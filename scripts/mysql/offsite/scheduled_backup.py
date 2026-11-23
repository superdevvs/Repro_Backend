#!/usr/bin/env python3
"""Produce, publish and monitor scheduled database base backups on Linux."""

from __future__ import annotations

import argparse
import datetime as dt
import fcntl
import json
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import sys
import tempfile
import time
import uuid

TOOLS = Path(__file__).resolve().parent
sys.path.insert(0, str(TOOLS))
import check_backup_freshness as freshness
import prepare_mysql_backup as mysql
import prepare_sqlite_backup as sqlite
import publish_backup as backup


PLAN_FIELDS = {
    "version", "driver", "release", "identity", "app_root", "state_dir",
    "rclone_config", "php", "rclone", "crypt_remote", "destination",
    "max_age_seconds", "run_timeout_seconds", "transfer_timeout_seconds", "mysql",
}
MYSQL_FIELDS = {"database", "defaults_file", "client", "dump"}
STATE_FILE = "scheduled-backup.json"
STATE_LIMIT = 5 * 1024**2
FIELDS = {"version", "plan_sha256", "attempt_id", "started_at", "status", "stage", "verified"}
STAGES = {"preflight", "producer", "staging", "publication", "readback-receipt"}


def trusted_file(path: Path, *, plan: bool = False) -> None:
    backup.need(path.is_absolute() and path.resolve() == path, "trusted file must be an exact absolute path")
    for entry in reversed(path.parents):
        info = entry.lstat()
        backup.need(info.st_uid == 0 and stat.S_ISDIR(info.st_mode) and not info.st_mode & 0o022,
                    "trusted file has an unsafe ancestor")
    info = path.lstat()
    backup.need(info.st_uid == 0 and stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and
                not info.st_mode & 0o022 and (not plan or stat.S_IMODE(info.st_mode) in {0o600, 0o640}),
                "expected a protected root-owned file")


def absolute(value: object) -> Path:
    backup.need(isinstance(value, str) and bool(value), "expected an absolute plan path")
    path = Path(value)
    backup.need(path.is_absolute() and ".." not in path.parts, "expected an absolute plan path")
    return path


def read_plan(path: Path) -> tuple[dict, str]:
    trusted_file(path, plan=True)
    with path.open("rb") as stream:
        raw = stream.read(16 * 1024 + 1)
    backup.need(0 < len(raw) <= 16 * 1024, "invalid plan size")
    plan = json.loads(raw)
    backup.need(isinstance(plan, dict) and set(plan) == PLAN_FIELDS and
                type(plan["version"]) is int and plan["version"] == 1 and
                plan["driver"] in ("sqlite", "mysql"), "invalid backup plan")
    for field, pattern in (("release", r"[0-9a-f]{40}"), ("identity", backup.IDENTITY),
                           ("crypt_remote", backup.NAME), ("destination", r"[a-z][a-z0-9_-]*:[A-Za-z0-9._/-]+")):
        backup.need(isinstance(plan[field], str) and re.fullmatch(pattern, plan[field]) is not None,
                    f"invalid plan {field}")
    for field, minimum, maximum in (("max_age_seconds", 1, 31 * 86400),
                                    ("run_timeout_seconds", 120, 3600),
                                    ("transfer_timeout_seconds", 60, 3600)):
        number = plan[field]
        backup.need(type(number) is int and minimum <= number <= maximum, f"invalid plan {field}")
    backup.need(plan["transfer_timeout_seconds"] < plan["run_timeout_seconds"], "invalid transfer budget")
    for field in ("state_dir", "app_root", "rclone_config", "php", "rclone"):
        absolute(plan[field])
    db = plan["mysql"]
    if plan["driver"] == "mysql":
        backup.need(isinstance(db, dict) and set(db) == MYSQL_FIELDS and
                    mysql.UUID.fullmatch(plan["identity"]) is not None and
                    isinstance(db["database"], str) and mysql.DB_NAME.fullmatch(db["database"]) is not None,
                    "invalid MySQL plan")
        for field in ("defaults_file", "client", "dump"):
            absolute(db[field])
    else:
        backup.need(db is None, "unexpected MySQL settings on SQLite plan")
    return plan, backup.sha256(raw)


def write_state(state_dir: Path, record: dict) -> None:
    with tempfile.NamedTemporaryFile(dir=state_dir, prefix=".scheduled-", delete=False) as stream:
        temporary = Path(stream.name)
        try:
            stream.write(backup.encode(record))
            stream.flush()
            os.fsync(stream.fileno())
            os.replace(temporary, state_dir / STATE_FILE)
            backup.sync_directory(state_dir)
        finally:
            temporary.unlink(missing_ok=True)


