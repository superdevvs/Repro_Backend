"""Compare independently pinned website capture and replay ledgers offline."""

import argparse
from collections import Counter
import hashlib
import json
from pathlib import Path

from scripts.cutover.runner import is_sha256


PRODUCERS = {'contact', 'booking', 'recruiting'}
IDENTITY = {'site_commit', 'freeze_sha256'}
COMMON = {'producer', 'reference_sha256', 'payload_sha256', 'attachment_sha256s'}


def require(condition, message):
    if not condition:
        raise ValueError(message)


def protected_ledger(path, expected):
    require(path.is_file() and not path.is_symlink(), 'Use a regular ledger file')
    raw = path.read_bytes()
    require(is_sha256(expected) and hashlib.sha256(raw).hexdigest() == expected, 'Ledger checksum differs')
    return json.loads(raw)


def entries(record, schema, fields):
    require(isinstance(record, dict) and set(record) == {'schema', 'rows', *IDENTITY}
            and record['schema'] == schema and isinstance(record['rows'], list), 'Malformed ledger')
    indexed = {}
    for row in record['rows']:
        require(isinstance(row, dict) and set(row) == fields, 'Malformed ledger entry')
        require(isinstance(row['producer'], str) and row['producer'] in PRODUCERS
                and is_sha256(row['reference_sha256']) and is_sha256(row['payload_sha256'])
                and isinstance(row['attachment_sha256s'], list)
                and all(is_sha256(value) for value in row['attachment_sha256s']), 'Malformed replay fingerprints')
        key = (row['producer'], row['reference_sha256'])
        require(key not in indexed, 'A producer reference has more than one disposition')
        indexed[key] = row
    return indexed


def compare(capture, replay):
    before = entries(capture, 'repro.website-capture.v1', COMMON)
    after = entries(replay, 'repro.website-replay.v1', COMMON | {'verified_business_effects'})
    require(isinstance(capture['site_commit'], str) and len(capture['site_commit']) == 40
            and all(character in '0123456789abcdef' for character in capture['site_commit'])
            and is_sha256(capture['freeze_sha256'])
            and all(capture[name] == replay[name] for name in IDENTITY), 'Website release or freeze differs')
    require(before.keys() == after.keys(), 'Capture and replay references differ')
    counts = Counter(dict.fromkeys(PRODUCERS, 0))
    for key, source in before.items():
        result = after[key]
        require(source['payload_sha256'] == result['payload_sha256'], 'The replay payload differs from capture')
        require(source['attachment_sha256s'] == result['attachment_sha256s'], 'The replay attachments differ from capture')
        effects = result['verified_business_effects']
        require(type(effects) is int and effects == 1, 'Expected one independently verified effect per reference')
        counts[source['producer']] += 1
    return {'result': 'website-replay-ledger-matched', 'site_commit': capture['site_commit'],
            'freeze_sha256': capture['freeze_sha256'], 'verified_records': len(before), 'producer_counts': counts}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('capture', 'replay'):
        parser.add_argument('--'+name, type=Path, required=True)
        parser.add_argument('--'+name+'-sha256', required=True)
    options = parser.parse_args()
    try:
        result = compare(protected_ledger(options.capture, options.capture_sha256),
                         protected_ledger(options.replay, options.replay_sha256))
    except (OSError, ValueError, TypeError) as error:
        print(json.dumps({'result': 'website-replay-ledger-refused', 'error_type': type(error).__name__}))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
