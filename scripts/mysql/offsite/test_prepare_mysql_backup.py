"""Synthetic PITR producer artifacts and in-memory publication/retrieval."""

import copy
import gzip
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest

import prepare_mysql_backup as stage
import publish_backup as backup
import restore_backup as recovery
from test_publish_backup import FixtureRemote
from test_restore_backup import MemoryRemote


DATABASE_NAME = "repro_fixture"
IDENTITY = "11111111-2222-3333-4444-555555555555"
RELEASE = "a" * 40
COORDINATE = b"-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000001', SOURCE_LOG_POS=42;\n"
HEADER = (
    b"-- MySQL dump 10.13  Distrib 8.4.13, for Linux (x86_64)\n"
    b"-- Host: localhost    Database: repro_fixture\n"
    b"-- ------------------------------------------------------\n"
    b"-- Server version\t8.4.13\n"
    b"--\n-- Position to start replication or point-in-time recovery from\n--\n\n"
)
BODY = (
    b"-- Table structure for table `fixture`\n"
    b"CREATE TABLE `fixture` (id int PRIMARY KEY) ENGINE=InnoDB;\n"
    b"INSERT INTO `fixture` VALUES (17);\n"
)
FOOTER = b"-- Dump completed on 2026-11-22 18:00:00\n"
SQL = HEADER + COORDINATE + BODY + FOOTER


