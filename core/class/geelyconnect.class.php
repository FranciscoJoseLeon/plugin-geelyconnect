<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

/**
 * Main eqLogic class for Geely Connect.
 * Manages vehicle equipment, dependency/daemon lifecycle, capability-driven commands,
 * sensor-based alerts, Jeedom alarm & sentry/child-pet guards, and custom automotive widgets.
 */
class geelyconnect extends eqLogic {

    const DEFAULT_SOCKET_PORT = 55099;
    const DEFAULT_POLL_INTERVAL = 5;

    /**
     * Return the path to the isolated Python virtual environment binary.
     *
     * @return string
     */
    public static function getPythonBinary() {
        return realpath(__DIR__ . '/../../resources/venv/bin/python3') ?: (__DIR__ . '/../../resources/venv/bin/python3');
    }

    /**
     * Report dependency installation status to Jeedom Core.
     *
     * @return array<string, mixed>
     */
    public static function dependancy_info() {
        $return = array();
        $return['log'] = log::getPathToLog(__CLASS__ . '_dep');
        $return['progress_file'] = jeedom::getTmpFolder(__CLASS__) . '/dependancy';
        $return['state'] = 'nok';

        $pythonBin = self::getPythonBinary();
        if (file_exists($pythonBin) && is_executable($pythonBin)) {
            $output = array();
            $exitCode = 1;
            exec(escapeshellcmd($pythonBin) . ' -c "import requests" 2>/dev/null', $output, $exitCode);
            if ($exitCode === 0) {
                $return['state'] = 'ok';
            }
        }
        return $return;
    }

    /**
     * Install APT and Python virtual environment dependencies via Jeedom's standard install_#stype#.sh.
     *
     * @return array<string, string>
     */
    public static function dependancy_install() {
        log::remove(__CLASS__ . '_dep');
        return array(
            'script' => __DIR__ . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder(__CLASS__) . '/dependancy',
            'log' => log::getPathToLog(__CLASS__ . '_dep')
        );
    }

