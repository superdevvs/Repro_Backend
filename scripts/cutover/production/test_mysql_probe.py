"""Tests for the MySQL probe (design 1.2, 2.2, 4.3): the gate state, the accounts it reads, and business
activity from the receipt (the binary log scan of binlog_scan.py, the foreign split, the latches, --audit).

FakeServerTests need Linux root only: a root-owned stand-in for the mysql client answers from the
recorded 8.4.13 event text in test_binlog_scan.py, so the receipt, paging and exit 5 logic also runs
where no MySQL exists (the CI checks job as root).

The fixture tests need a disposable MySQL 8.4.13 built by `mysql/fixture.sh serve` from
`mysql/accounts.sql` itself, its directory mounted here and named by
REPRO_MYSQL_PROBE_FIXTURE. Two variant fixtures cover what one server cannot show:
REPRO_MYSQL_PROBE_FIXTURE_NETWORKING (`skip_networking` off) and
REPRO_MYSQL_PROBE_FIXTURE_SKIP_GRANTS (`--skip-grant-tables`). They run as root, like the
controller.

`run-probe-fixture-tests.sh` (next to this file) does all of it: it builds the three fixtures in
disposable containers, runs this file with REPRO_REQUIRE_PROBE_FIXTURE=1, so a fixture test that
would skip fails the run instead, and removes them again. Without the fixtures
`run-linux-tests.sh` prints a WARNING line naming the skipped tests (`--fixture-check` below).
The same by hand (Git Bash, MSYS_NO_PATHCONV=1), all containers --network none:

    docker volume create adp-fixture
    docker run -d --name adp-mysql --network none --cpus 2 --memory 2g \
      --mount type=volume,source=adp-fixture,target=/fixture \
      --mount type=bind,source=<checkout>/scripts/cutover/production/mysql,target=/src,readonly \
      --tmpfs /var/lib/mysql:rw,exec,size=1500m --tmpfs /tmp:rw,size=256m,mode=1777 \
      --entrypoint /bin/bash mysql@sha256:a81d0439... /src/fixture.sh serve /fixture
    # (same with `serve /fixture networking` and `serve /fixture skip-grant-tables` for the variants)
    docker run --rm --network none --cpus 2 --memory 2g \
      --mount type=bind,source=<checkout>,target=/src,readonly \
      --mount type=volume,source=adp-fixture,target=/fixture \
      -e REPRO_MYSQL_PROBE_FIXTURE=/fixture --entrypoint /bin/sh repro-mysql-rehearsal-qa:20261122 \
      -c 'cd /src && python3 -B -m unittest discover -s scripts/cutover/production -p test_mysql_probe.py -v'

The static tests (accounts.sql, the renderer) run anywhere with bash.
"""

import dataclasses
import hashlib
import itertools
import json
import os
import re
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import threading
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE.parent), str(HERE)]
import test_binlog_scan as recorded  # noqa: E402

ACCOUNTS_SQL = HERE / "mysql" / "accounts.sql"
FIXTURE_SH = HERE / "mysql" / "fixture.sh"
PROBE = HERE / "mysql_probe.py"
BASH = shutil.which("bash")
CLIENT = "/usr/local/bin/mysql"
FIXTURE = os.environ.get("REPRO_MYSQL_PROBE_FIXTURE")
FIXTURE_NETWORKING = os.environ.get("REPRO_MYSQL_PROBE_FIXTURE_NETWORKING")
FIXTURE_SKIP_GRANTS = os.environ.get("REPRO_MYSQL_PROBE_FIXTURE_SKIP_GRANTS")
FIXTURE_VARIABLES = ("REPRO_MYSQL_PROBE_FIXTURE", "REPRO_MYSQL_PROBE_FIXTURE_NETWORKING",
                     "REPRO_MYSQL_PROBE_FIXTURE_SKIP_GRANTS")
# Set by run-probe-fixture-tests.sh: a fixture test that would skip fails the run instead.
REQUIRE_FIXTURE = os.environ.get("REPRO_REQUIRE_PROBE_FIXTURE") == "1"
RUN_LINUX_TESTS = HERE.parent / "run-linux-tests.sh"
LINUX_ROOT = sys.platform == "linux" and hasattr(os, "geteuid") and os.geteuid() == 0
OTHER_UUID = "0b9f5f1e-0000-4000-8000-000000000001"
# The records the probe reads under the journal directory (design 1.2, 3.6): receipt, latch, activation,
# smoke window.
RECORDS = ("receipt", "activity", "activated", "smoke_window")
STRICT_JOURNALS = (None, "planned", "sqlite_authoritative")
MEASURED_JOURNALS = ("holding", "frozen", "ready", "writes_possible", "mysql_authoritative", "recovery_required")
# Design 2.3: what seal_mysql grants to open the gate, and the four privileges it adds.
OPEN_SQL = {
    "INSERT": "GRANT INSERT ON `repro\\_dashboard`.* TO 'repro_app'@'localhost'",
    "UPDATE": "GRANT UPDATE ON `repro\\_dashboard`.* TO 'repro_app'@'localhost'",
    "DELETE": "GRANT DELETE ON `repro\\_dashboard`.* TO 'repro_app'@'localhost'",
    "UPDATE(id)": "GRANT UPDATE (`id`) ON `repro_mysql_control`.`writer_token` TO 'repro_app'@'localhost'",
}
OPEN_GATE = ("GRANT INSERT, UPDATE, DELETE ON `repro\\_dashboard`.* TO 'repro_app'@'localhost';"
             "GRANT UPDATE (`id`) ON `repro_mysql_control`.`writer_token` TO 'repro_app'@'localhost'")
# The closed state exactly as accounts.sql leaves it, as root reads it.
CLOSED_GRANTS = [
    "GRANT USAGE ON *.* TO `repro_app`@`localhost`",
    "GRANT SELECT ON `repro\\_dashboard`.* TO `repro_app`@`localhost`",
    "GRANT SELECT ON `repro_mysql_control`.`writer_token` TO `repro_app`@`localhost`",
]
# The eight accounts of design 2.2 and the placeholder each password replaces.
ACCOUNTS = {
    "repro_app": "app", "repro_maint": "maint", "repro_migrator": "migrator", "repro_parity_reader": "reader",
    "repro_token_check": "check", "repro_cutover_probe": "probe", "repro_cutover_gate": "gate",
    "repro_monitor": "monitor",
}


class AccountsSqlTests(unittest.TestCase):
    """accounts.sql is the file the administrator runs; it never holds a password."""

    def setUp(self):
        self.sql = ACCOUNTS_SQL.read_text(encoding="utf-8")

    def test_accounts_sql_creates_the_eight_accounts_with_placeholders_only(self):
        created = re.findall(r"^CREATE USER '([a-z_]+)'@'localhost' IDENTIFIED BY :([a-z]+);", self.sql, re.MULTILINE)
        self.assertEqual(ACCOUNTS, dict(created))
        self.assertEqual(8, self.sql.count("CREATE USER"))
        # No literal secret anywhere: every IDENTIFIED BY takes a placeholder.
        self.assertEqual(8, len(re.findall(r"IDENTIFIED BY :[a-z]+;", self.sql)))
        self.assertNotRegex(self.sql, r"(?i)IDENTIFIED\s+(BY\s+['\"]|WITH)")
        self.assertNotRegex(self.sql, r"(?i)password\s*=")

    def test_schema_grants_escape_the_underscore(self):
        # An unescaped `_` in a schema-level grant is a wildcard that also matches `reproXdashboard`.
        statements = [line for line in self.sql.splitlines() if " ON `repro" in line and ".*" in line]
        self.assertTrue(statements)
        for line in statements:
            with self.subTest(line=line):
                self.assertIn("ON `repro\\_dashboard`.*", line)


@unittest.skipUnless(BASH and os.name == "posix", "The renderer is a bash script")
class RenderTests(unittest.TestCase):
    """`fixture.sh render` fills the placeholders from root-only files, the same way for the fixture and A1."""

    def setUp(self):
        self.directory = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.directory)
        os.chmod(self.directory, 0o700)
        for placeholder in ACCOUNTS.values():
            self.password(placeholder, f"pw{placeholder}0123456789abcdef")

    def password(self, placeholder, value, mode=0o600):
        path = self.directory / placeholder
        path.write_text(value + "\n", encoding="ascii")
        os.chmod(path, mode)

    def render(self):
        return subprocess.run([BASH, str(FIXTURE_SH), "render", str(ACCOUNTS_SQL), str(self.directory)],
                              capture_output=True, text=True, timeout=30)

    def test_render_fills_every_placeholder_and_changes_nothing_else(self):
        result = self.render()
        self.assertEqual(0, result.returncode, result.stderr)
        expected = ACCOUNTS_SQL.read_text(encoding="utf-8")
        for placeholder in ACCOUNTS.values():
            expected = expected.replace(f"IDENTIFIED BY :{placeholder};",
                                        f"IDENTIFIED BY 'pw{placeholder}0123456789abcdef';")
        self.assertEqual(expected, result.stdout)

    def test_render_refuses_a_missing_password(self):
        (self.directory / "probe").unlink()
        result = self.render()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual("", result.stdout)

    def test_render_refuses_a_password_that_needs_quoting(self):
        for value in ("it's-a-long-password", "back\\slash-password-0123", "short", "space in a password 01"):
            with self.subTest(value=value):
                self.password("gate", value)
                result = self.render()
                self.assertNotEqual(0, result.returncode)
                self.assertEqual("", result.stdout)

    @unittest.skipUnless(hasattr(os, "geteuid"), "POSIX owners")
    def test_render_refuses_a_password_file_others_can_read(self):
        self.password("app", "pwapp0123456789abcdef", mode=0o644)
        result = self.render()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual("", result.stdout)

    @unittest.skipUnless(LINUX_ROOT, "Needs root to give a file another owner")
    def test_render_refuses_a_password_file_owned_by_someone_else(self):
        os.chown(self.directory / "probe", 65534, 65534)
        result = self.render()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual("", result.stdout)


class ServerCheckTests(unittest.TestCase):
    """Exit 6 inputs that one fixture cannot show: the server version."""

    def test_version_other_than_8_4_is_a_wrong_server(self):
        import mysql_probe

        server = {"server_uuid": OTHER_UUID, "version": "8.4.13", "skip_networking": 1,
                  "current_user": "repro_cutover_probe@localhost"}
        self.assertEqual([], mysql_probe.server_problems(server, OTHER_UUID))
        for version in ("8.0.40", "9.1.0", "8.40.1", "8.4", "8.4.13-log", ""):
            with self.subTest(version=version):
                self.assertTrue(mysql_probe.server_problems({**server, "version": version}, OTHER_UUID))
        self.assertTrue(mysql_probe.server_problems({**server, "skip_networking": 0}, OTHER_UUID))
        self.assertTrue(mysql_probe.server_problems(server, "0b9f5f1e-0000-4000-8000-000000000002"))

    def test_only_8_4_13_is_the_target_server(self):
        # Review A13: the scan reads SHOW BINLOG EVENTS text as 8.4.13 prints it; another patch is exit 6.
        import mysql_probe

        server = {"server_uuid": OTHER_UUID, "version": "8.4.13", "skip_networking": 1,
                  "current_user": "repro_cutover_probe@localhost"}
        for version in ("8.4.12", "8.4.14", "8.4.0", "8.4.130", "8.4.1"):
            with self.subTest(version=version):
                problems = mysql_probe.server_problems({**server, "version": version}, OTHER_UUID)
                self.assertEqual(1, len(problems), problems)
                self.assertIn("8.4.13", problems[0])


