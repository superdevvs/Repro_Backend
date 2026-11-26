"""Tests for the binary log scan (design v2 section 1.2 and 4.3): business activity from the receipt.

No server is needed: the event text below is recorded from MySQL 8.4.13 and fed through the
client's batch format (`--batch --skip-column-names`, which escapes backslash, tab, newline and
NUL), exactly as mysql_probe.py reads `SHOW BINLOG EVENTS`.

The recordings come from the experiments next to the design (integration-20261125/adapter-lane/
experiments): E6 (`e6-gate-v2.sh`, section G6 of `e6-gate-v2.log`) and E1 (`e1-gate.sh`, sections
T7 to T7c of `e1-gate.log`). Positions and Info are copied verbatim; Log_name and Server_id are the
recipes' (`binlog.000001`, `--server-id`), Event_type follows from the Info form and the statement
the recipe ran, and End_log_pos is the next event's position (the events inside a compressed
transaction all show the payload's end, as 8.4.13 prints them). test_mysql_probe.py's
ActivityTests.test_recorded_e6_text_is_what_this_server_prints replays E6 on the fixture and
compares every event type and Info with E6_G6 below.
"""

import sys
import unittest
from pathlib import Path

sys.path[:0] = [str(Path(__file__).resolve().parent)]
import binlog_scan  # noqa: E402
from binlog_scan import Coordinate, Event, GtidSet, Rules, Unreadable  # noqa: E402

E6_UUID = "4eece786-e90e-11f1-90fe-c4151a420038"
E1_UUID = "40190506-e905-11f1-84b4-32c85654a43c"
RULES = Rules(database="repro_dashboard", excluded_tables=frozenset({"repro_recovery_heartbeat"}),
              lockable_accounts=frozenset({"repro_maint@localhost", "repro_migrator@localhost"}))
SMOKE_TABLES = frozenset({"users", "shoots", "aryeo_changes", "jobs"})


def gtid(uuid, number):
    return f"SET @@SESSION.GTID_NEXT= '{uuid}:{number}'"


# E6 G6: the binary log after the receipt coordinate (binlog.000001 13791, gtid_executed 1-48).
# 49 a foreign write (repro_maint), 50 and 51 the lockable accounts locked, 52 the heartbeat,
# 53 and 54 seal_mysql's GRANTs (the boundary), 55 and 56 the smoke window (1-54 before, 1-56
# after), 57 a business write, 58 and 59 the gate closed again.
E6_G6 = [
    (13791, "Gtid", gtid(E6_UUID, 49)),
    (13870, "Query", "BEGIN"),
    (13941, "Table_map", "table_id: 116 (repro_dashboard.shoots)"),
    (14012, "Write_rows", "table_id: 116 flags: STMT_END_F"),
    (14087, "Xid", "COMMIT /* xid=287 */"),
    (14118, "Gtid", gtid(E6_UUID, 50)),
    (14195, "Query", "ALTER USER 'repro_maint'@'localhost' ACCOUNT LOCK /* xid=291 */"),
    (14327, "Gtid", gtid(E6_UUID, 51)),
    (14404, "Query", "ALTER USER 'repro_migrator'@'localhost' ACCOUNT LOCK /* xid=292 */"),
    (14539, "Gtid", gtid(E6_UUID, 52)),
    (14618, "Query", "BEGIN"),
    (14697, "Table_map", "table_id: 117 (repro_dashboard.repro_recovery_heartbeat)"),
    (14776, "Write_rows", "table_id: 117 flags: STMT_END_F"),
    (14817, "Xid", "COMMIT /* xid=300 */"),
    (14848, "Gtid", gtid(E6_UUID, 53)),
    (14927, "Query", r"GRANT INSERT, UPDATE, DELETE ON `repro\_dashboard`.* TO 'repro_app'@'localhost' /* xid=304 */"),
    (15136, "Gtid", gtid(E6_UUID, 54)),
    (15215, "Query",
     "GRANT UPDATE (`id`) ON `repro_mysql_control`.`writer_token` TO 'repro_app'@'localhost' /* xid=305 */"),
    (15434, "Gtid", gtid(E6_UUID, 55)),
    (15513, "Query", "BEGIN"),
    (15584, "Table_map", "table_id: 116 (repro_dashboard.shoots)"),
    (15655, "Write_rows", "table_id: 116 flags: STMT_END_F"),
    (15706, "Table_map", "table_id: 118 (repro_dashboard.jobs)"),
    (15775, "Write_rows", "table_id: 118 flags: STMT_END_F"),
    (15834, "Xid", "COMMIT /* xid=329 */"),
    (15865, "Gtid", gtid(E6_UUID, 56)),
    (15944, "Query", "BEGIN"),
    (16015, "Table_map", "table_id: 118 (repro_dashboard.jobs)"),
    (16084, "Delete_rows", "table_id: 118 flags: STMT_END_F"),
    (16143, "Xid", "COMMIT /* xid=333 */"),
    (16174, "Gtid", gtid(E6_UUID, 57)),
    (16253, "Query", "BEGIN"),
    (16324, "Table_map", "table_id: 116 (repro_dashboard.shoots)"),
    (16395, "Write_rows", "table_id: 116 flags: STMT_END_F"),
    (16461, "Xid", "COMMIT /* xid=341 */"),
    (16492, "Gtid", gtid(E6_UUID, 58)),
    (16571, "Query",
     r"REVOKE INSERT, UPDATE, DELETE ON `repro\_dashboard`.* FROM 'repro_app'@'localhost' /* xid=359 */"),
    (16783, "Gtid", gtid(E6_UUID, 59)),
    (16862, "Query",
     "REVOKE UPDATE (`id`) ON `repro_mysql_control`.`writer_token` FROM 'repro_app'@'localhost' /* xid=360 */"),
]
E6_SMOKE_WINDOW = (f"{E6_UUID}:1-54", f"{E6_UUID}:1-56")

