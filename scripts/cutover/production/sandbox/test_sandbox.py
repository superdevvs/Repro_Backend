"""Tests for the namespace sandbox (design 7.3): launcher, checks and sandbox config.

The launcher tests need a disposable PRIVILEGED container. They install fake
production paths at the real fixed paths (/etc/repro-cutover-fence,
/var/lib/repro-cutover-fence, /run/repro-cutover-ingress, ...), fake
systemctl, systemd-run, supervisorctl and service binaries that log every
call, a fake production worker and a host listener, then prove that nothing
inside the sandbox reaches any of them. Never run them on a real host.

    docker run --rm --pull never --privileged --network none --cpus 2 --memory 2g \
      --tmpfs /tmp:rw,exec,size=512m,mode=1777 \
      --mount type=bind,source=<checkout>,target=/src,readonly \
      --entrypoint /bin/sh repro-mysql-rehearsal-qa:20261122 \
      /src/scripts/cutover/run-linux-tests.sh production/sandbox/test_sandbox.py

The make_config tests need Linux only (no root, no privileges).
"""

from __future__ import annotations

import hashlib
import json
import os
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import uuid
from pathlib import Path

HERE = Path(__file__).resolve().parent
CUTOVER = HERE.parents[1]
LAUNCHER = HERE / "run-sandbox.sh"
PYTHON = "/usr/bin/python3"
REFUSED = 96
SHIM_REFUSED = 97

# Fake host fixtures. SANDBOX_MOUNT is a tmpfs the tests mount for sandbox roots: an
# overlay upper directory cannot live on the container's own overlay root.
SANDBOX_MOUNT = Path("/srv/repro-sandbox-test")
HOST_LOG_DIRECTORY = Path("/srv/repro-sandbox-host")
HOST_CALLS = HOST_LOG_DIRECTORY / "calls.log"
DUMMY_INTERFACE = "sbxdummy0"
BOUND = (
    "/etc/repro-cutover-fence", "/var/lib/repro-cutover-fence", "/var/lib/repro-cutover-archive",
    "/var/lib/repro-cutover-keep", "/run/repro-cutover-fence", "/run/repro-cutover-ingress",
    "/etc/repro-database-cutover", "/opt/repro-cutover-fence", "/run/repro-telnyx-capture",
    "/etc/repro-telnyx-capture",
)
# Present on the fake host, like production after W1. The other bound paths are absent, like
# production before W1: the sandbox must not create them on the host.
FAKE_FILES = {
    "/etc/repro-cutover-fence/host.json": b'{"fake production": "host config"}\n',
    "/etc/repro-cutover-fence/policy.json": b'{"fake production": "policy"}\n',
    "/var/lib/repro-cutover-fence/state.json": b'{"fake production": "journal"}\n',
    "/run/repro-cutover-fence/verified-engine.json": b'{"fake production": "evidence"}\n',
    "/run/repro-cutover-ingress/open": b'{"fake production": "open marker"}\n',
    "/etc/repro-database-cutover/README": b"fake production deploy marker directory\n",
    "/var/www/backend/.env": b"APP_ENV=production\nDB_CONNECTION=sqlite\n",
    "/home/maverick/.repro-backend-deploy.lock": b"",
}
MISSING_ON_HOST = ("/var/lib/repro-cutover-archive", "/var/lib/repro-cutover-keep",
                   "/run/repro-telnyx-capture", "/etc/repro-telnyx-capture")
SHIMMED = ("/usr/bin/systemctl", "/usr/bin/systemd-run", "/usr/bin/supervisorctl", "/usr/sbin/service")
TREES = ("/etc/repro-cutover-fence", "/var/lib/repro-cutover-fence", "/run/repro-cutover-fence",
         "/run/repro-cutover-ingress", "/etc/repro-database-cutover", "/opt/repro-cutover-fence",
         "/var/www/backend", "/home/maverick", str(HOST_LOG_DIRECTORY))
SHALLOW = ("/etc", "/var", "/var/lib", "/var/www", "/run", "/opt", "/home", "/usr/bin", "/usr/sbin", "/tmp", "/srv")
BOOT_ID = Path("/proc/sys/kernel/random/boot_id")
FAKE_BINARY = f"#!/bin/sh\necho \"$0 $*\" >> {HOST_CALLS}\nexit 0\n"


def _describe(path: str):
    try:
        info = os.lstat(path)
    except FileNotFoundError:
        return None
    record = [stat.S_IFMT(info.st_mode), stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid, info.st_ino,
              info.st_mtime_ns]
    if stat.S_ISREG(info.st_mode):
        record.append(hashlib.sha256(Path(path).read_bytes()).hexdigest())
    elif stat.S_ISLNK(info.st_mode):
        record.append(os.readlink(path))
    elif stat.S_ISDIR(info.st_mode):
        record.append(sorted(os.listdir(path)))
    return record