class Fixture:
    """A running fixture built by `fixture.sh serve` from accounts.sql."""

    def __init__(self, directory: str):
        self.directory = Path(directory)
        if not (self.directory / "ready").is_file():
            raise AssertionError(f"MySQL fixture {directory} is not ready")
        self.socket = str(self.directory / "run" / "mysql.sock")
        self.uuid = (self.directory / "server_uuid").read_text(encoding="ascii").strip()

    def client(self, account: str, statements: str, socket_path: str | None = None):
        return subprocess.run(
            [CLIENT, f"--defaults-file={self.directory / f'{account}.cnf'}", "--no-login-paths",
             "--protocol=SOCKET", f"--socket={socket_path or self.socket}", "--batch", "--raw",
             "--skip-column-names", "-e", statements],
            capture_output=True, text=True, timeout=60)

    def sql(self, statements: str, account: str = "root") -> str:
        result = self.client(account, statements)
        if result.returncode != 0:
            raise AssertionError(f"{account}: {result.stderr.strip()}")
        return result.stdout

    def snapshot(self) -> dict:
        """The gated account's state as root reads it, to catch a test that leaks a change."""
        return {
            "grants": sorted(self.sql("SHOW GRANTS FOR 'repro_app'@'localhost'").splitlines()),
            "other": self.sql(
                "SELECT @@GLOBAL.mandatory_roles, (SELECT account_locked FROM mysql.user WHERE User='repro_app'),"
                " (SELECT COUNT(*) FROM mysql.role_edges), (SELECT COUNT(*) FROM mysql.default_roles),"
                " (SELECT COUNT(*) FROM mysql.procs_priv), (SELECT COUNT(*) FROM mysql.proxies_priv),"
                " (SELECT COUNT(*) FROM mysql.global_grants WHERE USER='repro_app'),"
                " (SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'repro\\_test%' OR User='repro_app_renamed'),"
                " (SELECT account_locked FROM mysql.user WHERE User='repro_cutover_probe')"),
        }

    def restore(self) -> None:
        """Put repro_app back in the closed state accounts.sql leaves, whatever a test changed."""
        password = (self.directory / "passwords" / "app").read_text(encoding="ascii").strip()
        for session in self.sql("SELECT ID FROM performance_schema.processlist WHERE USER = 'repro_app'").split():
            self.client("root", f"KILL {int(session)}")
        # Recreated as accounts.sql creates it; orphaned rows (a deleted user row) are removed directly.
        self.sql(f"""
            ALTER USER 'repro_cutover_probe'@'localhost' ACCOUNT UNLOCK;
            SET GLOBAL mandatory_roles = '';
            DROP ROLE IF EXISTS 'repro_test_role';
            DROP USER IF EXISTS 'repro_test_other'@'localhost', 'repro_app_renamed'@'localhost',
                'repro_app'@'localhost';
            DROP PROCEDURE IF EXISTS repro_dashboard.repro_test_write;
            DROP TABLE IF EXISTS repro_dashboard.repro_test_table;
            DROP DATABASE IF EXISTS repro_test_other;
            DELETE FROM mysql.db WHERE User = 'repro_app';
            DELETE FROM mysql.tables_priv WHERE User = 'repro_app';
            DELETE FROM mysql.columns_priv WHERE User = 'repro_app';
            DELETE FROM mysql.procs_priv WHERE User = 'repro_app';
            DELETE FROM mysql.global_grants WHERE USER = 'repro_app';
            DELETE FROM mysql.proxies_priv WHERE User = 'repro_app' OR Proxied_user = 'repro_app';
            DELETE FROM mysql.default_roles WHERE USER = 'repro_app' OR DEFAULT_ROLE_USER = 'repro_app';
            DELETE FROM mysql.role_edges WHERE FROM_USER = 'repro_app' OR TO_USER = 'repro_app';
            FLUSH PRIVILEGES;
            CREATE USER 'repro_app'@'localhost' IDENTIFIED BY '{password}';
            GRANT SELECT ON `repro\\_dashboard`.* TO 'repro_app'@'localhost';
            GRANT SELECT ON `repro_mysql_control`.`writer_token` TO 'repro_app'@'localhost';
        """)


class ProbeHost:
    """A root-owned host.json, identity.json, probe defaults file and journal directory for probe runs."""

    def __init__(self, fixture: Fixture):
        from test_adapter import IDENTITY, example_config, example_identity

        self.root = Path(tempfile.mkdtemp(prefix="repro-probe-test-", dir="/var/lib"))
        self.etc = self.root / "etc"
        self.state = self.root / "state"
        self.etc.mkdir(mode=0o755)
        self.state.mkdir(mode=0o700)
        # The runner's journal names its run (state["identity"]); a receipt is valid only for that run.
        self.run_identity = dataclasses.asdict(IDENTITY)
        config = example_config()
        paths = config["paths"]
        paths["state"] = str(self.state)
        paths["identity_config"] = str(self.etc / "identity.json")
        for name in RECORDS:
            paths[name] = str(self.state / Path(paths[name]).name)
        mysql = config["mysql"]
        mysql["client"] = CLIENT
        mysql["probe_defaults"] = str(self.etc / "mysql-probe.cnf")
        config["commands"]["identity"][-1] = paths["identity_config"]
        config["commands"]["mysql"] = ["/usr/bin/python3", "-I", "-S", str(PROBE), "--config",
                                       str(self.etc / "host.json")]
        self.config = config
        self.identity = example_identity(config)
        self.target(fixture)
        self.defaults(fixture.directory / "repro_cutover_probe.cnf")

    def target(self, fixture: Fixture, uuid: str | None = None) -> None:
        uuid = uuid or fixture.uuid
        mysql = self.config["mysql"]
        mysql["socket"] = mysql["switch"]["DB_SOCKET"] = fixture.socket
        mysql["target_uuid"] = mysql["switch"]["REPRO_MYSQL_EXPECTED_SERVER_UUID"] = uuid

    def defaults(self, source: Path, mode: int = 0o600) -> None:
        target = Path(self.config["mysql"]["probe_defaults"])
        shutil.copyfile(source, target)
        os.chmod(target, mode)

    def journal(self, phase: str | None) -> None:
        path = self.state / "state.json"
        path.unlink(missing_ok=True)
        if phase is not None:
            path.write_text(json.dumps({"version": 1, "phase": phase, "generation": 1, "pending": None,
                                        "identity": self.run_identity}), encoding="utf-8")
            os.chmod(path, 0o600)

    def path(self, name: str) -> Path:
        return Path(self.config["paths"][name])

    def write(self, name: str, record: dict, mode: int = 0o600) -> bytes:
        """A root-owned record at paths.<name>, as the controller writes it; returns its bytes."""
        raw = (json.dumps(record, sort_keys=True) + "\n").encode("utf-8")
        path = self.path(name)
        path.unlink(missing_ok=True)
        path.write_bytes(raw)
        os.chmod(path, mode)
        return raw

    def run(self, phase: str | None = None, *, as_user: int | None = None, arguments: tuple = ()):
        """Run the probe as the controller does; return (exit code, JSON stdout or None, stderr)."""
        for name, document in (("host.json", self.config), ("identity.json", self.identity)):
            (self.etc / name).write_text(json.dumps(document), encoding="utf-8")
            os.chmod(self.etc / name, 0o644)
        self.journal(phase)
        result = subprocess.run([*self.config["commands"]["mysql"], *arguments], capture_output=True, text=True,
                                timeout=300, env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8"},
                                user=as_user)
        try:
            output = json.loads(result.stdout) if result.stdout.strip() else None
        except ValueError:
            output = result.stdout
        return result.returncode, output, result.stderr


def closed_output(fixture: Fixture, mode: str, sessions: int = 0) -> dict:
    return {"version": 2, "reachable": True, "server_uuid": fixture.uuid, "database": "repro_dashboard",
            "gate": "closed", "writable": False, "business_activity": False, "foreign_activity": False,
            "boundary": None, "receipt": None, "gated_sessions": sessions, "scanned_events": 0, "mode": mode}


def journal_mode(phase: str | None) -> str:
    return "strict" if phase in STRICT_JOURNALS else "measured"