    /**
     * Return the path to the daemon runtime directory inside Jeedom tmp folder.
     *
     * @return string
     */
    public static function getRuntimeDir() {
        $dir = jeedom::getTmpFolder('geelyconnect');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /**
     * Return the persistent plugin data directory (0700).
     *
     * @return string
     */
    public static function getDataDir() {
        $dir = __DIR__ . '/../../data';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        return realpath($dir) ?: $dir;
    }

    /**
     * Return the persistent directory for mTLS certificates (0700).
     *
     * @return string
     */
    public static function getCertDir() {
        $dir = self::getDataDir() . '/certs';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        return realpath($dir) ?: $dir;
    }

    /**
     * Return the configured local socket port, validated within safe bounds.
     *
     * @return int
     */
    public static function getSocketPort() {
        $port = (int) config::byKey('socketport', 'geelyconnect', self::DEFAULT_SOCKET_PORT);
        if ($port < 1024 || $port > 65535) {
            return self::DEFAULT_SOCKET_PORT;
        }
        return $port;
    }

    /**
     * Report daemon status and launchability to Jeedom Core.
     *
     * @return array<string, mixed>
     */
    public static function deamon_info() {
        $return = array(
            'log' => 'geelyconnect',
            'state' => 'nok',
            'launchable' => 'ok',
            'launchable_message' => ''
        );

        $pidFile = self::getRuntimeDir() . '/deamon.pid';
        if (file_exists($pidFile)) {
            $pid = (int) trim((string) @file_get_contents($pidFile));
            if ($pid > 0 && @posix_getpgid($pid) !== false) {
                $return['state'] = 'ok';
            } else {
                @unlink($pidFile);
            }
        }

        $pythonBin = self::getPythonBinary();
        if (!file_exists($pythonBin) || !is_executable($pythonBin)) {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Python virtual environment is missing. Please install dependencies first.', __FILE__);
            return $return;
        }

        $username = trim((string) config::byKey('username', 'geelyconnect', ''));
        if ($username === '') {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Geely account email / username must be configured.', __FILE__);
            return $return;
        }

        return $return;
    }

    /**
     * Start the Geely Connect Python daemon via a 0600 bootstrap file.
     *
     * @return void
     * @throws Exception
     */
    public static function deamon_start() {
        self::deamon_stop();

        $deamonInfo = self::deamon_info();
        if ($deamonInfo['launchable'] !== 'ok') {
            throw new Exception(__('Cannot start daemon: ', __FILE__) . $deamonInfo['launchable_message']);
        }

        $runtimeDir = self::getRuntimeDir();
        $pidFile = $runtimeDir . '/deamon.pid';
        $bootstrapFile = $runtimeDir . '/daemon_bootstrap.json';
        $daemonScript = realpath(__DIR__ . '/../../resources/geelyconnectd/geelyconnectd.py');

        if ($daemonScript === false || !file_exists($daemonScript)) {
            throw new Exception(__('Daemon script geelyconnectd.py not found.', __FILE__));
        }

        $pollInterval = max(1, (int) config::byKey('poll_interval', 'geelyconnect', self::DEFAULT_POLL_INTERVAL));
        $region = strtoupper(trim((string) config::byKey('region', 'geelyconnect', 'EU')));
        if (!in_array($region, array('EU', 'GLOBAL', 'APAC'), true)) {
            $region = 'EU';
        }
        $countryCode = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) config::byKey('country_code', 'geelyconnect', 'ES')));
        if ($countryCode === '') {
            $countryCode = 'ES';
        }

        $bootstrapPayload = array(
            'apikey' => jeedom::getApiKey('geelyconnect'),
            'callback' => network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/geelyconnect/core/php/jeeGeelyconnect.php',
            'username' => (string) config::byKey('username', 'geelyconnect', ''),
            'password' => (string) config::byKey('password', 'geelyconnect', ''),
            'region' => $region,
            'country_code' => $countryCode,
            'cert_dir' => self::getCertDir(),
            'poll_interval' => $pollInterval,
            'socketport' => self::getSocketPort(),
            'vins' => self::getConfiguredVins()
        );

        $oldUmask = umask(0077);
        $written = file_put_contents($bootstrapFile, json_encode($bootstrapPayload));
        umask($oldUmask);
        if ($written === false) {
            throw new Exception(__('Failed to write restricted daemon bootstrap file.', __FILE__));
        }
        @chmod($bootstrapFile, 0600);

        $logLevel = log::convertLogLevel(log::getLogLevel('geelyconnect'));
        $cmd = escapeshellcmd(self::getPythonBinary()) . ' ' . escapeshellarg($daemonScript);
        $cmd .= ' --loglevel ' . escapeshellarg($logLevel);
        $cmd .= ' --socketport ' . escapeshellarg((string) self::getSocketPort());
        $cmd .= ' --pid ' . escapeshellarg($pidFile);
        $cmd .= ' --bootstrap ' . escapeshellarg($bootstrapFile);

        log::add('geelyconnect', 'info', __('Starting Geely Connect daemon...', __FILE__));
        exec('nohup ' . $cmd . ' >> ' . log::getPathToLog('geelyconnect') . ' 2>&1 &');

        $waitCount = 0;
        while ($waitCount < 20) {
            usleep(500000);
            $info = self::deamon_info();
            if ($info['state'] === 'ok') {
                log::add('geelyconnect', 'info', __('Geely Connect daemon started successfully.', __FILE__));
                return;
            }
            $waitCount++;
        }

        log::add('geelyconnect', 'error', __('Geely Connect daemon failed to start within timeout.', __FILE__));
    }

    /**
     * Stop the Geely Connect Python daemon cleanly.
     *
     * @return void
     */
    public static function deamon_stop() {
        $runtimeDir = self::getRuntimeDir();
        $pidFile = $runtimeDir . '/deamon.pid';
        $bootstrapFile = $runtimeDir . '/daemon_bootstrap.json';

        if (file_exists($bootstrapFile)) {
            @unlink($bootstrapFile);
        }

        if (file_exists($pidFile)) {
            $pid = (int) trim((string) @file_get_contents($pidFile));
            if ($pid > 0 && @posix_getpgid($pid) !== false) {
                log::add('geelyconnect', 'info', __('Stopping Geely Connect daemon...', __FILE__));
                @posix_kill($pid, SIGTERM);
                $wait = 0;
                while ($wait < 10 && @posix_getpgid($pid) !== false) {
                    usleep(250000);
                    $wait++;
                }
                if (@posix_getpgid($pid) !== false) {
                    @posix_kill($pid, SIGKILL);
                }
            }
            @unlink($pidFile);
        }

        // Clean up any orphaned geelyconnectd.py process holding the socket port.
        exec('pkill -f "geelyconnectd/geelyconnectd.py" 2>/dev/null');
        usleep(300000);
    }

    /**
     * Collect all enabled vehicle VINs configured in Jeedom eqLogics.
     *
     * @return array<int, string>
     */
    public static function getConfiguredVins() {
        $vins = array();
        foreach (eqLogic::byType('geelyconnect', true) as $eqLogic) {
            $vin = trim((string) $eqLogic->getConfiguration('vin', ''));
            if ($vin !== '') {
                $vins[] = strtoupper($vin);
            }
        }
        return array_values(array_unique($vins));
    }

    /**
     * Send an authenticated JSON command to the localhost-only Python daemon socket.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws Exception
     */
    public static function sendToDaemon(array $payload, $timeout = 20) {
        $port = self::getSocketPort();
        $payload['apikey'] = jeedom::getApiKey('geelyconnect');

        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 5);
        if ($socket === false) {
            throw new Exception(__('Unable to connect to Geely Connect daemon: ', __FILE__) . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($socket, max(5, (int) $timeout));
        $encoded = json_encode($payload) . "\n";
        fwrite($socket, $encoded);

        $responseRaw = fgets($socket);
        fclose($socket);

        if ($responseRaw === false || trim($responseRaw) === '') {
            throw new Exception(__('Empty response received from Geely Connect daemon.', __FILE__));
        }

        $decoded = json_decode($responseRaw, true);
        if (!is_array($decoded)) {
            throw new Exception(__('Invalid JSON response received from Geely Connect daemon.', __FILE__));
        }

        if (isset($decoded['status']) && $decoded['status'] === 'error') {
            $msg = isset($decoded['message']) ? (string) $decoded['message'] : __('Daemon reported an error.', __FILE__);
            throw new Exception($msg);
        }

        return $decoded;
    }

    /**
     * Push updated credentials and settings to a running daemon over the authenticated local socket.
     *
     * @return void
     */
    public static function pushConfigToDaemon() {
        $info = self::deamon_info();
        if ($info['state'] !== 'ok') {
            return;
        }
        try {
            self::sendToDaemon(array(
                'action' => 'configure',
                'username' => (string) config::byKey('username', 'geelyconnect', ''),
                'password' => (string) config::byKey('password', 'geelyconnect', ''),
                'region' => strtoupper(trim((string) config::byKey('region', 'geelyconnect', 'EU'))),
                'country_code' => strtoupper(trim((string) config::byKey('country_code', 'geelyconnect', 'ES'))),
                'poll_interval' => max(1, (int) config::byKey('poll_interval', 'geelyconnect', self::DEFAULT_POLL_INTERVAL)),
                'vins' => self::getConfiguredVins()
            ));
        } catch (Exception $e) {
            log::add('geelyconnect', 'warning', __('Could not push live config to daemon: ', __FILE__) . $e->getMessage());
        }
    }

    /**
     * Automatically provision capability-driven Info, Alert, Guard, and Action commands.
     *
     * @return void
     */
    public function postSave() {
        $powertrain = strtoupper((string) $this->getConfiguration('powertrain', 'EV'));

        $infoCommands = array(
            'vin' => array('name' => __('VIN', __FILE__), 'subType' => 'string', 'unite' => '', 'visible' => 1, 'order' => 1),
            'model_info' => array('name' => __('Vehicle Model', __FILE__), 'subType' => 'string', 'unite' => '', 'visible' => 1, 'order' => 2),
            'soc' => array('name' => __('State of Charge', __FILE__), 'subType' => 'numeric', 'unite' => '%', 'visible' => 1, 'order' => 3, 'min' => 0, 'max' => 100),
            'range' => array('name' => __('Remaining Range', __FILE__), 'subType' => 'numeric', 'unite' => 'km', 'visible' => 1, 'order' => 4, 'min' => 0),
            'range_full' => array('name' => __('Full Charge Range', __FILE__), 'subType' => 'numeric', 'unite' => 'km', 'visible' => 0, 'order' => 5, 'min' => 0),
            'charging_status' => array('name' => __('Charging Status', __FILE__), 'subType' => 'string', 'unite' => '', 'visible' => 1, 'order' => 6),
            'charging_power_kw' => array('name' => __('Charging Power', __FILE__), 'subType' => 'numeric', 'unite' => 'kW', 'visible' => 1, 'order' => 7, 'min' => 0),
            'plug_status' => array('name' => __('Charger Plugged In', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 1, 'order' => 8),
            'time_to_full_min' => array('name' => __('Time to Full Charge', __FILE__), 'subType' => 'numeric', 'unite' => 'min', 'visible' => 1, 'order' => 9, 'min' => 0),
            'scheduled_charge' => array('name' => __('Scheduled Charging', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 0, 'order' => 10),
            'lock_status' => array('name' => __('Lock Status', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 1, 'order' => 11),
            'climate_status' => array('name' => __('Climate Status', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 1, 'order' => 12),
            'target_temp' => array('name' => __('Target Temperature', __FILE__), 'subType' => 'numeric', 'unite' => '°C', 'visible' => 1, 'order' => 13, 'min' => 16, 'max' => 30),
            'defrost_status' => array('name' => __('Defrost Status', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 0, 'order' => 14),
            'g_clean_status' => array('name' => __('G-Clean Status', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 0, 'order' => 15),
            'interior_temp' => array('name' => __('Interior Temperature', __FILE__), 'subType' => 'numeric', 'unite' => '°C', 'visible' => 1, 'order' => 16),
            'exterior_temp' => array('name' => __('Exterior Temperature', __FILE__), 'subType' => 'numeric', 'unite' => '°C', 'visible' => 1, 'order' => 17),
            'battery_12v' => array('name' => __('12V Battery Voltage', __FILE__), 'subType' => 'numeric', 'unite' => 'V', 'visible' => 1, 'order' => 18),
            'tpms_fl' => array('name' => __('Tire Pressure FL', __FILE__), 'subType' => 'numeric', 'unite' => 'bar', 'visible' => 1, 'order' => 19),
            'tpms_fr' => array('name' => __('Tire Pressure FR', __FILE__), 'subType' => 'numeric', 'unite' => 'bar', 'visible' => 1, 'order' => 20),
            'tpms_rl' => array('name' => __('Tire Pressure RL', __FILE__), 'subType' => 'numeric', 'unite' => 'bar', 'visible' => 1, 'order' => 21),
            'tpms_rr' => array('name' => __('Tire Pressure RR', __FILE__), 'subType' => 'numeric', 'unite' => 'bar', 'visible' => 1, 'order' => 22),
            'door_fl' => array('name' => __('Door Front Left', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 0, 'order' => 23),
            'door_fr' => array('name' => __('Door Front Right', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 0, 'order' => 24),
            'door_rl' => array('name' => __('Door Rear Left', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 0, 'order' => 25),
            'door_rr' => array('name' => __('Door Rear Right', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 0, 'order' => 26),
            'trunk_open' => array('name' => __('Trunk Open', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 0, 'order' => 27),
            'hood_open' => array('name' => __('Hood Open', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 0, 'order' => 28),
            'sunroof_open' => array('name' => __('Sunroof Open', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 0, 'order' => 29),
            'windows_open' => array('name' => __('Windows Open', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 0, 'order' => 30),
            'driver_seatbelt' => array('name' => __('Driver Seatbelt', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 0, 'order' => 31),
            'service_days' => array('name' => __('Service Due (Days)', __FILE__), 'subType' => 'numeric', 'unite' => 'd', 'visible' => 0, 'order' => 32),
            'service_km' => array('name' => __('Service Due (km)', __FILE__), 'subType' => 'numeric', 'unite' => 'km', 'visible' => 0, 'order' => 33),
            'location' => array('name' => __('GPS Location', __FILE__), 'subType' => 'string', 'unite' => '', 'visible' => 1, 'order' => 34),
            'odometer' => array('name' => __('Odometer', __FILE__), 'subType' => 'numeric', 'unite' => 'km', 'visible' => 1, 'order' => 35, 'min' => 0),
            'last_update' => array('name' => __('Last Sync', __FILE__), 'subType' => 'string', 'unite' => '', 'visible' => 1, 'order' => 36),
            // Sensor-based alerts and Jeedom Alarm / Child & Pet Guard states
            'alarm_armed' => array('name' => __('Vehicle Alarm Armed', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 1, 'order' => 37),
            'alarm_triggered' => array('name' => __('Vehicle Alarm Triggered', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 1, 'order' => 38),
            'child_pet_guard' => array('name' => __('Child & Pet Cabin Guard', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 1, 'order' => 39),
            'cabin_overheat_alert' => array('name' => __('Cabin Overheat / Cold Alert', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 1, 'order' => 40),
            'tpms_alert' => array('name' => __('Low Tire Pressure Alert', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 1, 'order' => 41),
            'open_while_locked_alert' => array('name' => __('Open While Locked Alert', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 1, 'order' => 42),
            'low_12v_alert' => array('name' => __('Low 12V Battery Alert', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 1, 'order' => 43),
            'alert_message' => array('name' => __('Active Alerts Summary', __FILE__), 'subType' => 'string', 'unite' => '', 'visible' => 1, 'order' => 44)
        );

        if (in_array($powertrain, array('PHEV', 'ICE', 'HYBRID'), true)) {
            $infoCommands['fuel_level'] = array('name' => __('Fuel Level', __FILE__), 'subType' => 'numeric', 'unite' => '%', 'visible' => 1, 'order' => 47, 'min' => 0, 'max' => 100);
            $infoCommands['fuel_range'] = array('name' => __('Fuel Range', __FILE__), 'subType' => 'numeric', 'unite' => 'km', 'visible' => 1, 'order' => 48, 'min' => 0);
        }

        foreach ($infoCommands as $logicalId => $meta) {
            $this->ensureCommand($logicalId, 'info', $meta);
        }

        $actionCommands = array(
            'refresh' => array('name' => __('Refresh', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 50),
            'lock' => array('name' => __('Lock Doors', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 51),
            'unlock' => array('name' => __('Unlock Doors', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 52, 'confirm' => 1),
            'climate_on' => array('name' => __('Climate On', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 53),
            'climate_off' => array('name' => __('Climate Off', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 54),
            'set_temp' => array('name' => __('Set Target Temperature', __FILE__), 'subType' => 'slider', 'visible' => 1, 'order' => 55, 'min' => 16, 'max' => 30),
            'rapid_cool' => array('name' => __('Rapid Cooling', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 56),
            'rapid_warm' => array('name' => __('Rapid Warming', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 57),
            'defrost_on' => array('name' => __('Defrost On', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 58),
            'defrost_off' => array('name' => __('Defrost Off', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 59),
            'g_clean_on' => array('name' => __('G-Clean On', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 60),
            'g_clean_off' => array('name' => __('G-Clean Off', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 61),
            'seat_heat_driver' => array('name' => __('Driver Seat Heat', __FILE__), 'subType' => 'slider', 'visible' => 0, 'order' => 62, 'min' => 0, 'max' => 3),
            'seat_vent_driver' => array('name' => __('Driver Seat Vent', __FILE__), 'subType' => 'slider', 'visible' => 0, 'order' => 63, 'min' => 0, 'max' => 3),
            'find_car' => array('name' => __('Find Car (Lights & Horn)', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 64),
            'unlock_trunk' => array('name' => __('Unlock Trunk', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 65, 'confirm' => 1),
            'sunroof_open' => array('name' => __('Open Sunroof', __FILE__), 'subType' => 'other', 'visible' => 0, 'order' => 66),
            'sunroof_close' => array('name' => __('Close Sunroof', __FILE__), 'subType' => 'other', 'visible' => 0, 'order' => 67),
            'scheduled_charge_on' => array('name' => __('Scheduled Charge On', __FILE__), 'subType' => 'other', 'visible' => 0, 'order' => 68),
            'scheduled_charge_off' => array('name' => __('Scheduled Charge Off', __FILE__), 'subType' => 'other', 'visible' => 0, 'order' => 69),
            // Local Jeedom Security & Guard commands
            'alarm_arm' => array('name' => __('Arm Vehicle Alarm', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 70),
            'alarm_disarm' => array('name' => __('Disarm Vehicle Alarm', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 71, 'confirm' => 1),
            'child_pet_guard_on' => array('name' => __('Enable Child & Pet Guard', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 72),
            'child_pet_guard_off' => array('name' => __('Disable Child & Pet Guard', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 73)
        );

        foreach ($actionCommands as $logicalId => $meta) {
            $this->ensureCommand($logicalId, 'action', $meta);
        }

        self::pushConfigToDaemon();
    }

    /**
     * Create a command on this eqLogic if it does not yet exist.
     *
     * @param string $logicalId
     * @param string $type
     * @param array<string, mixed> $meta
     * @return void
     */
    private function ensureCommand($logicalId, $type, array $meta) {
        $cmd = $this->getCmd($type, $logicalId);
        if (!is_object($cmd)) {
            $cmd = new geelyconnectCmd();
            $cmd->setLogicalId($logicalId);
            $cmd->setEqLogic_id($this->getId());
            $cmd->setName($meta['name']);
            $cmd->setType($type);
            $cmd->setSubType($meta['subType']);
            $cmd->setIsVisible($meta['visible']);
            $cmd->setOrder($meta['order']);
            if (isset($meta['unite']) && $meta['unite'] !== '') {
                $cmd->setUnite($meta['unite']);
            }
            if (isset($meta['min'])) {
                $cmd->setConfiguration('minValue', $meta['min']);
            }
            if (isset($meta['max'])) {
                $cmd->setConfiguration('maxValue', $meta['max']);
            }
            if (!empty($meta['confirm'])) {
                $cmd->setConfiguration('actionConfirm', 1);
            }
            $cmd->save();
        }
    }

    /**
     * Evaluate sensor-based alerts, Jeedom Vehicle Alarm, Sentry Mode, and Child & Pet Cabin Guard.
     *
     * @param array<string, mixed> $telemetry
     * @return void
     */
    public function evaluateAlertsAndGuards(array $telemetry) {
        $alerts = array();

        $tpmsValues = array(
            (float) ($telemetry['tpms_fl'] ?? 2.5),
            (float) ($telemetry['tpms_fr'] ?? 2.5),
            (float) ($telemetry['tpms_rl'] ?? 2.5),
            (float) ($telemetry['tpms_rr'] ?? 2.5)
        );
        $lowTpms = min($tpmsValues) > 0 && min($tpmsValues) < 2.0;
        $this->checkAndUpdateCmd('tpms_alert', $lowTpms ? 1 : 0);
        if ($lowTpms) {
            $alerts[] = __('Low tire pressure detected', __FILE__);
        }

        $bat12v = (float) ($telemetry['battery_12v'] ?? 12.6);
        $low12v = $bat12v > 0 && $bat12v < 11.8;
        $this->checkAndUpdateCmd('low_12v_alert', $low12v ? 1 : 0);
        if ($low12v) {
            $alerts[] = sprintf(__('Low 12V battery (%.1fV)', __FILE__), $bat12v);
        }

        $anyOpen = !empty($telemetry['door_fl']) || !empty($telemetry['door_fr']) ||
                   !empty($telemetry['door_rl']) || !empty($telemetry['door_rr']) ||
                   !empty($telemetry['trunk_open']) || !empty($telemetry['hood_open']) ||
                   !empty($telemetry['sunroof_open']) || !empty($telemetry['windows_open']);
        $isLocked = !empty($telemetry['lock_status']);

        $openWhileLocked = ($isLocked && $anyOpen);
        $this->checkAndUpdateCmd('open_while_locked_alert', $openWhileLocked ? 1 : 0);
        if ($openWhileLocked) {
            $alerts[] = __('Opening detected while vehicle is locked', __FILE__);
        }

        // Child & Pet Cabin Guard: monitors cabin temperature and auto-starts AC if unsafe.
        $guardCmd = $this->getCmd('info', 'child_pet_guard');
        $guardOn = is_object($guardCmd) && ((int) $guardCmd->execCmd()) === 1;
        $interiorTemp = (float) ($telemetry['interior_temp'] ?? 22);
        $unsafeCabin = $guardOn && ($interiorTemp >= 28.0 || $interiorTemp <= 5.0);
        $this->checkAndUpdateCmd('cabin_overheat_alert', $unsafeCabin ? 1 : 0);
        if ($unsafeCabin) {
            $alerts[] = sprintf(__('CRITICAL: Unsafe cabin temperature (%.1f°C)', __FILE__), $interiorTemp);
            message::add('geelyconnect', sprintf(__('[%s] Child & Pet Cabin Guard ALERT: interior at %.1f°C! Auto-enabling climate.', __FILE__), $this->getName(), $interiorTemp));
            if (empty($telemetry['climate_status'])) {
                try {
                    self::sendToDaemon(array(
                        'action' => 'command',
                        'vin' => strtoupper((string) $this->getConfiguration('vin', '')),
                        'command' => 'climate_on'
                    ));
                } catch (Exception $e) {
                    log::add('geelyconnect', 'error', 'Child/Pet Guard auto-climate failed: ' . $e->getMessage());
                }
            }
        }

        // Jeedom Vehicle Alarm: detect unexpected opening or GPS movement while armed.
        $alarmCmd = $this->getCmd('info', 'alarm_armed');
        $alarmArmed = is_object($alarmCmd) && ((int) $alarmCmd->execCmd()) === 1;

        $gpsMoved = false;
        $armedLoc = (string) $this->getConfiguration('armed_location', '');
        $currentLoc = (string) ($telemetry['location'] ?? '');
        if ($alarmArmed && $armedLoc !== '' && $currentLoc !== '' && self::distanceMeters($armedLoc, $currentLoc) > 100.0) {
            $gpsMoved = true;
        }

        $intrusion = ($anyOpen || !$isLocked || $gpsMoved);
        if ($alarmArmed && $intrusion) {
            $this->checkAndUpdateCmd('alarm_triggered', 1);
            $alerts[] = __('ALARM TRIGGERED (intrusion or movement)', __FILE__);
            message::add('geelyconnect', sprintf(__('[%s] VEHICLE ALARM TRIGGERED! Check doors/GPS immediately.', __FILE__), $this->getName()));
        }

        $this->checkAndUpdateCmd('alert_message', empty($alerts) ? 'OK' : implode(' | ', $alerts));
    }

    /**
     * Compute approximate distance in meters between two "lat,lon" strings.
     *
     * @param string $locA
     * @param string $locB
     * @return float
     */
    public static function distanceMeters($locA, $locB) {
        if (!preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $locA, $a) ||
            !preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $locB, $b)) {
            return 0.0;
        }
        $lat1 = deg2rad((float) $a[1]);
        $lon1 = deg2rad((float) $a[2]);
        $lat2 = deg2rad((float) $b[1]);
        $lon2 = deg2rad((float) $b[2]);
        $dLat = $lat2 - $lat1;
        $dLon = $lon2 - $lon1;
        $h = sin($dLat / 2) * sin($dLat / 2) + cos($lat1) * cos($lat2) * sin($dLon / 2) * sin($dLon / 2);
        return 2 * 6371000 * asin(min(1.0, sqrt($h)));
    }

    /**
     * Validate GPS coordinates ("lat,lon") and return a safe OpenStreetMap URL or empty string.
     *
     * @param string $rawLocation
     * @return string
     */
    public static function buildSafeMapUrl($rawLocation) {
        if (!preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $rawLocation, $matches)) {
            return '';
        }
        $lat = (float) $matches[1];
        $lon = (float) $matches[2];
        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            return '';
        }
        return 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string) $lat) . '&mlon=' . rawurlencode((string) $lon) . '#map=16/' . rawurlencode((string) $lat) . '/' . rawurlencode((string) $lon);
    }

    /**
     * Render the custom automotive widget for dashboard and mobile views.
     *
     * @param string $_version
     * @return string
     */
    public function toHtml($_version = 'dashboard') {
        if ($this->getIsEnable() != 1) {
            return '';
        }

        if ((string) $this->getConfiguration('useCustomWidget', '1') === '0') {
            return parent::toHtml($_version);
        }

        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }

        $version = jeedom::versionAlias($_version);
        $templatePath = __DIR__ . '/../template/' . $version . '/geelyconnect.html';
        if (!file_exists($templatePath)) {
            return parent::toHtml($_version);
        }

        $getVal = function ($logicalId, $default = '') {
            $cmd = $this->getCmd('info', $logicalId);
            if (!is_object($cmd)) {
                return $default;
            }
            $val = $cmd->execCmd();
            return ($val === '' || $val === null) ? $default : $val;
        };

        $soc = max(0, min(100, (int) round((float) $getVal('soc', 0))));
        $range = max(0, (int) round((float) $getVal('range', 0)));
        $odometer = max(0, (int) round((float) $getVal('odometer', 0)));
        $rawVin = (string) $getVal('vin', $this->getConfiguration('vin', '--'));
        $modelName = trim((string) $this->getConfiguration('model', $getVal('model_info', 'Geely Vehicle')));
        $year = trim((string) $this->getConfiguration('year', ''));
        $powertrain = strtoupper(trim((string) $this->getConfiguration('powertrain', 'EV')));
        $vehicleBadge = trim($modelName . ($year !== '' ? ' ' . $year : '')) . ' • ' . $powertrain;

        $rawCharging = (string) $getVal('charging_status', 'idle');
        $chargingKw = (float) $getVal('charging_power_kw', 0);
        $rawLocation = (string) $getVal('location', '');
        $rawLastUpdate = (string) $getVal('last_update', '--');

        $isLocked = ((int) $getVal('lock_status', 0)) === 1;
        $isClimateOn = ((int) $getVal('climate_status', 0)) === 1;
        $isCharging = in_array(strtolower(trim($rawCharging)), array('charging', 'fast_charging', 'ac_charging', 'dc_charging', 'active', '1', 'true'), true);

        $alarmArmed = ((int) $getVal('alarm_armed', 0)) === 1;
        $alarmTriggered = ((int) $getVal('alarm_triggered', 0)) === 1;
        $guardOn = ((int) $getVal('child_pet_guard', 0)) === 1;
        $alertSummary = (string) $getVal('alert_message', 'OK');

        $socColor = '#ef4444';
        if ($soc > 50) {
            $socColor = '#10b981';
        } elseif ($soc >= 20) {
            $socColor = '#f59e0b';
        }

        $circumference = 276.46;
        $dashOffset = round($circumference * (1 - ($soc / 100)), 2);
        $safeMapUrl = self::buildSafeMapUrl($rawLocation);

        $openList = array();
        foreach (array('door_fl' => 'FL', 'door_fr' => 'FR', 'door_rl' => 'RL', 'door_rr' => 'RR', 'trunk_open' => 'Trunk', 'hood_open' => 'Hood', 'windows_open' => 'Win') as $k => $lbl) {
            if (((int) $getVal($k, 0)) === 1) {
                $openList[] = $lbl;
            }
        }

        $replace['#vin#'] = htmlspecialchars($rawVin, ENT_QUOTES, 'UTF-8');
        $replace['#vehicle_badge#'] = htmlspecialchars($vehicleBadge, ENT_QUOTES, 'UTF-8');
        $replace['#soc#'] = (string) $soc;
        $replace['#soc_color#'] = $socColor;
        $replace['#soc_dashoffset#'] = (string) $dashOffset;
        $replace['#range#'] = (string) $range;
        $replace['#odometer#'] = number_format($odometer, 0, '.', ' ');
        $replace['#charging_status#'] = htmlspecialchars(ucwords(str_replace('_', ' ', $rawCharging)) . ($isCharging && $chargingKw > 0 ? ' (' . $chargingKw . ' kW)' : ''), ENT_QUOTES, 'UTF-8');
        $replace['#charging_active#'] = $isCharging ? 'is-charging' : '';
        $replace['#lock_state_class#'] = $isLocked ? 'is-locked' : 'is-unlocked';
        $replace['#lock_label#'] = $isLocked ? __('Locked', __FILE__) : __('Unlocked', __FILE__);
        $replace['#climate_state_class#'] = $isClimateOn ? 'is-active' : 'is-inactive';
        $replace['#climate_label#'] = $isClimateOn ? __('Active', __FILE__) : __('Off', __FILE__);
        $replace['#climate_next_action#'] = $isClimateOn ? 'climate_off' : 'climate_on';
        $replace['#interior_temp#'] = (string) round((float) $getVal('interior_temp', 22), 1);
        $replace['#exterior_temp#'] = (string) round((float) $getVal('exterior_temp', 18), 1);
        $replace['#target_temp#'] = (string) round((float) $getVal('target_temp', 22), 0);
        $replace['#battery_12v#'] = number_format((float) $getVal('battery_12v', 12.6), 1);
        $replace['#tpms_fl#'] = number_format((float) $getVal('tpms_fl', 2.5), 1);
        $replace['#tpms_fr#'] = number_format((float) $getVal('tpms_fr', 2.5), 1);
        $replace['#tpms_rl#'] = number_format((float) $getVal('tpms_rl', 2.5), 1);
        $replace['#tpms_rr#'] = number_format((float) $getVal('tpms_rr', 2.5), 1);
        $replace['#openings_summary#'] = empty($openList) ? __('All Closed', __FILE__) : htmlspecialchars(implode(', ', $openList), ENT_QUOTES, 'UTF-8');
        $replace['#alarm_next_action#'] = $alarmArmed ? 'alarm_disarm' : 'alarm_arm';
        $replace['#alarm_active_class#'] = $alarmTriggered ? 'is-alarm-triggered' : ($alarmArmed ? 'is-guard-on' : '');
        $replace['#guard_next_action#'] = $guardOn ? 'child_pet_guard_off' : 'child_pet_guard_on';
        $replace['#guard_active_class#'] = $guardOn ? 'is-guard-on' : '';
        $replace['#alert_message#'] = htmlspecialchars($alertSummary, ENT_QUOTES, 'UTF-8');
        $replace['#has_alert#'] = ($alertSummary !== '' && $alertSummary !== 'OK') ? '1' : '0';
        $replace['#map_url#'] = htmlspecialchars($safeMapUrl, ENT_QUOTES, 'UTF-8');
        $replace['#has_map#'] = $safeMapUrl !== '' ? '1' : '0';
        $replace['#last_update#'] = htmlspecialchars($rawLastUpdate, ENT_QUOTES, 'UTF-8');
        $replace['#has_unlock_pin#'] = !empty(config::byKey('unlock_pin', 'geelyconnect', '')) ? '1' : '0';

        $html = template_replace($replace, getTemplate('core', $version, 'geelyconnect', 'geelyconnect'));
        return $this->postToHtml($_version, $html);
    }
}

/**
 * Command class for Geely Connect.
 */
class geelyconnectCmd extends cmd {

    /**
     * Execute an action command (cloud remote control or local Jeedom alarm/sentry/guard).
     *
     * @param array<string, mixed> $_options
     * @return void
     * @throws Exception
     */
    public function execute($_options = array()) {
        if ($this->getType() !== 'action') {
            return;
        }

        /** @var geelyconnect $eqLogic */
        $eqLogic = $this->getEqLogic();
        $logicalId = $this->getLogicalId();

        // Handle local Jeedom security & guard modes directly without cloud latency.
        if ($logicalId === 'alarm_arm') {
            $locCmd = $eqLogic->getCmd('info', 'location');
            $eqLogic->setConfiguration('armed_location', is_object($locCmd) ? (string) $locCmd->execCmd() : '');
            $eqLogic->save(true);
            $eqLogic->checkAndUpdateCmd('alarm_armed', 1);
            $eqLogic->checkAndUpdateCmd('alarm_triggered', 0);
            return;
        }
        if ($logicalId === 'alarm_disarm') {
            $eqLogic->checkAndUpdateCmd('alarm_armed', 0);
            $eqLogic->checkAndUpdateCmd('alarm_triggered', 0);
            return;
        }
        if ($logicalId === 'child_pet_guard_on') {
            $eqLogic->checkAndUpdateCmd('child_pet_guard', 1);
            return;
        }
        if ($logicalId === 'child_pet_guard_off') {
            $eqLogic->checkAndUpdateCmd('child_pet_guard', 0);
            $eqLogic->checkAndUpdateCmd('cabin_overheat_alert', 0);
            return;
        }

        $vin = trim((string) $eqLogic->getConfiguration('vin', ''));
        if ($vin === '') {
            throw new Exception(__('Vehicle VIN is not configured on this equipment.', __FILE__));
        }

        $allowedCloudActions = array(
            'refresh', 'lock', 'unlock', 'climate_on', 'climate_off', 'set_temp',
            'rapid_cool', 'rapid_warm', 'defrost_on', 'defrost_off', 'g_clean_on', 'g_clean_off',
            'seat_heat_driver', 'seat_vent_driver', 'find_car', 'unlock_trunk',
            'sunroof_open', 'sunroof_close', 'scheduled_charge_on', 'scheduled_charge_off'
        );
        if (!in_array($logicalId, $allowedCloudActions, true)) {
            throw new Exception(__('Unsupported command action.', __FILE__));
        }

        if (in_array($logicalId, array('unlock', 'unlock_trunk'), true)) {
            $configuredPin = (string) config::byKey('unlock_pin', 'geelyconnect', '');
            if ($configuredPin !== '') {
                $providedPin = isset($_options['pin']) ? (string) $_options['pin'] : (isset($_options['message']) ? (string) $_options['message'] : '');
                if ($providedPin === '' || !hash_equals($configuredPin, $providedPin)) {
                    throw new Exception(__('Action rejected: valid security PIN is required.', __FILE__));
                }
            }
        }

        $payload = array(
            'action' => 'command',
            'vin' => strtoupper($vin),
            'command' => $logicalId
        );
        if (isset($_options['slider'])) {
            $payload['value'] = $_options['slider'];
        }

        geelyconnect::sendToDaemon($payload);
    }
}
