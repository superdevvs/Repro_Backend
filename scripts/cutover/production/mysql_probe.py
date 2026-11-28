"""MySQL target probe for the cutover controller: the write gate and business activity (design v2, 1.2, 2.2, 4.3).

    /usr/bin/python3 -I -S /opt/repro-cutover-fence/production/mysql_probe.py --config /etc/repro-cutover-fence/host.json
        [--audit | --since <file>:<position> [--since-gtid <gtid set>]]

Runs as root, reads files and MySQL, writes only the activity latch, and prints one JSON object. It runs
the native 8.4.13 client by absolute path as the probe account (`mysql.probe_defaults`), over the socket
in host.json, and reads the gated accounts' rows in the nine grant tables, `mandatory_roles` and
`CURRENT_USER()`:

- closed: SELECT on the app schema and on the token, nothing else;
- open: the same plus INSERT, UPDATE, DELETE on the app schema and UPDATE (`id`) on the token;
- partial: any other subset of those four with everything else exactly closed (gate.py can close it);
- unrecognised: anything else (a role, a default or mandatory role, a routine or proxy grant, a global
  privilege, a lock, another schema or table row, a missing account, `--skip-grant-tables`).

Business activity is measured from the receipt that `prepare` writes (`paths.receipt`), valid only for
the journal's run: binlog_scan.py reads `SHOW BINLOG EVENTS` from its `armed_from` coordinate (paged by
offset, every later file from its start) and splits the app-schema transactions at the first account
statement (the boundary) into foreign (before it) and business (after it). The first business hit is
latched in `paths.activity`; once the MySQL reopen has written `paths.activated` the probe reports
business activity from that record without scanning, so a later purge cannot fail it.

`--audit` lists every app-schema transaction after `armed_from` as foreign, smoke (inside the smoke
window of `paths.smoke_window`, only `smoke_tables`) or business (design 4.3). `--since f:p` lists them
from any coordinate and reports the coordinate read before the scan (`until`), for `prepare` (3.2
steps 4 and 10); `--since-gtid` adds the receipt's history check (exit 5 when gtid_executed no longer
holds the given set). Both only read.

Exit codes: 0 observed; 3 the server is not listening (stdout {"version": 2, "reachable": false});
4 gate state not recognised or not readable, a binary log this probe cannot read, a receipt or
activation record of this run for another server or database, or a non-closed reading or business
activity outside the frozen window; 5 the binary log at the receipt's `armed_from` (or `--since`)
coordinate is gone, or its history was reset; 6 wrong server (UUID differs from `target_uuid`,
version not 8.4.13, networking on, or, when a scan runs, a binary log filter: `SHOW BINARY LOG STATUS`
names a Binlog_Do_DB or Binlog_Ignore_DB); anything else is a probe failure (1). Strict mode (no journal,
`planned`, `sqlite_authoritative`) exits 0 only for "closed" with no business activity, so it can
never report a writable target or business writes while SQLite is live.
"""

from __future__ import annotations

import argparse
import errno
import json
import os
import re
import socket
import stat
import subprocess
import sys
import time
from dataclasses import dataclass, field
from pathlib import Path

sys.path[:0] = [str(Path(__file__).resolve().parent), str(Path(__file__).resolve().parent.parent)]
import adapter as host  # noqa: E402
import binlog_scan  # noqa: E402
from adapter import guard  # noqa: E402
from binlog_scan import Coordinate, GtidSet, Unreadable  # noqa: E402

VERSION = 2
AUTHENTICATED_SMOKE_WINDOW_VERSION = 2
EXIT_FAILURE, EXIT_UNREACHABLE, EXIT_UNRECOGNISED, EXIT_PURGED, EXIT_WRONG_SERVER = 1, 3, 4, 5, 6
PROBE_ACCOUNT = "repro_cutover_probe@localhost"
TOKEN_TABLE, TOKEN_COLUMN = "writer_token", "id"
# Every privilege column of mysql.user and mysql.db on 8.4.13 (31 and 19); accounts.sql grants the
# probe exactly the user columns, never authentication_string.
USER_PRIVILEGES = (
    "Select_priv", "Insert_priv", "Update_priv", "Delete_priv", "Create_priv", "Drop_priv", "Reload_priv",
    "Shutdown_priv", "Process_priv", "File_priv", "Grant_priv", "References_priv", "Index_priv", "Alter_priv",
    "Show_db_priv", "Super_priv", "Create_tmp_table_priv", "Lock_tables_priv", "Execute_priv", "Repl_slave_priv",
    "Repl_client_priv", "Create_view_priv", "Show_view_priv", "Create_routine_priv", "Alter_routine_priv",
    "Create_user_priv", "Event_priv", "Trigger_priv", "Create_tablespace_priv", "Create_role_priv", "Drop_role_priv",
)
DB_PRIVILEGES = (
    "Select_priv", "Insert_priv", "Update_priv", "Delete_priv", "Create_priv", "Drop_priv", "Grant_priv",
    "References_priv", "Index_priv", "Alter_priv", "Create_tmp_table_priv", "Lock_tables_priv", "Create_view_priv",
    "Show_view_priv", "Create_routine_priv", "Alter_routine_priv", "Execute_priv", "Event_priv", "Trigger_priv",
)
# What seal_mysql grants (design 2.3), by the name gate.py revokes.
OPEN_SCHEMA = {"Insert_priv": "INSERT", "Update_priv": "UPDATE", "Delete_priv": "DELETE"}
TOKEN_PRIVILEGE = "UPDATE(id)"
OPEN_PRIVILEGES = frozenset({*OPEN_SCHEMA.values(), TOKEN_PRIVILEGE})
# Journal phases (runner.py). Outside the frozen window SQLite may be live: only "closed" is accepted.
STRICT_PHASES = frozenset({"planned", "sqlite_authoritative"})
MEASURED_PHASES = frozenset({"holding", "frozen", "ready", "writes_possible", "mysql_authoritative",
                             "recovery_required"})
