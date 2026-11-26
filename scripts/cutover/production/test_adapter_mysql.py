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
| php | `bin/php` | /usr/bin/php: `queue:pause|resume` (records), `config:cache` (writes $APP_CONFIG_CACHE from `.env` through ROUTES, see `config_for`; DB_* variables in its environment win over `.env`, as Laravel's), `cutover:smoke` and `db:writer-policy-check` (D11: printed from the config the process would load and its own user, group, cwd and umask, `fake_artisan`; each run records its context, `php_context`; `php(answers=...)` changes them), any other artisan command records itself and exits 0 |
| identity probe | `bin/identity-probe` | identity_probe.py: the pinned release, artifact, source and target, and `config_sha256` measured from the live `.env` with identity.json's denylist, so the runner refuses a switch that moved it |
| MySQL probe | `bin/mysql-probe` | fixture: records, then runs the real mysql_probe.py with `etc/probe-host.json` (design-form commands); otherwise answers from `etc/mysql-answers.json` (`answer(...)`, `since(...)`); a reachable reading whose `receipt` is null names this run's receipt once it exists, as the real probe does (`answer(..., arm=False)`: not armed) |
| wrapper profiles | `state/wrappers.json` (`profiles()`, `write_wrappers(...)`) | /etc/repro-cutover-fence/wrappers.json: nine profiles in five contexts, two shell scripts, one with its own environment (D11's per-profile probes) |
| FPM pool | `fpm/php-fpm.sock` (`FpmStandIn`, `host.fpm`) | the app's PHP-FPM for the FastCGI probe: fpm-engine.php's report for the live config.php (`fpm.settings`, `fpm.stop()`, `fpm.start()`) |
| gate.check | patched on scripted hosts (`gate(...)`, `gate_check` counts calls) | the real gate.check with the gate account's self-check runs on fixture hosts |
| token check account | `etc/mysql-token-check.cnf` | repro_token_check's defaults file (fixture: the fixture's; scripted: a fake password, `token_password`) |

Every recorder appends one JSON list per call to `log/commands.jsonl` (`commands()`); the MySQL probe records
`["mysql-probe", *arguments]`. In-process, the adapter's QUEUE_PHP and config_build's RUNUSER and PHP are patched
to `bin/php` and `bin/runuser`, so load_config's command checks hold for the harness's own paths.

Use: `freeze()` runs init, hold and freeze through the real runner and writes the night inputs; `prepare()`
runs `runner.perform("prepare")` and returns the refusal; `assert_prepared(error)` checks that steps 1 to 12
passed, `evidence()` held and the run is `ready` with the record of this run's report (D11), so a retry after it
uses `adapter_prepare()` instead (the run stays `frozen`); `write_night(...)` writes check-evidence's input
directory, `report()` reads the evidence report, `tree()` snapshots every file for "nothing else changed";
`adapter_prepare()` calls `adapter.prepare` again under a drained lease (a retry); `changes()` is everything
prepare may change, for "refused without change" checks; `assert_markers(after=n)` checks the durable state a
crash after step n leaves (the markers D12's abort reads, README "prepare"). `crash_after(n)` stops a runner
prepare between step n and the next; `kill_inside(point)` SIGKILLs a forked controller inside step 7
(`before-env-rename`) or step 8 (`after-config-rename`), the in-step states of README "What a crash leaves";
`leftovers()` lists the temporary files such a kill leaves. `PrepareHost(..., app_link=True)` reaches the app
root through a symbolic link. D11's tests are EvidenceTests and EvidenceFixtureTests; D12 drives `abort` from
each crash point that
`test_prepare_is_safe_to_run_twice_and_resumes_after_a_crash_at_any_step` and
`test_a_kill_inside_a_step_leaves_a_state_the_next_prepare_finishes` build (`SealSqliteTests`, on `UndoHost`, at
the end of this file), and its undo replaced D10's interim refusal and that refusal's test.

    python3 -B -m unittest discover -s scripts/cutover/production -p test_adapter_mysql.py -v
"""

from __future__ import annotations

import copy
import hashlib
import json
import os
import re
import shutil
import signal
import socket
import stat
import subprocess
import sys
import tempfile
import threading
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
import gate  # noqa: E402
import identity_probe  # noqa: E402
import live_config  # noqa: E402
import sqlite_seal  # noqa: E402
import var_export  # noqa: E402
from fence import guard  # noqa: E402
from runner import Identity, Journal, UnsafeCutover  # noqa: E402
from test_adapter import (CLOSE_GATE, MYSQL_CLIENT, MYSQL_CONFIG, MYSQL_PROBE, NIGHT_FLAGS, OPEN_GATE,  # noqa: E402
                          OTHER_UUID, PROBE_FIXTURE, REQUIRE_PROBE_FIXTURE, SQLITE_CONFIG, TARGET_UUID, drain_report,
                          example_config, probe_reading, queue_command)
from test_envfile import BACKUP_ACL  # noqa: E402
from test_fastcgi import decode_pairs, end, read_records, rec  # noqa: E402
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
# D11 (step 11): the marked test records the read-only smoke reads (host.json smoke), the check account the token check
# logs in as (mysql-token-check.cnf; scripted hosts get this password) and the FPM pool's OPcache the stand-in reports.
SMOKE_RECORDS = example_config()["smoke"]
TOKEN_USER, FAKE_TOKEN_PASSWORD = "repro_token_check", "fixture-token-check-password-0123"
OPCACHE = {"enabled": True, "cache_full": False, "restart_pending": False, "num_cached_scripts": 0,
           "start_time": None, "last_restart_time": 0, "validate_timestamps": True}
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
stdout = answer["stdout"]
# A reading of a reachable server names the receipt it armed on, as mysql_probe.py does once prepare wrote this run's
# receipt (D11's checks compare it), unless the answer says it is not armed.
if answer.get("arm", True) and isinstance(stdout, dict) and stdout.get("reachable") and stdout.get("receipt", 0) is None:
    import hashlib
    try:
        with open({receipt!r}, "rb") as stream:
            stdout["receipt"] = hashlib.sha256(stream.read()).hexdigest()
    except FileNotFoundError:
        pass
print(json.dumps(stdout))
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
    # Laravel's immutable environment keeps what the process already has over .env (the token check's account);
    # "ignore-env" builds from .env alone.
    mode = settings.get("config_cache", "ok")
    if mode != "ignore-env":
        env.update((name, value) for name, value in os.environ.items() if name.startswith("DB_"))
    if mode == "other-route":
        env["DB_COLLATION"] = "utf8mb4_general_ci"
    content = cache_text(config_for(env))
    with open(target, "wb") as stream:
        stream.write(content[:len(content) // 2] if mode == "half-then-fail" else content)
    sys.exit(1 if mode == "half-then-fail" else 0)
if command in ("cutover:smoke", "db:writer-policy-check"):
    from test_adapter_mysql import fake_artisan, php_context
    record(["php", command, *arguments, php_context()])
    stdout, code = fake_artisan(command, arguments, settings, artisan)
    print(stdout)
    sys.exit(code)
record(["php", command, *arguments])
answer = settings.get("answers", {{}}).get(command)
if answer is not None:
    print(answer["stdout"])
    sys.exit(answer["code"])
"""


def engine_report(config: dict, path: str, settings: dict, process: dict) -> dict:
    """What `cutover:smoke --engine-only --json` and fpm-engine.php print for a process on this cached config
    (CutoverEngineProbe::measure): engine, database, server identity and the raw route values; the fixture's server
    UUID comes from the fake's settings, since the fake never connects."""
    engine = live_config.engine(config)
    default = config["database"]["default"]
    connection = config["database"]["connections"][default]
    return {"engine": engine, "connection": default, "database": connection.get("database"),
            "server_uuid": settings.get("server_uuid") if engine == "mysql" else None,
            "server_version": "8.4.13" if engine == "mysql" else "3.46.1", "config_cached": True,
            "config_path": path, "process": process, "route_values": live_config.route_values(config), "problems": []}


def _changed(report: dict, changes: dict) -> dict:
    """A computed report with an answer's changes: maps one level deep, any other value replaced."""
    for key, value in changes.items():
        report[key] = {**report[key], **value} if isinstance(value, dict) and isinstance(report.get(key), dict) else value
    return report


def fake_artisan(command: str, arguments: list, settings: dict, artisan: str) -> tuple[str, int]:
    """What the fake php prints for D11's artisan commands, computed from the config this process would load (the
    APP_CONFIG_CACHE it was given, else the app's live config.php) and its own user, group, cwd and umask.

    An answer in fake-php.json's `answers`, keyed `"<command line>@<user>"`, `"<command line>"` or `"<command>"`,
    either replaces the output (`stdout`, `code`) or changes the computed report (`changes`, merged one level deep;
    `ok` and the exit follow the problems unless the answer names them)."""
    import grp
    import pwd

    user = pwd.getpwuid(os.geteuid()).pw_name
    line = " ".join([command, *arguments])
    answers = settings.get("answers", {})
    answer = answers.get(f"{line}@{user}") or answers.get(line) or answers.get(command) or {}
    if "stdout" in answer:
        return answer["stdout"], answer.get("code", 0)
    path = os.environ.get("APP_CONFIG_CACHE") or os.path.join(os.path.dirname(os.path.abspath(artisan)), "bootstrap",
                                                              "cache", "config.php")
    with open(path, "rb") as stream:
        config = var_export.parse(stream.read())
    if command == "db:writer-policy-check":
        connection = config["database"]["connections"][config["database"]["default"]]
        if live_config.engine(config) != "mysql":
            report = {"engine": live_config.engine(config), "writer_policy": "not applicable", "problems": []}
        else:
            # The token check account may take the token; the app account, behind the closed gate, may not.
            acquired = connection.get("username") in settings.get("token_accounts", []) and bool(connection.get("password"))
            report = {"engine": "mysql", "writer_policy": True,
                      "session": {"isolation": "READ-COMMITTED", "row_lock_wait": 5, "metadata_lock_wait": 5},
                      "token": {"acquired": acquired}, "problems": [] if acquired else [
                          f"SQLSTATE[42000]: UPDATE command denied to user '{connection.get('username')}'@'localhost' "
                          "for table 'writer_token'"]}
        report = _changed(report, answer.get("changes", {}))
        return json.dumps(report), answer.get("code", 1 if report["problems"] else 0)
    if os.geteuid() == 0:
        # The command's own refusal, which the real one gives only after artisan bootstrapped as root (wave 1).
        return json.dumps({"version": 1, "mode": None, "ok": False, "problems": [
            "refused: cutover:smoke never runs as root (effective uid 0); run it as www-data or the deploy user"]}), 1
    flags = [argument[2:] for argument in arguments if argument in ("--engine-only", "--read-only", "--write")]
    umask = os.umask(0o022)
    os.umask(umask)
    process = {"euid": os.geteuid(), "user": user, "egid": os.getegid(), "group": grp.getgrgid(os.getegid()).gr_name,
               "cwd": os.getcwd(), "umask": f"{umask:04o}"}
    report = {"version": 1, "mode": flags[0] if len(flags) == 1 else None, "ok": True,
              **engine_report(config, path, settings, process)}
    if report["mode"] in ("read-only", "write"):
        smoke = settings.get("smoke", SMOKE_RECORDS)
        report["checks"] = {"client_id": smoke["client_id"], "shoot_id": smoke["shoot_id"], "client_read": True,
                            "shoot_read": True, "encrypted_value_decrypts": True, "smoke_queue": "database:cutover-smoke",
                            "smoke_queue_pending": 0, "smoke_queue_paused": False}
    changes = answer.get("changes", {})
    report = _changed(report, changes)
    if "ok" not in changes:
        report["ok"] = not report["problems"]
    return json.dumps(report), answer.get("code", 0 if report["ok"] else 1)


def php_context() -> dict:
    """The fake php's process, for step 11's tests: ids, groups, cwd, umask and environment (a password's value
    hashed); run on a private config cache (APP_CONFIG_CACHE), also that cache's folder and file (owner, group and
    mode), its account and its password's SHA-256."""
    umask = os.umask(0o022)
    os.umask(umask)
    context = {"uid": os.getuid(), "euid": os.geteuid(), "gid": os.getgid(), "egid": os.getegid(),
               "groups": sorted(os.getgroups()), "cwd": os.getcwd(), "umask": f"{umask:04o}",
               "env": {name: hashlib.sha256(value.encode()).hexdigest() if "PASSWORD" in name else value
                       for name, value in os.environ.items()}}
    cache = os.environ.get("APP_CONFIG_CACHE")
    if cache:
        def owner(path: str) -> list:
            info = os.stat(path)
            return [info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)]

        with open(cache, "rb") as stream:
            config = var_export.parse(stream.read())
        connection = config["database"]["connections"][config["database"]["default"]]
        context["cache"] = {"path": cache, "folder": owner(os.path.dirname(cache)), "file": owner(cache),
                            "username": connection.get("username"),
                            "password_sha256": hashlib.sha256(str(connection.get("password")).encode()).hexdigest()}
    return context


class FpmStandIn:
    """The app's PHP-FPM pool for fastcgi.engine_probe (design 5.3): a FastCGI responder on the host's fpm.socket that
    answers every request with fpm-engine.php's report for the live config.php, as the pool user www-data prints it.

    `settings`: `changes` (merged into the report as fake_artisan merges them) and `status` (the HTTP status; 200, or
    500 when the report is not ok). `stop()` closes the socket (FPM stopped), `start()` listens again. `requests`
    holds each request's FastCGI parameters."""

    def __init__(self, host: "PrepareHost"):
        self.host = host
        self.path = Path(host.root / "fpm" / "php-fpm.sock")
        self.settings: dict = {}
        self.requests: list[dict] = []
        self.listener = None
        self.thread = None
        self.stopped = threading.Event()
        self.start()
        host.test.addCleanup(self.stop)

    def start(self) -> None:
        self.stopped.clear()
        listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        listener.bind(str(self.path))
        listener.listen(8)
        listener.settimeout(0.1)
        self.listener = listener
        self.thread = threading.Thread(target=self.serve, args=(listener,), daemon=True)
        self.thread.start()

    def stop(self) -> None:
        self.stopped.set()
        if self.thread is not None:
            self.thread.join(15)
        if self.listener is not None:
            self.listener.close()
        self.listener = self.thread = None
        self.path.unlink(missing_ok=True)

    def serve(self, listener: socket.socket) -> None:
        while not self.stopped.is_set():
            try:
                connection, _ = listener.accept()
            except socket.timeout:
                continue
            except OSError:
                return
            with connection:
                connection.settimeout(10)
                try:
                    records = read_records(connection)
                    params = {name.decode(): value.decode() for name, value in
                              decode_pairs(b"".join(content for _, kind, _, content in records if kind == 4))}
                    self.requests.append(params)
                    connection.sendall(self.answer())
                except OSError:
                    continue

    def answer(self) -> bytes:
        settings = self.settings
        path = str(self.host.cache)
        with open(path, "rb") as stream:
            config = var_export.parse(stream.read())
        process = {"euid": WEB_UID, "user": WEB_USER, "egid": WEB_UID, "group": WEB_USER, "cwd": str(self.host.app),
                   "umask": "0022"}
        report = {"version": 1, "probe": "fpm-engine", "ok": True, "sapi": "fpm-fcgi", "pid": os.getpid(),
                  **engine_report(config, path, {"server_uuid": self.host.target}, process),
                  "opcache": dict(OPCACHE), "elapsed_ms": 4}
        report = _changed(report, settings.get("changes", {}))
        if "ok" not in settings.get("changes", {}):
            report["ok"] = not report["problems"]
        status = settings.get("status", 200 if report["ok"] else 500)
        body = json.dumps(report).encode()
        headers = f"Status: {status}\r\nContent-Type: application/json\r\nCache-Control: no-store\r\n\r\n".encode()
        return rec(6, headers + body) + rec(6) + end()


class Crash(Exception):
    """A controller that stopped between two prepare steps (killed, or the host rebooted)."""


# Where kill_inside stops a prepare inside a step: the temporary file whose rename it precedes or follows.
KILL_POINTS = {"before-env-rename": ".env.repro-", "after-config-rename": ".config.php.repro-"}


class PrepareHost:
    """One root-owned host for prepare and the actions around it (module docstring)."""

    def __init__(self, test: unittest.TestCase, *, fixture=None, mode: str = "drill", waiver: bool = False,
                 app_link: bool = False):
        self.test, self.fixture, self.mode, self.waiver = test, fixture, mode, waiver
        self.root = Path(tempfile.mkdtemp(prefix="repro-adapter-mysql-", dir="/var/lib"))
        test.addCleanup(shutil.rmtree, self.root, True)
        self.root.chmod(0o755)
        self.tag = uuid.uuid4().hex[:12]
        for folder in ("state", "run", "ingress", "etc", "home", "database", "bin", "night", "log", "fpm"):
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
        if app_link:
            # host.json's app_root reached through a symbolic link: the identity probe, live_config and the build
            # follow it, the .env switch does not (review D10R-6).
            link = self.root / "app-link"
            link.symlink_to(self.app)
            self.app, self.env, self.cache = link, link / ".env", link / "bootstrap" / "cache" / "config.php"
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
        self.write_wrappers(self.profiles())
        # D11: FPM's pool for the FastCGI probe; a scripted host's gate.check (the fixture runs the real one).
        self.fpm = FpmStandIn(self)
        self.gate_answer = None
        self.gate_check = None
        if not fixture:
            patcher = patch.object(gate, "check", side_effect=self._gate_check)
            self.gate_check = patcher.start()
            test.addCleanup(patcher.stop)
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
        """What the fake php does on its next runs (config_cache: ok, half-then-fail, other-route; answers, see
        fake_artisan; server_uuid, smoke and token_accounts default to this host's)."""
        path = self.app / "fake-php.json"
        defaults = {"server_uuid": self.target, "smoke": SMOKE_RECORDS, "token_accounts": [TOKEN_USER]}
        path.write_text(json.dumps({"log": str(self.log), **defaults, **settings}), encoding="utf-8")
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
            self._program("mysql-probe", FAKE_PROBE, answers=str(self.answers), count=str(self.etc / "since-count"),
                          receipt=str(self.root / "state" / Path(example_config()["paths"]["receipt"]).name))
            self.answer(probe_reading(server_uuid=self.target))
        for name in ("stop", "start", "fpm-stop", "smoke-write", "telnyx-replay", "timer"):
            self._program(name, RECORD)

    def answer(self, stdout, code: int = 0, *, arm: bool = True) -> None:
        """The scripted MySQL probe's reading (no fixture): what it prints and exits with without --since. A reading
        of a reachable server whose `receipt` is null names this run's receipt once it exists, unless `arm` is
        false (a probe that did not arm on it)."""
        answers = json.loads(self.answers.read_text()) if self.answers.exists() else {"since": []}
        answers["observe"] = {"code": code, "stdout": stdout, "arm": arm}
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
        config["fpm"].update(drain_seconds=5, socket=str(root / "fpm" / "php-fpm.sock"),
                             status_socket=str(root / "fpm" / "php-fpm-status.sock"))
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
        else:
            # The token check's defaults file (design 3.3; step 11 reads its account and password).
            (etc / "mysql-token-check.cnf").write_text(f"[client]\nuser={TOKEN_USER}\npassword={FAKE_TOKEN_PASSWORD}\n",
                                                       encoding="ascii")
            (etc / "mysql-token-check.cnf").chmod(0o600)
        self.token_password = re.search(r"^password=(.*)$", (etc / "mysql-token-check.cnf").read_text(),
                                        re.MULTILINE).group(1)
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

    def crash_after(self, number: int) -> None:
        """runner.perform("prepare") stopped between step `number` and the next one (Crash raised in its place)."""
        steps = dict(production.PREPARE_STEPS)
        with patch.object(production.ProductionAdapter, f"_prepare_{steps[number + 1]}",
                          side_effect=Crash(f"stopped after step {number}")):
            with self.test.assertRaises(Crash):
                self.runner().perform("prepare")

    def kill_inside(self, point: str) -> None:
        """runner.perform("prepare") in a child process that SIGKILLs itself inside a step, as a crash or a reboot
        stops the controller (README "What a crash leaves"): "before-env-rename" in step 7, the switched temporary
        .env written with the old owner, mode and ACL and not yet renamed; "after-config-rename" in step 8,
        config.php renamed to the MySQL build and switch_at not yet recorded."""
        prefix = KILL_POINTS[point]
        pid = os.fork()
        if pid == 0:  # The child never returns into the test runner.
            try:
                rename = os.replace

                def replace(source, destination, *arguments, **keywords):
                    named = os.path.basename(os.fspath(source)).startswith(prefix)
                    if named and point.startswith("before-"):
                        os.kill(os.getpid(), signal.SIGKILL)
                    result = rename(source, destination, *arguments, **keywords)
                    if named:
                        os.kill(os.getpid(), signal.SIGKILL)
                    return result

                os.replace = replace
                self.runner().perform("prepare")
            finally:
                os._exit(1)
        _, status = os.waitpid(pid, 0)
        self.test.assertEqual(signal.SIGKILL, os.WTERMSIG(status) if os.WIFSIGNALED(status) else None,
                              f"prepare was not killed at {point}")

    def leftovers(self) -> list[str]:
        """The temporary files an interrupted .env switch or config build left."""
        return sorted([entry.name for entry in self.app.iterdir() if entry.name.startswith(".env.repro-")]
                      + [entry.name for entry in self.cache.parent.iterdir() if entry.name != "config.php"])

    def assert_prepared(self, error) -> None:
        """Steps 1 to 12 passed, the runner observed a frozen route on MySQL, evidence() held and the run is `ready`
        with the record of this run's evidence report (D11)."""
        self.test.assertIsNone(error, f"prepare refused: {error}")
        journal, adapter, _ = self.host()
        state = journal.read()
        self.test.assertEqual(("ready", None, False), (state["phase"], state["pending"], state["writes_possible"]))
        self.test.assertEqual(hashlib.sha256(self.path("evidence_report").read_bytes()).hexdigest(),
                              state["evidence"]["report_sha256"])
        observed = adapter.observe()
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
        the FPM restart from 9 (`restarts` restarts in all), the receipt from 10, the evidence report from 12 (step 11
        leaves nothing: its token-check folder is removed). `after=10` still means "the switch finished": the report
        is checked only from 12, so a full prepare passes both."""
        if after >= 12:
            self.test.assertIsNotNone(self.record("evidence_report"), f"evidence report after step {after}")
        self.test.assertEqual([], self.token_folders(), f"token-check folders after step {after}")
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

    # D11: what step 11 runs against, and what it leaves.

    def profiles(self) -> dict:
        """wrappers.json's profiles (design 3.8) in this host's app root: five distinct contexts (user, group, cwd,
        umask, environment), two shell scripts among them, one profile with its own environment."""
        php, app = str(self.php_path), str(self.app)
        artisan = f"{app}/artisan"

        def profile(user, umask, argv, *, action="queue-worker", env=None, timeout=None) -> dict:
            return {"action": action, "engine": "sqlite", "user": user, "group": WEB_USER, "cwd": app, "umask": umask,
                    "env": env or {}, "timeout_seconds": timeout, "argv": argv}

        return {
            "laravel-worker": profile(WEB_USER, "0007", [php, artisan, "queue:work", "database", "--queue=mail,default"]),
            "media-archives": profile(WEB_USER, "0007", [php, artisan, "queue:work", "database",
                                                         "--queue=media-archives"]),
            "studio": profile(DEPLOY_USER, "0002", [f"{app}/worker.sh"]),
            "voice-realtime": profile(DEPLOY_USER, "0002", [php, artisan, "queue:work", "database",
                                                            "--queue=voice-realtime"]),
            "telnyx-replay": profile(WEB_USER, "0002", [php, artisan, "telnyx:replay-spool"], timeout=600),
            "cutover-smoke": profile(WEB_USER, "0022", [php, artisan, "cutover:smoke", "--write", "--json"], timeout=300),
            "scheduler": profile(DEPLOY_USER, "0002", [php, artisan, "schedule:run"], action="scheduler", timeout=3300),
            "auth-prune": profile(DEPLOY_USER, "0002", [php, artisan, "auth:prune-security-limits", "--limit=1000"],
                                  action="scheduler", env={"REPRO_PROFILE_MARK": "auth-prune"}, timeout=900),
            "cubicasa-poll": profile(DEPLOY_USER, "0002", ["/bin/sh", "-c", f"exec {php} artisan cubicasa:refresh-deliveries"],
                                     action="scheduler", timeout=1500),
        }

    def write_wrappers(self, profiles: dict) -> None:
        """paths.wrappers (root 0644 in the root-only state folder): the live wrapper profiles step 11 probes."""
        path = self.path("wrappers")
        path.write_text(json.dumps({"version": 1, "profiles": profiles}, sort_keys=True), encoding="utf-8")
        path.chmod(0o644)

    def _gate_check(self, config: dict) -> tuple:
        """gate.check on a scripted host: the closed gate and the gate account as accounts.sql makes it, unless
        `gate(...)` set another answer."""
        output = {"version": 1, "reachable": True, "gate": "closed", "open_privileges": {"repro_app@localhost": []},
                  "sessions": [], "root_sql": None,
                  "gate_account": {"current_user": "repro_cutover_gate@localhost", "problems": []}}
        answer = self.gate_answer or {}
        return {**output, **answer.get("changes", {})}, answer.get("code", 0), answer.get("message")

    def gate(self, code: int = 0, message: str | None = None, **changes) -> None:
        """What gate.check answers next on a scripted host (gate() with nothing: the default)."""
        self.gate_answer = {"code": code, "message": message, "changes": changes}

    def php_runs(self, command: str, *flags: str) -> list:
        """The fake php's recorded runs of a D11 artisan command with these flags: [*arguments, context]."""
        return [entry[2:] for entry in self.commands()
                if entry[:2] == ["php", command] and all(flag in entry[2:-1] for flag in flags)]

    def token_folders(self) -> list:
        parent = getattr(production, "TOKEN_CHECK_PARENT", Path("/run"))
        prefix = getattr(production, "TOKEN_CHECK_PREFIX", "repro-token-check-")
        return sorted(entry.name for entry in Path(parent).iterdir() if entry.name.startswith(prefix))

    def report(self) -> dict:
        """paths.evidence_report, the evidence report step 12 wrote."""
        return json.loads(self.path("evidence_report").read_text(encoding="utf-8"))

    def write_night(self, change=None, *, folder: str = "night-files") -> Path:
        """The night's input directory check-evidence reads (adapter.NIGHT_FILES, adapter.BACKUP_COORDINATE): the six
        tool outputs of documents() and the backup coordinate ({file, position, gtid_executed}); `change(documents,
        coordinate)` first. A document that is None is not written."""
        documents, coordinate = self.documents(), dict(self.backup)
        if change is not None:
            change(documents, coordinate)
        night = self.root / folder
        night.mkdir(mode=0o700, exist_ok=True)
        files = {**production.NIGHT_FILES, "backup": production.BACKUP_COORDINATE}
        for name, filename in files.items():
            document = coordinate if name == "backup" else documents[name]
            path = night / filename
            path.unlink(missing_ok=True)
            if document is None:
                continue
            if isinstance(document, (str, bytes)):
                raw = document.encode("utf-8") if isinstance(document, str) else document
            elif isinstance(document, list):
                raw = "".join(json.dumps(line) + "\n" for line in document).encode("utf-8")
            else:
                raw = (json.dumps(document) + "\n").encode("utf-8")
            path.write_bytes(raw)
            path.chmod(0o600)
        return night

    def tree(self) -> dict:
        """Every file under the host's state, runtime, config, app and database folders (bytes, owner, mode), the
        journal included, for "nothing else changed" checks; the command log is left out."""
        found = {}
        for folder in ("state", "run", "ingress", "etc", "app", "database"):
            for path in sorted((self.root / folder).rglob("*")):
                info = path.lstat()
                content = path.read_bytes() if stat.S_ISREG(info.st_mode) else None
                found[str(path.relative_to(self.root))] = (content, info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))
        return found


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
                # The order of the switch's host effects: config:cache (through runuser, as the deploy user), then the
                # FPM restart; step 11's smokes and token check follow (EvidenceTests).
                self.assertEqual([["runuser", "-u", DEPLOY_USER], ["php", "config:cache"], ["systemctl", "restart"]],
                                 [entry[:3] if entry[0] == "runuser" else entry[:2] for entry in host.effects()][:3])

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
            "an import whose source changed": (lambda d, m: d["import"].update(source_changed=True),
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
            # Digits in the right places, but no such day (review D10R-4): a refusal, never a ValueError.
            "a freeze on a day that does not exist": (lambda d, m: d["backup_check"].update(
                frozen_at="2026-02-30T10:00:00Z"), "final backup check is malformed"),
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
            "a snapshot record of two files": (lambda d, m: d.update(
                snapshot=f"{FROZEN_SNAPSHOT}  /protected/frozen.sqlite\n{COPY_SNAPSHOT}  /protected/copy.sqlite\n"),
                                               "frozen snapshot record is not one sha256sum line"),
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
        # An input that is a symbolic link, a FIFO or larger than 1 MiB, each with the digest check-evidence would
        # have recorded for its content (review D10R-3): each refused for what it is.
        night = host.root / "night"

        def linked(path: Path) -> None:
            path.rename(night / "import.real")
            path.symlink_to(night / "import.real")

        def fifo(path: Path) -> None:
            path.unlink()
            os.mkfifo(path, 0o600)

        def plain_inputs() -> None:
            # Before each case, even after a failed one: writing into a FIFO left behind would block.
            for leftover in (night / "import", night / "import.real"):
                leftover.unlink(missing_ok=True)

        for name, change, made, pattern in (
            ("a symbolic link", None, linked, "import result cannot be read"),
            ("a FIFO", None, fifo, "import result is not a regular file"),
            ("larger than 1 MiB", lambda d, m: d["import"].update(padding="x" * production.MAX_INPUT_BYTES), None,
             f"import result is larger than {production.MAX_INPUT_BYTES} bytes"),
        ):
            with self.subTest(input=name):
                plain_inputs()
                host.write_inputs(change)
                if made is not None:
                    made(night / "import")
                host.refused(pattern)
        plain_inputs()
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
        # The adapter's prepare, not the runner's: the run stays frozen, so a retry below can run (a prepare the runner
        # finished is `ready`, D11).
        host.adapter_prepare()
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
        host.adapter_prepare()  # the run stays frozen for the retry below (D11: the runner's prepare ends ready)
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
        self.assertEqual("ready", host.runner().perform("tick")["phase"])
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
        # ... and a MySQL config with another route hash (another collation, say) is refused as well: the measure
        # compares the pin, not only the engine and the source (review D10R-3).
        other = self.frozen()

        def build_another_route(*arguments, **keywords):
            result = build_and_replace(*arguments, **keywords)
            switch = {**other.config["mysql"]["switch"], "DB_PASSWORD": other.password}
            other.cache.write_bytes(cache_text(config_for({**switch, "DB_COLLATION": "utf8mb4_general_ci"})))
            return result

        with patch.object(config_build, "build", side_effect=build_another_route):
            self.assertRegex(str(other.prepare()), "live config after the build is not the MySQL pin")
        measured = live_config.measure(other.app)
        self.assertEqual(("mysql", live_config.CACHE), (measured.engine, measured.source))
        self.assertNotEqual(other.mysql_pin, measured.live_config_sha256)
        self.assertEqual([], [entry for entry in other.effects() if entry[0] == "systemctl"])
        self.assertIsNone(other.record("switch_record")["switch_at"])
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
        last = len(steps)
        self.assertEqual(list(range(1, 13)), sorted(steps))
        for number in range(1, last + 1):
            with self.subTest(crash_after=steps[number]):
                host = self.frozen()
                if number < last:
                    host.crash_after(number)
                else:
                    host.adapter_prepare()  # all steps; the run stays frozen for the second run (D11)
                host.assert_markers(after=number)
                # A second run from that state finishes the switch, whatever was done before; the runner's next
                # action still finds the pinned identity (the .env digest did not move).
                host.adapter_prepare()
                host.assert_markers(after=last, restarts=2 if number >= 9 else 1)
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
            # Exit 0 is not enough: the reading must say it reached the server (review D10R-3).
            ("an exit-0 reading of no server", ({**reading, "reachable": False}, 0),
             "no reading of a reachable server"),
        ):
            with self.subTest(name):
                host.answer(*answer)
                host.refused(pattern)
        host.answer(reading)
        host.adapter_prepare()
        host.assert_markers(after=10)

    def test_app_sessions_are_ended_only_on_a_gate_that_was_closed(self):
        # Step 4 ends app sessions with gate.close_gate. If that close found the gate open or partial (a GRANT
        # raced the reading), it closed it, and prepare stops rather than trust a reading from before (review
        # D10R-3: the fixture test only covers the closed gate).
        import gate

        host = self.frozen()
        host.answer({**probe_reading(server_uuid=host.target), "gated_sessions": 2})
        for before in ("open", "partial"):
            with self.subTest(before=before), patch.object(gate, "close_gate", return_value={
                    "before": before, "revoked": {"repro_app@localhost": ["INSERT"]}, "killed": [41, 42]}) as close:
                host.refused("was not closed when its app sessions were ended")
                close.assert_called_once()
        with patch.object(gate, "close_gate", return_value={"before": "closed", "revoked": {}, "killed": [41, 42]}) \
                as close:
            host.adapter_prepare()
        close.assert_called_once()
        host.assert_markers(after=10)

    def test_prepare_needs_its_lease_current_and_the_writers_drained_before_each_step(self):
        host = self.frozen()
        journal, adapter, _ = host.host()
        before = host.changes()
        # A lease that never drained the admitted writers: step 1 refuses (review D10R-3).
        with journal.acquire() as (state, lease):
            journal.write({**state, "generation": lease.generation})
            with self.assertRaisesRegex(UnsafeCutover, "Admitted writers have not been drained"):
                adapter.prepare(lease, time.time() + 600)
        self.assertEqual(before, host.changes())
        # Another controller took the journal while step 2 ran: the lease is checked again before step 3, and
        # nothing from step 3 on runs.
        inputs = production.ProductionAdapter._prepare_inputs

        def inputs_then_taken_over(adapter_self, run):
            inputs(adapter_self, run)
            current = journal.read()
            journal.write({**current, "generation": current["generation"] + 1})

        with patch.object(production.ProductionAdapter, "_prepare_inputs", inputs_then_taken_over):
            with self.assertRaisesRegex(UnsafeCutover, "no longer owns the journal generation"):
                host.adapter_prepare()
        self.assertEqual(before, host.changes())
        host.adapter_prepare()
        host.assert_markers(after=10)

    def test_the_env_is_checked_before_the_switch_record_is_written(self):
        # Review D10R-6: what the .env switch (steps 6 and 7) would refuse is refused at step 5, before the switch
        # record is written, so "refused before any write" holds for the .env's own shape.
        with self.subTest("an app root reached through a symbolic link"):
            linked = PrepareHost(self, app_link=True)
            linked.freeze()
            linked.refused("folder of the .env cannot be used", scans=True)
        for name, change, pattern in (
            ("a hard-linked .env", lambda host: os.link(host.env, host.app / "env-hard-link"), "hard link"),
            ("a key to append and no final newline",
             lambda host: host.env.write_bytes(host.env.read_bytes().rstrip(b"\n")), "must end with a newline"),
        ):
            with self.subTest(name):
                host = self.frozen()
                change(host)
                host.refused(pattern, scans=True)

    def test_a_kill_inside_a_step_leaves_a_state_the_next_prepare_finishes(self):
        # Review D10R-5: the two in-step states README "What a crash leaves" lists (D12's abort tests start from
        # them too). Inside step 7, before the rename: the switched temporary .env, with .env's owner, mode and ACL.
        host = self.frozen()
        original, before = host.env.read_bytes(), metadata(host.env)
        host.kill_inside("before-env-rename")
        [leftover] = [name for name in host.leftovers() if name.startswith(".env.repro-")]
        switch = {**host.config["mysql"]["switch"], "DB_PASSWORD": host.password}
        self.assertEqual(envfile.assign(original, switch), (host.app / leftover).read_bytes())
        self.assertEqual(before[1:], metadata(host.app / leftover)[1:])
        self.assertEqual((original, before), (host.env.read_bytes(), metadata(host.env)))
        host.assert_markers(after=6)
        host.adapter_prepare()
        host.assert_markers(after=10)
        self.assertEqual([], host.leftovers())
        # Inside step 8, after config.php's rename: the MySQL pin is live, switch_at not yet recorded.
        host = self.frozen()
        host.kill_inside("after-config-rename")
        measured = live_config.measure(host.app)
        self.assertEqual(("mysql", live_config.CACHE, host.mysql_pin),
                         (measured.engine, measured.source, measured.live_config_sha256))
        self.assertTrue(host.switched())
        self.assertIsNone(host.record("switch_record")["switch_at"])
        self.assertEqual([], [entry for entry in host.effects() if entry[0] == "systemctl"])
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


