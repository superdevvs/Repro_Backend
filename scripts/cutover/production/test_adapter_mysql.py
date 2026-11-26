"""`prepare`, steps 1 to 10, the switch half (design v2, 3.2; build plan D10), and the MySQL host harness that D11
(steps 11 and 12, `evidence()`) and D12 (`seal_sqlite`'s full undo) add their tests to.

Everything here runs as Linux root in a disposable container; ../run-linux-tests.sh runs this file after its
production loop.

- PrepareTests use a scripted stand-in for mysql_probe.py, so they run wherever the root suites run.
- PrepareFixtureTests run the real mysql_probe.py (as a host command) and gate.py (in the controller) against
  D04's socket-only MySQL 8.4.13 fixture, which `mysql/fixture.sh serve` builds from accounts.sql in its own
  container. The fixture's volume is mounted here and named by REPRO_MYSQL_PROBE_FIXTURE: the root container
  and the MySQL container share the socket volume and nothing else (`run-probe-fixture-tests.sh --linux-suite`
  does all of it, and removes everything again). Without the fixture they skip; `--fixture-check`, which
  run-linux-tests.sh runs before any suite, names them in a WARNING line, or stops the run when
  REPRO_REQUIRE_PROBE_FIXTURE=1.

The harness: `PrepareHost(test, fixture=None, mode="drill", waiver=False)`
----------------------------------------------------------------------------
One complete host under a fresh root-owned folder in /var/lib (the guard's protected-file checks refuse /tmp's
world-writable chain), removed when the test ends:

| Part | Where | Stands in for |
| --- | --- | --- |
| journal and records | `state/` (root 0700) | /var/lib/repro-cutover-fence: state.json, sealed hash, switch record, receipt, latch, evidence inputs |
| runtime | `run/`, `ingress/open` | /run/repro-cutover-fence, the nginx open marker |
| host.json, identity.json, policy.json | `etc/` (root 0644) | /etc/repro-cutover-fence; the policy pins both live config hashes (`sqlite_pin`, `mysql_pin`) |
| mysql-app.env, mysql-probe.cnf, mysql-gate.cnf, mysql-token-check.cnf | `etc/` (root 0600) | the root-only secret and defaults files (fixture: copies of the fixture's `<account>.cnf`) |
| app root | `app/` (`nobody`:`www-data`, the deploy user's stand-in) | /var/www/backend: `.env` (0640, with a POSIX ACL for uid 1000 as production's backup account, and a user xattr), `bootstrap/cache/config.php` (the SQLite pin), `artisan`, `fake-php.json` |
| SQLite | `database/database.sqlite` (www-data, WAL) | the live database; init, hold and freeze run on it for real |
| night inputs | `night/` (root 0700) and `paths.evidence_inputs` | the night's tool outputs and the manifest check-evidence writes (`write_inputs`) |
| recorders | `bin/systemctl`, `bin/runuser` | `systemctl` (records `["systemctl", ...]`, exit from `recorders.json`) and runuser (records `["runuser", ...]`, then runs the real /usr/sbin/runuser) |
| php | `bin/php` | /usr/bin/php: `queue:pause|resume` (records), `config:cache` (writes $APP_CONFIG_CACHE from `.env` through ROUTES, see `config_for`), any other artisan command records itself and exits 0 (D11's smokes: set answers in `fake-php.json` via `php(...)`) |
| identity probe | `bin/identity-probe` | identity_probe.py: the pinned release, artifact, source and target, and `config_sha256` measured from the live `.env` with identity.json's denylist, so the runner refuses a switch that moved it |
| MySQL probe | `bin/mysql-probe` | fixture: records, then runs the real mysql_probe.py with `etc/probe-host.json` (design-form commands); otherwise answers from `etc/mysql-answers.json` (`answer(...)`, `since(...)`) |

Every recorder appends one JSON list per call to `log/commands.jsonl` (`commands()`); the MySQL probe records
`["mysql-probe", *arguments]`. In-process, the adapter's QUEUE_PHP and config_build's RUNUSER and PHP are patched
to `bin/php` and `bin/runuser`, so load_config's command checks hold for the harness's own paths.

Use: `freeze()` runs init, hold and freeze through the real runner and writes the night inputs; `prepare()`
runs `runner.perform("prepare")` and returns the refusal; `assert_prepared(error)` checks that steps 1 to 10
passed and the runner's observation after them was frozen on MySQL (only `evidence()`, D11's, refused);
`adapter_prepare()` calls `adapter.prepare` again under a drained lease (a retry); `changes()` is everything
prepare may change, for "refused without change" checks; `assert_markers(after=n)` checks the durable state a
crash after step n leaves (the markers D12's abort reads, README "prepare"). D11 adds steps 11 and 12 by
extending `prepare()`'s expectation and the fake php's answers; D12 drives `abort` from each crash point that
`test_prepare_is_safe_to_run_twice_and_resumes_after_a_crash_at_any_step` builds.

    python3 -B -m unittest discover -s scripts/cutover/production -p test_adapter_mysql.py -v
"""

from __future__ import annotations

import copy
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import unittest
import uuid
from contextlib import contextmanager
from dataclasses import asdict
from pathlib import Path
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE.parent), str(HERE)]
import adapter as production  # noqa: E402
import config_build  # noqa: E402
import envfile  # noqa: E402
import identity_probe  # noqa: E402
import live_config  # noqa: E402
import sqlite_seal  # noqa: E402
from fence import guard  # noqa: E402
from runner import Identity, Journal, UnsafeCutover  # noqa: E402
from test_adapter import (MYSQL_CLIENT, MYSQL_CONFIG, MYSQL_PROBE, NIGHT_FLAGS, OTHER_UUID,  # noqa: E402
                          PROBE_FIXTURE, REQUIRE_PROBE_FIXTURE, SQLITE_CONFIG, TARGET_UUID, drain_report,
                          example_config, probe_reading, queue_command)
from test_envfile import BACKUP_ACL  # noqa: E402
from test_live_config import cache_text  # noqa: E402

LINUX_ROOT = sys.platform == "linux" and hasattr(os, "geteuid") and os.geteuid() == 0
RUN_ID = "prepare-fixture"
# The deploy user's stand-in (it owns the app root and runs config:cache) and the web user.
DEPLOY_USER, WEB_USER, WEB_UID = "nobody", "www-data", 33
FAKE_PASSWORD = "fixture-app-password-0123456789"
UNIT = "php8.3-fpm.service"
# The night's tool outputs (design 3.2 step 2), by the manifest's input names.
INPUTS = ("snapshot", "import", "verify_fields", "migrate_status", "complete_schema", "backup_check")
FROZEN_SNAPSHOT, COPY_SNAPSHOT = "1" * 64, "2" * 64
RECEIPT_KEYS = {"version", "run_id", "server_uuid", "database", "backup", "armed_from"}
SWITCH_RECORD_KEYS = {"version", "run_id", "values", "env_sha256", "switch_at"}
# A production-shaped SQLite .env (production has these DB_ keys and no other switch key, review A5).
ENV = """APP_NAME=RePro
APP_ENV=production
APP_KEY=base64:cHJlcGFyZS1maXh0dXJlLWtleS1ub3QtcmVhbC0zMg==
APP_DEBUG=false
# Database
DB_CONNECTION=sqlite
DB_DATABASE={database}
DB_BUSY_TIMEOUT=5000
DB_JOURNAL_MODE=wal
DB_SYNCHRONOUS=normal
QUEUE_CONNECTION=database
CRM_OUTBOUND_HOLD=true
SCHEDULER_HOLD_ENABLED=true
MAIL_FROM_NAME="Re Pro"
"""

# Where config/database.php puts each switch key in the cached config. The fake php's config:cache fills these
# from .env (a key .env lacks becomes null), and the harness computes the pins the same way, so the MySQL pin is
# met only when every switch value, the password included, reached .env.
MYSQL_CONNECTIONS = ("mysql", "mysql_queue_worker", "mysql_scheduling_cache", "mysql_cache_locks")
ROUTES = {
    "mysql": {
        "DB_DATABASE": [("database", "connections", name, "database") for name in MYSQL_CONNECTIONS],
        "DB_SOCKET": [("database", "connections", name, "unix_socket") for name in MYSQL_CONNECTIONS],
        "DB_USERNAME": [("database", "connections", name, "username") for name in ("mysql", "mysql_cache_locks")],
        "DB_PASSWORD": [("database", "connections", name, "password") for name in ("mysql", "mysql_cache_locks")],
        "DB_COLLATION": [("database", "connections", "mysql", "collation")],
        "REPRO_MYSQL_EXPECTED_SERVER_UUID": [("database", "connections", "mysql", "repro_expected_server_uuid")],
    },
    "sqlite": {"DB_DATABASE": [("database", "connections", "sqlite", "database")]},
}
TEMPLATES = {"mysql": MYSQL_CONFIG["config"], "sqlite": SQLITE_CONFIG["config"]}


def env_settings(data: bytes) -> dict[str, str]:
    """The last value of each plain NAME=VALUE line, as the fake php reads .env (the harness's values are plain)."""
    found = {}
    for line in data.decode("utf-8").splitlines():
        name, separator, value = line.partition("=")
        if separator and re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", name):
            found[name] = value
    return found


def config_for(env: dict) -> dict:
    """The cached config `php artisan config:cache` writes for these .env values (the fake php's build)."""
    engine = env.get("DB_CONNECTION")
    config = copy.deepcopy(TEMPLATES[engine])
    for key, places in ROUTES[engine].items():
        for *parents, last in places:
            node = config
            for part in parents:
                node = node[part]
            node[last] = env.get(key)
    return config


