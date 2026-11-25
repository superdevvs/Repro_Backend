"""Linux tests for the production host adapter and CLI with fake host commands and real processes."""

import io
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import unittest
import uuid
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

sys.path[:0] = [str(Path(__file__).resolve().parents[1]), str(Path(__file__).resolve().parent)]
import adapter as production
import cli
import deploy_marker
import sqlite_seal
from fence import guard
from runner import Identity, Journal, Runner, UnsafeCutover

IDENTITY = Identity("host-fixture", "a" * 40, "b" * 64, "c" * 64, "d" * 64, "ec219d68-61fd-4a9e-ba98-43ddb4008383")
QUEUES = ["database:mail", "database:default", "studio:studio"]


@unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0, "Requires Linux root, /proc and flock")
class ProductionAdapterTests(unittest.TestCase):
    def setUp(self):
        # Protected-file checks reject world-writable parents such as /tmp.
        temporary = tempfile.TemporaryDirectory(prefix="repro-cutover-host-", dir="/var/lib")
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.root.chmod(0o755)
        self.tag = uuid.uuid4().hex[:12]
        for folder in ("state", "run", "ingress", "etc", "home", "database", "log"):
            (self.root / folder).mkdir(mode=0o755)
        (self.root / "state").chmod(0o700)
        (self.root / "run").chmod(0o700)
        os.chown(self.root / "database", 33, 33)
        os.chown(self.root / "home", 33, 33)
        self.database = self.root / "database" / "database.sqlite"
        subprocess.run(["/usr/bin/setpriv", "--reuid=33", "--regid=33", "--clear-groups", sys.executable, "-c",
                        "import sqlite3, sys; c = sqlite3.connect(sys.argv[1]); c.execute('PRAGMA journal_mode=WAL');"
                        "c.execute('CREATE TABLE t (v)'); c.commit()", str(self.database)], check=True)
        (self.root / "machine-id").write_bytes(b"host fixture machine\n")
        self.boot = self.root / "boot-id"
        self.boot.write_text("2fb9e4be-f041-45a0-9055-8d3ed47f9857\n", encoding="ascii")
        self.log = self.root / "log" / "commands.jsonl"
        self.config_path = self.root / "etc" / "host.json"
        self.config_path.write_text(json.dumps(self.config()), encoding="utf-8")
        policy = {
            "version": 1, "machine_id_sha256": guard.sha256((self.root / "machine-id").read_bytes()),
            "identity": {**IDENTITY.__dict__}, "sqlite_live_config_sha256": "1" * 64, "mysql_live_config_sha256": "2" * 64,
        }
        (self.root / "etc" / "policy.json").write_text(json.dumps(policy, sort_keys=True), encoding="utf-8")
        (self.root / "ingress" / "open").write_text("{}", encoding="ascii")
        self.workers = []

    def record(self, name):
        return [sys.executable, "-c",
                "import json, sys; open(sys.argv[1], 'a').write(json.dumps(sys.argv[2:]) + '\\n')", str(self.log), name]

    def config(self, **overrides):
        root = self.root
        return {
            "version": 1,
            "paths": {
                "state": str(root / "state"), "policy": str(root / "etc" / "policy.json"),
                "evidence": str(root / "run" / "verified-engine.json"),
                "activation": str(root / "run" / "verified-activation.json"),
                "machine_id": str(root / "machine-id"), "boot_id": str(self.boot),
                "ingress_open": str(root / "ingress" / "open"), "deploy_marker": str(root / "etc" / "active"),
                "scheduler_hold": str(root / "run" / "scheduler-hold.json"),
                "sqlite_seal_record": str(root / "state" / "sqlite-seal.json"),
            },
            "sqlite": str(self.database),
            "deploy_locks": [str(root / "home" / ".repro-backend-deploy.lock"),
                             str(root / "home" / ".repro-frontend-deploy.lock")],
            "deploy_owner": "www-data",
            "queues": QUEUES,
            "commands": {
                "identity": [sys.executable, "-c", f"print({json.dumps(json.dumps(IDENTITY.__dict__))})"],
                "mysql": [sys.executable, "-c", "print('{\"writable\": false, \"business_activity\": false}')"],
                "queue_pause": self.record("pause"), "queue_resume": self.record("resume"),
                # '[f]ake' never matches this shell's own command line.
                "stop_workers": [["/bin/sh", "-c", f"/usr/bin/pkill -f '[f]ake-queue-worker-{self.tag}' || true"],
                                 self.record("stop")],
                "start_workers": [self.record("start")],
                "drain_fpm": [self.record("drain-fpm")],
            },
            "writer_processes": [f"fake-queue-worker-{self.tag}", f"fake-studio-worker-{self.tag}"],
            "long_job_processes": [f"fake-studio-worker-{self.tag}"],
            "scheduler_processes": [f"fake-schedule-run-{self.tag}"],
            "command_seconds": 20,
            "options": {"max_drain_seconds": 5, "separate_freeze_approval": False, "adopt_external_hold": False,
                        "require_long_job_predrain": True},
            **overrides,
        }

    def spawn(self, name, seconds=30):
        process = subprocess.Popen([f"{name}-{self.tag}", str(seconds)], executable="/bin/sleep")
        self.workers.append(process)
        self.addCleanup(lambda: process.poll() is None and process.kill())
        time.sleep(0.1)
        return process

    def commands(self):
        if not self.log.exists():
            return []
        return [json.loads(line) for line in self.log.read_text().splitlines()]

    def host(self):
        loaded = production.load_config(self.config_path.read_bytes())
        journal = Journal(Path(loaded["paths"]["state"]))
        adapter = production.ProductionAdapter(loaded, journal)
        return journal, adapter, production.runner_for(loaded, journal, adapter)

    def started(self):
        journal, adapter, runner = self.host()
        runner.initialize()
        return journal, adapter, runner

    def test_hold_closes_ingress_publishes_the_deploy_fence_and_pauses_every_queue(self):
        journal, adapter, runner = self.started()
        worker = self.spawn("fake-queue-worker")
        state = runner.perform("hold", runner.approval_request("hold"))
        self.assertEqual("holding", state["phase"])
        self.assertFalse((self.root / "ingress" / "open").exists())
        self.assertTrue(deploy_marker.published(self.root / "etc" / "active", IDENTITY.run_id))
        self.assertEqual([["pause", queue] for queue in QUEUES], self.commands())
        observed = adapter.observe()
        self.assertTrue(observed.held())
        self.assertFalse(observed.workers_stopped)
        self.assertIsNone(worker.poll(), "The hold must not stop workers; the drain does")
        with self.assertRaisesRegex(guard.Refused, "holds all admissions"):
            guard.authority(json.loads((self.root / "etc" / "policy.json").read_text()), state, "scheduler")

    def test_long_job_worker_still_running_refuses_the_hold(self):
        journal, adapter, runner = self.started()
        self.spawn("fake-studio-worker")
        original = journal.path.read_bytes()
        with self.assertRaisesRegex(UnsafeCutover, "Long-job queues"):
            runner.perform("hold", runner.approval_request("hold"))
        self.assertEqual(original, journal.path.read_bytes())
        self.assertTrue((self.root / "ingress" / "open").exists())

    def test_freeze_stops_workers_waits_for_the_scheduler_and_seals_sqlite(self):
        journal, adapter, runner = self.started()
        worker = self.spawn("fake-queue-worker")
        scheduler = self.spawn("fake-schedule-run", seconds=1.5)
        runner.perform("hold", runner.approval_request("hold"))
        state = runner.perform("freeze")
        self.assertEqual("frozen", state["phase"])
        self.assertIsNotNone(worker.poll())
        self.assertIsNotNone(scheduler.poll(), "The scheduler drain returned while a scheduled command ran")
        self.assertEqual(0, state["scheduler_drain"]["unresolved_permits"])
        self.assertFalse(sqlite_seal.writable(self.database))
        self.assertTrue(adapter.observe().frozen())
        self.assertIn(["drain-fpm"], self.commands())

    def test_writer_that_never_stops_keeps_the_hold_and_the_watchdog_rolls_back(self):
        config = self.config()
        config["commands"]["stop_workers"] = [self.record("stop")]  # The stop does not reach this worker.
        self.config_path.write_text(json.dumps(config), encoding="utf-8")
        journal, adapter, runner = self.started()
        worker = self.spawn("fake-queue-worker")
        held = runner.perform("hold", runner.approval_request("hold"))
        with self.assertRaisesRegex(UnsafeCutover, "still running"):
            runner.perform("freeze")
        self.assertEqual(("holding", "freeze"), (journal.read()["phase"], journal.read()["pending"]["action"]))
        self.assertTrue(sqlite_seal.writable(self.database))
        worker.kill()
        worker.wait()
        late = Runner(journal, IDENTITY, adapter, lambda: held["started_at"] + Runner.DECISION_SECONDS)
        self.assertEqual("sqlite_authoritative", late.perform("tick")["phase"])

    def test_abort_publishes_sqlite_authority_and_reopen_restores_admissions_in_order(self):
        journal, adapter, runner = self.started()
        runner.perform("hold", runner.approval_request("hold"))
        runner.perform("freeze")
        state = runner.perform("abort")
        self.assertEqual("sqlite_authoritative", state["phase"])
        self.assertTrue(sqlite_seal.writable(self.database))
        self.assertFalse((self.root / "ingress" / "open").exists())
        policy = (self.root / "etc" / "policy.json").read_bytes()
        evidence = (self.root / "run" / "verified-engine.json").read_bytes()
        host = {"machine_sha": guard.sha256((self.root / "machine-id").read_bytes()),
                "boot_id": self.boot.read_text().strip()}
        guard.verify(policy, journal.path.read_bytes(), evidence, **host, action="queue-worker", expected_engine="sqlite")
        with self.assertRaisesRegex(guard.Refused, "not authoritative"):
            guard.verify(policy, journal.path.read_bytes(), evidence, **host, action="queue-worker",
                         expected_engine="mysql")

        before = len(self.commands())
        adapter.reopen()
        self.assertTrue((self.root / "ingress" / "open").exists())
        self.assertEqual([["resume", queue] for queue in QUEUES] + [["start"]], self.commands()[before:])
        self.assertFalse((self.root / "etc" / "active").exists())
        activation = (self.root / "run" / "verified-activation.json").read_bytes()
        guard.verify(policy, journal.path.read_bytes(), evidence, **host, action="deploy", expected_engine="sqlite",
                     activation_raw=activation)
        self.assertTrue(adapter.observe().sqlite_live())

    def admitted_worker(self, name):
        """A started worker whose wrapper keeps its shared writer lease for its whole life."""
        code = ("import fcntl, sys, time\nlease = open(sys.argv[1], 'rb')\nfcntl.flock(lease, fcntl.LOCK_SH)\n"
                "print('admitted', flush=True)\ntime.sleep(60)\n")
        process = subprocess.Popen([sys.executable, "-c", code, str(self.root / "state" / "operator.lock"),
                                    f"{name}-{self.tag}"], stdout=subprocess.PIPE, text=True)
        self.addCleanup(lambda: process.poll() is None and process.kill())
        self.addCleanup(process.stdout.close)
        self.assertEqual("admitted\n", process.stdout.readline())
        return process

    def test_reopen_retry_stops_workers_that_a_failed_reopen_started(self):
        blocker = self.root / "etc" / "second-group-fails"
        blocker.write_text("", encoding="ascii")
        config = self.config(command_seconds=3)
        config["commands"]["start_workers"] = [self.record("start"), ["/bin/sh", "-c", f"! test -e {blocker}"]]
        self.config_path.write_text(json.dumps(config), encoding="utf-8")
        journal, adapter, runner = self.started()
        runner.perform("hold", runner.approval_request("hold"))
        runner.perform("freeze")
        runner.perform("abort")
        with self.assertRaisesRegex(UnsafeCutover, "Host command failed"):
            adapter.reopen()
        # The first group started before the second failed; its wrapper keeps the shared lease.
        worker = self.admitted_worker("fake-queue-worker")
        self.assertFalse((self.root / "ingress" / "open").exists())
        blocker.unlink()

        before = len(self.commands())
        adapter.reopen()
        self.assertIsNotNone(worker.poll(), "The retry did not stop the worker the failed reopen started")
        self.assertEqual([["stop"]] + [["resume", queue] for queue in QUEUES] + [["start"]], self.commands()[before:])
        self.assertTrue((self.root / "ingress" / "open").exists())
        self.assertTrue((self.root / "run" / "verified-activation.json").exists())
        self.assertFalse((self.root / "etc" / "active").exists())
        self.assertTrue(adapter.observe().sqlite_live())

    def test_reboot_during_hold_reasserts_the_closed_route_under_the_new_boot(self):
        journal, adapter, runner = self.started()
        runner.perform("hold", runner.approval_request("hold"))
        # The deploy marker lives in /etc and survives; /run starts empty after a reboot.
        (self.root / "etc" / "active").unlink()
        self.boot.write_text("6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11\n", encoding="ascii")
        before = len(self.commands())
        journal, adapter, runner = self.host()
        state = runner.perform("tick")
        self.assertEqual("6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11", state["hold"]["boot_id"])
        self.assertEqual("reassert_hold", state["history"][-1]["action"])
        self.assertTrue(deploy_marker.published(self.root / "etc" / "active", IDENTITY.run_id))
        self.assertEqual([["pause", queue] for queue in QUEUES], self.commands()[before:])

    def test_target_steps_are_refused_by_this_adapter(self):
        journal, adapter, runner = self.started()
        runner.perform("hold", runner.approval_request("hold"))
        runner.perform("freeze")
        with self.assertRaisesRegex(UnsafeCutover, "SQLite hold, freeze and rollback only"):
            runner.perform("prepare")
        self.assertEqual("sqlite_authoritative", runner.perform("abort")["phase"])

    def guard_paths(self):
        # The installed guard's fixed paths, pointed at this fixture's root-owned tree.
        paths = self.config()["paths"]
        return patch.multiple(
            guard, STATE=Path(paths["state"]), POLICY=Path(paths["policy"]), EVIDENCE=Path(paths["evidence"]),
            ACTIVATION=Path(paths["activation"]), MACHINE_ID=Path(paths["machine_id"]), BOOT_ID=self.boot,
            _require_root_installation=lambda: None,
        )

    def marker_paths(self):
        # The nginx hold and Deploy-App read fixed marker paths; point them at this fixture.
        paths = self.config()["paths"]
        return patch.multiple(cli, INGRESS_OPEN=Path(paths["ingress_open"]), DEPLOY_MARKER=Path(paths["deploy_marker"]))

    def cli(self, *argv):
        output = io.StringIO()
        with self.guard_paths(), self.marker_paths(), redirect_stdout(output):
            code = cli.main(["--config", str(self.config_path), *argv])
        return code, json.loads(output.getvalue()) if output.getvalue().strip() else None

    def test_cli_refuses_marker_paths_that_nginx_and_deploys_do_not_read(self):
        journal, _, runner = self.started()
        approval = self.root / "state" / "hold-approval.json"
        approval.write_text(json.dumps(runner.approval_request("hold").__dict__), encoding="utf-8")
        approval.chmod(0o600)
        # A host config that names another file would close nothing nginx or Deploy-App reads.
        for constant in ("INGRESS_OPEN", "DEPLOY_MARKER"):
            with self.subTest(constant=constant):
                errors = io.StringIO()
                with self.guard_paths(), self.marker_paths(), patch.object(cli, constant, self.root / "elsewhere"), \
                        redirect_stdout(io.StringIO()), patch("sys.stderr", errors):
                    code = cli.main(["--config", str(self.config_path), "hold", "--approval", str(approval)])
                self.assertEqual("planned", journal.read()["phase"])
                self.assertEqual(1, code)
                self.assertIn("marker paths", errors.getvalue())
        self.assertTrue((self.root / "ingress" / "open").exists())

    def test_reboot_in_planned_holds_writers_until_refresh_and_reopen(self):
        journal, adapter, runner = self.started()
        # A reboot empties /run: ingress is closed and no authority proof exists for the new boot.
        (self.root / "ingress" / "open").unlink()
        self.boot.write_text("6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11\n", encoding="ascii")
        journal, adapter, runner = self.host()
        with self.assertRaises((UnsafeCutover, FileNotFoundError)):
            adapter.reopen()
        self.assertFalse((self.root / "ingress" / "open").exists())

        code, refreshed = self.cli("refresh")
        self.assertEqual(0, code)
        self.assertEqual("planned", refreshed["state"]["phase"])
        evidence = json.loads((self.root / "run" / "verified-engine.json").read_text())
        self.assertEqual(("6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11", guard.sha256(journal.path.read_bytes())),
                         (evidence["boot_id"], evidence["journal_sha256"]))
        before = len(self.commands())
        self.assertEqual(0, self.cli("reopen")[0])
        self.assertTrue((self.root / "ingress" / "open").exists())
        self.assertEqual([["resume", queue] for queue in QUEUES] + [["start"]], self.commands()[before:])
        self.assertFalse((self.root / "run" / "verified-activation.json").exists())
        policy = (self.root / "etc" / "policy.json").read_bytes()
        host = {"machine_sha": guard.sha256((self.root / "machine-id").read_bytes()),
                "boot_id": "6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11"}
        guard.verify(policy, journal.path.read_bytes(), json.dumps(evidence).encode(), **host,
                     action="scheduler", expected_engine="sqlite")
        with self.assertRaisesRegex(guard.Refused, "Deploys are held"):
            guard.verify(policy, journal.path.read_bytes(), json.dumps(evidence).encode(), **host,
                         action="deploy", expected_engine="sqlite")

    def test_reboot_after_reopened_rollback_ticks_refreshes_and_reopens(self):
        journal, adapter, runner = self.started()
        runner.perform("hold", runner.approval_request("hold"))
        runner.perform("freeze")
        runner.perform("abort")
        adapter.reopen()
        self.assertFalse((self.root / "etc" / "active").exists())
        # A reboot empties /run: no open marker, no authority proof, no activation receipt.
        (self.root / "ingress" / "open").unlink()
        for record in (self.root / "run").iterdir():
            record.unlink()
        rebooted = "6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11"
        self.boot.write_text(rebooted + "\n", encoding="ascii")

        code, ticked = self.cli("tick")
        self.assertEqual(0, code)
        self.assertEqual(("sqlite_authoritative", False), (ticked["state"]["phase"], ticked["state"]["writes_possible"]))
        code, refreshed = self.cli("refresh")
        self.assertEqual(0, code)
        self.assertEqual("sqlite_authoritative", refreshed["state"]["phase"])
        self.assertTrue(deploy_marker.published(self.root / "etc" / "active", IDENTITY.run_id))
        self.assertEqual(0, self.cli("reopen")[0])
        self.assertTrue((self.root / "ingress" / "open").exists())
        self.assertFalse((self.root / "etc" / "active").exists())
        policy = (self.root / "etc" / "policy.json").read_bytes()
        evidence = (self.root / "run" / "verified-engine.json").read_bytes()
        activation = (self.root / "run" / "verified-activation.json").read_bytes()
        guard.verify(policy, journal.path.read_bytes(), evidence,
                     machine_sha=guard.sha256((self.root / "machine-id").read_bytes()), boot_id=rebooted,
                     action="deploy", expected_engine="sqlite", activation_raw=activation)
        self.assertEqual("sqlite_authoritative", journal.read()["phase"])

    def reboot_without_runtime_directories(self):
        """A real reboot mounts a fresh /run: the runtime directories are gone, not only their files."""
        shutil.rmtree(self.root / "run")
        shutil.rmtree(self.root / "ingress")
        self.boot.write_text("6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11\n", encoding="ascii")

    def assert_runtime_directories(self):
        for folder in ("run", "ingress"):
            info = (self.root / folder).lstat()
            self.assertTrue(stat.S_ISDIR(info.st_mode))
            # Root-owned and not writable by others (guard chain); nginx workers must traverse the ingress one.
            self.assertEqual((0, 0o755), (info.st_uid, stat.S_IMODE(info.st_mode)), folder)

    def test_refresh_and_reopen_recreate_the_runtime_directories_after_a_reboot(self):
        self.started()
        self.reboot_without_runtime_directories()
        errors = io.StringIO()
        with patch("sys.stderr", errors):
            code, refreshed = self.cli("refresh")
        self.assertEqual(0, code, errors.getvalue())
        self.assertEqual("planned", refreshed["state"]["phase"])
        self.assertEqual(0, self.cli("reopen")[0])
        self.assertTrue((self.root / "ingress" / "open").exists())
        self.assert_runtime_directories()

    def test_watchdog_rolls_back_after_a_reboot_removed_the_runtime_directories(self):
        journal, adapter, runner = self.started()
        held = runner.perform("hold", runner.approval_request("hold"))
        self.reboot_without_runtime_directories()
        journal, adapter, _ = self.host()
        late = Runner(journal, IDENTITY, adapter, lambda: held["started_at"] + Runner.DECISION_SECONDS)
        self.assertEqual("sqlite_authoritative", late.perform("tick")["phase"])
        self.assertTrue((self.root / "run" / "verified-engine.json").exists())
        self.assertFalse((self.root / "ingress" / "open").exists())

    def test_refresh_on_an_open_host_keeps_the_current_proof(self):
        self.started()
        (self.root / "ingress" / "open").unlink()
        self.assertEqual(0, self.cli("refresh")[0])
        self.assertEqual(0, self.cli("reopen")[0])
        evidence = (self.root / "run" / "verified-engine.json").read_bytes()
        # Live host: refresh must not revoke the proof that admits the scheduler and restarted workers.
        errors = io.StringIO()
        with patch("sys.stderr", errors):
            self.assertEqual(1, self.cli("refresh")[0])
        self.assertTrue((self.root / "run" / "verified-engine.json").exists(), "Refresh revoked the live proof")
        self.assertEqual(evidence, (self.root / "run" / "verified-engine.json").read_bytes())
        self.assertIn("Ingress is open", errors.getvalue())
        self.assertTrue((self.root / "ingress" / "open").exists())

    def test_cli_refuses_a_config_that_differs_from_the_installed_guard_paths(self):
        self.started()
        self.assertEqual(0, self.cli("status")[0])
        errors = io.StringIO()
        with redirect_stdout(io.StringIO()), patch("sys.stderr", errors):
            self.assertEqual(1, cli.main(["--config", str(self.config_path), "status"]))
        self.assertIn("installed guard paths", errors.getvalue())

    def test_cli_runs_status_hold_freeze_tick_and_abort_from_the_root_config(self):
        call = self.cli

        self.assertEqual("planned", call("init")[1]["state"]["phase"])
        code, template = call("approval-template", "--phase", "hold")
        approval = self.root / "state" / "hold-approval.json"
        approval.write_text(json.dumps(template), encoding="utf-8")
        approval.chmod(0o600)
        self.assertEqual("holding", call("hold", "--approval", str(approval))[1]["state"]["phase"])
        self.assertEqual("frozen", call("freeze")[1]["state"]["phase"])
        self.assertEqual("frozen", call("tick")[1]["state"]["phase"])
        self.assertEqual("sqlite_authoritative", call("abort")[1]["state"]["phase"])
        self.assertEqual(1, call("hold", "--approval", str(approval))[0])

    def test_cli_requires_a_protected_approval_file(self):
        journal, adapter, runner = self.started()
        approval = self.root / "state" / "hold-approval.json"
        approval.write_text(json.dumps(runner.approval_request("hold").__dict__), encoding="utf-8")
        approval.chmod(0o666)
        self.assertEqual(1, self.cli("hold", "--approval", str(approval))[0])
        self.assertEqual("planned", journal.read()["phase"])


