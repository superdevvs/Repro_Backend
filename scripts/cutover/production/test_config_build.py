"""Atomic config cache build (design v2, 1.3; build plan D03): a temporary file, its checks, one rename.

- ConfigBuildCommandTests run on any POSIX system as any user.
- ConfigBuildTests need Linux root, runuser and the accounts `nobody` (the deploy user's stand-in) and
  `www-data`. A fixture program stands in for /usr/bin/php (config_build.PHP, patched as the queue tests
  patch adapter.QUEUE_PHP) and runs as `nobody` through design 1.3's exact runuser command.
- RealConfigCacheTests run this checkout's real `php artisan config:cache` in a private app root laid out
  as on production (bootstrap/cache and storage of its own, everything else linked), and read the result
  through a private PHP-FPM pool with scripts/cutover/probe/fpm-engine.php (probe/test_fpm_engine.py's
  recipe). They need root, php, php-fpm and a vendor/ tree (the pinned QA image) and skip elsewhere:

    docker run --rm --pull never --network none --cpus 2 --memory 2g \
      --mount type=bind,source=<checkout>,target=/app,readonly \
      --mount type=volume,source=<vendor volume>,target=/vendor-src,readonly,volume-subpath=vendor \
      --tmpfs /app/vendor:rw,exec,size=400m,mode=0755 --tmpfs /tmp:rw,exec,size=512m,mode=1777 \
      -w /app --entrypoint /bin/sh repro-mysql-rehearsal-qa:20261122 -c \
      'cp -a /vendor-src/. /app/vendor/ && chmod -R a+rX /app/vendor &&
       python3 -B -m unittest discover -s scripts/cutover/production -p test_config_build.py -v'

  (a read-only checkout needs an empty vendor/ folder as the mount point). The design gives no measured
  production hash to compare with (the pin is measured on the host, P3), so the staged build is compared
  with the measured live cache and with what fpm-engine.php prints for it.
"""

from __future__ import annotations

import base64
import json
import os
import pwd
import grp
import re
import shutil
import signal
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
import config_build  # noqa: E402
import live_config  # noqa: E402
from test_adapter import example_config  # noqa: E402
from test_live_config import cache_text, load_vectors  # noqa: E402

APP = HERE.parents[2]
RUNUSER = "/usr/sbin/runuser"
DEPLOY, WEB = "nobody", "www-data"
REAL_PHP = shutil.which("php")
PHP_FPM = next((path for path in ("/usr/local/sbin/php-fpm", "/usr/sbin/php-fpm8.3") if os.path.exists(path)), None)
SQLITE_VECTOR = next(v for v in load_vectors()["vectors"] if v["name"] == "sqlite-production-shape")
MYSQL_VECTOR = next(v for v in load_vectors()["vectors"] if v["name"] == "mysql-socket-with-extras")
NIGHT_FLAGS = {"CRM_OUTBOUND_HOLD": "true", "CRM_DEFER_REPLY_FORWARDING": "true", "CRM_CALENDAR_SYNC_HOLD": "true",
               "PRODUCER_INGRESS_HOLD": "true", "SCHEDULER_HOLD_ENABLED": "true"}


def accounts() -> str | None:
    for name in (DEPLOY, WEB):
        try:
            pwd.getpwnam(name)
        except KeyError:
            return f"needs a {name} account"
    return None


def can_build() -> str | None:
    if sys.platform != "linux" or os.geteuid() != 0:
        return "needs Linux root to run the build as another account"
    if not os.access(RUNUSER, os.X_OK):
        return "needs runuser"
    return accounts()


def can_build_real() -> str | None:
    if can_build() is not None:
        return can_build()
    if REAL_PHP is None or PHP_FPM is None:
        return "needs php and php-fpm"
    if not (APP / "vendor" / "autoload.php").is_file():
        return "needs the vendor/ tree"
    return None


