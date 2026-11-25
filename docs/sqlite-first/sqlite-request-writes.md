# SQLite-first: fewer request writes, guard rules, direct SQLite access

Operator note for branch `claude/sqlite-request-writes-20261125`. It is built to ship
to production while production is still on SQLite, through a normal approved deploy.
It needs no migration.

## What changes in production

| Change | Default after deploy | Turn back |
| --- | --- | --- |
| A token's "last used" time is saved at most once every 5 minutes, not on every request. | On | `SANCTUM_LAST_USED_WRITE_INTERVAL=0` |
| Request telemetry is written after the response is sent. Durations still stop when the response was ready. | On | `SYSTEM_OVERVIEW_RECORD_AFTER_RESPONSE=false` |
| Table and column checks on request paths come from a cache keyed by the release. | **Off** | `SCHEMA_CACHE_ENABLED=false` |
| `automation_runs` and their steps get an age limit, run by the daily `model:prune` at 03:45. | **Off** until an age is set | leave `AUTOMATION_RUNS_RETENTION_DAYS` empty |
| Cherry-pick of `2943bc40`: the fal.ai job no longer saves its outgoing request (which can hold the whole image) in `provider_payload`; AI job and automation run lists read fewer columns; scheduled iGUIDE recovery goes through the recovery throttle instead of queuing asset ingest again. | On | revert the commit |

What users may notice: "Last active" on the profile security page can lag by up to five
minutes. Nothing else should look different.

After any `.env` change, run `php artisan config:cache` (each deploy already does).

## Schema cache (off by default)

- Turn on with `SCHEMA_CACHE_ENABLED=true`. It uses the `file` cache store
  (`SCHEMA_CACHE_STORE`). If the cache cannot be read or written, the app asks the
  database, as it does today.
- "It exists" is kept for a day (`SCHEMA_CACHE_TTL`). "Missing" is kept for 60 seconds
  (`SCHEMA_CACHE_NEGATIVE_TTL`, 0 never keeps it).
- The key is a fingerprint of the migration file names, frozen by `config:cache`, so each
  release with a new migration starts fresh. `SCHEMA_CACHE_RELEASE` overrides it.
- With it on, a signed-in request asks the database no layout questions once the cache
  is warm (with it off, it sends eight such queries).

## Automation run age limit (off by default)

The owner picks the age. Then:

1. Check what would go: `php artisan model:prune --model="App\Models\AutomationRun" --pretend`.
2. Make sure a fresh backup exists.
3. Set `AUTOMATION_RUNS_RETENTION_DAYS=<days>` and run `php artisan config:cache`.

Only finished runs (completed, failed, cancelled) older than the age go, with their steps,
in chunks of 1,000, one short transaction each. Running and waiting runs, and runs with an
unfinished step, stay. Ages below 30 days are raised to 30, because old runs also stop a
scheduled message from being sent twice.

## Guard and engine-branch list

- `tests/Unit/DatabaseDialectGuardTest.php` fails if `app/` gains SQL that only SQLite
  understands, or a new engine branch.
- `tests/database-dialect-baseline.json` lists the 38 cases that exist today. It may only
  shrink. After removing one, regenerate it (this only drops entries that are gone; it
  never adds new SQL):
  `DIALECT_GUARD_WRITE_BASELINE=1 php vendor/bin/phpunit tests/Unit/DatabaseDialectGuardTest.php`
- `tests/database-engine-branches.json` lists all 23 engine branches, each `safe` or `to-fix`
  with a reason. 13 are still to fix before the MySQL cutover.
- `AGENTS.md` and `CLAUDE.md` give coding sessions the plan's rules.

## Code that opened the SQLite file directly

- `scripts/ops/summarize-failed-jobs.php` and `scripts/ops/prune-clamav-failed-jobs.php` now
  use the app's database connection. Run them from the app folder.
- `scripts/ops/correct-service-55-sqft-boundary.php`, and `--apply` on
  `shoots:classify-travel-fees` and `shoots:reconcile-partial-delivery`, refuse with a clear
  message when the live database is not SQLite. On SQLite they work as before.
- `media:storage-health` on MySQL checks the MySQL data folder
  (`MEDIA_HEALTH_DATABASE_DIRECTORY`, else the server's `@@datadir`) and fails if it cannot
  find it. On SQLite nothing changes.
- Not in this branch: `scripts/deploy/deployment-storage.py` still assumes `database.sqlite`
  (it belongs with the deploy-backup work, P0-3a).
