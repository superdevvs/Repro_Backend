"""Owner-operated recovery of a live post-write run. None of these actions grants admissions."""
from dataclasses import asdict
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile

import adapter as host
import binlog_scan
import gate
import mysql_probe
import sqlite_seal
from adapter import guard
from runner import UnsafeCutover, is_sha256


FIELD_READER = Path("/opt/repro-cutover-fence/production/verify-fields.php")
CLOSED = "way-back.json"
INSTALLED = "wayback-installed.json"
INTENT = "wayback-install-intent.json"
EFFECTS = {"website", "providers", "payments", "calendar", "outbound", "queues"}


def need(condition, message):
    if not condition:
        raise UnsafeCutover(message)


def private_record(path):
    raw = guard.read_protected(path)
    need(stat.S_IMODE(path.lstat().st_mode) & 0o077 == 0, "Recovery records must be root-private")
    result = json.loads(raw, object_pairs_hook=guard.unique_fields, parse_constant=guard.refuse_non_json_constant)
    need(isinstance(result, dict), "Recovery input must be a JSON object")
    return result, guard.sha256(raw)


def file_hash(path):
    with guard.open_protected(path) as fd:
        need(os.fstat(fd).st_mode & 0o077 == 0, "Recovery input must be root-private")
        with os.fdopen(os.dup(fd), "rb") as stream:
            return hashlib.file_digest(stream, "sha256").hexdigest()


def recovery_state(adapter, state):
    need(adapter.config["mysql"]["mode"] == "cutover" and state and state.get("version") == 1
         and state["identity"] == asdict(adapter.pinned)
         and state["phase"] in {"writes_possible", "mysql_authoritative", "recovery_required"}
         and state["writes_possible"] is True, "Way back requires the pinned active post-write run")


def held(adapter):
    for name in ("evidence", "activation", "scheduler_release", "ingress_open"):
        need(not os.path.lexists(adapter.paths[name]), f"Way-back hold lost: {name} exists")
    need(not adapter.processes(adapter.config["writer_processes"] + adapter.config["scheduler_processes"]),
         "Application or scheduler writers are still running")
    need(host.deploy_marker.published(adapter.paths["deploy_marker"], adapter.pinned.run_id),
         "The run's deployment fence is missing")
    need(not os.path.lexists(adapter.config["fpm"]["socket"]), "Application FPM still has a listening endpoint")


def closed_coordinate(adapter):
    reading = gate.read(adapter.config)
    need(reading.gate == "closed" and reading.sessions == (), "The MySQL gate must be closed with zero app sessions")
    raw = mysql_probe._show(adapter.config, "SELECT @@server_uuid; " + mysql_probe.LISTING)
    uuid_value, separator, listing = raw.partition(b"\n")
    need(separator and uuid_value == adapter.pinned.target_uuid.encode("ascii"),
         "The close coordinate was not read on the pinned MySQL server")
    (point, gtids), _ = binlog_scan.parse_listing(listing)
    coordinate = {**point.record(), "gtid_executed": gtids}
    need(host._coordinate(coordinate, host.BACKUP_KEYS), "Invalid MySQL close coordinate")
    return {"version": 1, "run_id": adapter.pinned.run_id, "server_uuid": adapter.pinned.target_uuid,
            "gate": "closed", "coordinate": coordinate}


def close(adapter):
    with adapter.journal.acquire() as (state, lease):
        recovery_state(adapter, state)
        adapter.require_quiescent()
        # Withdraw durable authority first. A crash between any two unlinks cannot let
        # refresh reissue yesterday's MySQL admission grants on this boot or the next one.
        state.update(phase="recovery_required", generation=lease.generation)
        adapter.journal.write(state)
        for name in ("evidence", "activation", "scheduler_release", "ingress_open"):
            host._remove(adapter.paths[name])
        deadline = adapter.clock() + 600
        adapter._install_sqlite_wrappers()
        adapter.close_admissions(lease, deadline)
        adapter.drain(lease, deadline, purpose="freeze")
        adapter.hold_and_drain_scheduler(lease, deadline)
        adapter.journal.drain_writers(lease, max(0, deadline - adapter.clock()))
        held(adapter)
        gate.close_gate(adapter.config)
        record = closed_coordinate(adapter)
        path = adapter.journal.directory / CLOSED
        if os.path.lexists(path):
            prior, _ = private_record(path)
            need(prior == record, "The previously closed coordinate changed; existing recovery evidence is stale")
        else:
            host._write_atomic(path, record, 0o600)
        pending = state.get("pending")
        if pending is not None:
            need(isinstance(pending, dict) and pending.get("action") == "promote",
                 "Unexpected interrupted action requires review under the closed gate")
            state["history"].append({**pending, "phase": "interrupted", "interrupted_by": "way-back close",
                                     "close_sha256": private_record(path)[1]})
            state["pending"] = None
            adapter.journal.write(state)
        return record


