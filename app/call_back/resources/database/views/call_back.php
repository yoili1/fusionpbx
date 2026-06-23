<?php

$view['name'] = "view_call_back";
$view['version'] = "20260623";
$view['description'] = "Show the call back requests with extension information.";

$view['sql']  = "SELECT \n";
$view['sql'] .= "    c.call_back_uuid, \n";
$view['sql'] .= "    c.domain_uuid, \n";
$view['sql'] .= "    c.extension_uuid, \n";
$view['sql'] .= "    e.extension, \n";
$view['sql'] .= "    e.number_alias, \n";
$view['sql'] .= "    e.effective_caller_id_name, \n";
$view['sql'] .= "    c.call_back_type, \n";
$view['sql'] .= "    c.call_back_status, \n";
$view['sql'] .= "    c.call_back_caller_id_name, \n";
$view['sql'] .= "    c.call_back_caller_id_number, \n";
$view['sql'] .= "    c.call_back_destination, \n";
$view['sql'] .= "    c.call_back_recording_path, \n";
$view['sql'] .= "    c.call_back_recording_name, \n";
$view['sql'] .= "    c.call_back_enabled, \n";
$view['sql'] .= "    c.call_back_description, \n";
$view['sql'] .= "    c.insert_date, \n";
$view['sql'] .= "    c.insert_user, \n";
$view['sql'] .= "    c.update_date, \n";
$view['sql'] .= "    c.update_user \n";
$view['sql'] .= "FROM v_call_back AS c \n";
$view['sql'] .= "LEFT JOIN v_extensions AS e \n";
$view['sql'] .= "    ON c.extension_uuid = e.extension_uuid;\n";

?>