def set_commands(host: PrepareHost, **commands) -> None:
    """Change host.json's commands on disk only (rewrite_config would measure the pins again from a switched .env)."""
    config = json.loads(host.config_path.read_text(encoding="utf-8"))
    config["commands"].update(commands)
    host.config_path.write_text(json.dumps(config), encoding="utf-8")


@unittest.skipUnless(LINUX_ROOT, "Needs Linux root: owners, ACLs, runuser and the guard's protected files")
class EvidenceTests(unittest.TestCase):
    """prepare steps 11 and 12, evidence() and check-evidence --write (design 3.2 steps 11 and 12, 3.3, 5.3, 7.1;
    build plan D11), with the scripted MySQL probe and gate.check."""

    def frozen(self, **options) -> PrepareHost:
        host = PrepareHost(self, **options)
        host.freeze()
        return host

    def prepared(self, **options) -> PrepareHost:
        host = self.frozen(**options)
        host.assert_prepared(host.prepare())
        return host

    def refused_at_checks(self, host: PrepareHost, pattern: str) -> UnsafeCutover:
        """adapter.prepare again (steps 1 to 10 keep the switch) is refused with `pattern` in step 11: no report is
        written and no token-check folder is left."""
        host.path("evidence_report").unlink(missing_ok=True)
        with self.assertRaisesRegex(UnsafeCutover, pattern) as refused:
            host.adapter_prepare()
        self.assertIsNone(host.record("evidence_report"))
        self.assertEqual([], host.token_folders())
        return refused.exception

    def test_profile_probes_follow_wrapper_contexts(self):
        import admit
        import grp
        import pwd

        host = self.prepared()
        raw = host.path("wrappers").read_bytes()
        profiles = json.loads(raw)["profiles"]
        probes = host.report()["checks"]["profile_probes"]
        runs = host.php_runs("cutover:smoke", "--engine-only")
        # One probe per distinct context (user, group, cwd, umask, environment, php and artisan), every profile named
        # once: nine profiles, five contexts, five probes.
        self.assertEqual(sorted(profiles), sorted(name for probe in probes for name in probe["profiles"]))
        self.assertEqual(5, len(probes))
        self.assertEqual([["--engine-only", "--json"]] * 5, [run[:-1] for run in runs])
        for probe in probes:
            with self.subTest(probe["profiles"]):
                profile = admit.load_profile(probe["profiles"][0], raw)
                account = pwd.getpwnam(profile["user"])
                group = grp.getgrnam(profile["group"]).gr_gid
                # Exactly as admit.py starts that profile's writer: user, group and supplementary groups, cwd, umask
                # and environment, with the controller's child tag.
                expected = {"uid": account.pw_uid, "euid": account.pw_uid, "gid": group, "egid": group,
                            "groups": sorted(os.getgrouplist(account.pw_name, group)), "cwd": profile["cwd"],
                            "umask": profile["umask"],
                            "env": {**admit.environment(profile, account), production.CHILD_TAG: "1"}}
                self.assertIn(expected, [run[-1] for run in runs])
                self.assertEqual({"user": profile["user"], "group": profile["group"], "cwd": profile["cwd"],
                                  "umask": profile["umask"], "env": sorted(profile["env"]), "engine": "mysql",
                                  "database": "repro_dashboard", "server_uuid": host.target,
                                  "route_sha256": host.mysql_pin, "ok": True},
                                 {key: probe[key] for key in ("user", "group", "cwd", "umask", "env", "engine",
                                                              "database", "server_uuid", "route_sha256", "ok")})
        # The shell-script profiles are probed for their user and cwd with the app's php and artisan, which is the
        # context of the artisan profiles they share it with.
        shared = next(probe for probe in probes if "studio" in probe["profiles"])
        self.assertEqual((["cubicasa-poll", "scheduler", "studio", "voice-realtime"], ["cubicasa-poll", "studio"]),
                         (shared["profiles"], shared["scripts"]))
        self.assertEqual([], [name for probe in probes if probe is not shared for name in probe["scripts"]])
        # A profile in another folder is probed there.
        elsewhere = self.frozen()
        other = elsewhere.root / "elsewhere"
        other.mkdir(mode=0o755)
        shutil.copyfile(elsewhere.app / "fake-php.json", other / "fake-php.json")
        (other / "fake-php.json").chmod(0o644)
        moved = elsewhere.profiles()
        moved["telnyx-replay"]["cwd"] = str(other)
        elsewhere.write_wrappers(moved)
        elsewhere.adapter_prepare()
        probe = next(probe for probe in elsewhere.report()["checks"]["profile_probes"]
                     if "telnyx-replay" in probe["profiles"])
        self.assertEqual((["telnyx-replay"], str(other)), (probe["profiles"], probe["cwd"]))
        self.assertEqual(1, sum(run[-1]["cwd"] == str(other) for run in elsewhere.php_runs("cutover:smoke",
                                                                                            "--engine-only")))
        # A context whose processes would still read SQLite, or report another context, is refused by name.
        stale = self.frozen()
        line = "cutover:smoke --engine-only --json"
        for name, answers, pattern in (
            ("SQLite for the deploy user", {f"{line}@{DEPLOY_USER}": {"changes": {"engine": "sqlite"}}},
             r"auth-prune .*engine sqlite"),
            ("another route", {line: {"changes": {"route_values": {"database.default": "sqlite"}}}}, "route hash"),
            ("another umask", {f"{line}@{WEB_USER}": {"changes": {"process": {"umask": "0077"}}}}, "umask 0077"),
            ("its own problems", {line: {"changes": {"problems": ["database: SQLSTATE[HY000] [2002] No such file"]}}},
             r"SQLSTATE\[HY000\] \[2002\]"),
        ):
            with self.subTest(name):
                stale.php(answers=answers)
                self.refused_at_checks(stale, pattern)
        stale.php()
        stale.adapter_prepare()

    def test_fastcgi_probe_recorded(self):
        host = self.prepared()
        self.assertEqual({"socket": host.config["fpm"]["socket"], "engine": "mysql", "database": "repro_dashboard",
                          "server_uuid": host.target, "route_sha256": host.mysql_pin, "sapi": "fpm-fcgi",
                          "pid": os.getpid(), "opcache": OPCACHE, "ok": True}, host.report()["checks"]["fastcgi"])
        # fpm-engine.php straight into the pool's own socket (design 5.3).
        self.assertEqual(f"{host.app}/scripts/cutover/probe/fpm-engine.php", host.fpm.requests[-1]["SCRIPT_FILENAME"])
        fresh = self.frozen()
        for name, settings, pattern in (
            ("an FPM still on the SQLite config", {"changes": {"engine": "sqlite"}}, "FastCGI.*engine sqlite"),
            ("another route", {"changes": {"route_values": {"database.default": "sqlite"}}}, "FastCGI.*route hash"),
            ("another server", {"changes": {"server_uuid": OTHER_UUID}}, "FastCGI.*server"),
            ("its own problems", {"changes": {"problems": ["boot: the services manifest is missing"]}},
             "services manifest is missing"),
            ("an answer that is not its report", {"changes": {"probe": "other"}}, "FastCGI"),
        ):
            with self.subTest(name):
                fresh.fpm.settings = settings
                self.refused_at_checks(fresh, pattern)
        fresh.fpm.settings = {}
        # FPM started in step 9 may not listen yet: the probe waits for its socket, within FPM_SOCKET_SECONDS.
        fresh.fpm.stop()
        with patch.object(production, "FPM_SOCKET_SECONDS", 0.5):
            self.refused_at_checks(fresh, "FPM socket .*not listening")
        timer = threading.Timer(1.5, fresh.fpm.start)
        self.addCleanup(timer.cancel)
        with patch.object(production, "FPM_SOCKET_SECONDS", 30), \
                patch.object(production.ProductionAdapter, "_prepare_fpm", lambda adapter, run: timer.start()):
            fresh.adapter_prepare()
        self.assertEqual("mysql", fresh.report()["checks"]["fastcgi"]["engine"])

    def test_read_only_smoke_as_both_users(self):
        host = self.prepared()
        expected = {"engine": "mysql", "database": "repro_dashboard", "server_uuid": host.target,
                    "route_sha256": host.mysql_pin, "client_id": SMOKE_RECORDS["client_id"],
                    "shoot_id": SMOKE_RECORDS["shoot_id"], "client_read": True, "shoot_read": True,
                    "encrypted_value_decrypts": True, "smoke_queue_pending": 0, "smoke_queue_paused": False, "ok": True}
        self.assertEqual([{"user": WEB_USER, "group": WEB_USER, **expected},
                          {"user": DEPLOY_USER, "group": WEB_USER, **expected}],
                         host.report()["checks"]["readonly_smoke"])
        # smoke_readonly's two commands, each through runuser as its user (design 3.1).
        runs = host.php_runs("cutover:smoke", "--read-only")
        self.assertEqual([(WEB_UID, WEB_UID, "0022"), (host.deploy, WEB_UID, "0002")],
                         [(run[-1]["euid"], run[-1]["egid"], run[-1]["umask"]) for run in runs])
        self.assertEqual([["-u", WEB_USER, "--"], ["-u", DEPLOY_USER, "-g"]],
                         [entry[1:4] for entry in host.commands() if entry[0] == "runuser" and "--read-only" in entry[-2]])
        fresh = self.frozen()
        line = "cutover:smoke --read-only --json"
        for name, answers, pattern in (
            ("the test client unread", {line: {"changes": {"checks": {"client_read": False}}}}, "client_read"),
            ("a value that does not decrypt", {line: {"changes": {"checks": {"encrypted_value_decrypts": False}}}},
             "encrypted_value_decrypts"),
            ("smoke jobs pending", {line: {"changes": {"checks": {"smoke_queue_pending": 2}}}}, "2 jobs .*cutover-smoke"),
            ("another test client", {line: {"changes": {"checks": {"client_id": 7}}}}, "marked test client"),
            ("SQLite", {line: {"changes": {"engine": "sqlite"}}}, "engine sqlite"),
            ("its own problems", {line: {"changes": {"problems": ["the test shoot is missing"]}}},
             "test shoot is missing"),
            ("the deploy user's smoke from another user", {f"{line}@{DEPLOY_USER}": {"changes": {"process": {
                "user": WEB_USER}}}}, f"ran as {WEB_USER}"),
            ("the deploy user's smoke in another group than -g", {f"{line}@{DEPLOY_USER}": {"changes": {"process": {
                "group": "nogroup"}}}}, "ran in group nogroup"),
        ):
            with self.subTest(name):
                fresh.php(answers=answers)
                self.refused_at_checks(fresh, pattern)
        fresh.php()
        # smoke_readonly itself: one command for each of the two users, through runuser, read-only; checked before
        # step 11 runs anything.
        web, deploy = fresh.config["commands"]["smoke_readonly"]
        php, artisan, runuser = str(fresh.php_path), f"{fresh.app}/artisan", str(fresh.root / "bin" / "runuser")
        for name, commands, pattern in (
            ("only the web user", [web], f"once as {WEB_USER} and once as {DEPLOY_USER}"),
            ("the web user twice", [web, web], f"once as {WEB_USER} and once as {DEPLOY_USER}"),
            ("php with no runuser", [[php, artisan, "cutover:smoke", "--read-only", "--json"], deploy], "runuser"),
            ("runuser as root", [[runuser, "-u", "root", "--", php, artisan, "cutover:smoke", "--read-only", "--json"],
                                 deploy], "runuser"),
            ("the write smoke", [[*web[:-2], web[-2].replace("--read-only", "--write"), web[-1]], deploy], "read-only"),
        ):
            with self.subTest(name):
                set_commands(fresh, smoke_readonly=commands)
                ran = len(fresh.php_runs("cutover:smoke"))
                self.refused_at_checks(fresh, pattern)
                self.assertEqual(ran, len(fresh.php_runs("cutover:smoke")))
        set_commands(fresh, smoke_readonly=[web, deploy])
        fresh.adapter_prepare()

    def test_token_check_uses_private_cache_and_cleans_up(self):
        host = self.prepared()
        self.assertEqual({"account": f"{TOKEN_USER}@localhost", "user": WEB_USER, "engine": "mysql",
                          "writer_policy": True, "token_acquired": True, "problems": [], "ok": True},
                         host.report()["checks"]["token_check"])
        # Step 8's build, then the token check's: as the web user, umask 0077, into a private folder under /run.
        builds = [entry for entry in host.commands() if entry[:2] == ["php", "config:cache"]]
        self.assertEqual(2, len(builds))
        cache = Path(builds[1][2])
        self.assertEqual((Path(production.TOKEN_CHECK_PARENT), True, "config.php"),
                         (cache.parent.parent, cache.parent.name.startswith(production.TOKEN_CHECK_PREFIX), cache.name))
        self.assertEqual([WEB_UID, WEB_UID, 0o077], builds[1][3:])
        [check] = host.php_runs("db:writer-policy-check")
        context = check[-1]
        self.assertEqual((WEB_UID, str(cache)), (context["euid"], context["env"]["APP_CONFIG_CACHE"]))
        # The cache names the check account with its password (the switched .env names the app account), in a 0700
        # folder of the web user; the policy check itself gets no account or password in its environment.
        self.assertEqual({"path": str(cache), "folder": [WEB_UID, WEB_UID, 0o700], "file": [WEB_UID, WEB_UID, 0o600],
                          "username": TOKEN_USER,
                          "password_sha256": hashlib.sha256(host.token_password.encode()).hexdigest()}, context["cache"])
        self.assertFalse({"DB_USERNAME", "DB_PASSWORD"} & set(context["env"]))
        runs = [entry for entry in host.commands() if entry[0] == "runuser" and entry[-1] == str(cache)]
        self.assertEqual([["-u", WEB_USER, "--", "/bin/sh", "-c"]] * 2, [entry[1:6] for entry in runs])
        self.assertEqual(["config:cache", "db:writer-policy-check"], [entry[6].split()[-1] for entry in runs])
        # The password is never in a command line, the report or a record; the folder is gone.
        for path in (host.log, host.path("evidence_report"), host.path("state") / "state.json"):
            self.assertNotIn(host.token_password, path.read_text(encoding="utf-8"), path)
        self.assertFalse(cache.parent.exists())
        self.assertEqual([], host.token_folders())
        # A failed check removes its folder too, and a folder a crash left is removed by the next check.
        fresh = self.frozen()
        leftover = Path(tempfile.mkdtemp(prefix=production.TOKEN_CHECK_PREFIX, dir=production.TOKEN_CHECK_PARENT))
        self.addCleanup(shutil.rmtree, leftover, True)
        (leftover / "config.php").write_text("<?php return array (\n);\n", encoding="ascii")
        for name, answers, pattern in (
            ("no token (the app account's grants)", {"db:writer-policy-check": {"changes": {"token": {
                "acquired": False}}}}, "did not take the writer token"),
            ("a problem", {"db:writer-policy-check": {"changes": {"problems": [
                "The writer policy is off on the default MySQL connection."]}}}, "writer policy is off"),
        ):
            with self.subTest(name):
                fresh.php(answers=answers)
                self.refused_at_checks(fresh, pattern)
                self.assertFalse(leftover.exists())
        with self.subTest("a cache built without the check account"):
            fresh.php(config_cache="ignore-env")
            self.refused_at_checks(fresh, "does not name the check account")
        fresh.php()
        # The check account and its password come from mysql.token_check_defaults, root-only, exactly
        # repro_token_check; anything else is refused before step 11 runs anything, the password never quoted.
        cnf = fresh.etc / "mysql-token-check.cnf"
        good = cnf.read_bytes()
        for name, content, mode, pattern in (
            ("another account", good.replace(b"user=repro_token_check", b"user=repro_app"), 0o600, TOKEN_USER),
            ("readable by others", good, 0o644, "root only"),
            ("no password", b"[client]\nuser=repro_token_check\n", 0o600, "password"),
        ):
            with self.subTest(name):
                cnf.write_bytes(content)
                cnf.chmod(mode)
                ran = len(fresh.php_runs("cutover:smoke"))
                error = self.refused_at_checks(fresh, pattern)
                self.assertNotIn(fresh.token_password, str(error))
                self.assertEqual(ran, len(fresh.php_runs("cutover:smoke")))
        cnf.write_bytes(good)
        cnf.chmod(0o600)
        fresh.adapter_prepare()

    def test_evidence_is_pure_and_equal_across_calls(self):
        import evidence

        host = self.prepared()
        journal, adapter, runner = host.host()
        state = journal.read()
        before = host.tree()
        first, second = adapter.evidence(), adapter.evidence()
        self.assertEqual(first, second)
        # The record prepare journalled, the one promote compares, and another controller's adapter reads the same.
        self.assertEqual(state["evidence"], asdict(first))
        self.assertEqual(first, host.host()[1].evidence())
        self.assertEqual(before, host.tree())
        receipt = host.record("receipt")
        self.assertEqual((COPY_SNAPSHOT, hashlib.sha256(host.path("evidence_report").read_bytes()).hexdigest(),
                          receipt["backup"]["file"], receipt["backup"]["position"], True, True, True, True, True),
                         tuple(asdict(first).values()))
        # It reads again what it binds: each refusal changes nothing, and the record is back with the file.
        report_path, receipt_path = host.path("evidence_report"), host.path("receipt")
        report = host.report()
        for name, path, content, pattern in (
            ("an input changed after prepare", host.root / "night" / "import",
             (host.root / "night" / "import").read_bytes() + b"\n", "changed after check-evidence recorded it"),
            # Refused by the report's own binding, before the probe (which would read another receipt digest too).
            ("the receipt written again", receipt_path, json.dumps({**receipt, "armed_from": {
                **receipt["armed_from"], "position": receipt["armed_from"]["position"] + 1}}).encode(),
             "receipt changed after prepare wrote the evidence report"),
            ("the report laid out again", report_path, json.dumps(report, indent=1).encode(), "not canonical"),
            ("another run's report", report_path, evidence.encode({**report, "run_id": "earlier-run"}), "another run"),
            ("a report naming another frozen snapshot", report_path,
             evidence.encode({**report, "frozen_snapshot_sha256": "3" * 64}), "no longer bind"),
            ("no report", report_path, None, "No evidence report"),
            ("host.json in another mode", host.config_path, json.dumps({**host.config, "mysql": {
                **host.config["mysql"], "mode": "cutover"}}).encode(), "another mode"),
        ):
            with self.subTest(name):
                original, mode = path.read_bytes(), stat.S_IMODE(path.stat().st_mode)
                try:
                    if content is None:
                        path.unlink()
                    else:
                        path.write_bytes(content)
                    with self.assertRaisesRegex(UnsafeCutover, pattern):
                        host.host()[1].evidence()  # a fresh adapter: host.json is read again
                finally:
                    path.write_bytes(original)
                    path.chmod(mode)
                self.assertEqual(first, adapter.evidence())
        # A report written again, valid but a second later, is other evidence: promote refuses before writes_possible.
        report_path.write_bytes(evidence.encode({**report, "written_at": report["written_at"] + 1}))
        self.assertNotEqual(first.report_sha256, adapter.evidence().report_sha256)
        with self.assertRaisesRegex(UnsafeCutover, "Verification evidence changed after approval"):
            runner.perform("promote", runner.approval_request("promote"))
        after = journal.read()
        self.assertEqual(("ready", False, None), (after["phase"], after["writes_possible"], after["pending"]))

    def test_evidence_raises_when_mysql_unreachable_or_gate_not_closed_or_foreign_activity(self):
        host = self.prepared()
        _, adapter, _ = host.host()
        record = adapter.evidence()
        reading = probe_reading(server_uuid=host.target)
        cache = host.cache.read_bytes()
        sqlite_cache = cache_text(config_for(env_settings(ENV.format(database=host.database).encode())))
        other_route = cache_text(config_for({**host.config["mysql"]["switch"], "DB_PASSWORD": host.password,
                                             "DB_COLLATION": "utf8mb4_general_ci"}))
        locked = {"current_user": None, "problems": ["The gate account is locked (ERROR 3118 (HY000))"]}
        cases = (
            ("MySQL unreachable", lambda: host.answer({"version": 2, "reachable": False}, 3), "not reachable"),
            ("an open gate", lambda: host.answer(probe_reading(gate="open", server_uuid=host.target)), "gate"),
            ("a partial gate (exit 4)", lambda: host.answer({"gate": "partial", "problems": [
                "The gate state is partial"]}, 4), r"exit 4\).*partial"),
            ("foreign activity", lambda: host.answer({**reading, "foreign_activity": True}), "foreign activity"),
            ("business activity", lambda: host.answer({**reading, "business_activity": True}), "business activity"),
            ("another server", lambda: host.answer({**reading, "server_uuid": OTHER_UUID}), "another server"),
            ("a probe not armed on this receipt", lambda: host.answer(reading, arm=False),
             "not armed on this run's receipt"),
            ("the gate account's self-check", lambda: host.gate(5, f"The gate account's self-check failed: "
                                                                 f"{locked['problems'][0]}", gate_account=locked),
             "gate account is locked"),
            ("gate.check found nothing listening", lambda: host.gate(3, "The MySQL server is not listening; the gate "
                                                                        "was not changed", reachable=False, gate=None,
                                                                     gate_account=None), "not listening"),
            ("the live config is the .env fallback", lambda: host.cache.write_bytes(cache[:40]), "fallback"),
            ("the live config is SQLite", lambda: host.cache.write_bytes(sqlite_cache), "sqlite"),
            ("the live config has another route", lambda: host.cache.write_bytes(other_route), "MySQL pin"),
        )
        with patch.object(production, "PROBE_RETRY_SECONDS", 0.05):
            for name, change, pattern in cases:
                with self.subTest(name):
                    change()
                    before = host.tree()
                    with self.assertRaisesRegex(UnsafeCutover, pattern):
                        adapter.evidence()
                    self.assertEqual(before, host.tree())
                    host.answer(reading)
                    host.gate()
                    host.cache.write_bytes(cache)
                    self.assertEqual(record, adapter.evidence())

    def test_drill_binds_copy_and_frozen_snapshots(self):
        for options, forward in (({"mode": "drill"}, COPY_SNAPSHOT), ({"mode": "drill", "waiver": True}, COPY_SNAPSHOT),
                                 ({"mode": "cutover"}, FROZEN_SNAPSHOT)):
            with self.subTest(**options):
                host = self.prepared(**options)
                report = host.report()
                # Design 7.1: forward parity binds the copy's S_copy in a drill, the frozen snapshot in a cutover; the
                # SQLite publication binds S_frozen in both, and the report keeps both.
                self.assertEqual((forward, FROZEN_SNAPSHOT), (report["snapshot_sha256"],
                                                              report["frozen_snapshot_sha256"]))
                waiver = options.get("waiver", False)
                self.assertEqual((options["mode"], waiver, waiver), (report["mode"], report["offsite_waiver"],
                                                                     report["inputs"]["backup_check"] is None))
                record = host.host()[1].evidence()
                self.assertEqual((forward, True, True), (record.snapshot_sha256, record.sqlite_backup_offsite,
                                                         record.mysql_backup_offsite))
                self.assertEqual(forward, host.host()[0].read()["evidence"]["snapshot_sha256"])

    def test_check_evidence_write_creates_manifest_only(self):
        host = self.frozen()
        host.path("evidence_inputs").unlink()
        night = host.write_night()
        _, adapter, _ = host.host()
        effects, before = host.effects(), host.tree()
        result = adapter.check_evidence(night)
        # Without --write it checks and changes nothing.
        self.assertEqual(before, host.tree())
        files = production.NIGHT_FILES
        self.assertEqual({"version": 1, "run_id": RUN_ID, "mode": "drill", "backup": host.backup, "inputs": {
            name: {"path": str(night / files[name]),
                   "sha256": hashlib.sha256((night / files[name]).read_bytes()).hexdigest()} for name in INPUTS}},
            result["manifest"])
        self.assertEqual((COPY_SNAPSHOT, FROZEN_SNAPSHOT), (result["bound"]["snapshot_sha256"],
                                                            result["bound"]["frozen_snapshot_sha256"]))
        self.assertEqual(("closed", []), (result["gate"]["gate"], result["gate"]["gate_account"]["problems"]))
        # With --write: paths.evidence_inputs, root 0600, the manifest D10's prepare reads; nothing else.
        self.assertEqual(result["manifest"], adapter.check_evidence(night, write=True)["manifest"])
        after = host.tree()
        written = str(host.path("evidence_inputs").relative_to(host.root))
        self.assertEqual({written}, set(after) - set(before))
        self.assertEqual(before, {name: value for name, value in after.items() if name != written})
        content, uid, gid, mode = after[written]
        self.assertEqual((0, 0, 0o600, result["manifest"]), (uid, gid, mode, json.loads(content)))
        self.assertEqual(effects, host.effects())
        host.assert_prepared(host.prepare())
        # What it refuses writes nothing.
        refusing = self.frozen()
        refusing.path("evidence_inputs").unlink()
        _, adapter, _ = refusing.host()
        nested = "[" * 100000 + "]" * 100000 + "\n"
        for name, change, pattern in (
            ("an import of another server", lambda d, c: d["import"].update(target_server_uuid=OTHER_UUID),
             "import result names another MySQL server"),
            ("a deeply nested import result (D10V-3)", lambda d, c: d.update({"import": nested}),
             "import result is not one JSON object"),
            ("a coordinate with a key too many", lambda d, c: c.update(note="after the copy load"), "backup coordinate"),
            ("no backup check without the waiver", lambda d, c: d.update(backup_check=None),
             "final backup check is required"),
            ("a coordinate the backup check does not name", lambda d, c: c.update(position=c["position"] + 1),
             "not the recorded backup coordinate"),
            ("a coordinate that is not one", lambda d, c: c.update(position="1157"), "backup coordinate"),
            ("an input missing", lambda d, c: d.update(complete_schema=None), "complete-schema output"),
        ):
            with self.subTest(name):
                night = refusing.write_night(change)
                with self.assertRaisesRegex(UnsafeCutover, pattern):
                    adapter.check_evidence(night, write=True)
                self.assertFalse(refusing.path("evidence_inputs").exists())
        for name, night in (("a night path with NUL (D10V-3)", f"{refusing.root / 'night-files'}\0x"),
                            ("a relative night path", "night-files")):
            with self.subTest(name), self.assertRaisesRegex(UnsafeCutover, "night's input directory"):
                adapter.check_evidence(night, write=True)
        # Only in a frozen run in which MySQL was never opened for writes (design 3.10).
        journal = refusing.host()[0]
        state = journal.read()
        for name, change in (("ready", {"phase": "ready"}), ("holding", {"phase": "holding"}),
                             ("writes possible", {"writes_possible": True})):
            with self.subTest(name):
                journal.write({**state, **change})
                with self.assertRaisesRegex(UnsafeCutover, "frozen"):
                    adapter.check_evidence(refusing.write_night(), write=True)
                self.assertFalse(refusing.path("evidence_inputs").exists())
        journal.write(state)
        # The waiver (drill only) replaces the final backup check: a night that has one is refused.
        waived = self.frozen(waiver=True)
        waived.path("evidence_inputs").unlink()
        _, adapter, _ = waived.host()
        with self.assertRaisesRegex(UnsafeCutover, "off-site waiver replaces the final backup check"):
            adapter.check_evidence(waived.write_night(lambda d, c: d.update(
                backup_check=PrepareTests.frozen_check(waived))), write=True)
        self.assertFalse(waived.path("evidence_inputs").exists())
        self.assertIsNone(adapter.check_evidence(waived.write_night(), write=True)["manifest"]["inputs"]["backup_check"])

    def test_smokes_never_run_application_php_as_root(self):
        # Wave 1: cutover:smoke refuses root only after artisan bootstrapped, which may already have written root-owned
        # files where www-data writes. So no step 11 command reaches php as root: refused before anything runs.
        host = self.frozen()
        for name, change in (("a root profile", {"user": "root"}), ("a profile of group root", {"group": "root"})):
            with self.subTest(name):
                profiles = host.profiles()
                profiles["laravel-worker"].update(change)
                host.write_wrappers(profiles)
                ran = len(host.php_runs("cutover:smoke"))
                self.refused_at_checks(host, "never run as root")
                self.assertEqual(ran, len(host.php_runs("cutover:smoke")))
        host.write_wrappers(host.profiles())
        web, deploy = host.config["commands"]["smoke_readonly"]
        php, artisan, runuser = str(host.php_path), f"{host.app}/artisan", str(host.root / "bin" / "runuser")
        for name, command in (("php as root", [php, artisan, "cutover:smoke", "--read-only", "--json"]),
                              ("runuser -u root", [runuser, "-u", "root", "--", php, artisan, "cutover:smoke",
                                                   "--read-only", "--json"])):
            with self.subTest(name):
                set_commands(host, smoke_readonly=[command, deploy])
                ran = len(host.php_runs("cutover:smoke"))
                self.refused_at_checks(host, "runuser")
                self.assertEqual(ran, len(host.php_runs("cutover:smoke")))
        set_commands(host, smoke_readonly=[web, deploy])
        host.adapter_prepare()
        self.assertEqual([], [run for command in ("cutover:smoke", "db:writer-policy-check")
                              for run in host.php_runs(command) if 0 in (run[-1]["uid"], run[-1]["euid"])])
        self.assertEqual([], [entry for entry in host.commands() if entry[:2] == ["php", "config:cache"] and
                              entry[3] == 0])

    def test_the_freeze_drain_record_is_part_of_the_evidence(self):
        import evidence

        host = self.prepared()
        [freeze] = [entry for entry in host.host()[0].read()["history"] if entry["action"] == "freeze"]
        self.assertEqual({"purpose": "freeze", "outcome": "drained", "active_processes": 0, "waited_seconds": 0.5,
                          "polls": 2, "problem": None}, host.report()["fpm_drain"])
        self.assertEqual(freeze["fpm_drain"], host.report()["fpm_drain"])
        # evidence() reads it from the journal again.
        path, report = host.path("evidence_report"), host.report()
        original = path.read_bytes()
        path.write_bytes(evidence.encode({**report, "fpm_drain": {**report["fpm_drain"], "waited_seconds": 9.5}}))
        with self.assertRaisesRegex(UnsafeCutover, "FPM drain"):
            host.host()[1].evidence()
        path.write_bytes(original)
        # A freeze journalled without a drain record gives no evidence: step 12 refuses.
        bare = self.frozen()
        journal = bare.host()[0]
        state = journal.read()
        for entry in state["history"]:
            entry.pop("fpm_drain", None)
        journal.write(state)
        with self.assertRaisesRegex(UnsafeCutover, "FPM drain"):
            bare.adapter_prepare()
        self.assertIsNone(bare.record("evidence_report"))

    def test_mysql_down_reads_false_but_evidence_refuses_promote(self):
        # Review C2-7: with MySQL down and writes_possible false, observe() reads not writable and no business activity
        # (design 1.2), so the run stays frozen-on-MySQL for the watchdog; evidence() needs a reachable, closed target,
        # so promote refuses before writes_possible instead of ending in recovery_required (design 3.3).
        with patch.object(production, "PROBE_RETRY_SECONDS", 0.05):
            host = self.prepared(mode="cutover")
            host.answer({"version": 2, "reachable": False}, 3)
            journal, adapter, runner = host.host()
            observed = adapter.observe()
            self.assertTrue(observed.frozen() and observed.engine == "mysql", observed)
            self.assertEqual("ready", runner.perform("tick")["phase"])
            approval = runner.approval_request("promote")
            with self.assertRaisesRegex(UnsafeCutover, "not reachable"):
                runner.perform("promote", approval)
            state = journal.read()
            self.assertEqual(("ready", False, None), (state["phase"], state["writes_possible"], state["pending"]))
            self.assertFalse(host.path("evidence").exists())
            host.answer(probe_reading(server_uuid=host.target))
            self.assertEqual(state["evidence"], asdict(adapter.evidence()))

    def test_gate_account_self_check_is_part_of_check_evidence_and_evidence(self):
        # Review B2-1: gate.check (the reading plus the gate account's read-only login) before the one-shot prepare,
        # in step 11 and in every evidence(), so a gate account that cannot open the gate is found before Approval 2.
        host = self.frozen()
        host.path("evidence_inputs").unlink()
        night = host.write_night()
        _, adapter, _ = host.host()
        locked = {"current_user": None, "problems": ["The gate account is locked (ERROR 3118 (HY000))"]}
        message = f"The gate account's self-check failed: {locked['problems'][0]}"
        host.gate(5, message, gate_account=locked)
        with self.assertRaisesRegex(UnsafeCutover, "gate account is locked"):
            adapter.check_evidence(night, write=True)
        self.assertFalse(host.path("evidence_inputs").exists())
        host.gate()
        calls = host.gate_check.call_count
        adapter.check_evidence(night, write=True)
        self.assertEqual(calls + 1, host.gate_check.call_count)
        host.assert_prepared(host.prepare())
        self.assertEqual(calls + 3, host.gate_check.call_count)  # step 11 and the runner's evidence()
        self.assertEqual({"current_user": "repro_cutover_gate@localhost", "problems": []},
                         host.report()["checks"]["mysql"]["gate_account"])
        host.gate(5, message, gate_account=locked)
        with self.assertRaisesRegex(UnsafeCutover, "gate account is locked"):
            host.host()[1].evidence()
        stopped = self.frozen()
        stopped.gate(5, message, gate_account=locked)
        self.refused_at_checks(stopped, "gate account is locked")

    def test_report_written_root_only_with_the_readme_keys(self):
        import evidence

        host = self.prepared()
        path = host.path("evidence_report")
        raw, info = path.read_bytes(), path.lstat()
        self.assertEqual((0, 0, 0o600), (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)))
        report = evidence.parse(raw)
        self.assertEqual(evidence.REPORT_KEYS, set(report))
        self.assertEqual(raw, evidence.encode(report))
        receipt_raw, manifest = host.path("receipt").read_bytes(), host.record("evidence_inputs")
        self.assertEqual({"run_id": RUN_ID, "mode": "drill", "offsite_waiver": False, "backup": manifest["backup"],
                          "inputs": manifest["inputs"], "receipt_sha256": hashlib.sha256(receipt_raw).hexdigest(),
                          "armed_from": json.loads(receipt_raw)["armed_from"],
                          "switch_at": host.record("switch_record")["switch_at"]},
                         {key: report[key] for key in ("run_id", "mode", "offsite_waiver", "backup", "inputs",
                                                       "receipt_sha256", "armed_from", "switch_at")})
        self.assertEqual(report["receipt_sha256"], report["checks"]["mysql"]["receipt"])
        self.assertEqual({"forward_parity": True, "final_schema": True, "readonly_smoke": True,
                          "sqlite_backup_offsite": True, "mysql_backup_offsite": True}, report["results"])


