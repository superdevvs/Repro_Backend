# MySQL production cutover runbook

Status: implementation and isolated rehearsal. Production cutover has a separate
approval gate. The previous Docker-based rehearsal is historical evidence.

Every repository Python on the host, at the drill and at cutover, runs with
`python3 -B`: a bytecode cache under the app tree changes the identity the
controller checks before every action, so if it refuses on the artifact field
after no deploy, look for `__pycache__` under the app tree's `scripts/` (and
`resources/iguide-lab/`) and remove it.

> **What this candidate can do on the night.** It can hold, freeze, abort
> and reopen production on SQLite. It cannot reach MySQL:
>
> - `cli.py` has no `prepare` or `promote` action (`ACTIONS` in
>   `scripts/cutover/production/cli.py`).
> - `cli.py approval-template` accepts only `--phase hold` or
>   `--phase freeze`, so no "reopen on MySQL" approval can be issued.
> - The host adapter's `prepare` (D10) switches `.env`, `config.php` and
>   FPM to MySQL read-only behind the closed gate, but no `cli.py` action
>   runs it, and `evidence` and `seal_mysql` still refuse (`NOT_SUPPORTED`
>   in `scripts/cutover/production/adapter.py`), so nothing opens the
>   gate. Its rollback does not undo a `prepare` yet (D12). `cli.py reopen`
>   reopens SQLite only.
> - Every writer profile in `scripts/cutover/production/wrappers.example.json`
>   is pinned to `"engine": "sqlite"`. The guard admits MySQL writers only in
>   `mysql_authoritative`, which only `promote` reaches.
> - The watchdog rolls back to SQLite 45 minutes after the hold
>   (`Runner.DECISION_SECONDS`), whatever the import shows.
>
> Until the [MySQL adapter lane](#mysql-adapter-lane-not-built) is built,
> any run at this candidate is a SQLite dress rehearsal that ends in
> `cli.py abort` and `cli.py reopen`. Even that needs W1, and W1 waits for
> these ([Release and target preparation](#release-and-target-preparation)
> step 10):
>
> - The host identity and MySQL probes and the `drain_fpm` command. None is
>   written.
> - The lab-tour queue. At `4e4a262d` the host-config check refuses
>   `iguide-lab-tour:iguide-lab-tour`. The code-fix lane (`71f7db04`)
>   accepts it, so a host with the `repro-lab-tour` program needs that
>   change before `cli.py init`.
> - Queue commands as `www-data`, with an explicit umask. `queue_pause`
>   and `queue_resume` in `host.json` must run through `runuser -u
>   www-data` and set `umask 0022` themselves, in exactly the form the
>   adapter lane's `load_config` accepts (Release step 10); it refuses
>   any other umask. The pause flags' cache
>   folders on production are `www-data` 755, so the deploy user cannot
>   write them: the hold, every abort and every watchdog rollback would
>   fail at `close_admissions` and leave the site held. Run as root, they
>   create root-owned folders where none existed, and later cache writes
>   into those fail. `runuser` keeps the caller's umask, and the watchdog
>   runs with `UMask=0077`, so without its own umask a flag the watchdog
>   creates is 0600 and the workers that run as `maverick` cannot read it:
>   for them the queue is not paused. The deploy user's own pauses and
>   resumes (the 9:20 pm step and the called-off undo list) go through
>   `sudo -n /usr/local/sbin/repro-queue-flag`, which the adapter lane
>   adds and W1 installs.
> - The storage guard on cron lines. On production all but one of the
>   deploy user's cron lines run through
>   `/usr/local/sbin/repro-storage-guard` (25 November). The adapter
>   lane's `crontab.py` (D20) carries that exact prefix over in front of
>   the wrapper and refuses any other prefix, so W1 keeps the guard on the
>   `scheduler` and `auth-prune` lines. Its rules name the exact command
>   each profile replaces; check them against the live crontab before W1
>   (Release step 10).
>
> The cache store no longer blocks the abort. Production keeps the queue
> pause flags in the `file` cache store (measured 25 November). So an abort
> after the seal (from `frozen`, or from `holding` after a freeze that
> failed once SQLite was sealed) runs `queue_pause` against
> `storage/framework/cache`, not the sealed SQLite file, before
> `seal_sqlite` unseals it.

Use the separate native **MySQL 8.4.13** target from
[`scripts/mysql/native`](../scripts/mysql/native/README.md). The existing 8.0.46
service is outside this migration. The actual target host, its administrator
preparation, backup destination/retention, and maintenance window must be
confirmed before cutover. Local success does not substitute for that acceptance.

## Sales CRM on SQLite before cutover

The CRM release adds ten migrations (nine dated 24 November and `2026_11_25_140000_add_replay_identity_to_recruit_application_receipts`, which adds two nullable columns) and 37 tables with the `crm_` or `recruit_` prefix or the name `email_suppressions`. Production conversion must start with a SQLite online backup taken **after** those migrations and the client import. The MySQL schema dump is a clean-install baseline. It includes the migration ledger, eight system event types and three unpublished starter drips with their 30 steps. It contains no contacts, applicants, quotes, messages, tokens or provider credentials.

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

On the isolated rehearsal host, set `snapshot` to the protected copy's absolute path. Set the expected checksum by hand from `frozen.sha256` on the production host, never from the copy itself. Run the block as a script (for example `bash -s < plan.sh`), so `set -e` stops the block at the first failure rather than closing your login shell. `migrate.py` checks the file against the same value again. The following uses the rehearsal branch scripts.

```bash
set -euo pipefail
snapshot=/protected/frozen.sqlite
EXPECTED_SNAPSHOT_SHA256='<sha256 from frozen.sha256 on the production host>'
printf '%s  %s\n' "$EXPECTED_SNAPSHOT_SHA256" "$snapshot" | sha256sum --check --strict
python3 -B scripts/mysql/migrate.py plan --snapshot "$snapshot" \
  --snapshot-sha256 "$EXPECTED_SNAPSHOT_SHA256" --output /protected/new-crm-plan
```

Review the new exceptions and schema plan. Use the `import-empty` and `verify-fields.php` commands below with the same snapshot and their independently checked fingerprints. Keep the full private reports with the rehearsal. Production customer records never belong in the schema dump.

To reproduce the small CRM conversion check, run `APP_ENV=testing php scripts/mysql/crm-rehearsal-fixture.php /tmp/crm-test.sqlite` with an unused filename. The fixture is created with mode 0600. Run the same checksum-bound plan, empty-target import and independent read as below against an isolated, socket-only MySQL 8.4.13 service. The fixture is synthetic and contains consent, a quote, Unicode JSON intake, an SMS request and the fixed campaign/event defaults. At `4e4a262d` it globs only `2026_11_24_*` and lacks the two new columns. The code-fix lane (`6fa0f78e`) makes it run every migration that creates or changes a CRM table (`crm_*`, `recruit_*`, `email_suppressions`), all ten, the same set as `crm_migrations` in the nightly config. The November 24 run, with nine migrations, verified 37 tables, 48 rows and 462 fields with zero mismatches and zero MySQL warnings. Test the native Laravel migration path with `php vendor/bin/phpunit -c phpunit.mysql.xml tests/MySql/RecentFeatureMigrationCompatibilityTest.php` and `php vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/Crm` on the dedicated test database.

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
| Shared Resend webhook | Hold at ingress and return a retryable failure until an approved retry or capture path is proven. The edge snippet and `PRODUCER_INGRESS_HOLD` answer 503 with `Retry-After`. Turning off CRM leaves delivery events and public opt-outs able to write, so it is not a freeze control. Before the producer-replay lane it also acknowledged an inbound reply without processing it; `email.received` now gets 503. | Use fresh provider-signed redelivery under the original provider event identity (`svix-id`). The webhook applies it once and refuses a stale signature. Verify required received email content is recoverable. Check forwarding effects before retrying any ambiguous send. Resend's docs give retries for about 27.5 hours (derived), so a 60-minute hold loses nothing ([provider table](#providers-during-a-60-minute-hold)). Not verified on Resend yet: that a dashboard replay keeps the `svix-id` and re-signs with a fresh timestamp. |
| Google Calendar | Hold the poller, `crm:calendar-sync`, with `CRM_CALENDAR_SYNC_HOLD` (step 2 below). Nothing holds the OAuth callback (`/api/google-calendar/callback`): the writer hold answers it 503 during the hold, and after reopening a reconnect writes at once. Preserve existing mappings and pending pushes. | Reconcile changes made directly in Calendar before releasing sync. Restart an expired OAuth flow from the application. |
| Aryeo API webhook (`/api/integrations/aryeo/webhooks/{installation}`) | Held with 503 and `Retry-After` at the edge and in the app. Signatures expire after 300 s, so only a fresh delivery from Aryeo can be accepted. Deduplicated by `event_id` with a body hash. | A retried or redelivered event gives one `aryeo_api_webhooks` row and one `ReconcileAryeoWebhook` job (tested). Aryeo's docs give one retry after 10 s and a final attempt after 100 s, so deliveries during the hold are lost except the last 2 minutes or so ([provider table](#providers-during-a-60-minute-hold)). No replay is documented: reconcile through the Aryeo orders API after the hold. Not confirmed with Aryeo. |

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

For resolved website replays, compare two independently pinned ledgers from
the repository root. Every flag is required:

```bash
python3 -B -m scripts.cutover.verify_website_replay \
  --capture /private/replay-<date>/capture.json --capture-sha256 <capture_sha256> \
  --replay /private/replay-<date>/replay.json --replay-sha256 <sha256 of replay.json>
```

`website_ledger` (CRM step 7) prints `capture_sha256` when it writes the
capture file. It writes its three outputs with `O_EXCL`, so each must be a
new path. The capture and replay files share `site_commit` and
`freeze_sha256` at the top level. Use schemas `repro.website-capture.v1` and
`repro.website-replay.v1` respectively. Each `rows` entry includes
`producer`, `reference_sha256`, `payload_sha256`, and the ordered
`attachment_sha256s`. `reference_sha256` is the SHA-256 of the lowercased
reference. The replay entry adds the independently observed
`verified_business_effects` count, which must be exactly 1. Hash the same
original request encoding in both files. Preserve the raw materials
separately under the private replay controls. Compare by producer and
original reference. Any missing row, byte difference or multiple effect
requires reconciliation. This offline comparison assumes an independently
verified producer capture inventory and does not close the live ingress
gate. Open: no tool writes the `repro.website-replay.v1` ledger; only the
tests build one. Until one is built, the operator writes it by hand from the
replay receipts, lowercasing each reference before hashing it.

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
inside an approved window. Edit `.env` in place, as in "Editing `.env` on
the night" in [Freeze all writers](#freeze-all-writers), then apply it:

```bash
cd /var/www/backend
# As the deploy user, the way Deploy-App runs them: group www-data, umask 0022.
sg www-data -c 'umask 0022; php artisan config:cache'
sudo systemctl try-reload-or-restart php8.3-fpm   # with PHP's OPcache defaults FPM rechecks files every 2 s; this makes it certain
# Only while workers run; they keep the config they started with.
sg www-data -c 'umask 0022; php artisan queue:restart'
```

`try-reload-or-restart` reloads a running FPM and does nothing, with exit
0, when the freeze has stopped it; plain `reload` fails on a stopped unit.
Both artisan commands run as the deploy user, like `Deploy-App.ps1`
(`sg www-data`, `umask 0022`), not as `www-data` and not as root.
`config:cache` deletes `bootstrap/cache/config.php` and writes a new one
owned by the caller. `queue:restart` writes the cache key
`illuminate:queue:restart` (folder `storage/framework/cache/data/ee/2f`),
which every deploy writes as the deploy user with mode 0644. `www-data`
cannot overwrite that file, and if `www-data` created it first, the next
deploy's `queue:restart` would fail. Both were checked with Laravel
12.71 in the QA image. Check the file's owner first (read-only checks in
[Freeze all writers](#freeze-all-writers)). Only `queue:pause` and
`queue:resume` run as `www-data`.

Never run `php artisan optimize:clear` or `cache:clear` (Deploy-App runs
`optimize:clear`): they empty the cache store, which holds the queue pause
flags (the `file` store, measured 25 November). Each step that changes
production needs its own approval. The times and the hold are the ones in
[Freeze all writers](#freeze-all-writers).

Record the start of these CRM steps in UTC as `FREEZE_AT` before step 1,
and keep it in the private ledger, because step 11 still needs it two hours
later:

```bash
install -d -m 0700 /private/cutover-<date>
FREEZE_AT=$(date -u +%Y-%m-%dT%H:%M:%SZ)
printf 'FREEZE_AT=%s\n' "$FREEZE_AT" >> /private/cutover-<date>/ledger.env
# In any later shell: . /private/cutover-<date>/ledger.env
```

`FREEZE_AT` is not `cli.py freeze`, and not the plan's "start of the
freeze", which is the hold. It comes before the hold's `started_at`, which
starts the outage clock. The checks below use `FREEZE_AT`, so they also
cover the steps before the hold.

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
2. Set `CRM_OUTBOUND_HOLD=true`, `CRM_DEFER_REPLY_FORWARDING=true` and
   `CRM_CALENDAR_SYNC_HOLD=true`, then run the three commands above, and
   confirm the three booleans in the effective config:
   `php artisan crm:outbound-release-check --since=$FREEZE_AT --json`
   prints the first two (`outbound_hold`, `defer_reply_forwarding`), and
   `php artisan config:show crm.calendar_sync_hold` prints the third. Both
   are the CLI's view, not FPM's. Keep
   `CRM_CALENDAR_SYNC_HOLD` in the environment that reopens; step 10
   releases it.
3. Hold the website recruiting scheduler with the `worker_paused` switch from
   website change P2-05 ([spec](mysql-lanes/producer-replay.md#p2-05-a-scheduler-hold-that-keeps-capture-on)).
   Not possible at this candidate: P2-05 is not built. Until it ships, the
   outbox keeps retrying into the 503s and uses its attempts; rows turn
   `failed_exhausted` after about 127 minutes of 503s. Do not turn off
   `recruiting_forwarding_settings.enabled`: that stops capture.

**During the hold**

4. Right after the approved hold, hold producers at the edge. Each Dashboard
   server block (`frontend.conf` and `laravel.conf`) must already carry
   `include /etc/nginx/repro-holds/*.conf;` once (see
   [Release and target preparation](#release-and-target-preparation)
   step 10, and the `nginx -T` check before Approval 1):

   ```bash
   sudo install -o root -g root -m 0644 \
     /var/www/backend/scripts/cutover/fence/producer-hold.nginx.conf \
     /etc/nginx/repro-holds/producer-hold.conf
   sudo nginx -t && sudo systemctl reload nginx
   ```

   Optionally also set `PRODUCER_INGRESS_HOLD=true` as the in-app second
   layer. Never use `CRM_ENABLED=false` as the freeze control; it is now safe
   for producers but it also stops reply processing and intake processing.
5. While the writer hold is in force, it answers every path with 503 at
   server level, so it hides the edge snippet. After reopening and before step 6,
   check that every held path answers 503 with `Retry-After: 60` on both
   vhosts (never 403, 404 or a redirect; the website treats 3xx and 4xx
   other than 408, 425 and 429 as permanent). For each Dashboard host name:

   ```bash
   for path in /api/external/website-email /api/webhooks/resend \
       /api/webhooks/stripe /api/integrations/aryeo/webhooks/1; do
     curl -s -o /dev/null -D - -X POST "https://<host>$path" | grep -Ei '^(HTTP|retry-after)'
   done
   ```

**Release, after reopening, in this order**

6. Release the edge hold, then set `PRODUCER_INGRESS_HOLD=false` (if it was
   set) and apply it as above:

   ```bash
   sudo rm /etc/nginx/repro-holds/producer-hold.conf
   sudo nginx -t && sudo systemctl reload nginx
   ```

   The website outbox, booking forwarder, Resend and Stripe retry on their
   own (see the [provider table](#providers-during-a-60-minute-hold)).
7. Website ledger. Not possible at this candidate: the website export (P2-06,
   [export spec](mysql-lanes/producer-replay.md#p2-06-website-export-for-the-operator-ledger))
   is not built. Once it is, export the website rows read-only and run, from
   the repository root:
   `python3 -B -m scripts.cutover.website_ledger --export <export.json> --capture <capture.json> --private-csv <private.csv> --references <refs.json>`
   (the three output paths must be new). Then
   `php artisan crm:website-ledger --sqlite=<copy> --references=<refs.json> --json`,
   where `<copy>` is a fresh online backup of the live SQLite file
   (`php scripts/deploy/backup-sqlite.php <live database> <new file>`). No
   read-only connection is configured, so `--connection` has nothing safe to
   name. Replay only through the same reference:
   `not_in_dashboard` bookings through forward-booking-v2 with the frozen
   quote; `not_received` and `not_delivered` contacts through website-email
   with the same reference; `uncertain` contacts only after the provider log
   shows the outcome. A person handles `submission_without_shoot`,
   `other_source_only` (the reference belongs only to another integration's
   booking; bookings match on source `reprophotos.com` and the reference) and
   every `manual_reconciliation` entry. An unreadable `--since` exits 2. Each
   replay POST needs approval per reference. Compare the capture and replay
   ledgers with `verify_website_replay` and all four flags (see
   [CRM freeze and replay](#crm-freeze-and-replay)).
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
   disposition on the send it answered. `due_enrolments_that_should_stop`
   counts active drips that are due for a contact who opted out, bounced,
   complained, is suppressed or replied since `FREEZE_AT`. Replies,
   unsubscribes and bounces stop the drip when they arrive, so this is an
   edge case. `crm:send-due` would stop the rest itself, but the outbound
   hold keeps it from running. The check prints only a count: find those
   contacts on a read-only copy with the rule in
   `CrmOutboundReleaseCheck::shouldStop`, and stop each drip from the
   contact in the CRM (`contacts/{contact}/drip/stop`). When it exits 0, set
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
    disconnected Google) are listed for information and do not fail it.
    When it exits 0, set `CRM_CALENDAR_SYNC_HOLD=false` and apply it
    (`config:cache` and `systemctl try-reload-or-restart php8.3-fpm`). The
    scheduler runs `crm:calendar-sync` again from its next five-minute slot.

    A planned follow-up that shows `push_pending` clears when it is saved
    again in the CRM. A cancelled one clears only when the sync runs: the
    CRM refuses to edit a follow-up that is not planned
    (`CrmEventService::update`), and only `crm:calendar-sync`, the held job,
    retries its Google delete. While such a row exists, the reconcile cannot
    exit 0 with the hold on. Settle every other row, release `CRM_CALENDAR_SYNC_HOLD`,
    let one sync run, then rerun `crm:calendar-reconcile --dry-run`, which
    must exit 0. With the code-fix lane (`cf5594b6`), a run of
    `crm:calendar-sync` while the hold is on says the same: release the
    hold once the reconcile exits 0, or once only cancelled `push_pending`
    rows are left. When those are the only blocking rows, the reconcile's
    report also carries a `hint` (a JSON key, and the last line in text
    mode) with these steps; it still exits 1.

    Until the release, `CRM_CALENDAR_SYNC_HOLD` from step 2 holds the job: the scheduler
    skips it and a manual run calls Google for nothing and writes nothing
    (`tests/Feature/Crm/CrmCalendarSyncHoldTest.php`). Run before this step,
    it pushes pending CRM times over changes made in Google, moves CRM
    follow-ups to Google's time, unlinks follow-ups deleted in Google and may
    refresh tokens. This reconcile then exits 0 with nothing left to decide.
    Do not use `CRM_GOOGLE_SYNC=false` as the hold: it also stops saves from
    pushing and nothing pushes them later, so a follow-up created or
    cancelled meanwhile does not reach Google. The hold covers only this job.
    A follow-up saved in the CRM after reopening is still pushed at once.
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

#### Providers during a 60-minute hold

The producer hold does not cover Telnyx (`/api/webhooks/telnyx/messaging`,
`/status`, `/shared-mms/{uuid}` and `/voice`), iGUIDE
(`/iguide_webhook.php`), CubiCasa (`/cubicasa_webhook.php`), Cakemail
(`/api/webhooks/cakemail`), Autoenhance (`/api/webhooks/autoenhance`) or
Vapi (`/api/webhooks/vapi`). This candidate has no Telnyx capture route.
It has two unbound building blocks: the database-independent spool
`App\Support\TelnyxCutoverInbox`
([inbox README](../scripts/cutover/inbox/README.md)) and the standalone
HTTP adapter `App\Support\TelnyxCutoverCapture` for all four Telnyx paths
(`tests/Unit/TelnyxCutoverCaptureTest.php`), which no route, middleware or
service activates. Neither is reviewed for activation. During the hold the
writer hold answers all of them 503 at server level, including the two
direct `.php` files.

What each provider does with a 503 or no answer during a 60-minute hold,
from the provider docs only (read on 25 November; no provider was asked,
nothing was tested against a provider). "Unknown" means the docs do not
say. "Derived" marks arithmetic from documented numbers, not a figure the
provider publishes.

| Provider | Retries on 503 | Window | Lost in a 60-minute hold | After the hold |
| --- | --- | --- | --- | --- |
| Telnyx messaging (`/api/webhooks/telnyx/messaging`, `/status`, `/shared-mms/{uuid}`) | Yes, any non-2xx (2 s timeout). Up to 3 attempts per URL with exponential backoff, then the failover URL; another Telnyx page says 2 retries per URL. Intervals not published. | Unknown | Assume every text and delivery report of the hold is lost, unless a failover URL on a separate, healthy host is set. | List failed deliveries for the window (`GET /v2/webhook_deliveries?filter[status][eq]=failed&filter[started_at][gte]=...`; each record has the payload; check in the portal that messaging webhooks appear there). Cross-check inbound texts with `GET /v2/detail_records?filter[record_type]=messaging`, and outbound ones still sent or queued with `GET /v2/messages/{id}`. Open: no reviewed path re-injects them; the public endpoint refuses signatures older than 300 s. |
| Telnyx voice (`/api/webhooks/telnyx/voice`) | Yes for 5xx, 408 and 429; other 4xx are not retried. 2 attempts on the primary URL, then the failover URL; immediate unless a custom retry delay is set. | Unknown; seconds by default (derived) | Lost. A call during the hold cannot be controlled, and a replay later is pointless. What the caller hears is not documented beyond the optional hang-up on timeout. | Owner decision before the night: point `webhook_event_failover_url` at an independent host, or route the numbers elsewhere. After: list failed `call.initiated` deliveries and the call records for the window, and call missed callers back. |
| Vapi (`/api/webhooks/vapi`) | No by default. Opt-in `server.backoffPlan` (API only). `assistant-request` and `campaign.predial` are never retried. | 0 by default; about 85 minutes at the maximum exponential setting (derived; no delay cap documented) | Lost. End-of-call reports and status updates are sent once; inbound calls on `assistant-request` numbers end with `assistant-request-returned-error`; tool calls fail mid-call. | List calls for the window, re-ingest each with Get call, and download recordings before their links expire. Check the Webhook logs (not available under Zero Data Retention). |
| iGUIDE (`/iguide_webhook.php`) | Only 5xx; 3xx and 4xx are dropped silently. Every 10 to 15 minutes, stops after the 5th failed attempt. | About 40 to 60 minutes after the first attempt (derived) | Partly. An event first sent in about the first 20 minutes of the hold can use all five attempts inside it (derived). The docs say there is no way to know which events were sent. | For every iGUIDE or work order that could have become ready in the window (from our records), call `POST /api/v1/iguides/{iguideId}/events/{eventType}/dispatch?taskId=` (scope `iguide.events`), or GET `/events/ready/object`. The handler must accept duplicates. The scheduled `iguide:resync-pending` (:15 and :45) also retries recent shoots without a tour URL. |
| CubiCasa (`/cubicasa_webhook.php`) | Unknown. The docs say it tries 3 times and then gives up; intervals not stated. | Unknown | Assume lost. | Poll "Get order by ID" for every order not yet final. The scheduled `cubicasa:resync-pending` (every 30 minutes) reconciles unfinished orders once the scheduler runs. |
| Cakemail (`/api/webhooks/cakemail`) | Unknown | Unknown | Assume lost. | Pull the email activity logs for the window (`GET /v2/logs/emails`, and campaign logs). Apply bounces, unsubscribes and spam complaints to the suppression state first; that is a compliance matter. |
| Autoenhance (`/api/webhooks/autoenhance`) | Unknown | Unknown | Assume lost. | Poll orders and images sent before or during the hold (`GET /v3/orders/{id}`, `GET /v3/images/{id}`) and finish any that are now processed; staff can also poll from the Dashboard (`POST /api/autoenhance/jobs/poll`). |
| Resend (`/api/webhooks/resend`, edge hold) | Yes, anything but 200: at once, 5 s, 5 min, 30 min, 2 h, 5 h, 10 h, 10 h, each after the previous failure. | About 27.5 hours (derived) | Nothing. Attempts at about 0 s, 5 s, 5 min and 35 min fall inside the hold; the next comes about 2 h 35 min after the first. | Optional: replay failed events from the dashboard or API (CRM step 8), deduplicated on `svix-id`. Resend disables an endpoint after "sustained failures" (not quantified; the generic Svix rule is 5 days). |
| Aryeo (`/api/integrations/aryeo/webhooks/{installation}`, edge hold) | Yes, any non-2xx: again after 10 s, a final attempt after 100 s, then it stops. | About 2 minutes (derived) | Lost, except events from the last 2 minutes or so before the edge hold is released (CRM step 6). | No replay is documented. List orders (`GET /orders`) and re-fetch those touched in the window (`GET /orders/{order_id}`). Open: no Dashboard command does this for a window. |
| Stripe (`/api/webhooks/stripe`, edge hold) | Yes, any non-2xx, a timeout or a redirect. Exponential backoff; intervals not published. | Up to 3 days in live mode (3 tries over a few hours in a sandbox) | Nothing. | CRM step 11 (`stripe:undelivered-events`). Resend from the dashboard (up to 15 days) with approval, and keep answering 200 for events already handled. |

Answer 503, never 3xx or 4xx: iGUIDE drops anything that is not 5xx,
Telnyx voice does not retry 4xx other than 408 and 429, and Stripe counts a
redirect as a failure. No redirect to a maintenance page. iGUIDE does not
document a refused connection or a timeout, so a 503 is safer than closing
the port. The server-level hold and the edge snippet both answer 503.

Sources, all provider docs: Telnyx messaging
([receiving webhooks](https://developers.telnyx.com/docs/messaging/messages/receiving-webhooks),
[receive a message](https://developers.telnyx.com/docs/messaging/messages/receive-message),
[support: webhooks](https://support.telnyx.com/en/articles/4334722-how-to-leverage-webhooks),
[detail record search](https://telnyx.com/release-notes/detail-record-search-api));
Telnyx voice ([voice API webhooks](https://developers.telnyx.com/docs/voice/programmable-voice/voice-api-webhooks),
[support: Call Control applications](https://support.telnyx.com/en/articles/4374050-configuring-call-control-texml-applications-voice-api),
[Dial](https://developers.telnyx.com/api-reference/call-commands/dial),
[list webhook deliveries](https://telnyx.mintlify.app/api-reference/webhooks/list-webhook-deliveries),
[debugging tools](https://support.telnyx.com/en/articles/4304872-telnyx-debugging-tools),
[receiving webhooks](https://developers.telnyx.com/development/api-fundamentals/webhooks/receiving-webhooks));
Vapi ([server events](https://docs.vapi.ai/server-url/events),
[end-of-call report](https://github.com/VapiAI/docs/blob/main/fern/server-url/end-of-call-report.mdx),
[server URLs](https://docs.vapi.ai/server-url/setting-server-urls),
[campaign webhooks](https://docs.vapi.ai/outbound-campaigns/webhooks),
[reliability](https://docs.vapi.ai/tools/api-request/reliability));
[iGUIDE webhooks](https://docs.youriguide.com/docs/integrations/webhooks/);
CubiCasa ([about webhooks](https://integrate.docs.cubi.casa/about-webhooks-1362318m0),
[get started](https://integrate.docs.cubi.casa/get-started-1362307m0));
Cakemail ([webhook](https://cakemail.dev/en/api/webhook),
[email API](https://cakemail.dev/en/api/email-api));
Autoenhance ([webhooks](https://docs.autoenhance.ai/webhooks),
[retrieving orders](https://docs.autoenhance.ai/orders/managing-orders/retrieving),
[listing orders](https://docs.autoenhance.ai/orders/managing-orders/listing-and-pagination),
[retrieving images](https://docs.autoenhance.ai/images/managing-images/retrieveing));
Resend ([retries and replays](https://resend.com/docs/webhooks/retries-and-replays),
[create webhook](https://resend.com/docs/webhooks/create-webhook),
[list event attempts](https://www.resend.com/docs/api-reference/webhooks/list-event-attempts),
[list events](https://www.resend.com/docs/api-reference/webhooks/list-events),
[Svix retries](https://docs.svix.com/retries));
Aryeo ([setting up webhooks](https://docs.aryeo.com/docs/4-setting-up-webhooks),
[orders API](https://docs.aryeo.com/api/aryeo/orders));
Stripe ([webhooks](https://docs.stripe.com/webhooks),
[managing endpoints](https://docs.stripe.com/events/manage-webhook-endpoints)).
A lower-authority Telnyx guide on GitHub lists a different schedule
(immediately, 1, 5 and 15 minutes, 1 hour); it contradicts the developer
docs and is not used here.

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
   On the production target, as MySQL root and before the cutover accounts
   exist (their table grants need the table):

   ```sql
   CREATE DATABASE repro_mysql_control CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
   CREATE TABLE repro_mysql_control.writer_token (
     id TINYINT UNSIGNED NOT NULL PRIMARY KEY CHECK (id = 1),
     application_database VARBINARY(64) NOT NULL,
     contract_version INT UNSIGNED NOT NULL
   ) ENGINE=InnoDB;
   INSERT INTO repro_mysql_control.writer_token VALUES (1, '<application database>', 1);
   ```

   This SQL grants nothing. Then, as MySQL root, run the adapter lane's
   `scripts/cutover/production/mysql/accounts.sql` (not in this candidate),
   with each password filled from a root-only file by its `fixture.sh
   render`. It creates the eight cutover accounts and is the closed state
   of the lane's MySQL write gate. Only two accounts get `SELECT,
   UPDATE(id)` on the token: `repro_migrator`, which runs `php artisan
   migrate` (the migrations ledger is an ordinary write and waits for the
   token), and `repro_token_check`, which runs the token check before
   Approval 2. The app account `repro_app` gets `SELECT` on the app schema
   and on the token, nothing more. Its `INSERT, UPDATE, DELETE` on the app
   schema and `UPDATE(id)` on the token come only from the cutover
   controller's `seal_mysql`, and `seal_sqlite` revokes them (MySQL
   adapter lane item 3). Never grant the token to `repro_app` by hand:
   with `accounts.sql`'s grants that is part of the open gate, which the
   lane's MySQL probe reads as `partial` and refuses with exit 4, so
   `init`, every watchdog tick and every other action that runs the probe
   refuse until the grant is revoked.

   Without the row every write stops. That is deliberate. Add the row to
   the restore and failback checklists, and provision it before any tool
   that boots the application against MySQL writes (rehearsal verifiers
   that only read are unaffected). The importer (`migrate.py
   import-empty`) does not boot the application and needs no token; its
   maintenance account `repro_maint` has no token grant, so `php artisan
   migrate` runs as `repro_migrator`. On a rehearsal or CI target without
   the cutover's write gate (for example the `mysql` workflow's fixture),
   the account the application writes with needs `SELECT, UPDATE(id)` on
   the token itself, and so does the one `php artisan migrate` uses.

   On the production target the app
   account must stay unable to write until `seal_mysql` opens the MySQL
   write gate. From `cli.py init` until `promote` after Approval 2, and
   again after a reopened SQLite rollback, the MySQL probe must never
   report `writable` or `business_activity`: `init` refuses, and in the
   SQLite phases (`planned`, `holding`, `frozen`, `ready` and
   `sqlite_authoritative`) the next watchdog tick records
   `recovery_required` and the guard holds every wrapped writer (Freeze
   all writers, step 9). Only the MySQL path changes this: `seal_mysql`
   opens the gate, and MySQL traffic then counts as business activity
   (MySQL adapter lane items 3, 7 and 18). So make the token row and
   `accounts.sql` on the probed target before `init`, and run no import and
   change no grant on it after `init` outside the night's frozen window;
   inside that window only the controller changes grants.
5. The application sets READ COMMITTED and 5-second lock waits per session.
   The server file may keep its own defaults, but the migrator account should
   use a separate connection if layout changes need a longer metadata wait.
   Each request that takes a database cache lock opens a second MySQL session
   (`mysql_cache_locks`); size `max_connections` for that.
6. Give the off-site alert probe's monitor account its grants (see
   [Alerts](#alerts-b6-b7)). `accounts.sql` (step 4) creates it as
   `repro_monitor` with `PROCESS` and `SELECT ON
   performance_schema.data_locks`. Add
   `writer_token_held_too_long` to the probe plan's conditions with its
   `mysql` monitor section. This daytime alert fails when a session has held
   the writer token for 30 s or more (`max_hold_seconds` in
   `alert-conditions.json`). It runs the query of `php artisan
   db:writer-token-holders` through the monitor's own socket login, so the
   alert account never gets the app's database password, and only while the
   cutover journal makes MySQL authoritative. On MySQL 8.4.13 these two
   grants were enough: a 34 s holder was reported, and without either grant
   the query was refused. To see which sessions hold the token, run the
   query in `app/Console/Commands/WriterTokenHolders.php` as a MySQL
   administrator with the native 8.4 client by absolute path. `php artisan
   db:writer-token-holders` reads through `--connection` (default `mysql`,
   the app account), which has no `PROCESS` grant, and `config/database.php`
   defines no read-only or monitor connection.

   **Alert monitor grants** (a separate step after `accounts.sql`, not part
   of it). Two MySQL conditions read the app schema: `queue_stale` reads
   `jobs`, and `historical_money_totals_changed` runs the reviewed money
   queries. `accounts.sql` gives `repro_monitor` neither. As MySQL root,
   grant one table-level `SELECT` per table, and set `"database":
   "repro_dashboard"` in the probe plan's `mysql` section, because those
   queries name their tables without the schema:

   ```sql
   GRANT SELECT ON `repro_dashboard`.`jobs` TO 'repro_monitor'@'localhost';
   -- One line for each table the reviewed money queries read:
   GRANT SELECT ON `repro_dashboard`.`<money query table>` TO 'repro_monitor'@'localhost';
   ```

   These grants leave the write gate alone. The lane's MySQL probe reads
   only the grant rows and sessions of the gated account (`repro_app`), so
   rows for `repro_monitor` never change its reading: on a fixture built
   from `accounts.sql` it still read `closed` (exit 0) in every journal
   phase after them. Grant to `repro_monitor` only and table by table:
   never to `repro_app`, never through a role (a role edge that names
   `repro_app` is a gate problem, exit 4), and never on the whole schema.

   When: MySQL refuses a table grant while the table does not exist
   (`ERROR 1146`) and keeps it when the table or the schema is dropped and
   created again (both checked on 8.4.13). So run it once the tables exist
   and while no cutover journal exists. After `init` nobody changes grants
   by hand (step 4), and after `prepare` the lane's binary log scan takes
   any account statement as its boundary. On the lane's calendar that is
   the session that retires the drill run: after `retire`, before the
   drill copy is dropped. The grants then apply to the tables the cutover
   night imports. Check them with `SHOW GRANTS FOR
   'repro_monitor'@'localhost'` before the cutover run's `init`, and redo
   this step in such a window whenever the reviewed money queries change.
   Without them `queue_stale` and the money check fail once MySQL is
   authoritative.
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
10. Deploy the reviewed candidate with Deploy-App before installing the
    writer hold (W1). From `cli.py init` the deploy marker holds every
    deploy through the planned observation (C1) and the cutover, and the
    policy pins that release.

    The producer-hold include is a root nginx change, not a Deploy-App
    deploy: Deploy-App never touches nginx. Make it any time before the
    night; an empty folder holds nothing.

    ```bash
    sudo install -d -o root -g root -m 0755 /etc/nginx/repro-holds
    # As root, add this line once inside each Dashboard server block
    # (frontend.conf and laravel.conf), before any location:
    #     include /etc/nginx/repro-holds/*.conf;
    sudo nginx -t && sudo systemctl reload nginx
    sudo nginx -T 2>/dev/null | grep -nE '^# configuration file|server_name|location |repro-holds|repro-cutover-hold'
    ```

    W1 installs the guard, controller, wrappers, watchdog units, the
    `tmpfiles.d` entry, the server-level nginx hold include, sudoers,
    Supervisor and cron changes
    ([production README](../scripts/cutover/production/README.md)). Before
    W1:

    - Write and review the identity and MySQL probes
      ([MySQL adapter lane](#mysql-adapter-lane-not-built) items 1 to 3).
      Neither exists. Every `cli.py` action except `approval-template`
      runs the MySQL probe; `init`, `hold`, `freeze`, `abort`, `tick` and
      `refresh` also run the identity probe (`status` and `reopen` do
      not).
    - Write `host.json` completely before `init`, because `hold` refuses a
      host config that changed after `init`. Its `queues` are the nine
      queues plus `iguide-lab-tour:iguide-lab-tour` if the `repro-lab-tour`
      program is installed. At `4e4a262d` the host-config check
      (`[a-z]+:[a-z0-9-]+`) refuses that name; the code-fix lane
      (`71f7db04`) accepts it (`[a-z][a-z0-9-]*:[a-z0-9-]+`). On a host
      with the program, W1 must install an adapter that has that change.
    - Make `queue_pause` and `queue_resume` run as `www-data` with their
      own umask, in exactly this form. The CLI runs as root and appends
      one `connection:queue` to each, which the shell receives as `$1`:

      ```json
      "queue_pause": ["/usr/sbin/runuser", "-u", "www-data", "--", "/bin/sh", "-c", "cd /var/www/backend && umask 0022 && exec /usr/bin/php artisan queue:pause \"$1\"", "repro-queue"],
      "queue_resume": ["/usr/sbin/runuser", "-u", "www-data", "--", "/bin/sh", "-c", "cd /var/www/backend && umask 0022 && exec /usr/bin/php artisan queue:resume \"$1\"", "repro-queue"]
      ```

      The adapter lane's `load_config` accepts only this shape: the
      script's steps joined by ` && `, a `cd` to the app root only, exactly
      one `umask 0022`, and last `exec /usr/bin/php artisan queue:pause
      "$1"` (`queue:resume "$1"` for `queue_resume`). It refuses any other
      umask, and so do the lane's MySQL probe and `repro-queue-flag`, which
      load `host.json` the same way, so a different form stops W1.

      Production keeps the pause flags in the `file` cache, under
      `storage/framework/cache/data`. The folders the pause keys hash to
      are `www-data:www-data` 755 (measured 25 November), so the deploy
      user cannot create the flag files there. Root can, but where no
      folder exists it creates a root-owned one, and later cache writes
      into it fail. The umask matters because `runuser` keeps the
      caller's, and the watchdog service sets `UMask=0077`. In the QA
      image, a pause run from a 0077 parent without its own umask made a
      0700 folder and a 0600 flag, which a `maverick` process in group
      `www-data` read as "not paused". `umask 0022` matches the measured
      755 folders: the adapter lane's queue flag tests ran this form from
      a 0077 parent in the QA image and got a `www-data` 0644 flag, which
      a member of group `www-data` read as paused. A folder the pause has
      to create is 755, like the 142 existing ones (pass rule under
      "Read-only checks before the night"). Host commands run as root, so
      they need no `sudo`; the deploy user's own pauses use the helper in
      the next item.
    - Install the deploy user's queue flag helper at W1. It comes with the
      adapter lane (`scripts/cutover/production/sbin/repro-queue-flag` and
      `sudoers/repro-queue-flag`, not in this candidate):
      `/usr/local/sbin/repro-queue-flag` (root 0755) and
      `/etc/sudoers.d/repro-queue-flag` (root 0440, one line:
      `maverick ALL=(root) NOPASSWD: /usr/local/sbin/repro-queue-flag`).
      As part of the W1 install, check the sudoers file with
      `visudo -cf /etc/sudoers.d/repro-queue-flag`; it has been checked
      as text only. The helper takes `pause|resume
      <connection:queue>`, refuses a queue that `host.json` does not list,
      loads `host.json` with the controller's own `load_config` and runs
      its `queue_pause` or `queue_resume` as `www-data`. The 9:20 pm
      pauses and the called-off undo list's resumes use it (Freeze all
      writers): the deploy user has no sudo rule for `runuser`, and
      `sudo -n` fails at once instead of asking for a password if the
      rule is missing. The helper takes no lock, so only that undo list
      uses `resume`.
    - Carry the storage guard through the cron rewrite. On production all
      but one of the deploy user's cron lines run through
      `/usr/local/sbin/repro-storage-guard`. The adapter lane's
      `crontab.py` (D20) carries that exact prefix over in front of the
      wrapper: `* * * * * /usr/local/sbin/repro-storage-guard --live &&
      cd /var/www/backend && php artisan schedule:run >> /dev/null 2>&1`
      becomes `* * * * * /usr/local/sbin/repro-storage-guard --live &&
      /usr/bin/sudo -n /usr/bin/python3 -I -S
      /opt/repro-cutover-fence/production/admit.py scheduler >> /dev/null
      2>&1`. The guard still runs before the wrapper, so the `scheduler`
      and `auth-prune` profiles need no guard of their own. Each rule in
      `cron-rules.json` names the exact command it replaces. A line with
      any other prefix (another guard form, `flock`, `nice`), anything
      chained after the command, or a different command text is refused:
      `crontab.py` exits 1, names the lines and prints nothing to
      install. Before W1, check each rule's `command` against `crontab -u
      maverick -l` (only schedules and command names were read on 25
      November) and correct the rule, not the line. Install the rewrite
      only on exit 0 and after `crontab.py --reverse` gives back the saved
      crontab byte for byte (the commands are under "Cron lines" in
      `scripts/cutover/production/README.md`).
    - Set all five owner options in `host.json`. The adapter has no default
      for them and refuses a missing one. The plan's values:
      `"separate_freeze_approval": false`, `"adopt_external_hold": false`,
      `"require_long_job_predrain": true`, `"max_drain_seconds": 600`,
      `"unattended_reopen": false`. The complete list of `host.json` keys
      is the contract table in `scripts/cutover/production/README.md` and
      `example_config` in `scripts/cutover/production/test_adapter.py`;
      write `host.json` from those, not from this list.
    - Write `drain_fpm`. `cli.py reopen` runs only `queue_resume` and
      `start_workers`, so if the drain stops the PHP-FPM pool,
      `start_workers` must start it again. The command is not written.
    - Reconcile the Supervisor template with the live `laravel-worker.conf`
      and the user `supervisord.conf`.

    The nginx hold include holds the site until the open marker exists, and
    `cli.py init` needs a live SQLite route: create the marker by hand, run
    `init`, remove the marker, then `refresh` and `reopen`. Then observe 24
    to 48 hours in `planned` (C1).

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
`/var/www/backend/database/database.sqlite`, `php` `/usr/bin/php8.3`,
`python` `/usr/bin/python3`, `rclone` `/usr/bin/rclone`,
`alert_fail_suffix` `/fail` (use `null` for a provider with no fail
endpoint), `probe_conditions` (the six SQLite conditions and `site_held`),
`access_logs` and `disks`.

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

Enable timers only after review, with the commands each job prints:
`systemctl enable --now repro-backup.timer repro-backup-watchdog.timer`
(`base-backup`), then
`systemctl enable --now repro-alert-heartbeat.timer repro-alert-probe.timer repro-alert-forward.timer`
(`alerts`), then
`systemctl enable --now repro-recovery-kit.timer repro-recovery-kit.path`
(`recovery-kit`; the path unit republishes the kit on every `.env` change).
Install `alerts` right after `base-backup`; until then the backup units'
bridge targets do not exist and only the journal marker is written.

The installer does not show APP_KEY. Copy the `APP_KEY=` line from `.env`
into the password manager yourself (checklist E.3), on the server, not
through a session.

`Deploy-App.ps1` rewrites `.env` by rename when it stores a new Stripe
webhook secret. That drops the ACL: the next backup and the kit fail loudly
(`backup_failed`, daytime). `wrong_database_engine` does not catch it on
production: the probe reads the cached config first and reads `.env` only
when `bootstrap/cache/config.php` is missing (`app_engine` in
`alert_probe.py`), and production has a cached config. Re-run the
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
| wrong_database_engine | wake | probe (cached config's default driver, else `.env` DB_CONNECTION, vs the engine the cutover journal makes authoritative; the plan's value only with no writer hold) | on a test host without the writer hold (no journal, no ingress folder), set the probe plan's expected engine to the other one; with the writer hold the journal wins, so outside a hold set the fixture's cached config driver or `.env` `DB_CONNECTION` to the other engine |
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

`cache_write_failed` fails on production as soon as it is installed. The
default probe plan sets `cache_group_writable: true`
(`install_backup_host.py`), and the check fails on any cache folder
without group write (`alert_probe.py`), while 142 of the 257 top-level
cache folders on production are 755 (measured 25 November; see the
read-only checks in [Freeze all writers](#freeze-all-writers)). Before
the `alerts` job is installed, the owner decides: make the cache tree
group-writable, or drop or change `cache_group_writable`.

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
`PROCESS`, `database_contention` fails every minute. On the production target
it is `repro_monitor`: the adapter lane's `accounts.sql` gives it `PROCESS` and
the `data_locks` grant only, and the table grants come from the separate
**Alert monitor grants** step after it
([Release and target preparation](#release-and-target-preparation) step 6).

`backup_lag` measures the recovery point: the checkpoint's `next_file` says
every earlier log is published, and the newest of those was last written by
MySQL when it rotated away from it. The probe plan needs
`binlog_checkpoint_file` and `binlog_dir`.

`wrong_database_engine` takes the default connection's driver from
`bootstrap/cache/config.php` first (a `DB_URL` scheme wins, as in Laravel),
because `Deploy-App` caches the config. With no cached config it reads
`DB_CONNECTION` in `.env`. It is a config check only: it does not see a
long-lived worker on the old engine. A probe that cannot read the cached
config, or `.env` when there is no cached config, reports it as failed, a
wake alert.

**Cutover night and held sites.** The probe reads the cutover journal
through `repro-alert-cutover-view.service`, which the `alerts` job installs.
That unit runs as root with no capabilities, so it can read the root-private
journal folder and nothing else, and it has no network. It writes the run,
phase, hold start and hold boot to `/var/lib/repro-alert-cutover/view.json`
for the probe, and never locks or writes the journal. The probe also checks
the nginx open marker. The limits are the plan's: 45 minutes from the hold
in a held phase (`holding`, `frozen`, `ready`), 60 minutes of write outage
after it (`sqlite_authoritative`, `writes_possible`, `mysql_authoritative`)
(`cutover_hold` in `alert-conditions.json`).

- Inside an approved hold of the current boot and within those limits,
  `site_held` reports the held site as expected, and a switch of the app
  between SQLite and MySQL does not page `wrong_database_engine`.
- `server_error_rate` leaves out every 503 from the start of a hold of the
  current boot until the reopen (the open marker's modification time,
  `st_mtime`), capped at
  60 minutes, whatever the phase. So a 503 from the app or from a stuck
  rollback in that span is left out too; `site_held` still pages for a
  held site outside the limits. Other 5xx always count, and nothing is left
  out after a reboot.
- At the very start of an approved hold, one probe run can read a view
  written just before the hold while the open marker is already gone, and
  send one false `site_held` page. The next run, a minute later, clears it.
  Expect at most one such page right after Approval 1.
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
| repro-cutover-watchdog | every minute, first 30 s after boot | no |

Every `OnCalendar` line in the off-site unit files
(`scripts/mysql/offsite/systemd/system/*.timer`) names UTC, and the
watchdog's `minutely` needs no zone, so the host's own zone
(America/New_York, measured 25 November) does not move them. Host cron
lines do run on Eastern time (see "Scheduled tasks inside the outage" in
[Freeze all writers](#freeze-all-writers)).

The backup moved from 02:30 to 03:30 UTC. That keeps 90 minutes clear of the
monthly 02:00 reboot on the 28th whatever that reboot's time zone is (UTC, US
or IST). Its source is still unknown: it is not in the deploy user's
crontab. If it is a host cron line or timer with no zone, it runs at 02:00
Eastern (07:00 UTC in winter). The 26-hour freshness limit still covers 5
minutes of jitter and a 30-minute run. Minute timers never catch up: a missed minute is what the
provider should see. A reboot longer than the 5-minute silence grace will
page; measure the reboot once and raise the grace only if it is needed and
still fits the 10-minute delivery limit.

Cutover night: the night falls in EST (UTC-5), so 10 pm Eastern is 03:00
UTC. 03:30 UTC is 10:30 pm EST, inside the planned Saturday 10 to 11 pm
Eastern outage. The old 02:30 UTC (9:30 pm EST)
falls before the outage, but it would run during the 9:20 to 9:50 pm
pre-hold steps. Hold `repro-backup.timer`, `repro-recovery-kit.timer` and
`repro-recovery-kit.path` that night and during drills, and list them as
"nothing due" ([Freeze all writers](#freeze-all-writers) step 4), or the
owner picks another time. The path unit matters most: it starts a kit run
on every `.env` change, and the night edits `.env` several times. Both
timers have `Persistent=true`, so each runs once as soon as it is started
again after its time has passed.

Once the writer hold is installed (W1), any reboot, the monthly one
included, leaves the site and every writer held until an operator runs
`cli.py refresh` and `cli.py reopen`. That fails closed by design, on
SQLite: in `planned` and after a completed SQLite rollback. The alert
timers start by themselves after the boot, and `site_held` (wake) pages
within minutes with "site held after a reboot or an unfinished reopen: run
cli.py refresh, then cli.py reopen". The provider shows only the check
name, so name that check with the remedy. Plan on-call cover for the 02:00
reboot on the 28th, or schedule around it.

On MySQL that remedy does not exist yet. As the runner stands, the first
watchdog tick after any reboot that follows a MySQL reopen records
`recovery_required`, which `refresh` and `reopen` cannot clear
([MySQL adapter lane](#mysql-adapter-lane-not-built) item 18). The lane
must build and drill that path before a night reaches MySQL.

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
retrieve the kit with every flag `restore_backup.py` requires (values from
the kit's publication receipt; see the
[off-site README](../scripts/mysql/offsite/README.md#independent-retrieval)),
then verify it:

```sh
/usr/bin/python3 -I -B /opt/repro-backup-tools/restore_backup.py \
  --backup-id APPROVED_BACKUP_ID --scope application \
  --expected-release APPROVED_FULL_COMMIT --expected-identity APPROVED_INSTANCE_ID \
  --manifest-bytes APPROVED_MANIFEST_SIZE --manifest-sha256 APPROVED_MANIFEST_SHA256 \
  --completion-bytes APPROVED_COMPLETION_SIZE --completion-sha256 APPROVED_COMPLETION_SHA256 \
  --config /var/lib/repro-recovery/credentials/rclone.conf --rclone /usr/bin/rclone \
  --crypt-remote APPROVED_CRYPT_NAME --expected-destination APPROVED_S3_BUCKET_PREFIX \
  --output /var/lib/repro-recovery/private/new-kit
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
python3 -B -m scripts.cutover.readiness.summarize_backup_health --policy review-policy.json --observations backup-health.jsonl
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
PIDs and counts can change. There were also nine deployment-user cron entries;
on 25 November the deploy user's crontab had ten lines, on Eastern time.
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

**Read-only checks before the night.** Each reads production and changes
nothing; each still needs its own approval. The lead session ran these on
25 November at about 19:00 UTC. Repeat the first one on the day before the
night.

- Cache store (ordering M7, answered). Config is cached
  (`bootstrap/cache/config.php` exists), and it sets `cache.default` to
  `file` with driver `file`, `queue.default` to `database` and
  `app.timezone` to `UTC`. So the queue pause flags
  (`illuminate:queue:paused:{connection}:{queue}`) are files under
  `storage/framework/cache/data`, not rows in SQLite. An abort after the
  seal, the watchdog rollback and a re-close after a reboot while `frozen`
  write them there, not into the sealed file, and a switch of
  `DB_CONNECTION` does not move them to MySQL. The code default is still
  `database` (`config/cache.php` reads `CACHE_STORE`; `.env.example` sets
  `CACHE_DRIVER`, the Laravel 10 name, which this release never reads), so
  check again with `cd /var/www/backend && php artisan config:show cache.default`.
- Pause flag folders. The five keys measured hash to folders that exist:
  `data/73/82` (`database:default`), `data/aa/99` (`database:mail`),
  `data/86/52` (`database:integrations`), `data/7a/f7`
  (`database:watermarks`) and `data/9c/90`
  (`iguide-lab-tour:iguide-lab-tour`), all `www-data:www-data` 755 with no
  pause file. Of the 257 top-level `data/` folders (one more than the 256 hex names, not identified), 142 are 755, 114 are
  775 and one is 3777; `cache` and `cache/data` are `www-data:www-data`
  775. That is why every queue command runs as `www-data` (step 2 below,
  and `host.json`). The other five queues' folders were not measured. To
  list them all:

  ```bash
  cd /var/www/backend/storage/framework/cache/data
  for q in database:mail database:integrations database:default database:watermarks \
      database:voice-realtime database:voice-notifications database:voice-transcripts \
      studio:studio database:media-archives iguide-lab-tour:iguide-lab-tour; do
    h=$(printf '%s' "illuminate:queue:paused:$q" | sha1sum | cut -c1-40)
    for d in "${h:0:2}" "${h:0:2}/${h:2:2}"; do
      stat -c "%U:%G %a %n $q" "$d" 2>/dev/null || echo "absent $d $q"
    done
  done
  ```

  Pass rule, for each queue: look at the deepest folder that exists
  (`xx/yy`, or `xx` when `xx/yy` is absent, or `data` when both are
  absent). The key passes only if that folder is owned by `www-data`, or
  has group `www-data` with group write. Anything else stops the night
  until the owner fixes it, including a folder owned by `maverick` with
  mode 755 (a deploy or cron run under umask 0022 makes those) and a
  root-owned folder. A missing folder is then created at the first pause
  by `www-data` with the command's `umask 0022`: 755 with a 0644 flag.
  The workers that run as `maverick` can read the flag but not write
  other cache keys into that folder, so like the 755 folders below it
  trips `cache_write_failed` until the owner's cache-folder decision (a
  default ACL replaces the umask for new entries). Without its own umask
  the folder would be 0700 with a 0600 flag under the watchdog's
  `UMask=0077`, which those workers cannot read.
- Queue restart key. `queue:restart` writes `illuminate:queue:restart`
  under `data/ee/2f`, not measured. Every deploy writes it as the deploy
  user, so expect a `maverick` file with mode 0644:

  ```bash
  h=$(printf '%s' 'illuminate:queue:restart' | sha1sum | cut -c1-40)
  stat -c '%U:%G %a %n' "/var/www/backend/storage/framework/cache/data/${h:0:2}/${h:2:2}/$h"
  ```

  If another user owns it and group `www-data` cannot write it, the
  runbook's `queue:restart` fails, and so does the next deploy's; stop and
  call the owner.
- Cache folders and the off-site alerts. 142 of the 257 top-level `data/`
  folders are 755, so they are not group-writable. Three effects:
  - Processes that run as `maverick` in group `www-data` (the Studio,
    lab-tour and voice workers, and the scheduler, with umask 0002)
    cannot write a cache key that hashes into one of them.
  - The `alerts` job's default probe plan includes `cache_write_failed`
    with `cache_group_writable: true` (`install_backup_host.py`), and
    that check fails on any cache folder without group write
    (`alert_probe.py`). So once the alerts are installed,
    `cache_write_failed` (daytime) fails at once and stays failing.
  - New folders made under umask 0022 (`sudo`'s default, and systemd's
    for a unit that sets no `UMask`) add more such folders.

  Owner decision, before the alerts are installed: make the cache tree
  group-writable in a way that lasts, or drop or change
  `cache_group_writable` in the probe plan. `chmod g+w` alone does not
  last: PHP-FPM, `sudo`, cron and Deploy-App write cache keys under umask
  0022 and keep creating new 755 folders. A default ACL does last,
  because it replaces the umask for new entries, for example
  `setfacl -R -m g:www-data:rwX,d:g:www-data:rwX storage/framework/cache`
  plus setgid on the folders (as root, in an administrator session).
  Either choice changes production and needs its own approval.
- FPM. The unit is `php8.3-fpm.service`, active. No
  `opcache.enable`, `opcache.validate_timestamps` or
  `opcache.revalidate_freq` line was found under `/etc/php/8.3/fpm`, so
  PHP's defaults apply (`validate_timestamps=1`, `revalidate_freq=2`) unless
  they are set elsewhere: FPM sees a new `bootstrap/cache/config.php`
  within about 2 s. The runbook still reloads it after each
  `config:cache`, with `systemctl try-reload-or-restart php8.3-fpm`. A
  `grep -r` does not follow the `conf.d` symlinks into
  `/etc/php/8.3/mods-available`; to rule that out, read the effective
  values, for example
  `php-fpm8.3 -i | grep -E '^opcache\.(enable|validate_timestamps|revalidate_freq) '`.
- Time zone. The host runs on America/New_York (`timedatectl`). The deploy
  user's crontab has no `CRON_TZ` line, so its lines run on Eastern time.
  The Laravel scheduler uses `app.timezone` (UTC) unless a task sets
  `->timezone()`, and the repository's systemd timers name UTC.

**Scheduled tasks inside the outage.** The night falls in EST (UTC-5), so
10 pm Eastern is 03:00 UTC. The app time zone is UTC (`config/app.php`),
so the Saturday 10 to 11 pm EST outage is 03:00 to 04:00 UTC on Sunday for
the Laravel scheduler and the systemd timers. Host cron lines run on the
host's Eastern time instead. The guard refuses scheduler admission from the hold
(`guard.authority` admits only `planned` and the two terminal phases)
until authority is published again, and there is no catch-up. An abort
that completes before 03:15 admits the scheduler in time for that slot,
so check which slots were really skipped.

| Task | When | During the outage | Decision |
| --- | --- | --- | --- |
| `crm:nightly` (client health, expired quotes, licence and insurance reminders) | 03:15 UTC | skipped if the scheduler is still held | If it was skipped, run `php artisan crm:nightly` once by hand after CRM step 9 releases the outbound hold. |
| `copilot:prune` | 03:35 UTC | skipped | Leave it to the next night. |
| `model:prune` | 03:45 UTC | skipped | Leave it to the next night. |
| `media:prune-editor-archives --apply` | 04:15 UTC | runs if the scheduler is admitted by then | Leave it. |
| Hourly and every-few-minutes tasks | each slot | skipped while held | None: each runs at its next slot. |
| `repro-backup.timer` | 03:30 UTC, up to 5 min jitter | held (step 4) | Start it in the undo list; it runs once right away. |
| `repro-recovery-kit.timer` and `.path` | 04:15 UTC and every `.env` change | held (step 4) | Start them in the undo list and run the kit once. |
| Deploy-backup retention cron (`deployment-storage.py scheduled-retention`) | `47 3 * * *`, host time: 03:47 Eastern (08:47 UTC) | outside: it runs about 4 hours 45 minutes after the outage ends. It is not wrapped and takes both deploy locks for its run | None. |
| The deploy user's `35 3 * * *` cron line (its command is not recorded here) | 03:35 Eastern (08:35 UTC) | outside | None. |
| The deploy user's other cron lines (`@reboot`, three every minute, `17 * * * *`, two `*/5`, `7,37 * * * *`) | each slot, host time | lines that W1's cron rules wrap are refused while held | None: each runs at its next slot. W1's rewrite keeps the storage guard in front of the wrapped lines (Release step 10). |
| `telnyx:prune-webhook-events` | 02:30 UTC (9:30 pm EST) | before the hold | None. |
| `crm:follow-up-digest` | weekdays 07:00 Eastern | not on a Saturday night | None. |

**Editing `.env` on the night.** Every edit must keep the file's inode. An
editor or `sed -i` that saves by rename drops the `repro-backup` ACL, and
then the next backup and the kit fail (`backup_failed`, daytime).
`wrong_database_engine` does not notice: with a cached config, as on
production, the probe never reads `.env`. Edit a private copy and copy it
back over the file:

```bash
cd /var/www/backend
work=$(mktemp -d "$HOME/env-edit-XXXXXXXX") && echo "$work"   # $work/before is the undo copy
inode=$(stat -c %i .env)
cp .env "$work/before" && cp .env "$work/next"
set_env() { grep -v "^$1=" "$work/next" > "$work/tmp"; printf '%s=%s\n' "$1" "$2" >> "$work/tmp"; cat "$work/tmp" > "$work/next"; }
set_env CRM_OUTBOUND_HOLD true        # one call per key
diff "$work/before" "$work/next"      # only the intended lines change
cp "$work/next" .env                  # writes into the existing file: same inode, ACL kept
test "$(stat -c %i .env)" = "$inode" && echo "inode kept"
getfacl -cp .env | grep '^user:repro-backup:'   # once the off-site units are installed
```

To undo an edit, `cp "$work/before" .env` the same way, then apply it. The
work folder holds secrets (APP_KEY): remove it with `rm -r "$work"` once the
undo list no longer needs it.

**Before the hold, no outage.**

1. 7:45 pm: pause Studio with its documented pause file, as `maverick`:
   `touch ~/.local/share/repro-studio/paused`. `worker.sh` refuses to start
   while it exists; let the running job finish.
2. 9:20 pm: pause the long-job queues as `www-data`, as the deploy user,
   through the queue flag helper that W1 installs (Release and target
   preparation step 10). The helper runs the `queue_pause` command from
   `host.json` (`umask 0022`) for one queue that `host.json` lists. As the
   deploy user `queue:pause` itself fails on the `www-data` 755 cache
   folders. As root it creates root-owned folders where none existed, and
   later cache writes into them fail (see "Read-only checks before the
   night" above). The deploy user has no sudo rule for `runuser`; the
   helper's sudoers line is its only way in:

   ```bash
   for q in studio:studio database:media-archives database:default \
       database:integrations database:watermarks; do
     sudo -n /usr/local/sbin/repro-queue-flag pause "$q"
   done
   # Only if the repro-lab-tour program is installed. "iguide-lab-tour" alone
   # would pause database:iguide-lab-tour, the wrong connection, and the
   # helper refuses any queue that host.json does not list.
   sudo -n /usr/local/sbin/repro-queue-flag pause iguide-lab-tour:iguide-lab-tour
   # Each flag is a www-data file; workers of both owners read it.
   for q in studio:studio database:media-archives database:default \
       database:integrations database:watermarks; do
     h=$(printf '%s' "illuminate:queue:paused:$q" | sha1sum | cut -c1-40)
     stat -c "%U %a $q" "/var/www/backend/storage/framework/cache/data/${h:0:2}/${h:2:2}/$h"
   done
   ```

   Every line must show `www-data 644` (add the lab-tour queue to the
   check if you paused it). If the helper exits non-zero, stop and check
   before going on: 1 is a refusal (not root, a host config the
   controller refuses, or a queue `host.json` does not list), 2 a bad
   argument, 3 a failed or timed-out command, after which the flag may or
   may not have changed. Wait for their running jobs, then stop the
   workers that the `long_job_processes` patterns in `host.json` match (for example
   `repro-studio` and `repro-lab-tour` in the user Supervisor, and
   `repro-media-archives.service`). The hold refuses while one still runs,
   because `host.json` sets `require_long_job_predrain: true`. Identify stale reservations
   separately; never force-requeue an uncertain payment/message/provider
   operation. The observed Studio worker timeout is 7,200 seconds and its
   retry interval 7,260; the database connection defaults to 2,100 seconds
   for long media assembly. Do not interrupt a long provider job to shorten
   the window. Lab-tour jobs can run 600 s, the whole default drain limit.
3. Before 9:50 pm: CRM steps 1 to 3 in
   [Freeze and replay steps](#freeze-and-replay-steps): record and pause
   Aryeo, hold CRM outbound sends, reply forwarding and calendar sync, hold
   the website recruiting scheduler (not built). Apply the flags as in
   that section: `config:cache` and `queue:restart` as the deploy user,
   the way Deploy-App runs them, and `systemctl try-reload-or-restart
   php8.3-fpm`. Never clear the cache, which erases the queue pause flags
   from step 2.
4. 9:50 pm: preflight as before (no call in progress, Aryeo recorded and
   paused, no upload in flight). Once the off-site units are installed, also
   hold the backup and kit units for the night and list them as "nothing
   due": the 03:30 UTC backup falls inside the outage, and the kit's path
   unit fires on every `.env` edit
   (see [Timers and the monthly reboot](#timers-and-the-monthly-reboot)):

   ```bash
   sudo systemctl stop repro-recovery-kit.path repro-recovery-kit.timer repro-backup.timer
   systemctl list-timers --all 'repro-backup*' 'repro-recovery-kit*'   # no next run
   systemctl is-active repro-recovery-kit.path                         # inactive
   ```

   `stop` lasts until the next boot; after a reboot, stop them again. Start
   them the same night (undo list), or `backup_stale` fires 26 hours after
   the last backup.

   Owner, at the alert provider: give the `site_unreachable` check a
   maintenance window that starts at the hold and ends by itself after 60
   minutes, and end it early after `cli.py reopen`. Pause no other check
   (see [Alerts](#alerts-b6-b7)).

**The hold: the outage clock starts here.**

5. Before Approval 1, while the site is still open, check that the nginx
   holds are where they must be (ordering M6). `nginx -t` passes even if
   one server block lacks an include:

   ```bash
   sudo nginx -T 2>/dev/null | grep -nE '^# configuration file|server_name|location |repro-cutover-hold|repro-holds'
   ```

   Each Dashboard server block that reaches PHP (in `frontend.conf` and
   `laravel.conf`) must show both the `repro-cutover-hold` include and
   `include /etc/nginx/repro-holds/*.conf;`, with lower line numbers than
   the block's first `location`. If not,
   stop: no approval. W2 must also rehearse the runtime 503 check of CRM
   step 5. Owner option: apply the edge hold (CRM step 4) a few minutes
   before Approval 1 instead, while the site is open, and run the CRM step
   5 check at once; producers then get 503 a few minutes earlier.

   Approval 1, "start freeze". The operator writes the approval from
   `cli.py approval-template --phase hold` to a root-owned 0600 file after
   the owner's typed approval, and runs `cli.py hold --approval FILE`
   within 5 minutes (the approval's `expires_at`):

   ```bash
   cli=/opt/repro-cutover-fence/production/cli.py
   sudo sh -c "umask 077; /usr/bin/python3 -I -S $cli approval-template --phase hold > /root/approval-hold.json"
   sudo /usr/bin/python3 -I -S "$cli" hold --approval /root/approval-hold.json
   ```

   The journal records phase `holding`, `started_at` and the pending hold
   before anything changes. Then:
   - the nginx open marker `/run/repro-cutover-ingress/open` is removed, so
     both vhosts answer 503 with `Retry-After: 30` at server level, including
     `/cubicasa_webhook.php` and `/iguide_webhook.php`;
   - `/etc/repro-database-cutover/active` is published under both deploy
     locks, so no deploy is between its check and its changes;
   - every queue in `host.json` is paused (nine, ten with lab-tour);
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
   run stays `holding` with admissions closed. A failed freeze cannot be
   retried: a second `cli.py freeze` refuses with "Interrupted action
   requires a verified pre-write abort". Run `cli.py abort` at once; do not
   wait for the watchdog (ordering L7).
8. Record source release/configuration fingerprints, source snapshot SHA-256,
   target UUID, worker/scheduler state, queue counts/reservations, and a journal
   of received/retried provider events. Keep this protected evidence outside
   the release archive. Preserve the original environment file separately.
9. Data steps as in [Import into the empty target](#import-into-the-empty-target).
   Not a path to MySQL at this candidate: `cli.py` has no `prepare` or
   `promote` action, the adapter's `evidence` and `seal_mysql` refuse, and
   its rollback does not undo a `prepare` yet (D12), so a run covers the
   hold, the freeze and the pre-write rollback only. A SQLite dress
   rehearsal goes from step 8 to `cli.py abort` and the undo list.

   In the SQLite phases (`planned`, `holding`, `frozen`, `ready` and
   `sqlite_authoritative`), that is from `cli.py init` until `promote`
   after Approval 2 and again after a reopened SQLite rollback, the MySQL
   probe must never report `writable` or `business_activity`. Only the
   MySQL path makes them true: `seal_mysql` opens the gate, and MySQL
   traffic then counts as business activity (MySQL adapter lane items 3, 7
   and 18). `Runner.perform` checks the probe before anything else, before
   a `planned` tick returns, so a tick in `planned` (C1, and every day
   until the night) or after a reopened SQLite rollback that sees either
   value records `recovery_required`. The guard then refuses every wrapped writer
   (`guard.authority` admits only `planned` and the two terminal phases):
   workers that restart, the scheduler and the wrapped cron lines stop while
   the web keeps writing SQLite. Neither `abort` nor the watchdog rolls back
   from there (ordering H3). So run no import and change no grant on the
   probed target outside the night's frozen window, and run import
   rehearsals in the nightly runner's disposable MySQL.

**Watchdog.** `repro-cutover-watchdog.timer` runs `cli.py tick` every minute
from boot (`OnCalendar=minutely`). In a held phase it closes admissions again
if a reboot or a restart reopened them, and re-drains the scheduler under the
new boot. At 45 minutes from the hold it rolls back to SQLite. All deadlines
count from the hold, so the drain uses the same 60-minute outage budget. The
service allows a tick 25 minutes, enough for the controller-lock wait and a
whole rollback; while a tick runs, an operator command is refused with
"Another host operator holds the cutover lock" and can be retried. The
watchdog does not reopen: run `cli.py reopen` and the undo list after it.

**Timing on the night.** The outage clock starts at the approved hold
(`started_at`), not at `FREEZE_AT`. Expected minutes from the hold for a
MySQL night, from the ordering review; every row after the freeze assumes
the MySQL adapter lane exists:

| Step | Budget in the plan | Expected |
| --- | --- | --- |
| Approval 1 and `cli.py hold` | approval valid 5 min | 1 |
| Producer edge hold | none | 1-2 |
| `cli.py freeze` | at most 10 | 3 |
| Step 8 evidence; frozen online backup (about 21 s), SHA-256, integrity | none | 3 |
| `schema-drift`, `migrate.py plan`, exception review | none | 3-5 |
| `import-empty` plus `verify-fields` (measured 593 to 754 s) | 12, hard limit 20 | 12 |
| Migrations, `complete-schema`, final schema check | none | 3-5 |
| Final SQLite and MySQL backups off-site (both required by `Evidence`) | none | 5 |
| `cli.py prepare`: `.env` switch with the write gate closed, `config:cache`, `systemctl try-reload-or-restart php8.3-fpm` | none | 2 |
| Still inside `prepare`: read-only smoke on the switched config and the writer-policy check (lane item 7), then `evidence()` (`Runner._prepare` collects it after `adapter.prepare`) | none | 5 |
| Approval 2, accepted before 0:45 | 5 | 2-5 |
| Promote (`seal_mysql` opens the gate), reopen, queues, workers, scheduler | none (not built) | 3 |

- Expected total: about 43 to 50 minutes. The go point falls at about 0:40
  to 0:47, marginal against the 0:45 watchdog. With the stated maximums
  (drain 10, import 20) it is past 0:55, and the watchdog rolls back first.
  In practice the import has about 15 minutes.
- Rollback: the watchdog fires between 45:00 and 46:50 (next minute tick
  plus up to 50 s for the lock), the rollback takes about 2 to 3 minutes
  and `cli.py reopen` about 1 to 2. SQLite is open by about 0:50 to 0:53.
- Producers stay held until CRM step 6, so they see more than 60 minutes
  of 503s.
- A SQLite dress rehearsal at this candidate (hold, edge hold, freeze,
  step 8, abort, reopen) should take about 11 to 14 minutes: the first
  four rows plus the rollback and reopen figures. That is an estimate; it
  has not been measured on the host.

Checkpoints. If one is missed, run `cli.py abort` at once:

| Checkpoint | By (from the hold) |
| --- | --- |
| Freeze done (`cli.py status` shows `frozen`) | 0:12 |
| Import started | 0:15 |
| Import plus verification done | 0:27; abort at 0:30 at the latest |
| Off-site backups done | 0:35 |
| Go decision | 0:40 |
| Approval 2 accepted | 0:44 |

**Approvals that run out.** An approval is valid for 5 minutes
(`Runner.APPROVAL_SECONDS`). The runner undoes nothing when one expires.

- Approval 1 expires before `cli.py hold`: nothing has changed on the
  runner's side. Issue a new template if the owner still wants to go. If
  the owner does not answer, call the night off and run the pre-hold undo
  list below; the pre-hold holds have no deadline of their own.
- Approval 2 (MySQL lane, not built) expires: run `cli.py abort` at once.
  Its `seal_sqlite` restores the SQLite configuration that `prepare`
  switched (MySQL adapter lane item 10), with the write gate still
  closed. Then `cli.py reopen` and the undo list. Do not leave the site
  held until the watchdog, which would do the same at 0:45.

**Undo.** `cli.py abort` (or the watchdog) re-closes admissions, drains,
unseals SQLite and publishes boot-bound SQLite authority. From that moment
the guard admits the cron scheduler again, before `cli.py reopen` and while
ingress is still closed, so scheduled commands write SQLite from then on.
`crm:calendar-sync` stays held by `CRM_CALENDAR_SYNC_HOLD` until CRM step
10, and `crm:website-intake` completes intake receipts already saved in the
Dashboard. At this candidate neither `abort` nor `reopen` touches `.env`
or the config cache, and nothing switches them. Once the adapter lane
exists, `abort` restores them through `seal_sqlite` (MySQL adapter lane
item 10). `cli.py reopen` verifies that authority through the guard, checks
the live host again
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
installed fences or start a new run before the next deploy. Neither is
written yet. A new run cannot simply `init`: the journal lives at the
guard's fixed state path (`/var/lib/repro-cutover-fence/state.json`), and
`init` refuses with "A run already exists in this directory" while it is
there (`Runner.initialize`). Open: a reviewed retire step that moves the
old journal and its records, whole and unchanged, to a root-only archive,
retires the old policy, and only then lets a new `init` run. Moving the
journal aside is not editing it; keep the copy as evidence.

If `abort` or a tick prints "MySQL writes may exist. Use separately verified
recovery", the journal is `recovery_required`. From there neither `abort`
nor the watchdog rolls back, the site stays held, and `site_held` pages.
Open: no runbook path covers `recovery_required`, including the case where
the only MySQL writes came from the import (see the
[MySQL adapter lane](#mysql-adapter-lane-not-built) item 3). Do not edit
the journal; call the owner.

**Undo list, after an abort or a reopen.** Every hold the night sets, in the
order to undo it. It applies after `cli.py abort` or the watchdog on
SQLite. Once the adapter lane exists it also applies to a MySQL reopen:
step 3 then runs before that reopen too, step 1 expects
`mysql_authoritative` after it, and steps 2 and 4 do not apply, because
the MySQL reopen resumes the queues and starts the workers itself. Step 3
comes before any reopen because Studio's `worker.sh` refuses to start
while the pause file exists (`scripts/install-studio-runtime.sh`; the
lab-tour worker checks the same file). If that makes a `start_workers`
command exit non-zero, the reopen fails: adapter `reopen` raises at the
first host command that does.

1. `cli.py status` shows `sqlite_authoritative` (after an abort) or
   `mysql_authoritative` (after a MySQL reopen). If it shows
   `recovery_required`, stop (see above).
2. Database settings, after an abort on the MySQL path: `seal_sqlite` has
   restored them (MySQL adapter lane item 10). Check before `cli.py
   reopen` that `php artisan config:show database.default` prints
   `sqlite`. If it does not, stop and call the owner. Nothing to do at
   this candidate, which never switches them.
3. Studio pause file, before any reopen (SQLite or MySQL), as `maverick`:
   `rm ~/.local/share/repro-studio/paused` (and `paused-lab-tour` if set),
   so the Studio program starts with the others. If it already went FATAL:
   `supervisorctl -c ~/.local/share/repro-studio/supervisord.conf start repro-studio`.
4. `cli.py reopen`. It resumes every queue in `host.json` through
   `queue_resume`, as `www-data`, the long-job queues paused at 9:20 pm
   included, starts the worker groups, recreates
   the open marker and, after an abort, lifts the deploy fence. Check
   `sudo supervisorctl status`, the user Supervisor's `status` and
   `systemctl status repro-media-archives.service`.
5. Alerts: the owner ends the `site_unreachable` maintenance window at the
   provider. `queue_stale` clears once the queues drain.
6. Producer edge hold: CRM step 5 (check), then step 6 (release, and
   `PRODUCER_INGRESS_HOLD=false` if it was set). Do it promptly: until then
   `/api/external/`, Resend, Stripe and Aryeo get 503, and Aryeo stops
   retrying after about 2 minutes.
7. Recruiting `worker_paused` back to false (website, one row update with
   approval), after step 6 so the outbox reaches an open edge. Not built.
8. CRM steps 7 to 12 in order: website ledger (not built), Resend and
   `CRM_DEFER_REPLY_FORWARDING=false`, the outbound release check and
   `CRM_OUTBOUND_HOLD=false`, the calendar reconcile and
   `CRM_CALENDAR_SYNC_HOLD=false`, Stripe at +30 minutes and +2 hours, and
   Aryeo last. After an abort they run against SQLite; the commands do not
   depend on the engine.
9. Missed scheduled tasks: `php artisan crm:nightly` once, after the
   outbound release, only if its 03:15 UTC slot fell while the scheduler
   was held. After an early abort the guard admits the scheduler again, so
   it may already have run (see the table above).
10. Backup timers and the kit:
    `sudo systemctl start repro-backup.timer repro-recovery-kit.timer repro-recovery-kit.path`.
    Each timer runs once right away if its time has passed (`Persistent=true`).
    Then `sudo systemctl start repro-recovery-kit.service` once, so the kit
    picks up the night's `.env`. After a MySQL reopen this is open: the base
    backup copies SQLite only and the MySQL leg (`mysql-recovery`) is
    preview only, so decide before the night what runs.
11. Deploys: after a reopened rollback the policy still pins the old
    release. Retire the installed fences or start a new run before the next
    deploy. Open: neither step is written, and a new `init` refuses while
    the old journal is in place (see "Undo" above).
12. Remove the `.env` work folders (`rm -r "$work"`) once nothing above
    needs them.

**If the night is called off before the hold** (Approval 1 not given, or a
no-go before 10 pm), undo the pre-hold steps:

1. Cancel the `site_unreachable` maintenance window at the provider.
2. Remove the Studio pause file and start Studio (as in step 3 above).
3. Resume the long-job queues as `www-data` through the same helper as
   the pause: `sudo -n /usr/local/sbin/repro-queue-flag resume
   <connection:queue>` for each queue paused at 9:20 pm, then start the
   stopped workers through their Supervisor or systemd. The guard admits them in `planned`.
4. CRM steps 8 to 10 in order (Resend and reply forwarding, the outbound
   release check with `--since=$FREEZE_AT`, the calendar reconcile), then
   step 12 (Aryeo). The edge hold was never applied, so steps 5 and 6 do
   not apply.
5. Recruiting `worker_paused` back to false (not built).
6. Start the backup timers and the kit path unit, and run the kit once
   (step 10 above).

## MySQL adapter lane (not built)

This candidate stops at the SQLite hold, freeze and pre-write rollback. The
next lane must build and test everything below before a night can reach
MySQL. The list comes from the two runbook reviews of 25 November
(executability findings 1, 2, 3, 13 and 15; ordering findings H1 to H4, M2
to M4 and M8), the verifier rounds after them and the production facts
measured that day. Each item is a requirement for that lane, not a step to
run now.

**Host probes.** These are needed for W1, so even a SQLite dress rehearsal
waits for items 1 to 3.

1. `host.json` carries `commands.identity` and `commands.mysql` as absolute
   argument lists; `adapter.load_config` refuses anything else. Every
   `cli.py` action except `approval-template` calls `adapter.observe()`,
   which runs the MySQL probe and refuses on a failed command, invalid JSON
   or a missing boolean. Neither probe is written.
2. The identity probe prints the six `Identity` fields as JSON (`run_id`,
   `release_sha`, `artifact_sha256`, `source_sha256`, `config_sha256`,
   `target_uuid`), measured on the host, not copied from the policy. The
   runner compares all six with the pinned identity before `init`, `hold`,
   `freeze`, `abort` and every watchdog `tick`
   (`Runner._verify_identity`), and `hold` also compares the preflight
   digest, which includes them. A mismatch refuses all of them, the
   automatic rollback included. `refresh` reads the probe too, but compares
   only `source_sha256`, `release_sha` and `artifact_sha256` with the
   policy (adapter `host_authority`, `guard.verify`), so a different
   `config_sha256` does not stop it. So
   `config_sha256` must leave out the flags the night changes after
   `init`: `CRM_OUTBOUND_HOLD`, `CRM_DEFER_REPLY_FORWARDING`,
   `CRM_CALENDAR_SYNC_HOLD`, `PRODUCER_INGRESS_HOLD`, and the database
   settings that `prepare` switches and `seal_sqlite` restores (item 10,
   ordering M4).
3. The MySQL probe prints exactly `{"writable": bool, "business_activity": bool}`.
   The runner's contract fixes what the two values must mean
   (`Observation` in `scripts/cutover/runner.py`): `frozen()` needs both
   false, `prepare` must end with `engine` `mysql` and both still false,
   and only `seal_mysql` may turn `writable` true. So `writable` cannot
   mean "the app account can reach MySQL with the token row in place": that
   is true from Release step 4, long before `init`.
   - `writable` is the state of a MySQL write gate that keeps the
     application's runtime route (FPM, workers, scheduler) from writing.
     The gate is closed from before `init` through `prepare`, while the
     import, `php artisan migrate` and `complete-schema.php` run as the
     maintenance and migrator accounts (`repro_maint`, `repro_migrator`),
     which are outside the gate. `seal_mysql` opens it;
     `seal_sqlite` makes sure it is closed. The probe reads the gate itself,
     not what the server or those accounts can do.
   - `business_activity` means application rows changed after the recorded
     end of the import (for example a binlog position the import records).
     Migrations and `complete-schema.php` do not count.

   Open: which gate. Candidates, each to be proven in W2:
   - The app account has no write privilege on `repro_dashboard` and no
     `UPDATE(id)` on the token row until `seal_mysql` grants them;
     `seal_sqlite` revokes them. Release step 4 then grants the token
     only to the migrator and token check accounts, never to the app
     account, through the adapter lane's `accounts.sql`. The lane builds
     this option.
   - The app account is locked (`ALTER USER ... ACCOUNT LOCK`) until
     `seal_mysql`. Then nothing in the app can read MySQL either, so the
     read-only smoke (item 11) needs its own account.

   Not enough on its own: withholding only the token grant, because the
   connections with `repro_writer_exempt_tables` (`mysql_queue_worker`,
   `mysql_scheduling_cache` and `mysql_cache_locks` in
   `config/database.php`) write `jobs`, `cache` and `cache_locks` without
   the token; and `super_read_only`, because it also stops the importer and
   the migrations, which run while the run is `frozen` and the probe must
   report `writable` false.

   Why it matters: `init` needs both false, and once either is true, in any
   phase from `init` on (`planned` and a reopened SQLite rollback
   included), the runner records `recovery_required` and refuses `abort`
   and the 45-minute rollback with "MySQL writes may exist. Use separately
   verified recovery" (`Runner._rollback`, `Runner.perform`). The guard
   then holds every wrapped writer (Freeze all writers, step 9). W2 must
   prove that the token row, the import, `php artisan migrate`,
   `complete-schema.php` and `prepare` keep both false, that `seal_mysql`
   turns `writable` true, and that `seal_sqlite` turns it false again
   (executability 3, ordering H3).
4. A live configuration probe. `observe()` hard-codes `engine="sqlite"`,
   and `publish_authority` and `refresh` copy `sqlite_live_config_sha256`
   from the policy instead of measuring it. `engine` must be the cached
   config's default driver (`bootstrap/cache/config.php`, else `.env`
   `DB_CONNECTION`); the off-site alert probe already reads the driver
   this way. That measured value is what `Runner._prepare` and
   `Runner._promote` require to be `mysql`, and what the rollback's
   `sealed("sqlite")` requires to be `sqlite`. `reopen` must refuse unless
   it matches the engine the journal makes authoritative. Without it, a
   reopen after a no-go whose configuration was not restored starts
   workers, the scheduler and FPM on MySQL while the journal says SQLite
   (ordering H2). Once measured, the live config hash must still equal the
   policy's single `sqlite_live_config_sha256` (`guard.verify`) after the
   night has changed the CRM and producer flags, which stay changed through
   an abort. So it must leave out the same flags as item 2, or the policy
   must pin both SQLite configurations, which the guard does not support
   today.
5. The activation receipt sets `application_verified` without any
   application check. Add the smoke command from item 11.

**Controller actions.**

6. `cli.py prepare`, `cli.py promote` and `cli.py approval-template --phase
   promote` (Approval 2, "reopen on MySQL"). The runner has both actions
   (`Runner._prepare`, `Runner._promote`); `cli.py` exposes neither. A
   promote approval expires 5 minutes after it is issued and never later
   than 45 minutes after the hold (`Runner._request`).
7. Adapter `prepare`, `evidence` and `seal_mysql`, and a wider
   `seal_sqlite`. The reference `FixtureAdapter` in
   `scripts/cutover/rehearse.py` shows the contract the runner checks:
   - `prepare` makes the configuration switch of item 10 with the write
     gate (item 3) still closed. Afterwards `observe()` must show `engine`
     `mysql` and `frozen()` still true, or `Runner._prepare` refuses.
   - `evidence()` returns the `Evidence` record: snapshot and report
     digests, the starting binlog file and position, and `true` for
     forward parity, final schema, read-only smoke and both off-site
     backups (final SQLite and MySQL).
   - `seal_mysql` opens the write gate. Afterwards `sealed("mysql")` must
     hold: engine `mysql`, MySQL writable, SQLite not writable, no
     business activity, admissions closed, workers stopped and deploys
     fenced (`Observation.sealed`).
   - `seal_sqlite`, which today unseals the SQLite file and removes the
     open marker, must also check that the write gate is closed and undo
     the switch of item 10. Afterwards `sealed("sqlite")` must hold, engine
     `sqlite` included, or `publish_authority("sqlite")` refuses and the
     rollback fails on every tick.

   `db:writer-policy-check` takes the writer token in an empty
   transaction. An account with only `SELECT` on the token table cannot
   take it (the "readonly account" case in
   `tests/MySql/contracts/MySqlWriterTokenContract.php`), and a locked
   account cannot connect, so with either gate candidate the check reports
   a problem while the gate is closed. Do not run it after `seal_mysql`:
   `_promote` sets `writes_possible` before it calls `seal_mysql`, so any
   failure from there records `recovery_required`, and no rollback runs.
   Prefer running its token step during `prepare`, as part of the evidence
   before Approval 2, under a separately granted check account
   (`repro_token_check` in `accounts.sql`, Release step 4; for
   example through a staged config cache, as for `php artisan migrate` in
   [Import into the empty target](#import-into-the-empty-target)). A
   failure there stops before the point of no return. This is open.
8. A MySQL `reopen`: verify the `mysql_authoritative` proof through the
   guard, resume the queues, start the workers, create the open marker and
   write an activation receipt for engine `mysql`. In
   `mysql_authoritative` the runner refuses everything but `tick`, so this
   reopen is the only way forward. Remove the Studio pause file first (undo
   list step 3); otherwise Studio cannot start and the reopen can fail with
   it.
9. MySQL writer profiles. Every profile in `wrappers.json` says `"engine":
   "sqlite"`, so the guard refuses every wrapped writer on MySQL until the
   profiles change at promotion.

**Switch, smoke and timing.**

10. The `.env` switch and its undo (ordering H2) belong in the adapter:
    `prepare` makes the switch and `seal_sqlite` undoes it. The runner
    leaves no other place:
    - `prepare` keeps a copy of the SQLite `.env`, writes the MySQL keys
      in place (ACL kept, see "Editing `.env` on the night" in
      [Freeze all writers](#freeze-all-writers)), runs `php artisan
      config:cache` as the deploy user (below) and `/usr/bin/systemctl
      try-reload-or-restart php8.3-fpm`. The write gate stays closed, so
      the observed engine is `mysql` while nothing can write MySQL.
    - `seal_sqlite` copies the saved `.env` back in place, runs
      `config:cache` the same way and the same `try-reload-or-restart`, so
      `cli.py abort` and the unattended 45-minute watchdog undo the switch
      with no operator. `cli.py reopen` then needs no manual step, and item
      4 makes it refuse if the restore did not happen.

    `config:cache` must not run as root. It deletes
    `bootstrap/cache/config.php` and writes a new one owned by the caller,
    with the caller's umask, and `runuser` keeps the caller's umask. The
    watchdog service sets `UMask=0077`, so a root run in an unattended
    rollback leaves a `root:root` 0600 file: FPM and every worker then fail
    to boot ("Permission denied" in `LoadConfiguration.php`), and so does
    the next deploy's `config:cache`, which must read the file before it
    can delete it. Run it the way Deploy-App does, as the deploy owner in
    group `www-data` with its own umask:

    ```json
    ["/usr/sbin/runuser", "-u", "maverick", "-g", "www-data", "--", "/bin/sh", "-c", "umask 0022 && cd /var/www/backend && exec /usr/bin/php artisan config:cache"]
    ```

    In the QA image, from a 0077 parent, this form wrote `maverick:www-data`
    0644, which `www-data` could read; the root run wrote `root:root` 0600.
    W2 must check after a watchdog rollback that `www-data` can read the
    file (`sudo -u www-data test -r /var/www/backend/bootstrap/cache/config.php`).

    Not `systemctl reload`: the freeze's `drain_fpm` may have stopped FPM,
    and `reload` exits 1 on a stopped unit ("is not active, cannot
    reload"), while `try-reload-or-restart` exits 0 and leaves it stopped
    (both checked under systemd 252 in the QA image). The adapter's `_run`
    raises on a non-zero exit, and `_rollback` calls `seal_sqlite` before
    it publishes authority, so a plain `reload` would fail the watchdog
    rollback on every tick. A stopped FPM reads the restored config when
    `start_workers` starts it. The controller runs as root, so its host
    commands take no `sudo`; operator shells as `maverick` do.

    Ruled out: a switch made by hand before Approval 2. Once item 4
    measures `engine`, an unattended watchdog rollback would find `mysql`,
    fail `sealed("sqlite")` on every tick and leave the site held. Also
    ruled out: moving the switch into `promote`. `Runner._prepare` refuses
    unless the engine is `mysql` after `prepare`, and `Runner._promote`
    refuses unless it is `mysql` before.

    A rollback after `prepare` runs `queue_pause` (`close_admissions`)
    while the default connection is MySQL and the gate is closed. With the
    `file` cache store measured on 25 November the flag goes to
    `storage/framework/cache`, not a database table. With the `database`
    store it would go to MySQL and fail before `seal_sqlite`; recheck the
    store before the night. W2 must prove a rollback after `prepare`.

    The keys the switch sets are in
    [Validate before reopening traffic](#validate-before-reopening-traffic).
11. A smoke path that does not need the nginx hold to open. The gate map
    defaults to 1 with no exemption (`repro-cutover-gate.conf`), so an HTTP
    smoke test through nginx is impossible before the point of no return
    (ordering M8). Either a CLI smoke command run as the app user against
    the target, or a reviewed bypass design. This is open. The same gap
    means nothing confirms the flags in FPM's effective config:
    `config:cache` and `crm:outbound-release-check --json` show the CLI
    view only, and Deploy-App's OPcache reset is an HTTPS fetch that the
    hold answers with 503 (executability 15).
12. The way back after MySQL writes (ordering H4), with a time budget. In
    `mysql_authoritative` `cli.py abort` fails, and the guard keeps
    admitting MySQL writers and the scheduler while the proof is valid. A
    new SQLite file fails the pinned source identity, and a new run's
    `init` needs a live SQLite route. Steps to write and rehearse:
    1. Remove the open marker: `sudo rm /run/repro-cutover-ingress/open`
       (nginx answers 503 at once, no reload).
    2. Run each `stop_workers` command from `host.json`, and `sudo
       systemctl stop repro-media-archives.service`.
    3. Revoke the authority proof so the guard refuses the scheduler and
       every restart: `sudo rm /run/repro-cutover-fence/verified-engine.json`.
       Wait until no writer process is left.
    4. Export and verify as in [Rollback](#rollback-has-two-different-paths).
    5. Move the old journal and its records, whole and unchanged, out of
       `/var/lib/repro-cutover-fence` to a root-only archive (the reviewed
       retire step under "Undo" in [Freeze all writers](#freeze-all-writers),
       not written yet). `init` refuses while `state.json` is there, and
       the guard's state path is fixed. Moving it is not editing it.
    6. Write a new policy for the new SQLite file and run `init` with the W1
       marker method (create the marker by hand, `init`, remove it, then
       `refresh` and `reopen`).

    Budget: not timed end to end. The measured parts are the reverse export
    plus verification (174.6 s, 209.2 s and 790 s in local rehearsals) and
    the forward field check (135.4 s). On those figures the tool time alone
    is likely 15 to 30 minutes of further write outage; that is an
    estimate, not a measurement. Reconciling external side effects has no
    bound.
13. Approval expiry branches (ordering M3), written in
    [Freeze all writers](#freeze-all-writers) under "Approvals that run
    out".
14. `host.json` complete before `init` (ordering M4): `hold` refuses a
    changed host config (`preflight_sha256` includes it) and `init` cannot
    run twice. That includes the lab-tour queue
    (`iguide-lab-tour:iguide-lab-tour`) when the program is installed, which
    the host-config check accepts from the code-fix lane on (`71f7db04`).
15. The scheduler starts at authority publication, before `reopen`, on both
    paths (ordering M2). `crm:calendar-sync` is held by
    `CRM_CALENDAR_SYNC_HOLD` until CRM step 10. Any other task that must
    wait for a CRM step needs its own hold.
16. Telnyx texts during the hold (executability 13). The nginx hold answers
    503 before Laravel, and the freeze drains PHP-FPM. The maintenance-mode
    receiver on `claude/telnyx-receiver-20261125` runs inside Laravel, so it
    never sees a text during the hold. Telnyx retries a few times and then
    stops (see the [provider table](#providers-during-a-60-minute-hold)).
    Options:
    - an exempt nginx location for the Telnyx text paths, served by a
      capture that needs no database and is not drained (a reviewed gate
      exemption). The candidate already has the unbound pieces:
      `TelnyxCutoverCapture` (a standalone HTTP adapter for all four Telnyx
      paths: messaging, status, voice and `/shared-mms/{uuid}`, which it
      stores as `shared-mms:<uuid>`; `tests/Unit/TelnyxCutoverCaptureTest.php`
      covers each) on top of the file spool `TelnyxCutoverInbox`, which
      accepts the same four destinations. Neither is reviewed for
      activation, and no entry point serves them yet;
    - a Telnyx failover URL on a separate host that captures to a spool for
      replay after the hold.

    Open: no option is chosen or built.

**Host commands and reboots.**

17. Queue commands as `www-data`, with an explicit umask. The adapter
    must refuse a `host.json` whose `queue_pause` or `queue_resume` does
    not run as `www-data` and set its own umask: anything but the
    `runuser -u www-data -- /bin/sh -c 'cd /var/www/backend && umask 0022
    && exec /usr/bin/php artisan queue:pause "$1"'` form (and
    `queue:resume`) in [Release and target preparation](#release-and-target-preparation)
    step 10. The adapter lane's config contract does this. In this
    candidate `load_config` still checks only that each is an absolute
    argument list, and `_run` passes no umask, so a command inherits the
    watchdog's `UMask=0077`. The pause flags live in the `file` cache in
    `www-data` 755 folders, so the deploy user's pause fails and the hold,
    abort and rollback fail with it (the deploy user's own pauses go
    through `repro-queue-flag`, step 10). Root's pause creates root-owned
    folders where none existed, and later cache writes into them fail. A
    flag made under 0077 is 0600, so the workers that run as `maverick`
    do not see the pause. The same rule covers every artisan command the
    controller runs: `config:cache` in item 10 runs as the deploy owner
    with umask 0022, never as root.
18. A MySQL reboot and re-close path. `Runner.perform` keeps
    `mysql_authoritative` only while the host shows `sealed("mysql")` or
    `mysql_live()`. After a reboot that follows the MySQL reopen, `/run` is
    empty, so admissions are closed and `mysql_live()` is false.
    `sealed("mysql")` needs `business_activity` false, which item 3
    defines as no application rows changed after the import, so it stays
    true once traffic has run. There is no MySQL counterpart of
    `externally_held()`, and `refresh` re-publishes the deploy fence only
    in `planned` and `sqlite_authoritative` (adapter `refresh`). So the
    first watchdog tick after any such reboot, the monthly 02:00 reboot
    included, records `recovery_required`, and `refresh` and `reopen`
    cannot clear it. Removing the open marker by hand (item 12, step 1)
    has the same effect at the next tick, and item 12 must expect that.
    The lane needs:
    - a MySQL counterpart of `externally_held()` (MySQL the only writable
      engine, admissions closed), or a `business_activity` meaning that
      does not count writes made under MySQL authority;
    - `refresh` in `mysql_authoritative` that publishes the deploy fence
      again before it measures the host, as it does for
      `sqlite_authoritative`;
    - a W2 test: reboot after the MySQL reopen, then `refresh` and the
      MySQL `reopen` (item 8), with no `recovery_required`.

**Other branches the night depends on.** None is in this candidate.

- `claude/telnyx-receiver-20261125`: the per-task scheduler hold
  (`SCHEDULER_HOLD_ENABLED`, `php artisan scheduler:hold set <owner>
  --only=<task>`; off by default, needs `config:cache`, a hold lasts at most
  720 minutes) and the Telnyx maintenance capture with
  `telnyx:replay-spool`. The capture does not work behind the cutover hold
  (item 16).
- `claude/sqlite-observability-20261125`: `DB_EXPECTED_ENGINE`. If it is
  set to `sqlite` before the night, the switch must change it with
  `DB_CONNECTION` and run `php artisan queue:restart` as the deploy user,
  the way Deploy-App does (not as `www-data` or root; see
  [Freeze and replay steps](#freeze-and-replay-steps)), or every query on
  the default connection throws `DatabaseEngineMismatch`; `complete-schema`
  and `migrate` in a staged copy of the production `.env` fail the same way.
  The way back restores it. Its database-backed `/up` and `ops:heartbeat`
  also page during the hold; add both to the alert plan for the night.
- `claude/sqlite-backups-billing-20261125`: the nightly SQLite copy at
  01:40 server time (Eastern: 06:40 UTC in winter, outside the outage).
  Its cron line matches no rule in
  `cron-rules.example.json`, so `crontab.py` refuses it at W1, and a copy
  still running at `freeze` can hold `-shm` open, which makes the seal
  refuse. It also brings the
  engine-aware deploy preflight and retention: the candidate's
  `deployment-storage.py` still requires `database/database.sqlite` and
  never ages out bundles that hold a MySQL dump.
- `claude/sqlite-request-writes-20261125`: the schema cache
  (`SCHEMA_CACHE_ENABLED`). The dress pass mark needs no layout lookup on
  any of the 50 busiest endpoints, and the candidate still asks for the
  layout per request, so the speed mark cannot pass without it. With the
  cache on, the switch must set a new `SCHEMA_CACHE_RELEASE` and run
  `config:cache`, because the cache store must not be cleared. Its dialect
  guard is also not in this candidate's CI.

## Import into the empty target

Use a standalone online-backup artifact, never a raw copy of a live WAL database.
The import tool opens it read-only and verifies its checksum and integrity.
Create a private Python virtual environment and install
`scripts/mysql/requirements.txt`. The maintenance account must be scoped to the
new database. On the production target it is `repro_maint` from the adapter
lane's `accounts.sql`, which has no writer-token grant, so `php artisan
migrate` there runs as `repro_migrator`, the account with the token grant
([Release and target preparation](#release-and-target-preparation)
step 4). Its protected 0600 JSON file contains only `unix_socket`, `user`,
and `password`; credentials never belong in command arguments or source control.

First generate a fresh plan with a new private output directory:

```bash
python3 -B scripts/mysql/schema-drift.py \
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
python3 -B scripts/mysql/migrate.py plan \
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
python3 -B scripts/mysql/migrate.py import-empty \
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

With explicit MySQL process configuration and a separate staging cache, from
the staged release:

```bash
cd /protected/staged-backend
DB_CONNECTION=mysql DB_DATABASE=repro_dashboard \
  APP_CONFIG_CACHE=/protected/staging-cache/config.php \
  php artisan migrate --force
DB_CONNECTION=mysql DB_DATABASE=repro_dashboard \
  APP_CONFIG_CACHE=/protected/staging-cache/config.php \
  php artisan migrate:status --pending   # must report no pending migration
DB_CONNECTION=mysql DB_DATABASE=repro_dashboard \
  APP_CONFIG_CACHE=/protected/staging-cache/config.php \
  php scripts/mysql/complete-schema.php --app=/protected/staged-backend \
  --expected-server-uuid=<independently-observed-new-server-uuid> \
  --writers-stopped
```

The staged application's protected environment supplies its target socket and
credentials. With `APP_ENV=production` it needs the MySQL keys listed under
[Validate before reopening traffic](#validate-before-reopening-traffic);
without `REPRO_MYSQL_EXPECTED_SERVER_UUID` and an absolute `DB_SOCKET`, every
MySQL connection fails with "The approved MySQL UUID and an explicit local
socket are required". Require the `verified` result, validate the final schema, and restore
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
   the entry to a new manifest file:
   `python3 -B -m scripts.cutover.nightly.policy add-night --manifest reviewed-nights.json --entry <night>/manifest-entry.json --output reviewed-nights.next.json`.
6. After five consecutive reviewed nights, the last two on the frozen
   release, pin the manifest, build the summary with
   `--producer-receipts`, pin the summary, and run the readiness check.

Each approved night reads the live SQLite file through the online backup API
(about 21 s), keeps about 4.5 GB under the state directory, and runs a
disposable socket-only MySQL 8.4.13 for about 10 to 16 minutes. It needs
45 GB free and 12 GiB available memory. `retain_snapshots` is `null`, so
every snapshot stays: five nights keep about 22.5 GB. The README's example
state directory, `/home/maverick/repro-nightly`, is most likely on the
root file system, which had 96 GB free on 25 November (see the disk risk
in the [readiness record](mysql-production-readiness.md#operational-work-still-requiring-approval)).
`approved_exceptions` pins
`/home/maverick/repro-mysql-rehearsal-20261122/native-import-02/exceptions-private.json`
by SHA-256, so every night stops (`exceptions-pin-differs`) if that file
goes. No night can qualify yet. In
`scripts/cutover/nightly/nightly-config.json`, `finance`,
`reverse_triggers` (the reviewed trigger helper, which is not committed) and
`applicant_files_root` are `null`. Without an applicant files root,
`crm_applicant_file_parity_verified` is false, and with any applicant file
present the runner never sets it true, because it has no target-side
comparison or access check. `busiest_endpoints` in
`review-policy.draft.json` is also `null`, so the readiness check cannot
pass. At `4e4a262d` the committed `crm_migrations` list names the nine
migrations of 24 November. The code-fix lane (`6fa0f78e`) adds the tenth,
`2026_11_25_140000_add_replay_identity_to_recruit_application_receipts`,
and a test that fails when a migration creating or changing a CRM table is
missing from the list. The capture stops (`crm-ledger-differs`) unless the
live ledger has every listed migration, so with that list no night can run
before the candidate is deployed. `reviewed_schema_sha256` was pinned
while the live ledger had nine CRM migrations; the tenth adds two columns,
so the first night after the candidate is deployed will most likely stop
at `plan` (`reviewed-layout-differs`) until the digest is re-pinned (not
proven without a snapshot). `migrate.py plan` computes it from the
captured live file, so only a production snapshot can re-pin it. The
code-fix lane (`fa7193c2`) adds the steps to the
[nightly runner README](../scripts/cutover/nightly/README.md#configuration),
in a new section "Re-pin the reviewed layout"; follow them there. The order "deploy
the candidate, re-pin `reviewed_schema_sha256` from a production snapshot,
then start the five-night streak" is a decision for the owner. If the pinned finance command
writes through the application on a MySQL copy, provision the writer token
there first ([Release and target preparation](#release-and-target-preparation)
step 4).

## Validate before reopening traffic

Not executable at this candidate. Every check below that needs an HTTP
request through nginx, a worker on MySQL or a MySQL reopen waits for the
[MySQL adapter lane](#mysql-adapter-lane-not-built): the server-level hold
has no smoke bypass, workers start only through `admit.py`, which refuses
MySQL without guard authority, and only `cli.py reopen` creates the open
marker, on SQLite only.

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
preserved environment, editing `.env` in place ("Editing `.env` on the
night" in [Freeze all writers](#freeze-all-writers)). The switch is part of
the adapter's `prepare` and its undo part of `seal_sqlite`, not a manual
step ([MySQL adapter lane](#mysql-adapter-lane-not-built) item 10). Retain the
APP_KEY and all unrelated settings. With `APP_ENV=production` every MySQL
connection needs these keys, or it refuses to connect
(`config/database.php`, `app/Database/MySqlConnector.php`):

| Key | Value |
| --- | --- |
| `DB_CONNECTION` | `mysql` |
| `DB_DATABASE` | `repro_dashboard` |
| `DB_SOCKET` | `/run/repro-mysql84-native/mysql.sock` (must be absolute) |
| `REPRO_MYSQL_EXPECTED_SERVER_UUID` | the target's independently observed `@@server_uuid` |
| `DB_USERNAME`, `DB_PASSWORD` | the app account; it can write only once `seal_mysql` opens the write gate (lane item 3) |
| `DB_COLLATION` | `utf8mb4_0900_bin`, as CI runs it (`phpunit.mysql.xml`); the code default is `utf8mb4_unicode_ci` |
| `REPRO_MYSQL_WRITER_POLICY` | unset or `true` (on by default) |
| `DB_EXPECTED_ENGINE` | `mysql`, only if the observability branch is merged and the key is set |

The server must report `@@skip_networking=1` and an 8.4 version, or the
connection fails with "The configured MySQL connection did not reach the
approved socket-only server". Keep the optional MySQL pilots
(`REPRO_MYSQL_QUEUE_WORKER`, `REPRO_MYSQL_STUDIO_QUEUE_WORKER`,
`REPRO_MYSQL_IGUIDE_LAB_TOUR_QUEUE_WORKER`, `REPRO_MYSQL_SCHEDULING_CACHE`,
`REPRO_MYSQL_ROUTE_BUDGET_WRITER_TOKEN`) off unless they are approved
separately. `prepare` then applies it with `php artisan config:cache`, run
as the deploy owner in group `www-data` with umask 0022 (never as root;
[MySQL adapter lane](#mysql-adapter-lane-not-built) item 10), and
`systemctl try-reload-or-restart php8.3-fpm` (never `optimize:clear`; the
controller runs as root, so no `sudo`), and verify FPM and both worker
owners resolve the same driver, database and server UUID.
Open: nothing shows FPM's effective config while the hold is in force (see
the [MySQL adapter lane](#mysql-adapter-lane-not-built) item 11).
`php artisan db:writer-policy-check` must print `"problems":[]` and exit
0. It takes the writer token, so with the app account it cannot pass while
the gate is closed. Run it before Approval 2 under a separately granted
check account (`repro_token_check`), not after `seal_mysql`, where a failure ends in
`recovery_required` (lane item 7). Add it to every deploy once MySQL is live. Every
process must run the same release: a process without the policy would write
without the token. The engine check in the drill covers this for workers.
The writer-hold controller does not promote MySQL or reopen on it; the
controlled smoke period, Approval 2, `promote`, the MySQL reopen that resumes
the queues and starts the groups and the scheduler, and the undo of the
switch are all requirements of the
[MySQL adapter lane](#mysql-adapter-lane-not-built). Reconcile incoming
provider events before declaring done.

## Rollback has two different paths

**Before any MySQL business write:** restore the saved SQLite connection
settings and its configuration cache while writers remain stopped. The frozen
SQLite database is still authoritative. Retain source/media/cache safeguards and
verify the application before resuming writers. `cli.py abort` (or the
watchdog at 45 minutes) and `cli.py reopen` carry out this path: they
close admissions again, unseal SQLite and reopen it. At this candidate
nothing switches the database settings, so there is nothing else to
restore. Once the adapter lane exists, `prepare` makes the switch with the
MySQL write gate closed, and the rollback's `seal_sqlite` restores the
saved `.env` in place, runs `php artisan config:cache` as the deploy owner
with umask 0022 (as root under the watchdog's `UMask=0077` it would leave
a config cache that FPM cannot read) and
`systemctl try-reload-or-restart php8.3-fpm` before SQLite authority is
published, so the unattended watchdog undoes
it too. The live config probe then makes `reopen` refuse if the restore
did not happen ([MySQL adapter lane](#mysql-adapter-lane-not-built) items
4 and 10). Then follow the undo list in
[Freeze all writers](#freeze-all-writers).

**After MySQL has received writes:** stop admissions and all writers again.
`cli.py abort` refuses in `mysql_authoritative`; the commands and their time
budget are requirements of the
[MySQL adapter lane](#mysql-adapter-lane-not-built) item 12, not rehearsed.
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
connecting to MySQL. Open: the reviewed reverse-trigger helper that
installs the MySQL triggers before the export is not committed
(`reverse_triggers` is `null` in the nightly config), so its check against
the exact tool digests, snapshot and reviewed trigger names cannot run until
it is committed and pinned. Verify the durable profile on a disposable instance. Use
`SHOW GLOBAL VARIABLES` for the binary log format check because reading
`@@binlog_format` directly emits a deprecation warning on MySQL 8.4.13.
Keep the warning check enabled for every trigger statement.

After stopping writers and reconciling external effects, run the reviewed
reverse tool.

```bash
python3 -B scripts/mysql/export-sqlite.py \
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
