"""Tests for the write gate's open and close procedures, gate.py (design v2, sections 2.1, 2.3 and 2.4).

The fixture tests need Linux root and the disposable MySQL 8.4.13 that `mysql/fixture.sh serve` builds
from `mysql/accounts.sql` itself, named by REPRO_MYSQL_PROBE_FIXTURE, exactly as test_mysql_probe.py
does (its docstring has the recipe by hand). `run-probe-fixture-tests.sh --linux-suite` builds the
fixtures and runs `../run-linux-tests.sh`, which runs this file with REPRO_REQUIRE_PROBE_FIXTURE=1: a
fixture test here that would skip fails the run instead. Without the fixture those tests skip and a
WARNING line names them at the end of the file's run.

The fixture tests judge every procedure from the server side, never from gate.py's own report: the
probe (as the controller runs it) for the gate state, root's SHOW GRANTS, the binary log for the
account statements that ran, performance_schema.accounts for gate-account logins, and
performance_schema.processlist for app sessions. Tests that change the gate account itself (its lock,
its grants) restore it from accounts.sql's own GRANT lines, and every test checks that it was restored.

The SQL builders, the root-SQL quoting and the gate account's expected grants (parsed from accounts.sql
here, independently of gate.py) are also tested without MySQL, anywhere.
"""

import itertools
import json
import os
import queue
import re
import shutil
import subprocess
import sys
import threading
import time
import unittest
from pathlib import Path
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE.parent), str(HERE)]

import test_mysql_probe as probe_tests  # noqa: E402  (fixture, probe host and the probe's state catalogue)

GATE = HERE / "gate.py"
CLIENT = probe_tests.CLIENT
FIXTURE = probe_tests.FIXTURE
LINUX_ROOT = probe_tests.LINUX_ROOT
REQUIRE_FIXTURE = probe_tests.REQUIRE_FIXTURE
APP = "repro_app@localhost"
SCHEMA = "`repro\\_dashboard`.*"
TOKEN = "`repro_mysql_control`.`writer_token`"
SCHEMA_PRIVILEGES = ("INSERT", "UPDATE", "DELETE")
OPEN_SET = ("DELETE", "INSERT", "UPDATE", "UPDATE(id)")
# States the probe reads as unrecognised that a root script cannot repair: the account itself must be
# recreated as accounts.sql does, with its password from a root-only file.
NEEDS_ACCOUNT = {"app account missing", "app user row missing, its grants left"}
# One more unrecognised state: an open gate plus a role is not "open" (the probe's own test).
UNRECOGNISED = {
    **probe_tests.GateStateTests.UNRECOGNISED,
    "open gate plus a role": f"{probe_tests.OPEN_GATE}; CREATE ROLE 'repro_test_role';"
                             "GRANT 'repro_test_role' TO 'repro_app'@'localhost'",
}
TABLE = "repro_dashboard.repro_gate_test"  # Created by each fixture test, dropped after it.
# A table for one large uncommitted transaction: its rollback after a KILL takes a moment (about 1 s for
# BULK_ROWS on this fixture), so the killed session stays listed in the processlist meanwhile.
BULK = "repro_dashboard.repro_gate_bulk"
BULK_ROWS = 300000
ACCOUNT_STATEMENT = re.compile(r"^(GRANT|REVOKE) (.+) ON (\S+) (?:TO|FROM) '([a-z_]+)'@'([a-z]+)'$")
GATE_ACCOUNT = "repro_cutover_gate@localhost"
GATE_ACCOUNT_SQL = "'repro_cutover_gate'@'localhost'"
WRONG_PASSWORD = "not-the-gate-password-0123"  # Written into a copy of the gate defaults file only.
# What SHOW GRANTS prints for the gate account on 8.4.13 when accounts.sql made it (read on the fixture).
GATE_SHOW_GRANTS = [
    "GRANT PROCESS ON *.* TO `repro_cutover_gate`@`localhost`",
    "GRANT CONNECTION_ADMIN ON *.* TO `repro_cutover_gate`@`localhost`",
    "GRANT INSERT, UPDATE, DELETE ON `repro\\_dashboard`.* TO `repro_cutover_gate`@`localhost` WITH GRANT OPTION",
    "GRANT UPDATE (`id`) ON `repro_mysql_control`.`writer_token` TO `repro_cutover_gate`@`localhost` WITH GRANT OPTION",
]


def accounts_sql_gate_grants() -> list:
    """accounts.sql's GRANT statements for the gate account, verbatim and without the final `;`."""
    lines = probe_tests.ACCOUNTS_SQL.read_text(encoding="utf-8").splitlines()
    return [line[:-1] for line in lines if f" TO {GATE_ACCOUNT_SQL}" in line and line.startswith("GRANT ")]


def accounts_sql_gate_triples() -> set:
    """The gate account's grants as accounts.sql writes them: (privilege, level, with grant option) triples.

    Parsed here on purpose, not with gate.py's own parser: gate.py's expected set must equal what the
    administrator's file grants, so a change to either side fails StatementTests.
    """
    triples = set()
    for statement in accounts_sql_gate_grants():
        match = re.fullmatch(rf"GRANT ([A-Z_, ()`a-z]+) ON (\S+) TO {GATE_ACCOUNT_SQL}( WITH GRANT OPTION)?", statement)
        assert match, statement
        names = re.findall(r"[A-Z_]+(?: \(`[a-z_]+`\))?", match.group(1))
        assert ", ".join(names) == match.group(1), statement  # Every privilege of the statement was read.
        triples |= {(name, match.group(2), bool(match.group(3))) for name in names}
    return triples


def expected_statements(verb: str, present) -> list:
    """The account statements one per level (design 2.3) for the privileges present, as binlog_statements reads them."""
    statements = []
    schema = [name for name in SCHEMA_PRIVILEGES if name in present]
    if schema:
        statements.append((verb, frozenset(schema), SCHEMA, APP))
    if "UPDATE(id)" in present:
        statements.append((verb, frozenset({"UPDATE (`id`)"}), TOKEN, APP))
    return statements


class Session:
    """One long-lived app session, as a queue worker or an FPM child would hold it: a mysql client fed over a pipe."""

    def __init__(self, fixture, account="repro_app"):
        self.child = subprocess.Popen(
            [CLIENT, f"--defaults-file={fixture.directory / f'{account}.cnf'}", "--no-login-paths",
             "--protocol=SOCKET", f"--socket={fixture.socket}", "--batch", "--raw", "--skip-column-names",
             "--unbuffered", "--skip-reconnect", "--force"],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, bufsize=1)
        self.lines = queue.Queue()
        self.line = 0
        threading.Thread(target=self._pump, daemon=True).start()

    def _pump(self):
        for line in self.child.stdout:
            self.lines.put(line.rstrip("\n"))
        self.lines.put(None)

    def ask(self, sql: str, timeout: float = 10.0) -> list:
        """Run one input line and return its output and error lines (stopping at a marker on the next line)."""
        self.line += 2
        marker = f"repro-gate-test-{self.line}"
        try:
            self.child.stdin.write(f"{sql};\nSELECT '{marker}';\n")
            self.child.stdin.flush()
        except (BrokenPipeError, ValueError):
            return ["<closed>"]
        output, end = [], time.monotonic() + timeout
        while time.monotonic() < end:
            try:
                line = self.lines.get(timeout=0.05)
            except queue.Empty:
                continue
            if line is None or line == marker:
                break
            output.append(line)
            if f" at line {self.line}: " in line:  # The marker itself failed: the connection is gone.
                break
        else:
            raise AssertionError(f"The session did not answer: {output}")
        return output

    def connection_id(self) -> int:
        return int(self.ask("USE repro_dashboard; SELECT CONNECTION_ID()")[0])

    def close(self):
        try:
            self.child.stdin.close()
        except OSError:
            pass
        try:
            self.child.wait(timeout=10)
        except subprocess.TimeoutExpired:
            self.child.kill()
            self.child.wait()
        self.child.stdout.close()


