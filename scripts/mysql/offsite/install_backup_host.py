#!/usr/bin/env python3
"""Install the off-site backup host components. Preview by default; --apply and --undo need root.

Jobs (each with its own preview, apply, self-test and undo):
  base-backup    repro-backup account, /opt/repro-backup-tools, /etc/repro-backup/plan.json,
                 /var/lib/repro-backup, read-only ACLs on .env and the WAL SQLite files,
                 the five base units (installed disabled) and the no-echo R2 config entry.
  alerts         repro-alert account, bridge and probe configs, the inbox, ten alert units.
  recovery-kit   the kit producer, its plan and its three units.
  mysql-recovery preview only: the MySQL heartbeat, rotation, archive and purge units need
                 the owner's decision on the rehearsal instance and reviewed plan pins.

Preview reads the system and prints every account, directory, file (owner, mode,
SHA-256), read grant, unit and the R2 bucket rules to enter. It changes nothing.
Apply never overwrites a file it did not install, never enables a timer, and
records what it created so --undo can return the host to its previous state.
"""
from __future__ import annotations

import argparse
import base64
from dataclasses import dataclass, field
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import struct
import subprocess
import sys
import tempfile

HERE = Path(__file__).resolve().parent
DEPLOY = HERE.parents[1] / "deploy"
UNITS = HERE / "systemd" / "system"
TOOLS_DIR = "/opt/repro-backup-tools"
UNIT_DIR = "/etc/systemd/system"
RECORD_DIR = "/var/lib/repro-backup-installer"
STATE = "/var/lib/repro-backup"
ACCOUNT = "repro-backup"
BASE_TOOLS = ("scheduled_backup.py", "prepare_sqlite_backup.py", "prepare_mysql_backup.py", "publish_backup.py",
              "check_backup_freshness.py", "enter_r2_config.py", "backup-database.php", "backup-sqlite.php")
ALERT_TOOLS = ("alert_bridge.py", "alert_probe.py", "alert-conditions.json")
KIT_TOOLS = ("produce_recovery_kit.py",)
MYSQL_TOOLS = ("heartbeat.py", "rotate_closed_binlogs.py", "rotation_attempt.py", "archive_binlogs.py",
               "collect_closed_binlogs.py", "advance_binlog_checkpoint.py", "prepare_closed_binlogs.py",
               "verify_mysql_binlog_chain.py", "purge_archived_binlogs.py")
BASE_UNITS = ("repro-backup.service", "repro-backup.timer", "repro-backup-watchdog.service",
              "repro-backup-watchdog.timer", "repro-backup-alert.service")
ALERT_UNITS = ("repro-alert-backup-ok.service", "repro-alert-backup-failed.service",
               "repro-alert-backup-fresh.service", "repro-alert-backup-stale.service",
               "repro-alert-heartbeat.service", "repro-alert-heartbeat.timer", "repro-alert-forward.service",
               "repro-alert-forward.timer", "repro-alert-probe.service", "repro-alert-probe.timer")
KIT_UNITS = ("repro-recovery-kit.service", "repro-recovery-kit.timer", "repro-recovery-kit.path")
MYSQL_UNITS = ("repro-mysql-heartbeat.service", "repro-mysql-heartbeat.timer", "repro-binlog-rotate.service",
               "repro-binlog-rotate.timer", "repro-binlog-archive.service", "repro-binlog-archive.timer",
               "repro-binlog-purge.service", "repro-binlog-purge.timer")
JOBS = ("base-backup", "alerts", "recovery-kit", "mysql-recovery")
REQUIRES = {"base-backup": (), "alerts": ("base-backup",), "recovery-kit": ("base-backup",),
            "mysql-recovery": ("base-backup",)}
SETTING_DEFAULTS = {
    "app_root": "/var/www/backend", "sqlite_database": "/var/www/backend/database/database.sqlite",
    "php": "/usr/bin/php8.3", "python": "/usr/bin/python3", "rclone": "/usr/bin/rclone",
    "upload_credential": "derived-no-delete", "alert_fail_suffix": "/fail",
    "probe_conditions": ["database_down", "wrong_database_engine", "disk_nearly_full", "server_error_rate",
                         "queue_stale", "cache_write_failed"],
    "access_logs": ["/var/log/nginx/access.log"],
    "disks": [{"path": "/", "require_mount": True}, {"path": "/mnt/16tb", "require_mount": True}],
}
REQUIRED_SETTINGS = ("release", "identity", "bucket", "endpoint")
RETENTION_DAYS = {"daily/": 30, "binlog/": 30, "monthly/": 365, "final-sqlite/": 365}
USER_OBJ, USER, GROUP_OBJ, GROUP, MASK, OTHER = 0x01, 0x02, 0x04, 0x08, 0x10, 0x20
READ, WRITE, EXECUTE = 4, 2, 1
UNDEFINED = 0xFFFFFFFF
ACL_ACCESS, ACL_DEFAULT = "system.posix_acl_access", "system.posix_acl_default"


