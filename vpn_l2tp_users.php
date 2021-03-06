<?php
/*
 * vpn_l2tp_users.php
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2004-2018 Rubicon Communications, LLC (Netgate)
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
2018.03.12
한글화 번역 시작
*/

##|+PRIV
##|*IDENT=page-vpn-vpnl2tp-users
##|*NAME=VPN: L2TP: Users
##|*DESCR=Allow access to the 'VPN: L2TP: Users' page.
##|*MATCH=vpn_l2tp_users.php*
##|-PRIV

$pgtitle = array(gettext("VPN"), gettext("L2TP"), gettext("Users"));
$pglinks = array("", "vpn_l2tp.php", "@self");
$shortcut_section = "l2tps";

require_once("guiconfig.inc");
require_once("pfsense-utils.inc");
require_once("vpn.inc");

if (!is_array($config['l2tp']['user'])) {
	$config['l2tp']['user'] = array();
}
$a_secret = &$config['l2tp']['user'];


$pconfig = $_POST;

if ($_POST['apply']) {
	$retval = 0;
	if (!is_subsystem_dirty('rebootreq')) {
		$retval |= vpn_l2tp_configure();
	}
	if ($retval == 0) {
		if (is_subsystem_dirty('l2tpusers')) {
			clear_subsystem_dirty('l2tpusers');
		}
	}
}

if ($_POST['act'] == "del") {
	if ($a_secret[$_POST['id']]) {
		unset($a_secret[$_POST['id']]);
		write_config(gettext("L2TP VPN 사용자를 삭제하였습니다."));
		mark_subsystem_dirty('l2tpusers');
		pfSenseHeader("vpn_l2tp_users.php");
		exit;
	}
}

include("head.inc");

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (isset($config['l2tp']['radius']['enable'])) {
	print_info_box(gettext("RADIUS가 사용됩니다. 로컬 사용자 데이터베이스는 사용되지 않습니다."));
}

if (is_subsystem_dirty('l2tpusers')) {
	print_apply_box(gettext("L2TP 사용자 목록이 수정되었습니다.") . "<br />" . gettext("변경사항을 저장하시면 적용됩니다.") . ".<br /><b>" . gettext("경고 : 모든 L2TP 세션을 종료합니다!") . "</b>");
}


$tab_array = array();
$tab_array[] = array(gettext("구성"), false, "vpn_l2tp.php");
$tab_array[] = array(gettext("사용자"), true, "vpn_l2tp_users.php");
display_top_tabs($tab_array);
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('L2TP 사용자')?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th><?=gettext("사용자 이름")?></th>
						<th><?=gettext("IP주소")?></th>
						<th><?=gettext("Actions")?></th>
					</tr>
				</thead>
				<tbody>
<?php $i = 0; foreach ($a_secret as $secretent):?>
					<tr>
						<td>
							<?=htmlspecialchars($secretent['name'])?>
						</td>
						<td>
							<?php if ($secretent['ip'] == "") $secretent['ip'] = "Dynamic"?>
							<?=htmlspecialchars($secretent['ip'])?>&nbsp;
						</td>
						<td>
							<a class="fa fa-pencil"	title="<?=gettext('유저 편집')?>"	href="vpn_l2tp_users_edit.php?id=<?=$i?>"></a>
							<a class="fa fa-trash"	title="<?=gettext('유저 삭제')?>"	href="vpn_l2tp_users.php?act=del&amp;id=<?=$i?>" usepost></a>
						</td>
					</tr>
<?php $i++; endforeach?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<nav class="action-buttons">
	<a class="btn btn-success btn-sm" href="vpn_l2tp_users_edit.php">
		<i class="fa fa-plus icon-embed-btn"></i>
		<?=gettext("Add")?>
	</a>
</nav>

<?php include("foot.inc");
