"""Linux subprocess rehearsal of journal fencing and interrupted authority publication."""

import fcntl
import json
import os
from pathlib import Path
import select
import signal
import subprocess
import sys
import tempfile
import time
from contextlib import contextmanager
from types import SimpleNamespace
import unittest
from unittest.mock import patch

from fence import guard
from rehearse import FixtureAdapter
from runner import Identity, Journal, Runner, UnsafeCutover


FIXTURE_PREFIX = "repro-cutover-process-"
DRAIN_SECONDS = 10
# Generous: other work on a shared host can slow child start-up a lot.
CHILD_SECONDS = 20
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


@contextmanager
def fixture_guard(directory):
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
    ):
        yield


def hammer(directory, seconds):
    """Refused admission checks in a tight loop, as a crowd of restarting writers would."""
    attempts = refused = 0
    limit = time.monotonic() + seconds
    with fixture_guard(directory):
        while time.monotonic() < limit:
            attempts += 1
            try:
                with guard.admitted("scheduler", "sqlite"):
                    pass
            except (guard.Refused, BlockingIOError):
                refused += 1
    print(json.dumps({"attempts": attempts, "refused": refused}), flush=True)
    return 0


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
    # A late runner sees the watchdog clock just past the decision deadline.
    clock = (lambda: time.time() + Runner.DECISION_SECONDS) if role == "late-runner" else time.time
    runner = Runner(journal, IDENTITY, adapter, clock, max_drain_seconds=DRAIN_SECONDS, controller_wait_seconds=4)
    try:
        if role == "control":
            with journal.acquire():
                print("controlling", flush=True)
                sys.stdin.buffer.read(1)
            return 0
        if role == "hammer":
            return hammer(directory, float(operation))
        if role in {"check", "writer"}:
            with fixture_guard(directory), guard.admitted("scheduler", operation):
                print("admitted", flush=True)
                if role == "writer":
                    sys.stdin.buffer.read(1)
            return 0
        if role in {"runner", "late-runner"} and operation == "tick":
            print(json.dumps(runner.perform("tick")), flush=True)
            return 0
        if role in {"runner", "pause-publication"} and operation in {"hold", "freeze", "prepare", "promote", "abort"}:
            adapter.pause_publication = role == "pause-publication"
            approval = runner.approval_request(operation) if operation in {"hold", "promote"} else None
            print(json.dumps(runner.perform(operation, approval)), flush=True)
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
            self.command(role, operation), stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=CHILD_SECONDS,
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
        child.wait(timeout=CHILD_SECONDS)

    def pause_at(self, role, operation, message):
        child = subprocess.Popen(
            self.command(role, operation), stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        )
        self.addCleanup(self.close_child, child)
        self.assertTrue(select.select([child.stdout], [], [], CHILD_SECONDS)[0], f"Child did not reach {message}")
        self.assertEqual(message.encode() + b"\n", child.stdout.readline())
        self.assertIsNone(child.poll())
        return child

    def background(self, role, operation):
        child = subprocess.Popen(self.command(role, operation), stdin=subprocess.DEVNULL,
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.addCleanup(self.close_child, child)
        return child

    def wait_for_pending(self, action, seconds=10):
        limit = time.monotonic() + seconds
        while (self.journal.read()["pending"] or {}).get("action") != action:
            self.assertLess(time.monotonic(), limit, f"The runner never journaled its {action}")
            time.sleep(0.02)

    def test_already_admitted_wrapper_drains_after_hold_and_new_admissions_refused(self):
        wrapper = self.pause_at("writer", "sqlite", "admitted")
        # The hold is journaled while the old writer still holds its shared lease.
        self.call("runner", "hold")
        held = self.journal.read()
        self.assertEqual(("holding", None), (held["phase"], held["pending"]))
        self.call("check", "sqlite", error="holds all admissions")

        freeze = self.background("runner", "freeze")
        self.wait_for_pending("freeze")
        time.sleep(0.3)
        self.assertIsNone(freeze.poll(), "The drain finished while an admitted writer still ran")
        self.assertIsNone(wrapper.poll())
        _, errors = wrapper.communicate(b"x", timeout=CHILD_SECONDS)
        self.assertEqual(0, wrapper.returncode, errors)
        _, errors = freeze.communicate(timeout=CHILD_SECONDS)
        self.assertEqual(0, freeze.returncode, errors)
        frozen = self.journal.read()
        self.assertEqual("frozen", frozen["phase"])
        # The drain waited for the wrapper; the child's own start-up used part of the pause.
        self.assertGreater(frozen["drain"]["writers_drained_ms"], 0)
        self.call("check", "sqlite", error="holds all admissions")

    def test_runner_killed_mid_drain_is_recovered_by_the_watchdog(self):
        wrapper = self.pause_at("writer", "sqlite", "admitted")
        self.call("runner", "hold")
        freeze = self.background("runner", "freeze")
        self.wait_for_pending("freeze")
        time.sleep(0.2)
        freeze.kill()
        freeze.communicate(timeout=CHILD_SECONDS)
        self.assertEqual(-signal.SIGKILL, freeze.returncode)

        # Before the deadline the watchdog keeps the hold; new writers stay out.
        ticked = json.loads(self.call("runner", "tick").stdout)
        self.assertEqual(("holding", "freeze"), (ticked["phase"], ticked["pending"]["action"]))
        self.call("check", "sqlite", error="unfinished action")
        wrapper.communicate(b"x", timeout=CHILD_SECONDS)

        # At the decision deadline, counted from the hold, it rolls back to SQLite only.
        recovered = json.loads(self.call("late-runner", "tick").stdout)
        self.assertEqual("sqlite_authoritative", recovered["phase"])
        self.assertEqual(["holding", "interrupted", "sqlite_authoritative"],
                         [entry["phase"] for entry in recovered["history"]])
        self.call("check", "mysql", error="not authoritative")
        self.assertEqual("admitted", self.call("check", "sqlite").stdout.strip())

    def test_held_admission_is_refused_without_waiting_for_the_writer_lock(self):
        """A refusal is read before the shared lock, so refused checks never contend with a drain."""
        self.call("runner", "hold")
        with self.journal.acquire() as (_, lease):
            self.journal.drain_writers(lease, DRAIN_SECONDS)
            # The drain now holds the writer lock exclusively. The check must
            # still be refused by the hold itself, not by a busy lock.
            self.call("check", "sqlite", error="holds all admissions")

    def test_drain_under_refused_admission_load(self):
        """Measure writer starvation: flock has no writer preference while refused checks share the lock."""
        self.call("runner", "hold")
        results = {}
        for crowd in (1, 4, 8):
            hammers = [self.background("hammer", "3") for _ in range(crowd)]
            time.sleep(0.3)
            started = time.monotonic()
            with self.journal.acquire() as (_, lease):
                waited = self.journal.drain_writers(lease, DRAIN_SECONDS)
            elapsed = time.monotonic() - started
            counts = [json.loads(hammer.communicate(timeout=CHILD_SECONDS)[0]) for hammer in hammers]
            self.assertTrue(all(count["attempts"] == count["refused"] > 0 for count in counts))
            results[crowd] = {"drain_ms": round(waited * 1000), "elapsed_ms": round(elapsed * 1000),
                              "refused_checks": sum(count["refused"] for count in counts)}
        print("drain-under-load " + json.dumps(results, sort_keys=True), file=sys.stderr, flush=True)
        for crowd, result in results.items():
            self.assertLess(result["drain_ms"], 1000, f"Drain starved behind {crowd} refused checkers: {results}")

    def test_watchdog_tick_waits_for_controller_lock(self):
        original = self.journal.path.read_bytes()
        controller = self.pause_at("control", "none", "controlling")
        tick = subprocess.Popen(self.command("runner", "tick"), stdin=subprocess.DEVNULL,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.addCleanup(self.close_child, tick)
        self.assertFalse(select.select([tick.stdout], [], [], 0.5)[0], "Tick ran while a controller held the lock")
        self.assertIsNone(tick.poll())
        controller.communicate(b"x", timeout=CHILD_SECONDS)
        output, errors = tick.communicate(timeout=CHILD_SECONDS)
        self.assertEqual(0, tick.returncode, errors)
        self.assertEqual("planned", json.loads(output)["phase"])
        self.assertEqual(original, self.journal.path.read_bytes())

    def kill_after_publishing(self, action, *, writes_possible):
        child = self.pause_at("pause-publication", action, "proof published")
        # The unfinished action refuses both engines before the lock is tried,
        # and the publishing controller still holds the writer lock exclusively.
        self.call("check", "sqlite", error="unfinished action")
        self.call("check", "mysql", error="unfinished action")
        with (self.directory / guard.WRITER_LOCK).open("a+b") as lock, self.assertRaises(BlockingIOError):
            fcntl.flock(lock, fcntl.LOCK_SH | fcntl.LOCK_NB)
        pending_bytes = self.journal.path.read_bytes()
        pending = json.loads(pending_bytes)
        self.assertEqual(action, pending["pending"]["action"])
        self.assertIs(writes_possible, pending["writes_possible"])
        proof = json.loads((self.directory / "fixture-authority.json").read_bytes())
        self.assertNotEqual(guard.sha256(pending_bytes), proof["journal_sha256"])
        child.kill()
        child.communicate(timeout=CHILD_SECONDS)
        self.assertEqual(-signal.SIGKILL, child.returncode)
        self.assertEqual(pending_bytes, self.journal.path.read_bytes())
        self.call("check", "sqlite", error="unfinished action")
        self.call("check", "mysql", error="unfinished action")

    def test_mysql_publication_crash_cannot_reopen_or_restore_sqlite(self):
        self.call("runner", "hold")
        self.call("runner", "freeze")
        self.call("runner", "prepare")
        self.kill_after_publishing("promote", writes_possible=True)
        self.call("runner", "abort", error="writes may exist")
        self.assertEqual("recovery_required", self.journal.read()["phase"])
        self.assertTrue(ProcessAdapter(self.journal, IDENTITY).observe().sealed("mysql"))
        self.call("check", "sqlite", error="unfinished action")

    def test_sqlite_rollback_publication_crash_can_retry_only_sqlite_authority(self):
        self.call("runner", "hold")
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
