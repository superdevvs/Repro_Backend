import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from scripts.cutover.readiness import check, summarize_nightly_parity as nightly
from scripts.cutover.readiness.test_check import readiness_fixture
from scripts.cutover.runner import digest


class NightlyParitySummaryTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.folder = Path(folder.name)
        self.policy, self.receipt = readiness_fixture()
        original = self.receipt["nightly_data_parity"]
        self.reports = [
            {key: value for key, value in run.items() if key != "report_sha256"}
            | {"nightly_scope_sha256": original["nightly_scope_sha256"]}
            for run in original["nights"]
        ]
        self.paths = [self.folder / f"night-{index}.json" for index in range(check.NIGHTLY_RUN_COUNT)]
        self.manifest_path = self.folder / "manifest.json"
        self.operations = self.policy["operations"]

    @staticmethod
    def write(path, record):
        raw = (json.dumps(record) + "\n").encode()
        path.write_bytes(raw)
        return hashlib.sha256(raw).hexdigest()

    def write_reviewed_input(self, manifest_overrides=None):
        manifest = {
            "schema": nightly.SCHEMA,
            "identity_sha256": digest(self.policy["identity"]),
            "nightly_scope_sha256": self.operations["nightly_scope_sha256"],
            "nights": [
                {"scheduled_day_utc": report["scheduled_day_utc"], "report_sha256": self.write(path, report)}
                for path, report in zip(self.paths, self.reports, strict=True)
            ],
        } | (manifest_overrides or {})
        self.operations["nightly_data_parity_report_sha256"] = self.write(self.manifest_path, manifest)

    def summarize(self):
        return nightly.summarize(self.policy, self.manifest_path, self.paths)

    def test_summary_uses_exact_digests_and_passes_existing_gate(self):
        # An unchanged snapshot digest can be recorded on consecutive nights.
        self.reports[1]["snapshot_sha256"] = self.reports[0]["snapshot_sha256"]
        self.write_reviewed_input()
        summary = self.summarize()
        self.assertEqual(summary["report_sha256"], self.operations["nightly_data_parity_report_sha256"])
        self.assertEqual(summary["nights"][-1]["report_sha256"],
                         hashlib.sha256(self.paths[-1].read_bytes()).hexdigest())
        self.assertEqual(len(summary["nights"]), check.NIGHTLY_RUN_COUNT)
        self.assertNotIn("nightly_scope_sha256", summary["nights"][-1])
        self.operations["nightly_data_parity_summary_sha256"] = digest(summary)
        check.review_nightly_data_parity(self.policy, {"nightly_data_parity": summary})

    def test_manifest_and_each_report_are_checked_against_exact_bytes(self):
        for changed in (self.manifest_path, *self.paths):
            with self.subTest(file=changed.name):
                self.write_reviewed_input()
                changed.write_bytes(changed.read_bytes() + b"\n")
                with self.assertRaisesRegex(check.Failed, "reviewed SHA-256"):
                    self.summarize()

    def test_files_must_match_the_reviewed_scope_and_date_even_when_pinned(self):
        for key in ("identity_sha256", "nightly_scope_sha256"):
            with self.subTest(key=key):
                self.write_reviewed_input({key: "0" * 64})
                with self.assertRaisesRegex(check.Failed, "manifest differs"):
                    self.summarize()
        self.reports[-1]["nightly_scope_sha256"] = "0" * 64
        self.write_reviewed_input()
        with self.assertRaisesRegex(check.Failed, "report differs"):
            self.summarize()

    def test_rejects_invalid_runs_with_matching_source_pins(self):
        for field, value, expected in (
            ("scheduled_day_utc", self.reports[0]["scheduled_day_utc"], "consecutive"),
            ("release_sha", "0" * 40, "frozen release"),
            ("import_and_verify_ms", 12 * check.MINUTE + 1, "exceeds"),
            ("unexpected_field_differences", 1, "must be zero"),
            ("sqlite_reverse_parity_verified", False, "did not pass"),
        ):
            with self.subTest(field=field):
                original, self.reports[-1][field] = self.reports[-1][field], value
                self.write_reviewed_input()
                with self.assertRaisesRegex(check.Failed, expected):
                    self.summarize()
                self.reports[-1][field] = original

    def test_cli_refuses_to_emit_summary_when_a_report_is_missing(self):
        self.write_reviewed_input()
        policy_path = self.folder / "policy.json"
        self.write(policy_path, self.policy)
        self.paths[-1].unlink()
        result = subprocess.run([
            sys.executable, "-B", "-m", "scripts.cutover.readiness.summarize_nightly_parity",
            "--policy", str(policy_path), "--manifest", str(self.manifest_path),
            "--nights", *(str(path) for path in self.paths),
        ], capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stderr, "")
        self.assertEqual(json.loads(result.stdout)["result"], "nightly-parity-summary-refused")


if __name__ == "__main__":
    unittest.main()