# E1 T7, T7b, T7c: the binary log after binlog.000001 9000. GRANT and REVOKE as the gate account typed
# them (GRANT rewritten by the server, REVOKE as typed), app writes (37, 39, 44, 52), the app account
# locked (43, not a lockable account), a control-schema table (47, 48), the heartbeat (51), a
# compressed app write (53: every inner event at the payload's end, 15330) and the migrator's DDL
# without a default database (54).
E1_T7 = [
    (9000, "Gtid", gtid(E1_UUID, 32)),
    (9079, "Query", r"GRANT INSERT, UPDATE, DELETE ON `repro\_dashboard`.* TO 'repro_app'@'localhost' /* xid=120 */"),
    (9288, "Gtid", gtid(E1_UUID, 33)),
    (9367, "Query",
     "GRANT UPDATE (`id`) ON `repro_mysql_control`.`writer_token` TO 'repro_app'@'localhost' /* xid=121 */"),
    (9586, "Gtid", gtid(E1_UUID, 34)),
    (9665, "Query", r"REVOKE INSERT, UPDATE, DELETE ON `repro\_dashboard`.* FROM repro_app@localhost /* xid=126 */"),
    (9873, "Gtid", gtid(E1_UUID, 35)),
    (9952, "Query", "REVOKE UPDATE (id) ON repro_mysql_control.writer_token FROM repro_app@localhost /* xid=127 */"),
    (10164, "Gtid", gtid(E1_UUID, 36)),
    (10243, "Query", r"GRANT INSERT, UPDATE, DELETE ON `repro\_dashboard`.* TO 'repro_app'@'localhost' /* xid=132 */"),
    (10452, "Gtid", gtid(E1_UUID, 37)),
    (10531, "Query", "BEGIN"),
    (10617, "Table_map", "table_id: 111 (repro_dashboard.shoots)"),
    (10688, "Write_rows", "table_id: 111 flags: STMT_END_F"),
    (10741, "Xid", "COMMIT /* xid=138 */"),
    (10772, "Gtid", gtid(E1_UUID, 38)),
    (10851, "Query", r"REVOKE INSERT, UPDATE, DELETE ON `repro\_dashboard`.* FROM repro_app@localhost /* xid=142 */"),
    (11059, "Gtid", gtid(E1_UUID, 39)),
    (11138, "Query", "BEGIN"),
    (11224, "Table_map", "table_id: 111 (repro_dashboard.shoots)"),
    (11295, "Write_rows", "table_id: 111 flags: STMT_END_F"),
    (11361, "Xid", "COMMIT /* xid=145 */"),
    (11392, "Gtid", gtid(E1_UUID, 40)),
    (11471, "Query",
     "GRANT UPDATE (`id`) ON `repro_mysql_control`.`writer_token` TO 'repro_app'@'localhost' /* xid=152 */"),
    (11690, "Gtid", gtid(E1_UUID, 41)),
    (11769, "Query", "REVOKE UPDATE (id) ON repro_mysql_control.writer_token FROM repro_app@localhost /* xid=162 */"),
    (11981, "Gtid", gtid(E1_UUID, 42)),
    (12060, "Query", r"GRANT INSERT, UPDATE, DELETE ON `repro\_dashboard`.* TO 'repro_app'@'localhost' /* xid=183 */"),
    (12269, "Gtid", gtid(E1_UUID, 43)),
    (12346, "Query", "ALTER USER 'repro_app'@'localhost' ACCOUNT LOCK /* xid=190 */"),
    (12476, "Gtid", gtid(E1_UUID, 44)),
    (12555, "Query", "BEGIN"),
    (12626, "Table_map", "table_id: 111 (repro_dashboard.shoots)"),
    (12697, "Write_rows", "table_id: 111 flags: STMT_END_F"),
    (12765, "Xid", "COMMIT /* xid=192 */"),
    (12796, "Gtid", gtid(E1_UUID, 45)),
    (12873, "Query", "ALTER USER 'repro_app'@'localhost' ACCOUNT UNLOCK /* xid=197 */"),
    (13005, "Gtid", gtid(E1_UUID, 46)),
    (13084, "Query", r"REVOKE INSERT, UPDATE, DELETE ON `repro\_dashboard`.* FROM repro_app@localhost /* xid=201 */"),
    (13292, "Gtid", gtid(E1_UUID, 47)),
    (13371, "Query",
     "CREATE TABLE IF NOT EXISTS repro_mysql_control.receipt (id INT PRIMARY KEY, pos BIGINT) /* xid=222 */"),
    (13557, "Gtid", gtid(E1_UUID, 48)),
    (13636, "Query", "BEGIN"),
    (13707, "Table_map", "table_id: 114 (repro_mysql_control.receipt)"),
    (13776, "Write_rows", "table_id: 114 flags: STMT_END_F"),
    (13824, "Xid", "COMMIT /* xid=223 */"),
    (13855, "Gtid", gtid(E1_UUID, 49)),
    (13934, "Query", r"GRANT INSERT, UPDATE, DELETE ON `repro\_dashboard`.* TO 'repro_app'@'localhost' /* xid=233 */"),
    (14143, "Gtid", gtid(E1_UUID, 50)),
    (14222, "Query",
     "GRANT UPDATE (`id`) ON `repro_mysql_control`.`writer_token` TO 'repro_app'@'localhost' /* xid=234 */"),
    (14441, "Gtid", gtid(E1_UUID, 51)),
    (14520, "Query", "BEGIN"),
    (14599, "Table_map", "table_id: 115 (repro_dashboard.repro_recovery_heartbeat)"),
    (14678, "Write_rows", "table_id: 115 flags: STMT_END_F"),
    (14719, "Xid", "COMMIT /* xid=238 */"),
    (14750, "Gtid", gtid(E1_UUID, 52)),
    (14829, "Query", "BEGIN"),
    (14900, "Table_map", "table_id: 111 (repro_dashboard.shoots)"),
    (14971, "Write_rows", "table_id: 111 flags: STMT_END_F"),
    (15025, "Xid", "COMMIT /* xid=247 */"),
    (15056, "Gtid", gtid(E1_UUID, 53)),
    (15135, "Transaction_payload", "compression='ZSTD', decompressed_size=222 bytes"),
    (15330, "Query", "BEGIN"),
    (15330, "Table_map", "table_id: 111 (repro_dashboard.shoots)"),
    (15330, "Write_rows", "table_id: 111 flags: STMT_END_F"),
    (15330, "Xid", "COMMIT /* xid=265 */"),
    (15330, "Gtid", gtid(E1_UUID, 54)),
    (15407, "Query", "ALTER TABLE repro_dashboard.shoots ADD COLUMN extra INT NULL /* xid=277 */"),
]


