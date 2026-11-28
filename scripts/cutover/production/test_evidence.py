"""evidence.py: prepare's evidence report and the record evidence() returns (design v2, 3.2 steps 11 and 12, 3.3,
7.1; build plan D11), and check_inputs' refusals of review D10V-3.

Pure: any POSIX system, any user. The host side (step 11's probes, the report written root 0600, evidence() on a
prepared host, check-evidence --write) is in test_adapter_mysql.py's EvidenceTests and EvidenceFixtureTests.

    python3 -B -m unittest discover -s scripts/cutover/production -p test_evidence.py -v
"""

from __future__ import annotations

import hashlib
import json
import re
import sys
import tempfile
import unittest
from dataclasses import asdict
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE.parent), str(HERE)]
import adapter as production  # noqa: E402
import evidence  # noqa: E402
from fence import guard  # noqa: E402
from runner import Evidence, UnsafeCutover  # noqa: E402
from test_adapter import IDENTITY, OTHER_UUID, TARGET_UUID, example_config  # noqa: E402

PIN, OTHER_PIN = "e" * 64, "9" * 64
FROZEN, COPY = "1" * 64, "2" * 64
RECEIPT = "f" * 64
BACKUP = {"file": "binlog.000003", "position": 1157, "gtid_executed": f"{TARGET_UUID}:1-40"}
INPUTS = ("snapshot", "import", "verify_fields", "migrate_status", "complete_schema", "backup_check")
EXPECTED = {"engine": "mysql", "database": "repro_dashboard", "server_uuid": TARGET_UUID, "route_sha256": PIN}
DRAIN = {"purpose": "freeze", "outcome": "drained", "active_processes": 0, "waited_seconds": 0.5, "polls": 2,
         "problem": None}


def route(**changes) -> dict:
    return {**EXPECTED, **changes}


def sample_checks() -> dict:
    """Step 11's records as prepare writes them when every check passed."""
    return {
        "profile_probes": [
            {"profiles": ["laravel-worker", "media-archives"], "scripts": [], "user": "www-data",
             "group": "www-data", "cwd": "/var/www/backend", "umask": "0007", "env": [], **route(), "ok": True},
            {"profiles": ["studio", "voice-realtime"], "scripts": ["studio"], "user": "maverick", "group": "www-data",
             "cwd": "/var/www/backend", "umask": "0002", "env": [], **route(), "ok": True}],
        "fastcgi": {"socket": "/run/php/php8.3-fpm.sock", **route(), "sapi": "fpm-fcgi", "pid": 4242,
                    "opcache": {"enabled": True, "start_time": 1764000000}, "ok": True},
        "readonly_smoke": [
            {"user": user, "group": "www-data", **route(), "client_id": 4101, "shoot_id": 9201, "client_read": True,
             "shoot_read": True, "encrypted_value_decrypts": True, "smoke_queue_pending": 0,
             "smoke_queue_paused": False, "ok": True} for user in ("www-data", "maverick")],
        "token_check": {"account": "repro_token_check@localhost", "user": "www-data", "engine": "mysql",
                        "writer_policy": True, "token_acquired": True, "problems": [], "ok": True},
        "mysql": {"server_uuid": TARGET_UUID, "database": "repro_dashboard", "gate": "closed", "writable": False,
                  "business_activity": False, "foreign_activity": False, "receipt": RECEIPT, "mode": "measured",
                  "gate_account": {"current_user": "repro_cutover_gate@localhost", "problems": []}, "ok": True},
    }


def bound(mode: str = "drill", waiver: bool = False) -> dict:
    """What check_inputs returns for a night that binds (README "prepare")."""
    inputs = {name: {"path": f"/root/night/{name}", "sha256": hashlib.sha256(name.encode()).hexdigest()}
              for name in INPUTS}
    if waiver:
        inputs["backup_check"] = None
    return {"mode": mode, "offsite_waiver": waiver, "snapshot_sha256": COPY if mode == "drill" else FROZEN,
            "frozen_snapshot_sha256": FROZEN, "backup": dict(BACKUP), "inputs": inputs}


def sample_report(mode: str = "drill", waiver: bool = False, **changes) -> dict:
    fields = {"run_id": IDENTITY.run_id, "mode": mode, "offsite_waiver": waiver, "bound": bound(mode, waiver),
              "receipt_sha256": RECEIPT, "armed_from": {"file": "binlog.000003", "position": 5253},
              "switch_at": 1764000100, "fpm_drain": dict(DRAIN), "checks": sample_checks(), "written_at": 1764000160}
    return evidence.build(**{**fields, **changes})


