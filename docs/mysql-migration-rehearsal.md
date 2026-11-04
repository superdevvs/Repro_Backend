# SQLite to MySQL migration rehearsal

This branch is an isolated migration candidate. The November 5 continuation
made no production connections or changes. Production was last verified on
SQLite during the earlier rehearsal. Deployment remains outside the current
authorization, and the outstanding gates below still apply.

## Copy and isolation

The rehearsal starts from the deployed backend `f87b87038c7beb5d036f40236647fe7daab8c407`
and frontend `821ec42a6f015ab2454076381fec2a976449752f`. The isolated backend
candidate was then advanced to the previously verified deployed release
`f89a6ab77147189554aa13688c7ab45f1105bda2`, retaining its Studio watermark
change. Work in the primary checkouts was preserved. Compare and merge any
subsequent releases before cutover.

A consistent SQLite online backup was created with the live source opened
read-only. The snapshot passed its integrity check. SHA-256:
`42f5e221e7a204ee20f1c80b616295a80af7b14ab3a8964dd72a772cba56cdee`.
All 229 original tables and 1,130,891 original rows matched after import,
using row counts and canonical field hashes. Text was compared exactly;
dates, timestamp instants and numeric values were compared by meaning.

The current lab target is MySQL **8.4.13**, pinned to Docker image digest
`sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902`.
Strict mode, foreign-key checks, binary logging, `sync_binlog=1` and
`innodb_flush_log_at_trx_commit=1` remain enabled. Test accounts and source
volumes are separate from the copied application data. The final MySQL suite
uses two disjoint file shards on independent local servers.

The local MySQL 8.4 preview, separate SQLite reference and regression database
have separate storage. Application and test accounts cannot access each other's
databases. The app and test networks block outbound traffic, mail uses the array
transport, and no scheduler or queue worker runs. The preview accepts Windows
loopback connections only. No production environment file or APP_KEY was copied.
Existing encrypted provider credentials therefore cannot be exercised here.

Media references were preserved, but original media bytes have not been
bulk-copied. Passing metadata or fixture tests does not prove all stored media,
production provider connections or mobile clients work after cutover.

## Approved historical exceptions

The owner approved preserving existing history and documenting exceptions.
Do not delete financial/audit rows or invent replacement parent records.

| Table | Existing orphan rows preserved |
| --- | ---: |
| account_links | 1 |
| ai_video_vertical_variants | 6 |
| invoice_audit_events | 1 |
| invoice_items | 7 |
| payment_service_allocations | 2 |
| system_email_dispatches | 178 |
| Total distinct rows | 195 |

These rows contain 223 missing references across eight relationships; one row
can have more than one missing reference. Ten rows concern invoices, invoice
audit events or payment allocations. Exact IDs and broken parent references
are retained in the protected lab manifest, not committed customer data.

The import verified the exact frozen exception set, installed all 379 original
foreign keys, and restored `foreign_key_checks=1`. Checks were suspended only
for the eight affected constraint additions. Eight rollback-only negative tests
confirmed new invalid references were rejected. Enabling foreign-key checks
does not retroactively validate existing rows in MySQL; the explicit manifest
and independent data checks are required.

The optional messaging-column repair adds one valid foreign key, bringing the
candidate to 380. A fresh production snapshot needs a fresh exception audit;
new exceptions must not be silently accepted using this old manifest.

## Compatibility changes

The candidate retains binary identifier equality while making application LIKE
searches case-insensitive. SQL helpers cover JSON scalar/null handling, string
concatenation, text casts and literal wildcard escaping. SQLite paths remain
available for regression testing and rollback.

Queries now select physical storage fields rather than virtual accessors. API
numeric values are explicitly cast where MySQL DECIMAL retrieval otherwise
changed JSON numbers into strings. Duplicate names and tied dashboard timestamps
have explicit ID ordering. Existing HTML/entity/Unicode email search uses the
same PHP normalizer on authorized MySQL message candidates; performance still
needs a measured acceptance check.

Two import widenings preserve existing values: `services.delivery_time` retains
legacy text and `voice_calls.duration_seconds` retains fractional durations.
Seven index names are shortened deterministically to fit MySQL's name limit.
Calendar dates and timestamp instants are checked independently.

