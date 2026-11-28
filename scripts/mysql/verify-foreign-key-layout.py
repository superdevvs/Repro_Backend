#!/usr/bin/env python3
"""Compare SQLite referential layout with a pinned, archived MySQL catalog.

Reads metadata only. It does not connect to a database server, inspect live
rows, adjudicate historical exceptions, or exercise constraint behavior.
"""
from __future__ import annotations

import argparse
from collections import Counter, defaultdict
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import sqlite3


spec = importlib.util.spec_from_file_location("sqlite_schema_drift", Path(__file__).with_name("schema-drift.py"))
sqlite_layout = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sqlite_layout)
need = sqlite_layout.need
IDENTIFIER = re.compile(r"[A-Za-z_][A-Za-z0-9_]{0,63}")
ACTIONS = {"NO ACTION", "RESTRICT", "CASCADE", "SET NULL", "SET DEFAULT"}


def identifier(value):
    need(isinstance(value, str) and IDENTIFIER.fullmatch(value) is not None, "unsupported-relationship-identifier")
    return value


def signature(child, parent, columns, update, delete, match):
    need(update in ACTIONS and delete in ACTIONS and match == "NONE", "unsupported-relationship-action")
    need(bool(columns), "missing-relationship-columns")
    return (identifier(child), identifier(parent), tuple((identifier(a), identifier(b)) for a, b in columns),
            update, delete, match)


def from_sqlite(schema):
    tables = schema["tables"]
    constraints = Counter()
    for child, layout in tables.items():
        for decl in layout["foreign_key_declarations"]:
            need(not any(token in decl for token in (("word", "deferrable"), ("word", "initially"),
                                                    ("word", "match"))), "explicit-sqlite-fk-policy-needs-review")
        for fk in layout["foreign_keys"]:
            need(fk["parent"] in tables, "missing-sqlite-parent-table")
            rule = signature(child, fk["parent"], fk["columns"], fk["on_update"], fk["on_delete"], fk["match"])
            constraints[rule] += 1
    return set(tables), constraints


def from_mysql(catalog):
    tables = {identifier(table["TABLE_NAME"]) for table in catalog["tables"]}
    need(len(tables) == len(catalog["tables"]) and
         all(table["ENGINE"] == "InnoDB" and table["TABLE_TYPE"] == "BASE TABLE" for table in catalog["tables"]),
         "invalid-mysql-table-inventory")
    keys = defaultdict(list)
    for key in catalog["keys"]:
        if key["REFERENCED_TABLE_NAME"] is not None:
            group = (identifier(key["TABLE_NAME"]), identifier(key["CONSTRAINT_NAME"]))
            keys[group].append(key)
    constraints = Counter()
    for relation in catalog["foreign_keys"]:
        child = identifier(relation["TABLE_NAME"])
        parent = identifier(relation["REFERENCED_TABLE_NAME"])
        name = identifier(relation["CONSTRAINT_NAME"])
        need(child in tables and parent in tables, "mysql-relationship-outside-archived-tables")
        columns = sorted(keys.pop((child, name), []), key=lambda key: key["ORDINAL_POSITION"])
        need([column["ORDINAL_POSITION"] for column in columns] == list(range(1, len(columns) + 1)) and
             all(column["REFERENCED_TABLE_NAME"] == parent for column in columns),
             "mysql-relationship-key-shape-mismatch")
        pairs = [(column["COLUMN_NAME"], column["REFERENCED_COLUMN_NAME"]) for column in columns]
        rule = signature(child, parent, pairs, relation["UPDATE_RULE"], relation["DELETE_RULE"], relation["MATCH_OPTION"])
        constraints[rule] += 1
    need(not keys, "unmatched-mysql-referenced-keys")
    return tables, constraints


def affected_tables(difference):
    return sorted(set(rule[0] for rule in difference))


def compare(source, target):
    source_tables, expected = from_sqlite(source)
    target_tables, actual = from_mysql(target)
    missing, extra = expected - actual, actual - expected
    matched = source_tables == target_tables and not missing and not extra
    return {
        "result": "foreign-key-layout-match" if matched else "foreign-key-layout-drift",
        "sqlite_tables": len(source_tables), "mysql_tables": len(target_tables),
        "sqlite_relationships": expected.total(), "mysql_relationships": actual.total(),
        "exact_relationships": (expected & actual).total(),
        "missing_relationships": missing.total(), "extra_relationships": extra.total(),
        "sqlite_only_tables": sorted(source_tables - target_tables),
        "mysql_only_tables": sorted(target_tables - source_tables),
        "tables_with_missing_relationships": affected_tables(missing),
        "tables_with_extra_relationships": affected_tables(extra),
        "compared_ordered_keys_and_referential_actions": True,
        "live_identity_or_enforcement_verified": False,
        "rows_read": False,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--snapshot", type=Path, required=True)
    parser.add_argument("--snapshot-sha256", required=True)
    parser.add_argument("--mysql-catalog", type=Path, required=True)
    parser.add_argument("--mysql-catalog-sha256", required=True)
    args = parser.parse_args()
    try:
        need(args.mysql_catalog.stat().st_size <= 32 * 1024**2, "mysql-catalog-size-limit")
        sqlite_layout.checked_file(args.snapshot, args.snapshot_sha256)
        sqlite_layout.checked_file(args.mysql_catalog, args.mysql_catalog_sha256)
        raw = args.mysql_catalog.read_bytes()
        need(len(raw) <= 32 * 1024**2 and hashlib.sha256(raw).hexdigest() ==
             args.mysql_catalog_sha256, "mysql-catalog-input-changed")
        report = compare(sqlite_layout.read_schema(args.snapshot), json.loads(raw))
        report.update({"sqlite_sha256": args.snapshot_sha256, "mysql_catalog_sha256": args.mysql_catalog_sha256})
    except sqlite_layout.Refusal as error:
        print(json.dumps({"result": "REFUSED", "reason": str(error)}))
        return 1
    except (OSError, sqlite3.Error, TypeError, KeyError, ValueError) as error:
        print(json.dumps({"result": "REFUSED", "reason": type(error).__name__}))
        return 1
    print(json.dumps(report, sort_keys=True))
    return 0 if report["result"] == "foreign-key-layout-match" else 2


if __name__ == "__main__":
    raise SystemExit(main())
