# MySQL production readiness: November 5, 2026

## November 8 refresh

The candidate now includes production release `46284c95`, retaining the newer
component/provider delivery, notification timing/privacy, travel-fee and Zillow
changes. The November 5 results below remain historical evidence; they do not
establish acceptance of this refreshed source or current production data.

Separate MySQL 8.4 host provisioning has been authorized. It is still **not
installed**: both SSH and the Maverick server chat require interactive
administrator authentication. The live application remains on SQLite, and no
application deployment or database switch is authorized. The server-side
administrator preflight is at
`/home/maverick/repro-mysql84-provisioning-20261107/admin-preflight.py`.

The service templates now use dedicated UID 64084 instead of the host's
dnsmasq UID 999, bounded container logs, and an isolated slow-log rotation
timer. An authenticated unit preflight runs before logrotate; its in-config
precheck alone cannot prevent older archives moving when the database is down.
Local synthetic validation passed fresh initialization, PHP UID 33 socket
access and account isolation, durable recovery of 100 committed rows with one
uncommitted row rolled back, nine log rotations, ownership, compression,
seven-file retention, and refusal to touch logs while the database was offline.
That smoke test used a 256 MiB buffer and 1 GiB cap to limit workstation memory;
it is not a host-capacity test. Rotation hooks used matching direct clients in
the fixture; actual host Compose/systemd execution remains to be accepted.

The three new delivery migrations now match signed imported or unsigned native
parent IDs and use a MySQL-compatible provider index name. Provider observation
locks its shoot before the duplicate check, preserving one event under
concurrent identical observations. External receipt identity comparisons sort
JSON object keys while retaining strict key, value and type checks, because
native MySQL JSON does not preserve insertion order. New native tests cover the
real migrations and two independent worker processes; cross-engine receipt
tests cover reordering, changed identity and revocation. The travel-fee test builds a portable
SQLite safety-backup fixture without weakening the repair command's checks.
Focused runs passed: 16 MySQL migration/concurrency tests with 224 assertions,
72 MySQL delivery/fixture checks with 766 assertions, and 49 affected SQLite
tests with 645 assertions. These runs overlap and are not summed. Removing the
provider row lock in a disposable source copy reproduced the duplicate-key
race; restoring it passed both native cases with 40 assertions. Broader refreshed application checks and CI
are recorded in the draft PR and protected refresh evidence against the final
candidate commit; this document does not turn historical results into current
production acceptance.

Protected refresh evidence is under
`C:\Users\shubh\.codex\repro-mysql-lab\20261105\host-refresh-20261108`.
Do not reuse the older customer snapshot for a production cutover. Actual-host
installation, fresh-copy parity, host load, off-host restore, provider/device
acceptance and a separately approved cutover window remain outstanding.

## November 5 rehearsal record

The isolated migration candidate is prepared and tested. Production activation
is **not approved or complete**. This migration work made no production
application/configuration/service or business-data changes. Read-only SSH inventory and an online
read-only SQLite backup were used for this rehearsal.

The remaining production gates are the actual selected MySQL 8.4 host,
administrator provisioning and operational acceptance, a fresh frozen-data
rehearsal there, final CI/release approval, and an agreed cutover window. Live
provider and physical-device acceptance must use a designated sandbox/device or
explicit authorization. No real payment, message or customer job was executed.

## Candidate and evidence

The backend candidate is based on upstream `bb4271b8`, preserving brokerage-logo
uploads, the newer rep impersonation release, Aryeo handoff and Symfony security
updates. The frontend reference is `98af9062`. Backend runtime changes were
reconciled and retested; the frontend has no migration-specific edits. Dirty primary checkouts were
preserved in separate worktrees. The final release commit, source hashes, image
identities and report checksums belong in the protected final manifest.

Private lab: `C:\Users\shubh\.codex\repro-mysql-lab\20261105`.
Application source: the isolated `mysql-migration-20261104` worktree's backend.
The older November 4 evidence and checkpoint were retained separately.
Customer snapshots, credentials, media and detailed reports are not committed.

