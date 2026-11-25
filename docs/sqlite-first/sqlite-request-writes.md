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
| Cherry-pick of `2943bc40`: the fal.ai job no longer saves its outgoing request (which can hold the whole image) in `provider_payload`; AI job and automation run lists read fewer columns; scheduled iGUIDE recovery goes through the recovery throttle instead of queuing asset ingest again (it still moves older, unassigned iGUIDE floorplans onto the booked iGUIDE service first, and refreshes cached file and shoot lists, as the ingest job did). | On | revert `51a227e1`, `ca618eb6`, `b7b79cc6`, then `cb51c0cd` |

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
- If a table or column goes away without a new release (for example a manual
  `migrate:rollback`), set `SCHEMA_CACHE_RELEASE` to a new value and run
  `php artisan config:cache`. Otherwise "it exists" can be served for up to a day.
  Clearing the whole cache store also works, but drops anything else kept there.
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
  understands, or a new engine branch. It reads each PHP string the way PHP does, so
  escaped quotes (`'DATE(\'now\')'`) do not hide SQL.
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
- They ask `App\Support\DatabaseDriver` which engine is live. It reads the config without
  connecting, and honours `DB_URL` the way Laravel does: a `mysql://` URL counts as MySQL
  even when `DB_CONNECTION` still says `sqlite`.
- `media:storage-health` on MySQL checks the MySQL data folder
  (`MEDIA_HEALTH_DATABASE_DIRECTORY`, else the server's `@@datadir`) and fails if it cannot
  find it. On SQLite nothing changes.
- Not in this branch: `scripts/deploy/deployment-storage.py` still assumes `database.sqlite`
  (it belongs with the deploy-backup work, P0-3a).

## Codex review round (25 Nov)

Codex reviewed `3684aa05`. All three findings still applied at `53e4f029`; each got a failing
test first.

- Cross-lane rename: `App\Support\DatabaseEngine` is now `App\Support\DatabaseDriver`
  (`c667fb40`), so this branch no longer clashes with the sqlite-observability branch.
- sqlite-request-writes#1 (DB_URL): fixed in `ed24e7bf`. `DatabaseDriver` reads the engine through Laravel's URL parser; new tests in `SqliteBackupCommandsEngineTest` and `OpsScriptsDatabaseDriverTest`.
- sqlite-request-writes#2 (iGUIDE adoption): fixed in `b7b79cc6`. Scheduled syncs move unscoped iGUIDE floorplans onto the iGUIDE line again; `IguideAutomaticSyncTest::test_scheduled_sync_still_assigns_legacy_unscoped_floorplans_to_the_iguide_service`.
- sqlite-request-writes#3 (escaped quotes): fixed in `b0faac70`. The guard decodes PHP strings; `DatabaseDialectGuardTest::test_scanner_reads_string_literals_the_way_php_does`. The baseline did not change.
- reviewer#1 (`deployment-storage.py`): documented. It belongs to the sqlite-backups-billing branch, which changes that file. That branch reads only the `driver` key, so a `mysql://` `DB_URL` would look like SQLite there too.
- reviewer#2 (schema cache off): owner decision. It stays off until `SCHEMA_CACHE_ENABLED=true`.
- reviewer#3 (two changes on by default): owner decision. They are the point of P0-6; each has an env switch (table above).
- reviewer#4 (no MySQL run): run locally on MySQL 8.4.13 (details below). Every test the lane added or changed passes there. One older test in `MediaAttributionBoundaryTest` fails only because the PR #46 dump lacks a base column. A MySQL CI shard still needs PR #46.
- reviewer#5 (not pushed): owner decision. Push and PR wait for approval.

Second round: Codex reviewed `9bff5eaa` and found nothing. A re-check of the first round:

- sqlite-request-writes#2 follow-up: fixed in `ca618eb6` and `51a227e1`. The scheduled sync moved the
  files but, unlike the ingest job, did not refresh cached file and shoot lists. The move writes
  no `updated_at`, so the file list could show the floorplans as unassigned for up to 30 seconds.
  Test: `IguideAutomaticSyncTest::test_scheduled_sync_refreshes_cached_media_after_assigning_legacy_floorplans`.
- reviewer#1: still holds. The sqlite-backups-billing branch changes `deployment-storage.py`.
  This branch does not.
- reviewer#4: corrected. The first list left out `MediaAttributionBoundaryTest`,
  `StudioExistingEndpointContractIntegrationTest` and `StudioQueueControllerTest`. They now run
  on MySQL; results are below.

MySQL 8.4.13 run (local, READ COMMITTED, `utf8mb4_0900_bin`): main's migrations do not run
on MySQL (`shoot_service` points at `shoots` before it exists), so the schema came from the
PR #46 MySQL baseline dump plus the two recovery-attempt migrations, reloaded before each
file. These passed: `IguideAutomaticSyncTest`, `SqliteBackupCommandsEngineTest`,
`OpsScriptsDatabaseDriverTest`, `DatabaseDialectGuardTest`, `AutomationRunPruningTest`,
`AutomationRunHistoryTest`, `PerRequestSchemaLookupTest`, `TokenLastUsedThrottleTest`,
`SystemOverviewTelemetryAfterResponseTest`, `ProviderFloorplanRecoveryTest`,
`ProviderFloorplanRecoveryBackoffTest`. `SchemaFactsTest` passed one test at a time (its
`Schema::create` commits on MySQL and ends the test transaction). `MediaStorageHealthTest`
passed with `MEDIA_HEALTH_DATABASE_DIRECTORY` set, because the server ran in another container.
With `DB_CONNECTION=sqlite` and a `mysql://` `DB_URL`, `DatabaseDriver` said `mysql`, as did
the live connection. Second round, same setup: `IguideAutomaticSyncTest` (18),
`StudioExistingEndpointContractIntegrationTest` (17), `StudioQueueControllerTest` (4),
`IguideFloorPlanDiscoveryTest` (35) and `ProviderFloorplanRecoveryTest` (11) passed.
`MediaAttributionBoundaryTest` passed 14 of 15, including the lane's fal test. The one error is in
an older test: the dump has no `services.financial_only` column (base migration `2026_11_05_190000`).
`InternalTestShootIsolationTest` passed 13 of 14. Its one error is missing
`shoot_delivery_notification_outbox` (base migration `2026_11_06_000002`). Neither test is touched
by this branch.

Left as they were (both also on base):

- Whole-shoot iGUIDE recovery counts only unscoped files. A shoot whose files already sit on
  the iGUIDE line gets a no-op ingest job, at most 10 times per asset list, with growing
  gaps. The scheduled sync before this branch queued a full ingest on every run.
- `media:storage-health` on SQLite reads the file path from `DB_DATABASE`, not from `DB_URL`.
