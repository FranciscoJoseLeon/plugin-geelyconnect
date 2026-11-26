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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');

if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}

// Check server-side whether secrets and certificates are stored without ever exposing them to the DOM.
$certDir = geelyconnect::getCertDir();
$hasStoredPassword = !empty(config::byKey('password', 'geelyconnect', ''));
$hasUnlockPin = !empty(config::byKey('unlock_pin', 'geelyconnect', ''));
$hasCaCert = file_exists($certDir . '/ca.crt');
$hasMtlsCert = file_exists($certDir . '/client.crt') && file_exists($certDir . '/client.key');
$sessionFile = $certDir . '/session.json';
$sessionJson = file_exists($sessionFile) ? json_decode((string) file_get_contents($sessionFile), true) : array();
$hasSession = is_array($sessionJson) && (!empty($sessionJson['access_token']) || !empty($sessionJson['refresh_token']));
$sessionState = (string) config::byKey('session_state', 'geelyconnect', 'unknown');
?>

<form class="form-horizontal">
    <fieldset>
        <legend><i class="fas fa-user-shield"></i> {{Geely Account &amp; Authentication}}</legend>

        <div class="form-group">
            <label class="col-md-4 control-label">{{Email / Username}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Your Geely Connect / Global account email address.}}"></i></sup>
            </label>
            <div class="col-md-6">
                <input type="text" class="configKey form-control" id="geelyconnect_username_input" data-l1key="username" autocomplete="username" placeholder="user@example.com" />
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-4 control-label">{{Password (Optional)}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Optional for Geely Global accounts that use passwordless Email OTP. Stored encrypted on the server.}}"></i></sup>
            </label>
            <div class="col-md-6">
                <div class="input-group">
                    <input type="password"
                           id="geelyconnect_password_input"
                           class="form-control"
                           autocomplete="new-password"
                           value=""
                           data-has-password="<?php echo $hasStoredPassword ? '1' : '0'; ?>"
                           placeholder="<?php echo $hasStoredPassword ? '{{Password saved (enter a new one to replace)}}' : '{{Leave blank if using Email OTP login}}'; ?>" />
                    <span class="input-group-addon">
                        <?php if ($hasStoredPassword) : ?>
                            <span class="label label-success"><i class="fas fa-lock"></i> {{Configured}}</span>
                        <?php else : ?>
                            <span class="label label-warning"><i class="fas fa-unlock"></i> {{Not set}}</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-4 control-label">{{Region}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Select the Geely cloud region matching your vehicle account.}}"></i></sup>
            </label>
            <div class="col-md-4">
                <select class="configKey form-control" data-l1key="region">
                    <option value="EU">{{Europe (EU)}}</option>
                    <option value="GLOBAL">{{Global (GLOBAL)}}</option>
                    <option value="US">{{Americas (US)}}</option>
                    <option value="APAC">{{Asia-Pacific (APAC)}}</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-4 control-label">{{Country Code (ISO-2)}}
                <sup><i class="fas fa-question-circle tooltips" title="{{2-letter country code used by Geely Global (e.g., ES, GB, DE, IL).}}"></i></sup>
            </label>
            <div class="col-md-2">
                <input type="text" class="configKey form-control" id="geelyconnect_country_input" data-l1key="country_code" maxlength="2" placeholder="ES" style="text-transform:uppercase;" />
            </div>
        </div>

        <legend><i class="fas fa-certificate"></i> {{Geely Global OTP, TLS CA &amp; mTLS Certificate Manager}}</legend>

        <div class="form-group">
            <label class="col-md-4 control-label">{{Certificate &amp; Session Status}}</label>
            <div class="col-md-6">
                <?php if ($hasCaCert) : ?>
                    <span class="label label-success"><i class="fas fa-shield-alt"></i> {{CA Pinned}}</span>
                <?php else : ?>
                    <span class="label label-warning"><i class="fas fa-exclamation-triangle"></i> {{CA Not Pinned}}</span>
                <?php endif; ?>
                <?php if ($hasMtlsCert) : ?>
                    <span class="label label-success"><i class="fas fa-check-circle"></i> {{mTLS Provisioned}}</span>
                <?php else : ?>
                    <span class="label label-default"><i class="fas fa-info-circle"></i> {{mTLS Not Set}}</span>
                <?php endif; ?>
                <?php if ($hasSession) : ?>
                    <span class="label label-success"><i class="fas fa-key"></i> {{Session Active}}</span>
                <?php else : ?>
                    <span class="label label-default"><i class="fas fa-key"></i> {{No Session}}</span>
                <?php endif; ?>
                <?php if ($sessionState === 'invalid') : ?>
                    <span class="label label-danger"><i class="fas fa-user-slash"></i> {{Session Invalidated - Login Again}}</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-4 control-label">{{Step 0: Pin Geely Server CA}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Fetches and pins the Geely cloud server TLS certificate chain into data/certs/ca.crt (0600) so strict TLS verification succeeds.}}"></i></sup>
            </label>
            <div class="col-md-6">
                <button type="button" class="btn btn-primary" id="bt_geelyconnect_fetch_ca">
                    <i class="fas fa-download"></i> {{Fetch &amp; Pin Server CA Certificate}}
                </button>
                <span id="geelyconnect_ca_fingerprint" style="margin-left:8px;font-size:11px;color:#94a3b8;"></span>
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-4 control-label">{{Step 1: Start Login &amp; Send OTP}}
                <sup><i class="fas fa-question-circle tooltips" title="{{If Geely requires a human verification challenge, it is displayed here and must be solved by you. The plugin never solves challenges automatically.}}"></i></sup>
            </label>
            <div class="col-md-6">
                <button type="button" class="btn btn-info" id="bt_geelyconnect_request_otp">
                    <i class="fas fa-envelope"></i> {{Start Login &amp; Send 6-Digit OTP}}
                </button>
                <div id="geelyconnect_captcha_box" style="margin-top:8px;"></div>
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-4 control-label">{{Step 2: Verify OTP &amp; Provision mTLS}}</label>
            <div class="col-md-4">
                <div class="input-group">
                    <input type="text" id="geelyconnect_otp_input" class="form-control" maxlength="6" placeholder="123456" autocomplete="one-time-code" />
                    <span class="input-group-btn">
                        <button type="button" class="btn btn-success" id="bt_geelyconnect_verify_otp">
                            <i class="fas fa-key"></i> {{Verify OTP}}
                        </button>
                    </span>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-4 control-label">{{Manual Certificate / Token Import}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Optional: paste PEM certificates or session tokens directly. Saved with 0600 permissions and never echoed back.}}"></i></sup>
            </label>
            <div class="col-md-6">
                <a class="btn btn-default btn-sm" role="button" data-toggle="collapse" href="#geelyconnect_manual_certs" aria-expanded="false">
                    <i class="fas fa-file-signature"></i> {{Open Built-in Certificate &amp; Token Manager}}
                </a>
                <div class="collapse" id="geelyconnect_manual_certs" style="margin-top:10px;">
                    <div class="well" style="padding:12px;">
                        <label>{{Custom CA Certificate (PEM - ca.crt)}}</label>
                        <textarea id="geelyconnect_ca_crt" class="form-control" rows="3" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
                        <br>
                        <label>{{mTLS Client Certificate (PEM - client.crt)}}</label>
                        <textarea id="geelyconnect_client_crt" class="form-control" rows="3" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
                        <br>
                        <label>{{mTLS Client Private Key (PEM - client.key)}}</label>
                        <textarea id="geelyconnect_client_key" class="form-control" rows="3" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
                        <br>
                        <label>{{Access Token (optional)}}</label>
                        <input type="password" id="geelyconnect_access_token" class="form-control" autocomplete="off" placeholder="{{Paste access_token}}" />
                        <br>
                        <label>{{Refresh Token (optional)}}</label>
                        <input type="password" id="geelyconnect_refresh_token" class="form-control" autocomplete="off" placeholder="{{Paste refresh_token}}" />
                        <br>
                        <button type="button" class="btn btn-success btn-sm" id="bt_geelyconnect_save_certs">
                            <i class="fas fa-save"></i> {{Save Certificates &amp; Tokens}}
                        </button>
                        <button type="button" class="btn btn-info btn-sm" id="bt_geelyconnect_provision_mtls" style="margin-left:6px;">
                            <i class="fas fa-id-card"></i> {{Provision mTLS via Local CSR}}
                        </button>
                        <button type="button" class="btn btn-danger btn-sm" id="bt_geelyconnect_clear_certs" style="margin-left:6px;">
                            <i class="fas fa-trash"></i> {{Clear All Certificates}}
                        </button>
                        <hr style="margin:12px 0;">
                        <label>{{Endpoint Overrides (data/endpoints.json, optional)}}</label>
                        <textarea id="geelyconnect_endpoints_json" class="form-control" rows="4" placeholder='{"auth":{"send_code":"/auth/account/code/send"}}'></textarea>
                        <br>
                        <button type="button" class="btn btn-default btn-xs" id="bt_geelyconnect_load_endpoints">
                            <i class="fas fa-sync"></i> {{Load Current Overrides}}
                        </button>
                        <button type="button" class="btn btn-primary btn-xs" id="bt_geelyconnect_save_endpoints" style="margin-left:6px;">
                            <i class="fas fa-save"></i> {{Save Endpoint Overrides}}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <legend><i class="fas fa-cogs"></i> {{Polling &amp; Daemon Settings}}</legend>

        <div class="form-group">
            <label class="col-md-4 control-label">{{Polling Interval (minutes)}}
                <sup><i class="fas fa-question-circle tooltips" title="{{How often the daemon fetches vehicle telemetry (minimum 1 minute).}}"></i></sup>
            </label>
            <div class="col-md-4">
                <input type="number" class="configKey form-control" data-l1key="poll_interval" min="1" max="1440" placeholder="5" />
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-4 control-label">{{Local Socket Port}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Localhost-only TCP port (127.0.0.1) used for PHP-to-daemon communication. Default: 55099.}}"></i></sup>
            </label>
            <div class="col-md-4">
                <input type="number" class="configKey form-control" data-l1key="socketport" min="1024" max="65535" placeholder="55099" />
            </div>
        </div>

        <legend><i class="fas fa-shield-alt"></i> {{Security Safeguards}}</legend>

        <div class="form-group">
            <label class="col-md-4 control-label">{{Optional Unlock PIN}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Optional confirmation PIN required before executing door/trunk unlock commands. Leave blank to keep existing PIN.}}"></i></sup>
            </label>
            <div class="col-md-6">
                <div class="input-group">
                    <input type="password"
                           id="geelyconnect_pin_input"
                           class="form-control"
                           autocomplete="off"
                           value=""
                           placeholder="<?php echo $hasUnlockPin ? '{{PIN saved (enter new PIN to replace)}}' : '{{Optional 4-8 digit PIN}}'; ?>" />
                    <span class="input-group-btn">
                        <button type="button" class="btn btn-default" id="bt_geelyconnect_clear_pin" title="{{Clear saved unlock PIN}}">
                            <i class="fas fa-eraser"></i> {{Clear PIN}}
                        </button>
                    </span>
                </div>
            </div>
        </div>
    </fieldset>
