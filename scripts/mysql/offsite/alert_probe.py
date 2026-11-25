#!/usr/bin/env python3
"""Check local alert conditions and write one status file per condition.

The probe runs without network access. It reads local state only: disk space,
the app database (read-only), the MySQL monitor socket, web and app logs, and
backup or rehearsal receipts. alert_bridge.py forwards the statuses to the
alert provider. Thresholds come from alert-conditions.json beside this file.
No status, output or error contains a secret, a row value or a .env value.
"""
from __future__ import annotations

import argparse
from contextlib import closing
from dataclasses import dataclass, field
import datetime as dt
import fnmatch
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import stat
import subprocess
import sys
import tempfile
import time
from typing import Callable

TOOLS = Path(__file__).resolve().parent
CONDITIONS_FILE = TOOLS / "alert-conditions.json"
STATUS_SCHEMA = "repro.alert.status.v1"
SAFE_ENV = {"PATH": "/usr/bin:/bin", "LANG": "C", "LC_ALL": "C", "TZ": "UTC"}
PLAN_FIELDS = {
    "version", "inbox_dir", "memory_file", "conditions", "app_root", "expected_engine", "sqlite_database",
    "mysql", "disks", "access_logs", "laravel_log", "cache_dir", "cache_group_writable", "money_queries",
    "money_baseline_file", "binlog_checkpoint_file", "restore_receipts", "rehearsal_receipts",
}
ACCESS_LINE = re.compile(rb'\[(\d{2}/[A-Za-z]{3}/\d{4}:\d{2}:\d{2}:\d{2} [+-]\d{4})\] "[^"]*" (\d{3}) ')
CACHE_ERROR = re.compile(rb"storage/framework/cache.*(Permission denied|No space left|Read-only file system|"
                         rb"Failed to open stream|Unable to create)", re.IGNORECASE)
SELECT_ONLY = re.compile(r"\s*SELECT\s[^;]*", re.IGNORECASE | re.DOTALL)


class Refusal(RuntimeError):
    pass


class DetectorError(RuntimeError):
    """A detector could not observe its input. The message must hold no secret."""


def need(condition: bool, reason: str) -> None:
    if not condition:
        raise Refusal(reason)


def load_conditions(path: Path = CONDITIONS_FILE) -> dict:
    record = json.loads(path.read_bytes())
    need(isinstance(record, dict) and record.get("schema") == "repro.alert.conditions.v1"
         and isinstance(record.get("conditions"), dict), "invalid alert conditions file")
    for name, item in record["conditions"].items():
        need(isinstance(item, dict) and set(item) == {"class", "delivery_limit_seconds", "source", "threshold"}
             and isinstance(item["threshold"], dict), "invalid alert condition " + name)
    return record["conditions"]


@dataclass
class Context:
    plan: dict
    now: int
    mysql_query: Callable[[str], list[list[str]]] | None = None
    disk_stats: Callable[[str], tuple[int, int, bool]] | None = None
    memory: dict = field(default_factory=dict)
    conditions: dict = field(default_factory=load_conditions)

    def threshold(self, condition: str) -> dict:
        return self.conditions[condition]["threshold"]


def parse_plan(raw: bytes) -> dict:
    need(0 < len(raw) <= 64 * 1024, "invalid probe plan size")
    plan = json.loads(raw)
    need(isinstance(plan, dict) and set(plan) == PLAN_FIELDS and plan["version"] == 1, "unexpected probe plan fields")
    conditions = load_conditions()
    chosen = plan["conditions"]
    need(isinstance(chosen, list) and chosen and len(set(chosen)) == len(chosen)
         and all(name in DETECTORS and conditions.get(name, {}).get("source") in ("probe-host", "probe-backup")
                 for name in chosen), "probe plan lists a condition without a local detector")
    need(plan["expected_engine"] in ("sqlite", "mysql"), "expected_engine must be sqlite or mysql")
    for name in ("inbox_dir", "memory_file", "app_root"):
        need(isinstance(plan[name], str) and Path(plan[name]).is_absolute(), "probe paths must be absolute")
    mysql = plan["mysql"]
    need(mysql is None or (isinstance(mysql, dict) and {"client", "defaults_file", "socket"} <= set(mysql)
                           <= {"client", "defaults_file", "socket", "database"}), "invalid MySQL monitor settings")
    required = {
        "database_down": ["sqlite_database" if plan["expected_engine"] == "sqlite" else "mysql"],
        "disk_nearly_full": ["disks"], "server_error_rate": ["access_logs"],
        "historical_money_totals_changed": ["money_queries", "money_baseline_file"],
        "queue_stale": ["sqlite_database" if plan["expected_engine"] == "sqlite" else "mysql"],
        "cache_write_failed": ["cache_dir", "laravel_log"], "mysql_restarted": ["mysql"],
        "database_contention": ["mysql"], "backup_lag": ["binlog_checkpoint_file"],
        "restore_overdue": ["restore_receipts"], "rehearsal_failed": ["rehearsal_receipts"],
    }
    for name in chosen:
        for item in required.get(name, []):
            need(plan[item] is not None, f"{name} needs {item} in the probe plan")
    for query in (plan["money_queries"] or {}).values():
        need(isinstance(query, str) and SELECT_ONLY.fullmatch(query) is not None and "--" not in query
             and "/*" not in query, "money queries must be single SELECT statements")
    return plan


