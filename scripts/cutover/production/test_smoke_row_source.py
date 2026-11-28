"""Read-only native smoke attribution and safe fallback to full recovery."""
import hashlib
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import binlog_scan
import gate
import mysql_probe
import smoke_row_source as source

UUID = "189fbdff-3fdd-48a8-8da4-655a96df30de"
NAME = "binlog.000013"


class RowSourceTests(unittest.TestCase):
    def setUp(self):
        self.config = {"mysql": {"target_uuid": UUID, "database": "sample", "smoke_tables": list(source.TABLES)}}
        self.window = (binlog_scan.GtidSet.parse(f"{UUID}:1-12"), binlog_scan.GtidSet.parse(f"{UUID}:1-16"),
                       {"gtid_before": f"{UUID}:1-12", "gtid_after": f"{UUID}:1-16",
                        "report": {"checks": {"value": "marker", "client_id": 1, "shoot_id": 2}}})
        self.listed = [{"class": "smoke", "file": NAME, "position": pos, "gtid": f"{UUID}:{13 + idx}"}
                       for idx, pos in enumerate([456, 560, 680, 779])]
        self.events = b"".join(f"{NAME}\t{pos}\t{kind}\t44\t{end}\tdata\n".encode()
                               for (pos, end, kind) in [(779, 800, "Gtid"), (800, 840, "Query"),
                                                        (840, 862, "Table_map"), (862, 888, "Delete_rows"),
                                                        (888, 920, "Xid"), (920, 931, "Gtid")])

    def test_bounds_are_first_start_and_last_commit_on_the_same_file_only(self):
        with patch.object(mysql_probe, "_show", return_value=self.events) as query:
            ids, name, start, stop = source.coordinates(self.config, self.window, self.listed)
        self.assertEqual((NAME, 456, 920), (name, start, stop))
        self.assertEqual(tuple(item["gtid"] for item in self.listed), ids)
        self.assertIn("FROM 779 LIMIT 32", query.call_args.args[1])

    def test_unknown_extra_gtid_rotated_file_position_gaps_and_partial_last_commit_refuse(self):
        extra = (self.window[0], binlog_scan.GtidSet.parse(f"{UUID}:1-17"), self.window[2])
        other = [*self.listed[:-1], {**self.listed[-1], "file": "binlog.000014"}]
        for window, listed, events in ((extra, self.listed, self.events), (self.window, other, self.events),
                                       (self.window, self.listed, self.events.replace(b"\tXid\t", b"\tGtid\t")),
                                       (self.window, self.listed, self.events.replace(b"\t862\tDelete_rows", b"\t870\tDelete_rows")),
                                       (self.window, self.listed, self.events.replace(b"\t920\tdata", b"\t9999999\tdata"))):
            with self.subTest(window=window, events=events), patch.object(mysql_probe, "_show", return_value=events):
                with self.assertRaises(source.Unavailable):
                    source.coordinates(self.config, window, listed)

    def test_layout_query_is_bound_to_target_account_ordinal_sequence_and_full_images(self):
        header = ["repro_token_check@localhost", UUID, "8.4.13", 1, "FULL", "ROW"]
        rows = [[name, index, value] for name in source.TABLES for index, value in enumerate(["id", "value"], 1)]
        def output(first, columns):
            return SimpleNamespace(returncode=0, stdout="\n".join(json.dumps(row) for row in [first, *columns]))

        names = {name: ["id", "value"] for name in source.TABLES}
        with patch.object(gate, "_client", return_value=output(header, rows)):
            self.assertEqual(names, source.layout(self.config))
        for first, columns in (([*header[:4], "MINIMAL", "ROW"], rows), ([*header[:1], "other", *header[2:]], rows),
                               (header, [*rows[:-1], [rows[-1][0], 3, "other"]]), (header, [[None, 1, "id"]])):
            with (self.subTest(first=first, columns=columns),
                  patch.object(gate, "_client", return_value=output(first, columns))):
                with self.assertRaises(source.Unavailable):
                    source.layout(self.config)

    @unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0, "Protected Linux fixture")
    def test_decoder_is_pinned_bounded_and_cannot_leak_errors(self):
        with tempfile.TemporaryDirectory(dir="/var/lib") as folder:
            path = Path(folder) / "mysqlbinlog"
            defaults = Path(folder) / "probe.cnf"
            defaults.write_text("[client]\n")
            defaults.chmod(0o600)
            self.config["mysql"].update(client=str(path.with_name("mysql")), probe_defaults=str(defaults), socket="/unused")
            for command, success in (("print('decoded privately')", True), ("print('x' * 12000)", False),
                                     ("import sys; sys.exit('private error text')", False)):
                path.write_text("#!/usr/bin/python3\n" + command + "\n")
                path.chmod(0o755)
                digest = hashlib.sha256(path.read_bytes()).hexdigest()
                with (self.subTest(command=command), patch.object(source, "DECODER_SHA256", digest),
                      patch.object(source, "MAX_DECODED_BYTES", 2048)):
                    if success:
                        self.assertEqual(b"decoded privately\n", source.decode(self.config, NAME, 10, 50))
                    else:
                        with self.assertRaisesRegex(source.Unavailable, "^The native smoke row scope could not be verified$"):
                            source.decode(self.config, NAME, 10, 50)
            with self.assertRaises(source.Unavailable):
                source.decode(self.config, NAME, 10, 50)

    def test_public_audit_requires_positive_native_evidence_before_discounting(self):
        receipt = mysql_probe.Receipt("a" * 64, binlog_scan.Coordinate(NAME, 4), self.window[0])
        journal = {"phase": "recovery_required", "identity": {"run_id": "smoke"}}
        for verified in (False, True):
            listed = [dict(item) for item in self.listed]
            with (self.subTest(verified=verified), patch.object(mysql_probe, "read_journal", return_value=journal),
                  patch.object(mysql_probe, "read", return_value=({"server_uuid": UUID}, None)),
                  patch.object(mysql_probe, "_base", return_value={}), patch.object(mysql_probe, "load_receipt", return_value=receipt),
                  patch.object(mysql_probe, "load_window", return_value=self.window),
                  patch.object(mysql_probe, "_scan", return_value=(binlog_scan.Result(4, None, []), None, f"{UUID}:1-16")),
                  patch.object(binlog_scan, "audit", return_value=listed), patch.object(source, "verified", return_value=verified)):
                report = mysql_probe.audit(self.config)
                self.assertIs(verified, report["smoke_row_scope_verified"])
                self.assertEqual(4, report["counts"]["smoke" if verified else "business"])
                self.assertNotIn("marker", json.dumps(report))
