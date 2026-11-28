"""Reopen committed MySQL authority while the controller holds the journal lock."""
from __future__ import annotations

import stat
import time
from pathlib import Path

import adapter as host
import admit
import evidence
import fastcgi
import fpm_drain
import gate
import live_config
import mysql_probe
from adapter import _remove, _require, _runtime_directory, _write_atomic, _write_raw_atomic, guard
from runner import ControllerBusy, UnsafeCutover

ADMIT_COMMAND = ["/usr/bin/python3", "-I", "-S", "/opt/repro-cutover-fence/production/admit.py"]


def profiles(adapter) -> bytes:
    raw = {engine: adapter._protected(adapter.paths[f"wrappers_{engine}"], f"{engine} profiles")
           for engine in ("sqlite", "mysql")}
    sqlite, mysql = (guard.read_json(raw[engine]) for engine in ("sqlite", "mysql"))
    _require(sqlite.keys() == {"version", "profiles"} and type(sqlite["version"]) is int and sqlite["version"] == 1
             and isinstance(sqlite["profiles"], dict) and {"cutover-smoke", "telnyx-replay"} <= sqlite["profiles"].keys(),
             "Incomplete SQLite wrapper inventory")
    for name in sqlite["profiles"]:
        original = admit.load_profile(name, raw["sqlite"])
        target = admit.load_profile(name, raw["mysql"])
        _require(original["engine"] == "sqlite" and target == {**original, "engine": "mysql"},
                 f"Wrapper profiles for {name} differ beyond their engines")
        sqlite["profiles"][name]["engine"] = "mysql"
    _require(sqlite == mysql, "MySQL and SQLite wrapper inventories differ")
    app = adapter.config["app_root"]
    for name, command, args in (("cutover-smoke", "smoke_write", ["cutover:smoke", "--write", "--json"]),
                                ("telnyx-replay", "telnyx_replay", ["telnyx:replay-spool"])):
        profile = mysql["profiles"][name]
        _require(adapter.config["commands"][command] == [*ADMIT_COMMAND, name] and
                 profile["argv"] == [host.QUEUE_PHP, f"{app}/artisan", *args] and profile["cwd"] == app
                 and profile["action"] == "queue-worker" and profile["user"] == adapter.config["web_user"]
                 and profile["group"] == "www-data" and profile["env"] == {}
                 and profile["umask"] == ("0022" if name == "cutover-smoke" else "0002"),
                 f"The {name} command or writer identity differs from the approved wrapper")
    return raw["mysql"]


def live(adapter, state, *, opened: bool) -> tuple[dict, str, int]:
    mysql = adapter.config["mysql"]
    observed, reading, cached = adapter._observed()
    _require(mysql["mode"] == "cutover" and observed.engine == "mysql" and observed.admissions_open is opened
             and observed.mysql_writable and not observed.sqlite_writable and observed.deployment_fenced,
             "The held MySQL gate, ingress, SQLite file or deploy fence changed")
    _require(stat.S_ISREG(adapter.database.lstat().st_mode), "The frozen SQLite database must be a regular file")
    _require(cached.source == live_config.CACHE and cached.live_config_sha256 == adapter.policy["mysql_live_config_sha256"]
             and adapter.identity() == adapter.pinned, "The cached MySQL route or release identity differs from the pin")
    report = adapter._protected(adapter.paths["evidence_report"], "prepared report")
    _require(guard.sha256(report) == state["evidence"]["report_sha256"], "The prepared evidence report changed")
    prepared = evidence.parse(report)
    receipt = guard.sha256(adapter._protected(adapter.paths["receipt"], "MySQL receipt"))
    switch = adapter._switch_record()
    _require(switch is not None and switch["run_id"] == adapter.pinned.run_id
             and type(switch["switch_at"]) is int and switch["switch_at"] == prepared["switch_at"],
             "The switch time does not match the prepared run")
    _require(receipt == prepared["receipt_sha256"] and reading.get("receipt") == receipt
             and reading.get("reachable") is True and reading.get("gate") == "open"
             and reading.get("foreign_activity") is False and reading.get("server_uuid") == adapter.pinned.target_uuid
             and reading.get("database") == mysql["database"], "The pinned MySQL target, gate or armed receipt changed")
    expected = {"engine": "mysql", "database": mysql["database"], "server_uuid": adapter.pinned.target_uuid,
                "route_sha256": cached.live_config_sha256}
    return expected, receipt, switch["switch_at"]