CONNECT_SECONDS = 10
CLIENT_SECONDS = 30
# The client's own report that nothing listens at the socket: no such file (2) or refused (111).
NOT_LISTENING = re.compile(
    r"ERROR 2002 \(HY000\): Can't connect to local MySQL server through socket '[^'\n]*' \((?:2|111)\)")
# Columns read from each grant table, as JSON keys; `user`/`host` name the account a row belongs to.
ACCOUNT_KEYS = {"user": "User", "host": "Host"}
TABLES = {
    "user": ("mysql.user", {**ACCOUNT_KEYS, "account_locked": "account_locked",
                            **{name: name for name in USER_PRIVILEGES}}),
    "global_grants": ("mysql.global_grants", {"user": "USER", "host": "HOST", "privilege": "PRIV"}),
    "role_edges": ("mysql.role_edges", {"user": "TO_USER", "host": "TO_HOST",
                                        "other_user": "FROM_USER", "other_host": "FROM_HOST"}),
    "default_roles": ("mysql.default_roles", {"user": "USER", "host": "HOST",
                                              "other_user": "DEFAULT_ROLE_USER", "other_host": "DEFAULT_ROLE_HOST"}),
    "procs_priv": ("mysql.procs_priv", {**ACCOUNT_KEYS, "db": "Db", "routine": "Routine_name",
                                        "privilege": "Proc_priv"}),
    "proxies_priv": ("mysql.proxies_priv", {**ACCOUNT_KEYS, "other_user": "Proxied_user",
                                            "other_host": "Proxied_host"}),
    "db": ("mysql.db", {**ACCOUNT_KEYS, "db": "Db", **{name: name for name in DB_PRIVILEGES}}),
    "tables_priv": ("mysql.tables_priv", {**ACCOUNT_KEYS, "db": "Db", "table": "Table_name",
                                          "table_priv": "Table_priv", "column_priv": "Column_priv"}),
    "columns_priv": ("mysql.columns_priv", {**ACCOUNT_KEYS, "db": "Db", "table": "Table_name",
                                            "column": "Column_name", "column_priv": "Column_priv"}),
}
# Grant tables where any row naming a gated account, in either column pair, is an extra write path.
NO_ROWS = ("global_grants", "role_edges", "default_roles", "procs_priv", "proxies_priv")
SERVER_KEYS = {"server_uuid", "version", "skip_networking", "current_user"}
STATE_KEYS = {*TABLES, "mandatory_roles", "probe_privileges", "sessions"}
CLIENT_ENVIRONMENT = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8"}
# The records written under the journal directory (design 1.2, 3.2, 3.6). The receipt by prepare (D10),
# the latch by this probe, the activation and the smoke window by the MySQL reopen (C03).
RECEIPT_KEYS = {"version", "run_id", "server_uuid", "database", "backup", "armed_from"}
BACKUP_KEYS = {"file", "position", "gtid_executed"}
COORDINATE_KEYS = {"file", "position"}
LATCH_KEYS = {"version", "run_id", "receipt_sha256", "gtid", "file", "position", "tables", "statements",
              "boundary", "foreign_activity"}
ACTIVATED_KEYS = {"version", "run_id", "server_uuid", "database", "receipt_sha256"}
LISTING = "SHOW BINARY LOG STATUS; SHOW BINARY LOGS"


class Refusal(Exception):
    """A reading the controller must not act on: `output` is printed, then the probe exits with `code`.

    `reading` (a Reading, never printed) is set when the refusal came after the gate was classified.
    """

    def __init__(self, code: int, message: str, output: dict | None = None, reading: Reading | None = None):
        super().__init__(message)
        self.code = code
        self.output = output
        self.reading = reading


class Purged(Exception):
    """The binary log history a scan starts from is gone (exit 5)."""


@dataclass
class Gate:
    name: str
    problems: list[str] = field(default_factory=list)
    open_privileges: dict[str, list[str]] = field(default_factory=dict)
    sessions: list[int] = field(default_factory=list)


def _literal(value: str) -> str:
    # Only names load_config accepted reach SQL; anything that would need escaping is refused.
    guard.require(re.fullmatch(r"[a-z][a-z0-9_]{0,63}", value) is not None, "Unsafe name for the probe query")
    return f"'{value}'"


