"""Isolation checks and init for the cutover namespace sandbox (design 7.3).

run-sandbox.sh calls this module; nobody else should:

    prepare --root ROOT                  host namespace: validate the sandbox root, lay it out
                                         (refusing any symbolic link or file in the layout)
    arm --root ROOT [--reboot]           host namespace, under the launch lock: layout again, no
                                         shadow of the launcher and no loader file in the copy-on-write
                                         layers, boot ID, the checker copy ROOT/sync/sandbox_check.py,
                                         canaries, host facts; prints the launch token and the copy's
                                         sha256
    outside --root ROOT --token T        host namespace, while the sandbox waits: the canary check
                                         from outside; writes go (exit 0) or stop
    init --root ROOT --token T -- CMD    PID 1 inside the new namespaces, run from the checker copy:
                                         the inside checks, then CMD as a child; reaps orphans;
                                         stops everything at the end

The controller's fixed paths (cli.py, the guard, admit.py) and its /proc scans
mean a "sandbox with private paths" would act on production (critic HO-H2).
Nothing runs inside until both halves agree that every fixed path is the
sandbox's own:

- outside writes a canary into each sandbox directory before the namespaces
  exist; inside must read that exact canary at each fixed path (a missing bind
  is caught here, before anything inside writes);
- inside then writes a second canary at each fixed path; outside must find it
  in the sandbox directory and nowhere on the host path;
- PID 1 inside is this init, the PID, network and mount namespaces differ from
  the host's, /run, /tmp and /dev/shm are private, /etc, /var, /opt, /home and
  /root are copy-on-write views, /, /usr and /boot are read-only, ROOT's
  top-level directory shows only the path to ROOT, the boot ID is the
  sandbox's, the service managers are the refusing shim, and the only network
  interface is loopback.

Any failure stops the run with exit 96 before the command starts. PID 1 runs
the copy arm wrote for this launch (the launcher checks its sha256 first), so
a file left in a copy-on-write layer never replaces these checks; and the
launcher counts a run only when `outside` gave its go.
Standard library only; runs as /usr/bin/python3 -I -S as root.
"""

from __future__ import annotations

import argparse
import errno
import hashlib
import json
import os
import re
import shutil
import signal
import socket
import stat
import struct
import subprocess
import sys
import time
import uuid
from pathlib import Path

HERE = Path(__file__).resolve().parent
USAGE = 2
REFUSED = 96
SHIM_REFUSED = 97
COMMAND_NOT_FOUND = 127

# Design 7.3: every fixed directory the controller, the guard, admit.py, nginx or Deploy-App
# reads or writes, with the mode a fresh sandbox directory gets (as production creates them).
DIRECTORIES = {
    "/etc/repro-cutover-fence": 0o755,
    "/var/lib/repro-cutover-fence": 0o700,
    "/var/lib/repro-cutover-archive": 0o700,
    "/var/lib/repro-cutover-keep": 0o700,
    "/run/repro-cutover-fence": 0o755,
    "/run/repro-cutover-ingress": 0o755,
    "/etc/repro-database-cutover": 0o755,
    "/opt/repro-cutover-fence": 0o755,
    "/run/repro-telnyx-capture": 0o755,
    "/etc/repro-telnyx-capture": 0o755,
}
# A simulated reboot empties these, as a real boot empties /run.
RUNTIME = tuple(path for path in DIRECTORIES if path.startswith("/run/"))
BOOT_ID = Path("/proc/sys/kernel/random/boot_id")
SHIMS = ("/usr/bin/systemctl", "/usr/bin/systemd-run", "/usr/bin/supervisorctl", "/usr/sbin/service")
# Host trees seen copy-on-write inside, so a missing fixed directory gets its mount point in the
# sandbox and a stray write (app tree, deploy locks, Studio files, root's dot files) never reaches
# the host.
COPY_ON_WRITE = ("/etc", "/var", "/opt", "/home", "/root")
# Read by the dynamic loader at every program start (glibc); one left in the /etc layer by a launch would
# load into the inside half's own programs at every later launch.
LOADER_FILES = ("ld.so.preload", "ld.so.cache")
# Fresh per launch: host unix sockets (systemd, D-Bus, Supervisor, PHP-FPM, MySQL) live here and
# a network namespace does not hide a socket that has a path; /dev/shm is POSIX shared memory.
PRIVATE = ("/run", "/tmp", "/dev/shm")
# Read-only inside: the root file system (top-level directories, /mnt, /srv, /media, merged /bin
# and /lib) and /usr and /boot, which may be file systems of their own. W1 installs into
# /usr/local/sbin; a drill script must never change those files on the host.
READ_ONLY = ("/", "/usr", "/boot")
SYSTEM = ("/proc", "/sys", "/dev", "/usr", "/boot", "/bin", "/sbin", "/lib", "/lib32", "/lib64", "/libx32")
CANARY = ".repro-sandbox-canary"
CANARY_IN = ".repro-sandbox-canary-in"
WAIT_SECONDS = 60
POLL_SECONDS = 0.05
STOP_GRACE_SECONDS = 10
# struct ifreq: 16-byte name, then a union whose first member is the flags short (40 bytes).
IFREQ = "16sH22x"
SIOCGIFFLAGS, SIOCSIFFLAGS, IFF_UP = 0x8913, 0x8914, 0x1


