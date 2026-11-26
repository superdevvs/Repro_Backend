"""Start a disposable systemd container, run inside.py, and remove everything it created.

Usage (from the repo root, Windows or Linux with Docker):
  python -B scripts/mysql/offsite/systemd/manager-fixture/run.py [--vendor-volume NAME]

The container has no network (--network none), no published ports, a private
cgroup namespace and CAP_SYS_ADMIN so systemd can apply ProtectSystem and
PrivateNetwork. It mounts the repo's scripts/ read-only and, for the producer
self-test, a vendor directory read-only. The image is pinned by ID and never pulled.
"""
import argparse
import json
from pathlib import Path
import subprocess
import sys
import time
import uuid

IMAGE = "sha256:90096fc97dcb15e27a5f2d2b91522af449cdb924364fa876910fcd33ac67130e"
REPO = Path(__file__).resolve().parents[5]


def docker(*args, check=True, timeout=120):
    result = subprocess.run(["docker", *args], stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=timeout)
    if check and result.returncode != 0:
        raise RuntimeError(f"docker {args[0]} failed: {result.stdout.decode()[-800:]}")
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--vendor-volume", default="repro-locked-dev-vendor-20261125")
    args = parser.parse_args()
    name = "offsite-backups-manager-" + uuid.uuid4().hex[:10]
    label = "repro.offsite-backups.fixture=" + name
    started = time.monotonic()
    report, cleaned = None, False
    try:
        docker("image", "inspect", "--format", "{{.Id}}", IMAGE)
        docker("run", "-d", "--pull", "never", "--name", name, "--label", label, "--network", "none",
               "--cpus", "2", "--memory", "2g", "--pids-limit", "512", "--cap-add", "SYS_ADMIN",
               "--security-opt", "seccomp=unconfined", "--cgroupns=private",
               "--tmpfs", "/run", "--tmpfs", "/run/lock", "--tmpfs", "/tmp", "-e", "container=docker",
               "--mount", f"type=bind,source={REPO / 'scripts'},target=/bundle-src/scripts,readonly",
               "--mount", f"type=volume,source={args.vendor_volume},target=/vendor-src,readonly,volume-subpath=vendor",
               "--entrypoint", "/bin/sh", IMAGE, "-c",
               "mount -o remount,rw /sys/fs/cgroup && exec /lib/systemd/systemd --log-target=console")
        for _ in range(60):
            state = docker("exec", name, "systemctl", "is-system-running", check=False).stdout.decode().strip()
            if state in ("running", "degraded"):
                break
            time.sleep(0.5)
        result = docker("exec", name, "/usr/bin/python3", "-I", "-B",
                        "/bundle-src/scripts/mysql/offsite/systemd/manager-fixture/inside.py", check=False, timeout=600)
        text = result.stdout.decode()
        report = json.loads(text[text.index("{"):]) if "{" in text else {"result": "failed", "error": text[-1500:]}
    finally:
        docker("rm", "-f", name, check=False)
        remaining = docker("ps", "-a", "--filter", "label=" + label, "--format", "{{.Names}}").stdout.decode().split()
        cleaned = not remaining
    report = (report or {"result": "failed"}) | {"container_removed": cleaned, "image": IMAGE,
                                                 "launcher_elapsed_seconds": round(time.monotonic() - started, 1)}
    print(json.dumps(report, indent=1, sort_keys=True))
    return 0 if report.get("result") == "passed" and cleaned else 1


if __name__ == "__main__":
    sys.exit(main())
