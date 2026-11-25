# MySQL writer suite

These tests prove the one-writer rule through the real application
connection, with real committed rows and real separate processes. They live
outside `phpunit.mysql.xml` because that profile wraps each test in a
transaction, which would hold the writer token for the whole test.

## What they cover

| File | What it proves |
| --- | --- |
| `WriterTokenRuleTest.php` | BEGIN takes the token. Each autocommit write form (builder, Eloquent, `firstOrCreate`, `updateOrCreate`, raw statements) waits for it, times out with `WriterTokenTimeout` and no row, then commits once. Reads never wait. DDL and other implicit-commit statements inside a transaction, raw transaction control, batches of statements and row locks outside a transaction are refused; executable comments and EXECUTE take the token. A missing token row stops every write. A swallowed lock timeout or a lost session cannot commit; rollback callbacks and a replaced session start clean. Only the token wait is retried. A lost commit acknowledgement runs no callbacks and is not replayed. Long holds are logged. |
| `WriterExemptionsTest.php` | Queue claim and delete skip the token while enqueue waits for it. Cache locks never wait for their own caller. Each token-free session may write only its own tables. `LockedWrite` and the rate limiter retry once and run their callback once; a limiter hit whose last write waits for the token counts once. |
| `WorkerHygieneTest.php` | A worker rolls back a transaction a job left open and carries on; a leaking job that fails for good is failed once. `db:writer-token-holders` reports an old holder. |
| `WriterPolicyCheckTest.php` | `db:writer-policy-check` passes on a provisioned server and fails on a missing token row, a policy that is off, or a second token connection. It checks the `DB_URL` target and passes with `DB_URL` in the environment. |
| `WriterBusinessPathsTest.php` | Eight processes racing for the last Google Routes quota unit reserve it once (a test-only trigger holds each insert half a second so the racers really overlap). Eight first texts from a new number give one contact, one thread and eight messages. Six status webhooks for one message, sent at once in random order, keep the final delivered status. Twelve queue workers run 1,000 jobs, each exactly once: not a serialization check, because queue claims skip the token by design. |
| `WriterBusinessRacesTest.php` | The plan's WP-7 business paths. Eight sends of one editing request give one dispatch and one queued job. Eight staff bookings for one slot give one success: guard off on an empty and a booked day, guard on, and guard on without its photographer lock. Two invoice runs per role give one invoice each and no reused invoice number. One Stripe payment event sent five times gives one payment. A slow (faked) Stripe call does not fail the client editing the same shoot. Two overlapping weekly automation runs give one dispatch. The Aryeo change feed never skips a row behind a delayed writer and moves past a rolled-back one. Each worker sleeps briefly right after the read its path decides on, so the racers overlap. |

## Running it

The fixture is a disposable MySQL 8.4.13 on a private socket with networking
off. CI runs it as the last step of `scripts/mysql/test-queue-worker-ci.sh`.

1. Create `repro_mysql_test` and `repro_mysql_control.writer_token` with the
   row `(1, 'repro_mysql_test', 1)`.
2. Accounts: `repro_test` with ALL on `repro_mysql_test` and SELECT plus
   column UPDATE(id) on the token table; `repro_token_fixture` with ALL on
   both schemas. The tests also use the daemon's passwordless local `root`
   to kill sessions and read lock tables. Never point this suite at a host.
3. Build the schema as the fixture account:
   `DB_USERNAME=repro_token_fixture php artisan migrate:fresh --force`.
4. Run with `MYSQL_WRITER_POLICY_FIXTURE=isolated-v1`,
   `DB_SOCKET=/fixture-socket/mysql.sock`, `DB_USERNAME=repro_test` and
   `REPRO_MYSQL_EXPECTED_SERVER_UUID` set to the fixture's `@@server_uuid`:
   `php vendor/bin/phpunit -c phpunit.mysql-writers.xml`.

Leave the queue-worker and scheduling-cache pilot flags unset: the suite
tests the default layout.

To see what the token itself prevents, add `MYSQL_WRITER_PROOF_TOKEN=off`:
every worker process then runs with `REPRO_MYSQL_WRITER_POLICY=false` while
the test process keeps it on. The token-rule tests and most business proofs
then fail; `docs/mysql-lanes/writer-policy.md` lists which, and which other
guard keeps the rest. Never set it in CI.
