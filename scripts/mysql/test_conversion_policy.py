"""Focused regression checks for the snapshot conversion contract."""
import hashlib
import json
import os
import pathlib
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from contextlib import nullcontext
from unittest.mock import Mock, call, patch

import conversion


class ConversionPolicyTest(unittest.TestCase):
    def setUp(self):
        self.source = sqlite3.connect(":memory:")
        self.source.row_factory = sqlite3.Row
        self.addCleanup(self.source.close)

    def schema(self, table):
        sql = self.source.execute("SELECT sql FROM sqlite_schema WHERE name=?", (table,)).fetchone()[0]
        return conversion.table_schema(self.source, table, sql)

    def test_preserves_date_and_timestamp_representations(self):
        self.source.execute("CREATE TABLE events (id INTEGER PRIMARY KEY, day DATE, recorded DATETIME, slot TIME)")
        values = ("2026-11-22 00:00:00", "2026-11-22 00:00:00.123456+00:00", "09:00")
        self.source.execute("INSERT INTO events VALUES (1,?,?,?)", values)
        schema = self.schema("events")
        conversion.validate_source_values(self.source, [schema])
        for value, column in zip(values, schema["columns"][1:]):
            self.assertEqual("VARCHAR(64)", column["target_type"])
            self.assertEqual(["text", value], conversion.canonical(value, column))
        self.assertNotEqual(conversion.canonical(values[0], schema["columns"][1]),
                            conversion.canonical("2026-11-22", schema["columns"][1]))

    def test_schema_fingerprint_ignores_data_growth_within_reviewed_width(self):
        self.source.execute("CREATE TABLE events (id INTEGER PRIMARY KEY, label VARCHAR(255) UNIQUE)")
        before = self.schema("events")
        self.source.execute("INSERT INTO events VALUES (1,?)", ("x" * 200,))
        after = self.schema("events")
        self.assertEqual(200, after["columns"][1]["maximum_characters"])
        self.assertEqual(conversion.schema_fingerprint([before]), conversion.schema_fingerprint([after]))
        self.source.execute("INSERT INTO events VALUES (2,?)", ("y" * 256,))
        with self.assertRaisesRegex(ValueError, "reviewed width"):
            self.schema("events")

    def test_census_rejects_fractional_values_in_integer_columns(self):
        self.source.execute("CREATE TABLE money (id INTEGER PRIMARY KEY, cents INTEGER)")
        self.source.execute("INSERT INTO money VALUES (1,12.25)")
        with self.assertRaisesRegex(ValueError, r"money\.cents: 1 rows"):
            conversion.validate_source_values(self.source, [self.schema("money")])

    def test_tour_survey_index_preserves_full_values_with_declared_widths(self):
        self.source.executescript("""
            CREATE TABLE iguide_lab_tour_surveys (
                project_id VARCHAR NOT NULL,
                case_id VARCHAR NOT NULL,
                preview_sha256 VARCHAR NOT NULL,
                survey_version VARCHAR NOT NULL,
                generation INTEGER NOT NULL
            );
            CREATE UNIQUE INDEX iguide_lab_tour_surveys_identity_unique
                ON iguide_lab_tour_surveys (project_id, case_id, preview_sha256, survey_version, generation);
        """)
        schema = self.schema("iguide_lab_tour_surveys")
        self.assertEqual(["VARCHAR(255)", "VARCHAR(255)", "VARCHAR(64)", "VARCHAR(32)", "BIGINT"],
                         [column["target_type"] for column in schema["columns"]])
        self.assertLessEqual(4 * (255 + 255 + 64 + 32) + 8, 3072)
        self.source.execute("INSERT INTO iguide_lab_tour_surveys VALUES (?,?,?,?,?)",
                            ("project", "case", "a" * 64, "v" * 32, 1))
        self.schema("iguide_lab_tour_surveys")
        self.source.execute("INSERT INTO iguide_lab_tour_surveys VALUES (?,?,?,?,?)",
                            ("project", "case", "b" * 64, "v" * 33, 1))
        with self.assertRaisesRegex(ValueError, r"iguide_lab_tour_surveys\.survey_version"):
            self.schema("iguide_lab_tour_surveys")

    def test_conversion_rejects_nonzero_condition_count(self):
        connection = Mock()
        conversion.require_warning_free(0, connection)
        connection.show_warnings.assert_not_called()
        connection.show_warnings.return_value = [
            ("Warning", 1265, "private data"),
            ("Note", 1064, "private data"),
        ]
        with self.assertRaises(conversion.MySqlImportWarnings) as failure:
            conversion.require_warning_free(2, connection)
        self.assertEqual(2, failure.exception.count)
        self.assertEqual([("Note", 1064), ("Warning", 1265)], failure.exception.conditions)
        self.assertNotIn("private data", str(failure.exception))

    def insert_fixture(self, *batches):
        source, mysql, cursor = Mock(), Mock(), Mock()
        source.fetchmany.side_effect = [*batches, []]
        mysql.cursor.return_value = nullcontext(cursor)
        return source, mysql, cursor

    def test_insert_groups_bound_rows_without_changing_executemany_batches(self):
        statement = "INSERT INTO items VALUES (%s)"
        first = [(index,) for index in range(conversion.INSERT_BATCH_ROWS)]
        second = [(index + len(first),) for index in range(len(first))]
        last = [(len(first) + len(second),)]
        source, mysql, cursor = self.insert_fixture(first, second, last)

        with patch.object(conversion, "INSERT_TRANSACTION_ROWS", len(first) + len(second)):
            self.assertEqual(len(first) + len(second) + len(last), conversion.insert_rows(mysql, source, statement))

        self.assertEqual([call(conversion.INSERT_BATCH_ROWS)] * 4, source.fetchmany.call_args_list)
        self.assertEqual([
            call.execute("START TRANSACTION"),
            call.executemany(statement, first),
            call.executemany(statement, second),
            call.execute("COMMIT"),
            call.execute("START TRANSACTION"),
            call.executemany(statement, last),
            call.execute("COMMIT"),
        ], cursor.method_calls)

    def test_insert_groups_bound_encoded_bytes_and_do_not_group_large_batches(self):
        statement = "INSERT INTO items VALUES (%s,%s)"
        first = [("é 😀 \\\x00", b"\xff\x00\\")]
        second = [("🍀", b"payload")]
        large = [("x" * 1_000, b"")]
        last = [(None, 4.25)]
        source, mysql, cursor = self.insert_fixture(first, second, large, last)
        limit = sum(conversion.insert_batch_sql_bytes(statement, batch) for batch in (first, second)) - 1

        with patch.object(conversion, "INSERT_TRANSACTION_BYTES", limit):
            self.assertEqual(4, conversion.insert_rows(mysql, source, statement))

        self.assertEqual([
            call.execute("START TRANSACTION"),
            call.executemany(statement, first),
            call.execute("COMMIT"),
            call.execute("START TRANSACTION"),
            call.executemany(statement, second),
            call.execute("COMMIT"),
            call.executemany(statement, large),
            call.execute("START TRANSACTION"),
            call.executemany(statement, last),
            call.execute("COMMIT"),
        ], cursor.method_calls)

    def test_insert_warning_rolls_back_group_and_never_claims_success(self):
        statement = "INSERT INTO items VALUES (%s)"
        first, second, unused = [(1,)], [(2,)], [(3,)]
        warning = conversion.MySqlImportWarnings(1, [("Warning", 1265)])
        for rollback_unavailable in (False, True):
            with self.subTest(rollback_unavailable=rollback_unavailable):
                source, mysql, cursor = self.insert_fixture(first, second, unused)
                cursor.executemany.side_effect = [None, warning]
                if rollback_unavailable:
                    cursor.execute.side_effect = [None, RuntimeError("Connection closed")]
                with self.assertRaises(conversion.MySqlImportWarnings) as raised:
                    conversion.insert_rows(mysql, source, statement)
                self.assertIs(warning, raised.exception)
                self.assertEqual([
                    call.execute("START TRANSACTION"),
                    call.executemany(statement, first),
                    call.executemany(statement, second),
                    call.execute("ROLLBACK"),
                ], cursor.method_calls)
                self.assertEqual(2, source.fetchmany.call_count)

    def test_failed_commit_is_never_replayed_or_followed_by_another_write(self):
        statement = "INSERT INTO items VALUES (%s)"
        first, excess = [(1,)], [(2,)]
        source, mysql, cursor = self.insert_fixture(first, excess)
        cursor.execute.side_effect = [None, RuntimeError("Commit outcome unknown")]
        with patch.object(conversion, "INSERT_TRANSACTION_ROWS", 1):
            with self.assertRaisesRegex(RuntimeError, "Commit outcome unknown"):
                conversion.insert_rows(mysql, source, statement)
        self.assertEqual([
            call.execute("START TRANSACTION"),
            call.executemany(statement, first),
            call.execute("COMMIT"),
        ], cursor.method_calls)

    def test_only_reviewed_equivalent_shoot_indexes_are_marked_redundant(self):
        self.source.executescript("""
            CREATE TABLE shoots(id INTEGER PRIMARY KEY, status VARCHAR(32), client_id INTEGER,
                photographer_id INTEGER, editor_id INTEGER, scheduled_date DATE);
            CREATE INDEX shoots_status_index ON shoots(status);
            CREATE INDEX idx_shoots_status ON shoots(status);
            CREATE INDEX another_status_index ON shoots(status);
            CREATE INDEX shoots_client_id_index ON shoots(client_id);
            CREATE UNIQUE INDEX idx_shoots_client_id ON shoots(client_id);
            CREATE INDEX shoots_photographer_id_index ON shoots(photographer_id);
            CREATE INDEX idx_shoots_photographer_id ON shoots(photographer_id DESC);
            CREATE INDEX shoots_scheduled_date_index ON shoots(scheduled_date);
            CREATE INDEX idx_shoots_editor_id ON shoots(editor_id);
        """)
        marked = {index["name"]: index["target_redundant_with"]
                  for index in self.schema("shoots")["indexes"] if "target_redundant_with" in index}
        self.assertEqual({"idx_shoots_status": "shoots_status_index"}, marked)

    def test_real_mysql_forward_and_reverse(self):
        credentials_path = os.environ.get("REPRO_CONVERSION_TEST_CREDENTIALS")
        uuid = os.environ.get("REPRO_CONVERSION_TEST_UUID")
        if not credentials_path or not uuid:
            self.skipTest("requires the socket-only disposable conversion database")
        private_config = json.loads(pathlib.Path(credentials_path).read_text())
        self.assertEqual("repro_test", private_config["user"])
        self.assertEqual("/var/run/mysqld/mysqld.sock", private_config["unix_socket"])
        with conversion.WarningCheckedConnection(**private_config, database="repro_conversion_test",
                                                 autocommit=True) as mysql:
            with mysql.cursor() as cursor:
                cursor.execute("CREATE TEMPORARY TABLE warning_probe (id BIGINT PRIMARY KEY)")
                cursor.execute("INSERT INTO warning_probe VALUES (1)")
                cursor.max_stmt_length = len("INSERT IGNORE INTO warning_probe VALUES (1)")
                with self.assertRaises(conversion.MySqlImportWarnings) as failure:
                    cursor.executemany("INSERT IGNORE INTO warning_probe VALUES (%s)", [(1,), (2,)])
                self.assertEqual(1, failure.exception.count)
                self.assertEqual([("Warning", 1062)], failure.exception.conditions)
                cursor.execute("SELECT id FROM warning_probe ORDER BY id")
                self.assertEqual(((1,),), cursor.fetchall())
            with mysql.cursor(conversion.pymysql.cursors.SSCursor) as cursor:
                cursor.execute("SELECT CAST('not a number' AS UNSIGNED)")
                self.assertEqual([(0,)], list(cursor))
                with self.assertRaises(conversion.MySqlImportWarnings):
                    conversion.require_warning_free(cursor.warning_count, mysql)

        folder = pathlib.Path(__file__).parent.resolve()
        with tempfile.TemporaryDirectory() as path:
            root = pathlib.Path(path)
            source = root / "source.sqlite"
            with sqlite3.connect(source) as db:
                db.executescript("""
                    CREATE TABLE shoots(id INTEGER PRIMARY KEY, status VARCHAR(32));
                    CREATE INDEX shoots_status_index ON shoots(status);
                    CREATE INDEX idx_shoots_status ON shoots(status);
                    INSERT INTO shoots VALUES (1,'scheduled');
                    CREATE TABLE owners(id INTEGER PRIMARY KEY AUTOINCREMENT);
                    CREATE TABLE records(
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        owner_id INTEGER REFERENCES owners(id),
                        day DATE NOT NULL,
                        recorded DATETIME NOT NULL,
                        exact_amount DECIMAL(20,2),
                        payload BLOB
                    );
                    INSERT INTO owners VALUES(0);
                    INSERT INTO records VALUES(0,0,'2026-11-22','2026-11-22 09:00:00.123456+02:00',12.75,X'00FF');
                    INSERT INTO records VALUES(7,42,'2026-11-22 00:00:00','2026-11-22',2147483649,X'01');
                    UPDATE sqlite_sequence SET seq=22 WHERE name='records';
                    CREATE TABLE iguide_lab_tour_surveys (
                        id VARCHAR PRIMARY KEY,
                        project_id VARCHAR NOT NULL,
                        case_id VARCHAR NOT NULL,
                        preview_sha256 VARCHAR NOT NULL,
                        survey_version VARCHAR NOT NULL,
                        generation INTEGER NOT NULL
                    );
                    CREATE UNIQUE INDEX iguide_lab_tour_surveys_identity_unique
                        ON iguide_lab_tour_surveys (project_id, case_id, preview_sha256, survey_version, generation);
                    INSERT INTO iguide_lab_tour_surveys VALUES ('first','project','case',hex(zeroblob(32)),'tour-survey/1',1);
                """)
            checksum = lambda p: hashlib.sha256(p.read_bytes()).hexdigest()

            def invoke(script, *args, success=True):
                result = subprocess.run([sys.executable, str(folder / script), *map(str, args)],
                                        capture_output=True, text=True)
                failure_file = pathlib.Path(args[args.index("--output") + 1]) / "failure-private.json"
                detail = failure_file.read_text() if failure_file.is_file() else ""
                self.assertEqual(success, result.returncode == 0, result.stdout + result.stderr + detail)
                return json.loads(result.stdout.splitlines()[-1])

            plan_dir = root / "plan"
            source_args = ["--snapshot", source, "--snapshot-sha256", checksum(source)]
            planned = invoke("migrate.py", "plan", *source_args, "--output", plan_dir)
            exceptions = plan_dir / "exceptions-private.json"
            connection = ["--credentials", credentials_path, "--database", "repro_conversion_test",
                          "--expected-server-uuid", uuid, "--approved-exceptions", exceptions,
                          "--exceptions-file-sha256", checksum(exceptions)]
            invoke("migrate.py", "import-empty", *source_args, *connection,
                   "--schema-sha256", "0" * 64, "--output", root / "wrong-plan", success=False)
            imported = invoke("migrate.py", "import-empty", *source_args, *connection,
                              "--schema-sha256", planned["schema_sha256"], "--output", root / "import")
            self.assertTrue(imported["import_verified"])
            self.assertEqual(0, imported["mysql_warning_count"])
            self.assertEqual([{"table": "shoots", "omitted": "idx_shoots_status", "retained": "shoots_status_index"}],
                             imported["redundant_mysql_indexes"])

            with conversion.pymysql.connect(**json.loads(pathlib.Path(credentials_path).read_text()),
                                            database="repro_conversion_test", autocommit=True) as mysql:
                with mysql.cursor() as cursor:
                    cursor.execute("SELECT INDEX_NAME FROM information_schema.STATISTICS "
                                   "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shoots'")
                    self.assertEqual({"PRIMARY", "shoots_status_index"}, {row[0] for row in cursor.fetchall()})
                    with self.assertRaises(conversion.pymysql.err.IntegrityError):
                        cursor.execute("INSERT INTO records(owner_id,day,recorded) VALUES(99,'2026-11-22','2026-11-22')")
                    with self.assertRaises(conversion.pymysql.err.IntegrityError):
                        cursor.execute("INSERT INTO iguide_lab_tour_surveys SELECT 'other',project_id,case_id,preview_sha256,survey_version,generation FROM iguide_lab_tour_surveys WHERE id='first'")
                    cursor.execute("INSERT INTO records(owner_id,day,recorded) VALUES(0,'2026-11-23','2026-11-23')")
                    new_id = cursor.lastrowid
                    self.assertGreater(new_id, 22)
            output = root / "reverse"
            recovered = invoke("export-sqlite.py", *connection, "--schema-template", source,
                               "--schema-template-sha256", checksum(source), "--writers-stopped", "--output", output)
            self.assertTrue(recovered["verified"])
            with sqlite3.connect(output / "database.sqlite") as restored:
                self.assertEqual(("2026-11-22", "2026-11-22 09:00:00.123456+02:00", "00FF"),
                                 restored.execute("SELECT day,recorded,hex(payload) FROM records WHERE id=0").fetchone())
                self.assertEqual(("2026-11-23",), restored.execute("SELECT day FROM records WHERE id=?", (new_id,)).fetchone())
                self.assertEqual([("records", 7, "owners", 0)], restored.execute("PRAGMA foreign_key_check").fetchall())
                self.assertEqual({"shoots_status_index", "idx_shoots_status"},
                                 {row[1] for row in restored.execute("PRAGMA index_list('shoots')")})


if __name__ == "__main__":
    unittest.main()
