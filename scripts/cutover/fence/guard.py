"""Root-installed cutover admission check and authority evidence refresh."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import stat
import sys
import uuid
from collections.abc import Callable, Iterator
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path

ENTRYPOINT = Path("/opt/repro-cutover-fence/guard.py")
POLICY = Path("/etc/repro-cutover-fence/policy.json")
STATE = Path("/var/lib/repro-cutover-fence")
EVIDENCE = Path("/run/repro-cutover-fence/verified-engine.json")
ACTIVATION = Path("/run/repro-cutover-fence/verified-activation.json")
MACHINE_ID = Path("/etc/machine-id")
BOOT_ID = Path("/proc/sys/kernel/random/boot_id")
# Controllers (runner, watchdog, refresh) serialize on this lock. Writers share WRITER_LOCK.
CONTROLLER_LOCK = "controller.lock"
WRITER_LOCK = "operator.lock"
MAX_RECORD_BYTES = 1024 * 1024
ACTIONS = ("deploy", "scheduler", "web-writer", "queue-worker")
IDENTITY_FIELDS = {"run_id", "release_sha", "artifact_sha256", "source_sha256", "config_sha256", "target_uuid"}
ACTIVATION_FIELDS = {
    "version", "boot_id", "policy_sha256", "journal_sha256", "authority_sha256", "engine",
    "application_verified", "reconciliation_verified",
}


class Refused(RuntimeError):
    pass


@dataclass(frozen=True)
class HostAuthority:
    """Independent observations gathered by the trusted host adapter."""

    engine: str
    database_identity: str
    live_config_sha256: str
    release_sha: str
    artifact_sha256: str
    competing_writer_detected: bool
    admissions_open: bool
    workers_stopped: bool
    deployment_fenced: bool
    sqlite_writable: bool
    mysql_writable: bool


def require(condition: bool, message: str) -> None:
    if not condition:
        raise Refused(message)


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def matches(pattern: str, value: object) -> bool:
    return isinstance(value, str) and re.fullmatch(pattern, value) is not None


def valid_uuid(value: object) -> bool:
    return isinstance(value, str) and matches(r"[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}", value)


def trusted(info: os.stat_result, *, directory: bool = False) -> None:
    kind = stat.S_ISDIR if directory else stat.S_ISREG
    require(kind(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
            "Unsafe file type, owner or permissions in the guard chain")


def check_chain(path: Path, *, directory: bool = False) -> None:
    require(path.is_absolute() and ".." not in path.parts, "Guard paths must be fixed and absolute")
    for parent in reversed(path.parents):
        trusted(parent.lstat(), directory=True)
    trusted(path.lstat(), directory=directory)


@contextmanager
def open_protected(path: Path):
    check_chain(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK)
    try:
        trusted(os.fstat(descriptor))
        yield descriptor
    finally:
        os.close(descriptor)


def read_protected(path: Path) -> bytes:
    with open_protected(path) as descriptor:
        # Bound the read, including in case of malformed or unexpectedly large state.
        with os.fdopen(os.dup(descriptor), "rb") as stream:
            content = stream.read(MAX_RECORD_BYTES + 1)
        require(0 < len(content) <= MAX_RECORD_BYTES, "Missing or oversized guard record")
        return content


def unique_fields(pairs: list[tuple[str, object]]) -> dict:
    record = dict(pairs)
    require(len(record) == len(pairs), "Duplicate fields in guard record")
    return record


def refuse_non_json_constant(_value: str):
    raise Refused("Non-JSON value in guard record")


def read_json(raw: bytes) -> dict:
    record = json.loads(raw.decode("utf-8"), object_pairs_hook=unique_fields, parse_constant=refuse_non_json_constant)
    require(isinstance(record, dict) and type(record.get("version")) is int and record["version"] == 1,
            "Unknown or malformed guard record")
    return record


def authority(policy: dict, journal: dict, action: str) -> str:
    identity = policy.get("identity")
    require(isinstance(identity, dict) and identity.keys() == IDENTITY_FIELDS
            and matches(r"[A-Za-z0-9][A-Za-z0-9_-]{0,63}", identity["run_id"])
            and matches(r"[0-9a-f]{40}", identity["release_sha"])
            and all(matches(r"[0-9a-f]{64}", identity[key]) for key in
                    ("artifact_sha256", "source_sha256", "config_sha256"))
            and valid_uuid(identity["target_uuid"]),
            "Malformed pinned cutover identity")
    require(journal.get("identity") == identity, "Cutover journal identity differs from policy")
    require(type(journal.get("generation")) is int and journal["generation"] > 0
            and "pending" in journal and journal["pending"] is None,
            "The cutover has an unfinished action or invalid generation")
    phase = journal.get("phase")
    writes_possible = journal.get("writes_possible")
    if (phase == "planned" and writes_possible is False and "started_at" in journal
            and journal["started_at"] is None and journal.get("history") == []):
        require(action != "deploy", "Deploys are held for the planned cutover")
        return "sqlite"

    terminal = {"sqlite_authoritative": ("sqlite", False, "abort"), "mysql_authoritative": ("mysql", True, "promote")}
    require(isinstance(phase, str) and phase in terminal, "Cutover phase holds all admissions")
    engine, expected_marker, final_action = terminal[phase]
    history = journal.get("history")
    require(writes_possible is expected_marker and type(journal.get("started_at")) is int
            and isinstance(history, list) and bool(history) and isinstance(history[-1], dict)
            and history[-1].get("action") == final_action and history[-1].get("phase") == phase,
            "Terminal phase has no verified final action or expected write marker")
    observed = history[-1].get("after")
    require(isinstance(observed, dict) and observed.get("engine") == engine
            and observed.get("admissions_open") is False
            and observed.get("workers_stopped") is True
            and observed.get("deployment_fenced") is True
            and observed.get("mysql_business_activity") is False
            and observed.get("sqlite_writable") is (engine == "sqlite")
            and observed.get("mysql_writable") is (engine == "mysql"),
            "Terminal phase does not establish a sealed single writable authority")
    return engine


def verify(policy_raw: bytes, journal_raw: bytes, evidence_raw: bytes, *, machine_sha: str,
           boot_id: str, action: str, expected_engine: str, activation_raw: bytes | None = None) -> None:
    require(action in ACTIONS and expected_engine in ("sqlite", "mysql"), "Unknown admission request")
    policy, journal, evidence = map(read_json, (policy_raw, journal_raw, evidence_raw))
    require(matches(r"[0-9a-f]{64}", machine_sha) and policy.get("machine_id_sha256") == machine_sha,
            "Guard policy belongs to a different host")
    engine = authority(policy, journal, action)
    require(engine == expected_engine, "Requested engine is not authoritative in the journal")
    identity = policy["identity"]
    expected_config = policy.get(f"{engine}_live_config_sha256")
    require(matches(r"[0-9a-f]{64}", expected_config), "Authoritative configuration is unpinned")
    require(valid_uuid(boot_id) and evidence.get("boot_id") == boot_id
            and evidence.get("policy_sha256") == sha256(policy_raw)
            and evidence.get("journal_sha256") == sha256(journal_raw),
            "Authority evidence does not match this boot, policy or journal")
    expected_db = identity["source_sha256"] if engine == "sqlite" else identity["target_uuid"]
    require(evidence.get("engine") == engine and evidence.get("database_identity") == expected_db
            and evidence.get("live_config_sha256") == expected_config
            and evidence.get("release_sha") == identity["release_sha"]
            and evidence.get("artifact_sha256") == identity["artifact_sha256"]
            and evidence.get("competing_writer_detected") is False,
            "Authority evidence failed the engine, configuration, release or writer checks")
    if action == "deploy":
        require(activation_raw is not None, "Deploys are held until application activation is verified")
        activation = read_json(activation_raw)
        require(activation.keys() == ACTIVATION_FIELDS
                and activation["boot_id"] == boot_id
                and activation["policy_sha256"] == sha256(policy_raw)
                and activation["journal_sha256"] == sha256(journal_raw)
                and activation["authority_sha256"] == sha256(evidence_raw)
                and activation["engine"] == engine
                and activation["application_verified"] is True
                and activation["reconciliation_verified"] is True,
                "Deploys are held because activation evidence is stale or incomplete")


def _require_root_installation() -> None:
    require(sys.platform == "linux" and os.geteuid() == 0 and sys.flags.isolated and sys.flags.no_site,
            "Guard requires Linux root with the isolated system interpreter")
    require(Path(__file__).absolute() == ENTRYPOINT, "Use the fixed root-installed guard entrypoint")
    check_chain(ENTRYPOINT)
    interpreter = Path(sys.executable).resolve()
    require(interpreter.parent == Path("/usr/bin") and matches(r"python3(?:\.\d+)?", interpreter.name),
            "Guard requires the system Python 3 interpreter")
    check_chain(interpreter)
    private = STATE.lstat()
    trusted(private, directory=True)
    require(not private.st_mode & 0o077, "Cutover journal directory is not root-private")


def _invalidate(directory: int, filename: str) -> None:
    try:
        trusted(os.stat(filename, dir_fd=directory, follow_symlinks=False))
    except FileNotFoundError:
        return
    os.unlink(filename, dir_fd=directory)
    os.fsync(directory)


def _publish(directory: int, record: bytes) -> None:
    temporary = f".proof-{uuid.uuid4().hex}"
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
                         0o600, dir_fd=directory)
    try:
        with os.fdopen(descriptor, "wb") as stream:
            stream.write(record)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, EVIDENCE.name, src_dir_fd=directory, dst_dir_fd=directory)
        os.fsync(directory)
    except BaseException:
        # An error after rename must not leave usable authority after the lock is released.
        _invalidate(directory, EVIDENCE.name)
        raise
    finally:
        try:
            os.unlink(temporary, dir_fd=directory)
        except FileNotFoundError:
            pass


def refresh_engine_evidence(observe: Callable[[str], HostAuthority],
                            precondition: Callable[[], None] | None = None) -> None:
    """Refresh committed-journal authority while a trusted adapter has drained writers.

    The caller supplies a bounded read-only host probe. This entrypoint owns
    the exclusive flock and is separate from a runner's pending transition.
    The optional precondition runs once both locks are held and before any
    evidence is revoked, so its checks cannot race another controller; it
    raises to leave the current proof untouched.
    """
    _require_root_installation()
    import fcntl

    require(ACTIVATION.parent == EVIDENCE.parent, "Evidence files must share a protected directory")
    check_chain(EVIDENCE.parent, directory=True)
    controller_path = STATE / CONTROLLER_LOCK
    lock_path = STATE / WRITER_LOCK
    # Same order as the runner: the controller lock first, then the writer lock.
    with open_protected(controller_path) as controller, open_protected(lock_path) as lock:
        fcntl.flock(controller, fcntl.LOCK_EX | fcntl.LOCK_NB)
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if precondition is not None:
            precondition()
        directory = os.open(EVIDENCE.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
        try:
            trusted(os.fstat(directory), directory=True)
            _invalidate(directory, EVIDENCE.name)
            _invalidate(directory, ACTIVATION.name)
            policy_raw = read_protected(POLICY)
            journal_raw = read_protected(STATE / "state.json")
            machine_sha = sha256(read_protected(MACHINE_ID))
            boot_id = BOOT_ID.read_text(encoding="ascii").strip()
            policy, journal = map(read_json, (policy_raw, journal_raw))
            require(valid_uuid(boot_id) and policy.get("machine_id_sha256") == machine_sha,
                    "Current boot or policy host identity is invalid")
            engine = authority(policy, journal, "web-writer")
            observed = observe(engine)
            require(isinstance(observed, HostAuthority)
                    and observed.admissions_open is False and observed.workers_stopped is True
                    and observed.deployment_fenced is True
                    and observed.sqlite_writable is (engine == "sqlite")
                    and observed.mysql_writable is (engine == "mysql"),
                    "Host probe did not establish a sealed single-engine route")
            record = {
                "version": 1, "boot_id": boot_id, "policy_sha256": sha256(policy_raw),
                "journal_sha256": sha256(journal_raw), "engine": observed.engine,
                "database_identity": observed.database_identity, "live_config_sha256": observed.live_config_sha256,
                "release_sha": observed.release_sha, "artifact_sha256": observed.artifact_sha256,
                "competing_writer_detected": observed.competing_writer_detected,
            }
            evidence_raw = (json.dumps(record, sort_keys=True, allow_nan=False) + "\n").encode("utf-8")
            verify(policy_raw, journal_raw, evidence_raw, machine_sha=machine_sha, boot_id=boot_id,
                   action="web-writer", expected_engine=engine)
            require(policy_raw == read_protected(POLICY) and journal_raw == read_protected(STATE / "state.json")
                    and machine_sha == sha256(read_protected(MACHINE_ID))
                    and boot_id == BOOT_ID.read_text(encoding="ascii").strip(),
                    "Authority inputs changed during the host probe")
            require(os.path.samestat(os.fstat(lock), lock_path.lstat())
                    and os.path.samestat(os.fstat(controller), controller_path.lstat())
                    and os.path.samestat(os.fstat(directory), EVIDENCE.parent.lstat()),
                    "Authority paths changed during the host probe")
            _publish(directory, evidence_raw)
        finally:
            os.close(directory)


def _verify_admission(action: str, expected_engine: str) -> None:
    try:
        activation_raw = read_protected(ACTIVATION) if action == "deploy" else None
    except FileNotFoundError:
        activation_raw = None
    verify(
        read_protected(POLICY), read_protected(STATE / "state.json"), read_protected(EVIDENCE),
        machine_sha=sha256(read_protected(MACHINE_ID)),
        boot_id=BOOT_ID.read_text(encoding="ascii").strip(),
        action=action, expected_engine=expected_engine, activation_raw=activation_raw,
    )


@contextmanager
def admitted(action: str, expected_engine: str) -> Iterator[int]:
    """Hold a verified shared lease for the bounded operation in this scope."""
    _require_root_installation()
    import fcntl

    # Refuse before the lock when the current records already refuse. Refusing
    # is always safe, and flock has no writer preference: a crowd of refused
    # checks holding the shared lock would starve a drain. Any other read
    # problem is settled under the lock below, which alone can admit.
    try:
        _verify_admission(action, expected_engine)
    except Refused:
        raise
    except (OSError, ValueError, UnicodeError, RecursionError):
        pass

    # Shared lock first, then the journal: a writer that reads state after a
    # hold is refused, and one admitted earlier holds the lock a drain waits for.
    with open_protected(STATE / WRITER_LOCK) as lock:
        fcntl.flock(lock, fcntl.LOCK_SH | fcntl.LOCK_NB)
        _verify_admission(action, expected_engine)
        yield lock


def check(action: str, expected_engine: str) -> None:
    with admitted(action, expected_engine):
        pass


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=ACTIONS)
    parser.add_argument("engine", choices=("sqlite", "mysql"))
    args = parser.parse_args()
    try:
        check(args.action, args.engine)
    except Refused as error:
        print(f"Cutover admission held: {error}", file=sys.stderr)
        return 1
    except (OSError, ValueError, UnicodeError, RecursionError):
        print("Cutover admission held: lock, host evidence or journal is unavailable or invalid", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