class Refusal(RuntimeError):
    pass


def need(condition: bool, reason: str) -> None:
    if not condition:
        raise Refusal(reason)


# ---------------------------------------------------------------- POSIX ACL xattrs
def acl_from_mode(mode: int) -> list:
    return [(USER_OBJ, UNDEFINED, (mode >> 6) & 7), (GROUP_OBJ, UNDEFINED, (mode >> 3) & 7),
            (OTHER, UNDEFINED, mode & 7)]


def encode_acl(entries: list) -> bytes:
    return struct.pack("<I", 2) + b"".join(struct.pack("<HHI", tag, perm, ident) for tag, ident, perm in entries)


def decode_acl(raw: bytes) -> list:
    need(len(raw) >= 4 and (len(raw) - 4) % 8 == 0 and struct.unpack("<I", raw[:4])[0] == 2, "unexpected ACL format")
    return [(tag, ident, perm) for tag, perm, ident in struct.iter_unpack("<HHI", raw[4:])]


def with_named_user(entries: list, uid: int, perm: int) -> list:
    """Add read access for one user without widening what the owning group can do."""
    current = {(tag, ident): value for tag, ident, value in entries}
    current[(USER, uid)] = current.get((USER, uid), 0) | perm
    if (MASK, UNDEFINED) in current:
        current[(MASK, UNDEFINED)] |= perm
    else:
        named = [value for (tag, _), value in current.items() if tag in (USER, GROUP)]
        current[(MASK, UNDEFINED)] = current[(GROUP_OBJ, UNDEFINED)] | perm
        for value in named:
            current[(MASK, UNDEFINED)] |= value
    return sorted(((tag, ident, value) for (tag, ident), value in current.items()), key=lambda item: item[:2])


def describe_acl(entries: list, names: dict | None = None) -> str:
    labels = {USER_OBJ: "user:", USER: "user:", GROUP_OBJ: "group:", GROUP: "group:", MASK: "mask:", OTHER: "other:"}
    text = []
    for tag, ident, perm in entries:
        who = "" if ident == UNDEFINED else (names or {}).get(ident, str(ident))
        rights = "".join(flag if perm & bit else "-" for flag, bit in (("r", 4), ("w", 2), ("x", 1)))
        text.append(f"{labels[tag]}{who}:{rights}")
    return ",".join(text)


def rights(perm: int) -> str:
    return "".join(flag if perm & bit else "-" for flag, bit in (("r", 4), ("w", 2), ("x", 1)))


# ---------------------------------------------------------------- job model
@dataclass(frozen=True)
class Stat:
    kind: str
    uid: int
    gid: int
    mode: int
    size: int


@dataclass
class Account:
    name: str


@dataclass
class Directory:
    path: str
    owner: str
    group: str
    mode: int
    remove_contents: bool = False


@dataclass
class FileItem:
    path: str
    owner: str
    group: str
    mode: int
    data: bytes
    source: str


@dataclass
class Grant:
    path: str
    user: str
    perm: int
    default: bool = False
    optional: bool = False


@dataclass
class Job:
    name: str
    accounts: list = field(default_factory=list)
    directories: list = field(default_factory=list)
    files: list = field(default_factory=list)
    grants: list = field(default_factory=list)
    units: list = field(default_factory=list)
    credential_step: list | None = None
    enable_hint: str = ""
    apply_supported: bool = True
    notes: list = field(default_factory=list)


def parse_settings(raw: bytes) -> dict:
    try:
        given = json.loads(raw)
    except (ValueError, UnicodeError):
        raise Refusal("settings must be JSON") from None
    need(isinstance(given, dict) and given.get("version") == 1, "settings need version 1")
    unknown = set(given) - set(SETTING_DEFAULTS) - set(REQUIRED_SETTINGS) - {"version"}
    need(not unknown, "unknown settings: " + ", ".join(sorted(unknown)))
    for name in REQUIRED_SETTINGS:
        need(isinstance(given.get(name), str) and given[name], f"settings need {name}")
    settings = SETTING_DEFAULTS | given
    need(re.fullmatch(r"[0-9a-f]{40}", settings["release"]) is not None, "release must be a full commit")
    need(re.fullmatch(r"[a-z0-9][a-z0-9._-]{0,127}", settings["identity"]) is not None, "invalid identity")
    need(re.fullmatch(r"[a-z0-9][a-z0-9-]{1,61}[a-z0-9]", settings["bucket"]) is not None, "invalid bucket name")
    need(re.fullmatch(r"https://[0-9a-f]{32}\.r2\.cloudflarestorage\.com", settings["endpoint"]) is not None,
         "endpoint must be the R2 account endpoint")
    need(settings["upload_credential"] in ("derived-no-delete", "scoped-read-write"),
         "upload_credential must be derived-no-delete or scoped-read-write")
    for name in ("app_root", "sqlite_database", "php", "python", "rclone"):
        need(PurePosixPath(settings[name]).is_absolute() and ".." not in PurePosixPath(settings[name]).parts,
             f"{name} must be an absolute path")
    need(settings["alert_fail_suffix"] is None or re.fullmatch(r"/[a-z0-9_-]{1,32}", settings["alert_fail_suffix"]),
         "alert_fail_suffix must be null or like /fail")
    return settings


