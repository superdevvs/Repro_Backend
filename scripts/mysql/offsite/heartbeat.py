#!/usr/bin/env python3
"""Record or verify one exact UTC recovery heartbeat on a reviewed MySQL socket."""
from __future__ import annotations

import argparse
from contextlib import suppress
import datetime as dt
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import threading

sys.path.insert(0, str(Path(__file__).resolve().parent))
import collect_closed_binlogs as collector
import publish_backup as backup
from rotate_closed_binlogs import send, until


TABLE = "repro_recovery_heartbeat"
WRITER = "repro_recovery_heartbeat"
READER = "repro_heartbeat_reader"
WINDOW_SECONDS = 60
PLAN_FIELDS = {"version", "collector_plan", "collector_plan_sha256", "defaults_file", "runtime_dir", "timeout_seconds"}
IDENTITY = (
    "SELECT 'heartbeat-source',CONNECTION_ID(),CURRENT_USER(),CURRENT_ROLE(),VERSION(),@@server_uuid,"
    "@@server_id,@@global.skip_networking,@@global.log_bin,@@session.sql_log_bin,@@session.autocommit,"
    "COALESCE(@@global.log_bin_basename,''),@@global.mandatory_roles;"
)
PRE_END = "heartbeat-preflight-end"
READ_END = "heartbeat-read-end"
WRITE_END = "heartbeat-write-end"
TIMESTAMP = re.compile(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z")


class HeartbeatUnverified(backup.Refusal):
    def __init__(self, marker: str):
        super().__init__("a heartbeat insert was requested but its result could not be verified")
        self.marker = marker


def parse_plan(raw: bytes, pin: str) -> dict:
    backup.need(0 < len(raw) <= collector.PLAN_LIMIT and backup.sha256(raw) == pin, "heartbeat plan pin or size differs")
    plan = json.loads(raw)
    backup.need(isinstance(plan, dict) and set(plan) == PLAN_FIELDS and type(plan["version"]) is int
                and plan["version"] == 1, "unexpected heartbeat plan fields")
    for field in ("collector_plan", "defaults_file", "runtime_dir"):
        value = plan[field]
        backup.need(isinstance(value, str) and 0 < len(value) <= 4096
                    and re.fullmatch(r"/[A-Za-z0-9_./-]+", value) is not None
                    and Path(value).as_posix() == value and ".." not in Path(value).parts, "invalid exact heartbeat plan path")
    backup.need(isinstance(plan["collector_plan_sha256"], str) and backup.HEX.fullmatch(plan["collector_plan_sha256"])
                and type(plan["timeout_seconds"]) is int and 3 <= plan["timeout_seconds"] <= 60,
                "invalid heartbeat plan timeout or source pin")
    return plan


def read_plan(path: Path, pin: str) -> dict:
    from scheduled_backup import trusted_file

    trusted_file(path, plan=True)
    with path.open("rb") as stream:
        return parse_plan(stream.read(collector.PLAN_LIMIT + 1), pin)


def utc(value: str) -> dt.datetime:
    backup.need(TIMESTAMP.fullmatch(value) is not None, "heartbeat timestamps must be exact UTC seconds")
    return dt.datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=dt.timezone.utc)


def stamp(value: dt.datetime) -> str:
    return value.strftime("%Y-%m-%dT%H:%M:%SZ")


def floor_minute(value: dt.datetime) -> dt.datetime:
    return value.replace(second=0, microsecond=0)


def connection_id(line: str, source: dict, account: str) -> str:
    fields = line.split("\t")
    backup.need(len(fields) == 13 and re.fullmatch(r"[1-9][0-9]{0,19}", fields[1]) is not None,
                "unexpected heartbeat session metadata")
    backup.need(fields[:8] == ["heartbeat-source", fields[1], account + "@localhost", "NONE", source["server_version"],
                               source["identity"], str(source["server_id"]), "1"]
                and fields[8] in ("0", "1") and fields[9] in ("0", "1") and fields[10] == "1" and fields[12] == "",
                "heartbeat source, narrow account, or session identity differs")
    if account == WRITER:
        backup.need(fields[8:12] == ["1", "1", "1", source["binlog_base"]], "recording requires enabled binary logging")
    return fields[1]


def qualified(source: dict) -> str:
    database = source["database"]
    backup.need(collector.staging.chain.base_backup.DB_NAME.fullmatch(database) is not None, "invalid heartbeat schema")
    return f"`{database}`.`{TABLE}`"


def preflight(source: dict) -> str:
    condition = f"TABLE_SCHEMA='{source['database']}' AND TABLE_NAME='{TABLE}'"
    return (IDENTITY + "SELECT 'heartbeat-table',TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE " + condition + ";"
            "SELECT 'heartbeat-column',COLUMN_NAME,DATA_TYPE,DATETIME_PRECISION,IS_NULLABLE,COLUMN_KEY,"
            "COALESCE(COLUMN_DEFAULT,'NULL'),EXTRA FROM information_schema.COLUMNS WHERE " + condition +
            " ORDER BY ORDINAL_POSITION;SHOW GRANTS;SELECT '" + PRE_END + "';")


