"""Checkpoint evidence and interruption tests with a stock fake publication."""
import datetime as dt
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import uuid

import advance_binlog_checkpoint as checkpoint
import prepare_closed_binlogs as archive
import publish_backup as backup
from test_publish_backup import FixtureRemote
from test_verify_mysql_binlog_chain import DATABASE, SERVER_ID, UUID, segment


class ArchiveFixture(unittest.TestCase):
    def setUp(self):
        base = os.environ.get("REPRO_OFFSITE_TEST_ROOT", str(Path.home()))
        temporary = tempfile.TemporaryDirectory(dir=base, prefix="repro-checkpoint-fixture-")
        self.addCleanup(temporary.cleanup)
        self.addCleanup(os.umask, os.umask(0o077))
        self.root = Path(temporary.name)
        self.release = "a" * 40
        self.crypt = "fixture-crypt"
        self.base = "binlog.000041"
        self.now = dt.datetime(2026, 11, 26, 11, tzinfo=dt.timezone.utc)
        self.state = self.root / "state"
        self.state.mkdir(mode=0o700)
        receipt = {
            "format_version": 1, "driver": "mysql", "created_utc": "2026-11-23T14:00:00+00:00",
            "application_booted": False, "schema_sha256": "a" * 64, "tables": 1,
            "foreign_keys": 0, "triggers": 0, "server_version": "8.4.13", "server_uuid": UUID,
            "database": DATABASE, "consistent_transaction": True, "includes_triggers_routines_events": True,
            "file": "database.sql.gz", "bytes": 17, "sha256": "b" * 64, "uncompressed_bytes": 21,
            "pitr": {"binlog_file": self.base, "binlog_position": 42, "coordinate_source": "mysqldump-source-data"},
        }
        self.base_raw = backup.encode(receipt)
        self.base_receipt = self.root / "database-backup.json"
        self.base_receipt.write_bytes(self.base_raw)
        if os.name != "posix":
            self.enterContext(patch.object(backup, "private_path"))
        self.binding = self.read_source(backup.sha256(self.base_raw))

    def read_source(self, digest):
        return checkpoint.source(self.base_receipt, digest=digest, identity=UUID, database=DATABASE,
                                 server_id=SERVER_ID, release=self.release, crypt_remote=self.crypt)

    def publication(self, first: int = 41, count: int = 2, *, backup_id=None, inventory_changes=None,
                    unexpected_artifact=False):
        with tempfile.TemporaryDirectory(dir=self.root) as directory:
            root = Path(directory)
            segments = []
            for number in range(first, first + count):
                name, following = (f"binlog.{value:06d}" for value in (number, number + 1))
                payload = segment(following)[0]
                (root / name).write_bytes(payload)
                segments.append({"file": name, "bytes": len(payload), "sha256": backup.sha256(payload)})
            inventory = {
                "schema": archive.SCHEMA, "source_uuid": UUID, "server_id": SERVER_ID,
                "sealed_at": "2026-11-24T08:03:01Z", "segments": segments, "next_file": following,
            } | (inventory_changes or {})
            inventory_raw = backup.encode(inventory)
            (root / archive.INVENTORY).write_bytes(inventory_raw)
            artifacts = [{"path": item["file"], "bytes": item["bytes"], "sha256": item["sha256"]} for item in segments]
            artifacts.append({"path": archive.INVENTORY, "bytes": len(inventory_raw), "sha256": backup.sha256(inventory_raw)})
            if unexpected_artifact:
                payload = b"unexpected unrelated artifact"
                (root / "unrelated.txt").write_bytes(payload)
                artifacts.append({"path": "unrelated.txt", "bytes": len(payload), "sha256": backup.sha256(payload)})
            manifest = {"schema": backup.INPUT_SCHEMA, "backup_id": backup_id or str(uuid.uuid4()), "scope": "database",
                        "created_at": "2026-11-24T08:03:01Z", "release": self.release,
                        "identity": UUID + ".closed-binlogs", "artifacts": artifacts}
            manifest_raw = backup.encode(manifest)
            receipt = backup.publish(manifest, manifest_raw, root, self.crypt, FixtureRemote(), root)
            receipt_raw = backup.encode(receipt)
            return {"inventory_raw": inventory_raw, "manifest_raw": manifest_raw,
                    "manifest_sha256": backup.sha256(manifest_raw), "receipt_raw": receipt_raw,
                    "receipt_sha256": backup.sha256(receipt_raw), "now": self.now}


