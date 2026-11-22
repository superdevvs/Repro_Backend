#!/usr/bin/env python3
"""Prepare a completed, checksum-pinned SQLite backup for encrypted publication."""
from __future__ import annotations

import argparse
from contextlib import closing
import datetime as dt
import json
import os
from pathlib import Path
import sqlite3
import sys
import tempfile
import time
import uuid

sys.path.insert(0, str(Path(__file__).resolve().parent))
import publish_backup as backup
from restore_backup import sync_directory

PRODUCER = "database-backup.json"
DATABASE = "database.sqlite"
OUTPUT = "input-manifest.json"


def prepare(root: Path, *, producer_sha256: str, release: str, identity: str) -> dict:
    backup.private_path(root, directory=True)
    backup.need(not os.path.lexists(root / OUTPUT), "publication manifest already exists")
    backup.need(not os.path.lexists(root / ".database-backup.lock"), "backup producer still holds the batch")
    producer = root / PRODUCER
    database = root / DATABASE
    backup.private_path(producer)
    backup.need(producer.stat().st_size <= 64 * 1024, "backup producer receipt too large")
    raw_producer = producer.read_bytes()
    backup.need(backup.sha256(raw_producer) == producer_sha256, "backup producer receipt digest mismatch")
    record = json.loads(raw_producer)
    required = {"format_version", "driver", "created_utc", "application_booted", "tables", "file", "bytes", "sha256"}
    backup.need(isinstance(record, dict) and set(record) == required and
                type(record["format_version"]) is int and record["format_version"] == 1 and
                record["driver"] == "sqlite" and record["application_booted"] is False and
                record["file"] == DATABASE and type(record["tables"]) is int and record["tables"] > 0 and
                type(record["bytes"]) is int and record["bytes"] > 0 and isinstance(record["sha256"], str) and
                backup.HEX.fullmatch(record["sha256"]) is not None, "unexpected SQLite backup producer receipt")
    produced = dt.datetime.fromisoformat(record["created_utc"])
    backup.need(produced.utcoffset() == dt.timedelta(0), "backup producer time must be UTC")

    def verify_closed() -> None:
        backup.need(not any(os.path.lexists(str(database) + ext) for ext in ("-wal", "-shm", "-journal")) and
                    not os.path.lexists(root / ".database-backup.lock"), "SQLite backup is not closed and standalone")
        backup.need(backup.local_hash(database) == (record["bytes"], record["sha256"]),
                    "SQLite backup size or digest mismatch")

    verify_closed()
    deadline = time.monotonic() + 300
    with closing(sqlite3.connect(database.as_uri() + "?mode=ro&immutable=1", uri=True)) as db:
        db.set_progress_handler(lambda: int(time.monotonic() >= deadline), 10_000)
        db.execute("PRAGMA query_only=ON")
        db.execute("PRAGMA trusted_schema=OFF")
        backup.need(db.execute("PRAGMA quick_check(1)").fetchone() == ("ok",), "SQLite backup integrity check failed")
        tables = db.execute("SELECT COUNT(*) FROM sqlite_schema WHERE type='table' "
                            "AND name NOT LIKE 'sqlite_%'").fetchone()[0]
        backup.need(tables == record["tables"], "SQLite backup table count differs from receipt")
    verify_closed()
    backup.need(backup.local_hash(producer) == (len(raw_producer), producer_sha256), "backup producer receipt changed")

    manifest = {
        "schema": backup.INPUT_SCHEMA, "backup_id": str(uuid.uuid4()),
        "created_at": produced.strftime("%Y-%m-%dT%H:%M:%SZ"), "scope": "database",
        "release": release, "identity": identity,
        "artifacts": [
            {"path": DATABASE, "bytes": record["bytes"], "sha256": record["sha256"]},
            {"path": PRODUCER, "bytes": len(raw_producer), "sha256": producer_sha256},
        ],
    }
    raw = backup.encode(manifest)
    digest = backup.sha256(raw)
    backup.parse_manifest(raw, digest, "database", release, identity)
    with tempfile.NamedTemporaryFile(dir=root, prefix=".input-manifest-", delete=False) as stream:
        staged = Path(stream.name)
        try:
            os.fchmod(stream.fileno(), 0o600)
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
            os.link(staged, root / OUTPUT, follow_symlinks=False)
        finally:
            staged.unlink()
    sync_directory(root)
    return {"result": "sqlite-backup-ready-for-publication", "backup_id": manifest["backup_id"],
            "scope": "database", "tables": tables, "manifest_sha256": digest,
            "manifest_bytes": len(raw), "remote_accessed": False}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, required=True)
    for name in ("producer-sha256", "expected-release", "expected-identity"):
        parser.add_argument("--" + name, required=True)
    args = parser.parse_args()
    try:
        result = prepare(args.source, producer_sha256=args.producer_sha256,
                         release=args.expected_release, identity=args.expected_identity)
    except backup.Refusal as error:
        print(json.dumps({"result": "REFUSED", "reason": str(error)}))
        return 1
    except (OSError, ValueError, TypeError, sqlite3.Error) as error:
        print(json.dumps({"result": "REFUSED", "reason": type(error).__name__}))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