def source_bytes(name: str) -> bytes:
    for folder in (HERE, DEPLOY, HERE / "systemd" / "binlog-fixture"):
        if (folder / name).is_file():
            return (folder / name).read_bytes()
    raise Refusal(f"reviewed source file {name} is missing from this bundle")


def tool_files(names) -> list:
    return [FileItem(f"{TOOLS_DIR}/{name}", "root", "root", 0o444, source_bytes(name), name) for name in names]


def unit_files(names) -> list:
    return [FileItem(f"{UNIT_DIR}/{name}", "root", "root", 0o644, (UNITS / name).read_bytes(), "systemd/system/" + name)
            for name in names]


def json_bytes(record: dict) -> bytes:
    return (json.dumps(record, indent=2) + "\n").encode()


def base_plan(settings: dict) -> bytes:
    return json_bytes({
        "version": 1, "driver": "sqlite", "release": settings["release"], "identity": settings["identity"],
        "app_root": settings["app_root"], "state_dir": STATE,
        "rclone_config": f"{STATE}/credentials/rclone-daily.conf", "php": settings["php"],
        "rclone": settings["rclone"], "crypt_remote": "repro_encrypted",
        "destination": f"storage:{settings['bucket']}/daily", "max_age_seconds": 93600,
        "run_timeout_seconds": 3600, "transfer_timeout_seconds": 1800, "mysql": None,
    })


