# Dropbox retirement — original security finding #5

Dropbox is retired from the dashboard. Device uploads, scanning, thumbnails, watermarks, local/R2 storage, selected downloads, editor ZIPs, share links and finalization use the storage-neutral `ShootMediaStorageService` and existing media services.

## Disable before removal

Production was inspected before changing configuration: zero Dropbox OAuth records, no environment access/refresh tokens, and none of 231 shoot files had a Dropbox path or file ID. The sole share record pointed to a local ZIP despite its historical `dropbox_path` column name.

On 7 September 2026 at 03:52:32 UTC, the operator removed active `DROPBOX_CLIENT_ID` and `DROPBOX_CLIENT_SECRET`, set `DROPBOX_ENABLED=false`, rebuilt configuration and signaled queue restart. The guarded operation preserved every unrelated environment value. It did not contact Dropbox or modify stored media.

The disabled-state baseline passed 130 local media tests and production acceptance. All 211 thumbnail, grid, web and placeholder references and all recorded watermarked variants were readable. Bounded image decoding and a synthetic two-color image verified actual rendition dimensions/pixels, gallery URLs and original preview bytes. Two original references were already missing; quarantined media was excluded. Compare this baseline after release rather than labeling pre-existing missing originals a removal regression.

## Removed integration surfaces

- Studio OAuth controls/callback, Dropbox webhook, status and connection-test provider.
- Personal Dropbox upload source OAuth, browsing and imports; other providers remain.
- Legacy copy/browse routes and the unused Dropbox folder-copy `/shoots/{shoot}/archive` route. Ordinary shoot status and local ZIP archives remain.
- Provider HTTP client code, image placeholder helper, connection commands and configuration.
- Frontend connection/import/callback UI and the unused Dropbox SDK. The ordinary client is named `shootMediaService`.

Retired routes return 404/405 even if stale configuration or historical token rows exist. New albums accept local storage only. Generic settings retain their deny rule so legacy credential rows cannot be exposed or rewritten through an unrelated settings endpoint.

## Compatibility and recovery

Keep historical migrations and database columns; some contain local ZIP or workflow metadata. Retained old job classes are adapters: mirror work completes without provider access, and old album jobs use local intake with scanning. The legacy workflow class has no provider access. A minimal token-service stub throws locally so the paused workstation recovery seeder continues to skip Dropbox cleanup. Recovery passwords, shared defaults and account provisioning are unchanged under paused finding #7.

No Dropbox account, provider app or cloud files were deleted. Removing active configuration does not establish revocation of historical provider grants. That evidence remains part of finding #3; quarantine stays closed and APP_KEY replacement stays deferred.

## Live release and acceptance

Backend **`80672ae7cc5f0a846bcbab2c5a4c7ac8af184f6f`** and frontend **`c6f8f1c16d3e6062a533ec0e6ca617a79e4f0370`** are verified live. Both were deployed from clean, reviewed `output/security-releases/20260907-dropbox` component checkouts. Backend CI run `34082254659` passed **3,089 tests / 87,542 assertions**. Frontend CI run `34081885708` passed **234 suites / 1,327 tests** and all quality gates. Deleted source files were removed by the deployment wrapper while runtime storage was preserved.

The production retired-media probe passed **19 checks**. Its original/rendition inventory fingerprint is **`217bd721a3b29249dc2970532bd864c20573258bd537a9adcaeabee050f951fa`**, exactly matching the disabled baseline:

| Media class | Post-release readable references | Comparison |
| --- | --- | --- |
| Thumbnail, grid, web and placeholder | 211 in each class | Unchanged |
| Watermarked originals | 88 | Unchanged |
| Watermarked previews | 264 | Unchanged |
| Originals | 213 of 215 | The same two references were already missing before removal |
| Quarantined records | 16 excluded | No quarantined content read |

Readability checks were supplemented by bounded actual image decoding and a synthetic two-color image that verified rendition dimensions/pixels, gallery URLs and original preview bytes. The matching fingerprint establishes the same inspected inventory; the two pre-existing missing originals are recorded separately from retirement regressions.

All **14 retired endpoint probes returned 404 over actual production HTTPS**. Browser checks confirmed raw and edited thumbnails and the large web viewer render; Settings has no Dropbox UI.

**Final package acceptance passed; #5 is closed for the application integration.** Initial testing exposed an existing stopped default worker, with 719 jobs ahead of the test export. Its permission failures at 2026-09-06 16:27:47 UTC and backlog from 16:29:01 UTC predated Dropbox disablement. After explicit approval to resume queued scheduled messages, the user ran the reviewed administrator command and restored the existing worker as `www-data`, PID 893810.

The earlier isolated runner stopped before mutation because the deployment account could not write the web-worker-owned shoot directory. Normal processing succeeded after the actual worker was restored. At 05:33:45 UTC it had remained running for 355 seconds, the queue was empty, test archive job 198602 was consumed and no new failed jobs were recorded. Bounded post-start logs contained no new permission, fatal, database-lock, archive, mail or general-error classifications.

Browser RAW, MLS and Print download flows passed. At 05:33:17 UTC both edited ZIPs returned HTTPS 200 application/zip with hashes/lengths matching their local files. Each has three correctly ordered entries matching current local inputs: MLS 655,908 ZIP bytes and Print 655,267 ZIP bytes. Both manifests are fresh. The post-recovery media probe again passed 19 checks with the same inventory fingerprint and all 211 thumbnails readable. Browser OS-folder receipt was not inspected; download transport and ZIP content were verified independently. Detailed evidence is in the workspace security acceptance record and `dropbox-worker-recovery-acceptance.json`.

The original backend/frontend workspaces now contain the reviewed retirement patches as uncommitted changes. Their older HEADs are unchanged, staging indexes are empty, and unrelated work plus paused #7/#8 scope is preserved. The isolated release checkouts remain clean. Historical credential/grant revocation under #3 is still unverified and is not a condition that can be satisfied by removing dashboard code.
