#!/usr/bin/env python3
"""Publish the application recovery kit (.env with APP_KEY, MySQL settings) when it changes, and weekly.

The kit is encrypted by the reviewed rclone crypt remote under the application
scope. Nothing here prints a file's content, the APP_KEY or any .env value;
output holds only names, sizes and hashes. Run as the backup account, which
needs read access to each listed file.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time
import uuid
from typing import Callable

TOOLS = Path(__file__).resolve().parent
sys.path.insert(0, str(TOOLS))
import check_backup_freshness as freshness
import publish_backup as backup

SCHEMA = "repro.recovery-kit.v1"
STATE_FILE = "recovery-kit-state.json"
FILE_LIMIT = 1024 * 1024
NAME = re.compile(r"[a-z0-9][a-z0-9._-]{0,63}")
PLAN_FIELDS = {"version", "release", "identity", "state_dir", "files", "rclone_config", "rclone", "crypt_remote",
               "destination", "transfer_timeout_seconds", "run_timeout_seconds"}
APP_KEY = re.compile(rb"^APP_KEY=[\"']?base64:[A-Za-z0-9+/]{43}=[\"']?\r?$", re.MULTILINE)
# The kit lives under daily/, whose bucket rule deletes objects after 31 days.
# An unchanged kit is published again weekly so the bucket always holds one.
REPUBLISH_AFTER_SECONDS = 7 * 86400


def parse_plan(raw: bytes) -> dict:
    backup.need(0 < len(raw) <= 16 * 1024, "invalid kit plan size")
    plan = json.loads(raw)
    backup.need(isinstance(plan, dict) and set(plan) == PLAN_FIELDS and plan["version"] == 1,
                "unexpected kit plan fields")
    backup.need(isinstance(plan["release"], str) and re.fullmatch(r"[0-9a-f]{40}", plan["release"]) is not None
                and isinstance(plan["identity"], str) and backup.IDENTITY.fullmatch(plan["identity"]) is not None
                and isinstance(plan["crypt_remote"], str) and backup.NAME.fullmatch(plan["crypt_remote"]) is not None,
                "invalid kit release, identity or remote")
    files = plan["files"]
    backup.need(isinstance(files, list) and 0 < len(files) <= 16, "kit needs a short file list")
    names = [item.get("name") for item in files if isinstance(item, dict)]
    backup.need(len(names) == len(files) == len(set(names)) and "app.env" in names
                and all(isinstance(name, str) and NAME.fullmatch(name) and name != "kit.json" for name in names)
                and all(set(item) == {"source", "name"} and isinstance(item["source"], str)
                        and Path(item["source"]).is_absolute() for item in files),
                "kit files need unique simple names, absolute sources and app.env")
    for field, low, high in (("transfer_timeout_seconds", 60, 3600), ("run_timeout_seconds", 120, 3600)):
        backup.need(type(plan[field]) is int and low <= plan[field] <= high, "invalid kit timeout " + field)
    return plan


def read_source(path: Path) -> bytes:
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(fd, "rb") as stream:
        backup.need(os.fstat(stream.fileno()).st_size <= FILE_LIMIT, "kit source file is too large")
        return stream.read(FILE_LIMIT + 1)


def build(plan: dict, batch: Path, now: dt.datetime) -> tuple[str, str]:
    """Write the kit artifacts and input manifest into a private batch; return (files hash, manifest hash)."""
    entries, artifacts = [], []
    for item in plan["files"]:
        data = read_source(Path(item["source"]))
        if item["name"] == "app.env":
            backup.need(APP_KEY.search(data) is not None, "the .env file has no usable APP_KEY line")
        target = batch / item["name"]
        with open(target, "xb") as stream:
            os.fchmod(stream.fileno(), 0o600)
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        entry = {"name": item["name"], "source": item["source"], "bytes": len(data), "sha256": backup.sha256(data)}
        entries.append(entry)
        artifacts.append({"path": item["name"], "bytes": len(data), "sha256": entry["sha256"]})
    files_sha256 = backup.sha256(json.dumps(entries, sort_keys=True).encode())
    created = now.strftime("%Y-%m-%dT%H:%M:%SZ")
    meta = backup.encode({"schema": SCHEMA, "created_at": created, "release": plan["release"],
                          "files": entries, "files_sha256": files_sha256, "app_key_present": True})
    with open(batch / "kit.json", "xb") as stream:
        os.fchmod(stream.fileno(), 0o600)
        stream.write(meta)
    artifacts.append({"path": "kit.json", "bytes": len(meta), "sha256": backup.sha256(meta)})
    manifest = {"schema": backup.INPUT_SCHEMA, "backup_id": str(uuid.uuid4()), "created_at": created,
                "scope": "application", "release": plan["release"], "identity": plan["identity"],
                "artifacts": artifacts}
    manifest_sha256, _ = backup.write_input_manifest(batch, manifest)
    return files_sha256, manifest_sha256


def previous(state_dir: Path) -> dict | None:
    try:
        record = json.loads(freshness.read_private(state_dir / STATE_FILE, 64 * 1024))
    except (OSError, ValueError, backup.Refusal):
        return None
    return record if isinstance(record, dict) else None


def save_state(state_dir: Path, record: dict) -> None:
    descriptor, name = tempfile.mkstemp(dir=state_dir, prefix=".recovery-kit-")
    temporary = Path(name)
    try:
        with os.fdopen(descriptor, "wb") as stream:
            stream.write(backup.encode(record))
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, state_dir / STATE_FILE)
        backup.sync_directory(state_dir)
    finally:
        temporary.unlink(missing_ok=True)


def run(plan: dict, *, publish: Callable[[Path, str], dict]) -> dict:
    state_dir = Path(plan["state_dir"])
    backup.private_path(state_dir, directory=True)
    now = dt.datetime.now(dt.timezone.utc).replace(microsecond=0)
    with tempfile.TemporaryDirectory(dir=state_dir, prefix="stage-") as name:
        batch = Path(name)
        files_sha256, manifest_sha256 = build(plan, batch, now)
        last = previous(state_dir)
        published = last.get("published_unix_s") if last is not None else None
        recent = type(published) is int and 0 <= time.time() - published < REPUBLISH_AFTER_SECONDS
        if last is not None and last.get("files_sha256") == files_sha256 and recent:
            return {"result": "recovery-kit-unchanged", "files_sha256": files_sha256,
                    "backup_id": last.get("backup_id")}
        receipt = publish(batch, manifest_sha256)
        backup.need(receipt.get("result") == "published-readback-verified", "kit publication was not verified")
    save_state(state_dir, {"files_sha256": files_sha256, "backup_id": receipt["backup_id"],
                           "published_unix_s": int(time.time())})
    return {"result": "recovery-kit-published", "files_sha256": files_sha256, "backup_id": receipt["backup_id"],
            "artifact_count": receipt.get("artifact_count")}


def stock_publisher(plan: dict) -> Callable[[Path, str], dict]:
    from scheduled_backup import child

    def publish(batch: Path, manifest_sha256: str) -> dict:
        workdir = Path(plan["state_dir"]) / "kit-publish-state"
        workdir.mkdir(mode=0o700, exist_ok=True)
        backup.private_path(workdir, directory=True)
        deadline = time.monotonic() + plan["run_timeout_seconds"]
        manifest = json.loads((batch / "input-manifest.json").read_bytes())
        child(["/usr/bin/python3", "-I", "-B", TOOLS / "publish_backup.py", "--source", batch,
               "--manifest", batch / "input-manifest.json", "--manifest-sha256", manifest_sha256,
               "--scope", "application", "--expected-release", plan["release"],
               "--expected-identity", plan["identity"], "--config", plan["rclone_config"],
               "--rclone", plan["rclone"], "--crypt-remote", plan["crypt_remote"],
               "--expected-destination", plan["destination"], "--workdir", workdir,
               "--timeout-seconds", str(plan["transfer_timeout_seconds"])], backup.SAFE_ENV, deadline)
        raw = freshness.read_private(workdir / (manifest["backup_id"] + ".receipt.json"), 16 * 1024)
        return json.loads(raw)

    return publish


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plan", type=Path, required=True)
    args = parser.parse_args()
    os.umask(0o077)
    try:
        from scheduled_backup import trusted_file

        trusted_file(args.plan, plan=True)
        plan = parse_plan(args.plan.read_bytes())
        result = run(plan, publish=stock_publisher(plan))
    except backup.Refusal as error:
        result = {"result": "recovery-kit-failed", "reason": str(error)}
    except (OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError):
        result = {"result": "recovery-kit-failed", "reason": "kit source, staging or publication I/O failed"}
    print(json.dumps(result, sort_keys=True))
    return 0 if result["result"] in ("recovery-kit-published", "recovery-kit-unchanged") else 1


if __name__ == "__main__":
    sys.exit(main())
