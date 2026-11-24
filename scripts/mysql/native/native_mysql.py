#!/usr/bin/env python3
"""Offline-first native MySQL preparation. No downloads, upgrades or auto-start.

`manifest` and `preflight` are read-only. `install` only installs a new service;
`initialize` is a separate, explicit operation on its empty new data directory.
Run installed privileged commands with /usr/bin/python3 -I and absolute paths.
"""
from __future__ import annotations

import argparse
import configparser
import hashlib
import json
import os
import platform
import re
import shutil
import stat
import subprocess
import sys
import time
import uuid
from pathlib import Path, PurePosixPath

VERSION = "8.4.13"
ACCOUNT = "repro-mysql84-native"
UID = GID = 64084
WEB_GID = 33
PREFIX = Path("/opt/repro-mysql84-native")
BINARY = PREFIX / f"mysql-{VERSION}"
OPS = PREFIX / "ops"
ETC = Path("/etc/repro-mysql84-native")
STATE = Path("/var/lib/repro-mysql84-native")
DATA = STATE / "data"
LOG = Path("/var/log/repro-mysql84-native")
RUN = Path("/run/repro-mysql84-native")
SOCKET = RUN / "mysql.sock"
SYSTEMD = Path("/etc/systemd/system")
UNIT = f"{ACCOUNT}.service"
FILES = ("mysql.cnf", UNIT, f"{ACCOUNT}-logrotate.service",
         f"{ACCOUNT}-logrotate.timer", "logrotate.conf", "native_mysql.py")
CLIENTS = ("mysqld", "mysql", "mysqladmin", "mysqlbinlog", "mysqldump")
SERVER_PLUGIN_DIR = Path("lib/mysql/plugin")
SERVER_MESSAGE_DIR = Path("share/mysql-8.4")
SERVER_PRIVATE_LIBRARY = Path("lib/mysql/private/libprotobuf-lite.so.24.4.0")
SERVER_RESOURCES = (
    SERVER_PRIVATE_LIBRARY,
    SERVER_PLUGIN_DIR / "component_validate_password.so",
    SERVER_MESSAGE_DIR / "english/errmsg.sys",
)
SAFE_ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C", "LC_ALL": "C"}
ACL_TOOLS = (Path("/usr/bin/setfacl"), Path("/usr/bin/getfacl"))


class Refusal(RuntimeError):
    pass


def need(condition: bool, message: str) -> None:
    if not condition:
        raise Refusal(message)


def digest(path: Path) -> str:
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def protected(path: Path, *, directory: bool = False) -> None:
    """No symlink or non-root writable component in a privileged input path."""
    need(path.is_absolute(), f"absolute path required: {path}")
    for part in reversed((path, *path.parents)):
        info = part.lstat()
        need(not stat.S_ISLNK(info.st_mode), f"symlink refused: {part}")
        need(info.st_uid == 0 and not info.st_mode & 0o022,
             f"path must be root-owned and not group/other writable: {part}")
    need(path.is_dir() if directory else path.is_file(), f"wrong path type: {path}")


def command(argv: list[str], *, timeout: int = 30) -> str:
    result = subprocess.run(argv, env=SAFE_ENV, text=True, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=timeout, check=False)
    # Do not relay stderr: initialization/clients could include private details.
    need(result.returncode == 0, f"command failed ({result.returncode}): {argv[0]}")
    return result.stdout.strip()