class Readers:
    """App readers that reconnect at once whenever a session ends, overlapping so one is nearly always connected."""

    def __init__(self, fixture, count=3, seconds=0.6):
        self.fixture = fixture
        self.stop = threading.Event()
        self.sessions = 0
        self.lock = threading.Lock()
        self.threads = [threading.Thread(target=self._loop, args=(index * seconds / count, seconds), daemon=True)
                        for index in range(count)]
        for thread in self.threads:
            thread.start()

    def _loop(self, delay, seconds):
        time.sleep(delay)
        while not self.stop.is_set():
            self.fixture.client("repro_app", f"SELECT COUNT(*) FROM {TABLE}; DO SLEEP({seconds})")
            with self.lock:
                self.sessions += 1

    def count(self) -> int:
        with self.lock:
            return self.sessions

    def close(self):
        self.stop.set()
        for thread in self.threads:
            thread.join(timeout=30)


class StatementTests(unittest.TestCase):
    """The account statements gate.py runs, without MySQL (design 2.3)."""

    def setUp(self):
        import gate
        from test_adapter import example_config

        self.gate = gate
        self.config = example_config()

    def test_open_grants_exactly_the_design_open_set(self):
        self.assertEqual(
            ["GRANT INSERT, UPDATE, DELETE ON `repro\\_dashboard`.* TO 'repro_app'@'localhost'",
             "GRANT UPDATE (`id`) ON `repro_mysql_control`.`writer_token` TO 'repro_app'@'localhost'"],
            self.gate.grant_statements(self.config))

    def test_revoke_names_only_the_present_privileges_one_statement_per_level(self):
        cases = {
            ("INSERT",): ["REVOKE INSERT ON `repro\\_dashboard`.* FROM 'repro_app'@'localhost'"],
            ("DELETE", "UPDATE"): ["REVOKE UPDATE, DELETE ON `repro\\_dashboard`.* FROM 'repro_app'@'localhost'"],
            ("UPDATE(id)",): ["REVOKE UPDATE (`id`) ON `repro_mysql_control`.`writer_token` FROM 'repro_app'@'localhost'"],
            OPEN_SET: ["REVOKE INSERT, UPDATE, DELETE ON `repro\\_dashboard`.* FROM 'repro_app'@'localhost'",
                       "REVOKE UPDATE (`id`) ON `repro_mysql_control`.`writer_token` FROM 'repro_app'@'localhost'"],
            (): [],
        }
        for present, statements in cases.items():
            with self.subTest(present=present):
                self.assertEqual(statements, self.gate.revoke_statements(self.config, {APP: list(present)}))

    def test_revoke_refuses_a_privilege_or_account_outside_the_gate(self):
        for present in ({APP: ["SELECT"]}, {APP: ["UPDATE(id)", "ALL"]}, {"repro_maint@localhost": ["INSERT"]}):
            with self.subTest(present=present):
                with self.assertRaises(self.gate.GateRefused):
                    self.gate.revoke_statements(self.config, present)

    def state(self, **rows):
        """A probe state document (mysql_probe.state_statement's shape) with the closed rows plus `rows`."""
        closed = {
            "user": [{"user": "repro_app", "host": "localhost", "account_locked": "N",
                      **{name: "N" for name in self.gate.probe.USER_PRIVILEGES}}],
            "db": [{"user": "repro_app", "host": "localhost", "db": "repro\\_dashboard",
                    **{name: "Y" if name == "Select_priv" else "N" for name in self.gate.probe.DB_PRIVILEGES}}],
            "tables_priv": [{"user": "repro_app", "host": "localhost", "db": "repro_mysql_control",
                             "table": "writer_token", "table_priv": "Select", "column_priv": ""}],
            "columns_priv": None, "global_grants": None, "role_edges": None, "default_roles": None,
            "procs_priv": None, "proxies_priv": None, "sessions": None, "probe_privileges": ["PROCESS"],
            "mandatory_roles": "",
        }
        return {**closed, **rows}

    def test_root_sql_never_carries_a_name_that_needs_quoting_into_a_statement(self):
        hostile = "x'; DROP DATABASE repro_dashboard; --\nGRANT ALL ON *.* TO 'repro_app'@'localhost"
        state = self.state(role_edges=[{"user": "repro_app", "host": "localhost",
                                        "other_user": hostile, "other_host": "%"}])
        sql = self.gate.root_sql(self.config, state)
        statements = [line for line in sql.splitlines() if line.strip() and not line.startswith("--")]
        self.assertTrue(statements, sql)
        for line in statements:
            self.assertNotIn("DROP", line)
        self.assertNotIn("GRANT ALL", "\n".join(statements))
        self.assertIn("by hand", sql)

    def test_root_sql_ends_with_the_closed_grants_of_accounts_sql(self):
        sql = self.gate.root_sql(self.config, self.state())
        statements = [line for line in sql.splitlines() if line.strip() and not line.startswith("--")]
        self.assertEqual(["REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'repro_app'@'localhost';",
                          "GRANT SELECT ON `repro\\_dashboard`.* TO 'repro_app'@'localhost';",
                          "GRANT SELECT ON `repro_mysql_control`.`writer_token` TO 'repro_app'@'localhost';"],
                         statements)

    # The gate account's self-check (review B2-1).

    def test_gate_account_grants_are_exactly_those_of_accounts_sql(self):
        # Three statements in accounts.sql today; gate.py's set must follow any change to them.
        self.assertEqual(3, len(accounts_sql_gate_grants()))
        self.assertIn(f"CREATE USER {GATE_ACCOUNT_SQL} IDENTIFIED BY :gate;",
                      probe_tests.ACCOUNTS_SQL.read_text(encoding="utf-8"))
        self.assertEqual(GATE_ACCOUNT, self.gate.GATE_ACCOUNT)
        self.assertEqual(accounts_sql_gate_triples(), set(self.gate.gate_account_grants(self.config)))

    def test_show_grants_of_the_accounts_sql_gate_account_has_no_problem(self):
        self.assertEqual([], self.gate.grant_problems(self.config, GATE_SHOW_GRANTS))
        # The order of the lines does not matter, and USAGE (no privilege) is no grant.
        usage = "GRANT USAGE ON *.* TO `repro_cutover_gate`@`localhost`"
        self.assertEqual([], self.gate.grant_problems(self.config, [usage, *reversed(GATE_SHOW_GRANTS)]))

    def test_show_grants_that_differ_from_accounts_sql_name_the_difference(self):
        process, connection, schema, token = GATE_SHOW_GRANTS
        other = "`repro_cutover_gate`@`localhost`"
        cases = {
            "missing CONNECTION_ADMIN ON *.*": [process, schema, token],
            "missing PROCESS ON *.*": [connection, schema, token],
            "missing UPDATE (`id`) ON `repro_mysql_control`.`writer_token` WITH GRANT OPTION": [
                process, connection, schema],
            "INSERT ON `repro\\_dashboard`.* lacks WITH GRANT OPTION": [
                process, connection, schema.replace(" WITH GRANT OPTION", ""), token],
            "UPDATE (`id`) ON `repro_mysql_control`.`writer_token` lacks WITH GRANT OPTION": [
                process, connection, schema, token.replace(" WITH GRANT OPTION", "")],
            "unexpected SELECT ON `mysql`.`db`": [*GATE_SHOW_GRANTS, f"GRANT SELECT ON `mysql`.`db` TO {other}"],
            "unexpected SUPER ON *.*": [f"GRANT PROCESS, SUPER ON *.* TO {other}", connection, schema, token],
            "unexpected PROCESS ON *.* WITH GRANT OPTION": [
                f"{process} WITH GRANT OPTION", connection, schema, token],
            "unexpected grant line: GRANT `repro_test_role`@`%` TO": [
                *GATE_SHOW_GRANTS, f"GRANT `repro_test_role`@`%` TO {other}"],
            "unexpected grant line: GRANT SELECT ON `repro\\_dashboard`.* TO `repro_app`@`localhost`": [
                *GATE_SHOW_GRANTS, "GRANT SELECT ON `repro\\_dashboard`.* TO `repro_app`@`localhost`"],
            "unexpected grant line: REVOKE": [
                *GATE_SHOW_GRANTS, f"REVOKE INSERT ON `repro_dashboard`.`x` FROM {other}"],
        }
        for expected, lines in cases.items():
            with self.subTest(expected=expected):
                problems = self.gate.grant_problems(self.config, lines)
                self.assertTrue(any(problem.startswith(expected) for problem in problems), problems)


