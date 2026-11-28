"""Production host adapter for the SQLite hold, freeze and pre-write rollback.

Every effect is idempotent and re-observable, as the runner requires:

- close_admissions removes the nginx open marker (both vhosts answer 503 at
  once), publishes the deployment fence under both deploy locks and pauses
  every queue;
- drain stops the worker groups, drains PHP-FPM and waits until no writer
  process remains, and returns the drain report's record for the journal; a
  freeze refuses a pool the drain could not watch, a rollback always stops it;
- freeze seals the SQLite file and records its SHA-256 for this run and hold
  (design 3.2 step 3);
- seal_sqlite, in every rollback, undoes any part of prepare (design 3.5): the gate
  closed, the .env restored from the run's switch record, the SQLite config cache
  rebuilt to the pin, FPM reloaded only if it runs, all checked before SQLite is
  unsealed; the unseal proof records the sealed file against the freeze's hash;
- prepare (steps 1 to 10, design 3.2) switches the frozen host to MySQL behind the
  closed gate: bound inputs, the sealed file unchanged, no app-schema event since
  the verified backup, the switch record, the `.env` switch (envfile.py), the MySQL
  config cache, a fresh FPM and the receipt; then (steps 11 and 12) it checks every
  process context, FPM, the read-only smoke and the writer token on MySQL and writes
  the evidence report (evidence.py), which evidence() reads back with its live
  preconditions (design 3.3); check_evidence is check-evidence's manifest writer;
- publish_authority writes the boot-bound authority proof, measured: the live
  config's route hash, the identity probe's release, artifact and source, and
  for MySQL the server UUID the probe read (design 3.4);
- reopen verifies that proof through the guard, then resumes queues, starts
  workers and replays captured texts around the open marker. Deploys stay fenced.

observe() takes the engine from the live config (live_config.measure, design
1.3), accepting its .env fallback so that a rollback can run; both
publications and host_authority refuse the fallback. A MySQL probe that finds
no server listening (exit 3) reads as not writable and without business
activity only while the journal says no MySQL write is possible (design 1.2).

It never makes MySQL writable: prepare leaves the gate closed, evidence() only reads, and
seal_mysql (C02) refuses.
Host commands are fixed argument lists from the root-owned host config, each
run in its own process group, which a timeout or an interrupt kills whole.
"""

from __future__ import annotations

import calendar
import json
import math
import os
import re
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time
import uuid
from collections.abc import Callable
from dataclasses import asdict, dataclass
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE), str(HERE.parent)]
try:
    from fence import guard  # Repository layout.
except ImportError:  # Installed layout: /opt/repro-cutover-fence/guard.py
    import guard
import binlog_scan  # noqa: E402
import config_build  # noqa: E402
import deploy_marker  # noqa: E402
import envfile  # noqa: E402
import evidence  # noqa: E402
import fastcgi  # noqa: E402
import live_config  # noqa: E402
import sqlite_seal  # noqa: E402
from runner import (  # noqa: E402
    DRAIN_PURPOSES, Evidence, Identity, Journal, Lease, Observation, Runner, SchedulerDrain, UnsafeCutover, digest,
    is_sha256,
)

NOT_SUPPORTED = "This adapter does not open the MySQL gate yet: seal_mysql refuses (C02)"
CHILD_TAG = "REPRO_CUTOVER_CHILD"
# The environment of every host command: nothing of root's own (cron, Supervisor, systemd, the operator's shell).
HOST_ENVIRONMENT = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8"}
POLL_SECONDS = 0.25
# The MySQL probe's exit status when nothing listens at the socket, and all it prints then (design 1.2).
MYSQL_UNREACHABLE = 3
# Its refusals that hold the site until a person acts (design 4.7): stdout carries a "problems" list of texts
# that never hold a secret, quoted in the refusal up to this many characters.
MYSQL_REFUSALS = (4, 5, 6)
PROBLEM_CHARACTERS = 500
# fpm_drain.py's outcomes (design 5.4). A freeze goes on after these (timed_out only with at least one status
# reading); any other refuses it, "unmonitored" (the pool listens, but its status page cannot be read) included.
# A rollback records any outcome and stops FPM.
FREEZE_DRAIN_OUTCOMES = ("drained", "timed_out", "stopped")
# What fpm_drain.py needs inside command_seconds beyond fpm.drain_seconds: its last status request (REQUEST_SECONDS)
# plus start-up and the report (MARGIN_SECONDS). Copied, not imported: fpm_drain imports this module.
FPM_DRAIN_MARGIN_SECONDS = 12
ENGINE_NAMES = {"sqlite": "SQLite", "mysql": "MySQL"}
PATHS = {"state", "policy", "evidence", "activation", "machine_id", "boot_id", "ingress_open", "deploy_marker",
         "scheduler_hold", "sqlite_seal_record", "identity_config", "switch_record", "sealed_hash", "receipt",
         "activity", "activated", "smoke_window", "evidence_inputs", "evidence_report", "unseal_proof",
         "issued_approvals", "scheduler_release", "wrappers", "wrappers_sqlite", "wrappers_mysql", "retired",
         "archive", "keep"}
# One fixed argument list each; the adapter may append arguments.
SINGLE_COMMANDS = {"identity", "mysql", "queue_pause", "queue_resume", "config_build", "fpm_start", "fpm_reload",
                   "fpm_stop", "smoke_write", "telnyx_replay", "timer"}
# Lists of fixed argument lists, run in order.
COMMAND_LISTS = {"stop_workers", "start_workers", "drain_fpm", "smoke_readonly"}
COMMANDS = SINGLE_COMMANDS | COMMAND_LISTS
OPTIONS = {"max_drain_seconds", "separate_freeze_approval", "adopt_external_hold", "require_long_job_predrain",
           "unattended_reopen"}
# host.json (design 3.1): every key is required and no other key is accepted, so nothing is filled in silently.
HOST_KEYS = {"version", "paths", "app_root", "app_owner", "app_group", "web_user", "sqlite", "deploy_locks",
             "deploy_owner", "queues", "mysql", "fpm", "smoke", "studio_pause_files", "commands", "writer_processes",
             "long_job_processes", "scheduler_processes", "command_seconds", "options"}
MYSQL_KEYS = {"mode", "database", "control_database", "target_uuid", "datadir", "client", "socket", "probe_defaults",
              "gate_defaults", "token_check_defaults", "gated_accounts", "lockable_accounts",
              "activity_excluded_tables", "smoke_tables", "switch", "switch_secrets", "night_flags", "offsite_waiver",
              "free_disk_floor_bytes"}
FPM_KEYS = {"unit", "socket", "status_socket", "drain_seconds"}
SMOKE_KEYS = {"client_id", "shoot_id"}
# identity.json (design 1.1): the identity probe's own inputs.
IDENTITY_CONFIG_KEYS = {"version", "run_id", "app_root", "sqlite_path", "mysql_datadir", "env_denylist"}
MODES = ("drill", "cutover")
RUN_ID = r"[A-Za-z0-9][A-Za-z0-9_-]{0,63}"
ACCOUNT = r"[a-z_][a-z0-9_-]{0,31}"
ENV_KEY = r"[A-Z][A-Z0-9_]*"
# Switch values go into .env unquoted: no spaces, quotes, `#`, `$` (dotenv interpolation) or control characters.
ENV_VALUE = r"[A-Za-z0-9_.,/:@+-]+"
SQL_NAME = r"[a-z][a-z0-9_]{0,63}"
# The server listens on its socket only, so every account is @localhost.
MYSQL_ACCOUNT = r"[a-z][a-z0-9_]{0,31}@localhost"
SERVICE_UNIT = r"[A-Za-z0-9@._-]+\.service"
# The switch (design 3.1): the route keys `prepare` writes into .env and `seal_sqlite` restores. Each one is
# denylisted, so the switch never changes config_sha256; a key outside this list would hide its edits.
SWITCH_ROUTE = {"DB_CONNECTION", "DB_DATABASE", "DB_SOCKET", "DB_USERNAME", "DB_COLLATION",
                "REPRO_MYSQL_EXPECTED_SERVER_UUID"}
# Switched as well once the engine-check and schema-cache branches are merged and the host config names them.
SWITCH_OPTIONAL = {"DB_EXPECTED_ENGINE", "SCHEMA_CACHE_RELEASE"}
# Read from root-only files, never written in host.json.
SWITCH_SECRETS = {"DB_PASSWORD"}
# The pause flags live in www-data's 0755 cache folders (design 5.1).
QUEUE_PREFIX = ["/usr/sbin/runuser", "-u", "www-data", "--", "/bin/sh", "-c"]
# The one program a queue command may run, with the app's own `artisan` from the `cd <app_root>` step.
QUEUE_PHP = "/usr/bin/php"
# A path the shell reads as one plain word: no space, quote, glob, expansion or control character.
SHELL_PATH = r"/[A-Za-z0-9_./-]*"
# Longest single host command the config may allow (command_seconds).
MAX_COMMAND_SECONDS = 300
# mysql.free_disk_floor_bytes: prepare refuses unless / has more free bytes (design 3.2 step 2, critic HO-M12).
MAX_FREE_DISK_FLOOR = 2 ** 50
# The hold-time Telnyx capture (design 5.2) is its own php-fpm master and pool, repro-telnyx-capture.service,
# and keeps answering while the freeze drains and stops the app's FPM. Its command lines in /proc: the unit's
# ExecStart until FPM retitles itself, then the master and pool titles.
CAPTURE_NAME = "repro-telnyx-capture"
CAPTURE_COMMAND_LINES = (
    "/usr/sbin/php-fpm8.3 --nodaemonize --fpm-config /etc/repro-telnyx-capture/php-fpm.conf",
    "php-fpm: master process (/etc/repro-telnyx-capture/php-fpm.conf)",
    "php-fpm: pool repro-telnyx-capture",
)
# Process patterns, matched as substrings of /proc/<pid>/cmdline (ProductionAdapter.processes).
PROCESS_PATTERNS = ("writer_processes", "long_job_processes", "scheduler_processes")
# prepare (design 3.2): the switch half, steps 1 to 10, then the checks (11) and the evidence report (12). Each step is
# the method `_prepare_<name>`. A crash between two steps leaves the markers the README lists ("prepare"), which
# seal_sqlite (D12) reads to undo any part of the switch; steps 11 and 12 add only the report.
PREPARE_STEPS = ((1, "writers_drained"), (2, "inputs"), (3, "sqlite_frozen"), (4, "mysql_history"),
                 (5, "switch_record"), (6, "mysql_env"), (7, "env_replaced"), (8, "config"), (9, "fpm"),
                 (10, "receipt"), (11, "checks"), (12, "report"))
# The evidence inputs manifest that `check-evidence --write` (D11) writes and step 2 reads (README "prepare").
EVIDENCE_INPUTS = {"snapshot": "frozen snapshot record", "import": "import result",
                   "verify_fields": "verify-fields report", "migrate_status": "migrate:status --pending output",
                   "complete_schema": "complete-schema output", "backup_check": "final backup check"}
MANIFEST_KEYS = {"version", "run_id", "mode", "backup", "inputs"}
INPUT_KEYS = {"path", "sha256"}
BACKUP_KEYS = {"file", "position", "gtid_executed"}
MAX_INPUT_BYTES = guard.MAX_RECORD_BYTES
# `sha256sum <frozen snapshot>`: the digest, a space, a space or `*`, the path.
SNAPSHOT_RECORD = re.compile(rb"([0-9a-f]{64}) [ *][^\n]+\n?")
# Laravel 12's `migrate:status --pending` with nothing pending, whitespace collapsed.
NO_PENDING = "INFO No pending migrations."
BACKUP_VERIFIED = "frozen-base-publications-verified"
UTC_SECONDS = r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z"
# Design 3.2 step 2: free bytes on / (the SQLite file, the MySQL datadir and its binary logs on this host).
FREE_DISK_PATH = "/"
SEALED_HASH_KEYS = {"version", "run_id", "hold_id", "started_at", "database", "sha256"}
SWITCH_RECORD_KEYS = {"version", "run_id", "values", "env_sha256", "switch_at"}
# A switch secret (mysql-app.env holds one `DB_PASSWORD=<password>` line): mysql/fixture.sh render's password
# rule, nothing a .env line would need to quote.
SECRET_VALUE = r"[A-Za-z0-9._~+/=-]{16,256}"
# Bounded retries of a MySQL probe that finds nothing listening (design 3.2, critic HO-L6).
PROBE_ATTEMPTS = 3
PROBE_RETRY_SECONDS = 1.0
# Step 11's FastCGI probe waits at most this long for the socket of the FPM that step 9 started (critic HO-L6).
FPM_SOCKET_SECONDS = 15
# The token check (design 3.3): the check account of accounts.sql, which may take the writer token in an empty
# transaction while the gate is closed, from mysql.token_check_defaults; its private config cache lives in a 0700
# folder of the web user under /run, removed after the check (and before the next one, after a crash).
TOKEN_CHECK_ACCOUNT = "repro_token_check"
TOKEN_CHECK_PARENT = Path("/run")
TOKEN_CHECK_PREFIX = "repro-token-check-"
# check-evidence's input directory (design 3.2, 3.10): one file per manifest input, and the verified MySQL base's
# coordinate the operator records with its gtid_executed (the base's own receipt has no GTID set).
NIGHT_FILES = {"snapshot": "frozen.sha256", "import": "import-result.json",
               "verify_fields": "verify-fields.jsonl", "migrate_status": "migrate-status.txt",
               "complete_schema": "complete-schema.json", "backup_check": "final-backup-check.json"}
BACKUP_COORDINATE = "backup-coordinate.json"
# seal_sqlite, the rollback's undo of any part of prepare (design 3.5 steps 1 to 9). Each step is the method
# `_seal_sqlite_<name>` and is safe to run again; a rollback stopped anywhere, inside a step too, is finished by the
# next one (README "seal_sqlite").
SEAL_SQLITE_STEPS = ((1, "writers_drained"), (2, "gate"), (3, "env"), (4, "config"), (5, "fpm"), (6, "verify"),
                     (7, "unseal_proof"), (8, "unseal"), (9, "markers"))
# The rollback's record, paths.unseal_proof (design 3.5 step 7, and step 2's skipped gate close, which 3.7 reads).
UNSEAL_PROOF_KEYS = {"version", "run_id", "hold_id", "started_at", "database", "sha256", "sealed_sha256", "unchanged",
                     "problem", "gate", "gate_close_skipped", "at"}
# The drain record of a rollback that had no time for fpm_drain.py and stopped FPM at once (README "seal_sqlite").
DRAIN_SKIPPED = "skipped"


def _argv(value) -> bool:
    return isinstance(value, list) and bool(value) and all(isinstance(part, str) for part in value) \
        and Path(value[0]).is_absolute()


def _absolute(value) -> bool:
    # NUL would reach os.open as a ValueError, not a refusal (review D10V-3).
    return isinstance(value, str) and "\0" not in value and Path(value).is_absolute()


def _names(value, pattern: str, *, empty: bool = False) -> bool:
    """A list of distinct strings that each match the pattern (non-empty unless `empty`)."""
    return (isinstance(value, list) and (empty or bool(value))
            and all(guard.matches(pattern, item) for item in value) and len(set(value)) == len(value))


