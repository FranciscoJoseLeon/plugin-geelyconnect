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

$vehicles = isset($payload['vehicles']) && is_array($payload['vehicles']) ? $payload['vehicles'] : array($payload);

foreach ($vehicles as $vehicleData) {
    if (!is_array($vehicleData) || empty($vehicleData['vin'])) {
        continue;
    }

    $vin = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $vehicleData['vin']));
    if ($vin === '') {
        continue;
    }

    $matchedEqLogic = null;
    foreach (eqLogic::byType('geelyconnect') as $eqLogic) {
        if (strtoupper(trim((string) $eqLogic->getConfiguration('vin', ''))) === $vin) {
            $matchedEqLogic = $eqLogic;
            break;
        }
    }

    // Auto-create equipment when a new vehicle is discovered on the Geely account.
    if (!is_object($matchedEqLogic)) {
        $modelName = isset($vehicleData['model']) ? trim((string) $vehicleData['model']) : 'Geely Vehicle';
        $matchedEqLogic = new geelyconnect();
        $matchedEqLogic->setEqType_name('geelyconnect');
        $matchedEqLogic->setName(substr($modelName, 0, 48) . ' (' . substr($vin, -4) . ')');
        $matchedEqLogic->setConfiguration('vin', $vin);
        $matchedEqLogic->setConfiguration('model', $modelName);
        $matchedEqLogic->setConfiguration('useCustomWidget', '1');
        $matchedEqLogic->setIsEnable(1);
        $matchedEqLogic->setIsVisible(1);
        $matchedEqLogic->save();
    }

    if ($matchedEqLogic->getIsEnable() != 1) {
        continue;
    }

    $matchedEqLogic->checkAndUpdateCmd('vin', $vin);

    if (isset($vehicleData['soc']) && is_numeric($vehicleData['soc'])) {
        $matchedEqLogic->checkAndUpdateCmd('soc', max(0, min(100, (float) $vehicleData['soc'])));
    }
    if (isset($vehicleData['range']) && is_numeric($vehicleData['range'])) {
        $matchedEqLogic->checkAndUpdateCmd('range', max(0, (float) $vehicleData['range']));
    }
    if (isset($vehicleData['charging_status'])) {
        $charging = preg_replace('/[^A-Za-z0-9_ -]/', '', (string) $vehicleData['charging_status']);
        $matchedEqLogic->checkAndUpdateCmd('charging_status', $charging);
    }
    if (isset($vehicleData['lock_status'])) {
        $matchedEqLogic->checkAndUpdateCmd('lock_status', ((int) $vehicleData['lock_status']) ? 1 : 0);
    }
    if (isset($vehicleData['climate_status'])) {
        $matchedEqLogic->checkAndUpdateCmd('climate_status', ((int) $vehicleData['climate_status']) ? 1 : 0);
    }
    if (isset($vehicleData['location'])) {
        $loc = trim((string) $vehicleData['location']);
        if (geelyconnect::buildSafeMapUrl($loc) !== '') {
            $matchedEqLogic->checkAndUpdateCmd('location', $loc);
        }
    }
    if (isset($vehicleData['odometer']) && is_numeric($vehicleData['odometer'])) {
        $matchedEqLogic->checkAndUpdateCmd('odometer', max(0, (float) $vehicleData['odometer']));
    }
    if (isset($vehicleData['last_update'])) {
        $ts = preg_replace('/[^0-9A-Za-z:T._ -]/', '', (string) $vehicleData['last_update']);
        $matchedEqLogic->checkAndUpdateCmd('last_update', $ts);
    } else {
        $matchedEqLogic->checkAndUpdateCmd('last_update', date('Y-m-d H:i:s'));
    }
}

echo json_encode(array('status' => 'ok'));
