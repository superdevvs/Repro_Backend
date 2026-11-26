"""FastCGI client of the cutover controller (design v2, 5.3 and 5.4).

    /usr/bin/python3 -I -S /opt/repro-cutover-fence/production/fastcgi.py \\
        --socket /run/php/php8.3-fpm.sock --app-root /var/www/backend

One request per connection to a PHP-FPM Unix socket, as a responder without keep-alive: BEGIN_REQUEST,
the PARAMS stream and the STDIN stream (records of at most 65535 bytes, each stream closed by an empty
record), then STDOUT and STDERR records until END_REQUEST. The record handling is the client of
scripts/cutover/probe/test_fpm_engine.py (experiment E8's), the one proven against PHP-FPM 8.3, made
strict: every record must be version 1, for request 1, and STDOUT, STDERR or END_REQUEST; the end record
must say the request completed and nothing may follow it; a stream that closes before it, more than
`max_output` bytes, or the deadline (one bound for the whole exchange, not per read) refuses. A missing
socket, or one nothing listens on, raises NotListening: fpm_drain reads that as a stopped pool.

`engine_probe(socket, app_root)` sends `<app_root>/scripts/cutover/probe/fpm-engine.php` (design 5.3)
with a fixed request line and nothing else. Laravel reads `$_SERVER` as its environment, so a parameter
could stand in for the pool's own (an `APP_CONFIG_CACHE`, say), and the probe must report FPM's
effective config. It returns the probe's JSON report; the caller checks the engine and the pin
(`live_config.route_sha256` of `route_values`; PHP never hashes). The CLI prints
`{"version": 1, "report", "route_sha256"}` for host proof P8 and exits 0 when the report is ok, 2 when
it is not, 1 when there is no report.

Standard library only.
"""

from __future__ import annotations

import argparse
import json
import os
import posixpath
import re
import socket
import struct
import sys
import time
from dataclasses import dataclass
from pathlib import Path

VERSION = 1
BEGIN_REQUEST, END_REQUEST, PARAMS, STDIN, STDOUT, STDERR = 1, 3, 4, 5, 6, 7
RESPONDER, REQUEST_COMPLETE = 1, 0
REQUEST_ID = 1
# version, type, request id, content length, padding length, reserved
HEADER = struct.Struct("!BBHHBx")
# role, flags (0: the server closes the connection after the request), reserved
BEGIN_BODY = struct.Struct("!HB5x")
# application status, protocol status, reserved
END_BODY = struct.Struct("!IB3x")
MAX_CONTENT = 0xFFFF
MAX_LENGTH = 0x7FFFFFFF
MAX_OUTPUT = 1024 * 1024
PROTOCOL_STATUS = {1: "it cannot multiplex", 2: "it is overloaded", 3: "it does not know the role"}
ENGINE_PROBE = ("scripts", "cutover", "probe", "fpm-engine.php")
# connect(2) on a Unix socket: ENOENT, no socket at the path; ECONNREFUSED, nothing listens on it.
NOT_LISTENING = (FileNotFoundError, ConnectionRefusedError)
HEADER_NAME = re.compile(r"[!#$%&'*+.^_`|~0-9a-z-]+")
STATUS_CODE = re.compile(r"[1-5][0-9][0-9]")


class FastCgiError(Exception):
    """The exchange failed, or the answer is not one complete FastCGI response."""


class NotListening(FastCgiError):
    """No socket at the path, or nothing listens on it: no FPM master serves it."""


class ProbeFailed(FastCgiError):
    """fpm-engine.php did not answer with its report."""


@dataclass(frozen=True)
class Response:
    status: int            # from the CGI Status header; 200 without one
    headers: dict          # lower-case names; a repeated header's values joined with ", "
    body: bytes
    stdout: bytes          # the whole STDOUT stream, headers included
    stderr: bytes          # the whole STDERR stream (PHP messages when fastcgi.logging is on)
    app_status: int
    protocol_status: int


def _text(value, what: str) -> bytes:
    if not isinstance(value, str):
        raise FastCgiError(f"{what} is not text")
    raw = value.encode("utf-8")
    if b"\0" in raw or len(raw) > MAX_LENGTH:
        raise FastCgiError(f"{what} holds a NUL byte or is too long")
    return raw


