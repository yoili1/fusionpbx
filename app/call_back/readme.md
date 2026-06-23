# Call Back

An IVR that lets callers request a call back or leave a message, plus a per
extension portal where the requests are listed, can be called back, and removed.

## How it works

1. A caller reaches the call back IVR (see *Wiring* below).
2. The IVR offers two options:
   - **Press 1** to request a call back. The IVR reads back the caller id
     number and asks the caller to **press 1 to confirm** it, or **press 2 to
     enter a different number** (terminated with `#`).
   - **Press 2** to leave a message. The caller records a message after the
     beep.
3. The request is stored in `v_call_back` against the extension it was left
   for, with a status of `waiting`.
4. The request appears in **Apps → Call Back**. From there a user can:
   - **Call** the requester back (rings the extension, then dials the number).
     The request status is set to `called`.
   - **Play / download** a recorded message.
   - **Edit** the number to call, status (`waiting` / `called` / `completed`)
     and notes.
   - **Delete** the request, which also removes any recorded message file.

## Per extension

Each request is tied to an `extension_uuid`. Users who do **not** have the
`call_back_extension` permission only see and act on requests for the
extensions assigned to them (via `v_extension_users`). Admins with
`call_back_extension` see all requests in the domain, and `call_back_domain`
adds visibility of global requests across domains.

## Permissions

- `call_back_view` – view the portal (admin, superadmin, user)
- `call_back_edit` – edit a request and toggle it
- `call_back_delete` – delete a request
- `call_back_call` – call a requester back
- `call_back_extension` – see/act on requests for all extensions in the domain
- `call_back_domain` – see/act on global requests across domains

## Wiring the IVR

The application installs a feature code dialplan per domain on upgrade:

```
*732<extension>
```

Dialing `*732` followed by an extension routes the caller to the call back IVR
for that extension. For example, a caller who cannot reach extension `1001` can
dial `*7321001` to leave a call back request for it.

To trigger it automatically (for example on a no-answer condition), route the
call to the lua application and set the target extension first:

```xml
<action application="set" data="call_back_extension=1001"/>
<action application="lua" data="app.lua call_back"/>
```

The target extension can be provided three ways, in order of precedence:

1. the `call_back_extension` channel variable,
2. an argument: `lua app.lua call_back 1001`,
3. the dialed `destination_number`.

## Optional prompts

The IVR plays standard FreeSWITCH `ivr/*` prompts when present and falls back to
short tones otherwise. To use a custom greeting, record a file named
`call_back_greeting.wav` in the domain recordings directory
(`<recordings>/<domain>/call_back_greeting.wav`).

## Settings (Advanced → Default Settings → call_back)

- `ringback` – ringback played to the extension while connecting a call back
  (default `us-ring`).
- `message_max_length` – maximum message length in seconds (default `300`).
