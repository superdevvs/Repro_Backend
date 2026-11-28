"""The app's `.env` switch (design v2, 3.2 steps 5 to 7, 3.5 step 3; build plan D10).

`prepare` sets the switch keys (host.json `mysql.switch` plus `mysql.switch_secrets`) in `<app_root>/.env`
and `seal_sqlite` sets them back; every other line stays byte for byte. The lines are read exactly as the
identity probe reads them (identity_probe.config_sha256): blank lines and lines starting with `#` (after
spaces) are skipped, every other line must be `NAME=VALUE` with the name first on the line (ENV_LINE, the
probe's own pattern). Every switch key is on the identity's env_denylist, so a switch never changes
`config_sha256` and never makes the probe refuse the file (review A2-11).

- `values(data, keys)`: the raw text after the first `=` of each key's line, or None when the key has no
  line. That is what the switch record keeps (the SQLite values): not a copy of `.env`, so no `APP_KEY`
  or other secret is copied (critic RC-L4, HO-L10). A key on two lines is refused (phpdotenv keeps one of
  them; the switch could not tell which). Refusals name line numbers and key names, never a value.
- `assign(data, values)`: each key's line becomes `NAME=value`, or goes when the value is None; a key with
  no line is appended at the end, in name order. So `assign(assign(data, switch), values(data, keys))`
  is `data` again, byte for byte: a file that does not end with a newline is refused when a line would
  have to be appended.
- `digest(data, ignored)`: the SHA-256 of the file with every line of the `ignored` names left out (the
  switch record's `env_sha256`, with the night flags left out, so a flag the night turns does not make a
  recorded switch unverifiable).
- `replace(path, data, expected=...)`: the atomic replacement of E5. A temporary file in the same folder
  gets the old file's owner, group, mode and every extended attribute (the backup account's POSIX ACL is
  `system.posix_acl_access`), is fsynced, renamed over the file only while the name still holds the file
  that was read with exactly `expected`, the folder is fsynced, and the result is read back. Any failure
  before the rename removes the temporary file and leaves the old one. Temporary files an interrupted
  replacement left (`.env.repro-<32 hex>`) are removed first. The folder is opened without following a
  final link, and a `.env` with a second name (a hard link) is refused: the rename would leave the old
  bytes under the other name.
- `require_switchable(path, keys)`: everything `replace` and `assign` would refuse for a switch of `keys`,
  checked before anything is written (prepare step 5, before its switch record; review D10R-6).
- `remove_leftovers(path)`: removes what an interrupted replacement left beside `path` (each one a whole
  switched `.env`, the app password included) and returns the names; `seal_sqlite` calls it (review D10R-5).

Standard library only; it runs as root under /usr/bin/python3 -I -S beside adapter.py.
"""

from __future__ import annotations

import errno
import hashlib
import os
import re
import stat
import uuid
from collections.abc import Iterable, Mapping
from pathlib import Path

# identity_probe.ENV_LINE: a plain name at the start of the line, `=`, then the value as bytes.
ENV_LINE = re.compile(rb"([A-Za-z_][A-Za-z0-9_]*)=(.*)", re.DOTALL)
NAME = re.compile(r"[A-Za-z_][A-Za-z0-9_]*")
# The identity probe's bound on `.env` (guard.MAX_RECORD_BYTES).
MAX_BYTES = 1024 * 1024
TEMPORARY = re.compile(r"\.env\.repro-[0-9a-f]{32}")


class Refused(Exception):
    """The `.env` was not read or changed as asked. The message names lines and keys, never a value."""


def _name(line: bytes, number: int) -> str | None:
    """The name a line assigns, None for a blank or comment line; refused as the identity probe refuses."""
    if not line.strip() or line.lstrip().startswith(b"#"):
        return None
    assignment = ENV_LINE.fullmatch(line)
    if assignment is None:
        raise Refused(f".env line {number} is not KEY=VALUE")
    return assignment.group(1).decode("ascii")


