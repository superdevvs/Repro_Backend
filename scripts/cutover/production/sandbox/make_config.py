"""Write the sandbox controller config: host.json, identity.json, policy and wrappers (design 7.3).

    /usr/bin/python3 -I -S make_config.py --root ROOT --controller DIR \\
        --app-root APP --run-id RUN --mysql-datadir DIR \\
        --smoke-client-id N --smoke-shoot-id N [--deploy-owner maverick] [--web-user www-data] \\
        [--mode drill|cutover] [--mysql-client PATH] [--measured FILE] [--wrappers FILE]

Every file goes to ROOT/binds/etc/repro-cutover-fence/, the sandbox directory
that run-sandbox.sh binds over /etc/repro-cutover-fence, so this never writes
a production path. --controller is the cutover tree the sandbox installs at
/opt/repro-cutover-fence (guard.py or fence/guard.py, runner.py,
production/): its own adapter.load_config and cli.py path checks must accept
host.json.

Rules:
- fixed paths keep their production values; inside the sandbox each lies in a
  directory run-sandbox.sh binds from ROOT (or is the bound boot ID, or the
  host's machine ID, read only);
- all other state (the app copy and its SQLite file, the MySQL datadir,
  deploy locks, Studio pause files) lies inside ROOT;
- host commands call the sandbox copies of the probes, the sandbox app copy
  or sandbox service scripts in ROOT/bin (written by the drill script), never
  systemctl, systemd-run, supervisorctl or service;
- the host.json contract (adapter PATHS, COMMANDS, OPTIONS) is read from the
  controller. A name with no sandbox value here is refused, so a contract
  change fails here until this file is aligned with it.

Exit 2 on any refusal, with nothing written. Standard library only.
"""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
import re
import sys
import uuid
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
import sandbox_check  # noqa: E402

REFUSED = 2
PYTHON = "/usr/bin/python3"
INSTALLED = "/opt/repro-cutover-fence/production"
ETC = "/etc/repro-cutover-fence"
# Fixed paths: the production values, which the sandbox binds. The first ten are the SQLite
# controller's; the rest are the design 3.1 names as D01 defines them.
PATHS = {
    "state": "/var/lib/repro-cutover-fence",
    "policy": f"{ETC}/policy.json",
    "evidence": "/run/repro-cutover-fence/verified-engine.json",
    "activation": "/run/repro-cutover-fence/verified-activation.json",
    "machine_id": "/etc/machine-id",
    "boot_id": "/proc/sys/kernel/random/boot_id",
    "ingress_open": "/run/repro-cutover-ingress/open",
    "deploy_marker": "/etc/repro-database-cutover/active",
    "scheduler_hold": "/run/repro-cutover-fence/scheduler-hold.json",
    "sqlite_seal_record": "/var/lib/repro-cutover-fence/sqlite-seal.json",
    "identity_config": f"{ETC}/identity.json",
    "switch_record": "/var/lib/repro-cutover-fence/switch-record.json",
    "sealed_hash": "/var/lib/repro-cutover-fence/sealed-hash.json",
    "receipt": "/var/lib/repro-cutover-fence/receipt.json",
    "activity": "/var/lib/repro-cutover-fence/activity.json",
    "activated": "/var/lib/repro-cutover-fence/mysql-activated.json",
    "smoke_window": "/var/lib/repro-cutover-fence/smoke-window.json",
    "evidence_inputs": "/var/lib/repro-cutover-fence/evidence-inputs.json",
    "evidence_report": "/var/lib/repro-cutover-fence/evidence-report.json",
    "unseal_proof": "/var/lib/repro-cutover-fence/unseal-proof.json",
    "issued_approvals": "/var/lib/repro-cutover-fence/issued-approvals",
    "scheduler_release": "/run/repro-cutover-fence/scheduler-released",
    "wrappers": f"{ETC}/wrappers.json",
    "wrappers_sqlite": f"{ETC}/wrappers.sqlite.json",
    "wrappers_mysql": f"{ETC}/wrappers.mysql.json",
    "retired": f"{ETC}/retired.json",
    "archive": "/var/lib/repro-cutover-archive",
    "keep": "/var/lib/repro-cutover-keep",
}
# Read inside the sandbox but not bound: the host's machine ID (read only) and the bound boot ID file.
UNBOUND = {"/etc/machine-id", "/proc/sys/kernel/random/boot_id"}
OPTIONS = {"max_drain_seconds": 600, "separate_freeze_approval": False, "adopt_external_hold": False,
           "require_long_job_predrain": True, "unattended_reopen": False}
