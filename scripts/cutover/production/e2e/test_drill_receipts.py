"""Fault-inject receipt streams at the outer drill's launcher boundary."""
import contextlib
import hashlib
import io
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import run_drill as drill


class DrillVerdictTest(unittest.TestCase):
    def run_with(self, mode, fault=None):
        with tempfile.TemporaryDirectory() as directory, patch.object(drill, "Outer") as outer:
            root = Path(directory)
            instance = outer.return_value
            instance.srv = root
            instance.host_step.return_value = "fixture setup"

            def launch(n, first, last, reboot):
                self.assertFalse(reboot)
                names = drill.NAMES[drill.NAMES.index(first):drill.NAMES.index(last) + 1]
                if mode == "--a3-only":
                    names = [name for name in names if name in drill.A3_STEPS]
                rows = [{"step": name, "status": "passed", "detail": "fixture",
                         "seconds": 0.1, "ended_at": pos + 1} for pos, name in enumerate(names)]
                if n == 2 and fault:
                    if fault == "absent":
                        return 0
                    if fault == "empty":
                        rows.clear()
                    elif fault == "missing first":
                        rows.pop(0)
                    elif fault == "missing A3":
                        rows.pop()
                    elif fault == "duplicate":
                        rows.append(rows[-1])
                    elif fault == "out of order":
                        rows[-2:] = reversed(rows[-2:])
                    elif fault == "unexpected":
                        rows[-1]["step"] = "r2.unexpected"
                    elif fault == "invalid fields":
                        del rows[-1]["seconds"]
                    elif fault != "truncated":
                        rows[-1]["status"] = fault
                path = drill.Paths(drill.root_for(n, root)).results
                path.parent.mkdir(parents=True)
                data = "".join(json.dumps(row) + "\n" for row in rows)
                if n == 2 and fault == "truncated":
                    data = data[:-3]
                path.write_text(data, encoding="utf-8")
                return 0

            instance.launch.side_effect = launch
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                code = drill.main(["run", *([mode] if mode else [])])
            return code, output.getvalue()

    def test_complete_a3_and_full_runs(self):
        for mode in (None, "--a3-only"):
            with self.subTest(mode=mode):
                code, report = self.run_with(mode)
                self.assertEqual(code, 0, report)
                self.assertIn("Verdict: GREEN:", report)
                self.assertIn("r2.a3-copy-load", report)

    def test_incomplete_duplicate_unordered_and_nonpassing_receipts_in_both_modes(self):
        for mode in (None, "--a3-only"):
            for fault in ("absent", "empty", "missing first", "missing A3", "duplicate", "out of order",
                          "unexpected", "failed", "skipped", "stand-in", "pending", "bad status", "truncated",
                          "invalid fields"):
                with self.subTest(mode=mode, fault=fault):
                    code, report = self.run_with(mode, fault)
                    self.assertEqual(code, drill.FAILED_EXIT, report)
                    self.assertIn("Verdict: RED:", report)

    def test_development_mode_never_green_even_if_all_receipts_pass(self):
        code, report = self.run_with("--stand-ins")
        self.assertEqual(code, drill.STAND_IN_EXIT, report)
        self.assertIn("Verdict: RED:", report)


class VendorIdentityTest(unittest.TestCase):
    def test_install_manifest_and_lock_must_agree_including_dev_versions_and_refs(self):
        prod = {"name": "test/prod", "version": "1", "source": {"reference": "aaa"}}
        dev = {"name": "test/dev", "version": "2", "dist": {"reference": "bbb"}}
        with tempfile.TemporaryDirectory() as directory:
            lock, installed = (Path(directory) / name for name in ("composer.lock", "installed.json"))
            lock.write_text(json.dumps({"packages": [prod], "packages-dev": [dev]}))
            installed.write_text(json.dumps({"packages": [dev, prod]}))
            self.assertEqual(drill.locked_vendor_identity(lock, installed), {
                "packages": 2, "composer_lock_sha256": hashlib.sha256(lock.read_bytes()).hexdigest(),
                "installed_json_sha256": hashlib.sha256(installed.read_bytes()).hexdigest()})
            for packages in ([prod], [prod, dev, dev], [prod, dict(dev, version="3")],
                             [prod, dict(dev, dist={"reference": "other"})]):
                with self.subTest(packages=packages):
                    installed.write_text(json.dumps({"packages": packages}))
                    with self.assertRaises(drill.StepFailed):
                        drill.locked_vendor_identity(lock, installed)


if __name__ == "__main__":
    unittest.main()
