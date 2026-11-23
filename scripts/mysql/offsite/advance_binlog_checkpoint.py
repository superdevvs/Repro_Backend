#!/usr/bin/env python3
"""Track contiguous closed logs recorded by independently pinned publications.

Initialise once in a private directory from the reviewed stock MySQL base
receipt. Advance only from a trusted publication receipt for the reviewed crypt
destination. Stock receipts name the crypt profile, not the backing bucket.
A check reads local state without testing storage, retention, or replay.
"""
from __future__ import annotations

import argparse
from contextlib import contextmanager
import datetime as dt
import json
import os
from pathlib import Path
import re
import stat
import sys
import tempfile
import uuid

sys.path.insert(0, str(Path(__file__).resolve().parent))
import check_backup_freshness as publication
import prepare_closed_binlogs as archive
import publish_backup as backup


SCHEMA = "repro.mysql.closed-binlog-checkpoint.v1"
STATE = "closed-binlog-checkpoint.json"
LOCK = "closed-binlog-checkpoint.lock"
RECEIPT_LIMIT = 16 * 1024


def source(path: Path, *, digest: str, identity: str, database: str,
           server_id: int, release: str, crypt_remote: str) -> dict:
    chain = archive.chain
    backup.need(chain.base_backup.UUID.fullmatch(identity) and chain.base_backup.DB_NAME.fullmatch(database)
                and type(server_id) is int and 1 <= server_id <= chain.MAX_POSITION
                and re.fullmatch(r"[0-9a-f]{40}|[0-9a-f]{64}", release) and backup.NAME.fullmatch(crypt_remote),
                "invalid reviewed archive source")
    record = chain.pinned_json(path, digest, 64 * 1024)
    chain.base_backup.verify_receipt(record, identity, database)
    baseline, _ = chain.coordinate({"file": record["pitr"]["binlog_file"], "position": record["pitr"]["binlog_position"]})
    backup.relative_path(baseline)
    return {"source_uuid": identity, "server_id": server_id, "database": database, "release": release,
            "crypt_remote": crypt_remote, "base_file": baseline, "base_receipt_sha256": digest}


def review_batch(binding: dict, *, inventory_raw: bytes, manifest_raw: bytes, manifest_sha256: str,
                 receipt_raw: bytes, receipt_sha256: str, now: dt.datetime) -> dict:
    chain = archive.chain
    archive_identity = binding["source_uuid"] + ".closed-binlogs"
    # The publication checker validates identity, counts, digests and the
    # stock read-back result. An old accounting receipt may still advance an
    # archive gap, so snapshot freshness is intentionally not required here.
    publication.check(receipt_raw, manifest_raw, receipt_sha256=receipt_sha256, scope="database",
                      release=binding["release"], identity=archive_identity, crypt_remote=binding["crypt_remote"], now=now)
    manifest = backup.parse_manifest(manifest_raw, manifest_sha256, "database", binding["release"], archive_identity)

    inventory_digest = backup.sha256(inventory_raw)
    inventory_item = {"path": archive.INVENTORY, "bytes": len(inventory_raw), "sha256": inventory_digest}
    backup.need(0 < len(inventory_raw) <= archive.LIMIT and inventory_item in manifest["artifacts"],
                "manifest does not pin the supplied closed log inventory")
    record = json.loads(inventory_raw)
    backup.need(isinstance(record, dict)
                and set(record) == {"schema", "source_uuid", "server_id", "sealed_at", "segments", "next_file"}
                and record["schema"] == archive.SCHEMA and record["source_uuid"] == binding["source_uuid"]
                and type(record["server_id"]) is int and record["server_id"] == binding["server_id"]
                and record["sealed_at"] == manifest["created_at"], "published archive source or capture time differs")
    segments = record["segments"]
    backup.need(isinstance(segments, list) and 0 < len(segments) <= 4096, "published log sequence is empty or invalid")
    expected, files = None, []
    for entry in segments:
        backup.need(isinstance(entry, dict) and set(entry) == {"file", "bytes", "sha256"}
                    and type(entry["bytes"]) is int and len(chain.MAGIC) < entry["bytes"] <= chain.MAX_POSITION
                    and isinstance(entry["sha256"], str) and backup.HEX.fullmatch(entry["sha256"]) is not None,
                    "published log entry is malformed")
        file = chain.log_name(entry["file"])
        backup.need(expected is None or expected == file, "published log inventory has a gap or changed origin")
        files.append({"path": file, "bytes": entry["bytes"], "sha256": entry["sha256"]})
        expected = chain.next_log(file)
    backup.need(record["next_file"] == expected and manifest["artifacts"] == files + [inventory_item],
                "published artifact set or next file differs from the staged sequence")
    return {"first_file": files[0]["path"], "next_file": expected, "backup_id": manifest["backup_id"],
            "inventory_sha256": inventory_digest, "manifest_sha256": manifest_sha256, "receipt_sha256": receipt_sha256}


def read_state(root: Path, binding: dict) -> dict:
    current = json.loads(publication.read_private(root / STATE, RECEIPT_LIMIT))
    backup.need(isinstance(current, dict) and set(current) == {"schema", "source", "next_file", "last"}
                and current["schema"] == SCHEMA and current["source"] == binding, "checkpoint source or base pin changed")
    next_file = archive.chain.log_name(current["next_file"])
    last = current["last"]
    if last is None:
        backup.need(next_file == binding["base_file"], "uninitialised checkpoint differs from its base")
    else:
        backup.need(isinstance(last, dict)
                    and set(last) == {"first_file", "next_file", "backup_id",
                                      "inventory_sha256", "manifest_sha256", "receipt_sha256"}
                    and next_file == last["next_file"] and str(uuid.UUID(last["backup_id"])) == last["backup_id"]
                    and all(isinstance(last[key], str) and backup.HEX.fullmatch(last[key])
                            for key in ("inventory_sha256", "manifest_sha256", "receipt_sha256")),
                    "invalid prior publication checkpoint")
        base, first, end = (archive.chain.log_name(name).rsplit(".", 1)
                            for name in (binding["base_file"], last["first_file"], next_file))
        backup.need(base[0] == first[0] == end[0] and int(base[1]) <= int(first[1]) < int(end[1]),
                    "checkpoint file sequence disagrees with base")
    return current


