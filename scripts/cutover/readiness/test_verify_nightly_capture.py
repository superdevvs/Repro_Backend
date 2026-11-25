import hashlib
import json
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path

from scripts.cutover.readiness import check, verify_nightly_capture


class NightlySnapshotCaptureTests(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.root = Path(directory.name)
        self.night = {
            "scheduled_day_utc": "2026-11-25", "release_sha": "1" * 40,
            "snapshot_sha256": "2" * 64, "nightly_scope_sha256": "3" * 64,
            "import_and_verify_ms": 600_000,
            **dict.fromkeys(check.NIGHTLY_COUNTERS, 0),
            **dict.fromkeys(check.NIGHTLY_PROOFS, True),
        }
        self.producer = {
            "format_version": 1, "driver": "sqlite", "created_utc": "2026-11-25T10:00:00+00:00",
            "application_booted": False, "tables": 328, "file": "database.sqlite",
            "bytes": 4096, "sha256": self.night["snapshot_sha256"],
        }
        self.now = datetime(2026, 11, 25, 12, 0, tzinfo=timezone.utc)

    def write(self, filename, value):
        path = self.root / filename
        raw = json.dumps(value).encode("utf-8")
        path.write_bytes(raw)
        return path, hashlib.sha256(raw).hexdigest()

    def verify(self):
        return verify_nightly_capture.verify(*self.write("night.json", self.night),
                                             *self.write("producer.json", self.producer), now=self.now)

    def test_accepts_pinned_capture_from_the_scheduled_night(self):
        proof = self.verify()
        self.assertEqual(proof["captured_at_utc"], "2026-11-25T10:00:00Z")
        self.assertEqual(proof["snapshot_sha256"], self.night["snapshot_sha256"])

    def test_reused_capture_cannot_qualify_for_another_night_with_the_same_bytes(self):
        self.night["scheduled_day_utc"] = "2026-11-24"
        with self.assertRaisesRegex(check.Failed, "capture date differs"):
            self.verify()

    def test_refuses_wrong_snapshot_and_future_capture(self):
        self.producer["sha256"] = "4" * 64
        with self.assertRaisesRegex(check.Failed, "different snapshots"):
            self.verify()
        self.producer["sha256"] = self.night["snapshot_sha256"]
        self.producer["created_utc"] = "2026-11-25T12:00:01Z"
        with self.assertRaisesRegex(check.Failed, "in the future"):
            self.verify()

    def test_refuses_changed_receipt_even_if_the_values_are_unchanged(self):
        night, night_sha = self.write("night.json", self.night)
        producer, producer_sha = self.write("producer.json", self.producer)
        producer.write_bytes(producer.read_bytes() + b"\n")
        with self.assertRaisesRegex(check.Failed, "reviewed SHA-256"):
            verify_nightly_capture.verify(night, night_sha, producer, producer_sha, now=self.now)

    def test_refuses_redacted_receipt_without_producer_time(self):
        del self.producer["created_utc"]
        with self.assertRaisesRegex(check.Failed, "valid UTC-dated"):
            self.verify()

    def test_private_capture_uses_live_release_and_omits_protected_path(self):
        source_release = "a" * 40
        private = {
            "backup_tool_sha256": "b" * 64, "completed_utc": "2026-11-25T10:00:00.6552+00:00",
            "copy_seconds": 22.562, "crm_migrations": 9, "crm_tables": 37,
            "linked_client_contacts": 1206, "release": source_release,
            "result": "read-only-online-backup-pass", "snapshot_bytes": 4_460_900_352,
            "snapshot_path": "/private/snapshot.sqlite", "snapshot_sha256": self.night["snapshot_sha256"],
            "source_opened_read_only": True,
        }
        night, night_sha = self.write("night.json", self.night)
        producer, producer_sha = self.write("producer.json", private)
        proof = verify_nightly_capture.verify(night, night_sha, producer, producer_sha, now=self.now,
                                             expected_source_release=source_release)
        self.assertEqual(proof["source_release_sha"], source_release)
        self.assertEqual(proof["captured_at_utc"], "2026-11-25T10:00:00.6552Z")
        self.assertNotIn("/private/", json.dumps(proof))
        with self.assertRaisesRegex(check.Failed, "reviewed source release"):
            verify_nightly_capture.verify(night, night_sha, producer, producer_sha, now=self.now,
                                          expected_source_release=self.night["release_sha"])
        private["source_opened_read_only"] = False
        producer, producer_sha = self.write("producer.json", private)
        with self.assertRaisesRegex(check.Failed, "Invalid private SQLite source"):
            verify_nightly_capture.verify(night, night_sha, producer, producer_sha, now=self.now,
                                          expected_source_release=source_release)
