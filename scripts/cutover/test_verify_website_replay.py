import hashlib
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

from scripts.cutover.verify_website_replay import compare, protected_ledger


class WebsiteReplayTest(unittest.TestCase):
    def setUp(self):
        identity = {'site_commit': 'a'*40, 'freeze_sha256': 'b'*64}
        common = {'reference_sha256': 'c'*64, 'payload_sha256': 'd'*64, 'attachment_sha256s': ['e'*64]}
        rows = [{'producer': producer, **common} for producer in ('contact', 'booking', 'recruiting')]
        self.capture = {'schema': 'repro.website-capture.v1', **identity, 'rows': rows}
        self.replay = {'schema': 'repro.website-replay.v1', **identity,
                       'rows': [row | {'verified_business_effects': 1} for row in reversed(rows)]}

    def test_original_payloads_attach_once_across_different_order(self):
        self.assertEqual(compare(self.capture, self.replay)['producer_counts'],
                         {'contact': 1, 'booking': 1, 'recruiting': 1})

    def test_reproduced_source_edit_and_changed_attachment_refuse(self):
        for name, replacement in (('payload_sha256', 'f'*64), ('attachment_sha256s', [])):
            with self.subTest(name=name):
                actual = self.replay['rows'][0] | {name: replacement}
                replay = self.replay | {'rows': [actual, *self.replay['rows'][1:]]}
                with self.assertRaises(ValueError):
                    compare(self.capture, replay)

    def test_missing_extra_and_duplicate_references_refuse(self):
        rows = self.replay['rows']
        for invalid in (rows[:-1], rows+rows[:1], rows+[rows[0] | {'reference_sha256': 'f'*64}]):
            with self.assertRaises(ValueError):
                compare(self.capture, self.replay | {'rows': invalid})

    def test_two_effects_and_mismatched_freeze_refuse(self):
        self.replay['rows'][0]['verified_business_effects'] = 2
        with self.assertRaises(ValueError):
            compare(self.capture, self.replay)
        self.replay['rows'][0]['verified_business_effects'] = 1
        with self.assertRaises(ValueError):
            compare(self.capture, self.replay | {'freeze_sha256': 'f'*64})

    def test_changed_receipt_bytes_refuse(self):
        with TemporaryDirectory() as root:
            ledger = Path(root)/'proof.json'
            ledger.write_bytes(b'{"rows": []}')
            checksum = hashlib.sha256(ledger.read_bytes()).hexdigest()
            self.assertEqual(protected_ledger(ledger, checksum), {'rows': []})
            ledger.write_bytes(b'{"rows": [1]}')
            with self.assertRaises(ValueError):
                protected_ledger(ledger, checksum)
