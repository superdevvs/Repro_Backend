#!/usr/bin/env python3
"""Copy the few cutover journal fields the alert probe needs into a file it may read.

The writer-hold journal (/var/lib/repro-cutover-fence/state.json) is root-private,
and the guard refuses a journal folder that group or others can read. This tool
runs from repro-alert-cutover-view.service as root with no capabilities: it has
only the owner's rights and sees the journal folder, retirement marker, its output folder
and its code. An active journal takes precedence. After completed retirement the view
exposes only the engine and run ID. The view never takes a writer lock or changes authority.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import stat
import sys
import tempfile
import time
import uuid

SCHEMA = "repro.alert.cutover-view.v1"
JOURNAL = Path("/var/lib/repro-cutover-fence/state.json")
RETIRED = Path("/etc/repro-cutover-fence/retired.json")
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


def retired_authority(path: Path) -> dict:
    """Expose just the engine and run from a root-owned retirement marker."""
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0)
    with os.fdopen(os.open(path, flags), "rb") as stream:
        info = os.fstat(stream.fileno())
        if (not stat.S_ISREG(info.st_mode) or (os.name == "posix" and (info.st_uid != 0 or info.st_mode & 0o022))
                or not 0 < info.st_size <= MAX_JOURNAL_BYTES):
            raise ValueError("untrusted retirement record")
        raw = stream.read(MAX_JOURNAL_BYTES + 1)
    if len(raw) > MAX_JOURNAL_BYTES:
        raise ValueError("oversized retirement record")
    def unique_fields(items):
        fields = dict(items)
        if len(fields) != len(items):
            raise ValueError("duplicate retirement fields")
        return fields

    state = json.loads(raw, object_pairs_hook=unique_fields)
    if not (isinstance(state, dict) and state.keys() == {"version", "run_id", "engine", "archive"}
            and type(state["version"]) is int and state["version"] == 1 and state["engine"] in ("mysql", "sqlite")
            and isinstance(state["run_id"], str) and RUN_ID.fullmatch(state["run_id"])
            and isinstance(state["archive"], str)):
        raise ValueError("invalid retirement record")
    prefix = "/var/lib/repro-cutover-archive/" + state["run_id"] + "-"
    timestamp = state["archive"][len(prefix):]
    if not state["archive"].startswith(prefix) or re.fullmatch(r"[0-9]{8}T[0-9]{6}Z", timestamp) is None:
        raise ValueError("invalid retirement archive path")
    time.strptime(timestamp, "%Y%m%dT%H%M%SZ")
    return {"journal": "retired", "run_id": state["run_id"], "engine": state["engine"]}


def build(journal: Path, boot_id: str, now: int, *, retired: Path = RETIRED) -> dict:
    record = {"schema": SCHEMA, "observed_unix_s": int(now), "boot_id": boot_id}
    try:
        with open(journal, "rb") as stream:
            raw = stream.read(MAX_JOURNAL_BYTES + 1)
    except FileNotFoundError:
        pending = journal.with_name("retire-pending.json")
        if os.path.lexists(journal) or os.path.lexists(pending):
            return record | {"journal": "unreadable"}
        try:
            authority = retired_authority(retired)
        except FileNotFoundError:
            authority = {"journal": "absent"}
        except (OSError, ValueError, RecursionError):
            authority = {"journal": "unreadable"}
        # A dangling journal or an initialization racing the read cannot establish retired authority.
        if os.path.lexists(journal) or os.path.lexists(pending):
            authority = {"journal": "unreadable"}
        return record | authority
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