class Refused(RuntimeError):
    def __init__(self, message: str, code: int = REFUSED):
        super().__init__(message)
        self.code = code


def require(condition: bool, message: str, code: int = REFUSED) -> None:
    if not condition:
        raise Refused(message, code)


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def _sync_directory(directory: Path) -> None:
    descriptor = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def _write_atomic(path: Path, content: bytes, mode: int) -> None:
    temporary = path.with_name(f".{path.name}.{uuid.uuid4().hex}")
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, mode)
    try:
        with os.fdopen(descriptor, "wb") as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, path)
        _sync_directory(path.parent)
    finally:
        temporary.unlink(missing_ok=True)


def _read(path: Path) -> bytes | None:
    """A regular file's content; None when it is missing, a symbolic link or not a regular file."""
    try:
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK)
    except OSError:
        return None
    with os.fdopen(descriptor, "rb") as stream:
        if not stat.S_ISREG(os.fstat(stream.fileno()).st_mode):
            return None
        return stream.read(64 * 1024)


def _inside_tree(path: str, tree: str) -> bool:
    return path == tree or path.startswith(tree.rstrip("/") + "/")


def source(root: Path, target: str) -> Path:
    """The sandbox directory bound over a fixed path."""
    return root / "binds" / target.lstrip("/")


# The sandbox root.

def validate_root(value: str) -> Path:
    require(re.fullmatch(r"(?:/[A-Za-z0-9_][A-Za-z0-9._-]*)+", value) is not None,
            f"The sandbox root {value!r} must be a plain absolute path", USAGE)
    # Inside, ROOT's top-level directory shows only the path to ROOT.
    require(value.count("/") >= 2,
            "The sandbox root must lie below a top-level directory, for example /srv/repro-cutover-sandbox", USAGE)
    for tree in COPY_ON_WRITE + PRIVATE + SYSTEM:
        require(not _inside_tree(value, tree),
                f"The sandbox root must lie outside {tree}, which the sandbox replaces or shares", USAGE)
    require(os.path.realpath(value) == value, "The sandbox root must not contain symbolic links", USAGE)
    root = Path(value)
    try:
        info = os.lstat(root)
    except FileNotFoundError:
        raise Refused(f"The sandbox root {value} does not exist; create it with install -d -m 0711", USAGE) from None
    # 0700, or 0711/0755 when sandbox services running as www-data need the app copy under it.
    require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
            "The sandbox root must be a root-owned directory not writable by group or others", USAGE)
    for parent in root.parents:
        info = os.lstat(parent)
        require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
                f"Every parent of the sandbox root must be root-owned and not writable by others: {parent}", USAGE)
    return root


