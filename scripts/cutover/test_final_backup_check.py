"""Exercise the frozen-base publication gate with synthetic data."""
from __future__ import annotations

from contextlib import closing
from dataclasses import replace
import datetime as dt
import gzip
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import unittest

import final_backup_check as gate
from test_publish_backup import FixtureRemote
from test_prepare_mysql_backup import SQL


RELEASE = "a" * 40
SQLITE_LINEAGE = "fixture-source-lineage"
MYSQL_UUID = "11111111-2222-3333-4444-555555555555"
SQLITE_ID = "d3287efd-7f3b-4c86-a68c-abbb44c6012a"
MYSQL_ID = "5aba36a3-4436-4637-ab0b-d23c479790aa"
FREEZE = "2026-11-24T00:00:00Z"
NOW = dt.datetime.fromisoformat("2026-11-24T00:01:00.200Z")
SOURCE_SHA256 = gate.backup.sha256(b"exact frozen SQLite copy")


def sqlite_metadata(**changes) -> dict:
    return {
        "format_version": 1, "driver": "sqlite", "created_utc": "2026-11-24T00:00:00+00:00",
        "application_booted": False, "tables": 7, "file": "database.sqlite",
        "bytes": 40_960, "sha256": SOURCE_SHA256,
    } | changes


def mysql_metadata(**changes) -> dict:
    return {
        "format_version": 1, "driver": "mysql", "created_utc": "2026-11-24T00:00:05+00:00",
        "application_booted": False, "pitr": {
            "binlog_file": "binlog.000001", "binlog_position": 42, "coordinate_source": "mysqldump-source-data",
        },
        "schema_sha256": "b" * 64, "tables": 7, "foreign_keys": 8, "triggers": 1,
        "server_version": "8.4.13", "server_uuid": MYSQL_UUID, "database": "repro_fixture",
        "uncompressed_bytes": 8192, "consistent_transaction": True,
        "includes_triggers_routines_events": True, "file": "database.sql.gz", "bytes": 2560, "sha256": "c" * 64,
    } | changes


def publication(producer: dict, *, manifest_changes=None, receipt_changes=None, artifact_additions=()) -> gate.Publication:
    if producer["driver"] == "sqlite":
        identity, backup_id, remote = SQLITE_LINEAGE, SQLITE_ID, "sqlite-crypt"
    else:
        identity, backup_id, remote = MYSQL_UUID, MYSQL_ID, "mysql-crypt"
    raw_producer = gate.backup.encode(producer)
    created_at = dt.datetime.fromisoformat(producer["created_utc"]).strftime("%Y-%m-%dT%H:%M:%SZ")
    manifest = {
        "schema": gate.backup.INPUT_SCHEMA, "backup_id": backup_id, "scope": "database",
        "release": RELEASE, "identity": identity, "created_at": created_at,
        "artifacts": [
            {"path": producer["file"], "bytes": producer["bytes"], "sha256": producer["sha256"]},
            {"path": "database-backup.json", "bytes": len(raw_producer), "sha256": gate.backup.sha256(raw_producer)},
            *artifact_additions,
        ],
    } | (manifest_changes or {})
    raw_manifest = gate.backup.encode(manifest)
    receipt = gate.backup.encode({
        "result": "published-readback-verified", "scope": "database", "backup_id": manifest["backup_id"],
        "release": manifest["release"], "identity": manifest["identity"], "completion_sha256": "e" * 64,
        "completion_bytes": 777, "input_manifest_sha256": gate.backup.sha256(raw_manifest),
        "input_manifest_bytes": len(raw_manifest), "artifact_count": len(manifest["artifacts"]),
        "artifact_bytes": sum(item["bytes"] for item in manifest["artifacts"]),
        "remote": f"{remote}:database/{manifest['identity']}/{manifest['backup_id']}",
        "restore_verified": False, "retention_verified": False,
    } | (receipt_changes or {}))
    return gate.Publication(receipt, gate.backup.sha256(receipt), raw_manifest, raw_producer, remote)


