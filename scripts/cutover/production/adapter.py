"""Production host adapter for the SQLite hold, freeze and pre-write rollback.

Every effect is idempotent and re-observable, as the runner requires:

- close_admissions removes the nginx open marker (both vhosts answer 503 at
  once), publishes the deployment fence under both deploy locks and pauses
  every queue;
- drain stops the worker groups, drains PHP-FPM and waits until no writer
  process remains;
- freeze seals the SQLite file; seal_sqlite restores its modes for rollback;
- publish_authority writes the boot-bound SQLite authority proof;
- reopen verifies that proof through the guard, then resumes queues, starts
  workers, recreates the open marker and lifts the deployment fence.

It never makes MySQL writable: prepare, evidence and seal_mysql refuse.
Host commands are fixed argument lists from the root-owned host config.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import time
import uuid
from dataclasses import asdict
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE), str(HERE.parent)]
try:
    from fence import guard  # Repository layout.
except ImportError:  # Installed layout: /opt/repro-cutover-fence/guard.py
    import guard
import deploy_marker  # noqa: E402
import sqlite_seal  # noqa: E402
from runner import (  # noqa: E402
    Evidence, Identity, Journal, Lease, Observation, Runner, SchedulerDrain, UnsafeCutover, digest,
)

NOT_SUPPORTED = "This adapter supports the SQLite hold, freeze and rollback only"
CHILD_TAG = "REPRO_CUTOVER_CHILD"
POLL_SECONDS = 0.25
PATHS = {"state", "policy", "evidence", "activation", "machine_id", "boot_id", "ingress_open", "deploy_marker",
         "scheduler_hold", "sqlite_seal_record"}
COMMANDS = {"identity", "mysql", "queue_pause", "queue_resume", "stop_workers", "start_workers", "drain_fpm"}
OPTIONS = {"max_drain_seconds", "separate_freeze_approval", "adopt_external_hold", "require_long_job_predrain"}
# Longest single host command the config may allow (command_seconds).
MAX_COMMAND_SECONDS = 300


def _argv(value) -> bool:
    return isinstance(value, list) and bool(value) and all(isinstance(part, str) for part in value) \
        and Path(value[0]).is_absolute()


def load_config(raw: bytes) -> dict:
    config = guard.read_json(raw)
    paths, commands, options = config.get("paths"), config.get("commands"), config.get("options")
    guard.require(isinstance(paths, dict) and paths.keys() == PATHS
                  and all(isinstance(value, str) and Path(value).is_absolute() for value in paths.values()),
                  "Host config paths are incomplete")
    guard.require(isinstance(commands, dict) and commands.keys() == COMMANDS
                  and all(_argv(commands[name]) for name in ("identity", "mysql", "queue_pause", "queue_resume"))
                  and all(isinstance(commands[name], list) and all(map(_argv, commands[name]))
                          for name in ("stop_workers", "start_workers", "drain_fpm")),
                  "Host config commands must be fixed absolute argument lists")
    guard.require(isinstance(config.get("sqlite"), str) and Path(config["sqlite"]).is_absolute()
                  and isinstance(config.get("deploy_locks"), list) and len(config["deploy_locks"]) == 2
                  and all(isinstance(lock, str) and Path(lock).is_absolute() for lock in config["deploy_locks"])
                  and isinstance(config.get("deploy_owner"), str),
                  "Host config SQLite path or deploy locks are invalid")
    guard.require(isinstance(config.get("queues"), list) and config["queues"]
                  and all(isinstance(queue, str) and re.fullmatch(r"[a-z]+:[a-z0-9-]+", queue) for queue in config["queues"]),
                  "Host config queues must be connection:queue names")
    for name in ("writer_processes", "long_job_processes", "scheduler_processes"):
        guard.require(isinstance(config.get(name), list) and all(isinstance(item, str) and len(item) >= 8
                                                                for item in config[name]),
                      f"Host config {name} must be specific command-line patterns")
    guard.require(type(config.get("command_seconds")) is int and 0 < config["command_seconds"] <= MAX_COMMAND_SECONDS
                  and isinstance(options, dict) and options.keys() == OPTIONS
                  and type(options["max_drain_seconds"]) is int and 0 < options["max_drain_seconds"] <= 45 * 60
                  and all(type(options[name]) is bool for name in OPTIONS - {"max_drain_seconds"}),
                  "Host config timing or owner options are invalid")
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
    _runtime_directory(path.parent)
    temporary = path.with_name(f".{path.name}.{uuid.uuid4().hex}")
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, mode)
    try:
        with os.fdopen(descriptor, "wb") as stream:
            stream.write((json.dumps(value, sort_keys=True, allow_nan=False) + "\n").encode("utf-8"))
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

    def _run(self, argv: list[str], deadline: float | None = None) -> str:
        seconds = self.config["command_seconds"]
        if deadline is not None:
            seconds = max(1.0, min(seconds, deadline - self.clock()))
        try:
            result = subprocess.run(
                argv, stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=seconds,
                env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8", CHILD_TAG: "1"},
            )
        except subprocess.TimeoutExpired:
            raise UnsafeCutover(f"Host command timed out: {Path(argv[0]).name}") from None
        if result.returncode != 0:
            raise UnsafeCutover(f"Host command failed: {Path(argv[0]).name} exited {result.returncode}")
        return result.stdout

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

    def _mysql(self) -> dict:
        try:
            observed = json.loads(self._run(self.config["commands"]["mysql"]))
        except ValueError as error:
            raise UnsafeCutover("The MySQL target probe returned invalid JSON") from error
        if not (isinstance(observed, dict) and type(observed.get("writable")) is bool
                and type(observed.get("business_activity")) is bool):
            raise UnsafeCutover("The MySQL target probe must report writable and business_activity")
        return observed

    def observe(self) -> Observation:
        mysql = self._mysql()
        return Observation(
            engine="sqlite",
            admissions_open=self.paths["ingress_open"].exists(),
            workers_stopped=not self.processes(self.config["writer_processes"]),
            sqlite_writable=sqlite_seal.writable(self.database),
            mysql_writable=mysql["writable"],
            mysql_business_activity=mysql["business_activity"],
            deployment_fenced=deploy_marker.published(self.paths["deploy_marker"], self.pinned.run_id),
        )

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
        deploy_marker.publish(self.paths["deploy_marker"], self.locks, self.pinned.run_id,
                              max(0.0, deadline - self.clock()), owner=self.owner)
        for queue in self.config["queues"]:
            self._run(self.config["commands"]["queue_pause"] + [queue], deadline)

    def drain(self, lease: Lease, deadline: int) -> None:
        lease.require_current()
        for argv in self.config["commands"]["stop_workers"] + self.config["commands"]["drain_fpm"]:
            self._run(argv, deadline)
        self._wait_absent(self.config["writer_processes"], deadline, "Writers are still running after the stop")

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
        sqlite_seal.seal(self.database, self.paths["sqlite_seal_record"])

    def prepare(self, lease: Lease, deadline: int) -> None:
        raise UnsafeCutover(NOT_SUPPORTED)

    def evidence(self) -> Evidence:
        raise UnsafeCutover(NOT_SUPPORTED)

    def seal_mysql(self, lease: Lease, deadline: int) -> None:
        raise UnsafeCutover(NOT_SUPPORTED)

    def seal_sqlite(self, lease: Lease, deadline: int) -> None:
        lease.require_writers_drained()
        sqlite_seal.unseal(self.database, self.paths["sqlite_seal_record"])
        _remove(self.paths["ingress_open"])

    # Authority and reopening.

    def _machine_sha(self) -> str:
        return guard.sha256(self.paths["machine_id"].read_bytes())

    def publish_authority(self, lease: Lease, engine: str, candidate: bytes, deadline: int) -> None:
        lease.require_writers_drained()
        if engine != "sqlite" or not self.observe().sealed("sqlite") or self.clock() >= deadline:
            raise UnsafeCutover("SQLite authority is not sealed for publication")
        observed = self.identity()
        writers = self.processes(self.config["writer_processes"] + self.config["scheduler_processes"])
        _write_atomic(self.paths["evidence"], {
            "version": 1, "boot_id": self.boot_id(), "policy_sha256": guard.sha256(self.policy_raw),
            "journal_sha256": guard.sha256(candidate), "engine": "sqlite",
            "database_identity": observed.source_sha256,
            "live_config_sha256": self.policy["sqlite_live_config_sha256"],
            "release_sha": observed.release_sha, "artifact_sha256": observed.artifact_sha256,
            "competing_writer_detected": bool(writers),
        }, 0o600)

    def hold_deploys(self) -> None:
        """Publish the deployment fence under both deploy locks.

        The guard holds deploys for a planned cutover, but Deploy-App checks only
        the marker file and never calls the guard. So the marker is what holds
        them, from `init` until reopening a completed rollback lifts it.
        """
        deploy_marker.publish(self.paths["deploy_marker"], self.locks, self.pinned.run_id,
                              self.config["command_seconds"], owner=self.owner)

    def host_authority(self, engine: str) -> "guard.HostAuthority":
        """Read-only probe for guard.refresh_engine_evidence, for a committed planned or terminal journal.

        Deploys count as fenced only when the marker that Deploy-App checks exists;
        the journal's own deploy refusal is not enforced by any deploy script.
        """
        observed = self.observe()
        identity = self.identity()
        return guard.HostAuthority(
            engine=observed.engine, database_identity=identity.source_sha256,
            live_config_sha256=self.policy[f"{observed.engine}_live_config_sha256"],
            release_sha=identity.release_sha, artifact_sha256=identity.artifact_sha256,
            competing_writer_detected=bool(self.processes(self.config["writer_processes"]
                                                         + self.config["scheduler_processes"])),
            admissions_open=observed.admissions_open, workers_stopped=observed.workers_stopped,
            deployment_fenced=observed.deployment_fenced,
            sqlite_writable=observed.sqlite_writable, mysql_writable=observed.mysql_writable,
        )

    def refresh(self) -> None:
        """Publish current-boot authority evidence for a committed planned or terminal journal.

        The host is measured with the deploy fence published. A planned journal
        keeps deploys held throughout. Reopening a completed SQLite rollback
        lifted the fence, so after a later reboot it is published again before
        the measurement; reopen lifts it once activation is verified.
        Publishing the fence only ever holds deploys.

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
            if (state is not None and state.get("phase") in ("planned", "sqlite_authoritative")
                    and state.get("pending") is None):
                self.hold_deploys()

        self._refuse_open_ingress()
        # The guard checks the evidence directory's chain before it publishes into it.
        _runtime_directory(self.paths["evidence"].parent)
        guard.refresh_engine_evidence(self.host_authority, precondition=held_host)

    def _refuse_open_ingress(self) -> None:
        if self.paths["ingress_open"].exists() or self.paths["ingress_open"].is_symlink():
            raise UnsafeCutover("Ingress is open. Refresh measures a held host only, for example after a reboot")

    def _close_reopened(self) -> None:
        """Undo a reopen whose live check failed: ingress first, then the queues and workers.

        The deploy fence is still published and no activation receipt exists,
        so deploys stay held as well.
        """
        _remove(self.paths["ingress_open"])
        deadline = self.clock() + self.config["command_seconds"]
        for queue in self.config["queues"]:
            self._run(self.config["commands"]["queue_pause"] + [queue], deadline)
        for argv in self.config["commands"]["stop_workers"]:
            self._run(argv, deadline)

    def reopen(self) -> None:
        """Reopen SQLite writers in order once the guard accepts the committed SQLite authority.

        After an abort this follows sqlite_authoritative. After a reboot in the
        planned phase it follows a refresh of the authority evidence.

        A reopen that failed or crashed after starting a worker group leaves
        wrappers that keep their shared writer lease for their whole life. A
        retry stops those groups first, so the writer lock can drain, and
        starts them again below. Stopping workers only ever holds writers.
        """
        with self.journal.acquire() as (state, lease):
            if self.processes(self.config["writer_processes"]):
                deadline = self.clock() + self.config["command_seconds"]
                for argv in self.config["commands"]["stop_workers"]:
                    self._run(argv, deadline)
                self._wait_absent(self.config["writer_processes"], deadline,
                                  "Workers started by an earlier reopen are still running")
            self.journal.drain_writers(lease, self.config["command_seconds"])
            journal_raw = self.journal.path.read_bytes()
            evidence_raw = self.paths["evidence"].read_bytes()
            try:
                guard.verify(self.policy_raw, journal_raw, evidence_raw, machine_sha=self._machine_sha(),
                             boot_id=self.boot_id(), action="web-writer", expected_engine="sqlite")
            except guard.Refused as error:
                raise UnsafeCutover(str(error)) from error
            phase = None if state is None else state["phase"]
            if phase not in ("planned", "sqlite_authoritative"):
                raise UnsafeCutover("Reopening requires a planned journal or a completed SQLite authority transition")
            # The proof was measured earlier; the guard does not look at the host again.
            # Check the live route before any writer is let in.
            current = self.observe()
            if (current.engine != "sqlite" or not current.sqlite_writable or current.mysql_writable
                    or current.mysql_business_activity):
                raise UnsafeCutover("SQLite is no longer the only writable engine; writers stay held")
            # Invalidate an earlier receipt before any change, then let wrapped writers in.
            _remove(self.paths["activation"])
            self.journal.release_writers(lease)
            for queue in self.config["queues"]:
                self._run(self.config["commands"]["queue_resume"] + [queue])
            for argv in self.config["commands"]["start_workers"]:
                self._run(argv)
            _write_atomic(self.paths["ingress_open"], {"version": 1, "engine": "sqlite"}, 0o644)
            try:
                live = self.observe().sqlite_live()
            except UnsafeCutover:
                live = False
            if not live:
                self._close_reopened()
                raise UnsafeCutover("SQLite admissions could not be verified after reopening; "
                                    "ingress, queues and workers are held again")
            if phase == "planned":
                # Deploys stay held for the planned cutover; there is no activation receipt.
                return
            _write_atomic(self.paths["activation"], {
                "version": 1, "boot_id": self.boot_id(), "policy_sha256": guard.sha256(self.policy_raw),
                "journal_sha256": guard.sha256(journal_raw), "authority_sha256": guard.sha256(evidence_raw),
                "engine": "sqlite", "application_verified": True, "reconciliation_verified": True,
            }, 0o600)
            deploy_marker.withdraw(self.paths["deploy_marker"], self.locks, self.pinned.run_id,
                                   self.config["command_seconds"], owner=self.owner)
