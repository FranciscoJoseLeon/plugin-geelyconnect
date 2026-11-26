# -*- coding: utf-8 -*-
"""Geely Connect Cloud API client with TLS verification, payload signing, token refresh, and backoff."""

import base64
import hashlib
import hmac
import json
import logging
import os
import time
import requests
from Crypto.Cipher import AES, PKCS1_v1_5
from Crypto.PublicKey import RSA
from Crypto.Util.Padding import pad


class GeelyApiError(Exception):
    """Raised when Geely cloud authentication or API communication fails."""


class GeelyApiClient:
    """Modular client for Geely Connect regional vehicle telemetry and remote control APIs."""

    REGION_ENDPOINTS = {
        'EU': 'https://eu-connect-api.geely.com',
        'GLOBAL': 'https://global-connect-api.geely.com',
        'APAC': 'https://apac-connect-api.geely.com',
    }

    MAX_BACKOFF_SECONDS = 1800

    def __init__(self, username='', password='', region='EU'):
        self.username = username
        self.password = password
        self.region = region.upper() if region and region.upper() in self.REGION_ENDPOINTS else 'EU'
        self.base_url = self.REGION_ENDPOINTS[self.region]

        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'GeelyConnect-Jeedom/1.0.0',
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        })

        self._access_token = None
        self._refresh_token = None
        self._token_expires_at = 0.0
        self._rsa_public_key = None

        self._consecutive_failures = 0
        self._next_retry_at = 0.0

    @staticmethod
    def mask_vin(vin):
        """Mask middle characters of a VIN for safe logging."""
        if not vin or len(vin) < 8:
            return '***'
        return f'{vin[:4]}*********{vin[-4:]}'

    def update_credentials(self, username, password, region='EU'):
        """Update credentials in memory and reset backoff state."""
        self.username = username
        self.password = password
        self.region = region.upper() if region and region.upper() in self.REGION_ENDPOINTS else 'EU'
        self.base_url = self.REGION_ENDPOINTS[self.region]
        self._access_token = None
        self._refresh_token = None
        self._token_expires_at = 0.0
        self._consecutive_failures = 0
        self._next_retry_at = 0.0

    def _record_auth_failure(self):
        """Apply exponential backoff to prevent account lockouts on repeated login failures."""
        self._consecutive_failures += 1
        delay = min(self.MAX_BACKOFF_SECONDS, 15 * (2 ** (self._consecutive_failures - 1)))
        self._next_retry_at = time.time() + delay
        logging.warning('Authentication failure #%d; backing off for %d seconds.', self._consecutive_failures, delay)

    def _reset_backoff(self):
        self._consecutive_failures = 0
        self._next_retry_at = 0.0

    def _sign_request(self, method, path, timestamp, body_str):
        """Generate HMAC-SHA256 request signature for Geely Connect API requests."""
        canonical = f'{method.upper()}\n{path}\n{timestamp}\n{body_str}'
        signing_key = hashlib.sha256((self._access_token or self.region).encode('utf-8')).digest()
        digest = hmac.new(signing_key, canonical.encode('utf-8'), hashlib.sha256).digest()
        return base64.b64encode(digest).decode('ascii')

    def _encrypt_credentials_payload(self, payload_dict):
        """Encrypt login payload using AES-256-CBC and RSA-PKCS1_v1_5 when a server public key is available."""
        raw_json = json.dumps(payload_dict, separators=(',', ':')).encode('utf-8')
        if not self._rsa_public_key:
            return payload_dict

        aes_key = os.urandom(32)
        iv = os.urandom(16)
        cipher_aes = AES.new(aes_key, AES.MODE_CBC, iv)
        encrypted_data = cipher_aes.encrypt(pad(raw_json, AES.block_size))

        rsa_key = RSA.import_key(self._rsa_public_key)
        cipher_rsa = PKCS1_v1_5.new(rsa_key)
        encrypted_key = cipher_rsa.encrypt(aes_key)

        return {
            'key': base64.b64encode(encrypted_key).decode('ascii'),
            'iv': base64.b64encode(iv).decode('ascii'),
            'data': base64.b64encode(encrypted_data).decode('ascii'),
        }

    def _request(self, method, path, payload=None, authenticated=True):
        """Execute an HTTPS request with strict TLS verification (verify=True)."""
        if authenticated:
            self.ensure_authenticated()

        url = f'{self.base_url}{path}'
        timestamp = str(int(time.time() * 1000))
        body_str = json.dumps(payload, separators=(',', ':')) if payload is not None else ''
        headers = {
            'X-Timestamp': timestamp,
            'X-Signature': self._sign_request(method, path, timestamp, body_str),
        }
        if authenticated and self._access_token:
            headers['Authorization'] = f'Bearer {self._access_token}'

        try:
            response = self.session.request(
                method=method,
                url=url,
                data=body_str if body_str else None,
                headers=headers,
                timeout=20,
                verify=True,
            )
            if response.status_code == 401 and authenticated:
                logging.info('Access token rejected (401); attempting token refresh.')
                self._access_token = None
                self.ensure_authenticated()
                headers['Authorization'] = f'Bearer {self._access_token}'
                response = self.session.request(
                    method=method,
                    url=url,
                    data=body_str if body_str else None,
                    headers=headers,
                    timeout=20,
                    verify=True,
                )
            response.raise_for_status()
            return response.json()
        except requests.RequestException as exc:
            raise GeelyApiError(f'Geely API request error on {path}: {exc}') from exc

    def ensure_authenticated(self):
        """Ensure a valid access token is available, refreshing or re-authenticating as needed."""
        now = time.time()
        if self._access_token and now < (self._token_expires_at - 60):
            return

        if now < self._next_retry_at:
            remaining = int(self._next_retry_at - now)
            raise GeelyApiError(f'Authentication rate-limited; retry in {remaining}s.')

        if self._refresh_token:
            try:
                self._do_refresh()
                return
            except GeelyApiError:
                self._refresh_token = None

        self._do_login()

    def _do_login(self):
        """Authenticate against Geely Connect using configured credentials."""
        if not self.username or not self.password:
            raise GeelyApiError('Missing Geely account credentials.')

        login_body = self._encrypt_credentials_payload({
            'username': self.username,
            'password': self.password,
            'region': self.region,
        })

        try:
            data = self._request('POST', '/v1/auth/login', payload=login_body, authenticated=False)
        except GeelyApiError:
            self._record_auth_failure()
            raise

        token = data.get('access_token')
        if not token:
            self._record_auth_failure()
            raise GeelyApiError('Authentication response did not include an access token.')

        self._access_token = token
        self._refresh_token = data.get('refresh_token')
        expires_in = int(data.get('expires_in', 3600))
        self._token_expires_at = time.time() + expires_in
        self._reset_backoff()
        logging.info('Authenticated successfully with Geely Connect (%s).', self.region)

    def _do_refresh(self):
        """Refresh the active session token."""
        data = self._request(
            'POST',
            '/v1/auth/refresh',
            payload={'refresh_token': self._refresh_token},
            authenticated=False,
        )
        token = data.get('access_token')
        if not token:
            raise GeelyApiError('Token refresh failed.')
        self._access_token = token
        self._refresh_token = data.get('refresh_token', self._refresh_token)
        self._token_expires_at = time.time() + int(data.get('expires_in', 3600))

    def get_vehicles(self):
        """Fetch list of vehicles linked to the Geely Connect account."""
        data = self._request('GET', '/v1/vehicles')
        vehicles = data.get('vehicles', [])
        return vehicles if isinstance(vehicles, list) else []

    def get_vehicle_status(self, vin):
        """Fetch normalized telemetry for a specific VIN."""
        data = self._request('GET', f'/v1/vehicles/{vin}/status')
        battery = data.get('battery', {})
        climate = data.get('climate', {})
        security = data.get('security', {})
        position = data.get('position', {})

        lat = position.get('latitude')
        lon = position.get('longitude')
        location_str = f'{lat},{lon}' if lat is not None and lon is not None else ''

        return {
            'vin': vin,
            'model': data.get('model', 'Geely Vehicle'),
            'soc': battery.get('soc', 0),
            'range': battery.get('range_km', 0),
            'charging_status': battery.get('charging_status', 'idle'),
            'lock_status': 1 if security.get('locked') else 0,
            'climate_status': 1 if climate.get('active') else 0,
            'location': location_str,
            'odometer': data.get('odometer_km', 0),
            'last_update': time.strftime('%Y-%m-%d %H:%M:%S'),
        }

    def send_command(self, vin, command):
        """Dispatch a remote vehicle control command."""
        command_map = {
            'lock': ('/v1/vehicles/{vin}/commands/lock', {'action': 'lock'}),
            'unlock': ('/v1/vehicles/{vin}/commands/unlock', {'action': 'unlock'}),
            'climate_on': ('/v1/vehicles/{vin}/commands/climate', {'active': True}),
            'climate_off': ('/v1/vehicles/{vin}/commands/climate', {'active': False}),
            'refresh': ('/v1/vehicles/{vin}/commands/refresh', {}),
        }
        if command not in command_map:
            raise GeelyApiError(f'Unsupported vehicle command: {command}')

        path_tpl, payload = command_map[command]
        path = path_tpl.format(vin=vin)
        logging.info('Dispatching command "%s" for vehicle %s', command, self.mask_vin(vin))
        return self._request('POST', path, payload=payload)
