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
	if (!permission_exists('call_back_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//get the request
	$call_back_uuid = is_uuid($_GET['id'] ?? '') ? $_GET['id'] : '';
	$type = ($_GET['type'] ?? 'play') == 'download' ? 'download' : 'play';
	if (!is_uuid($call_back_uuid)) {
		echo "access denied";
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

//get the recording details
	$sql = "select call_back_recording_path, call_back_recording_name from v_call_back ";
	$sql .= "where call_back_uuid = :call_back_uuid ";
	$sql .= "and (domain_uuid = :domain_uuid ".(permission_exists('call_back_domain') ? "or domain_uuid is null " : "").") ";
	$sql .= $restrict;
	$parameters['call_back_uuid'] = $call_back_uuid;
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$row = $database->select($sql, $parameters, 'row');
	unset($sql, $parameters);

	if (empty($row) || empty($row['call_back_recording_path']) || empty($row['call_back_recording_name'])) {
		echo "access denied";
		exit;
	}

//validate the file is a plain file name (no traversal) and exists
	$recording_name = basename($row['call_back_recording_name']);
	$recording_file = $row['call_back_recording_path'].'/'.$recording_name;
	if (!is_file($recording_file)) {
		echo "file not found";
		exit;
	}

//stream the audio bytes
	$file_ext = strtolower(pathinfo($recording_name, PATHINFO_EXTENSION));
	switch ($file_ext) {
		case "mp3": $content_type = "audio/mpeg"; break;
		case "ogg": $content_type = "audio/ogg"; break;
		case "wav":
		default: $content_type = "audio/x-wav"; break;
	}

	if ($type == 'download') {
		header("Content-Type: application/octet-stream");
		header('Content-Disposition: attachment; filename="'.$recording_name.'"');
	}
	else {
		header("Content-Type: ".$content_type);
		header('Content-Disposition: inline; filename="'.$recording_name.'"');
	}
	header("Content-Length: ".filesize($recording_file));
	header("Cache-Control: no-cache, must-revalidate");
	$fd = fopen($recording_file, "rb");
	if ($fd !== false) {
		fpassthru($fd);
		fclose($fd);
	}
	exit;
