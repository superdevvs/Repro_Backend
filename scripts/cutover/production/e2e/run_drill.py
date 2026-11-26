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

The other roles (inside, supervise, watchdog, ctl, fpm, start-workers, timer, timer-stop, copy-sql) run
only inside the drill's namespace sandbox in a disposable container: each refuses (exit 2) unless
/.dockerenv exists and PID 1 is run-sandbox.sh's init for the drill root it is given.

Exit status: 0 green; 1 a step failed; 3 stopped at a pending step; 4 a stand-in run; 2 usage.
Standard library only. Messages never contain a password or key.
"""

from __future__ import annotations

import argparse
import base64
import contextlib
import errno
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
    "D19": "the drill runbook: the real A3 copy-load recipe and its recorded coordinate",
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
PROBES = "the same checks after a rollback with no prepare"


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
        Step(f"r{n}.copy-load", (), None, "afternoon, harness-local: S_copy loaded into MySQL as repro_maint "
             "with the release's generated layout, verify-fields, complete-schema, migrate:status, the "
             "coordinate after the load (waiver); the night's inputs written"),
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
    Step("r1.check-evidence", ("D11", "D14"), 60, "cli.py check-evidence --write builds the manifest from the "
         "night's inputs; the harness checks what it wrote"),
    Step("r1.prepare", ("D10", "D11", "D14"), 120, "cli.py prepare: switch, MySQL cache, FPM on MySQL, receipt "
         "(backup, armed_from), ready"),
    Step("r1.prepare-checks", ("D11", "D14"), None, "evidence report (D11 contract): every profile and FastCGI "
         "on MySQL with the pin, read-only smokes as both users, token check, gate closed and quiet, drill "
         "snapshots"),
    Step("r1.approval-promote", ("D14",), 60, "cli.py approval-template --phase promote: issued file, timer armed"),
    Step("r1.timer-abort", ("D12", "D14"), 180, "the template's timer aborts at +5 min (time-scaled): "
         "sqlite_authoritative", stand_in="cli.py abort"),
    Step("r1.probes-sqlite", ("D10", "D12"), None, "after the rollback: every profile context reports SQLite "
         "with the SQLite pin, PHP-FPM stopped, gate closed and quiet", stand_in=PROBES),
    Step("r1.unseal-proof", ("D12",), None, "unseal_proof equals sealed_hash"),
    Step("r1.reopen", ("D13", "D14"), 120, "cli.py reopen --hold-scheduler: no scheduler release, FPM started "
         "again, FastCGI reports SQLite with the SQLite pin", stand_in="cli.py reopen"),
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
    Step("r2.check-evidence", ("D11", "D14"), 60, "cli.py check-evidence --write"),
    Step("r2.prepare-fails", ("D10", "D14"), 120, "cli.py prepare exits non-zero at fpm_start (injected), after "
         "the .env switch and the MySQL config build; pending prepare, still frozen"),
    Step("r2.watchdog-rollback", ("D12",), 180, "watchdog tick at 0:45 (clock moved forward) rolls back; the "
         "switch undone", stand_in="watchdog rollback from frozen"),
    Step("r2.probes-sqlite", ("D10", "D12"), None, "as r1.probes-sqlite", stand_in=PROBES),
    Step("r2.unseal-proof", ("D12",), None, "unseal_proof equals sealed_hash"),
    Step("r2.reopen", ("D13", "D14"), 120, "cli.py reopen --hold-scheduler (FastCGI on SQLite), then "
         "release-scheduler", stand_in="cli.py reopen"),
    # Last, so no night step waits on it: the real A3 recipe, D19's, which the harness-local copy load stands
    # in for until D19 and an image with PyMySQL exist.
    Step("r2.a3-copy-load", ("D19", "PYMYSQL"), None, "the real A3 copy-load recipe (D19 runbook: migrate.py "
         "import-empty as repro_maint, verify-fields, complete-schema, the recorded coordinate) on a fresh "
         "empty target, against the harness-local load"),
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
# Routes that must all answer the held 503 (Retry-After, admission_held) while held: non-Telnyx routes of
# both server blocks (frontend.conf's SPA and API, laravel.conf's app routes).
HELD_ROUTES = ((LEGACY, "/up"), (LEGACY, "/api/clients"), (FRONTEND, "/"), (FRONTEND, "/api/clients"))
# The night's input directory (ROOT/e2e/night), the D11 contract proposed in e2e/README.md: the file each
# tool output of design 3.2 step 2 is saved under. check-evidence --write reads them and writes the manifest
# (adapter.EVIDENCE_INPUTS names; backup_check is absent with the off-site waiver).
NIGHT_FILES = {"snapshot": "frozen.sha256", "import": "import-result.json", "verify_fields": "verify-fields.jsonl",
               "migrate_status": "migrate-status.txt", "complete_schema": "complete-schema.json",
               "backup_check": "final-backup-check.json"}
# The coordinate recorded after the copy load (the waiver's), {file, position, gtid_executed} (the manifest's
# and the receipt's `backup`).
BACKUP_COORDINATE = "backup-coordinate.json"
# The harness's own record of the copy's digest, S_copy (not an input of the manifest).
COPY_RECORD = "copy.sha256"
MANIFEST_KEYS = {"version", "run_id", "mode", "backup", "inputs"}
RECEIPT_KEYS = {"version", "run_id", "server_uuid", "database", "backup", "armed_from"}
# The fixture server's own socket path. verify-fields.php requires its credentials' socket to equal @@socket,
# which is the fixture container's path, not the production path the sandbox binds it at.
FIXTURE_SOCKET = "run/mysql.sock"
# The load's SQL statement delimiter (mysqldump's for trigger bodies); no statement may contain it.
SQL_DELIMITER = ";;"
# systemd.time(7) time span units, for the timer stand-in's --on-active.
TIME_UNITS = {"": 1.0, "us": 1e-6, "usec": 1e-6, "ms": 1e-3, "msec": 1e-3, "s": 1.0, "sec": 1.0, "second": 1.0,
              "seconds": 1.0, "m": 60.0, "min": 60.0, "minute": 60.0, "minutes": 60.0, "h": 3600.0, "hr": 3600.0,
              "hour": 3600.0, "hours": 3600.0, "d": 86400.0, "day": 86400.0, "days": 86400.0, "w": 604800.0,
              "week": 604800.0, "weeks": 604800.0, "M": 2629800.0, "month": 2629800.0, "months": 2629800.0,
              "y": 31557600.0, "year": 31557600.0, "years": 31557600.0}
# systemd's exit status of a service whose command could not be executed (status=203/EXEC), and systemctl's
# for a unit that is not loaded.
EXIT_EXEC, NOT_LOADED = 203, 5
UNIT_NAME = r"[A-Za-z0-9@._-]{1,128}"


class StepFailed(RuntimeError):
    pass


def require(condition: bool, message: str) -> None:
    if not condition:
        raise StepFailed(message)


def sandbox_refusal(root) -> str | None:
    """Why a service role must not run here, or None. The roles act on production paths (/run/php, the
    ingress marker, the private Supervisor's /run), so they run only inside this drill root's namespace
    sandbox in a disposable container: /.dockerenv exists and PID 1 is run-sandbox.sh's init
    (ROOT/sync/sandbox_check.py init --root ROOT)."""
    root = Path(str(root))
    if not Path("/.dockerenv").exists():
        return "not in a disposable container (no /.dockerenv)"
    if not root.is_absolute() or not (root / ".repro-drill-root").is_file():
        return f"{root} is not a drill root"
    try:
        argv = Path("/proc/1/cmdline").read_bytes().split(b"\0")
    except OSError:
        return "PID 1 cannot be read"
    checker, root_bytes = os.fsencode(str(root / "sync" / "sandbox_check.py")), os.fsencode(str(root))
    if checker not in argv or b"init" not in argv or b"--root" not in argv \
            or argv[argv.index(b"--root") + 1:argv.index(b"--root") + 2] != [root_bytes]:
        return f"PID 1 is not the sandbox init of {root} (run-sandbox.sh)"
    return None


def route_sha256(values) -> str:
    """The route-hash contract (scripts/cutover/production/testdata/route-hash-vectors.json): every array the
    object of its indexes, sorted keys, separators , and :, ASCII escapes, no NaN; lowercase hex SHA-256. PHP
    prints the raw route values and never hashes; the harness checks this function against the contract's
    vectors before it uses it (Inside.check_route_vectors)."""
    def objects(value):
        if isinstance(value, list):
            return {str(index): objects(item) for index, item in enumerate(value)}
        if isinstance(value, dict):
            return {str(key): objects(item) for key, item in value.items()}
        return value

    return hashlib.sha256(json.dumps(objects(values), sort_keys=True, separators=(",", ":"), ensure_ascii=True,
                                     allow_nan=False).encode("ascii")).hexdigest()


def report_field(document, dotted: str, where: str = "the evidence report"):
    """One value of D11's report by its dotted key path; a missing key fails the step and names the path."""
    value = document
    for part in dotted.split("."):
        if not isinstance(value, dict) or part not in value:
            raise StepFailed(f"{where} has no {dotted} (the D11 report contract in e2e/README.md)")
        value = value[part]
    return value


def timespan(text: str) -> float:
    """A systemd time span (systemd.time(7)) in seconds: "300", "300s", "5min", "1h 30min"; ValueError otherwise."""
    total, rest, found = 0.0, text.strip(), False
    while rest:
        match = re.match(r"([0-9]+(?:\.[0-9]+)?)\s*([A-Za-z]*)\s*", rest)
        if match is None or match.group(2) not in TIME_UNITS:
            raise ValueError(f"Failed to parse time span: {text}")
        total += float(match.group(1)) * TIME_UNITS[match.group(2)]
        rest, found = rest[match.end():], True
    if not found:
        raise ValueError(f"Failed to parse time span: {text}")
    return total


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
        # No SQLite file of the checkout is copied (git ignores them, so a working tree may hold a real one:
        # review D18R-4): the drill database is built fresh, by the app's own migrations, below.
        ignore = shutil.ignore_patterns("__pycache__", "*.pyc", "node_modules", "*.sqlite*")
        for name in ("app", "bootstrap", "config", "resources", "routes", "scripts", "public", "database"):
            shutil.copytree(self.src / name, app / name, symlinks=True, ignore=ignore)
        carried = sorted(str(path.relative_to(app)) for path in app.rglob("*.sqlite*"))
        require(not carried, f"the app copy holds SQLite files from the checkout: {', '.join(carried)}")
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
        # The drill database: a new, empty file (never an existing one), filled only by the migrations.
        for suffix in ("", "-wal", "-shm", "-journal"):
            require(not os.path.lexists(f"{paths.sqlite}{suffix}"),
                    f"{paths.sqlite}{suffix} exists: the drill database must be built fresh from the migrations")
        os.close(os.open(paths.sqlite, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o664))
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
            # systemd-run's transient timer, and `systemctl stop <unit>.timer` to cancel one (D14's proposed
            # commands.timer_stop; e2e/README.md).
            "timer": f"timer --root {root}",
            "timer-stop": f"timer-stop --root {root}",
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
        """Every non-Telnyx route of both server blocks answers the held 503 (review D18R-7)."""
        for host, path in HELD_ROUTES:
            status, headers, body = self.ingress(host, path)
            require(status == 503 and '"admission_held":true' in body and "retry-after" in headers,
                    f"{what}: expected the held 503 with Retry-After at {host}{path}, got {status} {body[:80]}")

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
        self.require_no_route_out()
        if not INGRESS_OPEN.exists():
            self.require_held("before the open marker")
            status, headers, body = http_request(FRONTEND, "POST", MESSAGING, b"{}",
                                                 {"Content-Type": "application/json"})
            require(status == 503 and '"captured":false' in body and "retry-after" in headers,
                    f"an unsigned Telnyx post must reach the capture and be refused, got {status} {body[:80]}")
        return ("MySQL, capture FPM, app FPM, nginx, Supervisor and watchdog up; loopback only, no route out; "
                "held 503 on both server blocks, Telnyx to the capture")

    @staticmethod
    def require_no_route_out() -> None:
        """Loopback is the only interface, and a connection out fails at once with no route (ENETUNREACH), not
        with a timeout that a live network would also give (review D18R-8)."""
        interfaces = sorted(name for _, name in socket.if_nameindex())
        require(interfaces == ["lo"], f"network interfaces other than loopback: {interfaces}")
        for family, address in ((socket.AF_INET, ("192.0.2.1", 443)), (socket.AF_INET6, ("2001:db8::1", 443))):
            try:
                sock = socket.socket(family, socket.SOCK_STREAM)
            except OSError as error:
                require(error.errno == errno.EAFNOSUPPORT, f"cannot open a {family.name} socket: {error}")
                continue
            with sock:
                sock.settimeout(2)
                try:
                    sock.connect(address)
                except OSError as error:
                    require(error.errno == errno.ENETUNREACH,
                            f"a connection to {address[0]} failed with {error!r}, not ENETUNREACH (no route)")
                else:
                    raise StepFailed(f"a connection to {address[0]} left the sandbox")

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
        """Design 3.9: the marker, the scheduler release and the activation receipt removed, every queue paused,
        the workers stopped, no writer process left; the gate untouched; nginx holds."""
        before = self.journal()
        self.cli("rehold")
        activation = Path(self.host["paths"]["activation"])
        require(not INGRESS_OPEN.exists() and not SCHEDULER_RELEASE.exists(), "rehold left a marker or the release")
        require(not activation.exists(), f"rehold left the activation receipt {activation}")
        require(all(self.queue_paused(queue) for queue in self.host["queues"]), "rehold left a queue running")
        require(not self.writers(), "rehold left a writer process")
        require(not self.supervisor_running_any(PROGRAMS), "rehold left a supervised worker running")
        after = self.journal()
        require(after["phase"] == before["phase"] and not after.get("pending"),
                f"rehold changed the journal: {before['phase']} -> {after['phase']}, pending {after.get('pending')}")
        self.require_held("after rehold")
        return "held: marker, release and activation receipt gone, queues paused, workers stopped, no writer"

    def stand_in_reboot(self) -> str:
        return "stand-in: simulated reboot (run-sandbox.sh --reboot empties /run and stops every process)"

    def step_refresh(self) -> str:
        """Design 3.9; with D13 also the deploy fence published, the scheduler release removed and every queue
        paused before the evidence (the candidate's refresh does none of these)."""
        self.cli("refresh")
        require(EVIDENCE.exists(), "refresh published no evidence")
        detail = f"evidence for boot {read_json(EVIDENCE)['boot_id'][:8]}"
        if "D13" in LANDED:
            require(DEPLOY_MARKER.exists(), "refresh did not publish the deploy fence")
            require(not SCHEDULER_RELEASE.exists(), "refresh left the scheduler release")
            require(all(self.queue_paused(queue) for queue in self.host["queues"]), "refresh left a queue running")
            detail += "; deploy fence published, release removed, queues paused"
        return detail

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

    @staticmethod
    def supervisor_running_any(names) -> list:
        status = read_json(DRILL_RUN / "supervisor" / "status.json")
        return [name for name in names if status.get(name, {}).get("pid") is not None]

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

    # The afternoon copy load, harness-local (review D18R-3; e2e/README.md "The copy load" says what it proves).

    def mysql(self, defaults: Path, *arguments, input: bytes | None = None, timeout: float = 900) \
            -> subprocess.CompletedProcess:
        """The mysql client with one defaults file, over the socket only, tab-separated without column names."""
        return run([self.host["mysql"]["client"], f"--defaults-file={defaults}", "--no-login-paths",
                    "--protocol=SOCKET", f"--socket={self.host['mysql']['socket']}", "--default-character-set=utf8mb4",
                    "--batch", "--skip-column-names", *arguments], input=input, timeout=timeout)

    def fixture_password(self, name: str) -> str:
        return (Path(self.args.fixture) / "passwords" / name).read_text(encoding="ascii").strip()

    def copy_snapshot(self, night: Path) -> tuple[Path, str]:
        """S_copy: SQLite's online backup of the live synthetic database, taken as the deploy user (root must not
        create the live file's -wal or -shm), made standalone (rollback journal, no sidecar), then root's 0600."""
        stage = self.root / "copy-stage"
        if stage.exists():
            shutil.rmtree(stage)
        stage.mkdir(mode=0o700)
        shutil.chown(stage, DEPLOY_OWNER, APP_GROUP)
        staged = stage / "copy.sqlite"
        code = ("import sqlite3, sys\nsource = sqlite3.connect('file:' + sys.argv[1] + '?mode=ro', uri=True)\n"
                "target = sqlite3.connect(sys.argv[2])\nsource.backup(target)\nsource.close()\n"
                "mode = target.execute('PRAGMA journal_mode=DELETE').fetchone()[0]\n"
                "check = target.execute('PRAGMA integrity_check').fetchone()[0]\ntarget.close()\nprint(mode, check)\n")
        done = run([PY, "-I", "-S", "-B", "-c", code, self.paths.sqlite, staged], user=DEPLOY_OWNER, group=APP_GROUP,
                   umask=0o077, timeout=900)
        require(done.stdout.split() == [b"delete", b"ok"], f"the copy is not a standalone, intact database: "
                                                            f"{_tail(done.stdout)}")
        sidecars = sorted(path.name for path in stage.iterdir() if path != staged)
        require(not sidecars, f"the copy left sidecar files: {sidecars}")
        copy = night / "copy.sqlite"
        os.replace(staged, copy)
        os.chown(copy, 0, 0)
        os.chmod(copy, 0o600)
        shutil.rmtree(stage)
        digest = sha256_file(copy)
        write(night / COPY_RECORD, f"{digest}  {copy}\n", 0o600)
        return copy, digest

    def empty_target(self, maint: Path, database: str) -> bool:
        """The copy goes into an empty database, as migrate.py import-empty requires. Run 2 finds run 1's copy:
        the database is dropped and created again as repro_maint (it holds ALL on it). True when it was."""
        query = " + ".join(f"(SELECT COUNT(*) FROM information_schema.{table} WHERE {column} = '{database}')"
                           for table, column in (("TABLES", "TABLE_SCHEMA"), ("TRIGGERS", "TRIGGER_SCHEMA"),
                                                 ("ROUTINES", "ROUTINE_SCHEMA"), ("EVENTS", "EVENT_SCHEMA")))
        if int(self.mysql(maint, "-e", f"SELECT {query}").stdout.decode().strip()) == 0:
            return False
        self.mysql(maint, "-e", f"DROP DATABASE `{database}`; CREATE DATABASE `{database}` CHARACTER SET utf8mb4 "
                                f"COLLATE utf8mb4_0900_bin")
        require(int(self.mysql(maint, "-e", f"SELECT {query}").stdout.decode().strip()) == 0,
                f"{database} is not empty after the reset")
        return True

    def maintenance_env(self, account: str, password: str) -> dict:
        """The release's PHP tools against the MySQL copy as `account`: the switch values in the environment
        (Laravel's immutable dotenv keeps them over .env) and no config cache, as a maintenance session runs."""
        mysql = self.host["mysql"]
        return {**CLEAN_ENV, "HOME": pwd.getpwnam(DEPLOY_OWNER).pw_dir,
                "APP_CONFIG_CACHE": str(self.root / "no-config-cache" / "config.php"),
                **mysql["switch"], "DB_USERNAME": account, "DB_PASSWORD": password}

    def step_copy_load(self) -> str:
        """Design 6.2's afternoon, harness-local: the synthetic database's copy S_copy, loaded into the fixture as
        repro_maint with the MySQL layout the release's own importer generates (migrate.py plan, then the
        statements of conversion.apply and migrate.install_triggers, run by the mysql client instead of PyMySQL),
        checked by the release's verify-fields.php, completed by complete-schema.php, migrate:status --pending;
        then the coordinate A3 records (SHOW BINARY LOG STATUS with gtid_executed). Writes the night's inputs
        (NIGHT_FILES) that check-evidence --write reads; the snapshot step adds frozen.sha256."""
        mysql = self.host["mysql"]
        database, target = mysql["database"], mysql["target_uuid"]
        require(re.fullmatch(r"repro_[a-z0-9_]{1,50}", database) is not None, f"unexpected database name {database}")
        night = private_dir(self.paths.e2e / "night")
        stale = sorted(path.name for path in night.iterdir())
        require(not stale, f"the night's input directory is not empty: {stale}")
        fixture = Path(self.args.fixture)
        maint = fixture / "repro_maint.cnf"
        scripts = self.app / "scripts" / "mysql"
        # The target: 8.4.13, the expected server, durable (what migrate.py's destination() checks), empty.
        identity = self.mysql(maint, "-e", "SELECT VERSION(), @@server_uuid, @@innodb_flush_log_at_trx_commit, "
                                           "@@sync_binlog, @@FOREIGN_KEY_CHECKS").stdout.decode().strip().split("\t")
        require(identity[0].split("-")[0] == "8.4.13" and identity[1] == target and identity[2:] == ["1", "1", "1"],
                f"the copy's target is not the expected durable 8.4.13 server: {identity[0]}, {identity[2:]}")
        reset = self.empty_target(maint, database)
        # Triggers need SUPER while the binary log is on, unless log_bin_trust_function_creators is set: on for
        # the reviewed DDL (the load and complete-schema), then back (scripts/mysql/server/README.md; the
        # nightly rehearsal's server runs with it on). Set by the fixture's root, as an administrator would.
        root_cnf = fixture / "root.cnf"
        trusted = self.mysql(root_cnf, "-e", "SELECT @@GLOBAL.log_bin_trust_function_creators").stdout.decode().strip()
        self.mysql(root_cnf, "-e", "SET GLOBAL log_bin_trust_function_creators = ON")
        try:
            return self.load_copy(night, maint, scripts, database, target, reset)
        finally:
            self.mysql(root_cnf, "-e", f"SET GLOBAL log_bin_trust_function_creators = {int(trusted)}")

    def load_copy(self, night: Path, maint: Path, scripts: Path, database: str, target: str, reset: bool) -> str:
        """step_copy_load's body, run while log_bin_trust_function_creators is on."""
        fixture = Path(self.args.fixture)
        copy, s_copy = self.copy_snapshot(night)
        # The release's importer in plan mode (no PyMySQL needed): schema plan and exception audit of S_copy.
        plan = night / "plan"
        done = run([PY, "-B", "-S", "-E", "-s", scripts / "migrate.py", "plan", "--snapshot", copy,
                    "--snapshot-sha256", s_copy, "--output", plan], cwd=night, timeout=900)
        planned = json.loads(done.stdout.decode().strip().splitlines()[-1])
        require(planned.get("mode") == "plan" and planned.get("source_sha256") == s_copy
                and planned.get("source_changed") is False, f"migrate.py plan: {planned}")
        require(read_json(plan / "exceptions-private.json") == [],
                "the copy has orphan rows: the harness-local load installs no legacy constraints")
        # The load's statements, generated by the release's conversion code, then run as repro_maint.
        reports = private_dir(night / "load-reports")
        sql = night / "load.sql"
        generated = json.loads(run([PY, "-I", "-S", "-B", HARNESS, "copy-sql", "--root", self.root, "--scripts",
                                    scripts, "--snapshot", copy, "--plan", plan, "--reports", reports, "--output",
                                    sql], timeout=900).stdout)
        require(not generated["failures"], f"the generated layout reported failures: {generated['failures']}")
        self.mysql(maint, "--binary-mode", database, input=sql.read_bytes(), timeout=1800)
        tables = int(self.mysql(maint, "-e", f"SELECT COUNT(*) FROM information_schema.TABLES WHERE "
                                             f"TABLE_SCHEMA = '{database}'").stdout.decode().strip())
        require(tables == generated["tables"], f"{tables} tables loaded, the plan has {generated['tables']}")
        # verify-fields.php: every field of S_copy against the copy, as the read-only parity account.
        schema_plan = plan / "schema-plan.json"
        credentials = night / "parity-reader.json"
        write(credentials, json.dumps({"unix_socket": str(fixture / FIXTURE_SOCKET), "user": "repro_parity_reader",
                                       "password": self.fixture_password("reader")}), 0o600)
        try:
            done = run(["/usr/bin/php", scripts / "verify-fields.php", f"--snapshot={copy}",
                        f"--snapshot-sha256={s_copy}", f"--schema-plan={schema_plan}",
                        f"--schema-plan-sha256={sha256_file(schema_plan)}", f"--credentials={credentials}",
                        f"--database={database}", f"--expected-server-uuid={target}", "--writers-stopped"],
                       check=False, timeout=1800)
        finally:
            credentials.unlink()
        write(night / NIGHT_FILES["verify_fields"], done.stdout, 0o600)
        lines = [json.loads(line) for line in done.stdout.decode("utf-8", "replace").splitlines() if line.strip()]
        verified = lines[-1] if lines else {}
        require(done.returncode == 0 and verified.get("result") == "all-fields-verified"
                and verified.get("field_mismatches") == 0,
                f"verify-fields.php exited {done.returncode}: {verified or _tail(done.stderr)}")
        # complete-schema.php and migrate:status --pending, as the migrator account (the writer policy's token).
        env = self.maintenance_env("repro_migrator", self.fixture_password("migrator"))
        done = run(["/usr/bin/php", scripts / "complete-schema.php", f"--app={self.app}",
                    f"--expected-server-uuid={target}", "--writers-stopped"], user=DEPLOY_OWNER, group=APP_GROUP,
                   cwd=self.app, umask=0o002, env=env, check=False, timeout=900)
        write(night / NIGHT_FILES["complete_schema"], done.stdout, 0o600)
        require(done.returncode == 0, f"complete-schema.php exited {done.returncode}: {_tail(done.stderr)}")
        completed = json.loads(done.stdout)
        require(completed.get("verified") is True and completed.get("migration_ledger_unchanged") is True
                and completed.get("server_uuid") == target, f"complete-schema.php: {completed}")
        done = run(["/usr/bin/php", "artisan", "migrate:status", "--pending", "--no-ansi", "--no-interaction"],
                   user=DEPLOY_OWNER, group=APP_GROUP, cwd=self.app, umask=0o002, env=env, check=False, timeout=900)
        write(night / NIGHT_FILES["migrate_status"], done.stdout, 0o600)
        require(done.returncode == 0 and " ".join(done.stdout.decode().split()) == "INFO No pending migrations.",
                f"migrate:status --pending exited {done.returncode}: {_tail(done.stdout or done.stderr)}")
        # The coordinate A3 records after the load (the waiver's backup coordinate), with gtid_executed.
        fields = self.mysql(ETC / "mysql-probe.cnf", "-e", "SHOW BINARY LOG STATUS").stdout.decode().rstrip("\n")
        fields = fields.split("\t")
        require(len(fields) == 5 and fields[1].isdigit(), f"SHOW BINARY LOG STATUS printed {fields}")
        coordinate = {"file": fields[0], "position": int(fields[1]),
                      "gtid_executed": re.sub(r"\\n|\s", "", fields[4])}
        require(re.fullmatch(r"binlog\.[0-9]{6}", coordinate["file"]) is not None and coordinate["position"] >= 4
                and coordinate["gtid_executed"].startswith(f"{target}:"), f"unexpected coordinate {coordinate}")
        write(night / BACKUP_COORDINATE, json.dumps(coordinate, sort_keys=True) + "\n", 0o600)
        # The import result D10 binds (adapter.check_inputs): what migrate.py import-empty would print, written by
        # the harness, import_verified only on verify-fields' all-fields-verified, and labelled as this load.
        imported = {
            "mode": "import-empty", "source_sha256": s_copy, "tables": planned["tables"],
            "legacy_exception_relationships": planned["legacy_exception_relationships"],
            "schema_sha256": planned["schema_sha256"], "type_policy_sha256": planned["type_policy_sha256"],
            "source_changed": False, "application_configuration_changed": False, "cutover_ready": False,
            "import_verified": True, "original_rows": verified["rows"], "triggers": generated["triggers"],
            "mysql_warning_count": None, "redundant_mysql_indexes": generated["redundant_indexes"],
            "target_database": database, "target_server_uuid": target,
            "loader": "e2e/run_drill.py harness-local copy load (D18): the statements conversion.apply and "
                      "migrate.install_triggers generate, run by the mysql client as repro_maint; "
                      "migrate.py import-empty did not run (no PyMySQL in the QA image); verified by verify-fields.php",
        }
        write(night / NIGHT_FILES["import"], json.dumps(imported, sort_keys=True) + "\n", 0o600)
        return (f"S_copy {s_copy[:12]}: {tables} tables, {verified['rows']} rows, {generated['triggers']} triggers "
                f"as repro_maint; verify-fields {verified['fields']} fields; complete-schema verified; no pending "
                f"migration; coordinate {coordinate['file']}:{coordinate['position']}"
                + ("; run 1's copy dropped first" if reset else ""))

    def step_a3_copy_load(self) -> str:
        raise StepFailed("not written yet: D19's A3 recipe (migrate.py import-empty as repro_maint with PyMySQL, then "
                         "verify-fields.php, complete-schema.php, the coordinate after it) on a fresh empty target, "
                         "compared with the harness-local load (e2e/README.md)")

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

    def night_inputs(self) -> dict:
        """The night's input files by manifest name; backup_check is None with the off-site waiver (drill)."""
        night = self.paths.e2e / "night"
        waived = self.host["mysql"]["offsite_waiver"]
        return {name: None if name == "backup_check" and waived else night / file for name, file in NIGHT_FILES.items()}

    def coordinate(self) -> dict:
        return read_json(self.paths.e2e / "night" / BACKUP_COORDINATE)

    @staticmethod
    def require_root_private(path: Path, what: str) -> None:
        info = path.stat()
        require(info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o600,
                f"{what} {path} is not root's 0600 (uid {info.st_uid}, mode {stat.S_IMODE(info.st_mode):o})")

    def live_config(self) -> dict:
        return json.loads(run([PY, "-I", "-S", CONTROLLER / "production" / "live_config.py", "--app-root",
                               self.app]).stdout)

    def step_check_evidence(self) -> str:
        """check-evidence --write builds the manifest itself from the night's input directory (design 3.2, D10's
        manifest; the --inputs form is the README's proposal to D11 and D14). The harness only provides the
        inputs, then checks the manifest it wrote: root 0600, D10's keys, this run and mode, the coordinate
        recorded after the copy load, and each input's path and digest; backup_check null with the waiver."""
        night = self.paths.e2e / "night"
        inputs = self.night_inputs()
        for name, path in inputs.items():
            require(path is None or path.is_file(), f"the night's input {name} ({path}) is missing")
        coordinate = self.coordinate()
        self.cli("check-evidence", "--write", "--inputs", str(night))
        path = Path(self.host["paths"]["evidence_inputs"])
        require(path.is_file(), f"check-evidence --write wrote no manifest at {path}")
        self.require_root_private(path, "the evidence inputs manifest")
        manifest = read_json(path)
        require(manifest.keys() == MANIFEST_KEYS, f"the manifest's keys are {sorted(manifest)}, not D10's "
                                                  f"{sorted(MANIFEST_KEYS)}")
        require((manifest["version"], manifest["run_id"], manifest["mode"])
                == (1, self.policy["identity"]["run_id"], self.host["mysql"]["mode"]),
                f"the manifest is version {manifest['version']} for run {manifest['run_id']} in {manifest['mode']} mode")
        require(manifest["backup"] == coordinate, "the manifest's backup is not the coordinate recorded after the "
                                                  "copy load")
        expected = {name: None if file is None else {"path": str(file), "sha256": sha256_file(file)}
                    for name, file in inputs.items()}
        differing = sorted(name for name in expected.keys() | manifest["inputs"].keys()
                           if manifest["inputs"].get(name, "missing") != expected.get(name, "extra"))
        require(not differing, f"the manifest's inputs differ from the night's files: {differing}")
        return (f"manifest by check-evidence: run {manifest['run_id']}, backup {coordinate['file']}:"
                f"{coordinate['position']}, {sum(entry is not None for entry in expected.values())} inputs"
                + (", backup_check null (waiver)" if inputs["backup_check"] is None else ""))

    def step_prepare(self) -> str:
        """Design 3.2 steps 1 to 10: ready; every switch key in .env; the MySQL pin from the cache; FPM answering;
        the switch record of this run with switch_at; the receipt with the recorded backup and armed_from."""
        result = self.cli("prepare")
        require(result["state"]["phase"] == "ready", "prepare did not reach ready")
        mysql, run_id = self.host["mysql"], self.policy["identity"]["run_id"]
        lines = (self.app / ".env").read_text(encoding="utf-8").splitlines()
        values = dict(line.split("=", 1) for line in lines if "=" in line and not line.lstrip().startswith("#"))
        wrong = sorted(key for key, value in mysql["switch"].items() if values.get(key) != value)
        require(not wrong, f".env does not hold the switch value of {wrong}")
        require(bool(values.get("DB_PASSWORD")), ".env has no DB_PASSWORD after the switch")
        live = self.live_config()
        require((live["engine"], live["source"], live["live_config_sha256"])
                == ("mysql", "cache", self.policy["mysql_live_config_sha256"]),
                f"the live config is not the MySQL pin from the cache ({live['engine']}, {live['source']})")
        require(listening(FPM_SOCKET), "PHP-FPM does not answer after prepare (fpm_start)")
        for path, what in ((SWITCH_RECORD, "the switch record"), (RECEIPT, "the receipt")):
            require(path.is_file(), f"{what} is missing after prepare")
            self.require_root_private(path, what)
        record = read_json(SWITCH_RECORD)
        require(record.get("run_id") == run_id and type(record.get("switch_at")) is int,
                "the switch record is not this run's with switch_at set")
        receipt, coordinate = read_json(RECEIPT), self.coordinate()
        require(receipt.keys() == RECEIPT_KEYS, f"the receipt's keys are {sorted(receipt)}")
        require((receipt["run_id"], receipt["server_uuid"], receipt["database"])
                == (run_id, mysql["target_uuid"], mysql["database"]), "the receipt names another run or target")
        require(receipt["backup"] == coordinate, "the receipt's backup is not the coordinate recorded after the copy")
        armed = receipt["armed_from"]
        require(isinstance(armed, dict) and armed.keys() == {"file", "position"}
                and (armed["file"], armed["position"]) >= (coordinate["file"], coordinate["position"]),
                f"the receipt's armed_from {armed} is not a coordinate at or after the backup")
        return (f"ready; .env switched; MySQL pin from the cache; FPM up; receipt backup {coordinate['file']}:"
                f"{coordinate['position']}, armed_from {armed['file']}:{armed['position']}")

    def step_prepare_checks(self) -> str:
        """Design 3.2 step 11 from the structured evidence report (the D11 contract in e2e/README.md): a missing
        key fails the step and names it."""
        require(EVIDENCE_REPORT.is_file(), f"no evidence report at {EVIDENCE_REPORT}")
        self.require_root_private(EVIDENCE_REPORT, "the evidence report")
        report = read_json(EVIDENCE_REPORT)
        require(self.cli("status", "--evidence") == report, "cli.py status --evidence does not print the report")
        field = lambda dotted: report_field(report, dotted)  # noqa: E731
        mysql, web_user = self.host["mysql"], self.host["web_user"]
        expected = {"version": 1, "run_id": self.policy["identity"]["run_id"], "mode": mysql["mode"],
                    "offsite_waiver": mysql["offsite_waiver"],
                    "snapshot_sha256": (self.paths.e2e / "night" / COPY_RECORD).read_text().split()[0],
                    "frozen_snapshot_sha256": read_json(SEALED_HASH)["sha256"], "backup": self.coordinate(),
                    "inputs": read_json(Path(self.host["paths"]["evidence_inputs"]))["inputs"]}
        wrong = sorted(name for name, value in expected.items() if field(name) != value)
        require(not wrong, f"the evidence report's {wrong} differ (drill: snapshot_sha256 is S_copy, "
                           "frozen_snapshot_sha256 S_frozen)")
        pin, target, database = self.policy["mysql_live_config_sha256"], mysql["target_uuid"], mysql["database"]
        on_mysql = {"engine": "mysql", "database": database, "server_uuid": target, "route_sha256": pin, "ok": True}
        profiles = read_json(ETC / "wrappers.json")["profiles"]
        probes = field("checks.profile_probes")
        require(isinstance(probes, list) and probes, "checks.profile_probes is not a non-empty list")
        covered = []
        for index, entry in enumerate(probes):
            where = f"the evidence report's checks.profile_probes[{index}]"
            names = report_field(entry, "profiles", where)
            context = {key: report_field(entry, key, where) for key in ("user", "group", "cwd")}
            wrong = sorted(key for key, value in on_mysql.items() if report_field(entry, key, where) != value)
            require(not wrong, f"{where} ({', '.join(names)}): {wrong} not on MySQL with the pin")
            for name in names:
                require(name in profiles and all(profiles[name][key] == value for key, value in context.items()),
                        f"{where} probed {name} outside its profile's user, group and cwd")
            covered += names
        require(sorted(covered) == sorted(profiles), f"the profile probes cover {sorted(covered)}, not each profile "
                                                     f"once: {sorted(profiles)}")
        wrong = sorted(key for key, value in on_mysql.items() if field(f"checks.fastcgi.{key}") != value)
        require(not wrong and field("checks.fastcgi.opcache") is not None,
                f"the FastCGI probe's {wrong or ['opcache']} are not FPM on MySQL with the pin")
        for user in (web_user, self.host["app_owner"]):
            require(field(f"checks.readonly_smoke.{user}.ok") is True, f"the read-only smoke as {user} is not ok")
        require(field("checks.token_check.problems") == [] and field("checks.token_check.token.acquired") is True,
                "the token check has problems or did not acquire the token")
        quiet = {"gate": "closed", "business_activity": False, "foreign_activity": False}
        wrong = sorted(key for key, value in quiet.items() if field(f"checks.probe.{key}") != value)
        require(not wrong, f"the MySQL probe's {wrong} are not closed and quiet")
        return (f"{len(probes)} profile contexts ({len(covered)} profiles) and FastCGI on MySQL with the pin; "
                f"read-only smokes ok as {web_user} and {self.host['app_owner']}; token acquired, no problems; gate "
                f"closed, no business or foreign activity; drill S_copy {expected['snapshot_sha256'][:12]}, "
                f"S_frozen {expected['frozen_snapshot_sha256'][:12]}")

    def step_approval_promote(self) -> str:
        """Design 3.4, 3.10, 4.4: the issued promote approval and exactly one armed timer through commands.timer
        in systemd-run's form (--collect, UMask 0077, at most 5 minutes) running the conditional abort."""
        self.cli("approval-template", "--phase", "promote")
        require((ISSUED / "promote.json").exists(), "no issued promote approval")
        timers = [read_json(path) for path in (self.paths.e2e / "timers").glob("*.json")]
        armed = [timer for timer in timers if timer.get("fired_at") is None and timer.get("stopped_at") is None]
        require(len(armed) == 1, f"expected exactly one armed timer, found {len(armed)}")
        timer = armed[0]
        require({"abort", "--if-challenge", "--if-phase"} <= set(timer["command"]),
                f"the armed timer does not run the conditional abort: {timer['command']}")
        require(0 < timer["on_active"] <= 300 and timer["collect"] is True and timer["umask"] == "0077",
                f"the timer is not armed with --collect, UMask=0077 and at most 5 minutes: on_active "
                f"{timer['on_active']}, collect {timer['collect']}, umask {timer['umask']}")
        self.notes["promote_timer"] = timer["unit"]
        return f"timer {timer['unit']} armed for {timer['on_active']:g} s (scaled {timer['scaled']:.1f} s)"

    def step_timer_abort(self) -> str:
        unit = self.notes["promote_timer"]
        record = self.paths.e2e / "timers" / f"{unit}.json"
        wait_for(lambda: read_json(record).get("exit") is not None, 600, "the promote timer to fire")
        fired = read_json(record)
        require(fired["exit"] == 0, f"the timer's conditional abort exited {fired['exit']}"
                                    + (f" ({fired['error']})" if fired.get("error") else ""))
        state = self.journal()
        require(state["phase"] == "sqlite_authoritative" and state["history"][-1]["action"] == "abort",
                "the timer did not roll back to SQLite")
        return f"aborted by the timer after {fired['fired_at'] - fired['armed_at']:.1f} s"

    def stand_in_abort(self) -> str:
        result = self.cli("abort")
        require(result["state"]["phase"] == "sqlite_authoritative", "abort did not reach sqlite_authoritative")
        return "stand-in: cli.py abort (the lead aborts; design 6.2)"

    def check_route_vectors(self) -> None:
        """The harness's route hash reproduces every vector of the shared contract (D09)."""
        vectors = read_json(self.app / "scripts" / "cutover" / "production" / "testdata" /
                            "route-hash-vectors.json")["vectors"]
        require(bool(vectors) and all(route_sha256(vector["route_values"]) == vector["route_sha256"]
                                      for vector in vectors),
                "the harness's route hash does not reproduce the contract's vectors")

    def probe_profiles(self, engine: str, pin: str) -> tuple[int, int]:
        """`cutover:smoke --engine-only --json` in every wrapper profile's context (design 3.2 step 11, 3.7 step
        1): its user, group, cwd, umask and environment, deduplicated. Shell-script profiles (studio, lab-tour,
        cubicasa-poll) are probed in theirs too, with the app's artisan. Each context must report `engine` with
        a route hash (the raw route values, hashed here) equal to `pin`. Every profile of the production example
        is probed. Returns (contexts, profiles)."""
        self.check_route_vectors()
        profiles = read_json(ETC / "wrappers.json")["profiles"]
        expected = read_json(self.paths.e2e / "wrappers.template.json")["profiles"]
        require(bool(profiles) and sorted(profiles) == sorted(expected),
                f"wrappers.json names {sorted(profiles)}, not the production example's {sorted(expected)}")
        contexts: dict[tuple, list[str]] = {}
        for name, profile in sorted(profiles.items()):
            key = (profile["user"], profile["group"], profile["cwd"], profile["umask"],
                   json.dumps(profile["env"], sort_keys=True))
            contexts.setdefault(key, []).append(name)
        for (user, group, cwd, umask, environment), names in contexts.items():
            label = f"{', '.join(names)} ({user}:{group} in {cwd}, umask {umask})"
            account = pwd.getpwnam(user)
            env = {"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": account.pw_dir, "USER": user, "LOGNAME": user,
                   "LANG": "C.UTF-8", **json.loads(environment)}
            done = run(["/usr/bin/php", self.app / "artisan", "cutover:smoke", "--engine-only", "--json"],
                       user=user, group=group, cwd=cwd, umask=int(umask, 8), env=env, check=False)
            try:
                report = json.loads(done.stdout)
            except ValueError:
                report = {}
            require(done.returncode == 0 and report.get("ok") is True,
                    f"{label}: cutover:smoke --engine-only exited {done.returncode}: "
                    f"{report.get('problems') or _tail(done.stderr or done.stdout)}")
            require(report.get("engine") == engine, f"{label} reports {report.get('engine')}, not {engine}")
            require(route_sha256(report.get("route_values")) == pin, f"{label}: the route hash is not the {engine} pin")
        return len(contexts), len(profiles)

    def require_fpm_stopped(self, what: str) -> None:
        """The app's PHP-FPM is stopped: no master (pid file or process title) and its socket does not answer."""
        masters = processes(f"php-fpm: master process ({self.paths.e2e / 'fpm' / 'php-fpm.conf'})")
        require(_fpm_master() is None and not masters, f"{what}: the app's PHP-FPM master still runs {masters}")
        require(not listening(FPM_SOCKET), f"{what}: {FPM_SOCKET} still answers")

    def require_fastcgi(self, engine: str, pin: str, what: str) -> str:
        """fastcgi.py into FPM (design 5.3): the pool's own engine and route hash."""
        done = run([PY, "-I", "-S", CONTROLLER / "production" / "fastcgi.py", "--socket", FPM_SOCKET, "--app-root",
                    self.app], check=False)
        require(done.returncode == 0, f"{what}: fastcgi.py exited {done.returncode}: {_tail(done.stderr or done.stdout)}")
        probe = json.loads(done.stdout)
        require(probe["report"].get("engine") == engine and probe["route_sha256"] == pin,
                f"{what}: FastCGI reports {probe['report'].get('engine')}, not {engine} with the {engine} pin")
        return f"FastCGI on {engine} with the pin"

    def step_probes_sqlite(self) -> str:
        """After the rollback (design 3.5, 6.2): every profile context on SQLite with the SQLite pin; PHP-FPM
        stopped (the rollback's drain stops it and fpm_reload leaves a stopped FPM alone: review D18R-1; the
        FastCGI probe comes at the reopen); the MySQL probe reads the gate closed, no business or foreign
        activity."""
        contexts, profiles = self.probe_profiles("sqlite", self.policy["sqlite_live_config_sha256"])
        self.require_fpm_stopped("after the rollback")
        gate = json.loads(run(self.host["commands"]["mysql"]).stdout)
        require((gate.get("gate"), gate.get("business_activity"), gate.get("foreign_activity"))
                == ("closed", False, False),
                f"the MySQL probe reads gate {gate.get('gate')}, business activity {gate.get('business_activity')}, "
                f"foreign activity {gate.get('foreign_activity')}")
        return (f"{contexts} profile contexts ({profiles} profiles) on SQLite with the pin; PHP-FPM stopped; gate "
                "closed and quiet")

    def stand_in_probes(self) -> str:
        return "stand-in: " + self.step_probes_sqlite()

    def step_unseal_proof(self) -> str:
        proof, sealed = read_json(UNSEAL_PROOF), read_json(SEALED_HASH)
        require(proof.get("sha256") == sealed["sha256"], "unseal_proof differs from sealed_hash")
        return f"unseal_proof = sealed_hash = {sealed['sha256'][:12]}"

    def step_reopen(self) -> str:
        """Design 3.7: reopen --hold-scheduler starts FPM again (start_workers) and its FastCGI probe reports
        SQLite before the marker; the harness probes FastCGI again after it (review D18R-1)."""
        self.cli("reopen", "--hold-scheduler")
        require(INGRESS_OPEN.exists() and not SCHEDULER_RELEASE.exists(), "reopen --hold-scheduler: marker or release")
        self.require_open("after reopen --hold-scheduler")
        detail = self.require_fastcgi("sqlite", self.policy["sqlite_live_config_sha256"], "after the reopen")
        if self.run_number == 2:
            self.cli("release-scheduler")
            require(SCHEDULER_RELEASE.exists(), "release-scheduler made no release")
        return (f"open on SQLite with the scheduler held; {detail}"
                + ("; then released" if self.run_number == 2 else ""))

    def stand_in_reopen(self) -> str:
        self.cli("reopen")
        self.require_open("after cli.py reopen")
        detail = self.require_fastcgi("sqlite", self.policy["sqlite_live_config_sha256"], "after the reopen")
        return f"stand-in: cli.py reopen (no replay, no scheduler hold, lifts the deploy marker); {detail}"

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
        """Design 3.2 failure handling: prepare exits non-zero at step 9 (fpm_start, injected), after the .env
        switch and the MySQL config build (step 8 recorded switch_at); no receipt; FPM still stopped; the run
        stays frozen with the pending prepare for the watchdog."""
        fault = self.paths.e2e / "faults" / "fpm-start"
        write(fault, "prepare step 9\n", 0o600)
        try:
            self.cli("prepare", expect=None)
        finally:
            fault.unlink()
        code = self.notes["last_cli"][0]
        require(code != 0, "cli.py prepare exited 0 with fpm_start failing")
        state = self.journal()
        require(state["phase"] == "frozen" and (state.get("pending") or {}).get("action") == "prepare",
                "the failed prepare did not leave a pending prepare in frozen")
        require("DB_CONNECTION=mysql\n" in (self.app / ".env").read_text(encoding="utf-8"),
                "the injected failure came before the .env switch")
        live = self.live_config()
        require((live["engine"], live["source"], live["live_config_sha256"])
                == ("mysql", "cache", self.policy["mysql_live_config_sha256"]),
                "the injected failure came before the MySQL config build")
        require(type(read_json(SWITCH_RECORD).get("switch_at")) is int, "the switch record has no switch_at")
        require(not RECEIPT.exists(), "a receipt exists although prepare stopped at step 9")
        self.require_fpm_stopped("after the failed fpm_start")
        return (f"prepare exited {code} at fpm_start after the .env switch and the MySQL build; pending prepare, "
                "no receipt, FPM stopped")

    def watchdog_rollback(self) -> str:
        state = self.journal()
        offset = state["started_at"] + 45 * 60 + 1 - time.time()
        offset_file = self.paths.e2e / "watchdog-offset"
        write(offset_file, f"{offset:.3f}\n", 0o600)
        started = time.monotonic()
        try:
            wait_for(lambda: self.phase() == "sqlite_authoritative", 300, "the watchdog rollback", interval=0.5)
        finally:
            # One-shot (review D18R-9): later ticks run on the real clock, as production's watchdog does.
            with StepLock(self.paths.lock):
                offset_file.unlink(missing_ok=True)
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
             REBOOT: "stand_in_reboot", PROBES: "stand_in_probes"}


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
    """start_workers: `systemctl start php8.3-fpm.service` first (design 5.4: starts a stopped FPM, leaves a
    running one alone), then every supervised group."""
    code = _fpm_start(Paths(Path(args.root)), restart=False, what="start-workers")
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


