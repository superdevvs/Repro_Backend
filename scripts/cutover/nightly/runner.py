"""Nightly SQLite-to-MySQL parity runner.

One command produces every reviewer input for one scheduled UTC night: the
dated private capture, the import with both forward verifications, reverse
SQLite parity, finance parity, applicant files and the CRM review. Every run is
bound to its snapshot, the candidate release and the reviewed scope.

The runner is meant to run on the host that holds the live SQLite file, as the
unprivileged deploy user, from a clean checkout of the candidate release. It
never uses ssh or a network connection. `run` is a dry run unless `--execute`
and an owner approval file covering the night and the exact configuration are
both given. Completed stages are journaled with their output digests, so a run
can be resumed and a finished night is never run again.
"""
from __future__ import annotations

import argparse
import copy
from contextlib import closing
from dataclasses import dataclass
from datetime import date, datetime, timezone
import hashlib
import json
import math
import os
from pathlib import Path
import re
import secrets
import shutil
import signal
import sqlite3
import stat
import subprocess
import time
from typing import Protocol

from scripts.cutover.readiness import check, summarize_nightly_parity, verify_nightly_capture
from scripts.cutover.runner import digest, is_sha256

CONFIG_SCHEMA = "repro.nightly-runner.config.v1"
APPROVAL_SCHEMA = "repro.nightly-runner.approval.v1"
JOURNAL_SCHEMA = "repro.nightly-runner.journal.v1"
RECEIPT_SCHEMA = "repro.nightly-runner.receipt.v1"
EVIDENCE_SCHEMA = "repro.nightly-crm-parity.evidence.v1"
STAGES = ("capture", "plan", "target", "import_and_verify", "reverse", "finance", "applicant_files",
          "crm_review", "report")
# These stages share one disposable MySQL process. A resumed run starts them again together.
MYSQL_GROUP = ("target", "import_and_verify", "reverse")
CANDIDATE_TOOLS = {
    "backup": "scripts/deploy/backup-sqlite.php",
    "scope_audit": "scripts/mysql/scope-audit.py",
    "migrate": "scripts/mysql/migrate.py",
    "template": "scripts/mysql/build-sqlite-template.py",
    "census": "scripts/mysql/report_week_boundaries.py",
    "verify_fields": "scripts/mysql/verify-fields.php",
    "export": "scripts/mysql/export-sqlite.py",
    "crm_audit": "scripts/mysql/crm-snapshot-audit.py",
    "applicant_files": "scripts/mysql/verify-crm-applicant-files.py",
}
FINANCE_ISOLATION = ("passed", "inet_families_denied", "private_network_namespace", "production_paths_hidden",
                     "read_only_input")
FINANCE_REPORTS = ("crm_client_import_dry_run", "last_completed_photographer_reconciliation", "past_due", "weeks")
FINANCE_GENERATIONS = ("client_invoices", "editor_payout_rows", "payout_work_allocations", "week",
                       "weekly_payout_invoices")
FINANCE_PLACEHOLDERS = {"python", "php", "snapshot", "snapshot_sha256", "release", "as_of_utc", "output"}
DATABASE = "repro_nightly_data"
DAY = re.compile(r"[0-9]{4}-[0-9]{2}-[0-9]{2}")
SIZE = re.compile(r"([1-9][0-9]{0,5})([MG])")
# Durability-neutral options only. Anything else, including sync_binlog,
# innodb_flush_log_at_trx_commit, innodb_doublewrite or skipping the binary
# log, is refused because it would change the recovery evidence.
TUNING = {
    "innodb_buffer_pool_size": ("size", 1024, 16 * 1024),
    "innodb_redo_log_capacity": ("size", 512, 16 * 1024),
    "innodb_log_buffer_size": ("size", 16, 1024),
    "innodb_io_capacity": ("int", 100, 20_000),
    "innodb_io_capacity_max": ("int", 100, 40_000),
    "innodb_flush_method": ("choice", ("fsync", "O_DIRECT")),
    "innodb_adaptive_hash_index": ("choice", ("ON", "OFF")),
}
REQUIRED_TUNING = ("innodb_buffer_pool_size", "innodb_redo_log_capacity")
DURABLE_SETTINGS = ("1", "1", "OFF", "ROW", "FULL", "ON", "1", "1", "ON")
IDENTITY_SQL = ("SELECT VERSION(),@@server_uuid,@@skip_networking,@@log_bin,@@event_scheduler,@@binlog_format,"
                "@@binlog_row_image,@@gtid_mode,@@sync_binlog,@@innodb_flush_log_at_trx_commit,@@innodb_doublewrite")
CONFIG_FIELDS = {
    "schema", "backend_root", "candidate_root", "native_mysql_root", "php", "python", "pymysql_wheel",
    "deploy_lock", "scope", "approved_exceptions", "reviewed_schema_sha256", "crm_migrations", "budget", "guards",
    "priority", "mysql_tuning", "reverse_triggers", "finance", "applicant_files_root", "retain_snapshots",
}


class Refused(RuntimeError):
    """The run cannot start. Nothing was changed."""


class Stop(Exception):
    """A stage stopped the night. The reason and partial measurements are journaled."""

    def __init__(self, reason: str, result: dict | None = None):
        super().__init__(reason)
        self.reason = reason
        self.result = result or {}


@dataclass(frozen=True)
class LiveDatabase:
    path: Path
    identity: tuple
    size: int


@dataclass(frozen=True)
class Completed:
    returncode: int
    stdout: str
    stderr: str = ""


class Host(Protocol):
    """Every effect on the machine. Tests supply a fake; LocalHost is the real one."""

    def utc_now(self) -> datetime: ...
    def monotonic(self) -> float: ...
    def sleep(self, seconds: float) -> None: ...
    def deployed_release(self) -> str: ...
    def live_database(self) -> LiveDatabase: ...
    def free_bytes(self, path: Path) -> int: ...
    def mem_available_kib(self) -> int: ...
    def checkout(self) -> tuple[str, bool]: ...
    def deploy_lock_free(self) -> bool: ...
    def native_binaries_verified(self) -> bool: ...
    def stop_stale_mysqld(self, pid: int | None, work: Path) -> None: ...
    def start_mysqld(self, argv: list[str], *, env: dict, cwd: Path): ...
    def run(self, argv: list[str], *, timeout_s: float, input: str | None = None, env: dict | None = None,
            cwd: Path | None = None) -> Completed: ...


# Configuration ---------------------------------------------------------------

def need(condition: object, reason: str) -> None:
    if not condition:
        raise Refused(reason)


def absolute(value: object) -> bool:
    return isinstance(value, str) and (value.startswith("/") or Path(value).is_absolute())


def pinned(value: object, name: str, *, nullable: bool = False) -> None:
    if value is None and nullable:
        return
    need(isinstance(value, dict) and set(value) == {"path", "sha256"} and absolute(value["path"])
         and is_sha256(value["sha256"]), f"config-{name}-malformed")


def size_mib(value: object) -> int | None:
    match = SIZE.fullmatch(value) if isinstance(value, str) else None
    if match is None:
        return None
    return int(match[1]) * (1024 if match[2] == "G" else 1)


def validate_tuning(tuning: object) -> None:
    need(isinstance(tuning, dict), "tuning-malformed")
    for name, value in tuning.items():
        need(name in TUNING, f"tuning-option-refused:{name}")
        kind, *bounds = TUNING[name]
        if kind == "size":
            mib = size_mib(value)
            need(mib is not None and bounds[0] <= mib <= bounds[1], f"tuning-value-refused:{name}")
        elif kind == "int":
            need(type(value) is int and bounds[0] <= value <= bounds[1], f"tuning-value-refused:{name}")
        else:
            need(value in bounds[0], f"tuning-value-refused:{name}")
    need(all(name in tuning for name in REQUIRED_TUNING), "tuning-buffer-pool-and-redo-required")
    if "innodb_io_capacity_max" in tuning:
        need(tuning["innodb_io_capacity_max"] >= tuning.get("innodb_io_capacity", 200), "tuning-io-capacity-order")


