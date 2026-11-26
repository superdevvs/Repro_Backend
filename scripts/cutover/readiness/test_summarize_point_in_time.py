import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from scripts.cutover.readiness import check, summarize_point_in_time as pitr
from scripts.cutover.readiness.test_check import readiness_fixture
from scripts.cutover.runner import digest


class PointInTimeSummaryTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.folder = Path(folder.name)
        self.policy, _ = readiness_fixture()
        self.operations = self.policy["operations"]
        self.manifest_path = self.folder / "manifest.json"
        self.drills = [self.folder / f"drill-{index}" for index in range(check.PITR_DRILL_COUNT)]
        for directory in self.drills:
            directory.mkdir()
        self.source = "10100000-0000-4000-a000-000000000000"
        self.restored = "20200000-0000-4000-a000-000000000000"

    @staticmethod
    def write(path, record):
        raw = (json.dumps(record) + "\n").encode()
        path.write_bytes(raw)
        return hashlib.sha256(raw).hexdigest()

    def write_drill(self, index, *, replay=None, artifacts=None, chain=None, heartbeat=None):
        directory = self.drills[index]
        target = "2026-11-24T00:01:00Z"
        stop = {"file": "mysql-bin.000002", "position": 543}
        artifact_sha = self.write(directory / "artifacts.json", {
            "result": "artifacts-recovered-and-verified",
            "scope": "database",
            "identity": self.source,
            "artifact_count": 2,
            "artifact_bytes": 4096,
            "database_restore_verified": False,
        } | (artifacts or {}))
        chain_sha = self.write(directory / "chain.json", {
            "result": "closed-mysql-binlog-chain-verified",
            "source_uuid": self.source,
            "stop": stop,
            "contiguous_binlog_chain": True,
            "replay_verified": False,
        } | (chain or {}))
        heartbeat_sha = self.write(directory / "heartbeat.json", {
            "result": "recovered-heartbeat-verified",
            "source_uuid": self.restored,
            "target_utc": target,
            "recorded_utc": "2026-11-24T00:00:00Z",
            "minute_utc": "2026-11-24T00:00:00Z",
            "recovery_gap_seconds": 60,
            "record_requested": False,
            "remote_accessed": False,
        } | (heartbeat or {}))
        return self.write(directory / "replay.json", {
            "result": "native-mysql-replay-verified",
            "run_id": f"restore-{index}",
            "destination_sha256": self.operations["destination_sha256"],
            "source_uuid": self.source,
            "restored_uuid": self.restored,
            "target_utc": target,
            "replayed_stop": stop,
            "recovery_started_monotonic_ns": 1,
            "recovery_verified_monotonic_ns": 30 * check.MINUTE * 1_000_000 + 1,
            "planted_bad_write_excluded": index == 1,
            **dict.fromkeys(check.PITR_REPLAY_PROOFS, True),
            "artifacts_sha256": artifact_sha,
            "chain_sha256": chain_sha,
            "heartbeat_sha256": heartbeat_sha,
        } | (replay or {}))

    def write_reviewed_input(self, **changes):
        pins = [self.write_drill(0, **changes), self.write_drill(1)]
        self.operations["point_in_time_drills_report_sha256"] = self.write(self.manifest_path, {
            "schema": pitr.SCHEMA,
            "identity_sha256": digest(self.policy["identity"]),
            "destination_sha256": self.operations["destination_sha256"],
            "replay_report_sha256s": pins,
        })

    def summarize(self):
        return pitr.summarize(self.policy, self.manifest_path, self.drills)

    def test_summary_passes_existing_gate_and_derives_both_timings(self):
        self.write_reviewed_input()
        summary = self.summarize()
        self.assertEqual(summary["drills"][0]["database_recovery_ms"], 30 * check.MINUTE)
        self.assertEqual(summary["drills"][0]["absolute_heartbeat_target_delta_ms"], check.MINUTE)
        self.operations["point_in_time_drills_summary_sha256"] = digest(summary)
        check.review_point_in_time_drills(self.policy, {"point_in_time_drills": summary})

    def test_artifact_or_chain_verification_cannot_replace_native_replay(self):
        for result in ("artifacts-recovered-and-verified", "closed-mysql-binlog-chain-verified"):
            with self.subTest(result=result):
                self.write_reviewed_input(replay={"result": result})
                with self.assertRaisesRegex(check.Failed, "native MySQL replay"):
                    self.summarize()

    def test_manifest_replay_and_each_component_are_exactly_pinned(self):
        paths = [self.manifest_path, *(self.drills[0] / name for name in
                  ("replay.json", "artifacts.json", "chain.json", "heartbeat.json"))]
        for path in paths:
            with self.subTest(path=path.name):
                self.write_reviewed_input()
                path.write_bytes(path.read_bytes() + b"\n")
                with self.assertRaisesRegex(check.Failed, "reviewed SHA-256"):
                    self.summarize()

    def test_rejects_mismatched_or_incomplete_drills_even_when_pinned(self):
        for changes, expected in (
            ({"artifacts": {"identity": self.restored}}, "reviewed MySQL source"),
            ({"chain": {"stop": {"file": "mysql-bin.000003", "position": 543}}}, "Selected replay stop"),
            ({"chain": {"stop": {}}, "replay": {"replayed_stop": {}}}, "invalid coordinate"),
            ({"heartbeat": {"source_uuid": self.source}}, "reviewed instance or target"),
            ({"heartbeat": {"recorded_utc": "2026-11-24T00:01:01Z"}}, "recorded recovery gap"),
            ({"heartbeat": {"recovery_gap_seconds": 10**50}}, "recorded recovery gap"),
            ({"heartbeat": {"recorded_utc": "2026-11-23T23:59:59Z", "minute_utc": "2026-11-23T23:59:00Z",
                            "recovery_gap_seconds": 61}}, "over one minute"),
            ({"replay": {"recovery_verified_monotonic_ns": 30 * check.MINUTE * 1_000_000 + 2}}, "exceeds"),
            ({"replay": {"offsite_only": False}}, "offsite_only did not pass"),
            ({"replay": {"run_id": "restore-1"}}, "distinct run IDs"),
        ):
            with self.subTest(changes=changes):
                self.write_reviewed_input(**changes)
                with self.assertRaisesRegex(check.Failed, expected):
                    self.summarize()

    def test_cli_returns_summary_and_pin(self):
        self.write_reviewed_input()
        policy_path = self.folder / "policy.json"
        self.write(policy_path, self.policy)
        result = subprocess.run([
            sys.executable, "-B", "-m", "scripts.cutover.readiness.summarize_point_in_time",
            "--policy", str(policy_path), "--manifest", str(self.manifest_path),
            "--drills", *(str(directory) for directory in self.drills),
        ], capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0)
        self.assertEqual(result.stderr, "")
        output = json.loads(result.stdout)
        self.assertEqual(output["summary_sha256"], digest(output["point_in_time_drills"]))


if __name__ == "__main__":
    unittest.main()
