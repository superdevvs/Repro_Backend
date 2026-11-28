"""Tests for the deploy user's queue flag helper (design 5.1, build plan D16).

`sbin/repro-queue-flag pause|resume <connection:queue>` is installed root 0755 at
/usr/local/sbin/repro-queue-flag, and the deploy user reaches it through the one line in
`sudoers/repro-queue-flag`. It loads host.json with the controller's own check (so the queue
commands are www-data through runuser with umask 0022), refuses any queue the host config does not
list, and runs the configured `queue_pause` or `queue_resume` argument list with the queue as `$1`.

The queue commands are design 5.1's exact form, `cd <app_root> && umask 0022 && exec /usr/bin/php
artisan queue:<verb> "$1"`, the only form load_config accepts. The QA image has no /usr/bin/php, so
the fixtures patch adapter.QUEUE_PHP (the helper imports the same adapter module): the helper tests
to a fixture program that records what ran, the Laravel tests to the image's own php.

The Laravel tests run the real `php artisan queue:pause` through the helper and a real
`queue:work` as a member of the www-data group, on Laravel's own file cache layout. They need Linux
root, php and the vendor tree at the repository root (the QA image with the locked vendor volume);
without them they are skipped.
"""

import base64
import contextlib
import hashlib
import importlib.machinery
import importlib.util
import io
import json
import os
import shutil
import sqlite3
import stat
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE.parent), str(HERE)]
import adapter  # noqa: E402
from test_adapter import config_build_command, example_config, example_identity, queue_command  # noqa: E402

HELPER = HERE / "sbin" / "repro-queue-flag"
SUDOERS = HERE / "sudoers" / "repro-queue-flag"
APP = HERE.parents[2]
INSTALLED = "/usr/local/sbin/repro-queue-flag"
USAGE = "Usage: repro-queue-flag pause|resume <connection:queue>"
WWW_DATA = 33
# The deploy user's stand-in: uid 1000 with its own primary group and www-data as a supplementary group,
# like maverick on the production host.
DEPLOY_USER = 1000
# The no-op smoke queue (design 5.3). The tests add it to the host config's queues.
SMOKE_QUEUE = "database:cutover-smoke"
LINUX_ROOT = sys.platform == "linux" and os.geteuid() == 0
PHP = next((path for path in ("/usr/bin/php", "/usr/local/bin/php") if os.access(path, os.X_OK)), None)

# The helper tests' stand-in for /usr/bin/php (adapter.QUEUE_PHP). It runs as www-data after the script's
# `cd <app_root>` (it opens the app root's artisan) and reads the app root's fake-php.json: it saves the
# environment it sees if asked, waits `seconds`, leaves `marker` if named, appends what ran, as whom and with
# which umask to `log`, and exits with `code`. A slow or failing queue command is the same program.
FAKE_PHP = """#!{python} -IS
import json, os, sys, time
artisan, command, queue = sys.argv[1:]
open(artisan).close()
with open("fake-php.json") as stream:
    settings = json.load(stream)
if "environment" in settings:
    with open(settings["environment"], "w") as stream:
        json.dump(dict(os.environ), stream)
time.sleep(settings.get("seconds", 0))
if "marker" in settings:
    open(settings["marker"], "w").close()
mask = os.umask(0)
os.umask(mask)
with open(settings["log"], "a") as stream:
    stream.write(json.dumps([command, queue, os.getuid(), os.getgid(), mask]) + "\\n")
sys.exit(settings.get("code", 0))
"""
# Queues one no-op job as the deploy user: the job a paused worker must leave alone.
DISPATCH = """<?php
$root = $argv[1];
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
dispatch(function () {})->onConnection('database')->onQueue('cutover-smoke');
"""
JOBS_SCHEMA = """
CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, queue VARCHAR NOT NULL, payload TEXT NOT NULL,
                   attempts INTEGER NOT NULL, reserved_at INTEGER, available_at INTEGER NOT NULL,
                   created_at INTEGER NOT NULL);
CREATE INDEX jobs_queue_index ON jobs (queue);
CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, uuid VARCHAR NOT NULL,
                          connection TEXT NOT NULL, queue TEXT NOT NULL, payload TEXT NOT NULL,
                          exception TEXT NOT NULL, failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE UNIQUE INDEX failed_jobs_uuid_unique ON failed_jobs (uuid);
"""


