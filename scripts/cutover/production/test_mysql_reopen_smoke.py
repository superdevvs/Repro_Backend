"""A disposable protected directory and the actual smoke/probe JSON contracts."""

import copy
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock

from adapter import guard
import binlog_scan
import live_config
import mysql_probe
import mysql_reopen_smoke as smoke
from runner import UnsafeCutover

UUID = "ab12cd34-0000-4000-8000-123456789012"
SHA = "f" * 64
START = 1795824000
RUN = "reopen-fixture"
ROUTE_VALUES = {"database.default": "mysql"}


def gtid(end):
    return f"{UUID}:1-{end}"


@unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0, "Uses protected Linux records")
class WindowTests(unittest.TestCase):
    def setUp(self):
        private = tempfile.TemporaryDirectory(dir="/var/lib")
        self.addCleanup(private.cleanup)
        self.root = Path(private.name)
        self.paths = {key: self.root / key for key in ("wrappers", "wrappers_mysql", "smoke_window", "policy")}
        self.config = {
            "paths": self.paths, "mysql": {"target_uuid": UUID, "database": "sample", "smoke_tables": sorted(smoke.TABLES)},
            "smoke": {"client_id": 1, "shoot_id": 9}, "web_user": "www-data", "app_root": str(self.root),
            "commands": {"smoke_write": ["/test/admit", "cutover-smoke"]},
        }
        pinned = live_config.route_sha256(ROUTE_VALUES)
        self.expected = {"engine": "mysql", "server_uuid": UUID, "database": "sample", "route_sha256": pinned}
        policy = {"version": 1, "identity": {"run_id": RUN}, "mysql_live_config_sha256": pinned}
        self.paths["policy"].write_text(json.dumps(policy))
        profile = {"engine": "mysql", "action": "queue-worker", "user": "www-data", "group": "www-data",
                   "cwd": str(self.root), "umask": "0022", "env": {}, "timeout_seconds": 300,
                   "argv": ["/usr/bin/php", str(self.root / "artisan"), "cutover:smoke", "--write", "--json"]}
        for key in ("wrappers", "wrappers_mysql"):
            self.paths[key].write_text(json.dumps({"version": 1, "profiles": {"cutover-smoke": profile}}))
        self.report = {
            "version": 1, "mode": "write", "ok": True, "problems": [], "connection": "mysql",
            "engine": "mysql", "database": "sample", "server_uuid": UUID, "server_version": "8.4.13",
            "config_cached": True, "config_path": str(self.root / "bootstrap/cache/config.php"), "route_values": ROUTE_VALUES,
            "process": {"user": "www-data", "group": "www-data", "euid": 33, "egid": 33,
                        "cwd": str(self.root), "umask": "0022"},
            "checks": {"client_id": 1, "shoot_id": 9, "client_read": True, "shoot_read": True,
                       "encrypted_value_decrypts": True, "smoke_queue": "database:cutover-smoke",
                       "smoke_queue_pending": 0, "smoke_queue_paused": False, "read_back": True,
                       "value": "cutover smoke 0123456789abcdef 2026-11-28T00:00:00Z",
                       "job": {"queued": True, "ran": True, "failed": False, "row_left": False},
                       "smoke_tables": sorted(smoke.TABLES)},
        }
        self.adapter = SimpleNamespace(config=self.config, paths=self.paths, pinned=SimpleNamespace(run_id=RUN),
                                       clock=lambda: START, _execute=Mock(side_effect=self.execute))

    def execute(self, argv, deadline):
        self.assertEqual(self.config["commands"]["smoke_write"], argv)
        self.assertEqual(START + 60, deadline)
        attempt = self.record()["attempts"][-1]
        self.assertIsNone(attempt["report"])
        self.assertIsNone(attempt["gtid_after"])
        return 0, json.dumps(self.report)

    def record(self):
        return smoke.read(self.paths["smoke_window"])

    def attempt(self, *points):
        return smoke.run(self.adapter, self.expected, SHA, START + 60, Mock(side_effect=points))

    def audited(self):
        return mysql_probe.load_window(self.config, RUN, SimpleNamespace(sha256=SHA))

    def test_verified_smoke_is_reused_after_reboot_and_recognized_by_the_independent_audit(self):
        original = self.attempt(gtid(10), gtid(14))
        self.assertFalse(original["reused"])
        self.assertEqual(0o600, self.paths["smoke_window"].stat().st_mode & 0o777)
        self.assertEqual(self.report, self.audited()[2]["report"])
        again = self.attempt(gtid(25))
        self.assertTrue(again["reused"])
        self.assertEqual(original["report"], again["report"])
        self.assertEqual((1, 1), (len(self.record()["attempts"]), self.record()["reuses"]))
        self.assertEqual(1, self.adapter._execute.call_count)
        with self.assertRaisesRegex(UnsafeCutover, "last write-smoke GTIDs"):
            self.attempt(gtid(11))

    def test_missing_end_persists_and_a_retry_cannot_extend_an_old_window(self):
        with self.assertRaisesRegex(OSError, "server down"):
            self.attempt(gtid(10), OSError("server down"))
        self.assertIsNone(self.audited())
        last = self.attempt(gtid(33), gtid(37))
        self.assertEqual(gtid(33), last["gtid_before"])
        history = self.record()["attempts"]
        self.assertEqual([(gtid(10), None), (gtid(33), gtid(37))],
                         [(item["gtid_before"], item["gtid_after"]) for item in history])
        self.assertIsNone(history[0]["report"])
        self.assertIn(f"{UUID}:32", self.audited()[0])  # Outside the only window credited by after minus before.

    def test_failed_smoke_retains_its_end_and_never_receives_credit(self):
        self.adapter._execute.side_effect = lambda *args: (1, json.dumps(self.report))
        with self.assertRaisesRegex(UnsafeCutover, "exited 1"):
            self.attempt(gtid(3), gtid(7))
        self.assertEqual(gtid(7), self.record()["attempts"][0]["gtid_after"])
        self.assertIsNone(self.audited())
        self.adapter._execute.side_effect = self.execute
        self.attempt(gtid(9), gtid(13))
        self.assertEqual(gtid(9), self.audited()[2]["gtid_before"])

    def test_checks_for_wrong_target_fixtures_job_identity_cache_and_marker(self):
        base = copy.deepcopy(self.report)
        for location, key, value in [
            ("", "ok", False), ("", "version", True), ("", "mode", "read-only"), ("", "server_uuid", "wrong"),
            ("", "route_values", {"database.default": "sqlite"}), ("", "config_cached", False),
            ("", "config_path", "/other/config.php"), ("", "connection", "sqlite"),
            ("checks", "client_id", True), ("checks", "shoot_id", 2), ("checks", "read_back", False),
            ("checks", "smoke_queue_paused", True), ("checks", "encrypted_value_decrypts", False),
            ("checks", "smoke_queue_pending", False), ("checks", "smoke_tables", ["jobs"]),
            ("checks", "value", "yesterday"), ("checks", "value", "cutover smoke 0123456789abcdef 2020-01-01T00:00:00Z"),
            ("process", "euid", 0), ("process", "group", "root"), ("process", "umask", "0000"),
            ("job", "ran", 1), ("job", "row_left", True), ("job", "failed", True),
        ]:
            with self.subTest(location=location, key=key):
                self.report = copy.deepcopy(base)
                target = self.report["checks"]["job"] if location == "job" else self.report.get(location, self.report)
                target[key] = value
                with self.assertRaises(UnsafeCutover):
                    self.attempt(gtid(15), gtid(19))
                self.assertIsNone(self.audited())
                self.paths["smoke_window"].unlink()

    def test_receipt_tamper_wrong_policy_or_edited_report_cannot_be_reused_or_audited(self):
        self.attempt(gtid(10), gtid(14))
        saved = self.record()
        for change in (lambda r: r["attempts"][0]["report"]["checks"]["job"].update(ran=False),
                       lambda r: r["binding"]["target"].update(route_sha256="0" * 64),
                       lambda r: r["binding"]["fixtures"].update(shoot_id=99),
                       lambda r: r["attempts"].append(r["attempts"][0].copy()),
                       lambda r: r["attempts"][0].update(gtid_before=gtid(15))):
            with self.subTest(change=change):
                modified = copy.deepcopy(saved)
                change(modified)
                self.paths["smoke_window"].write_text(json.dumps(modified))
                with self.assertRaises(UnsafeCutover):
                    self.attempt(gtid(25))
                with self.assertRaises(guard.Refused):
                    self.audited()
        legacy = {"version": 1, "run_id": RUN, "receipt_sha256": SHA, "report": {"ok": True},
                  "gtid_before": gtid(1), "gtid_after": gtid(100)}
        self.paths["smoke_window"].write_text(json.dumps(legacy))
        self.assertIsNone(self.audited())
        with self.assertRaises(UnsafeCutover):
            self.attempt(gtid(104))
        self.assertEqual(legacy, self.record())


