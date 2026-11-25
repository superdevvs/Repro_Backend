"""fpm-engine.php under a real PHP-FPM pool (design v2, 5.3; host proof P8 in miniature).

Root starts a private php-fpm master whose pool runs as www-data with clear_env,
builds the config cache the way a deploy does (`php artisan config:cache`, here
into a private path given by APP_CONFIG_CACHE), sends the probe straight to the
pool socket with a minimal FastCGI client (experiment E8's) and checks that:

- the probe runs as the pool user and reports the cached config's engine and database;
- its route values equal what `php artisan cutover:smoke --engine-only --json` prints as
  www-data with the same cache, and what Python builds from the cache file itself with
  a strict var_export reader (never executed), so both languages agree on a real config;
- the database, the cache directory and the app's storage and bootstrap/cache are unchanged;
- root running the file on the CLI is refused before any application code loads;
- root running `cutover:smoke` or `cutover:smoke-fixtures` is refused and changes nothing.

Needs root, php-fpm, www-data and a readable vendor/ (the pinned QA image); skipped
otherwise. Run from the app root: python3 -B -m unittest discover -s scripts/cutover/probe
"""

from __future__ import annotations

import hashlib
import json
import os
import pwd
import re
import shutil
import signal
import socket
import sqlite3
import struct
import subprocess
import tempfile
import time
import unittest
from pathlib import Path

from test_route_hash_vectors import canonical, engine, route_values

APP = Path(__file__).resolve().parents[3]
PROBE = APP / "scripts" / "cutover" / "probe" / "fpm-engine.php"
PHP_FPM = next((path for path in ("/usr/local/sbin/php-fpm", "/usr/sbin/php-fpm8.3") if os.path.exists(path)), None)


def can_run() -> str | None:
    if os.geteuid() != 0:
        return "needs root to start a php-fpm master"
    if PHP_FPM is None or shutil.which("php") is None:
        return "needs php and php-fpm"
    try:
        pwd.getpwnam("www-data")
    except KeyError:
        return "needs a www-data user"
    if not os.access(APP / "vendor" / "autoload.php", os.R_OK):
        return "needs a readable vendor/"
    return None


# ------------------------------------------------------------------ strict reader of var_export output (experiment E3)
TOKEN = re.compile(rb"""\s*(?:(?P<str>'(?:[^'\\]|\\.)*')|(?P<op>=>|[(),;])|(?P<num>-?[0-9]+(?:\.[0-9]+)?(?:E[+-]?[0-9]+)?)|(?P<word>[A-Za-z_\\][A-Za-z0-9_\\:]*|<\?php))""")


def parse_config(raw: bytes):
    items, index = [], 0
    while index < len(raw) and raw[index:].strip() != b"":
        match = TOKEN.match(raw, index)
        if not match or match.end() == index:
            raise ValueError(f"unexpected byte at {index}")
        items.append((match.lastgroup, match.group(match.lastgroup)))
        index = match.end()
    if items[:2] != [("word", b"<?php"), ("word", b"return")]:
        raise ValueError("not a cached config")
    value, index = _value(items, 2)
    if items[index:] != [("op", b";")]:
        raise ValueError("trailing text")
    return value


def _value(items, index):
    kind, text = items[index]
    if kind == "word" and text in (b"array", b"\\array") and items[index + 1] == ("op", b"("):
        result, index = {}, index + 2
        while items[index] != ("op", b")"):
            key, index = _value(items, index)
            if not isinstance(key, (str, int)) or isinstance(key, bool) or items[index] != ("op", b"=>"):
                raise ValueError("malformed array entry")
            value, index = _value(items, index + 1)
            result[str(key)] = value
            if items[index] == ("op", b","):
                index += 1
        return result, index + 1
    if kind == "str":
        return re.sub(rb"\\([\\'])", rb"\1", text[1:-1]).decode("utf-8"), index + 1
    if kind == "num":
        return (float(text) if (b"." in text or b"E" in text) else int(text)), index + 1
    if kind == "word" and text.lower() in (b"true", b"false"):
        return text.lower() == b"true", index + 1
    if kind == "word" and text.upper() == b"NULL":
        return None, index + 1
    raise ValueError(f"value the reader does not accept: {text[:40]!r}")


