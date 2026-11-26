"""Local end-to-end drill of the cutover controller (build plan D18; design 6.2, 7.1, 7.3).

    python3 -I -S -B run_drill.py list
    python3 -I -S -B run_drill.py run [--through STEP] [--runs 1|2] [--stand-ins] [--time-scale N]
                                      [--release-sha SHA] [--src /src] [--fixture /fixture] [--vendor /vendor-src]

`run` is the outer half: root in a disposable privileged container (e2e/run-drill.sh starts it with a
MySQL 8.4.13 fixture beside it), never on a real host. For each run it lays out a namespace-sandbox
root under /srv (the app copy with a synthetic SQLite database built from the app's own migrations,
no production data, no provider keys), writes the sandbox controller config with make_config.py, pins
the policy from the installed probes' own measurements, then launches the sandbox (run-sandbox.sh) with
this file's inside half as the command. Inside, the controller is driven only through the commands
the runbook uses: cli.py actions, live_config.py and fastcgi.py from the installed copy, admit.py
profiles and the app's own artisan commands. Nothing imports a controller module.

The run is the test. STEPS below is the whole drill in order, with the slice each step needs. A step
whose slice has not landed (LANDED) is pending: the run stops there with "pending: needs Dnn" and is
red. It is green only when every selected step really ran and passed. `--through STEP` runs a prefix.
`--stand-ins` is for developing this harness only: a pending step with a stand-in from code that exists
runs that instead, one without is skipped, and the run is red whatever happens (exit 4).

Exit status: 0 green; 1 a step failed; 3 stopped at a pending step; 4 a stand-in run; 2 usage.
Standard library only. Messages never contain a password or key.
"""

from __future__ import annotations

import argparse
import base64
import fcntl
import hashlib
import http.client
import json
import os
import pwd
import grp
import re
import shutil
import signal
import socket
import sqlite3
import stat
import struct
import subprocess
import sys
import threading
import time
import uuid
from dataclasses import dataclass
from pathlib import Path

# --------------------------------------------------------------------------------------------------
# The drill, in order. One row per step: name, the slices it needs, the design budget in seconds (None
# where design 6.1/6.2/3.x gives no figure), what it proves, and (for --stand-ins only) the stand-in.
# --------------------------------------------------------------------------------------------------

# Slices merged into the lane at this harness's base (3b0c734f). A slice adds itself here when it lands;
# the steps that need it then run for real.
LANDED = frozenset({"D01", "D02", "D03", "D04", "D05", "D06", "D07", "D08", "D09",
                    "D15", "D16", "D17", "D20"})
SLICES = {
    "D10": "prepare steps 1 to 10 (switch record, .env switch, MySQL config build, fpm_start, receipt)",
    "D11": "prepare checks and evidence (step 11 probes, token check, evidence_report, check-evidence --write)",
    "D12": "seal_sqlite full undo (gate close, .env restore, config_build, fpm_reload, unseal_proof)",
    "D13": "SQLite reopen (replay passes, FastCGI probe, --hold-scheduler), scheduler release, rehold, refresh",
    "D14": "CLI actions, timers, issued approvals, run wrapper",
    "D19": "the drill runbook: the copy-load recipe and its recorded coordinate",
    "PYMYSQL": "PyMySQL in the test image for scripts/mysql/migrate.py import-empty (not in the QA image)",
}


@dataclass(frozen=True)
class Step:
    name: str
    needs: tuple
    budget: float | None
    what: str
    stand_in: str | None = None

    @property
    def run(self) -> int:
        return int(self.name[1])

    @property
    def host(self) -> bool:
        return self.name.split(".", 1)[1] in HOST_STEPS

    @property
    def missing(self) -> tuple:
        return tuple(slice_ for slice_ in self.needs if slice_ not in LANDED)


HOST_STEPS = ("root", "config", "measure", "policy")
REBOOT = "reboot"


def _init_sequence(n: int) -> list:
    """The W1 init sequence (P10; review C2-4): marker, init, the live-config check, rehold, refresh, reopen."""
    return [
        Step(f"r{n}.root", (), None, "sandbox root: controller copy, app copy and vendor, synthetic SQLite from the "
             "migrations, smoke fixtures, live SQLite config cache, MySQL defaults files, sandbox scripts"),
        Step(f"r{n}.config", (), None, "make_config.py: host.json, identity.json and the wrapper files"),
        Step(f"r{n}.measure", (), None, "pins measured by the installed probes (P2 identity, P3 both live-config "
             "hashes, the MySQL one from a staged build with the switch values)"),
        Step(f"r{n}.policy", (), None, "make_config.py --measured: policy.json"),
        Step(f"r{n}.sandbox", (), None, "namespace sandbox launched; canaries checked from inside and outside"),
        Step(f"r{n}.services", (), None, "boot: MySQL socket, capture FPM, app FPM, nginx -t and start, private "
             "Supervisor with stand-in workers, watchdog loop; held 503, Telnyx to the capture, no route out"),
        Step(f"r{n}.marker", (), None, "W1: the open marker; nginx routes to the app"),
        Step(f"r{n}.init", (), None, "cli.py init: planned journal, deploy marker published"),
        Step(f"r{n}.init-live-config", (), None, "live_config.py: source cache, hash = policy SQLite pin (C2-4)"),
    ]


def _w1_rest(n: int) -> list:
    pin = Step("r1.pin-config", ("D14",), None, "cli.py pin-config reproduces both policy pins (P3)")
    return ([pin] if n == 1 else []) + [
        Step(f"r{n}.rehold", ("D13", "D14"), 120, "cli.py rehold: marker, release and activation gone, queues "
             "paused, workers stopped", stand_in=REBOOT),
        Step(f"r{n}.refresh", (), 60, "cli.py refresh: current-boot SQLite evidence for the planned journal"),
        Step(f"r{n}.reopen-init", (), 120, "cli.py reopen: wrapped workers admitted, open marker"),
    ]


def _night(n: int) -> list:
    return [
        Step(f"r{n}.copy-load", ("D19", "PYMYSQL"), None, "afternoon: synthetic copy loaded into MySQL as "
             "repro_maint (import-empty, verify-fields, complete-schema), coordinate recorded (waiver)"),
        Step(f"r{n}.predrain", (), None, "9:20 pm: long-job worker stopped (systemctl stop repro-media-archives)"),
        Step(f"r{n}.approval-hold", (), 60, "cli.py approval-template --phase hold (Approval 1)"),
        Step(f"r{n}.hold", (), 60, "slow request started, then cli.py hold: outage clock, every route held"),
    ]


STEPS = [
    # Run 1: the drill of design 6.2.
    *_init_sequence(1),
    *_w1_rest(1),
    Step("r1.scheduler-release", ("D13",), None, "after the reopen the scheduler release exists, made last; "
         "admit.py auth-prune is admitted"),
    Step("r1.watchdog-idle", (), None, "watchdog ticks leave the planned journal unchanged (P10)"),
    *_night(1),
    Step("r1.capture", (), None, "a signed test text captured during the hold (200), a test call saved (503)"),
    Step("r1.freeze", (), 180, "cli.py freeze with the slow request in flight: drained, request finished, sealed"),
    Step("r1.snapshot", (), 180, "frozen snapshot S_frozen, integrity, SHA-256 record"),
    Step("r1.check-evidence", ("D11", "D14", "D19"), 60, "cli.py check-evidence --write: the night's manifest"),
    Step("r1.prepare", ("D10", "D11", "D14"), 120, "cli.py prepare: switch, FPM on MySQL, receipt, ready"),
    Step("r1.prepare-checks", ("D11", "D14"), None, "evidence report: profile and FastCGI probes on MySQL, "
         "read-only smokes, token check, gate closed"),
    Step("r1.approval-promote", ("D14",), 60, "cli.py approval-template --phase promote: issued file, timer armed"),
    Step("r1.timer-abort", ("D12", "D14"), 180, "the template's timer aborts at +5 min (time-scaled): "
         "sqlite_authoritative", stand_in="cli.py abort"),
    Step("r1.probes-sqlite", ("D10", "D12"), None, "every probe reports SQLite: profile probes, FastCGI, gate"),
    Step("r1.unseal-proof", ("D12",), None, "unseal_proof equals sealed_hash"),
    Step("r1.reopen", ("D13", "D14"), 120, "cli.py reopen --hold-scheduler: replay passes, FastCGI before the "
         "marker, no scheduler release", stand_in="cli.py reopen"),
    Step("r1.replay", ("D13",), None, "the captured text replayed into SQLite once",
         stand_in="admit.py telnyx-replay"),
    Step("r1.release-scheduler", ("D13", "D14"), None, "cli.py release-scheduler: release marker; auth-prune "
         "admitted"),
    Step("r1.deploy-fence", ("D13",), None, "the deploy marker stays published after the reopen (until retire)"),
    # Run 2: the watchdog rolls back after a failed prepare.
    *_init_sequence(2),
    *_w1_rest(2),
    *_night(2),
    Step("r2.freeze", (), 180, "cli.py freeze"),
    Step("r2.snapshot", (), 180, "frozen snapshot S_frozen"),
    Step("r2.check-evidence", ("D11", "D14", "D19"), 60, "cli.py check-evidence --write"),
    Step("r2.prepare-fails", ("D10", "D14"), 120, "cli.py prepare fails at fpm_start (injected), after the .env "
         "switch and the MySQL config build; pending prepare, still frozen"),
    Step("r2.watchdog-rollback", ("D12",), 180, "watchdog tick at 0:45 (clock moved forward) rolls back; the "
         "switch undone", stand_in="watchdog rollback from frozen"),
    Step("r2.probes-sqlite", ("D10", "D12"), None, "every probe reports SQLite"),
    Step("r2.unseal-proof", ("D12",), None, "unseal_proof equals sealed_hash"),
    Step("r2.reopen", ("D13", "D14"), 120, "cli.py reopen --hold-scheduler, then release-scheduler",
         stand_in="cli.py reopen"),
]
NAMES = [step.name for step in STEPS]
BY_NAME = {step.name: step for step in STEPS}
assert len(set(NAMES)) == len(NAMES)
# Design 6.2 for the whole outage (hold to reopen) with the off-site waiver; 4.5 for rehold, refresh, reopen.
OUTAGE_BUDGET = 20 * 60
INIT_BUDGET = 5 * 60

# --------------------------------------------------------------------------------------------------
# Fixed paths. Inside the sandbox these are the sandbox's own (run-sandbox.sh binds them).
# --------------------------------------------------------------------------------------------------

