"""Verify post-write failback in the disposable synthetic Docker fixture."""

from __future__ import annotations

import importlib.util
import io
import json
import secrets
import sys
import time
from argparse import Namespace
from contextlib import closing, redirect_stdout
from pathlib import Path

from rehearse import (
    DATABASE, FAILBACK, SNAPSHOT, SOCKET, SOURCE, TABLES, ContainerRehearsal, file_digest, load_pymysql,
    prepare_fixture, require, sqlite_connect, sqlite_rows,
)
from scripts.cutover.runner import UnsafeCutover

SOURCE_TOOLS = Path("/workspace/scripts/mysql")
UNCOMMITTED = "uncommitted-synthetic-effect"
MYSQL_RECEIPT = "synthetic-mysql-receipt"
READ_RECEIPT = "synthetic-http-receipt"
REOPENED_RECEIPT = "synthetic-sqlite-reopened"


class PostWriteRehearsal(ContainerRehearsal):
    def configure_databases(self):
        super().configure_databases()
        self.reverse_password = secrets.token_hex(32)
        with self.admin.cursor() as cursor:
            cursor.execute("SET GLOBAL super_read_only=OFF")
            cursor.execute("SET GLOBAL read_only=OFF")
            try:
                cursor.execute("CREATE USER 'dresser_reverse'@'localhost' IDENTIFIED BY %s", (self.reverse_password,))
                cursor.execute(f"GRANT SELECT ON {DATABASE}.* TO 'dresser_reverse'@'localhost'")
            finally:
                cursor.execute("SET GLOBAL super_read_only=ON")

    def allow_fixture_mysql(self, runner):
        # Deliberately outside Runner authority. This never supplies an off-site
        # success flag, publishes authority, or replaces the cutover journal.
        with self.journal.acquire():
            state = runner.status()
            evidence = self.evidence()
            require(state["pending"]["action"] == "prepare" and not state["writes_possible"]
                    and evidence.sqlite_backup_offsite is False and evidence.mysql_backup_offsite is False
                    and self.observe().frozen() and self.gate() == {"engine": "mysql", "open": False},
                    "The closed and verified fixture state is required")
            self.journal.write_record("postwrite-fixture.json", {"synthetic": True, "phase": "mysql-test"})
            with self.admin.cursor() as cursor:
                cursor.execute("SET GLOBAL super_read_only=OFF")
                cursor.execute("SET GLOBAL read_only=OFF")
            self.set_gate("mysql", True)
            require(self.observe().mysql_live(), "The isolated MySQL fixture could not accept the test write")

    def client_sessions(self):
        with self.admin.cursor() as cursor:
            cursor.execute("SHOW FULL PROCESSLIST")
            return {row[0]: row[1] for row in cursor.fetchall() if row[1] == "dresser"}

    def seal_after_test_write(self, writer):
        with self.journal.acquire():
            closed_at = time.monotonic()
            self.set_gate("mysql", False)
            require(self.http("POST", 503) == {"admission_held": True}, "HTTP writer escaped fixture hold")
            require(self.queue("work") == {"admission_held": True}, "Queue worker escaped fixture hold")
            writer_id = writer.thread_id()
            require(self.client_sessions() == {writer_id: "dresser"}, "An untracked fixture MySQL session exists")
            with self.admin.cursor() as cursor:
                cursor.execute(f"KILL CONNECTION {writer_id:d}")
            for _ in range(50):
                if not self.client_sessions():
                    break
                time.sleep(0.02)
            else:
                raise UnsafeCutover("The isolated in-flight writer failed to drain")
            with self.admin.cursor() as cursor:
                cursor.execute("SET GLOBAL super_read_only=ON")
                cursor.execute("SELECT @@global.read_only, @@global.super_read_only")
                require(cursor.fetchone() == (1, 1), "MySQL read-only seal failed")

            with closing(self.fixture_mysql()) as probe, probe.cursor() as cursor:
                try:
                    cursor.execute("INSERT INTO fixture_effects VALUES ('write-after-seal')")
                except self.pymysql.MySQLError as error:
                    require(error.args[0] == 1290, "Unexpected MySQL write refusal")
                else:
                    raise UnsafeCutover("MySQL admitted a write after the fixture hold")
            require(not self.client_sessions(), "MySQL fixture clients did not quiesce")
            self.journal.write_record("postwrite-fixture.json", {"synthetic": True, "phase": "writers-held"})
            return closed_at

    def export_fresh_sqlite(self, state, sealed_rows):
        marker = json.loads((self.journal.directory / "postwrite-fixture.json").read_text())
        require(marker == {"synthetic": True, "phase": "writers-held"}
                and state["phase"] == "recovery_required" and not self.gate()["open"]
                and self.gate()["engine"] == "mysql" and not self.observe().mysql_writable,
                "Fresh export requires the held and quiescent fixture")
        self.journal.write_record("reverse-credentials.json", {
            "unix_socket": SOCKET, "user": "dresser_reverse", "password": self.reverse_password,
        })
        exceptions = self.journal.directory / "empty-exceptions.json"
        with exceptions.open("x", encoding="utf-8") as stream:
            stream.write("[]\n")

        export_path = SOURCE_TOOLS / "export-sqlite.py"
        sys.path.insert(0, str(SOURCE_TOOLS))
        spec = importlib.util.spec_from_file_location("rehearsal_reverse_export", export_path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        try:
            with redirect_stdout(io.StringIO()):
                module.export(Namespace(
                    credentials=self.journal.directory / "reverse-credentials.json",
                    database=DATABASE, expected_server_uuid=self.native_uuid, schema_template=SNAPSHOT,
                    schema_template_sha256=file_digest(SNAPSHOT), approved_exceptions=exceptions,
                    exceptions_file_sha256=file_digest(exceptions), output=FAILBACK.parent, writers_stopped=True,
                ))
        finally:
            (self.journal.directory / "reverse-credentials.json").unlink()

        artifact = json.loads((FAILBACK.parent / "result.json").read_text())
        field_report = json.loads((FAILBACK.parent / "data-verification.json").read_text())
        require(FAILBACK.is_file() and not FAILBACK.samefile(SOURCE)
                and artifact["database_sha256"] == file_digest(FAILBACK) and artifact["verified"]
                and artifact["field_mismatches"] == 0 and artifact["tables"] == len(TABLES)
                and artifact["foreign_keys_enabled"] and not artifact["exception_relationships"]
                and {field["table"]: field["rows"] for field in field_report
                     if field["match"] and field["mysql_sha256"] == field["sqlite_sha256"]}
                == {name: len(rows) for name, rows in sealed_rows.items()}
                and sqlite_rows(FAILBACK) == sealed_rows == self.rows_mysql(),
                "Fresh SQLite field or artifact verification failed")
        with closing(sqlite_connect(FAILBACK)) as connection:
            require(connection.execute("PRAGMA quick_check").fetchall() == [("ok",)]
                    and connection.execute("PRAGMA foreign_key_check").fetchall() == [],
                    "Fresh SQLite integrity verification failed")
        return artifact["database_sha256"]

    def reopen_fixture(self, runner, acknowledged, recovered_digest):
        with self.journal.acquire():
            require(runner.status()["phase"] == "recovery_required" and not self.gate()["open"]
                    and not self.observe().mysql_writable and not self.client_sessions()
                    and file_digest(FAILBACK) == recovered_digest,
                    "The verified fresh SQLite artifact is required before fixture reopening")
            self.sqlite_path = FAILBACK
            self.set_gate("sqlite", False)
            self.build_cache("sqlite")
            expected = {**acknowledged, "engine": "sqlite", "pending": 1}
            require(self.open_server("sqlite") == expected, "The reopened Laravel snapshot differs")
            require(self.http("POST", 503) == {"admission_held": True}, "Fixture reopened too early")
            self.journal.write_record("postwrite-fixture.json", {"synthetic": True, "phase": "sqlite-recovered"})
            self.set_gate("sqlite", True)
            require(self.http() == expected, "The acknowledged MySQL receipt was not available in Laravel")


def exercise(adapter):
    runner = prepare_fixture(adapter, queue_before_freeze=False)
    old_sqlite_sha = file_digest(SOURCE)
    old_sqlite_rows = sqlite_rows(SOURCE)

    adapter.allow_fixture_mysql(runner)
    acknowledged = adapter.http("POST")
    require(acknowledged["engine"] == "mysql" and set(acknowledged["receipts"]) == {READ_RECEIPT, MYSQL_RECEIPT},
            "The MySQL Laravel notification receipt was not acknowledged")
    require(adapter.queue("enqueue") == {"queued": 1}, "The MySQL-backed Laravel queue write failed")

    with closing(adapter.fixture_mysql()) as writer, writer.cursor() as cursor:
        writer.begin()
        cursor.execute("INSERT INTO fixture_effects VALUES (%s)", (UNCOMMITTED,))
        cursor.execute("SELECT receipt FROM fixture_effects")
        require(cursor.fetchall() == ((UNCOMMITTED,),) and adapter.rows_mysql()["fixture_effects"] == [],
                "Expected one isolated, uncommitted MySQL write")
        closed_at = adapter.seal_after_test_write(writer)
        try:
            cursor.execute("SELECT 1")
        except adapter.pymysql.MySQLError as error:
            require(error.args[0] in (2006, 2013), "The drained writer did not disconnect")
        else:
            raise UnsafeCutover("The drained writer stayed connected")

    try:
        runner.perform("abort")
    except UnsafeCutover as error:
        require(str(error) == "MySQL writes may exist. Use separately verified recovery",
                "Unexpected refusal after the acknowledged MySQL write")
    else:
        raise UnsafeCutover("Runner attempted pre-write rollback after MySQL business activity")
    state = runner.status()
    require(state["phase"] == "recovery_required" and not (adapter.journal.directory / "proof.json").exists(),
            "Runner did not hold authority for separate recovery")
    sealed = adapter.rows_mysql()
    require(sealed["fixture_effects"] == [] and len(sealed["jobs"]) == 1, "Drained writes did not reconcile")
    require(json.loads(sealed["notification_read_states"][0][3]) == acknowledged["receipts"],
            "MySQL acknowledged receipts differ from database state")

    recovered_digest = adapter.export_fresh_sqlite(state, sealed)
    try:
        adapter.reopen_fixture(runner, acknowledged, "0" * 64)
    except UnsafeCutover as error:
        require(str(error) == "The verified fresh SQLite artifact is required before fixture reopening"
                and adapter.gate() == {"engine": "mysql", "open": False},
                "The fixture did not hold writers after rejecting the unexpected digest")
    else:
        raise UnsafeCutover("The fixture reopened with the wrong artifact digest")
    adapter.reopen_fixture(runner, acknowledged, recovered_digest)
    reopened_at = time.monotonic()
    one_effect = {"worker_exit": 0, "effects": ["synthetic-queue-effect"]}
    require(adapter.queue("work") == one_effect and adapter.queue("work") == one_effect
            and adapter.http()["pending"] == 0 and sqlite_rows(FAILBACK)["fixture_effects"] == [("synthetic-queue-effect",)],
            "Reopened Laravel did not drain exactly one durable queue effect")
    fresh_http = adapter.http("POST")
    reopened_receipts = fresh_http["receipts"]
    require(fresh_http["engine"] == "sqlite"
            and type(reopened_receipts.pop(REOPENED_RECEIPT, None)) is int
            and reopened_receipts == acknowledged["receipts"],
            "Laravel did not accept a new write on the verified SQLite artifact")
    require(file_digest(SOURCE) == old_sqlite_sha and sqlite_rows(SOURCE) == old_sqlite_rows
            and adapter.rows_mysql() == sealed and runner.status()["phase"] == "recovery_required",
            "The original database, sealed MySQL, or Runner authority changed")

    return {
        "result": "postwrite-fixture-failback-passed", "release": adapter.identity().release_sha,
        "fixture_only": True, "runner_offsite_gate_refused": True, "runner_authority": "recovery_required",
        "freeze_to_reopened_http_ms": round((reopened_at - closed_at) * 1000),
        "acknowledged_mysql_receipts_recovered": 1, "uncommitted_mysql_writes_recovered": 0,
        "queue_effects": 1, "new_sqlite_http_write": True, "fresh_sqlite_artifact": True, "old_sqlite_preserved": True,
        "mysql_writer_disconnected": True, "mysql_sealed_readonly": True, "wrong_artifact_digest_refused": True,
        "verified_tables": len(sealed), "verified_rows_before_queue": sum(map(len, sealed.values())),
        "sqlite_export_sha256_before_queue": recovered_digest,
        "harness_sha256": file_digest(Path(__file__)), "adapter_sha256": file_digest(Path("/host/rehearse.py")),
        "php_probe_sha256": file_digest(Path("/host/probe.php")),
        "reverse_exporter_sha256": file_digest(SOURCE_TOOLS / "export-sqlite.py"),
        "scope": "offline synthetic Laravel HTTP and one-shot database queue; independent read-only native export",
    }


if __name__ == "__main__":
    with closing(PostWriteRehearsal(load_pymysql(), *sys.argv[1:])) as adapter:
        print(json.dumps(exercise(adapter), sort_keys=True))