def inventory(bundle: Path) -> dict:
    """Hash all files; allow only relative symlinks contained in this bundle."""
    need(bundle.is_dir() and not bundle.is_symlink(), "bundle must be a real directory")
    root = bundle.resolve()
    entries = {}
    for item in sorted(bundle.rglob("*")):
        rel = item.relative_to(bundle).as_posix()
        info = item.lstat()
        if item.is_symlink():
            target = os.readlink(item)
            need(not os.path.isabs(target), f"absolute bundle symlink: {rel}")
            need(item.resolve().is_relative_to(root) and item.exists(),
                 f"escaping/dangling bundle symlink: {rel}")
            entries[rel] = {"link": target}
        elif item.is_file():
            need(not info.st_mode & (stat.S_ISUID | stat.S_ISGID), f"set-ID file: {rel}")
            entries[rel] = {"sha256": digest(item), "mode": stat.S_IMODE(info.st_mode)}
        else:
            need(item.is_dir(), f"special file in bundle: {rel}")
    for name in CLIENTS:
        need(f"bin/{name}" in entries, f"missing matching client/server: {name}")
        need("sha256" in entries[f"bin/{name}"], f"critical executable must not be a symlink: {name}")
    for path in SERVER_RESOURCES:
        need("sha256" in entries.get(path.as_posix(), {}), f"missing or symlinked Noble server resource: {path}")
    return {"version": VERSION, "files": entries}


def validate_manifest(value: dict) -> None:
    need(value.get("version") == VERSION and isinstance(value.get("files"), dict),
         "manifest version/format mismatch")
    for key in value["files"]:
        path = PurePosixPath(key)
        need(not path.is_absolute() and ".." not in path.parts and "\\" not in key,
             "unsafe manifest path")


def verify_vendor_file_modes(manifest: dict) -> None:
    # File modes are pinned by the original manifest, so reject inaccessible
    # files before installing rather than silently changing signed file modes.
    for name, entry in manifest["files"].items():
        if "sha256" in entry:
            need(entry["mode"] & 0o444 == 0o444, f"vendor file is not service-readable: {name}")
    for name in CLIENTS:
        need(manifest["files"][f"bin/{name}"]["mode"] & 0o111 == 0o111,
             f"vendor executable is not service-executable: {name}")


def normalize_copied_bundle(bundle: Path, manifest: dict) -> None:
    # Only install() calls this, immediately after copytree into its new path.
    # Verify the unmodified copy first. Never chmod the source staging tree or
    # follow a symlink: inventory() rejects links outside the copied bundle.
    need(inventory(bundle) == manifest, "copied bundle verification failed")
    verify_vendor_file_modes(manifest)
    for item in (bundle, *sorted(bundle.rglob("*"))):
        info = item.lstat()
        if stat.S_ISDIR(info.st_mode):
            os.chmod(item, 0o755)
    need(inventory(bundle) == manifest, "normalized bundle verification failed")


def verify_installed_bundle_access(bundle: Path) -> None:
    # This also runs as the service UID. Check directories before hashing files
    # so a root-only directory is an explicit refusal, not a partial inventory.
    def verify(path: Path) -> None:
        info = path.lstat()
        need(info.st_uid == 0 and (stat.S_ISLNK(info.st_mode) or not info.st_mode & 0o022),
             "installed vendor ownership or directory permissions changed")
        if stat.S_ISDIR(info.st_mode):
            need(info.st_mode & 0o555 == 0o555, "installed vendor directory is not service-readable/traversable")
        elif stat.S_ISREG(info.st_mode):
            need(info.st_mode & 0o444 == 0o444, "installed vendor file is not service-readable")
        else:
            need(stat.S_ISLNK(info.st_mode), "special file in installed bundle")

    need(not bundle.is_symlink() and bundle.is_dir(), "installed bundle must be a real directory")
    verify(bundle)
    for item in bundle.rglob("*"):
        verify(item)
    for name in CLIENTS:
        info = (bundle / "bin" / name).lstat()
        need(stat.S_ISREG(info.st_mode) and info.st_mode & 0o111 == 0o111,
             f"installed executable is not service-executable: {name}")


