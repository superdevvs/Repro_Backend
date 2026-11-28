"""Operator entry points on a disposable runner journal."""
import contextlib
import io
import json
import os
import runpy
import stat
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE), str(HERE.parent)]
import cli
import test_runner
from test_adapter import probe_reading


class OperatorTests(unittest.TestCase):
    def setUp(self):
        fixture = test_runner.CutoverTest()
        fixture.setUp()
        self.addCleanup(fixture.doCleanups)
        self.ready = fixture.ready
        self.hold = fixture.hold
        self.runner = fixture.runner
        self.adapter = fixture.adapter
        self.adapter.paths = {"issued_approvals": fixture.journal.directory / "issued"}
        reading = probe_reading()
        self.adapter.config = {"commands": {"timer": ["/usr/bin/systemd-run"]},
                               "mysql": {"target_uuid": reading["server_uuid"], "database": reading["database"]}}
        self.adapter._mysql = Mock(return_value=reading)
        self.adapter._execute = Mock(return_value=(5, ""))
        self.adapter._run = Mock()

    def dispatch(self, *args):
        return cli.dispatch(cli.arguments(args), self.runner, self.adapter)

    @unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0, "approvals are root-owned")
    def test_approval_template_promote_writes_issued_file_and_arms_timer(self):
        self.ready()
        approval = self.dispatch("approval-template", "--phase", "promote")
        path = self.adapter.paths["issued_approvals"] / "promote.json"
        self.assertEqual(approval, json.loads(path.read_bytes()))
        self.assertEqual(0o600, stat.S_IMODE(path.stat().st_mode))
        self.assertEqual(0o700, stat.S_IMODE(path.parent.stat().st_mode))
        command = self.adapter._run.call_args.args[0]
        self.assertEqual(["abort", "--if-challenge", approval["challenge"], "--if-phase", "ready"], command[-5:])
        self.assertIn("--on-active=300s", command)
        self.adapter._run.side_effect = cli.UnsafeCutover("timer unavailable")
        with self.assertRaisesRegex(cli.UnsafeCutover, "timer unavailable"):
            self.dispatch("approval-template", "--phase", "promote")
        self.assertFalse(path.exists())

    @unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0, "approvals are root-owned")
    def test_issued_hold_approval_is_consumed_by_the_operator_entrypoint(self):
        self.dispatch("approval-template", "--phase", "hold")
        with patch.object(cli.guard, "read_protected", side_effect=lambda path: path.read_bytes()):
            # The tempfile fixture lives under /tmp. Production checks the protected ancestor chain too.
            held = self.dispatch("hold", "--issued-approval")
        self.assertEqual("holding", held["state"]["phase"])
        self.adapter._mysql.assert_called_once()

    def test_approval_template_promote_refuses_failed_live_preconditions(self):
        self.ready()
        self.adapter.backup_ok = False
        with self.assertRaisesRegex(cli.UnsafeCutover, "evidence"):
            self.dispatch("approval-template", "--phase", "promote")
        self.adapter._run.assert_not_called()
        self.assertFalse(self.adapter.paths["issued_approvals"].exists())

    def test_promote_refused_in_drill_mode_with_journal_unchanged(self):
        self.ready()
        before = self.runner.journal.path.read_bytes()
        with self.assertRaisesRegex(cli.UnsafeCutover, "cannot promote"):
            self.dispatch("promote", "--issued-approval", "--then-reopen")
        self.assertEqual(before, self.runner.journal.path.read_bytes())

    def test_hold_preflight_refuses_unreachable_or_open_mysql(self):
        before = self.runner.journal.path.read_bytes()
        for change in ({"reachable": False}, {"gate": "open"}, {"writable": True}, {"foreign_activity": True},
                       {"business_activity": True}, {"database": "other"}, {"server_uuid": "other"}):
            self.adapter._mysql.return_value = probe_reading(**change)
            with self.assertRaises(cli.UnsafeCutover):
                self.runner.perform("hold", self.runner.approval_request("hold"),
                                    hold_preflight=lambda: cli.hold_preflight(self.adapter))
            self.assertEqual(before, self.runner.journal.path.read_bytes())
        self.assertEqual(0, self.adapter.close_calls)

    def test_conditional_abort_noop_when_phase_or_challenge_changed(self):
        state = self.hold()
        before = self.runner.journal.path.read_bytes()
        for phase, challenge in (("holding", "f" * 32), ("ready", state["challenge"])):
            self.dispatch("abort", "--if-phase", phase, "--if-challenge", challenge)
            self.assertEqual(before, self.runner.journal.path.read_bytes())
        with self.runner.journal.acquire():
            result = self.dispatch("abort", "--if-phase", "holding", "--if-challenge", state["challenge"])
        self.assertFalse(result["aborted"])
        self.assertEqual(before, self.runner.journal.path.read_bytes())
        result = self.dispatch("abort", "--if-phase", "holding", "--if-challenge", state["challenge"])
        self.assertEqual("sqlite_authoritative", result["state"]["phase"])

    def test_arm_deadline_builds_transient_timer(self):
        state = self.hold()
        result = self.dispatch("arm-deadline", "--minutes", "3", "--phase", "holding")
        cmd = self.adapter._run.call_args.args[0]
        self.assertIn("--unit=" + result["timer"], cmd)
        self.assertIn("--on-active=180s", cmd)
        self.assertIn("--property=UMask=0077", cmd)
        self.assertIn(state["challenge"], cmd)
        self.adapter._execute.assert_called_once_with(["/usr/bin/systemctl", "stop", result["timer"] + ".timer"])
        with self.assertRaises(cli.UnsafeCutover):
            self.dispatch("arm-deadline", "--minutes", "3", "--phase", "ready")

    def test_gate_normalise_refused_when_writes_possible(self):
        state = self.runner.status()
        self.runner.journal.write({**state, "writes_possible": True})
        with patch.object(cli.gate, "close_gate") as close:
            with self.assertRaises(cli.UnsafeCutover):
                self.dispatch("gate-normalise")
        close.assert_not_called()

    def test_pin_config_refuses_unreproducible_sqlite_hash(self):
        live = SimpleNamespace(engine="sqlite", source="cache", live_config_sha256="1" * 64)
        with patch.object(cli, "RUNTIME", self.runner.journal.directory), patch.object(cli.live_config, "measure",
                return_value=live), patch.object(cli.config_build, "stage",
                side_effect=cli.config_build.BuildFailed("different hash")) as stage:
            with self.assertRaises(cli.config_build.BuildFailed):
                cli.pin_config({"app_root": "/fixture"})
        self.assertEqual(live.live_config_sha256, stage.call_args.kwargs["expected_sha256"])
        self.assertFalse(Path(stage.call_args.kwargs["parent"]).exists())


