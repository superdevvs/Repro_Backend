import contextlib
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

import rotation_attempt as runner


def stock(result, requested, **fields):
    return (json.dumps({"result": result, "rotation_requested": requested, "remote_accessed": False,
                        **fields}, sort_keys=True) + "\n").encode("ascii")


VERIFIED = stock("closed-binlog-rotation-verified", True, closed_file="binlog.000001", active_file="binlog.000002")
REFUSED = stock("REFUSED", False, reason="rotation source did not match")
UNCERTAIN = stock("closed-binlog-rotation-unverified", True, closed_candidate="binlog.000001",
                  reason="rotation was requested but its exact outcome could not be verified")


class RotationGuardTests(unittest.TestCase):
    def test_guard_release_matches_stock_outcome_and_exit(self):
        cases = (
            (0, VERIFIED, 0, False),
            (1, REFUSED, 1, False),
            (1, UNCERTAIN, 1, True),
            (0, REFUSED, 1, True),
            (1, VERIFIED, 1, True),
            (-15, REFUSED, 1, True),
            (1, b"", 1, True),
            (1, REFUSED.replace(b'"rotation_requested": false', b'"rotation_requested": 0'), 1, True),
            (1, REFUSED.replace(b'"rotation_requested": false', b'"rotation_requested": true, "rotation_requested": false'),
             1, True),
        )
        for child_code, report, exit_code, held in cases:
            with self.subTest(code=child_code, report=report), tempfile.TemporaryDirectory() as scratch:
                guard = Path(scratch) / "attempt.pending"
                guard.mkdir()
                completed = subprocess.CompletedProcess([], child_code, stdout=report)
                with (patch.object(runner, "GUARD", guard),
                      patch.object(runner.subprocess, "run", return_value=completed) as child,
                      patch.object(runner.os, "fsync", wraps=runner.os.fsync) as sync,
                      contextlib.redirect_stdout(io.StringIO())):
                    self.assertEqual(runner.run("/approved/rotation.json", "f" * 64), exit_code)
                self.assertEqual(guard.is_dir(), held)
                self.assertEqual(sync.call_count, int(not held))
                self.assertEqual(child.call_args.args[0], [
                    "/usr/bin/python3", "-I", "-B", str(runner.ROTATOR),
                    "--plan", "/approved/rotation.json", "--plan-sha256", "f" * 64,
                ])

    def test_missing_guard_never_starts_stock_caller(self):
        with tempfile.TemporaryDirectory() as scratch:
            with (patch.object(runner, "GUARD", Path(scratch) / "attempt.pending"),
                  patch.object(runner.subprocess, "run") as child,
                  contextlib.redirect_stdout(io.StringIO())):
                self.assertEqual(runner.run("/approved/rotation.json", "f" * 64), 1)
            child.assert_not_called()

    def test_cannot_launch_stock_caller_leaves_guard_held(self):
        with tempfile.TemporaryDirectory() as scratch:
            guard = Path(scratch) / "attempt.pending"
            guard.mkdir()
            with (patch.object(runner, "GUARD", guard),
                  patch.object(runner.subprocess, "run", side_effect=OSError),
                  contextlib.redirect_stdout(io.StringIO())):
                self.assertEqual(runner.run("/approved/rotation.json", "f" * 64), 1)
            self.assertTrue(guard.is_dir())

    def test_parent_sync_failure_is_never_reported_as_a_durable_release(self):
        with tempfile.TemporaryDirectory() as scratch:
            guard = Path(scratch) / "attempt.pending"
            guard.mkdir()
            with (patch.object(runner, "GUARD", guard),
                  patch.object(runner.subprocess, "run", return_value=subprocess.CompletedProcess([], 0, stdout=VERIFIED)),
                  patch.object(runner.os, "fsync", side_effect=OSError),
                  contextlib.redirect_stdout(io.StringIO()) as output):
                self.assertEqual(runner.run("/approved/rotation.json", "f" * 64), 1)
            self.assertIs(json.loads(output.getvalue())["rotation_guard_release_durable"], False)


class GuardOptionTests(unittest.TestCase):
    def test_production_unit_can_name_its_own_guard(self):
        with tempfile.TemporaryDirectory() as scratch:
            guard = Path(scratch) / "attempt.pending"
            guard.mkdir()
            completed = subprocess.CompletedProcess([], 0, stdout=VERIFIED)
            argv = ["rotation_attempt.py", "--plan", "/approved/rotation.json", "--plan-sha256", "f" * 64,
                    "--guard", str(guard)]
            with (patch.object(runner.sys, "argv", argv),
                  patch.object(runner.subprocess, "run", return_value=completed),
                  contextlib.redirect_stdout(io.StringIO())):
                self.assertEqual(runner.main(), 0)
            self.assertFalse(guard.exists())
            self.assertEqual(runner.GUARD, Path("/var/lib/repro-binlog-fixture-rotation/attempt.pending"))

    def test_guard_must_be_an_absolute_attempt_pending_path(self):
        for value in ("relative/attempt.pending", "/var/lib/x/other", "/var/lib/../attempt.pending"):
            argv = ["rotation_attempt.py", "--plan", "/p.json", "--plan-sha256", "f" * 64, "--guard", value]
            with (patch.object(runner.sys, "argv", argv), patch.object(runner.subprocess, "run") as child,
                  contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO())):
                self.assertNotEqual(runner.main(), 0)
            child.assert_not_called()
