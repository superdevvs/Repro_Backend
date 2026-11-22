# Isolated cross-schema writer-token contract

This is an **unregistered primitive**, not the application's completed concurrency
policy. Nothing in AppServiceProvider or MySqlConnection invokes it. The explicit
contract filename is outside the normal `*Test.php` discovery; it must be run by
path after provisioning the fixture. It does not silently skip missing setup.

## Runtime and invocation

Use the existing disposable, network-none fixture with MySQL **8.4.13**, PHP
**8.3.33** and the candidate's exact Composer lock including development packages.
The checked MySQL image is
`sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902`;
the local PHP image is `repro-mysql-rehearsal-qa:20261122`. The test daemon and PHP
each use one CPU and 640 MiB, temporary storage, no external network, and no live
configuration or data. The PHP process runs as uid 1000.

The contract deliberately requires the socket `/fixture-socket/mysql.sock`, a
new empty `repro_mysql_test` application schema, a separate
`repro_mysql_control` schema, and these fixture-only accounts. All use
`caching_sha2_password` and the same ephemeral value supplied as `DB_PASSWORD`:

| Account at localhost | Privileges |
| --- | --- |
| `repro_test` | SELECT, INSERT, UPDATE, DELETE on `repro_mysql_test` only; SELECT and column UPDATE(id) on `repro_mysql_control.writer_token` |
| `repro_token_reader` | SELECT on the application schema and token table only |
| `repro_token_fixture` | ALL on those two disposable schemas only, no GRANT OPTION |

Provision the control table before granting column privileges:

```sql
CREATE TABLE repro_mysql_control.writer_token (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY CHECK (id = 1),
  application_database VARBINARY(64) NOT NULL,
  contract_version INT UNSIGNED NOT NULL
) ENGINE=InnoDB;
GRANT SELECT, UPDATE(id) ON repro_mysql_control.writer_token
  TO repro_test@localhost;
```

The fixture setup account recreates and seeds this table for every case. The test
also connects to the disposable daemon's passwordless local root account solely
to toggle its recovery-only state and kill its own synthetic connection at an
exact fault boundary. That root account is created by `--initialize-insecure`
inside this network-none, temporary daemon; this contract is not a host test.

Set the following environment, with the UUID read independently from this new
fixture and the ephemeral fixture password:

```sh
export APP_ENV=testing DB_CONNECTION=mysql
export DB_SOCKET=/fixture-socket/mysql.sock
export DB_DATABASE=repro_mysql_test DB_USERNAME=repro_test
export DB_PASSWORD='<ephemeral fixture password>'
export MYSQL_WRITER_TOKEN_FIXTURE=isolated-v1
export MYSQL_WRITER_TOKEN_UUID='<independently observed fixture UUID>'
php vendor/bin/phpunit --no-progress --fail-on-warning --fail-on-risky \
  -c phpunit.mysql.xml tests/MySql/contracts/MySqlWriterTokenContract.php
```

The test owns only the token table and `_writer_token_probe`. Its empty-schema
assertion intentionally rejects a loaded application database. Stop/remove the
two scoped fixture containers and their temporary socket volume afterwards.

## Proven boundary and remaining integration

`beginAndAcquire` refuses an already active PDO transaction without rolling back
its owner's work. It validates the exact server UUID, current database, writable
server/session, actual server autocommit and configured wait settings. It sets
one-shot READ COMMITTED and READ WRITE immediately before BEGIN on the caller's
PDO, then locks the fixed token row and verifies its InnoDB engine and binding.
The caller owns the eventual commit/rollback. The token is outside application
table parity and requires no application migration or reverse-export exclusion.

Only error 1205 during token acquisition becomes `WriterTokenTimeout`, and only
when the PDO still owned a transaction, rollback returned success, and the
transaction is confirmed closed. Other failures require discarding that PDO.
There is no retry or reconnect inside this primitive. The five-second settings
bound individual row/metadata lock waits, not a total wall-clock deadline.

The separate control schema is an explicit candidate choice replacing the old
plan's earliest application migration. Its definition, binding and minimal grants
must be independently provisioned and verified before fresh or imported schemas
admit traffic, and again after restoration. Runtime never creates missing state.

Still required: Laravel begin/commit/savepoint bookkeeping and exception cleanup;
all autocommit write paths and raw-PDO/alternate-connection coverage; narrow queue
bookkeeping exemptions; cache/telemetry lock ordering; one retry owner; post-token
failure poisoning; fresh/import/restore admission; ordinary CI integration;
business concurrency tests, long-holder instrumentation and representative load.
