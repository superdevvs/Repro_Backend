"""MySQL target probe for the cutover controller: the write gate (design v2, sections 1.2 and 2.2).

    /usr/bin/python3 -I -S /opt/repro-cutover-fence/production/mysql_probe.py --config /etc/repro-cutover-fence/host.json

Runs as root, reads files and MySQL only, and prints one JSON object. It runs the native 8.4 client
by absolute path as the probe account (`mysql.probe_defaults`), over the socket in host.json, and reads
the gated accounts' rows in the nine grant tables, `mandatory_roles` and `CURRENT_USER()`:

- closed: SELECT on the app schema and on the token, nothing else;
- open: the same plus INSERT, UPDATE, DELETE on the app schema and UPDATE (`id`) on the token;
- partial: any other subset of those four with everything else exactly closed (gate.py can close it);
- unrecognised: anything else (a role, a default or mandatory role, a routine or proxy grant, a global
  privilege, a lock, another schema or table row, a missing account, `--skip-grant-tables`).

Exit codes: 0 observed; 3 the server is not listening (stdout {"version": 2, "reachable": false});
4 gate state not recognised or not readable, or a non-closed reading outside the frozen window;
6 wrong server (UUID differs from `target_uuid`, version not 8.4, or networking on); anything else is
a probe failure. Strict mode (no journal, `planned`, `sqlite_authoritative`) exits 0 only for "closed",
so it can never report a writable target while SQLite is live.

This build reads the gate only. Business activity needs the binary log scan from the receipt; until
that scan exists the probe refuses whenever a receipt, activity or activated record is present, and
otherwise reports no receipt and no activity.
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
from dataclasses import dataclass, field
from pathlib import Path

sys.path[:0] = [str(Path(__file__).resolve().parent), str(Path(__file__).resolve().parent.parent)]
import adapter as host  # noqa: E402
from adapter import guard  # noqa: E402

VERSION = 2
EXIT_FAILURE, EXIT_UNREACHABLE, EXIT_UNRECOGNISED, EXIT_WRONG_SERVER = 1, 3, 4, 6
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


class Refusal(Exception):
    """A reading the controller must not act on: printed, then the probe exits with `code`."""

    def __init__(self, code: int, message: str, output: dict | None = None):
        super().__init__(message)
        self.code = code
        self.output = output


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
    """Why this is not the pinned target server (exit 6): UUID, version 8.4, socket only."""
    problems = []
    if server["server_uuid"] != target_uuid:
        problems.append(f"Server UUID {server['server_uuid']} is not the target {target_uuid}")
    if not guard.matches(r"8\.4\.[0-9]+", server["version"]):
        problems.append(f"Server version {server['version']} is not 8.4")
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


def journal_mode(config: dict) -> str:
    """strict with no journal or outside the frozen window, measured inside it (verifier correction 3)."""
    path = Path(config["paths"]["state"]) / "state.json"
    try:
        journal = guard.read_json(guard.read_protected(path))
    except FileNotFoundError:
        return "strict"
    phase = journal.get("phase")
    if phase in STRICT_PHASES:
        return "strict"
    guard.require(phase in MEASURED_PHASES, "The cutover journal has an unknown phase")
    return "measured"


def _protected(path: Path, *, secret: bool = False) -> None:
    guard.check_chain(path)
    if secret:
        guard.require(not stat.S_IMODE(path.lstat().st_mode) & 0o077, f"{path} must be readable by root only")


def _listening(path: str) -> bool:
    probe = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    probe.settimeout(CONNECT_SECONDS)
    try:
        probe.connect(path)
    except OSError as error:
        if error.errno in (errno.ENOENT, errno.ECONNREFUSED):
            return False
        raise
    finally:
        probe.close()
    return True


def _json_line(line: str, keys: set[str]) -> dict:
    try:
        value = json.loads(line)
    except ValueError:
        value = None
    guard.require(isinstance(value, dict) and value.keys() == keys, "Unexpected probe output")
    return value


def read(config: dict, mode: str) -> tuple[dict, Gate]:
    """Read the server identity and the gate; raise Refusal for exits 3, 4 and 6."""
    mysql = config["mysql"]
    client, defaults = Path(mysql["client"]), Path(mysql["probe_defaults"])
    refused = {"version": VERSION, "reachable": True, "server_uuid": None, "database": mysql["database"],
               "gate": None, "mode": mode}
    missing = [str(path) for path in (client, defaults) if not path.exists()]
    if missing:
        # Before the administrator installs MySQL and the probe account (session A1), nothing listens.
        if not _listening(mysql["socket"]):
            raise Refusal(EXIT_UNREACHABLE, "The MySQL server is not listening", {"version": VERSION,
                                                                                 "reachable": False})
        problems = [f"The server listens but {path} is missing" for path in missing]
        raise Refusal(EXIT_UNRECOGNISED, "; ".join(problems), {**refused, "problems": problems})
    _protected(client)
    _protected(defaults, secret=True)
    argv = [mysql["client"], f"--defaults-file={defaults}", "--no-login-paths", "--protocol=SOCKET",
            f"--socket={mysql['socket']}", "--batch", "--raw", "--skip-column-names",
            f"--connect-timeout={CONNECT_SECONDS}", "-e",
            f"{server_statement()}; {state_statement(_accounts(config))}"]
    try:
        result = subprocess.run(argv, stdin=subprocess.DEVNULL, capture_output=True, text=True,
                                timeout=CLIENT_SECONDS, env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin",
                                                             "LANG": "C.UTF-8"})
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
        gate = classify(config, server, _json_line(lines[1], STATE_KEYS))
    except guard.Refused as refusal:
        raise Refusal(EXIT_UNRECOGNISED, str(refusal), {**refused, "problems": [str(refusal)]}) from None
    refused.update(gate=gate.name, problems=gate.problems, open_privileges=gate.open_privileges)
    if gate.name in ("unrecognised", "partial"):
        raise Refusal(EXIT_UNRECOGNISED, f"The gate state is {gate.name}", refused)
    if mode == "strict" and gate.name != "closed":
        message = f"The gate is {gate.name} outside the frozen window, where only a closed gate is accepted"
        refused["problems"] = [message]
        raise Refusal(EXIT_UNRECOGNISED, message, refused)
    return server, gate


def require_no_receipt(config: dict) -> None:
    """Business activity is measured from the receipt by the binary log scan, which this build lacks."""
    for name in ("receipt", "activity", "activated"):
        path = Path(config["paths"][name])
        guard.require(not (path.exists() or path.is_symlink()),
                      f"A MySQL {name} record exists and this probe cannot scan the binary log; "
                      "business activity is not reported")


def observe(config: dict) -> dict:
    mode = journal_mode(config)
    server, gate = read(config, mode)
    require_no_receipt(config)
    return {"version": VERSION, "reachable": True, "server_uuid": server["server_uuid"],
            "database": config["mysql"]["database"], "gate": gate.name, "writable": gate.name == "open",
            "business_activity": False, "foreign_activity": False, "boundary": None, "receipt": None,
            "gated_sessions": len(gate.sessions), "scanned_events": 0, "mode": mode}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--config", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        if sys.platform != "linux" or os.geteuid() != 0:
            raise guard.Refused("The MySQL probe runs as root on the Linux host")
        config = host.load_config(guard.read_protected(args.config), read=guard.read_protected)
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