def raw(report: dict) -> bytes:
    return evidence.encode(report)


class ReportContractTests(unittest.TestCase):
    """The report's keys are the contract the drill harness (D18) and the CLI (D14) read."""

    def test_report_holds_exactly_the_contract_keys(self):
        report = sample_report()
        self.assertEqual(evidence.REPORT_KEYS, set(report))
        self.assertEqual(evidence.CHECK_KEYS, set(report["checks"]))
        for name, keys in evidence.CHECK_RECORD_KEYS.items():
            records = report["checks"][name]
            for record in records if isinstance(records, list) else [records]:
                self.assertEqual(keys, set(record), name)
        self.assertEqual(evidence.RESULT_KEYS, set(report["results"]))
        # Every result the runner's Evidence carries is in the report, so the record follows from it.
        self.assertEqual(evidence.RESULT_KEYS, {name for name in Evidence.__dataclass_fields__
                                               if name not in ("snapshot_sha256", "report_sha256", "binlog_file",
                                                               "binlog_position")})

    def test_report_keys_are_the_readme_contract(self):
        # README "The evidence report": the JSON block after the contract marker names every key of the report and of
        # each check record; D18's harness and D14's `status --evidence` read the report by these names.
        text = (HERE / "README.md").read_text(encoding="utf-8")
        match = re.search(r"<!-- evidence-report-contract -->\s*```json\n(.*?)\n```", text, re.DOTALL)
        self.assertIsNotNone(match, "README has no evidence report contract block")
        documented = json.loads(match.group(1))
        self.assertEqual(evidence.REPORT_KEYS, set(documented))
        self.assertEqual(evidence.CHECK_KEYS, set(documented["checks"]))
        for name, keys in evidence.CHECK_RECORD_KEYS.items():
            record = documented["checks"][name]
            self.assertEqual(keys, set(record[0] if isinstance(record, list) else record), name)
        self.assertEqual(evidence.RESULT_KEYS, set(documented["results"]))

    def test_report_is_canonical_json_and_parses_back(self):
        report = sample_report()
        encoded = raw(report)
        self.assertEqual(json.dumps(report, sort_keys=True, separators=(",", ":"), ensure_ascii=True,
                                    allow_nan=False).encode("ascii") + b"\n", encoded)
        self.assertEqual(report, evidence.parse(encoded))
        # Another layout of the same document is not the report prepare wrote: report_sha256 must be reproducible.
        for name, text in (("indented", json.dumps(report, sort_keys=True, indent=1).encode() + b"\n"),
                           ("unsorted", json.dumps(dict(reversed(list(report.items())))).encode() + b"\n"),
                           ("no final newline", encoded[:-1])):
            with self.subTest(name), self.assertRaisesRegex(UnsafeCutover, "not canonical"):
                evidence.parse(text)

    def test_unreadable_or_nested_report_is_refused_not_raised(self):
        for name, text in (("not JSON", b"{"), ("not an object", b"[]\n"), ("not UTF-8", b"\xff\n"),
                           ("deeply nested", b"[" * 100000 + b"]" * 100000), ("NaN", b'{"version": NaN}\n'),
                           ("a repeated key", b'{"version": 1, "version": 1}\n')):
            with self.subTest(name), self.assertRaisesRegex(UnsafeCutover, "evidence report"):
                evidence.parse(text)


