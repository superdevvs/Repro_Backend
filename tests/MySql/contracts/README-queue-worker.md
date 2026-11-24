# MySQL queue worker routing pilot

The default database queue uses its original business connection. The
`REPRO_MYSQL_QUEUE_WORKER` flag defaults to false and only applies when
`DB_CONNECTION=mysql`. This pilot covers the `database` queue connection and
its existing unprefixed InnoDB `jobs` table. Studio and iGUIDE use their
existing connections.

When the flag is enabled, claim, acknowledgement, and release use the
`mysql_queue_worker` database connection. Laravel's normal job object keeps
that worker connection for acknowledgement and release under the same logical
queue name. A normal, raw, delayed or bulk enqueue or administrative clear
uses the original business PDO. A rolled-back business transaction still
rolls back its enqueues. The SQLite connector is used normally with SQLite
configured.

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
refusal of an invalid or overprivileged worker. The fixture provides no
application data or external communications.
