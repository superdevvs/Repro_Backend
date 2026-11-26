"""Business activity from the binary log (design v2, sections 1.2 and 4.3), read as SHOW BINLOG EVENTS text.

Pure functions over the mysql client's batch output; mysql_probe.py runs the client. The text is the
one MySQL 8.4.13 prints (the probe refuses any other server version, exit 6), so retest after an
upgrade. Standard library only: it runs as root under /usr/bin/python3 -I -S.

The client's batch mode (`--batch --skip-column-names`, without `--raw`) separates fields with a tab
and rows with a newline and escapes exactly four bytes inside a value: backslash, tab, newline and NUL.
A carriage return or any other control byte passes through, so rows are split on newline only.

Events are grouped into transactions by their Gtid event. From the receipt's `armed_from`:

- A **boundary** is an account statement (GRANT, REVOKE, CREATE/ALTER/DROP/RENAME USER, CREATE/DROP
  ROLE, SET DEFAULT ROLE, SET PASSWORD), except exactly `ALTER USER [IF EXISTS] '<a>'@'localhost'[,'<b>'@
  'localhost' ...] ACCOUNT LOCK|UNLOCK` as the server logs it, with every account in `lockable_accounts`
  and nothing else in the statement. Beyond the design's list, a row event on one of the nine grant
  tables and FLUSH PRIVILEGES are boundaries too: a direct edit of `mysql.db` reaches the app account
  without an account statement. A statement this module cannot read (an executable `/*!` comment, an
  unclosed comment) is a boundary and an app-schema event at once.
- An **app-schema event** is a Table_map on `<database>.<t>` with `t` not in `excluded_tables`, a
  Query whose default database or text names the app schema (other than BEGIN, COMMIT, ROLLBACK and
  XA control), an Incident, a rows event without a Table_map of its own transaction, any Info this
  module cannot read and any event type it does not know.
- An app-schema transaction before the first boundary is **foreign**; one at or after it is
  **business** (a boundary inside a transaction makes that transaction business).

A compressed transaction (Transaction_payload) lists its inner events after the payload, all at the
payload's end position, so pages are read by offset from one fixed position (`walk`), never by position.
"""

from __future__ import annotations

import re
from collections.abc import Callable, Iterable, Iterator
from dataclasses import dataclass, field

# The server version whose SHOW BINLOG EVENTS text this module reads (review A13).
SERVER_VERSION = "8.4.13"
PAGE_EVENTS = 500
# A binary log file name the probe may quote into SHOW BINLOG EVENTS IN '<name>'.
LOG_NAME = r"[A-Za-z0-9][A-Za-z0-9._-]{0,254}"
UUID = r"[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}"
TAG = r"[a-z_][a-z0-9_]{0,31}"
# The nine grant tables the probe reads for the gate (design 1.2); a row event on any of them is a boundary.
GRANT_TABLES = frozenset({"user", "db", "tables_priv", "columns_priv", "procs_priv", "proxies_priv",
                          "global_grants", "role_edges", "default_roles"})
FILE_EVENTS = frozenset({"Format_desc", "Previous_gtids", "Rotate", "Stop"})
GTID_EVENTS = frozenset({"Gtid", "Gtid_tagged_log_event", "Anonymous_Gtid"})
# Parts of a transaction that decide nothing on their own: the commit, the compressed container (its
# inner events follow), the original statement of a rows event, statement-based helpers, XA PREPARE.
NEUTRAL_EVENTS = frozenset({"Xid", "Transaction_payload", "Rows_query", "Intvar", "RAND", "User var",
                            "XA_prepare"})
ROWS_EVENTS = frozenset({"Write_rows", "Update_rows", "Delete_rows", "Update_rows_partial"})