def read_state(state_dir: Path, plan_sha: str) -> dict:
    raw = freshness.read_private(state_dir / STATE_FILE, STATE_LIMIT)
    record = json.loads(raw)
    backup.need(isinstance(record, dict) and set(record) == FIELDS and
                type(record["version"]) is int and record["version"] == 1 and
                record["plan_sha256"] == plan_sha and record["stage"] in STAGES and
                record["status"] in ("running", "failed", "verified") and
                type(record["started_at"]) is int and record["started_at"] > 0 and
                str(uuid.UUID(record["attempt_id"])) == record["attempt_id"],
                "backup evidence does not match the current plan")
    return record


def evaluate(plan: dict, evidence: dict, now: dt.datetime) -> dict:
    backup.need(isinstance(evidence, dict) and set(evidence) == {"receipt", "receipt_sha256", "manifest"} and
                all(isinstance(item, str) for item in evidence.values()),
                "verified publication evidence is missing or malformed")
    return freshness.check(
        evidence["receipt"].encode("utf-8"), evidence["manifest"].encode("utf-8"),
        receipt_sha256=evidence["receipt_sha256"], scope="database", release=plan["release"],
        identity=plan["identity"], crypt_remote=plan["crypt_remote"], now=now,
        max_age_seconds=plan["max_age_seconds"],
    )


def state_lock(path: Path, flags: int) -> int:
    fd = os.open(path, flags | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    info = os.fstat(fd)
    if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid()
            or stat.S_IMODE(info.st_mode) != 0o600 or info.st_nlink != 1):
        os.close(fd)
        raise backup.Refusal("backup coordination lock is unsafe")
    return fd


def check(plan: dict, plan_sha: str, *, now: dt.datetime) -> dict:
    state_dir = absolute(plan["state_dir"])
    backup.private_path(state_dir, directory=True)
    record = read_state(state_dir, plan_sha)
    if record["status"] == "running":
        try:
            with os.fdopen(state_lock(state_dir / "scheduler.lock", os.O_RDONLY), "rb") as held:
                fcntl.flock(held, fcntl.LOCK_SH | fcntl.LOCK_NB)
                record = read_state(state_dir, plan_sha)
                backup.need(record["status"] != "running", "previous backup stopped without a result")
        except BlockingIOError:
            age = now.timestamp() - record["started_at"]
            backup.need(-1 <= age < plan["run_timeout_seconds"], "running backup passed its deadline")
    if record["status"] == "failed":
        return {"result": "scheduled-backup-failed", "stage": record["stage"], "remote_accessed": False}
    return evaluate(plan, record["verified"], now)


def child(argv: list, env: dict, deadline: float) -> None:
    remaining = deadline - time.monotonic()
    backup.need(remaining > 0, "scheduled backup deadline expired")
    with subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                          stderr=subprocess.DEVNULL, env=env, start_new_session=True) as process:
        try:
            code = process.wait(timeout=remaining)
        except BaseException:
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            process.wait()
            raise
    backup.need(code == 0, "scheduled backup command failed")


def command_plan(plan: dict, batch: Path) -> tuple[list, dict, Path]:
    php = absolute(plan["php"])
    rclone = absolute(plan["rclone"])
    for executable in (php, rclone):
        backup.protected_rclone(executable)
    config = absolute(plan["rclone_config"])
    backup.validate_config(freshness.read_private(config, 1024**2), plan["crypt_remote"], plan["destination"])
    producer = TOOLS / "backup-database.php"
    trusted_file(producer)
    app = absolute(plan["app_root"])
    backup.need(app.resolve() == app and app.is_dir(), "application path differs from reviewed path")
    argv = [php, producer, app, batch]
    env = backup.SAFE_ENV.copy()
    if plan["driver"] == "mysql":
        db = plan["mysql"]
        for executable in (db["client"], db["dump"]):
            backup.protected_rclone(absolute(executable))
        defaults = absolute(db["defaults_file"])
        backup.private_path(defaults)
        env.update(REPRO_MYSQL_BACKUP_DEFAULTS_FILE=str(defaults), REPRO_MYSQL_CLIENT=db["client"],
                   REPRO_MYSQLDUMP=db["dump"])
        argv += ["--pitr", "--expected-server-uuid", plan["identity"]]
        stage_tool = TOOLS / "prepare_mysql_backup.py"
    else:
        trusted_file(TOOLS / "backup-sqlite.php")
        stage_tool = TOOLS / "prepare_sqlite_backup.py"
    trusted_file(stage_tool)
    trusted_file(TOOLS / "publish_backup.py")
    return argv, env, stage_tool


