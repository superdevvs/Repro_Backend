#!/usr/bin/env python3
"""Stage a pinned batch of closed MySQL logs for standard encrypted publication.

Supply a private directory containing only the receipt and the listed, closed
file copies. Start with the log in the reviewed base snapshot, then advance the
expected first file only after a successful archive publication and read-back.
This tool does not collect logs, rotate servers, upload, or advance a checkpoint.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import os
from pathlib import Path
import re
import sys
import uuid

sys.path.insert(0, str(Path(__file__).resolve().parent))
import publish_backup as backup
import verify_mysql_binlog_chain as chain


SCHEMA = "repro.mysql.closed-binlogs.v1"
INVENTORY = "closed-binlogs.json"
OUTPUT = "input-manifest.json"
LIMIT = 4 * 1024**2


def prepare(root: Path, *, inventory_sha256: str, release: str, identity: str,
            server_id: int, first_file: str) -> dict:
    backup.need(chain.base_backup.UUID.fullmatch(identity) is not None and
                type(server_id) is int and 1 <= server_id <= chain.MAX_POSITION,
                "invalid reviewed binary log source")
    backup.relative_path(chain.log_name(first_file))
    backup.private_path(root, directory=True)
    backup.need(not os.path.lexists(root / OUTPUT), "publication manifest already exists")

    inventory_path = root / INVENTORY
    inventory = chain.pinned_json(inventory_path, inventory_sha256, LIMIT)
    backup.need(set(inventory) == {"schema", "source_uuid", "server_id", "sealed_at", "segments", "next_file"}
                and inventory["schema"] == SCHEMA and inventory["source_uuid"] == identity
                and type(inventory["server_id"]) is int and inventory["server_id"] == server_id,
                "closed log receipt disagrees with the reviewed source")
    sealed_at = inventory["sealed_at"]
    backup.need(isinstance(sealed_at, str) and re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T"
                r"[0-9]{2}:[0-9]{2}:[0-9]{2}Z", sealed_at) is not None, "closed log receipt requires UTC seconds")
    dt.datetime.strptime(sealed_at, "%Y-%m-%dT%H:%M:%SZ")

    segments = inventory["segments"]
    backup.need(isinstance(segments, list) and 0 < len(segments) <= 4096, "invalid closed log segment list")
    next_file = first_file
    artifacts = []
    for entry in segments:
        backup.need(isinstance(entry, dict) and set(entry) == {"file", "bytes", "sha256"}
                    and type(entry["bytes"]) is int and len(chain.MAGIC) < entry["bytes"] <= chain.MAX_POSITION
                    and isinstance(entry["sha256"], str) and backup.HEX.fullmatch(entry["sha256"]) is not None,
                    "invalid closed log inventory segment")
        name = backup.relative_path(chain.log_name(entry["file"]))
        backup.need(name == next_file, "closed log inventory does not continue at the expected file")
        artifacts.append({"path": name, "bytes": entry["bytes"], "sha256": entry["sha256"]})
        next_file = chain.next_log(name)
    backup.need(inventory["next_file"] == next_file, "closed log receipt next file disagrees with the sequence")
    backup.need({path.name for path in root.iterdir()} == {INVENTORY, *(item["path"] for item in artifacts)},
                "closed staging directory has missing or unexpected entries")

    for entry in segments:
        chain.verify_segment(root / entry["file"], entry, server_id, start=None, stop=None)
    inventory_size, inventory_digest = backup.local_hash(inventory_path)
    backup.need(inventory_size <= LIMIT and inventory_digest == inventory_sha256, "closed log receipt changed during staging")
    artifacts.append({"path": INVENTORY, "bytes": inventory_size, "sha256": inventory_digest})

    # Keep log-only receipts distinct from base-snapshot freshness evidence.
    archive_identity = identity + ".closed-binlogs"
    manifest = {"schema": backup.INPUT_SCHEMA, "backup_id": str(uuid.uuid4()), "created_at": sealed_at,
                "scope": "database", "release": release, "identity": archive_identity, "artifacts": artifacts}
    digest, size = backup.write_input_manifest(root, manifest)
    return {"result": "closed-mysql-binlogs-ready-for-publication", "scope": "database",
            "backup_id": manifest["backup_id"], "identity": archive_identity, "source_uuid": identity,
            "server_id": server_id, "first_file": first_file, "segments": len(segments), "next_file": next_file,
            "inventory_sha256": inventory_digest, "manifest_sha256": digest, "manifest_bytes": size,
            "remote_accessed": False, "archive_publication_verified": False}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, required=True)
    parser.add_argument("--expected-server-id", type=int, required=True)
    for name in ("inventory-sha256", "expected-release", "expected-identity", "expected-first-file"):
        parser.add_argument("--" + name, required=True)
    args = parser.parse_args()
    try:
        result = prepare(args.source, inventory_sha256=args.inventory_sha256, release=args.expected_release,
                         identity=args.expected_identity, server_id=args.expected_server_id, first_file=args.expected_first_file)
    except backup.Refusal as error:
        print(json.dumps({"result": "REFUSED", "reason": str(error)}))
        return 1
    except (OSError, TypeError, ValueError, RecursionError):
        print(json.dumps({"result": "REFUSED", "reason": "invalid closed log receipt or local I/O failure"}))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
