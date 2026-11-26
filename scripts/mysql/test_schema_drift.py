"""Synthetic SQLite files only. No production paths, network or data migrations."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import sqlite3
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("schema_drift", Path(__file__).with_name("schema-drift.py"))
s = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(s)


class SchemaDriftTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()
        self.counter = 0

    def fixture(self, sql):
        self.counter += 1
        path = self.root / (str(self.counter) + ".sqlite")
        db = sqlite3.connect(path)
        try:
            db.executescript(sql)
            db.commit()
        finally:
            db.close()
        path.chmod(0o600)
        return path

    def compare(self, left, right):
        a, b = self.fixture(left), self.fixture(right)
        return s.compare(a, s.file_hash(a), b, s.file_hash(b), "a" * 40)

    def test_column_order_and_autoindex_numbers_are_ignored(self):
        left = "CREATE TABLE t(a TEXT UNIQUE,b TEXT UNIQUE,c INTEGER,PRIMARY KEY(a,c));"
        right = "CREATE TABLE t(c INTEGER,b TEXT UNIQUE,a TEXT UNIQUE,PRIMARY KEY(a,c));"
        report = self.compare(left, right)
        self.assertTrue(report["match"])
        self.assertEqual(report["release_schema_sha256"], report["snapshot_schema_sha256"])

    def test_row_contents_are_irrelevant_and_never_reported(self):
        schema = "CREATE TABLE t(id INTEGER PRIMARY KEY, payload TEXT);"
        report = self.compare(schema + "INSERT INTO t VALUES(1,'PRIVATE_PAYLOAD_A');",
                              schema + "INSERT INTO t VALUES(999,'PRIVATE_PAYLOAD_B');")
        self.assertTrue(report["match"])
        self.assertNotIn("PRIVATE_PAYLOAD", json.dumps(report))
        self.assertFalse(report["table_data_queried"])

    def test_default_changes_are_detected_without_literal_disclosure(self):
        report = self.compare("CREATE TABLE t(value TEXT DEFAULT 'PRIVATE_DEFAULT_A');",
                              "CREATE TABLE t(value TEXT DEFAULT 'PRIVATE_DEFAULT_B');")
        self.assertFalse(report["match"])
        self.assertEqual(report["changes"][0]["kind"], "column")
        self.assertIn("default", report["changes"][0]["attributes"])
        self.assertNotIn("PRIVATE_DEFAULT", json.dumps(report))

    def test_bare_word_default_case_is_a_value_change(self):
        report = self.compare("CREATE TABLE t(value TEXT DEFAULT Foo);",
                              "CREATE TABLE t(value TEXT DEFAULT foo);")
        self.assertFalse(report["match"])
        self.assertIn("default", report["changes"][0]["attributes"])
        self.assertNotIn("Foo", json.dumps(report))

    def test_primary_key_order_is_significant(self):
        report = self.compare("CREATE TABLE t(a INT,b INT,PRIMARY KEY(a,b));",
                              "CREATE TABLE t(a INT,b INT,PRIMARY KEY(b,a));")
        self.assertIn("primary_key", {item["kind"] for item in report["changes"]})

    def test_foreign_key_action_and_deferral_detected(self):
        prefix = "CREATE TABLE p(id INTEGER PRIMARY KEY);"
        left = prefix + "CREATE TABLE c(id INTEGER REFERENCES p(id) ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED);"
        right = prefix + "CREATE TABLE c(id INTEGER REFERENCES p(id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE);"
        report = self.compare(left, right)
        self.assertIn("foreign_keys", {item["kind"] for item in report["changes"]})
        self.assertIn("foreign_key_declarations", {item["kind"] for item in report["changes"]})
        # PRAGMA is identical for deferral-only changes; source SQL must catch it.
        report = self.compare(left, left.replace("INITIALLY DEFERRED", "INITIALLY IMMEDIATE"))
        self.assertIn("foreign_key_declarations", {item["kind"] for item in report["changes"]})

    def test_foreign_key_declaration_order_ignored(self):
        prefix = "CREATE TABLE p(id INTEGER PRIMARY KEY);CREATE TABLE q(id INTEGER PRIMARY KEY);"
        left = prefix + "CREATE TABLE c(a INT,b INT,FOREIGN KEY(a) REFERENCES p(id),FOREIGN KEY(b) REFERENCES q(id));"
        right = prefix + "CREATE TABLE c(b INT,a INT,FOREIGN KEY(b) REFERENCES q(id),FOREIGN KEY(a) REFERENCES p(id));"
        self.assertTrue(self.compare(left, right)["match"])

    def test_nested_check_and_string_case_preserved(self):
        left = "CREATE TABLE t(a TEXT CHECK(instr(a,'PRIVATE_Check_(')>0), b INT CHECK((b+2)>1));"
        right = left.replace("PRIVATE_Check_(", "PRIVATE_check_(")
        report = self.compare(left, right)
        self.assertIn("checks", {item["kind"] for item in report["changes"]})
        self.assertNotIn("PRIVATE_", json.dumps(report))

    def test_generated_column_and_collation_detected(self):
        left = "CREATE TABLE t(a TEXT COLLATE NOCASE,b TEXT GENERATED ALWAYS AS(lower(a)) VIRTUAL);"
        right = "CREATE TABLE t(a TEXT COLLATE BINARY,b TEXT GENERATED ALWAYS AS(upper(a)) STORED);"
        report = self.compare(left, right)
        self.assertEqual({item["name"] for item in report["changes"] if item["kind"] == "column"}, {"a", "b"})

    def test_partial_expression_index_direction_and_uniqueness_detected(self):
        prefix = "CREATE TABLE t(a TEXT,b INT);"
        left = prefix + "CREATE INDEX idx ON t(lower(a),b DESC) WHERE b>0;"
        right = prefix + "CREATE UNIQUE INDEX idx ON t(upper(a),b ASC) WHERE b>=0;"
        report = self.compare(left, right)
        change = next(item for item in report["changes"] if item["kind"] == "index")
        self.assertEqual(change["name"], "idx")
        self.assertIn("unique", change["attributes"])
        self.assertIn("keys", change["attributes"])
        self.assertIn("sql_tokens", change["attributes"])

    def test_explicit_index_name_is_not_ignored(self):
        prefix = "CREATE TABLE t(a INT);"
        report = self.compare(prefix + "CREATE INDEX first_name ON t(a);",
                              prefix + "CREATE INDEX second_name ON t(a);")
        self.assertEqual(len(report["changes"]), 2)
        self.assertEqual({item["change"] for item in report["changes"]}, {"missing-in-snapshot", "extra-in-snapshot"})

    def test_triggers_and_views_are_compared_without_sql_disclosure(self):
        prefix = "CREATE TABLE t(a INT);"
        left = prefix + "CREATE TRIGGER guard BEFORE INSERT ON t BEGIN SELECT RAISE(ABORT,'PRIVATE_TRIGGER_A');END;CREATE VIEW v AS SELECT a FROM t;"
        right = left.replace("PRIVATE_TRIGGER_A", "PRIVATE_TRIGGER_B").replace("SELECT a FROM t", "SELECT a+1 FROM t")
        report = self.compare(left, right)
        self.assertEqual({item["kind"] for item in report["changes"]}, {"trigger", "view"})
        self.assertNotIn("PRIVATE_TRIGGER", json.dumps(report))
        self.assertNotIn("RAISE", json.dumps(report))

    def test_view_alias_and_named_constraint_spelling_preserved(self):
        prefix = "CREATE TABLE t(a INT);"
        report = self.compare(prefix + "CREATE VIEW v AS SELECT a AS ResultName FROM t;",
                              prefix + "CREATE VIEW v AS SELECT a AS resultname FROM t;")
        self.assertEqual(report["changes"][0]["kind"], "view")
        report = self.compare("CREATE TABLE t(a INT CONSTRAINT Positive CHECK(a>0));",
                              "CREATE TABLE t(a INT CONSTRAINT positive CHECK(a>0));")
        self.assertIn("constraint_names", {item["kind"] for item in report["changes"]})

    def test_table_options_autoincrement_and_missing_objects_detected(self):
        report = self.compare("CREATE TABLE t(a INTEGER PRIMARY KEY);",
                              "CREATE TABLE t(a INTEGER PRIMARY KEY) WITHOUT ROWID;")
        self.assertIn("table_options", {item["kind"] for item in report["changes"]})
        report = self.compare("CREATE TABLE t(a INTEGER PRIMARY KEY);",
                              "CREATE TABLE t(a INTEGER PRIMARY KEY AUTOINCREMENT);")
        self.assertFalse(report["match"])
        report = self.compare("CREATE TABLE t(a INTEGER) STRICT;", "CREATE TABLE t(a INTEGER);")
        self.assertIn("table_options", {item["kind"] for item in report["changes"]})
        report = self.compare("CREATE TABLE t(a INT);", "CREATE TABLE other(a INT);")
        self.assertEqual({item["kind"] for item in report["changes"]}, {"table"})

    def test_whitespace_comment_and_unquoted_case_normalize(self):
        self.assertTrue(self.compare("CREATE TABLE t(a TEXT DEFAULT 'KeepCase',b INT CHECK(b>0));",
            "create table t(a text /* comment */ default 'KeepCase',b int check ( b > 0 ));")["match"])

    def test_internal_filter_does_not_hide_application_prefix(self):
        report = self.compare("CREATE TABLE sqliteXapplication(a INT);",
                              "CREATE TABLE sqliteXapplication(a TEXT);")
        self.assertFalse(report["match"])
        self.assertEqual(report["release_tables"], 1)

    def test_metadata_reader_works_when_user_table_reads_are_denied(self):
        db = sqlite3.connect(":memory:")
        self.addCleanup(db.close)
        db.executescript("CREATE TABLE payload(id INTEGER PRIMARY KEY,data TEXT);INSERT INTO payload VALUES(1,'PRIVATE');")
        queries = []
        db.set_trace_callback(queries.append)
        def authorize(action, first, second, database, source):
            if action == sqlite3.SQLITE_READ and first not in ("sqlite_master", "sqlite_schema"):
                return sqlite3.SQLITE_DENY
            if action in (sqlite3.SQLITE_INSERT, sqlite3.SQLITE_UPDATE, sqlite3.SQLITE_DELETE):
                return sqlite3.SQLITE_DENY
            return sqlite3.SQLITE_OK
        db.set_authorizer(authorize)
        schema = s.schema_from_connection(db)
        self.assertIn("payload", schema["tables"])
        self.assertTrue(all(query.startswith("PRAGMA ") or "FROM sqlite_schema" in query for query in queries))

    def test_checksum_sidecar_and_unreadable_inputs_fail_closed(self):
        path = self.fixture("CREATE TABLE t(a INT);")
        good = s.file_hash(path)
        with self.assertRaises(s.Refusal):
            s.checked_file(path, "0" * 64)
        for suffix in ("-wal", "-shm", "-journal"):
            sidecar = Path(str(path) + suffix)
            sidecar.write_bytes(b"")
            try:
                with self.subTest(suffix=suffix), self.assertRaises(s.Refusal):
                    s.checked_file(path, good)
            finally:
                sidecar.unlink()
        with self.assertRaises(s.Refusal):
            s.checked_file(self.root / "missing.sqlite", good)

    def test_cli_drift_nonzero_and_databases_unchanged(self):
        left = self.fixture("CREATE TABLE t(a INT);")
        right = self.fixture("CREATE TABLE t(a TEXT DEFAULT 'PRIVATE_VALUE');")
        before = [s.file_hash(left), s.file_hash(right)]
        argv = ["schema-drift.py", "--release", str(left), "--release-sha256", before[0],
                "--release-commit", "a" * 40, "--snapshot", str(right), "--snapshot-sha256", before[1]]
        with patch("sys.argv", argv), contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(s.main(), 1)
        report = json.loads(output.getvalue())
        self.assertEqual(report["result"], "schema-drift")
        self.assertNotIn("PRIVATE_VALUE", output.getvalue())
        self.assertEqual(before, [s.file_hash(left), s.file_hash(right)])
        self.assertEqual(sorted(path.name for path in self.root.iterdir()), [left.name, right.name])

    def test_corrupt_snapshot_error_is_sanitized(self):
        left = self.fixture("CREATE TABLE t(a INT);")
        right = self.root / "corrupt.sqlite"
        right.write_bytes(b"PRIVATE_CORRUPT_CONTENT")
        right.chmod(0o600)
        argv = ["schema-drift.py", "--release", str(left), "--release-sha256", s.file_hash(left),
                "--release-commit", "a" * 40, "--snapshot", str(right), "--snapshot-sha256", s.file_hash(right)]
        with patch("sys.argv", argv), contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(s.main(), 1)
        self.assertNotIn("PRIVATE", output.getvalue())
        self.assertEqual(json.loads(output.getvalue())["result"], "REFUSED")


if __name__ == "__main__":
    unittest.main()
