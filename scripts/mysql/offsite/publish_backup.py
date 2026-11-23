#!/usr/bin/env python3
"""Publish closed backup artifacts through an explicitly supplied rclone crypt remote.

No snapshot creation, bucket configuration, remote deletion, or recovery claims.
Linux is required for the production permission/locking checks. Tests use fixtures.
"""
from __future__ import annotations

import argparse
import configparser
import datetime as dt
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import tempfile
import threading
from typing import BinaryIO
import uuid

INPUT_SCHEMA = "repro.offsite.input.v1"
COMPLETE_SCHEMA = "repro.offsite.complete.v1"
SAFE_ENV = {"PATH": "/usr/bin:/bin", "LANG": "C", "LC_ALL": "C", "TZ": "UTC"}
HEX = re.compile(r"[0-9a-f]{64}")
NAME = re.compile(r"[a-z][a-z0-9_-]{0,63}")
IDENTITY = re.compile(r"[a-z0-9][a-z0-9._-]{0,127}")
BANDWIDTH = 16 * 1024**2


class Refusal(RuntimeError):
    pass


def need(condition: bool, reason: str) -> None:
    if not condition:
        raise Refusal(reason)


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def encode(value: dict) -> bytes:
    return (json.dumps(value, sort_keys=True, indent=2) + "\n").encode("utf-8")


def relative_path(value: str) -> str:
    need(isinstance(value, str) and 0 < len(value) <= 512, "invalid artifact path")
    path = PurePosixPath(value)
    need(not path.is_absolute() and path.as_posix() == value and
         all(re.fullmatch(r"[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}", part)
             and part not in (".", "..") for part in path.parts), "unsafe artifact path")
    return value


def parse_manifest(raw: bytes, expected_sha256: str, scope: str,
                   release: str, identity: str) -> dict:
    need(len(raw) <= 4 * 1024**2 and HEX.fullmatch(expected_sha256) is not None,
         "invalid input manifest size/hash")
    need(sha256(raw) == expected_sha256, "input manifest hash mismatch")
    try:
        value = json.loads(raw)
        need(set(value) == {"schema", "backup_id", "created_at", "scope", "release",
                            "identity", "artifacts"}, "unexpected input manifest fields")
        need(value["schema"] == INPUT_SCHEMA, "wrong input manifest schema")
        need(str(uuid.UUID(value["backup_id"])) == value["backup_id"], "invalid backup UUID")
        need(re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", value["created_at"])
             is not None, "created_at must be UTC seconds")
        dt.datetime.strptime(value["created_at"], "%Y-%m-%dT%H:%M:%SZ")
        need(scope in ("application", "database") and value["scope"] == scope, "backup scope mismatch")
        need(re.fullmatch(r"[0-9a-f]{40}|[0-9a-f]{64}", release) is not None and
             value["release"] == release, "release mismatch")
        need(IDENTITY.fullmatch(identity) is not None and value["identity"] == identity,
             "instance identity mismatch")
        items = value["artifacts"]
        need(isinstance(items, list) and 0 < len(items) <= 10000, "invalid artifact list")
        paths = set()
        for item in items:
            need(set(item) == {"path", "bytes", "sha256"}, "unexpected artifact fields")
            path = relative_path(item["path"])
            need(path not in paths, "duplicate artifact path")
            paths.add(path)
            need(type(item["bytes"]) is int and 0 <= item["bytes"] <= 10 * 1024**4,
                 "invalid artifact size")
            need(isinstance(item["sha256"], str) and HEX.fullmatch(item["sha256"]) is not None,
                 "invalid artifact hash")
        need(not any(str(parent) in paths for path in paths
                     for parent in PurePosixPath(path).parents),
             "artifact file/directory collision")
        return value
    except (KeyError, TypeError, ValueError, AttributeError):
        raise Refusal("malformed input manifest") from None


def sync_directory(path: Path) -> None:
    descriptor = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def write_input_manifest(root: Path, manifest: dict) -> tuple[str, int]:
    raw = encode(manifest)
    digest = sha256(raw)
    parse_manifest(raw, digest, manifest["scope"], manifest["release"], manifest["identity"])
    with tempfile.NamedTemporaryFile(dir=root, prefix=".input-manifest-", delete=False) as stream:
        staged = Path(stream.name)
        try:
            os.fchmod(stream.fileno(), 0o600)
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
            os.link(staged, root / "input-manifest.json", follow_symlinks=False)
        finally:
            staged.unlink()
    sync_directory(root)
    return digest, len(raw)


