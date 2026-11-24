#!/usr/bin/env python3
"""Release a durable systemd rotation guard only on a classified stock result."""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import subprocess


GUARD = Path("/var/lib/repro-binlog-fixture-rotation/attempt.pending")
ROTATOR = Path(__file__).resolve().with_name("rotate_closed_binlogs.py")
BINLOG = re.compile(r"[A-Za-z0-9][A-Za-z0-9_.-]*\.[0-9]{6,}")
COMMON = {"result", "rotation_requested", "remote_accessed"}


def classify(raw: bytes, code: int) -> dict | None:
    if not 0 < len(raw) <= 4096:
        return None
    try:
        report = json.loads(raw)
        canonical = (json.dumps(report, sort_keys=True) + "\n").encode("ascii")
    except (ValueError, RecursionError):
        return None
    if (not isinstance(report, dict) or report.get("remote_accessed") is not False
            or raw != canonical):
        return None
    result = report.get("result")
    requested = report.get("rotation_requested")
    if (code == 0 and result == "closed-binlog-rotation-verified" and requested is True
            and set(report) == COMMON | {"closed_file", "active_file"}
            and all(isinstance(report[field], str) and len(report[field]) <= 255 and BINLOG.fullmatch(report[field])
                    for field in ("closed_file", "active_file"))):
        return report
    if (code == 1 and result == "REFUSED" and requested is False
            and set(report) == COMMON | {"reason"}
            and isinstance(report["reason"], str) and report["reason"]):
        return report
    if (code == 1 and result == "closed-binlog-rotation-unverified" and requested is True
            and set(report) == COMMON | {"closed_candidate", "reason"}
            and isinstance(report["closed_candidate"], str) and len(report["closed_candidate"]) <= 255
            and BINLOG.fullmatch(report["closed_candidate"])
            and report["reason"] == "rotation was requested but its exact outcome could not be verified"):
        return report
    return None


def release_guard() -> None:
    directory = os.open(GUARD.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.rmdir(GUARD.name, dir_fd=directory)
        os.fsync(directory)
    finally:
        os.close(directory)


def run(plan: str, pin: str) -> int:
    if not GUARD.is_dir() or GUARD.is_symlink():
        print("Rotation refused because the service guard was not opened.", flush=True)
        return 1
    try:
        completed = subprocess.run(
            ["/usr/bin/python3", "-I", "-B", str(ROTATOR), "--plan", plan, "--plan-sha256", pin],
            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False,
            env={"PATH": "/usr/bin:/bin", "LANG": "C", "LC_ALL": "C", "TZ": "UTC"},
        )
    except OSError:
        report = None
    else:
        report = classify(completed.stdout, completed.returncode)
    if report is None:
        print("Rotation outcome unclassified. The service guard remains held for reconciliation.", flush=True)
        return 1

    held = completed.returncode != 0 and report["rotation_requested"] is not False
    if not held:
        try:
            release_guard()
        except OSError:
            print(json.dumps(report | {"rotation_guard_release_durable": False}, sort_keys=True), flush=True)
            return 1
    print(json.dumps(report | {"rotation_guard_held": held}, sort_keys=True), flush=True)
    return completed.returncode


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plan", required=True)
    parser.add_argument("--plan-sha256", required=True)
    args = parser.parse_args()
    return run(args.plan, args.plan_sha256)


if __name__ == "__main__":
    raise SystemExit(main())
