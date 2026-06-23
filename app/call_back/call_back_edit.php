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

//check permissions
	if (!permission_exists('call_back_edit')) {
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

//build the query string for the return link
	$param = [];
	if (!empty($page)) { $param['page'] = $page; }
	if (!empty($_GET['order_by'])) { $param['order_by'] = $order_by; }
	if (!empty($_GET['order'])) { $param['order'] = $order; }
	if (!empty($search)) { $param['search'] = $search; }
	if (!empty($show)) { $param['show'] = $show; }
	$query_string = http_build_query($param);

//get the assigned extension uuids for the user (per extension restriction)
	$user_extension_uuids = [];
	if (!permission_exists('call_back_extension') && !empty($_SESSION['user']['extension'])) {
		foreach ($_SESSION['user']['extension'] as $field) {
			if (is_uuid($field['extension_uuid'])) {
				$user_extension_uuids[] = $field['extension_uuid'];
			}
		}
	}

//get the call_back_uuid
	$call_back_uuid = '';
	if (is_uuid($_REQUEST['id'] ?? '')) {
		$call_back_uuid = $_REQUEST['id'];
	}
	if (!is_uuid($call_back_uuid)) {
		header('Location: call_backs.php'.($query_string ? '?'.$query_string : ''));
		exit;
	}

//build the sql restriction so a user can only access their own extensions
	$restrict = '';
	if (!permission_exists('call_back_extension')) {
		if (empty($user_extension_uuids)) {
			$restrict = "and false ";
		}
		else {
			$quoted = [];
			foreach ($user_extension_uuids as $extension_uuid) {
				$quoted[] = "'".$extension_uuid."'";
			}
			$restrict = "and extension_uuid in (".implode(', ', $quoted).") ";
		}
	}

//process the form post
	if (!empty($_POST) && empty($_POST['persistformvar'])) {

		//validate the token
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add($text['message-invalid_token'], 'negative');
			header('Location: call_backs.php'.($query_string ? '?'.$query_string : ''));
			exit;
		}

		//collect the posted values
		$call_back_status = $_POST['call_back_status'] ?? 'waiting';
		$call_back_destination = preg_replace('#[^0-9+*#]#', '', $_POST['call_back_destination'] ?? '');
		$call_back_description = $_POST['call_back_description'] ?? '';

		//confirm the record exists and is in scope before saving
		$sql = "select call_back_uuid from v_call_back ";
		$sql .= "where call_back_uuid = :call_back_uuid ";
		$sql .= "and (domain_uuid = :domain_uuid ".(permission_exists('call_back_domain') ? "or domain_uuid is null " : "").") ";
		$sql .= $restrict;
		$parameters['call_back_uuid'] = $call_back_uuid;
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$found = $database->select($sql, $parameters, 'column');
		unset($sql, $parameters);

		if (!empty($found)) {
			$array['call_back'][0]['call_back_uuid'] = $call_back_uuid;
			$array['call_back'][0]['call_back_status'] = $call_back_status;
			$array['call_back'][0]['call_back_destination'] = $call_back_destination;
			$array['call_back'][0]['call_back_description'] = $call_back_description;

			$p = permissions::new();
			$p->add('call_back_edit', 'temp');
			$database->save($array);
			$p->delete('call_back_edit', 'temp');
			unset($array);

			message::add($text['message-update']);
		}
		header('Location: call_backs.php'.($query_string ? '?'.$query_string : ''));
		exit;
	}

//get the record
	$sql = "select * from view_call_back ";
	$sql .= "where call_back_uuid = :call_back_uuid ";
	$sql .= "and (domain_uuid = :domain_uuid ".(permission_exists('call_back_domain') ? "or domain_uuid is null " : "").") ";
	$sql .= $restrict;
	$parameters['call_back_uuid'] = $call_back_uuid;
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$row = $database->select($sql, $parameters, 'row');
	unset($sql, $parameters);

	if (empty($row)) {
		header('Location: call_backs.php'.($query_string ? '?'.$query_string : ''));
		exit;
	}