def read_gtid(adapter, deadline: float) -> str:
    remaining = adapter._remaining(deadline, "The smoke GTID read reached its deadline")
    result = gate._client(adapter.config, "probe_defaults", [
        "SELECT JSON_OBJECT('uuid', @@server_uuid, 'account', CURRENT_USER(), 'gtid', @@GLOBAL.gtid_executed)"
    ], deadline=time.monotonic() + remaining)
    _require(result.returncode == 0, "The probe account could not read the smoke GTID on the target")
    try:
        record = guard.read_json(result.stdout.encode())
    except (ValueError, guard.Refused) as error:
        raise UnsafeCutover("The probe returned an invalid smoke GTID measurement") from error
    _require(record.get("uuid") == adapter.pinned.target_uuid and record.get("account") == mysql_probe.PROBE_ACCOUNT
             and isinstance(record.get("gtid"), str), "The GTID measurement came from another server or account")
    adapter._remaining(deadline, "The smoke GTID read reached its deadline")
    return record["gtid"]


def token(adapter, deadline: float) -> None:
    # Explicitly choose the installed live cache, never the rehearsal check account cache.
    cache = Path(adapter.config["app_root"]) / "bootstrap/cache/config.php"
    argv = adapter._token_command("db:writer-policy-check", cache)
    code, output = adapter._execute(argv, deadline)
    mysql = adapter.config["mysql"]
    account = mysql["switch"]["DB_USERNAME"]
    evidence.token_check(output, code, account, adapter.config["web_user"])


def activated(adapter, expected: dict, receipt: str) -> dict:
    return {"version": 1, "run_id": adapter.pinned.run_id, "server_uuid": expected["server_uuid"],
            "database": expected["database"], "receipt_sha256": receipt}


def intent_path(adapter) -> Path:
    return adapter.journal.directory / f"{adapter.pinned.run_id}.mysql-reopen-pending.json"


def intent(adapter, journal: bytes) -> dict:
    return {"version": 1, "run_id": adapter.pinned.run_id, "policy_sha256": guard.sha256(adapter.policy_raw),
            "journal_sha256": guard.sha256(journal)}


def finish_hold(adapter, lease, path: Path) -> None:
    adapter._close_reopened(engine="mysql")
    if lease.writers is None:
        adapter.journal.drain_writers(lease, adapter.config["command_seconds"])
    _remove(path)


def recover_interrupted(adapter) -> bool:
    """A dead reopen can leave ingress or workers live. The watchdog reholds only after the controller exits."""
    path = intent_path(adapter)
    if not path.exists() and not path.is_symlink():
        return False
    try:
        with adapter.journal.acquire() as (state, lease):
            if not path.exists() and not path.is_symlink():
                return False
            # Refuse to change a subsequent operator's run or a different authoritative engine.
            _require(guard.authority(adapter.policy, state or {}, "web-writer") == "mysql"
                     and guard.read_json(adapter._protected(path, "MySQL reopen intent"))
                     == intent(adapter, adapter.journal.path.read_bytes()),
                     "The interrupted MySQL reopen does not match this journal")
            finish_hold(adapter, lease, path)
            return True
    except ControllerBusy:
        return False