def private_path(path: Path, *, directory: bool = False) -> None:
    need(os.name == "posix" and path.is_absolute(), "Linux absolute paths required")
    owner = os.geteuid()
    for entry in reversed((path, *path.parents)):
        info = entry.lstat()
        need(not stat.S_ISLNK(info.st_mode) and info.st_uid in (0, owner) and
             not info.st_mode & 0o022, "unsafe path owner, symlink or writable ancestor")
    info = path.stat()
    need(info.st_uid == owner, "private input must belong to executing backup account")
    need(stat.S_IMODE(info.st_mode) == (0o700 if directory else 0o600),
         "private directory/file must have mode 0700/0600")
    need(stat.S_ISDIR(info.st_mode) if directory else
         stat.S_ISREG(info.st_mode) and info.st_nlink == 1, "invalid private input type/link count")


def validate_config(raw: bytes, remote: str, expected_destination: str) -> None:
    """Support one named crypt -> named S3 remote, never local/alias/ambient auth."""
    need(NAME.fullmatch(remote) is not None, "invalid crypt remote name")
    need(len(raw) <= 1024**2, "rclone configuration too large")
    try:
        cfg = configparser.ConfigParser(interpolation=None, strict=True)
        cfg.read_string(raw.decode("utf-8"))
        crypt = cfg[remote]
        need(not cfg.defaults(), "rclone DEFAULT overrides refused")
        need(crypt.get("type") == "crypt" and crypt.get("remote") == expected_destination,
             "crypt remote/destination mismatch")
        need(crypt.get("filename_encryption", "standard") == "standard" and
             crypt.get("directory_name_encryption", "true").lower() == "true" and
             crypt.get("password", "") and crypt.get("password2", ""),
             "content/name encryption and both crypt secrets are required")
        need(crypt.get("no_data_encryption", "false").lower() == "false",
             "unencrypted crypt content refused")
        allowed = {"type", "remote", "filename_encryption", "directory_name_encryption",
                   "password", "password2", "no_data_encryption"}
        need(set(crypt) <= allowed, "unreviewed crypt configuration option")
        backend, path = expected_destination.split(":", 1)
        need(NAME.fullmatch(backend) is not None and backend != remote, "invalid storage remote")
        relative_path(path)
        need("/" in path, "dedicated bucket/prefix required")
        store = cfg[backend]
        need(store.get("type") == "s3" and store.get("env_auth", "false").lower() == "false",
             "only explicit S3 credentials are supported")
        need(store.get("access_key_id", "") and store.get("secret_access_key", "") and
             store.get("no_check_bucket", "false").lower() == "true",
             "explicit scoped credentials and no_check_bucket=true required")
        allowed_s3 = {"type", "provider", "env_auth", "access_key_id", "secret_access_key",
                      "session_token", "region", "endpoint", "location_constraint", "acl", "no_check_bucket"}
        need(set(store) <= allowed_s3 and set(cfg.sections()) == {remote, backend},
             "configuration must contain only the reviewed crypt/S3 pair")
        endpoint = store.get("endpoint", "")
        need(not endpoint or endpoint.startswith("https://"), "HTTPS storage endpoint required")
        if "session_token" in store:
            need(store["session_token"] and not any(char.isspace() for char in store["session_token"]),
                 "S3 session token must be explicit and nonempty")
    except (configparser.Error, KeyError, UnicodeError, ValueError):
        raise Refusal("invalid or unsupported rclone configuration") from None


def local_hash(path: Path) -> tuple[int, str]:
    private_path(path)
    before = path.stat()
    with path.open("rb") as stream:
        value = hashlib.file_digest(stream, "sha256").hexdigest()
    after = path.stat()
    need((before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns) ==
         (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns),
         "artifact changed while hashing")
    return after.st_size, value