QUEUES = ["database:mail", "database:integrations", "database:default", "database:watermarks",
          "database:media-archives", "database:voice-realtime", "database:voice-notifications",
          "database:voice-transcripts", "studio:studio"]
NIGHT_FLAGS = ["CRM_OUTBOUND_HOLD", "CRM_DEFER_REPLY_FORWARDING", "CRM_CALENDAR_SYNC_HOLD",
               "PRODUCER_INGRESS_HOLD", "SCHEDULER_HOLD_ENABLED"]
SERVICE_MANAGERS = ("systemctl", "systemd-run", "supervisorctl", "/usr/sbin/service")
# The production app tree and user homes: a sandbox profile must use the sandbox copy instead.
PRODUCTION_TREES = ("/var/www/", "/home/")
IDENTITY_FIELDS = ("run_id", "release_sha", "artifact_sha256", "source_sha256", "config_sha256", "target_uuid")
UUID = r"[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}"


class Refused(RuntimeError):
    pass


def require(condition: bool, message: str) -> None:
    if not condition:
        raise Refused(message)


def _load(name: str, path: Path):
    require(path.is_file(), f"The controller has no {path}")
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


def _inside(path: Path, root: Path, what: str) -> Path:
    require(path.is_absolute() and os.path.realpath(path) == str(path)
            and sandbox_check._inside_tree(str(path), str(root)) and path != root,
            f"{what} {path} must lie inside the sandbox root {root} (no symbolic links)")
    return path


def _server_uuid(datadir: Path) -> str:
    try:
        text = (datadir / "auto.cnf").read_text(encoding="ascii")
    except OSError:
        raise Refused(f"{datadir}/auto.cnf is unreadable; initialise the sandbox MySQL first") from None
    found = re.findall(rf"^server-uuid\s*=\s*({UUID})\s*$", text, re.MULTILINE)
    require(len(found) == 1, f"{datadir}/auto.cnf has no single server-uuid")
    return found[0]


def _commands(args, app: Path, root: Path) -> dict:
    script = lambda name: str(root / "bin" / name)  # noqa: E731 - sandbox service scripts (drill script)
    queue = lambda verb: ["/usr/sbin/runuser", "-u", args.web_user, "--", "/bin/sh", "-c",  # noqa: E731
                          f"cd {app} && umask 0022 && exec /usr/bin/php artisan queue:{verb} \"$1\"", "repro-queue"]
    smoke = lambda user: ["/usr/sbin/runuser", "-u", user, "--", "/usr/bin/php", f"{app}/artisan",  # noqa: E731
                          "cutover:smoke", "--read-only", "--json"]
    return {
        "identity": [PYTHON, "-I", "-S", f"{INSTALLED}/identity_probe.py", "--config", f"{ETC}/identity.json"],
        "mysql": [PYTHON, "-I", "-S", f"{INSTALLED}/mysql_probe.py", "--config", f"{ETC}/host.json"],
        "queue_pause": queue("pause"),
        "queue_resume": queue("resume"),
        "stop_workers": [[script("stop-workers")]],
        "start_workers": [[script("start-workers")]],
        "drain_fpm": [[PYTHON, "-I", "-S", f"{INSTALLED}/fpm_drain.py", "--config", f"{ETC}/host.json"],
                      [script("fpm-stop")]],
        # Design 3.1 names (D01: single argument lists, smoke_readonly a list of them).
        "config_build": [PYTHON, "-I", "-S", f"{INSTALLED}/config_build.py", "--config", f"{ETC}/host.json"],
        "fpm_start": [script("fpm-start")],
        "fpm_reload": [script("fpm-reload")],
        "fpm_stop": [script("fpm-stop")],
        "smoke_readonly": [smoke(args.web_user), smoke(args.deploy_owner)],
        "smoke_write": [PYTHON, "-I", "-S", f"{INSTALLED}/admit.py", "cutover-smoke"],
        "telnyx_replay": [PYTHON, "-I", "-S", f"{INSTALLED}/admit.py", "telnyx-replay"],
        "timer": [script("timer")],
    }


