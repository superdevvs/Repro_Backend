"""Do not recognize partial or mismatched local case reports as a completed pair."""
import json
from pathlib import Path
import tempfile
import unittest

import verify_w2c as check


class ReceiptTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.path = Path(temp.name) / "cases.log"
        self.release = "a" * 40
        self.short = dict(version=1, case="short", result="local-recovery-verified", release=self.release,
                          original_run_id="fixture-one", server_uuid="db131ffe-4c85-4caf-beb0-8aa4217dc594",
                          close_sha256="1"*64, closed_coordinate_sha256="2"*64, frozen_sha256="3"*64,
                          installed_sha256="3"*64, source_tables=2, source_inode_preserved=True,
                          method="verified-prewrite", business_write_preserved=False, post_reopen_business_read=False,
                          closed_counts={"smoke":4, "business":0, "foreign":0},
                          local_fixture_elapsed_seconds=54.1, backup_transport="disconnected-readback-fixture")
        self.export = {**self.short, "case":"export", "original_run_id":"fixture-two",
                       "server_uuid":"44922526-73f2-40bd-966d-3fb5b47efeaa", "close_sha256":"4"*64,
                       "installed_sha256":"5"*64, "source_inode_preserved":False, "method":"verified-export",
                       "closed_counts":None, "business_write_preserved":True, "post_reopen_business_read":True}

    def run_pair(self, end="run-drill: run_drill.py exited 0"):
        self.path.write_text("\n".join([json.dumps(self.short), "run-drill: run_drill.py exited 0",
                                        json.dumps(self.export), end]))
        return check.verify(self.path, self.path, self.release)

    def test_completed_distinct_cases(self):
        self.assertEqual("local-recovery-pair-verified", self.run_pair()["result"])

    def test_incomplete_export_wrong_release_or_reused_target(self):
        original = self.export.copy()
        for update in ({"post_reopen_business_read":False}, {"release":"b"*40},
                       {"server_uuid":self.short["server_uuid"]}, {"source_inode_preserved":True},
                       {"close_sha256":"bad"}, {"method":"verified-prewrite"}):
            with self.subTest(fields=list(update)), self.assertRaises(ValueError):
                self.export = {**original, **update}
                self.run_pair()

    def test_exit_must_follow_the_final_receipt(self):
        for end in ("", "run-drill: run_drill.py exited 1"):
            with self.subTest(end=end), self.assertRaises(ValueError):
                self.run_pair(end)
