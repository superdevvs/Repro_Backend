"""Drain the app's PHP-FPM before the freeze stops it (design v2, 5.4).

    /usr/bin/python3 -I -S /opt/repro-cutover-fence/production/fpm_drain.py --config /etc/repro-cutover-fence/host.json

The first command of host.json `drain_fpm`; `systemctl stop php8.3-fpm.service` follows it. nginx already
answers 503 before PHP for every new request, so only requests admitted before the hold are left. The
drain polls the pool's status page on its own socket (`fpm.status_socket`, the `pm.status_listen` of
php-fpm/repro-status.conf) through the FastCGI client every 0.25 s until the pool is idle, for at most
`fpm.drain_seconds` (90 s), and returns; the stop follows.

Idle means two readings in a row with no active process, nothing in the listen queue and the same
accepted-connection count. PHP-FPM does not measure the listen queue of a Unix socket (it stays 0 while
a request waits for the one busy child), so a request taken from the backlog between two readings shows
only as a moved accepted count. A status request on pm.status_listen moves neither count (E8).

Outcomes, printed as one JSON object, each with exit 0 so that the stop runs:
- drained: the pool was idle;
- timed_out: the limit passed with requests still active (or without a reading). The stop ends them and
  SQLite rolls back their transactions; the report keeps the last counters. Without a reading (polls 0:
  only transient errors) the drain saw none of the pool's requests, and the adapter refuses a freeze on
  it as on unmonitored; a rollback records it and stops FPM (review W2F-6);
- stopped: nothing listens at the status socket nor at the pool's socket (`fpm.socket`): FPM is stopped;
- unmonitored: the drain cannot see the pool's requests, because the pool's socket listens and nothing
  answers at the status socket on the first reading (the snippet is missing; "stopped" would hide
  in-flight requests), or because the status page answered with anything but its JSON document. The
  report's `problem` says which, in fixed words. The adapter refuses a freeze on it (design 5.4); a
  rollback, where nginx already holds every request, records it and stops FPM anyway (review A2-2).

The report always has the keys version, outcome, limit_seconds, waited_seconds, polls, pool,
accepted_conn, active_processes, idle_processes, total_processes, listen_queue and problem. The pool and
its counters are those of the last status reading, null when there was none; problem is null, the last
transient error of a timed_out drain, or the fixed text of an unmonitored one. Transient errors (a
timeout, a reset) are retried until the limit.

It refuses (exit 1, nothing on stdout: the stop does not run) when it cannot run as configured: not
root, a protected file or a host config that load_config refuses, `fpm.status_socket` equal to
`fpm.socket` (there every status request is an active process), `fpm.drain_seconds` leaving no room
inside `command_seconds` (the adapter would kill the drain and never run the stop), and a `fpm.socket`
whose connect fails other than by no socket, nothing listening or a full backlog (a path through a
file, say), so that the drain cannot tell stopped from unmonitored. load_config refuses the two
`fpm` conditions first; drain_settings keeps its own copy of each as defence in depth (review W2I-4).

Standard library only.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import sys
import time
from dataclasses import dataclass
from pathlib import Path

sys.path[:0] = [str(Path(__file__).resolve().parent), str(Path(__file__).resolve().parent.parent)]
import adapter as host  # noqa: E402
import fastcgi  # noqa: E402
from adapter import guard  # noqa: E402

VERSION = 1
STATUS_PATH = "/repro-fpm-status"
POLL_SECONDS = 0.25
REQUEST_SECONDS = 2.0
# What adapter._run needs beyond the drain's own limit: the last status request, start-up, the report.
MARGIN_SECONDS = 10
DRAINED, TIMED_OUT, STOPPED, UNMONITORED = "drained", "timed_out", "stopped", "unmonitored"
SNIPPET = "production/php-fpm/repro-status.conf"
# The problem of an unmonitored drain: fixed words, no path, nothing the status socket answered.
NO_STATUS_SOCKET = ("the pool listens but nothing answers at its status socket: the status snippet is not "
                    "installed or not loaded (host proof P8)")
NOT_A_STATUS_DOCUMENT = "the status socket answered, but not with the pool's JSON status document"
# The request experiment E8 proved against PHP-FPM 8.3's pm.status_listen.
STATUS_PARAMS = {"SCRIPT_NAME": STATUS_PATH, "SCRIPT_FILENAME": STATUS_PATH, "QUERY_STRING": "json",
                 "REQUEST_METHOD": "GET", "SERVER_PROTOCOL": "HTTP/1.1"}
COUNTERS = {"accepted": "accepted conn", "active": "active processes", "idle": "idle processes",
            "total": "total processes", "listen_queue": "listen queue"}


class Refused(Exception):
    """The drain cannot run as configured: the stop must not follow."""


class StatusError(Exception):
    """The status socket answered, but not with the pool's status document: the drain is unmonitored."""


@dataclass(frozen=True)
class PoolStatus:
    pool: str
    accepted: int
    active: int
    idle: int
    total: int
    listen_queue: int


@dataclass(frozen=True)
class Drain:
    outcome: str                  # drained, timed_out, stopped or unmonitored
    limit_seconds: float
    waited_seconds: float
    polls: int                    # status readings
    status: PoolStatus | None     # the last reading
    problem: str | None           # the last transient error after it, or why the drain is unmonitored

    def report(self) -> dict:
        last = self.status
        counters = {key: None if last is None else getattr(last, field) for key, field in (
            ("accepted_conn", "accepted"), ("active_processes", "active"), ("idle_processes", "idle"),
            ("total_processes", "total"), ("listen_queue", "listen_queue"))}
        return {"version": VERSION, "outcome": self.outcome, "limit_seconds": self.limit_seconds,
                "waited_seconds": round(self.waited_seconds, 3), "polls": self.polls,
                "pool": None if last is None else last.pool, **counters, "problem": self.problem}


