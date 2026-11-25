"""The hold-time Telnyx capture (design v2, 5.2): its files, and the real capture.php.

The file tests run anywhere. CaptureTests run the real capture.php behind the
real nginx hold include (test_nginx_hold.HoldHarness) in its own PHP-FPM
master, against an application checkout with vendor/, as root in a disposable
Linux container (it starts PHP-FPM children and the replay as www-data). The
web user must be able to read vendor/, as on the host; the locked dev vendor
volume is root-only, so copy it into a tmpfs first. For example:

    docker run --rm --pull never --network none --cpus 2 --memory 2g \\
      --mount type=bind,source=<checkout>,target=/app \\
      --mount type=volume,source=<vendor volume>,target=/vendor-src,volume-subpath=vendor,readonly \\
      --tmpfs /app/vendor:rw,exec,size=400m,mode=0755 \\
      --tmpfs /tmp:rw,exec,size=512m,mode=1777 --tmpfs /app/bootstrap/cache:rw,size=64m,mode=0755 \\
      -w /app --entrypoint /bin/sh repro-mysql-rehearsal-qa:20261122 -c \\
      'cp -a /vendor-src/. /app/vendor/ && chmod -R a+rX /app/vendor &&
       python3 -B -m unittest discover -s scripts/cutover/production -p test_capture.py -v'

The class caches the application's config into bootstrap/cache/config.php
(the capture reads that fixed path) and refuses to run if one exists already.
Storage, the SQLite database and the spool live in a temporary directory
(LARAVEL_STORAGE_PATH), so nothing else in the checkout is written.
"""

from __future__ import annotations

import base64
import calendar
import configparser
import fcntl
import grp
import json
import os
import pwd
import re
import shutil
import sqlite3
import stat
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE.parent), str(HERE)]
from test_nginx_hold import HOSTS, SHARED_MMS, HoldHarness, capture_runnable  # noqa: E402

REPOSITORY = HERE.parents[2]
APP = Path(os.environ.get("REPRO_CAPTURE_TEST_APP", str(REPOSITORY)))
CONFIG_CACHE = APP / "bootstrap" / "cache" / "config.php"
PHP = shutil.which("php")
MESSAGING = "/api/webhooks/telnyx/messaging"
CAPTURED = '{"status":"captured"}'
REFUSED = '{"captured":false}'

CAPTURE_DIR = "/run/repro-telnyx-capture"
CAPTURE_SOCKET = f"{CAPTURE_DIR}/php-fpm.sock"
POOL_FILE = "/etc/repro-telnyx-capture/php-fpm.conf"
SETTINGS_FILE = "/etc/repro-telnyx-capture/capture.json"
SCRIPT_FILE = "/opt/repro-telnyx-capture/capture.php"


def unit_entries(path: Path) -> dict[str, list[str]]:
    entries: dict[str, list[str]] = {}
    for line in path.read_text(encoding="ascii").splitlines():
        if line.strip() and not line.startswith(("#", ";", "[")):
            key, _, value = line.partition("=")
            entries.setdefault(key.strip(), []).append(value.strip())
    return entries


