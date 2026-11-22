# MySQL production cutover runbook

Status: prepared for review and isolated rehearsal. This document does not
authorize a deployment, service installation, database switch, or live provider
action. The owner explicitly requested that production stay unchanged.

Use the separate **MySQL 8.4.13** target from
[`scripts/mysql/server`](../scripts/mysql/server/README.md). The existing 8.0.46
service is outside this migration. The actual target host, its administrator
preparation, backup destination/retention, and maintenance window must be
confirmed before cutover. Local success does not substitute for that acceptance.

## Release and target preparation

1. Freeze a reviewed backend commit and the corresponding current frontend
   release. Reconcile later releases deliberately. Preserve the dirty primary
   checkouts; use the isolated candidate and the existing guarded
   `scripts/deploy/Deploy-App.ps1 -PreparedRelease` workflow.
2. Require the complete `deploy.yml` workflow, including its reusable MySQL
   profile, to pass for the exact reviewed commit. The new MySQL job uses empty,
   separately permissioned test databases and no production credentials/data.
   Once separately authorized, the compatibility code can be deployed and
   verified while the live database remains SQLite, before the migration window.
   This marks conditional MySQL migrations as applied even when they did no
   MySQL DDL; the completion step below is required regardless of ledger state.
3. Have the administrator provision the separate target. Verify the pinned
   image/version, server UUID, durable commit/binlog settings, strict mode,
   Unix-socket access as both `www-data` and `maverick`, account isolation,
   connection budget, automatic recovery, disk alerts, and off-host backups.
   Validate boot recovery on the actual host. Do not upgrade/restart the
   existing MySQL service or install Docker without the owner's authorization.
4. Install matching 8.4 `mysql` and `mysqldump` under
   `/opt/repro-mysql84/client/`; retain the old clients. Rehearse the candidate's
   `scripts/deploy/backup-database.php` and an independent full restore on the
   actual target. A successful dump alone is insufficient.
5. Rehearse the whole sequence using a fresh read-only online SQLite backup.
   Preserve the original APP_KEY, provider ciphertexts, IDs, media references,
   queue payloads, timezones, same-origin URLs, and storage permissions. No
   historical financial/audit record is deleted or assigned an invented parent.
   Compare the fresh exact exception identities with the approved history.
6. Review provider retry/reconciliation procedures for the maintenance window.
   Verify offline webhook replay/idempotency and queue contracts. Real provider
   calls, messages, payments and physical-device acceptance require a designated
   sandbox/device or explicit authorization; mocks and viewport emulation are
   labeled as such in the readiness report.

## Freeze all writers

Maintenance mode alone is not a writer freeze. The read-only inventory on
November 5 found these independent worker owners:

| Owner/control | Workers | Connection and queues |
| --- | ---: | --- |
| System Supervisor, `/etc/supervisor/conf.d/laravel-worker.conf` | 3 | database: mail, integrations, default, watermarks |
| User Supervisor, `/home/maverick/.local/share/repro-studio/supervisord.conf` | 4 | database: voice-realtime |
| Same user Supervisor | 2 | database: voice-notifications |
| Same user Supervisor | 1 | database: voice-transcripts |
| Same user Supervisor | 1 | studio: studio |
| `repro-media-archives.service`, user www-data | 1 | database: media-archives |

The user Supervisor is started through cron. Inventory current processes again;
PIDs and counts can change. There were also nine deployment-user cron entries.
An administrator must account for other users' crontabs, timers, schedulers,
manual commands, uploads, provider callbacks, active voice calls and integrations.
Do not stop the whole system cron/Supervisor service to affect one application.

Before the final backup:

1. Drain active calls, media work and provider operations. Stop new admissions
   with the approved maintenance procedure and confirm HTTP writes are blocked.
   Keep webhook failures recoverable; do not acknowledge events and drop them.
2. Pause new job reservations with the installed Laravel `queue:pause` command:
   `database:mail`, `database:integrations`, `database:default`,
   `database:watermarks`, `database:voice-realtime`,
   `database:voice-notifications`, `database:voice-transcripts`,
   `database:media-archives`, and `studio:studio`. Use the normal shared cache
   context and confirm both Unix owners see the pause flags.
