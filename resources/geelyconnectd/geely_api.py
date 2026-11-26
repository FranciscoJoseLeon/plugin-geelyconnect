# -*- coding: utf-8 -*-
"""Geely Global cloud API client for the Jeedom Geely Connect daemon.

Features:
- Configurable endpoint map (resources/geelyconnectd/endpoints.json + data/endpoints.json override)
  with an HTTPS host allowlist so tokens can never be sent to arbitrary hosts.
- Strict TLS verification, optionally pinned to data/certs/ca.crt (partial-chain aware).
- Interactive login: captcha descriptor relay (solved by the human admin in the browser),
  email OTP request/verification, token persistence (0600) and automatic refresh.
- Local key pair + CSR generation (openssl CLI, private key never leaves the host) and
  mTLS client certificate provisioning.
- Single-session invalidation detection (raises SessionInvalidated).
"""

import copy
import json
import logging
import os
import ssl
import subprocess
import tempfile
import time
import uuid
from urllib.parse import urlparse

import requests
from requests.adapters import HTTPAdapter

ALLOWED_HOST_SUFFIXES = ('.ecloudeu.com', '.xcloudsvc.com', '.ecloudus.com', '.ecloudkr.com')
ALLOWED_CSR_KEY_TYPES = ('rsa:2048', 'rsa:3072', 'ec:prime256v1')
CAPTCHA_V4_KEYS = ('lot_number', 'captcha_output', 'pass_token', 'gen_time', 'captcha_id')
CAPTCHA_V3_KEYS = ('geetest_challenge', 'geetest_validate', 'geetest_seccode')
DEFAULT_ENDPOINTS_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'endpoints.json')


class GeelyApiError(Exception):
    """Raised when Geely cloud authentication or API communication fails."""


class SessionInvalidated(GeelyApiError):
    """Raised when the cloud session is missing, expired beyond refresh, or opened elsewhere."""


class _PinnedCertAdapter(HTTPAdapter):
    """HTTPAdapter enforcing hostname + pinned certificate verification (partial chains allowed)."""

    def __init__(self, ca_bundle_path, **kwargs):
        self.ca_bundle_path = ca_bundle_path
        super().__init__(**kwargs)

    def init_poolmanager(self, *args, **kwargs):
        ctx = ssl.create_default_context(cafile=self.ca_bundle_path)
        ctx.verify_mode = ssl.CERT_REQUIRED
        ctx.check_hostname = True
        ctx.verify_flags |= getattr(ssl, 'VERIFY_X509_PARTIAL_CHAIN', 0x80000)
        kwargs['ssl_context'] = ctx
        return super().init_poolmanager(*args, **kwargs)


def _deep_merge(base, override):
    """Recursively merge override into a copy of base."""
    result = copy.deepcopy(base)
    for key, value in (override or {}).items():
        if isinstance(value, dict) and isinstance(result.get(key), dict):
            result[key] = _deep_merge(result[key], value)
        else:
            result[key] = value
    return result


def _pick(data, *keys, default=None):
    """Return the first non-empty value found under any of the dotted key paths."""
    for key in keys:
        node = data
        for part in key.split('.'):
            if isinstance(node, dict) and part in node:
                node = node[part]
            else:
                node = None
                break
        if node not in (None, ''):
            return node
    return default


def _write_secret(path, content):
    """Atomically write a secret file with 0600 permissions."""
    directory = os.path.dirname(path)
    os.makedirs(directory, mode=0o700, exist_ok=True)
    fd, tmp_path = tempfile.mkstemp(dir=directory)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as handle:
            handle.write(content)
        os.chmod(tmp_path, 0o600)
        os.replace(tmp_path, path)
    finally:
        if os.path.exists(tmp_path):
            os.remove(tmp_path)


