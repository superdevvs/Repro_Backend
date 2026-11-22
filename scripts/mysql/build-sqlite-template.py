#!/usr/bin/env python3
"""Build an exact empty SQLite schema template from a pinned standalone copy.

The immutable source authorizer permits only catalog reads. The new template
includes retained historical DDL and SQLite triggers in their original order.
Apply reviewed compatibility overlays separately before reverse-export use.
"""
from __future__ import annotations

import argparse
from collections import Counter
from contextlib import closing
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import stat
import time


SIDECARS = ("-wal", "-shm", "-journal")
CATALOG = {"sqlite_master", "sqlite_schema"}
MESSAGING_FIELDS = {
    "contacts": {"phones_json": "TEXT", "tags_json": "TEXT", "comment": "TEXT"},
    "message_threads": {
        "assigned_to_user_id": 'INTEGER REFERENCES "users" ("id") ON DELETE SET NULL',
        "status": "VARCHAR",
        "tags_json": "TEXT",
    },
}
DEFINITION_PREFIXES = {
    "table": r"CREATE\s+TABLE\s",
    "index": r"CREATE\s+(?:UNIQUE\s+)?INDEX\s",
    "trigger": r"CREATE\s+TRIGGER\s",
}
DDL_ACTIONS = {
    sqlite3.SQLITE_CREATE_TABLE, sqlite3.SQLITE_CREATE_INDEX, sqlite3.SQLITE_CREATE_TRIGGER,
    sqlite3.SQLITE_READ, sqlite3.SQLITE_REINDEX,
}


class Refused(RuntimeError):
    pass


def require(condition, reason):
    if not condition:
        raise Refused(reason)


def stamp(info):
    # Windows lstat may report birth time where fstat reports change time.
    common = (info.st_dev, info.st_ino, info.st_mode, info.st_nlink, info.st_size, info.st_mtime_ns)
    return common if os.name == "nt" else (*common, info.st_ctime_ns, info.st_uid, info.st_gid)


def no_sidecars(path):
    require(not any(os.path.lexists(str(path) + suffix) for suffix in SIDECARS),
            "standalone-snapshot-has-sidecar")


def catalog_only(action, name, column, database, trigger):
    if trigger is None and (action == sqlite3.SQLITE_SELECT or (
        action == sqlite3.SQLITE_READ and database == "main" and name in CATALOG
    )):
        return sqlite3.SQLITE_OK
    return sqlite3.SQLITE_DENY


def template_ddl_only(action, name, column, database, trigger):
    if trigger is not None or database not in {None, "main"}:
        return sqlite3.SQLITE_DENY
    if action in DDL_ACTIONS or (
        action in {sqlite3.SQLITE_INSERT, sqlite3.SQLITE_UPDATE} and name in CATALOG
    ) or (action == sqlite3.SQLITE_FUNCTION and column != "load_extension"):
        return sqlite3.SQLITE_OK
    return sqlite3.SQLITE_DENY


def definitions(db):
    return [row for row in db.execute(
        "SELECT type,name,tbl_name,sql FROM main.sqlite_schema "
        "ORDER BY CASE type WHEN 'table' THEN 0 WHEN 'index' THEN 1 ELSE 2 END, rowid"
    ) if not row[1].startswith("sqlite_")]


def snapshot_digest(stream, rate_mib):
    if not rate_mib:
        return hashlib.file_digest(stream, "sha256").hexdigest()
    digest = hashlib.sha256()
    offset = 0
    started = time.monotonic()
    while chunk := stream.read(4 * 1024**2):
        digest.update(chunk)
        if hasattr(os, "posix_fadvise"):
            try:
                os.posix_fadvise(stream.fileno(), offset, len(chunk), os.POSIX_FADV_DONTNEED)
            except OSError:
                pass  # The read limit still applies when the filesystem ignores cache hints.
        offset += len(chunk)
        delay = started + offset / (rate_mib * 1024**2) - time.monotonic()
        if delay > 0:
            time.sleep(delay)
    return digest.hexdigest()


def snapshot_definitions(snapshot, expected_sha256, rate_mib=0):
    require(snapshot.is_absolute() and snapshot.resolve(strict=True) == snapshot, "canonical-source-required")
    info = snapshot.lstat()
    require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1, "source-must-be-regular-and-single-link")
    require(os.name == "nt" or stat.S_IMODE(info.st_mode) == 0o600, "source-mode-must-be-0600")
    require(re.fullmatch(r"[a-f0-9]{64}", expected_sha256) is not None, "approved-source-checksum-required")
    no_sidecars(snapshot)
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0) | getattr(os, "O_BINARY", 0)
    with os.fdopen(os.open(snapshot, flags), "rb") as stream:
        original = stamp(info)
        require(stamp(os.fstat(stream.fileno())) == original, "source-changed-on-open")
        require(snapshot_digest(stream, rate_mib) == expected_sha256, "source-checksum-mismatch")
        no_sidecars(snapshot)
        # The hash authenticates the whole input file. SQLite is restricted to
        # catalog reads and never inspects table rows, views or their plans.
        with closing(sqlite3.connect(snapshot.as_uri() + "?mode=ro&immutable=1", uri=True)) as db:
            db.execute("PRAGMA query_only=ON")
            db.execute("PRAGMA trusted_schema=OFF")
            db.set_authorizer(catalog_only)
            objects = definitions(db)
        require(stamp(os.fstat(stream.fileno())) == original and stamp(snapshot.lstat()) == original,
                "source-metadata-changed")
        no_sidecars(snapshot)
    require(any(kind == "table" for kind, *_ in objects), "source-schema-has-no-tables")
    for kind, _, _, sql in objects:
        require(kind in DEFINITION_PREFIXES and isinstance(sql, str) and
                re.match(DEFINITION_PREFIXES[kind], sql, flags=re.IGNORECASE) is not None,
                "unsupported-schema-object")
    return objects


