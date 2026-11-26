#!/usr/bin/env python3
"""Purge local MySQL binary logs only after they are archived off-site and old enough.

The source runs with binlog_expire_logs_auto_purge=OFF, so nothing else frees
binary log space. This tool reads the archive checkpoint: every file before its
next_file was published and read back. It purges up to the first file that is
not yet archived, younger than the local retention, among the newest files kept
locally, or the active file. It never deletes a file the archive still needs.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
from typing import Callable

sys.path.insert(0, str(Path(__file__).resolve().parent))
import publish_backup as backup
import verify_mysql_binlog_chain as chain

PLAN_FIELDS = {"version", "collector_plan", "collector_plan_sha256", "defaults_file", "timeout_seconds",
               "min_age_seconds", "keep_files"}


def number(name: str) -> tuple[str, int]:
    prefix, value = chain.log_name(name).rsplit(".", 1)
    return prefix, int(value)


def select_target(entries: list[tuple[str, int]], *, active: str, checkpoint_next: str, mtimes: dict,
                  now: int, min_age_seconds: int, keep_files: int) -> str | None:
    """Return the file to pass to PURGE BINARY LOGS TO, or None when nothing may go."""
    names = [chain.log_name(name) for name, _ in entries]
    backup.need(bool(names) and names[-1] == chain.log_name(active), "inventory does not end at the active file")
    prefix = number(names[0])[0]
    for earlier, later in zip(names, names[1:]):
        backup.need(chain.next_log(earlier) == later, "binary log inventory has a gap")
    backup.need(number(checkpoint_next)[0] == prefix, "archive checkpoint names a different log sequence")
    closed = names[:-1]
    kept_tail = set(closed[max(0, len(closed) - keep_files):]) if keep_files else set()
    archived_before = number(checkpoint_next)[1]
    last = None
    for name in closed:
        moment = mtimes.get(name)
        if (number(name)[1] >= archived_before or name in kept_tail
                or moment is None or now - moment < min_age_seconds):
            break
        last = name
    return None if last is None else chain.next_log(last)


def run(*, listing: Callable[[], tuple[str, list]], checkpoint_next: Callable[[], str],
        mtime: Callable[[str], int | None], execute: Callable[[str], None], now: int,
        min_age_seconds: int, keep_files: int) -> dict:
    active, entries = listing()
    next_file = checkpoint_next()
    mtimes = {name: mtime(name) for name, _ in entries}
    target = select_target(entries, active=active, checkpoint_next=next_file, mtimes=mtimes, now=now,
                           min_age_seconds=min_age_seconds, keep_files=keep_files)
    result = {"checkpoint_next_file": next_file, "remote_accessed": False}
    if target is None:
        return {"result": "archived-binlogs-nothing-to-purge"} | result
    execute(f"PURGE BINARY LOGS TO '{target}'")
    _, after = listing()
    backup.need(bool(after) and after[0][0] == target, "purge did not leave the expected first binary log")
    purged = sum(1 for name, _ in entries if number(name)[1] < number(target)[1])
    return {"result": "archived-binlogs-purged", "purged_to": target, "purged_files": purged} | result


def parse_plan(raw: bytes, pin: str) -> dict:
    backup.need(0 < len(raw) <= 16 * 1024 and backup.sha256(raw) == pin, "purge plan pin or size differs")
    plan = json.loads(raw)
    backup.need(isinstance(plan, dict) and set(plan) == PLAN_FIELDS and plan["version"] == 1,
                "unexpected purge plan fields")
    for field in ("collector_plan", "defaults_file"):
        backup.need(isinstance(plan[field], str) and re.fullmatch(r"/[A-Za-z0-9_./-]+", plan[field])
                    and ".." not in Path(plan[field]).parts, "invalid purge plan path")
    backup.need(isinstance(plan["collector_plan_sha256"], str) and backup.HEX.fullmatch(plan["collector_plan_sha256"])
                and type(plan["timeout_seconds"]) is int and 3 <= plan["timeout_seconds"] <= 60
                and type(plan["min_age_seconds"]) is int and 3600 <= plan["min_age_seconds"] <= 30 * 86400
                and type(plan["keep_files"]) is int and 1 <= plan["keep_files"] <= 1000, "invalid purge plan bounds")
    return plan


def production(plan: dict) -> dict:
    import advance_binlog_checkpoint as checkpoint
    import collect_closed_binlogs as collector
    from scheduled_backup import trusted_file

    source = collector.read_plan(Path(plan["collector_plan"]), plan["collector_plan_sha256"])
    binding = checkpoint.source(Path(source["base_receipt"]), digest=source["base_receipt_sha256"],
                                identity=source["identity"], database=source["database"],
                                server_id=source["server_id"], release=source["release"],
                                crypt_remote=source["crypt_remote"])
    defaults = Path(plan["defaults_file"])
    raw = checkpoint.publication.read_private(defaults, 4096)
    backup.need(re.fullmatch(rb"\[client\]\r?\nuser=[A-Za-z0-9_]{1,32}\r?\npassword=[^\r\n\x00]+\r?\n?", raw)
                is not None, "purge option file must contain only client user and password")
    client = Path(source["mysql"])
    trusted_file(client)
    binlog_dir = Path(source["binlog_base"]).parent

    def execute(statement: str) -> None:
        args = [str(client), "--defaults-file=" + str(defaults), "--no-login-paths", "--protocol=SOCKET",
                "--socket=" + source["socket"], "--batch", "--skip-reconnect",
                f"--connect-timeout={plan['timeout_seconds']}", "--execute=" + statement]
        result = subprocess.run(args, env=backup.SAFE_ENV, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, timeout=plan["timeout_seconds"] + 5, check=False)
        backup.need(result.returncode == 0, "PURGE BINARY LOGS was refused")

    def mtime(name: str) -> int | None:
        try:
            return int(os.stat(binlog_dir / name).st_mtime)
        except OSError:
            return None

    return run(listing=lambda: collector.catalog(source, defaults),
               checkpoint_next=lambda: checkpoint.check(Path(source["checkpoint_dir"]), binding)["next_file"],
               mtime=mtime, execute=execute, now=int(time.time()),
               min_age_seconds=plan["min_age_seconds"], keep_files=plan["keep_files"])


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plan", type=Path, required=True)
    parser.add_argument("--plan-sha256", required=True)
    args = parser.parse_args()
    os.umask(0o077)
    try:
        from scheduled_backup import trusted_file

        trusted_file(args.plan, plan=True)
        result = production(parse_plan(args.plan.read_bytes(), args.plan_sha256))
    except backup.Refusal as error:
        print(json.dumps({"result": "REFUSED", "reason": str(error), "remote_accessed": False}))
        return 1
    except (OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError):
        print(json.dumps({"result": "REFUSED", "reason": "purge plan, catalog or local I/O failure",
                          "remote_accessed": False}))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