//set the values from the record
	$extension = $row['extension'] ?? '';
	$call_back_type = $row['call_back_type'] ?? 'call_back';
	$call_back_status = $row['call_back_status'] ?? 'waiting';
	$call_back_caller_id_name = $row['call_back_caller_id_name'] ?? '';
	$call_back_caller_id_number = $row['call_back_caller_id_number'] ?? '';
	$call_back_destination = $row['call_back_destination'] ?? '';
	$call_back_recording_name = $row['call_back_recording_name'] ?? '';
	$call_back_description = $row['call_back_description'] ?? '';

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-call_back'];
	require_once "resources/header.php";

//show the content
	echo "<form name='frm' id='frm' method='post' action=''>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-call_back']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','link'=>'call_backs.php'.($query_string ? '?'.$query_string : '')]);
	if (permission_exists('call_back_call') && !empty($call_back_destination)) {
		echo button::create(['type'=>'button','label'=>$text['button-call'],'icon'=>'phone','link'=>'call_back_call.php?id='.urlencode($call_back_uuid).($query_string ? '&'.$query_string : '')]);
	}
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	//extension
	echo "<tr>\n";
	echo "	<td width='30%' class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-extension']."</td>\n";
	echo "	<td width='70%' class='vtable' align='left'>".escape($extension)."</td>\n";
	echo "</tr>\n";

	//type
	echo "<tr>\n";
	echo "	<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-type']."</td>\n";
	echo "	<td class='vtable' align='left'>".($call_back_type == 'message' ? $text['label-message'] : $text['label-call_back'])."</td>\n";
	echo "</tr>\n";

	//caller id name
	echo "<tr>\n";
	echo "	<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-caller_id_name']."</td>\n";
	echo "	<td class='vtable' align='left'>".escape($call_back_caller_id_name)."</td>\n";
	echo "</tr>\n";

	//caller id number
	echo "<tr>\n";
	echo "	<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-caller_id_number']."</td>\n";
	echo "	<td class='vtable' align='left'>".escape(format_phone($call_back_caller_id_number))."</td>\n";
	echo "</tr>\n";

	//destination (editable)
	echo "<tr>\n";
	echo "	<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-destination']."</td>\n";
	echo "	<td class='vtable' align='left'>\n";
	echo "		<input class='formfld' type='text' name='call_back_destination' maxlength='255' value=\"".escape($call_back_destination)."\">\n";
	echo "	</td>\n";
	echo "</tr>\n";

	//status (editable)
	echo "<tr>\n";
	echo "	<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-status']."</td>\n";
	echo "	<td class='vtable' align='left'>\n";
	echo "		<select class='formfld' name='call_back_status'>\n";
	foreach (['waiting','called','completed'] as $status_option) {
		$selected = ($call_back_status == $status_option) ? "selected='selected'" : '';
		echo "			<option value='".$status_option."' ".$selected.">".$text['label-'.$status_option]."</option>\n";
	}
	echo "		</select>\n";
	echo "	</td>\n";
	echo "</tr>\n";

	//message recording
	if (!empty($call_back_recording_name)) {
		echo "<tr>\n";
		echo "	<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-recording']."</td>\n";
		echo "	<td class='vtable' align='left'>\n";
		echo "		<a href='call_back_recording.php?id=".urlencode($call_back_uuid)."&type=play'><span class='fas fa-play'></span>&nbsp;".$text['button-view']."</a>\n";
		echo "	</td>\n";
		echo "</tr>\n";
	}

	//notes / description
	echo "<tr>\n";
	echo "	<td class='vncell' valign='top' align='left'>".$text['label-description']."</td>\n";
	echo "	<td class='vtable' align='left'>\n";
	echo "		<textarea class='formfld' name='call_back_description' style='width: 100%; height: 80px;'>".escape($call_back_description)."</textarea>\n";
	echo "	</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";

	echo "<input type='hidden' name='id' value='".escape($call_back_uuid)."'>\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";