def execute(plan: dict, batch: Path, workdir: Path, deadline: float, started: int,
            update_stage) -> tuple[dict, dict]:
    producer_argv, env, stage_tool = command_plan(plan, batch)
    update_stage("producer")
    child(producer_argv, env, deadline)
    update_stage("staging")
    producer = freshness.read_private(batch / mysql.PRODUCER, 64 * 1024)
    base_args = ["--expected-release", plan["release"], "--expected-identity", plan["identity"]]
    stage = ["/usr/bin/python3", "-I", "-B", stage_tool, "--source", batch,
             "--producer-sha256", backup.sha256(producer), *base_args]
    if plan["driver"] == "mysql":
        stage += ["--expected-database", plan["mysql"]["database"]]
    child(stage, backup.SAFE_ENV, deadline)
    manifest_path = batch / sqlite.OUTPUT
    raw_manifest = freshness.read_private(manifest_path, 4 * 1024**2)
    manifest_hash = backup.sha256(raw_manifest)
    manifest = backup.parse_manifest(raw_manifest, manifest_hash, "database", plan["release"], plan["identity"])
    created = dt.datetime.strptime(manifest["created_at"], "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=dt.timezone.utc)
    backup.need(started <= created.timestamp() <= time.time(), "producer output is outside the current attempt")
    update_stage("publication")
    child(
        ["/usr/bin/python3", "-I", "-B", TOOLS / "publish_backup.py", "--source", batch,
         "--manifest", manifest_path, "--manifest-sha256", manifest_hash, "--scope", "database", *base_args,
         "--config", plan["rclone_config"], "--rclone", plan["rclone"], "--crypt-remote", plan["crypt_remote"],
         "--expected-destination", plan["destination"], "--workdir", workdir,
         "--timeout-seconds", str(plan["transfer_timeout_seconds"])],
        backup.SAFE_ENV, deadline,
    )
    update_stage("readback-receipt")
    raw_receipt = freshness.read_private(workdir / (manifest["backup_id"] + ".receipt.json"), 16 * 1024)
    evidence = {"receipt": raw_receipt.decode("utf-8"), "receipt_sha256": backup.sha256(raw_receipt),
                "manifest": raw_manifest.decode("utf-8")}
    result = evaluate(plan, evidence, dt.datetime.now(dt.timezone.utc))
    backup.need(result["result"] == "backup-publication-fresh", "published snapshot was already stale")
    return evidence, result


def run(plan: dict, plan_sha: str) -> dict:
    state_dir = absolute(plan["state_dir"])
    backup.private_path(state_dir, directory=True)
    with os.fdopen(state_lock(state_dir / "scheduler.lock", os.O_CREAT | os.O_RDWR), "r+b") as held:
        fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
        try:
            previous = read_state(state_dir, plan_sha)["verified"]
        except (backup.Refusal, OSError, ValueError, TypeError, RecursionError):
            previous = None
        record = {"version": 1, "plan_sha256": plan_sha, "attempt_id": str(uuid.uuid4()),
                  "started_at": int(time.time()), "status": "running", "stage": "preflight", "verified": previous}
        write_state(state_dir, record)
        deadline = time.monotonic() + plan["run_timeout_seconds"]

        def update_stage(stage: str) -> None:
            record["stage"] = stage
            write_state(state_dir, record)

        try:
            workdir = state_dir / "publish-state"
            workdir.mkdir(mode=0o700, exist_ok=True)
            backup.private_path(workdir, directory=True)
            with tempfile.TemporaryDirectory(dir=state_dir, prefix="stage-") as temporary:
                evidence, result = execute(plan, Path(temporary), workdir, deadline, record["started_at"], update_stage)
        except (backup.Refusal, OSError, ValueError, TypeError, RecursionError, subprocess.SubprocessError):
            record["status"] = "failed"
            write_state(state_dir, record)
            return {"result": "scheduled-backup-failed", "stage": record["stage"]}
        record.update(status="verified", verified=evidence)
        write_state(state_dir, record)
        return result


def terminate(_signum, _frame) -> None:
    raise InterruptedError("backup interrupted")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("run", "check"))
    parser.add_argument("--plan", required=True, type=Path)
    args = parser.parse_args()
    os.umask(0o077)
    try:
        plan, pin = read_plan(args.plan)
        if args.action == "run":
            signal.signal(signal.SIGTERM, terminate)
            result = run(plan, pin)
        else:
            result = check(plan, pin, now=dt.datetime.now(dt.timezone.utc))
    except (backup.Refusal, OSError, ValueError, TypeError, RecursionError, subprocess.SubprocessError):
        result = {"result": "scheduled-backup-unavailable", "reason": "invalid plan, evidence, lock or local I/O"}
    print(json.dumps(result, sort_keys=True))
    return 0 if result["result"] == "backup-publication-fresh" else 1


if __name__ == "__main__":
    sys.exit(main())