The fresh source snapshot was taken at **2026-11-05 02:44 UTC**, from deployed
backend `c253567153e2beed1a4161b9f13ba60712aa6740`. It contains **231 tables and
1,185,728 original rows**, with SHA-256
`4a94bc0eef698a15852c08869ac039aacd9dfc9db8f1e00f20391f4eb93ab52f`.
Every original row/field matched immediately after conversion. Subsequent lab
activity is kept distinct from that initial data-preservation claim.

The owner-approved history is unchanged: **195 distinct rows, 223 missing
references across eight relationships**, including ten financial/audit rows.
No historical row was repaired/deleted or given an invented parent. The exact
exception manifest is private; all eight negative new-write checks rejected
invalid links. The final candidate has **385 foreign keys, 791 non-primary
indexes (180 unique, 611 non-unique), 15 checks, 35 triggers and 369 migration
names**. This includes MySQL's supporting FK indexes and the reviewed repairs.

## Verification results

| Area | Verified result |
| --- | --- |
| MySQL regression inventory | 6,024 cases in two independent server shards. One missing local TIFF tool and five PDF tool skips were resolved with a complete Ubuntu runtime and focused reruns; 12 SQLite-specific cases remain intentionally outside MySQL execution. |
| Current-release backend checks | 105 tests / 988 assertions passed on MySQL, including the newer permission release, TIFF/PDF/HDR, backups, native revision races and queue contracts. Zero skips/failures in this focused run. |
| Brokerage release reconciliation | The later brokerage-logo release is retained. Logo permissions, persistence, tours and failed-upload cleanup passed within 45 MySQL tests / 323 assertions and 43 SQLite tests / 303 assertions, including the shared failure-injection callers. The new frontend paths passed 31 focused cases. |
| SQLite fallback | 830 affected cases / 6,882 assertions passed, plus 74 current-release/media/deployment tests / 680 assertions. Counts overlap and are not summed as unique coverage. |
| Frontend | 5,862 of 5,863 cases passed in the full run; one registration case hit its 5-second timeout. Its unchanged file then passed all 18 cases. After upstream reconciliation, 54 affected cases, type check, lint baseline and production build passed. |
| Copied business APIs | 39 admin/role comparisons matched status and business values, apart from independently checked signed-URL expiry and numeric roundoff below 0.00000001. |
| Desktop/mobile web | Actual login form and eight page checks across two browser viewports passed without API server errors, unhandled browser errors or page-level horizontal overflow. Phone checks are emulation, not physical/native-device proof. |
| Real media sample | Eight original/thumbnail files (34,730,787 bytes) matched source hashes and scanned clean. Five permitted uploads/replays, three oversized paid-upload denials, eight owner download hashes, eight private previews, eight foreign denials and eight staff denials passed. ZIP entry integrity was verified for the sample; application archive contracts also run in the regression suite. |
| Existing public media | All 237,265 original photo-signature inputs matched after removing only MySQL's whole-second timestamp padding. Existing links require the original APP_KEY at eventual cutover. |
| Full backup/restore | Driver-aware helper produced a 311,610,171-byte protected dump. All 231 restored tables, data/DDL, column metadata, 35 triggers, 385 FKs and next IDs matched. |
| Worker recovery | Twelve actual Laravel workers processed 1,021 synthetic deliveries into 1,001 unique committed effects. A killed worker's transaction rolled back and its reserved job recovered; shared-cache pause held the backlog and resume drained it. No customer job ran. |
| Proposed server configuration | Pinned 8.4.13, Unix-socket-only operation, strict SQL, durable commits/binlogs, 5 GB buffer pool and 96 connections passed locally. PHP 8.3.6 as UID 33 connected and wrote only its own DB. Automatic restart preserved 100 committed rows and rejected an interrupted transaction. |
| Copied-server crash after HTTP load | All 164 newly committed notification receipts survived; their hashes and server UUID matched, and the existing FPM application reconnected with HTTP 200. |
| Packaged forward/reverse tools | Wrong UUID/nonempty destination refusal, exact historical exceptions, binary/date/decimal fields, next IDs and new-write constraints passed. Reverse export refused unrepresentable numeric precision instead of silently losing it. |
| Driver-specific migration ledger | A guarded completion tool reapplies the five idempotent MySQL repairs even when SQLite already marked them applied. It preserves the original ledger IDs/batches and data, validates the expected target UUID, and requires writer-freeze attestation. |
| Full post-write reverse recovery | Packaged reverse tool verified 231 tables / 1,187,699 current lab rows with zero field mismatches, 25 SQLite triggers, exact exceptions and FK enforcement. The same application booted on the read-only recovery artifact and retained all 164 load receipts, the earlier queued-job fixture and invoice queries. |
| Deployment guards | 23 storage safeguard fixtures and the public-storage deployment tests passed. The deployment wrapper now makes a driver-aware private database backup and the MySQL profile is included in the required `deploy.yml` quality workflow. |
| Real HDR tools | The complete local runtime includes both alignment and Enfuse; actual fusion passed on PHP 8.3.6 with five assertions. CI explicitly installs/checks both binaries and runs this real-tool case. |