def _children(pid: int) -> list[int]:
    found = []
    for entry in Path("/proc").iterdir():
        if entry.name.isdigit():
            try:
                fields = (entry / "stat").read_text().rsplit(")", 1)[1].split()
            except (OSError, IndexError):
                continue
            if int(fields[1]) == pid:
                found.append(int(entry.name))
    return found


def _fpm_stop() -> None:
    """`systemctl stop php8.3-fpm.service`: SIGTERM to the master (Debian's unit sets no KillSignal), which ends
    every request in flight at once (design 5.4: a request still running after the drain is killed by the stop);
    SIGKILL to what is left after 30 s (systemd's stop timeout, shortened)."""
    master = _fpm_master()
    if master is None:
        return
    children = _children(master)
    os.kill(master, signal.SIGTERM)
    deadline = time.monotonic() + 30
    def alive() -> list[int]:
        found = []
        for pid in (*children, master):
            try:
                if b"php-fpm" in Path(f"/proc/{pid}/cmdline").read_bytes():
                    found.append(pid)
            except OSError:
                continue
        return found

    while alive() and time.monotonic() < deadline:
        time.sleep(0.1)
    for pid in alive():
        with contextlib.suppress(ProcessLookupError):
            os.kill(pid, signal.SIGKILL)


def _fpm_start(paths: Paths, *, restart: bool, what: str) -> int:
    """`systemctl restart` (restart, design 3.1's fpm_start) or `systemctl start` (start_workers) of the app's
    PHP-FPM; exits 0 once its socket answers."""
    if restart:
        _fpm_stop()
    if _fpm_master() is None:
        Path("/run/php").mkdir(mode=0o755, exist_ok=True)
        done = run(["/usr/local/sbin/php-fpm", "--fpm-config", paths.e2e / "fpm" / "php-fpm.conf"], check=False)
        if done.returncode != 0:
            print(f"{what}: php-fpm exited {done.returncode}: {_tail(done.stderr)}", file=sys.stderr)
            return 1
    try:
        wait_for(lambda: listening(FPM_SOCKET), 15, "the FPM socket")
    except StepFailed as error:
        print(f"{what}: {error}", file=sys.stderr)
        return 1
    return 0


