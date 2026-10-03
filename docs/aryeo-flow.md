# Aryeo Flow connector contract (v1)

Status: API and Tours panel deployed on 2026-10-03; the Mac credential passed live authentication and scope checks. Discovery/heartbeat and the dashboard-job executor are being connected separately. No delivery is authorized by this document.

Dashboard-backed processing requires a Showcase request matched to a paid, dashboard-delivered shoot with approved requested media. A Summary email is not required. Dashboard delivery and Aryeo delivery remain separate states; only a verified Aryeo receipt confirms the latter. Non-media fee lines do not block otherwise approved tour links.

## Transport and authentication

The Mac initiates HTTPS requests to `https://reprodashboard.com/api/integrations/aryeo/v1`. Keep `127.0.0.1:18792` private. The dashboard never invokes the local general `/api/run` endpoint.

Every worker request uses `Authorization: Bearer <worker credential>` and `Accept: application/json`. POST/PUT bodies use JSON. Worker credentials cannot authenticate dashboard staff routes. Do not put tokens or lease secrets in URLs, logs, screenshots or chat.

Provision on the backend host in a private operator directory:

```sh
umask 077
php artisan aryeo:connection 'Mac mini Aryeo' --company=repro --clients=1225 --token-file=/private/operator/directory/aryeo-worker-token
```

Use an actual private directory. The command refuses to overwrite an existing file and saves the credential with mode 0600; the database stores only its SHA-256 hash. Transfer the file through an existing secure channel into the Mac's Keychain or a private 0600 configuration file. The company key is an operator-assigned label; the explicit client ID allowlist is the authorization boundary. Add all and only verified company clients. No wildcard is supported. Running provisioning again rotates the credential and disables processing. `--revoke` disables the connection; `--disable-processing` stops new processing while allowing read-only synchronization.

Only after the user selects a delivery test and both sides pass read-only validation:

```sh
php artisan aryeo:connection 'Mac mini Aryeo' --enable-processing --allow-shoots=115
```

This example is a command template, not approval to deliver shoot 115. Enabling requires selected shoot IDs belonging to allowed clients. The Mac must acknowledge `dashboard_jobs` mode. Disable both Gmail-triggered processing and watchdog processing outside a claimed dashboard job, while leaving discovery enabled.

## Initial read-only example

Read-only production lookup on 2026-10-03 matched:

- Shoot: **115**; client: **1225**, Andrea Lovelace.
- Address: **9407 Reservoir Road, Fredericksburg, VA 22407**.
- Unit: **null** (no unit records).
- Stored state: delivered, paid; admin verified at `2026-10-02 13:13:47` as stored by the database.
- Gmail request source ID from the Mac handoff: `1a0fedd16d78cc61`.

This is identity/state evidence, not a complete readiness response or delivery receipt. The real Aryeo request ID, requirements and current inventory still need to be read on the Mac. The discovery matcher deliberately does not guess that `Rd` and `Road` are identical. Use the full canonical address and verified requester email or manually confirm the match in Tours. Never reuse the Gmail source ID as an Aryeo request/listing ID.

A subsequent read-only evaluation of the new catalog against production records at `2026-10-03T04:36:56Z` returned **49 approved photo records**, zero floor-plan/video/tour records, zero payment-withheld records, and `eligible:false` with the sole blocker `request_requirements_unknown`. This was a temporary CLI evaluation, not a deployed HTTP endpoint; no files were downloaded and no delivery was triggered. Media version at that check: `cc8e4f6c70fb87c134331ecc6562bceb7ba5a9f81e7146d6875a74f060a49f99`. The Mac must supply actual request requirements before the endpoint can make a complete readiness decision.

## Discovery and read-only endpoints

| Method/path | Purpose |
|---|---|
| `GET /shoots?search=9407&after_id=0` | Up to 100 allowed shoots, including delivered; paginate by `next_after_id` until empty. |
| `GET /shoots/115` | Identity, client email, address, date, workflow state, units and explicit imported Pro ID if recorded. |
| `GET /shoots/115/readiness?request_record_id=7` | Request-specific release decision and approved manifest. |
| `GET /shoots/115/readiness` | Inspection without an order; includes `request_requirements_unknown`, never asserts full readiness. |
| `GET /requests/7/assets/42/original` | Read-only original download after current release checks. Never returns a thumbnail as an original. |
| `GET /changes?cursor=0` | Durable changes, 100 at a time. Save `next_cursor` only after processing the entire page. |
| `POST /requests` | Idempotent upsert by connection + Gmail source ID; automatic exact matching. |
| `PUT /requests/7/inventory` | Fresh remote inventory, including items added outside this connector. |
| `POST /heartbeat` | Worker presence/capabilities; send every 30 seconds. |

Identity response shape (illustrative, not a live response):

```json
{"id":115,"client_id":1225,"client_email":"verified-client@example.test","address":"9407 Reservoir Road","city":"Fredericksburg","state":"VA","zip":"22407","scheduled_date":"2026-10-02","status":"delivered","workflow_status":"delivered","imported_pro_shoot_id":null,"units":[]}
```