def _lines(data: bytes) -> list[tuple[bytes, str | None]]:
    return [(line, _name(line, number)) for number, line in enumerate(data.split(b"\n"), 1)]


def values(data: bytes, keys: Iterable[str]) -> dict[str, str | None]:
    """The raw value of each key's one line, or None when it has none."""
    wanted = set(keys)
    found: dict[str, str | None] = {key: None for key in wanted}
    seen = set()
    for number, (line, name) in enumerate(_lines(data), 1):
        if name not in wanted:
            continue
        if name in seen:
            raise Refused(f".env line {number} repeats {name}")
        seen.add(name)
        raw = line[len(name) + 1:]
        try:
            text = raw.decode("utf-8")
        except UnicodeDecodeError:
            text = None
        if text is None or "\0" in text:
            raise Refused(f"The value of {name} on .env line {number} is not UTF-8 text without NUL")
        found[name] = text
    return found


def _checked(assignments: Mapping[str, str | None]) -> None:
    for key, value in assignments.items():
        if not (isinstance(key, str) and NAME.fullmatch(key)):
            raise Refused("A .env key must be a plain name")
        if value is not None and not (isinstance(value, str) and "\n" not in value and "\0" not in value):
            raise Refused(f"The value for {key} would not stay on one .env line")


def assign(data: bytes, assignments: Mapping[str, str | None]) -> bytes:
    """`data` with each key's line set to `NAME=value` (appended if absent) or removed (None)."""
    _checked(assignments)
    result, seen = [], set()
    for number, (line, name) in enumerate(_lines(data), 1):
        if name not in assignments:
            result.append(line)
            continue
        if name in seen:
            raise Refused(f".env line {number} repeats {name}")
        seen.add(name)
        if assignments[name] is not None:
            result.append(f"{name}={assignments[name]}".encode("utf-8"))
    appended = [f"{key}={value}".encode("utf-8") for key, value in sorted(assignments.items())
                if value is not None and key not in seen]
    if appended:
        if result[-1] != b"":
            raise Refused("The .env must end with a newline before the switch can append a line")
        result[-1:] = [*appended, b""]
    return b"\n".join(result)


def digest(data: bytes, ignored: Iterable[str] = ()) -> str:
    """SHA-256 of the file with every line of the `ignored` names left out, the rest byte for byte."""
    left_out = set(ignored)
    return hashlib.sha256(b"\n".join(line for line, name in _lines(data) if name not in left_out)).hexdigest()


def _read_all(descriptor: int) -> bytes:
    chunks, size = [], 0
    while size <= MAX_BYTES:
        chunk = os.read(descriptor, 1 << 16)
        if not chunk:
            break
        chunks.append(chunk)
        size += len(chunk)
    if size > MAX_BYTES:
        raise Refused(f"The .env is larger than {MAX_BYTES} bytes")
    return b"".join(chunks)


def _open_regular(name, folder: int | None = None) -> int:
    """A descriptor of a regular file, never through a final link and never blocking on a FIFO."""
    try:
        descriptor = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=folder)
    except OSError as error:
        if error.errno == errno.ELOOP:
            raise Refused("The .env is a symbolic link, not a regular file") from None
        raise
    if not stat.S_ISREG(os.fstat(descriptor).st_mode):
        os.close(descriptor)
        raise Refused("The .env is not a regular file")
    return descriptor


def read(path) -> bytes:
    """The bytes of a regular `.env` (no final link, at most MAX_BYTES)."""
    descriptor = _open_regular(os.fspath(path))
    try:
        return _read_all(descriptor)
    finally:
        os.close(descriptor)


def _attributes(descriptor: int) -> dict[str, bytes]:
    return {name: os.getxattr(descriptor, name) for name in sorted(os.listxattr(descriptor))}


def _metadata(descriptor: int) -> tuple:
    info = os.fstat(descriptor)
    return info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode), _attributes(descriptor)


