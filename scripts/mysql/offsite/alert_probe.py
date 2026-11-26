#!/usr/bin/env python3
"""Check local alert conditions and write one status file per condition.

The probe runs without network access. It reads local state only: disk space,
the app database (read-only), the MySQL monitor socket, web and app logs, and
backup or rehearsal receipts. alert_bridge.py forwards the statuses to the
alert provider. Thresholds come from alert-conditions.json beside this file.
No status, output or error contains a secret, a row value or a .env value.

Where the writer hold is installed, the cutover journal decides which engine is
authoritative and whether a held site is inside an approved hold. The probe
reads it through the view cutover_view.py writes, and the nginx open marker.
"""
from __future__ import annotations

import argparse
from contextlib import closing
from dataclasses import dataclass, field
import datetime as dt
import fnmatch
import hashlib
import importlib.util
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
    "money_baseline_file", "binlog_checkpoint_file", "binlog_dir", "restore_receipts", "rehearsal_receipts",
    "cutover",
}
BOOT_ID = Path("/proc/sys/kernel/random/boot_id")
VIEW_SCHEMA = "repro.alert.cutover-view.v1"  # Written by cutover_view.py.
WORD = re.compile(r"[a-z][a-z_]{0,31}")
# Cutover phases (scripts/cutover/runner.py). SQLite stays authoritative until the promotion.
HELD_PHASES = {"holding", "frozen", "ready"}
SQLITE_PHASES = {"planned", "sqlite_authoritative"} | HELD_PHASES
MYSQL_PHASES = {"writes_possible", "mysql_authoritative"}
# After the hold, until the reopen, the site may stay held within the write-outage limit.
AFTER_HOLD_PHASES = {"sqlite_authoritative"} | MYSQL_PHASES
# Sessions that have held the writer token (taken at BEGIN, MySqlWriterToken) at least {limit} seconds,
# as in `php artisan db:writer-token-holders`. Needs PROCESS and SELECT on performance_schema.data_locks.
TOKEN_HOLDERS = ("SELECT COUNT(DISTINCT t.trx_id), COALESCE(MAX(TIMESTAMPDIFF(SECOND, t.trx_started, NOW())), 0) "
                 "FROM performance_schema.data_locks l JOIN information_schema.INNODB_TRX t "
                 "ON t.trx_id = l.ENGINE_TRANSACTION_ID WHERE l.OBJECT_SCHEMA = 'repro_mysql_control' "
                 "AND l.OBJECT_NAME = 'writer_token' AND l.LOCK_TYPE = 'RECORD' AND l.LOCK_STATUS = 'GRANTED' "
                 "AND TIMESTAMPDIFF(SECOND, t.trx_started, NOW()) >= {limit}")
ACCESS_LINE = re.compile(rb'\[(\d{2}/[A-Za-z]{3}/\d{4}:\d{2}:\d{2}:\d{2} [+-]\d{4})\] "[^"]*" (\d{3}) ')
CACHE_ERROR = re.compile(rb"storage/framework/cache.*(Permission denied|No space left|Read-only file system|"
                         rb"Failed to open stream|Unable to create)", re.IGNORECASE)
SELECT_ONLY = re.compile(r"\s*SELECT\s[^;]*", re.IGNORECASE | re.DOTALL)
BINLOG_NAME = re.compile(r"([A-Za-z0-9][A-Za-z0-9_.-]*)\.([0-9]{6,})")


class Refusal(RuntimeError):
    pass


class DetectorError(RuntimeError):
    """A detector could not observe its input. The message must hold no secret."""


def need(condition: bool, reason: str) -> None:
    if not condition:
        raise Refusal(reason)


def load_conditions(path: Path = CONDITIONS_FILE, *, extra: bool = False) -> dict:
    """The readiness gate's conditions; with extra=True also the conditions outside that gate."""
    record = json.loads(path.read_bytes())
    need(isinstance(record, dict) and record.get("schema") == "repro.alert.conditions.v1"
         and isinstance(record.get("conditions"), dict) and isinstance(record.get("extra_conditions", {}), dict),
         "invalid alert conditions file")
    chosen = dict(record["conditions"]) | (record.get("extra_conditions", {}) if extra else {})
    for name, item in chosen.items():
        need(isinstance(item, dict) and set(item) == {"class", "delivery_limit_seconds", "source", "threshold"}
             and isinstance(item["threshold"], dict), "invalid alert condition " + name)
    return chosen


