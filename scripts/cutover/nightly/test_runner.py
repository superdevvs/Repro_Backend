"""Offline tests for the nightly runner. Every host effect is a fake."""
import copy
import hashlib
import json
import os
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from dataclasses import asdict
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch

from scripts.cutover.nightly import runner as nightly
from scripts.cutover.readiness import check, summarize_nightly_parity, verify_nightly_capture
from scripts.cutover.readiness.test_check import readiness_fixture
from scripts.cutover.runner import Identity, digest

HERE = Path(__file__).resolve().parent
LIVE_RELEASE = "8" * 40
CANDIDATE = "a" * 40  # equals the readiness fixture's frozen release
SCHEMA_SHA = "7" * 64
UUID = "0b5a4d1e-3c2f-4a6b-9d8e-7f6a5b4c3d2e"
CRM_MIGRATIONS = [f"2026_11_24_1{index}0000_create_crm_part_{index}" for index in range(9)]
CRM_TABLES = ["crm_contacts", *(f"crm_part_{index:02d}" for index in range(35)), "email_suppressions"]
OTHER_TABLES = [*summarize_nightly_parity.SHARED_CRM_TABLES, "migrations", "invoices"]
ALL_TABLES = sorted({*CRM_TABLES, *OTHER_TABLES})
ROWS_PER_TABLE = 3
BASE_NOW = datetime(2026, 11, 26, 5, 0, 0, tzinfo=timezone.utc)


def sha_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def option(argv, name):
    for index, value in enumerate(argv):
        if value == name:
            return argv[index + 1]
        if value.startswith(name + "="):
            return value.split("=", 1)[1]
    raise AssertionError(f"{name} missing from {argv}")


