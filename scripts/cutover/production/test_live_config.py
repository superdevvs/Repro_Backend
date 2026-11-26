"""Live config module and the strict var_export reader (design v2, 1.3; build plan D03).

The route hash follows the contract D09 defined in testdata/route-hash-vectors.json, the one copy the PHP
probes and this module read. Every test here runs as any user on any POSIX system; the vector test that
renders the configs with PHP's own var_export needs `php` and skips without it. The tests against a real
`php artisan config:cache` and PHP-FPM are in test_config_build.py (RealConfigCacheTests).

Run from the app root: python3 -B -m unittest discover -s scripts/cutover/production -p test_live_config.py
"""

from __future__ import annotations

import copy
import hashlib
import io
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE)]
import live_config  # noqa: E402
import var_export  # noqa: E402

APP = HERE.parents[2]
VECTORS = HERE / "testdata" / "route-hash-vectors.json"
PHP = shutil.which("php")
NIGHT_FLAGS = {"crm": {"outbound_hold": True, "calendar_sync_hold": True, "inbound": {"defer_reply_forwarding": True}},
               "producer_replay": {"ingress_hold": True}, "schedule": {"hold": {"enabled": True}}}


def objects(value):
    """A PHP array is a map with string keys, as the reader returns it: a JSON list is the map of its indexes."""
    if isinstance(value, list):
        return {str(index): objects(item) for index, item in enumerate(value)}
    if isinstance(value, dict):
        return {str(key): objects(item) for key, item in value.items()}
    return value


def php_float(number: float) -> str:
    text = repr(number)
    if "e" in text:
        mantissa, exponent = text.split("e")
        mantissa += "" if "." in mantissa else ".0"
        return f"{mantissa}E{'-' if exponent.startswith('-') else '+'}{int(exponent.lstrip('+-'))}"
    return text if "." in text else text + ".0"


def php_export(value, indent: str = "") -> str:
    """PHP 8.3's var_export layout, for configs written as Python values (lists become index maps)."""
    if isinstance(value, (dict, list)):
        items = value.items() if isinstance(value, dict) else enumerate(value)
        lines = ["array ("]
        for key, item in items:
            key = str(key)
            shown = key if re.fullmatch(r"-?(?:0|[1-9][0-9]*)", key) else php_string(key)
            if isinstance(item, (dict, list)):
                lines.append(f"{indent}  {shown} => \n{indent}  {php_export(item, indent + '  ')},")
            else:
                lines.append(f"{indent}  {shown} => {php_export(item)},")
        return "\n".join(lines + [indent + ")"])
    if isinstance(value, bool):
        return "true" if value else "false"
    if value is None:
        return "NULL"
    if isinstance(value, int):
        return str(value)
    if isinstance(value, float):
        return php_float(value)
    return php_string(value)


def php_string(text: str) -> str:
    return "'" + text.replace("\\", "\\\\").replace("'", "\\'") + "'"


def cache_text(config) -> bytes:
    return ("<?php return " + php_export(config) + ";\n").encode("utf-8")