def load_hold_limits(path: Path = CONDITIONS_FILE) -> dict:
    """The cutover plan's limits: decision deadline and write outage from the hold, and the view's age."""
    limits = json.loads(path.read_bytes()).get("cutover_hold")
    need(isinstance(limits, dict) and set(limits) == {"decision_deadline_seconds", "max_write_outage_seconds",
                                                      "max_view_age_seconds"}
         and all(type(value) is int and value > 0 for value in limits.values()), "invalid cutover hold limits")
    return limits


def read_boot_id() -> str:
    return BOOT_ID.read_text(encoding="ascii").strip()


@dataclass
class Context:
    plan: dict
    now: int
    mysql_query: Callable[[str], list[list[str]]] | None = None
    disk_stats: Callable[[str], tuple[int, int, bool]] | None = None
    memory: dict = field(default_factory=dict)
    conditions: dict = field(default_factory=lambda: load_conditions(extra=True))
    hold: dict = field(default_factory=load_hold_limits)
    boot_id: Callable[[], str] = read_boot_id
    cache: dict = field(default_factory=dict)

    def threshold(self, condition: str) -> dict:
        return self.conditions[condition]["threshold"]


def parse_plan(raw: bytes) -> dict:
    need(0 < len(raw) <= 64 * 1024, "invalid probe plan size")
    plan = json.loads(raw)
    need(isinstance(plan, dict) and set(plan) == PLAN_FIELDS and plan["version"] == 1, "unexpected probe plan fields")
    conditions = load_conditions(extra=True)
    chosen = plan["conditions"]
    need(isinstance(chosen, list) and chosen and len(set(chosen)) == len(chosen)
         and all(name in DETECTORS and conditions.get(name, {}).get("source") in ("probe-host", "probe-backup")
                 for name in chosen), "probe plan lists a condition without a local detector")
    need(plan["expected_engine"] in ("sqlite", "mysql"), "expected_engine must be sqlite or mysql")
    for name in ("inbox_dir", "memory_file", "app_root"):
        need(isinstance(plan[name], str) and Path(plan[name]).is_absolute(), "probe paths must be absolute")
    for name in ("binlog_checkpoint_file", "binlog_dir"):
        need(plan[name] is None or (isinstance(plan[name], str) and Path(plan[name]).is_absolute()),
             "probe paths must be absolute")
    mysql = plan["mysql"]
    need(mysql is None or (isinstance(mysql, dict) and {"client", "defaults_file", "socket"} <= set(mysql)
                           <= {"client", "defaults_file", "socket", "database"}), "invalid MySQL monitor settings")
    cutover = plan["cutover"]
    need(cutover is None or (isinstance(cutover, dict) and set(cutover) == {"view_file", "ingress_open"}
                             and all(isinstance(value, str) and Path(value).is_absolute() for value in cutover.values())),
         "cutover needs absolute view_file and ingress_open paths")
    required = {
        "database_down": ["sqlite_database" if plan["expected_engine"] == "sqlite" else "mysql"],
        "disk_nearly_full": ["disks"], "server_error_rate": ["access_logs"],
        "historical_money_totals_changed": ["money_queries", "money_baseline_file"],
        "queue_stale": ["sqlite_database" if plan["expected_engine"] == "sqlite" else "mysql"],
        "cache_write_failed": ["cache_dir", "laravel_log"], "mysql_restarted": ["mysql"],
        "database_contention": ["mysql"], "backup_lag": ["binlog_checkpoint_file", "binlog_dir"],
        "restore_overdue": ["restore_receipts"], "rehearsal_failed": ["rehearsal_receipts"],
        "site_held": ["cutover"], "writer_token_held_too_long": ["mysql"],
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


def _reader_paths() -> list[Path]:
    """Where var_export.py may be: beside this file; and only when this file sits in a checkout's
    scripts/mysql/offsite, that checkout's scripts/cutover/production. Installed flat
    (/opt/repro-backup-tools), no path outside the tools folder is ever searched."""
    paths = [TOOLS / "var_export.py"]
    if TOOLS.parts[-3:] == ("scripts", "mysql", "offsite"):
        paths.append(TOOLS.parents[2] / "scripts" / "cutover" / "production" / "var_export.py")
    return paths


def _shared_reader():
    """The reader of PHP var_export output shared with the cutover controller (design v2, 1.3).

    The alerts job installs var_export.py beside this file (/opt/repro-backup-tools); in the repository it
    is scripts/cutover/production/var_export.py. Loaded by path, so the probe's sys.path stays as it is.
    A missing copy is an error.
    """
    for path in _reader_paths():
        if path.is_file():
            spec = importlib.util.spec_from_file_location("var_export", path)
            module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
            return module
    raise ImportError(f"var_export.py is missing beside {TOOLS / 'alert_probe.py'}")


var_export = _shared_reader()
# The lenient reader, moved unchanged: strings and NULL are kept, any other value is OPAQUE.
OPAQUE = var_export.OPAQUE  # A cached config value the engine check does not need (object, concatenation, number).
var_export_tokens = var_export.tokens
var_export_value = var_export.value
URL_DRIVERS = {"mysql2": "mysql", "postgres": "pgsql", "postgresql": "pgsql", "sqlite3": "sqlite", "mssql": "sqlsrv"}


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
    except (Refusal, var_export.Refused, IndexError, KeyError, TypeError):
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


@dataclass
class Cutover:
    """What this boot shows of the cutover: the journal view (None if unusable), the boot, the nginx marker."""
    view: dict | None
    problem: str
    boot: str
    site_open: bool
    reopened_at: int | None
    ingress_folder: bool


def valid_view(record: object) -> bool:
    if not (isinstance(record, dict) and record.get("schema") == VIEW_SCHEMA
            and type(record.get("observed_unix_s")) is int and isinstance(record.get("boot_id"), str)
            and record.get("journal") in ("absent", "present", "unreadable")):
        return False
    if record["journal"] != "present":
        return True
    return (isinstance(record.get("run_id"), str) and isinstance(record.get("phase"), str)
            and WORD.fullmatch(record["phase"]) is not None and type(record.get("writes_possible")) is bool
            and (record.get("started_at") is None or type(record["started_at"]) is int)
            and (record.get("pending_action") is None or isinstance(record["pending_action"], str))
            and (record.get("hold_boot_id") is None or isinstance(record["hold_boot_id"], str)))


def read_view(path: Path, now: int, boot: str, max_age: int) -> tuple[dict | None, str]:
    try:
        with open(path, "rb") as stream:
            raw = stream.read(64 * 1024 + 1)
    except FileNotFoundError:
        return None, "the cutover view is missing"
    except OSError:
        return None, "the cutover view is unreadable"
    try:
        record = json.loads(raw) if len(raw) <= 64 * 1024 else None
    except (ValueError, UnicodeError):
        record = None
    if not valid_view(record):
        return None, "the cutover view is invalid"
    if record["boot_id"] != boot:
        return None, "the cutover view is from another boot"
    if not -60 <= now - record["observed_unix_s"] <= max_age:
        return None, "the cutover view is stale"
    return record, ""


def cutover_state(context: Context) -> Cutover | None:
    """None when the probe plan has no cutover section (no writer hold on this host)."""
    settings = context.plan["cutover"]
    if settings is None:
        return None
    if "cutover" not in context.cache:
        boot = context.boot_id()
        view, problem = read_view(Path(settings["view_file"]), context.now, boot, context.hold["max_view_age_seconds"])
        marker = Path(settings["ingress_open"])
        try:
            reopened_at, site_open = int(os.stat(marker).st_mtime), True  # nginx tests -f, following links.
        except FileNotFoundError:
            reopened_at, site_open = None, False
        context.cache["cutover"] = Cutover(view, problem, boot, site_open, reopened_at, marker.parent.is_dir())
    return context.cache["cutover"]


def authoritative_engine(context: Context) -> str:
    """The engine the cutover journal makes authoritative; the plan's value only where no writer hold exists."""
    state = cutover_state(context)
    if state is None:
        return context.plan["expected_engine"]
    view = state.view
    if view is None:
        raise DetectorError("authoritative engine unknown: " + state.problem)
    if view["journal"] == "unreadable":
        raise DetectorError("authoritative engine unknown: the cutover journal is unreadable")
    if view["journal"] == "absent":
        if state.ingress_folder:
            raise DetectorError("authoritative engine unknown: the writer hold has no cutover journal")
        return context.plan["expected_engine"]
    if view["phase"] in SQLITE_PHASES:
        return "sqlite"
    if view["phase"] in MYSQL_PHASES:
        return "mysql"
    raise DetectorError("authoritative engine unknown in cutover phase " + view["phase"])


def data_engine(context: Context) -> str:
    """The database the data detectors read: the authoritative one, else the one the app is set to.

    An unknown authority already pages through wrong_database_engine."""
    if "data_engine" not in context.cache:
        try:
            engine = authoritative_engine(context)
        except DetectorError:
            try:
                engine = app_engine(context.plan)[0]
            except (OSError, DetectorError):
                engine = None
            if engine not in ("sqlite", "mysql"):
                engine = context.plan["expected_engine"]
        context.cache["data_engine"] = engine
    return context.cache["data_engine"]


def hold_verdict(context: Context) -> tuple[bool, str]:
    """Whether a held site is inside an approved cutover hold of this boot and within the plan's limits."""
    state = cutover_state(context)
    view = state.view
    if view is None:
        return False, "cutover state unknown: " + state.problem
    if view["journal"] == "absent":
        return False, "site held with no cutover journal"
    if view["journal"] == "unreadable":
        return False, "site held: the cutover journal is unreadable"
    phase, started = view["phase"], view["started_at"]
    same_boot = view["hold_boot_id"] == state.boot
    if phase in ("planned", "sqlite_authoritative") and (started is None or not same_boot):
        return False, "site held after a reboot or an unfinished reopen: run cli.py refresh, then cli.py reopen"
    if phase == "recovery_required":
        return False, "site held: the cutover needs verified recovery"
    if started is None or phase not in HELD_PHASES | AFTER_HOLD_PHASES:
        return False, f"site held in cutover phase {phase} with no approved hold"
    if not same_boot:
        return False, f"cutover hold ({phase}) survived a reboot"
    age = context.now - started
    limit = context.hold["decision_deadline_seconds" if phase in HELD_PHASES else "max_write_outage_seconds"]
    if age < 0:
        return False, "cutover hold starts in the future: the host clock moved"
    if age >= limit:
        return False, f"cutover hold ({phase}) is {age // 60} min old, over its {limit // 60} min limit"
    return True, f"approved cutover hold ({phase}), {age // 60} of {limit // 60} min"


def hold_in_force(context: Context) -> bool:
    state = cutover_state(context)
    return state is not None and not state.site_open and hold_verdict(context)[0]


def hold_span(context: Context) -> tuple[int, int] | None:
    """Seconds in which nginx held the site for a cutover hold of this boot, up to the write-outage limit."""
    state = cutover_state(context)
    if state is None or state.view is None or state.view["journal"] != "present":
        return None
    started = state.view["started_at"]
    if started is None or state.view["hold_boot_id"] != state.boot or started > context.now:
        return None
    end = min(context.now, started + context.hold["max_write_outage_seconds"])
    if state.site_open:
        if state.reopened_at is None or state.reopened_at < started:
            return None
        end = min(end, state.reopened_at)  # The reopen writes the open marker.
    return started, end


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
        if any(os.path.lexists(str(path) + suffix) for suffix in ("-wal", "-journal")):
            break  # A writer or a journal is there, so the normal open's failure stands (no busy-wait loop).
        rows = immutable_rows(path, sql, params)
        if rows is not None:
            return rows
    raise DetectorError("SQLite read failed")


def mysql_rows(context: Context, sql: str) -> list[list[str]]:
    if context.mysql_query is None:
        raise DetectorError("MySQL monitor is not configured")
    return context.mysql_query(sql)


def app_rows(context: Context, sql: str) -> list:
    if data_engine(context) == "sqlite":
        return [list(row) for row in sqlite_rows(context.plan, sql)]
    return mysql_rows(context, sql)


def database_down(context: Context) -> tuple[str, str]:
    if data_engine(context) == "sqlite":
        # SELECT 1 never reads the file; the schema read proves the header and pages open.
        rows = sqlite_rows(context.plan, "SELECT COUNT(*) FROM sqlite_master")
        healthy = bool(rows) and isinstance(rows[0][0], int) and rows[0][0] > 0
    else:
        rows = mysql_rows(context, "SELECT 1")
        healthy = bool(rows) and rows[0][:1] == ["1"]
    return ("ok", "database answered") if healthy else ("fail", "database gave no answer")


def wrong_database_engine(context: Context) -> tuple[str, str]:
    expected = authoritative_engine(context)
    engine, source = app_engine(context.plan)
    if engine != expected and engine in ("sqlite", "mysql") and hold_in_force(context):
        # No writer is admitted on either engine while the hold lasts; the reopen checks again.
        return "ok", f"app set to {engine} inside the approved cutover hold; {expected} is authoritative"
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
    total = errors = held = 0
    # nginx answers 503 to every request while an approved cutover hold holds the site; those are not faults.
    span = hold_span(context)
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
                    status = int(match.group(2))
                    if span is not None and status == 503 and span[0] <= moment <= span[1]:
                        held += 1
                        continue
                    total += 1
                    errors += 500 <= status <= 599
    detail = f"{errors} of {total} requests failed in {limits['window_seconds'] // 60} min"
    detail += f"; {held} held by the cutover" if held else ""
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


def archived_through(plan: dict) -> int:
    """When MySQL closed the newest archived binary log: its last write, the rotation to the checkpoint's next file.

    MySQL 8.4.13 last writes a log when it rotates away from it (its mtime is the FLUSH second,
    checked natively), and every transaction committed before then is archived. The checkpoint's own write
    time is not the recovery point: after an outage the collector's file and byte limits cut
    each catch-up batch to the oldest closed logs, and every batch rewrites the checkpoint now."""
    with open(plan["binlog_checkpoint_file"], "rb") as stream:
        record = json.loads(stream.read(64 * 1024 + 1))
    next_file = record.get("next_file") if isinstance(record, dict) else None
    match = BINLOG_NAME.fullmatch(next_file) if isinstance(next_file, str) else None
    if match is None:
        raise ValueError("the archive checkpoint names no binary log")
    archived = f"{match.group(1)}.{int(match.group(2)) - 1:06d}"
    return int(os.stat(Path(plan["binlog_dir"]) / archived).st_mtime)


def backup_lag(context: Context) -> tuple[str, str]:
    limits = context.threshold("backup_lag")
    try:
        lag = context.now - archived_through(context.plan)
    except (OSError, ValueError):
        lag = None  # No checkpoint, or the newest archived log is gone (purged only when over an hour old).
    late = lag is None or lag > limits["max_lag_seconds"]
    count = context.memory.get("backup_lag_late", 0) + 1 if late else 0
    context.memory["backup_lag_late"] = count
    detail = "no archived binary log found" if lag is None else f"archived through {lag} s ago"
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


def site_held(context: Context) -> tuple[str, str]:
    """nginx holds the site while the open marker is missing. Only an approved hold of this boot within the
    plan's limits is expected; any other held site, including one after a reboot, pages."""
    state = cutover_state(context)
    if state.site_open:
        return "ok", "site open"
    if state.view is not None and state.view["journal"] == "absent" and not state.ingress_folder:
        return "ok", "writer hold not installed"
    expected, detail = hold_verdict(context)
    return ("ok" if expected else "fail"), detail


def writer_token_held_too_long(context: Context) -> tuple[str, str]:
    if authoritative_engine(context) != "mysql":
        return "ok", "SQLite is authoritative; there is no writer token"
    limit = int(context.threshold("writer_token_held_too_long")["max_hold_seconds"])
    rows = mysql_rows(context, TOKEN_HOLDERS.format(limit=limit))
    count, oldest = int(rows[0][0]), int(rows[0][1])
    # The query counts holders of {limit} seconds or more (>=), as the PHP watchdog does.
    if count:
        return "fail", (f"{count} session{'s' if count != 1 else ''} held the writer token for {limit} s or more "
                        f"(oldest {oldest} s)")
    return "ok", f"no session held the writer token for {limit} s or more"


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
    "site_held": site_held,
    "writer_token_held_too_long": writer_token_held_too_long,
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
