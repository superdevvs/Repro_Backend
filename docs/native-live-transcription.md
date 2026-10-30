# Consent-based live transcription

Human calls do not start capture automatically. After the staff member confirms caller consent, send:

`POST /api/voice/mobile/sessions/{session}/calls/{sdk}/transcription`

```json
{"action":"start","consented":true,"idempotency_key":"<new UUID>"}
```

Stop uses `{"action":"stop","idempotency_key":"<new UUID>"}`. Reuse the same UUID when retrying an unconfirmed operation. A UUID cannot change action, canonical call or customer leg. An unresolved start must be retried with its original key or explicitly stopped; a new start is blocked until an uncertain stop is confirmed. A stale start cannot restart capture after a later stop.

Persist each operation UUID until its result is confirmed. If the app loses that local state, fetch current call state first. For an unresolved capture, send an explicit stop with a fresh UUID and retain/retry that stop until state is `off`. Only then start a new capture with a new UUID and a fresh explicit consent confirmation. Do not recover by repeatedly sending new start UUIDs. Ended calls cannot start or stop capture through these controls; natural carrier hangup terminates media.

The response and `GET .../calls/{sdk}/state` retain `voice_call_id` and the existing call `state`, and include:

```json
{"transcription":{"state":"active","available":true,"enabled":true,"consent_given":true,"error":null}}
```

Capture states are `off`, `starting`, `active`, `stopping`, `error` and `ended`. `available` means the current owner can operate the line, the requesting native session still owns the exact joined SDK call, both human legs are connected, and Call Control configuration is present. It is false after hangup or replacement of that phone session. `enabled` reflects confirmed provider start and is false after the call ends. Provider acceptance does not prove that a speech segment has arrived. The only exposed error codes are `start_unconfirmed` and `stop_unconfirmed`; a failed provider operation returns503, after which state can be polled and the same operation retried.

This control sends only `transcription_start`/`transcription_stop` to the canonical call's customer leg. It does not create an audio recording, change recording consent, or stop a separately running recording. Separately started audio recording cannot undo an explicit transcript stop, and stopping audio recording preserves independently consented transcription. Consent withdrawal immediately excludes further transcript chunks, including when the provider stop is unconfirmed. Natural hangup allows genuine delayed final chunks for capture that retained consent; it never restarts media.

Browser owners may use `POST /api/voice/calls/{voice_call_id}/transcription` with the same request body. Browser responses include the shared call-control state with the same `transcription` object. Existing view, operate, line, owner and agent authorization remain required.

Read actual text from the canonical numeric call's `/api/voice/calls/{voice_call_id}/transcript` or `/stream`. REST has flat `transcript`, `display_transcript`, `state` and `segment_count`; SSE `transcript` events have `{seq,chunks:[{seq,text,speaker,ts,telnyx_confidence}]}`. Human speakers are `agent` and `customer`. Do not substitute an SDK UUID, another call's AI transcript or phone-number matching. The native claim response already supplies the correct numeric call ID.
