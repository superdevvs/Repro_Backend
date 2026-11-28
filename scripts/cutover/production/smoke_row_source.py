"""Read the four smoke transactions privately over the checked MySQL socket for the short rollback audit."""
from __future__ import annotations

import hashlib
import json
import os
import re
import subprocess
from pathlib import Path

import binlog_scan
import mysql_probe as probe
from adapter import guard

# Oracle mysqlbinlog 8.4.13 Linux x86_64, verified by the isolated native PITR rehearsal.
DECODER_SHA256 = "1087ee9d26ea42f396a1a7f302eb2cc549b2e05c32c62f8125f9d1b40bc08048"
# Sorted table names and ordinal column-name arrays in database/schema/mysql-schema.sql at the reviewed release.
LAYOUT_SHA256 = "06023313b2563a1152159193e6677e9bebbdd3683f5d08463ea417724aabf0cc"
MAX_BINLOG_BYTES = 128 * 1024
MAX_DECODED_BYTES = 2 * 1024 * 1024
TABLES = ("aryeo_changes", "jobs", "shoots", "users")


class Unavailable(ValueError):
    """Private attribution could not be completed. Keep the full reverse-export path."""


def require(condition):
    if not condition:
        raise Unavailable("The native smoke row scope could not be verified")


def _size(gtid_set):
    total = 0
    for intervals in gtid_set.intervals.values():
        previous = 0
        for first, last in sorted(intervals):
            require(first > previous)
            total += last - first + 1
            previous = last
    return total


def coordinates(config, window, listed):
    uuid = config["mysql"]["target_uuid"]
    require(len(listed) == 4 and all(item["class"] == "smoke" for item in listed))
    before, after = window[:2]
    gtids = tuple(item["gtid"] for item in listed)
    pattern = re.compile(re.escape(uuid) + r":([1-9][0-9]{0,18})")
    numbers = []
    for gtid in gtids:
        match = pattern.fullmatch(gtid)
        require(match and gtid not in before and gtid in after)
        numbers.append(int(match[1]))
    require(numbers == list(range(numbers[0], numbers[0] + 4))
            and before.issubset(after) and _size(after) - _size(before) == 4)
    name = listed[0]["file"]
    positions = [item["position"] for item in listed]
    require(re.fullmatch(binlog_scan.LOG_NAME, name) and all(item["file"] == name for item in listed)
            and all(type(pos) is int and pos >= 4 for pos in positions)
            and positions == sorted(set(positions)))
    # SQL's transaction records carry the start of each GTID. Stop at the fourth commit,
    # before any later activity or current mutable file tail.
    raw = probe._show(config, f"SHOW BINLOG EVENTS IN '{name}' FROM {positions[-1]} LIMIT 32")
    events = binlog_scan.parse_events(raw, name)
    require(events and events[0].position == positions[-1] and events[0].type == "Gtid")
    previous = positions[-1]
    for event in events:
        require(event.position == previous and event.end_position > previous)
        previous = event.end_position
        if event.type == "Xid":
            require(0 < event.end_position - positions[0] <= MAX_BINLOG_BYTES)
            return gtids, name, positions[0], event.end_position
        require(event is events[0] or event.type in {"Query", "Table_map", "Delete_rows"})
    raise Unavailable("The smoke transaction has no verified end")


def layout(config):
    import gate

    mysql = config["mysql"]
    database = probe._literal(mysql["database"])
    tables = ", ".join(probe._literal(name) for name in TABLES)
    sql = ("SELECT JSON_ARRAY(CURRENT_USER(), @@server_uuid, VERSION(), @@GLOBAL.skip_networking,"
           " @@GLOBAL.binlog_row_image, @@GLOBAL.binlog_format); "
           "SELECT JSON_ARRAY(TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME) FROM information_schema.COLUMNS "
           f"WHERE TABLE_SCHEMA = {database} AND TABLE_NAME IN ({tables}) ORDER BY TABLE_NAME, ORDINAL_POSITION")
    result = gate._client(config, "token_check_defaults", [sql])
    require(result.returncode == 0 and len(result.stdout) < 65536)
    lines = result.stdout.splitlines()
    require(lines and json.loads(lines[0]) == ["repro_token_check@localhost", mysql["target_uuid"],
                                               binlog_scan.SERVER_VERSION, 1, "FULL", "ROW"])
    names = {name: [] for name in TABLES}
    for line in lines[1:]:
        table, ordinal, column = json.loads(line)
        require(table in names and type(ordinal) is int and ordinal == len(names[table]) + 1
                and isinstance(column, str) and re.fullmatch(r"[a-z0-9_]+", column))
        names[table].append(column)
    raw = json.dumps(names, sort_keys=True, separators=(",", ":")).encode()
    require(hashlib.sha256(raw).hexdigest() == LAYOUT_SHA256)
    return names


def decode(config, name, start, stop):
    import resource

    mysql = config["mysql"]
    path = Path(mysql["client"]).with_name("mysqlbinlog")
    probe._protected(path)
    probe._protected(Path(mysql["probe_defaults"]), secret=True)
    digest = hashlib.sha256()
    with path.open("rb") as executable:
        for block in iter(lambda: executable.read(1024 * 1024), b""):
            digest.update(block)
    require(digest.hexdigest() == DECODER_SHA256)
    argv = [str(path), f"--defaults-file={mysql['probe_defaults']}", "--no-login-paths",
            "--read-from-remote-server", "--protocol=SOCKET", f"--socket={mysql['socket']}",
            "--verify-binlog-checksum", "--base64-output=DECODE-ROWS", "--verbose", "--verbose",
            f"--start-position={start}", f"--stop-position={stop}", name]

    def limit_output():
        resource.setrlimit(resource.RLIMIT_FSIZE, (MAX_DECODED_BYTES, MAX_DECODED_BYTES))

    # Row images can contain private columns. Use anonymous Linux memory and never print
    # or persist the decoder output or stderr. Enforce the limit in the child as it writes.
    with os.fdopen(os.memfd_create("cutover-smoke-rows", os.MFD_CLOEXEC), "w+b") as output:
        result = subprocess.run(argv, stdin=subprocess.DEVNULL, stdout=output, stderr=subprocess.DEVNULL,
                                env=probe.CLIENT_ENVIRONMENT, timeout=probe.CLIENT_SECONDS, preexec_fn=limit_output)
        require(result.returncode == 0 and 0 < output.tell() < MAX_DECODED_BYTES)
        output.seek(0)
        return output.read(MAX_DECODED_BYTES)


def verified(config, window, listed):
    """Return one aggregate boolean. Missing tools, unsupported layouts and row ambiguity have no exemption."""
    import gate
    try:
        import smoke_rows

        gtids, name, start, stop = coordinates(config, window, listed)
        names = layout(config)
        raw = decode(config, name, start, stop)
        checked = window[2]["report"]["checks"]
        fixture = {"client_id": checked["client_id"], "shoot_id": checked["shoot_id"], "marker": checked["value"]}
        return smoke_rows.verify_rows(raw, database=config["mysql"]["database"],
                                      server_uuid=config["mysql"]["target_uuid"], expected_gtids=gtids,
                                      fixture=fixture, layout=names) is True
    except (ImportError, OSError, ValueError, TypeError, KeyError, guard.Refused, gate.GateRefused,
            subprocess.SubprocessError):
        return False
