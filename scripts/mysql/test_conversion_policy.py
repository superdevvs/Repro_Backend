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

    def test_real_mysql_forward_and_reverse(self):
        credentials_path = os.environ.get("REPRO_CONVERSION_TEST_CREDENTIALS")
        uuid = os.environ.get("REPRO_CONVERSION_TEST_UUID")
        if not credentials_path or not uuid:
            self.skipTest("requires the socket-only disposable conversion database")
        private_config = json.loads(pathlib.Path(credentials_path).read_text())
        self.assertEqual("repro_test", private_config["user"])
        self.assertEqual("/var/run/mysqld/mysqld.sock", private_config["unix_socket"])
        folder = pathlib.Path(__file__).parent.resolve()
        with tempfile.TemporaryDirectory() as path:
            root = pathlib.Path(path)
            source = root / "source.sqlite"
            with sqlite3.connect(source) as db:
                db.executescript("""
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

            with conversion.pymysql.connect(**json.loads(pathlib.Path(credentials_path).read_text()),
                                            database="repro_conversion_test", autocommit=True) as mysql:
                with mysql.cursor() as cursor:
                    with self.assertRaises(conversion.pymysql.err.IntegrityError):
                        cursor.execute("INSERT INTO records(owner_id,day,recorded) VALUES(99,'2026-11-22','2026-11-22')")
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


if __name__ == "__main__":
    unittest.main()
