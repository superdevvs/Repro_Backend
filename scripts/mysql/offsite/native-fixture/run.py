"""Native MySQL 8.4.13 check for the archive-aware purge and the drill runner's replay.

Usage (repo root, Docker available, nothing pulled):
  python -B scripts/mysql/offsite/native-fixture/run.py --mysqlbinlog-vendor DIR

DIR holds the pinned bin/mysqlbinlog and lib/private/libssl.so.3 and libcrypto.so.3
(the MySQL server image ships without mysqlbinlog). Three containers, all with
--network none and no published ports: a binlogging source, a fresh target and
the QA image running inside.py. Sockets share a tmpfs volume; the source data
volume is mounted read-only into the QA container. Everything is removed after.
"""
import argparse
import json
from pathlib import Path
import subprocess
import sys
import uuid

MYSQL = "sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902"
QA = "sha256:90096fc97dcb15e27a5f2d2b91522af449cdb924364fa876910fcd33ac67130e"
REPO = Path(__file__).resolve().parents[4]
SERVER = ("set -eu; umask 077; mysqld --no-defaults --initialize-insecure --datadir={data} --innodb-buffer-pool-size=96M "
          "--innodb-redo-log-capacity=32M --log-error=/tmp/init.log; exec mysqld --no-defaults --datadir={data} "
          "--socket=/fixture-socket/{name}.sock --pid-file=/tmp/m.pid --log-error=/tmp/m.log --skip-networking "
          "--mysqlx=OFF --event-scheduler=OFF --innodb-buffer-pool-size=96M --innodb-redo-log-capacity=32M "
          "--server-id={server_id} {binlog} --sync-binlog=1 --innodb-flush-log-at-trx-commit=1")


def docker(*args, check=True, timeout=180):
    result = subprocess.run(["docker", *args], stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=timeout)
    if check and result.returncode != 0:
        raise RuntimeError(f"docker {args[0]} failed: {result.stdout.decode()[-600:]}")
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mysqlbinlog-vendor", type=Path, required=True)
    args = parser.parse_args()
    token = uuid.uuid4().hex[:10]
    label = "repro.offsite-backups.native=" + token
    names = {role: f"offsite-backups-native-{role}-{token}" for role in ("source", "target", "qa")}
    volumes = {role: f"offsite-backups-native-{role}-{token}" for role in ("sock", "data")}
    report = None
    try:
        for image in (MYSQL, QA):
            docker("image", "inspect", "--format", "{{.Id}}", image)
        docker("volume", "create", "--label", label, "--opt", "type=tmpfs", "--opt", "device=tmpfs",
               "--opt", "o=uid=999,gid=999,mode=0777,size=8m", volumes["sock"])
        docker("volume", "create", "--label", label, "--opt", "type=tmpfs", "--opt", "device=tmpfs",
               "--opt", "o=uid=999,gid=999,mode=0750,size=512m", volumes["data"])
        common = ["--pull", "never", "--label", label, "--network", "none", "--cpus", "1", "--memory", "768m",
                  "--user", "mysql", "--tmpfs", "/tmp:rw,size=32m,mode=1777",
                  "--mount", f"type=volume,source={volumes['sock']},target=/fixture-socket"]
        docker("run", "-d", "--name", names["source"], *common,
               "--mount", f"type=volume,source={volumes['data']},target=/data,volume-nocopy",
               "--entrypoint", "/bin/bash", MYSQL, "-c",
               SERVER.format(data="/data", name="source", server_id=5384, binlog="--log-bin=/data/binlog"))
        docker("run", "-d", "--name", names["target"], *common, "--tmpfs", "/target:rw,size=512m,uid=999,gid=999,mode=0700",
               "--entrypoint", "/bin/bash", MYSQL, "-c",
               SERVER.format(data="/target", name="target", server_id=5385, binlog="--skip-log-bin"))
        result = docker("run", "--rm", "--pull", "never", "--name", names["qa"], "--label", label, "--network", "none",
                        "--cpus", "1", "--memory", "768m", "--tmpfs", "/work:rw,exec,size=256m", "--tmpfs", "/tools:rw,exec,size=64m",
                        "--mount", f"type=bind,source={REPO / 'scripts'},target=/src/scripts,readonly",
                        "--mount", f"type=bind,source={args.mysqlbinlog_vendor},target=/vendor-binlog,readonly",
                        "--mount", f"type=volume,source={volumes['sock']},target=/fixture-socket",
                        "--mount", f"type=volume,source={volumes['data']},target=/source-data,readonly,volume-nocopy",
                        "--entrypoint", "/usr/bin/python3", QA, "-I", "-B", "/src/scripts/mysql/offsite/native-fixture/inside.py",
                        check=False, timeout=600)
        text = result.stdout.decode()
        report = json.loads(text[text.index("{"):]) if "{" in text else {"result": "failed", "error": text[-1500:]}
    finally:
        for name in names.values():
            docker("rm", "-f", name, check=False)
        for volume in volumes.values():
            docker("volume", "rm", volume, check=False)
        left = (docker("ps", "-a", "--filter", "label=" + label, "--format", "{{.Names}}").stdout.decode().split()
                + docker("volume", "ls", "--filter", "label=" + label, "--format", "{{.Name}}").stdout.decode().split())
    report = (report or {"result": "failed"}) | {"cleanup_verified": not left}
    print(json.dumps(report, indent=1, sort_keys=True))
    return 0 if report.get("result") == "passed" and not left else 1


if __name__ == "__main__":
    sys.exit(main())
