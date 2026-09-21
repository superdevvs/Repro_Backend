# Browser calling integration

The browser phone uses short-lived Telnyx telephony credentials and server-controlled conference calls. The browser never chooses or dials a PSTN destination through the SDK. All authenticated routes below also require `voice-calls-view` through the parent voice route group.

## Configuration and release

- `TELNYX_WEBRTC_ENABLED` defaults to false.
- `TELNYX_WEBRTC_CREDENTIAL_CONNECTION_ID` selects the dedicated credential connection. It must allow internal SIP, SRTP and parked browser-origin calls, with no PSTN outbound voice profile.
- Existing `TELNYX_VOICE_CONNECTION_ID`, provider key, voice webhook URL and company source number remain the server dialing configuration.
- Run migration `2026_09_21_030000_create_voice_browser_tables.php` before enabling the browser connection.
- `voice-browser:reconcile` is scheduled every minute in `bootstrap/app.php`. Normal staff offers have a 25-second provider ring timeout; the scheduler recovers offers older than 45 seconds when a webhook is missing and revokes expired credentials. Presence must be refreshed at least once every 60 seconds to receive a new offer; stale active devices are reconciled after two minutes.
- Credential create/refresh/get/revoke can be smoke-tested without a customer call. Actual registration, two-way audio, incoming routing, takeover, coach privacy, transfer and media capture still require a controlled provider call after deployment.

## REST contract

All paths have prefix `/api/voice`. Session endpoints require operate **or** supervise permission, and authorize the session owner. Token responses have `Cache-Control: no-store, private`; safe session reads never return a token or SIP credentials. Effective credentials expire within one hour; refresh extends the same credential before minting another JWT.

| Endpoint | Input / result |
| --- | --- |
| `GET browser/config` | `enabled`, `ready`, `presence_verification: "provider"`, `blockers`, capabilities `human_outbound`, `receive_calls`, `takeover`, `monitor`, `whisper`, `barge` |
| `POST browser/sessions` | UUID `device_id`; returns safe session, JWT `token`, `registration_delay_ms` (5000 for a new credential) |
| `POST browser/sessions/{id}/token` | Same owner/device UUID; refreshes credential and returns new JWT |
| `GET browser/sessions/{id}` | `id`, `device_id`, `status`, `expires_at`, `registered`, `offers` |
| `POST browser/sessions/{id}/heartbeat` | Boolean `transport_connected`; returns safe session with provider-confirmed `registered`. Legacy `registered` input remains accepted as a transport hint. |
| `DELETE browser/sessions/{id}` | Disconnect owned legs and revoke registration |
| `POST calls/human` | Operate; `session_id`, E.164 `to`, optional company `from`, `contact_id`, `related_shoot_id`, `reason`, required `idempotency_key`; returns canonical VoiceCall |
| `GET calls/{id}/browser` | Browser state, owned leg identities, capabilities and capture state |
| `POST calls/{id}/takeover` | Operate; `session_id`, `idempotency_key` |
| `POST calls/{id}/supervise` | Supervise; `session_id`, `idempotency_key`, `mode` (`monitor`, `whisper`, `barge`) |
| `PATCH calls/{id}/supervise` | Supervise; `mode` |
| `DELETE calls/{id}/supervise` | End only the requesting supervisor leg |
| `POST calls/{id}/browser-actions` | Owner + operate; `action` (`mute`, `unmute`, `hold`, `resume`, `dtmf`, `end`, `transfer`, `recording_start`, `recording_stop`); `digits` for DTMF, E.164 `to` for transfer; optional `idempotency_key` |
| `PATCH calls/{id}/browser-consent` | Owner + operate; `consented`, optional `idempotency_key`; explicit consent does not itself start capture |

