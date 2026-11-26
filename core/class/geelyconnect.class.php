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
 * Manages vehicle equipment, daemon lifecycle, command provisioning,
 * and custom automotive dashboard/mobile widget rendering.
 */
class geelyconnect extends eqLogic {

    /**
     * Default local TCP socket port for PHP-to-daemon communication.
     */
    const DEFAULT_SOCKET_PORT = 55099;

    /**
     * Default polling interval in minutes.
     */
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
        $password = (string) config::byKey('password', 'geelyconnect', '');
        if ($username === '' || $password === '') {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Geely account username and password must be configured.', __FILE__);
            return $return;
        }

        return $return;
    }

    /**
     * Start the Geely Connect Python daemon.
     * Credentials and API key are passed exclusively via a 0600 bootstrap file
     * (never via CLI process arguments) and updated over the localhost TCP socket.
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

        $bootstrapPayload = array(
            'apikey' => jeedom::getApiKey('geelyconnect'),
            'callback' => network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/geelyconnect/core/php/jeeGeelyconnect.php',
            'username' => (string) config::byKey('username', 'geelyconnect', ''),
            'password' => (string) config::byKey('password', 'geelyconnect', ''),
            'region' => $region,
            'poll_interval' => $pollInterval,
            'socketport' => self::getSocketPort(),
            'vins' => self::getConfiguredVins()
        );

        // Write bootstrap config with strict 0600 permissions before launching the process.
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

        if (!file_exists($pidFile)) {
            return;
        }

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
    public static function sendToDaemon(array $payload) {
        $port = self::getSocketPort();
        $payload['apikey'] = jeedom::getApiKey('geelyconnect');

        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 5);
        if ($socket === false) {
            throw new Exception(__('Unable to connect to Geely Connect daemon: ', __FILE__) . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($socket, 15);
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
                'poll_interval' => max(1, (int) config::byKey('poll_interval', 'geelyconnect', self::DEFAULT_POLL_INTERVAL)),
                'vins' => self::getConfiguredVins()
            ));
        } catch (Exception $e) {
            log::add('geelyconnect', 'warning', __('Could not push live config to daemon: ', __FILE__) . $e->getMessage());
        }
    }

    /**
     * Automatically provision all required Info and Action commands after saving an equipment.
     *
     * @return void
     */
    public function postSave() {
        $infoCommands = array(
            'vin' => array('name' => __('VIN', __FILE__), 'subType' => 'string', 'unite' => '', 'visible' => 1, 'order' => 1),
            'soc' => array('name' => __('State of Charge', __FILE__), 'subType' => 'numeric', 'unite' => '%', 'visible' => 1, 'order' => 2, 'min' => 0, 'max' => 100),
            'range' => array('name' => __('Remaining Range', __FILE__), 'subType' => 'numeric', 'unite' => 'km', 'visible' => 1, 'order' => 3, 'min' => 0),
            'charging_status' => array('name' => __('Charging Status', __FILE__), 'subType' => 'string', 'unite' => '', 'visible' => 1, 'order' => 4),
            'lock_status' => array('name' => __('Lock Status', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 1, 'order' => 5),
            'climate_status' => array('name' => __('Climate Status', __FILE__), 'subType' => 'binary', 'unite' => '', 'visible' => 1, 'order' => 6),
            'location' => array('name' => __('GPS Location', __FILE__), 'subType' => 'string', 'unite' => '', 'visible' => 1, 'order' => 7),
            'odometer' => array('name' => __('Odometer', __FILE__), 'subType' => 'numeric', 'unite' => 'km', 'visible' => 1, 'order' => 8, 'min' => 0),
            'last_update' => array('name' => __('Last Sync', __FILE__), 'subType' => 'string', 'unite' => '', 'visible' => 1, 'order' => 9)
        );

        foreach ($infoCommands as $logicalId => $meta) {
            $this->ensureCommand($logicalId, 'info', $meta);
        }

        $actionCommands = array(
            'refresh' => array('name' => __('Refresh', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 10),
            'lock' => array('name' => __('Lock Doors', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 11),
            'unlock' => array('name' => __('Unlock Doors', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 12, 'confirm' => 1),
            'climate_on' => array('name' => __('Climate On', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 13),
            'climate_off' => array('name' => __('Climate Off', __FILE__), 'subType' => 'other', 'visible' => 1, 'order' => 14)
        );

        foreach ($actionCommands as $logicalId => $meta) {
            $this->ensureCommand($logicalId, 'action', $meta);
        }

        self::pushConfigToDaemon();
    }

    /**
     * Create or update a command on this eqLogic without duplicating logic.
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
     * Falls back cleanly to standard Jeedom rendering if disabled by the user.
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

        $getCmdId = function ($logicalId) {
            $cmd = $this->getCmd('action', $logicalId);
            return is_object($cmd) ? (string) $cmd->getId() : '';
        };

        $soc = max(0, min(100, (int) round((float) $getVal('soc', 0))));
        $range = max(0, (int) round((float) $getVal('range', 0)));
        $odometer = max(0, (int) round((float) $getVal('odometer', 0)));
        $rawVin = (string) $getVal('vin', $this->getConfiguration('vin', '--'));
        $rawCharging = (string) $getVal('charging_status', 'idle');
        $rawLocation = (string) $getVal('location', '');
        $rawLastUpdate = (string) $getVal('last_update', '--');

        $isLocked = ((int) $getVal('lock_status', 0)) === 1;
        $isClimateOn = ((int) $getVal('climate_status', 0)) === 1;
        $isCharging = in_array(strtolower(trim($rawCharging)), array('charging', 'fast_charging', 'ac_charging', 'dc_charging', 'active', '1', 'true'), true);

        // Determine SoC color tier: emerald (>50%), amber (20-50%), crimson (<20%).
        $socColor = '#ef4444';
        $socTier = 'critical';
        if ($soc > 50) {
            $socColor = '#10b981';
            $socTier = 'healthy';
        } elseif ($soc >= 20) {
            $socColor = '#f59e0b';
            $socTier = 'medium';
        }

        // SVG circle circumference for r=44 is ~276.46.
        $circumference = 276.46;
        $dashOffset = round($circumference * (1 - ($soc / 100)), 2);

        $safeMapUrl = self::buildSafeMapUrl($rawLocation);
        $hasPin = !empty(config::byKey('unlock_pin', 'geelyconnect', '')) ? '1' : '0';

        $replace['#vin#'] = htmlspecialchars($rawVin, ENT_QUOTES, 'UTF-8');
        $replace['#soc#'] = (string) $soc;
        $replace['#soc_color#'] = $socColor;
        $replace['#soc_tier#'] = $socTier;
        $replace['#soc_dashoffset#'] = (string) $dashOffset;
        $replace['#range#'] = (string) $range;
        $replace['#odometer#'] = number_format($odometer, 0, '.', ' ');
        $replace['#charging_status#'] = htmlspecialchars(ucwords(str_replace('_', ' ', $rawCharging)), ENT_QUOTES, 'UTF-8');
        $replace['#charging_active#'] = $isCharging ? 'is-charging' : '';
        $replace['#lock_state_class#'] = $isLocked ? 'is-locked' : 'is-unlocked';
        $replace['#lock_label#'] = $isLocked ? __('Locked', __FILE__) : __('Unlocked', __FILE__);
        $replace['#climate_state_class#'] = $isClimateOn ? 'is-active' : 'is-inactive';
        $replace['#climate_label#'] = $isClimateOn ? __('Active', __FILE__) : __('Off', __FILE__);
        $replace['#climate_next_action#'] = $isClimateOn ? 'climate_off' : 'climate_on';
        $replace['#location#'] = htmlspecialchars($rawLocation !== '' ? $rawLocation : __('Unavailable', __FILE__), ENT_QUOTES, 'UTF-8');
        $replace['#map_url#'] = htmlspecialchars($safeMapUrl, ENT_QUOTES, 'UTF-8');
        $replace['#has_map#'] = $safeMapUrl !== '' ? '1' : '0';
        $replace['#last_update#'] = htmlspecialchars($rawLastUpdate, ENT_QUOTES, 'UTF-8');
        $replace['#has_unlock_pin#'] = $hasPin;

        $replace['#cmd_refresh_id#'] = $getCmdId('refresh');
        $replace['#cmd_lock_id#'] = $getCmdId('lock');
        $replace['#cmd_unlock_id#'] = $getCmdId('unlock');
        $replace['#cmd_climate_on_id#'] = $getCmdId('climate_on');
        $replace['#cmd_climate_off_id#'] = $getCmdId('climate_off');

        $html = template_replace($replace, getTemplate('core', $version, 'geelyconnect', 'geelyconnect'));
        return $this->postToHtml($_version, $html);
    }
}

/**
 * Command class for Geely Connect.
 */
class geelyconnectCmd extends cmd {

    /**
     * Execute an action command on the vehicle via the authenticated Python daemon.
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
        $vin = trim((string) $eqLogic->getConfiguration('vin', ''));
        if ($vin === '') {
            throw new Exception(__('Vehicle VIN is not configured on this equipment.', __FILE__));
        }

        $logicalId = $this->getLogicalId();
        $allowedActions = array('refresh', 'lock', 'unlock', 'climate_on', 'climate_off');
        if (!in_array($logicalId, $allowedActions, true)) {
            throw new Exception(__('Unsupported command action.', __FILE__));
        }

        // Enforce optional PIN check for high-risk door unlock commands.
        if ($logicalId === 'unlock') {
            $configuredPin = (string) config::byKey('unlock_pin', 'geelyconnect', '');
            if ($configuredPin !== '') {
                $providedPin = '';
                if (isset($_options['pin'])) {
                    $providedPin = (string) $_options['pin'];
                } elseif (isset($_options['message'])) {
                    $providedPin = (string) $_options['message'];
                }
                if ($providedPin === '' || !hash_equals($configuredPin, $providedPin)) {
                    throw new Exception(__('Door unlock rejected: valid security PIN is required.', __FILE__));
                }
            }
        }

        geelyconnect::sendToDaemon(array(
            'action' => 'command',
            'vin' => strtoupper($vin),
            'command' => $logicalId
        ));
    }
}