def _account(value) -> bool:
    # Application commands never run as root (design 1).
    return guard.matches(ACCOUNT, value) and value != "root"


def switch_keys(config: dict) -> list[str]:
    """Every .env key `prepare` writes and `seal_sqlite` restores: the switch and its secrets (DB_PASSWORD)."""
    return sorted({*config["mysql"]["switch"], *config["mysql"]["switch_secrets"]})


def env_denylist(config: dict) -> list[str]:
    """The one identity.json env_denylist load_config accepts: the night flags plus the switch keys."""
    return sorted({*config["mysql"]["night_flags"], *switch_keys(config)})


def _queue_command(argv, verb: str, app_root: str) -> bool:
    """`runuser -u www-data -- /bin/sh -c 'cd <app_root> && umask 0022 && exec /usr/bin/php artisan
    queue:<verb> "$1"' <name>`, matched by structure, not by text.

    The watchdog runs with UMask=0077, which runuser keeps; a 0600 flag reads as "not paused" to the
    deploy user's workers. The script is split on ' && ' only, into exactly three steps: `cd <app_root>`
    and `umask 0022` (in either order), then exactly `exec /usr/bin/php artisan queue:<verb> "$1"`. Each
    step must equal its form, so no other shell syntax (`;`, `#`, `|`, `&`, quotes, expansions,
    redirections, newlines) can hide another command, and app_root must be one plain shell word.
    The adapter's `connection:queue` argument becomes `$1`.
    """
    if not (_argv(argv) and len(argv) == len(QUEUE_PREFIX) + 2 and argv[:len(QUEUE_PREFIX)] == QUEUE_PREFIX):
        return False
    *before, command = argv[len(QUEUE_PREFIX)].split(" && ")
    return (guard.matches(SHELL_PATH, app_root)
            and sorted(before) == sorted([f"cd {app_root}", "umask 0022"])
            and command == f'exec {QUEUE_PHP} artisan queue:{verb} "$1"')


def _spares_capture(pattern: str) -> bool:
    """A process pattern that matches neither php-fpm by name nor the Telnyx capture's master or pool."""
    return (CAPTURE_NAME not in pattern and "php-fpm" not in pattern
            and not any(pattern in line for line in CAPTURE_COMMAND_LINES))


def _json_object(text: str) -> dict | None:
    """The one JSON object in a host command's output, or None: no NaN or Infinity, no repeated key."""
    def unique(pairs: list) -> dict:
        record = dict(pairs)
        if len(record) != len(pairs):
            raise ValueError("repeated key")
        return record

    def constant(name: str):
        raise ValueError(f"{name} is not JSON")

    try:
        value = json.loads(text, object_pairs_hook=unique, parse_constant=constant)
    except (ValueError, RecursionError):
        # Deep nesting within the size bound is a refusal, never a RecursionError (review D10V-3).
        return None
    return value if isinstance(value, dict) else None


def _probe_problems(output: str) -> str:
    """A refused MySQL probe reading's own "problems" texts, joined and cut to PROBLEM_CHARACTERS, or ""."""
    document = _json_object(output)
    problems = None if document is None else document.get("problems")
    if not (isinstance(problems, list) and problems and all(isinstance(item, str) for item in problems)):
        return ""
    text = "; ".join(problems)
    return text if len(text) <= PROBLEM_CHARACTERS else text[:PROBLEM_CHARACTERS - 3] + "..."


def _count(value) -> bool:
    return type(value) is int and value >= 0


def _seconds(value) -> bool:
    return type(value) in (int, float) and math.isfinite(value) and value >= 0


# The report keys the journal keeps besides the outcome, each when the report has it: a count, seconds or text.
# polls is the number of status readings: 0 means the drain never saw the pool (review W2F-6).
DRAIN_FIELDS = {"active_processes": _count, "waited_seconds": _seconds, "polls": _count,
                "problem": lambda value: isinstance(value, str)}


def _drain_record(output: str, purpose: str) -> dict:
    """What the journal keeps of fpm_drain.py's report: purpose, outcome, active processes, wait, polls, problem.

    The report is the one JSON object fpm_drain.py prints on stdout. Anything else raises, whatever the purpose;
    texts are cut to PROBLEM_CHARACTERS so the journal stays within what the guard reads.
    """
    report = _json_object(output)
    if report is None or not isinstance(report.get("outcome"), str):
        raise UnsafeCutover("The FPM drain report is not one JSON object with an outcome")
    record = {"purpose": purpose, "outcome": report["outcome"][:PROBLEM_CHARACTERS]}
    for key, valid in DRAIN_FIELDS.items():
        if key in report:
            if not (report[key] is None or valid(report[key])):
                raise UnsafeCutover(f"The FPM drain report has a malformed {key}")
            record[key] = report[key]
    if isinstance(record.get("problem"), str):
        record["problem"] = record["problem"][:PROBLEM_CHARACTERS]
    return record


def _require_freeze_drain(record: dict) -> None:
    """A freeze goes on only after an outcome it knows; an FPM pool the drain could not watch keeps the hold.

    That includes a timed_out drain without one status reading (polls 0, null or missing): only transient
    errors for the whole limit, so the stop would end requests the drain never saw (review W2F-6).
    """
    problem = record.get("problem") or "no problem given"
    if record["outcome"] == "unmonitored":
        raise UnsafeCutover(f"The FPM drain could not watch the pool ({problem}); "
                            "the freeze stops before the FPM stop and the hold stays")
    if record["outcome"] not in FREEZE_DRAIN_OUTCOMES:
        raise UnsafeCutover(f"The FPM drain report's outcome {record['outcome']!r} is not one the freeze knows")
    if record["outcome"] == "timed_out" and not record.get("polls"):
        raise UnsafeCutover(f"The FPM drain timed out with no status reading of the pool ({problem}); "
                            "the freeze stops before the FPM stop and the hold stays")


def load_identity_config(raw: bytes) -> dict:
    """Check the shape of identity.json; load_config checks it against the host config."""
    identity = guard.read_json(raw)
    guard.require(identity.keys() == IDENTITY_CONFIG_KEYS and guard.matches(RUN_ID, identity["run_id"])
                  and all(_absolute(identity[name]) for name in ("app_root", "sqlite_path", "mysql_datadir"))
                  and _names(identity["env_denylist"], ENV_KEY),
                  "identity.json must hold run_id, app_root, sqlite_path, mysql_datadir and env_denylist")
    return identity


def switch_secrets(config: dict) -> dict:
    """Each switch secret (DB_PASSWORD) from its root-only file of one `KEY=value` line; never printed."""
    found = {}
    for key, name in config["mysql"]["switch_secrets"].items():
        path = Path(name)
        try:
            raw = guard.read_protected(path)
            private = not stat.S_IMODE(path.lstat().st_mode) & 0o077
        except FileNotFoundError:
            raise UnsafeCutover(f"The secret file {path} for {key} is missing") from None
        except (guard.Refused, OSError) as error:
            raise UnsafeCutover(f"The secret file {path} for {key} is not root's: {error}") from None
        _require(private, f"The secret file {path} must be readable by root only")
        match = re.fullmatch(rf"{key}=({SECRET_VALUE})\n?", raw.decode("ascii", "replace"))
        _require(match is not None, f"{path} must hold one line {key}=<16 to 256 characters of A-Z a-z 0-9 . _ ~ + / = ->")
        found[key] = match.group(1)
    return found

def _check_mysql(mysql) -> None:
    guard.require(isinstance(mysql, dict) and mysql.keys() == MYSQL_KEYS,
                  "Host config mysql keys are incomplete or unknown")
    guard.require(mysql["mode"] in MODES, "Host config mysql mode must be drill or cutover")
    guard.require(type(mysql["offsite_waiver"]) is bool, "Host config offsite_waiver must be true or false")
    guard.require(guard.matches(SQL_NAME, mysql["database"]) and guard.matches(SQL_NAME, mysql["control_database"])
                  and mysql["database"] != mysql["control_database"] and guard.valid_uuid(mysql["target_uuid"])
                  and all(_absolute(mysql[name]) for name in ("datadir", "client", "socket", "probe_defaults",
                                                              "gate_defaults", "token_check_defaults")),
                  "Host config mysql target, client or defaults files are invalid")
    guard.require(_names(mysql["gated_accounts"], MYSQL_ACCOUNT)
                  and _names(mysql["lockable_accounts"], MYSQL_ACCOUNT, empty=True)
                  and not set(mysql["gated_accounts"]) & set(mysql["lockable_accounts"]),
                  "Host config mysql accounts are invalid")
    guard.require(_names(mysql["activity_excluded_tables"], SQL_NAME, empty=True)
                  and _names(mysql["smoke_tables"], SQL_NAME),
                  "Host config mysql tables are invalid")
    switch, secrets = mysql["switch"], mysql["switch_secrets"]
    guard.require(isinstance(switch, dict) and all(guard.matches(ENV_KEY, key) and guard.matches(ENV_VALUE, value)
                                                   for key, value in switch.items())
                  and isinstance(secrets, dict) and all(guard.matches(ENV_KEY, key) and _absolute(value)
                                                        for key, value in secrets.items())
                  and _names(mysql["night_flags"], ENV_KEY, empty=True),
                  "Host config switch keys, secrets or night flags are invalid")
    guard.require(SWITCH_ROUTE <= switch.keys() <= SWITCH_ROUTE | SWITCH_OPTIONAL and secrets.keys() == SWITCH_SECRETS
                  and not set(mysql["night_flags"]) & (switch.keys() | secrets.keys())
                  and ("DB_EXPECTED_ENGINE" not in switch or switch["DB_EXPECTED_ENGINE"] == "mysql"),
                  "Host config switch must hold the route keys and the app password only")
    guard.require(switch["DB_CONNECTION"] == "mysql" and switch["DB_DATABASE"] == mysql["database"]
                  and switch["DB_SOCKET"] == mysql["socket"]
                  and switch["REPRO_MYSQL_EXPECTED_SERVER_UUID"] == mysql["target_uuid"]
                  and f'{switch["DB_USERNAME"]}@localhost' in mysql["gated_accounts"],
                  "Host config switch values must match the mysql target and a gated account")
    guard.require(not mysql["offsite_waiver"] or mysql["mode"] == "drill",
                  "The off-site waiver is allowed only in drill mode")
    guard.require(type(mysql["free_disk_floor_bytes"]) is int
                  and 0 < mysql["free_disk_floor_bytes"] <= MAX_FREE_DISK_FLOOR,
                  "Host config free_disk_floor_bytes must be a positive number of bytes")


def load_config(raw: bytes, *, read: Callable[[Path], bytes]) -> dict:
    """Check host.json and the identity.json it names; refuse anything missing, unknown or malformed.

    `read(path)` returns a file's bytes. The CLI passes guard.read_protected, so identity.json gets
    the same root-only check as host.json.
    """
    config = guard.read_json(raw)
    guard.require(config.keys() == HOST_KEYS, "Host config keys are incomplete or unknown")
    paths, commands, options = config.get("paths"), config.get("commands"), config.get("options")
    guard.require(isinstance(paths, dict) and paths.keys() == PATHS
                  and all(isinstance(value, str) and Path(value).is_absolute() for value in paths.values()),
                  "Host config paths are incomplete")
    guard.require(isinstance(commands, dict) and commands.keys() in (COMMANDS, COMMANDS | {"timer_stop"})
                  and ("timer_stop" not in commands or _argv(commands["timer_stop"]))
                  and all(_argv(commands[name]) for name in SINGLE_COMMANDS)
                  and all(isinstance(commands[name], list) and all(map(_argv, commands[name]))
                          for name in COMMAND_LISTS)
                  # An empty list would skip the read-only smoke without saying so.
                  and bool(commands["smoke_readonly"]),
                  "Host config commands must be fixed absolute argument lists")
    guard.require(_queue_command(commands["queue_pause"], "pause", config["app_root"])
                  and _queue_command(commands["queue_resume"], "resume", config["app_root"]),
                  "Queue commands must run as www-data through runuser with umask 0022")
    guard.require(isinstance(config.get("sqlite"), str) and Path(config["sqlite"]).is_absolute()
                  and isinstance(config.get("deploy_locks"), list) and len(config["deploy_locks"]) == 2
                  and all(isinstance(lock, str) and Path(lock).is_absolute() for lock in config["deploy_locks"])
                  and isinstance(config.get("deploy_owner"), str),
                  "Host config SQLite path or deploy locks are invalid")
    # One `connection:queue` argument for `queue:pause`/`queue:resume`. The connection may hold hyphens
    # (`iguide-lab-tour:iguide-lab-tour`) but starts with a letter, so it is never read as an option.
    guard.require(isinstance(config.get("queues"), list) and config["queues"]
                  and all(isinstance(queue, str) and re.fullmatch(r"[a-z][a-z0-9-]*:[a-z0-9-]+", queue)
                          for queue in config["queues"]),
                  "Host config queues must be connection:queue names")
    for name in PROCESS_PATTERNS:
        guard.require(isinstance(config.get(name), list) and all(isinstance(item, str) and len(item) >= 8
                                                                for item in config[name]),
                      f"Host config {name} must be specific command-line patterns")
    # The freeze waits until no pattern matches and stops FPM through its own unit (design 5.4). A pattern or
    # a stop that names php-fpm, which cannot tell the app's pool from the capture's, or the capture itself
    # would stop the capture or wait for it until the freeze fails.
    stops = [*commands["stop_workers"], *commands["drain_fpm"], commands["fpm_stop"]]
    guard.require(all(_spares_capture(pattern) for name in PROCESS_PATTERNS for pattern in config[name])
                  and all(CAPTURE_NAME not in part and "php-fpm" not in part for argv in stops for part in argv),
                  "Host config must never wait for or stop the Telnyx capture or php-fpm by name")
    guard.require(type(config.get("command_seconds")) is int and 0 < config["command_seconds"] <= MAX_COMMAND_SECONDS
                  and isinstance(options, dict) and options.keys() == OPTIONS
                  and type(options["max_drain_seconds"]) is int and 0 < options["max_drain_seconds"] <= 45 * 60
                  and all(type(options[name]) is bool for name in OPTIONS - {"max_drain_seconds"}),
                  "Host config timing or owner options are invalid")
    guard.require(_absolute(config["app_root"]) and all(_account(config[name])
                                                        for name in ("app_owner", "app_group", "web_user")),
                  "Host config app root or accounts are invalid")
    # Design 1.3's runuser command, checked here as config_build checks it before every build (reviews A2-5, C2-7).
    try:
        config_build.check_command(config)
    except config_build.BuildFailed as refusal:
        raise guard.Refused(f"Host config {refusal}") from None
    _check_mysql(config["mysql"])
    fpm, smoke = config["fpm"], config["smoke"]
    guard.require(isinstance(fpm, dict) and fpm.keys() == FPM_KEYS and guard.matches(SERVICE_UNIT, fpm["unit"])
                  and _absolute(fpm["socket"]) and _absolute(fpm["status_socket"])
                  and type(fpm["drain_seconds"]) is int and 0 < fpm["drain_seconds"] <= MAX_COMMAND_SECONDS,
                  "Host config fpm unit, sockets or drain_seconds are invalid")
    # What fpm_drain.py refuses at run time, where its exit 1 also keeps the FPM stop of a rollback from running.
    guard.require(os.path.normpath(fpm["status_socket"]) != os.path.normpath(fpm["socket"]),
                  "Host config fpm.status_socket must be the pool's pm.status_listen, not fpm.socket: on the pool's "
                  "own socket every status request is an active process, and the drain never reads idle")
    guard.require(fpm["drain_seconds"] + FPM_DRAIN_MARGIN_SECONDS <= config["command_seconds"],
                  f"Host config fpm.drain_seconds must leave {FPM_DRAIN_MARGIN_SECONDS} s inside command_seconds: a "
                  "host command is stopped after command_seconds, and the FPM stop after the drain would never run")
    guard.require(isinstance(smoke, dict) and smoke.keys() == SMOKE_KEYS
                  and all(type(smoke[name]) is int and smoke[name] > 0 for name in SMOKE_KEYS),
                  "Host config smoke records must be the marked test client and shoot IDs")
    guard.require(isinstance(config["studio_pause_files"], list)
                  and all(_absolute(path) for path in config["studio_pause_files"])
                  and len(set(config["studio_pause_files"])) == len(config["studio_pause_files"]),
                  "Host config studio_pause_files must be absolute paths")
    # The denylist is checked in the very file the identity probe reads.
    guard.require(commands["identity"][-2:] == ["--config", paths["identity_config"]],
                  "The identity probe must read paths.identity_config")
    identity = load_identity_config(read(Path(paths["identity_config"])))
    guard.require((identity["app_root"], identity["sqlite_path"], identity["mysql_datadir"])
                  == (config["app_root"], config["sqlite"], config["mysql"]["datadir"]),
                  "identity.json must describe this host's app root, SQLite file and MySQL datadir")
    guard.require(sorted(identity["env_denylist"]) == env_denylist(config),
                  "identity.json env_denylist must equal the night flags plus the switch keys")
    return config


