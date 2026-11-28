"""Verify two saved local cutover drill logs from the same release. Reports contain fixture metadata only."""
import argparse
import hashlib
import json
import math
from pathlib import Path
import re
import sys
import uuid


def require(ok, message):
    if not ok:
        raise ValueError(message)


def validated(record, case, release):
    require(re.fullmatch(r"[0-9a-f]{40}", release) and record.get("release") == release,
            "Case belongs to another release")
    require(type(record.get("version")) is int and record["version"] == 1 and record.get("case") == case
            and record.get("result") == "local-recovery-verified", "Incomplete case record")
    require(record.get("backup_transport") == "disconnected-readback-fixture", "Unexpected backup transport")
    require(isinstance(record.get("original_run_id"), str)
            and re.fullmatch(r"[A-Za-z0-9._-]{1,128}", record["original_run_id"]), "Missing run identity")
    server = record.get("server_uuid")
    require(isinstance(server, str) and str(uuid.UUID(server)) == server, "Invalid MySQL server UUID")
    for key in ("frozen_sha256", "installed_sha256", "close_sha256", "closed_coordinate_sha256"):
        require(isinstance(record.get(key), str) and re.fullmatch(r"[0-9a-f]{64}", record[key]),
                "Missing or invalid " + key)
    elapsed = record.get("local_fixture_elapsed_seconds")
    require(type(elapsed) in (int, float) and math.isfinite(elapsed) and elapsed > 0, "Invalid fixture time")
    require(type(record.get("source_tables")) is int and record["source_tables"] > 0, "Missing verified source tables")
    counts = record.get("closed_counts")
    if counts is not None:
        require(isinstance(counts, dict) and counts.keys() == {"smoke", "foreign", "business"}
                and all(type(v) is int and v >= 0 for v in counts.values()), "Invalid transaction counts")
    if case == "short":
        require(record.get("method") == "verified-prewrite" and record.get("source_inode_preserved") is True
                and record["frozen_sha256"] == record["installed_sha256"] and record.get("business_write_preserved") is False
                and counts is not None and counts["business"] == 0 and counts["smoke"] == 4,
                "Incomplete proof for the unchanged frozen file")
    else:
        require(record.get("method") == "verified-export" and record.get("source_inode_preserved") is False
                and record["frozen_sha256"] != record["installed_sha256"]
                and record.get("business_write_preserved") is True and record.get("post_reopen_business_read") is True
                and (counts is None or counts["business"] > 0), "Missing new-file or post-reopen business-write proof")


def read_case(path, case, release):
    raw = path.read_bytes()
    result = None
    completed = False
    for line in raw.decode("utf-8-sig").splitlines():
        if line.startswith("run-drill: run_drill.py exited "):
            require(line == "run-drill: run_drill.py exited 0", "Launcher reported failure")
            completed = result is not None
        elif line.startswith("{"):
            record = json.loads(line)
            if record.get("case") == case:
                require(result is None, "Duplicate case receipt")
                validated(record, case, release)
                result = record
    require(result is not None and completed, "Missing successful launcher exit after the case")
    return result, hashlib.sha256(raw).hexdigest()


def verify(short_log, export_log, release):
    short, short_sha = read_case(short_log, "short", release)
    export, export_sha = read_case(export_log, "export", release)
    for key in ("original_run_id", "server_uuid", "close_sha256"):
        require(short[key] != export[key], "The two cases reused " + key)
    require(short["source_tables"] == export["source_tables"], "Different source schemas between paired cases")
    return {"version": 1, "result": "local-recovery-pair-verified", "release": release,
            "cases": {"short": {"receipt": short, "log_sha256": short_sha},
                      "export": {"receipt": export, "log_sha256": export_sha}}}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--release", required=True)
    parser.add_argument("short_log", type=Path)
    parser.add_argument("export_log", type=Path)
    args = parser.parse_args()
    try:
        print(json.dumps(verify(args.short_log, args.export_log, args.release), sort_keys=True))
    except (ValueError, OSError) as error:
        sys.exit(str(error))
