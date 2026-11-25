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
- **Autocommit writes.** INSERT, UPDATE, DELETE, REPLACE, CALL, LOAD and
  EXECUTE at level 0 run as BEGIN, token, statement, COMMIT
  (`App\Database\WriterStatement` classifies the SQL, reading MySQL executable
  comments `/*! ... */` as SQL). DDL, SET and SELECT are never wrapped.
- **Refusals.** DDL, TRUNCATE and the other statements MySQL commits
  implicitly (ANALYZE, OPTIMIZE, REPAIR, CHECK, FLUSH, SET autocommit and
  similar) inside a transaction; raw BEGIN, COMMIT, ROLLBACK, SAVEPOINT, LOCK
  TABLES on the writer connection; more than one statement in one call (the
  writer and table-limited sessions also connect without multi-statement
  support, so the server refuses a batch too); a row lock outside a
  transaction (error in tests, warning log elsewhere). One test-only
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
  level 0 or the session is replaced (`DB::disconnect()`, `DB::reconnect()`).
  Rollback callbacks run after the guard is cleared. An unacknowledged COMMIT
  discards the session; no callback runs and nothing is replayed.
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
  The worker loop starts every job at depth 0. A failed database job whose
  delete that rollback undid is deleted again, so it is failed once.
- **Long holders.** Holds over one second are logged.
  `php artisan db:writer-token-holders --connection=<monitor> --older-than=N`
  lists holders from `performance_schema.data_locks` and exits 1 if any.
- **Fixes found by the WP-7 proofs** (both engines, B17 and B18 in the
  register): the Stripe checkout webhook asks Stripe for the payment intent
  and charge before its payment transaction opens, never inside it; a weekly
  automation run claims its rule's period in one transaction and skips a
  period another run started less than 30 minutes ago.
- **Deploy check.** `php artisan db:writer-policy-check` exits 1 unless only
  the default connection carries the token, every other MySQL connection has a
  table limit, a fresh session is READ COMMITTED with 5/5 lock waits, and the
  token can be taken. It resolves `DB_URL` as Laravel does. On SQLite it
  reports "not applicable" and exits 0.

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
  reason was not investigated; later explained: its race window is
  microseconds, see "WP-7 business proofs"). Afterwards two retry proofs were changed to
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

## Independent review (same day)

A second session reviewed the branch as if to refute it, re-ran the tests and
fixed two defects, each test first:

- **Busy writers got a 500 on MySQL.** Callers such as the editing dispatch,
  media rename, schedule commit and upload submission answer "busy, retry"
  (503 or 409) when `LockedWrite::isLockContention()` says another writer held
  the database. It did not recognise `WriterTokenTimeout`, so on MySQL the same
  situation became a 500. It now does. `LockedWrite::run()` still replays its
  callback only for SQLite's own lock error. Proofs:
  `WriterTokenRetryLayersTest::test_a_writer_token_timeout_is_lock_contention_for_busy_responses`
  (both engines) and `WriterExemptionsTest::test_a_token_wait_that_runs_out_is_busy_for_callers`.
- **A job that could not be claimed was deleted without a failed_jobs row.**
  `MySqlDatabaseQueue` wrapped Laravel's whole `pop()` in the token-free
  bookkeeping scope, so the failure path inside it (the job's failed handler
  and the JobFailed listeners, which write `failed_jobs`) was refused and the
  refusal swallowed. Only the claim transaction skips the token now. Proof:
  `WriterExemptionsTest::test_a_job_that_cannot_be_claimed_is_failed_and_recorded_with_the_token`.

Review runs, all local and offline, MySQL 8.4.13 with durable flushing:

- Writer suite on `80521e0d` before the fixes: 65 tests passed. After them:
  67 tests, 876 assertions, all passing.
- The CI script's contract stages, in its order: primitive 23, Laravel
  fixture 22, Google Routes pilot 9, queue-worker pilot 9, scheduling-cache
  pilot 3, all passing.
- MySQL profile for the touched areas (the lane's Feature tests, the Telnyx,
  contention, schedule-guard, Google Calendar and iGUIDE Lab tour files, and
  all of `tests/MySql`): 176 tests, 4 failures. All 4 are in
  `DatabaseBackupTest` and `PointInTimeBackupTest`, stop at their
  configuration stage in this fixture, and fail the same way on `a6e799bb`.
