"""Build a point-in-time recovery summary from reviewed local evidence files."""
from __future__ import annotations

import argparse
from datetime import datetime, timedelta
import json
from pathlib import Path
import re
from typing import Sequence
import uuid

from scripts.cutover.readiness import check
from scripts.cutover.runner import digest, is_sha256


SCHEMA = "repro.point-in-time-drills.manifest.v1"
UTC_SECOND = re.compile(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z")


def utc_second(record: dict, name: str) -> datetime:
    value = check.field(record, name)
    if isinstance(value, str) and UTC_SECOND.fullmatch(value):
        try:
            return datetime.fromisoformat(value[:-1] + "+00:00")
        except ValueError:
            pass
    raise check.Failed(f"{name} must be an exact UTC second")


def mysql_uuid(record: dict, name: str) -> str:
    value = check.field(record, name)
    if isinstance(value, str):
        try:
            if str(uuid.UUID(value)) == value:
                return value
        except ValueError:
            pass
    raise check.Failed(f"{name} must be a canonical MySQL UUID")


def recovery_drill(directory: Path, replay_sha: str, destination_sha: str) -> tuple[str, dict]:
    replay = check.load(directory / "replay.json", expected_sha256=replay_sha)
    if (replay.get("result") != "native-mysql-replay-verified"
            or check.sha_field(replay, "destination_sha256") != destination_sha):
        raise check.Failed("Expected a native MySQL replay report for the reviewed destination")
    run_id = check.field(replay, "run_id")
    if not isinstance(run_id, str) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_-]{0,63}", run_id):
        raise check.Failed("Point-in-time drill requires a valid run ID")
    source = mysql_uuid(replay, "source_uuid")
    restored = mysql_uuid(replay, "restored_uuid")
    if source == restored:
        raise check.Failed("Recovery must use an independent MySQL instance")
    artifacts = check.load(directory / "artifacts.json", expected_sha256=check.sha_field(replay, "artifacts_sha256"))
    if (artifacts.get("result") != "artifacts-recovered-and-verified" or artifacts.get("scope") != "database"
            or mysql_uuid(artifacts, "identity") != source):
        raise check.Failed("Off-site artifact recovery differs from the reviewed MySQL source")
    check.number(artifacts, "artifact_count", minimum=1)
    check.number(artifacts, "artifact_bytes", minimum=1)

    chain = check.load(directory / "chain.json", expected_sha256=check.sha_field(replay, "chain_sha256"))
    stop = check.object_field(chain, "stop")
    if (set(stop) != {"file", "position"} or not isinstance(stop["file"], str) or not stop["file"]
            or check.number(stop, "position", minimum=4) >= 2**32):
        raise check.Failed("Verified binary log stop has an invalid coordinate")
    if (chain.get("result") != "closed-mysql-binlog-chain-verified"
            or mysql_uuid(chain, "source_uuid") != source or stop != check.object_field(replay, "replayed_stop")):
        raise check.Failed("Selected replay stop differs from the verified binary log chain")
    check.confirmed(chain, "contiguous_binlog_chain")

    heartbeat = check.load(directory / "heartbeat.json", expected_sha256=check.sha_field(replay, "heartbeat_sha256"))
    target = utc_second(replay, "target_utc")
    if (heartbeat.get("result") != "recovered-heartbeat-verified"
            or heartbeat.get("record_requested") is not False or heartbeat.get("remote_accessed") is not False
            or mysql_uuid(heartbeat, "source_uuid") != restored or utc_second(heartbeat, "target_utc") != target):
        raise check.Failed("Recovered heartbeat differs from the reviewed instance or target")
    recorded = utc_second(heartbeat, "recorded_utc")
    gap_seconds = check.number(heartbeat, "recovery_gap_seconds")
    if ((target - recorded) // timedelta(seconds=1) != gap_seconds
            or utc_second(heartbeat, "minute_utc") != recorded.replace(second=0)):
        raise check.Failed("Recovered heartbeat does not match its recorded recovery gap")

    start = check.number(replay, "recovery_started_monotonic_ns")
    finish = check.number(replay, "recovery_verified_monotonic_ns", minimum=start + 1)
    excluded = check.field(replay, "planted_bad_write_excluded")
    if type(excluded) is not bool:
        raise check.Failed("Planted-write exclusion must be a boolean result")
    return run_id, {
        "report_sha256": replay_sha,
        **{name: check.field(replay, name) for name in check.PITR_REPLAY_PROOFS},
        "contiguous_binlog_chain": True,
        "database_recovery_ms": (finish - start + 999_999) // 1_000_000,
        "absolute_heartbeat_target_delta_ms": gap_seconds * 1_000,
        "planted_bad_write_excluded": excluded,
    }


def summarize(policy: dict, manifest_path: Path, directories: Sequence[Path]) -> dict:
    check.version(policy)
    operations = check.object_field(policy, "operations")
    destination_sha = check.sha_field(operations, "destination_sha256")
    identity_sha = digest(check.load_identity(policy))
    manifest_sha = check.sha_field(operations, "point_in_time_drills_report_sha256")
    manifest = check.load(manifest_path, expected_sha256=manifest_sha)
    if (set(manifest) != {"schema", "identity_sha256", "destination_sha256", "replay_report_sha256s"}
            or manifest["schema"] != SCHEMA or manifest["identity_sha256"] != identity_sha
            or manifest["destination_sha256"] != destination_sha):
        raise check.Failed("Recovery manifest differs from the reviewed identity or destination")
    selected = manifest["replay_report_sha256s"]
    if (not isinstance(selected, list) or len(selected) != check.PITR_DRILL_COUNT
            or len(directories) != check.PITR_DRILL_COUNT):
        raise check.Failed("Supply exactly two reviewed point-in-time drills")

    runs = []
    run_ids = set()
    for directory, replay_sha in zip(directories, selected, strict=True):
        if not is_sha256(replay_sha):
            raise check.Failed("Each replay report must have a reviewed SHA-256 digest")
        run_id, report = recovery_drill(directory, replay_sha, destination_sha)
        if run_id in run_ids:
            raise check.Failed("Point-in-time drills must have distinct run IDs")
        run_ids.add(run_id)
        runs.append(report)
    check.review_point_in_time_runs(runs)
    return {"identity_sha256": identity_sha, "destination_sha256": destination_sha,
            "report_sha256": manifest_sha, "drills": runs}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--policy", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--drills", nargs=check.PITR_DRILL_COUNT, type=Path, required=True,
                        metavar="EVIDENCE_DIR", help="reviewed drill directories in manifest order")
    args = parser.parse_args()
    try:
        summary = summarize(check.load(args.policy), args.manifest, args.drills)
    except (check.Missing, check.Failed) as error:
        print(json.dumps({"result": "point-in-time-summary-refused", "detail": str(error)}))
        return 1
    print(json.dumps({"result": "point-in-time-summary-ready", "point_in_time_drills": summary,
                      "summary_sha256": digest(summary)}, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