def load_helper():
    """The helper as a module (it has no .py suffix); its main() takes the host config path."""
    loader = importlib.machinery.SourceFileLoader("repro_queue_flag", str(HELPER))
    spec = importlib.util.spec_from_loader(loader.name, loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


def flag_path(data: Path, queue: str) -> Path:
    """Laravel's FileStore path for the pause key: sha1 of the key under two folders from its first hex digits."""
    digest = hashlib.sha1(f"illuminate:queue:paused:{queue}".encode()).hexdigest()
    return data / digest[:2] / digest[2:4] / digest


def owner_and_mode(path: Path) -> tuple[int, int, int]:
    info = path.stat()
    return info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)


class SudoersTests(unittest.TestCase):
    """The deploy user's one sudoers line (design 3.11, 5.1)."""

    def test_sudoers_grants_the_deploy_user_the_helper_only(self):
        text = SUDOERS.read_text(encoding="ascii")
        rules = [line for line in text.splitlines() if line.strip() and not line.lstrip().startswith("#")]
        deploy_user = example_config()["app_owner"]
        # No arguments in the rule: the helper itself refuses every verb and queue it does not know.
        self.assertEqual([f"{deploy_user} ALL=(root) NOPASSWD: {INSTALLED}"], rules)
        # sudo silently skips a file in /etc/sudoers.d whose name holds a dot or ends with "~",
        # and refuses a file whose last line has no newline.
        self.assertNotIn(".", SUDOERS.name)
        self.assertFalse(SUDOERS.name.endswith("~"))
        self.assertTrue(text.endswith("\n"))
        if os.name == "posix":
            self.assertEqual(Path(INSTALLED), load_helper().INSTALLED)


@unittest.skipUnless(sys.platform == "linux", "Runs the helper under the Linux system interpreter")
class HelperProgramTests(unittest.TestCase):
    """The helper as sudo runs it: through its own shebang, with no host config in this container."""

    def program(self, *argv, user=None):
        shebang = HELPER.read_text(encoding="utf-8").splitlines()[0]
        # Linux passes everything after the interpreter as one argument.
        interpreter = shebang[2:].split(" ", 1)
        command = [*interpreter, str(HELPER), *argv]
        if user is not None:
            command = ["/usr/bin/setpriv", f"--reuid={user}", f"--regid={user}", "--clear-groups", *command]
        return subprocess.run(command, capture_output=True, text=True, timeout=60, cwd="/",
                              env={"PATH": "/usr/bin:/bin", "LANG": "C.UTF-8"})

    def test_shebang_is_the_isolated_system_python(self):
        self.assertTrue(HELPER.is_file(), f"{HELPER} is missing")
        self.assertEqual("#!/usr/bin/python3 -IS", HELPER.read_text(encoding="utf-8").splitlines()[0])

    def test_program_prints_usage_for_unknown_arguments(self):
        self.assertTrue(HELPER.is_file(), f"{HELPER} is missing")
        for argv in ((), ("restart", "database:default"), ("pause",), ("--help",)):
            with self.subTest(argv=argv):
                result = self.program(*argv)
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertIn(USAGE, result.stderr)

    def test_refused_unless_root(self):
        # Run directly by the deploy user (no sudo) it changes nothing.
        self.assertTrue(HELPER.is_file(), f"{HELPER} is missing")
        result = self.program("pause", "database:default", user=DEPLOY_USER if os.geteuid() == 0 else None)
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn("repro-queue-flag refused: it runs as root on the Linux host", result.stderr)


