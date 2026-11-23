#!/usr/bin/env python3
"""Prepare a closed MySQL PITR base snapshot for encrypted publication."""
from __future__ import annotations

import argparse
import datetime as dt
import gzip
import json
import os
from pathlib import Path
import re
import sys
import uuid
import zlib

sys.path.insert(0, str(Path(__file__).resolve().parent))
import publish_backup as backup

PRODUCER = "database-backup.json"
DATABASE = "database.sql.gz"
OUTPUT = "input-manifest.json"
PRODUCER_LOCK = ".database-backup.lock"
UUID = re.compile(r"[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}")
DB_NAME = re.compile(r"[A-Za-z0-9_]{1,64}")
BINLOG = rb"[A-Za-z0-9][A-Za-z0-9_.-]*\.[0-9]{6,}"
FIELDS = {
    "format_version", "driver", "created_utc", "application_booted", "pitr",
    "schema_sha256", "tables", "foreign_keys", "triggers", "server_version",
    "server_uuid", "database", "uncompressed_bytes", "consistent_transaction",
    "includes_triggers_routines_events", "file", "bytes", "sha256",
}


def verify_receipt(record: dict, identity: str, expected_database: str) -> str:
    backup.need(isinstance(record, dict) and set(record) == FIELDS and
                type(record["format_version"]) is int and record["format_version"] == 1 and
                record["driver"] == "mysql" and record["application_booted"] is False and
                record["consistent_transaction"] is True and
                record["includes_triggers_routines_events"] is True and record["file"] == DATABASE,
                "unexpected MySQL PITR producer receipt")
    backup.need(record["server_uuid"] == identity and record["database"] == expected_database,
                "MySQL source UUID or database mismatch")
    backup.need(isinstance(record["server_version"], str) and
                re.fullmatch(r"8\.4\.[0-9]+(?:\.[0-9]+)?(?:[-+][A-Za-z0-9_.+-]+)?", record["server_version"]) is not None,
                "expected a MySQL 8.4 producer")
    backup.need(all(type(record[key]) is int and 0 < record[key] <= 10 * 1024**4
                    for key in ("tables", "bytes", "uncompressed_bytes")) and
                all(type(record[key]) is int and record[key] >= 0 for key in ("foreign_keys", "triggers")) and
                all(isinstance(record[key], str) and backup.HEX.fullmatch(record[key]) is not None
                    for key in ("sha256", "schema_sha256")), "invalid MySQL backup counts or hashes")
    point = record["pitr"]
    backup.need(isinstance(point, dict) and set(point) == {"binlog_file", "binlog_position", "coordinate_source"} and
                point["coordinate_source"] == "mysqldump-source-data" and
                isinstance(point["binlog_file"], str) and len(point["binlog_file"]) <= 255 and
                re.fullmatch(BINLOG.decode("ascii"), point["binlog_file"]) is not None and
                type(point["binlog_position"]) is int and 4 <= point["binlog_position"] <= 2**63 - 1,
                "invalid MySQL binlog coordinate")
    timestamp = record["created_utc"]
    backup.need(isinstance(timestamp, str) and
                re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|\+00:00)", timestamp) is not None,
                "backup producer time must be UTC seconds")
    return dt.datetime.fromisoformat(timestamp).strftime("%Y-%m-%dT%H:%M:%SZ")