# bootstrap/cache/config.php as PHP 8.3's var_export writes it, with the values that are easy to get wrong:
# a key text inside a multi-line string, escaped quotes and backslashes, integer and negative keys, floats
# in PHP's forms, the largest PHP integer, UTF-8, an empty array, and passwords at several depths.
REAL_SHAPE = r"""<?php return array (
  'app' =>
  array (
    'name' => 'Re\'Pro \\ Dash',
    'env' => 'production',
    'key' => 'base64:Zml4dHVyZS1rZXktbm90LXJlYWwtMzItYnl0ZXMhIQ==',
    'debug' => false,
    'timezone' => 'UTC',
    'providers' =>
    array (
      0 => 'App\\Providers\\AppServiceProvider',
      1 => 'App\\Providers\\EventServiceProvider',
    ),
  ),
  'services' =>
  array (
    'apple' =>
    array (
      'private_key' => '-----BEGIN KEY-----
  \'database\' =>
  array (
    \'default\' => \'mysql\',
-----END KEY-----',
      'ratio' => 1.5,
      'big' => 1.0E+25,
      'small' => 1.0E-7,
      'zero' => -0.0,
      'neg' => -3,
      'max' => 9223372036854775807,
      'name' => 'Ünïcödé',
      'none' =>
      array (
      ),
    ),
  ),
  'database' =>
  array (
    'default' => 'sqlite',
    'connections' =>
    array (
      'sqlite' =>
      array (
        'driver' => 'sqlite',
        'url' => NULL,
        'database' => '/var/www/backend/database/database.sqlite',
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => '5000',
        'journal_mode' => 'wal',
        'synchronous' => 'normal',
      ),
      'mysql' =>
      array (
        'driver' => 'mysql',
        'url' => NULL,
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'laravel',
        'username' => 'root',
        'password' => 'p\'a)s,s\\',
        'unix_socket' => '',
        'options' =>
        array (
          1014 => '/x',
          -1 => NULL,
        ),
      ),
    ),
    'repro_leftover_transactions' => 'rollback',
  ),
  'cache' =>
  array (
    'default' => 'file',
    'stores' =>
    array (
      'file' =>
      array (
        'driver' => 'file',
        'path' => '/var/www/backend/storage/framework/cache/data',
      ),
      'redis' =>
      array (
        'driver' => 'redis',
        'password' => 'store-secret',
      ),
    ),
    'prefix' => 'repro-cache-',
  ),
  'queue' =>
  array (
    'default' => 'database',
    'connections' =>
    array (
      'database' =>
      array (
        'driver' => 'database',
        'connection' => NULL,
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => false,
      ),
    ),
  ),
  'crm' =>
  array (
    'outbound_hold' => false,
  ),
);
"""


def mysql_config() -> dict:
    """The candidate's MySQL shape: socket-only default connection and the three route extras."""
    vector = next(item for item in load_vectors()["vectors"] if item["name"] == "mysql-socket-with-extras")
    return copy.deepcopy(vector["config"])


def load_vectors() -> dict:
    return json.loads(VECTORS.read_text(encoding="utf-8"))


def measured(config) -> live_config.LiveConfig:
    return live_config.from_cache(cache_text(config))


def keys_named(value, name: str) -> list[str]:
    if not isinstance(value, dict):
        return []
    found = [key for key in value if key == name]
    return found + [inner for item in value.values() for inner in keys_named(item, name)]


class SharedVectorTests(unittest.TestCase):
    """The one route-hash contract: testdata/route-hash-vectors.json (build plan addenda, review B5)."""

    @classmethod
    def setUpClass(cls):
        cls.document = load_vectors()
        cls.vectors = cls.document["vectors"]

    def test_contract_names_this_one_copy(self):
        # The contract names this file, and no second copy can drift anywhere in the scripts or tests.
        self.assertIn(VECTORS.relative_to(APP).as_posix(), " ".join(self.document["contract"]))
        copies = sorted(path.relative_to(APP).as_posix() for folder in ("scripts", "tests", "app", "config")
                        if (APP / folder).is_dir() for path in (APP / folder).rglob("route-hash-vectors*.json"))
        self.assertEqual(copies, [VECTORS.relative_to(APP).as_posix()])
        self.assertGreaterEqual(len(self.vectors), 4)

    def test_every_vector_through_the_route_values(self):
        for vector in self.vectors:
            with self.subTest(vector["name"]):
                config = objects(vector["config"])
                self.assertEqual(live_config.engine(config), vector["engine"])
                values = live_config.route_values(config)
                self.assertEqual(values, vector["route_values"])
                self.assertEqual(live_config.route_sha256(values), vector["route_sha256"])

    def test_every_vector_read_from_its_var_export_text(self):
        for vector in self.vectors:
            with self.subTest(vector["name"]):
                result = live_config.from_cache(cache_text(vector["config"]))
                self.assertEqual((result.engine, result.source), (vector["engine"], "cache"))
                self.assertEqual(result.values, vector["route_values"])
                self.assertEqual(result.live_config_sha256, vector["route_sha256"])

    @unittest.skipIf(PHP is None, "needs php for its own var_export output")
    def test_every_vector_read_from_real_php_var_export(self):
        # PHP itself writes the file, as `config:cache` does: '<?php return '.var_export($config, true).';'.PHP_EOL
        script = ('echo "<?php return ".var_export(json_decode(stream_get_contents(STDIN), true, 512, '
                  'JSON_THROW_ON_ERROR), true).";".PHP_EOL;')
        for vector in self.vectors:
            with self.subTest(vector["name"]):
                done = subprocess.run([PHP, "-n", "-r", script], input=json.dumps(vector["config"]).encode(),
                                      capture_output=True, timeout=60, check=True)
                result = live_config.from_cache(done.stdout)
                self.assertEqual(result.engine, vector["engine"])
                self.assertEqual(result.values, vector["route_values"])
                self.assertEqual(result.live_config_sha256, vector["route_sha256"])


