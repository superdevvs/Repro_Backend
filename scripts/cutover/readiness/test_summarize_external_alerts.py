import hashlib
import json
from pathlib import Path
import tempfile
import unittest

from scripts.cutover.readiness import check, summarize_external_alerts as alerts
from scripts.cutover.readiness.test_check import readiness_fixture
from scripts.cutover.runner import digest


class ExternalAlertSummaryTests(unittest.TestCase):
    def setUp(self):
        self.policy, _ = readiness_fixture()
        self.operations = self.policy["operations"]
        self.now = 1_800_000_000_000
        self.raw = {
            "schema": alerts.SCHEMA,
            "identity_sha256": digest(self.policy["identity"]),
            "monitor_profile_sha256": self.operations["monitor_profile_sha256"],
            "phone_recipient_sha256": self.operations["phone_recipient_sha256"],
            "rounds": {},
        }
        for index, milestone in enumerate(check.ALERT_MILESTONES, 1):
            self.raw["rounds"][milestone] = {
                "report_sha256": f"{index:064x}",
                "cases": {
                    name: {"triggered_at_unix_ms": self.now - limit, "phone_observed_at_unix_ms": self.now,
                           "condition_evidence_sha256": f"{index * 100 + order:064x}",
                           "delivery_receipt_sha256": f"{index * 1000 + order:064x}"}
                    for order, (name, limit) in enumerate(check.ALERT_LIMITS_MS.items())
                },
            }

    def cases(self, milestone="before_drill"):
        return self.raw["rounds"][milestone]["cases"]

    def summarize(self, *, append=b"", repin=True):
        payload = json.dumps(self.raw).encode("utf-8")
        if repin:
            self.operations["external_alerts_report_sha256"] = hashlib.sha256(payload).hexdigest()
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "observations.json"
            path.write_bytes(payload + append)
            return alerts.summarize(self.policy, path, now_unix_ms=self.now)

    def test_exact_deadlines_reproduce_the_existing_gate_contract(self):
        summary = self.summarize()
        self.operations["external_alerts_summary_sha256"] = digest(summary)
        check.review_alerts(self.policy, {"external_alerts": summary})
        cases = summary["before_drill"]["cases"]
        self.assertEqual(cases["backup_stale"]["delivery_ms"], 5 * check.MINUTE)
        self.assertEqual(cases["server_silent"]["delivery_ms"], 10 * check.MINUTE)

    def test_missing_handset_observation_cannot_be_replaced_by_provider_status(self):
        event = self.cases()["server_silent"]
        event["phone_observed_at_unix_ms"] = None
        with self.assertRaisesRegex(check.Missing, "confirmed handset observation"):
            self.summarize()
        event["provider_status"] = "Delivered"
        with self.assertRaisesRegex(check.Failed, "unexpected fields"):
            self.summarize()

    def test_wrong_phone_or_changed_source_bytes_are_refused(self):
        with self.assertRaisesRegex(check.Failed, "digest|SHA-256"):
            self.summarize(append=b"\n")
        self.raw["phone_recipient_sha256"] = "e" * 64
        with self.assertRaisesRegex(check.Failed, "different identity, monitor or phone"):
            self.summarize()

    def test_incomplete_round_cannot_pass(self):
        del self.cases()["backup_stale"]
        with self.assertRaisesRegex(check.Missing, "before_drill cases is incomplete"):
            self.summarize()

    def test_a_receipt_cannot_be_reused_across_cases_or_rounds(self):
        first = self.cases("before_installer")["server_silent"]
        self.cases()["server_silent"]["delivery_receipt_sha256"] = first["delivery_receipt_sha256"]
        with self.assertRaisesRegex(check.Failed, "delivery receipt was reused"):
            self.summarize()
        self.cases()["server_silent"]["condition_evidence_sha256"] = first["condition_evidence_sha256"]
        with self.assertRaisesRegex(check.Failed, "condition receipt was reused"):
            self.summarize()

    def test_late_receipt_cannot_pass(self):
        self.cases()["server_silent"]["triggered_at_unix_ms"] -= 1
        with self.assertRaisesRegex(check.Failed, "server_silent phone delivery exceeds"):
            self.summarize()

    def test_timestamp_must_follow_trigger_and_cannot_be_in_the_future(self):
        event = self.cases()["server_silent"]
        event["phone_observed_at_unix_ms"] = event["triggered_at_unix_ms"] - 1
        with self.assertRaisesRegex(check.Failed, "phone_observed_at_unix_ms"):
            self.summarize()
        event["phone_observed_at_unix_ms"] = self.now + 1
        with self.assertRaisesRegex(check.Failed, "receipt is in the future"):
            self.summarize()


if __name__ == "__main__":
    unittest.main()