def design_command(app_root, php: str = "/usr/bin/php", owner: str = "maverick") -> list[str]:
    """Design 1.3's config_build command, as host.json holds it; the adapter appends the temporary path ($1)."""
    return [RUNUSER, "-u", owner, "-g", WEB, "--", "/bin/sh", "-c",
            f'umask 0022 && cd {app_root} && exec env APP_CONFIG_CACHE="$1" {php} artisan config:cache',
            "repro-config"]


def host_config(app_root: Path, php: str) -> dict:
    config = example_config()
    config.update(app_root=str(app_root), app_owner=DEPLOY, app_group=WEB)
    config["commands"]["config_build"] = design_command(app_root, php, owner=DEPLOY)
    return config


def running(text: str) -> list[int]:
    """Processes whose command line holds `text`."""
    found = []
    for entry in Path("/proc").iterdir():
        try:
            if entry.name.isdigit() and text.encode() in (entry / "cmdline").read_bytes():
                found.append(int(entry.name))
        except OSError:
            continue
    return found


def state(path: Path) -> tuple:
    info = path.lstat()
    return (info.st_ino, info.st_size, info.st_mtime_ns, info.st_uid, info.st_gid, info.st_mode, path.read_bytes())


@unittest.skipUnless(os.name == "posix", "Host config paths are POSIX absolute paths")
class ConfigBuildCommandTests(unittest.TestCase):
    """commands.config_build must be design 1.3's command exactly: the deploy user, umask 0022, the app root."""

    def test_constants_match_the_adapter(self):
        # Copied, not imported: the adapter imports config_build, so config_build cannot import the adapter.
        import adapter

        self.assertEqual(config_build.CHILD_TAG, adapter.CHILD_TAG)
        self.assertEqual(config_build.SHELL_PATH.pattern, adapter.SHELL_PATH)
        self.assertEqual(config_build.PHP, adapter.QUEUE_PHP)

    def test_production_example_is_the_design_command(self):
        config = example_config()
        self.assertEqual(config["commands"]["config_build"], design_command("/var/www/backend"))
        self.assertEqual(config_build.check_command(config), design_command("/var/www/backend"))

    def test_command_must_be_design_form(self):
        base = design_command("/var/www/backend")
        script = base[8]
        variants = {
            "as root": [RUNUSER, "-u", "root", *base[3:]],
            "as the web user": [RUNUSER, "-u", "www-data", *base[3:]],
            "without the group": [RUNUSER, "-u", "maverick", *base[5:]],
            "another group": [*base[:4], "maverick", *base[5:]],
            "another runuser": ["/sbin/runuser", *base[1:]],
            "through sudo": ["/usr/bin/sudo", "-u", "maverick", *base[5:]],
            "umask 0002": [*base[:8], script.replace("umask 0022", "umask 0002"), base[9]],
            "no umask": [*base[:8], script.replace("umask 0022 && ", ""), base[9]],
            "another directory": [*base[:8], script.replace("cd /var/www/backend", "cd /tmp"), base[9]],
            "another php": [*base[:8], script.replace("/usr/bin/php", "/usr/local/bin/php"), base[9]],
            "another command": [*base[:8], script.replace("config:cache", "config:clear"), base[9]],
            "a second command": [*base[:8], script + "; id", base[9]],
            "no cache path": [*base[:8], script.replace(' APP_CONFIG_CACHE="$1"', ""), base[9]],
            "another name": [*base[:9], "repro"],
            "an extra argument": [*base, "/tmp/x"],
        }
        for name, command in variants.items():
            with self.subTest(name):
                config = example_config()
                config["commands"]["config_build"] = command
                with self.assertRaisesRegex(config_build.BuildFailed, "design 1.3's runuser command"):
                    config_build.check_command(config)
        config = example_config()
        config["app_root"] = "/var/www/my backend"
        config["commands"]["config_build"] = design_command("/var/www/my backend")
        with self.assertRaisesRegex(config_build.BuildFailed, "plain shell word"):
            config_build.check_command(config)