def _contract(table: dict, names, kind: str) -> dict:
    unknown = sorted(set(names) - table.keys())
    require(not unknown, f"make_config.py has no sandbox value for {kind} {', '.join(map(repr, unknown))}; "
                         "align it with the controller's host.json contract")
    return {name: table[name] for name in sorted(names)}


def host_config(args, root: Path, app: Path, datadir: Path, server_uuid: str, adapter) -> dict:
    socket = "/run/repro-mysql84-native/mysql.sock"
    switch = {"DB_CONNECTION": "mysql", "DB_DATABASE": "repro_dashboard", "DB_SOCKET": socket,
              "DB_USERNAME": "repro_app", "DB_COLLATION": "utf8mb4_0900_bin",
              "REPRO_MYSQL_EXPECTED_SERVER_UUID": server_uuid}
    return {
        "version": 1,
        "paths": _contract(PATHS, adapter.PATHS, "path"),
        "commands": _contract(_commands(args, app, root), adapter.COMMANDS, "command"),
        "options": _contract(OPTIONS, adapter.OPTIONS, "option"),
        "sqlite": str(app / "database" / "database.sqlite"),
        "deploy_locks": [str(root / "locks" / ".repro-backend-deploy.lock"),
                         str(root / "locks" / ".repro-frontend-deploy.lock")],
        "deploy_owner": args.deploy_owner,
        "queues": QUEUES,
        # The sandbox's PID namespace shows only sandbox processes.
        "writer_processes": [f"{app}/artisan queue:work", f"{app}/artisan schedule:run"],
        "long_job_processes": ["--queue=media-archives"],
        "scheduler_processes": [f"{app}/artisan schedule:run"],
        "command_seconds": 300,
        # Design 3.1 blocks (D01).
        "app_root": str(app), "app_owner": args.deploy_owner, "app_group": "www-data", "web_user": args.web_user,
        "mysql": {
            "mode": args.mode, "database": "repro_dashboard", "control_database": "repro_mysql_control",
            "target_uuid": server_uuid, "datadir": str(datadir), "client": args.mysql_client,
            # /run is private in the sandbox: the sandbox MySQL listens at the production socket path.
            "socket": socket,
            "probe_defaults": f"{ETC}/mysql-probe.cnf", "gate_defaults": f"{ETC}/mysql-gate.cnf",
            "token_check_defaults": f"{ETC}/mysql-token-check.cnf",
            "gated_accounts": ["repro_app@localhost"],
            "lockable_accounts": ["repro_maint@localhost", "repro_migrator@localhost"],
            "activity_excluded_tables": ["repro_recovery_heartbeat"],
            # The tables the write smoke changes (CutoverSmokeFixture::SMOKE_TABLES); the app has no clients
            # table, the test client is a users row (review B1).
            "smoke_tables": ["users", "shoots", "aryeo_changes", "jobs"],
            "switch": switch,
            "switch_secrets": {"DB_PASSWORD": f"{ETC}/mysql-app.env"},
            "night_flags": NIGHT_FLAGS,
            # The sandbox network has only loopback, so off-site uploads cannot run there.
            "offsite_waiver": args.mode == "drill",
        },
        "fpm": {"unit": "php8.3-fpm.service", "socket": "/run/php/php8.3-fpm.sock",
                "status_socket": "/run/php/php8.3-fpm-status.sock", "drain_seconds": 90},
        "smoke": {"client_id": args.smoke_client_id, "shoot_id": args.smoke_shoot_id},
        "studio_pause_files": [str(root / "studio" / "paused"), str(root / "studio" / "paused-lab-tour")],
    }