def verify_bundle(bundle: Path, manifest: Path, expected_sha256: str) -> dict:
    need(re.fullmatch(r"[0-9a-fA-F]{64}", expected_sha256) is not None,
         "supply the independently reviewed manifest SHA-256")
    protected(manifest)
    protected(bundle, directory=True)
    need(digest(manifest) == expected_sha256.lower(), "manifest hash mismatch")
    expected = json.loads(manifest.read_text())
    validate_manifest(expected)
    for item in bundle.rglob("*"):
        info = item.lstat()
        need(info.st_uid == 0, f"non-root bundle owner: {item.relative_to(bundle)}")
        if not item.is_symlink():
            need(not info.st_mode & 0o022, f"writable bundle entry: {item.relative_to(bundle)}")
    need(inventory(bundle) == expected, "bundle differs from reviewed manifest")
    verify_vendor_file_modes(expected)
    for name in CLIENTS:
        exe = bundle / "bin" / name
        # Only execute after trusted manifest and ownership checks. No user defaults.
        output = command([str(exe), "--no-defaults", "--version"])
        need(re.search(r"(?<![\d.])" + re.escape(VERSION) + r"(?![\d.])", output) is not None,
             f"wrong {name} version")
        deps = command(["/usr/bin/ldd", str(exe)])
        need("not found" not in deps, f"unresolved libraries for {name}; no system symlink fix")
        if name == "mysqld":
            verify_noble_server_libraries(bundle, deps)
    return expected


def verify_noble_server_libraries(bundle: Path, dependencies: str) -> None:
    need(re.search(r"^\s*libaio\.so\.1t64\s+=>\s+/", dependencies, re.MULTILINE) is not None,
         "Noble server must resolve Ubuntu libaio.so.1t64")
    protobuf = re.search(
        r"^\s*libprotobuf-lite\.so\.24\.4\.0\s+=>\s+(\S+)", dependencies, re.MULTILINE
    )
    need(protobuf is not None and
         Path(protobuf.group(1)).resolve() == (bundle / SERVER_PRIVATE_LIBRARY).resolve(),
         "Noble server must resolve its own bundled private protobuf library")


def verify_package(source: Path) -> None:
    for name in FILES:
        protected(source / name)


def host_identity(expected_host: str, expected_machine: str) -> None:
    need(platform.system() == "Linux" and platform.machine() in ("x86_64", "amd64"),
         "Ubuntu amd64 host required")
    release = dict(line.split("=", 1) for line in Path("/etc/os-release").read_text().splitlines()
                   if "=" in line)
    need(release.get("ID", "").strip('"') == "ubuntu" and
         release.get("VERSION_ID", "").strip('"') == "24.04", "only reviewed Ubuntu 24.04 supported")
    need(platform.node() == expected_host, "hostname differs from approved target")
    need(digest(Path("/etc/machine-id")) == expected_machine.lower(), "machine identity mismatch")
    need(Path("/sys/fs/cgroup/cgroup.controllers").is_file(), "cgroup v2 required")


def absent_targets() -> None:
    for path in (PREFIX, ETC, STATE, LOG, RUN,
                 Path("/var/lib/repro-mysql84-native-logrotate"),
                 *(SYSTEMD / name for name in FILES if name.endswith((".service", ".timer")))):
        need(not os.path.lexists(path), f"target exists; no overwrite/adoption: {path}")


def unused_account() -> None:
    import grp
    import pwd
    for lookup, key in ((pwd.getpwnam, ACCOUNT), (pwd.getpwuid, UID),
                        (grp.getgrnam, ACCOUNT), (grp.getgrgid, GID)):
        try:
            lookup(key)
        except KeyError:
            continue
        raise Refusal(f"account/ID already allocated: {key}")
    need(grp.getgrnam("www-data").gr_gid == WEB_GID, "www-data GID is not 33")


def existing_service() -> dict:
    need(command(["/usr/bin/systemctl", "is-active", "mysql.service"]) == "active",
         "existing mysql.service must remain active")
    values = {key: command(["/usr/bin/systemctl", "show", "mysql.service",
                           f"--property={key}", "--value"])
              for key in ("MainPID", "InvocationID")}
    need(values["MainPID"].isdigit() and int(values["MainPID"]) > 0 and
         re.fullmatch(r"[0-9a-f]{32}", values["InvocationID"]) is not None,
         "cannot identify existing mysql.service process")
    return values