def read_policy(config: dict) -> tuple[bytes, dict]:
    raw = Path(config["paths"]["policy"]).read_bytes()
    return raw, guard.read_json(raw)


def runner_for(config: dict, journal: Journal, adapter: "ProductionAdapter", clock=time.time) -> Runner:
    options = config["options"]
    return Runner(
        journal, adapter.pinned, adapter, clock, max_drain_seconds=options["max_drain_seconds"],
        separate_freeze_approval=options["separate_freeze_approval"],
        adopt_external_hold=options["adopt_external_hold"],
        require_long_job_predrain=options["require_long_job_predrain"],
    )


def _sync_directory(directory: Path) -> None:
    descriptor = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def _runtime_directory(directory: Path) -> None:
    """Recreate a runtime directory that a reboot removed.

    /run is a fresh tmpfs after every boot, so the evidence, scheduler-hold and
    ingress directories are gone, not only their files. tmpfiles.d recreates
    them at boot; the controller does it too, in case that file is missing.
    Root-owned 0755: the guard refuses group or other write bits in its chain,
    and nginx workers must traverse the ingress directory.
    """
    try:
        directory.mkdir(mode=0o755)
    except FileExistsError:
        return
    # The watchdog service runs with UMask=0077.
    os.chmod(directory, 0o755)
    _sync_directory(directory.parent)


def _write_atomic(path: Path, value: dict, mode: int) -> None:
    _write_raw_atomic(path, (json.dumps(value, sort_keys=True, allow_nan=False) + "\n").encode("utf-8"), mode)


def _write_raw_atomic(path: Path, raw: bytes, mode: int) -> None:
    """These exact bytes at `path`: a temporary file beside it, fsynced, renamed over it, the folder fsynced."""
    _runtime_directory(path.parent)
    temporary = path.with_name(f".{path.name}.{uuid.uuid4().hex}")
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, mode)
    try:
        with os.fdopen(descriptor, "wb") as stream:
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
        _sync_directory(path.parent)
    finally:
        temporary.unlink(missing_ok=True)


def _remove(path: Path) -> None:
    if path.exists() or path.is_symlink():
        path.unlink()
        _sync_directory(path.parent)


def _require(condition: bool, message: str) -> None:
    if not condition:
        raise UnsafeCutover(message)


def _read_input(path: Path, label: str) -> bytes:
    """A night input another account may own: a regular file, never through a final link, size bounded."""
    try:
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
    except OSError as error:
        raise UnsafeCutover(f"The {label} cannot be read: {os.strerror(error.errno)}") from None
    try:
        _require(stat.S_ISREG(os.fstat(descriptor).st_mode), f"The {label} is not a regular file")
        with os.fdopen(os.dup(descriptor), "rb") as stream:
            content = stream.read(MAX_INPUT_BYTES + 1)
    finally:
        os.close(descriptor)
    _require(len(content) <= MAX_INPUT_BYTES, f"The {label} is larger than {MAX_INPUT_BYTES} bytes")
    return content


def _input_json(raw: bytes, label: str) -> dict:
    document = _json_object(raw.decode("utf-8", "replace"))
    _require(document is not None, f"The {label} is not one JSON object")
    return document


def _gtid_set(text) -> binlog_scan.GtidSet | None:
    try:
        return binlog_scan.GtidSet.parse(text) if isinstance(text, str) else None
    except binlog_scan.Unreadable:
        return None


def _coordinate(value, keys: set) -> bool:
    return (isinstance(value, dict) and value.keys() == keys and guard.matches(binlog_scan.LOG_NAME, value["file"])
            and type(value["position"]) is int and value["position"] >= 4
            and ("gtid_executed" not in keys or _gtid_set(value["gtid_executed"]) is not None))


def check_inputs(config: dict, pinned: Identity, policy: dict, started_at: int, manifest) -> dict:
    """The night's inputs bound to one target, one database and this run's snapshots (design 3.2 step 2).

    `manifest` is the evidence inputs manifest (README "prepare": the six inputs, each `{path, sha256}`, and the
    verified backup's coordinate). Every input is read again and must still have the digest the manifest
    records. `started_at` is the journal's hold. The first binding that differs refuses (UnsafeCutover), so
    prepare refuses before any change; check-evidence (D11) runs the same function before it writes the
    manifest. Returns the bound values: mode, both snapshots, the backup coordinate and the input digests.
    """
    mysql = config["mysql"]
    mode, waiver, target, database = mysql["mode"], mysql["offsite_waiver"], mysql["target_uuid"], mysql["database"]
    _require(isinstance(manifest, dict) and manifest.keys() == MANIFEST_KEYS and type(manifest["version"]) is int
             and manifest["version"] == 1 and manifest["mode"] in MODES and isinstance(manifest["inputs"], dict)
             and manifest["inputs"].keys() == EVIDENCE_INPUTS.keys(), "The evidence inputs manifest is malformed")
    _require(manifest["run_id"] == pinned.run_id, "The evidence inputs belong to another run")
    _require(manifest["mode"] == mode, f"The evidence inputs were checked for {manifest['mode']} mode, not {mode}")
    backup = manifest["backup"]
    _require(_coordinate(backup, BACKUP_KEYS), "The recorded backup coordinate is malformed")
    entries = manifest["inputs"]
    for name, entry in entries.items():
        _require((name == "backup_check" and entry is None)
                 or (isinstance(entry, dict) and entry.keys() == INPUT_KEYS and _absolute(entry["path"])
                     and is_sha256(entry["sha256"])), "The evidence inputs manifest is malformed")
    if waiver:
        _require(entries["backup_check"] is None,
                 "The off-site waiver replaces the final backup check: the manifest must name none")
    else:
        _require(entries["backup_check"] is not None, "Without the off-site waiver the final backup check is required")
    raw = {}
    for name, entry in entries.items():
        if entry is not None:
            raw[name] = _read_input(Path(entry["path"]), EVIDENCE_INPUTS[name])
            _require(guard.sha256(raw[name]) == entry["sha256"],
                     f"The {EVIDENCE_INPUTS[name]} changed after check-evidence recorded it")
    record = SNAPSHOT_RECORD.fullmatch(raw["snapshot"])
    _require(record is not None, "The frozen snapshot record is not one sha256sum line")
    frozen = record.group(1).decode("ascii")
    imported = _input_json(raw["import"], "import result")
    _require(imported.get("mode") == "import-empty" and imported.get("import_verified") is True
             and imported.get("source_changed") is False and is_sha256(imported.get("source_sha256")),
             "The import result is not a verified import-empty run")
    _require(imported.get("target_server_uuid") == target, "The import result names another MySQL server than the target")
    _require(imported.get("target_database") == database, "The import result names another database")
    snapshot = imported["source_sha256"]
    lines = [_json_object(line) for line in raw["verify_fields"].decode("utf-8", "replace").splitlines() if line.strip()]
    _require(bool(lines) and None not in lines, "The verify-fields report is not the tool's JSON lines")
    report = lines[-1]
    _require(report.get("result") == "all-fields-verified" and type(report.get("field_mismatches")) is int
             and report["field_mismatches"] == 0, "The verify-fields report is not all-fields-verified with zero mismatches")
    _require(report.get("source_sha256") == snapshot, "The verify-fields report checked another snapshot than the import")
    _require(report.get("target_server_uuid") == target,
             "The verify-fields report names another MySQL server than the target")
    _require(report.get("target_database") == database, "The verify-fields report names another database")
    _require(" ".join(raw["migrate_status"].decode("utf-8", "replace").split()) == NO_PENDING,
             "The migrate:status --pending output does not say that no migration is pending")
    schema = _input_json(raw["complete_schema"], "complete-schema output")
    _require(schema.get("verified") is True and schema.get("migration_ledger_unchanged") is True,
             "The complete-schema output is not verified with the migration ledger unchanged")
    _require(schema.get("server_uuid") == target, "The complete-schema output names another MySQL server than the target")
    if mode == "cutover":
        _require(frozen == snapshot, "Cutover mode needs one snapshot: the frozen snapshot record, the import and "
                                     "verify-fields differ")
    if not waiver:
        check = _input_json(raw["backup_check"], "final backup check")
        _require(check.get("result") == BACKUP_VERIFIED, "The final backup check did not verify the frozen base publications")
        _require(check.get("release") == pinned.release_sha, "The final backup check is for another release")
        frozen_at, sqlite_base, mysql_base = check.get("frozen_at"), check.get("sqlite"), check.get("mysql")
        _require(guard.matches(UTC_SECONDS, frozen_at) and isinstance(sqlite_base, dict)
                 and isinstance(mysql_base, dict), "The final backup check is malformed")
        try:
            # The pattern admits digits that name no time (2026-02-30): a refusal, never a ValueError (D10R-4).
            frozen_seconds = calendar.timegm(time.strptime(frozen_at, "%Y-%m-%dT%H:%M:%SZ"))
        except ValueError:
            raise UnsafeCutover("The final backup check is malformed: frozen_at is no UTC time") from None
        _require(frozen_seconds >= started_at, "The final backup check names a freeze before this run's hold")
        _require(sqlite_base.get("snapshot_sha256") == frozen, "The SQLite publication is not the frozen snapshot")
        _require(mysql_base.get("identity") == target, "The MySQL base names another server than the target")
        _require(mysql_base.get("database") == database, "The MySQL base names another database")
        point = mysql_base.get("pitr")
        _require(isinstance(point, dict) and (point.get("binlog_file"), point.get("binlog_position"))
                 == (backup["file"], backup["position"]),
                 "The MySQL base's coordinate is not the recorded backup coordinate")
    _require(is_sha256(policy.get("mysql_live_config_sha256")),
             "The policy pins no MySQL live config hash (mysql_live_config_sha256)")
    disk = os.statvfs(FREE_DISK_PATH)
    free, floor = disk.f_bavail * disk.f_frsize, mysql["free_disk_floor_bytes"]
    _require(free > floor, f"Free disk on {FREE_DISK_PATH} is {free} bytes, below the floor of {floor} bytes")
    return {"mode": mode, "offsite_waiver": waiver, "snapshot_sha256": snapshot, "frozen_snapshot_sha256": frozen,
            "backup": {name: backup[name] for name in sorted(BACKUP_KEYS)},
            "inputs": {name: entry for name, entry in sorted(entries.items())}}


def night_manifest(directory, run_id: str, mode: str) -> dict:
    """The evidence inputs manifest (README "prepare") from the night's input directory (check-evidence, design 3.10).

    Each input is NIGHT_FILES' file in `directory`, hashed as check_inputs reads it (a regular file, never through a
    final link, at most 1 MiB); a missing final backup check is null (the waiver), any other missing input refuses.
    BACKUP_COORDINATE holds exactly `{"file", "position", "gtid_executed"}`: the verified MySQL base's binary log
    coordinate with its gtid_executed (with the waiver, the coordinate recorded after the copy load). The bindings
    themselves are check_inputs'.
    """
    _require(isinstance(directory, (str, Path)) and "\0" not in str(directory) and Path(directory).is_absolute()
             and ".." not in Path(directory).parts,
             "The night's input directory must be an absolute path without NUL or '..'")
    night = Path(directory)
    coordinate = _input_json(_read_input(night / BACKUP_COORDINATE, f"backup coordinate ({BACKUP_COORDINATE})"),
                             f"backup coordinate ({BACKUP_COORDINATE})")
    _require(_coordinate(coordinate, BACKUP_KEYS),
             f"The recorded backup coordinate ({BACKUP_COORDINATE}) is malformed: it must hold exactly file, position "
             "and gtid_executed")
    inputs = {}
    for name, filename in NIGHT_FILES.items():
        path = night / filename
        try:
            path.lstat()
        except FileNotFoundError:
            _require(name == "backup_check", f"The night's input directory has no {EVIDENCE_INPUTS[name]} ({filename})")
            inputs[name] = None
            continue
        except OSError as error:
            raise UnsafeCutover(f"The {EVIDENCE_INPUTS[name]} cannot be read: {os.strerror(error.errno)}") from None
        inputs[name] = {"path": str(path), "sha256": guard.sha256(_read_input(path, EVIDENCE_INPUTS[name]))}
    return {"version": 1, "run_id": run_id, "mode": mode,
            "backup": {name: coordinate[name] for name in sorted(BACKUP_KEYS)}, "inputs": inputs}


@dataclass
class PrepareRun:
    """What one prepare call carries from step to step, in memory only: a new call (a retry, or after a crash)
    runs every step again from what is on disk."""

    lease: Lease
    deadline: float
    inputs: dict | None = None
    env: bytes | None = None
    record: dict | None = None
    switched: bytes | None = None
    checks: dict | None = None


@dataclass
class SealRun:
    """What one seal_sqlite call carries from step to step, in memory only: a retried rollback runs every step
    again from what is on disk."""

    lease: Lease
    deadline: float
    record: dict | None = None  # this run's switch record, when prepare wrote one
    env_restored: bool = False
    config_built: bool = False
    gate: dict | None = None  # gate.close_gate's result: before, revoked, killed
    gate_close_skipped: bool = False


