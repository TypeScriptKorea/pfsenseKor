<?php
/*
 * system_gateways.php
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2004-2018 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2010 Seth Mos <seth.mos@dds.nl>
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

##|+PRIV
##|*IDENT=page-system-gateways
##|*NAME=System: Gateways
##|*DESCR=Allow access to the 'System: Gateways' page.
##|*MATCH=system_gateways.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");

$a_gateways = return_gateways_array(true, false, true, true);

if (!is_array($config['gateways']['gateway_item'])) {
	$config['gateways']['gateway_item'] = array();
}

$a_gateway_item = &$config['gateways']['gateway_item'];

$pconfig = $_REQUEST;

if ($_POST['apply']) {

	$retval = 0;

	$retval |= system_routing_configure();
	$retval |= system_resolvconf_generate();
	$retval |= filter_configure();
	/* reconfigure our gateway monitor */
	setup_gateways_monitor();
	/* Dynamic DNS on gw groups may have changed */
	send_event("service reload dyndnsall");

	if ($retval == 0) {
		clear_subsystem_dirty('staticroutes');
	}
}


function can_delete_disable_gateway_item($id, $disable = false) {
	global $config, $input_errors, $a_gateways;

	if (!isset($a_gateways[$id])) {
		return false;
	}

	if (is_array($config['gateways']['gateway_group'])) {
		foreach ($config['gateways']['gateway_group'] as $group) {
			foreach ($group['item'] as $item) {
				$items = explode("|", $item);
				if ($items[0] == $a_gateways[$id]['name']) {
					if (!$disable) {
						$input_errors[] = sprintf(gettext('Gateway "%1$s" cannot be deleted because it is in use on Gateway Group "%2$s"'), $a_gateways[$id]['name'], $group['name']);
					} else {
						$input_errors[] = sprintf(gettext('Gateway "%1$s" cannot be disabled because it is in use on Gateway Group "%2$s"'), $a_gateways[$id]['name'], $group['name']);
					}
				}
			}
		}
	}

	if (is_array($config['staticroutes']['route'])) {
		foreach ($config['staticroutes']['route'] as $route) {
			if ($route['gateway'] == $a_gateways[$id]['name']) {
				if (!$disable) {
					// The user wants to delete this gateway, but there is a static route (enabled or disabled) that refers to the gateway.
					$input_errors[] = sprintf(gettext('Gateway "%1$s" cannot be deleted because it is in use on Static Route "%2$s"'), $a_gateways[$id]['name'], $route['network']);
				} else if (!isset($route['disabled'])) {
					// The user wants to disable this gateway.
					// But there is a static route that uses this gateway and is enabled (not disabled).
					$input_errors[] = sprintf(gettext('Gateway "%1$s" cannot be disabled because it is in use on Static Route "%2$s"'), $a_gateways[$id]['name'], $route['network']);
				}
			}
		}
	}

	if (isset($input_errors)) {
		return false;
	}

	return true;
}

function delete_gateway_item($id) {
	global $config, $a_gateways;

	if (!isset($a_gateways[$id])) {
		return;
	}

	/* If the removed gateway was the default route, remove the default route */
	if (!empty($a_gateways[$id]) && is_ipaddr($a_gateways[$id]['gateway']) &&
	    !isset($a_gateways[$id]['disabled']) &&
	    isset($a_gateways[$id]['defaultgw'])) {
		$inet = (!is_ipaddrv4($a_gateways[$id]['gateway']) ? '-inet6' : '-inet');
		mwexec("/sbin/route delete {$inet} default");
	}

	/* NOTE: Cleanup static routes for the interface route if any */
	if (!empty($a_gateways[$id]) && is_ipaddr($a_gateways[$id]['gateway']) &&
	    $gateway['gateway'] != $a_gateways[$id]['gateway'] &&
	    isset($a_gateways[$id]["nonlocalgateway"])) {
		$realif = get_real_interface($a_gateways[$id]['interface']);
		$inet = (!is_ipaddrv4($a_gateways[$id]['gateway']) ? "-inet6" : "-inet");
		$cmd = "/sbin/route delete $inet " . escapeshellarg($a_gateways[$id]['gateway']) . " -iface " . escapeshellarg($realif);
		mwexec($cmd);
	}
	/* NOTE: Cleanup static routes for the monitor ip if any */
	if (!empty($a_gateways[$id]['monitor']) &&
	    $a_gateways[$id]['monitor'] != "dynamic" &&
	    is_ipaddr($a_gateways[$id]['monitor']) &&
	    $a_gateways[$id]['gateway'] != $a_gateways[$id]['monitor']) {
		if (is_ipaddrv4($a_gateways[$id]['monitor'])) {
			mwexec("/sbin/route delete " . escapeshellarg($a_gateways[$id]['monitor']));
		} else {
			mwexec("/sbin/route delete -inet6 " . escapeshellarg($a_gateways[$id]['monitor']));
		}
	}

	if ($config['interfaces'][$a_gateways[$id]['friendlyiface']]['gateway'] == $a_gateways[$id]['name']) {
		unset($config['interfaces'][$a_gateways[$id]['friendlyiface']]['gateway']);
	}
	unset($config['gateways']['gateway_item'][$a_gateways[$id]['attribute']]);
}

unset($input_errors);
if ($_REQUEST['act'] == "del") {
	if (can_delete_disable_gateway_item($_REQUEST['id'])) {
		$realid = $a_gateways[$_REQUEST['id']]['attribute'];
		delete_gateway_item($_REQUEST['id']);
		write_config("Gateways: removed gateway {$realid}");
		mark_subsystem_dirty('staticroutes');
		header("Location: system_gateways.php");
		exit;
	}
}

