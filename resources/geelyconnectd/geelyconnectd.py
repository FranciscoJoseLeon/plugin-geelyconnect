# -*- coding: utf-8 -*-
"""Geely Connect Jeedom daemon entry point."""

import argparse
import json
import logging
import os
import signal
import sys
import threading

from geely_api import GeelyApiClient, GeelyApiError, SessionInvalidated
from jeedom.jeedom import (
    JeedomCallback,
    JeedomSocketServer,
    configure_logging,
    remove_pid,
    write_pid,
)


class GeelyConnectDaemon:
    """Coordinates polling, interactive authentication, and authenticated localhost commands."""

    def __init__(self, bootstrap_cfg, pid_file):
        self.pid_file = pid_file
        self.stop_event = threading.Event()
        self.sync_event = threading.Event()
        self.lock = threading.Lock()
        self.session_invalid_notified = False

        self.poll_interval = max(1, int(bootstrap_cfg.get('poll_interval', 5))) * 60
        self.vins = [str(v).upper() for v in bootstrap_cfg.get('vins', []) if v]

        self.callback = JeedomCallback(
            callback_url=bootstrap_cfg.get('callback', ''),
            apikey=bootstrap_cfg.get('apikey', ''),
        )
        self.api = GeelyApiClient(
            username=bootstrap_cfg.get('username', ''),
            password=bootstrap_cfg.get('password', ''),
            region=bootstrap_cfg.get('region', 'EU'),
            country_code=bootstrap_cfg.get('country_code', 'ES'),
            cert_dir=bootstrap_cfg.get('cert_dir', ''),
        )
        self.socket_server = JeedomSocketServer(
            port=bootstrap_cfg.get('socketport', 55099),
            apikey=bootstrap_cfg.get('apikey', ''),
            on_message=self.handle_socket_message,
        )

    # -------------------------------------------------------------- events

    def _notify_session_invalid(self, reason):
        """Notify Jeedom once per invalidation so the admin can re-authenticate."""
        if self.session_invalid_notified:
            return
        self.session_invalid_notified = True
        logging.warning('Session invalidated: %s', reason)
        self.callback.send({'event': 'session_invalidated', 'message': str(reason)})

    def _notify_session_ok(self):
        if self.session_invalid_notified:
            self.session_invalid_notified = False
        self.callback.send({'event': 'session_ok'})

    # -------------------------------------------------------------- socket

    def handle_socket_message(self, message):
        """Process authenticated commands received from Jeedom PHP."""
        action = message.get('action')

        if action == 'configure':
            with self.lock:
                self.poll_interval = max(1, int(message.get('poll_interval', 5))) * 60
                self.vins = [str(v).upper() for v in message.get('vins', []) if v]
                self.api.update_credentials(
                    username=message.get('username', self.api.username),
                    password=message.get('password', self.api.password),
                    region=message.get('region', self.api.region),
                    country_code=message.get('country_code', self.api.country_code),
                )
            self.sync_event.set()
            return {'configured': True}

        if action == 'reload_endpoints':
            with self.lock:
                self.api.reload_endpoints()
            return {'reloaded': True}

        if action == 'auth_status':
            with self.lock:
                return self.api.auth_status()

        if action == 'auth_start':
            with self.lock:
                return self.api.auth_start(
                    email=message.get('email', self.api.username),
                    country_code=message.get('country_code', self.api.country_code),
                )

        if action == 'request_otp':
            with self.lock:
                return self.api.request_otp(
                    email=message.get('email', self.api.username),
                    country_code=message.get('country_code', self.api.country_code),
                    captcha=message.get('captcha') or {},
                )

        if action == 'verify_otp':
            with self.lock:
                result = self.api.verify_otp_and_provision_mtls(
                    email=message.get('email', self.api.username),
                    otp_code=message.get('otp_code', ''),
                    country_code=message.get('country_code', self.api.country_code),
                )
            self._notify_session_ok()
            self.sync_event.set()
            return result

        if action == 'provision_mtls':
            with self.lock:
                return self.api.provision_mtls()

        if action == 'sync':
            self.session_invalid_notified = False
            self.sync_event.set()
            return {'sync_queued': True}

        if action == 'command':
            vin = str(message.get('vin', '')).upper()
            command = str(message.get('command', ''))
            if not vin:
                raise ValueError('Missing VIN for vehicle command.')
            with self.lock:
                try:
                    result = self.api.send_command(vin, command, value=message.get('value'))
                    status = self.api.get_vehicle_status(vin)
                except SessionInvalidated as exc:
                    self._notify_session_invalid(exc)
                    raise
            self.callback.send({'vehicles': [status]})
            return result

        raise ValueError(f'Unknown socket action: {action}')

    # -------------------------------------------------------------- polling

    def poll_once(self):
        """Discover vehicles and push telemetry. Skips silently while the session is invalid."""
        with self.lock:
            try:
                target_vins = list(self.vins)
                if not target_vins:
                    discovered = self.api.get_vehicles()
                    target_vins = [str(_v.get('vin', '')).upper() for _v in discovered if isinstance(_v, dict) and _v.get('vin')]
                    if target_vins:
                        self.vins = target_vins

                batch = []
                for vin in target_vins:
                    status = self.api.get_vehicle_status(vin)
                    caps = self.api.get_vehicle_capabilities(vin)
                    if caps.get('powertrain'):
                        status['powertrain'] = caps['powertrain']
                    batch.append(status)
            except SessionInvalidated as exc:
                self._notify_session_invalid(exc)
                return
            except GeelyApiError as exc:
                logging.error('Polling cycle failed: %s', exc)
                return
            except Exception as exc:  # noqa: BLE001 - keep daemon alive
                logging.exception('Unexpected error during polling cycle: %s', exc)
                return

        if batch:
            self.callback.send({'vehicles': batch})

    def run(self):
        """Start the socket server and run the polling loop until a termination signal."""
        write_pid(self.pid_file)
        self.socket_server.start()
        logging.info('Geely Connect daemon started (poll interval: %ds).', self.poll_interval)
        self.sync_event.set()

        while not self.stop_event.is_set():
            if self.sync_event.is_set():
                self.sync_event.clear()
                if not self.session_invalid_notified:
                    self.poll_once()
            triggered = self.sync_event.wait(timeout=self.poll_interval)
            if not triggered and not self.stop_event.is_set():
                self.sync_event.set()

        self.socket_server.stop()
        remove_pid(self.pid_file)
        logging.info('Geely Connect daemon stopped cleanly.')