def verify_dump(path: Path, record: dict) -> None:
    # Check gzip integrity and the producer's bounded mysqldump header/footer
    # rules without loading its table data into memory or running the SQL.
    size = 0
    prefix = bytearray()
    tail = b""
    try:
        with gzip.open(path, "rb") as stream:
            for block in iter(lambda: stream.read(1024 * 1024), b""):
                size += len(block)
                backup.need(size <= record["uncompressed_bytes"], "MySQL dump uncompressed size mismatch")
                if len(prefix) < 32768:
                    prefix.extend(block[:32768 - len(prefix)])
                tail = (tail + block)[-4096:]
    except (EOFError, gzip.BadGzipFile, zlib.error):
        raise backup.Refusal("invalid or truncated MySQL gzip dump") from None
    backup.need(size == record["uncompressed_bytes"], "MySQL dump uncompressed size mismatch")

    sections = re.split(rb"^-- (?:Table structure for table|Temporary view structure for view) ",
                        prefix, maxsplit=1, flags=re.MULTILINE)
    header = sections[0]
    point = record["pitr"]
    position = re.findall(rb"^-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='(" + BINLOG +
                          rb")', SOURCE_LOG_POS=([0-9]+);\r?$", header, flags=re.MULTILINE)
    expected = (point["binlog_file"].encode("ascii"), str(point["binlog_position"]).encode("ascii"))
    backup.need(len(sections) == 2 and header.startswith(b"-- MySQL dump ") and
                header.count(b"-- Position to start replication or point-in-time recovery from") == 1 and
                len(re.findall(rb"^(?:-- )?CHANGE (?:REPLICATION SOURCE|MASTER) TO\b", header, re.MULTILINE)) == 1 and
                position == [expected], "MySQL dump snapshot coordinate mismatch")
    backup.need(re.search(rb"^-- Dump completed on [0-9 -]+[0-9:]+\r?\n?\Z", tail, re.MULTILINE) is not None,
                "MySQL dump completion marker missing")


def prepare(root: Path, *, producer_sha256: str, release: str, identity: str, expected_database: str) -> dict:
    backup.need(UUID.fullmatch(identity) is not None and DB_NAME.fullmatch(expected_database) is not None,
                "expected a canonical MySQL source UUID and database name")
    backup.private_path(root, directory=True)
    backup.need(not os.path.lexists(root / OUTPUT), "publication manifest already exists")
    backup.need(not os.path.lexists(root / PRODUCER_LOCK), "backup producer still holds the batch")
    producer = root / PRODUCER
    database = root / DATABASE
    backup.private_path(producer)
    with producer.open("rb") as stream:
        raw_producer = stream.read(64 * 1024 + 1)
    backup.need(len(raw_producer) <= 64 * 1024, "backup producer receipt too large")
    backup.need(backup.sha256(raw_producer) == producer_sha256, "backup producer receipt digest mismatch")
    record = json.loads(raw_producer)
    created_at = verify_receipt(record, identity, expected_database)
    expected_dump = (record["bytes"], record["sha256"])
    backup.need(backup.local_hash(database) == expected_dump, "MySQL backup size or digest mismatch")
    verify_dump(database, record)
    backup.need(backup.local_hash(database) == expected_dump, "MySQL backup changed during staging")
    backup.need(backup.local_hash(producer) == (len(raw_producer), producer_sha256), "backup producer receipt changed")
    backup.need(not os.path.lexists(root / PRODUCER_LOCK), "backup producer still holds the batch")

    manifest = {
        "schema": backup.INPUT_SCHEMA, "backup_id": str(uuid.uuid4()),
        "created_at": created_at, "scope": "database", "release": release, "identity": identity,
        "artifacts": [
            {"path": DATABASE, "bytes": expected_dump[0], "sha256": expected_dump[1]},
            {"path": PRODUCER, "bytes": len(raw_producer), "sha256": producer_sha256},
        ],
    }
    digest, size = backup.write_input_manifest(root, manifest)
    return {"result": "mysql-pitr-base-ready-for-publication", "backup_id": manifest["backup_id"],
            "scope": "database", "tables": record["tables"], "manifest_sha256": digest,
            "manifest_bytes": size, "remote_accessed": False, "binlog_continuity_verified": False}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, required=True)
    for name in ("producer-sha256", "expected-release", "expected-identity", "expected-database"):
        parser.add_argument("--" + name, required=True)
    args = parser.parse_args()
    try:
        result = prepare(args.source, producer_sha256=args.producer_sha256,
                         release=args.expected_release, identity=args.expected_identity, expected_database=args.expected_database)
    except backup.Refusal as error:
        print(json.dumps({"result": "REFUSED", "reason": str(error)}))
        return 1
    except (OSError, TypeError, ValueError, RecursionError):
        print(json.dumps({"result": "REFUSED", "reason": "invalid backup metadata or local I/O failure"}))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
