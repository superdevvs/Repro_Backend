#!/usr/bin/env python3
"""Review pinned Cloudflare R2 retention evidence locally.

An operator captures successful bucket-lock and object-lifecycle GET response
bodies for the approved account and bucket, plus single-object S3 observations
from a separately authorized synthetic sentinel. Match the exact visible R2
prefix before rclone name encryption. Keep the capture and its independently
recorded SHA-256. Supply the reviewed account, bucket, key, content hash,
credential fingerprints and durations independently of the capture. This tool
has no transport or secrets.
"""
from __future__ import annotations

import argparse
from dataclasses import dataclass
import datetime as dt
import json
from pathlib import Path
import re
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
import publish_backup as backup


SCHEMA = "repro.r2.retention-evidence.v1"
LIMIT = 2 * 1024**2
ACCOUNT = re.compile(r"[0-9a-f]{32}")
BUCKET = re.compile(r"[a-z0-9][a-z0-9-]{1,61}[a-z0-9]")
LOCKED = "ObjectLockedByBucketPolicy"


@dataclass(frozen=True)
class Expected:
    account: str
    bucket: str
    prefix: str
    sentinel: str
    content_sha256: str
    lock_seconds: int
    expiry_seconds: int
    publisher_fingerprint: str
    lock_probe_fingerprint: str


def records(pairs):
    result = dict(pairs)
    backup.need(len(result) == len(pairs), "duplicate retention evidence keys")
    return result


def utc(value: str) -> dt.datetime:
    backup.need(isinstance(value, str) and re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T"
                                                       r"[0-9]{2}:[0-9]{2}:[0-9]{2}Z", value) is not None,
                "retention evidence requires a UTC timestamp")
    return dt.datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=dt.timezone.utc)


def enabled_rules(export: dict):
    backup.need(isinstance(export, dict) and export.get("success") is True and export.get("errors", []) == []
                and isinstance(export.get("result"), dict), "unsuccessful Cloudflare rules export")
    rules = export["result"].get("rules", [])
    backup.need(isinstance(rules, list) and len(rules) <= 1000, "malformed Cloudflare rules export")
    for rule in rules:
        backup.need(isinstance(rule, dict) and type(rule.get("enabled")) is bool, "malformed Cloudflare rule")
        if rule["enabled"]:
            yield rule


def overlaps(left: str, right: str) -> bool:
    return left.startswith(right) or right.startswith(left)


def matching_age_rule(export: dict, prefix: str, *, lifecycle: bool) -> int:
    matching = []
    for rule in enabled_rules(export):
        if lifecycle:
            if "deleteObjectsTransition" not in rule:
                continue  # A multipart abort window is unrelated to object expiry.
            backup.need(isinstance(rule.get("conditions"), dict)
                        and isinstance(rule["deleteObjectsTransition"], dict), "malformed lifecycle expiry rule")
            rule_prefix = rule["conditions"].get("prefix")
            condition = rule["deleteObjectsTransition"].get("condition")
            age_key = "maxAge"
        else:
            rule_prefix = rule.get("prefix", "")
            condition = rule.get("condition")
            age_key = "maxAgeSeconds"
        backup.need(isinstance(rule_prefix, str), "malformed retention rule prefix")
        if overlaps(rule_prefix, prefix):
            backup.need(rule_prefix == prefix and isinstance(condition, dict)
                        and condition.get("type") == "Age"
                        and type(condition.get(age_key)) is int and condition[age_key] > 0,
                        "retention rule scope or age differs from the reviewed policy")
            matching.append(condition[age_key])
    backup.need(len(matching) == 1, "expected one exact enabled rule for the reviewed prefix")
    return matching[0]


def verify_probe(sentinel: dict, expected: Expected, observed: dt.datetime) -> None:
    backup.need(isinstance(sentinel, dict) and set(sentinel) ==
                {"key", "created_utc", "publisher_fingerprint", "lock_probe_fingerprint", "operations"}
                and sentinel["key"] == expected.sentinel, "synthetic sentinel binding mismatch")
    created = utc(sentinel["created_utc"])
    backup.need(0 <= (observed - created).total_seconds() < expected.lock_seconds,
                "synthetic probe occurred outside the approved lock window")
    writer, probe = expected.publisher_fingerprint, expected.lock_probe_fingerprint
    backup.need((sentinel["publisher_fingerprint"], sentinel["lock_probe_fingerprint"]) == (writer, probe),
                "synthetic S3 credential fingerprints differ from the reviewed bindings")
    required = {
        "create": (writer, "PutObject", 200, None, None),
        "read_before": (writer, "GetObject", 200, None, expected.content_sha256),
        "overwrite": (writer, "PutObject", 409, LOCKED, None),
        "publisher_delete": (writer, "DeleteObject", 403, "AccessDenied", None),
        "lock_probe_delete": (probe, "DeleteObject", 409, LOCKED, None),
        "read_after": (writer, "GetObject", 200, None, expected.content_sha256),
    }
    observed_operations = sentinel["operations"]
    backup.need(isinstance(observed_operations, dict) and set(observed_operations) == set(required),
                "incomplete synthetic S3 observations")
    for name, (credential, method, status, code, digest) in required.items():
        observation = observed_operations[name]
        backup.need(isinstance(observation, dict) and type(observation.get("http_status")) is int and observation == {
            "key": expected.sentinel, "credential_fingerprint": credential, "operation": method,
            "http_status": status, "s3_code": code, "content_sha256": digest,
        }, "synthetic S3 observation differs from the reviewed expectation: " + name)


