"""The app's live configuration: engine, source and route hash (design v2, 1.3).

    /usr/bin/python3 -I -S /opt/repro-cutover-fence/production/live_config.py --app-root /var/www/backend

`measure(app_root)` reads `<app_root>/bootstrap/cache/config.php`, at most 8 MiB, with the strict
var_export reader (var_export.parse) and never executes it: the deploy user can write that file, and
root reads it. Root never follows a symbolic link there and refuses anything but a regular file.

- **Engine**: the `driver` of `database.connections[database.default]`; a non-empty `url` wins with its
  scheme (Laravel's aliases: mysql2, postgres, postgresql, sqlite3, mssql).
- **Route values and hash**: the allowlist of testdata/route-hash-vectors.json, the one contract this
  module, `scripts/cutover/probe/fpm-engine.php` and `php artisan cutover:smoke --engine-only` share
  (PHP prints the values raw; Python hashes them). Every key named `password`, at any depth, becomes
  `password_set`; night flags are outside the allowlist; the hash is the SHA-256 of canonical JSON.
- **Fallback**: a missing cache, or one the reader refuses (empty, truncated, not var_export, an object
  or expression inside, a link, not a regular file, over 8 MiB, or a shape the contract cannot hash),
  gives the engine of `.env` `DB_CONNECTION` with source `fallback` and no hash. That is what Laravel
  reads without a cache, and an app whose cache does not load cannot write. `observe()` accepts the
  fallback so a rollback can run (and `seal_sqlite` rebuilds the cache); `prepare`, both publications
  and both reopens refuse it. With no plain `DB_CONNECTION` of sqlite or mysql, or with a `DB_URL`, the
  engine cannot be read without PHP: `Unmeasurable`.

The CLI prints `{"version": 1, "engine", "source", "live_config_sha256", "values", "problem"}` and exits
0, or exits 1 when the config is unmeasurable. Standard library only.
"""

from __future__ import annotations

import argparse
import errno
import hashlib
import json
import os
import re
import stat
import sys
import urllib.parse
from dataclasses import asdict, dataclass
from pathlib import Path

sys.path[:0] = [str(Path(__file__).resolve().parent)]
import var_export  # noqa: E402

VERSION = 1
CACHE_FILE = ("bootstrap", "cache", "config.php")
MAX_CACHE_BYTES = 8 * 1024 * 1024
MAX_ENV_BYTES = 1024 * 1024
CACHE, FALLBACK = "cache", "fallback"
# Laravel's ConfigurationUrlParser aliases (the same table as alert_probe.URL_DRIVERS and CutoverRouteValues).
URL_DRIVERS = {"mysql2": "mysql", "postgres": "pgsql", "postgresql": "pgsql", "sqlite3": "sqlite", "mssql": "sqlsrv"}
MYSQL_EXTRAS = ("mysql_cache_locks", "mysql_queue_worker", "mysql_scheduling_cache")
QUEUE_KEYS = ("driver", "connection", "table")
# config/database.php names its sqlite and mysql connections after their drivers.
FALLBACK_ENGINES = ("sqlite", "mysql")
CONNECTION_NAME = re.compile(r"[a-z][a-z0-9_]*")
ENV_NAME = re.compile(r"[A-Za-z0-9_.]+")
# As CutoverRouteValues::refuseUrlPasswords: a user:password@ in any url.
URL_PASSWORD = re.compile(r"//[^/@]*:[^/@]*@")


class Unmeasurable(Exception):
    """Neither the cache nor .env tells the engine without running PHP."""


@dataclass(frozen=True)
class LiveConfig:
    engine: str
    source: str                       # "cache" or "fallback"
    live_config_sha256: str | None    # None for the fallback
    values: dict | None               # the route values, no password; None for the fallback
    problem: str | None = None        # why the cache was not used (fallback only)


def _map(value, what: str) -> dict:
    if not isinstance(value, dict):
        raise var_export.Refused(f"{what} is not an array")
    return value


def _optional_map(value, what: str) -> dict:
    return {} if value is None else _map(value, what)


def engine(config: dict) -> str:
    """The engine of the default connection: a non-empty url's scheme wins over the driver."""
    database = _map(config.get("database"), "database")
    default = database.get("default")
    if not isinstance(default, str) or default == "":
        raise var_export.Refused("database.default is not a connection name")
    connection = _map(_map(database.get("connections"), "database.connections").get(default), "the default connection")
    url = connection.get("url")
    if isinstance(url, str) and url != "":
        # PHP's strtolower changes ASCII letters only.
        scheme = url.split(":", 1)[0].encode("utf-8").lower().decode("utf-8")
        return URL_DRIVERS.get(scheme, scheme)
    driver = connection.get("driver")
    if not isinstance(driver, str) or driver == "":
        raise var_export.Refused("the default connection has no driver")
    return driver


