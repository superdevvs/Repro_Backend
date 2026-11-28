"""Synthetic reverse recovery fixtures. No server or customer data is used."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import re
import sqlite3
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch


SPEC = importlib.util.spec_from_file_location("export_sqlite", Path(__file__).with_name("export-sqlite.py"))
reverse = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(reverse)
MIGRATION = Path(__file__).resolve().parents[2] / (
    "database/migrations/2026_11_22_120200_preserve_iguide_lab_project_deletion.php"
)


def project_trigger():
    # Derive the fixture from the PHP migration so a real definition change
    # requires reviewing the reverse export's pin.
    statements = re.findall(r"'(DELETE FROM iguide_lab_\w+ WHERE project_id = OLD\.id;)'", MIGRATION.read_text())
    return {
        "TRIGGER_NAME": reverse.IGUIDE_PROJECT_TRIGGER,
        "EVENT_OBJECT_TABLE": "iguide_lab_projects",
        "ACTION_TIMING": "BEFORE",
        "EVENT_MANIPULATION": "DELETE",
        "ACTION_STATEMENT": "BEGIN " + "\n".join(statements) + " END",
    }


class FixtureSource:
    @contextlib.contextmanager
    def cursor(self, _kind=None):
        yield self

    def execute(self, statement):
        self.rows = [(7,)] if statement.startswith("SELECT `id` FROM `iguide_lab_projects` ") else []

    def fetchmany(self, _size):
        rows, self.rows = self.rows, []
        return rows

    def fetchall(self):
        return self.rows

    def rollback(self):
        pass

    def close(self):
        pass


class ReverseExportTest(unittest.TestCase):
    def test_missing_manifest_refuses_before_connecting_to_source(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            template = root / "template.sqlite"
            with contextlib.closing(sqlite3.connect(template)) as db:
                for table in ("aryeo_changes", "shoots", "shoot_files", "payments"):
                    db.execute(f"CREATE TABLE {table} (id INTEGER PRIMARY KEY)")
            approved = root / "approved.json"
            approved.write_text("[]")
            approved.chmod(0o600)
            options = SimpleNamespace(
                writers_stopped=True, output=root / "out", schema_template=template,
                schema_template_sha256=reverse.migrate.file_hash(template), approved_exceptions=approved,
                exceptions_file_sha256=reverse.migrate.file_hash(approved),
            )
            with patch.object(reverse, "CASCADE_TRIGGER_MANIFEST", root / "missing.json"), \
                    patch.object(reverse, "connect") as connect, \
                    self.assertRaisesRegex(RuntimeError, "cascade trigger manifest is missing or invalid"):
                reverse.export(options)
            connect.assert_not_called()
            self.assertFalse(options.output.exists())

    def test_cli_input_check_needs_no_credentials_or_output(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            template = root / "template.sqlite"
            with contextlib.closing(sqlite3.connect(template)) as db:
                db.execute("CREATE TABLE fixture (id INTEGER PRIMARY KEY)")
            approved = root / "approved.json"
            approved.write_text("[]")
            approved.chmod(0o600)
            args = [
                "export-sqlite.py", "--check-inputs-only", "--schema-template", str(template),
                "--schema-template-sha256", reverse.migrate.file_hash(template), "--approved-exceptions", str(approved),
                "--exceptions-file-sha256", reverse.migrate.file_hash(approved),
            ]
            stdout = io.StringIO()
            with patch.object(sys, "argv", args), patch.object(reverse, "connect") as connect, \
                    contextlib.redirect_stdout(stdout):
                reverse.main()
            connect.assert_not_called()
            report = json.loads(stdout.getvalue())
            self.assertEqual(report["result"], "reverse-inputs-verified")
            self.assertEqual(report["tables"], 1)
            self.assertFalse(report["mysql_connected"])
            self.assertFalse(report["output_created"])

    def test_new_trigger_uses_the_reviewed_migration_and_is_required(self):
        tables = reverse.IGUIDE_PROJECT_TABLES
        reverse.verify_source_triggers(tables, {}, [project_trigger()])
        with self.assertRaisesRegex(RuntimeError, "Unknown/missing source trigger"):
            reverse.verify_source_triggers(tables, {}, [])
        with self.assertRaisesRegex(RuntimeError, "Unknown/missing source trigger"):
            reverse.verify_source_triggers(tables, {}, [project_trigger(), {"TRIGGER_NAME": "unreviewed"}])

    def test_same_name_with_wrong_trigger_body_or_metadata_is_refused(self):
        known = project_trigger()
        for fields in (
            {"ACTION_STATEMENT": known["ACTION_STATEMENT"].replace("OLD.id", "OLD.other_id")},
            {"EVENT_OBJECT_TABLE": "iguide_lab_cases"},
            {"ACTION_TIMING": "AFTER"},
            {"EVENT_MANIPULATION": "UPDATE"},
        ):
            with self.subTest(fields=fields), self.assertRaisesRegex(RuntimeError, "definition differs"):
                reverse.verify_source_triggers(reverse.IGUIDE_PROJECT_TABLES, {}, [known | fields])

    def test_native_only_trigger_remains_unexpected_without_its_tables(self):
        older_tables = {"iguide_lab_projects", "iguide_lab_cases"}
        reverse.verify_source_triggers(older_tables, {}, [])
        with self.assertRaisesRegex(RuntimeError, "Unknown/missing source trigger"):
            reverse.verify_source_triggers(older_tables, {}, [project_trigger()])
        with self.assertRaisesRegex(RuntimeError, "cannot be in the SQLite template"):
            reverse.verify_source_triggers(reverse.IGUIDE_PROJECT_TABLES, {reverse.IGUIDE_PROJECT_TRIGGER: ""}, [])

    def test_exports_rows_and_sqlite_trigger_without_copying_native_only_trigger(self):
        for template_mode, output_mode in (("delete", None), ("wal", None), ("wal", "wal"), ("delete", "wal")):
            with self.subTest(template=template_mode, output=output_mode):
                self.check_export_in_frozen_journal_mode(template_mode, output_mode)

    def check_export_in_frozen_journal_mode(self, journal, output_mode):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            template = root / "template.sqlite"
            with contextlib.closing(sqlite3.connect(template)) as sqlite:
                self.assertEqual(journal, sqlite.execute("PRAGMA journal_mode=" + journal).fetchone()[0])
                for table in sorted(reverse.IGUIDE_PROJECT_TABLES):
                    sqlite.execute(f"CREATE TABLE {table} (id INTEGER PRIMARY KEY)")
                sqlite.execute(
                    "CREATE TRIGGER fixture_project_update AFTER INSERT ON iguide_lab_projects BEGIN SELECT 1; END"
                )
            exceptions = root / "exceptions-private.json"
            exceptions.write_text("[]")
            exceptions.chmod(0o600)
            options = SimpleNamespace(
                writers_stopped=True, output=root / "result", schema_template=template,
                schema_template_sha256=reverse.migrate.file_hash(template), approved_exceptions=exceptions,
                exceptions_file_sha256=reverse.migrate.file_hash(exceptions),
                expected_server_uuid="ec219d68-61fd-4a9e-ba98-43ddb4008383",
            )
            if output_mode:
                options.sqlite_journal_mode = output_mode
            metadata = {table: {"id": False} for table in reverse.IGUIDE_PROJECT_TABLES}
            state = metadata, [project_trigger(), {"TRIGGER_NAME": "fixture_project_update"}], {}
            with (
                patch.object(reverse, "connect", return_value=FixtureSource()),
                patch.object(reverse, "schema_state", return_value=state),
                patch.object(reverse.core, "pymysql", SimpleNamespace(cursors=SimpleNamespace(SSCursor=object()))),
                patch.object(reverse.core, "REPORTS", None),
                contextlib.redirect_stdout(io.StringIO()),
            ):
                reverse.export(options)

            result = json.loads((options.output / "result.json").read_text())
            self.assertTrue(result["verified"])
            self.assertEqual(output_mode or "delete", result["sqlite_journal_mode"])
            artifact = options.output / "database.sqlite"
            self.assertEqual(reverse.migrate.file_hash(artifact), result["database_sha256"])
            self.assertEqual(journal, reverse.journal_mode_from_header(template))
            self.assertEqual(result["sqlite_journal_mode"], reverse.journal_mode_from_header(artifact))
            for suffix in ("-wal", "-shm", "-journal"):
                self.assertFalse(Path(str(artifact) + suffix).exists())
            with contextlib.closing(sqlite3.connect(artifact.as_uri() + "?mode=ro", uri=True)) as sqlite:
                self.assertEqual(result["sqlite_journal_mode"], sqlite.execute("PRAGMA journal_mode=" + result["sqlite_journal_mode"]).fetchone()[0])
                self.assertEqual([(7,)], sqlite.execute("SELECT id FROM iguide_lab_projects").fetchall())
                self.assertEqual(
                    [("fixture_project_update",)],
                    sqlite.execute("SELECT name FROM sqlite_schema WHERE type='trigger'").fetchall(),
                )
            self.assertEqual(reverse.migrate.file_hash(artifact), result["database_sha256"])


if __name__ == "__main__":
    unittest.main()