@unittest.skipUnless(LINUX_ROOT and FIXTURE, "Needs Linux root and the MySQL fixture (REPRO_MYSQL_PROBE_FIXTURE)")
class GateStateTests(unittest.TestCase):
    """The probe against a fixture built from accounts.sql (design 1.2 gate state)."""

    @classmethod
    def setUpClass(cls):
        cls.fixture = Fixture(FIXTURE)
        cls.fixture.restore()
        cls.baseline = cls.fixture.snapshot()
        assert cls.baseline["grants"] == sorted(CLOSED_GRANTS), cls.baseline

    def setUp(self):
        self.host = ProbeHost(self.fixture)
        self.addCleanup(shutil.rmtree, self.host.root)
        self.addCleanup(self.assert_restored)

    def assert_restored(self):
        self.fixture.restore()
        self.assertEqual(self.baseline, self.fixture.snapshot())

    def assert_refused(self, phase, expected_exit, gate=None):
        code, output, stderr = self.host.run(phase)
        self.assertEqual(expected_exit, code, (output, stderr))
        # A refused reading never carries a writable or activity value someone could act on.
        if isinstance(output, dict):
            self.assertNotIn("writable", output)
            self.assertNotIn("business_activity", output)
            if gate is not None:
                self.assertEqual(gate, output.get("gate"), output)
        return output, stderr

    def test_fixture_is_built_from_this_accounts_sql(self):
        self.assertEqual(hashlib.sha256(ACCOUNTS_SQL.read_bytes()).hexdigest(),
                         (self.fixture.directory / "accounts.sha256").read_text(encoding="ascii").strip())

    def test_closed_state(self):
        for phase in STRICT_JOURNALS + MEASURED_JOURNALS:
            with self.subTest(journal=phase):
                code, output, stderr = self.host.run(phase)
                self.assertEqual(0, code, stderr)
                self.assertEqual(closed_output(self.fixture, journal_mode(phase)), output)

    def test_open_state(self):
        self.fixture.sql(OPEN_GATE, account="repro_cutover_gate")
        for phase in MEASURED_JOURNALS:
            with self.subTest(journal=phase):
                code, output, stderr = self.host.run(phase)
                self.assertEqual(0, code, stderr)
                self.assertEqual({**closed_output(self.fixture, "measured"), "gate": "open", "writable": True},
                                 output)

    def test_gated_sessions_are_counted(self):
        sleeper = subprocess.Popen(
            [CLIENT, f"--defaults-file={self.fixture.directory / 'repro_app.cnf'}", "--no-login-paths",
             "--protocol=SOCKET", f"--socket={self.fixture.socket}", "-e", "DO SLEEP(30)"])
        self.addCleanup(sleeper.wait)
        self.addCleanup(sleeper.kill)
        for _ in range(100):
            if self.fixture.sql("SELECT COUNT(*) FROM performance_schema.processlist WHERE USER='repro_app'").strip() == "1":
                break
            threading.Event().wait(0.1)
        code, output, stderr = self.host.run("ready")
        self.assertEqual(0, code, stderr)
        self.assertEqual(closed_output(self.fixture, "measured", sessions=1), output)

    def test_partial_state_exit_4(self):
        privileges = list(OPEN_SQL)
        for size in range(1, len(privileges)):
            for subset in itertools.combinations(privileges, size):
                with self.subTest(present=subset):
                    try:
                        self.fixture.sql(";".join(OPEN_SQL[name] for name in subset), account="repro_cutover_gate")
                        for phase in ("ready", "planned"):
                            output, _ = self.assert_refused(phase, 4, gate="partial")
                            self.assertEqual({"repro_app@localhost": sorted(subset)}, output["open_privileges"])
                    finally:
                        self.fixture.restore()

    # Each state a person must fix (design 1.2, 4.7): exit 4 in every journal phase, never a gate reading.
    UNRECOGNISED = {
        "role granted to the app": (
            "CREATE ROLE 'repro_test_role'; GRANT INSERT ON `repro\\_dashboard`.* TO 'repro_test_role';"
            "GRANT 'repro_test_role' TO 'repro_app'@'localhost'"),
        "app granted as a role": (
            "CREATE USER 'repro_test_other'@'localhost'; GRANT 'repro_app'@'localhost' TO 'repro_test_other'@'localhost'"),
        "default role": (
            "CREATE ROLE 'repro_test_role';"
            "INSERT INTO mysql.default_roles VALUES ('localhost', 'repro_app', '%', 'repro_test_role');"
            "FLUSH PRIVILEGES"),
        "mandatory role": (
            "CREATE ROLE 'repro_test_role'; GRANT INSERT ON `repro\\_dashboard`.* TO 'repro_test_role';"
            "SET GLOBAL mandatory_roles = 'repro_test_role'"),
        "definer routine": (
            "CREATE TABLE repro_dashboard.repro_test_table (id INT PRIMARY KEY);"
            "CREATE PROCEDURE repro_dashboard.repro_test_write() SQL SECURITY DEFINER"
            " INSERT INTO repro_dashboard.repro_test_table VALUES (1);"
            "GRANT EXECUTE ON PROCEDURE repro_dashboard.repro_test_write TO 'repro_app'@'localhost'"),
        "proxy grant to the app": "GRANT PROXY ON 'repro_maint'@'localhost' TO 'repro_app'@'localhost'",
        "proxy grant of the app": (
            "CREATE USER 'repro_test_other'@'localhost';"
            "GRANT PROXY ON 'repro_app'@'localhost' TO 'repro_test_other'@'localhost'"),
        "global static grant": "GRANT SELECT ON *.* TO 'repro_app'@'localhost'",
        "global dynamic grant": "GRANT SESSION_VARIABLES_ADMIN ON *.* TO 'repro_app'@'localhost'",
        "account lock": "ALTER USER 'repro_app'@'localhost' ACCOUNT LOCK",
        "another schema": (
            "CREATE DATABASE repro_test_other; GRANT SELECT ON `repro_test_other`.* TO 'repro_app'@'localhost'"),
        "unescaped schema wildcard": "GRANT INSERT ON `repro_dashboard`.* TO 'repro_app'@'localhost'",
        "another app schema privilege": "GRANT CREATE ON `repro\\_dashboard`.* TO 'repro_app'@'localhost'",
        "app schema SELECT missing": "REVOKE SELECT ON `repro\\_dashboard`.* FROM 'repro_app'@'localhost'",
        "token SELECT missing": (
            "REVOKE SELECT ON `repro_mysql_control`.`writer_token` FROM 'repro_app'@'localhost'"),
        "another table grant": (
            "CREATE TABLE repro_dashboard.repro_test_table (id INT PRIMARY KEY);"
            "GRANT SELECT ON repro_dashboard.repro_test_table TO 'repro_app'@'localhost'"),
        # Sorts after the token row in mysql.tables_priv, so only the row count can catch it.
        "another table grant after the token": (
            "CREATE DATABASE repro_test_other; CREATE TABLE repro_test_other.repro_test_table (id INT PRIMARY KEY);"
            "GRANT SELECT ON repro_test_other.repro_test_table TO 'repro_app'@'localhost'"),
        "token table UPDATE": "GRANT UPDATE ON `repro_mysql_control`.`writer_token` TO 'repro_app'@'localhost'",
        "token column SELECT": (
            "GRANT SELECT (`id`) ON `repro_mysql_control`.`writer_token` TO 'repro_app'@'localhost'"),
        "token column grant without its column row": (
            "UPDATE mysql.tables_priv SET Column_priv = 'Update' WHERE User = 'repro_app'; FLUSH PRIVILEGES"),
        # The reverse: tables_priv.Column_priv stays '' while columns_priv holds the token's UPDATE (id) row.
        "token column row without its table column grant": (
            "INSERT INTO mysql.columns_priv (Host, Db, User, Table_name, Column_name, Column_priv)"
            " VALUES ('localhost', 'repro_mysql_control', 'repro_app', 'writer_token', 'id', 'Update');"
            "FLUSH PRIVILEGES"),
        "app account missing": "RENAME USER 'repro_app'@'localhost' TO 'repro_app_renamed'@'localhost'",
        "app user row missing, its grants left": (
            "DELETE FROM mysql.user WHERE User = 'repro_app' AND Host = 'localhost'; FLUSH PRIVILEGES"),
        "app schema SELECT replaced by INSERT": (
            "REVOKE SELECT ON `repro\\_dashboard`.* FROM 'repro_app'@'localhost';"
            "GRANT INSERT ON `repro\\_dashboard`.* TO 'repro_app'@'localhost'"),
        "token grant moved to another table": (
            "CREATE TABLE repro_dashboard.repro_test_table (id INT PRIMARY KEY);"
            "REVOKE SELECT ON `repro_mysql_control`.`writer_token` FROM 'repro_app'@'localhost';"
            "GRANT SELECT ON repro_dashboard.repro_test_table TO 'repro_app'@'localhost'"),
    }

    def test_role_default_role_mandatory_role_routine_proxy_global_lock_exit_4(self):
        for name, statements in self.UNRECOGNISED.items():
            with self.subTest(state=name):
                try:
                    self.fixture.sql(statements)
                    for phase in ("ready", "writes_possible", "planned"):
                        output, _ = self.assert_refused(phase, 4, gate="unrecognised")
                        self.assertTrue(output["problems"], output)
                finally:
                    self.fixture.restore()

    def test_unrecognised_state_wins_over_an_open_gate(self):
        # An open gate plus a role is not "open": the extra path is only visible as exit 4.
        self.fixture.sql(OPEN_GATE, account="repro_cutover_gate")
        self.fixture.sql("CREATE ROLE 'repro_test_role'; GRANT 'repro_test_role' TO 'repro_app'@'localhost'")
        self.assert_refused("writes_possible", 4, gate="unrecognised")

    def test_read_gate_hands_out_the_rows_it_classified(self):
        # For gate.py's root SQL (D06 open item): the probe account's own rows, as this server returned them.
        import mysql_probe

        self.fixture.sql(self.UNRECOGNISED["role granted to the app"])
        with self.assertRaises(mysql_probe.Refusal) as caught:
            mysql_probe.read_gate(self.host.config, "measured")
        reading = caught.exception.reading
        self.assertEqual(("unrecognised", mysql_probe.PROBE_ACCOUNT, self.fixture.uuid),
                         (reading.gate.name, reading.server["current_user"], reading.server["server_uuid"]))
        edge = {"user": "repro_app", "host": "localhost", "other_user": "repro_test_role", "other_host": "%"}
        self.assertEqual([edge], reading.state["role_edges"])
        self.fixture.restore()
        reading = mysql_probe.read_gate(self.host.config, "measured")
        self.assertEqual(("closed", None, ["repro_app"]), (reading.gate.name, reading.state["role_edges"],
                                                           [row["user"] for row in reading.state["user"]]))

    def test_strict_mode_never_reports_open_gate(self):
        self.fixture.sql(OPEN_GATE, account="repro_cutover_gate")
        for phase in STRICT_JOURNALS:
            with self.subTest(journal=phase):
                output, _ = self.assert_refused(phase, 4, gate="open")
                self.assertEqual("strict", output["mode"])
                self.assertTrue(output["problems"], output)
        code, output, stderr = self.host.run("writes_possible")
        self.assertEqual((0, True), (code, output["writable"]), stderr)

    def test_unknown_or_malformed_journal_refuses(self):
        for raw in ('{"version": 1, "phase": "opened"}', '{"version": 1}', '{"version": 2, "phase": "planned"}',
                    "not json"):
            with self.subTest(journal=raw):
                self.host.journal(None)
                (self.host.state / "state.json").write_text(raw, encoding="utf-8")
                os.chmod(self.host.state / "state.json", 0o600)
                code, output, _ = self.run_without_rewriting_journal()
                self.assertNotIn(code, (0, 3))
                self.assertFalse(isinstance(output, dict) and "writable" in output)

    def run_without_rewriting_journal(self):
        journal = self.host.journal
        self.host.journal = lambda phase: None
        try:
            return self.host.run()
        finally:
            self.host.journal = journal

    def test_another_runs_records_leave_the_gate_reading_unchanged(self):
        # Review A11: D04's interim probe exited 1 whenever a receipt, latch or activation record existed,
        # which would have stopped every runner action, the watchdog rollback included. Records of
        # another run are ignored: the reading is the plain gate state.
        other = "another-run"
        coordinate = {"file": "binlog.000001", "position": 4}
        self.host.write("receipt", {"version": 1, "run_id": other, "server_uuid": self.fixture.uuid,
                                    "database": "repro_dashboard", "armed_from": coordinate,
                                    "backup": {**coordinate, "gtid_executed": ""}})
        self.host.write("activity", {"version": 1, "run_id": other, "receipt_sha256": "0" * 64,
                                     "gtid": f"{self.fixture.uuid}:1", "file": "binlog.000001", "position": 4,
                                     "tables": ["shoots"], "statements": [], "boundary": None,
                                     "foreign_activity": False})
        self.host.write("activated", {"version": 1, "run_id": other, "server_uuid": self.fixture.uuid,
                                      "database": "repro_dashboard", "receipt_sha256": "0" * 64})
        for phase in STRICT_JOURNALS + MEASURED_JOURNALS:
            with self.subTest(journal=phase):
                code, output, stderr = self.host.run(phase)
                self.assertEqual(0, code, stderr)
                self.assertEqual(closed_output(self.fixture, journal_mode(phase)), output)

    def test_unreachable_exit_3(self):
        missing = str(self.host.root / "no-server" / "mysql.sock")
        stale = str(self.host.root / "stale.sock")
        listener = socket.socket(socket.AF_UNIX)
        listener.bind(stale)  # Bound but never listening: connections are refused.
        self.addCleanup(listener.close)
        for path in (missing, stale):
            with self.subTest(socket=path):
                mysql = self.host.config["mysql"]
                mysql["socket"] = mysql["switch"]["DB_SOCKET"] = path
                for phase in ("planned", "ready"):
                    code, output, stderr = self.host.run(phase)
                    self.assertEqual(3, code, stderr)
                    self.assertEqual({"version": 2, "reachable": False}, output)

    def without(self, missing):
        if missing == "defaults file":
            Path(self.host.config["mysql"]["probe_defaults"]).unlink()
        else:
            self.host.config["mysql"]["client"] = str(self.host.root / "no-bundle" / "bin" / "mysql")

    def test_server_not_installed_yet_is_unreachable(self):
        # Before session A1 there is no bundle, no probe account and no server: nothing can write.
        for missing in ("defaults file", "client"):
            with self.subTest(missing=missing):
                self.host.defaults(self.fixture.directory / "repro_cutover_probe.cnf")
                self.host.config["mysql"]["client"] = CLIENT
                self.without(missing)
                mysql = self.host.config["mysql"]
                mysql["socket"] = mysql["switch"]["DB_SOCKET"] = str(self.host.root / "no-server" / "mysql.sock")
                code, output, stderr = self.host.run("planned")
                self.assertEqual((3, {"version": 2, "reachable": False}), (code, output), stderr)

    def test_listening_server_without_a_defaults_file_or_client_is_not_unreachable(self):
        for missing in ("defaults file", "client"):
            with self.subTest(missing=missing):
                self.host.defaults(self.fixture.directory / "repro_cutover_probe.cnf")
                self.host.config["mysql"]["client"] = CLIENT
                self.without(missing)
                output, _ = self.assert_refused("planned", 4)
                self.assertTrue(output["problems"], output)

    def test_hung_server_is_not_unreachable(self):
        # A socket that accepts but never answers is a server that may still be writable.
        path = str(self.host.root / "hung.sock")
        listener = socket.socket(socket.AF_UNIX)
        listener.bind(path)
        listener.listen(8)
        self.addCleanup(listener.close)
        accepted = []
        threading.Thread(target=lambda: accepted.append(listener.accept()[0]), daemon=True).start()
        self.addCleanup(lambda: [connection.close() for connection in accepted])
        mysql = self.host.config["mysql"]
        mysql["socket"] = mysql["switch"]["DB_SOCKET"] = path
        self.assert_refused("planned", 4)

    def test_client_that_never_answers_is_not_unreachable(self):
        client = self.host.etc / "mysql"
        client.write_text("#!/bin/sh\nexec sleep 600\n", encoding="ascii")
        os.chmod(client, 0o755)
        self.host.config["mysql"]["client"] = str(client)
        self.assert_refused("planned", 4)

    def test_wrong_server_exit_6(self):
        self.host.target(self.fixture, uuid=OTHER_UUID)
        for phase in ("planned", "ready"):
            with self.subTest(journal=phase):
                output, _ = self.assert_refused(phase, 6)
                self.assertEqual(self.fixture.uuid, output["server_uuid"])

    @unittest.skipUnless(FIXTURE_NETWORKING, "Needs the networking fixture (REPRO_MYSQL_PROBE_FIXTURE_NETWORKING)")
    def test_networking_server_exit_6(self):
        networked = Fixture(FIXTURE_NETWORKING)
        self.host.target(networked)
        self.host.defaults(networked.directory / "repro_cutover_probe.cnf")
        output, _ = self.assert_refused("planned", 6)
        self.assertEqual(networked.uuid, output["server_uuid"])

    def assert_login_refused(self, error: str) -> None:
        # The server is there and the gate unread, so it may be writable. Exit 3 is the one reading
        # adapter._mysql turns into writable and business_activity false: a refused login is never that.
        for phase in ("planned", "ready", "writes_possible"):
            with self.subTest(journal=phase):
                output, _ = self.assert_refused(phase, 4)
                self.assertIsInstance(output, dict)
                self.assertEqual((True, None, None), (output["reachable"], output["gate"], output["server_uuid"]),
                                 output)
                self.assertRegex(output["problems"][0], error)

    def test_wrong_probe_password_exit_4(self):
        text = (self.fixture.directory / "repro_cutover_probe.cnf").read_text(encoding="ascii")
        wrong = self.host.root / "wrong-password.cnf"
        wrong.write_text(re.sub(r"(?m)^password=.*$", "password=not-the-probe-password-0123", text), encoding="ascii")
        self.host.defaults(wrong)
        self.assert_login_refused(r"ERROR 1045 \(28000\): Access denied for user 'repro_cutover_probe'@'localhost'")

    def test_locked_probe_account_exit_4(self):
        self.fixture.sql("ALTER USER 'repro_cutover_probe'@'localhost' ACCOUNT LOCK")
        try:
            self.assert_login_refused(
                r"ERROR 3118 \(HY000\): Access denied for user 'repro_cutover_probe'@'localhost'\. Account is locked\.")
        finally:
            self.fixture.sql("ALTER USER 'repro_cutover_probe'@'localhost' ACCOUNT UNLOCK")
        self.assertEqual(0, self.host.run("planned")[0])

    def test_current_user_mismatch_exit_4(self):
        # A defaults file with another account, here root, which could also read authentication_string.
        self.host.defaults(self.fixture.directory / "root.cnf")
        output, _ = self.assert_refused("planned", 4)
        self.assertTrue(any("CURRENT_USER" in problem for problem in output["problems"]), output)

    @unittest.skipUnless(FIXTURE_SKIP_GRANTS, "Needs the skip-grant-tables fixture (REPRO_MYSQL_PROBE_FIXTURE_SKIP_GRANTS)")
    def test_skip_grant_tables_server_exit_4(self):
        unguarded = Fixture(FIXTURE_SKIP_GRANTS)
        self.host.target(unguarded)
        self.host.defaults(unguarded.directory / "repro_cutover_probe.cnf")
        for phase in ("planned", "ready"):
            with self.subTest(journal=phase):
                output, _ = self.assert_refused(phase, 4)
                self.assertTrue(any("CURRENT_USER" in problem for problem in output["problems"]), output)

    def test_unreadable_state_exit_4(self):
        # The probe account without one of its grant-table reads.
        self.fixture.sql("REVOKE SELECT ON mysql.procs_priv FROM 'repro_cutover_probe'@'localhost'")
        try:
            output, _ = self.assert_refused("planned", 4)
            self.assertEqual(self.fixture.uuid, output["server_uuid"])
        finally:
            self.fixture.sql("GRANT SELECT ON mysql.procs_priv TO 'repro_cutover_probe'@'localhost'")
        # Without PROCESS it would see no app session at all, so it would always count none.
        self.fixture.sql("REVOKE PROCESS ON *.* FROM 'repro_cutover_probe'@'localhost'")
        try:
            output, _ = self.assert_refused("planned", 4)
            self.assertTrue(any("PROCESS" in problem for problem in output["problems"]), output)
        finally:
            self.fixture.sql("GRANT PROCESS ON *.* TO 'repro_cutover_probe'@'localhost'")
        self.assertEqual(0, self.host.run("planned")[0])

    def test_probe_cannot_read_authentication_string(self):
        for statement in ("SELECT authentication_string FROM mysql.user LIMIT 1",
                          "SELECT * FROM mysql.user LIMIT 1"):
            with self.subTest(statement=statement):
                result = self.fixture.client("repro_cutover_probe", statement)
                self.assertNotEqual(0, result.returncode)
                self.assertRegex(result.stderr, r"ERROR 114[23] ")
                self.assertEqual("", result.stdout)

    def test_probe_reads_every_privilege_column(self):
        import mysql_probe

        columns = self.fixture.sql(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mysql'"
            " AND TABLE_NAME IN ('user', 'db') AND COLUMN_NAME LIKE '%\\_priv' ORDER BY TABLE_NAME, ORDINAL_POSITION")
        found = {"user": [], "db": []}
        for line in columns.splitlines():
            table, column = line.split("\t")
            found[table].append(column)
        self.assertEqual(31, len(found["user"]))
        self.assertEqual(found["user"], list(mysql_probe.USER_PRIVILEGES))
        self.assertEqual(found["db"], list(mysql_probe.DB_PRIVILEGES))
        granted = re.search(r"GRANT SELECT \(([^)]*)\)\s+ON mysql\.user", ACCOUNTS_SQL.read_text(encoding="utf-8"))
        self.assertEqual(["Host", "User", "account_locked", *found["user"]],
                         [name.strip() for name in granted.group(1).split(",")])

    def test_probe_refuses_unsafe_defaults_file_or_client(self):
        self.host.defaults(self.fixture.directory / "repro_cutover_probe.cnf", mode=0o644)
        code, output, _ = self.host.run("planned")
        self.assertNotIn(code, (0, 3))
        self.host.defaults(self.fixture.directory / "repro_cutover_probe.cnf")
        client = self.host.etc / "mysql"
        shutil.copyfile(CLIENT, client)
        os.chmod(client, 0o755)
        self.host.config["mysql"]["client"] = str(client)
        self.assertEqual(0, self.host.run("planned")[0])
        os.chmod(client, 0o775)
        code, output, _ = self.host.run("planned")
        self.assertNotIn(code, (0, 3))

    def test_probe_runs_only_as_root(self):
        code, output, stderr = self.host.run("planned", as_user=65534)
        self.assertNotIn(code, (0, 3))
        self.assertIn("runs as root", stderr)


