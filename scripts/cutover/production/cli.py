"""Linux entry point for the fenced database drill and its watchdog."""
from __future__ import annotations

import argparse
import json
import os
import stat
import sys
import tempfile
from dataclasses import asdict
from pathlib import Path

sys.path[:0] = [str(Path(__file__).resolve().parent), str(Path(__file__).resolve().parent.parent)]
import adapter as host
import config_build
import gate
import live_config
import retire
import timers
from adapter import guard
from runner import Approval, ControllerBusy, Journal, UnsafeCutover

CONFIG = Path("/etc/repro-cutover-fence/host.json")
RUNTIME = Path("/run/repro-cutover-fence")
INGRESS_OPEN = Path("/run/repro-cutover-ingress/open")
DEPLOY_MARKER = Path("/etc/repro-database-cutover/active")
FIXED_PATHS = {
    "state": guard.STATE, "policy": guard.POLICY, "evidence": guard.EVIDENCE,
    "activation": guard.ACTIVATION, "machine_id": guard.MACHINE_ID, "boot_id": guard.BOOT_ID,
    "scheduler_release": RUNTIME / "scheduler-released", "wrappers": CONFIG.parent / "wrappers.json",
    "retired": CONFIG.parent / "retired.json", "issued_approvals": guard.STATE / "issued-approvals",
    "ingress_open": INGRESS_OPEN, "deploy_marker": DEPLOY_MARKER,
    "archive": retire.ARCHIVE, "keep": retire.KEEP,
}


def arguments(argv):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", type=Path, default=CONFIG)
    commands = parser.add_subparsers(dest="action", required=True)
    commands.add_parser("init").add_argument("--from-held", action="store_true")
    commands.add_parser("draft-policy").add_argument("--run-id", required=True)
    for name in ("tick", "refresh", "release-scheduler", "rehold", "prepare",
                 "pin-config", "gate-check", "gate-normalise", "retired-open"):
        commands.add_parser(name)
    retirement = commands.add_parser("retire")
    retirement.add_argument("--next-policy", type=Path)
    retirement.add_argument("--next-identity", type=Path)
    commands.add_parser("status").add_argument("--evidence", action="store_true")
    commands.add_parser("reopen").add_argument("--hold-scheduler", action="store_true")
    issue = commands.add_parser("approval-template")
    issue.add_argument("--phase", choices=("hold", "freeze", "promote"), required=True)
    for name in ("hold", "freeze", "promote"):
        action = commands.add_parser(name)
        source = action.add_mutually_exclusive_group(required=name != "freeze")
        source.add_argument("--issued-approval", action="store_true")
        if name == "promote":
            action.add_argument("--then-reopen", action="store_true")
        else:
            source.add_argument("--approval", type=Path)
    check = commands.add_parser("check-evidence")
    check.add_argument("--inputs", type=Path, required=True)
    check.add_argument("--write", action="store_true")
    recovery = commands.add_parser("way-back").add_subparsers(dest="recovery_action", required=True)
    recovery.add_parser("close")
    recovery.add_parser("audit")
    install = recovery.add_parser("install-sqlite")
    source = install.add_mutually_exclusive_group(required=True)
    source.add_argument("--file", type=Path)
    source.add_argument("--keep-frozen", action="store_true")
    install.add_argument("--sha256", required=True)
    install.add_argument("--review", type=Path, required=True)
    abort = commands.add_parser("abort")
    abort.add_argument("--if-phase", choices=("ready", "frozen", "holding"))
    abort.add_argument("--if-challenge")
    deadline = commands.add_parser("arm-deadline")
    deadline.add_argument("--minutes", type=int, choices=range(1, 46), required=True)
    deadline.add_argument("--phase", choices=("holding", "frozen", "ready"), required=True)
    args = parser.parse_args(argv)
    if args.action == "abort" and bool(args.if_phase) != bool(args.if_challenge):
        parser.error("--if-phase and --if-challenge must be used together")
    return args