PY = "/usr/bin/python3"
SANDBOX = Path("/opt/repro-cutover-sandbox")
HARNESS = SANDBOX / "e2e" / "run_drill.py"
CONTROLLER = Path("/opt/repro-cutover-fence")
CLI = CONTROLLER / "production" / "cli.py"
ADMIT = CONTROLLER / "production" / "admit.py"
ETC = Path("/etc/repro-cutover-fence")
STATE = Path("/var/lib/repro-cutover-fence/state.json")
EVIDENCE = Path("/run/repro-cutover-fence/verified-engine.json")
INGRESS_OPEN = Path("/run/repro-cutover-ingress/open")
DEPLOY_MARKER = Path("/etc/repro-database-cutover/active")
SCHEDULER_RELEASE = Path("/run/repro-cutover-fence/scheduler-released")
SEALED_HASH = Path("/var/lib/repro-cutover-fence/sealed-hash.json")
UNSEAL_PROOF = Path("/var/lib/repro-cutover-fence/unseal-proof.json")
SWITCH_RECORD = Path("/var/lib/repro-cutover-fence/switch-record.json")
RECEIPT = Path("/var/lib/repro-cutover-fence/receipt.json")
EVIDENCE_REPORT = Path("/var/lib/repro-cutover-fence/evidence-report.json")
ISSUED = Path("/var/lib/repro-cutover-fence/issued-approvals")
MYSQL_RUN = Path("/run/repro-mysql84-native")
FPM_SOCKET = Path("/run/php/php8.3-fpm.sock")
FPM_PID = Path("/run/php/php8.3-fpm.pid")
CAPTURE_DIR = Path("/run/repro-telnyx-capture")
CAPTURE_SOCKET = CAPTURE_DIR / "php-fpm.sock"
CAPTURE_PHP = Path("/opt/repro-telnyx-capture/capture.php")
CAPTURE_FPM = "/usr/sbin/php-fpm8.3"
DRILL_RUN = Path("/run/repro-drill")
NGINX_SOCKET = DRILL_RUN / "nginx.sock"
SLOW_PHP = Path("/opt/repro-drill/slow.php")
FRONTEND, LEGACY = "dashboard.drill.test", "api.drill.test"
MESSAGING, VOICE = "/api/webhooks/telnyx/messaging", "/api/webhooks/telnyx/voice"
DEPLOY_OWNER, WEB_USER, APP_GROUP = "maverick", "www-data", "www-data"
CLEAN_ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8"}
# Supervised stand-in workers: real queue workers on the synthetic database, each through admit.py.
PROGRAMS = ("laravel-worker", "media-archives", "voice-notifications")
SLOW_SECONDS = 30
REBOOT_EXIT, PENDING_EXIT, FAILED_EXIT, STAND_IN_EXIT = 10, 3, 1, 4
SIGN = ("echo base64_encode(sodium_crypto_sign_detached($argv[1].'|'.stream_get_contents(STDIN), "
        "base64_decode($argv[2])));")
KEYPAIR = ("$k = sodium_crypto_sign_keypair(); echo json_encode([base64_encode(sodium_crypto_sign_publickey($k)),"
           " base64_encode(sodium_crypto_sign_secretkey($k))]);")
# The watchdog's own command with its wall clock moved forward: the runner reads time.time(), so the 0:45
# deadline is reached without waiting 45 minutes. cli.py itself is unchanged.
SHIFTED = ("import runpy, sys, time\noffset = float(sys.argv[1]); script = sys.argv[2]\nreal = time.time\n"
           "time.time = lambda: real() + offset\nsys.argv = [script, *sys.argv[3:]]\n"
           "runpy.run_path(script, run_name='__main__')\n")


class StepFailed(RuntimeError):
    pass


def require(condition: bool, message: str) -> None:
    if not condition:
        raise StepFailed(message)


# --------------------------------------------------------------------------------------------------
# Small helpers.
# --------------------------------------------------------------------------------------------------

def run(argv, *, env=None, input=None, timeout=600, check=True, user=None, group=None, cwd=None,
        umask=-1) -> subprocess.CompletedProcess:
    kwargs = {}
    if user is not None:
        account = pwd.getpwnam(user)
        gid = grp.getgrnam(group or user).gr_gid
        kwargs.update(user=account.pw_uid, group=gid, extra_groups=os.getgrouplist(account.pw_name, gid))
    done = subprocess.run([str(part) for part in argv], env=CLEAN_ENV if env is None else env,
                          input=input, capture_output=True, timeout=timeout, cwd=cwd, umask=umask, **kwargs)
    if check and done.returncode != 0:
        raise StepFailed(f"{Path(str(argv[0])).name} {' '.join(map(str, argv[1:3]))} exited {done.returncode}: "
                         f"{_tail(done.stderr or done.stdout)}")
    return done


def _tail(data, limit: int = 600) -> str:
    text = data.decode("utf-8", "replace") if isinstance(data, bytes) else (data or "")
    text = " | ".join(line.strip() for line in text.strip().splitlines() if line.strip())
    return text if len(text) <= limit else "..." + text[-limit:]


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as stream:
        for block in iter(lambda: stream.read(1 << 20), b""):
            digest.update(block)
    return digest.hexdigest()


def write(path: Path, content, mode: int = 0o644, owner: str | None = None, group: str | None = None) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    data = content.encode("utf-8") if isinstance(content, str) else content
    temporary = path.with_name(f".{path.name}.{uuid.uuid4().hex}")
    with open(temporary, "wb") as stream:
        stream.write(data)
    os.chmod(temporary, mode)
    if owner is not None:
        shutil.chown(temporary, owner, group or owner)
    os.replace(temporary, path)


def read_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def private_dir(path: Path, mode: int = 0o700) -> Path:
    path.mkdir(parents=True, exist_ok=True)
    os.chown(path, 0, 0)
    os.chmod(path, mode)
    return path


def chown_tree(path: Path, owner: str, group: str) -> None:
    run(["chown", "-R", f"{owner}:{group}", path])


def wait_for(predicate, seconds: float, what: str, interval: float = 0.2):
    deadline = time.monotonic() + seconds
    while True:
        value = predicate()
        if value:
            return value
        if time.monotonic() >= deadline:
            raise StepFailed(f"timed out after {seconds:g} s waiting for {what}")
        time.sleep(interval)


def listening(path: Path) -> bool:
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as sock:
        sock.settimeout(2)
        try:
            sock.connect(str(path))
        except OSError:
            return False
    return True


def processes(pattern: str) -> list[int]:
    found = []
    for entry in Path("/proc").iterdir():
        if entry.name.isdigit() and int(entry.name) != os.getpid():
            try:
                command = (entry / "cmdline").read_bytes().replace(b"\0", b" ").decode("utf-8", "replace")
            except OSError:
                continue
            if pattern in command:
                found.append(int(entry.name))
    return found


def spawn(argv, log: Path) -> subprocess.Popen:
    """A service process detached from the harness: its own session, a clean environment, output to a log."""
    log.parent.mkdir(parents=True, exist_ok=True)
    stream = open(log, "ab")
    try:
        return subprocess.Popen([str(part) for part in argv], env=CLEAN_ENV, stdin=subprocess.DEVNULL,
                                stdout=stream, stderr=subprocess.STDOUT, start_new_session=True)
    finally:
        stream.close()


def fpm_status(path: Path) -> dict | None:
    """The pool's status page (pm.status_listen, ?json) through a minimal FastCGI request of the harness's own,
    so the harness imports nothing of the controller. None when nothing answers."""
    def record(kind: int, content: bytes) -> bytes:
        return struct.pack(">BBHHBx", 1, kind, 1, len(content), 0) + content

    params = {"SCRIPT_NAME": "/repro-fpm-status", "SCRIPT_FILENAME": "/repro-fpm-status", "REQUEST_METHOD": "GET",
              "QUERY_STRING": "json", "SERVER_PROTOCOL": "HTTP/1.1", "GATEWAY_INTERFACE": "CGI/1.1"}
    pairs = b"".join(bytes([len(key), len(value)]) + key.encode() + value.encode() for key, value in params.items())
    message = record(1, struct.pack(">HB5x", 1, 0)) + record(4, pairs) + record(4, b"") + record(5, b"")
    data = b""
    try:
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as sock:
            sock.settimeout(5)
            sock.connect(str(path))
            sock.sendall(message)
            while chunk := sock.recv(65536):
                data += chunk
    except OSError:
        return None
    stdout, offset = b"", 0
    while offset + 8 <= len(data):
        _, kind, _, length, padding = struct.unpack(">BBHHBx", data[offset:offset + 8])
        if kind == 6:
            stdout += data[offset + 8:offset + 8 + length]
        offset += 8 + length + padding
    try:
        return json.loads(stdout.split(b"\r\n\r\n", 1)[1])
    except (IndexError, ValueError):
        return None


class UnixHTTPConnection(http.client.HTTPConnection):
    def __init__(self, path: Path, host: str, timeout: float):
        super().__init__(host, timeout=timeout)
        self.unix_path = str(path)

    def connect(self):
        sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        sock.settimeout(self.timeout)
        sock.connect(self.unix_path)
        self.sock = sock


def http_request(host: str, method: str, path: str, body: bytes | None = None, headers: dict | None = None,
                 timeout: float = 30) -> tuple[int, dict, str]:
    connection = UnixHTTPConnection(NGINX_SOCKET, host, timeout)
    try:
        connection.request(method, path, body=body, headers={"Host": host, **(headers or {})})
        response = connection.getresponse()
        return response.status, {key.lower(): value for key, value in response.getheaders()}, \
            response.read().decode("utf-8", "replace")
    finally:
        connection.close()


@dataclass
class Paths:
    root: Path

    @property
    def app(self) -> Path:
        return self.root / "app" / "backend"

    @property
    def e2e(self) -> Path:
        return self.root / "e2e"

    @property
    def logs(self) -> Path:
        return self.root / "e2e" / "logs"

    @property
    def bin(self) -> Path:
        return self.root / "bin"

    @property
    def etc(self) -> Path:
        """The sandbox directory bound over /etc/repro-cutover-fence (seen from the host namespace)."""
        return self.root / "binds" / "etc" / "repro-cutover-fence"

    @property
    def sqlite(self) -> Path:
        return self.app / "database" / "database.sqlite"

    @property
    def results(self) -> Path:
        return self.e2e / "results.jsonl"

    @property
    def lock(self) -> Path:
        return self.e2e / "step.lock"


def root_for(run_number: int, srv: Path) -> Path:
    return srv / f"repro-drill-{run_number}"


class StepLock:
    """Serialises controller commands of the harness, its timers and its watchdog loop (one at a time, as an
    operator retries a command refused while the watchdog holds the controller lock)."""

    def __init__(self, path: Path, blocking: bool = True):
        self.path, self.blocking, self.stream = path, blocking, None

    def __enter__(self):
        self.stream = open(self.path, "a+b")
        try:
            fcntl.flock(self.stream, fcntl.LOCK_EX | (0 if self.blocking else fcntl.LOCK_NB))
        except BlockingIOError:
            self.stream.close()
            self.stream = None
        return self.stream is not None

    def __exit__(self, *exc):
        if self.stream is not None:
            fcntl.flock(self.stream, fcntl.LOCK_UN)
            self.stream.close()


# --------------------------------------------------------------------------------------------------
# Outer half: container preparation and the host-namespace steps.
# --------------------------------------------------------------------------------------------------