@unittest.skipUnless(LINUX_ROOT and PROBE_FIXTURE, "Needs Linux root and the MySQL fixture (REPRO_MYSQL_PROBE_FIXTURE)")
class EvidenceFixtureTests(unittest.TestCase):
    """evidence() and check-evidence against D04's MySQL 8.4.13 fixture: the real probe and the real gate.check with
    the gate account's self-check (review B2-1)."""

    GATE_ACCOUNT = "'repro_cutover_gate'@'localhost'"

    setUpClass = classmethod(PrepareFixtureTests.setUpClass.__func__)
    setUp = PrepareFixtureTests.setUp
    assert_restored = PrepareFixtureTests.assert_restored
    frozen = PrepareFixtureTests.frozen

    def test_evidence_on_the_fixture_refuses_a_foreign_write(self):
        from test_mysql_probe import FOREIGN_WRITE

        host = self.frozen()
        host.assert_prepared(host.prepare())
        _, adapter, _ = host.host()
        self.assertEqual({"current_user": "repro_cutover_gate@localhost", "problems": []},
                         host.report()["checks"]["mysql"]["gate_account"])
        adapter.evidence()
        # An app-schema write after armed_from with no boundary before it: the copy changed (design 1.2).
        account, statement = FOREIGN_WRITE
        self.fixture.sql(statement, account=account)
        with self.assertRaisesRegex(UnsafeCutover, "foreign activity"):
            adapter.evidence()

    def test_evidence_on_the_fixture_refuses_an_open_gate_and_a_locked_gate_account(self):
        host = self.frozen()
        host.assert_prepared(host.prepare())
        _, adapter, _ = host.host()
        record = adapter.evidence()
        for name, change, undo, pattern in (
            ("an open gate", OPEN_GATE, CLOSE_GATE, "gate"),
            ("a locked gate account", f"ALTER USER {self.GATE_ACCOUNT} ACCOUNT LOCK",
             f"ALTER USER {self.GATE_ACCOUNT} ACCOUNT UNLOCK", "locked"),
        ):
            with self.subTest(name):
                self.fixture.sql(change)
                with self.assertRaisesRegex(UnsafeCutover, pattern):
                    adapter.evidence()
                self.fixture.sql(undo)
                self.assertEqual(record, adapter.evidence())

    def test_check_evidence_runs_the_real_gate_check(self):
        host = self.frozen()
        host.path("evidence_inputs").unlink()
        night = host.write_night()
        _, adapter, _ = host.host()
        self.fixture.sql(f"ALTER USER {self.GATE_ACCOUNT} ACCOUNT LOCK")
        with self.assertRaisesRegex(UnsafeCutover, "locked"):
            adapter.check_evidence(night, write=True)
        self.assertFalse(host.path("evidence_inputs").exists())
        self.fixture.sql(f"ALTER USER {self.GATE_ACCOUNT} ACCOUNT UNLOCK")
        result = adapter.check_evidence(night, write=True)
        self.assertEqual(("closed", []), (result["gate"]["gate"], result["gate"]["gate_account"]["problems"]))
        host.assert_prepared(host.prepare())


