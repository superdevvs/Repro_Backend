"""Durable state machine for isolated SQLite-to-MySQL cutover rehearsals."""

from __future__ import annotations

import hashlib
import json
import os
import re
import stat
import tempfile
import time
import uuid
from contextlib import contextmanager
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Callable, Protocol


class UnsafeCutover(RuntimeError):
    """A cutover precondition could not be established."""


def digest(value: object) -> str:
    encoded = json.dumps(value, sort_keys=True, separators=(",", ":"), allow_nan=False)
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def is_sha256(value: object) -> bool:
    return isinstance(value, str) and re.fullmatch(r"[0-9a-f]{64}", value) is not None


@dataclass(frozen=True)
class Identity:
    run_id: str
    release_sha: str
    artifact_sha256: str
    source_sha256: str
    config_sha256: str
    target_uuid: str

    def __post_init__(self) -> None:
        if not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}", self.run_id):
            raise ValueError("Invalid cutover run ID")
        if not re.fullmatch(r"[0-9a-f]{40}", self.release_sha):
            raise ValueError("Expected the full release commit SHA")
        if not all(map(is_sha256, (self.artifact_sha256, self.source_sha256, self.config_sha256))):
            raise ValueError("Expected SHA-256 artifact, source identity and configuration digests")
        if str(uuid.UUID(self.target_uuid)) != self.target_uuid:
            raise ValueError("Expected the canonical target MySQL UUID")


@dataclass(frozen=True)
class Approval:
    """Trusted operator authorization as supplied by the host integration."""

    action: str
    identity_sha256: str
    evidence_sha256: str
    challenge: str
    expires_at: int


@dataclass(frozen=True)
class Observation:
    engine: str
    admissions_open: bool
    workers_stopped: bool
    sqlite_writable: bool
    mysql_writable: bool
    mysql_business_activity: bool
    deployment_fenced: bool

    def frozen(self) -> bool:
        return (
            self.engine in {"sqlite", "mysql"}
            and not self.admissions_open
            and self.workers_stopped
            and not self.sqlite_writable
            and not self.mysql_writable
            and not self.mysql_business_activity
            and self.deployment_fenced
        )

    def sqlite_live(self) -> bool:
        return (
            self.engine == "sqlite"
            and self.admissions_open
            and self.sqlite_writable
            and not self.mysql_writable
            and not self.mysql_business_activity
        )

    def mysql_live(self) -> bool:
        return self.engine == "mysql" and self.admissions_open and self.mysql_writable and not self.sqlite_writable

    def held(self) -> bool:
        """New admissions and deploys are closed on SQLite. Old writers may still be draining."""
        return (
            self.engine == "sqlite"
            and not self.admissions_open
            and self.deployment_fenced
            and not self.mysql_writable
            and not self.mysql_business_activity
        )

    def externally_held(self) -> bool:
        """SQLite is still the writable source, but admissions were closed outside the journal."""
        return (
            self.engine == "sqlite"
            and not self.admissions_open
            and self.sqlite_writable
            and not self.mysql_writable
            and not self.mysql_business_activity
        )

    def sealed(self, engine: str) -> bool:
        """A single writer route is prepared while every admission stays held."""
        return (
            engine in {"sqlite", "mysql"}
            and self.engine == engine
            and not self.admissions_open
            and self.workers_stopped
            and self.sqlite_writable is (engine == "sqlite")
            and self.mysql_writable is (engine == "mysql")
            and not self.mysql_business_activity
            and self.deployment_fenced
        )


@dataclass(frozen=True)
class Evidence:
    snapshot_sha256: str
    report_sha256: str
    binlog_file: str
    binlog_position: int
    forward_parity: bool
    final_schema: bool
    readonly_smoke: bool
    sqlite_backup_offsite: bool
    mysql_backup_offsite: bool

    def require_verified(self) -> None:
        if not all(map(is_sha256, (self.snapshot_sha256, self.report_sha256))):
            raise UnsafeCutover("Snapshot or evidence report digest is missing")
        if not self.binlog_file or type(self.binlog_position) is not int or self.binlog_position < 4:
            raise UnsafeCutover("Verified starting binlog position is missing")
        if any(
            result is not True
            for result in (
                self.forward_parity, self.final_schema, self.readonly_smoke,
                self.sqlite_backup_offsite, self.mysql_backup_offsite,
            )
        ):
            raise UnsafeCutover("Required parity, smoke or off-site backup gate failed")


