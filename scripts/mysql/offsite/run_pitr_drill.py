#!/usr/bin/env python3
"""Run one bucket-only point-in-time restore drill and write the replay.json evidence set.

Order: restore the base and each binlog batch from the bucket (restore_backup.py,
read-only key), assemble the chain from the base's start coordinate to the chosen
stop, verify it (verify_mysql_binlog_chain.py), load the base into a fresh MySQL
8.4 instance, replay with mysqlbinlog --verify-binlog-checksum to the stop
position, verify the heartbeat (heartbeat.py verify), then check the chosen
transaction is present and the planted bad write is absent. One monotonic clock
runs from the first retrieval to the verified target. The output folder holds
artifacts.json, chain.json, heartbeat.json and replay.json in the shape that
scripts/cutover/readiness/summarize_point_in_time.py reads. The source server is
never contacted; every input comes from the restore outputs.
"""
from __future__ import annotations

import argparse
import gzip
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import threading
import time

TOOLS = Path(__file__).resolve().parent
sys.path.insert(0, str(TOOLS))
import publish_backup as backup
import verify_mysql_binlog_chain as chain

VERIFIED = "native-mysql-replay-verified"
FAILED = "native-mysql-replay-failed"
PLAN_FIELDS = {"version", "run_id", "destination_sha256", "source_uuid", "database", "server_id", "base", "binlogs",
               "target_utc", "stop", "chosen_check", "planted_check", "work_dir", "rclone", "crypt_remote",
               "database_config", "database_destination", "binlog_config", "binlog_destination", "target",
               "heartbeat", "timeout_seconds"}


def write(path: Path, raw: bytes) -> str:
    with open(path, "xb") as stream:
        stream.write(raw)
    return backup.sha256(raw)


def restored_logs(manifest_path: Path, folder: Path) -> dict:
    manifest = json.loads(manifest_path.read_bytes())
    return {item["path"]: (folder / item["path"], item["bytes"], item["sha256"])
            for item in manifest["artifacts"] if chain.BINLOG.fullmatch(item["path"])}


