"""Measure the next recovery policy and verify an archived restore before initializing a held host."""
from dataclasses import asdict
import os
from pathlib import Path

import adapter as host
import identity_probe
import sqlite_seal
import wayback
from adapter import guard
from runner import UnsafeCutover, is_sha256


def draft(config, run_id, pin_config):
    guard.require(guard.matches(host.RUN_ID, run_id), "Invalid proposed run ID")
    installed = host.load_identity_config(guard.read_protected(Path(config["paths"]["identity_config"])))
    guard.require(run_id != installed["run_id"], "Choose a new run ID")
    inputs = {**installed, "run_id": run_id}
    machine = Path(config["paths"]["machine_id"])
    measured = identity_probe.measure(inputs, by_uuid=identity_probe.BY_UUID, machine_id=machine)
    guard.require(measured.target_uuid == config["mysql"]["target_uuid"], "The measured MySQL target changed")
    pins = pin_config(config)
    repeated = identity_probe.measure(inputs, by_uuid=identity_probe.BY_UUID, machine_id=machine)
    guard.require(repeated == measured, "The host identity changed during the policy measurement")
    return {"identity": inputs, "policy": {"version": 1, "identity": asdict(measured), **pins,
            "machine_id_sha256": guard.sha256(guard.read_protected(machine))}}


def archive_for(adapter):
    import retire

    path = adapter.journal.directory / "next-run.json"
    marker = guard.read_json(guard.read_protected(path))
    guard.require(guard.read_protected(adapter.paths["policy"]) == adapter.policy_raw,
                  "Installed recovery policy changed before initialization")
    guard.require(marker.keys() == {"version", "run_id", "previous_run_id", "archive", "policy_sha256"}
                  and marker["run_id"] == adapter.pinned.run_id
                  and marker["previous_run_id"] != marker["run_id"]
                  and marker["policy_sha256"] == guard.sha256(adapter.policy_raw),
                  "Next run does not match the installed recovery policy")
    retire._archived_at(marker["archive"], marker["previous_run_id"], adapter.paths["archive"])
    return Path(marker["archive"]), marker["previous_run_id"]


def check_installation(adapter, archive, run_id):
    close_raw = guard.read_protected(archive / "way-back.json")
    closed = guard.read_json(close_raw)
    installed = guard.read_json(guard.read_protected(archive / "wayback-installed.json"))
    target = adapter.config["mysql"]
    guard.require(closed.get("gate") == "closed" and closed.get("server_uuid") == target["target_uuid"]
                  and closed.get("run_id") == run_id and host._coordinate(closed.get("coordinate"), host.BACKUP_KEYS),
                  "Archived gate closure is incomplete or belongs to another target")
    guard.require(installed.get("run_id") == run_id and installed.get("database") == str(adapter.database)
                  and installed.get("server_uuid") == target["target_uuid"] and installed.get("writers_held") is True
                  and installed.get("method") in ("verified-export", "verified-prewrite")
                  and installed.get("close_sha256") == guard.sha256(close_raw)
                  and installed.get("source_sha256") == adapter.pinned.source_sha256
                  and installed.get("sqlite_live_config_sha256") == adapter.policy["sqlite_live_config_sha256"]
                  and all(is_sha256(installed.get(k)) for k in ("installed_sha256", "review_sha256", "verification_sha256")),
                  "The archived restore receipt does not match the next run")
    require_clean_sidecars(adapter.database)
    guard.require(sqlite_seal.file_sha256(adapter.database) == installed["installed_sha256"],
                  "The restored SQLite file changed after independent verification")
    require_clean_sidecars(adapter.database)
    wayback.require_same_close(adapter, closed, run_id)
    return closed


def require_clean_sidecars(database):
    for suffix in ("-wal", "-journal", "-shm"):
        path = Path(str(database) + suffix)
        if not os.path.lexists(path):
            continue
        if path.is_symlink() or not path.is_file() or (suffix != "-shm" and path.stat().st_size):
            raise UnsafeCutover("An unverified SQLite sidecar is present")


