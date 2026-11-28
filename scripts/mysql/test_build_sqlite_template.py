"""Test metadata-only template building against synthetic standalone copies."""
from contextlib import closing
import hashlib
import importlib.util
from pathlib import Path
import os
import sqlite3
import stat
import tempfile
from unittest.mock import patch
import unittest


SPEC = importlib.util.spec_from_file_location("build_template", Path(__file__).with_name("build-sqlite-template.py"))
builder = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(builder)


class BuildTemplateTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.private = Path(temporary.name).resolve()
        self.snapshot = self.private / "frozen-copy.sqlite"
        self.output = self.private / "template"

    def fixture(self, ddl):
        with closing(sqlite3.connect(self.snapshot)) as db:
            db.executescript(ddl)
        self.snapshot.chmod(0o600)
        return self.checksum(self.snapshot)

    @staticmethod
    def checksum(path):
        with path.open("rb") as stream:
            return hashlib.file_digest(stream, "sha256").hexdigest()

    def test_keeps_exact_schema_without_copying_rows_or_sequences(self):
        pinned = self.fixture("""
            CREATE TABLE historical_assets (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT UNIQUE);
            CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT);
            CREATE TABLE assets (id INTEGER PRIMARY KEY, owner_id INTEGER, original_id INTEGER, tool TEXT,
                FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (original_id) REFERENCES historical_assets(id) ON DELETE CASCADE);
            CREATE UNIQUE INDEX partial_tool ON assets (owner_id, COALESCE(tool, '')) WHERE tool IS NOT NULL;
            CREATE TRIGGER after_asset_1 AFTER INSERT ON assets BEGIN UPDATE assets SET tool = 'first' WHERE id=NEW.id; END;
            CREATE TRIGGER after_asset_2 AFTER INSERT ON assets BEGIN UPDATE assets SET tool = 'second' WHERE id=NEW.id; END;
            INSERT INTO historical_assets VALUES (99, 'synthetic-marker-not-copied');
        """)
        result = builder.build(self.snapshot, pinned, self.output)
        target = self.output / "schema-template.sqlite"
        self.assertEqual({"table": 3, "index": 1, "trigger": 2}, result["schema_objects"])
        self.assertEqual(self.checksum(target), result["template_sha256"])
        self.assertFalse(result["rows_copied"])
        self.assertFalse(result["overlay_applied"])
        self.assertFalse(result["ready_for_reverse_export"])
        self.assertNotIn(b"synthetic-marker-not-copied", target.read_bytes())
        with closing(sqlite3.connect(self.snapshot)) as source, closing(sqlite3.connect(target)) as template:
            self.assertEqual(builder.definitions(source), builder.definitions(template))
            self.assertEqual(source.execute("PRAGMA foreign_key_list(assets)").fetchall(),
                             template.execute("PRAGMA foreign_key_list(assets)").fetchall())
            self.assertEqual([], template.execute("SELECT * FROM historical_assets").fetchall())
            self.assertEqual([], template.execute("SELECT * FROM sqlite_sequence").fetchall())
        self.assertEqual(pinned, self.checksum(self.snapshot))
        if os.name != "nt":
            self.assertEqual(0o700, stat.S_IMODE(self.output.stat().st_mode))
            for path in (target, self.output / "result.json"):
                self.assertEqual(0o600, stat.S_IMODE(path.stat().st_mode))

    def test_source_authorizer_refuses_user_rows_and_views(self):
        pinned = self.fixture("""
            CREATE TABLE protected_rows (id INTEGER PRIMARY KEY, value TEXT);
            CREATE VIEW protected_view AS SELECT value FROM protected_rows;
            INSERT INTO protected_rows VALUES (1, 'synthetic-row');
        """)
        with closing(sqlite3.connect(self.snapshot.as_uri() + "?mode=ro&immutable=1", uri=True)) as db:
            db.set_authorizer(builder.catalog_only)
            self.assertEqual(2, len(builder.definitions(db)))
            for sql in ("SELECT value FROM protected_rows", "SELECT * FROM protected_view"):
                with self.subTest(sql=sql), self.assertRaises(sqlite3.DatabaseError):
                    db.execute(sql)
        with self.assertRaisesRegex(builder.Refused, "unsupported-schema-object"):
            builder.build(self.snapshot, pinned, self.output)
        self.assertFalse(self.output.exists())

    def test_write_authorizer_refuses_create_table_as_select(self):
        with self.assertRaisesRegex(sqlite3.DatabaseError, "not authorized"):
            builder.create_template(self.private / "pending.sqlite", [
                ("table", "copy", "copy", "CREATE TABLE copy AS SELECT 7 AS secret"),
            ])

    def test_wrong_hash_sidecar_and_existing_output_fail_without_replacement(self):
        pinned = self.fixture("CREATE TABLE retained (id INTEGER PRIMARY KEY)")
        with self.assertRaisesRegex(builder.Refused, "source-checksum-mismatch"):
            builder.build(self.snapshot, "f" * 64, self.output)
        self.snapshot.with_suffix(".sqlite-wal").write_text("fixture sidecar")
        with self.assertRaisesRegex(builder.Refused, "standalone-snapshot-has-sidecar"):
            builder.build(self.snapshot, pinned, self.output)
        self.snapshot.with_suffix(".sqlite-wal").unlink()
        self.output.mkdir()
        existing = self.output / "schema-template.sqlite"
        existing.write_bytes(b"existing fixture")
        with self.assertRaisesRegex(builder.Refused, "output-must-be-new"):
            builder.build(self.snapshot, pinned, self.output)
        self.assertEqual(b"existing fixture", existing.read_bytes())

    def test_changed_metadata_during_read_refuses_template(self):
        pinned = self.fixture("CREATE TABLE retained (id INTEGER PRIMARY KEY)")
        original = builder.definitions

        def change_stamp(db):
            result = original(db)
            info = self.snapshot.stat()
            os.utime(self.snapshot, ns=(info.st_atime_ns, info.st_mtime_ns + 10**9))
            return result

        with patch.object(builder, "definitions", change_stamp):
            with self.assertRaisesRegex(builder.Refused, "source-metadata-changed"):
                builder.build(self.snapshot, pinned, self.output)
        self.assertFalse(self.output.exists())

    def test_messaging_overlay_adds_optional_columns_and_preserves_existing_key_ordinals(self):
        pinned = self.fixture("""
            CREATE TABLE contacts (id INTEGER PRIMARY KEY);
            CREATE TABLE users (id INTEGER PRIMARY KEY);
            CREATE TABLE sms_groups (id INTEGER PRIMARY KEY);
            CREATE TABLE message_threads (
                id INTEGER PRIMARY KEY, contact_id INTEGER, sms_group_id INTEGER,
                FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
                FOREIGN KEY (sms_group_id) REFERENCES sms_groups(id) ON DELETE SET NULL
            );
            CREATE TRIGGER untouched AFTER INSERT ON users BEGIN SELECT NEW.id; END;
            INSERT INTO contacts VALUES (42);
        """)
        result = builder.build(self.snapshot, pinned, self.output, rate_mib=100,
                               messaging_overlay=True)
        self.assertEqual(6, len(result["overlay_added_columns"]))
        self.assertTrue(result["overlay_applied"])
        self.assertFalse(result["ready_for_reverse_export"])
        target = self.output / "schema-template.sqlite"
        with closing(sqlite3.connect(self.snapshot)) as source, closing(sqlite3.connect(target)) as template:
            old = list(source.execute("PRAGMA foreign_key_list(message_threads)"))
            new = list(template.execute("PRAGMA foreign_key_list(message_threads)"))
            self.assertEqual(old, [row for row in new if row[3] != "assigned_to_user_id"])
            self.assertEqual("SET NULL", next(row[6] for row in new if row[3] == "assigned_to_user_id"))
            self.assertEqual([], template.execute("SELECT * FROM contacts").fetchall())
            self.assertEqual([row for row in builder.definitions(source) if row[0] == "trigger"],
                             [row for row in builder.definitions(template) if row[0] == "trigger"])
        self.assertEqual(pinned, self.checksum(self.snapshot))

    def test_messaging_overlay_refuses_unexpected_required_column(self):
        pinned = self.fixture("""
            CREATE TABLE contacts (id INTEGER PRIMARY KEY, phones_json TEXT NOT NULL);
            CREATE TABLE users (id INTEGER PRIMARY KEY);
            CREATE TABLE message_threads (id INTEGER PRIMARY KEY);
        """)
        with self.assertRaisesRegex(builder.Refused, "preexisting-optional-column-is-required"):
            builder.build(self.snapshot, pinned, self.output, messaging_overlay=True)
        self.assertFalse((self.output / "schema-template.sqlite").exists())


if __name__ == "__main__":
    unittest.main()
