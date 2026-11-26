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

if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}

$plugin = plugin::byId('geelyconnect');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Management}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i>
                <br>
                <span>{{Add Vehicle}}</span>
            </div>
            <div class="cursor pluginAction logoSecondary" id="bt_syncGeelyVehicles">
                <i class="fas fa-sync"></i>
                <br>
                <span>{{Sync Vehicles}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i>
                <br>
                <span>{{Configuration}}</span>
            </div>
        </div>

        <legend><i class="fas fa-car"></i> {{My Geely Vehicles}}</legend>
        <?php if (count($eqLogics) === 0) : ?>
            <br><br><br>
            <div class="center" style="font-size:1.2em;color:#767676;">
                {{No Geely Connect vehicle found. Click "Sync Vehicles" or "Add Vehicle" to get started.}}
            </div>
        <?php else : ?>
            <div class="input-group" style="margin-bottom:5px;">
                <input class="form-control roundedLeft" placeholder="{{Search}}" id="in_searchEqlogic" />
                <div class="input-group-btn">
                    <a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>
                    <a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>
                </div>
            </div>
            <div class="eqLogicThumbnailContainer">
                <?php
                foreach ($eqLogics as $eqLogic) {
                    $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                    $model = htmlspecialchars((string) $eqLogic->getConfiguration('model', ''), ENT_QUOTES, 'UTF-8');
                    $year = htmlspecialchars((string) $eqLogic->getConfiguration('year', ''), ENT_QUOTES, 'UTF-8');
                    $powertrain = htmlspecialchars(strtoupper((string) $eqLogic->getConfiguration('powertrain', 'EV')), ENT_QUOTES, 'UTF-8');
                    $subtitle = trim($model . ($year !== '' ? ' ' . $year : '')) . ' [' . $powertrain . ']';
                    echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
                    echo '<img src="' . $plugin->getPathImgIcon() . '" />';
                    echo '<br>';
                    echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
                    if ($model !== '') {
                        echo '<br><span style="font-size:11px;color:#38bdf8;">' . $subtitle . '</span>';
                    }
                    echo '</div>';
                }
                ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-xs-12 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex;">
            <span class="input-group-btn">
                <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Advanced configuration}}</span></a>
                <a class="btn btn-sm btn-default eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Duplicate}}</span></a>
                <a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Save}}</a>
                <a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Delete}}</a>
            </span>
        </div>

        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
            <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Equipment}}</a></li>
            <li role="presentation"><a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commands}}</a></li>
        </ul>

        <div class="tab-content">
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <form class="form-horizontal">
                    <fieldset>
                        <div class="col-lg-6">
                            <legend><i class="fas fa-wrench"></i> {{General}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Vehicle Name}}</label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;" />
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Vehicle name}}" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Parent Object}}</label>
                                <div class="col-sm-6">
                                    <select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
                                        <option value="">{{None}}</option>
                                        <?php
                                        $options = '';
                                        foreach ((jeeObject::buildTree(null, false)) as $object) {
                                            $options .= '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
                                        }
                                        echo $options;
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Category}}</label>
                                <div class="col-sm-6">
                                    <?php
                                    foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                                        echo '<label class="checkbox-inline">';
                                        echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" />' . $value['name'];
                                        echo '</label>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Options}}</label>
                                <div class="col-sm-6">
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked />{{Activate}}</label>
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked />{{Visible}}</label>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <legend><i class="fas fa-car-side"></i> {{Vehicle Identity &amp; Capabilities}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{VIN}}
                                    <sup><i class="fas fa-question-circle tooltips" title="{{17-character Vehicle Identification Number.}}"></i></sup>
                                </label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="vin" placeholder="L6T79T4S..." maxlength="17" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Model}}</label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="model" placeholder="Geely EX5 / Geometry C / Starray" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Year / Trim}}</label>
                                <div class="col-sm-3">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="year" placeholder="2025" maxlength="4" />
                                </div>
                                <div class="col-sm-3">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="trim" placeholder="Max / Pro" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Powertrain}}</label>
                                <div class="col-sm-6">
                                    <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="powertrain">
                                        <option value="EV">{{Electric (EV)}}</option>
                                        <option value="PHEV">{{Plug-in Hybrid (PHEV)}}</option>
                                        <option value="ICE">{{Combustion / Hybrid (ICE)}}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Custom Automotive Widget}}</label>
                                <div class="col-sm-6">
                                    <label class="checkbox-inline">
                                        <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="useCustomWidget" checked />{{Enable sleek automotive dashboard widget}}
                                    </label>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </form>
            </div>

            <div role="tabpanel" class="tab-pane" id="commandtab">
                <div class="table-responsive">
                    <table id="table_cmd" class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th class="hidden-xs" style="min-width:50px;width:70px;">{{ID}}</th>
                                <th style="min-width:200px;width:320px;">{{Name}}</th>
                                <th>{{Type}}</th>
                                <th style="min-width:200px;">{{Options}}</th>
                                <th>{{Status}}</th>
                                <th style="min-width:80px;width:200px;">{{Actions}}</th>
                            </tr>
                        </thead>
                        <tbody>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_file('desktop', 'geelyconnect', 'js', 'geelyconnect'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
