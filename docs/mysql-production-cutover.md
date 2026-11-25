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

### CRM freeze and replay

Review the effective CRM flags from the cached configuration before each
rehearsal and before the cutover. Record booleans and whether the dedicated
Resend receiving credential is configured. Keep the credential, signing
secret and application encryption key in protected storage.

| Producer | Close and verify | Before releasing normal processing |
| --- | --- | --- |
| Staff, FPM and queued jobs | Close admission before Laravel. Include CRM staff routes, shoot observer writes, public quote views and opt-out actions. Hold and drain scheduler, the named `crm:website-intake` task, the four scheduled CRM Artisan commands, all workers and manual imports. | Reconcile the captured work against the authoritative engine. Keep campaign and drip claims held until replies, bounces and opt-outs are applied and uncertain sends have a reviewed disposition. |
| Website inquiries, bookings and careers | Preserve the producer's original reference and its durable retry or outbox record. Hold the recruiting outbox runner. Require a durable receipt before acknowledging any submission that cannot be retried. | Reapply the same reference, payload and recruiting attachments. Confirm one business effect per reference and reconcile any partial attachment replay. |
| Shared Resend webhook | Hold at ingress and return a retryable failure until an approved retry or capture path is proven. The edge snippet and `PRODUCER_INGRESS_HOLD` answer 503 with `Retry-After`. Turning off CRM leaves delivery events and public opt-outs able to write, so it is not a freeze control. Before the producer-replay lane it also acknowledged an inbound reply without processing it; `email.received` now gets 503. | Use fresh provider-signed redelivery under the original provider event identity (`svix-id`). The webhook applies it once and refuses a stale signature. Verify required received email content is recoverable. Check forwarding effects before retrying any ambiguous send. Not verified on Resend yet: that a dashboard replay keeps the `svix-id`, re-signs with a fresh timestamp, and retries long enough to cover a 60-minute hold. |
| Google Calendar | Hold the OAuth callback and poller. Preserve existing mappings and pending pushes. | Reconcile changes made directly in Calendar before releasing sync. Restart an expired OAuth flow from the application. |
| Aryeo API webhook (`/api/integrations/aryeo/webhooks/{installation}`) | Held with 503 and `Retry-After` at the edge and in the app. Signatures expire after 300 s, so only a fresh delivery from Aryeo can be accepted. Deduplicated by `event_id` with a body hash. | Aryeo retries or redelivers. The same event gives one `aryeo_api_webhooks` row and one `ReconcileAryeoWebhook` job (tested). Aryeo's retry window is not confirmed: ask Aryeo or read their docs before a real freeze. If Aryeo does not retry, held deliveries need a manual redelivery request. |

Maintain a private ledger of each original identity, payload or attachment
checksum, acknowledgement, replay disposition and verified effect. Include
only counts and digests in shared evidence. Test restart while sealed and
exercise both rollback paths. The authority journal must cross its durable
first-write boundary before permitting any MySQL replay that can write.

For website email, record the response body disposition as well as the
HTTP status. The API may return HTTP 200 with a failed or uncertain
delivery. Keep bookings without an original external reference in manual
reconciliation, since that route otherwise bypasses deduplication. For
recruiting, verify each replay attachment against its original SHA-256
before submission. The consumer can accept changed bytes as a different
file. Verify producer restart and retry of a fenced HTTP 503 before
using the website's outbox as an acceptance gate.

The reviewed website source at `4011d53a8d82af72ef4247af6034a0c0751cbe60`
has different recovery paths for the three producers. Confirm the actual
deployed revision and Supabase migration, scheduler and forwarding settings
before a timed rehearsal.

- Recruiting acquires durable leases and retries fenced 503s. Hold and drain
  the scheduler while preserving the existing enabled capture setting if it
  is active. Disabling that setting stops the insert trigger from capturing
  new applications. The retry worker reads the current application and
  forwards JSON fields. Save the original submitted fields and original
  attachment bytes under access controls so an edit cannot change the
  replay behind the same reference. Route any mismatch to review.
- Booking can retry with the original website UUID and saved quote. Include
  a durable recovery path for pending bookings after the browser session is
  gone. A fenced catalog lookup may leave a booking saved without a frozen
  quote or a downstream POST. Resolve those cases before constructing a
  replay. Record the original payload, the downstream booking disposition,
  and whether a confirmation was sent before replaying an uncertain outcome.
- Contact saves the inquiry with a reference and preserves an uncertain
  forwarding disposition. Reconcile and replay it through an approved
  operator path using the same reference. The website does not automatically
  replay these submissions.

For resolved website replays, compare two independently pinned ledgers with
`python -B -m scripts.cutover.verify_website_replay`. The capture and replay
files share `site_commit` and `freeze_sha256` at the top level. Use schemas
`repro.website-capture.v1` and `repro.website-replay.v1` respectively. Each
`rows` entry includes `producer`, `reference_sha256`, `payload_sha256`, and
the ordered `attachment_sha256s`. The replay entry adds the independently
observed `verified_business_effects` count. Hash the same original request
encoding in both files. Preserve the raw materials separately under the
private replay controls. Compare by producer and original reference. Any
missing row, byte difference or multiple effect requires reconciliation.
This offline comparison assumes an independently verified producer capture
inventory and does not close the live ingress gate.

