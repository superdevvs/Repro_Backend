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

## Release acceptance

Use reviewed clean component checkouts based on the verified live revisions. Deploy the frontend removal and backend removal only after their quality checks pass. The deployment wrapper removes deleted source files while preserving runtime storage.

Repeat media acceptance with Dropbox routes required absent. Compare the original/rendition inventory fingerprint to the disabled baseline, exercise local media/upload/share/thumbnail regressions, and verify retired endpoints over HTTPS. Record the deployed commits and actual production result in the workspace security release acceptance record before closing #5.