class RecordTests(unittest.TestCase):
    """evidence.record(raw): the runner's Evidence from the report's bytes alone (design 3.3)."""

    def test_record_is_a_pure_function_of_the_report_bytes(self):
        encoded = raw(sample_report())
        first, second = evidence.record(encoded), evidence.record(encoded)
        self.assertEqual(first, second)
        self.assertEqual(Evidence(snapshot_sha256=COPY, report_sha256=hashlib.sha256(encoded).hexdigest(),
                                  binlog_file=BACKUP["file"], binlog_position=BACKUP["position"], forward_parity=True,
                                  final_schema=True, readonly_smoke=True, sqlite_backup_offsite=True,
                                  mysql_backup_offsite=True), first)
        first.require_verified()
        # The report's whole bytes are bound: a report written a second later is another report.
        later = raw(sample_report(written_at=1764000161))
        self.assertNotEqual(first.report_sha256, evidence.record(later).report_sha256)
        self.assertEqual({**asdict(first), "report_sha256": None},
                         {**asdict(evidence.record(later)), "report_sha256": None})

    def test_record_binds_the_copy_in_drill_and_the_frozen_snapshot_in_cutover(self):
        # Design 7.1: forward parity is the copy's S_copy in drill mode, the frozen snapshot in cutover mode; the
        # report keeps both.
        drill = evidence.parse(raw(sample_report("drill")))
        self.assertEqual((COPY, FROZEN), (drill["snapshot_sha256"], drill["frozen_snapshot_sha256"]))
        self.assertEqual(COPY, evidence.record(raw(sample_report("drill"))).snapshot_sha256)
        cutover = sample_report("cutover")
        self.assertEqual((FROZEN, FROZEN), (cutover["snapshot_sha256"], cutover["frozen_snapshot_sha256"]))
        self.assertEqual(FROZEN, evidence.record(raw(cutover)).snapshot_sha256)
        with self.assertRaisesRegex(UnsafeCutover, "Cutover mode needs one snapshot"):
            evidence.record(raw({**cutover, "snapshot_sha256": COPY}))

    def test_a_failed_missing_or_extra_check_is_refused(self):
        good = sample_report()

        def changed(change) -> dict:
            report = json.loads(json.dumps(good))
            change(report)
            return report

        cases = {
            "a profile probe that failed": lambda r: r["checks"]["profile_probes"][1].update(ok=False),
            # The record's own `ok` is checked, not only the results that follow from it.
            "a failed smoke with results that say so": lambda r: (r["checks"]["readonly_smoke"][1].update(ok=False),
                                                                  r["results"].update(readonly_smoke=False)),
            "no profile probe": lambda r: r["checks"].update(profile_probes=[]),
            "the FastCGI probe failed": lambda r: r["checks"]["fastcgi"].update(ok=False),
            "one read-only smoke failed": lambda r: r["checks"]["readonly_smoke"][0].update(ok=False),
            "a smoke user omitted": lambda r: r["checks"]["readonly_smoke"].pop(),
            "the same smoke user twice": lambda r: r["checks"]["readonly_smoke"][1].update(user="www-data"),
            "a smoke failed to decrypt": lambda r: r["checks"]["readonly_smoke"][1].update(encrypted_value_decrypts=False),
            "a boolean queue depth": lambda r: r["checks"]["readonly_smoke"][0].update(smoke_queue_pending=False),
            "different marked shoots": lambda r: r["checks"]["readonly_smoke"][0].update(shoot_id=7),
            "the check account logged an error": lambda r: r["checks"]["token_check"].update(problems=["failed"]),
            "an open gate": lambda r: r["checks"]["mysql"].update(writable=True),
            "a numeric result": lambda r: r["results"].update(final_schema=1),
            "the token check failed": lambda r: r["checks"]["token_check"].update(ok=False),
            "the MySQL reading failed": lambda r: r["checks"]["mysql"].update(ok=False),
            "a check missing": lambda r: r["checks"].pop("token_check"),
            "an unknown check": lambda r: r["checks"].update(write_smoke={"ok": True}),
            "a check record key too many": lambda r: r["checks"]["fastcgi"].update(note="x"),
            "a report key missing": lambda r: r.pop("fpm_drain"),
            "a report key too many": lambda r: r.update(comment="x"),
            "results that do not follow from the checks": lambda r: r["results"].update(readonly_smoke=False),
            "results that do not follow from the inputs": lambda r: r["results"].update(forward_parity=False),
            "the MySQL reading of another receipt": lambda r: r["checks"]["mysql"].update(receipt="0" * 64),
            "a check of another route": lambda r: r["checks"]["profile_probes"][0].update(route_sha256=OTHER_PIN),
            "a check of another server": lambda r: r["checks"]["fastcgi"].update(server_uuid=OTHER_UUID),
            "a freeze drain the freeze would refuse": lambda r: r["fpm_drain"].update(outcome="unmonitored"),
            "another report version": lambda r: r.update(version=2),
        }
        for name, change in cases.items():
            with self.subTest(name), self.assertRaises(UnsafeCutover):
                evidence.record(raw(changed(change)))
        evidence.record(raw(good))