def escape(value: str) -> bytes:
    """The mysql client's batch-mode escaping (safe_put_field without --raw)."""
    return (value.encode("utf-8").replace(b"\\", b"\\\\").replace(b"\t", b"\\t")
            .replace(b"\n", b"\\n").replace(b"\0", b"\\0"))


def rows(recording, file="binlog.000001", server_id=64086, end=None):
    """SHOW BINLOG EVENTS output for the recorded events, as the client prints it in batch mode."""
    lines = []
    for index, (position, kind, info) in enumerate(recording):
        if index + 1 < len(recording):
            following = recording[index + 1][0]
        else:
            following = end if end is not None else position + 100
        # Inside a compressed transaction every event shows the payload's end as its own position.
        end_position = position if position == following else following
        fields = [file, str(position), kind, str(server_id), str(end_position), info]
        lines.append(b"\t".join(escape(field) for field in fields) + b"\n")
    return b"".join(lines)


def events(recording, file="binlog.000001", server_id=64086):
    return binlog_scan.parse_events(rows(recording, file, server_id), file)


def until(recording, number):
    """The recording up to, not including, the Gtid event of transaction `number`."""
    for index, (_, kind, info) in enumerate(recording):
        if kind == "Gtid" and info.endswith(f":{number}'"):
            return recording[:index]
    raise AssertionError(number)


def between(recording, first, last):
    """Transactions first..last of a recording, inclusive."""
    numbers = [int(info.rsplit(":", 1)[1].rstrip("'")) for _, kind, info in recording if kind == "Gtid"]
    later = [number for number in numbers if number > last]
    stop = len(until(recording, later[0])) if later else len(recording)
    return recording[len(until(recording, first)):stop]


def gtids(transactions):
    return [int(transaction.gtid.rsplit(":", 1)[1]) for transaction in transactions]


def gtids_of(records):
    """The transaction numbers of listed transaction records (the probe's --audit and --since output)."""
    return [int(record["gtid"].rsplit(":", 1)[1]) for record in records]


def synthetic(*entries, start=4, file="binlog.000001"):
    """Events at increasing positions from (type, info) pairs, as 8.4.13 prints them."""
    return [Event(file, start + 100 * index, kind, 64084, start + 100 * (index + 1), info)
            for index, (kind, info) in enumerate(entries)]


def transaction(number, *entries, uuid=E1_UUID):
    return [("Gtid", gtid(uuid, number)), *entries]


def write(table, table_id=111, schema="repro_dashboard"):
    return [("Query", "BEGIN"), ("Table_map", f"table_id: {table_id} ({schema}.{table})"),
            ("Write_rows", f"table_id: {table_id} flags: STMT_END_F"), ("Xid", "COMMIT /* xid=1 */")]


def scan(entries, rules=RULES):
    return binlog_scan.scan(entries, rules)


class BatchFormatTests(unittest.TestCase):
    """The client's batch output, split on newline and tab only, unescaped exactly."""

    def test_rows_unescape_backslash_tab_newline_and_nul(self):
        info = "use `repro_dashboard`; CREATE TABLE ml (\n  id INT,\t-- a \\ and a \\\\ here\n  x CHAR(1) DEFAULT '\0'\n)"
        parsed = events([(4, "Query", info)])
        self.assertEqual([Event("binlog.000001", 4, "Query", 64086, 104, info)], parsed)

    def test_carriage_return_and_control_bytes_stay_inside_their_field(self):
        # The client does not escape \r or other control bytes (a password hash in CREATE USER has them).
        info = "CREATE USER 'x'@'localhost' IDENTIFIED WITH 'caching_sha2_password' AS '$A$005$\x19\r\x0b\\r' /* xid=9 */"
        self.assertEqual(info, events([(4, "Query", info)])[0].info)

    def test_malformed_rows_are_refused(self):
        good = b"binlog.000001\t4\tFormat_desc\t64084\t127\tServer ver: 8.4.13, Binlog ver: 4\n"
        self.assertEqual(1, len(binlog_scan.parse_events(good, "binlog.000001")))
        for raw in (good.replace(b"\t127", b""),                       # five fields
                    good.replace(b"\n", b"\textra\n"),                 # seven fields
                    good.replace(b"\t4\t", b"\tfour\t"),               # position not a number
                    good.replace(b"\t64084\t", b"\t-1\t"),             # server id not a number
                    good.replace(b"Server ver", b"Server \\x ver"),     # an escape the client never prints
                    good.replace(b"4\n", b"4\\\n"),                     # a trailing backslash
                    good[:-1],                                          # no final newline
                    good.replace(b"binlog.000001", b"binlog.000002")):  # another file than asked for
            with self.subTest(raw=raw):
                with self.assertRaises(Unreadable):
                    binlog_scan.parse_events(raw, "binlog.000001")
        self.assertEqual([], binlog_scan.parse_events(b"", "binlog.000001"))

    def test_binary_log_listing_and_status(self):
        listing = (b"binlog.000002\t555\t\t\t" + E6_UUID.encode() + b":1-69:tagone:1\n"
                   b"binlog.000001\t20132\tNo\nbinlog.000002\t555\tNo\n")
        status, logs = binlog_scan.parse_listing(listing)
        self.assertEqual((Coordinate("binlog.000002", 555), f"{E6_UUID}:1-69:tagone:1"), status)
        self.assertEqual([("binlog.000001", 20132), ("binlog.000002", 555)], logs)
        # gtid_executed with several server UUIDs comes with an escaped newline after each comma.
        status, _ = binlog_scan.parse_listing(
            f"binlog.000003\t4\t\t\t{E6_UUID}:1-5,\\n{E1_UUID}:1-9\nbinlog.000003\t4\tNo\n".encode())
        self.assertEqual(f"{E6_UUID}:1-5,\n{E1_UUID}:1-9", status[1])
        for raw in (b"", b"binlog.000001\t20132\tNo\n",                     # no status (binary logging off)
                    listing.replace(b"binlog.000001\t20132", b"binlog.000001\tbig"),
                    listing + b"binlog.000001\t9\tNo\n",                     # a file listed twice
                    listing.replace(b"binlog.000001", b"bin'log.000001"),    # a name that would need quoting
                    listing.replace(b"\t\t\t", b"\t\t")):                   # status with four fields
            with self.subTest(raw=raw):
                with self.assertRaises(Unreadable):
                    binlog_scan.parse_listing(raw)