def _length(size: int) -> bytes:
    return bytes([size]) if size < 0x80 else struct.pack("!I", size | 0x80000000)


def encode_params(params) -> bytes:
    """FastCGI name-value pairs: each length in one byte below 128, else in four with the top bit set."""
    encoded = []
    for name, value in params.items():
        raw_name, raw_value = _text(name, "a parameter name"), _text(value, f"parameter {name!r}")
        if raw_name == b"":
            raise FastCgiError("a parameter name is empty")
        encoded += [_length(len(raw_name)), _length(len(raw_value)), raw_name, raw_value]
    return b"".join(encoded)


def _stream(kind: int, content: bytes) -> bytes:
    """One stream: records of at most 65535 bytes, closed by an empty record."""
    chunks = [content[start:start + MAX_CONTENT] for start in range(0, len(content), MAX_CONTENT)]
    return b"".join(HEADER.pack(VERSION, kind, REQUEST_ID, len(chunk), 0) + chunk for chunk in [*chunks, b""])


def _remaining(deadline: float) -> float:
    left = deadline - time.monotonic()
    if left <= 0:
        raise TimeoutError("the deadline passed")
    return left


def request(socket_path, params, stdin: bytes = b"", *, timeout: float = 10.0,
            max_output: int = MAX_OUTPUT) -> Response:
    """One FastCGI request; `timeout` bounds the connect, the send and every read together."""
    deadline = time.monotonic() + timeout
    message = (HEADER.pack(VERSION, BEGIN_REQUEST, REQUEST_ID, BEGIN_BODY.size, 0) + BEGIN_BODY.pack(RESPONDER, 0)
               + _stream(PARAMS, encode_params(params)) + _stream(STDIN, bytes(stdin)))
    path = os.fspath(socket_path)
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as peer:
        try:
            peer.settimeout(_remaining(deadline))
            peer.connect(path)
        except NOT_LISTENING as error:
            raise NotListening(f"nothing listens at {path}: {error.strerror}") from None
        except TimeoutError:
            raise FastCgiError(f"connecting to {path} timed out after {timeout} s") from None
        except OSError as error:
            raise FastCgiError(f"cannot connect to {path}: {error}") from None
        try:
            peer.settimeout(_remaining(deadline))
            peer.sendall(message)
            stdout, stderr, app_status, protocol_status = _read(peer, deadline, max_output)
        except TimeoutError:
            raise FastCgiError(f"the request to {path} timed out after {timeout} s") from None
        except OSError as error:
            raise FastCgiError(f"the exchange with {path} failed: {error}") from None
    if protocol_status != REQUEST_COMPLETE:
        reason = PROTOCOL_STATUS.get(protocol_status, f"protocol status {protocol_status}")
        raise FastCgiError(f"the server at {path} did not complete the request: {reason}")
    status, headers, body = _cgi(stdout)
    return Response(status, headers, body, stdout, stderr, app_status, protocol_status)


def _read(peer: socket.socket, deadline: float, max_output: int) -> tuple[bytes, bytes, int, int]:
    buffer, streams, size = b"", {STDOUT: [], STDERR: []}, 0
    while True:
        while len(buffer) >= HEADER.size:
            version, kind, request_id, length, padding = HEADER.unpack_from(buffer)
            if version != VERSION or request_id != REQUEST_ID:
                raise FastCgiError(f"a record of version {version} for request {request_id}")
            if kind not in (STDOUT, STDERR, END_REQUEST):
                raise FastCgiError(f"a record of unexpected type {kind}")
            if len(buffer) < HEADER.size + length + padding:
                break
            content = buffer[HEADER.size:HEADER.size + length]
            buffer = buffer[HEADER.size + length + padding:]
            if kind == END_REQUEST:
                if length != END_BODY.size:
                    raise FastCgiError(f"an end record of {length} bytes")
                if buffer:
                    raise FastCgiError("data after the end record")
                app_status, protocol_status = END_BODY.unpack(content)
                return b"".join(streams[STDOUT]), b"".join(streams[STDERR]), app_status, protocol_status
            size += length
            if size > max_output:
                raise FastCgiError(f"the response holds more than {max_output} bytes")
            streams[kind].append(content)
        peer.settimeout(_remaining(deadline))
        chunk = peer.recv(65536)
        if not chunk:
            raise FastCgiError("the connection closed before the end of the request")
        buffer += chunk