def verify_close(adapter):
    held(adapter)
    current = adapter.journal.read()
    need(current.get("phase") == "recovery_required" and current.get("pending") is None,
         "Complete way-back close before auditing or installing")
    record, digest = private_record(adapter.journal.directory / CLOSED)
    need(record == closed_coordinate(adapter), "MySQL state or binary-log coordinate changed after the close")
    return record, digest


def audit_locked(adapter):
    close_record, close_hash = verify_close(adapter)
    receipt, receipt_hash = private_record(adapter.paths["receipt"])
    need(receipt["run_id"] == adapter.pinned.run_id and receipt["server_uuid"] == adapter.pinned.target_uuid
         and receipt["database"] == adapter.config["mysql"]["database"], "Wrong MySQL promotion receipt")
    prepared = guard.read_protected(adapter.paths["evidence_report"])
    need(guard.sha256(prepared) == adapter.journal.read()["evidence"]["report_sha256"],
         "Prepared evidence no longer matches the journal")
    report_binding = host.evidence.parse(prepared)
    need((report_binding["run_id"], report_binding["receipt_sha256"], report_binding["armed_from"])
         == (receipt["run_id"], receipt_hash, receipt["armed_from"]),
         "Audit receipt or starting coordinate differs from the approved prepare")
    report = mysql_probe.audit(adapter.config)
    need(report["receipt"] == receipt_hash and report["armed_from"] == receipt["armed_from"]
         and report["server_uuid"] == adapter.pinned.target_uuid and report["gate"] == "closed"
         and report["database"] == receipt["database"], "Binary-log audit belongs to another run, target or receipt")
    counts = {name: 0 for name in ("foreign", "smoke", "business")}
    for row in report["transactions"]:
        need(row["class"] in counts, "Unclassified application transaction")
        counts[row["class"]] += 1
    need(counts == report["counts"], "Inconsistent audit totals")
    # C03 validates the completed ORM write report. Older window records cannot excuse any transaction.
    trusted_smoke = not counts["smoke"] or authentic_smoke(adapter, report, close_record["coordinate"])
    verify_close(adapter)
    return {"run_id": receipt["run_id"], "server_uuid": receipt["server_uuid"], "receipt_sha256": receipt_hash,
            "close_sha256": close_hash, "coordinate": close_record["coordinate"], "counts": counts,
            "short_path_eligible": counts["business"] == 0 and trusted_smoke,
            "audit_sha256": guard.sha256(host.evidence.encode(report))}


def authentic_smoke(adapter, report, coordinate):
    """Only the C03 audit authenticates an ORM report and binds its completed GTID pair to this receipt."""
    window = report["smoke_window"]
    if (getattr(mysql_probe, "AUTHENTICATED_SMOKE_WINDOW_VERSION", None) != 2
            or report.get("smoke_row_scope_verified") is not True
            or not isinstance(window, dict) or window.keys() != {"gtid_before", "gtid_after"}
            or not report["boundary"]):
        return False
    before, after, closed = (binlog_scan.GtidSet.parse(text) for text in
                             (window["gtid_before"], window["gtid_after"], coordinate["gtid_executed"]))
    allowed = set(adapter.config["mysql"]["smoke_tables"])
    observed = []
    touched = []
    for row in report["transactions"]:
        if row["class"] != "smoke":
            continue
        gtid = row["gtid"]
        if (gtid in observed or gtid not in after or gtid in before or row["statements"]
                or not row["tables"] or not set(row["tables"]) <= allowed):
            return False
        observed.append(gtid)
        touched.append(set(row["tables"]))
    # CutoverSmoke::write edits its two fixture records in one transaction, then pushes,
    # reserves and removes one jobs row. An extra concurrent write to any of the same
    # tables has its own GTID even when it occurs inside the authenticated smoke interval.
    if touched != [{"users", "shoots", "aryeo_changes"}, {"jobs"}, {"jobs"}, {"jobs"}]:
        return False
    prefix = adapter.pinned.target_uuid + ":"
    if any(not g.startswith(prefix) or not g[len(prefix):].isdigit() for g in observed):
        return False
    first = int(observed[0][len(prefix):])
    if observed != [prefix + str(first + index) for index in range(4)]:
        return False
    expected = binlog_scan.GtidSet.parse(",".join(filter(None, [window["gtid_before"], *observed])))
    return expected.issubset(after) and after.issubset(expected) and after.issubset(closed)