_ESCAPES = {ord("\\"): b"\\", ord("t"): b"\t", ord("n"): b"\n", ord("0"): b"\0"}
_GTID_INFO = re.compile(r"SET @@SESSION\.GTID_NEXT= '([^'\n]+)'")
_TABLE_MAP = re.compile(r"table_id: ([0-9]+) \((.+)\)", re.DOTALL)
_ROWS = re.compile(r"table_id: ([0-9]+)(?: flags: STMT_END_F)?")
_USE = re.compile(r"use `((?:[^`]|``)+)`; (.*)", re.DOTALL)
_XID = re.compile(r"(.*) /\* xid=[0-9]+ \*/", re.DOTALL)
_CONTROL = re.compile(r"(?:BEGIN|COMMIT|ROLLBACK|XA\s+(?:START|BEGIN|END|PREPARE|COMMIT|ROLLBACK)\b.*)",
                      re.IGNORECASE | re.DOTALL)
_ACCOUNT = re.compile(r"(?:GRANT|REVOKE|(?:CREATE|ALTER|DROP|RENAME)\s+USER|(?:CREATE|DROP)\s+ROLE"
                      r"|SET\s+DEFAULT\s+ROLE|SET\s+PASSWORD)\b", re.IGNORECASE)
_FLUSH_PRIVILEGES = re.compile(r"FLUSH\b.*\bPRIVILEGES\b", re.IGNORECASE | re.DOTALL)
# The lock forms the server writes for `ALTER USER [IF EXISTS] <account>[, <account> ...] ACCOUNT
# LOCK|UNLOCK` (review B2-6): it rewrites the quoting, the keywords, the comments and the list separator,
# so only this exact text is exempt, and only when every account in it is lockable.
_LOCK = re.compile(r"ALTER USER (?:IF EXISTS )?"
                   r"('[a-z][a-z0-9_]{0,31}'@'localhost'(?:,'[a-z][a-z0-9_]{0,31}'@'localhost')*)"
                   r" ACCOUNT (?:LOCK|UNLOCK)")
_LOCKED_ACCOUNT = re.compile(r"'([a-z][a-z0-9_]{0,31})'@'localhost'")


class Unreadable(ValueError):
    """Binary log text this module cannot read with certainty: the probe exits 4."""


class WrongServer(ValueError):
    """A server whose binary log this scan cannot rely on (it filters what it logs): the probe exits 6."""


@dataclass(frozen=True)
class Event:
    file: str
    position: int
    type: str
    server_id: int
    end_position: int
    info: str


@dataclass(frozen=True)
class Coordinate:
    file: str
    position: int

    def record(self) -> dict:
        return {"file": self.file, "position": self.position}


@dataclass(frozen=True)
class Rules:
    """What the scan compares against: host.json's mysql.database and its two lists."""

    database: str
    excluded_tables: frozenset
    lockable_accounts: frozenset

    @classmethod
    def from_config(cls, mysql: dict) -> "Rules":
        return cls(mysql["database"], frozenset(mysql["activity_excluded_tables"]),
                   frozenset(mysql["lockable_accounts"]))


# Batch output.

def _unescape(value: bytes) -> str:
    if b"\\" not in value:
        return value.decode("utf-8", "replace")
    out, index = bytearray(), 0
    while index < len(value):
        byte = value[index]
        if byte != 0x5C:
            out.append(byte)
            index += 1
            continue
        if index + 1 >= len(value) or value[index + 1] not in _ESCAPES:
            raise Unreadable("The client printed an escape it never prints")
        out += _ESCAPES[value[index + 1]]
        index += 2
    return bytes(out).decode("utf-8", "replace")


def _rows(raw: bytes, width: int) -> list[list[str]]:
    if raw == b"":
        return []
    if not raw.endswith(b"\n"):
        raise Unreadable("The client output ends inside a row")
    rows = []
    for line in raw[:-1].split(b"\n"):
        fields = line.split(b"\t")
        if len(fields) != width:
            raise Unreadable(f"Expected {width} fields per row, got {len(fields)}")
        rows.append([_unescape(value) for value in fields])
    return rows


def _number(value: str) -> int:
    if not re.fullmatch(r"[0-9]{1,19}", value):
        raise Unreadable(f"Not a position or server ID: {value[:40]!r}")
    return int(value)


