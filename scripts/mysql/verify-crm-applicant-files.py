#!/usr/bin/env python3
"""Verify recruiting attachments from a pinned SQLite copy and a staging root.

The supplied private root represents the Laravel local disk (storage/app/private).
Verify from a protected, stable copy while application file writes are excluded.
Use the same private 32-byte key and --expected-manifest-hmac-sha256 on a second
copy to compare all file bytes, including manual uploads without stored checksums.
This Linux verifier emits only counts and the aggregate keyed fingerprint.
"""
from __future__ import annotations

import argparse
from contextlib import ExitStack, closing, contextmanager
import hashlib
import hmac
import importlib.util
import json
import os
from pathlib import Path
import re
import sqlite3
import stat


SPEC = importlib.util.spec_from_file_location("crm_snapshot_audit", Path(__file__).with_name("crm-snapshot-audit.py"))
crm_audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(crm_audit)
schema_drift = crm_audit.schema_drift
need = schema_drift.need

REPORT_SCHEMA = "repro.mysql-crm-applicant-files.audit.v1"
KINDS = frozenset({"resume", "portfolio_sample", "license", "insurance", "application", "other"})
MANUAL_NAME = re.compile(r"[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\..+")
SHA256 = re.compile(r"[a-f0-9]{64}")
SIDECARS = ("-wal", "-shm", "-journal")


def path_components(applicant, kind, relative, checksum):
    need(type(applicant) is int and applicant > 0 and isinstance(kind, str) and kind in KINDS,
         "invalid-applicant-file-identity")
    need(isinstance(relative, str) and not any(ord(char) < 32 or char in "\\:" for char in relative),
         "invalid-applicant-file-path")
    parts = relative.split("/")
    need(all(part not in {"", ".", ".."} for part in parts) and parts[:2] == ["recruiting", str(applicant)],
         "applicant-file-path-outside-owner-directory")
    if checksum is None:
        need(len(parts) == 3 and MANUAL_NAME.fullmatch(parts[-1]) is not None
             and not parts[-1].endswith(SIDECARS), "invalid-manual-file-path")
    else:
        need(isinstance(checksum, str) and SHA256.fullmatch(checksum) is not None,
             "invalid-intake-checksum")
        need(parts[2:] == ["intake", f"{kind}-{checksum}"], "invalid-intake-file-path")
    return parts


def identity(info):
    return info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid


def file_stamp(info):
    return identity(info), info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns


@contextmanager
def directories(start, components):
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    with ExitStack() as stack:
        current = start
        opened = []
        for component in components:
            child = os.open(component, flags, dir_fd=current)
            stack.callback(os.close, child)
            opened.append((current, component, child, identity(os.fstat(child))))
            current = child
        yield current
        for parent, name, child, initial in reversed(opened):
            need(initial == identity(os.fstat(child)) == identity(os.stat(name, dir_fd=parent, follow_symlinks=False)),
                 "applicant-file-directory-changed")


def verified_digest(root, parts, expected_size):
    with directories(root, parts[:-1]) as folder:
        name = parts[-1]
        flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
        with os.fdopen(os.open(name, flags, dir_fd=folder), "rb") as stream:
            entry = os.fstat(stream.fileno())
            need(stat.S_ISREG(entry.st_mode) and entry.st_nlink == 1 and entry.st_mode & 0o007 == 0,
                 "applicant-file-must-be-private-regular-and-single-link")
            need(entry.st_size == expected_size, "applicant-file-size-differs")
            digest = hashlib.sha256()
            remaining = expected_size
            while remaining:
                data = stream.read(min(remaining, 1024 * 1024))
                need(bool(data), "applicant-file-size-differs")
                digest.update(data)
                remaining -= len(data)
            need(not stream.read(1) and file_stamp(entry) == file_stamp(os.fstat(stream.fileno()))
                 == file_stamp(os.stat(name, dir_fd=folder, follow_symlinks=False)), "applicant-file-changed")
            return digest.hexdigest()