@unittest.skipUnless(sys.platform == "linux" and hasattr(os, "geteuid"), "The gate CLI runs on the Linux host")
class CommandLineTests(unittest.TestCase):
    def test_cli_runs_only_as_root(self):
        user = 65534 if LINUX_ROOT else None
        result = subprocess.run([sys.executable, "-I", "-S", str(GATE), "--config", "/nonexistent/host.json",
                                 "check"], capture_output=True, text=True, timeout=60, user=user)
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn("runs as root", result.stderr)


@unittest.skipUnless(LINUX_ROOT, "Needs Linux root: the probe runs as root")
class ClassifiedRowsTests(unittest.TestCase):
    """The root SQL for an unrecognised state comes from the rows the probe classified (mysql_probe.read_gate):
    one read as the probe account, never a second one, and nothing from another account's view (D05, D06).

    Against D05's root-owned stand-in for the 8.4.13 client (test_mysql_probe.FakeServer), which logs every
    statement it is given; no MySQL needed."""

    ROLE = {"user": "repro_app", "host": "localhost", "other_user": "repro_test_role", "other_host": "%"}

    def setUp(self):
        import gate

        self.gate = gate
        self.fake = probe_tests.FakeServer()
        self.addCleanup(shutil.rmtree, self.fake.root)
        self.host = probe_tests.ProbeHost(self.fake)
        self.addCleanup(shutil.rmtree, self.host.root)
        self.host.config["mysql"]["client"] = str(self.fake.client)
        self.fake.state = {**probe_tests.gate_state(), "role_edges": [self.ROLE]}

    def refused(self) -> "gate.GateRefused":
        self.fake.save()
        with self.assertRaises(self.gate.GateRefused) as caught:
            self.gate.read(self.host.config)
        self.assertNotIsInstance(caught.exception, self.gate.GateUnreachable)
        return caught.exception

    def test_root_sql_comes_from_the_one_classified_read(self):
        refusal = self.refused()
        self.assertIn("REVOKE 'repro_test_role'@'%' FROM 'repro_app'@'localhost';", refusal.root_sql or "")
        self.assertIn(refusal.root_sql, str(refusal))
        self.assertEqual("unrecognised", refusal.reading["gate"])
        self.assertEqual(1, len(self.fake.calls()), self.fake.calls())

    def test_rows_read_as_another_account_give_no_root_sql(self):
        # A defaults file holding another account (root could read anything): the probe reads unrecognised,
        # and nothing is derived from that view.
        self.fake.server = {**self.fake.server, "current_user": "root@localhost"}
        refusal = self.refused()
        self.assertIsNone(refusal.root_sql, str(refusal))
        self.assertIn("CURRENT_USER", str(refusal))
        self.assertIn("not read as the probe account", str(refusal))
        self.assertEqual(1, len(self.fake.calls()), self.fake.calls())

    def test_rows_gate_sql_cannot_quote_go_to_the_administrator_by_hand(self):
        edge = {**self.ROLE, "other_user": "repro'; DROP USER x; --"}
        self.fake.state = {**probe_tests.gate_state(), "role_edges": [edge]}
        refusal = self.refused()
        self.assertIn("remove it by hand", refusal.root_sql or "")
        self.assertNotIn("DROP USER x;\n", refusal.root_sql)
        self.assertEqual(1, len(self.fake.calls()), self.fake.calls())