During the approved reconciliation window, verify
`CRM_DEFER_REPLY_FORWARDING=true` in the effective configuration of the
FPM instances permitted to replay. A verified reply can be fetched and
recorded, stop its drip and create a notice while forwarding stays held.
Record the original event identity in the private ledger. After outbound
release is approved, restore the reviewed false setting and request fresh
signed replay for those recorded events. Check one forward per original
send and received email ID. An uncertain forward requires a provider
disposition before any further attempt. This setting controls reply
forwarding. The separate worker and ingress fence controls other sends.
Step 8 of [Freeze and replay steps](#freeze-and-replay-steps) restores it
before `CRM_OUTBOUND_HOLD` is released, because the release check waits for
those forwards.

[CRM relationship audit](../scripts/mysql/crm-snapshot-audit.py) compares the
immutable source and the independently verified SQLite reverse export using
the same private HMAC key. [Applicant file verifier](../scripts/mysql/verify-crm-applicant-files.py)
checks metadata against files staged under a private local disk root. Pair
it with application authorization tests and an actual attachment recovery
receipt. Include these, the real client-import dry-run comparison, and the
replay identity checks in the [nightly evidence](../scripts/cutover/readiness/README.md#assemble-five-nightly-parity-reports).

#### Freeze and replay steps

From the producer-replay lane. Every flag here is off by default. Set it only
inside an approved window, then rebuild the config cache with
`php artisan config:cache`. Do not clear the application cache: it holds the
queue pause flags. Each step that changes production needs its own approval.
The times and the hold are the ones in [Freeze all writers](#freeze-all-writers).

Record the freeze start time in UTC as `FREEZE_AT` before step 1. It comes
before the hold's `started_at`, which starts the outage clock. The checks
below use `FREEZE_AT`, so they also cover the steps before the hold.

**Before the hold** (after the 9:20 pm long-job pause, before the 9:50 pm
preflight)

1. Record and pause the Aryeo agent:
   `php artisan aryeo:connection "<name>" --record-state=/private/aryeo-<date>.json --pause`.
   The file is new, mode 0600, holds no secret, and records `enabled`,
   `processing_enabled`, `discover_all_clients`, `delivery_shoot_ids` and
   `client_ids` with a digest. The pause turns off job claims (so no Aryeo
   action starts) and email-less discovery. Like `--disable-processing`, it
   leaves request sync on: heartbeats, discovery with a requester email,
   inventory and provider status still write. The agent re-sends those, and
   they are safe before the copy. During the hold, admission closed before
   Laravel stops every HTTP write, these included. Then check that no Aryeo
   job is still leased (Tours panel).
2. Set `CRM_OUTBOUND_HOLD=true` and `CRM_DEFER_REPLY_FORWARDING=true`, rebuild
   the config cache, and confirm both booleans in the effective config.
3. Hold the website recruiting scheduler with the `worker_paused` switch from
   website change P2-05 ([spec](mysql-lanes/producer-replay.md#p2-05-a-scheduler-hold-that-keeps-capture-on);
   not built yet). Do not turn off `recruiting_forwarding_settings.enabled`:
   that stops capture.

**During the hold**

4. Right after the approved hold, hold producers at the edge: copy
   [`scripts/cutover/fence/producer-hold.nginx.conf`](../scripts/cutover/fence/producer-hold.nginx.conf)
   into `/etc/nginx/repro-holds/`, run `nginx -t`, reload. Each Dashboard
   server block (`frontend.conf` and `laravel.conf`) must already carry
   `include /etc/nginx/repro-holds/*.conf;` once (see
   [Release and target preparation](#release-and-target-preparation)).
   Optionally also set `PRODUCER_INGRESS_HOLD=true` as the in-app second
   layer. Never use `CRM_ENABLED=false` as the freeze control; it is now safe
   for producers but it also stops reply processing and intake processing.
5. While the writer hold is in force, it answers every path with 503 at
   server level, so it hides the edge snippet. After reopening and before step 6,
   check that every held path answers 503 with `Retry-After` (never 403, 404
   or a redirect; the website treats 3xx and 4xx other than 408, 425 and 429
   as permanent).

**Release, after reopening, in this order**

6. Remove the nginx hold file, `nginx -t`, reload; set
   `PRODUCER_INGRESS_HOLD=false`. The website outbox, booking forwarder,
   Resend, Stripe and Aryeo retry on their own.
7. Website ledger. Export the website rows read-only
   ([export spec](mysql-lanes/producer-replay.md#p2-06-website-export-for-the-operator-ledger);
   the website side is not built yet) and run
   `python -B -m scripts.cutover.website_ledger --export <export.json> --capture <capture.json> --private-csv <private.csv> --references <refs.json>`.
   Then `php artisan crm:website-ledger --connection=<read-only> --references=<refs.json> --json`
   (or `--sqlite=<copy>`). Replay only through the same reference:
   `not_in_dashboard` bookings through forward-booking-v2 with the frozen
   quote; `not_received` and `not_delivered` contacts through website-email
   with the same reference; `uncertain` contacts only after the provider log
   shows the outcome. A person handles `submission_without_shoot`,
   `other_source_only` (the reference belongs only to another integration's
   booking; bookings match on source `reprophotos.com` and the reference) and
   every `manual_reconciliation` entry. An unreadable `--since` exits 2. Each
   replay POST needs approval per reference. Compare the capture and replay
   ledgers with `verify_website_replay`.
8. Resend. In the Resend dashboard, replay the failed messages of the window
   (approval per batch). The webhook accepts a fresh signature with the
   original `svix-id` and applies it once; a stale signature is refused.
   Then set `CRM_DEFER_REPLY_FORWARDING=false` and request the replay of the
   recorded replies, so each is forwarded once
   (`crm-reply-fwd-{send}-{md5(email_id)}`). Forwards are transactional and
   are not blocked by the outbound hold. This comes before the outbound
   release in step 9, because the release check counts a reply as settled
   only once its forward is sent or reviewed.
9. `php artisan crm:outbound-release-check --since=$FREEZE_AT`. It is
   read-only and exits 1 while any of these is non-zero: replies not yet
   forwarded; permanent bounces or complaints without a suppression;
   uncertain or queued sends without a reviewed disposition; due drip steps
   that would reach someone who replied, bounced or opted out. For each
   uncertain send, check the provider log and run
   `php artisan crm:send-disposition <send-id> delivered|not_delivered|resend_approved|abandoned --note="..."`.
   A reply counts as forwarded only while its forward send (`reply_fwd`) has
   status `sent`. A forward that failed, was blocked, was only logged or is
   still queued stays blocking until you check that the rep has the reply
   (Conversations shows it) and record a disposition on the forward send. A
   reply with no forward send at all (no rep, or no valid address) takes the
   disposition on the send it answered. When it exits 0, set
   `CRM_OUTBOUND_HOLD=false`.
10. `php artisan crm:calendar-reconcile --dry-run --json`. It reads Google
    and writes nothing. Connections whose token needs a refresh are listed as
    `token_refresh_needed`; add `--allow-token-refresh` only if approved, as a
    refresh saves the new token. Decide each `moved_in_google`,
    `deleted_in_google`, `push_pending` (a pending push overwrites Google),
    `unconfirmed_create`, `cancelled_still_in_google`, `remote_without_time`
    and `error` row. `unconfirmed_create` is a pending push with no Google id:
    an earlier create may have reached Google with its response lost, and the
    release then patches that event with the CRM time (a planned follow-up)
    or leaves it in Google (a cancelled one). Look in the rep's calendar at
    `local_starts_at` and match the CRM to Google before release. It exits 1
    until none is left. `closed_locally` (done or missed follow-ups, which the
    sync leaves in Google on purpose) and `no_connection` (the rep
    disconnected Google) are listed for information and do not fail it. Then
    release `crm:calendar-sync`.
    Open: no lane says how `crm:calendar-sync` is held until this step. The
    scheduler starts again at reopening and runs it every five minutes.
    `CRM_GOOGLE_SYNC=false` switches it off, but no lane tested that as the
    hold.
11. Stripe at +30 minutes and +2 hours:
    `php artisan stripe:undelivered-events --since=$FREEZE_AT --json` and the
    same with `--account=listing-studio`. They use the restricted Events-read
    keys `STRIPE_EVENTS_READ_KEY` and `LISTING_STUDIO_STRIPE_EVENTS_READ_KEY`,
    never the secret key. Exit 1 means something is still pending; resend it
    from the Stripe dashboard with approval. A repeated event is harmless
    (tested 5 times on both endpoints).
12. Resume Aryeo last:
    `php artisan aryeo:connection "<name>" --resume-processing=/private/aryeo-<date>.json`.
    It refuses a file for another connection or an edited file, prints what
    it changed, and the agent should be seen again within a minute. It turns
    back on only what the pause turned off. If anything else changed after
    the recording (a credential revoked, client or shoot scope narrowed), it
    refuses, names the settings and changes nothing. Settle those by hand.

The producer hold does not cover Telnyx (its own capture route), iGUIDE
(`/iguide_webhook.php`), CubiCasa (`/cubicasa_webhook.php`), Cakemail
(`/api/webhooks/cakemail`), Autoenhance (`webhooks/autoenhance`) or Vapi
(`/api/webhooks/vapi`). During the hold the writer hold answers all of them
503 at server level, including the two direct `.php` files.
Open: no lane has confirmed that these six providers retry a 503, or that
dropping their events during the hold is safe.

The edge snippet answers 503 for everything under `/api/external/`, which is
wider than `PRODUCER_INGRESS_HOLD`: the catalog read, `check-client` and the
website's Listing Studio guest-trial bridge are held too. Confirm the
guest-trial flow shows a retry message on 503, or narrow the snippet to the
producer paths, before a real freeze. The snippet's `Retry-After` is fixed at
60; edit it if `PRODUCER_INGRESS_RETRY_AFTER` changes.

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
4. Provision the writer token on every fresh, imported or restored MySQL
   target, before any application process connects. The writer policy is on
   by default for MySQL (`REPRO_MYSQL_WRITER_POLICY`). The token is outside
   the application schema, so import, verify and reverse export do not see it.

   ```sql
   CREATE DATABASE repro_mysql_control CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
   CREATE TABLE repro_mysql_control.writer_token (
     id TINYINT UNSIGNED NOT NULL PRIMARY KEY CHECK (id = 1),
     application_database VARBINARY(64) NOT NULL,
     contract_version INT UNSIGNED NOT NULL
   ) ENGINE=InnoDB;
   INSERT INTO repro_mysql_control.writer_token VALUES (1, '<application database>', 1);
   GRANT SELECT, UPDATE(id) ON repro_mysql_control.writer_token TO '<app account>'@'localhost';
   ```

   Grant it to every account that writes through the application's `mysql`
   connection, including the one `php artisan migrate` uses: the migrations
   ledger is an ordinary write and waits for the token. Without the row every
   write stops. That is deliberate. Add the row to the restore and failback
   checklists, and provision it before any tool that boots the application
   against MySQL writes (rehearsal verifiers that only read are unaffected).
5. The application sets READ COMMITTED and 5-second lock waits per session.
   The server file may keep its own defaults, but the migrator account should
   use a separate connection if layout changes need a longer metadata wait.
   Each request that takes a database cache lock opens a second MySQL session
   (`mysql_cache_locks`); size `max_connections` for that.
6. Give the off-site alert probe's monitor account `PROCESS` and `SELECT ON
   performance_schema.data_locks` (see [Alerts](#alerts-b6-b7)), and add
   `writer_token_held_too_long` to the probe plan's conditions with its
   `mysql` monitor section. This daytime alert fails when a session has held
   the writer token for 30 s or more (`max_hold_seconds` in
   `alert-conditions.json`). It runs the query of `php artisan
   db:writer-token-holders` through the monitor's own socket login, so the
   alert account never gets the app's database password, and only while the
   cutover journal makes MySQL authoritative. On MySQL 8.4.13 these two
   grants were enough: a 34 s holder was reported, and without either grant
   the query was refused. To see which sessions hold the token, run
   `db:writer-token-holders` as an administrator.
7. Use the native bundle's matching 8.4 `mysql` and `mysqldump` by absolute path. Rehearse the candidate's
   `scripts/deploy/backup-database.php` and an independent full restore on the
   actual target. A successful dump alone is insufficient.
8. Rehearse the whole sequence using a fresh read-only online SQLite backup.
   Preserve the original APP_KEY, provider ciphertexts, IDs, media references,
   queue payloads, timezones, same-origin URLs, and storage permissions. No
   historical financial/audit record is deleted or assigned an invented parent.
   Compare the fresh exact exception identities with the approved history.
9. Review provider retry/reconciliation procedures for the maintenance window.
   Verify offline webhook replay/idempotency and queue contracts. Real provider
   calls, messages, payments and physical-device acceptance require a designated
   sandbox/device or explicit authorization; mocks and viewport emulation are
   labeled as such in the readiness report.
10. Deploy the reviewed candidate, and add the producer-hold line
    `include /etc/nginx/repro-holds/*.conf;` once to both Dashboard server
    blocks (`frontend.conf` and `laravel.conf`), in normal reviewed deploys
    before installing the writer hold (W1). From `cli.py init` the deploy marker
    holds every deploy through the planned observation (C1) and the cutover,
    and the policy pins that release. W1 installs the guard, controller,
    wrappers, watchdog units, the `tmpfiles.d` entry, the nginx include,
    sudoers, Supervisor and cron changes
    ([production README](../scripts/cutover/production/README.md)). Reconcile
    the Supervisor template with the live `laravel-worker.conf` and the user
    `supervisord.conf` first. The nginx include holds the site until the open
    marker exists, and `cli.py init` needs a live SQLite route: create the
    marker by hand, run `init`, remove the marker, then `refresh` and
    `reopen`. Then observe 24 to 48 hours in `planned` (C1).

## Off-site backups and alerts

From the off-site backups lane (B5 to B9). Nothing here is installed on
Maverick. The tools are in [`scripts/mysql/offsite`](../scripts/mysql/offsite/README.md).
The evidence is in the
[readiness record](mysql-production-readiness.md#off-site-backups-and-alerts).

### Owner checklist: bucket, two keys, two passphrases

This replaces steps B.2 and C of the week-1 checklist. The old step asked for
one "Object Read & Write" key. That key can delete, and the same key would sit
on the server and on the restore machine. The design needs two separate keys.

Do these in the Cloudflare dashboard and the password manager. Never paste a
key, secret or passphrase into a chat. Sessions only ever need the bucket
name, the account ID and the key fingerprints the tools print.

1. **Bucket.** In **R2 Object Storage**, **Create bucket** named
   `repro-db-backups`, automatic location, default storage class. In its
   **Settings**, keep **Public access** off: no r2.dev URL and no custom
   domain. Save the bucket name and the S3 address
   `https://<account id>.r2.cloudflarestorage.com` in the password manager.
   Add no lock or clean-up rules yet.
2. **Read-only restore key.** **R2**, **Manage API tokens**, **Create API
   token**. Name it `repro-db-backups restore (read-only)`, permission
   **Object Read only**, applied to the specific bucket `repro-db-backups`.
   TTL forever, or a date you will remember to renew. Save the Access Key ID
   and Secret Access Key as "ReProDashboard backups: restore key
   (read-only)"; the secret is shown once. This key belongs only on the
   recovery machine. Never type it on the server; the server's config tool
   refuses it there by design.
3. **Upload key for the server.** The server must write new backups but
   should not be able to delete them. R2's dashboard tokens offer read,
   read-and-write or admin; none is "write but not delete". Choose one:
   - **A. Upload-only derived credential (the installer's default).** Create
     a bucket-scoped **Object Read & Write** token as a parent and keep it
     only in the password manager, never on the server. From it, sign a
     short-lived child credential with object read and write actions and no
     delete, as in the 24 November retention trial. Type the child's key ID,
     secret and session token at the server's no-echo prompt. It expires, so
     renew it on a calendar. The signing helper and the exact action list
     rclone needs for multipart uploads are not in the repo yet; they need one
     approved test against R2 before you rely on this option.
   - **B. Bucket-scoped read-and-write key on the server.** **Object Read &
     Write** for `repro-db-backups` only. It does not expire, so nightly
     backups never stop because of it. It can delete objects whose lock
     period has passed. Inside the lock period the bucket refuses its deletes
     and overwrites (the trial saw `409 ObjectLockedByBucketPolicy`). Set
     `"upload_credential": "scoped-read-write"` in the installer settings to
     choose it.

   Either way, name the token `repro-db-backups upload (server)`, save its
   values as "ReProDashboard backups: upload key (server)", and keep any admin
   token off both machines.
4. **Lock and clean-up rules**, after the installer preview. The preview
   prints them again with the JSON the retention check reads. In the bucket's
   **Settings**:

   | Prefix | Bucket lock rule | Object lifecycle rule |
   | --- | --- | --- |
   | `daily/` | 30 days | delete after 31 days |
   | `binlog/` | 30 days | delete after 31 days |
   | `monthly/` | 365 days | delete after 366 days |
   | `final-sqlite/` | 365 days | delete after 366 days |
   | (all) | none | keep the default 7-day multipart abort rule |

   Rules must name the visible outer prefix; names below it are encrypted.
5. **Two passphrases.** In the password manager, create two long random
   passphrases (five or more words, or 30 or more characters each), labelled
   "ReProDashboard backups: passphrase 1" and "passphrase 2". Every restore
   needs both. They must not exist only on the server. Type them at the
   installer's no-echo prompt on the server and again at the restore
   machine's prompt.

### Install the backup host (B5)

The installer follows the `install-download-tcp.py` style: preview by
default, `--apply` and `--undo` with sudo. Copy the reviewed bundle to a
root-owned folder first; apply refuses a bundle another account could change.
It checks that every folder on the way to every source file in the bundle is
root-owned and not group- or other-writable.

```sh
sudo install -d -o root -g root -m 0755 /opt/repro-install-bundle
sudo cp -r --no-preserve=ownership scripts /opt/repro-install-bundle/   # from the reviewed commit
sudo chmod -R go-w /opt/repro-install-bundle
B=/opt/repro-install-bundle/scripts/mysql/offsite/install_backup_host.py
python3 -I -B $B --job base-backup --settings settings.json          # preview, as the deploy user
sudo python3 -I -B $B --job base-backup --settings settings.json --apply
sudo python3 -I -B $B --job alerts --settings settings.json --apply
sudo python3 -I -B $B --job recovery-kit --settings settings.json --apply
sudo python3 -I -B $B --undo --job recovery-kit                      # undo in reverse order
```

`settings.json` holds only non-secret values:

```json
{"version": 1, "release": "<live backend commit, 40 hex>", "identity": "<approved SQLite lineage id>",
 "bucket": "repro-db-backups", "endpoint": "https://<account id>.r2.cloudflarestorage.com",
 "php": "/usr/bin/php8.3", "upload_credential": "derived-no-delete"}
```

Optional keys and defaults: `app_root` `/var/www/backend`, `sqlite_database`
`/var/www/backend/database/database.sqlite`, `rclone` `/usr/bin/rclone`,
`alert_fail_suffix` `/fail` (use `null` for a provider with no fail
endpoint), `probe_conditions`, `access_logs` and `disks`.

What apply does for `base-backup`:

- Creates the system account `repro-backup` (no login, no home).
- Installs `/opt/repro-backup-tools/*` root 0444, `/etc/repro-backup/plan.json`
  root:repro-backup 0640 and `/var/lib/repro-backup` 0700.
- Adds read-only POSIX ACL entries: `.env`; the database folder (r-x) with a
  default entry so new `-wal` and `-shm` files are readable; the database
  file, `-wal` and `-shm`; and `--x` on any parent the account cannot
  traverse. Nothing is made writable. With the default entry, the umask no
  longer applies to new entries in the database folder. Only a file opened
  with mode 0666 under a umask (PHP `fopen` under PHP-FPM's 0022) comes out
  0664 instead of 0644, never wider than the folder itself. The deploy's
  `rsync`, explicit modes and SQLite files are unchanged.
- Installs the five base units disabled and runs `daemon-reload`.
- Runs `enter_r2_config.py --role upload --prefix daily` as `repro-backup` for
  the no-echo prompts, unless `--skip-credentials`.
- Self-tests and prints PASS, FAIL or TODO per line: every file's bytes, owner
  and mode; the account; `systemd-analyze verify`; timers not enabled; the
  producer's reads as `repro-backup` inside the unit's sandbox; that the
  account cannot write the database folder; and whether credentials were
  entered (`rclone-daily.conf`; for `alerts`, `ping-urls.json` as
  `repro-alert`). If the no-echo entry fails, or its file is not there after
  an entry in this run, that line is FAIL and apply exits 1; enter the values
  with the printed command and run apply again. A later apply that finds the
  values already readable by their account does not run the create-only entry
  again. With `--skip-credentials` the line is TODO and apply exits 0.

Apply is idempotent and never overwrites a file it did not install or one
that changed after install. Re-running it repairs a lost ACL. It refuses to
widen an ACL mask that was narrowed on purpose, because that would give the
owning group its rights back; review such a path by hand. Undo disables the
units, restores the exact previous ACL bytes (and, where there was no ACL
before, the group bits of the mode), removes only unchanged installed files,
removes created folders and accounts, and reloads systemd. The record lives
in `/var/lib/repro-backup-installer/`.

Enable timers only after review:
`systemctl enable --now repro-backup.timer repro-backup-watchdog.timer`, then
the alert timers. Install `alerts` right after `base-backup`; until then the
backup units' bridge targets do not exist and only the journal marker is
written.

The installer does not show APP_KEY. Copy the `APP_KEY=` line from `.env`
into the password manager yourself (checklist E.3), on the server, not
through a session.

`Deploy-App.ps1` rewrites `.env` by rename when it stores a new Stripe
webhook secret. That drops the ACL: the next backup and the kit fail loudly,
and the probe reports `wrong_database_engine` within minutes. Re-run the
base-backup apply to repair.

### Alerts (B6, B7)

One bridge account, `repro-alert`, is the only one with IP networking. It
holds the ping URLs in `/var/lib/repro-alert/ping-urls.json` (0600), entered
with `alert_bridge.py enter-urls`. Output, errors and the journal never
contain a URL. `forward` sends every condition's ping at once, so one slow or
unreachable endpoint cannot use up the unit's 45 s limit.

| Condition | Class | Sent by | How to induce it for a round |
| --- | --- | --- | --- |
| server_silent | wake | ok ping every minute (`repro-alert-heartbeat.timer`); provider alerts after 5 min of silence | stop the heartbeat timer |
| backup_stale | daytime | watchdog result (OnSuccess / OnFailure) | point the plan at a stale receipt on a test host |
| backup_failed (extra) | daytime | nightly backup and kit result | run the backup with a missing rclone config |
| database_down | wake | probe | rename a fixture database / stop the rehearsal MySQL |
| wrong_database_engine | wake | probe (cached config's default driver, else `.env` DB_CONNECTION, vs the engine the cutover journal makes authoritative; the plan's value only with no writer hold) | set the probe plan's expected engine to the other one |
| site_unreachable | wake | provider's website check | provider-side check against a closed URL |
| disk_nearly_full | wake | probe (10% or 20 GiB free; mounts checked) | probe a fixture path or an unmounted path |
| backup_lag | wake | probe (newest archived binlog, the one before the checkpoint's `next_file`, last written over 300 s ago, two checks; needs `binlog_dir`) | pause the archive timer |
| server_error_rate | wake | probe (5xx over 2% in 30 min, at least 50 requests; reads `access.log.1` too) | fixture access log |
| historical_money_totals_changed | wake | probe (reviewed SELECTs vs baseline digest) | change a row on the rehearsal copy |
| rehearsal_failed | daytime | probe (newest nightly receipt red or older than 36 h) | fixture receipt |
| restore_overdue | daytime | probe (newest restore-test receipt older than 8 days) | fixture receipt |
| database_contention | daytime | probe (InnoDB deadlocks plus lock timeouts over 5 an hour) | two sessions deadlocking on the rehearsal MySQL |
| mysql_restarted | daytime | probe (uptime under 15 min) | restart the rehearsal MySQL |
| queue_stale | daytime | probe (oldest waiting job over 10 min; a reservation older than 7,260 s, the longest `retry_after`, counts as waiting since then) | insert a delayed job on a copy |
| cache_write_failed | daytime | probe (cache root or a cache folder not group-writable, or a cache write error in the app log) | `chmod g-w` a fixture cache folder |
| site_held (extra) | wake | probe (nginx open marker missing outside an approved cutover hold of this boot) | remove the open marker on a test host with a `planned` journal |
| writer_token_held_too_long (extra) | daytime | probe, MySQL authoritative only (a session held the writer token 30 s or more) | hold the token in one session on the rehearsal MySQL |

The two extra conditions are outside the readiness gate's fifteen cases.
Adding them to an alert round is an owner decision.

Provider setup: one check per row, period and grace from
`alert-conditions.json`; for wake-me checks a 15-minute repeat and the second
contact. The default probe plan enables the six conditions that work on
SQLite today and `site_held`. MySQL conditions need the `mysql` monitor
section pointed at the rehearsal instance (owner decision on round 1). The
money check needs reviewed queries and a baseline from
`alert_probe.py money-digest`. The MySQL monitor
account needs `PROCESS` (MySQL 8.4.13 refuses `INNODB_METRICS` without it) and
`SELECT` on the app's `jobs` table and the money query tables, plus `SELECT`
on `performance_schema.data_locks` for `writer_token_held_too_long`. Without
`PROCESS`, `database_contention` fails every minute.

`backup_lag` measures the recovery point: the checkpoint's `next_file` says
every earlier log is published, and the newest of those was last written by
MySQL when it rotated away from it. The probe plan needs
`binlog_checkpoint_file` and `binlog_dir`.

`wrong_database_engine` takes the default connection's driver from
`bootstrap/cache/config.php` first (a `DB_URL` scheme wins, as in Laravel),
because `Deploy-App` caches the config. With no cached config it reads
`DB_CONNECTION` in `.env`. It is a config check only: it does not see a
long-lived worker on the old engine. A probe that cannot read `.env` or the
cached config reports it as failed, a wake alert.

**Cutover night and held sites.** The probe reads the cutover journal
through `repro-alert-cutover-view.service`, which the `alerts` job installs.
That unit runs as root with no capabilities, so it can read the root-private
journal folder and nothing else, and it has no network. It writes the run,
phase, hold start and hold boot to `/var/lib/repro-alert-cutover/view.json`
for the probe, and never locks or writes the journal. The probe also checks
the nginx open marker. The limits are the plan's: 45 minutes from the hold
in a held phase, 60 minutes of write outage after it (`cutover_hold` in
`alert-conditions.json`).

- Inside an approved hold of the current boot and within those limits, the
  hold's own 503s do not count for `server_error_rate`, `site_held` reports
  the hold as expected, and a switch of the app between SQLite and MySQL
  does not page `wrong_database_engine`. Other errors still count.
- `site_held` pages for every other held site: past a limit, a hold that
  survived a reboot, a held site with no or an unreadable journal, or a
  missing, stale or other-boot view.
- `wrong_database_engine` and the database reads of `database_down`,
  `queue_stale` and the money check follow the engine the journal makes
  authoritative: SQLite until the promotion, MySQL after it. A missing
  journal on a host with the writer hold, an unreadable journal or
  `recovery_required` pages.
- `site_unreachable` comes from the provider and cannot be silenced from
  the host. `/up` stays behind the nginx hold. In this release `GET /up` runs
  no query: it answered 200 with the database file missing. An exempt `/up`
  would show the site as up while every other request gets 503, which hides
  the held site this check must catch. The gate allows no exemption without
  its own review (`repro-cutover-gate.conf`), and the freeze drains PHP-FPM
  anyway. So the owner pauses that one check at the provider for the hold
  ([Freeze all writers](#freeze-all-writers) step 4). Every other check
  stays on, and `site_held` pages if the hold runs past its limits.
- `queue_stale` (daytime) can still fire from the 9:20 pm queue pause.

### Timers and the monthly reboot

| Timer | Schedule (UTC) | Catches up after downtime |
| --- | --- | --- |
| repro-backup | daily 03:30, up to 5 min jitter | yes, once, after network-online |
| repro-backup-watchdog | 5 min after boot, then 5 min after each check | n/a (monotonic) |
| repro-recovery-kit | daily 04:15, and on every `.env` change | yes |
| repro-alert-heartbeat / probe / forward | every minute at :00, :15, :40 | no |
| repro-mysql-heartbeat | every minute at :05 | no |
| repro-binlog-rotate | every 2 min at :00 | no |
| repro-binlog-archive (catch-up) | every 2 min at :30 on odd minutes | no |
| repro-binlog-purge | hourly at :17 | no |

The backup moved from 02:30 to 03:30 UTC. That keeps 90 minutes clear of the
monthly 02:00 reboot on the 28th whatever that reboot's time zone is (UTC, US
or IST). The 26-hour freshness limit still covers 5 minutes of jitter and a
30-minute run. Minute timers never catch up: a missed minute is what the
provider should see. A reboot longer than the 5-minute silence grace will
page; measure the reboot once and raise the grace only if it is needed and
still fits the 10-minute delivery limit.

Cutover night: 03:30 UTC is 10:30 pm US Eastern in winter (EST), inside the
planned Saturday 10 to 11 pm Eastern outage. 02:30 UTC (9:30 pm EST) would
also run into it. Hold `repro-backup.timer` and `repro-recovery-kit.timer`
that night and during drills, and list them as "nothing due"
([Freeze all writers](#freeze-all-writers) step 4), or the owner picks
another time.

Once the writer hold is installed (W1), any reboot, the monthly one
included, leaves the site and every writer held until an operator runs
`cli.py refresh` and `cli.py reopen`. That fails closed by design. The
alert timers start by themselves after the boot, and `site_held` (wake)
pages within minutes with "site held after a reboot or an unfinished
reopen: run cli.py refresh, then cli.py reopen". The provider shows only
the check name, so name that check with the remedy. Plan on-call cover for
the 02:00 reboot on the 28th, or schedule around it.

### MySQL leg (B8)

Units: `repro-mysql-heartbeat`, `repro-binlog-rotate` (OnSuccess and
OnFailure start the archive), `repro-binlog-archive` (also on its own
catch-up timer) and `repro-binlog-purge`. Rotation and heartbeat run as
`repro-binlog` with no network and no data access. Archive and purge run as
`repro-backup`.

`purge_archived_binlogs.py` runs `PURGE BINARY LOGS TO` only up to the first
file that is not yet archived (from the archive checkpoint), is younger than
the local retention (default 3 days), is among the newest files kept locally,
or is the active file. With fewer closed files than `keep_files`, it keeps
them all. It checks the purge took effect. Grant its MySQL account
`BINLOG_ADMIN` and `REPLICATION CLIENT` only.

`install_backup_host.py --job mysql-recovery` is preview only. Before it can
apply, these are needed: the owner's decision on B1 e; MySQL accounts and the
`repro_archive_control` procedure ([heartbeat](../scripts/mysql/offsite/heartbeat.md),
[rotation](../scripts/mysql/offsite/rotate_closed_binlogs.md)) plus the purge
account; a `repro-binlog-plan` group for the shared collector plan; traverse
on `/run/repro-mysql84-native` for `repro-backup` and `repro-binlog` (today
the native unit grants it to www-data only); read on the binlog files for
`repro-backup` (binlogs live in the data folder, so a separate binlog folder
would be cleaner); a `binlog` prefix config; and reviewed plan pins rendered
into the four `REVIEWED_*_SHA256` placeholders.

### Recovery kit (B8)

`produce_recovery_kit.py` copies `.env` (with APP_KEY) and any listed MySQL
files into a private batch with a `kit.json` hash list. It publishes it under
the application scope when the files change, and again once the last
publication is seven days old, because the kit lives under `daily/`, which
the bucket rule deletes after 31 days. It refuses an `.env` without a usable
APP_KEY and prints names, sizes and hashes only. On the recovery machine,
after `restore_backup.py --scope application`:

```sh
php scripts/mysql/offsite/verify_recovery_kit.php RESTORE_OUTPUT CIPHERTEXT_FILE EXPECTED_PLAINTEXT_SHA256
```

It checks the kit's hashes and decrypts one stored Laravel value (CBC or GCM)
with the recovered key, printing only the plaintext hash.

### Seven-day observer and drill runner (B9)

Run on the recovery machine (Linux, WSL or a container) with the read-only
key:

```sh
python3 -I -B observe_backup_health.py observe --plan observer.json      # every minute
python3 -I -B observe_backup_health.py export --plan observer.json --window-end END \
  --identity-sha256 H --destination-sha256 H --monitor-profile-sha256 H --output backup-health.jsonl
python -B -m scripts.cutover.readiness.summarize_backup_health --policy review-policy.json --observations backup-health.jsonl
```

Use `interval_s` 60. The summarizer credits a slot only while lag stays
within 300 s, using the value seen at the slot start. Recoverable-through is
the time of the Rotate event that closed the last log of the newest
contiguous binlog batch from the newest base, never the batch's seal time. A
base or batch counts for a slot only if its completion `verified_at` is at or
before the slot start. SQLite alone has no binlog chain, so the lag rule
cannot pass before the MySQL leg runs.

A failed run (network, rclone timeout, the machine asleep) leaves its minute
without a row if nothing retries it, and `export` then refuses the whole
window. So run `observe` every 20 s (for example a systemd timer at
`*:*:05,25,45`): a run for a slot already recorded returns at once without
touching the bucket. Run it on a machine that does not sleep, and check the
journal daily.

```sh
python3 -I -B run_pitr_drill.py --plan drill-a.json --output drill-a
```

The drill plan names the base and binlog publications (IDs, sizes, digests),
the stop coordinate (end of the chosen commit), the target UTC second, a
check for the chosen row and, for drill 2, a check that the planted row is
absent. The runner creates the plan's `database` on the fresh target
(refused if it already exists) and loads into it. The plan's
`timeout_seconds` bounds the whole load; a target that stops reading is
killed at the deadline and the drill records `load_base` as failed. A
restored target that the application will write needs the writer token
first ([Release and target preparation](#release-and-target-preparation)
step 4).

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

The approved hold replaces the old order (drain, then pause, then stop, with
no journal). The controller, wrappers, watchdog units and nginx hold are in
[`scripts/cutover/production`](../scripts/cutover/production/README.md); the
state machine is described in
[hold first, then drain, then freeze](../scripts/cutover/README.md#hold-first-then-drain-then-freeze).
They are built and tested locally only. Installing them (W1) and a drill with
real services and a reboot (W2) each need their own approval. Keep webhook
failures recoverable; do not acknowledge events and drop them.

**Before the hold, no outage.**

1. 7:45 pm: pause Studio with its documented pause file; let its job finish.
2. 9:20 pm: pause the long-job queues with `queue:pause`:
   `studio:studio`, `database:media-archives`, `database:default`,
   `database:integrations`, `database:watermarks`. Use the normal shared cache
   context and confirm both Unix owners see the pause flags. Wait for their
   running jobs, then stop those workers. The hold refuses while a long-job
   worker still runs (`require_long_job_predrain`, on by default). Identify
   stale reservations separately; never force-requeue an uncertain
   payment/message/provider operation. The observed Studio worker timeout is
   7,200 seconds and its retry interval 7,260; the database connection
   defaults to 2,100 seconds for long media assembly. Do not interrupt a long
   provider job to shorten the window. If the optional `repro-lab-tour`
   program is installed, add its queue (`iguide-lab-tour`) to the host config
   and to this pause; its jobs can run 600 s, the whole default drain limit.
3. Before 9:50 pm: CRM steps 1 to 3 in
   [Freeze and replay steps](#freeze-and-replay-steps): record and pause
   Aryeo, hold CRM outbound sends and reply forwarding, hold the website
   recruiting scheduler. Rebuild only the config cache; cache clearing can
   erase the queue pause flags from step 2.
4. 9:50 pm: preflight as before (no call in progress, Aryeo recorded and
   paused, no upload in flight). Once the off-site units are installed, also
   hold `repro-backup.timer` and `repro-recovery-kit.timer` for the night and
   list them as "nothing due": the 03:30 UTC backup falls inside the outage
   (see [Timers and the monthly reboot](#timers-and-the-monthly-reboot)).
   Owner, at the alert provider: give the `site_unreachable` check a
   maintenance window that starts at the hold and ends by itself after 60
   minutes, and end it early after `cli.py reopen`. Pause no other check
   (see [Alerts](#alerts-b6-b7)).

**The hold: the outage clock starts here.**

5. Approval 1, "start freeze". The operator writes the approval from
   `cli.py approval-template --phase hold` to a root-owned 0600 file and runs
   `cli.py hold --approval FILE`. The journal records phase `holding`,
   `started_at` and the pending hold before anything changes. Then:
   - the nginx open marker `/run/repro-cutover-ingress/open` is removed, so
     both vhosts answer 503 with `Retry-After: 30` at server level, including
     `/cubicasa_webhook.php` and `/iguide_webhook.php`;
   - `/etc/repro-database-cutover/active` is published under both deploy
     locks, so no deploy is between its check and its changes;
   - all nine queues are paused;
   - the guard refuses every new admission (web, queue, scheduler, deploy).
   Writers admitted earlier keep running and keep their shared lease.
6. Right after the hold, apply the producer edge hold (CRM step 4). It is
   checked after reopening (CRM step 5), because the server-level hold hides
   it until then.

**Drain and freeze.**

7. `cli.py freeze` stops the worker groups, drains PHP-FPM, waits until no
   writer process remains and every admitted lease is released (at most 10
   minutes), waits for running scheduled commands, then seals SQLite:
   checkpoint, empty WAL, no write bits. If the drain does not finish, the
   run stays `holding` with admissions closed. Use `cli.py abort`, or let the
   watchdog roll back.
8. Record source release/configuration fingerprints, source snapshot SHA-256,
   target UUID, worker/scheduler state, queue counts/reservations, and a journal
   of received/retried provider events. Keep this protected evidence outside
   the release archive. Preserve the original environment file separately.
9. Data steps as in [Import into the empty target](#import-into-the-empty-target).
   The writer-hold adapter refuses `prepare` and `promote`; it covers the
   hold, the freeze and the pre-write rollback only.

**Watchdog.** `repro-cutover-watchdog.timer` runs `cli.py tick` every minute
from boot (`OnCalendar=minutely`). In a held phase it closes admissions again
if a reboot or a restart reopened them, and re-drains the scheduler under the
new boot. At 45 minutes from the hold it rolls back to SQLite. All deadlines
count from the hold, so the drain uses the same 60-minute outage budget. The
service allows a tick 25 minutes, enough for the controller-lock wait and a
whole rollback; while a tick runs, an operator command is refused with
"Another host operator holds the cutover lock" and can be retried.

**Undo.** `cli.py abort` (or the watchdog) re-closes admissions, drains,
unseals SQLite and publishes boot-bound SQLite authority. `cli.py reopen`
verifies that authority through the guard, checks the live host again
(SQLite the only writable engine), resumes the queues, starts the workers,
recreates the open marker, writes the activation receipt and lifts the
deploy fence. If its check after opening fails, it closes ingress, pauses
the queues and stops the workers again. After any reboot, run
`cli.py refresh` first, then `cli.py reopen`; `tmpfiles.d` recreates the
`/run` directories at boot. `refresh` refuses while the open marker exists,
because the guard revokes the current proof before it measures the host; it
checks that and publishes the deploy fence inside the guard's locks. Deploys
stay held by the marker from `init` until `reopen` lifts it after a
completed rollback. The watchdog keeps the completed SQLite rollback as it
is after such a reboot; it does not escalate to recovery. After an aborted
and reopened cutover the policy still pins the old release, so retire the
installed fences or start a new run before the next deploy.

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
The writer token must already exist on the target
([Release and target preparation](#release-and-target-preparation) step 4):
the migrations ledger write waits for it. Apply the reviewed
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

### Run a qualifying night

From the nightly-dress lane. Qualifying nights run before the cutover night
and use the same import path. The
[nightly runner README](../scripts/cutover/nightly/README.md) has the details.

1. Keep a clean checkout of the candidate release on the host. Check the
   config and note its digest:
   `python3 -B -m scripts.cutover.nightly.runner check-config --config scripts/cutover/nightly/nightly-config.json`.
2. Dry run for the night and review the guards:
   `python3 -B -m scripts.cutover.nightly.runner run --config scripts/cutover/nightly/nightly-config.json --state-dir /home/maverick/repro-nightly --day YYYY-MM-DD`.
3. With the owner's approval file for that date range and config digest, run
   it with `--execute --approval approval.json` on the scheduled UTC date.
4. Read `run-receipt.json`. A stopped night does not count. A budget stop
   names the stage and its time.
5. Review `night.json`, `crm-review.json` and their evidence, then add
   `manifest-entry.json` with `policy.py add-night`.
6. After five consecutive reviewed nights, the last two on the frozen
   release, pin the manifest, build the summary with
   `--producer-receipts`, pin the summary, and run the readiness check.

Each approved night reads the live SQLite file through the online backup API
(about 21 s), keeps about 4.5 GB under the state directory, and runs a
disposable socket-only MySQL 8.4.13 for about 10 to 16 minutes. It needs
45 GB free and 12 GiB available memory. No night can qualify until the owner
pins the finance step and the reverse trigger helper in the config; both are
unset. If the pinned finance command writes through the application on a
MySQL copy, provision the writer token there first
([Release and target preparation](#release-and-target-preparation) step 4).

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

Each dress rehearsal record must carry `busiest_p95_us`, `stress`,
`mysql_kills`, `data` and `way_back` as described in the
[readiness README](../scripts/cutover/readiness/README.md#required-input).

The current deploy scripts check `/etc/repro-database-cutover/active`
after taking their individual backend and frontend deployment locks. The
privileged cutover controller must coordinate both locks when publishing or
clearing that marker. Its parent directory must let the deploy account check
the marker and allow writes only by root. A stale script or manual deployment
also needs the host-level permission fence.

Back up the verified MySQL target. Change only the database settings in the
preserved environment through the approved cutover procedure. Retain the APP_KEY
and all unrelated settings. Rebuild configuration/OPcache and verify FPM and both
worker owners resolve the same driver/database/server UUID. Then run
`php artisan db:writer-policy-check` as the application user. It must print
`"problems":[]` and exit 0. Add it to every deploy once MySQL is live. Every
process must run the same release: a process without the policy would write
without the token. The engine check in the drill covers this for workers.
The writer-hold controller does not promote MySQL or reopen on it yet; that
needs a MySQL adapter. Use a controlled
smoke period before reopening public writes, then resume the exact paused queues
with `queue:resume`, restart their owning groups/service and the scheduler, and
verify they use MySQL. Reconcile incoming provider events before declaring done.

## Rollback has two different paths

**Before any MySQL business write:** restore the saved SQLite connection
settings and its configuration cache while writers remain stopped. The frozen
SQLite database is still authoritative. Retain source/media/cache safeguards and
verify the application before resuming writers. The writer-hold `cli.py abort`
(or the watchdog at 45 minutes) and `cli.py reopen` carry out this path; see
[Freeze all writers](#freeze-all-writers).

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
connecting to MySQL. Also check the trigger installer against the exact
tool digests, snapshot and reviewed trigger names before starting the
timed import. Verify the durable profile on a disposable instance. Use
`SHOW GLOBAL VARIABLES` for the binary log format check because reading
`@@binlog_format` directly emits a deprecation warning on MySQL 8.4.13.
Keep the warning check enabled for every trigger statement.

After stopping writers and reconciling external effects, run the reviewed
reverse tool.

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