class StrictReaderTests(unittest.TestCase):
    """var_export.parse: arrays, strings, integers, floats, true, false, NULL; anything else is refused."""

    def test_reads_what_var_export_writes(self):
        config = var_export.parse(REAL_SHAPE.encode("utf-8"))
        apple = config["services"]["apple"]
        self.assertEqual(config["app"]["name"], "Re'Pro \\ Dash")
        self.assertEqual(config["app"]["providers"], {"0": "App\\Providers\\AppServiceProvider",
                                                      "1": "App\\Providers\\EventServiceProvider"})
        self.assertIs(config["app"]["debug"], False)
        self.assertTrue(apple["private_key"].startswith("-----BEGIN KEY-----\n  'database' =>\n  array (\n"))
        self.assertEqual((apple["ratio"], apple["big"], apple["small"], apple["neg"], apple["max"]),
                         (1.5, 1e25, 1e-7, -3, 2**63 - 1))
        self.assertEqual(str(apple["zero"]), "-0.0")
        self.assertEqual(apple["name"], "Ünïcödé")
        self.assertEqual(apple["none"], {})
        mysql = config["database"]["connections"]["mysql"]
        self.assertEqual(mysql["password"], "p'a)s,s\\")
        self.assertEqual(mysql["options"], {"1014": "/x", "-1": None})
        self.assertEqual(config["database"]["default"], "sqlite")
        self.assertEqual(list(config), ["app", "services", "database", "cache", "queue", "crm"])

    def test_refuses_objects_and_expressions(self):
        refused = {
            "an object": "\\App\\Support\\Thing::__set_state(array(\n     'x' => 1,\n  ))",
            "a stdClass": "\n  (object) array(\n     'x' => 1,\n  )",
            "an enum": "\\App\\Enums\\Role::Admin",
            "a NUL concatenation": "'a' . \"\\0\" . 'b'",
            "a double-quoted string": '"text"',
            "INF": "INF", "-INF": "-INF", "NAN": "NAN",
            "PHP_INT_MIN's expression": "-9223372036854775807-1",
            "an integer PHP reads as a float": "9223372036854775808",
            "a float PHP reads as INF": "1.0E+400",
            "arithmetic": "1 + 2",
            "a constant": "PHP_EOL",
            "a variable": "$secret",
            "a call": "getenv('DB_PASSWORD')",
            "a lower-case null": "null",
            "an upper-case TRUE": "TRUE",
            "a short array": "['x']",
            "a leading zero": "007",
            "a bare dot float": ".5",
        }
        for name, value in refused.items():
            with self.subTest(name):
                text = f"<?php return array (\n  'app' => \n  array (\n    'x' => {value},\n  ),\n);\n".encode()
                with self.assertRaises(var_export.Refused):
                    var_export.parse(text)
                # The alert probe's lenient reader still skips such a value, as before the move.
                self.assertIs(var_export.value(var_export.tokens(text), 2)[0]["app"]["x"], var_export.OPAQUE)
        structure = {
            "no opening tag": b"return array (\n);\n",
            "text before the tag": b" <?php return array (\n);\n",
            "not a return": b"<?php echo array (\n);\n",
            "not an array": b"<?php return 'x';\n",
            "no semicolon": b"<?php return array (\n)\n",
            "trailing code": b"<?php return array (\n);\necho 1;\n",
            "a closing tag": b"<?php return array (\n);\n?>\n",
            "a duplicate key": b"<?php return array (\n  'a' => 1,\n  'a' => 2,\n);\n",
            "an integer and a string key alike": b"<?php return array (\n  1 => 1,\n  '1' => 2,\n);\n",
            "a missing comma": b"<?php return array (\n  'a' => 1\n  'b' => 2,\n);\n",
            "a missing arrow": b"<?php return array (\n  'a' 1,\n);\n",
            "a float key": b"<?php return array (\n  1.5 => 1,\n);\n",
            "a boolean key": b"<?php return array (\n  true => 1,\n);\n",
            "invalid UTF-8": b"<?php return array (\n  'a' => '\xff',\n);\n",
            "an unterminated string": b"<?php return array (\n  'a' => 'b,\n);\n",
            "nested too deep": b"<?php return " + b"array (\n  0 => " * 80 + b"1" + b",\n)" * 80 + b";\n",
        }
        for name, text in structure.items():
            with self.subTest(name):
                with self.assertRaises(var_export.Refused):
                    var_export.parse(text)

    def test_truncated_text_is_refused_at_every_length(self):
        # E9: a truncated cache is refused; no prefix of a valid file reads as a smaller config.
        raw = cache_text(next(vector for vector in load_vectors()["vectors"]
                              if vector["name"] == "mysql-without-extras-or-key")["config"])
        end = raw.rindex(b";")
        for length in range(end + 1):
            with self.subTest(length=length):
                with self.assertRaises(var_export.Refused):
                    var_export.parse(raw[:length])
        self.assertIsInstance(var_export.parse(raw[:end + 1]), dict)


