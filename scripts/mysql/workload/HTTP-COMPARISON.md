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

## First real transport executor

`http_execute.py` now executes one engine/round against one pinned private Linux
AF_UNIX HTTP socket. It starts no server, FPM process, container or database. The
reviewed external supervisor must establish isolation and start only the intended
fixture before calling it. This is a small real HTTP transport slice, not a general
HTTP client or an implemented copied-data/FPM benchmark.

```text
python -B scripts/mysql/workload/http_execute.py run --profile /private/profile.json --schedule /private/schedule.json --supervised-parent-net net:[OBSERVED_BASELINE] --output /private/sqlite-round-1.json < /private/synthetic-run.json
python -B scripts/mysql/workload/http_execute.py assemble --profile /private/profile.json --round-one /private/sqlite-round-1.json --round-two /private/sqlite-round-2.json --output /private/sqlite-receipt.json
```

Use the same commands for the MySQL-labelled rounds, in the profile's prescribed
counterbalanced order. The independently supervised fixture preparation and engine
ordering remain external responsibilities. The assembled receipts feed the earlier
`http_profile.py compare` command. Retain the original round fragments and supervisor
proof: assembly preserves the reducer's existing schema and does not add execution
attestation to it. The complete executor digest binds `http_execute.py`,
`http_profile.py`, and the reused isolation helper `run.py`.

Before reading stdin, the CLI verifies a distinct network namespace, only loopback
interfaces, no outbound route, a nonroot UID, empty capability sets and
`NoNewPrivs=1`, using the existing `run.py` checks. The baseline namespace must come
from the reviewed supervisor; an arbitrary different namespace string is not proof
of supervision. Socket and output parents must be canonical, owned by the executing
UID and mode 0700. Ancestors must be owned by root or that UID and not writable by
others, except root-owned sticky temporary directories. `/var/www` is refused.
The socket must be mode 0600, owned by the same unprivileged UID, and match the
reviewed device/inode. Linux `SO_PEERCRED` must match the expected UID/PID before
any request bytes are sent. The socket identity is checked again after connecting.
This initial same-UID restriction is intentional; do not relax it for a live FPM
socket. Every output is a fresh 0600 file, flushed and synced; existing output paths
are refused. A failed write may leave an incomplete output that is not a receipt.

The bounded stdin object has exactly these fields:

| Field | Required contract |
| --- | --- |
| `version`, `engine`, `round` | `1`, `sqlite` or `mysql`, and `1` or `2`. The engine is a reviewed target label, not inferred database proof. |
| `profile_sha256`, `schedule_sha256`, `database_identity` | Exact bindings required by the reducer. |
| `server_kind` | `inert-http-fixture` or `reviewed-http-instrumentation`; the server must confirm its configured kind. |
| `socket` | Exact `path`, `device`, `inode`, `uid`, `pid` supplied by the supervisor. |
| `run_token` | Fresh random 64-character lowercase hex correlation token. |
| `fixture_manifest` | `version: 1`, reviewed `seed_receipt_sha256`, and fixed per-label `routes`. Its canonical digest must equal the profile pin. |
| `response_contract` | `version: 1` and per-label `routes`, each exactly `{"kind":"exact-json-numeric-lexemes-v1"}`. Its canonical digest must equal the profile pin. |
| `synthetic_authorization` | Exact role-to-Authorization map. Only `Bearer repro-fixture-` followed by 32–128 ASCII letters/digits/underscores/hyphens is accepted. No production tokens. |

Each fixed fixture route has exactly `role`, `fixture`, `method`, `path`, and
`query`. The first three must match the reviewed profile. The path must match its
template, with placeholders resolved to bounded ASCII letters/digits/underscores/
hyphens. Query parameters are a separately pinned small map of constrained strings;
the executor URL-encodes them. There is no arbitrary URL, request-header, request-body,
proxy, cookie, shell-command or redirect interface. Use synthetic values only.
The protected input is at most 64 KiB, passes through stdin, and is never included
in output or errors. Keep any input file private mode 0600.

