"""Exercise the order and failure behavior of the closed-log archive job."""
import datetime as dt
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import archive_binlogs as job
import collect_closed_binlogs as collector
from test_advance_binlog_checkpoint import ArchiveFixture


@unittest.skipUnless(os.name == "posix", "archive job uses Linux filesystem paths and locks")
class ArchiveJobTests(ArchiveFixture):
    def setUp(self):
        super().setUp()
        job.checkpoint.initialise(self.state, self.binding)
        self.spool = self.root / "spool"
        self.spool.mkdir()
        self.workdir = self.root / "publish-state"
        self.workdir.mkdir()
        self.plan = {
            "collector_plan": str(self.root / "collector.json"), "collector_plan_sha256": "1" * 64,
            "publish_state_dir": str(self.workdir), "rclone_config": str(self.root / "rclone.conf"),
            "rclone": "/usr/bin/rclone", "destination": "store:fixture/archive",
            "run_timeout_seconds": 120, "transfer_timeout_seconds": 60,
        }
        self.enterContext(patch.object(job.scheduler, "trusted_file"))
        now = self.now

        class FixtureClock(dt.datetime):
            @classmethod
            def now(cls, tz=None):
                return now.astimezone(tz)

        self.enterContext(patch.object(job.dt, "datetime", FixtureClock))
        self.enterContext(patch.object(collector, "read_plan", return_value={
            "base_receipt": str(self.base_receipt), "base_receipt_sha256": job.backup.sha256(self.base_raw),
            "identity": self.binding["source_uuid"], "server_id": self.binding["server_id"],
            "database": self.binding["database"], "release": self.binding["release"],
            "crypt_remote": self.binding["crypt_remote"], "spool_dir": str(self.spool),
            "checkpoint_dir": str(self.state),
        }))

    def simulated_child(self, proof, *, upload_succeeds=True, change_input=False):
        def run(argv, _env, _deadline, *, stdout=None):
            self.assertEqual(job.checkpoint.check(self.state, self.binding)["next_file"], self.base)
            if Path(argv[3]).name == "collect_closed_binlogs.py":
                if proof is None:
                    stdout.write(job.backup.encode({
                        "result": "closed-binlog-collection-idle", "first_file": self.base, "active_file": self.base,
                    }))
                    return
                batch = Path(argv[-1])
                batch.mkdir()
                manifest = json.loads(proof["manifest_raw"])
                (batch / job.archive.OUTPUT).write_bytes(proof["manifest_raw"] + (b" " if change_input else b""))
                (batch / job.archive.INVENTORY).write_bytes(proof["inventory_raw"])
                stdout.write(job.backup.encode({
                    "result": "closed-mysql-binlogs-ready-for-publication", "active_file": "binlog.000043",
                    "batch_dir": str(batch), "backup_id": manifest["backup_id"], "first_file": self.base,
                    "manifest_sha256": proof["manifest_sha256"], "inventory_sha256": job.backup.sha256(proof["inventory_raw"]),
                }))
            else:
                self.assertEqual(Path(argv[3]).name, "publish_backup.py")
                self.assertEqual(argv[argv.index("--expected-destination") + 1], self.plan["destination"])
                if not upload_succeeds:
                    raise job.backup.Refusal("scheduled backup command failed")
                backup_id = json.loads(proof["manifest_raw"])["backup_id"]
                (self.workdir / (backup_id + ".receipt.json")).write_bytes(proof["receipt_raw"])
        return run

    def test_publisher_error_leaves_anchor_then_readback_advances(self):
        proof = self.publication()
        with patch.object(job.scheduler, "child", side_effect=self.simulated_child(proof, upload_succeeds=False)) as child:
            with self.assertRaisesRegex(job.backup.Refusal, "command failed"):
                job.run(self.plan)
        self.assertEqual(child.call_count, 2)
        self.assertEqual(list(self.spool.iterdir()), [])
        self.assertIsNone(job.checkpoint.check(self.state, self.binding)["last"])

        proof = self.publication()
        with patch.object(job.scheduler, "child", side_effect=self.simulated_child(proof)):
            result = job.run(self.plan)
        self.assertEqual(result["next_file"], "binlog.000043")
        self.assertTrue(result["caught_up_to_observed_active_file"])
        self.assertTrue(result["remote_accessed"])
        self.assertEqual(result["last"]["receipt_sha256"], proof["receipt_sha256"])
        self.assertEqual(list(self.spool.iterdir()), [])

    def test_idle_or_changed_manifest_makes_no_publisher_call(self):
        with patch.object(job.scheduler, "child", side_effect=self.simulated_child(None)) as child:
            result = job.run(self.plan)
        self.assertEqual(child.call_count, 1)
        self.assertEqual(result["result"], "closed-binlog-archive-idle")
        self.assertFalse(result["remote_accessed"])

        with patch.object(job.scheduler, "child", side_effect=self.simulated_child(self.publication(), change_input=True)) as child:
            with self.assertRaises(job.backup.Refusal):
                job.run(self.plan)
        self.assertEqual(child.call_count, 1)
        self.assertEqual(list(self.spool.iterdir()), [])

    def test_archive_plan_requires_exact_reviewed_bytes(self):
        with tempfile.NamedTemporaryFile(dir=self.root) as stream:
            raw = job.backup.encode(self.plan | {"version": 1})
            stream.write(raw)
            stream.flush()
            self.assertEqual(job.read_plan(Path(stream.name), job.backup.sha256(raw))["version"], 1)
            with self.assertRaisesRegex(job.backup.Refusal, "plan hash"):
                job.read_plan(Path(stream.name), "0" * 64)


if __name__ == "__main__":
    unittest.main()