class WaiverTests(unittest.TestCase):
    def test_waiver_only_in_drill_mode(self):
        # Design 3.1 and 7.1: the off-site waiver (drill only) replaces the final backup check, and evidence() then
        # reports both off-site results true by the waiver.
        waived = sample_report("drill", waiver=True)
        self.assertIsNone(waived["inputs"]["backup_check"])
        self.assertTrue(waived["offsite_waiver"])
        record = evidence.record(raw(waived))
        self.assertEqual((True, True), (record.sqlite_backup_offsite, record.mysql_backup_offsite))
        record.require_verified()
        # Without the waiver the backup check is what proves both publications.
        checked = evidence.record(raw(sample_report("cutover")))
        self.assertEqual((True, True), (checked.sqlite_backup_offsite, checked.mysql_backup_offsite))
        # A waiver outside drill mode, a waiver beside a backup check, no backup check without a waiver: refused.
        with self.assertRaisesRegex(UnsafeCutover, "waiver is allowed only in drill mode"):
            sample_report("cutover", waiver=True)
        drill_checked = json.loads(json.dumps(sample_report("drill")))
        for name, report, pattern in (
            ("a cutover report with the waiver", {**json.loads(json.dumps(sample_report("cutover"))),
                                                  "offsite_waiver": True}, "waiver is allowed only in drill mode"),
            ("a waiver beside a backup check", {**drill_checked, "offsite_waiver": True}, "replaces the final backup"),
            ("no backup check and no waiver", {**drill_checked, "inputs": {**drill_checked["inputs"],
                                                                         "backup_check": None}},
             "final backup check is required"),
        ):
            with self.subTest(name), self.assertRaisesRegex(UnsafeCutover, pattern):
                evidence.record(raw(report))
        # And the host config itself never carries a waiver outside drill mode (D01).
        config = example_config()
        config["mysql"].update(mode="cutover", offsite_waiver=True)
        with self.assertRaisesRegex(guard.Refused, "only in drill mode"):
            production._check_mysql(config["mysql"])


