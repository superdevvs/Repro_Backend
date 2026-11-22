"""Synthetic SQLite snapshots only. The publisher operates on an in-memory remote."""
from contextlib import closing
import json
import os
from pathlib import Path
import sqlite3
import tempfile
import unittest

import prepare_sqlite_backup as stage
from test_publish_backup import FixtureRemote


@unittest.skipUnless(os.name == "posix", "production staging requires Linux permissions")
class PrepareBackupTest(unittest.TestCase):
    def setUp(self):
        base = os.environ.get("REPRO_OFFSITE_TEST_ROOT", str(Path.home()))
        temporary = tempfile.TemporaryDirectory(dir=base, prefix="repro-closed-backup-")
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.database = self.root / stage.DATABASE
        self.producer = self.root / stage.PRODUCER
        with closing(sqlite3.connect(self.database)) as db:
            db.execute("CREATE TABLE fixture (id INTEGER PRIMARY KEY, value TEXT)")
            db.execute("INSERT INTO fixture VALUES (1, 'fixture')")
            db.commit()
        self.database.chmod(0o600)
        self.record = {"format_version": 1, "driver": "sqlite", "created_utc": "2026-11-22T13:00:00+00:00",
                       "application_booted": False, "tables": 1, "file": stage.DATABASE}
        self.record_database_bytes()
        self.release = "a" * 40
        self.identity = "sqlite-fixture"

    def record_database_bytes(self):
        self.record["bytes"], self.record["sha256"] = stage.backup.local_hash(self.database)
        self.record_producer()

    def record_producer(self):
        raw = stage.backup.encode(self.record)
        self.producer.write_bytes(raw)
        self.producer.chmod(0o600)
        self.producer_digest = stage.backup.sha256(raw)

    def prepare(self):
        return stage.prepare(self.root, producer_sha256=self.producer_digest,
                             release=self.release, identity=self.identity)

    def test_sealed_manifest_matches_existing_publisher_and_restored_sqlite(self):
        receipt = self.prepare()
        self.assertIs(receipt["remote_accessed"], False)
        path = self.root / stage.OUTPUT
        stage.backup.private_path(path)
        raw = path.read_bytes()
        manifest = stage.backup.parse_manifest(raw, receipt["manifest_sha256"], "database", self.release, self.identity)
        self.assertEqual(manifest["created_at"], "2026-11-22T13:00:00Z")
        remote = FixtureRemote()
        result = stage.backup.publish(manifest, raw, self.root, "fixture-crypt", remote, self.root)
        self.assertEqual(result["result"], "published-readback-verified")
        copy = self.root / "restored-fixture.sqlite"
        copy.write_bytes(remote.objects[result["remote"] + "/artifacts/" + stage.DATABASE])
        with closing(sqlite3.connect(copy)) as db:
            self.assertEqual(db.execute("SELECT * FROM fixture").fetchall(), [(1, "fixture")])
        with self.assertRaisesRegex(stage.backup.Refusal, "already exists"):
            self.prepare()
        self.assertEqual(path.read_bytes(), raw)

    def test_existing_sidecar_and_backup_lock_refuse_even_with_expected_hashes(self):
        for path in (self.root / (stage.DATABASE + "-wal"), self.root / ".database-backup.lock"):
            with self.subTest(path=path.name):
                path.touch()
                with self.assertRaises(stage.backup.Refusal):
                    self.prepare()
                path.unlink()
        self.assertFalse((self.root / stage.OUTPUT).exists())

    def test_wrong_table_count_refuses_publication(self):
        self.record["tables"] = 2
        self.record_producer()
        with self.assertRaisesRegex(stage.backup.Refusal, "table count"):
            self.prepare()
        self.assertFalse((self.root / stage.OUTPUT).exists())

    def test_damaged_bytes_without_original_pin_cannot_create_a_manifest(self):
        self.database.write_bytes(b"synthetic damaged bytes")
        with self.assertRaisesRegex(stage.backup.Refusal, "size or digest"):
            self.prepare()
        self.record_database_bytes()
        with self.assertRaises(sqlite3.DatabaseError):
            self.prepare()
        self.assertFalse((self.root / stage.OUTPUT).exists())

    def test_unpinned_and_symlinked_producer_receipts_refuse_staging(self):
        old = self.producer.read_bytes()
        self.producer.write_bytes(json.dumps(self.record).encode())
        with self.assertRaisesRegex(stage.backup.Refusal, "receipt digest"):
            self.prepare()
        target = self.root / "other-producer.json"
        target.write_bytes(old)
        self.producer.unlink()
        self.producer.symlink_to(target)
        with self.assertRaisesRegex(stage.backup.Refusal, "unsafe path"):
            self.prepare()


if __name__ == "__main__":
    unittest.main()