def run_drill(plan: dict, steps, out: Path, *, clock=time.monotonic_ns) -> dict:
    out.mkdir(mode=0o700)
    work = Path(plan["work_dir"])
    work.mkdir(mode=0o700)
    record = {"result": FAILED, "run_id": plan["run_id"], "destination_sha256": plan["destination_sha256"],
              "source_uuid": plan["source_uuid"], "target_utc": plan["target_utc"], "replayed_stop": plan["stop"],
              "recovery_started_monotonic_ns": clock()}
    step = "restore"
    try:
        artifacts_sha = write(out / "artifacts.json", steps.restore(plan, plan["base"], plan["source_uuid"], work / "base"))
        base = work / "base" / "artifacts"
        producer = (base / "database-backup.json").read_bytes()
        point = json.loads(producer)["pitr"]
        start_file, start_position = chain.log_name(point["binlog_file"]), point["binlog_position"]
        segments = {}
        for index, item in enumerate(plan["binlogs"]):
            folder = work / f"binlog-{index}"
            steps.restore(plan, item, plan["source_uuid"] + ".closed-binlogs", folder)
            segments |= restored_logs(folder / "input-manifest.json", folder / "artifacts")
        names, name = [], start_file
        while True:
            backup.need(name in segments, "the restored batches do not cover the base start through the stop file")
            names.append(name)
            if name == plan["stop"]["file"]:
                break
            backup.need(len(names) < 4096, "the chain from the base start is too long")
            name = chain.next_log(name)
        step = "verify_chain"
        inputs = work / "replay-inputs"
        inputs.mkdir(mode=0o700)
        (inputs / "database-backup.json").write_bytes(producer)
        for name in names:
            shutil.copyfile(segments[name][0], inputs / name)
        inventory = backup.encode({"schema": chain.SCHEMA, "source_uuid": plan["source_uuid"], "server_id": plan["server_id"],
                                   "start": {"file": start_file, "position": start_position}, "stop": plan["stop"],
                                   "segments": [{"file": name, "bytes": segments[name][1], "sha256": segments[name][2]}
                                                for name in names]})
        write(inputs / chain.INVENTORY, inventory)
        for path in inputs.iterdir():
            os.chmod(path, 0o600)
        chain_raw = steps.verify_chain(plan, inputs, backup.sha256(inventory), backup.sha256(producer))
        backup.need(json.loads(chain_raw).get("result") == "closed-mysql-binlog-chain-verified", "chain did not verify")
        chain_sha = write(out / "chain.json", chain_raw)
        step = "load_base"
        steps.load_base(plan, base / "database.sql.gz")
        step = "replay"
        steps.replay(plan, [inputs / name for name in names], start_position, plan["stop"]["position"])
        step = "heartbeat"
        heartbeat_raw = steps.heartbeat(plan)
        backup.need(json.loads(heartbeat_raw).get("result") == "recovered-heartbeat-verified", "heartbeat did not verify")
        heartbeat_sha = write(out / "heartbeat.json", heartbeat_raw)
        step = "checks"
        restored = steps.query(plan, "SELECT @@server_uuid")
        chosen = steps.query(plan, plan["chosen_check"]["sql"]) == plan["chosen_check"]["expected"]
        planted = plan["planted_check"]
        excluded = planted is not None and steps.query(plan, planted["sql"]) == planted["expected"]
        record.update(restored_uuid=restored, offsite_only=True, independent_restore=restored != plan["source_uuid"],
                      chosen_transaction_verified=chosen, planted_bad_write_excluded=excluded,
                      recovery_verified_monotonic_ns=clock(), artifacts_sha256=artifacts_sha, chain_sha256=chain_sha,
                      heartbeat_sha256=heartbeat_sha)
        if restored != plan["source_uuid"] and chosen and (planted is None or excluded):
            record["result"] = VERIFIED
        else:
            record.update(failed_step="checks", reason="a restored-data check did not match")
    except backup.Refusal as error:
        record.update(failed_step=step, reason=str(error)[:200])
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError):
        record.update(failed_step=step, reason="drill input, tool or local I/O failure")
    write(out / "replay.json", backup.encode(record))
    return record