class CheckRecordTests(unittest.TestCase):
    """Step 11's parsers: what a probe printed, compared with what the host must show (design 3.2 step 11)."""

    def engine_output(self, **changes) -> str:
        report = {"version": 1, "mode": "engine-only", "ok": True, "engine": "mysql", "connection": "mysql",
                  "database": "repro_dashboard", "server_uuid": TARGET_UUID, "server_version": "8.4.13",
                  "config_cached": True, "config_path": "/var/www/backend/bootstrap/cache/config.php",
                  "process": {"euid": 33, "user": "www-data", "egid": 33, "group": "www-data",
                              "cwd": "/var/www/backend", "umask": "0007"},
                  "route_values": {"database.default": "mysql"}, "problems": []}
        report.update(changes)
        return json.dumps(report)

    def test_the_route_values_are_hashed_and_compared_with_the_pin(self):
        values = {"database.default": "mysql"}
        expected = {**EXPECTED, "route_sha256": hashlib.sha256(json.dumps(
            values, sort_keys=True, separators=(",", ":")).encode()).hexdigest()}
        context = {"user": "www-data", "group": "www-data", "cwd": "/var/www/backend", "umask": "0007"}
        checked = evidence.engine_check(self.engine_output(), 0, context, expected, "the probe")
        self.assertEqual({key: checked[key] for key in EXPECTED}, expected)
        # A deprecation line before the report does not hide it: the report is the last line.
        evidence.engine_check("PHP Deprecated: x\n" + self.engine_output() + "\n", 0, context, expected, "the probe")
        for name, output, code, pattern in (
            ("another route", self.engine_output(route_values={"database.default": "sqlite"}), 0, "route hash"),
            ("SQLite", self.engine_output(engine="sqlite"), 0, "engine sqlite"),
            ("another server", self.engine_output(server_uuid=OTHER_UUID), 0, "server"),
            ("another database", self.engine_output(database="repro_other"), 0, "database"),
            ("its own problems", self.engine_output(ok=False, problems=["database: SQLSTATE[HY000] [2002]"]), 1,
             r"SQLSTATE\[HY000\] \[2002\]"),
            ("no report", "", 255, "printed no report"),
            ("not JSON", "Segmentation fault", 139, "printed no report"),
            ("exit 1 with an ok report", self.engine_output(), 1, "exited 1"),
            ("the root refusal", json.dumps({"version": 1, "mode": None, "ok": False, "problems": [
                "refused: cutover:smoke never runs as root (effective uid 0)"]}), 1, "never runs as root"),
            ("another user", self.engine_output(process={**json.loads(self.engine_output())["process"],
                                                         "user": "maverick", "euid": 1000}), 0, "ran as maverick"),
            ("another umask", self.engine_output(process={**json.loads(self.engine_output())["process"],
                                                          "umask": "0022"}), 0, "umask 0022"),
            ("another cwd", self.engine_output(process={**json.loads(self.engine_output())["process"],
                                                        "cwd": "/tmp"}), 0, "ran in /tmp"),
            ("root", self.engine_output(process={**json.loads(self.engine_output())["process"], "euid": 0}), 0,
             "root"),
            ("another mode", self.engine_output(mode="read-only"), 0, "ran in mode read-only"),
        ):
            with self.subTest(name), self.assertRaisesRegex(UnsafeCutover, pattern):
                evidence.engine_check(output, code, context, expected, "the probe")

    def test_the_read_only_smoke_reads_the_marked_records_as_its_user_and_group(self):
        values = {"database.default": "mysql"}
        expected = {**EXPECTED, "route_sha256": hashlib.sha256(json.dumps(
            values, sort_keys=True, separators=(",", ":")).encode()).hexdigest()}
        checks = {"client_id": 4101, "shoot_id": 9201, "client_read": True, "shoot_read": True,
                  "encrypted_value_decrypts": True, "smoke_queue": "database:cutover-smoke", "smoke_queue_pending": 0,
                  "smoke_queue_paused": False}
        process = {"euid": 1000, "user": "maverick", "egid": 33, "group": "www-data", "cwd": "/", "umask": "0002"}

        def output(**changes) -> str:
            report = {"version": 1, "mode": "read-only", "ok": True, "engine": "mysql", "database": "repro_dashboard",
                      "server_uuid": TARGET_UUID, "process": process, "route_values": values, "problems": [],
                      "checks": checks}
            return json.dumps({**report, **changes})

        smoke = {"client_id": 4101, "shoot_id": 9201}
        record = evidence.readonly_check(output(), 0, "maverick", "www-data", smoke, expected)
        self.assertEqual({"user": "maverick", "group": "www-data", **expected, **{name: checks[name] for name in (
            "client_id", "shoot_id", "client_read", "shoot_read", "encrypted_value_decrypts", "smoke_queue_pending",
            "smoke_queue_paused")}, "ok": True}, record)
        for name, text, group, pattern in (
            ("another group than runuser's -g", output(process={**process, "group": "maverick"}), "www-data",
             "ran in group maverick"),
            ("another test shoot", output(checks={**checks, "shoot_id": 7}), "www-data", "marked test client"),
            ("the shoot unread", output(checks={**checks, "shoot_read": False}), "www-data", "shoot_read"),
            ("another queue", output(checks={**checks, "smoke_queue": "database:default"}), "www-data", "cutover-smoke"),
            ("boolean depth", output(checks={**checks, "smoke_queue_pending": False}), "www-data", "jobs wait"),
            ("the engine-only report", output(mode="engine-only"), "www-data", "mode engine-only"),
        ):
            with self.subTest(name), self.assertRaisesRegex(UnsafeCutover, pattern):
                evidence.readonly_check(text, 0, "maverick", group, smoke, expected)
        # Without -g the group is whatever the user's is, and recorded.
        self.assertEqual("www-data", evidence.readonly_check(output(), 0, "maverick", None, smoke, expected)["group"])
        with self.assertRaisesRegex(UnsafeCutover, "marked test client"):
            evidence.readonly_check(output(checks={**checks, "client_id": True}), 0, "maverick", None,
                                    {**smoke, "client_id": 1}, expected)

    def test_token_check_needs_no_problems_and_the_token(self):
        good = {"engine": "mysql", "writer_policy": True, "session": {"isolation": "READ-COMMITTED"},
                "token": {"acquired": True}, "problems": []}
        record = evidence.token_check(json.dumps(good), 0, "repro_token_check@localhost", "www-data")
        self.assertEqual({"account": "repro_token_check@localhost", "user": "www-data", "engine": "mysql",
                          "writer_policy": True, "token_acquired": True, "problems": [], "ok": True}, record)
        for name, report, code, pattern in (
            ("a problem", {**good, "problems": ["UPDATE command denied to user"], "token": {"acquired": False}}, 1,
             "UPDATE command denied"),
            ("no token", {**good, "token": {"acquired": False}}, 0, "did not take the writer token"),
            ("SQLite", {"engine": "sqlite", "writer_policy": "not applicable", "problems": []}, 0, "engine sqlite"),
            ("the policy off", {**good, "writer_policy": False}, 0, "writer policy"),
            ("exit 1 with no problem", good, 1, "exited 1"),
            ("no report", None, 255, "printed no report"),
        ):
            with self.subTest(name), self.assertRaisesRegex(UnsafeCutover, pattern):
                evidence.token_check("" if report is None else json.dumps(report), code, "repro_token_check@localhost",
                                     "www-data")

    def test_gate_check_needs_exit_0_a_closed_gate_and_a_clean_gate_account(self):
        # Review B2-1: each of gate.check's three answers is checked on its own, whatever the others say.
        account = {"current_user": "repro_cutover_gate@localhost", "problems": []}
        good = {"version": 1, "reachable": True, "gate": "closed", "open_privileges": {}, "sessions": [],
                "root_sql": None, "gate_account": account}
        self.assertEqual(account, evidence.gate_result((good, 0, None)))
        for name, result, pattern in (
            ("a failure exit beside a clean reading", (good, 1, "Gate check failed: unreadable host.json"),
             r"gate.check refused \(exit 1\): Gate check failed"),
            ("an open gate with exit 0", ({**good, "gate": "open"}, 0, None), "reads the gate open"),
            ("account problems with exit 0", ({**good, "gate_account": {**account, "problems": [
                "missing CONNECTION_ADMIN ON *.*"]}}, 0, None), "missing CONNECTION_ADMIN"),
            ("another account with exit 0", ({**good, "gate_account": {**account, "current_user": "repro_app@localhost"}},
                                              0, None), "self-check failed"),
            ("no reading", ({"version": 1, "reachable": False, "gate": None, "root_sql": None, "gate_account": None}, 3,
                            "The MySQL server is not listening"), r"exit 3\): The MySQL server is not listening"),
        ):
            with self.subTest(name), self.assertRaisesRegex(UnsafeCutover, pattern):
                evidence.gate_result(result)

    def test_the_mysql_reading_must_be_closed_quiet_and_armed_on_this_receipt(self):
        reading = {"version": 2, "reachable": True, "server_uuid": TARGET_UUID, "database": "repro_dashboard",
                   "gate": "closed", "writable": False, "business_activity": False, "foreign_activity": False,
                   "boundary": None, "receipt": RECEIPT, "gated_sessions": 1, "scanned_events": 3,
                   "mode": "measured"}
        gate = {"version": 1, "reachable": True, "gate": "closed", "open_privileges": {}, "sessions": [],
                "root_sql": None, "gate_account": {"current_user": "repro_cutover_gate@localhost", "problems": []}}
        mysql = example_config()["mysql"]
        record = evidence.mysql_check(reading, (gate, 0, None), mysql, RECEIPT)
        self.assertTrue(record["ok"])
        self.assertEqual(sample_checks()["mysql"], record)
        for name, changed, gate_result, pattern in (
            ("an open gate", {"gate": "open", "writable": True}, (gate, 0, None), "gate"),
            ("business activity", {"business_activity": True}, (gate, 0, None), "business activity"),
            ("foreign activity", {"foreign_activity": True}, (gate, 0, None), "foreign activity"),
            ("another server", {"server_uuid": OTHER_UUID}, (gate, 0, None), "another server"),
            ("another receipt", {"receipt": "0" * 64}, (gate, 0, None), "not armed on this run's receipt"),
            ("no receipt", {"receipt": None}, (gate, 0, None), "not armed on this run's receipt"),
            ("the gate account's self-check", {}, ({**gate, "gate_account": {"current_user": None, "problems": [
                "The gate account is locked"]}}, 5, "The gate account's self-check failed: The gate account is locked"),
             "gate account is locked"),
            ("a partial gate for gate.check", {}, ({**gate, "gate": "partial"}, 4, "The gate is partial"),
             "partial"),
        ):
            with self.subTest(name), self.assertRaisesRegex(UnsafeCutover, pattern):
                evidence.mysql_check({**reading, **changed}, gate_result, mysql, RECEIPT)


