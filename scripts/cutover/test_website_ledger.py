import csv
import hashlib
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

from scripts.cutover.verify_website_replay import compare, protected_ledger
from scripts.cutover.website_ledger import export


def sha(value):
    return hashlib.sha256(value.encode()).hexdigest()


class WebsiteLedgerExportTest(unittest.TestCase):
    """P2-06: only unconfirmed website submissions enter the operator ledger."""

    def setUp(self):
        self.root = TemporaryDirectory()
        self.dir = Path(self.root.name)
        self.rows = [
            {'producer': 'booking', 'reference': 'b-confirmed', 'state': 'confirmed', 'request': '{"a":1}', 'attachment_sha256s': []},
            {'producer': 'booking', 'reference': 'b-quoted', 'state': 'sync_failed', 'request': '{"b":1}', 'attachment_sha256s': []},
            {'producer': 'booking', 'reference': 'b-unquoted', 'state': 'pending_without_quote', 'request': '{"c":1}', 'attachment_sha256s': []},
            {'producer': 'booking', 'reference': 'b-reconcile', 'state': 'reconciliation_pending', 'request': '{"d":1}', 'attachment_sha256s': []},
            {'producer': 'contact', 'reference': 'c-uncertain', 'state': 'uncertain', 'request': '{"e":1}', 'attachment_sha256s': []},
            {'producer': 'contact', 'reference': 'c-delivered', 'state': 'delivered', 'request': '{"f":1}', 'attachment_sha256s': []},
        ]
        self.source = self.dir/'website-export.json'
        self.source.write_text(json.dumps({'site_commit': 'a'*40, 'freeze_sha256': 'b'*64, 'rows': self.rows}))

    def tearDown(self):
        self.root.cleanup()

    def run_export(self):
        return export(self.source, self.dir/'capture.json', self.dir/'private.csv', self.dir/'references.json')

    def test_emits_exactly_the_unconfirmed_rows_with_hashed_identity(self):
        summary = self.run_export()
        capture = json.loads((self.dir/'capture.json').read_text())
        self.assertEqual(capture['schema'], 'repro.website-capture.v1')
        expected = {('booking', sha('b-quoted')), ('booking', sha('b-unquoted')), ('booking', sha('b-reconcile')), ('contact', sha('c-uncertain'))}
        self.assertEqual({(row['producer'], row['reference_sha256']) for row in capture['rows']}, expected)
        self.assertIn({'producer': 'booking', 'reference_sha256': sha('b-quoted'), 'payload_sha256': sha('{"b":1}'),
                       'attachment_sha256s': []}, capture['rows'])
        self.assertNotIn('b-quoted', (self.dir/'capture.json').read_text())
        self.assertEqual(summary['capture_sha256'], hashlib.sha256((self.dir/'capture.json').read_bytes()).hexdigest())
        self.assertEqual(summary['counts'], {'booking': 3, 'contact': 1})
        with open(self.dir/'private.csv', newline='') as handle:
            private = list(csv.DictReader(handle))
        self.assertEqual(sorted(row['reference'] for row in private), ['b-quoted', 'b-reconcile', 'b-unquoted', 'c-uncertain'])
        references = json.loads((self.dir/'references.json').read_text())
        self.assertEqual(len(references['rows']), 4)

    def test_replay_matches_and_an_omitted_row_is_refused(self):
        summary = self.run_export()
        capture = protected_ledger(self.dir/'capture.json', summary['capture_sha256'])
        replay = {'schema': 'repro.website-replay.v1', 'site_commit': capture['site_commit'], 'freeze_sha256': capture['freeze_sha256'],
                  'rows': [row | {'verified_business_effects': 1} for row in capture['rows']]}
        self.assertEqual(compare(capture, replay)['verified_records'], 4)
        with self.assertRaises(ValueError):
            compare(capture, replay | {'rows': replay['rows'][1:]})

    def test_refuses_to_overwrite_and_rejects_unknown_producers_or_duplicates(self):
        self.run_export()
        with self.assertRaises(FileExistsError):
            self.run_export()
        for bad in ([{'producer': 'other', 'reference': 'x', 'state': 'pending', 'request': '{}', 'attachment_sha256s': []}],
                    [self.rows[1], self.rows[1]]):
            with self.subTest(bad=bad):
                target = Path(self.root.name)/('bad-'+str(len(bad)))
                target.mkdir()
                self.source.write_text(json.dumps({'site_commit': 'a'*40, 'freeze_sha256': 'b'*64, 'rows': bad}))
                with self.assertRaises(ValueError):
                    export(self.source, target/'c.json', target/'p.csv', target/'r.json')


if __name__ == '__main__':
    unittest.main()
