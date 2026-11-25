"""Tests for the MySQL probe's gate state (design 1.2, 2.2) and the accounts it reads.

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

import hashlib
import itertools
import json
import os
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE.parent), str(HERE)]

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
        from test_adapter import example_config, example_identity

        self.root = Path(tempfile.mkdtemp(prefix="repro-probe-test-", dir="/var/lib"))
        self.etc = self.root / "etc"
        self.state = self.root / "state"
        self.etc.mkdir(mode=0o755)
        self.state.mkdir(mode=0o700)
        config = example_config()
        paths = config["paths"]
        paths["state"] = str(self.state)
        paths["identity_config"] = str(self.etc / "identity.json")
        for name in ("receipt", "activity", "activated"):
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
            path.write_text(json.dumps({"version": 1, "phase": phase, "generation": 1, "pending": None}),
                            encoding="utf-8")
            os.chmod(path, 0o600)

    def run(self, phase: str | None = None, *, as_user: int | None = None):
        """Run the probe as the controller does; return (exit code, JSON stdout or None, stderr)."""
        for name, document in (("host.json", self.config), ("identity.json", self.identity)):
            (self.etc / name).write_text(json.dumps(document), encoding="utf-8")
            os.chmod(self.etc / name, 0o644)
        self.journal(phase)
        result = subprocess.run(self.config["commands"]["mysql"], capture_output=True, text=True, timeout=120,
                                env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8"},
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

    def test_receipt_present_refuses_until_the_scan_exists(self):
        # Business activity needs the binary log scan (design 1.2, slice D05); without it no false is printed.
        for name in ("receipt", "activity", "activated"):
            with self.subTest(record=name):
                path = Path(self.host.config["paths"][name])
                path.write_text('{"version": 1}', encoding="utf-8")
                self.addCleanup(path.unlink, missing_ok=True)
                code, output, _ = self.host.run("ready")
                self.assertNotIn(code, (0, 3))
                self.assertFalse(isinstance(output, dict) and "business_activity" in output)
                path.unlink()

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
