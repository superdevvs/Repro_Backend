# Archived credential remediation — audit #3

Quarantine remains closed. No archive values were compared, no archives deleted, and APP_KEY cutover is deferred. This register distinguishes configured consumers from provider verification: an absent runtime key is **not** proof an archived grant was revoked.

## Read-only production observation, 2026-09-06

The saved deployment SSH identity reached the configured production host. Runtime PHP is 8.3.6; database, queue and shared cache use the database/SQLite configuration. Expected API/frontend URLs matched. Only booleans and aggregate counts were returned.

| Credential family | Runtime observation | Owner / provider verification | Next action |
| --- | --- | --- | --- |
| AWS S3 | Access key and secret unconfigured | Provider owner and historical grant status unverified | Verify historical IAM grant; revoke if still active. Do not create unused replacement. |
| Cloudflare R2 | Media access key and secret unconfigured | Cloudflare owner and historical grant status unverified | Confirm all bucket consumers before revoking archive-era tokens. |
| Redis | Password unconfigured; cache uses database | Historical instance status unverified | Verify retired instance/grant before closure. |
| SMTP | Password configured; current application mail config defines only the log mailer | Provider identity and external/legacy consumers unverified | Identify the SMTP host/account and every consumer before changing a possibly shared password. No SMTP rotation performed. |
| CakeMail | Username/password configured; verified-TLS authentication and self-profile readiness passed | API password change is documented; old-grant revocation remains unverified | Complete the consumer and old-token revocation plan below before changing the password. Console access is not inherently required for self-password change. |
| Dropbox | App ID/secret configured; no environment access/refresh token; zero OAuth rows | Dropbox App Console access needed | Verify archived grants separately, rotate app secret with consumer coordination, revoke retired grants. |
| Google | Client secret configured; two Calendar connection rows | Google Cloud project administrator needed | Map shared OAuth consumers, replace secret with overlap, verify token refresh, retire old secret. |
| Fotello / MightyCall / weather | Archive inventory only; no active integration asserted | Historical provider owner needed | Verify and revoke remaining old grants; no replacement integration. |
| APP_KEY | Application encryption in use | Cutover deliberately deferred | Follow maintenance prerequisites below; do not generate a replacement now. |
| iGUIDE / CubiCasa webhook credentials | Callback hardening is paused under #8 | Deferred dependency | Do not alter callbacks or webhook settings in this pass. |
| Stripe publishable / Pusher public / Square application IDs | Public identifiers | No secret rotation required on that basis | Keep unchanged. |

Other exposed integration families in `output/QUARANTINE-SECRET-TYPES-PARKED-3.md` retain unknown provider status until their owner supplies provider evidence. Inventory names alone do not establish an active compromise.

`php artisan security:credential-inventory` produces a fresh presence/consumer report without secrets or provider requests. Provider identifiers, permissions and verified revocation dates must be recorded by the credential owner after console verification, never replaced by guessed status.

## Rotation transaction and evidence

For each active credential: identify every web/worker/backup/local consumer; create a least-privilege replacement with provider-supported overlap; update via protected server secret handling; reload cached configuration and workers; verify normal operations; revoke the prior credential; verify the old credential is rejected. Do not print either value, place it in command arguments/transcripts, or ship it in source. If the provider has no overlap, coordinate its short maintenance interval first. If validation fails before revocation, restore the still-valid old consumer configuration; after revocation, fix forward using the replacement.

No provider rotations have been performed in this implementation record yet. The user authorized rotations, but runtime credential presence alone does not verify provider-side issuance/revocation capability or all external consumers. Browser administration was unavailable (`Transport closed`); no AWS/Cloudflare/Dropbox administration connector is available in this task. These are operational access dependencies, not a request to expose secrets in chat. Provider-supported API rotation is being checked separately where runtime access may be sufficient.

## CakeMail readiness and supported rotation plan, 2026-09-06