def parse_events(raw: bytes, file: str) -> list[Event]:
    """`SHOW BINLOG EVENTS IN '<file>' ...` in batch mode: Log_name, Pos, Event_type, Server_id, End_log_pos, Info."""
    events = []
    for name, position, kind, server_id, end_position, info in _rows(raw, 6):
        if name != file:
            raise Unreadable(f"An event of {name!r} in the listing of {file!r}")
        if not re.fullmatch(r"[A-Za-z][A-Za-z_ ]{0,63}", kind):
            raise Unreadable(f"Unreadable event type {kind[:40]!r}")
        events.append(Event(name, _number(position), kind, _number(server_id), _number(end_position), info))
    return events


def parse_listing(raw: bytes) -> tuple[tuple[Coordinate, str], list[tuple[str, int]]]:
    """`SHOW BINARY LOG STATUS; SHOW BINARY LOGS` in one batch: the current coordinate with
    gtid_executed, then every listed file with its size, oldest first.

    Raises WrongServer when the status names a binary log filter (`binlog-do-db` or
    `binlog-ignore-db`): such a server leaves app-schema writes out of the log, so no scan could see
    them (review B2-7). The message names the column only.
    """
    lines = raw.split(b"\n")
    if len(lines) < 2 or lines[-1] != b"":
        raise Unreadable("No binary log status (is binary logging on?)")
    status = _rows(lines[0] + b"\n", 5)[0]
    filters = [column for column, value in (("Binlog_Do_DB", status[2]), ("Binlog_Ignore_DB", status[3])) if value]
    if filters:
        raise WrongServer(f"SHOW BINARY LOG STATUS reports a non-empty {' and '.join(filters)}: the server "
                          "filters its binary log, so the activity scan cannot see every app-schema write")
    logs = [(name, _number(size)) for name, size, _ in _rows(b"\n".join(lines[1:]), 3)]
    names = [status[0], *(name for name, _ in logs)]
    if not all(re.fullmatch(LOG_NAME, name) for name in names) or len({name for name, _ in logs}) != len(logs):
        raise Unreadable("Unexpected binary log file names")
    return (Coordinate(status[0], _number(status[1])), status[4]), logs


# Classification.

def _names_schema(database: str) -> re.Pattern:
    # The schema's name anywhere in a statement, also with the `\_` that schema-level grants use.
    return re.compile("".join(r"\\?_" if char == "_" else re.escape(char) for char in database), re.IGNORECASE)


def _statement_head(text: str) -> str | None:
    """The statement after leading whitespace and comments, or None if a comment hides or runs into it."""
    index = 0
    while True:
        while index < len(text) and text[index].isspace():
            index += 1
        if text.startswith("/*!", index) or text.startswith("/*+", index):
            return None
        if text.startswith("/*", index):
            end = text.find("*/", index + 2)
            if end < 0:
                return None
            index = end + 2
        elif text.startswith("#", index) or (text.startswith("--", index)
                                             and (index + 2 == len(text) or text[index + 2].isspace())):
            end = text.find("\n", index)
            if end < 0:
                return ""
            index = end + 1
        else:
            return text[index:]


@dataclass
class Transaction:
    gtid: str | None
    file: str
    position: int
    tables: set = field(default_factory=set)
    statements: list = field(default_factory=list)
    boundary: bool = False
    business: bool = False
    mapped: dict = field(default_factory=dict)

    @property
    def app(self) -> bool:
        return bool(self.tables or self.statements)

    def record(self) -> dict:
        return {"gtid": self.gtid, "file": self.file, "position": self.position, "tables": sorted(self.tables),
                "statements": list(self.statements), "class": "business" if self.business else "foreign"}


@dataclass
class Result:
    events: int
    boundary: dict | None
    transactions: list  # app-schema transactions only, in log order

    @property
    def business(self) -> list:
        return [item for item in self.transactions if item.business]

    @property
    def foreign(self) -> list:
        return [item for item in self.transactions if not item.business]

    @property
    def business_activity(self) -> bool:
        return any(item.business for item in self.transactions)

    @property
    def foreign_activity(self) -> bool:
        return any(not item.business for item in self.transactions)


