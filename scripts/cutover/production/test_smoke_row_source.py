"""Read-only native smoke attribution and safe fallback to full recovery."""
import hashlib
import json
import os
import re
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

    def test_audit_scope_preserves_prior_foreign_writes_and_later_business_writes_but_refuses_later_ddl(self):
        prior = {"class": "foreign", "gtid": f"{UUID}:9", "statements": [], "tables": ["users"]}
        later = {"class": "business", "gtid": f"{UUID}:17", "statements": [], "tables": ["users"]}
        with patch.object(mysql_probe, "_show", return_value=self.events):
            ids, name, start, stop = source.coordinates(self.config, self.window, [prior, *self.listed, later])
            self.assertEqual((tuple(item["gtid"] for item in self.listed), NAME, 456, 920), (ids, name, start, stop))
            self.assertEqual("business", later["class"])
            with self.assertRaises(source.Unavailable):
                source.coordinates(self.config, self.window, [*self.listed, {**later, "statements": ["ALTER TABLE"]}])

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
        rows = [[name, 1, "id", "bigint", "NO", 1] for name in source.TABLES]
        rows += [[name, 2, "value", "longtext", "YES", 1] for name in source.TABLES]
        def output(first, columns):
            return SimpleNamespace(returncode=0, stdout="\n".join(json.dumps(row) for row in [first, *columns]))

        names = {name: ["id", "value"] for name in source.TABLES}
        catalog = dict.fromkeys(source.TABLES, [["id", "bigint", "NO"], ["value", "longtext", "YES"]])
        encoded = json.dumps(catalog, sort_keys=True, separators=(",", ":")).encode()
        pin = patch.object(source, "CATALOG_SHA256", {hashlib.sha256(encoded).hexdigest()})
        pin.start()
        self.addCleanup(pin.stop)
        with patch.object(gate, "_client", return_value=output(header, rows)):
            self.assertEqual(names, source.layout(self.config))
        for first, columns in (([*header[:4], "MINIMAL", "ROW"], rows), ([*header[:1], "other", *header[2:]], rows),
                               (header, [*rows[:-1], [rows[-1][0], 3, "other", "longtext", "YES", 1]]),
                               (header, [*rows[:-1], [*rows[-1][:3], "bigint", "YES", 1]]),
                               (header, [*rows[:-1], [*rows[-1][:4], "NO", 1]]),
                               (header, [*rows, [rows[-1][0], 3, "virtual_tail", "bigint", "YES", 0]]),
                               (header, [[None, 1, "id", "bigint", "NO", 1]])):
            with (self.subTest(first=first, columns=columns),
                  patch.object(gate, "_client", return_value=output(first, columns))):
                with self.assertRaises(source.Unavailable):
                    source.layout(self.config)

    def test_foreign_key_evidence_needs_real_probe_target_visibility_canary_and_zero_danger(self):
        expected = [mysql_probe.PROBE_ACCOUNT, UUID, "8.4.13", 1, 0, 1, 1, 0]
        with patch.object(source, "layout") as get_layout, patch("smoke_rows.verified_layout"):
            variants = [(expected, True), (expected[:7], False)]
            for i, changed in enumerate(["guest@localhost", "other", "8.4.12", 0, 1, 0, 0, 2]):
                variants.append(([*expected[:i], changed, *expected[i+1:]], False))
            variants.append(([*expected[:-1], False], False))
            for response, valid in variants:
                out = SimpleNamespace(returncode=0, stdout=json.dumps(response))
                with self.subTest(response=response), patch.object(gate, "_client", return_value=out) as query:
                    self.assertIs(valid, source.safe_schema_at_smoke(self.config, 123))
                    get_layout.assert_called_with(self.config, deadline=123)
                    self.assertEqual("probe_defaults", query.call_args.args[1])
                    self.assertEqual({"deadline": 123}, query.call_args.kwargs)

    def test_a_later_clean_catalog_cannot_replace_the_proof_at_write_time(self):
        for assessment in ({}, {"schema_verified_before_write": False}, {"schema_verified_before_write": 1}):
            window = (*self.window[:2], {**self.window[2], **assessment})
            with self.subTest(assessment=assessment), patch.object(source, "coordinates") as bounds:
                self.assertFalse(source.verified(self.config, window, self.listed))
                bounds.assert_not_called()

    def test_reviewed_clean_and_imported_catalogs_and_rejecting_mixed_layouts(self):
        ddl = (Path(__file__).resolve().parents[3] / "database/schema/mysql-schema.sql").read_text(encoding="utf-8")
        clean = {}
        for table in source.TABLES:
            body = re.search(r"CREATE TABLE \x60" + table + r"\x60 \((.*?)\n\) ENGINE=", ddl, re.S)[1]
            declarations = re.findall(r"^  \x60([a-z0-9_]+)\x60 ([a-z]+(?:\([0-9,]+\))?(?: unsigned)?)([^\n]*)",
                                      body, re.M)
            clean[table] = [(col, kind, "NO" if "NOT NULL" in attrs else "YES") for col, kind, attrs in declarations]

        # Reconstruct this release's imported metadata. The converter preserves SQLite
        # date/time text and maps the original integer and text affinities to these types.
        native = {"datetime(6)": "varchar(64)", "date": "varchar(64)", "tinyint(1)": "bigint",
                  "char(36)": "longtext", "json": "longtext"}
        imported = {table: [(col, native.get(kind, kind), null) for col, kind, null in columns]
                    for table, columns in clean.items()}
        users = {col[0]: col for col in imported["users"]}
        ordered = [col for col in imported["users"] if col[0] not in {"deleted_at", "secondary_roles"}]
        ordered.insert(6, users["deleted_at"])
        ordered.insert(11, users["secondary_roles"])
        imported["users"] = ordered

        def attest(candidate, *, extra=None):
            result = [["repro_token_check@localhost", UUID, "8.4.13", 1, "FULL", "ROW"]]
            for table, columns in candidate.items():
                result += [[table, ordinal, *column, 1] for ordinal, column in enumerate(columns, 1)]
            if extra:
                result.append(extra)
            rows = SimpleNamespace(returncode=0, stdout="\n".join(json.dumps(line) for line in result))
            with patch.object(gate, "_client", return_value=rows):
                return source.layout(self.config)

        self.assertEqual([col[0] for col in clean["users"]], attest(clean)["users"])
        self.assertEqual([col[0] for col in ordered], attest(imported)["users"])
        wrong_order = {**imported, "users": [users[column[0]] for column in clean["users"]]}
        for hybrid in (wrong_order, {**imported, "shoots": clean["shoots"]}, {**clean, "users": ordered}):
            with self.assertRaises(source.Unavailable):
                attest(hybrid)
        with self.assertRaises(source.Unavailable):
            attest(imported, extra=["users", 61, "virtual_tail", "bigint", "YES", 0])

    @unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0, "Protected Linux fixture")
    def test_decoder_is_pinned_bounded_and_cannot_leak_errors(self):
        with tempfile.TemporaryDirectory(dir="/var/lib") as folder:
            path = Path(folder) / "bin/mysqlbinlog"
            path.parent.mkdir()
            private = Path(folder) / "lib/private"
            private.mkdir(parents=True)
            libraries = {key: hashlib.sha256(key.encode()).hexdigest() for key in source.OPENSSL_SHA256}
            for key in libraries:
                (private / key).write_bytes(key.encode())
            defaults = Path(folder) / "probe.cnf"
            credentials = "[client]\nuser=repro_cutover_probe\npassword=synthetic-probe-password\n"
            defaults.write_text(credentials)
            defaults.chmod(0o600)
            self.config["mysql"].update(client=str(path.with_name("mysql")), probe_defaults=str(defaults), socket="/unused")
            for command, success in (("print('decoded privately')", True), ("print('x' * 12000)", False),
                                     ("import sys; sys.exit('private error text')", False)):
                path.write_text("#!/usr/bin/python3\n" + command + "\n")
                path.chmod(0o755)
                digest = hashlib.sha256(path.read_bytes()).hexdigest()
                with (self.subTest(command=command), patch.object(source, "DECODER_SHA256", digest),
                      patch.object(source, "OPENSSL_SHA256", libraries),
                      patch.object(source, "MAX_DECODED_BYTES", 2048)):
                    if success:
                        self.assertEqual(b"decoded privately\n", source.decode(self.config, NAME, 10, 50))
                        for extra in ("[mysqlbinlog]\nresult-file=/private/bypass\n", "!include /private/extra.cnf\n",
                                      "!includedir /private/other\n", "plugin-dir=/private/plugin\n"):
                            defaults.write_text(credentials + extra)
                            with self.assertRaises(source.Unavailable):
                                source.decode(self.config, NAME, 10, 50)
                        defaults.write_text(credentials)
                        shadow = private / "libc.so.6"
                        shadow.symlink_to("missing")
                        with self.assertRaises(source.Unavailable):
                            source.decode(self.config, NAME, 10, 50)
                        shadow.unlink()
                        with patch.dict(libraries, {"libssl.so.3": "b" * 64}):
                            with self.assertRaises(source.Unavailable):
                                source.decode(self.config, NAME, 10, 50)
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