def _remove_leftovers(folder: int) -> list[str]:
    removed = []
    for name in sorted(os.listdir(folder)):
        if TEMPORARY.fullmatch(name):
            try:
                os.unlink(name, dir_fd=folder)
            except (FileNotFoundError, IsADirectoryError):
                continue
            removed.append(name)
    return removed


def _open_folder(path: Path) -> int:
    """The `.env`'s folder, never reached through a final link (the rename happens inside it)."""
    try:
        return os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except OSError as error:
        raise Refused(f"The folder of the .env cannot be used: {os.strerror(error.errno)}") from None


def _single_name(descriptor: int) -> None:
    if os.fstat(descriptor).st_nlink != 1:
        raise Refused("The .env has a second name (a hard link): the switch would leave the old file under it")


def require_switchable(path, keys: Iterable[str]) -> None:
    """Refuse what a switch of `keys` would refuse (replace, then assign), before anything is written: the folder
    reached through a final link, a `.env` that is not one regular file with one name, a line the identity probe
    refuses, a key on two lines, a key to append to a file without a final newline."""
    path = Path(path)
    folder = _open_folder(path)
    try:
        descriptor = _open_regular(path.name, folder)
        try:
            _single_name(descriptor)
            data = _read_all(descriptor)
        finally:
            os.close(descriptor)
    finally:
        os.close(folder)
    assign(data, dict.fromkeys(keys, "switch"))


def remove_leftovers(path) -> list[str]:
    """Remove the temporary files an interrupted replacement of `path` left (`.env.repro-<32 hex>`); their names."""
    folder = _open_folder(Path(path))
    try:
        return _remove_leftovers(folder)
    finally:
        os.close(folder)


def replace(path, data: bytes, *, expected: bytes) -> None:
    """Replace the regular file at `path`, which must still hold `expected`, by `data`; keep its owner, group,
    mode and extended attributes (ACL included). Atomic: a temporary file in the same folder is renamed over it.
    """
    path = Path(path)
    folder = _open_folder(path)
    try:
        _remove_leftovers(folder)
        old = _open_regular(path.name, folder)
        try:
            _single_name(old)
            if _read_all(old) != expected:
                raise Refused("The .env changed since it was read; nothing was replaced")
            kept = _metadata(old)
            identity = os.fstat(old)
        finally:
            os.close(old)
        uid, gid, mode, attributes = kept
        name = f".env.repro-{uuid.uuid4().hex}"
        new = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600,
                      dir_fd=folder)
        try:
            view = memoryview(data)
            while view:
                view = view[os.write(new, view):]
            os.fchown(new, uid, gid)
            for key, value in attributes.items():
                os.setxattr(new, key, value)
            # After the ACL: a chmod keeps the ACL's mask equal to the group bits of the old mode.
            os.fchmod(new, mode)
            os.fsync(new)
            if _metadata(new) != kept:
                raise Refused("The new .env did not keep the owner, mode and extended attributes")
            # The names still hold the two files: the new one, and the very file that was read.
            written = os.stat(name, dir_fd=folder, follow_symlinks=False)
            current = os.stat(path.name, dir_fd=folder, follow_symlinks=False)
            if ((written.st_dev, written.st_ino) != (os.fstat(new).st_dev, os.fstat(new).st_ino)
                    or (current.st_dev, current.st_ino, current.st_size, current.st_mtime_ns)
                    != (identity.st_dev, identity.st_ino, identity.st_size, identity.st_mtime_ns)):
                raise Refused("The .env or its temporary file was replaced while the switch ran; nothing was "
                              "replaced")
            os.replace(name, path.name, src_dir_fd=folder, dst_dir_fd=folder)
            os.fsync(folder)
        finally:
            os.close(new)
            try:
                os.unlink(name, dir_fd=folder)
            except FileNotFoundError:
                pass
        # Read back (E5): the bytes, the owner, the mode and the attributes that were asked for.
        back = _open_regular(path.name, folder)
        try:
            if _read_all(back) != data or _metadata(back) != kept:
                raise Refused("The .env read back differs from what was written")
        finally:
            os.close(back)
    finally:
        os.close(folder)
