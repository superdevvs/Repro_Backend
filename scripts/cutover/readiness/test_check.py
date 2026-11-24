import json
import subprocess
import sys
import tempfile
import unittest
from dataclasses import asdict
from pathlib import Path

from scripts.cutover.readiness import check
from scripts.cutover.runner import Identity, digest


def readiness_fixture():
    identity = asdict(Identity("review-fixture", "a" * 40, "b" * 64, "c" * 64,
                               "d" * 64, "f1d53bff-40ce-46ba-a35b-4591b2be65b7"))
    policy = {
        "version": 1, "identity": identity, "critical_endpoints": ["book", "invoice"],
        "host_profile_sha256": "e" * 64, "workload_sha256": "f" * 64,
        "operations": {
            "destination_sha256": "5" * 64, "monitor_profile_sha256": "6" * 64,
            "phone_recipient_sha256": "7" * 64, "window_end_unix_s": 1_800_000_000, "interval_s": 120,
            "evidence_valid_until_unix_s": 1_800_003_600,
            "backup_health_report_sha256": "8" * 64, "offsite_retention_report_sha256": "9" * 64,
            "external_alerts_report_sha256": "a" * 64, "point_in_time_drills_report_sha256": "b" * 64,
        },
    }
    operations = policy["operations"]

    def report(kind, scope):
        return {"identity_sha256": digest(identity), "report_sha256": operations[f"{kind}_report_sha256"],
                scope: operations[scope]}

    receipt = {
        "version": 1, "identity": identity.copy(),
        "prerequisites": {
            "identity_sha256": digest(identity), "report_sha256": "1" * 64,
            **dict.fromkeys(check.PREREQUISITES, True),
        },
        "offsite_recovery": {
            "identity_sha256": digest(identity), "report_sha256": "2" * 64,
            **dict.fromkeys(check.RECOVERY_PROOFS, True),
            "database_recovery_ms": 60 * check.MINUTE,
            "offsite_database_data_loss_ms": 5 * check.MINUTE,
        },
        "backup_health": report("backup_health", "destination_sha256") | {
            "monitor_profile_sha256": operations["monitor_profile_sha256"], "external_observer_verified": True,
            "window_start_unix_s": operations["window_end_unix_s"] - check.BACKUP_WINDOW_SECONDS,
            "window_end_unix_s": operations["window_end_unix_s"], "covered_duration_s": check.BACKUP_WINDOW_SECONDS,
            "distinct_scheduled_observations": check.BACKUP_WINDOW_SECONDS // operations["interval_s"],
            "max_snapshot_age_s": check.BACKUP_MAX_AGE_SECONDS - 1,
            "duration_with_lag_at_most_five_minutes_s": check.BACKUP_WINDOW_SECONDS * 99 // 100,
        },
        "offsite_retention": report("offsite_retention", "destination_sha256") | dict.fromkeys((
            "exact_provider_policy_verified", "configured_writer_credential_verified", "writer_overwrite_blocked",
            "writer_delete_blocked", "independent_lock_delete_blocked", "probe_bytes_unchanged",
        ), True),
        "external_alerts": report("external_alerts", "monitor_profile_sha256") | {
            "phone_recipient_sha256": operations["phone_recipient_sha256"],
            **{
                milestone: {"report_sha256": str(index) * 64, "cases": {
                    name: {"condition_tested": True, "phone_delivery_verified": True, "delivery_ms": limit,
                           "delivery_receipt_sha256": f"{index*100 + sequence:064x}"}
                    for sequence, (name, limit) in enumerate(check.ALERT_LIMITS_MS.items())
                }} for index, milestone in enumerate(check.ALERT_MILESTONES, 1)
            },
        },
        "point_in_time_drills": report("point_in_time_drills", "destination_sha256") | {
            "drills": [{"report_sha256": str(index) * 64, "offsite_only": True, "independent_restore": True,
                        "contiguous_binlog_chain": True, "chosen_transaction_verified": True,
                        "database_recovery_ms": 30 * check.MINUTE, "absolute_heartbeat_target_delta_ms": check.MINUTE,
                        "planted_bad_write_excluded": index == 3} for index in (3, 4)],
        },
        "dress_rehearsals": [
            {
                "id": f"dress-{index}", "report_sha256": str(index) * 64, "snapshot_sha256": str(index + 2) * 64,
                "identity_sha256": digest(identity),
                "host_profile_sha256": policy["host_profile_sha256"],
                "workload_sha256": policy["workload_sha256"],
                "peak_and_surge_profile_completed": True,
                **check.TIMINGS,
                "lost_acknowledged_writes": 0, "duplicate_business_effects": 0,
                "peak_queue_start_depth": 4, "peak_queue_end_depth": 4,
                "endpoint_p95_us": {
                    "book": {"sqlite": 1_000, "mysql": 1_100},
                    "invoice": {"sqlite": 840, "mysql": 795},
                },
                "sqlite_writes": {"attempts": 100, "contended_attempts": 10, "p95_us": 1_000},
                "mysql_writes": {"attempts": 200, "contended_attempts": 10, "p95_us": 900},
            }
            for index in (3, 4)
        ],
    }
    for kind in check.OPERATIONS_REPORTS:
        operations[f"{kind}_summary_sha256"] = digest(receipt[kind])
    receipt["policy_sha256"] = digest(policy)
    return policy, receipt


