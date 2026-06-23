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

if ($domains_processed == 1) {

	//application uuid used as the dialplan app_uuid
		$call_back_app_uuid = "d1a751ac-d105-4685-8d0e-3e0645860414";

	//feature code prefix used to reach the call back ivr: *732 followed by the extension
		$feature_code = "*732";

	//get the list of domains
		$sql = "select domain_uuid, domain_name from v_domains ";
		$domains = $database->select($sql, null, 'all');
		unset($sql);

		$array = [];
		$x = 0;
		if (!empty($domains)) {
			foreach ($domains as $domain) {

				//skip if a call back dialplan already exists for this domain
					$sql = "select count(*) from v_dialplans ";
					$sql .= "where domain_uuid = :domain_uuid ";
					$sql .= "and app_uuid = :app_uuid ";
					$parameters['domain_uuid'] = $domain['domain_uuid'];
					$parameters['app_uuid'] = $call_back_app_uuid;
					$existing = $database->select($sql, $parameters, 'column');
					unset($sql, $parameters);
					if (!empty($existing)) {
						continue;
					}

				//build the feature code dialplan that routes to the call back ivr
					$dialplan_uuid = uuid();
					$dialplan_xml = "<extension name=\"call_back\" continue=\"false\" uuid=\"".$dialplan_uuid."\">\n";
					$dialplan_xml .= "	<condition field=\"destination_number\" expression=\"^\\".$feature_code."(\\d+)$\">\n";
					$dialplan_xml .= "		<action application=\"answer\" data=\"\"/>\n";
					$dialplan_xml .= "		<action application=\"set\" data=\"call_back_extension=$1\"/>\n";
					$dialplan_xml .= "		<action application=\"lua\" data=\"app.lua call_back\"/>\n";
					$dialplan_xml .= "	</condition>\n";
					$dialplan_xml .= "</extension>\n";

				//build the dialplan array
					$array['dialplans'][$x]["domain_uuid"] = $domain['domain_uuid'];
					$array['dialplans'][$x]["dialplan_uuid"] = $dialplan_uuid;
					$array['dialplans'][$x]["dialplan_name"] = "call_back";
					$array['dialplans'][$x]["dialplan_number"] = $feature_code;
					$array['dialplans'][$x]["dialplan_context"] = $domain['domain_name'];
					$array['dialplans'][$x]["dialplan_continue"] = "false";
					$array['dialplans'][$x]["dialplan_xml"] = $dialplan_xml;
					$array['dialplans'][$x]["dialplan_order"] = "332";
					$array['dialplans'][$x]["dialplan_enabled"] = "true";
					$array['dialplans'][$x]["dialplan_description"] = "Call back IVR. Dial ".$feature_code." followed by an extension to request a call back or leave a message.";
					$array['dialplans'][$x]["app_uuid"] = $call_back_app_uuid;
					$x++;
			}
		}

	//save the dialplans
		if (!empty($array)) {
			$p = permissions::new();
			$p->add("dialplan_add", "temp");
			$p->add("dialplan_edit", "temp");

			$database->save($array, false);
			unset($array);

			$p->delete("dialplan_add", "temp");
			$p->delete("dialplan_edit", "temp");
		}

}

?>