@contextmanager
def held_lock(root: Path, *, shared: bool = False, create: bool = False):
    import fcntl

    flags = os.O_RDONLY if shared else os.O_RDWR
    if create:
        flags |= os.O_CREAT | os.O_EXCL
    fd = os.open(root / LOCK, flags | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600)
    try:
        info = os.fstat(fd)
        backup.need(stat.S_ISREG(info.st_mode) and info.st_uid == os.geteuid()
                    and info.st_nlink == 1 and stat.S_IMODE(info.st_mode) == 0o600, "unsafe archive checkpoint lock")
        try:
            fcntl.flock(fd, (fcntl.LOCK_SH if shared else fcntl.LOCK_EX) | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise backup.Refusal("archive checkpoint is busy") from error
        yield
    finally:
        os.close(fd)


def write_state(root: Path, record: dict) -> None:
    with tempfile.NamedTemporaryFile(dir=root, prefix=".closed-log-state-", delete=False) as stream:
        temporary = Path(stream.name)
        try:
            stream.write(backup.encode(record))
            stream.flush()
            os.fsync(stream.fileno())
            os.replace(temporary, root / STATE)
            backup.sync_directory(root)
        finally:
            temporary.unlink(missing_ok=True)


def initialise(root: Path, binding: dict) -> dict:
    backup.private_path(root, directory=True)
    backup.need(not any(root.iterdir()), "initialize requires a new empty private checkpoint directory")
    with held_lock(root, create=True):
        record = {"schema": SCHEMA, "source": binding, "next_file": binding["base_file"], "last": None}
        write_state(root, record)
        return report("closed-binlog-checkpoint-initialised", record)


def report(result: str, record: dict) -> dict:
    return {"result": result, "next_file": record["next_file"], "last": record["last"], "remote_accessed": False}


def check(root: Path, binding: dict) -> dict:
    backup.private_path(root, directory=True)
    with held_lock(root, shared=True):
        return report("closed-binlog-checkpoint-checked", read_state(root, binding))


def advance(root: Path, binding: dict, *, inventory_raw: bytes, manifest_raw: bytes, manifest_sha256: str,
            receipt_raw: bytes, receipt_sha256: str, now: dt.datetime) -> dict:
    proof = review_batch(binding, inventory_raw=inventory_raw, manifest_raw=manifest_raw, manifest_sha256=manifest_sha256,
                         receipt_raw=receipt_raw, receipt_sha256=receipt_sha256, now=now)
    backup.private_path(root, directory=True)
    with held_lock(root):
        current = read_state(root, binding)
        if proof == current["last"]:
            backup.sync_directory(root)
            return report("closed-binlog-checkpoint-already-advanced", current)
        backup.need(current["last"] is None or proof["backup_id"] != current["last"]["backup_id"],
                    "publication identifier reused with different evidence")
        backup.need(proof["first_file"] == current["next_file"], "publication does not begin at the checkpoint")
        updated = current | {"next_file": proof["next_file"], "last": proof}
        write_state(root, updated)
        return report("closed-binlog-checkpoint-advanced", updated)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    for mode in ("initialize", "check", "advance"):
        sub = commands.add_parser(mode)
        for name in ("state-dir", "base-receipt"):
            sub.add_argument("--" + name, type=Path, required=True)
        for name in ("base-receipt-sha256", "expected-release", "expected-identity", "expected-database", "crypt-remote"):
            sub.add_argument("--" + name, required=True)
        sub.add_argument("--expected-server-id", type=int, required=True)
        if mode == "advance":
            for name in ("inventory", "manifest", "receipt"):
                sub.add_argument("--" + name, type=Path, required=True)
            for name in ("manifest-sha256", "receipt-sha256"):
                sub.add_argument("--" + name, required=True)
    args = parser.parse_args()
    try:
        binding = source(args.base_receipt, digest=args.base_receipt_sha256, identity=args.expected_identity,
                         database=args.expected_database, server_id=args.expected_server_id,
                         release=args.expected_release, crypt_remote=args.crypt_remote)
        if args.command == "initialize":
            result = initialise(args.state_dir, binding)
        elif args.command == "check":
            result = check(args.state_dir, binding)
        else:
            result = advance(
                args.state_dir, binding, inventory_raw=publication.read_private(args.inventory, archive.LIMIT),
                manifest_raw=publication.read_private(args.manifest, archive.LIMIT), manifest_sha256=args.manifest_sha256,
                receipt_raw=publication.read_private(args.receipt, RECEIPT_LIMIT), receipt_sha256=args.receipt_sha256,
                now=dt.datetime.now(dt.timezone.utc),
            )
    except backup.Refusal as error:
        print(json.dumps({"result": "REFUSED", "reason": str(error), "remote_accessed": False}))
        return 1
    except (OSError, TypeError, ValueError, AttributeError, RecursionError):
        print(json.dumps({"result": "REFUSED", "reason": "invalid checkpoint evidence or local I/O failure",
                          "remote_accessed": False}))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