def verify_unit(name: str) -> None:
    fragment = command(["/usr/bin/systemctl", "show", name, "--property=FragmentPath", "--value"])
    overrides = command(["/usr/bin/systemctl", "show", name, "--property=DropInPaths", "--value"])
    need(fragment == str(SYSTEMD / name) and not overrides,
         f"unexpected unit source or drop-ins: {name}")


def verify_acl_tools() -> None:
    for path in ACL_TOOLS:
        need(path.is_file(), f"required ACL tool missing: {path}")
        resolved = path.resolve()
        protected(resolved)
        need(resolved.stat().st_mode & 0o111 == 0o111, f"ACL tool is not executable: {path}")
        need(command([str(path), "--version"]).startswith(path.name + " "),
             f"unexpected ACL tool: {path}")


def preflight(args: argparse.Namespace) -> dict:
    host_identity(args.expect_host, args.machine_id_sha256)
    absent_targets()
    unused_account()
    verify_package(Path(__file__).absolute().parent)
    manifest = verify_bundle(args.bundle, args.manifest, args.manifest_sha256)
    for executable in ("/usr/bin/python3", "/usr/bin/systemctl", "/usr/bin/systemd-analyze",
                       "/usr/sbin/logrotate", "/usr/sbin/useradd", "/usr/sbin/groupadd"):
        protected(Path(executable).resolve())
    verify_acl_tools()
    # Fail closed if the expected unrelated service is not active. Never start it.
    previous = existing_service()
    for name in (UNIT, f"{ACCOUNT}-logrotate.service", f"{ACCOUNT}-logrotate.timer"):
        state = command(["/usr/bin/systemctl", "show", name, "--property=LoadState", "--value"])
        need(state == "not-found", f"unit already supplied by another path: {name}")
    need(shutil.disk_usage("/var/lib").free >= 40 * 1024**3, "less than 40 GiB installation headroom")
    return {"result": "preflight-pass", "host": args.expect_host, "version": VERSION,
            "uid": UID, "gid": GID, "bundle_files": len(manifest["files"]),
            "existing_mysql": previous, "provisioned": False,
            "limits": "installation checks only; capacity, backup and runtime acceptance remain pending"}


def root_only() -> None:
    need(platform.system() == "Linux" and os.geteuid() == 0, "administrator/root execution required")
    verify_package(Path(__file__).absolute().parent)


def mkdir(path: Path, mode: int, uid: int = 0, gid: int = 0) -> None:
    path.mkdir(mode=mode)
    os.chown(path, uid, gid)
    os.chmod(path, mode)


def install(args: argparse.Namespace) -> dict:
    root_only()
    report = preflight(args)
    source = Path(__file__).absolute().parent
    os.umask(0o077)
    # All destinations were absent. Failure leaves a disabled partial install for
    # administrator inspection; never remove an account/directory automatically.
    command(["/usr/sbin/groupadd", "--gid", str(GID), ACCOUNT])
    command(["/usr/sbin/useradd", "--uid", str(UID), "--gid", str(GID), "--no-create-home",
             "--home-dir", str(STATE), "--shell", "/usr/sbin/nologin", ACCOUNT])
    mkdir(PREFIX, 0o755)
    shutil.copytree(args.bundle, BINARY, symlinks=True)
    normalize_copied_bundle(BINARY, json.loads(args.manifest.read_text()))
    verify_installed_bundle_access(BINARY)
    mkdir(OPS, 0o755)
    for name in FILES:
        shutil.copyfile(source / name, OPS / name)
        os.chmod(OPS / name, 0o644)
    shutil.copyfile(args.manifest, OPS / "bundle-manifest.json")
    os.chmod(OPS / "bundle-manifest.json", 0o644)
    mkdir(ETC, 0o750, 0, GID)
    mkdir(STATE, 0o700, UID, GID)
    mkdir(DATA, 0o700, UID, GID)
    mkdir(STATE / "tmp", 0o700, UID, GID)
    mkdir(LOG, 0o700, UID, GID)
    for name in ("mysql.cnf", "logrotate.conf"):
        shutil.copyfile(source / name, ETC / name)
        os.chown(ETC / name, 0, GID)
        os.chmod(ETC / name, 0o640)
    receipt = {"version": VERSION, "host": args.expect_host,
               "machine_id_sha256": args.machine_id_sha256.lower(),
               "bundle_manifest_sha256": args.manifest_sha256.lower(),
               "package": {name: digest(source / name) for name in FILES}}
    (ETC / "install-receipt.json").write_text(json.dumps(receipt, indent=2) + "\n")
    os.chown(ETC / "install-receipt.json", 0, GID)
    os.chmod(ETC / "install-receipt.json", 0o640)
    for name in FILES:
        if name.endswith((".service", ".timer")):
            shutil.copyfile(source / name, SYSTEMD / name)
            os.chmod(SYSTEMD / name, 0o644)
    command(["/usr/bin/systemd-analyze", "verify", str(SYSTEMD / UNIT),
             str(SYSTEMD / f"{ACCOUNT}-logrotate.service"), str(SYSTEMD / f"{ACCOUNT}-logrotate.timer")])
    command(["/usr/bin/systemctl", "daemon-reload"])
    for name in FILES:
        if name.endswith((".service", ".timer")):
            verify_unit(name)
    need(existing_service() == report["existing_mysql"],
         "existing service process changed; investigate without auto-restarting it")
    report.update(result="installed-disabled", provisioned=True, initialized=False, started=False)
    return report