class EngineAndHashTests(unittest.TestCase):
    def test_engine_from_driver_and_url_scheme(self):
        config = objects(mysql_config())
        connection = config["database"]["connections"]["mysql"]
        self.assertEqual(live_config.engine(config), "mysql")
        for url, expected in ((None, "mysql"), ("", "mysql"), ("sqlite:///tmp/x.sqlite", "sqlite"),
                              ("SQLite3:///tmp/x.sqlite", "sqlite"), ("MySQL2://repro_app@localhost/repro", "mysql"),
                              ("postgresql://u@h/d", "pgsql"), ("mssql://u@h/d", "sqlsrv"),
                              ("mariadb://h/d", "mariadb")):
            with self.subTest(url=url):
                connection["url"] = url
                self.assertEqual(live_config.engine(config), expected)
                self.assertEqual(measured(config).engine, expected)
        connection["url"] = None
        connection["driver"] = "sqlite"
        self.assertEqual(live_config.engine(config), "sqlite")
        # The default connection decides, not any other one.
        config["database"]["default"] = "sqlite"
        config["database"]["connections"]["sqlite"]["driver"] = "mysql"
        self.assertEqual(live_config.engine(config), "mysql")
        for broken in ({"database": {"default": "missing", "connections": {}}}, {"database": {"default": ""}},
                       {"database": {"default": "x", "connections": {"x": {"url": None}}}}, {}):
            with self.subTest(broken=broken):
                with self.assertRaises(var_export.Refused):
                    live_config.engine(broken)

    def test_hash_ignores_night_flags_and_passwords(self):
        for name, config in (("sqlite", load_vectors()["vectors"][0]["config"]), ("mysql", mysql_config())):
            with self.subTest(name):
                base = measured(config)
                changed = copy.deepcopy(config)
                changed.update(copy.deepcopy(NIGHT_FLAGS))
                connections = changed["database"]["connections"]
                for connection in connections.values():
                    if connection.get("password"):
                        connection["password"] = "rotated-" + connection["password"]
                changed["database"].setdefault("redis", {})["default"] = {"password": "redis-rotated"}
                changed["cache"]["prefix"] = "another-prefix"
                changed["app"]["name"] = "Another name"
                result = measured(changed)
                self.assertEqual(result.live_config_sha256, base.live_config_sha256)
                self.assertNotIn("rotated", json.dumps(result.values))
                self.assertEqual(keys_named(result.values, "password"), [])
        # Only whether a password is set takes part (password_set), never the password itself.
        config = mysql_config()
        before = measured(config).live_config_sha256
        config["database"]["connections"]["mysql"]["password"] = ""
        self.assertNotEqual(measured(config).live_config_sha256, before)

    def test_hash_changes_with_socket(self):
        config = mysql_config()
        base = measured(config).live_config_sha256
        for connection in ("mysql", "mysql_queue_worker", "mysql_scheduling_cache", "mysql_cache_locks"):
            with self.subTest(connection):
                changed = copy.deepcopy(config)
                changed["database"]["connections"][connection]["unix_socket"] = "/run/other/mysql.sock"
                self.assertNotEqual(measured(changed).live_config_sha256, base)
        for path, value in ((("database", "connections", "mysql", "database"), "other_schema"),
                            (("database", "connections", "mysql", "username"), "repro_maint"),
                            (("database", "default"), "sqlite"), (("queue", "default"), "sync"),
                            (("cache", "default"), "database"), (("app", "key"), "base64:b3RoZXI="),
                            (("database", "repro_leftover_transactions"), "commit")):
            with self.subTest(path):
                changed = copy.deepcopy(config)
                target = changed
                for step in path[:-1]:
                    target = target[step]
                target[path[-1]] = value
                self.assertNotEqual(measured(changed).live_config_sha256, base)

    def test_refuses_what_the_contract_cannot_hash(self):
        config = mysql_config()
        config["database"]["connections"]["mysql"]["password_set"] = True
        with self.assertRaises(var_export.Refused):
            measured(config)
        for url in ("mysql://repro_app:secret@localhost/repro", "mysql://:secret@localhost/repro"):
            with self.subTest(url=url):
                config = mysql_config()
                config["database"]["connections"]["mysql"]["url"] = url
                with self.assertRaises(var_export.Refused):
                    measured(config)
        config = mysql_config()
        config["database"]["connections"]["mysql"]["url"] = "mysql://repro_app@localhost/repro"
        self.assertEqual(measured(config).engine, "mysql")