def build_job(name: str, settings: dict) -> Job:
    need(name in JOBS, "unknown job " + name)
    app = settings["app_root"]
    database = settings["sqlite_database"]
    if name == "base-backup":
        job = Job(name, accounts=[Account(ACCOUNT)])
        job.directories = [Directory(TOOLS_DIR, "root", "root", 0o755), Directory("/etc/repro-backup", "root", ACCOUNT, 0o750),
                           Directory(STATE, ACCOUNT, ACCOUNT, 0o700, remove_contents=True)]
        job.files = tool_files(BASE_TOOLS) + [
            FileItem("/etc/repro-backup/plan.json", "root", ACCOUNT, 0o640, base_plan(settings), "rendered plan")
        ] + unit_files(BASE_UNITS)
        parent = str(PurePosixPath(database).parent)
        job.grants = [Grant(f"{app}/.env", ACCOUNT, READ), Grant(parent, ACCOUNT, READ | EXECUTE),
                      Grant(parent, ACCOUNT, READ, default=True)]
        job.grants += [Grant(database + suffix, ACCOUNT, READ, optional=bool(suffix)) for suffix in ("", "-wal", "-shm")]
        token = ["--with-session-token"] if settings["upload_credential"] == "derived-no-delete" else []
        job.credential_step = [settings["python"], "-I", "-B", f"{TOOLS_DIR}/enter_r2_config.py", "--role", "upload",
                               "--endpoint", settings["endpoint"], "--bucket", settings["bucket"], "--prefix", "daily",
                               "--config-dir", f"{STATE}/credentials", "--rclone", settings["rclone"], *token]
        job.units = list(BASE_UNITS)
        job.enable_hint = "systemctl enable --now repro-backup.timer repro-backup-watchdog.timer"
        job.notes = ["Install the alerts job right after this one. The backup and watchdog units start the",
                     "bridge units on success and failure; until they exist only the journal marker is written."]
        return job
    if name == "alerts":
        job = Job(name, accounts=[Account("repro-alert")])
        job.directories = [Directory("/etc/repro-alert", "root", "repro-alert", 0o750),
                           Directory("/var/lib/repro-alert", "repro-alert", "repro-alert", 0o700, remove_contents=True),
                           Directory("/var/lib/repro-alert-inbox", ACCOUNT, "repro-alert", 0o2750, remove_contents=True)]
        conditions = list(settings["probe_conditions"])
        bridge = {"version": 1, "urls_file": "/var/lib/repro-alert/ping-urls.json",
                  "inbox_dir": "/var/lib/repro-alert-inbox", "fail_suffix": settings["alert_fail_suffix"],
                  "timeout_seconds": 10, "max_status_age_seconds": 180, "forward": conditions}
        probe = {"version": 1, "inbox_dir": "/var/lib/repro-alert-inbox",
                 "memory_file": "/var/lib/repro-alert-probe/memory.json", "conditions": conditions,
                 "app_root": app, "expected_engine": "sqlite", "sqlite_database": database, "mysql": None,
                 "disks": settings["disks"], "access_logs": settings["access_logs"],
                 "laravel_log": f"{app}/storage/logs/laravel.log",
                 "cache_dir": f"{app}/storage/framework/cache/data", "cache_group_writable": True,
                 "money_queries": None, "money_baseline_file": None, "binlog_checkpoint_file": None,
                 "restore_receipts": None, "rehearsal_receipts": None}
        job.files = tool_files(ALERT_TOOLS) + [
            FileItem("/etc/repro-alert/bridge.json", "root", "repro-alert", 0o640, json_bytes(bridge), "rendered config"),
            FileItem("/etc/repro-backup/alert-probe.json", "root", ACCOUNT, 0o640, json_bytes(probe), "rendered plan"),
        ] + unit_files(ALERT_UNITS)
        job.grants = [Grant(f"{app}/storage/logs", ACCOUNT, READ | EXECUTE), Grant(f"{app}/storage/logs", ACCOUNT, READ, default=True),
                      Grant(f"{app}/storage/logs/laravel.log", ACCOUNT, READ, optional=True)]
        job.credential_step = [settings["python"], "-I", "-B", f"{TOOLS_DIR}/alert_bridge.py", "enter-urls",
                               "--config", "/etc/repro-alert/bridge.json"]
        job.units = list(ALERT_UNITS)
        job.enable_hint = ("systemctl enable --now repro-alert-heartbeat.timer repro-alert-probe.timer "
                           "repro-alert-forward.timer")
        return job
    if name == "recovery-kit":
        plan = {"version": 1, "release": settings["release"], "identity": "repro-app-recovery-kit",
                "state_dir": STATE, "files": [{"source": f"{app}/.env", "name": "app.env"}],
                "rclone_config": f"{STATE}/credentials/rclone-daily.conf", "rclone": settings["rclone"],
                "crypt_remote": "repro_encrypted", "destination": f"storage:{settings['bucket']}/daily",
                "transfer_timeout_seconds": 600, "run_timeout_seconds": 900}
        job = Job(name, files=tool_files(KIT_TOOLS) + [
            FileItem("/etc/repro-backup/recovery-kit.json", "root", ACCOUNT, 0o640, json_bytes(plan), "rendered plan")
        ] + unit_files(KIT_UNITS), units=list(KIT_UNITS))
        job.enable_hint = "systemctl enable --now repro-recovery-kit.timer repro-recovery-kit.path"
        job.notes = ["The kit holds .env, which holds APP_KEY. It is encrypted by the daily crypt remote and is",
                     "never printed. Copy the APP_KEY line into the password manager yourself; the installer",
                     "does not show it."]
        return job
    job = Job(name, accounts=[Account("repro-binlog")], files=tool_files(MYSQL_TOOLS) + unit_files(MYSQL_UNITS),
              units=list(MYSQL_UNITS), apply_supported=False)
    job.notes = [
        "Apply is not offered yet. It needs: the owner's decision to run the 7-day window on the native",
        "rehearsal instance (B1 e); MySQL accounts and the repro_archive_control procedure (heartbeat.md,",
        "rotate_closed_binlogs.md) plus a repro_binlog_purger account with BINLOG_ADMIN and REPLICATION",
        "CLIENT; a group repro-binlog-plan for the shared collector plan; traverse on",
        "/run/repro-mysql84-native for repro-backup and repro-binlog (today only www-data has it); read",
        "on the binlog files for repro-backup; a binlog prefix config (enter_r2_config.py --prefix binlog);",
        "and reviewed plan pins rendered into the four REVIEWED_*_SHA256 placeholders.",
    ]
    return job


def r2_rules() -> tuple[dict, dict]:
    """Bucket lock and lifecycle rules, in the transcription format verify_retention.py reads."""
    locks = [{"id": f"repro-{prefix[:-1]}-lock", "prefix": prefix, "enabled": True,
              "condition": {"type": "Age", "maxAgeSeconds": days * 86400}} for prefix, days in RETENTION_DAYS.items()]
    lifecycle = [{"id": "Default Multipart Abort Rule", "enabled": True, "conditions": {"prefix": ""},
                  "abortMultipartUploadsTransition": {"condition": {"type": "Age", "maxAge": 604800}}}]
    lifecycle += [{"id": f"repro-{prefix[:-1]}-expiry", "enabled": True, "conditions": {"prefix": prefix},
                   "deleteObjectsTransition": {"condition": {"type": "Age", "maxAge": (days + 1) * 86400}}}
                  for prefix, days in RETENTION_DAYS.items()]
    wrap = {"source": "installer preview: rules to enter in the Cloudflare dashboard", "success": True, "errors": []}
    return wrap | {"result": {"rules": locks}}, wrap | {"result": {"rules": lifecycle}}