def installed_receipt() -> dict:
    protected(ETC / "install-receipt.json")
    receipt = json.loads((ETC / "install-receipt.json").read_text())
    host_identity(receipt["host"], receipt["machine_id_sha256"])
    verify_package(OPS)
    for name, expected in receipt["package"].items():
        need(digest(OPS / name) == expected, f"installed operation changed: {name}")
        if name.endswith((".service", ".timer")):
            protected(SYSTEMD / name)
            need(digest(SYSTEMD / name) == expected, f"installed unit changed: {name}")
            verify_unit(name)
    need(digest(ETC / "mysql.cnf") == receipt["package"]["mysql.cnf"], "server configuration drift")
    protected(ETC / "mysql.cnf")
    protected(ETC / "logrotate.conf")
    need(digest(ETC / "logrotate.conf") == receipt["package"]["logrotate.conf"], "log rotation drift")
    need(digest(OPS / "bundle-manifest.json") == receipt["bundle_manifest_sha256"], "manifest drift")
    manifest = json.loads((OPS / "bundle-manifest.json").read_text())
    verify_installed_bundle_access(BINARY)
    need(inventory(BINARY) == manifest, "installed vendor files or libraries changed")
    for name in CLIENTS:
        exe = BINARY / "bin" / name
        protected(exe)
        need(digest(exe) == manifest["files"][f"bin/{name}"]["sha256"], f"binary changed: {name}")
    import pwd
    import grp
    user = pwd.getpwnam(ACCOUNT)
    need(user.pw_uid == UID and user.pw_gid == GID and user.pw_shell == "/usr/sbin/nologin",
         "service account drift")
    need(grp.getgrnam(ACCOUNT).gr_gid == GID and grp.getgrnam("www-data").gr_gid == WEB_GID,
         "service group drift")
    for path in (STATE, DATA, STATE / "tmp", LOG):
        info = path.lstat()
        need(stat.S_ISDIR(info.st_mode) and info.st_uid == UID and info.st_gid == GID and
             stat.S_IMODE(info.st_mode) == 0o700,
             f"private data/log directory changed: {path}")
    return receipt


def disk_uuid() -> str:
    path = DATA / "auto.cnf"
    need(path.is_file() and not path.is_symlink(), "missing/unsafe server identity file")
    config = configparser.ConfigParser(interpolation=None)
    config.read(path)
    return str(uuid.UUID(config["auto"]["server-uuid"]))