def env_engine(plan: dict) -> str:
    """Read only the DB_CONNECTION line of the app's .env; nothing else is kept."""
    with open(Path(plan["app_root"]) / ".env", "rb") as stream:
        raw = stream.read(1024 * 1024)
    engine = None
    for line in raw.splitlines():
        if line.startswith(b"DB_CONNECTION="):
            engine = line.split(b"=", 1)[1].strip().strip(b"'\"").decode("ascii", "replace")
    return engine or "unset"


OPAQUE = object()  # A cached config value the engine check does not need (object, concatenation, number).
URL_DRIVERS = {"mysql2": "mysql", "postgres": "pgsql", "postgresql": "pgsql", "sqlite3": "sqlite", "mssql": "sqlsrv"}


def var_export_tokens(raw: bytes) -> list[tuple[str, bytes]]:
    """Tokens of PHP var_export output: quoted strings (unescaped), the symbols => ( ) , ; and bare words."""
    tokens, index, size = [], 0, len(raw)
    while index < size:
        char = raw[index:index + 1]
        if char in b" \t\r\n":
            index += 1
        elif char == b"'":
            value, index = bytearray(), index + 1
            while True:
                need(index < size, "unterminated string")
                char = raw[index:index + 1]
                if char == b"\\" and raw[index + 1:index + 2] in (b"\\", b"'"):
                    value += raw[index + 1:index + 2]
                    index += 2
                elif char == b"'":
                    index += 1
                    break
                else:
                    value += char
                    index += 1
            tokens.append(("str", bytes(value)))
        elif raw.startswith(b"=>", index):
            tokens.append(("op", b"=>"))
            index += 2
        elif char in b"(),;":
            tokens.append(("op", char))
            index += 1
        else:
            start = index
            while index < size and raw[index:index + 1] not in b" \t\r\n'(),;" and not raw.startswith(b"=>", index):
                index += 1
            tokens.append(("word", raw[start:index]))
    return tokens


def var_export_value(tokens: list, index: int):
    """Parse one value; return (value, index of the token after it). Arrays become dicts."""
    if tokens[index] == ("word", b"array") and tokens[index + 1] == ("op", b"("):
        result, index = {}, index + 2
        while tokens[index] != ("op", b")"):
            kind, key = tokens[index]
            need(kind in ("str", "word") and tokens[index + 1] == ("op", b"=>"), "unexpected array entry")
            result[key.decode("utf-8", "replace")], index = var_export_value(tokens, index + 2)
            if tokens[index] == ("op", b","):
                index += 1
        return result, index + 1
    if tokens[index][0] == "str" and tokens[index + 1] in (("op", b","), ("op", b")"), ("op", b";")):
        return tokens[index][1].decode("utf-8", "replace"), index + 1
    if tokens[index] == ("word", b"NULL") and tokens[index + 1] in (("op", b","), ("op", b")")):
        return None, index + 1
    depth = 0  # Anything else (object, enum, concatenation, number) is skipped to its end.
    while True:
        kind, text = tokens[index]
        if kind == "op" and text == b"(":
            depth += 1
        elif kind == "op" and text in (b")", b",", b";") and depth == 0:
            return OPAQUE, index
        elif kind == "op" and text == b")":
            depth -= 1
        index += 1