# ------------------------------------------------------------------ minimal FastCGI client (experiment E8)
def fastcgi(sock_path: Path, params: dict[str, str], timeout: float = 30.0) -> tuple[int, dict[str, str], bytes]:
    def record(kind, content, request_id=1):
        return struct.pack("!BBHHBx", 1, kind, request_id, len(content), 0) + content

    def pair(name, value):
        n, v = name.encode(), value.encode()
        enc = lambda x: bytes([x]) if x < 128 else struct.pack("!I", x | 0x80000000)  # noqa: E731
        return enc(len(n)) + enc(len(v)) + n + v

    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as s:
        s.settimeout(timeout)
        s.connect(str(sock_path))
        payload = b"".join(pair(k, v) for k, v in params.items())
        s.sendall(record(1, struct.pack("!HB5x", 1, 0)) + record(4, payload) + record(4, b"") + record(5, b""))
        out, buf, done = b"", b"", False
        while not done:
            chunk = s.recv(65536)
            if not chunk:
                break
            buf += chunk
            while len(buf) >= 8:
                _, kind, _, length, pad = struct.unpack("!BBHHB", buf[:7])
                if len(buf) < 8 + length + pad:
                    break
                content, buf = buf[8:8 + length], buf[8 + length + pad:]
                if kind == 6:
                    out += content
                if kind == 3:
                    done = True
    head, _, body = out.partition(b"\r\n\r\n")
    headers = {}
    for line in head.decode("latin-1").split("\r\n"):
        name, _, value = line.partition(":")
        headers[name.strip().lower()] = value.strip()
    status = int(headers.get("status", "200").split()[0])
    return status, headers, body


def tree_state(*roots: Path, skip: tuple[str, ...] = ()) -> dict[str, str]:
    state = {}
    for root in roots:
        for path in sorted(root.rglob("*")):
            if path.name in skip:
                continue
            info = path.lstat()
            state[str(path)] = "dir" if path.is_dir() else f"{info.st_size} {info.st_mtime_ns} " + hashlib.sha256(path.read_bytes()).hexdigest()
    return state