def make_live_database(path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with sqlite3.connect(path) as db:
        db.execute("CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)")
        db.executemany("INSERT INTO migrations (migration, batch) VALUES (?, 1)",
                       [("2014_10_12_000000_create_users_table",), *((name,) for name in CRM_MIGRATIONS)])
        db.execute("CREATE TABLE users (id INTEGER PRIMARY KEY)")
        db.execute("INSERT INTO users (id) VALUES (1)")
        db.execute("CREATE TABLE crm_contacts (id INTEGER PRIMARY KEY, client_user_id INTEGER)")
        db.execute("INSERT INTO crm_contacts (client_user_id) VALUES (1)")
        for name in CRM_TABLES[1:]:
            db.execute(f"CREATE TABLE {name} (id INTEGER PRIMARY KEY)")
    db.close()


class FakeProcess:
    def __init__(self, host):
        self.host = host
        self.running = True

    def poll(self):
        return None if self.running else 0

    def terminate(self):
        self.host.events.append("mysqld-stopped")
        self.running = False

    def kill(self):
        self.terminate()

    def wait(self, timeout=None):
        return 0


class FakeHost:
    """Simulates the reviewed tools by name. Nothing here touches a network or a server."""

    def __init__(self, root: Path, now: datetime = BASE_NOW):
        self.root = root
        self.now = now
        self.clock = 0.0
        self.calls = []
        self.events = []
        self.release = LIVE_RELEASE
        self.release_after_capture = None
        self.candidate = (CANDIDATE, True)
        self.free = 500 * 10**9
        self.mem_kib = 30 * 1024**2
        self.deploying = False
        self.durations = {"import": 400.0, "verify": 150.0}
        self.fail = set()
        self.live = root / "live" / "database.sqlite"
        make_live_database(self.live)
        self.processes = []
        self.stale = []

    # Host protocol -----------------------------------------------------
    def utc_now(self):
        return self.now + timedelta(seconds=self.clock)

    def monotonic(self):
        return self.clock

    def sleep(self, seconds):
        self.clock += seconds

    def deployed_release(self):
        if self.release_after_capture and any("backup-sqlite.php" in " ".join(call) for call in self.calls):
            return self.release_after_capture
        return self.release

    def live_database(self):
        info = self.live.stat()
        return nightly.LiveDatabase(path=self.live, identity=(1, 2), size=info.st_size)

    def free_bytes(self, path):
        return self.free

    def mem_available_kib(self):
        return self.mem_kib

    def checkout(self):
        return self.candidate

    def deploy_lock_free(self):
        return not self.deploying

    def native_binaries_verified(self):
        return True

    def stop_stale_mysqld(self, pid, work):
        self.stale.append((pid, str(work)))

    def start_mysqld(self, argv, *, env, cwd):
        self.calls.append(list(argv))
        self.events.append("mysqld-started")
        process = FakeProcess(self)
        self.processes.append(process)
        return process

    def run(self, argv, *, timeout_s, input=None, env=None, cwd=None):
        argv = [str(value) for value in argv]
        self.calls.append(argv)
        tool = next((Path(value).name for value in argv if Path(value).name in self.TOOLS), None)
        if tool is None:
            raise AssertionError(f"unexpected command {argv}")
        self.events.append(tool if tool != "migrate.py" else f"migrate.py {argv[argv.index(next(a for a in argv if a.endswith('migrate.py'))) + 1]}")
        if tool in self.fail:
            return nightly.Completed(1, json.dumps({"result": "refused"}) + "\n", "")
        return getattr(self, self.TOOLS[tool])(argv, timeout_s, input)

    TOOLS = {
        "backup-sqlite.php": "on_backup", "scope-audit.py": "on_scope", "migrate.py": "on_migrate",
        "build-sqlite-template.py": "on_template", "report_week_boundaries.py": "on_census",
        "verify-fields.php": "on_verify", "export-sqlite.py": "on_export", "crm-snapshot-audit.py": "on_crm_audit",
        "verify-crm-applicant-files.py": "on_applicants", "apply-reviewed-reverse-triggers.py": "on_triggers",
        "finance-step.py": "on_finance", "mysqld": "on_mysqld", "mysql": "on_mysql",
    }

    # Simulated tools ---------------------------------------------------
    def advance(self, seconds, timeout_s):
        if seconds > timeout_s:
            self.clock += timeout_s
            raise subprocess.TimeoutExpired("fake", timeout_s)
        self.clock += seconds

    def on_backup(self, argv, timeout_s, _input):
        shutil.copyfile(argv[-2], argv[-1])
        if os.name == "posix":
            os.chmod(argv[-1], 0o600)
        self.clock += 20.8
        return nightly.Completed(0, "sqlite_consistent_backup=passed\n", "")

    def on_scope(self, argv, timeout_s, _input):
        return nightly.Completed(0, json.dumps({"result": "verified", "source_scope_verified": True,
                                                "scope_sha256": option(argv, "--scope-sha256"),
                                                "snapshot_sha256": option(argv, "--snapshot-sha256"),
                                                "unlisted_tables": []}) + "\n", "")

    def on_migrate(self, argv, timeout_s, _input):
        mode = argv[argv.index(next(a for a in argv if a.endswith("migrate.py"))) + 1]
        output = Path(option(argv, "--output"))
        output.mkdir()
        (output / "schema-plan.json").write_text("[]\n", encoding="utf-8")
        result = {"mode": mode, "schema_sha256": SCHEMA_SHA, "tables": len(ALL_TABLES),
                  "legacy_exception_relationships": 8, "source_sha256": option(argv, "--snapshot-sha256")}
        if mode == "import-empty":
            self.advance(self.durations["import"], timeout_s)
            result |= {"import_verified": True, "original_rows": ROWS_PER_TABLE * len(ALL_TABLES),
                       "mysql_warning_count": 0, "triggers": 11}
        (output / "result.json").write_text(json.dumps(result), encoding="utf-8")
        return nightly.Completed(0, json.dumps(result) + "\n", "")

    def on_template(self, argv, timeout_s, _input):
        output = Path(option(argv, "--output"))
        output.mkdir()
        template = output / "schema-template.sqlite"
        template.write_bytes(b"template")
        record = {"result": "base-schema-template-created", "template_sha256": sha_bytes(b"template"),
                  "snapshot_sha256": option(argv, "--snapshot-sha256")}
        (output / "result.json").write_text(json.dumps(record), encoding="utf-8")
        return nightly.Completed(0, json.dumps(record) + "\n", "")

    def on_census(self, argv, timeout_s, _input):
        return nightly.Completed(0, json.dumps({"result": "observed", "snapshot_sha256": option(argv, "--snapshot-sha256"),
                                                "as_of_utc": option(argv, "--as-of-utc")}) + "\n", "")

    def on_verify(self, argv, timeout_s, _input):
        self.advance(self.durations["verify"], timeout_s)
        lines = [json.dumps({"event": "table-verified", "table": name, "rows": ROWS_PER_TABLE,
                             "fields": ROWS_PER_TABLE * 2}) for name in ALL_TABLES]
        lines.append(json.dumps({"result": "all-fields-verified", "field_mismatches": 0, "tables": len(ALL_TABLES),
                                 "rows": ROWS_PER_TABLE * len(ALL_TABLES), "fields": 2 * ROWS_PER_TABLE * len(ALL_TABLES),
                                 "source_sha256": option(argv, "--snapshot-sha256")}))
        return nightly.Completed(0, "\n".join(lines) + "\n", "")

    def on_mysqld(self, argv, timeout_s, _input):
        assert "--initialize-insecure" in argv
        return nightly.Completed(0, "", "")

    def on_mysql(self, argv, timeout_s, input):
        sql = (input or "") + " ".join(argv)
        if "SELECT VERSION()" in sql:
            return nightly.Completed(0, f"8.4.13\t{UUID}\t1\t1\tOFF\tROW\tFULL\tON\t1\t1\tON\n", "")
        if "super_read_only=ON" in sql:
            return nightly.Completed(0, "1\t1\n", "")
        if "super_read_only=OFF" in sql:
            return nightly.Completed(0, "0\t0\n", "")
        return nightly.Completed(0, "", "")

    def on_triggers(self, argv, timeout_s, _input):
        return nightly.Completed(0, json.dumps({"result": "reviewed-triggers-verified",
                                                "expected_mysql_triggers": 36}) + "\n", "")

    def on_export(self, argv, timeout_s, _input):
        output = Path(option(argv, "--output"))
        output.mkdir()
        recovered = output / "database.sqlite"
        recovered.write_bytes(b"recovered sqlite")
        record = {"verified": True, "tables": len(ALL_TABLES), "rows": ROWS_PER_TABLE * len(ALL_TABLES),
                  "field_mismatches": 0, "database_sha256": sha_bytes(b"recovered sqlite"),
                  "schema_template_sha256": option(argv, "--schema-template-sha256")}
        (output / "result.json").write_text(json.dumps(record), encoding="utf-8")
        self.clock += 60
        return nightly.Completed(0, json.dumps(record) + "\n", "")

    def on_crm_audit(self, argv, timeout_s, _input):
        record = {"schema": "repro.crm-snapshot-audit.v1", "result": "verified",
                  "snapshot_sha256": option(argv, "--snapshot-sha256"),
                  "comparison_snapshot_sha256": option(argv, "--compare-snapshot-sha256"),
                  "table_counts": {name: 1 for name in CRM_TABLES}, "migrations": {"count": 9},
                  "references": {"crm_contacts.client_user_id->users.id": {"null": 0, "linked": 1, "missing": 0}},
                  "parity": {"table_counts": True, "migrations": True, "references": True}}
        return nightly.Completed(0, json.dumps(record) + "\n", "")

    def on_applicants(self, argv, timeout_s, _input):
        return nightly.Completed(0, json.dumps({"result": "verified", "files": 0, "bytes_verified": 0,
                                                "snapshot_sha256": option(argv, "--snapshot-sha256")}) + "\n", "")

    def on_finance(self, argv, timeout_s, _input):
        snapshot_sha = option(argv, "--snapshot-sha256")
        receipt = {
            "scope": "private-finance-rehearsal", "status": "passed", "snapshot_derivative": "copy",
            "snapshot_sha256": snapshot_sha, "private_scratch_removed": True, "transient_unit_removed": True,
            "production_database_services_unchanged": True,
            "isolation": dict.fromkeys(nightly.FINANCE_ISOLATION, True),
            "rehearsal": {
                "result": "finance-comparison-completed", "release": option(argv, "--release"),
                "snapshot_sha256": snapshot_sha, "mysql_rows": ROWS_PER_TABLE * len(ALL_TABLES),
                "mysql_tables": len(ALL_TABLES), "report_weeks_compared": 8, "client_invoice_sample_count": 6,
                "external_effects": 0, "report_results_equal": True, "generated_results_equal": True,
                "report_comparisons": dict.fromkeys(nightly.FINANCE_REPORTS, True),
                "generation_comparisons": dict.fromkeys(nightly.FINANCE_GENERATIONS, True),
                "mysql_import_ms": 500_000,
            },
        }
        Path(option(argv, "--output")).write_text(json.dumps(receipt), encoding="utf-8")
        return nightly.Completed(0, json.dumps({"result": "finance-receipt-written"}) + "\n", "")


class NightlyRunnerTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.root = Path(folder.name)
        self.candidate_root = self.root / "candidate"
        self.state = self.root / "state"
        for relative in nightly.CANDIDATE_TOOLS.values():
            path = self.candidate_root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(f"reviewed bytes of {relative}\n", encoding="utf-8")
        self.scope_path = self.candidate_root / "database/schema/migration-scope.json"
        self.scope_path.parent.mkdir(parents=True, exist_ok=True)
        scope = {"schema": summarize_nightly_parity.SCOPE_SCHEMA, "tables": {name: {"class": "A"} for name in ALL_TABLES}}
        self.scope_path.write_text(json.dumps(scope) + "\n", encoding="utf-8")
        private = self.root / "private"
        private.mkdir()
        exceptions = private / "exceptions-private.json"
        exceptions.write_bytes(b"[]\n")
        wheel = private / "pymysql.whl"
        wheel.write_bytes(b"wheel")
        helper = private / "apply-reviewed-reverse-triggers.py"
        helper.write_text("reviewed helper\n", encoding="utf-8")
        finance = private / "finance-step.py"
        finance.write_text("reviewed finance step\n", encoding="utf-8")
        applicant_root = private / "applicant-disk"
        applicant_root.mkdir()
        self.config = {
            "schema": nightly.CONFIG_SCHEMA,
            "backend_root": str(self.root / "backend"),
            "candidate_root": str(self.candidate_root),
            "native_mysql_root": "/opt/repro-mysql84-native/mysql-8.4.13",
            "php": "/usr/bin/php8.3", "python": "/usr/bin/python3",
            "pymysql_wheel": {"path": str(wheel), "sha256": sha_bytes(b"wheel")},
            "deploy_lock": str(self.root / ".repro-backend-deploy.lock"),
            "scope": {"path": "database/schema/migration-scope.json", "sha256": sha_bytes(self.scope_path.read_bytes())},
            "approved_exceptions": {"path": str(exceptions), "sha256": sha_bytes(b"[]\n")},
            "reviewed_schema_sha256": SCHEMA_SHA,
            "crm_migrations": CRM_MIGRATIONS,
            "budget": {"import_and_verify_ms": 720_000, "fail_fast": True,
                       "baseline": {"snapshot_bytes": 4_460_900_352, "import_and_verify_ms": 596_700}},
            "guards": {"min_free_bytes": 45_000_000_000, "free_bytes_per_source_byte": 9,
                       "max_source_bytes": 6_000_000_000, "min_mem_available_kib": 12 * 1024**2,
                       "mem_margin_kib": 8 * 1024**2},
            "priority": {"nice": 10, "ionice_class": 3, "ionice_level": None, "capture_nice": 15},
            "mysql_tuning": {"innodb_buffer_pool_size": "4G", "innodb_redo_log_capacity": "2G"},
            "reverse_triggers": {"path": str(helper), "sha256": sha_bytes(helper.read_bytes())},
            "finance": {"argv": ["{python}", "-B", str(finance), "--snapshot", "{snapshot}",
                                 "--snapshot-sha256", "{snapshot_sha256}", "--release", "{release}",
                                 "--as-of-utc", "{as_of_utc}", "--output", "{output}"],
                        "pins": {str(finance): sha_bytes(finance.read_bytes())}, "timeout_s": 3600},
            "applicant_files_root": str(applicant_root),
            "retain_snapshots": None,
        }
        self.host = FakeHost(self.root)

    def approval(self, first="2026-11-20", last="2026-11-30", config=None):
        return {"schema": nightly.APPROVAL_SCHEMA, "first_day_utc": first, "last_day_utc": last,
                "config_sha256": nightly.Config(config or self.config).sha256}

    def runner(self, day="2026-11-26", host=None, config=None):
        return nightly.NightlyRunner(nightly.Config(config or self.config), host or self.host, self.state, day)

    def run_night(self, day="2026-11-26", host=None, config=None):
        return self.runner(day, host, config).run(self.approval(config=config))

    def outputs(self, day="2026-11-26"):
        return self.state / day

    def tool_calls(self, host=None):
        return [call for call in (host or self.host).calls]

    # Dry run and approval ---------------------------------------------
    def test_dry_run_plans_every_stage_without_side_effects(self):
        plan = self.runner().plan()
        self.assertEqual([stage["name"] for stage in plan["stages"]], list(nightly.STAGES))
        self.assertEqual(self.host.calls, [])
        self.assertFalse(self.state.exists())
        self.assertEqual(plan["live_release"], LIVE_RELEASE)
        self.assertEqual(plan["candidate_release"], CANDIDATE)
        self.assertTrue(plan["guards"]["free_space"]["ok"])
        self.assertEqual(plan["budget"]["import_and_verify_ms"], 720_000)
        self.assertIn("projected_ms", plan["budget"])
        self.assertTrue(plan["dry_run"])
        commands = [argv for stage in plan["stages"] for argv in stage["commands"]]
        self.assertTrue(any("backup-sqlite.php" in " ".join(argv) for argv in commands))

    def test_execute_requires_a_matching_owner_approval(self):
        for approval, reason in ((None, "approval-required"),
                                 (self.approval(first="2026-11-27"), "approval-day-outside-range"),
                                 (self.approval() | {"config_sha256": "0" * 64}, "approval-config-differs")):
            with self.subTest(reason=reason):
                with self.assertRaisesRegex(nightly.Refused, reason):
                    self.runner().run(approval)
        self.assertEqual(self.host.calls, [])
        self.assertFalse(self.state.exists())

    # Reviewer inputs ----------------------------------------------------
    def test_full_night_emits_reviewer_inputs_that_the_readiness_tools_accept(self):
        receipt = self.run_night()
        self.assertEqual(receipt["status"], "reviewer-inputs-ready", receipt)
        self.assertTrue(receipt["qualifying_draft"], receipt)
        out = self.outputs()
        night = json.loads((out / "night.json").read_bytes())
        crm = json.loads((out / "crm-review.json").read_bytes())
        self.assertEqual(set(night), check.NIGHTLY_REPORT_FIELDS)
        self.assertEqual(set(crm), check.NIGHTLY_CRM_FIELDS)
        self.assertEqual(night["release_sha"], CANDIDATE)
        self.assertEqual(night["scheduled_day_utc"], "2026-11-26")
        self.assertEqual(night["nightly_scope_sha256"], self.config["scope"]["sha256"])
        self.assertEqual(night["import_and_verify_ms"], 550_000)
        self.assertEqual(crm["crm_tables_verified"], len(CRM_TABLES))
        self.assertEqual(crm["verified_linked_client_count"], 1)
        entry = json.loads((out / "manifest-entry.json").read_bytes())
        self.assertEqual(entry["report_sha256"], sha_bytes((out / "night.json").read_bytes()))
        self.assertEqual(entry["source_release_sha"], LIVE_RELEASE)
        proof = verify_nightly_capture.verify(out / "night.json", entry["report_sha256"], out / "copy-receipt.json",
                                              entry["producer_receipt_sha256"],
                                              now=BASE_NOW + timedelta(hours=2), expected_source_release=LIVE_RELEASE)
        self.assertEqual(proof["snapshot_sha256"], night["snapshot_sha256"])
        timings = receipt["stage_ms"]
        self.assertEqual(set(timings), set(nightly.STAGES))
        self.assertEqual(receipt["import_ms"], 400_000)
        self.assertEqual(receipt["verify_ms"], 150_000)
        self.assertEqual(receipt["headroom_ms"], 170_000)

    def test_five_consecutive_runs_feed_the_nightly_summary(self):
        days = [(BASE_NOW.date() - timedelta(days=4 - index)) for index in range(5)]
        for day in days:
            host = FakeHost(self.root / f"host-{day}", now=BASE_NOW - (BASE_NOW.date() - day))
            self.assertEqual(self.run_night(day.isoformat(), host)["status"], "reviewer-inputs-ready")
        policy, _ = readiness_fixture()
        operations = policy["operations"]
        operations["nightly_scope_sha256"] = self.config["scope"]["sha256"]
        operations["last_nightly_day_utc"] = days[-1].isoformat()
        entries = [json.loads((self.outputs(day.isoformat()) / "manifest-entry.json").read_bytes()) for day in days]
        manifest = {"schema": summarize_nightly_parity.SCHEMA, "identity_sha256": digest(policy["identity"]),
                    "nightly_scope_sha256": operations["nightly_scope_sha256"], "nights": entries}
        manifest_path = self.root / "reviewed-nights.json"
        manifest_path.write_text(json.dumps(manifest) + "\n", encoding="utf-8")
        operations["nightly_data_parity_report_sha256"] = sha_bytes(manifest_path.read_bytes())
        folders = [self.outputs(day.isoformat()) for day in days]
        with patch.object(summarize_nightly_parity, "datetime", wraps=datetime) as clock:
            clock.now.return_value = BASE_NOW + timedelta(hours=6)
            summary = summarize_nightly_parity.summarize(
                policy, manifest_path, self.scope_path, [folder / "night.json" for folder in folders],
                [folder / "crm-review.json" for folder in folders],
                producer_paths=[folder / "copy-receipt.json" for folder in folders])
        self.assertEqual(len(summary["nights"]), check.NIGHTLY_RUN_COUNT)
        # A capture reused for a different night is refused.
        shutil.copyfile(folders[0] / "copy-receipt.json", folders[1] / "copy-receipt.json")
        manifest["nights"][1]["producer_receipt_sha256"] = manifest["nights"][0]["producer_receipt_sha256"]
        manifest_path.write_text(json.dumps(manifest) + "\n", encoding="utf-8")
        operations["nightly_data_parity_report_sha256"] = sha_bytes(manifest_path.read_bytes())
        with patch.object(summarize_nightly_parity, "datetime", wraps=datetime) as clock:
            clock.now.return_value = BASE_NOW + timedelta(hours=6)
            with self.assertRaisesRegex(check.Failed, "capture date differs|different snapshots"):
                summarize_nightly_parity.summarize(
                    policy, manifest_path, self.scope_path, [folder / "night.json" for folder in folders],
                    [folder / "crm-review.json" for folder in folders],
                    producer_paths=[folder / "copy-receipt.json" for folder in folders])

    def test_capture_must_finish_on_the_scheduled_utc_date(self):
        receipt = self.runner(day="2026-11-25").run(self.approval())
        self.assertEqual(receipt["status"], "stopped")
        self.assertEqual(receipt["reason"], "capture-date-differs")
        self.assertFalse((self.outputs("2026-11-25") / "copy-receipt.json").exists())
        # The live database is not read at all on the wrong day.
        self.assertNotIn("backup-sqlite.php", self.host.events)

    def test_capture_that_crosses_midnight_is_discarded(self):
        self.host.now = datetime(2026, 11, 26, 23, 59, 50, tzinfo=timezone.utc)
        receipt = self.run_night()
        self.assertEqual((receipt["status"], receipt["reason"]), ("stopped", "capture-date-differs"))
        self.assertIn("backup-sqlite.php", self.host.events)
        self.assertFalse((self.outputs() / "snapshot.sqlite").exists())
        self.assertFalse((self.outputs() / "copy-receipt.json").exists())

    def test_a_refusal_after_the_copy_is_a_journaled_stop_and_the_copy_is_removed(self):
        live_release = self.host.deployed_release
        reads = []

        def deploy_metadata():
            reads.append(1)
            if len(reads) > 1:  # unreadable when re-read after the copy, as during a deploy
                raise nightly.Refused("deploy-metadata-unreadable")
            return live_release()

        self.host.deployed_release = deploy_metadata
        receipt = self.run_night()
        self.assertEqual((receipt["status"], receipt["stopped_at"], receipt["reason"]),
                         ("stopped", "capture", "deploy-metadata-unreadable"))
        self.assertEqual(json.loads((self.outputs() / "journal.json").read_bytes())["status"], "stopped")
        self.assertTrue((self.outputs() / "run-receipt.json").exists())
        self.assertFalse((self.outputs() / "snapshot.sqlite").exists())

    def test_a_copy_that_fails_part_way_leaves_no_partial_snapshot(self):
        copy_file = self.host.on_backup

        def interrupted(argv, timeout_s, _input):
            copy_file(argv, timeout_s, _input)  # the backup tool is killed after writing part of the copy
            raise subprocess.TimeoutExpired("backup-sqlite.php", timeout_s)

        self.host.on_backup = interrupted
        receipt = self.run_night()
        self.assertEqual((receipt["status"], receipt["stopped_at"]), ("stopped", "capture"))
        self.assertFalse((self.outputs() / "snapshot.sqlite").exists())
        self.assertFalse((self.outputs() / "copy-receipt.json").exists())

    def test_unconfigured_finance_and_applicant_steps_leave_a_non_qualifying_draft(self):
        config = copy.deepcopy(self.config) | {"finance": None, "applicant_files_root": None}
        receipt = self.run_night(config=config)
        self.assertEqual(receipt["status"], "reviewer-inputs-ready")
        self.assertFalse(receipt["qualifying_draft"])
        night = json.loads((self.outputs() / "night.json").read_bytes())
        crm = json.loads((self.outputs() / "crm-review.json").read_bytes())
        self.assertFalse(night["invoice_and_payout_parity_verified"])
        self.assertFalse(crm["crm_client_import_dry_run_verified"])
        self.assertFalse(crm["crm_applicant_file_parity_verified"])
        self.assertIn("did not pass", receipt["draft_gate"])

    # Ordering, isolation and safety ------------------------------------
    def test_finance_runs_after_reverse_with_the_disposable_mysql_stopped(self):
        self.run_night()
        events = self.host.events
        self.assertLess(events.index("crm-snapshot-audit.py"), events.index("mysqld-stopped"))
        self.assertLess(events.index("mysqld-stopped"), events.index("finance-step.py"))
        self.assertEqual(events.count("mysqld-started"), 1)

    def test_commands_stay_on_the_host_and_never_reach_a_network(self):
        self.run_night()
        allowed = {"nice", "ionice", "php8.3", "python3", "mysqld", "mysql"}
        for argv in self.host.calls:
            executable = argv[0]
            self.assertIn(Path(executable).name, allowed, argv)
            joined = " ".join(argv).lower()
            for banned in ("ssh", "scp", "curl", "wget", "http://", "https://", "rclone", "systemctl", "sudo"):
                self.assertNotIn(banned, joined, argv)
        mysqld = next(argv for argv in self.host.calls if "--socket" in " ".join(argv) and "mysqld" in " ".join(argv))
        self.assertIn("--skip-networking", mysqld)
        for durable in ("--sync-binlog=1", "--innodb-flush-log-at-trx-commit=1", "--binlog-row-image=FULL",
                        "--innodb-doublewrite=ON"):
            self.assertIn(durable, mysqld)

    def test_guards_stop_before_any_tool_runs(self):
        cases = (
            ("free", 45_000_000_000, "insufficient-disk"),
            ("deploying", True, "deploy-in-progress"),
            ("candidate", (CANDIDATE, False), "candidate-checkout-dirty"),
        )
        for attribute, value, reason in cases:
            with self.subTest(reason=reason):
                host = FakeHost(self.root / f"guard-{reason}")
                setattr(host, attribute, value)
                receipt = self.run_night(day="2026-11-26", host=host)
                self.assertEqual((receipt["status"], receipt["reason"]), ("stopped", reason))
                self.assertEqual(host.calls, [])
                shutil.rmtree(self.outputs())

    def test_scope_pin_and_release_change_during_capture_are_refused(self):
        config = copy.deepcopy(self.config)
        config["scope"]["sha256"] = "0" * 64
        self.assertEqual(self.run_night(config=config)["reason"], "scope-pin-differs")
        shutil.rmtree(self.outputs())
        self.host.release_after_capture = "9" * 40
        receipt = self.run_night()
        self.assertEqual(receipt["reason"], "release-changed-during-capture")
        self.assertFalse((self.outputs() / "copy-receipt.json").exists())

    def test_memory_guard_uses_the_buffer_pool_size(self):
        config = copy.deepcopy(self.config)
        config["mysql_tuning"]["innodb_buffer_pool_size"] = "8G"
        self.host.mem_kib = 15 * 1024**2  # enough for 4G + 8G margin, not for 8G + 8G
        receipt = self.run_night(config=config)
        self.assertEqual((receipt["status"], receipt["reason"]), ("stopped", "insufficient-memory"))
        self.assertNotIn("mysqld-started", self.host.events)

    def test_one_runner_at_a_time(self):
        self.state.mkdir(mode=0o700)
        with nightly.RunLock(self.state):
            with self.assertRaisesRegex(nightly.Refused, "runner-locked"):
                self.runner().run(self.approval())
        self.assertEqual(self.host.calls, [])

    # Budget ------------------------------------------------------------
    def test_projection_over_budget_fails_fast_before_the_import(self):
        config = copy.deepcopy(self.config)
        size = self.host.live.stat().st_size
        config["budget"]["baseline"] = {"snapshot_bytes": size, "import_and_verify_ms": 720_001}
        receipt = self.run_night(config=config)
        self.assertEqual((receipt["status"], receipt["reason"]), ("stopped", "budget-at-risk"))
        self.assertGreater(receipt["projected_ms"], 720_000)
        self.assertNotIn("migrate.py import-empty", self.host.events)
        self.assertIn("mysqld-stopped", self.host.events)
        self.assertEqual(list(self.outputs().glob("work-*")), [])

    def test_import_is_killed_at_the_budget_and_later_stages_are_skipped(self):
        self.host.durations["import"] = 721.0
        receipt = self.run_night()
        self.assertEqual((receipt["status"], receipt["reason"]), ("stopped", "budget-exceeded"))
        import_call = next(call for call in self.host.calls if "import-empty" in call)
        self.assertIsNotNone(import_call)
        self.assertNotIn("verify-fields.php", self.host.events)
        self.assertNotIn("finance-step.py", self.host.events)
        self.assertEqual(receipt["stage_ms"]["import_and_verify"], 720_000)
        self.assertIn("capture", receipt["stage_ms"])
        self.assertFalse((self.outputs() / "night.json").exists())

    def test_verification_gets_only_the_remaining_budget(self):
        self.host.durations["verify"] = 320.1
        receipt = self.run_night()
        self.assertEqual(receipt["reason"], "budget-exceeded")
        self.assertEqual(receipt["import_ms"], 400_000)
        self.assertEqual(receipt["stage_ms"]["import_and_verify"], 720_000)

    def test_over_budget_measurement_can_continue_when_fail_fast_is_off(self):
        config = copy.deepcopy(self.config)
        config["budget"]["fail_fast"] = False
        self.host.durations["verify"] = 330.0
        receipt = self.run_night(config=config)
        self.assertEqual(receipt["status"], "reviewer-inputs-ready")
        self.assertFalse(receipt["qualifying_draft"])
        self.assertEqual(receipt["import_and_verify_ms"], 730_000)
        self.assertEqual(receipt["headroom_ms"], -10_000)

    # Tuning --------------------------------------------------------------
    def test_tuning_cannot_weaken_durability(self):
        for name, value in (("sync_binlog", 0), ("innodb_flush_log_at_trx_commit", 2),
                            ("innodb_doublewrite", "OFF"), ("skip_log_bin", True),
                            ("innodb_flush_method", "O_DIRECT_NO_FSYNC"), ("innodb_buffer_pool_size", "64G")):
            with self.subTest(name=name):
                config = copy.deepcopy(self.config)
                config["mysql_tuning"][name] = value
                with self.assertRaisesRegex(nightly.Refused, "tuning"):
                    nightly.Config(config)

    def test_allowed_tuning_is_passed_to_mysqld(self):
        config = copy.deepcopy(self.config)
        config["mysql_tuning"] |= {"innodb_redo_log_capacity": "8G", "innodb_io_capacity": 2000,
                                   "innodb_io_capacity_max": 4000, "innodb_flush_method": "O_DIRECT",
                                   "innodb_adaptive_hash_index": "OFF"}
        config["priority"] |= {"ionice_class": 2, "ionice_level": 7}
        receipt = self.run_night(config=config)
        self.assertEqual(receipt["status"], "reviewer-inputs-ready")
        mysqld = next(argv for argv in self.host.calls if any(value.startswith("--socket=") for value in argv))
        for flag in ("--innodb-redo-log-capacity=8G", "--innodb-io-capacity=2000", "--innodb-io-capacity-max=4000",
                     "--innodb-flush-method=O_DIRECT", "--innodb-adaptive-hash-index=OFF", "--sync-binlog=1"):
            self.assertIn(flag, mysqld)
        self.assertEqual(mysqld[:6], ["/usr/bin/nice", "-n", "10", "/usr/bin/ionice", "-c", "2"])
        self.assertEqual(receipt["tuning"]["innodb_io_capacity"], 2000)

    # Resume and re-run --------------------------------------------------
    def test_resume_reuses_capture_and_plan_and_restarts_the_mysql_group(self):
        self.host.fail.add("export-sqlite.py")
        first = self.run_night()
        self.assertEqual((first["status"], first["stopped_at"]), ("stopped", "reverse"))
        self.assertIn("mysqld-stopped", self.host.events)
        self.host.fail.clear()
        self.host.calls.clear()
        self.host.events.clear()
        second = self.run_night()
        self.assertEqual(second["status"], "reviewer-inputs-ready")
        self.assertNotIn("backup-sqlite.php", self.host.events)
        self.assertNotIn("migrate.py plan", self.host.events)
        self.assertIn("migrate.py import-empty", self.host.events)
        self.assertEqual(self.host.events.count("mysqld-started"), 1)
        journal = json.loads((self.outputs() / "journal.json").read_bytes())
        self.assertEqual(len(journal["attempts"]), 2)

    def test_a_completed_night_is_not_run_again(self):
        first = self.run_night()
        before = {path.name: path.read_bytes() for path in self.outputs().glob("*.json")}
        self.host.calls.clear()
        second = self.run_night()
        self.assertEqual(self.host.calls, [])
        self.assertEqual(second, first)
        self.assertEqual(before, {path.name: path.read_bytes() for path in self.outputs().glob("*.json")})

    def test_changed_evidence_blocks_resume(self):
        self.host.fail.add("export-sqlite.py")
        self.run_night()
        receipt = self.outputs() / "copy-receipt.json"
        receipt.write_bytes(receipt.read_bytes().replace(b"read-only", b"READ-only"))
        with self.assertRaisesRegex(nightly.Refused, "evidence-changed"):
            self.run_night()

    def test_stale_mysqld_from_an_interrupted_run_is_stopped_on_resume(self):
        self.host.fail.add("export-sqlite.py")
        self.run_night()
        journal = json.loads((self.outputs() / "journal.json").read_bytes())
        work = Path(journal["mysql"]["work"])
        work.mkdir()  # an interrupted process left its private work directory behind
        self.host.fail.clear()
        self.run_night()
        self.assertEqual(self.host.stale[0][1], str(work))
        self.assertFalse(work.exists())

    # Retention -----------------------------------------------------------
    def test_retention_prunes_only_older_runner_snapshots(self):
        config = copy.deepcopy(self.config) | {"retain_snapshots": 2}
        self.state.mkdir(mode=0o700)
        foreign = self.state / "not-a-run"
        foreign.mkdir()
        (foreign / "snapshot.sqlite").write_bytes(b"keep")
        days = [(BASE_NOW.date() - timedelta(days=3 - index)) for index in range(4)]
        for day in days:
            host = FakeHost(self.root / f"retain-{day}", now=BASE_NOW - (BASE_NOW.date() - day))
            self.assertEqual(self.run_night(day.isoformat(), host, config)["status"], "reviewer-inputs-ready")
        kept = [day for day in days if (self.outputs(day.isoformat()) / "snapshot.sqlite").exists()]
        self.assertEqual(kept, days[-2:])
        for day in days:
            self.assertTrue((self.outputs(day.isoformat()) / "copy-receipt.json").exists())
            self.assertTrue((self.outputs(day.isoformat()) / "night.json").exists())
        self.assertTrue((foreign / "snapshot.sqlite").exists())

    def test_default_retention_keeps_every_snapshot(self):
        days = [(BASE_NOW.date() - timedelta(days=1 - index)) for index in range(2)]
        for day in days:
            host = FakeHost(self.root / f"keep-{day}", now=BASE_NOW - (BASE_NOW.date() - day))
            self.run_night(day.isoformat(), host)
        for day in days:
            self.assertTrue((self.outputs(day.isoformat()) / "snapshot.sqlite").exists())


class ExitCodeTests(unittest.TestCase):
    def test_only_a_qualifying_draft_exits_zero(self):
        self.assertEqual(nightly.exit_code({"status": "reviewer-inputs-ready", "qualifying_draft": True}), 0)
        self.assertEqual(nightly.exit_code({"status": "reviewer-inputs-ready", "qualifying_draft": False}), 3)
        self.assertEqual(nightly.exit_code({"status": "stopped", "qualifying_draft": False}), 1)


class CommittedConfigTests(unittest.TestCase):
    def test_committed_config_is_valid_and_pins_the_reviewed_scope(self):
        path = HERE / "nightly-config.json"
        config = nightly.Config.load(path)
        repository = HERE.parents[2]
        scope = repository / config.data["scope"]["path"]
        self.assertEqual(config.data["scope"]["sha256"], sha_bytes(scope.read_bytes()))
        self.assertEqual(config.data["budget"]["import_and_verify_ms"], 12 * check.MINUTE)
        self.assertTrue(config.data["budget"]["fail_fast"])
        self.assertIsNone(config.data["retain_snapshots"])
        self.assertIsNone(config.data["finance"])

    def test_cli_checks_config_and_defaults_to_dry_run(self):
        path = HERE / "nightly-config.json"
        result = subprocess.run([sys.executable, "-B", "-m", "scripts.cutover.nightly.runner", "check-config",
                                 "--config", str(path)], capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(json.loads(result.stdout)["config_sha256"], nightly.Config.load(path).sha256)
        result = subprocess.run([sys.executable, "-B", "-m", "scripts.cutover.nightly.runner", "run",
                                 "--config", str(path), "--state-dir", "unused", "--day", "2026-11-26", "--execute"],
                                capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 2)
        self.assertIn("--approval", result.stderr)


if __name__ == "__main__":
    unittest.main()