Further checks corrected filtered invoice status SQL, strict grouped payout
summaries, numeric voice-duration serialization and binary-collation UNIONs.
Studio transaction history retains a numeric credit sequence when combining
integer credit IDs with card UUIDs. A same-timestamp regression across IDs 9 and
10 verifies correct newest-first pagination; the displayed transaction IDs and
account scoping remain unchanged.
The connector reports matched rows for no-op updates, matching SQLite's
contract; repeated browser/SIP heartbeats therefore remain successful while
failed conditional updates still report zero. Default and featured shoot lists
now use ID tie-breakers so tied timestamps cannot repeat or omit rows between
pages.

Public-photo revision hashes remove only MySQL's whole-second timestamp
padding. All 236,715 original photo rows have identical normalized signature
inputs. Existing public signatures still require the original production
APP_KEY at eventual cutover. Revocation, replacement, scan, payment and
cross-shoot denial tests remain in the suite.

Independent PHP workers reproduced double credit reservations, excess unpaid
orders, duplicate worker claims and a repeated-payment error. Per-account order
admission and a consistent order-then-item locking order now protect the real
quote, credit, order, settlement and outbox services. Permanent races also cover
exact request replay, completion versus cancellation, concurrent partial
refunds, payment versus cancellation, and single-use password resets. External
providers and media preparation are replaced only at their boundaries.

Studio operational telemetry now uses an independent MySQL autocommit session
with a one-second lock wait. Rejection counters survive business rollback;
telemetry contention cannot alter the business session's timeout or expose SQL
or identifiers in its generic failure log. SQLite's existing bounded telemetry
path remains available.

The schema baseline contains table/index/constraint definitions, change-feed
triggers and applied migration names, with no customer rows. Historical SQLite
table rebuilds had lost nine intended Aryeo triggers; the MySQL candidate
restores their coverage and guards invoice-only payments with no shoot.
MySQL foreign-key cascades do not activate child triggers. Ten additional parent
triggers cover direct and transitive cascades and SET NULL effects, bringing the
candidate to 35 triggers. Five MySQL regression cases verify service, category
and client deletion, invoice-only payment deletion, and invoice detachment of
an allocated payment. The static cascade definitions must be reviewed when
foreign-key relationships change.

The new messaging repair restores six nullable contact/thread columns that had
been lost despite their migrations remaining in the ledger. It preserves all
existing values and supports signed IDs from conversion. Rollback does not
drop these pre-existing contract fields or newly saved messaging data.

A second schema repair makes the legacy equipment owner nullable, matching
current validation and fresh migrations. Saved owners and the existing foreign
key/delete behavior remain intact. Rollback never invents an owner for equipment
saved without one. A third repair completes the historical nullable invoice-role
contract that had been skipped on SQLite despite its applied ledger entry. It
preserves the default and all existing values. The baseline contains 367 migration
names, 229 tables,
380 foreign keys, 783 non-primary indexes (177 unique and 606 non-unique),
15 checks and 35 triggers. This inventory includes MySQL's supporting FK indexes.

An independent fresh-SQLite schema audit found all 222 current application
tables and every current column in the MySQL copy. Seven additional historical
tables are retained. The legacy required automation-rule ID remains unchanged:
the current dispatcher writes it from a saved rule. Historical nullable message
recipient/source fields remain nullable to preserve existing values.

The invoice date-sort index uses an explicit `DATETIME(6)` cast, matching the
MySQL application's sort expression. A plain `COALESCE` functional index could
restore successfully under mysqldump's temporarily relaxed SQL mode but leave a
hidden-column default that blocked later unrelated table changes in strict mode.
The regression test rebuilds the candidate's actual expression under that dump
mode, restores strict mode, and verifies a later unrelated column change succeeds.

## Regression testing

`phpunit.mysql.xml` requires the separately permissioned `repro_mysql_test`
database and `repro_test` account. Initialize it with `artisan migrate:fresh`
before running the profile. Set `DB_CONNECTION=mysql` and
`DB_DATABASE=repro_mysql_test` in the runner's process environment as well as
the PHPUnit profile. Both the environment and Laravel's actual booted connection
are guarded. Tests must never point at the preview database.

The MySQL profile uses the converted schema baseline, followed by new PHP
migrations. Factory template/rule fixtures come from an empty, freshly migrated
SQLite database with zero users; they contain no copied production records.
This reproduces the defaults historically installed by data migrations skipped
when a schema baseline is loaded.