def _accounts(config: dict) -> list[tuple[str, str]]:
    return [tuple(account.split("@")) for account in config["mysql"]["gated_accounts"]]


def server_statement() -> str:
    return ("SELECT JSON_OBJECT('server_uuid', @@GLOBAL.server_uuid, 'version', VERSION(), "
            "'skip_networking', @@GLOBAL.skip_networking, 'current_user', CURRENT_USER())")


def state_statement(accounts: list[tuple[str, str]]) -> str:
    """One JSON document with every gate input, so values are escaped by the server, never split."""
    pairs = ", ".join(f"({_literal(user)}, {_literal(hostname)})" for user, hostname in accounts)
    parts = []
    for name, (table, columns) in TABLES.items():
        where = f"({columns['user']}, {columns['host']}) IN ({pairs})"
        if "other_user" in columns:
            where += f" OR ({columns['other_user']}, {columns['other_host']}) IN ({pairs})"
        fields = ", ".join(f"'{key}', {column}" for key, column in columns.items())
        parts.append(f"'{name}', (SELECT JSON_ARRAYAGG(JSON_OBJECT({fields})) FROM {table} WHERE {where})")
    users = ", ".join(_literal(user) for user, _ in accounts)
    parts.append("'sessions', (SELECT JSON_ARRAYAGG(JSON_OBJECT('id', ID, 'user', USER)) "
                 f"FROM performance_schema.processlist WHERE USER IN ({users}))")
    parts.append("'probe_privileges', (SELECT JSON_ARRAYAGG(PRIVILEGE_TYPE) FROM information_schema.USER_PRIVILEGES "
                 "WHERE GRANTEE = '''repro_cutover_probe''@''localhost''')")
    parts.append("'mandatory_roles', @@GLOBAL.mandatory_roles")
    return f"SELECT JSON_OBJECT({', '.join(parts)})"


def server_problems(server: dict, target_uuid: str) -> list[str]:
    """Why this is not the pinned target server (exit 6): UUID, version 8.4.13, socket only.

    The version is pinned to the patch release (review A13): the scan reads SHOW BINLOG EVENTS text as
    8.4.13 prints it, so an upgraded server holds the site until the scan is retested.
    """
    problems = []
    if server["server_uuid"] != target_uuid:
        problems.append(f"Server UUID {server['server_uuid']} is not the target {target_uuid}")
    if server["version"] != binlog_scan.SERVER_VERSION:
        problems.append(f"Server version {server['version']} is not {binlog_scan.SERVER_VERSION}, "
                        "whose binary log text the activity scan reads")
    if server["skip_networking"] != 1:
        problems.append("The server accepts network connections (skip_networking is off)")
    return problems


def _rows(state: dict, name: str) -> list[dict]:
    rows = state[name]
    if rows is None:  # JSON_ARRAYAGG of no rows.
        return []
    keys = set(TABLES[name][1]) if name in TABLES else {"id", "user"}
    guard.require(isinstance(rows, list) and all(isinstance(row, dict) and row.keys() == keys for row in rows),
                  f"Unexpected probe output for {name}")
    return rows


def classify(config: dict, server: dict, state: dict) -> Gate:
    """Compare the gated accounts' grant rows with the exact closed and open states (design 1.2)."""
    mysql = config["mysql"]
    guard.require(isinstance(state, dict) and state.keys() == STATE_KEYS, "Unexpected probe output")
    schema = mysql["database"].replace("_", "\\_")
    token = (mysql["control_database"], TOKEN_TABLE)
    gate = Gate("closed")
    problems = gate.problems
    if server["current_user"] != PROBE_ACCOUNT:
        problems.append(f"CURRENT_USER() is {server['current_user']!r}, not {PROBE_ACCOUNT} "
                        "(another account in the probe defaults file, or --skip-grant-tables)")
    if state["mandatory_roles"] != "":
        problems.append(f"mandatory_roles is set: {state['mandatory_roles']!r}")
    if "PROCESS" not in (state["probe_privileges"] or []):
        problems.append("The probe account lacks PROCESS, so it cannot see app sessions")
    for name in NO_ROWS:
        for row in _rows(state, name):
            problems.append(f"mysql.{name} names a gated account: {row}")
    gate.sessions = sorted(int(row["id"]) for row in _rows(state, "sessions"))
    states = []
    for user, hostname in _accounts(config):
        account = f"{user}@{hostname}"
        mine = {name: [row for row in _rows(state, name) if (row["user"], row["host"]) == (user, hostname)]
                for name in ("user", "db", "tables_priv", "columns_priv")}
        present = set()
        users = mine["user"]
        if len(users) != 1:
            problems.append(f"{account}: {len(users)} mysql.user rows, expected one")
        for row in users:
            problems.extend(f"{account}: global privilege {name}" for name in USER_PRIVILEGES if row[name] != "N")
            if row["account_locked"] != "N":
                problems.append(f"{account}: the account is locked")
        databases = mine["db"]
        if [row["db"] for row in databases] != [schema]:
            problems.append(f"{account}: schema grants {[row['db'] for row in databases]}, expected [{schema!r}]")
        for row in databases:
            if row["db"] != schema:
                continue
            if row["Select_priv"] != "Y":
                problems.append(f"{account}: no SELECT on {schema}")
            for name in DB_PRIVILEGES[1:]:
                if name in OPEN_SCHEMA and row[name] == "Y":
                    present.add(OPEN_SCHEMA[name])
                elif row[name] != "N":
                    problems.append(f"{account}: {name} on {schema}")
        tables, columns = mine["tables_priv"], mine["columns_priv"]
        if len(tables) != 1 or (tables[0]["db"], tables[0]["table"]) != token or tables[0]["table_priv"] != "Select":
            problems.append(f"{account}: table grants {tables}, expected SELECT on {'.'.join(token)}")
        elif tables[0]["column_priv"] == "Update" and [
                (row["db"], row["table"], row["column"], row["column_priv"]) for row in columns
        ] == [(*token, TOKEN_COLUMN, "Update")]:
            present.add(TOKEN_PRIVILEGE)
        elif tables[0]["column_priv"] != "" or columns:
            problems.append(f"{account}: token column grants are neither closed nor open: {tables} {columns}")
        gate.open_privileges[account] = sorted(present)
        states.append(present)
    if problems:
        gate.name = "unrecognised"
    elif all(present == OPEN_PRIVILEGES for present in states):
        gate.name = "open"
    elif any(states):
        gate.name = "partial"
    return gate