# The run that ProbeHost's journal names (test_adapter.IDENTITY.run_id).
RUN_ID = "host-fixture"
CLOSE_GATE = ("REVOKE INSERT, UPDATE, DELETE ON `repro\\_dashboard`.* FROM 'repro_app'@'localhost';"
              "REVOKE UPDATE (`id`) ON `repro_mysql_control`.`writer_token` FROM 'repro_app'@'localhost'")


def receipt_record(server_uuid: str, file: str, position: int, executed: str, run_id: str = RUN_ID,
                   database: str = "repro_dashboard") -> dict:
    """The receipt prepare writes (design 1.2, 3.2 step 10); here backup and armed_from are one coordinate."""
    coordinate = {"file": file, "position": position}
    return {"version": 1, "run_id": run_id, "server_uuid": server_uuid, "database": database,
            "backup": {**coordinate, "gtid_executed": executed}, "armed_from": coordinate}


def activated_record(server_uuid: str, receipt_sha256: str, run_id: str = RUN_ID) -> dict:
    """What a successful MySQL reopen writes (design 3.6 step 16): from then on no scan is needed."""
    return {"version": 1, "run_id": run_id, "server_uuid": server_uuid, "database": "repro_dashboard",
            "receipt_sha256": receipt_sha256}


def window_record(receipt_sha256: str, before: str, after: str, run_id: str = RUN_ID) -> dict:
    """The smoke window the MySQL reopen records around the write smoke (design 3.6 step 7)."""
    return {"version": 1, "run_id": run_id, "receipt_sha256": receipt_sha256, "gtid_before": before,
            "gtid_after": after, "report": {"ok": True}}


def gate_state(open_gate: bool = False) -> dict:
    """The probe's state document for repro_app as the server prints it: closed, or open."""
    import mysql_probe

    account = {"user": "repro_app", "host": "localhost"}
    user = {**account, "account_locked": "N", **{name: "N" for name in mysql_probe.USER_PRIVILEGES}}
    schema = {**account, "db": "repro\\_dashboard", **{name: "N" for name in mysql_probe.DB_PRIVILEGES},
              "Select_priv": "Y"}
    token = {**account, "db": "repro_mysql_control", "table": "writer_token", "table_priv": "Select",
             "column_priv": ""}
    columns = None
    if open_gate:
        schema.update(Insert_priv="Y", Update_priv="Y", Delete_priv="Y")
        token["column_priv"] = "Update"
        columns = [{**account, "db": "repro_mysql_control", "table": "writer_token", "column": "id",
                    "column_priv": "Update"}]
    return {"user": [user], "global_grants": None, "role_edges": None, "default_roles": None, "procs_priv": None,
            "proxies_priv": None, "db": [schema], "tables_priv": [token], "columns_priv": columns, "sessions": None,
            "probe_privileges": ["PROCESS", "REPLICATION CLIENT", "REPLICATION SLAVE"], "mandatory_roles": ""}


# A root-owned stand-in for the mysql client. It answers the probe's three statement forms from
# server.json next to it, logs every statement, and refuses --raw on SHOW statements (newlines in a
# statement's text would then split its row).
FAKE_CLIENT = """#!{python} -I
import json, re, sys
from pathlib import Path
here = Path(__file__).resolve().parent
statement = sys.argv[sys.argv.index("-e") + 1]
with open(here / "calls.log", "a", encoding="utf-8") as log:
    log.write(json.dumps(statement) + "\\n")
data = json.loads((here / "server.json").read_text(encoding="utf-8"))
out = sys.stdout.buffer
if data["down"]:
    # What the 8.4.13 client prints when nothing listens at the socket.
    socket = next(argument.split("=", 1)[1] for argument in sys.argv if argument.startswith("--socket="))
    sys.stderr.write("ERROR 2002 (HY000): Can't connect to local MySQL server through socket '%s' (2)\\n" % socket)
    sys.exit(1)
if statement.startswith("SELECT JSON_OBJECT('server_uuid'"):
    out.write((json.dumps(data["server"]) + "\\n" + json.dumps(data["state"]) + "\\n").encode())
    sys.exit(0)
if "--raw" in sys.argv:
    sys.stderr.write("fake client: --raw on a SHOW statement\\n")
    sys.exit(1)
if statement == "SHOW BINARY LOG STATUS; SHOW BINARY LOGS":
    names = list(data["files"])
    out.write(("%s\\t%d\\t%s\\t%s\\t%s\\n" % (names[-1], data["sizes"][names[-1]], *data["filters"],
                                          data["executed"])).encode())
    for name in names:
        out.write(("%s\\t%d\\tNo\\n" % (name, data["sizes"][name])).encode())
    sys.exit(0)
match = re.fullmatch(r"SHOW BINLOG EVENTS IN '([^']+)'(?: FROM ([0-9]+))? LIMIT ([0-9]+), ([0-9]+)", statement)
if match is None or match.group(1) not in data["files"]:
    sys.stderr.write("ERROR 1220 (HY000) at line 1: Error when executing command SHOW BINLOG EVENTS: "
                     "Could not find target log\\n")
    sys.exit(1)
rows = [row + "\\n" for row in data["files"][match.group(1)].split("\\n")[:-1]]
start = int(match.group(2) or 0)
rows = [row for row in rows if int(row.split("\\t")[1]) >= start]
offset, count = int(match.group(3)), int(match.group(4))
out.write("".join(rows[offset:offset + count]).encode())
"""


class FakeServer:
    """ProbeHost's server side without MySQL: the fake client plus the recorded binary logs it serves."""

    def __init__(self):
        self.root = Path(tempfile.mkdtemp(prefix="repro-probe-fake-", dir="/var/lib"))
        os.chmod(self.root, 0o755)
        self.directory = self.root
        self.socket = str(self.root / "mysql.sock")
        self.uuid = recorded.E6_UUID
        defaults = self.root / "repro_cutover_probe.cnf"
        defaults.write_text("[client]\nuser=repro_cutover_probe\n", encoding="ascii")
        os.chmod(defaults, 0o600)
        self.client = self.root / "mysql"
        self.client.write_text(FAKE_CLIENT.replace("{python}", sys.executable), encoding="utf-8")
        os.chmod(self.client, 0o755)
        self.server = {"server_uuid": self.uuid, "version": "8.4.13", "skip_networking": 1,
                       "current_user": "repro_cutover_probe@localhost"}
        self.state = gate_state()
        self.files: dict[str, str] = {}
        self.sizes: dict[str, int] = {}
        self.executed = f"{self.uuid}:1-59"
        # SHOW BINARY LOG STATUS's Binlog_Do_DB and Binlog_Ignore_DB (no filter on production).
        self.filters = ["", ""]
        # True: the client answers as when nothing listens at the socket (error 2002).
        self.down = False

    def log(self, name: str, recording: list, server_id: int = 64086) -> None:
        self.files[name] = self.files.get(name, "") + recorded.rows(recording, name, server_id).decode("utf-8")
        self.sizes[name] = max(position for position, _, _ in recording) + 100

    def save(self) -> None:
        (self.root / "calls.log").unlink(missing_ok=True)
        (self.root / "server.json").write_text(json.dumps({
            "server": self.server, "state": self.state, "files": self.files, "sizes": self.sizes,
            "executed": self.executed, "filters": self.filters, "down": self.down}), encoding="utf-8")

    def calls(self) -> list[str]:
        path = self.root / "calls.log"
        if not path.exists():
            return []
        return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines()]

    def scans(self) -> list[str]:
        return [call for call in self.calls() if call.startswith("SHOW BINLOG EVENTS")]


