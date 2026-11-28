"""Check native MySQL FULL row images before discounting a cutover smoke's four GTIDs.

Input can contain confidential row contents. Keep it in-process and return only a verdict.
"""

from __future__ import annotations

import hashlib
import json
import re
from datetime import datetime, timezone


# SHA-256 of the NUL-separated column names in server ordinal order.
# In a fresh SQLite migration and import, users.deleted_at and secondary_roles occupy
# positions 7 and 12. The reviewed clean MySQL schema places them at positions 46 and 26.
# fields() maps the measured native ordinals to names separately for each admitted layout.
LAYOUT_HASHES = {
    "users": {"ee0b74e03a5f889b215ffa7a0d2c92c4d368878dd54ea62ab8cf6e91de02e3f3",
              "fd51177506dc06bd70b69429d7c10e2e02c2b426b4772b2135439d7c0f9172fa"},
    "shoots": {"949be0f8cb9547c1e3aa4f051c29b5092c908e1df676f98203d66dc4fc117b14"},
    "aryeo_changes": {"b89490109bbb440f92aa27b8efd935f643e717cd23598883a19791a02dabe5e2"},
    "jobs": {"671cf5925293f4ddd626a5933b587ffe32a72fbd4b0dbad9e827566cc369a75b"},
}
IDENT = rb"[a-zA-Z0-9_]+"
UUID = r"[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}"
MARKER = re.compile(r"cutover smoke ([0-9a-f]{16}) (\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ)", re.ASCII)
HEADER = re.compile(rb"#\d{6}\s+\d{1,2}:\d{2}:\d{2}\s+server id \d+\s+end_log_pos \d+\s+(?:CRC32 0x[0-9a-fA-F]{8}\s+)?(.*)")
COLUMN = re.compile(rb"###   @([1-9][0-9]{0,2})=(.+) /\* ([A-Z][A-Z0-9]*(?:/[A-Z]+|\([0-9,]+\))?) meta=([0-9]+) nullable=([01]) is_null=([01]) \*/")
MAP = re.compile(rb"Table_map: \x60(" + IDENT + rb")\x60\.\x60(" + IDENT + rb")\x60 mapped to number (\d+)(?: \(has_generated_invisible_primary_key=0\))?")
ROWS = re.compile(rb"(Write|Update|Delete)_rows: table id (\d+)(?: flags: STMT_END_F)?")
OP = {b"Write": b"INSERT INTO", b"Update": b"UPDATE", b"Delete": b"DELETE FROM"}
ROW = re.compile(rb"### (INSERT INTO|UPDATE|DELETE FROM) \x60(" + IDENT + rb")\x60\.\x60(" + IDENT + rb")\x60")
GTID = re.compile(rb"SET @@SESSION\.GTID_NEXT= '([a-f0-9-]+:[1-9][0-9]*)'/\*!\*/;")
JOB = r"App\Jobs\CutoverSmokeJob"
HANDLER = r"Illuminate\Queue\CallQueuedHandler@call"


class InvalidRowEvidence(ValueError):
    """Safe refusal. Never include a decoded line, a field value or a job payload in the message."""


def require(condition):
    if not condition:
        raise InvalidRowEvidence("Unverified MySQL row evidence")


def verified_layout(layout):
    require(isinstance(layout, dict) and layout.keys() == LAYOUT_HASHES.keys())
    for table, columns in layout.items():
        require(isinstance(columns, (list, tuple)) and 0 < len(columns) <= 200
                and all(isinstance(col, str) and re.fullmatch("[a-z][a-z0-9_]*", col) for col in columns))
        require(hashlib.sha256("\0".join(columns).encode("ascii")).hexdigest() in LAYOUT_HASHES[table])


def column_value(line):
    match = COLUMN.fullmatch(line)
    require(match is not None)
    ordinal, value, kind, _, nullable, is_null = match.groups()
    require((value == b"NULL") == (is_null == b"1") and (is_null != b"1" or nullable == b"1"))
    if value != b"NULL":
        if kind.startswith((b"VARSTRING(", b"STRING(", b"BLOB", b"LONGBLOB", b"TEXT", b"LONGTEXT")):
            # mysqlbinlog's textual escapes can be indistinguishable from literal bytes.
            # Reject ambiguous hex/control escapes, inner quotes and non-ASCII values.
            require(len(value) >= 2 and value[:1] == value[-1:] == b"'"
                    and re.fullmatch(rb"[\x20-\x26\x28-\x7e]*", value[1:-1])
                    and not re.search(rb"\\x[0-9a-fA-F]{2}", value))
        elif kind in (b"LONGINT", b"INT", b"TINYINT", b"SHORTINT", b"MEDIUMINT"):
            require(re.fullmatch(rb"-?(?:0|[1-9][0-9]*)(?: \([0-9]+\))?", value))
        elif kind.startswith(b"DECIMAL("):
            require(re.fullmatch(rb"-?[0-9]+(?:\.[0-9]+)?", value))
        elif kind.startswith(b"DATETIME") or kind == b"DATE":
            require(re.fullmatch(rb"'[0-9]{4}-[0-9]{2}-[0-9]{2}(?: [0-9:]{8}(?:\.[0-9]{1,6})?)?'", value))
        else:
            raise InvalidRowEvidence()
    return int(ordinal), value, kind, nullable


