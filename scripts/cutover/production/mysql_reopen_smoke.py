"""The private write-smoke record used by MySQL reopen and the short-rollback audit."""

from __future__ import annotations

import re
import time
from datetime import datetime, timezone
from pathlib import Path

import adapter as host
import binlog_scan
import evidence
from runner import Journal, UnsafeCutover

VERSION = 2
TABLES = {"users", "shoots", "aryeo_changes", "jobs"}
VALUE = re.compile(r"cutover smoke [0-9a-f]{16} [0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z")


def require(condition, message):
    if not condition:
        raise UnsafeCutover(message)


def gtid_set(raw):
    require(isinstance(raw, str), "The write smoke needs a GTID set")
    try:
        return binlog_scan.GtidSet.parse(raw)
    except binlog_scan.Unreadable as error:
        raise UnsafeCutover("Malformed GTID set in the write smoke") from error


def load_profile(config):
    import admit

    raw = host.guard.read_protected(Path(config["paths"]["wrappers_mysql"]))
    profile = admit.load_profile("cutover-smoke", raw)
    require(profile["engine"] == "mysql" and profile["action"] == "queue-worker"
            and profile["user"] == config["web_user"]
            and profile["argv"] == [host.QUEUE_PHP, str(Path(config["app_root"]) / "artisan"),
                                    "cutover:smoke", "--write", "--json"],
            "The installed MySQL wrapper does not run the configured application's write smoke")
    return profile


def binding(config, expected, run_id, receipt_sha256, profile):
    require(expected["engine"] == "mysql" and expected["server_uuid"] == config["mysql"]["target_uuid"]
            and expected["database"] == config["mysql"]["database"], "The smoke route differs from the target")
    require(set(config["mysql"]["smoke_tables"]) == TABLES, "The host smoke tables differ from the application")
    return {"run_id": run_id, "receipt_sha256": receipt_sha256, "target": expected, "fixtures": config["smoke"],
            "profile_sha256": host.guard.sha256(Journal.encode(profile))}


