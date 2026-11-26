# -*- coding: utf-8 -*-
"""Geely Connect Cloud API client with OTP/mTLS auth, capability discovery, rich telemetry, and TCU polling."""

import base64
import hashlib
import hmac
import json
import logging
import os
import time
import requests


class GeelyApiError(Exception):
    """Raised when Geely cloud authentication or API communication fails."""


class GeelyApiClient:
    """Modular client for Geely Connect / Global regional vehicle telemetry and remote control APIs."""

    REGION_ENDPOINTS = {
        'EU': 'https://apis.ecloudeu.com',
        'GLOBAL': 'https://apis.ecloudeu.com',
        'APAC': 'https://apis.xcloudsvc.com',
    }

    MAX_BACKOFF_SECONDS = 1800

    def __init__(self, username='', password='', region='EU', country_code='ES', cert_dir=''):
        self.username = username
        self.password = password
        self.region = region.upper() if region and region.upper() in self.REGION_ENDPOINTS else 'EU'
        self.country_code = (country_code or 'ES').upper()
        self.base_url = self.REGION_ENDPOINTS[self.region]
        self.cert_dir = cert_dir

        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'GeelyConnect-Jeedom/2.0.0',
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        })

        self._access_token = None
        self._refresh_token = None
        self._token_expires_at = 0.0
        self._rsa_public_key = None
        self._mtls_cert = self._resolve_mtls_pair()

        self._consecutive_failures = 0
        self._next_retry_at = 0.0
        self._load_session()

    def _session_file_path(self):
        return os.path.join(self.cert_dir, 'session.json') if self.cert_dir else ''

    def _load_session(self):
        """Load persisted OTP/refresh tokens from 0600 session.json if available."""
        path = self._session_file_path()
        if not path or not os.path.exists(path):
            return
        try:
            with open(path, 'r', encoding='utf-8') as fh:
                data = json.load(fh)
            self._access_token = data.get('access_token')
            self._refresh_token = data.get('refresh_token')
            self._token_expires_at = float(data.get('expires_at', 0.0))
        except Exception:
            pass

    def _save_session(self):
        """Persist active OTP/refresh tokens with strict 0600 permissions."""
        path = self._session_file_path()
        if not path:
            return
        os.makedirs(self.cert_dir, mode=0o750, exist_ok=True)
        with open(path, 'w', encoding='utf-8') as fh:
            json.dump({
                'access_token': self._access_token,
                'refresh_token': self._refresh_token,
                'expires_at': self._token_expires_at,
            }, fh)
        os.chmod(path, 0o600)

    def _resolve_mtls_pair(self):
        """Return (cert_path, key_path) tuple if mTLS device certificates exist on disk."""
        if not self.cert_dir:
            return None
        cert_path = os.path.join(self.cert_dir, 'client.crt')
        key_path = os.path.join(self.cert_dir, 'client.key')
        if os.path.exists(cert_path) and os.path.exists(key_path):
            return (cert_path, key_path)
        return None

    @staticmethod
    def mask_vin(vin):
        """Mask middle characters of a VIN for safe logging."""
        if not vin or len(vin) < 8:
            return '***'
        return f'{vin[:4]}*********{vin[-4:]}'

    def update_credentials(self, username, password, region='EU', country_code='ES'):
        """Update credentials in memory and reset backoff state."""
        self.username = username
        self.password = password
        self.region = region.upper() if region and region.upper() in self.REGION_ENDPOINTS else 'EU'
        self.country_code = (country_code or 'ES').upper()
        self.base_url = self.REGION_ENDPOINTS[self.region]
        self._mtls_cert = self._resolve_mtls_pair()
        self._access_token = None
        self._refresh_token = None
        self._token_expires_at = 0.0
        self._consecutive_failures = 0
        self._next_retry_at = 0.0
        self._load_session()

    def _session_file_path(self):
        return os.path.join(self.cert_dir, 'session.json') if self.cert_dir else ''

    def _load_session(self):
        """Load persisted OTP/refresh tokens from 0600 session.json if available."""
        path = self._session_file_path()
        if not path or not os.path.exists(path):
            return
        try:
            with open(path, 'r', encoding='utf-8') as fh:
                data = json.load(fh)
            self._access_token = data.get('access_token')
            self._refresh_token = data.get('refresh_token')
            self._token_expires_at = float(data.get('expires_at', 0.0))
        except Exception:
            pass

    def _save_session(self):
        """Persist active OTP/refresh tokens with strict 0600 permissions."""
        path = self._session_file_path()
        if not path:
            return
        os.makedirs(self.cert_dir, mode=0o750, exist_ok=True)
        with open(path, 'w', encoding='utf-8') as fh:
            json.dump({
                'access_token': self._access_token,
                'refresh_token': self._refresh_token,
                'expires_at': self._token_expires_at,
            }, fh)
        os.chmod(path, 0o600)

    def _record_auth_failure(self):
        """Apply exponential backoff to prevent account lockouts on repeated login failures."""
        self._consecutive_failures += 1
        delay = min(self.MAX_BACKOFF_SECONDS, 15 * (2 ** (self._consecutive_failures - 1)))
        self._next_retry_at = time.time() + delay
        logging.warning('Authentication failure #%d; backing off for %d seconds.', self._consecutive_failures, delay)

    def _reset_backoff(self):
        self._consecutive_failures = 0
        self._next_retry_at = 0.0
        self._load_session()

    def _session_file_path(self):
        return os.path.join(self.cert_dir, 'session.json') if self.cert_dir else ''

    def _load_session(self):
        """Load persisted OTP/refresh tokens from 0600 session.json if available."""
        path = self._session_file_path()
        if not path or not os.path.exists(path):
            return
        try:
            with open(path, 'r', encoding='utf-8') as fh:
                data = json.load(fh)
            self._access_token = data.get('access_token')
            self._refresh_token = data.get('refresh_token')
            self._token_expires_at = float(data.get('expires_at', 0.0))
        except Exception:
            pass

    def _save_session(self):
        """Persist active OTP/refresh tokens with strict 0600 permissions."""
        path = self._session_file_path()
        if not path:
            return
        os.makedirs(self.cert_dir, mode=0o750, exist_ok=True)
        with open(path, 'w', encoding='utf-8') as fh:
            json.dump({
                'access_token': self._access_token,
                'refresh_token': self._refresh_token,
                'expires_at': self._token_expires_at,
            }, fh)
        os.chmod(path, 0o600)

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

        from Cryptodome.Cipher import AES, PKCS1_v1_5
        from Cryptodome.PublicKey import RSA
        from Cryptodome.Util.Padding import pad

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
        """Execute an HTTPS request with strict TLS verification (verify=True) and optional mTLS."""
        if authenticated:
            self.ensure_authenticated()

        url = f'{self.base_url}{path}'
        timestamp = str(int(time.time() * 1000))
        body_str = json.dumps(payload, separators=(',', ':')) if payload is not None else ''
        headers = {
            'X-Timestamp': timestamp,
            'X-Country-Code': self.country_code,
            'X-Signature': self._sign_request(method, path, timestamp, body_str),
        }
        if authenticated and self._access_token:
            headers['Authorization'] = f'Bearer {self._access_token}'

        ca_bundle = os.path.join(self.cert_dir, 'ca.crt') if self.cert_dir else ''
        tls_verify = ca_bundle if (ca_bundle and os.path.exists(ca_bundle)) else True

        try:
            response = self.session.request(
                method=method,
                url=url,
                data=body_str if body_str else None,
                headers=headers,
                cert=self._mtls_cert,
                timeout=20,
                verify=tls_verify,
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
                    cert=self._mtls_cert,
                    timeout=20,
                    verify=tls_verify,
                )
            response.raise_for_status()
            return response.json()
        except requests.RequestException as exc:
            raise GeelyApiError(f'Geely API request error on {path}: {exc}') from exc

    def request_otp(self, email, country_code='ES'):
        """Request a 6-digit email OTP verification code for Geely Global login."""
        if not email:
            raise GeelyApiError('Email is required to request an OTP code.')
        self.country_code = (country_code or 'ES').upper()
        return self._request(
            'POST',
            '/v1/auth/otp/request',
            payload={'email': email, 'country_code': self.country_code},
            authenticated=False,
        )

    def verify_otp_and_provision_mtls(self, email, otp_code, country_code='ES'):
        """Verify 6-digit email OTP and store provisioned mTLS device certificates with 0600 permissions."""
        if not email or not otp_code:
            raise GeelyApiError('Email and 6-digit OTP code are required.')
        self.country_code = (country_code or 'ES').upper()
        data = self._request(
            'POST',
            '/v1/auth/otp/verify',
            payload={'email': email, 'code': str(otp_code).strip(), 'country_code': self.country_code},
            authenticated=False,
        )
        token = data.get('access_token')
        if token:
            self._access_token = token
            self._refresh_token = data.get('refresh_token')
            self._token_expires_at = time.time() + int(data.get('expires_in', 3600))
            self._reset_backoff()

        client_cert = data.get('client_cert')
        client_key = data.get('client_key')
        if client_cert and client_key and self.cert_dir:
            os.makedirs(self.cert_dir, mode=0o750, exist_ok=True)
            cert_path = os.path.join(self.cert_dir, 'client.crt')
            key_path = os.path.join(self.cert_dir, 'client.key')
            for path, content in ((cert_path, client_cert), (key_path, client_key)):
                with open(path, 'w', encoding='utf-8') as fh:
                    fh.write(content)
                os.chmod(path, 0o600)
            self._mtls_cert = (cert_path, key_path)
            logging.info('Provisioned mTLS client certificate securely.')

        self._save_session()
        return {'verified': True, 'mtls_provisioned': bool(self._mtls_cert)}

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
            raise GeelyApiError('Awaiting Email OTP verification in plugin configuration (no password configured).')

        login_body = self._encrypt_credentials_payload({
            'username': self.username,
            'password': self.password,
            'region': self.region,
            'country_code': self.country_code,
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
        logging.info('Authenticated successfully with Geely Connect (%s/%s).', self.region, self.country_code)

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
        self._save_session()

    def get_vehicles(self):
        """Fetch list of vehicles linked to the Geely Connect account."""
        data = self._request('GET', '/v1/vehicles')
        vehicles = data.get('vehicles', [])
        return vehicles if isinstance(vehicles, list) else []

    def get_vehicle_capabilities(self, vin):
        """Query per-VIN capability catalog to determine powertrain and supported trim features."""
        try:
            data = self._request('GET', f'/geelyTCAccess/tcservices/capability/{vin}?pageSize=2000&pageIndex=1&vehicleType=0')
            return {
                'powertrain': str(data.get('powertrain', 'EV')).upper(),
                'features': data.get('features', {}),
            }
        except GeelyApiError:
            return {'powertrain': 'EV', 'features': {}}

    def get_vehicle_status(self, vin):
        """Fetch normalized comprehensive telemetry for a specific VIN."""
        data = self._request('GET', f'/v1/vehicles/{vin}/status')
        battery = data.get('battery', {})
        fuel = data.get('fuel', {})
        climate = data.get('climate', {})
        security = data.get('security', {})
        doors = data.get('doors', {})
        tpms = data.get('tpms', {})
        diagnostics = data.get('diagnostics', {})
        position = data.get('position', {})

        lat = position.get('latitude')
        lon = position.get('longitude')
        location_str = f'{lat},{lon}' if lat is not None and lon is not None else ''

        return {
            'vin': vin,
            'model': data.get('model', 'Geely Vehicle'),
            'year': data.get('year', ''),
            'trim': data.get('trim', ''),
            'powertrain': str(data.get('powertrain', 'EV')).upper(),
            'soc': battery.get('soc', 0),
            'range': battery.get('range_km', 0),
            'range_full': battery.get('range_full_km', 0),
            'charging_status': battery.get('charging_status', 'idle'),
            'charging_power_kw': battery.get('charging_power_kw', 0),
            'plug_status': 1 if battery.get('plugged_in') else 0,
            'time_to_full_min': battery.get('time_to_full_min', 0),
            'scheduled_charge': 1 if battery.get('scheduled_charge') else 0,
            'fuel_level': fuel.get('level_pct', 0),
            'fuel_range': fuel.get('range_km', 0),
            'lock_status': 1 if security.get('locked') else 0,
            'climate_status': 1 if climate.get('active') else 0,
            'target_temp': climate.get('target_temp_c', 22),
            'defrost_status': 1 if climate.get('defrost') else 0,
            'g_clean_status': 1 if climate.get('g_clean') else 0,
            'interior_temp': climate.get('interior_temp_c', 0),
            'exterior_temp': climate.get('exterior_temp_c', 0),
            'battery_12v': diagnostics.get('battery_12v_v', 12.6),
            'service_days': diagnostics.get('service_days', 0),
            'service_km': diagnostics.get('service_km', 0),
            'tpms_fl': tpms.get('front_left_bar', 2.5),
            'tpms_fr': tpms.get('front_right_bar', 2.5),
            'tpms_rl': tpms.get('rear_left_bar', 2.5),
            'tpms_rr': tpms.get('rear_right_bar', 2.5),
            'door_fl': 1 if doors.get('front_left') else 0,
            'door_fr': 1 if doors.get('front_right') else 0,
            'door_rl': 1 if doors.get('rear_left') else 0,
            'door_rr': 1 if doors.get('rear_right') else 0,
            'trunk_open': 1 if doors.get('trunk') else 0,
            'hood_open': 1 if doors.get('hood') else 0,
            'sunroof_open': 1 if doors.get('sunroof') else 0,
            'windows_open': 1 if doors.get('windows') else 0,
            'driver_seatbelt': 1 if security.get('driver_seatbelt') else 0,
            'location': location_str,
            'odometer': data.get('odometer_km', 0),
            'last_update': time.strftime('%Y-%m-%d %H:%M:%S'),
        }

    def send_command(self, vin, command, value=None):
        """Dispatch a remote vehicle control command with TCU wake-up & async confirmation."""
        command_map = {
            'lock': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'lock'}),
            'unlock': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'unlock'}),
            'climate_on': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'climate', 'active': True}),
            'climate_off': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'climate', 'active': False}),
            'set_temp': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'climate_temp', 'temperature': float(value) if value is not None else 22.0}),
            'rapid_cool': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'rapid_cooling'}),
            'rapid_warm': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'rapid_warming'}),
            'defrost_on': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'defrost', 'active': True}),
            'defrost_off': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'defrost', 'active': False}),
            'g_clean_on': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'g_clean', 'active': True}),
            'g_clean_off': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'g_clean', 'active': False}),
            'seat_heat_driver': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'seat_heat', 'seat': 'driver', 'level': int(value) if value is not None else 1}),
            'seat_vent_driver': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'seat_vent', 'seat': 'driver', 'level': int(value) if value is not None else 1}),
            'find_car': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'find_car'}),
            'unlock_trunk': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'unlock_trunk'}),
            'sunroof_open': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'sunroof', 'open': True}),
            'sunroof_close': ('PUT', '/remote-control/vehicle/telematics/{vin}', {'command': 'sunroof', 'open': False}),
            'scheduled_charge_on': ('POST', '/charge-server/ecarx_charge_set/{vin}', {'bizType': 6, 'scheduled': True}),
            'scheduled_charge_off': ('POST', '/charge-server/ecarx_charge_set/{vin}', {'bizType': 6, 'scheduled': False}),
            'refresh': ('POST', '/v1/vehicles/{vin}/commands/refresh', {}),
        }
        if command not in command_map:
            raise GeelyApiError(f'Unsupported vehicle command: {command}')

        method, path_tpl, payload = command_map[command]
        path = path_tpl.format(vin=vin)
        logging.info('Dispatching command "%s" for vehicle %s', command, self.mask_vin(vin))

        result = self._request(method, path, payload=payload)
        task_id = result.get('task_id')
        if task_id:
            self._poll_command_status(vin, task_id)
        return result

    def _poll_command_status(self, vin, task_id, max_attempts=6):
        """Poll asynchronous TCU command execution until confirmed or timed out."""
        for _ in range(max_attempts):
            time.sleep(2)
            try:
                status = self._request('GET', f'/v1/vehicles/{vin}/commands/{task_id}')
                state = str(status.get('state', '')).lower()
                if state in ('completed', 'success'):
                    return status
                if state in ('failed', 'error'):
                    raise GeelyApiError(status.get('message', 'Vehicle TCU rejected command.'))
            except GeelyApiError:
                break
        return {'state': 'pending'}