def host_snapshot() -> dict:
    """Everything the sandbox must never change on the host."""
    found = {}
    for tree in TREES:
        found[tree] = _describe(tree)
        for directory, folders, files in os.walk(tree):
            for name in folders + files:
                found[os.path.join(directory, name)] = _describe(os.path.join(directory, name))
    for path in SHALLOW + SHIMMED + MISSING_ON_HOST:
        found[path] = _describe(path)
    for path in BOUND:
        for name in (".repro-sandbox-canary", ".repro-sandbox-canary-in"):
            found[os.path.join(path, name)] = _describe(os.path.join(path, name))
    found["boot_id"] = BOOT_ID.read_text(encoding="ascii")
    return found


def cmdlines() -> dict[int, str]:
    found = {}
    for entry in Path("/proc").iterdir():
        if entry.name.isdigit() and int(entry.name) != os.getpid():
            try:
                found[int(entry.name)] = (entry / "cmdline").read_bytes().replace(b"\0", b" ").decode("utf-8", "replace")
            except (FileNotFoundError, ProcessLookupError):
                continue
    return found


def privileged_container() -> bool:
    return sys.platform == "linux" and os.geteuid() == 0 and Path("/.dockerenv").exists()


@unittest.skipUnless(privileged_container(), "Needs root in a disposable privileged container")
class SandboxLauncherTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        probe = subprocess.run(["unshare", "--mount", "--pid", "--net", "--fork", "--mount-proc", "/bin/true"],
                               capture_output=True, text=True)
        if probe.returncode != 0:
            raise RuntimeError(f"These tests need docker run --privileged: {probe.stderr.strip()}")
        cls.tag = uuid.uuid4().hex[:12]
        cls._install_fake_production()
        SANDBOX_MOUNT.mkdir(parents=True, exist_ok=True)
        if not os.path.ismount(SANDBOX_MOUNT):
            subprocess.run(["mount", "-t", "tmpfs", "-o", "mode=0755,size=512m", "tmpfs", str(SANDBOX_MOUNT)],
                           check=True)
        # A host interface other than loopback, so 'only loopback inside' is not an artefact of
        # the container's own --network none.
        if subprocess.run(["ip", "link", "show", DUMMY_INTERFACE], capture_output=True).returncode != 0:
            subprocess.run(["ip", "link", "add", DUMMY_INTERFACE, "type", "dummy"], check=True)
            subprocess.run(["ip", "link", "set", DUMMY_INTERFACE, "up"], check=True)
        # A host service on loopback (stands for a provider, MySQL over TCP or Supervisor's HTTP port).
        cls.listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        cls.listener.bind(("127.0.0.1", 0))
        cls.listener.listen(8)
        cls.host_port = cls.listener.getsockname()[1]
        threading.Thread(target=cls._accept, daemon=True).start()
        # A fake production queue worker: the adapter's /proc scans must never see it inside.
        cls.worker = subprocess.Popen([f"php artisan queue:work {cls.tag}", "3600"], executable="/bin/sleep")

    @classmethod
    def tearDownClass(cls):
        cls.worker.kill()
        cls.worker.wait()
        cls.listener.close()

    @classmethod
    def _accept(cls):
        while True:
            try:
                connection, _ = cls.listener.accept()
            except OSError:
                return
            connection.sendall(b"host\n")
            connection.close()

    @classmethod
    def _install_fake_production(cls):
        if subprocess.run(["getent", "passwd", "maverick"], capture_output=True).returncode != 0:
            subprocess.run(["useradd", "--no-create-home", "--home-dir", "/home/maverick", "--gid", "www-data",
                            "--shell", "/bin/sh", "maverick"], check=True)
        for path, content in FAKE_FILES.items():
            target = Path(path)
            target.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
            if not target.exists():
                target.write_bytes(content)
        os.chmod("/var/lib/repro-cutover-fence", 0o700)
        guard = Path("/opt/repro-cutover-fence/guard.py")
        if not guard.exists():
            guard.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
            shutil.copyfile(CUTOVER / "fence" / "guard.py", guard)
        for path in MISSING_ON_HOST:
            assert not os.path.lexists(path), f"{path} must be absent on the fake host"
        HOST_LOG_DIRECTORY.mkdir(parents=True, exist_ok=True)
        HOST_CALLS.touch()
        for path in SHIMMED:
            Path(path).write_text(FAKE_BINARY, encoding="ascii")
            os.chmod(path, 0o755)

    def setUp(self):
        self.root = SANDBOX_MOUNT / f"root-{uuid.uuid4().hex[:8]}"
        self.root.mkdir(mode=0o700)
        self.before = host_snapshot()

    def tearDown(self):
        self.assertEqual(self.before, host_snapshot(), "The sandbox changed a host path")
        self.assertEqual("", HOST_CALLS.read_text(encoding="ascii"), "A host service manager was called")
        self.assertNoSandboxProcesses()

    # Helpers.

    def launch(self, *command: str, launcher: Path = LAUNCHER, root: Path | None = None, reboot: bool = False,
               timeout: float = 120) -> subprocess.CompletedProcess:
        argv = ["/bin/sh", str(launcher)] + (["--reboot"] if reboot else []) + [str(root or self.root), "--", *command]
        return subprocess.run(argv, capture_output=True, text=True, timeout=timeout, stdin=subprocess.DEVNULL)

    def inside(self, code: str, *arguments: str, **options) -> dict:
        result = self.launch(PYTHON, "-I", "-S", "-c", code, *arguments, **options)
        self.assertEqual(0, result.returncode, result.stderr)
        return json.loads(result.stdout)

    def mutant(self, *lines: str) -> Path:
        """A container-local copy of the sandbox files with the given launcher lines deleted."""
        copy = SANDBOX_MOUNT / "mutants" / uuid.uuid4().hex[:8]
        shutil.copytree(HERE, copy, ignore=shutil.ignore_patterns("__pycache__"))
        launcher = copy / "run-sandbox.sh"
        text = launcher.read_text(encoding="utf-8").splitlines(keepends=True)
        for line in lines:
            matching = [number for number, existing in enumerate(text) if existing.strip() == line]
            self.assertEqual(1, len(matching), f"Expected exactly one launcher line {line!r}")
            del text[matching[0]]
        launcher.write_text("".join(text), encoding="utf-8")
        return launcher

    def assertNoSandboxProcesses(self):
        deadline = time.monotonic() + 5
        while True:
            left = {pid: line for pid, line in cmdlines().items()
                    if "sandbox_check.py" in line or "run-sandbox.sh" in line or "unshare" in line
                    or f"sandbox-orphan-{self.tag}" in line}
            if not left or time.monotonic() >= deadline:
                break
            time.sleep(0.1)
        self.assertEqual({}, left, "Sandbox processes outlived the launcher")

    # The tests listed in the build plan.

    def test_canary_detects_missing_bind_and_stops(self):
        # One bound path that exists on the host and one that does not.
        for target in ("/var/lib/repro-cutover-fence", "/etc/repro-telnyx-capture"):
            with self.subTest(target=target):
                root = SANDBOX_MOUNT / f"root-{uuid.uuid4().hex[:8]}"
                root.mkdir(mode=0o700)
                marker = root / "command-ran"
                result = self.launch("/bin/sh", "-c", f"echo ran > {marker}",
                                     launcher=self.mutant(f"bind_directory {target}"), root=root)
                self.assertEqual(REFUSED, result.returncode, result.stderr)
                self.assertIn("canary", result.stderr)
                self.assertIn(target, result.stderr)
                self.assertFalse(marker.exists(), "The command ran although a bind was missing")
                self.assertEqual(self.before, host_snapshot())

    def test_shims_refuse_systemctl_and_systemd_run(self):
        observed = self.inside(
            "import json, subprocess\n"
            "calls = [['/usr/bin/systemctl', 'stop', 'php8.3-fpm.service'], ['systemctl', 'restart', 'nginx'],\n"
            "         ['/bin/systemctl', 'daemon-reload'],\n"
            "         ['/usr/bin/systemd-run', '--unit=repro-cutover-op-x', '--collect', '/bin/true'],\n"
            "         ['systemd-run', '--on-active=5', '/bin/true'],\n"
            "         ['/usr/bin/supervisorctl', 'stop', 'all'], ['/usr/sbin/service', 'nginx', 'reload']]\n"
            "result = {}\n"
            "for argv in calls:\n"
            "    done = subprocess.run(argv, capture_output=True, text=True)\n"
            "    result[' '.join(argv)] = [done.returncode, done.stderr]\n"
            "print(json.dumps(result))\n")
        self.assertEqual(7, len(observed))
        for call, (code, error) in observed.items():
            self.assertEqual(SHIM_REFUSED, code, call)
            self.assertIn("refused", error, call)

    def test_pid_one_inside_is_not_host_init(self):
        observed = self.inside(
            "import json, os, sys\n"
            "tag = sys.argv[1][::-1]\n"
            "pids = [name for name in os.listdir('/proc') if name.isdigit()]\n"
            "def cmdline(pid):\n"
            "    try:\n"
            "        return open(f'/proc/{pid}/cmdline', 'rb').read().decode('utf-8', 'replace')\n"
            "    except OSError:\n"
            "        return ''\n"
            "print(json.dumps({'pid': os.getpid(), 'init': cmdline(1), 'pid_ns': os.readlink('/proc/self/ns/pid'),\n"
            "                  'tagged': [pid for pid in pids if tag in cmdline(pid)], 'processes': len(pids)}))\n",
            self.tag[::-1])
        host_init = Path("/proc/1/cmdline").read_bytes().decode("utf-8", "replace")
        self.assertNotEqual(host_init, observed["init"])
        arguments = observed["init"].split("\0")
        self.assertEqual(PYTHON, arguments[0])
        self.assertTrue(arguments[3].endswith("/sandbox_check.py") and arguments[4] == "init", observed["init"])
        self.assertNotEqual(os.readlink("/proc/self/ns/pid"), observed["pid_ns"])
        self.assertNotEqual(1, observed["pid"])
        self.assertEqual([], observed["tagged"], "A host process is visible inside")
        self.assertLess(observed["processes"], 5)
        self.assertTrue(any(self.tag in line for line in cmdlines().values()), "The fake host worker must run")

    def test_boot_id_bind_changes_guard_boot_id(self):
        installed = self.root / "binds" / "opt" / "repro-cutover-fence"
        installed.mkdir(parents=True, mode=0o755)
        shutil.copyfile(CUTOVER / "fence" / "guard.py", installed / "guard.py")
        observed = self.inside(
            "import importlib.util, json, sys\n"
            "spec = importlib.util.spec_from_file_location('guard', '/opt/repro-cutover-fence/guard.py')\n"
            "guard = sys.modules['guard'] = importlib.util.module_from_spec(spec)\n"
            "spec.loader.exec_module(guard)\n"
            "print(json.dumps({'path': str(guard.BOOT_ID), 'boot_id': guard.BOOT_ID.read_text().strip(),\n"
            "                  'guard': open(guard.__file__, 'rb').read().hex()}))\n")
        sandbox_boot = (self.root / "boot" / "boot_id").read_text(encoding="ascii").strip()
        self.assertEqual(str(BOOT_ID), observed["path"])
        self.assertEqual(sandbox_boot, observed["boot_id"])
        self.assertNotEqual(BOOT_ID.read_text(encoding="ascii").strip(), observed["boot_id"])
        self.assertEqual(36, len(observed["boot_id"]))
        self.assertEqual((installed / "guard.py").read_bytes().hex(), observed["guard"])

    def test_network_has_only_loopback(self):
        self.assertIn(DUMMY_INTERFACE, [name for _, name in socket.if_nameindex()])
        with socket.create_connection(("127.0.0.1", self.host_port), timeout=5) as connection:
            self.assertEqual(b"host\n", connection.recv(16))
        observed = self.inside(
            "import errno, json, socket, sys\n"
            "port = int(sys.argv[1])\n"
            "def attempt(address):\n"
            "    try:\n"
            "        socket.create_connection(address, timeout=3).close()\n"
            "        return 'connected'\n"
            "    except OSError as error:\n"
            "        return errno.errorcode.get(error.errno, str(error))\n"
            "own = socket.socket()\n"
            "own.bind(('127.0.0.1', 0))\n"
            "own.listen(1)\n"
            "print(json.dumps({'interfaces': [name for _, name in socket.if_nameindex()],\n"
            "                  'host_listener': attempt(('127.0.0.1', port)),\n"
            "                  'outside': attempt(('192.0.2.1', 443)),\n"
            "                  'own_loopback': attempt(own.getsockname())}))\n",
            str(self.host_port))
        self.assertEqual(["lo"], observed["interfaces"])
        self.assertEqual("ECONNREFUSED", observed["host_listener"])
        self.assertEqual("ENETUNREACH", observed["outside"])
        self.assertEqual("connected", observed["own_loopback"])

    # Further launcher guarantees.

    def test_missing_copy_on_write_view_never_creates_a_host_mountpoint(self):
        marker = self.root / "command-ran"
        result = self.launch("/bin/sh", "-c", f"echo ran > {marker}", launcher=self.mutant("copy_on_write /etc"))
        self.assertEqual(REFUSED, result.returncode, result.stderr)
        self.assertIn("/etc/repro-telnyx-capture", result.stderr)
        self.assertFalse(marker.exists())
        self.assertFalse(os.path.lexists("/etc/repro-telnyx-capture"))

    def test_writes_land_in_the_sandbox_and_never_on_the_host(self):
        observed = self.inside(
            "import fcntl, json, os, sys\n"
            "bound = json.loads(sys.argv[1])\n"
            "for path in bound:\n"
            "    open(os.path.join(path, 'written-inside'), 'w').write(path)\n"
            "for path in ('/etc/repro-sandbox-cow', '/var/lib/repro-sandbox-cow', '/opt/repro-sandbox-cow',\n"
            "             '/home/repro-sandbox-cow', '/run/repro-sandbox-private', '/tmp/repro-sandbox-private'):\n"
            "    open(path, 'w').write('inside')\n"
            "open('/var/www/backend/.env', 'a').write('CHANGED_INSIDE=1\\n')\n"
            "lock = os.open('/home/maverick/.repro-backend-deploy.lock', os.O_RDWR)\n"
            "fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)\n"
            "os.write(lock, b'held inside')\n"
            "print(json.dumps({'env': open('/var/www/backend/.env').read()}))\n",
            json.dumps(BOUND))
        self.assertIn("CHANGED_INSIDE=1", observed["env"])
        for path in BOUND:
            self.assertEqual(path, (self.root / "binds" / path.lstrip("/") / "written-inside").read_text())
        self.assertIn(b"CHANGED_INSIDE=1", (self.root / "cow" / "upper" / "var" / "www" / "backend" / ".env").read_bytes())
        self.assertEqual(b"held inside",
                         (self.root / "cow" / "upper" / "home" / "maverick" / ".repro-backend-deploy.lock").read_bytes())
        for path in ("/run/repro-sandbox-private", "/tmp/repro-sandbox-private", "/etc/repro-sandbox-cow"):
            self.assertFalse(os.path.lexists(path), path)

    def test_reboot_gives_new_boot_id_and_empty_runtime_directories(self):
        code = ("import json, os\n"
                "seen = {name: os.path.exists(path) for name, path in (('run', '/run/repro-cutover-fence/mark'),"
                " ('var', '/var/lib/repro-cutover-fence/mark'))}\n"
                "for path in ('/run/repro-cutover-fence/mark', '/var/lib/repro-cutover-fence/mark'):\n"
                "    open(path, 'w').write('x')\n"
                "print(json.dumps({'boot_id': open('/proc/sys/kernel/random/boot_id').read().strip(), **seen}))\n")
        first = self.inside(code)
        again = self.inside(code)
        rebooted = self.inside(code, reboot=True)
        self.assertEqual({"run": False, "var": False}, {key: first[key] for key in ("run", "var")})
        self.assertEqual(first["boot_id"], again["boot_id"])
        self.assertEqual({"run": True, "var": True}, {key: again[key] for key in ("run", "var")})
        self.assertNotEqual(first["boot_id"], rebooted["boot_id"])
        self.assertEqual({"run": False, "var": True}, {key: rebooted[key] for key in ("run", "var")})

    def test_sandbox_processes_end_with_the_run(self):
        started = time.monotonic()
        result = self.launch(PYTHON, "-I", "-S", "-c",
                             "import subprocess, sys\n"
                             "subprocess.Popen([sys.argv[1], '3600'], executable='/bin/sleep', start_new_session=True,"
                             " stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)\n",
                             f"sandbox-orphan-{self.tag}")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertLess(time.monotonic() - started, 30)
        self.assertFalse([line for line in cmdlines().values() if f"sandbox-orphan-{self.tag}" in line])

    def test_launch_lock_lasts_as_long_as_the_sandbox(self):
        argv = ["/bin/sh", str(LAUNCHER), str(self.root), "--", "/bin/sleep", "3"]
        first = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        deadline = time.monotonic() + 30
        while not (self.root / "sync" / "go").exists():
            self.assertLess(time.monotonic(), deadline, "The first sandbox never started")
            time.sleep(0.05)
        # Only the launcher shell dies; the sandbox it started keeps running.
        first.kill()
        first.wait()
        second = self.launch("/bin/true")
        self.assertEqual(2, second.returncode, second.stderr)
        self.assertIn("another launch is using", second.stderr)
        deadline = time.monotonic() + 30
        while any(str(self.root) in line for line in cmdlines().values()):
            self.assertLess(time.monotonic(), deadline, "The first sandbox never ended")
            time.sleep(0.1)
        self.assertEqual(0, self.launch("/bin/true").returncode)

    def test_command_gets_only_standard_descriptors(self):
        observed = self.inside("import json, os\nprint(json.dumps(sorted(int(fd) for fd in os.listdir('/proc/self/fd'))))\n")
        # 0, 1, 2 and the descriptor listdir itself holds open.
        self.assertEqual([0, 1, 2], observed[:3])
        self.assertEqual(4, len(observed), observed)

    def test_command_exit_status_is_returned(self):
        self.assertEqual(7, self.launch("/bin/sh", "-c", "exit 7").returncode)
        missing = self.launch("/nonexistent/command")
        self.assertEqual(127, missing.returncode, missing.stderr)

    def test_refuses_a_root_inside_a_copied_private_or_system_tree(self):
        for parent in ("/var/lib", "/etc", "/opt", "/home", "/run", "/tmp", "/usr/local"):
            with self.subTest(parent=parent):
                root = Path(tempfile.mkdtemp(prefix="repro-sandbox-bad-", dir=parent))
                try:
                    before = host_snapshot()
                    result = self.launch("/bin/true", root=root)
                    self.assertEqual(2, result.returncode, result.stderr)
                    self.assertIn("sandbox root", result.stderr)
                    self.assertEqual([], os.listdir(root))
                    self.assertEqual(before, host_snapshot())
                finally:
                    shutil.rmtree(root)
        # Creating and removing the bad roots changed their parents' modification times.
        self.before = host_snapshot()
        writable = SANDBOX_MOUNT / f"open-{uuid.uuid4().hex[:8]}"
        writable.mkdir(mode=0o700)
        os.chmod(writable, 0o770)
        result = self.launch("/bin/true", root=writable)
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertEqual([], os.listdir(writable))

    def test_root_traversable_by_sandbox_services_is_accepted(self):
        # Sandbox FPM and queue workers run as www-data and must reach the app copy under ROOT.
        os.chmod(self.root, 0o711)
        app = self.root / "app"
        app.mkdir(mode=0o755)
        (app / "artisan").write_text("app copy\n", encoding="ascii")
        result = self.launch("/usr/sbin/runuser", "-u", "www-data", "--", "/bin/cat", str(app / "artisan"))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("app copy\n", result.stdout)

    def test_outside_check_stops_when_an_inside_canary_reaches_a_host_path(self):
        # Drive the launcher's outside half by hand: the inside half reports ready and every sandbox
        # directory holds its canary, but a copy of one also reached the host path. Only the
        # host-side check can see that.
        check = [PYTHON, "-I", "-S", str(HERE / "sandbox_check.py")]
        prepared = subprocess.run(check + ["prepare", "--root", str(self.root)], capture_output=True, text=True)
        self.assertEqual(0, prepared.returncode, prepared.stderr)
        armed = subprocess.run(check + ["arm", "--root", str(self.root)], capture_output=True, text=True)
        self.assertEqual(0, armed.returncode, armed.stderr)
        token = armed.stdout.strip()
        leaked = Path("/etc/repro-cutover-fence/.repro-sandbox-canary-in")
        for target in BOUND:
            (self.root / "binds" / target.lstrip("/") / ".repro-sandbox-canary-in").write_text(f"in:{token}\n")
        leaked.write_text(f"in:{token}\n", encoding="ascii")
        (self.root / "sync" / "ready").write_text(json.dumps({"version": 1, "token": token}), encoding="ascii")
        result = subprocess.run(check + ["outside", "--root", str(self.root), "--token", token],
                                capture_output=True, text=True, timeout=60)
        self.assertEqual(REFUSED, result.returncode, result.stderr)
        self.assertIn(f"canary reached the host path {leaked}", result.stderr)
        self.assertTrue((self.root / "sync" / "stop").exists())
        self.assertFalse((self.root / "sync" / "go").exists())
        self.assertFalse(leaked.exists(), "The leaked canary must be removed from the host path")
        # Planting and removing the canary changed the directory's modification time.
        self.before = host_snapshot()


