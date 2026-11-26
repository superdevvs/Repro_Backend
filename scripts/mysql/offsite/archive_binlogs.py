#!/usr/bin/env python3
"""Collect and publish a bounded batch of closed MySQL logs, then advance its checkpoint."""
from __future__ import annotations

import argparse
import datetime as dt
import fcntl
import json
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import uuid

TOOLS = Path(__file__).resolve().parent
sys.path.insert(0, str(TOOLS))
import advance_binlog_checkpoint as checkpoint
import check_backup_freshness as freshness
import prepare_closed_binlogs as archive
import publish_backup as backup
import scheduled_backup as scheduler


PLAN_FIELDS = {"version", "collector_plan", "collector_plan_sha256", "publish_state_dir",
               "rclone_config", "rclone", "destination", "run_timeout_seconds", "transfer_timeout_seconds"}


def read_plan(path: Path, pin: str) -> dict:
    scheduler.trusted_file(path, plan=True)
    with path.open("rb") as stream:
        raw = stream.read(16 * 1024 + 1)
    backup.need(0 < len(raw) <= 16 * 1024 and backup.HEX.fullmatch(pin) is not None
                and backup.sha256(raw) == pin, "archive plan hash or size mismatch")
    plan = json.loads(raw)
    backup.need(isinstance(plan, dict) and set(plan) == PLAN_FIELDS
                and type(plan["version"]) is int and plan["version"] == 1, "invalid archive plan")
    for field in ("collector_plan", "publish_state_dir", "rclone_config", "rclone"):
        scheduler.absolute(plan[field])
    backup.need(backup.HEX.fullmatch(plan["collector_plan_sha256"]) is not None
                and re.fullmatch(r"[a-z][a-z0-9_-]*:[A-Za-z0-9._/-]+", plan["destination"]) is not None,
                "invalid reviewed collector or destination")
    run_timeout = plan["run_timeout_seconds"]
    transfer_timeout = plan["transfer_timeout_seconds"]
    backup.need(type(run_timeout) is int and type(transfer_timeout) is int
                and 60 <= transfer_timeout < run_timeout <= 3600, "invalid archive time budget")
    return plan


def publish_batch(plan: dict, binding: dict, state_dir: Path, workdir: Path,
                  batch: Path, collected: dict, deadline: float) -> dict:
    manifest_path = batch / archive.OUTPUT
    manifest_raw = freshness.read_private(manifest_path, archive.LIMIT)
    manifest_sha256 = collected["manifest_sha256"]
    identity = binding["source_uuid"] + ".closed-binlogs"
    manifest = backup.parse_manifest(manifest_raw, manifest_sha256, "database", binding["release"], identity)
    inventory_raw = freshness.read_private(batch / archive.INVENTORY, archive.LIMIT)
    expected_item = {"path": archive.INVENTORY, "bytes": len(inventory_raw), "sha256": backup.sha256(inventory_raw)}
    current = checkpoint.check(state_dir, binding)
    backup.need(collected["backup_id"] == manifest["backup_id"]
                and collected["inventory_sha256"] == expected_item["sha256"]
                and expected_item in manifest["artifacts"]
                and manifest["artifacts"][0]["path"] == collected["first_file"] == current["next_file"],
                "staged archive no longer starts at the source checkpoint")
    scheduler.child(
        ["/usr/bin/python3", "-I", "-B", TOOLS / "publish_backup.py", "--source", batch,
         "--manifest", manifest_path, "--manifest-sha256", manifest_sha256, "--scope", "database",
         "--expected-release", binding["release"], "--expected-identity", identity,
         "--config", plan["rclone_config"], "--rclone", plan["rclone"], "--crypt-remote", binding["crypt_remote"],
         "--expected-destination", plan["destination"], "--workdir", workdir,
         "--timeout-seconds", str(plan["transfer_timeout_seconds"])],
        backup.SAFE_ENV, deadline,
    )
    receipt_raw = freshness.read_private(workdir / (manifest["backup_id"] + ".receipt.json"), checkpoint.RECEIPT_LIMIT)
    result = checkpoint.advance(state_dir, binding, inventory_raw=inventory_raw, manifest_raw=manifest_raw,
                                manifest_sha256=manifest_sha256, receipt_raw=receipt_raw,
                                receipt_sha256=backup.sha256(receipt_raw), now=dt.datetime.now(dt.timezone.utc))
    return result | {"remote_accessed": True}


