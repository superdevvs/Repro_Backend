"""Synthetic counterexamples for the four-GTID rollback exemption (no database required)."""

import json
import re
import unittest
from pathlib import Path

import smoke_rows


DB = "sample"
SERVER = "aaaa1111-2222-4333-8444-555555555555"
GTIDS = tuple(f"{SERVER}:{seq}" for seq in range(31, 35))
TOKEN = "0123456789abcdef"
MARKER = f"cutover smoke {TOKEN} 2026-11-28T00:00:00Z"
NOW = 1795824000
FIXTURE = {"client_id": 5, "shoot_id": 7, "marker": MARKER}


def layout():
    ddl = (Path(__file__).resolve().parents[3] / "database/schema/mysql-schema.sql").read_text(encoding="utf8")
    result = {}
    for table in ("users", "shoots", "aryeo_changes", "jobs"):
        body = re.search(r"CREATE TABLE \x60" + table + r"\x60 \((.*?)\n\)", ddl, re.S)[1]
        result[table] = tuple(re.findall(r"^  \x60([a-z][a-z0-9_]*)\x60 ", body, re.M))
    return result


def job_payload(token):
    # The pinned Laravel serialization. A token embedded in another job or in a chained
    # action does not authenticate a database job.
    job = r"App\Jobs\CutoverSmokeJob"
    serialized = (
        f'O:24:"{job}":15:{{' + 's:5:"tries";i:1;s:7:"timeout";i:60;'
        + f's:5:"token";s:16:"{token}";' + 's:3:"job";N;s:10:"connection";s:8:"database";'
        's:5:"queue";s:13:"cutover-smoke";s:12:"messageGroup";N;s:12:"deduplicator";N;'
        's:5:"delay";N;s:11:"afterCommit";N;s:10:"middleware";a:0:{}s:7:"chained";a:0:{}'
        's:15:"chainConnection";N;s:10:"chainQueue";N;s:19:"chainCatchCallbacks";N;}')
    return {
        "uuid": "bbbb1111-2222-4333-8444-555555555555", "displayName": job,
        "job": r"Illuminate\Queue\CallQueuedHandler@call", "maxTries": 1, "maxExceptions": None,
        "failOnTimeout": False, "backoff": None, "timeout": 60, "retryUntil": None, "delay": None,
        "data": {"commandName": job, "command": serialized, "batchId": None}, "createdAt": NOW,
    }


def fixture_transactions():
    old_client = {"id": 5, "email": "cutover-smoke@repro-cutover.invalid", "name": "Fixture", "company_notes": "old"}
    old_shoot = {"id": 7, "client_id": 5, "shoot_type": "internal_test", "company_notes": "old"}
    feed = {"id": 8, "client_id": 5, "shoot_id": 7, "kind": "shoots.UPDATE", "created_at": "2026-11-28 00:00:00.000000"}
    job = {"id": 12, "queue": "cutover-smoke", "payload": json.dumps(job_payload(TOKEN), separators=(",", ":")),
           "attempts": 0, "reserved_at": None, "available_at": NOW, "created_at": NOW}
    reserved = {**job, "attempts": 1, "reserved_at": NOW}
    return [[("Update", "users", [old_client, {**old_client, "company_notes": MARKER}]),
             ("Update", "shoots", [old_shoot, {**old_shoot, "company_notes": MARKER}]), ("Write", "aryeo_changes", [feed])],
            [("Write", "jobs", [job])], [("Update", "jobs", [job.copy(), reserved.copy()])], [("Delete", "jobs", [reserved.copy()])]]


def entry(pos, value, name):
    nullable = 1
    if value is None:
        kind, raw = "LONGINT", "NULL"
    elif type(value) is int:
        kind, raw = "LONGINT", str(value)
    elif name == "created_at":
        kind, raw = "DATETIME(6)", f"'{value}'"
    else:
        kind = "LONGBLOB/LONGTEXT"
        raw = "'" + value.replace("\\", "\\\\") + "'"
    return f"###   @{pos}={raw} /* {kind} meta=0 nullable={nullable} is_null={int(value is None)} */"


