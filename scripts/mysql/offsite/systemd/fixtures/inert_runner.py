"""NO BACKUP: inert CLI boundary fixture, mounted at the installed runner path."""
import argparse
import json
from pathlib import Path
import sys

parser = argparse.ArgumentParser()
parser.add_argument("action", choices=("run", "check"))
parser.add_argument("--plan", required=True)
args = parser.parse_args()
if args.plan != "/etc/repro-backup/plan.json":
    raise SystemExit(64)
plan = json.loads(Path(args.plan).read_text())
if plan != {"fixture": "systemd-cli-only-v1"}:
    raise SystemExit(65)
control = Path("/var/lib/repro-backup/inert-exit-code").read_text().strip()
if control not in {"0", "1"}:
    raise SystemExit(66)
print(json.dumps({"fixture": "inert", "action": args.action, "provider_accessed": False}))
sys.exit(int(control))
