# MySQL production cutover runbook

Status: implementation and isolated rehearsal. Production cutover has a separate
approval gate. The previous Docker-based rehearsal is historical evidence.

Use the separate native **MySQL 8.4.13** target from
[`scripts/mysql/native`](../scripts/mysql/native/README.md). The existing 8.0.46
service is outside this migration. The actual target host, its administrator
preparation, backup destination/retention, and maintenance window must be
confirmed before cutover. Local success does not substitute for that acceptance.

## Sales CRM on SQLite before cutover

The CRM release adds nine migrations dated 24 November and 37 tables with the `crm_` or `recruit_` prefix or the name `email_suppressions`. Production conversion must start with a SQLite online backup taken **after** those migrations and the client import. The MySQL schema dump is a clean-install baseline. It includes the migration ledger, eight system event types and three unpublished starter drips with their 30 steps. It contains no contacts, applicants, quotes, messages, tokens or provider credentials.

1. On a rehearsal copy, identify the deployed backend commit and verify every CRM migration is applied using `artisan migrate:status`. Take a new SQLite online backup, verify integrity, preserve its SHA-256 and run the schema drift and `migrate.py plan` commands below. Include all 37 CRM tables plus the existing linked users, shoots and messaging tables in the new inventory. Count populated CRM rows and record issued IDs, opt-outs, explicit consent, live enrolments, queued sends, outstanding replies, public quotes and intake receipts.
2. Audit application-level references before import. CRM currently indexes many relationship columns without database foreign keys. Check contact-to-client and rep IDs, quote-to-shoot links, calendar links, message receipts, applicant documents and their parent records. Record every exception by stable row identity. Reconcile or explicitly account for each exception before using the fresh plan.
3. Run the documented empty-target import with the fresh snapshot and exception manifest, then the independent read-only PHP verifier for that exact snapshot. Require identical field hashes and counts, including every CRM table and the highest issued identity for each auto-increment column. Compare the audited application references again and exercise the CRM feature suite on MySQL with fake providers. For the import workflow, restore into a new isolated target and rerun `crm:import-clients --dry-run` against the copied data to confirm already-linked clients remain linked.
4. At the traffic handoff include the CRM scheduler (including website intake replay), workers, website intake, Resend inbound and Google Calendar changes in the existing writer freeze and replay accounting. Keep each provider idempotency key and each pending or uncertain send. Exercise representative CRM requests and webhook replays against the copy with external delivery disabled. A production cutover uses the separately reviewed full-site rehearsal and rollback procedure.

On the production application host, create a protected rehearsal snapshot and inventory its CRM references. This reads the live database through SQLite's online backup API. Use the new file as the source for the isolated target. Run the final frozen backup separately at cutover.

```bash
set -euo pipefail
umask 077
cd /var/www/backend
install -d -m 0700 "$HOME/mysql-rehearsals"
run_dir=$(mktemp -d "$HOME/mysql-rehearsals/crm-XXXXXXXX")
source_db=$(php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $db=$app["db"]->connection(); if ($db->getDriverName()!=="sqlite" || !is_file($db->getDatabaseName())) {exit(1);} echo realpath($db->getDatabaseName());')
php scripts/deploy/backup-sqlite.php "$source_db" "$run_dir/frozen.sqlite"
sha256sum "$run_dir/frozen.sqlite" | tee "$run_dir/frozen.sha256"
python3 - "$run_dir/frozen.sqlite" <<'PY'
import pathlib, sqlite3, sys
snapshot = pathlib.Path(sys.argv[1]).resolve(strict=True)
db = sqlite3.connect(snapshot.as_uri() + "?mode=ro&immutable=1", uri=True)
tables = sorted(row[0] for row in db.execute("SELECT name FROM sqlite_master WHERE type='table'")
                if row[0].startswith(("crm_", "recruit_")) or row[0] == "email_suppressions")
assert len(tables) >= 37, "Snapshot predates the complete CRM release"
for table in tables:
    name = '"' + table.replace('"', '""') + '"'
    print(table, db.execute("SELECT count(*) FROM " + name).fetchone()[0])
for source, column, parent in [
    ("crm_contacts", "client_user_id", "users"), ("crm_contacts", "assigned_rep_id", "users"),
    ("crm_quotes", "contact_id", "crm_contacts"), ("crm_quotes", "booking_shoot_id", "shoots"),
    ("crm_event_calendar_links", "event_id", "crm_events"), ("crm_sms_requests", "message_id", "messages"),
    ("recruit_applicant_files", "applicant_id", "recruit_applicants"),
]:
    query = f"SELECT count(*) FROM {source} c LEFT JOIN {parent} p ON p.id=c.{column} WHERE c.{column} IS NOT NULL AND p.id IS NULL"
    print(f"{source}.{column} missing parent", db.execute(query).fetchone()[0])
PY
```