def fields(lines, i, columns):
    values = {}
    for ordinal, name in enumerate(columns, 1):
        require(i < len(lines))
        pos, value, kind, nullable = column_value(lines[i])
        require(pos == ordinal)
        values[name] = (value, kind, nullable)
        i += 1
    return values, i


def row(lines, i, match, layout):
    action, _, table = match.groups()
    columns = layout[table.decode("ascii")]
    images = []
    for keyword in {b"UPDATE": (b"WHERE", b"SET"), b"DELETE FROM": (b"WHERE",), b"INSERT INTO": (b"SET",)}[action]:
        require(i < len(lines) and lines[i] == b"### " + keyword)
        values, i = fields(lines, i + 1, columns)
        images.append(values)
    return (action, table, images), i


def positive(values, name):
    raw = values[name][0]
    require(re.fullmatch(rb"[1-9][0-9]{0,18}", raw) and values[name][1] in (b"LONGINT", b"INT"))
    return int(raw)


def literal(value):
    return b"'" + value.encode("ascii") + b"'"


def changed(old, new):
    require(old.keys() == new.keys() and all(old[k][1:] == new[k][1:] for k in old))
    return {name for name in old if old[name] != new[name]}


def unique(pairs):
    result = dict(pairs)
    require(len(result) == len(pairs))
    return result


def verify_job(payload, token, created):
    require(payload.startswith(b"'{") and payload.endswith(b"}'") and len(payload) <= 4096)
    # Oracle's native formatter doubles the literal backslashes in Laravel's JSON.
    # This payload needs only paired backslashes. Reject unfamiliar native escapes.
    escaped = payload[1:-1]
    require(b"\\" not in escaped.replace(b"\\\\", b""))
    data = json.loads(escaped.replace(b"\\\\", b"\\"), object_pairs_hook=unique)
    require(isinstance(data, dict) and re.fullmatch(UUID, str(data.get("uuid", ""))) and type(data.get("createdAt")) is int)
    require(data["createdAt"] == created and data["maxTries"] == 1 and type(data["maxTries"]) is int
            and type(data["timeout"]) is int and data["timeout"] == 60 and data["failOnTimeout"] is False)
    expected = {"uuid", "displayName", "job", "maxTries", "maxExceptions", "failOnTimeout", "backoff", "timeout",
                "retryUntil", "data", "createdAt", "delay"}
    require(data.keys() == expected and data["displayName"] == JOB and data["job"] == HANDLER
            and all(data[x] is None for x in ("maxExceptions", "backoff", "retryUntil", "delay")))
    command = data["data"]
    require(isinstance(command, dict) and command.keys() == {"commandName", "command", "batchId"}
            and command["commandName"] == JOB and command["batchId"] is None)
    # Verify the exact serialized no-op job without invoking a PHP deserializer.
    names = {"tries": "i:1;", "timeout": "i:60;", "token": f's:16:"{token}";', "job": "N;",
             "connection": 's:8:"database";', "queue": 's:13:"cutover-smoke";',
             "messageGroup": "N;", "deduplicator": "N;", "delay": "N;", "afterCommit": "N;",
             "middleware": "a:0:{}", "chained": "a:0:{}", "chainConnection": "N;", "chainQueue": "N;",
             "chainCatchCallbacks": "N;"}
    require(isinstance(command["command"], str))
    body = command["command"]
    prefix = f'O:{len(JOB)}:"{JOB}":{len(names)}:{{'
    require(body.startswith(prefix) and body.endswith("}"))
    body = body[len(prefix):-1]
    for _ in range(len(names)):
        found = re.match(r's:([1-9][0-9]*):"([A-Za-z]+)";', body)
        require(found is not None and int(found[1]) == len(found[2]) and found[2] in names)
        value = names.pop(found[2])
        require(body[found.end():].startswith(value))
        body = body[found.end() + len(value):]
    require(not body and not names)