@unittest.skipUnless(LINUX_ROOT, "Requires Linux root, runuser and root-owned config files")
class HelperFixture(unittest.TestCase):
    """A root-owned host.json and identity.json under /var/lib, read through guard.read_protected."""

    def setUp(self):
        self.assertTrue(HELPER.is_file(), f"{HELPER} is missing")
        self.helper = load_helper()
        # Protected-file checks reject world-writable parents such as /tmp.
        temporary = tempfile.TemporaryDirectory(prefix="repro-queue-flag-", dir="/var/lib")
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.root.chmod(0o755)
        self.etc = self.root / "etc"
        self.etc.mkdir(mode=0o755)
        self.config_path = self.etc / "host.json"
        self.identity_path = self.etc / "identity.json"

    def write_config(self, config, identity=None):
        config["paths"]["identity_config"] = str(self.identity_path)
        config["paths"]["state"] = str(self.root / "state")
        config["commands"]["identity"] = config["commands"]["identity"][:-1] + [str(self.identity_path)]
        for path, value in ((self.config_path, config),
                            (self.identity_path, example_identity(config) if identity is None else identity)):
            path.write_text(json.dumps(value), encoding="utf-8")
            os.chown(path, 0, 0)
            path.chmod(0o644)
        return config

    def run_helper(self, *argv, umask=0o022):
        """main() in this process, with the parent umask sudo or the watchdog would give it."""
        stdout, stderr = io.StringIO(), io.StringIO()
        previous = os.umask(umask)
        try:
            with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                code = self.helper.main(list(argv), config=self.config_path)
        finally:
            os.umask(previous)
        return code, stdout.getvalue(), stderr.getvalue()