def audit(adapter):
    with adapter.journal.acquire() as (state, lease):
        recovery_state(adapter, state)
        adapter.journal.drain_writers(lease, 0)
        return audit_locked(adapter)


def review_for(adapter, path, method, digest, close_hash):
    review, review_hash = private_record(path)
    keys = {"version", "run_id", "server_uuid", "database", "method", "installed_sha256", "close_sha256",
            "owner_approved", "effects", "reconciliation_report", "reconciliation_sha256"}
    if method == "verified-export":
        keys.update({"schema_plan", "schema_plan_sha256", "reader_credentials", "reviewed_reverse_layout"})
    need(review.keys() == keys and type(review["version"]) is int and review["version"] == 1
         and review["run_id"] == adapter.pinned.run_id and review["server_uuid"] == adapter.pinned.target_uuid
         and review["database"] == adapter.config["mysql"]["database"] and review["method"] == method
         and review["close_sha256"] == close_hash and review["installed_sha256"] == digest
         and is_sha256(digest) and review["owner_approved"] is True,
         "Owner approval must bind this exact method, run, target, closed coordinate and SQLite file")
    need(isinstance(review["effects"], dict) and review["effects"].keys() == EFFECTS
         and all(v == "reconciled" for v in review["effects"].values())
         and Path(review["reconciliation_report"]).stat().st_size > 0
         and file_hash(Path(review["reconciliation_report"])) == review["reconciliation_sha256"],
         "External effects require a reviewed reconciliation report before installing SQLite")
    return review, review_hash


def verify_export(adapter, candidate, digest, review):
    result, result_hash = private_record(candidate.parent / "result.json")
    frozen_sha, _ = adapter._sealed_sha256()
    need(is_sha256(frozen_sha) and result.get("schema_template_sha256") == frozen_sha
         and candidate.name == "database.sqlite" and file_hash(candidate) == digest and result.get("verified") is True
         and result.get("database_sha256") == digest and result.get("source_server_uuid") == adapter.pinned.target_uuid
         and result.get("field_mismatches") == 0 and result.get("foreign_keys_enabled") is True
         and type(result.get("tables")) is int and result["tables"] > 0
         and type(result.get("rows")) is int and result["rows"] >= 0, "Reverse export result or file differs")
    plan = Path(review["schema_plan"])
    need(file_hash(plan) == review["schema_plan_sha256"], "Independent schema plan checksum differs")
    credentials = Path(review["reader_credentials"])
    access, _ = private_record(credentials)
    need(access.keys() == {"user", "password", "unix_socket"} and access["user"] == "repro_parity_reader"
         and access["unix_socket"] == adapter.config["mysql"]["socket"], "Use the pinned socket and parity reader")
    guard.check_chain(FIELD_READER)
    need(type(review["reviewed_reverse_layout"]) is bool, "Invalid reverse layout flag")
    command = ["/usr/bin/php", str(FIELD_READER), "--writers-stopped", f"--snapshot={candidate}",
               f"--snapshot-sha256={digest}", f"--schema-plan={plan}", f"--schema-plan-sha256={review['schema_plan_sha256']}",
               f"--credentials={credentials}", f"--expected-server-uuid={adapter.pinned.target_uuid}",
               f"--database={adapter.config['mysql']['database']}"]
    if review["reviewed_reverse_layout"]:
        command.append("--reviewed-reverse-layout")
    try:
        completed = subprocess.run(command, capture_output=True, text=True, timeout=1200, cwd="/",
                                   env=host.HOST_ENVIRONMENT, stdin=subprocess.DEVNULL)
    except subprocess.TimeoutExpired:
        raise UnsafeCutover("Independent PHP field verification timed out") from None
    need(completed.returncode == 0, "Independent PHP field verification failed")
    verified = host.evidence.last_json_line(completed.stdout)
    need(verified and verified.get("result") == "all-fields-verified" and verified.get("source_sha256") == digest
         and verified.get("reader") == "independent-php83" and verified.get("target_server_uuid") == adapter.pinned.target_uuid
         and verified.get("target_database") == adapter.config["mysql"]["database"]
         and verified.get("schema_plan_sha256") == review["schema_plan_sha256"] and verified.get("field_mismatches") == 0
         and verified.get("tables") == result["tables"] and verified.get("rows") == result["rows"],
         "Independent verification did not confirm this reverse export")
    return guard.sha256(host.evidence.encode({"export_result_sha256": result_hash, "independent": verified}))


