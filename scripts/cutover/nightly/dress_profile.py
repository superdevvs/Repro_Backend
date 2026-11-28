"""Derive and pin the dress-rehearsal host profile, workload and endpoint lists.

Reads JSON profiles and, for the busiest list, one private SQLite copy opened
read-only. It never starts the application, sends a request or writes a database.
"""
from __future__ import annotations

import argparse
from contextlib import closing
from datetime import datetime, timedelta, timezone
import hashlib
import json
from pathlib import Path
import re
import sqlite3

from scripts.cutover.readiness import check
from scripts.cutover.runner import is_sha256

BUSIEST_SCHEMA = "repro.dress-rehearsal.busiest-endpoints.v1"
WORKLOAD_SCHEMA = "repro.dress-rehearsal.workload.v1"
HOST_SCHEMA = "repro.dress-rehearsal.host-profile.v1"
LABEL = re.compile(r"(?:read|write):[a-z0-9-]{1,48}|[A-Z]{3,7} /[^\s]{0,240}")
STORED_TIME = "%Y-%m-%d %H:%M:%S"
DURABLE_MYSQL = {
    "skip_networking": "ON", "binlog": "ON", "binlog_format": "ROW", "binlog_row_image": "FULL",
    "gtid_mode": "ON", "sync_binlog": 1, "innodb_flush_log_at_trx_commit": 1, "innodb_doublewrite": "ON",
}


class Refused(ValueError):
    """The profile input is unsafe, incomplete or weaker than the plan."""


def need(condition: object, reason: str) -> None:
    if not condition:
        raise Refused(reason)