On the isolated rehearsal host, set `snapshot` to the protected copy's absolute path. Check it against the source checksum from the previous command before planning. The following uses the rehearsal branch scripts.

```bash
snapshot=/protected/frozen.sqlite
snapshot_sha=$(sha256sum "$snapshot" | cut -d' ' -f1)
test "$snapshot_sha" = "$EXPECTED_SNAPSHOT_SHA256"
python3 scripts/mysql/migrate.py plan --snapshot "$snapshot" --snapshot-sha256 "$snapshot_sha" --output /protected/new-crm-plan
```

Review the new exceptions and schema plan. Use the `import-empty` and `verify-fields.php` commands below with the same snapshot and their independently checked fingerprints. Keep the full private reports with the rehearsal. Production customer records never belong in the schema dump.

To reproduce the small CRM conversion check, run `APP_ENV=testing php scripts/mysql/crm-rehearsal-fixture.php /tmp/crm-test.sqlite` with an unused filename. The fixture is created with mode 0600. Run the same checksum-bound plan, empty-target import and independent read as below against an isolated, socket-only MySQL 8.4.13 service. The fixture is synthetic and contains consent, a quote, Unicode JSON intake, an SMS request and the fixed campaign/event defaults. The November 24 run verified 37 tables, 48 rows and 462 fields with zero mismatches and zero MySQL warnings. Test the native Laravel migration path with `php vendor/bin/phpunit -c phpunit.mysql.xml tests/MySql/RecentFeatureMigrationCompatibilityTest.php` and `php vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/Crm` on the dedicated test database.

The release candidate containing backend 9487965492cc9b9575a951ff9a1e415986327b21 passed both suites on the restored MySQL baseline (100 tests, 861 assertions).

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
   binary manifest/version, server UUID, durable commit/binlog settings, strict mode,
   Unix-socket access as both `www-data` and `maverick`, account isolation,
   connection budget, automatic recovery, disk alerts, and off-host backups.
   Validate boot recovery on the actual host. The native service leaves the
   existing MySQL service and system client binaries in place.
4. Use the native bundle's matching 8.4 `mysql` and `mysqldump` by absolute path. Rehearse the candidate's
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
python scripts/mysql/schema-drift.py \
  --release /protected/release-schema.sqlite \
  --release-sha256 <verified-release-schema-file-sha256> \
  --release-commit <reviewed-backend-commit> \
  --snapshot /protected/frozen.sqlite \
  --snapshot-sha256 <verified-snapshot-sha256>
```

Build the release schema from the reviewed commit in an isolated SQLite
environment. Inspect the report and resolve or explicitly approve each
difference before signing the import layout. Preserve historical tables and
data unless a separate reviewed change makes a different disposition explicit.
The report reads metadata only and leaves both files intact.

```bash
python scripts/mysql/migrate.py plan \
  --snapshot /protected/frozen.sqlite \
  --snapshot-sha256 <verified-snapshot-sha256> \
  --output /protected/new-plan