`imported_pro_shoot_id` reads the explicit `external_booking_payload.legacy_migration.pro_shoot_id` field, or `legacy_migration.source_id` only when `legacy_migration.source` is exactly `pro.reprophotos.com`. The latter format was verified in production with a read-only metadata query. Null means unavailable, not identical to the dashboard ID. Other source formats require an audited mapping; never infer them from unrelated provider IDs.

Discovery example (replace placeholders with verified values; zero means not requested, not unknown):

```json
{"source_id":"1a0fedd16d78cc61","request_id":"actual-aryeo-request-id","listing_id":"actual-existing-listing-id","address":"9407 Reservoir Road","requester_email":"verified-client@example.test","summary_present":false,"required":{"photos":30,"floorplans":1,"videos":0,"tours":0}}
```

If the requested categories are unknown, submit `required:null`; discovery is recorded but processing remains blocked. When categories are verified but quantities are unspecified, send all four keys with null for each requested category and 0 for each explicitly unrequested category. A null quantity requires at least one approved item of that type and includes all approved media; it is not a fabricated exact count. Preserve any verified numeric quantities. If a request or listing does not yet have a verified provider ID, omit that ID; processing remains blocked without a request ID. Matching requires an exact normalized address and client email; an optional date narrows repeat shoots. Multiple matches or ambiguous units remain unlinked. Staff can explicitly match a discovered request in Tours.

Readiness response shape:

```json
{"shoot_id":115,"unit_id":null,"eligible":false,"blockers":["missing_floorplans"],"required":{"photos":30,"floorplans":1,"videos":0,"tours":0},"available":{"photos":35,"floorplans":0,"videos":0,"tours":0},"withheld_for_payment":0,"media_version":"sha256-of-manifest","assets":[{"id":42,"type":"photos","filename":"photo.jpg","bytes":12345,"sha256":null,"extra":false,"order":1,"revision":"sha256-of-file-revision"}],"tours":[],"checked_at":"2026-10-03T12:00:00Z"}
```

Release is computed independently of staff privileges, using shoot verification, suppression/cancellation/hold state, payment/explicit release, per-service permissions and media filtering. Hidden, raw, quarantined and unfinished files are excluded. Approved extra photos are included. Video is only required when its request count is nonzero. Unit manifests use the existing unit/common-area scope. Tours are URL records, not fictitious downloadable files. `sha256:null` means no stored content checksum; original responses include `X-Content-SHA256`, computed from the actual file. Verify that hash and transfer size before uploading, and retain them in the local journal. `media_version` is a manifest revision, not a content checksum. Manifest availability describes approved database records; the original download verifies physical availability. Missing originals fail the download; do not substitute previews.

Inventory example:

```json
{"request_id":"actual-aryeo-request-id","listing_id":"actual-existing-listing-id","checked_at":"2026-10-03T12:00:00Z","complete":true,"assets":[{"id":"remote-file-1","type":"photos","filename":"photo.jpg","delivered":true,"dashboard_asset_id":42}]}
```

Only set `complete:true` after reading all relevant remote media, not from saved workflow state. Missing or incomplete inventory displays **Unknown**, not zero. Complete inventory older than two minutes retains its labelled last-verified counts and requires refreshing before delivery. `delivered` must come from actual destination evidence. Report uploaded-but-unreleased items as false. Older inventories cannot replace newer ones.

Changes are a SQLite trigger-backed feed covering shoot, file, service, unit, payment and payment-allocation inserts/updates/deletes, including bulk writes. Events carry `id`, `shoot_id`, `client_id`, `kind`, `created_at`. Refetch identity/readiness on any event; a 404 means remove/revoke that shoot locally. Client ownership changes generate a revocation event for the old scope. Polling may return multiple events for one shoot. Cursor zero also includes a migration-time initial snapshot. Production storage is SQLite; this trigger implementation is not a portable MySQL change feed.

Heartbeat body:

```json
{"version":"mac-connector-1","processing_mode":"dashboard_jobs","shoot_executor":true,"inventory_refresh":true}
```

Response contains `processing_enabled`, `processing_mode:"dashboard_jobs"`, `lease_seconds:90`, `poll_seconds:15`. Offline means no successful heartbeat within two minutes.

## Durable job protocol

Staff create jobs only through `POST /api/shoots/{shoot}/aryeo/requests/{record}/process`. The Tours panel is restricted to admin, superadmin and editing_manager on frontend and backend. Opening or refreshing the panel never starts delivery. Only one job may hold an active lease per connection, because the Mac's browser tabs are shared across orders.

