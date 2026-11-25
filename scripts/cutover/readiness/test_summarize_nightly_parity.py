from datetime import datetime
import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts.cutover.readiness import check, summarize_nightly_parity as nightly
from scripts.cutover.readiness.test_check import readiness_fixture
from scripts.cutover.runner import digest


class NightlyParitySummaryTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.folder = Path(folder.name)
        self.policy, self.receipt = readiness_fixture()
        self.operations = self.policy["operations"]
        self.scope_path = self.folder / "scope.json"
        self.scope = {
            "schema": nightly.SCOPE_SCHEMA,
            "tables": {
                name: {"class": "A"} for name in (
                    *nightly.SHARED_CRM_TABLES, "email_suppressions",
                    *(f"crm_test_{index}" for index in range(check.MIN_CRM_TABLES - 1)),
                )
            },
        }
        self.operations["nightly_scope_sha256"] = self.write(self.scope_path, self.scope)
        original = self.receipt["nightly_data_parity"]
        self.reports = [
            {key: value for key, value in run.items() if key not in ("report_sha256", "crm")}
            | {"nightly_scope_sha256": self.operations["nightly_scope_sha256"]}
            for run in original["nights"]
        ]
        self.crm_reports = [
            {key: value for key, value in run["crm"].items() if key != "report_sha256"}
            | {"nightly_scope_sha256": self.operations["nightly_scope_sha256"]}
            for run in original["nights"]
        ]
        self.paths = [self.folder / f"night-{index}.json" for index in range(check.NIGHTLY_RUN_COUNT)]
        self.crm_paths = [self.folder / f"crm-{index}.json" for index in range(check.NIGHTLY_RUN_COUNT)]
        self.manifest_path = self.folder / "manifest.json"

    @staticmethod
    def write(path, record):
        raw = (json.dumps(record) + "\n").encode()
        path.write_bytes(raw)
        return hashlib.sha256(raw).hexdigest()

    def write_reviewed_input(self, manifest_overrides=None, *, captures=None):
        manifest = {
            "schema": nightly.SCHEMA,
            "identity_sha256": digest(self.policy["identity"]),
            "nightly_scope_sha256": self.operations["nightly_scope_sha256"],
            "nights": [
                {"scheduled_day_utc": report["scheduled_day_utc"], "report_sha256": self.write(path, report),
                 "crm_report_sha256": self.write(crm_path, crm)}
                for path, report, crm_path, crm in zip(self.paths, self.reports, self.crm_paths, self.crm_reports, strict=True)
            ],
        } | (manifest_overrides or {})
        if captures is not None:
            for entry, capture_path in zip(manifest["nights"], captures, strict=True):
                entry["producer_receipt_sha256"] = hashlib.sha256(capture_path.read_bytes()).hexdigest()
        self.operations["nightly_data_parity_report_sha256"] = self.write(self.manifest_path, manifest)

    def summarize(self):
        return nightly.summarize(self.policy, self.manifest_path, self.scope_path, self.paths, self.crm_paths)

    def test_pinned_dated_receipts_are_checked_with_the_five_nightly_reports(self):
        paths = [self.folder / f"capture-{index}.json" for index in range(check.NIGHTLY_RUN_COUNT)]
        # The database bytes can be unchanged while two independently dated captures are valid.
        self.reports[1]["snapshot_sha256"] = self.reports[0]["snapshot_sha256"]
        self.crm_reports[1]["snapshot_sha256"] = self.reports[0]["snapshot_sha256"]
        for path, report in zip(paths, self.reports, strict=True):
            self.write(path, {
                "format_version": 1, "driver": "sqlite", "created_utc": report["scheduled_day_utc"] + "T00:00:01Z",
                "application_booted": False, "tables": 328, "file": "database.sqlite", "bytes": 4096,
                "sha256": report["snapshot_sha256"],
            })

        def summarize_captures():
            now = datetime.fromisoformat(self.reports[-1]["scheduled_day_utc"] + "T12:00:00+00:00")
            with patch.object(nightly, "datetime", wraps=datetime) as clock:
                clock.now.return_value = now
                return nightly.summarize(self.policy, self.manifest_path, self.scope_path, self.paths, self.crm_paths,
                                         producer_paths=paths)

        self.write_reviewed_input(captures=paths)
        self.assertEqual(len(summarize_captures()["nights"]), check.NIGHTLY_RUN_COUNT)
        with self.assertRaisesRegex(check.Failed, "Malformed nightly report binding"):
            self.summarize()
        paths[1].write_bytes(paths[0].read_bytes())
        with self.assertRaisesRegex(check.Failed, "reviewed SHA-256"):
            summarize_captures()
        self.write_reviewed_input(captures=paths)
        with self.assertRaisesRegex(check.Failed, "capture date differs"):
            summarize_captures()

    def test_summary_uses_exact_digests_and_passes_existing_gate(self):
        # An unchanged snapshot digest can be recorded on consecutive nights.
        self.reports[1]["snapshot_sha256"] = self.reports[0]["snapshot_sha256"]
        self.crm_reports[1]["snapshot_sha256"] = self.reports[0]["snapshot_sha256"]
        self.write_reviewed_input()
        summary = self.summarize()
        self.assertEqual(summary["report_sha256"], self.operations["nightly_data_parity_report_sha256"])
        self.assertEqual(summary["nights"][-1]["report_sha256"],
                         hashlib.sha256(self.paths[-1].read_bytes()).hexdigest())
        self.assertEqual(len(summary["nights"]), check.NIGHTLY_RUN_COUNT)
        self.assertNotIn("nightly_scope_sha256", summary["nights"][-1])
        self.assertEqual(summary["crm_scope_table_count"], check.MIN_CRM_TABLES)
        self.assertEqual(summary["nights"][-1]["crm"]["report_sha256"],
                         hashlib.sha256(self.crm_paths[-1].read_bytes()).hexdigest())
        self.assertEqual(summary["nights"][-1]["crm"]["audit_report_sha256"],
                         self.crm_reports[-1]["audit_report_sha256"])
        self.operations["nightly_data_parity_summary_sha256"] = digest(summary)
        check.review_nightly_data_parity(self.policy, {"nightly_data_parity": summary})

    def test_manifest_and_each_report_are_checked_against_exact_bytes(self):
        for changed in (self.scope_path, self.manifest_path, *self.paths, *self.crm_paths):
            with self.subTest(file=changed.name):
                self.write_reviewed_input()
                original = changed.read_bytes()
                changed.write_bytes(original + b"\n")
                with self.assertRaisesRegex(check.Failed, "reviewed SHA-256"):
                    self.summarize()
                changed.write_bytes(original)

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

    def test_crm_reports_must_match_every_identity_and_pass_parity(self):
        for field, value, expected in (
            ("scheduled_day_utc", self.reports[0]["scheduled_day_utc"], "release, snapshot or scope"),
            ("release_sha", "0" * 40, "release, snapshot or scope"),
            ("snapshot_sha256", "0" * 64, "release, snapshot or scope"),
            ("nightly_scope_sha256", "0" * 64, "release, snapshot or scope"),
            ("schema", "repro.nightly-crm-parity.review.v0", "Malformed nightly CRM"),
            ("crm_tables_verified", check.MIN_CRM_TABLES + 1, "cover every scoped CRM table"),
            ("crm_client_import_dry_run_verified", False, "did not pass"),
        ):
            with self.subTest(field=field):
                original, self.crm_reports[-1][field] = self.crm_reports[-1][field], value
                self.write_reviewed_input()
                with self.assertRaisesRegex(check.Failed, expected):
                    self.summarize()
                self.crm_reports[-1][field] = original

    def test_scope_must_preserve_complete_crm_and_shared_tables(self):
        self.scope["tables"]["users"] = {"class": "B", "approval_sha256": "a" * 64}
        scope_sha = self.write(self.scope_path, self.scope)
        with self.assertRaisesRegex(check.Failed, "preserve every CRM and shared table"):
            nightly.crm_scope_table_count(self.scope_path, scope_sha)
        self.scope["tables"]["users"] = {"class": "A"}
        del self.scope["tables"]["email_suppressions"]
        scope_sha = self.write(self.scope_path, self.scope)
        with self.assertRaisesRegex(check.Failed, "full CRM release tables"):
            nightly.crm_scope_table_count(self.scope_path, scope_sha)

    def test_old_manifest_cannot_bypass_crm_review_requirements(self):
        self.write_reviewed_input({"schema": "repro.nightly-data-parity.manifest.v1"})
        with self.assertRaisesRegex(check.Failed, "manifest differs"):
            self.summarize()

    def test_cli_emits_summary_and_refuses_a_missing_crm_review(self):
        self.write_reviewed_input()
        policy_path = self.folder / "policy.json"
        self.write(policy_path, self.policy)
        command = [
            sys.executable, "-B", "-m", "scripts.cutover.readiness.summarize_nightly_parity",
            "--policy", str(policy_path), "--manifest", str(self.manifest_path), "--scope", str(self.scope_path),
            "--nights", *(str(path) for path in self.paths),
            "--crm-reports", *(str(path) for path in self.crm_paths),
        ]
        result = subprocess.run(command, capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stdout)
        output = json.loads(result.stdout)
        self.assertEqual(output["result"], "nightly-parity-summary-ready")
        self.assertEqual(output["summary_sha256"], digest(output["nightly_data_parity"]))

        self.crm_paths[-1].unlink()
        result = subprocess.run(command, capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stderr, "")
        self.assertEqual(json.loads(result.stdout)["result"], "nightly-parity-summary-refused")


if __name__ == "__main__":
    unittest.main()
