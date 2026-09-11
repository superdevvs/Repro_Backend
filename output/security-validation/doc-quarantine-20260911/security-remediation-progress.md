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

## Live retirement accepted: #5 Dropbox

The user-requested Dropbox retirement supersedes the earlier connection hardening. Backend `80672ae7cc5f0a846bcbab2c5a4c7ac8af184f6f` and frontend `c6f8f1c16d3e6062a533ec0e6ca617a79e4f0370` are verified live. Studio and personal OAuth, callbacks, webhook, browsing/import, connection controls and provider code are removed. Normal local media operations use `ShootMediaStorageService`; historical migrations/columns and safe queued-job adapters remain for compatibility. Example: the dashboard no longer offers Dropbox connection controls, and the old API endpoints return 404 while existing thumbnails still render.

The earlier hardened release had passed 346 backend tests / 3,474 assertions and 25 frontend tests. Its browser consent/reconnect instructions are historical and no longer apply; see `dropbox-security.md`. Zero studio OAuth rows needed the earlier encryption migration. No provider credentials were rebound or rotated.

Before removal, active Dropbox app configuration was cleared, synchronization disabled, configuration rebuilt and workers signaled to restart. The guarded operation changed no unrelated configuration and made no provider calls or media writes. Exact retirement CI passed: backend run `34082254659`, **3,089 tests / 87,542 assertions**; frontend run `34081885708`, **234 suites / 1,327 tests** plus all quality gates.

Production retired-media acceptance passed **19 checks**. The inventory fingerprint `217bd721a3b29249dc2970532bd864c20573258bd537a9adcaeabee050f951fa` exactly matches the disabled baseline. All 211 references in each thumbnail/grid/web/placeholder class, 88 watermarked originals and 264 watermarked previews were readable. Of 215 original references, 213 were readable and two were already missing before removal; 16 quarantined records were excluded. Bounded decoding and an isolated generated image checked actual image content and dimensions. All **14 retired endpoints returned 404 over actual HTTPS**. Browser checks confirmed raw/edited thumbnails and the large web viewer render, and Settings contains no Dropbox UI.

**#5 is closed for the application integration.** Initial browser testing found an existing stopped default worker whose permission failures preceded Dropbox disablement. After approving queued-message resumption, the user ran the administrator command to restore that worker. At 05:33:45 UTC on 7 September it had remained running for 355 seconds, the queue had zero pending/reserved jobs, the stuck archive job was consumed and no new failed jobs were recorded. Post-start logs showed no new relevant error classifications. Browser RAW, MLS and Print download flows passed; both edited ZIPs returned HTTPS 200 application/zip and their three entries exactly matched local inputs and order. The post-recovery media probe again passed 19 checks with the unchanged inventory fingerprint and all 211 thumbnails readable. The earlier isolated runner stopped before mutation because of the deployment account's directory permissions; normal execution under the restored web-worker account succeeded. Detailed evidence is in `dropbox-retirement.md` and the workspace acceptance record.

Historical Dropbox grants still require provider-side revocation evidence under #3. Retirement of the dashboard does not establish revocation. No reconnect or consent test is required for this retired integration.

## Remaining steps