def route_values(config: dict) -> dict:
    """The allowlisted values that decide where a process reads and writes, without any password."""
    chosen_engine = engine(config)
    database = config["database"]
    default, connections = database["default"], database["connections"]
    chosen = {default: connections.get(default)}
    if chosen_engine == "mysql":
        for name in MYSQL_EXTRAS:
            chosen[name] = connections.get(name)
    cache = _optional_map(config.get("cache"), "cache")
    stores = _optional_map(cache.get("stores"), "cache.stores")
    cache_default = cache.get("default")
    queue = _optional_map(config.get("queue"), "queue")
    queues = {name: {key: item for key, item in _map(settings, f"queue connection {name}").items() if key in QUEUE_KEYS}
              for name, settings in _optional_map(queue.get("connections"), "queue.connections").items()}
    key = _optional_map(config.get("app"), "app").get("key")
    if key is not None and not isinstance(key, str):
        raise var_export.Refused("app.key is not a string")
    values = {
        "app.key_sha256": None if key is None else hashlib.sha256(key.encode("utf-8")).hexdigest(),
        "cache.default": cache_default,
        "cache.stores.default": stores.get(cache_default) if isinstance(cache_default, str) else None,
        "cache.stores.scheduling": stores.get("scheduling"),
        "database.connections": chosen,
        "database.default": default,
        "database.repro_leftover_transactions": database.get("repro_leftover_transactions"),
        "queue.connections": queues,
        "queue.default": queue.get("default"),
    }
    values = _without_passwords(values)
    _refuse_url_passwords(values)
    return values


def _without_passwords(value):
    """Every key named exactly `password`, at any depth, becomes `password_set` (false for null or '')."""
    if not isinstance(value, dict):
        return value
    if "password" in value and "password_set" in value:
        raise var_export.Refused("a config map holds both password and password_set")
    result = {}
    for key, item in value.items():
        if key == "password":
            result["password_set"] = not (item is None or item == "")
        else:
            result[key] = _without_passwords(item)
    return result


def _refuse_url_passwords(value, path: str = "") -> None:
    if not isinstance(value, dict):
        return
    for key, item in value.items():
        if key == "url" and isinstance(item, str) and item != "":
            try:
                password = urllib.parse.urlsplit(item).password
            except ValueError:
                password = None
            if password or URL_PASSWORD.search(item):
                raise var_export.Refused(f"the url at {path}url carries a password")
        _refuse_url_passwords(item, f"{path}{key}.")


def _objects(value):
    if isinstance(value, list):
        return {str(index): _objects(item) for index, item in enumerate(value)}
    if isinstance(value, dict):
        return {str(key): _objects(item) for key, item in value.items()}
    return value


def canonical(values) -> bytes:
    """The contract's canonical JSON: every array an object with string keys, sorted, ASCII, no NaN."""
    return json.dumps(_objects(values), sort_keys=True, separators=(",", ":"), ensure_ascii=True,
                      allow_nan=False).encode("ascii")


def route_sha256(values) -> str:
    return hashlib.sha256(canonical(values)).hexdigest()


def from_cache(raw: bytes) -> LiveConfig:
    """Engine and route hash of one cached config's bytes; raises var_export.Refused."""
    config = var_export.parse(raw)
    values = route_values(config)
    return LiveConfig(engine(config), CACHE, route_sha256(values), values, None)


def read_regular(path: Path, limit: int, *, follow: bool = False) -> bytes:
    """A regular file's bytes; never blocks on a FIFO, and never follows a final link unless asked."""
    flags = os.O_RDONLY | os.O_NONBLOCK | os.O_CLOEXEC | (0 if follow else os.O_NOFOLLOW)
    try:
        descriptor = os.open(path, flags)
    except OSError as error:
        if error.errno == errno.ELOOP and not follow:
            raise var_export.Refused("it is a symbolic link") from None
        raise
    try:
        if not stat.S_ISREG(os.fstat(descriptor).st_mode):
            raise var_export.Refused("it is not a regular file")
        stream = os.fdopen(descriptor, "rb")
    except BaseException:
        os.close(descriptor)
        raise
    with stream:
        raw = stream.read(limit + 1)
    if len(raw) > limit:
        raise var_export.Refused(f"it is larger than {limit} bytes")
    return raw


