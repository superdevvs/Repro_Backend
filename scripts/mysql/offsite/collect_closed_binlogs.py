#!/usr/bin/env python3
"""Collect a bounded prefix of already-closed local MySQL binary logs."""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
import advance_binlog_checkpoint as checkpoint
import prepare_closed_binlogs as staging
import publish_backup as backup


PLAN_LIMIT = 16 * 1024
CATALOG_LIMIT = 4 * 1024**2
PLAN_FIELDS = {
    "version", "release", "identity", "database", "server_id", "crypt_remote", "checkpoint_dir", "base_receipt",
    "base_receipt_sha256", "spool_dir", "mysql", "defaults_file", "socket", "binlog_base", "mysql_owner_uid",
    "server_version", "max_files", "max_batch_bytes", "query_timeout_seconds",
}
PATH_FIELDS = ("checkpoint_dir", "base_receipt", "spool_dir", "mysql", "defaults_file", "socket", "binlog_base")
IDENTITY_SQL = ("SELECT 'archive-source',VERSION(),@@server_uuid,@@server_id,@@global.log_bin,"
                "@@global.skip_networking,@@global.binlog_checksum,@@global.binlog_encryption,@@global.log_bin_basename;")
CATALOG_SQL = IDENTITY_SQL + "SHOW BINARY LOG STATUS;SHOW BINARY LOGS;SHOW BINARY LOG STATUS;" + IDENTITY_SQL


def parse_plan(raw: bytes, expected_sha256: str) -> dict:
    backup.need(0 < len(raw) <= PLAN_LIMIT and backup.sha256(raw) == expected_sha256, "collector plan pin or size differs")
    plan = json.loads(raw)
    backup.need(isinstance(plan, dict) and set(plan) == PLAN_FIELDS and type(plan["version"]) is int
                and plan["version"] == 1, "unexpected collector plan fields")
    for field, pattern in (
        ("release", r"[0-9a-f]{40}|[0-9a-f]{64}"), ("identity", staging.chain.base_backup.UUID),
        ("database", staging.chain.base_backup.DB_NAME), ("crypt_remote", backup.NAME),
        ("base_receipt_sha256", backup.HEX), ("server_version", r"8\.4\.[0-9]+"),
    ):
        backup.need(isinstance(plan[field], str) and re.fullmatch(pattern, plan[field]) is not None,
                    "invalid pinned collector plan: " + field)
    for field, low, high in (
        ("server_id", 1, staging.chain.MAX_POSITION), ("mysql_owner_uid", 1, 2**32 - 2),
        ("max_files", 1, 64), ("max_batch_bytes", 1, 16 * 1024**3), ("query_timeout_seconds", 3, 60),
    ):
        backup.need(type(plan[field]) is int and low <= plan[field] <= high, "invalid collector plan bound: " + field)
    for field in PATH_FIELDS:
        value = plan[field]
        backup.need(isinstance(value, str) and len(value) <= 4096 and re.fullmatch(r"/[A-Za-z0-9_./-]+", value)
                    and Path(value).as_posix() == value and ".." not in Path(value).parts, "invalid collector plan path: " + field)
    backup.need(plan["binlog_base"] != plan["socket"], "collector socket and byte source must differ")
    return plan


def read_plan(path: Path, expected_sha256: str) -> dict:
    from scheduled_backup import trusted_file

    trusted_file(path, plan=True)
    with path.open("rb") as stream:
        return parse_plan(stream.read(PLAN_LIMIT + 1), expected_sha256)


def protected_source(path: Path, uid: int, *, socket: bool = False):
    for directory in reversed(path.parents):
        entry = directory.lstat()
        backup.need(stat.S_ISDIR(entry.st_mode) and entry.st_uid in (0, uid) and not entry.st_mode & 0o022,
                    "unsafe local MySQL source path")
    entry = path.lstat()
    backup.need(entry.st_uid == uid and (stat.S_ISSOCK(entry.st_mode) if socket else
                stat.S_ISDIR(entry.st_mode) and not entry.st_mode & 0o022), "unexpected local MySQL source type or owner")
    return entry


def stat_identity(info):
    return info.st_dev, info.st_ino, info.st_uid, info.st_mode


def file_stamp(info):
    return (*stat_identity(info), info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def new_private_file(path: Path):
    return os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600), "wb")


def prepare_credentials(plan: dict, path: Path) -> None:
    raw = checkpoint.publication.read_private(Path(plan["defaults_file"]), 4096)
    # Require one credentials-only option file. Protect against includes,
    # duplicate sections, transport overrides and ambient login paths.
    text = raw.decode("utf-8")
    backup.need(re.fullmatch(r"\[client\]\r?\nuser=[A-Za-z0-9_]{1,32}\r?\npassword=[^\r\n\x00]+\r?\n?", text)
                is not None, "collector option file must contain only client user and password")
    with new_private_file(path) as file:
        file.write(raw)


