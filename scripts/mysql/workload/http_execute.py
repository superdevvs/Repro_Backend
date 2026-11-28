"""Execute one reviewed HTTP read round on a pinned private Linux Unix socket."""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import signal
import socket
import stat
import struct
import sys
import threading
import time
from urllib.parse import urlencode


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(filename))
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


profile_tools = module("http_profile_contract", "http_profile.py")
isolation = module("http_executor_isolation", "run.py")
Refusal = profile_tools.Refusal
need = profile_tools.need
keys = profile_tools.keys
integer = profile_tools.integer
MAX_INPUT = 65536
MAX_HEADERS = 16384
MAX_BODY = 1024 * 1024
HOP_HEADERS = {"connection", "keep-alive", "proxy-authenticate", "proxy-authorization",
               "proxy-connection", "te", "trailer", "transfer-encoding", "upgrade", "location"}
FRAGMENT_FIELDS = {"version", "scope", "engine", "profile_sha256", "schedule_sha256",
                   "database_identity", "executor_sha256", "server_kind", "run", "isolation",
                   "transport", "application_execution_verified", "database_metrics_independently_verified",
                   "production_readiness"}


class Number(str):
    """Preserve JSON number spelling instead of rounding through binary floats."""


def json_value(raw):
    try:
        return json.loads(raw.decode("utf-8"), object_pairs_hook=profile_tools.pairs,
                          parse_int=Number, parse_float=Number, parse_constant=profile_tools.reject_constant)
    except (UnicodeError, ValueError, RecursionError) as error:
        raise Refusal("invalid-response-json") from error


def normalized_json(value, depth=0):
    need(depth <= 64, "response-json-depth")
    if isinstance(value, Number):
        return str(value)
    if value is None or type(value) is bool or isinstance(value, str):
        return json.dumps(value, ensure_ascii=True, separators=(",", ":"))
    if isinstance(value, list):
        return "[" + ",".join(normalized_json(item, depth + 1) for item in value) + "]"
    need(isinstance(value, dict), "response-json-type")
    return "{" + ",".join(json.dumps(key, ensure_ascii=True) + ":" + normalized_json(value[key], depth + 1)
                          for key in sorted(value)) + "}"


def response_digest(raw):
    return hashlib.sha256(normalized_json(json_value(raw)).encode("ascii")).hexdigest()


def executor_digest():
    return profile_tools.digest({name: hashlib.sha256(Path(__file__).with_name(name).read_bytes()).hexdigest()
                                 for name in ("http_execute.py", "http_profile.py", "run.py")})