def initialize() -> dict:
    root_only()
    installed_receipt()
    need(not any(DATA.iterdir()), "data directory is not empty; initialization refused")
    need(not os.path.lexists(ETC / "identity.json"), "identity already pinned")
    need(not os.path.lexists(RUN), "runtime directory already exists")
    # Vendor guidance: bootstrap with directory/user options, not the complete
    # runtime config. Override the Noble build's packaged resource directories.
    command([str(BINARY / "bin/mysqld"), "--no-defaults", "--initialize",
             f"--basedir={BINARY}", f"--plugin-dir={BINARY / SERVER_PLUGIN_DIR}",
             f"--lc-messages-dir={BINARY / SERVER_MESSAGE_DIR}",
             f"--datadir={DATA}", f"--tmpdir={STATE / 'tmp'}",
             f"--log-error={LOG / 'error.log'}", f"--user={ACCOUNT}"], timeout=180)
    identity = {"server_uuid": disk_uuid(), "version": VERSION, "datadir": str(DATA) + "/"}
    with (ETC / "identity.json").open("x") as stream:
        json.dump(identity, stream, indent=2)
        stream.write("\n")
    os.chown(ETC / "identity.json", 0, GID)
    os.chmod(ETC / "identity.json", 0o640)
    return {"result": "initialized-not-started", "server_uuid": identity["server_uuid"],
            "temporary_root_password": "private error log only; never paste its contents"}


def verify_start() -> dict:
    installed_receipt()
    protected(ETC / "identity.json")
    identity = json.loads((ETC / "identity.json").read_text())
    need(identity == {"server_uuid": disk_uuid(), "version": VERSION, "datadir": str(DATA) + "/"},
         "data directory identity mismatch")
    need(not (DATA / "mysqld-auto.cnf").exists(), "persisted settings file refused")
    return identity


def wait_ready() -> dict:
    # mysqladmin ping can report alive despite access denied. This checks process
    # availability only; `check` is the separate authenticated acceptance gate.
    deadline = time.monotonic() + 120
    while time.monotonic() < deadline:
        try:
            command([str(BINARY / "bin/mysqladmin"), "--no-defaults", "--no-login-paths",
                     "--protocol=SOCKET", f"--socket={SOCKET}", "--connect-timeout=2", "ping"], timeout=4)
            return {"result": "server-responding", "authenticated_acceptance": False}
        except (Refusal, subprocess.TimeoutExpired):
            time.sleep(1)
    raise Refusal("server did not respond within startup deadline")


def sql(query: str) -> str:
    client = ETC / "maintenance.cnf"
    protected(client)
    need(stat.S_IMODE(client.stat().st_mode) == 0o600, "maintenance credential must be root-only 0600")
    return command([str(BINARY / "bin/mysql"), f"--defaults-file={client}", "--no-login-paths",
                    "--protocol=SOCKET", f"--socket={SOCKET}", "--connect-timeout=5",
                    "--batch", "--skip-column-names", f"--execute={query}"], timeout=10)


def validate_live_row(row: str, identity: dict) -> None:
    values = row.split("\t")
    expected = [identity["server_uuid"], VERSION, identity["datadir"], str(SOCKET),
                "1", "1", "1", "0", "OFF", "ON", "ON", "0", "1", "ON", "0"]
    need(values == expected, "authenticated identity/durability/isolation check failed")


def verify_runtime_access() -> None:
    # Called only after startup, never by verify-start/wait-ready: the named
    # traversal ACL is granted by the final ExecStartPost command.
    info = RUN.lstat()
    need(stat.S_ISDIR(info.st_mode) and info.st_uid == UID and info.st_gid == GID and
         stat.S_IMODE(info.st_mode) == 0o750, "runtime directory ownership or mode changed")
    info = SOCKET.lstat()
    need(stat.S_ISSOCK(info.st_mode) and info.st_uid == UID and info.st_gid == GID and
         stat.S_IMODE(info.st_mode) == 0o777, "runtime socket ownership or mode changed")
    verify_acl_tools()
    acl = command(["/usr/bin/getfacl", "--omit-header", "--numeric", "--absolute-names", str(RUN)])
    expected = ["user::rwx", "group::r-x", f"group:{WEB_GID}:--x", "mask::r-x", "other::---"]
    need(sorted(acl.splitlines()) == sorted(expected),
         "runtime directory must have only the exact www-data traversal ACL; no extra or default ACLs")


