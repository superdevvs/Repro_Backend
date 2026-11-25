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
  creating process's umask. Undo removes the default entry; a `-wal` created
  while it was present keeps a harmless entry for the removed account until
  SQLite next recreates the file.
- Installs the five base units **disabled** and runs `daemon-reload`.
- Runs `enter_r2_config.py --role upload --prefix daily` as `repro-backup` for the
  no-echo prompts, unless `--skip-credentials`.
- Self-tests and prints PASS, FAIL or TODO per line: every file's bytes, owner
  and mode; the account; `systemd-analyze verify`; timers not enabled; the
  producer's reads (vendor, `.env`, cached config if present, the WAL database)
  as `repro-backup` inside the unit's sandbox via `systemd-run`; that the account
  cannot write the database folder; and whether credentials were entered.

Apply is idempotent and never overwrites a file it did not install or one that
changed after install. Re-running it repairs a lost ACL. Undo disables the
units, restores the exact previous ACL bytes, removes only unchanged installed
files, removes created folders and accounts, and reloads systemd. The record
lives in `/var/lib/repro-backup-installer/`.

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

| Condition | Class | Sent by | How to induce it for a round |
| --- | --- | --- | --- |
| server_silent | wake | ok ping every minute (`repro-alert-heartbeat.timer`); provider alerts after 5 min of silence | stop the heartbeat timer |
| backup_stale | daytime | watchdog result (OnSuccess / OnFailure) | point the plan at a stale receipt on a test host |
| backup_failed (extra) | daytime | nightly backup and kit result | run the backup with a missing rclone config |
| database_down | wake | probe | rename a fixture database / stop the rehearsal MySQL |
| wrong_database_engine | wake | probe (`.env` DB_CONNECTION vs plan) | set the probe plan's expected engine to the other one |
| site_unreachable | wake | provider's website check | provider-side check against a closed URL |
| disk_nearly_full | wake | probe (10% or 20 GiB free; mounts checked) | probe a fixture path or an unmounted path |
| backup_lag | wake | probe (archive checkpoint older than 300 s, two checks) | pause the archive timer |
| server_error_rate | wake | probe (5xx over 2% in 30 min, at least 50 requests) | fixture access log |
| historical_money_totals_changed | wake | probe (reviewed SELECTs vs baseline digest) | change a row on the rehearsal copy |
| rehearsal_failed | daytime | probe (newest nightly receipt red or older than 36 h) | fixture receipt |
| restore_overdue | daytime | probe (newest restore-test receipt older than 8 days) | fixture receipt |
| database_contention | daytime | probe (InnoDB deadlocks plus lock timeouts over 5 an hour) | two sessions deadlocking on the rehearsal MySQL |
| mysql_restarted | daytime | probe (uptime under 15 min) | restart the rehearsal MySQL |
| queue_stale | daytime | probe (oldest waiting job over 10 min) | insert a delayed job on a copy |
| cache_write_failed | daytime | probe (cache folder not group-writable, or a cache write error in the app log) | `chmod g-w` a fixture cache folder |

Provider setup: one check per row, period and grace from `alert-conditions.json`;
for wake-me checks a 15-minute repeat and the second contact. The default probe
plan enables only the six conditions that work on SQLite today. MySQL conditions
need the `mysql` monitor section pointed at the rehearsal instance (owner
decision on round 1, below); the money check needs reviewed queries and a
baseline from `alert_probe.py money-digest`.

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

## MySQL leg (B8)

Units: `repro-mysql-heartbeat`, `repro-binlog-rotate` (OnSuccess and OnFailure
start the archive), `repro-binlog-archive` (also on its own catch-up timer) and
`repro-binlog-purge`. Rotation and heartbeat run as `repro-binlog` with no
network and no data access. Archive and purge run as `repro-backup`.

`purge_archived_binlogs.py` runs `PURGE BINARY LOGS TO` only up to the first file
that is not yet archived (from the archive checkpoint), is younger than the local
retention (default 3 days), is among the newest files kept locally, or is the
active file. It checks the purge took effect. Grant its MySQL account
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
the application scope only when the files change. It refuses an `.env` without a
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
zero lag. Recoverable-through is the seal time of the last contiguous binlog
batch from the newest base; it can be up to one rotation newer than the last
transaction. SQLite alone has no binlog chain, so the lag rule cannot pass
before the MySQL leg runs.

```sh
python3 -I -B run_pitr_drill.py --plan drill-a.json --output drill-a
```

The drill plan names the base and binlog publications (IDs, sizes, digests),
the stop coordinate (end of the chosen commit), the target UTC second, a check
for the chosen row and, for drill 2, a check that the planted row is absent.

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
  or schedule around worker restarts.
- **Limit: `.env` replaced by rename.** `Deploy-App.ps1` rewrites `.env` by rename
  when it stores a new Stripe webhook secret. That drops the ACL; the next
  backup fails loudly. Re-run the base-backup apply to repair.
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

## Owner decisions

1. Upload key type: derived upload-only credential with renewal (default) or a
   bucket-scoped read-and-write key that can delete after the lock period.
2. Whether the 7-day window and alert round 1 run on the native rehearsal
   instance (B1 e). The `before_installer` round needs MySQL-only cases; the
   native service is already installed.
3. The monthly reboot: confirm its source and time zone. The backup now runs at
   03:30 UTC; keep it, or move it back once the reboot is known.
4. Alert provider and phone channel (B4), and whether `alert_fail_suffix` stays `/fail`.
5. Whether to add an app-side WAL keeper, or accept a rare loud backup failure
   when no app connection is open.
6. The SQL and baseline for `historical_money_totals_changed`.

## Folding into the shared docs

- `docs/mysql-production-readiness.md`: "What this lane adds", "Risks fixed and
  limits found" and "Evidence" go under off-site recovery and alerts, replacing
  the statements that there is no installer, no alert bridge, no heartbeat
  timer and no observer.
- `docs/mysql-production-cutover.md`: the owner checklist, installer runbook,
  alerts table, timers table, MySQL leg prerequisites, recovery kit and
  observer/drill commands go under the backup and alert steps.