class GeelyApiClient:
    """Client for Geely Global telemetry, authentication, and remote control APIs."""

    MAX_BACKOFF_SECONDS = 1800

    def __init__(self, username='', password='', region='EU', country_code='ES', cert_dir=''):
        self.username = username
        self.password = password
        self.region = (region or 'EU').upper()
        self.country_code = (country_code or 'ES').upper()
        self.cert_dir = cert_dir
        self.data_dir = os.path.dirname(cert_dir) if cert_dir else ''

        self.endpoints = {}
        self.base_url = ''
        self.session = requests.Session()

        self._access_token = None
        self._refresh_token = None
        self._token_expires_at = 0.0
        self._device_id = None
        self._mtls_cert = None
        self._consecutive_failures = 0
        self._next_retry_at = 0.0

        self.reload_endpoints()
        self._load_session()

    # ------------------------------------------------------------------ setup

    def reload_endpoints(self):
        """Load default endpoints, apply data/endpoints.json override, validate, and rebuild the HTTP session."""
        with open(DEFAULT_ENDPOINTS_FILE, 'r', encoding='utf-8') as handle:
            endpoints = json.load(handle)

        override_path = os.path.join(self.data_dir, 'endpoints.json') if self.data_dir else ''
        if override_path and os.path.exists(override_path):
            try:
                with open(override_path, 'r', encoding='utf-8') as handle:
                    endpoints = _deep_merge(endpoints, json.load(handle))
                logging.info('Loaded endpoint overrides from data/endpoints.json.')
            except (OSError, ValueError) as exc:
                logging.error('Ignoring invalid endpoint override file: %s', exc)

        for region_key, url in endpoints.get('base_urls', {}).items():
            self._assert_allowed_url(url, f'base_urls.{region_key}')

        self.endpoints = endpoints
        self.base_url = endpoints['base_urls'].get(self.region) or endpoints['base_urls']['EU']
        self._mtls_cert = self._resolve_mtls_pair()
        self._build_http_session()

    @staticmethod
    def _assert_allowed_url(url, label):
        parsed = urlparse(url)
        host = (parsed.hostname or '').lower()
        if parsed.scheme != 'https' or not any(host.endswith(sfx) or host == sfx.lstrip('.') for sfx in ALLOWED_HOST_SUFFIXES):
            raise GeelyApiError(f'Endpoint {label} host "{host}" is not in the Geely HTTPS allowlist.')

    def _build_http_session(self):
        """Create a fresh requests session with optional pinned CA adapter."""
        self.session.close()
        self.session = requests.Session()
        self.session.headers.update(self.endpoints.get('static_headers', {}))
        ca_bundle = os.path.join(self.cert_dir, 'ca.crt') if self.cert_dir else ''
        if ca_bundle and os.path.exists(ca_bundle):
            self.session.mount('https://', _PinnedCertAdapter(ca_bundle))

    def _resolve_mtls_pair(self):
        if not self.cert_dir:
            return None
        cert_path = os.path.join(self.cert_dir, 'client.crt')
        key_path = os.path.join(self.cert_dir, 'client.key')
        if os.path.exists(cert_path) and os.path.exists(key_path):
            return (cert_path, key_path)
        return None

    def update_credentials(self, username, password, region='EU', country_code='ES'):
        """Update credentials and region; keep persisted session tokens."""
        self.username = username
        self.password = password
        self.region = (region or 'EU').upper()
        self.country_code = (country_code or 'ES').upper()
        self.reload_endpoints()
        self._consecutive_failures = 0
        self._next_retry_at = 0.0

    @staticmethod
    def mask_vin(vin):
        if not vin or len(vin) < 8:
            return '***'
        return f'{vin[:4]}*********{vin[-4:]}'

    # ---------------------------------------------------------------- session

    def _session_path(self):
        return os.path.join(self.cert_dir, 'session.json') if self.cert_dir else ''

    def _load_session(self):
        path = self._session_path()
        if path and os.path.exists(path):
            try:
                with open(path, 'r', encoding='utf-8') as handle:
                    data = json.load(handle)
                self._access_token = data.get('access_token') or None
                self._refresh_token = data.get('refresh_token') or None
                self._token_expires_at = float(data.get('expires_at', 0.0))
                self._device_id = data.get('device_id') or None
            except (OSError, ValueError):
                logging.warning('Session file unreadable; a new login will be required.')
        if not self._device_id:
            self._device_id = uuid.uuid4().hex
            self._save_session()

    def _save_session(self):
        path = self._session_path()
        if not path:
            return
        _write_secret(path, json.dumps({
            'access_token': self._access_token,
            'refresh_token': self._refresh_token,
            'expires_at': self._token_expires_at,
            'device_id': self._device_id,
        }))

    def _clear_tokens(self):
        self._access_token = None
        self._refresh_token = None
        self._token_expires_at = 0.0
        self._save_session()

    def _store_tokens(self, data):
        token = _pick(data, 'access_token', 'accessToken', 'token')
        if not token:
            raise GeelyApiError('Authentication response did not include an access token.')
        self._access_token = token
        self._refresh_token = _pick(data, 'refresh_token', 'refreshToken', default=self._refresh_token)
        expires_in = int(_pick(data, 'expires_in', 'expiresIn', default=3600))
        self._token_expires_at = time.time() + expires_in
        self._save_session()

    def auth_status(self):
        """Return a non-secret summary of the authentication state."""
        return {
            'has_session': bool(self._access_token or self._refresh_token),
            'token_valid': bool(self._access_token and time.time() < self._token_expires_at),
            'mtls_provisioned': bool(self._mtls_cert),
            'ca_pinned': bool(self.cert_dir and os.path.exists(os.path.join(self.cert_dir, 'ca.crt'))),
            'base_url': self.base_url,
        }

    # ---------------------------------------------------------------- HTTP

    def _endpoint(self, dotted):
        value = _pick(self.endpoints, dotted, default='')
        if value and (not value.startswith('/') or '://' in value):
            raise GeelyApiError(f'Endpoint {dotted} must be a relative path starting with "/".')
        return value

    def _is_session_invalid(self, status_code, body):
        codes = [str(c) for c in self.endpoints.get('session_invalid_codes', [])]
        body_code = str(_pick(body, 'code', 'errorCode', 'error_code', default='')) if isinstance(body, dict) else ''
        return status_code in (401, 403) or (body_code and body_code in codes)

    def _request(self, method, path, payload=None, authenticated=True, allow_refresh=True):
        """Execute an HTTPS request with strict TLS, optional mTLS, and session-invalidation handling."""
        if not path:
            raise GeelyApiError('Endpoint is not configured. Set it in the endpoint override editor.')
        if authenticated:
            self.ensure_authenticated()

        headers = {'X-Timestamp': str(int(time.time() * 1000))}
        country_header = self.endpoints.get('country_header')
        device_header = self.endpoints.get('device_header')
        if country_header:
            headers[country_header] = self.country_code
        if device_header and self._device_id:
            headers[device_header] = self._device_id
        if authenticated and self._access_token:
            headers['Authorization'] = f'Bearer {self._access_token}'

        try:
            response = self.session.request(
                method=method,
                url=f'{self.base_url}{path}',
                json=payload if payload is not None else None,
                headers=headers,
                cert=self._mtls_cert,
                timeout=20,
                verify=True,
            )
        except requests.RequestException as exc:
            raise GeelyApiError(f'Geely API request error on {path}: {exc}') from exc

        try:
            body = response.json()
        except ValueError:
            body = {}

        if authenticated and self._is_session_invalid(response.status_code, body):
            if allow_refresh and self._refresh_token:
                logging.info('Session rejected on %s; attempting token refresh.', path)
                self._access_token = None
                self._do_refresh()
                return self._request(method, path, payload, authenticated, allow_refresh=False)
            self._clear_tokens()
            raise SessionInvalidated('Geely session invalidated (expired or opened on another device). Please log in again.')

        if response.status_code >= 400:
            message = _pick(body, 'message', 'msg', 'error_description', 'error', default=response.reason)
            raise GeelyApiError(f'Geely API {response.status_code} on {path}: {message}')

        unwrap = self.endpoints.get('response_unwrap_key')
        if unwrap and isinstance(body, dict) and isinstance(body.get(unwrap), (dict, list)):
            return body[unwrap]
        return body

    # ----------------------------------------------------------- auth flow

    def _record_auth_failure(self):
        self._consecutive_failures += 1
        delay = min(self.MAX_BACKOFF_SECONDS, 15 * (2 ** (self._consecutive_failures - 1)))
        self._next_retry_at = time.time() + delay
        logging.warning('Authentication failure #%d; backing off for %d seconds.', self._consecutive_failures, delay)

    def _check_backoff(self):
        remaining = int(self._next_retry_at - time.time())
        if remaining > 0:
            raise GeelyApiError(f'Authentication rate-limited; retry in {remaining}s.')

    def auth_start(self, email, country_code='ES'):
        """Start interactive login. Returns a captcha descriptor for the human admin to solve, or type none."""
        self._check_backoff()
        self.country_code = (country_code or self.country_code).upper()
        path = self._endpoint('auth.captcha_init')
        if not path:
            return {'captcha': {'type': 'none'}}
        data = self._request('POST', path, {'email': email, 'countryCode': self.country_code}, authenticated=False)
        captcha_id = _pick(data, 'captcha_id', 'captchaId')
        if captcha_id:
            return {'captcha': {'type': 'geetest_v4', 'captcha_id': str(captcha_id)}}
        gt = _pick(data, 'gt')
        challenge = _pick(data, 'challenge')
        if gt and challenge:
            return {'captcha': {
                'type': 'geetest_v3',
                'gt': str(gt),
                'challenge': str(challenge),
                'new_captcha': bool(_pick(data, 'new_captcha', default=True)),
                'offline': not bool(_pick(data, 'success', default=1)),
            }}
        return {'captcha': {'type': 'none'}}

    @staticmethod
    def sanitize_captcha_result(captcha):
        """Keep only known captcha result fields (strings, bounded length)."""
        if not isinstance(captcha, dict):
            return {}
        clean = {}
        for key in CAPTCHA_V4_KEYS + CAPTCHA_V3_KEYS:
            value = captcha.get(key)
            if isinstance(value, (str, int)) and len(str(value)) <= 1024:
                clean[key] = str(value)
        return clean

    def request_otp(self, email, country_code='ES', captcha=None):
        """Request a 6-digit email OTP, forwarding the captcha result solved by the admin."""
        if not email:
            raise GeelyApiError('Email is required to request an OTP code.')
        self._check_backoff()
        self.country_code = (country_code or self.country_code).upper()
        payload = {'email': email, 'countryCode': self.country_code, 'deviceId': self._device_id}
        payload.update(self.sanitize_captcha_result(captcha))
        try:
            self._request('POST', self._endpoint('auth.send_code'), payload, authenticated=False)
        except GeelyApiError:
            self._record_auth_failure()
            raise
        return {'otp_sent': True}

    def verify_otp_and_provision_mtls(self, email, otp_code, country_code='ES'):
        """Verify the OTP, persist tokens, then provision the mTLS client certificate if needed."""
        if not email or not otp_code:
            raise GeelyApiError('Email and 6-digit OTP code are required.')
        self._check_backoff()
        self.country_code = (country_code or self.country_code).upper()
        try:
            data = self._request('POST', self._endpoint('auth.verify_code'), {
                'email': email,
                'code': str(otp_code).strip(),
                'countryCode': self.country_code,
                'deviceId': self._device_id,
            }, authenticated=False)
            self._store_tokens(data)
        except GeelyApiError:
            self._record_auth_failure()
            raise
        self._consecutive_failures = 0
        self._next_retry_at = 0.0
        logging.info('OTP verified; session established.')

        provisioned = bool(self._mtls_cert)
        if not provisioned and self._endpoint('auth.provision_cert'):
            provisioned = self.provision_mtls()['mtls_provisioned']
        return {'verified': True, 'mtls_provisioned': provisioned}

    def _generate_key_and_csr(self, key_path, csr_path):
        """Generate a private key and CSR locally with the openssl CLI (no shell, umask 077)."""
        key_type = self.endpoints.get('csr_key_type', 'rsa:2048')
        if key_type not in ALLOWED_CSR_KEY_TYPES:
            key_type = 'rsa:2048'
        if key_type.startswith('ec:'):
            newkey = ['-newkey', 'ec', '-pkeyopt', f'ec_paramgen_curve:{key_type.split(":", 1)[1]}']
        else:
            newkey = ['-newkey', key_type]
        cmd = ['openssl', 'req', '-new', *newkey, '-nodes', '-keyout', key_path, '-out', csr_path,
               '-subj', f'/CN={self._device_id}']
        old_umask = os.umask(0o077)
        try:
            subprocess.run(cmd, check=True, timeout=60, capture_output=True)
        except (OSError, subprocess.SubprocessError) as exc:
            raise GeelyApiError(f'Local CSR generation failed: {exc}') from exc
        finally:
            os.umask(old_umask)
        os.chmod(key_path, 0o600)

    def provision_mtls(self):
        """Create a local key/CSR, submit the CSR, and store the issued client certificate (0600)."""
        if not self.cert_dir:
            raise GeelyApiError('Certificate directory is not configured.')
        os.makedirs(self.cert_dir, mode=0o700, exist_ok=True)
        key_tmp = os.path.join(self.cert_dir, 'client.key.pending')
        csr_tmp = os.path.join(self.cert_dir, 'client.csr.pending')
        try:
            self._generate_key_and_csr(key_tmp, csr_tmp)
            with open(csr_tmp, 'r', encoding='utf-8') as handle:
                csr_pem = handle.read()
            data = self._request('POST', self._endpoint('auth.provision_cert'), {
                'csr': csr_pem,
                'deviceId': self._device_id,
            })
            cert_pem = _pick(data, 'certificate', 'client_cert', 'clientCert', 'cert')
            if not cert_pem or 'BEGIN CERTIFICATE' not in str(cert_pem):
                raise GeelyApiError('Provisioning response did not include a PEM client certificate.')
            _write_secret(os.path.join(self.cert_dir, 'client.crt'), str(cert_pem).strip() + '\n')
            os.replace(key_tmp, os.path.join(self.cert_dir, 'client.key'))
            ca_pem = _pick(data, 'ca_chain', 'caChain', 'ca')
            if ca_pem and 'BEGIN CERTIFICATE' in str(ca_pem):
                _write_secret(os.path.join(self.cert_dir, 'ca.crt'), str(ca_pem).strip() + '\n')
                self._build_http_session()
            self._mtls_cert = self._resolve_mtls_pair()
            logging.info('mTLS client certificate provisioned and stored securely.')
            return {'mtls_provisioned': True}
        finally:
            for path in (key_tmp, csr_tmp):
                if os.path.exists(path):
                    os.remove(path)

    def ensure_authenticated(self):
        """Ensure a valid access token exists, refreshing or logging in when possible."""
        if self._access_token and time.time() < (self._token_expires_at - 60):
            return
        self._check_backoff()
        if self._refresh_token:
            self._do_refresh()
            return
        if self.username and self.password and self._endpoint('auth.password_login'):
            self._do_password_login()
            return
        raise SessionInvalidated('No active Geely session. Complete the email OTP login in the plugin configuration.')

    def _do_refresh(self):
        try:
            data = self._request('POST', self._endpoint('auth.refresh'),
                                 {'refresh_token': self._refresh_token, 'deviceId': self._device_id},
                                 authenticated=False)
            self._store_tokens(data)
        except SessionInvalidated:
            raise
        except GeelyApiError as exc:
            self._clear_tokens()
            raise SessionInvalidated(f'Token refresh failed: {exc}') from exc

    def _do_password_login(self):
        try:
            data = self._request('POST', self._endpoint('auth.password_login'), {
                'username': self.username,
                'password': self.password,
                'countryCode': self.country_code,
                'deviceId': self._device_id,
            }, authenticated=False)
            self._store_tokens(data)
        except GeelyApiError:
            self._record_auth_failure()
            raise
        self._consecutive_failures = 0

    # ----------------------------------------------------------- data APIs

    def get_profile(self):
        return self._request('GET', self._endpoint('account.profile'))

    def get_vehicles(self):
        data = self._request('GET', self._endpoint('vehicle.list'))
        vehicles = data if isinstance(data, list) else _pick(data, 'vehicles', 'list', 'records', default=[])
        return vehicles if isinstance(vehicles, list) else []

    def get_vehicle_capabilities(self, vin):
        try:
            data = self._request('GET', self._endpoint('vehicle.capability').format(vin=vin))
        except SessionInvalidated:
            raise
        except GeelyApiError:
            return {'powertrain': 'EV', 'features': {}}
        return {
            'powertrain': str(_pick(data, 'powertrain', 'energyType', default='EV')).upper(),
            'features': data if isinstance(data, (dict, list)) else {},
        }

    def get_vehicle_status(self, vin):
        """Fetch and normalize telemetry with tolerant key lookup."""
        d = self._request('GET', self._endpoint('vehicle.status').format(vin=vin))
        lat = _pick(d, 'position.latitude', 'location.lat', 'latitude')
        lon = _pick(d, 'position.longitude', 'location.lng', 'longitude')

        def flag(*keys):
            return 1 if _pick(d, *keys, default=False) in (True, 1, '1', 'true', 'open', 'locked', 'on') else 0

        return {
            'vin': vin,
            'model': _pick(d, 'model', 'modelName', 'vehicleModel', default='Geely Vehicle'),
            'year': _pick(d, 'year', 'modelYear', default=''),
            'trim': _pick(d, 'trim', 'trimName', default=''),
            'powertrain': str(_pick(d, 'powertrain', 'energyType', default='EV')).upper(),
            'soc': _pick(d, 'battery.soc', 'evStatus.soc', 'soc', default=0),
            'range': _pick(d, 'battery.range_km', 'evStatus.range', 'range', default=0),
            'range_full': _pick(d, 'battery.range_full_km', 'evStatus.fullRange', default=0),
            'charging_status': _pick(d, 'battery.charging_status', 'evStatus.chargeState', default='idle'),
            'charging_power_kw': _pick(d, 'battery.charging_power_kw', 'evStatus.chargePower', default=0),
            'plug_status': flag('battery.plugged_in', 'evStatus.plugged'),
            'time_to_full_min': _pick(d, 'battery.time_to_full_min', 'evStatus.timeToFull', default=0),
            'scheduled_charge': flag('battery.scheduled_charge', 'evStatus.scheduled'),
            'fuel_level': _pick(d, 'fuel.level_pct', 'fuelLevel', default=0),
            'fuel_range': _pick(d, 'fuel.range_km', 'fuelRange', default=0),
            'lock_status': flag('security.locked', 'doorLockStatus', 'locked'),
            'climate_status': flag('climate.active', 'climateStatus.active'),
            'target_temp': _pick(d, 'climate.target_temp_c', 'climateStatus.targetTemp', default=22),
            'defrost_status': flag('climate.defrost', 'climateStatus.defrost'),
            'g_clean_status': flag('climate.g_clean', 'climateStatus.purifier'),
            'interior_temp': _pick(d, 'climate.interior_temp_c', 'climateStatus.interiorTemp', default=0),
            'exterior_temp': _pick(d, 'climate.exterior_temp_c', 'climateStatus.exteriorTemp', default=0),
            'battery_12v': _pick(d, 'diagnostics.battery_12v_v', 'maintenance.batteryVoltage', default=12.6),
            'service_days': _pick(d, 'diagnostics.service_days', 'maintenance.daysToService', default=0),
            'service_km': _pick(d, 'diagnostics.service_km', 'maintenance.distanceToService', default=0),
            'tpms_fl': _pick(d, 'tpms.front_left_bar', 'tyre.frontLeft', default=2.5),
            'tpms_fr': _pick(d, 'tpms.front_right_bar', 'tyre.frontRight', default=2.5),
            'tpms_rl': _pick(d, 'tpms.rear_left_bar', 'tyre.rearLeft', default=2.5),
            'tpms_rr': _pick(d, 'tpms.rear_right_bar', 'tyre.rearRight', default=2.5),
            'door_fl': flag('doors.front_left', 'doorStatus.driver'),
            'door_fr': flag('doors.front_right', 'doorStatus.passenger'),
            'door_rl': flag('doors.rear_left', 'doorStatus.rearLeft'),
            'door_rr': flag('doors.rear_right', 'doorStatus.rearRight'),
            'trunk_open': flag('doors.trunk', 'doorStatus.trunk'),
            'hood_open': flag('doors.hood', 'doorStatus.hood'),
            'sunroof_open': flag('doors.sunroof', 'windowStatus.sunroof'),
            'windows_open': flag('doors.windows', 'windowStatus.anyOpen'),
            'driver_seatbelt': flag('security.driver_seatbelt', 'seatbelt.driver'),
            'location': f'{lat},{lon}' if lat is not None and lon is not None else '',
            'odometer': _pick(d, 'odometer_km', 'odometer', 'mileage', default=0),
            'last_update': time.strftime('%Y-%m-%d %H:%M:%S'),
        }

    @staticmethod
    def _render_template(node, value):
        """Recursively substitute {timestamp} and {value} placeholders in a command body."""
        if isinstance(node, dict):
            return {k: GeelyApiClient._render_template(v, value) for k, v in node.items()}
        if isinstance(node, list):
            return [GeelyApiClient._render_template(v, value) for v in node]
        if node == '{value}':
            return value
        if node == '{timestamp}':
            return str(int(time.time() * 1000))
        return node

    def send_command(self, vin, command, value=None):
        """Dispatch a remote command through the telematics or charge-server pipe."""
        spec = self.endpoints.get('commands', {}).get(command)
        if not spec:
            raise GeelyApiError(f'Unsupported vehicle command: {command}')
        pipe = self.endpoints.get('pipes', {}).get(spec.get('pipe'))
        if not pipe:
            raise GeelyApiError(f'Command pipe not configured for: {command}')
        path = pipe['path'].format(vin=vin)
        if not path.startswith('/') or '://' in path:
            raise GeelyApiError('Invalid command pipe path.')

        body = self._render_template(spec.get('body', {}), value)
        logging.info('Dispatching command "%s" for vehicle %s', command, self.mask_vin(vin))
        result = self._request(pipe.get('method', 'PUT').upper(), path, body)

        task_id = _pick(result, 'task_id', 'taskId', 'operationId') if isinstance(result, dict) else None
        status_path = self._endpoint('vehicle.command_status')
        if task_id and status_path:
            return self._poll_command_status(status_path.format(vin=vin, task_id=task_id))
        return result

    def _poll_command_status(self, path, max_attempts=6):
        for _ in range(max_attempts):
            time.sleep(2)
            status = self._request('GET', path)
            state = str(_pick(status, 'state', 'status', default='')).lower()
            if state in ('completed', 'success', 'succeeded'):
                return status
            if state in ('failed', 'error', 'rejected'):
                raise GeelyApiError(_pick(status, 'message', default='Vehicle TCU rejected command.'))
        return {'state': 'pending'}
