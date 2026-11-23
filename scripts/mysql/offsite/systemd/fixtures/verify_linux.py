"""Validate units and fixed argv in an externally isolated, non-root Linux fixture.

No systemd manager is started. This cannot prove unit activation or sandboxing.
"""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import time

ROOT = Path(__file__).resolve().parents[1]
VERIFY_ROOT = Path("/tmp/repro-unit-input")
spec = importlib.util.spec_from_file_location("unit_contract", ROOT / "test_units.py")
units = importlib.util.module_from_spec(spec)
spec.loader.exec_module(units)


def command(argv, expected=0, profile="system"):
    process = subprocess.run(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                             stderr=subprocess.PIPE, timeout=30, env={
                                 "PATH": "/usr/bin:/bin", "LANG": "C.UTF-8",
                                 "HOME": "/tmp/repro-unit-home",
                                 "XDG_STATE_HOME": "/tmp/repro-unit-state",
                                 "XDG_RUNTIME_DIR": "/tmp/repro-unit-runtime",
                                 "SYSTEMD_UNIT_PATH": str(VERIFY_ROOT / profile) + ":",
                             })
    if process.returncode != expected:
        raise RuntimeError("public fixture command returned unexpected status for " + profile + " " + str(process.returncode)
                           + ": " + process.stderr.decode("utf-8", "replace")[:4096])
    return process


def main():
    started = time.monotonic()
    if os.geteuid() == 0 or Path("/run/systemd/system").exists():
        raise RuntimeError("requires non-root isolated fixture without a system manager")
    runtime = Path("/tmp/repro-unit-runtime")
    runtime.mkdir(mode=0o700)
    report = {"version": 1, "uid": os.geteuid(), "manager_started": False,
              "provider_accessed": False, "unit_activation_proven": False}
    version = command(["/usr/bin/systemd-analyze", "--version"]).stdout.decode().splitlines()[0]
    report["systemd_version"] = version
    hashes = {}
    VERIFY_ROOT.mkdir(mode=0o700)
    for profile in units.PROFILES:
        # Windows bind mounts expose synthetic 0777 modes. Copy only the public
        # units into Linux tmpfs; compare identical bytes before parser validation.
        staged = VERIFY_ROOT / profile
        staged.mkdir(mode=0o700)
        for name in sorted(units.NAMES):
            raw = (ROOT / profile / name).read_bytes()
            target = staged / name
            target.write_bytes(raw)
            target.chmod(0o644)
            if target.read_bytes() != raw:
                raise RuntimeError("unit staging bytes differ")
        paths = sorted(staged.glob("*.service")) + sorted(staged.glob("*.timer"))
        argv = ["/usr/bin/systemd-analyze"]
        if profile == "user-fixture":
            argv += ["--user"]
        argv += ["verify"] + [str(p) for p in paths]
        # Exit zero alone is insufficient: systemd may ignore unknown directives.
        result = command(argv, profile=profile)
        if result.stdout or result.stderr:
            # Only these public unit files are inputs; no plan or secret is read by verify.
            diagnostic = (result.stdout + result.stderr).decode("utf-8", "replace")[:4096]
            raise RuntimeError("systemd verification emitted diagnostics for " + profile + ": " + diagnostic)
        hashes.update({str(p.relative_to(VERIFY_ROOT)): hashlib.sha256(p.read_bytes()).hexdigest() for p in paths})
    command(["/usr/bin/systemd-analyze", "calendar", "--iterations=2", "*-*-* 02:30:00 UTC"])
    report["verified_units"] = len(hashes)
    report["sha256"] = hashes
    control = Path("/var/lib/repro-backup/inert-exit-code")
    cases = 0
    markers = 0
    try:
        for profile in units.PROFILES:
            for name in sorted(units.NAMES):
                if not name.endswith(".service"):
                    continue
                service = units.read_unit(profile, name)["Service"]
                for status in (0, 1):
                    control.write_text(str(status))
                    control.chmod(0o600)
                    if "ExecStartPre" in service:
                        marker = command(shlex.split(service["ExecStartPre"]))
                        if marker.stdout != b"REPRO_BACKUP_ALERT: scheduled backup or freshness check failed\n":
                            raise RuntimeError("constant alert marker mismatch")
                        markers += 1
                    result = command(shlex.split(service["ExecStart"]), status)
                    body = json.loads(result.stdout)
                    expected_action = "run" if name == "repro-backup.service" else "check"
                    if body != {"fixture": "inert", "action": expected_action, "provider_accessed": False}:
                        raise RuntimeError("fixed command contract mismatch")
                    cases += 1
    finally:
        control.unlink(missing_ok=True)
        shutil.rmtree(runtime)
        shutil.rmtree(VERIFY_ROOT)
    report.update(fixed_argv_cases=cases, constant_alert_markers=markers,
                  elapsed_seconds=round(time.monotonic() - started, 3), result="passed")
    print(json.dumps(report, sort_keys=True))


if __name__ == "__main__":
    main()