def file_sha256(path: Path) -> str:
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def busiest_report(snapshot: Path, snapshot_sha256: str, *, as_of_utc: str, window_hours: int = 24) -> dict:
    """Rank method and route-template labels by request count in the window ending at as_of_utc."""
    need(re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z", as_of_utc), "as-of must be UTC seconds")
    need(type(window_hours) is int and 1 <= window_hours <= 24 * 7, "window must be 1 to 168 hours")
    need(not snapshot.is_symlink() and file_sha256(snapshot) == snapshot_sha256, "snapshot differs from its pin")
    end = datetime.strptime(as_of_utc, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
    bounds = ((end - timedelta(hours=window_hours)).strftime(STORED_TIME), end.strftime(STORED_TIME))
    with closing(sqlite3.connect(snapshot.resolve(strict=True).as_uri() + "?mode=ro&immutable=1", uri=True)) as db:
        db.execute("PRAGMA query_only=ON")
        db.execute("PRAGMA trusted_schema=OFF")
        rows = db.execute(
            "SELECT method, path, COUNT(*) FROM system_overview_request_traces"
            " WHERE occurred_at > ? AND occurred_at <= ? GROUP BY method, path", bounds).fetchall()
        unclassified = db.execute(
            "SELECT COUNT(*) FROM system_overview_request_traces WHERE occurred_at NOT GLOB"
            " '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9] [0-9][0-9]:[0-9][0-9]:[0-9][0-9]'").fetchone()[0]
    need(file_sha256(snapshot) == snapshot_sha256, "snapshot changed while it was read")
    counts = {}
    for method, path, requests in rows:
        label = f"{str(method).upper()} {path}"
        need(LABEL.fullmatch(label), "a stored method or route template is not a safe label")
        counts[label] = counts.get(label, 0) + requests
    ranked = sorted(counts.items(), key=lambda item: (-item[1], item[0]))
    need(len(ranked) >= check.SPEED_ENDPOINT_COUNT, "fewer than 50 distinct endpoints were observed in the window")
    return {
        "schema": BUSIEST_SCHEMA, "result": "busiest-endpoints-observed", "snapshot_sha256": snapshot_sha256,
        "as_of_utc": as_of_utc, "window_hours": window_hours, "total_requests": sum(counts.values()),
        "distinct_endpoints": len(ranked), "unclassified_timestamp_rows": unclassified, "database_writes": False,
        "endpoints": [{"label": label, "requests": requests}
                      for label, requests in ranked[:check.SPEED_ENDPOINT_COUNT]],
    }


def labels(values: object, *, count: int | None = None) -> list[str]:
    need(isinstance(values, list) and values and all(isinstance(value, str) and LABEL.fullmatch(value)
                                                     for value in values), "endpoint labels are malformed")
    need(len(set(values)) == len(values), "endpoint labels must be unique")
    need(count is None or len(values) == count, f"exactly {count} busiest endpoint labels are required")
    return values


def validate_workload(record: dict) -> dict:
    need(isinstance(record, dict) and record.get("schema") == WORKLOAD_SCHEMA, "workload schema differs")
    phases = record.get("phases")
    need(isinstance(phases, dict) and set(phases) == {"warmup", "peak", "surge"}, "workload needs warmup, peak and surge")
    for phase in phases.values():
        need(isinstance(phase, dict) and all(type(phase.get(name)) is int and phase[name] > 0
                                             for name in ("duration_ms", "requests_per_minute")),
             "each phase needs a positive duration and rate")
    need(phases["surge"]["requests_per_minute"] > phases["peak"]["requests_per_minute"]
         >= record["observed"]["peak_requests_per_minute"], "peak must cover the observed peak and surge exceed it")
    stress, crash = record.get("stress"), record.get("crash")
    need(isinstance(stress, dict) and stress.get("duration_ms", 0) >= check.STRESS_MIN_MS
         and stress.get("parallel_clients", 0) >= check.STRESS_MIN_CLIENTS
         and stress.get("queue_backlog_jobs", 0) >= 1, "stress must meet the plan's ten minutes at 20 clients")
    need(isinstance(crash, dict) and crash.get("mysql_kills", 0) >= check.CRASH_KILL_COUNT
         and 0 < crash.get("recovery_limit_ms", 0) <= check.CRASH_RECOVERY_MS,
         "crash test must kill MySQL three times with a 60 second recovery limit")
    labels(record.get("critical_endpoints"))
    busiest, source = record.get("busiest_endpoints"), record.get("busiest_endpoints_report_sha256")
    need((busiest is None) == (source is None), "busiest endpoints need their reviewed report digest")
    if busiest is not None:
        labels(busiest, count=check.SPEED_ENDPOINT_COUNT)
        need(is_sha256(source), "busiest endpoint report digest is malformed")
    return record


def validate_host_profile(record: dict) -> dict:
    need(isinstance(record, dict) and record.get("schema") == HOST_SCHEMA, "host profile schema differs")
    mysql = record.get("mysql")
    need(isinstance(mysql, dict) and all(mysql.get(name) == value for name, value in DURABLE_MYSQL.items()),
         "host profile must keep the durable MySQL settings")
    need(isinstance(record.get("host"), dict) and isinstance(record.get("priority"), dict), "host profile is incomplete")
    return record


def fill_workload(workload: dict, busiest: dict, busiest_sha256: str) -> dict:
    validate_workload(workload)
    need(isinstance(busiest, dict) and busiest.get("schema") == BUSIEST_SCHEMA
         and busiest.get("result") == "busiest-endpoints-observed", "busiest endpoint report differs")
    entries = busiest.get("endpoints")
    need(isinstance(entries, list) and len(entries) == check.SPEED_ENDPOINT_COUNT,
         "the busiest report must list exactly 50 endpoints")
    filled = dict(workload) | {"busiest_endpoints": [entry["label"] for entry in entries],
                               "busiest_endpoints_report_sha256": busiest_sha256}
    return validate_workload(filled)


def load_pinned(path: Path) -> tuple[dict, str]:
    try:
        return check.load(path), file_sha256(path)
    except (check.Missing, check.Failed) as error:
        raise Refused(str(error)) from error


def policy_pins(host_profile: Path, workload: Path) -> dict:
    host, host_sha = load_pinned(host_profile)
    load, load_sha = load_pinned(workload)
    validate_host_profile(host)
    validate_workload(load)
    return {"host_profile_sha256": host_sha, "workload_sha256": load_sha,
            "critical_endpoints": load["critical_endpoints"], "busiest_endpoints": load["busiest_endpoints"]}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    busiest = commands.add_parser("busiest", help="rank the 50 busiest endpoints on a private copy")
    busiest.add_argument("--snapshot", type=Path, required=True)
    busiest.add_argument("--snapshot-sha256", required=True)
    busiest.add_argument("--as-of-utc", required=True)
    busiest.add_argument("--window-hours", type=int, default=24)
    fill = commands.add_parser("fill-workload", help="write a new workload with the reviewed busiest list")
    fill.add_argument("--workload", type=Path, required=True)
    fill.add_argument("--busiest", type=Path, required=True)
    fill.add_argument("--busiest-sha256", required=True)
    fill.add_argument("--output", type=Path, required=True)
    pins = commands.add_parser("pins", help="print the policy values for the reviewed profile files")
    pins.add_argument("--host-profile", type=Path, required=True)
    pins.add_argument("--workload", type=Path, required=True)
    args = parser.parse_args()
    try:
        if args.command == "busiest":
            result = busiest_report(args.snapshot, args.snapshot_sha256, as_of_utc=args.as_of_utc,
                                    window_hours=args.window_hours)
        elif args.command == "fill-workload":
            report = check.load(args.busiest, expected_sha256=args.busiest_sha256)
            filled = fill_workload(check.load(args.workload), report, args.busiest_sha256)
            with args.output.open("x", encoding="utf-8") as stream:
                stream.write(json.dumps(filled, indent=2) + "\n")
            result = {"result": "workload-written", "workload_sha256": file_sha256(args.output)}
        else:
            result = policy_pins(args.host_profile, args.workload)
    except (Refused, check.Missing, check.Failed, OSError, sqlite3.Error) as error:
        print(json.dumps({"result": "dress-profile-refused", "detail": str(error)}))
        return 1
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