def frozen_intent(adapter, method, digest, review_hash, close_hash):
    path = adapter.journal.directory / INTENT
    binding = {"version": 1, "run_id": adapter.pinned.run_id, "method": method, "installed_sha256": digest,
               "review_sha256": review_hash, "close_sha256": close_hash}
    if os.path.lexists(path):
        intent, _ = private_record(path)
        need(intent.keys() == binding.keys() | {"original"} and all(intent[k] == v for k, v in binding.items()),
             "A different recovery installation already has an intent")
        return intent
    db = adapter.database
    info = db.lstat()
    expected, _ = adapter._sealed_sha256()
    need(stat.S_ISREG(info.st_mode) and not (info.st_mode & 0o222) and expected
         and expected == sqlite_seal.file_sha256(db), "The original frozen SQLite file differs from its sealed hash")
    need(adapter.identity() == adapter.pinned, "The original SQLite file or release identity changed")
    seal = guard.read_json(guard.read_protected(adapter.paths["sqlite_seal_record"]))
    mode = seal["modes"].get(str(db))
    need(seal.get("database") == str(db) and type(mode) is int and 0 < mode <= 0o777 and mode & 0o222,
         "The SQLite seal has no original writable mode for this path")
    original = {"dev": info.st_dev, "ino": info.st_ino, "uid": info.st_uid, "gid": info.st_gid,
                "mode": mode, "sha256": expected}
    intent = {**binding, "original": original}
    host._write_atomic(path, intent, 0o600)
    return intent


def install_file(adapter, candidate, intent):
    """Hard-link the frozen inode before the atomic replacement. No retry may overwrite the frozen backup."""
    db, original = adapter.database, intent["original"]
    need(db.parent.resolve() == db.parent and db.is_absolute(), "Unexpected SQLite directory")
    info = db.lstat()
    need(stat.S_ISREG(info.st_mode), "SQLite destination is not a regular file")
    same = (info.st_dev, info.st_ino) == (original["dev"], original["ino"])
    if candidate is None:
        need(same and sqlite_seal.file_sha256(db) == original["sha256"] == intent["installed_sha256"],
             "Short recovery requires the original frozen inode and sealed hash")
        return
    archive = db.parent / ("frozen-" + adapter.pinned.run_id)
    archive.mkdir(mode=0o700, exist_ok=True)
    folder = archive.lstat()
    need(stat.S_ISDIR(folder.st_mode) and folder.st_uid == 0 and folder.st_mode & 0o077 == 0,
         "The frozen recovery folder must be a root-private directory")
    saved = archive / db.name
    if same and not os.path.lexists(saved):
        need(sqlite_seal.file_sha256(db) == original["sha256"], "The sealed original changed since the intent")
        os.link(db, saved, follow_symlinks=False)
        host._sync_directory(archive)
        host._sync_directory(db.parent)
    saved_info = saved.lstat()
    need(stat.S_ISREG(saved_info.st_mode) and (saved_info.st_dev, saved_info.st_ino) == (original["dev"], original["ino"])
         and sqlite_seal.file_sha256(saved) == original["sha256"], "The frozen backup is missing or changed")
    if not same:
        need(sqlite_seal.file_sha256(db) == intent["installed_sha256"],
             "The post-crash destination is neither the frozen inode nor the verified export")
        return
    sqlite_seal._refuse_open_writers(db, adapter.proc)
    for companion in sqlite_seal.companions(db)[1:]:
        if os.path.lexists(companion):
            cs = companion.lstat()
            need(stat.S_ISREG(cs.st_mode) and (companion.name.endswith("-shm") or cs.st_size == 0)
                 and not os.path.lexists(archive / companion.name), "The frozen SQLite sidecar requires review")
            os.rename(companion, archive / companion.name)
            host._sync_directory(archive)
            host._sync_directory(db.parent)
    fd, temp = tempfile.mkstemp(dir=archive, prefix=".verified-export-")
    try:
        with os.fdopen(fd, "wb") as output, guard.open_protected(candidate) as source:
            with os.fdopen(os.dup(source), "rb") as stream:
                shutil.copyfileobj(stream, output, length=4 << 20)
            output.flush()
            os.fchown(output.fileno(), original["uid"], original["gid"])
            os.fchmod(output.fileno(), original["mode"] & ~0o222)
            os.fsync(output.fileno())
        need(sqlite_seal.file_sha256(Path(temp)) == intent["installed_sha256"], "Staged export checksum differs")
        os.replace(temp, db)
        host._sync_directory(db.parent)
        host._sync_directory(archive)
    finally:
        Path(temp).unlink(missing_ok=True)


