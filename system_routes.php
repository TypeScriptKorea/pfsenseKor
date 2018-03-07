<?php
/*
 * system_routes.php
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
##|*IDENT=page-system-staticroutes
##|*NAME=System: Static Routes
##|*DESCR=Allow access to the 'System: Static Routes' page.
##|*MATCH=system_routes.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");

if (!is_array($config['staticroutes']['route'])) {
	$config['staticroutes']['route'] = array();
}

$a_routes = &$config['staticroutes']['route'];
$a_gateways = return_gateways_array(true, true, true);
$changedesc_prefix = gettext("동적 ") . ": ";
unset($input_errors);

if ($_POST['apply']) {
	$pconfig = $_POST;
	$retval = 0;

	if (file_exists("{$g['tmp_path']}/.system_routes.apply")) {
		$toapplylist = unserialize(file_get_contents("{$g['tmp_path']}/.system_routes.apply"));
		foreach ($toapplylist as $toapply) {
			mwexec("{$toapply}");
		}

		@unlink("{$g['tmp_path']}/.system_routes.apply");
	}

	$retval |= system_routing_configure();
	$retval |= filter_configure();
	/* reconfigure our gateway monitor */
	setup_gateways_monitor();

	if ($retval == 0) {
		clear_subsystem_dirty('staticroutes');
	}
}

function delete_static_route($id) {
	global $config, $a_routes, $changedesc_prefix;

	if (!isset($a_routes[$id])) {
		return;
	}

	$targets = array();
	if (is_alias($a_routes[$id]['network'])) {
		foreach (filter_expand_alias_array($a_routes[$id]['network']) as $tgt) {
			if (is_ipaddrv4($tgt)) {
				$tgt .= "/32";
			} else if (is_ipaddrv6($tgt)) {
				$tgt .= "/128";
			}
			if (!is_subnet($tgt)) {
				continue;
			}
			$targets[] = $tgt;
		}
	} else {
		$targets[] = $a_routes[$id]['network'];
	}

	foreach ($targets as $tgt) {
		$family = (is_subnetv6($tgt) ? "-inet6" : "-inet");
		mwexec("/sbin/route delete {$family} " . escapeshellarg($tgt));
	}

	unset($targets);
}

if ($_POST['act'] == "del") {
	if ($a_routes[$_POST['id']]) {
		$changedesc = $changedesc_prefix . sprintf(gettext("%s에 대한 경로를 삭제했습니다."), $a_routes[$_POST['id']]['network']);
		delete_static_route($_POST['id']);
		unset($a_routes[$_POST['id']]);
		write_config($changedesc);
		header("Location: system_routes.php");
		exit;
	}
}

if (isset($_POST['del_x'])) {
	/* delete selected routes */
	if (is_array($_POST['route']) && count($_POST['route'])) {
		$deleted_routes = "";
		foreach ($_POST['route'] as $routei) {
			$deleted_routes .= " " . $a_routes[$routei]['network'];
			delete_static_route($routei);
			unset($a_routes[$routei]);
		}
		$changedesc = $changedesc_prefix . sprintf(gettext("%s에 대한 경로를 삭제했습니다."), $deleted_routes);
		write_config($changedesc);
		header("Location: system_routes.php");
		exit;
	}

}

if ($_POST['act'] == "toggle") {
	if ($a_routes[$_POST['id']]) {
		$do_update_config = true;
		if (isset($a_routes[$_POST['id']]['disabled'])) {
			// Do not enable a route whose gateway is disabled
			if (isset($a_gateways[$a_routes[$_POST['id']]['gateway']]['disabled'])) {
				$do_update_config = false;
				$input_errors[] = $changedesc_prefix . sprintf(gettext("게이트웨이가 비활성화되어 있으며 %s에 대한 경로를 활성화 할 수 없습니다."), $a_routes[$_POST['id']]['network']);
			} else {
				unset($a_routes[$_POST['id']]['disabled']);
				$changedesc = $changedesc_prefix . sprintf(gettext("%s에 대한 활성화 된 경로"), $a_routes[$_POST['id']]['network']);
			}
		} else {
			delete_static_route($_POST['id']);
			$a_routes[$_POST['id']]['disabled'] = true;
			$changedesc = $changedesc_prefix . sprintf(gettext("%s에 대한 비활성화 된 경로"), $a_routes[$_POST['id']]['network']);
		}

		if ($do_update_config) {
			if (write_config($changedesc)) {
				mark_subsystem_dirty('staticroutes');
			}
			header("Location: system_routes.php");
			exit;
		}
	}
}

