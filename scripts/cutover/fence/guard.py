"""Root-installed, read-only admission check for the cutover journal."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import stat
import sys
from contextlib import contextmanager
from pathlib import Path

ENTRYPOINT = Path("/opt/repro-cutover-fence/guard.py")
POLICY = Path("/etc/repro-cutover-fence/policy.json")
STATE = Path("/var/lib/repro-cutover-fence")
EVIDENCE = Path("/run/repro-cutover-fence/verified-engine.json")
MAX_RECORD_BYTES = 1024 * 1024
ACTIONS = ("deploy", "scheduler", "web-writer", "queue-worker")
IDENTITY_FIELDS = {"run_id", "release_sha", "artifact_sha256", "source_sha256", "config_sha256", "target_uuid"}


class Refused(RuntimeError):
    pass


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
           boot_id: str, action: str, expected_engine: str) -> None:
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


def check(action: str, expected_engine: str) -> None:
    require(sys.platform == "linux" and os.geteuid() == 0 and sys.flags.isolated and sys.flags.no_site,
            "Guard requires Linux root with the isolated system interpreter")
    import fcntl

    require(Path(__file__).absolute() == ENTRYPOINT, "Use the fixed root-installed guard entrypoint")
    check_chain(ENTRYPOINT)
    interpreter = Path(sys.executable).resolve()
    require(interpreter.parent == Path("/usr/bin") and matches(r"python3(?:\.\d+)?", interpreter.name),
            "Guard requires the system Python 3 interpreter")
    check_chain(interpreter)
    private = STATE.lstat()
    trusted(private, directory=True)
    require(not private.st_mode & 0o077, "Cutover journal directory is not root-private")
    with open_protected(STATE / "operator.lock") as lock:
        fcntl.flock(lock, fcntl.LOCK_SH | fcntl.LOCK_NB)
        verify(
            read_protected(POLICY), read_protected(STATE / "state.json"), read_protected(EVIDENCE),
            machine_sha=sha256(read_protected(Path("/etc/machine-id"))),
            boot_id=Path("/proc/sys/kernel/random/boot_id").read_text(encoding="ascii").strip(),
            action=action, expected_engine=expected_engine,
        )


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