def verify(raw: bytes, *, evidence_sha256: str, expected: Expected, now: dt.datetime) -> dict:
    backup.need(ACCOUNT.fullmatch(expected.account) is not None and BUCKET.fullmatch(expected.bucket) is not None
                and expected.prefix.endswith("/") and backup.relative_path(expected.prefix[:-1])
                and backup.relative_path(expected.sentinel) and expected.sentinel.startswith(expected.prefix)
                and len(expected.sentinel) > len(expected.prefix) and backup.HEX.fullmatch(expected.content_sha256)
                and type(expected.lock_seconds) is int and type(expected.expiry_seconds) is int
                and 0 < expected.lock_seconds <= expected.expiry_seconds, "invalid reviewed retention policy")
    fingerprints = (expected.publisher_fingerprint, expected.lock_probe_fingerprint)
    backup.need(all(isinstance(value, str) and backup.HEX.fullmatch(value) is not None for value in fingerprints)
                and fingerprints[0] != fingerprints[1], "expected distinct reviewed credential fingerprints")
    backup.need(now.utcoffset() == dt.timedelta(0), "retention evidence review clock must be UTC")
    backup.need(0 < len(raw) <= LIMIT and backup.sha256(raw) == evidence_sha256, "retention evidence pin mismatch")
    observed = json.loads(raw, object_pairs_hook=records)
    backup.need(isinstance(observed, dict) and set(observed) == {
        "schema", "account", "bucket", "prefix", "observed_utc", "locks", "lifecycle", "sentinel",
    } and observed["schema"] == SCHEMA and
        (observed["account"], observed["bucket"], observed["prefix"]) ==
        (expected.account, expected.bucket, expected.prefix), "retention evidence target mismatch")
    timestamp = utc(observed["observed_utc"])
    backup.need(timestamp <= now, "retention evidence time is in the future")
    lock = matching_age_rule(observed["locks"], expected.prefix, lifecycle=False)
    expiry = matching_age_rule(observed["lifecycle"], expected.prefix, lifecycle=True)
    backup.need(lock == expected.lock_seconds and expiry == expected.expiry_seconds,
                "observed lock or expiry duration differs from the reviewed policy")
    verify_probe(observed["sentinel"], expected, timestamp)
    return {"result": "r2-retention-evidence-matched", "observed_utc": observed["observed_utc"],
            "lock_seconds": lock, "expiry_seconds": expiry, "evidence_sha256": evidence_sha256,
            "remote_accessed": False, "live_probe_run": False}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--evidence", type=Path, required=True)
    for argument in ("evidence-sha256", "expected-account", "expected-bucket", "expected-prefix",
                     "expected-sentinel", "sentinel-sha256", "expected-publisher-fingerprint",
                     "expected-lock-probe-fingerprint"):
        parser.add_argument("--" + argument, required=True)
    for argument in ("lock-seconds", "expiry-seconds"):
        parser.add_argument("--" + argument, type=int, required=True)
    args = parser.parse_args()
    try:
        expected = Expected(args.expected_account, args.expected_bucket, args.expected_prefix,
                            args.expected_sentinel, args.sentinel_sha256, args.lock_seconds, args.expiry_seconds,
                            args.expected_publisher_fingerprint, args.expected_lock_probe_fingerprint)
        backup.private_path(args.evidence)
        with args.evidence.open("rb") as source:
            raw = source.read(LIMIT + 1)
        result = verify(raw, evidence_sha256=args.evidence_sha256, expected=expected,
                        now=dt.datetime.now(dt.timezone.utc))
    except backup.Refusal as error:
        result = {"result": "r2-retention-evidence-refused", "reason": str(error), "remote_accessed": False}
    except (OSError, ValueError, TypeError, RecursionError):
        result = {"result": "r2-retention-evidence-refused", "reason": "unreadable or malformed retention evidence",
                  "remote_accessed": False}
    print(json.dumps(result, sort_keys=True))
    return 0 if result["result"] == "r2-retention-evidence-matched" else 1


if __name__ == "__main__":
    raise SystemExit(main())
