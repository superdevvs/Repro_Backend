import datetime as dt
import fcntl
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import uuid

import publish_backup as backup
import scheduled_backup as scheduled
from test_publish_backup import FixtureRemote


class ScheduledBackupTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(dir=Path.home())
        self.addCleanup(temporary.cleanup)
        self.directory = Path(temporary.name)
        self.plan = {
            "version": 1, "driver": "sqlite", "release": "a" * 40, "identity": "isolated-sqlite",
            "state_dir": str(self.directory), "app_root": "/opt/isolated-app", "php": "/usr/bin/php8.3",
            "rclone": "/opt/rclone/rclone", "rclone_config": "/etc/isolated/rclone.conf",
            "destination": "storage:fixture/acceptance", "crypt_remote": "encrypted",
            "max_age_seconds": 600, "run_timeout_seconds": 300, "transfer_timeout_seconds": 90,
            "mysql": None,
        }
        self.pin = backup.sha256(backup.encode(self.plan))
        self.clock = dt.datetime.now(dt.timezone.utc).replace(microsecond=0)

    def evidence(self, root, *, timestamp=None):
        content = b"test snapshot"
        (root / "base.sqlite").write_bytes(content)
        created = (timestamp or self.clock).strftime("%Y-%m-%dT%H:%M:%SZ")
        manifest = {
            "schema": backup.INPUT_SCHEMA, "backup_id": str(uuid.uuid4()), "created_at": created,
            "scope": "database", "release": self.plan["release"], "identity": self.plan["identity"],
            "artifacts": [{"path": "base.sqlite", "bytes": len(content), "sha256": backup.sha256(content)}],
        }
        manifest_raw = backup.encode(manifest)
        with patch.object(backup, "local_hash", return_value=(len(content), backup.sha256(content))):
            receipt = backup.publish(manifest, manifest_raw, root, self.plan["crypt_remote"], FixtureRemote(), root)
        receipt_raw = backup.encode(receipt)
        return {"receipt": receipt_raw.decode(), "receipt_sha256": backup.sha256(receipt_raw),
                "manifest": manifest_raw.decode()}

    def accepted(self, plan, batch, workdir, deadline, started, update):
        update("readback-receipt")
        evidence = self.evidence(batch)
        return evidence, scheduled.evaluate(plan, evidence, self.clock)

    def read(self):
        return scheduled.read_state(self.directory, self.pin)

    def test_success_commits_receipt_after_stage_cleanup_and_check_binds_current_plan(self):
        with patch.object(scheduled, "execute", side_effect=self.accepted):
            result = scheduled.run(self.plan, self.pin)
        self.assertEqual(result, scheduled.check(self.plan, self.pin, now=self.clock))
        self.assertEqual("verified", self.read()["status"])
        self.assertEqual([], list(self.directory.glob("stage-*")))
        with self.assertRaisesRegex(backup.Refusal, "current plan"):
            scheduled.check(self.plan, "b" * 64, now=self.clock)

    def test_failed_publication_does_not_replace_latest_verified_evidence(self):
        with patch.object(scheduled, "execute", side_effect=self.accepted):
            scheduled.run(self.plan, self.pin)
        good = self.read()["verified"]

        def failed(plan, batch, workdir, deadline, started, update):
            update("publication")
            raise backup.Refusal("credential-must-not-escape")

        with patch.object(scheduled, "execute", side_effect=failed):
            self.assertEqual({"result": "scheduled-backup-failed", "stage": "publication"},
                             scheduled.run(self.plan, self.pin))
        report = scheduled.check(self.plan, self.pin, now=self.clock)
        self.assertEqual("scheduled-backup-failed", report["result"])
        self.assertEqual("publication", report["stage"])
        self.assertEqual(good, self.read()["verified"])
        self.assertEqual([], list(self.directory.glob("stage-*")))
        self.assertNotIn("credential-must-not-escape", (self.directory / scheduled.STATE_FILE).read_text())

    def test_stale_and_invalid_receipt_cannot_report_healthy(self):
        with patch.object(scheduled, "execute", side_effect=self.accepted):
            scheduled.run(self.plan, self.pin)
        later = self.clock + dt.timedelta(seconds=self.plan["max_age_seconds"])
        self.assertEqual("backup-publication-stale", scheduled.check(self.plan, self.pin, now=later)["result"])
        record = self.read()
        receipt = json.loads(record["verified"]["receipt"])
        receipt["artifact_count"] = 0
        record["verified"]["receipt"] = backup.encode(receipt).decode()
        scheduled.write_state(self.directory, record)
        with self.assertRaisesRegex(backup.Refusal, "receipt hash"):
            scheduled.check(self.plan, self.pin, now=self.clock)

    def test_overlap_is_refused_and_abandoned_running_attempt_alerts(self):
        with patch.object(scheduled, "execute", side_effect=self.accepted):
            scheduled.run(self.plan, self.pin)
        record = self.read() | {"status": "running", "started_at": int(self.clock.timestamp())}
        scheduled.write_state(self.directory, record)
        with (self.directory / "scheduler.lock").open("rb") as held:
            fcntl.flock(held, fcntl.LOCK_EX)
            self.assertEqual("backup-publication-fresh", scheduled.check(self.plan, self.pin, now=self.clock)["result"])
            with self.assertRaises(BlockingIOError):
                scheduled.run(self.plan, self.pin)
        with self.assertRaisesRegex(backup.Refusal, "stopped without a result"):
            scheduled.check(self.plan, self.pin, now=self.clock)

    def test_unsafe_plan_cannot_bind_a_shell_or_switch_mysql_without_uuid(self):
        path = self.directory / "plan.json"
        with patch.object(scheduled, "trusted_file"):
            for change in ({"command": ["sh", "-c", "exit 0"]}, {"state_dir": "/state/../other"},
                           {"run_timeout_seconds": True}, {"driver": "mysql"}):
                path.write_bytes(backup.encode(self.plan | change))
                with self.subTest(change=change), self.assertRaises(backup.Refusal):
                    scheduled.read_plan(path)


if __name__ == "__main__":
    unittest.main()