Local runtime is Ubuntu 24.04, **PHP 8.3.6 FPM with OPcache** and MySQL
**8.4.13**, pinned to image digest
`sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902`.
Tests and copied data use separate accounts/databases. Outbound application
network access and mail delivery are disabled, and copied queues/schedulers are
not running. The production APP_KEY was not copied into the lab.

The pristine dump SHA-256 is
`04b45c59c50779b349a00cef58bbc1979d44ff07afac8edb51eb358d4ff45894`.
Its comparison permits only redundant `CHARACTER SET utf8mb4` rendering before
the same collation; independent column metadata matched exactly. It was taken
before preview accounts and HTTP load, after the reviewed compatibility DDL.

## Measured performance

The controlled mixed workload used real nginx/FPM, copied data, authenticated
reads and notification writes. Both databases had four FPM children, 2 CPUs and
2 GB application memory. MySQL additionally had a 4-CPU, 4-GB database service
with a 2-GB buffer pool; SQLite runs inside FPM. This resource allocation and the
local Docker/storage environment limit comparisons with the actual server.

| Concurrent requests | SQLite req/s | MySQL req/s | SQLite p95 | MySQL p95 |
| ---: | ---: | ---: | ---: | ---: |
| 4, 400 measured requests per engine | 7.39 | 9.29 | 3,320.3 ms | 2,026.2 ms |
| 12, 1,200 measured requests per engine | 6.82 | 6.97 | 4,993.9 ms | 3,839.6 ms |

All **3,200 measured requests returned HTTP 200**, and all **320 measured
notification writes** were retained. Warmup writes are counted separately.
MySQL's aggregate p95 improved by 39% and 23%; throughput improved by 26% and 2%.
This is not a universal endpoint speedup: some medians and higher-load endpoint
tails were slower, including the dashboard overview and shoot list. Email search
still dominates the workload. Accept endpoint limits and capacity on the actual
host before production, rather than extrapolating an empty-DB or local result.

## Operational work still requiring approval

The existing production host has MySQL **8.0.46**, 32 CPUs, 32 GB RAM, 20 FPM
children and twelve observed application workers. Docker is not installed there.
The proposed separate 8.4 service is prepared in
[`scripts/mysql/server`](../scripts/mysql/server/README.md), with a socket-only
network boundary, resource limits, boot unit and authenticated health check.
Installing it or Docker changes that host and is outside “don't touch production.”

Before cutover, the actual target needs administrator acceptance for service/boot
behavior, FPM and both worker owners, measured load, storage/disk alerts,
off-host backup destination/retention and an independent restore. Inventory all
cron/timer writers and provider event handling, designate any required live
provider/device checks, and approve a maintenance window. Revalidate current
release/data drift at that point. The read-only inventory and test copy are
time-specific; do not import this older snapshot into a live application.

Use the [cutover and rollback runbook](mysql-production-cutover.md). Its tools
produce verified artifacts and deliberately never switch a live application.
Reopening writes and eventual post-write rollback each require their own
controlled validation; the old frozen SQLite file cannot retain later MySQL
activity.