def parse_catalog(raw: bytes, plan: dict) -> tuple[str, list[tuple[str, int]]]:
    backup.need(0 < len(raw) <= CATALOG_LIMIT, "MySQL log inventory exceeds limits")
    lines = [line.split("\t") for line in raw.decode("utf-8").splitlines()]
    identity = ["archive-source", plan["server_version"], plan["identity"], str(plan["server_id"]),
                "1", "1", "CRC32", "0", plan["binlog_base"]]
    backup.need(len(lines) >= 5 and lines[0] == identity and lines[-1] == identity,
                "unexpected socket MySQL source, logging configuration or server identity")
    start, end = lines[1], lines[-2]
    backup.need(len(start) == len(end) == 5 and start[0] == end[0]
                and all(re.fullmatch(r"[0-9]{1,10}", row[1]) is not None
                        and 4 <= int(row[1]) <= staging.chain.MAX_POSITION for row in (start, end)),
                "active MySQL binary log changed while listing")
    active = staging.chain.log_name(start[0])
    entries = []
    next_file = None
    for item in lines[2:-2]:
        backup.need(len(item) == 3 and item[2] == "No" and re.fullmatch(r"[0-9]{1,10}", item[1]) is not None,
                    "unexpected SHOW BINARY LOGS entry")
        name = backup.relative_path(staging.chain.log_name(item[0]))
        backup.need(name.rsplit(".", 1)[0] == Path(plan["binlog_base"]).name and
                    (next_file is None or name == next_file), "MySQL binary log inventory has a gap")
        size = int(item[1])
        backup.need(len(staging.chain.MAGIC) < size <= staging.chain.MAX_POSITION, "invalid MySQL binary log size")
        entries.append((name, size))
        next_file = staging.chain.next_log(name)
    backup.need(entries[-1][0] == active, "MySQL binary log inventory omits the active file")
    return active, entries


def catalog(plan: dict, defaults: Path) -> tuple[str, list[tuple[str, int]]]:
    from scheduled_backup import trusted_file

    client, socket_path = Path(plan["mysql"]), Path(plan["socket"])
    trusted_file(client)
    socket_id = stat_identity(protected_source(socket_path, plan["mysql_owner_uid"], socket=True))
    args = [str(client), "--defaults-file=" + str(defaults), "--no-login-paths", "--protocol=SOCKET",
            "--socket=" + str(socket_path), "--batch", "--raw", "--skip-column-names", "--binary-mode",
            "--skip-reconnect", "--connect-timeout=" + str(plan["query_timeout_seconds"]), "--execute=" + CATALOG_SQL]
    with tempfile.TemporaryFile(dir=defaults.parent) as stdout:
        result = subprocess.run(args, env=backup.SAFE_ENV, stdin=subprocess.DEVNULL, stdout=stdout,
                                stderr=subprocess.DEVNULL, timeout=plan["query_timeout_seconds"], check=False)
        backup.need(result.returncode == 0, "socket-only MySQL binary log listing failed")
        stdout.seek(0)
        raw = stdout.read(CATALOG_LIMIT + 1)
    backup.need(socket_id == stat_identity(protected_source(socket_path, plan["mysql_owner_uid"], socket=True)),
                "local MySQL socket changed during the query")
    return parse_catalog(raw, plan)


def choose_batch(entries: list[tuple[str, int]], active: str, first: str, files: int, max_bytes: int) -> list[tuple[str, int]]:
    backup.need(entries[-1][0] == active, "unexpected active log boundary")
    index = next((index for index, item in enumerate(entries) if item[0] == first), None)
    backup.need(index is not None, "checkpoint log is missing from the local catalog")
    selected, used = [], 0
    for entry in entries[index:-1]:
        if len(selected) >= files or used + entry[1] > max_bytes:
            backup.need(bool(selected), "the first closed file exceeds the reviewed batch limit")
            break
        selected.append(entry)
        used += entry[1]
    return selected


def copy_segment(directory: int, batch: Path, name: str, size: int, owner: int) -> dict:
    fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
    with os.fdopen(fd, "rb") as stream:
        initial = os.fstat(stream.fileno())
        backup.need(stat.S_ISREG(initial.st_mode) and initial.st_uid == owner and initial.st_nlink == 1
                    and not initial.st_mode & 0o022 and initial.st_size == size, "unsafe or changed closed log file")
        digest = hashlib.sha256()
        with new_private_file(batch / name) as output:
            remaining = size
            while remaining:
                data = stream.read(min(remaining, 1024 * 1024))
                backup.need(bool(data), "closed log truncated during collection")
                output.write(data)
                digest.update(data)
                remaining -= len(data)
            backup.need(not stream.read(1) and file_stamp(initial) == file_stamp(os.fstat(stream.fileno()))
                        == file_stamp(os.stat(name, dir_fd=directory, follow_symlinks=False)),
                        "closed log changed during collection")
            output.flush()
            os.fsync(output.fileno())
    return {"file": name, "bytes": size, "sha256": digest.hexdigest()}