def cached_engine(raw: bytes) -> str:
    """The driver of the default connection in Laravel's cached config. Only that name is kept."""
    try:
        tokens = var_export_tokens(raw)
        need(tokens[:2] == [("word", b"<?php"), ("word", b"return")], "not a cached config")
        config, index = var_export_value(tokens, 2)
        need(tokens[index:] == [("op", b";")] and isinstance(config, dict), "cached config has trailing text")
        database = config["database"]
        connection = database["connections"][database["default"]]
        driver, url = connection["driver"], connection.get("url")
        if isinstance(url, str) and url:
            # Laravel's ConfigurationUrlParser takes the driver from the URL scheme.
            scheme = url.split(":", 1)[0].lower()
            driver = URL_DRIVERS.get(scheme, scheme)
        need(isinstance(driver, str) and driver != "", "no driver")
        return driver
    except (Refusal, IndexError, KeyError, TypeError):
        raise DetectorError("cached config could not be read") from None


def app_engine(plan: dict) -> tuple[str, str]:
    """The engine the app runs on, and where that came from.

    Laravel ignores .env once `php artisan config:cache` wrote bootstrap/cache/config.php
    (Deploy-App runs it), so that file wins, as in backup-database.php."""
    cached = Path(plan["app_root"]) / "bootstrap" / "cache" / "config.php"
    try:
        with open(cached, "rb") as stream:
            raw = stream.read(8 * 1024 * 1024 + 1)
    except FileNotFoundError:
        return env_engine(plan), ".env"
    if len(raw) > 8 * 1024 * 1024:
        raise DetectorError("cached config is too large")
    return cached_engine(raw), "cached config"


def file_stamp(path: Path) -> tuple:
    info = os.stat(path)
    return info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns


def immutable_rows(path: Path, sql: str, params: tuple) -> list[tuple] | None:
    """Read the database file alone, only while no writer can be changing what it holds.

    With every app connection closed, SQLite has checkpointed the WAL into the file and
    removed -wal and -shm; this read-only account cannot create -shm, so the normal open
    fails although the file is complete. immutable=1 reads it with no locks and no -shm.
    The answer is kept only if no -wal or -journal existed before or after the read and the
    file did not change during it (a writer that opened since writes to a new -wal first)."""
    sidecars = [Path(str(path) + suffix) for suffix in ("-wal", "-journal")]
    try:
        if any(os.path.lexists(item) for item in sidecars):
            return None
        before = file_stamp(path)
        with closing(sqlite3.connect(path.as_uri() + "?mode=ro&immutable=1", uri=True, timeout=10)) as db:
            rows = db.execute(sql, params).fetchall()
        if any(os.path.lexists(item) for item in sidecars) or file_stamp(path) != before:
            return None
    except (OSError, sqlite3.Error):
        return None
    return rows


def sqlite_rows(plan: dict, sql: str, params: tuple = ()) -> list[tuple]:
    path = Path(plan["sqlite_database"]).resolve()
    for _ in range(3):  # A writer opening or closing between the two reads gets a second chance.
        try:
            with closing(sqlite3.connect(path.as_uri() + "?mode=ro", uri=True, timeout=10)) as db:
                return db.execute(sql, params).fetchall()
        except sqlite3.Error:
            pass
        rows = immutable_rows(path, sql, params)
        if rows is not None:
            return rows
    raise DetectorError("SQLite read failed")


def mysql_rows(context: Context, sql: str) -> list[list[str]]:
    if context.mysql_query is None:
        raise DetectorError("MySQL monitor is not configured")
    return context.mysql_query(sql)


def app_rows(context: Context, sql: str) -> list:
    if context.plan["expected_engine"] == "sqlite":
        return [list(row) for row in sqlite_rows(context.plan, sql)]
    return mysql_rows(context, sql)


def database_down(context: Context) -> tuple[str, str]:
    if context.plan["expected_engine"] == "sqlite":
        # SELECT 1 never reads the file; the schema read proves the header and pages open.
        rows = sqlite_rows(context.plan, "SELECT COUNT(*) FROM sqlite_master")
        healthy = bool(rows) and isinstance(rows[0][0], int) and rows[0][0] > 0
    else:
        rows = mysql_rows(context, "SELECT 1")
        healthy = bool(rows) and rows[0][:1] == ["1"]
    return ("ok", "database answered") if healthy else ("fail", "database gave no answer")


def wrong_database_engine(context: Context) -> tuple[str, str]:
    (engine, source), expected = app_engine(context.plan), context.plan["expected_engine"]
    if source == "cached config":
        if engine != expected:
            return "fail", f"app runs on {engine[:16]} (cached config), expected {expected}"
        return "ok", f"app runs on {expected} (cached config)"
    if engine != expected:
        return "fail", f"app is configured for {engine[:16]}, expected {expected}"
    return "ok", f"app is configured for {expected}"


