"""Deterministic synthetic event frames for position-based binlog verification."""
import os
from pathlib import Path
import struct
import tempfile
import unittest
import zlib

import verify_mysql_binlog_chain as chain

UUID = "11111111-2222-3333-4444-555555555555"
SERVER_ID = 64084
DATABASE = "repro_binlog_fixture"


def append(log, kind, payload, flags=0):
    size = chain.EVENT_HEADER.size + len(payload) + 4
    header = chain.EVENT_HEADER.pack(0, kind, SERVER_ID, size, len(log) + size, flags)
    log.extend(header + payload + struct.pack("<I", zlib.crc32(header + payload)))


def segment(rotation, *, open_flag=0):
    log = bytearray(chain.MAGIC)
    format_body = b"\x04\x00" + b"8.4.13".ljust(50, b"\0") + b"\0" * 4 + bytes([19, 1])
    append(log, chain.FORMAT_DESCRIPTION, format_body, open_flag)
    start = len(log)
    append(log, 2, b"opaque fixture event")
    append(log, chain.XID, struct.pack("<Q", 1))
    stop = len(log)
    append(log, 2, b"later event to exclude")
    append(log, chain.XID, struct.pack("<Q", 2))
    append(log, chain.ROTATE, struct.pack("<Q", 4) + rotation.encode("ascii"))
    return log, start, stop


@unittest.skipUnless(os.name == "posix", "production verification requires Linux permissions")
class VerifyMySqlBinlogChainTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory(dir=os.environ.get("REPRO_OFFSITE_TEST_ROOT", str(Path.home())))
        self.addCleanup(temp.cleanup)
        self.addCleanup(os.umask, os.umask(0o077))
        self.root = Path(temp.name)
        self.names = ["binlog.000001", "binlog.000002", "binlog.000003"]
        _, start, stop = segment(self.names[1])
        self.record = {
            "format_version": 1, "driver": "mysql", "created_utc": "2026-11-23T14:00:00+00:00",
            "application_booted": False, "schema_sha256": "a" * 64, "tables": 1,
            "foreign_keys": 0, "triggers": 0, "server_version": "8.4.13", "server_uuid": UUID,
            "database": DATABASE, "consistent_transaction": True, "includes_triggers_routines_events": True,
            "file": "database.sql.gz", "bytes": 17, "sha256": "b" * 64, "uncompressed_bytes": 21,
            "pitr": {"binlog_file": self.names[0], "binlog_position": start, "coordinate_source": "mysqldump-source-data"},
        }
        self.inventory = {"schema": chain.SCHEMA, "source_uuid": UUID, "server_id": SERVER_ID,
                          "start": {"file": self.names[0], "position": start},
                          "stop": {"file": self.names[-1], "position": stop}, "segments": []}
        for name in self.names:
            content, _, _ = segment(chain.next_log(name))
            self.write_segment(name, content)

    def write_segment(self, name, content):
        path = self.root / name
        path.write_bytes(content)
        info = {"file": name, "bytes": len(content), "sha256": chain.backup.sha256(content)}
        for index, existing in enumerate(self.inventory["segments"]):
            if existing["file"] == name:
                self.inventory["segments"][index] = info
                return
        self.inventory["segments"].append(info)

    def verify(self, **overrides):
        producer = chain.backup.encode(self.record)
        inventory = chain.backup.encode(self.inventory)
        (self.root / chain.base_backup.PRODUCER).write_bytes(producer)
        (self.root / chain.INVENTORY).write_bytes(inventory)
        values = {"inventory_sha256": chain.backup.sha256(inventory), "producer_sha256": chain.backup.sha256(producer),
                  "identity": UUID, "expected_database": DATABASE}
        return chain.verify(self.root, **(values | overrides))

    def test_verified_range_spans_three_closed_segments_and_excludes_later_events(self):
        result = self.verify()
        self.assertEqual(result["segments"], 3)
        self.assertEqual(result["stop"], self.inventory["stop"])
        self.assertIs(result["contiguous_binlog_chain"], True)
        self.assertIs(result["replay_verified"], False)

    def test_missing_middle_and_wrong_rotation_are_refused_even_with_fresh_pins(self):
        segments = self.inventory["segments"].copy()
        self.inventory["segments"].pop(1)
        with self.assertRaisesRegex(chain.backup.Refusal, "incomplete or out of order"):
            self.verify()
        self.inventory["segments"] = segments
        content, _, _ = segment("binlog.000009")
        self.write_segment(self.names[0], content)
        with self.assertRaisesRegex(chain.backup.Refusal, "rotation target"):
            self.verify()

    def test_corrupt_event_and_still_open_format_are_rejected(self):
        name = self.names[1]
        content, start, _ = segment(self.names[2])
        content[start + chain.EVENT_HEADER.size] ^= 1
        self.write_segment(name, content)
        with self.assertRaisesRegex(chain.backup.Refusal, "event checksum"):
            self.verify()
        self.write_segment(name, segment(self.names[2], open_flag=1)[0])
        with self.assertRaisesRegex(chain.backup.Refusal, "still open"):
            self.verify()

    def test_snapshot_pin_and_full_commit_are_required(self):
        with self.assertRaisesRegex(chain.backup.Refusal, "pinned recovery metadata"):
            self.verify(producer_sha256="0" * 64)
        stop = self.inventory["stop"]["position"]
        self.inventory["stop"]["position"] = stop - (chain.EVENT_HEADER.size + 8 + 4)
        with self.assertRaisesRegex(chain.backup.Refusal, "end after an InnoDB commit"):
            self.verify()
        self.inventory["stop"]["position"] = stop
        self.inventory["start"]["position"] += 1
        with self.assertRaisesRegex(chain.backup.Refusal, "does not start at"):
            self.verify()
