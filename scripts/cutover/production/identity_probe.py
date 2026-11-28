"""Identity probe for the cutover controller (design v2, section 1.1).

    /usr/bin/python3 -I -S /opt/repro-cutover-fence/production/identity_probe.py --config /etc/repro-cutover-fence/identity.json

Runs as root, reads files only (it never runs application code) and prints the six runner `Identity`
fields as one JSON object:

- run_id: identity.json's `run_id`;
- release_sha: `commit` in <app_root>/storage/app/deploy-meta.json, 40 lower-case hex digits;
- artifact_sha256: the deploy preflight's source fingerprint of app_root, computed by
  backend_source_fingerprint.py beside this file, a root-owned byte-for-byte copy of
  scripts/deploy/backend-source-fingerprint.py (never imported from the app tree);
- source_sha256: SHA-256 of canonical JSON {"kind": "sqlite-file", "path", "fs_uuid", "st_ino",
  "machine_id_sha256"}. The SQLite path must be a regular file (lstat: no symlink, directory or FIFO).
  fs_uuid is the one name in /dev/disk/by-uuid whose block device's st_rdev is the file's st_dev;
  none, or more than one, refuses. Device numbers are not hashed (NVMe minors can change between
  boots). A file renamed into place is another file; a write in place keeps the identity;
- config_sha256: <app_root>/.env canonicalised by config_sha256() with identity.json's env_denylist;
- target_uuid: `server-uuid` in <mysql_datadir>/auto.cnf, readable with MySQL stopped.

identity.json and the machine ID are read through guard.read_protected (root-owned chain), and
identity.json's shape is checked by adapter.load_identity_config. Its denylist is used as given:
load_config, which every controller entry point runs before it calls this probe, refuses a
denylist other than the night flags plus the switch keys. The other inputs belong to other
accounts (deploy-meta.json and .env to the deploy user, auto.cnf to mysql): each is opened without
following a final symlink, must be a regular file and is read with a size bound. Nothing read is
printed: a refusal names paths and `.env` line numbers, never a value.

Exit codes: 0 measured (stdout: the identity); 1 refused or unreadable (stderr says why, stdout is
empty); 2 usage. adapter.identity() raises on anything but 0, so the runner refuses before any
journal change.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import stat
import sys
from dataclasses import asdict
from pathlib import Path

sys.path[:0] = [str(Path(__file__).resolve().parent), str(Path(__file__).resolve().parent.parent)]
import adapter as host  # noqa: E402
from adapter import guard  # noqa: E402
# The root-owned copy of scripts/deploy/backend-source-fingerprint.py beside this file, never the app tree's.
import backend_source_fingerprint  # noqa: E402
from runner import Identity, digest  # noqa: E402

BY_UUID = Path("/dev/disk/by-uuid")
MACHINE_ID = guard.MACHINE_ID
DEPLOY_META = ("storage", "app", "deploy-meta.json")
MAX_INPUT_BYTES = guard.MAX_RECORD_BYTES
COMMIT = r"[0-9a-f]{40}"
# udev's by-uuid names: ext4 and xfs UUIDs, vfat and ntfs serial numbers.
FS_UUID_NAME = r"[0-9A-Za-z][0-9A-Za-z._-]{0,63}"
# One `.env` assignment: a plain name at the start of the line, `=`, then the value as bytes.
ENV_LINE = re.compile(rb"([A-Za-z_][A-Za-z0-9_]*)=(.*)", re.DOTALL)


def read_input(path: Path) -> bytes:
    """A regular file another account may own: no final symlink, no FIFO or device, size bounded."""
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
    try:
        guard.require(stat.S_ISREG(os.fstat(descriptor).st_mode), f"{path} is not a regular file")
        with os.fdopen(os.dup(descriptor), "rb") as stream:
            content = stream.read(MAX_INPUT_BYTES + 1)
    finally:
        os.close(descriptor)
    guard.require(len(content) <= MAX_INPUT_BYTES, f"{path} is larger than {MAX_INPUT_BYTES} bytes")
    return content


def release_sha(app_root: Path) -> str:
    """`commit` in the deploy's storage/app/deploy-meta.json."""
    path = app_root.joinpath(*DEPLOY_META)
    meta = json.loads(read_input(path).decode("utf-8"), object_pairs_hook=guard.unique_fields)
    guard.require(isinstance(meta, dict) and guard.matches(COMMIT, meta.get("commit")),
                  f"{path} must name the deployed commit as 40 lower-case hex digits")
    return meta["commit"]


def artifact_sha256(app_root: Path) -> str:
    """The deploy preflight's source fingerprint of the app root, by the root-owned copy."""
    # The fingerprint of a missing tree is a constant, so a moved app root must refuse, as the deploy does.
    guard.require(app_root.is_dir(), f"The app root {app_root} is not a directory")
    return backend_source_fingerprint.fingerprint(app_root)