def measure(app_root) -> LiveConfig:
    root = Path(app_root)
    try:
        raw = read_regular(root.joinpath(*CACHE_FILE), MAX_CACHE_BYTES)
    except (FileNotFoundError, NotADirectoryError):
        return _fallback(root, "the config cache is missing")
    except var_export.Refused as refusal:
        return _fallback(root, f"the config cache was refused: {refusal}")
    try:
        return from_cache(raw)
    except var_export.Refused as refusal:
        return _fallback(root, f"the config cache was refused: {refusal}")


def _fallback(root: Path, problem: str) -> LiveConfig:
    try:
        settings = env_values(read_regular(root / ".env", MAX_ENV_BYTES, follow=True), ("DB_CONNECTION", "DB_URL"))
    except (OSError, var_export.Refused) as error:
        raise Unmeasurable(f"{problem}, and .env cannot be read: {error}") from None
    if settings.get("DB_URL"):
        raise Unmeasurable(f"{problem}, and .env sets DB_URL, whose engine only PHP can tell")
    connection = settings.get("DB_CONNECTION")
    if connection not in FALLBACK_ENGINES:
        raise Unmeasurable(f"{problem}, and .env DB_CONNECTION is not a plain sqlite or mysql")
    return LiveConfig(connection, FALLBACK, None, None, problem)


def _quotes(line: str) -> int:
    """phpdotenv's count of unescaped double quotes after the first character (Lines::looksLikeMultilineStop)."""
    text = line.replace("\\\\", "")
    return sum(1 for index in range(1, len(text)) if text[index] == '"' and text[index - 1] != "\\")


def _entries(text: str) -> list[str]:
    """phpdotenv's Lines::process: one entry per line, a double-quoted value may run over several lines."""
    entries, buffer = [], None
    for line in re.split(r"\r\n|\n|\r", text):
        if buffer is None and '="' in line and _quotes(line) <= 1 and line != '"':
            buffer = [line]
            continue
        if buffer is not None:
            buffer.append(line)
            if line == '"' or _quotes(line) >= 1:
                entries.append("\n".join(buffer))
                buffer = None
            continue
        if line.strip() != "" and not line.strip().startswith("#"):
            entries.append(line)
    # A value whose closing quote never comes is not loaded at all.
    return entries


def _env_value(raw: str, name: str) -> str:
    value = raw.strip()
    if value[:1] in ('"', "'"):
        quote, index, text = value[0], 1, []
        while True:
            if index >= len(value):
                raise Unmeasurable(f".env {name} has no closing quote")
            char = value[index]
            if quote == '"' and char == "\\" and value[index + 1:index + 2] in ('"', "\\"):
                text.append(value[index + 1])
                index += 2
            elif char == quote:
                break
            else:
                text.append(char)
                index += 1
        rest = value[index + 1:].strip()
        if rest != "" and not rest.startswith("#"):
            raise Unmeasurable(f".env {name} has text after its closing quote")
        return "".join(text)
    found = re.match(r"[^\s#]*", value).group(0)
    rest = value[len(found):].strip()
    if rest != "" and not rest.startswith("#"):
        raise Unmeasurable(f".env {name} holds whitespace inside its value")
    return found


def env_values(raw: bytes, names) -> dict[str, str]:
    """The last value of each named key in .env, read as phpdotenv reads it (comments, quotes, export)."""
    try:
        text = raw.decode("utf-8")
    except UnicodeDecodeError:
        raise Unmeasurable(".env is not valid UTF-8") from None
    found = {}
    for entry in _entries(text):
        if "=" not in entry:
            continue
        name, value = (part.strip() for part in entry.split("=", 1))
        if name.startswith("export") and name[6:7].isspace():
            name = name[6:].lstrip()
        if ENV_NAME.fullmatch(name) is None or name not in names:
            continue
        found[name] = _env_value(value, name)
    if "DB_CONNECTION" in found and CONNECTION_NAME.fullmatch(found["DB_CONNECTION"]) is None:
        found["DB_CONNECTION"] = ""
    return found


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--app-root", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        result = measure(args.app_root)
    except (Unmeasurable, OSError) as error:
        print(f"Live config: {error}", file=sys.stderr)
        return 1
    print(json.dumps({"version": VERSION, **asdict(result)}, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