@unittest.skipIf(can_run() is not None, can_run() or "")
class FpmEngineProbeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.work = Path(tempfile.mkdtemp(prefix="fpm-engine-"))
        cls.work.chmod(0o755)
        www = pwd.getpwnam("www-data")
        cls.data = cls.work / "data"
        cls.data.mkdir()
        (cls.data / "cache").mkdir()
        cls.sqlite = cls.data / "database.sqlite"
        with sqlite3.connect(cls.sqlite) as db:
            db.execute("PRAGMA journal_mode = DELETE")
            db.execute("CREATE TABLE probe_rows (id INTEGER PRIMARY KEY)")
            db.execute("INSERT INTO probe_rows VALUES (1)")
        for path in (cls.data, cls.data / "cache", cls.sqlite):
            os.chown(path, www.pw_uid, www.pw_gid)
        cls.config = cls.work / "config.php"
        os.chown(cls.work, www.pw_uid, www.pw_gid)
        cls.key = "base64:" + __import__("base64").b64encode(os.urandom(32)).decode()
        environment = {
            "PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": str(cls.work), "APP_ENV": "production", "APP_DEBUG": "false",
            "APP_KEY": cls.key, "APP_CONFIG_CACHE": str(cls.config), "DB_CONNECTION": "sqlite", "DB_DATABASE": str(cls.sqlite),
            "DB_PASSWORD": "fpm-probe-secret-password", "CACHE_STORE": "file", "QUEUE_CONNECTION": "database",
            "LOG_CHANNEL": "stderr", "SESSION_DRIVER": "file", "MAIL_MAILER": "array",
        }
        build = cls.as_www_data(["php", "artisan", "config:cache"], environment)
        if build.returncode != 0 or not cls.config.exists():
            raise RuntimeError(f"config:cache failed: {build.stdout}{build.stderr}")
        os.chown(cls.work, 0, 0)

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
env[APP_CONFIG_CACHE] = {cls.config}
""")
        cls.fpm = subprocess.Popen([PHP_FPM, "-y", str(conf), "-F"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(200):
            if (cls.work / "fpm.sock").exists():
                break
            time.sleep(0.05)
        time.sleep(0.3)
        cls.watched = (cls.data, APP / "storage", APP / "bootstrap" / "cache")
        cls.before = tree_state(*cls.watched)
        cls.status, cls.headers, body = fastcgi(cls.work / "fpm.sock", {
            "SCRIPT_FILENAME": str(PROBE), "SCRIPT_NAME": "/fpm-engine.php", "REQUEST_METHOD": "GET",
            "SERVER_PROTOCOL": "HTTP/1.1", "REQUEST_URI": "/fpm-engine.php"})
        cls.body = body
        cls.after = tree_state(*cls.watched)
        cls.cli = cls.as_www_data(["php", "artisan", "cutover:smoke", "--engine-only", "--json"],
                                  {"PATH": "/usr/local/bin:/usr/bin:/bin", "APP_CONFIG_CACHE": str(cls.config)})

    @classmethod
    def tearDownClass(cls):
        if getattr(cls, "fpm", None) is not None:
            cls.fpm.send_signal(signal.SIGQUIT)
            try:
                cls.fpm.wait(10)
            except subprocess.TimeoutExpired:
                cls.fpm.kill()
        shutil.rmtree(cls.work, ignore_errors=True)

    @staticmethod
    def as_www_data(argv, environment):
        www = pwd.getpwnam("www-data")
        return subprocess.run(["setpriv", f"--reuid={www.pw_uid}", f"--regid={www.pw_gid}", "--clear-groups", *argv],
                              cwd=APP, env=environment, capture_output=True, text=True, timeout=120)

    def probe(self) -> dict:
        self.assertEqual(self.headers.get("content-type"), "application/json", self.body[:500])
        return json.loads(self.body)

    def test_probe_runs_in_the_pool_as_www_data_on_the_cached_config(self):
        out = self.probe()
        self.assertEqual(self.status, 200, out)
        self.assertTrue(out["ok"], out)
        self.assertEqual(out["sapi"], "fpm-fcgi")
        self.assertEqual(out["process"]["user"], "www-data")
        self.assertEqual(out["engine"], "sqlite")
        self.assertEqual(out["database"], str(self.sqlite))
        self.assertTrue(out["config_cached"])
        self.assertEqual(out["config_path"], str(self.config))
        self.assertIn("enabled", out["opcache"])
        self.assertNotIn("fpm-probe-secret-password", self.body.decode())
        self.assertNotIn(self.key, self.body.decode())

    def test_route_values_equal_the_cli_smoke_and_the_python_reading_of_the_cache(self):
        out = self.probe()
        cli = json.loads(self.cli.stdout)
        self.assertEqual(self.cli.returncode, 0, self.cli.stdout + self.cli.stderr)
        self.assertEqual(cli["process"]["user"], "www-data")
        self.assertEqual(canonical(out["route_values"]), canonical(cli["route_values"]))
        config = parse_config(self.config.read_bytes())
        self.assertEqual(engine(config), out["engine"])
        self.assertEqual(canonical(route_values(config)), canonical(out["route_values"]))
        self.assertEqual(out["route_values"]["app.key_sha256"], hashlib.sha256(self.key.encode()).hexdigest())
        self.assertEqual(out["route_values"]["database.connections"]["sqlite"]["database"], str(self.sqlite))

    def test_probe_writes_nothing(self):
        self.probe()
        self.assertEqual(self.before, self.after)
        self.assertEqual(sorted(p.name for p in self.data.iterdir()), ["cache", "database.sqlite"])

    def test_root_on_the_cli_is_refused_before_application_code_loads(self):
        result = subprocess.run(["php", str(PROBE)], cwd=APP, capture_output=True, text=True, timeout=60)
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        out = json.loads(result.stdout)
        self.assertFalse(out["ok"])
        self.assertIn("root", " ".join(out["problems"]))
        self.assertNotIn("route_values", out)

    def test_root_running_the_smoke_commands_is_refused_and_changes_nothing(self):
        # The real effective uid 0, no test seam: what a profile misconfigured as root would do.
        # That the refusal comes before any query is proven by CutoverSmokeTest's query log.
        before = tree_state(self.data)
        environment = {"PATH": "/usr/local/bin:/usr/bin:/bin", "APP_CONFIG_CACHE": str(self.config)}
        for argv in (["cutover:smoke", "--engine-only", "--json"], ["cutover:smoke", "--write", "--json"],
                     ["cutover:smoke-fixtures", "--create", "--json"]):
            with self.subTest(argv=argv):
                result = subprocess.run(["php", "artisan", *argv], cwd=APP, env=environment, capture_output=True, text=True, timeout=120)
                self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
                out = json.loads(result.stdout)
                self.assertFalse(out["ok"])
                self.assertEqual(len(out["problems"]), 1, out)
                self.assertIn(f"refused: {argv[0]} never runs as root", out["problems"][0])
                self.assertNotIn("route_values", out)
        self.assertEqual(before, tree_state(self.data), "a refused command changed the database")


if __name__ == "__main__":
    unittest.main()