# The stand-in for /usr/bin/php. It runs as the deploy user after the script's `cd <app_root>` (it opens the
# app root's artisan), reads fake-php.json there and writes $APP_CONFIG_CACHE in one of several ways.
FAKE_PHP = """#!{python} -IS
import json, os, sys, time
artisan, command = sys.argv[1:]
open(artisan).close()
assert command == "config:cache", command
with open("fake-php.json") as stream:
    settings = json.load(stream)
target = os.environ["APP_CONFIG_CACHE"]
with open(settings["content"], "rb") as stream:
    content = stream.read()
mode = settings["mode"]
if "record" in settings:
    folder = os.stat(os.path.dirname(target))
    with open(settings["record"], "w") as stream:
        json.dump({{"target": target, "folder_mode": folder.st_mode & 0o7777, "folder_uid": folder.st_uid,
                   "uid": os.getuid(), "gid": os.getgid(), "umask": os.umask(0o022),
                   "environment": dict(os.environ)}}, stream)
if mode == "nothing":
    sys.exit(0)
if mode == "symlink":
    os.symlink(settings["link"], target)
    sys.exit(0)
if mode == "hardlink":
    os.link(settings["link"], target)
    sys.exit(0)
with open(target, "wb") as stream:
    if mode in ("half", "half-then-fail", "half-then-hang"):
        stream.write(content[:len(content) // 2])
        stream.flush()
    else:
        stream.write(content)
if mode == "mode-0600":
    os.chmod(target, 0o600)
if mode == "half-then-hang":
    time.sleep(60)
sys.exit(1 if mode in ("half-then-fail", "full-then-fail") else 0)
"""


