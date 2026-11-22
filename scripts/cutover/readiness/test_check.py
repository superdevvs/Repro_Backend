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
    }
    receipt = {
        "version": 1, "identity": identity.copy(), "policy_sha256": digest(policy),
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
    return policy, receipt


class ReadinessTests(unittest.TestCase):
    def setUp(self):
        self.policy, self.receipt = readiness_fixture()
        self.first, self.second = self.receipt["dress_rehearsals"]

    def assert_gate(self, name, status):
        result = check.evaluate(self.policy, self.receipt)
        self.assertEqual(status, result["gates"][name]["status"])
        if status != "passed":
            self.assertEqual("no_go", result["decision"])

    def test_inclusive_timing_and_p95_boundaries(self):
        result = check.evaluate(self.policy, self.receipt)
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
