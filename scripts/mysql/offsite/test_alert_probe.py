"""Induce each server-side alert condition with fixtures and check the provider fail ping."""
from __future__ import annotations

import datetime as dt
import json
import os
from pathlib import Path
import sqlite3
from contextlib import closing
import sys
import tempfile
import time
import unittest

import alert_bridge as bridge
import alert_probe as probe

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO))
from scripts.cutover.readiness import check  # noqa: E402

NOW = 1_800_000_000
GIB = 1024**3


class Pings:
    """Records the URL path a real bridge would request, without any network."""

    def __init__(self):
        self.paths = []

    def __call__(self, url, _timeout, _context):
        self.paths.append(url.split("/ping/", 1)[1])
        return 200, None


class ConditionsFileTests(unittest.TestCase):
    def test_thresholds_file_names_exactly_the_readiness_cases(self):
        conditions = probe.load_conditions()
        self.assertEqual(set(conditions), set(check.ALERT_LIMITS_MS))
        for name, item in conditions.items():
            self.assertEqual(item["delivery_limit_seconds"] * 1000, check.ALERT_LIMITS_MS[name], name)
            self.assertIn(item["class"], ("wake", "daytime"))
        wake = {name for name, item in conditions.items() if item["class"] == "wake"}
        self.assertEqual(wake, {"database_down", "wrong_database_engine", "site_unreachable", "disk_nearly_full",
                                "backup_lag", "server_error_rate", "historical_money_totals_changed",
                                "server_silent"})

    def test_every_server_side_condition_has_a_detector_or_a_named_unit(self):
        for name, item in probe.load_conditions().items():
            if item["source"] in ("probe-host", "probe-backup"):
                self.assertIn(name, probe.DETECTORS)
            else:
                self.assertIn(item["source"], ("watchdog", "heartbeat", "external-website-check"))


class DetectorTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.root = Path(folder.name)
        (self.root / "app" / "storage" / "logs").mkdir(parents=True)
        (self.root / "app" / ".env").write_text("APP_KEY=base64:secret\nDB_CONNECTION=sqlite\n")
        self.database = self.root / "app" / "database.sqlite"
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute("CREATE TABLE jobs (id INTEGER PRIMARY KEY, queue TEXT, available_at INTEGER, "
                       "reserved_at INTEGER, created_at INTEGER)")
            db.execute("CREATE TABLE invoices (id INTEGER PRIMARY KEY, total REAL, issued_on TEXT)")
            db.execute("INSERT INTO invoices (total, issued_on) VALUES (100.5, '2026-10-01'), (20, '2026-10-02')")
        self.inbox = self.root / "inbox"
        self.inbox.mkdir()
        self.cache = self.root / "app" / "storage" / "framework" / "cache" / "data"
        self.cache.mkdir(parents=True)
        self.access = self.root / "access.log"
        self.access.write_text("")
        self.plan = {
            "version": 1, "inbox_dir": str(self.inbox), "memory_file": str(self.root / "memory.json"),
            "conditions": sorted(name for name, item in probe.load_conditions().items()
                                 if item["source"] in ("probe-host", "probe-backup")),
            "app_root": str(self.root / "app"), "expected_engine": "sqlite", "sqlite_database": str(self.database),
            "mysql": {"client": "/usr/bin/mysql", "defaults_file": "/x/monitor.cnf",
                      "socket": "/run/mysqld/mysqld.sock"},
            "disks": [{"path": "/", "require_mount": True}],
            "access_logs": [str(self.access)], "laravel_log": str(self.root / "app" / "storage" / "logs" / "laravel.log"),
            "cache_dir": str(self.cache), "cache_group_writable": True,
            "money_queries": {"invoices": "SELECT COUNT(*), SUM(total) FROM invoices WHERE issued_on < '2026-11-01'"},
            "money_baseline_file": str(self.root / "money-baseline.json"),
            "binlog_checkpoint_file": str(self.root / "checkpoint.json"),
            "restore_receipts": {"directory": str(self.root / "restores"), "pattern": "restore-test-*.json",
                                 "result": "restore-test-passed"},
            "rehearsal_receipts": {"directory": str(self.root / "nightly"), "pattern": "nightly-*.json",
                                   "result": "nightly-data-green"},
        }
        (self.root / "checkpoint.json").write_text("{}")
        os.utime(self.root / "checkpoint.json", (NOW - 60, NOW - 60))
        for kind, result in (("restores", "restore-test-passed"), ("nightly", "nightly-data-green")):
            (self.root / kind).mkdir()
            name = "restore-test-1.json" if kind == "restores" else "nightly-1.json"
            (self.root / kind / name).write_text(json.dumps({"result": result, "verified_unix_s": NOW - 3600}))
        self.mysql = {"uptime": 86400, "deadlocks": 10, "timeouts": 3, "down": False, "metrics": "enabled"}
        self.disk = {"/": (200 * GIB, 1000 * GIB, True)}
        self.urls = {"version": 1, "checks": {name: "https://hc.example.test/ping/" + name
                                              for name in probe.load_conditions()}}

    def mysql_query(self, sql):
        if self.mysql["down"]:
            raise probe.DetectorError("MySQL query failed")
        if "Uptime" in sql:
            return [["Uptime", str(self.mysql["uptime"])]]
        if "INNODB_METRICS" in sql:
            return [["lock_deadlocks", str(self.mysql["deadlocks"]), self.mysql["metrics"]],
                    ["lock_timeouts", str(self.mysql["timeouts"]), self.mysql["metrics"]]]
        return [["1"]]

    def context(self, now=NOW):
        return probe.Context(plan=self.plan, now=now, mysql_query=self.mysql_query,
                             disk_stats=lambda path: self.disk[path])

    def baseline(self):
        digest = probe.money_digest(self.context())
        Path(self.plan["money_baseline_file"]).write_text(json.dumps({"version": 1, "result_sha256": digest}))

    def run_probe(self, now=NOW):
        records = probe.probe(self.context(now))
        probe.write_statuses(self.inbox, records)
        return {record["condition"]: record for record in records}

    def fail_pings(self, now=NOW):
        pings = Pings()
        config = {"version": 1, "urls_file": str(self.root / "unused"), "inbox_dir": str(self.inbox), "fail_suffix": "/fail",
                  "timeout_seconds": 5, "max_status_age_seconds": 180, "forward": self.plan["conditions"]}
        original = bridge.deliver
        bridge.deliver = pings
        try:
            bridge.forward(bridge.parse_config(json.dumps(config).encode()),
                           bridge.parse_urls(json.dumps(self.urls).encode()), now=now)
        finally:
            bridge.deliver = original
        return {path[: -len("/fail")] for path in pings.paths if path.endswith("/fail")}

    def assert_only_failure(self, condition, now=NOW):
        statuses = self.run_probe(now)
        self.assertEqual(statuses[condition]["state"], "fail", statuses[condition])
        self.assertEqual(self.fail_pings(now), {condition})

    def write_access(self, total, errors, *, minutes_ago=5):
        moment = dt.datetime.fromtimestamp(NOW - minutes_ago * 60, dt.timezone.utc)
        stamp = moment.strftime("%d/%b/%Y:%H:%M:%S +0000")
        lines = [f'203.0.113.9 - - [{stamp}] "GET /api/x HTTP/1.1" {500 if i < errors else 200} 12 "-" "ua"'
                 for i in range(total)]
        self.access.write_text("\n".join(lines) + "\n")

    def test_healthy_fixture_sends_no_fail_ping(self):
        self.baseline()
        self.write_access(100, 1)
        statuses = self.run_probe()
        self.assertEqual({name for name, item in statuses.items() if item["state"] != "ok"}, set(), statuses)
        self.assertEqual(self.fail_pings(), set())

    def induce(self, condition, change):
        self.baseline()
        self.write_access(100, 1)
        change()
        self.assert_only_failure(condition)

    def test_database_down(self):
        self.baseline()
        self.write_access(100, 1)
        self.database.write_bytes(b"not a database at all" * 64)
        statuses = self.run_probe()
        self.assertEqual(statuses["database_down"]["state"], "fail")
        self.mysql["down"] = True
        self.plan["expected_engine"] = "mysql"
        (self.root / "app" / ".env").write_text("DB_CONNECTION=mysql\n")
        self.assertEqual(self.run_probe()["database_down"]["state"], "fail")

    def test_wrong_database_engine(self):
        self.induce("wrong_database_engine",
                    lambda: (self.root / "app" / ".env").write_text("APP_KEY=x\nDB_CONNECTION=mysql\n"))

    def test_disk_nearly_full_by_percent_bytes_or_missing_mount(self):
        self.induce("disk_nearly_full", lambda: self.disk.update({"/": (19 * GIB, 1000 * GIB, True)}))
        self.disk["/"] = (300 * GIB, 1000 * GIB, False)
        self.assertEqual(self.run_probe()["disk_nearly_full"]["state"], "fail")

    def test_server_error_rate_over_two_percent_for_thirty_minutes(self):
        self.induce("server_error_rate", lambda: self.write_access(100, 3))
        self.write_access(100, 30, minutes_ago=40)
        self.assertEqual(self.run_probe()["server_error_rate"]["state"], "ok")

    def test_historical_money_totals_changed(self):
        def change():
            with closing(sqlite3.connect(self.database)) as db, db:
                db.execute("UPDATE invoices SET total = 99 WHERE id = 1")
        self.induce("historical_money_totals_changed", change)

    def test_missing_money_baseline_is_a_failure(self):
        self.write_access(100, 1)
        self.assertEqual(self.run_probe()["historical_money_totals_changed"]["state"], "fail")

    def test_queue_stale_after_ten_minutes(self):
        def change():
            with closing(sqlite3.connect(self.database)) as db, db:
                db.execute("INSERT INTO jobs (queue, available_at, reserved_at, created_at) VALUES "
                           "('default', ?, NULL, ?), ('default', ?, NULL, ?)", (NOW - 601, NOW - 601, NOW + 900, NOW))
        self.induce("queue_stale", change)

    def test_cache_write_failed_from_folder_mode_or_log(self):
        if os.name == "posix":
            folder = self.cache / "ab"
            folder.mkdir()
            folder.chmod(0o755)
            self.induce("cache_write_failed", lambda: None)
            folder.chmod(0o775)
        stamp = dt.datetime.fromtimestamp(NOW - 60, dt.timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        Path(self.plan["laravel_log"]).write_text(
            f"[{stamp}] production.ERROR: file_put_contents(/var/www/backend/storage/framework/cache/data/ab/cd): "
            "Failed to open stream: Permission denied\n")
        self.assertEqual(self.run_probe()["cache_write_failed"]["state"], "fail")

    @unittest.skipUnless(os.name == "posix", "the cache folder mode check runs on Linux")
    def test_cache_folder_that_cannot_be_read_is_a_failure(self):
        # os.walk skips folders it cannot list, so a missing or unreadable cache
        # folder looked like a clean one.
        self.baseline()
        self.write_access(100, 1)
        self.plan["cache_dir"] = str(self.root / "no-such-cache")
        self.assertEqual(self.run_probe()["cache_write_failed"]["state"], "fail")
        self.plan["cache_dir"] = str(self.cache)
        self.cache.chmod(0)
        self.addCleanup(self.cache.chmod, 0o775)
        self.assertEqual(self.run_probe()["cache_write_failed"]["state"], "fail")
        self.cache.chmod(0o775)
        self.assertEqual(self.run_probe()["cache_write_failed"]["state"], "ok")

    def test_mysql_restarted(self):
        self.induce("mysql_restarted", lambda: self.mysql.update(uptime=120))

    def test_database_contention_over_five_an_hour(self):
        self.baseline()
        self.write_access(100, 1)
        self.run_probe(NOW - 3600)
        self.mysql.update(deadlocks=14, timeouts=5)
        self.assert_only_failure("database_contention")

    def test_disabled_contention_counters_are_a_failure(self):
        self.induce("database_contention", lambda: self.mysql.update(metrics="disabled"))

    def test_backup_lag_needs_two_consecutive_late_checks(self):
        self.baseline()
        self.write_access(100, 1)
        os.utime(self.root / "checkpoint.json", (NOW - 400, NOW - 400))
        self.assertEqual(self.run_probe()["backup_lag"]["state"], "ok")
        self.assertEqual(self.run_probe(NOW + 60)["backup_lag"]["state"], "fail")
        self.assertEqual(self.fail_pings(NOW + 60), {"backup_lag"})

    def test_restore_overdue_after_eight_days(self):
        def change():
            path = self.root / "restores" / "restore-test-1.json"
            path.write_text(json.dumps({"result": "restore-test-passed", "verified_unix_s": NOW - 8 * 86400 - 1}))
        self.induce("restore_overdue", change)

    def test_rehearsal_failed_on_red_or_missing_result(self):
        self.induce("rehearsal_failed", lambda: (self.root / "nightly" / "nightly-2.json").write_text(
            json.dumps({"result": "nightly-data-red", "verified_unix_s": NOW - 60})))

    def test_detector_crash_reports_failure_not_silence(self):
        self.baseline()
        self.write_access(100, 1)
        self.mysql["down"] = True
        statuses = self.run_probe()
        self.assertEqual(statuses["mysql_restarted"]["state"], "fail")
        self.assertNotIn("secret", json.dumps(statuses))

    def test_status_files_never_carry_env_values(self):
        self.baseline()
        self.write_access(100, 1)
        self.run_probe()
        text = "".join(path.read_text() for path in self.inbox.iterdir())
        self.assertNotIn("base64:secret", text)
        for path in self.inbox.iterdir():
            record = json.loads(path.read_text())
            self.assertEqual(set(record), bridge.STATUS_FIELDS)

    def test_plan_refuses_unknown_conditions(self):
        self.plan["conditions"] = ["server_silent"]
        with self.assertRaises(probe.Refusal):
            probe.parse_plan(json.dumps(self.plan).encode())


if __name__ == "__main__":
    unittest.main()


class ExternalAlertContractTests(unittest.TestCase):
    """Synthetic phone observations at each condition's own delivery limit pass summarize_external_alerts."""

    def observations(self, late=None):
        from scripts.cutover.readiness import summarize_external_alerts as alerts
        from scripts.cutover.readiness.test_check import readiness_fixture
        from scripts.cutover.runner import digest

        policy, _ = readiness_fixture()
        now = 1_800_000_000_000
        rounds = {}
        for index, milestone in enumerate(check.ALERT_MILESTONES, 1):
            cases = {}
            for order, (name, item) in enumerate(sorted(probe.load_conditions().items())):
                delay = item["delivery_limit_seconds"] * 1000 + (1 if name == late else 0)
                cases[name] = {"triggered_at_unix_ms": now - delay, "phone_observed_at_unix_ms": now,
                               "condition_evidence_sha256": f"{index * 100 + order:064x}",
                               "delivery_receipt_sha256": f"{index * 1000 + order:064x}"}
            rounds[milestone] = {"report_sha256": f"{index:064x}", "cases": cases}
        raw = json.dumps({"schema": alerts.SCHEMA, "identity_sha256": digest(policy["identity"]),
                          "monitor_profile_sha256": policy["operations"]["monitor_profile_sha256"],
                          "phone_recipient_sha256": policy["operations"]["phone_recipient_sha256"],
                          "rounds": rounds}).encode()
        policy["operations"]["external_alerts_report_sha256"] = __import__("hashlib").sha256(raw).hexdigest()
        with tempfile.TemporaryDirectory() as name:
            path = Path(name) / "observations.json"
            path.write_bytes(raw)
            return alerts.summarize(policy, path, now_unix_ms=now)

    def test_thresholds_file_limits_pass_the_external_alert_gate(self):
        summary = self.observations()
        self.assertEqual(summary["before_drill"]["cases"]["server_silent"]["delivery_ms"], 10 * check.MINUTE)

    def test_one_millisecond_over_a_limit_fails_the_gate(self):
        with self.assertRaises(check.Failed):
            self.observations(late="queue_stale")