Requests use GET HTTP/1.0, a fixed inert Host header, JSON Accept and identity
encoding. Responses support HTTP/1.0 or HTTP/1.1 with bounded Content-Length or
connection-close framing. One explicit `Connection: close` header is allowed;
duplicate or non-close Connection options are refused. Other hop-by-hop headers,
Location, all redirects, chunked encoding and compression are refused. Headers
are capped at 16 KiB/48 fields, the body at 1 MiB, with an absolute per-request
deadline. These restrictions are suitable for a specifically configured local
gateway, not an arbitrary web server.

Every response must bind the following headers to that exact request:

```text
X-Repro-Run-Token              X-Repro-Request-Nonce
X-Repro-Profile-Sha256         X-Repro-Schedule-Sha256
X-Repro-Engine                 X-Repro-Database-Identity
X-Repro-Sequence               X-Repro-Round
X-Repro-Server-Kind
```

It must also supply measured integer `X-Repro-Query-Count` and
`X-Repro-Database-Warning-Count`. A real application adapter must derive identity
and counters from its verified runtime and request instrumentation, not blindly
echo identity claims or hardcode zero. Correlation nonces/tokens bind a response;
they do not independently prove the server's measurements. Missing or unbound
metrics, malformed responses or transport timeouts abort the round and cancel
its remaining work without retries. No successful receipt is emitted and unknown
counters are never filled with zero. Already-dispatched concurrent requests may
have reached the fixture before cancellation; no rollback or exactly-once business
effect is claimed. Ordinary non-success HTTP statuses with complete instrumentation
can be recorded as error samples and subsequently fail the comparison.

The client hashes the actual JSON response. It sorts object keys, preserves array
order, all fields and exact numeric lexemes (including decimal precision), and
rejects duplicate keys/nonstandard numbers. There is no field-ignore callback or
user-supplied normalization code. Timing ends at complete response reception;
subsequent client JSON normalization is excluded. These are real socket/HTTP
measurements, not PHP-FPM or database measurements unless separately demonstrated.

### Application work still required

Authenticated GETs in this application can write traces and session data:
`SystemOverviewTelemetryMiddleware::terminate` calls
`SystemOverviewTelemetryService::recordRequestTrace`, which can also broadcast.
A future FPM fixture must explicitly contain those writes and provider/broadcast
effects, as well as copied jobs, schedulers, auth state and storage. Disabling
telemetry changes the workload and must be recorded in the pinned profile; it
cannot support a claim of production-equivalent timing. The network-isolated
client does not by itself isolate a separately running server.

The executor unit tests use an inert, same-UID Unix HTTP server with synthetic JSON and
zero actual database queries. They exercise the guarded CLI for both target labels,
two rounds each, real timing, response parity, protected output and receipt
assembly, plus peer/socket, framing, deadline and metric failures. No Laravel,
FPM, database, copied customer data, production credential or provider is involved.

```text
python -B -m unittest discover -s scripts/mysql/workload -p test_http_execute.py -v
```

Real transport tests require unprivileged Linux. The full CLI test additionally
requires `REPRO_HTTP_TEST_BASELINE_NET` from an independently observed, distinct
namespace. The local acceptance run uses the already installed, pinned Python
3.12.3 image with `--network none`, nonroot UID 65532, no capabilities/new privileges,
a read-only root filesystem and bounded tmpfs. A second small network-disabled
container supplies the observed namespace baseline; both are removed afterward.
Windows-only results deliberately skip the Linux proof.

## Local real Laravel and PHP-FPM fixture

`run-local-http-fixture.ps1` now provides a separate first application proof. It
archives the candidate commit, refuses staged/unstaged/untracked application
changes, verifies a supplied locked dependency archive against `composer.lock`,
and starts only pre-existing pinned MySQL 8.4.13 and PHP 8.3 images. It uses two
network-disabled, nonroot containers with no capabilities or new privileges,
read-only roots, bounded memory/CPU/tmpfs, and a private Unix database socket.
It does not use production data, environment files, credentials or providers.

```powershell
pwsh -NoProfile -File scripts/mysql/workload/run-local-http-fixture.ps1 `
  -LockedVendorArchive C:\private\locked-vendor.tar.gz `
  -EvidenceDirectory C:\private\new-http-fixture-evidence
```

