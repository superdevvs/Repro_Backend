"""Mount the verified SQLite file and disposable sidecars, then run the configured non-root smoke."""
import os
from pathlib import Path
import pwd
import subprocess
import sys
import stat


def main(source, database, cache, work, user, command):
    if os.geteuid() or os.stat("/proc/self/ns/mnt").st_ino == os.stat(f"/proc/{os.getppid()}/ns/mnt").st_ino:
        raise RuntimeError("A separate root mount namespace is required")
    account = pwd.getpwnam(user)
    if (account.pw_uid == 0 or not database.is_absolute() or database.parent == Path("/")
            or work.is_relative_to(database.parent) or cache.is_relative_to(database.parent)
            or work.stat().st_uid or work.stat().st_mode & 0o022 or not stat.S_ISREG(source.lstat().st_mode)
            or not stat.S_ISREG(database.lstat().st_mode)):
        raise RuntimeError("Unsafe staged SQLite mount paths or user")
    if command[:3] != ["/usr/sbin/runuser", "-u", user]:
        raise RuntimeError("Missing reviewed non-root smoke command")

    def mount(*args):
        subprocess.run(["/usr/bin/mount", *args], check=True, stdin=subprocess.DEVNULL)

    mount("--make-rprivate", "/")
    # Retain access to siblings (e.g. database/migrations) after overlaying the database directory.
    original = work / "original-directory"
    original.mkdir(mode=0o700)
    mount("-o", "bind,ro", "--", database.parent, original)
    mount("-t", "tmpfs", "-o", "size=16m,nosuid,nodev,mode=0700", "tmpfs", database.parent)
    private_names = {database.name + suffix for suffix in ("", "-wal", "-shm", "-journal")}
    for sibling in original.iterdir():
        if sibling.name not in private_names:
            (database.parent / sibling.name).symlink_to(sibling)
    # Resolve an archive-side source through the saved original directory; the live
    # database and every live WAL, journal and shm inode stay outside the application view.
    if source.is_relative_to(database.parent):
        source = original / source.relative_to(database.parent)
    database.touch(mode=0o400, exist_ok=False)
    mount("-o", "bind,ro", "--", source, database)
    os.chown(database.parent, account.pw_uid, account.pw_gid)
    # The app may create its empty WAL and shared-memory index in tmpfs. Only the
    # verified main file is mounted there, and its mount rejects all writes.
    os.environ["APP_CONFIG_CACHE"] = str(cache)
    os.execv(command[0], command)


if __name__ == "__main__":
    main(*(Path(s) for s in sys.argv[1:5]), sys.argv[5], sys.argv[6:])
