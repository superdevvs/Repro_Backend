#!/usr/bin/env python3
"""Send one alert-provider ping per condition without revealing the ping URL.

`signal` sends one ok or fail ping. `forward` relays the latest status written
by the local detectors (alert_probe.py) for each configured condition. The ping
URLs live in a private 0600 file owned by the bridge account. Output and
errors never contain a URL, a token or provider text.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import socket
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request

STATUS_SCHEMA = "repro.alert.status.v1"
CONDITION = re.compile(r"[a-z][a-z0-9_]{0,63}")
HOST = re.compile(r"(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*"
                  r"[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?")
URL_PATH = re.compile(r"/[A-Za-z0-9._~/-]{1,512}")
SUFFIX = re.compile(r"/[a-z0-9_-]{1,32}")
CONFIG_FIELDS = {"version", "urls_file", "inbox_dir", "fail_suffix", "timeout_seconds",
                 "max_status_age_seconds", "forward"}
STATUS_FIELDS = {"schema", "condition", "state", "observed_unix_s", "detail"}
LIMIT = 64 * 1024
USER_AGENT = "repro-alert-bridge/1"


class Refusal(RuntimeError):
    pass


def need(condition: bool, reason: str) -> None:
    if not condition:
        raise Refusal(reason)


def parse_config(raw: bytes) -> dict:
    need(0 < len(raw) <= LIMIT, "invalid bridge config size")
    try:
        config = json.loads(raw)
    except (ValueError, UnicodeError):
        raise Refusal("bridge config is not JSON") from None
    need(isinstance(config, dict) and set(config) == CONFIG_FIELDS and type(config["version"]) is int
         and config["version"] == 1, "unexpected bridge config fields")
    for field in ("urls_file", "inbox_dir"):
        need(isinstance(config[field], str) and Path(config[field]).is_absolute(), "bridge paths must be absolute")
    need(config["fail_suffix"] is None or (isinstance(config["fail_suffix"], str)
                                           and SUFFIX.fullmatch(config["fail_suffix"]) is not None),
         "fail_suffix must be null or a short path segment")
    need(type(config["timeout_seconds"]) is int and 1 <= config["timeout_seconds"] <= 30, "invalid ping timeout")
    need(type(config["max_status_age_seconds"]) is int and 60 <= config["max_status_age_seconds"] <= 3600,
         "invalid detector status age")
    forward = config["forward"]
    need(isinstance(forward, list) and len(forward) == len(set(forward)) <= 64
         and all(isinstance(name, str) and CONDITION.fullmatch(name) for name in forward),
         "invalid forwarded condition list")
    return config


def check_url(value: object) -> str:
    need(isinstance(value, str) and len(value) <= 1024, "invalid ping URL")
    parts = urllib.parse.urlsplit(value)
    need(parts.scheme == "https" and not parts.username and not parts.password and not parts.query
         and not parts.fragment and "@" not in parts.netloc, "ping URL must be plain HTTPS without credentials")
    try:
        port = parts.port
    except ValueError:
        raise Refusal("ping URL has an invalid port") from None
    need(parts.hostname is not None and HOST.fullmatch(parts.hostname) is not None
         and (port is None or 0 < port < 65536), "ping URL has an invalid host")
    need(URL_PATH.fullmatch(parts.path) is not None and "/../" not in parts.path + "/",
         "ping URL needs a plain check path")
    return value


def parse_urls(raw: bytes) -> dict[str, str]:
    need(0 < len(raw) <= LIMIT, "invalid ping URL file size")
    try:
        record = json.loads(raw)
    except (ValueError, UnicodeError):
        raise Refusal("ping URL file is not JSON") from None
    need(isinstance(record, dict) and set(record) == {"version", "checks"} and record["version"] == 1
         and isinstance(record["checks"], dict) and record["checks"], "unexpected ping URL file fields")
    checks = {}
    for name, url in record["checks"].items():
        need(isinstance(name, str) and CONDITION.fullmatch(name) is not None, "invalid condition name")
        checks[name] = check_url(url)
    return checks


def read_private_urls(path: Path) -> dict[str, str]:
    """Read the URL file only if it is a 0600 regular file of the bridge account."""
    need(os.name == "posix" and path.is_absolute(), "Linux absolute paths required")
    for entry in reversed(path.parents):
        info = entry.lstat()
        need(not os.path.islink(entry) and info.st_uid in (0, os.geteuid()) and not info.st_mode & 0o022,
             "ping URL file has an unsafe ancestor")
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(fd, "rb") as stream:
        info = os.fstat(stream.fileno())
        need(info.st_uid == os.geteuid() and info.st_mode & 0o7777 == 0o600 and info.st_nlink == 1
             and (info.st_mode & 0o170000) == 0o100000, "ping URL file must be a private 0600 file")
        return parse_urls(stream.read(LIMIT + 1))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_args, **_kwargs):
        return None


def deliver(url: str, timeout: int, context: ssl.SSLContext | None) -> tuple[int | None, str | None]:
    """Return (status, None) for any HTTP answer, or (None, reason) without URL text."""
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(),
                                         urllib.request.HTTPSHandler(context=context or ssl.create_default_context()))
    request = urllib.request.Request(url, method="GET", headers={"User-Agent": USER_AGENT})
    try:
        with opener.open(request, timeout=timeout) as response:
            response.read(1024)
            return response.status, None
    except urllib.error.HTTPError as error:
        status = error.code
        error.close()
        return status, None
    except (socket.timeout, TimeoutError):
        return None, "provider timed out"
    except ssl.SSLError:
        return None, "TLS verification or handshake failed"
    except (urllib.error.URLError, OSError, ValueError):
        return None, "network error"


def signal(config: dict, urls: dict[str, str], condition: str, state: str,
           *, context: ssl.SSLContext | None = None) -> dict:
    need(state in ("ok", "fail"), "state must be ok or fail")
    need(CONDITION.fullmatch(condition) is not None and condition in urls, "condition has no configured check")
    result = {"condition": condition, "state": state}
    if state == "fail" and config["fail_suffix"] is None:
        # The provider has no fail endpoint: withholding the ok ping lets its grace period alert.
        return {"result": "alert-ping-withheld"} | result
    url = urls[condition] + (config["fail_suffix"] if state == "fail" else "")
    status, reason = deliver(url, config["timeout_seconds"], context)
    if status is not None and 200 <= status < 300:
        return {"result": "alert-ping-delivered"} | result | {"http_status": status}
    return {"result": "alert-ping-failed"} | result | {"reason": reason or f"provider returned HTTP {status}"}


def read_status(inbox: Path, condition: str, now: int, max_age: int) -> tuple[str, str]:
    path = inbox / f"{condition}.json"
    try:
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        with os.fdopen(fd, "rb") as stream:
            raw = stream.read(LIMIT + 1)
    except FileNotFoundError:
        return "fail", "detector status missing"
    except OSError:
        return "fail", "detector status unreadable"
    try:
        record = json.loads(raw)
        valid = (len(raw) <= LIMIT and isinstance(record, dict) and set(record) == STATUS_FIELDS
                 and record["schema"] == STATUS_SCHEMA and record["condition"] == condition
                 and record["state"] in ("ok", "fail") and type(record["observed_unix_s"]) is int)
    except (ValueError, UnicodeError):
        valid = False
    if not valid:
        return "fail", "detector status invalid"
    if not -60 <= now - record["observed_unix_s"] <= max_age:
        return "fail", "detector status stale"
    return record["state"], "detector reported " + record["state"]


def forward(config: dict, urls: dict[str, str], *, context: ssl.SSLContext | None = None,
            now: int) -> list[dict]:
    results = []
    for condition in config["forward"]:
        state, status = read_status(Path(config["inbox_dir"]), condition, now, config["max_status_age_seconds"])
        results.append(signal(config, urls, condition, state, context=context) | {"status": status})
    return results


def run(argv: list[str], *, config: bytes | None = None, urls: bytes | None = None,
        context: ssl.SSLContext | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("signal", "forward"))
    parser.add_argument("--config", type=Path, default=Path("/etc/repro-alert/bridge.json"))
    parser.add_argument("--condition")
    parser.add_argument("--state", choices=("ok", "fail"))
    args = parser.parse_args(argv)
    try:
        if config is None:
            with args.config.open("rb") as stream:
                config = stream.read(LIMIT + 1)
        settings = parse_config(config)
        checks = parse_urls(urls) if urls is not None else read_private_urls(Path(settings["urls_file"]))
        if args.action == "signal":
            need(args.condition is not None and args.state is not None, "signal needs --condition and --state")
            results = [signal(settings, checks, args.condition, args.state, context=context)]
        else:
            import time

            results = forward(settings, checks, context=context, now=int(time.time()))
    except Refusal as error:
        print(json.dumps({"result": "alert-bridge-refused", "reason": str(error)}))
        return 1
    except (OSError, ValueError, TypeError):
        print(json.dumps({"result": "alert-bridge-refused", "reason": "unreadable bridge config or URL file"}))
        return 1
    for item in results:
        print(json.dumps(item, sort_keys=True))
    return 0 if all(item["result"] != "alert-ping-failed" for item in results) else 1


def main() -> int:
    os.umask(0o077)
    return run(sys.argv[1:])


if __name__ == "__main__":
    raise SystemExit(main())