def read_journal(config: dict) -> dict | None:
    path = Path(config["paths"]["state"]) / "state.json"
    try:
        return guard.read_json(guard.read_protected(path))
    except FileNotFoundError:
        return None


def mode_of(journal: dict | None) -> str:
    """strict with no journal or outside the frozen window, measured inside it (verifier correction 3)."""
    if journal is None:
        return "strict"
    phase = journal.get("phase")
    if phase in STRICT_PHASES:
        return "strict"
    guard.require(phase in MEASURED_PHASES, "The cutover journal has an unknown phase")
    return "measured"


def journal_mode(config: dict) -> str:
    return mode_of(read_journal(config))


def journal_run(journal: dict) -> str:
    """The run the journal belongs to (the runner writes the pinned identity into it)."""
    identity = journal.get("identity")
    guard.require(isinstance(identity, dict) and guard.matches(host.RUN_ID, identity.get("run_id")),
                  "The cutover journal names no run, so no receipt can be bound to it")
    return identity["run_id"]


def _protected(path: Path, *, secret: bool = False) -> None:
    guard.check_chain(path)
    if secret:
        guard.require(not stat.S_IMODE(path.lstat().st_mode) & 0o077, f"{path} must be readable by root only")


def timeout_seconds(maximum: float, deadline: float | None) -> float:
    """Limit each read to its share of a single monotonic deadline."""
    if deadline is None:
        return maximum
    remaining = deadline - time.monotonic()
    if remaining <= 0:
        raise TimeoutError("The MySQL gate check reached its deadline")
    return min(maximum, remaining)


def _listening(path: str, deadline: float | None = None) -> bool:
    probe = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    try:
        probe.settimeout(timeout_seconds(CONNECT_SECONDS, deadline))
        probe.connect(path)
    except OSError as error:
        if error.errno in (errno.ENOENT, errno.ECONNREFUSED):
            return False
        raise
    finally:
        probe.close()
    return True


def _client_argv(mysql: dict, *, raw: bool) -> list[str]:
    """The native client as the probe account over the socket, ending in `-e` (the statement follows).

    `--raw` only for the gate read, whose JSON the server escapes. SHOW output keeps the client's batch
    escaping, or a newline inside a statement's text would split its row.
    """
    return [mysql["client"], f"--defaults-file={mysql['probe_defaults']}", "--no-login-paths", "--protocol=SOCKET",
            f"--socket={mysql['socket']}", "--batch", *(["--raw"] if raw else []), "--skip-column-names",
            f"--connect-timeout={CONNECT_SECONDS}", "-e"]


def _json_line(line: str, keys: set[str]) -> dict:
    try:
        value = json.loads(line)
    except ValueError:
        value = None
    guard.require(isinstance(value, dict) and value.keys() == keys, "Unexpected probe output")
    return value


@dataclass(frozen=True)
class Reading:
    """One gate read: the server identity, the state document the gate was classified from, the gate.

    `state` holds exactly STATE_KEYS: each grant table's rows for the gated accounts (None for no rows),
    `sessions`, `probe_privileges` and `mandatory_roles`, as the probe account read them. A consumer
    that builds SQL from it must itself require `server["current_user"] == PROBE_ACCOUNT` (a mismatch
    only makes the gate unrecognised); the UUID and version are already checked (exit 6 otherwise).
    """

    server: dict
    state: dict
    gate: Gate


def read(config: dict, mode: str, *, deadline: float | None = None) -> tuple[dict, Gate]:
    """Read the server identity and the gate; raise Refusal for exits 3, 4 and 6."""
    reading = read_gate(config, mode, deadline=deadline)
    return reading.server, reading.gate