Offers contain `voice_call_id`, `agent_call_control_id`, `browser_call_control_id`, `role`, `mode`, `state`, `caller_name`, and `remote_phone`. The SDK must accept only a call-control identity present in its authenticated session offers. The receiving credential-connection identity may differ from the server-created SIP leg. The server binds that alias from signed provider events using `call_session_id`, or the server's opaque `X-Repro-Offer` header plus the expected device SIP destination when the receiving event races the original dial response. Conference commands always target the originating server leg.

Presence requires a connected browser transport plus Telnyx confirmation from `GET /sip_registration_status` for the session's server-owned telephony credential. Carrier checks time out after three seconds and are cached for at most ten seconds. Unknown or failed checks return unavailable. A disconnected transport clears availability immediately; a delayed check cannot override a newer disconnect or revive a revoked or expired session. The browser must use the authoritative returned `registered` flag and `status: "ready"` rather than interpreting raw SDK gateway-state values.

Browser state includes `voice_call_id`, `state`, `session_id`, both leg identities, `conference_id`, `role`, `mode`, `muted`, `held`, `error`, and capabilities `can_takeover`, `can_monitor`, `can_whisper`, `can_barge`, `can_control`, `can_end`, `can_transfer`, `can_record`. Capture state is `recording.{consent_given,active,stop_pending,transcription_active,transcription_pending}`. Display unconfirmed capture separately from revoked consent; allow retry stop. Retrying recording start reuses the recording/transcription generation and does not restart confirmed capture.

## Event and media guarantees

- A human outbound call dials staff first. The customer is dialed only after a signed staff conference-join event. Provider acceptance alone never marks a participant joined.
- AI-enabled inbound lines retain Robbie. AI-disabled lines offer the first eligible available browser staff device before configured staff fallback. Decline/timeout does not create an unsolicited AI callback.
- Takeover preserves the customer and running AI until staff is confirmed joined. The server then stops the AI segment and joins that same customer leg. A declined offer preserves AI; failure after AI stop retries durable commands or recovers to configured staff/current AI policy. AI segment completion never substitutes for a carrier hangup.
- Monitor is server-muted. Whisper targets only the staff originating leg; customer leg IDs are never whisper targets. Human monitor/coach/barge are implemented. Listening to Robbie-only calls is not available in this workspace yet; Telnyx AI compatibility with passive native supervision remains unverified.
- Customer conference departure during a transfer is not a carrier hangup. Transfer success is recorded only after the signed transfer event. End requests wait for real terminal events to classify the customer call.
- Recording and transcription use the customer leg and explicit caller consent. Supervisor audio is excluded from that leg's direct media capture. Transcript speakers are `customer` and `agent`; consented text feeds existing live/final intelligence. Recording saved events use existing consent-gated storage and fresh authenticated playback URL handling.
- Browser provider commands use a durable operation ledger and stable Telnyx command IDs. Ambiguous responses remain retryable/uncertain; this is not a claim of universal exactly-once carrier delivery.

## Verification

Focused feature tests cover registration expiry/owner isolation, permission splits, staff-before-customer ordering, receiving-leg correlation, unsolicited origin rejection, duplicate/late events, takeover preservation and retry, disabled-AI recovery, staff-only whisper, capture retries, transfer departure and stale offers. Existing voice, number routing, scheduled-callback and permission suites are also regression-tested. These automated tests fake the provider transport; successful tests do not prove real browser audio or carrier event timing.

Provider references: [WebRTC architecture](https://developers.telnyx.com/development/webrtc/architecture), [telephony credentials](https://developers.telnyx.com/docs/development/webrtc/auth/telephony-credentials), [JWT authentication](https://developers.telnyx.com/docs/voice/webrtc/auth/jwt), [conference joins and supervisor roles](https://developers.telnyx.com/api-reference/conference-commands/join-a-conference), [conference participant updates](https://developers.telnyx.com/api-reference/conference-commands/update-conference-participant), [SIP header propagation](https://support.telnyx.com/en/articles/16666680-custom-sip-x-header-propagation-on-telnyx).