3. Wait for current work to complete. Identify stale reservations separately;
   never force-requeue an uncertain payment/message/provider operation. The
   observed Studio worker timeout is 7,200 seconds and its retry interval 7,260;
   the database connection defaults to 2,100 seconds for long media assembly.
   Do not interrupt a long provider job to shorten the window.
4. Stop the exact application worker groups/service and application scheduler
   after draining. Verify no application writer remains. Cache clearing can
   erase queue pause flags, so stop the workers before rebuilding caches.
5. Record source release/configuration fingerprints, source snapshot SHA-256,
   target UUID, worker/scheduler state, queue counts/reservations, and a journal
   of received/retried provider events. Keep this protected evidence outside
   the release archive. Preserve the original environment file separately.

## Import into the empty target

Use a standalone online-backup artifact, never a raw copy of a live WAL database.
The import tool opens it read-only and verifies its checksum and integrity.
Create a private Python virtual environment and install
`scripts/mysql/requirements.txt`. The maintenance account must be scoped to the
new database. Its protected 0600 JSON file contains only `unix_socket`, `user`,
and `password`; credentials never belong in command arguments or source control.

First generate a fresh plan with a new private output directory:

```bash
python scripts/mysql/migrate.py plan \
  --snapshot /protected/frozen.sqlite \
  --snapshot-sha256 <verified-snapshot-sha256> \
  --output /protected/new-plan
```

Compare `exceptions-private.json` with the approved exception list. Preserve
history exactly; any additional exception requires review. Record its file hash.
Only after confirming writer freeze, the empty target and independent UUID:

```bash
python scripts/mysql/migrate.py import-empty \
  --snapshot /protected/frozen.sqlite \
  --snapshot-sha256 <verified-snapshot-sha256> \
  --credentials /protected/maintenance.json \
  --database repro_dashboard \
  --expected-server-uuid <independently-observed-new-server-uuid> \
  --approved-exceptions /protected/new-plan/exceptions-private.json \
  --exceptions-file-sha256 <approved-exception-file-sha256> \
  --output /protected/new-import
```

The tool refuses a different patch version, wrong UUID, nonempty destination,
changed snapshot or changed exception set. It has no reset/drop-database path.
A failed import leaves a partial isolated target for investigation; never
automatically erase it or retry against a database that has received traffic.

Import success is deliberately **not** cutover approval. It verifies every
original row/field, restores the approved legacy constraints with enforcement
enabled, and installs the 25 direct change-feed triggers. Apply the reviewed
application migrations in an isolated maintenance configuration, then always run
the guarded schema-completion tool to add the repairs, effective-period index
and ten MySQL cascade triggers. SQLite can have already recorded these migration
names while skipping their MySQL-specific work. The tool reapplies only the five
reviewed idempotent repairs and does not delete or rewrite migration-ledger rows.

With explicit MySQL process configuration and a separate staging cache:

```bash
DB_CONNECTION=mysql DB_DATABASE=repro_dashboard \
  APP_CONFIG_CACHE=/protected/staging-cache/config.php \
  php scripts/mysql/complete-schema.php --app=/protected/staged-backend \
  --expected-server-uuid=<independently-observed-new-server-uuid> \
  --writers-stopped
```

The staged application's protected environment supplies its target socket and
credentials. Require the `verified` result, validate the final schema, and restore
any temporary trigger-installation privilege setting after all DDL completes.
Do not reuse the active SQLite configuration cache or boot a scheduler/worker while doing this.
Verify the final schema, supporting indexes, next IDs and migration ledger.
New migrations referencing converted legacy IDs should use
`App\Support\MigrationForeignId::matching`; the CI baseline catches type drift.

## Validate before reopening traffic

Keep public writes and all workers stopped while checking:

- All original row/field hashes and approved exception identities match. Account
  for only the reviewed new migration rows and explicitly documented repairs.