# The fixture tests: named before any suite when they would skip (as test_adapter.py's real-probe test).

# The fixture classes; SealSqliteFixtureTests (D12, at the end of this file) is added there.
FIXTURE_CLASSES = (PrepareFixtureTests, EvidenceFixtureTests)


def fixture_tests() -> list[str]:
    return [f"{case.__name__}.{name}" for case in FIXTURE_CLASSES for name in unittest.TestLoader().getTestCaseNames(case)]


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
                          "PrepareFixtureTests.test_receipt_has_backup_and_armed_from",
                          "EvidenceFixtureTests.test_check_evidence_runs_the_real_gate_check",
                          "EvidenceFixtureTests.test_evidence_on_the_fixture_refuses_a_foreign_write",
                          "EvidenceFixtureTests.test_evidence_on_the_fixture_refuses_an_open_gate_and_a_locked_gate_account",
                          "SealSqliteFixtureTests.test_abort_from_ready_closes_the_gate_and_ends_app_sessions",
                          "SealSqliteFixtureTests.test_receipt_still_armed_after_publication"],
                         fixture_tests())

    def test_readme_names_every_fixture_check(self):
        # review D10V-4: the README's Tests paragraph names each file run-linux-tests.sh checks before any suite.
        script = RUN_LINUX_TESTS.read_text(encoding="utf-8")
        checked = re.findall(r'production/(test_[a-z_]+\.py)" --fixture-check', script)
        self.assertEqual(["test_mysql_probe.py", "test_gate.py", "test_adapter.py", "test_adapter_mysql.py"], checked)
        readme = (HERE / "README.md").read_text(encoding="utf-8")
        start = readme.index("Without the fixtures\n`../run-linux-tests.sh`")
        paragraph = " ".join(readme[start:readme.index("The probe file's docstring", start)].split())
        for name in checked:
            self.assertIn(f"`{name}`", paragraph)
        self.assertIn("one `WARNING` line per file", paragraph)
        self.assertIn("when any of them would skip", paragraph)

    def test_missing_fixture_prints_one_warning_line_naming_the_fixture_tests(self):
        result = self.check(without_fixture_environment())
        self.assertEqual((0, ""), (result.returncode, result.stderr))
        lines = result.stdout.splitlines()
        self.assertEqual(1, len(lines), result.stdout)
        self.assertRegex(lines[0], rf"^WARNING: {len(fixture_tests())} prepare tests against the MySQL probe fixture "
                                   "SKIPPED ")
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


