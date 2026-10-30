# Native voice bootstrap push-token updates

`POST /api/voice/mobile/sessions` requires `device_id` (UUID), `platform: "ios"`,
and `apns_environment` (`debug` or `production`). The authenticated login owns
the device registration; a different login or environment cannot silently reuse it.

The optional `push_token` field has three meanings:

| Request field | Incoming-call registration |
| --- | --- |
| Omitted | Preserve the existing encrypted token and hash for this registration. A new registration remains tokenless. |
| Nonempty valid hexadecimal token | Set or replace the token, retaining the existing duplicate-token binding guard. |
| Explicit `null` or empty string | Clear the token and hash. Later refreshes that omit the field leave it cleared. |

`receive_calls` reflects the effective token binding after the update. It is not
an SDK socket-connected status. Tokenless bootstrap can still return
`make_calls: true` for outgoing calling setup. These are response fields, not
request flags for disabling calls.

`DELETE /api/voice/mobile/sessions/{session}` continues to revoke the whole
registration. Login revocation, permission checks, and lease bounds are unchanged.

Client compatibility: servers before this safeguard clear the binding when the
field is omitted. Clients must continue sending their known token on refresh
until this contract is deployed; intentional token removal must be explicit.
