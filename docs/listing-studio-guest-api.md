# Listing Studio guest API

The website server calls `/api/external/listing-studio` with a dedicated `X-Studio-Key`, a random 64-hex `X-Studio-Session`, and the visitor's trusted `X-Studio-IP`. These values never enter browser JavaScript. The website uses a signed, secure, HttpOnly, SameSite=Lax first-party session cookie and enforces same-origin mutation requests. Forward only a platform-trusted visitor IP, never arbitrary browser forwarding headers. All responses are `Cache-Control: no-store`.

Services are `staging`, `decluttering`, `grass`, `twilight`. JSON successes use `{success:true,data:...}`. Errors use `{message,code,request_id}` with normal HTTP status codes.

- `GET /capabilities`: data `{enabled,services:[{id,label,ready,reason,available}],turnstileSiteKey,limits:{maxFileBytes,globalDaily,ipDaily},stagingOptions:{rooms,styles},allowances:{staging,decluttering,grass,twilight},activeTrialId}`. Allowances are booleans. Staging option objects map supported IDs to labels. A missing challenge configuration disables submission.
- `GET /trials`: data `{trials:[Trial],allowances:{...},activeTrialId}`. Resume the latest active/completed trial after refresh.
- `POST /trials`: multipart `image`, `service`, `challengeToken`, optional staging `roomType` and `style`; required `Idempotency-Key` (8–64 letters/digits/hyphen/underscore). Turnstile action is `listing_studio_trial`. Returns 202 with data `Trial`. Repeating the exact key/image/service/options returns the existing trial; a changed payload is 409 `idempotency_conflict`. Only JPEG/PNG/WebP, at most 10 MiB and 40 megapixels.
- `GET /trials/{id}`: data `Trial`.
- `GET /trials/{id}/preview`: private, resized, watermarked JPEG bytes. Never forward original/provider URLs. The website proxies these bytes at its own authenticated guest URL.
- `GET /trials/{id}/original-preview`: the same session-bound, resized, watermarked representation of the original; lets a refresh restore the comparison without persisting original bytes in browser storage.
- `POST /trials/{id}/claim-token`: data `{token,expiresAt,claimUrl}`. The claim URL is `https://reprodashboard.com/listing-studio/claim#token=<opaque-token>`; never place the token in a query string or logs. It lasts 30 minutes.

`Trial` is `{id,service,status,progress,previewAvailable,originalPreviewAvailable,downloadAvailable,createdAt,completedAt,expiresAt,error,errorCode}`. Status is `queued|processing|completed|failed|needs_attention|expired`. Progress is a real provider percentage or null. Error is a sanitized string or null and errorCode is a stable string or null; no private paths/provider identifiers are returned. The browser builds its own same-origin preview URL when the respective availability field is true. Full results are never available through the guest bridge.

Dashboard endpoints retain Sanctum, active-account and client checks, but the three named routes below do not require email verification:

- `POST /api/listing-studio/trials/claim` with `{token}`: atomically links the guest session's jobs to the signed-in client. Data `{trials:[Trial]}`. A same-client replay succeeds. Different-client ownership gives 409 `claim_conflict`; expired token gives 410 `claim_expired`; previously consumed client service allowance gives 409 `trial_allowance_used`.
- `GET /api/listing-studio/trials`: data `{trials:[Trial]}` for the authenticated client, allowing refresh after the claim token is consumed.
- `GET /api/listing-studio/trials/{id}/download`: authenticated owner-only binary attachment. Use a bearer-authenticated fetch and local blob URL, never put an auth token in a URL.

One successful free result per service and guest/client; one active job per session. Global 100 and IP 12 starts per rolling 24 hours include failed starts. Confirmed failures release the service allowance; ambiguous submissions remain reserved. Unclaimed media expires after seven days. Existing team-delivered free edits and paid credits are separate.

Operational configuration is in `config/listing-studio-trials.php`: trials default disabled, bridge key must be at least 32 characters, Turnstile secret/public key and exact allowed hostnames must all be configured. `listing-studio:prune-guest-trials` runs hourly to reconcile interrupted bookkeeping and expire unclaimed media. `listing-studio:resume-trial {id}` only resumes an existing confirmed provider operation; it never creates a new editing job. Never automatically retry `needs_attention` with a new idempotency key.
