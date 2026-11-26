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

    if ($action === 'fetchServerCa') {
        $region = strtoupper(trim((string) init('region', config::byKey('region', 'geelyconnect', 'EU'))));
        $host = ($region === 'APAC') ? 'apis.xcloudsvc.com' : 'apis.ecloudeu.com';

        $ctx = stream_context_create(array(
            'ssl' => array(
                'capture_peer_cert' => true,
                'capture_peer_cert_chain' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
            )
        ));
        $client = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if ($client === false) {
            throw new Exception(__('Failed to connect to Geely server: ', __FILE__) . $errstr);
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $pemChain = '';
        $certs = array();
        if (!empty($params['options']['ssl']['peer_certificate_chain'])) {
            $certs = $params['options']['ssl']['peer_certificate_chain'];
        } elseif (!empty($params['options']['ssl']['peer_certificate'])) {
            $certs = array($params['options']['ssl']['peer_certificate']);
        }

        foreach ($certs as $certRes) {
            $pem = '';
            if (openssl_x509_export($certRes, $pem)) {
                $pemChain .= $pem;
            }
        }
        if ($pemChain === '') {
            throw new Exception(__('No TLS certificate returned by server.', __FILE__));
        }

        $leafCert = $certs[0];
        $parsed = openssl_x509_parse($leafCert);
        $fingerprint = strtoupper(openssl_x509_fingerprint($leafCert, 'sha256'));

        $certDir = geelyconnect::getCertDir();
        $caPath = $certDir . '/ca.crt';
        $oldUmask = umask(0077);
        file_put_contents($caPath, $pemChain);
        umask($oldUmask);
        @chmod($caPath, 0600);

        ajax::success(array(
            'host' => $host,
            'subject' => isset($parsed['subject']['CN']) ? $parsed['subject']['CN'] : $host,
            'issuer' => isset($parsed['issuer']['CN']) ? $parsed['issuer']['CN'] : 'Unknown',
            'sha256' => $fingerprint
        ));
    }

    if ($action === 'saveCertificates') {
        $certDir = geelyconnect::getCertDir();
        $caCrt = trim((string) init('ca_crt', ''));
        $clientCrt = trim((string) init('client_crt', ''));
        $clientKey = trim((string) init('client_key', ''));
        $accessToken = trim((string) init('access_token', ''));
        $refreshToken = trim((string) init('refresh_token', ''));

        $oldUmask = umask(0077);

        if ($caCrt !== '') {
            if (@openssl_x509_read($caCrt) === false) {
                umask($oldUmask);
                throw new Exception(__('Invalid CA Certificate PEM format.', __FILE__));
            }
            file_put_contents($certDir . '/ca.crt', $caCrt . "\n");
            @chmod($certDir . '/ca.crt', 0600);
        }

        if ($clientCrt !== '') {
            if (@openssl_x509_read($clientCrt) === false) {
                umask($oldUmask);
                throw new Exception(__('Invalid Client Certificate PEM format.', __FILE__));
            }
            file_put_contents($certDir . '/client.crt', $clientCrt . "\n");
            @chmod($certDir . '/client.crt', 0600);
        }

        if ($clientKey !== '') {
            if (@openssl_pkey_get_private($clientKey) === false) {
                umask($oldUmask);
                throw new Exception(__('Invalid Client Private Key PEM format.', __FILE__));
            }
            file_put_contents($certDir . '/client.key', $clientKey . "\n");
            @chmod($certDir . '/client.key', 0600);
        }

        if ($accessToken !== '' || $refreshToken !== '') {
            $sessionData = array(
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => time() + 3600
            );
            file_put_contents($certDir . '/session.json', json_encode($sessionData));
            @chmod($certDir . '/session.json', 0600);
        }

        umask($oldUmask);
        ajax::success();
    }

    if ($action === 'clearCertificates') {
        $certDir = geelyconnect::getCertDir();
        foreach (array('ca.crt', 'client.crt', 'client.key', 'session.json') as $file) {
            $path = $certDir . '/' . $file;
            if (file_exists($path)) {
                @unlink($path);
            }
        }
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