class Outer:
    def __init__(self, args):
        self.args = args
        self.src = Path(args.src)
        self.cutover = self.src / "scripts" / "cutover"
        self.srv = Path(args.srv)
        self.fixture = Path(args.fixture)
        self.measured: dict[int, dict] = {}
        self.smoke: dict[int, tuple[int, int]] = {}
        self.run_ids: dict[int, str] = {}

    # Container prerequisites: what the production host has and the QA image lacks.
    def prepare_container(self) -> None:
        require(sys.platform == "linux" and os.geteuid() == 0, "run as root on Linux")
        require(Path("/.dockerenv").exists(), "the drill runs only in a disposable container (no /.dockerenv)")
        for path in (self.cutover / "runner.py", self.fixture / "ready", Path(self.args.vendor) / "autoload.php"):
            require(path.exists(), f"missing {path}")
        # The launcher and this harness, installed as sandbox/README.md shows, read by root only from here.
        sandbox = self.cutover / "production" / "sandbox"
        private_dir(SANDBOX, 0o755)
        private_dir(SANDBOX / "shims", 0o755)
        private_dir(SANDBOX / "e2e", 0o755)
        for name in ("run-sandbox.sh", "sandbox_check.py", "make_config.py"):
            write(SANDBOX / name, (sandbox / name).read_bytes(), 0o644)
        write(SANDBOX / "shims" / "refuse", (sandbox / "shims" / "refuse").read_bytes(), 0o755)
        write(HARNESS, Path(__file__).read_bytes(), 0o644)
        # Production has /usr/bin/php (the queue and config-build commands name it) and php-fpm8.3 (the
        # capture unit's ExecStart); the QA image has /usr/local/bin/php and /usr/local/sbin/php-fpm.
        for link, target in (("/usr/bin/php", "/usr/local/bin/php"), (CAPTURE_FPM, "/usr/local/sbin/php-fpm")):
            if not os.path.lexists(link):
                os.symlink(target, link)
        try:
            pwd.getpwnam(DEPLOY_OWNER)
        except KeyError:
            run(["useradd", "--create-home", "--user-group", "--groups", APP_GROUP, "--shell", "/usr/sbin/nologin",
                 DEPLOY_OWNER])
        self.by_uuid()

    def by_uuid(self) -> None:
        """The identity probe names the SQLite file's filesystem by its /dev/disk/by-uuid entry. A container
        has none: add one block-device node for the device of the sandbox roots' volume (only stat-ed)."""
        private_dir(self.srv, 0o755)
        device = os.stat(self.srv).st_dev
        by_uuid = Path("/dev/disk/by-uuid")
        by_uuid.mkdir(parents=True, exist_ok=True)
        for name in os.listdir(by_uuid):
            info = os.stat(by_uuid / name)
            if stat.S_ISBLK(info.st_mode) and info.st_rdev == device:
                return
        os.mknod(by_uuid / str(uuid.uuid4()), stat.S_IFBLK | 0o600, device)

    def step_root(self, n: int) -> str:
        root = root_for(n, self.srv)
        if root.exists():
            require((root / ".repro-drill-root").exists(), f"{root} exists and is not a drill root")
            shutil.rmtree(root)
        root.mkdir(mode=0o711)
        os.chmod(root, 0o711)
        (root / ".repro-drill-root").touch()
        paths = Paths(root)
        run([PY, "-I", "-S", SANDBOX / "sandbox_check.py", "prepare", "--root", root])
        self.controller_copy(paths)
        self.app_copy(paths, n)
        self.service_files(paths)
        return f"app {paths.app}, smoke client {self.smoke[n][0]}, shoot {self.smoke[n][1]}"

    def controller_copy(self, paths: Paths) -> None:
        target = paths.root / "binds" / "opt" / "repro-cutover-fence"
        private_dir(target / "production", 0o755)
        write(target / "guard.py", (self.cutover / "fence" / "guard.py").read_bytes())
        write(target / "runner.py", (self.cutover / "runner.py").read_bytes())
        for path in sorted((self.cutover / "production").glob("*.py")):
            if not path.name.startswith("test_"):
                write(target / "production" / path.name, path.read_bytes())

    def app_copy(self, paths: Paths, n: int) -> None:
        app = paths.app
        app.mkdir(parents=True)
        ignore = shutil.ignore_patterns("__pycache__", "*.pyc", "node_modules")
        for name in ("app", "bootstrap", "config", "resources", "routes", "scripts", "public", "database"):
            shutil.copytree(self.src / name, app / name, symlinks=True, ignore=ignore)
        for name in ("artisan", "composer.json", "composer.lock", "package.json", "package-lock.json"):
            if (self.src / name).exists():
                shutil.copy2(self.src / name, app / name)
        for cached in (app / "bootstrap" / "cache").glob("*.php"):
            cached.unlink()
        (app / "vendor").mkdir()
        run(["cp", "-a", f"{self.args.vendor}/.", app / "vendor"], timeout=900)
        for folder in ("app", "app/public", "framework/cache/data", "framework/sessions", "framework/views",
                       "framework/testing", "logs"):
            (app / "storage" / folder).mkdir(parents=True, exist_ok=True)
        public_key, secret_key = json.loads(run(["/usr/local/bin/php", "-r", KEYPAIR]).stdout)
        write(paths.e2e / "telnyx-secret", secret_key, 0o600)
        os.chmod(paths.e2e, 0o700)
        app_key = "base64:" + base64.b64encode(os.urandom(32)).decode("ascii")
        # Blank provider keys and no production data: the database is built from the app's own migrations.
        env = {
            "APP_NAME": "RePro", "APP_ENV": "production", "APP_KEY": app_key, "APP_DEBUG": "false",
            "APP_URL": f"http://{FRONTEND}", "LOG_CHANNEL": "single", "LOG_LEVEL": "warning",
            "DB_CONNECTION": "sqlite", "DB_DATABASE": str(paths.sqlite), "DB_BUSY_TIMEOUT": "5000",
            "DB_JOURNAL_MODE": "wal", "DB_SYNCHRONOUS": "normal", "QUEUE_CONNECTION": "database",
            "CACHE_STORE": "file", "SESSION_DRIVER": "file", "MAIL_MAILER": "log", "BROADCAST_CONNECTION": "log",
            "FILESYSTEM_DISK": "local", "TELESCOPE_ENABLED": "false", "PULSE_ENABLED": "false",
            "TELNYX_API_KEY": "", "TELNYX_PUBLIC_KEY": public_key, "STRIPE_SECRET": "", "AWS_ACCESS_KEY_ID": "",
        }
        write(app / ".env", "".join(f"{key}={value}\n" for key, value in env.items()), 0o640)
        write(app / "storage" / "app" / "deploy-meta.json", json.dumps({"commit": self.args.release_sha}) + "\n")
        paths.sqlite.touch()
        chown_tree(app, DEPLOY_OWNER, APP_GROUP)
        run(["chmod", "-R", "a+rX,go-w", app])
        for writable in (app / "storage", app / "bootstrap" / "cache", app / "database"):
            run(["find", writable, "-type", "d", "-exec", "chmod", "2775", "{}", "+"])
            run(["find", writable, "-type", "f", "-exec", "chmod", "0664", "{}", "+"])
        os.chmod(app / ".env", 0o640)
        def artisan(*words: str) -> subprocess.CompletedProcess:
            return run(["/usr/bin/php", "artisan", *words], user=DEPLOY_OWNER, group=APP_GROUP, cwd=app,
                       umask=0o002, env={**CLEAN_ENV, "HOME": "/home/maverick"}, timeout=900)

        artisan("migrate", "--force", "--no-interaction")
        fixtures = json.loads(artisan("cutover:smoke-fixtures", "--create", "--json").stdout)
        require(fixtures.get("ok") is True and fixtures.get("client_id") and fixtures.get("shoot_id"),
                f"cutover:smoke-fixtures --create did not report both records: {fixtures.get('problems')}")
        self.smoke[n] = (int(fixtures["client_id"]), int(fixtures["shoot_id"]))
        # The live config cache, as Deploy-App builds it: the deploy user, group www-data, umask 0022.
        run(["/usr/sbin/runuser", "-u", DEPLOY_OWNER, "-g", APP_GROUP, "--", "/bin/sh", "-c",
             f'umask 0022 && cd {app} && exec env APP_CONFIG_CACHE="$1" /usr/bin/php artisan config:cache',
             "repro-config", app / "bootstrap" / "cache" / "config.php"])

    def service_files(self, paths: Paths) -> None:
        production = self.cutover / "production"
        root, app = paths.root, paths.app
        private_dir(paths.e2e, 0o700)
        private_dir(paths.logs, 0o755)
        write(paths.lock, b"", 0o600)
        write(paths.e2e / "scale", f"{self.args.time_scale}\n", 0o600)
        # The sandbox MySQL's datadir: the fixture server runs in its own container (the QA image has no
        # mysqld), so only its auto.cnf, which the identity probe and make_config read, is here.
        server_uuid = (self.fixture / "server_uuid").read_text(encoding="ascii").strip()
        datadir = root / "mysql" / "data"
        private_dir(datadir, 0o750)
        write(datadir / "auto.cnf", f"[auto]\nserver-uuid={server_uuid}\n", 0o644)
        # Defaults files and the app password (design 3.1), root 0600, from the fixture's rendered accounts.
        etc = paths.etc
        for name, account in (("mysql-probe.cnf", "repro_cutover_probe"), ("mysql-gate.cnf", "repro_cutover_gate"),
                              ("mysql-token-check.cnf", "repro_token_check")):
            write(etc / name, (self.fixture / f"{account}.cnf").read_bytes(), 0o600)
        app_password = (self.fixture / "passwords" / "app").read_text(encoding="ascii").strip()
        write(etc / "mysql-app.env", f"DB_PASSWORD={app_password}\n", 0o600)
        # The capture's files (design 5.2): its pool as installed, settings naming the sandbox app copy.
        capture = root / "binds" / "etc" / "repro-telnyx-capture"
        write(capture / "php-fpm.conf", (production / "capture" / "php-fpm.conf").read_bytes())
        write(capture / "capture.json", json.dumps({"app_root": str(app),
                                                    "ingress_open_marker": str(INGRESS_OPEN)}) + "\n")
        write(paths.e2e / "capture.php", (production / "capture" / "capture.php").read_bytes(), 0o644)
        # nginx: the two production includes verbatim, two server blocks standing in for frontend.conf and
        # laravel.conf, and one drill-only location for the slow request.
        nginx = paths.e2e / "nginx"
        write(nginx / "repro-cutover-gate.conf", (production / "nginx" / "repro-cutover-gate.conf").read_bytes())
        write(nginx / "repro-cutover-hold.conf", (production / "nginx" / "repro-cutover-hold.conf").read_bytes())
        shutil.copyfile("/etc/nginx/fastcgi_params", nginx / "fastcgi_params")
        temp = DRILL_RUN / "nginx-temp"
        fastcgi = f"fastcgi_pass unix:{FPM_SOCKET}; include {nginx}/fastcgi_params;"
        write(nginx / "nginx.conf", f"""user www-data;
worker_processes 1;
daemon off;
pid {DRILL_RUN}/nginx.pid;
error_log {paths.logs}/nginx-error.log;
events {{ worker_connections 64; }}
http {{
    access_log {paths.logs}/nginx-access.log;
    client_body_temp_path {temp}/body; fastcgi_temp_path {temp}/fastcgi; proxy_temp_path {temp}/proxy;
    uwsgi_temp_path {temp}/uwsgi; scgi_temp_path {temp}/scgi;
    include {nginx}/repro-cutover-gate.conf;
    server {{
        listen unix:{NGINX_SOCKET};
        server_name {FRONTEND};
        include {nginx}/repro-cutover-hold.conf;
        location ^~ /api {{
            {fastcgi} fastcgi_param SCRIPT_FILENAME {app}/public/index.php; fastcgi_param SCRIPT_NAME /index.php;
        }}
        location / {{ default_type text/plain; return 200 'spa'; }}
    }}
    server {{
        listen unix:{NGINX_SOCKET};
        server_name {LEGACY};
        root {app}/public;
        include {nginx}/repro-cutover-hold.conf;
        location = /__drill_slow {{ {fastcgi} fastcgi_param SCRIPT_FILENAME {SLOW_PHP}; fastcgi_read_timeout 120s; }}
        location / {{ try_files $uri /index.php?$query_string; }}
        location ~ \\.php$ {{ {fastcgi} fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; }}
    }}
}}
""")
        write(paths.e2e / "slow.php", "<?php\n$seconds = min(60, max(1, (int) ($_GET['seconds'] ?? 5)));\n"
              "sleep($seconds);\nheader('Content-Type: application/json');\n"
              "echo json_encode(['slept' => $seconds]);\n")
        # The app's PHP-FPM, laid out as Debian's: the [www] pool plus the status snippet (design 5.4) verbatim.
        fpm = paths.e2e / "fpm"
        write(fpm / "php-fpm.conf", f"[global]\npid = {FPM_PID}\nerror_log = {paths.logs}/php-fpm.log\n"
              f"daemonize = yes\ninclude = {fpm}/pool.d/*.conf\n")
        write(fpm / "pool.d" / "www.conf", f"""[www]
user = www-data
group = www-data
listen = {FPM_SOCKET}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
clear_env = yes
catch_workers_output = yes
""")
        write(fpm / "pool.d" / "zz-repro-status.conf", (production / "php-fpm" / "repro-status.conf").read_bytes())
        # Host commands of the sandbox host.json (make_config.py names them): service scripts that reach no
        # service manager. Each clears the controller's environment (its REPRO_CUTOVER_CHILD tag), so nothing
        # they start counts as a host command still running.
        harness = f"exec /usr/bin/env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C.UTF-8 {PY} -I -S -B {HARNESS}"
        scripts = {
            "stop-workers": f"ctl --root {root} stop",
            "start-workers": f"start-workers --root {root}",
            "fpm-start": f"fpm --root {root} start",
            "fpm-reload": f"fpm --root {root} reload",
            "fpm-stop": f"fpm --root {root} stop",
            "timer": f"timer --root {root}",
        }
        for name, command in scripts.items():
            write(paths.bin / name, f'#!/bin/sh\n{harness} {command} "$@"\n', 0o755)
        os.chmod(paths.bin, 0o755)
        # The private Supervisor's programs (supervisor/laravel-worker.conf.example: the wrapper as root).
        write(paths.e2e / "supervisor.json", json.dumps({"programs": {
            name: [PY, "-I", "-S", str(ADMIT), name] for name in PROGRAMS}}, indent=2) + "\n", 0o600)
        # The wrapper profiles: the production example with the sandbox app copy and sandbox home paths.
        template = (production / "wrappers.sqlite.example.json").read_text(encoding="utf-8")
        # (make_config.py refuses any text naming /home/ or /var/www/.)
        template = template.replace("/var/www/backend", str(app)).replace("/home/maverick", f"{root}/maverick-home")
        write(paths.e2e / "wrappers.template.json", template, 0o600)
        for directory in ("faults", "approvals", "night", "timers"):
            private_dir(paths.e2e / directory)

    def make_config(self, n: int, measured: bool) -> list:
        paths = Paths(root_for(n, self.srv))
        if n not in self.run_ids:
            self.run_ids[n] = f"drill-r{n}-{time.strftime('%Y%m%d%H%M%S', time.gmtime())}"
        client, shoot = self.smoke[n]
        argv = [PY, "-I", "-S", "-B", SANDBOX / "make_config.py", "--root", paths.root, "--controller", self.cutover,
                "--app-root", paths.app, "--run-id", self.run_ids[n], "--mysql-datadir", paths.root / "mysql" / "data",
                "--smoke-client-id", client, "--smoke-shoot-id", shoot, "--deploy-owner", DEPLOY_OWNER,
                "--mode", "drill", "--mysql-client", "/usr/local/bin/mysql",
                "--wrappers", paths.e2e / "wrappers.template.json"]
        if measured:
            argv += ["--measured", paths.e2e / "measured.json"]
        run(argv)
        return sorted(os.listdir(paths.etc))

    def step_config(self, n: int) -> str:
        return "wrote " + ", ".join(name for name in self.make_config(n, False) if name.endswith(".json"))

    def step_measure(self, n: int) -> str:
        """The pins, measured by the reviewed controller tree (read-only) as P2 and P3 read them on the host."""
        paths = Paths(root_for(n, self.srv))
        production = self.cutover / "production"
        identity = json.loads(run([PY, "-I", "-S", "-B", production / "identity_probe.py", "--config",
                                   paths.etc / "identity.json"]).stdout)
        sqlite_live = json.loads(run([PY, "-I", "-S", "-B", production / "live_config.py", "--app-root",
                                      paths.app]).stdout)
        require(sqlite_live["engine"] == "sqlite" and sqlite_live["source"] == "cache",
                f"the live SQLite config is not a parsed cache: {sqlite_live['problem']}")
        # The MySQL pin: a staged config:cache as the deploy user with the switch values in its environment
        # (what config_build.stage and D14's pin-config do), measured the same way.
        host = read_json(paths.etc / "host.json")
        parent = private_dir(paths.root / "stage", 0o711)
        stage = parent / uuid.uuid4().hex
        stage.mkdir(mode=0o700)
        (stage / "bootstrap" / "cache").mkdir(parents=True)
        chown_tree(stage, DEPLOY_OWNER, APP_GROUP)
        switch = dict(host["mysql"]["switch"])
        secret = (paths.etc / "mysql-app.env").read_text(encoding="ascii").strip().split("=", 1)[1]
        try:
            run(["/usr/sbin/runuser", "-u", DEPLOY_OWNER, "-g", APP_GROUP, "--", "/bin/sh", "-c",
                 f'umask 0022 && cd {paths.app} && exec env APP_CONFIG_CACHE="$1" /usr/bin/php artisan config:cache',
                 "repro-config", stage / "bootstrap" / "cache" / "config.php"],
                env={**CLEAN_ENV, **switch, "DB_PASSWORD": secret})
            mysql_live = json.loads(run([PY, "-I", "-S", "-B", production / "live_config.py", "--app-root",
                                         stage]).stdout)
        finally:
            shutil.rmtree(parent)
        require(mysql_live["engine"] == "mysql" and mysql_live["source"] == "cache",
                f"the staged MySQL config is not a parsed MySQL cache: {mysql_live['problem']}")
        measured = {"identity": identity, "sqlite_live_config_sha256": sqlite_live["live_config_sha256"],
                    "mysql_live_config_sha256": mysql_live["live_config_sha256"]}
        write(paths.e2e / "measured.json", json.dumps(measured, indent=2) + "\n", 0o600)
        self.measured[n] = measured
        return (f"run {identity['run_id']}, release {identity['release_sha'][:12]}, SQLite pin "
                f"{measured['sqlite_live_config_sha256'][:12]}, MySQL pin {measured['mysql_live_config_sha256'][:12]}")

    def step_policy(self, n: int) -> str:
        self.make_config(n, True)
        policy = read_json(Paths(root_for(n, self.srv)).etc / "policy.json")
        require(policy["sqlite_live_config_sha256"] == self.measured[n]["sqlite_live_config_sha256"],
                "policy.json does not pin the measured SQLite config")
        return f"policy.json pins run {policy['identity']['run_id']}"

    def host_step(self, step: Step) -> str:
        return getattr(self, f"step_{step.name.split('.', 1)[1]}")(step.run)

    def launch(self, n: int, first: str, through: str, reboot: bool) -> int:
        root = root_for(n, self.srv)
        argv = ["/bin/sh", SANDBOX / "run-sandbox.sh", *(["--reboot"] if reboot else []), root, "--",
                PY, "-I", "-S", "-B", HARNESS, "inside", "--root", root, "--from", first, "--through", through,
                "--launched-at", f"{time.time():.3f}", *(["--stand-ins"] if self.args.stand_ins else []),
                *(["--relaunch"] if reboot else [])]
        log = root / "e2e" / "logs" / f"launch-{int(time.time())}.log"
        with open(log, "ab") as stream:
            done = subprocess.run([str(part) for part in argv], env=CLEAN_ENV, stdin=subprocess.DEVNULL,
                                  stdout=stream, stderr=subprocess.STDOUT, timeout=6 * 3600)
        self.launch_log = log
        return done.returncode


