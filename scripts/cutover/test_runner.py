import errno
import json
import os
import re
import tempfile
import threading
import time
import unittest
from contextlib import contextmanager
from dataclasses import asdict, replace
from pathlib import Path
from unittest.mock import patch

from fence import guard
from rehearse import FixtureAdapter
from runner import Identity, Journal, Runner, UnsafeCutover

REBOOTED = "6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11"


class SimulatedPowerLoss(BaseException):
    pass


class FaultAdapter(FixtureAdapter):
    def __init__(self, journal, identity):
        super().__init__(journal, identity)
        self.before_close = lambda: None
        self.after_close = lambda: None
        self.before_drain = lambda: None
        self.before_freeze = lambda: None
        self.after_freeze = lambda: None
        self.before_promote = lambda: None
        self.after_promote = lambda: None
        self.after_prepare = lambda: None
        self.after_restore = lambda: None
        self.after_authority_publish = lambda: None
        self.children_active = False
        self.backup_ok = True
        self.long_jobs = True
        self.evidence_override = {}
        self.sqlite_restores = 0
        self.close_calls = 0
        self.last_lease = None
        # What each drain was for, a refusal it may raise, and the FPM drain record it returns.
        self.drain_purposes = []
        self.drain_refusal = lambda purpose: None
        self.drain_report = lambda purpose: None

    def close_admissions(self, lease, deadline):
        self.close_calls += 1
        self.before_close()
        super().close_admissions(lease, deadline)
        self.after_close()

    def drain(self, lease, deadline, *, purpose):
        self.before_drain()
        self.drain_purposes.append(purpose)
        self.drain_refusal(purpose)
        super().drain(lease, deadline, purpose=purpose)
        return self.drain_report(purpose)

    def long_jobs_drained(self):
        return self.long_jobs

    def freeze(self, lease, deadline):
        self.last_lease = lease
        self.before_freeze()
        super().freeze(lease, deadline)
        self.after_freeze()

    def prepare(self, lease, deadline):
        super().prepare(lease, deadline)
        self.after_prepare()

    def seal_mysql(self, lease, deadline):
        self.before_promote()
        super().seal_mysql(lease, deadline)
        self.after_promote()

    def seal_sqlite(self, lease, deadline):
        self.sqlite_restores += 1
        super().seal_sqlite(lease, deadline)
        self.after_restore()

    def publish_authority(self, lease, engine, candidate, deadline):
        super().publish_authority(lease, engine, candidate, deadline)
        self.after_authority_publish()

    def require_quiescent(self):
        if self.children_active:
            raise UnsafeCutover("Prior runner still has active children")

    def evidence(self):
        return replace(super().evidence(), mysql_backup_offsite=self.backup_ok, **self.evidence_override)


class CutoverTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.journal = Journal(Path(self.tmp.name) / "repro-cutover-fixture-test")
        self.identity = Identity("test", "1" * 40, "2" * 64, "3" * 64, "4" * 64,
                                 "ec219d68-61fd-4a9e-ba98-43ddb4008383")
        self.adapter = FaultAdapter(self.journal, self.identity)
        self.now = 100_000
        self.runner = self.new_runner()
        self.runner.initialize()

    def new_runner(self, **options):
        return Runner(self.journal, self.identity, self.adapter, lambda: self.now, **options)

    def test_watchdog_never_reopens_an_inconsistent_postwrite_phase(self):
        runner = self.new_runner(after_unattended_rollback=lambda *args: self.fail("unexpected reopen"))
        held = self.hold(runner)
        self.now += Runner.DECISION_SECONDS
        for phase in ("mysql_authoritative", "recovery_required"):
            with self.subTest(phase=phase):
                self.journal.write({**held, "phase": phase, "writes_possible": False})
                with self.assertRaisesRegex(UnsafeCutover, "separately verified recovery"):
                    runner.perform("tick")
                self.assertTrue(self.journal.read()["writes_possible"])
                self.assertEqual("recovery_required", self.journal.read()["phase"])

    def hold(self, runner=None):
        runner = runner or self.runner
        return runner.perform("hold", runner.approval_request("hold"))

    def freeze(self):
        self.hold()
        return self.runner.perform("freeze")

    def reboot(self, **observed):
        """A new boot restores the open nginx configuration and restarts services."""
        self.adapter.BOOT_ID = REBOOTED
        self.journal.write_record("fixture.json", asdict(replace(self.adapter.observe(), **observed)))

    def ready(self):
        self.freeze()
        self.runner.perform("prepare")

    @staticmethod
    def crash():
        raise SimulatedPowerLoss()

    @contextmanager
    def admitted_writer(self):
        """Hold the lock an admitted writer shares (exclusive on Windows, which has no flock)."""
        with (self.journal.directory / "operator.lock").open("a+b") as stream:
            stream.seek(0)
            if os.name == "nt":
                import msvcrt
                msvcrt.locking(stream.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                import fcntl
                fcntl.flock(stream.fileno(), fcntl.LOCK_SH | fcntl.LOCK_NB)
            try:
                yield
            finally:
                stream.seek(0)
                if os.name == "nt":
                    msvcrt.locking(stream.fileno(), msvcrt.LK_UNLCK, 1)
                else:
                    fcntl.flock(stream.fileno(), fcntl.LOCK_UN)

    def test_admitted_writer_does_not_block_a_planned_watchdog_tick(self):
        original = self.journal.path.read_bytes()
        with self.admitted_writer():
            self.now += 60
            self.assertEqual("planned", self.runner.perform("tick")["phase"])
        self.assertEqual(original, self.journal.path.read_bytes())

    def test_second_controller_is_refused_while_writers_stay_admitted(self):
        with self.journal.acquire():
            with self.assertRaisesRegex(UnsafeCutover, "holds the cutover lock"):
                self.runner.perform("abort")
            with self.admitted_writer():
                pass

    def test_drain_writers_waits_for_admitted_writer_then_excludes_new_ones(self):
        with self.journal.acquire() as (state, lease):
            self.journal.write(state | {"generation": lease.generation})
            with self.admitted_writer():
                with self.assertRaisesRegex(UnsafeCutover, "did not drain"):
                    self.journal.drain_writers(lease, 0.05)
                with self.assertRaisesRegex(UnsafeCutover, "not been drained"):
                    lease.require_writers_drained()
            self.journal.drain_writers(lease, 1)
            lease.require_writers_drained()
            with self.assertRaises(OSError):
                with self.admitted_writer():
                    self.fail("A writer was admitted after the drain")
            lease.writers.close()
            with self.assertRaisesRegex(UnsafeCutover, "no longer excludes admitted writers"):
                lease.require_current()
        with self.admitted_writer():
            pass

    def test_watchdog_tick_waits_for_the_controller_lock(self):
        held, release = threading.Event(), threading.Event()

        def other_controller():
            with self.journal.acquire():
                held.set()
                release.wait(5)

        thread = threading.Thread(target=other_controller)
        thread.start()
        self.addCleanup(thread.join, 5)
        self.addCleanup(release.set)
        self.assertTrue(held.wait(5))
        threading.Timer(0.3, release.set).start()
        started = time.monotonic()
        self.now += 60
        self.assertEqual("planned", self.runner.perform("tick")["phase"])
        self.assertGreaterEqual(time.monotonic() - started, 0.25)

    def test_prepare_promotion_and_exact_marker_order(self):
        self.ready()

        def check_persisted_marker():
            state = self.journal.read()
            self.assertTrue(state["writes_possible"])
            self.assertEqual("writes_possible", state["phase"])
            self.assertEqual("promote", state["pending"]["action"])
            self.assertFalse(state["pending"]["before"]["mysql_writable"])
            self.assertFalse(self.adapter.observe().mysql_business_activity)

        self.adapter.before_promote = check_persisted_marker
        state = self.runner.perform("promote", self.runner.approval_request("promote"))
        self.assertEqual("mysql_authoritative", state["phase"])
        self.assertTrue(self.adapter.observe().sealed("mysql"))
        self.adapter.reopen()
        self.assertTrue(self.adapter.observe().mysql_live())
        with self.assertRaisesRegex(UnsafeCutover, "writes may exist"):
            self.runner.perform("abort")
        self.assertEqual(0, self.adapter.sqlite_restores)

    def test_reboot_after_mysql_reopen_keeps_mysql_authority_for_the_watchdog(self):
        self.ready()
        committed = self.runner.perform("promote", self.runner.approval_request("promote"))
        self.adapter.reopen()
        self.reboot(admissions_open=False, workers_stopped=True, mysql_business_activity=True)
        proof = self.journal.directory / "fixture-authority.json"
        previous_boot_proof = proof.read_bytes()
        for _ in range(2):
            self.now += 60
            self.assertEqual(committed, self.new_runner().perform("tick"))
        with self.assertRaisesRegex(UnsafeCutover, "writes may exist"):
            self.new_runner().perform("abort")
        self.assertEqual(committed, self.journal.read())
        self.assertFalse(self.adapter.observe().admissions_open)
        self.assertEqual(previous_boot_proof, proof.read_bytes())
        with self.assertRaisesRegex(guard.Refused, "this boot"):
            guard.verify(self.journal.encode(self.adapter.policy()), self.journal.path.read_bytes(),
                         proof.read_bytes(), machine_sha=self.adapter.MACHINE_SHA256,
                         boot_id=self.adapter.BOOT_ID, action="queue-worker", expected_engine="mysql")
        self.assertEqual(0, self.adapter.sqlite_restores)

    def test_failed_mysql_reopen_after_smoke_keeps_mysql_authority(self):
        self.ready()
        committed = self.runner.perform("promote", self.runner.approval_request("promote"))
        # The write smoke ran on this boot. Reopen failed while workers were still draining.
        observation = replace(self.adapter.observe(), workers_stopped=False, mysql_business_activity=True)
        self.journal.write_record("fixture.json", asdict(observation))
        proof = self.journal.directory / "fixture-authority.json"
        proof.unlink()
        self.assertEqual(committed, self.new_runner().perform("tick"))
        self.assertEqual(committed, self.journal.read())
        self.assertEqual(observation, self.adapter.observe())
        self.assertFalse(proof.exists())
        self.assertEqual(0, self.adapter.sqlite_restores)

    def test_reverted_engine_closed_gate_or_writable_sqlite_still_escalate(self):
        self.ready()
        committed = self.runner.perform("promote", self.runner.approval_request("promote"))
        held = replace(self.adapter.observe(), mysql_business_activity=True)
        for change in ({"engine": "sqlite"}, {"mysql_writable": False}, {"sqlite_writable": True}):
            with self.subTest(change=change):
                self.journal.write(committed)
                self.journal.write_record("fixture.json", asdict(replace(held, **change)))
                with self.assertRaisesRegex(UnsafeCutover, "writes may exist"):
                    self.new_runner().perform("tick")
                self.assertEqual("recovery_required", self.journal.read()["phase"])
                self.assertEqual(0, self.adapter.sqlite_restores)

    def test_standby_rejects_wrong_artifact_or_target(self):
        approval = self.runner.approval_request("hold")
        for override in [{"artifact_sha256": "a" * 64},
                         {"target_uuid": "a6a22173-806c-4d38-90ee-07bde9aa5ecd"}]:
            self.adapter._identity = replace(self.identity, **override)
            with self.assertRaisesRegex(UnsafeCutover, "identity differs"):
                self.new_runner().perform("hold", approval)
        self.assertTrue(self.adapter.observe().sqlite_live())

    def test_identity_refusal_names_the_fields_that_differ_and_no_value(self):
        approval = self.runner.approval_request("hold")
        changes = {
            "run": {"run_id": "other-run"}, "release": {"release_sha": "9" * 40},
            "artifact": {"artifact_sha256": "a" * 64}, "source": {"source_sha256": "b" * 64},
            "configuration": {"config_sha256": "c" * 64}, "target": {"target_uuid": "a6a22173-806c-4d38-90ee-07bde9aa5ecd"},
        }
        pinned = list(asdict(self.identity).values())

        def refusal(**change):
            self.adapter._identity = replace(self.identity, **change)
            with self.assertRaises(UnsafeCutover) as refused:
                self.new_runner().perform("hold", approval)
            message = str(refused.exception)
            for value in [*change.values(), *pinned]:
                self.assertNotIn(value, message, "an identity value in the refusal")
            named = re.fullmatch(r"Observed identity differs from the pin in: ([a-z, ]+)\.(?: (.*))?", message)
            self.assertIsNotNone(named, message)
            return named.group(1).split(", "), named.group(2)

        for name, change in changes.items():
            with self.subTest(name):
                fields, advice = refusal(**change)
                self.assertEqual([name], fields)
                if name == "artifact":
                    # The runbook's bytecode rule (A2-1): repository Python without -B writes __pycache__.
                    self.assertRegex(advice, r"__pycache__.*python3 -B.*runbook|runbook.*python3 -B.*__pycache__")
                else:
                    self.assertIsNone(advice)
        fields, advice = refusal(source_sha256="b" * 64, artifact_sha256="a" * 64)
        self.assertEqual(["artifact", "source"], fields)
        self.assertIn("__pycache__", advice)
        self.assertTrue(self.adapter.observe().sqlite_live())
        self.assertEqual(0, self.adapter.close_calls)

    def test_artifact_advice_gives_the_runbooks_find_over_every_fingerprinted_tree(self):
        # The runbook's rule (D02, review A2-1) gives one find over every tree the source fingerprint hashes, and
        # the refusal repeats it: a cache anywhere in those trees changes the artifact, not only under scripts/
        # and resources/iguide-lab/ (review W2V-4).
        import importlib.util

        import runner

        app = Path(__file__).resolve().parents[2]
        spec = importlib.util.spec_from_file_location(
            "fingerprint_trees", app / "scripts" / "cutover" / "production" / "backend_source_fingerprint.py")
        fingerprint = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(fingerprint)
        trees = sorted({tree.split("/")[0] for tree in fingerprint.TREES})
        runbook = (app / "docs" / "mysql-production-cutover.md").read_text(encoding="utf-8")
        rule = re.search(r"Every repository Python run from the app tree.*?remove what it prints\.", runbook, re.DOTALL)
        self.assertIsNotNone(rule, "the runbook's bytecode rule moved")
        find = re.search(r"`cd <app_root> && find ([a-z ]+) -name __pycache__`", rule.group(0))
        self.assertIsNotNone(find, "the runbook's bytecode rule gives no find over the app root")
        self.assertEqual(trees, sorted(find.group(1).split()))
        self.assertIn(find.group(0).strip("`"), runner.ARTIFACT_ADVICE)
        # The lab services rely on the environment variable, not -B (W2F-1); both are named.
        self.assertIn("PYTHONDONTWRITEBYTECODE=1", rule.group(0))
        self.assertIn("PYTHONDONTWRITEBYTECODE=1", runner.ARTIFACT_ADVICE)

    def test_approval_binds_run_gate_phase_and_expiry(self):
        approval = self.runner.approval_request("hold")
        for changed in [
            replace(approval, action="promote"), replace(approval, identity_sha256="7" * 64),
            replace(approval, evidence_sha256="8" * 64), replace(approval, challenge="different"),
        ]:
            with self.assertRaisesRegex(UnsafeCutover, "phase approval"):
                self.runner.perform("hold", changed)
        self.now = approval.expires_at
        with self.assertRaisesRegex(UnsafeCutover, "phase approval"):
            self.runner.perform("hold", approval)
        self.assertTrue(self.adapter.observe().sqlite_live())
        self.assertEqual(0, self.adapter.close_calls)

    def test_crash_before_or_after_freeze_effect_records_undo_intent(self):
        self.adapter.before_freeze = self.crash
        with self.assertRaises(SimulatedPowerLoss):
            self.freeze()
        state = self.journal.read()
        self.assertEqual("freeze", state["pending"]["action"])
        # The hold came first, so admissions were already closed before the freeze.
        self.assertFalse(state["pending"]["before"]["admissions_open"])
        self.assertFalse(self.adapter.observe().admissions_open)
        self.assertEqual("sqlite_authoritative", self.new_runner().perform("abort")["phase"])

    def test_external_pre_close_is_refused_or_adopted_with_clock(self):
        approval = self.runner.approval_request("hold")
        observed = replace(self.adapter.observe(), admissions_open=False, deployment_fenced=True)
        self.journal.write_record("fixture.json", asdict(observed))
        original = self.journal.read()

        # Default: refuse loudly and change nothing.
        with self.assertRaisesRegex(UnsafeCutover, "closed outside the cutover journal"):
            self.runner.perform("hold", approval)
        self.assertEqual(original, self.journal.read())
        self.assertEqual(0, self.adapter.close_calls)

        # Explicit adoption journals the hold and starts the outage clock.
        adopting = self.new_runner(adopt_external_hold=True)
        state = adopting.perform("hold", approval)
        self.assertEqual("holding", state["phase"])
        self.assertEqual(self.now, state["started_at"])
        self.assertTrue(state["hold"]["adopted_external_close"])
        self.assertFalse(state["history"][-1]["before"]["admissions_open"])
        self.now += Runner.DECISION_SECONDS
        self.assertEqual("sqlite_authoritative", self.new_runner().perform("tick")["phase"])
        self.adapter.reopen()
        self.assertTrue(self.adapter.observe().sqlite_live())

    def test_hold_is_first_journaled_step_and_starts_outage_clock(self):
        def nothing_closed_yet_but_hold_is_durable():
            state = self.journal.read()
            self.assertEqual("holding", state["phase"])
            self.assertEqual(self.now, state["started_at"])
            self.assertEqual("hold", state["pending"]["action"])
            self.assertEqual(state["hold"]["hold_id"], state["pending"]["hold_id"])
            self.assertEqual(self.adapter.BOOT_ID, state["pending"]["boot_id"])
            self.assertTrue(state["pending"]["before"]["admissions_open"])
            self.assertTrue(self.adapter.observe().sqlite_live())
            with self.assertRaises(guard.Refused):
                guard.authority(self.adapter.policy(), state, "queue-worker")

        self.adapter.before_close = nothing_closed_yet_but_hold_is_durable
        state = self.hold()
        self.assertEqual("holding", state["phase"])
        self.assertIsNone(state["pending"])
        self.assertEqual(["hold"], [entry["action"] for entry in state["history"]])
        observed = self.adapter.observe()
        self.assertFalse(observed.admissions_open)
        self.assertTrue(observed.deployment_fenced)
        self.assertTrue(observed.sqlite_writable)
        with self.assertRaisesRegex(guard.Refused, "holds all admissions"):
            guard.authority(self.adapter.policy(), state, "web-writer")

    def test_long_job_queues_must_be_predrained_before_the_hold(self):
        original = self.journal.read()
        self.adapter.long_jobs = False
        with self.assertRaisesRegex(UnsafeCutover, "Long-job queues"):
            self.hold()
        self.assertEqual(original, self.journal.read())
        self.assertEqual(0, self.adapter.close_calls)
        # The owner can choose to drain long jobs inside the clock instead.
        self.assertEqual("holding", self.hold(self.new_runner(require_long_job_predrain=False))["phase"])

    def test_crash_after_hold_intent_before_close_rolls_back_on_tick(self):
        self.adapter.before_close = self.crash
        with self.assertRaises(SimulatedPowerLoss):
            self.hold()
        state = self.journal.read()
        self.assertEqual(("holding", "hold"), (state["phase"], state["pending"]["action"]))
        self.assertTrue(self.adapter.observe().admissions_open)

        self.adapter.before_close = lambda: None
        self.now += 60
        ticked = self.new_runner().perform("tick")
        self.assertEqual("hold", ticked["pending"]["action"])
        self.assertFalse(self.adapter.observe().admissions_open)

        self.now = state["started_at"] + Runner.DECISION_SECONDS
        self.assertEqual("sqlite_authoritative", self.new_runner().perform("tick")["phase"])
        self.adapter.reopen()
        self.assertTrue(self.adapter.observe().sqlite_live())

    def test_crash_during_drain_after_hold_rolls_back_at_45_minutes_from_hold(self):
        hold_at = self.hold()["started_at"]
        self.now += 10 * 60
        self.adapter.before_drain = self.crash
        with self.assertRaises(SimulatedPowerLoss):
            self.runner.perform("freeze")
        self.adapter.before_drain = lambda: None
        state = self.journal.read()
        self.assertEqual(("holding", "freeze"), (state["phase"], state["pending"]["action"]))

        self.now = hold_at + Runner.DECISION_SECONDS - 1
        self.assertEqual("holding", self.new_runner().perform("tick")["phase"])
        self.assertFalse(self.adapter.observe().admissions_open)
        self.now = hold_at + Runner.DECISION_SECONDS
        self.assertEqual("sqlite_authoritative", self.new_runner().perform("tick")["phase"])
        self.assertEqual("interrupted", self.journal.read()["history"][-2]["phase"])

    def test_freeze_deadline_counts_from_hold_not_freeze(self):
        hold_at = self.hold()["started_at"]
        self.now = hold_at + 30 * 60
        frozen = self.runner.perform("freeze")
        self.assertEqual(("frozen", hold_at), (frozen["phase"], frozen["started_at"]))
        self.assertIn("writers_drained_ms", frozen["drain"])
        self.now = hold_at + 31 * 60
        self.runner.perform("prepare")
        self.now = hold_at + 44 * 60
        approval = self.runner.approval_request("promote")
        self.assertEqual(hold_at + Runner.DECISION_SECONDS, approval.expires_at)
        self.now = hold_at + Runner.DECISION_SECONDS
        self.assertEqual("sqlite_authoritative", self.new_runner().perform("tick")["phase"])

    def test_reboot_during_hold_reasserts_hold_and_redrains_scheduler(self):
        self.hold()
        # The freeze drained the scheduler under the old boot, then power was lost.
        self.adapter.before_freeze = self.crash
        with self.assertRaises(SimulatedPowerLoss):
            self.runner.perform("freeze")
        self.adapter.before_freeze = lambda: None
        self.assertEqual(FixtureAdapter.BOOT_ID, self.journal.read()["scheduler_drain"]["boot_id"])

        self.reboot(admissions_open=True, workers_stopped=False, deployment_fenced=False)
        self.now += 60
        state = self.new_runner().perform("tick")
        observed = self.adapter.observe()
        self.assertFalse(observed.admissions_open)
        self.assertTrue(observed.deployment_fenced)
        self.assertEqual(REBOOTED, state["hold"]["boot_id"])
        self.assertEqual(REBOOTED, state["scheduler_drain"]["boot_id"])
        self.assertEqual("reassert_hold", state["history"][-1]["action"])
        self.assertEqual("freeze", state["pending"]["action"])

        aborted = self.new_runner().perform("abort")
        self.assertEqual("sqlite_authoritative", aborted["phase"])
        proof = json.loads((self.journal.directory / "fixture-authority.json").read_bytes())
        self.assertEqual(REBOOTED, proof["boot_id"])

    def test_crash_after_journal_handoff_from_holding_to_frozen(self):
        self.adapter.after_freeze = self.crash
        with self.assertRaises(SimulatedPowerLoss):
            self.freeze()
        self.adapter.after_freeze = lambda: None
        state = self.journal.read()
        self.assertEqual(("holding", "freeze"), (state["phase"], state["pending"]["action"]))
        self.assertTrue(self.adapter.observe().frozen())
        self.now += 60
        self.assertEqual("holding", self.new_runner().perform("tick")["phase"])
        self.assertTrue(self.adapter.observe().frozen())
        self.assertEqual("sqlite_authoritative", self.new_runner().perform("abort")["phase"])

    def test_reboot_after_handoff_to_frozen_redrains_scheduler_before_prepare(self):
        self.assertEqual("frozen", self.freeze()["phase"])
        self.reboot()
        with self.assertRaisesRegex(UnsafeCutover, "scheduler hold"):
            self.new_runner().perform("prepare")
        self.assertEqual("frozen", self.journal.read()["phase"])
        self.now += 60
        state = self.new_runner().perform("tick")
        self.assertEqual(REBOOTED, state["scheduler_drain"]["boot_id"])
        self.assertEqual("ready", self.new_runner().perform("prepare")["phase"])

    def test_hold_retry_is_idempotent(self):
        self.adapter.after_close = self.crash
        with self.assertRaises(SimulatedPowerLoss):
            self.hold()
        self.adapter.after_close = lambda: None
        interrupted = self.journal.read()
        self.assertEqual("hold", interrupted["pending"]["action"])
        self.assertFalse(self.adapter.observe().admissions_open)

        self.now += 30
        retried = self.new_runner().perform("hold")
        self.assertEqual(("holding", None), (retried["phase"], retried["pending"]))
        self.assertEqual(interrupted["started_at"], retried["started_at"])
        self.assertEqual(interrupted["hold"], retried["hold"])
        self.assertEqual(["hold"], [entry["action"] for entry in retried["history"]])
        self.assertEqual(2, self.adapter.close_calls)
        self.now += 30
        self.assertEqual("holding", self.new_runner().perform("tick")["phase"])
        self.assertEqual(2, self.adapter.close_calls)
        with self.assertRaisesRegex(UnsafeCutover, "invalid for the current phase"):
            self.new_runner().perform("hold")

    def test_hold_approval_binds_challenge_and_expires(self):
        approval = self.runner.approval_request("hold")
        self.assertEqual(self.journal.read()["challenge"], approval.challenge)
        self.assertEqual(self.now + Runner.APPROVAL_SECONDS, approval.expires_at)
        self.now = approval.expires_at
        with self.assertRaisesRegex(UnsafeCutover, "phase approval"):
            self.runner.perform("hold", approval)
        self.now -= 1
        with self.assertRaisesRegex(UnsafeCutover, "phase approval"):
            self.runner.perform("hold", None)
        state = self.runner.perform("hold", approval)
        self.assertNotEqual(approval.challenge, state["challenge"])
        self.assertEqual(asdict(approval), state["history"][0]["approval"])
        # By default the approved hold also authorizes the freeze.
        with self.assertRaisesRegex(UnsafeCutover, "approved hold authorizes the freeze"):
            self.runner.approval_request("freeze")
        with self.assertRaisesRegex(UnsafeCutover, "approved hold authorizes the freeze"):
            self.runner.perform("freeze", approval)
        self.assertEqual("holding", self.journal.read()["phase"])

    def test_separate_freeze_approval_binds_the_hold(self):
        runner = self.new_runner(separate_freeze_approval=True)
        self.hold(runner)
        with self.assertRaisesRegex(UnsafeCutover, "phase approval"):
            runner.perform("freeze")
        approval = runner.approval_request("freeze")
        with self.assertRaisesRegex(UnsafeCutover, "phase approval"):
            runner.perform("freeze", replace(approval, evidence_sha256="8" * 64))
        self.assertEqual("frozen", runner.perform("freeze", approval)["phase"])

    def test_abort_from_holding_reopens_only_sqlite(self):
        self.hold()
        state = self.runner.perform("abort")
        self.assertEqual("sqlite_authoritative", state["phase"])
        self.assertEqual(["hold", "abort"], [entry["action"] for entry in state["history"]])
        self.assertTrue(self.adapter.observe().sealed("sqlite"))
        proof = (self.journal.directory / "fixture-authority.json").read_bytes()
        policy, committed = self.journal.encode(self.adapter.policy()), self.journal.path.read_bytes()
        host = {"machine_sha": self.adapter.MACHINE_SHA256, "boot_id": self.adapter.BOOT_ID}
        guard.verify(policy, committed, proof, **host, action="queue-worker", expected_engine="sqlite")
        with self.assertRaisesRegex(guard.Refused, "not authoritative"):
            guard.verify(policy, committed, proof, **host, action="queue-worker", expected_engine="mysql")
        self.adapter.reopen()
        self.assertTrue(self.adapter.observe().sqlite_live())
        self.assertFalse(self.adapter.observe().mysql_writable)

    def test_reboot_after_reopened_rollback_keeps_sqlite_authority_for_the_watchdog(self):
        self.freeze()
        self.runner.perform("abort")
        self.adapter.reopen()
        # A reboot empties /run: ingress is closed, wrapped writers are refused,
        # and the deploy fence stays lifted. SQLite is still the only writable engine.
        self.reboot(admissions_open=False, workers_stopped=True)
        for _ in range(2):
            self.now += 60
            state = self.new_runner().perform("tick")
            self.assertEqual(("sqlite_authoritative", False, None),
                             (state["phase"], state["writes_possible"], state["pending"]))
        self.assertEqual("sqlite_authoritative", self.journal.read()["phase"])
        with self.assertRaisesRegex(UnsafeCutover, "cannot be re-run"):
            self.new_runner().perform("hold")
        # A read-only SQLite still cannot be verified as the authority.
        self.reboot(admissions_open=False, workers_stopped=True, sqlite_writable=False)
        self.now += 60
        with self.assertRaisesRegex(UnsafeCutover, "could not be verified"):
            self.new_runner().perform("tick")
        self.assertEqual("recovery_required", self.journal.read()["phase"])

    def test_freeze_drains_for_the_freeze_and_the_abort_for_the_rollback(self):
        report = {"outcome": "drained", "active_processes": 0, "waited_seconds": 0.5}
        self.adapter.drain_report = lambda purpose: {"purpose": purpose, **report}
        frozen = self.freeze()
        self.assertEqual(["freeze"], self.adapter.drain_purposes)
        # The adapter's FPM drain record is journalled with the action it belongs to.
        self.assertEqual(("freeze", {"purpose": "freeze", **report}),
                         (frozen["history"][-1]["action"], frozen["history"][-1]["fpm_drain"]))
        aborted = self.runner.perform("abort")
        self.assertEqual(["freeze", "rollback"], self.adapter.drain_purposes)
        self.assertEqual(("abort", {"purpose": "rollback", **report}),
                         (aborted["history"][-1]["action"], aborted["history"][-1]["fpm_drain"]))
        self.assertEqual(aborted, self.journal.read())

    def test_refused_freeze_drain_still_lets_the_rollback_drain(self):
        def refuse(purpose):
            if purpose == "freeze":
                raise UnsafeCutover("The FPM drain could not watch the pool")

        self.adapter.drain_refusal = refuse
        self.adapter.drain_report = lambda purpose: {"purpose": purpose, "outcome": "unmonitored",
                                                     "problem": "no status page"}
        self.hold()
        with self.assertRaisesRegex(UnsafeCutover, "could not watch the pool"):
            self.runner.perform("freeze")
        state = self.journal.read()
        self.assertEqual(("holding", "freeze"), (state["phase"], state["pending"]["action"]))
        self.assertNotIn("fpm_drain", state["pending"])
        aborted = self.new_runner().perform("abort")
        self.assertEqual("sqlite_authoritative", aborted["phase"])
        self.assertEqual(["freeze", "rollback"], self.adapter.drain_purposes)
        self.assertEqual({"purpose": "rollback", "outcome": "unmonitored", "problem": "no status page"},
                         aborted["history"][-1]["fpm_drain"])

    def test_drain_takes_a_freeze_or_rollback_purpose(self):
        # An adapter without FPM returns no record, and the runner journals none.
        frozen = self.freeze()
        self.assertNotIn("fpm_drain", frozen["history"][-1])
        with self.journal.acquire() as (state, lease):
            self.journal.write(state | {"generation": lease.generation})
            with self.assertRaises(TypeError):
                FixtureAdapter.drain(self.adapter, lease, 0)
            for purpose in ("", "Freeze", "reopen", None):
                with self.subTest(purpose=purpose), self.assertRaisesRegex(UnsafeCutover, "purpose"):
                    FixtureAdapter.drain(self.adapter, lease, 0, purpose=purpose)

    def test_drain_timeout_keeps_admissions_closed(self):
        hold_at = self.hold()["started_at"]
        with self.admitted_writer():
            with self.assertRaisesRegex(UnsafeCutover, "did not drain"):
                self.new_runner(max_drain_seconds=0.05).perform("freeze")
            state = self.journal.read()
            self.assertEqual(("holding", "freeze"), (state["phase"], state["pending"]["action"]))
            observed = self.adapter.observe()
            self.assertFalse(observed.admissions_open)
            self.assertFalse(observed.frozen())
            with self.assertRaisesRegex(guard.Refused, "unfinished action"):
                guard.authority(self.adapter.policy(), state, "web-writer")
        self.now = hold_at + Runner.DECISION_SECONDS
        self.assertEqual("sqlite_authoritative", self.new_runner().perform("tick")["phase"])

    def test_scheduler_drain_blocks_snapshot_while_background_permit_remains(self):
        self.freeze()
        path = "fixture-scheduler-drain.json"
        drained = self.adapter.scheduler_drain()
        self.journal.write_record(path, asdict(replace(drained, unresolved_permits=1)))
        with self.assertRaisesRegex(UnsafeCutover, "zero unresolved permits"):
            self.runner.perform("prepare")
        self.assertEqual("frozen", self.journal.read()["phase"])
        self.assertEqual("sqlite", self.adapter.observe().engine)
        with self.assertRaisesRegex(UnsafeCutover, "zero unresolved permits"):
            self.runner.perform("abort")
        self.assertEqual(0, self.adapter.sqlite_restores)
        self.journal.write_record(path, asdict(drained))
        self.assertEqual("sqlite_authoritative", self.new_runner().perform("abort")["phase"])

    def test_scheduler_proof_binds_promotion_to_the_current_boot_and_hold(self):
        self.ready()
        approval = self.runner.approval_request("promote")
        drained = self.adapter.scheduler_drain()
        for changed in (
            replace(drained, boot_id="104565fc-e301-46b2-a6a4-bd0ace01ab5e"),
            replace(drained, hold_id="8fcf0bf4-906b-4610-b96b-ecbaa8087aae"),
            replace(drained, identity_sha256="a" * 64),
            replace(drained, admission_closed=False),
        ):
            self.journal.write_record("fixture-scheduler-drain.json", asdict(changed))
            with self.assertRaisesRegex(UnsafeCutover, "[Ss]cheduler hold"):
                self.runner.perform("promote", approval)
            self.assertFalse(self.journal.read()["writes_possible"])
            self.assertFalse(self.adapter.observe().mysql_writable)

    def test_interrupted_scheduler_close_can_establish_drain_for_abort(self):
        with patch.object(self.adapter, "hold_and_drain_scheduler", side_effect=SimulatedPowerLoss):
            with self.assertRaises(SimulatedPowerLoss):
                self.freeze()
        self.assertNotIn("scheduler_drain", self.journal.read())
        recovered = self.new_runner().perform("abort")
        self.assertEqual("sqlite_authoritative", recovered["phase"])
        self.assertEqual(0, recovered["scheduler_drain"]["unresolved_permits"])
        self.assertTrue(self.adapter.observe().sealed("sqlite"))

    def test_authority_is_held_if_scheduler_proof_changes_during_publication(self):
        self.ready()
        drained = self.adapter.scheduler_drain()

        def change_hold():
            changed = replace(drained, admission_closed=False)
            self.journal.write_record("fixture-scheduler-drain.json", asdict(changed))

        self.adapter.after_authority_publish = change_hold
        with self.assertRaisesRegex(UnsafeCutover, "scheduler hold"):
            self.runner.perform("promote", self.runner.approval_request("promote"))
        self.assertEqual("recovery_required", self.journal.read()["phase"])

    def test_standby_cannot_reverse_while_old_import_child_is_active(self):
        self.adapter.after_freeze = self.crash
        with self.assertRaises(SimulatedPowerLoss):
            self.freeze()
        self.assertTrue(self.adapter.observe().frozen())
        self.adapter.children_active = True
        with self.assertRaisesRegex(UnsafeCutover, "children"):
            self.new_runner().perform("abort")
        self.assertEqual(0, self.adapter.sqlite_restores)
        self.adapter.children_active = False
        self.assertEqual("sqlite_authoritative", self.new_runner().perform("abort")["phase"])
        self.assertEqual(["holding", "interrupted", "sqlite_authoritative"],
                         [entry["phase"] for entry in self.journal.read()["history"]])

    def test_timeout_from_start_reserves_time_for_abort(self):
        self.ready()
        self.now += 44 * 60
        approval = self.runner.approval_request("promote")
        self.assertEqual(self.journal.read()["started_at"] + 45 * 60, approval.expires_at)
        self.now = approval.expires_at
        result = self.new_runner().perform("tick")
        self.assertEqual("sqlite_authoritative", result["phase"])
        self.assertEqual(1, self.adapter.sqlite_restores)

    def test_hung_preparation_never_reaches_approval(self):
        self.freeze()
        self.adapter.after_prepare = lambda: setattr(self, "now", self.now + 46 * 60)
        with self.assertRaisesRegex(UnsafeCutover, "did not complete"):
            self.runner.perform("prepare")
        self.assertEqual("sqlite_authoritative", self.new_runner().perform("tick")["phase"])

    def test_clock_rollback_does_not_extend_the_window(self):
        self.ready()
        self.now -= 1
        self.assertEqual("sqlite_authoritative", self.new_runner().perform("tick")["phase"])

    def test_crash_before_first_mysql_effect_still_requires_recovery(self):
        self.ready()
        self.adapter.before_promote = self.crash
        with self.assertRaises(SimulatedPowerLoss):
            self.runner.perform("promote", self.runner.approval_request("promote"))
        self.assertTrue(self.journal.read()["writes_possible"])
        self.now += 60 * 60
        with self.assertRaisesRegex(UnsafeCutover, "writes may exist"):
            self.new_runner().perform("tick")
        self.assertEqual("recovery_required", self.journal.read()["phase"])
        self.assertEqual(0, self.adapter.sqlite_restores)

    def test_crash_after_mysql_effect_requires_reconciliation(self):
        self.ready()
        self.adapter.after_promote = self.crash
        with self.assertRaises(SimulatedPowerLoss):
            self.runner.perform("promote", self.runner.approval_request("promote"))
        self.assertTrue(self.adapter.observe().sealed("mysql"))
        with self.assertRaisesRegex(UnsafeCutover, "writes may exist"):
            self.new_runner().perform("abort")
        self.assertEqual(0, self.adapter.sqlite_restores)

    def test_power_loss_after_mysql_proof_before_commit_never_authorizes_or_reverts(self):
        self.ready()
        self.adapter.after_authority_publish = self.crash
        with self.assertRaises(SimulatedPowerLoss):
            self.runner.perform("promote", self.runner.approval_request("promote"))
        self.assertTrue(self.journal.read()["writes_possible"])
        self.assertEqual("promote", self.journal.read()["pending"]["action"])
        with self.assertRaisesRegex(UnsafeCutover, "unfinished"):
            self.adapter.reopen()
        with self.assertRaisesRegex(UnsafeCutover, "writes may exist"):
            self.new_runner().perform("tick")
        self.assertEqual("recovery_required", self.journal.read()["phase"])
        self.assertEqual(0, self.adapter.sqlite_restores)

    def test_power_loss_after_sqlite_proof_before_commit_requires_verified_abort_retry(self):
        self.ready()
        self.adapter.after_authority_publish = self.crash
        with self.assertRaises(SimulatedPowerLoss):
            self.runner.perform("abort")
        self.assertFalse(self.journal.read()["writes_possible"])
        self.assertEqual("abort", self.journal.read()["pending"]["action"])
        with self.assertRaisesRegex(UnsafeCutover, "unfinished"):
            self.adapter.reopen()
        self.adapter.after_authority_publish = lambda: None
        self.assertEqual("sqlite_authoritative", self.new_runner().perform("abort")["phase"])
        self.adapter.reopen()
        self.assertTrue(self.adapter.observe().sqlite_live())

    def test_full_disk_during_sqlite_authority_commit_keeps_admission_closed(self):
        self.ready()
        replace_file = os.replace

        def fail_authority_commit(temporary, target):
            if target == self.journal.path:
                candidate = Path(temporary).read_bytes()
                if json.loads(candidate)["phase"] == "sqlite_authoritative":
                    proof = (self.journal.directory / "fixture-authority.json").read_bytes()
                    self.assertEqual(self.adapter.authority_evidence("sqlite", candidate), json.loads(proof))
                    raise OSError(errno.ENOSPC, "Fixture journal device full")
            replace_file(temporary, target)

        with patch("runner.os.replace", side_effect=fail_authority_commit):
            with self.assertRaises(OSError) as error:
                self.runner.perform("abort")
        self.assertEqual(errno.ENOSPC, error.exception.errno)
        pending = self.journal.read()
        self.assertEqual("ready", pending["phase"])
        self.assertEqual("abort", pending["pending"]["action"])
        self.assertFalse(pending["writes_possible"])
        self.assertTrue(self.adapter.observe().sealed("sqlite"))
        self.assertEqual([], list(self.journal.directory.glob(".journal-*")))
        with self.assertRaisesRegex(UnsafeCutover, "unfinished"):
            self.adapter.reopen()

        self.assertEqual("sqlite_authoritative", self.new_runner().perform("abort")["phase"])
        self.adapter.reopen()
        self.assertTrue(self.adapter.observe().sqlite_live())

    def test_authority_proof_after_window_does_not_commit_mysql_authority(self):
        self.ready()
        started = self.journal.read()["started_at"]
        self.adapter.after_authority_publish = lambda: setattr(self, "now", started + self.runner.WINDOW_SECONDS)
        with self.assertRaisesRegex(UnsafeCutover, "maintained in time"):
            self.runner.perform("promote", self.runner.approval_request("promote"))
        self.assertEqual("recovery_required", self.journal.read()["phase"])
        self.assertTrue(self.journal.read()["writes_possible"])
        with self.assertRaises(UnsafeCutover):
            self.adapter.reopen()
        self.assertEqual(0, self.adapter.sqlite_restores)

    def test_abort_can_reconcile_crash_after_restore(self):
        self.ready()
        self.adapter.after_restore = self.crash
        with self.assertRaises(SimulatedPowerLoss):
            self.runner.perform("abort")
        self.assertEqual("abort", self.journal.read()["pending"]["action"])
        self.adapter.after_restore = lambda: None
        self.assertEqual("sqlite_authoritative", self.new_runner().perform("abort")["phase"])
        self.adapter.reopen()
        self.assertTrue(self.adapter.observe().sqlite_live())

    def test_failed_offsite_backup_blocks_readiness(self):
        self.freeze()
        self.adapter.backup_ok = False
        with self.assertRaisesRegex(UnsafeCutover, "off-site backup"):
            self.runner.perform("prepare")
        self.assertEqual("prepare", self.journal.read()["pending"]["action"])
        with self.assertRaises(UnsafeCutover):
            self.runner.approval_request("promote")
        self.assertEqual("sqlite_authoritative", self.runner.perform("abort")["phase"])

    def test_lost_backup_gate_after_approval_prevents_open(self):
        self.ready()
        approval = self.runner.approval_request("promote")
        self.adapter.backup_ok = False
        with self.assertRaisesRegex(UnsafeCutover, "off-site backup"):
            self.runner.perform("promote", approval)
        self.assertFalse(self.journal.read()["writes_possible"])
        self.assertFalse(self.adapter.observe().mysql_writable)

    def test_changed_snapshot_after_approval_prevents_open(self):
        self.ready()
        approval = self.runner.approval_request("promote")
        self.adapter.evidence_override = {"snapshot_sha256": "9" * 64}
        with self.assertRaisesRegex(UnsafeCutover, "evidence changed"):
            self.runner.perform("promote", approval)
        self.assertFalse(self.journal.read()["writes_possible"])
        self.assertFalse(self.adapter.observe().mysql_writable)

    def test_observed_mysql_activity_poison_marks_prewrite_journal(self):
        self.ready()
        self.journal.write_record("fixture.json", {
            **self.journal.read()["history"][-1]["after"], "mysql_business_activity": True,
        })
        with self.assertRaisesRegex(UnsafeCutover, "writes may exist"):
            self.runner.perform("abort")
        self.assertEqual("recovery_required", self.journal.read()["phase"])
        self.assertEqual(0, self.adapter.sqlite_restores)

    def test_active_lease_excludes_other_callers_and_old_lease_fails(self):
        with self.journal.acquire():
            with self.assertRaisesRegex(UnsafeCutover, "holds the cutover lock"):
                self.freeze()
        self.freeze()
        with self.assertRaisesRegex(UnsafeCutover, "no longer owns"):
            self.adapter.last_lease.require_current()


if __name__ == "__main__":
    unittest.main()