def read_gate(config: dict, mode: str, *, deadline: float | None = None) -> Reading:
    """read(), also handing out the classified rows (for gate.py's root SQL; never printed).

    A Refusal raised after the classification (unrecognised, partial, or not closed in strict mode)
    carries the same Reading as `refusal.reading`; one raised before it (exit 3, 6, an unreadable
    answer) carries None.
    """
    mysql = config["mysql"]
    client, defaults = Path(mysql["client"]), Path(mysql["probe_defaults"])
    refused = {"version": VERSION, "reachable": True, "server_uuid": None, "database": mysql["database"],
               "gate": None, "mode": mode}
    missing = [str(path) for path in (client, defaults) if not path.exists()]
    if missing:
        # Before the administrator installs MySQL and the probe account (session A1), nothing listens.
        if not _listening(mysql["socket"], deadline):
            raise Refusal(EXIT_UNREACHABLE, "The MySQL server is not listening", {"version": VERSION,
                                                                                 "reachable": False})
        problems = [f"The server listens but {path} is missing" for path in missing]
        raise Refusal(EXIT_UNRECOGNISED, "; ".join(problems), {**refused, "problems": problems})
    _protected(client)
    _protected(defaults, secret=True)
    argv = _client_argv(mysql, raw=True) + [f"{server_statement()}; {state_statement(_accounts(config))}"]
    try:
        result = subprocess.run(argv, stdin=subprocess.DEVNULL, capture_output=True, text=True,
                                timeout=timeout_seconds(CLIENT_SECONDS, deadline), env=CLIENT_ENVIRONMENT)
    except subprocess.TimeoutExpired:
        raise Refusal(EXIT_UNRECOGNISED, "The MySQL server did not answer the probe",
                      {**refused, "problems": ["The server did not answer"]}) from None
    lines = result.stdout.splitlines()
    error = result.stderr.strip()
    if result.returncode != 0 and not lines and NOT_LISTENING.fullmatch(error):
        raise Refusal(EXIT_UNREACHABLE, "The MySQL server is not listening", {"version": VERSION,
                                                                             "reachable": False})
    if not lines:
        raise Refusal(EXIT_UNRECOGNISED, f"The gate state is not readable: {error[:500]}",
                      {**refused, "problems": [error[:500] or "No output"]})
    try:
        server = _json_line(lines[0], SERVER_KEYS)
    except guard.Refused as refusal:
        raise Refusal(EXIT_UNRECOGNISED, str(refusal), {**refused, "problems": [str(refusal)]}) from None
    refused["server_uuid"] = server["server_uuid"]
    problems = server_problems(server, mysql["target_uuid"])
    if problems:
        raise Refusal(EXIT_WRONG_SERVER, "; ".join(problems), {**refused, "problems": problems})
    if result.returncode != 0 or len(lines) != 2:
        message = error[:500] or "Unexpected probe output"
        raise Refusal(EXIT_UNRECOGNISED, f"The gate state is not readable: {message}",
                      {**refused, "problems": [message]})
    try:
        state = _json_line(lines[1], STATE_KEYS)
        gate = classify(config, server, state)
    except guard.Refused as refusal:
        raise Refusal(EXIT_UNRECOGNISED, str(refusal), {**refused, "problems": [str(refusal)]}) from None
    reading = Reading(server, state, gate)
    refused.update(gate=gate.name, problems=gate.problems, open_privileges=gate.open_privileges)
    if gate.name in ("unrecognised", "partial"):
        raise Refusal(EXIT_UNRECOGNISED, f"The gate state is {gate.name}", refused, reading)
    if mode == "strict" and gate.name != "closed":
        message = f"The gate is {gate.name} outside the frozen window, where only a closed gate is accepted"
        refused["problems"] = [message]
        raise Refusal(EXIT_UNRECOGNISED, message, refused, reading)
    return reading


# The binary log (design 1.2): SHOW BINARY LOGS and SHOW BINLOG EVENTS as the probe account, which has
# REPLICATION CLIENT and REPLICATION SLAVE (accounts.sql). There is no mysqlbinlog on the host to rely on.

def _show(config: dict, statement: str) -> bytes:
    argv = _client_argv(config["mysql"], raw=False) + [statement]
    try:
        result = subprocess.run(argv, stdin=subprocess.DEVNULL, capture_output=True, timeout=CLIENT_SECONDS,
                                env=CLIENT_ENVIRONMENT)
    except subprocess.TimeoutExpired:
        raise Unreadable("The MySQL server did not answer a binary log read") from None
    if result.returncode != 0:
        error = result.stderr.decode("utf-8", "replace").strip()
        raise Unreadable(f"The binary log is not readable: {error[:500] or 'no output'}")
    return result.stdout


