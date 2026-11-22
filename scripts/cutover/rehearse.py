"""Run the cutover journal against local fixture state, with zero host adapters."""

from __future__ import annotations

import argparse
import json
import sys
import uuid
from dataclasses import asdict, replace
from pathlib import Path

from runner import Approval, Evidence, Identity, Journal, Lease, Observation, Runner, UnsafeCutover, digest


class FixtureAdapter:
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

    def enable_mysql(self, lease: Lease, deadline: int) -> None:
        self._write(lease, Observation("mysql", True, False, False, True, True, True))

    def restore_sqlite(self, lease: Lease, deadline: int) -> None:
        self._write(lease, Observation("sqlite", True, False, True, False, False, False))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--state-dir", required=True, type=Path)
    parser.add_argument(
        "action", choices=["init", "status", "approval-template", "freeze", "prepare", "promote", "tick", "abort"]
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
        runner = Runner(journal, identity, FixtureAdapter(journal, identity))
        if args.action == "init":
            result = runner.initialize()
        elif args.action == "status":
            result = runner.status()
        elif args.action == "approval-template":
            print(json.dumps(asdict(runner.approval_request(args.phase)), indent=2))
            return 0
        else:
            approval = Approval(**json.loads(args.approval.read_text(encoding="utf-8-sig"))) if args.approval else None
            result = runner.perform(args.action, approval)
    except (UnsafeCutover, ValueError, OSError, TypeError) as error:
        print(f"Cutover rehearsal refused: {error}", file=sys.stderr)
        return 1
    print(json.dumps({"mode": "fixture-only", "state": result}, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
