"""Seal the production SQLite file against writes during the freeze.

The seal checkpoints and truncates the WAL, refuses while another process
holds the files open for writing, records the exact modes of the
database and its -wal/-shm files, then removes every write bit and checks
for writers again. sqlite3, tinker and stray scripts running as the deploy
or web user then get SQLITE_READONLY; a session opened before the seal
would keep writable descriptors, which is why the seal refuses it. Root ignores file modes, so root-run tools are fenced only
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


def _access_mode(fdinfo: Path) -> int:
    for line in fdinfo.read_text(encoding="ascii").splitlines():
        name, _, value = line.partition(":")
        if name == "flags":
            return int(value.strip(), 8) & os.O_ACCMODE
    raise UnsafeCutover(f"SQLite still has a writer that cannot be ruled out: no flags in {fdinfo}")


def open_writers(database: Path, proc: Path = Path("/proc")) -> list[int]:
    """Other processes that hold the database or a companion file open for writing.

    Removing write bits stops new opens only; a descriptor opened earlier, for
    example by an idle tinker or sqlite3 session, can still write. A process
    whose descriptors cannot be read raises instead of being skipped: the
    controller runs as root, so that means the check cannot be trusted.
    """
    targets = set()
    for path in companions(database):
        try:
            info = path.stat()
        except FileNotFoundError:
            continue
        targets.add((info.st_dev, info.st_ino))
    writers = []
    for entry in proc.iterdir():
        if not entry.name.isdigit() or int(entry.name) == os.getpid():
            continue
        try:
            for descriptor in (entry / "fd").iterdir():
                try:
                    info = descriptor.stat()
                    if (info.st_dev, info.st_ino) in targets \
                            and _access_mode(entry / "fdinfo" / descriptor.name) != os.O_RDONLY:
                        writers.append(int(entry.name))
                        break
                except FileNotFoundError:
                    continue  # Closed since the listing.
        except (FileNotFoundError, ProcessLookupError):
            continue  # Exited since the listing.
        except PermissionError:
            raise UnsafeCutover(
                f"SQLite still has a writer that cannot be ruled out: the open files of process {entry.name} "
                "cannot be read"
            ) from None
    return writers


def _refuse_open_writers(database: Path, proc: Path) -> None:
    writers = open_writers(database, proc)
    if writers:
        raise UnsafeCutover(
            "SQLite still has a writer: process " + ", ".join(map(str, writers))
            + " keeps the database open for writing"
        )


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


def seal(database: Path, record: Path, busy_seconds: float = 2.0, proc: Path = Path("/proc")) -> None:
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
        # An idle connection holds no lock, so the checkpoint cannot see it. Refuse before any change.
        _refuse_open_writers(database, proc)
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
    # Anything that opened the files before the chmod keeps writable descriptors. Later
    # non-root opens are read-only. unseal restores the recorded modes after a refusal.
    _refuse_open_writers(database, proc)
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
