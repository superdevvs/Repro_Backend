#!/usr/bin/env python3
"""Audit a pinned migration scope against a standalone SQLite snapshot.

python -B scripts/mysql/scope-audit.py --scope /private/migration-scope.json
  --scope-sha256 HEX --snapshot /private/copy.sqlite --snapshot-sha256 HEX

The reviewed JSON uses schema "repro.mysql-migration-scope.v1" and a "tables"
object keyed by exact SQLite table name. Each value is {"class":"A"} to retain
every row, or {"class":"B","approval_sha256":"HEX"} for a separately reviewed
disposition. B means migrate empty, C means archive before removal, and D means
trim a reviewed portion. Each non-A entry needs a pinned approval reference.
The caller supplies the independently reviewed SHA-256 of the entire scope.

Report metadata only. Allocated bytes include each table's indexes and overflow
pages. Views and SQLite's internal tables are excluded. Unclassified tables of
50,000,000 bytes or more are highlighted, and any scope mismatch or unavailable
size measurement fails the audit. Exit 0 means the reviewed scope covers the
snapshot, with no approval of its provenance or application of any disposition.
"""
from __future__ import annotations

import argparse
from contextlib import closing
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import sqlite3


SPEC = importlib.util.spec_from_file_location("sqlite_schema_drift", Path(__file__).with_name("schema-drift.py"))
schema_drift = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(schema_drift)
need = schema_drift.need

SCOPE_SCHEMA = "repro.mysql-migration-scope.v1"
REPORT_SCHEMA = "repro.mysql-migration-scope.audit.v1"
CLASSES = ("A", "B", "C", "D")
MAX_SCOPE_BYTES = 8 * 1024**2
LARGE_TABLE_BYTES = 50_000_000
SHA256 = re.compile(r"[a-f0-9]{64}")


def unique_object(pairs):
    result = dict(pairs)
    need(len(result) == len(pairs), "duplicate-scope-key")
    return result


def load_scope(path, expected):
    need(path.stat().st_size <= MAX_SCOPE_BYTES, "scope-size-limit")
    schema_drift.checked_file(path, expected)
    with path.open("rb") as stream:
        raw = stream.read(MAX_SCOPE_BYTES + 1)
    need(len(raw) <= MAX_SCOPE_BYTES, "scope-size-limit")
    need(hashlib.sha256(raw).hexdigest() == expected, "scope-changed-during-read")
    scope = json.loads(raw.decode("utf-8"), object_pairs_hook=unique_object)
    need(isinstance(scope, dict) and set(scope) == {"schema", "tables"} and scope["schema"] == SCOPE_SCHEMA,
         "unsupported-scope-schema")
    tables = scope["tables"]
    need(isinstance(tables, dict) and bool(tables), "scope-tables-required")
    dispositions = {}
    for name, entry in tables.items():
        need(bool(name) and "\0" not in name and not name.lower().startswith("sqlite_"), "invalid-scope-table-name")
        need(isinstance(entry, dict) and entry.get("class") in CLASSES, "invalid-scope-table-class")
        disposition = entry["class"]
        if disposition == "A":
            need(set(entry) == {"class"}, "unexpected-preserve-entry-fields")
        else:
            approval = entry.get("approval_sha256")
            need(set(entry) == {"class", "approval_sha256"} and isinstance(approval, str)
                 and SHA256.fullmatch(approval) is not None and approval != "0" * 64,
                 "approved-disposition-marker-required")
        dispositions[name] = disposition
    return dispositions


def table_objects(db):
    tables = {}
    for database, name, kind, *_ in db.execute("PRAGMA main.table_list"):
        if database == "main" and not name.lower().startswith("sqlite_") and kind != "view":
            need(kind == "table", "unsupported-snapshot-table-type")
            tables[name] = []
    need(bool(tables), "snapshot-application-tables-required")

    for name, table in db.execute("SELECT name,tbl_name FROM main.sqlite_schema WHERE type IN ('table','index')"):
        if table in tables:
            tables[table].append(name)
    need(all(table in objects for table, objects in tables.items()), "missing-table-storage-object")
    return tables


def table_sizes(db, tables):
    page_size = db.execute("PRAGMA main.page_size").fetchone()[0]
    expected = {name for objects in tables.values() for name in objects}
    sizes = {}
    try:
        for name, pages, size in db.execute("SELECT name,pageno,pgsize FROM dbstat('main',1)"):
            if name in expected:
                need(name not in sizes and pages > 0 and size == pages * page_size, "invalid-dbstat-measurement")
                sizes[name] = size
    except sqlite3.Error as error:
        reason = "dbstat-unavailable" if str(error) == "no such table: dbstat" else "dbstat-scan-failed"
        raise schema_drift.Refusal(reason) from None
    need(sizes.keys() == expected, "incomplete-dbstat-coverage")
    return {table: sum(sizes[name] for name in objects) for table, objects in tables.items()}


def audit(scope, scope_sha256, snapshot, snapshot_sha256):
    dispositions = load_scope(scope, scope_sha256)
    schema_drift.checked_file(snapshot, snapshot_sha256)
    with closing(sqlite3.connect(snapshot.as_uri() + "?mode=ro&immutable=1", uri=True)) as db:
        db.execute("PRAGMA query_only=ON")
        db.execute("PRAGMA trusted_schema=OFF")
        tables = table_objects(db)
        try:
            sizes = table_sizes(db, tables)
            storage_error = None
        except schema_drift.Refusal as error:
            sizes = {}
            storage_error = str(error)
    schema_drift.checked_file(snapshot, snapshot_sha256)

    unlisted = sorted(tables.keys() - dispositions.keys())
    missing = sorted(dispositions.keys() - tables.keys())
    verified = not (unlisted or missing or storage_error)
    return {
        "schema": REPORT_SCHEMA,
        "result": "verified" if verified else "refused",
        "scope_sha256": scope_sha256,
        "snapshot_sha256": snapshot_sha256,
        "source_scope_verified": verified,
        "dispositions_applied": False,
        "application_table_count": len(tables),
        "table_sizes_verified": storage_error is None,
        "storage_error": storage_error,
        "large_table_threshold_bytes": LARGE_TABLE_BYTES,
        "unlisted_tables": unlisted,
        "unlisted_large_tables": [name for name in unlisted if sizes and sizes[name] >= LARGE_TABLE_BYTES],
        "scope_tables_missing_from_snapshot": missing,
        "tables": [{"name": name, "class": dispositions.get(name), "allocated_bytes": sizes.get(name)}
                   for name in sorted(tables)],
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--scope", required=True, type=Path)
    parser.add_argument("--scope-sha256", required=True)
    parser.add_argument("--snapshot", required=True, type=Path)
    parser.add_argument("--snapshot-sha256", required=True)
    options = parser.parse_args()
    try:
        report = audit(options.scope, options.scope_sha256, options.snapshot, options.snapshot_sha256)
    except schema_drift.Refusal as error:
        report = {"schema": REPORT_SCHEMA, "result": "refused", "reason": str(error)}
    except (OSError, sqlite3.Error, ValueError, RecursionError):
        report = {"schema": REPORT_SCHEMA, "result": "refused", "reason": "scope-or-snapshot-unreadable"}
    print(json.dumps(report, sort_keys=True))
    return 0 if report["result"] == "verified" else 1


if __name__ == "__main__":
    raise SystemExit(main())
