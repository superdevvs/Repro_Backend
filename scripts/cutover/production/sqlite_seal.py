"""Seal the production SQLite file against writes during the freeze.

The seal checkpoints and truncates the WAL, records the exact modes of the
database and its -wal/-shm files, then removes every write bit. sqlite3,
tinker and stray scripts running as the deploy or web user then get
SQLITE_READONLY. Root ignores file modes, so root-run tools are fenced only
by the runbook. unseal restores the recorded modes exactly.
"""

from __future__ import annotations

import json
import os
import sqlite3
import stat
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from runner import UnsafeCutover  # noqa: E402

WRITE_BITS = stat.S_IWUSR | stat.S_IWGRP | stat.S_IWOTH


def companions(database: Path) -> list[Path]:
    return [database, Path(f"{database}-wal"), Path(f"{database}-shm"), Path(f"{database}-journal")]


def writable(database: Path) -> bool:
    return any(path.exists() and path.stat().st_mode & WRITE_BITS for path in companions(database)[:1])


def _sync(path: Path, flags: int = 0) -> None:
    descriptor = os.open(path, os.O_RDONLY | os.O_CLOEXEC | flags)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def _write_record(record: Path, value: dict) -> None:
    temporary = record.with_name(f".{record.name}.{os.getpid()}")
    with open(temporary, "x", encoding="utf-8") as stream:
        json.dump(value, stream, sort_keys=True)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(temporary, record)
    # The rename must survive a power loss before any write bit is removed;
    # otherwise recovery finds a read-only database without its saved modes.
    _sync(record.parent, os.O_DIRECTORY)


def seal(database: Path, record: Path, busy_seconds: float = 2.0) -> None:
    info = database.stat()
    if not record.exists():
        modes = {str(path): stat.S_IMODE(path.stat().st_mode) for path in companions(database) if path.exists()}
        if not database.stat().st_mode & WRITE_BITS:
            raise UnsafeCutover("SQLite is already read-only without a seal record")
        connection = sqlite3.connect(str(database), timeout=busy_seconds, isolation_level=None)
        try:
            busy, _, _ = connection.execute("PRAGMA wal_checkpoint(TRUNCATE)").fetchone()
            if busy:
                raise UnsafeCutover("SQLite still has a writer; the checkpoint could not finish")
            connection.execute("BEGIN IMMEDIATE")
            connection.execute("ROLLBACK")
        except sqlite3.OperationalError as error:
            raise UnsafeCutover("SQLite still has a writer; the checkpoint could not finish") from error
        finally:
            connection.close()
        # Root may have created companion files; they must stay the owner's.
        for path in companions(database)[1:]:
            if path.exists() and path.stat().st_uid != info.st_uid:
                os.chown(path, info.st_uid, info.st_gid)
        _write_record(record, {"version": 1, "database": str(database), "modes": modes})
    saved = json.loads(record.read_text(encoding="utf-8"))
    if saved.get("database") != str(database):
        raise UnsafeCutover("The SQLite seal record belongs to another database")
    for path in companions(database):
        if path.exists():
            os.chmod(path, stat.S_IMODE(path.stat().st_mode) & ~WRITE_BITS)
    wal = Path(f"{database}-wal")
    if wal.exists() and wal.stat().st_size:
        raise UnsafeCutover("The SQLite WAL is not empty after the seal")


def unseal(database: Path, record: Path) -> None:
    if not record.exists():
        if writable(database):
            return
        raise UnsafeCutover("SQLite is read-only and no seal record says how to restore it")
    saved = json.loads(record.read_text(encoding="utf-8"))
    if saved.get("database") != str(database):
        raise UnsafeCutover("The SQLite seal record belongs to another database")
    modes = saved["modes"]
    info = database.stat()
    for path in companions(database):
        if not path.exists():
            continue
        if str(path) in modes:
            os.chmod(path, modes[str(path)])
        else:
            # A refused writer during the seal makes SQLite create -wal or -shm
            # with the read-only mode. Give them the database's own owner and mode.
            os.chown(path, info.st_uid, info.st_gid)
            os.chmod(path, modes[str(database)])
    # The restored modes must be durable before the record that describes them goes.
    for path in companions(database):
        if path.exists():
            _sync(path)
    record.unlink()
    _sync(record.parent, os.O_DIRECTORY)
