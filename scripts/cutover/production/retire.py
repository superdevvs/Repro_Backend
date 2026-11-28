"""Retire a completed run under the controller lock. The pending record survives every destructive step."""

from __future__ import annotations

import hashlib
import os
import re
import stat
import tempfile
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path

import adapter as host
from adapter import guard
from runner import CONTROLLER_LOCK, WRITER_LOCK, Identity, UnsafeCutover

RETIRED = Path("/etc/repro-cutover-fence/retired.json")
ARCHIVE = Path("/var/lib/repro-cutover-archive")
KEEP = Path("/var/lib/repro-cutover-keep")
PENDING = "retire-pending.json"
NEXT_RUN = "next-run.json"
LOCKS = {CONTROLLER_LOCK, WRITER_LOCK}
KEEP_NAMES = ("switch_record", "sealed_hash", "sqlite_seal_record", "receipt")


def _record(raw):
    return guard.read_json(raw)


def exists(path):
    """A permissions error must never be interpreted as the absence of an authority record."""
    try:
        path.lstat()
    except FileNotFoundError:
        return False
    return True


def _archived_at(directory, run, base):
    guard.require(isinstance(directory, str) and isinstance(run, str) and guard.matches(host.RUN_ID, run),
                  "The retired run identifier is malformed")
    path = Path(directory)
    guard.require(path.parent == base and str(path) == directory
                  and re.fullmatch(re.escape(run) + r"-[0-9]{8}T[0-9]{6}Z", path.name) is not None,
                  "The retirement archive path does not belong to this run")
    try:
        datetime.strptime(path.name[len(run) + 1:], "%Y%m%dT%H%M%SZ")
    except ValueError:
        raise guard.Refused("The retirement archive timestamp is invalid") from None
    return path


def read_retired(retired_path: Path, state_dir: Path, *, archive_root: Path = ARCHIVE) -> dict:
    """The read-only public contract. Monitoring does not require access to the private archive."""
    def check_state():
        guard.require(not exists(state_dir / "state.json") and not exists(state_dir / PENDING),
                      "An active journal or unfinished retirement takes precedence")

    check_state()
    record = _record(guard.read_protected(retired_path))
    guard.require(record.keys() == {"version", "run_id", "engine", "archive"} and type(record["version"]) is int
                  and record["version"] == 1 and record["engine"] in ("sqlite", "mysql"), "Invalid retired authority")
    _archived_at(record["archive"], record["run_id"], archive_root)
    check_state()
    return record


@contextmanager
def retired_lease(engine, *, marker=RETIRED, state_dir=None, archive_root=ARCHIVE):
    import fcntl

    guard._require_root_installation()
    state_dir = guard.STATE if state_dir is None else state_dir
    first = read_retired(marker, state_dir, archive_root=archive_root)
    guard.require(first["engine"] == engine, "The profile engine differs from retired authority")
    with guard.open_protected(state_dir / WRITER_LOCK) as lease:
        fcntl.flock(lease, fcntl.LOCK_SH | fcntl.LOCK_NB)
        guard.require(first == read_retired(marker, state_dir, archive_root=archive_root),
                      "Retired authority changed while taking the writer lease")
        yield lease


def _private_dir(path: Path):
    guard.check_chain(path.parent, directory=True)
    if not os.path.lexists(path):
        path.mkdir(mode=0o700)
        host._sync_directory(path.parent)
    guard.check_chain(path, directory=True)
    guard.require(stat.S_IMODE(path.stat().st_mode) == 0o700, "Retirement directories must be root-private")


def _copy(path: Path, dest: Path):
    """Idempotent copy into a private verified archive. Reject special entries and differing prior copies."""
    info = path.lstat()
    if stat.S_ISDIR(info.st_mode):
        guard.check_chain(path, directory=True)
        _private_dir(dest)
        for child in sorted(path.iterdir()):
            _copy(child, dest / child.name)
        return
    if not os.path.lexists(dest):
        fd, temporary = tempfile.mkstemp(dir=dest.parent, prefix=".copy-")
        try:
            with os.fdopen(fd, "wb") as out, guard.open_protected(path) as source:
                while block := os.read(source, 1024 * 1024):
                    out.write(block)
                out.flush()
                os.fsync(out.fileno())
            os.replace(temporary, dest)
            host._sync_directory(dest.parent)
        finally:
            Path(temporary).unlink(missing_ok=True)
    guard.require(_file_hash(dest) == _file_hash(path), "Archive differs from a source record")


