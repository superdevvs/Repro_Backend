"""Isolation checks and init for the cutover namespace sandbox (design 7.3).

run-sandbox.sh calls this module; nobody else should:

    prepare --root ROOT                  host namespace: validate the sandbox root, lay it out
    arm --root ROOT [--reboot]           host namespace, under the launch lock: boot ID, canaries,
                                         host facts; prints the launch token
    outside --root ROOT --token T        host namespace, while the sandbox waits: the canary check
                                         from outside; writes go or stop
    init --root ROOT --token T -- CMD    PID 1 inside the new namespaces: the inside checks, then
                                         CMD as a child; reaps orphans; stops everything at the end

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
  the host's, /run and /tmp are private, /etc, /var, /opt and /home are
  copy-on-write views, the boot ID is the sandbox's, the service managers are
  the refusing shim, and the only network interface is loopback.

Any failure stops the run with exit 96 before the command starts.
Standard library only; runs as /usr/bin/python3 -I -S as root.
"""

from __future__ import annotations

import argparse
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
# sandbox and a stray write (app tree, deploy locks, Studio files) never reaches the host.
COPY_ON_WRITE = ("/etc", "/var", "/opt", "/home")
# Fresh per launch: host unix sockets (systemd, D-Bus, Supervisor, PHP-FPM, MySQL) live here and
# a network namespace does not hide a socket that has a path.
PRIVATE = ("/run", "/tmp")
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


def prepare(root: Path) -> None:
    """Lay out the sandbox root. Idempotent; never touches a host path."""
    for tree in COPY_ON_WRITE + PRIVATE:
        require(os.path.isdir(tree) and not os.path.islink(tree), f"The host has no directory {tree}")
    for folder in ("binds", "cow", "cow/upper", "cow/work", "boot", "shims", "sync"):
        (root / folder).mkdir(mode=0o700, exist_ok=True)
    for target, mode in DIRECTORIES.items():
        directory = source(root, target)
        if not os.path.lexists(directory):
            directory.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
            directory.mkdir(mode=mode)
            os.chmod(directory, mode)
        require(directory.is_dir() and not directory.is_symlink(), f"{directory} must be a directory")
    for tree in COPY_ON_WRITE:
        upper, work = root / "cow" / "upper" / tree.lstrip("/"), root / "cow" / "work" / tree.lstrip("/")
        upper.mkdir(mode=0o755, exist_ok=True)
        work.mkdir(mode=0o700, exist_ok=True)
        # The merged directory shows the upper directory's owner and mode; the guard checks them.
        host = os.stat(tree)
        os.chown(upper, host.st_uid, host.st_gid)
        os.chmod(upper, stat.S_IMODE(host.st_mode))


def arm(root: Path, reboot: bool) -> str:
    """Per-launch state under the launch lock: boot ID, canaries and the host facts."""
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
    token = uuid.uuid4().hex
    for target in DIRECTORIES:
        directory = source(root, target)
        (directory / CANARY_IN).unlink(missing_ok=True)
        _write_atomic(directory / CANARY, f"out:{token}\n".encode("ascii"), 0o644)
    host = {
        "boot_id": BOOT_ID.read_text(encoding="ascii").strip(),
        "namespaces": {name: os.readlink(f"/proc/self/ns/{name}") for name in ("pid", "net", "mnt")},
        "devices": {tree: [os.stat(tree).st_dev, os.stat(tree).st_ino] for tree in COPY_ON_WRITE + PRIVATE},
        "shims": {path: (sha256(Path(path).read_bytes()) if os.path.exists(path) else None) for path in SHIMS},
    }
    launch = {"version": 1, "token": token, "root": str(root), "boot_id": boot.read_text(encoding="ascii").strip(),
              "refuse_sha256": sha256(refuse_raw), "host": host}
    _write_atomic(root / "sync" / "launch.json", (json.dumps(launch, sort_keys=True) + "\n").encode("utf-8"), 0o600)
    return token


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