```

Compare `exceptions-private.json` with the approved exception list. Preserve
history exactly; any additional exception requires review. Record its file hash.
Review the schema fingerprint and the checked-in type policy. The fingerprint
binds the conversion layout, while the census checks the actual values fit.
Only after confirming writer freeze, the empty target and independent UUID:

```bash
python scripts/mysql/migrate.py import-empty \
  --snapshot /protected/frozen.sqlite \
  --snapshot-sha256 <verified-snapshot-sha256> \
  --credentials /protected/maintenance.json \
  --database repro_dashboard \
  --expected-server-uuid <independently-observed-new-server-uuid> \
  --schema-sha256 <reviewed-schema-fingerprint> \
  --approved-exceptions /protected/new-plan/exceptions-private.json \
  --exceptions-file-sha256 <approved-exception-file-sha256> \
  --output /protected/new-import
```

The tool refuses a different patch version, wrong UUID, nonempty destination,
changed snapshot or changed exception set. It has no reset/drop-database path.
A failed import leaves a partial isolated target for investigation; never
automatically erase it or retry against a database that has received traffic.

The importer verifies every original row and field, restores the approved
legacy constraints with enforcement enabled, and installs the 25 direct
change-feed triggers. While writers remain stopped, run the independent PHP
reader against the imported schema before applying the reviewed repairs. Use a
separate read-only account and record the checksum of the exact schema-plan file
independently of its structural fingerprint.

```bash
php scripts/mysql/verify-fields.php \
  --snapshot=/protected/frozen.sqlite \
  --snapshot-sha256=<verified-snapshot-sha256> \
  --schema-plan=/protected/new-import/schema-plan.json \
  --schema-plan-sha256=<verified-schema-plan-file-sha256> \
  --credentials=/protected/mysql-parity-reader.json \
  --database=repro_dashboard \
  --expected-server-uuid=<independently-observed-new-server-uuid> \
  --writers-stopped
```

Require `all-fields-verified`, with zero mismatches and the expected table and
row totals. Both checks belong to the same immutable snapshot and target.
Apply the reviewed
application migrations in an isolated maintenance configuration, then always run
the guarded schema-completion tool to add the repairs, effective-period index
and MySQL cascade triggers, including iGUIDE project cleanup. SQLite can have
already recorded these migration names while skipping their MySQL-specific
work. The tool reapplies only the reviewed idempotent repairs and does not
delete or rewrite migration-ledger rows.

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

Use the [readiness receipt checker](../scripts/cutover/readiness/README.md) with
both timed dress-rehearsal reports and the independent off-site recovery report.
Review the original measurements along with the output. A passing receipt is
evidence for the operator's go/no-go review. Live writer fencing must pass an
actual restart test using the [admission guard](../scripts/cutover/fence/README.md)
and its privileged wrappers. The [Telnyx inbox](../scripts/cutover/inbox/README.md)
requires a reviewed ingress and replay integration before it can capture
events during maintenance.

The current deploy scripts check `/etc/repro-database-cutover/active`
after taking their individual backend and frontend deployment locks. The
privileged cutover controller must coordinate both locks when publishing or
clearing that marker. Its parent directory must let the deploy account check
the marker and allow writes only by root. A stale script or manual deployment
also needs the host-level permission fence.

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
the candidate's SQLite-compatible migrations. Record its checksum. Before the
outage, use `export-sqlite.py --check-inputs-only` with the four template and
exception-file flags below to validate the local recovery packet. This checks
the pinned template, exceptions and bundled cascade-trigger manifest without
connecting to MySQL. After stopping writers and reconciling external effects,
the reviewed reverse tool is:

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
field, application, and side-effect validation before connection activation.
Build a new schema plan with `migrate.py plan` against the checksum-pinned
exported SQLite file. Compare it against the frozen MySQL source with
`verify-fields.php` and dedicated SELECT-only credentials. When using the
reviewed six-column messaging overlay, pass `--reviewed-reverse-layout`.
This option expects the exact MySQL JSON, TEXT, and VARCHAR types from the
messaging repair and compares every value using the same field checks.

Repeat acceptance against the final candidate and target before planning a
connection switch.

The local forward import took 508.6 seconds plus 135.4 seconds for field-hash
verification, before further migrations and acceptance. Agree on a maintenance
window from an actual-host rehearsal; do not promise zero downtime from these
local measurements.
