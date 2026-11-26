# Geely Connect for Jeedom (`geelyconnect`)

A security-hardened **Jeedom Core 4.4+** plugin to monitor and control Geely connected vehicles (EV, PHEV, and ICE) using regional Geely Connect / Geely Global APIs.

---

## Key Features

- **Vehicle Identity & Capability Discovery:** Displays model, year, trim, powertrain (`EV`/`PHEV`/`ICE`), and VIN.
- **Comprehensive Telemetry (40+ Commands):** Battery SoC, range, charging power (kW), plug state, time to full, TPMS (4 tires), 12V battery voltage, interior/exterior temperatures, individual doors/trunk/hood/sunroof/windows, PHEV fuel level & range, and GPS location.
- **Remote Controls with Async TCU Polling:** Lock/Unlock (with confirmation & optional PIN), Climate On/Off, Target Temperature slider, Rapid Cooling/Warming, Defrost, G-Clean, Seat Heating/Ventilation, Find Car, Trunk Unlock, Sunroof, and Scheduled Charging.
- **Built-in Alerts & Jeedom Security Guards:**
  - Automatic alerts for low TPMS, low 12V battery, and openings while locked.
  - **Jeedom Vehicle Alarm** (`alarm_arm` / `alarm_disarm`) with intrusion and GPS movement detection.
  - **Child & Pet Cabin Guard** (`child_pet_guard`) monitoring cabin temperature and auto-enabling climate if unsafe.
- **Defense-in-Depth Security:** Server-side password masking, `0600` bootstrap & mTLS certificate storage, `127.0.0.1` socket with constant-time `hmac.compare_digest` / `hash_equals` API key validation, TLS `verify=True`, exponential auth backoff, and automatic log redaction.

---

## Disclaimer & Limitation of Liability

1. **No Official Affiliation:** This plugin is an independent, community-developed project. It is **not affiliated with, endorsed by, or supported by** Geely Auto, Zhejiang Geely Holding Group, or any of their subsidiaries. All trademarks and brand names belong to their respective owners.
2. **Provided "AS-IS" (AGPL License):** This software is provided "as is", without warranty of any kind, express or implied. The authors and contributors shall not be held liable for any direct, indirect, incidental, or consequential damages arising from its use, including but not limited to API changes, account rate-limiting/suspension, 12V battery drain from excessive polling, or failed remote commands.
3. **Life-Safety & Security Warning (CRITICAL):**
   - **Vehicle Alarm and Child & Pet Cabin Guard** rely on cloud telematics, mobile network coverage, and periodic polling. Cloud latency or connectivity outages may delay or prevent alerts and remote actions.
   - **NEVER** leave children, pets, or vulnerable individuals unattended inside a vehicle relying on home automation or this plugin. These features are strictly supplementary and **must never replace direct human supervision** or homologated vehicle safety systems.
4. **User Responsibility:** You are solely responsible for securing your Jeedom instance, API keys, Geely account credentials, unlock PIN, and compliance with local laws regarding vehicle remote control and privacy.

---

## Documentation

Full installation, security architecture, and command documentation is available in [docs/en_US/index.md](docs/en_US/index.md).