@unittest.skipUnless(LINUX_ROOT and FIXTURE, "Needs Linux root and the MySQL fixture (REPRO_MYSQL_PROBE_FIXTURE)")
class GateProcedureTests(unittest.TestCase):
    """gate.py against the fixture built from accounts.sql (design 2.3 and 2.4)."""

    @classmethod
    def setUpClass(cls):
        cls.fixture = probe_tests.Fixture(FIXTURE)
        cls.fixture.restore()
        cls.baseline = cls.fixture.snapshot()
        assert cls.baseline["grants"] == sorted(probe_tests.CLOSED_GRANTS), cls.baseline
        cls.restore_gate_account()
        cls.gate_baseline = cls.gate_snapshot()
        assert cls.gate_baseline == (sorted(GATE_SHOW_GRANTS), "N"), cls.gate_baseline

    @classmethod
    def gate_snapshot(cls) -> tuple:
        """The gate account as root reads it: its grants and its lock."""
        return (sorted(cls.fixture.sql(f"SHOW GRANTS FOR {GATE_ACCOUNT_SQL}").splitlines()),
                cls.fixture.sql("SELECT account_locked FROM mysql.user WHERE User = 'repro_cutover_gate'").strip())

    @classmethod
    def restore_gate_account(cls) -> None:
        """Give the gate account back exactly what accounts.sql gives it (its password is never changed)."""
        cls.fixture.sql(";".join([f"ALTER USER {GATE_ACCOUNT_SQL} ACCOUNT UNLOCK",
                                  f"REVOKE ALL PRIVILEGES, GRANT OPTION FROM {GATE_ACCOUNT_SQL}",
                                  *accounts_sql_gate_grants()]))

    def setUp(self):
        import gate
        from adapter import guard, load_config

        self.gate = gate
        self.host = probe_tests.ProbeHost(self.fixture)
        self.addCleanup(shutil.rmtree, self.host.root)
        self.addCleanup(self.assert_restored)
        self.host.config["mysql"]["gate_defaults"] = str(self.host.etc / "mysql-gate.cnf")
        # The one place the gate opens: seal_mysql in a cutover run, after the runner wrote writes_possible.
        self.host.config["mysql"]["mode"] = "cutover"
        self.host.journal("writes_possible")
        self.gate_defaults(self.fixture.directory / "repro_cutover_gate.cnf")
        self.write_host_files()
        # The config as the controller has it: through load_config, from root-protected files.
        self.config = load_config(guard.read_protected(self.host.etc / "host.json"), read=guard.read_protected)
        self.fixture.sql(f"DROP TABLE IF EXISTS {TABLE}; CREATE TABLE {TABLE} (id INT PRIMARY KEY)")
        self.addCleanup(self.fixture.sql, f"DROP TABLE IF EXISTS {TABLE}")

    def assert_restored(self):
        self.fixture.restore()
        self.assertEqual(self.baseline, self.fixture.snapshot())
        if self.gate_snapshot() != self.gate_baseline:
            self.restore_gate_account()
            self.assertEqual(self.gate_baseline, self.gate_snapshot())

    def gate_defaults(self, source: Path, mode: int = 0o600) -> None:
        target = Path(self.host.config["mysql"]["gate_defaults"])
        shutil.copyfile(source, target)
        os.chmod(target, mode)

    def cli_check(self) -> subprocess.CompletedProcess:
        """`gate.py check` as the controller runs it: root, /usr/bin/python3 -I -S, the installed host.json."""
        return subprocess.run(["/usr/bin/python3", "-I", "-S", str(GATE), "--config", str(self.host.etc / "host.json"),
                               "check"], capture_output=True, text=True, timeout=120,
                              env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8"})

    def bulk_session(self) -> tuple:
        """An app session (database selected) inside a large uncommitted transaction; needs INSERT.

        A KILL ends it only after its rollback, so it stays in the processlist for a moment after the KILL.
        """
        self.fixture.sql(f"DROP TABLE IF EXISTS {BULK};"
                         f"CREATE TABLE {BULK} (id INT PRIMARY KEY, pad CHAR(200) NOT NULL)")
        self.addCleanup(self.fixture.sql, f"DROP TABLE IF EXISTS {BULK}")  # After the session ends (cleanups run LIFO).
        session = self.session()
        identity = session.connection_id()
        answer = session.ask(f"SET SESSION cte_max_recursion_depth = {BULK_ROWS}; BEGIN; INSERT INTO {BULK} (id, pad) "
                             "WITH RECURSIVE seq (n) AS "
                             f"(SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < {BULK_ROWS}) "
                             "SELECT n, REPEAT('x', 200) FROM seq", timeout=120)
        self.assertEqual([], answer)
        return session, identity

    def wait_gone(self, identity: int) -> None:
        deadline = time.monotonic() + 60
        while identity in self.app_sessions():
            self.assertLess(time.monotonic(), deadline, f"session {identity} did not end")
            time.sleep(0.05)

    def bulk_rows(self) -> int:
        return int(self.fixture.sql(f"SELECT COUNT(*) FROM {BULK}"))

    def secrets(self) -> list:
        """The fixture's passwords: none may appear in any output or message."""
        return [path.read_text(encoding="ascii").strip() for path in (self.fixture.directory / "passwords").iterdir()]

    def write_host_files(self) -> None:
        for name, document in (("host.json", self.host.config), ("identity.json", self.host.identity)):
            (self.host.etc / name).write_text(json.dumps(document), encoding="utf-8")
            os.chmod(self.host.etc / name, 0o644)

    # Server-side observations.

    def probe_reads(self, gate_state: str, phase: str = "writes_possible") -> dict:
        """The probe as the controller runs it (a separate root process) must read this state."""
        code, output, stderr = self.host.run(phase)
        self.assertIsInstance(output, dict, (code, output, stderr))
        self.assertEqual(gate_state, output.get("gate"), (code, output, stderr))
        return output

    def grants(self) -> list:
        return sorted(self.fixture.sql("SHOW GRANTS FOR 'repro_app'@'localhost'").splitlines())

    def gate_logins(self) -> int:
        return int(self.fixture.sql("SELECT IFNULL(SUM(TOTAL_CONNECTIONS), 0) FROM performance_schema.accounts"
                                    " WHERE USER = 'repro_cutover_gate'"))

    def gtid_executed(self) -> str:
        return self.fixture.sql("SELECT @@GLOBAL.gtid_executed").strip()

    def coordinate(self) -> tuple:
        name, position = self.fixture.sql("SHOW BINARY LOG STATUS").split("\t")[:2]
        return name, int(position)

    def binlog_statements(self, coordinate: tuple) -> list:
        """The GRANT and REVOKE statements written to the binary log since `coordinate`, parsed."""
        name, position = coordinate
        found = []
        for line in self.fixture.sql(f"SHOW BINLOG EVENTS IN '{name}' FROM {position}").splitlines():
            fields = line.split("\t")
            if fields[2] != "Query":
                continue
            text = re.sub(r" /\* xid=[0-9]+ \*/$", "", fields[5])
            match = ACCOUNT_STATEMENT.match(text)
            if match:
                verb, privileges, target, user, hostname = match.groups()
                found.append((verb, frozenset(privileges.split(", ")), target, f"{user}@{hostname}"))
            elif re.match(r"(?i)(GRANT|REVOKE|ALTER USER|CREATE USER|DROP USER|SET)\b", text):
                found.append(("other", text))
        return found

    def app_sessions(self) -> set:
        return {int(value) for value in
                self.fixture.sql("SELECT ID FROM performance_schema.processlist WHERE USER = 'repro_app'").split()}

    def rows(self) -> list:
        return [int(value) for value in self.fixture.sql(f"SELECT id FROM {TABLE} ORDER BY id").split()]

    def open_by_hand(self, privileges=OPEN_SET) -> None:
        self.fixture.sql(";".join(probe_tests.OPEN_SQL[name] for name in privileges), account="repro_cutover_gate")

    def session(self) -> Session:
        session = Session(self.fixture)
        self.addCleanup(session.close)
        return session

    # The design's tests (build plan D06).

    def test_gate_account_cannot_read_grant_tables(self):
        for table in ("user", "db", "tables_priv", "columns_priv", "procs_priv", "proxies_priv", "role_edges",
                      "default_roles", "global_grants"):
            with self.subTest(table=table):
                result = self.fixture.client("repro_cutover_gate", f"SELECT COUNT(*) FROM mysql.{table}")
                self.assertNotEqual(0, result.returncode)
                self.assertRegex(result.stderr, r"^ERROR 1142 \(42000\)")
                self.assertEqual("", result.stdout)
        result = self.fixture.client("repro_cutover_gate", "SHOW GRANTS FOR 'repro_app'@'localhost'")
        self.assertNotEqual(0, result.returncode)
        # So gate.py reads the state as the probe only: with the gate account in the probe's defaults file
        # the procedure cannot read the gate, refuses, and changes nothing (an open gate stays open).
        self.open_by_hand()
        self.host.defaults(self.fixture.directory / "repro_cutover_gate.cnf")
        logins = self.gate_logins()
        for procedure in (self.gate.close_gate, self.gate.open_gate):
            with self.subTest(procedure=procedure.__name__):
                with self.assertRaises(self.gate.GateRefused) as caught:
                    procedure(self.config)
                self.assertNotIsInstance(caught.exception, self.gate.GateUnreachable)
                self.assertIn("1142", str(caught.exception))
        self.assertEqual(logins + 2, self.gate_logins())  # Only the two refused probe reads logged in.
        self.host.defaults(self.fixture.directory / "repro_cutover_probe.cnf")
        self.probe_reads("open")

    def test_close_of_closed_gate_runs_no_sql(self):
        logins, gtid = self.gate_logins(), self.gtid_executed()
        result = self.gate.close_gate(self.config)
        self.assertEqual({"before": "closed", "revoked": {}, "killed": []}, result)
        self.assertEqual(logins, self.gate_logins(), "close of a closed gate logged in as the gate account")
        self.assertEqual(gtid, self.gtid_executed())
        self.probe_reads("closed", "planned")

    def test_close_open_gate(self):
        # In every journal phase: the procedure reads in measured mode, so an open gate found outside the
        # frozen window (where the probe itself only exits 4) is still closed, never left open.
        for journal in (None, "planned", "sqlite_authoritative", "frozen", "writes_possible", "recovery_required"):
            with self.subTest(journal=journal):
                self.open_by_hand()
                self.probe_reads("open")
                self.host.journal(journal)
                coordinate = self.coordinate()
                result = self.gate.close_gate(self.config)
                self.assertEqual({"before": "open", "revoked": {APP: list(OPEN_SET)}, "killed": []}, result)
                self.assertEqual(expected_statements("REVOKE", OPEN_SET), self.binlog_statements(coordinate))
                self.assertEqual(sorted(probe_tests.CLOSED_GRANTS), self.grants())
                for phase in ("writes_possible", "planned"):
                    self.probe_reads("closed", phase)

    def test_reading_as_another_account_gives_no_root_sql(self):
        # The probe flags a defaults file holding another account (here root, which could read anything):
        # nothing is derived from that view, and nothing changes.
        self.fixture.sql(UNRECOGNISED["role granted to the app"])
        self.host.defaults(self.fixture.directory / "root.cnf")
        logins = self.gate_logins()
        with self.assertRaises(self.gate.GateRefused) as caught:
            self.gate.close_gate(self.config)
        self.assertIsNone(caught.exception.root_sql, str(caught.exception))
        self.assertIn("CURRENT_USER", str(caught.exception))
        self.assertEqual(logins, self.gate_logins())

    def test_close_partial_gate_by_present_subset(self):
        full_set = ["REVOKE INSERT, UPDATE, DELETE ON `repro\\_dashboard`.* FROM 'repro_app'@'localhost'",
                    "REVOKE UPDATE (`id`) ON `repro_mysql_control`.`writer_token` FROM 'repro_app'@'localhost'"]
        for size in range(1, len(OPEN_SET)):
            for present in itertools.combinations(OPEN_SET, size):
                with self.subTest(present=present):
                    try:
                        self.open_by_hand(present)
                        self.assertEqual({APP: list(present)},
                                         self.probe_reads("partial")["open_privileges"])
                        if set(SCHEMA_PRIVILEGES) - set(present):
                            # E6 G3: the full set fails with 1141 and revokes nothing.
                            failed = self.fixture.client("repro_cutover_gate", ";".join(full_set))
                            self.assertRegex(failed.stderr, r"^ERROR 1141 \(42000\)")
                            self.assertEqual({APP: list(present)},
                                             self.probe_reads("partial")["open_privileges"])
                        coordinate = self.coordinate()
                        result = self.gate.close_gate(self.config)
                        self.assertEqual({"before": "partial", "revoked": {APP: list(present)}, "killed": []},
                                         result)
                        self.assertEqual(expected_statements("REVOKE", present),
                                         self.binlog_statements(coordinate))
                        self.assertEqual(sorted(probe_tests.CLOSED_GRANTS), self.grants())
                        self.probe_reads("closed", "planned")
                    finally:
                        self.fixture.restore()

    def test_old_session_cannot_write_after_close(self):
        self.open_by_hand()
        old = self.session()
        identity = old.connection_id()  # The database is selected, as Laravel connects.
        self.assertEqual([], old.ask(f"INSERT INTO {TABLE} VALUES (1)"))
        result = self.gate.close_gate(self.config)
        answer = old.ask(f"INSERT INTO {TABLE} VALUES (2)")
        self.assertEqual([1], self.rows(), "the old session wrote after the close")
        self.assertTrue(any(line.startswith("ERROR ") for line in answer), answer)
        self.assertEqual("open", result["before"])
        self.assertIn(identity, result["killed"])
        self.assertNotIn(identity, self.app_sessions())
        self.probe_reads("closed", "planned")

    def test_close_kills_a_session_left_by_an_interrupted_close(self):
        # A close that revoked and then crashed before its KILL: the gate reads closed, an old session can still write.
        self.open_by_hand()
        old = self.session()
        identity = old.connection_id()
        self.fixture.sql(";".join(statement for statement in (
            "REVOKE INSERT, UPDATE, DELETE ON `repro\\_dashboard`.* FROM 'repro_app'@'localhost'",
            "REVOKE UPDATE (`id`) ON `repro_mysql_control`.`writer_token` FROM 'repro_app'@'localhost'")),
            account="repro_cutover_gate")
        self.probe_reads("closed")
        self.assertEqual([], old.ask(f"INSERT INTO {TABLE} VALUES (1)"))  # E1 T3b: the revoke did not reach it.
        result = self.gate.close_gate(self.config)
        self.assertEqual({"before": "closed", "revoked": {}, "killed": [identity]}, result)
        self.assertTrue(any(line.startswith("ERROR ") for line in old.ask(f"INSERT INTO {TABLE} VALUES (2)")))
        self.assertEqual([1], self.rows())

    def test_reconnecting_reader_does_not_block_close(self):
        self.open_by_hand()
        readers = Readers(self.fixture)
        self.addCleanup(readers.close)
        deadline = time.monotonic() + 30
        while not self.app_sessions():
            self.assertLess(time.monotonic(), deadline, "no reader connected")
            time.sleep(0.05)
        started = time.monotonic()
        result = self.gate.close_gate(self.config)
        self.assertLess(time.monotonic() - started, self.gate.KILL_WAIT_SECONDS)
        self.assertEqual("open", result["before"])
        self.assertTrue(result["killed"], result)
        served = readers.count()
        deadline = time.monotonic() + 30
        while readers.count() <= served + 3:  # Readers keep reconnecting after the close.
            self.assertLess(time.monotonic(), deadline, "readers stopped after the close")
            time.sleep(0.05)
        self.probe_reads("closed")
        readers.close()

    def test_unrecognised_state_refuses_and_prints_root_sql(self):
        for name, statements in UNRECOGNISED.items():
            with self.subTest(state=name):
                try:
                    self.fixture.sql(statements)
                    self.host.journal("writes_possible")
                    before, logins, gtid = self.grants_or_none(), self.gate_logins(), self.gtid_executed()
                    for procedure in (self.gate.close_gate, self.gate.open_gate):
                        with self.assertRaises(self.gate.GateRefused) as caught:
                            procedure(self.config)
                        refusal = caught.exception
                        self.assertNotIsInstance(refusal, self.gate.GateUnreachable)
                        self.assertTrue(refusal.root_sql, str(refusal))
                        self.assertIn(refusal.root_sql, str(refusal))
                    # Refused without any change: no gate login, nothing written, the grants as they were.
                    self.assertEqual((logins, gtid, before), (self.gate_logins(), self.gtid_executed(),
                                                              self.grants_or_none()))
                    sql = refusal.root_sql
                    executable = [line for line in sql.splitlines() if line.strip() and not line.startswith("--")]
                    if name in NEEDS_ACCOUNT:
                        self.assertIn("accounts.sql", sql)
                        self.assertEqual([], executable, sql)
                        continue
                    ran = self.fixture.client("root", sql)
                    self.assertEqual(0, ran.returncode, (sql, ran.stderr))
                    self.assertEqual(sorted(probe_tests.CLOSED_GRANTS), self.grants())
                    self.probe_reads("closed", "planned")
                    self.assertEqual("closed", self.gate.close_gate(self.config)["before"])
                finally:
                    self.fixture.restore()

    def grants_or_none(self):
        result = self.fixture.client("root", "SHOW GRANTS FOR 'repro_app'@'localhost'")
        return sorted(result.stdout.splitlines()) if result.returncode == 0 else None

    def test_check_prints_root_sql_for_an_unrecognised_state(self):
        self.fixture.sql(UNRECOGNISED["role granted to the app"])
        result = subprocess.run(["/usr/bin/python3", "-I", "-S", str(GATE), "--config",
                                 str(self.host.etc / "host.json"), "check"],
                                capture_output=True, text=True, timeout=120,
                                env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8"})
        self.assertEqual(4, result.returncode, result.stderr)
        output = json.loads(result.stdout)
        self.assertEqual("unrecognised", output["gate"])
        self.assertIn("REVOKE 'repro_test_role'@'%' FROM 'repro_app'@'localhost';", output["root_sql"])
        self.assertIn(output["root_sql"], result.stderr)
        self.assertEqual(4, self.host.run("planned")[0])  # Read-only: still unrecognised.

    def test_open_from_closed_kills_stale_sessions(self):
        stale = self.session()
        identity = stale.connection_id()
        refused = stale.ask(f"INSERT INTO {TABLE} VALUES (1)")
        self.assertRegex(refused[0], r"^ERROR 1142 ")  # Closed, and it keeps the closed privileges (E1b).
        coordinate = self.coordinate()
        result = self.gate.open_gate(self.config)
        self.assertEqual({"before": "closed", "granted": True, "killed": [identity], "kill_failed": [],
                          "stragglers": []}, result)
        self.assertEqual(expected_statements("GRANT", OPEN_SET), self.binlog_statements(coordinate))
        output = self.probe_reads("open")
        self.assertTrue(output["writable"])
        self.assertNotIn(identity, self.app_sessions())
        self.assertTrue(any(line.startswith("ERROR 2013") or line.startswith("ERROR 2006")
                            for line in stale.ask(f"INSERT INTO {TABLE} VALUES (2)")))
        self.fixture.sql(f"INSERT INTO {TABLE} VALUES (3)", account="repro_app")  # A new session writes.
        self.assertEqual([3], self.rows())

    # Further cases.

    def test_open_of_open_gate_grants_nothing(self):
        self.open_by_hand()
        coordinate = self.coordinate()
        result = self.gate.open_gate(self.config)
        self.assertEqual({"before": "open", "granted": False, "killed": [], "kill_failed": [], "stragglers": []},
                         result)
        self.assertEqual([], self.binlog_statements(coordinate))
        self.probe_reads("open")

    def test_open_refuses_outside_writes_possible(self):
        # seal_mysql runs after the runner committed writes_possible; in any other phase (no journal, the
        # SQLite phases, the held phases before promote, after it) opening would be wrong, so it never logs in.
        logins = self.gate_logins()
        for journal in (None, "planned", "holding", "frozen", "ready", "mysql_authoritative", "recovery_required",
                        "sqlite_authoritative"):
            with self.subTest(journal=journal):
                self.host.journal(journal)
                with self.assertRaises(self.gate.GateRefused) as caught:
                    self.gate.open_gate(self.config)
                self.assertIn("writes_possible", str(caught.exception))
        self.assertEqual(logins, self.gate_logins())
        self.probe_reads("closed", "planned")

    def test_open_refuses_in_drill_mode(self):
        # Design 3.4: the drill never opens the gate; seal_mysql refuses and so does the gate itself.
        config = json.loads(json.dumps(self.config))
        config["mysql"]["mode"] = "drill"
        logins = self.gate_logins()
        with self.assertRaises(self.gate.GateRefused) as caught:
            self.gate.open_gate(config)
        self.assertIn("drill", str(caught.exception))
        self.assertEqual(logins, self.gate_logins())
        self.probe_reads("closed", "planned")

    def test_open_refuses_a_partial_gate(self):
        self.open_by_hand(("INSERT",))
        logins = self.gate_logins()
        with self.assertRaises(self.gate.GateRefused) as caught:
            self.gate.open_gate(self.config)
        self.assertIn("partial", str(caught.exception))
        self.assertEqual(logins, self.gate_logins())
        self.assertEqual({APP: ["INSERT"]}, self.probe_reads("partial")["open_privileges"])

    def test_unreachable_server_raises_gate_unreachable_without_a_change(self):
        self.open_by_hand()
        config = json.loads(json.dumps(self.config))
        config["mysql"]["socket"] = str(self.host.root / "no-server" / "mysql.sock")
        logins = self.gate_logins()
        for procedure in (self.gate.close_gate, self.gate.open_gate):
            with self.subTest(procedure=procedure.__name__):
                with self.assertRaises(self.gate.GateUnreachable):
                    procedure(config)
        self.assertEqual(logins, self.gate_logins())
        self.probe_reads("open")

    def test_failed_kill_refuses(self):
        # A gate defaults file for an account without CONNECTION_ADMIN cannot end app sessions: error 1095.
        self.gate_defaults(self.fixture.directory / "repro_monitor.cnf")
        old = self.session()
        identity = old.connection_id()
        with self.assertRaises(self.gate.GateRefused) as caught:
            self.gate.close_gate(self.config)
        self.assertIn("1095", str(caught.exception))
        self.assertIn(identity, self.app_sessions())

    def test_close_refuses_when_the_kill_login_fails(self):
        # An error that names no KILL (here the login, 1045) leaves every KILL unaccounted for: all failed.
        text = (self.fixture.directory / "repro_cutover_gate.cnf").read_text(encoding="ascii")
        wrong = self.host.root / "wrong-password.cnf"
        wrong.write_text(re.sub(r"(?m)^password=.*$", f"password={WRONG_PASSWORD}", text), encoding="ascii")
        self.gate_defaults(wrong)
        old = self.session()
        identity = old.connection_id()
        started = time.monotonic()
        with self.assertRaises(self.gate.GateRefused) as caught:
            self.gate.close_gate(self.config)
        self.assertIn("KILL of the app sessions failed: ERROR 1045", str(caught.exception))
        self.assertLess(time.monotonic() - started, self.gate.KILL_WAIT_SECONDS)  # Refused, not waited for.
        self.assertNotIn(WRONG_PASSWORD, str(caught.exception))
        self.assertIn(identity, self.app_sessions())

    def test_close_kills_every_listed_session_after_one_has_already_ended(self):
        # A listed session may end by itself before its KILL (1094, fine). The KILLs after it must still run:
        # with -e the client stops at the first error even with --force, so they go on stdin, one per line.
        self.open_by_hand()
        sessions = {}
        for _ in range(2):
            session = self.session()
            sessions[session.connection_id()] = session
        # The KILLs run in ID order; the clients connect concurrently, so the lower ID is the one that leaves.
        gone, live = sorted(sessions)
        readings, real_read = [], self.gate.read

        def read(config):
            reading = real_read(config)
            readings.append(reading)
            if len(readings) == 2:  # The reading after the revoke lists both; then the first one leaves.
                sessions[gone].close()
                self.wait_gone(gone)
            return reading

        with mock.patch.object(self.gate, "read", read):
            result = self.gate.close_gate(self.config, wait_seconds=3)
        self.assertEqual((gone, live), readings[1].sessions)
        self.assertEqual({"before": "open", "revoked": {APP: list(OPEN_SET)}, "killed": [gone, live]}, result)
        self.assertNotIn(live, self.app_sessions())
        self.assertTrue(any(line.startswith("ERROR ")
                            for line in sessions[live].ask(f"INSERT INTO {TABLE} VALUES (1)")))
        self.assertEqual([], self.rows())

    # Review B2-2 to B2-4: the close of a partial gate, the wait after the KILL, check's exit for partial.

    def test_close_partial_gate_kills_an_old_session(self):
        # A partial reading lists no sessions: only the reading after the revoke finds this one.
        self.open_by_hand(("INSERT",))
        old = self.session()
        identity = old.connection_id()  # The database is selected, as Laravel connects.
        self.assertEqual([], old.ask(f"INSERT INTO {TABLE} VALUES (1)"))
        result = self.gate.close_gate(self.config)
        self.assertEqual({"before": "partial", "revoked": {APP: ["INSERT"]}, "killed": [identity]}, result)
        answer = old.ask(f"INSERT INTO {TABLE} VALUES (2)")
        self.assertTrue(any(line.startswith("ERROR ") for line in answer), answer)
        self.assertEqual([1], self.rows(), "the old session wrote after the close of a partial gate")
        self.assertNotIn(identity, self.app_sessions())
        self.probe_reads("closed", "planned")

    def test_close_returns_only_once_the_killed_sessions_are_gone(self):
        # Design 2.3 step 3: wait until none of the KILLed IDs remains. This one rolls back a large
        # transaction after its KILL, so it stays listed for a moment.
        self.open_by_hand()
        _, identity = self.bulk_session()
        readings, real_read = [], self.gate.read

        def read(config):
            reading = real_read(config)
            readings.append(reading)
            return reading

        with mock.patch.object(self.gate, "read", read):
            result = self.gate.close_gate(self.config)
        listed = self.app_sessions()  # At once, the moment the close returned.
        self.assertIn(identity, result["killed"])
        self.assertNotIn(identity, listed, "close_gate returned while a session it killed was still connected")
        # The close did have to wait (it saw the session after its KILL), or this test proves nothing.
        self.assertTrue(any(identity in (reading.sessions or ()) for reading in readings[2:]), readings)
        self.assertEqual(0, self.bulk_rows())
        self.probe_reads("closed", "planned")

    def test_check_exits_4_for_a_partial_gate(self):
        self.open_by_hand(("INSERT",))
        result = self.cli_check()
        self.assertEqual(4, result.returncode, result.stderr)
        output = json.loads(result.stdout)
        self.assertEqual(("partial", {APP: ["INSERT"]}), (output["gate"], output["open_privileges"]))
        self.assertIn("gate-normalise", result.stderr)
        self.assertEqual({APP: ["INSERT"]}, self.probe_reads("partial")["open_privileges"])  # Read-only.

    # Review B2-5: the open records stale sessions it could not end; the close stays strict.

    def test_open_records_a_failed_kill_and_still_opens(self):
        stale = self.session()
        identity = stale.connection_id()
        self.fixture.sql(f"REVOKE CONNECTION_ADMIN ON *.* FROM {GATE_ACCOUNT_SQL}")  # Its KILL then fails with 1095.
        result = self.gate.open_gate(self.config)
        self.assertEqual({"before": "closed", "granted": True, "killed": [], "kill_failed": [identity],
                          "stragglers": []}, result)
        self.assertTrue(self.probe_reads("open")["writable"])
        self.assertIn(identity, self.app_sessions())
        # It keeps the closed privileges it had before the GRANT (E1b), so it still cannot write.
        self.assertRegex(stale.ask(f"INSERT INTO {TABLE} VALUES (1)")[0], r"^ERROR 1142 ")
        self.fixture.sql(f"INSERT INTO {TABLE} VALUES (2)", account="repro_app")  # A new session writes.
        self.assertEqual([2], self.rows())

    def test_open_records_a_straggler_and_still_opens(self):
        self.open_by_hand()
        _, identity = self.bulk_session()
        # Closed again without the kill, as by an interrupted close: the transaction is still open.
        self.fixture.sql("REVOKE INSERT, UPDATE, DELETE ON `repro\\_dashboard`.* FROM 'repro_app'@'localhost';"
                         "REVOKE UPDATE (`id`) ON `repro_mysql_control`.`writer_token` FROM 'repro_app'@'localhost'",
                         account="repro_cutover_gate")
        self.probe_reads("closed")
        result = self.gate.open_gate(self.config, wait_seconds=0)
        self.assertEqual({"before": "closed", "granted": True, "killed": [], "kill_failed": [],
                          "stragglers": [identity]}, result)
        self.assertTrue(self.probe_reads("open")["writable"])
        self.wait_gone(identity)  # Its KILL did end it, after the rollback.
        self.assertEqual(0, self.bulk_rows())

    def test_close_refuses_when_a_killed_session_outlasts_the_wait(self):
        self.open_by_hand()
        _, identity = self.bulk_session()
        with self.assertRaises(self.gate.GateRefused) as caught:
            self.gate.close_gate(self.config, wait_seconds=0)
        self.assertIn(f"App sessions [{identity}] were still connected", str(caught.exception))
        self.probe_reads("closed", "planned")  # The revoke stands; the next close only ends sessions.
        self.wait_gone(identity)
        self.assertEqual(0, self.bulk_rows())
        self.assertEqual({"before": "closed", "revoked": {}, "killed": []}, self.gate.close_gate(self.config))

    # Review B2-1: check() logs in as the gate account, read-only, so a promote that cannot open the gate
    # is refused before Approval 2 rather than at seal_mysql's GRANT, after writes_possible.

    def test_check_logs_in_as_the_gate_account_and_changes_nothing(self):
        logins, gtid, coordinate = self.gate_logins(), self.gtid_executed(), self.coordinate()
        output, code, message = self.gate.check(self.config)
        self.assertEqual((0, None), (code, message), output)
        self.assertEqual("closed", output["gate"])
        self.assertEqual({"current_user": GATE_ACCOUNT, "problems": []}, output["gate_account"])
        self.assertEqual(logins + 1, self.gate_logins())  # Through mysql.gate_defaults, as the controller has it.
        self.assertEqual((gtid, coordinate), (self.gtid_executed(), self.coordinate()))  # Nothing written.
        self.assertEqual(self.gate_baseline, self.gate_snapshot())
        result = self.cli_check()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual({"current_user": GATE_ACCOUNT, "problems": []}, json.loads(result.stdout)["gate_account"])

    def assert_gate_account_refused(self, expected: str) -> None:
        """check() and the CLI exit 5 naming `expected`, with no password anywhere; the gate itself reads closed."""
        output, code, message = self.gate.check(self.config)
        self.assertEqual(self.gate.EXIT_GATE_ACCOUNT, code, (output, message))
        self.assertEqual("closed", output["gate"])
        self.assertTrue(any(expected in problem for problem in output["gate_account"]["problems"]), output)
        self.assertIn(expected, message)
        result = self.cli_check()
        self.assertEqual(5, result.returncode, result.stderr)
        self.assertEqual(output["gate_account"], json.loads(result.stdout)["gate_account"])
        self.assertIn(expected, result.stderr)
        for secret in [*self.secrets(), WRONG_PASSWORD]:
            for text in (json.dumps(output), message, result.stdout, result.stderr):
                self.assertNotIn(secret, text)
        self.probe_reads("closed", "planned")

    def test_check_refuses_a_gate_account_that_cannot_log_in(self):
        text = (self.fixture.directory / "repro_cutover_gate.cnf").read_text(encoding="ascii")
        wrong = self.host.root / "wrong-password.cnf"
        wrong.write_text(re.sub(r"(?m)^password=.*$", f"password={WRONG_PASSWORD}", text), encoding="ascii")
        with self.subTest(problem="wrong password"):
            self.gate_defaults(wrong)
            self.assert_gate_account_refused("wrong password")
            self.assert_gate_account_refused("ERROR 1045")
        self.gate_defaults(self.fixture.directory / "repro_cutover_gate.cnf")
        with self.subTest(problem="locked"):
            self.fixture.sql(f"ALTER USER {GATE_ACCOUNT_SQL} ACCOUNT LOCK")
            try:
                self.assert_gate_account_refused("is locked")
            finally:
                self.restore_gate_account()
        self.assertEqual(0, self.gate.check(self.config)[1])

    def test_check_refuses_gate_account_grants_other_than_accounts_sql(self):
        cases = {
            "missing CONNECTION_ADMIN ON *.*": f"REVOKE CONNECTION_ADMIN ON *.* FROM {GATE_ACCOUNT_SQL}",
            "INSERT ON `repro\\_dashboard`.* lacks WITH GRANT OPTION":
                f"REVOKE GRANT OPTION ON `repro\\_dashboard`.* FROM {GATE_ACCOUNT_SQL}",
            "UPDATE (`id`) ON `repro_mysql_control`.`writer_token` lacks WITH GRANT OPTION":
                f"REVOKE GRANT OPTION ON `repro_mysql_control`.`writer_token` FROM {GATE_ACCOUNT_SQL}",
            "unexpected SELECT ON `mysql`.`db`": f"GRANT SELECT ON mysql.db TO {GATE_ACCOUNT_SQL}",
        }
        for expected, statement in cases.items():
            with self.subTest(expected=expected):
                try:
                    self.fixture.sql(statement)
                    self.assert_gate_account_refused(expected)
                finally:
                    self.restore_gate_account()
        self.assertEqual(0, self.gate.check(self.config)[1])

    def test_check_refuses_another_account_or_an_unsafe_gate_defaults_file(self):
        path = Path(self.host.config["mysql"]["gate_defaults"])
        cases = {
            "CURRENT_USER() is 'repro_monitor@localhost'":
                lambda: self.gate_defaults(self.fixture.directory / "repro_monitor.cnf"),
            "must be readable by root only":
                lambda: self.gate_defaults(self.fixture.directory / "repro_cutover_gate.cnf", 0o640),
            str(path): path.unlink,
        }
        for expected, change in cases.items():
            with self.subTest(expected=expected):
                change()
                self.assert_gate_account_refused(expected)
        self.gate_defaults(self.fixture.directory / "repro_cutover_gate.cnf")
        self.assertEqual(0, self.gate.check(self.config)[1])


def _fixture_tests():
    return [f"{case.__name__}.{name}" for case in (GateProcedureTests,)
            for name in unittest.TestLoader().getTestCaseNames(case)]


class FixtureRequirementTests(unittest.TestCase):
    """With REPRO_REQUIRE_PROBE_FIXTURE=1 (run-probe-fixture-tests.sh) a gate fixture test that would skip fails."""

    def test_required_fixture_tests_do_not_skip(self):
        if not REQUIRE_FIXTURE:
            self.skipTest("REPRO_REQUIRE_PROBE_FIXTURE is not 1")
        self.assertTrue(LINUX_ROOT and FIXTURE,
                        f"REPRO_REQUIRE_PROBE_FIXTURE=1, but these gate tests would skip: {', '.join(_fixture_tests())}")


class FixtureCheckTests(unittest.TestCase):
    """`test_gate.py --fixture-check`, which run-linux-tests.sh runs before any suite, beside the probe's own."""

    def check(self, environment: dict) -> subprocess.CompletedProcess:
        return subprocess.run([sys.executable, "-B", str(Path(__file__).resolve()), "--fixture-check"],
                              env=environment, capture_output=True, text=True, timeout=60)

    def test_missing_fixture_prints_one_warning_line_naming_the_gate_tests(self):
        result = self.check(probe_tests.without_fixture_environment())
        self.assertEqual((0, ""), (result.returncode, result.stderr))
        lines = result.stdout.splitlines()
        self.assertEqual(1, len(lines), result.stdout)
        self.assertRegex(lines[0], rf"^WARNING: {len(_fixture_tests())} MySQL gate tests SKIPPED ")
        for name in ("REPRO_MYSQL_PROBE_FIXTURE", "GateProcedureTests.test_close_open_gate",
                     "GateProcedureTests.test_unrecognised_state_refuses_and_prints_root_sql"):
            self.assertIn(name, lines[0])

    def test_missing_fixture_fails_when_required(self):
        result = self.check(probe_tests.without_fixture_environment(REPRO_REQUIRE_PROBE_FIXTURE="1"))
        self.assertNotEqual(0, result.returncode)
        self.assertEqual("", result.stdout)
        self.assertRegex(result.stderr, r"^ERROR: REPRO_REQUIRE_PROBE_FIXTURE=1 ")
        self.assertIn("GateProcedureTests.test_close_open_gate", result.stderr)

    @unittest.skipUnless(LINUX_ROOT, "The gate fixture tests need Linux root as well as the fixture")
    def test_fixture_named_is_silent(self):
        result = self.check(probe_tests.without_fixture_environment(REPRO_REQUIRE_PROBE_FIXTURE="1",
                                                                     REPRO_MYSQL_PROBE_FIXTURE="/fixture/socket"))
        self.assertEqual((0, "", ""), (result.returncode, result.stdout, result.stderr))


@unittest.skipUnless(LINUX_ROOT and Path("/.dockerenv").is_file()
                     and probe_tests.RUN_LINUX_TESTS == Path("/src/scripts/cutover/run-linux-tests.sh")
                     and not os.environ.get("REPRO_LINUX_SUITE_NESTED"),
                     "Needs root in a disposable container with the checkout at /src, as run-linux-tests.sh")
class LinuxSuiteTests(unittest.TestCase):
    """run-linux-tests.sh checks both fixture-dependent files before any suite (D06 open item)."""

    def test_required_missing_fixture_names_the_probe_and_gate_tests_before_any_suite(self):
        # REPRO_LINUX_SUITE_NESTED keeps a broken script from starting this test again inside itself.
        result = subprocess.run(["/bin/sh", str(probe_tests.RUN_LINUX_TESTS)], capture_output=True, text=True,
                                timeout=600, env=probe_tests.without_fixture_environment(
                                    REPRO_REQUIRE_PROBE_FIXTURE="1", REPRO_LINUX_SUITE_NESTED="1"))
        self.assertNotEqual(0, result.returncode, result.stderr[-2000:])
        self.assertIn("GateStateTests.test_closed_state", result.stderr)
        self.assertIn("GateProcedureTests.test_close_open_gate", result.stderr)
        self.assertNotIn("Ran ", result.stdout + result.stderr)


def fixture_skips() -> list[str]:
    """The gate fixture tests that skip here, as Class.method names: all of them without Linux root or the fixture."""
    return [] if LINUX_ROOT and FIXTURE else _fixture_tests()


def fixture_check() -> int:
    """`--fixture-check`, run by run-linux-tests.sh first: one WARNING line on stdout naming the gate fixture
    tests that would skip, or, with REPRO_REQUIRE_PROBE_FIXTURE=1, an error and exit 1."""
    skipped = fixture_skips()
    if not skipped:
        return 0
    missing = [] if FIXTURE else ["REPRO_MYSQL_PROBE_FIXTURE unset"]
    if not LINUX_ROOT:
        missing.append("not Linux root")
    summary = f"{len(skipped)} MySQL gate tests SKIPPED ({', '.join(missing)}): {', '.join(skipped)}"
    if REQUIRE_FIXTURE:
        print(f"ERROR: REPRO_REQUIRE_PROBE_FIXTURE=1 but {summary}", file=sys.stderr)
        return 1
    print(f"WARNING: {summary}. This run says nothing about the gate procedures: run "
          "scripts/cutover/production/run-probe-fixture-tests.sh --linux-suite, or set REPRO_REQUIRE_PROBE_FIXTURE=1 "
          "to fail.")
    return 0


def tearDownModule():
    if not (LINUX_ROOT and FIXTURE) and not REQUIRE_FIXTURE:
        names = _fixture_tests()
        print(f"\nWARNING: {len(names)} MySQL gate tests SKIPPED (REPRO_MYSQL_PROBE_FIXTURE unset or not Linux root): "
              f"{', '.join(names)}. Run scripts/cutover/production/run-probe-fixture-tests.sh --linux-suite.",
              file=sys.stderr)


if __name__ == "__main__":
    if sys.argv[1:] == ["--fixture-check"]:
        raise SystemExit(fixture_check())
    unittest.main()