# seal_sqlite, the full undo of prepare (design 3.5, 4.4, 4.7; build plan D12).
#
# systemctl's stand-in for the rollback: it records and exits as the harness's recorder does, and keeps
# php8.3-fpm.service's state in fpm.json. start and restart run FPM, stop stops it; try-reload-or-restart reloads
# a running FPM (and records the live config that reload made it read) and does nothing to a stopped one.
FPM_SYSTEMCTL = RECORD + """try:
    with open({settings!r}) as stream:
        settings = json.load(stream)
except FileNotFoundError:
    settings = {{}}
verb = sys.argv[1] if len(sys.argv) > 1 else ""
code = settings.get("systemctl", {{}}).get(verb, 0)
if code == 0 and sys.argv[2:] == [{unit!r}]:
    with open({fpm!r}) as stream:
        fpm = json.load(stream)
    if verb in ("start", "restart"):
        fpm["running"] = True
    elif verb == "stop":
        fpm["running"] = False
    elif verb == "try-reload-or-restart" and fpm["running"]:
        sys.path[:0] = [{here!r}, {parent!r}]
        import live_config
        measured = live_config.measure({app!r})
        fpm["reloads"].append([measured.engine, measured.source, measured.live_config_sha256])
    with open({fpm!r}, "w") as stream:
        json.dump(fpm, stream)
sys.exit(code)
"""
# drain_fpm's second command, the FPM stop.
FPM_STOP = RECORD + """with open({fpm!r}) as stream:
    fpm = json.load(stream)
fpm["running"] = False
with open({fpm!r}, "w") as stream:
    json.dump(fpm, stream)
"""
# drain_fpm's first command, fpm_drain.py: records ["fpm-drain"] and prints a drained pool's report.
FPM_DRAIN = RECORD + """print({report!r})
"""
# The scripted MySQL probe, which also reads business activity the way the real one does after a rollback: only
# while this run's receipt is still armed (`business` stands for an app write after a boundary).
RECEIPT_PROBE = RECORD + """with open({answers!r}) as stream:
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
    if answer["code"] == 0 and os.path.exists({business!r}) and os.path.exists({receipt!r}):
        answer = {{**answer, "stdout": {{**answer["stdout"], "business_activity": True}}}}
stdout = answer["stdout"]
# As FAKE_PROBE: a reachable reading names the receipt it armed on once this run's receipt exists (D11's checks
# and evidence() compare it), unless the answer says it is not armed.
if answer.get("arm", True) and isinstance(stdout, dict) and stdout.get("reachable") and stdout.get("receipt", 0) is None:
    import hashlib
    try:
        with open({receipt!r}, "rb") as stream:
            stdout["receipt"] = hashlib.sha256(stream.read()).hexdigest()
    except FileNotFoundError:
        pass
print(json.dumps(stdout))
sys.exit(answer["code"])
"""
# Where the rollback's controller is killed inside seal_sqlite: the temporary file whose rename it precedes or follows.
ROLLBACK_KILL_POINTS = {"before-env-rename": ".env.repro-", "after-env-rename": ".env.repro-",
                        "after-config-rename": ".config.php.repro-"}
# The rollback's record (paths.unseal_proof; README "seal_sqlite").
UNSEAL_PROOF_KEYS = {"version", "run_id", "hold_id", "started_at", "database", "sha256", "sealed_sha256", "unchanged",
                     "problem", "gate", "gate_close_skipped", "at"}
UNREACHABLE = {"version": 2, "reachable": False}


class UndoHost(PrepareHost):
    """PrepareHost for the rollback (D12). PHP-FPM's state is kept by systemctl's stand-in (`fpm_service()`; `fpm`
    is D11's FastCGI stand-in), and drain_fpm is fpm_drain.py's stand-in (records ["fpm-drain"], prints a drained
    report) then the FPM stop, so a test sees whether FPM runs after the rollback. Without the fixture gate.close_gate is scripted from the probe's answer
    (exit 3: not listening; `gate_refusal`: an unrecognised state) and records ["gate-close"], and the probe reports
    business activity once `business` exists while the receipt is armed."""

    def __init__(self, test: unittest.TestCase, **options):
        super().__init__(test, **options)
        import gate

        bin_ = self.root / "bin"
        self.fpm_state = self.etc / "fpm.json"
        self.fpm_state.write_text(json.dumps({"running": True, "reloads": []}), encoding="utf-8")
        self._program("systemctl", FPM_SYSTEMCTL, settings=str(self.recorders), fpm=str(self.fpm_state), unit=UNIT,
                      app=str(self.app))
        self._program("fpm-stop", FPM_STOP, fpm=str(self.fpm_state))
        self._program("fpm-drain", FPM_DRAIN, report=json.dumps(drain_report("drained")))
        self.business = self.etc / "business"
        self.gate_refusal = None
        if not self.fixture:
            self._program("mysql-probe", RECEIPT_PROBE, answers=str(self.answers), count=str(self.etc / "since-count"),
                          business=str(self.business), receipt=self.config["paths"]["receipt"])
            patcher = patch.object(gate, "close_gate", side_effect=self._close_gate)
            patcher.start()
            test.addCleanup(patcher.stop)
        self.rewrite_config(lambda config: config["commands"].update(
            drain_fpm=[[str(bin_ / "fpm-drain")], [str(bin_ / "fpm-stop")]]))
        self.original = (self.env.read_bytes(), metadata(self.env))

    def _close_gate(self, config: dict, **_) -> dict:
        import gate

        with open(self.log, "a", encoding="utf-8") as stream:
            stream.write(json.dumps(["gate-close"]) + "\n")
        if json.loads(self.answers.read_text())["observe"]["code"] == production.MYSQL_UNREACHABLE:
            raise gate.GateUnreachable("The MySQL server is not listening; the gate was not changed")
        if self.gate_refusal:
            raise gate.GateRefused(self.gate_refusal)
        return {"before": "closed", "revoked": {}, "killed": []}

    def fpm_service(self) -> dict:
        """php8.3-fpm.service's state as systemctl's stand-in keeps it (`fpm` is D11's FastCGI stand-in)."""
        return json.loads(self.fpm_state.read_text(encoding="utf-8"))

    def hold(self) -> dict:
        """init and hold through the runner, no freeze (a rollback from `holding`)."""
        journal, adapter, runner = self.host()
        runner.initialize(fence_deploys=adapter.hold_deploys)
        self.state = runner.perform("hold", runner.approval_request("hold"))
        return self.state

    def ready(self) -> dict:
        """prepare through the runner to `ready`: steps 1 to 12 and D11's evidence() as they run, nothing patched."""
        self.assert_prepared(self.prepare())
        self.state = self.host()[0].read()
        return self.state

    def runner_at(self, seconds: float):
        """A runner and adapter whose clock reads `seconds` now (the watchdog's tick at a given point of the run)."""
        offset = seconds - time.time()

        def clock() -> float:
            return time.time() + offset

        loaded = production.load_config(guard.read_protected(self.config_path), read=guard.read_protected)
        journal = Journal(Path(loaded["paths"]["state"]))
        adapter = production.ProductionAdapter(loaded, journal, clock=clock)
        return production.runner_for(loaded, journal, adapter, clock=clock)

    def abort(self) -> dict:
        return self.runner().perform("abort")

    def crash_rollback_after(self, number: int) -> None:
        """runner.perform("abort") stopped inside seal_sqlite between its step `number` and the next one."""
        steps = dict(production.SEAL_SQLITE_STEPS)
        with patch.object(production.ProductionAdapter, f"_seal_sqlite_{steps[number + 1]}",
                          side_effect=Crash(f"stopped after seal_sqlite step {number}")):
            with self.test.assertRaises(Crash):
                self.abort()

    def kill_rollback_inside(self, point: str) -> None:
        """runner.perform("abort") in a child process that SIGKILLs itself inside seal_sqlite's .env restore or its
        config build, before or after the rename of the temporary file (ROLLBACK_KILL_POINTS)."""
        prefix = ROLLBACK_KILL_POINTS[point]
        pid = os.fork()
        if pid == 0:  # The child never returns into the test runner.
            try:
                rename = os.replace

                def replace(source, destination, *arguments, **keywords):
                    named = os.path.basename(os.fspath(source)).startswith(prefix)
                    if named and point.startswith("before-"):
                        os.kill(os.getpid(), signal.SIGKILL)
                    result = rename(source, destination, *arguments, **keywords)
                    if named:
                        os.kill(os.getpid(), signal.SIGKILL)
                    return result

                os.replace = replace
                self.abort()
            finally:
                os._exit(1)
        _, status = os.waitpid(pid, 0)
        self.test.assertEqual(signal.SIGKILL, os.WTERMSIG(status) if os.WIFSIGNALED(status) else None,
                              f"the rollback was not killed at {point}")

    def seal_sqlite(self, deadline: float | None = None) -> None:
        """adapter.seal_sqlite under a drained lease, as the runner's rollback calls it."""
        journal, adapter, _ = self.host()
        with self.lease(journal) as lease:
            adapter.seal_sqlite(lease, deadline or time.time() + 600)

    def release_scheduler(self) -> None:
        """The scheduler release a reopen leaves (design 3.8), for the rollback to remove."""
        path = self.path("scheduler_release")
        path.parent.mkdir(mode=0o755, exist_ok=True)
        path.write_text("{}", encoding="ascii")

    def rollback_effects(self, since: int) -> list:
        """The host effects (systemctl, runuser, config:cache) and drain commands since the `since`-th command."""
        return [entry[:2] if entry[0] in ("systemctl", "php") else entry[:1] for entry in self.commands()[since:]
                if entry[0] in ("systemctl", "runuser", "fpm-drain", "fpm-stop", "gate-close")
                or entry[:2] == ["php", "config:cache"]]

    def held_on_mysql_switch(self) -> bool:
        """True when SQLite is writable while .env or the live config still routes to MySQL (review D10R-1)."""
        measured = live_config.measure(self.app)
        return sqlite_seal.writable(self.database) and (self.switched() or measured.engine != "sqlite")


