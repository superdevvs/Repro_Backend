"""Linux subprocess rehearsal of journal fencing and interrupted authority publication."""

import json
import os
from pathlib import Path
import select
import signal
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

from fence import guard
from rehearse import FixtureAdapter
from runner import Identity, Journal, Runner, UnsafeCutover


FIXTURE_PREFIX = "repro-cutover-process-"
MACHINE_ID = b"synthetic-cutover-process-fixture"
IDENTITY = Identity(
    "process-fixture", "a" * 40, "b" * 64, "c" * 64, "d" * 64,
    "ec219d68-61fd-4a9e-ba98-43ddb4008383",
)


class ProcessAdapter(FixtureAdapter):
    MACHINE_SHA256 = guard.sha256(MACHINE_ID)
    pause_publication = False

    def publish_authority(self, lease, engine, candidate, deadline):
        super().publish_authority(lease, engine, candidate, deadline)
        if self.pause_publication:
            print("proof published", flush=True)
            sys.stdin.buffer.read(1)


def child_main(directory, role, operation):
    directory = Path(directory)
    if (
        sys.platform != "linux"
        or directory.resolve() != directory
        or directory.parent != Path(tempfile.gettempdir()).resolve()
        or not directory.name.startswith(FIXTURE_PREFIX)
        or not directory.is_dir()
        or (directory / "machine-id").read_bytes() != MACHINE_ID
    ):
        return 2
    journal = Journal(directory)
    adapter = ProcessAdapter(journal, IDENTITY)
    runner = Runner(journal, IDENTITY, adapter, max_drain_seconds=1, controller_wait_seconds=4)
    try:
        if role == "control":
            with journal.acquire():
                print("controlling", flush=True)
                sys.stdin.buffer.read(1)
            return 0
        if role == "runner" and operation == "tick":
            print(json.dumps(runner.perform("tick")), flush=True)
            return 0
        if role in {"check", "hold"}:
            fixture_runtime = SimpleNamespace(
                platform="linux", executable="/usr/bin/python3", flags=SimpleNamespace(isolated=1, no_site=1),
            )
            # Substitute only the fixture installation and privilege boundary.
            # Admission still reads and verifies the actual files under flock.
            with (
                patch.multiple(
                    guard, ENTRYPOINT=Path(guard.__file__).absolute(), sys=fixture_runtime,
                    STATE=directory, POLICY=directory / "policy.json", EVIDENCE=directory / "fixture-authority.json",
                    MACHINE_ID=directory / "machine-id", BOOT_ID=directory / "boot-id",
                ),
                patch.object(guard.os, "geteuid", return_value=0),
                patch.object(guard, "check_chain"),
                patch.object(guard, "trusted"),
                guard.admitted("scheduler", operation),
            ):
                print("admitted", flush=True)
                if role == "hold":
                    sys.stdin.buffer.read(1)
            return 0
        if role in {"runner", "pause-publication"} and operation in {"freeze", "prepare", "promote", "abort"}:
            adapter.pause_publication = role == "pause-publication"
            approval = runner.approval_request(operation) if operation in {"freeze", "promote"} else None
            runner.perform(operation, approval)
            return 0
    except BlockingIOError:
        print("admission lock held", file=sys.stderr)
        return 1
    except (UnsafeCutover, guard.Refused) as error:
        print(str(error), file=sys.stderr)
        return 1
    return 2


