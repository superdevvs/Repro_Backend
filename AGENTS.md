# Backend rules for coding agents

This is the Laravel 12 backend (PHP 8.3). Read these rules before you change code.

## The database: SQLite now, MySQL 8.4 next

Production runs on SQLite. The approved plan moves it to MySQL 8.4 (a native,
socket-only server). Until then every change ships to production on SQLite.

- Every change must work on SQLite and on MySQL 8.4, and must be safe on SQLite first.
- **Never change the isolation level to fix a race.** The plan fixes MySQL at
  READ COMMITTED. Fix a race with a transaction, a row lock taken inside that
  transaction, a unique index, or `App\Support\LockedWrite`.
- Do not add SQL that only SQLite understands. Examples: `json_each`, `||` to join
  text, `DATE('now')`, `strftime`, `CAST(x AS INTEGER)` or `AS TEXT`, two-argument
  `MAX(a, b)` or `MIN(a, b)`, `INSERT OR IGNORE`, `PRAGMA`, `sqlite_master`. Use the
  query builder, or a helper that works on both engines.
- Do not add new engine branches (`getDriverName()`, `PDO::ATTR_DRIVER_NAME`, `'sqlite'`).
  If one is truly needed, add it to `tests/database-engine-branches.json`, marked
  `safe` or `to-fix`, with a reason.
- The guard test `tests/Unit/DatabaseDialectGuardTest.php` fails on new SQLite-only SQL
  and on unlisted engine branches. `tests/database-dialect-baseline.json` lists what
  exists today. It may only shrink. Never add entries to it to make the guard pass.
- Code that opens the SQLite file directly (`new PDO('sqlite:...')`, `database.sqlite`)
  must check the engine first and refuse clearly on MySQL. No silent fallback.

## Writing to the database

- Keep transactions short. Send emails, make HTTP calls (Stripe and others) and copy
  files after the commit, never inside the transaction.
- No DDL or `TRUNCATE` inside a transaction. No database cache lock taken while a
  transaction is open.
- `lockForUpdate()` and `sharedLock()` only protect anything inside a transaction.
- "Create if missing" (`firstOrCreate`, `updateOrCreate`) outside a transaction needs a
  unique index or a lock.
- The count returned by `insertOrIgnore` may decide what happens next only where
  nothing else can fail. Money and dedupe paths use a plain insert and catch the
  duplicate.
- Avoid writes on every request. Request telemetry is written after the response;
  the token's last-used time is saved at most every five minutes. Use
  `App\Support\SchemaFacts` for table and column checks on request paths.

## Reading and comparing

- Dates, times and JSON are stored as text on both engines. Use Laravel's date filters.
  Do not apply date functions to text date columns in an `UPDATE` or `DELETE`.
- A limited or paginated query must end its `ORDER BY` with a unique column (for
  example `id`), so both engines return the same rows.
- Raw `LIKE`: lowercase both sides. Backslash is an escape character only on MySQL.
- Put SQL text in single quotes. Double quotes mean a column or table name.
- Cast `sum()` and `avg()` results that reach JSON or a strict comparison. MySQL can
  return a float where SQLite returned a whole number.

## Tests

- Write the failing test first, see it fail, then make it pass.
- SQLite suite: `php vendor/bin/phpunit`. It must stay green. CI runs it in shards.
- Run `tests/Unit/DatabaseDialectGuardTest.php` after any change to queries.
- The MySQL profile (`phpunit.mysql.xml`, `.github/workflows/mysql.yml`) arrives with the
  migration branch. Follow it when it is on `main`.

## Production and safety

- Read-only checks on production are fine. Every production write, config change or
  deploy needs the owner's approval for that action.
- No real message, call or charge from any copy of production data.
- Only the lead session runs `scripts/cutover`.
- Never read or print the backup bucket key, the encryption key or values from `.env`.
- Risky new behaviour ships behind a config flag that is off by default.
- Worktrees live outside the Dashboard folder and are removed once merged.