def collect(plan: dict, output: Path) -> dict:
    spool = Path(plan["spool_dir"])
    backup.private_path(spool, directory=True)
    backup.need(output.parent == spool and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,127}", output.name)
                and not os.path.lexists(output), "collection output requires a new directory in the reviewed spool")
    state_dir = Path(plan["checkpoint_dir"])
    binding = checkpoint.source(Path(plan["base_receipt"]), digest=plan["base_receipt_sha256"],
                                identity=plan["identity"], database=plan["database"], server_id=plan["server_id"],
                                release=plan["release"], crypt_remote=plan["crypt_remote"])
    first = checkpoint.check(state_dir, binding)["next_file"]
    summary = {"first_file": first, "remote_accessed": False, "archive_publication_verified": False}

    with tempfile.TemporaryDirectory(dir=spool, prefix=".closed-collection-") as temporary:
        scratch = Path(temporary)
        defaults = scratch / "client.cnf"
        prepare_credentials(plan, defaults)
        socket = Path(plan["socket"])
        socket_id = stat_identity(protected_source(socket, plan["mysql_owner_uid"], socket=True))
        active, listed = catalog(plan, defaults)
        selected = choose_batch(listed, active, first, plan["max_files"], plan["max_batch_bytes"])
        if not selected:
            return summary | {"result": "closed-binlog-collection-idle", "active_file": active, "segments": 0}

        batch = scratch / "batch"
        batch.mkdir(mode=0o700)
        binlogs = Path(plan["binlog_base"]).parent
        identity = stat_identity(protected_source(binlogs, plan["mysql_owner_uid"]))
        directory = os.open(binlogs, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            backup.need(identity == stat_identity(os.fstat(directory)), "binary log directory changed before copying")
            original = {name: file_stamp(os.stat(name, dir_fd=directory, follow_symlinks=False)) for name, _ in selected}
            entries = [copy_segment(directory, batch, name, size, plan["mysql_owner_uid"]) for name, size in selected]
            active_now, listed_now = catalog(plan, defaults)
            closed_now = dict(listed_now[:-1])
            backup.need(all(closed_now.get(name) == size and original[name] ==
                            file_stamp(os.stat(name, dir_fd=directory, follow_symlinks=False)) for name, size in selected),
                        "binary log sources changed during collection")
        finally:
            os.close(directory)
        backup.need(identity == stat_identity(protected_source(binlogs, plan["mysql_owner_uid"]))
                    and socket_id == stat_identity(protected_source(socket, plan["mysql_owner_uid"], socket=True))
                    and first == checkpoint.check(state_dir, binding)["next_file"],
                    "binary log sources or the checkpoint changed during collection")
        record = {
            "schema": staging.SCHEMA, "source_uuid": plan["identity"], "server_id": plan["server_id"],
            "sealed_at": dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"), "segments": entries,
            "next_file": staging.chain.next_log(entries[-1]["file"]),
        }
        inventory = backup.encode(record)
        with new_private_file(batch / staging.INVENTORY) as stream:
            stream.write(inventory)
            stream.flush()
            os.fsync(stream.fileno())
        staged = staging.prepare(batch, inventory_sha256=backup.sha256(inventory), release=plan["release"],
                                 identity=plan["identity"], server_id=plan["server_id"], first_file=first)
        # Reserve the final name without overwriting another output, then make
        # the verified directory and its manifest visible together.
        output.mkdir(mode=0o700)
        os.replace(batch, output)
        backup.sync_directory(spool)
        return staged | {"batch_dir": str(output), "active_file": active_now,
                         "collected_bytes": sum(size for _, size in selected)}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plan", type=Path, required=True)
    parser.add_argument("--plan-sha256", required=True)
    parser.add_argument("--output", type=Path, required=True)
    arguments = parser.parse_args()
    os.umask(0o077)
    try:
        result = collect(read_plan(arguments.plan, arguments.plan_sha256), arguments.output)
    except backup.Refusal as error:
        result = {"result": "REFUSED", "reason": str(error), "remote_accessed": False}
    except (OSError, ValueError, TypeError, RecursionError, subprocess.SubprocessError):
        result = {"result": "REFUSED", "reason": "invalid collector input or local I/O failure", "remote_accessed": False}
    print(json.dumps(result, sort_keys=True))
    return int(result["result"] == "REFUSED")


if __name__ == "__main__":
    raise SystemExit(main())