def pin_config(config):
    live = live_config.measure(config["app_root"])
    if live.engine != "sqlite" or live.source != "cache" or not live.live_config_sha256:
        raise UnsafeCutover("pin-config requires a live SQLite configuration cache")
    with tempfile.TemporaryDirectory(dir=RUNTIME, prefix="pin-") as directory:
        os.chmod(directory, 0o711)
        sqlite = config_build.stage(config, parent=directory, expected_engine="sqlite",
                                    expected_sha256=live.live_config_sha256)
        switch = {**config["mysql"]["switch"], **host.switch_secrets(config)}
        mysql = config_build.stage(config, parent=directory, expected_engine="mysql", environment=switch)
        if live_config.measure(config["app_root"]) != live:
            raise UnsafeCutover("The live configuration changed while pinning")
    return {"sqlite_live_config_sha256": sqlite.live_config_sha256,
            "mysql_live_config_sha256": mysql.live_config_sha256}


def hold_preflight(adapter):
    target = adapter._mysql()
    mysql = adapter.config["mysql"]
    if (target.get("reachable") is not True or target.get("gate") != "closed"
            or target.get("server_uuid") != mysql["target_uuid"] or target.get("database") != mysql["database"]
            or target.get("writable") is not False or target.get("business_activity") is not False
            or target.get("foreign_activity") is not False):
        raise UnsafeCutover("The hold requires the expected reachable MySQL target, closed and quiet")


def issue(runner, adapter, phase):
    with runner.journal.acquire():
        state = runner.status()
        if phase == "promote":
            if asdict(adapter.evidence()) != state["evidence"]:
                raise UnsafeCutover("Live evidence differs from the prepared evidence")
        approval = runner.approval_request(phase)
        directory = adapter.paths["issued_approvals"]
        directory.mkdir(mode=0o700, exist_ok=True)
        info = directory.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077:
            raise UnsafeCutover("Issued approvals require a root-private directory")
        path = directory / (phase + ".json")
        if phase == "promote":
            # Withdraw the old token first. Failed re-arming cannot leave an approval without a timer.
            host._remove(path)
            timers.arm(adapter, state["phase"], approval.challenge, purpose="approval",
                       deadline=approval.expires_at, clock=runner.clock)
        host._write_atomic(path, asdict(approval), 0o600)
        return asdict(approval)


