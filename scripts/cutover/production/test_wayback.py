"""Way-back controller proofs with private disposable SQLite files and a simulated server/host boundary."""
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from dataclasses import asdict, replace
from types import SimpleNamespace
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
import cli
import wayback as wb
from runner import Identity, Journal, UnsafeCutover

SERVER = "34645a82-0ad0-416e-a7d7-47a8fab78802"
PIN = Identity("fixture-01", "a" * 40, "b" * 64, "c" * 64, "d" * 64, SERVER)
REVOKED = ("evidence", "activation", "scheduler_release", "ingress_open")
POINT = {"file": "binlog.000001", "position": 400, "gtid_executed": SERVER + ":1-9"}


@unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0, "Requires the root-owned Linux rollback fixture")
class WayBackTest(unittest.TestCase):
    def setUp(self):
        root = tempfile.TemporaryDirectory(dir="/var/lib", prefix="repro-wayback-")
        self.addCleanup(root.cleanup)
        self.root = Path(root.name)
        self.a = Mock()
        self.a.pinned = PIN
        self.a.database = self.root / "database.sqlite"
        self.a.proc = self.root / "proc"
        self.a.proc.mkdir()
        self.a.config = {"mysql": {"mode": "cutover", "database": "repro_fixture", "socket": "/fixture/mysql.sock",
                                   "smoke_tables": ["users", "shoots", "aryeo_changes", "jobs"]},
                         "writer_processes": ["worker"], "scheduler_processes": ["scheduler"],
                         "fpm": {"socket": str(self.root / "fpm.sock")}, "command_seconds": 60}
        self.a.paths = {name: self.root / (name + ".json") for name in
                        (*REVOKED, "deploy_marker", "receipt", "evidence_report", "sqlite_seal_record")}
        self.a.clock.return_value = 50
        self.a.processes.return_value = []
        self.a.journal = Journal(self.root / "state")
        self.write(self.a.paths["deploy_marker"], {"version": 1, "run_id": PIN.run_id})
        self.receipt = {"version": 1, "run_id": PIN.run_id, "server_uuid": SERVER, "database": "repro_fixture",
                        "armed_from": {"file": "binlog.000001", "position": 150}}
        self.receipt_hash = self.write(self.a.paths["receipt"], self.receipt)
        self.prepared = {k: self.receipt[k] for k in ("run_id", "armed_from")}
        self.prepared["receipt_sha256"] = self.receipt_hash
        evidence_hash = self.write(self.a.paths["evidence_report"], self.prepared)
        self.state = {"version": 1, "identity": asdict(PIN), "writes_possible": True, "phase": "recovery_required",
                      "pending": None, "history": [],
                      "generation": 1, "evidence": {"report_sha256": evidence_hash}}
        self.a.journal.write(self.state)
        self.closed = {"version": 1, "run_id": PIN.run_id, "server_uuid": SERVER, "gate": "closed", "coordinate": POINT}
        self.close_path = self.a.journal.directory / wb.CLOSED
        self.close_hash = self.write(self.close_path, self.closed)
        self.audit_report = {"receipt": self.receipt_hash, "server_uuid": SERVER, "database": "repro_fixture",
                             "armed_from": self.receipt["armed_from"], "gate": "closed", "transactions": [],
                             "counts": {"business": 0, "smoke": 0, "foreign": 0}, "boundary": None,
                             "smoke_window": None}
        self.enterContext(patch.object(wb.host.evidence, "parse", side_effect=json.loads))
        self.enterContext(patch.object(wb.mysql_probe, "audit", side_effect=lambda config: self.audit_report))
        self.gate = self.enterContext(patch.object(wb.gate, "read", return_value=wb.gate.Reading("closed", {}, ())))
        self.listing = self.enterContext(patch.object(wb.mysql_probe, "_show", return_value=
            f"{SERVER}\nbinlog.000001\t400\t\t\t{SERVER}:1-9\nbinlog.000001\t400\tNo\n".encode()))

        self.make_sqlite(self.a.database, "frozen")
        self.a.database.chmod(0o440)
        self.initial = wb.sqlite_seal.file_sha256(self.a.database)
        self.a._sealed_sha256.return_value = (self.initial, None)
        self.write(self.a.paths["sqlite_seal_record"], {"version": 1, "database": str(self.a.database),
                                                      "modes": {str(self.a.database): 0o660}})
        self.a.identity.side_effect = lambda: (PIN if wb.sqlite_seal.file_sha256(self.a.database) == self.initial
                                               else replace(PIN, source_sha256="e" * 64))
        self.a._switch_record.return_value = {"run_id": PIN.run_id}
        self.a._sqlite_pin.return_value = "f" * 64
        self.a._smoke_commands.return_value = [([], "www-data", None), ([], "app", None)]
        self.fn = self.root / "review.json"
        self.effects = self.root / "effects.json"
        self.effects_sha = self.write(self.effects, {"all_fixture_effect_ledgers_reviewed": True})

    def write(self, path, value):
        wb.host._write_atomic(path, value, 0o600)
        return wb.guard.sha256(path.read_bytes())

    def make_sqlite(self, path, value):
        db = sqlite3.connect(path)
        with db:
            db.execute("CREATE TABLE ledger(id INTEGER PRIMARY KEY, entry TEXT)")
            db.execute("INSERT INTO ledger VALUES (1, ?)", (value,))
        db.close()
        path.chmod(0o600)

    def review(self, method="verified-prewrite", digest=None, **changes):
        review = {"version": 1, "run_id": PIN.run_id, "server_uuid": SERVER, "database": "repro_fixture",
                  "method": method, "installed_sha256": digest or self.initial, "close_sha256": self.close_hash,
                  "owner_approved": True, "effects": {label: "reconciled" for label in wb.EFFECTS},
                  "reconciliation_report": str(self.effects), "reconciliation_sha256": self.effects_sha, **changes}
        self.write(self.fn, review)
        return review

    def run_install(self, candidate=None, digest=None):
        return wb.install(self.a, candidate=candidate, digest=digest or self.initial, review_path=self.fn)

    def test_close_revokes_before_each_host_stop_and_grant_change(self):
        self.close_path.unlink()
        for name in REVOKED:
            self.write(self.a.paths[name], {})
        seen = []
        for name in ("_install_sqlite_wrappers", "close_admissions", "drain", "hold_and_drain_scheduler"):
            def action(*args, name=name, **kwargs):
                self.assertTrue(all(not self.a.paths[k].exists() for k in REVOKED))
                self.assertEqual("recovery_required", self.a.journal.read()["phase"])
                if args:
                    args[0].require_current()
                seen.append(name)
            getattr(self.a, name).side_effect = action
        with patch.object(wb.gate, "close_gate", side_effect=lambda cfg: seen.append("gate")):
            self.assertEqual(self.closed, wb.close(self.a))
            self.assertEqual(["_install_sqlite_wrappers", "close_admissions", "drain", "hold_and_drain_scheduler", "gate"], seen)
            self.assertEqual(self.closed, wb.close(self.a))
        self.assertEqual(0o600, self.close_path.stat().st_mode & 0o777)

    def test_failed_drain_leaves_all_admissions_closed_without_issuing_close(self):
        self.close_path.unlink()
        for name in REVOKED:
            self.write(self.a.paths[name], {})
        self.a.drain.side_effect = UnsafeCutover("unmonitored")
        with patch.object(wb.gate, "close_gate") as gate, self.assertRaisesRegex(UnsafeCutover, "unmonitored"):
            wb.close(self.a)
        gate.assert_not_called()
        self.assertFalse(self.close_path.exists())
        self.assertFalse(any(self.a.paths[k].exists() for k in REVOKED))

    def test_crash_during_first_unlink_cannot_restore_mysql_authority_on_refresh(self):
        self.a.journal.write({**self.state, "phase": "mysql_authoritative"})
        with patch.object(wb.host, "_remove", side_effect=OSError("power loss")), self.assertRaises(OSError):
            wb.close(self.a)
        self.assertEqual("recovery_required", self.a.journal.read()["phase"])
        self.a.close_admissions.assert_not_called()

    def test_interrupted_promotion_is_preserved_for_retirement_only_after_committed_close(self):
        self.close_path.unlink()
        self.state.update(phase="writes_possible", pending={"action": "promote", "at": 20})
        self.a.journal.write(self.state)
        with patch.object(wb.gate, "close_gate", side_effect=UnsafeCutover("unreachable")), self.assertRaises(UnsafeCutover):
            wb.close(self.a)
        self.assertEqual("promote", self.a.journal.read()["pending"]["action"])
        with patch.object(wb.gate, "close_gate"):
            wb.close(self.a)
            wb.close(self.a)
        state = self.a.journal.read()
        self.assertIsNone(state["pending"])
        self.assertEqual([{"action": "promote", "at": 20, "phase": "interrupted", "interrupted_by": "way-back close",
                           "close_sha256": self.close_hash}], state["history"])

    def test_reject_prewrite_or_other_run_before_revoking(self):
        self.write(self.a.paths["ingress_open"], {})
        for change in ({"writes_possible": False}, {"phase": "frozen"}, {"identity": {}}):
            self.a.journal.write({**self.state, **change})
            with self.assertRaisesRegex(UnsafeCutover, "post-write run"):
                wb.close(self.a)
            self.assertTrue(self.a.paths["ingress_open"].exists())
        self.a.journal.path.unlink()
        with self.assertRaisesRegex(UnsafeCutover, "post-write run"):
            wb.close(self.a)

    def test_stale_or_incomplete_close_refused(self):
        for reading in (wb.gate.Reading("open", {}, ()), wb.gate.Reading("closed", {}, (11,)), wb.gate.Reading("closed", {}, None)):
            self.gate.return_value = reading
            with self.assertRaisesRegex(UnsafeCutover, "zero app sessions"):
                wb.audit(self.a)
        self.gate.return_value = wb.gate.Reading("closed", {}, ())
        self.write(self.close_path, {**self.closed, "server_uuid": "other"})
        with self.assertRaisesRegex(UnsafeCutover, "changed after"):
            wb.audit(self.a)
        self.write(self.close_path, self.closed)
        self.write(self.a.paths["activation"], {})
        with self.assertRaisesRegex(UnsafeCutover, "activation"):
            wb.audit(self.a)

    def test_audit_receipt_and_prepared_bytes_must_bind(self):
        self.audit_report["receipt"] = "9" * 64
        with self.assertRaisesRegex(UnsafeCutover, "another run"):
            wb.audit(self.a)
        self.write(self.a.paths["evidence_report"], {"different": 1})
        with self.assertRaisesRegex(UnsafeCutover, "journal"):
            wb.audit(self.a)

    def add_transaction(self, kind, **more):
        self.audit_report["transactions"].append({"gtid": SERVER + ":6", "tables": ["users"], "statements": [],
                                                "class": kind, **more})
        self.audit_report["counts"][kind] += 1

    def test_business_and_uncertain_cannot_discard_rows(self):
        self.review()
        self.add_transaction("business", statements=["private record"])
        result = wb.audit(self.a)
        self.assertFalse(result["short_path_eligible"])
        self.assertNotIn("private record", json.dumps(result))
        with self.assertRaisesRegex(UnsafeCutover, "require a new reverse export"):
            self.run_install()
        self.assertEqual(0o440, self.a.database.stat().st_mode & 0o777)
        self.assertFalse((self.a.journal.directory / wb.INTENT).exists())

    def test_only_completed_c03_smoke_set_and_tables_are_eligible(self):
        self.audit_report["boundary"] = {"file": POINT["file"], "position": 200}
        self.audit_report["smoke_window"] = {"gtid_before": SERVER + ":1-5", "gtid_after": SERVER + ":1-9"}
        self.add_transaction("smoke", tables=["users", "shoots", "aryeo_changes"])
        for i in (7, 8, 9):
            self.add_transaction("smoke", gtid=SERVER + ":" + str(i), tables=["jobs"])
        for version in (None, 1):
            with patch.object(wb.mysql_probe, "AUTHENTICATED_SMOKE_WINDOW_VERSION", version, create=True):
                self.assertFalse(wb.audit(self.a)["short_path_eligible"])
        with patch.object(wb.mysql_probe, "AUTHENTICATED_SMOKE_WINDOW_VERSION", 2, create=True):
            self.assertFalse(wb.audit(self.a)["short_path_eligible"])
            self.audit_report["smoke_row_scope_verified"] = True
            self.assertTrue(wb.audit(self.a)["short_path_eligible"])
            row = self.audit_report["transactions"][0]
            for change in ({"tables": ["payments"]}, {"tables": []}, {"gtid": SERVER + ":4"},
                           {"gtid": SERVER + ":10"}, {"statements": ["opaque"]}):
                with patch.dict(row, change):
                    self.assertFalse(wb.audit(self.a)["short_path_eligible"])
            with patch.dict(self.audit_report["smoke_window"], {"gtid_after": SERVER + ":1-20"}):
                self.assertFalse(wb.audit(self.a)["short_path_eligible"])
            self.add_transaction("smoke")
            self.assertFalse(wb.audit(self.a)["short_path_eligible"])
            # A separate concurrent business write may have used the same affected tables.
            self.audit_report["transactions"][-1].update(gtid=SERVER + ":5", tables=["jobs"])
            self.audit_report["smoke_window"]["gtid_before"] = SERVER + ":1-4"
            self.assertFalse(wb.audit(self.a)["short_path_eligible"])

    def test_owner_and_external_effect_evidence_required(self):
        for change in ({"owner_approved": False}, {"run_id": "other"}, {"close_sha256": "0" * 64},
                       {"effects": {"payments": "unknown"}}, {"effects": None}, {"reconciliation_sha256": "0" * 64}):
            self.review(**change)
            with self.assertRaises(UnsafeCutover):
                self.run_install()
        self.review()
        self.fn.chmod(0o644)
        with self.assertRaisesRegex(UnsafeCutover, "root-private"):
            self.run_install()

    def test_short_path_keeps_original_inode_and_requires_held_boot(self):
        self.add_transaction("foreign")
        self.review()
        ino = self.a.database.stat().st_ino
        result = self.run_install()
        self.assertEqual("verified-prewrite", result["method"])
        self.assertTrue(result["writers_held"])
        self.assertEqual((self.initial, PIN.source_sha256, self.close_hash), tuple(result[k] for k in
                         ("installed_sha256", "source_sha256", "close_sha256")))
        self.assertEqual(ino, self.a.database.stat().st_ino)
        self.assertEqual(0o660, self.a.database.stat().st_mode & 0o777)
        self.assertTrue(self.a.paths["sqlite_seal_record"].is_file())
        self.assertEqual(2, self.a._readonly_smoke.call_count)
        self.assertEqual(result, self.run_install())
        self.assertFalse(any(self.a.paths[k].exists() for k in REVOKED))

    def export(self):
        folder = self.root / "new-export"
        folder.mkdir(mode=0o700)
        candidate = folder / "database.sqlite"
        self.make_sqlite(candidate, "the confirmed business entry")
        digest = wb.file_hash(candidate)
        self.write(folder / "result.json", {"verified": True, "tables": 1, "rows": 1, "field_mismatches": 0,
                   "foreign_keys_enabled": True, "database_sha256": digest, "source_server_uuid": SERVER,
                   "schema_template_sha256": self.initial})
        credentials = self.root / "credentials.json"
        self.write(credentials, {"user": "repro_parity_reader", "password": "synthetic", "unix_socket": "/fixture/mysql.sock"})
        plan = self.root / "schema-plan.json"
        plan.write_text('[{"name":"ledger"}]')
        plan.chmod(0o600)
        plan_sha = wb.file_hash(plan)
        self.review("verified-export", digest, schema_plan=str(plan), schema_plan_sha256=plan_sha,
                    reader_credentials=str(credentials), reviewed_reverse_layout=False)
        script = self.root / "verify-fields.php"
        script.write_text("<?php // synthetic verifier boundary")
        self.enterContext(patch.object(wb, "FIELD_READER", script))
        verified = {"tables": 1, "rows": 1, "result": "all-fields-verified", "field_mismatches": 0,
                    "source_sha256": digest, "schema_plan_sha256": plan_sha, "target_server_uuid": SERVER,
                    "target_database": "repro_fixture", "reader": "independent-php83"}
        proc = self.enterContext(patch.object(wb.subprocess, "run", return_value=
                                 subprocess.CompletedProcess([], 0, stdout=json.dumps(verified))))
        return candidate, digest, verified, proc

    def test_business_export_requires_independent_parity_before_installing(self):
        candidate, digest, report, process = self.export()
        self.add_transaction("business")
        for change in ({"rows": 2}, {"source_sha256": "0" * 64}, {"field_mismatches": 1}, {"target_database": "other"}):
            process.return_value.stdout = json.dumps({**report, **change})
            with self.assertRaisesRegex(UnsafeCutover, "Independent verification"):
                self.run_install(candidate, digest)
            self.assertEqual(self.initial, wb.sqlite_seal.file_sha256(self.a.database))
        process.side_effect = subprocess.TimeoutExpired([], 2)
        with self.assertRaisesRegex(UnsafeCutover, "timed out"):
            self.run_install(candidate, digest)
        self.assertFalse((self.a.journal.directory / wb.INSTALLED).exists())

    def test_new_export_survives_worker_crash_after_atomic_install(self):
        candidate, digest, _, process = self.export()
        preview = self.enterContext(patch.object(wb, "staged_smoke"))
        self.add_transaction("business")
        old_inode = self.a.database.stat().st_ino
        self.a._seal_sqlite_config.side_effect = UnsafeCutover("crash after swap")
        with self.assertRaisesRegex(UnsafeCutover, "crash"):
            self.run_install(candidate, digest)
        archived = self.root / ("frozen-" + PIN.run_id) / self.a.database.name
        self.assertEqual(old_inode, archived.stat().st_ino)
        self.assertEqual(self.initial, wb.sqlite_seal.file_sha256(archived))
        self.assertEqual(0o440, self.a.database.stat().st_mode & 0o777)
        self.assertFalse((self.a.journal.directory / wb.INSTALLED).exists())
        self.a._seal_sqlite_config.side_effect = None
        result = self.run_install(candidate, digest)
        self.assertEqual(("verified-export", digest, "e" * 64), tuple(result[k] for k in
                         ("method", "installed_sha256", "source_sha256")))
        self.assertNotEqual(old_inode, self.a.database.stat().st_ino)
        verify_argv = process.call_args.args[0]
        self.assertIn("--expected-server-uuid=" + SERVER, verify_argv)
        self.assertIn("--writers-stopped", verify_argv)
        with sqlite3.connect("file:" + str(self.a.database) + "?mode=ro", uri=True) as db:
            self.assertEqual(("the confirmed business entry",), db.execute("SELECT entry FROM ledger").fetchone())
        self.assertTrue(self.a.paths["sqlite_seal_record"].is_file())
        self.assertFalse(any(self.a.paths[k].exists() for k in REVOKED))
        self.assertEqual(result, self.run_install(candidate, digest))
        self.assertEqual(3, preview.call_count, "every retry revalidates the installed file under a private read-only mount")

    def test_failed_staged_smoke_preserves_frozen_sqlite_and_live_cache(self):
        candidate, digest, _, _ = self.export()
        shm = Path(str(self.a.database) + "-shm")
        shm.write_bytes(b"original-checkpointed-index")
        wal = Path(str(self.a.database) + "-wal")
        wal.touch()
        old_sidecars = [(p.stat().st_ino, p.read_bytes()) for p in (shm, wal)]
        with patch.object(wb, "staged_smoke", side_effect=UnsafeCutover("staged smoke failed")):
            with self.assertRaisesRegex(UnsafeCutover, "staged smoke failed"):
                self.run_install(candidate, digest)
        self.assertEqual(self.initial, wb.sqlite_seal.file_sha256(self.a.database))
        self.assertEqual(old_sidecars, [(p.stat().st_ino, p.read_bytes()) for p in (shm, wal)])
        self.a._seal_sqlite_config.assert_not_called()
        self.assertFalse((self.a.journal.directory / wb.INSTALLED).exists())

    def test_native_staged_mount_is_private_readonly_or_fails_closed_without_capability(self):
        self.root.chmod(0o711)
        live = self.root / "app-db"
        live.mkdir()
        self.a.database = live / "database.sqlite"
        self.make_sqlite(self.a.database, "original")
        conn = sqlite3.connect(self.a.database)
        self.addCleanup(conn.close)
        self.assertEqual("wal", conn.execute("PRAGMA journal_mode=WAL").fetchone()[0])
        conn.execute("PRAGMA wal_autocheckpoint=0")
        conn.execute("INSERT INTO ledger VALUES (2, 'confirmed transaction only in original WAL')")
        conn.commit()
        def original_files():
            return {p.name: (p.stat().st_ino, p.read_bytes()) for p in wb.sqlite_seal.companions(self.a.database) if p.exists()}
        before = original_files()
        self.assertGreater(len(before["database.sqlite-wal"][1]), 32)
        self.assertIn("database.sqlite-shm", before)
        hidden = live / "root-only"
        hidden.mkdir(mode=0o700)
        source = hidden / "new.sqlite"
        self.make_sqlite(source, "the verified replacement")
        conn_new = sqlite3.connect(source)
        self.assertEqual("wal", conn_new.execute("PRAGMA journal_mode=WAL").fetchone()[0])
        conn_new.close()
        source.chmod(0o444)
        expected = "the verified replacement"
        self.a.config.update(app_owner="www-data", smoke={})
        code = ("import os,sqlite3,sys; "
                "assert os.statvfs(sys.argv[1]).f_flag & os.ST_RDONLY; "
                "assert os.path.isfile(os.environ['APP_CONFIG_CACHE']); "
                "db=sqlite3.connect(sys.argv[1]); assert db.execute('pragma journal_mode=wal').fetchone()[0]=='wal'; "
                "assert db.execute('select count(*) from ledger').fetchone()[0]==1; "
                "print(db.execute('SELECT entry FROM ledger').fetchone()[0])")
        argv = ["/usr/sbin/runuser", "-u", "www-data", "--", "/usr/bin/python3", "-c", code, str(self.a.database)]
        vendor = os.environ.get("REPRO_WAYBACK_LARAVEL_VENDOR")
        if vendor:
            lib = self.root / "framework"
            lib.mkdir(mode=0o755)
            for name in ("DetectsLostConnections", "Connectors/ConnectorInterface", "Connectors/Connector", "Connectors/SQLiteConnector"):
                dest = lib / (Path(name).name + ".php")
                shutil.copyfile(Path(vendor) / "laravel/framework/src/Illuminate/Database" / (name + ".php"), dest)
                dest.chmod(0o444)
            php = r"""foreach (['DetectsLostConnections', 'ConnectorInterface', 'Connector', 'SQLiteConnector'] as $name) {
                require $argv[2].'/'.$name.'.php';
            }
            $pdo = (new Illuminate\Database\Connectors\SQLiteConnector)->connect([
                'database' => $argv[1], 'journal_mode' => 'wal', 'busy_timeout' => 5000,
                'synchronous' => 'normal', 'foreign_key_constraints' => true
            ]);
            try {
                $pdo->exec("INSERT INTO ledger VALUES (90, 'must fail on read-only mount')");
                exit(4);
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? 0) !== 8) exit(5);
            }
            $pdo->exec('PRAGMA query_only=ON');
            $rows = $pdo->query('SELECT entry FROM ledger')->fetchAll(PDO::FETCH_COLUMN);
            if (count($rows) !== 1) exit(6);
            echo $rows[0];"""
            argv = ["/usr/sbin/runuser", "-u", "www-data", "--", "/usr/local/bin/php", "-r", php,
                    str(self.a.database), str(lib)]
        self.a._smoke_commands.return_value = [(argv, "www-data", None)]

        def execute(argv, deadline):
            proc = subprocess.run(argv, cwd="/", capture_output=True, text=True, timeout=10)
            return proc.returncode, proc.stdout.strip()

        def stage(config, *, parent, inspect_cache, expected_sha256, **options):
            cache = Path(parent) / "config.php"
            cache.write_text("fixture")
            cache.chmod(0o444)
            values = {"database.default": "sqlite", "database.connections": {"sqlite": {"journal_mode": "wal"}}}
            inspect_cache(cache, SimpleNamespace(live_config_sha256=expected_sha256, values=values))

        def read_only(output, returncode, *args):
            wb.need(returncode == 0 and output == expected, "staged application smoke refused")

        self.a._execute.side_effect = execute
        capabilities = next(line.split()[1] for line in Path("/proc/self/status").read_text().splitlines() if line.startswith("CapEff:"))
        with patch.object(wb.config_build, "stage", side_effect=stage), patch.object(wb.host.evidence, "readonly_check", side_effect=read_only):
            if int(capabilities, 16) & (1 << 21):  # CAP_SYS_ADMIN may mount, but only in the throwaway namespace.
                wb.staged_smoke(self.a, source, 100)
            else:
                with self.assertRaisesRegex(UnsafeCutover, "staged application smoke refused"):
                    wb.staged_smoke(self.a, source, 100)
            mismatched = hidden / "delete-format.sqlite"
            self.make_sqlite(mismatched, "different format")
            with self.assertRaisesRegex(UnsafeCutover, "--sqlite-journal-mode wal"):
                wb.staged_smoke(self.a, mismatched, 100)
        self.assertEqual(before, original_files(), "staged SQLite must never see or modify original WAL/SHM")
        self.assertEqual(2, conn.execute("SELECT COUNT(*) FROM ledger").fetchone()[0])
        self.assertEqual([], list(self.root.glob("wayback-smoke-*")))

    def test_unexpected_sqlite_sidecar_stops_before_swap(self):
        candidate, digest, _, _ = self.export()
        Path(str(self.a.database) + "-wal").write_bytes(b"unreconciled WAL")
        with self.assertRaisesRegex(UnsafeCutover, "sidecar"):
            self.run_install(candidate, digest)
        self.assertEqual(self.initial, wb.sqlite_seal.file_sha256(self.a.database))
        self.assertFalse((self.a.journal.directory / wb.INSTALLED).exists())

    def test_cli_parses_distinct_approved_methods(self):
        args = cli.arguments(["way-back", "install-sqlite", "--keep-frozen", "--sha256", self.initial,
                              "--review", str(self.fn)])
        self.assertIsNone(args.file)
        self.assertTrue(args.keep_frozen)
        self.assertEqual("close", cli.arguments(["way-back", "close"]).recovery_action)
        self.assertEqual("audit", cli.arguments(["way-back", "audit"]).recovery_action)
