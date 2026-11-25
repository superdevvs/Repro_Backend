import copy
import hashlib
import json
import subprocess
import sys
import tempfile
import time
import unittest
from dataclasses import asdict
from datetime import datetime, timezone
from pathlib import Path

from scripts.cutover.nightly import policy as drafts
from scripts.cutover.readiness import check
from scripts.cutover.readiness.test_check import readiness_fixture
from scripts.cutover.runner import Identity, digest

HERE = Path(__file__).resolve().parent
REPOSITORY = HERE.parents[2]
POLICY = HERE / "review-policy.draft.json"
MANIFEST = HERE / "reviewed-nights.draft.json"
SCOPE = REPOSITORY / "database/schema/migration-scope.json"
HOST = HERE / "dress/host-profile.json"
WORKLOAD = HERE / "dress/workload.json"
SCOPE_SHA = "a3807732af33e799198c3e681b2610ff470605e023d2029fc5b8ec81bf5c99d2"


def entry(day: str, seed: int) -> dict:
    return {"scheduled_day_utc": day, "report_sha256": f"{seed:064x}", "crm_report_sha256": f"{seed + 100:064x}",
            "producer_receipt_sha256": f"{seed + 200:064x}", "source_release_sha": "8" * 40}


class DraftFileTests(unittest.TestCase):
    def setUp(self):
        self.policy = json.loads(POLICY.read_bytes())
        self.manifest = json.loads(MANIFEST.read_bytes())

    def test_drafts_pin_the_reviewed_scope_and_dress_profiles(self):
        status = drafts.draft_status(POLICY, MANIFEST, SCOPE, HOST, WORKLOAD)
        self.assertEqual(self.policy["operations"]["nightly_scope_sha256"], SCOPE_SHA)
        self.assertEqual(hashlib.sha256(SCOPE.read_bytes()).hexdigest(), SCOPE_SHA)
        self.assertEqual(self.manifest["nightly_scope_sha256"], SCOPE_SHA)
        self.assertEqual(self.manifest["nights"], [])
        self.assertEqual(self.manifest["schema"], "repro.nightly-data-parity.manifest.v2")
        self.assertIn("identity.target_uuid", status["unset"])
        self.assertIn("busiest_endpoints", status["unset"])
        self.assertIn("operations.nightly_data_parity_report_sha256", status["unset"])

    def test_a_stale_pin_is_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            changed = Path(directory) / "workload.json"
            changed.write_bytes(WORKLOAD.read_bytes() + b"\n")
            with self.assertRaisesRegex(drafts.Refused, "workload"):
                drafts.draft_status(POLICY, MANIFEST, SCOPE, HOST, changed)

    def test_the_unfilled_draft_is_missing_evidence_not_malformed(self):
        receipt = {"version": 1, "policy_sha256": digest(self.policy)}
        result = check.evaluate(self.policy, receipt, now_unix_s=1_800_000_030)
        self.assertEqual(result["gates"]["approved_policy"]["status"], "missing")

    def test_a_filled_draft_reports_nightly_parity_as_missing(self):
        fixture_policy, fixture_receipt = readiness_fixture()
        policy = copy.deepcopy(self.policy)
        policy["identity"] = fixture_policy["identity"]
        policy["busiest_endpoints"] = fixture_policy["busiest_endpoints"]
        operations = policy["operations"]
        for name, value in fixture_policy["operations"].items():
            if operations.get(name) is None:
                operations[name] = value
        # The command reads the real clock, so the reviewed window must include now.
        now = int(time.time())
        operations |= {"window_end_unix_s": now - 60, "evidence_valid_until_unix_s": now + 3600,
                       "last_nightly_day_utc": datetime.now(timezone.utc).date().isoformat()}
        receipt = {key: value for key, value in fixture_receipt.items() if key != "nightly_data_parity"}
        receipt["policy_sha256"] = digest(policy)
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            (folder / "policy.json").write_text(json.dumps(policy), encoding="utf-8")
            (folder / "receipt.json").write_text(json.dumps(receipt), encoding="utf-8")
            command = [sys.executable, "-B", "-m", "scripts.cutover.readiness.check",
                       "--policy", str(folder / "policy.json"), "--receipt", str(folder / "receipt.json")]
            result = subprocess.run(command, capture_output=True, text=True, check=False)
        report = json.loads(result.stdout)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(report["gates"]["approved_policy"]["status"], "passed")
        self.assertEqual(report["gates"]["operations_policy"]["status"], "passed")
        self.assertEqual(report["gates"]["nightly_data_parity"]["status"], "missing")


class ManifestTests(unittest.TestCase):
    def setUp(self):
        self.manifest = json.loads(MANIFEST.read_bytes())

    def test_add_night_keeps_the_latest_five_consecutive_dates(self):
        manifest = self.manifest
        for index, day in enumerate(("2026-11-26", "2026-11-27", "2026-11-28", "2026-11-29", "2026-11-30",
                                     "2026-12-01")):
            manifest = drafts.add_night(manifest, entry(day, index + 1))
        self.assertEqual([night["scheduled_day_utc"] for night in manifest["nights"]],
                         ["2026-11-27", "2026-11-28", "2026-11-29", "2026-11-30", "2026-12-01"])
        self.assertEqual(self.manifest["nights"], [])

    def test_a_gap_duplicate_or_malformed_entry_is_refused(self):
        manifest = drafts.add_night(self.manifest, entry("2026-11-26", 1))
        with self.assertRaisesRegex(drafts.Refused, "consecutive"):
            drafts.add_night(manifest, entry("2026-11-28", 2))
        with self.assertRaisesRegex(drafts.Refused, "consecutive"):
            drafts.add_night(manifest, entry("2026-11-26", 3))
        broken = entry("2026-11-27", 4)
        del broken["producer_receipt_sha256"]
        with self.assertRaisesRegex(drafts.Refused, "entry"):
            drafts.add_night(manifest, broken)

    def test_pinning_binds_the_manifest_bytes_identity_and_last_night(self):
        policy = json.loads(POLICY.read_bytes())
        with self.assertRaisesRegex(drafts.Refused, "identity"):
            drafts.pin_manifest(policy, drafts.add_night(self.manifest, entry("2026-11-26", 1)))
        identity = asdict(Identity("cutover-1", "a" * 40, "b" * 64, "c" * 64, "d" * 64,
                                   "f1d53bff-40ce-46ba-a35b-4591b2be65b7"))
        policy["identity"] = identity
        manifest = drafts.add_night(self.manifest, entry("2026-11-26", 1))
        manifest = drafts.add_night(manifest, entry("2026-11-27", 2))
        pinned_policy, raw = drafts.pin_manifest(policy, manifest)
        written = json.loads(raw)
        self.assertEqual(written["identity_sha256"], digest(identity))
        operations = pinned_policy["operations"]
        self.assertEqual(operations["nightly_data_parity_report_sha256"], hashlib.sha256(raw).hexdigest())
        self.assertEqual(operations["last_nightly_day_utc"], "2026-11-27")
        self.assertIsNone(policy["operations"]["nightly_data_parity_report_sha256"])
        summary = drafts.pin_summary(pinned_policy, "e" * 64)
        self.assertEqual(summary["operations"]["nightly_data_parity_summary_sha256"], "e" * 64)
        with self.assertRaisesRegex(drafts.Refused, "summary"):
            drafts.pin_summary(pinned_policy, "not-a-digest")


if __name__ == "__main__":
    unittest.main()