class ReviewFrozenPublications(unittest.TestCase):
    def setUp(self):
        self.cutover = gate.FrozenCutover(
            FREEZE, RELEASE, SQLITE_LINEAGE, SOURCE_SHA256, MYSQL_UUID, "repro_fixture", "binlog.000001", 42,
        )
        self.sqlite = publication(sqlite_metadata())
        self.mysql = publication(mysql_metadata())

    def verify(self, *, cutover=None, sqlite=None, mysql=None, now=NOW):
        return gate.verify(cutover or self.cutover, sqlite or self.sqlite, mysql or self.mysql, now=now)

    def test_exact_frozen_publications_produce_bound_local_proof(self):
        result = self.verify()
        self.assertEqual(result["result"], "frozen-base-publications-verified")
        self.assertEqual(result["frozen_at"], FREEZE)
        self.assertEqual(result["release"], RELEASE)
        self.assertEqual(result["sqlite"]["snapshot_sha256"], SOURCE_SHA256)
        self.assertEqual(result["sqlite"]["snapshot_created_at"], FREEZE)
        self.assertEqual(result["sqlite"]["publication_receipt_sha256"], self.sqlite.receipt_sha256)
        self.assertEqual(result["mysql"]["producer_receipt_sha256"], gate.backup.sha256(self.mysql.producer))
        self.assertEqual(result["mysql"]["identity"], MYSQL_UUID)
        self.assertEqual(result["mysql"]["pitr"]["binlog_position"], 42)
        for claim in ("remote_accessed", "restore_verified", "retention_verified", "binlog_continuity_verified"):
            self.assertIs(result[claim], False)

    def test_freeze_window_binds_both_producers_and_rejects_future(self):
        before = "2026-11-23T23:59:59+00:00"
        future = "2026-11-24T00:01:01+00:00"
        for kwargs, error in (
            ({"sqlite": publication(sqlite_metadata(created_utc=before))}, "SQLite|sqlite"),
            ({"mysql": publication(mysql_metadata(created_utc=before))}, "mysql"),
            ({"mysql": publication(mysql_metadata(created_utc=future))}, "future"),
            ({"now": dt.datetime.fromisoformat("2026-11-23T23:59:59Z")}, "review window"),
        ):
            with self.subTest(kwargs=tuple(kwargs)), self.assertRaisesRegex(gate.backup.Refusal, error):
                self.verify(**kwargs)

    def test_rejects_wrong_requirements_and_snapshot_even_when_independently_repinned(self):
        for change in (
            {"release": "d" * 40}, {"sqlite_identity": "another-source"},
            {"sqlite_snapshot_sha256": "d" * 64}, {"mysql_uuid": "21111111-2222-3333-4444-555555555555"},
            {"mysql_database": "another_database"}, {"mysql_binlog_file": "binlog.000002"},
            {"mysql_binlog_position": 43}, {"mysql_binlog_position": True},
        ):
            with self.subTest(change=change), self.assertRaises(gate.backup.Refusal):
                self.verify(cutover=replace(self.cutover, **change))
        with self.assertRaisesRegex(gate.backup.Refusal, "frozen source"):
            self.verify(sqlite=publication(sqlite_metadata(sha256="f" * 64)))
        with self.assertRaisesRegex(gate.backup.Refusal, "different backups"):
            self.verify(mysql=replace(self.mysql, crypt_remote="another-crypt"))

    def test_rejects_altered_receipt_manifest_or_producer_against_original_pins(self):
        for change, error in (
            ({"receipt": self.mysql.receipt + b" "}, "receipt hash"),
            ({"manifest": self.mysql.manifest.replace(b"00:00:05", b"00:00:06")}, "manifest hash"),
            ({"producer": self.mysql.producer.replace(b"repro_fixture", b"other_fixture")}, "producer receipt"),
        ):
            with self.subTest(change=tuple(change)), self.assertRaisesRegex(gate.backup.Refusal, error):
                self.verify(mysql=replace(self.mysql, **change))

    def test_rejects_incomplete_false_or_duplicated_publications(self):
        for change in (
            {"result": "local-inputs-verified"}, {"result": "mysql-pitr-base-ready-for-publication"},
            {"completion_bytes": 0}, {"restore_verified": True}, {"retention_verified": True},
        ):
            with self.subTest(change=change), self.assertRaises(gate.backup.Refusal):
                self.verify(mysql=publication(mysql_metadata(), receipt_changes=change))
        with self.assertRaisesRegex(gate.backup.Refusal, "same backup ID"):
            self.verify(mysql=publication(mysql_metadata(), manifest_changes={"backup_id": SQLITE_ID}))

    def test_rejects_extra_artifact_and_changed_producer_snapshot_coordinates(self):
        extra = {"path": "binlog.000001", "bytes": 4, "sha256": "a" * 64}
        with self.assertRaisesRegex(gate.backup.Refusal, "unexpected artifact mix"):
            self.verify(mysql=publication(mysql_metadata(), artifact_additions=(extra,)))
        with self.assertRaisesRegex(gate.backup.Refusal, "different base snapshots"):
            self.verify(mysql=publication(mysql_metadata(), manifest_changes={"created_at": "2026-11-24T00:00:06Z"}))
        with self.assertRaisesRegex(gate.backup.Refusal, "MySQL PITR producer"):
            self.verify(mysql=publication(mysql_metadata(consistent_transaction=False)))
        with self.assertRaisesRegex(gate.backup.Refusal, "SQLite base producer"):
            self.verify(sqlite=publication(sqlite_metadata(application_booted=True)))

    def test_duplicate_json_keys_in_even_a_repinned_receipt_are_refused(self):
        changed = self.mysql.receipt.replace(b"{", b'{"scope": "database",', 1)
        with self.assertRaisesRegex(gate.backup.Refusal, "duplicate JSON keys"):
            self.verify(mysql=replace(self.mysql, receipt=changed, receipt_sha256=gate.backup.sha256(changed)))

    @unittest.skipUnless(os.name == "posix", "operator CLI reads protected Linux paths")
    def test_cli_accepts_actual_stock_staging_and_in_memory_publication(self):
        parent = Path(os.environ.get("REPRO_OFFSITE_TEST_ROOT", str(Path.home())))
        frozen_at = dt.datetime.now(dt.timezone.utc).replace(microsecond=0) - dt.timedelta(seconds=30)
        freeze = frozen_at.strftime("%Y-%m-%dT%H:%M:%SZ")
        with tempfile.TemporaryDirectory(dir=parent) as temporary:
            root = Path(temporary)
            fixture = {
                "sqlite": (gate.sqlite_base, sqlite_metadata(created_utc=frozen_at.isoformat()),
                           SQLITE_LINEAGE, "sqlite-crypt", {}),
                "mysql": (gate.mysql_base, mysql_metadata(created_utc=(frozen_at + dt.timedelta(seconds=5)).isoformat()),
                          MYSQL_UUID, "mysql-crypt",
                          {"expected_database": "repro_fixture"}),
            }
            paths = {}
            for engine, (stage, producer, identity, remote, extra) in fixture.items():
                folder = root / engine
                folder.mkdir(mode=0o700)
                database = folder / stage.DATABASE
                if engine == "sqlite":
                    with closing(sqlite3.connect(database)) as conn:
                        conn.execute("CREATE TABLE sample (id INTEGER PRIMARY KEY)")
                    producer["tables"] = 1
                else:
                    database.write_bytes(gzip.compress(SQL, mtime=0))
                    producer["uncompressed_bytes"] = len(SQL)
                database.chmod(0o600)
                producer["bytes"], producer["sha256"] = gate.backup.local_hash(database)
                producer_raw = gate.backup.encode(producer)
                producer_path = folder / stage.PRODUCER
                producer_path.write_bytes(producer_raw)
                producer_path.chmod(0o600)
                staged = stage.prepare(folder, producer_sha256=gate.backup.sha256(producer_raw), release=RELEASE,
                                       identity=identity, **extra)
                manifest_path = folder / stage.OUTPUT
                raw = manifest_path.read_bytes()
                manifest = gate.backup.parse_manifest(raw, staged["manifest_sha256"], "database", RELEASE, identity)
                with tempfile.TemporaryDirectory(dir=folder) as scratch:
                    receipt = gate.backup.publish(manifest, raw, folder, remote, FixtureRemote(), Path(scratch))
                receipt_path = folder / "publication-receipt.json"
                receipt_raw = gate.backup.encode(receipt)
                receipt_path.write_bytes(receipt_raw)
                receipt_path.chmod(0o600)
                paths[engine] = {
                    "receipt": str(receipt_path), "receipt-sha256": gate.backup.sha256(receipt_raw),
                    "manifest": str(manifest_path), "producer": str(producer_path), "crypt-remote": remote,
                    "snapshot_sha256": producer["sha256"],
                }

            argv = [sys.executable, "-I", "-B", str(Path(gate.__file__).resolve()), "--frozen-at", freeze,
                    "--expected-release", RELEASE, "--sqlite-identity", SQLITE_LINEAGE, "--sqlite-snapshot-sha256",
                    paths["sqlite"]["snapshot_sha256"], "--mysql-uuid", MYSQL_UUID, "--mysql-database",
                    "repro_fixture", "--mysql-binlog-file", "binlog.000001", "--mysql-binlog-position", "42"]
            for engine, items in paths.items():
                for key, value in items.items():
                    if key != "snapshot_sha256":
                        argv.extend((f"--{engine}-{key}", value))
            # The operator entrypoint reuses protected-path checks. This fixture
            # does not open a network connection or require cloud credentials.
            result = subprocess.run(argv, check=False, capture_output=True, text=True, timeout=15)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertEqual(json.loads(result.stdout)["result"], "frozen-base-publications-verified")
            Path(paths["mysql"]["receipt"]).chmod(0o644)
            refused = subprocess.run(argv, check=False, capture_output=True, text=True, timeout=15)
            self.assertEqual(refused.returncode, 1)
            self.assertEqual(json.loads(refused.stdout)["result"], "frozen-base-publications-refused")


if __name__ == "__main__":
    unittest.main()