def verify_report(report, bound, profile, app_root, started_at, finished_at):
    import grp
    import pwd

    require(isinstance(report, dict) and type(report.get("version")) is int and report["version"] == 1
            and report.get("ok") is True and report.get("mode") == "write" and report.get("problems") == [],
            "The application returned no successful version 1 write smoke")
    require(report.keys() == {"version", "mode", "ok", "engine", "connection", "database", "server_uuid", "server_version",
                              "config_cached", "config_path", "process", "route_values", "problems", "checks"},
            "The application write-smoke report has unexpected fields")
    target = bound["target"]
    evidence._route_values(report, target, "The write smoke")
    require(report.get("config_cached") is True and isinstance(report.get("config_path"), str)
            and Path(report["config_path"]).resolve() == (Path(app_root) / "bootstrap/cache/config.php").resolve(),
            "The write smoke did not use the live configuration cache")
    require(report.get("connection") == report["route_values"].get("database.default"),
            "The write smoke connection differs from the pinned default route")
    process = evidence._process(report, profile["user"], "The write smoke")
    require(process.get("euid") == pwd.getpwnam(profile["user"]).pw_uid
            and type(process.get("egid")) is int and process["egid"] == grp.getgrnam(profile["group"]).gr_gid
            and process.get("group") == profile["group"] and process.get("umask") == profile["umask"]
            and isinstance(process.get("cwd"), str) and Path(process["cwd"]).resolve() == Path(profile["cwd"]).resolve(),
            "The write smoke ran outside its installed wrapper profile")
    checks = report.get("checks")
    require(isinstance(checks, dict) and all(type(checks.get(key)) is int and checks[key] == bound["fixtures"][key]
                                             for key in ("client_id", "shoot_id")),
            "The write smoke did not confirm the two marked fixture IDs")
    require(all(checks.get(name) is True for name in (*evidence.SMOKE_READS, "read_back"))
            and checks.get("smoke_queue") == "database:cutover-smoke" and checks.get("smoke_queue_paused") is False
            and type(checks.get("smoke_queue_pending")) is int and checks["smoke_queue_pending"] == 0,
            "The write smoke did not confirm the reads, decrypt, edits and empty active smoke queue")
    job = checks.get("job")
    require(isinstance(job, dict) and job.keys() == {"queued", "ran", "row_left", "failed"} and job.get("queued") is True and job.get("ran") is True
            and job.get("row_left") is False and job.get("failed") is False,
            "The dedicated write-smoke job was not verified")
    tables = checks.get("smoke_tables")
    require(isinstance(tables, list) and len(tables) == len(TABLES) and all(isinstance(t, str) for t in tables)
            and set(tables) == TABLES and isinstance(checks.get("value"), str) and VALUE.fullmatch(checks["value"]),
            "The write smoke did not report its fixture marker and approved tables")
    try:
        stamp = datetime.strptime(checks["value"][-20:], "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
    except ValueError:
        raise UnsafeCutover("Invalid timestamp in the smoke edit") from None
    require(started_at <= stamp.timestamp() <= finished_at, "The smoke edit was not generated during this attempt")


def verified_attempts(record, bound, profile, app_root):
    """Validate every attempt, including incomplete writes, without widening any GTID interval."""
    require(type(record.get("version")) is int and record["version"] == VERSION and record.get("binding") == bound,
            "Write-smoke evidence does not belong to this run, receipt, target, route, fixtures and wrapper")
    attempts = record.get("attempts")
    require(isinstance(attempts, list) and attempts and type(record.get("reuses")) is int and record["reuses"] >= 0,
            "Malformed write-smoke attempt history")
    previous = None
    verified = []
    markers = set()
    reuses = 0
    for attempt in attempts:
        require(isinstance(attempt, dict) and attempt.keys() - {"schema_verified_before_write"} == {
            "boot_id", "authority_sha256", "gtid_before", "gtid_after", "started_at", "finished_at", "report", "reuses"
        } and type(attempt.get("schema_verified_before_write", False)) is bool,
                "Malformed write-smoke attempt or missing boot and authority binding")
        require(host.guard.valid_uuid(attempt["boot_id"]) and host.guard.matches(r"[0-9a-f]{64}", attempt["authority_sha256"])
                and type(attempt["reuses"]) is int and attempt["reuses"] >= 0, "Malformed write-smoke authority or reuse count")
        reuses += attempt["reuses"]
        started, finished = attempt["started_at"], attempt["finished_at"]
        require(type(started) is int and (finished is None or (type(finished) is int and finished >= started)),
                "Malformed write-smoke timestamps")
        before = gtid_set(attempt["gtid_before"])
        require(previous is None or previous.issubset(before), "Write-smoke attempts have overlapping or reset GTIDs")
        end = attempt["gtid_after"]
        require((finished is None) == (end is None), "Incomplete write-smoke end boundary")
        after = None if end is None else gtid_set(end)
        require(after is None or before.issubset(after), "The GTID history reset during the write smoke")
        previous = before if after is None else after
        if attempt["report"] is not None:
            require(after is not None and not after.issubset(before), "The write smoke has no committed GTIDs")
            verify_report(attempt["report"], bound, profile, app_root, started, finished)
            marker = attempt["report"]["checks"]["value"]
            require(marker not in markers, "A write-smoke attempt repeated an earlier application marker")
            markers.add(marker)
            verified.append(attempt)
        else:
            require(attempt["reuses"] == 0, "An incomplete write smoke cannot have been reused")
    require(record["reuses"] == reuses, "Write-smoke reuse counts disagree")
    return verified


def verified_window(record, bound, profile, app_root):
    """The rollback classifier currently proves exactly one completed smoke interval."""
    windows = verified_attempts(record, bound, profile, app_root)
    require(len(windows) <= 1, "Multiple verified write-smoke windows require a full reverse export")
    return windows[0] if windows else None


def current_authority(adapter):
    _, proof = adapter._verify_authority("mysql")
    return host.guard.read_json(proof)["boot_id"], host.guard.sha256(proof)


def read(path):
    try:
        raw = host.guard.read_protected(path)
    except FileNotFoundError:
        return None
    record = evidence.json_object(raw.decode("utf-8"))
    host.guard.require(record is not None, "Malformed write-smoke record")
    return record


def _save(path, record):
    require(len(Journal.encode(record)) <= host.guard.MAX_RECORD_BYTES, "The write-smoke history exceeds the safe record size")
    host._write_atomic(path, record, 0o600)


def run(adapter, expected, receipt_sha256, deadline, read_gtid):
    """Called under the controller lock. read_gtid() reads the pinned server within the action deadline."""
    import admit

    config = adapter.config
    profile = load_profile(config)
    active = host.guard.read_protected(adapter.paths["wrappers"])
    require(admit.load_profile("cutover-smoke", active) == profile, "The active write-smoke profile differs")
    bound = binding(config, expected, adapter.pinned.run_id, receipt_sha256, profile)
    path = adapter.paths["smoke_window"]
    record = read(path)
    verified = [] if record is None else verified_attempts(record, bound, profile, config["app_root"])
    authority = current_authority(adapter)
    start = read_gtid()
    current = gtid_set(start)
    require(current_authority(adapter) == authority, "The MySQL authority changed while reading the starting GTID set")
    if record is None:
        record = {"version": VERSION, "binding": bound, "attempts": [], "reuses": 0}
    else:
        last = record["attempts"][-1]
        require(gtid_set(last["gtid_after"] if last["gtid_after"] is not None else last["gtid_before"]).issubset(current),
                "The target no longer contains the last write-smoke GTIDs")
        if last["report"] is not None and (last["boot_id"], last["authority_sha256"]) == authority:
            last["reuses"] += 1
            record["reuses"] += 1
            _save(path, record)
            return {**last, "reused": True}

    # A later audit alone cannot recover a cross-schema FK removed after the smoke.
    # The four consecutive GTIDs must subsequently prove no DDL intervened after start.
    try:
        from smoke_row_source import safe_schema_at_smoke
    except ImportError:
        safe_schema = False
    else:
        safe_schema = safe_schema_at_smoke(config, time.monotonic() + (deadline - adapter.clock()))
    attempt = {"boot_id": authority[0], "authority_sha256": authority[1], "reuses": 0,
               "schema_verified_before_write": safe_schema,
               "gtid_before": start, "gtid_after": None, "started_at": int(adapter.clock()), "finished_at": None,
               "report": None}
    record["attempts"].append(attempt)
    _save(path, record)  # A crash or an unreported application write can never erase this intent.
    code, output = adapter._execute(config["commands"]["smoke_write"], deadline)
    end = read_gtid()
    after = gtid_set(end)
    require(current.issubset(after), "The GTID history reset during the write smoke")
    attempt["gtid_after"] = end
    attempt["finished_at"] = int(adapter.clock())
    require(attempt["finished_at"] >= attempt["started_at"], "The clock moved backwards during the write smoke")
    _save(path, record)  # Preserve the observed interval even when the application reports failure.
    require(code == 0, f"The write smoke exited {code}")
    report = evidence.last_json_line(output)
    verify_report(report, bound, profile, config["app_root"], attempt["started_at"], attempt["finished_at"])
    require(not after.issubset(current), "The write smoke has no committed GTIDs")
    require(all(item["report"]["checks"]["value"] != report["checks"]["value"] for item in verified),
            "A fresh write-smoke attempt repeated an earlier application marker")
    require(current_authority(adapter) == authority, "The MySQL authority changed during the write smoke")
    attempt["report"] = report
    _save(path, record)
    return {**attempt, "reused": False}