def validate_input(profile, schedule, value):
    keys(value, {"version", "profile_sha256", "schedule_sha256", "engine", "round", "database_identity",
                 "server_kind", "socket", "run_token", "fixture_manifest", "response_contract", "synthetic_authorization"}, "executor-input-fields")
    integer(value["version"], 1, 1, "executor-input-version")
    need(value["profile_sha256"] == schedule["profile_sha256"] and
         value["schedule_sha256"] == schedule["schedule_sha256"], "executor-input-binding")
    need(value["engine"] in ("sqlite", "mysql"), "executor-engine")
    integer(value["round"], 1, 2, "executor-round")
    identity = profile["source_snapshot_sha256"] if value["engine"] == "sqlite" else profile["mysql_server_uuid"]
    need(value["database_identity"] == identity, "executor-database-identity")
    need(value["server_kind"] in ("inert-http-fixture", "reviewed-http-instrumentation"), "server-kind")
    profile_tools.sha(value["run_token"], "run-token")
    peer = value["socket"]
    keys(peer, {"path", "device", "inode", "uid", "pid"}, "socket-fields")
    need(isinstance(peer["path"], str) and re.fullmatch(r"/[A-Za-z0-9_./-]+", peer["path"]) and
         len(os.fsencode(peer["path"])) <= 100, "socket-path")
    for name in ("device", "inode", "uid", "pid"):
        integer(peer[name], 1 if name in ("inode", "pid") else 0, 2**63 - 1, "socket-identity")
    need(peer["uid"] == os.geteuid() and peer["uid"] != 0, "same-unprivileged-peer-required")
    manifest = value["fixture_manifest"]
    keys(manifest, {"version", "seed_receipt_sha256", "routes"}, "fixture-manifest-fields")
    integer(manifest["version"], 1, 1, "fixture-manifest-version")
    profile_tools.sha(manifest["seed_receipt_sha256"], "fixture-seed-digest")
    need(profile_tools.digest(manifest) == profile["fixture_manifest_sha256"], "fixture-manifest-binding")
    routes = {route["label"]: route for route in profile["routes"]}
    keys(manifest["routes"], routes, "fixture-route-set")
    for label, route in routes.items():
        bound = manifest["routes"][label]
        keys(bound, {"role", "fixture", "method", "path", "query"}, "fixture-route-fields")
        need(all(bound[key] == route[key] for key in ("role", "fixture", "method")), "fixture-route-binding")
        template = re.escape(route["path_template"])
        template = re.sub(r"\\\{[a-z][a-z0-9_]*\\\}", r"[A-Za-z0-9_-]{1,64}", template)
        need(isinstance(bound["path"], str) and re.fullmatch(template, bound["path"]), "fixture-path-binding")
        query = bound["query"]
        need(isinstance(query, dict) and len(query) <= 16, "fixture-query")
        for key, item in query.items():
            need(isinstance(key, str) and re.fullmatch(r"[a-z][a-z0-9_]{0,31}", key) and
                 isinstance(item, str) and re.fullmatch(r"[A-Za-z0-9_.:-]{1,128}", item), "fixture-query-value")
    contract = value["response_contract"]
    keys(contract, {"version", "routes"}, "response-contract-fields")
    integer(contract["version"], 1, 1, "response-contract-version")
    keys(contract["routes"], routes, "response-contract-route-set")
    need(all(rule == {"kind": "exact-json-numeric-lexemes-v1"} for rule in contract["routes"].values()),
         "unsupported-response-normalization")
    need(profile_tools.digest(contract) == profile["response_contract_sha256"], "response-contract-binding")
    roles = {route["role"] for route in routes.values()}
    keys(value["synthetic_authorization"], roles, "synthetic-authorization-roles")
    need(all(isinstance(token, str) and re.fullmatch(r"Bearer repro-fixture-[A-Za-z0-9_-]{32,128}", token)
             for token in value["synthetic_authorization"].values()), "synthetic-authorization-required")


def private_parent(path):
    need(path != Path("/var/www") and Path("/var/www") not in path.parents, "live-webroot-refused")
    need(path.is_absolute() and path.parent.resolve(strict=True) == path.parent, "canonical-private-parent")
    parent = path.parent.stat()
    need(stat.S_ISDIR(parent.st_mode) and parent.st_uid == os.geteuid() and
         stat.S_IMODE(parent.st_mode) == 0o700, "private-owned-parent-required")
    # An untrusted writable ancestor could replace the private parent itself.
    # Root-owned sticky temp directories are the one explicit fixture exception.
    for ancestor in path.parent.parents:
        item = ancestor.lstat()
        need(stat.S_ISDIR(item.st_mode) and item.st_uid in (0, os.geteuid()), "untrusted-path-ancestor")
        writable = item.st_mode & 0o022
        need(not writable or (item.st_uid == 0 and bool(item.st_mode & stat.S_ISVTX)), "writable-path-ancestor")


def check_socket(peer):
    path = Path(peer["path"])
    private_parent(path)
    info = path.lstat()
    need(stat.S_ISSOCK(info.st_mode) and stat.S_IMODE(info.st_mode) == 0o600 and
         (info.st_dev, info.st_ino, info.st_uid) == (peer["device"], peer["inode"], peer["uid"]), "socket-identity-or-mode")