# ---------------------------------------------------------------- the real system
class LinuxSystem:
    def user_id(self, name):
        import pwd

        try:
            return pwd.getpwnam(name).pw_uid
        except KeyError:
            return None

    def group_id(self, name):
        import grp

        try:
            return grp.getgrnam(name).gr_gid
        except KeyError:
            return None

    def checked(self, argv):
        code, _ = self.run(argv)
        need(code == 0, f"{argv[0]} failed")

    def add_group(self, name):
        self.checked(["/usr/sbin/groupadd", "--system", name])

    def add_user(self, name, group):
        self.checked(["/usr/sbin/useradd", "--system", "--gid", group, "--no-create-home", "--home-dir",
                      "/nonexistent", "--shell", "/usr/sbin/nologin", name])

    def del_user(self, name):
        self.checked(["/usr/sbin/userdel", name])

    def del_group(self, name):
        self.checked(["/usr/sbin/groupdel", name])

    def lstat(self, path):
        try:
            info = os.lstat(path)
        except FileNotFoundError:
            return None
        kind = "dir" if stat.S_ISDIR(info.st_mode) else "file" if stat.S_ISREG(info.st_mode) else "other"
        return Stat(kind, info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode), info.st_size)

    def read(self, path):
        info = self.lstat(path)
        if info is None or info.kind != "file":
            return None
        with open(path, "rb") as stream:
            return stream.read()

    def write_file(self, path, data, uid, gid, mode):
        directory = os.path.dirname(path)
        descriptor, temporary = tempfile.mkstemp(dir=directory, prefix=".repro-install-")
        try:
            with os.fdopen(descriptor, "wb") as stream:
                os.fchown(stream.fileno(), uid, gid)
                os.fchmod(stream.fileno(), mode)
                stream.write(data)
                stream.flush()
                os.fsync(stream.fileno())
            os.replace(temporary, path)
        finally:
            if os.path.lexists(temporary):
                os.unlink(temporary)
        self.sync(directory)

    def sync(self, directory):
        fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)

    def remove_file(self, path):
        os.unlink(path)

    def make_dir(self, path, uid, gid, mode):
        os.mkdir(path, 0o700)
        os.chown(path, uid, gid)
        os.chmod(path, mode)

    def remove_tree(self, path):
        shutil.rmtree(path)

    def is_empty_dir(self, path):
        return not os.listdir(path)

    def get_acl(self, path, default):
        try:
            return os.getxattr(path, ACL_DEFAULT if default else ACL_ACCESS, follow_symlinks=False)
        except OSError:
            return None

    def set_acl(self, path, default, raw):
        name = ACL_DEFAULT if default else ACL_ACCESS
        if raw is None:
            try:
                os.removexattr(path, name, follow_symlinks=False)
            except OSError:
                pass
        else:
            os.setxattr(path, name, raw, follow_symlinks=False)

    def run(self, argv, *, input_tty=False, env=None):
        result = subprocess.run(argv, stdin=None if input_tty else subprocess.DEVNULL,
                                stdout=None if input_tty else subprocess.PIPE, stderr=subprocess.STDOUT,
                                env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8"} | (env or {}),
                                check=False)
        return result.returncode, result.stdout or b""


# ---------------------------------------------------------------- preview, apply, self-test, undo
def record_path(job: str) -> str:
    return f"{RECORD_DIR}/{job}.json"


def load_record(system, job: str) -> dict | None:
    raw = system.read(record_path(job))
    return None if raw is None else json.loads(raw)


def file_state(system, item: FileItem) -> str:
    current = system.read(item.path)
    if current is None:
        return "new"
    return "same" if current == item.data else "DIFFERS"


def ancestors_needing_traverse(system, grants: list) -> list:
    """Directories on the way to a granted path that others cannot traverse."""
    extra, seen = [], set()
    for grant in grants:
        for parent in reversed(PurePosixPath(grant.path).parents):
            folder = str(parent)
            info = system.lstat(folder)
            if folder in seen or folder == "/" or info is None or info.mode & 0o001:
                continue
            seen.add(folder)
            extra.append(Grant(folder, grant.user, EXECUTE))
    return extra


def undo_command(job: str) -> str:
    return f"sudo /usr/bin/python3 -I -B {HERE / 'install_backup_host.py'} --undo --job {job}"


