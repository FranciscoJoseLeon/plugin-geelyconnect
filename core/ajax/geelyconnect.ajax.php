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

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    ajax::init();

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Unauthorized access', __FILE__));
    }

    $action = init('action');

    if ($action === 'saveCredentials') {
        $password = init('password', '');
        $unlockPin = init('unlock_pin', '');

        if ($password !== '') {
            config::save('password', $password, 'geelyconnect');
        }
        if ($unlockPin !== '') {
            if (!preg_match('/^[0-9]{4,8}$/', $unlockPin)) {
                throw new Exception(__('Unlock PIN must be 4 to 8 numeric digits.', __FILE__));
            }
            config::save('unlock_pin', $unlockPin, 'geelyconnect');
        }

        geelyconnect::pushConfigToDaemon();
        ajax::success();
    }

    if ($action === 'clearUnlockPin') {
        config::remove('unlock_pin', 'geelyconnect');
        ajax::success();
    }

    if ($action === 'requestOtp') {
        $email = trim((string) init('email', config::byKey('username', 'geelyconnect', '')));
        $countryCode = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) init('country_code', config::byKey('country_code', 'geelyconnect', 'ES'))));
        $result = geelyconnect::sendToDaemon(array(
            'action' => 'request_otp',
            'email' => $email,
            'country_code' => $countryCode ?: 'ES'
        ));
        ajax::success($result);
    }

    if ($action === 'verifyOtp') {
        $email = trim((string) init('email', config::byKey('username', 'geelyconnect', '')));
        $otpCode = preg_replace('/[^0-9]/', '', (string) init('otp_code', ''));
        $countryCode = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) init('country_code', config::byKey('country_code', 'geelyconnect', 'ES'))));
        if (strlen($otpCode) !== 6) {
            throw new Exception(__('OTP code must be 6 digits.', __FILE__));
        }
        $result = geelyconnect::sendToDaemon(array(
            'action' => 'verify_otp',
            'email' => $email,
            'otp_code' => $otpCode,
            'country_code' => $countryCode ?: 'ES'
        ));
        ajax::success($result);
    }

    if ($action === 'syncVehicles') {
        $result = geelyconnect::sendToDaemon(array(
            'action' => 'sync'
        ));
        ajax::success($result);
    }

    if ($action === 'executeWidgetAction') {
        $eqLogicId = (int) init('eqLogic_id');
        $commandName = init('command');
        $pin = init('pin', '');
        $value = init('value', null);

        $eqLogic = geelyconnect::byId($eqLogicId);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== 'geelyconnect') {
            throw new Exception(__('Equipment not found.', __FILE__));
        }

        $cmd = $eqLogic->getCmd('action', $commandName);
        if (!is_object($cmd)) {
            throw new Exception(__('Command not found.', __FILE__));
        }

        $options = array();
        if ($pin !== '') {
            $options['pin'] = $pin;
        }
        if ($value !== null && $value !== '') {
            $options['slider'] = $value;
        }
        $cmd->execCmd($options);
        ajax::success(array('executed' => $commandName));
    }

    throw new Exception(__('No matching AJAX action found: ', __FILE__) . $action);
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
