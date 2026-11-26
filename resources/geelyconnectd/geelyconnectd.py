# -*- coding: utf-8 -*-
"""Geely Connect Jeedom daemon entry point."""

import argparse
import json
import logging
import os
import signal
import sys
import threading

from geely_api import GeelyApiClient, GeelyApiError
from jeedom.jeedom import (
    JeedomCallback,
    JeedomSocketServer,
    configure_logging,
    remove_pid,
    write_pid,
)


class GeelyConnectDaemon:
    """Coordinates periodic vehicle polling and authenticated localhost control commands."""

    def __init__(self, bootstrap_cfg, pid_file):
        self.pid_file = pid_file
        self.stop_event = threading.Event()
        self.sync_event = threading.Event()
        self.lock = threading.Lock()

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
        )
        self.socket_server = JeedomSocketServer(
            port=bootstrap_cfg.get('socketport', 55099),
            apikey=bootstrap_cfg.get('apikey', ''),
            on_message=self.handle_socket_message,
        )

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
                )
            self.sync_event.set()
            return {'configured': True}

        if action == 'sync':
            self.sync_event.set()
            return {'sync_queued': True}

        if action == 'command':
            vin = str(message.get('vin', '')).upper()
            command = str(message.get('command', ''))
            if not vin:
                raise ValueError('Missing VIN for vehicle command.')
            with self.lock:
                result = self.api.send_command(vin, command)
                if command == 'refresh':
                    status = self.api.get_vehicle_status(vin)
                    self.callback.send({'vehicles': [status]})
            return result

        raise ValueError(f'Unknown socket action: {action}')

    def poll_once(self):
        """Discover vehicles if needed and push latest telemetry to Jeedom."""
        with self.lock:
            target_vins = list(self.vins)
            try:
                if not target_vins:
                    discovered = self.api.get_vehicles()
                    target_vins = [str(v.get('vin', '')).upper() for v in discovered if v.get('vin')]
                    if target_vins:
                        self.vins = target_vins

                telemetry_batch = []
                for vin in target_vins:
                    telemetry_batch.append(self.api.get_vehicle_status(vin))

                if telemetry_batch:
                    self.callback.send({'vehicles': telemetry_batch})
            except GeelyApiError as exc:
                logging.error('Polling cycle failed: %s', exc)
            except Exception as exc:
                logging.exception('Unexpected error during polling cycle: %s', exc)

    def run(self):
        """Start socket server and run polling loop until termination signal."""
        write_pid(self.pid_file)
        self.socket_server.start()
        logging.info('Geely Connect daemon started (poll interval: %ds).', self.poll_interval)

        # Trigger initial poll shortly after startup.
        self.sync_event.set()

        while not self.stop_event.is_set():
            if self.sync_event.is_set():
                self.sync_event.clear()
                self.poll_once()
            # Wait for next poll interval or immediate sync wake-up.
            triggered = self.sync_event.wait(timeout=self.poll_interval)
            if not triggered and not self.stop_event.is_set():
                self.sync_event.set()

        self.socket_server.stop()
        remove_pid(self.pid_file)
        logging.info('Geely Connect daemon stopped cleanly.')


def load_and_shred_bootstrap(path):
    """Read bootstrap JSON containing credentials and delete it from disk immediately."""
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
    except Exception as exc:
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