def preview(job: Job, system, settings: dict) -> int:
    print(f"RePro backup host installer: PREVIEW of job {job.name}. No changes were made.")
    print(f"Bundle: {HERE}  Release pinned in plans: {settings['release']}")
    if not job.apply_supported:
        print("This job is preview only:")
    for line in job.notes:
        print("  " + line)
    print("\nAccounts (system, no login shell, no home):")
    for account in job.accounts:
        state = "exists" if system.user_id(account.name) is not None else "new"
        print(f"  {account.name} (group {account.name}) [{state}]")
    print("\nDirectories:")
    for item in job.directories:
        state = "exists" if system.lstat(item.path) else "new"
        print(f"  {item.path} {item.owner}:{item.group} {item.mode:04o} [{state}]")
    print("\nFiles (owner:group mode sha256):")
    for item in job.files:
        print(f"  {item.path} {item.owner}:{item.group} {item.mode:04o} {hashlib.sha256(item.data).hexdigest()} "
              f"[{file_state(system, item)}]")
    try:
        grants = job.grants + ancestors_needing_traverse(system, job.grants)
    except PermissionError:
        grants = job.grants
        print("\nNote: this user cannot see every app folder; run the preview as the deploy user or root.")
    if grants:
        print("\nRead grants (POSIX ACL entries added; nothing is made writable):")
        for grant in grants:
            try:
                exists = "present" if system.lstat(grant.path) else ("skipped if absent" if grant.optional else "MISSING")
            except PermissionError:
                exists = "not visible to this user"
            kind = "default" if grant.default else "access"
            print(f"  {kind:7} user:{grant.user}:{rights(grant.perm)} {grant.path} [{exists}]")
    if job.units:
        print("\nUnits are installed disabled. After the self-tests pass, enable them with:")
        print("  " + job.enable_hint)
    if job.name == "base-backup":
        locks, lifecycle = r2_rules()
        print("\nR2 bucket rules to enter (Cloudflare dashboard, bucket settings):")
        for prefix, days in RETENTION_DAYS.items():
            print(f"  {prefix} lock {days} days, then delete after {days + 1} days")
        print("  keep the default 7-day multipart abort rule")
        print("  lock rules JSON: " + json.dumps(locks["result"]))
        print("  lifecycle rules JSON: " + json.dumps(lifecycle["result"]))
        print("\nCredentials (typed by the owner at a no-echo prompt during apply, never shown):")
        if settings["upload_credential"] == "derived-no-delete":
            print("  UPLOAD key: a derived upload-only credential (key ID, secret, session token) that cannot")
            print("  delete. It expires; renew it before its end date.")
        else:
            print("  UPLOAD key: bucket-scoped Object Read & Write key. It CAN delete objects outside the lock")
            print("  windows. Chosen by the upload_credential setting.")
        print("  READ-ONLY restore key: stays on the recovery machine. Never type it on this server.")
        print("  Backup passphrase 1 and 2 from the password manager.")
    print(f"\nUndo after apply: {undo_command(job.name)}")
    return 0


def ids(system, owner: str, group: str) -> tuple[int, int]:
    uid, gid = system.user_id(owner), system.group_id(group)
    need(uid is not None and gid is not None, f"account {owner}:{group} is missing")
    return uid, gid


def save_record(system, record: dict) -> None:
    if system.lstat(RECORD_DIR) is None:
        system.make_dir(RECORD_DIR, 0, 0, 0o700)
    system.write_file(record_path(record["job"]), json_bytes(record), 0, 0, 0o600)


def apply_grant(system, grant: Grant, record: dict) -> bool:
    info = system.lstat(grant.path)
    if info is None:
        need(grant.optional, f"{grant.path} is missing")
        return False
    uid = system.user_id(grant.user)
    need(uid is not None, f"account {grant.user} is missing")
    raw = system.get_acl(grant.path, grant.default)
    if grant.default:
        need(info.kind == "dir", f"default ACL needs a directory: {grant.path}")
    entries = decode_acl(raw) if raw is not None else acl_from_mode(info.mode)
    updated = with_named_user(entries, uid, grant.perm)
    if updated == entries:
        return False
    key = f"{grant.path}|{'default' if grant.default else 'access'}"
    record["acls"].setdefault(key, None if raw is None else base64.b64encode(raw).decode())
    system.set_acl(grant.path, grant.default, encode_acl(updated))
    return True


