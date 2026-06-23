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
	if (!permission_exists('call_back_call')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//get the query string for the return link
	$page = is_numeric($_GET['page'] ?? '') ? $_GET['page'] : 0;
	$order_by = preg_replace('#[^a-zA-Z0-9_\-]#', '', ($_GET['order_by'] ?? 'insert_date'));
	$order = ($_GET['order'] ?? '') == 'asc' ? 'asc' : 'desc';
	$search = $_GET['search'] ?? '';
	$show = $_GET['show'] ?? '';
	$param = [];
	if (!empty($page)) { $param['page'] = $page; }
	if (!empty($_GET['order_by'])) { $param['order_by'] = $order_by; }
	if (!empty($_GET['order'])) { $param['order'] = $order; }
	if (!empty($search)) { $param['search'] = $search; }
	if (!empty($show)) { $param['show'] = $show; }
	$query_string = http_build_query($param);

//get the call_back_uuid
	$call_back_uuid = is_uuid($_GET['id'] ?? '') ? $_GET['id'] : '';
	if (!is_uuid($call_back_uuid)) {
		header('Location: call_backs.php'.($query_string ? '?'.$query_string : ''));
		exit;
	}

//get the assigned extension uuids for the user (per extension restriction)
	$user_extension_uuids = [];
	if (!permission_exists('call_back_extension') && !empty($_SESSION['user']['extension'])) {
		foreach ($_SESSION['user']['extension'] as $field) {
			if (is_uuid($field['extension_uuid'])) {
				$user_extension_uuids[] = $field['extension_uuid'];
			}
		}
	}
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

//get the request and the extension to call from
	$sql = "select c.call_back_uuid, c.domain_uuid, c.call_back_destination, ";
	$sql .= " c.call_back_caller_id_name, c.call_back_caller_id_number, e.extension, e.user_context ";
	$sql .= "from v_call_back as c ";
	$sql .= "left join v_extensions as e on c.extension_uuid = e.extension_uuid ";
	$sql .= "where c.call_back_uuid = :call_back_uuid ";
	$sql .= "and (c.domain_uuid = :domain_uuid ".(permission_exists('call_back_domain') ? "or c.domain_uuid is null " : "").") ";
	$sql .= str_replace('extension_uuid', 'c.extension_uuid', $restrict);
	$parameters['call_back_uuid'] = $call_back_uuid;
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$row = $database->select($sql, $parameters, 'row');
	unset($sql, $parameters);

	if (empty($row) || empty($row['call_back_destination']) || empty($row['extension'])) {
		message::add($text['message-call'].' '.$text['label-false'], 'negative');
		header('Location: call_backs.php'.($query_string ? '?'.$query_string : ''));
		exit;
	}

//prepare the values
	$src = preg_replace('#[^0-9*#]#', '', $row['extension']);
	$dest = preg_replace('#[^0-9+*#]#', '', $row['call_back_destination']);
	$context = !empty($row['user_context']) ? $row['user_context'] : $_SESSION['domain_name'];
	$domain_name = $_SESSION['domains'][$_SESSION['domain_uuid']]['domain_name'] ?? $_SESSION['domain_name'];

//show the requester caller id on the extension as it rings the call back
	$dest_cid_name = $row['call_back_caller_id_name'];
	$dest_cid_number = $row['call_back_caller_id_number'];

//ringback setting
	$ringback = $settings->get('call_back', 'ringback', 'us-ring');
	switch ($ringback) {
		case "music": $ringback_value = "\'local_stream://moh\'"; break;
		case "uk-ring": $ringback_value = "\'%(400,200,400,450);%(400,2200,400,450)\'"; break;
		case "fr-ring": $ringback_value = "\'%(1500,3500,440.0,0.0)\'"; break;
		case "us-ring":
		default:
			$ringback = 'us-ring';
			$ringback_value = "\'%(2000,4000,440.0,480.0)\'";
	}

//originate the call: ring the extension, then bridge to the call back number
	$esl = event_socket::create();
	if ($esl->is_connected()) {

		//a-leg: the extension owner
		$source = "{call_back=true";
		$source .= ",origination_caller_id_name='".$dest_cid_name."'";
		$source .= ",origination_caller_id_number=".$dest_cid_number;
		$source .= ",instant_ringback=true";
		$source .= ",ringback=".$ringback_value;
		$source .= ",presence_id=".$src."@".$domain_name;
		$source .= ",domain_uuid=".$_SESSION['domain_uuid'];
		$source .= ",domain_name=".$domain_name."}user/".$src."@".$domain_name;

		//b-leg: dial the call back number through the dialplan (extension or outbound route)
		$switch_cmd = " &transfer('".$dest." XML ".$context."')";

		$switch_cmd = "originate ".$source.$switch_cmd;
		$result = trim(event_socket::api($switch_cmd));

		if (substr($result, 0, 3) == "+OK") {
			//mark the request as called
			$array['call_back'][0]['call_back_uuid'] = $call_back_uuid;
			$array['call_back'][0]['call_back_status'] = 'called';
			$p = permissions::new();
			$p->add('call_back_edit', 'temp');
			$database->save($array);
			$p->delete('call_back_edit', 'temp');
			unset($array);
			message::add($text['message-call'].' '.escape(format_phone($dest)));
		}
		else {
			message::add(escape($result), 'negative');
		}
	}
	else {
		message::add('Connection to Event Socket failed.', 'negative');
	}

//return to the list
	header('Location: call_backs.php'.($query_string ? '?'.$query_string : ''));
	exit;
