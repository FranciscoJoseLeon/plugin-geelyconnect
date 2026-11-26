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

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

// Constant-time API key verification to prevent timing attacks and unauthorized telemetry injection.
$expectedKey = jeedom::getApiKey('geelyconnect');
$providedKey = (string) init('apikey', '');
if ($expectedKey === '' || $providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'Unauthorized'));
    die();
}

$rawBody = file_get_contents('php://input');
$payload = json_decode((string) $rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'message' => 'Invalid JSON payload'));
    die();
}

// Session lifecycle events emitted by the daemon (single-session invalidation, re-login success).
if (isset($payload['event'])) {
    $event = (string) $payload['event'];
    if ($event === 'session_invalidated') {
        config::save('session_state', 'invalid', 'geelyconnect');
        $reason = substr(preg_replace('/[^A-Za-z0-9 .,:()\/_-]/', '', (string) ($payload['message'] ?? '')), 0, 300);
        message::add('geelyconnect', __('Geely session invalidated (expired or opened on another device). Open the plugin configuration and complete the email OTP login again.', __FILE__) . ($reason !== '' ? ' [' . $reason . ']' : ''), '', 'geelyconnect_session');
        log::add('geelyconnect', 'warning', 'Session invalidated: ' . $reason);
    } elseif ($event === 'session_ok') {
        config::save('session_state', 'active', 'geelyconnect');
    }
    echo json_encode(array('status' => 'ok'));
    die();
}

$vehicles = isset($payload['vehicles']) && is_array($payload['vehicles']) ? $payload['vehicles'] : array($payload);

foreach ($vehicles as $vehicleData) {
    if (!is_array($vehicleData) || empty($vehicleData['vin'])) {
        continue;
    }

    $vin = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $vehicleData['vin']));
    if ($vin === '') {
        continue;
    }

    $modelName = isset($vehicleData['model']) ? preg_replace('/[^A-Za-z0-9 _.-]/', '', trim((string) $vehicleData['model'])) : 'Geely Vehicle';
    $year = isset($vehicleData['year']) ? preg_replace('/[^0-9]/', '', (string) $vehicleData['year']) : '';
    $trim = isset($vehicleData['trim']) ? preg_replace('/[^A-Za-z0-9 _.-]/', '', trim((string) $vehicleData['trim'])) : '';
    $powertrain = isset($vehicleData['powertrain']) ? strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $vehicleData['powertrain'])) : 'EV';

    $matchedEqLogic = null;
    foreach (eqLogic::byType('geelyconnect') as $eqLogic) {
        if (strtoupper(trim((string) $eqLogic->getConfiguration('vin', ''))) === $vin) {
            $matchedEqLogic = $eqLogic;
            break;
        }
    }

    if (!is_object($matchedEqLogic)) {
        $matchedEqLogic = new geelyconnect();
        $matchedEqLogic->setEqType_name('geelyconnect');
        $matchedEqLogic->setName(substr($modelName, 0, 48) . ' (' . substr($vin, -4) . ')');
        $matchedEqLogic->setConfiguration('vin', $vin);
        $matchedEqLogic->setConfiguration('model', $modelName);
        $matchedEqLogic->setConfiguration('year', $year);
        $matchedEqLogic->setConfiguration('trim', $trim);
        $matchedEqLogic->setConfiguration('powertrain', $powertrain);
        $matchedEqLogic->setConfiguration('useCustomWidget', '1');
        $matchedEqLogic->setIsEnable(1);
        $matchedEqLogic->setIsVisible(1);
        $matchedEqLogic->save();
    } else {
        $changed = false;
        foreach (array('model' => $modelName, 'year' => $year, 'trim' => $trim, 'powertrain' => $powertrain) as $cfgKey => $cfgVal) {
            if ($cfgVal !== '' && $matchedEqLogic->getConfiguration($cfgKey, '') !== $cfgVal) {
                $matchedEqLogic->setConfiguration($cfgKey, $cfgVal);
                $changed = true;
            }
        }
        if ($changed) {
            $matchedEqLogic->save();
        }
    }

    if ($matchedEqLogic->getIsEnable() != 1) {
        continue;
    }

    $matchedEqLogic->checkAndUpdateCmd('vin', $vin);
    $matchedEqLogic->checkAndUpdateCmd('model_info', trim($modelName . ($year !== '' ? ' ' . $year : '') . ($trim !== '' ? ' ' . $trim : '')));

    $numericFields = array(
        'soc' => array(0, 100),
        'range' => array(0, 2000),
        'range_full' => array(0, 2000),
        'charging_power_kw' => array(0, 500),
        'time_to_full_min' => array(0, 3000),
        'target_temp' => array(16, 30),
        'interior_temp' => array(-50, 90),
        'exterior_temp' => array(-50, 70),
        'battery_12v' => array(0, 20),
        'tpms_fl' => array(0, 6),
        'tpms_fr' => array(0, 6),
        'tpms_rl' => array(0, 6),
        'tpms_rr' => array(0, 6),
        'service_days' => array(0, 2000),
        'service_km' => array(0, 200000),
        'odometer' => array(0, 2000000),
        'fuel_level' => array(0, 100),
        'fuel_range' => array(0, 2000)
    );
    foreach ($numericFields as $field => $bounds) {
        if (isset($vehicleData[$field]) && is_numeric($vehicleData[$field])) {
            $val = max($bounds[0], min($bounds[1], (float) $vehicleData[$field]));
            $matchedEqLogic->checkAndUpdateCmd($field, $val);
        }
    }

    $binaryFields = array(
        'plug_status', 'scheduled_charge', 'lock_status', 'climate_status',
        'defrost_status', 'g_clean_status', 'door_fl', 'door_fr', 'door_rl', 'door_rr',
        'trunk_open', 'hood_open', 'sunroof_open', 'windows_open', 'driver_seatbelt'
    );
    foreach ($binaryFields as $field) {
        if (isset($vehicleData[$field])) {
            $matchedEqLogic->checkAndUpdateCmd($field, ((int) $vehicleData[$field]) ? 1 : 0);
        }
    }

    if (isset($vehicleData['charging_status'])) {
        $charging = preg_replace('/[^A-Za-z0-9_ -]/', '', (string) $vehicleData['charging_status']);
        $matchedEqLogic->checkAndUpdateCmd('charging_status', $charging);
    }
    if (isset($vehicleData['location'])) {
        $loc = trim((string) $vehicleData['location']);
        if (geelyconnect::buildSafeMapUrl($loc) !== '') {
            $matchedEqLogic->checkAndUpdateCmd('location', $loc);
        }
    }
    if (isset($vehicleData['last_update'])) {
        $ts = preg_replace('/[^0-9A-Za-z:T._ -]/', '', (string) $vehicleData['last_update']);
        $matchedEqLogic->checkAndUpdateCmd('last_update', $ts);
    } else {
        $matchedEqLogic->checkAndUpdateCmd('last_update', date('Y-m-d H:i:s'));
    }

    $matchedEqLogic->evaluateAlertsAndGuards($vehicleData);
}

echo json_encode(array('status' => 'ok'));