def apply(job: Job, system, settings: dict, *, enter_credentials: bool) -> int:
    print(f"RePro backup host installer: APPLY job {job.name}")
    if not job.apply_supported:
        print("FAIL: this job is preview only. Nothing was changed.")
        return 1
    for required in REQUIRES[job.name]:
        if load_record(system, required) is None:
            print(f"FAIL: install job {required} first. Nothing was changed.")
            return 1
    record = load_record(system, job.name) or {"version": 1, "job": job.name, "groups": [], "users": [],
                                                 "directories": [], "files": {}, "acls": {}}
    before = json.dumps(record, sort_keys=True)
    step = "accounts"
    try:
        for account in job.accounts:
            if system.group_id(account.name) is None:
                system.add_group(account.name)
                record["groups"].append(account.name)
            if system.user_id(account.name) is None:
                system.add_user(account.name, account.name)
                record["users"].append(account.name)
        step = "directories"
        for item in job.directories:
            info = system.lstat(item.path)
            uid, gid = ids(system, item.owner, item.group)
            if info is None:
                system.make_dir(item.path, uid, gid, item.mode)
                record["directories"].append([item.path, item.remove_contents])
            else:
                need((info.kind, info.uid, info.gid, info.mode) == ("dir", uid, gid, item.mode),
                     f"{item.path} exists with a different owner or mode")
        step = "files"
        for item in job.files:
            uid, gid = ids(system, item.owner, item.group)
            current = system.read(item.path)
            digest = hashlib.sha256(item.data).hexdigest()
            if current == item.data:
                info = system.lstat(item.path)
                need((info.uid, info.gid, info.mode) == (uid, gid, item.mode), f"{item.path} has a different owner or mode")
                continue
            ours = record["files"].get(item.path)
            need(current is None or (ours is not None and hashlib.sha256(current).hexdigest() == ours),
                 f"{item.path} changed since it was installed; not overwritten")
            system.write_file(item.path, item.data, uid, gid, item.mode)
            record["files"][item.path] = digest
        step = "read grants"
        for grant in job.grants + ancestors_needing_traverse(system, job.grants):
            apply_grant(system, grant, record)
        step = "record"
        if json.dumps(record, sort_keys=True) != before:
            save_record(system, record)
        step = "daemon-reload"
        if job.units:
            code, _ = system.run(["/usr/bin/systemctl", "daemon-reload"])
            need(code == 0, "systemctl daemon-reload failed")
    except (Refusal, OSError, KeyError, ValueError) as error:
        if json.dumps(record, sort_keys=True) != before:
            save_record(system, record)
        print(f"FAIL {step}: {error}")
        print(f"Run the undo command and paste its output: {undo_command(job.name)}")
        return 1
    print(f"PASS {job.name} installed; units are disabled")
    if enter_credentials and job.credential_step:
        account = "repro-alert" if job.name == "alerts" else ACCOUNT
        print("Enter the private values now. Nothing you type is shown or logged.")
        code, _ = system.run(["/usr/sbin/runuser", "-u", account, "--", *job.credential_step], input_tty=True)
        if code != 0:
            print(f"FAIL credential entry. Run it again later as: sudo runuser -u {account} -- "
                  + " ".join(job.credential_step))
    failures = self_test(job, system, settings, record)
    if failures:
        print(f"Run the undo command and paste its output: {undo_command(job.name)}")
        return 1
    print("Enable the timers only after review: " + job.enable_hint)
    return 0


SANDBOX_READ = (
    "require $argv[1].'/vendor/autoload.php'; $env = Dotenv\\Dotenv::parse(file_get_contents($argv[1].'/.env'));"
    "if (is_file($argv[1].'/bootstrap/cache/config.php')) { require $argv[1].'/bootstrap/cache/config.php'; }"
    "$db = new SQLite3($argv[2], SQLITE3_OPEN_READONLY); $db->busyTimeout(5000);"
    "exit((int) ! ($db->querySingle('SELECT COUNT(*) FROM sqlite_master') > 0));"
)


def self_test(job: Job, system, settings: dict, record: dict) -> int:
    failures = 0

    def report(ok: bool, text: str) -> None:
        nonlocal failures
        failures += 0 if ok else 1
        print(("PASS " if ok else "FAIL ") + text)

    for item in job.files:
        info = system.lstat(item.path)
        uid, gid = system.user_id(item.owner), system.group_id(item.group)
        report(system.read(item.path) == item.data and info is not None and (info.uid, info.gid, info.mode) == (uid, gid, item.mode),
               f"{item.path} bytes, owner and mode")
    for account in job.accounts:
        report(system.user_id(account.name) is not None, f"account {account.name} exists")
    if job.units:
        # Resolve units this job names but a later job installs (the bridge units) from the bundle.
        code, output = system.run(["/usr/bin/systemd-analyze", "verify", *[f"{UNIT_DIR}/{name}" for name in job.units]],
                                  env={"SYSTEMD_UNIT_PATH": f"{UNIT_DIR}:{UNITS}:"})
        report(code == 0 and not output.strip(), "systemd-analyze verify of the installed units")
        for name in job.units:
            if name.endswith((".timer", ".path")):
                code, output = system.run(["/usr/bin/systemctl", "is-enabled", name])
                report(output.strip() != b"enabled", f"{name} is not enabled")
    if job.name == "base-backup":
        sandbox = ["/usr/bin/systemd-run", "--wait", "--pipe", "--quiet", "--collect", "-p", f"User={ACCOUNT}",
                   "-p", f"Group={ACCOUNT}", "-p", "ProtectSystem=strict", "-p", "ProtectHome=read-only",
                   "-p", "PrivateNetwork=yes", "-p", "PrivateTmp=yes", "-p", "NoNewPrivileges=yes",
                   settings["php"], "-d", "display_errors=0", "-r", SANDBOX_READ, settings["app_root"],
                   settings["sqlite_database"]]
        code, _ = system.run(sandbox)
        report(code == 0, "repro-backup reads vendor, .env and the WAL database inside the unit sandbox")
        code, _ = system.run(["/usr/sbin/runuser", "-u", ACCOUNT, "--", "/usr/bin/test", "-w",
                              str(PurePosixPath(settings["sqlite_database"]).parent)])
        report(code != 0, "repro-backup cannot write the database directory")
        code, _ = system.run(["/usr/sbin/runuser", "-u", ACCOUNT, "--", "/usr/bin/test", "-r",
                              f"{STATE}/credentials/rclone-daily.conf"])
        print(("PASS " if code == 0 else "TODO ") + "upload credentials entered (rclone-daily.conf)")
    return failures


