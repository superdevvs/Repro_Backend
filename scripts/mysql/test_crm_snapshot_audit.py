"""CRM parity audit contracts using generated SQLite rows only."""

from contextlib import closing
import importlib.util
import json
from pathlib import Path
import re
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import unittest


SPEC = importlib.util.spec_from_file_location("crm_audit", Path(__file__).with_name("crm-snapshot-audit.py"))
crm = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(crm)


class CrmSnapshotTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.root = Path(folder.name).resolve()
        self.snapshot = self.root / "copied.sqlite"
        self.key = self.root / "audit-key"
        self.key.write_bytes(bytes(range(32)))
        self.key.chmod(0o600)

        with closing(sqlite3.connect(self.snapshot)) as db:
            for table in sorted(crm.CRM_TABLES | crm.SHARED_TABLES):
                identity = "reference TEXT PRIMARY KEY" if table == "recruit_application_receipts" else "id INTEGER PRIMARY KEY"
                fields = {name: "INTEGER" for name in crm.REFERENCES.get(table, {})}
                extras = {
                    "crm_activities": {"subject_type": "TEXT", "subject_id": "INTEGER"},
                    "crm_email_sends": {"source_type": "TEXT", "source_id": "INTEGER"},
                }
                fields.update(extras.get(table, {}))
                columns = [identity, *(f"{name} {kind}" for name, kind in fields.items())]
                db.execute(f"CREATE TABLE {table} ({','.join(columns)})")

            db.execute("CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)")
            db.executemany("INSERT INTO migrations VALUES (?, ?, 13)", enumerate(crm.MIGRATIONS, start=151))
            db.executescript("""
                INSERT INTO users VALUES (412345), (412346);
                INSERT INTO crm_contacts (id, client_user_id, assigned_rep_id) VALUES
                    (912345, 412345, 412346), (912346, NULL, 812345);
                INSERT INTO recruit_application_receipts VALUES ('private-reference-fixture', 812346);
                INSERT INTO crm_activities (id, contact_id, subject_id, subject_type) VALUES
                    (512345, 912345, 812347, 'note');
            """)
        self.snapshot.chmod(0o600)

    def run_audit(self, other=None, *, digest=None):
        cmd = [sys.executable, "-B", crm.__file__, "--snapshot", str(self.snapshot),
               "--snapshot-sha256", digest or crm.schema_drift.file_hash(self.snapshot), "--key-file", str(self.key)]
        if other:
            cmd.extend(["--compare-snapshot", str(other), "--compare-snapshot-sha256", crm.schema_drift.file_hash(other)])
        result = subprocess.run(cmd, capture_output=True, text=True, check=False)
        self.assertEqual("", result.stderr)
        self.assertNotIn("private-reference-fixture", result.stdout)
        # Identity strings must not appear as JSON numeric fields or quoted values.
        self.assertIsNone(re.search(r'(?<![0-9a-f])(?:912345|812345|812346|412345)(?![0-9a-f])', result.stdout))
        return result.returncode, json.loads(result.stdout)

    def test_qualified_snapshot_preserves_exact_counts_and_fingerprints(self):
        before = crm.schema_drift.file_hash(self.snapshot)
        comparable = self.root / "reverse.sqlite"
        shutil.copyfile(self.snapshot, comparable)
        comparable.chmod(0o600)
        code, report = self.run_audit(comparable)
        self.assertEqual(0, code, report)
        self.assertEqual({"migrations": True, "references": True, "table_counts": True}, report["parity"])
        self.assertEqual(2, report["table_counts"]["crm_contacts"])
        self.assertEqual(10, report["migrations"]["count"])
        links = report["references"]
        self.assertEqual((1, 1, 0), tuple(links["crm_contacts.client_user_id->users.id"][name]
                                         for name in ("linked", "null", "missing")))
        self.assertEqual(1, links["crm_contacts.assigned_rep_id->users.id"]["missing"])
        self.assertEqual(1, links["recruit_application_receipts.applicant_id->recruit_applicants.id"]["missing"])
        self.assertEqual(1, links["crm_activities.subject_id->crm_notes.id[subject_type=note]"]["missing"])
        self.assertEqual(before, crm.schema_drift.file_hash(self.snapshot))

    def test_equal_orphan_count_with_another_missing_id_fails_parity(self):
        other = self.root / "mismatched.sqlite"
        shutil.copyfile(self.snapshot, other)
        other.chmod(0o600)
        with closing(sqlite3.connect(other)) as db, db:
            db.execute("UPDATE crm_contacts SET assigned_rep_id=812349 WHERE assigned_rep_id=812345")
        code, report = self.run_audit(other)
        self.assertEqual(1, code)
        self.assertEqual("crm-snapshot-parity-differs", report["reason"])
        self.assertEqual({"migrations": True, "references": False, "table_counts": True}, report["parity"])

    def test_snapshot_refuses_unpopulated_links_and_unreviewed_release_changes(self):
        with closing(sqlite3.connect(self.snapshot)) as db, db:
            db.execute("UPDATE crm_contacts SET client_user_id = NULL")
        code, report = self.run_audit()
        self.assertEqual(1, code)
        self.assertEqual("crm-client-links-unpopulated", report["reason"])
        with closing(sqlite3.connect(self.snapshot)) as db, db:
            db.execute("DELETE FROM migrations WHERE migration=?", (crm.MIGRATIONS[0],))
        self.assertEqual("crm-migration-ledger-differs", self.run_audit()[1]["reason"])
        with closing(sqlite3.connect(self.snapshot)) as db, db:
            db.execute("CREATE TABLE crm_future_scope(id INTEGER PRIMARY KEY)")
        self.assertEqual("crm-table-set-differs", self.run_audit()[1]["reason"])

    def test_snapshot_without_the_25_november_crm_migration_is_refused(self):
        # The capture already requires it (nightly-config.json crm_migrations); the audit's
        # ledger fingerprint and parity must cover it too, not only the 24 November nine.
        with closing(sqlite3.connect(self.snapshot)) as db, db:
            db.execute("DELETE FROM migrations WHERE migration=?",
                       ("2026_11_25_140000_add_replay_identity_to_recruit_application_receipts",))
        code, report = self.run_audit()
        self.assertEqual(1, code)
        self.assertEqual("crm-migration-ledger-differs", report["reason"])

    def test_snapshot_with_an_unreviewed_crm_migration_is_refused(self):
        # A CRM migration this release has not reviewed stops the audit, as a missing one does. The
        # reviewed layout pin catches one that changes a table, but not a data-only one.
        with closing(sqlite3.connect(self.snapshot)) as db, db:
            db.execute("INSERT INTO migrations VALUES (200, '2026_12_01_000000_add_notes_to_shoots', 14)")
        code, report = self.run_audit()
        self.assertEqual(0, code, report)
        self.assertEqual(10, report["migrations"]["count"])
        for name in ("2026_12_01_000000_backfill_crm_contact_sources", "2026_12_01_000000_archive_recruit_notes",
                     "2026_12_01_000000_prune_email_suppressions"):
            with self.subTest(name=name):
                with closing(sqlite3.connect(self.snapshot)) as db, db:
                    db.execute("DELETE FROM migrations WHERE id = 201")
                    db.execute("INSERT INTO migrations VALUES (201, ?, 14)", (name,))
                code, report = self.run_audit()
                self.assertEqual(1, code)
                self.assertEqual("crm-migration-ledger-differs", report["reason"])

    def test_no_other_migration_of_the_release_or_the_baseline_ledger_has_a_crm_name(self):
        # The name rule must not refuse a ledger this release produces: every migration file, and every
        # row of the production-derived baseline ledger, has a CRM name only if it is in MIGRATIONS.
        root = Path(__file__).resolve().parents[2]
        names = {path.stem for path in (root / "database/migrations").glob("*.php")}
        sql = (root / "database/schema/mysql-schema.sql").read_text(encoding="utf-8")
        names |= set(re.findall(r"\(\d+,'(\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+)',\d+\)", sql))
        self.assertGreater(len(names), 400)
        self.assertEqual(sorted(crm.MIGRATIONS), sorted(name for name in names if crm.CRM_MIGRATION_NAME.search(name)))

    def test_reviewed_tables_references_and_ledger_cover_candidate_layout(self):
        root = Path(__file__).resolve().parents[2]
        sql = (root / "database/schema/mysql-schema.sql").read_text(encoding="utf-8")
        tables = {name: set(re.findall(r"^  `(\w+)` ", body, re.M)) for name, body in
                  re.findall(r"^CREATE TABLE `([^`]+)` \((.*?)^\)", sql, re.M | re.S)}
        expected = {name for name in tables if name.startswith(("crm_", "recruit_")) or name == "email_suppressions"}
        self.assertEqual(37, len(expected))
        self.assertEqual(expected, crm.CRM_TABLES)
        for table, fields in crm.REFERENCES.items():
            self.assertLessEqual(fields.keys(), tables[table])
            for parent in fields.values():
                self.assertIn("id", tables[parent])
        # The nightly capture requires exactly these in the live ledger. test_runner.py ties that
        # list to every migration that creates or changes a CRM table.
        config = json.loads((root / "scripts/cutover/nightly/nightly-config.json").read_bytes())
        self.assertEqual(list(crm.MIGRATIONS), config["crm_migrations"])


if __name__ == "__main__":
    unittest.main()
