#!/usr/bin/env python3
"""Measure and verify an archival cleanup on a private SQLite snapshot derivative."""

import argparse
import datetime
import hashlib
import json
import os
import shutil
import sqlite3
import stat
import tempfile
from contextlib import closing
from pathlib import Path


JOB = 'App\\Jobs\\IngestIguideAssetsJob'
ERROR = 'RuntimeException: Provider floorplan document is not ready; no HTML payload was published.'
JOB_FILTER = '''date(failed_at) < ? AND queue = 'default' AND instr(exception, ?) = 1
    AND CASE WHEN json_valid(payload) THEN json_extract(payload, '$.displayName') END = ?'''


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def immutable(path):
    return path.as_uri() + '?mode=ro&immutable=1'


def quote(identifier):
    return '"' + identifier.replace('"', '""') + '"'


def catalog(db):
    return db.execute('SELECT type, name, tbl_name, sql FROM sqlite_schema ORDER BY name').fetchall()


def plans(db, schema):
    for kind, name, _, _ in schema:
        if kind != 'table':
            continue
        table = quote(name)
        fields = db.execute(f'PRAGMA table_xinfo({table})').fetchall()
        columns = [field[1] for field in fields if field[6] != 1]
        primary = sorted((field[5], field[1]) for field in fields if field[5])
        yield name, table, columns, [column for _, column in primary] or columns


def health(db):
    require(db.execute('PRAGMA integrity_check').fetchall() == [('ok',)], 'SQLite integrity check failed')
    return sorted(db.execute('PRAGMA foreign_key_check'), key=repr)


def verify(original, rebuilt, archive, expected_jobs, expected_payloads):
    with closing(sqlite3.connect(immutable(original), uri=True)) as before, \
            closing(sqlite3.connect(immutable(rebuilt), uri=True)) as after:
        after.execute('ATTACH DATABASE ? AS archive', (immutable(archive),))
        before.execute('PRAGMA query_only=ON')
        after.execute('PRAGMA query_only=ON')
        schema = catalog(before)
        require(schema == catalog(after), 'SQLite schema changed')
        require(health(before) == health(after), 'Foreign-key exception set changed')
        require(after.execute('PRAGMA archive.integrity_check').fetchall() == [('ok',)], 'Archive integrity check failed')
        require(after.execute('SELECT count(*) FROM archive.failed_jobs').fetchone()[0] == expected_jobs,
                'Archived failed-job count differs')
        require(after.execute('''SELECT count(*) FROM archive.ai_payloads x JOIN main.ai_editing_jobs j USING (id)
            WHERE j.provider_payload IS x.replacement''').fetchone()[0] == expected_payloads,
                'The compacted AI payloads differ from the recorded replacements')
        tables = rows = fields = 0
        for name, table, columns, order in plans(before, schema):
            projection = ', '.join(map(quote, columns))
            order_by = ', '.join(map(quote, order))
            original_query = f'SELECT {projection} FROM main.{table} ORDER BY {order_by}'
            restored_query = original_query
            if name == 'failed_jobs':
                restored_query = (f'SELECT {projection} FROM main.{table} UNION ALL '
                                  f'SELECT {projection} FROM archive.{table} ORDER BY {order_by}')
            if name == 'ai_editing_jobs':
                restored_fields = ', '.join(
                    'CASE WHEN x.id IS NULL THEN j.provider_payload ELSE x.original END AS provider_payload'
                    if column == 'provider_payload' else f'j.{quote(column)}' for column in columns)
                restored_query = (f'SELECT {restored_fields} FROM main.{table} j '
                                  f'LEFT JOIN archive.ai_payloads x ON x.id = j.id ORDER BY j.{quote(order[0])}')
            for old_row, restored_row in zip(before.execute(original_query), after.execute(restored_query), strict=True):
                require(old_row == restored_row and all(type(a) is type(b) for a, b in zip(old_row, restored_row, strict=True)),
                        f'Logical reconstruction differs in {name}')
                rows += 1
                fields += len(columns)
            tables += 1
        return {'tables_verified': tables, 'rows_verified_with_archive': rows, 'fields_verified_with_archive': fields}


