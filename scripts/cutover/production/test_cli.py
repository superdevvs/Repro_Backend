"""Operator entry points on a disposable runner journal."""
import contextlib
import io
import json
import os
import runpy
import stat
import sys
import tempfile
import unittest
from datetime import datetime, timezone
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
        self.fixture = fixture
        self.runner = fixture.runner
        self.adapter = fixture.adapter
        self.adapter.paths = {"issued_approvals": fixture.journal.directory / "issued"}
        reading = probe_reading()
        self.adapter.config = {"commands": {"timer": ["/usr/bin/systemd-run"]},
                               "mysql": {"mode": "drill", "target_uuid": reading["server_uuid"], "database": reading["database"]}}
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
        expiry = datetime.fromtimestamp(approval["expires_at"], timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")
        self.assertIn("--on-calendar=" + expiry, command)
        self.assertIn("--timer-property=AccuracySec=1us", command)
        approval_unit = next(arg for arg in command if arg.startswith("--unit="))
        independent = self.dispatch("arm-deadline", "--minutes", "30", "--phase", "ready")["timer"]
        self.assertNotEqual(approval_unit, "--unit=" + independent)
        self.assertEqual(independent + ".timer", self.adapter._execute.call_args.args[0][-1])
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

    def issue_cutover_approval(self):
        private = tempfile.TemporaryDirectory(dir="/var/lib")
        self.addCleanup(private.cleanup)
        self.adapter.paths["issued_approvals"] = Path(private.name)
        self.adapter.config["mysql"]["mode"] = "cutover"
        self.dispatch("approval-template", "--phase", "promote")

    def test_approval_template_promote_refuses_failed_live_preconditions(self):
        self.ready()
        self.adapter.backup_ok = False
        with self.assertRaisesRegex(cli.UnsafeCutover, "evidence"):
            self.dispatch("approval-template", "--phase", "promote")
        self.adapter._run.assert_not_called()
        self.assertFalse(self.adapter.paths["issued_approvals"].exists())

    def test_status_evidence_requires_the_current_journals_exact_report(self):
        from test_evidence import raw, sample_report

        state = self.runner.status()
        report = sample_report(run_id=state["identity"]["run_id"])
        self.adapter.paths["evidence_report"] = Path("/fixture/report")
        state["evidence"] = {"report_sha256": cli.guard.sha256(raw(report))}
        self.runner.journal.write(state)
        with patch.object(cli.guard, "read_protected", return_value=raw(report)):
            self.assertEqual(report, self.dispatch("status", "--evidence"))
        for replacement in ({**report, "written_at": report["written_at"] + 1}, {**report, "run_id": "another-run"}):
            with self.subTest(replacement=replacement["run_id"]), \
                    patch.object(cli.guard, "read_protected", return_value=raw(replacement)), \
                    self.assertRaisesRegex(cli.UnsafeCutover, "current run"):
                self.dispatch("status", "--evidence")
        state["evidence"] = None
        self.runner.journal.write(state)
        with patch.object(cli.guard, "read_protected", return_value=raw(report)), \
                self.assertRaisesRegex(cli.UnsafeCutover, "current run"):
            self.dispatch("status", "--evidence")

    def test_promote_refused_in_drill_mode_with_journal_unchanged(self):
        self.ready()
        before = self.runner.journal.path.read_bytes()
        with self.assertRaisesRegex(cli.UnsafeCutover, "cannot promote"):
            self.dispatch("promote", "--issued-approval", "--then-reopen")
        self.assertEqual(before, self.runner.journal.path.read_bytes())

    @unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0, "approvals are root-owned")
    def test_promote_then_reopen_runs_in_one_unit(self):
        self.ready()
        self.issue_cutover_approval()
        original = self.adapter.reopen

        def reopen():
            self.assertEqual("mysql_authoritative", self.runner.status()["phase"])
            original()

        with patch.object(self.adapter, "reopen_mysql", side_effect=reopen, create=True) as reopened:
            result = self.dispatch("promote", "--issued-approval", "--then-reopen")
        reopened.assert_called_once_with()
        self.assertEqual("mysql_authoritative", result["state"]["phase"])
        self.assertTrue(result["observation"]["admissions_open"])

    @unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0, "approvals are root-owned")
    def test_promotion_that_returns_sqlite_rollback_cannot_chain_reopen(self):
        self.ready()
        self.issue_cutover_approval()
        self.fixture.now += self.runner.DECISION_SECONDS
        with patch.object(self.adapter, "reopen_mysql", create=True) as reopened, self.assertRaisesRegex(cli.UnsafeCutover, "authority"):
            self.dispatch("promote", "--issued-approval", "--then-reopen")
        reopened.assert_not_called()
        self.assertEqual("sqlite_authoritative", self.runner.status()["phase"])

    def test_combined_promotion_requires_mysql_reopen_before_recording_write_intent(self):
        self.ready()
        self.adapter.config["mysql"]["mode"] = "cutover"
        before = self.runner.journal.path.read_bytes()
        with self.assertRaisesRegex(cli.UnsafeCutover, "no MySQL reopen action"):
            self.dispatch("promote", "--issued-approval", "--then-reopen")
        self.assertEqual(before, self.runner.journal.path.read_bytes())

    def test_production_promote_refuses_an_alternate_approval_source(self):
        with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
            cli.arguments(["promote", "--approval", "/some/other/record.json"])

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
        deadline = int(self.runner.clock()) + 180
        end = datetime.fromtimestamp(deadline, timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")
        self.assertIn("--on-calendar=" + end, cmd)
        self.assertIn("--property=UMask=0077", cmd)
        self.assertIn(state["challenge"], cmd)
        self.adapter._execute.assert_called_once_with(["/usr/bin/systemctl", "stop", result["timer"] + ".timer"], deadline)
        with self.assertRaises(cli.UnsafeCutover):
            self.dispatch("arm-deadline", "--minutes", "3", "--phase", "ready")

    @unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0, "approvals are root-owned")
    def test_timer_installation_uses_the_same_expiry_after_a_slow_stop(self):
        self.ready()
        start = self.fixture.now
        def stop(_argv, deadline):
            self.assertEqual(start + 300, deadline)
            self.fixture.now += 12
            return 0, ""

        self.adapter._execute.side_effect = stop
        approval = self.dispatch("approval-template", "--phase", "promote")
        options = self.adapter._run.call_args.args[0]
        from e2e import run_drill
        parsed, _ = run_drill.timer_arguments(options[1:])
        self.assertEqual(start + 300, parsed["expires_at"])
        self.assertEqual("1us", parsed["accuracy"])
        self.assertEqual(approval["expires_at"], self.adapter._run.call_args.args[1])
        for flags in (["--timer-property=AccuracySec=2min"], ["--on-calendar=bad"]):
            with self.assertRaises(run_drill.TimerUsage):
                run_drill.timer_arguments(flags)

    @unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0, "approvals are root-owned")
    def test_timer_expiring_during_stop_or_activation_issues_no_approval(self):
        self.ready()
        path = self.adapter.paths["issued_approvals"] / "promote.json"
        for expire_during in ("stop", "activate"):
            def stop(_argv, deadline):
                if expire_during == "stop":
                    self.fixture.now = deadline
                return 0, ""

            def activate(_argv, deadline):
                self.fixture.now = deadline

            self.adapter._execute.side_effect = stop
            self.adapter._run.reset_mock()
            self.adapter._run.side_effect = activate
            with self.subTest(expire_during), self.assertRaisesRegex(cli.UnsafeCutover, "expired"):
                self.dispatch("approval-template", "--phase", "promote")
            self.assertFalse(path.exists())
            self.assertEqual(expire_during == "activate", self.adapter._run.called)

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