class CheckpointEvidenceTests(ArchiveFixture):
    def test_accepts_exact_readback_receipt_even_after_snapshot_freshness_window(self):
        proof = checkpoint.review_batch(self.binding, **self.publication())
        self.assertEqual((proof["first_file"], proof["next_file"]), (self.base, "binlog.000043"))
        with self.assertRaisesRegex(backup.Refusal, "pinned recovery metadata"):
            self.read_source("0" * 64)

    def test_refuses_altered_transitive_pins_and_incomplete_publication_metadata(self):
        original = self.publication()
        changed = json.loads(original["receipt_raw"]) | {"result": "local-inputs-verified"}
        forged = backup.encode(changed)
        for changes in ({"receipt_sha256": "0" * 64}, {"manifest_sha256": "0" * 64},
                        {"inventory_raw": original["inventory_raw"] + b" "},
                        {"receipt_raw": forged, "receipt_sha256": backup.sha256(forged)}):
            with self.subTest(changes=changes.keys()), self.assertRaises(backup.Refusal):
                checkpoint.review_batch(self.binding, **(original | changes))

    def test_rejects_wrong_origin_wrong_terminal_and_extra_artifact(self):
        for options in ({"inventory_changes": {"server_id": SERVER_ID + 1}},
                        {"inventory_changes": {"source_uuid": "aaaaaaaa-2222-3333-4444-555555555555"}},
                        {"inventory_changes": {"next_file": "binlog.000044"}},
                        {"unexpected_artifact": True}):
            with self.subTest(options=options), self.assertRaises(backup.Refusal):
                checkpoint.review_batch(self.binding, **self.publication(**options))


@unittest.skipUnless(os.name == "posix", "checkpoint file locks and ownership require Linux")
class CheckpointStateTests(ArchiveFixture):
    def test_initializes_once_and_only_advances_contiguous_proof(self):
        self.assertEqual(checkpoint.initialise(self.state, self.binding)["next_file"], self.base)
        self.assertEqual(checkpoint.check(self.state, self.binding)["next_file"], self.base)
        first = self.publication()
        self.assertEqual(checkpoint.advance(self.state, self.binding, **first)["next_file"], "binlog.000043")
        path = self.state / checkpoint.STATE
        raw = path.read_bytes()
        retry = checkpoint.advance(self.state, self.binding, **first)
        self.assertEqual(retry["result"], "closed-binlog-checkpoint-already-advanced")
        self.assertEqual(path.read_bytes(), raw)
        with self.assertRaisesRegex(backup.Refusal, "does not begin"):
            checkpoint.advance(self.state, self.binding, **self.publication(first=44))
        with self.assertRaisesRegex(backup.Refusal, "identifier reused"):
            checkpoint.advance(self.state, self.binding, **self.publication(first=43, backup_id=retry["last"]["backup_id"]))
        self.assertEqual(path.read_bytes(), raw)
        self.assertEqual(checkpoint.advance(self.state, self.binding, **self.publication(first=43))["next_file"],
                         "binlog.000045")
        with self.assertRaisesRegex(backup.Refusal, "empty"):
            checkpoint.initialise(self.state, self.binding)

    def test_lost_state_is_not_silently_reset_and_source_drift_is_refused(self):
        checkpoint.initialise(self.state, self.binding)
        with self.assertRaisesRegex(backup.Refusal, "source or base pin changed"):
            checkpoint.check(self.state, self.binding | {"base_receipt_sha256": "c" * 64})
        (self.state / checkpoint.STATE).unlink()
        with self.assertRaises(FileNotFoundError):
            checkpoint.advance(self.state, self.binding, **self.publication())
        with self.assertRaisesRegex(backup.Refusal, "empty"):
            checkpoint.initialise(self.state, self.binding)

    def test_lock_conflict_and_ambiguous_atomic_write_have_safe_retry(self):
        checkpoint.initialise(self.state, self.binding)
        arguments = self.publication()
        with checkpoint.held_lock(self.state):
            with self.assertRaisesRegex(backup.Refusal, "busy"):
                checkpoint.advance(self.state, self.binding, **arguments)
        self.assertEqual(checkpoint.check(self.state, self.binding)["next_file"], self.base)
        with patch.object(backup, "sync_directory", side_effect=OSError):
            with self.assertRaises(OSError):
                checkpoint.advance(self.state, self.binding, **arguments)
        recovered = checkpoint.advance(self.state, self.binding, **arguments)
        self.assertEqual((recovered["result"], recovered["next_file"]),
                         ("closed-binlog-checkpoint-already-advanced", "binlog.000043"))
        self.assertEqual(sorted(item.name for item in self.state.iterdir()), sorted([checkpoint.LOCK, checkpoint.STATE]))


if __name__ == "__main__":
    unittest.main()