@unittest.skipUnless(LINUX_ROOT, "Needs Linux root: owners, ACLs, runuser and the guard's protected files")
class SealSqliteTests(unittest.TestCase):
    """seal_sqlite undoes any part of prepare (design 3.5; build plan D12), with the scripted MySQL probe."""

    def frozen(self, **options) -> UndoHost:
        host = UndoHost(self, **options)
        host.freeze()
        return host

    def assert_undone(self, host: UndoHost, *, gate_close_skipped: bool = False) -> None:
        """The rollback finished: SQLite authority published, every part of the switch undone, FPM stopped."""
        journal = host.host()[0]
        state = journal.read()
        self.assertEqual(("sqlite_authoritative", None, "abort"),
                         (state["phase"], state["pending"], state["history"][-1]["action"]))
        # The .env byte for byte, with its owner, group, mode and every extended attribute (the ACL), as before
        # prepare; no temporary file beside it or beside config.php.
        env, before = host.original
        self.assertEqual(env, host.env.read_bytes())
        self.assertEqual(before[1:], metadata(host.env)[1:])
        self.assertEqual([], host.leftovers())
        measured = live_config.measure(host.app)
        self.assertEqual(("sqlite", live_config.CACHE, host.sqlite_pin),
                         (measured.engine, measured.source, measured.live_config_sha256))
        info = host.cache.lstat()
        self.assertEqual((host.deploy, WEB_UID, 0o644), (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)))
        self.assertTrue(sqlite_seal.writable(host.database))
        self.assertFalse(host.path("ingress_open").exists())
        self.assertFalse(host.path("scheduler_release").exists())
        self.assertFalse(host.fpm_service()["running"], "PHP-FPM must be stopped after a rollback; the reopen starts it")
        proof, sealed = host.record("unseal_proof"), host.record("sealed_hash")
        self.assertEqual((sealed["sha256"], sealed["sha256"], True, gate_close_skipped),
                         (proof["sha256"], proof["sealed_sha256"], proof["unchanged"], proof["gate_close_skipped"]))
        # The SQLite authority the rollback published admits SQLite writers: measured on the SQLite pin.
        guard.verify((host.etc / "policy.json").read_bytes(), journal.path.read_bytes(),
                     host.path("evidence").read_bytes(), machine_sha=guard.sha256((host.root / "machine-id").read_bytes()),
                     boot_id=host.boot.read_text().strip(), action="queue-worker", expected_engine="sqlite")
        # The identity still matches the pin (the .env digest never moved), so the watchdog keeps the phase.
        self.assertEqual("sqlite_authoritative", host.runner().perform("tick")["phase"])

    def assert_held(self, host: UndoHost, pattern: str) -> None:
        """The rollback refused with `pattern` before it unsealed SQLite: the run stays held with its pending abort,
        nothing is published, and .env and config.php are as they were (never reopened on a MySQL route)."""
        def files() -> tuple:
            return host.env.read_bytes(), host.cache.read_bytes() if host.cache.exists() else None

        before = files()
        with self.assertRaisesRegex(UnsafeCutover, pattern):
            host.abort()
        state = host.host()[0].read()
        self.assertEqual("abort", state["pending"]["action"])
        self.assertIn(state["phase"], ("frozen", "ready"))
        self.assertFalse(sqlite_seal.writable(host.database))
        self.assertFalse(host.path("evidence").exists())
        self.assertEqual(before, files())

    def test_abort_from_frozen_without_prepare(self):
        # A rollback that has nothing to undo: no switch record, the .env and config.php untouched (same inode, no
        # build, no FPM reload), the gate closed (no login: the probe read it closed), the unseal proof recorded.
        host = self.frozen()
        env, cache = metadata(host.env), (host.cache.read_bytes(), host.cache.lstat().st_ino)
        host.release_scheduler()
        mark = len(host.commands())
        self.assertEqual("sqlite_authoritative", host.abort()["phase"])
        self.assertEqual([["fpm-drain"], ["fpm-stop"], ["gate-close"]], host.rollback_effects(mark))
        self.assertEqual((env, cache), (metadata(host.env), (host.cache.read_bytes(), host.cache.lstat().st_ino)))
        self.assertIsNone(host.record("switch_record"))
        self.assert_undone(host)
        proof = host.record("unseal_proof")
        self.assertEqual(UNSEAL_PROOF_KEYS, set(proof))
        self.assertEqual({"before": "closed", "revoked": {}, "killed": []}, proof["gate"])
        # From `holding` (no freeze): nothing was sealed, so the proof has no sealed hash to compare with.
        held = UndoHost(self)
        held.hold()
        self.assertEqual("sqlite_authoritative", held.abort()["phase"])
        proof = held.record("unseal_proof")
        self.assertEqual((None, None, False), (proof["sealed_sha256"], proof["unchanged"], proof["gate_close_skipped"]))
        self.assertEqual(hashlib.sha256(held.database.read_bytes()).hexdigest(), proof["sha256"])
        self.assertRegex(proof["problem"], "no sealed hash of this run and hold")
        self.assertTrue(sqlite_seal.writable(held.database))
        self.assertEqual(held.original[0], held.env.read_bytes())

    def test_abort_from_ready(self):
        host = self.frozen()
        host.ready()
        self.assertTrue(host.switched())
        records = {name: host.path(name).read_bytes() for name in ("switch_record", "sealed_hash", "receipt")}
        host.release_scheduler()
        mark = len(host.commands())
        self.assertEqual("sqlite_authoritative", host.abort()["phase"])
        self.assert_undone(host)
        # In order: the rollback's drain stopped FPM; then seal_sqlite closed the gate, built the SQLite cache as the
        # deploy user (after the .env restore) and sent try-reload-or-restart, which leaves the stopped FPM stopped.
        self.assertEqual([["fpm-drain"], ["fpm-stop"], ["gate-close"], ["runuser"], ["php", "config:cache"],
                          ["systemctl", "try-reload-or-restart"]], host.rollback_effects(mark))
        # The switch record and the sealed hash stay until retire; the receipt stays armed (design 3.5).
        self.assertEqual(records, {name: host.path(name).read_bytes() for name in records})

    def test_abort_after_crash_at_each_prepare_step(self):
        # Every state README "What a crash leaves" lists (PrepareHost.crash_after and kill_inside), then an abort:
        # always the whole undo, never SQLite reopened under a MySQL .env (review D10R-1).
        steps = production.PREPARE_STEPS
        points = [(f"crash after step {number}", number, lambda host, number=number: host.crash_after(number))
                  for number, _ in steps[:-1]]
        points += [("every step, then evidence() refused", 10, lambda host: host.prepare()),
                   ("kill inside step 7, before the rename", 6, lambda host: host.kill_inside("before-env-rename")),
                   ("kill inside step 8, after the rename", None, lambda host: host.kill_inside("after-config-rename"))]
        for name, after, stop in points:
            with self.subTest(name):
                host = self.frozen()
                stop(host)
                if after is None:
                    self.assertTrue(host.switched())
                    self.assertEqual(host.mysql_pin, live_config.measure(host.app).live_config_sha256)
                    self.assertIsNone(host.record("switch_record")["switch_at"])
                else:
                    host.assert_markers(after=after)
                # Design 3.5 steps 4 and 5: the cache is built again once the .env is restored (or not the pin),
                # and try-reload-or-restart goes out wherever this run's switch may have reached FPM.
                switched, recorded = host.switched(), host.record("switch_record") is not None
                mark = len(host.commands())
                self.assertEqual("sqlite_authoritative", host.abort()["phase"])
                effects = host.rollback_effects(mark)
                self.assertEqual((switched, recorded), (["php", "config:cache"] in effects,
                                                        ["systemctl", "try-reload-or-restart"] in effects))
                self.assert_undone(host)

    def test_watchdog_rollback_at_45_minutes(self):
        def failed_fpm_start(host: UndoHost) -> None:
            # D18's r2.prepare-fails: prepare stops at step 9, after the .env switch and the MySQL build.
            host.recorders.write_text(json.dumps({"systemctl": {"restart": 1}}), encoding="utf-8")
            self.assertRegex(str(host.prepare()), "Host command failed: systemctl exited 1")
            host.recorders.unlink()
            host.assert_markers(after=8, restarts=1)  # The recorder lists the restart that failed.
            self.assertFalse(host.fpm_service()["running"])

        for name, setup, phase in (("from ready", UndoHost.ready, "ready"),
                                   ("after a prepare that failed at the FPM start", failed_fpm_start, "frozen")):
            with self.subTest(name):
                host = self.frozen()
                setup(host)
                started = host.host()[0].read()["started_at"]
                # A tick before 0:45 keeps the phase; the tick at 0:45 rolls back unattended.
                early = host.runner_at(started + production.Runner.DECISION_SECONDS - 60).perform("tick")
                self.assertEqual(phase, early["phase"])
                self.assertTrue(host.switched())
                late = host.runner_at(started + production.Runner.DECISION_SECONDS).perform("tick")
                self.assertEqual("sqlite_authoritative", late["phase"])
                self.assert_undone(host)

    def test_mysql_down_close_skipped_and_recorded(self):
        host = self.frozen()
        host.ready()
        # Nothing listens: observe() reads false/false (writes_possible is false), the gate close is skipped.
        host.answer(UNREACHABLE, 3)
        self.assertEqual("sqlite_authoritative", host.abort()["phase"])
        self.assert_undone(host, gate_close_skipped=True)
        self.assertIsNone(host.record("unseal_proof")["gate"])
        # A rollback that crashed after its proof with MySQL down, retried once MySQL is back, records the close.
        retried = self.frozen()
        retried.ready()
        retried.answer(UNREACHABLE, 3)
        retried.crash_rollback_after(7)
        self.assertTrue(retried.record("unseal_proof")["gate_close_skipped"])
        retried.answer(probe_reading(server_uuid=retried.target))
        self.assertEqual("sqlite_authoritative", retried.abort()["phase"])
        self.assert_undone(retried)
        # The skip is only allowed while the journal says writes are impossible: with writes_possible true the
        # undo refuses before it changes anything (the runner refuses such a rollback first).
        fresh = self.frozen()
        fresh.ready()
        fresh.answer(UNREACHABLE, 3)
        journal = fresh.host()[0]
        journal.write({**journal.read(), "writes_possible": True})
        env = fresh.env.read_bytes()
        with self.assertRaisesRegex(UnsafeCutover, "unreachable while MySQL writes may be possible"):
            fresh.seal_sqlite()
        self.assertEqual(env, fresh.env.read_bytes())
        self.assertFalse(sqlite_seal.writable(fresh.database))
        self.assertIsNone(fresh.record("unseal_proof"))
        # Only writes_possible exactly false allows the skip: a journal without it, or with any other value, does not.
        for value in ("missing", None):
            with self.subTest(writes_possible=value):
                unknown = self.frozen()
                unknown.ready()
                unknown.answer(UNREACHABLE, 3)
                journal = unknown.host()[0]
                state = journal.read()
                if value == "missing":
                    state.pop("writes_possible", None)
                else:
                    state["writes_possible"] = value
                journal.write(state)
                with self.assertRaisesRegex(UnsafeCutover, "unreachable while MySQL writes may be possible"):
                    unknown.seal_sqlite()
                self.assertFalse(sqlite_seal.writable(unknown.database))
                self.assertIsNone(unknown.record("unseal_proof"))
        # A gate the close cannot handle (an unrecognised state) refuses before any undo; the retry finishes.
        refused = self.frozen()
        refused.ready()
        refused.gate_refusal = "The gate state is unrecognised: a role is granted to repro_app@localhost"
        self.assert_held(refused, "gate state is unrecognised")
        self.assertTrue(refused.switched())
        refused.gate_refusal = None
        self.assertEqual("sqlite_authoritative", refused.abort()["phase"])
        self.assert_undone(refused)

    def test_hand_switch_without_record_refuses(self):
        def by_hand_env(host: UndoHost) -> None:
            host.env.write_bytes(host.env.read_bytes().replace(b"DB_CONNECTION=sqlite", b"DB_CONNECTION=mysql"))

        def by_hand_cache(host: UndoHost) -> None:
            host.cache.write_bytes(cache_text(config_for({**host.config["mysql"]["switch"],
                                                          "DB_PASSWORD": host.password})))

        def another_runs_record(host: UndoHost) -> None:
            # Review D10V-1 (1): another run's record is never used to restore, whatever the .env holds.
            host.crash_after(7)
            host.write_record("switch_record", {**host.record("switch_record"), "run_id": "earlier-run"})

        def two_lines(host: UndoHost) -> None:
            # Review D10V-1 (2): a .env the switch record cannot be checked against.
            host.crash_after(7)
            host.env.write_bytes(host.env.read_bytes() + b"DB_CONNECTION=mysql\n")

        def linked(host: UndoHost) -> None:
            host.crash_after(7)
            real = host.app / "env.real"
            host.env.rename(real)
            host.env.symlink_to(real)

        def tampered_record(host: UndoHost) -> None:
            host.crash_after(7)
            host.write_record("switch_record", {**host.record("switch_record"), "env_sha256": "0" * 64})

        def malformed_record(host: UndoHost) -> None:
            host.crash_after(7)
            record = host.record("switch_record")
            record["values"].pop("DB_SOCKET")
            host.write_record("switch_record", record)

        def by_hand_env_no_cache(host: UndoHost) -> None:
            # No cache to tell the engine either: refused as switched by hand, before any build from that .env.
            by_hand_env(host)
            host.cache.unlink()

        def another_runs_record_two_lines(host: UndoHost) -> None:
            # Review D10V-1 (2) with another run's record: a .env it cannot be checked against refuses too.
            host.write_record("switch_record", {"version": 1, "run_id": "earlier-run", "values": envfile.values(
                host.env.read_bytes(), production.switch_keys(host.config)),
                "env_sha256": envfile.digest(host.env.read_bytes(), NIGHT_FLAGS), "switch_at": None})
            host.env.write_bytes(host.env.read_bytes() + b"DB_CONNECTION=sqlite\n")

        def another_engine_by_hand(host: UndoHost) -> None:
            # Without a switch of this run only sqlite (or no DB_CONNECTION) passes, not merely "not mysql".
            host.env.write_bytes(host.env.read_bytes().replace(b"DB_CONNECTION=sqlite", b"DB_CONNECTION=pgsql"))

        def another_runs_record_no_cache(host: UndoHost) -> None:
            # Another run's record over a switched .env is refused in step 3 itself, before step 4 would build a
            # config cache from that .env.
            another_runs_record(host)
            host.cache.unlink()

        for name, change, pattern in (
            ("a .env switched by hand", by_hand_env, "switched by hand"),
            ("a .env naming another engine by hand", another_engine_by_hand, "switched by hand"),
            ("another run's record over a switched .env and no config cache", another_runs_record_no_cache,
             "switched by hand"),
            ("a .env switched by hand and no config cache", by_hand_env_no_cache, "switched by hand"),
            ("another run's record and a switch key on two lines", another_runs_record_two_lines,
             "cannot be checked against the switch record"),
            ("a MySQL config cache built by hand", by_hand_cache, "switched by hand"),
            ("another run's record over a switched .env", another_runs_record, "switched by hand"),
            ("a switch key on two lines", two_lines, "cannot be checked against the switch record"),
            ("a .env that is a symbolic link", linked, "cannot be read"),
            ("a record the .env does not match", tampered_record, "does not match the .env"),
            ("a malformed record", malformed_record, "switch record is malformed"),
        ):
            with self.subTest(name):
                host = self.frozen()
                change(host)
                self.assert_held(host, pattern)
                self.assertFalse(host.held_on_mysql_switch())
        # Put right by hand, the retried rollback finishes.
        host = self.frozen()
        by_hand_env(host)
        self.assert_held(host, "switched by hand")
        host.env.write_bytes(host.original[0])
        self.assertEqual("sqlite_authoritative", host.abort()["phase"])
        self.assert_undone(host)
        # A .env with no DB_CONNECTION line (Laravel's default connection, sqlite) and no record: nothing to undo.
        bare = self.frozen()
        lines = bare.env.read_bytes().splitlines(keepends=True)
        bare.env.write_bytes(b"".join(line for line in lines if not line.startswith(b"DB_CONNECTION=")))
        bare.original = (bare.env.read_bytes(), metadata(bare.env))
        self.assertEqual("sqlite_authoritative", bare.abort()["phase"])
        self.assert_undone(bare)
        # Another run's record over a SQLite .env: nothing of this run to undo; the record is never used or replaced.
        other = self.frozen()
        record = {"version": 1, "run_id": "earlier-run", "values": envfile.values(
            other.env.read_bytes(), production.switch_keys(other.config)),
            "env_sha256": envfile.digest(other.env.read_bytes(), NIGHT_FLAGS), "switch_at": None}
        other.write_record("switch_record", record)
        raw = other.path("switch_record").read_bytes()
        # What an interrupted replacement left beside the .env is cleared with any run's record (review D10R-5).
        leftover = other.app / f".env.repro-{'a' * 32}"
        leftover.write_bytes(b"DB_CONNECTION=mysql\n")
        self.assertEqual([leftover.name], other.leftovers())
        self.assertEqual("sqlite_authoritative", other.abort()["phase"])
        self.assert_undone(other)
        self.assertEqual(raw, other.path("switch_record").read_bytes())

    def test_a_missing_env_with_a_switch_record_refuses(self):
        # With a switch record, this run's or another run's, a missing .env is refused in step 3 by name: nothing
        # records what the app would read, so the rollback stops before it unseals SQLite and the run stays held.
        # Through the runner the identity probe refuses first (it measures the .env); seal_sqlite refuses by itself.
        def this_runs(host: UndoHost) -> None:
            host.crash_after(7)

        def another_runs(host: UndoHost) -> None:
            host.write_record("switch_record", {"version": 1, "run_id": "earlier-run", "values": envfile.values(
                host.env.read_bytes(), production.switch_keys(host.config)),
                "env_sha256": envfile.digest(host.env.read_bytes(), NIGHT_FLAGS), "switch_at": None})

        for name, change in (("this run's record", this_runs), ("another run's record", another_runs)):
            with self.subTest(name):
                host = self.frozen()
                change(host)
                host.env.unlink()
                with self.assertRaisesRegex(UnsafeCutover, "identity-probe"):
                    host.abort()
                with self.assertRaisesRegex(UnsafeCutover, "cannot be read to undo the switch: it is missing"):
                    host.seal_sqlite()
                self.assertEqual("frozen", host.host()[0].read()["phase"])
                self.assertFalse(sqlite_seal.writable(host.database))
                self.assertFalse(host.path("evidence").exists())
                self.assertFalse(host.env.exists())

    def test_truncated_config_cache_rebuilt(self):
        # Design 4.7: a missing or unparseable config.php is the fallback; the rollback runs and rebuilds it.
        for name, prepared, change in (
            ("truncated after prepare", True, lambda host: host.cache.write_bytes(host.cache.read_bytes()[:40])),
            ("truncated, no prepare", False, lambda host: host.cache.write_bytes(host.cache.read_bytes()[:40])),
            ("missing, no prepare", False, lambda host: host.cache.unlink()),
        ):
            with self.subTest(name):
                host = self.frozen()
                if prepared:
                    host.ready()
                change(host)
                self.assertEqual(live_config.FALLBACK, live_config.measure(host.app).source)
                mark = len(host.commands())
                self.assertEqual("sqlite_authoritative", host.abort()["phase"])
                effects = host.rollback_effects(mark)
                self.assertIn(["php", "config:cache"], effects)
                # The rebuilt cache reaches FPM: step 5 follows the build even without a switch of this run.
                reload = ["systemctl", "try-reload-or-restart"]
                self.assertIn(reload, effects)
                self.assertLess(effects.index(["php", "config:cache"]), effects.index(reload))
                self.assert_undone(host)
        # A rebuild that fails leaves the live file as it was and SQLite sealed; the retry finishes.
        host = self.frozen()
        host.cache.write_bytes(host.cache.read_bytes()[:40])
        host.php(config_cache="half-then-fail")
        self.assert_held(host, "fallback.*not rebuilt|not rebuilt.*fallback")
        self.assertEqual(["config.php"], sorted(entry.name for entry in host.cache.parent.iterdir()))
        host.php()
        self.assertEqual("sqlite_authoritative", host.abort()["phase"])
        self.assert_undone(host)
        # A build that cannot give the SQLite pin (here the .env's SQLite path was changed by hand, a denylisted
        # key) is refused before it replaces config.php: the live file stays as it was.
        other = self.frozen()
        other.cache.write_bytes(other.cache.read_bytes()[:40])
        other.env.write_bytes(other.original[0].replace(f"DB_DATABASE={other.database}".encode(),
                                                        b"DB_DATABASE=/var/www/backend/database/other.sqlite"))
        self.assert_held(other, "not rebuilt.*not the expected route hash")
        other.env.write_bytes(other.original[0])
        self.assertEqual("sqlite_authoritative", other.abort()["phase"])
        self.assert_undone(other)

    def test_fpm_stopped_and_running_use_try_reload_or_restart(self):
        # Stopped (the rollback's drain stopped it, as after every freeze and rollback): try-reload-or-restart does
        # nothing, and FPM stays stopped until the reopen starts it.
        host = self.frozen()
        host.ready()
        self.assertTrue(host.fpm_service()["running"])
        mark = len(host.commands())
        host.abort()
        self.assertEqual([["systemctl", "try-reload-or-restart", UNIT]],
                         [entry for entry in host.commands()[mark:] if entry[0] == "systemctl"])
        self.assertEqual({"running": False, "reloads": []}, host.fpm_service())
        self.assert_undone(host)
        # Running (seal_sqlite after a drain that did not stop it): reloaded once, after the undo, so its workers
        # read the SQLite config; FPM keeps running.
        running = self.frozen()
        running.ready()
        # Called directly (no close_admissions before it), step 9 removes the ingress marker and the scheduler release.
        running.release_scheduler()
        running.path("ingress_open").parent.mkdir(mode=0o755, parents=True, exist_ok=True)
        running.path("ingress_open").write_text("", encoding="ascii")
        mark = len(running.commands())
        running.seal_sqlite()
        self.assertEqual((False, False), (running.path("ingress_open").exists(),
                                          running.path("scheduler_release").exists()))
        self.assertEqual([["systemctl", "try-reload-or-restart", UNIT]],
                         [entry for entry in running.commands()[mark:] if entry[0] == "systemctl"])
        self.assertEqual({"running": True, "reloads": [["sqlite", live_config.CACHE, running.sqlite_pin]]},
                         running.fpm_service())
        # A reload that fails stops the undo before SQLite is unsealed; the retry finishes.
        failing = self.frozen()
        failing.ready()
        failing.recorders.write_text(json.dumps({"systemctl": {"try-reload-or-restart": 1}}), encoding="utf-8")
        with self.assertRaisesRegex(UnsafeCutover, "Host command failed: systemctl exited 1"):
            failing.abort()
        self.assertFalse(sqlite_seal.writable(failing.database))
        self.assertFalse(failing.held_on_mysql_switch())
        failing.recorders.unlink()
        self.assertEqual("sqlite_authoritative", failing.abort()["phase"])
        self.assert_undone(failing)

    def test_unseal_proof_equals_sealed_hash(self):
        host = self.frozen()
        host.ready()
        host.abort()
        proof, sealed = host.record("unseal_proof"), host.record("sealed_hash")
        self.assertEqual(UNSEAL_PROOF_KEYS, set(proof))
        self.assertEqual({key: sealed[key] for key in ("run_id", "hold_id", "started_at", "database")},
                         {key: proof[key] for key in ("run_id", "hold_id", "started_at", "database")})
        self.assertEqual((sealed["sha256"], sealed["sha256"], True, None),
                         (proof["sha256"], proof["sealed_sha256"], proof["unchanged"], proof["problem"]))
        self.assertEqual(hashlib.sha256(host.database.read_bytes()).hexdigest(), proof["sha256"])
        info = host.path("unseal_proof").lstat()
        self.assertEqual((0, 0o600), (info.st_uid, stat.S_IMODE(info.st_mode)))
        # A sealed file that changed after the freeze: recorded and reported, and the rollback still finishes (SQLite
        # is the only copy with every confirmed write).
        import io

        changed = self.frozen()
        changed.ready()
        with open(changed.database, "ab") as stream:
            stream.write(b"\0" * 512)
        errors = io.StringIO()
        with patch("sys.stderr", errors):
            self.assertEqual("sqlite_authoritative", changed.abort()["phase"])
        proof = changed.record("unseal_proof")
        self.assertEqual((hashlib.sha256(changed.database.read_bytes()).hexdigest(), changed.record("sealed_hash")["sha256"],
                          False), (proof["sha256"], proof["sealed_sha256"], proof["unchanged"]))
        self.assertRegex(proof["problem"], "changed after the freeze")
        self.assertRegex(errors.getvalue(), r"WARNING: .*unseal proof.*changed after the freeze")
        self.assertTrue(sqlite_seal.writable(changed.database))
        # Only this run's and this hold's sealed hash counts: another hold's record (a journal reset by hand) is no
        # proof either way, and the rollback goes on.
        for name, change in (("another hold's", {"hold_id": str(uuid.uuid4())}), ("another run's", {"run_id": "earlier"}),
                             ("another database's", {"database": "/var/www/backend/database/other.sqlite"}),
                             ("another start's", {"started_at": "started_at+1"}),
                             ("a malformed", {"sha256": "not-a-digest"}), ("a malformed", {"extra": True})):
            with self.subTest(name, change=change):
                stale = self.frozen()
                record = stale.record("sealed_hash")
                if change.get("started_at") == "started_at+1":
                    change = {"started_at": stale.host()[0].read()["started_at"] + 1}
                stale.write_record("sealed_hash", {**record, **change})
                self.assertEqual("sqlite_authoritative", stale.abort()["phase"])
                proof = stale.record("unseal_proof")
                self.assertEqual((None, None), (proof["sealed_sha256"], proof["unchanged"]))
                self.assertRegex(proof["problem"], "malformed" if name == "a malformed" else "another run, hold or database")
        # A sealed file the proof cannot hash stops the rollback before step 8: SQLite stays sealed, the retry finishes.
        unhashable = self.frozen()
        unhashable.ready()
        with patch.object(sqlite_seal, "file_sha256", side_effect=PermissionError(13, "Permission denied")):
            with self.assertRaisesRegex(UnsafeCutover, "cannot be hashed for the unseal proof: Permission denied"):
                unhashable.abort()
        self.assertFalse(sqlite_seal.writable(unhashable.database))
        self.assertIsNone(unhashable.record("unseal_proof"))
        self.assertEqual("sqlite_authoritative", unhashable.abort()["phase"])
        self.assert_undone(unhashable)

    def test_evidence_before_and_after_the_undo(self):
        # D11 x D12: a ready reached through D11's real steps 11 and 12 and evidence(), then a rollback stopped
        # after several seal_sqlite steps and finished. While the abort is pending the runner refuses promote
        # whatever evidence() says; once the cache is SQLite again evidence() refuses too, so no promote can be
        # approved on an undone switch. The undo keeps what evidence() and the SQLite reopen (D13) read.
        host = self.frozen()
        host.ready()
        journal = host.host()[0]
        ready = journal.read()
        self.assertEqual(ready["evidence"], asdict(host.host()[1].evidence()))
        kept = {name: host.path(name).read_bytes() for name in ("evidence_report", "receipt", "sealed_hash")}
        report = json.loads(kept["evidence_report"])
        self.assertEqual(guard.sha256(kept["receipt"]), report["receipt_sha256"])
        for number in (1, 3, 4, 7):
            with self.subTest(f"stopped after seal_sqlite step {number}"):
                host.crash_rollback_after(number)
                state = journal.read()
                self.assertEqual(("ready", "abort"), (state["phase"], state["pending"]["action"]))
                with self.assertRaisesRegex(UnsafeCutover, "Interrupted action requires a verified pre-write abort"):
                    host.runner().perform("promote")
                self.assertEqual(state, journal.read())
                if number >= 4:  # Step 4 built the SQLite cache.
                    with self.assertRaisesRegex(UnsafeCutover, "The live config is not the MySQL pin from the cache"):
                        host.host()[1].evidence()
                self.assertFalse(sqlite_seal.writable(host.database))
        self.assertEqual("sqlite_authoritative", host.abort()["phase"])
        self.assert_undone(host)
        # The report, the receipt (still armed: the report's receipt_sha256 is still its digest) and the sealed hash
        # are byte for byte as prepare left them; the unseal proof is this run's and this hold's.
        self.assertEqual(kept, {name: host.path(name).read_bytes() for name in kept})
        proof = host.record("unseal_proof")
        self.assertEqual((RUN_ID, ready["hold"]["hold_id"], ready["started_at"], None),
                         (proof["run_id"], proof["hold_id"], proof["started_at"], proof["problem"]))
        with self.assertRaisesRegex(UnsafeCutover, "The live config is not the MySQL pin from the cache"):
            host.host()[1].evidence()
        with self.assertRaisesRegex(UnsafeCutover, "frozen"):
            host.host()[1].check_evidence(host.write_night())
        with self.assertRaises(UnsafeCutover):
            host.runner().perform("prepare")
        self.assertEqual("sqlite_authoritative", journal.read()["phase"])

    def test_the_undo_removes_token_check_folders_a_crash_left(self):
        # D11 x D12: step 11's token check removes its folder (the check account's password is in its config cache)
        # after the check, and a folder a killed check left is removed by the next check. A rolled-back run has no
        # next check, so the undo removes such folders (step 9); a symbolic link of that name is left alone.
        for name, crash in (("frozen, no prepare", None), ("prepare stopped after step 10", 10)):
            with self.subTest(name):
                host = self.frozen()
                if crash:
                    host.crash_after(crash)
                leftover = Path(tempfile.mkdtemp(prefix=production.TOKEN_CHECK_PREFIX,
                                                 dir=production.TOKEN_CHECK_PARENT))
                self.addCleanup(shutil.rmtree, leftover, True)
                (leftover / "config.php").write_text(f"<?php return array ('password' => '{host.token_password}');\n",
                                                     encoding="ascii")
                link = Path(production.TOKEN_CHECK_PARENT) / f"{production.TOKEN_CHECK_PREFIX}link-{uuid.uuid4().hex}"
                link.symlink_to(host.root)
                self.addCleanup(link.unlink, True)
                self.assertEqual("sqlite_authoritative", host.abort()["phase"])
                self.assert_undone(host)
                self.assertEqual((False, True), (leftover.exists(), link.is_symlink()))
                self.assertIn(link.name, host.token_folders())
                self.assertTrue(host.root.is_dir())

    def test_receipt_still_armed_after_publication(self):
        # Design 3.5 (critic RC-L10): the receipt stays armed until the SQLite reopen, so the runner's publication
        # after seal_sqlite still sees real business activity. Here an app write after a boundary lands after the
        # undo and before the publication: the publication refuses, and the next tick records recovery_required.
        host = self.frozen()
        host.ready()
        receipt = host.path("receipt").read_bytes()
        seal = production.ProductionAdapter.seal_sqlite

        def seal_then_write(adapter, lease, deadline):
            seal(adapter, lease, deadline)
            host.business.write_text("an app write after a boundary", encoding="ascii")

        with patch.object(production.ProductionAdapter, "seal_sqlite", seal_then_write):
            with self.assertRaisesRegex(UnsafeCutover, "sealed single-engine route could not be verified"):
                host.abort()
        self.assertEqual(receipt, host.path("receipt").read_bytes())
        self.assertFalse(host.path("evidence").exists())
        with self.assertRaisesRegex(UnsafeCutover, "MySQL writes may exist"):
            host.runner().perform("tick")
        self.assertEqual("recovery_required", host.host()[0].read()["phase"])
        # Without the write the rollback finishes, and the receipt is still there, byte for byte.
        plain = self.frozen()
        plain.ready()
        receipt = plain.path("receipt").read_bytes()
        self.assertEqual("sqlite_authoritative", plain.abort()["phase"])
        self.assertEqual(receipt, plain.path("receipt").read_bytes())

    def test_seal_sqlite_is_safe_to_run_twice_and_resumes_after_a_crash_at_any_step(self):
        steps = dict(production.SEAL_SQLITE_STEPS)
        self.assertEqual(list(range(1, len(steps) + 1)), sorted(steps))
        points = [(f"crash after seal_sqlite step {number} ({steps[number]})",
                   lambda host, number=number: host.crash_rollback_after(number)) for number in range(1, len(steps))]
        points += [(f"kill inside the undo, {point}", lambda host, point=point: host.kill_rollback_inside(point))
                   for point in ROLLBACK_KILL_POINTS]
        for name, stop in points:
            with self.subTest(name):
                host = self.frozen()
                host.ready()
                host.release_scheduler()
                stop(host)
                state = host.host()[0].read()
                self.assertEqual(("ready", "abort"), (state["phase"], state["pending"]["action"]))
                # Wherever it stopped, SQLite is never writable under a MySQL .env or config (review D10R-1).
                self.assertFalse(host.held_on_mysql_switch())
                self.assertEqual("sqlite_authoritative", host.abort()["phase"])
                self.assert_undone(host)
        # Twice is once: seal_sqlite again after a finished rollback changes nothing but its proof.
        host = self.frozen()
        host.ready()
        host.abort()
        env, cache = metadata(host.env), (host.cache.read_bytes(), host.cache.lstat().st_ino)
        mark = len(host.commands())
        host.seal_sqlite()
        self.assertEqual((env, cache), (metadata(host.env), (host.cache.read_bytes(), host.cache.lstat().st_ino)))
        self.assertEqual([["gate-close"], ["systemctl", "try-reload-or-restart"]], host.rollback_effects(mark))
        self.assertTrue(host.record("unseal_proof")["unchanged"])

    def test_the_undo_checks_again_before_it_unseals(self):
        # Step 6 measures the host again after the undo (the deploy user can write .env and config.php at any time):
        # a .env or a live config that reads MySQL again, a cache off the SQLite pin, or a configuration digest
        # that moved refuses before SQLite is unsealed.
        def after_step(name: str, change):
            original = getattr(production.ProductionAdapter, f"_seal_sqlite_{name}")

            def step(adapter, run):
                original(adapter, run)
                change()

            return patch.object(production.ProductionAdapter, f"_seal_sqlite_{name}", step)

        def switch_env(host: UndoHost):
            return lambda: host.env.write_bytes(host.env.read_bytes().replace(b"DB_CONNECTION=sqlite",
                                                                              b"DB_CONNECTION=mysql"))

        def mysql_cache(host: UndoHost):
            return lambda: host.cache.write_bytes(cache_text(config_for({**host.config["mysql"]["switch"],
                                                                         "DB_PASSWORD": host.password})))

        def other_sqlite_cache(host: UndoHost):
            def change():
                settings = env_settings(host.env.read_bytes())
                config = config_for(settings)
                config["cache"] = {**config["cache"], "default": "database"}
                host.cache.write_bytes(cache_text(config))
            return change

        def another_line(host: UndoHost):
            return lambda: host.env.write_bytes(host.env.read_bytes() + b"APP_DEBUG_EXTRA=true\n")

        def no_cache(host: UndoHost):
            return host.cache.unlink

        def linked_env(host: UndoHost):
            def change():
                real = host.app / "env.real"
                host.env.rename(real)
                host.env.symlink_to(real)
            return change

        def no_env(host: UndoHost):
            return host.env.unlink

        for name, prepared, step, change, pattern in (
            ("no prepare: config.php removed after step 4", False, "config", no_cache,
             "not SQLite from the config cache"),
            # "resumed": a rollback that stopped after its step 5 is retried, so this pass builds nothing and the
            # pin is checked for this run's switch alone.
            ("a resumed undo: a SQLite cache off the pin after step 5", "resumed", "fpm", other_sqlite_cache,
             "not the SQLite pin"),
            ("this run's switch: .env made a symbolic link after step 5", True, "fpm", linked_env,
             "cannot be read to undo the switch"),
            ("this run's switch: .env removed after step 5", True, "fpm", no_env,
             "does not hold the switch record's SQLite values"),
            ("no prepare: .env switched after step 3", False, "config", switch_env, "switched by hand"),
            ("this run's switch: .env switched again after step 3", True, "config", switch_env,
             "does not hold the switch record's SQLite values"),
            ("a MySQL cache after the build", True, "config", mysql_cache, "not SQLite from the config cache"),
            ("a SQLite cache off the pin after the build", True, "fpm", other_sqlite_cache, "not the SQLite pin"),
            ("a .env line outside the denylist", True, "fpm", another_line, "configuration digest is not the pin's"),
        ):
            with self.subTest(name):
                host = self.frozen()
                if prepared:
                    host.ready()
                if prepared == "resumed":
                    host.crash_rollback_after(5)
                with after_step(step, change(host)), self.assertRaisesRegex(UnsafeCutover, pattern):
                    host.abort()
                self.assertFalse(sqlite_seal.writable(host.database))
                self.assertFalse(host.path("evidence").exists())
                self.assertIsNone(host.record("unseal_proof"))

    def test_seal_sqlite_needs_its_lease_current_and_time_before_each_step(self):
        host = self.frozen()
        host.ready()
        journal, adapter, _ = host.host()
        before = host.changes(scans=False)
        # A lease whose admitted writers were never drained: step 1 refuses.
        with journal.acquire() as (state, lease):
            journal.write({**state, "generation": lease.generation})
            with self.assertRaisesRegex(UnsafeCutover, "Admitted writers have not been drained"):
                adapter.seal_sqlite(lease, time.time() + 600)
        # No time left: nothing runs.
        with self.assertRaisesRegex(UnsafeCutover, r"seal_sqlite ran out of time before step 1 \(writers drained\)"):
            host.seal_sqlite(deadline=time.time() - 1)
        # The deadline passes during step 2: the time is checked again before step 3, and nothing from it on runs.
        offset = [0.0]
        _, timed, _ = host.host()
        timed.clock = lambda: time.time() + offset[0]
        gate_close = production.ProductionAdapter._seal_sqlite_gate

        def slow_gate(adapter_self, run):
            gate_close(adapter_self, run)
            offset[0] += 120

        with patch.object(production.ProductionAdapter, "_seal_sqlite_gate", slow_gate), host.lease(journal) as lease:
            with self.assertRaisesRegex(UnsafeCutover, r"seal_sqlite ran out of time before step 3 \(env\)"):
                timed.seal_sqlite(lease, time.time() + 60)
        # Another controller took the journal while the gate closed: nothing from step 3 on runs.
        gate_step = production.ProductionAdapter._seal_sqlite_gate

        def gate_then_taken_over(adapter_self, run):
            gate_step(adapter_self, run)
            current = journal.read()
            journal.write({**current, "generation": current["generation"] + 1})

        with patch.object(production.ProductionAdapter, "_seal_sqlite_gate", gate_then_taken_over):
            with self.assertRaisesRegex(UnsafeCutover, "no longer owns the journal generation"):
                host.seal_sqlite()
        after = host.changes(scans=False)
        self.assertEqual({key: before[key] for key in ("env", "config", "records", "effects", "sqlite")},
                         {key: after[key] for key in ("env", "config", "records", "effects", "sqlite")})
        self.assertIsNone(host.record("unseal_proof"))
        self.assertEqual("sqlite_authoritative", host.abort()["phase"])
        self.assert_undone(host)

    def test_rollback_under_a_short_deadline_stops_fpm_without_the_drain(self):
        # The drain deadline item (README "seal_sqlite"): with less than fpm.drain_seconds + 12 s left, a rollback
        # does not start fpm_drain.py (the deadline would kill it and the FPM stop would never run): it stops FPM at
        # once and records outcome "skipped". With time enough, and for a freeze (unchanged), the drain runs first.
        host = self.frozen()
        host.ready()
        journal, adapter, _ = host.host()
        needed = host.config["fpm"]["drain_seconds"] + production.FPM_DRAIN_MARGIN_SECONDS
        mark = len(host.commands())
        with host.lease(journal) as lease:
            record = adapter.drain(lease, time.time() + needed - 5, purpose="rollback")
        self.assertEqual({"purpose": "rollback", "outcome": "skipped", "waited_seconds": 0, "polls": 0},
                         {key: record[key] for key in ("purpose", "outcome", "waited_seconds", "polls")})
        self.assertRegex(record["problem"], rf"fewer than fpm.drain_seconds plus {production.FPM_DRAIN_MARGIN_SECONDS} s")
        self.assertEqual([["fpm-stop"]], host.rollback_effects(mark))
        self.assertFalse(host.fpm_service()["running"])
        # The skip still waits until no writer process remains after the stop (one that exits 1.5 s later here).
        writer = subprocess.Popen([sys.executable, "-I", "-S", "-c", "import time; time.sleep(1.5)",
                                   f"fake-queue-worker-{host.tag}"])
        self.addCleanup(writer.wait)
        self.addCleanup(writer.kill)
        with host.lease(journal) as lease:
            record = adapter.drain(lease, time.time() + needed - 5, purpose="rollback")
        self.assertEqual("skipped", record["outcome"])
        self.assertIsNotNone(writer.poll(), "the skipped drain returned while a writer process was still running")
        # The time left is measured after stop_workers, when the FPM drain would start: worker stops that use up
        # the margin turn a drain that had time into the skip.
        offset = [0.0]
        adapter.clock = lambda: time.time() + offset[0]
        run = adapter._run

        def slow_stop_workers(argv, deadline=None):
            output = run(argv, deadline)
            if [argv] == host.config["commands"]["stop_workers"]:
                offset[0] += 30
            return output

        mark = len(host.commands())
        with patch.object(adapter, "_run", side_effect=slow_stop_workers), host.lease(journal) as lease:
            record = adapter.drain(lease, adapter.clock() + needed + 10, purpose="rollback")
        self.assertEqual("skipped", record["outcome"])
        self.assertEqual([["fpm-stop"]], host.rollback_effects(mark))
        adapter.clock = time.time
        for purpose, seconds in (("rollback", needed + 30), ("freeze", needed - 5)):
            with self.subTest(purpose=purpose, seconds=seconds):
                mark = len(host.commands())
                with host.lease(journal) as lease:
                    record = adapter.drain(lease, time.time() + seconds, purpose=purpose)
                self.assertEqual((purpose, "drained"), (record["purpose"], record["outcome"]))
                self.assertEqual([["fpm-drain"], ["fpm-stop"]], host.rollback_effects(mark))
        # Through the runner: a watchdog tick after 1:00 gets a fresh budget shorter than the drain needs.
        late = self.frozen()
        late.ready()
        started = late.host()[0].read()["started_at"]
        with patch.object(production.Runner, "ROLLBACK_SECONDS", needed - 2):
            state = late.runner_at(started + production.Runner.WINDOW_SECONDS).perform("tick")
        self.assertEqual("sqlite_authoritative", state["phase"])
        self.assertEqual("skipped", state["history"][-1]["fpm_drain"]["outcome"])
        self.assert_undone(late)


