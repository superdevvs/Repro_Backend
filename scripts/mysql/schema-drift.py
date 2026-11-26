#!/usr/bin/env python3
"""Read-only release-fixture versus copied-snapshot SQLite schema report.

python -B scripts/mysql/schema-drift.py --release /private/release.sqlite
  --release-sha256 HEX --release-commit FULL_GIT_SHA
  --snapshot /private/copy.sqlite --snapshot-sha256 HEX

Exit 0: normalized schemas match. Exit 1: drift or refusal. JSON stdout contains
identifiers, changed attribute names and fingerprints, never SQL/default values.
Both artifacts must be private standalone closed files, without WAL/SHM/journal.
Only sqlite_schema and PRAGMA metadata are queried; no table rows, maxima,
integrity-check scans or data-derived widths. File SHA-256 reads bind the inputs.
No mutation, drift resolution, MySQL access or application boot occurs.

Columns compare by name, not position. Expression whitespace/comments and
unquoted SQL word case normalize; quoted text and parentheses remain exact.
Default-expression word case is preserved: bare-word defaults can be strings.
Trigger/view SQL and named constraints also preserve spelling conservatively.
This is deliberately conservative: differing SQL spellings can require review
even when an operator considers them equivalent. SQLite internal tables and
statistics are excluded; virtual tables are refused rather than silently mapped.
Metadata reference: https://www.sqlite.org/pragma.html
"""
from __future__ import annotations

import argparse
from collections import defaultdict
import hashlib
import json
from pathlib import Path
import os
import re
import sqlite3
import stat


class Refusal(RuntimeError):
    pass


def need(valid: bool, reason: str) -> None:
    if not valid:
        raise Refusal(reason)


def packed(value) -> str:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def fingerprint(value) -> str:
    return hashlib.sha256(packed(value).encode("utf-8")).hexdigest()


def file_hash(path: Path) -> str:
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def checked_file(path: Path, expected: str) -> None:
    need(path.is_absolute() and path.is_file() and not path.is_symlink() and
         path.resolve(strict=True) == path, "private-regular-canonical-file-required")
    info = path.stat()
    need(stat.S_ISREG(info.st_mode) and info.st_nlink == 1, "regular-unlinked-artifact-required")
    need(os.name == "nt" or info.st_mode & 0o077 == 0, "private-input-mode-required")
    for suffix in ("-wal", "-shm", "-journal"):
        need(not os.path.lexists(str(path) + suffix), "standalone-snapshot-has-sidecar")
    need(re.fullmatch(r"[a-f0-9]{64}", expected) is not None and file_hash(path) == expected,
         "input-checksum-mismatch")


def q(name: str) -> str:
    return '"' + name.replace('"', '""') + '"'


def tokens(sql: str | None, *, fold_words: bool = True) -> list[tuple[str, str]]:
    """Lex schema expressions without folding quoted values or dropping parens."""
    if sql is None:
        return []
    answer, i = [], 0
    while i < len(sql):
        char = sql[i]
        if char.isspace():
            i += 1
        elif sql.startswith("--", i):
            end = sql.find("\n", i + 2)
            i = len(sql) if end == -1 else end + 1
        elif sql.startswith("/*", i):
            end = sql.find("*/", i + 2)
            need(end != -1, "unterminated-schema-comment")
            i = end + 2
        elif char in "'\"`[":
            closing = "]" if char == "[" else char
            value, j = [], i + 1
            while j < len(sql):
                if sql[j] == closing:
                    if closing != "]" and j + 1 < len(sql) and sql[j + 1] == closing:
                        value.append(closing)
                        j += 2
                        continue
                    break
                value.append(sql[j])
                j += 1
            need(j < len(sql), "unterminated-schema-quote")
            answer.append((char, "".join(value)))
            i = j + 1
        elif char.isalpha() or char == "_" or ord(char) >= 128:
            end = i + 1
            while end < len(sql) and (sql[end].isalnum() or sql[end] in "_$" or ord(sql[end]) >= 128):
                end += 1
            # SQLite's unquoted identifier case-insensitivity is ASCII only.
            word = sql[i:end]
            if fold_words:
                word = word.translate(str.maketrans("ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"))
            answer.append(("word", word))
            i = end
        elif char.isdigit() or (char == "." and i + 1 < len(sql) and sql[i + 1].isdigit()):
            match = re.match(r"(?:0[xX][0-9a-fA-F]+|(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)", sql[i:])
            need(match is not None, "unsupported-schema-number")
            answer.append(("number", match[0]))
            i += len(match[0])
        else:
            operator = next((op for op in ("->>", "->", "||", "<<", ">>", "<=", ">=", "!=", "<>", "==")
                             if sql.startswith(op, i)), char)
            answer.append(("symbol", operator))
            i += len(operator)
    return answer


def bracket_end(items: list, start: int) -> int:
    need(items[start] == ("symbol", "("), "schema-parenthesis-required")
    depth = 0
    for index in range(start, len(items)):
        if items[index] == ("symbol", "("):
            depth += 1
        elif items[index] == ("symbol", ")"):
            depth -= 1
            if depth == 0:
                return index
    raise Refusal("unbalanced-schema-expression")