def check(flush: bool = False) -> dict:
    root_only()
    identity = verify_start()
    verify_runtime_access()
    query = ("SELECT @@server_uuid,VERSION(),@@datadir,@@socket,@@skip_networking,"
             "@@innodb_flush_log_at_trx_commit,@@sync_binlog,@@local_infile,"
             "@@event_scheduler,@@gtid_mode,@@enforce_gtid_consistency,@@persisted_globals_load,"
             "@@require_secure_transport,@@innodb_doublewrite,@@binlog_expire_logs_auto_purge")
    validate_live_row(sql(query), identity)
    if flush:
        sql("FLUSH ERROR LOGS; FLUSH SLOW LOGS")
    return {"result": "authenticated-check-pass", "server_uuid": identity["server_uuid"],
            "logs_reopened": flush}


def directory_metadata(path: Path) -> tuple[os.stat_result, int]:
    """Read directory metadata and its actual mount through no-follow descriptors."""
    need(path.is_absolute() and path.resolve(strict=True) == path,
         "storage directory must be an exact canonical path")
    flags = os.O_PATH | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    descriptor = os.open("/", flags)
    try:
        for part in path.parts[1:]:
            next_descriptor = os.open(part, flags, dir_fd=descriptor)
            os.close(descriptor)
            descriptor = next_descriptor
        info = os.fstat(descriptor)
        with Path(f"/proc/self/fdinfo/{descriptor}").open("r", encoding="ascii") as stream:
            metadata = stream.read(8193)
        identifiers = re.findall(r"^mnt_id:\s*([0-9]+)$", metadata, re.MULTILINE)
        need(len(metadata) <= 8192 and len(identifiers) == 1 and int(identifiers[0]) > 0,
             "directory mount identity unavailable")
        return info, int(identifiers[0])
    finally:
        os.close(descriptor)


def storage_mounts() -> tuple[str, list[dict]]:
    """Read this namespace's mount table; no mount or filesystem command runs."""
    with Path("/proc/self/mountinfo").open("r", encoding="utf-8") as stream:
        raw = stream.read(1024 * 1024 + 1)
    need(len(raw) <= 1024 * 1024, "mount table exceeds acceptance bound")
    mounts = []
    for line in raw.splitlines():
        fields = line.split(" ")
        need(len(fields) >= 10 and "-" in fields[6:] and fields[0].isdigit() and
             re.fullmatch(r"[0-9]+:[0-9]+", fields[2]) is not None,
             "malformed mount metadata")
        root, target = (re.sub(r"\\([0-7]{3})", lambda match: chr(int(match[1], 8)), field)
                        for field in (fields[3], fields[4]))
        need(root.startswith("/") and target.startswith("/"), "nonabsolute mount metadata")
        mounts.append({"id": int(fields[0]), "device": fields[2], "root": root, "target": target})
    need(bool(mounts), "mount metadata unavailable")
    return raw, mounts


