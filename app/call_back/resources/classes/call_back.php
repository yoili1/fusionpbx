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

/**
 * call back class
 */
class call_back {

	/**
	 * declare constant variables
	 */
	const app_name = 'call_back';
	const app_uuid = 'd1a751ac-d105-4685-8d0e-3e0645860414';

	/**
	 * @var database Database Object
	 */
	private $database;

	/**
	 * @var settings Settings Object
	 */
	private $settings;

	private $user_uuid;
	private $domain_uuid;
	private $domain_name;

	private $permission_prefix;
	private $list_page;
	private $table;
	private $uuid_prefix;
	private $toggle_field;
	private $toggle_values;

	/**
	 * Initializes the object with a settings array.
	 */
	public function __construct(array $setting_array = []) {
		//set domain and user UUIDs
		$this->domain_uuid = $setting_array['domain_uuid'] ?? $_SESSION['domain_uuid'] ?? '';
		$this->domain_name = $setting_array['domain_name'] ?? $_SESSION['domain_name'] ?? '';
		$this->user_uuid   = $setting_array['user_uuid'] ?? $_SESSION['user_uuid'] ?? '';

		//set objects
		$config         = $setting_array['config'] ?? config::load();
		$this->database = $setting_array['database'] ?? database::new(['config' => $config]);
		$this->settings = $setting_array['settings'] ?? new settings(['database' => $this->database, 'domain_uuid' => $this->domain_uuid, 'user_uuid' => $this->user_uuid]);

		//assign private variables
		$this->permission_prefix = 'call_back_';
		$this->list_page         = 'call_backs.php';
		$this->table             = 'call_back';
		$this->uuid_prefix       = 'call_back_';
		$this->toggle_field      = 'call_back_enabled';
		$this->toggle_values     = ['true', 'false'];
	}

	/**
	 * Returns the array of extension uuids the current user is assigned to.
	 */
	private function user_extension_uuids() {
		$extension_uuids = [];
		$sql = "select extension_uuid from v_extension_users ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and user_uuid = :user_uuid ";
		$parameters['domain_uuid'] = $this->domain_uuid;
		$parameters['user_uuid'] = $this->user_uuid;
		$rows = $this->database->select($sql, $parameters, 'all');
		if (is_array($rows)) {
			foreach ($rows as $row) {
				if (is_uuid($row['extension_uuid'])) {
					$extension_uuids[] = $row['extension_uuid'];
				}
			}
		}
		return $extension_uuids;
	}

	/**
	 * Builds the restrictive sql so a user can only act on requests for their
	 * own extensions unless they have the call_back_extension permission.
	 */
	private function extension_restriction(&$sql) {
		if (!permission_exists('call_back_extension')) {
			$extension_uuids = $this->user_extension_uuids();
			if (empty($extension_uuids)) {
				//no assigned extensions: match nothing
				$sql .= "and extension_uuid is null and extension_uuid is not null ";
			}
			else {
				$quoted = [];
				foreach ($extension_uuids as $extension_uuid) {
					$quoted[] = "'".$extension_uuid."'";
				}
				$sql .= "and extension_uuid in (".implode(', ', $quoted).") ";
			}
		}
	}

	/**
	 * Deletes one or more records and removes any recorded messages.
	 */
	public function delete($records) {
		if (permission_exists($this->permission_prefix . 'delete')) {

			//add multi-lingual support
			$language = new text;
			$text     = $language->get();

			//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: ' . $this->list_page);
				exit;
			}

			//delete multiple records
			if (is_array($records) && @sizeof($records) != 0) {

				//filter out unchecked
				$uuids = [];
				foreach ($records as $record) {
					if (!empty($record['checked']) && $record['checked'] == 'true' && is_uuid($record['uuid'])) {
						$uuids[] = "'" . $record['uuid'] . "'";
					}
				}

				//get the records the user is allowed to delete
				$rows = [];
				if (!empty($uuids)) {
					$sql = "select call_back_uuid, call_back_recording_path, call_back_recording_name from v_call_back ";
					$sql .= "where (domain_uuid = :domain_uuid ";
					if (permission_exists('call_back_domain')) {
						$sql .= "or domain_uuid is null ";
					}
					$sql .= ") ";
					$this->extension_restriction($sql);
					$sql .= "and call_back_uuid in (" . implode(', ', $uuids) . ") ";
					$parameters['domain_uuid'] = $this->domain_uuid;
					$rows = $this->database->select($sql, $parameters, 'all');
					unset($sql, $parameters);
				}

				//build the delete array and remove recordings
				$array = [];
				if (is_array($rows) && @sizeof($rows) != 0) {
					$x = 0;
					foreach ($rows as $row) {
						$array[$this->table][$x]['call_back_uuid'] = $row['call_back_uuid'];
						$x++;
						//remove the recorded message file if present
						if (!empty($row['call_back_recording_path']) && !empty($row['call_back_recording_name'])) {
							$file = $row['call_back_recording_path'] . '/' . $row['call_back_recording_name'];
							if (is_file($file)) {
								@unlink($file);
							}
						}
					}
				}

				//delete the checked rows
				if (!empty($array)) {
					$this->database->delete($array);
					unset($array);
					message::add($text['message-delete']);
				}
				unset($records);
			}
		}
	}

	/**
	 * Toggles the enabled (active/archived) state of one or more records.
	 */
	public function toggle($records) {
		if (permission_exists($this->permission_prefix . 'edit')) {

			//add multi-lingual support
			$language = new text;
			$text     = $language->get();

			//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: ' . $this->list_page);
				exit;
			}

			//toggle the checked records
			if (is_array($records) && @sizeof($records) != 0) {

				$uuids = [];
				foreach ($records as $record) {
					if (!empty($record['checked']) && $record['checked'] == 'true' && is_uuid($record['uuid'])) {
						$uuids[] = "'" . $record['uuid'] . "'";
					}
				}

				$states = [];
				if (!empty($uuids)) {
					$sql = "select call_back_uuid, " . $this->toggle_field . " as toggle from v_call_back ";
					$sql .= "where (domain_uuid = :domain_uuid ";
					if (permission_exists('call_back_domain')) {
						$sql .= "or domain_uuid is null ";
					}
					$sql .= ") ";
					$this->extension_restriction($sql);
					$sql .= "and call_back_uuid in (" . implode(', ', $uuids) . ") ";
					$parameters['domain_uuid'] = $this->domain_uuid;
					$rows = $this->database->select($sql, $parameters, 'all');
					if (is_array($rows)) {
						foreach ($rows as $row) {
							$states[$row['call_back_uuid']] = $row['toggle'];
						}
					}
					unset($sql, $parameters, $rows);
				}

				$array = [];
				$x = 0;
				foreach ($states as $uuid => $state) {
					$array[$this->table][$x]['call_back_uuid'] = $uuid;
					$array[$this->table][$x][$this->toggle_field] = $state == $this->toggle_values[0] ? $this->toggle_values[1] : $this->toggle_values[0];
					$x++;
				}

				if (!empty($array)) {
					$this->database->save($array);
					unset($array);
					message::add($text['message-toggle']);
				}
				unset($records, $states);
			}
		}
	}

} //class