@dataclass(frozen=True)
class SchedulerDrain:
    identity_sha256: str
    boot_id: str
    hold_id: str
    admission_closed: bool
    unresolved_permits: int

    def require_verified(self, identity: Identity, current_boot: str) -> None:
        try:
            canonical = all(str(uuid.UUID(value)) == value for value in (self.boot_id, self.hold_id))
        except (ValueError, TypeError, AttributeError):
            canonical = False
        if (
            not canonical
            or self.boot_id != current_boot
            or self.identity_sha256 != digest(asdict(identity))
            or self.admission_closed is not True
            or type(self.unresolved_permits) is not int
            or self.unresolved_permits != 0
        ):
            raise UnsafeCutover("Current scheduler hold and zero unresolved permits could not be verified")


CONTROLLER_LOCK = "controller.lock"
# Admitted writers share this lock; the guard and installed wrappers use this name.
WRITER_LOCK = "operator.lock"
LOCK_POLL_SECONDS = 0.01


def _try_lock(stream) -> bool:
    stream.seek(0)
    try:
        if os.name == "nt":
            import msvcrt
            msvcrt.locking(stream.fileno(), msvcrt.LK_NBLCK, 1)
        else:
            import fcntl
            fcntl.flock(stream.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        return False
    return True


def _wait_lock(stream, seconds: float) -> bool:
    """Poll for an exclusive lock. flock has no writer preference, so polling is bounded."""
    limit = time.monotonic() + max(0.0, seconds)
    while not _try_lock(stream):
        if time.monotonic() >= limit:
            return False
        time.sleep(LOCK_POLL_SECONDS)
    return True


def _unlock(stream) -> None:
    if stream.closed:
        return
    stream.seek(0)
    try:
        if os.name == "nt":
            import msvcrt
            msvcrt.locking(stream.fileno(), msvcrt.LK_UNLCK, 1)
        else:
            import fcntl
            fcntl.flock(stream.fileno(), fcntl.LOCK_UN)
    except OSError:
        pass


class Journal:
    """One controller at a time, using an OS lock and atomically replaced state.

    The controller lock serializes the runner, the watchdog tick and the
    authority refresh. Admitted writers share the separate writer lock. A
    controller can therefore journal a hold while old writers still run, and
    takes the writer lock exclusively only when it drains them.
    """

    def __init__(self, directory: Path):
        directory.mkdir(mode=0o700, parents=False, exist_ok=True)
        info = directory.lstat()
        if not stat.S_ISDIR(info.st_mode):
            raise UnsafeCutover("Journal path must be a real private directory")
        if os.name == "posix" and (info.st_uid != os.geteuid() or info.st_mode & 0o077):
            raise UnsafeCutover("Journal directory must belong to the operator with mode 0700")
        self.directory = directory
        self.path = directory / "state.json"

    def read(self) -> dict | None:
        if not self.path.exists():
            return None
        return json.loads(self.path.read_text(encoding="utf-8"))

    def write(self, state: dict) -> None:
        self.write_record(self.path.name, state)

    @staticmethod
    def encode(value: dict) -> bytes:
        return (json.dumps(value, sort_keys=True, allow_nan=False) + "\n").encode("utf-8")

    def write_record(self, name: str, value: dict) -> None:
        descriptor, temporary = tempfile.mkstemp(prefix=".journal-", dir=self.directory)
        try:
            with os.fdopen(descriptor, "wb") as stream:
                stream.write(self.encode(value))
                stream.flush()
                os.fsync(stream.fileno())
            os.replace(temporary, self.directory / name)
            if os.name == "posix":
                directory_descriptor = os.open(self.directory, os.O_RDONLY | os.O_DIRECTORY)
                try:
                    os.fsync(directory_descriptor)
                finally:
                    os.close(directory_descriptor)
        finally:
            Path(temporary).unlink(missing_ok=True)

    @contextmanager
    def acquire(self, wait_seconds: float = 0):
        with (self.directory / CONTROLLER_LOCK).open("a+b") as stream:
            if not _wait_lock(stream, wait_seconds):
                raise UnsafeCutover("Another host operator holds the cutover lock")
            # The guard opens the writer lock read-only and never creates it.
            (self.directory / WRITER_LOCK).open("a+b").close()
            lease = None
            try:
                state = self.read()
                generation = 1 if state is None else state["generation"] + 1
                lease = Lease(self, generation)
                yield state, lease
            finally:
                if lease is not None:
                    lease.release()
                _unlock(stream)

    def drain_writers(self, lease: "Lease", seconds: float) -> float:
        """Wait until every admitted writer has released its shared lease, then keep them out.

        Returns the seconds waited. The exclusive writer lock stays held until
        the controller lease ends.
        """
        if not lease.active:
            raise UnsafeCutover("Cutover operator no longer owns the journal generation")
        if lease.writers is not None:
            lease.require_current()
            return 0.0
        stream = (self.directory / WRITER_LOCK).open("a+b")
        started = time.monotonic()
        if not _wait_lock(stream, seconds):
            stream.close()
            raise UnsafeCutover("Admitted writers did not drain before the deadline")
        lease.writers = stream
        return time.monotonic() - started


@dataclass
class Lease:
    journal: Journal
    generation: int
    active: bool = True
    writers: object = None

    def require_current(self) -> None:
        state = self.journal.read()
        if not self.active or state is None or state["generation"] != self.generation:
            raise UnsafeCutover("Cutover operator no longer owns the journal generation")
        if self.writers is not None and self.writers.closed:
            raise UnsafeCutover("Cutover operator no longer excludes admitted writers")

    def require_writers_drained(self) -> None:
        self.require_current()
        if self.writers is None:
            raise UnsafeCutover("Admitted writers have not been drained under this lease")

    def release(self) -> None:
        self.active = False
        if self.writers is not None:
            _unlock(self.writers)
            self.writers.close()


class Adapter(Protocol):
    """Host effects must be synchronous, fenced, bounded and re-observable."""

    def identity(self) -> Identity: ...
    def observe(self) -> Observation: ...
    def require_quiescent(self) -> None: ...
    def preflight_sha256(self) -> str: ...
    def boot_id(self) -> str: ...
    def long_jobs_drained(self) -> bool: ...
    # Idempotent and re-observable: both vhosts return 503 at the server level,
    # every queue is paused, the scheduler is held and the deploy marker is set.
    def close_admissions(self, lease: Lease, deadline: int) -> None: ...
    # Idempotent: workers stopped, scheduler permits zero, in-flight FPM requests finished.
    def drain(self, lease: Lease, deadline: int) -> None: ...
    def hold_and_drain_scheduler(self, lease: Lease, deadline: int) -> SchedulerDrain: ...
    def scheduler_drain(self) -> SchedulerDrain: ...
    def freeze(self, lease: Lease, deadline: int) -> None: ...
    def prepare(self, lease: Lease, deadline: int) -> None: ...
    def evidence(self) -> Evidence: ...
    def seal_mysql(self, lease: Lease, deadline: int) -> None: ...
    def seal_sqlite(self, lease: Lease, deadline: int) -> None: ...
    def publish_authority(self, lease: Lease, engine: str, candidate: bytes, deadline: int) -> None: ...


class Runner:
    DECISION_SECONDS = 45 * 60
    WINDOW_SECONDS = 60 * 60
    APPROVAL_SECONDS = 5 * 60
    # A watchdog tick waits this long for another controller, below the 1-minute timer period.
    CONTROLLER_WAIT_SECONDS = 50
    # Longest wait for admitted writers to finish once admissions are closed (plan: ten minutes).
    MAX_DRAIN_SECONDS = 10 * 60
    ACTIONS = frozenset({"hold", "freeze", "prepare", "promote", "tick", "abort"})
    # Admissions are held and the outage clock runs; MySQL has not been opened for writes.
    HELD_PHASES = frozenset({"holding", "frozen", "ready"})

    def __init__(self, journal: Journal, identity: Identity, adapter: Adapter, clock: Callable[[], float] = time.time,
                 *, max_drain_seconds: float | None = None, controller_wait_seconds: float | None = None,
                 separate_freeze_approval: bool = False, adopt_external_hold: bool = False,
                 require_long_job_predrain: bool = True):
        """Owner choices are explicit options; the defaults are the conservative plan defaults.

        separate_freeze_approval: False means the approved hold ("start freeze")
        also authorizes the freeze that follows it in the same journal.
        adopt_external_hold: False means admissions closed outside the journal
        are refused loudly instead of being adopted into the outage clock.
        require_long_job_predrain: True means long-job queues must already be
        paused and drained (the 9:20 pm step) before the hold starts the clock.
        """
        self.journal = journal
        self.identity = identity
        self.adapter = adapter
        self.clock = clock
        self.max_drain_seconds = self.MAX_DRAIN_SECONDS if max_drain_seconds is None else max_drain_seconds
        self.controller_wait_seconds = (
            self.CONTROLLER_WAIT_SECONDS if controller_wait_seconds is None else controller_wait_seconds
        )
        self.separate_freeze_approval = separate_freeze_approval
        self.adopt_external_hold = adopt_external_hold
        self.require_long_job_predrain = require_long_job_predrain

    def initialize(self) -> dict:
        with self.journal.acquire() as (prior, lease):
            if prior is not None:
                raise UnsafeCutover("A run already exists in this directory")
            self._verify_identity()
            self.adapter.require_quiescent()
            preflight = self.adapter.preflight_sha256()
            if not is_sha256(preflight) or not self.adapter.observe().sqlite_live():
                raise UnsafeCutover("SQLite preflight is incomplete")
            state = {
                "version": 1,
                "identity": asdict(self.identity),
                "generation": lease.generation,
                "phase": "planned",
                "writes_possible": False,
                "started_at": None,
                "last_observed_at": int(self.clock()),
                "preflight_sha256": preflight,
                "evidence": None,
                "challenge": uuid.uuid4().hex,
                "pending": None,
                "history": [],
            }
            self.journal.write(state)
            return state

    def _verify_identity(self) -> None:
        if self.adapter.identity() != self.identity:
            raise UnsafeCutover("Observed source, release, artifact, configuration or target identity differs")

    def status(self) -> dict:
        state = self.journal.read()
        if state is None or state.get("version") != 1 or state.get("identity") != asdict(self.identity):
            raise UnsafeCutover("Journal has no matching cutover run")
        return state

    @staticmethod
    def _require_state(state: dict, allowed: set[str]) -> None:
        if state["phase"] not in allowed:
            raise UnsafeCutover("Action is invalid for the current phase")

    def _request(self, state: dict, action: str, now: int) -> Approval:
        expected_phase = {"hold": "planned", "freeze": "holding", "promote": "ready"}[action]
        self._require_state(state, {expected_phase})
        if action == "freeze" and not self.separate_freeze_approval:
            raise UnsafeCutover("The approved hold authorizes the freeze; no separate approval is issued")
        expires = now + self.APPROVAL_SECONDS
        if action != "hold":
            expires = min(expires, state["started_at"] + self.DECISION_SECONDS)
        if state["pending"] is not None or expires <= now or now < state["last_observed_at"]:
            raise UnsafeCutover("No approval may be issued for the current journal state")
        if action == "hold":
            evidence = state["preflight_sha256"]
        elif action == "freeze":
            evidence = digest({"preflight": state["preflight_sha256"], "hold": state["hold"]})
        else:
            evidence = digest({"target": state["evidence"], "scheduler_drain": state.get("scheduler_drain")})
        return Approval(action, digest(state["identity"]), evidence, state["challenge"], expires)

    def approval_request(self, action: str) -> Approval:
        return self._request(self.status(), action, int(self.clock()))

    def _require_approval(self, state: dict, action: str, approval: Approval | None, now: int) -> None:
        expected = self._request(state, action, now)
        if approval is None or (
            approval.action != expected.action
            or approval.identity_sha256 != expected.identity_sha256
            or approval.evidence_sha256 != expected.evidence_sha256
            or approval.challenge != expected.challenge
            or type(approval.expires_at) is not int
            or not now < approval.expires_at <= expected.expires_at
        ):
            raise UnsafeCutover("Missing, expired or mismatched phase approval")

    def _intent(self, state: dict, action: str, before: Observation, now: int, **metadata) -> None:
        state["pending"] = {"action": action, "before": asdict(before), "at": now, **metadata}
        self.journal.write(state)

    def _complete(self, state: dict, after: Observation, phase: str) -> None:
        finished_at = max(state["last_observed_at"], int(self.clock()))
        state["history"].append({**state["pending"], "after": asdict(after), "phase": phase,
                                 "finished_at": finished_at})
        state["last_observed_at"] = finished_at
        state["pending"] = None
        state["phase"] = phase
        state["challenge"] = uuid.uuid4().hex

    def _finish(self, state: dict, after: Observation, phase: str) -> None:
        self._complete(state, after, phase)
        self.journal.write(state)

    def _exclude_writers(self, lease: Lease, deadline: float) -> float:
        """Drain admitted writers within both the drain limit and the action deadline."""
        seconds = min(self.max_drain_seconds, deadline - self.clock())
        if seconds <= 0:
            raise UnsafeCutover("Admitted writers did not drain before the deadline")
        return self.journal.drain_writers(lease, seconds)

    def _drain(self, state: dict, lease: Lease, deadline: int) -> None:
        started = time.monotonic()
        self.adapter.drain(lease, deadline)
        waited = self._exclude_writers(lease, deadline)
        if self.clock() >= deadline:
            raise UnsafeCutover("Admitted writers did not drain before the deadline")
        state["drain"] = {
            "writers_drained_ms": round(waited * 1000),
            "drain_ms": round((time.monotonic() - started) * 1000),
            "at": int(self.clock()),
        }

    def _close_admissions(self, state: dict, lease: Lease) -> None:
        deadline = state["started_at"] + self.DECISION_SECONDS
        self.adapter.close_admissions(lease, deadline)
        after = self.adapter.observe()
        if not after.held() or int(self.clock()) >= deadline:
            raise UnsafeCutover("Writer admissions could not be closed in time")
        self._finish(state, after, "holding")

    def _hold_scheduler(self, state: dict, lease: Lease, deadline: int) -> None:
        observed = self.adapter.hold_and_drain_scheduler(lease, deadline)
        observed.require_verified(self.identity, self.adapter.boot_id())
        if self.clock() >= deadline:
            raise UnsafeCutover("Scheduler drain did not complete in time")
        state["scheduler_drain"] = asdict(observed)
        self.journal.write(state)

    def _verify_scheduler_hold(self, state: dict) -> None:
        observed = self.adapter.scheduler_drain()
        observed.require_verified(self.identity, self.adapter.boot_id())
        if asdict(observed) != state.get("scheduler_drain"):
            raise UnsafeCutover("Scheduler hold changed during cutover")

    def _reassert_hold(self, state: dict, lease: Lease, before: Observation, now: int) -> bool:
        """After a crash or reboot, close admissions again and re-drain the scheduler under this boot."""
        deadline = state["started_at"] + self.DECISION_SECONDS
        boot_id = self.adapter.boot_id()
        hold = state.get("hold")
        changed = False
        if before.admissions_open or not before.deployment_fenced or (hold and hold["boot_id"] != boot_id):
            # Persist the current lease generation before any effect.
            self.journal.write(state)
            self.adapter.close_admissions(lease, deadline)
            after = self.adapter.observe()
            if after.admissions_open or not after.deployment_fenced:
                raise UnsafeCutover("Writer admissions could not be closed again")
            state["history"].append({"action": "reassert_hold", "boot_id": boot_id, "at": now,
                                     "before": asdict(before), "after": asdict(after), "phase": state["phase"]})
            if hold:
                hold["boot_id"] = boot_id
            changed = True
        drained = state.get("scheduler_drain")
        if drained is not None and drained.get("boot_id") != boot_id:
            self._hold_scheduler(state, lease, deadline)
            changed = True
        return changed

    def _publish_authority(self, state: dict, lease: Lease, engine: str, deadline: int) -> None:
        lease.require_writers_drained()
        self._verify_scheduler_hold(state)
        after = self.adapter.observe()
        if not after.sealed(engine) or int(self.clock()) >= deadline:
            raise UnsafeCutover("A sealed single-engine route could not be verified in time")
        self._complete(state, after, f"{engine}_authoritative")
        # The proof matches the candidate bytes only. While holding the exclusive
        # lease, publish the proof first, then commit exactly those journal bytes.
        # A crash between the two leaves a pending journal and no usable proof.
        self.adapter.publish_authority(lease, engine, self.journal.encode(state), deadline)
        self._verify_scheduler_hold(state)
        if not self.adapter.observe().sealed(engine) or int(self.clock()) >= deadline:
            raise UnsafeCutover("The authority proof or admission seal could not be maintained in time")
        self.journal.write(state)

    def _record_recovery(self, state: dict) -> None:
        state["writes_possible"] = True
        state["phase"] = "recovery_required"
        self.journal.write(state)

    def _rollback(self, state: dict, lease: Lease, before: Observation, now: int) -> None:
        if state["writes_possible"] or before.mysql_writable or before.mysql_business_activity:
            self._record_recovery(state)
            raise UnsafeCutover("MySQL writes may exist. Use separately verified recovery")
        if state["pending"] is not None:
            state["history"].append({**state["pending"], "phase": "interrupted"})
        self._intent(state, "abort", before, now)
        # Even a late watchdog must attempt recovery with a bounded fresh budget.
        rollback_deadline = max(state["started_at"] + self.WINDOW_SECONDS, now + 10 * 60)
        # From any held phase: keep admissions closed, stop workers and wait for
        # every admitted writer before SQLite authority is published again.
        self.adapter.close_admissions(lease, rollback_deadline)
        self.adapter.drain(lease, rollback_deadline)
        self.journal.drain_writers(lease, max(0.0, rollback_deadline - self.clock()))
        self._hold_scheduler(state, lease, rollback_deadline)
        self.adapter.seal_sqlite(lease, rollback_deadline)
        self._publish_authority(state, lease, "sqlite", rollback_deadline)

    def perform(self, action: str, approval: Approval | None = None) -> dict:
        if action not in self.ACTIONS:
            raise ValueError("Unknown cutover action")
        wait = self.controller_wait_seconds if action == "tick" else 0
        with self.journal.acquire(wait) as (_, lease):
            state = self.status()
            self._verify_identity()
            self.adapter.require_quiescent()
            before = self.adapter.observe()
            now = int(self.clock())
            clock_reversed = now < state["last_observed_at"]
            phase = state["phase"]

            if (phase == "mysql_authoritative" and state["writes_possible"] is True
                    and state["pending"] is None and (before.sealed("mysql") or before.mysql_live())):
                if action == "tick":
                    return state
                raise UnsafeCutover("MySQL writes may exist. Use separately verified recovery")
            if (phase == "sqlite_authoritative" and state["writes_possible"] is False
                    and state["pending"] is None and (before.sealed("sqlite") or before.sqlite_live())):
                if action in {"tick", "abort"}:
                    return state
                raise UnsafeCutover("Completed SQLite rollback cannot be re-run")

            last_observed = state["last_observed_at"]
            state["last_observed_at"] = max(now, last_observed)
            state["generation"] = lease.generation
            if state["writes_possible"] or before.mysql_writable or before.mysql_business_activity:
                if phase != "recovery_required":
                    self._record_recovery(state)
                raise UnsafeCutover("MySQL writes may exist. Use separately verified recovery")
            if phase == "sqlite_authoritative":
                self._record_recovery(state)
                raise UnsafeCutover("SQLite authority could not be verified. Use separately verified recovery")

            # The outage clock starts at the hold, so every deadline counts from it.
            started = state["started_at"]
            if started is not None and (
                clock_reversed or now >= started + self.DECISION_SECONDS or action == "abort"
            ):
                self._rollback(state, lease, before, state["last_observed_at"])
                return state
            if action == "tick":
                if started is None:
                    # An idle planned journal has no time-sensitive transition.
                    return self.status()
                changed = phase in self.HELD_PHASES and self._reassert_hold(state, lease, before, now)
                if changed or now > last_observed:
                    self.journal.write(state)
                    return state
                return self.status()
            pending = state["pending"]
            if pending is not None:
                if action == "hold" and pending["action"] == "hold" and phase == "holding" and not clock_reversed:
                    # The approved hold is already durable; re-assert its effects and finish it.
                    self.journal.write(state)
                    self._close_admissions(state, lease)
                    return state
                raise UnsafeCutover("Interrupted action requires a verified pre-write abort")
            if clock_reversed:
                raise UnsafeCutover("Host clock moved backwards")

            if action == "hold":
                self._hold(state, lease, before, approval, now)
            elif action == "freeze":
                self._freeze(state, lease, before, approval, now)
            elif action == "prepare":
                self._prepare(state, lease, before, now)
            elif action == "promote":
                self._promote(state, lease, before, approval, now)
            else:
                raise UnsafeCutover("No hold or freeze is active")
            return state

    def _hold(self, state: dict, lease: Lease, before: Observation, approval: Approval | None, now: int) -> None:
        self._require_approval(state, "hold", approval, now)
        if self.adapter.preflight_sha256() != state["preflight_sha256"]:
            raise UnsafeCutover("SQLite preflight changed after approval")
        adopted = not before.sqlite_live()
        if adopted and not (self.adopt_external_hold and before.externally_held()):
            raise UnsafeCutover(
                "Admissions are already closed outside the cutover journal. Reopen them, or adopt them explicitly"
            )
        if self.require_long_job_predrain and self.adapter.long_jobs_drained() is not True:
            raise UnsafeCutover("Long-job queues must be paused and drained before the hold")
        now = int(self.clock())
        self._require_approval(state, "hold", approval, now)
        boot_id = self.adapter.boot_id()
        hold_id = str(uuid.uuid4())
        # The hold is the first durable step: it starts the outage clock before any host effect.
        state["started_at"] = now
        state["phase"] = "holding"
        state["hold"] = {"hold_id": hold_id, "boot_id": boot_id, "adopted_external_close": adopted,
                         "long_jobs_predrained": self.require_long_job_predrain}
        self._intent(state, "hold", before, now, approval=asdict(approval), hold_id=hold_id, boot_id=boot_id)
        self._close_admissions(state, lease)

    def _freeze(self, state: dict, lease: Lease, before: Observation, approval: Approval | None, now: int) -> None:
        self._require_state(state, {"holding"})
        if self.separate_freeze_approval:
            self._require_approval(state, "freeze", approval, now)
        elif approval is not None:
            raise UnsafeCutover("The approved hold authorizes the freeze; no separate approval is issued")
        if not before.held() or self.adapter.preflight_sha256() != state["preflight_sha256"]:
            raise UnsafeCutover("Writer hold or SQLite preflight changed after the hold")
        deadline = state["started_at"] + self.DECISION_SECONDS
        metadata = {"approval": asdict(approval)} if approval is not None else {}
        self._intent(state, "freeze", before, now, **metadata)
        self._drain(state, lease, min(deadline, now + self.max_drain_seconds))
        self._hold_scheduler(state, lease, deadline)
        lease.require_writers_drained()
        self.adapter.freeze(lease, deadline)
        self._verify_scheduler_hold(state)
        after = self.adapter.observe()
        if not after.frozen() or not now <= self.clock() < deadline:
            raise UnsafeCutover("Could not establish the source, target and deploy freeze in time")
        self._finish(state, after, "frozen")

    def _prepare(self, state: dict, lease: Lease, before: Observation, now: int) -> None:
        self._require_state(state, {"frozen"})
        if not before.frozen():
            raise UnsafeCutover("Writer freeze was lost")
        self._verify_scheduler_hold(state)
        deadline = state["started_at"] + self.DECISION_SECONDS
        self._exclude_writers(lease, deadline)
        self._intent(state, "prepare", before, now)
        self.adapter.prepare(lease, deadline)
        self._verify_scheduler_hold(state)
        after = self.adapter.observe()
        if self.clock() >= deadline or not after.frozen() or after.engine != "mysql":
            raise UnsafeCutover("Read-only MySQL verification did not complete under the freeze in time")
        evidence = self.adapter.evidence()
        evidence.require_verified()
        if not now <= self.clock() < deadline:
            raise UnsafeCutover("Target evidence did not finish in time")
        state["evidence"] = asdict(evidence)
        self._finish(state, after, "ready")

    def _promote(self, state: dict, lease: Lease, before: Observation, approval: Approval | None, now: int) -> None:
        self._require_approval(state, "promote", approval, now)
        if not before.frozen() or before.engine != "mysql":
            raise UnsafeCutover("Writer freeze or read-only target was lost")
        self._exclude_writers(lease, state["started_at"] + self.DECISION_SECONDS)
        self._verify_scheduler_hold(state)
        evidence = self.adapter.evidence()
        evidence.require_verified()
        if asdict(evidence) != state["evidence"]:
            raise UnsafeCutover("Verification evidence changed after approval")
        self._verify_identity()
        self._verify_scheduler_hold(state)
        before = self.adapter.observe()
        if not before.frozen() or before.engine != "mysql":
            raise UnsafeCutover("Writer freeze or read-only target changed during verification")
        accepted_at = int(self.clock())
        if accepted_at < now:
            raise UnsafeCutover("Host clock moved backwards during verification")
        self._require_approval(state, "promote", approval, accepted_at)
        state["last_observed_at"] = accepted_at
        state["writes_possible"] = True
        state["phase"] = "writes_possible"
        self._intent(state, "promote", before, accepted_at, approval=asdict(approval))
        try:
            deadline = state["started_at"] + self.WINDOW_SECONDS
            self.adapter.seal_mysql(lease, deadline)
            self._publish_authority(state, lease, "mysql", deadline)
        except Exception:
            self._record_recovery(state)
            raise