def route_hash(env: dict) -> str:
    return live_config.from_cache(cache_text(config_for(env))).live_config_sha256


def utc(seconds: int) -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(seconds))


def metadata(path: Path) -> tuple:
    info = path.lstat()
    return (info.st_ino, info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode),
            {name: os.getxattr(path, name, follow_symlinks=False) for name in os.listxattr(path, follow_symlinks=False)})


# The host programs. Each records one JSON list per call in the shared log ({log}).
RECORD = """#!{python} -IBS
import json, os, sys
with open({log!r}, "a") as stream:
    stream.write(json.dumps([{name!r}, *sys.argv[1:]]) + "\\n")
"""
SYSTEMCTL = RECORD + """try:
    with open({settings!r}) as stream:
        settings = json.load(stream)
except FileNotFoundError:
    settings = {{}}
sys.exit(settings.get("systemctl", {{}}).get(sys.argv[1] if len(sys.argv) > 1 else "", 0))
"""
RUNUSER = RECORD + """os.execv("/usr/sbin/runuser", ["/usr/sbin/runuser", *sys.argv[1:]])
"""
FAKE_PROBE = RECORD + """with open({answers!r}) as stream:
    answers = json.load(stream)
if "--since" in sys.argv[1:]:
    try:
        with open({count!r}) as stream:
            count = int(stream.read())
    except FileNotFoundError:
        count = 0
    with open({count!r}, "w") as stream:
        stream.write(str(count + 1))
    answer = answers["since"][min(count, len(answers["since"]) - 1)]
else:
    answer = answers["observe"]
print(json.dumps(answer["stdout"]))
sys.exit(answer["code"])
"""
REAL_PROBE = RECORD + """os.execv("/usr/bin/python3", ["/usr/bin/python3", "-I", "-S", "-B", {probe!r}, "--config", {config!r},
                              *sys.argv[1:]])
"""
IDENTITY_PROBE = """#!{python} -IBS
import json, sys
sys.path[:0] = [{here!r}, {parent!r}]
import identity_probe
with open(sys.argv[sys.argv.index("--config") + 1]) as stream:
    config = json.load(stream)
with open({fields!r}) as stream:
    fields = json.load(stream)
with open(config["app_root"] + "/.env", "rb") as stream:
    env = stream.read()
print(json.dumps({{**fields, "config_sha256": identity_probe.config_sha256(env, config["env_denylist"])}}))
"""
# /usr/bin/php's stand-in. It runs after the command's `cd <app_root>` as the command's user and reads
# fake-php.json there.
FAKE_PHP = """#!{python} -IBS
import json, os, sys
sys.path[:0] = [{here!r}, {parent!r}]
artisan, command, *arguments = sys.argv[1:]
open(artisan).close()
with open("fake-php.json") as stream:
    settings = json.load(stream)
def record(entry):
    with open(settings["log"], "a") as stream:
        stream.write(json.dumps(entry) + "\\n")
if command == "config:cache":
    from test_adapter_mysql import config_for, env_settings
    from test_live_config import cache_text
    target = os.environ["APP_CONFIG_CACHE"]
    umask = os.umask(0o022)
    os.umask(umask)
    record(["php", "config:cache", target, os.getuid(), os.getgid(), umask])
    with open(".env", "rb") as stream:
        env = env_settings(stream.read())
    mode = settings.get("config_cache", "ok")
    if mode == "other-route":
        env["DB_COLLATION"] = "utf8mb4_general_ci"
    content = cache_text(config_for(env))
    with open(target, "wb") as stream:
        stream.write(content[:len(content) // 2] if mode == "half-then-fail" else content)
    sys.exit(1 if mode == "half-then-fail" else 0)
record(["php", command, *arguments])
answer = settings.get("answers", {{}}).get(command)
if answer is not None:
    print(answer["stdout"])
    sys.exit(answer["code"])
"""


class Crash(Exception):
    """A controller that stopped between two prepare steps (killed, or the host rebooted)."""