def disk_nearly_full(context: Context) -> tuple[str, str]:
    limits = context.threshold("disk_nearly_full")
    problems = []
    for disk in context.plan["disks"]:
        available, total, mounted = context.disk_stats(disk["path"])
        if disk.get("require_mount") and not mounted:
            problems.append(f"{disk['path']} is not mounted")
        elif available < limits["min_free_bytes"] or available * 100 < limits["min_free_percent"] * total:
            problems.append(f"{disk['path']} has {available * 100 // max(total, 1)}% free")
    return ("fail", "; ".join(problems)[:120]) if problems else ("ok", "free space above limits")


def tail(path: Path, limit: int) -> bytes:
    with open(path, "rb") as stream:
        size = os.fstat(stream.fileno()).st_size
        stream.seek(max(0, size - limit))
        return stream.read(limit)


def server_error_rate(context: Context) -> tuple[str, str]:
    limits = context.threshold("server_error_rate")
    start = context.now - limits["window_seconds"]
    total = errors = 0
    for name in context.plan["access_logs"]:
        # After logrotate the window also spans the previous file (delaycompress keeps it plain).
        for path, rotated in ((Path(name + ".1"), True), (Path(name), False)):
            try:
                chunk = tail(path, limits["max_read_bytes"])
            except FileNotFoundError:
                if rotated:
                    continue
                raise
            for match in ACCESS_LINE.finditer(chunk):
                moment = dt.datetime.strptime(match.group(1).decode(), "%d/%b/%Y:%H:%M:%S %z").timestamp()
                if start <= moment <= context.now + 60:
                    total += 1
                    errors += 500 <= int(match.group(2)) <= 599
    detail = f"{errors} of {total} requests failed in {limits['window_seconds'] // 60} min"
    if total >= limits["min_requests"] and errors * 100 > limits["max_percent"] * total:
        return "fail", detail
    return "ok", detail


def money_digest(context: Context) -> str:
    results = {name: [[str(value) for value in row] for row in app_rows(context, sql)]
               for name, sql in sorted(context.plan["money_queries"].items())}
    return hashlib.sha256(json.dumps(results, sort_keys=True).encode()).hexdigest()


def historical_money_totals_changed(context: Context) -> tuple[str, str]:
    try:
        baseline = json.loads(Path(context.plan["money_baseline_file"]).read_bytes())
    except (OSError, ValueError):
        return "fail", "no reviewed money baseline"
    if not isinstance(baseline, dict) or baseline.get("version") != 1:
        return "fail", "invalid money baseline"
    if money_digest(context) != baseline.get("result_sha256"):
        return "fail", "historical money totals differ from the reviewed baseline"
    return "ok", "historical money totals match the baseline"


def queue_stale(context: Context) -> tuple[str, str]:
    limits = context.threshold("queue_stale")
    limit, expiry, now = limits["max_age_seconds"], int(limits["reserved_expiry_seconds"]), int(context.now)
    # A reservation older than the longest retry_after in config/queue.php is expired on every
    # queue: its worker died and no worker took the job back. It has waited since it expired.
    rows = app_rows(context, "SELECT COUNT(*), MIN(CASE WHEN reserved_at IS NULL THEN available_at "
                             f"ELSE reserved_at + {expiry} END) FROM jobs WHERE available_at <= {now} "
                             f"AND (reserved_at IS NULL OR reserved_at <= {now - expiry})")
    count, oldest = rows[0]
    if not count or str(count) == "0" or oldest in (None, "NULL"):
        return "ok", "no waiting jobs"
    age = context.now - int(oldest)
    return ("fail" if age > limit else "ok"), f"{count} waiting, oldest {age} s"


