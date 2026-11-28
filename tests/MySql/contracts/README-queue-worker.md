# MySQL queue worker routing pilot

Each logical queue has its own optional jobs-only worker routing on
`DB_CONNECTION=mysql`. Every flag defaults to false.

| Queue connection | Flag | Existing retry interval | Existing enqueue timing |
| --- | --- | ---: | --- |
| `database` | `REPRO_MYSQL_QUEUE_WORKER` | `DB_QUEUE_RETRY_AFTER`, default 2,100 seconds | During the business transaction |
| `studio` | `REPRO_MYSQL_STUDIO_QUEUE_WORKER` | 7,260 seconds | After commit |
| `iguide-lab-tour` | `REPRO_MYSQL_IGUIDE_LAB_TOUR_QUEUE_WORKER` | 900 seconds | After commit |

Enabled queues share the verified `mysql_queue_worker` database connection
and its existing unprefixed InnoDB `jobs` table for claim, acknowledgement,
and release. Laravel's job object retains its logical queue name, and each
queue keeps its retry interval. Enqueue and administrative clear use the
original business PDO. A rolled-back business transaction leaves no normal
queued job.
Each queue retains its existing dispatch and after-commit behavior. SQLite
continues through the SQLite connector.

The worker connection shares the original socket, schema and expected server
UUID. Its username and password come from the protected
`REPRO_MYSQL_QUEUE_WORKER_USERNAME` and `REPRO_MYSQL_QUEUE_WORKER_PASSWORD`
settings. Its separate `@localhost` principal needs exactly `SELECT`,
`INSERT`, `UPDATE` and `DELETE` on the applicable `jobs` table, plus the
implicit global `USAGE` grant. The worker verifies the real database identity,
that principal, its grants, and the table on first use and before a replacement
PDO executes queue SQL. An invalid worker configuration stops the corresponding
worker operation and leaves enqueue routing on the original business PDO.

Run `MySqlQueueWorkerContract.php` explicitly in a network-isolated MySQL
8.4.13 fixture with a matching server UUID and separate application,
jobs-only, and intentionally overprivileged test accounts. The contract
exercises transactional enqueue and rollback, job progress while the business
token is held, the same scoped session for claim and acknowledgement, and
refusal of an invalid or overprivileged worker. It also checks the independent
alias flags, after-commit dispatch, logical queue names, distinct reservation
windows, and acknowledgement after an authenticated shared-session
replacement. The fixture provides no application data or external
communications.

The MySQL CI workflow runs this contract once before the regular tests in
shard zero. It starts a separate server from the workflow's pinned Oracle
image, shares a private Unix socket with the hosted runner, and runs the
already-installed PHP dependencies in an empty network namespace.
