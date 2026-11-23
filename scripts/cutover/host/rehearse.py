"""Exercise the real journal, a private Laravel smoke path and both databases."""

from __future__ import annotations

import base64
import hashlib
import http.client
import json
import os
import secrets
import sqlite3
import stat
import subprocess
import sys
import time
import uuid
from contextlib import closing
from dataclasses import asdict
from pathlib import Path

from scripts.cutover.fence import guard
from scripts.cutover.runner import Evidence, Identity, Journal, Observation, Runner, SchedulerDrain, UnsafeCutover, digest

PRIVATE = Path("/private")
SOURCE = PRIVATE / "source.sqlite"
SNAPSHOT = PRIVATE / "snapshot.sqlite"
CONFIG = PRIVATE / "active-config.php"
STORAGE = PRIVATE / "storage"
SOCKET = "/fixture-socket/mysql.sock"
DATABASE = "repro_dress_fixture"
TABLES = (
    ("users", "id"),
    ("notification_read_states", "user_id"),
    ("jobs", "id"),
    ("fixture_effects", "receipt"),
)
DEFINITIONS = (
    "users (id BIGINT PRIMARY KEY)",
    "notification_read_states (user_id BIGINT NOT NULL, context VARCHAR(90) NOT NULL, "
    "last_seen_at BIGINT NULL, read_ids TEXT NOT NULL, created_at VARCHAR(40), updated_at VARCHAR(40), "
    "PRIMARY KEY(user_id, context), FOREIGN KEY (user_id) REFERENCES users(id))",
    "jobs (id {sequence}, queue VARCHAR(90) NOT NULL, payload TEXT NOT NULL, attempts BIGINT NOT NULL, "
    "reserved_at BIGINT NULL, available_at BIGINT NOT NULL, created_at BIGINT NOT NULL)",
    "fixture_effects (receipt VARCHAR(90) PRIMARY KEY)",
)


def require(valid: object, reason: str):
    if not valid:
        raise UnsafeCutover(reason)


def file_digest(path: Path) -> str:
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def sqlite_connect(path=SOURCE):
    return sqlite3.connect(path, timeout=1)


def sqlite_rows(path):
    with closing(sqlite_connect(path)) as connection:
        return {name: connection.execute(f"SELECT * FROM {name} ORDER BY {key}").fetchall() for name, key in TABLES}


def mysql_rows(connection):
    with connection.cursor() as cursor:
        result = {}
        for name, key in TABLES:
            cursor.execute(f"SELECT * FROM {name} ORDER BY {key}")
            result[name] = list(cursor.fetchall())
        return result


