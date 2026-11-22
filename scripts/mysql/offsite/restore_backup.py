#!/usr/bin/env python3
"""Recover verified backup artifacts to a new private directory."""
from __future__ import annotations

import argparse
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import uuid

# Install both reviewed scripts together in a protected directory. -I excludes
# the script directory, so add this one explicit sibling module location.
sys.path.insert(0, str(Path(__file__).resolve().parent))
import publish_backup as backup


def read_metadata(transport, prefix: str, name: str, size: int, digest: str, limit: int) -> bytes:
    backup.need(0 < size <= limit and backup.HEX.fullmatch(digest) is not None,
                "invalid recovery metadata size or digest")
    received = io.BytesIO()
    transport.readback(prefix + "/" + name, size, digest, received)
    return received.getvalue()


def sync_directory(path: Path) -> None:
    descriptor = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def restore(args, transport) -> dict:
    prefix = f"{args.crypt_remote}:{args.scope}/{args.expected_identity}/{args.backup_id}"
    completion_raw = read_metadata(transport, prefix, "completion.json", args.completion_bytes,
                                   args.completion_sha256, 8 * 1024**2)
    raw = read_metadata(transport, prefix, "input-manifest.json", args.manifest_bytes,
                        args.manifest_sha256, 4 * 1024**2)
    manifest = backup.parse_manifest(raw, args.manifest_sha256, args.scope,
                                     args.expected_release, args.expected_identity)
    completion = json.loads(completion_raw)
    backup.need(isinstance(completion, dict) and completion.get("schema") == backup.COMPLETE_SCHEMA and
                completion.get("status") == "artifact-readback-verified" and
                completion.get("input_manifest_sha256") == args.manifest_sha256 and
                completion.get("input") == manifest and manifest["backup_id"] == args.backup_id,
                "completion record does not match the reviewed manifest")
    backup.transfer_budget(manifest, args.timeout_seconds)

    with tempfile.TemporaryDirectory(prefix=".recovery-", dir=args.output.parent) as scratch:
        staged = Path(scratch)
        for item in manifest["artifacts"]:
            target = staged / "artifacts" / item["path"]
            target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            with target.open("xb") as written:
                transport.readback(prefix + "/artifacts/" + item["path"], item["bytes"], item["sha256"], written)
                written.flush()
                os.fsync(written.fileno())
            backup.need(backup.local_hash(target) == (item["bytes"], item["sha256"]),
                        "recovered artifact hash or length mismatch")
        report = {
            "result": "artifacts-recovered-and-verified",
            "backup_id": manifest["backup_id"], "scope": manifest["scope"],
            "release": manifest["release"], "identity": manifest["identity"],
            "completion_sha256": args.completion_sha256, "input_manifest_sha256": args.manifest_sha256,
            "artifact_count": len(manifest["artifacts"]),
            "artifact_bytes": sum(item["bytes"] for item in manifest["artifacts"]),
            "database_restore_verified": False,
        }
        for name, data in (("completion.json", completion_raw), ("input-manifest.json", raw),
                           ("recovery-receipt.json", backup.encode(report))):
            with (staged / name).open("xb") as output:
                output.write(data)
                output.flush()
                os.fsync(output.fileno())
        for directory, _, _ in os.walk(staged, topdown=False):
            sync_directory(Path(directory))
        # Reserve the name exclusively and then expose the fully checked tree.
        # A crash between these operations can leave only an empty reservation.
        args.output.mkdir(mode=0o700)
        staged.replace(args.output)
        sync_directory(args.output.parent)
    return report


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ("config", "rclone", "output"):
        parser.add_argument("--" + name, type=Path, required=True)
    for name in ("backup-id", "manifest-sha256", "completion-sha256", "scope", "expected-release",
                 "expected-identity", "crypt-remote", "expected-destination"):
        parser.add_argument("--" + name, required=True)
    for name in ("manifest-bytes", "completion-bytes"):
        parser.add_argument("--" + name, type=int, required=True)
    parser.add_argument("--timeout-seconds", type=int, default=3600)
    args = parser.parse_args()
    try:
        os.umask(0o077)
        backup.need(os.name == "posix" and 60 <= args.timeout_seconds <= 21600,
                    "Linux and a bounded recovery timeout are required")
        backup.need(args.scope in ("database", "application") and
                    backup.IDENTITY.fullmatch(args.expected_identity) is not None and
                    str(uuid.UUID(args.backup_id)) == args.backup_id, "invalid recovery target")
        backup.need(args.output.is_absolute() and args.output.resolve() == args.output and
                    not os.path.lexists(args.output), "restore output must be a fresh absolute path")
        backup.private_path(args.output.parent, directory=True)
        backup.private_path(args.config)
        backup.protected_rclone(args.rclone)
        backup.need(args.config.stat().st_size <= 1024**2, "configuration too large")
        config = args.config.read_bytes()
        backup.validate_config(config, args.crypt_remote, args.expected_destination)
        with tempfile.TemporaryDirectory(prefix=".recovery-config-", dir=args.output.parent) as scratch:
            snapshot = Path(scratch) / "rclone.conf"
            snapshot.write_bytes(config)
            report = restore(args, backup.Rclone(args.rclone, snapshot, args.timeout_seconds))
        print(json.dumps(report, sort_keys=True))
        return 0
    except backup.Refusal as error:
        print(json.dumps({"result": "REFUSED", "reason": str(error)}))
        return 1
    except (OSError, ValueError, subprocess.SubprocessError):
        print(json.dumps({"result": "REFUSED", "reason": "recovery I/O, metadata or subprocess failure"}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
