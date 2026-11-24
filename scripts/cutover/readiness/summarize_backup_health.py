"""Derive seven-day backup health from reviewed, pinned monitor observations."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import time
from typing import BinaryIO

from scripts.cutover.readiness import check
from scripts.cutover.runner import digest, is_sha256


SCHEMA = "repro.backup-health.observations.v1"
HEADER_LIMIT = 2048
OBSERVATION_LIMIT = 512
OBSERVATION_FIELDS = {
    "slot_start_unix_s", "sample_sha256", "newest_verified_base_unix_s", "recoverable_through_unix_s",
}


def read_record(stream: BinaryIO, limit: int) -> tuple[bytes, dict]:
    raw = stream.readline(limit + 1)
    if not raw or len(raw) > limit:
        raise check.Failed("A scheduled observation is missing or exceeds its record limit")
    try:
        record = json.loads(raw.decode("utf-8"), object_pairs_hook=check.unique_keys,
                            parse_constant=check.reject_constant, parse_float=check.reject_constant)
    except (ValueError, UnicodeError, RecursionError) as error:
        raise check.Failed("A scheduled observation is malformed") from error
    if not isinstance(record, dict):
        raise check.Failed("Every monitor record must be an object")
    return raw, record


def expected_header(policy: dict, *, now_unix_s: int) -> dict:
    check.version(policy)
    operations = check.object_field(policy, "operations")
    stop = check.number(operations, "window_end_unix_s", minimum=check.BACKUP_WINDOW_SECONDS)
    expires = check.number(operations, "evidence_valid_until_unix_s", minimum=stop)
    if type(now_unix_s) is not int or not stop <= now_unix_s <= expires:
        raise check.Failed("The reviewed observation window is in the future or has expired")
    check.cap(operations, "interval_s", check.BACKUP_LAG_SECONDS)
    return {
        "schema": SCHEMA,
        "identity_sha256": digest(check.load_identity(policy)),
        "destination_sha256": check.sha_field(operations, "destination_sha256"),
        "monitor_profile_sha256": check.sha_field(operations, "monitor_profile_sha256"),
        "window_start_unix_s": stop - check.BACKUP_WINDOW_SECONDS,
        "window_end_unix_s": stop,
        "interval_s": operations["interval_s"],
        "external_observer_verified": True,
    }


def summarize(policy: dict, stream: BinaryIO, *, now_unix_s: int) -> dict:
    expected = expected_header(policy, now_unix_s=now_unix_s)
    expected_sha = check.sha_field(policy["operations"], "backup_health_report_sha256")
    raw, header = read_record(stream, HEADER_LIMIT)
    schedule_fields = ("interval_s", "window_start_unix_s", "window_end_unix_s")
    if (header != expected or header["external_observer_verified"] is not True
            or any(type(header[field]) is not int for field in schedule_fields)):
        raise check.Failed("Monitor observations differ from the reviewed scope or window")
    report_hash = hashlib.sha256(raw)
    start, stop, interval = (expected[name] for name in ("window_start_unix_s", "window_end_unix_s", "interval_s"))
    samples: set[bytes] = set()
    max_age = timely = covered = 0
    for slot in range(start, stop, interval):
        raw, sample = read_record(stream, OBSERVATION_LIMIT)
        report_hash.update(raw)
        if (set(sample) != OBSERVATION_FIELDS or type(sample["slot_start_unix_s"]) is not int
                or sample["slot_start_unix_s"] != slot or not is_sha256(sample["sample_sha256"])):
            raise check.Failed("Schedule observations are missing, duplicated, out of order, or malformed")
        sample_id = bytes.fromhex(sample["sample_sha256"])
        if sample_id in samples:
            raise check.Failed("A raw monitor sample was reused for multiple slots")
        samples.add(sample_id)
        base = sample["newest_verified_base_unix_s"]
        through = sample["recoverable_through_unix_s"]
        if type(base) is not int or not 0 <= base <= slot:
            raise check.Failed("A slot lacks a verified base timestamp as of its start")
        if through is not None and (type(through) is not int or not base <= through <= slot):
            raise check.Failed("A slot has an invalid recoverable-through timestamp")
        end = min(stop, slot + interval)
        covered += end - slot
        max_age = max(max_age, end - base)
        if through is not None:
            timely += max(0, min(end, through + check.BACKUP_LAG_SECONDS) - slot)
    if stream.read(1):
        raise check.Failed("Monitor export has additional observations outside its exact schedule")
    actual_sha = report_hash.hexdigest()
    if actual_sha != expected_sha:
        raise check.Failed("Monitor export bytes differ from the independently reviewed report digest")
    return {key: header[key] for key in ("identity_sha256", "destination_sha256", "monitor_profile_sha256",
                                        "window_start_unix_s", "window_end_unix_s", "external_observer_verified")} | {
        "report_sha256": actual_sha,
        "covered_duration_s": covered,
        "distinct_scheduled_observations": len(samples),
        "max_snapshot_age_s": max_age,
        "duration_with_lag_at_most_five_minutes_s": timely,
    }


def thresholds_met(summary: dict) -> bool:
    timely = summary["duration_with_lag_at_most_five_minutes_s"]
    return (summary["max_snapshot_age_s"] < check.BACKUP_MAX_AGE_SECONDS
            and 100 * timely >= check.BACKUP_WINDOW_SECONDS * check.BACKUP_LAG_PERCENT)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--policy", type=Path, required=True)
    parser.add_argument("--observations", type=Path, required=True)
    args = parser.parse_args()
    try:
        policy = check.load(args.policy)
        with args.observations.open("rb") as stream:
            summary = summarize(policy, stream, now_unix_s=int(time.time()))
        healthy = thresholds_met(summary)
        result = {"result": "backup-health-summary-ready", "backup_health": summary,
                  "summary_sha256": digest(summary), "thresholds_met": healthy}
    except OSError:
        print(json.dumps({"result": "backup-health-summary-refused", "detail": "Unable to read local evidence"}))
        return 1
    except (check.Missing, check.Failed) as error:
        print(json.dumps({"result": "backup-health-summary-refused", "detail": str(error)}))
        return 1
    print(json.dumps(result, indent=2, sort_keys=True))
    return int(not healthy)


if __name__ == "__main__":
    raise SystemExit(main())
