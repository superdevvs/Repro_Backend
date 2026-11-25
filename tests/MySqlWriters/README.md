# MySQL writer suite

These tests prove the one-writer rule through the real application
connection, with real committed rows and real separate processes. They live
outside `phpunit.mysql.xml` because that profile wraps each test in a
transaction, which would hold the writer token for the whole test.

## What they cover

| File | What it proves |
| --- | --- |
| `WriterTokenRuleTest.php` | BEGIN takes the token. Each autocommit write form (builder, Eloquent, `firstOrCreate`, `updateOrCreate`, raw statements) waits for it, times out with `WriterTokenTimeout` and no row, then commits once. Reads never wait. DDL, raw transaction control and row locks outside a transaction are refused. A missing token row stops every write. A swallowed lock timeout or a lost session cannot commit. Only the token wait is retried. A lost commit acknowledgement runs no callbacks and is not replayed. Long holds are logged. |
| `WriterExemptionsTest.php` | Queue claim and delete skip the token while enqueue waits for it. Cache locks never wait for their own caller. Each token-free session may write only its own tables. `LockedWrite` and the rate limiter retry once and run their callback once. |
| `WorkerHygieneTest.php` | A worker rolls back a transaction a job left open and carries on. `db:writer-token-holders` reports an old holder. |
| `WriterPolicyCheckTest.php` | `db:writer-policy-check` passes on a provisioned server and fails on a missing token row, a policy that is off, or a second token connection. |
| `WriterBusinessPathsTest.php` | Eight processes racing for the last Google Routes quota unit reserve it once. Eight first texts from a new number give one contact, one thread and eight messages. Six status webhooks for one message, sent at once in random order, keep the final delivered status. Twelve queue workers run 1,000 jobs, each exactly once. |

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