def validate_preflight(rows: list[str], source: dict, account: str) -> str:
    backup.need(len(rows) in (5, 6), "unexpected heartbeat preflight observations")
    session = connection_id(rows[0], source, account)
    backup.need(rows[1:4] == [
        "heartbeat-table\tBASE TABLE\tInnoDB",
        "heartbeat-column\tminute_utc\tdatetime\t0\tNO\tPRI\tNULL\t",
        "heartbeat-column\trecorded_utc\tdatetime\t0\tNO\t\tNULL\t",
    ], "reviewed heartbeat table layout differs")
    target = f"`{account}`@`localhost`"
    permissions = "SELECT, INSERT" if account == WRITER else "SELECT"
    must = f"GRANT {permissions} ON {qualified(source)} TO {target}"
    grants = set(rows[4:])
    backup.need(len(grants) == len(rows[4:]) and must in grants
                and grants <= {must, "GRANT USAGE ON *.* TO " + target}, "heartbeat credential grants are too broad or incomplete")
    return session


def row_query(table: str, condition: str) -> str:
    return ("SELECT 'heartbeat-row',DATE_FORMAT(minute_utc,'%Y-%m-%dT%H:%i:%sZ'),"
            "DATE_FORMAT(recorded_utc,'%Y-%m-%dT%H:%i:%sZ') FROM " + table + " WHERE " + condition +
            " ORDER BY minute_utc DESC LIMIT 1;")


def row_times(row: str) -> tuple[dt.datetime, dt.datetime]:
    values = row.split("\t")
    backup.need(len(values) == 3 and values[0] == "heartbeat-row", "unexpected heartbeat row framing")
    minute, recorded = utc(values[1]), utc(values[2])
    backup.need(minute == floor_minute(recorded), "heartbeat minute key does not match recording time")
    return minute, recorded


def record(source: dict, session: str, stdin, stdout, prove_socket) -> dict:
    table = qualified(source)
    sample = ("SET @heartbeat_now=UTC_TIMESTAMP(0);"
              "SET @heartbeat_minute=CAST(DATE_FORMAT(@heartbeat_now,'%Y-%m-%d %H:%i:00') AS DATETIME);"
              "SELECT 'heartbeat-sample',DATE_FORMAT(@heartbeat_now,'%Y-%m-%dT%H:%i:%sZ');" +
              row_query(table, "minute_utc=@heartbeat_minute") + IDENTITY + "SELECT '" + READ_END + "';")
    send(stdin, sample)
    before = until(stdout, READ_END)
    backup.need(len(before) in (2, 3) and before[0].startswith("heartbeat-sample\t")
                and connection_id(before[-1], source, WRITER) == session, "heartbeat changed verified connection during sampling")
    sampled = utc(before[0].split("\t", 1)[1])
    minute = floor_minute(sampled)
    prove_socket()
    if len(before) == 3:
        key, existing = row_times(before[1])
        backup.need(key == minute and existing <= sampled, "existing heartbeat is not the exact sampled slot")
        return {"result": "heartbeat-already-recorded", "minute_utc": stamp(minute),
                "recorded_utc": stamp(existing), "record_requested": False}

    insert = (f"INSERT INTO {table} (minute_utc,recorded_utc) SELECT @heartbeat_minute,@heartbeat_now FROM DUAL "
              "WHERE UTC_TIMESTAMP(0)>=@heartbeat_now AND UTC_TIMESTAMP(0)<@heartbeat_minute+INTERVAL 1 MINUTE;"
              "SELECT 'heartbeat-effect',ROW_COUNT();" + row_query(table, "minute_utc=@heartbeat_minute") +
              IDENTITY + "SELECT '" + WRITE_END + "';")
    try:
        send(stdin, insert)
        after = until(stdout, WRITE_END)
        backup.need(len(after) == 3 and after[0] == "heartbeat-effect\t1" and
                    connection_id(after[-1], source, WRITER) == session and row_times(after[1]) == (minute, sampled),
                    "heartbeat insert did not yield exactly the sampled committed row")
        prove_socket()
    except (backup.Refusal, OSError, ValueError, KeyboardInterrupt) as error:
        raise HeartbeatUnverified(stamp(minute)) from error
    return {"result": "heartbeat-recorded", "minute_utc": stamp(minute), "recorded_utc": stamp(sampled), "record_requested": True}


def verify(source: dict, session: str, target: dt.datetime, stdin, stdout, prove_socket) -> dict:
    precise = target.strftime("%Y-%m-%d %H:%M:%S")
    minute = floor_minute(target).strftime("%Y-%m-%d %H:%M:%S")
    select = row_query(qualified(source), f"minute_utc<='{minute}' AND recorded_utc<='{precise}'")
    send(stdin, select + IDENTITY + "SELECT '" + READ_END + "';")
    rows = until(stdout, READ_END)
    backup.need(len(rows) in (1, 2) and connection_id(rows[-1], source, READER) == session,
                "recovery heartbeat changed verified connection")
    prove_socket()
    backup.need(len(rows) == 2, "no recovered marker precedes the chosen target")
    minute, recorded = row_times(rows[0])
    age = (target - recorded).total_seconds()
    backup.need(0 <= age <= WINDOW_SECONDS, "recovered marker is outside the preceding 60 seconds")
    return {"result": "recovered-heartbeat-verified", "minute_utc": stamp(minute), "recorded_utc": stamp(recorded),
            "target_utc": stamp(target), "recovery_gap_seconds": int(age), "record_requested": False}