def renumber(entries: list, start: int) -> list:
    """(type, info) pairs at increasing positions from `start`, as recorded rows."""
    return [(start + 100 * index, kind, info) for index, (kind, info) in enumerate(entries)]


def heartbeats(first: int, count: int) -> list:
    """`count` heartbeat transactions (five events each), GTIDs from `first`."""
    return [entry for number in range(first, first + count)
            for entry in (("Gtid", recorded.gtid(recorded.E6_UUID, number)), *recorded.write(
                "repro_recovery_heartbeat", 117))]


def measured_output(uuid: str, **activity) -> dict:
    return {**closed_output_for(uuid, "measured"), **activity}


def closed_output_for(uuid: str, mode: str) -> dict:
    return {"version": 2, "reachable": True, "server_uuid": uuid, "database": "repro_dashboard",
            "gate": "closed", "writable": False, "business_activity": False, "foreign_activity": False,
            "boundary": None, "receipt": None, "gated_sessions": 0, "scanned_events": 0, "mode": mode}


@unittest.skipUnless(LINUX_ROOT, "Needs Linux root: the probe runs as root")
class FakeServerTests(unittest.TestCase):
    """The probe's receipt, scan, latch and audit logic against recorded 8.4.13 text (no MySQL needed)."""

    LISTING = "SHOW BINARY LOG STATUS; SHOW BINARY LOGS"

    def setUp(self):
        self.fake = FakeServer()
        self.addCleanup(shutil.rmtree, self.fake.root)
        self.host = ProbeHost(self.fake)
        self.addCleanup(shutil.rmtree, self.host.root)
        self.host.config["mysql"]["client"] = str(self.fake.client)
        self.fake.log("binlog.000001", recorded.E6_G6)
        self.fake.save()

    def arm(self, **changes) -> str:
        record = {**receipt_record(self.fake.uuid, "binlog.000001", 13791, f"{self.fake.uuid}:1-48"), **changes}
        return hashlib.sha256(self.host.write("receipt", record)).hexdigest()

    def run_probe(self, phase, *arguments):
        self.fake.save()
        return self.host.run(phase, arguments=arguments)

    def assert_refused(self, phase, expected_exit, *arguments):
        code, output, stderr = self.run_probe(phase, *arguments)
        self.assertEqual(expected_exit, code, (output, stderr))
        if isinstance(output, dict):
            self.assertNotIn("writable", output)
            self.assertNotIn("business_activity", output)
        return output, stderr

    def records(self) -> dict:
        """The files under the journal directory other than the journal itself (which run_probe rewrites)."""
        return {item.name: (item.lstat().st_ino, item.lstat().st_mtime_ns) for item in self.host.state.iterdir()
                if item.name != "state.json"}

    def e6_activity(self, sha: str, **changes) -> dict:
        return {**measured_output(self.fake.uuid, business_activity=True, foreign_activity=True, receipt=sha,
                                  boundary={"file": "binlog.000001", "position": 14927,
                                            "gtid": f"{self.fake.uuid}:53"},
                                  scanned_events=len(recorded.E6_G6)), **changes}

    def test_other_patch_version_exit_6(self):
        for version in ("8.4.12", "8.4.14"):
            with self.subTest(version=version):
                self.fake.server["version"] = version
                output, _ = self.assert_refused("ready", 6)
                self.assertEqual(self.fake.uuid, output["server_uuid"])
                self.assertTrue(any("8.4.13" in problem for problem in output["problems"]), output)
        self.fake.server["version"] = "8.4.13"
        self.assertEqual(0, self.run_probe("ready")[0])

    def test_binary_log_filters_exit_6(self):
        # Review B2-7: binlog-do-db or binlog-ignore-db would keep app writes out of the scan. Every mode
        # that scans refuses as a wrong server, before any binary log is read and without a latch. (The
        # fixture cannot start with a filter unless fixture.sh changes; the status text is recorded.)
        sha = self.arm()
        self.host.write("smoke_window", window_record(sha, *recorded.E6_SMOKE_WINDOW))
        for filters, column in ((["", "repro_dashboard"], "Binlog_Ignore_DB"), (["other_db", ""], "Binlog_Do_DB")):
            self.fake.filters = filters
            for phase, arguments in (("ready", ()), ("sqlite_authoritative", ()), ("recovery_required", ("--audit",)),
                                     ("frozen", ("--since", "binlog.000001:13791"))):
                with self.subTest(filters=filters, phase=phase, arguments=arguments):
                    output, stderr = self.assert_refused(phase, 6, *arguments)
                    self.assertEqual((self.fake.uuid, "closed"), (output["server_uuid"], output["gate"]))
                    self.assertEqual(1, len(output["problems"]), output)
                    self.assertIn(column, output["problems"][0])
                    self.assertNotIn("repro_dashboard", output["problems"][0])
                    self.assertNotIn("other_db", output["problems"][0])
                    self.assertEqual([], self.fake.scans())
                    self.assertFalse(self.host.path("activity").exists())
        self.fake.filters = ["", ""]
        self.assertEqual((0, self.e6_activity(sha)), self.run_probe("ready")[:2])

    def test_read_gate_hands_out_the_classified_rows(self):
        # For gate.py (D06 open item): the state document the gate was classified from, with a reading and
        # with a refusal after the classification, so gate.py's root SQL needs no second read of the rows.
        import mysql_probe

        config = self.host.config
        self.fake.save()
        reading = mysql_probe.read_gate(config, "measured")
        self.assertEqual((self.fake.server, self.fake.state, "closed"),
                         (reading.server, reading.state, reading.gate.name))
        self.assertEqual((reading.server, reading.gate), mysql_probe.read(config, "measured"))
        edge = {"user": "repro_app", "host": "localhost", "other_user": "repro_test_role", "other_host": "%"}
        partial = gate_state()
        partial["db"][0]["Insert_priv"] = "Y"
        opened = sorted(mysql_probe.OPEN_PRIVILEGES)
        for name, state, mode, gate, open_privileges in (
                ("unrecognised", {**gate_state(), "role_edges": [edge]}, "measured", "unrecognised", []),
                ("partial", partial, "measured", "partial", ["INSERT"]),
                ("open in strict mode", gate_state(open_gate=True), "strict", "open", opened)):
            with self.subTest(state=name):
                self.fake.state = state
                self.fake.save()
                with self.assertRaises(mysql_probe.Refusal) as caught:
                    mysql_probe.read_gate(config, mode)
                refusal = caught.exception
                reading = refusal.reading
                self.assertEqual((4, gate, state, self.fake.server),
                                 (refusal.code, reading.gate.name, reading.state, reading.server))
                self.assertEqual({"repro_app@localhost": open_privileges}, reading.gate.open_privileges)
                # What the probe prints is unchanged: never the rows.
                self.assertEqual({"version", "reachable", "server_uuid", "database", "gate", "mode", "problems",
                                  "open_privileges"}, set(refusal.output))
        # Refusals before the classification carry no rows: a wrong server (6), an unreachable one (3).
        self.fake.state = gate_state()
        for name, change, code in (("wrong server", {"server_uuid": OTHER_UUID}, 6), ("unreachable", None, 3)):
            with self.subTest(refusal=name):
                if change is None:
                    self.fake.down = True
                else:
                    self.fake.server = {**self.fake.server, **change}
                self.fake.save()
                with self.assertRaises(mysql_probe.Refusal) as caught:
                    mysql_probe.read_gate(config, "measured")
                self.assertEqual((code, None), (caught.exception.code, caught.exception.reading))

    def test_unreachable_is_exit_3_even_with_this_runs_records(self):
        # The command-line contract D07's adapter relies on: exit 3 prints exactly {"version": 2,
        # "reachable": false}, which it maps to false/false while writes_possible is false. The server is
        # read before any record, so this run's receipt, latch, activation record or smoke window never
        # turn an unreachable server into another exit, and nothing is written.
        sha = self.arm()
        self.host.write("activity", {"version": 1, "run_id": RUN_ID, "receipt_sha256": sha,
                                     "gtid": f"{self.fake.uuid}:55", "file": "binlog.000001", "position": 15434,
                                     "tables": ["shoots"], "statements": [], "boundary": None,
                                     "foreign_activity": False})
        self.host.write("activated", activated_record(self.fake.uuid, sha))
        self.host.write("smoke_window", window_record(sha, *recorded.E6_SMOKE_WINDOW))
        before = self.records()
        mysql = self.host.config["mysql"]
        mysql["socket"] = mysql["switch"]["DB_SOCKET"] = str(self.host.root / "no-server" / "mysql.sock")
        runs = [(phase, ()) for phase in STRICT_JOURNALS + MEASURED_JOURNALS]
        runs += [("recovery_required", ("--audit",)), ("frozen", ("--since", "binlog.000001:13791"))]
        for down in ("client answers 2002", "client not installed yet"):
            self.fake.down = down == "client answers 2002"
            if down == "client not installed yet":
                mysql["client"] = str(self.host.root / "no-bundle" / "bin" / "mysql")
            for phase, arguments in runs:
                with self.subTest(down=down, journal=phase, arguments=arguments):
                    code, output, stderr = self.run_probe(phase, *arguments)
                    self.assertEqual((3, {"version": 2, "reachable": False}), (code, output), stderr)
                    self.assertEqual(before, self.records())

    def test_no_receipt_or_other_run_receipt_is_false(self):
        code, output, stderr = self.run_probe("ready")
        self.assertEqual((0, measured_output(self.fake.uuid)), (code, output), stderr)
        self.assertEqual([], self.fake.scans())
        self.arm(run_id="another-run")
        code, output, stderr = self.run_probe("ready")
        self.assertEqual((0, measured_output(self.fake.uuid)), (code, output), stderr)
        self.assertEqual([], self.fake.scans())
        # Without a journal there is no run, so no receipt is this run's (strict, as D04 printed it).
        sha = self.arm()
        code, output, stderr = self.run_probe(None)
        self.assertEqual((0, closed_output_for(self.fake.uuid, "strict")), (code, output), stderr)
        self.assertEqual([], self.fake.scans())
        code, output, stderr = self.run_probe("ready")
        self.assertEqual((0, self.e6_activity(sha)), (code, output), stderr)

    def test_scan_pages_by_offset_from_the_receipt(self):
        # 1039 events: E6 up to its GRANT, 200 heartbeats, then E6 from the GRANT (the business writes last).
        entries = [(kind, info) for _, kind, info in recorded.until(recorded.E6_G6, 53)]
        entries += heartbeats(100, 200)
        entries += [(kind, info) for _, kind, info in recorded.between(recorded.E6_G6, 53, 59)]
        self.fake.files, self.fake.sizes = {}, {}
        self.fake.log("binlog.000001", renumber(entries, 13791))
        sha = self.arm()
        code, output, stderr = self.run_probe("writes_possible")
        self.assertEqual(0, code, stderr)
        self.assertEqual((True, True, len(entries), sha),
                         (output["business_activity"], output["foreign_activity"], output["scanned_events"],
                          output["receipt"]))
        self.assertEqual([self.LISTING] + [f"SHOW BINLOG EVENTS IN 'binlog.000001' FROM 13791 LIMIT {offset}, 500"
                                           for offset in (0, 500, 1000)], self.fake.calls()[1:])

    def test_later_files_are_read_from_their_start(self):
        self.fake.files, self.fake.sizes = {}, {}
        self.fake.log("binlog.000001", recorded.until(recorded.E6_G6, 53))
        self.fake.log("binlog.000002", recorded.between(recorded.E6_G6, 53, 59))
        sha = self.arm()
        code, output, stderr = self.run_probe("ready")
        self.assertEqual((0, self.e6_activity(sha, boundary={"file": "binlog.000002", "position": 14927,
                                                             "gtid": f"{self.fake.uuid}:53"})), (code, output), stderr)
        self.assertEqual(["SHOW BINLOG EVENTS IN 'binlog.000001' FROM 13791 LIMIT 0, 500",
                          "SHOW BINLOG EVENTS IN 'binlog.000002' LIMIT 0, 500"], self.fake.scans())

    def test_purged_armed_from_file_exit_5(self):
        self.arm(armed_from={"file": "binlog.000000", "position": 4})
        for phase in ("ready", "sqlite_authoritative"):
            with self.subTest(journal=phase):
                output, _ = self.assert_refused(phase, 5)
                self.assertEqual((True, "closed", self.fake.uuid),
                                 (output["reachable"], output["gate"], output["server_uuid"]))
                self.assertTrue(any("binlog.000000" in problem for problem in output["problems"]), output)
        self.assertEqual([], self.fake.scans())

    def test_reset_binary_log_history_exit_5(self):
        # After RESET BINARY LOGS AND GTIDS the file names come back, but the receipt's history is gone.
        self.fake.executed = f"{recorded.E1_UUID}:1-10"
        self.arm()
        self.assert_refused("ready", 5)
        self.assertEqual([], self.fake.scans())

    def test_armed_from_beyond_the_file_or_not_an_event_exit_4(self):
        self.arm(armed_from={"file": "binlog.000001", "position": 999999})
        self.assert_refused("ready", 4)
        self.arm(armed_from={"file": "binlog.000001", "position": 13792})
        self.assert_refused("ready", 4)

    def test_receipt_of_this_run_for_another_server_or_database_exit_4(self):
        for changes in ({"server_uuid": OTHER_UUID}, {"database": "repro_other"}):
            with self.subTest(changes=changes):
                self.arm(**changes)
                output, _ = self.assert_refused("ready", 4)
                self.assertTrue(output["problems"], output)

    def test_malformed_or_unsafe_records_refused(self):
        sha = self.arm()
        cases = {
            "receipt with another key": ("receipt", {**receipt_record(self.fake.uuid, "binlog.000001", 13791, ""),
                                                     "note": "x"}),
            "receipt version 2": ("receipt", {**receipt_record(self.fake.uuid, "binlog.000001", 13791, ""),
                                              "version": 2}),
            "receipt quoting a file name": ("receipt", receipt_record(self.fake.uuid, "bin'log.1", 13791, "")),
            "receipt with a bad GTID set": ("receipt", receipt_record(self.fake.uuid, "binlog.000001", 13791, "x:1")),
            "latch without its tables": ("activity", {"version": 1, "run_id": RUN_ID, "receipt_sha256": sha}),
            "activation without its server": ("activated", {"version": 1, "run_id": RUN_ID}),
        }
        for name, (record, document) in cases.items():
            with self.subTest(case=name):
                self.arm()
                self.host.write(record, document)
                code, output, stderr = self.run_probe("ready")
                self.assertEqual(1, code, (output, stderr))
                self.assertIsNone(output)
                if record != "receipt":
                    self.host.path(record).unlink()
        self.arm()
        for mode in (0o620, 0o602):
            with self.subTest(mode=oct(mode)):
                os.chmod(self.host.path("receipt"), mode)
                self.assertEqual(1, self.run_probe("ready")[0])
        self.host.path("receipt").unlink()
        os.symlink(self.fake.root / "server.json", self.host.path("receipt"))
        self.assertEqual(1, self.run_probe("ready")[0])

    def test_unsafe_records_refused_by_every_reading_that_reads_them(self):
        # Review B2-8: each record goes through guard.read_protected. The plain reading reads the receipt,
        # the latch and the activation record; --audit reads the receipt and the smoke window (it never
        # reads the latch or the activation record). A record that is safe reads; the same record
        # group-writable, owned by another user or behind a symbolic link is exit 1 with nothing printed.
        readers = (("receipt", ()), ("activity", ()), ("activated", ()), ("receipt", ("--audit",)),
                   ("smoke_window", ("--audit",)))
        for name, arguments in readers:
            for unsafe in ("group-writable", "another owner", "symbolic link"):
                with self.subTest(record=name, arguments=arguments, unsafe=unsafe):
                    for record in RECORDS:
                        self.host.path(record).unlink(missing_ok=True)
                    sha = self.arm()
                    documents = {
                        "activity": {"version": 1, "run_id": RUN_ID, "receipt_sha256": sha,
                                     "gtid": f"{self.fake.uuid}:55", "file": "binlog.000001", "position": 15434,
                                     "tables": ["jobs", "shoots"], "statements": [], "boundary": None,
                                     "foreign_activity": False},
                        "activated": activated_record(self.fake.uuid, sha),
                        "smoke_window": window_record(sha, *recorded.E6_SMOKE_WINDOW),
                    }
                    if name != "receipt":
                        self.host.write(name, documents[name])
                    self.assertEqual(0, self.run_probe("recovery_required", *arguments)[0])
                    path = self.host.path(name)
                    if unsafe == "group-writable":
                        os.chmod(path, 0o620)
                    elif unsafe == "another owner":
                        os.chown(path, 65534, 65534)
                    else:
                        target = self.fake.root / f"{name}.json"
                        path.rename(target)
                        os.symlink(target, path)
                    before = self.records()
                    code, output, stderr = self.run_probe("recovery_required", *arguments)
                    self.assertEqual((1, None), (code, output), stderr)
                    self.assertIn("Unsafe file type, owner or permissions", stderr)
                    self.assertEqual(before, self.records())  # The refused reading wrote nothing.

    def test_journal_without_a_run_id_refuses_only_when_a_record_exists(self):
        self.host.run_identity = None
        self.assertEqual(0, self.run_probe("ready")[0])
        self.arm()
        code, _, stderr = self.run_probe("ready")
        self.assertEqual(1, code, stderr)
        self.assertIn("run", stderr)

    def test_business_activity_is_latched(self):
        sha = self.arm()
        code, output, stderr = self.run_probe("writes_possible")
        self.assertEqual((0, self.e6_activity(sha)), (code, output), stderr)
        latch = self.host.path("activity")
        info = latch.lstat()
        self.assertEqual((0, 0o600), (info.st_uid, stat.S_IMODE(info.st_mode)))
        self.assertEqual({"version": 1, "run_id": RUN_ID, "receipt_sha256": sha, "gtid": f"{self.fake.uuid}:55",
                          "file": "binlog.000001", "position": 15434, "tables": ["jobs", "shoots"], "statements": [],
                          "boundary": {"file": "binlog.000001", "position": 14927, "gtid": f"{self.fake.uuid}:53"},
                          "foreign_activity": True}, json.loads(latch.read_text(encoding="utf-8")))
        # Later calls read the latch: no scan, and a purge of the armed_from file no longer matters.
        self.fake.files, self.fake.sizes = {}, {}
        self.fake.log("binlog.000009", recorded.between(recorded.E1_T7, 37, 37))
        code, output, stderr = self.run_probe("mysql_authoritative")
        self.assertEqual((0, self.e6_activity(sha, scanned_events=0)), (code, output), stderr)
        self.assertEqual([], self.fake.scans())
        # A latch bound to another receipt (prepare re-armed) is stale: the scan decides again.
        self.arm(backup={"file": "binlog.000001", "position": 13791, "gtid_executed": ""})
        self.assert_refused("mysql_authoritative", 5)

    def test_activated_record_short_circuits_scan(self):
        sha = self.arm()
        self.host.write("activated", activated_record(self.fake.uuid, sha))
        self.fake.files, self.fake.sizes = {}, {}
        self.fake.log("binlog.000009", recorded.between(recorded.E1_T7, 37, 37))
        code, output, stderr = self.run_probe("mysql_authoritative")
        self.assertEqual((0, measured_output(self.fake.uuid, business_activity=True, receipt=sha)), (code, output),
                         stderr)
        self.assertEqual(1, len(self.fake.calls()))  # The gate read only.
        # Bound to this run: another run's activation is ignored (here the scan then finds the file purged).
        self.host.write("activated", activated_record(self.fake.uuid, sha, run_id="another-run"))
        self.assert_refused("mysql_authoritative", 5)
        self.host.write("activated", {**activated_record(self.fake.uuid, sha), "server_uuid": OTHER_UUID})
        self.assert_refused("mysql_authoritative", 4)
        # Even without the receipt, this run's activation reports the activity.
        self.host.write("activated", activated_record(self.fake.uuid, sha))
        self.host.path("receipt").unlink()
        code, output, stderr = self.run_probe("mysql_authoritative")
        self.assertEqual((0, measured_output(self.fake.uuid, business_activity=True)), (code, output), stderr)

    def test_strict_mode_business_activity_exit_4(self):
        self.arm()
        for phase in STRICT_JOURNALS[1:]:
            with self.subTest(journal=phase):
                output, _ = self.assert_refused(phase, 4)
                self.assertEqual("strict", output["mode"])
                self.assertTrue(output["problems"], output)
        # The first hit latches in strict mode too.
        self.assertTrue(self.host.path("activity").is_file())
        # Foreign activity alone does not stop the strict reading (it never blocks the rollback).
        self.fake.files, self.fake.sizes = {}, {}
        self.fake.log("binlog.000001", recorded.until(recorded.E6_G6, 53))
        self.host.path("activity").unlink()
        code, output, stderr = self.run_probe("sqlite_authoritative")
        self.assertEqual(0, code, stderr)
        self.assertEqual((False, True), (output["business_activity"], output["foreign_activity"]))

    def test_audit_classifies_foreign_smoke_and_business(self):
        sha = self.arm()
        self.host.write("smoke_window", window_record(sha, *recorded.E6_SMOKE_WINDOW))
        code, output, stderr = self.run_probe("recovery_required", "--audit")
        self.assertEqual(0, code, stderr)
        self.assertEqual([(f"{self.fake.uuid}:{number}", kind) for number, kind in
                          ((49, "foreign"), (55, "business"), (56, "business"), (57, "business"))],
                         [(item["gtid"], item["class"]) for item in output["transactions"]])
        self.assertEqual({"foreign": 1, "smoke": 0, "business": 3}, output["counts"])
        self.assertEqual((sha, {"file": "binlog.000001", "position": 13791}, len(recorded.E6_G6)),
                         (output["receipt"], output["armed_from"], output["scanned_events"]))
        self.assertIsNone(output["smoke_window"], "An old unverified synthetic report cannot authorize rollback")
        self.assertFalse(self.host.path("activity").exists())  # The audit only reads.
        # A window recorded for another receipt is not this run's: every business transaction is business.
        self.host.write("smoke_window", window_record("0" * 64, *recorded.E6_SMOKE_WINDOW))
        code, output, stderr = self.run_probe("recovery_required", "--audit")
        self.assertEqual((0, None), (code, output["smoke_window"]), stderr)
        self.assertEqual({"foreign": 1, "smoke": 0, "business": 3}, output["counts"])
        self.host.path("receipt").unlink()
        self.assert_refused("recovery_required", 4, "--audit")

    def test_since_reports_the_coordinate_and_every_app_transaction(self):
        code, output, stderr = self.run_probe("frozen", "--since", "binlog.000001:13791")
        self.assertEqual(0, code, stderr)
        self.assertEqual({"file": "binlog.000001", "position": 13791}, output["since"])
        self.assertEqual({"file": "binlog.000001", "position": 16962, "gtid_executed": f"{self.fake.uuid}:1-59"},
                         output["until"])
        self.assertEqual([49, 55, 56, 57], recorded.gtids_of(output["transactions"]))
        self.assertEqual("closed", output["gate"])
        self.assertNotIn("writable", output)
        self.assertEqual([], [path.name for path in self.host.state.iterdir() if path.name != "state.json"])
        self.assert_refused("frozen", 5, "--since", "binlog.000000:4")
        for argument in ("binlog.000001", "bin'log:4", "binlog.000001:-4"):
            with self.subTest(argument=argument):
                self.assertEqual(2, self.run_probe("frozen", "--since", argument)[0])
        self.assertEqual(2, self.run_probe("frozen", "--since", "binlog.000001:4", "--audit")[0])

    def test_since_gtid_requires_that_history(self):
        # Review B2-9: --since alone checks no history. With --since-gtid <the backup's gtid_executed>, a
        # gtid_executed that no longer holds it (RESET BINARY LOGS AND GTIDS reuses file names) is exit 5,
        # before any event is read, as for the receipt.
        since = ("--since", "binlog.000001:13791")
        backup = f"{self.fake.uuid}:1-48"
        plain = self.run_probe("frozen", *since)
        self.assertEqual(0, plain[0], plain[2])
        for history in (backup, "", self.fake.executed):
            with self.subTest(history=history):
                code, output, stderr = self.run_probe("frozen", *since, "--since-gtid", history)
                self.assertEqual((0, plain[1]), (code, output), stderr)
        self.fake.executed = f"{recorded.E1_UUID}:1-10"
        self.assertEqual(0, self.run_probe("frozen", *since)[0])  # Unnoticed without the check.
        for history in (backup, f"{self.fake.uuid}:60"):
            with self.subTest(history=history, reset=True):
                output, _ = self.assert_refused("frozen", 5, *since, "--since-gtid", history)
                self.assertIn("history was reset", output["problems"][0])
                self.assertEqual([], self.fake.scans())
        for arguments in (("--since-gtid", backup), ("--audit", "--since-gtid", backup),
                          (*since, "--since-gtid", "x:1"), (*since, "--since-gtid", f"{self.fake.uuid}:0")):
            with self.subTest(arguments=arguments):
                self.assertEqual(2, self.run_probe("frozen", *arguments)[0])


