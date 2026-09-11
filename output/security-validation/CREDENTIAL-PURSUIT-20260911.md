# Credential pursuit — parked #3 — 2026-09-11

Status-only evidence. No secret values, tokens, passwords, APP_KEY material, or `.env` contents are recorded here. Quarantine archives remain closed. APP_KEY was not rotated.

Observed (UTC): production `php artisan security:credential-inventory` at **2026-09-11T04:33:22Z** (10:03 IST). Local Live working presence scan and packaging confirmation on Salienware the same morning.

## Method

1. Production (SSH `repro-deploy`, `/var/www/backend`): read-only `php artisan security:credential-inventory` (presence/consumers/row counts only).
2. Production independent presence booleans for Dropbox / mail flags (key presence and non-secret literals only; values never printed).
3. Local Live working: key-name inventory from `.env.example` + config consumers; local `.env` presence booleans only. Local `php artisan` blocked (PHP 8.4 CLI lacks `mbstring`).
4. Packaging: `Deploy-App.ps1` / `Test-ReleaseArchive.ps1` exclusion rules reviewed (names/patterns only).
5. Safe actions taken: documented evidence; cleared **3** non-secret local `storage/framework/cache/data` files; confirmed packaging exclusions; confirmed production `DROPBOX_ENABLED=false`. Did **not** mutate CakeMail, Dropbox provider grants, Google secrets, AWS/R2 keys, or APP_KEY. Did **not** change local `.env` Dropbox drift (reported only).

## Production inventory snapshot (booleans / counts)

| Item | Observation |
| --- | --- |
| environment | production |
| database_driver | sqlite |
| cache_store | database |
| media dual_write / read_from_r2 / r2_only | false / false / false |
| oauth_tokens rows | 0 |
| google_calendar_connections rows | 2 |
| user_tax_documents rows | 0 |
| users_with_mfa_secret | 0 |
| app_key_cutover | deferred_pending_verified_recovery |
| archive_values_compared | false |
| DROPBOX_ENABLED (env literal) | false |
| DROPBOX_CLIENT_ID/SECRET/ACCESS/REFRESH | ABSENT |
| config/dropbox.php | absent |
| mail.default | cakemail (name present; custom mailer key not listed among stock mailers from config dump — treat as CakeMail-path + SMTP dual surface) |
| smtp host/username/password configured | true / true / true |
| CONFIG_CACHE | present |

Artisan `credentials.*.configured` (production):

| Key name | configured | consumer path | provider_revocation_verified |
| --- | --- | --- | --- |
| APP_KEY | true | app.key | false |
| AWS_ACCESS_KEY_ID | false | filesystems.disks.s3.key | false |
| AWS_SECRET_ACCESS_KEY | false | filesystems.disks.s3.secret | false |
| R2_ACCESS_KEY_ID | false | filesystems.disks.media.key | false |
| R2_SECRET_ACCESS_KEY | false | filesystems.disks.media.secret | false |
| REDIS_PASSWORD | false (artisan filled(config); `.env` key token present — likely null-ish literal; cache uses database) | database.redis.default.password | false |
| MAIL_PASSWORD | true | mail.mailers.smtp.password | false |
| CAKEMAIL_USERNAME | true | services.cakemail.username | false |
| CAKEMAIL_PASSWORD | true | services.cakemail.password | false |
| CAKEMAIL_WEBHOOK_SECRET | false | services.cakemail.webhook_secret | false |
| GOOGLE_CLIENT_SECRET | true (via services.google; calendar secret fallback) | services.google.client_secret | false |
| DROPBOX_* | false (command hardcodes retired; independent env check also ABSENT) | retired_integration_historical_inventory_only | false |

## Local Live working notes (not production)

| Observation | Status |
| --- | --- |
| Dropbox integration PHP/config surfaces | absent (retirement code present) |
| DROPBOX_ENABLED | **true** (drift vs production false) |
| DROPBOX_CLIENT_ID / CLIENT_SECRET / ACCESS_TOKEN | SET locally (REFRESH ABSENT) — local hygiene drift; not provider revocation |
| AWS access/secret/bucket | EMPTY |
| R2 keys | ABSENT |
| CakeMail username/password | SET |
| Google Calendar client secret | SET |
| Fotello / MightyCall / weather key names | ABSENT from local `.env` |
| Local artisan inventory | blocked (mbstring missing on available Windows PHP) |

## Family status board

