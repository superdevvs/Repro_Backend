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
performance_schema.processlist for app sessions.

The SQL builders and the root-SQL quoting are also tested without MySQL, anywhere.
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
ACCOUNT_STATEMENT = re.compile(r"^(GRANT|REVOKE) (.+) ON (\S+) (?:TO|FROM) '([a-z_]+)'@'([a-z]+)'$")


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


@unittest.skipUnless(sys.platform == "linux" and hasattr(os, "geteuid"), "The gate CLI runs on the Linux host")
class CommandLineTests(unittest.TestCase):
    def test_cli_runs_only_as_root(self):
        user = 65534 if LINUX_ROOT else None
        result = subprocess.run([sys.executable, "-I", "-S", str(GATE), "--config", "/nonexistent/host.json",
                                 "check"], capture_output=True, text=True, timeout=60, user=user)
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn("runs as root", result.stderr)


@unittest.skipUnless(LINUX_ROOT and FIXTURE, "Needs Linux root and the MySQL fixture (REPRO_MYSQL_PROBE_FIXTURE)")
class GateProcedureTests(unittest.TestCase):
    """gate.py against the fixture built from accounts.sql (design 2.3 and 2.4)."""

    @classmethod
    def setUpClass(cls):
        cls.fixture = probe_tests.Fixture(FIXTURE)
        cls.fixture.restore()
        cls.baseline = cls.fixture.snapshot()
        assert cls.baseline["grants"] == sorted(probe_tests.CLOSED_GRANTS), cls.baseline

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

    def gate_defaults(self, source: Path) -> None:
        target = Path(self.host.config["mysql"]["gate_defaults"])
        shutil.copyfile(source, target)
        os.chmod(target, 0o600)

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
        self.assertEqual({"before": "closed", "granted": True, "killed": [identity]}, result)
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
        self.assertEqual({"before": "open", "granted": False, "killed": []}, result)
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


def tearDownModule():
    if not (LINUX_ROOT and FIXTURE) and not REQUIRE_FIXTURE:
        names = _fixture_tests()
        print(f"\nWARNING: {len(names)} MySQL gate tests SKIPPED (REPRO_MYSQL_PROBE_FIXTURE unset or not Linux root): "
              f"{', '.join(names)}. Run scripts/cutover/production/run-probe-fixture-tests.sh --linux-suite.",
              file=sys.stderr)


if __name__ == "__main__":
    unittest.main()
