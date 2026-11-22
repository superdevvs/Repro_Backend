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


class Journal:
    """One operation at a time, using an OS lock and atomically replaced state."""

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

    def write_record(self, name: str, value: dict) -> None:
        descriptor, temporary = tempfile.mkstemp(prefix=".journal-", dir=self.directory)
        try:
            with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
                json.dump(value, stream, sort_keys=True, allow_nan=False)
                stream.write("\n")
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
    def acquire(self):
        with (self.directory / "operator.lock").open("a+b") as stream:
            stream.seek(0)
            try:
                if os.name == "nt":
                    import msvcrt
                    msvcrt.locking(stream.fileno(), msvcrt.LK_NBLCK, 1)
                else:
                    import fcntl
                    fcntl.flock(stream.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            except OSError as error:
                raise UnsafeCutover("Another host operator holds the cutover lock") from error
            state = self.read()
            generation = 1 if state is None else state["generation"] + 1
            lease = Lease(self, generation)
            try:
                yield state, lease
            finally:
                lease.active = False


@dataclass
class Lease:
    journal: Journal
    generation: int
    active: bool = True

    def require_current(self) -> None:
        state = self.journal.read()
        if not self.active or state is None or state["generation"] != self.generation:
            raise UnsafeCutover("Cutover operator no longer owns the journal generation")


class Adapter(Protocol):
    """Host effects must be synchronous, fenced, bounded and re-observable."""

    def identity(self) -> Identity: ...
    def observe(self) -> Observation: ...
    def require_quiescent(self) -> None: ...
    def preflight_sha256(self) -> str: ...
    def freeze(self, lease: Lease, deadline: int) -> None: ...
    def prepare(self, lease: Lease, deadline: int) -> None: ...
    def evidence(self) -> Evidence: ...
    def enable_mysql(self, lease: Lease, deadline: int) -> None: ...
    def restore_sqlite(self, lease: Lease, deadline: int) -> None: ...


class Runner:
    DECISION_SECONDS = 45 * 60
    WINDOW_SECONDS = 60 * 60
    APPROVAL_SECONDS = 5 * 60

    def __init__(self, journal: Journal, identity: Identity, adapter: Adapter, clock: Callable[[], float] = time.time):
        self.journal = journal
        self.identity = identity
        self.adapter = adapter
        self.clock = clock

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
        expected_phase = {"freeze": "planned", "promote": "ready"}[action]
        self._require_state(state, {expected_phase})
        expires = now + self.APPROVAL_SECONDS
        if action == "promote":
            expires = min(expires, state["started_at"] + self.DECISION_SECONDS)
        if state["pending"] is not None or expires <= now or now < state["last_observed_at"]:
            raise UnsafeCutover("No approval may be issued for the current journal state")
        evidence = state["preflight_sha256"] if action == "freeze" else digest(state["evidence"])
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

    def _finish(self, state: dict, after: Observation, phase: str) -> None:
        finished_at = max(state["last_observed_at"], int(self.clock()))
        state["history"].append({**state["pending"], "after": asdict(after), "phase": phase,
                                 "finished_at": finished_at})
        state["last_observed_at"] = finished_at
        state["pending"] = None
        state["phase"] = phase
        state["challenge"] = uuid.uuid4().hex
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
        self.adapter.restore_sqlite(lease, rollback_deadline)
        after = self.adapter.observe()
        if not after.sqlite_live():
            raise UnsafeCutover("SQLite restoration could not be verified")
        self._finish(state, after, "sqlite_authoritative")

    def perform(self, action: str, approval: Approval | None = None) -> dict:
        if action not in {"freeze", "prepare", "promote", "tick", "abort"}:
            raise ValueError("Unknown cutover action")
        with self.journal.acquire() as (_, lease):
            state = self.status()
            self._verify_identity()
            self.adapter.require_quiescent()
            before = self.adapter.observe()
            now = int(self.clock())
            clock_reversed = now < state["last_observed_at"]
            state["last_observed_at"] = max(now, state["last_observed_at"])
            state["generation"] = lease.generation
            self.journal.write(state)

            if state["writes_possible"] or before.mysql_writable or before.mysql_business_activity:
                if state["phase"] == "mysql_authoritative" and action == "tick" and before.mysql_live():
                    return state
                if state["phase"] != "mysql_authoritative" or not before.mysql_live():
                    self._record_recovery(state)
                raise UnsafeCutover("MySQL writes may exist. Use separately verified recovery")
            if state["phase"] == "sqlite_authoritative":
                if action not in {"tick", "abort"} or not before.sqlite_live():
                    raise UnsafeCutover("Completed SQLite rollback cannot be re-run or verified")
                return state

            started = state["started_at"]
            if started is not None and (
                clock_reversed or now >= started + self.DECISION_SECONDS or action == "abort"
            ):
                self._rollback(state, lease, before, state["last_observed_at"])
                return state
            if action == "tick":
                return state
            if state["pending"] is not None:
                raise UnsafeCutover("Interrupted action requires a verified pre-write abort")
            if clock_reversed:
                raise UnsafeCutover("Host clock moved backwards")

            if action == "freeze":
                self._freeze(state, lease, before, approval, now)
            elif action == "prepare":
                self._prepare(state, lease, before, now)
            elif action == "promote":
                self._promote(state, lease, before, approval, now)
            else:
                raise UnsafeCutover("No freeze is active")
            return state

    def _freeze(self, state: dict, lease: Lease, before: Observation, approval: Approval | None, now: int) -> None:
        self._require_approval(state, "freeze", approval, now)
        if not before.sqlite_live() or self.adapter.preflight_sha256() != state["preflight_sha256"]:
            raise UnsafeCutover("SQLite preflight changed after approval")
        now = int(self.clock())
        self._require_approval(state, "freeze", approval, now)
        state["started_at"] = now
        self._intent(state, "freeze", before, now, approval=asdict(approval))
        self.adapter.freeze(lease, now + self.DECISION_SECONDS)
        after = self.adapter.observe()
        if not after.frozen() or not now <= self.clock() < now + self.DECISION_SECONDS:
            raise UnsafeCutover("Could not establish the source, target and deploy freeze in time")
        self._finish(state, after, "frozen")

    def _prepare(self, state: dict, lease: Lease, before: Observation, now: int) -> None:
        self._require_state(state, {"frozen"})
        if not before.frozen():
            raise UnsafeCutover("Writer freeze was lost")
        deadline = state["started_at"] + self.DECISION_SECONDS
        self._intent(state, "prepare", before, now)
        self.adapter.prepare(lease, deadline)
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
        evidence = self.adapter.evidence()
        evidence.require_verified()
        if asdict(evidence) != state["evidence"]:
            raise UnsafeCutover("Verification evidence changed after approval")
        self._verify_identity()
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
            self.adapter.enable_mysql(lease, state["started_at"] + self.WINDOW_SECONDS)
            after = self.adapter.observe()
            if not after.mysql_live():
                raise UnsafeCutover("MySQL admission could not be verified")
        except Exception:
            self._record_recovery(state)
            raise
        self._finish(state, after, "mysql_authoritative")
