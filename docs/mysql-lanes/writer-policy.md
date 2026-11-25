# Lane notes: MySQL writer policy (WP-1 to WP-9)

Branch `claude/writer-policy-20261125`, based on `a6e799bb` (draft PR 46 head).
Local commits only: nothing was pushed or deployed, and no production system
was touched. This page is written so an integrator can fold it into
`docs/mysql-production-readiness.md` (evidence) and
`docs/mysql-production-cutover.md` (runbook).

## What changed

The plan's one-writer-at-a-time rule now runs in the application, on the main
`mysql` connection, on by default for MySQL. It has no effect on SQLite.

- **Token at BEGIN.** `App\Database\MySqlConnection::executeBeginTransactionStatement()`
  takes `repro_mysql_control.writer_token` by raw PDO through
  `MySqlWriterToken`. A missing or mismatched row fails closed. BEGIN never
  reconnects.
- **Autocommit writes.** INSERT, UPDATE, DELETE, REPLACE, CALL and LOAD at
  level 0 run as BEGIN, token, statement, COMMIT (`App\Database\WriterStatement`
  classifies the SQL). DDL, SET and SELECT are never wrapped.
- **Refusals.** DDL or TRUNCATE inside a transaction; raw BEGIN, COMMIT,
  ROLLBACK, SAVEPOINT, LOCK TABLES on the writer connection; a row lock outside
  a transaction (error in tests, warning log elsewhere). One test-only
  exception: DDL when the only open transaction is a test's RefreshDatabase
  wrapper commits that wrapper, as MySQL always did, and the test carries on
  exactly as before the policy.
- **Retries.** A token wait is the only thing retried, and always in place:
  it happens before its BEGIN or statement does anything, so nothing is
  replayed. `transaction($callback, $attempts)` lets the wait at BEGIN retry up
  to `$attempts` times before the callback runs. `LockedWrite` runs its
  callback once, at its own depth, and lets every token wait inside retry up
  to four times. The rate limiter retries `WriterTokenTimeout`; the controller
  is outside the retry. Lock errors 1205 and 1213 after the token are never
  retried.
- **Poison guard.** After a lock error or a lost session inside a
  transaction, the connection refuses every statement until rollback reaches
  level 0. An unacknowledged COMMIT discards the session; no callback runs and
  nothing is replayed.
- **Session settings.** READ COMMITTED and five-second row and metadata lock
  waits on every application MySQL session (`config/database.php`,
  `App\Database\MySqlConnector`).
- **Exempt writers**, each limited to its own tables:
  - queue claim, finish and release on the same connection, `jobs` only
    (`App\Queue\MySqlDatabaseQueue`); enqueue still waits for the token;
  - database cache locks on `mysql_cache_locks` (same account, separate
    session), `cache_locks` only; cache values wait for the token;
  - the existing queue-worker and scheduling-cache pilots (off by default);
  - Listing Studio counters (unchanged raw session).
- **Workers.** After each job and in-process scheduled task, a transaction it
  left open is rolled back and logged (`App\Database\LeftoverTransactions`).
  The worker loop starts every job at depth 0.
- **Long holders.** Holds over one second are logged.
  `php artisan db:writer-token-holders --connection=<monitor> --older-than=N`
  lists holders from `performance_schema.data_locks` and exits 1 if any.
- **Deploy check.** `php artisan db:writer-policy-check` exits 1 unless only
  the default connection carries the token, every other MySQL connection has a
  table limit, a fresh session is READ COMMITTED with 5/5 lock waits, and the
  token can be taken. On SQLite it reports "not applicable" and exits 0.

Design choices that differ from the plan, and every behaviour change, are in
[`docs/mysql-known-differences.md`](../mysql-known-differences.md) for owner
sign-off.

## Flags

| Setting | Default | Effect |
| --- | --- | --- |
| `REPRO_MYSQL_WRITER_POLICY` | on for MySQL (only `false` turns it off) | The one-writer rule on `mysql`. No effect on SQLite. |
| `REPRO_MYSQL_WRITER_LONG_HOLD_MS` | `1000` | Log token holds longer than this. `0` turns the log off. |
| `REPRO_MYSQL_LEVEL0_LOCK_GUARD` | `throw` when `APP_ENV=testing`, else `log` | Row lock outside a transaction: `throw`, `log` or `off`. |
| `REPRO_DB_LEFTOVER_TRANSACTIONS` | `rollback` when `DB_CONNECTION=mysql`, else `log` | What a worker does with a transaction a job left open. On SQLite production it only logs. |
| `DB_CACHE_LOCK_CONNECTION` | `mysql_cache_locks` on MySQL, unset on SQLite | Session for database cache locks. |

