# SQLite observability: operator note

Phase 0 items 2a, 5, 11 and 13 of the SQLite to MySQL plan. Everything here is safe on SQLite.

## What changes when this is deployed

Only one thing changes by itself: **`/up` now reads the database.**

- Healthy: HTTP 200, `{"status":"ok","engine":"sqlite"}`.
- Not healthy: HTTP 503, `{"status":"fail","engine":"sqlite","reason":"..."}`. The reason is one of `database_unavailable`, `engine_mismatch` or `server_identity_mismatch`. No error text or path is shown.
- It reads one row from the `migrations` table, so a missing, empty or recreated database file fails.
- It still answers during maintenance mode, as before. It starts no session and writes nothing.
- The deploy script checks `/up` after every deploy. That check now fails if the database cannot be read.

Everything else stays off until its setting is turned on.

## Settings

All go in `/var/www/backend/.env`. Each production change needs the owner's approval. After a change, run `php artisan config:cache` and `php artisan queue:restart`, because workers keep the settings they started with.

| Setting | Default | What it does |
| --- | --- | --- |
| `DB_EXPECTED_ENGINE` | empty (off) | `sqlite` or `mysql`. On any other engine, `/up` answers 503 and every query on the default connection stops with `DatabaseEngineMismatch`, including code that takes the connection's PDO handle itself. Other named connections are not checked. |
| `REPRO_MYSQL_EXPECTED_SERVER_UUID` | empty (off) | MySQL only. `/up` also compares `@@server_uuid`. |
| `QUEUE_PRUNE_FAILED_ENABLED` | `false` | Runs `queue:prune-failed --hours=336` daily at 03:55. **The first run deletes every failed job older than 14 days.** Turn on only after the remaining `failed_jobs` rows are archived (item 2b). |
| `TRANSACTION_TIMING_ENABLED` | `false` | Turns on the slow-transaction log. |
| `TRANSACTION_TIMING_THRESHOLD_MS` | `250` | Transactions at or above this are logged. |
| `HEARTBEAT_PING_URL` | empty (off) | Turns on `ops:heartbeat` every minute. Treat the address as private. |
| `HEARTBEAT_FAIL_URL` | empty | Address for a failed check. Empty means the ping address plus `/fail`. |
| `HEARTBEAT_BACKUP_GLOB` | empty (check skipped) | Pattern for the backup files, for example `/mnt/16tb/repro/sqlite-nightly/*.sqlite`. |
| `HEARTBEAT_BACKUP_MAX_AGE_HOURS` | `26` | Newest backup must be younger than this, and not empty. |
| `HEARTBEAT_DISK_PATHS` | empty (the app's disk) | Comma-separated paths to watch, for example `/var/www/backend,/mnt/16tb`. |
| `HEARTBEAT_DISK_MIN_FREE_GB` | `20` | Minimum free space on each path. |
| `HEARTBEAT_DISK_MOUNTS` | empty (check skipped) | Comma-separated paths that must be mount points, for example `/mnt/16tb`. A path that is not mounted, or missing, fails the disk check: an empty mount folder would otherwise report the root disk's free space, and a backup written there would look fresh. A bind mount from the same disk does not count as mounted. |
| `HEARTBEAT_QUEUE_MAX_AGE_MINUTES` | `10` | The oldest waiting job must be younger than this. Delayed and running jobs do not count. A job whose worker died counts again from the moment its `retry_after` runs out (the value of the queue connection that serves that queue). |
| `HEARTBEAT_QUEUES` | empty (all queues) | Comma-separated queues to watch. Use it if a queue has no worker on purpose. |
| `HEARTBEAT_TIMEOUT_SECONDS` | `10` | Time limit for the ping. |

## Slow-transaction log (one week)

Turn on with `TRANSACTION_TIMING_ENABLED=true`. Records go to `storage/logs/transactions-YYYY-MM-DD.log`, one JSON line each, kept 14 days.

Each record has:

- `duration_ms`: from BEGIN to the start of COMMIT (or to the rollback).
- `first_write_ms`: from BEGIN to the first write.
- `writer_held_ms`: from the first write to COMMIT. On SQLite this is how long other writers had to wait.
- `queries`, `writes`, `connection`, `outcome` (`commit` or `rollback`).
- `origin`: the code line that opened the transaction.

No SQL text or values are logged. A failed log write never breaks the request or job; it leaves one line in the PHP error log.

Limits of the measurement (they do not change what the application does):

- A transaction whose COMMIT itself fails is not logged when it ran in `DB::transaction()`: Laravel fires no event for it. With `beginTransaction()` and `commit()`, the caller's `rollBack()` ends it, so it is logged as a `rollback` timed to the start of the failed COMMIT.
- A rollback's `duration_ms` also covers the ROLLBACK and any after-rollback hooks, because Laravel fires no event when a rollback starts.

**File permissions.** New day files are 0660 and are moved to the group of `storage/logs`. So a file the deploy user creates first stays writable by the web workers. Check with `ls -l storage/logs/transactions-*.log`: the group should be `www-data`. This works because the deploy user and `www-data` are both in the `www-data` group.

Summary after a week (read-only):

```sh
python3 - <<'PY'
import collections, glob, json
by_origin = collections.defaultdict(list)
for path in sorted(glob.glob('/var/www/backend/storage/logs/transactions-*.log')):
    for line in open(path):
        record = json.loads(line)['context']
        by_origin[record.get('origin', '?')].append(record)
for origin, rows in sorted(by_origin.items(), key=lambda item: -max(r['duration_ms'] for r in item[1])):
    print(f"{max(r['duration_ms'] for r in rows):9.1f} ms max {max(r.get('writer_held_ms', 0) for r in rows):9.1f} ms held {len(rows):6} times  {origin}")
PY
```

Every transaction over one second must then be fixed or signed off by name (plan pass mark "Long transactions").

## Heartbeat and alerts

`php artisan ops:heartbeat` checks four things and pings the alert service:

- `database`: the same check as `/up`.
- `backup`: age and size of the newest file matching `HEARTBEAT_BACKUP_GLOB`.
- `disk`: free space on each watched path, and that each path in `HEARTBEAT_DISK_MOUNTS` is mounted.
- `queue`: age of the oldest waiting job in the `jobs` table.

All ok: it POSTs a short text summary to the ping address. Any failure: it POSTs to the fail address. If the server is down, nothing is sent and the alert service raises the alarm after its grace time.

Before turning it on, run `php artisan ops:heartbeat --dry-run` as the user that runs `schedule:run`. Every line should say `ok` or `skipped`. That user must be able to read the backup folder. Which user runs the scheduler has not been checked yet (root's crontab needs sudo), so the owner confirms it first.

**Alert service setup (owner, in the provider's website, not code):**

1. One heartbeat check, every minute, alert after 5 minutes of silence. Its address goes in `HEARTBEAT_PING_URL`.
2. One website check on `https://api.reprodashboard.com/up`, every minute, alert on anything but HTTP 200. `/up` now tests the database, so no other address is needed.
3. Phone and one second contact on both checks.
4. Two reviewed alert rounds: each alert reaches the phone within 5 minutes, a silent server within 10 (plan pass mark "Alerts").

## Turning things off

Remove the setting (or set it back to its default), then `php artisan config:cache` and `php artisan queue:restart`. To restore the old `/up`, revert the commit "Make /up read the database and name its engine".

## Not in this change

- The nightly backup itself (item 1). The heartbeat only reads its age.
- An engine pin file (`storage/framework/db-engine.pin` in the plan). `DB_EXPECTED_ENGINE` is read when a process starts, so a long-running worker keeps its old setting until `queue:restart`. Whether to build the pin file is for the owner or the cutover lane to decide; until then the cutover steps must run `queue:restart` after changing the engine.

## Codex review round (25 Nov)

Codex reviewed 50329a84; findings checked again at eeaa0601.

- sqlite-observability#1 (queue check skips expired reservations): fixed in 19619dec. A reserved job waits again from `reserved_at` plus the `retry_after` of the connection that serves its queue. Tests: `test_a_job_whose_worker_died_waits_again_once_its_reservation_expires`, `test_a_reservation_expires_after_the_retry_after_of_the_connection_that_serves_its_queue`.
- sqlite-observability#2 (after-commit transaction hides the slow one): already fixed in df793b3f (per-connection stack). `test_a_transaction_opened_by_an_after_commit_hook_does_not_hide_the_slow_one` fails on the 50329a84 code (0 records) and passes now.
- sqlite-observability#3 (next day file not regrouped): not a defect. Monolog 3.10.0's StreamHandler calls `write()` again when the file's inode changes, so the new day file is opened inside a fresh `write()` that applies the group. `test_the_next_day_file_of_a_long_running_process_joins_the_log_directory_group` (9df28c22) pins it; it fails when files opened by a re-entered `write()` are ignored.
- reviewer#1 (no engine pin file): owner decision, stated under "Not in this change".
- reviewer#2 (unmounted mount reads as the root disk): fixed in e80cdc12 with `HEARTBEAT_DISK_MOUNTS` (empty, so off). Tests: `test_a_watched_mount_that_is_not_mounted_fails`, `test_a_mounted_watched_mount_passes`, `test_a_missing_watched_mount_fails`.
- reviewer#3 (expired reservations not counted): fixed in 19619dec, the same change as sqlite-observability#1.
- reviewer#4 (failed COMMIT and rollback timing): documented under "Limits of the measurement". Laravel fires no event for either, so they cannot be measured from events.
- reviewer#5 (`/up` is public and reads the database): not a defect. One `exists()` read on `migrations` per request (plus `@@server_uuid` on MySQL when that check is on), with no session and no write. A rate limit, if wanted, belongs in nginx.
- reviewer#6 (uid-33 fork tests fail locally): not a branch defect. The local read-only vendor volume causes it, identically on base 8bb478e6. CI runs as a non-root user and does not hit it.

Second round: Codex reviewed 8c05c699; the earlier dismissals were checked again.

- r2#1 (engine guard skipped by code that takes the PDO itself): fixed in d7513ff1. On the wrong engine the default connection's PDO handles now throw `DatabaseEngineMismatch`, so `ListingStudioTrialMonitoring` can no longer write its counters to the old database. Test: `test_code_that_takes_the_pdo_itself_cannot_write_on_the_wrong_engine`.
- sqlite-observability#2: still fixed. The after-commit test passes at the new head.
- sqlite-observability#3: still not a defect. Monolog 3.10.0's `StreamHandler::write()` calls `$this->write()` again after the inode changes, so the override sees a closed stream. The next-day test passes.
- reviewer#4: the note was partly wrong. A failed COMMIT inside `DB::transaction()` is not logged, but with `beginTransaction()`/`commit()` the caller's `rollBack()` logs it as a rollback. Corrected in 8b812807; `test_a_failed_commit_is_logged_only_when_the_caller_rolls_back` pins both paths.
- reviewer#5: still not a defect. `/up` gets no middleware group, and the global middleware writes nothing to the database for it, as before this change.
- reviewer#6: still not a branch defect. The shared vendor volume is mode 0700 root, so uid 33 cannot read `vendor/autoload.php` before any app code loads.
