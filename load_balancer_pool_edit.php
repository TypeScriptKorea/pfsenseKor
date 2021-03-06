<?php
/*
 * load_balancer_pool_edit.php
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2004-2018 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2005-2008 Bill Marquette <bill.marquette@gmail.com>
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
2018.03.06
한글화 번역 시작
*/

##|+PRIV
##|*IDENT=page-loadbalancer-pool-edit
##|*NAME=Load Balancer: Pool: Edit
##|*DESCR=Allow access to the 'Load Balancer: Pool: Edit' page.
##|*MATCH=load_balancer_pool_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("filter.inc");
require_once("util.inc");

$referer = (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/load_balancer_pool.php');

if (!is_array($config['load_balancer']['lbpool'])) {
	$config['load_balancer']['lbpool'] = array();
}

$a_pool = &$config['load_balancer']['lbpool'];

$id = $_REQUEST['id'];


if (isset($id) && $a_pool[$id]) {
	$pconfig['name'] = $a_pool[$id]['name'];
	$pconfig['mode'] = $a_pool[$id]['mode'];
	$pconfig['descr'] = $a_pool[$id]['descr'];
	$pconfig['port'] = $a_pool[$id]['port'];
	$pconfig['retry'] = $a_pool[$id]['retry'];
	$pconfig['servers'] = &$a_pool[$id]['servers'];
	$pconfig['serversdisabled'] = &$a_pool[$id]['serversdisabled'];
	$pconfig['monitor'] = $a_pool[$id]['monitor'];
}

$changedesc = gettext("로드 밸런서: Pool:") . " ";
$changecount = 0;

$allowed_modes = array("loadbalance", "failover");

if ($_POST['save']) {
	$changecount++;

	unset($input_errors);
	$pconfig = $_POST;

	/* input validation */
	$reqdfields = explode(" ", "name mode port monitor servers");
	$reqdfieldsn = array(gettext("이름"), gettext("모드"), gettext("포트"), gettext("모니터"), gettext("서버 리스트"));

	do_input_validation($_POST, $reqdfields, $reqdfieldsn, $input_errors);

	/* Ensure that our pool names are unique */
	for ($i=0; isset($config['load_balancer']['lbpool'][$i]); $i++) {
		if (($_POST['name'] == $config['load_balancer']['lbpool'][$i]['name']) && ($i != $id)) {
			$input_errors[] = gettext("동일한 이름의 풀(pool)이 존재합니다.");
		}
	}

	if (preg_match('/[ \/]/', $_POST['name'])) {
		$input_errors[] = gettext("이름에 공백 또는 슬래시는 사용할 수 없습니다.");
	}

	if (strlen($_POST['name']) > 16) {
		$input_errors[] = gettext("이름은 16자 이하로 설정해주십시오.");
	}

	if (in_array($_POST['name'], $reserved_table_names)) {
		$input_errors[] = sprintf(gettext("'%s'은(는) 예약어이므로 사용할 수 없습니다."), $_POST['name']);
	}

	if (is_alias($_POST['name'])) {
		$input_errors[] = sprintf(gettext("%s란 이름의 alias가 이미 존재합니다."), $_POST['name']);
	}

	if (!is_port_or_alias($_POST['port'])) {
		$input_errors[] = gettext("포트는 1에서 65535 사이의 정수 또는 alias로 지정되어야 합니다.");
	}

	// May as well use is_port as we want a positive integer and such.
	if (!empty($_POST['retry']) && !is_port($_POST['retry'])) {
		$input_errors[] = gettext("재시도 값은 1에서 65535 사이의 정수로 지정되어야 합니다.");
	}

	if (!in_array($_POST['mode'], $allowed_modes)) {
		$input_errors[] = gettext("제출 된 모드가 유효하지 않습니다.");
	}

	if (is_array($_POST['servers'])) {
		foreach ($pconfig['servers'] as $svrent) {
			if (!is_ipaddr($svrent) && !is_subnetv4($svrent)) {
				$input_errors[] = sprintf(gettext("%s은 유효한 IP주소 또는 IPv4 서브넷(\"사용 가능\"목록)이 아닙니다."), $svrent);
			} else if (is_subnetv4($svrent) && subnet_size($svrent) > 64) {
				$input_errors[] = sprintf(gettext("%s은(\"사용 가능\"목록에 있는) 64개 이상의 IP주소를 포함하는 서브넷입니다."), $svrent);
			}
		}
	}

	if (is_array($_POST['serversdisabled'])) {
		foreach ($pconfig['serversdisabled'] as $svrent) {
			if (!is_ipaddr($svrent) && !is_subnetv4($svrent)) {
				$input_errors[] = sprintf(gettext("%s은(는) (\"disabled\"목록에있는) 유효한 IP 주소 또는 IPv4 서브넷이 아닙니다."), $svrent);
			} else if (is_subnetv4($svrent) && subnet_size($svrent) > 64) {
				$input_errors[] = sprintf(gettext("%s은 64 개 이상의 IP 주소를 포함하는 서브넷입니다(\"비활성화 됨\" 목록에 있음)."), $svrent);
			}
		}
	}

	$m = array();

	for ($i=0; isset($config['load_balancer']['monitor_type'][$i]); $i++) {
		$m[$config['load_balancer']['monitor_type'][$i]['name']] = $config['load_balancer']['monitor_type'][$i];
	}

	if (!isset($m[$_POST['monitor']])) {
		$input_errors[] = gettext("Invalid monitor chosen.");
	}

	if (!$input_errors) {
		$poolent = array();
		if (isset($id) && $a_pool[$id]) {
			$poolent = $a_pool[$id];
		}

		if ($poolent['name'] != "") {
			$changedesc .= sprintf(gettext(" modified '%s' pool:"), $poolent['name']);
		}

		update_if_changed("name", $poolent['name'], $_POST['name']);
		update_if_changed("mode", $poolent['mode'], $_POST['mode']);
		update_if_changed("description", $poolent['descr'], $_POST['descr']);
		update_if_changed("port", $poolent['port'], $_POST['port']);
		update_if_changed("retry", $poolent['retry'], $_POST['retry']);
		update_if_changed("servers", $poolent['servers'], $_POST['servers']);
		update_if_changed("serversdisabled", $poolent['serversdisabled'], $_POST['serversdisabled']);
		update_if_changed("monitor", $poolent['monitor'], $_POST['monitor']);

		if (isset($id) && $a_pool[$id]) {
			/* modify all virtual servers with this name */
			for ($i = 0; isset($config['load_balancer']['virtual_server'][$i]); $i++) {
				if ($config['load_balancer']['virtual_server'][$i]['lbpool'] == $a_pool[$id]['name']) {
					$config['load_balancer']['virtual_server'][$i]['lbpool'] = $poolent['name'];
				}
			}
			$a_pool[$id] = $poolent;
		} else {
			$a_pool[] = $poolent;
		}

		if ($changecount > 0) {
			/* Mark pool dirty */
			mark_subsystem_dirty('loadbalancer');
			write_config($changedesc);
		}

		header("Location: load_balancer_pool.php");
		exit;
	}
}

$pgtitle = array(gettext("Services"), gettext("로드 밸런서"), gettext("Pools"), gettext("편집"));
$pglinks = array("", "load_balancer_pool.php", "load_balancer_pool.php", "@self");
$shortcut_section = "relayd";

include("head.inc");
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	// Disables the specified input element
	function disableInput(id, disable) {
		$('#' + id).prop("disabled", disable);
	}

	// Select every option in the specified multiselect
	function AllServers(id, selectAll) {
	   for (i = 0; i < id.length; i++)	   {
		   id.eq(i).prop('selected', selectAll);
	   }
	}

	// Move all selected options from one multiselect to another
	function moveOptions(From, To)	{
		var len = From.length;
		var option;

		if (len > 0) {
			for (i=0; i<len; i++) {
				if (From.eq(i).is(':selected')) {
					option = From.eq(i).val();
					value  = From.eq(i).text();
					To.append(new Option(value, option));
					From.eq(i).remove();
				}
			}
		}
	}

	function checkPoolControls() {

		if ($("#mode").val() == "failover") {
			disableInput('movetoenabled', $('[name="servers[]"] option').length > 0);
		} else {
			disableInput('movetoenabled', false);
		}
	}

	// Move (copy/delete) all but one of the items in the Enabled (server) list to the Disabled list
	function enforceFailover() {
		if ($('#mode').val() != 'failover') {
			return;
		}

		var len = $('[name="servers[]"] option').length;
		var option;

		if (len > 1) {
			for (i=len-1; i>0; i--) {
				option = $('[name="servers[]"] option').eq(i).val();
				$('[name="serversdisabled[]"]').append(new Option(option, option));
				$('[name="servers[]"] option').eq(i).remove();
			}
		}
	}

	// On click . .
	$("#btnaddtopool").click(function() {
		$('[name="servers[]"]').append(new Option($('#ipaddr').val(), $('#ipaddr').val()));
		enforceFailover();
		checkPoolControls();
	});

	$('#mode').on('change', function() {
		enforceFailover();
		checkPoolControls();
	});

	$("#removeenabled").click(function() {
		$('[name="servers[]"] option:selected').remove();
	});

	$("#removedisabled").click(function() {
		$('[name="serversdisabled[]"] option:selected').remove();
	});

	$("#movetodisabled").click(function() {
		moveOptions($('[name="servers[]"] option'), $('[name="serversdisabled[]"]'));
	});

	$("#movetoenabled").click(function() {
		moveOptions($('[name="serversdisabled[]"] option'), $('[name="servers[]"]'));
	});

	// On initial page load
	checkPoolControls();

	// On submit
	$('form').submit(function() {
		AllServers($('[name="servers[]"] option'), true);
		AllServers($('[name="serversdisabled[]"] option'), true);
	});

});
//]]>
</script>