# --------------------------------------------------------------------------------------------------
# Inside half: the sandbox's boot and the drill steps.
# --------------------------------------------------------------------------------------------------

class Inside:
    def __init__(self, args):
        self.args = args
        self.paths = Paths(Path(args.root))
        self.root, self.app = self.paths.root, self.paths.app
        self.run_number = int(re.search(r"repro-drill-(\d+)$", str(self.root)).group(1))
        self.host = read_json(ETC / "host.json")
        self.policy = read_json(ETC / "policy.json")
        self.notes: dict = {}
        self.slow: dict = {}

    # Controller commands (the runbook's), serialised with the watchdog loop and the timers.
    def cli(self, *words, expect: int | None = 0, timeout: float = 900) -> dict:
        with StepLock(self.paths.lock):
            done = run([PY, "-I", "-S", CLI, *words], check=False, timeout=timeout)
        if expect is not None and done.returncode != expect:
            raise StepFailed(f"cli.py {' '.join(words)} exited {done.returncode} (expected {expect}): "
                             f"{_tail(done.stderr or done.stdout)}")
        self.notes["last_cli"] = (done.returncode, _tail(done.stderr))
        text = done.stdout.decode("utf-8", "replace").strip()
        return json.loads(text) if text.startswith("{") else {"exit": done.returncode, "stdout": text}

    def journal(self) -> dict:
        return read_json(STATE)

    def phase(self) -> str:
        return self.journal()["phase"]

    def ingress(self, host: str = LEGACY, path: str = "/up") -> tuple[int, dict, str]:
        return http_request(host, "GET", path)

    def require_held(self, what: str) -> None:
        status, headers, body = self.ingress()
        require(status == 503 and '"admission_held":true' in body and "retry-after" in headers,
                f"{what}: expected the held 503 with Retry-After, got {status} {body[:80]}")

    def require_open(self, what: str) -> None:
        status, _, body = self.ingress()
        require(status == 200, f"{what}: expected the app's /up to answer 200, got {status} {body[:80]}")

    def writers(self) -> list[int]:
        return [pid for pattern in self.host["writer_processes"] for pid in processes(pattern)]

    def queue_paused(self, queue: str) -> bool:
        digest = hashlib.sha1(f"illuminate:queue:paused:{queue}".encode()).hexdigest()
        return (self.app / "storage" / "framework" / "cache" / "data" / digest[:2] / digest[2:4] / digest).exists()

    def sqlite_query(self, sql: str) -> list:
        """A read of the synthetic SQLite as the web user (root must not create -wal or -shm files)."""
        code = ("import json, sqlite3, sys\nc = sqlite3.connect('file:' + sys.argv[1] + '?mode=ro', uri=True)\n"
                "print(json.dumps(c.execute(sys.argv[2]).fetchall()))\n")
        done = run([PY, "-I", "-S", "-B", "-c", code, self.paths.sqlite, sql], user=WEB_USER)
        return json.loads(done.stdout)

    def spool_count(self, folder: str) -> int:
        path = self.app / "storage" / "app" / "telnyx-spool" / folder
        return len([name for name in os.listdir(path) if not name.startswith(".")]) if path.is_dir() else 0

    def sign(self, body: bytes, timestamp: str) -> str:
        secret = (self.paths.e2e / "telnyx-secret").read_text(encoding="ascii").strip()
        return run(["/usr/local/bin/php", "-r", SIGN, timestamp, secret], input=body).stdout.decode().strip()

    def telnyx(self, host: str, path: str, event: dict) -> tuple[int, dict, str]:
        body = json.dumps(event).encode()
        stamp = str(int(time.time()))
        return http_request(host, "POST", path, body, {"Content-Type": "application/json",
                                                        "Telnyx-Signature-Ed25519": self.sign(body, stamp),
                                                        "Telnyx-Timestamp": stamp})

    def approval(self, phase: str) -> Path:
        """Approval files are root 0600 in a root chain (guard.read_protected)."""
        document = self.cli("approval-template", "--phase", phase)
        require(document.get("action") == phase and document.get("challenge"),
                f"approval-template --phase {phase} printed no approval")
        path = self.paths.e2e / "approvals" / f"{phase}.json"
        write(path, json.dumps(document) + "\n", 0o600)
        return path

    # Boot: what a host starts at boot, in the sandbox (design 7.3).
    def boot(self) -> str:
        DRILL_RUN.mkdir(mode=0o755, exist_ok=True)
        (DRILL_RUN / "nginx-temp").mkdir(mode=0o755, exist_ok=True)
        # The sandbox MySQL socket at the production path (/run is private): the fixture server's directory.
        MYSQL_RUN.mkdir(mode=0o755, exist_ok=True)
        if not (MYSQL_RUN / "mysql.sock").exists():
            run(["mount", "-n", "--bind", Path(self.args.fixture) / "run", MYSQL_RUN])
        wait_for(lambda: listening(MYSQL_RUN / "mysql.sock"), 30, "the MySQL socket")
        # The capture (repro-telnyx-capture.service): its own PHP-FPM master, enabled at boot.
        CAPTURE_PHP.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
        write(CAPTURE_PHP, (self.paths.e2e / "capture.php").read_bytes(), 0o644)
        CAPTURE_DIR.mkdir(mode=0o755, exist_ok=True)
        spawn([CAPTURE_FPM, "--nodaemonize", "--fpm-config", "/etc/repro-telnyx-capture/php-fpm.conf"],
              self.paths.logs / "capture-fpm.log")
        wait_for(lambda: listening(CAPTURE_SOCKET), 30, "the capture's PHP-FPM socket")
        SLOW_PHP.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
        write(SLOW_PHP, (self.paths.e2e / "slow.php").read_bytes(), 0o644)
        # php8.3-fpm.service is enabled at boot.
        Path("/run/php").mkdir(mode=0o755, exist_ok=True)
        run([self.paths.bin / "fpm-start"])
        conf = self.paths.e2e / "nginx" / "nginx.conf"
        run(["/usr/sbin/nginx", "-t", "-c", conf, "-e", self.paths.logs / "nginx-startup.log"])
        spawn(["/usr/sbin/nginx", "-c", conf, "-e", self.paths.logs / "nginx-startup.log"],
              self.paths.logs / "nginx.out")
        wait_for(lambda: listening(NGINX_SOCKET), 30, "nginx")
        spawn([PY, "-I", "-S", "-B", HARNESS, "supervise", "--root", self.root], self.paths.logs / "supervisor.log")
        wait_for(lambda: (DRILL_RUN / "supervisor" / "status.json").exists(), 30, "the private Supervisor")
        period = max(5.0, 60.0 / self.args.time_scale)
        spawn([PY, "-I", "-S", "-B", HARNESS, "watchdog", "--root", self.root, "--period", f"{period:g}"],
              self.paths.logs / "watchdog.out")
        # Every outbound path is blocked: the sandbox network has loopback only.
        try:
            socket.create_connection(("192.0.2.1", 443), timeout=2).close()
            raise StepFailed("a connection left the sandbox")
        except OSError:
            pass
        if not INGRESS_OPEN.exists():
            self.require_held("before the open marker")
            status, headers, body = http_request(FRONTEND, "POST", MESSAGING, b"{}",
                                                 {"Content-Type": "application/json"})
            require(status == 503 and '"captured":false' in body and "retry-after" in headers,
                    f"an unsigned Telnyx post must reach the capture and be refused, got {status} {body[:80]}")
        return "MySQL, capture FPM, app FPM, nginx, Supervisor and watchdog up; held 503, Telnyx to the capture"

    # Steps. Each returns a short detail line or raises StepFailed.
    def step_services(self) -> str:
        return self.boot()

    def step_marker(self) -> str:
        write(INGRESS_OPEN, json.dumps({"engine": "sqlite", "version": 1}, sort_keys=True) + "\n", 0o644)
        os.chown(INGRESS_OPEN, 0, 0)
        self.require_open("after the open marker")
        return "open marker created; /up answers 200 through nginx"

    def step_init(self) -> str:
        result = self.cli("init")
        require(result["state"]["phase"] == "planned", "init did not commit a planned journal")
        require(DEPLOY_MARKER.exists(), "init did not publish the deploy marker")
        return f"planned, generation {result['state']['generation']}, deploy marker published"

    def step_init_live_config(self) -> str:
        live = json.loads(run([PY, "-I", "-S", CONTROLLER / "production" / "live_config.py", "--app-root",
                               self.app]).stdout)
        require(live["source"] == "cache" and live["engine"] == "sqlite"
                and live["live_config_sha256"] == self.policy["sqlite_live_config_sha256"],
                f"the live config is not the pinned SQLite cache ({live['source']}, {live['engine']})")
        return "source cache, engine sqlite, hash = policy sqlite_live_config_sha256"

    def step_pin_config(self) -> str:
        result = self.cli("pin-config")
        text = json.dumps(result)
        for name in ("sqlite_live_config_sha256", "mysql_live_config_sha256"):
            require(self.policy[name] in text, f"cli.py pin-config does not reproduce the policy's {name}")
        return "both pins reproduced"

    def step_rehold(self) -> str:
        self.cli("rehold")
        require(not INGRESS_OPEN.exists() and not SCHEDULER_RELEASE.exists(), "rehold left a marker or the release")
        require(all(self.queue_paused(queue) for queue in self.host["queues"]), "rehold left a queue running")
        require(not self.writers(), "rehold left a writer process")
        self.require_held("after rehold")
        return "held: marker and release gone, queues paused, no writer"

    def stand_in_reboot(self) -> str:
        return "stand-in: simulated reboot (run-sandbox.sh --reboot empties /run and stops every process)"

    def step_refresh(self) -> str:
        self.cli("refresh")
        require(EVIDENCE.exists(), "refresh published no evidence")
        return f"evidence for boot {read_json(EVIDENCE)['boot_id'][:8]}"

    def step_reopen_init(self) -> str:
        self.cli("reopen")
        require(INGRESS_OPEN.exists(), "reopen did not create the open marker")
        self.require_open("after the reopen")
        status = wait_for(lambda: self.supervisor_running(PROGRAMS), 30, "the wrapped workers")
        return f"open; workers running: {', '.join(status)}"

    def supervisor_running(self, names) -> list | None:
        status = read_json(DRILL_RUN / "supervisor" / "status.json")
        running = [name for name in names if status.get(name, {}).get("state") == "RUNNING"]
        return running if len(running) == len(names) else None

    def step_scheduler_release(self) -> str:
        require(SCHEDULER_RELEASE.exists(), "no scheduler release after the reopen")
        require(SCHEDULER_RELEASE.stat().st_mtime_ns >= INGRESS_OPEN.stat().st_mtime_ns,
                "the scheduler release was created before the open marker")
        code = run([PY, "-I", "-S", ADMIT, "auth-prune"], check=False, timeout=900).returncode
        require(code == 0, f"admit.py auth-prune exited {code} with the release present")
        return "release created last; a scheduler profile is admitted"

    def step_watchdog_idle(self) -> str:
        before = STATE.read_bytes()
        log = self.paths.logs / "watchdog.jsonl"
        seen = len(log.read_text().splitlines()) if log.exists() else 0
        wait_for(lambda: log.exists() and len(log.read_text().splitlines()) >= seen + 2, 120, "two watchdog ticks")
        ticks = [json.loads(line) for line in log.read_text().splitlines()[seen:]]
        require(all(tick["exit"] == 0 for tick in ticks), f"a watchdog tick failed: {ticks[-1].get('stderr')}")
        require(STATE.read_bytes() == before, "an idle watchdog tick changed the planned journal")
        return f"{len(ticks)} ticks, journal unchanged"

    def step_copy_load(self) -> str:
        raise StepFailed("not written yet: the copy load (migrate.py import-empty as repro_maint, verify-fields.php, "
                         "migrate:status --pending, complete-schema.php, the waiver coordinate after "
                         "cutover:smoke-fixtures) must write the six inputs of the evidence manifest into "
                         f"{self.paths.e2e / 'night'}")

    def step_predrain(self) -> str:
        run([PY, "-I", "-S", "-B", HARNESS, "ctl", "--root", self.root, "stop", "media-archives"], timeout=120)
        require(not processes("--queue=media-archives"), "the long-job worker is still running")
        return "media-archives stopped (the runbook's long-job pre-drain)"

    def step_approval_hold(self) -> str:
        if "D14" in LANDED:
            self.cli("approval-template", "--phase", "hold")
            require((ISSUED / "hold.json").exists(), "approval-template wrote no issued approval")
            return "issued_approvals/hold.json"
        self.notes["hold_approval"] = self.approval("hold")
        return "approval written root 0600"

    def start_slow_request(self) -> None:
        def worker():
            started = time.monotonic()
            try:
                status, _, body = http_request(LEGACY, "GET", f"/__drill_slow?seconds={SLOW_SECONDS}",
                                               timeout=SLOW_SECONDS + 90)
                self.slow.update(status=status, body=body)
            except OSError as error:
                self.slow.update(status=None, body=str(error))
            self.slow["seconds"] = time.monotonic() - started

        thread = threading.Thread(target=worker, daemon=True)
        thread.start()
        self.slow["thread"] = thread
        status_socket = Path(self.host["fpm"]["status_socket"])
        # Wait until the pool shows it active, so the hold really starts with a request in flight.
        wait_for(lambda: self.fpm_active(status_socket) >= 1, 15, "the slow request to reach PHP-FPM")

    @staticmethod
    def fpm_active(status_socket: Path) -> int:
        status = fpm_status(status_socket)
        return int(status.get("active processes", 0)) if status else 0

    def step_hold(self) -> str:
        self.start_slow_request()
        if "D14" in LANDED:
            result = self.cli("hold", "--issued-approval")
        else:
            result = self.cli("hold", "--approval", str(self.notes["hold_approval"]))
        require(result["state"]["phase"] == "holding", "hold did not reach holding")
        self.notes["hold_started"] = result["state"]["started_at"]
        require(not INGRESS_OPEN.exists(), "the open marker survived the hold")
        self.require_held("after the hold")
        paused = [queue for queue in self.host["queues"] if self.queue_paused(queue)]
        require(len(paused) == len(self.host["queues"]), "the hold left a queue running")
        require(self.slow["thread"].is_alive(), "the slow request ended before the hold")
        return f"holding; every route held; {len(paused)} queues paused; slow request still in flight"

    def step_capture(self) -> str:
        text = {"data": {"id": f"evt-drill-{self.run_number}", "event_type": "message.received", "payload": {
            "id": f"msg-drill-{self.run_number}", "from": {"phone_number": "+12025550177"},
            "to": [{"phone_number": "+18883426998"}], "text": "drill test text"}}}
        status, _, body = self.telnyx(FRONTEND, MESSAGING, text)
        require((status, body.strip()) == (200, '{"status":"captured"}'), f"text not captured: {status} {body[:80]}")
        call = {"data": {"id": f"evt-drill-call-{self.run_number}", "event_type": "call.initiated", "payload": {
            "call_control_id": f"drill-call-{self.run_number}", "from": "+12025550177", "to": "+18883426998"}}}
        status, headers, body = self.telnyx(LEGACY, VOICE, call)
        require(status == 503 and "retry-after" in headers, f"the call must be saved and answered 503: {status}")
        require((self.spool_count("texts"), self.spool_count("calls")) == (1, 1),
                f"spool holds {self.spool_count('texts')} texts and {self.spool_count('calls')} calls, not 1 and 1")
        return "text 200 captured (frontend block), call 503 saved (legacy block); spool 1 text, 1 call"

    def step_freeze(self) -> str:
        result = self.cli("freeze")
        state = result["state"]
        require(state["phase"] == "frozen", "freeze did not reach frozen")
        drained = state["history"][-1].get("fpm_drain") or {}
        details = f"fpm_drain {drained.get('outcome')}, waited {drained.get('waited_seconds')} s"
        if "thread" in self.slow:
            self.slow["thread"].join(SLOW_SECONDS + 90)
            require(self.slow.get("status") == 200 and '"slept"' in self.slow.get("body", ""),
                    f"the slow request did not finish: {self.slow.get('status')} {self.slow.get('body', '')[:80]}")
            require(drained.get("outcome") == "drained" and (drained.get("waited_seconds") or 0) >= 1,
                    f"the drain did not wait for the request in flight ({details})")
            details += f"; slow request 200 after {self.slow['seconds']:.1f} s"
        mode = self.paths.sqlite.stat().st_mode
        require(not mode & 0o222, "the SQLite file still has a write bit")
        sealed = read_json(SEALED_HASH)
        require(sealed["sha256"] == sha256_file(self.paths.sqlite), "sealed_hash differs from the sealed file")
        require(not listening(FPM_SOCKET), "PHP-FPM still answers after the freeze")
        require(not self.writers(), "a writer runs after the freeze")
        return f"frozen; {details}; sealed, sealed_hash recorded"

    def step_snapshot(self) -> str:
        night = private_dir(self.paths.e2e / "night")
        copy = night / "frozen.sqlite"
        shutil.copyfile(self.paths.sqlite, copy)
        os.chmod(copy, 0o600)
        with sqlite3.connect(f"file:{copy}?mode=ro&immutable=1", uri=True) as connection:
            check = connection.execute("PRAGMA integrity_check").fetchone()[0]
        require(check == "ok", f"integrity_check of the frozen snapshot: {check}")
        digest = sha256_file(copy)
        require(digest == read_json(SEALED_HASH)["sha256"], "the frozen snapshot differs from the sealed hash")
        write(night / "frozen.sha256", f"{digest}  {copy}\n", 0o600)
        self.notes["frozen"] = digest
        return f"S_frozen {digest[:12]}, integrity ok"

    def step_check_evidence(self) -> str:
        manifest = self.paths.e2e / "night" / "manifest.json"
        require(manifest.exists(), "no evidence manifest (the copy load writes the inputs)")
        self.cli("check-evidence", "--write", "--manifest", str(manifest))
        require(Path(self.host["paths"]["evidence_inputs"]).exists(), "check-evidence --write wrote no manifest")
        return "evidence_inputs written"

    def step_prepare(self) -> str:
        result = self.cli("prepare")
        require(result["state"]["phase"] == "ready", "prepare did not reach ready")
        env = (self.app / ".env").read_text(encoding="utf-8")
        require("DB_CONNECTION=mysql\n" in env, ".env was not switched")
        live = json.loads(run([PY, "-I", "-S", CONTROLLER / "production" / "live_config.py", "--app-root",
                               self.app]).stdout)
        require(live["live_config_sha256"] == self.policy["mysql_live_config_sha256"], "config is not the MySQL pin")
        require(listening(FPM_SOCKET) and SWITCH_RECORD.exists() and RECEIPT.exists(),
                "FPM, the switch record or the receipt is missing after prepare")
        return "ready; .env and config on the MySQL pin; FPM up; receipt armed"

    def step_prepare_checks(self) -> str:
        report = self.cli("status", "--evidence")
        text = json.dumps(report)
        for word in ("profile", "fastcgi", "readonly", "token"):
            require(word in text, f"the evidence report has no {word} check")
        require(EVIDENCE_REPORT.exists() and stat.S_IMODE(EVIDENCE_REPORT.stat().st_mode) == 0o600,
                "evidence_report missing or not root 0600")
        return "evidence report holds the profile, FastCGI, read-only smoke and token checks"

    def step_approval_promote(self) -> str:
        self.cli("approval-template", "--phase", "promote")
        require((ISSUED / "promote.json").exists(), "no issued promote approval")
        timers = [read_json(path) for path in (self.paths.e2e / "timers").glob("*.json")]
        armed = [timer for timer in timers if timer.get("fired_at") is None and timer.get("stopped_at") is None
                 and {"abort", "--if-challenge", "--if-phase"} <= set(timer["command"])]
        require(len(armed) == 1 and 0 < armed[0]["on_active"] <= 300,
                f"expected one conditional-abort timer of at most 5 minutes, found {len(armed)}")
        self.notes["promote_timer"] = armed[0]["unit"]
        return f"timer {armed[0]['unit']} armed for 300 s (scaled {armed[0]['scaled']:.1f} s)"

    def step_timer_abort(self) -> str:
        unit = self.notes["promote_timer"]
        record = self.paths.e2e / "timers" / f"{unit}.json"
        wait_for(lambda: read_json(record).get("exit") is not None, 600, "the promote timer to fire")
        fired = read_json(record)
        require(fired["exit"] == 0, f"the timer's conditional abort exited {fired['exit']}")
        state = self.journal()
        require(state["phase"] == "sqlite_authoritative" and state["history"][-1]["action"] == "abort",
                "the timer did not roll back to SQLite")
        return f"aborted by the timer after {fired['fired_at'] - fired['armed_at']:.1f} s"

    def stand_in_abort(self) -> str:
        result = self.cli("abort")
        require(result["state"]["phase"] == "sqlite_authoritative", "abort did not reach sqlite_authoritative")
        return "stand-in: cli.py abort (the lead aborts; design 6.2)"

    def step_probes_sqlite(self) -> str:
        pin = self.policy["sqlite_live_config_sha256"]
        seen = set()
        for name, profile in read_json(ETC / "wrappers.json")["profiles"].items():
            key = (profile["user"], profile["group"], profile["cwd"], profile["umask"], json.dumps(profile["env"]))
            if key in seen or profile["argv"][0] != "/usr/bin/php":
                continue
            seen.add(key)
            account = pwd.getpwnam(profile["user"])
            env = {"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": account.pw_dir, "USER": account.pw_name,
                   "LOGNAME": account.pw_name, "LANG": "C.UTF-8", **profile["env"]}
            report = json.loads(run(["/usr/bin/php", "artisan", "cutover:smoke", "--engine-only", "--json"],
                                    user=profile["user"], group=profile["group"], cwd=profile["cwd"],
                                    umask=int(profile["umask"], 8), env=env).stdout)
            require(report.get("engine") == "sqlite", f"profile {name} reports {report.get('engine')}")
        probe = json.loads(run([PY, "-I", "-S", CONTROLLER / "production" / "fastcgi.py", "--socket", FPM_SOCKET,
                                "--app-root", self.app]).stdout)
        require(probe["report"]["engine"] == "sqlite" and probe["route_sha256"] == pin,
                "the FastCGI probe does not report SQLite with the SQLite pin")
        gate = json.loads(run(self.host["commands"]["mysql"]).stdout)
        require(gate["gate"] == "closed" and gate["business_activity"] is False, "the gate is not closed and quiet")
        return f"{len(seen)} profile contexts, FastCGI and the gate all on SQLite"

    def step_unseal_proof(self) -> str:
        proof, sealed = read_json(UNSEAL_PROOF), read_json(SEALED_HASH)
        require(proof.get("sha256") == sealed["sha256"], "unseal_proof differs from sealed_hash")
        return f"unseal_proof = sealed_hash = {sealed['sha256'][:12]}"

    def step_reopen(self) -> str:
        self.cli("reopen", "--hold-scheduler")
        require(INGRESS_OPEN.exists() and not SCHEDULER_RELEASE.exists(), "reopen --hold-scheduler: marker or release")
        self.require_open("after reopen --hold-scheduler")
        if self.run_number == 2:
            self.cli("release-scheduler")
            require(SCHEDULER_RELEASE.exists(), "release-scheduler made no release")
        return "open on SQLite with the scheduler held" + ("; then released" if self.run_number == 2 else "")

    def stand_in_reopen(self) -> str:
        self.cli("reopen")
        self.require_open("after cli.py reopen")
        return "stand-in: cli.py reopen (no replay, no scheduler hold, lifts the deploy marker)"

    def step_replay(self) -> str:
        rows = self.sqlite_query(f"SELECT direction, body_text FROM messages WHERE provider_message_id = "
                                 f"'msg-drill-{self.run_number}'")
        require(rows == [["INBOUND", "drill test text"]], f"the captured text is not in SQLite once: {rows}")
        require(self.spool_count("texts") == 0, "the spool still holds a text")
        return "the captured text is one INBOUND row; spool texts empty"

    def stand_in_replay(self) -> str:
        done = run([PY, "-I", "-S", ADMIT, "telnyx-replay"], timeout=900)
        require(b"replayed 1," in done.stdout, f"telnyx-replay: {_tail(done.stdout)}")
        return "stand-in: admit.py telnyx-replay by hand; " + self.step_replay()

    def step_release_scheduler(self) -> str:
        self.cli("release-scheduler")
        require(SCHEDULER_RELEASE.exists(), "release-scheduler made no release")
        code = run([PY, "-I", "-S", ADMIT, "auth-prune"], check=False, timeout=900).returncode
        require(code == 0, f"admit.py auth-prune exited {code} after the release")
        return "release created; a scheduler profile is admitted"

    def step_deploy_fence(self) -> str:
        require(DEPLOY_MARKER.exists(), "the reopen withdrew the deploy marker (design 3.7 step 6: kept until retire)")
        return "deploy marker still published"

    def step_prepare_fails(self) -> str:
        fault = self.paths.e2e / "faults" / "fpm-start"
        write(fault, "prepare step 9\n", 0o600)
        try:
            self.cli("prepare", expect=None)
        finally:
            fault.unlink()
        state = self.journal()
        require(state["phase"] == "frozen" and (state.get("pending") or {}).get("action") == "prepare",
                "the failed prepare did not leave a pending prepare in frozen")
        require("DB_CONNECTION=mysql\n" in (self.app / ".env").read_text(encoding="utf-8"),
                "the injected failure came before the .env switch")
        return "prepare failed at fpm_start after the switch; pending prepare"

    def watchdog_rollback(self) -> str:
        state = self.journal()
        offset = state["started_at"] + 45 * 60 + 1 - time.time()
        write(self.paths.e2e / "watchdog-offset", f"{offset:.3f}\n", 0o600)
        started = time.monotonic()
        wait_for(lambda: self.phase() == "sqlite_authoritative", 300, "the watchdog rollback", interval=0.5)
        state = self.journal()
        require(state["history"][-1]["action"] == "abort", "the watchdog's last action is not the rollback")
        log = self.paths.logs / "watchdog.jsonl"
        ticks = [json.loads(line) for line in log.read_text(encoding="utf-8").splitlines()]
        require(any(tick["offset"] > 0 and tick["exit"] == 0 for tick in ticks),
                "no watchdog tick with the clock at 0:45 exited 0")
        return f"watchdog rolled back {time.monotonic() - started:.1f} s after its clock reached 0:45"

    def step_watchdog_rollback(self) -> str:
        detail = self.watchdog_rollback()
        env = (self.app / ".env").read_text(encoding="utf-8")
        require("DB_CONNECTION=sqlite\n" in env, "the rollback did not restore .env")
        live = json.loads(run([PY, "-I", "-S", CONTROLLER / "production" / "live_config.py", "--app-root",
                               self.app]).stdout)
        require(live["source"] == "cache" and live["live_config_sha256"] == self.policy["sqlite_live_config_sha256"],
                "the rollback did not rebuild the SQLite config")
        return detail + "; .env and config back on the SQLite pin"

    def stand_in_watchdog(self) -> str:
        return "stand-in: " + self.watchdog_rollback() + " (from frozen; no prepare ran)"