@unittest.skipUnless(os.name == "posix", "The app root is a POSIX tree")
class MeasureTests(unittest.TestCase):
    """measure(app_root): the cache, or `.env` DB_CONNECTION with source `fallback`."""

    def setUp(self):
        self.root = Path(tempfile.mkdtemp(prefix="live-config-"))
        self.addCleanup(shutil.rmtree, self.root)
        (self.root / "bootstrap" / "cache").mkdir(parents=True)
        self.cache = self.root / "bootstrap" / "cache" / "config.php"
        self.env("APP_ENV=production\nDB_CONNECTION=sqlite\nDB_DATABASE=/var/www/backend/database/database.sqlite\n")

    def env(self, text: str) -> None:
        (self.root / ".env").write_bytes(text.encode("utf-8"))

    def test_measure_reads_the_cache(self):
        vector = load_vectors()["vectors"][1]
        self.cache.write_bytes(cache_text(vector["config"]))
        result = live_config.measure(self.root)
        self.assertEqual(result, live_config.LiveConfig("mysql", "cache", vector["route_sha256"],
                                                        vector["route_values"], None))

    def test_fallback_on_missing_empty_and_truncated_cache(self):
        raw = cache_text(load_vectors()["vectors"][1]["config"])
        cases = {"missing": None, "empty": b"", "whitespace": b"\n", "truncated": raw[:len(raw) // 2],
                 "cut before the semicolon": raw[:raw.rindex(b";")], "not var_export": b"<?php phpinfo();\n"}
        for name, content in cases.items():
            with self.subTest(name):
                self.cache.unlink(missing_ok=True)
                if content is not None:
                    self.cache.write_bytes(content)
                result = live_config.measure(self.root)
                self.assertEqual((result.engine, result.source, result.live_config_sha256, result.values),
                                 ("sqlite", "fallback", None, None))
                self.assertIn("missing" if content is None else "refused", result.problem)
        # The fallback follows .env, which is what Laravel reads without a cache.
        self.env("DB_CONNECTION=mysql\n")
        self.assertEqual(live_config.measure(self.root).engine, "mysql")

    def test_fallback_on_refused_symlinked_or_oversized_cache(self):
        config = load_vectors()["vectors"][1]["config"]
        objects_inside = cache_text(config).replace(b"'rollback'", b"\\App\\Enums\\Leftover::Rollback")
        # A valid config, padded past the limit: only the size refuses it.
        oversized = cache_text(config).replace(b"<?php return array (", b"<?php return array (" + b" " * (8 << 20), 1)
        cases = {"an enum inside": objects_inside, "over 8 MiB": oversized}
        for name, content in cases.items():
            with self.subTest(name):
                self.cache.write_bytes(content)
                result = live_config.measure(self.root)
                self.assertEqual((result.engine, result.source), ("sqlite", "fallback"))
                self.assertIn("larger than 8388608 bytes" if name == "over 8 MiB" else "refused", result.problem)
        # Root never follows a link the deploy user could point anywhere.
        target = self.root / "elsewhere.php"
        target.write_bytes(cache_text(config))
        self.cache.unlink()
        self.cache.symlink_to(target)
        self.assertEqual(live_config.measure(self.root).source, "fallback")
        self.cache.unlink()
        self.cache.mkdir()
        self.assertEqual(live_config.measure(self.root).source, "fallback")

    def test_fallback_engine_reads_env_as_dotenv_does(self):
        cases = {
            "plain": ("DB_CONNECTION=mysql\n", "mysql"),
            "double quotes": ('DB_CONNECTION="mysql"\n', "mysql"),
            "single quotes and spaces": ("  DB_CONNECTION = 'mysql'  \n", "mysql"),
            "export": ("export DB_CONNECTION=mysql\n", "mysql"),
            "inline comment": ("DB_CONNECTION=mysql # switched\n", "mysql"),
            "CRLF": ("DB_CONNECTION=mysql\r\n", "mysql"),
            "the last one wins": ("DB_CONNECTION=sqlite\nDB_CONNECTION=mysql\n", "mysql"),
            "a comment line": ("DB_CONNECTION=sqlite\n# DB_CONNECTION=mysql\n", "sqlite"),
            "another key's suffix": ("DB_CONNECTION=sqlite\nOLD_DB_CONNECTION=mysql\n", "sqlite"),
            # phpdotenv reads a double-quoted value over several lines until its closing quote.
            "inside a multi-line value": ('DB_CONNECTION=sqlite\nKEY="-----BEGIN\nDB_CONNECTION=mysql\n-----END"\n',
                                          "sqlite"),
            "after a multi-line value": ('KEY="a\nb"\nDB_CONNECTION=mysql\n', "mysql"),
        }
        for name, (text, engine) in cases.items():
            with self.subTest(name):
                self.env(text)
                self.assertEqual(live_config.measure(self.root).engine, engine)

    def test_no_fallback_without_a_plain_db_connection(self):
        # Laravel would fall back to config/database.php's default or follow DB_URL; neither is read here.
        for name, text in {"no DB_CONNECTION": "APP_ENV=production\n", "empty": "DB_CONNECTION=\n",
                           "another engine": "DB_CONNECTION=pgsql\n", "interpolated": "DB_CONNECTION=${ENGINE}\n",
                           "DB_URL": "DB_CONNECTION=sqlite\nDB_URL=mysql://repro_app@localhost/repro\n",
                           # phpdotenv never ends this value, so Laravel would not see DB_CONNECTION at all.
                           "unterminated double quote": 'DB_CONNECTION="mysql\n',
                           "unterminated single quote": "DB_CONNECTION='mysql\n",
                           "text after the value": "DB_CONNECTION=mysql sqlite\n"}.items():
            with self.subTest(name):
                self.env(text)
                with self.assertRaises(live_config.Unmeasurable):
                    live_config.measure(self.root)
        (self.root / ".env").unlink()
        with self.assertRaises(live_config.Unmeasurable):
            live_config.measure(self.root)
        # An empty DB_URL is no URL, as in Laravel.
        self.env("DB_CONNECTION=sqlite\nDB_URL=\n")
        self.assertEqual(live_config.measure(self.root).engine, "sqlite")

    def test_cli_prints_the_measurement(self):
        vector = load_vectors()["vectors"][0]
        self.cache.write_bytes(cache_text(vector["config"]))
        output = io.StringIO()
        with redirect_stdout(output):
            self.assertEqual(live_config.main(["--app-root", str(self.root)]), 0)
        printed = json.loads(output.getvalue())
        self.assertEqual(printed, {"version": 1, "engine": "sqlite", "source": "cache",
                                   "live_config_sha256": vector["route_sha256"], "values": vector["route_values"],
                                   "problem": None})
        self.assertEqual(hashlib.sha256(live_config.canonical(printed["values"])).hexdigest(), vector["route_sha256"])
        self.env("APP_ENV=production\n")
        self.cache.unlink()
        output, errors = io.StringIO(), io.StringIO()
        with redirect_stdout(output), redirect_stderr(errors):
            self.assertEqual(live_config.main(["--app-root", str(self.root)]), 1)
        self.assertEqual(output.getvalue(), "")
        self.assertIn("the config cache is missing, and .env DB_CONNECTION is not a plain sqlite or mysql",
                      errors.getvalue())


if __name__ == "__main__":
    unittest.main()
