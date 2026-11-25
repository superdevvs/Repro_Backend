"""Publish or withdraw the deployment fence marker under both deploy locks.

Deploy-App takes $HOME/.repro-backend-deploy.lock (or the frontend lock), then
refuses with exit 95 if /etc/repro-database-cutover/active exists, before it
changes anything. Taking both locks here, in the same backend-then-frontend
order as the retention helper, means the marker never appears while a deploy
is between its check and its changes.
"""

from __future__ import annotations

import json
import os
import time
from contextlib import ExitStack, contextmanager
from pathlib import Path
from typing import Sequence

import sys

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from runner import UnsafeCutover  # noqa: E402

POLL_SECONDS = 0.05


def _open_lock(path: Path, owner: tuple[int, int] | None) -> int:
    try:
        return os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except FileNotFoundError:
        if owner is None:
            raise UnsafeCutover(f"Deploy lock {path} is missing") from None
    # Create it as the deploy user would, so its own 'exec 9>lock' still works.
    try:
        descriptor = os.open(path, os.O_RDONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    except FileExistsError:
        return os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    os.fchown(descriptor, *owner)
    return descriptor


@contextmanager
def deploy_locks(locks: Sequence[Path], seconds: float, owner: tuple[int, int] | None = None):
    import fcntl

    limit = time.monotonic() + max(0.0, seconds)
    with ExitStack() as stack:
        for path in locks:
            descriptor = _open_lock(path, owner)
            stack.callback(os.close, descriptor)
            while True:
                try:
                    fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    break
                except BlockingIOError:
                    if time.monotonic() >= limit:
                        raise UnsafeCutover(f"A deployment holds its lock {path.name}") from None
                    time.sleep(POLL_SECONDS)
        yield


def _sync_directory(directory: Path) -> None:
    descriptor = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def published(marker: Path, run_id: str) -> bool:
    try:
        record = json.loads(marker.read_text(encoding="utf-8"))
    except FileNotFoundError:
        return False
    except (OSError, ValueError):
        # Present but unreadable still fences deploys; it is not this run's marker.
        return False
    return record == {"version": 1, "run_id": run_id}


def publish(marker: Path, locks: Sequence[Path], run_id: str, seconds: float,
            owner: tuple[int, int] | None = None) -> None:
    with deploy_locks(locks, seconds, owner):
        if published(marker, run_id):
            return
        if marker.exists() or marker.is_symlink():
            raise UnsafeCutover("Another cutover run holds the deployment fence")
        temporary = marker.with_name(f".{marker.name}.{os.getpid()}")
        descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o644)
        try:
            with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
                json.dump({"version": 1, "run_id": run_id}, stream, sort_keys=True)
                stream.flush()
                os.fsync(stream.fileno())
            os.chmod(temporary, 0o644)
            os.replace(temporary, marker)
            _sync_directory(marker.parent)
        finally:
            temporary.unlink(missing_ok=True)


def withdraw(marker: Path, locks: Sequence[Path], run_id: str, seconds: float,
             owner: tuple[int, int] | None = None) -> None:
    with deploy_locks(locks, seconds, owner):
        if not (marker.exists() or marker.is_symlink()):
            return
        if not published(marker, run_id):
            raise UnsafeCutover("The deployment fence belongs to another cutover run")
        marker.unlink()
        _sync_directory(marker.parent)