def scan_from(config: dict, start: Coordinate,
              history: GtidSet | None = None) -> tuple[binlog_scan.Result, Coordinate, str]:
    """Scan from `start` to the end of the last listed file.

    Also returns the coordinate and gtid_executed read before the scan, so every event before that
    coordinate was scanned. Raises Purged if `start` is no longer listed, or if gtid_executed no longer
    holds `history` (a RESET BINARY LOGS AND GTIDS reuses the file names).
    """
    (until, executed), logs = binlog_scan.parse_listing(_show(config, LISTING))
    sizes = dict(logs)
    if start.file not in sizes:
        raise Purged(f"The binary log {start.file} is no longer listed (purged); the scan from "
                     f"{start.file}:{start.position} cannot run")
    if history is not None and not history.issubset(GtidSet.parse(executed)):
        raise Purged("gtid_executed no longer holds the backup's GTIDs (the receipt's or --since-gtid): the binary "
                     "log history was reset")
    if start.position > sizes[start.file]:
        raise Unreadable(f"{start.file} is shorter than the scan's start position {start.position}")

    def fetch(name: str, position: int | None, offset: int, count: int) -> list[binlog_scan.Event]:
        # Names come from the listing (binlog_scan.LOG_NAME), so they quote safely.
        start_at = "" if position is None else f" FROM {position}"
        return binlog_scan.parse_events(
            _show(config, f"SHOW BINLOG EVENTS IN '{name}'{start_at} LIMIT {offset}, {count}"), name)

    events = binlog_scan.walk(fetch, [name for name, _ in logs], start)
    return binlog_scan.scan(events, binlog_scan.Rules.from_config(config["mysql"])), until, executed


def _refused(code: int, message: str, base: dict) -> Refusal:
    return Refusal(code, message, {**base, "problems": [message]})


def _scan(config: dict, start: Coordinate, base: dict, history: GtidSet | None = None):
    try:
        return scan_from(config, start, history)
    except Purged as error:
        raise _refused(EXIT_PURGED, str(error), base) from None
    except binlog_scan.WrongServer as error:
        raise _refused(EXIT_WRONG_SERVER, str(error), base) from None
    except Unreadable as error:
        raise _refused(EXIT_UNRECOGNISED, str(error), base) from None


# Records under the journal directory, each root-owned in a root chain (guard.read_protected).

def _present(path: Path) -> bool:
    return path.exists() or path.is_symlink()


def _record(path: Path) -> tuple[bytes, dict] | None:
    try:
        raw = guard.read_protected(path)
    except FileNotFoundError:
        return None
    return raw, guard.read_json(raw)


def _coordinate(value: object, keys: set[str], what: str) -> Coordinate:
    guard.require(isinstance(value, dict) and value.keys() == keys
                  and guard.matches(binlog_scan.LOG_NAME, value["file"])
                  and type(value["position"]) is int and value["position"] >= 4, f"The MySQL {what} is malformed")
    return Coordinate(value["file"], value["position"])


def _gtid_set(value: object, what: str) -> GtidSet:
    guard.require(isinstance(value, str), f"The MySQL {what} is malformed")
    try:
        return GtidSet.parse(value)
    except Unreadable as error:
        raise guard.Refused(f"The MySQL {what} is malformed: {error}") from None


def _sha256(value: object) -> bool:
    return guard.matches(r"[0-9a-f]{64}", value)


@dataclass(frozen=True)
class Receipt:
    sha256: str
    armed_from: Coordinate
    backup: GtidSet


def load_receipt(config: dict, run: str, server: dict, base: dict) -> Receipt | None:
    """prepare's receipt (design 1.2, 3.2 step 10) if it is this run's; another run's is ignored."""
    loaded = _record(Path(config["paths"]["receipt"]))
    if loaded is None:
        return None
    raw, record = loaded
    guard.require(record.keys() == RECEIPT_KEYS and guard.matches(host.RUN_ID, record["run_id"])
                  and guard.valid_uuid(record["server_uuid"]) and guard.matches(host.SQL_NAME, record["database"]),
                  "The MySQL receipt is malformed")
    armed_from = _coordinate(record["armed_from"], COORDINATE_KEYS, "receipt")
    _coordinate(record["backup"], BACKUP_KEYS, "receipt")
    backup = _gtid_set(record["backup"]["gtid_executed"], "receipt")
    if record["run_id"] != run:
        return None
    if (record["server_uuid"], record["database"]) != (server["server_uuid"], config["mysql"]["database"]):
        raise _refused(EXIT_UNRECOGNISED, "The receipt of this run names another server or database", base)
    return Receipt(guard.sha256(raw), armed_from, backup)


def load_latch(config: dict, run: str, receipt: Receipt | None) -> dict | None:
    """The activity latch, if it belongs to this run's current receipt; a stale one is ignored."""
    loaded = _record(Path(config["paths"]["activity"]))
    if loaded is None:
        return None
    _, record = loaded
    boundary = record.get("boundary")
    guard.require(record.keys() == LATCH_KEYS and guard.matches(host.RUN_ID, record["run_id"])
                  and _sha256(record["receipt_sha256"]) and isinstance(record["file"], str)
                  and type(record["position"]) is int and type(record["foreign_activity"]) is bool
                  and all(isinstance(record[name], list) and all(isinstance(item, str) for item in record[name])
                          for name in ("tables", "statements"))
                  and (boundary is None or (isinstance(boundary, dict) and boundary.keys() == {"file", "position",
                                                                                               "gtid"})),
                  "The MySQL activity latch is malformed")
    if receipt is None or (record["run_id"], record["receipt_sha256"]) != (run, receipt.sha256):
        return None
    return record


