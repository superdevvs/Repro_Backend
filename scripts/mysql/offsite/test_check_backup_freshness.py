import datetime as dt
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import check_backup_freshness as freshness
import publish_backup as backup
from test_publish_backup import FixtureRemote


class BackupFreshnessTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        self.created_at = dt.datetime(2026, 11, 22, 10, tzinfo=dt.timezone.utc)
        self.release = "a" * 40
        self.identity = "fixture-instance"
        self.backup_id = "7ca011d3-b197-48c6-99d0-af07d99d3c08"
        data = b"closed database fixture"
        (self.root / "base.sql.zst").write_bytes(data)
        manifest = {
            "schema": backup.INPUT_SCHEMA, "backup_id": self.backup_id, "created_at": "2026-11-22T10:00:00Z",
            "scope": "database", "release": self.release, "identity": self.identity,
            "artifacts": [{"path": "base.sql.zst", "bytes": len(data), "sha256": backup.sha256(data)}],
        }
        self.manifest = backup.encode(manifest)
        with patch.object(backup, "local_hash", return_value=(len(data), backup.sha256(data))):
            self.receipt = backup.publish(manifest, self.manifest, self.root, "encrypted", FixtureRemote(), self.root)

    def review(self, *, age=0, changed_receipt=None, manifest=None, **overrides):
        receipt = backup.encode(self.receipt | (changed_receipt or {}))
        parameters = {
            "receipt_sha256": backup.sha256(receipt), "scope": "database",
            "release": self.release, "identity": self.identity, "crypt_remote": "encrypted",
            "now": self.created_at + dt.timedelta(seconds=age),
        }
        return freshness.check(receipt, self.manifest if manifest is None else manifest, **(parameters | overrides))

    def test_snapshot_is_stale_at_the_limit_even_with_a_completed_publication(self):
        for offset, expected in ((-1, "fresh"), (0, "stale"), (1, "stale")):
            with self.subTest(offset=offset):
                age = freshness.DEFAULT_MAX_AGE_SECONDS + offset
                result = self.review(age=age)
                self.assertEqual(result["result"], f"backup-publication-{expected}")
                self.assertEqual(result["snapshot_age_seconds"], age)
                self.assertIs(result["remote_accessed"], False)

    def test_refuses_future_snapshot_and_invalid_review_clock_or_limit(self):
        for overrides in ({"age": -1}, {"now": self.created_at.replace(tzinfo=None)}, {"max_age_seconds": 0}):
            with self.subTest(overrides=overrides), self.assertRaises(backup.Refusal):
                self.review(**overrides)

    def test_refuses_tampered_receipt_or_manifest(self):
        original_pin = backup.sha256(backup.encode(self.receipt))
        with self.assertRaisesRegex(backup.Refusal, "receipt hash"):
            self.review(changed_receipt={"artifact_count": 99}, receipt_sha256=original_pin)
        with self.assertRaisesRegex(backup.Refusal, "manifest hash"):
            self.review(manifest=self.manifest.replace(b"22T10", b"23T10"))

    def test_refuses_other_release_instance_scope_or_remote(self):
        for overrides in ({"release": "b" * 40}, {"identity": "other-fixture"}, {"scope": "application"},
                          {"crypt_remote": "other"}):
            with self.subTest(overrides=overrides), self.assertRaises(backup.Refusal):
                self.review(**overrides)

    def test_refuses_attempts_or_inconsistent_publication_claims(self):
        for change in (
            {"result": "local-inputs-verified"}, {"artifact_count": True}, {"artifact_bytes": 0},
            {"completion_bytes": 0}, {"completion_sha256": "wrong"}, {"restore_verified": True},
            {"retention_verified": True}, {"scope": "application"},
        ):
            with self.subTest(change=change), self.assertRaises(backup.Refusal):
                self.review(changed_receipt=change)

    def test_cli_returns_nonzero_for_stale_and_unreadable_evidence(self):
        receipt = backup.encode(self.receipt)
        arguments = [
            "check_backup_freshness.py", "--receipt", "receipt.json", "--manifest", "manifest.json",
            "--receipt-sha256", backup.sha256(receipt), "--scope", "database",
            "--expected-release", self.release, "--expected-identity", self.identity,
            "--crypt-remote", "encrypted", "--max-age-seconds", "1",
        ]
        with patch("sys.argv", arguments), patch("builtins.print") as printed:
            with patch.object(freshness, "read_private", side_effect=[receipt, self.manifest]):
                self.assertEqual(freshness.main(), 1)
                self.assertIn("backup-publication-stale", printed.call_args.args[0])
            with patch.object(freshness, "read_private", side_effect=FileNotFoundError):
                self.assertEqual(freshness.main(), 1)
                self.assertIn("backup-publication-invalid", printed.call_args.args[0])


if __name__ == "__main__":
    unittest.main()