def _directory(root: Path, relative: str, mode: int) -> tuple[int, bool]:
    """A descriptor for the directory ROOT/relative and whether its last component was created.

    Missing components are created with `mode`. The root is writable from inside the sandbox, so
    a symbolic link or anything but a directory at any component is refused: every component is
    opened with O_NOFOLLOW relative to its parent, and callers change owner and mode through the
    descriptor, never through a path."""
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    descriptor, created, walked = os.open(root, flags), False, root
    try:
        for name in Path(relative).parts:
            walked = walked / name
            try:
                os.mkdir(name, mode, dir_fd=descriptor)
                created = True
            except FileExistsError:
                created = False
            try:
                child = os.open(name, flags, dir_fd=descriptor)
            except OSError as error:
                if error.errno in (errno.ELOOP, errno.ENOTDIR):
                    raise Refused(f"{walked} must be a directory, not a symbolic link or another file") from None
                raise
            os.close(descriptor)
            descriptor = child
    except BaseException:
        os.close(descriptor)
        raise
    return descriptor, created


def prepare(root: Path) -> None:
    """Lay out the sandbox root. Idempotent; never touches a host path, never follows a link."""
    for tree in COPY_ON_WRITE + PRIVATE:
        require(os.path.isdir(tree) and not os.path.islink(tree), f"The host has no directory {tree}")
    for folder in ("binds", "cow", "cow/upper", "cow/work", "boot", "shims", "sync"):
        os.close(_directory(root, folder, 0o700)[0])
    for target, mode in DIRECTORIES.items():
        descriptor, created = _directory(root, str(source(root, target).relative_to(root)), 0o755)
        try:
            if created:
                os.fchmod(descriptor, mode)
        finally:
            os.close(descriptor)
    for tree in COPY_ON_WRITE:
        upper = _directory(root, f"cow/upper{tree}", 0o755)[0]
        try:
            # The merged directory shows the upper directory's owner and mode; the guard checks them.
            host = os.stat(tree)
            os.fchown(upper, host.st_uid, host.st_gid)
            os.fchmod(upper, stat.S_IMODE(host.st_mode))
        finally:
            os.close(upper)
        os.close(_directory(root, f"cow/work{tree}", 0o700)[0])


def _shadow(root: Path) -> Path | None:
    """The copy-on-write entry that would hide the launcher's own files inside, if any."""
    for tree in COPY_ON_WRITE:
        if _inside_tree(str(HERE), tree):
            entry = root / "cow" / "upper" / str(HERE).lstrip("/")
            return entry if os.path.lexists(entry) else None
    return None


def _shadow_refusal(shadow: Path) -> str:
    hint = ""
    if stat.S_ISDIR(os.lstat(shadow).st_mode) and os.path.lexists(shadow / "__pycache__"):
        hint = (f". {shadow}/__pycache__ is Python's bytecode cache, written when Python inside imported from "
                f"{HERE}: run Python inside with -B, and make_config.py in the host namespace "
                "(python3 -I -S -B, README)")
    return (f"The sandbox's copy-on-write layer holds {shadow}, which shadows the launcher's own files at {HERE} "
            f"inside{hint}. Inspect it, then remove it from the sandbox root (rm -r -- {shadow})")


def _loader_file(root: Path) -> Path | None:
    """A dynamic loader file in the /etc layer, which every program inside would read, if any."""
    for name in LOADER_FILES:
        entry = root / "cow" / "upper" / "etc" / name
        if os.path.lexists(entry):
            return entry
    return None