- Foreign-key enforcement and strict SQL are enabled. Invalid new references
  are rejected. Date-only values, decimals, JSON types, ownership and ordering
  preserve their contracts. Next IDs exceed both existing and deleted IDs.
- Queue jobs/payloads/attempts and outbox identities match. No copied customer
  job has run in the test environment. Provider idempotency/reconciliation state
  and the original APP_KEY are intact.
- Representative admin, client, rep, photographer and editor APIs, accounting,
  search, upload/preview/download/ZIP, scan/payment restrictions, public signed
  media and mobile-web flows pass on the actual target. Existing credentials
  and physical mobile/provider flows have the designated acceptance evidence.
- Measured target-host latency/throughput, write contention, connection headroom,
  queue backlog/recovery and backup/restore meet the agreed limits. Review both
  typical and sustained load; an empty test DB is not a performance benchmark.

Back up the verified MySQL target. Change only the database settings in the
preserved environment through the approved cutover procedure. Retain the APP_KEY
and all unrelated settings. Rebuild configuration/OPcache and verify FPM and both
worker owners resolve the same driver/database/server UUID. Use a controlled
smoke period before reopening public writes, then resume the exact paused queues
with `queue:resume`, restart their owning groups/service and the scheduler, and
verify they use MySQL. Reconcile incoming provider events before declaring done.

## Rollback has two different paths

**Before any MySQL business write:** restore the saved SQLite connection
settings and its configuration cache while writers remain stopped. The frozen
SQLite database is still authoritative. Retain source/media/cache safeguards and
verify the application before resuming writers.

**After MySQL has received writes:** stop admissions and all writers again.
Returning to the old frozen SQLite file would lose activity. Reconcile any
external side effects, export current MySQL data into a **new** SQLite file,
preserve IDs/sequences/nullable repairs/indexes/triggers, and verify every field
and the exact legacy exceptions before considering a switch. Keep the current
compatibility candidate code; the older code lacks its JSON/SQL fixes. Abort the
reverse switch on any unrepresentable numeric value or hash mismatch.

The isolated post-write rehearsal verified all 231 tables and 1,185,802 rows,
preserved a new notification receipt and queued job, and successfully booted the
candidate against the read-only exported SQLite file. Its reverse export and
verification took 790 seconds alongside other tests. This is evidence that a
careful recovery is possible, not a promise of instantaneous post-write rollback.
The packaged `scripts/mysql/export-sqlite.py` then repeated the full recovery
after sustained HTTP load and a copied-server crash. It verified 231 tables and
1,187,699 rows in 209.2 seconds, and the recovered application retained all 164
new notification receipts, the earlier queued-job fixture and invoice queries.
The additional rows are intentional local activity, not additional production
rows claimed from the original snapshot.

Prepare a frozen current-release SQLite schema template before cutover, including
the candidate's SQLite-compatible migrations. Record its checksum. After stopping
writers and reconciling external effects, the reviewed reverse tool is:

```bash
python scripts/mysql/export-sqlite.py \
  --credentials /protected/maintenance.json \
  --database repro_dashboard \
  --expected-server-uuid <independently-observed-source-uuid> \
  --schema-template /protected/current-release-sqlite-template.sqlite \
  --schema-template-sha256 <reviewed-template-sha256> \
  --approved-exceptions /protected/approved-exceptions.json \
  --exceptions-file-sha256 <approved-exception-file-sha256> \
  --writers-stopped --output /protected/new-recovery
```

This tool never switches the application or replaces a file. It refuses source
identity, table/column-set and trigger-name mismatches, detects DDL changes during
export, and stops on a field hash, precision, integrity or historical-exception
mismatch. Its `verified` result still requires independent
application/side-effect validation before connection activation. The complete
private evidence is retained in the protected November 5 lab; repeat acceptance
against the final actual target.

The local forward import took 508.6 seconds plus 135.4 seconds for field-hash
verification, before further migrations and acceptance. Agree on a maintenance
window from an actual-host rehearsal; do not promise zero downtime from these
local measurements.