STAND_INS = {"cli.py abort": "stand_in_abort", "cli.py reopen": "stand_in_reopen",
             "admit.py telnyx-replay": "stand_in_replay", "watchdog rollback from frozen": "stand_in_watchdog",
             REBOOT: "stand_in_reboot"}


def inside_main(args) -> int:
    inside = Inside(args)
    paths = inside.paths
    first, through = NAMES.index(args.first), NAMES.index(args.through)
    selected = [step for step in STEPS[first:through + 1] if not step.host]

    def record(name, status, seconds, detail, budget=None):
        with open(paths.results, "a", encoding="utf-8") as stream:
            stream.write(json.dumps({"step": name, "status": status, "seconds": round(seconds, 2),
                                     "budget": budget, "detail": detail, "ended_at": time.time()}) + "\n")

    started = time.time()
    if args.relaunch:
        record(f"r{inside.run_number}.sandbox (relaunch)", "passed", started - args.launched_at, "relaunched")
        began = time.monotonic()
        try:
            detail = inside.boot()
            record(f"r{inside.run_number}.services (relaunch)", "passed", time.monotonic() - began, detail)
        except (StepFailed, OSError, subprocess.SubprocessError) as error:
            record(f"r{inside.run_number}.services (relaunch)", "failed", time.monotonic() - began, str(error))
            return FAILED_EXIT
    for step in selected:
        began = time.monotonic()
        if step.name.endswith(".sandbox"):
            record(step.name, "passed", started - args.launched_at, "canaries checked; the inside half started")
            continue
        missing = step.missing
        try:
            if missing:
                message = f"pending: needs {', '.join(missing)} (" + "; ".join(SLICES[s] for s in missing) + ")"
                if not args.stand_ins:
                    record(step.name, "pending", 0, message, step.budget)
                    return PENDING_EXIT
                if step.stand_in is None:
                    record(step.name, "skipped", 0, message, step.budget)
                    continue
                detail = getattr(inside, STAND_INS[step.stand_in])()
                record(step.name, "stand-in", time.monotonic() - began, f"{detail} [{message}]", step.budget)
                if step.stand_in == REBOOT:
                    return REBOOT_EXIT
                continue
            detail = getattr(inside, "step_" + step.name.split(".", 1)[1].replace("-", "_"))()
            record(step.name, "passed", time.monotonic() - began, detail, step.budget)
        except (StepFailed, OSError, ValueError, KeyError, subprocess.SubprocessError) as error:
            record(step.name, "failed", time.monotonic() - began, f"{type(error).__name__}: {error}", step.budget)
            return FAILED_EXIT
    return 0