def fpm_main(args) -> int:
    """The sandbox's app pool: `fpm start` = systemctl restart (fpm_start), `fpm reload` = systemctl
    try-reload-or-restart (fpm_reload), `fpm stop` = systemctl stop (fpm_stop, drain_fpm's second command)."""
    paths = Paths(Path(args.root))
    if args.action == "start":
        if (paths.e2e / "faults" / "fpm-start").exists():
            print("fpm-start: injected fault (run 2: prepare fails at its FPM start)", file=sys.stderr)
            return 1
        return _fpm_start(paths, restart=True, what="fpm-start")
    if args.action == "stop":
        _fpm_stop()
        return 0
    master = _fpm_master()
    if master is None:
        return 0  # try-reload-or-restart does nothing to a stopped unit.
    os.kill(master, signal.SIGUSR2)
    time.sleep(0.5)
    try:
        wait_for(lambda: listening(FPM_SOCKET), 15, "the reloaded FPM socket")
    except StepFailed as error:
        print(f"fpm-reload: {error}", file=sys.stderr)
        return 1
    return 0


class TimerUsage(ValueError):
    pass


def timer_arguments(words: list[str]) -> tuple[dict, list[str]]:
    """systemd-run's options as the timer stand-in takes them: --unit/-u, --on-active, --property/-p (UMask only),
    --collect/-G, --quiet/-q, --no-block; `--NAME=VALUE` or `--NAME VALUE`; the command after the options or
    `--`. Anything else is refused (TimerUsage), so D14 never relies on something the stand-in ignores."""
    options = {"unit": None, "on_active": None, "umask": None, "collect": False}
    index = 0
    while index < len(words):
        word = words[index]
        if word == "--":
            index += 1
            break
        if not word.startswith("-") or word == "-":
            break
        if word.startswith("--"):
            name, equals, value = word[2:].partition("=")
            if name in ("unit", "on-active", "property"):
                if not equals:
                    index += 1
                    if index >= len(words):
                        raise TimerUsage(f"option --{name} needs a value")
                    value = words[index]
            elif name in ("collect", "quiet", "no-block") and not equals:
                value = None
            else:
                raise TimerUsage(f"unknown or unsupported option {word}")
        else:
            letter, value = word[1:2], word[2:]
            name = {"u": "unit", "p": "property", "G": "collect", "q": "quiet"}.get(letter)
            if name is None or (name in ("collect", "quiet") and value):
                raise TimerUsage(f"unknown or unsupported option {word}")
            if name in ("unit", "property") and not value:
                index += 1
                if index >= len(words):
                    raise TimerUsage(f"option -{letter} needs a value")
                value = words[index]
        if name == "unit":
            options["unit"] = value
        elif name == "on-active":
            try:
                options["on_active"] = timespan(value)
            except ValueError as error:
                raise TimerUsage(str(error)) from None
        elif name == "property":
            key, _, setting = value.partition("=")
            if key != "UMask" or re.fullmatch(r"0?[0-7]{3}", setting) is None:
                raise TimerUsage(f"the stand-in supports only --property=UMask=NNNN, not {value}")
            options["umask"] = setting.zfill(4)
        elif name == "collect":
            options["collect"] = True
        index += 1
    return options, words[index:]