def load_activated(config: dict, run: str, server: dict, base: dict) -> dict | None:
    """The MySQL reopen's activation record (design 3.6 step 16) if it is this run's."""
    loaded = _record(Path(config["paths"]["activated"]))
    if loaded is None:
        return None
    _, record = loaded
    guard.require(record.keys() == ACTIVATED_KEYS and guard.matches(host.RUN_ID, record["run_id"])
                  and guard.valid_uuid(record["server_uuid"]) and isinstance(record["database"], str)
                  and _sha256(record["receipt_sha256"]), "The MySQL activation record is malformed")
    if record["run_id"] != run:
        return None
    if (record["server_uuid"], record["database"]) != (server["server_uuid"], config["mysql"]["database"]):
        raise _refused(EXIT_UNRECOGNISED, "The activation record of this run names another server or database", base)
    return record


def load_window(config: dict, run: str, receipt: Receipt) -> tuple[GtidSet, GtidSet, dict] | None:
    """Only a completed write from this run, target, policy and marked fixtures can be discounted."""
    import mysql_reopen_smoke as smoke

    record = smoke.read(Path(config["paths"]["smoke_window"]))
    if record is None:
        return None
    if type(record.get("version")) is int and record["version"] == 1:
        # The old fixture format carries no authenticated application evidence.
        return None
    guard.require(type(record.get("version")) is int and record["version"] == AUTHENTICATED_SMOKE_WINDOW_VERSION
                  and isinstance(record.get("binding"), dict), "The MySQL smoke window is malformed")
    saved = record["binding"]
    if (saved.get("run_id"), saved.get("receipt_sha256")) != (run, receipt.sha256):
        return None
    policy = guard.read_json(guard.read_protected(Path(config["paths"]["policy"])))
    guard.require(isinstance(policy.get("identity"), dict) and policy["identity"].get("run_id") == run
                  and _sha256(policy.get("mysql_live_config_sha256")), "The audit has no matching MySQL route policy")
    expected = {"engine": "mysql", "database": config["mysql"]["database"], "server_uuid": config["mysql"]["target_uuid"],
                "route_sha256": policy["mysql_live_config_sha256"]}
    try:
        profile = smoke.load_profile(config)
        bound = smoke.binding(config, expected, run, receipt.sha256, profile)
        attempt = smoke.verified_window(record, bound, profile, config["app_root"])
    except host.UnsafeCutover as error:
        raise guard.Refused(f"Invalid write-smoke evidence: {error}") from None
    if attempt is None:
        return None
    return (_gtid_set(attempt["gtid_before"], "smoke window"), _gtid_set(attempt["gtid_after"], "smoke window"), attempt)


def _latch(config: dict, run: str, receipt: Receipt, result: binlog_scan.Result) -> None:
    first = result.business[0].record()
    host._write_atomic(Path(config["paths"]["activity"]), {
        "version": 1, "run_id": run, "receipt_sha256": receipt.sha256, "gtid": first["gtid"], "file": first["file"],
        "position": first["position"], "tables": first["tables"], "statements": first["statements"],
        "boundary": result.boundary, "foreign_activity": result.foreign_activity,
    }, 0o600)


NO_ACTIVITY = {"business_activity": False, "foreign_activity": False, "boundary": None, "receipt": None,
               "scanned_events": 0}


def activity(config: dict, journal: dict | None, server: dict, base: dict) -> dict:
    """Business and foreign activity for this run (design 1.2): activation record, latch or scan."""
    paths = config["paths"]
    if journal is None or not any(_present(Path(paths[name])) for name in ("receipt", "activity", "activated")):
        return dict(NO_ACTIVITY)
    run = journal_run(journal)
    activated = load_activated(config, run, server, base)
    receipt = load_receipt(config, run, server, base)
    latch = load_latch(config, run, receipt)
    sha = None if receipt is None else receipt.sha256
    if activated is not None or latch is not None:
        # No scan: after the reopen (or the first hit) business activity stays true, purge or not.
        return {"business_activity": True, "foreign_activity": bool(latch and latch["foreign_activity"]),
                "boundary": None if latch is None else latch["boundary"], "receipt": sha, "scanned_events": 0}
    if receipt is None:
        return dict(NO_ACTIVITY)
    result, _, _ = _scan(config, receipt.armed_from, base, receipt.backup)
    if result.business_activity:
        _latch(config, run, receipt, result)
    return {"business_activity": result.business_activity, "foreign_activity": result.foreign_activity,
            "boundary": result.boundary, "receipt": sha, "scanned_events": result.events}


def _base(config: dict, server: dict, gate: Gate, mode: str) -> dict:
    """What every reading after the gate read carries, refused or not."""
    return {"version": VERSION, "reachable": True, "server_uuid": server["server_uuid"],
            "database": config["mysql"]["database"], "gate": gate.name, "mode": mode,
            "open_privileges": gate.open_privileges}


