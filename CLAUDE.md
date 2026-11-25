# Claude Code instructions

Follow AGENTS.md. It holds the full rules for this backend, so they live in one place.

@AGENTS.md

The rules that matter most:

- Production runs on SQLite and moves to MySQL 8.4. Every change must work on both and be
  safe on SQLite first.
- **Never change the isolation level to fix a race.**
- `tests/Unit/DatabaseDialectGuardTest.php` blocks new SQLite-only SQL and unlisted engine
  branches. Its baseline may only shrink.
- Every production write, config change or deploy needs the owner's approval.