def check_sandbox_rules(config: dict, root: Path) -> None:
    bound = tuple(sandbox_check.DIRECTORIES)
    for name, value in config["paths"].items():
        require(value in UNBOUND or any(sandbox_check._inside_tree(value, directory) for directory in bound),
                f"Path {name} ({value}) is not inside a directory the sandbox binds")
    for value in [config["sqlite"], *config["deploy_locks"], *config["studio_pause_files"],
                  config["app_root"], config["mysql"]["datadir"]]:
        require(sandbox_check._inside_tree(value, str(root)), f"{value} must lie inside the sandbox root {root}")
    commands = []
    for value in config["commands"].values():
        commands.extend(value if value and isinstance(value[0], list) else [value])
    for argv in commands:
        require(argv and argv[0].startswith("/"), f"Command {argv} must be an absolute argument list")
        for part in argv:
            for manager in SERVICE_MANAGERS:
                require(manager not in part, f"Command {argv} calls {manager}, which the sandbox refuses")


def identity_config(config: dict, run_id: str) -> dict:
    mysql = config["mysql"]
    denylist = sorted(set(mysql["night_flags"]) | set(mysql["switch"]) | set(mysql["switch_secrets"]))
    return {"version": 1, "run_id": run_id, "app_root": config["app_root"], "sqlite_path": config["sqlite"],
            "mysql_datadir": mysql["datadir"], "env_denylist": denylist}


def policy(measured_path: Path, run_id: str, server_uuid: str) -> dict:
    measured = json.loads(measured_path.read_text(encoding="utf-8"))
    identity = measured.get("identity") if isinstance(measured, dict) else None
    require(isinstance(identity, dict) and sorted(identity) == sorted(IDENTITY_FIELDS),
            "The measured identity must have exactly the six identity fields")
    require(identity["run_id"] == run_id, "The measured run_id differs from --run-id")
    require(isinstance(identity["release_sha"], str) and re.fullmatch(r"[0-9a-f]{40}", identity["release_sha"]),
            "The measured release_sha is malformed")
    for name in ("artifact_sha256", "source_sha256", "config_sha256"):
        require(isinstance(identity[name], str) and re.fullmatch(r"[0-9a-f]{64}", identity[name]),
                f"The measured {name} is malformed")
    require(identity["target_uuid"] == server_uuid,
            "The measured target_uuid is not the sandbox MySQL's server-uuid")
    pins = {}
    for name in ("sqlite_live_config_sha256", "mysql_live_config_sha256"):
        value = measured.get(name)
        require(isinstance(value, str) and re.fullmatch(r"[0-9a-f]{64}", value), f"The measured {name} is malformed")
        pins[name] = value
    machine = hashlib.sha256(Path("/etc/machine-id").read_bytes()).hexdigest()
    return {"version": 1, "machine_id_sha256": machine, "identity": {key: identity[key] for key in IDENTITY_FIELDS},
            **pins}


def wrappers(template_path: Path, app: Path, admit) -> dict[str, dict]:
    template = json.loads(template_path.read_text(encoding="utf-8"))
    profiles = template.get("profiles") if isinstance(template, dict) else None
    require(template.get("version") == 1 and isinstance(profiles, dict) and profiles,
            "The wrappers template must be {\"version\": 1, \"profiles\": {...}}")
    for name, profile in profiles.items():
        require(isinstance(profile, dict) and profile.keys() == admit.PROFILE_FIELDS,
                f"Profile {name} fields differ from admit.py's contract")
        texts = [profile["cwd"], *profile["argv"], *profile["env"].values()]
        for text in texts:
            for tree in PRODUCTION_TREES:
                require(tree not in text, f"Profile {name} names the production path {tree} in {text!r}; "
                                          "use the sandbox app copy")
        require(sandbox_check._inside_tree(profile["cwd"], str(app)),
                f"Profile {name} must run in the sandbox app copy {app}")
    files = {}
    for engine in ("sqlite", "mysql"):
        files[f"wrappers.{engine}.json"] = {"version": 1, "profiles": {
            name: {**profile, "engine": engine} for name, profile in sorted(profiles.items())}}
    files["wrappers.json"] = files["wrappers.sqlite.json"]
    return files