1. `POST /jobs/claim` with a new UUID `claim_id` and cryptographically random `lease_token` (32+ characters). Persist both locally before calling. On timeout retry **the same body**. A successful response is `{job:null}` or `{job:{...},reconciliation_required:boolean}`. Jobs include stable UUID `id` (the operation ID), snapshot with shoot/unit, Aryeo request/listing IDs, source Gmail ID, manifest and `media_version`. Every click/retry reuses the unfinished job; a new media version after completed delivery creates a new job against the existing listing.
2. `POST /jobs/{id}/renew` with `lease_token`, optionally `phase` (`preparing`, `uploading`, `verifying`, `delivering`, `followup`), every 30 seconds. Lease lasts 90 seconds. A 409 means stop side effects and recover; never continue under a lost lease.
3. Refresh actual remote inventory and upload it. Before upload and immediately before delivery, `POST /jobs/{id}/authorize` with `lease_token`. This rechecks current release, selected-shoot permission, request identity, manifest version and fresh complete inventory. A 409 blocks delivery.
4. Download approved originals using `POST /jobs/{id}/assets/{asset}/download` with `lease_token` in JSON, never the URL. Reuse existing remote files when verified identical. Upload extras. Preserve the original listing ID and record each remote file ID locally before progressing.
5. `POST /jobs/{id}/result` with the lease token, `steps`, optional `error`, and delivery `receipt`. Use `GET /jobs/{id}` to recover uncertain results before retrying.

Example result (illustrative):

The renewal endpoint also accepts optional structured `progress` for live Mac activity:

```json
{"lease_token":"secret-from-claim","phase":"uploading","progress":{"action":"adding_files","message":"Adding photo to Aryeo","completed":1,"total":49,"current_file":"Reservoir-02.jpg","observed_at":"2026-10-03T12:00:00Z"}}
```

Report transitions and at most ten-second intervals during long work. `action` is at most 60 characters, `message` 300, and optional `current_file` 255; send safe user-facing descriptions, never commands, credentials or raw logs. Counts must satisfy `0 <= completed <= total <= 10000`. The server ignores older observations and records `updated_at` separately from `activity_at`, which changes only when the activity fields change. Lease ownership remains mandatory. Progress never overwrites actual errors. The panel polls every five seconds during active work, shows activity beside the action button, and warns about unchanged activity, expired leases and offline workers. Phase-only older workers remain compatible but cannot supply file counts.

Example result (illustrative):

```json
{"lease_token":"secret-from-claim","steps":{"upload":"success","delivery":"success","completion_email":"success","forwarding":"success","summary_forwarding":"not_applicable","filing":"failed"},"error":"Archive disk unavailable","receipt":{"request_id":"actual-aryeo-request-id","listing_id":"actual-existing-listing-id","media_version":"exact-job-media-version","verified_at":"2026-10-03T12:00:00Z","asset_ids":[42],"tour_ids":[]}}
```

Step states: `pending`, `success`, `failed`, `not_applicable`. Delivery cannot be skipped. A success receipt must identify the job's request/listing/version and every approved asset/tour. Mark success only after independent destination verification. The dashboard validates structural identity/completeness; the Mac must supply truthful fresh provider evidence. Upload success alone is never delivery success. No Summary means retain dashboard evidence and report Summary forwarding not applicable, never invent an email receipt.

Expired leases are reclaimed as `reconciling`. Refresh a complete inventory, inspect delivery and email records, and recover each uncertain operation by its stable operation ID. `POST /renew` with `reconciliation:"not_delivered"` may resume only after proving delivery did not occur. If delivery occurred, submit its verified receipt; never deliver again. `unknown` keeps the job blocked. A worker receiving a replayed old claim must inspect job state/expiry, not treat it as new permission.

Completed steps cannot be reset. A delivered job with unfinished email/forwarding/filing is `followup_pending`. Staff's Resume action requeues the **same** job and retained step evidence; reconcile and run only unfinished steps. A failed job retains evidence too. The connector must persist action identities for emails/forwarding/filing locally and verify uncertain outcomes; dashboard job idempotency cannot make browser clicks or email sends intrinsically exactly-once.

Do not call the dashboard `/complete` action. Do not call local `/api/run` as a substitute for a shoot-specific executor. An initial integration must keep automatic discovery but prevent both Gmail and watchdog paths from processing without a dashboard job.

## Validation boundary

Run backend tests in `tests/Feature/AryeoIntegrationTest.php` and frontend panel/unit-scope tests. Read-only live checks should confirm scope, shoot 115 identity, actual order requirements, approved originals and destination inventory. No delivered-state assertion can be made until a selected end-to-end delivery is verified on the Mac and its receipt is accepted.

Initial deployment validation on 2026-10-03: full backend CI passed 5,180 tests, frontend CI passed 3,744 tests plus monitor/desktop checks. Live release markers, the Tours panel, Mac authenticated shoot lookup/readiness, unauthenticated 401 and out-of-scope 404 were verified. The credential is stored privately on the Mac; only its hash is stored server-side. Connection 1 initially permits client 1225, with processing disabled and no jobs created. No Aryeo delivery has been tested or confirmed by this setup.