class DetachedWrapperTests(unittest.TestCase):
    def setUp(self):
        self.wrapper = runpy.run_path(str(HERE / "sbin" / "repro-cutover-run"))

    def test_run_wrapper_argv(self):
        with patch("sys.platform", "linux"), patch("os.geteuid", return_value=0, create=True), \
                patch("subprocess.run", return_value=SimpleNamespace(returncode=0)) as run, \
                contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(0, self.wrapper["main"](["hold", "--issued-approval"]))
        launch, journal = [entry.args[0] for entry in run.call_args_list]
        self.assertTrue(launch[1].startswith("--unit=repro-cutover-op-hold-"))
        self.assertEqual(launch[1][7:] + ".service", journal[-1])
        for flag in ("--collect", "--wait", "--service-type=exec", "--property=UMask=0077",
                     "--property=TimeoutStartSec=25min"):
            self.assertIn(flag, launch)
        self.assertEqual(["hold", "--issued-approval"], launch[-2:])
        for argv in (["--config", "/tmp/host"], ["hold", "--approval", "/anywhere"], ["gate-normalise"],
                     ["abort", "--if-phase", "ready"], ["prepare", "--help"], ["check-evidence", "--inputs", "../x"]):
            self.assertFalse(self.wrapper["allowed"](argv))

    def test_operator_sudoers_lists_exact_commands(self):
        text = (HERE / "sudoers" / "repro-cutover-operator").read_text()
        rules = [line.split(" NOPASSWD: ") for line in text.splitlines() if line and not line.startswith("#")]
        self.assertEqual({"repro-cutover-operator ALL=(root)"}, {who for who, _ in rules})
        units = ("repro-media-archives.service", "repro-backup.timer", "repro-recovery-kit.timer",
                 "repro-recovery-kit.path", "repro-binlog-purge.timer")
        expected = {f"/usr/bin/systemctl {verb} {unit}" for unit in units for verb in ("start", "stop")}
        self.assertEqual(expected | {"/usr/local/sbin/repro-cutover-run"}, {command for _, command in rules})
