<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2008-2026
	the Initial Developer. All Rights Reserved.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once "resources/paging.php";

//check permissions
	if (!permission_exists('call_back_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set variables from GET parameters
	$page = is_numeric($_GET['page'] ?? '') ? $_GET['page'] : 0;
	$order_by = preg_replace('#[^a-zA-Z0-9_\-]#', '', ($_GET['order_by'] ?? 'insert_date'));
	$order = ($_GET['order'] ?? '') == 'asc' ? 'asc' : 'desc';
	$search = $_GET['search'] ?? '';
	$show = $_GET['show'] ?? '';

//build the query string
	$param = [];
	if (!empty($page)) {
		$param['page'] = $page;
	}
	if (!empty($_GET['order_by'])) {
		$param['order_by'] = $order_by;
	}
	if (!empty($_GET['order'])) {
		$param['order'] = $order;
	}
	if (!empty($search)) {
		$param['search'] = $search;
	}
	if (!empty($show) && $show == 'all' && permission_exists('call_back_domain')) {
		$param['show'] = $show;
	}
	$query_string = http_build_query($param);

//get the assigned extension uuids for the user (used to restrict the view per extension)
	$user_extension_uuids = [];
	if (!permission_exists('call_back_extension') && !empty($_SESSION['user']['extension'])) {
		foreach ($_SESSION['user']['extension'] as $field) {
			if (is_uuid($field['extension_uuid'])) {
				$user_extension_uuids[] = $field['extension_uuid'];
			}
		}
	}

//define a helper to apply the per extension restriction to a query
	$apply_restrictions = function(&$sql, &$parameters) use ($show, $user_extension_uuids) {
		if ($show == 'all' && permission_exists('call_back_domain')) {
			//show all records across all domains
		}
		else {
			$sql .= "and ( ";
			$sql .= "	domain_uuid = :domain_uuid ";
			if (permission_exists('call_back_domain')) {
				$sql .= "	or domain_uuid is null ";
			}
			$sql .= ") ";
			$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		}
		if (!permission_exists('call_back_extension')) {
			if (empty($user_extension_uuids)) {
				$sql .= "and false ";
			}
			else {
				$quoted = [];
				foreach ($user_extension_uuids as $extension_uuid) {
					$quoted[] = "'".$extension_uuid."'";
				}
				$sql .= "and extension_uuid in (".implode(', ', $quoted).") ";
			}
		}
	};

//process the http post data by action
	if (!empty($_POST['call_backs']) && !empty($_POST['action'])) {
		$action = $_POST['action'];
		$call_backs = $_POST['call_backs'];
		switch ($action) {
			case 'toggle':
				if (permission_exists('call_back_edit')) {
					$obj = new call_back;
					$obj->toggle($call_backs);
				}
				break;
			case 'delete':
				if (permission_exists('call_back_delete')) {
					$obj = new call_back;
					$obj->delete($call_backs);
				}
				break;
		}
		header('Location: call_backs.php'.($query_string ? '?'.$query_string : ''));
		exit;
	}

//get the count
	$parameters = [];
	$sql = "select count(*) from v_call_back ";
	$sql .= "where true ";
	$apply_restrictions($sql, $parameters);
	if (!empty($search)) {
		$sql .= "and (";
		$sql .= " lower(call_back_caller_id_name) like :search ";
		$sql .= " or lower(call_back_caller_id_number) like :search ";
		$sql .= " or lower(call_back_destination) like :search ";
		$sql .= " or lower(call_back_description) like :search ";
		$sql .= ") ";
		$parameters['search'] = '%'.lower_case($search).'%';
	}
	$num_rows = $database->select($sql, $parameters, 'column');
	unset($parameters);

//prepare to page the results
	$rows_per_page = $settings->get('domain', 'paging', 50);
	list($paging_controls, $rows_per_page) = paging($num_rows, $query_string, $rows_per_page);
	list($paging_controls_mini, $rows_per_page) = paging($num_rows, $query_string, $rows_per_page, true);
	$offset = $rows_per_page * $page;

//set the time zone
	$time_zone = $settings->get('domain', 'time_zone', date_default_timezone_get());

//set the time format options: 12h, 24h
	if ($settings->get('domain', 'time_format') == '24h') {
		$time_format = 'HH24:MI:SS';
	}
	else {
		$time_format = 'HH12:MI:SS am';
	}

//get the list
	$sql = "select call_back_uuid, domain_uuid, extension_uuid, extension, number_alias, ";
	$sql .= " call_back_type, call_back_status, call_back_caller_id_name, call_back_caller_id_number, ";
	$sql .= " call_back_destination, call_back_recording_path, call_back_recording_name, ";
	$sql .= " cast(call_back_enabled as text) as call_back_enabled, call_back_description, ";
	$sql .= " to_char(timezone(:time_zone, insert_date), 'DD Mon YYYY') as date_formatted, \n";
	$sql .= " to_char(timezone(:time_zone, insert_date), '".$time_format."') as time_formatted, \n";
	$sql .= " insert_date ";
	$sql .= "from view_call_back ";
	$sql .= "where true ";
	$parameters = [];
	$apply_restrictions($sql, $parameters);
	if (!empty($search)) {
		$sql .= "and (";
		$sql .= " lower(call_back_caller_id_name) like :search ";
		$sql .= " or lower(call_back_caller_id_number) like :search ";
		$sql .= " or lower(call_back_destination) like :search ";
		$sql .= " or lower(call_back_description) like :search ";
		$sql .= ") ";
		$parameters['search'] = '%'.lower_case($search).'%';
	}
	$sql .= order_by($order_by, $order, ['insert_date','extension','call_back_caller_id_name']);
	$sql .= limit_offset($rows_per_page, $offset);
	$parameters['time_zone'] = $time_zone;
	$result = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);

//determine if any global records exist
	$global_call_backs = false;
	if (permission_exists('call_back_domain') && !empty($result) && is_array($result)) {
		foreach ($result as $row) {
			if (!is_uuid($row['domain_uuid'])) { $global_call_backs = true; break; }
		}
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-call_backs'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-call_backs']."</b><div class='count'>".number_format($num_rows)."</div></div>\n";
	echo "	<div class='actions'>\n";
	if (permission_exists('call_back_edit') && $result) {
		echo button::create(['type'=>'button','label'=>$text['button-toggle'],'icon'=>$settings->get('theme', 'button_icon_toggle'),'id'=>'btn_toggle','name'=>'btn_toggle','style'=>'display: none;','onclick'=>"modal_open('modal-toggle','btn_toggle');"]);
	}
	if (permission_exists('call_back_delete') && $result) {
		echo button::create(['type'=>'button','label'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete'),'id'=>'btn_delete','name'=>'btn_delete','style'=>'display: none;','onclick'=>"modal_open('modal-delete','btn_delete');"]);
	}
	echo 		"<form id='form_search' class='inline' method='get'>\n";
	foreach ($param as $key => $value) {
		if ($key !== 'search' && $key !== 'page') {
			echo "		<input type='hidden' name='".escape($key)."' value='".escape($value)."'>\n";
		}
	}
	if ($show !== 'all' && permission_exists('call_back_domain')) {
		echo button::create(['type'=>'button','label'=>$text['button-show_all'],'icon'=>$settings->get('theme', 'button_icon_all'),'link'=>'?show=all']);
	}
	echo "		<input type='text' class='txt list-search' name='search' id='search' value=\"".escape($search)."\" placeholder=\"".$text['label-search']."\" onkeydown=''>";
	echo button::create(['label'=>$text['button-search'],'icon'=>$settings->get('theme', 'button_icon_search'),'type'=>'submit','id'=>'btn_search']);
	if (!empty($paging_controls_mini)) {
		echo "	<span style='margin-left: 15px;'>".$paging_controls_mini."</span>";
	}
	echo "		</form>\n";
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	if (permission_exists('call_back_edit') && $result) {
		echo modal::create(['id'=>'modal-toggle','type'=>'toggle','actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_toggle','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('toggle'); list_form_submit('form_list');"])]);
	}
	if (permission_exists('call_back_delete') && $result) {
		echo modal::create(['id'=>'modal-delete','type'=>'delete','actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_delete','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('delete'); list_form_submit('form_list');"])]);
	}

	echo $text['description-call_back']."\n";
	echo "<br /><br />\n";

	echo "<form id='form_list' method='post'>\n";
	echo "<input type='hidden' id='action' name='action' value=''>\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if (permission_exists('call_back_edit') || permission_exists('call_back_delete')) {
		echo "	<th class='checkbox'>\n";
		echo "		<input type='checkbox' id='checkbox_all' name='checkbox_all' onclick='list_all_toggle(); checkbox_on_change(this);' ".(!empty($result) ?: "style='visibility: hidden;'").">\n";
		echo "	</th>\n";
	}
	if ($global_call_backs) {
		echo th_order_by('domain_uuid', $text['label-domain'], $order_by, $order, null, "class='center'", $param);
	}
	echo th_order_by('extension', $text['label-extension'], $order_by, $order, null, "class='center'", $param);
	echo th_order_by('call_back_type', $text['label-type'], $order_by, $order, null, "class='center'", $param);
	echo th_order_by('call_back_caller_id_name', $text['label-caller_id_name'], $order_by, $order, null, null, $param);
	echo th_order_by('call_back_destination', $text['label-destination'], $order_by, $order, null, null, $param);
	echo th_order_by('call_back_status', $text['label-status'], $order_by, $order, null, "class='center'", $param);
	echo "	<th class='center'>".$text['label-recording']."</th>\n";
	echo th_order_by('insert_date', $text['label-date-added'], $order_by, $order, null, "class='shrink no-wrap'", $param);
	echo "	<th class='hide-md-dn pct-20'>".$text['label-description']."</th>\n";
	if (permission_exists('call_back_call')) {
		echo "	<td class='action-button'>&nbsp;</td>\n";
	}
	echo "</tr>\n";

	if (!empty($result)) {
		$x = 0;
		foreach ($result as $row) {
			$list_row_url = '';
			if (permission_exists('call_back_edit')) {
				$list_row_url = "call_back_edit.php?id=".urlencode($row['call_back_uuid']).($query_string ? '&'.$query_string : '');
				if ($row['domain_uuid'] != $_SESSION['domain_uuid'] && permission_exists('domain_select')) {
					$list_row_url .= '&domain_uuid='.urlencode($row['domain_uuid'] ?? '').'&domain_change=true';
				}
			}
			echo "<tr class='list-row' href='".$list_row_url."'>\n";
			if (permission_exists('call_back_edit') || permission_exists('call_back_delete')) {
				echo "	<td class='checkbox'>\n";
				echo "		<input type='checkbox' name='call_backs[".$x."][checked]' id='checkbox_".$x."' value='true' onclick=\"checkbox_on_change(this); if (!this.checked) { document.getElementById('checkbox_all').checked = false; }\">\n";
				echo "		<input type='hidden' name='call_backs[".$x."][uuid]' value='".escape($row['call_back_uuid'])."' />\n";
				echo "	</td>\n";
			}
			if ($global_call_backs) {
				if (!is_uuid($row['domain_uuid'])) {
					echo "	<td>".$text['label-global']."</td>\n";
				}
				else {
					echo "	<td class='overflow'>".escape($_SESSION['domains'][$row['domain_uuid']]['domain_name'] ?? '')."</td>\n";
				}
			}
			echo "	<td class='center'>".escape($row['extension'])."</td>\n";
			echo "	<td class='center'>".($row['call_back_type'] == 'message' ? $text['label-message'] : $text['label-call_back'])."</td>\n";
			echo "	<td>".escape($row['call_back_caller_id_name'])."</td>\n";
			echo "	<td>";
			if (permission_exists('call_back_edit')) {
				echo "<a href='".$list_row_url."'>".escape(format_phone($row['call_back_destination']))."</a>";
			}
			else {
				echo escape(format_phone($row['call_back_destination']));
			}
			echo "	</td>\n";
			echo "	<td class='center'>".($text['label-'.$row['call_back_status']] ?? escape($row['call_back_status']))."</td>\n";
			echo "	<td class='center'>";
			if (!empty($row['call_back_recording_name'])) {
				echo "<a href='call_back_recording.php?id=".urlencode($row['call_back_uuid'])."&type=play' title='".$text['label-message']."' onclick='event.stopPropagation();'><span class='fas fa-play'></span></a>";
			}
			else {
				echo "&nbsp;";
			}
			echo "	</td>\n";
			echo "	<td class='no-wrap'>".$row['date_formatted']." <span class='hide-sm-dn'>".$row['time_formatted']."</span></td>\n";
			echo "	<td class='description overflow hide-md-dn'>".escape($row['call_back_description'])."</td>\n";
			if (permission_exists('call_back_call')) {
				echo "	<td class='action-button'>";
				if (!empty($row['call_back_destination'])) {
					echo button::create(['type'=>'button','title'=>$text['button-call'],'icon'=>'phone','link'=>'call_back_call.php?id='.urlencode($row['call_back_uuid']).($query_string ? '&'.$query_string : ''),'onclick'=>'event.stopPropagation();']);
				}
				echo "	</td>\n";
			}
			echo "</tr>\n";
			$x++;
		}
		unset($result);
	}

	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";
	echo "<div align='center'>".$paging_controls."</div>\n";

	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";