@unittest.skipUnless(sys.platform == "linux", "Linux paths")
class MakeConfigTests(unittest.TestCase):
    """make_config.py writes the sandbox host.json, identity.json, policy and wrapper files."""

    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix="repro-sandbox-config-")
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name) / "root"
        self.root.mkdir(mode=0o700)
        self.app = self.root / "app" / "backend"
        (self.app / "database").mkdir(parents=True)
        self.datadir = self.root / "mysql" / "data"
        self.datadir.mkdir(parents=True)
        self.server_uuid = "5f0e9a8c-2b51-11f1-9d3a-0242ac110002"
        (self.datadir / "auto.cnf").write_text(f"[auto]\nserver-uuid={self.server_uuid}\n", encoding="ascii")
        self.out = self.root / "binds" / "etc" / "repro-cutover-fence"

    def make(self, *extra: str, controller: Path = CUTOVER, check: bool = True) -> subprocess.CompletedProcess:
        argv = [sys.executable, "-I", "-S", str(HERE / "make_config.py"), "--root", str(self.root),
                "--controller", str(controller), "--app-root", str(self.app), "--run-id", "sandbox-drill-1",
                "--mysql-datadir", str(self.datadir), "--deploy-owner", "www-data",
                "--smoke-client-id", "41", "--smoke-shoot-id", "42", *extra]
        result = subprocess.run(argv, capture_output=True, text=True, timeout=60)
        if check:
            self.assertEqual(0, result.returncode, result.stderr)
        return result

    def controller_copy(self, edit) -> Path:
        copy = Path(tempfile.mkdtemp(prefix="repro-sandbox-controller-"))
        self.addCleanup(shutil.rmtree, copy)
        for name in ("runner.py", "fence", "production"):
            source = CUTOVER / name
            if source.is_dir():
                shutil.copytree(source, copy / name, ignore=shutil.ignore_patterns("__pycache__", "sandbox"))
            else:
                shutil.copyfile(source, copy / name)
        adapter = copy / "production" / "adapter.py"
        adapter.write_text(edit(adapter.read_text(encoding="utf-8")), encoding="utf-8")
        return copy

    def test_host_json_passes_load_config_and_cli_fixed_paths(self):
        self.make()
        raw = (self.out / "host.json").read_bytes()
        sys.path[:0] = [str(CUTOVER / "production"), str(CUTOVER)]
        try:
            import adapter
            import cli
            from fence import guard
        finally:
            del sys.path[:2]
        config = adapter.load_config(raw)
        installed = (guard.STATE, guard.POLICY, guard.EVIDENCE, guard.ACTIVATION, guard.MACHINE_ID, guard.BOOT_ID)
        for name, path in zip(cli.GUARD_PATHS, installed):
            self.assertEqual(str(path), config["paths"][name])
        self.assertEqual(str(cli.INGRESS_OPEN), config["paths"]["ingress_open"])
        self.assertEqual(str(cli.DEPLOY_MARKER), config["paths"]["deploy_marker"])
        self.assertEqual(str(self.app / "database" / "database.sqlite"), config["sqlite"])
        for lock in config["deploy_locks"]:
            self.assertTrue(lock.startswith(str(self.root) + "/"), lock)
        self.assertEqual(self.server_uuid, config["mysql"]["target_uuid"])
        self.assertEqual("drill", config["mysql"]["mode"])
        for name in ("queue_pause", "queue_resume"):
            self.assertEqual(["/usr/sbin/runuser", "-u", "www-data", "--"], config["commands"][name][:4])
            self.assertIn(f"cd {self.app} && umask 0022 && exec", config["commands"][name][6])
        mode = stat.S_IMODE(os.stat(self.out / "host.json").st_mode)
        self.assertEqual(0o644, mode)

    def test_identity_denylist_is_night_flags_plus_switch_keys(self):
        self.make()
        config = json.loads((self.out / "host.json").read_text(encoding="utf-8"))
        identity = json.loads((self.out / "identity.json").read_text(encoding="utf-8"))
        mysql = config["mysql"]
        expected = set(mysql["night_flags"]) | set(mysql["switch"]) | set(mysql["switch_secrets"])
        self.assertEqual(sorted(expected), identity["env_denylist"])
        self.assertIn("DB_PASSWORD", identity["env_denylist"])
        self.assertEqual({"run_id": "sandbox-drill-1", "app_root": str(self.app),
                          "sqlite_path": str(self.app / "database" / "database.sqlite"),
                          "mysql_datadir": str(self.datadir)},
                         {key: identity[key] for key in ("run_id", "app_root", "sqlite_path", "mysql_datadir")})

    def test_contract_name_without_a_sandbox_value_is_refused(self):
        known = self.controller_copy(lambda text: text.replace('"drain_fpm"}', '"drain_fpm", "fpm_start"}', 1))
        self.make(controller=known)
        config =json.loads((self.out / "host.json").read_text(encoding="utf-8"))
        self.assertIn("fpm_start", config["commands"])
        unknown = self.controller_copy(lambda text: text.replace('"drain_fpm"}', '"drain_fpm", "unheard_of"}', 1))
        result = self.make(controller=unknown, check=False)
        self.assertEqual(2, result.returncode)
        self.assertIn("unheard_of", result.stderr)

    def test_controller_reading_identity_json_gets_the_sandbox_copy(self):
        # The D01 contract: load_config(raw, *, read) reads paths.identity_config through `read`.
        def d01_shape(text):
            text = text.replace('"sqlite_seal_record"}', '"sqlite_seal_record", "identity_config"}', 1)
            return text.replace(
                "def load_config(raw: bytes) -> dict:\n    config = guard.read_json(raw)\n",
                "def load_config(raw: bytes, *, read) -> dict:\n    config = guard.read_json(raw)\n"
                "    identity = json.loads(read(Path(config['paths']['identity_config'])))\n"
                "    guard.require(identity['app_root'] == config['app_root'], 'identity.json differs')\n", 1)
        controller = self.controller_copy(d01_shape)
        self.assertIn("*, read", (controller / "production" / "adapter.py").read_text(encoding="utf-8"))
        self.make(controller=controller)
        config = json.loads((self.out / "host.json").read_text(encoding="utf-8"))
        self.assertEqual("/etc/repro-cutover-fence/identity.json", config["paths"]["identity_config"])
        self.assertEqual(["--config", "/etc/repro-cutover-fence/identity.json"], config["commands"]["identity"][-2:])

    def test_state_outside_the_sandbox_root_is_refused(self):
        for option, value in (("--app-root", "/var/www/backend"), ("--mysql-datadir", "/var/lib/mysql")):
            with self.subTest(option=option):
                argv = [sys.executable, "-I", "-S", str(HERE / "make_config.py"), "--root", str(self.root),
                        "--controller", str(CUTOVER), "--app-root", str(self.app), "--run-id", "sandbox-drill-1",
                        "--mysql-datadir", str(self.datadir), "--deploy-owner", "www-data",
                        "--smoke-client-id", "41", "--smoke-shoot-id", "42", option, value]
                result = subprocess.run(argv, capture_output=True, text=True, timeout=60)
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertIn("inside the sandbox root", result.stderr)
                self.assertFalse((self.out / "host.json").exists())

    def test_commands_never_call_host_service_managers(self):
        self.make()
        config = json.loads((self.out / "host.json").read_text(encoding="utf-8"))
        commands = []
        for value in config["commands"].values():
            commands.extend(value if value and isinstance(value[0], list) else [value])
        for argv in commands:
            self.assertTrue(argv[0].startswith("/"), argv)
            for part in argv:
                for manager in ("systemctl", "systemd-run", "supervisorctl", "/usr/sbin/service"):
                    self.assertNotIn(manager, part, argv)

    def test_wrappers_per_engine_and_production_paths_refused(self):
        template = self.root / "wrappers.template.json"
        profile = {"action": "queue-worker", "engine": "sqlite", "user": "www-data", "group": "www-data",
                   "cwd": str(self.app), "umask": "0022", "env": {}, "timeout_seconds": 300,
                   "argv": ["/usr/bin/php", f"{self.app}/artisan", "cutover:smoke", "--write", "--json"]}
        template.write_text(json.dumps({"version": 1, "profiles": {"cutover-smoke": profile}}), encoding="utf-8")
        self.make("--wrappers", str(template))
        sqlite = json.loads((self.out / "wrappers.sqlite.json").read_text(encoding="utf-8"))
        mysql = json.loads((self.out / "wrappers.mysql.json").read_text(encoding="utf-8"))
        self.assertEqual("sqlite", sqlite["profiles"]["cutover-smoke"]["engine"])
        self.assertEqual("mysql", mysql["profiles"]["cutover-smoke"]["engine"])
        self.assertEqual((self.out / "wrappers.sqlite.json").read_bytes(), (self.out / "wrappers.json").read_bytes())
        for field, value in (("cwd", "/var/www/backend"),
                             ("argv", ["/bin/sh", "-c", "exec /home/maverick/.local/share/repro-studio/worker.sh"])):
            with self.subTest(field=field):
                template.write_text(json.dumps({"version": 1, "profiles": {"studio": {**profile, field: value}}}),
                                    encoding="utf-8")
                result = self.make("--wrappers", str(template), check=False)
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertIn("production", result.stderr)

    def test_policy_from_measured_values(self):
        measured = self.root / "measured.json"
        identity = {"run_id": "sandbox-drill-1", "release_sha": "a" * 40, "artifact_sha256": "b" * 64,
                    "source_sha256": "c" * 64, "config_sha256": "d" * 64, "target_uuid": self.server_uuid}
        measured.write_text(json.dumps({"identity": identity, "sqlite_live_config_sha256": "1" * 64,
                                        "mysql_live_config_sha256": "2" * 64}), encoding="utf-8")
        self.make("--measured", str(measured))
        policy = json.loads((self.out / "policy.json").read_text(encoding="utf-8"))
        self.assertEqual(identity, policy["identity"])
        self.assertEqual(hashlib.sha256(Path("/etc/machine-id").read_bytes()).hexdigest(),
                         policy["machine_id_sha256"])
        identity["target_uuid"] = "00000000-0000-0000-0000-000000000000"
        measured.write_text(json.dumps({"identity": identity, "sqlite_live_config_sha256": "1" * 64,
                                        "mysql_live_config_sha256": "2" * 64}), encoding="utf-8")
        result = self.make("--measured", str(measured), check=False)
        self.assertEqual(2, result.returncode)
        self.assertIn("target_uuid", result.stderr)


if __name__ == "__main__":
    unittest.main()
