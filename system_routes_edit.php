<?php
/*
 * system_routes_edit.php
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2004-2018 Rubicon Communications, LLC (Netgate)
 * All rights reserved.
 *
 * originally based on m0n0wall (http://m0n0.ch/wall)
 * Copyright (c) 2003-2004 Manuel Kasper <mk@neon1.net>.
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

/*
2018.03.07
한글화 번역 시작
*/

##|+PRIV
##|*IDENT=page-system-staticroutes-editroute
##|*NAME=System: Static Routes: Edit route
##|*DESCR=Allow access to the 'System: Static Routes: Edit route' page.
##|*MATCH=system_routes_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("filter.inc");
require_once("util.inc");
require_once("gwlb.inc");

$referer = (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/system_routes.php');

if (!is_array($config['staticroutes']['route'])) {
	$config['staticroutes']['route'] = array();
}

$a_routes = &$config['staticroutes']['route'];
$a_gateways = return_gateways_array(true, true);

$id = $_REQUEST['id'];

if (isset($_REQUEST['dup']) && is_numericint($_REQUEST['dup'])) {
	$id = $_REQUEST['dup'];
}

if (isset($id) && $a_routes[$id]) {
	list($pconfig['network'], $pconfig['network_subnet']) =
		explode('/', $a_routes[$id]['network']);
	$pconfig['gateway'] = $a_routes[$id]['gateway'];
	$pconfig['descr'] = $a_routes[$id]['descr'];
	$pconfig['disabled'] = isset($a_routes[$id]['disabled']);
}

if (isset($_REQUEST['dup']) && is_numericint($_REQUEST['dup'])) {
	unset($id);
}

if ($_POST['save']) {

	global $aliastable;

	unset($input_errors);
	$pconfig = $_POST;

	/* input validation */
	$reqdfields = explode(" ", "network network_subnet gateway");
	$reqdfieldsn = explode(",",
		gettext("수신지 네트워크") . "," .
		gettext("수신지 네트워크 비트 카운트") . "," .
		gettext("게이트웨이"));

	do_input_validation($_POST, $reqdfields, $reqdfieldsn, $input_errors);

	if (($_POST['network'] && !is_ipaddr($_POST['network']) && !is_alias($_POST['network']))) {
		$input_errors[] = gettext("유효한 IPv4 또는 IPv6 대상 네트워크를 지정해야합니다.");
	}
	if (($_POST['network_subnet'] && !is_numeric($_POST['network_subnet']))) {
		$input_errors[] = gettext("유효한 대상 네트워크 비트 수를 지정해야합니다.");
	}
	if (($_POST['gateway']) && is_ipaddr($_POST['network'])) {
		if (!isset($a_gateways[$_POST['gateway']])) {
			$input_errors[] = gettext("유효한 게이트웨이를 지정해야합니다.");
		} else if (isset($a_gateways[$_POST['gateway']]['disabled']) && !$_POST['disabled']) {
			$input_errors[] = gettext("게이트웨이는 사용할 수 없지만 경로는 그렇지 않습니다. 비활성화 된 게이트웨이를 선택하려면 경로를 비활성화해야합니다.");
		} else {
			// Note that the 3rd parameter "disabled" must be passed as explicitly true or false.
			if (!validate_address_family($_POST['network'], $_POST['gateway'], $_POST['disabled'] ? true : false)) {
				$input_errors[] = sprintf(gettext('"%1$s"게이트웨이는 "%2$s"네트워크와 다른 주소 그룹입니다.'), $a_gateways[$_POST['gateway']]['gateway'], $_POST['network']);
			}
		}
	}

	/* check for overlaps */
	$current_targets = get_staticroutes(true);
	$new_targets = array();
	if (is_ipaddrv6($_POST['network'])) {
		$osn = gen_subnetv6($_POST['network'], $_POST['network_subnet']) . "/" . $_POST['network_subnet'];
		$new_targets[] = $osn;
	}
	if (is_ipaddrv4($_POST['network'])) {
		if ($_POST['network_subnet'] > 32) {
			$input_errors[] = gettext("A IPv4 subnet can not be over 32 bits.");
		} else {
			$osn = gen_subnet($_POST['network'], $_POST['network_subnet']) . "/" . $_POST['network_subnet'];
			$new_targets[] = $osn;
		}
	} elseif (is_alias($_POST['network'])) {
		$osn = $_POST['network'];
		foreach (preg_split('/\s+/', $aliastable[$osn]) as $tgt) {
			if (is_ipaddrv4($tgt)) {
				$tgt .= "/32";
			}
			if (is_ipaddrv6($tgt)) {
				$tgt .= "/128";
			}
			if (!is_subnet($tgt)) {
				continue;
			}
			if (!is_subnetv6($tgt)) {
				continue;
			}
			$new_targets[] = $tgt;
		}
	}
	if (!isset($id)) {
		$id = count($a_routes);
	}
	$oroute = $a_routes[$id];
	$old_targets = array();
	if (!empty($oroute)) {
		if (is_alias($oroute['network'])) {
			foreach (filter_expand_alias_array($oroute['network']) as $tgt) {
				if (is_ipaddrv4($tgt)) {
					$tgt .= "/32";
				} else if (is_ipaddrv6($tgt)) {
					$tgt .= "/128";
				}
				if (!is_subnet($tgt)) {
					continue;
				}
				$old_targets[] = $tgt;
			}
		} else {
			$old_targets[] = $oroute['network'];
		}
	}

	$overlaps = array_intersect($current_targets, $new_targets);
	$overlaps = array_diff($overlaps, $old_targets);
	if (count($overlaps)) {
		$input_errors[] = gettext("이러한 대상 네트워크에 대한 경로가 이미 있습니다.") . ": " . implode(", ", $overlaps);
	}

	if (is_array($config['interfaces'])) {
		foreach ($config['interfaces'] as $if) {
			if (is_ipaddrv4($_POST['network']) &&
			    isset($if['ipaddr']) && isset($if['subnet']) &&
			    is_ipaddrv4($if['ipaddr']) && is_numeric($if['subnet']) &&
			    ($_POST['network_subnet'] == $if['subnet']) &&
			    (gen_subnet($_POST['network'], $_POST['network_subnet']) == gen_subnet($if['ipaddr'], $if['subnet']))) {
				$input_errors[] = sprintf(gettext("이 네트워크는 % s 인터페이스에 구성된 주소와 충돌합니다."), $if['descr']);
			} else if (is_ipaddrv6($_POST['network']) &&
			    isset($if['ipaddrv6']) && isset($if['subnetv6']) &&
			    is_ipaddrv6($if['ipaddrv6']) && is_numeric($if['subnetv6']) &&
			    ($_POST['network_subnet'] == $if['subnetv6']) &&
			    (gen_subnetv6($_POST['network'], $_POST['network_subnet']) == gen_subnetv6($if['ipaddrv6'], $if['subnetv6']))) {
				$input_errors[] = sprintf(gettext("이 네트워크는 % s 인터페이스에 구성된 주소와 충돌합니다."), $if['descr']);
			}
		}
	}

	if (!$input_errors) {
		$route = array();
		$route['network'] = $osn;
		$route['gateway'] = $_POST['gateway'];
		$route['descr'] = $_POST['descr'];
		if ($_POST['disabled']) {
			$route['disabled'] = true;
		} else {
			unset($route['disabled']);
		}

		if (file_exists("{$g['tmp_path']}/.system_routes.apply")) {
			$toapplylist = unserialize(file_get_contents("{$g['tmp_path']}/.system_routes.apply"));
		} else {
			$toapplylist = array();
		}
		$a_routes[$id] = $route;

		if (!empty($oroute)) {
			$delete_targets = array_diff($old_targets, $new_targets);
			if (count($delete_targets)) {
				foreach ($delete_targets as $dts) {
					if (is_ipaddrv6($dts)) {
						$family = "-inet6";
					}
					$toapplylist[] = "/sbin/route delete {$family} {$dts}";
				}
			}
		}
		file_put_contents("{$g['tmp_path']}/.system_routes.apply", serialize($toapplylist));

		mark_subsystem_dirty('staticroutes');

		write_config(gettext("저장된 고정 경로 구성."));

		header("Location: system_routes.php");
		exit;
	}
}

$pgtitle = array(gettext("시스템"), gettext("라우팅"), gettext("정적 라우트"), gettext("편집"));
$pglinks = array("", "system_gateways.php", "system_routes.php", "@self");
$shortcut_section = "routing";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form;

if (isset($id) && $a_routes[$id]) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

$section = new Form_Section('Edit Route Entry');

$section->addInput(new Form_IpAddress(
	'network',
	'*Destination network',
	$pconfig['network'],
	'ALIASV4V6'
))->addMask('network_subnet', $pconfig['network_subnet'])->setHelp('Destination network for this static route');

$allGateways = array_combine(
	array_map(function($g){ return $g['name']; }, $a_gateways),
	array_map(function($g){ return $g['name'] .' - '. $g['gateway']; }, $a_gateways)
);
$section->addInput(new Form_Select(
	'gateway',
	'*Gateway',
	$pconfig['gateway'],
	$allGateways
))->setHelp('Choose which gateway this route applies to or %1$sadd a new one first%2$s',
	'<a href="/system_gateways_edit.php">', '</a>');

$section->addInput(new Form_Checkbox(
	'disabled',
	'Disabled',
	'Disable this static route',
	$pconfig['disabled']
))->setHelp('Set this option to disable this static route without removing it from '.
	'the list.');

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	htmlspecialchars($pconfig['descr'])
))->setHelp('A description may be entered here for administrative reference (not parsed).');

$form->add($section);

print $form;

?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// --------- Autocomplete -----------------------------------------------------------------------------------------
	var addressarray = <?= json_encode(get_alias_list(array("host", "network"))) ?>;

	$('#network').autocomplete({
		source: addressarray
	});
});
//]]>
</script>
<?php
include("foot.inc");
