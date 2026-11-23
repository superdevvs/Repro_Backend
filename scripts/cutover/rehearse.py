"""Run the cutover journal against local fixture state, with zero host adapters."""

from __future__ import annotations

import argparse
import json
import sys
import uuid
from dataclasses import asdict, replace
from pathlib import Path

from fence import guard
from runner import Approval, Evidence, Identity, Journal, Lease, Observation, Runner, SchedulerDrain, UnsafeCutover, digest


class FixtureAdapter:
    MACHINE_SHA256 = "e" * 64
    BOOT_ID = "2fb9e4be-f041-45a0-9055-8d3ed47f9857"

    def __init__(self, journal: Journal, identity: Identity):
        self.journal = journal
        self._identity = identity

    def identity(self) -> Identity:
        return self._identity

    def observe(self) -> Observation:
        path = self.journal.directory / "fixture.json"
        if path.exists():
            return Observation(**json.loads(path.read_text(encoding="utf-8")))
        return Observation("sqlite", True, False, True, False, False, False)

    def require_quiescent(self) -> None:
        # Fixture effects are synchronous local file replacements.
        return None

    def preflight_sha256(self) -> str:
        return digest({"fixture": True, "identity": asdict(self._identity)})

    def boot_id(self) -> str:
        return self.BOOT_ID

    def hold_and_drain_scheduler(self, lease: Lease, deadline: int) -> SchedulerDrain:
        lease.require_current()
        if (self.journal.directory / "fixture-scheduler-drain.json").exists():
            return self.scheduler_drain()
        observed = SchedulerDrain(digest(asdict(self._identity)), self.boot_id(), str(uuid.uuid4()), True, 0)
        self.journal.write_record("fixture-scheduler-drain.json", asdict(observed))
        return observed

    def scheduler_drain(self) -> SchedulerDrain:
        path = self.journal.directory / "fixture-scheduler-drain.json"
        try:
            return SchedulerDrain(**json.loads(path.read_text(encoding="utf-8")))
        except (OSError, ValueError, TypeError) as error:
            raise UnsafeCutover("Scheduler hold observation is unavailable") from error

    def _write(self, lease: Lease, state: Observation) -> None:
        lease.require_current()
        self.journal.write_record("fixture.json", asdict(state))

    def freeze(self, lease: Lease, deadline: int) -> None:
        self._write(lease, Observation("sqlite", False, True, False, False, False, True))

    def prepare(self, lease: Lease, deadline: int) -> None:
        self._write(lease, replace(self.observe(), engine="mysql"))

    def evidence(self) -> Evidence:
        return Evidence(
            digest({"fixture": "snapshot", "run": self._identity.run_id}),
            digest({"fixture": "verification", "run": self._identity.run_id}),
            "fixture-binlog.000001", 4, True, True, True, True, True,
        )

    def seal_mysql(self, lease: Lease, deadline: int) -> None:
        self._write(lease, Observation("mysql", False, True, False, True, False, True))

    def seal_sqlite(self, lease: Lease, deadline: int) -> None:
        self._write(lease, Observation("sqlite", False, True, True, False, False, True))

    def policy(self) -> dict:
        return {
            "version": 1, "machine_id_sha256": self.MACHINE_SHA256, "identity": asdict(self._identity),
            "sqlite_live_config_sha256": "1" * 64, "mysql_live_config_sha256": "2" * 64,
        }

    def authority_evidence(self, engine: str, candidate: bytes) -> dict:
        policy = self.policy()
        return {
            "version": 1, "boot_id": self.BOOT_ID, "policy_sha256": guard.sha256(self.journal.encode(policy)),
            "journal_sha256": guard.sha256(candidate), "engine": engine,
            "database_identity": self._identity.source_sha256 if engine == "sqlite" else self._identity.target_uuid,
            "live_config_sha256": policy[f"{engine}_live_config_sha256"],
            "release_sha": self._identity.release_sha, "artifact_sha256": self._identity.artifact_sha256,
            "competing_writer_detected": False,
        }

    def publish_authority(self, lease: Lease, engine: str, candidate: bytes, deadline: int) -> None:
        lease.require_current()
        if not self.observe().sealed(engine):
            raise UnsafeCutover("Fixture admissions are not sealed")
        self.journal.write_record("fixture-authority.json", self.authority_evidence(engine, candidate))

    def reopen(self) -> None:
        # The cross-platform fixture takes a conflicting lock on the same
        # journal without changing its generation. The installed Linux guard
        # and host admission wrappers use the real shared lease.
        with self.journal.acquire():
            observed = self.observe()
            engine = observed.engine
            if not (observed.sealed(engine) or observed.sqlite_live() or observed.mysql_live()):
                raise UnsafeCutover("Fixture has no verified authority to reopen")
            policy = self.journal.encode(self.policy())
            state = self.journal.path.read_bytes()
            try:
                evidence = (self.journal.directory / "fixture-authority.json").read_bytes()
                guard.verify(
                    policy, state, evidence,
                    machine_sha=self.MACHINE_SHA256, boot_id=self.BOOT_ID,
                    action="queue-worker", expected_engine=engine,
                )
            except guard.Refused as error:
                raise UnsafeCutover(str(error)) from error
            if guard.read_json(state).get("phase") != f"{engine}_authoritative":
                raise UnsafeCutover("Fixture reopening requires a completed authority transition")
            # Invalidate a previous receipt before changing activation state.
            # The durable empty record also survives a crash during this attempt.
            self.journal.write_record("fixture-activation.json", {})
            self.journal.write_record("fixture.json", asdict(replace(
                observed, admissions_open=True, workers_stopped=False, deployment_fenced=True,
            )))
            opened = self.observe()
            if (opened.engine != engine or not (opened.sqlite_live() or opened.mysql_live())
                    or opened.workers_stopped or not opened.deployment_fenced):
                raise UnsafeCutover("Fixture activation could not be verified")
            self.journal.write_record("fixture-activation.json", {
                "version": 1, "boot_id": self.BOOT_ID, "policy_sha256": guard.sha256(policy),
                "journal_sha256": guard.sha256(state), "authority_sha256": guard.sha256(evidence),
                "engine": engine, "application_verified": True, "reconciliation_verified": True,
            })
            self.journal.write_record("fixture.json", asdict(replace(opened, deployment_fenced=False)))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--state-dir", required=True, type=Path)
    parser.add_argument(
        "action", choices=["init", "status", "approval-template", "freeze", "prepare", "promote", "tick", "abort", "reopen"]
    )
    parser.add_argument("--phase", choices=["freeze", "promote"])
    parser.add_argument("--approval", type=Path)
    args = parser.parse_args()
    name = args.state_dir.name
    if not name.startswith("repro-cutover-fixture-"):
        parser.error("Use a dedicated directory named repro-cutover-fixture-*")
    if args.action == "approval-template" and args.phase is None:
        parser.error("Specify --phase freeze or --phase promote")

    try:
        identity = Identity(name, "a" * 40, "b" * 64, "c" * 64, "d" * 64,
                            str(uuid.uuid5(uuid.NAMESPACE_URL, "repro-cutover-fixture:" + name)))
        journal = Journal(args.state_dir)
        adapter = FixtureAdapter(journal, identity)
        runner = Runner(journal, identity, adapter)
        if args.action == "init":
            result = runner.initialize()
        elif args.action == "status":
            result = runner.status()
        elif args.action == "approval-template":
            print(json.dumps(asdict(runner.approval_request(args.phase)), indent=2))
            return 0
        elif args.action == "reopen":
            adapter.reopen()
            result = runner.status()
        else:
            approval = Approval(**json.loads(args.approval.read_text(encoding="utf-8-sig"))) if args.approval else None
            result = runner.perform(args.action, approval)
    except (UnsafeCutover, ValueError, OSError, TypeError) as error:
        print(f"Cutover rehearsal refused: {error}", file=sys.stderr)
        return 1
    print(json.dumps({"mode": "fixture-only", "state": result, "observation": asdict(adapter.observe())}, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