class E6RecordingTests(unittest.TestCase):
    """Design 1.2 on E6's binary log: the foreign split around the first account statement."""

    def test_e6_from_the_receipt(self):
        result = scan(events(E6_G6))
        self.assertEqual(len(E6_G6), result.events)
        self.assertEqual({"file": "binlog.000001", "position": 14927, "gtid": f"{E6_UUID}:53"}, result.boundary)
        self.assertEqual([49], gtids(result.foreign))
        self.assertEqual([55, 56, 57], gtids(result.business))
        self.assertTrue(result.foreign_activity)
        self.assertTrue(result.business_activity)
        self.assertEqual({"gtid": f"{E6_UUID}:55", "file": "binlog.000001", "position": 15434,
                          "tables": ["jobs", "shoots"], "statements": [], "class": "business"},
                         result.business[0].record())

    def test_app_schema_write_before_boundary_is_foreign(self):
        result = scan(events(until(E6_G6, 53)))
        self.assertIsNone(result.boundary)
        self.assertEqual([49], gtids(result.foreign))
        self.assertEqual([], result.business)
        self.assertTrue(result.foreign_activity)
        self.assertFalse(result.business_activity)

    def test_grant_alone_is_a_boundary_without_business_activity(self):
        # writes_possible and publication: the GRANT is a boundary, but nothing writes until the reopen.
        result = scan(events(between(E6_G6, 53, 54)))
        self.assertEqual(f"{E6_UUID}:53", result.boundary["gtid"])
        self.assertEqual(([], []), (result.foreign, result.business))
        self.assertFalse(result.business_activity)

    def test_account_lock_of_lockable_account_is_not_a_boundary(self):
        locks = between(E6_G6, 50, 51)
        self.assertIsNone(scan(events(locks)).boundary)
        # The same statements for accounts outside lockable_accounts are boundaries.
        strict = Rules(RULES.database, RULES.excluded_tables, frozenset())
        self.assertEqual(f"{E6_UUID}:50", scan(events(locks), strict).boundary["gtid"])
        # E1 locks the app account itself, which is never lockable.
        self.assertEqual(f"{E1_UUID}:43", scan(events(between(E1_T7, 43, 43), server_id=64084)).boundary["gtid"])
        # A write after the lockable accounts' locks is still foreign: they are not a boundary.
        result = scan(events(between(E6_G6, 50, 51) + between(E6_G6, 49, 49)))
        self.assertEqual(([49], []), (gtids(result.foreign), gtids(result.business)))

    def test_heartbeat_table_excluded(self):
        heartbeat = between(E6_G6, 52, 52)
        result = scan(events(heartbeat))
        self.assertEqual((0, False, False), (len(result.transactions), result.foreign_activity,
                                             result.business_activity))
        counted = Rules(RULES.database, frozenset(), RULES.lockable_accounts)
        self.assertEqual([52], gtids(scan(events(heartbeat), counted).foreign))
        # After the boundary too: the heartbeat between the GRANT and the smoke is never business.
        self.assertNotIn(52, gtids(scan(events(E6_G6)).business))

    def test_compressed_transaction_counts(self):
        compressed = between(E1_T7, 53, 53)
        self.assertEqual(4, sum(1 for position, _, _ in compressed if position == 15330))
        parsed = events(compressed, server_id=64084)
        self.assertEqual("Transaction_payload", parsed[1].type)
        result = scan(parsed)
        self.assertEqual([53], gtids(result.foreign))
        self.assertEqual(["shoots"], result.foreign[0].record()["tables"])

    def test_e1_grants_unquoted_revokes_and_ddl_without_default_database(self):
        result = scan(events(E1_T7, server_id=64084))
        self.assertEqual(f"{E1_UUID}:32", result.boundary["gtid"])
        self.assertEqual([], result.foreign)
        # 47 and 48 write the control schema and 51 the heartbeat: neither is the app schema.
        self.assertEqual([37, 39, 44, 52, 53, 54], gtids(result.business))
        self.assertEqual(["query"], result.business[-1].record()["statements"])


