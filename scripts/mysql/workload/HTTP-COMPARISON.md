# Isolated HTTP read-workload contract

`http_profile.py` plans a fixed offered workload and reduces two engines' recorded
measurements. It does not start PHP/FPM, send HTTP, open a database, read credentials,
provision services, or certify execution. It is preparation for the copied-data
application rehearsal; the existing notification comparison remains unchanged.

The initial contract supports reviewed GET routes only. GET is not proof that a
route has no effects. The future executor must review each controller, middleware
and provider path, enforce isolation, and reject unreviewed effects. Write/upload,
worker/queue, crash and host-capacity acceptance require separate workloads.

## Entry points

Run from the backend checkout, using private files outside the source directory:

```text
python -B scripts/mysql/workload/http_profile.py plan --profile /private/http-profile.json
python -B scripts/mysql/workload/http_profile.py compare --profile /private/http-profile.json --sqlite /private/sqlite-receipt.json --mysql /private/mysql-receipt.json
python -B -m unittest discover -s scripts/mysql/workload -p test_http_profile.py -v
```

Both commands emit JSON to stdout; use a private, previously absent output file
when saving it. `plan` emits the schedule, not a measured result. `compare` returns
0 for internally comparable receipts, 1 for measured failures, and 2 for refused
inputs. A comparable report may show MySQL slower on every route. It makes no
promotion decision, and always retains `http_execution_verified: false`,
`copied_data_execution_verified: false`, `write_or_queue_workload_tested: false`
and `production_readiness: false`.

The synthetic tests contain complete example profiles and receipts. Their route
names, weights, hashes and measurements are invented fixture data and must never
be submitted as traffic or rehearsal evidence. There is deliberately no default
traffic profile or production route allowlist.

## Reviewed profile, version 1

Every field below is required; unknown fields are refused. Digests are lowercase
64-character SHA-256 values. Keep the review inputs and originating artifacts
alongside the profile: a supplied digest does not authenticate its origin.

| Field | Meaning |
| --- | --- |
| `version` | Integer `1`. |
| `scope` | Exact string `isolated-http-read-workload`. |
| `release_sha` | Full 40-character reviewed release commit. |
| `app_source_sha256` | Reviewed application/dependency source digest; use the same source inventory on both engines. |
| `source_snapshot_sha256` | Original closed SQLite snapshot digest used for both prepared copies. |
| `host_profile_sha256` | Same machine, storage, FPM/resource limits, engine settings and other controlled load. |
| `fixture_manifest_sha256` | Reviewed private role, authentication, record, storage and provider-stub fixtures plus the source-snapshot binding. |
| `response_contract_sha256` | Reviewed canonical response rule implementation, pinned before the run. |
| `mysql_server_uuid` | Expected isolated target UUID, lowercase canonical form. |
| `logical_time_utc` | Fixed domain time, exactly `YYYY-MM-DDTHH:MM:SSZ`. |
| `max_in_flight` | Shared request concurrency ceiling, integer 1–64. |
| `request_timeout_ms` | Actual dispatched request timeout, integer 1–120000. |
| `routes` | 1–100 reviewed route records described below. |
| `phases` | Three phase records, exactly `warmup`, `peak`, `surge` in that order. |

A route has exactly `label`, `method`, `path_template`, `role`, `fixture`,
`weight`, and `expected_status`. Labels, roles and fixture names use lowercase
letters, digits, underscores and hyphens, starting with a letter (maximum 64
characters). Labels are unique. `method` is `GET`; `expected_status` is one exact
integer 200–299, not a broad success range. Separate denial-path tests remain
necessary.

`path_template` is an `/api/...` path with literal segments or named placeholders,
such as `/api/shoots/{shoot}/files`. It cannot contain a host, query string,
fragment, traversal, or numeric record-ID segment. The reviewed fixture resolves
placeholders and query parameters locally. Never put tokens, cookies, customer
identifiers, request/response bodies, or SQL in this profile or the receipts.

`weight` is a positive integer, up to 10000 per route and 100000 total. Derive
weights from the reviewed traffic report and retain low-frequency critical
routes; do not guess or silently omit them. Smooth weighted round-robin selection
is deterministic and resets at each phase. Route order resolves equal-weight ties
and is part of the profile digest. Both measured phases must actually schedule
every declared route, otherwise planning refuses the profile.

Each phase has exactly `name`, `duration_ms` (1–600000), and
`arrival_rate_milli_rps` (1–1000000). A rate of 1000 means one request per second;
rates are explicit review inputs, not inferred from prior results. Surge must be
at least the peak offered rate. The complete schedule is capped at 20000 requests.
Choose enough per-route samples for useful percentiles; the reducer reports exact
counts but does not turn a small sample into capacity evidence.

## Contract for a future isolated FPM executor