class CaptureFileTests(unittest.TestCase):
    def pool(self) -> configparser.RawConfigParser:
        parser = configparser.RawConfigParser(delimiters=("=",), comment_prefixes=(";",), strict=True)
        parser.optionxform = str  # Keys such as env[NAME] are case-sensitive.
        parser.read_string((HERE / "capture" / "php-fpm.conf").read_text(encoding="ascii"))
        return parser

    def test_pool_is_one_static_www_data_pool_with_a_cleared_environment(self):
        pool = self.pool()
        self.assertEqual(["global", "repro-telnyx-capture"], pool.sections())
        self.assertEqual({"pid": f"{CAPTURE_DIR}/php-fpm.pid", "error_log": "syslog",
                          "syslog.ident": "repro-telnyx-capture", "daemonize": "no"}, dict(pool["global"]))
        self.assertEqual({
            "user": "www-data", "group": "www-data",
            "listen": CAPTURE_SOCKET, "listen.owner": "www-data", "listen.group": "www-data", "listen.mode": "0660",
            "pm": "static", "pm.max_children": "4", "request_terminate_timeout": "20s",
            "clear_env": "yes", "env[REPRO_TELNYX_CAPTURE_SETTINGS]": SETTINGS_FILE,
            "security.limit_extensions": ".php",
            "php_admin_flag[display_errors]": "off", "php_admin_flag[log_errors]": "on",
        }, dict(pool["repro-telnyx-capture"]))

    def test_hold_include_reaches_this_pool_and_script(self):
        hold = (HERE / "nginx" / "repro-cutover-hold.conf").read_text(encoding="ascii")
        self.assertEqual([f"unix:{CAPTURE_SOCKET}"], re.findall(r"fastcgi_pass\s+(\S+);", hold))
        self.assertEqual([SCRIPT_FILE], re.findall(r"fastcgi_param\s+SCRIPT_FILENAME\s+(\S+);", hold))

    def test_service_runs_its_own_master_at_boot_and_never_with_the_app_pool(self):
        unit = unit_entries(HERE / "systemd" / "repro-telnyx-capture.service")
        self.assertEqual([f"/usr/sbin/php-fpm8.3 --nodaemonize --fpm-config {POOL_FILE}"], unit["ExecStart"])
        self.assertEqual(["notify"], unit["Type"])
        self.assertEqual(["always"], unit["Restart"])
        self.assertEqual(["multi-user.target"], unit["WantedBy"])
        self.assertIn("systemd-tmpfiles-setup.service", " ".join(unit["After"]).split())
        # Stopping or draining php8.3-fpm (the freeze) must never stop the capture.
        for key in ("PartOf", "BindsTo", "Requires", "Requisite", "Conflicts", "StopWhenUnneeded",
                    "RuntimeDirectory", "ExecStop"):
            self.assertNotIn(key, unit)
        for key, values in unit.items():
            if key != "ExecStart":
                self.assertNotIn("php8.3-fpm", " ".join(values), key)

    def test_boot_recreates_the_socket_directory(self):
        lines = [line.split() for line in (HERE / "tmpfiles.d" / "repro-cutover-fence.conf").read_text(
            encoding="ascii").splitlines() if line.strip() and not line.startswith("#")]
        self.assertIn(["d", CAPTURE_DIR, "0755", "root", "root", "-"], lines)

    def test_settings_name_the_release_and_the_marker_nginx_reads(self):
        import cli

        settings = json.loads((HERE / "capture" / "capture.example.json").read_text(encoding="utf-8"))
        self.assertEqual({"app_root": "/var/www/backend", "ingress_open_marker": cli.INGRESS_OPEN.as_posix()}, settings)
        hold = (HERE / "nginx" / "repro-cutover-hold.conf").read_text(encoding="ascii")
        self.assertIn(f"if (-f {settings['ingress_open_marker']})", hold)

    def test_sqlite_profiles_add_the_replay_and_the_smoke_to_todays_profiles(self):
        today = json.loads((HERE / "wrappers.example.json").read_text(encoding="utf-8"))
        sqlite = json.loads((HERE / "wrappers.sqlite.example.json").read_text(encoding="utf-8"))
        self.assertEqual(today["version"], sqlite["version"])
        added = {name: profile for name, profile in sqlite["profiles"].items() if name not in today["profiles"]}
        self.assertEqual(today["profiles"], {name: profile for name, profile in sqlite["profiles"].items()
                                             if name in today["profiles"]})
        self.assertEqual({
            "telnyx-replay": {
                "action": "queue-worker", "engine": "sqlite", "user": "www-data", "group": "www-data",
                "cwd": "/var/www/backend", "umask": "0002", "env": {}, "timeout_seconds": 600,
                "argv": ["/usr/bin/php", "/var/www/backend/artisan", "telnyx:replay-spool"]},
            "cutover-smoke": {
                "action": "queue-worker", "engine": "sqlite", "user": "www-data", "group": "www-data",
                "cwd": "/var/www/backend", "umask": "0022", "env": {}, "timeout_seconds": 300,
                "argv": ["/usr/bin/php", "/var/www/backend/artisan", "cutover:smoke", "--write", "--json"]},
        }, added)
        # The replay refuses to run under another group, and its files must stay group-writable for
        # PHP-FPM (ReplayTelnyxSpool: 'Run it with the www-data group and umask 002').
        command = (REPOSITORY / "app" / "Console" / "Commands" / "ReplayTelnyxSpool.php").read_text(encoding="utf-8")
        self.assertIn("protected $signature = 'telnyx:replay-spool ", command)
        services = (REPOSITORY / "config" / "services.php").read_text(encoding="utf-8")
        self.assertIn("'replay_group' => env('TELNYX_SPOOL_REPLAY_GROUP', 'www-data')", services)

    @unittest.skipUnless(os.name == "posix", "The wrapper reads POSIX accounts")
    def test_every_sqlite_profile_loads(self):
        import admit

        raw = (HERE / "wrappers.sqlite.example.json").read_bytes()
        with patch.object(admit.pwd, "getpwnam", return_value=type("Account", (), {"pw_uid": 1000})()), \
                patch.object(admit.grp, "getgrnam", return_value=type("Group", (), {"gr_gid": 33})()):
            for name in json.loads(raw)["profiles"]:
                with self.subTest(profile=name):
                    admit.load_profile(name, raw)


