#!/usr/bin/env python3
"""Verify the exact frozen-cutover base publications using local operator evidence."""
from __future__ import annotations

import argparse
from dataclasses import dataclass
import datetime as dt
import json
from pathlib import Path
import re
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "mysql" / "offsite"))
import check_backup_freshness as freshness
import prepare_mysql_backup as mysql_base
import prepare_sqlite_backup as sqlite_base
import publish_backup as backup


RECEIPT_LIMIT = 16 * 1024
MANIFEST_LIMIT = 4 * 1024**2
PRODUCER_LIMIT = 64 * 1024
SQLITE_FIELDS = {"format_version", "driver", "created_utc", "application_booted", "tables", "file", "bytes", "sha256"}
UTC_SECONDS = r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}"


@dataclass(frozen=True)
class Publication:
    receipt: bytes
    receipt_sha256: str
    manifest: bytes
    producer: bytes
    crypt_remote: str


@dataclass(frozen=True)
class FrozenCutover:
    frozen_at: str
    release: str
    sqlite_identity: str
    sqlite_snapshot_sha256: str
    mysql_uuid: str
    mysql_database: str
    mysql_binlog_file: str
    mysql_binlog_position: int


def unique_json(raw: bytes) -> dict:
    def object_from_pairs(pairs):
        result = {}
        for key, value in pairs:
            backup.need(key not in result, "backup evidence contains duplicate JSON keys")
            result[key] = value
        return result

    result = json.loads(raw, object_pairs_hook=object_from_pairs)
    backup.need(isinstance(result, dict), "backup evidence must be a JSON object")
    return result


def sqlite_producer_time(record: dict) -> str:
    backup.need(set(record) == SQLITE_FIELDS and
                type(record["format_version"]) is int and record["format_version"] == 1 and
                record["driver"] == "sqlite" and record["application_booted"] is False and
                record["file"] == sqlite_base.DATABASE and
                type(record["tables"]) is int and record["tables"] > 0 and
                type(record["bytes"]) is int and record["bytes"] > 0 and
                isinstance(record["sha256"], str) and backup.HEX.fullmatch(record["sha256"]) is not None,
                "unexpected SQLite base producer receipt")
    timestamp = record["created_utc"]
    backup.need(isinstance(timestamp, str) and re.fullmatch(UTC_SECONDS + r"(?:Z|\+00:00)", timestamp) is not None,
                "SQLite producer time must be UTC seconds")
    return dt.datetime.fromisoformat(timestamp).strftime("%Y-%m-%dT%H:%M:%SZ")


def review_publication(publication: Publication, cutover: FrozenCutover, *, engine: str, now: dt.datetime,
                       frozen_at: dt.datetime, max_age_seconds: int) -> dict:
    identity = cutover.sqlite_identity if engine == "sqlite" else cutover.mysql_uuid
    base = sqlite_base if engine == "sqlite" else mysql_base

    # The independent publication pin authenticates the exact manifest bytes,
    # and the manifest authenticates the exact producer receipt and base file.
    backup.need(0 < len(publication.receipt) <= RECEIPT_LIMIT and
                0 < len(publication.manifest) <= MANIFEST_LIMIT and
                0 < len(publication.producer) <= PRODUCER_LIMIT, "invalid frozen-backup evidence size")
    receipt = unique_json(publication.receipt)
    manifest = unique_json(publication.manifest)
    freshness_result = freshness.check(
        publication.receipt, publication.manifest,
        receipt_sha256=publication.receipt_sha256, scope="database", release=cutover.release,
        identity=identity, crypt_remote=publication.crypt_remote, now=now, max_age_seconds=max_age_seconds,
    )
    captured_at = dt.datetime.fromisoformat(freshness_result["snapshot_created_at"])
    backup.need(captured_at >= frozen_at, f"{engine} base was captured before the cutover freeze")

    items = {item["path"]: item for item in manifest["artifacts"]}
    backup.need(set(items) == {base.DATABASE, base.PRODUCER}, f"{engine} base has an unexpected artifact mix")
    producer_pin = backup.sha256(publication.producer)
    backup.need((items[base.PRODUCER]["bytes"], items[base.PRODUCER]["sha256"]) ==
                (len(publication.producer), producer_pin), f"{engine} producer receipt differs from the pinned manifest")
    producer = unique_json(publication.producer)
    producer_time = (
        sqlite_producer_time(producer) if engine == "sqlite"
        else mysql_base.verify_receipt(producer, identity, cutover.mysql_database)
    )
    artifact = items[base.DATABASE]
    backup.need(producer_time == manifest["created_at"] and
                (producer["bytes"], producer["sha256"]) == (artifact["bytes"], artifact["sha256"]),
                f"{engine} producer receipt and manifest describe different base snapshots")

    proof = {
        "backup_id": manifest["backup_id"],
        "identity": identity,
        "snapshot_created_at": producer_time,
        "snapshot_sha256": artifact["sha256"],
        "snapshot_bytes": artifact["bytes"],
        "publication_receipt_sha256": publication.receipt_sha256,
        "manifest_sha256": receipt["input_manifest_sha256"],
        "producer_receipt_sha256": producer_pin,
        "completion_sha256": receipt["completion_sha256"],
        "remote": receipt["remote"],
    }
    if engine == "sqlite":
        backup.need(artifact["sha256"] == cutover.sqlite_snapshot_sha256,
                    "SQLite publication does not contain the exact frozen source snapshot")
    else:
        point = producer["pitr"]
        backup.need((point["binlog_file"], point["binlog_position"]) ==
                    (cutover.mysql_binlog_file, cutover.mysql_binlog_position),
                    "MySQL publication does not match the approved starting binlog position")
        proof.update({"database": producer["database"], "schema_sha256": producer["schema_sha256"], "pitr": point})
    return proof


