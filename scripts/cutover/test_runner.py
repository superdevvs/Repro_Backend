import tempfile
import unittest
from dataclasses import replace
from pathlib import Path

from rehearse import FixtureAdapter
from runner import Identity, Journal, Runner, UnsafeCutover


class SimulatedPowerLoss(BaseException):
    pass


class FaultAdapter(FixtureAdapter):
    def __init__(self, journal, identity):
        super().__init__(journal, identity)
        self.before_freeze = lambda: None
        self.after_freeze = lambda: None
        self.before_promote = lambda: None
        self.after_promote = lambda: None
        self.after_prepare = lambda: None
        self.after_restore = lambda: None
        self.children_active = False
        self.backup_ok = True
        self.evidence_override = {}
        self.sqlite_restores = 0
        self.last_lease = None

    def freeze(self, lease, deadline):
        self.last_lease = lease
        self.before_freeze()
        super().freeze(lease, deadline)
        self.after_freeze()

    def prepare(self, lease, deadline):
        super().prepare(lease, deadline)
        self.after_prepare()

    def enable_mysql(self, lease, deadline):
        self.before_promote()
        super().enable_mysql(lease, deadline)
        self.after_promote()

    def restore_sqlite(self, lease, deadline):
        self.sqlite_restores += 1
        super().restore_sqlite(lease, deadline)
        self.after_restore()

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

    def new_runner(self):
        return Runner(self.journal, self.identity, self.adapter, lambda: self.now)

    def freeze(self):
        self.runner.perform("freeze", self.runner.approval_request("freeze"))

    def ready(self):
        self.freeze()
        self.runner.perform("prepare")

    @staticmethod
    def crash():
        raise SimulatedPowerLoss()

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
        self.assertTrue(self.adapter.observe().mysql_live())
        with self.assertRaisesRegex(UnsafeCutover, "writes may exist"):
            self.runner.perform("abort")
        self.assertEqual(0, self.adapter.sqlite_restores)

    def test_standby_rejects_wrong_artifact_or_target(self):
        approval = self.runner.approval_request("freeze")
        for override in [{"artifact_sha256": "a" * 64},
                         {"target_uuid": "a6a22173-806c-4d38-90ee-07bde9aa5ecd"}]:
            self.adapter._identity = replace(self.identity, **override)
            with self.assertRaisesRegex(UnsafeCutover, "identity differs"):
                self.new_runner().perform("freeze", approval)
        self.assertTrue(self.adapter.observe().sqlite_live())

    def test_approval_binds_run_gate_phase_and_expiry(self):
        approval = self.runner.approval_request("freeze")
        for changed in [
            replace(approval, action="promote"), replace(approval, identity_sha256="7" * 64),
            replace(approval, evidence_sha256="8" * 64), replace(approval, challenge="different"),
        ]:
            with self.assertRaisesRegex(UnsafeCutover, "phase approval"):
                self.runner.perform("freeze", changed)
        self.now = approval.expires_at
        with self.assertRaisesRegex(UnsafeCutover, "phase approval"):
            self.runner.perform("freeze", approval)
        self.assertTrue(self.adapter.observe().sqlite_live())

    def test_crash_before_or_after_freeze_effect_records_undo_intent(self):
        self.adapter.before_freeze = self.crash
        with self.assertRaises(SimulatedPowerLoss):
            self.freeze()
        state = self.journal.read()
        self.assertTrue(state["pending"]["before"]["admissions_open"])
        self.assertTrue(self.adapter.observe().sqlite_live())
        self.assertEqual("sqlite_authoritative", self.new_runner().perform("abort")["phase"])

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
        self.assertEqual("interrupted", self.journal.read()["history"][0]["phase"])

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
        self.assertTrue(self.adapter.observe().mysql_business_activity)
        with self.assertRaisesRegex(UnsafeCutover, "writes may exist"):
            self.new_runner().perform("abort")
        self.assertEqual(0, self.adapter.sqlite_restores)

    def test_abort_can_reconcile_crash_after_restore(self):
        self.ready()
        self.adapter.after_restore = self.crash
        with self.assertRaises(SimulatedPowerLoss):
            self.runner.perform("abort")
        self.assertEqual("abort", self.journal.read()["pending"]["action"])
        self.adapter.after_restore = lambda: None
        self.assertEqual("sqlite_authoritative", self.new_runner().perform("abort")["phase"])
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