class Scan:
    """Feed events in log order (across pages and files), then `finish()`."""

    def __init__(self, rules: Rules):
        self.rules = rules
        self.names_schema = _names_schema(rules.database)
        self.events = 0
        self.boundary: dict | None = None
        self.transactions: list[Transaction] = []
        self.current: Transaction | None = None
        self.file: str | None = None

    def _close(self) -> None:
        if self.current is not None and self.current.app:
            self.current.business = self.boundary is not None
            self.transactions.append(self.current)
        self.current = None

    def _transaction(self, event: Event) -> Transaction:
        if self.current is None:
            # Outside any Gtid group: still counted, with no GTID.
            self.current = Transaction(None, event.file, event.position)
        return self.current

    def _app(self, event: Event, statement: str) -> None:
        self._transaction(event).statements.append(statement)

    def _boundary(self, event: Event) -> None:
        transaction = self._transaction(event)
        transaction.boundary = True
        if self.boundary is None:
            self.boundary = {"file": event.file, "position": event.position, "gtid": transaction.gtid}

    def feed(self, event: Event) -> None:
        self.events += 1
        if event.file != self.file:
            # A transaction never spans files.
            self._close()
            self.file = event.file
        kind = event.type
        if kind in FILE_EVENTS:
            self._close()
        elif kind in GTID_EVENTS:
            self._close()
            match = _GTID_INFO.fullmatch(event.info)
            self.current = Transaction(match.group(1) if match else None, event.file, event.position)
            if match is None:
                self._app(event, f"unreadable {kind}")
        elif kind in NEUTRAL_EVENTS:
            pass
        elif kind == "Table_map":
            self._table_map(event)
        elif kind in ROWS_EVENTS:
            match = _ROWS.fullmatch(event.info)
            if match is None:
                self._app(event, f"unreadable {kind}")
            elif match.group(1) not in self._transaction(event).mapped:
                self._app(event, "rows without a table map")
        elif kind == "Query":
            self._query(event)
        elif kind == "Incident":
            self._app(event, "incident")
        else:
            self._app(event, f"event {kind}")

    def _table_map(self, event: Event) -> None:
        transaction = self._transaction(event)
        match = _TABLE_MAP.fullmatch(event.info)
        if match is None:
            self._app(event, "unreadable Table_map")
            return
        table_id, name = match.groups()
        transaction.mapped[table_id] = name
        prefix = self.rules.database + "."
        if name.startswith(prefix) and name[len(prefix):] in self.rules.excluded_tables:
            return
        if name.casefold().startswith(prefix.casefold()):
            transaction.tables.add(name[len(prefix):])
        elif name.startswith("mysql.") and name[len("mysql."):] in GRANT_TABLES:
            self._boundary(event)

    def _query(self, event: Event) -> None:
        info, default = event.info, None
        if info.startswith("use "):
            match = _USE.fullmatch(info)
            if match is None:
                self._app(event, "unreadable Query")
                return
            default, info = match.group(1).replace("``", "`"), match.group(2)
        match = _XID.fullmatch(info)
        statement = match.group(1) if match else info
        head = _statement_head(statement)
        if head is None:
            # It may hide an account statement as well as a write.
            self._boundary(event)
            self._app(event, "unreadable Query")
        elif _CONTROL.fullmatch(head):
            pass
        elif _ACCOUNT.match(head):
            lock = _LOCK.fullmatch(statement)
            if lock is None or not all(f"{user}@localhost" in self.rules.lockable_accounts
                                       for user in _LOCKED_ACCOUNT.findall(lock.group(1))):
                self._boundary(event)
        elif _FLUSH_PRIVILEGES.match(head):
            self._boundary(event)
        elif (default is not None and default.casefold() == self.rules.database.casefold()) \
                or self.names_schema.search(statement):
            self._app(event, "query")

    def finish(self) -> Result:
        self._close()
        return Result(self.events, self.boundary, self.transactions)


