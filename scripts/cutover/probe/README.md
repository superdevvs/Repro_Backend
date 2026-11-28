# Cutover engine probe and smoke

App side of the MySQL cutover smoke (adapter lane design v2, sections 1.3 and 5.3).
It ships in the normal SQLite release so the drill and the cutover can use it.

| Piece | Run by | What it does |
| --- | --- | --- |
| `php artisan cutover:smoke --engine-only --json` | every wrapper profile (user, group, cwd, umask, environment) | Engine, database, `@@server_uuid` (MySQL), process identity and the raw route values of the live config. Reads only, in a read-only session (`PRAGMA query_only` or `SET SESSION TRANSACTION READ ONLY`). |
| `php artisan cutover:smoke --read-only --json` | `www-data` and the deploy user, before Approval 2 | The above, then the marked test client and test shoot are read through the `User` and `Shoot` models, the client's encrypted value must decrypt, and nothing may wait on `database:cutover-smoke`. Every read runs in the read-only session. |
| `php artisan cutover:smoke --write --json` | `admit.py cutover-smoke`, first in the MySQL reopen | The read-only checks, then `company_notes` is set on both test rows with the query builder (no model event), read back, and one `CutoverSmokeJob` (does nothing) is queued on `database:cutover-smoke` and run in the same process with `queue:work database --queue=cutover-smoke --once --stop-when-empty`. Refuses before writing if the smoke queue is paused or busy (a leftover row: see "Leftover smoke job" below) or the fixtures lost inertness. Writes only `users`, `shoots`, `aryeo_changes` (shoot triggers) and `jobs`. |
| `php artisan cutover:smoke-fixtures [--create] [--json]` | the deploy user, once with release D | Creates (or reports) the inert test client (`users` row, `cutover-smoke@repro-cutover.invalid`, role `client`, `inactive`, one encrypted value in `two_factor_secret`) and test shoot (`internal_test`, `cancelled`, no payment due, no provider IDs, no person, no schedule). Refuses rows inside any Aryeo scope. Prints the IDs for `host.json` `smoke`. |
| `fpm-engine.php` | root's FastCGI client, straight to the FPM socket | Runs as the pool user, bootstraps the app without handling a request, prints the same values as `--engine-only` plus the OPcache state. Refuses root, and refuses to boot when `bootstrap/cache/packages.php` or `services.php` is missing (booting would write them). Logging goes to a null handler. HTTP 200 when ok, 500 otherwise. |

Exit codes: 0 ok, 1 a check failed (see `problems`), 2 usage error.

`cutover:smoke` and `cutover:smoke-fixtures` refuse to run as root (effective
uid 0) before any query: exit 1, one `refused: ...` problem, and for
`cutover:smoke` `mode` is null. Run them as `www-data` or the deploy user.

## Leftover smoke job

`cutover:smoke --read-only` and `--write` fail while any row waits on
`database:cutover-smoke` ("N jobs are pending on database:cutover-smoke"), and
the smoke never deletes rows itself. A row is left behind only when a write
smoke died between queueing its `CutoverSmokeJob` and its worker deleting it
(killed or timed out). Every later write smoke, a retried MySQL reopen
included, then refuses until the row is removed by hand.

Run these statements with the database's own client as the app's account, on
the database the `database` queue connection uses (the default connection
unless `DB_QUEUE_CONNECTION` names another). First look:

```sql
SELECT id, attempts, reserved_at, available_at, created_at, SUBSTR(payload, 1, 160) AS payload_start FROM jobs WHERE queue = 'cutover-smoke';
```

Every row must name the job as `"displayName":"App\\Jobs\\CutoverSmokeJob"`
(JSON-escaped) in `payload_start`. If one
names another job, stop: something else used the smoke queue, and that row is
not the smoke's to delete. Then delete the unreserved smoke jobs, and only
those:

```sql
DELETE FROM jobs WHERE queue = 'cutover-smoke' AND reserved_at IS NULL AND payload LIKE '%CutoverSmokeJob%';
```

A row whose `reserved_at` is set was taken by a worker, and the DELETE leaves
it. If `pgrep -af 'cutover:smoke|cutover-smoke'` shows a smoke or a worker on
that queue, wait for it to end. If nothing runs, the worker died holding the
row, which then stays reserved for the queue's `retry_after` (2100 s): release
it, then run the DELETE above again.

```sql
UPDATE jobs SET reserved_at = NULL WHERE queue = 'cutover-smoke' AND reserved_at IS NOT NULL AND payload LIKE '%CutoverSmokeJob%';
```

Afterwards `cutover:smoke --read-only` must show `smoke_queue_pending: 0`.

On MySQL after the gate opened, these statements are a business write outside
the recorded smoke window, so the short way back (design v2, 4.3) is no longer
available afterwards, only 4.2. Ask the owner before running them there.

## Route values and the shared vectors

PHP prints the route values raw; Python hashes them.
`scripts/cutover/production/testdata/route-hash-vectors.json` holds the
contract and the vectors both sides test: the allowlist, the `password` to
`password_set` rule, the url scheme rule and the canonical JSON form. It is
the one copy: the controller's live config module (D03) and its route-hash
tests (D08) read the same file. The release D branch kept it in this folder;
the lane moved it.

## Tests

```sh
php vendor/bin/phpunit tests/Feature/CutoverSmokeTest.php tests/Feature/CutoverSmokeFixturesTest.php
python3 -B -m unittest discover -s scripts/cutover/probe
```

The PHPUnit probe tests run `fpm-engine.php` on the CLI and must run as a
user other than root (as root they assert the refusal instead). The smoke
command tests run as any user: when PHPUnit runs as root,
`tests/Concerns/CutoverSmokeRootStandIn.php` binds an unprivileged stand-in uid
to the commands' one seam, `CutoverEngineProbe::EFFECTIVE_UID_SEAM` (a
container binding; nothing outside the PHP process can set it). The refusal
tests bind 0, and one test reads the real uid without the seam. The probe tests
give every path in the vectors a temporary-directory prefix before any process
reads them, so no test can open the production database or socket the vectors
name; the hashes stay pinned in `test_route_hash_vectors.py`.
`test_fpm_engine.py` needs root, `php-fpm` and a readable `vendor/`: it starts
a private pool as `www-data` and is skipped elsewhere. It also runs both smoke
commands as the real root and expects the refusal.

`tests/MySql/CutoverSmokeMySqlTest.php` is the MySQL test (server UUID, the
read-only session as a SELECT-only account, the write smoke's tables in the
binary log), in the `phpunit.mysql.xml` profile. Its header lists the two extra
test accounts it needs; `.github/workflows/mysql.yml` provisions them. The two
SQLite feature tests above skip on MySQL and point to it.