The schedule object contains `version`, `profile_sha256`, `schedule_sha256`, and
`operations`. Each operation has `sequence` (zero based across all phases),
`phase`, `route` and `due_us` relative to that engine run's monotonic origin.
Canonical digests use the module's `canonical`/`digest` helpers. The schedule
digest covers the object without its own `schedule_sha256` field.

1. Independently establish and record the source, host, database, fixture and
   executor identities. Require the already-reviewed nonroot/network-isolated
   environment before credentials or application boot. Keep the source free of
   live environment files and cached production config. The planner does not
   supply an isolation bypass or a privileged launcher.
2. Prepare identical fresh copies/fixtures for both engines and each round. Use
   a controlled domain clock for time-windowed results, including incoming offers.
   Keep timeout and scheduling clocks monotonic and advancing. Authentication and
   signed-link time behavior needs a separately reviewed fixture; do not weaken
   public authentication or change the machine clock.
3. Run two sequential engine pairs, SQLite/MySQL and then MySQL/SQLite, with the
   same bounded FPM/process settings. Pin identical warmup and phase schedules.
   Retain the real resource configuration and external-load observations. The
   receipt's declared order alone cannot prove that it occurred.
4. Drive the fixed arrival schedule independently of response completion, up to
   `max_in_flight`. Preserve scheduling delays when queued; do not move later due
   times to conceal an overloaded engine. Every scheduled request must produce
   exactly one terminal sample, including failed requests. A dropped request or
   interrupted round makes the receipt incomplete and is refused. Do not invent
   start times for a request that never dispatched or retry it in the harness.
5. Measure from HTTP dispatch through the complete response, including FPM queue,
   bootstrap, middleware, application database waits/retries and serialization.
   Record query counts and database warnings from actual request instrumentation;
   do not substitute zero when instrumentation is absent. Provider routes require
   reviewed local stubs, and copied queues/schedulers must stay disabled.
6. Hash a reviewed canonical logical response without exporting its raw contents.
   Preserve meaningful amounts, IDs, order, role isolation and pagination; never
   blanket-sort arrays or remove differing fields to make parity pass. Only
   explicitly reviewed volatile fields may be normalized. Pin those rules in
   `response_contract_sha256` before either engine runs.

The source-snapshot digest remains the original input identity even if private
fixtures add records to the disposable copy. An executor must separately prove
that the prepared copies match the fixture manifest; these declarations do not
perform a row-parity check.

## Executor receipt, version 1

Each engine emits one object with exactly these fields:

| Field | Required value |
| --- | --- |
| `version`, `engine` | Integer `1` and either `sqlite` or `mysql`. |
| `profile_sha256`, `schedule_sha256` | Exact planning digests. Any changed release, clock, load or source requires new receipts. |
| `database_identity` | Original source snapshot digest for SQLite; expected server UUID for MySQL. |
| `executor_sha256` | Same reviewed executor/tooling manifest digest for both engines. |
| `runs` | Exactly two objects with `round`, `engine_order`, `samples`. |

Round numbers are `1`, `2`; `engine_order` is respectively
`["sqlite","mysql"]` and `["mysql","sqlite"]`. Samples may finish out of order,
but must cover each scheduled sequence exactly once. Each sample has exactly:

```text
sequence                  integer, matching the schedule
started_us                monotonic dispatch offset, at or after due_us
completed_us              monotonic full-response/failure offset, after started_us
status                    actual integer HTTP status, or null when none arrived
outcome                   ok | http_error | timeout | transport_error | isolation_refused
query_count               measured nonnegative integer
database_warning_count    measured nonnegative integer
logical_result_sha256     reviewed logical response digest for ok; null for a failure
```

Offsets must fit within total scheduled duration plus the request timeout. A run
that cannot finish within that bound fails instead of quietly extending the load
window. Overlapping sample windows cannot exceed the profile concurrency cap.
A response exceeding the dispatched request timeout is counted as a failure even
if it reports an expected HTTP status. `ok` is not accepted as proof of the status:
the actual status must equal that route's reviewed `expected_status`.

Results retain both rounds and each phase/route separately. Service latency is
`completed_us - started_us`; arrival latency additionally includes scheduling
delay, `completed_us - due_us`. Both have nearest-rank p50/p95/p99/max; query-count
and scheduler-delay distributions are also retained. Warmup is excluded from
the measured ratios, while warmup errors, warnings and parity failures still
fail the run. No averages across routes or rounds can hide a regression.

This receipt reducer verifies structure, bindings, counts and arithmetic only.
It cannot verify that requests ran, that counters were instrumented correctly,
that FPM was isolated, or that an operator supplied authentic observations. The
real executor, its supervising receipt and independent acceptance remain pending.
Do not translate these results directly into a cutover readiness receipt.