def undo(job_name: str, system) -> int:
    print(f"RePro backup host installer: UNDO job {job_name}")
    record = load_record(system, job_name)
    if record is None:
        print(f"PASS nothing recorded for {job_name}; nothing to undo")
        return 0
    for other in JOBS:
        if job_name in REQUIRES[other] and load_record(system, other) is not None:
            print(f"FAIL undo job {other} first; it depends on {job_name}")
            return 1
    failures = 0
    units = [path for path in record["files"] if path.startswith(UNIT_DIR + "/")]
    for path in units:
        name = PurePosixPath(path).name
        system.run(["/usr/bin/systemctl", "disable", "--now", name])
    for key, previous in record["acls"].items():
        path, kind = key.rsplit("|", 1)
        if system.lstat(path) is not None:
            system.set_acl(path, kind == "default", None if previous is None else base64.b64decode(previous))
    for path, digest in record["files"].items():
        current = system.read(path)
        if current is None:
            continue
        if hashlib.sha256(current).hexdigest() != digest:
            print(f"FAIL {path} changed after install; left in place")
            failures += 1
            continue
        system.remove_file(path)
    for path, remove_contents in reversed(record["directories"]):
        if system.lstat(path) is None:
            continue
        if remove_contents or system.is_empty_dir(path):
            try:
                system.remove_tree(path)
            except OSError:
                print(f"FAIL could not remove {path}")
                failures += 1
    for name in reversed(record["users"]):
        if system.user_id(name) is not None:
            system.del_user(name)
    for name in reversed(record["groups"]):
        if system.group_id(name) is not None:
            system.del_group(name)
    if units:
        system.run(["/usr/bin/systemctl", "daemon-reload"])
    if failures == 0:
        system.remove_file(record_path(job_name))
        if not any(system.read(record_path(other)) for other in JOBS):
            system.remove_tree(RECORD_DIR)
    print(("PASS" if failures == 0 else "FAIL") + f" undo of {job_name}")
    return 0 if failures == 0 else 1


def bundle_is_protected() -> bool:
    """Root must not run an installer or tool bytes that another account could have changed."""
    names = set(BASE_TOOLS + ALERT_TOOLS + KIT_TOOLS + MYSQL_TOOLS)
    sources = [folder / name for folder in (HERE, DEPLOY, HERE / "systemd" / "binlog-fixture")
               for name in names if (folder / name).is_file()]
    paths = [HERE, *HERE.parents, DEPLOY, UNITS, Path(__file__), *sources, *UNITS.iterdir()]
    return all(os.lstat(path).st_uid == 0 and not os.lstat(path).st_mode & 0o022 for path in paths)


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--job", choices=JOBS, required=True)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--apply", action="store_true")
    mode.add_argument("--undo", action="store_true")
    parser.add_argument("--settings", type=Path, help="reviewed non-secret settings JSON (not needed for --undo)")
    parser.add_argument("--skip-credentials", action="store_true", help="install without the no-echo prompts")
    args = parser.parse_args(argv)
    system = LinuxSystem()
    try:
        if args.undo or args.apply:
            need(os.geteuid() == 0, "Run --apply and --undo with sudo. Nothing was changed.")
            need(bundle_is_protected(), "Copy the reviewed bundle to a root-owned directory first. Nothing was changed.")
        if args.undo:
            return undo(args.job, system)
        need(args.settings is not None, "--settings is required")
        settings = parse_settings(args.settings.read_bytes())
        job = build_job(args.job, settings)
        if args.apply:
            return apply(job, system, settings, enter_credentials=not args.skip_credentials)
        return preview(job, system, settings)
    except Refusal as error:
        print(f"FAIL {error}")
        return 1


if __name__ == "__main__":
    sys.exit(main())