# --------------------------------------------------------------------------------------------------
# Sandbox services the host commands reach: private Supervisor, FPM control, timers, watchdog loop.
# --------------------------------------------------------------------------------------------------

SUP = DRILL_RUN / "supervisor"
START_SECONDS, STOP_WAIT_SECONDS = 1.5, 30
HELD = 75


def supervise_main(args) -> int:
    """A private Supervisor (python3 -m supervisor is not in the QA image): autorestart=unexpected,
    exitcodes=75, stopasgroup and killasgroup, as supervisor/laravel-worker.conf.example."""
    paths = Paths(Path(args.root))
    programs = read_json(paths.e2e / "supervisor.json")["programs"]
    SUP.mkdir(parents=True, exist_ok=True)
    desired_path, status_path = SUP / "desired.json", SUP / "status.json"
    if not desired_path.exists():
        write(desired_path, json.dumps({"generation": 1, "programs": {name: True for name in programs}}), 0o600)
    state = {name: {"state": "STOPPED", "pid": None, "exit": None, "since": time.time(), "retries": 0,
                    "generation": 0} for name in programs}
    children: dict[str, subprocess.Popen] = {}
    signal.signal(signal.SIGTERM, lambda *_: (_stop_all(children), sys.exit(0)))
    while True:
        desired = read_json(desired_path)
        generation, now = desired["generation"], time.time()
        for name, argv in programs.items():
            entry, child = state[name], children.get(name)
            want = desired["programs"].get(name, False)
            if child is not None and child.poll() is not None:
                children.pop(name)
                code, stopping = child.returncode, entry["state"] == "STOPPING"
                entry.update(pid=None, exit=code, since=now)
                if stopping:
                    entry["state"] = "STOPPED"
                elif code == HELD:
                    entry["state"] = "EXITED"  # exitcodes=75: a held admission is expected, not restarted
                elif want and entry["retries"] < 3:
                    entry.update(state="BACKOFF", retries=entry["retries"] + 1)
                else:
                    entry["state"] = "FATAL"
                child = None
            if child is not None:
                if not want and entry["state"] != "STOPPING":
                    entry.update(state="STOPPING", since=now)
                    _signal_group(child, signal.SIGTERM)  # stopasgroup
                elif entry["state"] == "STOPPING" and now - entry["since"] > STOP_WAIT_SECONDS:
                    _signal_group(child, signal.SIGKILL)  # killasgroup
                elif entry["state"] == "STARTING" and now - entry["since"] >= START_SECONDS:
                    entry["state"] = "RUNNING"
                if want:
                    entry["generation"] = generation
                continue
            if not want:
                entry.update(state="STOPPED", generation=generation)
                continue
            fresh = entry["generation"] != generation
            if fresh or (entry["state"] == "BACKOFF" and now - entry["since"] >= 1):
                if fresh:
                    entry["retries"] = 0
                children[name] = spawn(argv, paths.logs / f"sup-{name}.log")
                entry.update(state="STARTING", pid=children[name].pid, exit=None, since=now, generation=generation)
        write(status_path, json.dumps(state), 0o644)
        time.sleep(0.1)


