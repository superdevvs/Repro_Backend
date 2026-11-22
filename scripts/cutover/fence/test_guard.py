import json
import os
import stat
import subprocess
import sys
import tempfile
import unittest
from contextlib import contextmanager
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from fence import guard
from rehearse import FixtureAdapter
from runner import Identity, Journal, Runner, UnsafeCutover


class HostWriterGuardTests(unittest.TestCase):
    def setUp(self):
        self.root = tempfile.TemporaryDirectory()
        self.addCleanup(self.root.cleanup)
        self.identity = Identity("guard-fixture", "a" * 40, "b" * 64, "c" * 64, "d" * 64,
                                 "f1d53bff-40ce-46ba-a35b-4591b2be65b7")
        self.journal = Journal(Path(self.root.name) / "state")
        self.adapter = FixtureAdapter(self.journal, self.identity)
        self.now = 4100
        self.runner = Runner(self.journal, self.identity, self.adapter, lambda: self.now)
        self.state = self.runner.initialize()
        self.machine = "e" * 64
        self.boot = "2fb9e4be-f041-45a0-9055-8d3ed47f9857"
        self.policy = {
            "version": 1, "machine_id_sha256": self.machine, "identity": self.state["identity"],
            "sqlite_live_config_sha256": "1" * 64, "mysql_live_config_sha256": "2" * 64,
        }

    def encode(self, value):
        return json.dumps(value, sort_keys=True).encode()

    def evidence(self, state, engine):
        return {
            "version": 1, "boot_id": self.boot, "policy_sha256": guard.sha256(self.encode(self.policy)),
            "journal_sha256": guard.sha256(self.encode(state)), "engine": engine,
            "database_identity": self.identity.source_sha256 if engine == "sqlite" else self.identity.target_uuid,
            "live_config_sha256": self.policy[f"{engine}_live_config_sha256"],
            "release_sha": self.identity.release_sha, "artifact_sha256": self.identity.artifact_sha256,
            "competing_writer_detected": False,
        }

    def verify(self, state, engine, action="scheduler", evidence=None, **host):
        if evidence is None:
            evidence = self.evidence(state, engine)
        guard.verify(self.encode(self.policy), self.encode(state), self.encode(evidence),
                     **({"machine_sha": self.machine, "boot_id": self.boot} | host),
                     action=action, expected_engine=engine)

    def freeze(self):
        return self.runner.perform("freeze", self.runner.approval_request("freeze"))

    @contextmanager
    def fixture_guard(self, evidence_overrides=None):
        root = Path(self.root.name)
        machine_id = root / "machine-id"
        boot_id = root / "boot-id"
        policy = root / "policy.json"
        evidence = root / "verified-engine.json"
        machine_id.write_bytes(b"fixture machine")
        boot_id.write_text(self.boot, encoding="ascii")
        policy.write_bytes(self.encode(self.policy | {"machine_id_sha256": guard.sha256(machine_id.read_bytes())}))
        proof = self.evidence(self.state, "sqlite") | {
            "journal_sha256": guard.sha256(self.journal.path.read_bytes()),
            "policy_sha256": guard.sha256(policy.read_bytes()),
        } | (evidence_overrides or {})
        evidence.write_bytes(self.encode(proof))
        runtime = SimpleNamespace(
            platform="linux", executable="/usr/bin/python3", flags=SimpleNamespace(isolated=1, no_site=1),
        )
        # Replace installation-specific permissions and paths. The real kernel
        # lock, evidence files and guard verification are used unchanged.
        with (
            patch.multiple(
                guard, STATE=self.journal.directory, POLICY=policy, EVIDENCE=evidence,
                MACHINE_ID=machine_id, BOOT_ID=boot_id, ENTRYPOINT=Path(guard.__file__).absolute(), sys=runtime,
            ),
            patch.object(guard.os, "geteuid", return_value=0),
            patch.object(guard, "check_chain"),
            patch.object(guard, "trusted"),
        ):
            yield

    @unittest.skipUnless(sys.platform == "linux", "Exercises the Linux flock shared lease")
    def test_verified_admission_blocks_transition_until_the_scope_ends(self):
        with self.fixture_guard():
            with guard.admitted("scheduler", "sqlite") as lease_fd:
                self.assertFalse(os.get_inheritable(lease_fd))
                with self.assertRaisesRegex(UnsafeCutover, "holds the cutover lock"):
                    with self.journal.acquire():
                        self.fail("Transition acquired during the bounded admission")

            with self.journal.acquire():
                pass
            guard.check("scheduler", "sqlite")
            with self.journal.acquire():
                pass

    @unittest.skipUnless(sys.platform == "linux", "Exercises the Linux flock shared lease")
    def test_invalid_evidence_never_enters_operation_and_releases_the_lease(self):
        with self.fixture_guard({"boot_id": self.identity.target_uuid}):
            with self.assertRaisesRegex(guard.Refused, "this boot"):
                with guard.admitted("scheduler", "sqlite"):
                    self.fail("Operation entered without valid evidence")
            with self.journal.acquire():
                pass

    @unittest.skipUnless(sys.platform == "linux", "Exercises inherited Linux flock descriptors")
    def test_bounded_child_retains_admission_after_wrapper_scope_closes(self):
        child_code = "import os, sys; os.fstat(int(sys.argv[1])); sys.stdin.buffer.read(1)"
        child = None
        try:
            with self.fixture_guard(), guard.admitted("scheduler", "sqlite") as lease_fd:
                child = subprocess.Popen(
                    [sys.executable, "-I", "-S", "-c", child_code, str(lease_fd)],
                    stdin=subprocess.PIPE, pass_fds=(lease_fd,),
                )
            self.assertIsNone(child.poll())
            with self.assertRaisesRegex(UnsafeCutover, "holds the cutover lock"):
                with self.journal.acquire():
                    self.fail("Transition acquired before the admitted child exited")
            child.communicate(b"x", timeout=5)
            self.assertEqual(0, child.returncode)
            with self.journal.acquire():
                pass
        finally:
            if child is not None and child.poll() is None:
                child.kill()
                child.communicate(timeout=5)

    def test_planned_keeps_one_sqlite_authority_and_holds_deploys(self):
        for kind in ("scheduler", "web-writer", "queue-worker"):
            self.verify(self.state, "sqlite", kind)
        with self.assertRaisesRegex(guard.Refused, "Deploys are held"):
            self.verify(self.state, "sqlite", "deploy")
        with self.assertRaisesRegex(guard.Refused, "not authoritative"):
            self.verify(self.state, "mysql", "scheduler")

    def check_sealed_transition(self, action, engine, approval=None):
        publish = self.adapter.publish_authority

        def verify_before_commit(lease, observed_engine, candidate, deadline):
            self.assertEqual(engine, observed_engine)
            self.assertTrue(self.adapter.observe().sealed(engine))
            self.assertEqual(action, self.journal.read()["pending"]["action"])
            publish(lease, observed_engine, candidate, deadline)
            with self.assertRaisesRegex(UnsafeCutover, "holds the cutover lock"):
                self.adapter.reopen()

        with patch.object(self.adapter, "publish_authority", verify_before_commit):
            state = self.runner.perform(action, approval)
        self.assertEqual(f"{engine}_authoritative", state["phase"])
        self.assertTrue(self.adapter.observe().sealed(engine))
        committed = self.journal.path.read_bytes()
        evidence = (self.journal.directory / "fixture-authority.json").read_bytes()
        proof_inputs = self.journal.encode(self.adapter.policy()), committed, evidence
        host = {"machine_sha": self.adapter.MACHINE_SHA256, "boot_id": self.adapter.BOOT_ID}
        wrong_engine = "mysql" if engine == "sqlite" else "sqlite"
        guard.verify(*proof_inputs, **host, action="queue-worker", expected_engine=engine)
        with self.assertRaisesRegex(guard.Refused, "not authoritative"):
            guard.verify(*proof_inputs, **host, action="queue-worker", expected_engine=wrong_engine)
        self.now += 5
        self.assertEqual(state, self.runner.perform("tick"))
        self.assertEqual(committed, self.journal.path.read_bytes())
        self.adapter.reopen()
        self.assertTrue(self.adapter.observe().admissions_open)
        self.assertEqual(engine, self.adapter.observe().engine)
        self.now += 5
        self.assertEqual(state, self.runner.perform("tick"))
        self.assertEqual(committed, self.journal.path.read_bytes())

    def test_sqlite_abort_seals_then_authorizes_only_sqlite_after_unlock(self):
        self.freeze()
        self.check_sealed_transition("abort", "sqlite")

    def test_mysql_promotion_seals_then_authorizes_only_mysql_after_unlock(self):
        self.freeze()
        self.runner.perform("prepare")
        self.check_sealed_transition("promote", "mysql", self.runner.approval_request("promote"))

    def test_planned_watchdog_tick_keeps_original_authority_proof_valid(self):
        original = self.journal.path.read_bytes()
        proof = self.adapter.authority_evidence("sqlite", original)
        self.now += 9
        self.assertEqual(self.state, self.runner.perform("tick"))
        self.assertEqual(original, self.journal.path.read_bytes())
        guard.verify(self.journal.encode(self.adapter.policy()), original, self.journal.encode(proof),
                     machine_sha=self.adapter.MACHINE_SHA256, boot_id=self.adapter.BOOT_ID,
                     action="scheduler", expected_engine="sqlite")

    def test_reboot_does_not_authorize_frozen_or_uncertain_phase(self):
        frozen = self.freeze()
        for phase in ("frozen", "ready", "writes_possible", "recovery_required", "unrecognized"):
            modified = frozen | {"phase": phase, "writes_possible": phase in {"writes_possible", "recovery_required"}}
            for kind in guard.ACTIONS:
                with self.subTest(phase=phase, kind=kind), self.assertRaisesRegex(guard.Refused, "holds all"):
                    self.verify(modified, "sqlite", kind)

    def test_pending_action_and_missing_journal_fields_fail_closed(self):
        for invalid in (
            self.state | {"pending": {"action": "freeze"}},
            {key: value for key, value in self.state.items() if key != "pending"},
            self.state | {"started_at": 4100},
            self.state | {"generation": 0},
            self.state | {"identity": self.state["identity"] | {"target_uuid": self.boot}},
        ):
            with self.subTest(state=invalid), self.assertRaises(guard.Refused):
                self.verify(invalid, "sqlite")

    def test_prior_boot_wrong_machine_and_state_replacement_invalidate_evidence(self):
        evidence = self.evidence(self.state, "sqlite")
        with self.assertRaisesRegex(guard.Refused, "different host"):
            self.verify(self.state, "sqlite", evidence=evidence, machine_sha="f" * 64)
        with self.assertRaisesRegex(guard.Refused, "this boot"):
            self.verify(self.state, "sqlite", evidence=evidence, boot_id=self.identity.target_uuid)
        changed = self.state | {"generation": self.state["generation"] + 1}
        with self.assertRaisesRegex(guard.Refused, "policy or journal"):
            self.verify(changed, "sqlite", evidence=evidence)

    def test_evidence_binds_config_release_database_and_single_writer(self):
        evidence = self.evidence(self.state, "sqlite")
        for field, value in (
            ("live_config_sha256", "3" * 64), ("release_sha", "4" * 40),
            ("database_identity", "5" * 64), ("competing_writer_detected", None),
            ("artifact_sha256", "6" * 64),
        ):
            with self.subTest(field=field), self.assertRaisesRegex(guard.Refused, "writer checks"):
                self.verify(self.state, "sqlite", evidence=evidence | {field: value})

    def test_unconfirmed_terminal_authority_is_denied(self):
        self.freeze()
        aborted = self.runner.perform("abort")
        abort_action = aborted["history"][-1]
        for lost_seal in (
            {"mysql_writable": True}, {"admissions_open": True}, {"workers_stopped": False},
            {"deployment_fenced": False}, {"mysql_business_activity": True},
        ):
            changed = abort_action | {"after": abort_action["after"] | lost_seal}
            with self.subTest(lost_seal=lost_seal), self.assertRaisesRegex(guard.Refused, "single writable authority"):
                self.verify(aborted | {"history": [changed]}, "sqlite")
        with self.assertRaisesRegex(guard.Refused, "expected write marker"):
            self.verify(aborted | {"writes_possible": True}, "sqlite")

    def test_rejects_malformed_duplicate_and_nonstandard_json(self):
        for content in (b"", b"{", b'{"version":true}', b'{"version":1,"version":1}', b'{"version":1,"value":NaN}'):
            with self.subTest(content=content), self.assertRaises((ValueError, guard.Refused)):
                guard.read_json(content)

    def test_privilege_chain_requires_root_and_no_untrusted_writes_or_symlinks(self):
        for bad in (
            SimpleNamespace(st_uid=1000, st_mode=stat.S_IFREG | 0o600),
            SimpleNamespace(st_uid=0, st_mode=stat.S_IFREG | 0o660),
            SimpleNamespace(st_uid=0, st_mode=stat.S_IFDIR | 0o777),
            SimpleNamespace(st_uid=0, st_mode=stat.S_IFLNK | 0o444),
        ):
            with self.subTest(metadata=bad), self.assertRaises(guard.Refused):
                guard.trusted(bad)
        guard.trusted(SimpleNamespace(st_uid=0, st_mode=stat.S_IFREG | 0o600))
        guard.trusted(SimpleNamespace(st_uid=0, st_mode=stat.S_IFDIR | 0o700), directory=True)


if __name__ == "__main__":
    unittest.main()
