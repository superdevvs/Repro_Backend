# Security remediation progress

Work proceeds one finding at a time, with focused regression checks before advancing.

## Released: #2 shoot access

Implementation and review complete locally. Assignment and sharing rules are centralized in `ShootAuthorizationSupport`, with coverage for details, listings/filter metadata, history, media/previews/archives, messages, issues, workflow, rescheduling, activity logs, tour analytics, scheduling and approval. Restricted actors bypass authorization-sensitive list caches. Per-service media, counts and hero images follow the same file permissions.

Final combined validation passed on 2026-09-06: **224 tests, 2,709 assertions**, with warnings and risky tests treated as failures. All 24 changed/new PHP files passed syntax validation. The suite uses PHP 8.4.25 and in-memory SQLite; the JUnit result is `output/security-validation/step-2-final.xml` from the workspace root. Tests cover five new security suites and existing listing/history, upload, media, workflow, reschedule, presenter, notes, iGUIDE, mutation and assignment behavior.

See `shoot-access-security.md` for the access matrix, compatibility changes and release checks. Backend release `76904ae6e6296e0fb28807a41cb383ccbc932ffb` is verified live. No migration or credential rotation is needed for this step. The private-listing discovery projection omits media/contact data; existing portal cards use thumbnail/agent placeholders and retain their public-tour action.

### Files for the #2 release review

Paths below are relative to `backend/`. Include the two documentation files with the review. The unrelated paused drafts listed below are excluded.

- `app/Services/Shoots/ShootAuthorizationSupport.php`
- `app/Services/Shoots/ShootPresenter.php`
- `app/Services/Shoots/ShootMediaReadService.php`
- `app/Services/Shoots/ShootIssueParsingService.php`
- `app/Services/Shoots/ShootListingService.php`
- `app/Services/Shoots/ShootHistoryService.php`
- `app/Services/Shoots/Actions/DownloadShootMediaZipAction.php`
- `app/Http/Controllers/PhotographerShootController.php`
- `app/Http/Controllers/API/ShootMessageController.php`
- `app/Http/Controllers/API/ShootIssuesController.php`
- `app/Http/Controllers/API/ShootRescheduleRequestController.php`
- `app/Http/Controllers/API/ShootWorkflowController.php`
- `app/Http/Controllers/API/ShootMediaController.php`
- `app/Http/Controllers/API/ImageDownloadController.php`
- `app/Http/Controllers/API/ImageProcessingController.php`
- `app/Http/Controllers/API/ShootController.php`
- `app/Http/Controllers/API/ShootNotesController.php`
- `app/Http/Controllers/API/TourAnalyticsController.php`
- `tests/Feature/ShootAccessSecurityTest.php`
- `tests/Feature/ShootAdjacentAccessSecurityTest.php`
- `tests/Feature/ShootListingAuthorizationTest.php`
- `tests/Feature/ShootHistoryAssignmentSecurityTest.php`
- `tests/Feature/ImageEndpointAuthorizationTest.php`
- `tests/Feature/ShootRescheduleRequestWorkflowTest.php`

## Released: #5 Dropbox

Studio connection management is primary-admin-only and rejects impersonation. OAuth uses one-use browser-bound state, PKCE, initiating-token checks and connection versions. Tokens stay server-side with encrypted writes and legacy migration support; disconnect remains effective if provider revocation fails. Shared upload-source rebind/browse bypasses and generic Dropbox credential settings are closed. Public webhook POSTs require exact-body signatures. The frontend now uses the secure administrator flow and supports pending-revocation retry; legacy browser token setup/debug helpers are retired.

Validation: **346 backend tests / 3,474 assertions** passed, including the prior #2 suite and existing MMM regressions; **25 frontend tests** passed. TypeScript checking, the production frontend build and the frontend lint baseline gate passed. Syntax checks passed for all 18 PHP files in this step. The JUnit result is `output/security-validation/step-5-final.xml` from the workspace root. Existing report-only lint notices concern unrelated preview files; no baseline regression was introduced.

Backend `76904ae6e6296e0fb28807a41cb383ccbc932ffb` and frontend `d5d482d9d9c51d0ace4add7640453bf239e03709` were deployed from isolated reviewed checkouts. Backend health is successful; signed-out Dropbox configuration returns 401, the legacy GET connect method returns 405, unsigned webhook POST returns 403, and signed-out photographer shoots returns 401. The ciphertext-compatible code was deployed before running the legacy encryption dry run and apply command: zero OAuth rows required migration. No provider credentials have been rotated or rebound. Real browser consent remains an acceptance dependency because the browser connection is unavailable. See `dropbox-security.md` for configuration and recovery instructions.

### Files for the #5 release review

Backend paths:

- `app/Models/DropboxStudioToken.php`
- `app/Services/DropboxTokenService.php`
- `app/Services/Dropbox/DropboxOAuthFlow.php`
- `app/Services/Dropbox/DropboxWebhookHandler.php`
- `app/Services/DropboxWorkflowService.php`
- `app/Services/UploadSourceService.php`
- `app/Http/Controllers/DropboxAuthController.php`
- `app/Http/Controllers/API/UploadSourceController.php`
- `app/Http/Controllers/API/IntegrationController.php`
- `app/Http/Controllers/API/SettingsController.php`
- `app/Console/Commands/EncryptDropboxStudioCredentials.php`
- `app/Console/Commands/AcknowledgeDropboxRevocation.php`
- `routes/api.php`
- `tests/Feature/DropboxOAuthSecurityTest.php`
- `tests/Feature/DropboxStudioCredentialSecurityTest.php`
- `tests/Feature/DropboxSettingsSecurityTest.php`
- `tests/Unit/DropboxWebhookHandlerTest.php`
- `tests/Unit/DropboxIntegrationTestConnectionSecurityTest.php`