def render(transactions, columns):
    ids = {name: i for i, name in enumerate(columns, 150)}
    result = []
    def header(text):
        result.append(f"#261128  0:00:00 server id 1  end_log_pos 711 CRC32 0x12345678 \t{text}")

    for gtid, changes in zip(GTIDS, transactions):
        header("GTID\tlast_committed=1\tsequence_number=2\trbr_only=yes")
        result.append(f"SET @@SESSION.GTID_NEXT= '{gtid}'/*!*/;")
        header("Query\tthread_id=17\texec_time=0\terror_code=0")
        result.extend(("BEGIN", "/*!*/;"))
        # Oracle flushes the shoot and trigger row bodies together at STMT_END_F.
        for group in ([changes[:1], changes[1:]] if len(changes) == 3 else [changes]):
            for _, table, _ in group:
                header(f"Table_map: \x60{DB}\x60.\x60{table}\x60 mapped to number {ids[table]}")
                result.append("# has_generated_invisible_primary_key=0")
            for index, (op, table, _) in enumerate(group):
                end = " flags: STMT_END_F" if index == len(group) - 1 else ""
                header(f"{op}_rows: table id {ids[table]}{end}")
            for op, table, images in group:
                action = {"Write": "INSERT INTO", "Update": "UPDATE", "Delete": "DELETE FROM"}[op]
                result.append(f"### {action} \x60{DB}\x60.\x60{table}\x60")
                keywords = {"Write": ["SET"], "Update": ["WHERE", "SET"], "Delete": ["WHERE"]}[op]
                for keyword, image in zip(keywords, images):
                    result.append("### " + keyword)
                    result.extend(entry(i, image.get(key), key) for i, key in enumerate(columns[table], 1))
        header("Xid = 2442")
        result.append("COMMIT/*!*/;")
    return ("\n".join(result) + "\n").encode("ascii")


class RowEvidenceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.columns = layout()

    def setUp(self):
        self.transactions = fixture_transactions()
        self.params = {"database": DB, "server_uuid": SERVER, "expected_gtids": GTIDS, "fixture": FIXTURE, "layout": self.columns}

    def verdict(self, raw=None, **kwargs):
        return smoke_rows.verify_rows(render(self.transactions, self.columns) if raw is None else raw, **{**self.params, **kwargs})

    def test_complete_four_committed_transactions(self):
        self.assertTrue(self.verdict())

    def test_converted_sqlite_users_ordinals_follow_the_marked_field_by_name(self):
        columns = dict(self.columns)
        users = [name for name in columns["users"] if name not in {"deleted_at", "secondary_roles"}]
        users.insert(6, "deleted_at")
        users.insert(11, "secondary_roles")
        columns["users"] = tuple(users)
        self.assertEqual(("company_notes", "company_notes"), (self.columns["users"][24], users[26]))
        self.assertTrue(self.verdict(render(self.transactions, columns), layout=columns))
        # A review of the old ordinals would attribute other fields to the wrong names.
        self.assertFalse(self.verdict(render(self.transactions, columns), layout=self.columns))
        wrong = fixture_transactions()
        user_after = wrong[0][0][2][1]
        user_after["company_notes"] = "old"
        user_after[users[24]] = MARKER
        self.assertFalse(self.verdict(render(wrong, columns), layout=columns))
        user_after["company_notes"] = MARKER
        self.assertFalse(self.verdict(render(wrong, columns), layout=columns))
        extra = fixture_transactions()
        extra[0].insert(1, extra[0][0])
        self.assertFalse(self.verdict(render(extra, columns), layout=columns))

    def test_extra_business_edit_or_wrong_row_in_first_transaction(self):
        for idx, key, value in ((0, "name", "some other edit"), (0, "email", "real@example.invalid"),
                                (0, "id", 39), (1, "id", 22), (1, "client_id", 6), (1, "shoot_type", "customer"),
                                (1, "company_notes", "wrong marker"), (2, "client_id", 6), (2, "shoot_id", 16),
                                (2, "kind", "scope_revoked"), (2, "created_at", "ERROR"),
                                (2, "created_at", "2000-01-01 00:00:00.000000")):
            with self.subTest(target=idx, field=key):
                self.transactions = fixture_transactions()
                images = self.transactions[0][idx][2]
                # For the identity cases, both before/after can agree and still name the wrong business row.
                for im in images if key in ("id", "email", "shoot_type", "client_id") else [images[-1]]:
                    im[key] = value
                self.assertFalse(self.verdict())

    def test_job_identity_payload_and_lifecycle(self):
        for field, value in (("queue", "email"), ("available_at", NOW - 3600), ("attempts", 2), ("reserved_at", NOW)):
            with self.subTest(field=field):
                self.transactions = fixture_transactions()
                for step in (1, 2):
                    self.transactions[step][0][2][0][field] = value
                self.assertFalse(self.verdict())
        for step, field, value in ((2, "id", 49), (2, "attempts", 2), (2, "reserved_at", NOW - 1),
                                   (3, "id", 49), (3, "payload", "{}")):
            with self.subTest(step=step, field=field):
                self.transactions = fixture_transactions()
                self.transactions[step][0][2][-1][field] = value
                self.assertFalse(self.verdict())

    def test_stale_token_wrong_handler_extra_job_properties_and_invalid_payload(self):
        for mutate in (
            lambda p: p["data"].update(command=p["data"]["command"].replace(TOKEN, "0000000000000000")),
            lambda p: p["data"].update(command=p["data"]["command"].replace("a:0:{}", "a:1:{i:0;i:9;}", 1)),
            lambda p: p["data"].update(commandName=r"App\Jobs\AnotherJob"),
            lambda p: p.update(job=r"App\AnotherHandler@call"), lambda p: p.update(displayName="AnotherJob"),
            lambda p: p.update(backoff=33), lambda p: p.update(createdAt=NOW-99),
            lambda p: p.update(maxTries=True), lambda p: p.update(timeout=True),
            lambda p: p.update(unknown_action=TOKEN)):
            with self.subTest(change=mutate):
                self.transactions = fixture_transactions()
                data = job_payload(TOKEN)
                mutate(data)
                for tx in self.transactions[1:]:
                    for item in tx[0][2]:
                        item["payload"] = json.dumps(data, separators=(",", ":"))
                self.assertFalse(self.verdict())

    def test_refuses_duplicate_rows_extra_statements_missing_transactions_unknown_events(self):
        baseline = render(self.transactions, self.columns)
        rows = baseline[baseline.index(b"### UPDATE"):baseline.index(b"#261128", baseline.index(b"### UPDATE"))]
        variations = [
            baseline.replace(rows, rows + rows), baseline.replace(b"### SET\n", b"### WHERE\n", 1),
            baseline.replace(b"BEGIN\n", b"BEGIN\nDELETE FROM other\n", 1),
            baseline.replace(b"COMMIT/*!*/;", b"ROLLBACK/*!*/;", 1), baseline.rsplit(b"COMMIT", 1)[0],
            baseline.replace(b"rbr_only=yes", b"rbr_only=no", 1), baseline.replace(b"Update_rows:", b"Unknown_rows:", 1),
            baseline.replace(b" flags: STMT_END_F", b"", 1),
            baseline.replace(b"###   @2=", b"###   @3=", 1), baseline.replace(b"###   @7=", b"###   @8=", 1),
            baseline.replace(b"###   @1=5", b"###   @1=6", 1),
            baseline.replace(b"mapped to number 150", b"mapped to number 169", 1),
            baseline.replace(b"GTID_NEXT= '", b"GTID_NEXT= 'x", 1),
            baseline.replace(b"Xid = ", b"Unknown_event ", 1),
            baseline.replace(b"COMMIT/*!*/;", b"COMMIT/*!*/;\nDELETE FROM jobs", 1),
            baseline.replace(b"# has_generated_invisible_primary_key=0", b"# has_generated_invisible_primary_key=1", 1),
        ]
        for index, raw in enumerate(variations):
            with self.subTest(index=index):
                self.assertFalse(self.verdict(raw))

    def test_opaque_unsupported_types_and_ambiguous_string_rendering_refuse(self):
        data = render(self.transactions, self.columns)
        self.assertFalse(self.verdict(data.replace(b"'Fixture' /* LONGBLOB/LONGTEXT", b"'Fixture' /* FLOAT")))
        self.assertFalse(self.verdict(data.replace(b"'Fixture'", br"'Fi\x09xture'")))
        self.assertFalse(self.verdict(data.replace(b"'Fixture'", b"'Cannot'be'")))

    def test_wrong_target_and_unknown_ordinal_layout(self):
        wrong_layout = dict(self.columns, users=tuple(reversed(self.columns["users"])))
        for change in ({"database": "elsewhere"}, {"server_uuid": "invalid"}, {"expected_gtids": GTIDS[::-1]},
                       {"expected_gtids": GTIDS[:3] + (SERVER + ":80",)}, {"layout": wrong_layout},
                       {"fixture": dict(FIXTURE, client_id=True)}, {"fixture": dict(FIXTURE, marker="bad")},
                       {"fixture": dict(FIXTURE, shoot_id=19)}):
            with self.subTest(change=tuple(change)):
                self.assertFalse(self.verdict(**change))
