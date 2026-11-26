"""Laravel's config cache, built into a temporary file and renamed into place (design v2, 1.3; RC-M1, HO-L9).

Root runs host.json's `commands.config_build`, which must be exactly design 1.3's command,

    ["/usr/sbin/runuser", "-u", "<app_owner>", "-g", "<app_group>", "--", "/bin/sh", "-c",
     "umask 0022 && cd <app_root> && exec env APP_CONFIG_CACHE=\"$1\" /usr/bin/php artisan config:cache",
     "repro-config"]

with one argument appended, the file to write, which becomes `$1`. `config:cache` clears and then writes
the file APP_CONFIG_CACHE names, so the live bootstrap/cache/config.php is never cleared or truncated (E9).

`build(config, expected_engine=...)`:

1. opens `<app_root>/bootstrap/cache` (never through a link) and removes the temporary files an
   interrupted build left there (`.config.php.repro-<32 hex>`, nothing else);
2. runs the command for `.config.php.repro-<32 hex>` in that folder, in its own session and process
   group, with the controller's fixed environment; a timeout (`command_seconds`, or the caller's
   deadline) kills the whole group, since runuser does not pass SIGKILL on;
3. opens the temporary file without following a link and checks it: a regular file with one link, owned
   by `app_owner`, mode 0644 (the command's umask), at most 8 MiB, accepted by the strict var_export
   reader, of the expected engine and, when given, of the expected route hash (the pin);
4. gives it the live file's owner, group and mode (`app_owner`, `app_group` and 0644 when no regular live
   file exists), fsyncs it, checks that its name still holds that file, renames it over config.php and
   fsyncs the folder.

Any failure removes the temporary file and leaves the live file as it was. It returns the LiveConfig of
the file it renamed. The deploy user owns bootstrap/cache and may replace config.php at any time, so the
callers measure the live file again against the pin (`prepare` step 8, `seal_sqlite` step 6).

`stage(config, expected_engine=..., environment=...)` (design 1.3, Pins) runs the same command, with
`environment` added (the switch values: Laravel's immutable dotenv keeps them over .env), into a fresh
0700 folder the deploy user owns, applies the same checks, renames nothing and removes the folder. It
returns the staged file's LiveConfig. Standard library only.
"""

from __future__ import annotations

import errno
import grp
import os
import pwd
import re
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time
import uuid
from pathlib import Path

sys.path[:0] = [str(Path(__file__).resolve().parent)]
import live_config  # noqa: E402
import var_export  # noqa: E402

PHP = "/usr/bin/php"
RUNUSER = "/usr/sbin/runuser"
SCRIPT_NAME = "repro-config"
LIVE_NAME = "config.php"
TEMPORARY = re.compile(r"\.config\.php\.repro-[0-9a-f]{32}")
BUILT_MODE = 0o644
# adapter.CHILD_TAG and the environment adapter._run gives every host command, so require_quiescent finds a
# build an earlier controller left running.
CHILD_TAG = "REPRO_CUTOVER_CHILD"
ENVIRONMENT = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8", CHILD_TAG: "1"}
ENV_KEY = re.compile(r"[A-Z][A-Z0-9_]*")
# A path the shell reads as one plain word (adapter.SHELL_PATH).
SHELL_PATH = re.compile(r"/[A-Za-z0-9_./-]*")


class BuildFailed(Exception):
    """The cache was not built; the live file is as it was. The message holds no config value."""


def expected_command(config: dict) -> list[str]:
    script = (f'umask 0022 && cd {config["app_root"]} && exec env APP_CONFIG_CACHE="$1" {PHP} artisan '
              'config:cache')
    return [RUNUSER, "-u", config["app_owner"], "-g", config["app_group"], "--", "/bin/sh", "-c", script, SCRIPT_NAME]


def check_command(config: dict) -> list[str]:
    """commands.config_build, if it is design 1.3's command for this host config; else BuildFailed."""
    root = config["app_root"]
    if not (isinstance(root, str) and SHELL_PATH.fullmatch(root)):
        raise BuildFailed("app_root must be one plain shell word for the config build")
    if config["app_owner"] == "root":
        raise BuildFailed("The config build never runs as root")
    if config["commands"]["config_build"] != expected_command(config):
        raise BuildFailed("commands.config_build must be design 1.3's runuser command: the deploy user and group, "
                          "umask 0022, cd to app_root, exec env APP_CONFIG_CACHE=\"$1\" php artisan config:cache")
    return list(config["commands"]["config_build"])