class PrepareHost:
    """One root-owned host for prepare and the actions around it (module docstring)."""

    def __init__(self, test: unittest.TestCase, *, fixture=None, mode: str = "drill", waiver: bool = False):
        self.test, self.fixture, self.mode, self.waiver = test, fixture, mode, waiver
        self.root = Path(tempfile.mkdtemp(prefix="repro-adapter-mysql-", dir="/var/lib"))
        test.addCleanup(shutil.rmtree, self.root, True)
        self.root.chmod(0o755)
        self.tag = uuid.uuid4().hex[:12]
        for folder in ("state", "run", "ingress", "etc", "home", "database", "bin", "night", "log"):
            (self.root / folder).mkdir(mode=0o755)
        for private in ("state", "run", "night"):
            (self.root / private).chmod(0o700)
        os.chown(self.root / "home", WEB_UID, WEB_UID)
        os.chown(self.root / "database", WEB_UID, WEB_UID)
        self.log = self.root / "log" / "commands.jsonl"
        self.log.touch()
        self.log.chmod(0o666)
        self.etc = self.root / "etc"
        self.target = fixture.uuid if fixture else TARGET_UUID
        self.socket = fixture.socket if fixture else "/run/repro-mysql84-native/mysql.sock"
        self.password = ((fixture.directory / "passwords" / "app").read_text(encoding="ascii").strip()
                         if fixture else FAKE_PASSWORD)
        import pwd  # Linux only: the harness runs as root on Linux.

        self.deploy = pwd.getpwnam(DEPLOY_USER).pw_uid
        self._database()
        self._app()
        self._programs()
        for target, value in ((production, "QUEUE_PHP"), (config_build, "PHP")):
            patcher = patch.object(target, value, str(self.php_path))
            patcher.start()
            test.addCleanup(patcher.stop)
        patcher = patch.object(config_build, "RUNUSER", str(self.root / "bin" / "runuser"))
        patcher.start()
        test.addCleanup(patcher.stop)
        self.config = self._config()
        self._write_config()
        self.state = None
        self.backup = None

    # Building the host.

    def _database(self):
        self.database = self.root / "database" / "database.sqlite"
        subprocess.run(["/usr/bin/setpriv", f"--reuid={WEB_UID}", f"--regid={WEB_UID}", "--clear-groups",
                        sys.executable, "-B", "-c",
                        "import sqlite3, sys; c = sqlite3.connect(sys.argv[1]); c.execute('PRAGMA journal_mode=WAL');"
                        "c.execute('CREATE TABLE t (v)'); c.commit()", str(self.database)], check=True)
        (self.root / "machine-id").write_bytes(b"prepare fixture machine\n")
        self.boot = self.root / "boot-id"
        self.boot.write_text("6f3b0a4c-2a41-4f5e-9d38-0b5d4f6e7a10\n", encoding="ascii")
        (self.root / "ingress" / "open").write_text("{}", encoding="ascii")

    def _app(self):
        self.app = self.root / "app"
        self.cache = self.app / "bootstrap" / "cache" / "config.php"
        self.cache.parent.mkdir(parents=True)
        (self.app / "artisan").write_text("<?php // The fixture php reads fake-php.json instead.\n")
        self.env = self.app / ".env"
        self.env.write_text(ENV.format(database=self.database), encoding="utf-8")
        self.cache.write_bytes(cache_text(config_for(env_settings(self.env.read_bytes()))))
        for path in (self.app, self.app / "bootstrap", self.cache.parent, self.cache, self.env, self.app / "artisan"):
            os.chown(path, self.deploy, WEB_UID)
            path.chmod(0o755 if path.is_dir() else 0o644)
        self.env.chmod(0o640)
        os.setxattr(self.env, "system.posix_acl_access", BACKUP_ACL)
        os.setxattr(self.env, "user.repro_test", b"kept")
        self.php()

    def php(self, **settings):
        """What the fake php does on its next runs (config_cache: ok, half-then-fail, other-route; answers)."""
        path = self.app / "fake-php.json"
        path.write_text(json.dumps({"log": str(self.log), **settings}), encoding="utf-8")
        path.chmod(0o644)

    def _program(self, name: str, text: str, **values) -> Path:
        path = self.root / "bin" / name
        path.write_text(text.format(python=sys.executable, log=str(self.log), name=name, here=str(HERE),
                                    parent=str(HERE.parent), **values), encoding="utf-8")
        path.chmod(0o755)
        return path

    def _programs(self):
        self.php_path = self._program("php", FAKE_PHP)
        self.recorders = self.etc / "recorders.json"
        self._program("systemctl", SYSTEMCTL, settings=str(self.recorders))
        self._program("runuser", RUNUSER)
        self.identity_fields = self.etc / "identity-fields.json"
        self._program("identity-probe", IDENTITY_PROBE, fields=str(self.identity_fields))
        self.answers = self.etc / "mysql-answers.json"
        self.probe_config = self.etc / "probe-host.json"
        if self.fixture:
            self._program("mysql-probe", REAL_PROBE, probe=str(MYSQL_PROBE), config=str(self.probe_config))
        else:
            self._program("mysql-probe", FAKE_PROBE, answers=str(self.answers), count=str(self.etc / "since-count"))
            self.answer(probe_reading(server_uuid=self.target))
        for name in ("stop", "start", "fpm-stop", "smoke-write", "telnyx-replay", "timer"):
            self._program(name, RECORD)

    def answer(self, stdout, code: int = 0) -> None:
        """The scripted MySQL probe's reading (no fixture): what it prints and exits with without --since."""
        answers = json.loads(self.answers.read_text()) if self.answers.exists() else {"since": []}
        answers["observe"] = {"code": code, "stdout": stdout}
        self.answers.write_text(json.dumps(answers), encoding="utf-8")

    def since(self, *outputs) -> None:
        """The scripted probe's `--since` answers in order, each (stdout, code); the last one repeats."""
        answers = json.loads(self.answers.read_text())
        answers["since"] = [{"code": code, "stdout": stdout} for stdout, code in outputs]
        self.answers.write_text(json.dumps(answers), encoding="utf-8")
        (self.etc / "since-count").unlink(missing_ok=True)

    def since_output(self, until: dict | None = None, transactions=(), **changes) -> dict:
        """mysql_probe.py --since's exit-0 output (README, --since contract) from the backup coordinate."""
        backup = self.backup
        return {"version": 2, "reachable": True, "server_uuid": self.target, "database": "repro_dashboard",
                "gate": "closed", "mode": "measured", "since": {"file": backup["file"], "position": backup["position"]},
                "until": until or {"file": backup["file"], "position": backup["position"] + 4096,
                                   "gtid_executed": backup["gtid_executed"]},
                "boundary": None, "transactions": list(transactions), "scanned_events": 12, **changes}

    def _config(self) -> dict:
        root, etc, bin_ = self.root, self.etc, self.root / "bin"
        config = example_config()
        state = root / "state"
        config["paths"] = {
            **{name: str(state / Path(value).name) for name, value in config["paths"].items()},
            "state": str(state), "policy": str(etc / "policy.json"), "evidence": str(root / "run" / "verified-engine.json"),
            "activation": str(root / "run" / "verified-activation.json"), "machine_id": str(root / "machine-id"),
            "boot_id": str(self.boot), "ingress_open": str(root / "ingress" / "open"),
            "deploy_marker": str(etc / "active"), "scheduler_hold": str(root / "run" / "scheduler-hold.json"),
            "scheduler_release": str(root / "run" / "scheduler-released"), "identity_config": str(etc / "identity.json"),
            "archive": str(root / "archive"), "keep": str(root / "keep"),
        }
        config.update({
            "app_root": str(self.app), "app_owner": DEPLOY_USER, "app_group": WEB_USER, "web_user": WEB_USER,
            "sqlite": str(self.database), "deploy_owner": WEB_USER,
            "deploy_locks": [str(root / "home" / ".repro-backend-deploy.lock"),
                             str(root / "home" / ".repro-frontend-deploy.lock")],
            "queues": ["database:mail", "database:default"],
            "writer_processes": [f"fake-queue-worker-{self.tag}"],
            "long_job_processes": [f"fake-studio-worker-{self.tag}"],
            "scheduler_processes": [f"fake-schedule-run-{self.tag}"],
            "command_seconds": 60,
            "options": {"max_drain_seconds": 5, "separate_freeze_approval": False, "adopt_external_hold": False,
                        "require_long_job_predrain": True, "unattended_reopen": False},
        })
        config["fpm"]["drain_seconds"] = 5
        mysql = config["mysql"]
        mysql.update({
            "mode": self.mode, "offsite_waiver": self.waiver, "target_uuid": self.target, "socket": self.socket,
            "datadir": str(root / "mysql-data"), "client": MYSQL_CLIENT,
            "probe_defaults": str(etc / "mysql-probe.cnf"), "gate_defaults": str(etc / "mysql-gate.cnf"),
            "token_check_defaults": str(etc / "mysql-token-check.cnf"),
            "switch_secrets": {"DB_PASSWORD": str(etc / "mysql-app.env")}, "free_disk_floor_bytes": 1,
        })
        mysql["switch"].update(DB_SOCKET=self.socket, REPRO_MYSQL_EXPECTED_SERVER_UUID=self.target)
        php, app = self.php_path, self.app
        config["commands"].update({
            "identity": [str(bin_ / "identity-probe"), "--config", str(etc / "identity.json")],
            "mysql": [str(bin_ / "mysql-probe")],
            "queue_pause": queue_command("pause", script=f'cd {app} && umask 0022 && exec {php} artisan queue:pause "$1"'),
            "queue_resume": queue_command("resume",
                                          script=f'cd {app} && umask 0022 && exec {php} artisan queue:resume "$1"'),
            "stop_workers": [[str(bin_ / "stop")]], "start_workers": [[str(bin_ / "start")]],
            "drain_fpm": [[sys.executable, "-B", "-c", f"print({json.dumps(json.dumps(drain_report('drained')))})"],
                          [str(bin_ / "fpm-stop")]],
            "config_build": [str(bin_ / "runuser"), "-u", DEPLOY_USER, "-g", WEB_USER, "--", "/bin/sh", "-c",
                             f'umask 0022 && cd {app} && exec env APP_CONFIG_CACHE="$1" {php} artisan config:cache',
                             "repro-config"],
            "fpm_start": [str(bin_ / "systemctl"), "restart", UNIT],
            "fpm_reload": [str(bin_ / "systemctl"), "try-reload-or-restart", UNIT],
            "fpm_stop": [str(bin_ / "systemctl"), "stop", UNIT],
            "smoke_readonly": [
                [str(bin_ / "runuser"), "-u", WEB_USER, "--", "/bin/sh", "-c",
                 f"cd {app} && umask 0022 && exec {php} artisan cutover:smoke --read-only --json", "repro-smoke"],
                [str(bin_ / "runuser"), "-u", DEPLOY_USER, "-g", WEB_USER, "--", "/bin/sh", "-c",
                 f"cd {app} && umask 0002 && exec {php} artisan cutover:smoke --read-only --json", "repro-smoke"]],
            "smoke_write": [str(bin_ / "smoke-write")], "telnyx_replay": [str(bin_ / "telnyx-replay")],
            "timer": [str(bin_ / "timer")],
        })
        return config

    def _write_config(self):
        config, etc = self.config, self.etc
        self.config_path = etc / "host.json"
        self.config_path.write_text(json.dumps(config), encoding="utf-8")
        self.identity_config = {"version": 1, "run_id": RUN_ID, "app_root": config["app_root"],
                                "sqlite_path": config["sqlite"], "mysql_datadir": config["mysql"]["datadir"],
                                "env_denylist": production.env_denylist(config)}
        (etc / "identity.json").write_text(json.dumps(self.identity_config), encoding="utf-8")
        # The real probe loads its own copy with design-form commands (outside this process's patches).
        (etc / "probe-host.json").write_text(json.dumps({**config, "commands": {
            **config["commands"], "config_build": self._design_build(),
            **{f"queue_{verb}": queue_command(verb, script=f'cd {self.app} && umask 0022 && exec /usr/bin/php '
                                                           f'artisan queue:{verb} "$1"') for verb in ("pause", "resume")}}}),
            encoding="utf-8")
        secret = etc / "mysql-app.env"
        secret.write_text(f"DB_PASSWORD={self.password}\n", encoding="ascii")
        secret.chmod(0o600)
        if self.fixture:
            for account, name in (("repro_cutover_probe", "mysql-probe.cnf"), ("repro_cutover_gate", "mysql-gate.cnf"),
                                  ("repro_token_check", "mysql-token-check.cnf")):
                shutil.copyfile(self.fixture.directory / f"{account}.cnf", etc / name)
                (etc / name).chmod(0o600)
        switch = {**config["mysql"]["switch"], "DB_PASSWORD": self.password}
        self.sqlite_pin = route_hash(env_settings(self.env.read_bytes()))
        self.mysql_pin = route_hash(switch)
        fields = {"run_id": RUN_ID, "release_sha": "a" * 40, "artifact_sha256": "b" * 64, "source_sha256": "c" * 64,
                  "target_uuid": self.target}
        self.identity_fields.write_text(json.dumps(fields), encoding="utf-8")
        self.pinned = Identity(**fields, config_sha256=identity_probe.config_sha256(
            self.env.read_bytes(), self.identity_config["env_denylist"]))
        policy = {"version": 1, "machine_id_sha256": guard.sha256((self.root / "machine-id").read_bytes()),
                  "identity": asdict(self.pinned), "sqlite_live_config_sha256": self.sqlite_pin,
                  "mysql_live_config_sha256": self.mysql_pin}
        (etc / "policy.json").write_text(json.dumps(policy, sort_keys=True), encoding="utf-8")

    def _design_build(self) -> list[str]:
        return ["/usr/sbin/runuser", "-u", DEPLOY_USER, "-g", WEB_USER, "--", "/bin/sh", "-c",
                f'umask 0022 && cd {self.app} && exec env APP_CONFIG_CACHE="$1" /usr/bin/php artisan config:cache',
                "repro-config"]

    def rewrite_config(self, change) -> None:
        """Change host.json (the in-memory copy, the file and the probe's copy) with `change(config)`."""
        change(self.config)
        self._write_config()

    # Running the controller.

    def host(self):
        loaded = production.load_config(guard.read_protected(self.config_path), read=guard.read_protected)
        journal = Journal(Path(loaded["paths"]["state"]))
        adapter = production.ProductionAdapter(loaded, journal)
        return journal, adapter, production.runner_for(loaded, journal, adapter)

    def runner(self):
        return self.host()[2]

    def freeze(self) -> dict:
        """init, hold and freeze through the runner, then the night's inputs for this hold."""
        journal, adapter, runner = self.host()
        runner.initialize(fence_deploys=adapter.hold_deploys)
        runner.perform("hold", runner.approval_request("hold"))
        self.state = runner.perform("freeze")
        self.test.assertEqual("frozen", self.state["phase"])
        self.backup = self.coordinate()
        if not self.fixture:
            self.since((self.since_output(), 0))
        self.write_inputs()
        return self.state

    def coordinate(self) -> dict:
        """The verified MySQL base's coordinate (the receipt's `backup`): the fixture's current one."""
        if not self.fixture:
            return {"file": "binlog.000003", "position": 1157, "gtid_executed": f"{self.target}:1-40"}
        file, position, *rest = self.fixture.sql("SHOW BINARY LOG STATUS").rstrip("\n").split("\t")
        return {"file": file, "position": int(position), "gtid_executed": rest[-1]}

    def prepare(self) -> UnsafeCutover | None:
        """runner.perform("prepare"); returns its refusal (None if it passed)."""
        try:
            self.runner().perform("prepare")
        except UnsafeCutover as error:
            return error
        return None

    def assert_prepared(self, error) -> None:
        """Steps 1 to 10 passed and the runner then observed a frozen route on MySQL: only evidence() (D11) refused."""
        self.test.assertEqual(production.NOT_SUPPORTED, str(error))
        observed = self.host()[1].observe()
        self.test.assertTrue(observed.frozen() and observed.engine == "mysql", observed)

    @contextmanager
    def lease(self, journal: Journal | None = None):
        """The controller lease with admitted writers drained, as the runner holds it around an adapter step."""
        journal = journal or self.host()[0]
        with journal.acquire() as (state, lease):
            state["generation"] = lease.generation
            journal.write(state)
            journal.drain_writers(lease, 5)
            yield lease

    def adapter_prepare(self, deadline: float | None = None) -> None:
        """adapter.prepare again under a drained lease, as a retry would run it."""
        _, adapter, _ = self.host()
        with self.lease() as lease:
            adapter.prepare(lease, deadline or time.time() + 600)

    def refused(self, pattern: str, *, scans: bool = False) -> None:
        """A direct prepare is refused with `pattern` and changes nothing (nor scans, unless `scans`)."""
        before = self.changes(scans=not scans)
        with self.test.assertRaisesRegex(UnsafeCutover, pattern):
            self.adapter_prepare()
        self.test.assertEqual(before, self.changes(scans=not scans))

    # The night's inputs (design 3.2 step 2) and the manifest check-evidence writes (D11).

    def documents(self) -> dict:
        """The six tool outputs of a night that bind: one target, one database, the snapshots of this mode."""
        backup, target, frozen_at = self.backup, self.target, utc(self.state["started_at"])
        copy_snapshot = FROZEN_SNAPSHOT if self.mode == "cutover" else COPY_SNAPSHOT
        documents = {
            "snapshot": f"{FROZEN_SNAPSHOT}  /protected/frozen.sqlite\n",
            "import": {"mode": "import-empty", "source_sha256": copy_snapshot, "tables": 120, "schema_sha256": "3" * 64,
                       "type_policy_sha256": "4" * 64, "source_changed": False,
                       "application_configuration_changed": False, "cutover_ready": False, "import_verified": True,
                       "original_rows": 1000, "triggers": 25, "mysql_warning_count": 0, "redundant_mysql_indexes": [],
                       "target_database": "repro_dashboard", "target_server_uuid": target, "next_step": "migrations"},
            "verify_fields": [
                {"event": "table-verified", "table": "users", "rows": 3, "fields": 30},
                {"tables": 120, "rows": 1000, "result": "all-fields-verified", "field_mismatches": 0,
                 "source_sha256": copy_snapshot, "schema_plan_sha256": "5" * 64, "target_server_uuid": target,
                 "target_database": "repro_dashboard", "reader": "independent-php83", "cutover_ready": False}],
            "migrate_status": "\n   INFO  No pending migrations.  \n\n",
            "complete_schema": {"verified": True, "server_uuid": target, "driver_repairs_reapplied": 6,
                                "mysql_cascade_triggers": 25, "migration_ledger_unchanged": True,
                                "application_configuration_changed": False, "cutover_ready": False},
            "backup_check": None if self.waiver else {
                "version": 1, "result": "frozen-base-publications-verified", "release": "a" * 40,
                "frozen_at": frozen_at, "verified_at": frozen_at,
                "sqlite": {"backup_id": str(uuid.uuid4()), "identity": "c" * 64, "snapshot_sha256": FROZEN_SNAPSHOT},
                "mysql": {"backup_id": str(uuid.uuid4()), "identity": target, "database": "repro_dashboard",
                          "schema_sha256": "6" * 64, "pitr": {"binlog_file": backup["file"],
                                                              "binlog_position": backup["position"],
                                                              "coordinate_source": "mysqldump-source-data"}},
                "remote_accessed": False, "restore_verified": False, "retention_verified": False,
                "binlog_continuity_verified": False},
        }
        return documents

    def write_inputs(self, change=None) -> dict:
        """Write the night's tool outputs and the evidence inputs manifest; `change(documents, manifest)` first."""
        documents = self.documents()
        manifest = {"version": 1, "run_id": RUN_ID, "mode": self.mode, "backup": dict(self.backup), "inputs": {}}
        if change is not None:
            change(documents, manifest)
        night = self.root / "night"
        for name in INPUTS:
            document = documents[name]
            if document is None:
                manifest["inputs"].setdefault(name, None)
                continue
            if isinstance(document, str):
                raw = document.encode("utf-8")
            elif isinstance(document, list):
                raw = "".join(json.dumps(line) + "\n" for line in document).encode("utf-8")
            else:
                raw = (json.dumps(document) + "\n").encode("utf-8")
            path = night / name
            path.write_bytes(raw)
            path.chmod(0o600)
            manifest["inputs"].setdefault(name, {"path": str(path), "sha256": hashlib.sha256(raw).hexdigest()})
        self.write_record("evidence_inputs", manifest)
        return manifest

    def path(self, name: str) -> Path:
        return Path(self.config["paths"][name])

    def record(self, name: str):
        path = self.path(name)
        return json.loads(path.read_text(encoding="utf-8")) if path.exists() else None

    def write_record(self, name: str, record) -> None:
        path = self.path(name)
        path.unlink(missing_ok=True)
        path.write_text(record if isinstance(record, str) else json.dumps(record, sort_keys=True), encoding="utf-8")
        path.chmod(0o600)

    # What happened.

    def commands(self) -> list:
        return [json.loads(line) for line in self.log.read_text().splitlines()]

    def scans(self) -> list:
        return [entry[1:] for entry in self.commands() if entry[0] == "mysql-probe" and "--since" in entry]

    def effects(self) -> list:
        """The host effects prepare's switch makes: systemctl, runuser and config:cache, in order."""
        return [entry for entry in self.commands()
                if entry[0] in ("systemctl", "runuser") or entry[:2] == ["php", "config:cache"]]

    def changes(self, *, scans: bool = True) -> dict:
        """Everything prepare may change, to compare before and after a refusal."""
        records = {name: (self.path(name).read_bytes() if self.path(name).exists() else None)
                   for name in ("switch_record", "receipt", "activity", "sealed_hash", "evidence_inputs")}
        return {"env": (self.env.read_bytes(), metadata(self.env)), "config": (self.cache.read_bytes(),
                                                                              self.cache.lstat().st_ino),
                "app": sorted(entry.name for entry in self.app.iterdir()),
                "cache": sorted(entry.name for entry in self.cache.parent.iterdir()),
                "records": records, "effects": self.effects(), "scans": self.scans() if scans else None,
                "gate": self.fixture.snapshot() if self.fixture else None,
                "sqlite": (sqlite_seal.writable(self.database), self.database.read_bytes())}

    def switched(self) -> bool:
        return env_settings(self.env.read_bytes()).get("DB_CONNECTION") == "mysql"

    def assert_markers(self, *, after: int, restarts: int | None = None) -> None:
        """The durable state a controller that stopped after step `after` leaves (README "prepare", for D12):
        the switch record from step 5 (switch_at from step 8), the switched .env from 7, the MySQL config from 8,
        the FPM restart from 9 (`restarts` restarts in all), the receipt from 10."""
        test, record = self.test, self.record("switch_record")
        engine = live_config.measure(self.app)
        restarted = [entry for entry in self.effects() if entry[0] == "systemctl"]
        test.assertEqual(after >= 5, record is not None, f"switch record after step {after}")
        test.assertEqual(after >= 7, self.switched(), f".env switched after step {after}")
        test.assertEqual("mysql" if after >= 8 else "sqlite", engine.engine, f"live engine after step {after}")
        if record is not None:
            test.assertEqual(after >= 8, record["switch_at"] is not None, f"switch_at after step {after}")
        expected = (1 if after >= 9 else 0) if restarts is None else restarts
        test.assertEqual([["systemctl", "restart", UNIT]] * expected, restarted, f"FPM restarts after step {after}")
        test.assertEqual(after >= 10, self.record("receipt") is not None, f"receipt after step {after}")


