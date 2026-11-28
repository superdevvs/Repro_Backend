"""Synthetic retirement, crash recovery, shared admissions and next-policy installation."""

import json
import os
import stat
import sys
import tempfile
import unittest
from dataclasses import asdict, replace
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE), str(HERE.parent)]
import retire
from adapter import guard
from runner import Journal, Runner, UnsafeCutover
from test_adapter import IDENTITY, example_config


@unittest.skipUnless(sys.platform.startswith("linux") and os.geteuid() == 0, "Linux private owner fixtures")
class RetirementTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory(dir="/root")
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        self.journal = Journal(self.root / "state")
        self.run = IDENTITY.run_id
        self.host = Mock()
        self.host.config = example_config()
        self.host.journal = self.journal
        self.host.database = self.root / "database.sqlite"
        self.host.config["sqlite"] = str(self.host.database)
        self.host.paths = {k: self.root / k for k in self.host.config["paths"]}
        self.host.paths.update(state=self.journal.directory)
        for k in (*retire.KEEP_NAMES, "activated"):
            self.host.paths[k] = self.journal.directory / (k + ".json")
        self.host.paths["machine_id"].write_bytes(b"fixture-machine")
        self.host.locks = [self.root / "backend.lock", self.root / "frontend.lock"]
        self.host.owner = (0, 0)
        self.json("deploy_marker", {"version": 1, "run_id": self.run})
        self.host.policy = {"version": 1, "identity": asdict(IDENTITY),
                            "machine_id_sha256": guard.sha256(b"fixture-machine"),
                            "sqlite_live_config_sha256": "a" * 64, "mysql_live_config_sha256": "b" * 64}
        self.host.config["mysql"]["target_uuid"] = IDENTITY.target_uuid
        self.host._live_config.return_value = SimpleNamespace(source="cache", engine="sqlite", live_config_sha256="a"*64)
        self.state = {"version": 1, "identity": asdict(IDENTITY), "phase": "planned", "pending": None,
                      "generation": 1, "writes_possible": False, "history": [], "started_at": None}
        self.journal.write(self.state)
        self.withdraw = self.enterContext(patch.object(retire.host.deploy_marker, "withdraw"))
        self.withdraw.side_effect = lambda *args, **kwargs: self.read()

    def read(self):
        return retire.read_retired(self.host.paths["retired"], self.journal.directory,
                                   archive_root=self.host.paths["archive"])

    def json(self, key, obj):
        self.host.paths[key].write_text(json.dumps(obj))

    def pair(self):
        policy = {**self.host.policy, "identity": {**asdict(IDENTITY), "run_id": "next-run"}}
        ident = {"version": 1, "run_id": "next-run", "app_root": self.host.config["app_root"],
                 "sqlite_path": str(self.host.database), "mysql_datadir": self.host.config["mysql"]["datadir"],
                 "env_denylist": retire.host.env_denylist(self.host.config)}
        p, i = self.root / "next-policy", self.root / "next-identity"
        p.write_text(json.dumps(policy))
        i.write_text(json.dumps(ident))
        return p, i

    def mysql(self):
        self.state.update(phase="mysql_authoritative", started_at=1, writes_possible=True,
                          history=[{"action": "promote", "phase": "mysql_authoritative",
                                    "after": {"engine": "mysql", "admissions_open": False, "workers_stopped": True,
                                              "deployment_fenced": True, "mysql_business_activity": False,
                                              "sqlite_writable": False, "mysql_writable": True}}])
        self.journal.write(self.state)
        self.host._live_config.return_value = SimpleNamespace(source="cache", engine="mysql", live_config_sha256="b"*64)
        self.host.database.write_bytes(b"disposable fixture")
        self.host.database.chmod(0o440)
        sha = guard.sha256(self.host.database.read_bytes())
        self.json("sealed_hash", {"version": 1, "run_id": self.run, "hold_id": "fixture", "started_at": 1,
                                  "database": str(self.host.database), "sha256": sha})
        self.json("sqlite_seal_record", {"version": 1, "database": str(self.host.database),
                                         "modes": {str(self.host.database): 0o660}})
        self.json("switch_record", {"version": 1, "run_id": self.run, "env_sha256": "c"*64, "switch_at": 3,
                                    "values": dict.fromkeys(retire.host.switch_keys(self.host.config))})
        self.json("receipt", {"version": 1, "run_id": self.run, "database": self.host.config["mysql"]["database"],
                              "server_uuid": IDENTITY.target_uuid, "backup": {"file": "binlog.001", "position": 4,
                              "gtid_executed": ""}, "armed_from": {"file": "binlog.001", "position": 4}})
        self.json("activated", {"version": 1, "run_id": self.run, "database": self.host.config["mysql"]["database"],
                                "server_uuid": IDENTITY.target_uuid,
                                "receipt_sha256": guard.sha256(self.host.paths["receipt"].read_bytes())})

    def test_archive_is_byte_identical_and_locks_stay(self):
        original = self.journal.path.read_bytes()
        directory = self.journal.directory / "issued"
        directory.mkdir()
        (directory / "approval").write_bytes(b'{"fixture": true}\n')
        answer = retire.retire(self.host)
        archive = Path(answer["archive"])
        self.assertEqual(original, (archive / "state.json").read_bytes())
        self.assertEqual(b'{"fixture": true}\n', (archive / "issued/approval").read_bytes())
        self.assertEqual(retire.LOCKS, {p.name for p in self.journal.directory.iterdir()})
        self.assertEqual(0o700, stat.S_IMODE(archive.stat().st_mode))
        self.assertEqual(0o644, stat.S_IMODE(self.host.paths["retired"].stat().st_mode))
        self.withdraw.assert_called_once()

    def test_keep_set_for_mysql_runs_holds_no_secret(self):
        self.mysql()
        path = self.host.paths["switch_record"]
        vals = json.loads(path.read_bytes())
        vals["values"]["DB_PASSWORD"] = "synthetic-secret"
        path.write_text(json.dumps(vals))
        with self.assertRaisesRegex(guard.Refused, "secret"):
            retire.retire(self.host)
        self.assertFalse((self.journal.directory / retire.PENDING).exists())
        self.assertFalse(self.host.paths["keep"].exists())
        self.assertTrue(self.journal.path.exists())

    def test_frozen_file_moved_with_placeholder_directory(self):
        self.mysql()
        db = self.host.database
        expected = {self.host.paths[k].name: self.host.paths[k].read_bytes() for k in retire.KEEP_NAMES}
        retire.retire(self.host)
        retained = self.host.paths["keep"] / self.run
        self.assertEqual(expected, {p.name:p.read_bytes() for p in retained.iterdir()})
        self.assertEqual(b"disposable fixture", (db.parent / ("frozen-" + self.run) / db.name).read_bytes())
        self.assertTrue(db.is_dir())
        self.assertEqual(0o700, stat.S_IMODE(db.stat().st_mode))
        self.assertEqual("mysql", self.read()["engine"])

    def test_retired_mode_valid_only_without_state_json(self):
        retire.retire(self.host)
        self.assertEqual("sqlite", self.read()["engine"])
        self.journal.path.write_text("null")
        with self.assertRaises(guard.Refused):
            self.read()
        self.journal.path.unlink()
        (self.journal.directory / retire.PENDING).write_text("null")
        with self.assertRaises(guard.Refused):
            self.read()

    def test_retired_admit_takes_shared_lease_and_checks_engine(self):
        import fcntl
        self.enterContext(patch.object(guard, "_require_root_installation"))
        retire.retire(self.host)
        args = dict(marker=self.host.paths["retired"], state_dir=self.journal.directory,
                    archive_root=self.host.paths["archive"])
        with self.assertRaises(guard.Refused), retire.retired_lease("mysql", **args):
            self.fail("wrong engine admitted")
        with retire.retired_lease("sqlite", **args) as held:
            self.assertEqual((self.journal.directory / retire.WRITER_LOCK).stat().st_ino, os.fstat(held).st_ino)
            with (self.journal.directory / retire.WRITER_LOCK).open("rb") as second, self.assertRaises(BlockingIOError):
                fcntl.flock(second, fcntl.LOCK_EX | fcntl.LOCK_NB)

    def test_crash_after_state_removal_refuses_admission_and_resumes(self):
        regular_remove = retire.host._remove
        def crash(path):
            regular_remove(path)
            if path == self.journal.path:
                raise InterruptedError("synthetic crash")
        with patch.object(retire.host, "_remove", crash), self.assertRaises(InterruptedError):
            retire.retire(self.host)
        with self.assertRaises(guard.Refused):
            self.read()
        with self.assertRaises(UnsafeCutover), self.journal.acquire():
            self.fail("a competing controller was admitted")
        self.withdraw.assert_not_called()
        retire.retire(self.host)
        self.withdraw.assert_called_once()
        self.assertEqual("sqlite", self.read()["engine"])

    def test_next_policy_and_identity_must_agree(self):
        p, i = self.pair()
        original = i.read_bytes()
        obj = json.loads(original)
        i.write_text(json.dumps({**obj, "run_id": "different"}))
        with self.assertRaises(guard.Refused):
            retire.retire(self.host, p, i)
        i.write_bytes(original)
        retire.retire(self.host, p, i)
        self.assertEqual(p.read_bytes(), self.host.paths["policy"].read_bytes())
        self.assertEqual(i.read_bytes(), self.host.paths["identity_config"].read_bytes())
        self.assertTrue(retire.host.deploy_marker.published(self.host.paths["deploy_marker"], "next-run"))
        self.assertFalse(self.host.paths["retired"].exists())
        self.assertFalse((self.journal.directory / retire.NEXT_RUN).exists())
        self.withdraw.assert_not_called()

    def test_recovery_handoff_pointer_survives_retirement_retry(self):
        self.mysql()
        self.host._live_config.return_value = SimpleNamespace(source="cache", engine="sqlite", live_config_sha256="a"*64)
        p, i = self.pair()
        self.state.update(phase="recovery_required", writes_possible=True)
        self.journal.write(self.state)
        close = {"version": 1, "run_id": self.run, "server_uuid": IDENTITY.target_uuid, "gate": "closed",
                 "coordinate": {"file": "binlog.000003", "position": 4, "gtid_executed": ""}}
        self.journal.write_record("way-back.json", close)
        closed = SimpleNamespace(admissions_open=False, workers_stopped=True, deployment_fenced=True)
        reading = {"reachable": True, "gate": "closed", "writable": False, "gated_sessions": 0,
                   "server_uuid": IDENTITY.target_uuid, "database": self.host.config["mysql"]["database"]}
        self.host._observed.return_value = closed, reading, self.host._live_config.return_value
        with self.assertRaises(FileNotFoundError):
            retire.retire(self.host, p, i)
        installed = {"version": 1, "run_id": self.run, "server_uuid": IDENTITY.target_uuid, "method": "verified-export",
                     "database": str(self.host.database), "writers_held": True, "source_sha256": "7" * 64,
                     "installed_sha256": guard.sha256(self.host.database.read_bytes()), "review_sha256": "2" * 64,
                     "verification_sha256": "3" * 64, "sqlite_live_config_sha256": self.host.policy["sqlite_live_config_sha256"],
                     "close_sha256": guard.sha256((self.journal.directory / "way-back.json").read_bytes())}
        self.journal.write_record("wayback-installed.json", installed)
        self.host.identity.return_value.source_sha256 = "9" * 64
        with self.assertRaisesRegex(guard.Refused, "installation receipt"):
            retire.retire(self.host, p, i)
        self.host.identity.return_value.source_sha256 = installed["source_sha256"]
        pointer = self.journal.directory / retire.NEXT_RUN
        original_write = retire.host._write_atomic
        def crash(path, obj, mode):
            original_write(path, obj, mode)
            if path == pointer:
                raise InterruptedError("crash after writing recovery pointer")
        with patch.object(retire.host, "_write_atomic", crash), self.assertRaises(InterruptedError):
            retire.retire(self.host, p, i)
        with self.assertRaises(UnsafeCutover), self.journal.acquire():
            self.fail("init proceeded before retirement completed")
        original = pointer.read_bytes()
        answer = retire.retire(self.host)
        self.assertEqual(original, pointer.read_bytes())
        self.assertEqual({"version": 1, "run_id": "next-run", "previous_run_id": self.run,
                          "archive": answer["archive"], "policy_sha256": guard.sha256(p.read_bytes())}, json.loads(original))
        self.assertEqual(0o600, stat.S_IMODE(pointer.stat().st_mode))
        self.assertEqual(close, json.loads((Path(answer["archive"]) / "way-back.json").read_bytes()))
        self.assertEqual(installed, json.loads((Path(answer["archive"]) / "wayback-installed.json").read_bytes()))
        self.assertEqual(4, len(list((self.host.paths["keep"] / self.run).iterdir())))
        self.assertFalse(self.host.paths["retired"].exists())

    def test_boot_unit_conditions(self):
        unit = (HERE / "systemd/repro-cutover-retired-ingress.service").read_text()
        self.assertIn("Before=nginx.service", unit)
        self.assertIn("ConditionPathExists=!/var/lib/repro-cutover-fence/state.json", unit)
        self.assertIn("ConditionPathExists=/etc/repro-cutover-fence/retired.json", unit)
        self.assertIn("cli.py retired-open", unit)

    def test_init_from_retired_deletes_retired_json_only_after_commit(self):
        from test_runner import FaultAdapter
        retire.retire(self.host)
        pinned = replace(IDENTITY, run_id="next-id")
        self.host.pinned = pinned
        runner = Runner(self.journal, pinned, FaultAdapter(self.journal, pinned))
        def crash_after_commit(path):
            self.assertEqual("planned", self.journal.read()["phase"])
            raise InterruptedError("power loss")
        with patch.object(retire.host, "_remove", crash_after_commit), self.assertRaises(InterruptedError):
            retire.initialize(runner, self.host)
        self.assertTrue(self.host.paths["retired"].exists())
        with self.assertRaises(guard.Refused):
            self.read()
        self.assertEqual("planned", retire.initialize(runner, self.host)["phase"])
        self.assertFalse(self.host.paths["retired"].exists())

    def test_policy_pair_resumes_after_first_file_and_keeps_deploys_held(self):
        p, i = self.pair()
        original_write = retire.host._write_raw_atomic
        def crash(path, raw, mode):
            original_write(path, raw, mode)
            if path == self.host.paths["identity_config"]:
                raise InterruptedError("injected after installed identity")
        with patch.object(retire.host, "_write_raw_atomic", crash), self.assertRaises(InterruptedError):
            retire.retire(self.host, p, i)
        p.unlink()
        i.unlink()
        self.assertFalse(self.host.paths["retired"].exists())
        self.assertTrue(self.host.paths["deploy_marker"].is_file())
        self.assertTrue(retire.retire(self.host)["next_policy_installed"])
        self.assertTrue(retire.host.deploy_marker.published(self.host.paths["deploy_marker"], "next-run"))

    def test_retired_open_requires_the_measured_route(self):
        retire.retire(self.host)
        self.assertEqual("sqlite", retire.retired_open(self.host)["engine"])
        self.assertTrue(self.host.paths["ingress_open"].exists())
        self.host._live_config.return_value.live_config_sha256 = "0"*64
        with self.assertRaisesRegex(guard.Refused, "route"):
            retire.retired_open(self.host)
        self.assertFalse(self.host.paths["ingress_open"].exists())

    def test_archive_includes_empty_files_and_records_larger_than_the_guard_limit(self):
        (self.journal.directory / "empty").touch()
        (self.journal.directory / "large").write_bytes(b"x" * (guard.MAX_RECORD_BYTES + 1))
        archive = Path(retire.retire(self.host)["archive"])
        self.assertEqual(b"", (archive / "empty").read_bytes())
        self.assertEqual(guard.MAX_RECORD_BYTES + 1, (archive / "large").stat().st_size)
