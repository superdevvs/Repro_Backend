"""Root admission wrapper for production writers.

Supervisor programs, cron entries and systemd services start their writer
through this fixed, root-owned entrypoint instead of the writer itself:

    /usr/bin/python3 -I -S /opt/repro-cutover-fence/production/admit.py <profile>

The wrapper reads the root-owned profile file, takes the shared writer lease
through the cutover guard and verifies current authority for the profile's
action and engine. Only then does it start the profile's fixed command as the
profile's user. The command inherits the lease descriptor, and so does every
child it starts, including detached background jobs. The cutover drain
therefore waits until the last of them has exited. A refused admission exits
75 (EX_TEMPFAIL) and runs nothing; a bad profile exits 78 (EX_CONFIG).
"""

from __future__ import annotations

import grp
import os
import pwd
import re
import signal
import subprocess
import sys
from contextlib import ExitStack
from pathlib import Path

sys.path[:0] = [str(Path(__file__).resolve().parent), str(Path(__file__).resolve().parent.parent)]
try:
    from fence import guard  # Repository layout.
except ImportError:  # Installed layout: /opt/repro-cutover-fence/guard.py
    import guard

WRAPPERS = Path("/etc/repro-cutover-fence/wrappers.json")
SCHEDULER_RELEASE = Path("/run/repro-cutover-fence/scheduler-released")
REFUSED = 75
CONFIGURATION = 78
TIMED_OUT = 124
FORWARDED = (signal.SIGTERM, signal.SIGINT, signal.SIGHUP, signal.SIGQUIT, signal.SIGUSR1, signal.SIGUSR2)
PROFILE_FIELDS = {"action", "engine", "user", "group", "cwd", "umask", "argv", "env", "timeout_seconds"}
STOP_GRACE_SECONDS = 30


def load_profile(name: str, raw: bytes) -> dict:
    record = guard.read_json(raw)
    profiles = record.get("profiles")
    guard.require(isinstance(profiles, dict) and re.fullmatch(r"[a-z][a-z0-9-]{0,63}", name) is not None
                  and isinstance(profiles.get(name), dict), "Unknown admission profile")
    profile = profiles[name]
    guard.require(profile.keys() == PROFILE_FIELDS, "Admission profile fields differ from the contract")
    argv, env, timeout = profile["argv"], profile["env"], profile["timeout_seconds"]
    guard.require(profile["action"] in guard.ACTIONS and profile["action"] != "deploy"
                  and profile["engine"] in ("sqlite", "mysql"), "Unknown admission action or engine")
    guard.require(isinstance(argv, list) and argv and all(isinstance(part, str) for part in argv)
                  and Path(argv[0]).is_absolute() and ".." not in Path(argv[0]).parts,
                  "The profile command must be a fixed absolute argument list")
    guard.require(isinstance(env, dict) and all(isinstance(key, str) and isinstance(value, str)
                                                and re.fullmatch(r"[A-Z][A-Z0-9_]*", key) for key, value in env.items()),
                  "The profile environment must be plain text")
    guard.require(timeout is None or (type(timeout) is int and timeout > 0), "Invalid profile timeout")
    guard.require(isinstance(profile["cwd"], str) and Path(profile["cwd"]).is_absolute(), "Invalid working directory")
    guard.require(isinstance(profile["umask"], str) and re.fullmatch(r"0[0-7]{3}", profile["umask"]) is not None,
                  "Invalid umask")
    account = pwd.getpwnam(profile["user"])
    guard.require(account.pw_uid != 0 and grp.getgrnam(profile["group"]).gr_gid != 0,
                  "Writers never run as root")
    return profile


def environment(profile: dict, account: pwd.struct_passwd) -> dict:
    # Never pass on root's environment from cron, Supervisor or systemd.
    return {"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": account.pw_dir, "USER": account.pw_name,
            "LOGNAME": account.pw_name, "LANG": "C.UTF-8", **profile["env"]}


def supervise(child: subprocess.Popen, timeout: int | None) -> int:
    try:
        code = child.wait(timeout)
    except subprocess.TimeoutExpired:
        child.terminate()
        try:
            child.wait(STOP_GRACE_SECONDS)
        except subprocess.TimeoutExpired:
            child.kill()
            child.wait()
        return TIMED_OUT
    return code if code >= 0 else 128 - code


def run(name: str, *, wrappers: Path = WRAPPERS) -> int:
    try:
        profile = load_profile(name, guard.read_protected(wrappers))
        account = pwd.getpwnam(profile["user"])
        group = grp.getgrnam(profile["group"]).gr_gid
    except (guard.Refused, KeyError, OSError, ValueError, UnicodeError, RecursionError) as error:
        print(f"Cutover admission wrapper configuration refused: {error}", file=sys.stderr)
        return CONFIGURATION

    child = None

    def forward(signum, _frame):
        # Supervisor and systemd stop the wrapper; the writer decides how to finish.
        if child is not None and child.poll() is None:
            child.send_signal(signum)

    with ExitStack() as stack:
        try:
            import retire
            retired = not retire.exists(guard.STATE / "state.json") and retire.exists(retire.RETIRED)
            lease = stack.enter_context(retire.retired_lease(profile["engine"], marker=retire.RETIRED)
                                        if retired else guard.admitted(profile["action"], profile["engine"]))
            if profile["action"] == "scheduler" and not retired:
                # Checked under the shared writer lease. A concurrent hold drains
                # this invocation along with the scheduler children it starts.
                with guard.open_protected(SCHEDULER_RELEASE):
                    pass
        except guard.Refused as error:
            print(f"Cutover admission held: {error}", file=sys.stderr)
            return REFUSED
        except (OSError, ValueError, UnicodeError, RecursionError):
            print("Cutover admission held: lock, host evidence or journal is unavailable or invalid", file=sys.stderr)
            return REFUSED
        previous = {signum: signal.signal(signum, forward) for signum in FORWARDED}
        stack.callback(lambda: [signal.signal(signum, handler) for signum, handler in previous.items()])
        try:
            # The writer stays in this process group so a group stop reaches it.
            child = subprocess.Popen(
                profile["argv"], cwd=profile["cwd"], env=environment(profile, account), stdin=subprocess.DEVNULL,
                user=account.pw_uid, group=group, extra_groups=os.getgrouplist(account.pw_name, group),
                umask=int(profile["umask"], 8), pass_fds=(lease,), close_fds=True,
            )
        except OSError as error:
            print(f"Cutover admission wrapper could not start the writer: {error}", file=sys.stderr)
            return CONFIGURATION
        return supervise(child, profile["timeout_seconds"])


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print("Usage: admit.py <profile>", file=sys.stderr)
        return CONFIGURATION
    return run(argv[1])


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