def _file_hash(path):
    result = hashlib.sha256()
    with guard.open_protected(path) as fd:
        while block := os.read(fd, 1024 * 1024):
            result.update(block)
    return result.digest()


def _verify_snapshot(source, archive, *, first=False):
    expected = {p.name for p in source.iterdir()} - (LOCKS | {PENDING} if first else set())
    for entry in archive.iterdir():
        if entry.name not in expected:
            guard.require(re.fullmatch(r"\.copy-[a-z0-9_]{8}", entry.name) is not None,
                          "Unexpected file in the private archive")
            guard.check_chain(entry)
            host._remove(entry)
    guard.require(expected == {p.name for p in archive.iterdir()}, "Private archive inventory differs from the journal")
    for name in expected:
        if (source / name).is_dir():
            _verify_snapshot(source / name, archive / name)


def _remove_archived(path, dest):
    if stat.S_ISDIR(path.lstat().st_mode):
        for child in sorted(path.iterdir()):
            _remove_archived(child, dest / child.name)
        path.rmdir()
        host._sync_directory(path.parent)
        return
    guard.require(_file_hash(path) == _file_hash(dest), "Unarchived journal record")
    host._remove(path)


def _pair(adapter, policy_path, identity_path, old_run):
    guard.require(bool(policy_path) == bool(identity_path), "Both next policy and next identity are required")
    if policy_path is None:
        return None
    policy_raw, identity_raw = [guard.read_protected(path) for path in (policy_path, identity_path)]
    policy, identity = _record(policy_raw), host.load_identity_config(identity_raw)
    pinned = Identity(**policy["identity"])
    guard.require(policy.keys() == {"version", "identity", "machine_id_sha256", "sqlite_live_config_sha256",
                                    "mysql_live_config_sha256"}
                  and policy["machine_id_sha256"] == guard.sha256(guard.read_protected(adapter.paths["machine_id"]))
                  and pinned.run_id == identity["run_id"] and pinned.run_id != old_run
                  and pinned.target_uuid == adapter.config["mysql"]["target_uuid"]
                  and all(host.is_sha256(policy.get(k)) for k in ("sqlite_live_config_sha256", "mysql_live_config_sha256"))
                  and identity["app_root"] == adapter.config["app_root"] and identity["sqlite_path"] == str(adapter.database)
                  and identity["mysql_datadir"] == adapter.config["mysql"]["datadir"]
                  and sorted(identity["env_denylist"]) == host.env_denylist(adapter.config),
                  "Next identity and policy must agree on a new run for this host")
    return {"policy": policy_raw.decode(), "identity_config": identity_raw.decode()}


def _activated(adapter, state):
    run = state["identity"]["run_id"]
    record = _record(guard.read_protected(adapter.paths["activated"]))
    guard.require(record.keys() == {"version", "run_id", "database", "server_uuid", "receipt_sha256"}
                  and record["version"] == 1 and record["run_id"] == run
                  and record["database"] == adapter.config["mysql"]["database"]
                  and record["server_uuid"] == state["identity"]["target_uuid"]
                  and record["receipt_sha256"] == guard.sha256(guard.read_protected(adapter.paths["receipt"])),
                  "No matching durable MySQL activation and receipt")


