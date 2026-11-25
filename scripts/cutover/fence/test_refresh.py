import os
import stat
import sys
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from fence import guard
from rehearse import FixtureAdapter
from runner import Identity, Journal, Runner


@unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0 and sys.flags.isolated
                     and sys.flags.no_site and os.environ.get("REPRO_FENCE_TEST_ROOT"),
                     "Requires the root-installed guard in a disposable private Linux filesystem")
class AuthorityRefreshTests(unittest.TestCase):
    def setUp(self):
        store = tempfile.TemporaryDirectory(dir=os.environ["REPRO_FENCE_TEST_ROOT"])
        self.addCleanup(store.cleanup)
        root = Path(store.name)
        self.identity = Identity("refresh-fixture", "a" * 40, "b" * 64, "c" * 64, "d" * 64,
                                 "f1d53bff-40ce-46ba-a35b-4591b2be65b7")
        self.journal = Journal(root / "state")
        self.adapter = FixtureAdapter(self.journal, self.identity)
        self.runner = Runner(self.journal, self.identity, self.adapter, lambda: 4100)
        self.runner.initialize()
        with self.journal.acquire():
            pass  # Use the actual lock inode created by the runner.
        self.boot = root / "boot-id"
        self.boot.write_text("2fb9e4be-f041-45a0-9055-8d3ed47f9857\n", encoding="ascii")
        self.machine = root / "machine-id"
        self.machine.write_bytes(b"isolated machine identity\n")
        self.policy = root / "policy.json"
        policy = self.adapter.policy() | {"machine_id_sha256": guard.sha256(self.machine.read_bytes())}
        self.policy.write_bytes(self.journal.encode(policy))
        self.evidence_dir = root / "run"
        self.evidence_dir.mkdir(mode=0o700)
        self.evidence = self.evidence_dir / "verified-engine.json"
        self.activation = self.evidence_dir / "verified-activation.json"
        paths = patch.multiple(guard, STATE=self.journal.directory, POLICY=self.policy, EVIDENCE=self.evidence,
                               ACTIVATION=self.activation, MACHINE_ID=self.machine, BOOT_ID=self.boot)
        paths.start()
        self.addCleanup(paths.stop)

    def observe(self, engine):
        return guard.HostAuthority(
            engine=engine, database_identity=self.identity.source_sha256 if engine == "sqlite" else self.identity.target_uuid,
            live_config_sha256=("1" if engine == "sqlite" else "2") * 64, release_sha=self.identity.release_sha,
            artifact_sha256=self.identity.artifact_sha256, competing_writer_detected=False, admissions_open=False,
            workers_stopped=True, deployment_fenced=True, sqlite_writable=engine == "sqlite", mysql_writable=engine == "mysql",
        )

    def test_refresh_writes_private_evidence_and_holds_deploys_until_reverified(self):
        self.runner.perform("freeze", self.runner.approval_request("freeze"))
        self.runner.perform("prepare")
        self.runner.perform("promote", self.runner.approval_request("promote"))
        journal = self.journal.path.read_bytes()
        self.activation.write_bytes(b"{}")

        guard.refresh_engine_evidence(self.observe)

        guard.check("web-writer", "mysql")
        self.assertEqual(journal, self.journal.path.read_bytes())
        self.assertEqual(guard.sha256(journal), guard.read_json(self.evidence.read_bytes())["journal_sha256"])
        self.assertEqual(0o600, stat.S_IMODE(self.evidence.stat().st_mode))
        self.assertFalse(self.activation.exists())
        with self.assertRaisesRegex(guard.Refused, "activation"):
            guard.check("deploy", "mysql")
        with self.assertRaisesRegex(guard.Refused, "not authoritative"):
            guard.check("web-writer", "sqlite")

    def test_contended_lock_leaves_existing_evidence_untouched(self):
        guard.refresh_engine_evidence(self.observe)
        before = self.evidence.read_bytes()
        with guard.admitted("queue-worker", "sqlite"), self.assertRaises(BlockingIOError):
            guard.refresh_engine_evidence(self.observe)
        self.assertEqual(before, self.evidence.read_bytes())

    def test_frozen_journal_revokes_old_evidence_without_probing(self):
        guard.refresh_engine_evidence(self.observe)
        self.runner.perform("freeze", self.runner.approval_request("freeze"))
        with self.assertRaisesRegex(guard.Refused, "holds all admissions"):
            guard.refresh_engine_evidence(lambda _engine: self.fail("frozen journal reached host probe"))
        self.assertFalse(self.evidence.exists())

    def test_wrong_or_unsealed_observations_cannot_leave_stale_evidence(self):
        for change in ({"workers_stopped": False}, {"mysql_writable": True}, {"competing_writer_detected": True},
                       {"engine": "mysql"}, {"live_config_sha256": "f" * 64}):
            with self.subTest(change=change):
                guard.refresh_engine_evidence(self.observe)
                self.activation.write_bytes(b"{}")
                with self.assertRaises(guard.Refused):
                    guard.refresh_engine_evidence(lambda engine: replace(self.observe(engine), **change))
                self.assertEqual([], list(self.evidence_dir.iterdir()))

    def test_boot_change_during_probe_leaves_authority_held(self):
        def observe(engine):
            self.boot.write_text("bb339e3d-8584-4d51-982a-ac67bb9afa85\n", encoding="ascii")
            return self.observe(engine)

        guard.refresh_engine_evidence(self.observe)
        with self.assertRaisesRegex(guard.Refused, "changed during the host probe"):
            guard.refresh_engine_evidence(observe)
        self.assertFalse(self.evidence.exists())

    def test_replaced_operator_lock_leaves_authority_held(self):
        def observe(engine):
            lock = self.journal.directory / "operator.lock"
            lock.rename(lock.with_name("retired.lock"))
            lock.touch(mode=0o600)
            return self.observe(engine)

        guard.refresh_engine_evidence(self.observe)
        with self.assertRaisesRegex(guard.Refused, "paths changed during the host probe"):
            guard.refresh_engine_evidence(observe)
        self.assertFalse(self.evidence.exists())

    def test_proof_symlink_does_not_replace_target(self):
        external = self.evidence_dir.parent / "sentinel"
        external.write_bytes(b"preserve target")
        self.evidence.symlink_to(external)
        with self.assertRaisesRegex(guard.Refused, "Unsafe file type"):
            guard.refresh_engine_evidence(self.observe)
        self.assertTrue(self.evidence.is_symlink())
        self.assertEqual(b"preserve target", external.read_bytes())

    def test_failed_directory_sync_after_rename_revokes_candidate(self):
        sync = os.fsync
        injected = False

        def fail_after_replace(descriptor):
            nonlocal injected
            if not injected and stat.S_ISDIR(os.fstat(descriptor).st_mode) and self.evidence.exists():
                injected = True
                raise OSError("fixture directory sync failure")
            sync(descriptor)

        with patch.object(guard.os, "fsync", side_effect=fail_after_replace):
            with self.assertRaisesRegex(OSError, "fixture directory sync failure"):
                guard.refresh_engine_evidence(self.observe)
        self.assertTrue(injected)
        self.assertEqual([], list(self.evidence_dir.iterdir()))
        with self.assertRaises(FileNotFoundError):
            guard.check("queue-worker", "sqlite")