@unittest.skipUnless(LINUX_ROOT, "Needs Linux root: owners, ACLs, runuser and the guard's protected files")
class PrepareTests(unittest.TestCase):
    """prepare steps 1 to 10 with the scripted MySQL probe (design 3.2)."""

    def frozen(self, **options) -> PrepareHost:
        host = PrepareHost(self, **options)
        host.freeze()
        return host

    def test_prepare_switches_the_frozen_host_to_mysql_in_each_mode(self):
        for options in ({"mode": "drill"}, {"mode": "drill", "waiver": True}, {"mode": "cutover"}):
            with self.subTest(**options):
                host = self.frozen(**options)
                host.assert_prepared(host.prepare())
                host.assert_markers(after=10)
                measured = live_config.measure(host.app)
                self.assertEqual((live_config.CACHE, host.mysql_pin), (measured.source, measured.live_config_sha256))
                # The order of the host effects: config:cache (through runuser, as the deploy user), then the FPM
                # restart.
                self.assertEqual([["runuser", "-u", DEPLOY_USER], ["php", "config:cache"], ["systemctl", "restart"]],
                                 [entry[:3] if entry[0] == "runuser" else entry[:2] for entry in host.effects()])

    def test_input_mismatch_refused_without_change(self):
        host = self.frozen()
        started = host.state["started_at"]
        cases = {
            "another run's manifest": (lambda d, m: m.update(run_id="earlier-run"), "another run"),
            "the other mode": (lambda d, m: m.update(mode="cutover"), "cutover mode, not drill"),
            "a manifest key too many": (lambda d, m: m.update(extra=1), "malformed"),
            "a malformed backup coordinate": (lambda d, m: m["backup"].update(position=3), "backup coordinate"),
            "a backup GTID set that is not one": (lambda d, m: m["backup"].update(gtid_executed="x"),
                                                  "backup coordinate"),
            "an input path that is not absolute": (lambda d, m: m["inputs"].update(
                {"import": {"path": "night/import", "sha256": "0" * 64}}), "malformed"),
            "an input that is not one of the six": (lambda d, m: m["inputs"].update(
                {"notes": {"path": str(host.root / "night" / "import"), "sha256": "0" * 64}}), "malformed"),
            "import of another server": (lambda d, m: d["import"].update(target_server_uuid=OTHER_UUID),
                                         "import result names another MySQL server"),
            "import into another database": (lambda d, m: d["import"].update(target_database="repro_other"),
                                             "import result names another database"),
            "import not verified": (lambda d, m: d["import"].update(import_verified=False),
                                    "import result is not a verified import-empty run"),
            "a plan, not an import": (lambda d, m: d["import"].update(mode="plan"),
                                      "import result is not a verified import-empty run"),
            "verify-fields refused": (lambda d, m: d["verify_fields"][-1].update(result="REFUSED"),
                                      "verify-fields report is not all-fields-verified"),
            "verify-fields mismatches": (lambda d, m: d["verify_fields"][-1].update(field_mismatches=1),
                                         "verify-fields report is not all-fields-verified"),
            "verify-fields of another snapshot": (lambda d, m: d["verify_fields"][-1].update(source_sha256="9" * 64),
                                                  "verify-fields report checked another snapshot"),
            "verify-fields of another server": (lambda d, m: d["verify_fields"][-1].update(
                target_server_uuid=OTHER_UUID), "verify-fields report names another MySQL"),
            "verify-fields of another database": (lambda d, m: d["verify_fields"][-1].update(
                target_database="repro_other"), "verify-fields report names another database"),
            "a pending migration": (lambda d, m: d.update(
                migrate_status="  Migration name ..... Batch / Status\n  2026_12_01_000000_x ..... Pending\n"),
                                    "no migration is pending"),
            "complete-schema not verified": (lambda d, m: d["complete_schema"].update(verified=False),
                                             "complete-schema output is not verified"),
            "complete-schema ledger changed": (lambda d, m: d["complete_schema"].update(
                migration_ledger_unchanged=False), "complete-schema output is not verified"),
            "complete-schema on another server": (lambda d, m: d["complete_schema"].update(
                server_uuid=OTHER_UUID), "complete-schema output names another MySQL"),
            "a refused backup check": (lambda d, m: d["backup_check"].update(result="frozen-base-publications-refused"),
                                       "final backup check did not verify"),
            "a backup check for another release": (lambda d, m: d["backup_check"].update(release="e" * 40),
                                                   "final backup check is for another release"),
            "a backup check from before this hold": (lambda d, m: d["backup_check"].update(frozen_at=utc(started - 60)),
                                                     "before this run's hold"),
            "a SQLite publication of another snapshot": (lambda d, m: d["backup_check"]["sqlite"].update(
                snapshot_sha256="9" * 64), "SQLite publication is not the frozen snapshot"),
            "a MySQL base of another server": (lambda d, m: d["backup_check"]["mysql"].update(
                identity=OTHER_UUID), "MySQL base names another server"),
            "a MySQL base of another database": (lambda d, m: d["backup_check"]["mysql"].update(database="repro_other"),
                                                 "MySQL base names another database"),
            "a MySQL base at another coordinate": (lambda d, m: d["backup_check"]["mysql"]["pitr"].update(
                binlog_position=host.backup["position"] + 1), "not the recorded backup coordinate"),
            "a snapshot record that is not sha256sum's": (lambda d, m: d.update(snapshot="not a digest\n"),
                                                          "frozen snapshot record"),
            "no backup check without the waiver": (lambda d, m: d.update(backup_check=None),
                                                   "Without the off-site waiver the final backup check is required"),
        }
        for name, (change, pattern) in cases.items():
            with self.subTest(name):
                host.write_inputs(change)
                host.refused(pattern)
        # A manifest missing, unsafe or not JSON.
        host.path("evidence_inputs").unlink()
        with self.subTest("no manifest"):
            host.refused("check-evidence --write")
        for name, text, mode in (("not JSON", "{", 0o600), ("writable by others", json.dumps(host.write_inputs()),
                                                                         0o666)):
            with self.subTest(name):
                host.write_record("evidence_inputs", text)
                host.path("evidence_inputs").chmod(mode)
                host.refused("evidence inputs manifest")
        # An input changed after check-evidence recorded its digest: each one.
        for name in INPUTS:
            with self.subTest(changed=name):
                host.write_inputs()
                path = host.root / "night" / name
                path.write_bytes(path.read_bytes() + b"\n")
                host.refused("changed after check-evidence recorded it")
        # The policy must pin the MySQL route hash prepare builds to.
        with self.subTest("no MySQL pin"):
            host.write_inputs()
            policy = json.loads((host.etc / "policy.json").read_text())
            (host.etc / "policy.json").write_text(json.dumps({**policy, "mysql_live_config_sha256": None}))
            host.refused("pins no MySQL live config hash")
            (host.etc / "policy.json").write_text(json.dumps(policy, sort_keys=True))
        # Free disk on / must exceed the configured floor (critic HO-M12).
        with self.subTest("disk below the floor"):
            host.rewrite_config(lambda config: config["mysql"].update(free_disk_floor_bytes=2 ** 50))
            host.refused("below the floor")
            host.rewrite_config(lambda config: config["mysql"].update(free_disk_floor_bytes=1))
        # Every binding holds again: prepare passes.
        host.write_inputs()
        host.adapter_prepare()
        host.assert_markers(after=10)

    def test_drill_and_cutover_bind_their_snapshots(self):
        # Drill (7.1): import and verify-fields bind to the copy's snapshot, the SQLite publication to the frozen
        # one; cutover: one snapshot across all four. The waiver (drill only) replaces the backup check.
        cutover = self.frozen(mode="cutover")
        cutover.write_inputs(lambda d, m: (d["import"].update(source_sha256=COPY_SNAPSHOT),
                                           d["verify_fields"][-1].update(source_sha256=COPY_SNAPSHOT)))
        cutover.refused("Cutover mode needs one snapshot")
        cutover.write_inputs(lambda d, m: d.update(snapshot=f"{COPY_SNAPSHOT}  /protected/frozen.sqlite\n"))
        cutover.refused("Cutover mode needs one snapshot|SQLite publication is not the frozen snapshot")
        waived = self.frozen(mode="drill", waiver=True)
        waived.write_inputs(lambda d, m: d.update(backup_check=self.frozen_check(waived)))
        waived.refused("The off-site waiver replaces the final backup check")
        drill = self.frozen(mode="drill")
        self.assertNotEqual(drill.documents()["import"]["source_sha256"], FROZEN_SNAPSHOT)
        drill.adapter_prepare()
        drill.assert_markers(after=10)

    @staticmethod
    def frozen_check(host: PrepareHost) -> dict:
        """The final backup check a host without the waiver would have."""
        waiver, host.waiver = host.waiver, False
        try:
            return host.documents()["backup_check"]
        finally:
            host.waiver = waiver

    def test_sealed_hash_mismatch_refused(self):
        host = self.frozen()
        sealed = host.record("sealed_hash")
        self.assertEqual(hashlib.sha256(host.database.read_bytes()).hexdigest(), sealed["sha256"])
        for name, record, pattern in (
            ("another run's", {**sealed, "run_id": "earlier-run"}, "another run or hold"),
            ("another hold's", {**sealed, "hold_id": str(uuid.uuid4())}, "another run or hold"),
            ("another database's", {**sealed, "database": "/var/www/backend/database/other.sqlite"}, "another database"),
            ("malformed", {**sealed, "sha256": "x"}, "malformed"),
        ):
            with self.subTest(name):
                host.write_record("sealed_hash", record)
                host.refused(pattern)
        host.write_record("sealed_hash", sealed)
        with self.subTest("missing"):
            host.path("sealed_hash").unlink()
            host.refused("No sealed SQLite hash")
            host.write_record("sealed_hash", sealed)
        # The sealed file itself changed after the freeze (root ignores the modes).
        original = host.database.read_bytes()
        with self.subTest("file changed"):
            with open(host.database, "ab") as stream:
                stream.write(b"\0" * 512)
            host.refused("changed after the freeze hashed it")
            host.database.write_bytes(original)
        with self.subTest("WAL not empty"):
            wal = Path(f"{host.database}-wal")
            wal.write_bytes(b"\1" * 32)
            host.refused("WAL is not empty")
            wal.write_bytes(b"")
        with self.subTest("SQLite writable again"):
            mode = stat.S_IMODE(host.database.stat().st_mode)
            host.database.chmod(mode | stat.S_IWUSR)
            host.refused("SQLite is writable")
            host.database.chmod(mode)
        host.adapter_prepare()
        host.assert_markers(after=10)

    def test_history_scans_start_at_the_backup_and_refuse_any_app_event(self):
        host = self.frozen()
        backup = host.backup
        until = {"file": "binlog.000004", "position": 900, "gtid_executed": f"{host.target}:1-44"}
        # Steps 4 and 10 each scan from the backup coordinate with its GTID history (review B2-9).
        host.since((host.since_output(), 0), (host.since_output(until=until), 0))
        host.assert_prepared(host.prepare())
        expected = ["--since", f"{backup['file']}:{backup['position']}", "--since-gtid", backup["gtid_executed"]]
        self.assertEqual([expected, expected], host.scans())
        # armed_from is the coordinate the probe read before the second scan, never the backup's.
        self.assertEqual({"file": until["file"], "position": until["position"]}, host.record("receipt")["armed_from"])
        transaction = {"gtid": f"{host.target}:41", "file": backup["file"], "position": backup["position"] + 99,
                       "tables": ["shoots"], "statements": [], "boundary": False, "class": "foreign"}
        foreign = (host.since_output(transactions=[transaction]), 0)
        for name, answers, pattern in (
            ("an app-schema event after the backup", [foreign], r"changed after the verified backup.*shoots"),
            ("a history without the backup's GTIDs", [(host.since_output(until={**until, "gtid_executed":
                                                                              f"{host.target}:1-39"}), 0)],
             "no longer holds the backup's GTIDs"),
            ("an open gate", [(host.since_output(gate="open"), 0)], "gate reads open"),
            ("another server", [(host.since_output(server_uuid=OTHER_UUID), 0)],
             "another server"),
            ("another start", [(host.since_output(since={"file": backup["file"], "position": 4}), 0)],
             "another coordinate"),
            ("a purged history (exit 5)", [({"problems": ["The binary log binlog.000003 is no longer listed"]}, 5)],
             r"exit 5.*no longer listed"),
        ):
            with self.subTest(name):
                fresh = self.frozen()
                fresh.since(*answers)
                fresh.refused(pattern, scans=True)
        # The second scan (step 10) refuses too, after the switch: no receipt, no latch removed.
        late = self.frozen()
        late.write_record("activity", {"version": 1, "stale": True})
        late.since((late.since_output(), 0), foreign)
        self.assertRegex(str(late.prepare()), "changed after the verified backup")
        late.assert_markers(after=9)
        self.assertEqual({"version": 1, "stale": True}, late.record("activity"))

    def test_unreachable_mysql_is_retried_then_refused(self):
        unreachable = ({"version": 2, "reachable": False}, 3)
        with patch.object(production, "PROBE_RETRY_SECONDS", 0.05):
            host = self.frozen()
            host.since(unreachable, (host.since_output(), 0))
            host.adapter_prepare()
            host.assert_markers(after=10)
            down = self.frozen()
            down.since(unreachable)
            down.refused("not reachable", scans=True)
            self.assertEqual(production.PROBE_ATTEMPTS, len(down.scans()))
            # The gate reading of step 4 needs a reachable server too.
            gone = self.frozen()
            gone.answer(*unreachable)
            gone.refused("not reachable", scans=True)

    def test_switch_record_bound_to_run_and_digest(self):
        host = self.frozen()
        original = host.env.read_bytes()
        host.assert_prepared(host.prepare())
        record = host.record("switch_record")
        self.assertEqual(SWITCH_RECORD_KEYS, set(record))
        info = host.path("switch_record").lstat()
        self.assertEqual((0, 0o600), (info.st_uid, stat.S_IMODE(info.st_mode)))
        # Only the switch keys, with their SQLite values (null when .env had none): no other key, no secret.
        self.assertEqual({"version": 1, "run_id": RUN_ID, "env_sha256": hashlib.sha256(b"\n".join(
            line for line in original.split(b"\n")
            if not any(line.startswith(flag.encode() + b"=") for flag in NIGHT_FLAGS))).hexdigest(),
            "values": {**{key: None for key in production.switch_keys(host.config)}, "DB_CONNECTION": "sqlite",
                       "DB_DATABASE": str(host.database)}},
            {name: value for name, value in record.items() if name != "switch_at"})
        self.assertIsInstance(record["switch_at"], int)
        raw = host.path("switch_record").read_bytes()
        for secret in (b"APP_KEY", b"cHJlcGFyZS1maXh0dXJl", host.password.encode()):
            self.assertNotIn(secret, raw)
        # A retry keeps the record (its switch_at moves to the new build) and the switched .env.
        switched = host.env.read_bytes()
        host.adapter_prepare()
        self.assertEqual({**record, "switch_at": None}, {**host.record("switch_record"), "switch_at": None})
        self.assertEqual(switched, host.env.read_bytes())
        # A night flag turned since the record was taken does not make it unverifiable.
        host.env.write_bytes(switched.replace(b"CRM_OUTBOUND_HOLD=true", b"CRM_OUTBOUND_HOLD=false"))
        host.adapter_prepare()
        # Another run's record, or this run's that the .env does not match, is never used or replaced.
        for name, change, pattern in (
            ("another run's", lambda r: r.update(run_id="earlier-run"), "belongs to another run"),
            ("another SQLite path", lambda r: r["values"].update(DB_DATABASE="/var/www/other.sqlite"),
             "does not match the .env"),
            ("another .env", lambda r: r.update(env_sha256="0" * 64), "does not match the .env"),
            ("a key too few", lambda r: r["values"].pop("DB_SOCKET"), "malformed"),
        ):
            with self.subTest(name):
                fresh = self.frozen()
                changed = {"version": 1, "run_id": RUN_ID, "values": envfile.values(
                    fresh.env.read_bytes(), production.switch_keys(fresh.config)),
                    "env_sha256": envfile.digest(fresh.env.read_bytes(), NIGHT_FLAGS), "switch_at": None}
                change(changed)
                fresh.write_record("switch_record", changed)
                fresh.refused(pattern, scans=True)
        # No record and a .env that already names MySQL: switched by hand, so nothing would restore SQLite.
        by_hand = self.frozen()
        by_hand.env.write_bytes(by_hand.env.read_bytes().replace(b"DB_CONNECTION=sqlite", b"DB_CONNECTION=mysql"))
        by_hand.refused("switched by hand", scans=True)

    def test_env_replaced_with_acl_owner_and_mode(self):
        host = self.frozen()
        before, original = metadata(host.env), host.env.read_bytes()
        self.assertIn("system.posix_acl_access", before[4])
        leftover = host.app / f".env.repro-{uuid.uuid4().hex}"
        leftover.write_bytes(b"DB_PASSWORD=left-by-a-crash\n")
        host.assert_prepared(host.prepare())
        after = metadata(host.env)
        # A new file renamed into place, with the owner, group, mode and every extended attribute kept.
        self.assertNotEqual(before[0], after[0])
        self.assertEqual(before[1:], after[1:])
        self.assertEqual(BACKUP_ACL, os.getxattr(host.env, "system.posix_acl_access"))
        self.assertEqual([".env", "artisan", "bootstrap", "fake-php.json"], sorted(e.name for e in host.app.iterdir()))
        # Only the switch keys changed; every other line byte for byte; the values are host.json's and the secret.
        switch = {**host.config["mysql"]["switch"], "DB_PASSWORD": host.password}
        self.assertEqual(envfile.assign(original, switch), host.env.read_bytes())
        self.assertEqual(switch, envfile.values(host.env.read_bytes(), switch))
        # Twice is once: a retry leaves the same file in place (no second replacement).
        host.adapter_prepare()
        self.assertEqual(after, metadata(host.env))

    def test_config_digest_unchanged_by_switch(self):
        # The v1 bug (critic RC-H1): a switch key outside the identity's denylist moves config_sha256, and the
        # runner then refuses every action, the rollback included. DB_PASSWORD is the switch key v1 missed.
        host = self.frozen()
        denylist = json.loads((host.etc / "identity.json").read_text())["env_denylist"]
        before = host.env.read_bytes()
        host.assert_prepared(host.prepare())
        after = host.env.read_bytes()
        self.assertIn(f"DB_PASSWORD={host.password}\n".encode(), after)
        self.assertNotEqual(before, after)
        self.assertEqual(identity_probe.config_sha256(before, denylist), identity_probe.config_sha256(after, denylist))
        self.assertEqual(host.pinned.config_sha256, identity_probe.config_sha256(after, denylist))
        # The runner measures the identity again (the identity probe reads the live .env): it still matches.
        self.assertEqual("frozen", host.runner().perform("tick")["phase"])
        # With a denylist that lacks DB_PASSWORD (identity.json changed after the config loaded), the switch would
        # move the digest: refused before .env changes.
        fresh = self.frozen()
        journal, adapter, _ = fresh.host()
        (fresh.etc / "identity.json").write_text(json.dumps({**fresh.identity_config, "env_denylist": [
            name for name in denylist if name != "DB_PASSWORD"]}))
        before = fresh.changes(scans=False)
        with fresh.lease(journal) as lease, self.assertRaisesRegex(UnsafeCutover, "would change config_sha256"):
            adapter.prepare(lease, time.time() + 600)
        changed = fresh.changes(scans=False)
        self.assertEqual(before["env"], changed["env"])
        self.assertEqual(before["effects"], changed["effects"])

    def test_config_built_atomically_and_matches_mysql_pin(self):
        host = self.frozen()
        inode = host.cache.lstat().st_ino
        started = int(time.time())
        host.assert_prepared(host.prepare())
        measured = live_config.measure(host.app)
        self.assertEqual(("mysql", live_config.CACHE, host.mysql_pin),
                         (measured.engine, measured.source, measured.live_config_sha256))
        info = host.cache.lstat()
        self.assertEqual((host.deploy, WEB_UID, 0o644), (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)))
        self.assertNotEqual(inode, info.st_ino)
        self.assertEqual(["config.php"], sorted(entry.name for entry in host.cache.parent.iterdir()))
        # Built by design 1.3's command as the deploy user, into a temporary file beside config.php.
        runuser, build = [entry for entry in host.effects() if entry[0] in ("runuser", "php")][:2]
        self.assertEqual(host.config["commands"]["config_build"][1:], runuser[1:-1])
        self.assertRegex(build[2], rf"^{re.escape(str(host.cache.parent))}/\.config\.php\.repro-[0-9a-f]{{32}}$")
        self.assertEqual([host.deploy, WEB_UID, 0o022], build[3:])
        self.assertLessEqual(started, host.record("switch_record")["switch_at"])
        # A build that fails, or gives another route hash, leaves the live file and starts no FPM.
        for mode, pattern in (("half-then-fail", "exited 1"), ("other-route", "not the expected route hash")):
            with self.subTest(mode):
                fresh = self.frozen()
                fresh.php(config_cache=mode)
                cache = fresh.cache.read_bytes(), fresh.cache.lstat().st_ino
                self.assertRegex(str(fresh.prepare()), f"MySQL config cache was not built: .*{pattern}")
                self.assertEqual(cache, (fresh.cache.read_bytes(), fresh.cache.lstat().st_ino))
                self.assertEqual(["config.php"], sorted(entry.name for entry in fresh.cache.parent.iterdir()))
                fresh.assert_markers(after=7)
        # The deploy user may replace config.php at any time: prepare measures the live file again after the build.
        replaced = self.frozen()
        build_and_replace = config_build.build

        def build(*arguments, **keywords):
            result = build_and_replace(*arguments, **keywords)
            replaced.cache.write_bytes(cache_text(config_for(env_settings(ENV.format(
                database=replaced.database).encode()))))
            return result

        with patch.object(config_build, "build", side_effect=build):
            self.assertRegex(str(replaced.prepare()), "live config after the build is not the MySQL pin")
        self.assertEqual([], [entry for entry in replaced.effects() if entry[0] == "systemctl"])
        self.assertIsNone(replaced.record("switch_record")["switch_at"])
        # A cache the reader refuses before prepare (a fallback) is simply rebuilt.
        truncated = self.frozen()
        truncated.cache.write_bytes(truncated.cache.read_bytes()[:40])
        truncated.adapter_prepare()
        truncated.assert_markers(after=10)

    def test_receipt_written_root_only_with_the_readme_keys(self):
        host = self.frozen()
        stale = {"version": 1, "run_id": RUN_ID, "receipt_sha256": "0" * 64, "gtid": "x", "file": "binlog.000001",
                 "position": 4, "tables": [], "statements": [], "boundary": None, "foreign_activity": False}
        host.write_record("activity", stale)
        host.assert_prepared(host.prepare())
        receipt = host.record("receipt")
        self.assertEqual(RECEIPT_KEYS, set(receipt))
        self.assertEqual({"version": 1, "run_id": RUN_ID, "server_uuid": host.target, "database": "repro_dashboard",
                          "backup": host.backup, "armed_from": {"file": host.backup["file"],
                                                                "position": host.backup["position"] + 4096}}, receipt)
        info = host.path("receipt").lstat()
        self.assertEqual((0, 0o600), (info.st_uid, stat.S_IMODE(info.st_mode)))
        # The latch went before the new receipt (README: prepare removes paths.activity first).
        self.assertIsNone(host.record("activity"))

    def test_prepare_is_safe_to_run_twice_and_resumes_after_a_crash_at_any_step(self):
        steps = dict(production.PREPARE_STEPS)
        self.assertEqual(list(range(1, 11)), sorted(steps))
        for number in range(1, 11):
            with self.subTest(crash_after=steps[number]):
                host = self.frozen()
                if number < 10:
                    with patch.object(production.ProductionAdapter, f"_prepare_{steps[number + 1]}",
                                      side_effect=Crash(f"stopped after step {number}")):
                        with self.assertRaises(Crash):
                            host.runner().perform("prepare")
                else:
                    host.assert_prepared(host.prepare())
                host.assert_markers(after=number)
                # A second run from that state finishes the switch, whatever was done before; the runner's next
                # action still finds the pinned identity (the .env digest did not move).
                host.adapter_prepare()
                host.assert_markers(after=10, restarts=2 if number >= 9 else 1)
                self.assertEqual("frozen", host.runner().perform("tick")["phase"])

    def test_prepare_runs_only_in_a_frozen_journal_before_its_deadline(self):
        host = self.frozen()
        journal = host.host()[0]
        state = journal.read()
        for name, change in (("ready", {"phase": "ready"}), ("holding", {"phase": "holding"}),
                             ("writes possible", {"writes_possible": True})):
            with self.subTest(name):
                journal.write({**state, **change})
                host.refused("only in a frozen journal in which MySQL was never opened for writes")
        journal.write(state)
        with self.subTest("deadline passed"):
            before = host.changes()
            with self.assertRaisesRegex(UnsafeCutover, r"ran out of time before step 1 \(writers drained\)"):
                host.adapter_prepare(deadline=time.time() - 1)
            self.assertEqual(before, host.changes())
        host.adapter_prepare()
        host.assert_markers(after=10)

    def test_the_gate_reading_must_be_closed_on_the_target(self):
        host = self.frozen()
        reading = probe_reading(server_uuid=host.target)
        for name, answer, pattern in (
            ("an open gate", (probe_reading(gate="open", server_uuid=host.target), 0), "not exactly closed"),
            ("business activity", ({**reading, "business_activity": True}, 0), "not exactly closed"),
            ("another server", ({**reading, "server_uuid": OTHER_UUID}, 0), "another server or database"),
            ("another database", ({**reading, "database": "repro_other"}, 0), "another server or database"),
            ("no session count", ({key: value for key, value in reading.items() if key != "gated_sessions"}, 0),
             "no app session count"),
            ("a partial gate (exit 4)", ({"gate": "partial", "problems": ["The gate state is partial"]}, 4),
             r"exit 4\): The gate state is partial"),
        ):
            with self.subTest(name):
                host.answer(*answer)
                host.refused(pattern)
        host.answer(reading)
        host.adapter_prepare()
        host.assert_markers(after=10)

    def test_the_app_password_comes_from_its_root_only_file(self):
        host = self.frozen()
        secret = host.etc / "mysql-app.env"
        good = secret.read_bytes()
        for name, content, mode, pattern in (
            ("readable by others", good, 0o644, "must be readable by root only"),
            ("writable by others", good, 0o666, "is not root's"),
            ("a second line", good + b"APP_KEY=base64:x\n", 0o600, "must hold one line DB_PASSWORD="),
            ("another key", good.replace(b"DB_PASSWORD=", b"DB_PASSWORDS="), 0o600, "must hold one line DB_PASSWORD="),
            ("a value .env would read otherwise", b"DB_PASSWORD=has space # 0123456789abcdef\n", 0o600,
             "must hold one line DB_PASSWORD="),
            ("too short", b"DB_PASSWORD=short\n", 0o600, "must hold one line DB_PASSWORD="),
        ):
            with self.subTest(name):
                secret.write_bytes(content)
                secret.chmod(mode)
                before = host.changes(scans=False)
                with self.assertRaisesRegex(UnsafeCutover, pattern) as refused:
                    host.adapter_prepare()
                self.assertNotIn(host.password, str(refused.exception))
                after = host.changes(scans=False)
                self.assertEqual((before["env"], before["effects"]), (after["env"], after["effects"]))
        secret.unlink()
        with self.subTest("missing"), self.assertRaisesRegex(UnsafeCutover, "mysql-app.env for DB_PASSWORD is missing"):
            host.adapter_prepare()
        secret.write_bytes(good)
        secret.chmod(0o600)
        host.adapter_prepare()
        host.assert_markers(after=10)
        self.assertIn(good, host.env.read_bytes())