@contextlib.contextmanager
def _unit_lock(paths: Paths, unit: str):
    """Serialises the reads and writes of one timer's record (arm, fire, stop)."""
    with open(paths.e2e / "timers" / f"{unit}.lock", "a+b") as stream:
        fcntl.flock(stream, fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(stream, fcntl.LOCK_UN)


def _timer_record(paths: Paths, unit: str) -> dict | None:
    path = paths.e2e / "timers" / f"{unit}.json"
    return read_json(path) if path.exists() else None


def _update_timer(paths: Paths, unit: str, **changes) -> dict:
    with _unit_lock(paths, unit):
        record = {**(_timer_record(paths, unit) or {}), **changes}
        write(paths.e2e / "timers" / f"{unit}.json", json.dumps(record), 0o600)
    return record


def timer_main(argv: list[str]) -> int:
    """`systemd-run` for a transient timer (design 3.10; the sandbox's host.json commands.timer, D14):

        timer --root ROOT --unit=NAME --on-active=SPAN [--collect] [--property=UMask=0077] [--] COMMAND...

    SPAN is a systemd time span (300, 300s, 5min). The delay is divided by the drill's time scale. COMMAND runs
    detached with that umask and a clean environment, serialised with the harness's controller commands. A
    unit stays loaded after it fired until `timer-stop stop NAME.timer` (systemd's RemainAfterElapse=yes), and
    arming a loaded name is refused (exit 1) as systemd-run refuses it. A command that cannot be executed is
    recorded with exit 203 (systemd's 203/EXEC) and the error. Record: ROOT/e2e/timers/NAME.json. Exit 2: an
    option the stand-in does not support, a malformed one, or a refusal outside the drill sandbox."""
    if len(argv) < 2 or argv[0] != "--root":
        print("timer: usage: timer --root ROOT --unit=NAME --on-active=SPAN [--] COMMAND...", file=sys.stderr)
        return 2
    refusal = sandbox_refusal(argv[1])
    if refusal:
        print(f"timer: refused: {refusal}", file=sys.stderr)
        return 2
    paths = Paths(Path(argv[1]))
    try:
        options, command = timer_arguments(list(argv[2:]))
    except TimerUsage as error:
        print(f"timer: {error}", file=sys.stderr)
        return 2
    unit = options["unit"]
    if not unit or re.fullmatch(UNIT_NAME, unit) is None or unit.endswith((".timer", ".service")):
        print("timer: --unit=NAME (without a .timer or .service suffix) is required", file=sys.stderr)
        return 2
    if options["on_active"] is None or not command:
        print("timer: --on-active=SPAN and a command are required", file=sys.stderr)
        return 2
    scale = float((paths.e2e / "scale").read_text().strip())
    with _unit_lock(paths, unit):
        loaded = _timer_record(paths, unit)
        if loaded is not None and loaded.get("stopped_at") is None:
            print(f"Failed to start transient timer unit: Unit {unit}.timer was already loaded or has a fragment "
                  "file.", file=sys.stderr)
            return 1
        record = {"unit": unit, "on_active": options["on_active"], "scaled": options["on_active"] / scale,
                  "umask": options["umask"], "collect": options["collect"], "command": command,
                  "armed_at": time.time(), "pid": None, "fired_at": None, "exit": None, "error": None,
                  "finished_at": None, "stopped_at": None}
        write(paths.e2e / "timers" / f"{unit}.json", json.dumps(record), 0o600)
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
        _update_timer(paths, unit, pid=os.getpid())
        time.sleep(record["scaled"])
        with _unit_lock(paths, unit):
            current = _timer_record(paths, unit) or {}
            if current.get("stopped_at") is not None or current.get("armed_at") != record["armed_at"]:
                os._exit(0)  # Stopped before it elapsed (or replaced): the command never runs.
            current["fired_at"] = time.time()
            write(paths.e2e / "timers" / f"{unit}.json", json.dumps(current), 0o600)
        umask = int(options["umask"] or "0022", 8)
        with StepLock(paths.lock):
            try:
                done = subprocess.run(command, env=CLEAN_ENV, stdin=subprocess.DEVNULL, umask=umask)
                result = {"exit": done.returncode, "error": None}
            except OSError as error:
                result = {"exit": EXIT_EXEC, "error": f"{type(error).__name__}: {error.strerror or error}"}
        _update_timer(paths, unit, finished_at=time.time(), **result)
    except BaseException as error:  # noqa: BLE001 - recorded, never swallowed silently
        with contextlib.suppress(BaseException):
            _update_timer(paths, unit, finished_at=time.time(), exit=EXIT_EXEC, error=f"timer stand-in: {error!r}")
    finally:
        os._exit(0)


def timer_stop_main(argv: list[str]) -> int:
    """`systemctl stop NAME.timer` for the timer stand-in (D14's proposed commands.timer_stop):

        timer-stop --root ROOT stop NAME.timer

    Cancels an armed timer (its command never runs) or unloads an elapsed one, so the name can be armed again;
    a command already running goes on (stopping the timer does not stop its service). Exit 5 when NAME.timer
    is not loaded, as systemctl; exit 2 for any other form or outside the drill sandbox."""
    if len(argv) < 2 or argv[0] != "--root":
        print("timer-stop: usage: timer-stop --root ROOT stop NAME.timer", file=sys.stderr)
        return 2
    refusal = sandbox_refusal(argv[1])
    if refusal:
        print(f"timer-stop: refused: {refusal}", file=sys.stderr)
        return 2
    paths, rest = Paths(Path(argv[1])), list(argv[2:])
    unit = rest[1][:-len(".timer")] if len(rest) == 2 and rest[1].endswith(".timer") else ""
    if rest[:1] != ["stop"] or re.fullmatch(UNIT_NAME, unit) is None:
        print("timer-stop: the stand-in runs only `stop NAME.timer`", file=sys.stderr)
        return 2
    with _unit_lock(paths, unit):
        record = _timer_record(paths, unit)
        if record is None or record.get("stopped_at") is not None:
            print(f"Failed to stop {unit}.timer: Unit {unit}.timer not loaded.", file=sys.stderr)
            return NOT_LOADED
        if record.get("fired_at") is None and record.get("pid"):
            with contextlib.suppress(ProcessLookupError):
                os.kill(record["pid"], signal.SIGTERM)
        record["stopped_at"] = time.time()
        write(paths.e2e / "timers" / f"{unit}.json", json.dumps(record), 0o600)
    return 0


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


def sql_literal(value) -> str:
    """A row value as PyMySQL sends it to migrate.py's connection (binary_prefix): NULL, integers as digits, a
    float as its repr with an exponent, text as a utf8mb4 string, bytes as a binary string. Text and bytes are
    hex literals, so no value needs escaping."""
    if value is None:
        return "NULL"
    if isinstance(value, bool):
        return "1" if value else "0"
    if isinstance(value, int):
        return str(value)
    if isinstance(value, float):
        text = repr(value)
        require(text not in ("inf", "-inf", "nan"), f"{text} cannot be sent to MySQL")
        return text if "e" in text else text + "e0"
    if isinstance(value, str):
        return "_utf8mb4 X'" + value.encode("utf-8").hex() + "'"
    if isinstance(value, (bytes, bytearray, memoryview)):
        return "_binary X'" + bytes(value).hex() + "'"
    raise StepFailed(f"a value of type {type(value).__name__} has no SQL literal here")


class _SqlRecorder:
    """Stands in for migrate.py's PyMySQL connection while conversion.apply and migrate.install_triggers run:
    every statement they send is recorded, executemany's rows as one multi-row INSERT (as PyMySQL's executemany
    builds it). SHOW TABLES reads empty: the harness checked the target is empty before the load."""

    def __init__(self):
        self.statements: list[str] = []

    def cursor(self, *_kind):
        return self

    def __enter__(self):
        return self

    def __exit__(self, *_exc):
        return False

    def execute(self, statement: str, args=None) -> int:
        if statement == "SHOW TABLES":
            return 0
        require(args is None, f"unexpected parameters for {statement[:60]}")
        self.statements.append(statement)
        return 0

    def executemany(self, statement: str, rows) -> int:
        head, separator, _ = statement.partition(" VALUES (")
        require(bool(separator) and head.startswith("INSERT INTO "), f"unexpected executemany: {statement[:60]}")
        rows = list(rows)
        self.statements.append(head + " VALUES " + ",".join("(" + ",".join(map(sql_literal, row)) + ")"
                                                            for row in rows))
        return len(rows)

    def fetchall(self):
        return ()

    def fetchone(self):
        return None


def copy_sql_main(args) -> int:
    """The harness-local copy load's SQL (review D18R-3): the release's own conversion code (scripts/mysql,
    PyMySQL not needed for this) turns migrate.py plan's schema plan and the copy into the statements
    migrate.py import-empty would send: CREATE TABLE, the rows, AUTO_INCREMENT, indexes, foreign keys, checks,
    then the triggers. Written for the mysql client with the delimiter ;; (trigger bodies hold ;). Prints the
    counts as JSON."""
    try:
        sys.path.insert(0, str(Path(args.scripts)))
        import conversion  # noqa: E402 - the release's importer modules, from the app copy
        import migrate  # noqa: E402

        plan = Path(args.plan)
        schema, planned = read_json(plan / "schema-plan.json"), read_json(plan / "result.json")
        require(conversion.schema_fingerprint(schema) == planned["schema_sha256"],
                "the schema plan is not the one migrate.py plan fingerprinted")
        conversion.REPORTS = Path(args.reports)
        source = sqlite3.connect(f"file:{args.snapshot}?mode=ro&immutable=1", uri=True)
        source.row_factory = sqlite3.Row
        recorder = _SqlRecorder()
        with contextlib.redirect_stdout(sys.stderr):  # conversion.emit prints its progress
            constraints = conversion.apply(recorder, source, schema)
            triggers = migrate.install_triggers(recorder, source)
        source.close()
        statements = ["SET SESSION time_zone='+00:00'",
                      "SET SESSION sql_mode=CONCAT(@@SESSION.sql_mode, ',NO_AUTO_VALUE_ON_ZERO')",
                      *recorder.statements]
        clashing = [statement[:60] for statement in statements if SQL_DELIMITER in statement]
        require(not clashing, f"statements hold the delimiter {SQL_DELIMITER}: {clashing}")
        write(Path(args.output), f"DELIMITER {SQL_DELIMITER}\n"
              + "".join(f"{statement}{SQL_DELIMITER}\n" for statement in statements), 0o600)
    except (RuntimeError, OSError, ValueError, KeyError, sqlite3.Error) as error:
        print(f"copy-sql: {type(error).__name__}: {error}", file=sys.stderr)
        return 1
    print(json.dumps({"statements": len(statements), "tables": len(schema), "triggers": triggers,
                      "redundant_indexes": constraints["redundant_indexes"], "failures": constraints["failures"]}))
    return 0


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
    """The step table, then the sums. A sum counts only steps that ran for real; `!` marks a passed step or a
    sum over its budget."""
    out = [f"D18 drill report (time scale {scale:g}: timers and the watchdog period divided by it)",
           f"{'step':38} {'status':9} {'seconds':>8} {'budget':>7}  detail"]
    for row in rows:
        seconds = "-" if row["status"] in ("pending", "skipped") else f"{row['seconds']:.1f}"
        budget = "-" if row.get("budget") is None else f"{row['budget']:g}"
        over = row.get("budget") is not None and row["status"] in ("passed", "summary") \
            and row["seconds"] > row["budget"]
        out.append(f"{row['step']:38} {row['status'].upper() if row['status'] != 'passed' else 'passed':9} "
                   f"{seconds:>8} {budget:>7}{' !' if over else '  '} {row['detail']}")
    times = {row["step"]: row for row in rows}
    for n in (1, 2):
        init = [times[name] for name in (f"r{n}.rehold", f"r{n}.refresh", f"r{n}.reopen-init") if name in times]
        if len(init) < 3:
            continue
        unreal = [row["step"] for row in init if row["status"] != "passed"]
        if unreal:
            out.append(f"r{n} rehold+refresh+reopen: not measured ({', '.join(unreal)} did not run for real)")
            continue
        total = sum(row["seconds"] for row in init)
        out.append(f"r{n} rehold+refresh+reopen: {total:.1f} s{' !' if total > INIT_BUDGET else ''} (design 4.5: 3 "
                   f"to 5 minutes held, budget {INIT_BUDGET} s)")
    out.append(f"Verdict: {verdict}")
    return "\n".join(out)


def outage_row(rows: list[dict], n: int) -> dict | None:
    """Design 6.2's outage: from the start of the hold to the end of run n's last passed step. Labelled, and not
    a measurement, when a step after the hold was stood in or skipped."""
    own = [row for row in rows if row["step"].startswith(f"r{n}.")]
    hold = [row for row in own if row["step"] == f"r{n}.hold" and row["status"] == "passed"]
    if not hold:
        return None
    after = own[own.index(hold[0]):]
    passed = [row for row in after if row["status"] == "passed"]
    unreal = [row["step"] for row in after if row["status"] != "passed"]
    last = passed[-1]
    return {"step": f"r{n} outage (hold to last passed step)", "status": "summary",
            "seconds": last["ended_at"] - hold[0]["ended_at"] + hold[0]["seconds"], "budget": OUTAGE_BUDGET,
            "detail": f"design 6.2: about 20 minutes with the waiver; last passed step {last['step']}"
                      + (f"; not a measurement: {len(unreal)} steps stood in or skipped" if unreal else ""),
            "ended_at": last["ended_at"]}


def outer_main(args) -> int:
    if args.through is not None and args.through not in BY_NAME:
        print(f"run_drill: unknown step {args.through}; see run_drill.py list", file=sys.stderr)
        return 2
    if args.runs is not None and args.through is not None and BY_NAME[args.through].run > args.runs:
        print(f"run_drill: --runs {args.runs} and --through {args.through} disagree", file=sys.stderr)
        return 2
    if not 0 < args.time_scale <= 3600:
        print("run_drill: --time-scale must be above 0 and at most 3600", file=sys.stderr)
        return 2
    outer = Outer(args)
    try:
        outer.prepare_container()
    except (StepFailed, OSError) as error:
        print(f"run_drill: refused: {error}", file=sys.stderr)
        return 2
    last = args.through or [name for name in NAMES if int(name[1]) <= (args.runs or 2)][-1]
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
        outage = outage_row(rows, n)
        if outage is not None:
            rows.append(outage)
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
    if argv[:1] == ["timer-stop"]:
        return timer_stop_main(argv[1:])
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="role", required=True)
    sub.add_parser("list")
    outer = sub.add_parser("run")
    outer.add_argument("--through", help="stop after this step (see list)")
    outer.add_argument("--runs", type=int, choices=(1, 2), help="1 stops after run 1 (default: both runs)")
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
    copy_sql = sub.add_parser("copy-sql")
    for name in ("--root", "--scripts", "--snapshot", "--plan", "--reports", "--output"):
        copy_sql.add_argument(name, required=True)
    args = parser.parse_args(argv)
    if args.role not in ("list", "run"):
        refusal = sandbox_refusal(args.root)
        if refusal:
            print(f"run_drill {args.role}: refused: {refusal}", file=sys.stderr)
            return 2
    if args.role == "inside":
        args.time_scale = float((Paths(Path(args.root)).e2e / "scale").read_text().strip())
    handlers = {"list": list_main, "run": outer_main, "inside": inside_main, "supervise": supervise_main,
                "watchdog": watchdog_main, "ctl": ctl_main, "fpm": fpm_main, "start-workers": start_workers_main,
                "copy-sql": copy_sql_main}
    return handlers[args.role](args)


if __name__ == "__main__":
    raise SystemExit(main())