def storage_check(originals_mount: str, originals_uuid: str) -> dict:
    """Metadata-only acceptance; never grant PHP traversal into private data."""
    root_only()
    need(sys.platform == "linux", "storage acceptance requires Linux mount metadata")
    mount = Path(originals_mount)
    need(mount.is_absolute() and mount != Path("/") and str(mount) == originals_mount
         and ".." not in mount.parts,
         "originals mount must be an exact canonical non-root path")
    need(re.fullmatch(r"[A-Za-z0-9-]{1,128}", originals_uuid) is not None,
         "supply the independently reviewed originals filesystem UUID")
    identity = verify_start()
    before, mounts = storage_mounts()
    data_info, data_mount_id = directory_metadata(DATA)
    originals_info, originals_mount_id = directory_metadata(mount)
    uuid_path = Path("/dev/disk/by-uuid") / originals_uuid
    device = uuid_path.resolve(strict=True)
    device_info = device.stat()
    need(stat.S_ISBLK(device_info.st_mode), "originals UUID must resolve to a block device")
    originals_device = f"{os.major(originals_info.st_dev)}:{os.minor(originals_info.st_dev)}"
    need(device_info.st_rdev == originals_info.st_dev, "originals filesystem UUID does not match mounted storage")
    exact = [item for item in mounts if item["id"] == originals_mount_id]
    need(len(exact) == 1 and exact[0]["target"] == originals_mount
         and exact[0]["device"] == originals_device and exact[0]["root"] == "/",
         "originals mount is unavailable, redirected or a subtree bind")
    # Device comparison also catches bind aliases: their st_dev is unchanged.
    need(data_info.st_dev != originals_info.st_dev,
         "MySQL data and originals must be on different filesystems, including bind aliases")
    # Refuse ambiguous aliases for this independently reviewed originals volume.
    need(sum(item["device"] == originals_device for item in mounts) == 1,
         "originals filesystem has ambiguous additional mounts or bind aliases")
    data_mounts = [item for item in mounts if item["id"] == data_mount_id]
    need(len(data_mounts) == 1, "MySQL data mount metadata unavailable or ambiguous")
    effective = data_mounts[0]
    need(effective["device"] == f"{os.major(data_info.st_dev)}:{os.minor(data_info.st_dev)}"
         and effective["root"] == "/", "MySQL data mount differs or is a subtree bind")
    after, _ = storage_mounts()
    need(before == after and directory_metadata(DATA) == (data_info, data_mount_id)
         and directory_metadata(mount) == (originals_info, originals_mount_id)
         and uuid_path.resolve(strict=True) == device and device.stat() == device_info
         and verify_start() == identity,
         "storage or instance identity changed during acceptance")
    return {"result": "storage-separation-check-pass", "server_uuid": identity["server_uuid"],
            "datadir": str(DATA), "datadir_device": effective["device"],
            "originals_mount": originals_mount, "reviewed_originals_uuid_mapping": originals_uuid,
            "originals_device": originals_device, "different_filesystems": True,
            "read_only": True, "mysql_running_required": False,
            "limits": "point-in-time filesystem separation only; no capacity, write, boot or alert proof"}


def parser() -> argparse.ArgumentParser:
    cli = argparse.ArgumentParser(description=__doc__)
    sub = cli.add_subparsers(dest="action", required=True)
    sub.add_parser("manifest").add_argument("bundle", type=Path)
    for action in ("preflight", "install"):
        cmd = sub.add_parser(action)
        cmd.add_argument("--bundle", type=Path, required=True)
        cmd.add_argument("--manifest", type=Path, required=True)
        cmd.add_argument("--manifest-sha256", required=True)
        cmd.add_argument("--expect-host", required=True)
        cmd.add_argument("--machine-id-sha256", required=True)
    for action in ("initialize", "verify-start", "wait-ready", "check", "flush-logs"):
        sub.add_parser(action)
    storage = sub.add_parser("storage-check")
    storage.add_argument("--originals-mount", required=True)
    storage.add_argument("--originals-uuid", required=True)
    return cli


def main() -> int:
    args = parser().parse_args()
    try:
        if args.action == "manifest":
            result = inventory(args.bundle)
        elif args.action == "preflight":
            result = preflight(args)
        elif args.action == "install":
            result = install(args)
        elif args.action == "storage-check":
            result = storage_check(args.originals_mount, args.originals_uuid)
        else:
            result = {"initialize": initialize, "verify-start": verify_start,
                      "wait-ready": wait_ready, "check": check,
                      "flush-logs": lambda: check(True)}[args.action]()
        print(json.dumps(result, indent=2, sort_keys=True))
        return 0
    except (Refusal, OSError, ValueError, KeyError, subprocess.TimeoutExpired) as error:
        print(json.dumps({"result": "REFUSED", "reason": str(error)}), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