def load_and_shred_bootstrap(path):
    """Read the bootstrap JSON containing credentials and delete it immediately."""
    if not path or not os.path.exists(path):
        raise FileNotFoundError('Daemon bootstrap file not found.')
    with open(path, 'r', encoding='utf-8') as handle:
        data = json.load(handle)
    try:
        os.remove(path)
    except OSError as exc:
        logging.warning('Could not delete bootstrap file: %s', exc)
    return data


def main():
    parser = argparse.ArgumentParser(description='Geely Connect Jeedom Daemon')
    parser.add_argument('--loglevel', default='info')
    parser.add_argument('--socketport', type=int, default=55099)
    parser.add_argument('--pid', required=True)
    parser.add_argument('--bootstrap', required=True)
    args = parser.parse_args()

    configure_logging(args.loglevel)

    try:
        bootstrap_cfg = load_and_shred_bootstrap(args.bootstrap)
    except Exception as exc:  # noqa: BLE001
        logging.critical('Failed to load bootstrap configuration: %s', exc)
        sys.exit(1)

    daemon = GeelyConnectDaemon(bootstrap_cfg, args.pid)

    def _handle_signal(signum, _frame):
        logging.info('Received signal %d; shutting down...', signum)
        daemon.stop_event.set()
        daemon.sync_event.set()

    signal.signal(signal.SIGTERM, _handle_signal)
    signal.signal(signal.SIGINT, _handle_signal)
    daemon.run()


if __name__ == '__main__':
    main()