def observe(config: dict) -> dict:
    journal = read_journal(config)
    mode = mode_of(journal)
    server, gate = read(config, mode)
    base = _base(config, server, gate, mode)
    measured = activity(config, journal, server, base)
    if mode == "strict" and measured["business_activity"]:
        raise _refused(EXIT_UNRECOGNISED, "MySQL has business activity outside the frozen window, where only "
                                          "no activity is accepted", base)
    return {"version": VERSION, "reachable": True, "server_uuid": server["server_uuid"],
            "database": config["mysql"]["database"], "gate": gate.name, "writable": gate.name == "open",
            **measured, "gated_sessions": len(gate.sessions), "mode": mode}


def _listing(base: dict) -> dict:
    return {name: value for name, value in base.items() if name != "open_privileges"}


def audit(config: dict) -> dict:
    """Every app-schema transaction after armed_from as foreign, smoke or business (design 4.3)."""
    journal = read_journal(config)
    mode = mode_of(journal)
    server, gate = read(config, mode)
    base = _base(config, server, gate, mode)
    run = None if journal is None else journal_run(journal)
    receipt = None if run is None else load_receipt(config, run, server, base)
    if receipt is None:
        raise _refused(EXIT_UNRECOGNISED, "No receipt of this run: nothing to audit", base)
    window = load_window(config, run, receipt)
    result, _, executed = _scan(config, receipt.armed_from, base, receipt.backup)
    if window is not None and not window[1].issubset(GtidSet.parse(executed)):
        raise _refused(EXIT_PURGED, "The server no longer contains the verified write-smoke GTIDs", base)
    listed = binlog_scan.audit(result, frozenset(config["mysql"]["smoke_tables"]),
                               None if window is None else window[:2], server_uuid=server["server_uuid"])
    return {**_listing(base), "receipt": receipt.sha256, "armed_from": receipt.armed_from.record(),
            "boundary": result.boundary,
            "smoke_window": None if window is None else {name: window[2][name] for name in ("gtid_before",
                                                                                            "gtid_after")},
            "transactions": listed,
            "counts": {name: sum(1 for item in listed if item["class"] == name)
                       for name in ("foreign", "smoke", "business")},
            "scanned_events": result.events}


def since(config: dict, start: Coordinate, history: GtidSet | None = None) -> dict:
    """Every app-schema transaction from `start`, and the coordinate read before the scan (prepare 3.2).

    With `history` (`--since-gtid`, the backup's gtid_executed) the scan refuses as Purged (exit 5) when
    gtid_executed no longer holds it, as for the receipt. Without it nothing checks that `start` is still
    the same history: the caller must (README, --since contract).
    """
    journal = read_journal(config)
    mode = mode_of(journal)
    server, gate = read(config, mode)
    base = _base(config, server, gate, mode)
    result, until, executed = _scan(config, start, base, history)
    return {**_listing(base), "since": start.record(), "until": {**until.record(), "gtid_executed": executed},
            "boundary": result.boundary, "transactions": [item.record() for item in result.transactions],
            "scanned_events": result.events}


def _since_argument(value: str) -> Coordinate:
    match = re.fullmatch(f"({binlog_scan.LOG_NAME}):([0-9]{{1,19}})", value)
    if match is None or int(match.group(2)) < 4:
        raise argparse.ArgumentTypeError("expected <binary log file>:<position>")
    return Coordinate(match.group(1), int(match.group(2)))


def _gtid_argument(value: str) -> GtidSet:
    try:
        return GtidSet.parse(value)
    except Unreadable:
        raise argparse.ArgumentTypeError("expected a GTID set as the server prints gtid_executed") from None


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--config", type=Path, required=True)
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument("--audit", action="store_true",
                       help="list every app-schema transaction after the receipt's armed_from (design 4.3)")
    modes.add_argument("--since", type=_since_argument, metavar="FILE:POSITION",
                       help="list every app-schema transaction from a coordinate (prepare, design 3.2)")
    parser.add_argument("--since-gtid", type=_gtid_argument, metavar="GTID_SET",
                        help="with --since: exit 5 unless gtid_executed still holds this set (the backup's)")
    args = parser.parse_args(argv)
    if args.since_gtid is not None and args.since is None:
        parser.error("--since-gtid needs --since")
    try:
        if sys.platform != "linux" or os.geteuid() != 0:
            raise guard.Refused("The MySQL probe runs as root on the Linux host")
        config = host.load_config(guard.read_protected(args.config), read=guard.read_protected)
        if args.audit:
            output = audit(config)
        elif args.since is not None:
            output = since(config, args.since, args.since_gtid)
        else:
            output = observe(config)
    except Refusal as refusal:
        print(json.dumps(refusal.output, sort_keys=True))
        print(f"MySQL probe: {refusal}", file=sys.stderr)
        return refusal.code
    except (guard.Refused, OSError, ValueError, TypeError, KeyError) as error:
        print(f"MySQL probe failed: {error}", file=sys.stderr)
        return EXIT_FAILURE
    print(json.dumps(output, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