class QueueFlagHelperTests(HelperFixture):
    """Arguments, host config and failures, with a recorder in place of artisan."""

    def setUp(self):
        super().setUp()
        # The queue commands run as www-data and write only here.
        self.www = self.root / "www"
        self.www.mkdir(mode=0o755)
        os.chown(self.www, WWW_DATA, WWW_DATA)
        self.log = self.www / "queue.jsonl"
        self.log.touch(mode=0o644)
        os.chown(self.log, WWW_DATA, WWW_DATA)
        # The fixture app root the queue command's `cd` enters, and the fixture php it runs there.
        self.app = self.root / "app"
        self.app.mkdir(mode=0o755)
        (self.app / "artisan").write_text("<?php // The fixture php reads fake-php.json instead.\n", encoding="ascii")
        self.php_settings()
        self.php = self.root / "bin" / "php"
        self.php.parent.mkdir(mode=0o755)
        self.php.write_text(FAKE_PHP.format(python=sys.executable), encoding="ascii")
        self.php.chmod(0o755)
        php = patch.object(adapter, "QUEUE_PHP", str(self.php))
        php.start()
        self.addCleanup(php.stop)
        self.config = self.write_config(self.recording_config())

    def test_resume_refused_throughout_a_controller_action_but_pause_remains_available(self):
        journal = self.helper.Journal(self.root / "state")
        queue = self.config["queues"][0]
        with journal.acquire():
            self.assertEqual(1, self.run_helper("resume", queue)[0])
            self.assertEqual(0, self.run_helper("pause", queue)[0])
        self.assertEqual(0, self.run_helper("resume", queue)[0])
        self.assertEqual(["queue:pause", "queue:resume"], [row[0] for row in self.commands()])

    def test_resume_refuses_held_and_interrupted_states(self):
        journal = self.helper.Journal(self.root / "state")
        queue = self.config["queues"][0]
        state = {"version": 1, "generation": 1, "phase": "planned", "pending": None, "writes_possible": False}
        for changes in ({"phase": "holding"}, {"phase": "ready"}, {"pending": {"action": "hold"}},
                        {"phase": "recovery_required"}, {"phase": "sqlite_authoritative", "writes_possible": True}):
            journal.write({**state, **changes})
            self.assertEqual(1, self.run_helper("resume", queue)[0], changes)
        journal.path.write_text("null")
        self.assertEqual(1, self.run_helper("resume", queue)[0])
        self.assertEqual([], self.commands())

    def php_settings(self, **settings):
        """What the fixture php does on its next runs; it always records what ran in the command log."""
        path = self.app / "fake-php.json"
        path.write_text(json.dumps({"log": str(self.log), **settings}), encoding="ascii")
        path.chmod(0o644)

    def script(self, verb, *, cd=True, umask=True, php=None):
        """Design 5.1's queue script for this fixture's app root and php, or one with a step left out."""
        steps = [f"cd {self.app}"] * cd + ["umask 0022"] * umask
        return " && ".join([*steps, f'exec {php or self.php} artisan queue:{verb} "$1"'])

    def recording_config(self):
        config = example_config()
        config["app_root"] = str(self.app)
        config["commands"]["config_build"] = config_build_command(self.app)
        for verb in ("pause", "resume"):
            config["commands"][f"queue_{verb}"] = queue_command(verb, script=self.script(verb))
        return config

    def commands(self):
        return [json.loads(line) for line in self.log.read_text(encoding="utf-8").splitlines()]

    def test_queue_not_in_host_config_refused(self):
        queues = example_config()["queues"]
        self.assertNotIn(SMOKE_QUEUE, queues)
        for verb in ("pause", "resume"):
            for queue in (SMOKE_QUEUE, "database:default ", " database:default", "DATABASE:default",
                          "database:Default", "database:default,database:mail", "database", "default", "",
                          "--queue=default", "database:default\n", "database:*", "studio"):
                with self.subTest(verb=verb, queue=queue):
                    code, _, error = self.run_helper(verb, queue)
                    self.assertEqual(1, code, error)
                    self.assertIn("is not a queue in the host config", error)
        self.assertEqual([], self.commands(), "A refused queue must run nothing")
        # Every listed queue runs the host config's command once, as www-data with umask 0022.
        for verb in ("pause", "resume"):
            for queue in queues:
                code, _, error = self.run_helper(verb, queue)
                self.assertEqual(0, code, error)
        self.assertEqual([[f"queue:{verb}", queue, WWW_DATA, WWW_DATA, 0o022]
                          for verb in ("pause", "resume") for queue in queues], self.commands())

    def test_unknown_verb_or_extra_arguments_refused(self):
        for argv in ((), ("pause",), ("restart", "database:default"), ("Pause", "database:default"),
                     ("pause", "database:default", "database:mail"), ("pause", "--", "database:default"),
                     ("--help",), ("pause", "database:default", "--config", str(self.config_path))):
            with self.subTest(argv=argv):
                code, _, error = self.run_helper(*argv)
                self.assertEqual(2, code, error)
                self.assertIn(USAGE, error)
        self.assertEqual([], self.commands())

    def test_host_config_failing_the_controller_check_runs_nothing(self):
        # The helper loads host.json with the controller's own load_config, so it never runs a queue
        # command the controller would refuse, and identity.json is read and checked as well.
        # Each refused queue command differs from the accepted recording one in one thing only.
        def changed(user="www-data", **script):
            config = self.recording_config()
            config["commands"]["queue_pause"] = queue_command("pause", user=user, script=self.script("pause", **script))
            return config

        unknown_key = {**self.recording_config(), "queue_flag": True}
        refused_command = "must run as www-data through runuser with umask 0022"
        cases = (
            ("as the deploy user", changed(user="maverick"), None, refused_command),
            ("without umask 0022", changed(umask=False), None, refused_command),
            ("without the cd to the app root", changed(cd=False), None, refused_command),
            # D16's own fixtures ran an interpreter directly; only the configured php is accepted.
            ("another program than the php", changed(php=sys.executable), None, refused_command),
            ("an unknown key", unknown_key, None, "Host config keys are incomplete or unknown"),
            ("a denylist short of a switch key", self.recording_config(),
             {**example_identity(self.recording_config()), "env_denylist": ["CRM_OUTBOUND_HOLD"]},
             "env_denylist must equal the night flags plus the switch keys"),
        )
        for name, config, identity, message in cases:
            with self.subTest(name):
                self.write_config(config, identity)
                code, _, error = self.run_helper("pause", "database:default")
                self.assertEqual(1, code, error)
                self.assertIn(message, error)
        self.assertEqual([], self.commands())

    def test_unprotected_host_config_refused(self):
        # host.json and identity.json get the root-only chain check the controller gives them.
        def group_writable(path):
            path.chmod(0o664)

        def deploy_user_owned(path):
            os.chown(path, DEPLOY_USER, DEPLOY_USER)

        def symlinked(path):
            target = path.with_name(f"real-{path.name}")
            path.rename(target)
            path.symlink_to(target)

        for path in (self.config_path, self.identity_path):
            for change in (group_writable, deploy_user_owned, symlinked):
                with self.subTest(file=path.name, change=change.__name__):
                    # Start from two regular root-owned files: an earlier symlink must not linger.
                    for leftover in (self.config_path, self.identity_path, *self.etc.glob("real-*")):
                        if leftover.is_symlink() or leftover.name.startswith("real-"):
                            leftover.unlink()
                    self.write_config(self.recording_config())
                    change(path)
                    code, _, error = self.run_helper("pause", "database:default")
                    self.assertEqual(1, code, error)
                    self.assertIn("Unsafe file type, owner or permissions", error)
        self.config_path.unlink()
        code, _, error = self.run_helper("pause", "database:default")
        self.assertEqual(1, code, error)
        self.assertEqual([], self.commands())

    def test_caller_environment_not_passed_on(self):
        # Laravel reads its environment before .env and the cached config: nothing from sudo's or the
        # caller's environment reaches artisan.
        seen = self.www / "environment.json"
        self.php_settings(environment=str(seen))
        caller = {"CACHE_STORE": "array", "APP_ENV": "testing", "PHP_INI_SCAN_DIR": str(self.www),
                  "APP_CONFIG_CACHE": str(self.www / "config.php"), "LANG": "fr_FR.UTF-8"}
        with patch.dict(os.environ, caller):
            code, _, error = self.run_helper("pause", "database:default")
        self.assertEqual(0, code, error)
        environment = json.loads(seen.read_text(encoding="utf-8"))
        self.assertEqual("C.UTF-8", environment.get("LANG"))
        self.assertEqual([], [name for name in caller if name != "LANG" and name in environment])

    def test_failed_or_slow_queue_command_reported(self):
        marker = self.www / "finished"
        for seconds, exit_code, message in ((0, 7, "exited 7"), (3, 0, "timed out after 1 s")):
            with self.subTest(message):
                config = self.recording_config()
                # Shorter than fpm_drain.py's margin, which load_config keeps inside command_seconds; no drain
                # runs here, so the margin is lifted for this timeout alone.
                config["command_seconds"], config["fpm"]["drain_seconds"] = 1, 1
                margin = patch.object(adapter, "FPM_DRAIN_MARGIN_SECONDS", 0)
                margin.start()
                self.addCleanup(margin.stop)
                self.write_config(config)
                self.php_settings(seconds=seconds, code=exit_code, marker=str(marker))
                started = time.monotonic()
                code, _, error = self.run_helper("pause", "database:default")
                self.assertEqual(3, code, error)
                self.assertIn(f"queue:pause database:default {message}", error)
                self.assertLess(time.monotonic() - started, 2.5)
                if seconds:
                    # The whole command is stopped, not only runuser: no late flag write after the report.
                    time.sleep(seconds + 0.5)
                    self.assertFalse(marker.exists(), "The timed-out queue command kept running")
                marker.unlink(missing_ok=True)


