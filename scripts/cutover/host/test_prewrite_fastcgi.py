"""Unit tests for the FastCGI fixture's replay check, without nginx, PHP-FPM or MySQL."""

import importlib.util
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE), str(HERE.parents[2])]
spec = importlib.util.spec_from_file_location("prewrite_fastcgi", HERE / "prewrite-fastcgi.py")
fastcgi = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fastcgi)

RECEIPTS = {
    fastcgi.QUOTE_PATH: "synthetic-quote-view",
    fastcgi.WEBHOOK_PATH: "synthetic-resend-event",
    "/cubicasa_webhook.php": "synthetic-cubicasa-direct",
    "/iguide_webhook.php": "synthetic-iguide-direct",
}
PRISTINE = {"users": [], "notification_read_states": [], "jobs": [], "fixture_effects": []}
IMPORTED = {**PRISTINE, "users": [(41,)]}


class ReplayRoutesTests(unittest.TestCase):
    def rehearsal(self, imported, mysql_now):
        adapter = fastcgi.FastCgiRehearsal.__new__(fastcgi.FastCgiRehearsal)
        adapter.mysql_initial = PRISTINE
        adapter.imported = imported
        adapter.slow = None
        adapter.queue_drained = False

        def http(method="GET", code=200, *, host=fastcgi.VHOSTS[0], path=fastcgi.SMOKE_PATH, body=None):
            if path == fastcgi.SMOKE_PATH:
                return {"engine": "sqlite"}
            return {"receipt": RECEIPTS[path.split("?")[0]], "host": host}

        adapter.http = http
        adapter.rows_mysql = lambda: mysql_now
        return adapter

    def replay(self, adapter):
        effects = [(receipt,) for receipt in [*RECEIPTS.values(), "synthetic-slow-write"]]
        with patch.object(fastcgi, "sqlite_rows", return_value={"fixture_effects": effects}):
            adapter.replay_routes()

    def test_recovery_replay_compares_against_the_unimported_mysql_baseline(self):
        # The recovery scenario never calls prepare(), so nothing was imported.
        self.replay(self.rehearsal(None, PRISTINE))

    def test_recovery_replay_still_refuses_a_write_that_reached_mysql(self):
        with self.assertRaisesRegex(fastcgi.UnsafeCutover, "reached the sealed MySQL target"):
            self.replay(self.rehearsal(None, IMPORTED))

    def test_hold_first_replay_compares_against_the_imported_rows(self):
        self.replay(self.rehearsal(IMPORTED, IMPORTED))
        with self.assertRaisesRegex(fastcgi.UnsafeCutover, "reached the sealed MySQL target"):
            self.replay(self.rehearsal(IMPORTED, PRISTINE))


if __name__ == "__main__":
    unittest.main()