class ActiveSockets:
    def __init__(self):
        self.stopped = threading.Event()
        self.lock = threading.Lock()
        self.sockets = set()

    def add(self, stream):
        with self.lock:
            need(not self.stopped.is_set(), "round-cancelled")
            self.sockets.add(stream)

    def remove(self, stream):
        with self.lock:
            self.sockets.discard(stream)

    def cancel(self):
        self.stopped.set()
        with self.lock:
            streams = list(self.sockets)
        for stream in streams:
            try:
                stream.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass
            stream.close()


def remaining(deadline):
    seconds = (deadline - time.monotonic_ns()) / 1_000_000_000
    need(seconds > 0, "http-deadline")
    return seconds


def receive(stream, deadline, count):
    stream.settimeout(remaining(deadline))
    chunk = stream.recv(count)
    remaining(deadline)
    return chunk


def parse_headers(raw):
    try:
        lines = raw.decode("ascii").split("\r\n")
    except UnicodeError as error:
        raise Refusal("http-header-encoding") from error
    match = re.fullmatch(r"HTTP/1\.[01] ([2-5][0-9]{2})(?: [\x20-\x7e]*)?", lines[0])
    need(match is not None, "http-status-line")
    status_code = int(match.group(1))
    need(not 300 <= status_code < 400, "http-redirect-refused")
    need(len(lines) <= 49, "http-header-count")
    headers = {}
    for line in lines[1:]:
        need(bool(line) and not line[0].isspace() and ":" in line, "http-header-line")
        name, value = line.split(":", 1)
        need(re.fullmatch(r"[!#$%&'*+.^_`|~0-9A-Za-z-]+", name), "http-header-name")
        name = name.lower()
        value = value.strip(" ")
        need(all(32 <= ord(char) <= 126 for char in value), "http-header-value")
        need(name not in headers, "duplicate-http-header")
        need(name not in HOP_HEADERS or (name == "connection" and value.lower() == "close"), "forbidden-http-header")
        headers[name] = value
    need(headers.get("content-type", "").lower() in ("application/json", "application/json; charset=utf-8"), "json-content-type-required")
    need(headers.get("content-encoding", "identity").lower() == "identity", "content-encoding-refused")
    return status_code, headers


def get_response(stream, deadline):
    buffered = bytearray()
    while b"\r\n\r\n" not in buffered:
        chunk = receive(stream, deadline, 4096)
        need(bool(chunk), "incomplete-http-headers")
        buffered.extend(chunk)
        end = buffered.find(b"\r\n\r\n")
        need((end if end >= 0 else len(buffered)) <= MAX_HEADERS, "http-header-budget")
    raw_headers, body = bytes(buffered).split(b"\r\n\r\n", 1)
    status_code, headers = parse_headers(raw_headers)
    length = headers.get("content-length")
    if length is not None:
        need(re.fullmatch(r"0|[1-9][0-9]{0,7}", length), "http-content-length")
        length = int(length)
        need(length <= MAX_BODY, "http-body-budget")
        need(len(body) <= length, "http-body-length")
    while length is None or len(body) < length:
        chunk = receive(stream, deadline, min(65536, MAX_BODY + 1 - len(body)))
        if not chunk:
            need(length is None or len(body) == length, "incomplete-http-body")
            break
        body += chunk
        need(len(body) <= MAX_BODY and (length is None or len(body) <= length), "http-body-budget")
    return status_code, headers, body, time.monotonic_ns()


def metric_headers(config, schedule, operation, nonce):
    return {
        "x-repro-run-token": config["run_token"], "x-repro-request-nonce": nonce,
        "x-repro-profile-sha256": schedule["profile_sha256"], "x-repro-schedule-sha256": schedule["schedule_sha256"],
        "x-repro-engine": config["engine"], "x-repro-database-identity": config["database_identity"],
        "x-repro-sequence": str(operation["sequence"]), "x-repro-round": str(config["round"]),
        "x-repro-server-kind": config["server_kind"],
    }