def validate_config(data: object) -> dict:
    need(isinstance(data, dict) and set(data) == CONFIG_FIELDS and data["schema"] == CONFIG_SCHEMA, "config-malformed")
    for name in ("backend_root", "candidate_root", "native_mysql_root", "php", "python", "deploy_lock"):
        need(absolute(data[name]), f"config-{name}-must-be-absolute")
    pinned(data["pymysql_wheel"], "pymysql-wheel")
    pinned(data["approved_exceptions"], "approved-exceptions")
    pinned(data["reverse_triggers"], "reverse-triggers", nullable=True)
    scope = data["scope"]
    need(isinstance(scope, dict) and set(scope) == {"path", "sha256"} and isinstance(scope["path"], str)
         and not absolute(scope["path"]) and ".." not in Path(scope["path"]).parts and is_sha256(scope["sha256"]),
         "config-scope-malformed")
    need(is_sha256(data["reviewed_schema_sha256"]), "config-reviewed-schema-malformed")
    migrations = data["crm_migrations"]
    need(isinstance(migrations, list) and migrations and all(isinstance(name, str) and name for name in migrations)
         and len(set(migrations)) == len(migrations), "config-crm-migrations-malformed")
    budget = data["budget"]
    need(isinstance(budget, dict) and set(budget) == {"import_and_verify_ms", "fail_fast", "baseline"}
         and type(budget["import_and_verify_ms"]) is int and 0 < budget["import_and_verify_ms"] <= 12 * check.MINUTE
         and type(budget["fail_fast"]) is bool, "config-budget-malformed")
    baseline = budget["baseline"]
    need(isinstance(baseline, dict) and set(baseline) == {"snapshot_bytes", "import_and_verify_ms"}
         and all(type(value) is int and value > 0 for value in baseline.values()), "config-budget-baseline-malformed")
    guards = data["guards"]
    need(isinstance(guards, dict) and set(guards) == {"min_free_bytes", "free_bytes_per_source_byte",
                                                      "max_source_bytes", "min_mem_available_kib", "mem_margin_kib"}
         and all(type(value) is int and value > 0 for value in guards.values()), "config-guards-malformed")
    priority = data["priority"]
    need(isinstance(priority, dict) and set(priority) == {"nice", "ionice_class", "ionice_level", "capture_nice"}
         and all(type(priority[name]) is int and 0 <= priority[name] <= 19 for name in ("nice", "capture_nice"))
         and priority["ionice_class"] in (2, 3)
         and (priority["ionice_level"] is None if priority["ionice_class"] == 3
              else type(priority["ionice_level"]) is int and 0 <= priority["ionice_level"] <= 7),
         "config-priority-malformed")
    validate_tuning(data["mysql_tuning"])
    finance = data["finance"]
    if finance is not None:
        need(isinstance(finance, dict) and set(finance) == {"argv", "pins", "timeout_s"}
             and isinstance(finance["argv"], list) and finance["argv"]
             and all(isinstance(value, str) for value in finance["argv"])
             and isinstance(finance["pins"], dict) and finance["pins"]
             and all(absolute(path) and is_sha256(sha) for path, sha in finance["pins"].items())
             and type(finance["timeout_s"]) is int and 0 < finance["timeout_s"] <= 4 * 3600, "config-finance-malformed")
        for value in finance["argv"]:
            need(set(re.findall(r"\{([a-z0-9_]+)\}", value)) <= FINANCE_PLACEHOLDERS, "config-finance-placeholder")
    need(data["applicant_files_root"] is None or absolute(data["applicant_files_root"]), "config-applicant-root")
    keep = data["retain_snapshots"]
    need(keep is None or (type(keep) is int and keep >= 1), "config-retain-snapshots")
    return data


class Config:
    def __init__(self, data: dict):
        self.data = validate_config(copy.deepcopy(data))
        self.sha256 = digest(self.data)

    @classmethod
    def load(cls, path: Path) -> "Config":
        try:
            return cls(check.load(path))
        except (check.Missing, check.Failed) as error:
            raise Refused(f"config-unreadable: {error}") from error


# Files, locks and journal -------------------------------------------------------

def file_sha256(path: Path) -> str:
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def write_json(path: Path, value: object, *, indent: int | None = 2) -> None:
    data = (json.dumps(value, sort_keys=True, indent=indent, allow_nan=False) + "\n").encode("utf-8")
    write_bytes(path, data)