class PagingTests(unittest.TestCase):
    """SHOW BINLOG EVENTS IN f FROM p LIMIT offset, n: offset paging from one fixed position."""

    @staticmethod
    def pager(files: dict):
        calls = []

        def fetch(name, position, offset, count):
            calls.append((name, position, offset, count))
            listed = files[name]
            if position is not None:
                # FROM p starts at the event that begins at byte p. The events inside a compressed
                # transaction only show the payload's end as their position: FROM that position starts
                # at the next real event, after them (as 8.4.13 does).
                inner, end = set(), None
                for index, event in enumerate(listed):
                    if event.type == "Transaction_payload":
                        end = event.end_position
                    elif event.type in binlog_scan.GTID_EVENTS:
                        end = None
                    elif end is not None and event.position == end:
                        inner.add(index)
                first = next((index for index, event in enumerate(listed)
                              if index not in inner and event.position >= position), len(listed))
                listed = listed[first:]
            return listed[offset:offset + count]

        return fetch, calls

    def test_paging_by_offset_across_pages(self):
        recorded = events(E1_T7, server_id=64084)
        expected = scan(recorded)
        for page in (1, 2, 3, 4, 5, 7, 500):
            with self.subTest(page=page):
                fetch, calls = self.pager({"binlog.000001": recorded})
                walked = list(binlog_scan.walk(fetch, ["binlog.000001"], Coordinate("binlog.000001", 9000), page))
                self.assertEqual(recorded, walked)
                self.assertEqual(expected, scan(walked))
                # Every page asks from the receipt's position, never from a position inside a payload.
                self.assertEqual({("binlog.000001", 9000)}, {(name, position) for name, position, _, _ in calls})
                self.assertEqual(list(range(0, page * len(calls), page)), [offset for _, _, offset, _ in calls])
                # Pages until the first short one.
                self.assertEqual(len(recorded) // page + 1, len(calls))

    def test_payload_split_across_a_page_boundary(self):
        recorded = events(E1_T7, server_id=64084)
        index = next(index for index, event in enumerate(recorded) if event.type == "Transaction_payload")
        fetch, _ = self.pager({"binlog.000001": recorded})
        # A page that ends right after the payload event, before its inner events.
        walked = list(binlog_scan.walk(fetch, ["binlog.000001"], Coordinate("binlog.000001", 9000), index + 1))
        self.assertIn(53, gtids(scan(walked).business))

    def test_walk_follows_every_listed_file_after_the_start(self):
        first = events(until(E6_G6, 53), file="binlog.000001")
        second = events(between(E6_G6, 53, 59), file="binlog.000002")
        earlier = events(between(E1_T7, 37, 37), file="binlog.000000", server_id=64084)
        fetch, calls = self.pager({"binlog.000000": earlier, "binlog.000001": first, "binlog.000002": second})
        walked = list(binlog_scan.walk(fetch, ["binlog.000000", "binlog.000001", "binlog.000002"],
                                       Coordinate("binlog.000001", 13791), 4))
        self.assertEqual(first + second, walked)
        self.assertNotIn("binlog.000000", [name for name, _, _, _ in calls])
        # Later files are read from their start (no FROM).
        self.assertEqual({None}, {position for name, position, _, _ in calls if name == "binlog.000002"})
        result = scan(walked)
        self.assertEqual(([49], [55, 56, 57]), (gtids(result.foreign), gtids(result.business)))
        self.assertEqual("binlog.000002", result.boundary["file"])

    def test_walk_refuses_a_start_that_is_not_listed_or_a_first_event_elsewhere(self):
        recorded = events(E6_G6)
        fetch, _ = self.pager({"binlog.000001": recorded})
        with self.assertRaises(Unreadable):
            list(binlog_scan.walk(fetch, ["binlog.000001"], Coordinate("binlog.000002", 4), 500))

        def shifted(name, position, offset, count):
            return recorded[1:][offset:offset + count]

        with self.assertRaises(Unreadable):
            list(binlog_scan.walk(shifted, ["binlog.000001"], Coordinate("binlog.000001", 13791), 500))

        def oversized(name, position, offset, count):
            return recorded[:count + 1]

        with self.assertRaises(Unreadable):
            list(binlog_scan.walk(oversized, ["binlog.000001"], Coordinate("binlog.000001", 13791), 3))


class ClassificationTests(unittest.TestCase):
    """Each 8.4.13 event form: boundary, app-schema event or neither (design 1.2)."""

    def assert_app(self, *entries, foreign=True, statements=None):
        result = scan(synthetic(*transaction(70, *entries)))
        self.assertEqual(1, len(result.transactions), entries)
        record = result.transactions[0].record()
        if foreign is not None:
            self.assertEqual("foreign" if foreign else "business", record["class"], record)
        if statements is not None:
            self.assertEqual(statements, record["statements"])
        return record

    def assert_neither(self, *entries):
        result = scan(synthetic(*transaction(70, *entries)))
        self.assertEqual(([], None), (result.transactions, result.boundary), entries)

    def assert_boundary(self, *entries):
        result = scan(synthetic(*transaction(70, *entries)))
        self.assertIsNotNone(result.boundary, entries)
        return result

    def test_query_with_app_default_database_counts(self):
        self.assert_app(("Query", "use `repro_dashboard`; CREATE TABLE ml (\n  id INT PRIMARY KEY\n) /* xid=99 */"),
                        statements=["query"])
        self.assert_app(("Query", "BEGIN"), ("Intvar", "INSERT_ID=2"),
                        ("RAND", "rand_seed1=144073730,rand_seed2=802497215"),
                        ("Query", "use `repro_dashboard`; INSERT INTO jobs (queue) VALUES (CONCAT('x', RAND()))"),
                        ("Xid", "COMMIT /* xid=184 */"), statements=["query"])
        self.assert_app(("Query", "use `REPRO_DASHBOARD`; DROP TABLE x /* xid=1 */"), statements=["query"])
        self.assert_app(("Query", "use `repro_dashboard`; SAVEPOINT `trans2`"), statements=["query"])

    def test_statement_naming_the_app_schema_counts(self):
        for text in ("ALTER TABLE repro_dashboard.shoots ADD COLUMN extra INT NULL /* xid=277 */",
                     "INSERT INTO repro_dashboard.jobs (queue) VALUES ('stmt')",
                     "ANALYZE TABLE repro_dashboard.shoots",
                     "DROP DATABASE `repro_dashboard` /* xid=5 */",
                     r"CREATE TABLE other.t (id INT) COMMENT 'repro\_dashboard' /* xid=6 */",
                     "use `other_db`; CREATE TABLE t2 LIKE repro_dashboard.shoots /* xid=296 */",
                     "CREATE DEFINER=`root`@`localhost` TRIGGER other.t AFTER INSERT ON other.x FOR EACH ROW "
                     "INSERT INTO Repro_Dashboard.y VALUES (1)"):
            with self.subTest(text=text):
                self.assert_app(("Query", text), statements=["query"])

    def test_transaction_control_and_xa_statements_do_not_count(self):
        self.assert_neither(("Query", "BEGIN"), ("Xid", "COMMIT /* xid=1 */"))
        self.assert_neither(("Query", "BEGIN"), ("Query", "COMMIT"))
        self.assert_neither(("Query", "BEGIN"), ("Query", "ROLLBACK"))
        self.assert_neither(("Query", "XA COMMIT X'7831',X'',1"))
        # The XA's own control statements are neutral; the table it writes is not.
        record = self.assert_app(("Query", "XA START X'7831',X'',1"),
                                 ("Table_map", "table_id: 111 (repro_dashboard.shoots)"),
                                 ("Write_rows", "table_id: 111 flags: STMT_END_F"),
                                 ("Query", "XA END X'7831',X'',1"), ("XA_prepare", "XA PREPARE X'7831',X'',1"),
                                 statements=[])
        self.assertEqual(["shoots"], record["tables"])

    def test_other_schemas_rows_query_and_file_events_do_not_count(self):
        self.assert_neither(*write("t1", 120, "other_db"))
        self.assert_neither(*write("writer_token", 7, "repro_mysql_control"))
        self.assert_neither(("Query", "CREATE DATABASE IF NOT EXISTS other_db /* xid=291 */"))
        self.assert_neither(("Query", "use `other_db`; CREATE TABLE t1 (id INT PRIMARY KEY) /* xid=294 */"))
        # Rows_query repeats the statement for information; the Table_map decides (here the heartbeat).
        self.assert_neither(("Query", "BEGIN"), ("Rows_query", "# DELETE FROM repro_dashboard.repro_recovery_heartbeat"),
                            ("Table_map", "table_id: 113 (repro_dashboard.repro_recovery_heartbeat)"),
                            ("Delete_rows", "table_id: 113 flags: STMT_END_F"), ("Xid", "COMMIT /* xid=311 */"))
        result = scan(synthetic(("Format_desc", "Server ver: 8.4.13, Binlog ver: 4"),
                                ("Previous_gtids", f"{E1_UUID}:1-68:tagone:1"),
                                ("Rotate", "binlog.000002;pos=4"), ("Stop", "")))
        self.assertEqual(([], None, 4), (result.transactions, result.boundary, result.events))

    def test_every_account_statement_is_a_boundary(self):
        for text in (r"GRANT INSERT ON `repro\_dashboard`.* TO 'repro_app'@'localhost' /* xid=1 */",
                     "GRANT 'r1' TO 'repro_maint'@'localhost' /* xid=116 */",
                     r"/* lead */ REVOKE INSERT ON `repro\_dashboard`.* FROM repro_app@localhost /* xid=130 */",
                     "-- note\nrevoke 'r1' from 'repro_maint'@'localhost'",
                     "# note\nREVOKE 'r1' FROM 'repro_maint'@'localhost' /* xid=118 */",
                     "CREATE USER 'tmpu'@'localhost' IDENTIFIED WITH 'caching_sha2_password' AS '$A$005$x' /* xid=123 */",
                     "ALTER USER 'tmpu'@'localhost' IDENTIFIED WITH 'caching_sha2_password' AS '$A$005$y' /* xid=124 */",
                     "RENAME USER 'tmpu'@'localhost' TO 'tmpv'@'localhost' /* xid=125 */",
                     "DROP USER 'tmpv'@'localhost' /* xid=126 */",
                     "CREATE ROLE 'r1' /* xid=115 */", "DROP ROLE 'r1' /* xid=119 */",
                     "SET DEFAULT ROLE ALL TO 'repro_maint'@'localhost' /* xid=117 */",
                     "SET PASSWORD FOR 'repro_app'@'localhost' = 'x' /* xid=3 */",
                     "use `repro_dashboard`; GRANT SELECT ON `other_db`.* TO 'repro_maint'@'localhost' /* xid=302 */",
                     "ALTER USER 'repro_maint'@'localhost' ACCOUNT LOCK, 'repro_app'@'localhost' ACCOUNT LOCK"):
            with self.subTest(text=text):
                result = self.assert_boundary(("Query", text))
                # An account statement names no app data: the text about `repro\_dashboard` is not a write.
                self.assertEqual([], result.transactions)

    def test_grant_table_rows_and_flush_privileges_are_boundaries(self):
        # Beyond the design's list: a direct edit of the grant tables reaches the app account too.
        for table in ("user", "db", "tables_priv", "columns_priv", "procs_priv", "proxies_priv",
                      "global_grants", "role_edges", "default_roles"):
            with self.subTest(table=table):
                self.assert_boundary(("Query", "BEGIN"), ("Table_map", f"table_id: 84 (mysql.{table})"),
                                     ("Update_rows", "table_id: 84 flags: STMT_END_F"), ("Xid", "COMMIT /* xid=134 */"))
        for text in ("FLUSH PRIVILEGES", "flush privileges", "FLUSH NO_WRITE_TO_BINLOG PRIVILEGES",
                     "FLUSH STATUS, PRIVILEGES"):
            with self.subTest(text=text):
                self.assert_boundary(("Query", text))
        self.assert_neither(*write("time_zone", 85, "mysql"))
        self.assert_neither(("Query", "FLUSH TABLES"))

    def test_lock_exemption_is_exact(self):
        for text in ("ALTER USER 'repro_maint'@'localhost' ACCOUNT LOCK /* xid=103 */",
                     "ALTER USER 'repro_migrator'@'localhost' ACCOUNT UNLOCK /* xid=111 */",
                     "use `repro_dashboard`; ALTER USER 'repro_migrator'@'localhost' ACCOUNT LOCK /* xid=4 */"):
            with self.subTest(text=text):
                self.assert_neither(("Query", text))
        for text in ("ALTER USER 'repro_app'@'localhost' ACCOUNT LOCK /* xid=190 */",
                     "ALTER USER 'repro_parity_reader'@'localhost' ACCOUNT LOCK /* xid=2 */",
                     "ALTER USER 'repro_maint'@'%' ACCOUNT LOCK /* xid=2 */",
                     "ALTER USER 'repro_maint'@'localhost' IDENTIFIED BY 'x' ACCOUNT LOCK",
                     "ALTER USER 'repro_maint'@'localhost' ACCOUNT LOCK PASSWORD EXPIRE",
                     "/* x */ ALTER USER 'repro_maint'@'localhost' ACCOUNT LOCK",
                     "alter user 'repro_maint'@'localhost' account lock"):
            with self.subTest(text=text):
                self.assert_boundary(("Query", text))

    def test_unknown_event_type_and_incident_count(self):
        for kind, info, statement in (("Incident", "#1 (LOST_EVENTS)", "incident"),
                                      ("Execute_load_query", "LOAD DATA INFILE 'x' INTO TABLE t", "event Execute_load_query"),
                                      ("Heartbeat", "", "event Heartbeat"),
                                      ("View_change", "view_id=1", "event View_change")):
            with self.subTest(kind=kind):
                self.assert_app((kind, info), statements=[statement])

    def test_unreadable_info_counts(self):
        for entries, statement in (
                ([("Table_map", "table_id: x (repro_dashboard.shoots)")], "unreadable Table_map"),
                ([("Table_map", "table_id: 5 repro_dashboard.shoots")], "unreadable Table_map"),
                ([("Write_rows", "table_id: 5 flags: STMT_END_F")], "rows without a table map"),
                ([("Write_rows", "table_id: 5 flags: OTHER")], "unreadable Write_rows"),
                ([("Query", "use repro_dashboard; INSERT INTO x VALUES (1)")], "unreadable Query"),
                ([("Query", "/*!80000 GRANT ALL ON *.* TO 'repro_app'@'localhost' */")], "unreadable Query"),
                ([("Query", "/* never closed GRANT ALL ON *.* TO x")], "unreadable Query")):
            with self.subTest(entries=entries):
                # Some of these are also boundaries (test_executable_comment_is_also_a_boundary).
                self.assert_app(*entries, foreign=None, statements=[statement])

    def test_executable_comment_is_also_a_boundary(self):
        # It may hide an account statement as well as a write: both, so later writes are business.
        result = scan(synthetic(*transaction(70, ("Query", "/*!80000 GRANT ALL ON *.* TO 'repro_app'@'localhost' */")),
                                *transaction(71, *write("shoots"))))
        self.assertIsNotNone(result.boundary)
        self.assertEqual(["business", "business"], [item.record()["class"] for item in result.transactions])

    def test_rows_event_names_the_table_its_own_transaction_mapped(self):
        # A table id mapped in an earlier transaction does not cover a rows event in this one.
        result = scan(synthetic(*transaction(70, ("Query", "BEGIN"), ("Table_map", "table_id: 9 (other.t)"),
                                             ("Write_rows", "table_id: 9 flags: STMT_END_F"), ("Xid", "COMMIT")),
                                *transaction(71, ("Query", "BEGIN"), ("Write_rows", "table_id: 9 flags: STMT_END_F"),
                                             ("Xid", "COMMIT"))))
        self.assertEqual(["rows without a table map"], result.transactions[0].record()["statements"])
        self.assertEqual(f"{E1_UUID}:71", result.transactions[0].gtid)
        # Several rows events of one statement, only the last flagged, all on a mapped table.
        self.assert_app(("Query", "BEGIN"), ("Table_map", "table_id: 112 (repro_dashboard.jobs)"),
                        *[("Write_rows", "table_id: 112")] * 3, ("Write_rows", "table_id: 112 flags: STMT_END_F"),
                        ("Xid", "COMMIT /* xid=331 */"), statements=[])

    def test_tagged_and_anonymous_gtids_start_a_transaction(self):
        result = scan(synthetic(*transaction(70, *write("shoots")),
                                ("Gtid_tagged_log_event", f"SET @@SESSION.GTID_NEXT= '{E1_UUID}:tagone:1'"),
                                *write("repro_recovery_heartbeat", 113),
                                ("Anonymous_Gtid", "SET @@SESSION.GTID_NEXT= 'ANONYMOUS'"), *write("jobs", 112)))
        self.assertEqual([f"{E1_UUID}:70", "ANONYMOUS"], [item.gtid for item in result.transactions])
        unreadable = scan(synthetic(("Gtid", "SET @@SESSION.GTID_NEXT= 49"), ("Query", "BEGIN")))
        self.assertEqual([(None, ["unreadable Gtid"])],
                         [(item.gtid, item.statements) for item in unreadable.transactions])

    def test_app_event_outside_a_transaction_counts(self):
        result = scan(synthetic(("Query", "INSERT INTO repro_dashboard.jobs (queue) VALUES ('x')")))
        self.assertEqual([(None, ["query"])], [(item.gtid, item.statements) for item in result.transactions])

    def test_boundary_in_the_same_transaction_makes_it_business(self):
        result = scan(synthetic(*transaction(70, ("Query", "BEGIN"),
                                             ("Table_map", "table_id: 9 (repro_dashboard.shoots)"),
                                             ("Write_rows", "table_id: 9 flags: STMT_END_F"),
                                             ("Table_map", "table_id: 84 (mysql.db)"),
                                             ("Update_rows", "table_id: 84 flags: STMT_END_F"), ("Xid", "COMMIT"))))
        self.assertEqual(["business"], [item.record()["class"] for item in result.transactions])

    def test_a_new_file_ends_the_open_transaction(self):
        first = synthetic(*transaction(70, ("Query", "BEGIN"), ("Table_map", "table_id: 9 (repro_dashboard.shoots)")))
        second = synthetic(("Write_rows", "table_id: 9 flags: STMT_END_F"), file="binlog.000002")
        result = scan(first + second)
        self.assertEqual([(f"{E1_UUID}:70", ["shoots"], []), (None, [], ["rows without a table map"])],
                         [(item.gtid, sorted(item.tables), item.statements) for item in result.transactions])


class GtidSetTests(unittest.TestCase):
    def test_parse_and_membership(self):
        text = f"{E6_UUID}:1-54:56,\n{E1_UUID}:1-9:tagone:1-3:7"
        parsed = GtidSet.parse(text)
        for member in (f"{E6_UUID}:1", f"{E6_UUID}:54", f"{E6_UUID}:56", f"{E1_UUID}:9", f"{E1_UUID}:tagone:2",
                       f"{E1_UUID}:tagone:7"):
            self.assertIn(member, parsed)
        for other in (f"{E6_UUID}:55", f"{E6_UUID}:57", f"{E1_UUID}:10", f"{E1_UUID}:tagone:4", f"{E1_UUID}:other:1",
                      "ANONYMOUS", None):
            self.assertNotIn(other, parsed)
        self.assertNotIn(f"{E6_UUID}:1", GtidSet.parse(""))

    def test_subset(self):
        self.assertTrue(GtidSet.parse(f"{E6_UUID}:1-48").issubset(GtidSet.parse(f"{E6_UUID}:1-59")))
        self.assertTrue(GtidSet.parse("").issubset(GtidSet.parse(f"{E6_UUID}:1-59")))
        self.assertTrue(GtidSet.parse(f"{E6_UUID}:3-4:9").issubset(GtidSet.parse(f"{E6_UUID}:1-5:7-12")))
        self.assertFalse(GtidSet.parse(f"{E6_UUID}:1-48").issubset(GtidSet.parse(f"{E6_UUID}:2-59")))
        self.assertFalse(GtidSet.parse(f"{E6_UUID}:1-48").issubset(GtidSet.parse(f"{E1_UUID}:1-59")))
        self.assertFalse(GtidSet.parse(f"{E6_UUID}:t:1").issubset(GtidSet.parse(f"{E6_UUID}:1")))

    def test_malformed_sets_refused(self):
        for text in (f"{E6_UUID}", f"{E6_UUID}:", f"{E6_UUID}:0", f"{E6_UUID}:5-3", f"{E6_UUID}:1-", "nope:1",
                     f"{E6_UUID.upper()}:1", f"{E6_UUID}:1,,", f"{E6_UUID}:Tag:1", f"{E6_UUID}:1 2",
                     f"{E6_UUID}:{'t' * 33}:1"):
            with self.subTest(text=text):
                with self.assertRaises(Unreadable):
                    GtidSet.parse(text)


class AuditTests(unittest.TestCase):
    """4.3: every app-schema transaction after armed_from as foreign, smoke or business."""

    @staticmethod
    def window(before, after):
        return GtidSet.parse(before), GtidSet.parse(after)

    def test_audit_classifies_foreign_smoke_and_business(self):
        listed = binlog_scan.audit(scan(events(E6_G6)), SMOKE_TABLES, self.window(*E6_SMOKE_WINDOW))
        self.assertEqual([(f"{E6_UUID}:49", "foreign", ["shoots"]), (f"{E6_UUID}:55", "smoke", ["jobs", "shoots"]),
                          (f"{E6_UUID}:56", "smoke", ["jobs"]), (f"{E6_UUID}:57", "business", ["shoots"])],
                         [(item["gtid"], item["class"], item["tables"]) for item in listed])

    def test_window_transaction_outside_smoke_tables_or_with_ddl_is_business(self):
        listed = binlog_scan.audit(scan(events(E6_G6)), frozenset({"shoots"}), self.window(*E6_SMOKE_WINDOW))
        self.assertEqual(["foreign", "business", "business", "business"], [item["class"] for item in listed])
        ddl = synthetic(("Gtid", gtid(E6_UUID, 53)), ("Query", r"GRANT INSERT ON `repro\_dashboard`.* TO x"),
                        ("Gtid", gtid(E6_UUID, 55)),
                        ("Query", "use `repro_dashboard`; ALTER TABLE shoots ADD COLUMN y INT /* xid=1 */"))
        self.assertEqual(["business"], [item["class"] for item in
                                        binlog_scan.audit(scan(ddl), SMOKE_TABLES, self.window(*E6_SMOKE_WINDOW))])

    def test_without_a_window_every_business_transaction_is_business(self):
        listed = binlog_scan.audit(scan(events(E6_G6)), SMOKE_TABLES, None)
        self.assertEqual(["foreign", "business", "business", "business"], [item["class"] for item in listed])

    def test_window_is_after_minus_before(self):
        # 55 was already in gtid_before: it is not part of the smoke, so it stays business.
        listed = binlog_scan.audit(scan(events(E6_G6)), SMOKE_TABLES, self.window(f"{E6_UUID}:1-55", f"{E6_UUID}:1-56"))
        self.assertEqual(["foreign", "business", "smoke", "business"], [item["class"] for item in listed])

    def test_window_never_turns_a_foreign_transaction_into_smoke(self):
        listed = binlog_scan.audit(scan(events(E6_G6)), SMOKE_TABLES, self.window("", f"{E6_UUID}:1-56"))
        self.assertEqual(["foreign", "smoke", "smoke", "business"], [item["class"] for item in listed])


if __name__ == "__main__":
    unittest.main()
