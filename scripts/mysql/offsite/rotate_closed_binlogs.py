#!/usr/bin/env python3
"""Request one reviewed binary-log rotation on the pinned local MySQL socket."""
from __future__ import annotations

import argparse
from contextlib import suppress
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import threading

sys.path.insert(0, str(Path(__file__).resolve().parent))
import collect_closed_binlogs as collector
import publish_backup as backup


CALLER = "repro_binlog_rotator"
DEFINER = "repro_binlog_definer"
ROUTINE = "repro_archive_control.rotate_binary_log"
TARGET = f"`{CALLER}`@`localhost`"
REQUIRED_GRANTS = {
    f"GRANT REPLICATION CLIENT ON *.* TO {TARGET}",
    f"GRANT EXECUTE ON PROCEDURE `repro_archive_control`.`rotate_binary_log` TO {TARGET}",
}
OPTIONAL_GRANT = f"GRANT USAGE ON *.* TO {TARGET}"
PLAN_FIELDS = {"version", "collector_plan", "collector_plan_sha256", "defaults_file", "runtime_dir",
               "timeout_seconds", "routine_created_epoch", "routine_altered_epoch"}
IDENTITY = (
    "SELECT 'repro-rotation-source',CURRENT_USER(),CURRENT_ROLE(),CONNECTION_ID(),VERSION(),@@server_uuid,"
    "@@server_id,@@global.log_bin,@@global.skip_networking,@@global.binlog_checksum,@@global.binlog_encryption,"
    "@@global.log_bin_basename,@@global.binlog_expire_logs_auto_purge,@@global.mandatory_roles;"
)
ROUTINE_QUERY = ("SELECT 'repro-rotation-routine',DEFINER,SECURITY_TYPE,ROUTINE_TYPE,UNIX_TIMESTAMP(CREATED),"
                 "UNIX_TIMESTAMP(LAST_ALTERED) FROM information_schema.ROUTINES "
                 "WHERE ROUTINE_SCHEMA='repro_archive_control' AND ROUTINE_NAME='rotate_binary_log' AND ROUTINE_TYPE='PROCEDURE';")
PRE_END = "repro-rotation-preflight-end"
POST_END = "repro-rotation-observed-end"
PRECHECK = IDENTITY + ROUTINE_QUERY + "SHOW GRANTS;SHOW BINARY LOG STATUS;SELECT '" + PRE_END + "';"
COMMAND = "CALL " + ROUTINE + "();" + IDENTITY + "SHOW BINARY LOG STATUS;SELECT '" + POST_END + "';"
LINE_LIMIT = 4096


class RotationUnverified(backup.Refusal):
    def __init__(self, before: str):
        super().__init__("rotation was requested but its exact outcome could not be verified")
        self.before = before


def parse_plan(raw: bytes, pin: str) -> dict:
    backup.need(0 < len(raw) <= collector.PLAN_LIMIT and backup.sha256(raw) == pin, "rotation plan pin or size differs")
    plan = json.loads(raw)
    backup.need(isinstance(plan, dict) and set(plan) == PLAN_FIELDS and type(plan["version"]) is int
                and plan["version"] == 1, "unexpected rotation plan fields")
    for field in ("collector_plan", "defaults_file", "runtime_dir"):
        value = plan[field]
        backup.need(isinstance(value, str) and 0 < len(value) <= 4096
                    and re.fullmatch(r"/[A-Za-z0-9_./-]+", value) is not None
                    and Path(value).as_posix() == value and ".." not in Path(value).parts, "invalid exact rotation plan path")
    backup.need(isinstance(plan["collector_plan_sha256"], str) and backup.HEX.fullmatch(plan["collector_plan_sha256"])
                and type(plan["timeout_seconds"]) is int and 3 <= plan["timeout_seconds"] <= 60
                and all(type(plan[field]) is int and 0 < plan[field] <= 2**32 - 1
                        for field in ("routine_created_epoch", "routine_altered_epoch"))
                and plan["routine_created_epoch"] <= plan["routine_altered_epoch"], "invalid rotation plan bound or routine pin")
    return plan


def read_plan(path: Path, pin: str) -> dict:
    from scheduled_backup import trusted_file

    trusted_file(path, plan=True)
    with path.open("rb") as stream:
        return parse_plan(stream.read(collector.PLAN_LIMIT + 1), pin)


def send(stream, statement: str) -> None:
    stream.write(statement.encode("ascii") + b"\n")
    stream.flush()


def until(stream, stop: str) -> list[str]:
    rows = []
    for _ in range(12):
        line = stream.readline(LINE_LIMIT + 1)
        backup.need(0 < len(line) <= LINE_LIMIT and line.endswith(b"\n"), "unexpected MySQL response framing")
        value = line.removesuffix(b"\n").removesuffix(b"\r").decode("utf-8")
        if value == stop:
            return rows
        rows.append(value)
    raise backup.Refusal("unexpected number of MySQL responses")


def connection_id(line: str, source: dict) -> str:
    fields = line.split("\t")
    backup.need(len(fields) == 14 and re.fullmatch(r"[1-9][0-9]{0,19}", fields[3]) is not None,
                "unexpected rotation session metadata")
    expected = ["repro-rotation-source", CALLER + "@localhost", "NONE", fields[3], source["server_version"],
                source["identity"], str(source["server_id"]), "1", "1", "CRC32", "0", source["binlog_base"], "0", ""]
    backup.need(fields == expected, "rotation source, session account, or no-purge configuration differs")
    return fields[3]