class StockSteps:
    """The reviewed tools and the pinned MySQL 8.4 client binaries, with fixed argv."""

    def __init__(self, plan: dict):
        self.plan = plan

    def tool(self, name: str, *args, stdin=None) -> bytes:
        result = subprocess.run(["/usr/bin/python3", "-I", "-B", str(TOOLS / name), *map(str, args)], env=backup.SAFE_ENV,
                                stdin=stdin or subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                timeout=self.plan["timeout_seconds"], check=False)
        backup.need(result.returncode == 0, f"{name} refused")
        return result.stdout

    def restore(self, plan, item, identity, output: Path) -> bytes:
        binlog = identity.endswith(".closed-binlogs")
        return self.tool("restore_backup.py", "--backup-id", item["backup_id"], "--scope", "database",
                         "--expected-release", item["release"], "--expected-identity", identity,
                         "--manifest-bytes", item["manifest_bytes"], "--manifest-sha256", item["manifest_sha256"],
                         "--completion-bytes", item["completion_bytes"], "--completion-sha256", item["completion_sha256"],
                         "--config", plan["binlog_config" if binlog else "database_config"], "--rclone", plan["rclone"],
                         "--crypt-remote", plan["crypt_remote"],
                         "--expected-destination", plan["binlog_destination" if binlog else "database_destination"],
                         "--output", output, "--timeout-seconds", plan["timeout_seconds"])

    def verify_chain(self, plan, root, inventory_sha256, producer_sha256) -> bytes:
        return self.tool("verify_mysql_binlog_chain.py", "--source", root, "--inventory-sha256", inventory_sha256,
                         "--producer-sha256", producer_sha256, "--expected-identity", plan["source_uuid"],
                         "--expected-database", plan["database"])

    def mysql(self, *args) -> list:
        target = self.plan["target"]
        return [target["client"], "--defaults-file=" + target["defaults_file"], "--no-login-paths", "--protocol=SOCKET",
                "--socket=" + target["socket"], "--batch", "--skip-column-names", *args]

    def load_base(self, plan, dump: Path) -> None:
        # backup-database.php dumps one database without --databases, so the dump has no
        # CREATE DATABASE or USE. Create it on the fresh target (refused if it already exists)
        # and load into it.
        database = plan["database"]
        backup.need(isinstance(database, str) and chain.base_backup.DB_NAME.fullmatch(database) is not None,
                    "invalid drill database name")
        created = subprocess.run(self.mysql(f"--execute=CREATE DATABASE `{database}`"), stdin=subprocess.DEVNULL,
                                 stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=backup.SAFE_ENV,
                                 timeout=60, check=False)
        backup.need(created.returncode == 0, "the drill database could not be created on the fresh target")
        with gzip.open(dump, "rb") as source:
            process = subprocess.Popen(self.mysql("--database=" + database), stdin=subprocess.PIPE,
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=backup.SAFE_ENV)
            # One deadline for the whole transfer: a client that stops reading (a stalled target)
            # blocks the copy itself once the pipe is full, long before a timed wait is reached.
            expired = threading.Event()

            def expire() -> None:
                expired.set()
                process.kill()

            timer = threading.Timer(plan["timeout_seconds"], expire)
            timer.daemon = True
            timer.start()
            try:
                try:
                    shutil.copyfileobj(source, process.stdin, 1024 * 1024)
                except BrokenPipeError:
                    pass  # The client stopped reading: it failed or the deadline killed it; its exit says which.
                finally:
                    try:
                        process.stdin.close()
                    except BrokenPipeError:
                        pass
                code = process.wait()  # Bounded by the same deadline.
            finally:
                timer.cancel()
                if process.poll() is None:
                    process.kill()
                    process.wait()
        backup.need(not expired.is_set(), "base load exceeded its time limit")
        backup.need(code == 0, "base load refused")

    def replay(self, plan, files, start, stop) -> None:
        decoder = subprocess.Popen([plan["target"]["mysqlbinlog"], "--no-defaults", "--verify-binlog-checksum",
                                    f"--start-position={start}", f"--stop-position={stop}", *map(str, files)],
                                   stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, env=backup.SAFE_ENV)
        loader = subprocess.run(self.mysql(), stdin=decoder.stdout, stdout=subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, env=backup.SAFE_ENV, timeout=plan["timeout_seconds"])
        decoder.stdout.close()
        backup.need(decoder.wait(timeout=60) == 0 and loader.returncode == 0, "binlog replay refused")

    def heartbeat(self, plan) -> bytes:
        return self.tool("heartbeat.py", "verify", "--plan", plan["heartbeat"]["plan"],
                         "--plan-sha256", plan["heartbeat"]["plan_sha256"], "--target-utc", plan["target_utc"])

    def query(self, plan, sql: str) -> str:
        result = subprocess.run(self.mysql("--execute=" + sql), stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, env=backup.SAFE_ENV, timeout=60, check=False)
        backup.need(result.returncode == 0, "restored-data query refused")
        return result.stdout.decode().strip()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--plan", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True, help="new evidence folder for this drill")
    args = parser.parse_args()
    os.umask(0o077)
    try:
        plan = json.loads(args.plan.read_bytes())
        backup.need(isinstance(plan, dict) and set(plan) == PLAN_FIELDS and plan["version"] == 1, "unexpected drill plan")
        record = run_drill(plan, StockSteps(plan), args.output)
    except (backup.Refusal, OSError, ValueError) as error:
        print(json.dumps({"result": "REFUSED", "reason": str(error)[:200]}))
        return 1
    print(json.dumps({key: record[key] for key in ("result", "run_id") if key in record}
                     | {"failed_step": record.get("failed_step")}))
    return 0 if record["result"] == VERIFIED else 1


if __name__ == "__main__":
    raise SystemExit(main())