def table_parts(sql: str) -> tuple[list, list, list]:
    items = tokens(sql)
    need(("word", "virtual") not in items[:4], "virtual-table-needs-separate-review")
    start = items.index(("symbol", "("))
    end = bracket_end(items, start)
    parts, begin, depth = [], start + 1, 0
    for index in range(start + 1, end):
        token = items[index]
        if token == ("symbol", "("):
            depth += 1
        elif token == ("symbol", ")"):
            depth -= 1
        elif token == ("symbol", ",") and depth == 0:
            parts.append(items[begin:index])
            begin = index + 1
    parts.append(items[begin:end])
    tail = [item for item in items[end + 1:] if item != ("symbol", ";")]
    return parts, tail, items


def ordered(values: list) -> list:
    return sorted(values, key=packed)


def checks(items: list) -> list:
    expressions = []
    for index, token in enumerate(items[:-1]):
        if token == ("word", "check") and items[index + 1] == ("symbol", "("):
            end = bracket_end(items, index + 1)
            expressions.append(items[index + 2:end])
    return ordered(expressions)


def schema_from_connection(db: sqlite3.Connection) -> dict:
    db.row_factory = sqlite3.Row
    entries = [dict(row) for row in db.execute(
        "SELECT type,name,tbl_name,sql FROM sqlite_schema WHERE substr(name,1,7)<>'sqlite_' ORDER BY type,name")]
    result = {"tables": {}, "triggers": {}, "views": {}}
    for entry in entries:
        kind, name, sql = entry["type"], entry["name"], entry["sql"]
        if kind in ("trigger", "view"):
            result[kind + "s"][name] = {"table": entry["tbl_name"], "sql_tokens": tokens(sql, fold_words=False)}
        elif kind == "table":
            need(isinstance(sql, str), "missing-table-definition")
            parts, options, all_tokens = table_parts(sql)
            columns = [dict(row) for row in db.execute("PRAGMA table_xinfo(" + q(name) + ")")]
            need(columns and all("hidden" in row for row in columns), "table-xinfo-unavailable")
            by_name = {row["name"]: row for row in columns}
            # Resolve unquoted column names using SQLite's ASCII identifier fold.
            folded = {key.translate(str.maketrans("ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")): key for key in by_name}
            declarations, constraints, fk_declarations = {}, [], []
            for part in parts:
                need(bool(part), "empty-table-declaration")
                is_constraint = part[0][0] == "word" and part[0][1] in ("constraint", "primary", "unique", "check", "foreign")
                if is_constraint:
                    constraints.append(part)
                else:
                    key = folded.get(part[0][1]) if part[0][0] == "word" else part[0][1]
                    need(key in by_name and key not in declarations, "unmapped-column-declaration")
                    declarations[key] = part[1:]
                if ("word", "references") in part:
                    # Includes DEFERRABLE/INITIALLY, which PRAGMA omits. Keeping
                    # the whole clause is conservative; no unknown tail is lost.
                    fk_declarations.append(part if is_constraint else [part[0], *part[part.index(("word", "references")):]])
            need(set(declarations) == set(by_name), "incomplete-column-declarations")
            spelling = tokens(sql, fold_words=False)
            constraint_names = [spelling[index + 1] for index, item in enumerate(spelling[:-1])
                                if item[0] == "word" and item[1].lower() == "constraint"]
            table = {"columns": {}, "primary_key": [row["name"] for row in sorted(columns, key=lambda c: c["pk"]) if row["pk"]],
                     "table_options": options, "table_constraints": ordered(constraints),
                     "constraint_names": ordered(constraint_names),
                     "checks": checks(all_tokens), "foreign_key_declarations": ordered(fk_declarations),
                     "foreign_keys": [], "named_indexes": {}, "automatic_indexes": []}
            for row in columns:
                table["columns"][row["name"]] = {
                    "type": tokens(row["type"]), "notnull": bool(row["notnull"]),
                    # DEFAULT Foo stores the text "Foo" in SQLite. Folding it
                    # to "foo" would hide a real behavior change.
                    "default": tokens(row["dflt_value"], fold_words=False), "hidden": row["hidden"],
                    "declaration": declarations[row["name"]]}
            grouped = defaultdict(list)
            for row in db.execute("PRAGMA foreign_key_list(" + q(name) + ")"):
                grouped[row["id"]].append(dict(row))
            for rows in grouped.values():
                rows.sort(key=lambda row: row["seq"])
                first = rows[0]
                table["foreign_keys"].append({"parent": first["table"],
                    "columns": [[row["from"], row["to"]] for row in rows],
                    "on_update": first["on_update"], "on_delete": first["on_delete"], "match": first["match"]})
            table["foreign_keys"] = ordered(table["foreign_keys"])
            for index in db.execute("PRAGMA index_list(" + q(name) + ")").fetchall():
                keys = []
                for key in db.execute("PRAGMA index_xinfo(" + q(index["name"]) + ")"):
                    need("key" in key.keys(), "index-xinfo-unavailable")
                    if key["key"]:
                        keys.append({"name": key["name"], "source": "expression" if key["cid"] == -2 else
                                     "rowid" if key["cid"] == -1 else "column", "desc": bool(key["desc"]),
                                     "collation": key["coll"]})
                raw = db.execute("SELECT sql FROM sqlite_schema WHERE type='index' AND name=?", (index["name"],)).fetchone()
                shape = {"unique": bool(index["unique"]), "origin": index["origin"],
                         "partial": bool(index["partial"]), "keys": keys, "sql_tokens": tokens(raw[0] if raw else None)}
                if index["origin"] in ("u", "pk"):
                    table["automatic_indexes"].append(shape)
                else:
                    table["named_indexes"][index["name"]] = shape
            table["automatic_indexes"] = ordered(table["automatic_indexes"])
            result["tables"][name] = table
        else:
            need(kind == "index", "unknown-schema-object")
    return result


