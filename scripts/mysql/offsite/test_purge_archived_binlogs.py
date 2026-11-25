"""Archive-aware binary log purge: selection rules and the guarded run path."""
from __future__ import annotations

import json
import unittest

import publish_backup as backup
import purge_archived_binlogs as purge

NOW = 1_800_000_000
DAY = 86400


def catalog(first: int, last: int) -> list[tuple[str, int]]:
    return [(f"binlog.{number:06d}", 4096) for number in range(first, last + 1)]


def ages(first: int, last: int, days_old: dict[int, float]) -> dict[str, int]:
    return {f"binlog.{n:06d}": NOW - int(days_old.get(n, 0) * DAY) for n in range(first, last + 1)}


class SelectionTests(unittest.TestCase):
    def select(self, *, entries, active, next_file, mtimes, min_age=3 * DAY, keep=2):
        return purge.select_target(entries, active=active, checkpoint_next=next_file, mtimes=mtimes,
                                   now=NOW, min_age_seconds=min_age, keep_files=keep)

    def test_purges_old_archived_files_up_to_the_first_kept_file(self):
        entries = catalog(1, 10)
        mtimes = ages(1, 10, {1: 9, 2: 8, 3: 7, 4: 6, 5: 5, 6: 1})
        self.assertEqual(self.select(entries=entries, active="binlog.000010", next_file="binlog.000008",
                                     mtimes=mtimes), "binlog.000006")

    def test_never_purges_a_file_that_is_not_yet_archived(self):
        entries = catalog(1, 10)
        mtimes = ages(1, 10, {n: 30 for n in range(1, 10)})
        self.assertEqual(self.select(entries=entries, active="binlog.000010", next_file="binlog.000003",
                                     mtimes=mtimes), "binlog.000003")
        self.assertIsNone(self.select(entries=entries, active="binlog.000010", next_file="binlog.000001",
                                      mtimes=mtimes))

    def test_keeps_young_files_and_the_minimum_count(self):
        entries = catalog(1, 6)
        self.assertIsNone(self.select(entries=entries, active="binlog.000006", next_file="binlog.000006",
                                      mtimes=ages(1, 6, {})))
        old = ages(1, 6, {n: 30 for n in range(1, 6)})
        self.assertEqual(self.select(entries=entries, active="binlog.000006", next_file="binlog.000006",
                                     mtimes=old, keep=2), "binlog.000004")
        self.assertEqual(self.select(entries=entries, active="binlog.000006", next_file="binlog.000006",
                                     mtimes=old, keep=5), None)

    def test_the_active_file_is_never_a_candidate(self):
        entries = catalog(1, 3)
        mtimes = ages(1, 3, {1: 30, 2: 30, 3: 30})
        self.assertEqual(self.select(entries=entries, active="binlog.000003", next_file="binlog.000004",
                                     mtimes=mtimes, keep=0), "binlog.000003")

    def test_a_missing_age_stops_the_purge_at_that_file(self):
        entries = catalog(1, 6)
        mtimes = ages(1, 6, {n: 30 for n in range(1, 6)})
        del mtimes["binlog.000002"]
        self.assertEqual(self.select(entries=entries, active="binlog.000006", next_file="binlog.000006",
                                     mtimes=mtimes, keep=0), "binlog.000002")

    def test_foreign_or_gapped_names_are_refused(self):
        with self.assertRaises(backup.Refusal):
            self.select(entries=[("binlog.000001", 9), ("binlog.000003", 9)], active="binlog.000003",
                        next_file="binlog.000003", mtimes={})
        with self.assertRaises(backup.Refusal):
            self.select(entries=catalog(1, 3), active="binlog.000003", next_file="other.000002", mtimes={})


class RunTests(unittest.TestCase):
    def setUp(self):
        self.statements = []
        self.entries = catalog(1, 8)
        self.mtimes = ages(1, 8, {n: 10 for n in range(1, 8)})

    def execute(self, statement):
        self.statements.append(statement)
        target = statement.split("'")[1]
        self.entries = [item for item in self.entries if item[0] >= target]

    def run_purge(self, next_file="binlog.000006"):
        return purge.run(listing=lambda: ("binlog.000008", list(self.entries)),
                         checkpoint_next=lambda: next_file, mtime=self.mtimes.get, execute=self.execute,
                         now=NOW, min_age_seconds=3 * DAY, keep_files=2)

    def test_run_issues_one_purge_and_verifies_the_new_first_file(self):
        result = self.run_purge()
        self.assertEqual(self.statements, ["PURGE BINARY LOGS TO 'binlog.000006'"])
        self.assertEqual(result, {"result": "archived-binlogs-purged", "purged_to": "binlog.000006",
                                  "purged_files": 5, "checkpoint_next_file": "binlog.000006",
                                  "remote_accessed": False})
        self.assertEqual(json.loads(json.dumps(result)), result)

    def test_nothing_to_purge_sends_no_statement(self):
        result = self.run_purge(next_file="binlog.000001")
        self.assertEqual(self.statements, [])
        self.assertEqual(result["result"], "archived-binlogs-nothing-to-purge")

    def test_a_purge_that_did_not_take_effect_is_a_failure(self):
        self.execute = lambda statement: self.statements.append(statement)
        with self.assertRaises(backup.Refusal):
            self.run_purge()


if __name__ == "__main__":
    unittest.main()
