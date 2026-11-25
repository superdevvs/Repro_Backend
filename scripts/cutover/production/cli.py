"""Production cutover controller and watchdog entry point (Linux, root).

    /usr/bin/python3 -I -S /opt/repro-cutover-fence/production/cli.py <action>

Actions: status, init, approval-template --phase hold|freeze, hold --approval FILE,
freeze [--approval FILE], tick, abort, refresh, reopen. 'refresh' publishes
authority evidence for the current boot (needed after every reboot) and
'reopen' then opens SQLite writers in order. The systemd watchdog timer runs
'tick' every minute from boot: it closes admissions again after a crash or a
reboot and rolls back to SQLite 45 minutes after the hold.

Approval files must be root-owned and writable by root only. The host config
at /etc/repro-cutover-fence/host.json must be protected the same way.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from dataclasses import asdict
from pathlib import Path

sys.path[:0] = [str(Path(__file__).resolve().parent), str(Path(__file__).resolve().parent.parent)]
import adapter as host  # noqa: E402
from adapter import guard  # noqa: E402
from runner import Approval, Journal, UnsafeCutover  # noqa: E402

CONFIG = Path("/etc/repro-cutover-fence/host.json")
ACTIONS = ("status", "init", "approval-template", "hold", "freeze", "tick", "abort", "refresh", "reopen")
# The host config must name exactly the paths the installed guard reads.
GUARD_PATHS = ("state", "policy", "evidence", "activation", "machine_id", "boot_id")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--config", type=Path, default=CONFIG)
    parser.add_argument("action", choices=ACTIONS)
    parser.add_argument("--phase", choices=("hold", "freeze"))
    parser.add_argument("--approval", type=Path)
    args = parser.parse_args(argv)
    if args.action == "approval-template" and args.phase is None:
        parser.error("Specify --phase hold or --phase freeze")
    try:
        if sys.platform != "linux" or os.geteuid() != 0:
            raise UnsafeCutover("The cutover controller runs as root on the Linux host")
        config = host.load_config(guard.read_protected(args.config))
        installed = (guard.STATE, guard.POLICY, guard.EVIDENCE, guard.ACTIVATION, guard.MACHINE_ID, guard.BOOT_ID)
        if any(Path(config["paths"][name]) != path for name, path in zip(GUARD_PATHS, installed)):
            raise UnsafeCutover("Host config paths differ from the installed guard paths")
        journal = Journal(Path(config["paths"]["state"]))
        adapter = host.ProductionAdapter(config, journal)
        runner = host.runner_for(config, journal, adapter)
        if args.action == "init":
            result = runner.initialize()
        elif args.action == "status":
            result = runner.status()
        elif args.action == "approval-template":
            print(json.dumps(asdict(runner.approval_request(args.phase)), sort_keys=True))
            return 0
        elif args.action == "refresh":
            # Current-boot authority for a committed planned or terminal journal, for example after a
            # reboot. It takes both locks without waiting and refuses while a controller runs.
            guard.refresh_engine_evidence(adapter.host_authority)
            result = runner.status()
        elif args.action == "reopen":
            adapter.reopen()
            result = runner.status()
        else:
            approval = None
            if args.approval is not None:
                approval = Approval(**json.loads(guard.read_protected(args.approval)))
            result = runner.perform(args.action, approval)
        observation = asdict(adapter.observe())
    except (UnsafeCutover, guard.Refused, ValueError, TypeError, KeyError, OSError) as error:
        print(f"Cutover controller refused: {error}", file=sys.stderr)
        return 1
    print(json.dumps({"state": result, "observation": observation}, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