def request(profile, schedule, config, operation, origin, active):
    while not active.stopped.is_set():
        wait_seconds = (origin + operation["due_us"] * 1000 - time.monotonic_ns()) / 1_000_000_000
        if wait_seconds <= 0:
            break
        active.stopped.wait(wait_seconds)
    need(not active.stopped.is_set(), "round-cancelled")
    peer = config["socket"]
    check_socket(peer)
    bound = config["fixture_manifest"]["routes"][operation["route"]]
    target = bound["path"]
    if bound["query"]:
        target += "?" + urlencode(sorted(bound["query"].items()))
    expected = metric_headers(config, schedule, operation, os.urandom(16).hex())
    lines = ["GET " + target + " HTTP/1.0", "Host: repro-http-workload.invalid", "Accept: application/json",
             "Accept-Encoding: identity", "Authorization: " + config["synthetic_authorization"][bound["role"]]]
    lines.extend(name + ": " + value for name, value in expected.items())
    wire = ("\r\n".join(lines) + "\r\n\r\n").encode("ascii")
    started = time.monotonic_ns()
    deadline = started + profile["request_timeout_ms"] * 1_000_000
    stream = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    try:
        active.add(stream)
        stream.settimeout(remaining(deadline))
        stream.connect(peer["path"])
        pid, uid, _gid = struct.unpack("3i", stream.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize("3i")))
        need((pid, uid) == (peer["pid"], peer["uid"]), "http-peer-identity")
        check_socket(peer)
        need(not active.stopped.is_set(), "round-cancelled")
        stream.settimeout(remaining(deadline))
        stream.sendall(wire)
        status_code, headers, body, completed = get_response(stream, deadline)
    except (TimeoutError, socket.timeout) as error:
        raise Refusal("http-deadline") from error
    except OSError as error:
        raise Refusal("http-transport-failed") from error
    finally:
        active.remove(stream)
        stream.close()
    need(all(headers.get(name) == value for name, value in expected.items()), "unbound-http-metrics")
    counts = {}
    for name in ("query-count", "database-warning-count"):
        raw = headers.get("x-repro-" + name)
        need(isinstance(raw, str) and re.fullmatch(r"0|[1-9][0-9]{0,6}", raw) and int(raw) <= 1_000_000,
             "missing-or-invalid-http-metrics")
        counts[name] = int(raw)
    good_status = status_code == next(route["expected_status"] for route in profile["routes"] if route["label"] == operation["route"])
    return {"sequence": operation["sequence"], "started_us": (started - origin) // 1000,
            "completed_us": max((started - origin) // 1000 + 1, (completed - origin) // 1000),
            "status": status_code, "outcome": "ok" if good_status else "http_error",
            "query_count": counts["query-count"], "database_warning_count": counts["database-warning-count"],
            "logical_result_sha256": response_digest(body) if good_status else None}


def execute(profile, schedule, config, proof):
    need(schedule == profile_tools.plan(profile), "exact-schedule-required")
    validate_input(profile, schedule, config)
    check_socket(config["socket"])
    pinned = executor_digest()
    active = ActiveSockets()
    pool = ThreadPoolExecutor(max_workers=profile["max_in_flight"])
    futures = []
    samples = []
    origin = time.monotonic_ns()
    try:
        futures = [pool.submit(request, profile, schedule, config, op, origin, active) for op in schedule["operations"]]
        for future in as_completed(futures):
            samples.append(future.result())
    finally:
        active.cancel()
        for future in futures:
            future.cancel()
        pool.shutdown(wait=True, cancel_futures=True)
    need(executor_digest() == pinned, "executor-source-changed")
    samples.sort(key=lambda item: item["sequence"])
    need(len(samples) == len(schedule["operations"]), "incomplete-round")
    need(max(item["completed_us"] for item in samples) <=
         sum(phase["duration_ms"] for phase in profile["phases"]) * 1000 + profile["request_timeout_ms"] * 1000,
         "round-deadline")
    return {"version": 1, "scope": "isolated-http-round", "engine": config["engine"],
            "profile_sha256": schedule["profile_sha256"], "schedule_sha256": schedule["schedule_sha256"],
            "database_identity": config["database_identity"], "executor_sha256": pinned,
            "server_kind": config["server_kind"],
            "run": {"round": config["round"], "engine_order": list(profile_tools.ORDERS[config["round"] - 1]), "samples": samples},
            "isolation": proof, "transport": "http-over-pinned-af-unix",
            "application_execution_verified": False, "database_metrics_independently_verified": False, "production_readiness": False}


def assemble(profile, first, second):
    schedule = profile_tools.plan(profile)
    for number, fragment in enumerate((first, second), 1):
        keys(fragment, FRAGMENT_FIELDS, "fragment-fields")
        need(fragment["version"] == 1 and fragment["scope"] == "isolated-http-round" and
             fragment["transport"] == "http-over-pinned-af-unix" and fragment["run"]["round"] == number,
             "fragment-contract")
        need(fragment["application_execution_verified"] is False and
             fragment["database_metrics_independently_verified"] is False and fragment["production_readiness"] is False,
             "unsupported-fragment-claim")
    for key in ("engine", "profile_sha256", "schedule_sha256", "database_identity", "executor_sha256", "server_kind"):
        need(first[key] == second[key], "fragment-binding")
    result = {key: first[key] for key in ("version", "engine", "profile_sha256", "schedule_sha256", "database_identity", "executor_sha256")}
    result["runs"] = [first["run"], second["run"]]
    need(result["engine"] in ("sqlite", "mysql"), "fragment-engine")
    profile_tools.validate_receipt(profile, schedule, result, result["engine"])
    return result


def save_new(path, value):
    private_parent(path)
    need(not os.path.lexists(path), "fresh-output-required")
    descriptor = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, "wb") as stream:
        stream.write(profile_tools.canonical(value) + b"\n")
        stream.flush()
        os.fsync(stream.fileno())
    directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="action", required=True)
    run = sub.add_parser("run")
    run.add_argument("--profile", type=Path, required=True)
    run.add_argument("--schedule", type=Path, required=True)
    run.add_argument("--supervised-parent-net", required=True)
    run.add_argument("--output", type=Path, required=True)
    merge = sub.add_parser("assemble")
    merge.add_argument("--profile", type=Path, required=True)
    merge.add_argument("--round-one", type=Path, required=True)
    merge.add_argument("--round-two", type=Path, required=True)
    merge.add_argument("--output", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        need(sys.platform == "linux", "linux-required")
        os.umask(0o077)
        if args.action == "run":
            # These checks precede stdin and credentials. The baseline must be
            # independently observed by the reviewed external supervisor.
            proof = isolation.namespace_check(args.supervised_parent_net)
            proof.update(isolation.supervised_status_check())
        private_parent(args.output)
        need(not os.path.lexists(args.output), "fresh-output-required")
        profile = profile_tools.load(args.profile)
        if args.action == "run":
            schedule = profile_tools.load(args.schedule)
            raw = sys.stdin.buffer.read(MAX_INPUT + 1)
            need(0 < len(raw) <= MAX_INPUT, "executor-input-size")
            config = json.loads(raw, object_pairs_hook=profile_tools.pairs, parse_constant=profile_tools.reject_constant)
            result = execute(profile, schedule, config, proof)
        else:
            result = assemble(profile, profile_tools.load(args.round_one), profile_tools.load(args.round_two))
        save_new(args.output, result)
        print(json.dumps({"result": "recorded", "action": args.action, "report_sha256": profile_tools.digest(result),
                          "production_readiness": False, "application_execution_verified": False}))
        return 0
    except (Refusal, isolation.Refusal) as error:
        reason = str(error)
    except KeyboardInterrupt:
        reason = "execution-interrupted"
    except (OSError, ValueError, TypeError, KeyError, RecursionError, OverflowError):
        reason = "invalid-input-or-local-execution"
    print(json.dumps({"result": "refused", "reason": reason, "production_readiness": False}))
    return 2


if __name__ == "__main__":
    def interrupted(_signal, _frame):
        raise Refusal("execution-interrupted")
    signal.signal(signal.SIGTERM, interrupted)
    sys.exit(main())