def verify_mutations(transactions, fixture):
    client, shoot, marker = fixture["client_id"], fixture["shoot_id"], fixture["marker"]
    require(type(client) is int and client > 0 and type(shoot) is int and shoot > 0 and isinstance(marker, str))
    marked = MARKER.fullmatch(marker)
    require(marked is not None)
    marked_time = int(datetime.strptime(marked[2], "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc).timestamp())
    expected = [[(b"UPDATE", b"users"), (b"UPDATE", b"shoots"), (b"INSERT INTO", b"aryeo_changes")],
                [(b"INSERT INTO", b"jobs")], [(b"UPDATE", b"jobs")], [(b"DELETE FROM", b"jobs")]]
    require([[(op, name) for op, name, _ in tx] for tx in transactions] == expected)
    users, shoots, trigger = (item[2] for item in transactions[0])
    for old, new in (users, shoots):
        require(changed(old, new) == {"company_notes"} and new["company_notes"][0] == literal(marker))
    require(positive(users[0], "id") == client and users[0]["email"][0] == literal("cutover-smoke@repro-cutover.invalid"))
    require(positive(shoots[0], "id") == shoot and positive(shoots[0], "client_id") == client
            and shoots[0]["shoot_type"][0] == literal("internal_test"))
    feed = trigger[0]
    require(positive(feed, "id") > 0 and positive(feed, "shoot_id") == shoot and positive(feed, "client_id") == client
            and feed["kind"][0] == literal("shoots.UPDATE")
            and re.fullmatch(rb"'\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(?:\.\d{1,6})?'", feed["created_at"][0]))

    queued = transactions[1][0][2][0]
    old, reserved = transactions[2][0][2]
    removed = transactions[3][0][2][0]
    require(queued == old and reserved == removed and changed(old, reserved) == {"attempts", "reserved_at"})
    created = positive(queued, "created_at")
    require(positive(queued, "id") > 0 and queued["queue"][0] == literal("cutover-smoke")
            and queued["attempts"][0] == b"0" and queued["reserved_at"][0] == b"NULL"
            and positive(queued, "available_at") == created and created >= marked_time
            and reserved["attempts"][0] == b"1" and positive(reserved, "reserved_at") >= created)
    stamp = feed["created_at"][0].strip(b"'").decode("ascii")
    trigger_time = datetime.fromisoformat(stamp).replace(tzinfo=timezone.utc)
    require(marked_time <= int(trigger_time.timestamp()) <= created)
    verify_job(queued["payload"][0], marked[1], created)


# mysqlbinlog emits session settings around the BEGIN Query and GTID. Accept only its
# numeric or standard character-set settings; any other Query or executable SQL refuses.
SETTING = re.compile(rb"(?:/\*![0-9]{5} )?SET (?:@@(?:session|SESSION)\.[a-zA-Z0-9_]+=(?:[0-9]+|DEFAULT)"
                     rb"(?:,\s*@@(?:session|SESSION)\.[a-zA-Z0-9_]+=(?:[0-9]+|DEFAULT))*"
                     rb"|TIMESTAMP=[0-9]+(?:\.[0-9]+)?)(?:\*/)?/\*!\*/;")
CHARSET = re.compile(rb"/\*!\\C [a-z0-9_]+ \*/\s*/\*!\*/;")
SESSION_VARS = {
    b"original_commit_timestamp", b"original_server_version", b"immediate_server_version",
    b"pseudo_thread_id", b"foreign_key_checks", b"sql_auto_is_null", b"unique_checks", b"autocommit",
    b"sql_mode", b"auto_increment_increment", b"auto_increment_offset", b"character_set_client",
    b"collation_connection", b"collation_server", b"lc_time_names", b"collation_database",
    b"default_collation_for_utf8mb4", b"sql_require_primary_key", b"default_table_encryption",
    b"sql_generate_invisible_primary_key",
}
OUTER = {
    b"", b"DELIMITER ;", b"DELIMITER /*!*/;", b"ROLLBACK/*!*/;", b"ROLLBACK /* added by mysqlbinlog */ /*!*/;",
    b"SET @@SESSION.GTID_NEXT= 'AUTOMATIC' /* added by mysqlbinlog */ /*!*/;",
    b"SET @OLD_COMPLETION_TYPE=@@COMPLETION_TYPE,COMPLETION_TYPE=0/*!*/;",
    b"/*!50003 SET @OLD_COMPLETION_TYPE=@@COMPLETION_TYPE,COMPLETION_TYPE=0*/;",
    b"/*!50003 SET COMPLETION_TYPE=@OLD_COMPLETION_TYPE*/;", b"/*!50530 SET @@SESSION.PSEUDO_SLAVE_MODE=0*/;",
    b"/*!50530 SET @@SESSION.PSEUDO_SLAVE_MODE=1*/;", b"/*!50530 SET @@SESSION.PSEUDO_REPLICA_MODE=1*/;",
    b"/*!50530 SET @@SESSION.PSEUDO_REPLICA_MODE=0*/;",
}


def setting(line):
    return line == b"/*!50718 SET TRANSACTION ISOLATION LEVEL READ COMMITTED*//*!*/;" or (SETTING.fullmatch(line) and
            all(name in SESSION_VARS for name in re.findall(rb"@@(?:session|SESSION)\.([A-Za-z0-9_]+)=", line))) or CHARSET.fullmatch(line)


def outer(lines):
    require(all(line in OUTER or line.startswith(b"# ") for line in lines))


def native_events(decoded):
    prior, body = None, []
    for line in decoded.split(b"\n"):
        if re.fullmatch(rb"# at [0-9]+", line) or not line:
            continue
        header = HEADER.fullmatch(line)
        if header:
            yield prior, body
            prior, body = header[1].strip(), []
        else:
            body.append(line)
    yield prior, body


def native_transactions(decoded, database, expected, layout):
    maps, queued_rows, transactions = {}, [], []
    tx, begun = None, False
    db = database.encode("ascii")
    for header, body in native_events(decoded):
        if header is None:
            outer(body)
        elif header.startswith((b"Start: binlog v 4, server v 8.4.13 ", b"Rotate to ", b"Previous-GTIDs")):
            require(tx is None)
            outer(body)
        elif re.match(rb"GTID\s+last_committed=\d+\s+sequence_number=\d+\s+rbr_only=yes(?:\s|$)", header):
            require(tx is None and len(transactions) < 4)
            lines = [line for line in body if not line.startswith(b"# ") and not setting(line)]
            require(len(lines) == 1)
            current = GTID.fullmatch(lines[0])
            require(current and current[1].decode("ascii") == expected[len(transactions)])
            tx, begun, maps = [], False, {}
        elif re.match(rb"Query\s+thread_id=\d+\s+exec_time=\d+\s+error_code=0(?:\s|$)", header):
            require(tx == [] and not begun)
            commands = [line for line in body if not setting(line) and line != b"use \x60" + db + b"\x60/*!*/;"]
            require(commands == [b"BEGIN", b"/*!*/;"] or commands == [b"BEGIN/*!*/;"])
            begun = True
        elif mapped := MAP.fullmatch(header):
            require(begun and not queued_rows and mapped[1] == db and mapped[2].decode("ascii") in layout
                    and body in ([], [b"# has_generated_invisible_primary_key=0"]))
            require(mapped[3] not in maps or maps[mapped[3]] == mapped[2])
            maps[mapped[3]] = mapped[2]
        elif rows := ROWS.fullmatch(header):
            require(begun and rows[2] in maps)
            queued_rows.append((OP[rows[1]], db, maps[rows[2]]))
            if not header.endswith(b"flags: STMT_END_F"):
                require(not body)
                continue
            # mysqlbinlog buffers the trigger's row text until the statement end.
            # Match one row per header, strictly in order, including the shoot trigger.
            require(set(maps.values()) == {name for _, _, name in queued_rows})
            end = 0
            for expected_row in queued_rows:
                require(end < len(body))
                op = ROW.fullmatch(body[end])
                require(op and op.groups() == expected_row)
                change, end = row(body, end + 1, op, layout)
                tx.append(change)
            require(end == len(body))
            maps.clear()
            queued_rows.clear()
        elif re.fullmatch(rb"Xid = \d+", header):
            require(begun and tx and not maps and not queued_rows and body and body[0] == b"COMMIT/*!*/;")
            outer(body[1:])
            transactions.append(tx)
            tx, begun, maps = None, False, {}
        else:
            raise InvalidRowEvidence()
    require(tx is None and len(transactions) == 4)
    return transactions


def verify_rows(native_decoded, *, database, server_uuid, expected_gtids, fixture, layout):
    """Return True only for a complete authenticated fixture edit and its single no-op database job."""
    try:
        require(isinstance(native_decoded, bytes) and 0 < len(native_decoded) <= 8 * 1024 * 1024
                and isinstance(database, str) and re.fullmatch("[A-Za-z0-9_]{1,64}", database)
                and isinstance(server_uuid, str) and re.fullmatch(UUID, server_uuid))
        require(isinstance(expected_gtids, (list, tuple)) and len(expected_gtids) == 4
                and all(isinstance(g, str) and re.fullmatch(re.escape(server_uuid) + ":[1-9][0-9]{0,18}", g)
                        for g in expected_gtids))
        numbers = [int(gtid.split(":")[1]) for gtid in expected_gtids]
        require(numbers == list(range(numbers[0], numbers[0] + 4)))
        require(isinstance(fixture, dict) and fixture.keys() == {"client_id", "shoot_id", "marker"})
        verified_layout(layout)
        verify_mutations(native_transactions(native_decoded, database, expected_gtids, layout), fixture)
        return True
    except (InvalidRowEvidence, KeyError, TypeError, ValueError, OverflowError, RecursionError):
        return False