# The app tables these tests write, created before any receipt (DDL on the app schema is an app-schema event).
APP_TABLES = """
CREATE TABLE IF NOT EXISTS repro_dashboard.shoots (id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64));
CREATE TABLE IF NOT EXISTS repro_dashboard.jobs (id BIGINT AUTO_INCREMENT PRIMARY KEY, queue VARCHAR(64));
CREATE TABLE IF NOT EXISTS repro_dashboard.repro_recovery_heartbeat (id BIGINT AUTO_INCREMENT PRIMARY KEY,
  minute_utc DATETIME);
"""
FOREIGN_WRITE = ("repro_maint", "INSERT INTO repro_dashboard.shoots (name) VALUES ('maint after receipt (foreign)')")
HEARTBEAT = "INSERT INTO repro_dashboard.repro_recovery_heartbeat (minute_utc) VALUES (UTC_TIMESTAMP())"
APP_WRITE = ("repro_app", "INSERT INTO repro_dashboard.shoots (name) VALUES ('business after smoke')")
# E6's G5 statements after the receipt (experiments/e6-gate-v2.sh), in order, as the same accounts.
E6_REPLAY = (
    FOREIGN_WRITE,
    ("root", "ALTER USER 'repro_maint'@'localhost' ACCOUNT LOCK; ALTER USER 'repro_migrator'@'localhost' ACCOUNT LOCK"),
    ("root", HEARTBEAT),
    ("repro_cutover_gate", OPEN_GATE),
    ("repro_app", "BEGIN; SELECT id FROM repro_mysql_control.writer_token WHERE id = 1 FOR UPDATE;"
                  " INSERT INTO repro_dashboard.shoots (name) VALUES ('smoke');"
                  " INSERT INTO repro_dashboard.jobs (queue) VALUES ('cutover-smoke'); COMMIT;"
                  " DELETE FROM repro_dashboard.jobs WHERE queue = 'cutover-smoke'"),
    APP_WRITE,
    ("repro_cutover_gate", CLOSE_GATE),
)


