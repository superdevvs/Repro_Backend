"""Point-in-time drill runner with fake steps; its outputs must satisfy summarize_point_in_time."""
from __future__ import annotations

import gzip
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import threading
import time
import unittest

import publish_backup as backup
import run_pitr_drill as drill

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO))
from scripts.cutover.readiness import check, summarize_point_in_time as pitr  # noqa: E402
from scripts.cutover.readiness.test_check import readiness_fixture  # noqa: E402
from scripts.cutover.runner import digest  # noqa: E402

SOURCE = "10100000-0000-4000-a000-000000000000"
RESTORED = "20200000-0000-4000-a000-000000000000"


def publication(backup_id: str) -> dict:
    return {"backup_id": backup_id, "release": "a" * 40, "manifest_bytes": 10, "manifest_sha256": "1" * 64,
            "completion_bytes": 10, "completion_sha256": "2" * 64}


class FakeSteps:
    """Stands in for restore_backup.py, the chain verifier, mysql, mysqlbinlog and heartbeat.py."""

    def __init__(self, *, planted_present=False, fail=None, clock=None):
        self.calls = []
        self.planted_present = planted_present
        self.fail = fail
        self.clock = clock

    def step(self, name):
        self.calls.append(name)
        if self.clock is not None:
            self.clock.append(self.clock[-1] + 60 * 10**9)
        if name == self.fail:
            raise backup.Refusal(f"{name} failed")

    def restore(self, plan, item, identity, output: Path) -> bytes:
        self.step("restore")
        artifacts = output / "artifacts"
        artifacts.mkdir(parents=True)
        if identity == plan["source_uuid"]:
            (artifacts / "database-backup.json").write_text(json.dumps(
                {"pitr": {"binlog_file": "binlog.000002", "binlog_position": 155}}))
            (artifacts / "database.sql.gz").write_bytes(b"dump")
            entries = ["database-backup.json", "database.sql.gz"]
        else:
            entries = []
            for number in item["files"]:
                name = f"binlog.{number:06d}"
                (artifacts / name).write_bytes(name.encode())
                entries.append(name)
        manifest = {"artifacts": [{"path": name, "bytes": (artifacts / name).stat().st_size,
                                   "sha256": backup.sha256((artifacts / name).read_bytes())} for name in entries]}
        (output / "input-manifest.json").write_text(json.dumps(manifest))
        return (json.dumps({"result": "artifacts-recovered-and-verified", "scope": "database", "identity": identity,
                            "artifact_count": len(entries), "artifact_bytes": 99, "database_restore_verified": False},
                           sort_keys=True) + "\n").encode()

    def verify_chain(self, plan, root: Path, inventory_sha256: str, producer_sha256: str) -> bytes:
        self.step("verify_chain")
        self.inventory = json.loads((root / "binlog-chain.json").read_bytes())
        assert backup.sha256((root / "binlog-chain.json").read_bytes()) == inventory_sha256
        return (json.dumps({"result": "closed-mysql-binlog-chain-verified", "source_uuid": plan["source_uuid"],
                            "stop": plan["stop"], "contiguous_binlog_chain": True, "replay_verified": False},
                           sort_keys=True) + "\n").encode()

    def load_base(self, plan, dump: Path) -> None:
        self.step("load_base")

    def replay(self, plan, files, start, stop) -> None:
        self.step("replay")
        self.replayed = (files, start, stop)

    def heartbeat(self, plan) -> bytes:
        self.step("heartbeat")
        return (json.dumps({"result": "recovered-heartbeat-verified", "source_uuid": RESTORED,
                            "target_utc": plan["target_utc"], "recorded_utc": "2026-11-24T00:00:00Z",
                            "minute_utc": "2026-11-24T00:00:00Z", "recovery_gap_seconds": 60,
                            "record_requested": False, "remote_accessed": False}, sort_keys=True) + "\n").encode()

    def query(self, plan, sql: str) -> str:
        self.step("query")
        if sql == "SELECT @@server_uuid":
            return RESTORED
        if "planted" in sql:
            return "1" if self.planted_present else "0"
        return "1"


class DrillTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.root = Path(folder.name)
        self.policy, _ = readiness_fixture()

    def plan(self, run_id, *, planted=True):
        return {"version": 1, "run_id": run_id, "destination_sha256": self.policy["operations"]["destination_sha256"],
                "source_uuid": SOURCE, "database": "repro_dashboard", "server_id": 64084,
                "base": publication("aaaaaaaa-0000-4000-8000-000000000001"),
                "binlogs": [publication("bbbbbbbb-0000-4000-8000-000000000001") | {"files": [1, 2, 3]},
                            publication("bbbbbbbb-0000-4000-8000-000000000002") | {"files": [4, 5]}],
                "target_utc": "2026-11-24T00:01:00Z", "stop": {"file": "binlog.000004", "position": 543},
                "chosen_check": {"sql": "SELECT COUNT(*) FROM repro_drill.markers WHERE id = 3", "expected": "1"},
                "planted_check": ({"sql": "SELECT COUNT(*) FROM repro_drill.planted WHERE id = 4", "expected": "0"}
                                  if planted else None),
                "work_dir": str(self.root / ("work-" + run_id))}

    def run_drill(self, run_id, steps, **changes):
        out = self.root / run_id
        clock = steps.clock if steps.clock is not None else [10**9]
        steps.clock = clock
        return drill.run_drill(self.plan(run_id, **changes), steps, out, clock=lambda: clock[-1]), out

    def test_two_drills_pass_the_point_in_time_summary(self):
        first, out_a = self.run_drill("drill-a", FakeSteps(), planted=False)
        second, out_b = self.run_drill("drill-b", FakeSteps())
        self.assertEqual(first["result"], "native-mysql-replay-verified")
        self.assertTrue(second["planted_bad_write_excluded"])
        self.assertFalse(first["planted_bad_write_excluded"])
        pins = [hashlib.sha256((out / "replay.json").read_bytes()).hexdigest() for out in (out_a, out_b)]
        manifest = self.root / "manifest.json"
        manifest.write_text(json.dumps({"schema": pitr.SCHEMA, "identity_sha256": digest(self.policy["identity"]),
                                        "destination_sha256": self.policy["operations"]["destination_sha256"],
                                        "replay_report_sha256s": pins}))
        self.policy["operations"]["point_in_time_drills_report_sha256"] = hashlib.sha256(manifest.read_bytes()).hexdigest()
        summary = pitr.summarize(self.policy, manifest, [out_a, out_b])
        self.assertLessEqual(max(item["database_recovery_ms"] for item in summary["drills"]), 30 * check.MINUTE)

    def test_chain_inventory_runs_from_the_base_start_to_the_stop_file_only(self):
        steps = FakeSteps()
        self.run_drill("drill-a", steps)
        self.assertEqual([item["file"] for item in steps.inventory["segments"]],
                         ["binlog.000002", "binlog.000003", "binlog.000004"])
        self.assertEqual(steps.inventory["start"], {"file": "binlog.000002", "position": 155})
        self.assertEqual(steps.replayed[1:], (155, 543))
        self.assertEqual([path.name for path in steps.replayed[0]], ["binlog.000002", "binlog.000003", "binlog.000004"])

    def test_timing_runs_from_retrieval_to_verified_target(self):
        steps = FakeSteps(clock=[5 * 10**9])
        result, _ = self.run_drill("drill-a", steps)
        self.assertEqual(result["recovery_started_monotonic_ns"], 5 * 10**9)
        self.assertEqual(result["recovery_verified_monotonic_ns"], steps.clock[-1])
        self.assertEqual(steps.calls[0], "restore")

    def test_a_present_planted_write_or_failed_step_is_never_verified(self):
        result, out = self.run_drill("drill-a", FakeSteps(planted_present=True))
        self.assertEqual(result["result"], "native-mysql-replay-failed")
        self.assertFalse(result["planted_bad_write_excluded"])
        result, out = self.run_drill("drill-b", FakeSteps(fail="replay"))
        self.assertEqual((result["result"], result["failed_step"]), ("native-mysql-replay-failed", "replay"))
        self.assertEqual(json.loads((out / "replay.json").read_bytes())["result"], "native-mysql-replay-failed")