def run_command(argv: list[str], seconds: float, environment: dict | None = None) -> None:
    """One host command in its own session and process group; a timeout or an interrupt kills the group."""
    extra = environment or {}
    if not all(isinstance(key, str) and ENV_KEY.fullmatch(key) and isinstance(value, str) and "\0" not in value
               for key, value in extra.items()):
        raise BuildFailed("The staged build environment must be KEY=value strings")
    with subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                          env={**extra, **ENVIRONMENT}, start_new_session=True) as child:
        try:
            child.wait(timeout=seconds)
        except BaseException as stopped:
            try:
                os.killpg(child.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            child.wait()
            if isinstance(stopped, subprocess.TimeoutExpired):
                raise BuildFailed("The config build timed out") from None
            raise
    if child.returncode != 0:
        raise BuildFailed(f"The config build exited {child.returncode}")


def _seconds(config: dict, deadline: float | None) -> float:
    seconds = float(config["command_seconds"])
    return seconds if deadline is None else max(1.0, min(seconds, deadline - time.time()))


def _accounts(config: dict) -> tuple[int, int]:
    try:
        return pwd.getpwnam(config["app_owner"]).pw_uid, grp.getgrnam(config["app_group"]).gr_gid
    except KeyError:
        raise BuildFailed("The deploy user or group does not exist") from None


def _open_folder(path: Path) -> int:
    try:
        return os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except OSError as error:
        raise BuildFailed(f"{path} is not a folder the build can use: {os.strerror(error.errno)}") from None


def _remove_leftovers(folder: int) -> None:
    for name in os.listdir(folder):
        if TEMPORARY.fullmatch(name):
            try:
                os.unlink(name, dir_fd=folder)
            except (FileNotFoundError, IsADirectoryError):
                continue


def _read_all(descriptor: int, limit: int) -> bytes:
    chunks, size = [], 0
    while size <= limit:
        chunk = os.read(descriptor, 1 << 20)
        if not chunk:
            break
        chunks.append(chunk)
        size += len(chunk)
    if size > limit:
        raise BuildFailed(f"The built config is larger than {limit} bytes")
    return b"".join(chunks)


def _checked(folder: int, name: str, owner: int, expected_engine: str,
             expected_sha256: str | None) -> tuple[int, os.stat_result, live_config.LiveConfig]:
    """Open the built file without following a link; return (descriptor, its stat, its LiveConfig)."""
    try:
        descriptor = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=folder)
    except FileNotFoundError:
        raise BuildFailed("The build wrote no config file") from None
    except OSError as error:
        if error.errno == errno.ELOOP:
            raise BuildFailed("The build left a symbolic link, not a config file") from None
        raise
    try:
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode):
            raise BuildFailed("The build left something other than a regular file")
        if info.st_nlink != 1:
            raise BuildFailed("The built file must have exactly one link")
        if info.st_uid != owner:
            raise BuildFailed("The built file must belong to the deploy user")
        if stat.S_IMODE(info.st_mode) != BUILT_MODE:
            raise BuildFailed("The built file must have mode 0644 (the build runs with umask 0022)")
        raw = _read_all(descriptor, live_config.MAX_CACHE_BYTES)
        try:
            measured = live_config.from_cache(raw)
        except var_export.Refused as refusal:
            raise BuildFailed(f"The build wrote a config the reader refused: {refusal}") from None
        if measured.engine != expected_engine:
            raise BuildFailed(f"The build gave engine {measured.engine[:40]}, not {expected_engine}")
        if expected_sha256 is not None and measured.live_config_sha256 != expected_sha256:
            raise BuildFailed("The build gave a config that is not the expected route hash")
        return descriptor, info, measured
    except BaseException:
        os.close(descriptor)
        raise


def _live_owner(folder: int, owner: int, group: int) -> tuple[int, int, int]:
    """The live file's owner, group and permission bits; the deploy user's, its group and 0644 without one."""
    try:
        info = os.stat(LIVE_NAME, dir_fd=folder, follow_symlinks=False)
    except FileNotFoundError:
        return owner, group, BUILT_MODE
    if not stat.S_ISREG(info.st_mode):
        return owner, group, BUILT_MODE
    return info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode) & 0o777


def build(config: dict, *, expected_engine: str, expected_sha256: str | None = None,
          deadline: float | None = None) -> live_config.LiveConfig:
    """Build the cache into a temporary file, check it, and rename it over bootstrap/cache/config.php."""
    command = check_command(config)
    owner, group = _accounts(config)
    path = Path(config["app_root"]) / "bootstrap" / "cache"
    folder = _open_folder(path)
    name = ".config.php.repro-" + uuid.uuid4().hex
    try:
        _remove_leftovers(folder)
        try:
            run_command(command + [str(path / name)], _seconds(config, deadline))
            descriptor, info, measured = _checked(folder, name, owner, expected_engine, expected_sha256)
            try:
                uid, gid, mode = _live_owner(folder, owner, group)
                os.fchown(descriptor, uid, gid)
                os.fchmod(descriptor, mode)
                os.fsync(descriptor)
                now = os.stat(name, dir_fd=folder, follow_symlinks=False)
                if (now.st_dev, now.st_ino) != (info.st_dev, info.st_ino):
                    raise BuildFailed("The temporary file was replaced while it was checked")
                try:
                    os.replace(name, LIVE_NAME, src_dir_fd=folder, dst_dir_fd=folder)
                except OSError as error:
                    raise BuildFailed(f"The built config could not replace config.php: {os.strerror(error.errno)}") \
                        from None
                os.fsync(folder)
            finally:
                os.close(descriptor)
        finally:
            try:
                os.unlink(name, dir_fd=folder)
            except FileNotFoundError:
                pass
    finally:
        os.close(folder)
    return measured


def stage(config: dict, *, expected_engine: str, environment: dict | None = None,
          expected_sha256: str | None = None, deadline: float | None = None,
          parent: str | None = None) -> live_config.LiveConfig:
    """Build the cache with the controller's exact command into a private folder; rename nothing, keep nothing."""
    command = check_command(config)
    owner, _ = _accounts(config)
    path = Path(tempfile.mkdtemp(prefix="repro-config-stage-", dir=parent))
    try:
        os.chmod(path, 0o700)
        os.chown(path, owner, -1)
        folder = _open_folder(path)
        try:
            run_command(command + [str(path / LIVE_NAME)], _seconds(config, deadline), environment)
            descriptor, _, measured = _checked(folder, LIVE_NAME, owner, expected_engine, expected_sha256)
            os.close(descriptor)
        finally:
            os.close(folder)
    finally:
        # A staged MySQL config holds the app password: nothing of it may stay behind.
        shutil.rmtree(path)
    return measured