@unittest.skipUnless(os.name == "posix", "production staging requires Linux permissions")
class PrepareMySqlBackupTest(unittest.TestCase):
    def setUp(self):
        base = os.environ.get("REPRO_OFFSITE_TEST_ROOT", str(Path.home()))
        temporary = tempfile.TemporaryDirectory(dir=base, prefix="repro-mysql-pitr-")
        self.addCleanup(temporary.cleanup)
        self.addCleanup(os.umask, os.umask(0o077))
        self.root = Path(temporary.name)
        self.artifact = self.root / stage.DATABASE
        self.producer = self.root / stage.PRODUCER
        self.manifest = self.root / stage.OUTPUT
        self.record = {
            "format_version": 1, "driver": "mysql", "created_utc": "2026-11-22T18:00:00+00:00",
            "application_booted": False, "schema_sha256": "b" * 64, "tables": 1,
            "foreign_keys": 0, "triggers": 0, "server_version": "8.4.13", "server_uuid": IDENTITY,
            "database": DATABASE_NAME, "consistent_transaction": True, "includes_triggers_routines_events": True,
            "file": stage.DATABASE, "pitr": {
                "binlog_file": "binlog.000001", "binlog_position": 42, "coordinate_source": "mysqldump-source-data",
            },
        }
        self.write_dump(SQL)

    def write_dump(self, sql):
        self.record["uncompressed_bytes"] = len(sql)
        self.write_archive(gzip.compress(sql, mtime=0))

    def write_archive(self, archive):
        self.artifact.write_bytes(archive)
        self.record["bytes"] = len(archive)
        self.record["sha256"] = backup.sha256(archive)
        self.write_receipt()

    def write_receipt(self):
        raw = backup.encode(self.record)
        self.producer.write_bytes(raw)
        self.producer_digest = backup.sha256(raw)

    def prepare(self, **overrides):
        options = {"producer_sha256": self.producer_digest, "release": RELEASE, "identity": IDENTITY,
                   "expected_database": DATABASE_NAME}
        return stage.prepare(self.root, **(options | overrides))

    def assert_refused(self, **overrides):
        with self.assertRaises((backup.Refusal, OSError, EOFError, ValueError)):
            self.prepare(**overrides)
        self.assertFalse(self.manifest.exists())

    def test_publisher_and_recovery_accept_the_exact_two_producer_artifacts(self):
        receipt = self.prepare()
        self.assertEqual(receipt["result"], "mysql-pitr-base-ready-for-publication")
        self.assertIs(receipt["remote_accessed"], False)
        self.assertIs(receipt["binlog_continuity_verified"], False)
        backup.private_path(self.manifest)
        raw = self.manifest.read_bytes()
        manifest = backup.parse_manifest(raw, receipt["manifest_sha256"], "database", RELEASE, IDENTITY)
        self.assertEqual(manifest["backup_id"], receipt["backup_id"])
        self.assertEqual(len(raw), receipt["manifest_bytes"])
        self.assertEqual(manifest["created_at"], "2026-11-22T18:00:00Z")
        self.assertCountEqual([item["path"] for item in manifest["artifacts"]], [stage.DATABASE, stage.PRODUCER])

        remote = FixtureRemote()
        with tempfile.TemporaryDirectory(dir=self.root) as scratch:
            published = backup.publish(manifest, raw, self.root, "encrypted", remote, Path(scratch))
        self.assertEqual(published["result"], "published-readback-verified")
        output = self.root / "recovered"
        arguments = SimpleNamespace(
            output=output, crypt_remote="encrypted", scope="database", backup_id=receipt["backup_id"],
            expected_identity=IDENTITY, expected_release=RELEASE, timeout_seconds=60,
            completion_bytes=published["completion_bytes"], completion_sha256=published["completion_sha256"],
            manifest_bytes=published["input_manifest_bytes"], manifest_sha256=published["input_manifest_sha256"],
        )
        recovered = recovery.restore(arguments, MemoryRemote(remote.objects))
        self.assertIs(recovered["database_restore_verified"], False)
        self.assertEqual(gzip.decompress((output / "artifacts" / stage.DATABASE).read_bytes()), SQL)
        self.assertEqual((output / "artifacts" / stage.PRODUCER).read_bytes(), self.producer.read_bytes())

        with self.assertRaises(backup.Refusal):
            self.prepare()
        self.assertEqual(self.manifest.read_bytes(), raw)

    def test_requires_the_independent_receipt_pin_uuid_and_database(self):
        self.assert_refused(producer_sha256="c" * 64)
        self.assert_refused(identity="21111111-2222-3333-4444-555555555555")
        self.assert_refused(expected_database="different_fixture")

    def test_refuses_open_batch_and_symlinked_artifact(self):
        lock = self.root / ".database-backup.lock"
        lock.touch()
        self.assert_refused()
        lock.unlink()
        target = self.root / "renamed-producer-artifact"
        self.artifact.rename(target)
        self.artifact.symlink_to(target)
        self.assert_refused()

    def test_truncated_gzip_with_a_matching_receipt_is_refused(self):
        self.write_archive(self.artifact.read_bytes()[:-4])
        self.assert_refused()

    def test_gzip_size_and_digest_must_match_the_pinned_receipt(self):
        self.artifact.write_bytes(gzip.compress(SQL.replace(b"(17)", b"(18)"), mtime=0))
        self.assert_refused()

    def test_refuses_unsafe_or_unbound_recovery_coordinates(self):
        for sql in (HEADER + BODY + COORDINATE + FOOTER,
                    HEADER + COORDINATE + COORDINATE + BODY + FOOTER,
                    HEADER + COORDINATE[3:] + BODY + FOOTER,
                    SQL.replace(b"SOURCE_LOG_POS=42", b"SOURCE_LOG_POS=43"),
                    SQL.removesuffix(FOOTER)):
            with self.subTest(sql=sql):
                self.write_dump(sql)
                self.assert_refused()

    def test_refuses_malformed_or_non_pitr_producer_receipts(self):
        original = self.record
        for change in ({"pitr": None},
                       {"pitr": original["pitr"] | {"binlog_file": "../binlog.000001"}},
                       {"pitr": original["pitr"] | {"binlog_position": True}},
                       {"consistent_transaction": False},
                       {"driver": "sqlite"},
                       {"uncompressed_bytes": len(SQL) - 1}):
            with self.subTest(change=change):
                self.record = copy.deepcopy(original) | change
                self.write_receipt()
                self.assert_refused()


if __name__ == "__main__":
    unittest.main()
