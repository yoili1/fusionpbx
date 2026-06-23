--	call_back/index.lua
--	Part of FusionPBX
--	Copyright (C) 2026 Mark J Crane <markjcrane@fusionpbx.com>
--	All rights reserved.
--
--	Redistribution and use in source and binary forms, with or without
--	modification, are permitted provided that the following conditions are met:
--
--	1. Redistributions of source code must retain the above copyright notice,
--	this list of conditions and the following disclaimer.
--
--	2. Redistributions in binary form must reproduce the above copyright
--	notice, this list of conditions and the following disclaimer in the
--	documentation and/or other materials provided with the distribution.
--
--	THIS SOFTWARE IS PROVIDED AS ''IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
--	INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
--	AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
--	AUTHOR BE LIABLE FOR ANY DAMAGES ARISING IN ANY WAY OUT OF THE USE OF THIS
--	SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

--	The call back IVR lets a caller either request a call back (press 1) or
--	leave a message (press 2). The caller confirms the number to call back or
--	enters a different one. The request is stored in v_call_back and shown in
--	the per extension call back portal.

--set defaults
	max_tries = 3;
	digit_timeout = 5000;

--includes
	require "resources.functions.config";
	local Database = require "resources.functions.database";
	local Settings = require "resources.functions.lazy_settings";
	require "resources.functions.mkdir";
	require "resources.functions.trim";

--prepare the api object
	api = freeswitch.API();

--get the session variables
	domain_uuid = session:getVariable("domain_uuid");
	domain_name = session:getVariable("domain_name");
	caller_id_name = session:getVariable("caller_id_name") or session:getVariable("effective_caller_id_name") or "";
	caller_id_number = session:getVariable("caller_id_number") or "";
	sounds_dir = session:getVariable("sounds_dir");
	recordings_dir = session:getVariable("recordings_dir");
	default_language = session:getVariable("default_language") or "en";
	default_dialect = session:getVariable("default_dialect") or "us";
	default_voice = session:getVariable("default_voice") or "callie";

--determine the record extension
	record_ext = session:getVariable("record_ext");
	if (record_ext == nil) then
		record_ext = "wav";
	end

--determine the target extension the request is for
--	(set call_back_extension in the dialplan, pass it as an argument, or
--	 fall back to the dialed destination number)
	target_extension = session:getVariable("call_back_extension");
	if (target_extension == nil and argv ~= nil and argv[2] ~= nil) then
		target_extension = argv[2];
	end
	if (target_extension == nil) then
		target_extension = session:getVariable("destination_number");
	end

--connect to the database
	dbh = Database.new('system');
	local settings = Settings.new(dbh, domain_name, domain_uuid);

--get the settings
	message_max_length = settings:get('call_back', 'message_max_length', 'numeric');
	if (message_max_length == nil or tonumber(message_max_length) == nil) then
		message_max_length = 300;
	else
		message_max_length = tonumber(message_max_length);
	end

--build the path to the standard ivr sound files
	ivr_dir = sounds_dir.."/"..default_language.."/"..default_dialect.."/"..default_voice.."/ivr";

--define a helper to play a sound file only when it exists
	function play_if_exists(file)
		local f = io.open(file, "r");
		if (f ~= nil) then
			f:close();
			session:streamFile(file);
			return true;
		end
		return false;
	end

--define a helper to play a short beep
	function beep()
		session:execute("playback", "tone_stream://%(200,0,640)");
	end

--look up the extension uuid for the target extension
	extension_uuid = nil;
	if (target_extension ~= nil and domain_uuid ~= nil) then
		local sql = "SELECT extension_uuid FROM v_extensions ";
		sql = sql .. "WHERE domain_uuid = :domain_uuid ";
		sql = sql .. "AND (extension = :extension OR number_alias = :extension) ";
		local params = {domain_uuid = domain_uuid, extension = tostring(target_extension)};
		dbh:query(sql, params, function(row)
			extension_uuid = row.extension_uuid;
		end);
	end

--answer the call
	session:answer();
	session:sleep(500);

--present the menu: 1 = call back, 2 = leave a message
	option = nil;
	if (session:ready()) then
		--play an optional custom greeting if the administrator recorded one
		local greeting = recordings_dir.."/"..domain_name.."/call_back_greeting."..record_ext;
		if (not play_if_exists(greeting)) then
			--otherwise fall back to standard prompts then a beep
			play_if_exists(ivr_dir.."/ivr-welcome.wav");
			beep();
		end

		--collect the menu selection
		option = session:playAndGetDigits(1, 1, max_tries, digit_timeout, "#", "silence_stream://250", "", "[12]");
	end