@unittest.skipIf(can_build() is not None, can_build() or "")
class ConfigBuildTests(unittest.TestCase):
    def setUp(self):
        self.root = Path(tempfile.mkdtemp(prefix="config-build-"))
        self.addCleanup(shutil.rmtree, self.root)
        self.root.chmod(0o755)
        self.deploy = pwd.getpwnam(DEPLOY)
        self.web_gid = grp.getgrnam(WEB).gr_gid
        self.php = self.root / "php"
        self.php.write_text(FAKE_PHP.format(python=sys.executable))
        self.php.chmod(0o755)
        self.app = self.root / "app"
        self.cache_dir = self.app / "bootstrap" / "cache"
        self.cache_dir.mkdir(parents=True)
        (self.app / "artisan").write_text("<?php\n")
        for path in (self.app, self.app / "bootstrap", self.cache_dir):
            os.chown(path, self.deploy.pw_uid, self.web_gid)
            path.chmod(0o755)
        self.live = self.cache_dir / "config.php"
        self.live.write_bytes(cache_text(SQLITE_VECTOR["config"]))
        os.chown(self.live, self.deploy.pw_uid, self.web_gid)
        self.live.chmod(0o644)
        self.config = host_config(self.app, str(self.php))
        php = patch.object(config_build, "PHP", str(self.php))
        php.start()
        self.addCleanup(php.stop)

    def fake(self, mode: str, content: bytes | None = None, **extra) -> None:
        """What the fake php writes: `content` (default: another valid SQLite config) in the given way."""
        source = self.root / "content.php"
        if content is None:
            changed = json.loads(json.dumps(SQLITE_VECTOR["config"]))
            changed["app"]["key"] = "base64:YW5vdGhlci1rZXktZm9yLXRoZS1uZXctYnVpbGQ="
            content = cache_text(changed)
        source.write_bytes(content)
        source.chmod(0o644)
        settings = self.app / "fake-php.json"
        settings.write_text(json.dumps({"mode": mode, "content": str(source), **extra}))
        settings.chmod(0o644)

    def leftovers(self) -> list[str]:
        return sorted(entry.name for entry in self.cache_dir.iterdir() if entry.name != "config.php")

    def test_failed_build_leaves_live_file_untouched(self):
        before = state(self.live)
        mysql = cache_text(MYSQL_VECTOR["config"])
        cases = {
            "half a file, then exit 1": ("half-then-fail", None, {}, "exited 1"),
            "a whole file, then exit 1": ("full-then-fail", None, {}, "exited 1"),
            "half a file, exit 0": ("half", None, {}, "refused"),
            "no file": ("nothing", None, {}, "wrote no config"),
            "a link to the live file": ("symlink", None, {"link": str(self.live)}, "symbolic link"),
            "a hard link to the live file": ("hardlink", None, {"link": str(self.live)}, "one link"),
            "mode 0600 (not umask 0022)": ("mode-0600", None, {}, "mode 0644"),
            "the other engine": ("valid", mysql, {}, "engine mysql, not sqlite"),
            "an object inside": ("valid", cache_text(SQLITE_VECTOR["config"]).replace(
                b"'jobs'", b"\\App\\Enums\\Table::Jobs", 1), {}, "refused"),
        }
        for name, (mode, content, extra, message) in cases.items():
            with self.subTest(name):
                self.fake(mode, content, **extra)
                with self.assertRaisesRegex(config_build.BuildFailed, message):
                    config_build.build(self.config, expected_engine="sqlite")
                self.assertEqual(state(self.live), before)
                self.assertEqual(self.leftovers(), [])
        with self.subTest("a hash other than the pin"):
            self.fake("valid")
            with self.assertRaisesRegex(config_build.BuildFailed, "not the expected route hash"):
                config_build.build(self.config, expected_engine="sqlite", expected_sha256="0" * 64)
            self.assertEqual((state(self.live), self.leftovers()), (before, []))
        with self.subTest("a file swapped in after the checks"):
            # The deploy user owns the folder: a file renamed over the checked one before root's rename.
            swap = self.cache_dir / "swap.php"
            real_fchmod = os.fchmod

            def fchmod_then_swap(descriptor, mode):
                real_fchmod(descriptor, mode)
                swap.write_bytes(cache_text(MYSQL_VECTOR["config"]))
                temporary = next(path for path in self.cache_dir.iterdir()
                                 if path.name.startswith(".config.php.repro-"))
                os.replace(swap, temporary)

            self.fake("valid")
            with patch.object(config_build.os, "fchmod", side_effect=fchmod_then_swap):
                with self.assertRaisesRegex(config_build.BuildFailed, "replaced while it was checked"):
                    config_build.build(self.config, expected_engine="sqlite")
            self.assertEqual((state(self.live), self.leftovers()), (before, []))
        with self.subTest("a file another account wrote"):
            # The controller expects app_owner's uid; here the build's account (nobody) is not that uid.
            real = pwd.getpwnam
            other = lambda name: real("daemon") if name == DEPLOY else real(name)  # noqa: E731
            self.fake("valid")
            with patch.object(config_build.pwd, "getpwnam", side_effect=other):
                with self.assertRaisesRegex(config_build.BuildFailed, "belong to the deploy user"):
                    config_build.build(self.config, expected_engine="sqlite")
            self.assertEqual((state(self.live), self.leftovers()), (before, []))

    def test_timed_out_build_is_stopped_and_leaves_live_file_untouched(self):
        before = state(self.live)
        self.config["command_seconds"] = 2
        self.fake("half-then-hang")
        started = time.monotonic()
        with self.assertRaisesRegex(config_build.BuildFailed, "timed out"):
            config_build.build(self.config, expected_engine="sqlite")
        self.assertLess(time.monotonic() - started, 30)
        self.assertEqual((state(self.live), self.leftovers()), (before, []))
        # runuser does not pass SIGKILL on: the whole group is gone, not only runuser.
        self.assertEqual(running(str(self.php)), [])

    def test_build_renames_with_owner_and_mode(self):
        # The live file keeps its owner, group and mode, whoever they are; its inode is replaced in one rename.
        os.chown(self.live, 1, self.web_gid)
        self.live.chmod(0o640)
        before = self.live.stat()
        self.fake("valid")
        built = config_build.build(self.config, expected_engine="sqlite")
        after = self.live.stat()
        self.assertNotEqual(after.st_ino, before.st_ino)
        self.assertEqual((after.st_uid, after.st_gid, stat.S_IMODE(after.st_mode)), (1, self.web_gid, 0o640))
        self.assertEqual(self.live.read_bytes(), (self.root / "content.php").read_bytes())
        self.assertEqual(built, live_config.measure(self.app))
        self.assertEqual((built.engine, built.source), ("sqlite", "cache"))
        self.assertNotEqual(built.live_config_sha256, SQLITE_VECTOR["route_sha256"])
        self.assertEqual(self.leftovers(), [])
        # With no live file (or a link there), the new one is the deploy user's, group www-data, 0644.
        for name in ("missing", "a link"):
            with self.subTest(name):
                self.live.unlink()
                if name == "a link":
                    self.live.symlink_to(self.root / "content.php")
                self.fake("valid", cache_text(SQLITE_VECTOR["config"]))
                built = config_build.build(self.config, expected_engine="sqlite",
                                           expected_sha256=SQLITE_VECTOR["route_sha256"])
                info = self.live.lstat()
                self.assertTrue(stat.S_ISREG(info.st_mode))
                self.assertEqual((info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)),
                                 (self.deploy.pw_uid, self.web_gid, 0o644))
                self.assertEqual(built.live_config_sha256, SQLITE_VECTOR["route_sha256"])

    def test_build_removes_temporary_files_an_earlier_build_left(self):
        stale = self.cache_dir / (".config.php.repro-" + "ab" * 16)
        other = self.cache_dir / ".config.php.repro-not-ours"
        for path in (stale, other):
            path.write_text("<?php return array (\n")
            os.chown(path, self.deploy.pw_uid, self.web_gid)
        self.fake("valid")
        config_build.build(self.config, expected_engine="sqlite")
        self.assertEqual(self.leftovers(), [other.name])

    def test_stage_builds_in_a_private_folder_and_keeps_nothing(self):
        before = state(self.live)
        record = self.root / "record.json"
        record.touch()
        os.chown(record, self.deploy.pw_uid, -1)
        self.fake("valid", cache_text(MYSQL_VECTOR["config"]), record=str(record))
        switch = {"DB_CONNECTION": "mysql", "DB_SOCKET": "/run/repro-mysql84-native/mysql.sock"}
        staged = config_build.stage(self.config, expected_engine="mysql", environment=switch)
        self.assertEqual((staged.engine, staged.live_config_sha256), ("mysql", MYSQL_VECTOR["route_sha256"]))
        seen = json.loads(record.read_text())
        target = Path(seen["target"])
        self.assertNotEqual(target.parent, self.cache_dir)
        self.assertEqual((seen["folder_mode"], seen["folder_uid"]), (0o700, self.deploy.pw_uid))
        self.assertEqual((seen["uid"], seen["gid"], seen["umask"]), (self.deploy.pw_uid, self.web_gid, 0o022))
        self.assertEqual({key: seen["environment"].get(key) for key in switch}, switch)
        self.assertEqual(seen["environment"].get("REPRO_CUTOVER_CHILD"), "1")
        self.assertFalse(target.parent.exists())
        self.assertEqual((state(self.live), self.leftovers()), (before, []))
        # The same checks as a build: the wrong engine is refused and nothing is kept.
        with self.assertRaisesRegex(config_build.BuildFailed, "engine mysql, not sqlite"):
            config_build.stage(self.config, expected_engine="sqlite", environment=switch)
        self.assertFalse(Path(json.loads(record.read_text())["target"]).parent.exists())


