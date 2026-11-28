#!/usr/bin/env python3
"""Check the snapshot age of a verified backup publication using local evidence."""
from __future__ import annotations

import argparse
import datetime as dt
import json
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
import publish_backup as backup


DEFAULT_MAX_AGE_SECONDS = 26 * 60 * 60
RECEIPT_FIELDS = {
    "result", "scope", "backup_id", "release", "identity", "completion_sha256",
    "completion_bytes", "input_manifest_sha256", "input_manifest_bytes", "artifact_count",
    "artifact_bytes", "remote", "restore_verified", "retention_verified",
}


def read_private(path: Path, limit: int) -> bytes:
    backup.private_path(path)
    with path.open("rb") as source:
        data = source.read(limit + 1)
    backup.need(0 < len(data) <= limit, "invalid backup evidence size")
    return data


def check(receipt_raw: bytes, manifest_raw: bytes, *, receipt_sha256: str, scope: str,
          release: str, identity: str, crypt_remote: str, now: dt.datetime,
          max_age_seconds: int = DEFAULT_MAX_AGE_SECONDS) -> dict:
    backup.need(type(max_age_seconds) is int and max_age_seconds > 0, "snapshot age limit must be positive")
    backup.need(now.utcoffset() == dt.timedelta(0), "backup check clock must be UTC")
    backup.need(backup.NAME.fullmatch(crypt_remote) is not None, "invalid crypt remote name")
    backup.need(0 < len(receipt_raw) <= 16 * 1024 and
                backup.HEX.fullmatch(receipt_sha256) is not None and backup.sha256(receipt_raw) == receipt_sha256,
                "publication receipt hash or size mismatch")
    receipt = json.loads(receipt_raw)
    backup.need(isinstance(receipt, dict) and set(receipt) == RECEIPT_FIELDS and
                receipt["result"] == "published-readback-verified" and
                receipt["restore_verified"] is False and receipt["retention_verified"] is False,
                "expected a successful publication readback receipt")
    backup.need(isinstance(receipt["input_manifest_sha256"], str) and
                isinstance(receipt["completion_sha256"], str) and
                backup.HEX.fullmatch(receipt["completion_sha256"]) is not None and
                type(receipt["completion_bytes"]) is int and 0 < receipt["completion_bytes"] <= 8 * 1024**2 and
                type(receipt["input_manifest_bytes"]) is int and receipt["input_manifest_bytes"] == len(manifest_raw),
                "invalid publication evidence metadata")
    manifest = backup.parse_manifest(manifest_raw, receipt["input_manifest_sha256"], scope, release, identity)
    backup.need(all(receipt[field] == manifest[field] for field in ("backup_id", "scope", "release", "identity")) and
                receipt["remote"] == f"{crypt_remote}:{scope}/{identity}/{manifest['backup_id']}",
                "publication and manifest describe different backups")
    backup.need(type(receipt["artifact_count"]) is int and receipt["artifact_count"] == len(manifest["artifacts"]) and
                type(receipt["artifact_bytes"]) is int and
                receipt["artifact_bytes"] == sum(item["bytes"] for item in manifest["artifacts"]),
                "publication artifact counts differ from the manifest")

    created = dt.datetime.strptime(manifest["created_at"], "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=dt.timezone.utc)
    age = (now - created).total_seconds()
    backup.need(age >= 0, "backup snapshot time is in the future")
    return {
        "result": "backup-publication-fresh" if age < max_age_seconds else "backup-publication-stale",
        "backup_id": manifest["backup_id"],
        "snapshot_created_at": manifest["created_at"],
        "snapshot_age_seconds": int(age),
        "max_age_seconds": max_age_seconds,
        "remote_accessed": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ("receipt", "manifest"):
        parser.add_argument("--" + name, type=Path, required=True)
    for name in ("receipt-sha256", "expected-release", "expected-identity", "crypt-remote"):
        parser.add_argument("--" + name, required=True)
    parser.add_argument("--scope", choices=("database", "application"), required=True)
    parser.add_argument("--max-age-seconds", type=int, default=DEFAULT_MAX_AGE_SECONDS)
    args = parser.parse_args()
    try:
        result = check(
            read_private(args.receipt, 16 * 1024), read_private(args.manifest, 4 * 1024**2),
            receipt_sha256=args.receipt_sha256, scope=args.scope, release=args.expected_release,
            identity=args.expected_identity, crypt_remote=args.crypt_remote,
            now=dt.datetime.now(dt.timezone.utc), max_age_seconds=args.max_age_seconds,
        )
    except backup.Refusal as error:
        result = {"result": "backup-publication-invalid", "reason": str(error), "remote_accessed": False}
    except (OSError, ValueError, RecursionError):
        result = {"result": "backup-publication-invalid", "reason": "unreadable or malformed backup evidence",
                  "remote_accessed": False}
    print(json.dumps(result, sort_keys=True))
    return 0 if result["result"] == "backup-publication-fresh" else 1


if __name__ == "__main__":
    raise SystemExit(main())
