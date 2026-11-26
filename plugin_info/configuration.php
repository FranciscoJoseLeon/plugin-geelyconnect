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

// Check server-side whether a password is already stored without ever exposing it to the DOM.
$hasStoredPassword = !empty(config::byKey('password', 'geelyconnect', ''));
$hasUnlockPin = !empty(config::byKey('unlock_pin', 'geelyconnect', ''));
?>

<form class="form-horizontal">
    <fieldset>
        <legend><i class="fas fa-user-shield"></i> {{Geely Account &amp; Authentication}}</legend>

        <div class="form-group">
            <label class="col-md-4 control-label">{{Email / Username}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Your Geely Connect account email address or username.}}"></i></sup>
            </label>
            <div class="col-md-6">
                <input type="text" class="configKey form-control" data-l1key="username" autocomplete="username" placeholder="user@example.com" />
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-4 control-label">{{Password}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Stored encrypted on the server. Leave blank to keep the currently saved password.}}"></i></sup>
            </label>
            <div class="col-md-6">
                <div class="input-group">
                    <input type="password"
                           id="geelyconnect_password_input"
                           class="form-control"
                           autocomplete="new-password"
                           value=""
                           data-has-password="<?php echo $hasStoredPassword ? '1' : '0'; ?>"
                           placeholder="<?php echo $hasStoredPassword ? '{{Password saved (enter a new one to replace)}}' : '{{Enter your Geely account password}}'; ?>" />
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
                    <option value="APAC">{{Asia-Pacific (APAC)}}</option>
                </select>
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
                <sup><i class="fas fa-question-circle tooltips" title="{{Optional confirmation PIN required before executing door unlock commands. Leave blank to keep existing PIN.}}"></i></sup>
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
    /**
     * Save sensitive credentials (password and optional unlock PIN) through a dedicated
     * authenticated AJAX endpoint so raw secrets are never stored in .configKey DOM fields
     * or returned by core config::byKey AJAX responses.
     */
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
                    $('#div_alert').showAlert({message: '{{Credentials saved securely. Restarting daemon if running.}}', level: 'success'});
                }
            });
        }
    }

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