- SQLite suite (two workers): 9,674 tests, 3 skipped (no ClamAV), 2 failures
  and 2 errors: the same four the lane reported, which also fail on
  `a6e799bb` in this container.

Notes for the integrator, not fixed here:

- The open item asked for the policy "behind a default-off flag". The lane
  turned it on by default for MySQL (no effect on SQLite). That keeps the
  readiness gate honest but makes every MySQL target need the token row before
  the application writes. Owner decision; the flag is `REPRO_MYSQL_WRITER_POLICY`.
- ~~Two business proofs do not depend on the token.~~ The Google Routes race
  now fails without it (see the WP-7 section). The twelve-worker queue run is
  relabelled: queue claims skip the token by design, so it passes either way
  and is not proof of serialization.
- ~~A job that leaves a transaction open and also fails on its last try is
  recorded in `failed_jobs` twice.~~ Fixed in the Codex review round below.
- ~~`ANALYZE`, `OPTIMIZE`, `REPAIR`, `CHECK` and `FLUSH` are not classed as
  DDL.~~ Fixed in the Codex review round below.
- Each request that takes a database cache lock opens a second MySQL session
  (`mysql_cache_locks`). Size `max_connections` for that.

## Codex review round (25 Nov)

Codex reviewed `bebf0144`; the reviewer's open items came from the independent
review above. Fixes are on top of `3fb34030`, one commit each, test first: each
new test failed on the unfixed code for the reason given, then passed.

