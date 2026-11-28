"""The read-only view of the cutover journal that the alert probe reads.

Journals here are written by the real cutover runner (scripts/cutover/runner.py)
with its fixture adapter, so a change to the journal format breaks these tests.
"""
from __future__ import annotations

import contextlib
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest

import cutover_view as view

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO / "scripts" / "cutover"))
from rehearse import FixtureAdapter  # noqa: E402
from runner import Identity, Journal, Runner  # noqa: E402

BOOT = FixtureAdapter.BOOT_ID
REBOOTED = "6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11"
NOW = 1_800_000_000


class CutoverViewTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.root = Path(folder.name)
        self.journal = Journal(self.root / "repro-cutover-fence")
        self.identity = Identity("cutover-2026-12-05", "1" * 40, "2" * 64, "3" * 64, "4" * 64,
                                 "ec219d68-61fd-4a9e-ba98-43ddb4008383")
        self.adapter = FixtureAdapter(self.journal, self.identity)
        self.now = NOW
        self.runner = Runner(self.journal, self.identity, self.adapter, lambda: self.now)

    def build(self, boot=BOOT):
        return view.build(self.journal.path, boot, self.now)

    def hold(self):
        self.runner.initialize()
        self.runner.perform("hold", self.runner.approval_request("hold"))

    def test_no_journal_is_reported_absent(self):
        record = self.build()
        self.assertEqual(record, {"schema": view.SCHEMA, "observed_unix_s": NOW, "boot_id": BOOT, "journal": "absent"})

    @unittest.skipUnless(os.name != "posix" or os.geteuid() == 0, "retirement records must be root-owned")
    def test_retired_authority_is_redacted_and_active_journal_takes_precedence(self):
        retired = self.root / "retired.json"
        marker = {"version": 1, "run_id": self.identity.run_id, "engine": "mysql",
                  "archive": "/var/lib/repro-cutover-archive/" + self.identity.run_id + "-20261128T083000Z"}
        retired.write_text(json.dumps(marker))
        retired.chmod(0o600)
        def read():
            return view.build(self.journal.path, BOOT, NOW, retired=retired)
        self.assertEqual({"schema": view.SCHEMA, "observed_unix_s": NOW, "boot_id": BOOT, "journal": "retired",
                          "run_id": self.identity.run_id, "engine": "mysql"}, read())
        for changes in ({"engine": "pg"}, {"archive": "/tmp/anything"},
                        {"archive": marker["archive"].replace("20261128", "20260231")}):
            retired.write_text(json.dumps({**marker, **changes}))
            self.assertEqual("unreadable", read()["journal"])
        retired.write_text(json.dumps(marker))
        pending = self.journal.path.with_name("retire-pending.json")
        pending.write_text("{}")
        self.assertEqual("unreadable", read()["journal"])
        pending.unlink()
        if os.name == "posix":
            retired.chmod(0o666)
            self.assertEqual("unreadable", read()["journal"])
            retired.chmod(0o600)
        retired.write_text(json.dumps(marker))
        self.runner.initialize()
        self.assertEqual("present", read()["journal"])
        self.journal.path.write_text("broken")
        self.assertEqual("unreadable", read()["journal"])

    def test_planned_journal_has_no_hold(self):
        self.runner.initialize()
        record = self.build()
        self.assertEqual(record["journal"], "present")
        self.assertEqual((record["phase"], record["started_at"], record["hold_boot_id"], record["writes_possible"]),
                         ("planned", None, None, False))
        self.assertEqual(record["run_id"], "cutover-2026-12-05")

    def test_hold_copies_only_phase_clock_boot_and_run(self):
        self.hold()
        self.now += 300
        record = self.build()
        self.assertEqual(record, {
            "schema": view.SCHEMA, "observed_unix_s": NOW + 300, "boot_id": BOOT, "journal": "present",
            "run_id": "cutover-2026-12-05", "phase": "holding", "writes_possible": False, "started_at": NOW,
            "pending_action": None, "hold_boot_id": BOOT})
        state = self.journal.read()
        text = json.dumps(record)
        for secret in (state["challenge"], state["preflight_sha256"], self.identity.source_sha256,
                       state["history"][-1]["approval"]["challenge"]):
            self.assertNotIn(secret, text)

    def test_hold_boot_stays_the_boot_of_the_hold_after_the_watchdog_reasserts_it(self):
        # The tick after a reboot moves state["hold"]["boot_id"] to the new boot. The
        # view must keep the boot the hold was taken in, or a reboot would go unseen.
        self.hold()
        self.runner.perform("freeze")
        self.adapter.BOOT_ID = REBOOTED
        self.now += 120
        self.runner.perform("tick")
        self.assertEqual(self.journal.read()["hold"]["boot_id"], REBOOTED)
        record = self.build(REBOOTED)
        self.assertEqual((record["phase"], record["hold_boot_id"], record["boot_id"]), ("frozen", BOOT, REBOOTED))

    def test_interrupted_hold_takes_its_boot_from_the_pending_intent(self):
        self.runner.initialize()
        state = self.journal.read()
        state.update(phase="holding", started_at=NOW, pending={"action": "hold", "at": NOW, "boot_id": BOOT,
                                                               "hold_id": "x", "before": {}})
        self.journal.write(state)
        record = self.build()
        self.assertEqual((record["pending_action"], record["hold_boot_id"]), ("hold", BOOT))

    def test_rollback_after_the_hold_keeps_the_hold_clock(self):
        self.hold()
        self.now += 60
        self.runner.perform("abort")
        record = self.build()
        self.assertEqual((record["phase"], record["started_at"], record["hold_boot_id"]),
                         ("sqlite_authoritative", NOW, BOOT))

    def test_unreadable_or_unexpected_journal_is_reported_unreadable(self):
        self.runner.initialize()
        good = self.journal.path.read_bytes()
        for broken in (b"", b"{", b"[]", good.replace(b'"version": 1', b'"version": 2'),
                       good.replace(b'"phase": "planned"', b'"phase": "Planned; rm"'),
                       good.replace(b'"started_at": null', b'"started_at": true'),
                       good.replace(b'"writes_possible": false', b'"writes_possible": 0'),
                       b'{"version": 1}', b" " * (view.MAX_JOURNAL_BYTES + 1)):
            self.journal.path.write_bytes(broken)
            self.assertEqual(self.build()["journal"], "unreadable", broken[:60])

    @unittest.skipUnless(os.name == "posix" and os.geteuid() != 0, "needs a file this account cannot read")
    def test_journal_that_cannot_be_opened_is_unreadable_not_absent(self):
        self.runner.initialize()
        self.journal.path.chmod(0)
        self.addCleanup(self.journal.path.chmod, 0o600)
        self.assertEqual(self.build()["journal"], "unreadable")

    def test_write_replaces_the_view_with_group_read_only(self):
        output = self.root / "out" / "view.json"
        output.parent.mkdir()
        view.write(output, self.build())
        self.assertEqual(json.loads(output.read_text())["journal"], "absent")
        if os.name == "posix":
            self.assertEqual(output.stat().st_mode & 0o777, 0o640)
        self.assertEqual([path.name for path in output.parent.iterdir()], ["view.json"])

    def test_main_reads_the_fixed_paths_and_never_changes_the_journal(self):
        self.hold()
        before = (self.journal.path.read_bytes(), self.journal.path.stat().st_mtime_ns)
        boot = self.root / "boot_id"
        boot.write_text(BOOT + "\n")
        output = self.root / "out" / "view.json"
        output.parent.mkdir()
        saved = view.JOURNAL, view.BOOT_ID, view.OUTPUT
        view.JOURNAL, view.BOOT_ID, view.OUTPUT = self.journal.path, boot, output
        try:
            with contextlib.redirect_stdout(io.StringIO()) as printed:
                self.assertEqual(view.main([]), 0)
        finally:
            view.JOURNAL, view.BOOT_ID, view.OUTPUT = saved
        self.assertEqual(json.loads(output.read_text())["phase"], "holding")
        self.assertEqual(json.loads(printed.getvalue()), {"result": "cutover-view-written", "journal": "present"})
        self.assertEqual((self.journal.path.read_bytes(), self.journal.path.stat().st_mtime_ns), before)


if __name__ == "__main__":
    unittest.main()