def _signal_group(child: subprocess.Popen, number: int) -> None:
    try:
        os.killpg(child.pid, number)
    except ProcessLookupError:
        pass


def _stop_all(children: dict) -> None:
    for child in children.values():
        _signal_group(child, signal.SIGTERM)


def ctl_main(args) -> int:
    """`supervisorctl start|stop [name...]` for the private Supervisor; exits 0 once every name is RUNNING
    (start) or stopped (stop), 1 otherwise. A held admission (exit 75) fails a start, as startsecs does."""
    desired_path = SUP / "desired.json"
    desired = read_json(desired_path)
    names = args.names or sorted(desired["programs"])
    for name in names:
        desired["programs"][name] = args.action == "start"
    desired["generation"] += 1
    write(desired_path, json.dumps(desired), 0o600)
    generation = desired["generation"]

    def settled():
        status = read_json(SUP / "status.json")
        entries = [status[name] for name in names]
        if args.action == "stop":
            return all(entry["state"] in ("STOPPED", "EXITED", "FATAL") and entry["pid"] is None for entry in entries)
        return all(entry["generation"] == generation and entry["state"] in ("RUNNING", "EXITED", "FATAL")
                   for entry in entries)

    try:
        wait_for(settled, STOP_WAIT_SECONDS + 15, f"supervisor {args.action}", interval=0.1)
    except StepFailed as error:
        print(f"supervisor: {error}", file=sys.stderr)
        return 1
    status = read_json(SUP / "status.json")
    failed = [name for name in names if args.action == "start" and status[name]["state"] != "RUNNING"]
    if failed:
        print(f"supervisor: not running (admission held or failed): {', '.join(failed)}", file=sys.stderr)
        return 1
    return 0


def start_workers_main(args) -> int:
    """start_workers: FPM first (design 5.4), then every supervised group."""
    code = fpm_main(argparse.Namespace(root=args.root, action="start"))
    if code != 0:
        return code
    return ctl_main(argparse.Namespace(root=args.root, action="start", names=[]))


def _fpm_master() -> int | None:
    try:
        pid = int(FPM_PID.read_text().strip())
        command = Path(f"/proc/{pid}/cmdline").read_bytes()
    except (OSError, ValueError):
        return None
    return pid if b"php-fpm: master process" in command else None


def fpm_main(args) -> int:
    """systemctl start|try-reload-or-restart|stop php8.3-fpm.service, for the sandbox's app pool."""
    paths = Paths(Path(args.root))
    master = _fpm_master()
    if args.action == "start":
        if (paths.e2e / "faults" / "fpm-start").exists():
            print("fpm-start: injected fault (run 2: prepare fails at its FPM start)", file=sys.stderr)
            return 1
        if master is None:
            Path("/run/php").mkdir(mode=0o755, exist_ok=True)
            done = run(["/usr/local/sbin/php-fpm", "--fpm-config", paths.e2e / "fpm" / "php-fpm.conf"], check=False)
            if done.returncode != 0:
                print(f"fpm-start: php-fpm exited {done.returncode}: {_tail(done.stderr)}", file=sys.stderr)
                return 1
        try:
            wait_for(lambda: listening(FPM_SOCKET), 15, "the FPM socket")
        except StepFailed as error:
            print(f"fpm-start: {error}", file=sys.stderr)
            return 1
        return 0
    if master is None:
        return 0  # Stopped: stop is done, and try-reload-or-restart does nothing to a stopped unit.
    if args.action == "reload":
        os.kill(master, signal.SIGUSR2)
        time.sleep(0.5)
        try:
            wait_for(lambda: listening(FPM_SOCKET), 15, "the reloaded FPM socket")
        except StepFailed as error:
            print(f"fpm-reload: {error}", file=sys.stderr)
            return 1
        return 0
    os.kill(master, signal.SIGQUIT)
    deadline = time.monotonic() + 30
    while Path(f"/proc/{master}").exists() and time.monotonic() < deadline:
        time.sleep(0.1)
    if Path(f"/proc/{master}").exists():
        os.kill(master, signal.SIGKILL)
    return 0