def _engine(adapter, state, pair):
    if state["phase"] == "recovery_required":
        guard.require(pair is not None and state.get("pending") is None and state.get("writes_possible") is True,
                      "Recovery retirement requires a reviewed next policy and a completed way-back close")
        closed_raw = guard.read_protected(adapter.paths["state"] / "way-back.json")
        closed = _record(closed_raw)
        guard.require(closed.get("run_id") == state["identity"]["run_id"] and closed.get("server_uuid") ==
                      state["identity"]["target_uuid"] and closed.get("gate") == "closed"
                      and isinstance(closed.get("coordinate"), dict)
                      and host.evidence._coordinate(closed["coordinate"], host.evidence.BACKUP_KEYS),
                      "No matching way-back close coordinate")
        installed = _record(guard.read_protected(adapter.paths["state"] / "wayback-installed.json"))
        guard.require(installed.get("run_id") == state["identity"]["run_id"] and installed.get("server_uuid") ==
                      state["identity"]["target_uuid"] and installed.get("writers_held") is True
                      and installed.get("database") == str(adapter.database)
                      and installed.get("method") in ("verified-export", "verified-prewrite")
                      and installed.get("sqlite_live_config_sha256") == adapter.policy["sqlite_live_config_sha256"]
                      and installed.get("close_sha256") == guard.sha256(closed_raw)
                      and all(host.is_sha256(installed.get(k)) for k in
                              ("source_sha256", "installed_sha256", "review_sha256", "verification_sha256"))
                      and installed["source_sha256"] == adapter.identity().source_sha256
                      and installed["installed_sha256"] == host.sqlite_seal.file_sha256(adapter.database),
                      "Way-back retirement requires the final verified SQLite installation receipt")
        observed, reading, live = adapter._observed()
        guard.require(observed.admissions_open is False and observed.workers_stopped is True
                      and observed.deployment_fenced is True and reading.get("reachable") is True
                      and reading.get("gate") == "closed" and reading.get("writable") is False
                      and reading.get("server_uuid") == state["identity"]["target_uuid"]
                      and reading.get("database") == adapter.config["mysql"]["database"]
                      and type(reading.get("gated_sessions")) is int and reading["gated_sessions"] == 0
                      and live.engine == "sqlite" and live.source == host.live_config.CACHE
                      and live.live_config_sha256 == adapter.policy["sqlite_live_config_sha256"],
                      "Way-back retirement requires the verified SQLite route, stopped writers and a closed MySQL gate")
        return "recovery"
    engine = guard.authority(adapter.policy, state, "queue-worker")
    if engine == "mysql":
        _activated(adapter, state)
    return engine


def _validate_keep(adapter, archive, run):
    records = {k: _record(guard.read_protected(archive / adapter.paths[k].name)) for k in KEEP_NAMES}
    switch, sealed, seal, receipt = (records[k] for k in KEEP_NAMES)
    guard.require(switch.keys() == host.SWITCH_RECORD_KEYS and switch["run_id"] == run and
                  switch["values"].keys() == set(host.switch_keys(adapter.config)) and
                  all(switch["values"][k] in (None, "", "''", '""') for k in adapter.config["mysql"]["switch_secrets"]),
                  "The saved SQLite switch settings are malformed or contain a secret")
    guard.require(sealed.keys() == host.SEALED_HASH_KEYS and sealed["run_id"] == run
                  and sealed["database"] == str(adapter.database) and host.is_sha256(sealed["sha256"])
                  and seal.keys() == {"version", "database", "modes"} and seal["database"] == str(adapter.database)
                  and isinstance(seal["modes"], dict)
                  and all(k in {str(p) for p in host.sqlite_seal.companions(adapter.database)}
                          and type(v) is int and 0 <= v <= 0o7777 for k, v in seal["modes"].items()),
                  "The saved SQLite hash or mode record is malformed")
    guard.require(receipt.keys() == {"version", "run_id", "server_uuid", "database", "backup", "armed_from"}
                  and receipt["run_id"] == run and receipt["server_uuid"] == adapter.config["mysql"]["target_uuid"]
                  and receipt["database"] == adapter.config["mysql"]["database"]
                  and host.evidence._coordinate(receipt["backup"], host.evidence.BACKUP_KEYS)
                  and host.evidence._coordinate(receipt["armed_from"], host.evidence.ARMED_KEYS),
                  "The saved MySQL receipt is malformed")
    return sealed["sha256"]


