# Fixture-only Laravel transaction integration

This is a development-only integration slice. The production
`App\Database\MySqlConnection`, AppServiceProvider, configuration and deployment
selection remain unchanged. No ordinary deployment switch selects
`FixtureWriterTokenConnection`; it lives under `tests/`, has a private constructor,
and its factory requires the exact isolated test account, schema, socket,
environment and pinned server identity. The contract is invoked explicitly rather
than added to normal `*Test.php` discovery or hidden behind skips.

Use the exact runtime and disposable provisioning in
[the primitive contract instructions](README-writer-token.md), then run:

```sh
php vendor/bin/phpunit --no-progress --fail-on-warning --fail-on-risky \
  -c phpunit.mysql.xml \
  tests/MySql/contracts/MySqlWriterTokenContract.php \
  tests/MySql/contracts/LaravelWriterTokenContract.php
```

The integration uses the locked Laravel MySQL connection, transaction manager,
events, query builder and savepoints, plus the existing application MySQL
connector and grammar. It does not bootstrap application providers or invoke
external services. The fixture owns the same `_writer_token_probe` and separate
control table as the primitive contract. Connection teardown is explicit so the
fixture remains within its twelve-connection bound.

## Behaviors exercised

- `executeBeginTransactionStatement()` calls `beginAndAcquire` instead of issuing
  a separate BEGIN. Laravel increments its depth and registers callbacks only
  after acquisition succeeds. Nested transactions use real savepoints without
  reacquiring the token.
- Manual and closure commit/rollback preserve database values, Laravel depth,
  callback timing and transaction-manager cleanup. Inner rollback keeps the outer
  token; inner commits defer callbacks until the outer commit.
- An outer `transaction(..., 3)` retries only the exact typed timeout raised by
  this connection's acquisition before entering the business callback. Each
  parent Laravel transaction receives one attempt. Business, nested and
  after-commit failures never replay the business callback.
- Native post-token error 1205 poisons the transaction even when caught by the
  callback. Later Laravel statements and both commit paths fail until outer
  rollback. A raw physical rollback is detected before a later Laravel write can
  accidentally run in autocommit.
- Missing control state and native connection loss at BEGIN, during a query and
  at COMMIT discard the connection without calling the reconnector. This includes
  the framework query-handler path that otherwise sees reset depth zero and
  attempts reconnect/replay.
- A separate fault performs a **real COMMIT**, then throws before Laravel receives
  its acknowledgement. It verifies durable data, no business replay and neither
  commit nor rollback callbacks. This is boundary fault injection, not a claim
  that a physical network acknowledgement was dropped.
- A throwing rollback callback is invoked once. Uncertain discard abandons the
  fixture's private transaction manager without dispatching callbacks that could
  incorrectly claim rollback or execute twice.

Pre-BEGIN application callbacks and pretend transactions are explicitly outside
this fixture scope and refused. The primitive still enforces READ COMMITTED and
five-second row/metadata wait settings; those are per-lock waits, not a total
end-to-end deadline. Three failed acquisitions therefore take approximately
fifteen seconds, before any business callback runs.

## Single-statement write proof

The fixture recognizes two exact probe statements. A query-builder `insertGetId`
and a bound `affectingStatement` reservation each wait for the same writer token
before executing on the same PDO. The contract checks that a contended write
times out without creating a row, an ordinary read proceeds while a peer holds
the token, and a new attempt after release creates one row. The generated ID or
affected-row count is preserved. Query observers run after the row is visible to
an independent connection. A throwing observer cannot undo the commit or replay
the statement. Rejected SQL rolls back and releases the token. An explicit
transaction still owns its insert and can roll it back. The probe match applies
to the two fixture statements only.

## Gates before runtime integration

Complete write serialization still needs the remaining autocommit paths, raw PDO,
alternate connections, queue bookkeeping exemptions, cache/telemetry ordering,
and complete SQL classification. Native 1213 deadlock
and broader business-workflow races still need their own proofs.

The fixture has a **private** transaction manager. Runtime integration must also
prove that abandoning one uncertain connection does not erase another
connection's pending callbacks in Laravel's shared manager. Uncertain COMMIT must
be reconciled with existing idempotency records; an outer layer must not blindly
replay it.

Before registration, finish all writer-path coverage and exception policy, fresh
and imported-schema admission, control-schema restore/grants checks, ordinary CI
integration, long-holder instrumentation, worker cleanup, and representative
application/queue load. Existing native queue and operational monitoring
contracts must remain valid. No deployment-readiness claim follows from this
standalone fixture passing.