# ------------------------------------------------------------------ the real `php artisan config:cache`

def private_app_root(work: Path) -> Path:
    """This checkout's app with its own bootstrap/cache and storage, laid out as on production.

    test_queue_flag.py's recipe: artisan, bootstrap/app.php and the FPM probe are copied (PHP resolves
    __DIR__ through links, and Laravel's base path comes from them); everything else links to the
    checkout. The deploy user's stand-in owns the app root and bootstrap/cache, as maverick does.
    """
    deploy, web_gid = pwd.getpwnam(DEPLOY), grp.getgrnam(WEB).gr_gid
    app = work / "app"
    app.mkdir(mode=0o755)
    for entry in APP.iterdir():
        skipped = entry.name in ("bootstrap", "storage", "artisan", "vendor", "scripts")
        if not skipped and not entry.name.startswith(".env"):
            (app / entry.name).symlink_to(entry)
    vendor = APP / "vendor"
    if os.stat(vendor).st_mode & 0o005 == 0o005 and os.stat(vendor / "autoload.php").st_mode & 0o004:
        (app / "vendor").symlink_to(vendor)
    else:
        subprocess.run(["/bin/cp", "-a", "--", f"{vendor}/.", str(app / "vendor")], check=True, timeout=300)
        subprocess.run(["/bin/chmod", "-R", "a+rX", "--", str(app / "vendor")], check=True, timeout=300)
    shutil.copyfile(APP / "artisan", app / "artisan")
    (app / "bootstrap" / "cache").mkdir(mode=0o755, parents=True)
    for name in ("app.php", "providers.php"):
        if (APP / "bootstrap" / name).is_file():
            shutil.copyfile(APP / "bootstrap" / name, app / "bootstrap" / name)
    probe = app / "scripts" / "cutover" / "probe" / "fpm-engine.php"
    probe.parent.mkdir(parents=True)
    shutil.copyfile(APP / "scripts" / "cutover" / "probe" / "fpm-engine.php", probe)
    for folder in ("app/public", "app/private", "framework/cache/data", "framework/sessions", "framework/views",
                   "framework/testing", "logs"):
        (app / "storage" / folder).mkdir(parents=True)
    for path in (app, app / "bootstrap", app / "bootstrap" / "cache", app / "storage", *(app / "storage").rglob("*"),
                 *(app / "scripts").rglob("*"), app / "scripts"):
        os.chown(path, deploy.pw_uid, web_gid, follow_symlinks=False)
        if path.is_dir() and not path.is_symlink():
            path.chmod(0o775 if "storage" in path.parts else 0o755)
    for path in (app / "artisan", *(app / "bootstrap").glob("*.php"), probe):
        path.chmod(0o644)
    return app


