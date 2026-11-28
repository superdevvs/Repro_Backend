"""Arm a challenge-bound abort through the host's transient systemd timer command."""

import re
from datetime import datetime, timezone

from runner import UnsafeCutover

CLI = "/opt/repro-cutover-fence/production/cli.py"


def arm(adapter, phase, challenge, *, purpose, deadline, clock):
    if phase not in ("holding", "frozen", "ready") or not re.fullmatch(r"[a-f0-9]{32}", challenge):
        raise UnsafeCutover("Invalid timer phase or journal challenge")
    if purpose not in ("approval", "deadline") or (purpose == "approval" and phase != "ready"):
        raise UnsafeCutover("Invalid purpose for the conditional abort timer")
    if type(deadline) is not int or not 0 < deadline - clock() <= 45 * 60:
        raise UnsafeCutover("Timer deadline has expired or exceeds the cutover decision limit")
    unit = f"repro-cutover-{purpose}-{phase}-{challenge[:12]}"
    commands = adapter.config["commands"]
    # systemctl returns EXIT_NOTINSTALLED (5) for an absent transient timer.
    stop = commands.get("timer_stop")
    if stop is None:
        if commands["timer"][0] != "/usr/bin/systemd-run":
            raise UnsafeCutover("Custom timer command requires its matching timer_stop command")
        stop = ["/usr/bin/systemctl"]
    code, _ = adapter._execute([*stop, "stop", unit + ".timer"], deadline)
    if code not in (0, 5):
        raise UnsafeCutover(f"Could not stop prior timer {unit} (exit {code})")
    command = list(commands["timer"])
    if clock() >= deadline:
        raise UnsafeCutover("Timer deadline expired while stopping the prior unit")
    for option in ("--collect", "--property=UMask=0077", "--timer-property=AccuracySec=1us"):
        if option not in command:
            command.append(option)
    when = datetime.fromtimestamp(deadline, timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")
    adapter._run([*command, f"--unit={unit}", f"--on-calendar={when}", "/usr/bin/python3",
                  "-I", "-S", CLI, "abort", "--if-challenge", challenge, "--if-phase", phase], deadline)
    if clock() >= deadline:
        raise UnsafeCutover("Timer deadline expired during activation")
    return unit