- `writer-policy#1` (P2) **fixed** in `abf943b4`: `SELECT 1; UPDATE ...` and `INSERT INTO cache_locks ...; UPDATE ...` ran in full (RED: no error); a second statement is now MULTIPLE and refused, and writer and table-limited sessions connect without multi-statement support so the server refuses a batch too. Tests: `WriterTokenRuleTest::test_a_batch_of_statements_is_refused_before_any_of_it_runs`, `..._the_server_runs_one_statement_per_call_on_every_writer_session`, `WriterExemptionsTest::test_the_cache_lock_session_may_write_only_cache_locks`, `WriterStatementTest`.
- `writer-policy#2` (P2) **fixed** in `f18c0da5`: `/*!80000 UPDATE ... */` and `EXECUTE` wrote while another session held the token and `/*!80000 COMMIT */` was not refused (RED); executable comments are now read as SQL and `EXECUTE` is a write. Tests: `WriterTokenRuleTest` data sets "executable comment", "prepared statement" and `/*!80000 COMMIT */`, `WriterStatementTest`.
- `writer-policy#3` (P2) **fixed** in `9620b468`: an `afterRollBack` callback that queried after a lock error got the poisoned-connection error and left the connection poisoned (RED); the guard and depth are now cleared before callbacks run. Test: `WriterTokenRuleTest::test_rollback_callbacks_after_a_lock_error_run_on_a_clean_connection`.
- `writer-policy#4` (P2) **fixed** in `8a8f7c83`: with `DB_URL` set the check connected to the fallback host and database (RED: `repro_not_the_target`); it now parses `DB_URL` as Laravel does. Tests: `WriterPolicyCheckCommandTest::test_the_check_connects_to_the_database_url_target_as_the_application_does` (any engine), `WriterPolicyCheckTest::test_checks_the_database_url_target_as_the_application_does`.
- Reviewer 1 (policy on by default for MySQL): **owner decision**, unchanged; see the integrator notes above and `REPRO_MYSQL_WRITER_POLICY`.
- Reviewer 2 (WP-7 business proofs): **fixed** in the second stage (`74e3cfee`, `53469637`, `673a065c`); one line per proof below and the table in "WP-7 business proofs".
- Reviewer 3 (CI not run on GitHub, shard-0 time unmeasured): **documented**; needs a push, which the owner approves. Nothing pushed.
- Reviewer 4 (a leaking job that fails for good gets two `failed_jobs` rows): **fixed** in `277d59cf`; after the rollback the cleanup deletes the failed job again. Tests: `LeftoverTransactionCleanupTest::test_a_job_that_leaks_a_transaction_and_fails_for_good_stays_deleted`, `WorkerHygieneTest::test_a_job_that_leaks_a_transaction_and_fails_on_its_last_try_is_failed_once`.
- Reviewer 5 (Google Routes race and twelve-worker proofs also pass without the token): **fixed** in `827d445d`. A test-only trigger holds each route insert 0.5 s, so the race now reserves 8 units without the token and 1 with it. The twelve-worker run is relabelled as not a serialization check (queue claims skip the token by design, D3).
- `wp7:editing-dispatch` **proved** (`74e3cfee`): with the token 8 × 202, one dispatch, one job; without it 7 × 500 on the unique `request_id`.
- `wp7:booking-slot` **proved** (`74e3cfee`): one success in all four cases with the token; without it the empty day gives 2 to 3 bookings and the guard without its photographer lock 5 to 8. The booked day and the full guard hold through their own locks.
- `wp7:payout-runs` **proved** (`74e3cfee`): with the token one invoice per photographer, rep and client and no reused number; without it two period invoices per client and a reused number. Payout invoices also hold through `payout_generation_locks`.
- `wp7:payment-event` **proved** (`74e3cfee`): one payment with the token; also without it, through the shoot row lock, the duplicate check and the unique session index. With those removed in a scratch copy it failed without the token in 2 of 3 runs and passed with it.
- `wp7:slow-stripe` **fixed** in `53469637`: RED before (500 after 8.0 s with the token, 1205 after 5.1 s without); Stripe is now asked before the transaction and the proof passes with and without the token.
- `wp7:automation-overlap` **fixed** in `673a065c`: RED before with the token (two dispatches, two deliveries); the period is now claimed in one transaction. Passes with the token, still fails without it.
- `wp7:aryeo-cursor` **proved** (`74e3cfee`): with the token every committed row is read; without it the delayed writer's row is skipped for good. The rolled-back writer case passes either way (gap check).
- Reviewer 6 (ANALYZE, OPTIMIZE, REPAIR, CHECK, FLUSH classed OTHER): **fixed** in `c991d365`, with the rest of MySQL's implicit-commit list (CACHE INDEX, LOAD INDEX, RESET, INSTALL, UNINSTALL, SET autocommit, SET PASSWORD). Test: `WriterTokenRuleTest::test_other_statements_that_commit_implicitly_are_refused_inside_a_transaction`.
- Reviewer 7 (deploy check not called by the deploy pipeline): **documented**; wiring it in is a production deploy change for the owner at cutover (runbook step 3).
- Reviewer 8 (guard survives `DB::reconnect()`/`disconnect()`; Windows caller paths): **fixed** in `bb57bf1e` (replacing the session clears the guard and drops the failed transaction's records; test `WriterTokenRuleTest::test_a_new_session_after_a_failed_transaction_is_usable_without_a_purge`) and `17fbabd7` (test `MySqlConnectionCallerTest`).

Runs for this round, local and offline (`--network none`), MySQL 8.4.13 with
durable flushing on a socket-only fixture, PHP 8.3.33:

- SQLite: `tests/Unit/Database`, `WriterTokenRetryLayersTest`,
  `LeftoverTransactionCleanupTest`, `WriterPolicyCheckCommandTest`,
  `ThrottleDatabaseResilienceTest` with `--fail-on-warning --fail-on-risky
  --fail-on-phpunit-deprecation`: OK (142 tests, 579 assertions).
- `migrate:fresh` with the final code passes; the iGUIDE trigger migration
  still runs through the writer connection without multi-statement support.
- Writer suite on a fresh schema, same flags: OK (82 tests, 1,045 assertions).
- Contracts in CI order: primitive 23, Laravel fixture 22, Google Routes pilot
  9, queue-worker pilot 9, scheduling-cache pilot 3, all passing.
- MySQL profile, the four Feature files above: OK (42 tests, 458 assertions).
  All of `tests/MySql` except the contracts: 67 tests, 4 failures, all in
  `DatabaseBackupTest` (2) and `PointInTimeBackupTest` (2) stopping at their
  configuration stage, the known fixture failures listed above.
- `vendor/bin/pint --test` on every changed PHP file: pass.

## WP-7 business proofs (second stage, 25 Nov)

`tests/MySqlWriters/WriterBusinessRacesTest.php`, workers in
`tests/MySqlWriters/support/business-worker.php`. Every racer is a separate
PHP process that boots the application and enters through the real entry
point: the HTTP kernel (routing, middleware, validation, controller), the
invoice service, or `artisan automations:run-system`. So that racers really
overlap, each worker sleeps 0.3 s (0.5 s for the automation run) right after
the read its path decides on, inside whatever transaction is open: with the
token the others wait their turn, without it they run in the gap. Providers
are faked inside the worker (Stripe's HTTP client, Google Routes, travel
locations, the sales report); every container runs with `--network none`, so
nothing leaves the machine. No path needed a live provider.

"Without the token" means `MYSQL_WRITER_PROOF_TOKEN=off`: every worker runs
with `REPRO_MYSQL_WRITER_POLICY=false`, the test process keeps it on.

| Proof | With the token | Workers without the token | Other guard on the path |
| --- | --- | --- | --- |
| `wp7:editing-dispatch`: 8 sends of one AI editing request (`POST /api/shoots/{id}/editing-dispatch`) | 8 × 202 with one dispatch id; 1 dispatch; 1 `PrepareEditingDispatch` job | **Fails**: 7 × 500 (`UniqueConstraintViolationException` 1062 on `request_id`), 1 × 202; still 1 job | The unique `request_id` index keeps one job; only the token turns the losers into the same answer |
| `wp7:booking-slot`, guard off, empty day (`POST /api/shoots`, 8 staff bookings of one 10 am slot) | 1 × 201, 7 × 422 | **Fails**: 2 or 3 bookings (two runs) | None: the day lock finds no row to lock |
| guard off, the photographer's day already has a 2 pm booking | 1 × 201, 7 × 422 | Passes | The day lock locks that booking's row |
| guard on (`ScheduleCommitGuard`), empty day | 1 × 201, 7 × 422 | Passes | The per-photographer scheduling lock (database cache lock) |
| guard on, its photographer lock made process-local (`availability.scheduling_lock_store` = `array`) | 1 × 201, 7 × 409 (the recheck inside the transaction) | **Fails**: 5 or 8 bookings (two runs) | None left but that recheck, which the token serializes |
| `wp7:payout-runs`: 2 photographer payout runs, 2 sales-rep payout runs, 2 period-invoice runs for each of 2 clients, all at once | One payout invoice per photographer and for the rep, one period invoice per client, no invoice number used twice | **Fails**: 2 period invoices per client and one invoice number issued twice (`Invoice 00016`, then `Invoice 00013` on a fresh schema) | Payout invoices stay single: the `payout_generation_locks` row lock and the `payout_work_allocations` unique key. Payout invoices carry no invoice number; the numbered ones are the client invoices |
| `wp7:payment-event`: one signed `checkout.session.completed` delivered 5 times (`POST /api/webhooks/stripe`) | 1 payment; 1 × `200 processed`, 4 × `500 failed` (Stripe retries); the later redelivery gets `200 already_processed` | Passes | The shoot row lock, the duplicate check inside the transaction and the unique `stripe_session_id` index. With the row lock and the index removed (a scratch copy, see below), it fails without the token and passes with it |
| `wp7:slow-stripe`: the fake Stripe API answers the payment-intent lookup after 8 s while the shoot's client saves its notes (`PATCH /api/shoots/{id}/notes`) | Before the fix **failed**: the edit got a 500 (`WriterTokenTimeout`) after 8.0 s. After: 200 | Before the fix **failed**: 500 (`QueryException` 1205, the shoot row lock) after 5.1 s. After: 200 | The protection is the fix itself (B17): no Stripe call inside the transaction |
| `wp7:automation-overlap`: the scheduler's run and an admin's "Run now" of one weekly rule, at once | Before the fix **failed**: 2 dispatch rows, both runs delivered the report. After: 1 dispatch, 1 run, 1 message | After the fix still **fails**: 2 dispatch rows (a first run's read has no row to lock) | None: on MySQL the token serializes the claim; on SQLite the reserved writer does (B18) |
| `wp7:aryeo-cursor`, delayed writer: a writer holds a shoot change open while a second writer commits and a reader pages `AryeoWorkerController::changes` in between | Every committed row read | **Fails**: the delayed writer's row skipped for good (row 11 in one run, 155 in another) | None |
| rolled-back writer, same setup | Every committed row read; the rolled-back change never appears | Passes | Not a serialization check: it shows the cursor moves past the gap a rollback leaves |
| Google Routes race (`WriterBusinessPathsTest`), now with a test-only trigger that holds each insert 0.5 s | 1 reservation | **Fails**: 8 reservations | None |
| Twelve workers, 1,000 jobs (`WriterBusinessPathsTest`) | Each job once | Passes | Queue claims skip the token by design (D3); relabelled as not a serialization check |

Two proofs exposed real defects with the token on; each was fixed test
first, in its own commit (B17, B18 in the register):

- **A slow Stripe call failed other writers** (fixed in `53469637`).
  `processSingleShootPayment` and
  `processMultipleShootPayment` called Stripe (`hydratePaymentRecord`) inside
  the payment transaction, holding the shoot's row lock and the token (on
  SQLite, the write lock). `StripePaymentMetadataService::prefetchStripeObjects()`
  now asks Stripe before the transaction opens and the transaction records the
  same details without another call. The stored details are unchanged; a
  failed lookup is logged once, as before. The production change that makes
  the proof fail again is passing no prefetched objects to
  `hydratePaymentRecord()` inside the transaction.
- **Two overlapping weekly automation runs delivered twice** (fixed in
  `673a065c`). The command read
  its period with `firstOrNew` and saved it outside a transaction, and a
  second run treated a period another run had started as free. It now claims
  the period in one transaction and skips a period another run started less
  than 30 minutes ago; a run that stopped without finishing releases it after
  that. Removing the transaction, or the "running" check, makes the proof fail.

Demonstration only (scratch copy of the source in the test container, never
committed): with `Shoot::query()->lockForUpdate()` removed from
`processSingleShootPayment` and the `payments_stripe_session_id_unique` index
dropped, the payment-event proof failed without the token in two of three
runs (2 payments; the losers died on 1213 deadlocks, which sometimes caught all
of them) and passed with the token in both runs (1 payment; the losers found it
in their duplicate check). So the token alone keeps one payment; today the
path does not depend on it.

Runs for this stage, local and offline, MySQL 8.4.13 with durable flushing on a
socket-only fixture, PHP 8.3.33, `--fail-on-warning --fail-on-risky
--fail-on-phpunit-deprecation`:

- Whole writer suite on a fresh schema with the token: OK (93 tests, 1,321
  assertions, 3 min 38 s). `WriterBusinessRacesTest` alone: OK (11 tests,
  276 assertions, about 35 s), so shard 0 grows by under a minute.
- Both business files with `MYSQL_WRITER_PROOF_TOKEN=off`: the races file
  6 of 11 failing as the table says, the paths file 3 of 4 (Routes race,
  first texts, status webhooks; the queue run passes).
- SQLite: the Stripe webhook, checkout, confirmation, refund, receipt and
  payment-intent files, `StripePaymentPrefetchTest`, `ScheduledAutomationCommandsTest`,
  the automation history and privacy files and the dispatcher dedupe unit test:
  OK (106 tests, 687 assertions).
- `vendor/bin/pint --test`: the new and changed test files pass. The three
  changed app files and `ScheduledAutomationCommandsTest` already failed Pint
  at `72ce6f50`; the changes add no new rule.

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

- **WP-7 proofs not covered offline:** none. Live provider behaviour (real
  Stripe latency and retries, real Google Routes answers) is faked; the fakes
  answer the same shapes the code reads.
- **B18's 30 minutes** is a choice for the owner, not measured: it must
  exceed the longest weekly run on production, and a run that crashed waits
  that long before the next one retries its period.
- **Long transactions.** With the token taken at BEGIN, a long transaction
  holds every writer back from its start. The plan's week of transaction-length
  logging on production is still the gate (Checkpoint 2). The long-hold log
  added here gives the same signal on MySQL.
- **Production items** (need approval, not done): provision the token schema,
  row and grants on the native 8.4.13 target; give the monitor account its
  grants; set `REPRO_MYSQL_*` in the rehearsal environment; run representative
  load and the dress rehearsals with the policy on (WP-11, WP-12); the
  read-only writer re-inventory on the server (WP-10).
- **Owner decisions:** D1 to D6 and B1 to B18 in the known-differences
  register.
