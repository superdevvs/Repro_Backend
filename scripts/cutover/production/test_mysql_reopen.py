"""C03/C05 operator tests on the socket-free Linux host fixture."""
import json
import os
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE.parent), str(HERE)]
import cli
import gate
import mysql_reopen
import worker_start
from fence import guard
from runner import UnsafeCutover
from test_adapter import probe_reading
from test_adapter_mysql import PrepareHost


@unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0, "Private root-owned Linux test host")
class MysqlReopenTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture = unittest.TestCase()
        cls.addClassCleanup(cls.fixture.doCleanups)
        cls.host = PrepareHost(cls.fixture, mode="cutover")
        cls.host.freeze()
        cls.host.assert_prepared(cls.host.prepare())
        cls.journal, cls.adapter, cls.runner = cls.host.host()

        def open_gate(config, *, deadline):
            cls.host.answer(probe_reading("open", server_uuid=cls.host.target))
            return {"before": "closed", "granted": True, "killed": [], "kill_failed": [], "stragglers": []}

        with patch.object(gate, "open_gate", side_effect=open_gate):
            cls.runner.perform("promote", cls.runner.approval_request("promote"))
        cls.proof = cls.host.path("evidence").read_bytes()
        cls.original_boot = cls.host.boot.read_bytes()
        cls.sqlite = cls.host.path("wrappers").read_bytes()
        cls.mysql = cls.sqlite.replace(b'"engine": "sqlite"', b'"engine": "mysql"')
        cls.host.path("wrappers_sqlite").write_bytes(cls.sqlite)
        cls.host.path("wrappers_mysql").write_bytes(cls.mysql)
        cls.adapter.config["commands"].update(
            smoke_write=[*mysql_reopen.ADMIT_COMMAND, "cutover-smoke"], telnyx_replay=[*mysql_reopen.ADMIT_COMMAND, "telnyx-replay"])
        cls.host.php(token_accounts=["repro_token_check", "repro_app"])

    def setUp(self):
        self.events = []
        self.failure = None
        self.reset_host()
        p = self.host.path
        self.enterContext(patch.multiple(guard, STATE=self.journal.directory, POLICY=p("policy"), EVIDENCE=p("evidence"),
                               ACTIVATION=p("activation"), MACHINE_ID=p("machine_id"), BOOT_ID=self.host.boot,
                               _require_root_installation=lambda: None))
        self.enterContext(patch.object(mysql_reopen.fpm_drain, "read_status", side_effect=lambda *_: self.step("status") or SimpleNamespace(pool="www")))
        self.enterContext(patch.object(worker_start, "verify", side_effect=lambda *_: self.step("workers fresh")))
        import mysql_reopen_smoke

        self.enterContext(patch.object(mysql_reopen_smoke, "run", side_effect=self.smoke))
        self.enterContext(patch.object(self.adapter, "_run", side_effect=self.run_command(self.adapter._run)))
        self.enterContext(patch.object(self.adapter, "_fastcgi_probe", side_effect=self.fpm(self.adapter._fastcgi_probe)))
        self.enterContext(patch.object(mysql_reopen, "_write_atomic", side_effect=self.publish(mysql_reopen._write_atomic)))
        self.enterContext(patch.object(mysql_reopen, "token", side_effect=self.token(mysql_reopen.token)))

    def reset_host(self):
        self.host.boot.write_bytes(self.original_boot)
        self.host.answer(probe_reading("open", server_uuid=self.host.target))
        self.host.path("wrappers").write_bytes(self.sqlite)
        self.host.path("evidence").write_bytes(self.proof)
        for name in ("ingress_open", "scheduler_release", "activated", "activation"):
            self.host.path(name).unlink(missing_ok=True)
        for path in map(Path, self.adapter.config["studio_pause_files"]):
            path.parent.mkdir(parents=True, exist_ok=True)
            path.touch()
        self.events.clear()
        self.failure = None

    def step(self, name):
        self.events.append(name)
        if name == self.failure:
            raise UnsafeCutover("injected " + name)

    def smoke(self, adapter, expected, receipt, deadline, read_gtid):
        self.assertEqual(self.mysql, self.host.path("wrappers").read_bytes())
        self.assertFalse(self.host.path("ingress_open").exists())
        self.assertFalse(self.host.path("scheduler_release").exists())
        guard.check("queue-worker", "mysql")
        self.step("smoke")
        self.host.answer(probe_reading("open", server_uuid=self.host.target, business_activity=True))
        return {}

    def token(self, actual):
        def check(adapter, deadline):
            self.step("token")
            return actual(adapter, deadline)
        return check

    def fpm(self, actual):
        def probe(expected, deadline):
            self.step("fastcgi")
            self.assertFalse(self.host.path("ingress_open").exists())
            return actual(expected, deadline)
        return probe

    def run_command(self, actual):
        commands = self.adapter.config["commands"]

        def run(argv, *args, **kwargs):
            if argv == commands["telnyx_replay"]:
                opened = self.host.path("ingress_open").exists()
                self.assertFalse(self.host.path("scheduler_release").exists())
                self.assertTrue(all(not Path(p).exists() for p in self.adapter.config["studio_pause_files"]))
                self.step("replay2" if opened else "replay1")
                return ""
            if argv[:len(commands["queue_resume"])] == commands["queue_resume"]:
                self.step("resume")
            elif argv in commands["start_workers"]:
                self.step("start")
            return actual(argv, *args, **kwargs)
        return run

    def publish(self, actual):
        def write(path, record, mode):
            for key in ("ingress_open", "activated", "activation", "scheduler_release"):
                if path == self.host.path(key):
                    self.step(key)
            return actual(path, record, mode)
        return write

    def test_mysql_reopen_order_of_effects_and_durable_activation(self):
        state = cli.dispatch(cli.arguments(["reopen"]), self.runner, self.adapter)
        self.assertEqual("mysql_authoritative", state["state"]["phase"])
        self.assertEqual(["status", "token", "smoke", "replay1", "resume", "resume", "start", "workers fresh",
                          "fastcgi", "ingress_open", "replay2", "activated", "activation", "scheduler_release"], self.events)
        self.assertTrue(self.adapter.observe().mysql_live())
        self.assertTrue(self.host.path("deploy_marker").exists())
        self.assertEqual(self.host.target, self.host.record("activated")["server_uuid"])
        self.assertEqual(self.host.record("activated")["receipt_sha256"], guard.sha256(self.host.path("receipt").read_bytes()))
        guard.verify(self.adapter.policy_raw, self.journal.path.read_bytes(), self.host.path("evidence").read_bytes(),
                     machine_sha=self.adapter._machine_sha(), boot_id=self.adapter.boot_id(), action="deploy",
                     expected_engine="mysql", activation_raw=self.host.path("activation").read_bytes())

    def test_failure_at_each_step_closes_again_with_sqlite_wrappers(self):
        for fail in ("status", "token", "smoke", "replay1", "resume", "start", "workers fresh", "fastcgi",
                     "ingress_open", "replay2", "activated", "activation", "scheduler_release"):
            with self.subTest(step=fail):
                self.reset_host()
                self.failure = fail
                with self.assertRaisesRegex(UnsafeCutover, "injected " + fail):
                    self.adapter.reopen_mysql()
                self.assertTrue(self.adapter.observe().mysql_held())
                self.assertEqual("mysql_authoritative", self.runner.perform("tick")["phase"])
                self.assertTrue(self.host.path("deploy_marker").exists())
                for name in ("activation", "scheduler_release", "ingress_open", "evidence"):
                    self.assertFalse(self.host.path(name).exists(), name)
                self.assertEqual(self.sqlite, self.host.path("wrappers").read_bytes())

    def test_refresh_cannot_publish_mysql_authority_with_mysql_wrappers_while_held(self):
        path = self.host.path("wrappers_sqlite")
        path.write_bytes(self.mysql)
        try:
            with self.assertRaisesRegex(UnsafeCutover, "admits MySQL"):
                self.adapter.refresh()
            self.assertFalse(self.host.path("evidence").exists())
            self.assertFalse(self.host.path("scheduler_release").exists())
        finally:
            path.write_bytes(self.sqlite)

    def test_stale_mysql_phase_hint_cannot_change_another_phase(self):
        committed = self.journal.read()
        before = self.host.path("evidence").read_bytes()
        try:
            self.journal.write({**committed, "phase": "recovery_required"})
            with patch.object(self.runner, "status", return_value=committed), self.assertRaises(guard.Refused):
                cli.dispatch(cli.arguments(["reopen"]), self.runner, self.adapter)
            self.assertEqual(before, self.host.path("evidence").read_bytes())
            self.assertFalse(self.host.path("ingress_open").exists())
            self.assertEqual([], self.events)
        finally:
            self.journal.write(committed)

    def test_changed_wrapper_or_non_guarded_smoke_refuses_before_write(self):
        mysql = json.loads(self.mysql)
        mysql["profiles"]["studio"]["umask"] = "0000"
        path = self.host.path("wrappers_mysql")
        path.write_text(json.dumps(mysql), encoding="utf-8")
        try:
            with self.assertRaisesRegex(UnsafeCutover, "differ beyond"):
                self.adapter.reopen_mysql()
        finally:
            path.write_bytes(self.mysql)
        self.assertNotIn("smoke", self.events)
        self.reset_host()
        with patch.dict(self.adapter.config["commands"], smoke_write=["/usr/bin/true"]):
            with self.assertRaisesRegex(UnsafeCutover, "approved wrapper"):
                self.adapter.reopen_mysql()
        self.assertNotIn("smoke", self.events)

    def test_reboot_and_failed_reopen_refresh_before_retry(self):
        self.adapter.reopen_mysql()
        self.host.boot.write_text("d6eb9d2f-294a-4280-b6e4-8d48e4d714aa", encoding="ascii")
        for name in ("ingress_open", "scheduler_release", "activation", "evidence"):
            self.host.path(name).unlink(missing_ok=True)
        committed = self.journal.path.read_bytes()
        self.assertEqual("mysql_authoritative", self.runner.perform("tick")["phase"])
        self.assertEqual(committed, self.journal.path.read_bytes())
        self.adapter.refresh()
        self.assertEqual(self.sqlite, self.host.path("wrappers").read_bytes())
        self.assertFalse(self.host.path("scheduler_release").exists())
        self.assertEqual(self.adapter.boot_id(), self.host.record("evidence")["boot_id"])
        self.failure = "replay1"
        with self.assertRaisesRegex(UnsafeCutover, "injected replay1"):
            self.adapter.reopen_mysql()
        self.failure = None
        self.adapter.refresh()
        self.adapter.reopen_mysql(hold_scheduler=True)
        self.assertFalse(self.host.path("scheduler_release").exists())
        self.adapter.release_scheduler()
        self.assertEqual("mysql", self.host.record("scheduler_release")["engine"])
        with self.assertRaisesRegex(UnsafeCutover, "Ingress is open"):
            self.adapter.refresh()
        self.adapter.rehold()
        self.host.answer(probe_reading("closed", server_uuid=self.host.target, business_activity=True))
        with self.assertRaisesRegex(guard.Refused, "sealed single-engine"):
            self.adapter.refresh()
        self.assertFalse(self.host.path("evidence").exists())
