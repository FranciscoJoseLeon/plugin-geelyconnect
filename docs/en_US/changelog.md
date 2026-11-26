# Changelog - Geely Connect

## 1.2.0
- Added configurable endpoint map (`resources/geelyconnectd/endpoints.json` + `data/endpoints.json` overrides with Geely HTTPS host allowlist).
- Added interactive human-solved GeeTest v3/v4 challenge relay in plugin configuration UI (`authStart` -> `requestOtp`).
- Added local OpenSSL CSR/key pair generation (`provision_mtls`) and automatic mTLS client certificate provisioning (`0600`).
- Added automatic token refresh, single-session invalidation detection (`SessionInvalidated`), and Jeedom message center notifications.
- Hardened `RedactingFormatter` to mask Bearer tokens, private keys, CSRs, and captcha tokens in logs.

## 1.1.0
- Added Geely Global 2-step Email OTP & mTLS certificate provisioning (`requestOtp` / `verifyOtp`) and ISO-2 country code support.
- Added capability-driven vehicle identity (`model`, `year`, `trim`, `powertrain` EV/PHEV/ICE) displayed in desktop cards and widget header.
- Expanded telemetry to 40+ info commands: TPMS (4 tires), 12V battery voltage, interior/exterior temperatures, individual door/trunk/hood/sunroof/window sensors, charging power (kW), plug status, time to full, service due, and PHEV fuel level/range.
- Added advanced remote controls with asynchronous TCU polling: target temperature slider, Rapid Cooling/Warming, Defrost, G-Clean, driver seat heat/ventilation, Find Car, trunk unlock, sunroof, and scheduled charging.
- Added sensor-based alerts (`tpms_alert`, `low_12v_alert`, `open_while_locked_alert`, `cabin_overheat_alert`), Jeedom Vehicle Alarm (`alarm_arm` / `alarm_disarm`), and Child & Pet Cabin Guard (`child_pet_guard_on` / `child_pet_guard_off`) with automatic climate protection.

## 1.0.0
- Initial production release for Jeedom Core 4.4+.