def verify(cutover: FrozenCutover, sqlite: Publication, mysql: Publication, *, now: dt.datetime) -> dict:
    backup.need(re.fullmatch(UTC_SECONDS + "Z", cutover.frozen_at) is not None,
                "cutover freeze must be supplied as UTC seconds")
    frozen_at = dt.datetime.fromisoformat(cutover.frozen_at)
    backup.need(now.utcoffset() == dt.timedelta(0) and frozen_at <= now, "invalid UTC cutover review window")
    backup.need(re.fullmatch(r"[0-9a-f]{40}", cutover.release) is not None and
                backup.HEX.fullmatch(cutover.sqlite_snapshot_sha256) is not None and
                backup.IDENTITY.fullmatch(cutover.sqlite_identity) is not None and
                mysql_base.UUID.fullmatch(cutover.mysql_uuid) is not None and
                mysql_base.DB_NAME.fullmatch(cutover.mysql_database) is not None and
                len(cutover.mysql_binlog_file) <= 255 and
                re.fullmatch(mysql_base.BINLOG.decode("ascii"), cutover.mysql_binlog_file) is not None and
                type(cutover.mysql_binlog_position) is int and 4 <= cutover.mysql_binlog_position <= 2**63 - 1,
                "invalid independently reviewed cutover release, identity, snapshot or binlog position")

    # A frozen base belongs to this cutover's window. The periodic backup age
    # default need not expire the operator's ability to audit the same proofs.
    window = int((now - frozen_at).total_seconds()) + 1
    source = review_publication(sqlite, cutover, engine="sqlite", now=now, frozen_at=frozen_at, max_age_seconds=window)
    target = review_publication(mysql, cutover, engine="mysql", now=now, frozen_at=frozen_at, max_age_seconds=window)
    backup.need(source["backup_id"] != target["backup_id"], "SQLite and MySQL bases reuse the same backup ID")
    return {
        "version": 1,
        "result": "frozen-base-publications-verified",
        "release": cutover.release,
        "frozen_at": cutover.frozen_at,
        "verified_at": now.strftime("%Y-%m-%dT%H:%M:%SZ"),
        "sqlite": source,
        "mysql": target,
        "remote_accessed": False,
        "restore_verified": False,
        "retention_verified": False,
        "binlog_continuity_verified": False,
    }


def read_publication(args: argparse.Namespace, engine: str) -> Publication:
    return Publication(
        receipt=freshness.read_private(getattr(args, f"{engine}_receipt"), RECEIPT_LIMIT),
        receipt_sha256=getattr(args, f"{engine}_receipt_sha256"),
        manifest=freshness.read_private(getattr(args, f"{engine}_manifest"), MANIFEST_LIMIT),
        producer=freshness.read_private(getattr(args, f"{engine}_producer"), PRODUCER_LIMIT),
        crypt_remote=getattr(args, f"{engine}_crypt_remote"),
    )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for argument in ("frozen-at", "expected-release", "sqlite-identity", "sqlite-snapshot-sha256",
                     "mysql-uuid", "mysql-database", "mysql-binlog-file"):
        parser.add_argument("--" + argument, required=True)
    parser.add_argument("--mysql-binlog-position", type=int, required=True)
    for engine in ("sqlite", "mysql"):
        for part in ("receipt", "manifest", "producer"):
            parser.add_argument(f"--{engine}-{part}", type=Path, required=True)
        parser.add_argument(f"--{engine}-receipt-sha256", required=True)
        parser.add_argument(f"--{engine}-crypt-remote", required=True)
    args = parser.parse_args()
    try:
        cutover = FrozenCutover(
            args.frozen_at, args.expected_release, args.sqlite_identity, args.sqlite_snapshot_sha256,
            args.mysql_uuid, args.mysql_database, args.mysql_binlog_file, args.mysql_binlog_position,
        )
        result = verify(cutover, read_publication(args, "sqlite"), read_publication(args, "mysql"),
                        now=dt.datetime.now(dt.timezone.utc))
    except backup.Refusal as error:
        result = {"result": "frozen-base-publications-refused", "reason": str(error), "remote_accessed": False}
    except (OSError, ValueError, TypeError, RecursionError):
        result = {"result": "frozen-base-publications-refused",
                  "reason": "unreadable or malformed frozen-backup evidence", "remote_accessed": False}
    print(json.dumps(result, sort_keys=True))
    return 0 if result["result"] == "frozen-base-publications-verified" else 1


if __name__ == "__main__":
    raise SystemExit(main())