def run(plan: dict) -> dict:
    collector_path = TOOLS / "collect_closed_binlogs.py"
    scheduler.trusted_file(collector_path)
    scheduler.trusted_file(TOOLS / "publish_backup.py")
    import collect_closed_binlogs as collector

    collector_plan = collector.read_plan(scheduler.absolute(plan["collector_plan"]), plan["collector_plan_sha256"])
    binding = checkpoint.source(scheduler.absolute(collector_plan["base_receipt"]),
                                digest=collector_plan["base_receipt_sha256"], identity=collector_plan["identity"],
                                database=collector_plan["database"], server_id=collector_plan["server_id"],
                                release=collector_plan["release"], crypt_remote=collector_plan["crypt_remote"])
    spool = scheduler.absolute(collector_plan["spool_dir"])
    state_dir = scheduler.absolute(collector_plan["checkpoint_dir"])
    workdir = scheduler.absolute(plan["publish_state_dir"])
    for directory in (spool, state_dir, workdir):
        backup.private_path(directory, directory=True)
    backup.need(len({spool, state_dir, workdir}) == 3, "archive directories must have separate paths")
    deadline = time.monotonic() + plan["run_timeout_seconds"]
    with os.fdopen(scheduler.state_lock(state_dir / "archive-job.lock", os.O_CREAT | os.O_RDWR), "r+b") as held:
        fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
        batch = spool / ("archive-" + uuid.uuid4().hex)
        backup.need(not os.path.lexists(batch), "archive staging destination already exists")
        try:
            with tempfile.TemporaryFile(dir=workdir) as stdout:
                scheduler.child(
                    ["/usr/bin/python3", "-I", "-B", collector_path, "--plan", plan["collector_plan"],
                     "--plan-sha256", plan["collector_plan_sha256"], "--output", batch],
                    backup.SAFE_ENV, deadline, stdout=stdout,
                )
                stdout.seek(0)
                raw = stdout.read(16 * 1024 + 1)
            backup.need(0 < len(raw) <= 16 * 1024, "invalid collector report size")
            collected = json.loads(raw)
            active = archive.chain.log_name(collected["active_file"])
            if collected["result"] == "closed-binlog-collection-idle":
                result = checkpoint.check(state_dir, binding)
                backup.need(not os.path.lexists(batch) and collected["first_file"] == active == result["next_file"],
                            "idle collector result disagrees with the checkpoint")
                result["result"] = "closed-binlog-archive-idle"
            else:
                backup.need(collected["result"] == "closed-mysql-binlogs-ready-for-publication"
                            and collected["batch_dir"] == str(batch), "unexpected archive collector result")
                backup.private_path(batch, directory=True)
                result = publish_batch(plan, binding, state_dir, workdir, batch, collected, deadline)
            return result | {"observed_active_file": active, "caught_up_to_observed_active_file": result["next_file"] == active}
        finally:
            if os.path.lexists(batch):
                backup.private_path(batch, directory=True)
                shutil.rmtree(batch)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plan", type=Path, required=True)
    parser.add_argument("--plan-sha256", required=True)
    args = parser.parse_args()
    signal.signal(signal.SIGTERM, scheduler.terminate)
    os.umask(0o077)
    try:
        result = run(read_plan(args.plan, args.plan_sha256))
    except backup.Refusal as error:
        print(json.dumps({"result": "REFUSED", "reason": str(error)}))
        return 1
    except (OSError, ValueError, TypeError, KeyError, AttributeError, RecursionError, subprocess.SubprocessError):
        print(json.dumps({"result": "REFUSED", "reason": "archive I/O, metadata, locking or subprocess failure"}))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
