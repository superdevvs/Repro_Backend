"""Metadata fixtures with declared composites and no business data."""
from contextlib import closing
import importlib.util
from pathlib import Path
import sqlite3
import unittest


spec = importlib.util.spec_from_file_location("verify_foreign_key_layout",
                                              Path(__file__).with_name("verify-foreign-key-layout.py"))
verify = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verify)


def source(declaration=""):
    with closing(sqlite3.connect(":memory:")) as db:
        db.execute("CREATE TABLE parent (a INTEGER, b INTEGER, PRIMARY KEY(a,b))")
        db.execute("CREATE TABLE child (x INTEGER, y INTEGER, "
                   "FOREIGN KEY(x,y) REFERENCES parent(a,b) ON DELETE CASCADE" + declaration + ")")
        return verify.sqlite_layout.schema_from_connection(db)


def target():
    rule = {"TABLE_NAME": "child", "CONSTRAINT_NAME": "renamed_fk", "REFERENCED_TABLE_NAME": "parent"}
    return {
        "tables": [{"TABLE_NAME": name, "ENGINE": "InnoDB", "TABLE_TYPE": "BASE TABLE"}
                   for name in ("child", "parent")],
        "keys": [{**rule, "COLUMN_NAME": child, "REFERENCED_COLUMN_NAME": parent, "ORDINAL_POSITION": pos}
                 for pos, (child, parent) in enumerate((("x", "a"), ("y", "b")), 1)],
        "foreign_keys": [{**rule, "DELETE_RULE": "CASCADE", "UPDATE_RULE": "NO ACTION", "MATCH_OPTION": "NONE"}],
    }


class ForeignKeyLayoutTest(unittest.TestCase):
    def test_constraint_alias_and_catalog_row_order_preserve_exact_match(self):
        archive = target()
        archive["keys"].reverse()
        report = verify.compare(source(), archive)
        self.assertEqual(report["result"], "foreign-key-layout-match")
        self.assertEqual(report["exact_relationships"], 1)
        self.assertFalse(report["rows_read"])
        self.assertFalse(report["live_identity_or_enforcement_verified"])

    def test_active_action_and_composite_mapping_changes_are_reported(self):
        for transform in (lambda value: value["foreign_keys"][0].update(DELETE_RULE="SET NULL"),
                          lambda value: value["keys"][0].update(REFERENCED_COLUMN_NAME="b")):
            archive = target()
            transform(archive)
            report = verify.compare(source(), archive)
            self.assertEqual(report["result"], "foreign-key-layout-drift")
            self.assertEqual(report["tables_with_missing_relationships"], ["child"])
            self.assertEqual(report["tables_with_extra_relationships"], ["child"])
            self.assertEqual(report["exact_relationships"], 0)

    def test_missing_and_extra_relationships_are_distinguished(self):
        archive = target()
        archive["keys"].clear()
        archive["foreign_keys"].clear()
        report = verify.compare(source(), archive)
        self.assertEqual((report["missing_relationships"], report["extra_relationships"]), (1, 0))

    def test_unbound_keys_and_skipped_ordinals_are_refused(self):
        archive = target()
        archive["keys"][1]["ORDINAL_POSITION"] = 3
        with self.assertRaisesRegex(verify.sqlite_layout.Refusal, "key-shape"):
            verify.compare(source(), archive)
        archive["foreign_keys"].clear()
        with self.assertRaisesRegex(verify.sqlite_layout.Refusal, "unmatched"):
            verify.compare(source(), archive)

    def test_deferrable_foreign_keys_need_separate_review(self):
        with self.assertRaisesRegex(verify.sqlite_layout.Refusal, "explicit-sqlite-fk-policy"):
            verify.compare(source(" DEFERRABLE INITIALLY DEFERRED"), target())
