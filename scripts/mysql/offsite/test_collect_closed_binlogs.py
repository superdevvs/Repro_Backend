"""Local query parsing, bounded selection and closed-file staging fixtures."""
import os
from pathlib import Path
import socket
import unittest
from unittest.mock import patch

import collect_closed_binlogs as collector
import publish_backup as backup
from test_advance_binlog_checkpoint import ArchiveFixture
from test_verify_mysql_binlog_chain import DATABASE, SERVER_ID, UUID, segment


def fixture_plan():
    return {
        "version": 1, "release": "a" * 40, "identity": UUID, "database": DATABASE,
        "server_id": SERVER_ID, "crypt_remote": "fixture-crypt", "checkpoint_dir": "/private/checkpoint",
        "base_receipt": "/private/producer.json", "base_receipt_sha256": "b" * 64,
        "spool_dir": "/private/spool", "mysql": "/usr/bin/mysql", "defaults_file": "/private/client.cnf",
        "socket": "/private/mysql.sock", "binlog_base": "/private/mysql/binlog", "mysql_owner_uid": 1000,
        "server_version": "8.4.13", "max_files": 2, "max_batch_bytes": 4096, "query_timeout_seconds": 10,
    }


def catalog_output(plan, entries, *, active=None, final_active=None, extra=None):
    active = active or entries[-1][0]
    identity = ["archive-source", plan["server_version"], plan["identity"], str(plan["server_id"]),
                "1", "1", "CRC32", "0", plan["binlog_base"]]
    lines = [
        identity, [active, "4", "", "", ""],
        *([name, str(size), "No"] for name, size in entries),
        [final_active or active, "9", "", "", ""], extra or identity,
    ]
    return ("\n".join("\t".join(row) for row in lines) + "\n").encode()


class CollectorPolicyTests(unittest.TestCase):
    def setUp(self):
        self.plan = fixture_plan()
        self.entries = [("binlog.000041", 256), ("binlog.000042", 315), ("binlog.000043", 277)]

    def test_pinned_plan_rejects_drift_and_unsafe_limits(self):
        raw = backup.encode(self.plan)
        self.assertEqual(collector.parse_plan(raw, backup.sha256(raw)), self.plan)
        with self.assertRaisesRegex(backup.Refusal, "pin"):
            collector.parse_plan(raw, "0" * 64)
        for changes in ({"socket": "/private/other/../mysql.sock"}, {"max_files": 0},
                        {"mysql_owner_uid": 0}, {"server_version": "8.0.46"}, {"max_batch_bytes": 17 * 1024**3}):
            with self.subTest(changes=changes), self.assertRaises(backup.Refusal):
                raw = backup.encode(self.plan | changes)
                collector.parse_plan(raw, backup.sha256(raw))

    def test_catalog_refuses_gap_rotation_source_and_unexpected_logging(self):
        raw = catalog_output(self.plan, self.entries)
        self.assertEqual(collector.parse_catalog(raw, self.plan), (self.entries[-1][0], self.entries))
        for changed in (
            catalog_output(self.plan, self.entries[::2]),
            catalog_output(self.plan, self.entries, final_active="binlog.000044"),
            raw.replace(b"8.4.13", b"8.0.46"),
            raw.replace(b"\tCRC32\t0\t", b"\tNONE\t0\t"),
            raw.replace(b"\tNo\n", b"\tYes\n"),
            raw.replace(b"\t1\tCRC32", b"\t0\tCRC32"),
            raw.replace(b"/private/mysql/binlog", b"/other/source/binlog"),
        ):
            with self.subTest(changed=changed), self.assertRaises(backup.Refusal):
                collector.parse_catalog(changed, self.plan)

    def test_selection_bounds_whole_files_without_copying_active_log(self):
        active = self.entries[-1][0]
        first = self.entries[0][0]
        self.assertEqual(collector.choose_batch(self.entries, active, first, 1, 4096), self.entries[:1])
        self.assertEqual(collector.choose_batch(self.entries, active, first, 3, 500), self.entries[:1])
        self.assertEqual(collector.choose_batch(self.entries, active, active, 3, 4096), [])
        with self.assertRaisesRegex(backup.Refusal, "exceeds"):
            collector.choose_batch(self.entries, active, first, 3, 200)
        with self.assertRaisesRegex(backup.Refusal, "missing"):
            collector.choose_batch(self.entries, active, "binlog.000040", 3, 4096)


@unittest.skipUnless(os.name == "posix", "private local spool and socket fixtures require Linux")
class CollectorStagingTests(ArchiveFixture):
    def setUp(self):
        super().setUp()
        self.spool = self.root / "spool"
        logs = self.root / "logs"
        for path in (self.spool, logs):
            path.mkdir(mode=0o700)
        credentials = self.root / "client.cnf"
        credentials.write_text("[client]\nuser=archive_fixture\npassword=synthetic-fixture-only\n")
        listener = socket.socket(socket.AF_UNIX)
        self.addCleanup(listener.close)
        local_socket = self.root / "s"
        listener.bind(str(local_socket))
        self.plan = fixture_plan() | {
            "checkpoint_dir": str(self.state), "spool_dir": str(self.spool), "base_receipt": str(self.base_receipt),
            "base_receipt_sha256": self.binding["base_receipt_sha256"], "defaults_file": str(credentials),
            "binlog_base": str(logs / "binlog"), "socket": str(local_socket), "mysql_owner_uid": os.geteuid(),
        }
        self.entries = []
        for number in (41, 42):
            name = f"binlog.{number:06d}"
            raw = segment(f"binlog.{number + 1:06d}")[0]
            (logs / name).write_bytes(raw)
            self.entries.append((name, len(raw)))
        self.active = "binlog.000043"
        # The active file deliberately does not exist in the byte source fixture.
        self.entries.append((self.active, 128))
        collector.checkpoint.initialise(self.state, self.binding)
        self.target = self.spool / "collected"

    def test_collects_and_stages_exact_closed_bytes_without_advancing_checkpoint(self):
        with patch.object(collector, "catalog", return_value=(self.active, self.entries)) as catalogs:
            report = collector.collect(self.plan, self.target)
        self.assertEqual(catalogs.call_count, 2)
        self.assertEqual((report["first_file"], report["next_file"], report["segments"]), (self.base, self.active, 2))
        self.assertFalse(report["archive_publication_verified"])
        self.assertEqual(collector.checkpoint.check(self.state, self.binding)["next_file"], self.base)
        self.assertEqual({item.name for item in self.target.iterdir()}, {self.base, "binlog.000042",
                         collector.staging.INVENTORY, collector.staging.OUTPUT})
        self.assertEqual({item.name for item in self.spool.iterdir()}, {"collected"})

    def test_idle_and_source_drift_never_expose_partial_lot(self):
        with patch.object(collector, "catalog", return_value=(self.base, [(self.base, 128)])):
            report = collector.collect(self.plan, self.target)
        self.assertEqual(report["result"], "closed-binlog-collection-idle")
        self.assertFalse(self.target.exists())
        with patch.object(collector, "catalog", side_effect=[(self.active, self.entries),
                          (self.active, self.entries[1:])]):
            with self.assertRaisesRegex(backup.Refusal, "changed during collection"):
                collector.collect(self.plan, self.target)
        self.assertEqual(list(self.spool.iterdir()), [])


if __name__ == "__main__":
    unittest.main()
