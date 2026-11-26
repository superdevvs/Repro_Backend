"""Offline scope checks on synthetic SQLite fixtures."""

from contextlib import closing
import importlib.util
import json
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch


SPEC = importlib.util.spec_from_file_location("scope_audit", Path(__file__).with_name("scope-audit.py"))
scope = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(scope)


class ScopeAuditTests(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.root = Path(directory.name).resolve()
        self.policy = self.root / "scope.json"
        self.snapshot = self.root / "copy.sqlite"
        with closing(sqlite3.connect(self.snapshot)) as db:
            db.executescript("""
                CREATE TABLE contacts(id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT UNIQUE);
                CREATE INDEX contacts_value_plain ON contacts(value);
                CREATE TABLE queued(id INTEGER PRIMARY KEY, note TEXT);
                CREATE VIEW public_contacts AS SELECT value FROM contacts;
                INSERT INTO contacts(value) VALUES ('synthetic-exclusive-row-content');
            """)
        self.snapshot.chmod(0o600)
        self.sha256 = scope.schema_drift.file_hash(self.snapshot)

    def write_scope(self, tables):
        self.policy.write_text(json.dumps({"schema": scope.SCOPE_SCHEMA, "tables": tables}), encoding="utf-8")
        self.policy.chmod(0o600)

    def audit(self):
        return scope.audit(self.policy, scope.schema_drift.file_hash(self.policy), self.snapshot, self.sha256)

    def test_preserve_and_explicit_review_do_not_alter_snapshot_or_report_data(self):
        self.write_scope({"contacts": {"class": "A"}, "queued": {"class": "B", "approval_sha256": "a" * 64}})
        with patch.object(scope, "table_sizes", return_value={"contacts": 12288, "queued": 4096}) as sizes:
            result = self.audit()
        self.assertEqual({"contacts": ["contacts", "contacts_value_plain", "sqlite_autoindex_contacts_1"],
                          "queued": ["queued"]},
                         {name: sorted(objects) for name, objects in sizes.call_args.args[1].items()})
        self.assertTrue(result["source_scope_verified"])
        self.assertFalse(result["dispositions_applied"])
        self.assertEqual(["A", "B"], [item["class"] for item in result["tables"]])
        self.assertNotIn("synthetic-exclusive-row-content", json.dumps(result))
        self.assertNotIn("public_contacts", json.dumps(result))
        self.assertEqual(self.sha256, scope.schema_drift.file_hash(self.snapshot))

    def test_unlisted_classes_and_missing_names_are_refused_even_for_small_tables(self):
        self.write_scope({"removed_table": {"class": "A"}})
        with patch.object(scope, "table_sizes", return_value={"contacts": 50_000_000, "queued": 4096}):
            result = self.audit()
        self.assertEqual("refused", result["result"])
        self.assertEqual(["contacts", "queued"], result["unlisted_tables"])
        self.assertEqual(["contacts"], result["unlisted_large_tables"])
        self.assertEqual(["removed_table"], result["scope_tables_missing_from_snapshot"])

    def test_nonpreserving_class_requires_a_review_marker(self):
        for entry in ({"class": "B"}, {"class": "C", "approval_sha256": "0" * 64},
                      {"class": "D", "approval_sha256": "not-a-hash"}):
            with self.subTest(entry=entry):
                self.write_scope({"contacts": entry})
                with self.assertRaisesRegex(scope.schema_drift.Refusal, "approved-disposition-marker-required"):
                    self.audit()

    def test_duplicate_table_class_cannot_override_another_entry(self):
        self.policy.write_text('{"schema":"' + scope.SCOPE_SCHEMA + '","tables":'
                               '{"contacts":{"class":"D"},"contacts":{"class":"A"}}}', encoding="utf-8")
        self.policy.chmod(0o600)
        with self.assertRaisesRegex(scope.schema_drift.Refusal, "duplicate-scope-key"):
            self.audit()

    def test_unavailable_storage_refuses_even_with_exact_coverage(self):
        self.write_scope({"contacts": {"class": "A"}, "queued": {"class": "A"}})
        with patch.object(scope, "table_sizes", side_effect=scope.schema_drift.Refusal("dbstat-unavailable")):
            result = self.audit()
        self.assertFalse(result["source_scope_verified"])
        self.assertFalse(result["table_sizes_verified"])
        self.assertEqual("dbstat-unavailable", result["storage_error"])
        self.assertEqual([None, None], [item["allocated_bytes"] for item in result["tables"]])

    def test_partial_size_measurement_refuses_a_missing_index(self):
        db = Mock()
        db.execute.side_effect = (Mock(fetchone=Mock(return_value=(4096,))), [("contacts", 1, 4096)])
        with self.assertRaisesRegex(scope.schema_drift.Refusal, "incomplete-dbstat-coverage"):
            scope.table_sizes(db, {"contacts": ["contacts", "contacts_value_plain"]})

    def test_snapshot_sidecar_and_scope_checksum_are_refused(self):
        self.write_scope({"contacts": {"class": "A"}, "queued": {"class": "A"}})
        with self.assertRaisesRegex(scope.schema_drift.Refusal, "input-checksum-mismatch"):
            scope.audit(self.policy, "2" * 64, self.snapshot, self.sha256)
        Path(str(self.snapshot) + "-wal").touch()
        with self.assertRaisesRegex(scope.schema_drift.Refusal, "standalone-snapshot-has-sidecar"):
            self.audit()

    def test_actual_dbstat_includes_the_table_and_both_indexes(self):
        self.write_scope({"contacts": {"class": "A"}, "queued": {"class": "A"}})
        result = self.audit()
        if result["storage_error"] == "dbstat-unavailable":
            self.assertFalse(result["source_scope_verified"])
            self.skipTest("this Python SQLite build does not include DBSTAT")
        self.assertTrue(result["source_scope_verified"], result)
        self.assertEqual(3 * 4096, result["tables"][0]["allocated_bytes"])

    def test_cli_returns_nonzero_for_refusal_and_sanitizes_errors(self):
        self.write_scope({"contacts": {"class": "A"}})
        result = subprocess.run([sys.executable, "-B", scope.__file__, "--scope", self.policy,
                                 "--scope-sha256", scope.schema_drift.file_hash(self.policy),
                                 "--snapshot", self.snapshot, "--snapshot-sha256", "1" * 64],
                                check=False, capture_output=True, text=True)
        self.assertEqual(1, result.returncode)
        self.assertEqual("input-checksum-mismatch", json.loads(result.stdout)["reason"])
        self.assertEqual("", result.stderr)


if __name__ == "__main__":
    unittest.main()
