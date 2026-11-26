"""Inside the QA container, as root: native MySQL 8.4 checks for the purge and the drill runner.

The source and target servers run in their own containers; this side reaches
them only through Unix sockets on a shared tmpfs volume. Synthetic data only.
"""
import gzip
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import time

sys.path.insert(0, "/src/scripts/mysql/offsite")
import publish_backup as backup  # noqa: E402
import purge_archived_binlogs as purge  # noqa: E402
import run_pitr_drill as drill  # noqa: E402

SOURCE, TARGET = "/fixture-socket/source.sock", "/fixture-socket/target.sock"
DATA = Path("/source-data")
WORK = Path("/work")
CLIENT, DUMP = "/usr/local/bin/mysql", "/usr/local/bin/mysqldump"
report = {"version": 1, "network": "none", "provider_contacted": False, "checks": {}}


def mysql(socket, statement, user="root", password=""):
    argv = [CLIENT, "--no-defaults", "--protocol=SOCKET", f"--socket={socket}", f"--user={user}",
            "--batch", "--skip-column-names", "--execute=" + statement]
    if password:
        argv.insert(5, f"--password={password}")
    result = subprocess.run(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
    if result.returncode:
        raise RuntimeError(f"mysql failed: {result.stderr.decode()[-300:]}")
    return result.stdout.decode().strip()


def record(name, ok, detail=None):
    report["checks"][name] = {"ok": bool(ok)} | ({"detail": detail} if detail is not None else {})
    if not ok:
        raise RuntimeError(f"check failed: {name}: {detail}")


def wait(socket):
    for _ in range(90):
        try:
            return mysql(socket, "SELECT @@server_uuid")
        except RuntimeError:
            time.sleep(1)
    raise RuntimeError("server did not start: " + socket)


def status(socket):
    file, position = mysql(socket, "SHOW BINARY LOG STATUS").split("\t")[:2]
    return {"file": file, "position": int(position)}


def manifest(folder: Path, names):
    items = [{"path": name, "bytes": (folder / "artifacts" / name).stat().st_size,
              "sha256": backup.sha256((folder / "artifacts" / name).read_bytes())} for name in names]
    (folder / "input-manifest.json").write_text(json.dumps({"artifacts": items}))


class NativeSteps(drill.StockSteps):
    """Real load, mysqlbinlog replay and queries. Retrieval, chain check and heartbeat are covered elsewhere."""

    def restore(self, plan, item, identity, output):
        shutil.copytree(WORK / "bucket" / item["backup_id"], output)
        return (json.dumps({"result": "artifacts-recovered-and-verified", "scope": "database", "identity": identity,
                            "artifact_count": 1, "artifact_bytes": 1}) + "\n").encode()

    def verify_chain(self, plan, root, inventory_sha256, producer_sha256):
        inventory = json.loads((root / "binlog-chain.json").read_bytes())
        report["chain_segments"] = [item["file"] for item in inventory["segments"]]
        return (json.dumps({"result": "closed-mysql-binlog-chain-verified", "source_uuid": plan["source_uuid"],
                            "stop": plan["stop"], "contiguous_binlog_chain": True}) + "\n").encode()

    def heartbeat(self, plan):
        return (json.dumps({"result": "recovered-heartbeat-verified"}) + "\n").encode()


def main():
    started = time.monotonic()
    vendor = Path("/tools/vendor")
    shutil.copytree("/vendor-binlog", vendor)
    os.chmod(vendor / "bin/mysqlbinlog", 0o755)
    source_uuid, target_uuid = wait(SOURCE), wait(TARGET)
    report["versions"] = [mysql(SOURCE, "SELECT VERSION()"), mysql(TARGET, "SELECT VERSION()")]

    mysql(SOURCE, "CREATE DATABASE repro_drill; CREATE TABLE repro_drill.markers (id INT PRIMARY KEY, note VARCHAR(64) NOT NULL) "
                  "ENGINE=InnoDB; INSERT INTO repro_drill.markers VALUES (1, 'base row');"
                  "CREATE USER repro_binlog_purger@localhost IDENTIFIED BY 'fixture_only_purger';"
                  "GRANT BINLOG_ADMIN, REPLICATION CLIENT ON *.* TO repro_binlog_purger@localhost;"
                  "CREATE USER repro_no_purge@localhost IDENTIFIED BY 'fixture_only_reader';"
                  "GRANT REPLICATION CLIENT ON *.* TO repro_no_purge@localhost; FLUSH BINARY LOGS;")
    start = status(SOURCE)
    base = WORK / "bucket" / "base"
    (base / "artifacts").mkdir(parents=True)
    # Same shape as scripts/deploy/backup-database.php: the database after "--" and no
    # --databases, so the dump has no CREATE DATABASE or USE and the drill must select one.
    dump = subprocess.run([DUMP, "--no-defaults", "--protocol=SOCKET", f"--socket={SOURCE}", "--user=root",
                           "--single-transaction", "--set-gtid-purged=OFF", "--", "repro_drill"],
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60, check=True).stdout
    with gzip.open(base / "artifacts/database.sql.gz", "wb") as stream:
        stream.write(dump)
    (base / "artifacts/database-backup.json").write_text(json.dumps(
        {"pitr": {"binlog_file": start["file"], "binlog_position": start["position"]}}))
    manifest(base, ["database.sql.gz", "database-backup.json"])

    mysql(SOURCE, "INSERT INTO repro_drill.markers VALUES (2, 'after base'); FLUSH BINARY LOGS;")
    mysql(SOURCE, "INSERT INTO repro_drill.markers VALUES (3, 'chosen marker');")
    stop = status(SOURCE)
    mysql(SOURCE, "UPDATE repro_drill.markers SET note = 'damaged' WHERE id = 1;"
                  "INSERT INTO repro_drill.markers VALUES (4, 'planted bad write'); FLUSH BINARY LOGS;")
    listing = [line.split("\t")[0] for line in mysql(SOURCE, "SHOW BINARY LOGS").splitlines()]
    closed = listing[:-1]
    batch = WORK / "bucket" / "binlogs"
    (batch / "artifacts").mkdir(parents=True)
    for name in closed:
        shutil.copyfile(DATA / name, batch / "artifacts" / name)
    manifest(batch, closed)

    defaults = Path("/tmp/target.cnf")
    defaults.write_text("[client]\nuser=root\npassword=\n")
    os.chmod(defaults, 0o600)
    plan = {"version": 1, "run_id": "native-drill", "destination_sha256": "e" * 64, "source_uuid": source_uuid,
            "database": "repro_drill", "server_id": 5384, "base": {"backup_id": "base"},
            "binlogs": [{"backup_id": "binlogs"}], "target_utc": "2026-11-25T00:00:00Z", "stop": stop,
            "chosen_check": {"sql": "SELECT COUNT(*) FROM repro_drill.markers WHERE id = 3", "expected": "1"},
            "planted_check": {"sql": "SELECT COUNT(*) FROM repro_drill.markers WHERE id = 4 OR note = 'damaged'",
                              "expected": "0"},
            "work_dir": str(WORK / "drill-work"), "timeout_seconds": 120,
            "target": {"client": CLIENT, "mysqlbinlog": str(vendor / "bin/mysqlbinlog"), "socket": TARGET,
                       "defaults_file": str(defaults)}}
    result = drill.run_drill(plan, NativeSteps(plan), WORK / "drill-out")
    rows = mysql(TARGET, "SELECT GROUP_CONCAT(id, ':', note ORDER BY id SEPARATOR '|') FROM repro_drill.markers")
    record("replay_stops_before_the_planted_write",
           result["result"] == drill.VERIFIED and result["planted_bad_write_excluded"]
           and rows == "1:base row|2:after base|3:chosen marker", {"result": result.get("result"),
                                                                   "failed_step": result.get("failed_step"), "rows": rows})
    record("restored_instance_is_independent", result["restored_uuid"] == target_uuid != source_uuid)

    def catalog(user, password):
        lines = mysql(SOURCE, "SHOW BINARY LOGS", user, password).splitlines()
        entries = [(line.split("\t")[0], int(line.split("\t")[1])) for line in lines]
        return entries[-1][0], entries

    checkpoint = closed[2]
    denied = None
    try:
        purge.run(listing=lambda: catalog("repro_no_purge", "fixture_only_reader"), checkpoint_next=lambda: checkpoint,
                  mtime=lambda name: int(os.stat(DATA / name).st_mtime),
                  execute=lambda statement: mysql(SOURCE, statement, "repro_no_purge", "fixture_only_reader"),
                  now=int(time.time()) + 10, min_age_seconds=1, keep_files=1)
    except RuntimeError as error:
        denied = "BINLOG_ADMIN" in str(error) or "denied" in str(error).lower()
    record("purge_without_binlog_admin_is_refused", denied is True)
    outcome = purge.run(listing=lambda: catalog("repro_binlog_purger", "fixture_only_purger"),
                        checkpoint_next=lambda: checkpoint, mtime=lambda name: int(os.stat(DATA / name).st_mtime),
                        execute=lambda statement: mysql(SOURCE, statement, "repro_binlog_purger", "fixture_only_purger"),
                        now=int(time.time()) + 10, min_age_seconds=1, keep_files=1)
    remaining = [line.split("\t")[0] for line in mysql(SOURCE, "SHOW BINARY LOGS").splitlines()]
    record("purge_stops_at_the_archive_checkpoint",
           outcome["purged_to"] == checkpoint and remaining[0] == checkpoint
           and not any((DATA / name).exists() for name in listing if name < checkpoint)
           and all((DATA / name).exists() for name in remaining), {"outcome": outcome, "remaining": remaining})
    report["elapsed_seconds"] = round(time.monotonic() - started, 1)
    report["result"] = "passed"


if __name__ == "__main__":
    try:
        main()
    except Exception as error:  # noqa: BLE001 - the report must say what failed
        report["result"] = "failed"
        report["error"] = str(error)[:1500]
    print(json.dumps(report, indent=1, sort_keys=True))
    sys.exit(0 if report["result"] == "passed" else 1)
