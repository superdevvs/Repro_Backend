import io
import unittest
from unittest.mock import Mock

import heartbeat
import publish_backup as backup


class HeartbeatProtocolTests(unittest.TestCase):
    def setUp(self):
        self.source = {"server_version": "8.4.13", "identity": "11111111-2222-3333-4444-555555555555",
                       "server_id": 64084, "binlog_base": "/fixture/mysql/binlog", "database": "fixture"}
        self.socket = Mock()
        self.recorded = "2026-11-24T08:04:10Z"
        self.marker = "2026-11-24T08:04:00Z"
        self.row = f"heartbeat-row\t{self.marker}\t{self.recorded}"

    def connection(self, *, reader=False, connection_id="17", log_bin=None):
        binary = "0" if reader else "1"
        return "\t".join(["heartbeat-source", connection_id, (heartbeat.READER if reader else heartbeat.WRITER) + "@localhost",
                          "NONE", self.source["server_version"], self.source["identity"], str(self.source["server_id"]),
                          "1", log_bin or binary, binary, "1", "" if reader else self.source["binlog_base"], ""])

    def initial(self, *, reader=False):
        account = heartbeat.READER if reader else heartbeat.WRITER
        permission = "SELECT" if reader else "SELECT, INSERT"
        return [self.connection(reader=reader), "heartbeat-table\tBASE TABLE\tInnoDB",
                "heartbeat-column\tminute_utc\tdatetime\t0\tNO\tPRI\tNULL\t",
                "heartbeat-column\trecorded_utc\tdatetime\t0\tNO\t\tNULL\t",
                f"GRANT USAGE ON *.* TO `{account}`@`localhost`",
                f"GRANT {permission} ON `fixture`.`{heartbeat.TABLE}` TO `{account}`@`localhost`", heartbeat.PRE_END]

    def sampled(self, *, existing=None):
        return [f"heartbeat-sample\t{self.recorded}", *([existing] if existing else []), self.connection(), heartbeat.READ_END]

    def check(self, rows, stdin, *, target=None):
        stdout = io.BytesIO(("\n".join(rows) + "\n").encode())
        return heartbeat.exchange(self.source, target, stdin, stdout, self.socket)

    def test_records_one_server_slot_then_confirms_committed_affected_row(self):
        stdin = io.BytesIO()
        response = self.initial() + self.sampled() + ["heartbeat-effect\t1", self.row, self.connection(), heartbeat.WRITE_END]
        result = self.check(response, stdin)
        self.assertEqual(result, {"result": "heartbeat-recorded", "minute_utc": self.marker,
                                  "recorded_utc": self.recorded, "record_requested": True})
        self.assertEqual(stdin.getvalue().count(b"INSERT INTO"), 1)
        self.assertIn(b"UTC_TIMESTAMP(0)", stdin.getvalue())

    def test_existing_exact_slot_is_verified_without_emitting_insert(self):
        stdin = io.BytesIO()
        response = self.initial() + self.sampled(existing=self.row)
        result = self.check(response, stdin)
        self.assertEqual(result["result"], "heartbeat-already-recorded")
        self.assertIs(result["record_requested"], False)
        self.assertNotIn(b"INSERT INTO", stdin.getvalue())

    def test_missing_write_reply_zero_effect_or_changed_session_is_unverified(self):
        for suffix in ([], ["heartbeat-effect\t0", self.connection(), heartbeat.WRITE_END],
                       ["heartbeat-effect\t1", self.row, self.connection(connection_id="18"), heartbeat.WRITE_END]):
            with self.subTest(suffix=suffix):
                stdin = io.BytesIO()
                with self.assertRaises(heartbeat.HeartbeatUnverified) as error:
                    self.check(self.initial() + self.sampled() + suffix, stdin)
                self.assertEqual(error.exception.marker, self.marker)
                self.assertEqual(stdin.getvalue().count(b"INSERT INTO"), 1)

    def test_refuses_wrong_table_source_or_grants_before_any_insert(self):
        base = self.initial()
        for position, value in ((0, self.connection(log_bin="0")), (1, "heartbeat-table\tVIEW\tNULL"),
                               (5, base[5].replace("INSERT", "INSERT, UPDATE"))):
            with self.subTest(position=position):
                stdin = io.BytesIO()
                response = base[:position] + [value] + base[position + 1:]
                with self.assertRaises(backup.Refusal):
                    self.check(response, stdin)
                self.assertNotIn(b"INSERT INTO", stdin.getvalue())

    def test_read_only_recovery_uses_freshest_preceding_mark_with_60_second_inclusive_bound(self):
        for target, age in ((self.recorded, 0), ("2026-11-24T08:05:10Z", 60)):
            with self.subTest(age=age):
                stdin = io.BytesIO()
                result = self.check(self.initial(reader=True) + [self.row, self.connection(reader=True), heartbeat.READ_END],
                                    stdin, target=heartbeat.utc(target))
                self.assertEqual(result["recovery_gap_seconds"], age)
                self.assertNotIn(b"INSERT INTO", stdin.getvalue())
                self.assertIn(b"ORDER BY minute_utc DESC LIMIT 1", stdin.getvalue())
        for moment in ("2026-11-24T08:04:09Z", "2026-11-24T08:05:11Z"):
            with self.subTest(moment=moment), self.assertRaises(backup.Refusal):
                self.check(self.initial(reader=True) + [self.row, self.connection(reader=True), heartbeat.READ_END],
                           io.BytesIO(), target=heartbeat.utc(moment))
        with self.assertRaisesRegex(backup.Refusal, "no recovered marker"):
            self.check(self.initial(reader=True) + [self.connection(reader=True), heartbeat.READ_END],
                       io.BytesIO(), target=heartbeat.utc(self.recorded))

    def test_exact_utc_timestamp_and_path_plan_scope(self):
        with self.assertRaises(backup.Refusal):
            heartbeat.utc("2026-11-24T08:04:10+01:00")
        plan = {"version": 1, "collector_plan": "/source/collector.json", "collector_plan_sha256": "a" * 64,
                "defaults_file": "/private/heartbeat.cnf", "runtime_dir": "/private/runtime", "timeout_seconds": 10}
        raw = backup.encode(plan)
        self.assertEqual(heartbeat.parse_plan(raw, backup.sha256(raw)), plan)
        with self.assertRaises(backup.Refusal):
            heartbeat.parse_plan(raw, "b" * 64)


if __name__ == "__main__":
    unittest.main()