class ContainerRehearsal:
    """An adapter limited to the small, manufactured Docker fixture."""

    def __init__(self, pymysql, release, artifact):
        require(os.getuid() == 1000 and Path("/workspace").is_dir() and Path("/host").is_dir(),
                "The disposable fixture entrypoint is required")
        require(stat.S_ISSOCK(os.stat(SOCKET).st_mode), "The private fixture socket is unavailable")
        require(len(Path("/proc/net/route").read_text().splitlines()) <= 1, "The fixture requires no default routes")
        require(not SOURCE.exists() and not CONFIG.exists() and not Path("/workspace/.env").exists(),
                "A fresh fixture and clean application tree are required")
        self.pymysql = pymysql
        self.server = None
        self.mysql_password = secrets.token_hex(32)
        self.app_key = "base64:" + base64.b64encode(secrets.token_bytes(32)).decode()
        try:
            self.admin = self.mysql("root", "", None)
            self.configure_databases()
            self.mysql_initial = self.rows_mysql()
            self.imported = None
            self.journal = Journal(PRIVATE / "authority")
            self._boot_id = Path("/proc/sys/kernel/random/boot_id").read_text().strip()
            self._identity = Identity(
                "docker-synthetic-dress", release, artifact, file_digest(SOURCE),
                digest({"socket": SOCKET, "database": DATABASE, "sqlite": str(SOURCE), "key": self.app_key}),
                self.native_uuid,
            )
            for folder in ("framework/cache/data", "framework/sessions", "framework/views", "logs"):
                (STORAGE / folder).mkdir(parents=True)
            self.set_gate("sqlite", True)
            self.build_cache("sqlite")
            self.sqlite_config = CONFIG.read_bytes()
            self.open_server("sqlite")
        except BaseException:
            self.close()
            raise

    def mysql(self, user, password, database):
        return self.pymysql.connect(
            unix_socket=SOCKET, user=user, password=password, database=database,
            autocommit=True, connect_timeout=3, read_timeout=4, write_timeout=4,
        )

    def fixture_mysql(self):
        return self.mysql("dresser", self.mysql_password, DATABASE)

    def configure_databases(self):
        with self.admin.cursor() as cursor:
            cursor.execute("SELECT @@server_uuid, VERSION(), @@skip_networking, @@global.event_scheduler, @@log_bin")
            self.native_uuid, version, socket_only, events, binlog = cursor.fetchone()
            require((version, socket_only, events, binlog) == ("8.4.13", 1, "OFF", 1), "Unexpected fixture MySQL")
            cursor.execute("SHOW DATABASES")
            require(set(cursor.fetchall()) == {("information_schema",), ("mysql",), ("performance_schema",), ("sys",)},
                    "The MySQL fixture is not pristine")
            cursor.execute(f"CREATE DATABASE {DATABASE}")
            cursor.execute(f"USE {DATABASE}")
            cursor.execute("CREATE USER 'dresser'@'localhost' IDENTIFIED BY %s", (self.mysql_password,))
            cursor.execute(f"GRANT SELECT, INSERT, UPDATE, DELETE ON {DATABASE}.* TO 'dresser'@'localhost'")
            for definition in DEFINITIONS:
                cursor.execute("CREATE TABLE " + definition.format(sequence="BIGINT PRIMARY KEY AUTO_INCREMENT"))
            cursor.execute("SET GLOBAL super_read_only=ON")

        with closing(sqlite_connect()) as connection, connection:
            connection.execute("PRAGMA foreign_keys=ON")
            for definition in DEFINITIONS:
                connection.execute("CREATE TABLE " + definition.format(sequence="INTEGER PRIMARY KEY AUTOINCREMENT"))
            connection.execute("INSERT INTO users (id) VALUES (41)")

    def rows_mysql(self):
        with closing(self.fixture_mysql()) as connection:
            return mysql_rows(connection)

    def environment(self, engine):
        return {
            "PATH": "/usr/local/bin:/usr/bin:/bin", "APP_ENV": "testing", "APP_KEY": self.app_key,
            "APP_URL": "http://127.0.0.1:8764", "APP_CONFIG_CACHE": str(CONFIG),
            "APP_STORAGE_PATH": str(STORAGE), "CACHE_STORE": "array", "SESSION_DRIVER": "array",
            "LOG_CHANNEL": "stderr", "MAIL_MAILER": "array", "QUEUE_CONNECTION": "database",
            "DB_CONNECTION": engine, "DB_DATABASE": str(SOURCE) if engine == "sqlite" else DATABASE,
            "DB_HOST": "localhost", "DB_SOCKET": SOCKET, "DB_USERNAME": "dresser",
            "DB_PASSWORD": self.mysql_password, "DB_FOREIGN_KEYS": "true", "FILESYSTEM_DISK": "local",
            "REPRO_DRESS_ENGINE": engine,
        }

    def php(self, engine, *args):
        result = subprocess.run(
            ("php", *args), cwd="/workspace", env=self.environment(engine),
            text=True, capture_output=True, timeout=20,
        )
        require(result.returncode == 0, f"Laravel operation failed ({engine}, {args[0]})")
        return result.stdout

    def build_cache(self, engine):
        self.php(engine, "artisan", "config:cache", "--no-interaction")
        require(CONFIG.is_file(), "Laravel did not create the expected cache")

    def close(self):
        if self.server is not None:
            self.server.terminate()
            try:
                self.server.wait(4)
            except subprocess.TimeoutExpired:
                self.server.kill()
                self.server.wait(3)
            self.server = None
        if hasattr(self, "admin"):
            self.admin.close()

    def open_server(self, engine):
        if self.server is not None:
            self.server.terminate()
            self.server.wait(4)
        self.server = subprocess.Popen(
            ("php", "-S", "127.0.0.1:8764", "/host/probe.php"), cwd="/workspace",
            env=self.environment(engine), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        for _ in range(60):
            require(self.server.poll() is None, "The owned HTTP fixture exited")
            try:
                return self.http()
            except ConnectionRefusedError:
                time.sleep(0.05)
        raise UnsafeCutover("The owned HTTP fixture never became ready")

    def http(self, method="GET", code=200):
        with closing(http.client.HTTPConnection("127.0.0.1", 8764, timeout=7)) as client:
            client.request(method, "/fixture/notification-state")
            response = client.getresponse()
            result = json.load(response)
            require(response.status == code, f"Unexpected synthetic HTTP result {response.status}: {result}")
            return result

    def queue(self, operation):
        return json.loads(self.php("sqlite", "/host/probe.php", operation))

    def identity(self):
        return self._identity

    def boot_id(self):
        return self._boot_id

    def preflight_sha256(self):
        return digest(asdict(self.identity()))

    def gate(self):
        return json.loads((self.journal.directory / "gate.json").read_text())

    def set_gate(self, engine, opened):
        self.journal.write_record("gate.json", {"engine": engine, "open": opened})

    def observe(self):
        gate = self.gate()
        with self.admin.cursor() as cursor:
            cursor.execute("SELECT @@global.super_read_only, @@server_uuid")
            read_only, server_uuid = cursor.fetchone()
        require(server_uuid == self.native_uuid, "Private MySQL target changed")
        mysql_changed = self.rows_mysql() != (self.mysql_initial if self.imported is None else self.imported)
        return Observation(
            gate["engine"], gate["open"], not gate["open"], bool(SOURCE.stat().st_mode & stat.S_IWUSR),
            not read_only, mysql_changed, not gate["open"],
        )

    def require_quiescent(self):
        # Requests and one-shot workers are synchronous. No direct or continuous
        # writers are started outside this controller in this fixture.
        require(self.server is not None and self.server.poll() is None, "The owned HTTP process is unavailable")

    def hold_and_drain_scheduler(self, lease, deadline):
        lease.require_current()
        require(time.time() < deadline, "Scheduler-model deadline expired")
        file = self.journal.directory / "no-scheduler-fixture.json"
        if not file.exists():
            marker = SchedulerDrain(digest(asdict(self.identity())), self.boot_id(), str(uuid.uuid4()), True, 0)
            self.journal.write_record(file.name, asdict(marker))
        return self.scheduler_drain()

    def scheduler_drain(self):
        # The fixture launches no scheduler. This marker fixes its empty
        # inventory for the Runner; it is not protected systemd evidence.
        return SchedulerDrain(**json.loads((self.journal.directory / "no-scheduler-fixture.json").read_text()))

    def freeze(self, lease, deadline):
        lease.require_current()
        self.set_gate("sqlite", False)
        self.closed_at = time.monotonic()
        require(self.http("POST", 503) == {"admission_held": True}, "SQLite HTTP write escaped the fixture hold")
        require(self.queue("work") == {"admission_held": True}, "Fixture queue worker escaped the hold")
        SOURCE.chmod(0o400)
        with closing(sqlite_connect()) as source:
            try:
                source.execute("INSERT INTO fixture_effects VALUES ('must-not-commit')")
            except sqlite3.OperationalError as error:
                require(error.sqlite_errorcode == sqlite3.SQLITE_READONLY, "Unexpected SQLite freeze error")
            else:
                raise UnsafeCutover("SQLite accepted a write under the fixture hold")
            with closing(sqlite_connect(SNAPSHOT)) as snapshot:
                source.backup(snapshot)
                require(snapshot.execute("PRAGMA quick_check").fetchone() == ("ok",), "Snapshot integrity failed")
        SNAPSHOT.chmod(0o400)
        require(time.time() < deadline, "Fixture freeze deadline expired")

    def prepare(self, lease, deadline):
        lease.require_current()
        source = sqlite_rows(SNAPSHOT)
        try:
            with self.admin.cursor() as cursor:
                cursor.execute("SET GLOBAL super_read_only=OFF")
                cursor.execute("SET GLOBAL read_only=OFF")
                for name, _ in TABLES:
                    if source[name]:
                        placeholders = ",".join(["%s"] * len(source[name][0]))
                        cursor.executemany(f"INSERT INTO {name} VALUES ({placeholders})", source[name])
        finally:
            with self.admin.cursor() as cursor:
                cursor.execute("SET GLOBAL super_read_only=ON")
        self.imported = source
        require(self.rows_mysql() == source, "Fixture cross-engine rows differ")
        with self.admin.cursor() as cursor:
            cursor.execute("SHOW BINARY LOG STATUS")
            self.binlog_file, self.binlog_position, *_ = cursor.fetchone()
        with closing(self.fixture_mysql()) as connection, connection.cursor() as cursor:
            try:
                cursor.execute("INSERT INTO fixture_effects VALUES ('must-not-commit')")
            except self.pymysql.MySQLError as error:
                require(error.args[0] == 1290, "Unexpected MySQL read-only gate error")
            else:
                raise UnsafeCutover("MySQL accepted a write under the fixture hold")

        self.set_gate("mysql", False)
        self.build_cache("mysql")
        prepared = self.open_server("mysql")
        require(prepared == {**self.baseline, "engine": "mysql"}, "Laravel read-only target differs")
        require(self.http("POST", 503) == {"admission_held": True}, "MySQL HTTP write escaped the fixture hold")
        self.prepared_at = time.monotonic()
        require(time.time() < deadline, "Fixture preparation deadline expired")

    def evidence(self):
        require(self.rows_mysql() == self.imported == sqlite_rows(SNAPSHOT), "Prepared fixture evidence changed")
        return Evidence(
            file_digest(SNAPSHOT), digest(self.imported), self.binlog_file, self.binlog_position,
            True, True, True, False, False,
        )

    def seal_sqlite(self, lease, deadline):
        lease.require_current()
        require(sqlite_rows(SOURCE) == sqlite_rows(SNAPSHOT), "Frozen SQLite data changed")
        SOURCE.chmod(0o600)
        replacement = CONFIG.with_suffix(".candidate")
        with replacement.open("xb") as stream:
            stream.write(self.sqlite_config)
            stream.flush()
            os.fsync(stream.fileno())
        replacement.replace(CONFIG)
        self.set_gate("sqlite", False)
        require(self.open_server("sqlite") == self.baseline, "Restored cached SQLite Laravel read differs")
        require(time.time() < deadline, "Fixture rollback deadline expired")

    def seal_mysql(self, *_):
        raise UnsafeCutover("This fixture cannot make MySQL authoritative")

    def policy(self):
        return self.journal.encode({
            "version": 1, "machine_id_sha256": file_digest(Path("/etc/machine-id")),
            "identity": asdict(self.identity()), "sqlite_live_config_sha256": guard.sha256(self.sqlite_config),
            "mysql_live_config_sha256": digest({"engine": "mysql", "fixture": True}),
        })

    def publish_authority(self, lease, engine, candidate, deadline):
        lease.require_current()
        require(engine == "sqlite" and self.observe().sealed(engine) and time.time() < deadline,
                "SQLite fixture authority is not sealed")
        self.journal.write_record("proof.json", {
            "version": 1, "boot_id": self.boot_id(), "policy_sha256": guard.sha256(self.policy()),
            "journal_sha256": guard.sha256(candidate), "engine": engine,
            "database_identity": self.identity().source_sha256,
            "live_config_sha256": file_digest(CONFIG), "release_sha": self.identity().release_sha,
            "artifact_sha256": self.identity().artifact_sha256, "competing_writer_detected": False,
        })

    def verify_guard(self, engine, evidence=None):
        proof = (self.journal.directory / "proof.json").read_bytes() if evidence is None else evidence
        guard.verify(
            self.policy(), self.journal.path.read_bytes(), proof,
            machine_sha=file_digest(Path("/etc/machine-id")), boot_id=self.boot_id(),
            action="web-writer", expected_engine=engine,
        )

    def reopen(self):
        with self.journal.acquire():
            self.verify_guard("sqlite")
            stale_proof = json.loads((self.journal.directory / "proof.json").read_bytes())
            stale_proof["boot_id"] = str(uuid.uuid4())
            for engine, proof in (("mysql", None), ("sqlite", self.journal.encode(stale_proof))):
                try:
                    self.verify_guard(engine, proof)
                except guard.Refused:
                    continue
                raise UnsafeCutover("Guard allowed the wrong engine or a stale boot proof")
            require(self.observe().sealed("sqlite"), "Reopening requires sealed SQLite")
            self.set_gate("sqlite", True)
            require(self.observe().sqlite_live() and self.http() == self.baseline, "SQLite failed to reopen")


def exercise(adapter):
    runner = Runner(adapter.journal, adapter.identity(), adapter)
    initial = runner.initialize()
    require(initial["phase"] == "planned", "Runner did not start planned")
    require(set(adapter.http("POST")["receipts"]) == {"synthetic-http-receipt"}, "Synthetic write not acknowledged")
    require(adapter.queue("enqueue") == {"queued": 1}, "Synthetic queue payload was not stored")
    adapter.baseline = adapter.http()
    require(adapter.baseline["pending"] == 1, "Synthetic queue payload was not read")
    runner.perform("freeze", runner.approval_request("freeze"))
    try:
        runner.perform("prepare")
    except UnsafeCutover as error:
        require(str(error) == "Required parity, smoke or off-site backup gate failed",
                f"Unexpected preparation failure: {error}")
        require(adapter.prepared_at > adapter.closed_at, "Target never finished read-only smoke")
    else:
        raise UnsafeCutover("Local backups incorrectly authorized readiness")

    before_promotion = runner.status()
    require(before_promotion["pending"]["action"] == "prepare" and not before_promotion["writes_possible"],
            "Failed off-site gate marked MySQL writable")
    try:
        runner.perform("promote")
    except UnsafeCutover:
        require(runner.status() == before_promotion, "Refused promotion changed the journal")
    else:
        raise UnsafeCutover("Promotion proceeded with unresolved local backup evidence")

    before_abort = time.monotonic()
    runner.perform("abort")
    adapter.reopen()
    after_reopen = time.monotonic()
    first = adapter.queue("work")
    require(first == {"worker_exit": 0, "effects": ["synthetic-queue-effect"]}, "Real Laravel queue work failed")
    require(adapter.queue("work") == first and adapter.http()["pending"] == 0, "Queue duplicated or lost the effect")
    require(adapter.rows_mysql() == adapter.imported, "MySQL changed after the pre-write rollback")
    return {
        "result": "prewrite-rehearsal-passed", "release": adapter.identity().release_sha,
        "freeze_through_target_smoke_ms": round((adapter.prepared_at - adapter.closed_at) * 1000),
        "abort_to_sqlite_http_ms": round((after_reopen - before_abort) * 1000),
        "acknowledged_http_receipts": 1, "queue_effects": 1, "mysql_business_writes": 0,
        "offsite_gate_refused": True, "promotion_refused": True, "stale_boot_refused": True,
        "authority": runner.status()["phase"], "harness_sha256": file_digest(Path(__file__)),
        "php_probe_sha256": file_digest(Path("/host/probe.php")),
        "scope": "synthetic fixture HTTP and one-shot queue; PHP CLI server, SQLite, socket-only MySQL",
        "unproven": ["offsite backups", "production admission and ingress", "systemd scheduler", "full data and workloads"],
    }


def main():
    wheel = Path("/offline-pymysql.whl")
    require(file_digest(wheel) == "e6b1d89711dd51f8f74b1631fe08f039e7d76cf67a42a323d3178f0f25762ed9",
            "The pinned offline wheel changed")
    sys.path.insert(0, str(wheel))
    import pymysql

    adapter = ContainerRehearsal(pymysql, *sys.argv[1:])
    try:
        print(json.dumps(exercise(adapter), sort_keys=True))
    finally:
        adapter.close()


if __name__ == "__main__":
    main()
