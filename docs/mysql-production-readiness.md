# MySQL production readiness

## November 25 lane integration

**Night status at this candidate.** It can hold, freeze, abort and reopen
production on SQLite. It cannot reach MySQL: no `prepare` or `promote`
action, `approval-template` accepts only `hold` or `freeze`, the adapter
refuses `prepare`, `evidence` and `seal_mysql`, every writer profile is
pinned to SQLite, and the watchdog rolls back at 45 minutes. Until the
[MySQL adapter lane](mysql-production-cutover.md#mysql-adapter-lane-not-built)
is built, any run is a SQLite dress rehearsal that ends in abort and reopen.

**Hold.** Five lanes, each based on `a6e799bb`, were merged into the release
candidate at `e9679aed` (draft PR 46). All lane work was local and offline.
The lanes pushed nothing, and nothing was deployed, installed or run on
production or Maverick. Production stays on SQLite. Each lane was tested on
its own branch; the lane notes record no test run of the merged head.

| Lane | Branch and merged head | Built | Tested | Review rounds | Still open |
| --- | --- | --- | --- | --- | --- |
| Writer policy (WP-1 to WP-9) | `claude/writer-policy-20261125` at `8b34c805` | One-writer token taken at BEGIN on the main `mysql` connection, on by default for MySQL, no effect on SQLite. Autocommit writes wrapped; implicit-commit, batch and raw transaction statements refused; poison guard; READ COMMITTED with 5 s lock waits; exempt writers limited to their own tables; worker cleanup of leftover transactions; `db:writer-policy-check` and `db:writer-token-holders`. Fixes for Telnyx first texts, B17 and B18. | Writer suite on MySQL 8.4.13: 95 tests, 1,344 assertions. WP-7 races with and without the token. Writer-token contracts in CI order. SQLite and MySQL profiles: only known fixture failures. Not run on GitHub. | Independent: 2 defects fixed. Codex 1: 4 findings fixed; 8 open review items: 5 fixed, 2 documented, 1 owner decision. Codex 2: 3 findings fixed; 3 rechecks hold or stay documented. | Owner: policy on by default, D1 to D6 and B1 to B18, B18's 30 minutes. CI run on GitHub (needs a push). Deploy check not in the deploy pipeline. Token, grants and monitor on the target. Week of transaction-length logging (Checkpoint 2). |
| Writer hold (H1 to H7) | `claude/writer-hold-20261125` at `273aa827` | Approved `hold` as the first journaled step: it starts the outage clock and closes admissions, then `freeze` drains. Controller and writer locks. Watchdog `tick` re-closes after a crash or reboot and rolls back at 45 minutes. Production CLI, host adapter, systemd watchdog, admission wrapper, nginx server-level hold, deploy fence, SQLite seal. | Linux suites at `adabedee` in a network-less container: runner 43, guard 20, process contract 6, wrapper 6, fences 12, adapter and CLI 30, nginx 1, host replay 3, refresh 10. systemd 252 unit checks; nginx 1.22.1 hold test. Full SQLite suite: known four only. Host fixture (H5) not run. | Independent: 5 defects fixed. Codex 1: 6 findings (4 fixed, 2 already fixed); 7 open review items: 3 fixed, 1 not run (H5), 1 left to a MySQL adapter lane, 1 owner decision, 1 needs W1 and W2. Codex 2: 5 findings fixed; 3 rechecks hold. | H5 fixture runs. W1 install and W2 drill. Host identity, MySQL and live-config probes. Activation smoke command. Supervisor template reconcile. MySQL promotion and its reboot case. Owner: O1, O2. |
| Producer replay (P2-03, P2-04, P2-06, P2-07, P2-08, P2-10, P2-11, P2-12) | `claude/producer-replay-20261125` at `9b37553b` | Recruiting replay identity: a divergent replay gets 409. Producer ingress hold in the app and at the nginx edge; careers and Resend inbound give 503 with CRM off. Website ledger tools, calendar reconcile, Stripe undelivered events, Aryeo record, pause and resume, CRM outbound hold with a release check and send dispositions. Flags off by default. | Lane PHP tests on both engines. MySQL profile for CRM and its neighbours: 311 tests, 1 skipped. Process, Python ledger and real-nginx hold tests. Full SQLite suite with a readable vendor copy: 9,626 tests, no failures. | Independent: 1 defect fixed, 1 doc fix. Codex 1: 4 findings (3 fixed, 1 already fixed); 6 open review items: 1 fixed, 1 dismissed with evidence, 2 documented, 1 left to owners, 1 by design, then reopened and fixed in round 2. Codex 2: 1 finding fixed, 1 documented (the Aryeo pause leaves sync writes on); rechecks hold. | Website changes P2-02, P2-05 and the P2-06 export not built. Aryeo 503 retry (docs: about 2 minutes) and Resend replay identity unconfirmed with the providers. Stripe restricted keys. nginx include line in both vhosts. Edge snippet width. The MySQL schema dump has since been regenerated with the new migration (schema lane). |
| Nightly dress (N1, N2, D1, T1) | `claude/nightly-dress-20261125` at `86e7d9e5` | Nightly runner: dry run by default, approval file to execute, lock, guards, journal with resume, 12-minute budget fail-fast, durability-neutral tuning only. Nightly summary needs capture receipts. Draft review policy and dress host and workload profiles. Readiness checker applies the plan's dress marks. | Nightly 72, readiness 68 and cutover 41 tests on Windows and Linux. Mutation checks on the runner. Runner flags probed on a local MySQL 8.4.13. Full SQLite suite: known four only. No PHP changed. | Independent: 4 defects fixed. Codex 1: 4 findings fixed; 5 open review items: 4 fixed, 1 owner decision. Codex 2: no findings; one README wording fix; rechecks hold. | Five approved nights on the host. Finance step, reverse trigger helper, applicant files root, retention, busiest endpoints and identity (N7) unset. Dress rehearsals D2 to D4. Owner decisions 1 to 8. |
| Off-site backups (B5 to B9) | `claude/offsite-backups-20261125` at `a5fcce5a` | Backup host installer (preview, apply, self-test, undo) and no-echo config entry. Alert bridge with heartbeat and forward timers. Twelve server-side detectors and one thresholds file. MySQL leg units and archive-aware binlog purge. Recovery kit and verifier. Seven-day observer and PITR drill runner. | Off-site tests: 202 on Linux, 116 on Windows. Real systemd 252 manager fixture: 23 of 23. Local MySQL 8.4.13 fixture 4 of 4 before the Codex rounds, focused native checks after each round. MySQL profile backup tests: 11 tests. Full SQLite suite: known four only. | Independent: 9 defects fixed. Codex 1: 10 findings (6 fixed, 4 already fixed); 7 open review items: 5 fixed in whole or part, 1 documented, 1 owner decision. Codex 2: 4 findings fixed; 4 rechecks hold, 1 note corrected. | Nothing installed on Maverick. Bucket, keys and passphrases. Derived upload credential helper not in the repo. MySQL leg apply (B1 e). Alert rounds (B14) and phone channel (B4). No 30-minute timer cadence run and no drill against a bucket. Owner decisions 1 to 6. |

Not every review item was fixed or dismissed. Items marked documented,
owner decision or left to another lane are still open and are listed in the
last column, the cutover runbook or
[Operational work still requiring approval](#operational-work-still-requiring-approval).
Points found while folding the lanes are marked "Open:" in the
[cutover runbook](mysql-production-cutover.md) and at the end of this file.
`database/schema/mysql-schema.sql` was regenerated on MySQL 8.4.13 with the
45 migrations it lacked, `2026_11_25_140000` included. `migrate:fresh` now
runs no migration after loading it. `tests/MySql/MySqlSchemaBaselineTest.php`
checks that the file's ledger lists every migration once, that each table is
defined once and that `recruit_application_receipts` has the two replay
columns, so it fails whenever a migration lands without a regenerated file.
At `4e4a262d` it does not prove the DDL is there: a file with a complete
ledger but a missing table still passes. The code-fix lane strengthens it.
Open: no script in the repository regenerates the file, and the method
(the `mysqldump` flags and the ledger assembly) is recorded only in the
schema lane's working notes, outside the repository. Owner: keep the test as
a guard or relax it.

Several lanes report the same four full-suite SQLite failures:
`ListingStudioPaidStorageTest` and `ListingStudioPrivateStorageTest`
(`test_fpm_upload_and_separate_worker_can_share_private_files`),
`WorkspacePhotoLogoPermissionsTest` and the `IguideLabImportTest` concurrent
retry. They fail the same way on `a6e799bb` in the same containers, because
they drop to a non-root user that cannot read the root-owned vendor copy.
With the copy made readable, the producer-replay review ran the full suite
with no failures. `DatabaseBackupTest` and `PointInTimeBackupTest` stop at
their configuration stage in some lane fixtures, as on `a6e799bb` there; the
nightly lane names a missing `mysqldump`. The off-site lane ran them green
from a copy with a test `.env`, as CI writes one.

### Writer policy

The one-writer rule now runs in the application on the main `mysql`
connection. It is on by default for MySQL and has no effect on SQLite. Every
MySQL target the application writes needs the token row in
`repro_mysql_control` first; without it every write stops. Design choices
and behaviour changes are in the
[known-differences register](mysql-known-differences.md) for owner sign-off.

All runs were local and offline (`--network none`) on MySQL 8.4.13 (image
`sha256:a81d0439…`) and PHP 8.3.33 (`repro-mysql-rehearsal-qa:20261122`),
with synthetic data only. The first runs used relaxed flushing for speed;
the review rounds used durable flushing on a socket-only fixture, as CI does.

- Multi-process writer suite (`phpunit.mysql-writers.xml`) on a fresh
  schema: 95 tests and 1,344 assertions after the second Codex round, about
  7 minutes. It covers every autocommit write form, the token at BEGIN, reads
  that never wait, refusals, a missing token row, the poison guard, lost
  sessions at BEGIN, inside a transaction and at COMMIT, retry limits, queue
  and cache exemptions, `LockedWrite` and rate-limiter retries, worker
  rollback, the long-hold watchdog and the deploy check.
- On the base code (`a6e799bb` plus only the new config and tests) the first
  suite failed 51 of 63 tests, as expected without the runtime policy. Of the
  12 that passed, the Google Routes race now fails without the token (a
  test-only trigger holds each insert 0.5 s: 8 reservations without the
  token, 1 with it). The twelve-worker queue run is relabelled: queue claims
  skip the token by design, so it is not a serialization check.
- WP-7 business races enter through the real HTTP kernel, invoice service or
  Artisan command, one process per racer, with providers faked. With the
  token each gives one effect. Without it, eight editing dispatches give
  7 × 500, an empty-day booking gives 2 or 3 bookings, period invoices are
  issued twice per client with a reused number, and a delayed writer's row is
  skipped for good by the Aryeo cursor. Paths with their own guard (a booked
  day, the full schedule guard, payout invoices, the Stripe payment event)
  pass either way.
- Real defects found and fixed test first: eight first texts from a new
  number made eight SMS threads (the Telnyx inbound handler now looks for the
  thread inside its transaction); a slow Stripe lookup inside the payment
  transaction failed other writers (B17: Stripe is now asked before the
  transaction opens); two overlapping weekly automation runs delivered twice
  (B18: the period is claimed in one transaction, and a period another run
  started less than 30 minutes ago is skipped). A stale "sent" status webhook
  also overwrote a delivery on the base code; with the token the message ends
  DELIVERED.
- Writer-token contracts in the CI order: primitive 23, Laravel fixture 22,
  Google Routes pilot 9, queue-worker pilot 9, scheduling-cache pilot 3, all
  passing.
- Full MySQL profile with the policy on (two shards as in CI, `185ed030`):
  7,543 tests, 12 failures. Two were iGUIDE Lab tour snapshot tests, fixed in
  `52da2771`. The other ten fail the same way on `a6e799bb` in that fixture.
  After the review rounds the touched Feature files passed on a fresh schema
  (118 tests, 1,688 assertions).
- SQLite suite (two workers): 9,674 tests, 3 skipped, and the known four.
- CI: shard 0 of `.github/workflows/mysql.yml` now runs every writer-token
  contract, then the writer suite against a fresh schema; the main profile's
  database gets the token row. It has not run on GitHub, because that needs
  a push. Measured locally, the writer suite takes about 7 minutes.

Each request that takes a database cache lock opens a second MySQL session
(`mysql_cache_locks`); size `max_connections` for that. With the token taken
at BEGIN, a long transaction holds every writer back from its start. The week
of transaction-length logging on production (Checkpoint 2) is still the gate;
the long-hold log gives the same signal on MySQL.

Full detail: [writer-policy lane notes](mysql-lanes/writer-policy.md).

### Writer hold

The writer-hold status is in the
[November 25 writer admission follow-up](#november-25-writer-admission-follow-up)
below. All runs were local and offline. Linux tests ran as root in a
disposable `repro-mysql-rehearsal-qa:20261122` container with
`--network none`.

- All Linux suites after Codex round 2 (`adabedee`), through
  `scripts/cutover/run-linux-tests.sh`: runner 43, guard 20, process contract
  6, admission wrapper 6, fences 12, adapter and CLI 30, nginx include 1, host
  replay 3, root-only refresh 10. All passed.
- The process-contract tests use real processes and flock. An
  already-admitted writer drains after the hold while new admissions are
  refused, and a runner killed with SIGKILL mid-drain is recovered by the
  watchdog. With 1, 4 and 8 processes looping refused admission checks on
  2 CPUs, the drain got the writer lock in 0 to 1 ms in every run.
- Under systemd 252 (Debian 12, offline), `systemd-tmpfiles` created both
  `/run` directories root-owned 0755, and `systemd-analyze verify` passed
  both watchdog units (`OnCalendar=minutely`, a 25-minute start timeout). A
  real timer firing after a skipped condition still needs W2.
- Under nginx 1.22.1 the production include answered 503 with
  `Retry-After: 30` on six routes on both vhosts, including the direct
  `.php` webhook files, while the open marker was absent. They passed through
  once it existed and closed again as soon as it was removed, with no reload.
- Full SQLite suite (`695dcbef`, `memory_limit=1536M`): 9,589 tests,
  3 skipped, and the known four. The lane changes no PHP application, test or
  config file.
- The new tests were written before the code and seen failing first. On the
  base commit `hold` does not exist.

The host fixture runs (H5) were not executed. `run-local.ps1` refused to
start by its own free-memory guard while other lanes ran. Run the
`prewrite-fastcgi` and `prewrite-fastcgi-recovery` scenarios from this
branch with fresh evidence folders when the machine has headroom.

Risks the lane records: Linux flock has no writer preference (the drain
polls every 10 ms). Installing the wrappers changes how live workers start
even in `planned`; a guard fault, or a reboot without `refresh`, holds every
writer and the site, so it needs on-call cover. Root ignores file modes, so
the SQLite seal refuses while any other process holds the database open for
writing. Behind a stale open marker, the two direct webhook files answer 200
before the PHP gate holds the write, so only the nginx server-level hold
protects them.

Full detail: [writer-hold lane notes](mysql-lanes/writer-hold.md).

### Producer replay

All runs were offline: Docker `--network none`, the pinned image (PHP 8.3.33)
and, for MySQL, a disposable 8.4.13 server with its socket on a tmpfs volume.
No provider, website or production system was contacted. Each new behaviour
was seen failing first for the expected reason, then passing.

- Lane PHP tests on SQLite (eight files): 40 tests, 286 assertions, at
  `18b43feb`. After round 2, the ledger, Aryeo freeze and Aryeo integration
  files gave 74 tests on SQLite and 74 on MySQL (1 skipped, SQLite copy only).
- MySQL 8.4.13 profile after `migrate:fresh`: `tests/Feature/Crm` plus the
  Aryeo, Stripe, Listing Studio, website email and external booking files,
  311 tests, 2,183 assertions, 1 skipped.
- MySQL process tests: two overlapping recruiting replays give one applicant
  and one file. The website intake concurrency test passes.
- Python ledger and replay tests and the producer hold with real nginx in the
  Linux image: 10 tests OK. The hold test fails as expected when the snippet
  returns 404 or drops `Retry-After`.
- Full SQLite suite with the vendor copy made readable: 9,626 tests, 0
  failures, 3 skipped.
- A Stripe payment event sent 5 times gives one payment on both endpoints.
  The same Aryeo event gives one webhook row and one reconcile job.

Not verified with the real providers: Aryeo's retry window for 503, and
whether a Resend dashboard replay keeps the `svix-id` and re-signs with a
fresh timestamp. The provider docs, read since, give Aryeo about 2 minutes
of retries and Resend about 27.5 hours (both derived from documented
intervals); the runbook's
[provider table](mysql-production-cutover.md#providers-during-a-60-minute-hold)
lists every provider. The website
changes P2-02, P2-05 and the P2-06 export are specified but not built. Once
deployed, a divergent replay of a website application reference gets 409
instead of a silent 201, and with `CRM_ENABLED=false` careers and Resend
`email.received` get 503. A replay of a receipt written before this change
gets 409 for staff review.

Full detail: [producer-replay lane notes](mysql-lanes/producer-replay.md).

### Nightly runner and dress-rehearsal gates

A committed runner now produces every input for one qualifying night: the
dated private capture, import with both forward verifications, reverse
SQLite parity, finance parity, applicant files and the CRM review, bound to
the snapshot, candidate release and reviewed scope
([runner](../scripts/cutover/nightly/README.md)). It has only run against
fakes. The streak count is still zero. Each night needs an approved run on
the host, then review of its outputs.

The nightly summary now refuses to run without the five dated capture
receipts. Every manifest entry pins its capture.

The readiness checker now tests the plan's pass marks in both dress
rehearsals, in addition to the earlier engineering thresholds. Speed: none
of the 50 busiest requests makes a layout lookup, at least 45 are within
20 ms of SQLite at p95 and none is more than 100 ms slower. Stress and crash:
ten minutes at 20 clients while workers shrink a queue backlog, with no
server error, unhandled lock error, missing confirmed write or duplicate,
then three mid-write MySQL kills each recovered within 60 seconds. Data:
import and both verifications within 12 minutes, clean scan, zero errors,
warnings, notes and unexplained differences. Way back: the exported SQLite
file reproduces every value and the weekly money runs match on it. The
critical-endpoint 10% p95 ratio and the write-improvement check remain.

Draft host and workload profiles are committed for review
([profile](../scripts/cutover/nightly/README.md#dress-rehearsal-profile)).
The workload uses the observed rates (p95 minute 125, peak 237 requests),
proposes a surge of twice the peak, and encodes the plan's stress and crash
test. The 50 busiest endpoints must be ranked from the request traces on a
private copy before the policy can be completed.

Import plus both forward verifications, with durable full-row binary logs,
measured 754.160 s (failed), 681.935 s, 593.230 s, 597.791 s, 700.066 s and
596.7 s. Headroom on the latest run is 123 s (17%), smaller than the spread
between runs. The runner stops before the import when the projection is over
budget, kills a step that reaches the budget, and records each stage's time.
It runs finance only after the disposable MySQL has stopped.

Evidence, all offline: nightly tests 72 OK on Windows (9 Linux-only skipped)
and in the pinned image as uid 1000; readiness tests 68 on both; the cutover
tests 41 on Windows. On Linux as uid 1000 the cutover tests have one
`test_final_backup_check` "unsafe path owner" error, which also occurs on
`a6e799bb`. `policy.py check-drafts` reports `drafts-consistent`. On a local
MySQL 8.4.13 server (socket only, no network) the runner's flags and
identity probe returned exactly the tuple it requires, and the seal read
back `1 1`. Removing each guard the lane lists (among them
`--sync-binlog=1`, the import timeout, the MySQL stop before finance, the
deploy lock check, the resume recheck and the post-import seal) made a test
fail. Full SQLite suite: 9,589 tests and the known four.

Full detail: [nightly-dress lane notes](mysql-lanes/nightly-dress.md).

### Off-site backups and alerts

Nothing was installed on production. Every test used fixtures, loopback
services or disposable containers with no network. The operator steps are in
the runbook's
[off-site backups and alerts](mysql-production-cutover.md#off-site-backups-and-alerts).
The repository now has an installer, an alert bridge, heartbeat and forward
timers, a MySQL heartbeat unit and a seven-day observer. None is installed:
the November 24 inventories that found no installed backup units still
describe production, and the recovery heartbeat still has no production
schema or timer.

| Item | What exists now | Status |
| --- | --- | --- |
| B5 installer | `install_backup_host.py`: jobs `base-backup`, `alerts`, `recovery-kit` (preview, apply, self-test, undo) and `mysql-recovery` (preview only). The no-echo config entry is `enter_r2_config.py`. | Done and tested under a real systemd 252 manager. Not run on Maverick. |
| B6 bridge and heartbeat | `alert_bridge.py`, the backup result units, and the heartbeat and forward timers. Backup and watchdog results start the bridge units. | Done. Phone delivery still needs B4 and B14. |
| B7 detectors | `alert_probe.py` and `alert-conditions.json` (one file for every threshold). Twelve server-side detectors; `server_silent`, `backup_stale` and `site_unreachable` come from the heartbeat, the watchdog and the provider's website check. | Code done. Rounds still need the owner (B14). |
| B8 MySQL units, purge, kit | One-minute heartbeat writer, two-minute rotation with archive dispatch and catch-up, hourly archive-aware purge, and the recovery kit with its verifier. | Code and units done; the purge ran on MySQL 8.4.13 in local containers. The MySQL leg install is preview only (needs B1 e). No 30-minute timer-driven cadence run yet. |
| B9 observer and drill runner | `observe_backup_health.py` (seven-day observer and export) and `run_pitr_drill.py` (writes the `replay.json` set). | Done against synthetic inputs; the drill's load, replay and checks ran on MySQL 8.4.13 in local containers. No run against a bucket yet. |

Risks fixed and limits found:

- **Separate keys.** The config tool has upload and restore roles and refuses
  to write the restore key into the server's backup state.
- **Sandbox reads.** Under a real systemd 252 manager, with another user
  holding WAL frames open, the producer as `repro-backup` under
  `ProtectSystem=strict` copied all 45 committed rows.
- **Limit: no open connection.** If every app connection has closed and
  SQLite removed `-wal` and `-shm`, the read-only sandbox cannot create them
  and the backup fails with exit 1 (no partial copy; `backup_failed`
  alerts). Long-lived queue workers normally keep the WAL open. The probe no
  longer pages in that case: it falls back to a guarded `immutable=1` read and
  keeps the answer only if the file did not change during it.
- **Limit: `.env` replaced by rename.** That drops the ACL. The next backup
  and the kit fail loudly and the probe reports `wrong_database_engine`
  within minutes. Re-run the base-backup apply to repair.
- **Archive-aware purge** replaces unbounded binlog growth.
- **Monthly reboot.** The backup moved to 03:30 UTC, clear of the 02:00
  reboot on the 28th.
- **Probe plan placement.** The real manager showed the probe could not read
  a plan under `/etc/repro-alert`; it lives in `/etc/repro-backup`.
- **Still limits after review:** the observer loses a minute on a failed run
  that nothing retries; `wrong_database_engine` is a config check only; the
  MySQL monitor account needs `PROCESS`.

Evidence (Linux runs in the pinned QA image with `--network none`):

- Off-site tests at the round-2 head: 202 OK on Linux as uid 1000 (1
  skipped), 23 OK as root for the installer (1 skipped), 116 OK on Windows
  (22 Linux- or root-only skipped).
- `test_rotation_attempt` 6, `systemd/test_units.py` 8 and
  `test_production_units` 12 tests OK. `run-fixture.ps1` verified 31 units
  under systemd 252; it was not rerun in the Codex rounds, which changed no
  unit file.
- Real-manager fixture (systemd 252 in a disposable container with no
  network): 23 of 23 checks. Preview changes nothing; base and alerts apply
  with all self-tests passing; the producer in the unit sandbox copies
  committed WAL rows; a watchdog failure reaches a loopback HTTPS provider as
  a fail ping; no ping URL appears in the journal; only the bridge units have
  network; undo restores the ACL bytes and leaves no files or accounts.
- Local MySQL 8.4.13 fixture, 4 of 4 checks, before the Codex rounds: the
  drill runner's load, checksum-verified replay across two binlog files,
  recovery of rows 1 to 3 and exclusion of a planted later update and
  insert; a purge account without `BINLOG_ADMIN` is refused; the purge stops
  at the archive checkpoint. Retrieval through rclone, the chain verifier and
  `heartbeat.py verify` were not part of it.
- Focused MySQL 8.4.13 checks after the Codex rounds: the drill loads a dump
  made with the producer's own arguments (ERROR 1046 before the fix) and
  refuses a stalled load at its 5 s limit; a closed binlog's rotation time
  equals the FLUSH second; `backup_lag` reads ok 13 s after a FLUSH and fails
  at 301 s; `queue_stale` gives the expected results through the real client.
- MySQL 8.4.13 profile: the three backup test files and
  `RecoveryKitVerifierTest`, 11 tests, 111 assertions. Full SQLite suite at
  `f6fac8c3`: 9,593 tests and the known four.

Full detail: [off-site backups lane notes](mysql-lanes/offsite-backups.md).

## November 25 writer admission follow-up

**Hold.** A fresh read-only inventory identified two enabled Dashboard
ingress paths. The same-origin `/api` location pins the backend front
controller. The legacy API host uses a front-controller rewrite and its
PHP location. Both resolve to the PHP 8.3 `www` pool and the same active
Unix socket. The inventory records the on-disk enabled configurations and
socket identities. It does not prove a production fence or a config reload.
The redacted vhost and upstream receipt SHA-256 digests are
`cba52d12fb981a3f89b87fe7e0558570e934cabf9ed30c7f0d80e0fb924c0b2e` and
`253b4dba0f5f7930389423a05f0f4090a34990cca39a545a323cb11cf2d34c2e`.

The private nginx/PHP-FPM fixture ran quote-view GET and Resend webhook
POST hold and replay probes through both hostnames. nginx returned 15
retryable 503 responses before FastCGI, and the PHP journal gate denied
20 additional matrix requests behind a stale, open ingress marker.
Seven PHP-FPM cohorts drained across restarts. The fixture reported one
SQLite effect per replay identity and zero MySQL business writes. The
redacted isolated report SHA-256 is
`cd35006037b000d5d6ff7af0230b37952875c9ea862aaffc318b4da8c0da9545`.

The root-side authority refresh helper now verifies the current boot and
committed journal under the exclusive operator lease. It revokes old proof,
requires the trusted adapter to observe a sealed single-engine route, and
publishes the new evidence atomically. All 27 isolated Linux fence tests
passed. The live adapter probes and publication for a pending service
transition remain staging integration work.

**Writer hold: implemented and tested locally, not installed.** The
approved hold is now the first journaled step and starts the outage clock.
A second lock lets the runner journal it while earlier writers still hold
their shared lease; the freeze then waits for them. The watchdog closes
admissions again after a crash or reboot, before and after the handoff from
`holding` to `frozen`, and rolls back to SQLite 45 minutes after the hold.
An admission closed outside the journal is refused, not silently ignored.
Local evidence: runner, guard and refresh tests, Linux process tests with a
real already-admitted writer and a runner killed with SIGKILL, and the
production nginx include under nginx 1.22.1 (results in
[November 25 lane integration](#writer-hold)). The hold-first run of the
private nginx/PHP-FPM fixture (H5) has not been executed. Production installation (W1)
and a production or staging drill with real services and a reboot (W2)
remain, each with its own approval. Keep production on SQLite.

Nightly evidence assembly can verify all five pinned SQLite producer
receipts alongside the report and CRM review for each UTC day. The fixture
rejected reusing a receipt on another date even when the snapshot bytes
were identical. Real nightly runs and backup observation remain open.

## November 25 post-cleanup rehearsal

**Hold.** The fresh online copy came from the live SQLite release
`8bb478e66b3c1eb8a78969f513e38901a2d860e8` after the approved failed-job
and AI payload cleanup. The checksum-bound copy completed at 05:01:30
UTC. It contains 328 tables, 1,916,750 application rows, nine CRM
migrations, 37 CRM-related tables, 4,828 CRM rows and 1,206 linked
client contacts. Its SHA-256 is
`9db47294c239c6c784803f41ec3026e7ce707c38cdce59ea3559909130211d98`.
The fresh scope audit, schema and reverse template checks matched the
reviewed layout. The eight historical exception sets matched again.
The application candidate incorporates that source release at
`f026eadfe45893afa07ac8aa092255ca02cae979`.
The Dashboard frontend release was `cff56754d65efe6334b3e8875b2738481fe9a441`.
The effective flags showed CRM, Resend sending, reply tracking and Google
Calendar sync enabled, CRM SMS disabled, and the dedicated receiving
credential configured.
[Exact application CI](https://github.com/superdevvs/Repro_Backend/actions/runs/45550749626)
· [Exact frontend CI](https://github.com/superdevvs/Repro-Frontend/actions/runs/45536570185)

The isolated native MySQL 8.4.13 import used durable full-row binlogs.
The converter and independent PHP reader matched all 1,916,750 rows and
31,253,958 fields with zero differences or warnings in 596.700 seconds.
The sealed reverse export verified all 36 trigger definitions and passed
independent field and CRM relationship comparisons in 346.550 seconds.
The separate private finance clone started during the reverse run. Preserve
that overlap in the timing record. The tables contained one applicant and no
applicant file rows, so actual production attachment recovery remains open.
The temporary native MySQL instance was removed. The redacted forward and
reverse receipt SHA-256 is
`337974acb04914f3a3873b3da4a2094703a7053c6951200bcc004ae00c05db25`.

The private finance rehearsal on application commit `f026eadf` matched
eight report weeks, past-due amounts, the photographer reconciliation,
six sampled client invoices, weekly payout invoices, editor payouts and
allocated work. The CRM client-import dry run matched across the two
engines. The independent reader checked each named comparison and confirmed
zero external effects and cleanup of the transient unit and scratch data.
The first attempt stopped at an incorrectly formatted date before reading
reports. The rerun used the pinned UTC capture time. The passing receipt
SHA-256 is `2025b479fef9228ef307eb650cbc68251912b22baeecb7a76117cadbb46e086b`.

An isolated PHP probe read all eight copied Google Calendar access and
refresh token pairs using the installed encryption configuration with
no provider access. The copy had zero pending encrypted website intake
payloads. A synthetic intake document passed encryption and decryption
in memory. No key, token, ciphertext or plaintext left Maverick. Field
verification compares the stored ciphertext as it does any other field.
The redacted encryption receipt SHA-256 is
`3d537e1761d2d0c6b29670fe775a28cb22454b0f31ae7d6d46cfb2bb06b55c25`.

The isolated writer fixture rejected writes after a PHP server restart
and fresh queue invocations under a held journal, missing proof, invalid
proof and stale boot proof. An exclusive operator lease also denied both
entry points. Fresh proof reopened SQLite and the queued job ran once.
The receipt SHA-256 is
`3a02995c2af425fb956b85f00d523c3af22d6a0c231bf8ee4f6aeb198e9f7c1e`.
The fixture uses PHP's CLI server and one-shot Laravel queues. Production
nginx, PHP-FPM, the trusted authority publisher, systemd and long-lived
workers still need their own restart and drain evidence. The subsequent
network-disabled fixture used nginx and PHP-FPM over private Unix sockets.
Three ingress probes returned 503 without reaching FPM. Seven FPM cohorts
drained cleanly across restarts, and a stale open nginx marker did not bypass
the frozen, missing, invalid or stale authority evidence. Verified SQLite
authority reopened admission and processed the queued job once. The private
FastCGI report on integrated tooling commit `7f7d9d77` has SHA-256
`4fa9c06581eebb8df4aaa31557d6c1c271360de8c53f43e26be37f20defec045`.
On the same commit, a second fixture preserved one acknowledged MySQL
write and excluded an uncommitted write in a new verified SQLite file.
It reopened the fixture application and ran one recovered job. That report
SHA-256 is `552cabd501f0716f1325557f90a2ebdf0c9ed190ca65b9b12f6df0ea26f4d93c`.
Both fixtures removed their containers and volumes. Use the deployed vhosts,
root publisher and actual systemd worker and scheduler controls for the
host acceptance run.

The producer replay fixture used the committed RE Pro Photos website source
at `4011d53a8d82af72ef4247af6034a0c0751cbe60` and a disposable PostgreSQL
database. Recruiting retried a fenced 503 across new processes, reclaimed
a lease after process loss, refused a stale update and retained exactly one
synthetic receiver effect for an unchanged application. Editing the
application before retry changed the payload under the same reference.
Turning forwarding off prevented new applications from entering the outbox.
Booking retained the original quote, reference and body across retry and
reconciled a lost response. A fenced catalog lookup left an unquoted booking
pending with no Dashboard submission. Contact saved the inquiry and its
reference with an uncertain forwarding disposition. Contact replay still
requires operator reconciliation. The redacted receipt SHA-256 is
`e801c730931095ea8d0f3d477efc056658b5d9f7e9f80fe8fd4b50eab367e940`.
Subsequent read-only checks confirmed the live Supabase outbox, claim
function and enqueue trigger, with forwarding enabled and one aggregate
recorded delivery on November 24. The deployed code SHA, recurring scheduler
identity and schedule remain unverified. The runtime receipt SHA-256 is
`5d6143440c46be522f423a1efca68bd1d4a684e281ec1b5f6b23861046ae2993`.
The offline ledger comparison accepted matching booking and recruiting
effects and refused changed recruiting bytes, an uncertain contact and an
omitted contact in the exact-source synthetic runs. Its receipt SHA-256 is
`3424543b79632f06d33476544e9713ea175d9839444a9fd9f7cb3eed23d91b17`.
The actual scheduler hold, operator replay coverage and applicant attachment
replay still need acceptance.

The fresh copy supplied 77,937 observed API traces within the preceding
24-hour window, including 296 for staff CRM. The p95 active minute had
125 requests and the peak had 237. Use these samples with the reviewed
route and role inventory when setting the dress rehearsal workload.
This observed profile does not establish complete coverage of public webhooks
or write-on-read routes.

The dated capture passed independent receipt review. Its redacted proof
SHA-256 is `20355a58e988e6c650ecf3b75010d501cce69c39fafe7751ff0f30c2e7bdecb7`.
Capture evidence by itself does not qualify a night. Five complete nightly
parity runs, seven days of backup evidence on the designated destination,
independent restore drills and the missed-check-in phone receipt remain open.

## November 25 Sales CRM rehearsal

**Hold.** The deployed backend `3ceb1f3e` and frontend `2240555e` were
confirmed from their release metadata. The live configuration still selects
SQLite. Sales CRM, Resend sending, inbound reply tracking and Google Calendar
sync were enabled. CRM SMS was disabled. The dedicated Resend receiving
credential was configured.

The integrated candidate `ba425206` and the bounded converter candidate
`25cf2959` passed all six hosted CI jobs. The application candidate
`21338da0` adds search parity across the remaining CRM paths and the
configurable reply-forwarding hold.
[Integrated release CI](https://github.com/superdevvs/Repro_Backend/actions/runs/45475073518)
· [Converter CI](https://github.com/superdevvs/Repro_Backend/actions/runs/45478884830)
· [Application candidate CI](https://github.com/superdevvs/Repro_Backend/actions/runs/45482758209)
· [CRM rehearsal draft](https://github.com/superdevvs/Repro_Backend/pull/46)

The fresh private online SQLite copy is 4,460,900,352 bytes and passed the
backup integrity check. It contains nine CRM migrations, 37 CRM-related
tables, 4,717 CRM rows and 1,206 linked client contacts. The SHA-256 is
`83542969cf0017be8b4aac5174101a16c11014c532161bb28e0cfc2b76725034`.
The compared release catalog had 321 tables. All 62 observed schema
differences were outside CRM. The drift report SHA-256 is
`9f574ea91acf03880954252a8c36cea0e647c6cb1537830dcdc582c5ec89a5f3`.
The eight existing foreign-key exception sets matched their reviewed row
identities. The verified preservation
manifest for all 328 tables has SHA-256
`a3807732af33e799198c3e681b2610ff470605e023d2029fc5b8ec81bf5c99d2`.

The first isolated native 8.4.13 run used durable full-row binlogs.
The converter and independent PHP reader verified 1,893,048 rows and
30,506,349 fields with zero differences or conversion warnings. The
combined forward run took 754.160 seconds against a 720-second nightly
limit. It did not qualify. The reverse trigger helper refused the logged
fixture and the temporary target was removed.

The second trial grouped small insert batches in bounded transactions while
retaining the full-row binlogs and durable commit settings. Both forward
checks again matched all 1,893,048 rows and 30,506,349 fields. Their combined
elapsed time was 681.935 seconds. The reverse helper then refused a stale
pin for the updated converter. The third trial verified the reverse
package before starting the target. Forward parity took 593.230 seconds.
The reverse helper detected a deprecation warning from reading the binary
log format variable. It now reads this setting through `SHOW GLOBAL
VARIABLES`. The metadata probe and all 11 reviewed trigger statements
passed without warnings on a disposable native MySQL 8.4.13 instance.

The fourth trial verified the full copy in 597.791 seconds with durable
full-row binlogs and zero import warnings. It checked all 36 trigger
definitions on the isolated target and exported a new SQLite file in
the sealed reverse check. The reverse run, including the independent
PHP field comparison and CRM relationship audit, took 180.892 seconds.
All 328 tables, 1,893,048 rows and 30,506,349 fields matched. The source
snapshot remained available and the temporary MySQL instance was removed.
The redacted combined receipt SHA-256 is
`45dc3f7e0103d87f2670559acc1d24f3cffda1b5a01908875230c428c6aeffc2`.
This verifies both data directions on the populated copy. The reverse
copy contained the original business rows. Use the timed dress rehearsal
to prove an acknowledged write and its external effects during failback.

The isolated CRM and recent-migration compatibility run passed 109
tests on MySQL. Representative tests covered receiving credentials,
fresh and duplicate authenticated Resend replay, partial recruiting
attachment recovery and an ambiguous SMS send. The separate concurrent
inquiry run observed two real MySQL sessions contending for the same
receipt and exactly one completed inquiry. The actual snapshot had
no applicant attachment rows. The private file verifier checked
synthetic file and recovery cases in Linux and the CRM tests checked
authorized and forbidden document retrieval.

CRM contact, Robbie and recruiting search preserve each path's SQLite
matching behavior on MySQL. The tests cover literal characters and
wildcard patterns, accented case, embedded NULs and role visibility.
Native load and query latency remain part of the dress rehearsals.

The signed Resend replay test recorded a reply while the outbound forward
was held, then verified one forward with the original idempotency key
after release. The rehearsal used provider fakes. A read-only census
observed four web-user queue workers and eight deployment-user workers,
including Studio and the three voice queues. The deployment workers'
working directories were unreadable to the census account. The actual
restart-safe writer fence and live provider delivery remained open.

The website's live producer retry remains unverified. The backend
accepted a repeated inquiry reference once and recovered a partial
recruiting attachment replay against provider substitutes. A complete
rehearsal must show that the website producers retain each original
reference, payload and attachment through a fenced HTTP response and a
restart. Reconcile provider events and holds before enabling outbound
CRM jobs. Do not use the same populated snapshot on successive trials
as evidence for different scheduled nights.

## Cutover decision on November 24

**Hold.** Production continues on SQLite. Scheduled evidence, the
full application writer policy and the timed dress rehearsals remain open.

| Gate | Latest rehearsal evidence | Remaining evidence |
| --- | --- | --- |
| Data and financial parity | The private copy verified 291 tables, 1,857,367 rows and 29,857,089 fields with zero differences. Import and independent verification took 11 minutes 40 seconds. Eight report weeks and sampled invoice and payout generation matched. | Five consecutive qualifying nights, with the last two on the frozen release. |
| Application behavior and rollback | The [scheduling summary](../scripts/mysql/workload/HTTP-COMPARISON.md#local-schedule-summary-fixture) matched on both engines, including role denial. Scoped worker routing, both synthetic rollback paths and the administrator schedule inventory passed. | Application-wide writer coverage, restart-safe admission across actual entry points, and two comparable end-to-end rehearsals within the planned outage. |
| Storage and recovery | Native data and media devices were confirmed separate. The private rehearsal bucket supported an independent encrypted recovery and protected a synthetic object. | The designated backup configuration, seven days of independent backup evidence, and point-in-time recovery drills on that configuration. |
| Alert delivery | The backup failure reached the phone. The repeated missed check-in reached the inbox. | Confirm missed check-in delivery on the phone and complete both reviewed alert rounds. |

## November 22 data rehearsal

The candidate is based on deployed backend
`9ef19f686d14b07de0995ee0b2cb157126d044a7`. The live application uses
SQLite. The rehearsal took an online backup with the source open read-only,
then imported the copy into a temporary, socket-only native MySQL 8.4.13
process under the deployment account. No application, scheduler or queue
worker used this database.

The corrected import verified all **291 tables and 1,841,678 original rows**
in **9 minutes 16 seconds**, including planning and initialization. It
installed all **477 foreign keys**, including eight relationships that retain
195 historical rows with 223 missing references, and installed 25 direct
triggers. A separate PHP reader used a SELECT-only account and a read-only
MySQL instance to compare all **29,909,713 fields** in 80 seconds with zero
mismatches. The first import attempt caught an oversized iGUIDE survey index.
The conversion now uses the original migration's declared 64- and
32-character widths for its two identity fields and retains the full unique key.

A separate same-host instance restored the compressed backup and verified all
291 tables, 29,909,713 fields, 477 foreign keys, 25 triggers and 166 next record
IDs. Exact column metadata matched. The DDL comparison accepted only a
redundant character-set clause where the collation was identical. Both
temporary instances stopped after verification. Off-site recovery remains
its own acceptance gate.

The fresh release build had 284 tables. The copy has seven additional
historical tables and other differences in constraints, columns, indexes and
triggers. The data rehearsal retained all seven tables. The protected drift
report must be reviewed against the final release and frozen snapshot before
signing a cutover layout.

On the retained rehearsal copy, a fresh metadata-only comparison verified the
copy's recorded SHA-256 and found 56 schema differences from the source release
and 62 from the candidate. Both fresh fixtures have 284 tables compared with
291 in the copy. Rebuilding the fixtures with locked dependencies reproduced
both schema fingerprints. The comparison queried schema metadata only.

The archived MySQL import catalog and the original empty SQLite template agree
on all **477 foreign-key relationships** across the 291 tables. The offline
comparison checks child and parent column order and both referential actions.
On the recovered copy, MySQL rejected an attempted invalid update for each of
the eight relationships with approved historical exceptions. Each transaction
was rolled back and its sampled historical row retained its original value.
Recheck the final snapshot and exception set during the dress rehearsals.
[Layout verifier](../scripts/mysql/verify-foreign-key-layout.py)

The draft disposition covers all 62 records. It proposes keeping 19,
using existing compatibility handling for 11, and collecting proof for 32.
The base SQLite recovery template was built from the pinned copy and contains
291 tables, 668 indexes and 16 original SQLite triggers. It contains no copied
application rows. A separate verified overlay adds the six optional messaging
columns and preserves the existing SQLite foreign-key ordinals. Reverse export
checks the reviewed MySQL-only iGUIDE trigger and recreates only the SQLite
trigger definitions in the restored database.

The resumed full-copy recovery restored the isolated MySQL backup and exported
all 291 tables and 1,841,678 rows to a new SQLite file in 125.3 seconds.
The export checked exact historical exceptions and enabled SQLite foreign-key
enforcement. An independent SELECT-only PHP reader compared 29,914,612 fields
with zero mismatches in a separate 75.84-second verification session. The
temporary MySQL process shut down cleanly. The restore and verification ran in
separate sessions around two compatibility corrections. Rehearse the complete
application failback and provider reconciliation under the outage clock before
signing off on recovery.

The November 23 data drill added two synthetic notification records to the
disposable MySQL copy and committed an update to one of them. The temporary
server was killed while a different write was uncommitted. It recovered the
committed records in a verified read-only restart. A new invalid foreign-key
write was rejected, and the explicitly rolled-back and in-flight writes were
absent. The first export stopped before copying rows because the packet omitted
the cascade trigger manifest. The corrected export resumed read-only, took
110.5 seconds, and produced 1,841,680 rows across 291 tables with exactly the
two intended additions. Independent PHP verification compared 29,914,626 fields
with zero mismatches. All other tables and the prior notification rows matched
the baseline. These were data-recovery steps over two sessions. The timed
application failback and provider checks remain open.

A later full-size private drill completed the failure recovery on one clock,
using a fresh isolated MySQL restore of the same retained copy. From the
controlled MySQL crash it took 14.091 seconds to reach verified read-only
mode, 128.898 seconds to export the new SQLite file, 207.601 seconds to finish
independent field verification, and 208.469 seconds to stop MySQL cleanly.
The recovery kept both acknowledged synthetic rows and excluded invalid,
rolled-back and unacknowledged writes. It matched all 29,914,626 fields
across 1,841,680 rows and 291 tables. The other 290 tables matched the
baseline. The rehearsal removed its new sensitive scratch data. The receipt
for release `fbfe24233423ebdb6fb1b505fce64d685e793e0e` has SHA-256
`d363c0d93a65e808520845f111f374545e3772dec853cb04ecfa9fc7445ab021`.
The clock ends with database verification and shutdown. Laravel reopening
and provider reconciliation still need their application rehearsal.

The separate managed MySQL 8.4.13 service on Maverick passed authenticated
checks on the empty instance before and after a controlled restart. Both
`www-data` and `maverick` initially received permission denied at the socket.
Granting the existing `www-data` group traverse-only access to the runtime
directory let both accounts reach the expected daemon. The managed v3 test
verified this access on start and restart without a manual ACL change. An
unrelated account was refused. The service and rotation timer were left
stopped and disabled. The live app still uses SQLite and the existing MySQL
service kept its process identity.

The isolated Laravel smoke now runs with the candidate's locked dependencies
and a disposable socket-only MySQL 8.4.13 instance. It verified that valid
credentials work and invalid credentials are refused, notification reads
persist with the correct user and watermark, rolled-back writes remain
invisible, and one native queue job produces one acknowledged effect. All 16
launcher tests passed on Linux. The disposable database was empty again after
the test. The smoke used only synthetic records.

Maverick refused unprivileged user namespace creation. The supervised smoke
verified a distinct private network namespace, a non-root UID, empty capability
sets, and no outbound route. The first supervisor stopped during filesystem
sandbox setup. The next refused its root-only service manager socket because
the check expected mode 0600 and Maverick uses 0700. The corrected host test
passed the isolation probe and all three database checks using the pinned
rehearsal release `0f644b36` and a synthetic schema. It verified that the production
paths and the service manager socket
were inaccessible. Each transient workload unit stopped and was collected.

The managed log-rotation service ran online and retained seven slow-log
archives through nine forced rotations. The offline test refused before
starting logrotate and left the logs and rotation state exact. Systemd kept the
one-shot unit marked failed after this intentional refusal. The coordinator's
final status check counted that marker as a cleanup failure. An independent
read-only check confirmed zero processes for the one-shot unit, no remaining
workload units, and both the isolated database service and timer stopped and
disabled. The candidate's loaded-copy workload and timed cutover remain to be
rehearsed.

The encrypted backup fixture published a SQLite backup and checkpoint to a
private S3-compatible HTTPS endpoint with rclone 1.76.0. A separate recovery
container had access only to the published objects and independently supplied
credentials. It verified both artifacts, SQLite integrity and the synthetic
records. The real bucket, retention rules and independent off-site recovery
still require acceptance.

The local MySQL fixture also published a consistent MySQL 8.4.13 dump, closed
binlog and checkpoint over encrypted HTTPS storage. A fresh recovery instance
used the recovered objects, restored the baseline, then replayed to the
reviewed transaction boundary. It recovered the first two synthetic rows and
excluded the later third row. Both database containers stopped and the test
network and volumes were removed.

A full-size recovery drill on Maverick used the isolated MySQL copy from the
upload rehearsal. Rclone encrypted the consistent dump and closed binary log,
then verified their decrypted sizes and hashes. The compressed dump was
766,321,760 bytes. A fresh MySQL 8.4.13 instance loaded the snapshot from
ciphertext, recovered the selected transaction and excluded the later one.
The comparison covered 293 tables, including two rehearsal tables, and matched
1,841,841 preexisting rows by table count and an ordered data-dump digest. It
also matched table definitions, indexes, foreign keys, triggers and next IDs.

Recovery took 191.7 seconds from fresh instance preparation through validation
and clean shutdown. The complete backup and recovery drill took 304.35 seconds.
Both private instances stopped. A separate check confirmed the live app still
used SQLite and the existing MySQL service had kept its original process
invocation. This rehearsal used encrypted storage on Maverick. Acceptance still
requires testing the designated off-host bucket, its retention controls and an
independent full-size recovery from that bucket.

The stock MySQL 8.4.13 PITR backup command also produced a 737,159,499-byte
compressed snapshot of the read-only copy. The backup took 44.04 seconds.
The MySQL staging command compared the dump's recovery position to its
producer receipt, verified the gzip stream and both artifact hashes, and
created the publisher manifest. The publisher's local checks passed 49.4
seconds after the start. The private database stopped and the temporary
plaintext batch was removed. No cloud bucket was configured for this run.

A separate synthetic recovery drill used three real MySQL 8.4.13 binlogs
closed by rotation. It refused a missing middle file before starting the
target, then replayed all three files on one connection through the selected
commit. The target recovered four committed rows and excluded the later fifth
row. A second drill using the stock backup producer and the repository checker
verified the same behavior. It checked actual event checksums, rotation
pointers, and the selected commit position before replay. Recovery, validation
and shutdown took 7.03 seconds in the second small fixture. Both drills used
fresh isolated MySQL instances and cleaned up their raw fixture data.

On November 23, an independent synthetic recovery passed against the actual
private Cloudflare R2 rehearsal bucket. Maverick published a stock MySQL
snapshot, three closed binary logs and their two metadata files. The publisher
verified all six artifacts after upload. A raw R2 listing found eight stored
objects, including the encrypted manifest and completion record, with encrypted
names. The Windows recovery environment received only the publication receipt
and used a separate bucket-scoped read-only key to download and verify all six
artifacts. A fresh network-isolated MySQL 8.4.13 instance restored the snapshot
and replayed three logs through the selected commit. It recovered rows 1–4 and
excluded the later row 5. Native replay and clean shutdown took 5.326 seconds
for this small fixture. The local recovery containers, private volume and
temporary Maverick credential files were removed.

The full-size follow-up published **753,141,689 bytes** of synthetic data
across seven artifacts to the private R2 rehearsal bucket. The encrypted
upload and publisher read-back took 140.94 seconds. A separate read-only
credential on Windows retrieved and verified the seven artifacts in 57.72
seconds. The decoder verified three closed logs and both the chosen and later
commit positions. A fresh network-isolated MySQL 8.4.13 process loaded the
snapshot, replayed through the chosen commit, and stopped cleanly in 51.18
seconds. All 79,168 generated payload rows and their indexes matched. The
four expected witness rows remained and the later fifth row was excluded.
The independent replay also regenerated each expected digest from the test
seed. Its report SHA-256 is
`24ebdbed04dec236290a54e44b92d817be8709a36fac99d9e2fe8874c62cbbbb`.

Only generated data went to R2. The two credential configurations stayed in
temporary memory-backed storage. The run removed the nine exact encrypted
test objects, both credential configurations, the temporary source files and
the independent recovery volumes. The rehearsal bucket's only lifecycle
rule aborts unfinished multipart uploads after seven days. It has no bucket
lock rules. Automatic backups and protected retention remain acceptance gates.

A separate off-site application rehearsal published **13,512,184 bytes**
from draft release `1683dfaa6e503a201f61158857b42c48d533b907`. The
application-scope kit contained 1,759 allowlisted Git source files, locked
production dependencies and a wrapped synthetic Laravel key. Historical seed
records, files with address-bearing examples or history, cached configuration
and the live application key were outside the archive. The synthetic wrapping
key stayed in separate local custody.

The encrypted upload and read-back took 21.33 seconds. The independent
read-only recovery took 8.23 seconds to fetch and verify the three artifacts.
A fresh offline container used only those recovered artifacts and the
separately held wrapping key. It booted Laravel 12.71.0, decrypted the
synthetic stored value and rejected the wrong wrapping key in 3.73 seconds.
The restore report SHA-256 is
`616eaf5fef544430d60f55c29cdf4917ab23a4327711a14220015cf83fe00b5b`.
All five encrypted test objects, temporary payloads, credential
configurations, the synthetic escrow and the recovery volumes were removed.
This verifies boot and key recovery for the allowlisted rehearsal package.
The full production recovery kit and its protected key custody still require
operational acceptance.

## November 24 backup and cutover integration

The scheduled base backup runs the stock producer, stages a closed snapshot,
and records a new restore point after encrypted publication and read-back.
The separate local health check reports stale or failed attempts. On a
private HTTPS storage fixture, both the SQLite and MySQL 8.4.13 workflows
published a baseline, reported an injected storage failure, preserved the
last verified receipt, and published a fresh UUID after storage recovered.
Independent consumers restored the expected synthetic records. The MySQL
consumer verified the snapshot's binlog coordinate and restored into a new
native MySQL instance after the source container stopped. All owned
containers, volumes and fixture networks were removed.

The backup and watchdog unit definitions passed Linux parser checks and
fixed-argument alert tests. A separate transient timer in Maverick's user
manager started its synthetic job. A failed watchdog fired the alert handler in
order. All test units and scratch were removed. Activating the real backup
schedule against the approved destination, delivering alerts to external
monitoring and proving live retention remain operational acceptance gates.
The retention verifier tested local evidence for exact prefix, duration,
provider refusals and unchanged content.

The independent email pilot used one down-only Healthchecks.io integration
in a dedicated synthetic project. It logged delivery after an explicit
backup failure and after a scheduled check missed its one-minute interval and
one-minute grace. The event log for the missed check recorded one successful
check-in and no explicit failure ping. Both checks were paused afterward.
The provider observation SHA-256 is
`af311c838b8e8ecec2787fa6f405b834e2f778bb64692d132a2917e54a872ddd`.
The iPhone receipt was pending when this evidence was recorded. The full
external alert rounds still need reviewed condition and receipt evidence.

The private R2 rehearsal bucket also enforced a real 24-hour lock on a new
synthetic prefix. A two-day expiry was configured for the same prefix. R2
refused an overwrite and a delete with a delete-capable credential with
`ObjectLockedByBucketPolicy`. The writer lacked delete permission and got
`AccessDenied`. A second read returned the original 80 bytes. The trial
removed the object, both rules and the disposable bucket credential. The
seven-day multipart-abort rule and older rehearsal prefix remained in place.
The receipt's SHA-256 is
`8b5f14d8106630077ba689807974022a9f13d6d9e63899ad389957e7a2b6d3f4`.
Rule settings were observed in the Cloudflare dashboard. The trial removed
the rule before expiry was due. The approved production backup destination
still needs its own retention and recovery evidence.

An administrator-supervised Maverick rehearsal used the stock scheduled
runner from `0dfa65dd`, a private dynamic account and loopback-only TLS S3.
Three real systemd timer activations published a SQLite backup with verified
read-back, reported a forced storage failure, and published a fresh backup
after recovery. The failure preserved the last verified receipt and invoked
the systemd `OnFailure` alert. A separate restore process used its own fixture
credential. With the original SQLite file hidden, it independently read and
verified the expected row in the decrypted backup. All ten temporary units,
cgroups and private staging directories were removed. The report SHA-256 is
`1c428eca504dec29b03bfc9391f830f488f0b2d658da6d6907de51054f93f919`.

The closed-binlog stager accepted two CRC-checked files from actual MySQL
8.4.13 rotations and proved the next filename. It refused the current active
log. The collector and archive runner now connect the socket-only catalog,
bounded staging, encrypted publication and read-back to the checkpoint.
The checkpoint advances only when the next expected file and the verified
publication match. Rotation cadence, backlog alerts and timed replay against
the accepted destination still require operational proof. The frozen-base
reviewer verified both publication chains against independently pinned SQLite
snapshot and MySQL coordinate evidence in a local fixture. It rejected
preflight-only evidence and reused backup IDs.

The native archive coordinator trial used the stock PHP base producer,
MySQL 8.4.13 and disposable TLS S3 storage. The collector ran as UID 1000
with a read-only mount of the source log volume. A failed provider call left
the checkpoint byte-identical. A one-file batch limit required two successive
verified publications with distinct IDs. The first left a closed log pending
and the second caught up to the observed active file. A final idle run while
the provider was unreachable kept the checkpoint and receipt set unchanged.
The archive phase took 17.385 seconds for this small fixture. Its synthetic
containers, volume and network were removed. The report SHA-256 is
`9358b239c2e9898fbef28fcb163c371747ecb794b4435da7eedc4c493a1b1c2b`.

The automated archive also passed a point-in-time restore from private TLS
S3. The stock base and two archive publications were the reader's only data
inputs. The source container and volume were removed before a fresh MySQL
instance began recovery. The reader verified artifact hashes, binlog CRCs and
the chosen commit boundary. Replay retained rows 1–3 and excluded the later
row 4. The chosen write took 21.313 seconds from its command to verified
archive read-back, including an injected provider refusal. Recovery took
6.594 seconds from fresh target startup through verification. These timings
cover a small synthetic fixture. The report SHA-256 is
`03dc243b96b8cf3d579c890e907b99cf8f8b360eb0080d4f8d40cef39b46594f`.

The dedicated rotation account passed a separate native MySQL 8.4.13 test.
Its locked-definer procedure advanced the active file exactly once. Direct
flush and reset were refused for the caller. Enabling automatic purge in the
disposable instance blocked rotation before the call and left the inventory
intact. The server exited normally and its private runtime was removed. The
report SHA-256 is
`7a96ef45b3e5a142000b6261c49ec9f5b30654bdd4b4d3d5485bc4b99f53bf85`.
The disabled-by-default two-minute timer, dispatch units and unknown-outcome
guard passed offline contract checks.

The combined Maverick rehearsal passed with transient systemd units on a
disposable native MySQL 8.4.13 source. Two real timer activations occurred
120.180 seconds apart. The first rotated one file and dispatched the archive
job on success. On the next tick, the rotator saw automatic purge enabled,
refused to call the routine, cleared the rotation guard and dispatched the
archive job on failure. The archive job used its separate account and an
explicit read-only mount. It verified both encrypted publications over
private loopback TLS and advanced the checkpoint through `binlog.000003`.
All twelve temporary units,
runtime directories and staged packet files were removed. The report SHA-256
is `c009060b0258d6a70a4f1c6c5a95a3663651d04f6f48790ff45de88256b06952`.

The [recovery heartbeat](../scripts/mysql/offsite/heartbeat.md) passed a
separate 19.86-second synthetic MySQL 8.4.13 rehearsal. The scoped source
writer recorded server UTC in one minute slot. A same-minute retry returned
the stored row with no change to the row count or binlog position. The closed
binlog contained exactly one committed heartbeat event. A fresh instance
restored the marker from a single-database dump after the source and its
volume were removed. Its SELECT-only account accepted the marker exactly 60
seconds before the target and rejected a 61-second gap and a marker after the
target. The recovered row remained unchanged. Both instances shut down
cleanly and the fixture resources were removed. The report SHA-256 is
`ea7dbd49f9409db8739257f8ee9fa4978326ed50c86074c9beca6bd2ea8dc4f9`.

The heartbeat has no production schema or timer installed. Verify the
one-minute cadence and clock on the approved source, then prove a preceding
marker after replay from the protected bucket on a separate recovery host.

The local cutover adapter froze a disposable SQLite Laravel state, copied
four fixture tables into MySQL, verified read-only application access and
refused promotion while the off-site backup gate was unsatisfied. Aborting
reopened SQLite and drained the queued job exactly once. The two recorded
intervals were 613 ms from freeze to the MySQL smoke and 440 ms from abort
to restored SQLite HTTP. This used a synthetic HTTP route and a one-shot
worker. The full timed host rehearsals still need the real ingress,
scheduler, writer inventory and representative load together.
The same test on `08a6e4d855ec29f52c9a24945be888cf41e8d94c` took 587 ms
through the target smoke and 350 ms from abort to the recovered HTTP read.

A pre-write abort against the published `0dfa65dd` source archive reopened
SQLite HTTP in 305 ms. The post-write fixture held HTTP and queue writes,
disconnected an identified MySQL session with an open transaction and proved
native read-only enforcement. The real runner required separate recovery.
The reverse exporter used a SELECT-only credential and created a fresh SQLite
file. All fields in four synthetic tables matched sealed MySQL. Laravel
reopened in 528 ms from the fixture hold, kept the acknowledged MySQL receipt,
ran the queued effect once and accepted a new SQLite HTTP write. The uncommitted
row was absent, and the original SQLite file stayed byte-identical. The pre-write
and post-write reports have SHA-256 digests
`e3fa03144cebbc05414e763e60bbcc2af20bfff50c70727f3948a1c9bd16533e` and
`ae67a58a804b6391f521d00f37588dbc056fb0b21c55bd6186a2b090bb743ff6`, respectively.
The production-sized service drains and recovery run are open.

In a separate isolated webhook trial, the actual capture adapter returned
HTTP 503 while its intake lock was held and while replica verification was
unavailable. An identical protected retry returned 204 and kept the encrypted
receipt unchanged. Two fresh replay processes recovered all seven acknowledged
receipts after the original spool was removed. They produced identical dry-run
projections, and a missing receipt stopped replay. The replica was a second
local directory. Live ingress, off-host durability and effects still need
their integrated rehearsal.

The snapshot-age check accepted the retained synthetic R2 receipt and exact
manifest at the 26-hour limit. It returned stale and a nonzero exit when the
test applied a one-second limit to the same evidence. Both checks ran locally
on Maverick, with no request to R2.

Two isolated pre-write rollback trials set the Laravel configuration cache to
an unavailable MySQL socket, then used the cutover journal and write fence to
restore the saved SQLite cache. The fence denied both admission probes during
authority publication. SQLite authority was restored in 332–340 ms and the
first permitted fresh Laravel read succeeded in 472–489 ms. The SQLite file
and saved configuration cache matched byte-for-byte, and one pending
synthetic job was preserved in each trial. The timings cover the local rollback
component from abort through the first admitted read. The host rehearsals
still need to measure service and worker restart and reopening.

An isolated post-write trial timed the sequence from disconnecting a pending
synthetic writer through the first fresh Laravel read from the new SQLite file
at 371 ms. It proved MySQL read-only before using the existing reverse exporter.
The new file kept both acknowledged transactions, excluded the uncommitted
job and accounting change, and matched all 34 independently compared field
values across eight rows and four tables. Next IDs matched and SQLite foreign
keys were enabled. The old SQLite file stayed byte-identical. This was a small
synthetic trial. The timed full-size host failback remains open.

The current SQLite backup producer and encrypted publication staging passed a
separate fixture with a WAL writer open. The exported single file retained the
committed row and excluded the uncommitted row. The source database and WAL
hashes stayed identical. Staging checked the backup bytes, SQLite integrity and
table count, then wrote the publisher manifest. The real destination and
independent recovery remain acceptance gates.

Concurrent notification updates initially deadlocked on MySQL. The fix locks
the user's row before adding or updating that user's notification state.
The native regression reproduced error 1213 with the original service and
passed with the fix. Two separate small comparisons then completed 1,600
measured service calls, including 320 writes, with the expected final state.
In this fixture MySQL was slower than SQLite. The measured paths run through
the Laravel service and do not represent peak HTTP/FPM or copied-data load.

The paired HTTP workload contract fixes the request schedule before either
engine runs and reports each route separately, including time waiting for
concurrency capacity. The contract tests refuse incomplete schedules,
uncomparable sources, database warnings and logical response differences.
Host load results require a supervised Laravel execution on the two copies,
alongside write, upload, queue and crash checks.

The synthetic FPM fixture completed 56 authenticated `GET /api/notifications`
requests across both engines and two engine orders, with four separate 401
checks. It used the existing route, Sanctum authentication, and request
telemetry. The measured requests produced zero errors, database warnings or
logical response differences. The small MySQL samples were slower under
this instrumented configuration. They establish behavior for one endpoint
and leave production-sized latency and capacity open.

The subsequent [two-route FPM comparison](../scripts/mysql/workload/HTTP-COMPARISON.md)
added incoming voice offers to notifications. All 224 authenticated requests
matched across both engines and engine orders. Four invalid-token and four
client-permission checks passed. The eligible and expired offers were correct,
the persisted voice state was unchanged, and no voice jobs were queued.
MySQL voice p95 was slower in both synthetic surge rounds. This is a signal
to measure the same route on the host with the copied dataset and realistic
worker and connection settings before accepting performance.

The first native two-route run against the server copies passed all 224
requests and 12 access checks. The sustained follow-up passed all 6,364
requests and 12 access checks with matching responses and zero observed
request errors or database warnings. MySQL p95 was within 2.5% of SQLite
across the measured groups. Wider route and workflow coverage and production
capacity checks remain open. The [workload report](../scripts/mysql/workload/HTTP-COMPARISON.md)
records the comparisons and the verified cleanup.

The native upload-acknowledgement run completed 224 matched GETs on the
server copies. The assigned photographer and staff could read the completed
batch. The client and unassigned photographer received 403. Separate
provider-disabled workflow regressions passed 14 cases and 114 assertions
per engine for upload retries, booking permissions and overlap protection,
and invoice report totals. Those regressions ran on fresh synthetic
databases. An independent SELECT-only check against the post-rehearsal
MySQL copy also matched 170,534 selected financial fields across 24,465
invoice, invoice-item, payment, allocation and refund rows against the
closed SQLite baseline.

An expanded synthetic run passed the same 19 cases and 136 assertions on
each engine, covering rescheduling, delivery, payment allocation and refunds,
payout review, and mocked SMS. All 48 cutover runner, write fence and readiness
contracts passed on Linux. The fixtures ran with networking disabled.

A four-client Laravel service comparison completed 6,400 notification reads
and 1,600 writes across both engines and two engine orders. Each round matched
the expected final notification state and all 418 shared-user receipts. Write
p95 was 19.60–19.63 ms on SQLite and 22.25–22.43 ms on MySQL. Separate queue
contract runs passed seven cases on SQLite and four on MySQL. These
measurements used synthetic data on memory-backed filesystems. The combined
HTTP and queue workload on the server copies remains an acceptance gate.

A short 20-client Laravel command-line load then ran alongside two actual
database queue workers. Across four engine-order rounds, all 8,000 notification
operations completed and all 960 jobs produced exactly one committed effect.
Both workers operated during each roughly 2.47-second interval with all
clients active. Queue backlogs drained to zero and final notification state
matched. In this fixture, write p95 was 80.18–130.53 ms on SQLite and
52.68–54.83 ms on MySQL. SQLite completed reads and queue drain faster.
The sustained HTTP load and crash-recovery timings still require the host
dress rehearsals.

The initial single-writer rule has a separately tested MySQL component.
Its lock row lives in a proposed control schema so the application snapshot
still compares exactly. On MySQL 8.4.13, 23 tests checked the scoped grants,
transaction isolation, lock timeout and rollback, savepoints, peer readers,
wrong-target refusal, and release after a worker was killed. The application
does not invoke this component yet. Before enabling it, verify all Laravel and
direct database write paths, the queue exception, retry and cache behavior,
restore provisioning, and representative contention.

A fixture-only integration exercised Laravel's actual transaction manager and
the same native writer lock. The 40 combined native contract tests covered
outer and nested transactions, deferred callbacks, pre-write retry, lock
failures caught by a caller, and a lost commit acknowledgement. They verified
that an uncertain commit cannot replay the callback.

The next native fixture run passed 45 tests and 705 assertions on MySQL
8.4.13. Laravel's builder insert and a bound `affectingStatement` waited for
the token while a plain read proceeded. Both timed out before writing with
the token held and each committed once after release. The fixture verified
the generated ID, outer rollback, token release after failed SQL and a query
listener failure after commit. The log SHA-256 is
`96bb99fe9252be422470264f5d7dea27b83bbc01e0dbca858f940aa80bc611cc`.
Runtime registration, the wider autocommit paths, raw connections and cache
remain open.

The first runtime pilot in `0542467c` guards the exact Google Routes quota
reservation statement behind a flag that defaults to off. It acquires the
writer token on the verified MySQL session, confirms the reservation commit
before the query event, and refuses to replay an uncertain outcome. The
isolated MySQL 8.4.13 contract passed nine tests with 131 assertions,
including two processes competing for the last quota unit. The SQLite
regression passed four tests with 25 assertions. The MySQL log SHA-256 is
`001b3bd9cadefa690a43e1170f8aaf90d60ddfddc41f644d0f8ed361d984e206`.
The pilot made no Google requests. Other business write paths require their
own enforcement and coverage.

The ordinary database queue writes new jobs on the business connection so a
rolled-back transaction also rolls back its enqueue. Worker reservation,
release and delete need a separately scoped exemption. In the disposable
native fixture, two Laravel workers with jobs-only access reserved, released,
reclaimed and deleted one synthetic job while a separate business transaction
held the writer token. The workers and a separate reader could not see an
uncommitted business enqueue. Rolling back removed that enqueue and the
business update. The scoped account was refused business writes and access
to the control table. The report SHA-256 is
`7616c9f3d41726713b168f4d2e194b60d2fe9d43d3685f8370678d4dece6a7ba`.
The default database queue now has a disabled-by-default routing pilot
at `f4b1826f`. Claim, release and acknowledgement use a separately
authenticated account restricted to the jobs table. Normal enqueue still
uses the business transaction and is removed by rollback. The pilot
verifies a replacement PDO session before it can acknowledge existing work.
Its isolated MySQL 8.4.13 contract passed six tests and 118 assertions,
and the stock SQLite queue passed seven tests and 33 assertions. The
native test log SHA-256 is
`3c4597bee51458d4880193c1697fa5f304499eadfbd69e2915ddcd8ed33e557a`.
Studio and iGUIDE Lab Tour now have independent, disabled-by-default
settings. The hosted socket-only contract at `d30f9dec` passed for the
three logical queue names. It exercised the original Studio and iGUIDE
retry intervals, after-commit enqueue, rollback and acknowledgement
through a reverified worker session. [Hosted contract](https://github.com/superdevvs/Repro_Backend/actions/runs/45396284788/job/138452825929)
The existing iGUIDE configuration suite passed all nine tests and 136
assertions after the disabled settings retained their original configuration
shape. Five targeted checks also compared the settings for enabled MySQL
routes and the disabled defaults on both engines.

Listing Studio rejection counters use an independent session so they survive
business rollback. The runtime writer policy must preserve this behavior.
Having that second session wait for a token held by the business session
would block its own caller.

Two more native fixtures at `26cc2fe4` exercised the actual Listing Studio
services. The counter used a narrowly granted synthetic account and committed
while an independent observer verified the business token was held. A
contended counter write took about one second, logged one generic error and
added no duplicate. The business connection kept its five-second lock timeout.
In the quota-rejection run, the actual service consumed a synthetic admission
under the token, then returned `trial_daily_limit` with HTTP status 429.
An independent reader verified rollback restored the admission and guard,
and exactly one counter committed afterward. The provider readiness check
was stubbed in the isolated fixture. The two report SHA-256 values are
`bcfbf43a2f8f265f41bcec9bed70c79b707a8120825b8ab0171ecc1128641f47`
and `f0ab9ec918ba2f225deae68e48928207e40f9a3268f4742b45fd312c898c720a`.
Runtime account routing and the other telemetry writes still need evidence.

An isolated cache test at `30b3892c` used a separate table-scoped MySQL
principal. Stock Laravel cache writes and scheduler locks progressed while a
business transaction held the writer token. Studio's middleware released a
contended job once and freed its own lock after a downstream failure. The
committed cache entry survived business rollback. The fixture verified that
the cache principal had no business or token access, and cleaned up both
512 MiB containers and their temporary socket volume. The proof SHA-256 is
`f7e792544f09fbdd99ae0cc0430301ff5470eb6361056d645e7a588c29715f5c`.
The scheduling store has a MySQL-only pilot flag,
`REPRO_MYSQL_SCHEDULING_CACHE`, which defaults to false. The flag uses a
separate session for the existing `cache` and `cache_locks` tables for
this store. The cache username and password come from
`REPRO_MYSQL_SCHEDULING_CACHE_USERNAME` and
`REPRO_MYSQL_SCHEDULING_CACHE_PASSWORD`. A newly established or replacement
session has to match the pinned socket and server, and the local principal
can have only SELECT, INSERT, UPDATE and DELETE on the two InnoDB tables.
The default database cache continues to honor its existing settings.
Three native MySQL 8.4.13 tests verified the routing flags, stock Laravel
cache and lock behavior across business rollback, and refusal of broader
grants on a replacement session. A separate 22-case native fixture verified
that losing a real COMMIT acknowledgement preserves sibling connections'
pending callbacks without replaying the committed write. The test log
digests are `8799e47b10ef2faa6a8c1dcaa0a43f5ee5188df594ea410315abcd73367e5309`
and `454aeebf1b8605c0f9278f78c2195306ac7c50a059bfd2181bd9fed33cd0aa39`.
The broader cache and business-write ordering and the runtime transaction
manager binding still need application acceptance.

The refreshed read-only host inventory observed 12 queue workers. Three use
Supervisor, one uses the media archives service, and eight appear under the
cron service. PHP-FPM and nginx are active. The final rehearsal must verify
the scheduler, worker restart sources, provider intake and manual/deploy
writers before reporting a freeze.

Network-isolated subprocess tests verified the runner and admission guard
against the same real lock and journal. An admitted operation held off a
competing freeze. A process killed after publishing authority proof left both
engine admissions closed until recovery. The private Telnyx inbox can also
authenticate an inventory of its captured receipts and flag multiple signed
versions or voice events for review. The host admission wrappers and
independently durable webhook capture and replay still need isolated
integration proof before any production wiring.

The exact deployment admission guard at release `91f6241429fc` also passed
98 isolated Linux cases using its fixed protected paths and actual exclusive
locks. It held deployments closed until the selected application engine and
reconciliation were both verified, and rejected stale activation proofs.
The guard, runner and readiness suites passed 53 checks on Maverick without
starting its application or workers.

A disposable Linux scheduler fixture reproduced a freeze gap. A background
writer detached, closed the inherited lease descriptor and committed one
SQLite row after the wrapper was killed and an operator acquired the exclusive
lock. The proposed durable permit refused the transition, refused another
launch while starts were closed, and allowed the transition only after the
existing writer exited. The fixture exercised real Linux processes and the
SQLite write. The systemd supervisor, permit retirement and Laravel scheduling
still need integration proof together.

The runner now requires a scheduler hold tied to the cutover identity and
boot before it can freeze the databases, prepare or promote MySQL, or seal a
SQLite rollback. The promotion approval binds the hold. A separate Linux
run passed 57 runner, cross-process lock, fence and readiness checks,
including a pending scheduler permit and a hold that changed during authority
publication. The fixture supplies the hold from local state. The host still
needs to supply it through the supervised scheduler.

Maverick's user-level systemd fixture stopped before it could start a scheduler
writer because its namespace controls left host interfaces, the manager socket
and a writable cgroup visible. A subsequent administrator-supervised test
passed both scenarios in temporary systemd units. It verified a private
network, blocked socket creation and access to the systemd manager and live
application files, and mounted the cgroup filesystem read-only.

The detached writer committed exactly once after its wrapper exited. Its
permit stayed present despite acquisition of the exclusive lock. The root
supervisor verified the bound writer exited, its cgroup was empty, and no
manager job remained before retiring the permit. It then cancelled a pending
manager start and confirmed it committed nothing. The two drains took 6.53 ms
and 6.27 ms, measured from the stop request to observed drain. The transient
units and scratch were removed, and the existing MySQL invocation stayed the
same. The synthetic witness report has SHA-256
`b82cea13b4fa9b79094de86936361bc2fc47f5fa5f8f44e0bbc92ae15e60ebc7`.
Laravel's scheduler and all other writer sources still need to exercise the
production adapter during the complete dress rehearsals.

A native synthetic run at `30b3892c` added the stock Laravel scheduler command
with one explicitly registered event to the actual cutover Runner. The hold
denied later HTTP, queue and scheduler work while an admitted scheduler
transaction was still open. The Runner refused to seal SQLite before it saw
that process exit. The scheduled write committed once, appeared in the sealed
snapshot and was visible in read-only MySQL. Abort reopened SQLite through the
authority guard, and the restarted schedule and queued job each completed once.
The hold-to-MySQL-read measurement was 673.063 ms. The report SHA-256 is
`649c30536635bdba16219d0b1d9e266c628ad67a64af50e7e8bed12fdbcdb00e`.
The fixture exercised synthetic Laravel and database processes. The physical
host's systemd and production writer inventory still require an integrated run.

A second synthetic run exercised the scheduled writer after MySQL opened.
The hold refused later starts, waited for the admitted transaction to commit
and exit, then disconnected a separate uncommitted writer before sealing.
The SELECT-only reverse export matched all four fixture tables. Guarded
SQLite reopening preserved one scheduled and one HTTP effect, refused a
retry on the old MySQL route, and completed the queued effect once. The
uncommitted row was absent. The Runner kept the off-site refusal and
`recovery_required` state. SQLite HTTP reopened in 648 ms within the fixture.
The report SHA-256 is
`7bee4be318d74584be677c9f8e51db77fb6ff50fa06ab73b20b7c8571a7bdf11`.

A separate disposable PHP process recovered and authenticated all eight
synthetic acknowledged receipts from the second encrypted spool after the
source spool was removed. The dry-run projection recorded one SMS effect,
one duplicate and six receipts requiring review. Missing, additional or
altered receipts failed reconciliation.

Two separate native trials on `917f6357` exercised the stock host runner with
real Laravel HTTP and queues on disposable four-table databases. The hold to
verified MySQL read-only smoke took 576 and 582 ms. Verified SQLite recovery
took 357 ms from the pre-write abort and 623 ms from the post-write hold. The
second trial independently matched the reverse export, preserved the committed
MySQL write, excluded an uncommitted write and processed the queue job once.
Off-site refusal remained enforced. Both trials removed their containers and
volumes. The timestamped receipt has SHA-256
`cfe788228e8de3aac98adfa8255ec0b811a42f630c7e6ca8d40501c380f181c3`.

A separate isolated FPM run at `42d4c678` closed ingress with a Laravel
request already admitted. The later request received HTTP 503 without
reaching FPM or writing to SQLite. The admitted request returned HTTP 200
and committed one matching trace. An early seal was refused. After the
response and database effect were verified, the test sent SIGQUIT to the
owned FPM master and verified its exit and both worker exits before sealing
SQLite. The hold-to-seal measurement was 208.098 ms. The receipt SHA-256 is
`2adfb4cde33c94d5dcd05eca6f216f422417785e1f41d26a92de53ea86824e7b`.
Stopping while a request was held failed with the default FPM timeout. A
separate run with an explicit 16-second process-control timeout preserved the
admitted response and trace and verified the same seal ordering. Its receipt
SHA-256 is `fdc196456c7f9554d67230f974ad5e8d43399bd4e704309f66a9ac114b29ce2e`.
This scope covers synthetic FPM ingress. The actual host needs a coordinated
drain of its ingress, workers and scheduler.

Full dress acceptance combines a fresh production-sized copy, all real writer
drains, provider intake, representative traffic, protected off-site recovery,
and both rollback paths under one clock. It requires two timed runs. Live
cutover requires distinct approval.

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

The `0dfa65dd` synthetic FPM repeat matched 224 paired GETs and 20 access
checks across notifications, voice and upload summaries. Peak/surge arrival
p95 exceeded SQLite by more than 10% in 10 of 16 route-round groups. The
largest was voice at 185.6 vs 294.1 ms over nine samples per engine. The
small populations, short test phases and disabled OPcache limit the result.
Use it to select routes for the representative performance run. The report
SHA-256 is `8af507fa7c062c86981d70906c90c44416abc83ced504f8c71a74bff0d968b00`.

A follow-up emphasized voice incoming offers and upload summaries. Across
four counterbalanced synthetic arms, 800 requests and 20 access checks
matched with zero errors, warnings or logical differences. Peak MySQL p95
rose 15% and 26% for voice, and 6% and 16% for upload summaries. Laravel
reported higher database time on MySQL for both routes in both peak rounds.
At surge, slower service in each second arm occupied both request slots and
accounted for the longer wait. Dispatch after a slot freed took under 1 ms.
The cause of the slower service and representative host performance remain
open. The verified timing report SHA-256 is
`5aba1afe821239b2e895408b9f5b4a2eb0a2e4f4df3e19c124def6da229eeab2`.

A repeat at `15300b26` started a fresh FPM container and database instance
for each arm. All 800 requests and 20 access checks passed. Surge dispatch
p95 stayed below 43 ms across all four arms. MySQL surge p95 for voice and
upload summaries stayed within 5% of SQLite. Logged database time remained
higher on MySQL, and OPcache was disabled in all arms. Separate fixture
clocks produced timestamp-sensitive payload differences, so exact cross-arm
voice and upload response parity remains open for this run. The verified
report SHA-256 is
`7dd3eb7a94426726988cb192e3a5cdc196ce8b083fab5efd2cbb866827ad1912`.

A separate upload-summary regression reproduced three service-item queries
for a photographer's request with two service items. The route now loads the
service items once and reuses the existing authorization check. The focused
suite passed nine tests and 57 assertions on each engine, including assigned
and unassigned photographers, unit scope and upload acknowledgement replay.

The fresh-container parity repeat at `9c54c7a9` pinned the same application
source, dependencies, request profile and logical UTC clock for both engines.
SQLite and MySQL returned identical canonical JSON in 200 matched request
pairs across voice offers, upload summaries and notifications. All ten access
checks passed and all fixture containers and volumes were removed. The
verified report SHA-256 is
`217a0b6f86eed0b9bd7a6932af49c04cee43059e87476f531cd0cdca84443c02`.
The representative host workload remains a separate gate.

A separate synthetic CLI comparison at `0dfa65dd` ran 8,000 measured
operations and 960 jobs with 20 clients and two Laravel workers in each arm.
Every expected job effect was present once, with zero failures or backlog.
SQLite to MySQL write p95 fell from 55.1 to 29.2 ms and from 130.8 to 58.9 ms
across the two engine orders. Read p95 and queue drain time rose in both
rounds. This comparison measures CLI and queue concurrency. The FPM trial
above exercised HTTP separately. The verification report SHA-256 is
`08ccc13193fa9fcf33d1dd1c2bbf5ebff7ae2cace478bd3126e686698f9d1824`.

The booking patch in `9e7b3bca` gives related MySQL schedule writes one
transaction when the optional travel guard has no enclosing transaction.
On MySQL 8.4.13 with READ COMMITTED, an injected failure rolled back the
appointment, hold fee and service alignment. A successful booking broadcast
after the database commit and invalidated the schedule and dashboard caches.
An injected broadcast exception did not replay the booking. Three focused
cases passed with 39 assertions on each engine. A broader MySQL booking run
passed 90 cases with 815 assertions. The broader SQLite run reached its
125-second fixture deadline. The focused two-engine log SHA-256 is
`d972bd525ba0d8e93b21160aa7f0f51447650835705b29443e7ab6eb3e08e184`.
Application-wide MySQL writer coverage still requires acceptance.

## Operational work still requiring approval

The read-only inventory observed MySQL **8.0.46**, 32 CPUs, 32 GB RAM, 20 FPM
children and twelve application workers on Maverick. The separate socket-only
[native MySQL 8.4.13 service](../scripts/mysql/native/README.md) subsequently
passed empty-instance acceptance. The last attended check left its service
and timer stopped and disabled. Reconfirm the installed bundle and saved
identity before another attended trial.

A read-only inventory on November 24 at 15:19 IST matched the live backend to
`9ef19f68` and the frontend to `1e3e26d3`. The configured driver was SQLite.
Maverick owned eight active queue workers and `www-data` owned four. The
isolated MySQL service and its rotation timer remained disabled and inactive.
The candidate's two off-site backup timers had no installed systemd units
under their reviewed names. Read-only inspection also confirmed the public
mount metadata for the configured originals disk.

The November 24 read-only inventory refresh confirmed the four `www-data`
workers and eight deployment-user workers. The running user Supervisor
matched its reviewed configuration, which names four realtime voice, two
voice notification, one voice transcript and one Studio worker. The two
reviewed backup timers still had no installed units. This inventory covers
observable processes and the deployment user's schedule.

The November 24 read-only administrator check examined nine cron files
across the system and user schedules. It found eight named application
entries, including the application scheduler, two Studio entries and the
deployment entry. It also identified SMART and inventory monitoring timers.
The administrative spool was readable and its inspected file had no
matching application command. The scan only classified named commands.
Trace wrapper actions, provider intake, manual access and PHP-FPM writers
through the actual admission fence before counting a timed rehearsal.
The inventory receipt SHA-256 is
`c6756eec9e337a2fcea49a2fc70298a42160a58440c34cc885affb15f2884cee`.

A subsequent metadata-only capacity probe found a 4.46 GB SQLite source,
approximately 143 GB available on the proposed private staging filesystem,
and approximately 26 GiB available memory at the time of the probe.
The installed MySQL binary reported 8.4.13, the CLI PHP process had SQLite
and MySQL drivers, and the host Python build included SQLite DBSTAT. Measure
available capacity again before taking a fresh snapshot or starting a
disposable server. A new production-derived import belongs in a disposable
data directory. The installed target uses retained full-row binary logs.

The [scope audit](../scripts/mysql/scope-audit.py) checks that a pinned
manifest classifies every table in a standalone candidate snapshot. It
reports allocated table and index bytes and refuses incomplete size
measurements. All 29 synthetic scope and schema drift checks passed with
the host's SQLite 3.45.1 build. The receipt SHA-256 is
`2427289754116d0cc65bdd29d9d9493309cc05869ec34e0fb5cac132664836f7`.
Review the current manifest against a fresh snapshot before counting any
nightly run. The earlier 291-table snapshot and the 284-table release
fixture remain historical evidence.

The [candidate scope](../database/schema/migration-scope.json) lists all 291
tables for preservation. The native scope audit verified available table
and index size measurements and zero missing or unlisted names on the
November 24 private snapshot. Its exact manifest SHA-256 is
`8fcb094f38c2ceeff40c9ee33b5ead2ee802666d87a9bdc854770daf9eb4ab21`.
Pin the manifest again with the final release and recheck the next
scheduled snapshot.

The importer now requires MySQL notes to be enabled and stops when a
conversion statement returns a warning or note. It checks each statement
inside a split batch and checks streaming reads at the end of the result.
The socket-only MySQL 8.4.13 fixture demonstrated both failure modes and a
successful forward and reverse conversion, including arbitrary binary
bytes, the approved synthetic historical orphan, and trigger behavior.
The receipt SHA-256 is
`9fd2b488325f1be70c797d5ea894eb98e244627482b314f7998223e89eb8b10d`.

The first strict import of the empty 291-table layout stopped at MySQL
warning 1831 for a duplicate index on `shoots.status`. The converter now
retains the original Laravel index in each of five reviewed, equivalent
nonunique pairs. The schema plan binds the exact omitted and retained
names. The SQLite reverse template still creates both original indexes.
The isolated rerun with the installed Maverick MySQL 8.4.13 binary verified
all 291 empty tables, 671 explicitly created indexes, 477 foreign keys,
15 checks and 25 triggers with zero warnings or notes in 74.884 seconds.
The temporary instance stopped and its workspace was removed. The receipt
SHA-256 is `706cf95d8d021706104316445d3035f94f6949a739d3db7a395146e82b21f4ad`.
This layout run measured metadata only.

The [eight-week boundary census](../scripts/mysql/report_week_boundaries.py)
uses a pinned, read-only SQLite snapshot to count differences between the
existing invoice and sales-rep payout date predicates and calendar-day
membership. Six synthetic tests passed, including date-only Sunday values
and fractional timestamps at the end of Saturday.

The fresh, privately authorized November 24 data rehearsal at `61ba8a83`
took a 4.46 GB online snapshot from SQLite. The snapshot's 291-table schema
and exact historical exception list matched their reviewed baselines. The
disposable native MySQL 8.4.13 import verified 1,857,367 rows with zero
warnings or notes. The independent PHP reader verified 29,857,089 fields
with zero differences. Import and both verification passes took 700.066
seconds. The reverse export and independent verification of the same data
took 174.563 seconds. The temporary MySQL process and its files were
removed. The original snapshot remains in the protected test workspace.
The aggregate receipt SHA-256 is
`b35c4e231590a62ab1636c5062d937d46c95e574938953d0302bb114f5702dd0`.
[Exact release CI](https://github.com/superdevvs/Repro_Backend/actions/runs/45375878109)

On that snapshot, the two finance date predicates returned the same counts
on both engines in each of the eight weeks. The historical date-only
fallback excluded 32 rows that fell within the calendar week before
eligibility filters. Full invoice and payout output parity still requires
the application rehearsal.

The isolated synthetic finance matrix ran the same 19 cases and 167
assertions on each engine at `61ba8a83`. It covered invoice summaries and
past-due results, payment reconciliation, photographer and sales-rep
payout rules, editor payout state and duplicate prevention. The fixtures
had networking disabled and were removed after each engine run. The test
receipt SHA-256 is
`0009fae84239220e78868898b2fc3f8d511ba30fffccdd5e2c72dba22aaaa930`.

The private application finance rehearsal used disposable derivatives of
the same protected snapshot and the exact application package at
`f4b1826f`. The sandbox proved a private network namespace, refused
Internet socket families, hid production paths and mounted the staged
input read-only. SQLite and MySQL matched across eight weeks of reports,
past-due invoices and the last completed week's photographer
reconciliation. The latest completed week's generation matched for payout
invoices, payout work allocations, editor payouts and six sampled client
invoices. The temporary service and both working databases were removed.
The snapshot and live database services kept their existing identities.
The receipt SHA-256 is
`b5f5c5a525534aa1e4744165af1c10657992953100b63ab48b386d99ed60f2eb`.
This run used the lock before the JWT security update in `7114966b`.

Before cutover, the actual target needs administrator acceptance for service/boot
behavior, FPM and both worker owners, measured load, storage/disk alerts,
off-host backup destination/retention and an independent restore. Inventory all
cron/timer writers and provider event handling, designate any required live
provider/device checks, and approve a maintenance window. Revalidate current
release/data drift at that point. The read-only inventory and test copy are
time-specific; do not import this older snapshot into a live application.

Use the [native storage placement check](../scripts/mysql/native/README.md#read-only-storage-separation-acceptance)
to compare the reviewed originals drive with MySQL's actual private data
directory. The Laravel media health command checks the application's local
`database/` directory in MySQL mode. On November 24, the administrator
check verified the native data directory on device `259:4` and the
originals mount on `8:0`. All 37 Linux tests and the host's mount parser
check passed after the parser learned to accept Linux namespace entries.
The check removed its temporary stage, and the managed native service
remained inactive and disabled. Storage acceptance receipt SHA-256
`7ea0fadf04eb6eeb0298990804089aedf541cd118853f05505e7587c1b67b7b5`.

The plan calls for seven clean days of verified backups, a snapshot age under
26 hours and off-site binlog lag within five minutes 99% of the time. Test
overwrite and delete refusals with the backup writer credential and verify
alert delivery independently. Time two end-to-end rehearsals with comparable
load, the whole writer inventory, provider intake and both rollback paths.
The agreed maximum production write outage is 60 minutes. Schedule a separate
cutover decision after these gates have evidence.

The [offline readiness checker](../scripts/cutover/readiness/README.md) now
requires independently reviewed report digests for that backup window, exact
provider retention, both external alert rounds, two point-in-time drills and
five consecutive nightly parity runs. The last two nightly runs must use the
exact frozen release. The checker also requires proof that the application
enforces the first-release MySQL writer serialization policy. The offline
backup-health adapter derives the seven-day elapsed metrics from independently
reviewed observations, including unverified recovery points.
Matching offline adapters derive the reviewed nightly, point-in-time and
external alert receipts from SHA-256-pinned source evidence. Provider emails
from the synthetic pilot were reported delivered. The operator observed the
backup-failure notification. The November 24 rerun produced one missed
check-in event at around 18:04 IST. Healthchecks reported delivery and the
operator confirmed that the email reached the inbox. The operator observed
no iPhone notification for that event. The rehearsal check was paused
again. The handset gate remains open. Retry receipt SHA-256
`7cb4df84a8921c66133103d25037018dca2c632acca82c8f551baa9005a7d4d4`.

Use the [cutover and rollback runbook](mysql-production-cutover.md). Its tools
produce verified artifacts and deliberately never switch a live application.
The local, network-disabled Laravel harness ran both rollback paths at
`2ef68343`. The pre-write path refused promotion, restored SQLite and
processed its queued job once. The post-write path sealed MySQL, recovered
one acknowledged HTTP write into a fresh SQLite file, excluded the
uncommitted MySQL write and processed the recovered queued job once.
The original SQLite file stayed byte-identical. The report digests are
`43098b8c617f7e01c3304e3f4d235a9e4386c6ef61c7172d050c8511e789ab13`
and `5d88e8ff830900538ecff49652ccea3195dc20a60fa2d0ac65dea07da7d4ec35`.
The fixture uses four small tables, a CLI PHP server and a one-shot queue.
The production-sized drills still need actual admission, scheduler, load
and off-site evidence.

The cutover runner also passed a synthetic full-disk regression. A failed
atomic journal replacement after the SQLite authority proof was written
left the abort pending and kept admission closed. A verified retry
restored SQLite authority and reopened admission.

Reopening writes and eventual post-write rollback each require their own
controlled validation; the old frozen SQLite file cannot retain later MySQL
activity.

The November 25 lanes add these owner decisions and production actions. None
has been done. Each production step needs its own approval.

- Writer policy: decide whether the policy stays on by default for MySQL
  (`REPRO_MYSQL_WRITER_POLICY`), and sign off D1 to D6 and B1 to B18 in the
  [known-differences register](mysql-known-differences.md), including B18's
  30-minute window. It must exceed the longest weekly run on production.
- Writer policy: provision the token schema, row and grants on the native
  8.4.13 target, and give the monitor account its grants.
- Writer policy: set `REPRO_MYSQL_*` in the rehearsal environment and run
  representative load and the dress rehearsals with the policy on (WP-11,
  WP-12).
- Writer policy: approve the push that runs the shard-0 writer suite on
  GitHub, and wire `db:writer-policy-check` into every deploy once MySQL is
  live.
- Writer hold: decide O1 (one approval for hold and freeze or two; an
  external hold is refused, never adopted; who signs approvals) and O2
  (long-job queues drained before the hold, 600-second drain limit). Reopen
  after a reboot stays manual, and the nginx hold has no smoke-test bypass.
- Writer hold: W1, install the guard, controller, wrappers, watchdog units,
  `tmpfiles.d` entry, nginx include, sudoers, Supervisor and cron changes,
  then observe 24 to 48 hours in `planned` (C1) with deploys held. Deploy
  the candidate first, because W1 holds every deploy until the cutover ends.
- Writer hold: W2, a drill with real services, an in-flight request and job,
  restarts and a reboot.
- Writer hold: after an aborted and reopened cutover, retire the installed
  fences or start a new run before the next deploy.
- Writer hold: write and review the host identity and MySQL probes before
  W1, and complete `host.json` (all four owner options, the lab-tour queue
  if installed, `drain_fpm`) before `init`.
- Cutover runbook: build the
  [MySQL adapter lane](mysql-production-cutover.md#mysql-adapter-lane-not-built),
  and decide its open choices: when the `.env` switch happens, the smoke
  path, the Telnyx capture during the hold, and a path out of
  `recovery_required`.
- Cutover runbook: the read-only production checks before the night (which
  cache store holds the queue pause flags, FPM's OPcache settings and unit
  name, the host time zone for cron).
- Producer replay: publish the website changes P2-02, P2-05 and the P2-06
  export, with their Supabase migration.
- Producer replay: add the `repro-holds` include line to both Dashboard
  vhosts in a reviewed deploy, and decide whether the edge snippet keeps all
  of `/api/external/` or only the producer paths.
- Producer replay: create two Stripe restricted keys with Events read only.
  Confirm with the providers what their docs say (runbook
  [provider table](mysql-production-cutover.md#providers-during-a-60-minute-hold)):
  Aryeo's 503 retries, Resend's replay identity, and Telnyx's retry
  intervals, which the docs do not give.
- Producer replay: accept that a replay of a receipt written before the lane
  gets 409 for staff review.
- Nightly runs: decide owner items 1 to 8 (finance step, reverse trigger
  helper, applicant files root, snapshot retention, budget tuning,
  scheduling, dress profiles, extra dress gates).
- Nightly runs: copy the candidate checkout and the pinned PyMySQL wheel
  under `/home/maverick`, run at least five approved nights as `maverick`,
  rank the 50 busiest endpoints on a private copy on the host, and set the
  identity from the installed target's server UUID (N7). Deleting older
  snapshots on the server needs its own approval.
- Off-site: create the bucket, the read-only restore key, the upload key and
  two passphrases, then the lock and lifecycle rules (owner checklist in the
  [runbook](mysql-production-cutover.md#owner-checklist-bucket-two-keys-two-passphrases)).
  Choose the upload key type; the derived credential needs one approved R2
  test first.
- Off-site: install `base-backup`, `alerts` and `recovery-kit` on Maverick
  and enable their timers after review. Copy the `APP_KEY` line into the
  password manager on the server.
- Off-site: decide B1 e (the seven-day window and alert round 1 on the
  native rehearsal instance), then meet the MySQL leg prerequisites before
  its apply.
- Off-site: confirm the monthly reboot's source and time zone and keep or
  move the 03:30 UTC backup; choose the alert provider and phone channel (B4)
  and `alert_fail_suffix`; decide on an app-side WAL keeper; supply the SQL
  and baseline for the money check.

Off-site alerts on the cutover night and after a held reboot (local only,
nothing installed): once the writer hold is installed, a reboot still leaves
the site and every writer held until `cli.py refresh` and `cli.py reopen`, by
design. The probe's new `site_held` check (wake) pages for that state with
the remedy, and for any hold past the plan's 45 and 60 minute limits, from
an earlier boot, or with no readable journal. It reads the journal through a
root view unit with no capabilities. `server_error_rate` leaves out every
503 from the start of a hold of the current boot until the reopen, capped
at 60 minutes, whatever the phase (`site_held` still pages outside the
limits). The engine checks follow the journal's authoritative engine, and
`writer_token_held_too_long` (daytime, a holder of 30 s or more) covers the
writer policy's token alert. One probe run at the very start of a hold can
send one false `site_held` page. `site_unreachable` is paused at the
provider for the hold, an owner step; `/up` stays held (see the runbook's
[Alerts](mysql-production-cutover.md#alerts-b6-b7)). Evidence: off-site tests
240 on the merged candidate `4e4a262d` (as root from `/app`: 240 OK, 4
skipped; as uid 1000 from a copy at `6b29f033`: 240 OK, 1 skipped) and
the installer 25 as root; real systemd 252 manager fixture 26 of 26, and 32
units pass `systemd-analyze verify`; on MySQL 8.4.13 the token query needs
only `PROCESS` and `SELECT` on `performance_schema.data_locks`. A
`writes_possible` case in the 60-minute tests and a test that pins the
token query text come with the code-fix lane. Plan on-call cover for the
monthly reboot or schedule around it.