def write_bytes(path: Path, data: bytes) -> None:
    temporary = path.with_name(f".{path.name}.{secrets.token_hex(4)}.tmp")
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_BINARY", 0), 0o600)
    try:
        with os.fdopen(descriptor, "wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)


def private_directory(path: Path) -> None:
    path.mkdir(mode=0o700, exist_ok=True)
    info = path.lstat()
    need(stat.S_ISDIR(info.st_mode), "state-dir-unsafe")
    if os.name == "posix":
        need(info.st_uid == os.geteuid() and not info.st_mode & 0o077, "state-dir-unsafe")


class RunLock:
    """Only one nightly runner may work in a state directory at a time."""

    def __init__(self, state_dir: Path):
        self.path = state_dir / "runner.lock"
        self.stream = None

    def __enter__(self):
        self.stream = self.path.open("a+b")
        try:
            if os.name == "nt":
                import msvcrt
                self.stream.seek(0)
                msvcrt.locking(self.stream.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                import fcntl
                fcntl.flock(self.stream.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError as error:
            self.stream.close()
            raise Refused("runner-locked: another nightly run holds the state directory") from error
        return self

    def __exit__(self, *_):
        if os.name == "nt":
            import msvcrt
            self.stream.seek(0)
            msvcrt.locking(self.stream.fileno(), msvcrt.LK_UNLCK, 1)
        self.stream.close()


def last_json(output: str) -> dict:
    lines = [line for line in output.splitlines() if line.strip()]
    try:
        value = json.loads(lines[-1]) if lines else None
    except ValueError:
        value = None
    return value if isinstance(value, dict) else {}


def milliseconds(seconds: float) -> int:
    return round(seconds * 1000)


def is_crm_table(name: str) -> bool:
    return name.startswith(("crm_", "recruit_")) or name == "email_suppressions"


# Runner ------------------------------------------------------------------------

class NightlyRunner:
    def __init__(self, config: Config, host: Host, state_dir: Path, day: str):
        need(isinstance(day, str) and DAY.fullmatch(day), "day-malformed")
        self.config = config
        self.cfg = config.data
        self.host = host
        self.state_dir = state_dir
        self.day = day
        self.scheduled = date.fromisoformat(day)
        self.run_dir = state_dir / day
        self.candidate_root = Path(self.cfg["candidate_root"])
        self.tools = {name: self.candidate_root / relative for name, relative in CANDIDATE_TOOLS.items()}
        self.scope_path = self.candidate_root / self.cfg["scope"]["path"]
        self.process = None
        self.session = None

    # Command builders shared by the dry run and the real stages ---------------
    def priority(self, *, capture: bool = False) -> list[str]:
        priority = self.cfg["priority"]
        if capture:
            return ["/usr/bin/nice", "-n", str(priority["capture_nice"]), "/usr/bin/ionice", "-c", "3"]
        prefix = ["/usr/bin/nice", "-n", str(priority["nice"]), "/usr/bin/ionice", "-c", str(priority["ionice_class"])]
        if priority["ionice_level"] is not None:
            prefix += ["-n", str(priority["ionice_level"])]
        return prefix

    def python(self, tool: str | Path, *arguments) -> list[str]:
        return [*self.priority(), self.cfg["python"], "-B", str(tool), *map(str, arguments)]

    def mysqld_base(self, work: Path) -> list[str]:
        native = self.cfg["native_mysql_root"]
        return [f"{native}/bin/mysqld", "--no-defaults", f"--basedir={native}",
                f"--plugin-dir={native}/lib/mysql/plugin", f"--lc-messages-dir={native}/share/mysql-8.4",
                f"--datadir={work}/data", f"--tmpdir={work}", f"--log-error={work}/mysql.log"]

    def mysqld_runtime(self, work: Path) -> list[str]:
        flags = [
            f"--socket={work}/mysql.sock", f"--pid-file={work}/mysql.pid", "--skip-networking", "--mysqlx=OFF",
            f"--log-bin={work}/data/binlog", "--server-id=64085", "--binlog-format=ROW", "--binlog-row-image=FULL",
            "--gtid-mode=ON", "--enforce-gtid-consistency=ON", "--binlog-expire-logs-auto-purge=OFF",
            "--log-bin-trust-function-creators=ON", "--local-infile=OFF", "--event-scheduler=OFF",
            "--persisted-globals-load=OFF", "--performance-schema=OFF", "--skip-name-resolve", "--max-connections=12",
            "--max-allowed-packet=268435456", "--innodb-flush-log-at-trx-commit=1", "--sync-binlog=1",
            "--innodb-doublewrite=ON", "--secure-file-priv=NULL", "--default-time-zone=+00:00",
            "--require-secure-transport=ON",
        ]
        flags += [f"--{name.replace('_', '-')}={value}" for name, value in sorted(self.cfg["mysql_tuning"].items())]
        return flags

    def mysql_client(self, work: Path, *, root_options: bool = True) -> list[str]:
        defaults = f"--defaults-file={work}/root.cnf" if root_options else "--no-defaults"
        return [f"{self.cfg['native_mysql_root']}/bin/mysql", defaults, "--no-login-paths", "--protocol=SOCKET",
                f"--socket={work}/mysql.sock", "-uroot", "--batch", "--skip-column-names", "--connect-timeout=2"]

    def commands(self, step: str, v: dict) -> list[str]:
        cfg, tools = self.cfg, self.tools
        exceptions = cfg["approved_exceptions"]
        if step == "capture":
            return [*self.priority(capture=True), cfg["php"], str(tools["backup"]), str(v["live_db"]), str(v["snapshot"])]
        if step == "scope_audit":
            return self.python(tools["scope_audit"], "--scope", self.scope_path, "--scope-sha256", cfg["scope"]["sha256"],
                               "--snapshot", v["snapshot"], "--snapshot-sha256", v["snapshot_sha256"])
        if step == "plan":
            return self.python(tools["migrate"], "plan", "--snapshot", v["snapshot"], "--snapshot-sha256",
                               v["snapshot_sha256"], "--output", self.run_dir / "forward-plan")
        if step == "template":
            return self.python(tools["template"], "--snapshot", v["snapshot"], "--snapshot-sha256", v["snapshot_sha256"],
                               "--output", self.run_dir / "reverse-schema")
        if step == "census":
            return self.python(tools["census"], "--snapshot", v["snapshot"], "--snapshot-sha256", v["snapshot_sha256"],
                               "--as-of-utc", v["as_of_utc"])
        if step == "mysqld_initialize":
            return [*self.priority(), *self.mysqld_base(v["work"]), "--initialize-insecure"]
        if step == "mysqld":
            return [*self.priority(), *self.mysqld_base(v["work"]), *self.mysqld_runtime(v["work"])]
        if step == "import":
            return self.python(tools["migrate"], "import-empty", "--snapshot", v["snapshot"], "--snapshot-sha256",
                               v["snapshot_sha256"], "--database", DATABASE, "--expected-server-uuid", v["uuid"],
                               "--approved-exceptions", exceptions["path"], "--exceptions-file-sha256",
                               exceptions["sha256"], "--credentials", f"{v['work']}/transfer.json",
                               "--schema-sha256", cfg["reviewed_schema_sha256"], "--output", f"{v['work']}/import")
        if step in ("verify_forward", "verify_reverse"):
            source = ("snapshot", "snapshot_sha256") if step == "verify_forward" else ("recovered", "recovered_sha256")
            return [*self.priority(), cfg["php"], "-d", "display_errors=0", "-d", "serialize_precision=-1",
                    str(tools["verify_fields"]), f"--snapshot={v[source[0]]}", f"--snapshot-sha256={v[source[1]]}",
                    f"--schema-plan={v['work']}/import/schema-plan.json", f"--schema-plan-sha256={v['schema_plan_sha256']}",
                    f"--credentials={v['work']}/compare.json", f"--database={DATABASE}",
                    f"--expected-server-uuid={v['uuid']}", "--writers-stopped"]
        if step == "reverse_triggers":
            return self.python(cfg["reverse_triggers"]["path"], "--code-root", self.candidate_root, "--schema-template",
                               v["snapshot"], "--snapshot-sha256", v["snapshot_sha256"], "--worker", v["work"],
                               "--credentials", f"{v['work']}/transfer.json", "--expected-server-uuid", v["uuid"])
        if step == "export":
            return self.python(tools["export"], "--schema-template", self.run_dir / "reverse-schema/schema-template.sqlite",
                               "--schema-template-sha256", v["template_sha256"], "--credentials",
                               f"{v['work']}/reverse.json", "--database", DATABASE, "--expected-server-uuid", v["uuid"],
                               "--approved-exceptions", exceptions["path"], "--exceptions-file-sha256",
                               exceptions["sha256"], "--writers-stopped", "--output", f"{v['work']}/reverse")
        if step == "crm_audit":
            return self.python(tools["crm_audit"], "--snapshot", v["snapshot"], "--snapshot-sha256", v["snapshot_sha256"],
                               "--key-file", f"{v['work']}/crm-audit.key", "--compare-snapshot", v["recovered"],
                               "--compare-snapshot-sha256", v["recovered_sha256"])
        if step == "finance":
            values = {"python": cfg["python"], "php": cfg["php"], "snapshot": str(v["snapshot"]),
                      "snapshot_sha256": v["snapshot_sha256"], "release": v["release"], "as_of_utc": v["as_of_utc"],
                      "output": str(self.run_dir / "finance-receipt-private.json")}
            return [*self.priority(), *(re.sub(r"\{([a-z0-9_]+)\}", lambda m: values[m[1]], part)
                                        for part in cfg["finance"]["argv"])]
        if step == "applicant_files":
            return self.python(tools["applicant_files"], "--snapshot", v["snapshot"], "--snapshot-sha256",
                               v["snapshot_sha256"], "--private-root", cfg["applicant_files_root"],
                               "--key-file", self.run_dir / ".applicant-files.key")
        raise ValueError(step)

    def tool_env(self, home: Path) -> dict:
        return {"PATH": "/usr/bin:/bin", "HOME": str(home), "LC_ALL": "C.UTF-8", "TMPDIR": str(home),
                "PYTHONPATH": self.cfg["pymysql_wheel"]["path"]}

    # Guards ------------------------------------------------------------------
    def projected_ms(self, snapshot_bytes: int) -> int:
        baseline = self.cfg["budget"]["baseline"]
        return math.ceil(baseline["import_and_verify_ms"] * snapshot_bytes / baseline["snapshot_bytes"])

    def required_free_bytes(self, source_bytes: int) -> int:
        guards = self.cfg["guards"]
        return max(guards["min_free_bytes"], guards["free_bytes_per_source_byte"] * source_bytes)

    def required_mem_kib(self) -> int:
        pool_kib = size_mib(self.cfg["mysql_tuning"]["innodb_buffer_pool_size"]) * 1024
        return max(self.cfg["guards"]["min_mem_available_kib"], pool_kib + self.cfg["guards"]["mem_margin_kib"])

    def pins_hold(self) -> dict:
        cfg = self.cfg
        checks = {"scope": (self.scope_path, cfg["scope"]["sha256"]),
                  "exceptions": (Path(cfg["approved_exceptions"]["path"]), cfg["approved_exceptions"]["sha256"]),
                  "driver": (Path(cfg["pymysql_wheel"]["path"]), cfg["pymysql_wheel"]["sha256"])}
        if cfg["reverse_triggers"] is not None:
            checks["reverse-helper"] = (Path(cfg["reverse_triggers"]["path"]), cfg["reverse_triggers"]["sha256"])
        for index, (path, sha) in enumerate(sorted((cfg["finance"] or {"pins": {}})["pins"].items())):
            checks[f"finance-{index}"] = (Path(path), sha)
        result = {}
        for name, (path, sha) in checks.items():
            try:
                result[name] = file_sha256(path) == sha
            except OSError:
                result[name] = False
        return result

    # Dry run -----------------------------------------------------------------
    def plan(self) -> dict:
        """Describe the night and evaluate read-only guards. Writes nothing and runs no tool."""
        host = self.host
        release, clean = host.checkout()
        try:
            live = host.live_database()
        except Refused:
            live = None
        size = live.size if live else 0
        free = host.free_bytes(self.state_dir if self.state_dir.exists() else self.state_dir.parent)
        pins = self.pins_hold()
        placeholders = {
            "live_db": live.path if live else "<live database>", "snapshot": self.run_dir / "snapshot.sqlite",
            "snapshot_sha256": "<snapshot_sha256>", "as_of_utc": "<capture completed UTC>", "work": self.run_dir / "work-<id>",
            "uuid": "<disposable server uuid>", "schema_plan_sha256": "<schema plan sha256>",
            "template_sha256": "<reverse template sha256>", "recovered": self.run_dir / "work-<id>/reverse/database.sqlite",
            "recovered_sha256": "<recovered sha256>", "release": release,
        }
        steps = {
            "capture": ["capture"], "plan": ["scope_audit", "plan", "template", "census"],
            "target": ["mysqld_initialize", "mysqld"], "import_and_verify": ["import", "verify_forward"],
            "reverse": [*(["reverse_triggers"] if self.cfg["reverse_triggers"] else []), "export", "verify_reverse",
                        "crm_audit"],
            "finance": ["finance"] if self.cfg["finance"] else [],
            "applicant_files": ["applicant_files"] if self.cfg["applicant_files_root"] else [],
            "crm_review": [], "report": [],
        }
        stages = [{"name": name, "commands": [self.commands(step, placeholders) for step in steps[name]]}
                  for name in STAGES]
        return {
            "dry_run": True, "scheduled_day_utc": self.day, "config_sha256": self.config.sha256,
            "run_dir": str(self.run_dir), "live_release": host.deployed_release(), "candidate_release": release,
            "guards": {
                "scheduled_day_is_today": {"ok": host.utc_now().date() == self.scheduled},
                "candidate_clean": {"ok": clean},
                "pins": {"ok": all(pins.values()), "detail": pins},
                "live_database": {"ok": live is not None, "bytes": size},
                "free_space": {"ok": live is not None and free > self.required_free_bytes(size),
                               "free_bytes": free, "required_bytes": self.required_free_bytes(size)},
                "memory": {"ok": host.mem_available_kib() >= self.required_mem_kib(),
                           "required_kib": self.required_mem_kib()},
            },
            "budget": {**self.cfg["budget"], "projected_ms": self.projected_ms(size) if size else None},
            "tuning": self.cfg["mysql_tuning"], "priority": self.cfg["priority"],
            "retain_snapshots": self.cfg["retain_snapshots"], "stages": stages,
        }

    # Execution ----------------------------------------------------------------
    def approve(self, approval: dict | None) -> None:
        need(approval is not None, "approval-required")
        need(isinstance(approval, dict) and set(approval) == {"schema", "first_day_utc", "last_day_utc", "config_sha256"}
             and approval["schema"] == APPROVAL_SCHEMA, "approval-malformed")
        try:
            first, last = (date.fromisoformat(approval[name]) for name in ("first_day_utc", "last_day_utc"))
        except (TypeError, ValueError) as error:
            raise Refused("approval-malformed") from error
        need(first <= self.scheduled <= last, "approval-day-outside-range")
        need(approval["config_sha256"] == self.config.sha256, "approval-config-differs")

    def run(self, approval: dict | None) -> dict:
        self.approve(approval)
        private_directory(self.state_dir)
        with RunLock(self.state_dir):
            journal = self.open_journal()
            if journal["status"] == "reviewer-inputs-ready":
                return json.loads((self.run_dir / "run-receipt.json").read_bytes())
            self.revalidate(journal)
            self.reset_incomplete(journal)
            journal["attempts"].append({"started_utc": self.host.utc_now().isoformat()})
            journal["status"], journal["reason"], journal["stopped_at"] = "running", None, None
            self.save(journal)
            try:
                for name in STAGES:
                    if journal["stages"].get(name, {}).get("status") == "done":
                        continue
                    started = self.host.monotonic()
                    try:
                        try:
                            result, outputs = getattr(self, f"stage_{name}")(journal)
                        except Refused as error:
                            # A host check that refuses mid-night stops the night like any other stage failure.
                            raise Stop(str(error)) from error
                        except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError) as error:
                            raise Stop(f"unexpected-{type(error).__name__}") from error
                    except Stop as stop:
                        journal["stages"][name] = {"status": "failed", "reason": stop.reason, "result": stop.result,
                                                   "elapsed_ms": milliseconds(self.host.monotonic() - started)}
                        raise
                    journal["stages"][name] = {
                        "status": "done", "result": result,
                        "elapsed_ms": milliseconds(self.host.monotonic() - started),
                        "outputs": {output: file_sha256(self.run_dir / output) for output in outputs},
                    }
                    self.save(journal)
                journal["status"] = "reviewer-inputs-ready"
            except Stop as stop:
                journal["status"], journal["reason"], journal["stopped_at"] = "stopped", stop.reason, name
            finally:
                self.end_mysql(journal)
            journal["attempts"][-1] |= {"finished_utc": self.host.utc_now().isoformat(), "status": journal["status"],
                                        "reason": journal["reason"]}
            receipt = self.receipt(journal)
            write_json(self.run_dir / "run-receipt.json", receipt)
            self.save(journal)
            if journal["status"] == "reviewer-inputs-ready":
                self.prune()
            return receipt

    def open_journal(self) -> dict:
        private_directory(self.run_dir)
        path = self.run_dir / "journal.json"
        release = self.host.checkout()[0]
        if not path.exists():
            journal = {"schema": JOURNAL_SCHEMA, "scheduled_day_utc": self.day, "config_sha256": self.config.sha256,
                       "candidate_release": release, "status": "new", "reason": None, "stopped_at": None,
                       "stages": {}, "attempts": [], "mysql": {"pid": None, "work": None}, "snapshot_pruned": False}
            self.save(journal)
            return journal
        journal = json.loads(path.read_bytes())
        need(journal.get("schema") == JOURNAL_SCHEMA and journal.get("scheduled_day_utc") == self.day,
             "journal-malformed")
        need(journal["config_sha256"] == self.config.sha256, "config-changed-for-this-night")
        need(journal["candidate_release"] == release, "candidate-changed-for-this-night")
        for name, stage in journal["stages"].items():
            if stage.get("status") != "done":
                continue
            for output, sha in stage["outputs"].items():
                try:
                    unchanged = file_sha256(self.run_dir / output) == sha
                except OSError:
                    unchanged = False
                need(unchanged, f"evidence-changed: {output} differs from the journal")
        return journal

    def revalidate(self, journal: dict) -> None:
        """A resumed night must run the code and pinned inputs its capture checked.

        A resume skips the capture stage, where the checkout's cleanliness and the
        pins are checked, so check them again here, and compare every candidate
        tool with the digest the capture recorded (git can be told to hide a
        change, for example with skip-worktree). Nothing is changed on refusal.
        """
        capture = journal["stages"].get("capture", {})
        if capture.get("status") != "done":
            return  # The capture stage runs again and checks all of this itself.
        need(self.host.checkout()[1], "candidate-checkout-dirty")
        for name, ok in self.pins_hold().items():
            need(ok, f"{'finance' if name.startswith('finance') else name}-pin-differs")
        tools = {}
        for name, tool in self.tools.items():
            try:
                tools[name] = file_sha256(tool)
            except OSError:
                tools[name] = None
        need(tools == capture["result"].get("tool_sha256"), "candidate-tools-changed-for-this-night")

    def save(self, journal: dict) -> None:
        write_json(self.run_dir / "journal.json", journal)

    def reset_incomplete(self, journal: dict) -> None:
        stages = journal["stages"]
        mysql = journal["mysql"]
        if mysql.get("work") and Path(mysql["work"]).exists():
            self.host.stop_stale_mysqld(mysql.get("pid"), Path(mysql["work"]))
            self.remove_work(Path(mysql["work"]))
        if any(stages.get(name, {}).get("status") != "done" for name in MYSQL_GROUP):
            for name in MYSQL_GROUP:
                stages.pop(name, None)
        for name in [name for name, stage in stages.items() if stage.get("status") != "done"]:
            del stages[name]
        if "capture" not in stages:
            (self.run_dir / "snapshot.sqlite").unlink(missing_ok=True)

    def remove_work(self, work: Path) -> None:
        if work.parent == self.run_dir and work.name.startswith("work-") and not work.is_symlink():
            shutil.rmtree(work, ignore_errors=True)

    def end_mysql(self, journal: dict) -> None:
        if self.process is not None and self.process.poll() is None:
            self.process.terminate()
            try:
                self.process.wait(timeout=35)
            except subprocess.TimeoutExpired:
                self.process.kill()
                self.process.wait(timeout=5)
        self.process = None
        self.session = None
        work = journal["mysql"].get("work")
        if work:
            self.remove_work(Path(work))

    def mysql(self, sql: str, *, expect: str | None = None, reason: str = "mysql-statement-failed") -> str:
        result = self.host.run([*self.mysql_client(self.session["work"]), "-e", sql], timeout_s=30)
        if result.returncode != 0 or (expect is not None and result.stdout.strip() != expect):
            raise Stop(reason)
        return result.stdout

    def done(self, journal: dict, name: str) -> dict:
        return journal["stages"][name]["result"]

    def values(self, journal: dict, **extra) -> dict:
        capture = self.done(journal, "capture")
        values = {"snapshot": self.run_dir / "snapshot.sqlite", "snapshot_sha256": capture["snapshot_sha256"],
                  "as_of_utc": capture["as_of_utc"], "release": journal["candidate_release"]}
        if self.session:
            values |= {"work": self.session["work"], "uuid": self.session["uuid"]}
        return values | extra

    def tool(self, step: str, values: dict, *, timeout_s: float, reason: str, home: Path | None = None) -> dict:
        result = self.host.run(self.commands(step, values), timeout_s=timeout_s, env=self.tool_env(home or self.run_dir),
                               cwd=home or self.run_dir)
        record = last_json(result.stdout)
        if result.returncode != 0:
            raise Stop(reason)
        return record | {"_stdout": result.stdout}

    # Stages ------------------------------------------------------------------
    def stage_capture(self, journal: dict) -> tuple[dict, list[str]]:
        host, cfg = self.host, self.cfg
        release, clean = host.checkout()
        if not clean:
            raise Stop("candidate-checkout-dirty")
        pins = self.pins_hold()
        for name, ok in pins.items():
            if not ok:
                raise Stop(f"{name.split('-')[0] if name.startswith('finance') else name}-pin-differs")
        if not host.deploy_lock_free():
            raise Stop("deploy-in-progress")
        if host.utc_now().date() != self.scheduled:
            raise Stop("capture-date-differs")
        try:
            live = host.live_database()
        except Refused as error:
            raise Stop(f"live-database-refused: {error}") from error
        if not 0 < live.size <= cfg["guards"]["max_source_bytes"]:
            raise Stop("source-size-out-of-range")
        if host.free_bytes(self.run_dir) <= self.required_free_bytes(live.size):
            raise Stop("insufficient-disk")
        live_release = host.deployed_release()
        snapshot = self.run_dir / "snapshot.sqlite"
        path = self.run_dir / "copy-receipt.json"
        started = host.monotonic()
        try:
            result = host.run(self.commands("capture", {"live_db": live.path, "snapshot": snapshot}), timeout_s=360,
                              env=self.tool_env(self.run_dir), cwd=self.run_dir)
            copy_seconds = host.monotonic() - started
            if result.returncode != 0 or result.stdout.strip() != "sqlite_consistent_backup=passed":
                raise Stop("capture-failed")
            after = host.live_database()
            if (after.path, after.identity) != (live.path, live.identity):
                raise Stop("source-changed-during-capture")
            if host.deployed_release() != live_release:
                raise Stop("release-changed-during-capture")
            if os.name == "posix" and stat.S_IMODE(snapshot.stat().st_mode) != 0o600:
                raise Stop("snapshot-not-private")
            if any(os.path.lexists(f"{snapshot}{suffix}") for suffix in ("-wal", "-shm", "-journal")):
                raise Stop("snapshot-has-sidecars")
            crm = self.crm_ledger(snapshot)
            completed = host.utc_now()
            if completed.date() != self.scheduled:
                raise Stop("capture-date-differs")
            receipt = {
                "backup_tool_sha256": file_sha256(self.tools["backup"]), "completed_utc": completed.isoformat(),
                "copy_seconds": round(copy_seconds, 3), "crm_migrations": crm["migrations"],
                "crm_tables": crm["tables"], "linked_client_contacts": crm["linked"], "release": live_release,
                "result": "read-only-online-backup-pass", "snapshot_bytes": snapshot.stat().st_size,
                "snapshot_path": str(snapshot), "snapshot_sha256": file_sha256(snapshot),
                "source_opened_read_only": True,
            }
            write_json(path, receipt, indent=None)
            try:
                verify_nightly_capture.read_capture(path, file_sha256(path), expected_source_release=live_release)
            except (check.Missing, check.Failed) as error:
                raise Stop("capture-receipt-invalid") from error
            tool_sha256 = {name: file_sha256(tool) for name, tool in self.tools.items()}
        except BaseException:
            # A copy that did not pass every check, including one cut short by a
            # timeout, a refusal or a signal, is private data with no receipt.
            snapshot.unlink(missing_ok=True)
            path.unlink(missing_ok=True)
            raise
        return {"snapshot_sha256": receipt["snapshot_sha256"], "snapshot_bytes": receipt["snapshot_bytes"],
                "live_release": live_release, "completed_utc": receipt["completed_utc"],
                "as_of_utc": completed.strftime("%Y-%m-%dT%H:%M:%SZ"), "copy_ms": milliseconds(copy_seconds),
                "linked_client_contacts": crm["linked"], "tool_sha256": tool_sha256}, ["copy-receipt.json"]

    def crm_ledger(self, snapshot: Path) -> dict:
        expected = self.cfg["crm_migrations"]
        scope = self.scope_crm_tables()
        try:
            with closing(sqlite3.connect(snapshot.resolve().as_uri() + "?mode=ro&immutable=1", uri=True)) as db:
                db.execute("PRAGMA query_only=ON")
                marks = ",".join("?" * len(expected))
                present = {row[0] for row in db.execute(
                    f"SELECT migration FROM migrations WHERE migration IN ({marks})", expected)}
                tables = {row[0] for row in db.execute("SELECT name FROM sqlite_schema WHERE type='table'")
                          if is_crm_table(row[0])}
                linked = db.execute("SELECT count(*) FROM crm_contacts WHERE client_user_id IS NOT NULL").fetchone()[0]
        except sqlite3.Error as error:
            raise Stop("crm-ledger-unreadable") from error
        if present != set(expected) or tables != scope or not linked > 0:
            raise Stop("crm-ledger-differs")
        return {"migrations": len(present), "tables": len(tables), "linked": linked}

    def scope_crm_tables(self) -> set[str]:
        try:
            scope = check.load(self.scope_path, expected_sha256=self.cfg["scope"]["sha256"])
            summarize_nightly_parity.crm_scope_table_count(self.scope_path, self.cfg["scope"]["sha256"])
        except (check.Missing, check.Failed) as error:
            raise Stop("scope-pin-differs") from error
        return {name for name in scope["tables"] if is_crm_table(name)}

    def stage_plan(self, journal: dict) -> tuple[dict, list[str]]:
        values = self.values(journal)
        for folder in ("forward-plan", "reverse-schema"):
            shutil.rmtree(self.run_dir / folder, ignore_errors=True)
        audit = self.tool("scope_audit", values, timeout_s=900, reason="scope-audit-refused")
        if audit.get("result") != "verified" or audit.get("source_scope_verified") is not True:
            raise Stop("scope-audit-refused")
        write_bytes(self.run_dir / "scope-audit.json", audit["_stdout"].encode("utf-8"))
        self.tool("plan", values, timeout_s=1800, reason="source-scan-refused")
        plan = json.loads((self.run_dir / "forward-plan/result.json").read_bytes())
        if plan.get("schema_sha256") != self.cfg["reviewed_schema_sha256"]:
            raise Stop("reviewed-layout-differs")
        template = self.tool("template", values, timeout_s=900, reason="reverse-template-refused")
        census = self.tool("census", values, timeout_s=900, reason="finance-date-census-refused")
        if census.get("result") != "observed":
            raise Stop("finance-date-census-refused")
        write_bytes(self.run_dir / "week-census.json", census["_stdout"].encode("utf-8"))
        return {"tables": plan["tables"], "schema_sha256": plan["schema_sha256"],
                "template_sha256": template["template_sha256"], "source_scope_verified": True,
                "source_integrity_scan_passed": True}, \
            ["scope-audit.json", "forward-plan/result.json", "reverse-schema/result.json", "week-census.json"]

    def stage_target(self, journal: dict) -> tuple[dict, list[str]]:
        host = self.host
        if host.mem_available_kib() < self.required_mem_kib():
            raise Stop("insufficient-memory")
        if host.free_bytes(self.run_dir) <= self.required_free_bytes(self.done(journal, "capture")["snapshot_bytes"]):
            raise Stop("insufficient-disk")
        if not host.native_binaries_verified():
            raise Stop("native-binary-differs")
        work = self.run_dir / f"work-{secrets.token_hex(6)}"
        work.mkdir(mode=0o700)
        (work / "data").mkdir(mode=0o700)
        journal["mysql"] = {"pid": None, "work": str(work)}
        self.save(journal)
        env = {"PATH": "/usr/bin:/bin", "HOME": str(work), "LC_ALL": "C.UTF-8", "TMPDIR": str(work)}
        initialized = host.run(self.commands("mysqld_initialize", {"work": work}), timeout_s=90, env=env, cwd=work)
        if initialized.returncode != 0:
            raise Stop("mysql-initialize-failed")
        self.process = host.start_mysqld(self.commands("mysqld", {"work": work}), env=env, cwd=work)
        journal["mysql"]["pid"] = getattr(self.process, "pid", None)
        self.save(journal)
        probe = [*self.mysql_client(work, root_options=False), "-e", IDENTITY_SQL]
        for _ in range(150):
            if self.process.poll() is not None:
                raise Stop("mysql-stopped")
            ready = host.run(probe, timeout_s=5, env=env, cwd=work)
            if ready.returncode == 0:
                break
            host.sleep(0.3)
        else:
            raise Stop("mysql-not-ready")
        fields = ready.stdout.strip().split("\t")
        if len(fields) != 11:
            raise Stop("target-settings-differ")
        version, uuid, *settings = fields
        engine = version == "8.4.13" and re.fullmatch(r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}", uuid)
        durable = tuple(settings) == DURABLE_SETTINGS
        if not (engine and durable):
            raise Stop("target-settings-differ")
        passwords = {user: secrets.token_hex(32) for user in ("root", "transfer", "compare", "reverse")}
        grants = {"transfer": "ALL", "compare": "SELECT", "reverse": "SELECT, TRIGGER"}
        target = r"`repro\_nightly\_data`.*"
        sql = f"CREATE DATABASE {DATABASE} CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;"
        for user, privileges in grants.items():
            sql += f"CREATE USER 'repro_{user}'@'localhost' IDENTIFIED BY '{passwords[user]}';"
            sql += f"GRANT {privileges} ON {target} TO 'repro_{user}'@'localhost';"
            write_json(work / f"{user}.json", {"unix_socket": f"{work}/mysql.sock", "user": f"repro_{user}",
                                               "password": passwords[user]}, indent=None)
        sql += f"ALTER USER CURRENT_USER() IDENTIFIED BY '{passwords['root']}';"
        provisioned = host.run(self.mysql_client(work, root_options=False), timeout_s=30, input=sql, env=env, cwd=work)
        if provisioned.returncode != 0:
            raise Stop("mysql-accounts-failed")
        write_bytes(work / "root.cnf", f"[client]\npassword={passwords['root']}\n".encode("utf-8"))
        self.session = {"work": work, "uuid": uuid}
        settings_record = dict(zip(("skip_networking", "log_bin", "event_scheduler", "binlog_format", "binlog_row_image",
                                    "gtid_mode", "sync_binlog", "innodb_flush_log_at_trx_commit", "innodb_doublewrite"),
                                   settings))
        write_json(self.run_dir / "target.json", {"version": version, "server_uuid": uuid, "settings": settings_record,
                                                  "tuning": self.cfg["mysql_tuning"], "priority": self.cfg["priority"]})
        return {"server_uuid": uuid, "target_engine_verified": True, "target_settings_verified": True}, ["target.json"]

    def stage_import_and_verify(self, journal: dict) -> tuple[dict, list[str]]:
        host, budget = self.host, self.cfg["budget"]
        limit_ms, fail_fast = budget["import_and_verify_ms"], budget["fail_fast"]
        projected = self.projected_ms(self.done(journal, "capture")["snapshot_bytes"])
        measured = {"projected_ms": projected, "budget_ms": limit_ms}
        if fail_fast and projected > limit_ms:
            raise Stop("budget-at-risk", measured)
        values = self.values(journal)
        work = self.session["work"]
        started = host.monotonic()

        def remaining() -> float:
            return limit_ms / 1000 - (host.monotonic() - started) if fail_fast else 1800

        try:
            imported = host.run(self.commands("import", values), timeout_s=remaining(),
                                env=self.tool_env(work), cwd=work)
        except subprocess.TimeoutExpired as error:
            raise Stop("budget-exceeded", measured | {"import_ms": milliseconds(host.monotonic() - started)}) from error
        measured["import_ms"] = milliseconds(host.monotonic() - started)
        result_path = work / "import/result.json"
        result = json.loads(result_path.read_bytes()) if imported.returncode == 0 and result_path.is_file() else {}
        if result.get("import_verified") is not True or result.get("mysql_warning_count") != 0:
            raise Stop("import-refused", measured)
        if fail_fast and measured["import_ms"] >= limit_ms:
            raise Stop("budget-exceeded", measured)
        self.mysql("SET GLOBAL super_read_only=ON; SELECT @@read_only,@@super_read_only", expect="1\t1",
                   reason="seal-failed")
        values["schema_plan_sha256"] = file_sha256(work / "import/schema-plan.json")
        try:
            verified = host.run(self.commands("verify_forward", values), timeout_s=remaining(),
                                env=self.tool_env(work), cwd=work)
        except subprocess.TimeoutExpired as error:
            raise Stop("budget-exceeded", measured | {"import_and_verify_ms": milliseconds(host.monotonic() - started)}) \
                from error
        total = milliseconds(host.monotonic() - started)
        measured |= {"import_and_verify_ms": total, "verify_ms": total - measured["import_ms"],
                     "headroom_ms": limit_ms - total}
        write_bytes(self.run_dir / "forward-fields-private.jsonl", verified.stdout.encode("utf-8"))
        fields = last_json(verified.stdout)
        if verified.returncode != 0 or fields.get("result") != "all-fields-verified" or fields.get("field_mismatches") != 0:
            raise Stop("forward-verification-refused", measured)
        plan = self.done(journal, "plan")
        if fields.get("rows") != result.get("original_rows") or fields.get("tables") != plan["tables"]:
            raise Stop("row-counts-differ", measured)
        if fail_fast and total > limit_ms:
            raise Stop("budget-exceeded", measured)
        write_json(self.run_dir / "forward-import-result.json", result)
        return measured | {"rows": fields["rows"], "tables": fields["tables"], "fields": fields.get("fields"),
                           "warnings": result["mysql_warning_count"], "field_mismatches": 0,
                           "field_verifiers_agree": True, "row_counts_match": True,
                           "historical_exceptions_match": True, "schema_plan_sha256": values["schema_plan_sha256"]}, \
            ["forward-fields-private.jsonl", "forward-import-result.json"]

    def stage_reverse(self, journal: dict) -> tuple[dict, list[str]]:
        if self.session is None:
            raise Stop("mysql-session-missing")
        host, work = self.host, self.session["work"]
        forward = self.done(journal, "import_and_verify")
        values = self.values(journal, template_sha256=self.done(journal, "plan")["template_sha256"],
                             schema_plan_sha256=forward["schema_plan_sha256"])
        started = host.monotonic()
        triggers = None
        if self.cfg["reverse_triggers"] is not None:
            # The helper runs from outside the candidate; check its pin again right before it runs.
            if not self.pins_hold().get("reverse-helper"):
                raise Stop("reverse-helper-pin-differs")
            self.mysql("SET GLOBAL super_read_only=OFF; SET GLOBAL read_only=OFF; SELECT @@read_only,@@super_read_only",
                       expect="0\t0", reason="trigger-maintenance-refused")
            try:
                detail = self.tool("reverse_triggers", values, timeout_s=180, reason="reverse-triggers-refused", home=work)
            finally:
                self.mysql("SET GLOBAL super_read_only=ON; SELECT @@read_only,@@super_read_only", expect="1\t1",
                           reason="seal-failed")
            if detail.get("result") != "reviewed-triggers-verified":
                raise Stop("reverse-triggers-refused")
            triggers = detail.get("expected_mysql_triggers")
        self.tool("export", values, timeout_s=1800, reason="reverse-export-refused", home=work)
        recovered = json.loads((work / "reverse/result.json").read_bytes())
        if (recovered.get("verified") is not True or recovered.get("tables") != forward["tables"]
                or recovered.get("field_mismatches") != 0):
            raise Stop("reverse-export-refused")
        values |= {"recovered": work / "reverse/database.sqlite", "recovered_sha256": recovered["database_sha256"]}
        result = host.run(self.commands("verify_reverse", values), timeout_s=1800, env=self.tool_env(work), cwd=work)
        write_bytes(self.run_dir / "reverse-fields-private.jsonl", result.stdout.encode("utf-8"))
        fields = last_json(result.stdout)
        if (result.returncode != 0 or fields.get("result") != "all-fields-verified" or fields.get("field_mismatches") != 0
                or [fields.get(name) for name in ("rows", "tables", "fields")]
                != [forward[name] for name in ("rows", "tables", "fields")]):
            raise Stop("reverse-verification-refused")
        write_json(self.run_dir / "reverse-export-result.json", recovered)
        write_bytes(work / "crm-audit.key", secrets.token_bytes(32))
        audit = self.tool("crm_audit", values, timeout_s=900, reason="crm-audit-refused", home=work)
        parity = audit.get("parity")
        if audit.get("result") != "verified" or not isinstance(parity, dict) or not parity or not all(
                value is True for value in parity.values()):
            raise Stop("crm-audit-refused")
        write_bytes(self.run_dir / "crm-audit-private.json", audit["_stdout"].encode("utf-8"))
        elapsed = milliseconds(host.monotonic() - started)
        # The disposable MySQL is no longer needed. Stop it before the finance step starts its own.
        self.end_mysql(journal)
        return {"sqlite_reverse_parity_verified": True, "recovered_sqlite_sha256": recovered["database_sha256"],
                "mysql_triggers_verified": triggers, "reverse_ms": elapsed, "field_mismatches": 0}, \
            ["reverse-fields-private.jsonl", "reverse-export-result.json", "crm-audit-private.json"]

    def stage_finance(self, journal: dict) -> tuple[dict, list[str]]:
        if self.cfg["finance"] is None:
            return {"status": "not-configured", "invoice_and_payout_parity_verified": False,
                    "crm_client_import_dry_run_verified": False}, []
        if not all(ok for name, ok in self.pins_hold().items() if name.startswith("finance")):
            raise Stop("finance-pin-differs")
        output = self.run_dir / "finance-receipt-private.json"
        output.unlink(missing_ok=True)
        result = self.host.run(self.commands("finance", self.values(journal)),
                               timeout_s=self.cfg["finance"]["timeout_s"], env=self.tool_env(self.run_dir),
                               cwd=self.run_dir)
        if result.returncode != 0 or not output.is_file():
            raise Stop("finance-refused")
        receipt = json.loads(output.read_bytes())
        forward = self.done(journal, "import_and_verify")
        problem = finance_problem(receipt, snapshot_sha256=self.done(journal, "capture")["snapshot_sha256"],
                                  release=journal["candidate_release"], rows=forward["rows"], tables=forward["tables"])
        if problem:
            raise Stop(f"finance-receipt-invalid: {problem}")
        rehearsal = receipt["rehearsal"]
        proof = {"result": "private-finance-and-client-import-parity-verified", "receipt_sha256": file_sha256(output),
                 "snapshot_sha256": receipt["snapshot_sha256"], "release": rehearsal["release"],
                 "weeks_compared": rehearsal["report_weeks_compared"],
                 "client_invoice_sample_count": rehearsal["client_invoice_sample_count"],
                 "mysql_import_ms": rehearsal.get("mysql_import_ms"), "external_effects": 0,
                 "report_comparisons": rehearsal["report_comparisons"],
                 "generation_comparisons": rehearsal["generation_comparisons"]}
        write_json(self.run_dir / "finance-proof.json", proof)
        return {"status": "verified", "invoice_and_payout_parity_verified": True,
                "crm_client_import_dry_run_verified": rehearsal["report_comparisons"]["crm_client_import_dry_run"] is True}, \
            ["finance-proof.json", "finance-receipt-private.json"]

    def stage_applicant_files(self, journal: dict) -> tuple[dict, list[str]]:
        if self.cfg["applicant_files_root"] is None:
            return {"status": "not-configured", "verified": False}, []
        key = self.run_dir / ".applicant-files.key"
        write_bytes(key, secrets.token_bytes(32))
        try:
            record = self.tool("applicant_files", self.values(journal), timeout_s=1800, reason="applicant-files-refused")
        finally:
            key.unlink(missing_ok=True)
        files = record.get("files")
        if (record.get("result") != "verified" or type(files) is not int or files < 0
                or record.get("snapshot_sha256") != self.done(journal, "capture")["snapshot_sha256"]):
            raise Stop("applicant-files-refused")
        write_bytes(self.run_dir / "applicant-files.json", record["_stdout"].encode("utf-8"))
        if files:
            # The verifier compared the snapshot with the private disk only. The
            # proof also needs a target-side comparison and permitted and denied
            # access checks on the isolated target, which this runner does not do.
            return {"status": "source-verified-target-checks-missing", "verified": False, "files": files}, \
                ["applicant-files.json"]
        # An empty inventory is recorded as the review policy asks; the field
        # verifiers already compared the empty applicant-file table on both engines.
        return {"status": "verified", "verified": True, "files": 0}, ["applicant-files.json"]

    def stage_crm_review(self, journal: dict) -> tuple[dict, list[str]]:
        scope_crm = self.scope_crm_tables()

        def verified_tables(name: str) -> set[str]:
            rows = [json.loads(line) for line in (self.run_dir / name).read_bytes().splitlines() if line.strip()]
            return {row["table"] for row in rows if row.get("event") == "table-verified" and is_crm_table(row["table"])}

        forward, reverse = verified_tables("forward-fields-private.jsonl"), verified_tables("reverse-fields-private.jsonl")
        every_crm_field = forward == reverse == scope_crm
        audit = json.loads((self.run_dir / "crm-audit-private.json").read_bytes())
        parity = audit["parity"]
        link = audit.get("references", {}).get("crm_contacts.client_user_id->users.id", {})
        finance = self.done(journal, "finance")
        applicants = self.done(journal, "applicant_files")
        outputs = {stage: journal["stages"][stage]["outputs"] for stage in ("import_and_verify", "reverse", "finance",
                                                                             "applicant_files")}
        evidence = {
            "schema": EVIDENCE_SCHEMA, "scheduled_day_utc": self.day, "release_sha": journal["candidate_release"],
            "snapshot_sha256": self.done(journal, "capture")["snapshot_sha256"],
            "forward_fields_sha256": outputs["import_and_verify"]["forward-fields-private.jsonl"],
            "reverse_fields_sha256": outputs["reverse"]["reverse-fields-private.jsonl"],
            "crm_audit_sha256": outputs["reverse"]["crm-audit-private.json"],
            "finance_proof_sha256": outputs["finance"].get("finance-proof.json"),
            "applicant_files_sha256": outputs["applicant_files"].get("applicant-files.json"),
            "crm_tables": sorted(forward & reverse & scope_crm),
        }
        write_json(self.run_dir / "crm-parity-evidence.json", evidence)
        review = {
            "schema": check.NIGHTLY_CRM_SCHEMA, "scheduled_day_utc": self.day,
            "release_sha": journal["candidate_release"], "snapshot_sha256": evidence["snapshot_sha256"],
            "nightly_scope_sha256": self.cfg["scope"]["sha256"],
            "audit_report_sha256": evidence["crm_audit_sha256"],
            "parity_evidence_sha256": file_sha256(self.run_dir / "crm-parity-evidence.json"),
            "crm_tables_verified": len(evidence["crm_tables"]),
            "verified_linked_client_count": link.get("linked", 0) if isinstance(link.get("linked"), int) else 0,
            "crm_field_and_identity_parity_verified": every_crm_field and parity.get("table_counts") is True
            and parity.get("migrations") is True,
            "crm_relationship_and_exception_parity_verified": parity.get("references") is True
            and self.done(journal, "import_and_verify")["historical_exceptions_match"] is True,
            "crm_replay_state_parity_verified": every_crm_field,
            "crm_client_import_dry_run_verified": finance["crm_client_import_dry_run_verified"],
            "crm_applicant_file_parity_verified": applicants["verified"],
        }
        write_json(self.run_dir / "crm-review.json", review)
        return {"crm_tables_verified": review["crm_tables_verified"]}, ["crm-parity-evidence.json", "crm-review.json"]

    def stage_report(self, journal: dict) -> tuple[dict, list[str]]:
        capture, plan = self.done(journal, "capture"), self.done(journal, "plan")
        target, forward = self.done(journal, "target"), self.done(journal, "import_and_verify")
        reverse, finance = self.done(journal, "reverse"), self.done(journal, "finance")
        night = {
            "scheduled_day_utc": self.day, "release_sha": journal["candidate_release"],
            "snapshot_sha256": capture["snapshot_sha256"], "nightly_scope_sha256": self.cfg["scope"]["sha256"],
            "import_and_verify_ms": forward["import_and_verify_ms"], "errors": 0, "warnings": forward["warnings"],
            "notes": 0, "unexpected_field_differences": forward["field_mismatches"] + reverse["field_mismatches"],
            "fresh_snapshot_verified": True, "source_scope_verified": plan["source_scope_verified"],
            "source_integrity_scan_passed": plan["source_integrity_scan_passed"],
            "field_verifiers_agree": forward["field_verifiers_agree"], "row_counts_match": forward["row_counts_match"],
            "historical_exceptions_match": forward["historical_exceptions_match"],
            "target_settings_verified": target["target_settings_verified"],
            "target_engine_verified": target["target_engine_verified"],
            "sqlite_reverse_parity_verified": reverse["sqlite_reverse_parity_verified"],
            "invoice_and_payout_parity_verified": finance["invoice_and_payout_parity_verified"],
        }
        night_path, crm_path, copy_path = (self.run_dir / name for name in ("night.json", "crm-review.json",
                                                                             "copy-receipt.json"))
        write_json(night_path, night)
        entry = {"scheduled_day_utc": self.day, "report_sha256": file_sha256(night_path),
                 "crm_report_sha256": file_sha256(crm_path), "producer_receipt_sha256": file_sha256(copy_path),
                 "source_release_sha": capture["live_release"]}
        write_json(self.run_dir / "manifest-entry.json", entry)
        try:
            verify_nightly_capture.verify(night_path, entry["report_sha256"], copy_path, entry["producer_receipt_sha256"],
                                          now=self.host.utc_now(), expected_source_release=capture["live_release"])
        except (check.Missing, check.Failed) as error:
            raise Stop(f"capture-binding-refused: {error}") from error
        crm = json.loads(crm_path.read_bytes())
        try:
            check.review_nightly_night(night | {"crm": crm | {"report_sha256": entry["crm_report_sha256"]}},
                                       self.cfg["scope"]["sha256"], len(self.scope_crm_tables()))
            gate = None
        except (check.Missing, check.Failed) as error:
            gate = str(error)
        return {"qualifying_draft": gate is None, "draft_gate": gate or "passed"}, ["night.json", "manifest-entry.json"]

    # Receipt and retention ------------------------------------------------------
    def receipt(self, journal: dict) -> dict:
        stages = journal["stages"]

        def result(name: str) -> dict:
            return stages.get(name, {}).get("result") or {}

        timing = result("import_and_verify")
        report = result("report")
        return {
            "schema": RECEIPT_SCHEMA, "scheduled_day_utc": self.day, "status": journal["status"],
            "reason": journal["reason"], "stopped_at": journal["stopped_at"],
            "qualifying_draft": report.get("qualifying_draft", False), "draft_gate": report.get("draft_gate"),
            "config_sha256": self.config.sha256, "candidate_release": journal["candidate_release"],
            "live_release": result("capture").get("live_release"),
            "snapshot_sha256": result("capture").get("snapshot_sha256"),
            "stage_ms": {name: stages[name]["elapsed_ms"] for name in STAGES if name in stages},
            **{name: timing.get(name) for name in ("import_ms", "verify_ms", "import_and_verify_ms", "projected_ms",
                                                   "headroom_ms")},
            "budget_ms": self.cfg["budget"]["import_and_verify_ms"], "fail_fast": self.cfg["budget"]["fail_fast"],
            "tuning": self.cfg["mysql_tuning"], "priority": self.cfg["priority"],
            "tool_sha256": result("capture").get("tool_sha256"),
            "outputs": {output: sha for name in STAGES for output, sha in stages.get(name, {}).get("outputs", {}).items()},
            "attempts": len(journal["attempts"]),
        }

    def prune(self) -> None:
        keep = self.cfg["retain_snapshots"]
        if keep is None:
            return
        runs = []
        for folder in sorted(self.state_dir.iterdir()):
            journal_path = folder / "journal.json"
            if folder.is_dir() and not folder.is_symlink() and DAY.fullmatch(folder.name) and journal_path.is_file():
                journal = json.loads(journal_path.read_bytes())
                if journal.get("schema") == JOURNAL_SCHEMA and journal.get("scheduled_day_utc") == folder.name:
                    runs.append((folder, journal))
        for folder, journal in runs[:-keep]:
            snapshot = folder / "snapshot.sqlite"
            if snapshot.is_file() and not snapshot.is_symlink():
                snapshot.unlink()
                journal["snapshot_pruned"] = True
                write_json(folder / "journal.json", journal)


def finance_problem(receipt: object, *, snapshot_sha256: str, release: str, rows: int, tables: int) -> str | None:
    """The checks of the reviewed finance verifier, applied to the finance step's receipt."""
    if not isinstance(receipt, dict):
        return "receipt-not-an-object"
    rehearsal = receipt.get("rehearsal")
    isolation = receipt.get("isolation")
    if (receipt.get("scope"), receipt.get("status"), receipt.get("snapshot_derivative")) != (
            "private-finance-rehearsal", "passed", "copy") or receipt.get("snapshot_sha256") != snapshot_sha256:
        return "scope-status-or-snapshot"
    if not all(receipt.get(name) is True for name in ("private_scratch_removed", "transient_unit_removed",
                                                     "production_database_services_unchanged")):
        return "cleanup-not-confirmed"
    if not isinstance(isolation, dict) or not all(isolation.get(name) is True for name in FINANCE_ISOLATION):
        return "isolation-not-confirmed"
    if not isinstance(rehearsal, dict) or rehearsal.get("release") != release \
            or rehearsal.get("snapshot_sha256") != snapshot_sha256:
        return "release-or-snapshot"
    if rehearsal.get("mysql_rows") != rows or rehearsal.get("mysql_tables") != tables:
        return "row-or-table-count"
    sample = rehearsal.get("client_invoice_sample_count")
    if rehearsal.get("report_weeks_compared") != 8 or type(sample) is not int or not 0 < sample <= 6:
        return "coverage"
    if rehearsal.get("external_effects") != 0:
        return "external-effects"
    if rehearsal.get("report_results_equal") is not True or rehearsal.get("generated_results_equal") is not True:
        return "results-differ"
    for name, expected in (("report_comparisons", FINANCE_REPORTS), ("generation_comparisons", FINANCE_GENERATIONS)):
        group = rehearsal.get(name)
        if not isinstance(group, dict) or set(group) != set(expected) or not all(value is True for value in group.values()):
            return name
    return None


# Real host ---------------------------------------------------------------------

def database_settings(text: str) -> dict:
    """Keep only the three database keys. No other setting or secret is retained."""
    settings = {}
    for line in text.splitlines():
        key, separator, value = line.partition("=")
        if separator and key in {"DB_CONNECTION", "DB_DATABASE", "DB_URL"}:
            need(key not in settings, "database-setting-repeated")
            settings[key] = value.strip().strip("'").strip('"')
    return settings


def resolve_live_database(backend: Path, settings: dict) -> LiveDatabase:
    need(settings.get("DB_CONNECTION") == "sqlite" and not settings.get("DB_URL"), "live-database-not-sqlite")
    configured = Path(settings.get("DB_DATABASE") or "database/database.sqlite")
    path = (backend / configured).resolve(strict=True)
    need(path.parent == (backend / "database").resolve(strict=True) and path.suffix == ".sqlite",
         "live-database-path-unexpected")
    info = path.stat()
    need(stat.S_ISREG(info.st_mode), "live-database-not-a-file")
    return LiveDatabase(path=path, identity=(info.st_dev, info.st_ino), size=info.st_size)


class LocalHost:
    """The machine the runner is on. Posix only, never root, no network or ssh."""

    def __init__(self, config: Config):
        need(os.name == "posix", "posix-host-required")
        need(os.geteuid() != 0, "refuses-to-run-as-root")
        self.cfg = config.data
        self.backend = Path(self.cfg["backend_root"])

    def utc_now(self) -> datetime:
        return datetime.now(timezone.utc)

    def monotonic(self) -> float:
        return time.monotonic()

    def sleep(self, seconds: float) -> None:
        time.sleep(seconds)

    def deployed_release(self) -> str:
        path = self.backend / "storage/app/deploy-meta.json"
        need(path.is_file() and path.stat().st_size < 32 * 1024, "deploy-metadata-unreadable")
        commit = json.loads(path.read_bytes()).get("commit")
        need(isinstance(commit, str) and re.fullmatch(r"[0-9a-f]{40}", commit), "deploy-metadata-malformed")
        return commit

    def live_database(self) -> LiveDatabase:
        settings = database_settings((self.backend / ".env").read_text(encoding="utf-8"))
        return resolve_live_database(self.backend, settings)

    def free_bytes(self, path: Path) -> int:
        while not path.exists():
            path = path.parent
        return shutil.disk_usage(path).free

    def mem_available_kib(self, meminfo: Path = Path("/proc/meminfo")) -> int:
        match = re.search(r"^MemAvailable:\s+([0-9]+) kB$", meminfo.read_text(encoding="ascii"), re.M)
        return int(match[1]) if match else 0

    def checkout(self) -> tuple[str, bool]:
        root = self.cfg["candidate_root"]
        # Read only the checkout's own repository settings: no system, global or
        # $HOME config, so a stray .gitconfig in the candidate cannot run hooks
        # or change what counts as clean.
        env = {"PATH": "/usr/bin:/bin", "LC_ALL": "C.UTF-8", "GIT_CONFIG_NOSYSTEM": "1",
               "GIT_CONFIG_GLOBAL": "/dev/null", "HOME": "/nonexistent"}
        head = subprocess.run(["git", "-C", root, "rev-parse", "HEAD"], capture_output=True, text=True, env=env,
                              timeout=30, check=False)
        status = subprocess.run(["git", "-C", root, "status", "--porcelain", "--untracked-files=no"],
                                capture_output=True, text=True, env=env, timeout=60, check=False)
        release = head.stdout.strip()
        need(head.returncode == 0 and re.fullmatch(r"[0-9a-f]{40}", release), "candidate-checkout-unreadable")
        return release, status.returncode == 0 and status.stdout == ""

    def deploy_lock_free(self) -> bool:
        import fcntl
        path = Path(self.cfg["deploy_lock"])
        if not path.exists():
            return True
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return False
        finally:
            os.close(descriptor)
        return True

    def native_binaries_verified(self) -> bool:
        native = Path(self.cfg["native_mysql_root"])
        try:
            manifest = json.loads((native.parent / "ops/bundle-manifest.json").read_bytes())
            for executable in ("mysqld", "mysql"):
                path = native / "bin" / executable
                info = path.stat()
                if (info.st_uid != 0 or info.st_mode & 0o022
                        or file_sha256(path) != manifest["files"][f"bin/{executable}"]["sha256"]):
                    return False
        except (OSError, ValueError, KeyError, TypeError):
            return False
        return True

    def stop_stale_mysqld(self, pid: int | None, work: Path) -> None:
        """Stop every process still serving this night's work directory.

        The journaled pid is only a hint: the runner can stop between starting
        mysqld and journaling its pid, so processes are found by their exact
        `--datadir` argument, which names this night's private work directory.
        """
        marker = f"--datadir={work}/data".encode()

        def holders() -> list[int]:
            found = []
            for entry in Path("/proc").iterdir():
                if not entry.name.isdigit() or int(entry.name) <= 1 or int(entry.name) == os.getpid():
                    continue
                try:
                    if marker in (entry / "cmdline").read_bytes().split(b"\0"):
                        found.append(int(entry.name))
                except OSError:
                    continue
            return found

        def signal_all(signum: int) -> None:
            for holder in holders():
                try:
                    os.kill(holder, signum)
                except ProcessLookupError:
                    pass

        signal_all(signal.SIGTERM)
        for _ in range(70):
            if not holders():
                return
            time.sleep(0.5)
        signal_all(signal.SIGKILL)
        for _ in range(10):
            if not holders():
                return
            time.sleep(0.5)
        raise Refused("stale-mysqld-did-not-stop")

    def start_mysqld(self, argv: list[str], *, env: dict, cwd: Path):
        return subprocess.Popen(list(map(str, argv)), env=env, cwd=cwd, stdin=subprocess.DEVNULL,
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    def run(self, argv: list[str], *, timeout_s: float, input: str | None = None, env: dict | None = None,
            cwd: Path | None = None) -> Completed:
        if timeout_s <= 0:
            raise subprocess.TimeoutExpired(argv, 0)
        # Never pass the caller's environment through to a tool.
        env = env if env is not None else {"PATH": "/usr/bin:/bin", "LC_ALL": "C.UTF-8"}
        result = subprocess.run(list(map(str, argv)), input=input, env=env, cwd=cwd, capture_output=True, text=True,
                                timeout=timeout_s, check=False)
        return Completed(result.returncode, result.stdout, result.stderr)


# Command line ------------------------------------------------------------------

def install_signal_handlers() -> None:
    """Turn SIGTERM and SIGHUP into a journaled stop, so cleanup stops the disposable MySQL."""
    def interrupted(signum, _frame):
        raise Stop(f"interrupted-by-signal-{signum}")

    for name in ("SIGTERM", "SIGHUP"):
        if hasattr(signal, name):
            signal.signal(getattr(signal, name), interrupted)


def exit_code(receipt: dict) -> int:
    """0 for a qualifying draft, 3 for a finished night that cannot qualify, 1 for a stopped night."""
    if receipt.get("status") != "reviewer-inputs-ready":
        return 1
    return 0 if receipt.get("qualifying_draft") is True else 3


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    commands = parser.add_subparsers(dest="command", required=True)
    checked = commands.add_parser("check-config", help="validate a configuration and print its digest")
    checked.add_argument("--config", type=Path, required=True)
    run = commands.add_parser("run", help="plan one night, or run it with --execute and an approval")
    run.add_argument("--config", type=Path, required=True)
    run.add_argument("--state-dir", type=Path, required=True)
    run.add_argument("--day", required=True, help="scheduled UTC date, YYYY-MM-DD")
    run.add_argument("--execute", action="store_true", help="run the night; without it nothing is written")
    run.add_argument("--approval", type=Path, help="owner approval covering this night and configuration")
    args = parser.parse_args()
    if args.command == "run" and args.execute and args.approval is None:
        parser.error("--execute needs --approval with the owner's approval file")
    try:
        config = Config.load(args.config)
        if args.command == "check-config":
            print(json.dumps({"result": "config-valid", "config_sha256": config.sha256}, sort_keys=True))
            return 0
        os.umask(0o077)
        runner = NightlyRunner(config, LocalHost(config), args.state_dir.resolve(), args.day)
        if not args.execute:
            print(json.dumps(runner.plan(), indent=2, sort_keys=True, default=str))
            return 0
        install_signal_handlers()
        receipt = runner.run(check.load(args.approval))
    except (Refused, Stop, check.Missing, check.Failed) as error:
        print(json.dumps({"result": "nightly-run-refused", "detail": str(error)}, sort_keys=True))
        return 1
    print(json.dumps(receipt, indent=2, sort_keys=True))
    return exit_code(receipt)


if __name__ == "__main__":
    raise SystemExit(main())
