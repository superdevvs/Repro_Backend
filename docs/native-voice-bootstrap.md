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

## Credential lifetime and recovery

The dedicated SIP connection remains bound to the same phone, account, and
Sanctum login. Its Telnyx telephony credential lasts up to the receiving
registration deadline (at most 30 days and never beyond the login deadline).
The API `expires_at` is a refresh lease of at most one hour, also bounded by
login and provider credential deadlines. The SDK token itself is issued by
Telnyx using `POST /telephony_credentials/{id}/token`, whose signed JWT lifetime
is 24 hours. A one-hour refresh lease does not shorten that signed lifetime.
Credential expiry/deletion still limits token use; logout/revocation cleans up
the credential. The server does not fetch or persist signing material.

Native acceptance on SDK4.2.2 found that the provider-issued token reached Ready
while the locally signed token was rejected with signaling error -32001 on the
same isolated credential. Therefore local signing is not used for bootstrap.

Telnyx terminal `expired` credentials cannot be revived with an expiry PATCH.
Authenticated foreground bootstrap replaces only that expired credential on
the existing dedicated connection, retaining session/login ownership and push
binding. Active call legs, pending call tickets, and live incoming offers block
replacement. HTTP errors or mismatched provider identity do not trigger rotation.

A durable nonsecret `credential_rotation_from` marker and deterministic name
precede the create request. An unknown outcome is reconciled using the exact
name and `filter[resource_id]=connection:<id>`; retries never blindly create a
second identity. Logout wins over adoption, and revocation cleanup also finds
and deletes an accepted-but-unadopted replacement. Its nonsecret
`credential_cleanup_id` is recorded before deletion, so an unknown DELETE
outcome retries the same ID and safely accepts a provider 404.

Background incoming preparation can extend an active identity within its
existing registration, but cannot replace a terminally expired one: the new
identity has not registered the phone's PushKit token with the SDK. That case
requires opening R/E Pro to reconnect. A successful bootstrap alone is not proof
of an SDK connection, hardware audio, or locked-screen incoming delivery.

### Live acceptance after deployment

1. Verify the exact release, migration and enabled production capabilities.
2. Let the existing authenticated phone reconnect; do not bind a new QA login
   token to its existing device. Observe the same session and dedicated
   connection, a single replacement credential, and HTTP 200 instead of 503.
3. Check only sanitized metadata: provider credential is active and registration
   bounded; provider token is returned unchanged and the API refresh lease is at most one hour;
   no secret material in DTO/storage/logs; SDK reports ready and SIP registration
   belongs to the replacement identity.
4. Perform authorized controlled audio and locked-screen PushKit acceptance
   separately. Do not infer those outcomes from credential recovery.

References: [Telnyx credential lifecycle](https://developers.telnyx.com/docs/voice/webrtc/auth/telephony-credentials/index),
[JWT minting](https://developers.telnyx.com/docs/voice/webrtc/auth/jwt/index),
[official Ruby signer](https://github.com/team-telnyx/telnyx-ruby/blob/master/lib/telnyx/lib/access_token.rb).
