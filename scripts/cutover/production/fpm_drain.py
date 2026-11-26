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
  SQLite rolls back their transactions; the report keeps the last counters;
- stopped: nothing listens at the status socket nor at the pool's socket (`fpm.socket`): FPM is stopped.

It refuses (exit 1: the stop does not run, the freeze fails and the hold stays) when the pool's socket
listens and the status socket does not (the snippet is missing, and "stopped" would let the stop end
in-flight requests unseen), when the status page answers with anything but its JSON document, when
`fpm.status_socket` is `fpm.socket` (there every status request is an active process), and when
`fpm.drain_seconds` leaves no room inside `command_seconds`, after which the adapter would kill the
drain and never run the stop. Transient errors (a timeout, a reset) are retried until the limit.

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
DRAINED, TIMED_OUT, STOPPED = "drained", "timed_out", "stopped"
SNIPPET = "production/php-fpm/repro-status.conf"
# The request experiment E8 proved against PHP-FPM 8.3's pm.status_listen.
STATUS_PARAMS = {"SCRIPT_NAME": STATUS_PATH, "SCRIPT_FILENAME": STATUS_PATH, "QUERY_STRING": "json",
                 "REQUEST_METHOD": "GET", "SERVER_PROTOCOL": "HTTP/1.1"}
COUNTERS = {"accepted": "accepted conn", "active": "active processes", "idle": "idle processes",
            "total": "total processes", "listen_queue": "listen queue"}


class Refused(Exception):
    """The drain cannot tell whether requests are in flight: the stop must not follow."""


class StatusError(Refused):
    """The status socket answered, but not with the pool's status document."""


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
    outcome: str                  # drained, timed_out or stopped
    limit_seconds: float
    waited_seconds: float
    polls: int                    # status readings
    status: PoolStatus | None     # the last reading
    problem: str | None           # the last transient error after it

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
                raise Refused(f"the pool listens at {main_socket} but nothing answers at {status_socket} "
                              f"({error}): install {SNIPPET} and reload FPM (host proof P8)") from None
            quiet, problem = None, str(error)
        except StatusError:
            raise
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


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--config", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        if sys.platform != "linux" or os.geteuid() != 0:
            raise Refused("the FPM drain runs as root on the Linux host")
        config = host.load_config(guard.read_protected(args.config), read=guard.read_protected)
        fpm = config["fpm"]
        if os.path.normpath(fpm["status_socket"]) == os.path.normpath(fpm["socket"]):
            raise Refused("fpm.status_socket must be the pool's pm.status_listen, not fpm.socket: on the pool's "
                          "own socket every status request is an active process, and the pool never reads idle")
        if fpm["drain_seconds"] + REQUEST_SECONDS + MARGIN_SECONDS > config["command_seconds"]:
            raise Refused(f"fpm.drain_seconds ({fpm['drain_seconds']}) must leave "
                          f"{REQUEST_SECONDS + MARGIN_SECONDS:g} s inside command_seconds "
                          f"({config['command_seconds']}): the adapter stops a host command after "
                          "command_seconds, and the FPM stop after the drain would never run")
        result = drain(Path(fpm["status_socket"]), fpm["drain_seconds"], main_socket=Path(fpm["socket"]))
    except (Refused, guard.Refused, fastcgi.FastCgiError, OSError, ValueError, TypeError, KeyError) as error:
        print(f"FPM drain refused: {error}", file=sys.stderr)
        return 1
    print(json.dumps(result.report(), sort_keys=True))
    if result.outcome == TIMED_OUT:
        active = "an unknown number of" if result.status is None else result.status.active
        print(f"FPM drain: {active} request(s) still active after {fpm['drain_seconds']} s; the stop ends them",
              file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
