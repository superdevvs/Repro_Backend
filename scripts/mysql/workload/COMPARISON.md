# Synthetic notification comparison

`compare.py` runs a small, repeatable slice of real application behavior on
SQLite and MySQL. It does not change the pinned `run.py` or `smoke.php` package.
It uses their reviewed isolation helpers, credentials contract and output bounds.

The default profile has 100 synthetic users with 10 read receipts each. Two
independent PHP/Laravel processes each perform 10 warmup operations followed by
100 measured operations. The measured mix is 160 reads and 40 writes per engine.
Writes contend on the same user through the actual
`NotificationReadStateController` and `NotificationReadStateService`; reads use
the real service. No replacement transaction implementation or harness retry is
used. A deadlock, lock timeout or other application failure refuses the run.

Workers have separate bootstrap and warmup barriers. Their measured monotonic
windows must overlap. Latencies include the controller/service call and JSON
serialization, including the application's retries and waits; bootstrap, warmup,
barriers and independent verification are excluded. Read and write percentiles
are reported separately using nearest rank. Two fresh rounds reverse engine
order (SQLite/MySQL, then MySQL/SQLite); do not pool them to hide a regression.

After each engine run, an independent process verifies every user's exact ID
set, context and watermark. The shared user must retain 54 receipts with the
default profile: 10 seeded, four warmup and 40 measured. All other users retain
their original receipts. Receipt timestamps themselves reflect actual operation
time and are not byte-compared between engines. The canonical logical receipt
hash includes sorted IDs, user, context and the exact numeric watermark. Both
engines must produce the same logical hash. This verifies watermark preservation;
the smoke and application tests cover monotonic-update permutations separately.

## Running

Use the same dedicated, empty MySQL schema/accounts and private source/runtime
rules documented in [README.md](README.md). Pass the same credential JSON through
stdin. This coordinator supports only the **explicit supervised isolation**
mode; it independently verifies network isolation, nonroot UID, zero capabilities
and no new privileges before connecting. It runs no services or privilege tools.

```text
python3 -I /isolated/backend/scripts/mysql/workload/compare.py
  --app /isolated/backend
  --runtime /private/acceptance-runs/new-comparison
  --php /usr/bin/php8.3
  --supervised-parent-net net:[VERIFIED_SUPERVISOR_NAMESPACE]
```

`--workers` is limited to 1–4 and `--operations` to 25–500 per worker. Defaults
are two and 100. Less than full read-user coverage is explicitly recorded for
smaller profiles. The profile digest covers the static workload shape; the
runtime watermark is also retained in the receipt but excluded from that digest.

Each engine/round gets fresh fixture data and process-private Laravel caches.
Only three tables are used. The creator first refuses a nonempty schema and
stores a random ownership token. Cleanup checks the token and table allowlist
before dropping its fixtures. Failed/partial setup or forced termination can
leave synthetic tables; never reset a nonempty schema blindly. Subsequent runs
refuse it. The SQLite files remain in the private runtime as empty fixtures after
cleanup; the coordinator never replaces an existing file.

Every SQLite application connection verifies WAL, synchronous FULL, foreign keys
and a 5-second busy timeout. MySQL verifies the dedicated account, UUID, exact
8.4.13 version, socket-only operation, foreign keys, durable commits/binlogs and
exact schema grants. MySQL application work uses only its DML account; verification
uses SELECT-only. SQLite uses an independent local connection, not an equivalent
OS-enforced SQL grant. No credentials reach a SQLite child.

## Interpreting results

The private `result.json` includes raw per-operation samples, process overlap,
read coverage, separate percentiles, final-state proof and source/tool hashes.
Standard output contains a compact version. Preserve the supervising container
or service resource configuration alongside the result.

The local Docker fixture uses small synthetic tables and memory-backed filesystems.
Its MySQL cache/process allowance differs from SQLite's in-process database.
These results can expose regressions and concurrency errors, but **cannot** prove
production latency, disk durability, capacity or a general MySQL speedup. They do
not exercise HTTP/FPM, queues, authentication middleware, actual customer data,
uploads, payments, providers, external delivery or backup/restore. The output
explicitly leaves those acceptance fields false.

`sqlite_locked_write_retry_events` counts only the application's existing SQLite
retry log events. It is not a cross-engine measure of all lock waits or contention;
MySQL may wait without a retry event. Do not substitute it for host contention
instrumentation in the readiness receipt.

Portable coordinator checks:

```text
python -B -m unittest discover -s scripts/mysql/workload -p test_compare.py -v
```

Actual PHP and both-engine execution are separate from these fixture tests.

## Disposable local runner and regression evidence

`run-local-comparison.ps1` uses only the already available pinned MySQL 8.4.13
image and the local PHP fixture image. It creates uniquely named, bounded,
network-disabled containers with temporary storage and removes them afterward.
It mounts the retained application archive and current candidate read-only,
then overlays the notification service and its new tests inside the disposable
workspace. The original archive is unchanged. Its fixture receipt records the
archive and overlaid service hashes, resources, namespace baseline and storage.

```powershell
./scripts/mysql/workload/run-local-comparison.ps1 `
  -AppArchive /path/to/locked-workload-app.tar.gz `
  -EvidenceDirectory /path/to/fresh-comparison-evidence
```

Add `-RunRegression -DevVendorArchive /path/to/locked-dev-vendor.tar.gz` to run
the existing SQLite notification feature tests and native MySQL concurrency
tests before the comparison. The development archive is extracted only inside
the temporary workspace; every installed package version/reference is checked
against `composer.lock`. The separate `repro_mysql_test` schema uses a schema-only
baseline. Its trigger-creation setting is enabled only in this disposable server,
not the managed service. Application comparison accounts remain restricted to
their separate acceptance schema.

`-RunNegativeControl` additionally restores the original service only inside the
fixture, runs the existing-row native race, requires the specific MySQL 1213
failure, and restores the candidate before comparison. An unrelated failure or a
passing negative control refuses this gate. This option is diagnostic evidence,
not a retry of failed application work.

On November 22, the original service failed the concurrent comparison with a
MySQL 1213 deadlock. The candidate now locks the user row first on MySQL before
the receipt insertion/lock. SQLite retains its existing transaction order.
Two independent fresh local instances then passed the strengthened native tests
(two cases, 42 assertions each) and the existing SQLite feature file (seven
cases, 38 assertions each). The old-service negative control failed specifically
with SQLSTATE 40001 / MySQL 1213. The native cases each use two processes making
100 merges apiece and verify the exact union, watermark and account separation.

Those two final comparison runs used the same application/dependency fingerprint
`b47b864f9f01ab4c37bced7f23d69c8ce0d8374c1917bf45f9627743f5221c12`.
Across their two engine-order rounds each, all 1,600 measured calls and 320
measured writes passed, with actual worker overlap and exact final receipt sets.
The retained full receipts are the private `local-comparison-20261122-regression-2`
and `local-comparison-20261122-regression-3` evidence directories.

MySQL was **slower** in this small memory-backed fixture: its read p95 was
307–379 microseconds versus SQLite's 83–104, and write p95 was 1,864–2,189 versus
643–1,777. These measurements establish neither an overall production regression
nor an improvement; the actual-host, loaded-copy performance gate remains open.
The result's `passed` value means fixture execution and correctness passed,
not that production latency targets passed.