def protected_rclone(path: Path) -> None:
    need(path.is_absolute() and path.is_file() and not path.is_symlink(),
         "supply the absolute installed rclone executable")
    for entry in (path, *path.parents):
        info = entry.lstat()
        need(not stat.S_ISLNK(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
             "rclone executable/ancestors must be protected root-owned paths")


def transfer_budget(manifest: dict, timeout: int) -> None:
    # Reserve 30 seconds plus 20% for setup/protocol overhead. Slower networking
    # can still time out; this merely refuses mathematically impossible batches.
    maximum = BANDWIDTH * max(timeout - 30, 0) * 4 // 5
    need(all(item["bytes"] <= maximum for item in manifest["artifacts"]),
         "artifact exceeds size/deadline budget; split closed artifacts or increase timeout")


class Rclone:
    def __init__(self, executable: Path, config: Path, timeout: int):
        self.executable, self.config, self.timeout = executable, config, timeout

    def argv(self, action: str, *args: str) -> list[str]:
        return [str(self.executable), action, *args, "--config", str(self.config),
                "--ask-password=false", "--contimeout", "15s", "--timeout", "60s",
                "--retries", "1", "--low-level-retries", "3", "--stats", "0",
                "--log-level", "ERROR", "--crypt-strict-names", "--bwlimit", "16M"]

    def run(self, action: str, *args: str, absent_ok: bool = False) -> bytes:
        result = subprocess.run(self.argv(action, *args), env=SAFE_ENV, stdin=subprocess.DEVNULL,
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=self.timeout)
        # rclone exit 3 means directory not found; no other error is absence.
        if absent_ok and result.returncode == 3:
            return b"[]"
        need(result.returncode == 0, "rclone operation failed: " + action)
        need(len(result.stdout) <= 4 * 1024**2, "rclone metadata output too large")
        return result.stdout

    def empty(self, prefix: str) -> None:
        listing = json.loads(self.run("lsjson", prefix, "--recursive", absent_ok=True))
        need(isinstance(listing, list) and not listing, "backup prefix already exists; use a new UUID")

    def copy(self, source: Path, destination: str) -> None:
        self.run("copy", str(source), destination, "--immutable", "--no-update-modtime",
                 "--transfers", "1", "--checkers", "1")

    def readback(self, remote: str, expected_size: int, expected_hash: str,
                 sink: BinaryIO | None = None) -> None:
        # Plaintext streams only to the hash loop and an optional private restore
        # file. A deadline kills a stalled child even while read() is blocked.
        process = subprocess.Popen(self.argv("cat", remote), env=SAFE_ENV,
                                   stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                   stderr=subprocess.DEVNULL)
        timer = threading.Timer(self.timeout, process.kill)
        timer.daemon = True
        timer.start()
        count, digest = 0, hashlib.sha256()
        try:
            while True:
                chunk = process.stdout.read(min(1024**2, expected_size + 1 - count))
                if not chunk:
                    break
                count += len(chunk)
                need(count <= expected_size, "decrypted remote artifact exceeds expected size")
                digest.update(chunk)
                if sink is not None:
                    sink.write(chunk)
            need(process.wait(timeout=10) == 0, "decrypted remote read failed")
            need(count == expected_size and digest.hexdigest() == expected_hash,
                 "decrypted remote artifact hash/length mismatch")
        finally:
            timer.cancel()
            if process.poll() is None:
                process.kill()
            process.wait()
            process.stdout.close()


def publish(manifest: dict, raw: bytes, root: Path, remote: str,
            transport: Rclone, scratch: Path) -> dict:
    prefix = f"{remote}:{manifest['scope']}/{manifest['identity']}/{manifest['backup_id']}"
    transport.empty(prefix)
    # All local files are verified before the first remote write.
    for item in manifest["artifacts"]:
        need(local_hash(root / item["path"]) == (item["bytes"], item["sha256"]),
             "local artifact hash/length mismatch")
    for item in manifest["artifacts"]:
        path = PurePosixPath(item["path"])
        parent = "" if str(path.parent) == "." else "/" + str(path.parent)
        transport.copy(root / item["path"], prefix + "/artifacts" + parent)
        transport.readback(prefix + "/artifacts/" + item["path"], item["bytes"], item["sha256"])
        need(local_hash(root / item["path"]) == (item["bytes"], item["sha256"]),
             "local artifact changed during publication")
    input_file = scratch / "input-manifest.json"
    input_file.write_bytes(raw)
    input_file.chmod(0o600)
    transport.copy(input_file, prefix)
    transport.readback(prefix + "/input-manifest.json", len(raw), sha256(raw))
    complete = {"schema": COMPLETE_SCHEMA, "status": "artifact-readback-verified",
                "input_manifest_sha256": sha256(raw), "input": manifest,
                "verified_at": dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
                "proof_limits": ["not a database consistency/restore proof",
                                 "not a bucket retention/immutability proof",
                                 "not a binlog continuity/PITR proof"]}
    completion_raw = encode(complete)
    completion = scratch / "completion.json"
    completion.write_bytes(completion_raw)
    completion.chmod(0o600)
    transport.copy(completion, prefix)  # The final remote write, after every readback.
    transport.readback(prefix + "/completion.json", len(completion_raw), sha256(completion_raw))
    return {"result": "published-readback-verified", "scope": manifest["scope"],
            "backup_id": manifest["backup_id"], "release": manifest["release"],
            "identity": manifest["identity"], "completion_sha256": sha256(completion_raw),
            "completion_bytes": len(completion_raw), "input_manifest_sha256": sha256(raw),
            "input_manifest_bytes": len(raw), "artifact_count": len(manifest["artifacts"]),
            "artifact_bytes": sum(item["bytes"] for item in manifest["artifacts"]),
            "remote": prefix, "restore_verified": False, "retention_verified": False}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ("manifest", "source", "config", "rclone", "workdir"):
        parser.add_argument("--" + name, type=Path, required=True)
    for name in ("manifest-sha256", "scope", "expected-release", "expected-identity",
                 "crypt-remote", "expected-destination"):
        parser.add_argument("--" + name, required=True)
    parser.add_argument("--timeout-seconds", type=int, default=3600)
    parser.add_argument("--check-local-only", action="store_true",
                        help="validate local inputs only; no rclone process or remote access")
    args = parser.parse_args()
    try:
        need(os.name == "posix", "production publisher requires Linux")
        need(60 <= args.timeout_seconds <= 21600, "timeout must be between 60 and 21600 seconds")
        for path in (args.source, args.workdir):
            private_path(path, directory=True)
        for path in (args.manifest, args.config):
            private_path(path)
        protected_rclone(args.rclone)
        need(args.manifest.stat().st_size <= 4 * 1024**2 and args.config.stat().st_size <= 1024**2,
             "manifest/configuration too large")
        raw = args.manifest.read_bytes()
        manifest = parse_manifest(raw, args.manifest_sha256, args.scope,
                                  args.expected_release, args.expected_identity)
        transfer_budget(manifest, args.timeout_seconds)
        config = args.config.read_bytes()
        validate_config(config, args.crypt_remote, args.expected_destination)
        if args.check_local_only:
            for item in manifest["artifacts"]:
                need(local_hash(args.source / item["path"]) == (item["bytes"], item["sha256"]),
                     "local artifact hash/length mismatch")
            print(json.dumps({"result": "local-inputs-verified", "remote_accessed": False,
                              "backup_id": manifest["backup_id"], "scope": manifest["scope"]}))
            return 0
        import fcntl
        # One publisher per protected workdir; an attempted UUID is never reused.
        lock = os.open(args.workdir / ".publish.lock", os.O_CREAT | os.O_NOFOLLOW | os.O_RDWR, 0o600)
        with os.fdopen(lock, "w") as held:
            fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
            attempt = args.workdir / (manifest["backup_id"] + ".attempt.json")
            with attempt.open("x") as stream:
                os.chmod(attempt, 0o600)
                json.dump({"input_manifest_sha256": sha256(raw), "backup_id": manifest["backup_id"]}, stream)
                stream.flush()
                os.fsync(stream.fileno())
            with tempfile.TemporaryDirectory(prefix="publish-", dir=args.workdir) as temporary:
                scratch = Path(temporary)
                snapshot = scratch / "rclone.conf"
                snapshot.write_bytes(config)
                snapshot.chmod(0o600)
                transport = Rclone(args.rclone, snapshot, args.timeout_seconds)
                report = publish(manifest, raw, args.source, args.crypt_remote, transport, scratch)
            receipt = args.workdir / (manifest["backup_id"] + ".receipt.json")
            with receipt.open("x") as stream:
                os.chmod(receipt, 0o600)
                stream.write(encode(report).decode("utf-8"))
                stream.flush()
                os.fsync(stream.fileno())
            print(json.dumps(report, sort_keys=True))
        return 0
    except Refusal as error:
        print(json.dumps({"result": "REFUSED", "reason": str(error)}))
        return 1
    except (OSError, ValueError, subprocess.SubprocessError):
        # Never expose config, provider responses, decrypted data or stderr.
        print(json.dumps({"result": "REFUSED", "reason": "local I/O, metadata, locking or subprocess failure"}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