def active_file(line: str, source: dict) -> str:
    parts = line.split("\t")
    backup.need(len(parts) == 5 and re.fullmatch(r"[0-9]{1,10}", parts[1]) is not None
                and 4 <= int(parts[1]) <= collector.staging.chain.MAX_POSITION, "unexpected active binary log coordinate")
    name = collector.staging.chain.log_name(parts[0])
    backup.need(name.rsplit(".", 1)[0] == Path(source["binlog_base"]).name, "active file belongs to another binlog source")
    return name


def exchange(plan: dict, source: dict, stdin, stdout, prove_socket) -> tuple[str, str]:
    send(stdin, PRECHECK)
    before = until(stdout, PRE_END)
    backup.need(len(before) in (5, 6), "unexpected preflight observations")
    session = connection_id(before[0], source)
    routine = ["repro-rotation-routine", DEFINER + "@localhost", "DEFINER", "PROCEDURE",
               str(plan["routine_created_epoch"]), str(plan["routine_altered_epoch"])]
    backup.need(before[1].split("\t") == routine, "reviewed rotation routine definition metadata differs")
    grants = set(before[2:-1])
    backup.need(len(grants) == len(before[2:-1]) and REQUIRED_GRANTS <= grants <= REQUIRED_GRANTS | {OPTIONAL_GRANT},
                "rotation credential grants differ from the reviewed narrow account")
    current = active_file(before[-1], source)
    prove_socket()

    try:
        send(stdin, COMMAND)
        after = until(stdout, POST_END)
        backup.need(len(after) == 2 and connection_id(after[0], source) == session,
                    "rotation client did not remain on the verified connection")
        new_active = active_file(after[1], source)
        backup.need(new_active == collector.staging.chain.next_log(current), "rotation did not advance by exactly one file")
        prove_socket()
        return current, new_active
    except (backup.Refusal, OSError, ValueError, KeyboardInterrupt) as error:
        raise RotationUnverified(current) from error


def rotate(plan: dict) -> dict:
    import fcntl
    from scheduled_backup import state_lock, trusted_file

    source = collector.read_plan(Path(plan["collector_plan"]), plan["collector_plan_sha256"])
    directory = Path(plan["runtime_dir"])
    backup.private_path(directory, directory=True)
    backup.need(directory != Path(source["spool_dir"]) and plan["defaults_file"] != source["defaults_file"],
                "rotation needs a separate runtime directory and credential file")
    trusted_file(Path(source["mysql"]))
    socket = Path(source["socket"])
    expected_socket = collector.stat_identity(collector.protected_source(socket, source["mysql_owner_uid"], socket=True))

    def prove_socket():
        backup.need(expected_socket == collector.stat_identity(collector.protected_source(
                    socket, source["mysql_owner_uid"], socket=True)), "rotation socket changed")

    with os.fdopen(state_lock(directory / "rotation.lock", os.O_RDWR | os.O_CREAT), "r+b") as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise backup.Refusal("a rotation is already running") from error
        with tempfile.TemporaryDirectory(dir=directory, prefix=".rotation-") as scratch:
            options = Path(scratch) / "rotation.cnf"
            collector.prepare_credentials(plan, options)
            backup.need(f"user={CALLER}".encode("ascii") == options.read_bytes().splitlines()[1],
                        "rotation credential is not the expected dedicated account")
            args = [source["mysql"], "--defaults-file=" + str(options), "--no-login-paths", "--protocol=SOCKET",
                    "--socket=" + str(socket), "--batch", "--raw", "--skip-column-names", "--binary-mode",
                    "--unbuffered", "--skip-reconnect", "--connect-timeout=" + str(plan["timeout_seconds"])]
            with subprocess.Popen(args, env=backup.SAFE_ENV, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                  stderr=subprocess.DEVNULL) as client:
                timer = threading.Timer(plan["timeout_seconds"], client.kill)
                timer.daemon = True
                timer.start()
                current = None
                try:
                    current, active = exchange(plan, source, client.stdin, client.stdout, prove_socket)
                    client.stdin.close()
                    backup.need(client.wait(timeout=plan["timeout_seconds"]) == 0, "rotation client did not finish cleanly")
                except (backup.Refusal, OSError, ValueError, subprocess.SubprocessError, KeyboardInterrupt) as error:
                    if current is not None:
                        raise RotationUnverified(current) from error
                    raise
                finally:
                    timer.cancel()
                    with suppress(OSError, subprocess.SubprocessError):
                        if client.poll() is None:
                            client.kill()
                        client.wait(timeout=5)
    return {"result": "closed-binlog-rotation-verified", "closed_file": current, "active_file": active,
            "rotation_requested": True, "remote_accessed": False}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plan", type=Path, required=True)
    parser.add_argument("--plan-sha256", required=True)
    args = parser.parse_args()
    os.umask(0o077)
    try:
        result = rotate(read_plan(args.plan, args.plan_sha256))
    except RotationUnverified as error:
        result = {"result": "closed-binlog-rotation-unverified", "closed_candidate": error.before,
                  "reason": str(error), "rotation_requested": True, "remote_accessed": False}
    except backup.Refusal as error:
        result = {"result": "REFUSED", "reason": str(error), "rotation_requested": False, "remote_accessed": False}
    except (OSError, ValueError, TypeError, RecursionError, subprocess.SubprocessError, KeyboardInterrupt):
        result = {"result": "REFUSED", "reason": "rotation refused before a verified request",
                  "rotation_requested": False, "remote_accessed": False}
    print(json.dumps(result, sort_keys=True))
    return int(result["result"] != "closed-binlog-rotation-verified")


if __name__ == "__main__":
    raise SystemExit(main())