</form>

<script>
    function geelyconnect_postSaveConfiguration() {
        var passwordVal = document.getElementById('geelyconnect_password_input').value;
        var pinVal = document.getElementById('geelyconnect_pin_input').value;

        if (passwordVal !== '' || pinVal !== '') {
            $.ajax({
                type: 'POST',
                url: 'plugins/geelyconnect/core/ajax/geelyconnect.ajax.php',
                data: {
                    action: 'saveCredentials',
                    password: passwordVal,
                    unlock_pin: pinVal
                },
                dataType: 'json',
                error: function (request, status, error) {
                    handleAjaxError(request, status, error);
                },
                success: function (data) {
                    if (data.state !== 'ok') {
                        $('#div_alert').showAlert({message: data.result, level: 'danger'});
                        return;
                    }
                    document.getElementById('geelyconnect_password_input').value = '';
                    document.getElementById('geelyconnect_pin_input').value = '';
                    $('#div_alert').showAlert({message: '{{Credentials saved securely.}}', level: 'success'});
                }
            });
        }
    }

    $('#bt_geelyconnect_fetch_ca').off('click').on('click', function () {
        $.ajax({
            type: 'POST',
            url: 'plugins/geelyconnect/core/ajax/geelyconnect.ajax.php',
            data: {
                action: 'fetchServerCa',
                region: $('select[data-l1key="region"]').val()
            },
            dataType: 'json',
            error: function (request, status, error) {
                handleAjaxError(request, status, error);
            },
            success: function (data) {
                if (data.state !== 'ok') {
                    $('#div_alert').showAlert({message: data.result, level: 'danger'});
                    return;
                }
                $('#geelyconnect_ca_fingerprint').text('Pinned ' + data.result.host + ' (SHA256: ' + data.result.sha256.substring(0, 16) + '...)');
                $('#div_alert').showAlert({message: '{{Server CA certificate pinned securely. Please restart the daemon.}}', level: 'success'});
            }
        });
    });

    $('#bt_geelyconnect_save_certs').off('click').on('click', function () {
        $.ajax({
            type: 'POST',
            url: 'plugins/geelyconnect/core/ajax/geelyconnect.ajax.php',
            data: {
                action: 'saveCertificates',
                ca_crt: $('#geelyconnect_ca_crt').val(),
                client_crt: $('#geelyconnect_client_crt').val(),
                client_key: $('#geelyconnect_client_key').val(),
                access_token: $('#geelyconnect_access_token').val(),
                refresh_token: $('#geelyconnect_refresh_token').val()
            },
            dataType: 'json',
            error: function (request, status, error) {
                handleAjaxError(request, status, error);
            },
            success: function (data) {
                if (data.state !== 'ok') {
                    $('#div_alert').showAlert({message: data.result, level: 'danger'});
                    return;
                }
                $('#geelyconnect_ca_crt, #geelyconnect_client_crt, #geelyconnect_client_key, #geelyconnect_access_token, #geelyconnect_refresh_token').val('');
                $('#div_alert').showAlert({message: '{{Certificates and tokens saved securely (0600). Restart the daemon to apply.}}', level: 'success'});
            }
        });
    });

    $('#bt_geelyconnect_clear_certs').off('click').on('click', function () {
        if (!window.confirm('{{Remove all stored CA, mTLS certificates, and session tokens?}}')) {
            return;
        }
        $.ajax({
            type: 'POST',
            url: 'plugins/geelyconnect/core/ajax/geelyconnect.ajax.php',
            data: {action: 'clearCertificates'},
            dataType: 'json',
            error: function (request, status, error) {
                handleAjaxError(request, status, error);
            },
            success: function (data) {
                if (data.state !== 'ok') {
                    $('#div_alert').showAlert({message: data.result, level: 'danger'});
                    return;
                }
                $('#div_alert').showAlert({message: '{{All certificates and session tokens cleared.}}', level: 'success'});
            }
        });
    });

    function sendOtpRequest(captchaResult) {
        $.ajax({
            type: 'POST',
            url: 'plugins/geelyconnect/core/ajax/geelyconnect.ajax.php',
            data: {
                action: 'requestOtp',
                email: $('#geelyconnect_username_input').val(),
                country_code: $('#geelyconnect_country_input').val(),
                captcha: JSON.stringify(captchaResult || {})
            },
            dataType: 'json',
            error: function (request, status, error) {
                handleAjaxError(request, status, error);
            },
            success: function (data) {
                if (data.state !== 'ok') {
                    $('#div_alert').showAlert({message: data.result, level: 'danger'});
                    return;
                }
                $('#geelyconnect_captcha_box').empty();
                $('#div_alert').showAlert({message: '{{OTP verification email sent. Enter the 6-digit code below.}}', level: 'success'});
            }
        });
    }

    function renderInteractiveCaptcha(captcha) {
        var $box = $('#geelyconnect_captcha_box').empty();
        if (!captcha || captcha.type === 'none') {
            sendOtpRequest({});
            return;
        }
        $box.html('<span class="label label-info">{{Complete the verification challenge below to send your OTP:}}</span><div id="geelyconnect_gt_container" style="margin-top:6px;"></div>');

        if (captcha.type === 'geetest_v4' && captcha.captcha_id) {
            $.getScript('https://static.geetest.com/v4/gt4.js', function () {
                if (typeof initGeetest4 !== 'function') {
                    $('#div_alert').showAlert({message: '{{Failed to load GeeTest v4 widget.}}', level: 'danger'});
                    return;
                }
                initGeetest4({captchaId: captcha.captcha_id, product: 'bind'}, function (captchaObj) {
                    captchaObj.onReady(function () {
                        captchaObj.showCaptcha();
                    }).onSuccess(function () {
                        sendOtpRequest(captchaObj.getValidate());
                    });
                });
            });
            return;
        }

        if (captcha.type === 'geetest_v3' && captcha.gt && captcha.challenge) {
            $.getScript('https://static.geetest.com/static/js/gt.js', function () {
                if (typeof initGeetest !== 'function') {
                    $('#div_alert').showAlert({message: '{{Failed to load GeeTest v3 widget.}}', level: 'danger'});
                    return;
                }
                initGeetest({
                    gt: captcha.gt,
                    challenge: captcha.challenge,
                    offline: !!captcha.offline,
                    new_captcha: !!captcha.new_captcha,
                    product: 'popup'
                }, function (captchaObj) {
                    captchaObj.appendTo('#geelyconnect_gt_container');
                    captchaObj.onSuccess(function () {
                        sendOtpRequest(captchaObj.getValidate());
                    });
                });
            });
        }
    }

    $('#bt_geelyconnect_request_otp').off('click').on('click', function () {
        $.ajax({
            type: 'POST',
            url: 'plugins/geelyconnect/core/ajax/geelyconnect.ajax.php',
            data: {
                action: 'authStart',
                email: $('#geelyconnect_username_input').val(),
                country_code: $('#geelyconnect_country_input').val()
            },
            dataType: 'json',
            error: function (request, status, error) {
                handleAjaxError(request, status, error);
            },
            success: function (data) {
                if (data.state !== 'ok') {
                    $('#div_alert').showAlert({message: data.result, level: 'danger'});
                    return;
                }
                renderInteractiveCaptcha((data.result && data.result.captcha) ? data.result.captcha : {type: 'none'});
            }
        });
    });

    $('#bt_geelyconnect_provision_mtls').off('click').on('click', function () {
        $.ajax({
            type: 'POST',
            url: 'plugins/geelyconnect/core/ajax/geelyconnect.ajax.php',
            data: {action: 'provisionMtls'},
            dataType: 'json',
            error: function (request, status, error) {
                handleAjaxError(request, status, error);
            },
            success: function (data) {
                if (data.state !== 'ok') {
                    $('#div_alert').showAlert({message: data.result, level: 'danger'});
                    return;
                }
                $('#div_alert').showAlert({message: '{{mTLS client certificate generated and provisioned!}}', level: 'success'});
            }
        });
    });

    $('#bt_geelyconnect_load_endpoints').off('click').on('click', function () {
        $.ajax({
            type: 'POST',
            url: 'plugins/geelyconnect/core/ajax/geelyconnect.ajax.php',
            data: {action: 'getEndpointOverrides'},
            dataType: 'json',
            success: function (data) {
                if (data.state === 'ok') {
                    $('#geelyconnect_endpoints_json').val(data.result.json || '');
                }
            }
        });
    });

    $('#bt_geelyconnect_save_endpoints').off('click').on('click', function () {
        $.ajax({
            type: 'POST',
            url: 'plugins/geelyconnect/core/ajax/geelyconnect.ajax.php',
            data: {
                action: 'saveEndpointOverrides',
                json: $('#geelyconnect_endpoints_json').val()
            },
            dataType: 'json',
            error: function (request, status, error) {
                handleAjaxError(request, status, error);
            },
            success: function (data) {
                if (data.state !== 'ok') {
                    $('#div_alert').showAlert({message: data.result, level: 'danger'});
                    return;
                }
                $('#div_alert').showAlert({message: '{{Endpoint overrides saved and applied.}}', level: 'success'});
            }
        });
    });

    $('#bt_geelyconnect_verify_otp').off('click').on('click', function () {
        $.ajax({
            type: 'POST',
            url: 'plugins/geelyconnect/core/ajax/geelyconnect.ajax.php',
            data: {
                action: 'verifyOtp',
                email: $('#geelyconnect_username_input').val(),
                country_code: $('#geelyconnect_country_input').val(),
                otp_code: $('#geelyconnect_otp_input').val()
            },
            dataType: 'json',
            error: function (request, status, error) {
                handleAjaxError(request, status, error);
            },
            success: function (data) {
                if (data.state !== 'ok') {
                    $('#div_alert').showAlert({message: data.result, level: 'danger'});
                    return;
                }
                $('#geelyconnect_otp_input').val('');
                $('#div_alert').showAlert({message: '{{OTP verified and mTLS certificate provisioned!}}', level: 'success'});
            }
        });
    });

    $('#bt_geelyconnect_clear_pin').off('click').on('click', function () {
        $.ajax({
            type: 'POST',
            url: 'plugins/geelyconnect/core/ajax/geelyconnect.ajax.php',
            data: {
                action: 'clearUnlockPin'
            },
            dataType: 'json',
            error: function (request, status, error) {
                handleAjaxError(request, status, error);
            },
            success: function (data) {
                if (data.state !== 'ok') {
                    $('#div_alert').showAlert({message: data.result, level: 'danger'});
                    return;
                }
                document.getElementById('geelyconnect_pin_input').value = '';
                document.getElementById('geelyconnect_pin_input').placeholder = '{{Optional 4-8 digit PIN}}';
                $('#div_alert').showAlert({message: '{{Unlock PIN cleared.}}', level: 'success'});
            }
        });
    });
</script>