def arm(root: Path, reboot: bool) -> str:
    """Per-launch state under the launch lock: boot ID, the checker copy, canaries and the host facts.

    Prints the launch token and the sha256 of the checker copy that PID 1 must run."""
    # prepare ran before the launch lock was taken, perhaps while an earlier sandbox could still
    # write into the root; check the layout again now that none can.
    prepare(root)
    # Installed under /opt (README), the launcher's files are seen through the sandbox's /opt
    # overlay; a write there inside one launch stays in the upper layer for every later one.
    shadow = _shadow(root)
    if shadow is not None:
        raise Refused(_shadow_refusal(shadow))
    # A preload or loader cache written inside persists in the /etc layer, and the loader would read it into
    # the inside half's own programs (mount, sha256sum, PID 1's python3) at this and every later launch.
    loader = _loader_file(root)
    require(loader is None, f"The sandbox's copy-on-write layer holds {loader}, which the dynamic loader would read "
                            f"into every program inside, the checks included (a preload, or ldconfig run inside). "
                            f"Inspect it, then remove it from the sandbox root (rm -- {loader})")
    boot = root / "boot" / "boot_id"
    if reboot or not boot.exists():
        _write_atomic(boot, f"{uuid.uuid4()}\n".encode("ascii"), 0o444)
    if reboot:
        for target in RUNTIME:
            directory = source(root, target)
            for entry in directory.iterdir():
                if entry.is_dir() and not entry.is_symlink():
                    shutil.rmtree(entry)
                else:
                    entry.unlink()
    shim = HERE / "shims" / "refuse"
    refuse_raw = shim.read_bytes()
    _write_atomic(root / "shims" / "refuse", refuse_raw, 0o755)
    for name in ("ready", "go", "stop", "launch.json"):
        (root / "sync" / name).unlink(missing_ok=True)
    # PID 1 runs this copy, read here in the host namespace, never the file under an overlay.
    checker_raw = (HERE / "sandbox_check.py").read_bytes()
    _write_atomic(root / "sync" / "sandbox_check.py", checker_raw, 0o600)
    token = uuid.uuid4().hex
    for target in DIRECTORIES:
        directory = source(root, target)
        (directory / CANARY_IN).unlink(missing_ok=True)
        _write_atomic(directory / CANARY, f"out:{token}\n".encode("ascii"), 0o644)
    host = {
        "boot_id": BOOT_ID.read_text(encoding="ascii").strip(),
        "namespaces": {name: os.readlink(f"/proc/self/ns/{name}") for name in ("pid", "net", "mnt")},
        "devices": {tree: [os.stat(tree).st_dev, os.stat(tree).st_ino] for tree in COPY_ON_WRITE + PRIVATE},
        "root": [os.stat(root).st_dev, os.stat(root).st_ino],
        "shims": {path: (sha256(Path(path).read_bytes()) if os.path.exists(path) else None) for path in SHIMS},
    }
    launch = {"version": 1, "token": token, "root": str(root), "boot_id": boot.read_text(encoding="ascii").strip(),
              "refuse_sha256": sha256(refuse_raw), "checker_sha256": sha256(checker_raw), "host": host}
    _write_atomic(root / "sync" / "launch.json", (json.dumps(launch, sort_keys=True) + "\n").encode("utf-8"), 0o600)
    return f"{token} {launch['checker_sha256']}"


def read_launch(root: Path, token: str) -> dict:
    raw = _read(root / "sync" / "launch.json")
    require(raw is not None, "The launch record is missing")
    launch = json.loads(raw)
    require(isinstance(launch, dict) and launch.get("version") == 1 and launch.get("token") == token
            and launch.get("root") == str(root), "The launch record belongs to another launch")
    return launch


def _wait_for(paths: tuple[Path, ...], seconds: float) -> Path | None:
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        for path in paths:
            if path.exists():
                return path
        time.sleep(POLL_SECONDS)
    return None


# Outside: the host namespace.

def outside(root: Path, token: str) -> None:
    launch = read_launch(root, token)
    sync = root / "sync"
    try:
        ready = _wait_for((sync / "ready",), WAIT_SECONDS)
        require(ready is not None, "The sandbox did not report ready")
        report = json.loads(ready.read_bytes())
        require(isinstance(report, dict) and report.get("token") == token, "The ready report belongs to another launch")
        failures = []
        for target in DIRECTORIES:
            host = Path(target)
            for name in (CANARY, CANARY_IN):
                leaked = host / name
                if os.path.lexists(leaked):
                    failures.append(f"a canary reached the host path {leaked}")
                    # Remove only our own canary: a regular file holding this launch's token.
                    content = _read(leaked)
                    if content in (f"out:{token}\n".encode("ascii"), f"in:{token}\n".encode("ascii")):
                        leaked.unlink()
            if _read(source(root, target) / CANARY_IN) != f"in:{token}\n".encode("ascii"):
                failures.append(f"the inside canary for {target} never reached its sandbox directory")
        if BOOT_ID.read_text(encoding="ascii").strip() != launch["host"]["boot_id"]:
            failures.append("the host boot ID changed")
        for path, expected in launch["host"]["shims"].items():
            current = sha256(Path(path).read_bytes()) if os.path.exists(path) else None
            if current != expected:
                failures.append(f"the host's {path} changed")
        require(not failures, "canary check failed: " + "; ".join(failures))
        for target in DIRECTORIES:
            for name in (CANARY, CANARY_IN):
                (source(root, target) / name).unlink(missing_ok=True)
    except BaseException:
        _write_atomic(sync / "stop", b"stop\n", 0o600)
        raise
    _write_atomic(sync / "go", b"go\n", 0o600)