def deploy_cache(app: Path, environment: dict | None = None) -> None:
    """`php artisan config:cache` the way a deploy runs it: as the deploy user in the app root, umask 0022."""
    command = [RUNUSER, "-u", DEPLOY, "-g", WEB, "--", "/bin/sh", "-c",
               f"umask 0022 && cd {app} && exec {REAL_PHP} artisan config:cache"]
    environment = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8", **(environment or {})}
    done = subprocess.run(command, env=environment, capture_output=True, text=True, timeout=120)
    if done.returncode != 0:
        raise AssertionError(f"config:cache failed: {done.stdout}{done.stderr}")


@unittest.skipIf(can_build_real() is not None, can_build_real() or "")
class RealConfigCacheTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        sys.path.insert(0, str(APP / "scripts" / "cutover" / "probe"))
        from test_fpm_engine import fastcgi  # The probe's own minimal FastCGI client (experiment E8).
        # The probe's independent stdlib reference of the contract's canonical form and hash.
        from test_route_hash_vectors import route_sha256 as reference_sha256

        cls.fastcgi = staticmethod(fastcgi)
        cls.reference_sha256 = staticmethod(reference_sha256)
        cls.work = Path(tempfile.mkdtemp(prefix="config-build-real-"))
        cls.work.chmod(0o755)
        try:
            cls.app = private_app_root(cls.work)
            www = pwd.getpwnam(WEB)
            data = cls.work / "data"
            data.mkdir(mode=0o755)
            cls.sqlite = data / "database.sqlite"
            with sqlite3.connect(cls.sqlite) as db:
                db.execute("PRAGMA journal_mode = DELETE")
                db.execute("CREATE TABLE probe_rows (id INTEGER PRIMARY KEY)")
            for path in (data, cls.sqlite):
                os.chown(path, www.pw_uid, www.pw_gid)
            cls.socket = cls.work / "mysql" / "mysql.sock"  # Never the production socket (review B7).
            key = "base64:" + base64.b64encode(os.urandom(32)).decode()
            # The production .env's shape (key names measured on 25 and 26 Nov); every value is a fixture's.
            env = (f"APP_NAME=Repro\nAPP_ENV=production\nAPP_KEY={key}\nAPP_DEBUG=false\nAPP_TIMEZONE=UTC\n"
                   f"DB_CONNECTION=sqlite\nDB_DATABASE={cls.sqlite}\nDB_BUSY_TIMEOUT=5000\nDB_JOURNAL_MODE=wal\n"
                   "DB_SYNCHRONOUS=normal\nCACHE_STORE=file\nQUEUE_CONNECTION=database\nSESSION_DRIVER=file\n"
                   "MAIL_MAILER=array\n")
            (cls.app / ".env").write_text(env)
            os.chown(cls.app / ".env", pwd.getpwnam(DEPLOY).pw_uid, www.pw_gid)
            (cls.app / ".env").chmod(0o640)
            deploy_cache(cls.app)
            cls.config = host_config(cls.app, REAL_PHP)
            cls.switch = {"DB_CONNECTION": "mysql", "DB_DATABASE": "repro_dashboard", "DB_SOCKET": str(cls.socket),
                          "DB_USERNAME": "repro_app", "DB_PASSWORD": "fixture-app-password",
                          "DB_COLLATION": "utf8mb4_0900_bin",
                          "REPRO_MYSQL_EXPECTED_SERVER_UUID": "ec219d68-61fd-4a9e-ba98-43ddb4008383"}
            conf = cls.work / "php-fpm.conf"
            conf.write_text(f"""[global]
error_log = {cls.work}/fpm.log
daemonize = no
pid = {cls.work}/fpm.pid
[probe]
user = www-data
group = www-data
listen = {cls.work}/fpm.sock
listen.owner = root
listen.group = root
listen.mode = 0600
pm = static
pm.max_children = 1
clear_env = yes
php_admin_value[opcache.enable] = 0
""")
            cls.fpm = subprocess.Popen([PHP_FPM, "-y", str(conf), "-F"], stdout=subprocess.DEVNULL,
                                       stderr=subprocess.DEVNULL)
            for _ in range(200):
                if (cls.work / "fpm.sock").exists():
                    break
                time.sleep(0.05)
            time.sleep(0.3)
        except BaseException:
            cls.tearDownClass()
            raise

    @classmethod
    def tearDownClass(cls):
        if getattr(cls, "fpm", None) is not None:
            cls.fpm.send_signal(signal.SIGQUIT)
            try:
                cls.fpm.wait(10)
            except subprocess.TimeoutExpired:
                cls.fpm.kill()
        shutil.rmtree(cls.work, ignore_errors=True)

    def setUp(self):
        php = patch.object(config_build, "PHP", REAL_PHP)
        php.start()
        self.addCleanup(php.stop)

    def fpm_engine(self) -> dict:
        """What fpm-engine.php prints from inside the pool, reading the live bootstrap/cache/config.php."""
        script = self.app / "scripts" / "cutover" / "probe" / "fpm-engine.php"
        status, headers, body = self.fastcgi(self.work / "fpm.sock", {
            "SCRIPT_FILENAME": str(script), "SCRIPT_NAME": "/fpm-engine.php", "REQUEST_METHOD": "GET",
            "SERVER_PROTOCOL": "HTTP/1.1", "REQUEST_URI": "/fpm-engine.php"})
        self.assertEqual(headers.get("content-type"), "application/json", body[:500])
        printed = json.loads(body)
        self.assertEqual(printed["process"]["user"], WEB)
        self.assertEqual(printed["config_path"], str(self.app / "bootstrap" / "cache" / "config.php"))
        self.assertTrue(printed["config_cached"])
        self.assertIsNotNone(printed["route_values"], printed["problems"])
        return printed

    def test_staged_sqlite_build_reproduces_measured_hash(self):
        live = live_config.measure(self.app)
        self.assertEqual((live.engine, live.source), ("sqlite", "cache"))
        self.assertEqual(live.values["database.connections"]["sqlite"]["database"], str(self.sqlite))
        staged = config_build.stage(self.config, expected_engine="sqlite")
        self.assertEqual(staged.live_config_sha256, live.live_config_sha256)
        self.assertEqual(staged.values, live.values)
        # No production value exists to compare with: the PHP probe's reading of the same file stands in.
        printed = self.fpm_engine()
        self.assertTrue(printed["ok"], printed["problems"])
        self.assertEqual(printed["engine"], "sqlite")
        self.assertEqual(live_config.canonical(printed["route_values"]), live_config.canonical(live.values))
        self.assertEqual(self.reference_sha256(printed["route_values"]), live.live_config_sha256)
        # The night flags are outside the hash; the rotation of a password too.
        flagged = config_build.stage(self.config, expected_engine="sqlite", environment=dict(NIGHT_FLAGS))
        self.assertEqual(flagged.live_config_sha256, live.live_config_sha256)

    def test_build_replaces_the_live_cache_in_one_rename(self):
        live = self.app / "bootstrap" / "cache" / "config.php"
        before, measured = live.stat(), live_config.measure(self.app)
        built = config_build.build(self.config, expected_engine="sqlite", expected_sha256=measured.live_config_sha256)
        after = live.stat()
        self.assertNotEqual(after.st_ino, before.st_ino)
        self.assertEqual((after.st_uid, after.st_gid, stat.S_IMODE(after.st_mode)),
                         (before.st_uid, before.st_gid, stat.S_IMODE(before.st_mode)))
        self.assertEqual(built, measured)
        self.assertEqual(sorted(path.name for path in live.parent.iterdir()),
                         ["config.php", "packages.php", "services.php"])
        self.assertEqual(self.reference_sha256(self.fpm_engine()["route_values"]), measured.live_config_sha256)

    def test_staged_mysql_build_follows_the_switch_and_ignores_night_flags(self):
        sqlite_hash = live_config.measure(self.app).live_config_sha256
        mysql = config_build.stage(self.config, expected_engine="mysql", environment=self.switch)
        self.assertNotEqual(mysql.live_config_sha256, sqlite_hash)
        connection = mysql.values["database.connections"]["mysql"]
        self.assertEqual((connection["unix_socket"], connection["username"], connection["password_set"]),
                         (str(self.socket), "repro_app", True))
        self.assertEqual(set(mysql.values["database.connections"]),
                         {"mysql", "mysql_cache_locks", "mysql_queue_worker", "mysql_scheduling_cache"})
        rotated = config_build.stage(self.config, expected_engine="mysql",
                                     environment={**self.switch, **NIGHT_FLAGS, "DB_PASSWORD": "another-password"})
        self.assertEqual(rotated.live_config_sha256, mysql.live_config_sha256)
        moved = config_build.stage(self.config, expected_engine="mysql",
                                   environment={**self.switch, "DB_SOCKET": str(self.work / "other.sock")})
        self.assertNotEqual(moved.live_config_sha256, mysql.live_config_sha256)
        # The same MySQL config live: Python's measurement equals the PHP probe's (it cannot connect here).
        self.addCleanup(deploy_cache, self.app)
        deploy_cache(self.app, self.switch)
        self.assertEqual(live_config.measure(self.app).live_config_sha256, mysql.live_config_sha256)
        printed = self.fpm_engine()
        self.assertEqual(printed["engine"], "mysql")
        self.assertEqual(self.reference_sha256(printed["route_values"]), mysql.live_config_sha256)
        self.assertNotIn("fixture-app-password", json.dumps(printed))


if __name__ == "__main__":
    unittest.main()