def capture_skip_reason() -> str | None:
    if not capture_runnable():
        return "Runs nginx, PHP-FPM and the replay as www-data: Linux root in a disposable container"
    if PHP is None or not (APP / "vendor" / "autoload.php").is_file():
        return f"Requires php and an application checkout with vendor/ at {APP}"
    if not os.access(CONFIG_CACHE.parent, os.W_OK):
        return f"Requires a writable {CONFIG_CACHE.parent} (for example a tmpfs)"
    return None


READBACK = """<?php
// Reads the spool as telnyx:replay-spool does: the booted app and TelnyxMaintenanceSpool::fromConfig().
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$spool = App\\Support\\TelnyxMaintenanceSpool::fromConfig();
$out = ['root' => $spool->root()];
foreach (['texts', 'calls', 'replayed', 'rejected'] as $folder) {
    $out[$folder] = array_map(fn (array $entry) => [
        'id' => $entry['id'], 'readable' => $entry['readable'], 'route' => $entry['route'] ?? null,
        'route_key' => $entry['route_key'] ?? null, 'headers' => $entry['headers'] ?? null,
        'body' => $entry['body'] ?? null,
        'arrived_at' => isset($entry['arrived_at']) ? $entry['arrived_at']->format('Y-m-d\\TH:i:s.u\\Z') : null,
    ], $spool->entries($folder));
}
echo json_encode($out);
"""

SIGN = "echo base64_encode(sodium_crypto_sign_detached($argv[1].'|'.stream_get_contents(STDIN), base64_decode($argv[2])));"