# Inside: PID 1 of the new namespaces.

def _mounts() -> dict[str, tuple[str, str]]:
    """Mount point -> (file system type, super options) of the topmost mount, from mountinfo."""
    found = {}
    for line in Path("/proc/self/mountinfo").read_text(encoding="utf-8").splitlines():
        left, _, right = line.partition(" - ")
        fields, extra = left.split(), right.split()
        point = re.sub(r"\\([0-7]{3})", lambda match: chr(int(match.group(1), 8)), fields[4])
        found[point] = (extra[0], extra[2] if len(extra) > 2 else "")
    return found


def inside_checks(root: Path, token: str, launch: dict) -> list[str]:
    failures = []
    host = launch["host"]
    own = [os.fsencode(argument) for argument in sys.orig_argv]
    if os.getpid() != 1 or os.readlink("/proc/self") != "1" \
            or Path("/proc/1/cmdline").read_bytes().split(b"\0")[:-1] != own:
        failures.append("PID 1 is not the sandbox init with its own /proc")
    for name in ("pid", "net", "mnt"):
        if os.readlink(f"/proc/self/ns/{name}") == host["namespaces"][name]:
            failures.append(f"the {name} namespace is the host's")
    mounts = _mounts()
    for tree in COPY_ON_WRITE:
        kind, options = mounts.get(tree, ("", ""))
        if kind != "overlay" or f"upperdir={root}/cow/upper{tree}" not in options.split(","):
            failures.append(f"{tree} is not the sandbox's copy-on-write view")
    for tree in PRIVATE:
        info = os.stat(tree)
        if mounts.get(tree, ("",))[0] != "tmpfs" or [info.st_dev, info.st_ino] == host["devices"][tree]:
            failures.append(f"{tree} is not private to the sandbox")
    for tree in READ_ONLY:
        if os.path.isdir(tree) and not os.statvfs(tree).f_flag & os.ST_RDONLY:
            failures.append(f"{tree} is writable inside")
    parts = root.parts[1:]
    top = "/" + parts[0]
    listing = [sorted(os.listdir("/" + "/".join(parts[:depth]))) for depth in range(1, len(parts))]
    info = os.stat(root)
    if mounts.get(top, ("",))[0] != "tmpfs" or not os.statvfs(top).f_flag & os.ST_RDONLY \
            or listing != [[part] for part in parts[1:]] or [info.st_dev, info.st_ino] != host["root"]:
        failures.append(f"{top} does not show only the path to the sandbox root")
    for target in DIRECTORIES:
        if _read(Path(target) / CANARY) != f"out:{token}\n".encode("ascii"):
            failures.append(f"canary missing at {target}: its sandbox bind is missing")
    observed = BOOT_ID.read_text(encoding="ascii").strip()
    if observed != launch["boot_id"] or observed == host["boot_id"]:
        failures.append("the boot ID is not the sandbox's")
    for path, original in host["shims"].items():
        if original is None:
            if os.path.lexists(path):
                failures.append(f"{path} appeared inside")
            continue
        # The bind follows a symbolic link to the file it replaces, so read through one too.
        try:
            content = Path(path).read_bytes()
        except OSError:
            content = None
        refused = subprocess.run([path, "sandbox-check"], stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                                 stderr=subprocess.DEVNULL, env={"PATH": "/usr/bin:/bin"}).returncode
        if content is None or sha256(content) != launch["refuse_sha256"] or refused != SHIM_REFUSED:
            failures.append(f"{path} is not the refusing shim")
    interfaces = sorted(name for _, name in socket.if_nameindex())
    if interfaces != ["lo"]:
        failures.append(f"network interfaces other than loopback: {interfaces}")
    return failures


def _loopback_up() -> None:
    import fcntl

    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        flags = struct.unpack(IFREQ, fcntl.ioctl(sock, SIOCGIFFLAGS, struct.pack(IFREQ, b"lo", 0)))[1]
        fcntl.ioctl(sock, SIOCSIFFLAGS, struct.pack(IFREQ, b"lo", flags | IFF_UP))