def exchange(source: dict, target: dt.datetime | None, stdin, stdout, prove_socket) -> dict:
    account = WRITER if target is None else READER
    qualified(source)
    send(stdin, preflight(source))
    session = validate_preflight(until(stdout, PRE_END), source, account)
    prove_socket()
    if target is None:
        return record(source, session, stdin, stdout, prove_socket)
    return verify(source, session, target, stdin, stdout, prove_socket)


def run(plan: dict, target: dt.datetime | None) -> dict:
    import fcntl
    from scheduled_backup import state_lock, trusted_file

    source = collector.read_plan(Path(plan["collector_plan"]), plan["collector_plan_sha256"])
    directory = Path(plan["runtime_dir"])
    backup.private_path(directory, directory=True)
    backup.need(directory != Path(source["spool_dir"]) and plan["defaults_file"] != source["defaults_file"],
                "heartbeat needs a separate runtime directory and credential file")
    trusted_file(Path(source["mysql"]))
    socket = Path(source["socket"])
    socket_id = collector.stat_identity(collector.protected_source(socket, source["mysql_owner_uid"], socket=True))

    def prove_socket():
        backup.need(socket_id == collector.stat_identity(collector.protected_source(socket, source["mysql_owner_uid"],
                    socket=True)), "heartbeat socket changed")

    with os.fdopen(state_lock(directory / "heartbeat.lock", os.O_RDWR | os.O_CREAT), "r+b") as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise backup.Refusal("a heartbeat operation is already running") from error
        with tempfile.TemporaryDirectory(dir=directory, prefix=".heartbeat-") as scratch:
            options = Path(scratch) / "heartbeat.cnf"
            collector.prepare_credentials(plan, options)
            account = WRITER if target is None else READER
            backup.need(f"user={account}".encode() == options.read_bytes().splitlines()[1], "unexpected heartbeat option-file account")
            args = [source["mysql"], "--defaults-file=" + str(options), "--no-login-paths", "--protocol=SOCKET",
                    "--socket=" + str(socket), "--batch", "--raw", "--skip-column-names", "--binary-mode",
                    "--unbuffered", "--skip-reconnect", "--connect-timeout=" + str(plan["timeout_seconds"])]
            with subprocess.Popen(args, env=backup.SAFE_ENV, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                  stderr=subprocess.DEVNULL) as client:
                deadline = threading.Timer(plan["timeout_seconds"], client.kill)
                deadline.daemon = True
                deadline.start()
                result = None
                try:
                    result = exchange(source, target, client.stdin, client.stdout, prove_socket)
                    client.stdin.close()
                    backup.need(client.wait(timeout=plan["timeout_seconds"]) == 0, "heartbeat client did not finish cleanly")
                except (backup.Refusal, OSError, ValueError, subprocess.SubprocessError, KeyboardInterrupt) as error:
                    if result is not None and result["record_requested"]:
                        raise HeartbeatUnverified(result["minute_utc"]) from error
                    raise
                finally:
                    deadline.cancel()
                    with suppress(OSError, subprocess.SubprocessError):
                        if client.poll() is None:
                            client.kill()
                        client.wait(timeout=5)
    return result | {"source_uuid": source["identity"], "remote_accessed": False}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=("record", "verify"))
    parser.add_argument("--target-utc")
    parser.add_argument("--plan", type=Path, required=True)
    parser.add_argument("--plan-sha256", required=True)
    args = parser.parse_args()
    os.umask(0o077)
    try:
        backup.need((args.mode == "verify") == (args.target_utc is not None), "choose a UTC target only for recovery verification")
        target = utc(args.target_utc) if args.target_utc is not None else None
        result = run(read_plan(args.plan, args.plan_sha256), target)
    except HeartbeatUnverified as error:
        result = {"result": "heartbeat-unverified", "minute_utc": error.marker, "record_requested": True,
                  "reason": str(error), "remote_accessed": False}
    except backup.Refusal as error:
        result = {"result": "REFUSED", "reason": str(error), "record_requested": False, "remote_accessed": False}
    except (OSError, ValueError, TypeError, RecursionError, subprocess.SubprocessError, KeyboardInterrupt):
        result = {"result": "REFUSED", "reason": "invalid heartbeat input or local I/O failure",
                  "record_requested": False, "remote_accessed": False}
    print(json.dumps(result, sort_keys=True))
    return int(result["result"] in ("REFUSED", "heartbeat-unverified"))


if __name__ == "__main__":
    raise SystemExit(main())