FAKE_CLIENT = """#!{python} -I
import json, sys
argv = sys.argv[1:]
data = sys.stdin.buffer.read().decode()
with open({log!r}, "a") as stream:
    stream.write(json.dumps({{"argv": argv, "stdin": data}}) + "\\n")
statement = next((item.split("=", 1)[1] for item in argv if item.startswith("--execute=")), "")
selected = any(item.startswith("--database=") for item in argv) or "USE " in data
# Like the real client: a CREATE TABLE with no selected database fails with ERROR 1046.
sys.exit(1 if "CREATE TABLE" in data + statement and not selected else 0)
"""


# Creates the database, then never reads the dump, like a client whose server has stalled.
STALLED_CLIENT = """#!{python} -I
import sys, time
if any(item.startswith("--execute=") for item in sys.argv[1:]):
    sys.exit(0)
time.sleep(30)
"""


@unittest.skipUnless(sys.platform == "linux", "runs a fake mysql client as a child process")
class StockLoadTests(unittest.TestCase):
    """The real backup-database.php dump names no database (no --databases), so the loader must select one."""

    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.root = Path(folder.name)
        self.log = self.root / "client.log"
        client = self.root / "mysql"
        client.write_text(FAKE_CLIENT.format(python=sys.executable, log=str(self.log)))
        client.chmod(0o700)
        self.plan = {"database": "repro_dashboard", "timeout_seconds": 30,
                     "target": {"client": str(client), "defaults_file": "/x/target.cnf", "socket": "/x/target.sock"}}
        # The producer's mysqldump argv ends with "-- <database>", so the dump has no CREATE DATABASE or USE.
        self.dump = self.root / "database.sql.gz"
        with gzip.open(self.dump, "wb") as stream:
            stream.write(b"/*!40101 SET NAMES utf8mb4 */;\nDROP TABLE IF EXISTS `markers`;\n"
                         b"CREATE TABLE `markers` (`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;\n"
                         b"INSERT INTO `markers` VALUES (1);\n")

    def calls(self):
        return [json.loads(line) for line in self.log.read_text().splitlines()]

    def test_load_base_creates_and_selects_the_plan_database_for_the_producer_dump(self):
        drill.StockSteps(self.plan).load_base(self.plan, self.dump)
        create, load = self.calls()
        self.assertIn("--execute=CREATE DATABASE `repro_dashboard`", create["argv"])
        self.assertIn("--database=repro_dashboard", load["argv"])
        self.assertIn("CREATE TABLE `markers`", load["stdin"])

    def test_load_base_stops_at_its_time_limit_when_the_client_stops_reading(self):
        # A target that stalls (a lock, a full disk) stops the client reading its input. Once the
        # pipe filled, the copy blocked before the timed wait was reached, so the drill hung
        # with no replay.json at all instead of failing at load_base.
        client = Path(self.plan["target"]["client"])
        client.write_text(STALLED_CLIENT.format(python=sys.executable))
        self.plan["timeout_seconds"] = 2
        with gzip.open(self.dump, "wb") as stream:
            stream.write(b"INSERT INTO `markers` VALUES (1);\n" * 200_000)  # Far more than a pipe holds.
        outcome = {}

        def load():
            started = time.monotonic()
            try:
                drill.StockSteps(self.plan).load_base(self.plan, self.dump)
                outcome["result"] = "loaded"
            except backup.Refusal as error:
                outcome["result"] = str(error)
            except OSError as error:
                outcome["result"] = type(error).__name__
            outcome["seconds"] = time.monotonic() - started

        worker = threading.Thread(target=load, daemon=True)
        worker.start()
        worker.join(15)
        self.assertFalse(worker.is_alive(), "load_base was still blocked writing to a client that stopped reading")
        self.assertEqual(outcome["result"], "base load exceeded its time limit")
        self.assertLess(outcome["seconds"], 10)

    def test_load_base_refuses_a_database_name_it_cannot_quote_safely(self):
        self.plan["database"] = "repro`; DROP DATABASE mysql; --"
        with self.assertRaises(backup.Refusal):
            drill.StockSteps(self.plan).load_base(self.plan, self.dump)
        self.assertFalse(self.log.exists())


if __name__ == "__main__":
    unittest.main()