def _stop_everything() -> None:
    """Terminate every process left in the namespace, then kill what remains."""
    try:
        os.kill(-1, signal.SIGTERM)
    except ProcessLookupError:
        return
    deadline = time.monotonic() + STOP_GRACE_SECONDS
    while time.monotonic() < deadline:
        try:
            if os.waitpid(-1, os.WNOHANG)[0] == 0:
                time.sleep(POLL_SECONDS)
        except ChildProcessError:
            return
    try:
        os.kill(-1, signal.SIGKILL)
    except ProcessLookupError:
        return
    while True:
        try:
            os.waitpid(-1, 0)
        except ChildProcessError:
            return


def run_as_init(command: list[str], environment: dict) -> int:
    child = os.fork()
    if child == 0:
        try:
            # Only stdin, stdout and stderr: the launch lock stays with this init.
            os.closerange(3, os.sysconf("SC_OPEN_MAX"))
            os.chdir("/")
            os.execvpe(command[0], command, environment)
        except OSError as error:
            print(f"run-sandbox: cannot run {command[0]}: {error.strerror}", file=sys.stderr)
        os._exit(COMMAND_NOT_FOUND)

    def forward(number, _frame):
        try:
            os.kill(child, number)
        except ProcessLookupError:
            pass

    # PID 1 of a namespace receives only signals it handles.
    for number in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP, signal.SIGQUIT, signal.SIGUSR1, signal.SIGUSR2):
        signal.signal(number, forward)
    while True:
        pid, status = os.waitpid(-1, 0)
        if pid == child:
            break
    code = os.waitstatus_to_exitcode(status)
    _stop_everything()
    return 128 - code if code < 0 else code


def init(root: Path, token: str, command: list[str]) -> int:
    require(bool(command), "No command to run", USAGE)
    launch = read_launch(root, token)
    failures = inside_checks(root, token, launch)
    require(not failures, "; ".join(failures))
    for target in DIRECTORIES:
        try:
            _write_atomic(Path(target) / CANARY_IN, f"in:{token}\n".encode("ascii"), 0o644)
        except OSError as error:
            raise Refused(f"canary cannot be written at {target}: {error.strerror}") from None
    _loopback_up()
    sync = root / "sync"
    _write_atomic(sync / "ready", (json.dumps({"version": 1, "token": token}) + "\n").encode("ascii"), 0o600)
    decided = _wait_for((sync / "go", sync / "stop"), WAIT_SECONDS)
    require(decided is not None and decided.name == "go" and not (sync / "stop").exists(),
            "the outside check did not let the sandbox start")
    environment = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8", "HOME": "/root",
                   "REPRO_SANDBOX_ROOT": str(root), "REPRO_SANDBOX_BOOT_ID": launch["boot_id"]}
    if "TERM" in os.environ:
        environment["TERM"] = os.environ["TERM"]
    return run_as_init(command, environment)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("action", choices=("prepare", "arm", "outside", "init"))
    parser.add_argument("--root", required=True)
    parser.add_argument("--token")
    parser.add_argument("--reboot", action="store_true")
    argv = sys.argv[1:] if argv is None else argv
    # Everything after the first '--' is the command, passed on untouched.
    split = argv.index("--") if "--" in argv else len(argv)
    args, command = parser.parse_args(argv[:split]), argv[split + 1:]
    try:
        require(sys.platform == "linux" and os.geteuid() == 0, "The sandbox runs as root on Linux", USAGE)
        require(args.action in ("prepare", "arm") or re.fullmatch(r"[0-9a-f]{32}", args.token or "") is not None,
                "A launch token is required", USAGE)
        root = validate_root(args.root)
        if args.action == "prepare":
            prepare(root)
        elif args.action == "arm":
            print(arm(root, args.reboot))
        elif args.action == "outside":
            outside(root, args.token)
        else:
            return init(root, args.token, command)
    except Refused as error:
        # Every refusal happens before the command starts.
        print(f"run-sandbox: refused: {error}; nothing ran", file=sys.stderr)
        return error.code
    except (OSError, ValueError, KeyError, TypeError) as error:
        print(f"run-sandbox: refused: {args.action} failed: {error}", file=sys.stderr)
        return REFUSED
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