The evidence directory must not exist. Docker must already be available with the
two image digests named in the launcher; it never pulls images. The dependency
archive must be readable by fixture UID 1000 and contain the exact locked
production dependencies. No customer files may be supplied. The launcher creates
unique labelled resources and removes its exact containers/socket volume in
`finally`, verifies their absence, and fails if cleanup cannot be confirmed. A
terminated host process still requires an ownership-scoped cleanup. Dependency
archive entries are confined to `vendor/` and cannot replace application code,
the lockfile or bootstrap files; links, traversal and environment files are refused.

`laravel_http_fixture.py` builds fresh schemas using the candidate's normal
migrations, seeds two synthetic clients and distinct notification read states,
and drives the unchanged `http_execute.py` over a fixed Unix HTTP-to-FastCGI
bridge. `laravel-http-fixture.php` wraps the actual HTTP kernel and invokes the
existing `GET /api/notifications` route through its normal Sanctum authentication
and middleware. It registers no replacement route, provider, auth service or
clock. Every round first proves an invalid token receives 401; valid responses
must contain each owner's expected read state and the expected response shape,
including an activity array, so a caught-error fallback is rejected.

The fixed profile is deliberately small and synthetic: two equally weighted
owners, one second each at 2, 4 and 8 arrivals/second, and at most two requests in
flight. It makes 14 valid GETs per engine/round, runs SQLite then MySQL and then
MySQL then SQLite on fresh fixtures, and compares every response field with the
existing exact numeric-lexeme-preserving hash. Its fixed logical-time value is
seed/reference metadata only: the application clock runs normally, and the
report explicitly says `fixed_clock_applied: false`. No production traffic mix
or copied-data timing acceptance is implied.

The private evidence directory retains the four credential-free round fragments,
two assembled receipts, profile and schedule. `result.json` binds the fragment and
receipt hashes; `supervisor.json` records the launcher/helper/image pins and limits.
No HTTP response bodies, auth headers or credentials are retained. Re-run the
existing `http_profile.py compare` against these receipts for independent
arithmetic/parity checking. `cleanup.json` records the exact-resource cleanup
check separately from the request result.

The dependency boundary tests run without a database on Linux:

```text
python -B -m unittest discover -s scripts/mysql/workload -p test_laravel_http_fixture.py -v
```

Application query logging measures the default connection during `handle` and
`terminate`; bootstrap/setup queries and raw transaction-control statements are
outside that count. A separate narrowly granted MySQL monitor connection reads
the application's `performance_schema` thread history without changing the
application PDO's warning/result state. The fixture rejects a wrapped history
boundary and proves the observer detects a deliberate synthetic warning before
requests. SQLite has no equivalent SQL warning channel. Both observers add
overhead, and the report identifies their scope.

Telemetry remains enabled. Every round verifies 14 persisted request traces,
two sessions, preserved owner read states, no system error events and no queued
jobs/messages/calls; its 14 broadcasts use the normal local `log` driver. Thus
these GETs do exercise contained writes. The fixture starts no queue worker or
scheduler. `APP_ENV=testing`, array cache/session, log broadcasting, tmpfs,
disabled opcache and the small two-child FPM pool differ from production. The
wrapper sends the response after `terminate`, so measured time includes
termination, instrumentation, FPM bootstrap and the Unix bridge. Production
nginx/FPM response-release timing is not reproduced.

The 2026-11-23 local run against candidate
`59e58e8349fd856984fb6e5ac5592535dc182328` passed all 56 authenticated GETs
(48 measured, eight warmup) and four 401 probes with zero request errors,
observed database warnings or paired response mismatches. The database remained
running without an OOM flag until cleanup; independent labelled inventories
then found no owned containers or volumes. Its `result.json` SHA-256 is
`354d5cb4f923e6ca92c53ed21723242cb1dde4cfb99b2a339ebf376cf662ba82`.
The six saved fragment/receipt hashes were checked, and a separate invocation of
the comparison CLI reproduced the saved comparison exactly. Four focused Linux
tests also passed the dependency archive boundary checks.

This proves the first real application HTTP slice and its measuring path. It
does not establish production speed, capacity, full role/route coverage,
copied-data behavior, external delivery, queue-worker behavior or cutover
readiness. The report retains `production_readiness: false`; the generic receipt
reducer's execution-attestation fields remain false because it cannot independently
verify the supervisor's evidence. Review the separate supervisor and fixture
checks together with the comparison rather than promoting the reducer alone.
