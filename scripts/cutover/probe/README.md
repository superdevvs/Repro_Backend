# Cutover engine probe and smoke

App side of the MySQL cutover smoke (adapter lane design v2, sections 1.3 and 5.3).
It ships in the normal SQLite release so the drill and the cutover can use it.

| Piece | Run by | What it does |
| --- | --- | --- |
| `php artisan cutover:smoke --engine-only --json` | every wrapper profile (user, group, cwd, umask, environment) | Engine, database, `@@server_uuid` (MySQL), process identity and the raw route values of the live config. Reads only, in a read-only session (`PRAGMA query_only` or `SET SESSION TRANSACTION READ ONLY`). |
| `php artisan cutover:smoke --read-only --json` | `www-data` and the deploy user, before Approval 2 | The above, then the marked test client and test shoot are read through the `User` and `Shoot` models, the client's encrypted value must decrypt, and nothing may wait on `database:cutover-smoke`. Every read runs in the read-only session. |
| `php artisan cutover:smoke --write --json` | `admit.py cutover-smoke`, first in the MySQL reopen | The read-only checks, then `company_notes` is set on both test rows with the query builder (no model event), read back, and one `CutoverSmokeJob` (does nothing) is queued on `database:cutover-smoke` and run in the same process with `queue:work database --queue=cutover-smoke --once --stop-when-empty`. Refuses before writing if the smoke queue is paused or busy or the fixtures lost inertness. Writes only `users`, `shoots`, `aryeo_changes` (shoot triggers) and `jobs`. |
| `php artisan cutover:smoke-fixtures [--create] [--json]` | the deploy user, once with release D | Creates (or reports) the inert test client (`users` row, `cutover-smoke@repro-cutover.invalid`, role `client`, `inactive`, one encrypted value in `two_factor_secret`) and test shoot (`internal_test`, `cancelled`, no payment due, no provider IDs, no person, no schedule). Refuses rows inside any Aryeo scope. Prints the IDs for `host.json` `smoke`. |
| `fpm-engine.php` | root's FastCGI client, straight to the FPM socket | Runs as the pool user, bootstraps the app without handling a request, prints the same values as `--engine-only` plus the OPcache state. Refuses root, and refuses to boot when `bootstrap/cache/packages.php` or `services.php` is missing (booting would write them). Logging goes to a null handler. HTTP 200 when ok, 500 otherwise. |

Exit codes: 0 ok, 1 a check failed (see `problems`), 2 usage error.

## Route values and the shared vectors

PHP prints the route values raw; Python hashes them. `route-hash-vectors.json`
holds the contract and the vectors both sides test: the allowlist, the
`password` to `password_set` rule, the url scheme rule and the canonical JSON
form. On the lane the file belongs at
`scripts/cutover/production/testdata/route-hash-vectors.json`; on the release D
branch it lives here.

## Tests

```sh
php vendor/bin/phpunit tests/Feature/CutoverSmokeTest.php tests/Feature/CutoverSmokeFixturesTest.php
python3 -B -m unittest discover -s scripts/cutover/probe
```

The PHPUnit probe tests run `fpm-engine.php` on the CLI and must run as a
user other than root (as root they assert the refusal instead).
`test_fpm_engine.py` needs root, `php-fpm` and a readable `vendor/`: it starts
a private pool as `www-data` and is skipped elsewhere.