def scan(events: Iterable[Event], rules: Rules) -> Result:
    state = Scan(rules)
    for event in events:
        state.feed(event)
    return state.finish()


def walk(fetch: Callable[[str, int | None, int, int], list[Event]], logs: list[str], start: Coordinate,
         page: int = PAGE_EVENTS) -> Iterator[Event]:
    """Every event from `start` to the end of the last listed file.

    `fetch(file, position, offset, count)` runs `SHOW BINLOG EVENTS IN file [FROM position] LIMIT offset, count`;
    the start file is read from `start.position`, every later listed file from its beginning. Pages are
    taken by offset from that one position until a page comes back short.
    """
    if start.file not in logs:
        raise Unreadable(f"{start.file} is not a listed binary log")
    for name in logs[logs.index(start.file):]:
        position = start.position if name == start.file else None
        offset = 0
        while True:
            events = fetch(name, position, offset, page)
            if len(events) > page:
                raise Unreadable("A page longer than asked for")
            if offset == 0 and position is not None and events and events[0].position != position:
                raise Unreadable(f"{name} has no event at position {position}")
            yield from events
            if len(events) < page:
                break
            offset += page


# GTID sets and the audit (design 4.3).

class GtidSet:
    """A gtid_executed-style set: `uuid:1-5:7[:tag:1-3],...`, as the server prints it."""

    def __init__(self, intervals: dict):
        self.intervals = intervals  # {(uuid, tag): [(first, last), ...]}

    @classmethod
    def parse(cls, text: str) -> "GtidSet":
        intervals: dict = {}
        text = text.replace("\n", "")
        if text == "":
            return cls(intervals)
        for item in text.split(","):
            parts = item.split(":")
            if len(parts) < 2 or not re.fullmatch(UUID, parts[0]):
                raise Unreadable(f"Unreadable GTID set {text[:80]!r}")
            tag, seen = "", False
            for part in parts[1:]:
                match = re.fullmatch(r"([0-9]{1,19})(?:-([0-9]{1,19}))?", part)
                if match:
                    first, last = int(match.group(1)), int(match.group(2) or match.group(1))
                    if not 0 < first <= last:
                        raise Unreadable(f"Unreadable GTID interval {part!r}")
                    intervals.setdefault((parts[0], tag), []).append((first, last))
                    seen = True
                elif re.fullmatch(TAG, part):
                    tag, seen = part, False
                else:
                    raise Unreadable(f"Unreadable GTID set {text[:80]!r}")
            if not seen:
                raise Unreadable(f"Unreadable GTID set {text[:80]!r}")
        return cls(intervals)

    def __contains__(self, gtid: object) -> bool:
        if not isinstance(gtid, str):
            return False
        parts = gtid.split(":")
        if len(parts) not in (2, 3) or not parts[-1].isdigit():
            return False
        key, number = (parts[0], parts[1] if len(parts) == 3 else ""), int(parts[-1])
        return any(first <= number <= last for first, last in self.intervals.get(key, ()))

    def issubset(self, other: "GtidSet") -> bool:
        for key, intervals in self.intervals.items():
            theirs = other.intervals.get(key, ())
            for first, last in intervals:
                number = first
                while number <= last:
                    covering = [end for start, end in theirs if start <= number <= end]
                    if not covering:
                        return False
                    number = max(covering) + 1
        return True


def audit(result: Result, smoke_tables: frozenset, window: tuple[GtidSet, GtidSet] | None) -> list[dict]:
    """Each app-schema transaction as foreign, smoke or business.

    Smoke: business, its GTID in the smoke window (in `after`, not in `before`), every table it wrote in
    `smoke_tables` and no other app-schema event. Anything else after the boundary is business.
    """
    listed = []
    for transaction in result.transactions:
        record = transaction.record()
        if (transaction.business and window is not None and transaction.gtid in window[1]
                and transaction.gtid not in window[0] and not transaction.statements
                and transaction.tables <= smoke_tables):
            record["class"] = "smoke"
        listed.append(record)
    return listed