def private_file(path, mode):
    return os.fdopen(os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY | getattr(os, "O_BINARY", 0), 0o600), mode)


def create_template(path, objects):
    with private_file(path, "wb"):
        pass
    with closing(sqlite3.connect(path)) as db:
        db.execute("PRAGMA trusted_schema=OFF")
        db.execute("BEGIN")
        db.set_authorizer(template_ddl_only)
        for _, _, _, ddl in objects:
            db.execute(ddl)
        db.set_authorizer(catalog_only)
        require(definitions(db) == objects, "recreated-schema-differs")
        db.set_authorizer(None)
        db.commit()


def apply_messaging_overlay(template):
    # Mirrors the optional fields in 2026_11_04_180000. This runs only on the new
    # empty template. The reverse exporter verifies every column against MySQL.
    with closing(sqlite3.connect(template)) as db:
        db.execute("PRAGMA trusted_schema=OFF")
        baseline = definitions(db)
        table_names = {name for kind, name, _, _ in baseline if kind == "table"}
        require({"contacts", "message_threads", "users"} <= table_names,
                "messaging-overlay-requires-reviewed-tables")
        old_keys = list(db.execute('PRAGMA foreign_key_list("message_threads")'))
        additions = []
        db.execute("BEGIN")
        for table, fields in MESSAGING_FIELDS.items():
            columns = {row[1]: row for row in db.execute(f'PRAGMA table_info("{table}")')}
            for name, declaration in fields.items():
                if name in columns:
                    require(columns[name][3] == 0, "preexisting-optional-column-is-required")
                else:
                    db.execute(f'ALTER TABLE "{table}" ADD COLUMN "{name}" {declaration}')
                    additions.append(f"{table}.{name}")
        new_keys = list(db.execute('PRAGMA foreign_key_list("message_threads")'))
        require(all(key in new_keys for key in old_keys), "existing-messaging-foreign-key-ordinal-changed")
        assignment = [key for key in new_keys if key[3] == "assigned_to_user_id"]
        require(len(assignment) == 1 and assignment[0][1:8] ==
                (0, "users", "assigned_to_user_id", "id", "NO ACTION", "SET NULL", "NONE"),
                "messaging-assignment-foreign-key-mismatch")
        updated = definitions(db)
        unchanged = lambda rows: [entry for entry in rows if entry[0] != "table" or entry[1] not in MESSAGING_FIELDS]
        require(unchanged(baseline) == unchanged(updated), "unexpected-messaging-overlay-ddl")
        db.commit()
    return additions


def build(snapshot, expected_sha256, output, rate_mib=0, messaging_overlay=False):
    require(output.is_absolute() and output.parent.resolve(strict=True) == output.parent and
            output == output.resolve(), "canonical-output-required")
    require(not os.path.lexists(output), "output-must-be-new")
    objects = snapshot_definitions(snapshot, expected_sha256, rate_mib)
    output.mkdir(mode=0o700)
    pending = output / "schema-template.incomplete.sqlite"
    template = output / "schema-template.sqlite"
    create_template(pending, objects)
    additions = apply_messaging_overlay(pending) if messaging_overlay else []
    with pending.open("rb") as stream:
        checksum = hashlib.file_digest(stream, "sha256").hexdigest()
    pending.rename(template)
    record = {
        "result": "schema-template-created" if messaging_overlay else "base-schema-template-created",
        "snapshot_sha256": expected_sha256,
        "template_sha256": checksum, "schema_objects": dict(Counter(kind for kind, *_ in objects)),
        "source_rows_queried": False, "rows_copied": False,
        "overlay_applied": messaging_overlay, "overlay_added_columns": additions,
        "ready_for_reverse_export": False,
    }
    with private_file(output / "result.json", "w") as stream:
        json.dump(record, stream, sort_keys=True, indent=2)
        stream.write("\n")
    return record


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--snapshot", type=Path, required=True)
    parser.add_argument("--snapshot-sha256", required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--hash-limit-mib-per-second", type=int, default=0,
                        metavar="MIB", help="pace checksum reads on a shared host (0 leaves them unpaced)")
    parser.add_argument("--messaging-compatibility-overlay", action="store_true",
                        help="apply the reviewed optional messaging fields to the empty template")
    args = parser.parse_args()
    os.umask(0o077)
    try:
        require(0 <= args.hash_limit_mib_per_second <= 1024, "invalid-checksum-read-limit")
        print(json.dumps(build(args.snapshot, args.snapshot_sha256, args.output,
                               args.hash_limit_mib_per_second, args.messaging_compatibility_overlay),
                         sort_keys=True))
    except Refused as error:
        print(json.dumps({"result": "REFUSED", "reason": str(error)}))
        return 1
    except (OSError, sqlite3.Error, ValueError):
        print(json.dumps({"result": "REFUSED", "reason": "artifact-or-schema-failure"}))
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