--default the call back number to the caller id number
	call_back_destination = caller_id_number;

--process the selection
	request_type = nil;
	recording_path = nil;
	recording_name = nil;

	if (session:ready() and option == "1") then
		--request a call back
		request_type = "call_back";

		--read back the caller id number and let the caller confirm or change it
		confirmed = false;
		tries = 0;
		while (session:ready() and not confirmed and tries < max_tries) do
			tries = tries + 1;

			--read back the current number digit by digit
			if (call_back_destination ~= nil and #call_back_destination > 0) then
				play_if_exists(ivr_dir.."/ivr-you_entered.wav");
				session:execute("say", default_language.." number iterated "..call_back_destination);
			end

			--press 1 to confirm, 2 to enter a different number
			beep();
			local confirm = session:playAndGetDigits(1, 1, 1, digit_timeout, "#", "silence_stream://250", "", "[12]");
			if (confirm == "1") then
				confirmed = true;
			elseif (confirm == "2") then
				--collect a new number, terminated by #
				beep();
				local entered = session:playAndGetDigits(1, 20, 1, 10000, "#", "silence_stream://250", "", "%d+");
				if (entered ~= nil and #entered > 0) then
					call_back_destination = entered;
				end
			end
		end

		--play a confirmation tone
		if (session:ready()) then
			play_if_exists(ivr_dir.."/ivr-thank_you.wav");
		end

	elseif (session:ready() and option == "2") then
		--leave a message
		request_type = "message";

		--build the recording path: recordings_dir/<domain>/archive/YYYY/Mon/DD
		recording_path = recordings_dir.."/"..domain_name.."/archive/"..os.date("%Y").."/"..os.date("%b").."/"..os.date("%d");
		mkdir(recording_path);
		message_filename = api:execute("create_uuid").."."..record_ext;
		local recording_file = recording_path.."/"..message_filename;

		--prompt to record the message after the beep
		play_if_exists(ivr_dir.."/ivr-record_message.wav");
		beep();

		--record the message (file, max length, silence threshold, silence seconds)
		session:recordFile(recording_file, message_max_length, 30, 5);

		--store the name only if the file was created
		local f = io.open(recording_file, "r");
		if (f ~= nil) then
			f:close();
			recording_name = message_filename;
		else
			recording_path = nil;
		end

		if (session:ready()) then
			play_if_exists(ivr_dir.."/ivr-thank_you.wav");
		end
	end

--store the request when a valid option was chosen
	if (request_type ~= nil and domain_uuid ~= nil) then
		call_back_uuid = api:execute("create_uuid");

		--build the column and value lists, only including columns that have data
		local columns = {"call_back_uuid", "domain_uuid", "call_back_type", "call_back_status",
			"call_back_caller_id_name", "call_back_caller_id_number", "call_back_destination",
			"call_back_enabled", "insert_date"};
		local values = {":call_back_uuid", ":domain_uuid", ":call_back_type", "'waiting'",
			":caller_id_name", ":caller_id_number", ":call_back_destination",
			"'true'", "now()"};
		local params = {
			call_back_uuid = call_back_uuid,
			domain_uuid = domain_uuid,
			call_back_type = request_type,
			caller_id_name = caller_id_name,
			caller_id_number = caller_id_number,
			call_back_destination = call_back_destination or "",
		};

		if (extension_uuid ~= nil) then
			table.insert(columns, "extension_uuid");
			table.insert(values, ":extension_uuid");
			params.extension_uuid = extension_uuid;
		end
		if (recording_path ~= nil and recording_name ~= nil) then
			table.insert(columns, "call_back_recording_path");
			table.insert(columns, "call_back_recording_name");
			table.insert(values, ":call_back_recording_path");
			table.insert(values, ":call_back_recording_name");
			params.call_back_recording_path = recording_path;
			params.call_back_recording_name = recording_name;
		end

		local sql = "INSERT INTO v_call_back ("..table.concat(columns, ", ")..") ";
		sql = sql .. "VALUES ("..table.concat(values, ", ")..")";

		freeswitch.consoleLog("notice", "[call_back] storing "..request_type.." request for extension "..tostring(target_extension).."\n");
		dbh:query(sql, params);
	end

--hang up
	if (session:ready()) then
		session:hangup();
	end

--close the database connection
	dbh:release();