def _cgi(stdout: bytes) -> tuple[int, dict, bytes]:
    """The CGI header block of the STDOUT stream: status, headers and body."""
    head, separator, body = stdout.partition(b"\r\n\r\n")
    if not separator:
        raise FastCgiError("the response has no CGI header block")
    headers: dict[str, str] = {}
    for line in head.decode("latin-1").split("\r\n"):
        name, colon, value = line.partition(":")
        name = name.strip().lower()
        if not colon or HEADER_NAME.fullmatch(name) is None:
            raise FastCgiError(f"a malformed CGI header line: {line[:80]!r}")
        value = value.strip()
        headers[name] = f"{headers[name]}, {value}" if name in headers else value
    status = headers.get("status", "200")
    code = status.split(" ", 1)[0]
    if STATUS_CODE.fullmatch(code) is None:
        raise FastCgiError(f"a malformed CGI header Status: {status[:40]!r}")
    return int(code), headers, body


def listening(socket_path, timeout: float = 2.0) -> bool:
    """Whether something listens on the Unix socket (a full backlog counts as listening)."""
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as peer:
        peer.settimeout(timeout)
        try:
            peer.connect(os.fspath(socket_path))
        except NOT_LISTENING:
            return False
        except BlockingIOError:
            return True
        except OSError as error:
            raise FastCgiError(f"cannot tell whether {socket_path} listens: {error}") from None
    return True


def _refuse_constant(name: str):
    raise ValueError(f"{name} is not JSON")


def engine_probe(socket_path, app_root, *, timeout: float = 30.0) -> dict:
    """fpm-engine.php's report, from inside the pool that serves socket_path."""
    root = os.fspath(app_root)
    if not (isinstance(root, str) and root.startswith("/") and posixpath.normpath(root) == root):
        raise ProbeFailed(f"the app root must be a normal absolute path: {root!r}")
    script = posixpath.join(root, *ENGINE_PROBE)
    params = {"SCRIPT_FILENAME": script, "SCRIPT_NAME": "/fpm-engine.php", "REQUEST_URI": "/fpm-engine.php",
              "REQUEST_METHOD": "GET", "SERVER_PROTOCOL": "HTTP/1.1", "GATEWAY_INTERFACE": "CGI/1.1",
              "QUERY_STRING": ""}
    response = request(socket_path, params, timeout=timeout)
    content_type = response.headers.get("content-type", "").split(";", 1)[0].strip().lower()
    if response.status not in (200, 500) or content_type != "application/json":
        raise ProbeFailed(f"{script} answered {response.status} {content_type or 'without a content type'}: "
                          f"{response.body[:200]!r}")
    try:
        report = json.loads(response.body.decode("utf-8"), parse_constant=_refuse_constant)
    except (UnicodeDecodeError, ValueError) as error:
        raise ProbeFailed(f"{script} did not print JSON: {error}") from None
    if not (isinstance(report, dict) and report.get("probe") == "fpm-engine" and type(report.get("ok")) is bool):
        raise ProbeFailed(f"{script} did not print the fpm-engine report")
    if report["ok"] != (response.status == 200):
        raise ProbeFailed(f"{script} answered {response.status} with ok {report['ok']}")
    return report


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--socket", type=Path, required=True)
    parser.add_argument("--app-root", required=True)
    parser.add_argument("--timeout", type=float, default=30.0)
    args = parser.parse_args(argv)
    try:
        report = engine_probe(args.socket, args.app_root, timeout=args.timeout)
    except FastCgiError as error:
        print(f"FastCGI probe: {error}", file=sys.stderr)
        return 1
    sys.path[:0] = [str(Path(__file__).resolve().parent)]
    import live_config

    values = report.get("route_values")
    try:
        route = live_config.route_sha256(values) if isinstance(values, dict) else None
    except (TypeError, ValueError):
        route = None
    print(json.dumps({"version": 1, "report": report, "route_sha256": route}, sort_keys=True))
    return 0 if report["ok"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