Frontend paths:

- `src/services/studioDropbox.ts`
- `src/services/studioDropbox.test.ts`
- `src/components/integrations/StudioDropboxConnectionPanel.tsx`
- `src/components/integrations/StudioDropboxConnectionPanel.test.tsx`
- `src/pages/Integrations.tsx`
- `src/pages/IntegrationsSettings.tsx`
- `src/components/media/FileUploader.tsx`
- `src/components/DropboxCallback.tsx`
- Removed `src/components/media/useDropboxFilePicker.ts`.

Include `docs/dropbox-security.md` and this progress document in the review. Build artifacts and the portable PHP validation runtime are not source release files.

## Remaining steps

- #6 tax documents: backend `650563484eed6444008eab1ab5d68f1e7cb95a1f` and frontend `8a1d833c21f3606b6c99053ae08d9bbf771e5e99` are verified live. Initial focused checks passed 34 backend tests / 198 assertions, eight frontend tests, TypeScript and production build. The private-file permission correction additionally passed 12 Linux checks and CI. Read-only production acceptance passed 43 checks; migration inventory found zero legacy references, private documents or orphaned files. The permanent Nginx guard requires administrator sudo and remains an acceptance condition; a 404 for a nonexistent old URL alone does not close this finding.
- #10 errors/debug routes: backend `a7c686425b5b36dabd2f347cd2e866ef3e40caf1` and frontend `93807974017b98ed760be335ac63990b2502a2bb` are verified live. Both exact revisions passed full CI. The frontend quality run passed 1,333 tests plus TypeScript, lint, build, size budgets and dependency audit; all 269 generated JavaScript chunks have zero executable console calls. Read-only production acceptance passed 33 checks and actual HTTP acceptance passed ten checks, including retired routes, safe error contracts and authoritative request IDs. Earlier failed regressions were corrected before the passing release CI. Retained assets support older open tabs; the new logging policy applies after refresh.
- #9 authentication: backend `3ad628da4e833b70f744dc03c811137b86a11720` and frontend `318fd061106a97419c573da388a77a4502f80991` are verified live. Full corrected CI passed 3,172 backend tests / 87,940 assertions and 1,343 frontend tests, with lint, type/build/budget and dependency gates. Production read-only authentication checks passed 90 checks before and 90 after pilot activation. Actual loopback HTTP acceptance observed ten generic 401 failures then 429 despite forged proxy headers, followed by successful minute-window expiry; no real account was used. Dedicated hourly expired-counter cleanup is installed. The one authorized verification-template email was delivered and the user confirmed correct receipt/rendering. The pilot started `2026-09-06T18:48:48Z`, enforcing the agreed cohort from `2026-09-20T18:48:48Z` (21 September, 00:18:48 Asia/Kolkata). Existing unchanged accounts retain access. The follow-up candidate adds a dedicated restricted daily `auth-security` channel because production's global error-only threshold suppresses notices; rate enforcement itself is verified. Focused logging/security regression checks pass 26 tests / 331 assertions.
- #3 archive credentials: packaging exclusions and generated-archive path scanning are deployed. Read-only credential inventory, verified TLS for CakeMail requests and the strict APP_KEY migration/restore runbook are deployed with #10. Provider-side credential issuance, revocation capability and external consumers are being verified; provider ownership, external consumers and revocation semantics remain unresolved. CakeMail token issuance and identity checks passed without changing credentials or sending mail, but password-change revocation behavior and shared SMTP consumers are not yet established. No rotations have occurred; APP_KEY cutover remains deferred.
- #7 recovery seeders/shared passwords/account provisioning: paused by user decision; remains open and unchanged.
- #8 provider webhooks: paused by user decision; partial unwired helpers/config remain outside releases, and existing callback behavior is preserved.
- #4 MMM: existing live return protection is preserved; return/punchout regressions pass in the #2/#5 validation.
- #1 remains excluded.

The remaining paused #8 drafts are `app/Services/Webhooks/ProviderWebhookAuthenticator.php`, `app/Services/Webhooks/WebhookDeliveryGuard.php`, and provider webhook entries in `config/services.php`. These helpers are unwired and unvalidated; keep them out of the #2/#5 release. The earlier Dropbox drafts have now been completed and tested under #5.

## Workspace and validation notes

- No real account credentials, email addresses or MFA settings were changed, and no provider credentials or APP_KEY were rotated. Releases, no-op legacy migrations, the rollout timestamp and bounded synthetic login counters are recorded above. One authorized verification-template email was sent; its harmless link did not change the existing verified account.
- Original verified live bases were backend `df2ede1370f38b9a31ecab1fb564fade6bf9971d` and frontend `c4fbde3351ba0f75dba6c8a1de0c7d63f8693b1c`. Current release checkouts descend from the verified release markers; the main working directories retain their accumulated uncommitted work and paused drafts.
- The first backend deployment encountered runtime permissions from a restrictive backup umask. Runtime permissions were restored, the global umask was removed, backup directories alone were restricted, and the corrected release passed CI and live health checks. The packaging regression test guards this distinction.
- A portable PHP runtime was downloaded from the official Windows PHP release service and its published SHA-256 verified. It resides under `output/security-validation/php`; it is a local validation dependency, not a release artifact.