def cache_write_failed(context: Context) -> tuple[str, str]:
    limits = context.threshold("cache_write_failed")
    problems = []
    if context.plan["cache_group_writable"] and os.name == "posix":
        locked, seen = 0, 0

        def unreadable(_error: OSError) -> None:
            # os.walk skips what it cannot list; a missing or unreadable folder is not a clean one.
            raise DetectorError("cache folder could not be read")

        try:
            root = os.stat(context.plan["cache_dir"])
        except OSError:
            unreadable(None)
        # Laravel creates each new cache folder in the root, so group writers need write there too.
        if not root.st_mode & stat.S_IWGRP:
            problems.append("the cache folder is not group-writable")
        for directory, folders, _ in os.walk(context.plan["cache_dir"], onerror=unreadable):
            for name in folders:
                seen += 1
                if not os.lstat(os.path.join(directory, name)).st_mode & stat.S_IWGRP:
                    locked += 1
            if seen > limits["max_folders"]:
                break
        if locked:
            problems.append(f"{locked} cache folders are not group-writable")
    memory = context.memory.setdefault("cache_log", {})
    path = Path(context.plan["laravel_log"])
    try:
        info = path.stat()
    except FileNotFoundError:
        info = None
    if info is not None:
        offset = memory.get("offset", 0) if memory.get("inode") == info.st_ino else 0
        if offset > info.st_size:
            offset = 0
        with open(path, "rb") as stream:
            stream.seek(max(offset, info.st_size - limits["max_read_bytes"]))
            if any(CACHE_ERROR.search(line) for line in stream.read().splitlines()):
                memory["last_error_unix_s"] = context.now
        memory.update(inode=info.st_ino, offset=info.st_size)
    if context.now - memory.get("last_error_unix_s", -10**12) <= limits["window_seconds"]:
        problems.append("cache write error in the app log")
    return ("fail", "; ".join(problems)) if problems else ("ok", "cache folders and log are clean")


def status_rows(rows: list[list[str]], name: str) -> int:
    for row in rows:
        if row and row[0] == name:
            return int(row[1])
    raise DetectorError(f"{name} is missing from MySQL status")


def mysql_restarted(context: Context) -> tuple[str, str]:
    uptime = status_rows(mysql_rows(context, "SHOW GLOBAL STATUS LIKE 'Uptime'"), "Uptime")
    limit = context.threshold("mysql_restarted")["min_uptime_seconds"]
    return ("fail" if uptime < limit else "ok"), f"MySQL uptime {uptime} s"


def database_contention(context: Context) -> tuple[str, str]:
    rows = mysql_rows(context, "SELECT NAME, COUNT, STATUS FROM information_schema.INNODB_METRICS "
                               "WHERE NAME IN ('lock_deadlocks','lock_timeouts') ORDER BY NAME")
    if len(rows) != 2 or any(len(row) != 3 or row[2] != "enabled" for row in rows):
        return "fail", "InnoDB deadlock and lock-timeout counters are not enabled"
    total = sum(int(row[1]) for row in rows)
    samples = [item for item in context.memory.get("contention", []) if item[0] >= context.now - 7200]
    if samples and total < samples[-1][1]:
        samples = []  # The counters reset when MySQL restarts.
    window = [item for item in samples if item[0] >= context.now - 3600 - 120]
    samples.append([context.now, total])
    context.memory["contention"] = samples
    if not window:
        return "ok", "first contention sample"
    increase = total - window[0][1]
    limit = context.threshold("database_contention")["max_per_hour"]
    return ("fail" if increase > limit else "ok"), f"{increase} deadlocks or lock timeouts in the last hour"


def backup_lag(context: Context) -> tuple[str, str]:
    limits = context.threshold("backup_lag")
    try:
        lag = context.now - int(Path(context.plan["binlog_checkpoint_file"]).stat().st_mtime)
    except OSError:
        lag = None
    late = lag is None or lag > limits["max_lag_seconds"]
    count = context.memory.get("backup_lag_late", 0) + 1 if late else 0
    context.memory["backup_lag_late"] = count
    detail = "no archive checkpoint" if lag is None else f"last archive {lag} s ago"
    return ("fail" if count >= limits["consecutive_checks"] else "ok"), detail


def newest_receipt(context: Context, settings: dict, condition: str) -> tuple[str, str]:
    newest = None
    folder = Path(settings["directory"])
    for path in sorted(folder.iterdir()) if folder.is_dir() else []:
        if not fnmatch.fnmatchcase(path.name, settings["pattern"]):
            continue
        try:
            record = json.loads(path.read_bytes()[: 1024 * 1024])
            moment = int(record["verified_unix_s"])
        except (OSError, ValueError, KeyError, TypeError):
            continue
        if newest is None or moment > newest[0]:
            newest = (moment, record.get("result"))
    if newest is None:
        return "fail", "no receipt found"
    age = context.now - newest[0]
    if newest[1] != settings["result"]:
        return "fail", f"latest result is not {settings['result']}"
    if age > context.threshold(condition)["max_age_seconds"]:
        return "fail", f"latest passing receipt is {age // 3600} h old"
    return "ok", f"latest passing receipt is {age // 3600} h old"


