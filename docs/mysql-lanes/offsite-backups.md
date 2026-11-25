# Lane: off-site backups and alerts (items B5 to B9)

Branch `claude/offsite-backups-20261125`, based on `a6e799bb` (draft PR 46 head).
Nothing here was installed on production. Every test used fixtures, loopback
services or disposable containers with no network.

This file is written so an integrator can fold it into
`docs/mysql-production-readiness.md` (what is built and proven) and
`docs/mysql-production-cutover.md` (what the owner and sessions do). The last
section says which part goes where.

## What this lane adds

| Item | What exists now | Status |
| --- | --- | --- |
| B5 installer | `scripts/mysql/offsite/install_backup_host.py`: jobs `base-backup`, `alerts`, `recovery-kit` (preview, apply, self-test, undo) and `mysql-recovery` (preview only). The no-echo config entry moved into the repo as `enter_r2_config.py`. | Done and tested under a real systemd 252 manager. Not run on Maverick. |
| B6 bridge and heartbeat | `alert_bridge.py`; units `repro-alert-backup-{ok,failed,fresh,stale}.service`, `repro-alert-heartbeat.{service,timer}`, `repro-alert-forward.{service,timer}`. Backup and watchdog results now start the bridge units. | Done. Phone delivery still needs B4 and B14. |
| B7 detectors | `alert_probe.py` and `alert-conditions.json` (one file for every threshold). Twelve server-side detectors; `server_silent`, `backup_stale` and `site_unreachable` come from the heartbeat, the watchdog and the provider's website check. | Done for the code. Rounds still need the owner (B14). |
| B8 MySQL units, purge, kit | Units for a one-minute heartbeat writer, two-minute rotation with archive dispatch and catch-up, hourly archive-aware purge (`purge_archived_binlogs.py`), and the recovery kit (`produce_recovery_kit.py`, `verify_recovery_kit.php`). | Code and units done; the purge ran on native MySQL 8.4.13. The MySQL leg install is preview only (needs B1 e). No 30-minute timer-driven cadence run yet. |
| B9 observer and drill runner | `observe_backup_health.py` (seven-day observer and export) and `run_pitr_drill.py` (writes the `replay.json` set). | Done against synthetic inputs; the drill's load, replay and checks ran on native MySQL 8.4.13. No run against a bucket (rclone retrieval, chain verifier and heartbeat check in one drill) yet. |

## Owner checklist: bucket, two keys, two passphrases (corrected)

This replaces steps B.2 and C of the week-1 checklist. The old step asked for one
"Object Read & Write" key. That key can delete, and the same key would sit on
the server and on the restore machine. The design needs two separate keys.

Do these in the Cloudflare dashboard and your password manager. Never paste a
key, secret or passphrase into a chat. Sessions only ever need the bucket name,
the account ID and the key fingerprints the tools print.

### 1. Bucket (5 minutes)

1. **R2 Object Storage**, then **Create bucket**. Name: `repro-db-backups`.
   Location: automatic. Default storage class.
2. In the bucket's **Settings**, keep **Public access** off: no r2.dev URL and no
   custom domain.
3. Save in the password manager: the bucket name and the S3 address
   `https://<account id>.r2.cloudflarestorage.com`.
4. Do not add lock or clean-up rules yet. Step 4 below gives the exact values.

### 2. The read-only restore key (5 minutes)

1. **R2**, then **Manage API tokens**, then **Create API token**.
2. Name: `repro-db-backups restore (read-only)`. Permission: **Object Read only**.
   Apply to **specific bucket**: `repro-db-backups`. TTL: forever, or a date you
   will remember to renew.
3. Save the **Access Key ID** and **Secret Access Key** in the password manager as
   "ReProDashboard backups: restore key (read-only)". The secret is shown once.
4. This key belongs only on the recovery machine (this PC). Never type it on the
   server. The server's config tool refuses it there by design.

### 3. The upload key for the server (your choice, 10 minutes)

The server must be able to write new backups. It should not be able to delete
them. R2's dashboard tokens offer read, read-and-write or admin; none is
"write but not delete". So there are two ways:

- **A. Upload-only derived credential (the installer's default).** Create a
  bucket-scoped **Object Read & Write** token as a *parent* and keep it only in
  the password manager, never on the server. From it, a short-lived child
  credential is signed with object read and write actions and no delete, as in
  the 24 November retention trial (fingerprint `de376a…`). You type the child's
  key ID, secret and session token at the server's no-echo prompt. It expires,
  so it must be renewed on a calendar. The signing helper and the exact action
  list rclone needs for multipart uploads are **not in the repo yet**; they need
  one approved test against R2 before you rely on this option.
- **B. Bucket-scoped read-and-write key on the server.** Create a token with
  **Object Read & Write** for `repro-db-backups` only. It does not expire, so
  nightly backups never stop because of it. It *can* delete objects whose lock
  period has passed. Inside the lock period the bucket refuses its deletes and
  overwrites (the trial saw `409 ObjectLockedByBucketPolicy`). Set
  `"upload_credential": "scoped-read-write"` in the installer settings to choose it.

Either way: name the token `repro-db-backups upload (server)`, save its values as
"ReProDashboard backups: upload key (server)", and keep any admin token off both
machines.

### 4. Lock and clean-up rules (5 minutes, after the installer preview)

The installer preview prints these again with the JSON the retention check reads.
In the bucket's **Settings**:

| Prefix | Bucket lock rule | Object lifecycle rule |
| --- | --- | --- |
| `daily/` | 30 days | delete after 31 days |
| `binlog/` | 30 days | delete after 31 days |
| `monthly/` | 365 days | delete after 366 days |
| `final-sqlite/` | 365 days | delete after 366 days |
| (all) | none | keep the default 7-day multipart abort rule |

Rules must name the visible outer prefix; names below it are encrypted.

### 5. Two passphrases (5 minutes)

1. In the password manager create two long random passphrases (five or more
   words, or 30 or more characters each).
2. Label them "ReProDashboard backups: passphrase 1" and "passphrase 2".
3. Every restore needs both. They must not exist only on the server.
4. You type them at the installer's no-echo prompt on the server and again at
   the restore machine's prompt.

## Installer runbook (B5)

The installer follows the `install-download-tcp.py` style: preview by default,
`--apply` and `--undo` with sudo. Copy the reviewed bundle to a root-owned folder
first; apply refuses a bundle another account could change.

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
`alert_fail_suffix` `/fail` (use `null` for a provider with no fail endpoint),
`probe_conditions`, `access_logs` and `disks`.

What apply does for `base-backup`:

- Creates the system account `repro-backup` (no login, no home).
- Installs `/opt/repro-backup-tools/*` root 0444, `/etc/repro-backup/plan.json`
  root:repro-backup 0640 and `/var/lib/repro-backup` 0700.
- Adds **read-only** POSIX ACL entries: `.env`; the database folder (r-x) with a
  default entry so new `-wal` and `-shm` files are readable; the database file,
  `-wal` and `-shm`; and `--x` on any parent the account cannot traverse.
  Nothing is made writable. SQLite sets new `-wal`/`-shm` modes from the
  database file, so the app's own access does not change. One side effect: with
  a default ACL on the database folder, other new files created directly in that
  folder take their group and other bits from the folder's mode instead of the
  creating process's umask. That matches umask 002; confirm the deploy user,
  PHP-FPM and the queue workers use 002 before apply, because with a stricter
  umask new files there would gain group bits. Undo removes the default entry; a `-wal` created
  while it was present keeps a harmless entry for the removed account until
  SQLite next recreates the file.
- Installs the five base units **disabled** and runs `daemon-reload`.
- Runs `enter_r2_config.py --role upload --prefix daily` as `repro-backup` for the
  no-echo prompts, unless `--skip-credentials`.
- Self-tests and prints PASS, FAIL or TODO per line: every file's bytes, owner
  and mode; the account; `systemd-analyze verify`; timers not enabled; the
  producer's reads (vendor, `.env`, cached config if present, the WAL database)
  as `repro-backup` inside the unit's sandbox via `systemd-run`; that the account
  cannot write the database folder; and whether credentials were entered
  (`rclone-daily.conf`; for `alerts`, `ping-urls.json` as `repro-alert`). If the
  no-echo entry fails, or its file is not there after an entry in this run,
  that line is FAIL and apply exits 1; enter the values with the printed
  command and run apply again. With `--skip-credentials` the line is TODO and
  apply exits 0.

Apply is idempotent and never overwrites a file it did not install or one that
changed after install. Re-running it repairs a lost ACL. It refuses to add a
grant where an ACL mask was narrowed on purpose, because widening that mask
would give the owning group its rights back; review such a path by hand. Undo
disables the units, restores the exact previous ACL bytes (and, where there was
no ACL before, the group bits of the mode), removes only unchanged installed
files, removes created folders (including the probe's state folder) and
accounts, and reloads systemd. The record lives in
`/var/lib/repro-backup-installer/`. Apply checks that every folder on the way
to every source file in the bundle is root-owned and not group- or
other-writable.

Enable timers only after review:
`systemctl enable --now repro-backup.timer repro-backup-watchdog.timer`, then the
alert timers. Install `alerts` right after `base-backup`; until then the backup
units' bridge targets do not exist and only the journal marker is written.

The installer does not show APP_KEY. Copy the `APP_KEY=` line from `.env` into
the password manager yourself (checklist E.3), on the server, not through a session.

## Alerts (B6, B7)

One bridge account, `repro-alert`, is the only one with IP networking. It holds
the ping URLs in `/var/lib/repro-alert/ping-urls.json` (0600), entered with
`alert_bridge.py enter-urls`. Output, errors and the journal never contain a URL.
`forward` sends every condition's ping at once (up to eight threads), so one
slow or unreachable endpoint cannot use up the unit's 45 s limit before the
later conditions are tried.

| Condition | Class | Sent by | How to induce it for a round |
| --- | --- | --- | --- |
| server_silent | wake | ok ping every minute (`repro-alert-heartbeat.timer`); provider alerts after 5 min of silence | stop the heartbeat timer |
| backup_stale | daytime | watchdog result (OnSuccess / OnFailure) | point the plan at a stale receipt on a test host |
| backup_failed (extra) | daytime | nightly backup and kit result | run the backup with a missing rclone config |
| database_down | wake | probe | rename a fixture database / stop the rehearsal MySQL |
| wrong_database_engine | wake | probe (cached config's default driver, else `.env` DB_CONNECTION, vs plan) | set the probe plan's expected engine to the other one |
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

Provider setup: one check per row, period and grace from `alert-conditions.json`;
for wake-me checks a 15-minute repeat and the second contact. The default probe
plan enables only the six conditions that work on SQLite today. MySQL conditions
need the `mysql` monitor section pointed at the rehearsal instance (owner
decision on round 1, below); the money check needs reviewed queries and a
baseline from `alert_probe.py money-digest`. The MySQL monitor account needs
`PROCESS` (MySQL 8.4.13 refuses `INNODB_METRICS` without it, checked in review)
and `SELECT` on the app's `jobs` table and the money query tables. Without
`PROCESS`, `database_contention` fails every minute.

`backup_lag` measures the recovery point, not the archive's last run: the
checkpoint's `next_file` says every earlier log is published, and the newest
of those was last written by MySQL when it rotated away from it. After an
outage each catch-up batch is cut to the oldest closed logs and rewrites the
checkpoint at once, so the checkpoint's own write time would report `ok` while
recovery is still hours behind. The probe plan needs `binlog_checkpoint_file`
and `binlog_dir` (the folder of the source's binary logs, which `repro-backup`
can already traverse for the archive).

`wrong_database_engine` reads the cached config first, because `Deploy-App`
runs `php artisan config:cache` and Laravel then ignores `.env`: it takes the
default connection's driver from `bootstrap/cache/config.php` (a `DB_URL`
scheme wins, as in Laravel), as `backup-database.php` does, by parsing the
`var_export` text without running PHP and keeping only the driver name. With
no cached config it reads `DB_CONNECTION` in `.env`. It still does not see a
long-lived worker on the old engine. The plan's per-minute engine and server
ID record, written by a scheduled command, is the real check; until it exists
this detector is a config check only.

A probe that cannot read `.env` (or a cached config it cannot read or parse)
reports `wrong_database_engine` as failed. That is a wake alert, so a deploy
that replaces `.env` by rename (see limits below) pages within minutes, not
only at the next backup.

## Timers and the monthly reboot

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
monthly 02:00 reboot on the 28th whatever that reboot's time zone is (UTC, US or
IST). The 26-hour freshness limit still covers 5 minutes of jitter and a
30-minute run. Minute timers never catch up: a missed minute is what the
provider should see. A reboot longer than the 5-minute silence grace will page;
measure the reboot once and raise the grace only if it is needed and still fits
the 10-minute delivery limit.

Cutover night: 03:30 UTC is 10:30 pm US Eastern in winter (EST), inside the
planned Saturday 10 to 11 pm Eastern outage. 02:30 UTC (9:30 pm EST) would also
run into it. The cutover and drill runbooks must hold `repro-backup.timer` and
`repro-recovery-kit.timer` (and list them as "nothing due") or the owner must
pick another time.

## MySQL leg (B8)

Units: `repro-mysql-heartbeat`, `repro-binlog-rotate` (OnSuccess and OnFailure
start the archive), `repro-binlog-archive` (also on its own catch-up timer) and
`repro-binlog-purge`. Rotation and heartbeat run as `repro-binlog` with no
network and no data access. Archive and purge run as `repro-backup`.

`purge_archived_binlogs.py` runs `PURGE BINARY LOGS TO` only up to the first file
that is not yet archived (from the archive checkpoint), is younger than the local
retention (default 3 days), is among the newest files kept locally, or is the
active file. With fewer closed files than `keep_files`, it keeps them all. It
checks the purge took effect. Grant its MySQL account
`BINLOG_ADMIN` and `REPLICATION CLIENT` only.

`install_backup_host.py --job mysql-recovery` is preview only. Before it can
apply, these are needed: the owner's decision on B1 e; MySQL accounts and the
`repro_archive_control` procedure (`heartbeat.md`, `rotate_closed_binlogs.md`)
plus the purge account; a `repro-binlog-plan` group for the shared collector
plan; traverse on `/run/repro-mysql84-native` for `repro-backup` and
`repro-binlog` (today the native unit grants it to www-data only); read on the
binlog files for `repro-backup` (binlogs live in the data folder, so a separate
binlog folder would be cleaner); a `binlog` prefix config; and reviewed plan
pins rendered into the four `REVIEWED_*_SHA256` placeholders.

## Recovery kit (B8)

`produce_recovery_kit.py` copies `.env` (with APP_KEY) and any listed MySQL
files into a private batch with a `kit.json` hash list, and publishes it under
the application scope when the files change, and again once the last
publication is seven days old. The kit lives under `daily/`, which the bucket
rule deletes after 31 days; publishing only on change would leave no kit after
a quiet month. It refuses an `.env` without a
usable APP_KEY. It prints names, sizes and hashes only. On the recovery
machine, after `restore_backup.py --scope application`:

```sh
php scripts/mysql/offsite/verify_recovery_kit.php RESTORE_OUTPUT CIPHERTEXT_FILE EXPECTED_PLAINTEXT_SHA256
```

It checks the kit's hashes and decrypts one stored Laravel value (CBC or GCM)
with the recovered key, printing only the plaintext hash.

## Seven-day observer and drill runner (B9)

Run on the recovery machine (Linux, WSL or a container) with the read-only key:

```sh
python3 -I -B observe_backup_health.py observe --plan observer.json      # every minute
python3 -I -B observe_backup_health.py export --plan observer.json --window-end END \
  --identity-sha256 H --destination-sha256 H --monitor-profile-sha256 H --output backup-health.jsonl
python -B -m scripts.cutover.readiness.summarize_backup_health --policy review-policy.json --observations backup-health.jsonl
```

Use `interval_s` 60. The summarizer credits a slot only while lag stays within
300 s, using the value seen at the slot start, so a 300 s interval would demand
zero lag. Recoverable-through is the time of the Rotate event that closed the
last log of the newest contiguous binlog batch from the newest base. The
observer reads that one log (checked against the size and digest the batch
manifest lists) and walks its events to the terminal rotation, which must name
the next file and pass its CRC; MySQL 8.4.13 stamps that event with the
rotation time (checked natively). Every transaction committed before it is in
the chain. It is never the batch's seal time: after an archive outage the
collector's file and byte limits can cut a catch-up batch to old logs sealed
now. A last log over 64 MiB, or one that does not end in its rotation, leaves
the previous value. A base or batch counts for a slot only if its completion
`verified_at` is at or before the slot start (the marker is uploaded seconds
after that stamp). SQLite alone has no binlog chain, so the lag rule cannot
pass before the MySQL leg runs.

A publication folder without `completion.json` (still uploading, or abandoned)
is skipped and read again on the next run. A run whose batch was published but
whose checkpoint did not advance (time limit, failed final read-back) is retried
from the same checkpoint, so the retry batch repeats those logs; the chain
follows whichever ready batch holds the next log and reaches furthest, in
either listing order. A crash between writing a slot's
sample and its journal row, or in the middle of the row, no longer costs the
slot: the retry replaces the orphan sample and drops the cut row. A lock on
the journal keeps two runs from writing one slot. Every other failed run
(network, rclone timeout, the machine asleep) still leaves its minute without a
row if nothing retries it, and `export` then refuses the whole window. So run
`observe` every 20 s (for example a systemd timer at `*:*:05,25,45`): a run for
a slot already recorded returns at once without touching the bucket, and a
retry later in the slot still observes as of the slot start. Run it on a
machine that does not sleep, and check the journal daily.

```sh
python3 -I -B run_pitr_drill.py --plan drill-a.json --output drill-a
```

The drill plan names the base and binlog publications (IDs, sizes, digests),
the stop coordinate (end of the chosen commit), the target UTC second, a check
for the chosen row and, for drill 2, a check that the planted row is absent.
`backup-database.php` dumps one database without `--databases`, so the dump has
no `CREATE DATABASE` or `USE`. The runner creates the plan's `database` on the
fresh target (refused if it already exists) and loads into it; MySQL 8.4.13
refused the dump with ERROR 1046 before this. The plan's `timeout_seconds`
bounds the whole load, streaming included: a target that stops reading is
killed at the deadline and the drill records `load_base` as failed instead of
hanging with no `replay.json`.

## Risks fixed and limits found

- **Separate keys.** The config tool has upload and restore roles and refuses to
  write the restore key into the server's backup state. The checklist above
  replaces the shared read-write token.
- **Sandbox reads the WAL database and `.env`.** Proven under a real systemd 252
  manager: with another user holding WAL frames open, the real producer as
  `repro-backup` under `ProtectSystem=strict` copied all 45 committed rows.
- **Limit: no open connection.** If every app connection has closed and SQLite
  removed `-wal` and `-shm`, the read-only sandbox cannot create them and the
  backup fails with exit 1 (no partial copy, `backup_failed` alerts). Maverick's
  long-lived queue workers normally keep the WAL open. Options: accept a rare
  loud failure; add an app-side keeper process that holds one idle connection;
  or schedule around worker restarts. The probe had the same limit (its
  read-only open failed, so `database_down`, a wake alert, paged during a
  deploy that stopped the workers). It is fixed in the probe: when the normal
  read-only open fails, the probe reads the file alone with `immutable=1`
  (still read-only, no locks, no `-shm`) and keeps the answer only if no `-wal`
  or `-journal` existed before or after the read and the file's inode, size and
  times did not change during it; otherwise it retries and fails after three
  attempts. A writer that opens meanwhile writes to a new `-wal` first, and a
  checkpoint that rewrites the file changes its times, so a torn read is not
  kept. This needs SQLite 3.22 or later for immutable WAL reads (the QA image
  has 3.40.1). An empty `-wal` left by a persistent-WAL setting would still
  fail; Laravel does not set it. The backup's limit is unchanged.
- **Limit: `.env` replaced by rename.** `Deploy-App.ps1` rewrites `.env` by rename
  when it stores a new Stripe webhook secret. That drops the ACL; the next
  backup fails loudly, the kit fails, and the probe reports
  `wrong_database_engine` (a wake alert) within minutes. Re-run the base-backup
  apply to repair.
- **Archive-aware purge** replaces unbounded binlog growth.
- **Persistent timers and the monthly 02:00 reboot on the 28th** are handled in "Timers and the monthly reboot".
- **Probe plan placement.** The real manager showed the probe could not read a
  plan under `/etc/repro-alert`; it lives in `/etc/repro-backup`.

## Evidence

Commands (Windows Python 3.12 unless noted; Linux runs use the pinned QA image
`repro-mysql-rehearsal-qa:20261122`, `--network none`, as UID 1000 on a copy of
`scripts/`):

| Command | Result |
| --- | --- |
| `python -B -m unittest test_alert_bridge test_alert_probe test_purge_archived_binlogs test_enter_r2_config test_install_backup_host test_run_pitr_drill test_observe_backup_health test_produce_recovery_kit` (in `scripts/mysql/offsite`, Windows) | 78 tests OK, 10 skipped (Linux-only) |
| `python3 -B -m unittest discover -s scripts/mysql/offsite -p 'test_*.py'` (Linux, UID 1000) | 164 tests OK (86 before this lane) |
| `python3 -B -m unittest test_rotation_attempt` (Linux, `systemd/binlog-fixture`) | 6 tests OK |
| `python -I -B systemd/test_units.py`; `python -B -m unittest test_production_units` | 8 and 12 tests OK |
| `python -B -m unittest discover -s scripts/cutover/readiness -p 'test_*.py'` | 59 tests OK (unchanged) |
| `pwsh -File scripts/mysql/offsite/systemd/run-fixture.ps1` | passed: 31 units verified by systemd 252, cleanup verified |
| `python -B scripts/mysql/offsite/systemd/manager-fixture/run.py` | passed: 22 of 22 checks, container removed |
| `python -B scripts/mysql/offsite/native-fixture/run.py --mysqlbinlog-vendor DIR` | passed: 4 of 4 checks on MySQL 8.4.13, containers and volumes removed |
| `vendor/bin/phpunit tests/Unit/RecoveryKitVerifierTest.php` (SQLite profile) | 4 tests, 14 assertions OK |
| MySQL 8.4.13 profile (`-c phpunit.mysql.xml`): `tests/MySql/DatabaseBackupTest.php tests/MySql/PointInTimeBackupTest.php tests/Feature/DeploymentDatabaseBackupTest.php` | 7 tests, 97 assertions OK |
| MySQL 8.4.13 profile: `tests/Unit/RecoveryKitVerifierTest.php` | 4 tests, 14 assertions OK |
| Full SQLite suite, `php -d memory_limit=1792M vendor/bin/phpunit` (container-local copy, no `.env`) | 9,593 tests, 320,770 assertions; 2 errors and 2 failures, all four also failing on base `a6e799bb` in the same setup |

The four pre-existing failures need a non-root PHP or a different filesystem
in this container setup: `ListingStudioPaidStorageTest` and
`ListingStudioPrivateStorageTest` (`test_fpm_upload_and_separate_worker_can_share_private_files`),
`WorkspacePhotoLogoPermissionsTest::test_php_can_upload_a_logo_when_worker_outputs_are_not_group_writable`
and `IguideLabImportTest::test_concurrent_retry_cannot_enter_publication_window_or_delete_canonical_chunk`.
The first full run, straight over the Windows bind mount with the default
512 MB limit, was too slow under shared load and an image test exhausted the
limit in a later copy run; CI uses `memory_limit=2G` and shards.

The MySQL profile ran from a container-local copy of the tree with its own
`APP_ENV=testing` `.env` (as CI writes one); the worktree has no `.env` and the
producer requires one. The native check needs the pinned `mysqlbinlog` folder
because the MySQL server image ships without it.

The native fixture (`native-fixture/`) checks on MySQL 8.4.13: the drill
runner's real load, `mysqlbinlog --verify-binlog-checksum` replay across two
files and queries recover rows 1 to 3 and exclude a planted update and insert
committed after the chosen marker; the target is a separate instance; a purge
account without `BINLOG_ADMIN` is refused; the purge with `BINLOG_ADMIN` stops
at the archive checkpoint and removes only archived files. Retrieval through
rclone, the chain verifier and `heartbeat.py verify` were not part of this run.

The real-manager fixture (`systemd/manager-fixture/`) checks, as root inside a
disposable systemd 252 container with no network and no published ports:
preview as the deploy user changes nothing; preview as `nobody` reports hidden
folders; base and alerts apply with all self-tests passing; the producer in the
unit sandbox copies committed WAL rows; watchdog failure reaches a loopback
HTTPS provider as a fail ping through OnFailure, and the journal marker is still
written; the server-alive ping and six relayed detector statuses arrive; no ping
URL appears in the journal or the inbox; only the bridge units have
`PrivateNetwork=no`, and a private-network unit cannot reach the loopback
provider; undo restores the ACL bytes and leaves no files or accounts.

## Independent review (25 Nov)

A second session reviewed the branch from `a6e799bb` to `f6fac8c3`, re-ran the
tests and fixed these defects test-first, each as its own commit:

| Defect | Effect before the fix | Fix |
| --- | --- | --- |
| Observer read the marker of every listed folder | Every base upload and binlog batch is listed before `completion.json` exists, so runs failed and minutes went missing; an abandoned folder broke every later run. The seven-day window could not pass. | Folders without a marker are skipped and read again next run. |
| Kit published only on change, under `daily/` | The bucket deletes `daily/` after 31 days, so a quiet month left no kit. | Republish when the last publication is seven days old. |
| `cache_write_failed` used `os.walk` without an error hook | A missing or unreadable cache folder reported ok. | Any folder it cannot list is a failure. |
| Undo removed an access ACL with `removexattr` | The kernel keeps the group bits at the mask, so a 0600 file came back 0640. Checked on the real kernel. | Undo writes only the owner, group and other entries. |
| Grant ORed its bits into an existing mask | A mask narrowed on purpose was reopened for the owning group. | Apply refuses and asks for a review by hand. |
| Bundle check skipped `systemd/` and `systemd/binlog-fixture/` | Another account with write there could swap units or the rotation wrapper. | Every folder above every source is checked. |
| Purge kept `closed[len - keep_files:]` | With fewer closed files than `keep_files`, the slice went negative and older files could go. | Start index clamped at 0. |
| Bridge did not catch `http.client.HTTPException` | A non-HTTP answer crashed the bridge and stopped the relay of later conditions. | Reported as a failed ping; the relay goes on. |
| Probe state folder not recorded | Undo left `/var/lib/repro-alert-probe` owned by a deleted uid. | The alerts job creates and removes it. |

Review re-runs at the review head (Linux runs in the pinned QA image with
`--network none`; uid 1000 runs need a private `HOME`, for example
`-e HOME=/work/home` on a 0700 folder, because the older offsite tests create
their fixtures under `HOME` and the image's default `HOME=/` is not writable):

| Command | Result |
| --- | --- |
| Windows: the eight lane test modules in `scripts/mysql/offsite` | 88 tests OK, 14 skipped (Linux or root only) |
| Linux uid 1000: `discover -s scripts/mysql/offsite -p 'test_*.py'` | 174 tests OK, 1 skipped (root only) |
| Linux root: `test_install_backup_host` | 18 tests OK, 1 skipped (non-root only) |
| `test_rotation_attempt`; `systemd/test_units.py`; `test_production_units`; readiness discover | 6, 8, 12 and 59 tests OK |
| `run-fixture.ps1` | passed: 31 units verified, cleanup verified |
| `manager-fixture/run.py` | passed: 23 of 23 checks (new: every watched path keeps its mode), container removed |
| `native-fixture/run.py` on MySQL 8.4.13 | passed: 4 of 4 checks, cleanup verified |
| MySQL 8.4.13 profile: the three backup tests and `RecoveryKitVerifierTest.php` | 11 tests, 111 assertions OK |
| Full SQLite suite at `f6fac8c3` (container-local copy, root, `memory_limit=1792M`; the review changed no PHP) | 9,593 tests, 320,624 assertions, 3 skipped; the same 2 errors and 2 failures as above, and all four fail on base `a6e799bb` in the same setup (24 tests, 2 errors, 2 failures) |
| `vendor/bin/pint --test` on the two lane PHP files | pass |

Notes the review did not change (see the sections above): with no app
connection open, the probe reports `database_down` and pages; the observer still
loses a minute on any other failed run; `wrong_database_engine` is a config
check only; the monitor account needs `PROCESS`; the backup time sits next to
the cutover outage; `queue_stale` ignores reserved jobs whose worker died;
`server_error_rate` sees only the current access log, so it undercounts for 30
minutes after log rotation; credential or URL entry failure still lets apply
exit 0 (it prints FAIL, and the base self-test prints TODO). The Codex round
below fixed most of these.

## Codex review round (25 Nov)

Codex reviewed `fd4a34da`; the findings were re-checked at `48f6145b` and each
real defect was fixed test-first as its own commit. Nothing here touched
production or a provider.

| Finding | Outcome |
| --- | --- |
| offsite-backups#1 (P1) drill load had no database selected | Fixed `7b4cf317`: runner creates and selects the plan database; ERROR 1046 reproduced and gone on MySQL 8.4.13; native fixture now dumps like the producer. |
| offsite-backups#2 (P1) unchanged kit expires | Already fixed `309eaf1d` (`produce_recovery_kit.py` republishes after 7 days; `test_an_unchanged_kit_is_republished_before_the_bucket_expires_it`). |
| offsite-backups#3 (P1) prefixes without a marker | Already fixed `fcab9308` (`refresh` skips them; `test_a_publication_without_its_completion_marker_is_skipped_until_the_marker_lands`). |
| offsite-backups#4 (P1) coverage from seal time | Fixed `5b101f49`: recovery reaches the closing Rotate event's time of the newest batch's last log, read and verified once. |
| offsite-backups#5 (P2) undo left 0640 | Already fixed `59c99926` (`restore_acl`; `RealAclUndoTests` on the real kernel). |
| offsite-backups#6 (P2) negative purge slice | Already fixed `7217c8bb` (`max(0, ...)`; `test_fewer_closed_files_than_the_minimum_count_are_all_kept`). |
| offsite-backups#7 (P2) slot credit before publication | Fixed `19d34e02`: bases and batches count only if `verified_at` is at or before the slot start. |
| offsite-backups#8 (P2) engine from `.env` only | Fixed `4ef789e4`: cached config wins, as in Laravel; long-lived workers on the old engine remain a documented limit. |
| offsite-backups#9 (P2) cache root not checked | Missing or unreadable root already fixed `9b31fc1b`; the root's own group-write bit fixed `ca4fad98`. |
| offsite-backups#10 (P2) forward budget | Fixed `440112dd`: pings go out together, each run tries every condition within about one timeout. |
| reviewer#1 probe pages with no WAL connection | Fixed `56b57866`: guarded `immutable=1` read (`58d43b41` keeps one normal open when a writer is present); the backup's own limit stays owner decision 5. |
| reviewer#2 observer loses slots | Crash between sample and row, and a cut row, fixed `67c842fe`; other failed runs documented with a 20 s retry schedule. |
| reviewer#3 engine check misses cached config | Fixed with #8 (`4ef789e4`); the per-minute engine record is still to come. |
| reviewer#4 backup timer in the cutover outage | Owner decision 3 (unchanged). |
| reviewer#5 PROCESS, reserved jobs, rotated log | Rotated log fixed `d5d0d2df`; dead-worker reservations fixed `cf595598`; the `PROCESS` grant is a documented setup step. |
| reviewer#6 apply exits 0 / no ping URL check | Fixed `78162406`. |
| reviewer#7 default ACL and umask | Documented (installer runbook): the default ACL mirrors the folder mode, so it matches umask 002; confirm the app's umask is 002 before apply. |

Re-runs at the round head (Linux runs in the pinned QA image, `--network none`,
uid 1000 with a private `HOME` on a tmpfs, unless noted):

| Command | Result |
| --- | --- |
| Windows: the eight lane test modules in `scripts/mysql/offsite` | 109 tests OK, 20 skipped (Linux or root only) |
| Linux uid 1000: `discover -s scripts/mysql/offsite -p 'test_*.py'` | 195 tests OK, 2 skipped |
| Linux root: `test_install_backup_host` | 21 tests OK, 1 skipped (non-root only) |
| `test_rotation_attempt` (Linux); `systemd/test_units.py`; `test_production_units`; readiness discover | 6, 8, 12 and 59 tests OK |
| `manager-fixture/run.py` (real systemd 252, at `cf595598`; `58d43b41` changes only a failure path) | passed: 23 of 23 checks, container removed |
| MySQL 8.4.13, two containers (server plus QA image, sockets on a tmpfs volume, no network; scratch scripts outside the repo) | drill `load_base` on a dump made with the producer's mysqldump argv: refused before the fix (the bare client says ERROR 1046), loads rows after; `rotated_at` on a real closed binlog equals the FLUSH second and the chain verifier accepts the same file; `queue_stale` through the real client: ok, ok, fail, fail for empty, reserved within 7,260 s, reserved 601 s past it, waiting 601 s |

Not re-run in this round: `native-fixture/run.py` needs three containers at
once and this round was limited to two (its dump argv changed to the
producer's; the load it exercises was checked above); the PHP suites (no PHP
changed); `run-fixture.ps1` (no unit file changed).

## Owner decisions

1. Upload key type: derived upload-only credential with renewal (default) or a
   bucket-scoped read-and-write key that can delete after the lock period.
2. Whether the 7-day window and alert round 1 run on the native rehearsal
   instance (B1 e). The `before_installer` round needs MySQL-only cases; the
   native service is already installed.
3. The monthly reboot: confirm its source and time zone. The backup now runs at
   03:30 UTC; keep it, or move it back once the reboot is known. Either time
   falls in or next to the Saturday 10 to 11 pm Eastern cutover outage, so the
   cutover and drill runbooks hold the backup and kit timers that night.
4. Alert provider and phone channel (B4), and whether `alert_fail_suffix` stays `/fail`.
5. Whether to add an app-side WAL keeper, or accept a rare loud backup failure
   when no app connection is open. (The probe no longer pages in that case.)
6. The SQL and baseline for `historical_money_totals_changed`.

## Folding into the shared docs

- `docs/mysql-production-readiness.md`: "What this lane adds", "Risks fixed and
  limits found" and "Evidence" go under off-site recovery and alerts, replacing
  the statements that there is no installer, no alert bridge, no heartbeat
  timer and no observer.
- `docs/mysql-production-cutover.md`: the owner checklist, installer runbook,
  alerts table, timers table, MySQL leg prerequisites, recovery kit and
  observer/drill commands go under the backup and alert steps.
