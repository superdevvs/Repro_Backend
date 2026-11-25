"""Check a reviewed SQLite producer receipt against one nightly parity report."""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
import hashlib
import json
from pathlib import Path
import re

from scripts.cutover import final_backup_check
from scripts.cutover.readiness import check, summarize_nightly_parity


PRODUCER_LIMIT = 64 * 1024
PRIVATE_COPY_FIELDS = {
    "backup_tool_sha256", "completed_utc", "copy_seconds", "crm_migrations", "crm_tables",
    "linked_client_contacts", "release", "result", "snapshot_bytes", "snapshot_path",
    "snapshot_sha256", "source_opened_read_only",
}
PRIVATE_COPY_UTC = re.compile(final_backup_check.UTC_SECONDS + r"(?:\.[0-9]{1,6})?(?:Z|\+00:00)")


def read_capture(path: Path, producer_sha256: str, *, expected_source_release: str | None = None) -> dict:
    try:
        with path.open("rb") as stream:
            raw = stream.read(PRODUCER_LIMIT + 1)
    except FileNotFoundError as error:
        raise check.Missing("The SQLite producer receipt is missing") from error
    except OSError as error:
        raise check.Failed("Unable to read the SQLite producer receipt") from error
    if not 0 < len(raw) <= PRODUCER_LIMIT or hashlib.sha256(raw).hexdigest() != producer_sha256:
        raise check.Failed("SQLite producer receipt differs from the reviewed SHA-256 digest or size limit")
    try:
        producer = json.loads(raw.decode("utf-8"), object_pairs_hook=check.unique_keys,
                              parse_constant=check.reject_constant, parse_float=Decimal)
    except (ValueError, InvalidOperation, RecursionError) as error:
        raise check.Failed("Malformed SQLite producer receipt") from error
    if not isinstance(producer, dict):
        raise check.Failed("Expected a SQLite producer receipt object")

    if producer.get("result") == "read-only-online-backup-pass":
        if set(producer) != PRIVATE_COPY_FIELDS:
            raise check.Failed("Expected the dated private SQLite copy receipt")
        release = producer["release"]
        completed = producer["completed_utc"]
        duration = producer["copy_seconds"]
        if (not isinstance(release, str) or not re.fullmatch(r"[0-9a-f]{40}", release)
                or release != expected_source_release):
            raise check.Failed("Private SQLite producer requires the independently reviewed source release")
        if (producer["source_opened_read_only"] is not True
                or not isinstance(completed, str) or not PRIVATE_COPY_UTC.fullmatch(completed)
                or type(duration) not in (int, Decimal) or not 0 < duration
                or not isinstance(producer["snapshot_path"], str) or not producer["snapshot_path"]):
            raise check.Failed("Invalid private SQLite source, completion time or duration")
        try:
            datetime.fromisoformat(completed)
        except ValueError as error:
            raise check.Failed("Invalid private SQLite completion time") from error
        check.sha_field(producer, "backup_tool_sha256")
        for name in ("snapshot_bytes", "crm_migrations", "crm_tables", "linked_client_contacts"):
            check.number(producer, name, minimum=1)
        return {"captured_at_utc": completed.replace("+00:00", "Z"), "source_release_sha": release,
                "snapshot_sha256": check.sha_field(producer, "snapshot_sha256"),
                "producer_receipt_sha256": producer_sha256}

    if expected_source_release is not None:
        raise check.Failed("Only a dated private SQLite producer receipt records its live source release")
    try:
        capture_time = final_backup_check.sqlite_producer_time(producer)
    except (final_backup_check.backup.Refusal, ValueError) as error:
        raise check.Failed("Expected a valid UTC-dated SQLite backup producer receipt") from error
    return {"captured_at_utc": capture_time, "snapshot_sha256": check.sha_field(producer, "sha256"),
            "producer_receipt_sha256": producer_sha256}


def verify(night_path: Path, night_sha256: str, producer_path: Path, producer_sha256: str,
           *, now: datetime, expected_source_release: str | None = None) -> dict:
    night = check.load(night_path, expected_sha256=night_sha256)
    if set(night) != summarize_nightly_parity.NIGHT_FIELDS:
        raise check.Failed("Expected an original nightly parity report")
    day = check.utc_day(night, "scheduled_day_utc")
    snapshot = check.sha_field(night, "snapshot_sha256")
    check.confirmed(night, "fresh_snapshot_verified")

    capture = read_capture(producer_path, producer_sha256, expected_source_release=expected_source_release)
    captured = datetime.fromisoformat(capture["captured_at_utc"])
    if captured.date() != day:
        raise check.Failed("SQLite capture date differs from the scheduled UTC night")
    if captured > now:
        raise check.Failed("SQLite capture time is in the future")
    if capture["snapshot_sha256"] != snapshot:
        raise check.Failed("SQLite producer and nightly report refer to different snapshots")

    return capture | {"result": "nightly-snapshot-capture-verified", "scheduled_day_utc": day.isoformat(),
                      "nightly_report_sha256": night_sha256}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--night", type=Path, required=True)
    parser.add_argument("--night-sha256", required=True)
    parser.add_argument("--producer", type=Path, required=True)
    parser.add_argument("--producer-sha256", required=True)
    parser.add_argument("--expected-source-release", help="deployed source release for a dated private SQLite copy")
    args = parser.parse_args()
    try:
        result = verify(args.night, args.night_sha256, args.producer, args.producer_sha256,
                        now=datetime.now(timezone.utc), expected_source_release=args.expected_source_release)
    except (check.Missing, check.Failed) as error:
        print(json.dumps({"result": "nightly-snapshot-capture-refused", "detail": str(error)}))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