def normalized(entries, uuid: str) -> list:
    """(type, Info) with the server UUID, GTID numbers, xids and table ids that differ between servers replaced."""
    out = []
    for kind, info in entries:
        info = re.sub(r"xid=[0-9]+", "xid=N", info.replace(uuid, "<uuid>"))
        info = re.sub(r"<uuid>:[0-9]+'", "<uuid>:N'", re.sub(r"table_id: [0-9]+", "table_id: N", info))
        out.append((kind, info))
    return out


@unittest.skipUnless(LINUX_ROOT and FIXTURE, "Needs Linux root and the MySQL fixture (REPRO_MYSQL_PROBE_FIXTURE)")
class ActivityTests(unittest.TestCase):
    """Business activity from the receipt on a real 8.4.13 binary log (design 1.2, 4.3)."""

    @classmethod
    def setUpClass(cls):
        cls.fixture = Fixture(FIXTURE)
        cls.fixture.restore()
        cls.fixture.sql(APP_TABLES)
        cls.baseline = cls.fixture.snapshot()

    def setUp(self):
        self.host = ProbeHost(self.fixture)
        self.addCleanup(shutil.rmtree, self.host.root)
        self.addCleanup(self.assert_restored)

    def assert_restored(self):
        self.fixture.sql("SET GLOBAL binlog_transaction_compression = OFF;"
                         "DROP TABLE IF EXISTS repro_dashboard.repro_test_ml;"
                         "ALTER USER 'repro_maint'@'localhost', 'repro_migrator'@'localhost',"
                         " 'repro_parity_reader'@'localhost' ACCOUNT UNLOCK")
        self.fixture.restore()
        self.assertEqual(self.baseline, self.fixture.snapshot())

    def status(self) -> tuple[str, int, str]:
        file, position, *rest = self.fixture.sql("SHOW BINARY LOG STATUS").rstrip("\n").split("\t")
        return file, int(position), rest[-1]

    def arm(self, run_id: str = RUN_ID, at: tuple | None = None) -> str:
        """prepare's receipt at the current coordinate (or `at`); returns its SHA-256."""
        file, position, executed = at or self.status()
        raw = self.host.write("receipt", receipt_record(self.fixture.uuid, file, position, executed, run_id))
        return hashlib.sha256(raw).hexdigest()

    def do(self, *steps) -> None:
        for account, statements in steps:
            self.fixture.sql(statements, account=account)

    def events(self, file: str, position: int) -> list:
        import binlog_scan

        result = subprocess.run(
            [CLIENT, f"--defaults-file={self.fixture.directory / 'root.cnf'}", "--no-login-paths",
             "--protocol=SOCKET", f"--socket={self.fixture.socket}", "--batch", "--skip-column-names", "-e",
             f"SHOW BINLOG EVENTS IN '{file}' FROM {position}"], capture_output=True, timeout=60)
        self.assertEqual(0, result.returncode, result.stderr)
        return binlog_scan.parse_events(result.stdout, file)

    def probe(self, phase, *arguments, expected: int = 0):
        code, output, stderr = self.host.run(phase, arguments=arguments)
        self.assertEqual(expected, code, (output, stderr))
        if expected:
            self.assertNotIn("writable", output)
            self.assertNotIn("business_activity", output)
        return output

    def test_recorded_e6_text_is_what_this_server_prints(self):
        file, position, _ = self.status()
        sha = self.arm()
        self.do(*E6_REPLAY)
        live = self.events(file, position)
        self.assertEqual(normalized([(kind, info) for _, kind, info in recorded.E6_G6], recorded.E6_UUID),
                         normalized([(event.type, event.info) for event in live], self.fixture.uuid))
        grant = next(event for event in live if event.type == "Query" and event.info.startswith("GRANT"))
        output = self.probe("recovery_required")
        self.assertEqual((True, True, sha, len(live), {"file": file, "position": grant.position}),
                         (output["business_activity"], output["foreign_activity"], output["receipt"],
                          output["scanned_events"], {key: output["boundary"][key] for key in ("file", "position")}))
        self.assertTrue(self.host.path("activity").is_file())

    def test_no_receipt_or_other_run_receipt_is_false(self):
        start = self.status()
        self.arm(run_id="another-run")
        self.do(("repro_cutover_gate", OPEN_GATE), APP_WRITE)
        expected = {**closed_output(self.fixture, "measured"), "gate": "open", "writable": True}
        self.assertEqual(expected, self.probe("writes_possible"))
        self.host.path("receipt").unlink()
        self.assertEqual(expected, self.probe("writes_possible"))
        sha = self.arm(at=start)
        output = self.probe("writes_possible")
        self.assertEqual((True, False, sha), (output["business_activity"], output["foreign_activity"],
                                              output["receipt"]))

    def test_app_schema_write_before_boundary_is_foreign(self):
        sha = self.arm()
        self.do(FOREIGN_WRITE)
        for phase in ("frozen", "ready", "sqlite_authoritative"):
            with self.subTest(journal=phase):
                output = self.probe(phase)
                self.assertEqual({**closed_output(self.fixture, journal_mode(phase)), "foreign_activity": True,
                                  "receipt": sha, "scanned_events": 5}, output)
        self.assertFalse(self.host.path("activity").exists())

    def test_app_write_after_grant_is_business_and_latched(self):
        sha = self.arm()
        self.do(("repro_cutover_gate", OPEN_GATE))
        output = self.probe("writes_possible")
        # The GRANT is a boundary, but nothing has written yet (design 1.2, writes_possible).
        self.assertEqual((True, False, False), (output["writable"], output["business_activity"],
                                                output["foreign_activity"]))
        self.assertIsNotNone(output["boundary"])
        self.do(APP_WRITE)
        output = self.probe("writes_possible")
        self.assertEqual((True, sha), (output["business_activity"], output["receipt"]))
        latch = json.loads(self.host.path("activity").read_text(encoding="utf-8"))
        self.assertEqual((sha, ["shoots"], output["boundary"]), (latch["receipt_sha256"], latch["tables"],
                                                                 latch["boundary"]))
        latched = self.probe("mysql_authoritative")
        self.assertEqual({**output, "scanned_events": 0}, latched)

    def test_account_lock_of_lockable_account_is_not_a_boundary(self):
        self.arm()
        self.do(("root", "ALTER USER 'repro_maint'@'localhost' ACCOUNT LOCK;"
                         "ALTER USER 'repro_migrator'@'localhost' ACCOUNT LOCK;"
                         "ALTER USER 'repro_maint'@'localhost' ACCOUNT UNLOCK"), FOREIGN_WRITE)
        output = self.probe("ready")
        self.assertEqual((True, False, None), (output["foreign_activity"], output["business_activity"],
                                               output["boundary"]))
        # The same statement for an account outside lockable_accounts is a boundary.
        self.do(("root", "ALTER USER 'repro_parity_reader'@'localhost' ACCOUNT LOCK"), FOREIGN_WRITE)
        output = self.probe("ready")
        self.assertEqual((True, True), (output["foreign_activity"], output["business_activity"]))

    def test_account_list_and_if_exists_locks_are_not_a_boundary(self):
        # Review B2-6: the forms a runbook may type, which the server rewrites to the accepted forms.
        self.arm()
        self.do(("root", "ALTER USER 'repro_maint'@'localhost', 'repro_migrator'@'localhost' ACCOUNT LOCK;"
                         "ALTER USER IF EXISTS repro_maint@localhost , `repro_migrator`@`localhost` ACCOUNT UNLOCK;"
                         "alter user if exists 'repro_migrator'@'localhost' account lock"), FOREIGN_WRITE)
        output = self.probe("ready")
        self.assertEqual((True, False, None), (output["foreign_activity"], output["business_activity"],
                                               output["boundary"]))
        # A list with one account outside lockable_accounts is a boundary, IF EXISTS or not.
        self.do(("root", "ALTER USER IF EXISTS 'repro_migrator'@'localhost', 'repro_parity_reader'@'localhost' "
                         "ACCOUNT LOCK"), FOREIGN_WRITE)
        output = self.probe("ready")
        self.assertEqual((True, True), (output["foreign_activity"], output["business_activity"]))

    def test_heartbeat_table_excluded(self):
        self.arm()
        self.do(("repro_cutover_gate", OPEN_GATE), ("root", "; ".join([HEARTBEAT] * 3)))
        output = self.probe("writes_possible")
        self.assertEqual((False, False, 4 + 15), (output["business_activity"], output["foreign_activity"],
                                                  output["scanned_events"]))
        self.assertIsNotNone(output["boundary"])

    def test_compressed_transaction_counts(self):
        file, position, _ = self.status()
        self.arm()
        self.do(("repro_cutover_gate", OPEN_GATE), ("root", "SET GLOBAL binlog_transaction_compression = ON"),
                APP_WRITE, ("root", "SET GLOBAL binlog_transaction_compression = OFF"), ("root", HEARTBEAT))
        live = self.events(file, position)
        payload = [event for event in live if event.type == "Transaction_payload"]
        self.assertEqual(1, len(payload), live)
        inner = [event for event in live if event.position == payload[0].end_position]
        self.assertIn("Table_map", [event.type for event in inner])
        # Why the scan pages by offset (E1 T7b): FROM the payload's end starts at the next transaction,
        # so paging by position would skip the app write inside the payload.
        following = self.events(file, payload[0].end_position)
        self.assertEqual(["Gtid"], [event.type for event in following if event.position == payload[0].end_position])
        self.assertTrue(self.probe("writes_possible")["business_activity"])

    def test_paging_by_offset_across_pages(self):
        file, position, _ = self.status()
        self.arm()
        self.do(("repro_cutover_gate", OPEN_GATE), ("root", "; ".join([HEARTBEAT] * 120)), APP_WRITE)
        total = len(self.events(file, position))
        self.assertGreater(total, 600)  # The app write is on the second page of 500.
        output = self.probe("writes_possible")
        self.assertEqual((True, total), (output["business_activity"], output["scanned_events"]))

    def test_scan_follows_every_binary_log_file(self):
        self.arm()
        self.do(("root", "FLUSH BINARY LOGS"))
        second = self.status()[0]
        self.do(("repro_cutover_gate", OPEN_GATE), ("root", "FLUSH BINARY LOGS"), APP_WRITE)
        output = self.probe("writes_possible")
        self.assertEqual((True, second), (output["business_activity"], output["boundary"]["file"]))

    def test_purged_armed_from_file_exit_5(self):
        armed = self.status()[0]
        self.arm()
        self.do(("root", "FLUSH BINARY LOGS"))
        self.do(("root", f"PURGE BINARY LOGS TO '{self.status()[0]}'"))
        for phase in ("ready", "sqlite_authoritative"):
            with self.subTest(journal=phase):
                output = self.probe(phase, expected=5)
                self.assertTrue(any(armed in problem for problem in output["problems"]), output)

    def test_activated_record_short_circuits_scan(self):
        sha = self.arm()
        self.do(("repro_cutover_gate", OPEN_GATE), APP_WRITE)
        self.host.write("activated", activated_record(self.fixture.uuid, sha))
        self.do(("root", "FLUSH BINARY LOGS"))
        self.do(("root", f"PURGE BINARY LOGS TO '{self.status()[0]}'"))
        output = self.probe("mysql_authoritative")
        self.assertEqual({**closed_output(self.fixture, "measured"), "gate": "open", "writable": True,
                          "business_activity": True, "receipt": sha}, output)
        self.host.path("activated").unlink()
        self.probe("mysql_authoritative", expected=5)

    def test_audit_classifies_foreign_smoke_and_business(self):
        sha = self.arm()
        self.do(*E6_REPLAY[:4])
        before = self.status()[2]
        self.do(E6_REPLAY[4])
        after = self.status()[2]
        self.do(*E6_REPLAY[5:])
        self.host.write("smoke_window", window_record(sha, before, after))
        output = self.probe("recovery_required", "--audit")
        self.assertEqual([("foreign", ["shoots"]), ("business", ["jobs", "shoots"]), ("business", ["jobs"]),
                          ("business", ["shoots"])],
                         [(item["class"], item["tables"]) for item in output["transactions"]])
        self.assertEqual({"foreign": 1, "smoke": 0, "business": 3}, output["counts"])

    def test_since_reports_the_coordinate_and_app_transactions(self):
        file, position, _ = self.status()
        self.do(FOREIGN_WRITE)
        output = self.probe("frozen", "--since", f"{file}:{position}")
        now = self.status()
        self.assertEqual({"file": now[0], "position": now[1], "gtid_executed": now[2]}, output["until"])
        self.assertEqual([("foreign", ["shoots"])], [(item["class"], item["tables"]) for item in output["transactions"]])
        self.assertEqual([], self.probe("frozen", "--since", f"{now[0]}:{now[1]}")["transactions"])

    def test_since_gtid_holds_on_this_server(self):
        # Review B2-9: the backup's gtid_executed is held, so the reading equals the plain one; a set this
        # server never executed reads as a reset history (exit 5).
        file, position, executed = self.status()
        self.do(FOREIGN_WRITE)
        plain = self.probe("frozen", "--since", f"{file}:{position}")
        self.assertEqual(plain, self.probe("frozen", "--since", f"{file}:{position}", "--since-gtid", executed))
        output = self.probe("frozen", "--since", f"{file}:{position}", "--since-gtid", f"{self.fixture.uuid}:1-999999",
                            expected=5)
        self.assertIn("history was reset", output["problems"][0])

    def test_multi_line_ddl_is_read_whole(self):
        # A statement with newlines, a tab and backslashes: one row in batch mode, unescaped (no --raw).
        file, position, _ = self.status()
        self.arm()
        self.do(("repro_migrator", "USE repro_dashboard; CREATE TABLE repro_test_ml (\n  id INT PRIMARY KEY,"
                                   "\t-- a tab and a \\\\ backslash\n  note VARCHAR(8) DEFAULT 'a\\\\nb'\n)"))
        live = self.events(file, position)
        self.assertIn("\n  id INT PRIMARY KEY,\t-- a tab", live[-1].info)
        output = self.probe("ready")
        self.assertEqual((True, False, len(live)), (output["foreign_activity"], output["business_activity"],
                                                    output["scanned_events"]))

    def test_strict_mode_business_activity_exit_4(self):
        self.arm()
        self.do(("repro_cutover_gate", OPEN_GATE), APP_WRITE, ("repro_cutover_gate", CLOSE_GATE))
        for phase in STRICT_JOURNALS[1:]:
            with self.subTest(journal=phase):
                output = self.probe(phase, expected=4)
                self.assertEqual("strict", output["mode"])
        self.assertTrue(self.probe("recovery_required")["business_activity"])