def audit(snapshot, snapshot_sha256, private_root, key):
    need(os.open in os.supports_dir_fd and os.stat in os.supports_dir_fd and hasattr(os, "O_NOFOLLOW"),
         "relative-no-follow-file-checks-unavailable")
    need(private_root.is_absolute() and private_root.anchor == "/" and private_root.resolve(strict=True) == private_root,
         "canonical-private-disk-root-required")
    schema_drift.checked_file(snapshot, snapshot_sha256)
    manifest = hmac.new(key, b"repro.crm.applicant-files.manifest.v1\n", hashlib.sha256)
    counts = {"files": 0, "bytes_verified": 0, "intake_checksums_verified": 0, "manual_files_hashed": 0}
    anchor = os.open(private_root.anchor, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with ExitStack() as stack:
        stack.callback(os.close, anchor)
        root = stack.enter_context(directories(anchor, private_root.parts[1:]))
        need(os.fstat(root).st_mode & 0o007 == 0, "private-disk-root-access-must-be-restricted")
        db = stack.enter_context(closing(sqlite3.connect(snapshot.as_uri() + "?mode=ro&immutable=1", uri=True)))
        db.execute("PRAGMA query_only=ON")
        db.execute("PRAGMA trusted_schema=OFF")
        table = db.execute("SELECT type FROM pragma_table_list WHERE schema='main' AND name='recruit_applicant_files'").fetchone()
        need(table == ("table",), "applicant-file-table-required")
        rows = db.execute("SELECT id, applicant_id, kind, path, size, intake_checksum FROM recruit_applicant_files ORDER BY id")
        previous_id = 0
        for row_id, applicant, kind, relative, size, stored in rows:
            need(type(row_id) is int and row_id > previous_id and type(size) is int and size >= 0,
                 "invalid-applicant-file-metadata")
            previous_id = row_id
            actual = verified_digest(root, path_components(applicant, kind, relative, stored), size)
            if stored is not None:
                need(hmac.compare_digest(stored, actual), "applicant-file-checksum-differs")
            record = hmac.new(key, b"repro.crm.applicant-file.record.v1\n", hashlib.sha256)
            crm_audit.add_digest(record, (row_id, applicant, kind, relative, size, stored, actual))
            manifest.update(record.digest())
            counts["files"] += 1
            counts["bytes_verified"] += size
            counts["manual_files_hashed" if stored is None else "intake_checksums_verified"] += 1
    schema_drift.checked_file(snapshot, snapshot_sha256)
    return counts | {"manifest_hmac_sha256": manifest.hexdigest()}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--snapshot", required=True, type=Path)
    parser.add_argument("--snapshot-sha256", required=True)
    parser.add_argument("--private-root", required=True, type=Path, help="staged root of the Laravel local disk")
    parser.add_argument("--key-file", required=True, type=Path)
    parser.add_argument("--expected-manifest-hmac-sha256")
    args = parser.parse_args()
    try:
        expected = args.expected_manifest_hmac_sha256
        need(expected is None or SHA256.fullmatch(expected) is not None, "invalid-expected-manifest-hmac")
        result = audit(args.snapshot, args.snapshot_sha256, args.private_root, crm_audit.read_key(args.key_file))
        if expected is not None:
            need(hmac.compare_digest(result["manifest_hmac_sha256"], expected), "applicant-file-manifest-differs")
        report = {"schema": REPORT_SCHEMA, "result": "verified", "disk": "local", "snapshot_sha256": args.snapshot_sha256,
                  "expected_manifest_verified": expected is not None, **result}
    except schema_drift.Refusal as error:
        report = {"schema": REPORT_SCHEMA, "result": "refused", "reason": str(error)}
    except (OSError, RuntimeError, sqlite3.Error, UnicodeError):
        report = {"schema": REPORT_SCHEMA, "result": "refused", "reason": "applicant-file-input-unsafe-or-unreadable"}
    print(json.dumps(report, sort_keys=True))
    return 0 if report["result"] == "verified" else 1


if __name__ == "__main__":
    raise SystemExit(main())