The reviewed readiness probe used existing cached runtime configuration over verified TLS with redirects disabled. The production operator executed it through SSH and reported PASS: a 600-second access token, a refresh token present, one account, and an active self-profile matching the configured login. Only booleans, fixed capability labels and token lifetime were emitted. No token was cached, no email sent, and no password, account or grant changed. Authentication necessarily issued a token and may create provider authentication logs. The returned refresh token was discarded with the process, not claimed revoked.

The [official TypeScript SDK guide](https://dev.cakemail.com/en/tools/sdks/typescript) links CakeMail's [official OpenAPI specification](https://raw.githubusercontent.com/cakemail/cakemail-sdk/main/openapi.json), version 1.20.18 when reviewed. It documents `POST /token` with `expiration_seconds` of at least 600; the response's `expires_in` supplies the actual access lifetime. Refresh tokens are long-lived. `GET /users/self` returns the current profile, not documented role or mutation permissions. `PATCH /users/{user_id}` accepts `password: {current, new}`; `new` has minimum length 8, and the current password is needed unless the caller has an admin override. The reset-password endpoints instead send an email token. The specification exposes no revoke/logout/session endpoint and does not state that password changes invalidate existing access or refresh tokens. Do not infer revocation from password replacement or token expiry.

CakeMail's [SMTP reference](https://dev.cakemail.com/apis/guides/email-api-reference) specifies `smtp.cakemail.dev`, TLS port 465, and the CakeMail username/password. Therefore API and SMTP can share the same password, but this application's configured SMTP secret has not been established as that same account. Current `config/mail.php` defines only the log mailer and explicitly directs transactional mail through the CakeMail REST provider; `MAIL_PASSWORD` is not wired into that mailer configuration. This does not establish that external or older consumers are absent.

The remaining cutover sequence is:

1. Identify and pin the provider user/account privately; confirm every API and SMTP consumer, including other applications and workers. Establish access to the provider recovery mailbox/MFA before replacement. The successful self-read does not prove admin override permission.
2. Obtain provider evidence for invalidating **all** previously issued access and refresh grants after password change, including whether old refresh tokens rotate or remain reusable. If no supported revocation mechanism exists, agree a provider-owner/support procedure before mutation. A short new access lifetime does not constrain older refresh grants.
3. Deploy verified TLS for all CakeMail credential-bearing requests and validate trust on each consumer host. Prepare the replacement in protected secret handling and a coordinated short pause if the provider cannot overlap passwords. Omit optional password-strength fields to preserve existing provider policy.
4. Once the consumer/revocation steps are concrete, perform the documented self-user password change with the current password. Update every shared consumer, rebuild cached configuration and restart long-lived workers. The provider stores credentials on construction, and its access/refresh/accounts caches use `cakemail_token_` plus the username hash, so a password change alone does not invalidate them. Clear all three entries explicitly; local cache deletion is not provider revocation.
5. Verify new authentication and normal operations. Under the agreed provider revocation procedure, verify that the old password, a controlled pre-change access token and its refresh grant are rejected. Keep any test tokens solely in protected process memory and never log them. Record provider revocation evidence before closing #3; if old grants remain usable, the item remains open.

As of this record, authentication readiness is proven and API password replacement is supported by documentation. Shared SMTP consumers and reliable old-grant invalidation are the two unresolved operational dependencies. No ordinary CakeMail/SMTP credential rotation or provider revocation has been performed.

## Packaging and quarantine containment

The reviewed deployment workflow accepts an isolated, clean committed release checkout with `-RepositoryPath ... -PreparedRelease`; it never stages that checkout automatically. The archive guard rejects environment files except `.env.example`, private-key files, databases, nested archives, cached configuration and local output/quarantine directories. Git export rules provide an additional exclusion. Backend source backups exclude all `.env*` variants and cached config; their directory is mode0700. Runtime file permissions remain independent of backup protection.

Generic deployment no longer changes Stripe configuration: provider webhook setup requires explicit `-ConfigureStripeWebhook`. Preserve the existing APP_KEY, public storage and database during code replacement. Quarantine stays outside serving/build paths and is not processed by the release scanner.

## Deferred APP_KEY maintenance procedure

Inventory and strictly migrate Google Calendar access/refresh tokens, shared Dropbox `encrypted:v1:` values, settings `enc:` fields, MFA secrets and encrypted recovery arrays, and private tax-document notes (`user_tax_documents.notes`). Invalidate expiring encrypted MFA enrollment, Dropbox OAuth cache entries and generic upload-source OAuth state. Use explicit old/new encrypters, checkpointed row locks or a maintenance window, and a protected database backup with a rehearsed restore. Abort on any decryption failure: never use the settings helper that substitutes empty strings. Verify migrated values using the new key alone before cutover.

Existing MFA recovery hashes depend on APP_KEY: re-encrypting their array does not preserve their validity under a new key. Reissue codes through a verified recovery process. Invalidate outstanding OAuth state, old signed links/confirmations and encrypted sessions; explicitly handle dashboard bearer-token revocation because key rotation alone does not revoke them. Previous-key acceptance is temporary and must end on every running service. Keep any old-key rollback material protected offline. Do not perform this cutover while recovery remains unverified.

| Persisted field or state | Format / required handling |
| --- | --- |
| `google_calendar_connections.access_token`, `.refresh_token` | Laravel encrypted casts; strict old-key decrypt and new-key re-encrypt |
| Studio Dropbox `oauth_tokens.access_token`, `.refresh_token` | Preserve `encrypted:v1:` prefix; distinguish any legacy plaintext explicitly |
| JSON `settings.value` fields | Only the reviewed `enc:` fields: `apiKey`, `apiUser`, `api_key`, `api_secret`, `access_token`, `secret_key`, `featuredShootApiKey`, `externalBookingApiKey` |
| `users.two_factor_secret`, `.two_factor_recovery_codes` | Encrypted scalar/array; recovery-code HMACs additionally depend on the old APP_KEY |
| `user_tax_documents.notes` | Encrypted cast; private document bytes themselves are unaffected |
| Pending MFA setup, Dropbox OAuth, upload-source OAuth, encrypted cookies and signed links | Invalidate; do not migrate old browser authorization state into a new trust window |

The future maintenance procedure must be rehearsed before it is executable against production:

1. Verify an operator/account recovery route with a controlled test account and record the result. Until this succeeds, stop before generating or applying a production replacement key. The paused #7 work is not implicitly authorized by this procedure.
2. Inventory raw stored ciphertext and format counts without model accessors that hide decryption failures. Check every non-null encrypted field; any unknown format or failed decryption blocks cutover. Record only aggregate counts and restricted row/field checkpoints, never plaintext or key values.
3. Make a consistent database backup and matching private-storage backup with a manifest. Restore both into a separate, restricted environment with outbound network, workers and scheduled sends disabled. Check database integrity, record/file counts, private file checksums and strict old-key decryption. Record the backup identity and successful restore evidence; this rehearsal has not yet occurred.
4. During the eventual maintenance window, pause writes and workers, take a fresh consistent backup, and run the reviewed migration using explicit old and new encrypters. Preserve each field's scalar/array format and prefix. Compare old/new decrypted values in memory before committing each checkpoint; abort on any mismatch, never substitute an empty value.
5. Verify every migrated value with the new encrypter alone, with previous-key fallback disabled. Confirm all expected counts match. Resolve recovery-code reissue through the verified route before users are signed out; re-encrypting existing recovery-code hashes is insufficient.
6. Update protected runtime configuration on every web/worker host, rebuild configuration caches and restart workers. Invalidate the agreed sessions, bearer tokens, OAuth state and signed links. Verify controlled account recovery, MFA and integration token refresh before reopening writes.
7. If acceptance fails while writes remain paused, restore the matching database/private-storage backup and old configuration together, then verify recovery before reopening. After writes resume, do not restore an old database blindly; use a reconciled recovery or fix forward. End any temporary previous-key acceptance on every process and protect or retire rollback material according to retention policy.

No APP_KEY replacement, decryption migration, restore rehearsal or recovery-code reissue has been performed in this release.
