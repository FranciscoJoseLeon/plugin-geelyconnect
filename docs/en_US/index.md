# Geely Connect Plugin for Jeedom

**Geely Connect** (`geelyconnect`) integrates your connected Geely vehicle (EV, PHEV, or ICE) into **Jeedom Core 4.4+**, providing real-time battery and vehicle telemetry, a modern glassmorphism automotive dashboard widget, and security-hardened remote controls.

---

## Features

- **Real-Time Telemetry:** Battery State of Charge (`soc`), remaining range (`range`), charging status (`charging_status`), door lock state (`lock_status`), climate/HVAC state (`climate_status`), GPS coordinates (`location`), odometer (`odometer`), and last sync timestamp (`last_update`).
- **Remote Vehicle Control:** Lock doors, unlock doors (with confirmation & optional PIN safeguard), toggle climate control (`climate_on` / `climate_off`), and force telemetry refresh (`refresh`).
- **Automotive Dashboard & Mobile Widgets:** Dark-mode glassmorphism card with dynamic SVG battery gauge, animated charging and HVAC indicators, quick action dock, and one-click OpenStreetMap location link.
- **Auto-Discovery:** Automatically discovers vehicles linked to your Geely Connect account and provisions all commands.

---

## Security Architecture

Geely Connect is built with defense-in-depth principles:

1. **Zero Plaintext Secrets in DOM or Process Lists:**
   - Your Geely account password and optional Unlock PIN are stored server-side via Jeedom's configuration storage and are never echoed back into HTML inputs on page reload.
   - Credentials are passed to the Python daemon exclusively through a `0600`-permission bootstrap file that is immediately deleted upon startup, and live updates are sent over an authenticated `127.0.0.1` TCP socket. Secrets never appear in `ps aux`.
2. **Constant-Time Authentication & CSRF/XSS Protection:**
   - Administrative AJAX endpoints enforce `ajax::init()` and `isConnect('admin')`.
   - Callback (`jeeGeelyconnect.php`) and local daemon socket communications validate the plugin API key using `hash_equals()` / `hmac.compare_digest()`.
   - All telemetry strings and GPS coordinates are sanitized and range-validated before rendering.
3. **Network & Log Hardening:**
   - All HTTPS requests to Geely cloud endpoints enforce strict TLS certificate verification (`verify=True`).
   - Exponential backoff protects your Geely account from lockouts on failed logins.
   - Sensitive tokens, passwords, and VINs are automatically redacted from logs, even in `DEBUG` mode.
4. **High-Risk Action Safeguards:**
   - Door unlock commands support Jeedom's native action confirmation and an optional 4–8 digit security PIN.

---

## Installation & Configuration

1. Install **Geely Connect** from the Jeedom Market.
2. Open **Plugins → Automation → Geely Connect** and click **Install Dependencies** to set up the isolated Python virtual environment (`requests`, `pycryptodome`).
3. In **Plugin Configuration**:
   - Enter your Geely **Email / Username** and **Password**.
   - Select your **Region** (`EU`, `GLOBAL`, or `APAC`).
   - Set the **Polling Interval** (default: `5` minutes) and **Local Socket Port** (default: `55099`).
   - *(Optional)* Configure a 4–8 digit **Unlock PIN** for door unlock protection.
4. Save the configuration and start the **Geely Connect Daemon**.
5. Click **Sync Vehicles** on the plugin page to auto-discover your vehicle(s).

---

## Commands Reference

### Info Commands
| Logical ID | Name | Type | Unit | Description |
| :--- | :--- | :--- | :--- | :--- |
| `vin` | VIN | String | — | Vehicle Identification Number |
| `soc` | State of Charge | Numeric | `%` | High-voltage battery level (0–100%) |
| `range` | Remaining Range | Numeric | `km` | Estimated remaining driving range |
| `charging_status` | Charging Status | String | — | Current charging state (`idle`, `charging`, etc.) |
| `lock_status` | Lock Status | Binary | — | `1` = Locked, `0` = Unlocked |
| `climate_status` | Climate Status | Binary | — | `1` = Active, `0` = Off |
| `location` | GPS Location | String | — | Latitude,Longitude coordinates |
| `odometer` | Odometer | Numeric | `km` | Total vehicle mileage |
| `last_update` | Last Sync | String | — | Timestamp of the last telemetry update |

### Action Commands
| Logical ID | Name | Safeguard | Description |
| :--- | :--- | :--- | :--- |
| `refresh` | Refresh | — | Requests an immediate telemetry poll |
| `lock` | Lock Doors | — | Remotely locks the vehicle doors |
| `unlock` | Unlock Doors | Confirmation + Optional PIN | Remotely unlocks the vehicle doors |
| `climate_on` | Climate On | — | Starts cabin pre-conditioning |
| `climate_off` | Climate Off | — | Stops cabin pre-conditioning |

---

## Best Practices & Battery Protection

- **12V Battery Protection:** Keep the **Polling Interval** at `5` minutes or higher. Frequent polling (< 2 minutes) can repeatedly wake the vehicle's Telematics Control Unit (TCU) and accelerate 12V battery drain when parked for long periods.
- **Unattended Vehicles:** Always verify physical door locks when leaving the vehicle in high-risk areas.

---

## Disclaimer & Limitation of Liability

- **No Official Affiliation:** Geely Connect is an independent, community-developed plugin and is **not** affiliated with, endorsed by, or supported by Geely Auto or Zhejiang Geely Holding Group.
- **AS-IS Warranty (AGPL):** This software is provided "as is" without warranty of any kind. The authors and contributors accept no liability for account lockouts, API disruptions, 12V battery discharge, or failed remote commands.
- **Life-Safety Notice:** The **Vehicle Alarm** and **Child & Pet Cabin Guard** features depend on third-party cloud APIs and cellular connectivity. **Never** leave children or pets unattended inside a vehicle relying on this plugin or any home automation alert.