<?php
if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form();

$form->setAction("load_balancer_pool_edit.php");

$section = new Form_Section('로드 밸런서 추가/편집 - 풀 항목');

$section->addInput(new Form_Input(
	'name',
	'*Name',
	'text',
	$pconfig['name']
));

$section->addInput(new Form_Select(
	'mode',
	'Mode',
	$pconfig['mode'],
	array(
		'loadbalance' => gettext('로드 밸런스'),
		'failover' => gettext('수동 장애 조치')
	)
));

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
));

$section->addInput(new Form_Input(
	'port',
	'*Port',
	'text',
	$pconfig['port']
))->setHelp('This is the port the servers are listening on. A port alias listed in Firewall -> Aliases may also be specified here.');

$section->addInput(new Form_Input(
	'retry',
	'Retry',
	'number',
	$pconfig['retry'],
	['min' => '1', 'max' => '65536']
))->setHelp('Optionally specify how many times to retry checking a server before declaring it down.');

$form->add($section);

$section = new Form_Section('Add Item to the Pool');

$monitorlist = array();

foreach ($config['load_balancer']['monitor_type'] as $monitor) {
	$monitorlist[$monitor['name']] = $monitor['name'];
}

if (count($config['load_balancer']['monitor_type'])) {
	$section->addInput(new Form_Select(
		'monitor',
		'Monitor',
		$pconfig['monitor'],
		$monitorlist
	));
} else {
	$section->addInput(new Form_StaticText(
		'Monitor',
		'Please add a monitor IP address on the monitors tab to use this feature."'
	));
}

