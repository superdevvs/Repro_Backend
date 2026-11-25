#!/usr/bin/env python3
"""Copy the few cutover journal fields the alert probe needs into a file it may read.

The writer-hold journal (/var/lib/repro-cutover-fence/state.json) is root-private,
and the guard refuses a journal folder that group or others can read. This tool
runs from repro-alert-cutover-view.service as root with no capabilities: it has
only the owner's rights, sees only the journal folder, its own output folder and
its code, and has no network. It never takes a cutover lock and never writes the
journal. The view holds the run ID, phase, write marker, hold start, pending
action and the boot the hold was taken in. It holds no approval, challenge,
digest or observation.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import sys
import tempfile
import time
import uuid

SCHEMA = "repro.alert.cutover-view.v1"
JOURNAL = Path("/var/lib/repro-cutover-fence/state.json")
BOOT_ID = Path("/proc/sys/kernel/random/boot_id")
OUTPUT = Path("/var/lib/repro-alert-cutover/view.json")
MAX_JOURNAL_BYTES = 1024 * 1024  # The guard's own record limit.
WORD = re.compile(r"[a-z][a-z_]{0,31}")
RUN_ID = re.compile(r"[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}")


def canonical_uuid(value: object) -> bool:
    try:
        return isinstance(value, str) and str(uuid.UUID(value)) == value
    except ValueError:
        return False


def hold_boot(state: dict) -> object:
    """The boot the hold was taken in. state["hold"]["boot_id"] follows later reboots; this does not."""
    pending = state.get("pending")
    if isinstance(pending, dict) and pending.get("action") == "hold":
        return pending.get("boot_id")
    history = state.get("history")
    for entry in reversed(history if isinstance(history, list) else []):
        if isinstance(entry, dict) and entry.get("action") == "hold":
            return entry.get("boot_id")
    return None


def fields(state: object) -> dict | None:
    if not isinstance(state, dict) or type(state.get("version")) is not int or state["version"] != 1:
        return None
    identity, pending = state.get("identity"), state.get("pending")
    run_id = identity.get("run_id") if isinstance(identity, dict) else None
    action = pending.get("action") if isinstance(pending, dict) else None
    record = {"run_id": run_id, "phase": state.get("phase"), "writes_possible": state.get("writes_possible"),
              "started_at": state.get("started_at"), "pending_action": action, "hold_boot_id": hold_boot(state)}
    valid = (isinstance(run_id, str) and RUN_ID.fullmatch(run_id) is not None
             and isinstance(record["phase"], str) and WORD.fullmatch(record["phase"]) is not None
             and type(record["writes_possible"]) is bool
             and (record["started_at"] is None or type(record["started_at"]) is int)
             and (pending is None or (isinstance(action, str) and WORD.fullmatch(action) is not None))
             and (record["hold_boot_id"] is None or canonical_uuid(record["hold_boot_id"])))
    return record if valid else None


def build(journal: Path, boot_id: str, now: int) -> dict:
    record = {"schema": SCHEMA, "observed_unix_s": int(now), "boot_id": boot_id}
    try:
        with open(journal, "rb") as stream:
            raw = stream.read(MAX_JOURNAL_BYTES + 1)
    except FileNotFoundError:
        return record | {"journal": "absent"}
    except OSError:
        return record | {"journal": "unreadable"}
    try:
        copied = fields(json.loads(raw)) if 0 < len(raw) <= MAX_JOURNAL_BYTES else None
    except (ValueError, UnicodeError, RecursionError):
        copied = None
    return record | ({"journal": "present"} | copied if copied is not None else {"journal": "unreadable"})


def write(output: Path, record: dict) -> None:
    descriptor, name = tempfile.mkstemp(dir=output.parent, prefix=".view-")
    temporary = Path(name)
    try:
        with os.fdopen(descriptor, "wb") as stream:
            stream.write((json.dumps(record, sort_keys=True) + "\n").encode())
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, 0o640)
        os.replace(temporary, output)
    finally:
        temporary.unlink(missing_ok=True)


def main(argv: list[str] | None = None) -> int:
    if argv:
        print(json.dumps({"result": "cutover-view-refused", "reason": "this tool takes no arguments"}))
        return 2
    try:
        boot_id = BOOT_ID.read_text(encoding="ascii").strip()
        if not canonical_uuid(boot_id):
            raise ValueError("invalid boot ID")
        record = build(JOURNAL, boot_id, int(time.time()))
        write(OUTPUT, record)
    except (OSError, ValueError) as error:
        print(json.dumps({"result": "cutover-view-refused", "reason": type(error).__name__}))
        return 1
    print(json.dumps({"result": "cutover-view-written", "journal": record["journal"]}))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
