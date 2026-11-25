#!/usr/bin/env python3
"""Observe the off-site backups from outside the server and export the seven-day health report.

Run `observe` every minute on the independent recovery machine with the
READ-ONLY restore key. Each run records, as of the start of the current slot:
the newest base backup with a completion marker, and how far a contiguous
binlog chain from that base is published. Recovery reaches the time of the
Rotate event that closed the newest batch's last log, read from the log itself
(verified against the batch manifest), never the batch's seal time: a catch-up
batch cut by the collector's limits is sealed now but holds only old logs.
The raw sample is kept beside the journal and pinned by its SHA-256.
A publication counts for a slot only if its completion marker's verified_at is
at or before the slot start, not merely if this run (a few seconds later) can
read it. The publisher uploads the marker right after that stamp.
A publication folder without completion.json (still uploading, or abandoned)
is skipped and looked at again on the next run; it never costs a slot.
`export` writes the repro.backup-health.observations.v1 file that
scripts/cutover/readiness/summarize_backup_health.py reads.

A slot is credited only while lag stays within 300 s, using the value seen at
the slot start, so sample every 60 s: a two-minute rotation then keeps full
credit. Every transaction committed before the rotation time is in the chain.
"""
from __future__ import annotations

import argparse
import bisect
import contextlib
import datetime as dt
import io
import json
import os
from pathlib import Path
import struct
import sys
import time
import zlib

sys.path.insert(0, str(Path(__file__).resolve().parent))
import publish_backup as backup
import verify_mysql_binlog_chain as chain

SAMPLE_SCHEMA = "repro.backup-health.sample.v1"
REPORT_SCHEMA = "repro.backup-health.observations.v1"
WINDOW = 7 * 24 * 3600
CACHE_VERSION = 3
COVERAGE_READ_LIMIT = 64 * 1024**2  # Larger last logs are not read; recovery then does not advance past them.
PLAN_FIELDS = {"version", "interval_s", "journal", "samples_dir", "database_identity", "binlog_identity", "rclone",
               "crypt_remote", "database_config", "database_destination", "binlog_config", "binlog_destination",
               "timeout_seconds"}


def epoch(value: str) -> int:
    return int(dt.datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=dt.timezone.utc).timestamp())


def parse_completion(raw: bytes, identity: str) -> dict:
    record = json.loads(raw)
    backup.need(isinstance(record, dict) and record.get("schema") == backup.COMPLETE_SCHEMA
                and record.get("status") == "artifact-readback-verified" and isinstance(record.get("input"), dict)
                and record["input"].get("identity") == identity, "completion marker is not a verified publication")
    names = [item["path"] for item in record["input"]["artifacts"]]
    logs = {int(item["path"].rsplit(".", 1)[1]): item for item in record["input"]["artifacts"]
            if chain.BINLOG.fullmatch(item["path"])}
    tail = logs[max(logs)] if logs else None
    return {"created": epoch(record["input"]["created_at"]), "available": epoch(record["verified_at"]),
            "names": names, "first": min(logs) if logs else None, "last": max(logs) if logs else None,
            "tail": None if tail is None else {key: tail[key] for key in ("path", "bytes", "sha256")},
            "sha256": backup.sha256(raw)}


def rotated_at(raw: bytes, name: str) -> int | None:
    """Server time of the Rotate event that closed this binary log, or None if it does not end in one.

    MySQL stamps that event with the rotation time (checked on 8.4.13), and every
    transaction committed before the rotation is in this file or an earlier one."""
    header = chain.EVENT_HEADER
    if raw[:len(chain.MAGIC)] != chain.MAGIC:
        return None
    position, last = len(chain.MAGIC), None
    while position < len(raw):
        if len(raw) - position < header.size:
            return None
        moment, kind, _, size, end, _ = header.unpack_from(raw, position)
        if size < header.size + 4 or end != position + size or end > len(raw) or (
                (kind == chain.FORMAT_DESCRIPTION) != (position == len(chain.MAGIC))):
            return None
        last, position = (moment, kind, raw[position:end]), end
    if last is None:
        return None
    moment, kind, event = last
    body = struct.pack("<Q", 4) + chain.next_log(name).encode("ascii")
    if kind != chain.ROTATE or event[header.size:-4] != body or event[-4:] != struct.pack("<I", zlib.crc32(event[:-4])):
        return None
    return moment