| Family | Runtime configured? | Consumers known? | Provider revocation evidence? | Status | Next action / blocker |
| --- | --- | --- | --- | --- | --- |
| AWS S3 | No (prod) | Config consumer paths known (`filesystems.disks.s3`); no active runtime keys | No | **OPEN** | Owner: AWS console/IAM — verify historical grants; revoke if active. Do not mint unused replacements. |
| Cloudflare R2 | No (prod); media flags all off | Config consumer paths known (`filesystems.disks.media` / media flags) | No | **OPEN** | Owner: Cloudflare — confirm bucket consumers before revoking any archive-era tokens. |
| Redis | Artisan unconfigured; cache=database | Historical instance unverified | No | **OPEN** | Owner: verify retired Redis instance/grant before closure. |
| SMTP | Yes (prod MAIL_PASSWORD + smtp host/username) | App default mail name `cakemail`; stock `smtp` mailer also present with host/user/password — **shared/external consumers not fully mapped** | No | **OPEN** | Map every SMTP consumer (app, workers, legacy hosts, CakeMail SMTP if shared). Blocker for password changes. |
| CakeMail | Yes (username/password) | REST consumer known (`services.cakemail`); readiness previously PASS; SMTP may share password | No old-grant revocation evidence; OpenAPI has no revoke/logout | **OPEN** (expected) | **Do not change password** until (1) shared SMTP consumer list pinned and (2) provider evidence that old refresh/access grants die after password change (or support procedure). Gap remains. |
| Dropbox (historical) | Dashboard retired; prod enabled=false; keys ABSENT | App consumers removed; oauth_tokens=0 | **No** provider-side revocation | **OPEN** | Owner: Dropbox app console — list apps/grants; revoke historical app secret/tokens. Local Live `.env` still has Dropbox keys ENABLED — hygiene cleanup (non-prod) still open. Local absence ≠ provider revocation. |
| Google | Yes (calendar client secret via services.google); 2 calendar connection rows | Dashboard Google Calendar consumers known | No | **OPEN** | Owner: Google Cloud admin — map shared OAuth clients, rotate secret with overlap, verify refresh, retire old secret. |
| Fotello / MightyCall / weather | No active env keys observed | Archive/quarantine inventory only; no active integration asserted in this pass | No | **OPEN** | Owner: historical provider consoles — verify/revoke old grants; no replacement integration. |
| APP_KEY | Yes (in use) | Encrypted field cutover procedure documented in register | N/A (deferred) | **CLOSED for this pass / DEFERRED** | Untouched. Do not generate replacement. Follow deferred maintenance prerequisites (recovery rehearsal, etc.). |
| Packaging / quarantine containment | N/A | Deploy excludes `.env*` (except example via guard), keys/pems, sqlite, output/, quarantine*, nested archives | N/A | **CLOSED** (controls confirmed) | `Test-ReleaseArchive.ps1` rejects forbidden leaves; `Deploy-App.ps1` rsync excludes match register. |

## Safe actions completed this pass

- Fresh production inventory JSON captured (presence only).
- Production Dropbox disablement re-confirmed (`DROPBOX_ENABLED=false`, keys absent, no `config/dropbox.php`).
- Packaging exclusion rules confirmed.
- Cleared 3 local non-secret framework cache files under `storage/framework/cache/data`.
- APP_KEY left untouched (still SET).

## Explicitly not done (unsafe / blocked)

- CakeMail password change — blocked on shared SMTP consumer map + old refresh-grant revocation evidence.
- Dropbox provider app revocation — needs owner console.
- AWS / R2 / Google secret rotation — needs owner console / MFA.
- APP_KEY cutover — deferred by policy.
- Quarantine tarball inspection — closed; not unpacked.
- Local `.env` Dropbox credential wipe — reported as drift; not mutated in this pass (owner decision).

## OPEN blockers requiring Ayoub / CRO owner input

1. **CakeMail**: Confirm every API + SMTP consumer (including whether `MAIL_PASSWORD` / smtp.* is the same CakeMail account). Obtain provider evidence or support path that password change invalidates prior refresh grants (spec does not document revocation).
2. **Dropbox**: Provider console access to revoke historical app/secret/grants; decide local Live working `.env` Dropbox key cleanup.
3. **AWS S3 / Cloudflare R2 / Redis**: Console access to prove historical grants revoked or never issued.
4. **Google Cloud**: Project admin to rotate OAuth client secret with overlap for 2 calendar connections.
5. **Fotello / MightyCall / weather**: Identify historical owners and revoke archive-era grants if any remain.
6. **Optional**: Install/enable PHP `mbstring` on Salienware so local `security:credential-inventory` can run without SSH.

## References

- `output/security-validation/doc-quarantine-20260911/archive-credential-register.md`
- `output/security-validation/doc-quarantine-20260911/dropbox-retirement.md`
- Command: `app/Console/Commands/SecurityCredentialInventory.php`
