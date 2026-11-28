"""Exercise the date-predicate report using closed synthetic SQLite files."""

from contextlib import closing
from datetime import date
import json
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import unittest

import report_week_boundaries as census


AS_OF = "2026-11-22T00:00:00Z"


class ReportWeekBoundariesTests(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.root = Path(directory.name).resolve()
        self.snapshot = self.root / "copy with a #.sqlite"

    def make_snapshot(self, values=()):
        with closing(sqlite3.connect(self.snapshot)) as db:
            db.execute("CREATE TABLE shoots (id INTEGER PRIMARY KEY, scheduled_date DATE, private_amount REAL)")
            db.executemany("INSERT INTO shoots VALUES (?, ?, 735959.77)", enumerate(values, start=556712300))
            db.execute("INSERT INTO shoots VALUES (999999999, CAST(X'FF' AS TEXT), 735959.77)")
            db.commit()
        self.snapshot.chmod(0o600)
        return census.schema_drift.file_hash(self.snapshot)

    def test_periods_use_eight_complete_sunday_through_saturday_weeks(self):
        weeks = census.completed_weeks(AS_OF)
        self.assertEqual(8, len(weeks))
        self.assertEqual((date(2026, 9, 27), date(2026, 10, 3)), weeks[0])
        self.assertEqual((date(2026, 11, 15), date(2026, 11, 21)), weeks[-1])
        self.assertEqual((date(2026, 11, 8), date(2026, 11, 14)),
                         census.completed_weeks("2026-11-21T23:59:59Z")[-1])

    def test_legacy_predicates_are_checked_against_valid_calendar_days(self):
        sha = self.make_snapshot([
            "2026-11-14 23:59:59", "2026-11-15", "2026-11-15 00:00:00", "2026-11-16", "2026-11-19 16:00:00.4",
            "2026-11-21", "2026-11-21 12:00:00", "2026-11-21 23:59:59", "2026-11-21 23:59:59.999999", "2026-11-22",
            "2026-11-17 25:00:00", "2026-11-31", "2026-11-18T12:00:00Z", "SECRET_BAD_DATE_VALUE",
            None, 42, sqlite3.Binary(b"2026-11-18"),
        ])
        result = census.report(self.snapshot, sha, AS_OF)

        self.assertEqual("observed", result["result"])
        self.assertEqual(sha, result["snapshot_sha256"])
        self.assertEqual(AS_OF, result["as_of_utc"])
        self.assertFalse(result["finance_parity_verified"])
        self.assertFalse(result["database_writes"])
        self.assertEqual({"date_only": 4, "datetime_seconds": 4, "datetime_fractional": 2, "malformed_text": 2,
                          "unsupported_text": 3, "null": 1, "non_text": 2}, result["representations"])
        self.assertEqual({"start_day_utc": "2026-11-15", "end_day_utc": "2026-11-21", "valid_day_rows": 8,
                          "legacy_predicates": {
                              "datetime_between": {"selected_rows": 8, "valid_day_rows_omitted": 2,
                                                   "outside_week_rows_selected": 0, "unclassifiable_rows_selected": 2},
                              "date_between": {"selected_rows": 7, "valid_day_rows_omitted": 3,
                                               "outside_week_rows_selected": 0, "unclassifiable_rows_selected": 2}}},
                         result["weeks"][-1])
        previous = result["weeks"][-2]
        self.assertEqual(1, previous["valid_day_rows"])
        self.assertEqual(1, previous["legacy_predicates"]["datetime_between"]["selected_rows"])
        self.assertEqual(1, previous["legacy_predicates"]["date_between"]["valid_day_rows_omitted"])
        self.assertTrue(all(week["valid_day_rows"] == 0 for week in result["weeks"][:-2]))

        payload = json.dumps(result)
        for forbidden in ("556712300", "999999999", "735959.77", "SECRET_BAD_DATE_VALUE", "2026-11-18T12:00:00Z"):
            self.assertNotIn(forbidden, payload)
        self.assertEqual(sha, census.schema_drift.file_hash(self.snapshot))
        self.assertEqual([self.snapshot], list(self.root.iterdir()))

    def test_refuses_unspecified_or_invalid_utc_anchor(self):
        for anchor in ("2026-11-22", "2026-11-22T00:00:00+00:00", "2026-02-30T00:00:00Z", "0001-01-01T00:00:00Z"):
            with self.subTest(anchor=anchor), self.assertRaises(census.schema_drift.Refusal):
                census.completed_weeks(anchor)

    def test_refuses_unpinned_noncanonical_or_unclosed_snapshot(self):
        sha = self.make_snapshot()
        with self.assertRaisesRegex(census.schema_drift.Refusal, "input-checksum-mismatch"):
            census.report(self.snapshot, "0" * 64, AS_OF)
        with self.assertRaisesRegex(census.schema_drift.Refusal, "private-regular-canonical-file-required"):
            census.report(Path(self.snapshot.name), sha, AS_OF)
        sidecar = Path(str(self.snapshot) + "-wal")
        sidecar.touch()
        with self.assertRaisesRegex(census.schema_drift.Refusal, "standalone-snapshot-has-sidecar"):
            census.report(self.snapshot, sha, AS_OF)

    def test_cli_uses_a_fixed_diagnostic_for_an_unreadable_database(self):
        self.snapshot.write_bytes(b"SECRET_DO_NOT_PRINT")
        self.snapshot.chmod(0o600)
        result = subprocess.run([sys.executable, "-B", census.__file__, "--snapshot", str(self.snapshot),
                                 "--snapshot-sha256", census.schema_drift.file_hash(self.snapshot),
                                 "--as-of-utc", AS_OF], check=False, text=True, capture_output=True)
        self.assertEqual(1, result.returncode)
        self.assertEqual("snapshot-read-failure", json.loads(result.stdout)["reason"])
        self.assertNotIn(str(self.snapshot), result.stdout + result.stderr)
        self.assertNotIn("SECRET_DO_NOT_PRINT", result.stdout + result.stderr)

    def test_refuses_a_view_or_generated_date_as_source(self):
        for index, (sql, reason) in enumerate([
            ("CREATE VIEW shoots AS SELECT '2026-11-30' AS scheduled_date", "ordinary-shoots-table-required"),
            ("CREATE TABLE shoots (secret TEXT, scheduled_date DATE AS (secret))", "stored-scheduled-date-required"),
        ]):
            fixture = self.root / f"synthetic-{index}.sqlite"
            with closing(sqlite3.connect(fixture)) as db:
                db.execute(sql)
            fixture.chmod(0o600)
            with self.subTest(reason=reason), self.assertRaisesRegex(census.schema_drift.Refusal, reason):
                census.report(fixture, census.schema_drift.file_hash(fixture), AS_OF)


if __name__ == "__main__":
    unittest.main()