class ProductionAdapter:
    def __init__(self, config: dict, journal: Journal, *, proc: Path = Path("/proc"), clock=time.time):
        self.config = config
        self.journal = journal
        self.proc = proc
        self.clock = clock
        self.paths = {name: Path(value) for name, value in config["paths"].items()}
        self.database = Path(config["sqlite"])
        self.locks = [Path(lock) for lock in config["deploy_locks"]]
        import pwd

        account = pwd.getpwnam(config["deploy_owner"])
        self.owner = (account.pw_uid, account.pw_gid)
        self.policy_raw, self.policy = read_policy(config)
        self.pinned = Identity(**self.policy["identity"])

    # Host commands and process observation.

    def _remaining(self, deadline: float, refusal: str) -> float:
        remaining = deadline - self.clock()
        _require(remaining > 0, refusal)
        return remaining

    def _execute(self, argv: list[str], deadline: float | None = None, *, environment: dict | None = None,
                 **process) -> tuple[int, str]:
        """Run one host command and return its exit status and standard output.

        The command runs in its own session and process group. runuser does not pass SIGKILL on to the
        command it started, so a timeout, or anything else that ends the wait (an interrupt), kills the
        whole group before it is reported: no queue command keeps running to change a flag afterwards.
        Its environment is HOST_ENVIRONMENT, or `environment`, with the child tag; `process` passes cwd, user,
        group, extra_groups and umask on to Popen (step 11's profile probes run as admit.py runs the profile).
        A command that calls setsid itself would leave the group and outlive the kill; no host command
        does, and a new one must not (README "seal_sqlite": host commands that start their own session).
        """
        seconds = self.config["command_seconds"]
        if deadline is not None:
            refusal = f"Host command reached its deadline: {Path(argv[0]).name}"
            seconds = min(seconds, self._remaining(deadline, refusal))
        environment = {**(HOST_ENVIRONMENT if environment is None else environment), CHILD_TAG: "1"}
        with subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                              text=True, env=environment, start_new_session=True, **process) as child:
            try:
                if deadline is not None:
                    seconds = min(seconds, self._remaining(deadline, refusal))
                output, _ = child.communicate(timeout=seconds)
            except BaseException as stopped:
                try:
                    os.killpg(child.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                child.wait()
                if isinstance(stopped, subprocess.TimeoutExpired):
                    raise UnsafeCutover(f"Host command timed out: {Path(argv[0]).name}") from None
                raise
        if deadline is not None:
            self._remaining(deadline, refusal)
        return child.returncode, output

    def _run(self, argv: list[str], deadline: float | None = None) -> str:
        """Run one host command (as _execute) and return its standard output; any exit but 0 raises."""
        code, output = self._execute(argv, deadline)
        if code != 0:
            raise UnsafeCutover(f"Host command failed: {Path(argv[0]).name} exited {code}")
        return output

    def _pids(self, predicate) -> list[int]:
        found = []
        for entry in self.proc.iterdir():
            if not entry.name.isdigit() or int(entry.name) == os.getpid():
                continue
            try:
                if predicate(entry):
                    found.append(int(entry.name))
            except (FileNotFoundError, ProcessLookupError, PermissionError):
                continue
        return found

    def processes(self, patterns: list[str]) -> list[int]:
        def matches(entry: Path) -> bool:
            command = (entry / "cmdline").read_bytes().replace(b"\0", b" ").decode("utf-8", "replace")
            return any(pattern in command for pattern in patterns)

        return self._pids(matches) if patterns else []

    def _wait_absent(self, patterns: list[str], deadline: float, message: str) -> None:
        while self.processes(patterns):
            if self.clock() >= deadline:
                raise UnsafeCutover(message)
            time.sleep(POLL_SECONDS)

    # Observations.

    def identity(self) -> Identity:
        try:
            return Identity(**json.loads(self._run(self.config["commands"]["identity"])))
        except (TypeError, ValueError) as error:
            raise UnsafeCutover("The host identity probe returned an invalid identity") from error

    def _writes_impossible(self) -> bool:
        """No journal, or one whose writes_possible is exactly false: MySQL has never been opened for writes."""
        try:
            state = self.journal.read()
        except (OSError, ValueError) as error:
            raise UnsafeCutover("The cutover journal cannot be read") from error
        return state is None or (isinstance(state, dict) and state.get("writes_possible") is False)

    def _mysql(self) -> dict:
        """One MySQL probe reading (design 1.2).

        Exit 0 is the reading as printed. Exit 3 means nothing listens at the socket and prints exactly
        {"version": 2, "reachable": false}. The gate lives in the grant tables, so a server that is down
        cannot be written: that reads as not writable and no business activity, but only while there is no
        journal or its writes_possible is false; otherwise it raises, as every other exit does.
        """
        code, output = self._execute(self.config["commands"]["mysql"])
        if code not in (0, MYSQL_UNREACHABLE):
            refused = f"The MySQL target probe refused the reading (exit {code})"
            problems = _probe_problems(output) if code in MYSQL_REFUSALS else ""
            raise UnsafeCutover(f"{refused}: {problems}" if problems else refused)
        try:
            observed = json.loads(output)
        except ValueError as error:
            raise UnsafeCutover("The MySQL target probe returned invalid JSON") from error
        if code == MYSQL_UNREACHABLE:
            if not (isinstance(observed, dict) and observed.keys() == {"version", "reachable"}
                    and type(observed["version"]) is int and observed["version"] == 2
                    and observed["reachable"] is False):
                raise UnsafeCutover("The MySQL target probe exited 3 without reporting an unreachable server")
            if not self._writes_impossible():
                raise UnsafeCutover("The MySQL target is unreachable while MySQL writes may be possible")
            return {**observed, "writable": False, "business_activity": False}
        if not (isinstance(observed, dict) and type(observed.get("writable")) is bool
                and type(observed.get("business_activity")) is bool):
            raise UnsafeCutover("The MySQL target probe must report writable and business_activity")
        return observed

    def _live_config(self) -> live_config.LiveConfig:
        """The app's live config (design 1.3): the cache, or the .env fallback; unmeasurable raises."""
        try:
            return live_config.measure(self.config["app_root"])
        except (live_config.Unmeasurable, OSError) as error:
            raise UnsafeCutover(f"The live config cannot be measured: {error}") from None

    def _observed(self) -> tuple[Observation, dict, live_config.LiveConfig]:
        """The observation with the MySQL reading and the live config it was made from."""
        mysql = self._mysql()
        live = self._live_config()
        observation = Observation(
            engine=live.engine,
            admissions_open=self.paths["ingress_open"].exists(),
            workers_stopped=not self.processes(self.config["writer_processes"]),
            sqlite_writable=sqlite_seal.writable(self.database),
            mysql_writable=mysql["writable"],
            mysql_business_activity=mysql["business_activity"],
            deployment_fenced=deploy_marker.published(self.paths["deploy_marker"], self.pinned.run_id),
        )
        return observation, mysql, live

    def observe(self) -> Observation:
        return self._observed()[0]

    def _measured_authority(self, observed: Observation, mysql: dict, live: live_config.LiveConfig) -> dict:
        """What an authority proof says about the observed engine, every value measured now (design 3.4).

        The guard compares these with the pins: the route hash with `<engine>_live_config_sha256`, the
        database identity with the source identity (SQLite) or the target UUID (MySQL), release and
        artifact with the pinned identity. A fallback config has no route hash, so nothing is published.
        """
        if live.source != live_config.CACHE or live.live_config_sha256 is None:
            raise UnsafeCutover(f"The live config is the .env fallback ({live.problem}), not a parsed config "
                                "cache; no authority is published from it")
        identity = self.identity()
        if observed.engine == "sqlite":
            database_identity = identity.source_sha256
        elif observed.engine == "mysql":
            database_identity = mysql.get("server_uuid")
            if database_identity != self.config["mysql"]["target_uuid"]:
                raise UnsafeCutover("The MySQL probe did not read the target's server UUID")
        else:
            raise UnsafeCutover("The live engine is neither sqlite nor mysql")
        return {"database_identity": database_identity, "live_config_sha256": live.live_config_sha256,
                "release_sha": identity.release_sha, "artifact_sha256": identity.artifact_sha256}

    def require_quiescent(self) -> None:
        def tagged(entry: Path) -> bool:
            return f"{CHILD_TAG}=1".encode() in (entry / "environ").read_bytes().split(b"\0")

        if self._pids(tagged):
            raise UnsafeCutover("A host command from an earlier controller is still running")

    def preflight_sha256(self) -> str:
        return digest({"config": self.config, "identity": asdict(self.identity()), "policy": self.policy})

    def boot_id(self) -> str:
        return self.paths["boot_id"].read_text(encoding="ascii").strip()

    def long_jobs_drained(self) -> bool:
        return not self.processes(self.config["long_job_processes"])

    # Hold, drain and freeze.

    def close_admissions(self, lease: Lease, deadline: int) -> None:
        lease.require_current()
        _remove(self.paths["ingress_open"])
        _remove(self.paths["scheduler_release"])
        deploy_marker.publish(self.paths["deploy_marker"], self.locks, self.pinned.run_id,
                              max(0.0, deadline - self.clock()), owner=self.owner)
        for queue in self.config["queues"]:
            self._run(self.config["commands"]["queue_pause"] + [queue], deadline)

    def drain(self, lease: Lease, deadline: int, *, purpose: str) -> dict | None:
        """Stop the worker groups, drain and stop PHP-FPM, then wait until no writer process remains.

        drain_fpm is fpm_drain.py, then the FPM stop (design 5.4). The first command's stdout is fpm_drain's
        report; its record (_drain_record) is returned for the runner to journal with the action. A freeze goes
        on after drained, timed_out with at least one status reading (the stop ends what still runs) or stopped,
        and refuses before any further drain_fpm command on any other outcome, "unmonitored" and a timed_out
        with polls 0 included (review W2F-6). A rollback records any outcome and always runs the stop: nginx
        holds every request by then, so a pool it cannot watch protects nothing (review A2-2). With no drain_fpm
        command a rollback returns None and a freeze refuses.

        A rollback that has less than fpm.drain_seconds plus FPM_DRAIN_MARGIN_SECONDS left before its deadline when
        the FPM drain would start does not start fpm_drain.py: the deadline would kill it, and the FPM stop after it
        would never run. It runs the rest of drain_fpm (the stop) at once and records outcome "skipped" (README
        "seal_sqlite": the rollback under a short deadline). A freeze is unchanged.
        """
        if purpose not in DRAIN_PURPOSES:
            raise UnsafeCutover("The drain purpose must be freeze or rollback")
        lease.require_current()
        commands = self.config["commands"]
        if purpose == "freeze" and not commands["drain_fpm"]:
            raise UnsafeCutover("The freeze needs drain_fpm: fpm_drain.py, then the FPM stop (design 5.4)")
        for argv in commands["stop_workers"]:
            self._run(argv, deadline)
        record = None
        left = deadline - self.clock()
        needed = self.config["fpm"]["drain_seconds"] + FPM_DRAIN_MARGIN_SECONDS
        if purpose == "rollback" and commands["drain_fpm"] and left < needed:
            record = {"purpose": purpose, "outcome": DRAIN_SKIPPED, "waited_seconds": 0, "polls": 0,
                      "problem": f"{max(0, int(left))} s were left before the rollback's deadline, fewer than "
                                 f"fpm.drain_seconds plus {FPM_DRAIN_MARGIN_SECONDS} s: FPM was stopped without the "
                                 "drain"}
            for argv in commands["drain_fpm"][1:]:
                # The mandatory rollback stop must still start if draining workers
                # consumed the budget. Keep its existing one-second minimum here,
                # without extending a freeze, evidence check or admission decision.
                self._run(argv, max(deadline, self.clock() + 1.0))
            self._wait_absent(self.config["writer_processes"], deadline, "Writers are still running after the stop")
            return record
        for index, argv in enumerate(commands["drain_fpm"]):
            output = self._run(argv, deadline)
            if index == 0:
                record = _drain_record(output, purpose)
                if purpose == "freeze":
                    _require_freeze_drain(record)
        self._wait_absent(self.config["writer_processes"], deadline, "Writers are still running after the stop")
        return record

    def _scheduler_closed(self) -> bool:
        state = self.journal.read()
        try:
            guard.authority(self.policy, state, "scheduler")
        except guard.Refused:
            return True
        return False

    def hold_and_drain_scheduler(self, lease: Lease, deadline: int) -> SchedulerDrain:
        lease.require_current()
        path = self.paths["scheduler_hold"]
        record = json.loads(path.read_text(encoding="utf-8")) if path.exists() else None
        if record is None or record.get("boot_id") != self.boot_id():
            _write_atomic(path, {"version": 1, "identity_sha256": digest(asdict(self.pinned)),
                                 "boot_id": self.boot_id(), "hold_id": str(uuid.uuid4())}, 0o600)
        self._wait_absent(self.config["scheduler_processes"], deadline, "Scheduled commands are still running")
        return self.scheduler_drain()

    def scheduler_drain(self) -> SchedulerDrain:
        try:
            record = json.loads(self.paths["scheduler_hold"].read_text(encoding="utf-8"))
        except (OSError, ValueError) as error:
            raise UnsafeCutover("Scheduler hold observation is unavailable") from error
        return SchedulerDrain(record["identity_sha256"], record["boot_id"], record["hold_id"],
                              self._scheduler_closed(), len(self.processes(self.config["scheduler_processes"])))

    def freeze(self, lease: Lease, deadline: int) -> None:
        lease.require_writers_drained()
        hold = self._hold_binding()
        sqlite_seal.seal(self.database, self.paths["sqlite_seal_record"], proc=self.proc)
        self._record_sealed_hash(hold)

    def _journal_state(self) -> dict:
        try:
            state = self.journal.read()
        except (OSError, ValueError) as error:
            raise UnsafeCutover("The cutover journal cannot be read") from error
        return state if isinstance(state, dict) else {}

    def _hold_binding(self) -> dict:
        """The journal's hold that a sealed hash belongs to: its hold_id, or its started_at, whichever it has."""
        state = self._journal_state()
        hold = state.get("hold")
        hold_id = hold.get("hold_id") if isinstance(hold, dict) and isinstance(hold.get("hold_id"), str) else None
        started_at = state.get("started_at") if type(state.get("started_at")) is int else None
        if hold_id is None and started_at is None:
            raise UnsafeCutover("The sealed SQLite hash is bound to the journal's hold, and the journal has no hold")
        return {"hold_id": hold_id, "started_at": started_at}

    def _record_sealed_hash(self, hold: dict) -> None:
        """The sealed file's SHA-256 for this run and hold, right after the seal (design 3.2 step 3).

        `prepare` (the file still frozen), the rollback's unseal proof and the short way back compare the
        file with it; retire keeps it for the way back. A record for this run and hold is never re-pinned: a
        repeated freeze of a file that changed since refuses. Another run's or another hold's record (a journal
        reset by hand and held again under the same run ID) is replaced.
        """
        path = self.paths["sealed_hash"]
        record = {"version": 1, "run_id": self.pinned.run_id, **hold, "database": str(self.database),
                  "sha256": sqlite_seal.file_sha256(self.database)}
        try:
            earlier = json.loads(path.read_text(encoding="utf-8"))
        except FileNotFoundError:
            earlier = None
        except (OSError, ValueError) as error:
            raise UnsafeCutover("The sealed SQLite hash record cannot be read") from error
        if isinstance(earlier, dict) and all(earlier.get(key) == record[key]
                                             for key in ("run_id", "hold_id", "started_at")):
            if earlier != record:
                raise UnsafeCutover("The sealed SQLite file changed after the freeze hashed it")
            return
        _write_atomic(path, record, 0o600)

    # prepare (design 3.2), steps 1 to 10: the switch half. D11 adds the checks (11) and the report (12).

    def prepare(self, lease: Lease, deadline: int) -> None:
        """Switch the frozen host to MySQL, read-only behind the closed gate (design 3.2 steps 1 to 10).

        Steps 1 to 4 refuse before any change (step 4 only ends app sessions, which hold no grant). From step 5
        on a failure leaves the switch partly done, and seal_sqlite undoes any part of it (design 3.5, D12).
        Every step is safe to run again: a retry, or a call after a controller that stopped between two steps,
        redoes or keeps each step from what is on disk (README "prepare" lists the markers).
        """
        run = PrepareRun(lease, deadline)
        for number, name in PREPARE_STEPS:
            lease.require_current()
            _require(self.clock() < deadline, f"prepare ran out of time before step {number} ({name.replace('_', ' ')})")
            getattr(self, f"_prepare_{name}")(run)

    def _prepare_writers_drained(self, run: PrepareRun) -> None:
        """Step 1: admitted writers drained under this lease, in a frozen journal that never opened MySQL."""
        run.lease.require_writers_drained()
        state = self._journal_state()
        _require(state.get("phase") == "frozen" and state.get("writes_possible") is False,
                 "prepare runs only in a frozen journal in which MySQL was never opened for writes")

    def _prepare_inputs(self, run: PrepareRun) -> None:
        """Step 2: check-evidence's manifest, every input re-read and bound (check_inputs), the free disk floor."""
        try:
            raw = guard.read_protected(self.paths["evidence_inputs"])
        except FileNotFoundError:
            raise UnsafeCutover("The evidence inputs manifest is missing: run check-evidence --write first") from None
        except (guard.Refused, OSError) as error:
            raise UnsafeCutover(f"The evidence inputs manifest cannot be read: {error}") from None
        manifest = _json_object(raw.decode("utf-8", "replace"))
        _require(manifest is not None, "The evidence inputs manifest is not one JSON object")
        started_at = self._journal_state().get("started_at")
        _require(type(started_at) is int, "The cutover journal has no hold to bind the evidence inputs to")
        run.inputs = check_inputs(self.config, self.pinned, self.policy, started_at, manifest)

    def _prepare_sqlite_frozen(self, run: PrepareRun) -> None:
        """Step 3: SQLite still sealed, its WAL empty, and the file the one freeze hashed for this run and hold."""
        _require(not sqlite_seal.writable(self.database), "SQLite is writable: the freeze was lost")
        wal = Path(f"{self.database}-wal")
        _require(not (wal.exists() and wal.stat().st_size), "The SQLite WAL is not empty")
        try:
            record = guard.read_json(guard.read_protected(self.paths["sealed_hash"]))
        except FileNotFoundError:
            raise UnsafeCutover("No sealed SQLite hash: the freeze did not record one") from None
        except (guard.Refused, OSError, ValueError) as error:
            raise UnsafeCutover(f"The sealed SQLite hash record is malformed: {error}") from None
        _require(record.keys() == SEALED_HASH_KEYS and is_sha256(record["sha256"]),
                 "The sealed SQLite hash record is malformed")
        binding = self._hold_binding()
        _require((record["run_id"], record["hold_id"], record["started_at"])
                 == (self.pinned.run_id, binding["hold_id"], binding["started_at"]),
                 "The sealed SQLite hash belongs to another run or hold")
        _require(record["database"] == str(self.database), "The sealed SQLite hash names another database")
        _require(sqlite_seal.file_sha256(self.database) == record["sha256"],
                 "The sealed SQLite file changed after the freeze hashed it")

    def _prepare_mysql_history(self, run: PrepareRun) -> None:
        """Step 4: the gate exactly closed, no app session left, no app-schema event since the verified backup."""
        mysql = self.config["mysql"]
        reading = self._probe(self.config["commands"]["mysql"], run.deadline)
        _require((reading.get("server_uuid"), reading.get("database")) == (mysql["target_uuid"], mysql["database"]),
                 "The MySQL probe read another server or database than the target")
        _require(reading.get("gate") == "closed" and reading.get("writable") is False
                 and reading.get("business_activity") is False,
                 f"The MySQL gate is not exactly closed with no business activity (it reads {str(reading.get('gate'))[:20]})")
        sessions = reading.get("gated_sessions")
        _require(type(sessions) is int and sessions >= 0, "The MySQL probe reading has no app session count")
        if sessions:
            # Sessions hold the privileges they connected with: none may outlive armed_from (design 2.1, 3.2).
            import gate

            closed = gate.close_gate(self.config)
            _require(closed["before"] == "closed", "The MySQL gate was not closed when its app sessions were ended; "
                                                   "it is closed now, and prepare stops")
        self._history(run)

    def _prepare_switch_record(self, run: PrepareRun) -> None:
        """Step 5: the switch keys' SQLite values for this run (verified on a retry), never a copy of .env. What
        steps 6 and 7 would refuse in the .env itself is refused first, so it leaves no record (review D10R-6)."""
        run.env = self._read_env()
        keys = switch_keys(self.config)
        try:
            envfile.require_switchable(self._env_path(), keys)
        except (envfile.Refused, OSError) as error:
            raise UnsafeCutover(f"The .env cannot be switched: {error}") from None
        night_flags = self.config["mysql"]["night_flags"]
        record = self._switch_record()
        if record is None:
            try:
                engine = live_config.env_values(run.env, ("DB_CONNECTION",)).get("DB_CONNECTION")
                values = envfile.values(run.env, keys)
            except (live_config.Unmeasurable, envfile.Refused) as error:
                raise UnsafeCutover(f"The SQLite .env cannot be recorded: {error}") from None
            _require(engine == "sqlite", "The .env does not read DB_CONNECTION=sqlite and there is no switch record: it "
                                         "was switched by hand, and nothing could restore SQLite")
            record = {"version": 1, "run_id": self.pinned.run_id, "values": values,
                      "env_sha256": envfile.digest(run.env, night_flags), "switch_at": None}
            _write_atomic(self.paths["switch_record"], record, 0o600)
        else:
            _require(record["run_id"] == self.pinned.run_id, "The switch record belongs to another run")
            try:
                restored = envfile.assign(run.env, record["values"])
            except envfile.Refused as error:
                raise UnsafeCutover(f"The switch record cannot be applied to the .env: {error}") from None
            _require(envfile.digest(restored, night_flags) == record["env_sha256"],
                     "The switch record does not match the .env (its digest differs)")
        run.record = record

    def _prepare_mysql_env(self, run: PrepareRun) -> None:
        """Step 6: the MySQL .env in memory, the switch keys set and every other line byte for byte; its
        config_sha256, with the denylist the identity probe reads, must equal the current file's."""
        assignments = {**self.config["mysql"]["switch"], **switch_secrets(self.config)}
        try:
            run.switched = envfile.assign(run.env, assignments)
        except envfile.Refused as error:
            raise UnsafeCutover(f"The MySQL .env cannot be built: {error}") from None
        import identity_probe

        try:
            denylist = load_identity_config(guard.read_protected(self.paths["identity_config"]))["env_denylist"]
            same = (identity_probe.config_sha256(run.env, denylist)
                    == identity_probe.config_sha256(run.switched, denylist))
        except (guard.Refused, OSError, ValueError) as error:
            raise UnsafeCutover(f"The switched .env cannot be measured as the identity probe measures it: {error}") \
                from None
        _require(same, "The switch would change config_sha256: a switch key is not on identity.json's env_denylist "
                       "(critic RC-H1); nothing was written")

    def _prepare_env_replaced(self, run: PrepareRun) -> None:
        """Step 7: the .env replaced atomically, owner, mode and ACL kept (envfile.replace); once is enough."""
        if run.env != run.switched:
            try:
                envfile.replace(self._env_path(), run.switched, expected=run.env)
            except envfile.Refused as error:
                raise UnsafeCutover(f"The .env was not replaced: {error}") from None
        _require(self._read_env() == run.switched, "The .env does not hold the switch after its replacement")

    def _prepare_config(self, run: PrepareRun) -> None:
        """Step 8: the MySQL config cache built to the pin, measured again (the deploy user owns config.php), and
        switch_at recorded."""
        pin = self.policy["mysql_live_config_sha256"]
        try:
            config_build.build(self.config, expected_engine="mysql", expected_sha256=pin, deadline=run.deadline)
        except config_build.BuildFailed as error:
            raise UnsafeCutover(f"The MySQL config cache was not built: {error}") from None
        live = self._live_config()
        _require(live.engine == "mysql" and live.source == live_config.CACHE and live.live_config_sha256 == pin,
                 "The live config after the build is not the MySQL pin from the cache; FPM was not started")
        run.record = {**run.record, "switch_at": int(self.clock())}
        _write_atomic(self.paths["switch_record"], run.record, 0o600)

    def _prepare_fpm(self, run: PrepareRun) -> None:
        """Step 9: fresh FPM workers on the MySQL config (the freeze's drain stopped FPM)."""
        self._run(self.config["commands"]["fpm_start"], run.deadline)

    def _prepare_receipt(self, run: PrepareRun) -> None:
        """Step 10: the backup range scanned once more, then the latch removed and the receipt written."""
        until = self._history(run)
        _remove(self.paths["activity"])
        mysql = self.config["mysql"]
        _write_atomic(self.paths["receipt"], {
            "version": 1, "run_id": self.pinned.run_id, "server_uuid": mysql["target_uuid"],
            "database": mysql["database"], "backup": run.inputs["backup"],
            "armed_from": {"file": until["file"], "position": until["position"]},
        }, 0o600)

    def _prepare_checks(self, run: PrepareRun) -> None:
        """Step 11 (design 3.2, 5.3): every process context, FPM, the read-only smoke as both users, the token check
        and the MySQL reading, each recorded in run.checks for the report; the first that fails refuses.

        Every command is checked before the first runs, so a profile, a smoke command or a token check account that
        would refuse runs nothing: application PHP never runs as root (wave 1: cutover:smoke refuses root only
        after artisan bootstrapped, which may already have written root-owned files where www-data writes)."""
        mysql = self.config["mysql"]
        contexts = self._profile_contexts()
        smokes = self._smoke_commands()
        account = self._token_check_account()
        receipt_sha256 = guard.sha256(self._protected(self.paths["receipt"], "receipt"))
        expected = {"engine": "mysql", "database": mysql["database"], "server_uuid": mysql["target_uuid"],
                    "route_sha256": self.policy["mysql_live_config_sha256"]}
        run.checks = {
            "profile_probes": [self._profile_probe(context, expected, run.deadline) for context in contexts],
            "fastcgi": self._fastcgi_probe(expected, run.deadline),
            "readonly_smoke": [self._readonly_smoke(argv, user, group, expected, run.deadline)
                               for argv, user, group in smokes],
            "token_check": self._token_check(account, run.deadline),
            "mysql": evidence.mysql_check(self._probe(self.config["commands"]["mysql"], run.deadline),
                                          self._gate_check(run.deadline), mysql, receipt_sha256),
        }

    def _prepare_report(self, run: PrepareRun) -> None:
        """Step 12: the evidence report (README "The evidence report"), canonical JSON, root 0600: the mode, both
        snapshots, the backup coordinate and the input digests check_inputs bound, the receipt, switch_at, the
        freeze's FPM drain record from the journal and step 11's checks."""
        receipt_raw = self._protected(self.paths["receipt"], "receipt")
        receipt = guard.read_json(receipt_raw)
        mysql = self.config["mysql"]
        report = evidence.build(run_id=self.pinned.run_id, mode=mysql["mode"], offsite_waiver=mysql["offsite_waiver"],
                                bound=run.inputs, receipt_sha256=guard.sha256(receipt_raw),
                                armed_from=receipt.get("armed_from"), switch_at=run.record["switch_at"],
                                fpm_drain=self._freeze_drain(self._journal_state()), checks=run.checks,
                                written_at=int(self.clock()))
        _write_raw_atomic(self.paths["evidence_report"], evidence.encode(report), 0o600)

    def _protected(self, path: Path, label: str) -> bytes:
        """A root-owned record in a root chain (guard.read_protected); missing or unsafe refuses."""
        try:
            return guard.read_protected(path)
        except FileNotFoundError:
            raise UnsafeCutover(f"The {label} ({path}) is missing") from None
        except (guard.Refused, OSError) as error:
            raise UnsafeCutover(f"The {label} ({path}) cannot be read: {error}") from None

    def _profile_contexts(self, engine: str | None = None) -> list[dict]:
        """The distinct contexts of the live wrapper profiles (paths.wrappers, design 3.8), in the order of their first
        profile's name: what admit.py gives a profile's writer (user, group and its supplementary groups, cwd, umask,
        environment) and the php and artisan it runs. A profile that does not run `<php> <app_root>/artisan` is a
        shell script: it is probed with QUEUE_PHP and the app's artisan, for its user and cwd (design 3.2 step 11).
        admit.load_profile checks each, so a root user or group refuses as admit.py would. admit, like pwd and grp,
        is imported here: `import adapter` needs no Unix account module (test_config_build)."""
        import grp
        import pwd

        import admit

        raw = self._protected(self.paths["wrappers"], "wrapper profiles")
        try:
            profiles = guard.read_json(raw).get("profiles")
        except (guard.Refused, ValueError, RecursionError) as error:
            raise UnsafeCutover(f"The wrapper profiles cannot be read: {error}") from None
        _require(isinstance(profiles, dict), "The wrapper profiles are not {\"version\": 1, \"profiles\": {...}}")
        names = sorted(profiles)
        _require(bool(names), "The wrapper profiles name no profile to probe")
        artisan = f"{self.config['app_root']}/artisan"
        contexts: dict[str, dict] = {}
        for name in names:
            try:
                profile = admit.load_profile(name, raw)
                if engine is not None:
                    _require(profile["engine"] == engine, f"The wrapper profile {name} must admit {engine}")
                account = pwd.getpwnam(profile["user"])
                group = grp.getgrnam(profile["group"]).gr_gid
            except (guard.Refused, KeyError, ValueError, RecursionError) as error:
                raise UnsafeCutover(f"The wrapper profile {name} cannot be probed: {error}") from None
            argv = profile["argv"]
            script = not (len(argv) >= 2 and argv[1] == artisan)
            php = QUEUE_PHP if script else argv[0]
            key = json.dumps([php, profile["user"], profile["group"], profile["cwd"], profile["umask"],
                              sorted(profile["env"].items())])
            context = contexts.setdefault(key, {
                "profiles": [], "scripts": [], "argv": [php, artisan, "cutover:smoke", "--engine-only", "--json"],
                "user": profile["user"], "group": profile["group"], "uid": account.pw_uid, "gid": group,
                "groups": os.getgrouplist(account.pw_name, group), "cwd": profile["cwd"], "umask": profile["umask"],
                "environment": admit.environment(profile, account), "env": sorted(profile["env"])})
            context["profiles"].append(name)
            if script:
                context["scripts"].append(name)
        return list(contexts.values())

    def _profile_probe(self, context: dict, expected: dict, deadline: float) -> dict:
        """`cutover:smoke --engine-only --json` in one profile context: the route its processes would read now."""
        what = (f"The engine probe of profiles {', '.join(context['profiles'])} ({context['user']}:{context['group']}, "
                f"umask {context['umask']}, cwd {context['cwd']})")
        try:
            code, output = self._execute(context["argv"], deadline, environment=context["environment"],
                                         cwd=context["cwd"], user=context["uid"], group=context["gid"],
                                         extra_groups=context["groups"], umask=int(context["umask"], 8))
        except OSError as error:
            raise UnsafeCutover(f"{what} could not start: {os.strerror(error.errno) if error.errno else error}") from None
        values = evidence.engine_check(output, code, {"user": context["user"], "group": context["group"],
                                                      "cwd": os.path.realpath(context["cwd"]),
                                                      "umask": context["umask"]}, expected, what)
        return {"profiles": context["profiles"], "scripts": context["scripts"], "user": context["user"],
                "group": context["group"], "cwd": context["cwd"], "umask": context["umask"], "env": context["env"],
                **values, "ok": True}

    def _fastcgi_probe(self, expected: dict, deadline: float) -> dict:
        """fpm-engine.php through FPM's own socket (design 5.3), once the socket of step 9's FPM listens (bounded by
        FPM_SOCKET_SECONDS: step 9 does not wait for it)."""
        socket_path = self.config["fpm"]["socket"]
        limit = min(deadline, self.clock() + FPM_SOCKET_SECONDS)
        not_listening = f"The FPM socket {socket_path} is still not listening before the step 9 deadline"
        while True:
            try:
                if fastcgi.listening(socket_path, timeout=min(2.0, self._remaining(limit, not_listening))):
                    break
            except fastcgi.FastCgiError as error:
                raise UnsafeCutover(f"The FPM socket {socket_path} cannot be checked: {error}") from None
            time.sleep(min(POLL_SECONDS, self._remaining(limit, not_listening)))
        refusal = "The FastCGI engine probe reached its deadline"
        try:
            report = fastcgi.engine_probe(socket_path, self.config["app_root"],
                                          timeout=min(30.0, self._remaining(deadline, refusal)))
        except fastcgi.FastCgiError as error:
            raise UnsafeCutover(f"The FastCGI probe of FPM failed: {error}") from None
        checked = evidence.fastcgi_check(report, expected, socket_path)
        self._remaining(deadline, refusal)
        return checked

    def _smoke_commands(self) -> list[tuple]:
        """commands.smoke_readonly checked before anything runs: each `runuser -u <user> ...` with the web user or the
        app owner, never root (no runuser, or runuser to root, would run application PHP as root), running
        `cutover:smoke --read-only --json` and nothing that writes; together exactly once as each of the two users
        (design 3.2 step 11). Returns (argv, user, group or None) for each."""
        import grp
        import pwd

        users = (self.config["web_user"], self.config["app_owner"])
        try:
            identities = {pwd.getpwnam(user).pw_uid for user in users}
        except KeyError:
            raise UnsafeCutover("Both smoke_readonly accounts must exist") from None
        _require(len(identities) == 2 and 0 not in identities,
                 "smoke_readonly requires two distinct non-root users")
        app = self.config["app_root"]
        php = config_build.PHP
        _require(guard.matches(SHELL_PATH, app) and guard.matches(SHELL_PATH, php),
                 "The read-only smoke requires plain absolute app and PHP paths")
        found = []
        for argv in self.config["commands"]["smoke_readonly"]:
            _require(len(argv) > 3 and argv[0] == config_build.RUNUSER and argv[1] == "-u" and argv[2] in users,
                     f"Each smoke_readonly command must start with {config_build.RUNUSER} -u {users[0]} or -u "
                     f"{users[1]}: application PHP never runs as root")
            args = argv[3:]
            group = None
            if len(args) >= 2 and args[0] == "-g" and _account(args[1]):
                group, args = args[1], args[2:]
                try:
                    group_id = grp.getgrnam(group).gr_gid
                except KeyError:
                    raise UnsafeCutover("The read-only smoke group does not exist") from None
                _require(group_id != 0, "The read-only smoke requires a non-root group")
            flags = "cutover:smoke --read-only --json"
            direct = args == ["--", php, f"{app}/artisan", *flags.split()]
            shell = False
            if len(args) == 5 and args[:3] == ["--", "/bin/sh", "-c"] and args[4] == "repro-smoke":
                steps = args[3].split(" && ")
                shell = (len(steps) == 3 and f"cd {app}" in steps[:2]
                         and any(re.fullmatch(r"umask 0[0-7]{3}", step) for step in steps[:2])
                         and steps[2] == f"exec {php} artisan {flags}")
            _require(direct or shell,
                     "Each smoke_readonly command must run cutover:smoke --read-only --json and nothing that writes")
            found.append((argv, argv[2], group))
        _require(sorted(user for _, user, _ in found) == sorted(users),
                 f"smoke_readonly must run the read-only smoke once as {users[0]} and once as {users[1]}")
        return found

    def _readonly_smoke(self, argv: list[str], user: str, group: str | None, expected: dict, deadline: float) -> dict:
        """One smoke_readonly command (design 5.3): the marked test records read on MySQL as `user`."""
        code, output = self._execute(argv, deadline)
        return evidence.readonly_check(output, code, user, group, self.config["smoke"], expected)

    def _token_check_account(self) -> tuple[str, str, str]:
        """The check account and its password from mysql.token_check_defaults (root 0600, a [client] section with
        user and password): exactly TOKEN_CHECK_ACCOUNT. Returns (account, user, password); never printed."""
        path = Path(self.config["mysql"]["token_check_defaults"])
        try:
            raw = guard.read_protected(path)
            private = not stat.S_IMODE(path.lstat().st_mode) & 0o077
        except FileNotFoundError:
            raise UnsafeCutover(f"The token check's defaults file {path} is missing") from None
        except (guard.Refused, OSError) as error:
            raise UnsafeCutover(f"The token check's defaults file {path} is not root's: {error}") from None
        _require(private, f"The token check's defaults file {path} must be readable by root only")
        section, values = None, {}
        for line in raw.decode("ascii", "replace").splitlines():
            text = line.strip()
            if not text or text[0] in "#;":
                continue
            if text.startswith("[") and text.endswith("]"):
                section = text[1:-1].strip()
            elif section == "client" and "=" in text:
                key, _, value = text.partition("=")
                values[key.strip()] = value.strip()
        _require(values.get("user") == TOKEN_CHECK_ACCOUNT,
                 f"{path} must name the check account {TOKEN_CHECK_ACCOUNT} (accounts.sql) in its [client] section")
        _require(guard.matches(SECRET_VALUE, values.get("password")),
                 f"{path} must hold the check account's password (16 to 256 characters of A-Z a-z 0-9 . _ ~ + / = -)")
        return f"{TOKEN_CHECK_ACCOUNT}@localhost", TOKEN_CHECK_ACCOUNT, values["password"]

    def _token_command(self, command: str, cache: Path) -> list[str]:
        """`runuser -u <web user> -- /bin/sh -c 'umask 0077 && cd <app_root> && exec env APP_CONFIG_CACHE="$1" <php>
        artisan <command>' repro-token-check <cache>`: as config_build runs config:cache, on the private cache."""
        app_root = self.config["app_root"]
        _require(guard.matches(SHELL_PATH, app_root), "The app root must be one plain shell word")
        script = f'umask 0077 && cd {app_root} && exec env APP_CONFIG_CACHE="$1" {config_build.PHP} artisan {command}'
        return [config_build.RUNUSER, "-u", self.config["web_user"], "--", "/bin/sh", "-c", script, "repro-token-check",
                str(cache)]

    def _token_check(self, account: tuple[str, str, str], deadline: float) -> dict:
        """The token check (design 3.3): as the web user, a private config cache built with the check account's
        DB_USERNAME and DB_PASSWORD in the environment (Laravel keeps them over .env), then
        `db:writer-policy-check` on it: no problems, the token taken in an empty transaction (nothing is written,
        nothing reaches the binary log, E1 T1b). The folder, which holds the password, is removed afterwards, and
        any a crash left before. The command prints JSON without a --json option."""
        import pwd

        name, user, password = account
        web = self.config["web_user"]
        self._remove_token_folders()
        try:
            entry = pwd.getpwnam(web)
        except KeyError:
            raise UnsafeCutover(f"The web user {web} does not exist") from None
        folder = Path(tempfile.mkdtemp(prefix=TOKEN_CHECK_PREFIX, dir=TOKEN_CHECK_PARENT))
        try:
            # mkdtemp made it 0700 in root's /run, so nobody else can have replaced it.
            os.chown(folder, entry.pw_uid, entry.pw_gid, follow_symlinks=False)
            cache = folder / "config.php"
            code, _ = self._execute(self._token_command("config:cache", cache), deadline,
                                    environment={**HOST_ENVIRONMENT, "DB_USERNAME": user, "DB_PASSWORD": password})
            _require(code == 0, f"The token check's config cache was not built: config:cache exited {code}")
            try:
                built = live_config.from_cache(live_config.read_regular(cache, live_config.MAX_CACHE_BYTES))
                connection = built.values["database.connections"][built.values["database.default"]]
            except (OSError, ValueError, KeyError, TypeError) as error:
                raise UnsafeCutover(f"The token check's config cache cannot be read: {error}") from None
            _require(built.engine == "mysql" and isinstance(connection, dict) and connection.get("username") == user,
                     f"The token check's config cache does not name the check account {name}")
            code, output = self._execute(self._token_command("db:writer-policy-check", cache), deadline)
            return evidence.token_check(output, code, name, web)
        finally:
            self._remove_token_folder(folder)

    @staticmethod
    def _remove_token_folder(folder: Path) -> None:
        try:
            shutil.rmtree(folder)
        except OSError as error:
            if isinstance(error, FileNotFoundError) and not folder.exists():
                return
            print(f"WARNING: could not remove private token-check folder {folder} (errno {error.errno}); "
                  "inspect it as administrator", file=sys.stderr)

    @staticmethod
    def _remove_token_folders() -> None:
        """Token-check folders an interrupted check left (each may hold the check account's password)."""
        try:
            entries = list(Path(TOKEN_CHECK_PARENT).iterdir())
        except OSError as error:
            print(f"WARNING: could not inspect token-check folders in {TOKEN_CHECK_PARENT} (errno {error.errno})",
                  file=sys.stderr)
            return
        for entry in entries:
            if entry.name.startswith(TOKEN_CHECK_PREFIX) and not entry.is_symlink() and entry.is_dir():
                ProductionAdapter._remove_token_folder(entry)

    def _gate_check(self, deadline: float) -> tuple:
        """gate.check (review B2-1): the gate state as the probe account reads it and the gate account's read-only
        self-check (a login, CURRENT_USER() and SHOW GRANTS). Returns its (output, exit code, message)."""
        import gate

        refusal = "The gate check reached its deadline"
        remaining = min(self.config["command_seconds"], self._remaining(deadline, refusal))
        try:
            result = gate.check(self.config, deadline=time.monotonic() + remaining)
        except (UnsafeCutover, guard.Refused, OSError, ValueError, subprocess.SubprocessError) as error:
            raise UnsafeCutover(f"gate.check failed: {str(error)[:PROBLEM_CHARACTERS]}") from None
        self._remaining(deadline, refusal)
        return result

    def _freeze_drain(self, state: dict) -> dict:
        """The freeze's FPM drain record from the journal history (design 5.4, D07/D08), one the freeze went on
        after: part of the evidence, so Approval 2 binds how FPM was drained."""
        freezes = [entry for entry in state.get("history") or [] if isinstance(entry, dict)
                   and entry.get("action") == "freeze"]
        record = freezes[-1].get("fpm_drain") if freezes else None
        _require(isinstance(record, dict), "The freeze in the journal carries no FPM drain record (design 5.4): "
                                           "nothing shows how FPM was drained")
        try:
            _require_freeze_drain(record)
        except UnsafeCutover as error:
            raise UnsafeCutover(f"The freeze's FPM drain record is not one the freeze goes on after: {error}") from None
        return record

    def _probe(self, argv: list[str], deadline: float, purpose: str = "prepare") -> dict:
        """One MySQL probe run for prepare or evidence(): its exit-0 JSON object. Nothing listening (exit 3) is retried
        PROBE_ATTEMPTS times in all, then refused; every other exit refuses with the probe's own problems."""
        for attempt in range(1, PROBE_ATTEMPTS + 1):
            _require(self.clock() < deadline, f"The MySQL {purpose} probe reached its deadline")
            code, output = self._execute(argv, deadline)
            if code != MYSQL_UNREACHABLE:
                break
            if attempt < PROBE_ATTEMPTS and self.clock() + PROBE_RETRY_SECONDS < deadline:
                time.sleep(PROBE_RETRY_SECONDS)
        else:
            raise UnsafeCutover(f"The MySQL target is not reachable (probe exit 3, {PROBE_ATTEMPTS} attempts): {purpose} "
                                "needs a reachable, closed target")
        if code != 0:
            problems = _probe_problems(output) if code in MYSQL_REFUSALS else ""
            refused = f"The MySQL probe refused {purpose}'s reading (exit {code})"
            raise UnsafeCutover(f"{refused}: {problems}" if problems else refused)
        reading = _json_object(output)
        _require(reading is not None and reading.get("reachable") is True,
                 "The MySQL probe returned no reading of a reachable server")
        return reading

    def _history(self, run: PrepareRun) -> dict:
        """mysql_probe.py --since <backup> --since-gtid <its gtid_executed> (design 3.2 steps 4 and 10, README
        "--since contract"): no app-schema transaction since the backup, the backup's GTIDs still in the history.
        Returns the coordinate the probe read before it scanned (`until`, with gtid_executed)."""
        mysql, backup = self.config["mysql"], run.inputs["backup"]
        start = f"{backup['file']}:{backup['position']}"
        scan = self._probe(self.config["commands"]["mysql"] + ["--since", start, "--since-gtid",
                                                               backup["gtid_executed"]], run.deadline)
        _require((scan.get("server_uuid"), scan.get("database")) == (mysql["target_uuid"], mysql["database"]),
                 "The MySQL history scan read another server or database than the target")
        _require(scan.get("gate") == "closed", f"The MySQL gate reads {str(scan.get('gate'))[:20]}, not closed, in the "
                                               "history scan")
        _require(scan.get("since") == {"file": backup["file"], "position": backup["position"]},
                 "The MySQL history scan started at another coordinate than the backup's")
        until, transactions = scan.get("until"), scan.get("transactions")
        _require(_coordinate(until, BACKUP_KEYS) and isinstance(transactions, list),
                 "The MySQL history scan's output is malformed")
        if transactions:
            first = transactions[0] if isinstance(transactions[0], dict) else {}
            names = [name for name in [*(first.get("tables") or []), *(first.get("statements") or [])]
                     if isinstance(name, str)]
            raise UnsafeCutover(f"The copy changed after the verified backup: {len(transactions)} app-schema "
                                f"transaction(s) since {start}, the first {str(first.get('gtid'))[:80]} "
                                f"({', '.join(names)[:200] or 'an app-schema event'})")
        held, executed = _gtid_set(backup["gtid_executed"]), _gtid_set(until["gtid_executed"])
        _require(held is not None and executed is not None and held.issubset(executed),
                 "gtid_executed no longer holds the backup's GTIDs: the binary log history was reset")
        return until

    def _env_path(self) -> Path:
        return Path(self.config["app_root"]) / ".env"

    def _read_env(self) -> bytes:
        try:
            return envfile.read(self._env_path())
        except (envfile.Refused, OSError) as error:
            raise UnsafeCutover(f"The app's .env cannot be read: {error}") from None

    def _switch_record(self) -> dict | None:
        """This host's switch record (any run's), shape-checked; None when there is none."""
        try:
            record = guard.read_json(guard.read_protected(self.paths["switch_record"]))
        except FileNotFoundError:
            return None
        except (guard.Refused, OSError, ValueError) as error:
            raise UnsafeCutover(f"The switch record is malformed: {error}") from None
        values = record.get("values")
        _require(record.keys() == SWITCH_RECORD_KEYS and guard.matches(RUN_ID, record["run_id"])
                 and isinstance(values, dict) and values.keys() == set(switch_keys(self.config))
                 and all(value is None or isinstance(value, str) for value in values.values())
                 and is_sha256(record["env_sha256"])
                 and (record["switch_at"] is None or type(record["switch_at"]) is int),
                 "The switch record is malformed")
        return record

    def evidence(self) -> Evidence:
        """The target evidence of prepare's report (design 3.3), called by the runner after prepare and again in
        promote, which requires the two to be equal.

        The record is a pure function of the report's bytes (evidence.record): forward parity's snapshot (drill:
        S_copy), the report's SHA-256, the receipt's backup coordinate and the five results. Before it is returned,
        what the report binds is read again, changing nothing: this run and mode, the night's inputs bound once more
        by check_inputs (each re-hashed), the receipt unchanged, the freeze's FPM drain record in the journal. Then
        the live preconditions, which raise and never change the record: the MySQL probe reaches the target, reads
        the gate closed, nothing writable, no business or foreign activity, armed on this receipt; gate.check with
        the gate account's self-check (review B2-1); the live config the MySQL pin from the cache. So a MySQL that
        went down after prepare refuses promote before writes_possible (review C2-7)."""
        raw = self._protected(self.paths["evidence_report"], "evidence report") \
            if self.paths["evidence_report"].exists() or self.paths["evidence_report"].is_symlink() else None
        _require(raw is not None, "No evidence report: prepare did not finish its checks (steps 11 and 12)")
        found = evidence.record(raw)
        report = evidence.parse(raw)
        decision_deadline = self._require_report_binding(report)
        self._require_live_target(report["receipt_sha256"], decision_deadline)
        return found

    def _require_report_binding(self, report: dict) -> float:
        """What the report binds, read again (evidence(), design 3.3); refuses, never writes."""
        mysql = self.config["mysql"]
        _require(report["run_id"] == self.pinned.run_id, "The evidence report belongs to another run")
        _require((report["mode"], report["offsite_waiver"]) == (mysql["mode"], mysql["offsite_waiver"]),
                 "The evidence report was written for another mode or off-site waiver than host.json's")
        state = self._journal_state()
        started_at = state.get("started_at")
        _require(type(started_at) is int, "The cutover journal has no hold to bind the evidence report to")
        smokes = report["checks"]["readonly_smoke"]
        _require({s["user"] for s in smokes} == {self.config["web_user"], self.config["app_owner"]}
                 and report["checks"]["token_check"]["user"] == self.config["web_user"]
                 and all(s[key] == self.config["smoke"][key] for s in smokes for key in ("client_id", "shoot_id")),
                 "The evidence report does not match the configured smoke accounts or marked test records")
        fpm = report["checks"]["fastcgi"]
        _require((fpm["socket"], fpm["database"], fpm["server_uuid"], fpm["route_sha256"])
                 == (self.config["fpm"]["socket"], mysql["database"], mysql["target_uuid"],
                     self.policy["mysql_live_config_sha256"]),
                 "The report does not match the configured FPM socket, MySQL target and route pin")
        fields = ("profiles", "scripts", "user", "group", "cwd", "umask", "env")
        expected = self._profile_contexts()
        recorded = report["checks"]["profile_probes"]
        _require(len(recorded) == len(expected)
                 and all(all(r[k] == c[k] for k in fields) for r, c in zip(recorded, expected)),
                 "The report does not cover the current wrapper profile contexts")
        bound = check_inputs(self.config, self.pinned, self.policy, started_at,
                             evidence.manifest(report, self.pinned.run_id))
        _require((bound["snapshot_sha256"], bound["frozen_snapshot_sha256"], bound["backup"])
                 == (report["snapshot_sha256"], report["frozen_snapshot_sha256"], report["backup"]),
                 "The night's inputs no longer bind the snapshots and backup coordinate of the evidence report")
        receipt_raw = self._protected(self.paths["receipt"], "receipt")
        _require(guard.sha256(receipt_raw) == report["receipt_sha256"],
                 "The receipt changed after prepare wrote the evidence report")
        _require(self._freeze_drain(state) == report["fpm_drain"],
                 "The evidence report's FPM drain record is not the freeze's in the journal")
        return started_at + Runner.DECISION_SECONDS

    def _require_live_target(self, receipt_sha256: str, decision_deadline: float) -> None:
        """evidence()'s live preconditions (design 3.3): the MySQL reading and gate.check, then the live config."""
        mysql = self.config["mysql"]
        deadline = min(decision_deadline, self.clock() + self.config["command_seconds"])
        reading = self._probe(self.config["commands"]["mysql"], deadline, "evidence()")
        evidence.mysql_check(reading, self._gate_check(deadline), mysql, receipt_sha256)
        live = self._live_config()
        pin = self.policy["mysql_live_config_sha256"]
        _require(live.engine == "mysql" and live.source == live_config.CACHE and live.live_config_sha256 == pin,
                 f"The live config is not the MySQL pin from the cache (engine {live.engine}, source {live.source})")
        self._remaining(deadline, "The live evidence check reached its deadline")

    def check_evidence(self, night, *, write: bool = False) -> dict:
        """`cli.py check-evidence [--write]` (design 3.2, 3.10), before the one-shot prepare: in a frozen run that
        never opened MySQL for writes, build the evidence inputs manifest from the night's input directory
        (night_manifest), bind it with check_inputs exactly as prepare's step 2 will, and run gate.check with the
        gate account's self-check (review B2-1). With `write`, store the manifest at paths.evidence_inputs (root
        0600) and nothing else; a refusal writes nothing. Returns what the CLI prints: the manifest, the bindings
        and gate.check's output."""
        state = self._journal_state()
        _require(state.get("phase") == "frozen" and state.get("writes_possible") is False,
                 "check-evidence runs only in a frozen run in which MySQL was never opened for writes")
        started_at = state.get("started_at")
        _require(type(started_at) is int, "The cutover journal has no hold to bind the evidence inputs to")
        deadline = started_at + Runner.DECISION_SECONDS
        refusal = "The evidence manifest check reached its deadline"
        self._remaining(deadline, refusal)
        manifest = night_manifest(night, self.pinned.run_id, self.config["mysql"]["mode"])
        bound = check_inputs(self.config, self.pinned, self.policy, started_at, manifest)
        output, code, message = self._gate_check(deadline)
        evidence.gate_result((output, code, message))
        self._remaining(deadline, refusal)
        if write:
            _write_atomic(self.paths["evidence_inputs"], manifest, 0o600)
        return {"version": 1, "manifest": manifest, "bound": bound, "gate": output, "written": write}

    def seal_mysql(self, lease: Lease, deadline: int) -> None:
        raise UnsafeCutover(NOT_SUPPORTED)

    # seal_sqlite (design 3.5): the rollback's undo of any part of prepare, then SQLite unsealed.

    def seal_sqlite(self, lease: Lease, deadline: int) -> None:
        """Undo whatever part of prepare ran, then unseal SQLite (design 3.5 steps 1 to 9, SEAL_SQLITE_STEPS).

        Called by every rollback (abort, the watchdog at 0:45, an expiry timer) and retried on every tick until it
        passes. Whether prepare never ran, ran partly or finished, and wherever an earlier seal_sqlite stopped, each
        step redoes or keeps its part from what is on disk. Everything that can refuse comes before step 8: SQLite
        is unsealed only once the .env and the live config read SQLite on the pin (never under a MySQL .env, review
        D10R-1). The receipt stays armed until the SQLite reopen (critic RC-L10); the switch record and the sealed
        hash stay until retire.
        """
        run = SealRun(lease, deadline)
        for number, name in SEAL_SQLITE_STEPS:
            lease.require_current()
            _require(self.clock() < deadline,
                     f"seal_sqlite ran out of time before step {number} ({name.replace('_', ' ')})")
            getattr(self, f"_seal_sqlite_{name}")(run)

    def _seal_sqlite_writers_drained(self, run: SealRun) -> None:
        """Step 1."""
        run.lease.require_writers_drained()

    def _seal_sqlite_gate(self, run: SealRun) -> None:
        """Step 2: the gate closed and the app sessions ended (gate.close_gate, design 2.3). A server that is not
        listening cannot be written; the close is skipped, and recorded, only while the journal says no MySQL
        write was ever possible. Any other refusal stops the rollback before any undo, for the next tick."""
        import gate

        try:
            run.gate, run.gate_close_skipped = gate.close_gate(self.config), False
        except gate.GateUnreachable:
            _require(self._writes_impossible(), "The MySQL target is unreachable while MySQL writes may be possible: "
                                                "the gate close is not skipped, and the rollback stops")
            run.gate, run.gate_close_skipped = None, True

    def _seal_sqlite_env(self, run: SealRun) -> None:
        """Step 3: this run's switch record restores each switch key's SQLite value in .env, through envfile, and
        keeps every other line (the night flags included); then the file is the SQLite .env byte for byte. Any run's
        record also clears what an interrupted replacement left (review D10R-5) and needs a .env it can be checked
        against (review D10V-1). Another run's record is never used. Without a record of this run nothing of this
        run was switched: a .env or a live config that names MySQL was switched by hand, and nothing records the
        SQLite values, so the rollback stops and the site stays held."""
        record, path = self._switch_record(), self._env_path()
        try:
            if record is not None:
                envfile.remove_leftovers(path)
            env = envfile.read(path)
        except FileNotFoundError:
            env = None
        except (envfile.Refused, OSError) as error:
            raise UnsafeCutover(f"The app's .env cannot be read to undo the switch: {error}") from None
        if record is not None:
            _require(env is not None, "The app's .env cannot be read to undo the switch: it is missing")
            try:
                envfile.values(env, switch_keys(self.config))
            except envfile.Refused as error:
                raise UnsafeCutover(f"The .env cannot be checked against the switch record: {error}") from None
        if record is None or record["run_id"] != self.pinned.run_id:
            self._require_no_switch(env)
            return
        try:
            restored = envfile.assign(env, record["values"])
        except envfile.Refused as error:
            raise UnsafeCutover(f"The .env cannot be checked against the switch record: {error}") from None
        _require(envfile.digest(restored, self.config["mysql"]["night_flags"]) == record["env_sha256"],
                 "The switch record does not match the .env (its digest differs): nothing is restored, the rollback "
                 "stops before it unseals SQLite, and the site stays held")
        if restored != env:
            try:
                envfile.replace(path, restored, expected=env)
            except envfile.Refused as error:
                raise UnsafeCutover(f"The .env was not restored: {error}") from None
            run.env_restored = True
        run.record = record

    def _require_no_switch(self, env: bytes | None) -> None:
        """No switch of this run to undo: neither the .env (when there is one) nor the live config may name MySQL."""
        try:
            named = None if env is None else live_config.env_values(env, ("DB_CONNECTION",)).get("DB_CONNECTION")
        except live_config.Unmeasurable as error:
            raise UnsafeCutover(f"The app's .env cannot be read to undo the switch: {error}") from None
        engine = self._live_config().engine
        _require(named in (None, "sqlite") and engine == "sqlite",
                 f"The .env reads DB_CONNECTION={str(named)[:20]} and the live config {engine[:20]}, and there is no "
                 "switch record of this run: it was switched by hand, and nothing records the SQLite values. The "
                 "rollback stops before it unseals SQLite, and the site stays held")

    def _sqlite_pin(self) -> str:
        pin = self.policy.get("sqlite_live_config_sha256")
        _require(is_sha256(pin), "The policy pins no SQLite live config hash (sqlite_live_config_sha256)")
        return pin

    def _seal_sqlite_config(self, run: SealRun) -> None:
        """Step 4: the SQLite config cache built to the SQLite pin (config_build, atomic) when the .env was
        restored, the live config is the .env fallback (design 4.7), or this run's switch left a cache that is not
        the SQLite pin. Without a switch of this run a parsed SQLite cache is left as it is: the publication
        measures it, and the guard admits nobody on a cache other than the pin."""
        pin, live = self._sqlite_pin(), self._live_config()
        if run.env_restored:
            why = "the .env was restored"
        elif live.source != live_config.CACHE:
            why = f"the live config is the .env fallback: {live.problem}"
        elif run.record is not None and live.live_config_sha256 != pin:
            why = "the live config is not the SQLite pin"
        else:
            return
        try:
            config_build.build(self.config, expected_engine="sqlite", expected_sha256=pin, deadline=run.deadline)
        except config_build.BuildFailed as error:
            raise UnsafeCutover(f"The SQLite config cache was not rebuilt ({why}): {error}. The rollback stops before "
                                "it unseals SQLite") from None
        run.config_built = True

    def _seal_sqlite_fpm(self, run: SealRun) -> None:
        """Step 5: `try-reload-or-restart` (never a plain reload): a running FPM reloads onto the SQLite config, a
        stopped one (the rollback's drain stopped it) stays stopped until the reopen starts it. Only where a switch
        of this run may have reached FPM, or this undo changed the .env or the cache."""
        if run.record is not None or run.env_restored or run.config_built:
            self._run(self.config["commands"]["fpm_reload"], run.deadline)

    def _seal_sqlite_verify(self, run: SealRun) -> None:
        """Step 6: the live config is the SQLite engine from a parsed cache (on the SQLite pin wherever this undo
        built it or this run switched), the .env reads SQLite (this run's record: every switch key its recorded
        value), and the identity probe's config_sha256 is the pin's."""
        live = self._live_config()
        _require(live.engine == "sqlite" and live.source == live_config.CACHE,
                 f"The live config is {live.engine[:20]} from the {live.source}, not SQLite from the config cache; the "
                 "rollback stops before it unseals SQLite")
        if run.record is not None or run.config_built:
            _require(live.live_config_sha256 == self._sqlite_pin(),
                     "The live config is not the SQLite pin; the rollback stops before it unseals SQLite")
        try:
            env = envfile.read(self._env_path())
        except FileNotFoundError:
            env = None
        except (envfile.Refused, OSError) as error:
            raise UnsafeCutover(f"The app's .env cannot be read to undo the switch: {error}") from None
        if run.record is not None:
            try:
                current = None if env is None else envfile.values(env, switch_keys(self.config))
            except envfile.Refused as error:
                raise UnsafeCutover(f"The .env cannot be checked against the switch record: {error}") from None
            _require(current == run.record["values"], "The .env does not hold the switch record's SQLite values after "
                                                      "the restore; the rollback stops before it unseals SQLite")
        else:
            self._require_no_switch(env)
        _require(self.identity().config_sha256 == self.pinned.config_sha256,
                 "The identity probe's configuration digest is not the pin's; the rollback stops before it unseals "
                 "SQLite")

    def _sealed_sha256(self) -> tuple[str | None, str | None]:
        """The sealed hash this run's freeze recorded for this hold and database, or None and why there is none."""
        try:
            record = guard.read_json(guard.read_protected(self.paths["sealed_hash"]))
        except FileNotFoundError:
            return None, "no sealed hash of this run and hold: the rollback came before a freeze"
        except (guard.Refused, OSError, ValueError) as error:
            return None, f"the sealed hash record cannot be read: {str(error)[:PROBLEM_CHARACTERS]}"
        binding = self._hold_binding()
        if not (record.keys() == SEALED_HASH_KEYS and is_sha256(record["sha256"])):
            return None, "the sealed hash record is malformed"
        if (record["run_id"], record["hold_id"], record["started_at"], record["database"]) != (
                self.pinned.run_id, binding["hold_id"], binding["started_at"], str(self.database)):
            return None, "no sealed hash of this run and hold: the record names another run, hold or database"
        return record["sha256"], None

    def _seal_sqlite_unseal_proof(self, run: SealRun) -> None:
        """Step 7: the drill's proof, the sealed file's SHA-256 now against the freeze's sealed hash, with the gate
        close (or its skip, which the SQLite reopen reads, design 3.7) in paths.unseal_proof (root 0600). A
        mismatch is recorded and reported on stderr; it never stops the rollback, since SQLite is still the only
        copy with every confirmed write."""
        sealed, problem = self._sealed_sha256()
        try:
            now = sqlite_seal.file_sha256(self.database)
        except OSError as error:
            raise UnsafeCutover(f"The SQLite file cannot be hashed for the unseal proof: {os.strerror(error.errno)}") \
                from None
        unchanged = None if sealed is None else now == sealed
        if unchanged is False:
            problem = "the sealed SQLite file changed after the freeze hashed it"
        _write_atomic(self.paths["unseal_proof"], {
            "version": 1, "run_id": self.pinned.run_id, **self._hold_binding(), "database": str(self.database),
            "sha256": now, "sealed_sha256": sealed, "unchanged": unchanged, "problem": problem, "gate": run.gate,
            "gate_close_skipped": run.gate_close_skipped, "at": int(self.clock()),
        }, 0o600)
        if unchanged is False:
            print(f"WARNING: the unseal proof found that the sealed SQLite file changed after the freeze hashed it "
                  f"(sealed {sealed[:12]}, now {now[:12]}); the rollback goes on", file=sys.stderr)

    def _seal_sqlite_unseal(self, run: SealRun) -> None:
        """Step 8: SQLite's recorded modes restored (sqlite_seal.unseal)."""
        sqlite_seal.unseal(self.database, self.paths["sqlite_seal_record"])

    def _seal_sqlite_markers(self, run: SealRun) -> None:
        """Step 9: the ingress marker and the scheduler release removed, and any token-check folder a killed step 11
        left (it may hold the check account's password; a rolled-back run has no next check to remove it)."""
        _remove(self.paths["ingress_open"])
        _remove(self.paths["scheduler_release"])
        self._remove_token_folders()

    # Authority and reopening.

    def _machine_sha(self) -> str:
        return guard.sha256(self.paths["machine_id"].read_bytes())

    def publish_authority(self, lease: Lease, engine: str, candidate: bytes, deadline: int) -> None:
        """The boot-bound proof for the candidate journal bytes, for either engine (design 3.4).

        The route must be sealed for `engine` and the live config a parsed cache; the route hash, the
        database identity, release and artifact are measured now, so guard.verify compares measurements
        with the pins. Only seal_mysql (which this adapter refuses) leads the runner to a MySQL publication.
        """
        lease.require_writers_drained()
        observed, mysql, live = self._observed()
        if engine not in ENGINE_NAMES or not observed.sealed(engine) or self.clock() >= deadline:
            raise UnsafeCutover(f"{ENGINE_NAMES.get(engine, 'The')} authority is not sealed for publication")
        measured = self._measured_authority(observed, mysql, live)
        writers = self.processes(self.config["writer_processes"] + self.config["scheduler_processes"])
        _write_atomic(self.paths["evidence"], {
            "version": 1, "boot_id": self.boot_id(), "policy_sha256": guard.sha256(self.policy_raw),
            "journal_sha256": guard.sha256(candidate), "engine": engine, **measured,
            "competing_writer_detected": bool(writers),
        }, 0o600)

    def hold_deploys(self) -> None:
        """Publish the deployment fence under both deploy locks.

        The guard holds deploys for a planned cutover, but Deploy-App checks only
        the marker file and never calls the guard. So the marker is what holds
        them, from `init` until the run is retired.
        """
        deploy_marker.publish(self.paths["deploy_marker"], self.locks, self.pinned.run_id,
                              self.config["command_seconds"], owner=self.owner)

    def host_authority(self, engine: str) -> "guard.HostAuthority":
        """Read-only probe for guard.refresh_engine_evidence, for a committed planned or terminal journal.

        Deploys count as fenced only when the marker that Deploy-App checks exists;
        the journal's own deploy refusal is not enforced by any deploy script.
        Every value is measured, as in publish_authority: the observed engine, its
        database identity (the source file, or the MySQL server UUID the probe
        read), the live route hash (a fallback config refuses), release and artifact.
        """
        observed, mysql, live = self._observed()
        measured = self._measured_authority(observed, mysql, live)
        return guard.HostAuthority(
            engine=observed.engine, **measured,
            competing_writer_detected=bool(self.processes(self.config["writer_processes"]
                                                         + self.config["scheduler_processes"])),
            admissions_open=observed.admissions_open, workers_stopped=observed.workers_stopped,
            deployment_fenced=observed.deployment_fenced,
            sqlite_writable=observed.sqlite_writable, mysql_writable=observed.mysql_writable,
        )

    def refresh(self) -> None:
        """Publish current-boot authority evidence for a committed planned or terminal journal.

        The guard revokes the current proof before it measures the host. On a
        live host that would hold the scheduler and every restarted worker, so
        refresh refuses while the ingress open marker exists. The check and the
        fence run inside the guard's controller and writer locks, before any
        revocation, so a reopen cannot finish between them; the early check only
        gives a clear message without taking the locks.
        """
        def held_host() -> None:
            self._refuse_open_ingress()
            state = self.journal.read()
            engine = guard.authority(self.policy, state or {}, "web-writer")
            try:
                self.hold_deploys()
                _remove(self.paths["scheduler_release"])
                self._pause_queues(self.clock() + self.config["command_seconds"])
                if engine == "mysql":
                    self._install_sqlite_wrappers()
            except BaseException:
                # The guard has both locks but does not invalidate evidence until
                # this precondition returns. An incomplete hold cannot reuse it.
                _remove(self.paths["evidence"])
                _remove(self.paths["activation"])
                raise

        self._refuse_open_ingress()
        # The guard checks the evidence directory's chain before it publishes into it.
        _runtime_directory(self.paths["evidence"].parent)
        guard.refresh_engine_evidence(self.host_authority, precondition=held_host)

    def _refuse_open_ingress(self) -> None:
        if self.paths["ingress_open"].exists() or self.paths["ingress_open"].is_symlink():
            raise UnsafeCutover("Ingress is open. This operation requires a held host")

    def _pause_queues(self, deadline: float) -> None:
        for queue in self.config["queues"]:
            self._run(self.config["commands"]["queue_pause"] + [queue], deadline)

    def _install_sqlite_wrappers(self) -> None:
        raw = self._protected(self.paths["wrappers_sqlite"], "SQLite wrapper profiles")
        _write_raw_atomic(self.paths["wrappers"], raw, 0o644)

    def _close_reopened(self) -> None:
        """Withdraw admissions first and attempt every queue pause and group stop."""
        # A restarted or manually invoked worker can acquire a fresh shared lease
        # from this boot's authority proof even when the ingress and scheduler are held.
        # Revoke it before taking down the running groups. Refresh will remeasure it.
        for name in ("evidence", "ingress_open", "scheduler_release", "activation"):
            _remove(self.paths[name])
        commands = self.config["commands"]
        errors = []
        for argv in ([commands["queue_pause"] + [q] for q in self.config["queues"]] + commands["stop_workers"]):
            try:
                self._run(argv)
            except (UnsafeCutover, OSError) as error:
                errors.append(str(error))
        self._wait_absent(self.config["writer_processes"] + self.config["scheduler_processes"],
                          self.clock() + self.config["command_seconds"], "Writers are still running after rehold")
        if errors:
            raise UnsafeCutover("Could not hold every writer: " + "; ".join(errors))

    def rehold(self) -> None:
        """Hold a committed authority without changing the journal, gate or database."""
        with self.journal.acquire() as (state, lease):
            engine = guard.authority(self.policy, state or {}, "web-writer")
            self._close_reopened()
            self.journal.drain_writers(lease, self.config["command_seconds"])
            if engine == "mysql":
                self._install_sqlite_wrappers()

    def _verify_sqlite_authority(self) -> tuple[bytes, bytes]:
        journal = self.journal.path.read_bytes()
        proof = self._protected(self.paths["evidence"], "authority proof")
        try:
            guard.verify(self.policy_raw, journal, proof, machine_sha=self._machine_sha(),
                         boot_id=self.boot_id(), action="web-writer", expected_engine="sqlite")
        except guard.Refused as error:
            raise UnsafeCutover(str(error)) from error
        return journal, proof

    def _sqlite_reopen_checks(self, state: dict, *, reopened: bool = False) -> dict:
        observed, mysql, live = self._observed()
        if reopened:
            _require(observed.sqlite_live(), "SQLite admissions could not be verified after reopening")
        _require(observed.engine == "sqlite" and observed.sqlite_writable and not observed.mysql_writable
                 and not observed.mysql_business_activity, "SQLite is no longer the only writable engine; writers stay held")
        _require(observed.deployment_fenced, "The deploy fence is missing")
        pin = self._sqlite_pin()
        _require(live.source == live_config.CACHE and live.live_config_sha256 == pin,
                 "The live route is not the pinned SQLite config cache")
        _require(self._protected(self.paths["wrappers"], "installed wrappers")
                 == self._protected(self.paths["wrappers_sqlite"], "SQLite wrapper profiles"),
                 "The installed wrappers do not match the SQLite wrapper profiles")
        if state["phase"] == "sqlite_authoritative":
            proof = guard.read_json(self._protected(self.paths["unseal_proof"], "SQLite unseal proof"))
            _require(proof.keys() == UNSEAL_PROOF_KEYS and proof["run_id"] == self.pinned.run_id
                     and proof["database"] == str(self.database) and type(proof["gate_close_skipped"]) is bool
                     and all(proof.get(k) == v for k, v in self._hold_binding().items()),
                     "The SQLite unseal proof does not belong to this run and hold")
            if proof["gate_close_skipped"]:
                _require(mysql.get("reachable") is True and mysql.get("gate") == "closed"
                         and mysql.get("server_uuid") == self.config["mysql"]["target_uuid"],
                         "The skipped gate close needs a reachable, closed MySQL target")
        return {"engine": "sqlite", "database": str(self.database), "server_uuid": None, "route_sha256": pin}

    def _retire_mysql_receipt(self) -> None:
        receipt = self.paths["receipt"]
        retired = receipt.with_name("mysql-receipt.retired.json")
        armed = receipt.exists() or receipt.is_symlink()
        if not armed and not (retired.exists() or retired.is_symlink()):
            return
        raw = self._protected(receipt if armed else retired, "MySQL receipt")
        record = guard.read_json(raw)
        if record.get("run_id") != self.pinned.run_id:
            return
        if armed:
            # The last scan is against the still-armed receipt on the intended server.
            reading = self._mysql()
            _require(reading.get("reachable") is True and reading.get("gate") == "closed"
                     and reading.get("server_uuid") == self.pinned.target_uuid
                     and reading.get("receipt") == guard.sha256(raw)
                     and not reading["business_activity"] and not reading["writable"],
                     "The final MySQL check does not confirm a closed, quiet target with this receipt")
            os.replace(receipt, retired)
            _sync_directory(receipt.parent)
        # A killed reopen can leave the old latch after the rename. It no longer
        # participates in the probe, which binds it to the active receipt.
        activity = self.paths["activity"]
        if activity.exists() or activity.is_symlink():
            latch = guard.read_json(self._protected(activity, "MySQL activity latch"))
            if latch.get("run_id") == self.pinned.run_id and latch.get("receipt_sha256") == guard.sha256(raw):
                _remove(activity)

    def _activation_record(self, journal: bytes, proof: bytes) -> dict:
        return {"version": 1, "boot_id": self.boot_id(), "policy_sha256": guard.sha256(self.policy_raw),
                "journal_sha256": guard.sha256(journal), "authority_sha256": guard.sha256(proof),
                "engine": "sqlite", "application_verified": True, "reconciliation_verified": True}

    def release_scheduler(self) -> None:
        """Release only a fully reopened, still-verified SQLite host, including replay pass two."""
        with self.journal.acquire() as (state, _):
            # A repeat invocation must also leave the scheduler held on a failed check.
            _remove(self.paths["scheduler_release"])
            journal, proof = self._verify_sqlite_authority()
            activation = guard.read_json(self._protected(self.paths["activation"], "activation receipt"))
            _require(activation == self._activation_record(journal, proof),
                     "The SQLite reopen has not completed on this boot")
            self._sqlite_reopen_checks(state, reopened=True)
            _write_atomic(self.paths["scheduler_release"], {"version": 1, "engine": "sqlite"}, 0o600)

    def reopen(self, *, hold_scheduler: bool = False) -> None:
        """Open verified SQLite admissions and replay captured messages under the controller lock."""
        with self.journal.acquire() as (state, lease):
            journal, proof = self._verify_sqlite_authority()
            self._refuse_open_ingress()
            _remove(self.paths["scheduler_release"])
            _remove(self.paths["activation"])
            deadline = self.clock() + self.config["command_seconds"]
            if self.processes(self.config["writer_processes"]):
                for argv in self.config["commands"]["stop_workers"]:
                    self._run(argv, deadline)
                self._wait_absent(self.config["writer_processes"], deadline,
                                  "Workers started by an earlier reopen are still running")
            self.journal.drain_writers(lease, self.config["command_seconds"])
            expected = self._sqlite_reopen_checks(state)
            for context in self._profile_contexts("sqlite"):
                self._profile_probe(context, expected, deadline)
            self._retire_mysql_receipt()
            for path in self.config["studio_pause_files"]:
                _remove(Path(path))
            self.journal.release_writers(lease)
            commands = self.config["commands"]
            try:
                self._run(commands["telnyx_replay"], deadline)
                for queue in self.config["queues"]:
                    self._run(commands["queue_resume"] + [queue], deadline)
                for argv in commands["start_workers"]:
                    self._run(argv, deadline)
                self._fastcgi_probe(expected, deadline)
                _write_atomic(self.paths["ingress_open"], {"version": 1, "engine": "sqlite"}, 0o644)
                self._run(commands["telnyx_replay"], deadline)
                self._sqlite_reopen_checks(state, reopened=True)
                _write_atomic(self.paths["activation"], self._activation_record(journal, proof), 0o600)
                if not hold_scheduler:
                    _write_atomic(self.paths["scheduler_release"], {"version": 1, "engine": "sqlite"}, 0o600)
            except BaseException:
                self._close_reopened()
                raise
