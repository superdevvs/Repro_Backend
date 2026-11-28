"""Turn a read-only website export into the operator replay ledger (P2-06).

The website exports, read-only, every booking, contact and recruiting row that
was saved during or near the freeze, with its exact original request encoding
(see docs/mysql-lanes/producer-replay.md for the export query). This keeps only
rows the website has not confirmed and writes three new files:

- a repro.website-capture.v1 ledger with hashes only, for verify_website_replay;
- a private CSV with the raw references, kept under the private replay controls;
- a references file for `php artisan crm:website-ledger --references=...`.

It never reads or writes a database and never overwrites a file.
"""

import argparse
import csv
import hashlib
import json
import os
from pathlib import Path

from scripts.cutover.runner import is_sha256

# The only states that need no replay. Everything else goes to the ledger.
SETTLED = {'booking': {'confirmed'}, 'contact': {'delivered'}, 'recruiting': {'delivered'}}


def require(condition, message):
    if not condition:
        raise ValueError(message)


def sha256(text):
    return hashlib.sha256(text.encode('utf-8')).hexdigest()


def write_new(path, data):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'w', encoding='utf-8', newline='') as handle:
        handle.write(data)


def export(source, capture_path, private_path, references_path):
    record = json.loads(Path(source).read_text(encoding='utf-8'))
    require(isinstance(record, dict) and isinstance(record.get('rows'), list), 'Malformed website export')
    site_commit, freeze = record.get('site_commit'), record.get('freeze_sha256')
    require(isinstance(site_commit, str) and len(site_commit) == 40
            and all(c in '0123456789abcdef' for c in site_commit) and is_sha256(freeze), 'Website release or freeze is missing')
    for path in (capture_path, private_path, references_path):
        if Path(path).exists():
            raise FileExistsError(path)

    pending, seen = [], set()
    for row in record['rows']:
        require(isinstance(row, dict) and row.get('producer') in SETTLED and isinstance(row.get('reference'), str)
                and row['reference'] != '' and isinstance(row.get('state'), str) and isinstance(row.get('request'), str)
                and isinstance(row.get('attachment_sha256s'), list)
                and all(is_sha256(value) for value in row['attachment_sha256s']), 'Malformed website export row')
        key = (row['producer'], row['reference'].lower())
        require(key not in seen, 'A website reference appears more than once')
        seen.add(key)
        if row['state'] not in SETTLED[row['producer']]:
            pending.append(row)

    pending.sort(key=lambda row: (row['producer'], row['reference'].lower()))
    rows = [{'producer': row['producer'], 'reference_sha256': sha256(row['reference'].lower()),
             'payload_sha256': sha256(row['request']), 'attachment_sha256s': list(row['attachment_sha256s'])} for row in pending]
    capture = json.dumps({'schema': 'repro.website-capture.v1', 'site_commit': site_commit, 'freeze_sha256': freeze,
                          'rows': rows}, sort_keys=True, indent=1)
    write_new(capture_path, capture)

    lines = [['producer', 'reference', 'state', 'reference_sha256', 'payload_sha256']]
    lines += [[row['producer'], row['reference'], row['state'], sha256(row['reference'].lower()), sha256(row['request'])] for row in pending]
    descriptor = os.open(private_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'w', encoding='utf-8', newline='') as handle:
        csv.writer(handle).writerows(lines)

    write_new(references_path, json.dumps({'rows': [{'producer': row['producer'], 'reference': row['reference']}
                                                    for row in pending if row['producer'] in ('booking', 'contact')]}))

    counts = {}
    for row in pending:
        counts[row['producer']] = counts.get(row['producer'], 0) + 1
    return {'capture_sha256': hashlib.sha256(capture.encode('utf-8')).hexdigest(), 'counts': counts, 'rows': len(pending)}


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument('--export', type=Path, required=True, help='read-only website export (JSON)')
    parser.add_argument('--capture', type=Path, required=True)
    parser.add_argument('--private-csv', type=Path, required=True)
    parser.add_argument('--references', type=Path, required=True)
    options = parser.parse_args()
    try:
        summary = export(options.export, options.capture, options.private_csv, options.references)
    except (OSError, ValueError) as error:
        print(json.dumps({'result': 'website-ledger-refused', 'error_type': type(error).__name__}))
        return 1
    print(json.dumps({'result': 'website-ledger-written', **summary}, sort_keys=True))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