def read_schema(path: Path) -> dict:
    db = sqlite3.connect(path.as_uri() + "?mode=ro&immutable=1", uri=True)
    try:
        db.execute("PRAGMA query_only=ON")
        need(db.execute("PRAGMA query_only").fetchone()[0] == 1, "query-only-not-enabled")
        return schema_from_connection(db)
    finally:
        db.close()


def changes(release: dict, snapshot: dict) -> list:
    result = []

    def mapping(kind, left, right, parent=None):
        for name in sorted(set(left) | set(right)):
            where = {"kind": kind, "name": name}
            if parent is not None:
                where["table"] = parent
            if name not in right:
                result.append(where | {"change": "missing-in-snapshot"})
            elif name not in left:
                result.append(where | {"change": "extra-in-snapshot"})
            elif left[name] != right[name]:
                attributes = [key for key in sorted(set(left[name]) | set(right[name]))
                              if left[name].get(key) != right[name].get(key)]
                result.append(where | {"change": "definition", "attributes": attributes,
                    "release_fingerprint": fingerprint(left[name]), "snapshot_fingerprint": fingerprint(right[name])})

    for name in sorted(set(release["tables"]) | set(snapshot["tables"])):
        if name not in release["tables"] or name not in snapshot["tables"]:
            result.append({"kind": "table", "name": name,
                           "change": "extra-in-snapshot" if name not in release["tables"] else "missing-in-snapshot"})
            continue
        a, b = release["tables"][name], snapshot["tables"][name]
        mapping("column", a["columns"], b["columns"], name)
        mapping("index", a["named_indexes"], b["named_indexes"], name)
        for attribute in ("primary_key", "foreign_keys", "foreign_key_declarations", "checks",
                          "automatic_indexes", "table_options", "table_constraints", "constraint_names"):
            if a[attribute] != b[attribute]:
                result.append({"kind": attribute, "table": name, "change": "definition",
                    "release_fingerprint": fingerprint(a[attribute]), "snapshot_fingerprint": fingerprint(b[attribute])})
    mapping("trigger", release["triggers"], snapshot["triggers"])
    mapping("view", release["views"], snapshot["views"])
    return result


def compare(release: Path, release_sha256: str, snapshot: Path,
            snapshot_sha256: str, release_commit: str) -> dict:
    need(re.fullmatch(r"[a-f0-9]{40}|[a-f0-9]{64}", release_commit) is not None, "full-release-commit-required")
    checked_file(release, release_sha256)
    checked_file(snapshot, snapshot_sha256)
    left, right = read_schema(release), read_schema(snapshot)
    differences = changes(left, right)
    checked_file(release, release_sha256)
    checked_file(snapshot, snapshot_sha256)
    return {"schema": "repro.sqlite.schema-drift.v1", "result": "schema-drift" if differences else "schema-match",
            "match": not differences, "release_commit": release_commit,
            "release_sha256": release_sha256, "snapshot_sha256": snapshot_sha256,
            "release_schema_sha256": fingerprint(left), "snapshot_schema_sha256": fingerprint(right),
            "release_tables": len(left["tables"]), "snapshot_tables": len(right["tables"]),
            "change_count": len(differences), "changes": differences,
            "column_order_ignored": True, "table_data_queried": False,
            "database_writes": False, "drift_resolved": False, "cutover_ready": False}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--release", type=Path, required=True)
    parser.add_argument("--release-sha256", required=True)
    parser.add_argument("--release-commit", required=True)
    parser.add_argument("--snapshot", type=Path, required=True)
    parser.add_argument("--snapshot-sha256", required=True)
    args = parser.parse_args()
    try:
        report = compare(args.release, args.release_sha256, args.snapshot,
                         args.snapshot_sha256, args.release_commit)
        print(json.dumps(report, sort_keys=True))
        return 0 if report["match"] else 1
    except Refusal as error:
        print(json.dumps({"result": "REFUSED", "reason": str(error), "database_writes": False}))
        return 1
    except (OSError, sqlite3.Error, ValueError, KeyError, IndexError, TypeError):
        # SQLite errors can include SQL/default literals; never echo them.
        print(json.dumps({"result": "REFUSED", "reason": "schema-read-failure", "database_writes": False}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