def ready(item: dict, slot: int) -> bool:
    """Created and verified by the slot start; completion.json is uploaded right after verified_at."""
    return item["created"] <= slot and item["available"] <= slot


class State:
    """Journal slots plus a cache of every completion already read (each is read once)."""

    def __init__(self, plan: dict):
        self.plan = plan
        self.slots: set[int] = set()
        self.cache = {"version": CACHE_VERSION, "bases": {}, "binlogs": {}, "chains": {}}
        self.ranges: list = []
        self.first: dict[int, str] = {}

    @classmethod
    def load(cls, plan: dict) -> "State":
        state = cls(plan)
        journal = Path(plan["journal"])
        if journal.exists():
            raw = journal.read_bytes()
            if raw and not raw.endswith(b"\n"):
                # A crash cut the last row short. It never completed, so drop it and retry its slot.
                raw = raw[:raw.rfind(b"\n") + 1]
                os.truncate(journal, len(raw))
            for line in raw.splitlines():
                state.slots.add(json.loads(line)["slot_start_unix_s"])
        cache = Path(plan["journal"] + ".cache.json")
        if cache.exists():
            cached = json.loads(cache.read_bytes())
            if cached.get("version") == CACHE_VERSION:  # An older cache is rebuilt from the bucket.
                state.cache = cached
        for backup_id, item in state.cache["binlogs"].items():
            state.index(backup_id, item)
        return state

    def index(self, backup_id: str, item: dict) -> None:
        if item["first"] is not None:
            bisect.insort(self.ranges, (item["first"], item["last"], backup_id))
            self.first[item["first"]] = backup_id

    def save(self) -> None:
        path = Path(self.plan["journal"] + ".cache.json")
        temporary = path.with_name(path.name + ".tmp")
        temporary.write_bytes(json.dumps(self.cache, sort_keys=True).encode())
        os.replace(temporary, path)


