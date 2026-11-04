# SQLite to MySQL migration rehearsal

This branch is an isolated migration candidate. Production remains on SQLite.
It is not eligible for deployment until the outstanding gates below pass.

## Copy and isolation

The rehearsal starts from the deployed backend `f87b87038c7beb5d036f40236647fe7daab8c407`
and frontend `821ec42a6f015ab2454076381fec2a976449752f`. The isolated backend
candidate was then advanced to the currently deployed
`f89a6ab77147189554aa13688c7ab45f1105bda2`, retaining its Studio watermark
change. Work in the primary checkouts was preserved. Compare and merge any
subsequent releases before cutover.

A consistent SQLite online backup was created with the live source opened
read-only. The snapshot passed its integrity check. SHA-256:
`42f5e221e7a204ee20f1c80b616295a80af7b14ab3a8964dd72a772cba56cdee`.
All 229 original tables and 1,130,891 original rows matched after import,
using row counts and canonical field hashes. Text was compared exactly;
dates, timestamp instants and numeric values were compared by meaning.

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

## Regression testing

`phpunit.mysql.xml` requires the separately permissioned `repro_mysql_test`
database and `repro_test` account. Initialize it with `artisan migrate:fresh`
before running the profile. Tests must never point at the preview database.

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

The first broad MySQL inventory was stopped after 1,684 passing tests,
42 failures and 115 errors, before completion. It exposed application defects,
missing schema columns, missing factory fixtures and SQLite-specific tests.
These counts are not a passing regression result. Read the latest protected
reports for subsequent runs; do not treat earlier targeted passes as full-suite
or production acceptance.

Subsequent focused validation passed 494 SQLite tests / 4,095 assertions,
46 MySQL tests / 311 assertions, and 126 MySQL core/Studio tests / 902 assertions
with one explicitly SQLite-WAL-only skip. These MySQL runs overlap; do not sum
their counts as unique tests. Both failures in the earlier 495-test affected
run were corrected and rerun. All 59 changed PHP files passed syntax checks;
Composer validation passed. Full-suite acceptance remains open.

Read-only API comparisons covered five copied roles: client, photographer,
sales representative, editor and editing manager. Allowed and denied statuses
matched. Signed media links have different signatures/expiry values on the
separate local origins; asset paths are compared separately. Binary media
bytes were not bulk-copied, so some preview images are intentionally unavailable.

A small local warmed-cache smoke benchmark returned HTTP 200 throughout.
Median MySQL/SQLite milliseconds were: shoots 109/100, overview 68/67, invoices
245/188 and email search 978/1,283. Sixteen four-way concurrent shoot reads
finished without errors on either engine. These are eight-sample local timings
with extra resources allocated to the separate MySQL server, not evidence of
an overall production speedup. Invoice latency, business-write contention and
representative production load remain acceptance work.

## Gates before production

1. Finish regression suites on both engines; resolve and classify remaining
   failures without weakening authorization, accounting or scheduling checks.
2. Compare real copied data through role-scoped APIs and UI. Check positive and
   denied paths, dates/timezones, booking/editing/delivery, invoice/payment
   idempotency, downloads, search and all queue connections.
3. Verify Aryeo changes for direct writes and cascading deletes/updates.
4. Benchmark representative requests and simultaneous business operations with
   equivalent data/resources, including email-search cost and tail latency.
   An engine switch alone is not evidence of better overall performance.
5. Complete a backup/restore rehearsal for the final candidate, including rows,
   schema, trigger definitions, constraints and next auto-increment values.
6. Select/provision the production target and validate against that exact
   version/configuration. The existing server advertises MySQL
   `8.0.46-0ubuntu0.24.04.4`; this was read from an unauthenticated greeting.
   The lab uses 8.4. The existing production service has not been upgraded.
   The SSH account's unattended MySQL login was rejected;
   an administrator-provisioned database/account is still needed.
7. Adapt guarded deployment/backup scripts for MySQL, review the complete release
   against current production, and rehearse the cutover and rollback timings.

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