DETECTORS: dict[str, Callable[[Context], tuple[str, str]]] = {
    "database_down": database_down,
    "wrong_database_engine": wrong_database_engine,
    "disk_nearly_full": disk_nearly_full,
    "server_error_rate": server_error_rate,
    "historical_money_totals_changed": historical_money_totals_changed,
    "queue_stale": queue_stale,
    "cache_write_failed": cache_write_failed,
    "mysql_restarted": mysql_restarted,
    "database_contention": database_contention,
    "backup_lag": backup_lag,
    "restore_overdue": lambda context: newest_receipt(context, context.plan["restore_receipts"], "restore_overdue"),
    "rehearsal_failed": lambda context: newest_receipt(context, context.plan["rehearsal_receipts"], "rehearsal_failed"),
}


def load_memory(path: Path) -> dict:
    try:
        memory = json.loads(path.read_bytes())
    except (OSError, ValueError):
        return {}
    return memory if isinstance(memory, dict) else {}


def save_json(path: Path, record: dict, mode: int) -> None:
    descriptor, name = tempfile.mkstemp(dir=path.parent, prefix="." + path.stem + "-")
    temporary = Path(name)
    try:
        with os.fdopen(descriptor, "wb") as stream:
            stream.write(json.dumps(record, sort_keys=True).encode())
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)


def probe(context: Context) -> list[dict]:
    memory_path = Path(context.plan["memory_file"])
    context.memory = load_memory(memory_path)
    records = []
    for condition in context.plan["conditions"]:
        try:
            state, detail = DETECTORS[condition](context)
        except DetectorError as error:
            state, detail = "fail", str(error)
        except (OSError, ValueError, KeyError, IndexError, TypeError, subprocess.SubprocessError):
            state, detail = "fail", "detector could not read its input"
        records.append({"schema": STATUS_SCHEMA, "condition": condition, "state": state,
                        "observed_unix_s": int(context.now), "detail": detail[:120]})
    save_json(memory_path, context.memory, 0o600)
    return records


def write_statuses(inbox: Path, records: list[dict]) -> None:
    for record in records:
        save_json(inbox / f"{record['condition']}.json", record, 0o640)


def mysql_cli(settings: dict, timeout: int) -> Callable[[str], list[list[str]]]:
    def query(sql: str) -> list[list[str]]:
        args = [settings["client"], "--defaults-file=" + settings["defaults_file"], "--no-login-paths",
                "--protocol=SOCKET", "--socket=" + settings["socket"], "--batch", "--raw", "--skip-column-names",
                "--skip-reconnect", f"--connect-timeout={timeout}", "--execute=" + sql]
        if settings.get("database"):
            args.insert(-1, "--database=" + settings["database"])
        result = subprocess.run(args, env=SAFE_ENV, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, timeout=timeout + 5, check=False)
        if result.returncode != 0:
            raise DetectorError("MySQL query failed")
        return [line.split("\t") for line in result.stdout.decode("utf-8", "replace").splitlines()]

    return query


def disk_stats(path: str) -> tuple[int, int, bool]:
    info = os.statvfs(path)
    return info.f_bavail * info.f_frsize, info.f_blocks * info.f_frsize, os.path.ismount(path)


def read_plan(path: Path) -> dict:
    if os.name == "posix":
        info = path.lstat()
        need(info.st_uid == 0 and stat.S_ISREG(info.st_mode) and not info.st_mode & 0o022,
             "probe plan must be a protected root-owned file")
    return parse_plan(path.read_bytes())


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("run", "money-digest"))
    parser.add_argument("--plan", type=Path, required=True)
    args = parser.parse_args(argv)
    os.umask(0o027)
    try:
        plan = read_plan(args.plan)
        timeout = load_conditions()["database_down"]["threshold"]["query_timeout_seconds"]
        context = Context(plan=plan, now=int(time.time()), disk_stats=disk_stats,
                          mysql_query=mysql_cli(plan["mysql"], timeout) if plan["mysql"] else None)
        if args.action == "money-digest":
            print(json.dumps({"result": "money-digest-ready", "result_sha256": money_digest(context)}))
            return 0
        records = probe(context)
        write_statuses(Path(plan["inbox_dir"]), records)
    except Refusal as error:
        print(json.dumps({"result": "alert-probe-refused", "reason": str(error)}))
        return 1
    except (OSError, ValueError, TypeError, KeyError, DetectorError, subprocess.SubprocessError):
        print(json.dumps({"result": "alert-probe-refused", "reason": "probe plan or status I/O failed"}))
        return 1
    print(json.dumps({"result": "alert-probe-written",
                      "failing": [item["condition"] for item in records if item["state"] == "fail"]}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