def held_preflight(adapter, lease):
    """Called under the controller lock with no journal. No public marker is needed to probe FPM."""
    paths = adapter.paths
    for name in ("ingress_open", "activation", "scheduler_release", "retired", "evidence"):
        guard.require(not os.path.lexists(paths[name]), "Restored-host admissions must remain held")
    adapter.journal.drain_writers(lease, adapter.config["command_seconds"])
    guard.require(adapter.observe().sealed("sqlite") and not adapter.processes(adapter.config["scheduler_processes"]),
                  "The restored SQLite host must be fenced with all writers stopped")
    archive, previous = archive_for(adapter)
    before = file_stamp(adapter.database)
    closed = check_installation(adapter, archive, previous)
    reading, target = adapter._mysql(), adapter.config["mysql"]
    guard.require(reading.get("reachable") is True and reading.get("gate") == "closed"
                  and reading.get("writable") is False and reading.get("business_activity") is False
                  and reading.get("foreign_activity") is False and type(reading.get("gated_sessions")) is int
                  and reading["gated_sessions"] == 0 and reading.get("database") == target["database"]
                  and reading.get("server_uuid") == target["target_uuid"], "MySQL is not the pinned closed target")
    expected = adapter._sqlite_reopen_checks({"phase": "planned"})
    deadline = adapter.clock() + adapter.config["command_seconds"]
    adapter._run(adapter.config["commands"]["fpm_start"], deadline)
    adapter._fastcgi_probe(expected, deadline)
    guard.require(adapter.observe().sealed("sqlite"), "Restored-host fence changed while starting FPM")
    require_clean_sidecars(adapter.database)
    guard.require(file_stamp(adapter.database) == before, "The restored SQLite file changed during the application check")
    wayback.require_same_close(adapter, closed, previous)
    return closed


def file_stamp(database):
    info = database.lstat()
    return info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns


def first_reopen(adapter, state, *, require_completed=False):
    """Keep the old coordinate binding until SQLite's first successful public activation."""
    if state.get("phase") != "planned" or "recovered_from" not in state:
        return None
    origin = state["recovered_from"]
    guard.require(isinstance(origin, dict) and guard.matches(host.RUN_ID, origin.get("run_id"))
                  and origin["run_id"] != adapter.pinned.run_id, "Invalid recovery origin in the planned journal")
    released = adapter.journal.directory / "restored-sqlite-live.json"
    binding = {"version": 1, "run_id": adapter.pinned.run_id, "recovered_from": origin}
    try:
        completed = guard.read_protected(released)
    except FileNotFoundError:
        guard.require(not require_completed, "Complete the first verified SQLite reopen before starting another cutover run")
        wayback.require_same_close(adapter, origin, origin["run_id"])
        return released, binding
    guard.require(guard.read_json(completed) == binding, "The first SQLite activation belongs to a different recovery")
    return None


def initialize_held(runner, adapter):
    """Consume the recovery handoff only after the planned journal is durable."""
    marker = adapter.journal.directory / "next-run.json"

    def consume():
        origin = adapter.journal.read()["recovered_from"]
        wayback.require_same_close(adapter, origin, origin["run_id"])
        host._remove(marker)

    with adapter.journal.acquire() as (state, lease):
        if state is not None:
            guard.require(state == runner.status() and state["phase"] == "planned" and not state["history"]
                          and state["pending"] is None and state["writes_possible"] is False,
                          "Cannot resume held initialization after the new run advanced")
            runner._verify_identity()
            adapter.require_quiescent()
            origin = held_preflight(adapter, lease)
            if "recovered_from" not in state:
                state["recovered_from"] = origin
                adapter.journal.write(state)
            guard.require(state["recovered_from"] == origin, "Planned journal and archived recovery coordinate differ")
            consume()
            return state
    return runner.initialize(fence_deploys=adapter.hold_deploys, after_commit=consume,
                             held_preflight=lambda lease: held_preflight(adapter, lease))