SQLite WAL/schema-specific fixtures are explicitly identified. SQLite-only
historical schema surgery and repair-command tests remain in the SQLite suite.
The MySQL directory separately tests simultaneous writers and lost updates.
Security rollback tests inject database delete failures without DDL: MySQL DDL
implicitly commits and cannot be used inside a rollback fixture transaction.
Timestamp assertions compare the expected second rather than engine formatting
of an otherwise identical `DATETIME(6)` value.

Exploratory MySQL inventories exposed application defects, schema drift,
missing factory defaults and SQLite-specific fixtures. These diagnostic runs
remain archived separately from acceptance results. MySQL DDL implicitly commits;
tests that need real commits use a separately guarded truncation fixture and
restore any temporary schema fault before teardown. They leave no synthetic
rows for later tests. Auto-increment gaps after rollback are supported rather
than assuming every new identity equals one.

The final complete MySQL profile (Feature and native MySQL tests) ran
5,988 distinct cases across 662 files on two independent servers:
5,976 passed, 12 explicit engine-specific skips, 114,510 assertions,
zero failures and zero errors. Both runners returned exit code zero with warning,
risky-test and PHPUnit-deprecation failure flags enabled. The two historical
SQLite fixture files excluded by the profile remain documented in its XML.

The complete SQLite inventory ran 7,572 tests: 7,567 passed, with five fixture
failures caused by public DNS being blocked. Those fixtures retain email-health
validation and replace only DNS for their example domains; all five now pass.
Later affected runs passed 1,039, 218, 34, 40 and 16 tests, covering the changed
areas through the final financial-history/bootstrap checks. These runs overlap
and are not summed as unique tests. All 122 changed PHP files passed syntax
checks; Composer validation and Git whitespace checks passed.

The app, both full-suite source volumes and the final worktree have identical
runtime/test manifests. Documentation is updated separately from the frozen
runtime. Runtime/test manifest SHA-256:
`09ebd4fdabdc08a951741609fce8c16c99862a99488b3d2674cdba003fcf0b6b`.

After testing, every original row was checked again as a per-table multiset of
canonical field hashes: all 1,130,891 originals remained across all 229 tables,
with no missing or changed originals. Additional rows were confined to lab
accounts, authentication state, migration names, notification-read state and
operational telemetry. The final invoice/equipment schema changes were followed
by a second check of their 5,783 original rows, also with zero differences.

The corrected 367-migration candidate was backed up and restored to a separate
local database. All 229 tables matched in row values, semantic DDL, exact column
metadata and next auto-increment values; all 35 triggers, 380 foreign keys,
177 unique constraints and 15 checks matched. Redundant charset rendering in
`SHOW CREATE TABLE` is the only DDL normalization. The complete exercise took
552.8 seconds while other QA jobs ran; this is not a production outage estimate.
Protected artifact: `private/mysql-candidate-20261104T203620Z.sql.gz`,
304,460,162 bytes, SHA-256
`fa3a5265ffa64716508753c574173553c7f465b959477654a81b50e2c6f32fb4`.

The MySQL profile explicitly identifies engine-specific SQLite WAL/EXPLAIN
fixtures and historical support-table rebuilds. Current role, opt-out, denied
access, schema, native concurrency and business behavior continue to run against
MySQL. Malformed SQLite text-date fixtures remain covered on SQLite; MySQL tests
verify strict rejection and exact UTC/microsecond/DST boundaries instead.

Four native queue checks use the actual framework `database` and `studio`
connections with synthetic jobs. Independent PHP processes reserve one job only
once; delayed release retains payload and attempts; Studio dispatch waits for
commit and disappears on rollback; failed-job payloads can be stored and removed.
These checks never invoke a job handler. Actual production worker/provider
acceptance remains a separate future gate.

Read-only API comparisons covered 20 checks across five copied roles
(client, photographer, sales representative, editor and editing manager), plus
19 admin reads. Allowed and denied statuses and business values matched.
The admin reads include invoice filters/review, paginated bookings, account search,
messaging and dashboard data. Two unsupported invoice filter values retain the
same HTTP 422 response on both engines. The synthetic lab account's timestamps
and notification-read fixture were aligned before the final comparisons.