def _tests(suite):
    for test in suite:
        if isinstance(test, unittest.TestSuite):
            yield from _tests(test)
        else:
            yield test


def fixture_skips() -> list[str]:
    """The tests here that skip for want of Linux root or a MySQL fixture, as Class.method names."""
    skipped = []
    # A fresh loader: `-k` narrows the shared default loader, which would hide every other test here.
    for test in _tests(unittest.TestLoader().loadTestsFromModule(sys.modules[__name__])):
        for target in (type(test), getattr(test, test._testMethodName)):
            if (getattr(target, "__unittest_skip__", False)
                    and "REPRO_MYSQL_PROBE_FIXTURE" in getattr(target, "__unittest_skip_why__", "")):
                skipped.append(f"{type(test).__name__}.{test._testMethodName}")
                break
    return skipped


def fixture_check() -> int:
    """`--fixture-check`, run by run-linux-tests.sh first: one WARNING line on stdout naming the fixture
    tests that would skip, or, with REPRO_REQUIRE_PROBE_FIXTURE=1, an error and exit 1."""
    skipped = fixture_skips()
    if not skipped:
        return 0
    missing = [f"{name} unset" for name in FIXTURE_VARIABLES if not os.environ.get(name)]
    if not LINUX_ROOT:
        missing.append("not Linux root")
    summary = f"{len(skipped)} MySQL probe tests SKIPPED ({', '.join(missing)}): {', '.join(skipped)}"
    if REQUIRE_FIXTURE:
        print(f"ERROR: REPRO_REQUIRE_PROBE_FIXTURE=1 but {summary}", file=sys.stderr)
        return 1
    print(f"WARNING: {summary}. This run says nothing about the probe's gate state: run "
          "scripts/cutover/production/run-probe-fixture-tests.sh, or set REPRO_REQUIRE_PROBE_FIXTURE=1 to fail.")
    return 0


class FixtureRequirementTests(unittest.TestCase):
    """With REPRO_REQUIRE_PROBE_FIXTURE=1 a fixture test that would skip fails the run (review A12)."""

    def test_required_fixture_tests_do_not_skip(self):
        if not REQUIRE_FIXTURE:
            self.skipTest("REPRO_REQUIRE_PROBE_FIXTURE is not 1")
        skipped = fixture_skips()
        self.assertFalse(skipped, f"REPRO_REQUIRE_PROBE_FIXTURE=1, but these MySQL probe tests would skip: "
                                  f"{', '.join(skipped)}")


def without_fixture_environment(**extra: str) -> dict:
    environment = {name: value for name, value in os.environ.items()
                   if name not in (*FIXTURE_VARIABLES, "REPRO_REQUIRE_PROBE_FIXTURE")}
    return {**environment, **extra}


class FixtureCheckTests(unittest.TestCase):
    """`test_mysql_probe.py --fixture-check`, which run-linux-tests.sh runs before any suite (review A12)."""

    def check(self, environment: dict):
        return subprocess.run([sys.executable, "-B", str(Path(__file__).resolve()), "--fixture-check"],
                              env=environment, capture_output=True, text=True, timeout=60)

    def test_missing_fixture_prints_one_warning_line_naming_the_skipped_tests(self):
        result = self.check(without_fixture_environment())
        self.assertEqual((0, ""), (result.returncode, result.stderr))
        lines = result.stdout.splitlines()
        self.assertEqual(1, len(lines), result.stdout)
        self.assertRegex(lines[0], r"^WARNING: [0-9]+ MySQL probe tests SKIPPED ")
        for name in (*FIXTURE_VARIABLES, "GateStateTests.test_closed_state", "GateStateTests.test_open_state",
                     "GateStateTests.test_wrong_probe_password_exit_4", "GateStateTests.test_networking_server_exit_6"):
            self.assertIn(name, lines[0])

    def test_missing_fixture_fails_when_required(self):
        result = self.check(without_fixture_environment(REPRO_REQUIRE_PROBE_FIXTURE="1"))
        self.assertNotEqual(0, result.returncode)
        self.assertEqual("", result.stdout)
        self.assertRegex(result.stderr, r"^ERROR: REPRO_REQUIRE_PROBE_FIXTURE=1 ")
        self.assertIn("GateStateTests.test_closed_state", result.stderr)

    def test_required_run_fails_instead_of_skipping(self):
        # Also with -k, which narrows unittest's shared default loader to the requirement test itself.
        for selection in (["test_mysql_probe.FixtureRequirementTests"],
                          ["discover", "-s", str(HERE), "-p", "test_mysql_probe.py", "-k", "FixtureRequirementTests"]):
            with self.subTest(selection=selection):
                result = subprocess.run([sys.executable, "-B", "-m", "unittest", *selection], cwd=HERE,
                                        env=without_fixture_environment(REPRO_REQUIRE_PROBE_FIXTURE="1"),
                                        capture_output=True, text=True, timeout=60)
                self.assertNotEqual(0, result.returncode, result.stderr)
                self.assertIn("these MySQL probe tests would skip", result.stderr)
                self.assertIn("GateStateTests.test_closed_state", result.stderr)

    @unittest.skipUnless(LINUX_ROOT and FIXTURE and FIXTURE_NETWORKING and FIXTURE_SKIP_GRANTS,
                         "Needs Linux root and all three fixtures (REPRO_MYSQL_PROBE_FIXTURE and its variants)")
    def test_complete_fixture_is_silent_and_a_missing_variant_is_named(self):
        complete = without_fixture_environment(
            REPRO_REQUIRE_PROBE_FIXTURE="1", REPRO_MYSQL_PROBE_FIXTURE=FIXTURE,
            REPRO_MYSQL_PROBE_FIXTURE_NETWORKING=FIXTURE_NETWORKING,
            REPRO_MYSQL_PROBE_FIXTURE_SKIP_GRANTS=FIXTURE_SKIP_GRANTS)
        result = self.check(complete)
        self.assertEqual((0, "", ""), (result.returncode, result.stdout, result.stderr))
        del complete["REPRO_MYSQL_PROBE_FIXTURE_NETWORKING"], complete["REPRO_REQUIRE_PROBE_FIXTURE"]
        result = self.check(complete)
        self.assertEqual((0, ""), (result.returncode, result.stderr))
        self.assertIn("GateStateTests.test_networking_server_exit_6", result.stdout)
        self.assertIn("REPRO_MYSQL_PROBE_FIXTURE_NETWORKING", result.stdout)
        self.assertNotIn("GateStateTests.test_closed_state", result.stdout)


@unittest.skipUnless(LINUX_ROOT and Path("/.dockerenv").is_file()
                     and RUN_LINUX_TESTS == Path("/src/scripts/cutover/run-linux-tests.sh")
                     and not os.environ.get("REPRO_LINUX_SUITE_NESTED"),
                     "Needs root in a disposable container with the checkout at /src, as run-linux-tests.sh")
class LinuxSuiteTests(unittest.TestCase):
    """run-linux-tests.sh stops before any suite when the probe fixture is required and absent (review A12)."""

    def test_required_missing_fixture_stops_the_linux_suite_before_any_test(self):
        # REPRO_LINUX_SUITE_NESTED keeps a broken script from starting this test again inside itself.
        result = subprocess.run(["/bin/sh", str(RUN_LINUX_TESTS)], capture_output=True, text=True, timeout=600,
                                env=without_fixture_environment(REPRO_REQUIRE_PROBE_FIXTURE="1",
                                                                REPRO_LINUX_SUITE_NESTED="1"))
        self.assertNotEqual(0, result.returncode, result.stderr[-2000:])
        self.assertIn("GateStateTests.test_closed_state", result.stderr)
        self.assertNotIn("Ran ", result.stdout + result.stderr)


if __name__ == "__main__":
    if sys.argv[1:] == ["--fixture-check"]:
        raise SystemExit(fixture_check())
    unittest.main()
