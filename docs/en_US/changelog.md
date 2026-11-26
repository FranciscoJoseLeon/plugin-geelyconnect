# Changelog - Geely Connect

## 1.0.0
- Initial production release for Jeedom Core 4.4+.
- Added isolated Python `venv` daemon (`geelyconnectd`) with `requests` and `pycryptodome` via `packages.json`.
- Implemented `GeelyApiClient` with regional endpoint support (`EU`, `GLOBAL`, `APAC`), TLS verification, payload signing/encryption, token auto-refresh, and exponential backoff.
- Hardened credential storage (server-side password masking, `0600` bootstrap handoff, `127.0.0.1` socket with constant-time API key verification, log redaction).
- Added optional 4–8 digit Unlock PIN and confirmation safeguards for door unlock actions.
- Designed dark-mode glassmorphism automotive dashboard and mobile widgets (`toHtml()`).
- Added full English documentation (`docs/en_US/index.md`).