def archive_and_compact(working, archive, rebuilt, cutoff):
    with closing(sqlite3.connect(working)) as db:
        db.execute('PRAGMA main.journal_mode=DELETE')
        db.execute('PRAGMA main.synchronous=FULL')
        db.execute('PRAGMA foreign_keys=ON')
        db.execute('ATTACH DATABASE ? AS archive', (archive,))
        db.execute('PRAGMA archive.synchronous=FULL')
        require(not db.execute("SELECT 1 FROM sqlite_schema WHERE type='trigger' AND tbl_name IN ('failed_jobs','ai_editing_jobs')").fetchone(),
                'Rehearsal tables must have no triggers')
        daily = dict(db.execute('''SELECT date(failed_at), count(*) FROM main.failed_jobs
            WHERE date(failed_at) >= '2026-11-14' AND instr(exception, ?) = 1
            AND CASE WHEN json_valid(payload) THEN json_extract(payload, '$.displayName') END = ?
            GROUP BY date(failed_at) ORDER BY date(failed_at)''', (ERROR, JOB)))
        with db:
            db.execute('BEGIN IMMEDIATE')
            db.execute('CREATE TABLE archive.failed_jobs AS SELECT * FROM main.failed_jobs WHERE ' + JOB_FILTER,
                       (cutoff, ERROR, JOB))
            db.execute('CREATE UNIQUE INDEX archive.failed_jobs_id ON failed_jobs(id)')
            jobs, job_bytes = db.execute('''SELECT count(*), coalesce(sum(length(cast(payload AS BLOB))
                + length(cast(exception AS BLOB))), 0) FROM archive.failed_jobs''').fetchone()
            db.execute('CREATE TABLE archive.ai_payloads (id INTEGER PRIMARY KEY, original TEXT NOT NULL, replacement TEXT NOT NULL)')
            completed_fal = """SELECT id, provider_payload FROM main.ai_editing_jobs
                WHERE status='completed' AND provider='fal' AND typeof(provider_payload)='text'"""
            for job_id, original in db.execute(completed_fal):
                try:
                    payload = json.loads(original)
                except (ValueError, RecursionError):
                    continue
                if not isinstance(payload, dict) or not isinstance(payload.get('payload'), dict):
                    continue
                del payload['payload']
                replacement = json.dumps(payload, ensure_ascii=False, separators=(',', ':'))
                if len(replacement.encode()) < len(original.encode()):
                    db.execute('INSERT INTO archive.ai_payloads VALUES (?, ?, ?)', (job_id, original, replacement))
        with db:
            db.execute('BEGIN IMMEDIATE')
            removed = db.execute('DELETE FROM main.failed_jobs WHERE id IN (SELECT id FROM archive.failed_jobs)').rowcount
            require(removed == jobs, 'Failed-job cleanup count differs from archive')
            changed = db.execute('''UPDATE main.ai_editing_jobs SET provider_payload = (
                SELECT replacement FROM archive.ai_payloads x WHERE x.id=ai_editing_jobs.id)
                WHERE id IN (SELECT id FROM archive.ai_payloads)''').rowcount
            payloads, payload_bytes = db.execute('''SELECT count(*), coalesce(sum(length(cast(original AS BLOB))
                - length(cast(replacement AS BLOB))), 0) FROM archive.ai_payloads''').fetchone()
            require(changed == payloads, 'AI payload update count differs from archive')
        db.execute('DETACH DATABASE archive')
        db.execute('VACUUM INTO ?', (rebuilt,))
        return {'archived_failed_jobs': jobs, 'failed_job_text_bytes': job_bytes, 'ai_payloads_compacted': payloads,
                'ai_payload_bytes_removed': payload_bytes,
                'iguide_failed_jobs_recent_days': daily}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--copy-receipt', required=True, type=Path)
    parser.add_argument('--before', required=True, type=datetime.date.fromisoformat)
    args = parser.parse_args()
    receipt = args.copy_receipt
    require(receipt.is_absolute() and receipt.resolve(strict=True) == receipt and receipt.is_file(), 'Use the absolute private receipt path')
    manifest = json.loads(receipt.read_bytes())
    source = Path(manifest['snapshot_path'])
    require(manifest['result'] == 'read-only-online-backup-pass' and manifest['source_opened_read_only'] is True,
            'A verified read-only online backup is required')
    require(source.parent == receipt.parent and source != receipt and source.is_file() and source.resolve(strict=True) == source,
            'Source must be a plain file alongside its backup receipt')
    for path in (source.parent, source, receipt):
        details = path.stat()
        require(details.st_uid == os.getuid() and not details.st_mode & (stat.S_IRWXG | stat.S_IRWXO),
                'Source, receipt and parent must belong to the current user and be private')
    require(not any(source.with_name(source.name + suffix).exists() for suffix in ('-wal', '-shm', '-journal')),
            'The source must be a closed snapshot')
    source_sha = digest(source)
    size = source.stat().st_size
    require(source_sha == manifest['snapshot_sha256'] and size == manifest['snapshot_bytes'], 'Snapshot does not match its receipt')
    require(shutil.disk_usage(source.parent).free >= size * 3, 'Insufficient space for the disposable copies and archive')
    os.umask(0o077)
    with tempfile.TemporaryDirectory(prefix='cleanup-rehearsal-', dir=source.parent) as path:
        work = Path(path)
        staged = work / 'working.sqlite'
        archive = work / 'archive.sqlite'
        rebuilt = work / 'compacted.sqlite'
        shutil.copyfile(source, staged)
        require(digest(staged) == source_sha, 'The disposable derivative differs from the snapshot')
        changes = archive_and_compact(staged, str(archive), str(rebuilt), args.before.isoformat())
        verified = verify(source, rebuilt, archive, changes['archived_failed_jobs'], changes['ai_payloads_compacted'])
        require(digest(source) == source_sha, 'The source snapshot checksum changed')
        evidence = {**changes, **verified, 'cutoff_exclusive': args.before.isoformat(), 'snapshot_sha256': source_sha,
                    'original_bytes': size, 'compacted_bytes': rebuilt.stat().st_size, 'archive_bytes': archive.stat().st_size}
    print(json.dumps({**evidence, 'temporary_derivatives_removed': True}, sort_keys=True))


if __name__ == '__main__':
    main()
