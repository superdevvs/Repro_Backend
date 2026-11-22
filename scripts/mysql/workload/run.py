"""Bounded, unprivileged synthetic Laravel smoke; never a production launcher.

Credentials arrive on stdin and cross only anonymous pipes. Linux user/network
namespaces are mandatory. There is deliberately no host-network fallback.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import selectors
import signal
import socket
import stat
import subprocess
import sys
import time

DATABASE = "repro_mysql84_acceptance"
ACCOUNTS = {role: "repro_accept_" + role for role in ("admin", "worker", "reader")}
MAX_INPUT = 16384
MAX_OUTPUT = 131072
TIMEOUT = 120
CACHE_NAMES = {
    "APP_CONFIG_CACHE": "config.php", "APP_SERVICES_CACHE": "services.php",
    "APP_PACKAGES_CACHE": "packages.php", "APP_ROUTES_CACHE": "routes.php",
    "APP_EVENTS_CACHE": "events.php",
}


class Refusal(RuntimeError):
    pass


def need(condition, reason):
    if not condition:
        raise Refusal(reason)


def unique_pairs(pairs):
    result = {}
    for key, value in pairs:
        need(key not in result, "duplicate-json-key")
        result[key] = value
    return result


def credentials(raw):
    need(isinstance(raw, bytes) and 0 < len(raw) <= MAX_INPUT, "credential-input-size")
    try:
        value = json.loads(raw, object_pairs_hook=unique_pairs)
    except (ValueError, UnicodeError) as error:
        raise Refusal("credential-input-json") from error
    need(isinstance(value, dict) and set(value) == {"version", "server_uuid", "socket", "database", "accounts"},
         "credential-input-fields")
    need(type(value["version"]) is int and value["version"] == 1, "credential-input-version")
    need(isinstance(value["server_uuid"], str) and
         re.fullmatch(r"[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}", value["server_uuid"]), "target-uuid")
    need(isinstance(value["socket"], str) and
         re.fullmatch(r"/[A-Za-z0-9_./-]+", value["socket"]) and
         all(part not in (".", "..") for part in value["socket"].split("/")[1:]), "target-socket")
    need(value["database"] == DATABASE, "target-database")
    need(isinstance(value["accounts"], dict) and set(value["accounts"]) == set(ACCOUNTS), "target-accounts")
    for role, expected in ACCOUNTS.items():
        account = value["accounts"][role]
        need(isinstance(account, dict) and set(account) == {"username", "password"} and
             account["username"] == expected, "target-account-" + role)
        password = account["password"]
        need(isinstance(password, str) and 16 <= len(password) <= 1024 and
             all(32 <= ord(char) <= 126 for char in password), "credential-shape-" + role)
    return value


def inside(path, parent):
    return path == parent or parent in path.parents


def validate_source(app):
    need(app.is_absolute() and app.resolve() == app and app.is_dir(), "canonical-source-required")
    need(not inside(app, Path("/var/www")), "live-webroot-refused")
    for name in (".env", ".env.production", "bootstrap/cache/config.php"):
        need(not os.path.lexists(app / name), "source-environment-or-config-cache-present")
    for name in ("bootstrap/app.php", "vendor/autoload.php", "composer.lock"):
        path = app / name
        need(path.is_file() and inside(path.resolve(), app), "self-contained-source-required")


def source_digest(app):
    paths = [app / "composer.lock", app / "composer.json", app / "vendor/composer/installed.json"]
    for directory in ("app", "bootstrap", "config", "routes"):
        paths.extend((app / directory).rglob("*.php"))
    digest = hashlib.sha256()
    for path in sorted(set(paths)):
        need(path.is_file() and inside(path.resolve(), app), "source-file-outside-checkout")
        digest.update(path.relative_to(app).as_posix().encode() + b"\0")
        digest.update(hashlib.sha256(path.read_bytes()).digest())
    return digest.hexdigest()


def prepare_runtime(runtime, app):
    need(runtime.is_absolute() and runtime.parent.resolve() == runtime.parent, "canonical-runtime-parent-required")
    need(not inside(runtime, app) and not inside(app, runtime), "runtime-must-be-outside-source")
    need(not inside(runtime, Path("/var/www")), "live-runtime-refused")
    parent = runtime.parent.stat()
    need(stat.S_ISDIR(parent.st_mode) and parent.st_uid == os.getuid() and
         stat.S_IMODE(parent.st_mode) == 0o700, "private-owned-runtime-parent-required")
    need(not os.path.lexists(runtime), "fresh-runtime-required")
    runtime.mkdir(mode=0o700)
    for name in ("cache", "tmp", "storage/logs", "storage/app/private", "storage/framework/cache/data",
                 "storage/framework/sessions", "storage/framework/views"):
        (runtime / name).mkdir(mode=0o700, parents=True, exist_ok=True)


def clean_environment(runtime):
    # Never inherit credentials, proxy variables, PHPRC, a production APP_KEY or
    # Laravel cache paths. Database passwords are supplied directly to PDO later.
    result = {
        "PATH": "/usr/bin:/bin", "HOME": str(runtime), "TMPDIR": str(runtime / "tmp"),
        "LANG": "C.UTF-8", "APP_ENV": "testing", "APP_DEBUG": "false",
        "APP_URL": "http://repro-workload.invalid", "DB_CONNECTION": "mysql",
        "CACHE_STORE": "array", "SESSION_DRIVER": "array", "MAIL_MAILER": "array",
        "QUEUE_CONNECTION": "database", "FILESYSTEM_DISK": "local",
        "LOG_CHANNEL": "single", "SERVER_MONITOR_ENABLED": "false",
        "CLAMAV_SCAN_ON_UPLOAD": "false",
    }
    result.update({key: str(runtime / "cache" / name) for key, name in CACHE_NAMES.items()})
    return result


def namespace_check(parent_net, parent_user=None, proc=Path("/proc")):
    need(isinstance(parent_net, str) and re.fullmatch(r"net:\[[0-9]+\]", parent_net), "valid-parent-network-namespace-required")
    current_net = os.readlink(proc / "self/ns/net")
    current_user = os.readlink(proc / "self/ns/user")
    need(current_net != parent_net, "new-network-namespace-required")
    if parent_user is not None:
        need(isinstance(parent_user, str) and re.fullmatch(r"user:\[[0-9]+\]", parent_user) and
             current_user != parent_user, "new-user-namespace-required")
    interfaces = {line.split(":", 1)[0].strip() for line in (proc / "net/dev").read_text().splitlines()[2:] if ":" in line}
    need(interfaces <= {"lo"}, "network-interface-present")
    need(len((proc / "net/route").read_text().strip().splitlines()) <= 1, "network-route-present")
    # UDP connect performs a route lookup without sending a datagram. No public
    # service is contacted even when a wrongly configured namespace is refused.
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as probe:
        try:
            probe.connect(("192.0.2.1", 9))
        except OSError:
            pass
        else:
            raise Refusal("outbound-route-available")
    return {"new_network_namespace": True, "new_user_namespace": parent_user is not None, "outbound_route_available": False}


def supervised_status_check(proc=Path("/proc")):
    need(os.geteuid() != 0, "supervised-host-user-must-be-unprivileged")
    status = dict(line.split(":", 1) for line in (proc / "self/status").read_text().splitlines() if ":" in line)
    need(status.get("NoNewPrivs", "").strip() == "1", "supervisor-must-disable-new-privileges")
    for field in ("CapEff", "CapPrm", "CapInh", "CapAmb", "CapBnd"):
        value = status.get(field, "").strip()
        need(bool(re.fullmatch(r"[0-9a-fA-F]+", value)) and int(value, 16) == 0, "supervisor-capabilities-" + field)
    uids = status.get("Uid", "").split()
    need(len(uids) == 4 and all(value == str(os.geteuid()) for value in uids), "supervisor-uid-mismatch")
    return {"unprivileged_uid": os.geteuid(), "no_new_privileges": True, "capability_sets_empty": True}


def collect_child_output(child, raw, timeout):
    """Bound stdin time and both output pipes before accumulating their bytes."""
    deadline = time.monotonic() + timeout
    output = bytearray()
    total = 0
    written = 0
    with selectors.DefaultSelector() as selector:
        for stream in (child.stdout, child.stderr):
            os.set_blocking(stream.fileno(), False)
            selector.register(stream, selectors.EVENT_READ)
        if raw:
            os.set_blocking(child.stdin.fileno(), False)
            selector.register(child.stdin, selectors.EVENT_WRITE)
        else:
            child.stdin.close()
        while selector.get_map():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise subprocess.TimeoutExpired("isolated-workload", timeout)
            for key, event in selector.select(min(remaining, 1)):
                stream = key.fileobj
                if event & selectors.EVENT_WRITE:
                    try:
                        written += os.write(stream.fileno(), memoryview(raw)[written:])
                    except BrokenPipeError:
                        written = len(raw)
                    if written == len(raw):
                        selector.unregister(stream)
                        stream.close()
                else:
                    chunk = os.read(stream.fileno(), 16384)
                    if not chunk:
                        selector.unregister(stream)
                        stream.close()
                        continue
                    total += len(chunk)
                    need(total <= MAX_OUTPUT, "child-output-too-large")
                    if stream is child.stdout:
                        output.extend(chunk)
        child.wait(timeout=max(0.01, deadline - time.monotonic()))
    return bytes(output)


def bounded_process(argv, raw, env, cwd, *, own_session=True):
    child = subprocess.Popen(argv, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                             env=env, cwd=cwd, start_new_session=own_session)
    try:
        stdout = collect_child_output(child, raw, TIMEOUT)
    except BaseException:
        # Kill only the new session this invocation owns, including descendants.
        if own_session:
            os.killpg(child.pid, signal.SIGKILL)
        else:
            child.kill()
        child.wait(timeout=10)
        raise
    finally:
        if own_session:
            try:
                os.killpg(child.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
        for stream in (child.stdin, child.stdout, child.stderr):
            stream.close()
    need(len(stdout) <= MAX_OUTPUT, "child-output-too-large")
    try:
        result = json.loads(stdout, object_pairs_hook=unique_pairs)
    except (ValueError, UnicodeError) as error:
        raise Refusal("child-did-not-return-a-private-result") from error
    need(isinstance(result, dict) and result.get("scope") == "synthetic-laravel-smoke", "child-result-scope")
    phase = str(result.get("phase", "unknown"))
    reason = str(result.get("reason", "unknown"))
    safe_detail = phase + ":" + reason
    if not re.fullmatch(r"[A-Za-z0-9_.:\\-]{1,200}", safe_detail):
        safe_detail = "private-child-error"
    need(child.returncode == 0 and result.get("result") == "passed", "child-refused-" + safe_detail)
    return result


def save_report(runtime, report):
    with (runtime / "result.json").open("x", encoding="utf-8") as stream:
        json.dump(report, stream, sort_keys=True)
        stream.write("\n")
        stream.flush()
        os.fsync(stream.fileno())


def php_command(args):
    command = [str(args.php), "-d", "display_errors=0", "-d", "log_errors=0", "-d", "memory_limit=256M",
               "-d", "auto_prepend_file=", "-d", "auto_append_file=", "-d", "opcache.enable_cli=0",
               "-d", "max_execution_time=90"]
    if args.mode == "isolation":
        # A credential-free prerequisite probe; it does not bootstrap Laravel.
        command += ["-r", "$ok=extension_loaded('pdo_mysql')&&PHP_VERSION_ID>=80300;"
                    "echo json_encode(['scope'=>'synthetic-laravel-smoke','result'=>$ok?'passed':'refused',"
                    "'phase'=>'php-runtime','mode'=>'isolation','php_version'=>PHP_VERSION,"
                    "'database_contacted'=>false]);exit($ok?0:1);"]
    else:
        command += [str(Path(__file__).with_name("smoke.php")), str(args.app), str(args.runtime), args.mode]
    return command


def run_php(args, raw, parent_net, *, own_session=False):
    env = clean_environment(args.runtime)
    env["REPRO_WORKLOAD_PARENT_NETWORK_NAMESPACE"] = parent_net
    return bounded_process(php_command(args), raw, env, args.runtime, own_session=own_session)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--app", type=Path, required=True)
    parser.add_argument("--runtime", type=Path, required=True)
    parser.add_argument("--php", type=Path, default=Path("/usr/bin/php"))
    parser.add_argument("--mode", choices=("isolation", "check", "smoke"), default="check")
    parser.add_argument("--supervised-parent-net", help="Verified network namespace of the trusted supervisor before isolation")
    parser.add_argument("--isolated", action="store_true", help=argparse.SUPPRESS)
    parser.add_argument("--parent-net", help=argparse.SUPPRESS)
    parser.add_argument("--parent-user", help=argparse.SUPPRESS)
    args = parser.parse_args(argv)
    os.umask(0o077)
    need(sys.platform == "linux", "linux-namespace-runtime-required")
    raw = b"" if args.mode == "isolation" else sys.stdin.buffer.read(MAX_INPUT + 1)
    config = None if args.mode == "isolation" else credentials(raw)
    validate_source(args.app)
    need(args.php.is_absolute() and args.php.resolve().is_file(), "explicit-php-binary-required")
    need(args.runtime.is_absolute(), "absolute-runtime-required")
    if args.isolated:
        need(args.supervised_parent_net is None, "incompatible-isolation-modes")
        need(args.parent_user is not None, "parent-user-namespace-required")
        isolation = namespace_check(args.parent_net, args.parent_user)
        need(args.runtime.resolve() == args.runtime and args.runtime.is_dir() and
             stat.S_IMODE(args.runtime.stat().st_mode) == 0o700, "private-runtime-required")
        result = run_php(args, raw, args.parent_net)
        isolation["mechanism"] = "unprivileged-user-and-network-namespace"
        result["isolation"] = isolation
        print(json.dumps(result, sort_keys=True))
        return

    need(os.geteuid() != 0, "run-as-unprivileged-host-user")
    if args.supervised_parent_net is not None:
        isolation = namespace_check(args.supervised_parent_net)
        isolation.update(supervised_status_check())
        isolation["mechanism"] = "supervisor-private-network"
    else:
        unshare = Path("/usr/bin/unshare")
        need(unshare.is_file() and unshare.stat().st_uid == 0 and
             not stat.S_IMODE(unshare.stat().st_mode) & 0o022, "trusted-unshare-required")
    if config is not None:
        target_socket = Path(config["socket"])
        need(target_socket.resolve() == target_socket and stat.S_ISSOCK(target_socket.stat().st_mode), "target-socket-missing")
    prepare_runtime(args.runtime, args.app)
    started = time.monotonic()
    digest = source_digest(args.app)
    if args.supervised_parent_net is not None:
        result = run_php(args, raw, args.supervised_parent_net, own_session=True)
        result["isolation"] = isolation
    else:
        result = bounded_process(
            [str(unshare), "--user", "--map-root-user", "--net", "--", sys.executable, "-I", str(Path(__file__).resolve()),
             "--isolated", "--parent-net", os.readlink("/proc/self/ns/net"),
             "--parent-user", os.readlink("/proc/self/ns/user"), "--app", str(args.app),
             "--runtime", str(args.runtime), "--php", str(args.php), "--mode", args.mode],
            raw, clean_environment(args.runtime), args.runtime)
    need(source_digest(args.app) == digest, "source-changed-during-smoke")
    result.update(source_sha256=digest, runner_sha256=hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
                  smoke_sha256=hashlib.sha256(Path(__file__).with_name("smoke.php").read_bytes()).hexdigest(),
                  elapsed_ms=round((time.monotonic() - started) * 1000), production_cutover=False,
                  copied_data_tested=False, http_load_tested=False)
    save_report(args.runtime, result)
    print(json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    def interrupted(_signum, _frame):
        raise Refusal("interrupted")

    for sig in (signal.SIGINT, signal.SIGTERM, getattr(signal, "SIGHUP", signal.SIGTERM)):
        signal.signal(sig, interrupted)
    try:
        main()
    except BaseException as error:
        # Exception messages from PHP, subprocesses and PDO can contain secrets.
        print(json.dumps({"scope": "synthetic-laravel-smoke", "result": "refused", "phase": "launcher",
                          "reason": str(error) if isinstance(error, Refusal) else type(error).__name__,
                          "production_cutover": False}))
        sys.exit(1)