@contextlib.contextmanager
def journal_lock(plan: dict):
    """One observe run at a time, so a slot's check, sample and journal row never interleave."""
    try:
        import fcntl
    except ImportError:  # The observer runs on Linux, WSL or a container; Windows only runs its tests.
        yield
        return
    with open(plan["journal"] + ".lock", "ab") as stream:
        try:
            fcntl.flock(stream.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise backup.Refusal("another observe run holds the journal") from None
        yield


def write_atomically(path: Path, raw: bytes) -> None:
    temporary = path.with_name("." + path.name + ".tmp")
    with open(temporary, "wb") as stream:
        stream.write(raw)
    os.replace(temporary, path)


def refresh(bucket, state: State, now: int) -> None:
    plan = state.plan
    for backup_id in sorted(set(bucket.list_backups(plan["database_identity"], now)).difference(state.cache["bases"])):
        raw = bucket.completion(plan["database_identity"], backup_id)
        if raw is None:
            continue  # Still uploading (the marker is written last) or abandoned; look again next run.
        item = parse_completion(raw, plan["database_identity"])
        item["start"] = None
        if plan["binlog_identity"] and "database-backup.json" in item["names"]:
            receipt = json.loads(bucket.artifact(plan["database_identity"], backup_id, "database-backup.json"))
            pitr = receipt.get("pitr") if isinstance(receipt, dict) else None
            if isinstance(pitr, dict) and isinstance(pitr.get("binlog_file"), str):
                item["start"] = int(chain.log_name(pitr["binlog_file"]).rsplit(".", 1)[1])
        state.cache["bases"][backup_id] = item
    if plan["binlog_identity"]:
        listing = set(bucket.list_backups(plan["binlog_identity"], now)).difference(state.cache["binlogs"])
        for backup_id in sorted(listing):
            raw = bucket.completion(plan["binlog_identity"], backup_id)
            if raw is None:
                continue
            item = parse_completion(raw, plan["binlog_identity"])
            state.cache["binlogs"][backup_id] = item
            state.index(backup_id, item)


def covered(bucket, state: State, backup_id: str) -> int | None:
    """Rotation time of a batch's last log (read once, then cached), capped at the batch's seal time."""
    item = state.cache["binlogs"][backup_id]
    if "covered" not in item:
        tail, moment = item["tail"], None
        if tail is not None and tail["bytes"] <= COVERAGE_READ_LIMIT:
            raw = bucket.verified_artifact(state.plan["binlog_identity"], backup_id, tail["path"], tail["bytes"],
                                           tail["sha256"])
            moment = rotated_at(raw, tail["path"])
        item["covered"] = None if moment is None else min(moment, item["created"])
    return item["covered"]


def chain_through(state: State, base_id: str, base: dict, slot: int, coverage) -> int | None:
    """Extend the contiguous published chain from a base over batches published by the slot start.

    Returns the newest rotation time the chain is known to reach. Only the chain's newest
    batch is read; a batch whose last log cannot be read leaves the previous value."""
    binlogs = state.cache["binlogs"]
    current = state.cache["chains"].get(base_id)
    advanced = False
    if current is None:
        position = bisect.bisect_right(state.ranges, (base["start"], float("inf"), "")) - 1
        if position < 0:
            return None
        first, last, backup_id = state.ranges[position]
        if not first <= base["start"] <= last or not ready(binlogs[backup_id], slot):
            return None
        current, advanced = {"next": last + 1, "through": None, "batches": 1, "tip": backup_id}, True
    while current["next"] in state.first and ready(binlogs[state.first[current["next"]]], slot):
        backup_id = state.first[current["next"]]
        current = {"next": binlogs[backup_id]["last"] + 1, "through": current["through"],
                   "batches": current["batches"] + 1, "tip": backup_id}
        advanced = True
    if advanced:
        moment = coverage(current["tip"])
        if moment is not None and (current["through"] is None or moment > current["through"]):
            current["through"] = moment
    state.cache["chains"][base_id] = current
    return current["through"]


def observe(plan: dict, bucket, *, now: int, state: State) -> dict | None:
    interval = plan["interval_s"]
    slot = now - now % interval
    if slot in state.slots:
        return None
    refresh(bucket, state, now)
    eligible = [(item["created"], backup_id) for backup_id, item in state.cache["bases"].items() if ready(item, slot)]
    base_time, base_id = max(eligible) if eligible else (0, None)
    through = None
    if base_id is not None and plan["binlog_identity"] and state.cache["bases"][base_id]["start"] is not None:
        value = chain_through(state, base_id, state.cache["bases"][base_id], slot,
                              lambda backup_id: covered(bucket, state, backup_id))
        through = value if value is not None and base_time <= value <= slot else None
    sample = {"schema": SAMPLE_SCHEMA, "slot_start_unix_s": slot, "observed_unix_s": now,
              "base": None if base_id is None else {"backup_id": base_id, "created_unix_s": base_time,
                                                     "completion_sha256": state.cache["bases"][base_id]["sha256"]},
              "chain": state.cache["chains"].get(base_id), "recoverable_through_unix_s": through}
    raw = json.dumps(sample, sort_keys=True).encode()
    # A sample left by an attempt that crashed before its journal row is pinned by no row; replace it.
    write_atomically(Path(plan["samples_dir"]) / f"{slot}.json", raw)
    row = {"slot_start_unix_s": slot, "sample_sha256": backup.sha256(raw),
           "newest_verified_base_unix_s": base_time, "recoverable_through_unix_s": through}
    with open(plan["journal"], "ab") as stream:
        stream.write(json.dumps(row, sort_keys=True).encode() + b"\n")
    state.slots.add(slot)
    return row


def export(plan: dict, *, window_end: int, identity_sha256: str, destination_sha256: str,
           monitor_profile_sha256: str) -> bytes:
    interval = plan["interval_s"]
    backup.need(window_end % interval == 0 and WINDOW % interval == 0, "window end must fall on a slot boundary")
    start = window_end - WINDOW
    rows = {}
    for line in Path(plan["journal"]).read_bytes().splitlines():
        row = json.loads(line)
        backup.need(rows.setdefault(row["slot_start_unix_s"], row) == row, "the journal holds two different rows for one slot")
    missing = [slot for slot in range(start, window_end, interval) if slot not in rows]
    backup.need(not missing, f"{len(missing)} scheduled slot(s) have no observation")
    header = {"schema": REPORT_SCHEMA, "identity_sha256": identity_sha256, "destination_sha256": destination_sha256,
              "monitor_profile_sha256": monitor_profile_sha256, "window_start_unix_s": start,
              "window_end_unix_s": window_end, "interval_s": interval, "external_observer_verified": True}
    lines = [header] + [rows[slot] for slot in range(start, window_end, interval)]
    return b"".join(json.dumps(item, sort_keys=True).encode() + b"\n" for item in lines)


class RcloneBucket:
    """Reads through the READ-ONLY restore config. Names and contents are decrypted by the crypt remote."""

    def __init__(self, plan: dict):
        self.remotes = {}
        for identity, config, destination in ((plan["database_identity"], plan["database_config"],
                                               plan["database_destination"]),
                                              (plan["binlog_identity"], plan["binlog_config"],
                                               plan["binlog_destination"])):
            if identity is None:
                continue
            raw = Path(config).read_bytes()
            backup.validate_config(raw, plan["crypt_remote"], destination)
            self.remotes[identity] = backup.Rclone(Path(plan["rclone"]), Path(config), plan["timeout_seconds"])
        self.crypt = plan["crypt_remote"]

    def list_backups(self, identity: str, _now: int) -> list[str]:
        listing = json.loads(self.remotes[identity].run("lsjson", f"{self.crypt}:database/{identity}",
                                                        "--dirs-only", absent_ok=True))
        return [item["Name"] for item in listing if isinstance(item, dict) and item.get("IsDir")]

    def completion(self, identity: str, backup_id: str) -> bytes | None:
        """The marker's bytes, or None while the publication has no marker (uploading or abandoned)."""
        folder = f"{self.crypt}:database/{identity}/{backup_id}"
        listing = json.loads(self.remotes[identity].run("lsjson", folder, "--files-only", absent_ok=True))
        if not any(isinstance(item, dict) and item.get("Name") == "completion.json" for item in listing):
            return None
        return self.remotes[identity].run("cat", folder + "/completion.json")

    def artifact(self, identity: str, backup_id: str, path: str) -> bytes:
        return self.remotes[identity].run("cat", f"{self.crypt}:database/{identity}/{backup_id}/artifacts/{path}")

    def verified_artifact(self, identity: str, backup_id: str, path: str, size: int, sha256: str) -> bytes:
        """The artifact's bytes, checked against the size and digest its published manifest lists."""
        sink = io.BytesIO()
        self.remotes[identity].readback(f"{self.crypt}:database/{identity}/{backup_id}/artifacts/{path}", size, sha256,
                                        sink)
        return sink.getvalue()


def read_plan(path: Path) -> dict:
    plan = json.loads(path.read_bytes())
    backup.need(isinstance(plan, dict) and set(plan) == PLAN_FIELDS and plan["version"] == 1
                and type(plan["interval_s"]) is int and 10 <= plan["interval_s"] <= 300
                and WINDOW % plan["interval_s"] == 0, "unexpected observer plan; interval_s must divide 7 days, at most 300")
    return plan


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("action", choices=("observe", "export"))
    parser.add_argument("--plan", type=Path, required=True)
    parser.add_argument("--window-end", type=int)
    parser.add_argument("--identity-sha256")
    parser.add_argument("--destination-sha256")
    parser.add_argument("--monitor-profile-sha256")
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    try:
        plan = read_plan(args.plan)
        if args.action == "observe":
            with journal_lock(plan):
                state = State.load(plan)
                row = observe(plan, RcloneBucket(plan), now=int(time.time()), state=state)
                state.save()
            print(json.dumps({"result": "observation-recorded" if row else "slot-already-observed"} | (row or {})))
            return 0
        raw = export(plan, window_end=args.window_end, identity_sha256=args.identity_sha256,
                     destination_sha256=args.destination_sha256, monitor_profile_sha256=args.monitor_profile_sha256)
        with open(args.output, "xb") as stream:
            stream.write(raw)
        print(json.dumps({"result": "observations-exported", "report_sha256": backup.sha256(raw)}))
        return 0
    except backup.Refusal as error:
        print(json.dumps({"result": "REFUSED", "reason": str(error)}))
    except (OSError, ValueError, KeyError, TypeError):
        print(json.dumps({"result": "REFUSED", "reason": "observer plan, journal or remote read failed"}))
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