def timer_main(argv: list[str]) -> int:
    """systemd-run's transient timer as the sandbox's `timer` host command (design 3.10; D14):

        timer --unit=NAME --on-active=SECONDS [--collect] [--property=UMask=0077] [--] COMMAND...
        timer --stop --unit=NAME

    The delay is divided by the drill's time scale. The command runs detached with a clean environment,
    serialised with the harness's controller commands; its record is ROOT/e2e/timers/NAME.json."""
    if len(argv) < 2 or argv[0] != "--root":
        print("timer: usage: timer --root ROOT --unit=NAME --on-active=SECONDS [--] COMMAND...", file=sys.stderr)
        return 2
    paths, rest = Paths(Path(argv[1])), list(argv[2:])
    unit, delay, umask, stop = None, None, 0o077, False
    while rest and rest[0].startswith("--"):
        word = rest.pop(0)
        if word == "--":
            break
        name, _, value = word.partition("=")
        if name == "--unit":
            unit = value
        elif name == "--on-active":
            delay = float(value.rstrip("s"))
        elif name == "--property" and value.startswith("UMask="):
            umask = int(value.split("=", 1)[1], 8)
        elif name == "--stop":
            stop = True
        elif name not in ("--collect", "--property", "--quiet", "--no-block"):
            print(f"timer: unknown option {word}", file=sys.stderr)
            return 2
    if not unit or not re.fullmatch(r"[A-Za-z0-9@._-]{1,128}", unit):
        print("timer: --unit=NAME is required", file=sys.stderr)
        return 2
    record_path = paths.e2e / "timers" / f"{unit}.json"
    if stop:
        if record_path.exists():
            record = read_json(record_path)
            if record.get("pid") and record.get("fired_at") is None:
                try:
                    os.kill(record["pid"], signal.SIGTERM)
                except ProcessLookupError:
                    pass
            record["stopped_at"] = time.time()
            write(record_path, json.dumps(record), 0o600)
        return 0
    if delay is None or not rest:
        print("timer: --on-active=SECONDS and a command are required", file=sys.stderr)
        return 2
    if record_path.exists() and read_json(record_path).get("fired_at") is None \
            and read_json(record_path).get("stopped_at") is None:
        print(f"timer: unit {unit} already exists", file=sys.stderr)
        return 1
    scale = float((paths.e2e / "scale").read_text().strip())
    record = {"unit": unit, "on_active": delay, "scaled": delay / scale, "command": rest, "armed_at": time.time(),
              "fired_at": None, "exit": None, "pid": None}
    write(record_path, json.dumps(record), 0o600)
    first = os.fork()
    if first:
        os.waitpid(first, 0)
        return 0
    os.setsid()
    if os.fork():
        os._exit(0)
    try:
        devnull = os.open(os.devnull, os.O_RDWR)
        log = os.open(paths.logs / f"timer-{unit}.log", os.O_WRONLY | os.O_CREAT | os.O_APPEND, 0o600)
        os.dup2(devnull, 0)
        os.dup2(log, 1)
        os.dup2(log, 2)
        os.closerange(3, 256)
        record["pid"] = os.getpid()
        write(record_path, json.dumps(record), 0o600)
        time.sleep(record["scaled"])
        if read_json(record_path).get("stopped_at") is not None:
            os._exit(0)
        with StepLock(paths.lock):
            record["fired_at"] = time.time()
            done = subprocess.run(rest, env=CLEAN_ENV, stdin=subprocess.DEVNULL, umask=umask)
        record["exit"] = done.returncode
        write(record_path, json.dumps(record), 0o600)
    finally:
        os._exit(0)


def watchdog_main(args) -> int:
    """The watchdog timer (repro-cutover-watchdog.timer: cli.py tick every minute from boot), time-scaled.
    ROOT/e2e/watchdog-offset, when present, moves the tick's wall clock forward (run 2's 0:45)."""
    paths = Paths(Path(args.root))
    log = paths.logs / "watchdog.jsonl"
    while True:
        time.sleep(args.period)
        with StepLock(paths.lock, blocking=False) as held:
            if not held or not STATE.exists():
                continue
            offset_file = paths.e2e / "watchdog-offset"
            offset = float(offset_file.read_text().strip()) if offset_file.exists() else 0.0
            if offset:
                argv = [PY, "-I", "-S", "-c", SHIFTED, f"{offset:.3f}", CLI, "tick"]
            else:
                argv = [PY, "-I", "-S", CLI, "tick"]
            done = run(argv, check=False, timeout=1800)
        with open(log, "a", encoding="utf-8") as stream:
            stream.write(json.dumps({"at": time.time(), "offset": offset, "exit": done.returncode,
                                     "stderr": _tail(done.stderr, 300)}) + "\n")


# --------------------------------------------------------------------------------------------------
# The outer loop, the report and the command line.
# --------------------------------------------------------------------------------------------------

def read_results(path: Path, offset: int) -> tuple[list[dict], int]:
    if not path.exists():
        return [], offset
    data = path.read_bytes()
    lines = data[offset:].decode("utf-8").splitlines()
    return [json.loads(line) for line in lines if line.strip()], len(data)


def report(rows: list[dict], verdict: str, scale: float) -> str:
    out = [f"D18 drill report (time scale {scale:g}: timers and the watchdog period divided by it)",
           f"{'step':34} {'status':9} {'seconds':>8} {'budget':>7}  detail"]
    for row in rows:
        seconds = "-" if row["status"] in ("pending", "skipped") else f"{row['seconds']:.1f}"
        budget = "-" if row.get("budget") is None else f"{row['budget']:g}"
        over = row.get("budget") is not None and row["status"] == "passed" and row["seconds"] > row["budget"]
        out.append(f"{row['step']:34} {row['status'].upper() if row['status'] != 'passed' else 'passed':9} "
                   f"{seconds:>8} {budget:>7}{' !' if over else '  '} {row['detail']}")
    for n in (1, 2):
        times = {row["step"]: row for row in rows}
        init = [times[name]["seconds"] for name in (f"r{n}.rehold", f"r{n}.refresh", f"r{n}.reopen-init")
                if name in times and times[name]["status"] in ("passed", "stand-in")]
        if len(init) == 3:
            out.append(f"r{n} rehold+refresh+reopen: {sum(init):.1f} s (design 4.5: 3 to 5 minutes held, "
                       f"budget {INIT_BUDGET} s)")
    out.append(f"Verdict: {verdict}")
    return "\n".join(out)


def outer_main(args) -> int:
    if args.through is not None and args.through not in BY_NAME:
        print(f"run_drill: unknown step {args.through}; see run_drill.py list", file=sys.stderr)
        return 2
    outer = Outer(args)
    try:
        outer.prepare_container()
    except (StepFailed, OSError) as error:
        print(f"run_drill: refused: {error}", file=sys.stderr)
        return 2
    last = args.through or [name for name in NAMES if int(name[1]) <= args.runs][-1]
    plan = STEPS[:NAMES.index(last) + 1]
    rows: list[dict] = []

    def finish(code: int, verdict: str) -> int:
        print(report(rows, verdict, args.time_scale))
        if code == FAILED_EXIT:
            diagnostics()
        return code

    def diagnostics() -> None:
        """The tails of the sandbox's own service logs (never the app's .env or a defaults file)."""
        for n in (1, 2):
            logs = Paths(root_for(n, outer.srv)).logs
            if logs.is_dir():
                for path in sorted(logs.iterdir()):
                    if path.is_file() and path.stat().st_size:
                        print(f"--- {path} (tail)")
                        print(_tail(path.read_bytes()[-4000:], 1500))

    for n in sorted({step.run for step in plan}):
        steps = [step for step in plan if step.run == n]
        for step in (step for step in steps if step.host):
            began = time.monotonic()
            try:
                detail = outer.host_step(step)
            except (StepFailed, OSError, ValueError, KeyError, subprocess.SubprocessError) as error:
                rows.append({"step": step.name, "status": "failed", "seconds": time.monotonic() - began,
                             "budget": step.budget, "detail": f"{type(error).__name__}: {error}",
                             "ended_at": time.time()})
                return finish(FAILED_EXIT, f"RED: {step.name} failed")
            rows.append({"step": step.name, "status": "passed", "seconds": time.monotonic() - began,
                         "budget": step.budget, "detail": detail, "ended_at": time.time()})
        inside = [step for step in steps if not step.host]
        if not inside:
            continue
        results = Paths(root_for(n, outer.srv)).results
        offset, first, reboot = 0, inside[0].name, False
        while True:
            code = outer.launch(n, first, inside[-1].name, reboot)
            new, offset = read_results(results, offset)
            rows.extend(new)
            if code == REBOOT_EXIT and new:
                first = NAMES[NAMES.index(new[-1]["step"]) + 1]
                reboot = True
                continue
            break
        if code == PENDING_EXIT:
            pending = rows[-1]
            return finish(PENDING_EXIT, f"RED: stopped at {pending['step']} ({pending['detail']})")
        if code != 0:
            tail = _tail(outer.launch_log.read_bytes(), 800) if not new or new[-1]["status"] != "failed" else ""
            if not new or new[-1]["status"] != "failed":
                rows.append({"step": f"r{n}.sandbox", "status": "failed", "seconds": 0, "budget": None,
                             "detail": f"launcher exited {code}: {tail}", "ended_at": time.time()})
            return finish(FAILED_EXIT, f"RED: {rows[-1]['step']} failed")
        hold = [row for row in rows if row["step"] == f"r{n}.hold"]
        if hold:
            # Wall clock from the start of the hold step to the end of the run's last step.
            outage = rows[-1]["ended_at"] - hold[0]["ended_at"] + hold[0]["seconds"]
            rows.append({"step": f"r{n} outage (hold to last step)", "status": "passed", "seconds": outage,
                         "budget": OUTAGE_BUDGET, "detail": f"design 6.2: about 20 minutes with the waiver; "
                                                            f"last step {rows[-1]['step']}"})
    if any(row["status"] in ("stand-in", "skipped") for row in rows):
        return finish(STAND_IN_EXIT, "RED: stand-in run (development only): "
                      f"{sum(row['status'] == 'stand-in' for row in rows)} stood in, "
                      f"{sum(row['status'] == 'skipped' for row in rows)} skipped; this is not the drill")
    return finish(0, f"GREEN: every step through {last} ran for real and passed")


def list_main(_args) -> int:
    print(f"{'step':24} {'needs':16} {'state':8} {'budget':>6}  what")
    for step in STEPS:
        state = "pending" if step.missing else "ready"
        budget = "-" if step.budget is None else f"{step.budget:g}"
        print(f"{step.name:24} {','.join(step.needs) or '-':16} {state:8} {budget:>6}  {step.what}"
              + (f"  [stand-in: {step.stand_in}]" if step.stand_in else ""))
    print("Slices: " + "; ".join(f"{name} {text}" for name, text in SLICES.items()))
    return 0


def main(argv: list[str] | None = None) -> int:
    argv = sys.argv[1:] if argv is None else argv
    if argv[:1] == ["timer"]:
        return timer_main(argv[1:])
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="role", required=True)
    sub.add_parser("list")
    outer = sub.add_parser("run")
    outer.add_argument("--through", help="stop after this step (see list)")
    outer.add_argument("--runs", type=int, choices=(1, 2), default=2)
    outer.add_argument("--stand-ins", action="store_true", help="development only; never green")
    outer.add_argument("--time-scale", type=float, default=60.0)
    outer.add_argument("--release-sha", default="0" * 40)
    outer.add_argument("--src", default="/src")
    outer.add_argument("--srv", default="/srv")
    outer.add_argument("--fixture", default="/fixture")
    outer.add_argument("--vendor", default="/vendor-src")
    inside = sub.add_parser("inside")
    inside.add_argument("--root", required=True)
    inside.add_argument("--from", dest="first", required=True)
    inside.add_argument("--through", required=True)
    inside.add_argument("--launched-at", type=float, required=True)
    inside.add_argument("--stand-ins", action="store_true")
    inside.add_argument("--relaunch", action="store_true")
    inside.add_argument("--fixture", default="/fixture")
    inside.add_argument("--time-scale", type=float, default=60.0)
    for name in ("supervise", "watchdog", "start-workers"):
        role = sub.add_parser(name)
        role.add_argument("--root", required=True)
        if name == "watchdog":
            role.add_argument("--period", type=float, required=True)
    ctl = sub.add_parser("ctl")
    ctl.add_argument("--root", required=True)
    ctl.add_argument("action", choices=("start", "stop"))
    ctl.add_argument("names", nargs="*")
    fpm = sub.add_parser("fpm")
    fpm.add_argument("--root", required=True)
    fpm.add_argument("action", choices=("start", "reload", "stop"))
    args = parser.parse_args(argv)
    if args.role == "inside":
        args.time_scale = float((Paths(Path(args.root)).e2e / "scale").read_text().strip())
    handlers = {"list": list_main, "run": outer_main, "inside": inside_main, "supervise": supervise_main,
                "watchdog": watchdog_main, "ctl": ctl_main, "fpm": fpm_main, "start-workers": start_workers_main}
    return handlers[args.role](args)


if __name__ == "__main__":
    raise SystemExit(main())