class HostEvidenceTests(unittest.TestCase):
    def setUp(self):
        self.adapter = object.__new__(production.ProductionAdapter)
        self.adapter.config = example_config()
        self.adapter.pinned = IDENTITY

    @unittest.skipUnless(sys.platform.startswith("linux"), "the smoke users are Linux accounts")
    def test_smoke_command_forms_and_distinct_nonroot_accounts(self):
        commands = self.adapter.config["commands"]["smoke_readonly"]
        web, owner = commands
        with mock.patch("pwd.getpwnam", side_effect=lambda u: SimpleNamespace(pw_uid=33 if u == web[2] else 1000)):
            self.assertEqual(2, len(self.adapter._smoke_commands()))
            direct = [*web[:3], "--", production.config_build.PHP, f"{self.adapter.config['app_root']}/artisan",
                      "cutover:smoke", "--read-only", "--json"]
            commands[:] = [direct, owner]
            self.assertEqual(2, len(self.adapter._smoke_commands()))
            for invalid in ([*direct, "--other"], [*direct[:5], "/other/artisan", *direct[6:]],
                            [*web[:-2], web[-2] + " && true", web[-1]]):
                commands[:] = [invalid, owner]
                with self.subTest(command=invalid), self.assertRaisesRegex(UnsafeCutover, "read-only"):
                    self.adapter._smoke_commands()
        for ids in ((33, 33), (0, 1000)):
            commands[:] = [web, owner]
            with self.subTest(ids=ids), mock.patch("pwd.getpwnam", side_effect=[SimpleNamespace(pw_uid=i) for i in ids]), \
                    self.assertRaisesRegex(UnsafeCutover, "distinct non-root"):
                self.adapter._smoke_commands()

    def test_evidence_binds_the_configured_users_and_test_records(self):
        report = sample_report()
        self.adapter._journal_state = lambda: {"started_at": report["switch_at"]}
        self.adapter.config["mysql"].update(mode="drill", offsite_waiver=False)
        self.adapter.config["smoke"].update(client_id=4101, shoot_id=9201)
        for key, value in (("user", "someone-else"), ("shoot_id", 42)):
            record = report["checks"]["readonly_smoke"][1]
            with self.subTest(key=key), mock.patch.dict(record, {key: value}), \
                    self.assertRaisesRegex(UnsafeCutover, "accounts or marked"):
                self.adapter._require_report_binding(report)
        self.adapter.config["fpm"]["socket"] = report["checks"]["fastcgi"]["socket"]
        self.adapter.config["mysql"].update(database=EXPECTED["database"], target_uuid=TARGET_UUID)
        self.adapter.policy = {"mysql_live_config_sha256": OTHER_PIN}
        with self.assertRaisesRegex(UnsafeCutover, "route pin"):
            self.adapter._require_report_binding(report)
        self.adapter.policy["mysql_live_config_sha256"] = PIN
        self.adapter._profile_contexts = lambda: sample_checks()["profile_probes"]
        report["checks"]["profile_probes"].pop()
        with self.assertRaisesRegex(UnsafeCutover, "wrapper profile"):
            self.adapter._require_report_binding(report)

    @unittest.skipUnless(sys.platform.startswith("linux"), "the drill imports Linux process bindings")
    def test_dress_rehearsal_consumes_the_adapter_report(self):
        from e2e import run_drill as drill

        report = sample_report()
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / "night").mkdir()
            (root / "night" / drill.COPY_RECORD).write_text(COPY)
            report_path, sealed, inputs = root / "report.json", root / "sealed.json", root / "inputs.json"
            sealed.write_text(json.dumps({"sha256": FROZEN}))
            inputs.write_text(json.dumps({"inputs": report["inputs"]}))
            profiles = {name: {k: record[k] for k in ("user", "group", "cwd")}
                        for record in report["checks"]["profile_probes"] for name in record["profiles"]}
            (root / "wrappers.json").write_text(json.dumps({"profiles": profiles}))
            inside = object.__new__(drill.Inside)
            inside.paths = SimpleNamespace(e2e=root)
            inside.host = {"mysql": {"mode": "drill", "offsite_waiver": False, "target_uuid": TARGET_UUID,
                                     "database": "repro_dashboard"}, "web_user": "www-data", "app_owner": "maverick",
                           "paths": {"evidence_inputs": str(inputs)}}
            inside.policy = {"identity": {"run_id": IDENTITY.run_id}, "mysql_live_config_sha256": PIN}
            inside.require_root_private = mock.Mock()
            inside.coordinate = lambda: BACKUP
            inside.cli = lambda *args: report
            with mock.patch.multiple(drill, EVIDENCE_REPORT=report_path, SEALED_HASH=sealed, ETC=root):
                report_path.write_bytes(raw(report))
                self.assertIn("2 profile contexts", inside.step_prepare_checks())
                report["checks"]["readonly_smoke"][1]["user"] = "www-data"
                report_path.write_bytes(raw(report))
                with self.assertRaisesRegex(drill.StepFailed, "configured web user"):
                    inside.step_prepare_checks()

    def test_gate_probe_uses_one_remaining_budget(self):
        import gate

        self.adapter.clock = lambda: 500
        with mock.patch.object(gate, "check") as check, mock.patch.object(production.time, "monotonic", return_value=100):
            self.adapter._gate_check(501)
            check.assert_called_once_with(self.adapter.config, deadline=101)
            with self.assertRaisesRegex(UnsafeCutover, "deadline"):
                self.adapter._gate_check(499)
            self.assertEqual(1, check.call_count)
        with mock.patch.object(gate.probe.time, "monotonic", side_effect=[100, 100.75, 101]):
            self.assertEqual(1, gate.probe.timeout_seconds(30, 101))
            self.assertEqual(.25, gate.probe.timeout_seconds(30, 101))
            with self.assertRaisesRegex(TimeoutError, "deadline"):
                gate.probe.timeout_seconds(30, 101)
        with mock.patch.object(gate, "read", return_value=gate.Reading("closed", {}, ())) as read, \
                mock.patch.object(gate, "gate_account_check", return_value={"problems": []}) as account:
            gate.check(self.adapter.config, deadline=101)
            read.assert_called_once_with(self.adapter.config, deadline=101)
            account.assert_called_once_with(self.adapter.config, deadline=101)

    def test_token_cache_cleanup_warns_without_disclosing_exception_contents(self):
        import io

        with tempfile.TemporaryDirectory() as folder:
            entry = Path(folder) / (production.TOKEN_CHECK_PREFIX + "fixture")
            entry.mkdir()
            with mock.patch.object(production, "TOKEN_CHECK_PARENT", folder), \
                    mock.patch.object(production.shutil, "rmtree", side_effect=PermissionError(13, "sensitive message")), \
                    mock.patch("sys.stderr", new_callable=io.StringIO) as errors:
                self.adapter._remove_token_folders()
                self.assertIn("WARNING", errors.getvalue())
                self.assertIn(str(entry), errors.getvalue())
                self.assertNotIn("sensitive message", errors.getvalue())