class UnitFileTests(unittest.TestCase):
    HERE = Path(__file__).resolve().parent

    @staticmethod
    def entries(path):
        values = {}
        for line in path.read_text(encoding="utf-8").splitlines():
            if line and not line.startswith(("#", ";", "[")) and "=" in line:
                key, value = line.split("=", 1)
                values.setdefault(key.strip(), []).append(value.strip())
        return values

    @staticmethod
    def seconds(span):
        units = {"": 1, "s": 1, "sec": 1, "m": 60, "min": 60, "h": 3600}
        parts = re.findall(r"(\d+)\s*([a-z]*)", span)
        if not parts or "".join(number + unit for number, unit in parts) != span.replace(" ", ""):
            raise ValueError(f"Unsupported systemd time span: {span}")
        return sum(int(number) * units[unit] for number, unit in parts)

    def test_watchdog_timer_ticks_every_minute_from_boot_as_root(self):
        service = self.entries(self.HERE / "systemd" / "repro-cutover-watchdog.service")
        timer = self.entries(self.HERE / "systemd" / "repro-cutover-watchdog.timer")
        self.assertEqual(["oneshot"], service["Type"])
        self.assertEqual(["/usr/bin/python3 -I -S /opt/repro-cutover-fence/production/cli.py tick"], service["ExecStart"])
        self.assertNotIn("User", service)
        self.assertEqual(["/var/lib/repro-cutover-fence/state.json"], service["ConditionPathExists"])
        # A wall-clock schedule keeps ticking even when the service's condition skipped an
        # earlier tick (no journal yet); OnUnitActiveSec counts from the unit's last activation.
        self.assertEqual(["minutely"], timer["OnCalendar"])
        self.assertNotIn("OnUnitActiveSec", timer)
        self.assertEqual(["30s"], timer["OnBootSec"])
        self.assertEqual(["timers.target"], timer["WantedBy"])

    def test_watchdog_service_outlasts_the_controller_wait_and_a_full_rollback(self):
        # A deadline tick runs the whole rollback (close, drain, unseal, publish). systemd must not
        # kill it inside the runner's own bounds: the controller-lock wait, the longest rollback
        # budget the runner grants, and one more maximal host command after that budget.
        service = self.entries(self.HERE / "systemd" / "repro-cutover-watchdog.service")
        budget = max(Runner.WINDOW_SECONDS - Runner.DECISION_SECONDS, Runner.ROLLBACK_SECONDS)
        required = Runner.CONTROLLER_WAIT_SECONDS + budget + production.MAX_COMMAND_SECONDS
        self.assertEqual(1, len(service["TimeoutStartSec"]))
        self.assertGreaterEqual(self.seconds(service["TimeoutStartSec"][0]), required)

    def test_every_boot_recreates_the_runtime_directories_of_the_guard_and_nginx(self):
        # /run is a tmpfs. The guard checks the evidence directory's chain, the controller writes
        # its records there, and nginx looks for the open marker in the ingress directory.
        config = self.HERE / "tmpfiles.d" / "repro-cutover-fence.conf"
        lines = [line.split() for line in config.read_text(encoding="ascii").splitlines()
                 if line.strip() and not line.startswith("#")]
        self.assertEqual({guard.EVIDENCE.parent.as_posix(), guard.ACTIVATION.parent.as_posix(),
                          cli.INGRESS_OPEN.parent.as_posix()}, {line[1] for line in lines})
        for line in lines:
            with self.subTest(path=line[1]):
                self.assertEqual(["d", line[1], "0755", "root", "root", "-"], line)

    def test_media_archive_drop_in_starts_the_worker_through_the_wrapper(self):
        drop_in = self.entries(self.HERE / "systemd" / "repro-media-archives.service.d" / "cutover-fence.conf")
        self.assertEqual(["", "+/usr/bin/python3 -I -S /opt/repro-cutover-fence/production/admit.py media-archives"],
                         drop_in["ExecStart"])
        self.assertEqual(["75"], drop_in["RestartPreventExitStatus"])
        profiles = json.loads((self.HERE / "wrappers.example.json").read_text(encoding="utf-8"))["profiles"]
        unit = self.entries(self.HERE.parents[1] / "deploy" / "repro-media-archives.service")
        media = profiles["media-archives"]
        self.assertEqual(unit["ExecStart"][0].split(), media["argv"])
        self.assertEqual((unit["User"][0], unit["Group"][0], unit["UMask"][0]), (media["user"], media["group"], media["umask"]))

    def test_cli_marker_paths_are_the_ones_nginx_and_deploys_read(self):
        hold = (self.HERE / "nginx" / "repro-cutover-hold.conf").read_text(encoding="ascii")
        self.assertIn(f"if (-f {cli.INGRESS_OPEN.as_posix()})", hold)
        deploy = (self.HERE.parents[1] / "deploy" / "Deploy-App.ps1").read_text(encoding="utf-8")
        self.assertEqual(2, deploy.count(f"[ -e {cli.DEPLOY_MARKER.as_posix()} ]"))

    def test_supervisor_restarts_a_worker_that_exits_normally(self):
        # Every deploy runs queue:restart, and --max-time ends a worker each hour: queue:work then
        # exits 0 and must start again through the wrapper. Only a held admission (75) stays down.
        program = self.entries(self.HERE / "supervisor" / "laravel-worker.conf.example")
        self.assertEqual(["unexpected"], program["autorestart"])
        self.assertEqual(["75"], program["exitcodes"])

    def test_every_installed_user_supervisor_program_has_a_wrapper_profile(self):
        installer = (self.HERE.parents[1] / "install-studio-runtime.sh").read_text(encoding="utf-8")
        scripts = set(re.findall(r"^command=\$runtime/(\S+)$", installer, re.MULTILINE))
        self.assertEqual({"worker.sh", "lab-tour-worker.sh"}, scripts)
        profiles = json.loads((self.HERE / "wrappers.example.json").read_text(encoding="utf-8"))["profiles"]
        by_script = {Path(profile["argv"][0]).name: name for name, profile in profiles.items()
                     if profile["argv"][0].startswith("/home/maverick/.local/share/repro-studio/")}
        sudoers = (self.HERE / "sudoers" / "repro-cutover-admit").read_text(encoding="utf-8")
        allowed = set(re.findall(r"admit\.py ([a-z0-9-]+)", sudoers))
        for script in sorted(scripts):
            with self.subTest(script=script):
                self.assertIn(script, by_script)
                self.assertEqual("queue-worker", profiles[by_script[script]]["action"])
                self.assertIn(by_script[script], allowed)

    @unittest.skipUnless(os.name == "posix", "The wrapper reads POSIX accounts")
    def test_every_example_profile_is_valid_and_every_cron_rule_has_a_profile(self):
        import admit

        raw = (self.HERE / "wrappers.example.json").read_bytes()
        names = json.loads(raw)["profiles"]
        with patch.object(admit.pwd, "getpwnam", return_value=type("Account", (), {"pw_uid": 1000})()), \
                patch.object(admit.grp, "getgrnam", return_value=type("Group", (), {"gr_gid": 33})()):
            for name in names:
                admit.load_profile(name, raw)
        rules = json.loads((self.HERE / "cron-rules.example.json").read_text(encoding="utf-8"))["rules"]
        self.assertTrue({rule["profile"] for rule in rules if "profile" in rule} <= set(names))
        supervisor = (self.HERE / "supervisor" / "laravel-worker.conf.example").read_text(encoding="utf-8")
        self.assertIn("admit.py laravel-worker", supervisor)


if __name__ == "__main__":
    unittest.main()
