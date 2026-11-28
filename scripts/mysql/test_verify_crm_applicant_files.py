"""Verify the private recruiting file audit with synthetic attachments only."""

from contextlib import closing
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import unittest


SPEC = importlib.util.spec_from_file_location("crm_applicant_files", Path(__file__).with_name("verify-crm-applicant-files.py"))
audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)

MANUAL_FILE = "6c797893-38c0-46b1-8052-0d6153619b7b.pdf"
MANUAL_BYTES = b"synthetic-manual-file-only"
INTAKE_BYTES = b"synthetic-intake-file-only"
INTAKE_CHECKSUM = hashlib.sha256(INTAKE_BYTES).hexdigest()


class PathTests(unittest.TestCase):
    def test_accepts_only_applicant_relative_manual_and_intake_paths(self):
        intake = f"recruiting/61/intake/resume-{INTAKE_CHECKSUM}"
        manual = f"recruiting/61/{MANUAL_FILE}"
        self.assertEqual(manual.split("/"), audit.path_components(61, "resume", manual, None))
        self.assertEqual(intake.split("/"), audit.path_components(61, "resume", intake, INTAKE_CHECKSUM))
        for relative, checksum in (
            ("/" + manual, None), (manual.replace("/61/", "/62/"), None),
            (f"recruiting/61/../61/{MANUAL_FILE}", None), (f"recruiting/61//{MANUAL_FILE}", None),
            (f"recruiting/61/{MANUAL_FILE}-wal", None), (manual.replace("/", "\\"), None),
            (intake.replace("resume-", "license-"), INTAKE_CHECKSUM), (intake, None),
        ):
            with self.subTest(relative=relative), self.assertRaises(audit.schema_drift.Refusal):
                audit.path_components(61, "resume", relative, checksum)


@unittest.skipUnless(os.open in os.supports_dir_fd and hasattr(os, "O_NOFOLLOW"), "Linux directory-relative reads required")
class ApplicantFileAuditTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        base = Path(folder.name).resolve()
        self.root = base / "private"
        self.root.mkdir(mode=0o700)
        self.snapshot = base / "copy.sqlite"
        self.key = base / "audit-key"
        self.key.write_bytes(bytes(range(32)))
        self.key.chmod(0o600)
        self.manual = self.root / "recruiting" / "61" / MANUAL_FILE
        self.intake = self.root / "recruiting" / "62" / "intake" / f"resume-{INTAKE_CHECKSUM}"
        for path, content in [(self.manual, MANUAL_BYTES), (self.intake, INTAKE_BYTES)]:
            path.parent.mkdir(parents=True)
            path.write_bytes(content)
            path.chmod(0o600)
        with closing(sqlite3.connect(self.snapshot)) as db, db:
            db.execute("CREATE TABLE recruit_applicant_files (id INTEGER PRIMARY KEY, applicant_id INTEGER, kind TEXT, path TEXT, size INTEGER, intake_checksum TEXT)")
            db.executemany("INSERT INTO recruit_applicant_files VALUES (?, ?, ?, ?, ?, ?)", [
                (500001, 61, "license", self.manual.relative_to(self.root).as_posix(), len(MANUAL_BYTES), None),
                (500002, 62, "resume", self.intake.relative_to(self.root).as_posix(), len(INTAKE_BYTES), INTAKE_CHECKSUM),
            ])
        self.snapshot.chmod(0o600)

    def run_audit(self, expected=None, root=None):
        cmd = [sys.executable, "-B", audit.__file__, "--snapshot", str(self.snapshot), "--snapshot-sha256",
               audit.schema_drift.file_hash(self.snapshot), "--private-root", str(root or self.root), "--key-file", str(self.key)]
        if expected is not None:
            cmd.extend(["--expected-manifest-hmac-sha256", expected])
        result = subprocess.run(cmd, capture_output=True, check=False, text=True)
        self.assertEqual("", result.stderr)
        report = json.loads(result.stdout)
        for forbidden in ["recruiting/", "500001", "500002", str(self.root), MANUAL_FILE, INTAKE_CHECKSUM,
                          MANUAL_BYTES.decode(), INTAKE_BYTES.decode()]:
            self.assertNotIn(forbidden, json.dumps({key: value for key, value in report.items() if not key.endswith("sha256")}))
        return result.returncode, report

    def test_checks_both_files_and_compares_manual_file_content_even_at_same_size(self):
        code, report = self.run_audit()
        self.assertEqual(0, code, report)
        self.assertEqual((2, 1, 1, len(MANUAL_BYTES) + len(INTAKE_BYTES)),
                         tuple(report[name] for name in ("files", "intake_checksums_verified", "manual_files_hashed", "bytes_verified")))
        digest = report["manifest_hmac_sha256"]
        self.assertTrue(self.run_audit(digest)[1]["expected_manifest_verified"])
        self.manual.write_bytes(b"m" * len(MANUAL_BYTES))
        self.assertEqual("applicant-file-manifest-differs", self.run_audit(digest)[1]["reason"])
        self.intake.write_bytes(b"i" * len(INTAKE_BYTES))
        self.assertEqual("applicant-file-checksum-differs", self.run_audit()[1]["reason"])

    def test_refuses_size_mismatch_missing_file_links_and_public_root(self):
        self.manual.write_bytes(b"too-short")
        self.assertEqual("applicant-file-size-differs", self.run_audit()[1]["reason"])
        self.manual.unlink()
        self.assertEqual(1, self.run_audit()[0])
        outside = self.root.parent / "outside.pdf"
        outside.write_bytes(MANUAL_BYTES)
        outside.chmod(0o600)
        self.manual.symlink_to(outside)
        self.assertEqual(1, self.run_audit()[0])
        self.manual.unlink()
        os.link(outside, self.manual)
        self.assertEqual("applicant-file-must-be-private-regular-and-single-link", self.run_audit()[1]["reason"])
        self.root.chmod(0o755)
        self.assertEqual("private-disk-root-access-must-be-restricted", self.run_audit()[1]["reason"])

    def test_refuses_symlinked_parent_root_alias_and_snapshot_sidecar(self):
        applicant = self.manual.parent
        moved = self.root.parent / "outside-applicant"
        applicant.rename(moved)
        applicant.symlink_to(moved, target_is_directory=True)
        self.assertEqual(1, self.run_audit()[0])
        root_alias = self.root.parent / "private-alias"
        root_alias.symlink_to(self.root, target_is_directory=True)
        self.assertEqual("canonical-private-disk-root-required", self.run_audit(root=root_alias)[1]["reason"])
        Path(str(self.snapshot) + "-wal").touch()
        self.assertEqual("standalone-snapshot-has-sidecar", self.run_audit()[1]["reason"])


if __name__ == "__main__":
    unittest.main()
