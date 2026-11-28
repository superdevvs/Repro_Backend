"""Use the real journal and file digests to verify held initialization and crash recovery."""
import json
import os
import sys
import tempfile
import unittest
from dataclasses import asdict, replace
from pathlib import Path
from unittest.mock import Mock, patch

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE), str(HERE.parent)]
import cli
import wayback_policy as recovery
from adapter import guard
from runner import ControllerBusy, Journal, Observation, Runner, UnsafeCutover
from test_adapter import IDENTITY, example_config, example_identity, probe_reading


@unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0, "Linux root-owned synthetic records")
class HeldInitializationTests(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory(dir="/root")
        self.addCleanup(directory.cleanup)
        root = Path(directory.name)
        self.adapter = adapter = Mock()
        self.journal = adapter.journal = Journal(root / "state")
        self.pinned = adapter.pinned = replace(IDENTITY, run_id="new-run")
        adapter.policy = {"version": 1, "identity": asdict(self.pinned), "sqlite_live_config_sha256": "a"*64,
                          "mysql_live_config_sha256": "b"*64, "machine_id_sha256": guard.sha256(b"machine")}
        adapter.policy_raw = Journal.encode(adapter.policy)
        config = adapter.config = example_config()
        config["mysql"]["target_uuid"] = self.pinned.target_uuid
        adapter.paths = {key: root / key for key in config["paths"]}
        adapter.paths["policy"].write_bytes(adapter.policy_raw)
        adapter.config["paths"] = {key: str(path) for key, path in adapter.paths.items()}
        self.archive = adapter.paths["archive"] / "previous-run-20261127T012300Z"
        self.archive.mkdir(parents=True, mode=0o700)
        self.pointer = self.journal.directory / "next-run.json"
        self.marker = {"version": 1, "run_id": "new-run", "previous_run_id": "previous-run",
                       "archive": str(self.archive), "policy_sha256": guard.sha256(adapter.policy_raw)}
        self.pointer.write_bytes(Journal.encode(self.marker))
        adapter.database = root / "restored.sqlite"
        adapter.database.write_bytes(b"synthetic verified bytes")
        self.closed = {"version": 1, "gate": "closed", "server_uuid": self.pinned.target_uuid,
                       "run_id": "previous-run", "coordinate": {"file":"binlog.000001", "position":240,
                                                                "gtid_executed":""}}
        self.close_file = self.archive / "way-back.json"
        self.close_file.write_bytes(Journal.encode(self.closed))
        self.installed = {"version":1, "run_id":"previous-run", "database":str(adapter.database),
                          "server_uuid":self.pinned.target_uuid, "writers_held":True, "method":"verified-export",
                          "installed_sha256":guard.sha256(adapter.database.read_bytes()), "review_sha256":"1"*64,
                          "verification_sha256":"2"*64, "close_sha256":guard.sha256(self.close_file.read_bytes()),
                          "source_sha256":self.pinned.source_sha256, "sqlite_live_config_sha256":"a"*64}
        self.receipt = self.archive / "wayback-installed.json"
        self.receipt.write_bytes(Journal.encode(self.installed))
        self.held = Observation("sqlite", False, True, True, False, False, True)
        adapter.observe.return_value = self.held
        adapter._mysql.return_value = probe_reading(server_uuid=self.pinned.target_uuid,
                                                    database=config["mysql"]["database"])
        adapter.clock.return_value = 100
        adapter.processes.return_value = []
        adapter.identity.return_value = self.pinned
        adapter.preflight_sha256.return_value = "d"*64
        self.runner = Runner(self.journal, self.pinned, adapter, lambda: 100)

    def init(self):
        return cli.dispatch(cli.arguments(["init", "--from-held"]), self.runner, self.adapter)["state"]

    def assertHeld(self):
        self.assertTrue(self.pointer.is_file())
        self.assertIsNone(self.journal.read())
        self.assertFalse(self.adapter.paths["ingress_open"].exists())

    def test_plain_init_refuses_and_held_init_keeps_traffic_closed_through_commit(self):
        with self.assertRaises(UnsafeCutover):
            cli.dispatch(cli.arguments(["init"]), self.runner, self.adapter)
        self.assertHeld()

        def verify_fpm(*args):
            self.assertHeld()
            with self.assertRaises(ControllerBusy), self.journal.acquire():
                self.fail("initialization released control during the private FastCGI check")
        self.adapter._fastcgi_probe.side_effect = verify_fpm
        self.assertEqual("planned", self.init()["phase"])
        self.assertFalse(self.pointer.exists())
        self.assertFalse(self.adapter.paths["ingress_open"].exists())
        self.adapter.hold_deploys.assert_called_once()

    def test_bad_archive_link_cannot_start_fpm(self):
        for updates in ({"policy_sha256":"f"*64}, {"run_id":"foreign"}, {"previous_run_id":"new-run"},
                        {"archive":str(self.archive / "../foreign")}, {"version":True}):
            with self.subTest(updates=updates):
                self.pointer.write_bytes(Journal.encode({**self.marker, **updates}))
                with self.assertRaises(guard.Refused):
                    self.init()
                self.assertHeld()
        self.adapter._run.assert_not_called()

    def test_receipt_identifies_verified_file_review_and_source(self):
        for updates in ({"source_sha256":"f"*64}, {"database":"unrelated"}, {"installed_sha256":"f"*64},
                        {"review_sha256":None}, {"verification_sha256":False}, {"method":"unverified"},
                        {"writers_held":False}, {"run_id":"foreign"}, {"close_sha256":"f"*64}):
            with self.subTest(fields=tuple(updates)):
                self.receipt.write_bytes(Journal.encode({**self.installed, **updates}))
                with self.assertRaises(guard.Refused):
                    self.init()
                self.assertHeld()
        self.adapter._run.assert_not_called()

    def test_wrong_closed_coordinate_and_unverified_wal_refuse(self):
        self.close_file.write_bytes(Journal.encode({**self.closed, "coordinate":{"position":4}}))
        with self.assertRaisesRegex(guard.Refused, "gate closure"):
            self.init()
        self.close_file.write_bytes(Journal.encode(self.closed))
        for suffix in ("-wal", "-journal"):
            sidecar = Path(str(self.adapter.database) + suffix)
            sidecar.write_bytes(b"unverified transaction")
            with self.assertRaisesRegex(UnsafeCutover, "sidecar"):
                self.init()
            sidecar.unlink()
        self.assertHeld()

    def test_open_ingress_active_writers_live_mysql_or_old_proof_prevent_init(self):
        for name in ("ingress_open", "activation", "scheduler_release", "retired", "evidence"):
            marker = self.adapter.paths[name]
            marker.touch()
            with self.assertRaisesRegex(guard.Refused, "admissions"):
                self.init()
            marker.unlink()
        self.adapter.processes.return_value = [15]
        with self.assertRaisesRegex(guard.Refused, "writers"):
            self.init()
        self.adapter.processes.return_value = []
        self.adapter._mysql.return_value["gated_sessions"] = 1
        with self.assertRaisesRegex(guard.Refused, "MySQL"):
            self.init()
        self.assertHeld()

    def test_policy_change_or_sqlite_file_replacement_during_probe_refuses(self):
        self.adapter.paths["policy"].write_bytes(b"changed")
        with self.assertRaisesRegex(guard.Refused, "policy changed"):
            self.init()
        self.adapter.paths["policy"].write_bytes(self.adapter.policy_raw)

        def replace_file(*args):
            replacement = self.adapter.database.with_suffix(".other")
            replacement.write_bytes(self.adapter.database.read_bytes())
            replacement.replace(self.adapter.database)

        self.adapter._fastcgi_probe.side_effect = replace_file
        with self.assertRaisesRegex(guard.Refused, "file changed"):
            self.init()
        self.assertHeld()

    def test_failed_fpm_or_deploy_check_retains_recovery_pointer(self):
        self.adapter._fastcgi_probe.side_effect = UnsafeCutover("failed probe")
        with self.assertRaisesRegex(UnsafeCutover, "failed probe"):
            self.init()
        self.assertHeld()
        self.adapter._fastcgi_probe.side_effect = None
        self.adapter.hold_deploys.side_effect = UnsafeCutover("changed deploy fence")
        with self.assertRaisesRegex(UnsafeCutover, "deploy"):
            self.init()
        self.assertHeld()

    def test_resume_after_commit_requires_matching_private_handoff_and_no_history(self):
        with patch.object(recovery.host, "_remove", side_effect=OSError("simulated crash")):
            with self.assertRaisesRegex(OSError, "simulated crash"):
                self.init()
        planned = self.journal.read()
        self.assertEqual("planned", planned["phase"])
        self.assertTrue(self.pointer.exists())
        with self.assertRaisesRegex(UnsafeCutover, "held initialization"):
            cli.dispatch(cli.arguments(["refresh"]), self.runner, self.adapter)
        self.adapter.refresh.assert_not_called()
        self.journal.write({**planned, "history":[{"action":"hold"}]})
        with self.assertRaisesRegex(guard.Refused, "advanced"):
            self.init()
        self.journal.write(planned)
        self.assertEqual(planned, self.init())
        self.assertFalse(self.pointer.exists())
        self.assertFalse(self.adapter.paths["ingress_open"].exists())

    def test_draft_rereads_identity_and_never_installs_the_proposal(self):
        old = example_identity(self.adapter.config)
        self.adapter.paths["identity_config"].write_bytes(Journal.encode(old))
        self.adapter.paths["machine_id"].write_bytes(b"machine")
        stage = Mock(return_value={key: self.adapter.policy[key] for key in
                                  ("sqlite_live_config_sha256", "mysql_live_config_sha256")})
        with patch.object(recovery.identity_probe, "measure", return_value=self.pinned) as measure:
            proposal = recovery.draft(self.adapter.config, self.pinned.run_id, stage)
            self.assertEqual(self.adapter.policy, proposal["policy"])
            self.assertEqual({**old,"run_id":self.pinned.run_id}, proposal["identity"])
            self.assertEqual(2, measure.call_count)
            measure.side_effect = [self.pinned, replace(self.pinned, artifact_sha256="e"*64)]
            with self.assertRaisesRegex(guard.Refused, "changed"):
                recovery.draft(self.adapter.config, self.pinned.run_id, stage)
            with self.assertRaisesRegex(guard.Refused, "new run"):
                recovery.draft(self.adapter.config, old["run_id"], stage)
        self.assertEqual(Journal.encode(old), self.adapter.paths["identity_config"].read_bytes())
        self.assertEqual(self.adapter.policy_raw, self.adapter.paths["policy"].read_bytes())
