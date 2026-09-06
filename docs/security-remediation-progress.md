# Security remediation progress

Work proceeds one finding at a time, with focused regression checks before advancing.

## Completed local step: #2 shoot access

Implementation and review complete locally. Assignment and sharing rules are centralized in `ShootAuthorizationSupport`, with coverage for details, listings/filter metadata, history, media/previews/archives, messages, issues, workflow, rescheduling, activity logs, tour analytics, scheduling and approval. Restricted actors bypass authorization-sensitive list caches. Per-service media, counts and hero images follow the same file permissions.

Final combined validation passed on 2026-09-06: **224 tests, 2,709 assertions**, with warnings and risky tests treated as failures. All 24 changed/new PHP files passed syntax validation. The suite uses PHP 8.4.25 and in-memory SQLite; the JUnit result is `output/security-validation/step-2-final.xml` from the workspace root. Tests cover five new security suites and existing listing/history, upload, media, workflow, reschedule, presenter, notes, iGUIDE, mutation and assignment behavior.

See `shoot-access-security.md` for the access matrix, compatibility changes and release checks. No deployment has occurred. No migration or credential rotation is needed for this step. The private-listing discovery projection omits media/contact data; existing portal cards use thumbnail/agent placeholders and retain their public-tour action.

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

## Completed local step: #5 Dropbox

Studio connection management is primary-admin-only and rejects impersonation. OAuth uses one-use browser-bound state, PKCE, initiating-token checks and connection versions. Tokens stay server-side with encrypted writes and legacy migration support; disconnect remains effective if provider revocation fails. Shared upload-source rebind/browse bypasses and generic Dropbox credential settings are closed. Public webhook POSTs require exact-body signatures. The frontend now uses the secure administrator flow and supports pending-revocation retry; legacy browser token setup/debug helpers are retired.

Validation: **346 backend tests / 3,474 assertions** passed, including the prior #2 suite and existing MMM regressions; **25 frontend tests** passed. TypeScript checking, the production frontend build and the frontend lint baseline gate passed. Syntax checks passed for all 18 PHP files in this step. The JUnit result is `output/security-validation/step-5-final.xml` from the workspace root. Existing report-only lint notices concern unrelated preview files; no baseline regression was introduced.

Nothing has been deployed, no credentials have been rotated, and migration/recovery commands have only run in isolated tests. See `dropbox-security.md` for cache/cookie/provider configuration, legacy encryption and failed-revocation recovery instructions.

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

- #8 provider webhooks: partial, unwired authentication/idempotency helpers and configuration entries exist. Controllers and routes remain unchanged.
- #6 tax documents: private storage, authorized downloads and metadata filtering pending.
- #10 errors/debug routes: safe error contract and production route cleanup pending.
- #7 recovery seeders: replacement recovery command and tested runbook pending.
- #9 authentication: assess existing throttle/MFA implementation, strengthen new-password policy, and prepare staged enforcement.
- #3 archive credentials: quarantine remains closed; credential inventory, revocation/rotation and APP_KEY migration runbooks pending. No credentials have been rotated.
- #4 MMM: existing deployed protection reported by the user; local return/punchout regressions pass in the #5 validation. No MMM behavior changed or live deployment verification performed.

The remaining paused #8 drafts are `app/Services/Webhooks/ProviderWebhookAuthenticator.php`, `app/Services/Webhooks/WebhookDeliveryGuard.php`, and provider webhook entries in `config/services.php`. These helpers are unwired and unvalidated; keep them out of the #2/#5 release. The earlier Dropbox drafts have now been completed and tested under #5.

## Workspace and validation notes

- No deployment or live account/provider mutation has been performed.
- Backend/frontend Git metadata is now available. Verified live bases: backend df2ede1370f38b9a31ecab1fb564fade6bf9971d (deployment log plus matching source hashes), frontend c4fbde3351ba0f75dba6c8a1de0c7d63f8693b1c (live release marker). Separate release checkouts exclude paused drafts.
- A portable PHP runtime was downloaded from the official Windows PHP release service and its published SHA-256 verified. It resides under `output/security-validation/php`; it is a local validation dependency, not a release artifact.