Response-specific request IDs are ignored. Signed local media URLs retain the
same asset paths and non-signature parameters; differing signatures/expiry values
are reported explicitly. Numeric roundoff below 1e-8 is also recorded explicitly.
The dashboard rendered real overview/shoot data with no JavaScript page errors.
Original media bytes were not bulk-copied; some images are unavailable and CSP
blocks external avatars. Browser HTTP and WebSocket traffic outside loopback was
blocked. This does not constitute live media, provider, realtime or mobile acceptance.

After the full suites finished, eight alternating warmed API samples per engine
returned HTTP 200 throughout. Timings are milliseconds; with only eight samples,
the reported p95 is also the slowest sampled request.

| Endpoint | MySQL median | SQLite median | MySQL slowest | SQLite slowest |
| --- | ---: | ---: | ---: | ---: |
| Shoot list | 125.7 | 102.3 | 133.2 | 136.4 |
| Dashboard overview | 75.2 | 74.7 | 98.5 | 85.5 |
| Invoice list | 265.5 | 227.8 | 277.8 | 247.6 |
| Email search | 1,185.5 | 1,528.9 | 1,326.6 | 1,737.9 |

Each engine also completed sixteen shoot-list reads at concurrency four without
HTTP errors. These local smoke timings use the same copied business data and
equal PHP allocations, but MySQL has additional server resources. Mixed endpoint
results and this small sample do not demonstrate an overall production speedup.

Eight alternating executions on the same MySQL data compared the final
cast-based invoice index with the index ignored: median SQL time was
2.23 ms indexed and 9.02 ms with the old plan. Both returned
the identical 25 page IDs in the identical order. The protected EXPLAIN JSON
records estimated access/sort plans separately from measured query timings.
API latency includes other work; representative sustained production-like load
and tail latency remain open gates.

## Remaining gates before production

1. Reconcile the candidate with releases made since the recorded backend/frontend
   base, preserving unrelated ongoing work. Repeat affected acceptance checks
   after that reconciliation.
2. Verify real stored media, encrypted provider configuration, webhooks, mobile
   clients and every actual worker connection in a future authorized environment.
   Copied-data API reads and synthetic provider boundaries have a narrower scope.
3. Benchmark representative sustained requests and simultaneous business
   operations with equivalent data/resources, including email-search cost,
   tail latency, lock/deadlock behavior and failure recovery. An engine switch
   alone is not evidence of better overall performance.
4. Provision a separate MySQL 8.4 LTS production target and validate its exact
   version/configuration in a future authorized phase. The earlier rehearsal
   observed an existing `8.0.46-0ubuntu0.24.04.4` service and rejected unattended
   database login. Neither that service nor production access was revisited
   during this continuation. Production database/account provisioning remains
   an administrator task; no production upgrade has been performed.
5. Adapt guarded deployment/backup scripts for MySQL, review the complete release
   against current production, and rehearse the all-writer drain, fresh-snapshot
   import, cutover timings and reconciliation of writes during rollback.

## Eventual cutover sequence

Never promote the used preview database. Keep the production APP_KEY, user
credentials, IDs, media paths and provider configuration intact.

At an agreed window, block new mutations, durably handle incoming webhooks,
drain in-flight HTTP requests, and stop **all** writers. Inventory both system
and user Supervisor groups, every database queue connection (including Studio,
voice, mail, integrations and archives), cron/scheduler and archive workers.
Maintenance mode alone does not stop queue/CLI writers.

Take a fresh consistent safety backup, import it into a newly provisioned empty
MySQL database, and audit all data, exception manifests, constraints and sequence
counters again. Apply the tested migration candidate and clear/rebuild config
with the web account and permissions appropriate to production. Switch the web
app and all worker connections together. Start with controlled acceptance and
verify queue reservation, failed-job handling and provider idempotency before
reopening general writes. Monitor errors, latency, locks/deadlocks and delivery.

Retain the frozen SQLite database and prior application release. Before any
new MySQL writes, a rollback can restore the old application/configuration.
After MySQL accepts new writes, switching to the frozen SQLite file would lose
those writes: stop mutations, preserve a MySQL backup and reconcile the delta
before any switch back. Do not promise an instant lossless rollback after writes.

Operational lab files and private evidence are under
`C:\Users\shubh\.codex\repro-mysql-lab\20261104`. Credentials and row-level
exception manifests remain protected there and must not be committed.