def _move_frozen(database, run, expected):
    parent = database.parent
    folder = parent / ("frozen-" + run)
    if not os.path.lexists(folder):
        folder.mkdir(mode=0o700)
        host._sync_directory(parent)
    info = folder.lstat()
    guard.require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o700,
                  "The frozen archive must be a root-private directory")
    for source in host.sqlite_seal.companions(database):
        dest = folder / source.name
        if os.path.lexists(dest):
            guard.require(stat.S_ISREG(dest.lstat().st_mode) and not (dest.lstat().st_mode & 0o222)
                          and (not os.path.lexists(source) or (source == database and source.is_dir())),
                          "An unexpected file occupies the frozen archive or original path")
            continue
        if source == database or os.path.lexists(source):
            info = source.lstat()
            guard.require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and not info.st_mode & 0o222,
                          "The frozen SQLite file must be sealed before it moves")
            if source == database:
                guard.require(host.sqlite_seal.file_sha256(source) == expected, "The frozen SQLite hash changed")
            os.rename(source, dest)
            host._sync_directory(folder)
            host._sync_directory(parent)
    guard.require(host.sqlite_seal.file_sha256(folder / database.name) == expected, "The archived SQLite hash changed")
    if not os.path.lexists(database):
        database.mkdir(mode=0o700)
        host._sync_directory(parent)
    info = database.lstat()
    guard.require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o700
                  and not any(database.iterdir()), "The SQLite placeholder must be an empty root-private directory")


def retire(adapter, next_policy=None, next_identity=None):
    paths, journal = adapter.paths, adapter.journal
    marker = journal.directory / PENDING
    with journal.acquire(retiring=True) as (state, _):
        if os.path.lexists(marker):
            work = _record(guard.read_protected(marker))
            guard.require(work.keys() == {"version", "run_id", "engine", "archive", "state_sha256", "next", "archived"}
                          and work["version"] == 1 and work["engine"] in ("sqlite", "mysql", "recovery")
                          and type(work["archived"]) is bool and host.is_sha256(work["state_sha256"]),
                          "Malformed retirement progress")
            if next_policy is not None or next_identity is not None:
                guard.require(_pair(adapter, next_policy, next_identity, work["run_id"]) == work["next"],
                              "The pending retirement already binds a different policy")
        else:
            if state is None and next_policy is None and next_identity is None:
                completed = read_retired(paths["retired"], journal.directory, archive_root=paths["archive"])
                host.deploy_marker.withdraw(paths["deploy_marker"], adapter.locks, completed["run_id"],
                                            adapter.config["command_seconds"], owner=adapter.owner)
                return {**completed, "next_policy_installed": False}
            guard.require(state is not None, "No journal or interrupted retirement to retire")
            guard.require(state["identity"] == adapter.policy["identity"], "Journal and retirement policy disagree")
            run = state["identity"]["run_id"]
            guard.require(host.deploy_marker.published(paths["deploy_marker"], run), "The run's deployment fence is missing")
            pair = _pair(adapter, next_policy, next_identity, run)
            engine = _engine(adapter, state, pair)
            if engine in ("mysql", "recovery"):
                _validate_keep(adapter, journal.directory, run)
            if pair is None:
                observed = adapter.observe()
                guard.require(observed.sqlite_live() if engine == "sqlite" else observed.mysql_live(),
                              "Complete the normal reopen before retiring into service")
                live = adapter._live_config()
                guard.require(live.engine == engine and live.source == host.live_config.CACHE
                              and live.live_config_sha256 == adapter.policy[f"{engine}_live_config_sha256"],
                              "The live configuration does not match this run's authority")
            stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
            archive = paths["archive"] / (run + "-" + stamp)
            guard.require(not os.path.lexists(archive), "A retirement archive already occupies this timestamp")
            work = {"version": 1, "run_id": run, "engine": engine, "archive": str(archive),
                    "state_sha256": guard.sha256(guard.read_protected(journal.path)), "next": pair, "archived": False}
            host._write_atomic(marker, work, 0o600)

        run, engine, pair = work["run_id"], work["engine"], work["next"]
        archive = _archived_at(work["archive"], run, paths["archive"])
        if state is not None:
            guard.require(guard.sha256(guard.read_protected(journal.path)) == work["state_sha256"],
                          "The journal changed during retirement")
        if not work["archived"]:
            guard.require(state is not None, "The journal disappeared before archival")
            _private_dir(paths["archive"])
            _private_dir(archive)
            for entry in sorted(journal.directory.iterdir()):
                if entry.name not in LOCKS | {PENDING}:
                    _copy(entry, archive / entry.name)
            _verify_snapshot(journal.directory, archive, first=True)
            guard.require(guard.sha256(guard.read_protected(archive / journal.path.name)) == work["state_sha256"],
                          "Archived journal differs from the completed run")
            if engine in ("mysql", "recovery"):
                _validate_keep(adapter, archive, run)
                _private_dir(paths["keep"])
                _private_dir(paths["keep"] / run)
                for key in KEEP_NAMES:
                    _copy(archive / paths[key].name, paths["keep"] / run / paths[key].name)
            work["archived"] = True
            host._write_atomic(marker, work, 0o600)

        handoff = None
        if pair is None:
            authority = {"version": 1, "run_id": run, "engine": engine, "archive": str(archive)}
            host._write_atomic(paths["retired"], authority, 0o644)
            with guard.open_protected(paths["retired"]) as fd:
                os.fchmod(fd, 0o644)
                os.fsync(fd)
        else:
            host._remove(paths["retired"])
            for key in ("identity_config", "policy"):
                host._write_raw_atomic(paths[key], pair[key].encode(), 0o600)
            next_run = _record(pair["identity_config"].encode())["run_id"]
            host.deploy_marker.publish(paths["deploy_marker"], adapter.locks, next_run,
                                       adapter.config["command_seconds"], owner=adapter.owner, previous_run=run)
            if engine == "recovery":
                handoff = {"version": 1, "run_id": next_run, "previous_run_id": run, "archive": str(archive),
                           "policy_sha256": guard.sha256(guard.read_protected(paths["policy"]))}
        for entry in sorted(journal.directory.iterdir()):
            if handoff is not None and entry.name == NEXT_RUN and _record(guard.read_protected(entry)) == handoff:
                continue
            if entry.name not in LOCKS | {PENDING, journal.path.name}:
                _remove_archived(entry, archive / entry.name)
        if os.path.lexists(journal.path):
            _remove_archived(journal.path, archive / journal.path.name)
        for key in ("evidence", "activation"):
            host._remove(paths[key])
        if pair is None:
            if engine == "mysql":
                expected = _validate_keep(adapter, archive, run)
                _move_frozen(adapter.database, run, expected)
        if handoff is not None:
            host._write_atomic(journal.directory / NEXT_RUN, handoff, 0o600)
        host._remove(marker)
        if pair is None:
            host.deploy_marker.withdraw(paths["deploy_marker"], adapter.locks, run,
                                        adapter.config["command_seconds"], owner=adapter.owner)
        return {"run_id": run, "archive": str(archive), "engine": engine, "next_policy_installed": pair is not None}