def _encode(value: dict) -> bytes:
    return (json.dumps(value, indent=2, sort_keys=True, allow_nan=False) + "\n").encode("utf-8")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--root", required=True, type=Path)
    parser.add_argument("--controller", required=True, type=Path)
    parser.add_argument("--app-root", required=True, type=Path)
    parser.add_argument("--run-id", required=True)
    parser.add_argument("--mysql-datadir", required=True, type=Path)
    parser.add_argument("--smoke-client-id", required=True, type=int)
    parser.add_argument("--smoke-shoot-id", required=True, type=int)
    parser.add_argument("--deploy-owner", default="maverick")
    parser.add_argument("--web-user", default="www-data")
    parser.add_argument("--mode", choices=("drill", "cutover"), default="drill")
    parser.add_argument("--mysql-client", default="/opt/repro-mysql84-native/mysql-8.4.13/bin/mysql")
    parser.add_argument("--measured", type=Path)
    parser.add_argument("--wrappers", type=Path)
    args = parser.parse_args(argv)
    try:
        root = args.root
        require(root.is_absolute() and os.path.realpath(root) == str(root) and root.is_dir(),
                f"The sandbox root {root} must be an existing absolute directory without symbolic links")
        app = _inside(args.app_root, root, "The app copy")
        datadir = _inside(args.mysql_datadir, root, "The MySQL datadir")
        require(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_-]{0,63}", args.run_id) is not None, "Invalid --run-id")
        require(args.smoke_client_id > 0 and args.smoke_shoot_id > 0, "Smoke record IDs must be positive")
        server_uuid = _server_uuid(datadir)
        controller = args.controller.resolve()
        sys.path[:0] = [str(controller / "production"), str(controller)]
        adapter = _load("adapter", controller / "production" / "adapter.py")
        cli = _load("cli", controller / "production" / "cli.py")
        admit = _load("admit", controller / "production" / "admit.py")

        config = host_config(args, root, app, datadir, server_uuid, adapter)
        check_sandbox_rules(config, root)
        identity = _encode(identity_config(config, args.run_id))

        def read(path: Path) -> bytes:
            # The D01 contract reads paths.identity_config through this; it is the file written below.
            require(str(path) == config["paths"].get("identity_config"),
                    f"The controller asked to read {path}, which make_config.py does not write")
            return identity

        # D01's contract only: a controller whose load_config takes no `read` would check nothing of
        # identity.json, and the TypeError below refuses it.
        loaded = adapter.load_config(_encode(config), read=read)
        guard = adapter.guard
        installed = (guard.STATE, guard.POLICY, guard.EVIDENCE, guard.ACTIVATION, guard.MACHINE_ID, guard.BOOT_ID)
        require(all(Path(loaded["paths"][name]) == path for name, path in zip(cli.GUARD_PATHS, installed))
                and Path(loaded["paths"]["ingress_open"]) == cli.INGRESS_OPEN
                and Path(loaded["paths"]["deploy_marker"]) == cli.DEPLOY_MARKER,
                "host.json would be refused by the sandbox controller's fixed-path checks")
        files = {"host.json": config, "identity.json": identity_config(config, args.run_id)}
        if args.measured is not None:
            files["policy.json"] = policy(args.measured, args.run_id, server_uuid)
        if args.wrappers is not None:
            files.update(wrappers(args.wrappers, app, admit))
    except Refused as error:
        print(f"make_config: refused: {error}", file=sys.stderr)
        return REFUSED
    except (OSError, ValueError, KeyError, TypeError, AttributeError) as error:
        print(f"make_config: refused: {type(error).__name__}: {error}", file=sys.stderr)
        return REFUSED
    except RuntimeError as error:  # the controller's load_config raises the guard's Refused
        print(f"make_config: refused: the controller rejected host.json: {error}", file=sys.stderr)
        return REFUSED

    out = sandbox_check.source(root, ETC)
    out.mkdir(mode=0o755, parents=True, exist_ok=True)
    for name in ("locks", "studio"):
        (root / name).mkdir(mode=0o755, exist_ok=True)
    for name, value in files.items():
        temporary = out / f".{name}.{uuid.uuid4().hex}"
        temporary.write_bytes(_encode(value))
        os.chmod(temporary, 0o644)
        os.replace(temporary, out / name)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