$group = new Form_Group('서버 IP ');

$group->add(new Form_IpAddress(
	'ipaddr',
	'IP Address',
	$pconfig['ipaddr']
));

$group->add(new Form_Button(
	'btnaddtopool',
	'Add to pool',
	null,
	'fa-plus'
))->setAttribute('type','button')->addClass('btn-success');

$section->add($group);

$form->add($section);

$section = new Form_Section('Current Pool Members');

$group = new Form_Group('*Members');

$list = array();

if (is_array($pconfig['serversdisabled'])) {
	foreach ($pconfig['serversdisabled'] as $svrent) {
		if ($svrent != '') {
			$list[$svrent] = $svrent;
		}
	}
}

$group->add(new Form_Select(
	'serversdisabled',
	null,
	$pconfig['serversdisabled'],
	$list,
	true
))->setHelp('Disabled');

$group->add(new Form_Select(
	'servers',
	null,
	$pconfig['servers'],
	is_array($pconfig['servers']) ? array_combine($pconfig['servers'], $pconfig['servers']) : array(),
	true
))->setHelp('Enabled (Default)');

$section->add($group);

$group = new Form_Group('');

$group->add(new Form_Button(
	'removedisabled',
	'Remove',
	null,
	'fa-trash'
))->setAttribute('type','button')->addClass('btn-danger btn-sm');

$group->add(new Form_Button(
	'removeenabled',
	'Remove',
	null,
	'fa-trash'
))->setAttribute('type','button')->addClass('btn-danger btn-sm');

$section->add($group);

$group = new Form_Group('');

$group->add(new Form_Button(
	'movetoenabled',
	'Move to enabled list',
	null,
	'fa-angle-double-right'
))->setAttribute('type','button')->addClass('btn-info btn-sm');

$group->add(new Form_Button(
	'movetodisabled',
	'Move to disabled list',
	null,
	'fa-angle-double-left'
))->setAttribute('type','button')->addClass('btn-info btn-sm');

$section->add($group);

if (isset($id) && $a_pool[$id] && $_REQUEST['act'] != 'dup') {
	$section->addInput(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

$form->add($section);

print($form);
?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {

	// --------- Autocomplete -----------------------------------------------------------------------------------------
	var customarray = <?= json_encode(get_alias_list(array("port", "url_ports", "urltable_ports"))) ?>;

	$('#port').autocomplete({
		source: customarray
	});
});
//]]>
</script>

<?php
include("foot.inc");