def retired_open(adapter):
    with adapter.journal.acquire():
        guard.require(not exists(adapter.journal.path), "An active journal owns ingress")
        try:
            record = read_retired(adapter.paths["retired"], adapter.journal.directory, archive_root=adapter.paths["archive"])
            live = adapter._live_config()
            guard.require(live.source == host.live_config.CACHE and live.engine == record["engine"]
                          and live.live_config_sha256 == adapter.policy[f"{record['engine']}_live_config_sha256"],
                          "The cached application route does not match retired authority")
        except (guard.Refused, OSError, UnsafeCutover, ValueError):
            host._remove(adapter.paths["ingress_open"])
            raise
        host._write_atomic(adapter.paths["ingress_open"], {"version": 1, "engine": record["engine"]}, 0o644)
        return record


def initialize(runner, adapter):
    marker = adapter.paths["retired"]

    def fence():
        guard.require(not exists(adapter.journal.directory / NEXT_RUN),
                      "A recovery policy handoff requires initialization from the verified held host")
        if exists(marker):
            record = read_retired(marker, adapter.journal.directory, archive_root=adapter.paths["archive"])
            guard.require(record["engine"] == "sqlite" and record["run_id"] != adapter.pinned.run_id,
                          "Only retired SQLite can start a new migration run ID")
        adapter.hold_deploys()

    # A crash after the journal commit leaves normal guard authority in effect.
    # The same init may finish this cleanup for an already-planned new run.
    if exists(marker):
        with adapter.journal.acquire() as (state, _):
            if state is not None:
                guard.require(state == runner.status() and state.get("phase") == "planned" and state.get("pending") is None
                              and state.get("history") == [], "Cannot finish retired initialization in this phase")
                host._remove(marker)
                return state
    return runner.initialize(fence_deploys=fence, after_commit=lambda: host._remove(marker))