def install(adapter, *, candidate, digest, review_path):
    method = "verified-prewrite" if candidate is None else "verified-export"
    with adapter.journal.acquire() as (state, lease):
        recovery_state(adapter, state)
        adapter.journal.drain_writers(lease, 0)
        closed, close_hash = verify_close(adapter)
        review, review_hash = review_for(adapter, review_path, method, digest, close_hash)
        switch = adapter._switch_record()
        need(switch and switch["run_id"] == adapter.pinned.run_id, "Missing switch record for this recovery")
        if candidate is None:
            summary = audit_locked(adapter)
            need(summary["short_path_eligible"], "Business or uncertain transactions require a new reverse export")
            verification = summary["audit_sha256"]
        else:
            need(candidate != adapter.database and not os.path.samefile(candidate, adapter.database),
                 "A reverse recovery must install a separately exported new SQLite file")
            verification = verify_export(adapter, candidate, digest, review)
        verify_close(adapter)
        intent = frozen_intent(adapter, method, digest, review_hash, close_hash)
        install_file(adapter, candidate, intent)
        now = adapter.clock()
        run = host.SealRun(lease=lease, deadline=now + adapter.config["command_seconds"])
        adapter._seal_sqlite_env(run)
        adapter._seal_sqlite_config(run)
        adapter._seal_sqlite_verify(run)
        expected = {"engine": "sqlite", "database": str(adapter.database), "server_uuid": None,
                    "route_sha256": adapter._sqlite_pin()}
        for argv, user, group in adapter._smoke_commands():
            adapter._readonly_smoke(argv, user, group, expected, run.deadline)
        need(sqlite_seal.file_sha256(adapter.database) == digest, "Installed SQLite bytes changed during verification")
        sqlite_seal._refuse_open_writers(adapter.database, adapter.proc)
        original = intent["original"]
        info = adapter.database.lstat()
        need((info.st_uid, info.st_gid) == (original["uid"], original["gid"]), "SQLite ownership changed")
        verify_close(adapter)
        identity = adapter.identity()
        need(identity.run_id == adapter.pinned.run_id and identity.release_sha == adapter.pinned.release_sha
             and identity.artifact_sha256 == adapter.pinned.artifact_sha256 and identity.config_sha256 == adapter.pinned.config_sha256
             and identity.target_uuid == closed["server_uuid"], "The installed runtime no longer matches the pinned release")
        restore_modes(adapter, original, kept=candidate is None)
        record = {"version": 1, "run_id": adapter.pinned.run_id, "server_uuid": closed["server_uuid"],
                  "database": str(adapter.database), "method": method, "installed_sha256": digest,
                  "source_sha256": identity.source_sha256, "sqlite_live_config_sha256": expected["route_sha256"],
                  "close_sha256": close_hash, "review_sha256": review_hash, "verification_sha256": verification,
                  "writers_held": True}
        path = adapter.journal.directory / INSTALLED
        if os.path.lexists(path):
            need(private_record(path)[0] == record, "Installed recovery receipt differs on retry")
        else:
            host._write_atomic(path, record, 0o600)
        return record


def restore_modes(adapter, original, *, kept):
    """Restore writable modes under the writer hold; retain the durable seal record for recovery retirement."""
    saved = guard.read_json(guard.read_protected(adapter.paths["sqlite_seal_record"]))
    need(saved["database"] == str(adapter.database) and saved["modes"].get(str(adapter.database)) == original["mode"],
         "The SQLite seal record changed during installation")
    for path in sqlite_seal.companions(adapter.database):
        if not os.path.lexists(path):
            continue
        info = path.lstat()
        need(stat.S_ISREG(info.st_mode), "Unexpected SQLite file at the restored destination")
        if path != adapter.database:
            need(path.name.endswith("-shm") or info.st_size == 0, "A restored SQLite journal contains unresolved writes")
        mode = saved["modes"].get(str(path), original["mode"]) if kept else original["mode"]
        need(type(mode) is int and 0 < mode <= 0o777, "Invalid SQLite mode in the original seal")
        os.chown(path, original["uid"], original["gid"], follow_symlinks=False)
        os.chmod(path, mode, follow_symlinks=False)
        sqlite_seal._sync(path)