def filesystem_uuid(device: int, by_uuid: Path, path: Path) -> str:
    """The one name in by_uuid whose block device is `device` (the SQLite file's st_dev)."""
    missing = (f"No filesystem UUID in {by_uuid} names the device of {path} "
               f"({os.major(device)}:{os.minor(device)})")
    try:
        # udev's folder: root-owned and written by root only, like every other input root trusts.
        guard.check_chain(by_uuid, directory=True)
    except FileNotFoundError:
        raise guard.Refused(missing) from None
    names = []
    for name in sorted(os.listdir(by_uuid)):
        try:
            target = os.stat(by_uuid / name)
        except FileNotFoundError:  # A link whose device has gone.
            continue
        if stat.S_ISBLK(target.st_mode) and target.st_rdev == device:
            names.append(name)
    guard.require(bool(names), missing)
    guard.require(len(names) == 1, f"Several filesystem UUIDs in {by_uuid} name the device of {path}: "
                                   f"{', '.join(names)}")
    guard.require(guard.matches(FS_UUID_NAME, names[0]), f"Unexpected filesystem UUID name in {by_uuid}")
    return names[0]


def source_sha256(path: Path, *, by_uuid: Path, machine_id_sha256: str, lstat=os.lstat) -> str:
    """Which file is the SQLite database: path, filesystem UUID, inode and machine (design 1.1).

    The device number itself is not hashed: NVMe partition minors can change between boots on a
    two-drive host, and udev then points the same UUID at the new node. A file renamed into place has
    another inode; a write in place keeps it. `lstat` is replaceable for tests only.
    """
    try:
        info = lstat(path)
    except FileNotFoundError:
        raise guard.Refused(f"The SQLite path {path} is not a regular file: it does not exist") from None
    guard.require(stat.S_ISREG(info.st_mode), f"The SQLite path {path} is not a regular file")
    return digest({"kind": "sqlite-file", "path": str(path), "fs_uuid": filesystem_uuid(info.st_dev, by_uuid, path),
                   "st_ino": info.st_ino, "machine_id_sha256": machine_id_sha256})


def config_sha256(raw: bytes, denylist) -> str:
    """SHA-256 of `.env` canonicalised (design 1.1): comments and blank lines dropped, each other line
    `NAME=VALUE` kept byte for byte unless NAME is denylisted, the lines sorted and joined with `\\n`.

    A line that is not `NAME=VALUE` (an `export` prefix, a space before `=`, a multi-line value) and a
    repeated name outside the denylist are refused: Laravel's immutable repository keeps the last of two
    definitions of one name, and sorting would otherwise hide a swap of the two. Names are compared
    exactly, letter case included, as phpdotenv compares them. Denylisted lines are dropped whatever they
    hold or how often they appear, so nothing the night or the switch does to them changes the digest or
    makes the probe refuse. Messages name line numbers only, never a value.
    """
    denied = {name.encode("ascii") for name in denylist}
    kept, names = [], set()
    for number, line in enumerate(raw.split(b"\n"), 1):
        if not line.strip() or line.lstrip().startswith(b"#"):
            continue
        assignment = ENV_LINE.fullmatch(line)
        guard.require(assignment is not None, f".env line {number} is not KEY=VALUE")
        name = assignment.group(1)
        if name in denied:
            continue
        guard.require(name not in names, f".env line {number} repeats a key")
        names.add(name)
        kept.append(line)
    return hashlib.sha256(b"\n".join(sorted(kept))).hexdigest()


def target_uuid(datadir: Path) -> str:
    """`server-uuid` in the datadir's auto.cnf, exactly as MySQL writes it: `[auto]`, then `server-uuid=<uuid>`."""
    path = datadir / "auto.cnf"
    lines = [line for line in read_input(path).split(b"\n") if line.strip()]
    prefix = b"server-uuid="
    value = None
    if len(lines) == 2 and lines[0] == b"[auto]" and lines[1].startswith(prefix):
        value = lines[1][len(prefix):].decode("ascii")
    guard.require(guard.valid_uuid(value), f"{path} must hold MySQL's [auto] section with one server-uuid")
    return value


def measure(identity: dict, *, by_uuid: Path, machine_id: Path) -> Identity:
    """The six runner Identity fields for one identity.json, already shape-checked."""
    app_root, datadir = Path(identity["app_root"]), Path(identity["mysql_datadir"])
    machine_id_sha256 = guard.sha256(guard.read_protected(machine_id))
    return Identity(
        run_id=identity["run_id"],
        release_sha=release_sha(app_root),
        artifact_sha256=artifact_sha256(app_root),
        source_sha256=source_sha256(Path(identity["sqlite_path"]), by_uuid=by_uuid,
                                    machine_id_sha256=machine_id_sha256),
        config_sha256=config_sha256(read_input(app_root / ".env"), identity["env_denylist"]),
        target_uuid=target_uuid(datadir),
    )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--config", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        if sys.platform != "linux" or os.geteuid() != 0:
            raise guard.Refused("The identity probe runs as root on the Linux host")
        identity = host.load_identity_config(guard.read_protected(args.config))
        measured = measure(identity, by_uuid=BY_UUID, machine_id=MACHINE_ID)
    except (guard.Refused, OSError, ValueError, TypeError, KeyError) as error:
        print(f"Identity probe refused: {error}", file=sys.stderr)
        return 1
    print(json.dumps(asdict(measured), sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