@unittest.skipUnless(LINUX_ROOT and PROBE_FIXTURE, "Needs Linux root and the MySQL fixture (REPRO_MYSQL_PROBE_FIXTURE)")
class PrepareFixtureTests(unittest.TestCase):
    """prepare against D04's MySQL 8.4.13 fixture: the real probe's --since scans, the receipt, the session kill."""

    @classmethod
    def setUpClass(cls):
        from test_mysql_probe import APP_TABLES, Fixture

        cls.fixture = Fixture(PROBE_FIXTURE)
        cls.fixture.restore()
        cls.fixture.sql(APP_TABLES)
        cls.baseline = cls.fixture.snapshot()

    def setUp(self):
        self.addCleanup(self.assert_restored)

    def assert_restored(self):
        self.fixture.sql("ALTER USER 'repro_maint'@'localhost' ACCOUNT UNLOCK")
        self.fixture.restore()
        self.assertEqual(self.baseline, self.fixture.snapshot())

    def frozen(self, **options) -> PrepareHost:
        host = PrepareHost(self, fixture=self.fixture, **options)
        host.freeze()
        return host

    def test_receipt_has_backup_and_armed_from(self):
        from test_mysql_probe import HEARTBEAT

        host = self.frozen()
        # Events after the backup coordinate that touch no app table: the heartbeat (excluded) and an exempt lock.
        self.fixture.sql(f"{HEARTBEAT}; ALTER USER 'repro_maint'@'localhost' ACCOUNT LOCK;"
                         "ALTER USER 'repro_maint'@'localhost' ACCOUNT UNLOCK")
        host.write_record("activity", {"version": 1, "run_id": RUN_ID, "receipt_sha256": "0" * 64, "gtid": "x",
                                       "file": host.backup["file"], "position": 4, "tables": [], "statements": [],
                                       "boundary": None, "foreign_activity": False})
        host.assert_prepared(host.prepare())
        now = host.coordinate()
        receipt = host.record("receipt")
        self.assertEqual({"version": 1, "run_id": RUN_ID, "server_uuid": self.fixture.uuid,
                          "database": "repro_dashboard", "backup": host.backup,
                          "armed_from": {"file": now["file"], "position": now["position"]}}, receipt)
        self.assertNotEqual(host.backup["position"], receipt["armed_from"]["position"])
        info = host.path("receipt").lstat()
        self.assertEqual((0, 0o600), (info.st_uid, stat.S_IMODE(info.st_mode)))
        self.assertIsNone(host.record("activity"))
        expected = ["--since", f"{host.backup['file']}:{host.backup['position']}", "--since-gtid",
                    host.backup["gtid_executed"]]
        self.assertEqual([expected, expected], host.scans())
        # The probe arms on it: this run's receipt, no activity since armed_from.
        code, output = host.host()[1]._execute(host.config["commands"]["mysql"])
        reading = json.loads(output)
        self.assertEqual((0, hashlib.sha256(host.path("receipt").read_bytes()).hexdigest(), False, False),
                         (code, reading["receipt"], reading["business_activity"], reading["foreign_activity"]))

    def test_foreign_event_after_backup_coordinate_refused(self):
        from test_mysql_probe import FOREIGN_WRITE

        for name, (account, statement), tables in (
            ("a maintenance write", FOREIGN_WRITE, "shoots"),
            # README, the --since contract: every app-schema event after the coordinate counts, even ANALYZE.
            ("ANALYZE TABLE", ("repro_migrator", "ANALYZE TABLE repro_dashboard.jobs"), "jobs|query"),
        ):
            with self.subTest(name):
                host = self.frozen()
                before = host.changes(scans=False)
                self.fixture.sql(statement, account=account)
                self.assertRegex(str(host.prepare()), rf"changed after the verified backup.*({tables})")
                self.assertEqual(before, host.changes(scans=False))
                self.assertEqual(1, len(host.scans()))

    def test_app_sessions_are_killed_before_the_scan(self):
        host = self.frozen()
        session = subprocess.Popen([MYSQL_CLIENT, f"--defaults-file={self.fixture.directory / 'repro_app.cnf'}",
                                    "--no-login-paths", "--protocol=SOCKET", f"--socket={self.fixture.socket}",
                                    "-e", "SELECT SLEEP(120)"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.addCleanup(lambda: session.poll() is None and session.kill())
        listed = "SELECT ID FROM performance_schema.processlist WHERE USER = 'repro_app'"
        for _ in range(100):
            if self.fixture.sql(listed).strip():
                break
            time.sleep(0.1)
        self.assertTrue(self.fixture.sql(listed).strip())
        host.assert_prepared(host.prepare())
        self.assertEqual("", self.fixture.sql(listed).strip())
        self.assertNotEqual(0, session.wait(timeout=30))


# The fixture tests: named before any suite when they would skip (as test_adapter.py's real-probe test).

def fixture_tests() -> list[str]:
    return [f"{PrepareFixtureTests.__name__}.{name}" for name in unittest.TestLoader().getTestCaseNames(PrepareFixtureTests)]


def fixture_skips() -> list[str]:
    return [] if LINUX_ROOT and PROBE_FIXTURE else fixture_tests()


def fixture_check() -> int:
    """`--fixture-check`, run by run-linux-tests.sh first: one WARNING line on stdout naming the fixture tests
    that would skip, or, with REPRO_REQUIRE_PROBE_FIXTURE=1, an error and exit 1."""
    skipped = fixture_skips()
    if not skipped:
        return 0
    missing = [] if PROBE_FIXTURE else ["REPRO_MYSQL_PROBE_FIXTURE unset"]
    if not LINUX_ROOT:
        missing.append("not Linux root")
    summary = (f"{len(skipped)} prepare tests against the MySQL probe fixture SKIPPED ({', '.join(missing)}): "
               f"{', '.join(skipped)}")
    if REQUIRE_PROBE_FIXTURE:
        print(f"ERROR: REPRO_REQUIRE_PROBE_FIXTURE=1 but {summary}", file=sys.stderr)
        return 1
    print(f"WARNING: {summary}. This run says nothing about prepare's scans and receipt on a real server: run "
          "scripts/cutover/production/run-probe-fixture-tests.sh --linux-suite, or set REPRO_REQUIRE_PROBE_FIXTURE=1 "
          "to fail.")
    return 0


def without_fixture_environment(**extra: str) -> dict:
    environment = {name: value for name, value in os.environ.items()
                   if not name.startswith("REPRO_MYSQL_PROBE_FIXTURE") and name != "REPRO_REQUIRE_PROBE_FIXTURE"}
    return {**environment, **extra}


class FixtureCheckTests(unittest.TestCase):
    """`test_adapter_mysql.py --fixture-check`, which run-linux-tests.sh runs before any suite."""

    def check(self, environment: dict) -> subprocess.CompletedProcess:
        return subprocess.run([sys.executable, "-B", str(Path(__file__).resolve()), "--fixture-check"],
                              env=environment, capture_output=True, text=True, timeout=120)

    def test_the_fixture_tests_are_the_fixture_class(self):
        self.assertEqual(["PrepareFixtureTests.test_app_sessions_are_killed_before_the_scan",
                          "PrepareFixtureTests.test_foreign_event_after_backup_coordinate_refused",
                          "PrepareFixtureTests.test_receipt_has_backup_and_armed_from"], fixture_tests())

    def test_missing_fixture_prints_one_warning_line_naming_the_fixture_tests(self):
        result = self.check(without_fixture_environment())
        self.assertEqual((0, ""), (result.returncode, result.stderr))
        lines = result.stdout.splitlines()
        self.assertEqual(1, len(lines), result.stdout)
        self.assertRegex(lines[0], r"^WARNING: 3 prepare tests against the MySQL probe fixture SKIPPED ")
        for name in ("REPRO_MYSQL_PROBE_FIXTURE", *fixture_tests(), "run-probe-fixture-tests.sh --linux-suite"):
            self.assertIn(name, lines[0])

    def test_missing_fixture_fails_when_required(self):
        result = self.check(without_fixture_environment(REPRO_REQUIRE_PROBE_FIXTURE="1"))
        self.assertNotEqual(0, result.returncode)
        self.assertEqual("", result.stdout)
        self.assertRegex(result.stderr, r"^ERROR: REPRO_REQUIRE_PROBE_FIXTURE=1 ")
        self.assertIn("PrepareFixtureTests.test_receipt_has_backup_and_armed_from", result.stderr)

    @unittest.skipUnless(LINUX_ROOT, "The fixture tests need Linux root as well")
    def test_fixture_named_is_silent(self):
        result = self.check(without_fixture_environment(REPRO_REQUIRE_PROBE_FIXTURE="1",
                                                        REPRO_MYSQL_PROBE_FIXTURE="/fixture/socket"))
        self.assertEqual((0, "", ""), (result.returncode, result.stdout, result.stderr))


class FixtureRequirementTests(unittest.TestCase):
    """With REPRO_REQUIRE_PROBE_FIXTURE=1 a fixture test that would skip fails the run."""

    def test_required_fixture_tests_do_not_skip(self):
        if not REQUIRE_PROBE_FIXTURE:
            self.skipTest("REPRO_REQUIRE_PROBE_FIXTURE is not 1")
        self.assertEqual([], fixture_skips(), "REPRO_REQUIRE_PROBE_FIXTURE=1, but these prepare tests would skip")


RUN_LINUX_TESTS = HERE.parent / "run-linux-tests.sh"


@unittest.skipUnless(LINUX_ROOT and Path("/.dockerenv").is_file()
                     and RUN_LINUX_TESTS == Path("/src/scripts/cutover/run-linux-tests.sh")
                     and not os.environ.get("REPRO_LINUX_SUITE_NESTED"),
                     "Needs root in a disposable container with the checkout at /src, as run-linux-tests.sh")
class LinuxSuiteTests(unittest.TestCase):
    """run-linux-tests.sh names this file's fixture tests before any suite, and runs both new files."""

    def test_required_missing_fixture_names_the_prepare_tests_before_any_suite(self):
        # REPRO_LINUX_SUITE_NESTED keeps a broken script from starting this test again inside itself.
        result = subprocess.run(["/bin/sh", str(RUN_LINUX_TESTS)], capture_output=True, text=True, timeout=600,
                                env=without_fixture_environment(REPRO_REQUIRE_PROBE_FIXTURE="1",
                                                                REPRO_LINUX_SUITE_NESTED="1"))
        self.assertNotEqual(0, result.returncode, result.stderr[-2000:])
        self.assertIn("PrepareFixtureTests.test_receipt_has_backup_and_armed_from", result.stderr)
        self.assertNotIn("Ran ", result.stdout + result.stderr)

    def test_the_script_runs_the_envfile_and_prepare_suites(self):
        lines = RUN_LINUX_TESTS.read_text(encoding="utf-8").splitlines()
        for name in ("test_envfile.py", "test_adapter_mysql.py"):
            with self.subTest(name):
                self.assertEqual(1, sum(1 for line in lines if line.strip().endswith(f"-p {name}")), name)


if __name__ == "__main__":
    if sys.argv[1:] == ["--fixture-check"]:
        raise SystemExit(fixture_check())
    unittest.main()