def reopen(adapter, *, hold_scheduler: bool) -> None:
    import mysql_reopen_smoke
    import worker_start

    if recover_interrupted(adapter):
        raise UnsafeCutover("Interrupted MySQL reopen was held. Refresh authority, then reopen")
    with adapter.journal.acquire() as (state, lease):
        _require(guard.authority(adapter.policy, state or {}, "web-writer") == "mysql"
                 and adapter.config["mysql"]["mode"] == "cutover", "MySQL reopen requires committed cutover authority")
        adapter._refuse_open_ingress()
        deadline = adapter.clock() + adapter.config["command_seconds"]
        commands = adapter.config["commands"]
        pending = intent_path(adapter)
        _require(not pending.exists() and not pending.is_symlink(), "An earlier MySQL reopen still requires a hold")
        _write_atomic(pending, intent(adapter, adapter.journal.path.read_bytes()), 0o600)
        _remove(adapter.paths["scheduler_release"])
        _remove(adapter.paths["activation"])
        try:
            if adapter.processes(adapter.config["writer_processes"]):
                for argv in commands["stop_workers"]:
                    adapter._run(argv, deadline)
                adapter._wait_absent(adapter.config["writer_processes"], deadline, "Earlier MySQL workers did not stop")
            adapter.journal.drain_writers(lease, adapter._remaining(deadline, "MySQL writers did not drain in time"))
            journal, proof = adapter._verify_authority("mysql")
            expected, receipt, switch_at = live(adapter, state, opened=False)
            timeout = min(fpm_drain.REQUEST_SECONDS, adapter._remaining(deadline, "The FPM status check timed out"))
            try:
                status = fpm_drain.read_status(adapter.config["fpm"]["status_socket"], timeout)
                prepared_pool = adapter._freeze_drain(state).get("pool")
                _require(prepared_pool is None or status.pool == prepared_pool,
                         "The PHP-FPM status socket names a different pool from the verified freeze")
            except (fastcgi.FastCgiError, fpm_drain.StatusError) as error:
                raise UnsafeCutover(f"MySQL reopen could not read PHP-FPM status: {error}") from error
            mysql_profiles = profiles(adapter)
            _write_raw_atomic(adapter.paths["wrappers"], mysql_profiles, 0o644)
            adapter.journal.release_writers(lease)
            token(adapter, deadline)
            mysql_reopen_smoke.run(adapter, expected, receipt, deadline, lambda: read_gtid(adapter, deadline))
            for path in map(Path, adapter.config["studio_pause_files"]):
                _remove(path)
                _require(not path.exists() and not path.is_symlink(), "A Studio queue is still paused")
            adapter._run(commands["telnyx_replay"], deadline)
            for queue in adapter.config["queues"]:
                adapter._run(commands["queue_resume"] + [queue], deadline)
            for argv in commands["start_workers"]:
                adapter._run(argv, deadline)
            worker_start.verify(adapter.proc, adapter.config["writer_processes"], switch_at)
            adapter._fastcgi_probe(expected, deadline)
            _require(adapter._verify_authority("mysql") == (journal, proof), "MySQL authority changed during reopening")
            live(adapter, state, opened=False)
            _require(adapter._protected(adapter.paths["wrappers"], "active profiles") == mysql_profiles,
                     "The active MySQL wrapper profiles changed before opening ingress")
            _runtime_directory(adapter.paths["ingress_open"].parent)
            _write_atomic(adapter.paths["ingress_open"], {"version": 1, "engine": "mysql"}, 0o644)
            adapter._run(commands["telnyx_replay"], deadline)
            live(adapter, state, opened=True)
            worker_start.verify(adapter.proc, adapter.config["writer_processes"], switch_at)
            _require(adapter._verify_authority("mysql") == (journal, proof), "MySQL authority changed during replay")
            adapter._remaining(deadline, "MySQL reopening reached its deadline")
            _write_atomic(adapter.paths["activated"], activated(adapter, expected, receipt), 0o600)
            _write_atomic(adapter.paths["activation"], adapter._activation_record(journal, proof, "mysql"), 0o600)
            if not hold_scheduler:
                _write_atomic(adapter.paths["scheduler_release"], {"version": 1, "engine": "mysql"}, 0o600)
            _remove(pending)
        except BaseException:
            finish_hold(adapter, lease, pending)
            raise
