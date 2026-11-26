"""Verify an admission while the synthetic PHP caller holds its journal lease."""

from pathlib import Path
import os
import sys

sys.path.insert(0, "/workspace")

from scripts.cutover.fence import guard


def main() -> int:
    if os.geteuid() != 1000 or Path(__file__) != Path("/host/verify-writer.py"):
        return 1
    if len(sys.argv) != 3 or len(Path("/proc/net/route").read_text().splitlines()) > 1:
        return 1
    directory = Path("/private/authority")
    try:
        proof = (directory / "proof.json").read_bytes()
        if guard.read_json(proof).get("live_config_sha256") != guard.sha256(Path("/private/active-config.php").read_bytes()):
            return 1
        guard.verify(
            (directory / "policy.json").read_bytes(),
            (directory / "state.json").read_bytes(),
            proof,
            machine_sha=guard.sha256(Path("/etc/machine-id").read_bytes()),
            boot_id=Path("/proc/sys/kernel/random/boot_id").read_text().strip(),
            action=sys.argv[1], expected_engine=sys.argv[2],
        )
    except (guard.Refused, OSError, ValueError, RecursionError):
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