- #6 tax documents: backend `650563484eed6444008eab1ab5d68f1e7cb95a1f` and frontend `8a1d833c21f3606b6c99053ae08d9bbf771e5e99` are verified live. Initial focused checks passed 34 backend tests / 198 assertions, eight frontend tests, TypeScript and production build. The private-file permission correction additionally passed 12 Linux checks and CI. Read-only production acceptance passed 43 checks; migration inventory found zero legacy references, private documents or orphaned files. The permanent Nginx guard requires administrator sudo and remains an acceptance condition; a 404 for a nonexistent old URL alone does not close this finding.
- #10 errors/debug routes: backend `a7c686425b5b36dabd2f347cd2e866ef3e40caf1` and frontend `93807974017b98ed760be335ac63990b2502a2bb` are verified live. Both exact revisions passed full CI. The frontend quality run passed 1,333 tests plus TypeScript, lint, build, size budgets and dependency audit; all 269 generated JavaScript chunks have zero executable console calls. Read-only production acceptance passed 33 checks and actual HTTP acceptance passed ten checks, including retired routes, safe error contracts and authoritative request IDs. Earlier failed regressions were corrected before the passing release CI. Retained assets support older open tabs; the new logging policy applies after refresh.
- #9 authentication: backend `2a410ac35ef9c1284696ea0648016f9d248de6a2` and frontend `318fd061106a97419c573da388a77a4502f80991` are verified live. Full corrected CI passed 3,173 backend tests / 88,134 assertions and 1,343 frontend tests, with lint, type/build/budget and dependency gates. Production read-only authentication checks passed 90 checks before and 90 after pilot activation. Actual loopback HTTP acceptance observed ten generic 401 failures then 429 despite forged proxy headers, followed by successful minute-window expiry; no real account was used. Dedicated hourly expired-counter cleanup is installed. The one authorized verification-template email was delivered and the user confirmed correct receipt/rendering. The pilot started `2026-09-06T18:48:48Z`, enforcing the agreed cohort from `2026-09-20T18:48:48Z` (21 September, 00:18:48 Asia/Kolkata). Existing unchanged accounts retain access. The dedicated restricted daily `auth-security` channel is deployed. Final production acceptance matched exactly one redacted throttle notice to the synthetic request, with 0660 permissions and 14-day retention; default logs remain error-only. Focused logging/security checks passed 26 tests / 331 assertions and final full CI passed 3,173 tests / 88,134 assertions.
- #3 archive credentials: packaging exclusions and generated-archive path scanning are deployed. Read-only credential inventory, verified TLS for CakeMail requests and the strict APP_KEY migration/restore runbook are deployed with #10. Provider-side credential issuance, revocation capability and external consumers are being verified; provider ownership, external consumers and revocation semantics remain unresolved. CakeMail token issuance and identity checks passed without changing credentials or sending mail, but password-change revocation behavior and shared SMTP consumers are not yet established. No rotations have occurred; APP_KEY cutover remains deferred.
- #7 recovery seeders/shared passwords/account provisioning: paused by user decision; remains open and unchanged.
- #8 provider webhooks: paused by user decision; partial unwired helpers/config remain outside releases, and existing callback behavior is preserved.
- #4 MMM: existing live return protection is preserved; return/punchout regressions pass in the #2/#5 validation.
- #1 remains excluded.

The remaining paused #8 drafts are `app/Services/Webhooks/ProviderWebhookAuthenticator.php`, `app/Services/Webhooks/WebhookDeliveryGuard.php`, and provider webhook entries in `config/services.php`. These helpers are unwired and unvalidated; they remain excluded from security releases, including Dropbox retirement. The earlier Dropbox hardening was superseded by the accepted live retirement described under #5.

## Workspace and validation notes

- No real account credentials, email addresses or MFA settings were changed, and no provider credentials or APP_KEY were rotated. Releases, no-op legacy migrations, the rollout timestamp and bounded synthetic login counters are recorded above. One authorized verification-template email was sent; its harmless link did not change the existing verified account.
- Original verified live bases were backend `df2ede1370f38b9a31ecab1fb564fade6bf9971d` and frontend `c4fbde3351ba0f75dba6c8a1de0c7d63f8693b1c`. Current release checkouts descend from the verified release markers. The reviewed retirement patches have been mirrored into the original backend/frontend workspaces without changing their HEADs; those workspaces retain accumulated uncommitted work and paused drafts, with empty staging indexes.
- The first backend deployment encountered runtime permissions from a restrictive backup umask. Runtime permissions were restored, the global umask was removed, backup directories alone were restricted, and the corrected release passed CI and live health checks. The packaging regression test guards this distinction.
- A portable PHP runtime was downloaded from the official Windows PHP release service and its published SHA-256 verified. It resides under `output/security-validation/php`; it is a local validation dependency, not a release artifact.

Historical logging release acceptance on 7 September 2026 verified backend `2a410ac35ef9c1284696ea0648016f9d248de6a2` and frontend `318fd061106a97419c573da388a77a4502f80991`. A real synthetic HTTPS burst verified the dedicated redacted notice, counter cleanup removed two expired test counters, and the pilot timestamp stayed immutable. The current live retirement revisions are backend `80672ae7cc5f0a846bcbab2c5a4c7ac8af184f6f` and frontend `c6f8f1c16d3e6062a533ec0e6ca617a79e4f0370`; their isolated `20260907-dropbox` release checkouts are clean. Original workspace HEADs remain unchanged with retirement patches and acceptance documentation as uncommitted work. #5 application retirement acceptance is complete. Full acceptance and the other outstanding operator actions are recorded in workspace `output/security-validation/SECURITY-RELEASE-STATUS.md`.