def dispatch(args, runner, adapter):
    action = args.action
    if action not in ("init", "status", "retire") and os.path.lexists(runner.journal.directory / retire.NEXT_RUN):
        raise UnsafeCutover("Finish held initialization before issuing another controller action")
    if action == "retire":
        return retire.retire(adapter, args.next_policy, args.next_identity)
    if action == "retired-open":
        return retire.retired_open(adapter)
    if action == "tick":
        recover = getattr(adapter, "recover_interrupted_mysql_reopen", None)
        if recover is not None:
            recover()
    if action == "way-back":
        import wayback
        try:
            if args.recovery_action == "close":
                return wayback.close(adapter)
            if args.recovery_action == "audit":
                return wayback.audit(adapter)
            return wayback.install(adapter, candidate=args.file, digest=args.sha256, review_path=args.review)
        except (wayback.mysql_probe.Refusal, wayback.mysql_probe.Purged,
                wayback.binlog_scan.Unreadable, wayback.binlog_scan.WrongServer):
            raise UnsafeCutover("MySQL recovery evidence is unavailable or its history has expired") from None
    if action == "approval-template":
        return issue(runner, adapter, args.phase)
    if action == "status" and args.evidence:
        state = runner.status()
        raw = guard.read_protected(adapter.paths["evidence_report"])
        report = host.evidence.parse(raw)
        prepared = state.get("evidence")
        if (report["run_id"] != state["identity"]["run_id"] or not isinstance(prepared, dict)
                or prepared.get("report_sha256") != guard.sha256(raw)):
            raise UnsafeCutover("This report is not the evidence recorded by the current run")
        return report
    if action == "check-evidence":
        with runner.journal.acquire():
            state = runner.status()
            if state["pending"] is not None:
                raise UnsafeCutover("Cannot change evidence during an interrupted transition")
            return adapter.check_evidence(args.inputs, write=args.write)
    if action == "arm-deadline":
        with runner.journal.acquire():
            state = runner.status()
            if state["phase"] != args.phase or state["pending"] or state["writes_possible"]:
                raise UnsafeCutover("Timer does not match a stable held phase")
            deadline = min(int(runner.clock()) + 60 * args.minutes, state["started_at"] + runner.DECISION_SECONDS)
            return {"timer": timers.arm(adapter, args.phase, state["challenge"], purpose="deadline",
                                        deadline=deadline, clock=runner.clock)}
    if action == "gate-normalise":
        with runner.journal.acquire():
            state = runner.status()
            if state["pending"] is not None or state["writes_possible"] is not False:
                raise UnsafeCutover("Gate normalization requires a stable pre-write run")
            runner._verify_identity()
            adapter.require_quiescent()
            if gate.read(adapter.config).gate != "partial":
                raise UnsafeCutover("Gate normalization requires a partial gate")
            return gate.close_gate(adapter.config)
    if action == "init":
        if args.from_held:
            import wayback_policy
            state = wayback_policy.initialize_held(runner, adapter)
        else:
            state = retire.initialize(runner, adapter)
    elif action == "status":
        state = runner.status()
    elif action in ("refresh", "rehold", "release-scheduler"):
        getattr(adapter, action.replace("-", "_"))()
        state = runner.status()
    elif action == "reopen":
        # Each implementation verifies its own authority again after taking the controller lock.
        reopen = adapter.reopen_mysql if runner.status()["phase"] == "mysql_authoritative" else adapter.reopen
        reopen(hold_scheduler=args.hold_scheduler)
        state = runner.status()
    elif action == "abort" and args.if_phase:
        try:
            state = runner.perform("abort", abort_if=(args.if_phase, args.if_challenge))
        except ControllerBusy:
            return {"aborted": False, "reason": "controller busy"}
        return {"state": state}
    else:
        if action == "promote" and adapter.config["mysql"].get("mode") != "cutover":
            raise UnsafeCutover("This drill controller cannot promote MySQL")
        reopen_mysql = None
        if action == "promote" and args.then_reopen:
            reopen_mysql = getattr(adapter, "reopen_mysql", None)
            if not callable(reopen_mysql):
                raise UnsafeCutover("This release has no MySQL reopen action; promotion was not started")
            preflight = getattr(adapter, "check_mysql_reopen", None)
            if preflight is not None:
                preflight()
        approval = None
        if action in ("hold", "freeze", "promote"):
            path = adapter.paths["issued_approvals"] / (action + ".json") if args.issued_approval else args.approval
            if path is not None:
                raw = guard.read_protected(path)
                approval = Approval(**json.loads(raw, object_pairs_hook=guard.unique_fields,
                                                 parse_constant=guard.refuse_non_json_constant))
        state = runner.perform(action, approval, hold_preflight=lambda: hold_preflight(adapter))
        if reopen_mysql is not None:
            if state["phase"] != "mysql_authoritative" or state["writes_possible"] is not True or state["pending"]:
                raise UnsafeCutover("MySQL authority was not committed; admissions stay held")
            reopen_mysql()
            state = runner.status()
    return {"state": state, "observation": asdict(adapter.observe())}


def main(argv=None):
    args = arguments(argv)
    try:
        if sys.platform != "linux" or os.geteuid() != 0:
            raise UnsafeCutover("The controller runs as root on Linux")
        config = host.load_config(guard.read_protected(args.config), read=guard.read_protected)
        if any(Path(config["paths"][key]) != path for key, path in FIXED_PATHS.items()):
            raise UnsafeCutover("Host config differs from installed guard paths or marker paths")
        if args.action == "pin-config":
            result = pin_config(config)
        elif args.action == "draft-policy":
            import wayback_policy
            result = wayback_policy.draft(config, args.run_id, pin_config)
        elif args.action == "gate-check":
            result, code, message = gate.check(config)
            print(json.dumps(result, sort_keys=True))
            if message:
                print(message, file=sys.stderr)
            return code
        else:
            journal = Journal(Path(config["paths"]["state"]))
            adapter = host.ProductionAdapter(config, journal)
            result = dispatch(args, host.runner_for(config, journal, adapter), adapter)
    except (UnsafeCutover, guard.Refused, live_config.Unmeasurable, config_build.BuildFailed,
            ValueError, TypeError, KeyError, OSError) as error:
        print(f"Cutover controller refused: {error}", file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