class CheckInputsRefusalTests(unittest.TestCase):
    """Review D10V-3: check_inputs, which check-evidence calls directly, refuses (UnsafeCutover) what used to raise."""

    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.night = Path(folder.name)
        self.config = example_config()
        self.config["mysql"].update(free_disk_floor_bytes=1, offsite_waiver=True)
        self.policy = {"mysql_live_config_sha256": PIN}

    def manifest(self, **documents) -> dict:
        texts = {
            "snapshot": f"{FROZEN}  /protected/frozen.sqlite\n",
            "import": json.dumps({"mode": "import-empty", "import_verified": True, "source_changed": False,
                                  "source_sha256": COPY, "target_server_uuid": TARGET_UUID,
                                  "target_database": "repro_dashboard"}) + "\n",
            "verify_fields": json.dumps({"result": "all-fields-verified", "field_mismatches": 0, "source_sha256": COPY,
                                         "target_server_uuid": TARGET_UUID,
                                         "target_database": "repro_dashboard"}) + "\n",
            "migrate_status": "   INFO  No pending migrations.  \n",
            "complete_schema": json.dumps({"verified": True, "migration_ledger_unchanged": True,
                                           "server_uuid": TARGET_UUID}) + "\n",
            **documents,
        }
        inputs = {"backup_check": None}
        for name, text in texts.items():
            path = self.night / name
            path.write_bytes(text.encode("utf-8") if isinstance(text, str) else text)
            inputs[name] = {"path": str(path), "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}
        return {"version": 1, "run_id": IDENTITY.run_id, "mode": "drill", "backup": dict(BACKUP), "inputs": inputs}

    def check(self, manifest: dict) -> dict:
        return production.check_inputs(self.config, IDENTITY, self.policy, 0, manifest)

    def test_a_night_that_binds_passes(self):
        self.assertEqual(COPY, self.check(self.manifest())["snapshot_sha256"])

    def test_a_deeply_nested_json_input_is_refused_not_raised(self):
        nested = b"[" * 100000 + b"]" * 100000 + b"\n"
        self.assertLess(len(nested), production.MAX_INPUT_BYTES)
        for name, pattern in (("import", "import result is not one JSON object"),
                              ("verify_fields", "verify-fields report is not the tool's JSON lines"),
                              ("complete_schema", "complete-schema output is not one JSON object")):
            with self.subTest(name), self.assertRaisesRegex(UnsafeCutover, pattern):
                self.check(self.manifest(**{name: nested}))

    def test_a_manifest_path_with_nul_is_refused_not_raised(self):
        manifest = self.manifest()
        manifest["inputs"]["import"]["path"] += "\0.json"
        with self.assertRaisesRegex(UnsafeCutover, "manifest is malformed"):
            self.check(manifest)


if __name__ == "__main__":
    unittest.main()