def _refuse_constant(name: str):
    raise ValueError(f"{name} is not JSON")


def parse_status(response: fastcgi.Response) -> PoolStatus:
    """The counters of PHP-FPM's JSON status page; anything else raises StatusError."""
    content_type = response.headers.get("content-type", "").split(";", 1)[0].strip().lower()
    if response.status != 200 or content_type != "application/json":
        raise StatusError(f"the status page answered {response.status} {content_type or 'without a content type'}, "
                          f"not its JSON document (pm.status_path must be {STATUS_PATH})")
    try:
        document = json.loads(response.body.decode("utf-8"), parse_constant=_refuse_constant)
    except (UnicodeDecodeError, ValueError) as error:
        raise StatusError(f"the status page is not JSON: {error}") from None
    if not (isinstance(document, dict) and isinstance(document.get("pool"), str) and document["pool"] != ""):
        raise StatusError("the status page names no pool")
    counters = {}
    for field, name in COUNTERS.items():
        value = document.get(name)
        if type(value) is not int or value < 0:
            raise StatusError(f"the status page has no count of {name!r}")
        counters[field] = value
    return PoolStatus(pool=document["pool"], **counters)


def read_status(status_socket, timeout: float) -> PoolStatus:
    return parse_status(fastcgi.request(status_socket, STATUS_PARAMS, timeout=timeout))


def _limit(value) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value) or value <= 0:
        raise Refused(f"the drain limit must be a positive number of seconds, not {value!r}")
    return value


def drain(status_socket, limit_seconds, *, main_socket, read=read_status, listening=fastcgi.listening,
          clock=time.monotonic, sleep=time.sleep, interval: float = POLL_SECONDS) -> Drain:
    """Wait until the pool is idle, for at most limit_seconds; see the module docstring."""
    limit = _limit(limit_seconds)
    started = clock()
    end = started + limit
    polls, last, quiet, problem = 0, None, None, None
    while True:
        try:
            reading = read(status_socket, max(0.05, min(REQUEST_SECONDS, end - clock())))
        except fastcgi.NotListening as error:
            if not listening(main_socket):
                return Drain(STOPPED, limit_seconds, clock() - started, polls, last, None)
            if polls == 0:
                return Drain(UNMONITORED, limit_seconds, clock() - started, polls, last, NO_STATUS_SOCKET)
            quiet, problem = None, str(error)
        except StatusError:
            return Drain(UNMONITORED, limit_seconds, clock() - started, polls, last, NOT_A_STATUS_DOCUMENT)
        except fastcgi.FastCgiError as error:
            quiet, problem = None, str(error)
        else:
            polls += 1
            last, problem = reading, None
            idle = reading.active == 0 and reading.listen_queue == 0
            if idle and quiet is not None and quiet.accepted == reading.accepted:
                return Drain(DRAINED, limit_seconds, clock() - started, polls, reading, None)
            quiet = reading if idle else None
        left = end - clock()
        if left <= 0:
            return Drain(TIMED_OUT, limit_seconds, clock() - started, polls, last, problem)
        sleep(min(interval, left))


def drain_settings(config: dict) -> tuple[Path, Path, float]:
    """(status socket, pool socket, limit) from a loaded host config; Refused when the drain cannot run as set.

    load_config refuses both conditions first (since 4b214f5e), so main() never reaches these; they stay as
    defence in depth beside the code that depends on them, and are tested by calling this function (W2I-4).
    """
    fpm = config["fpm"]
    if os.path.normpath(fpm["status_socket"]) == os.path.normpath(fpm["socket"]):
        raise Refused("fpm.status_socket must be the pool's pm.status_listen, not fpm.socket: on the pool's "
                      "own socket every status request is an active process, and the pool never reads idle")
    if fpm["drain_seconds"] + REQUEST_SECONDS + MARGIN_SECONDS > config["command_seconds"]:
        raise Refused(f"fpm.drain_seconds ({fpm['drain_seconds']}) must leave "
                      f"{REQUEST_SECONDS + MARGIN_SECONDS:g} s inside command_seconds "
                      f"({config['command_seconds']}): the adapter stops a host command after "
                      "command_seconds, and the FPM stop after the drain would never run")
    return Path(fpm["status_socket"]), Path(fpm["socket"]), fpm["drain_seconds"]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--config", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        if sys.platform != "linux" or os.geteuid() != 0:
            raise Refused("the FPM drain runs as root on the Linux host")
        config = host.load_config(guard.read_protected(args.config), read=guard.read_protected)
        status_socket, main_socket, limit = drain_settings(config)
        result = drain(status_socket, limit, main_socket=main_socket)
    except (Refused, guard.Refused, fastcgi.FastCgiError, OSError, ValueError, TypeError, KeyError) as error:
        print(f"FPM drain refused: {error}", file=sys.stderr)
        return 1
    print(json.dumps(result.report(), sort_keys=True))
    if result.outcome == TIMED_OUT:
        active = "an unknown number of" if result.status is None else result.status.active
        print(f"FPM drain: {active} request(s) still active after {limit} s; the stop ends them", file=sys.stderr)
    elif result.outcome == UNMONITORED:
        hint = f"install {SNIPPET} and reload FPM" if result.problem == NO_STATUS_SOCKET else \
            f"pm.status_path must be {STATUS_PATH}"
        print(f"FPM drain unmonitored: {result.problem}; {hint}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