Unchanged and still off by default: `REPRO_MYSQL_QUEUE_WORKER` (and the Studio
and iGUIDE Lab Tour variants), `REPRO_MYSQL_SCHEDULING_CACHE`,
`REPRO_MYSQL_ROUTE_BUDGET_WRITER_TOKEN` (now used only when the policy is off).

## Runbook additions (for docs/mysql-production-cutover.md)

Fold into "Release and target preparation" and "Validate before reopening
traffic":

1. Provision the token on every fresh, imported or restored MySQL target,
   before any application process connects. It is outside the application
   schema, so import, verify and reverse export do not see it.

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
2. The application sets READ COMMITTED and 5-second lock waits per session.
   The server file may keep its own defaults, but the migrator account should
   use a separate connection if layout changes need a longer metadata wait.
3. After switching `.env` to MySQL and before reopening, run as the
   application user: `php artisan db:writer-policy-check`. It must print
   `"problems":[]` and exit 0. Add it to every deploy once MySQL is live.
4. Give the monitor account `PROCESS` and `SELECT` on `performance_schema`
   and alert on `php artisan db:writer-token-holders --connection=<monitor>
   --older-than=30` exiting 1. Daytime alert; the plan's threshold list owns
   the number.
5. Every process must run the same release: a process without the policy
   would write without the token. The engine check in the drill covers this
   for workers.

## Evidence (for docs/mysql-production-readiness.md)

All runs were local, offline (`--network none`), on MySQL 8.4.13 (image
`sha256:a81d0439…`) and PHP 8.3.33 (`repro-mysql-rehearsal-qa:20261122`),
with synthetic data only. The local MySQL fixture used relaxed flushing
(`innodb_flush_log_at_trx_commit=2`, `sync_binlog=0`) for speed; CI keeps
the durable defaults.

- SQLite suite (`scripts/ci/run_phpunit_shard.py`, one shard, two workers,
  commit `dd87fe65`): 9,671 tests, 9,664 passed, 3 skipped (no ClamAV), 4 failed. The same 4 also
  fail on `a6e799bb` here (file permissions and a sub-process in this
  container): `ListingStudioPaidStorageTest` and `ListingStudioPrivateStorageTest`
  (FPM and worker sharing files), `WorkspacePhotoLogoPermissionsTest`, and
  `IguideLabImportTest::test_concurrent_retry_cannot_enter_publication_window_or_delete_canonical_chunk`.
  Files changed after that run were rerun on SQLite and pass.
- MySQL profile (`phpunit.mysql.xml`, two shards as in CI, policy on,
  commit `185ed030`): 7,543 tests, 7,513 passed, 18 skipped, 12 failed. Two
  were the iGUIDE Lab tour snapshot tests, fixed in `52da2771`; both files
  then passed on a fresh fixture (28 tests). The other ten fail the same way
  on `a6e799bb` in this fixture: `DatabaseBackupTest` (2) and
  `PointInTimeBackupTest` (3) stop at their configuration stage here,
  `MysqlCutoverSchemaTest` (2) refuses this fixture's relaxed flushing, and
  the two Listing Studio storage tests and the iGUIDE Lab import retry fail
  on file permissions in this container.
- Multi-process writer suite (`phpunit.mysql-writers.xml`), fresh fixture,
  commit `f89162e8`: 65 tests, 856 assertions, all passing. It covers every
  autocommit write form, the token at BEGIN, reads that never wait, refusals, missing token row, poison guard,
  lost session at BEGIN, inside a transaction and at COMMIT, retry limits,
  queue and cache exemptions, `LockedWrite` and rate-limiter retries, worker
  rollback, the watchdog, the deploy check, eight processes racing for the
  last Google Routes quota unit, eight first texts from a new number, six
  status webhooks for one message in random order, and twelve workers running
  1,000 jobs each exactly once (11 to 53 seconds in the local fixture,
  depending on host load).
