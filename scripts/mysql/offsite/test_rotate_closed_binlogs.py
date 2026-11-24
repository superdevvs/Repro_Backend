"""Exercise the fixed streamed rotation protocol without MySQL or subprocesses."""
import io
import unittest
from unittest.mock import Mock

import publish_backup as backup
import rotate_closed_binlogs as rotation


class RotationProtocolTest(unittest.TestCase):
    def setUp(self):
        self.plan = {"version": 1, "collector_plan": "/fixture/collector.json", "collector_plan_sha256": "a" * 64,
                     "defaults_file": "/fixture/rotator.cnf", "runtime_dir": "/fixture/run", "timeout_seconds": 12,
                     "routine_created_epoch": 1_795_381_100, "routine_altered_epoch": 1_795_381_100}
        self.source = {"server_version": "8.4.13", "identity": "11111111-2222-3333-4444-555555555555",
                       "server_id": 64084, "binlog_base": "/fixture/mysql/binlog"}
        self.stat = Mock()

    def identity(self, connection="17", *, autopurge="0", user=rotation.CALLER + "@localhost",
                 mandatory="", uuid=None):
        return "\t".join(["repro-rotation-source", user, "NONE", connection, self.source["server_version"],
                          uuid or self.source["identity"], str(self.source["server_id"]), "1", "1", "CRC32", "0",
                          self.source["binlog_base"], autopurge, mandatory])

    def prefix(self, *, identity=None, grants=None, epoch=None):
        return [identity or self.identity(),
                "\t".join(["repro-rotation-routine", rotation.DEFINER + "@localhost", "DEFINER", "PROCEDURE",
                           str(epoch or self.plan["routine_created_epoch"]), str(self.plan["routine_altered_epoch"])]),
                *(grants if grants is not None else sorted(rotation.REQUIRED_GRANTS)),
                "binlog.000017\t131\t\t\t", rotation.PRE_END]

    def suffix(self, number="000018", *, connection="17"):
        return [self.identity(connection), f"binlog.{number}\t131\t\t\t", rotation.POST_END]

    def execute(self, rows, request):
        response = io.BytesIO(("\n".join(rows) + "\n").encode())
        return rotation.exchange(self.plan, self.source, request, response, self.stat)

    def test_only_one_fixed_call_after_verifying_the_session_and_procedure(self):
        stream = Mock(wraps=io.BytesIO())
        self.assertEqual(self.execute(self.prefix() + self.suffix(), stream), ("binlog.000017", "binlog.000018"))
        self.assertEqual([call.args[0] for call in stream.write.call_args_list],
                         [(rotation.PRECHECK + "\n").encode(), (rotation.COMMAND + "\n").encode()])
        self.assertIn(b"CALL repro_archive_control.rotate_binary_log();", stream.write.call_args_list[1].args[0])
        self.assertNotIn(b"FLUSH", stream.write.call_args_list[1].args[0])
        self.assertEqual(self.stat.call_count, 2)

    def test_refuses_wrong_source_credential_purge_or_routine_before_any_call(self):
        for rows in (
            self.prefix(identity=self.identity(autopurge="1")),
            self.prefix(identity=self.identity(mandatory="`broad`@`%`")),
            self.prefix(identity=self.identity(user="archive_writer@localhost")),
            self.prefix(identity=self.identity(uuid="21111111-2222-3333-4444-555555555555")),
            self.prefix(grants=sorted(rotation.REQUIRED_GRANTS) + [f"GRANT RELOAD ON *.* TO {rotation.TARGET}"]),
            self.prefix(epoch=self.plan["routine_created_epoch"] + 1),
        ):
            with self.subTest(rows=rows):
                stream = io.BytesIO()
                with self.assertRaises(backup.Refusal) as refused:
                    self.execute(rows, stream)
                self.assertNotIsInstance(refused.exception, rotation.RotationUnverified)
                self.assertNotIn(b"CALL", stream.getvalue())

    def test_reports_unknown_after_a_lost_reply_reconnect_or_nonexact_advance_without_retry(self):
        for suffix in ([], self.suffix("000017"), self.suffix("000019"), self.suffix(connection="18")):
            with self.subTest(suffix=suffix):
                stream = io.BytesIO()
                with self.assertRaises(rotation.RotationUnverified) as exception:
                    self.execute(self.prefix() + suffix, stream)
                self.assertEqual(exception.exception.before, "binlog.000017")
                self.assertEqual(stream.getvalue().count(b"CALL "), 1)

    def test_refuses_socket_replacement_before_sending_the_call(self):
        self.stat.side_effect = backup.Refusal("socket changed")
        stream = io.BytesIO()
        with self.assertRaisesRegex(backup.Refusal, "socket changed"):
            self.execute(self.prefix() + self.suffix(), stream)
        self.assertNotIn(b"CALL", stream.getvalue())

    def test_uses_an_exact_pinned_plan_and_disallows_unbounded_time_or_changed_metadata(self):
        raw = backup.encode(self.plan)
        self.assertEqual(rotation.parse_plan(raw, backup.sha256(raw)), self.plan)
        with self.assertRaises(backup.Refusal):
            rotation.parse_plan(raw, "f" * 64)
        for change in ({"timeout_seconds": 61}, {"routine_altered_epoch": 0},
                       {"defaults_file": "/fixture/../unexpected"}):
            with self.subTest(change=change):
                raw = backup.encode(self.plan | change)
                with self.assertRaises(backup.Refusal):
                    rotation.parse_plan(raw, backup.sha256(raw))


if __name__ == "__main__":
    unittest.main()
