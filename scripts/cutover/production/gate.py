"""The MySQL write gate's open and close procedures (design v2, sections 2.1, 2.3 and 2.4).

The gate is schema-level: the gated app account holds SELECT only, until `open_gate` grants INSERT,
UPDATE, DELETE on the app schema and UPDATE (`id`) on the writer token. Only the controller changes it,
under its lock: adapter.seal_mysql opens; seal_sqlite, `way-back close` and the owner-approved
`gate-normalise` close. Reading and changing use two different accounts (mysql/accounts.sql):

- every reading is mysql_probe.read() as the probe account, always in measured mode: the strict rule
  shapes only what the probe reports to the runner, and a procedure must see the real state to act;
- the gate account runs GRANT, REVOKE and KILL only. It cannot read the grant tables and never needs to.

close_gate (design 2.3, Close):
  1. closed with no app session: done, without a gate-account login;
  2. open or partial: revoke exactly the privileges present, one statement per level (a REVOKE of an
     absent privilege fails with 1141 or 1147 and revokes nothing, E6 G2 and G3);
  3. list the app sessions with the probe after the revoke, KILL each (1094, already gone, is fine) and
     wait up to KILL_WAIT_SECONDS until none of those IDs remains. A schema-level REVOKE does not reach
     a session that has the database selected (E1 T3b), while a new session gets the closed privileges
     at connect, so a reconnecting reader cannot hold the close up (E6 G5);
  4. the probe must read closed.

open_gate (design 2.3, Open): closed, or open (idempotent, no GRANT); grant the open set, KILL every app
session the probe lists afterwards (they hold the closed privileges from before the GRANT, E1b) and
require the probe to read open. A partial gate is refused: it is closed first. Unlike the close, a stale
session whose KILL fails, or that is still listed after the wait, does not fail the open (it runs after
the runner committed writes_possible, and such a session cannot write): it is recorded in the result
(review B2-5).

The KILLs go to the client on stdin, one per line, with --force: with -e the client stops at the first
error even with --force, so one session that ended by itself (1094) would leave every later one unkilled.

Anything else the probe reads (a role, a routine, a global privilege, a lock, a missing account) is
refused without any change, with the root SQL that restores the closed state of accounts.sql, for the
administrator (design 2.3 step 5, 4.7). A server that is not listening raises GateUnreachable, also
without a change: the grant tables persist, so a stopped server cannot be written, and only the caller
knows whether a skip is allowed (seal_sqlite: only while writes_possible is false, and recorded, 2.4).

Read-only lint, run as root on the host (cli.py gate-check calls check()):

    /usr/bin/python3 -I -S /opt/repro-cutover-fence/production/gate.py --config /etc/repro-cutover-fence/host.json check

Besides the probe's reading it runs the gate account's self-check (review B2-1): a login through
mysql.client and mysql.gate_defaults, as the procedures log in, running SELECT CURRENT_USER() and
SHOW GRANTS (no privilege needed, no change, nothing in the binary log). The account must be
repro_cutover_gate@localhost with exactly the grants accounts.sql gives it, so a wrong password, a lock,
a missing CONNECTION_ADMIN or a missing WITH GRANT OPTION is found before Approval 2 (check-evidence and
the promote preconditions), not at seal_mysql's GRANT after writes_possible.

It prints one JSON object (the reading, with `root_sql` for an unrecognised state, and `gate_account`;
the message and the SQL also go to stderr) and exits 0 for closed or open with the gate account as
accounts.sql makes it, 3 when nothing listens (no self-check), 4 for any other gate state (partial:
gate-normalise closes it; unrecognised or unreadable: the administrator), 5 when the gate reads closed or
open but the gate account's self-check fails (the administrator), 1 on a failure.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import stat
import subprocess
import sys
import time
from dataclasses import dataclass
from pathlib import Path

sys.path[:0] = [str(Path(__file__).resolve().parent), str(Path(__file__).resolve().parent.parent)]
import adapter as host  # noqa: E402
import mysql_probe as probe  # noqa: E402
from adapter import guard  # noqa: E402
from runner import UnsafeCutover  # noqa: E402

# Design 2.3: wait this long for the sessions listed after the change to end.
KILL_WAIT_SECONDS = 10
POLL_SECONDS = 0.2
# The open set, in the order the statements name it: the probe's schema privileges, then the token's.
SCHEMA_PRIVILEGES = tuple(probe.OPEN_SCHEMA.values())
TOKEN_SQL = f"UPDATE (`{probe.TOKEN_COLUMN}`)"
# One error of a KILL script on stdin: code, input line (the n-th KILL), text. 1094 is "Unknown thread id".
KILL_ERROR = re.compile(r"ERROR ([0-9]+) \([0-9A-Z]{5}\) at line ([0-9]+): (.*)")
ENVIRONMENT = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8"}
# A user or host name read from a grant table enters a root statement only if '...' quotes it safely.
SAFE_NAME = r"[A-Za-z0-9_.%:/-]{0,80}"
PROBE_ACCOUNT_SQL = "'{}'@'{}'".format(*probe.PROBE_ACCOUNT.split("@"))
# The account in mysql.gate_defaults (accounts.sql), and one line of its SHOW GRANTS as 8.4 prints it.
GATE_ACCOUNT = "repro_cutover_gate@localhost"
GRANT_LINE = re.compile(r"GRANT (.+?) ON (\S+) TO `([^`]*)`@`([^`]*)`( WITH GRANT OPTION)?")
PRIVILEGE = re.compile(r"[A-Z][A-Z_ ]*[A-Z](?: \(`[^`]+`(?:, `[^`]+`)*\))?")  # INSERT, UPDATE (`id`), ...
EXIT_OBSERVED, EXIT_FAILURE, EXIT_UNREACHABLE, EXIT_NOT_OPEN_OR_CLOSED, EXIT_GATE_ACCOUNT = 0, 1, 3, 4, 5


class GateRefused(UnsafeCutover):
    """The gate was not changed as asked.

    `reading` is the probe's refused output when there is one. `root_sql`, for an unrecognised state, is
    the SQL the administrator runs as MySQL root to restore the closed state; it is also in the message,
    so wherever the refusal is printed (cli.py, the watchdog's journal), the SQL is printed with it.
    """

    def __init__(self, message: str, *, reading: dict | None = None, root_sql: str | None = None):
        if root_sql:
            message = f"{message}\nReview and run this as MySQL root, then the gate close again:\n{root_sql}"
        super().__init__(message)
        self.reading = reading
        self.root_sql = root_sql


class GateUnreachable(GateRefused):
    """Nothing listens at the MySQL socket (probe exit 3); the gate was not changed."""


@dataclass(frozen=True)
class Reading:
    """A measured probe reading the procedures may act on."""

    gate: str  # closed, open or partial
    present: dict  # each gated account -> its open privileges present (probe names), sorted
    sessions: tuple | None  # the gated accounts' session IDs; None when the probe did not list them (partial)


def _refuse_unless(condition: bool, message: str) -> None:
    if not condition:
        raise GateRefused(message)


# The account statements.

def _account(account: str) -> str:
    # load_config accepted only `[a-z][a-z0-9_]*@localhost`; checked again because this text becomes SQL.
    _refuse_unless(guard.matches(host.MYSQL_ACCOUNT, account), "Unsafe gated account name")
    return "'{}'@'{}'".format(*account.split("@"))


def _schema(config: dict) -> str:
    database = config["mysql"]["database"]
    _refuse_unless(guard.matches(host.SQL_NAME, database), "Unsafe app schema name")
    return "`" + database.replace("_", "\\_") + "`.*"  # An unescaped `_` is a wildcard.


def _token(config: dict) -> str:
    control = config["mysql"]["control_database"]
    _refuse_unless(guard.matches(host.SQL_NAME, control), "Unsafe control schema name")
    return f"`{control}`.`{probe.TOKEN_TABLE}`"


def grant_statements(config: dict) -> list[str]:
    """What open_gate runs as the gate account: the open set for each gated account (design 2.3)."""
    statements = []
    for account in config["mysql"]["gated_accounts"]:
        name = _account(account)
        statements.append(f"GRANT {', '.join(SCHEMA_PRIVILEGES)} ON {_schema(config)} TO {name}")
        statements.append(f"GRANT {TOKEN_SQL} ON {_token(config)} TO {name}")
    return statements


def revoke_statements(config: dict, present: dict) -> list[str]:
    """What close_gate runs as the gate account: exactly the open privileges present, one statement per level."""
    accounts = config["mysql"]["gated_accounts"]
    _refuse_unless(isinstance(present, dict) and set(present) <= set(accounts)
                   and all(isinstance(names, list) and set(names) <= probe.OPEN_PRIVILEGES
                           for names in present.values()),
                   f"Not a subset of the open gate: {present!r}")
    statements = []
    for account in accounts:
        names, name = present.get(account, []), _account(account)
        schema = [privilege for privilege in SCHEMA_PRIVILEGES if privilege in names]
        if schema:
            statements.append(f"REVOKE {', '.join(schema)} ON {_schema(config)} FROM {name}")
        if probe.TOKEN_PRIVILEGE in names:
            statements.append(f"REVOKE {TOKEN_SQL} ON {_token(config)} FROM {name}")
    return statements


# The root SQL for an unrecognised state (design 2.3 step 5).

def _rows(state: dict, table: str) -> list[dict]:
    rows = state.get(table)
    if rows is None:  # JSON_ARRAYAGG of no rows.
        return []
    _refuse_unless(isinstance(rows, list) and all(isinstance(row, dict) for row in rows),
                   f"Unexpected probe rows for {table}")
    return rows


def _named(row: dict, side: str, user: str, hostname: str) -> bool:
    return (row.get(f"{side}user"), row.get(f"{side}host")) == (user, hostname)


def _quoted(row: dict, side: str) -> str | None:
    user, hostname = row.get(f"{side}user"), row.get(f"{side}host")
    if guard.matches(SAFE_NAME, user) and guard.matches(SAFE_NAME, hostname):
        return f"'{user}'@'{hostname}'"
    return None


def _by_hand(table: str, row: dict) -> str:
    # JSON escapes every control character, so the row cannot end the comment and start a statement.
    return f"-- mysql.{table} row with a name that needs quoting; remove it by hand: {json.dumps(row, sort_keys=True)}"


def root_sql(config: dict, state: dict) -> str:
    """The SQL that restores the closed state of accounts.sql for every gated account, from the probe's rows.

    Targeted statements remove what REVOKE ALL leaves (roles either way, default roles, proxies either
    way, the lock, a column row without its table grant); REVOKE ALL PRIVILEGES, GRANT OPTION removes every
    other privilege (global, schema, table, column, routine); the two closed grants of accounts.sql end
    it. An account without its mysql.user row cannot be repaired here: it needs its password, so it is
    recreated as accounts.sql does (session A1).
    """
    lines = ["-- Restores the closed write gate of accounts.sql (design 2.3 step 5). Review it, run it as",
             "-- MySQL root, then run the gate close again: it ends every app session and requires the",
             "-- probe to read closed."]
    schema, token = _schema(config), _token(config)
    edges, defaults, proxies = _rows(state, "role_edges"), _rows(state, "default_roles"), _rows(state, "proxies_priv")
    for account in config["mysql"]["gated_accounts"]:
        name = _account(account)
        user, hostname = account.split("@")
        users = [row for row in _rows(state, "user") if _named(row, "", user, hostname)]
        if not users:
            lines += [f"-- {name} has no mysql.user row. Recreate it exactly as accounts.sql does (its CREATE USER",
                      "-- with the password from its root-only file, rendered by mysql/fixture.sh render, then",
                      "-- its two GRANT lines), as in session A1; then run the gate check again."]
            continue
        statements = []
        for row in edges:  # Roles granted to the account; the account granted as a role.
            if _named(row, "", user, hostname):
                role = _quoted(row, "other_")
                statements.append(f"REVOKE {role} FROM {name};" if role else _by_hand("role_edges", row))
            elif _named(row, "other_", user, hostname):
                grantee = _quoted(row, "")
                statements.append(f"REVOKE {name} FROM {grantee};" if grantee else _by_hand("role_edges", row))
        if any(_named(row, "", user, hostname) for row in defaults):
            statements.append(f"SET DEFAULT ROLE NONE TO {name};")
        for row in defaults:  # Another account defaults to this one as a role without the grant itself.
            if _named(row, "other_", user, hostname) and not any(
                    _named(edge, "other_", user, hostname) and _named(edge, "", row["user"], row["host"])
                    for edge in edges):
                grantee = _quoted(row, "")
                statements.append(f"SET DEFAULT ROLE NONE TO {grantee};" if grantee else _by_hand("default_roles", row))
        for row in proxies:
            if _named(row, "", user, hostname):
                proxied = _quoted(row, "other_")
                statements.append(f"REVOKE PROXY ON {proxied} FROM {name};" if proxied
                                  else _by_hand("proxies_priv", row))
            elif _named(row, "other_", user, hostname):
                grantee = _quoted(row, "")
                statements.append(f"REVOKE PROXY ON {name} FROM {grantee};" if grantee
                                  else _by_hand("proxies_priv", row))
        if any(row.get("account_locked") != "N" for row in users):
            statements.append(f"ALTER USER {name} ACCOUNT UNLOCK;")
        statements.append(f"REVOKE ALL PRIVILEGES, GRANT OPTION FROM {name};")
        tables = {(row.get("db"), row.get("table")) for row in _rows(state, "tables_priv")
                  if _named(row, "", user, hostname) and row.get("column_priv")}
        if any(_named(row, "", user, hostname) and (row.get("db"), row.get("table")) not in tables
               for row in _rows(state, "columns_priv")):
            statements += ["-- A column grant row without its table's column grant (direct DML): REVOKE ALL leaves it.",
                           f"DELETE FROM mysql.columns_priv WHERE User = '{user}' AND Host = '{hostname}';",
                           "FLUSH PRIVILEGES;"]
        statements += [f"GRANT SELECT ON {schema} TO {name};", f"GRANT SELECT ON {token} TO {name};"]
        lines += statements
    if state.get("mandatory_roles"):
        lines += ["SET GLOBAL mandatory_roles = '';",
                  "-- and remove mandatory_roles from the server's option files, or a restart sets it again."]
    if "PROCESS" not in (state.get("probe_privileges") or []):
        lines += ["-- The probe needs PROCESS to see app sessions (accounts.sql).",
                  f"GRANT PROCESS ON *.* TO {PROBE_ACCOUNT_SQL};"]
    return "\n".join(lines) + "\n"


# Readings, always through the probe account.

def _client(config: dict, defaults_key: str, statements: list[str], *,
            script: bool = False, deadline: float | None = None) -> subprocess.CompletedProcess:
    """Run statements with the native client as the account in `mysql.<defaults_key>`, flags as the probe's.

    Normally with -e: the client stops at the first error. With `script` they go on stdin, one per line,
    with --force: every statement runs, and each error names its line (`ERROR n (s) at line i: ...`).
    """
    mysql = config["mysql"]
    client, defaults = Path(mysql["client"]), Path(mysql[defaults_key])
    try:
        guard.check_chain(client)
        guard.check_chain(defaults)
        guard.require(not stat.S_IMODE(defaults.lstat().st_mode) & 0o077, f"{defaults} must be readable by root only")
    except (guard.Refused, OSError) as refusal:
        raise GateRefused(f"Unsafe or missing MySQL client or defaults file: {refusal}") from None
    argv = [str(client), f"--defaults-file={defaults}", "--no-login-paths", "--protocol=SOCKET",
            f"--socket={mysql['socket']}", "--batch", "--raw", "--skip-column-names",
            f"--connect-timeout={probe.CONNECT_SECONDS}"]
    if script:
        argv.append("--force")
        feed = {"input": "".join(f"{statement};\n" for statement in statements)}
    else:
        argv += ["-e", "; ".join(statements)]
        feed = {"stdin": subprocess.DEVNULL}
    try:
        return subprocess.run(argv, **feed, capture_output=True, text=True,
                              timeout=probe.timeout_seconds(probe.CLIENT_SECONDS, deadline),
                              env=ENVIRONMENT)
    except subprocess.TimeoutExpired:
        raise GateRefused("The MySQL client did not finish; the gate state is unknown until the next reading") from None


def _classified_rows(config: dict, refusal: probe.Refusal) -> dict:
    """The rows behind an unrecognised reading, for root_sql only: the very state document the probe classified
    (mysql_probe.read_gate hands it out on the refusal; it is never printed), so no second read is needed.

    Refused unless they were read as the probe account on the target server, so nothing is derived from another
    account's view: a CURRENT_USER() mismatch only makes the gate unrecognised (mysql_probe.Reading).
    """
    reading = refusal.reading
    _refuse_unless(reading is not None and isinstance(reading.state, dict)
                   and reading.state.keys() == probe.STATE_KEYS and isinstance(reading.server, dict),
                   "The probe handed out no rows for this reading")
    _refuse_unless(reading.server.get("current_user") == probe.PROBE_ACCOUNT
                   and reading.server.get("server_uuid") == config["mysql"]["target_uuid"],
                   "The rows were not read as the probe account on the target server")
    return reading.state


def _present(config: dict, open_privileges) -> dict:
    accounts = config["mysql"]["gated_accounts"]
    _refuse_unless(isinstance(open_privileges, dict) and set(open_privileges) <= set(accounts),
                   "The probe named open privileges for an account outside the gate")
    present = {account: sorted(open_privileges.get(account, [])) for account in accounts}
    revoke_statements(config, present)  # Refuses any name outside the open set.
    return present


def read(config: dict, *, deadline: float | None = None) -> Reading:
    """The probe's measured reading; raise GateUnreachable or GateRefused for anything not to act on."""
    try:
        _, gate = probe.read(config, "measured", deadline=deadline)
    except probe.Refusal as refusal:
        output = refusal.output or {}
        if refusal.code == probe.EXIT_UNREACHABLE:
            raise GateUnreachable("The MySQL server is not listening; the gate was not changed",
                                  reading=output) from None
        if refusal.code == probe.EXIT_UNRECOGNISED and output.get("gate") == "partial":
            return Reading("partial", _present(config, output.get("open_privileges")), None)
        message = f"The gate state is {output.get('gate') or 'not readable'}: {refusal}"
        if refusal.code == probe.EXIT_UNRECOGNISED and output.get("gate") == "unrecognised":
            message = f"The gate state is unrecognised: {'; '.join(output.get('problems') or [])}"
            try:
                sql = root_sql(config, _classified_rows(config, refusal))
            except GateRefused as unreadable:
                raise GateRefused(f"{message}. No root SQL: {unreadable}", reading=output) from None
            raise GateRefused(message, reading=output, root_sql=sql) from None
        raise GateRefused(message, reading=output) from None
    except guard.Refused as refusal:
        raise GateRefused(f"The gate state could not be read: {refusal}") from None
    return Reading(gate.name, _present(config, gate.open_privileges), tuple(gate.sessions))


# The procedures.

def _run_gate(config: dict, statements: list[str], *, deadline: float | None = None) -> None:
    result = _client(config, "gate_defaults", statements, deadline=deadline)
    if result.returncode != 0:
        # The client stops at the first error; the next reading shows what was applied.
        raise GateRefused(f"The gate account's statements failed: {result.stderr.strip()[:500]}")


def _kill(config: dict, listed: list[int], *, deadline: float | None = None) -> dict:
    """KILL each listed session as the gate account; return {session: error} for each KILL that failed.

    A script on stdin, one KILL per line, so every KILL runs and an error names the KILL it belongs to.
    1094 (unknown thread) for its own ID means the session had already gone. An error that names no line
    (a refused login, a client error) or a client failure leaves the other KILLs unaccounted for: failed.
    """
    result = _client(config, "gate_defaults", [f"KILL {int(session)}" for session in listed], script=True, deadline=deadline)
    failed, gone, unaccounted = {}, set(), []
    for line in (line for line in result.stderr.splitlines() if line.strip()):
        match = KILL_ERROR.fullmatch(line)
        index = int(match.group(2)) - 1 if match else -1
        if not 0 <= index < len(listed):
            unaccounted.append(line)
        elif match.group(1) == "1094" and match.group(3) == f"Unknown thread id: {listed[index]}":
            gone.add(listed[index])
        else:
            failed[listed[index]] = line
    if unaccounted or result.returncode != 0:
        reason = "; ".join(unaccounted) or f"the MySQL client exited with status {result.returncode}"
        failed.update({session: failed.get(session, reason) for session in listed if session not in gone})
    return failed


def _wait_gone(config: dict, sessions: list[int], wait_seconds: float, state: str, *,
               deadline: float | None = None) -> list[int]:
    """Poll the probe until none of `sessions` remains, at most `wait_seconds`; return those still listed.

    Only these IDs are waited for (connection IDs are never reused while the server runs): sessions that
    connect after the change carry its privileges and may come and go. Every reading must stay `state`.
    """
    poll_until = time.monotonic() + wait_seconds
    while True:
        reading = read(config, deadline=deadline)
        _refuse_unless(reading.gate == state, f"The gate reads {reading.gate} while its app sessions end")
        remaining = sorted(set(sessions) & set(reading.sessions))
        if not remaining or time.monotonic() >= poll_until:
            return remaining
        time.sleep(probe.timeout_seconds(POLL_SECONDS, deadline))


def _end_sessions(config: dict, sessions: tuple, wait_seconds: float) -> list[int]:
    """The close's rule: KILL the listed sessions and wait until none remains, or refuse; return them."""
    if not sessions:
        return []
    listed = sorted(sessions)
    failed = _kill(config, listed)
    if failed:
        raise GateRefused(f"KILL of the app sessions failed: {'; '.join(dict.fromkeys(failed.values()))[:500]}")
    remaining = _wait_gone(config, listed, wait_seconds, "closed")
    if remaining:
        raise GateRefused(f"App sessions {remaining} were still connected {wait_seconds} s after KILL")
    return listed


def close_gate(config: dict, *, wait_seconds: float = KILL_WAIT_SECONDS) -> dict:
    """Close the gate (design 2.3, Close). Returns {"before", "revoked", "killed"} for the caller's record.

    Strict: a failed KILL, or a killed session still listed after `wait_seconds`, refuses the close (the
    rollback retries it). A closed gate with no app session needs no gate-account login.
    """
    reading = read(config)
    before = reading.gate
    if before == "closed" and not reading.sessions:
        return {"before": before, "revoked": {}, "killed": []}
    revoked = {}
    if before != "closed":
        _run_gate(config, revoke_statements(config, reading.present))
        revoked = {account: names for account, names in reading.present.items() if names}
        reading = read(config)
        _refuse_unless(reading.gate == "closed", f"The gate reads {reading.gate} after the revoke")
    # Also for a gate that already reads closed: a close that crashed between its REVOKE and its KILL
    # leaves sessions that still write.
    killed = _end_sessions(config, reading.sessions, wait_seconds)
    return {"before": before, "revoked": revoked, "killed": killed}


def _journal_phase(config: dict) -> str | None:
    """The committed journal phase (the file the runner and the probe read), or None without a journal."""
    path = Path(config["paths"]["state"]) / "state.json"
    try:
        return guard.read_json(guard.read_protected(path)).get("phase")
    except FileNotFoundError:
        return None
    except (guard.Refused, OSError, ValueError) as error:
        raise GateRefused(f"The cutover journal could not be read: {error}") from None


def open_gate(config: dict, *, wait_seconds: float = KILL_WAIT_SECONDS, deadline: float | None = None) -> dict:
    """Open the gate (design 2.3, Open; seal_mysql only).

    Returns {"before", "granted", "killed", "kill_failed", "stragglers"}: of the app sessions listed once
    the gate reads open (they hold the closed privileges from before the GRANT), `killed` ended,
    `kill_failed` got a KILL error and `stragglers` were still listed `wait_seconds` after their KILL.
    Neither of the last two fails the open (review B2-5): they cannot write, the open runs after the
    runner committed writes_possible, and only the probe's open reading is required. C02 records them.

    Refused before any MySQL login in drill mode (design 3.4) and unless the runner has committed
    writes_possible, which it does just before it calls seal_mysql: in every other phase SQLite may
    still be live or MySQL must not take its first writes yet.
    """
    _refuse_unless(config["mysql"]["mode"] == "cutover", "The gate never opens in drill mode (design 3.4)")
    phase = _journal_phase(config)
    _refuse_unless(phase == "writes_possible",
                   f"The gate opens only once the runner has committed writes_possible (journal: {phase or 'none'})")
    reading = read(config, deadline=deadline)
    before = reading.gate
    _refuse_unless(before in ("closed", "open"),
                   f"The gate is {before}; it is opened only from closed (close it first)")
    granted = before == "closed"
    if granted:
        _run_gate(config, grant_statements(config), deadline=deadline)
        reading = read(config, deadline=deadline)
        _refuse_unless(reading.gate == "open", f"The gate reads {reading.gate} after the grant")
    listed = sorted(reading.sessions)
    failed = _kill(config, listed, deadline=deadline) if listed else {}
    ending = [session for session in listed if session not in failed]
    stragglers = _wait_gone(config, ending, wait_seconds, "open", deadline=deadline) if ending else []
    return {"before": before, "granted": granted,
            "killed": [session for session in ending if session not in stragglers],
            "kill_failed": sorted(failed), "stragglers": stragglers}


# The gate account's self-check (review B2-1).

def gate_account_grants(config: dict) -> frozenset:
    """What accounts.sql grants the gate account, as (privilege, level, with grant option) in SHOW GRANTS' names."""
    schema, token = _schema(config), _token(config)
    return frozenset({*((name, schema, True) for name in SCHEMA_PRIVILEGES), (TOKEN_SQL, token, True),
                      ("CONNECTION_ADMIN", "*.*", False), ("PROCESS", "*.*", False)})


def _privileges(text: str) -> list[str] | None:
    """`INSERT, UPDATE (`id`, `x`), PROCESS` -> its privileges; None if it is not such a list.

    8.4 joins static privileges with ', ' and dynamic ones with ',' (`CONNECTION_ADMIN,SYSTEM_VARIABLES_ADMIN`,
    review W2F-4); either separator is read, nothing else.
    """
    names, position = [], 0
    while (match := PRIVILEGE.match(text, position)) is not None:
        names.append(match.group())
        position = match.end()
        if position == len(text):
            return names
        if text.startswith(", ", position):
            position += 2
        elif text.startswith(",", position):
            position += 1
        else:
            return None
    return None


def _grants(line: str) -> set | None:
    """One SHOW GRANTS line of the gate account as triples; None for any other line (a role, a REVOKE, ...)."""
    match = GRANT_LINE.fullmatch(line)
    names = _privileges(match.group(1)) if match else None
    if names is None or f"{match.group(3)}@{match.group(4)}" != GATE_ACCOUNT:
        return None
    return {(name, match.group(2), bool(match.group(5))) for name in names if name != "USAGE"}


def grant_problems(config: dict, lines: list[str]) -> list[str]:
    """How the gate account's SHOW GRANTS lines differ from what accounts.sql grants it; [] when exactly equal."""
    expected, found, problems = gate_account_grants(config), set(), []
    for line in lines:
        grants = _grants(line)
        if grants is None:
            problems.append(f"unexpected grant line: {line[:200]}")
        else:
            found |= grants

    def named(name: str, level: str, option: bool = False) -> str:
        return f"{name} ON {level}{' WITH GRANT OPTION' if option else ''}"

    lacking = {(name, level) for name, level, option in expected - found if option and (name, level, False) in found}
    problems += [f"{named(name, level)} lacks WITH GRANT OPTION" for name, level in sorted(lacking)]
    problems += [f"missing {named(*grant)}" for grant in sorted(expected - found) if grant[:2] not in lacking]
    problems += [f"unexpected {named(*grant)}" for grant in sorted(found - expected) if grant[:2] not in lacking]
    return problems


def _login_problem(config: dict, result: subprocess.CompletedProcess) -> str:
    """Why the gate account's login failed, from the client's first line of error text.

    The client's error names the account and whether a password was sent, never the password. With nothing on
    stderr it is the first stdout line: for an expired password the 8.4.13 client prints only
    "Please use --connect-expired-password option ..." there and exits 1 (review W2F-4); a failed login prints
    nothing else, and what the two statements print (the account and its grants) holds no secret either.
    """
    lines = result.stderr.strip().splitlines() or result.stdout.strip().splitlines() or ["no error text"]
    error = lines[0][:300]
    defaults = config["mysql"]["gate_defaults"]
    if error.startswith("ERROR 1045 "):
        return f"The gate account's login was refused: a wrong password in {defaults}, or no such account ({error})"
    if error.startswith("ERROR 3118 "):
        return f"The gate account is locked ({error})"
    if error.startswith("ERROR 1862 ") or "--connect-expired-password" in error:
        return (f"The gate account's password has expired: as MySQL root, set it again to the password in "
                f"{defaults} ({error})")
    return f"The gate account could not log in ({error})"


def gate_account_check(config: dict, *, deadline: float | None = None) -> dict:
    """Log in as the gate account, as the procedures do, read-only: {"current_user", "problems"}.

    SELECT CURRENT_USER() and SHOW GRANTS (of the current account) need no privilege, change nothing and
    write nothing to the binary log. The login must be GATE_ACCOUNT, with exactly gate_account_grants.
    """
    try:
        result = _client(config, "gate_defaults", ["SELECT CURRENT_USER()", "SHOW GRANTS"], deadline=deadline)
    except GateRefused as refusal:
        return {"current_user": None, "problems": [str(refusal)]}
    if result.returncode != 0:
        return {"current_user": None, "problems": [_login_problem(config, result)]}
    lines = result.stdout.splitlines()
    current = lines[0] if lines else None
    if current != GATE_ACCOUNT:
        defaults = config["mysql"]["gate_defaults"]
        return {"current_user": current,
                "problems": [f"CURRENT_USER() is {current!r}, not {GATE_ACCOUNT}: {defaults} holds another account"]}
    return {"current_user": current, "problems": grant_problems(config, lines[1:])}


def _with_account(message: str | None, account: dict) -> str | None:
    if not account["problems"]:
        return message
    failed = f"The gate account's self-check failed: {'; '.join(account['problems'])}"
    return f"{message}\n{failed}" if message else failed


def check(config: dict, *, deadline: float | None = None) -> tuple[dict, int, str | None]:
    """Read-only lint: (output, exit code, message), the probe's reading plus the gate account's self-check."""
    try:
        reading = read(config, deadline=deadline)
    except GateUnreachable as refusal:
        return ({"version": 1, "reachable": False, "gate": None, "root_sql": None, "gate_account": None},
                EXIT_UNREACHABLE, str(refusal))
    except GateRefused as refusal:
        output, account = refusal.reading or {}, gate_account_check(config, deadline=deadline)
        return ({"version": 1, "reachable": True, "gate": output.get("gate"),
                 "problems": output.get("problems") or [str(refusal).splitlines()[0]],
                 "open_privileges": output.get("open_privileges"), "root_sql": refusal.root_sql,
                 "gate_account": account},
                EXIT_NOT_OPEN_OR_CLOSED, _with_account(str(refusal), account))
    account = gate_account_check(config, deadline=deadline)
    output = {"version": 1, "reachable": True, "gate": reading.gate, "open_privileges": reading.present,
              "sessions": list(reading.sessions or []), "root_sql": None, "gate_account": account}
    if reading.gate == "partial":
        return output, EXIT_NOT_OPEN_OR_CLOSED, _with_account("The gate is partial: gate-normalise closes it", account)
    if account["problems"]:
        return output, EXIT_GATE_ACCOUNT, _with_account(None, account)
    return output, EXIT_OBSERVED, None


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--config", type=Path, required=True)
    parser.add_argument("action", choices=("check",))
    args = parser.parse_args(argv)
    try:
        if sys.platform != "linux" or os.geteuid() != 0:
            raise guard.Refused("The gate check runs as root on the Linux host")
        config = host.load_config(guard.read_protected(args.config), read=guard.read_protected)
        output, code, message = check(config)
    except (guard.Refused, OSError, ValueError, TypeError, KeyError) as error:
        print(f"Gate check failed: {error}", file=sys.stderr)
        return EXIT_FAILURE
    print(json.dumps(output, sort_keys=True))
    if message:
        print(f"Gate check: {message}", file=sys.stderr)
    return code


if __name__ == "__main__":
    raise SystemExit(main())