@unittest.skipIf(capture_skip_reason() is not None, capture_skip_reason() or "")
class CaptureTests(unittest.TestCase):
    """The real capture.php behind the real hold include, against the application's own spool code."""

    @classmethod
    def setUpClass(cls):
        if CONFIG_CACHE.exists():
            raise AssertionError(f"{CONFIG_CACHE} exists; this test caches its own config and would replace it")
        cls.www = pwd.getpwnam("www-data")
        # As on the host, the web user must read the release (a root-only vendor volume cannot be used).
        readable = subprocess.run([PHP, "-r", "exit(is_readable($argv[1]) ? 0 : 1);", str(APP / "vendor" / "autoload.php")],
                                  user=cls.www.pw_uid, group=cls.www.pw_gid, capture_output=True)
        if readable.returncode != 0:
            raise AssertionError(f"www-data cannot read {APP / 'vendor' / 'autoload.php'}")
        cls.work = Path(tempfile.mkdtemp(prefix="repro-capture-"))
        cls.work.chmod(0o755)
        storage = cls.work / "storage"
        for folder in ("app", "framework/cache/data", "framework/sessions", "framework/views", "framework/testing", "logs"):
            (storage / folder).mkdir(parents=True)
        cls.database = cls.work / "db" / "database.sqlite"
        cls.database.parent.mkdir()
        cls.database.touch()
        cls.spool = storage / "app" / "telnyx-spool"
        cls.public_key, cls.secret_key = json.loads(cls.php_run(
            "$k = sodium_crypto_sign_keypair(); echo json_encode([base64_encode(sodium_crypto_sign_publickey($k)),"
            " base64_encode(sodium_crypto_sign_secretkey($k))]);"))
        cls.readback = cls.work / "readback.php"
        cls.readback.write_text(READBACK, encoding="ascii")
        # The application as production runs it: cached config, no file-backed drivers in the way,
        # the database queue. Only the storage path (test-only) comes from the environment at run time.
        cls.runtime_env = {"LARAVEL_STORAGE_PATH": str(storage)}
        env = {"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": "/tmp", **cls.runtime_env,
               "APP_ENV": "production", "APP_DEBUG": "false",
               "APP_KEY": "base64:" + base64.b64encode(os.urandom(32)).decode(),
               "DB_CONNECTION": "sqlite", "DB_DATABASE": str(cls.database), "CACHE_STORE": "array",
               "QUEUE_CONNECTION": "database", "MAIL_MAILER": "array", "SESSION_DRIVER": "array",
               "BROADCAST_CONNECTION": "null", "LOG_CHANNEL": "stderr", "PULSE_ENABLED": "false",
               "TELESCOPE_ENABLED": "false", "TELNYX_PUBLIC_KEY": cls.public_key,
               "TELNYX_WEBHOOK_TOLERANCE_SECONDS": "300", "TELNYX_MAINTENANCE_SPOOL_PATH": str(cls.spool)}
        cls.created_cache = True
        for command in (["config:cache"], ["migrate", "--force"]):
            subprocess.run([PHP, "artisan", *command], cwd=APP, env=env, check=True, capture_output=True, timeout=600)
        os.chmod(CONFIG_CACHE, 0o644)
        for path in (storage, cls.database.parent):
            for folder, _, files in os.walk(path):
                for name in [folder, *(os.path.join(folder, file) for file in files)]:
                    os.chown(name, cls.www.pw_uid, cls.www.pw_gid)

    @classmethod
    def tearDownClass(cls):
        if getattr(cls, "created_cache", False):
            CONFIG_CACHE.unlink(missing_ok=True)
        shutil.rmtree(cls.work, ignore_errors=True)

    @staticmethod
    def php_run(code: str, *args: str, stdin: bytes = b"") -> str:
        return subprocess.run([PHP, "-r", code, *args], input=stdin, capture_output=True, check=True).stdout.decode()

    def setUp(self):
        shutil.rmtree(self.spool, ignore_errors=True)
        self.hold = HoldHarness(self, HERE / "capture" / "capture.php")
        self.write_settings({"app_root": str(APP), "ingress_open_marker": str(self.hold.marker)})
        self.hold.start()

    def write_settings(self, settings: dict) -> None:
        self.hold.settings.write_text(json.dumps(settings), encoding="utf-8")
        self.hold.settings.chmod(0o644)

    def sign(self, body: bytes, timestamp: str) -> str:
        return self.php_run(SIGN, timestamp, self.secret_key, stdin=body)

    @staticmethod
    def event(event_id: str, kind: str, payload: dict) -> bytes:
        return json.dumps({"data": {"id": event_id, "event_type": kind, "payload": payload}}).encode()

    def text(self, event_id: str, message_id: str, words: str) -> bytes:
        return self.event(event_id, "message.received", {
            "id": message_id, "from": {"phone_number": "+12025550177"},
            "to": [{"phone_number": "+18883426998"}], "text": words})

    def post(self, path: str, body: bytes, *, host: str = HOSTS[0], timestamp: str | None = None,
             signature: str | None = None, omit: tuple = ()) -> tuple[int, str | None, str]:
        timestamp = str(int(time.time())) if timestamp is None else timestamp
        headers = {"Telnyx-Signature-Ed25519": self.sign(body, timestamp) if signature is None else signature,
                   "Telnyx-Timestamp": timestamp}
        for name in omit:
            headers.pop(name)
        return self.hold.request(host, "POST", path, body, headers, timeout=30)

    def read_spool(self) -> dict:
        done = subprocess.run([PHP, str(self.readback), str(APP)], cwd=APP, env={
            "PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": self.www.pw_dir, **self.runtime_env},
            user=self.www.pw_uid, group=self.www.pw_gid, capture_output=True, text=True, timeout=60)
        self.assertEqual(0, done.returncode, (done.stdout + done.stderr)[-2000:])
        return json.loads(done.stdout)

    def assertNothingSaved(self):
        spool = self.read_spool()
        self.assertEqual(([], []), (spool["texts"], spool["calls"]))

    def test_capture_saves_to_receiver_spool_in_arrival_order(self):
        sent = [
            (HOSTS[0], MESSAGING + "?source=telnyx", self.text("evt-1", "msg-1", "first")),
            (HOSTS[1], "/api/webhooks/telnyx/status", self.event("evt-2", "message.finalized", {"id": "msg-out-2"})),
            (HOSTS[0], SHARED_MMS, self.text("evt-3", "msg-3", "third")),
        ]
        started = time.time()
        signed = []
        for host, path, body in sent:
            stamp = str(int(time.time()))
            signature = self.sign(body, stamp)
            signed.append({"telnyx-signature-ed25519": signature, "telnyx-timestamp": stamp})
            # Texts are acknowledged only once saved, so Telnyx stops retrying them.
            self.assertEqual((200, None, CAPTURED), self.post(path, body, host=host, timestamp=stamp, signature=signature))
        call = self.event("evt-4", "call.initiated", {"call_control_id": "v3:call"})
        # Call events are kept for the missed-call list; the provider's retry and failover stay.
        self.assertEqual((503, "30", '{"captured":"call"}'), self.post("/api/webhooks/telnyx/voice", call, host=HOSTS[1]))
        finished = time.time()

        spool = self.read_spool()
        texts = spool["texts"]
        self.assertEqual(str(self.spool), spool["root"])
        self.assertEqual([True] * 3, [entry["readable"] for entry in texts])
        self.assertEqual(["messaging", "status", "shared-mms"], [entry["route"] for entry in texts])
        self.assertEqual([None, None, SHARED_MMS.rsplit("/", 1)[1]], [entry["route_key"] for entry in texts])
        self.assertEqual([body.decode() for _, _, body in sent], [entry["body"] for entry in texts])
        self.assertEqual(signed, [entry["headers"] for entry in texts])
        arrivals = [entry["arrived_at"] for entry in texts]
        self.assertEqual(sorted(arrivals), arrivals)
        self.assertEqual(3, len(set(arrivals)))
        for arrival in arrivals:
            seconds = calendar.timegm(time.strptime(arrival[:19], "%Y-%m-%dT%H:%M:%S"))
            self.assertLessEqual(int(started) - 1, seconds)
            self.assertLessEqual(seconds, finished + 1)
        self.assertEqual([("voice", "evt-4")], [(entry["route"], json.loads(entry["body"])["data"]["id"])
                                               for entry in spool["calls"]])
        self.assertEqual(([], []), (spool["replayed"], spool["rejected"]))
        # The receiver's layout: folders 2770 and entries 0640, owned by the web user.
        for folder in (self.spool, self.spool / "texts", self.spool / "calls"):
            info = folder.stat()
            self.assertEqual((0o2770, self.www.pw_uid, self.www.pw_gid),
                             (stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid), folder)
        for entry in [*(self.spool / "texts").iterdir(), *(self.spool / "calls").iterdir()]:
            info = entry.stat()
            self.assertEqual((0o640, self.www.pw_uid), (stat.S_IMODE(info.st_mode), info.st_uid), entry.name)

    def test_unverified_requests_are_refused_and_nothing_is_saved(self):
        body = self.text("evt-x", "msg-x", "not saved")
        now = int(time.time())
        flipped = bytearray(base64.b64decode(self.sign(body, str(now))))
        flipped[0] ^= 1
        cases = {
            "signature over another body": {"signature": self.sign(self.text("evt-y", "msg-y", "other"), str(now))},
            "signature with one bit changed": {"signature": base64.b64encode(bytes(flipped)).decode()},
            "signature not base64": {"signature": "not base64!"},
            "stale timestamp": {"timestamp": str(now - 400)},
            "future timestamp": {"timestamp": str(now + 400)},
            "timestamp not digits": {"timestamp": f"{now}.5"},
            "no signature header": {"omit": ("Telnyx-Signature-Ed25519",)},
            "no timestamp header": {"omit": ("Telnyx-Timestamp",)},
        }
        for label, options in cases.items():
            with self.subTest(label):
                self.assertEqual((503, "30", REFUSED), self.post(MESSAGING, body, **options))
        unnamed = {
            "no data.id": json.dumps({"data": {"event_type": "message.received"}}).encode(),
            "empty data.id": json.dumps({"data": {"id": "", "event_type": "message.received"}}).encode(),
            "numeric data.id": json.dumps({"data": {"id": 7, "event_type": "message.received"}}).encode(),
            "not JSON": b"hello",
            "empty body": b"",
        }
        for label, signed_body in unnamed.items():
            with self.subTest(label):
                self.assertEqual((503, "30", REFUSED), self.post(MESSAGING, signed_body))
        status, _, answer = self.hold.request(HOSTS[0], "GET", MESSAGING)
        self.assertEqual((405, REFUSED), (status, answer))
        self.assertNothingSaved()

    def test_capture_fails_closed_without_its_settings_or_the_cached_config(self):
        body = self.text("evt-z", "msg-z", "not saved")
        marker = str(self.hold.marker)
        cases = (
            ("unknown settings key", {"app_root": str(APP), "ingress_open_marker": marker, "spool": "/tmp"}),
            ("relative app root", {"app_root": "backend", "ingress_open_marker": marker}),
            ("relative marker", {"app_root": str(APP), "ingress_open_marker": "open"}),
            ("release without vendor", {"app_root": str(self.hold.root), "ingress_open_marker": marker}),
        )
        for label, settings in cases:
            with self.subTest(label):
                self.write_settings(settings)
                self.assertEqual((503, "30", REFUSED), self.post(MESSAGING, body))
        self.hold.settings.unlink()
        self.assertEqual((503, "30", REFUSED), self.post(MESSAGING, body))
        self.write_settings({"app_root": str(APP), "ingress_open_marker": marker})
        moved = CONFIG_CACHE.with_name("config.php.away")
        CONFIG_CACHE.rename(moved)
        try:
            self.assertEqual((503, "30", REFUSED), self.post(MESSAGING, body))
        finally:
            moved.rename(CONFIG_CACHE)
        self.assertNothingSaved()
        self.assertEqual((200, None, CAPTURED), self.post(MESSAGING, body))

    def wait_for_lock_waiter(self, lock: Path) -> None:
        inode = lock.stat().st_ino
        deadline = time.monotonic() + 15
        while time.monotonic() < deadline:
            for line in Path("/proc/locks").read_text().splitlines():
                fields = line.split()
                if "->" in fields and "FLOCK" in fields and any(field.endswith(f":{inode}") for field in fields):
                    return
            time.sleep(0.02)
        self.fail("The capture never waited for the spool's capture lock")

    def test_capture_refuses_after_marker_appears_under_lock(self):
        self.assertEqual((200, None, CAPTURED), self.post(MESSAGING, self.text("evt-before", "msg-before", "saved")))
        lock = self.spool / ".capture.lock"
        # A replay takes the capture lock exclusively. A capture that reaches the lock while the
        # replay holds it must look for the marker only once it has the lock.
        holder = os.open(lock, os.O_RDONLY)
        fcntl.flock(holder, fcntl.LOCK_EX)
        answer = {}
        late = self.text("evt-late", "msg-late", "arrived as the site opened")
        sending = threading.Thread(target=lambda: answer.update(result=self.post(MESSAGING, late, host=HOSTS[1])))
        try:
            sending.start()
            self.wait_for_lock_waiter(lock)
            self.hold.marker.write_text('{"engine": "sqlite"}', encoding="ascii")
        finally:
            fcntl.flock(holder, fcntl.LOCK_UN)
            os.close(holder)
            sending.join(30)
        # Neither saved nor acknowledged: Telnyx retries, and nginx now sends it to the app.
        self.assertEqual((503, "30", REFUSED), answer.get("result"))
        self.assertEqual(["evt-before"], [json.loads(entry["body"])["data"]["id"] for entry in self.read_spool()["texts"]])
        self.assertEqual((200, None, "api"), self.post(MESSAGING, late))

    def test_captured_text_replays_through_the_receivers_replay_command(self):
        import admit

        words = f"Lockbox is on the side door {time.time()}"
        body = self.text("evt-e2e", "msg-e2e", words)
        self.assertEqual((200, None, CAPTURED), self.post(MESSAGING, body, host=HOSTS[1]))
        arrived = self.read_spool()["texts"][0]["arrived_at"]
        self.hold.marker.write_text('{"engine": "sqlite"}', encoding="ascii")

        # Pass 2 of a reopen, as the controller runs it: admit.py telnyx-replay's user, group, umask,
        # working directory, environment and command. Only the release path differs.
        profile = json.loads((HERE / "wrappers.sqlite.example.json").read_text(encoding="utf-8"))["profiles"]["telnyx-replay"]
        argv = [part.replace("/var/www/backend", str(APP)) for part in profile["argv"]]
        if not os.path.exists(argv[0]):
            argv[0] = PHP  # The QA image has /usr/local/bin/php only.
        account = pwd.getpwnam(profile["user"])
        group = grp.getgrnam(profile["group"]).gr_gid

        def replay() -> subprocess.CompletedProcess:
            return subprocess.run(
                argv, cwd=profile["cwd"].replace("/var/www/backend", str(APP)), stdin=subprocess.DEVNULL,
                env={**admit.environment(profile, account), **self.runtime_env}, user=account.pw_uid, group=group,
                extra_groups=os.getgrouplist(account.pw_name, group), umask=int(profile["umask"], 8),
                capture_output=True, text=True, timeout=profile["timeout_seconds"])

        done = replay()
        self.assertEqual(0, done.returncode, done.stdout + done.stderr)
        self.assertIn("replayed 1, duplicates 0, set aside 0, failed 0", done.stdout)
        with sqlite3.connect(self.database) as connection:
            rows = connection.execute("SELECT direction, from_address, body_text, sent_at FROM messages "
                                      "WHERE provider_message_id = 'msg-e2e'").fetchall()
        # The app's normal inbound handler stored it, at its arrival time.
        self.assertEqual([("INBOUND", "+12025550177", words, arrived[:19].replace("T", " "))], rows)
        spool = self.read_spool()
        self.assertEqual(([], ["evt-e2e"]), (spool["texts"], [json.loads(entry["body"])["data"]["id"]
                                                              for entry in spool["replayed"]]))
        again = replay()
        self.assertEqual(0, again.returncode, again.stdout + again.stderr)
        self.assertIn("replayed 0, duplicates 0, set aside 0, failed 0", again.stdout)


if __name__ == "__main__":
    unittest.main()
