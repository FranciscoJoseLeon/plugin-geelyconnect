# -*- coding: utf-8 -*-
"""Jeedom daemon helper utilities: redacting logger, PID manager, callback client, and local socket server."""

import hmac
import json
import logging
import os
import re
import socketserver
import threading
import requests


class RedactingFormatter(logging.Formatter):
    """Logging formatter that masks passwords, API keys, bearer tokens, and VINs even in DEBUG mode."""

    _SENSITIVE_KV_RE = re.compile(
        r'((?:password|passwd|apikey|api_key|token|access_token|refresh_token|authorization|secret|pin)'
        r'["\']?\s*[:=]\s*["\']?)([^"\'\s,}]+)',
        re.IGNORECASE,
    )
    _VIN_RE = re.compile(r'\b([A-HJ-NPR-Z0-9]{4})[A-HJ-NPR-Z0-9]{9}([A-HJ-NPR-Z0-9]{4})\b')

    def format(self, record):
        rendered = super().format(record)
        rendered = self._SENSITIVE_KV_RE.sub(r'\1***REDACTED***', rendered)
        rendered = self._VIN_RE.sub(r'\1********* \2'.replace(' ', ''), rendered)
        return rendered


def configure_logging(level_name='info'):
    """Configure root logger with redacting formatter and Jeedom-compatible log levels."""
    level_map = {
        'debug': logging.DEBUG,
        'info': logging.INFO,
        'warning': logging.WARNING,
        'error': logging.ERROR,
        'critical': logging.CRITICAL,
        'none': logging.CRITICAL + 10,
    }
    level = level_map.get(str(level_name).lower(), logging.INFO)

    handler = logging.StreamHandler()
    handler.setFormatter(
        RedactingFormatter('[%(asctime)s][%(levelname)s] : %(message)s', datefmt='%Y-%m-%d %H:%M:%S')
    )

    root = logging.getLogger()
    root.handlers.clear()
    root.addHandler(handler)
    root.setLevel(level)


def write_pid(pid_file):
    """Write current process PID to the specified file."""
    with open(pid_file, 'w', encoding='utf-8') as handle:
        handle.write(str(os.getpid()))


def remove_pid(pid_file):
    """Remove PID file on clean shutdown."""
    try:
        if pid_file and os.path.exists(pid_file):
            os.remove(pid_file)
    except OSError:
        pass


class JeedomCallback:
    """HTTP client for pushing vehicle telemetry back to Jeedom jeeGeelyconnect.php."""

    def __init__(self, callback_url, apikey):
        self.callback_url = callback_url
        self.apikey = apikey
        self.session = requests.Session()

    def send(self, payload):
        """Send JSON telemetry payload to Jeedom with API key authentication."""
        if not self.callback_url or not self.apikey:
            logging.warning('Callback URL or API key missing; skipping telemetry push.')
            return False

        try:
            response = self.session.post(
                self.callback_url,
                params={'apikey': self.apikey},
                json=payload,
                timeout=15,
                verify=True,
            )
            response.raise_for_status()
            return True
        except requests.RequestException as exc:
            logging.error('Failed to push telemetry to Jeedom callback: %s', exc)
            return False


class _LocalSocketHandler(socketserver.StreamRequestHandler):
    """Line-delimited JSON handler validating API keys in constant time."""

    def handle(self):
        raw = self.rfile.readline(65536)
        if not raw:
            return

        try:
            message = json.loads(raw.decode('utf-8'))
        except (UnicodeDecodeError, json.JSONDecodeError):
            self._reply({'status': 'error', 'message': 'Invalid JSON payload'})
            return

        if not isinstance(message, dict):
            self._reply({'status': 'error', 'message': 'Payload must be a JSON object'})
            return

        provided_key = str(message.get('apikey', ''))
        expected_key = self.server.apikey
        if not expected_key or not hmac.compare_digest(expected_key, provided_key):
            logging.warning('Rejected unauthorized local socket request from %s', self.client_address[0])
            self._reply({'status': 'error', 'message': 'Unauthorized'})
            return

        try:
            result = self.server.on_message(message)
            self._reply({'status': 'ok', 'result': result})
        except Exception as exc:
            logging.error('Socket command execution failed: %s', exc)
            self._reply({'status': 'error', 'message': str(exc)})

    def _reply(self, payload):
        self.wfile.write((json.dumps(payload) + '\n').encode('utf-8'))


class _ThreadedTCPServer(socketserver.ThreadingMixIn, socketserver.TCPServer):
    allow_reuse_address = True
    daemon_threads = True


class JeedomSocketServer:
    """Localhost-only (127.0.0.1) TCP JSON server for PHP-to-daemon commands."""

    def __init__(self, port, apikey, on_message):
        self.port = int(port)
        self.server = _ThreadedTCPServer(('127.0.0.1', self.port), _LocalSocketHandler)
        self.server.apikey = apikey
        self.server.on_message = on_message
        self._thread = None

    def start(self):
        """Start the socket listener in a background daemon thread."""
        self._thread = threading.Thread(target=self.server.serve_forever, name='JeedomSocketServer', daemon=True)
        self._thread.start()
        logging.info('Local socket server listening on 127.0.0.1:%d', self.port)

    def stop(self):
        """Shut down the socket listener cleanly."""
        self.server.shutdown()
        self.server.server_close()