class ReadinessTests(unittest.TestCase):
    def setUp(self):
        self.policy, self.receipt = readiness_fixture()
        self.first, self.second = self.receipt["dress_rehearsals"]
        self.now = self.policy["operations"]["window_end_unix_s"] + 30

    def repin_summary(self, kind):
        self.policy["operations"][f"{kind}_summary_sha256"] = digest(self.receipt[kind])
        self.receipt["policy_sha256"] = digest(self.policy)

    def assert_gate(self, name, status):
        result = check.evaluate(self.policy, self.receipt, now_unix_s=self.now)
        self.assertEqual(status, result["gates"][name]["status"])
        if status != "passed":
            self.assertEqual("no_go", result["decision"])

    def test_inclusive_timing_and_p95_boundaries(self):
        result = check.evaluate(self.policy, self.receipt, now_unix_s=self.now)
        self.assertEqual("meets_review_gates", result["decision"])
        self.assertEqual(digest(self.receipt), result["receipt_sha256"])
        self.assertTrue(all(gate["status"] == "passed" for gate in result["gates"].values()))

    def test_over_budget_rehearsal_fails_each_timing_gate(self):
        for name in check.TIMINGS:
            self.first[name] += 1
            with self.subTest(name=name):
                self.assert_gate(f"dress_rehearsal_1.{name}", "failed")

    def test_missing_measurement_is_not_zero_or_a_failed_measurement(self):
        self.first.pop("lost_acknowledged_writes")
        self.first["duplicate_business_effects"] = 1
        self.assert_gate("dress_rehearsal_1.lost_acknowledged_writes", "missing")
        self.assert_gate("dress_rehearsal_1.duplicate_business_effects", "failed")
        self.assert_gate("dress_rehearsal_1.prewrite_rollback_ms", "passed")

    def test_boolean_does_not_satisfy_integer_proof(self):
        self.first["lost_acknowledged_writes"] = False
        self.assert_gate("dress_rehearsal_1.lost_acknowledged_writes", "failed")

    def test_known_critical_endpoint_cannot_be_omitted_or_regress(self):
        self.first["endpoint_p95_us"].pop("book")
        self.assert_gate("dress_rehearsal_1.critical_p95", "missing")
        self.first["endpoint_p95_us"]["book"] = {"sqlite": 1000, "mysql": 1101}
        self.assert_gate("dress_rehearsal_1.critical_p95", "failed")

    def test_a_rising_peak_queue_is_a_no_go(self):
        self.first["peak_queue_end_depth"] = 5
        self.assert_gate("dress_rehearsal_1.queue", "failed")

    def test_require_two_distinct_rehearsals(self):
        self.receipt["dress_rehearsals"] = [self.first]
        self.assert_gate("distinct_dress_rehearsals", "missing")
        self.receipt["dress_rehearsals"] = [self.first, self.first.copy()]
        self.assert_gate("distinct_dress_rehearsals", "failed")

    def test_mismatched_release_or_workload_blocks_promotion(self):
        self.first["workload_sha256"] = "1" * 64
        self.assert_gate("dress_rehearsal_1.scope", "failed")
        self.receipt["identity"]["release_sha"] = "9" * 40
        self.assert_gate("receipt_binding", "failed")

    def test_repeatability_uses_rates_and_the_same_improvement_on_both_runs(self):
        # Run 1 improves latency only. Run 2 improves contention rate only.
        self.first["mysql_writes"]["contended_attempts"] = 20
        self.second["mysql_writes"]["p95_us"] = 1000
        self.assert_gate("repeatable_write_improvement", "failed")
        # Both now have lower rates, even though the MySQL attempts and
        # absolute contention counts are higher than SQLite.
        self.first["mysql_writes"]["contended_attempts"] = 15
        self.assert_gate("repeatable_write_improvement", "passed")

    def test_missing_prerequisites_and_measured_recovery_fail_closed(self):
        del self.receipt["prerequisites"]["mysql_ci_passed"]
        self.receipt["prerequisites"]["host_writer_fence_restart_passed"] = False
        self.assert_gate("mysql_ci_passed", "missing")
        self.assert_gate("host_writer_fence_restart_passed", "failed")
        recovery = self.receipt["offsite_recovery"]
        recovery["offsite_database_data_loss_ms"] += 1
        self.assert_gate("offsite_database_recovery", "failed")
        recovery["offsite_database_data_loss_ms"] -= 1
        recovery["database_recovery_ms"] += 1
        self.assert_gate("offsite_database_recovery", "failed")

    def test_prerequisite_evidence_must_match_the_pinned_candidate(self):
        self.receipt["prerequisites"]["identity_sha256"] = "1" * 64
        self.assert_gate("prerequisite_report", "failed")

    def test_older_receipt_with_no_operational_policy_cannot_clear_all_gates(self):
        self.policy.pop("operations")
        self.receipt["policy_sha256"] = digest(self.policy)
        self.assert_gate("offsite_database_recovery", "passed")
        self.assert_gate("operations_policy", "missing")

    def test_week_of_observation_checks_coverage_freshness_and_elapsed_lag(self):
        report = self.receipt["backup_health"]
        self.assert_gate("seven_day_backup_health", "passed")
        for name, delta in (("window_start_unix_s", 1), ("covered_duration_s", -1),
                            ("distinct_scheduled_observations", -1), ("max_snapshot_age_s", 1),
                            ("duration_with_lag_at_most_five_minutes_s", -1)):
            with self.subTest(name=name):
                report[name] += delta
                self.repin_summary("backup_health")
                self.assert_gate("seven_day_backup_health", "failed")
                report[name] -= delta

    def test_stale_or_future_operations_window_blocks_approval(self):
        operations = self.policy["operations"]
        for now in (operations["window_end_unix_s"] - 1, operations["evidence_valid_until_unix_s"] + 1):
            result = check.evaluate(self.policy, self.receipt, now_unix_s=now)
            self.assertEqual("failed", result["gates"]["operations_policy"]["status"])
            self.assertEqual("no_go", result["decision"])

    def test_correcting_measurement_without_reviewed_summary_pin_cannot_clear_gate(self):
        report = self.receipt["backup_health"]
        report["max_snapshot_age_s"] = check.BACKUP_MAX_AGE_SECONDS
        self.repin_summary("backup_health")
        self.assert_gate("seven_day_backup_health", "failed")
        report["max_snapshot_age_s"] = 1
        self.assert_gate("seven_day_backup_health", "failed")
        self.repin_summary("backup_health")
        self.assert_gate("seven_day_backup_health", "passed")

    def test_retention_requires_exact_report_and_approved_writer_refusals(self):
        report = self.receipt["offsite_retention"]
        self.assert_gate("offsite_retention", "passed")
        for name, value in (("report_sha256", "a" * 64), ("destination_sha256", "f" * 64),
                            ("configured_writer_credential_verified", False), ("writer_overwrite_blocked", False),
                            ("writer_delete_blocked", False), ("independent_lock_delete_blocked", False)):
            with self.subTest(name=name):
                original, report[name] = report[name], value
                self.repin_summary("offsite_retention")
                self.assert_gate("offsite_retention", "failed")
                report[name] = original

    def test_alerts_require_phone_delivery_for_each_case_and_both_rounds(self):
        report = self.receipt["external_alerts"]
        self.assert_gate("external_alert_delivery", "passed")
        second = report.pop("before_drill")
        self.repin_summary("external_alerts")
        self.assert_gate("external_alert_delivery", "missing")
        report["before_drill"] = second
        alert = second["cases"]["backup_lag"]
        alert["phone_delivery_verified"] = False
        self.repin_summary("external_alerts")
        self.assert_gate("external_alert_delivery", "failed")
        alert["phone_delivery_verified"] = True
        second["cases"]["server_silent"]["delivery_ms"] += 1
        self.repin_summary("external_alerts")
        self.assert_gate("external_alert_delivery", "failed")
        second["cases"]["server_silent"]["delivery_ms"] -= 1
        alert["delivery_receipt_sha256"] = report["before_installer"]["cases"]["backup_lag"]["delivery_receipt_sha256"]
        self.repin_summary("external_alerts")
        self.assert_gate("external_alert_delivery", "failed")

    def test_point_in_time_drills_require_distinct_restores_and_bad_write_exclusion(self):
        drills = self.receipt["point_in_time_drills"]["drills"]
        first = drills[0]
        self.assert_gate("point_in_time_drills", "passed")
        for name, value in (("database_recovery_ms", 30 * check.MINUTE + 1),
                            ("absolute_heartbeat_target_delta_ms", check.MINUTE + 1),
                            ("planted_bad_write_excluded", False), ("report_sha256", drills[1]["report_sha256"])):
            with self.subTest(name=name):
                original, first[name] = first[name], value
                self.repin_summary("point_in_time_drills")
                self.assert_gate("point_in_time_drills", "failed")
                first[name] = original
        drills.pop()
        self.repin_summary("point_in_time_drills")
        self.assert_gate("point_in_time_drills", "missing")

    def test_cli_reports_missing_and_invalid_receipts(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            policy, receipt = folder / "policy.json", folder / "receipt.json"
            policy.write_text(json.dumps(self.policy), encoding="utf-8")
            command = [
                sys.executable, "-B", "-m", "scripts.cutover.readiness.check",
                "--policy", str(policy), "--receipt", str(receipt),
            ]
            for data, expected in (
                (None, "missing"), ('{"version":1,"version":1}', "failed"),
                ('{"version":1,"overflow":1e999}', "failed"),
            ):
                if data is not None:
                    receipt.write_text(data, encoding="utf-8")
                result = subprocess.run(command, capture_output=True, text=True, check=False)
                self.assertEqual(1, result.returncode)
                self.assertEqual(expected, json.loads(result.stdout)["gates"]["receipt_file"]["status"])
                self.assertEqual("", result.stderr)


if __name__ == "__main__":
    unittest.main()
