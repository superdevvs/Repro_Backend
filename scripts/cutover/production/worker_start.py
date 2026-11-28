"""Check configured writer processes against the recorded MySQL switch time on Linux."""

from __future__ import annotations

import os
from pathlib import Path

from runner import UnsafeCutover


def _start_ticks(pid: int, raw: bytes) -> int:
    # comm (field 2) can contain spaces, newlines and closing parentheses. The
    # last closing parenthesis precedes the state (field 3); starttime is field 22.
    comm, delimiter, rest = raw.rpartition(b") ")
    fields = rest.split()
    if (not delimiter or not comm.startswith(f"{pid} (".encode("ascii")) or len(fields) < 20
            or fields[0] not in (b"R", b"S", b"D", b"T", b"t", b"I", b"W", b"P")
            or not fields[19].isdigit()):
        raise UnsafeCutover(f"Cannot verify the start of writer PID {pid}")
    return int(fields[19])


def verify(proc: Path, writer_patterns: list[str], switch_at: int, *, clock_ticks: int | None = None) -> list[int]:
    """Return matching live PIDs whose start can be proved strictly after the switch.

    Writer patterns have the same OR/substring semantics as ProductionAdapter.processes.
    Some patterns match intermittent jobs. This checks the age of observed processes,
    while expected service counts are a separate service-manager observation.
    """
    if type(switch_at) is not int or switch_at <= 0:
        raise UnsafeCutover("The MySQL switch record has no valid switch time")
    if not writer_patterns or any(not isinstance(pattern, str) or not pattern.strip() for pattern in writer_patterns):
        raise UnsafeCutover("No valid writer process inventory is configured")
    try:
        ticks = os.sysconf("SC_CLK_TCK") if clock_ticks is None else clock_ticks
        boot = [line.split()[1:] for line in (proc / "stat").read_bytes().splitlines()
                if line.split()[:1] == [b"btime"]]
        if type(ticks) is not int or ticks <= 0 or len(boot) != 1 or len(boot[0]) != 1 or not boot[0][0].isdigit():
            raise UnsafeCutover("Cannot verify the process clock or boot time")
        boot_seconds = int(boot[0][0])
        if boot_seconds <= 0:
            raise UnsafeCutover("Cannot verify the boot time")
        # switch_at and btime are stored as integer seconds. A writer born in
        # the same reported second could have started before the cache switch.
        first_safe_tick = (switch_at + 1 - boot_seconds) * ticks
        patterns = tuple(pattern.encode("utf-8") for pattern in writer_patterns)
        verified = []
        for entry in proc.iterdir():
            if not entry.name.isascii() or not entry.name.isdigit() or int(entry.name) == os.getpid():
                continue
            pid = int(entry.name)
            try:
                previous = (entry / "stat").read_bytes()
                command = (entry / "cmdline").read_bytes().replace(b"\0", b" ")
            except (FileNotFoundError, ProcessLookupError):
                # An unrelated process may exit during the scan. An existing PID
                # with inaccessible or incomplete metadata cannot be ruled out.
                if entry.exists():
                    raise UnsafeCutover(f"Incomplete process inventory for PID {pid}") from None
                continue
            if not any(pattern in command for pattern in patterns):
                continue
            birth = _start_ticks(pid, previous)
            current = _start_ticks(pid, (entry / "stat").read_bytes())
            if current != birth:
                raise UnsafeCutover(f"Writer PID {pid} changed during verification")
            if birth < first_safe_tick:
                raise UnsafeCutover(f"Writer PID {pid} cannot be proved newer than the MySQL switch")
            verified.append(pid)
    except (OSError, AttributeError, ValueError) as error:
        raise UnsafeCutover("Cannot verify the Linux writer process inventory") from error
    if not verified:
        raise UnsafeCutover("No live writer matched the configured process inventory")
    return sorted(verified)