@unittest.skipUnless(sys.platform == "linux", "Requires Linux subprocess pipes and flock")
class CutoverProcessContractTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix=FIXTURE_PREFIX)
        self.addCleanup(temporary.cleanup)
        self.directory = Path(temporary.name).resolve()
        self.journal = Journal(self.directory)
        adapter = ProcessAdapter(self.journal, IDENTITY)
        (self.directory / "machine-id").write_bytes(MACHINE_ID)
        (self.directory / "boot-id").write_text(adapter.BOOT_ID, encoding="ascii")
        self.journal.write_record("policy.json", adapter.policy())
        Runner(self.journal, IDENTITY, adapter).initialize()
        proof = adapter.authority_evidence("sqlite", self.journal.path.read_bytes())
        self.journal.write_record("fixture-authority.json", proof)

    def command(self, role, operation):
        return [sys.executable, "-B", str(Path(__file__).resolve()), "--fixture-child",
                str(self.directory), role, operation]

    def call(self, role, operation, *, error=None):
        result = subprocess.run(
            self.command(role, operation), stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=5,
        )
        if error is None:
            self.assertEqual(0, result.returncode, result.stderr)
        else:
            self.assertEqual(1, result.returncode, result.stderr)
            self.assertIn(error, result.stderr)
        return result

    @staticmethod
    def close_child(child):
        if child.poll() is None:
            child.kill()
        for pipe in (child.stdin, child.stdout, child.stderr):
            if pipe is not None and not pipe.closed:
                pipe.close()
        child.wait(timeout=5)

    def pause_at(self, role, operation, message):
        child = subprocess.Popen(
            self.command(role, operation), stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        )
        self.addCleanup(self.close_child, child)
        self.assertTrue(select.select([child.stdout], [], [], 5)[0], f"Child did not reach {message}")
        self.assertEqual(message.encode() + b"\n", child.stdout.readline())
        self.assertIsNone(child.poll())
        return child

    def test_active_wrapper_prevents_another_process_from_freezing(self):
        original = self.journal.path.read_bytes()
        wrapper = self.pause_at("hold", "sqlite", "admitted")
        self.call("runner", "freeze", error="did not drain")
        self.assertIsNone(wrapper.poll())
        self.assertEqual(original, self.journal.path.read_bytes())
        _, errors = wrapper.communicate(b"x", timeout=5)
        self.assertEqual(0, wrapper.returncode, errors)
        self.call("runner", "freeze")
        self.assertEqual("frozen", self.journal.read()["phase"])
        self.call("check", "sqlite", error="holds all admissions")

    def test_watchdog_tick_waits_for_controller_lock(self):
        original = self.journal.path.read_bytes()
        controller = self.pause_at("control", "none", "controlling")
        tick = subprocess.Popen(self.command("runner", "tick"), stdin=subprocess.DEVNULL,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.addCleanup(self.close_child, tick)
        self.assertFalse(select.select([tick.stdout], [], [], 0.5)[0], "Tick ran while a controller held the lock")
        self.assertIsNone(tick.poll())
        controller.communicate(b"x", timeout=5)
        output, errors = tick.communicate(timeout=5)
        self.assertEqual(0, tick.returncode, errors)
        self.assertEqual("planned", json.loads(output)["phase"])
        self.assertEqual(original, self.journal.path.read_bytes())

    def kill_after_publishing(self, action, *, writes_possible):
        child = self.pause_at("pause-publication", action, "proof published")
        self.call("check", "sqlite", error="admission lock held")
        self.call("check", "mysql", error="admission lock held")
        pending_bytes = self.journal.path.read_bytes()
        pending = json.loads(pending_bytes)
        self.assertEqual(action, pending["pending"]["action"])
        self.assertIs(writes_possible, pending["writes_possible"])
        proof = json.loads((self.directory / "fixture-authority.json").read_bytes())
        self.assertNotEqual(guard.sha256(pending_bytes), proof["journal_sha256"])
        child.kill()
        child.communicate(timeout=5)
        self.assertEqual(-signal.SIGKILL, child.returncode)
        self.assertEqual(pending_bytes, self.journal.path.read_bytes())
        self.call("check", "sqlite", error="unfinished action")
        self.call("check", "mysql", error="unfinished action")

    def test_mysql_publication_crash_cannot_reopen_or_restore_sqlite(self):
        self.call("runner", "freeze")
        self.call("runner", "prepare")
        self.kill_after_publishing("promote", writes_possible=True)
        self.call("runner", "abort", error="writes may exist")
        self.assertEqual("recovery_required", self.journal.read()["phase"])
        self.assertTrue(ProcessAdapter(self.journal, IDENTITY).observe().sealed("mysql"))
        self.call("check", "sqlite", error="unfinished action")

    def test_sqlite_rollback_publication_crash_can_retry_only_sqlite_authority(self):
        self.call("runner", "freeze")
        self.call("runner", "prepare")
        self.kill_after_publishing("abort", writes_possible=False)
        self.call("runner", "abort")
        self.assertEqual("sqlite_authoritative", self.journal.read()["phase"])
        self.call("check", "mysql", error="not authoritative")
        self.assertEqual("admitted", self.call("check", "sqlite").stdout.strip())


if __name__ == "__main__":
    if len(sys.argv) == 5 and sys.argv[1] == "--fixture-child":
        sys.exit(child_main(*sys.argv[2:]))
    unittest.main()