if (isset($_REQUEST['del_x'])) {
	/* delete selected items */
	if (is_array($_REQUEST['rule']) && count($_REQUEST['rule'])) {
		foreach ($_REQUEST['rule'] as $rulei) {
			if (!can_delete_disable_gateway_item($rulei)) {
				break;
			}
		}

		if (!isset($input_errors)) {
			$items_deleted = "";
			foreach ($_REQUEST['rule'] as $rulei) {
				delete_gateway_item($rulei);
				$items_deleted .= "{$rulei} ";
			}
			if (!empty($items_deleted)) {
				write_config(sprintf(gettext("Gateways: removed gateways %s", $items_deleted)));
				mark_subsystem_dirty('staticroutes');
			}
			header("Location: system_gateways.php");
			exit;
		}
	}

} else if ($_REQUEST['act'] == "toggle" && $a_gateways[$_REQUEST['id']]) {
	$realid = $a_gateways[$_REQUEST['id']]['attribute'];
	$disable_gw = !isset($a_gateway_item[$realid]['disabled']);
	if ($disable_gw) {
		// The user wants to disable the gateway, so check if that is OK.
		$ok_to_toggle = can_delete_disable_gateway_item($_REQUEST['id'], $disable_gw);
	} else {
		// The user wants to enable the gateway. That is always OK.
		$ok_to_toggle = true;
	}
	if ($ok_to_toggle) {
		if ($disable_gw) {
			$a_gateway_item[$realid]['disabled'] = true;
			/* If the disabled gateway was the default route, remove the default route */
			if (!empty($a_gateway_item[$realid]) && is_ipaddr($a_gateway_item[$realid]['gateway']) &&
			    isset($a_gateway_item[$realid]['defaultgw'])) {
				$inet = (!is_ipaddrv4($a_gateway_item[$realid]['gateway']) ? '-inet6' : '-inet');
				mwexec("/sbin/route delete {$inet} default");
			}
		} else {
			unset($a_gateway_item[$realid]['disabled']);
		}

		if (write_config("Gateways: enable/disable")) {
			mark_subsystem_dirty('staticroutes');
		}

		header("Location: system_gateways.php");
		exit;
	}
}

$pgtitle = array(gettext("System"), gettext("Routing"), gettext("Gateways"));
$pglinks = array("", "@self", "@self");
$shortcut_section = "gateways";

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('staticroutes')) {
	print_apply_box(gettext("The gateway configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}

$tab_array = array();
$tab_array[0] = array(gettext("Gateways"), true, "system_gateways.php");
$tab_array[1] = array(gettext("Static Routes"), false, "system_routes.php");
$tab_array[2] = array(gettext("Gateway Groups"), false, "system_gateway_groups.php");
display_top_tabs($tab_array);

?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Gateways')?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-condensed table-rowdblclickedit">
				<thead>
					<tr>
						<th></th>
						<th><?=gettext("Name")?></th>
						<th><?=gettext("Interface")?></th>
						<th><?=gettext("Gateway")?></th>
						<th><?=gettext("Monitor IP")?></th>
						<th><?=gettext("Description")?></th>
						<th><?=gettext("Actions")?></th>
					</tr>
				</thead>
				<tbody>
<?php
foreach ($a_gateways as $i => $gateway):
	if (isset($gateway['inactive'])) {
		$icon = 'fa-times-circle-o';
	} elseif (isset($gateway['disabled'])) {
		$icon = 'fa-ban';
	} else {
		$icon = 'fa-check-circle-o';
	}

	if (isset($gateway['inactive'])) {
		$title = gettext("This gateway is inactive because interface is missing");
	} else {
		$title = '';
	}
?>
				<tr<?=($icon != 'fa-check-circle-o')? ' class="disabled"' : ''?>>
					<td title="<?=$title?>"><i class="fa <?=$icon?>"></i></td>
					<td>
						<?=htmlspecialchars($gateway['name'])?>
<?php
			if (isset($gateway['defaultgw'])) {
				echo " <strong>(default)</strong>";
			}
?>
						</td>
						<td>
							<?=htmlspecialchars(convert_friendly_interface_to_friendly_descr($gateway['friendlyiface']))?>
						</td>
						<td>
							<?=htmlspecialchars($gateway['gateway'])?>
						</td>
						<td>
							<?=htmlspecialchars($gateway['monitor'])?>
						</td>
						<td>
							<?=htmlspecialchars($gateway['descr'])?>
						</td>
						<td>
							<a href="system_gateways_edit.php?id=<?=$i?>" class="fa fa-pencil" title="<?=gettext('Edit gateway');?>"></a>
							<a href="system_gateways_edit.php?dup=<?=$i?>" class="fa fa-clone" title="<?=gettext('Copy gateway')?>"></a>

<?php if (is_numeric($gateway['attribute'])): ?>
	<?php if (isset($gateway['disabled'])) {
	?>
							<a href="?act=toggle&amp;id=<?=$i?>" class="fa fa-check-square-o" title="<?=gettext('Enable gateway')?>" usepost></a>
	<?php } else {
	?>
							<a href="?act=toggle&amp;id=<?=$i?>" class="fa fa-ban" title="<?=gettext('Disable gateway')?>" usepost></a>
	<?php }
	?>
							<a href="system_gateways.php?act=del&amp;id=<?=$i?>" class="fa fa-trash" title="<?=gettext('Delete gateway')?>" usepost></a>

<?php endif; ?>
						</td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<nav class="action-buttons">
	<a href="system_gateways_edit.php" role="button" class="btn btn-success">
		<i class="fa fa-plus icon-embed-btn"></i>
		<?=gettext("Add");?>
	</a>
</nav>
<?php

include("foot.inc");