- The same suite on the base code (`a6e799bb` plus only the new config and
  tests) failed 51 of 63 tests, as expected without the runtime policy. The
  12 that passed there do not depend on the token: reads never waiting, a
  write inside a transaction, DDL outside one, business code reusing the
  timeout type, a job rolled back with its transaction, the cache lock
  progressing, twelve workers each running once, and the Google Routes race
  (it also reserved the last unit once without the token in this fixture; the
  reason was not investigated). Afterwards two retry proofs were changed to
  write a row the holder never touches, and the table-limit proofs now check
  the refusal message, so each fails on the base code for the right reason.
- The status-webhook proof also fails on the base code: a stale "sent"
  event overwrote the delivery and the message ended SENT. With the token it
  ends DELIVERED.
- Business finding: eight concurrent first texts from one new number made one
  contact but **eight SMS threads**, with the token on. The inbound Telnyx
  handler looked for the thread before its transaction and never again
  inside it. Fixed in `TelnyxWebhookController::handleInbound`; the proof now
  gives one contact, one thread and eight messages, and the existing Telnyx
  feature tests pass on SQLite (78 tests).
- Existing contracts with the policy on, run in the order of the CI script,
  commit `f89162e8`: the primitive (23 tests), the Laravel fixture (22), the
  Google Routes pilot (9), the queue-worker pilot (9) and the scheduling-cache
  pilot (3), all passing.
- CI: shard 0 of `.github/workflows/mysql.yml` now runs every writer-token
  contract in full, then this suite against a fresh schema in the same
  socket-only fixture. The main profile's database gets the token row. Not
  yet run on GitHub: that needs a push, which this lane did not do. The suite
  waits out many five-second token timeouts, so it adds roughly 10 to 20
  minutes to shard 0 (local estimate). If shard 0 nears its 75-minute limit,
  move `scripts/mysql/test-queue-worker-ci.sh` into its own job.

## How to rerun locally

The image tags are the ones above. Every container runs with
`--network none`; MySQL listens only on a socket in a shared tmpfs volume.

1. Start MySQL 8.4.13 with `mysqld --initialize-insecure`, then
   `mysqld --skip-networking --socket=/fixture-socket/mysql.sock`.
2. Provision as in `scripts/mysql/test-queue-worker-ci.sh` (token schema and
   row, `repro_test`, `repro_token_reader`, `repro_token_fixture`, the queue
   and cache accounts).
3. Contracts: `php vendor/bin/phpunit -c phpunit.mysql.xml
   tests/MySql/contracts/<Contract>.php`, in the order the CI script uses.
4. Writer suite: `DB_USERNAME=repro_token_fixture php artisan migrate:fresh
   --force`, then `php vendor/bin/phpunit -c phpunit.mysql-writers.xml` with
   the environment in `tests/MySqlWriters/README.md`.
5. MySQL profile: as `.github/workflows/mysql.yml`, plus the token row for
   `repro_mysql_test` and `GRANT SELECT, UPDATE(id)` on it to `repro_test`.

Copy the source into a Docker volume first on Windows hosts: the profile is
several times slower over a Windows bind mount.

## Still open

- **WP-7 business proofs not written:** 8 editing dispatches for one shoot;
  8 bookings for one slot (empty day, guard on and off); 2 payout runs per
  role; one payment event sent 5 times; a slow
  Stripe call during a concurrent edit; two overlapping automation runs; the
  Aryeo cursor. Code reading (not tested): `RunSystemAutomations` reads its
  dispatch row with `firstOrNew` and saves it outside a transaction, so two
  overlapping runs can both start the same period even with the token.
- **Long transactions.** With the token taken at BEGIN, a long transaction
  holds every writer back from its start. The plan's week of transaction-length
  logging on production is still the gate (Checkpoint 2). The long-hold log
  added here gives the same signal on MySQL.
- **Production items** (need approval, not done): provision the token schema,
  row and grants on the native 8.4.13 target; give the monitor account its
  grants; set `REPRO_MYSQL_*` in the rehearsal environment; run representative
  load and the dress rehearsals with the policy on (WP-11, WP-12); the
  read-only writer re-inventory on the server (WP-10).
- **Owner decisions:** D1 to D6 and B1 to B15 in the known-differences
  register.