if($_POST['save']) {
	/* yuck - IE won't send value attributes for image buttons, while Mozilla does - so we use .x/.y to find move button clicks instead... */
	unset($movebtn);
	foreach ($_POST as $pn => $pd) {
		if (preg_match("/move_(\d+)_x/", $pn, $matches)) {
			$movebtn = $matches[1];
			break;
		}
	}
	/* move selected routes before this route */
	if (isset($movebtn) && is_array($_POST['route']) && count($_POST['route'])) {
		$a_routes_new = array();

		/* copy all routes < $movebtn and not selected */
		for ($i = 0; $i < $movebtn; $i++) {
			if (!in_array($i, $_POST['route'])) {
				$a_routes_new[] = $a_routes[$i];
			}
		}

		/* copy all selected routes */
		for ($i = 0; $i < count($a_routes); $i++) {
			if ($i == $movebtn) {
				continue;
			}
			if (in_array($i, $_POST['route'])) {
				$a_routes_new[] = $a_routes[$i];
			}
		}

		/* copy $movebtn route */
		if ($movebtn < count($a_routes)) {
			$a_routes_new[] = $a_routes[$movebtn];
		}

		/* copy all routes > $movebtn and not selected */
		for ($i = $movebtn+1; $i < count($a_routes); $i++) {
			if (!in_array($i, $_POST['route'])) {
				$a_routes_new[] = $a_routes[$i];
			}
		}
		if (count($a_routes_new) > 0) {
			$a_routes = $a_routes_new;
		}

		if (write_config(gettext("저장된 고정 경로 구성."))) {
			mark_subsystem_dirty('staticroutes');
		}
		header("Location: system_routes.php");
		exit;
	}
}

$pgtitle = array(gettext("시스템"), gettext("라우팅"), gettext("정적 라우트"));
$pglinks = array("", "system_gateways.php", "@self");
$shortcut_section = "routing";

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}
if ($_POST['apply']) {
	print_apply_result_box($retval);
}
if (is_subsystem_dirty('staticroutes')) {
	print_apply_box(gettext("고정 경로 구성이 변경되었습니다.") . "<br />" . gettext("변경사항을 저장해야 적용됩니다."));
}

$tab_array = array();
$tab_array[0] = array(gettext("게이트웨이"), false, "system_gateways.php");
$tab_array[1] = array(gettext("정적 라우트"), true, "system_routes.php");
$tab_array[2] = array(gettext("게이트웨이 그룹"), false, "system_gateway_groups.php");
display_top_tabs($tab_array);

?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('정적 라우트')?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-condensed table-rowdblclickedit">
				<thead>
					<tr>
						<th></th>
						<th><?=gettext("네트워크")?></th>
						<th><?=gettext("게이트웨이")?></th>
						<th><?=gettext("인터페이스")?></th>
						<th><?=gettext("설명")?></th>
						<th><?=gettext("Actions")?></th>
					</tr>
				</thead>
				<tbody>
<?php
foreach ($a_routes as $i => $route):
	if (isset($route['disabled'])) {
		$icon = 'fa-ban';
	} else {
		$icon = 'fa-check-circle-o';
	}
?>
				<tr<?=($icon != 'fa-check-circle-o')? ' class="disabled"' : ''?>>
					<td><i class="fa <?=$icon?>"></i></td>
					<td>
						<?=strtolower($route['network'])?>
					</td>
					<td>
						<?=htmlentities($a_gateways[$route['gateway']]['name']) . " - " . htmlentities($a_gateways[$route['gateway']]['gateway'])?>
					</td>
					<td>
						<?=convert_friendly_interface_to_friendly_descr($a_gateways[$route['gateway']]['friendlyiface'])?>
					</td>
					<td>
						<?=htmlspecialchars($route['descr'])?>
					</td>
					<td>
						<a href="system_routes_edit.php?id=<?=$i?>" class="fa fa-pencil" title="<?=gettext('라우트 편집')?>"></a>

						<a href="system_routes_edit.php?dup=<?=$i?>" class="fa fa-clone" title="<?=gettext('라우트 복사')?>"></a>

				<?php if (isset($route['disabled'])) {
				?>
						<a href="?act=toggle&amp;id=<?=$i?>" class="fa fa-check-square-o" title="<?=gettext('라우트 활성화')?>" usepost></a>
				<?php } else {
				?>
						<a href="?act=toggle&amp;id=<?=$i?>" class="fa fa-ban" title="<?=gettext('라우트 비활성화')?>" usepost></a>
				<?php }
				?>
						<a href="system_routes.php?act=del&amp;id=<?=$i?>" class="fa fa-trash" title="<?=gettext('라우트 삭제')?>" usepost></a>

					</td>
				</tr>
<?php endforeach; ?>
			</table>
		</div>
	</div>
</div>

<nav class="action-buttons">
	<a href="system_routes_edit.php" role="button" class="btn btn-success btn-sm">
		<i class="fa fa-plus icon-embed-btn"></i>
		<?=gettext("")?>
	</a>
</nav>
<?php

include("foot.inc");
