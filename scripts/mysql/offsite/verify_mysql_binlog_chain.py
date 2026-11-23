#!/usr/bin/env python3
"""Verify rotated MySQL binlogs for a position-based recovery before replay."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import struct
import sys
import zlib

sys.path.insert(0, str(Path(__file__).resolve().parent))
import prepare_mysql_backup as base_backup
import publish_backup as backup

SCHEMA = "repro.mysql.binlog-chain.v1"
INVENTORY = "binlog-chain.json"
MAGIC = b"\xfebin"
EVENT_HEADER = struct.Struct("<IBIIIH")
BINLOG = re.compile(base_backup.BINLOG.decode("ascii"))
MAX_POSITION = 2**32 - 1
ROTATE, FORMAT_DESCRIPTION, XID = 4, 15, 16


def pinned_json(path: Path, digest: str, limit: int) -> dict:
    backup.private_path(path)
    with path.open("rb") as file:
        raw = file.read(limit + 1)
    backup.need(len(raw) <= limit and backup.sha256(raw) == digest, "pinned recovery metadata does not match")
    value = json.loads(raw)
    backup.need(isinstance(value, dict), "expected a recovery metadata object")
    return value


def log_name(value: str) -> str:
    backup.need(isinstance(value, str) and len(value) <= 255 and BINLOG.fullmatch(value) is not None,
                "invalid binary log filename")
    return value


def next_log(name: str) -> str:
    prefix, number = name.rsplit(".", 1)
    return f"{prefix}.{int(number) + 1:06d}"


def coordinate(value: dict) -> tuple[str, int]:
    backup.need(isinstance(value, dict) and set(value) == {"file", "position"} and
                type(value["position"]) is int and 4 <= value["position"] <= MAX_POSITION,
                "invalid recovery coordinate")
    return log_name(value["file"]), value["position"]


def verify_segment(path: Path, entry: dict, server_id: int, *, start: int | None, stop: int | None) -> None:
    backup.private_path(path)
    digest = hashlib.sha256()
    position = len(MAGIC)
    found_start, found_stop = start is None, stop is None
    rotation = struct.pack("<Q", 4) + next_log(path.name).encode("ascii")

    with path.open("rb") as file:
        def read_exact(size: int) -> bytes:
            data = file.read(size)
            backup.need(len(data) == size, "truncated binary log event")
            digest.update(data)
            return data

        backup.need(read_exact(len(MAGIC)) == MAGIC, "invalid MySQL binary log magic")
        rotated = False
        while position < entry["bytes"]:
            header = read_exact(EVENT_HEADER.size)
            _, kind, event_server, size, end, flags = EVENT_HEADER.unpack(header)
            backup.need(EVENT_HEADER.size + 4 <= size <= entry["bytes"] - position and end == position + size,
                        "binary log event framing or position differs")
            backup.need(event_server == server_id, "unexpected originating binary log server ID")
            is_first = position == len(MAGIC)
            backup.need((kind == FORMAT_DESCRIPTION) == is_first and not (is_first and flags & 1),
                        "binary log format event is missing or still open")
            remaining = size - EVENT_HEADER.size - 4
            checksum = zlib.crc32(header)
            if is_first:
                backup.need(58 <= remaining <= 1024, "invalid binary log format description length")
                payload = read_exact(remaining)
                backup.need(payload[:2] == b"\x04\x00" and payload[56] == EVENT_HEADER.size and payload[-1] == 1,
                            "binary log format v4 and CRC32 are required")
                checksum = zlib.crc32(payload, checksum)
            elif kind == ROTATE:
                backup.need(end == entry["bytes"] and remaining == len(rotation), "invalid terminal binary log rotation")
                payload = read_exact(remaining)
                backup.need(payload == rotation, "binary log rotation target mismatch")
                checksum = zlib.crc32(payload, checksum)
                rotated = True
            else:
                while remaining:
                    payload = read_exact(min(remaining, 1024 * 1024))
                    checksum = zlib.crc32(payload, checksum)
                    remaining -= len(payload)
            backup.need(read_exact(4) == struct.pack("<I", checksum), "binary log event checksum mismatch")
            found_start = found_start or position == start
            found_stop = found_stop or (end == stop and kind == XID)
            position = end
        backup.need(rotated and not file.read(1) and digest.hexdigest() == entry["sha256"],
                    "closed binary log size or digest mismatch")
    backup.need(found_start and found_stop, "recovery positions must start at an event and end after an InnoDB commit")


def verify(root: Path, *, inventory_sha256: str, producer_sha256: str,
           identity: str, expected_database: str) -> dict:
    backup.private_path(root, directory=True)
    backup.need(base_backup.UUID.fullmatch(identity) is not None and
                base_backup.DB_NAME.fullmatch(expected_database) is not None,
                "expected a canonical MySQL source UUID and database name")
    record = pinned_json(root / base_backup.PRODUCER, producer_sha256, 64 * 1024)
    base_backup.verify_receipt(record, identity, expected_database)
    inventory = pinned_json(root / INVENTORY, inventory_sha256, 4 * 1024**2)
    backup.need(set(inventory) == {"schema", "source_uuid", "server_id", "start", "stop", "segments"} and
                inventory["schema"] == SCHEMA and inventory["source_uuid"] == identity and
                type(inventory["server_id"]) is int and 1 <= inventory["server_id"] <= MAX_POSITION,
                "unexpected binary log inventory scope or identity")
    start_file, start_position = coordinate(inventory["start"])
    stop_file, stop_position = coordinate(inventory["stop"])
    point = record["pitr"]
    backup.need(start_file == point["binlog_file"] and start_position == point["binlog_position"],
                "binary log inventory does not start at the approved snapshot")
    backup.need(start_file != stop_file or start_position < stop_position, "recovery stop must follow the snapshot")

    segments = inventory["segments"]
    backup.need(isinstance(segments, list) and 0 < len(segments) <= 4096, "invalid binary log segment list")
    expected_name = start_file
    for entry in segments:
        backup.need(isinstance(entry, dict) and set(entry) == {"file", "bytes", "sha256"} and
                    type(entry["bytes"]) is int and len(MAGIC) < entry["bytes"] <= MAX_POSITION and
                    isinstance(entry["sha256"], str) and backup.HEX.fullmatch(entry["sha256"]) is not None,
                    "invalid binary log inventory entry")
        backup.need(log_name(entry["file"]) == expected_name, "binary log inventory is incomplete or out of order")
        expected_name = next_log(expected_name)
    backup.need(segments[-1]["file"] == stop_file, "recovery inventory must end at the selected log")

    for index, entry in enumerate(segments):
        verify_segment(root / entry["file"], entry, inventory["server_id"],
                       start=start_position if index == 0 else None,
                       stop=stop_position if index == len(segments) - 1 else None)
    return {"result": "closed-mysql-binlog-chain-verified", "source_uuid": identity,
            "segments": len(segments), "start": inventory["start"], "stop": inventory["stop"],
            "inventory_sha256": inventory_sha256, "contiguous_binlog_chain": True, "replay_verified": False}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, required=True)
    for name in ("inventory-sha256", "producer-sha256", "expected-identity", "expected-database"):
        parser.add_argument("--" + name, required=True)
    args = parser.parse_args()
    try:
        result = verify(args.source, inventory_sha256=args.inventory_sha256, producer_sha256=args.producer_sha256,
                        identity=args.expected_identity, expected_database=args.expected_database)
    except backup.Refusal as error:
        print(json.dumps({"result": "REFUSED", "reason": str(error)}))
        return 1
    except (OSError, ValueError, TypeError, RecursionError):
        print(json.dumps({"result": "REFUSED", "reason": "invalid recovery metadata or local I/O failure"}))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