class AuditAttributionTests(unittest.TestCase):
    def test_consumer_and_writer_share_the_authenticated_window_version(self):
        self.assertEqual(2, mysql_probe.AUTHENTICATED_SMOKE_WINDOW_VERSION)
        self.assertEqual(mysql_probe.AUTHENTICATED_SMOKE_WINDOW_VERSION, smoke.VERSION)

    def test_only_exact_smoke_transactions_can_be_discounted(self):
        edit = {"users", "shoots", "aryeo_changes"}
        jobs = {"jobs"}

        def classify(tables, target=UUID):
            transactions = [binlog_scan.Transaction(f"{UUID}:{sequence}", "binlog.000001", sequence,
                                                    tables=names, business=True)
                            for sequence, names in enumerate(tables, 11)]
            result = binlog_scan.Result(0, None, transactions)
            window = (binlog_scan.GtidSet.parse(gtid(10)), binlog_scan.GtidSet.parse(gtid(20)))
            return [item["class"] for item in binlog_scan.audit(result, smoke.TABLES, window, server_uuid=target)]
        self.assertEqual(["smoke"] * 4, classify([edit, jobs, jobs, jobs]))
        self.assertEqual(["business"] * 4, classify([edit, jobs, jobs, jobs], target="another-server"))
        for tables in ([edit, jobs], [edit, jobs, jobs, jobs, jobs], [edit, jobs, {"users"}, jobs],
                       [edit, jobs, jobs, jobs, {"shoots"}], [jobs, edit, jobs, jobs]):
            with self.subTest(tables=tables):
                self.assertEqual(["business"] * len(tables), classify(tables))