@unittest.skipUnless(LINUX_ROOT and PHP is not None and (APP / "vendor" / "autoload.php").is_file(),
                     "Requires Linux root, php and the vendor tree at the repository root (QA image)")
class LaravelQueueFlagTests(HelperFixture):
    """The real `php artisan queue:pause` through the helper, and a real worker as the deploy user."""

    def setUp(self):
        super().setUp()
        self.app = self.private_app_root()
        self.data = self.app / "storage" / "framework" / "cache" / "data"
        queue_folder = self.root / "queue"
        queue_folder.mkdir(mode=0o775)
        os.chown(queue_folder, DEPLOY_USER, WWW_DATA)
        self.jobs = queue_folder / "jobs.sqlite"
        with contextlib.closing(sqlite3.connect(self.jobs)) as connection:
            connection.executescript(JOBS_SCHEMA)
        os.chown(self.jobs, DEPLOY_USER, WWW_DATA)
        self.jobs.chmod(0o664)
        self.dispatch = self.etc / "dispatch.php"
        self.dispatch.write_text(DISPATCH, encoding="ascii")
        self.dispatch.chmod(0o644)
        # Production caches its config (cache.default=file, queue.default=database): so does the fixture.
        environment = {
            "PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": str(self.root), "LANG": "C.UTF-8",
            "APP_ENV": "production", "APP_DEBUG": "false",
            "APP_KEY": "base64:" + base64.b64encode(os.urandom(32)).decode(), "APP_TIMEZONE": "UTC",
            "CACHE_STORE": "file", "QUEUE_CONNECTION": "database", "DB_CONNECTION": "sqlite",
            "DB_DATABASE": str(self.jobs), "LOG_CHANNEL": "stderr", "SESSION_DRIVER": "file", "MAIL_MAILER": "array",
        }
        result = subprocess.run([PHP, "artisan", "config:cache"], cwd=self.app, env=environment, umask=0o022,
                                capture_output=True, text=True, timeout=120)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        config = example_config()
        config["app_root"] = str(self.app)
        config["commands"]["config_build"] = config_build_command(self.app)
        config["queues"].append(SMOKE_QUEUE)
        # The real php stands in for /usr/bin/php (this image has it elsewhere); the rule is otherwise unchanged.
        php = patch.object(adapter, "QUEUE_PHP", PHP)
        php.start()
        self.addCleanup(php.stop)
        for verb in ("pause", "resume"):
            # Design 5.1's command with this fixture's app root and php.
            config["commands"][f"queue_{verb}"] = queue_command(
                verb, script=f'cd {self.app} && umask 0022 && exec {PHP} artisan queue:{verb} "$1"')
        self.write_config(config)

    def private_app_root(self) -> Path:
        """The repository's app with its own bootstrap/cache and storage, laid out as on production.

        bootstrap/app.php and artisan are copied (PHP resolves __DIR__ through symlinks, and Laravel's base
        path comes from them); everything else links to the repository.
        """
        app = self.root / "app"
        app.mkdir(mode=0o755)
        for entry in APP.iterdir():
            if entry.name not in ("bootstrap", "storage", "artisan", "vendor") and not entry.name.startswith(".env"):
                (app / entry.name).symlink_to(entry)
        vendor = APP / "vendor"
        if os.stat(vendor).st_mode & 0o005 == 0o005 and os.stat(vendor / "autoload.php").st_mode & 0o004:
            (app / "vendor").symlink_to(vendor)
        else:
            # The locked dev vendor volume is root-only, while the host's vendor tree is readable by www-data
            # and the deploy user. A copy inside the app root keeps Composer's base directory there.
            subprocess.run(["/bin/cp", "-a", "--", f"{vendor}/.", str(app / "vendor")], check=True, timeout=300)
            subprocess.run(["/bin/chmod", "-R", "a+rX", "--", str(app / "vendor")], check=True, timeout=300)
        shutil.copyfile(APP / "artisan", app / "artisan")
        (app / "bootstrap" / "cache").mkdir(mode=0o755, parents=True)
        for name in ("app.php", "providers.php"):
            if (APP / "bootstrap" / name).is_file():
                shutil.copyfile(APP / "bootstrap" / name, app / "bootstrap" / name)
        storage = app / "storage"
        for folder in ("app/public", "app/private", "framework/cache/data", "framework/sessions",
                       "framework/views", "framework/testing", "logs"):
            (storage / folder).mkdir(parents=True)
        # The cache tree belongs to www-data with 0755 folders, as measured on the production host.
        for path in (storage, *storage.rglob("*")):
            os.chown(path, WWW_DATA, WWW_DATA)
            path.chmod(0o755)
        for path in (app / "artisan", *(app / "bootstrap").glob("*.php")):
            path.chmod(0o644)
        return app

    def as_deploy_user(self, *argv) -> subprocess.CompletedProcess:
        command = ["/usr/bin/setpriv", f"--reuid={DEPLOY_USER}", f"--regid={DEPLOY_USER}", f"--groups={WWW_DATA}",
                   *argv]
        return subprocess.run(command, cwd=self.app, capture_output=True, text=True, timeout=120,
                              env={"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": str(self.root), "LANG": "C.UTF-8"})

    def queue_job(self):
        result = self.as_deploy_user(PHP, str(self.dispatch), str(self.app))
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual(1, self.waiting())

    def work_once(self):
        """One pass of the deploy user's queue worker on the smoke queue."""
        result = self.as_deploy_user(PHP, "artisan", "queue:work", "database", "--queue=cutover-smoke", "--once",
                                     "--sleep=0", "--tries=1")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def waiting(self) -> int:
        with contextlib.closing(sqlite3.connect(self.jobs)) as connection:
            return connection.execute("SELECT count(*) FROM jobs WHERE queue = 'cutover-smoke'").fetchone()[0]

    def pause_resume_round_trip(self, umask):
        """Pause, prove the worker skips the queued job, resume, prove it runs it; return the pause flag."""
        self.queue_job()
        flag = flag_path(self.data, SMOKE_QUEUE)
        self.assertFalse(flag.exists())
        code, output, error = self.run_helper("pause", SMOKE_QUEUE, umask=umask)
        self.assertEqual(0, code, error)
        self.assertIn("paused", output)
        # Laravel's own record: forever (expiry 9999999999) and a serialized true.
        self.assertEqual(b"9999999999b:1;", flag.read_bytes())
        self.assertEqual((WWW_DATA, WWW_DATA, 0o644), owner_and_mode(flag))
        for folder in (flag.parent, flag.parent.parent):
            self.assertEqual((WWW_DATA, WWW_DATA, 0o755), owner_and_mode(folder), folder)
        read = self.as_deploy_user(sys.executable, "-I", "-S", "-c",
                                   "import sys; sys.stdout.write(open(sys.argv[1]).read())", str(flag))
        self.assertEqual((0, "9999999999b:1;"), (read.returncode, read.stdout), read.stderr)
        self.work_once()
        self.assertEqual(1, self.waiting(), "The deploy user's worker ran a job on a paused queue")
        code, output, error = self.run_helper("resume", SMOKE_QUEUE, umask=umask)
        self.assertEqual(0, code, error)
        self.assertIn("resumed", output)
        self.assertFalse(flag.exists())
        self.work_once()
        self.assertEqual(0, self.waiting(), "The deploy user's worker did not run the job after the resume")
        return flag

    def test_flag_file_is_www_data_0644_and_readable_by_group_member(self):
        # As sudo runs it for the deploy user (sudo's default umask 0022).
        self.pause_resume_round_trip(0o022)

    def test_umask_0022_under_a_0077_parent(self):
        # The watchdog unit runs with UMask=0077, which runuser keeps: only the command's own umask 0022
        # gives the deploy user's workers a flag and folders they can read (an unreadable flag is "not paused").
        flag = self.pause_resume_round_trip(0o077)
        self.assertEqual(0o755, stat.S_IMODE(flag.parent.stat().st_mode))


if __name__ == "__main__":
    unittest.main()
