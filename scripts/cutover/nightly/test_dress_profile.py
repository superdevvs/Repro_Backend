import copy
import hashlib
import json
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from scripts.cutover.nightly import dress_profile as profile
from scripts.cutover.readiness import check

HERE = Path(__file__).resolve().parent
HOST_PROFILE = HERE / "dress" / "host-profile.json"
WORKLOAD = HERE / "dress" / "workload.json"


def write_traces(path: Path, rows) -> str:
    with sqlite3.connect(path) as db:
        db.execute("CREATE TABLE system_overview_request_traces (id INTEGER PRIMARY KEY, method TEXT NOT NULL,"
                   " path TEXT NOT NULL, occurred_at TEXT NOT NULL)")
        db.executemany("INSERT INTO system_overview_request_traces (method, path, occurred_at) VALUES (?, ?, ?)", rows)
    db.close()
    return hashlib.sha256(path.read_bytes()).hexdigest()


class BusiestEndpointTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.folder = Path(folder.name)
        self.snapshot = self.folder / "snapshot.sqlite"
        rows = []
        # 55 endpoints in the window: endpoint n has 100 - n requests, with a tie
        # between n=10 and n=11 so ordering must fall back to the label.
        for n in range(55):
            requests = 100 - n if n != 11 else 90
            rows += [("GET", f"/api/route-{n:02d}", "2026-11-25 04:00:00")] * requests
        rows += [("GET", "/api/too-old", "2026-11-24 05:00:00")] * 500
        rows += [("POST", "/api/after-capture", "2026-11-25 05:00:01")] * 500
        self.sha = write_traces(self.snapshot, rows)

    def report(self, **overrides):
        options = {"as_of_utc": "2026-11-25T05:00:00Z"} | overrides
        return profile.busiest_report(self.snapshot, self.sha, **options)

    def test_ranks_the_fifty_busiest_labels_inside_the_window(self):
        report = self.report()
        labels = [entry["label"] for entry in report["endpoints"]]
        self.assertEqual(len(labels), check.SPEED_ENDPOINT_COUNT)
        self.assertEqual(labels[:3], ["GET /api/route-00", "GET /api/route-01", "GET /api/route-02"])
        self.assertLess(labels.index("GET /api/route-10"), labels.index("GET /api/route-11"))
        self.assertNotIn("GET /api/too-old", labels)
        self.assertNotIn("POST /api/after-capture", labels)
        self.assertEqual(report["total_requests"], sum(100 - n if n != 11 else 90 for n in range(55)))
        self.assertEqual(report["distinct_endpoints"], 55)
        self.assertEqual(report["snapshot_sha256"], self.sha)
        self.assertFalse(report["database_writes"])

    def test_refuses_a_changed_snapshot_or_too_few_endpoints(self):
        with self.assertRaisesRegex(profile.Refused, "snapshot"):
            profile.busiest_report(self.snapshot, "0" * 64, as_of_utc="2026-11-25T05:00:00Z")
        with self.assertRaisesRegex(profile.Refused, "50"):
            self.report(as_of_utc="2026-11-24T06:00:00Z")


class ProfileFileTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.folder = Path(folder.name)
        self.workload = json.loads(WORKLOAD.read_bytes())
        self.host = json.loads(HOST_PROFILE.read_bytes())

    def busiest(self):
        return {"schema": profile.BUSIEST_SCHEMA, "result": "busiest-endpoints-observed",
                "snapshot_sha256": "a" * 64, "as_of_utc": "2026-11-25T05:00:00Z", "window_hours": 24,
                "total_requests": 5000, "distinct_endpoints": 60, "database_writes": False,
                "endpoints": [{"label": f"GET /api/busy-{n:02d}", "requests": 100 - n}
                              for n in range(check.SPEED_ENDPOINT_COUNT)]}

    def test_committed_profiles_are_valid_and_pinned_by_their_bytes(self):
        pins = profile.policy_pins(HOST_PROFILE, WORKLOAD)
        self.assertEqual(pins["host_profile_sha256"], hashlib.sha256(HOST_PROFILE.read_bytes()).hexdigest())
        self.assertEqual(pins["workload_sha256"], hashlib.sha256(WORKLOAD.read_bytes()).hexdigest())
        self.assertEqual(pins["critical_endpoints"], self.workload["critical_endpoints"])
        self.assertEqual(len(set(pins["critical_endpoints"])), len(pins["critical_endpoints"]))
        # The busiest list needs the private telemetry copy, so the draft leaves it unset.
        self.assertIsNone(pins["busiest_endpoints"])

    def test_filled_workload_binds_the_reviewed_busiest_report(self):
        busiest = self.busiest()
        filled = profile.fill_workload(self.workload, busiest, "b" * 64)
        self.assertEqual(filled["busiest_endpoints"], [entry["label"] for entry in busiest["endpoints"]])
        self.assertEqual(filled["busiest_endpoints_report_sha256"], "b" * 64)
        path = self.folder / "workload.json"
        path.write_text(json.dumps(filled, indent=2) + "\n", encoding="utf-8")
        pins = profile.policy_pins(HOST_PROFILE, path)
        self.assertEqual(len(pins["busiest_endpoints"]), check.SPEED_ENDPOINT_COUNT)
        policy = {"busiest_endpoints": pins["busiest_endpoints"]}
        self.assertEqual(check.busiest_endpoints(policy), pins["busiest_endpoints"])
        busiest["endpoints"].pop()
        with self.assertRaisesRegex(profile.Refused, "50"):
            profile.fill_workload(self.workload, busiest, "b" * 64)

    def test_workload_cannot_weaken_the_plan_marks(self):
        for path, value in ((("stress", "parallel_clients"), 19), (("stress", "duration_ms"), check.STRESS_MIN_MS - 1),
                            (("crash", "mysql_kills"), 2), (("crash", "recovery_limit_ms"), check.CRASH_RECOVERY_MS + 1)):
            with self.subTest(path=path):
                weakened = copy.deepcopy(self.workload)
                weakened[path[0]][path[1]] = value
                with self.assertRaises(profile.Refused):
                    profile.validate_workload(weakened)

    def test_host_profile_must_keep_durable_mysql_settings(self):
        for name, value in (("sync_binlog", 0), ("innodb_flush_log_at_trx_commit", 2), ("binlog_row_image", "MINIMAL")):
            with self.subTest(name=name):
                weakened = copy.deepcopy(self.host)
                weakened["mysql"][name] = value
                with self.assertRaises(profile.Refused):
                    profile.validate_host_profile(weakened)

    def test_cli_prints_policy_pins(self):
        result = subprocess.run([sys.executable, "-B", "-m", "scripts.cutover.nightly.dress_profile", "pins",
                                 "--host-profile", str(HOST_PROFILE), "--workload", str(WORKLOAD)],
                                capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        output = json.loads(result.stdout)
        self.assertEqual(output["workload_sha256"], hashlib.sha256(WORKLOAD.read_bytes()).hexdigest())


if __name__ == "__main__":
    unittest.main()