@unittest.skipUnless(LINUX_ROOT and PROBE_FIXTURE, "Needs Linux root and the MySQL fixture (REPRO_MYSQL_PROBE_FIXTURE)")
class SealSqliteFixtureTests(unittest.TestCase):
    """seal_sqlite against D04's MySQL 8.4.13 fixture: the real gate close and the real probe's armed receipt.

    One of the fixture classes (fixture_tests(), --fixture-check), as PrepareFixtureTests and EvidenceFixtureTests."""

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
        self.fixture.sql("ALTER USER 'repro_maint'@'localhost' ACCOUNT UNLOCK;"
                         "ALTER USER 'repro_parity_reader'@'localhost' ACCOUNT UNLOCK")
        self.fixture.restore()
        self.assertEqual(self.baseline, self.fixture.snapshot())

    def ready(self) -> UndoHost:
        host = UndoHost(self, fixture=self.fixture)
        host.freeze()
        host.ready()
        return host

    def test_abort_from_ready_closes_the_gate_and_ends_app_sessions(self):
        host = self.ready()
        session = subprocess.Popen([MYSQL_CLIENT, f"--defaults-file={self.fixture.directory / 'repro_app.cnf'}",
                                    "--no-login-paths", "--protocol=SOCKET", f"--socket={self.fixture.socket}",
                                    "-e", "SELECT SLEEP(120)"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.addCleanup(lambda: session.poll() is None and session.kill())
        listed = "SELECT ID FROM performance_schema.processlist WHERE USER = 'repro_app'"
        for _ in range(100):
            if self.fixture.sql(listed).strip():
                break
            time.sleep(0.1)
        [session_id] = [int(line) for line in self.fixture.sql(listed).split()]
        self.assertEqual("sqlite_authoritative", host.abort()["phase"])
        proof = host.record("unseal_proof")
        self.assertEqual(({"before": "closed", "revoked": {}, "killed": [session_id]}, False, True),
                         (proof["gate"], proof["gate_close_skipped"], proof["unchanged"]))
        self.assertEqual("", self.fixture.sql(listed).strip())
        self.assertNotEqual(0, session.wait(timeout=30))
        self.assertEqual(host.original[0], host.env.read_bytes())
        measured = live_config.measure(host.app)
        self.assertEqual(("sqlite", host.sqlite_pin), (measured.engine, measured.live_config_sha256))

    def test_receipt_still_armed_after_publication(self):
        from test_mysql_probe import FOREIGN_WRITE

        # A boundary (an account statement outside lockable_accounts) and an app-schema write after it, after the
        # undo and before the publication: the real probe reads business activity from the armed receipt.
        host = self.ready()
        receipt = host.path("receipt").read_bytes()
        seal = production.ProductionAdapter.seal_sqlite

        def seal_then_write(adapter, lease, deadline):
            seal(adapter, lease, deadline)
            self.fixture.sql("ALTER USER 'repro_parity_reader'@'localhost' ACCOUNT LOCK")
            self.fixture.sql(FOREIGN_WRITE[1], account=FOREIGN_WRITE[0])

        with patch.object(production.ProductionAdapter, "seal_sqlite", seal_then_write):
            with self.assertRaisesRegex(UnsafeCutover, "sealed single-engine route could not be verified"):
                host.abort()
        self.assertEqual(receipt, host.path("receipt").read_bytes())
        self.assertFalse(host.path("evidence").exists())
        with self.assertRaisesRegex(UnsafeCutover, "MySQL writes may exist"):
            host.runner().perform("tick")
        self.assertEqual("recovery_required", host.host()[0].read()["phase"])
        # Without a write: SQLite authority, and the strict reading after it is still made from this run's receipt.
        plain = self.ready()
        receipt = plain.path("receipt").read_bytes()
        self.assertEqual("sqlite_authoritative", plain.abort()["phase"])
        self.assertEqual(receipt, plain.path("receipt").read_bytes())
        code, output = plain.host()[1]._execute(plain.config["commands"]["mysql"])
        reading = json.loads(output)
        self.assertEqual((0, "strict", hashlib.sha256(receipt).hexdigest(), False, False),
                         (code, reading["mode"], reading["receipt"], reading["writable"], reading["business_activity"]))


FIXTURE_CLASSES = (*FIXTURE_CLASSES, SealSqliteFixtureTests)


if __name__ == "__main__":
    if sys.argv[1:] == ["--fixture-check"]:
        raise SystemExit(fixture_check())
    unittest.main()
