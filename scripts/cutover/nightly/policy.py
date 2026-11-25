"""Check and re-pin the draft review policy and the nightly evidence manifest.

The committed drafts hold every value known today: the nightly scope pin and
the dress-rehearsal profile pins. The cutover identity, the busiest endpoint
list and the operations evidence stay unset until they exist. This tool reports
what is still unset, adds each reviewed night to a manifest, and pins the
manifest and the summary into a new policy file. It reads and writes local
JSON only.
"""
from __future__ import annotations

import argparse
import copy
from datetime import date, timedelta
import hashlib
import json
from pathlib import Path

from scripts.cutover.nightly import dress_profile
from scripts.cutover.readiness import check, summarize_nightly_parity
from scripts.cutover.runner import Identity, digest, is_sha256

ENTRY_FIELDS = {"scheduled_day_utc", "report_sha256", "crm_report_sha256", "producer_receipt_sha256",
                "source_release_sha"}


class Refused(ValueError):
    pass


def need(condition: object, reason: str) -> None:
    if not condition:
        raise Refused(reason)


def file_sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def unset(record: dict, prefix: str = "") -> list[str]:
    names = []
    for name, value in record.items():
        if isinstance(value, dict):
            names += unset(value, f"{prefix}{name}.")
        elif value is None:
            names.append(prefix + name)
    return names


def draft_status(policy_path: Path, manifest_path: Path, scope_path: Path, host_path: Path,
                 workload_path: Path) -> dict:
    """Confirm every value the drafts pin today still matches its file, and list what is unset."""
    policy, manifest = json.loads(policy_path.read_bytes()), json.loads(manifest_path.read_bytes())
    scope_sha = file_sha256(scope_path)
    need(policy["operations"]["nightly_scope_sha256"] == scope_sha == manifest["nightly_scope_sha256"],
         "the nightly scope pin differs from the migration scope file")
    try:
        summarize_nightly_parity.crm_scope_table_count(scope_path, scope_sha)
        pins = dress_profile.policy_pins(host_path, workload_path)
    except (check.Missing, check.Failed, dress_profile.Refused) as error:
        raise Refused(str(error)) from error
    need(policy["host_profile_sha256"] == pins["host_profile_sha256"], "the host profile pin differs from its file")
    need(policy["workload_sha256"] == pins["workload_sha256"], "the workload pin differs from its file")
    need(policy["critical_endpoints"] == pins["critical_endpoints"]
         and policy["busiest_endpoints"] == pins["busiest_endpoints"], "endpoint lists differ from the workload file")
    need(manifest.get("schema") == summarize_nightly_parity.SCHEMA, "manifest schema differs")
    if all(value is not None for value in policy["identity"].values()):
        need(manifest["identity_sha256"] == digest(check.load_identity(policy)), "manifest identity differs")
    return {"result": "drafts-consistent", "nightly_scope_sha256": scope_sha, "nights": len(manifest["nights"]),
            "unset": unset(policy) + [f"manifest.{name}" for name in unset(manifest)]}


def add_night(manifest: dict, entry: dict) -> dict:
    """Append one reviewed night. Dates must be consecutive; only the latest five are kept."""
    need(isinstance(entry, dict) and set(entry) == ENTRY_FIELDS
         and all(is_sha256(entry[name]) for name in ("report_sha256", "crm_report_sha256", "producer_receipt_sha256"))
         and isinstance(entry["source_release_sha"], str) and len(entry["source_release_sha"]) == 40,
         "malformed manifest entry")
    try:
        day = date.fromisoformat(entry["scheduled_day_utc"])
    except (TypeError, ValueError) as error:
        raise Refused("malformed manifest entry date") from error
    nights = list(manifest["nights"])
    if nights:
        previous = date.fromisoformat(nights[-1]["scheduled_day_utc"])
        need(day == previous + timedelta(days=1), "nights must be consecutive; start a new manifest after a gap")
    updated = copy.deepcopy(manifest)
    updated["nights"] = (nights + [dict(entry)])[-check.NIGHTLY_RUN_COUNT:]
    return updated


def pin_manifest(policy: dict, manifest: dict) -> tuple[dict, bytes]:
    """Bind the manifest to the cutover identity and pin its exact bytes in a new policy."""
    identity = policy.get("identity") or {}
    need(all(identity.get(name) is not None for name in Identity.__dataclass_fields__),
         "the cutover identity is not set yet")
    try:
        identity_sha = digest(check.load_identity(policy))
    except (check.Missing, check.Failed) as error:
        raise Refused(f"the cutover identity is malformed: {error}") from error
    need(manifest["nights"], "the manifest has no reviewed nights")
    bound = copy.deepcopy(manifest) | {"identity_sha256": identity_sha}
    raw = (json.dumps(bound, indent=2) + "\n").encode("utf-8")
    pinned = copy.deepcopy(policy)
    pinned["operations"]["nightly_data_parity_report_sha256"] = hashlib.sha256(raw).hexdigest()
    pinned["operations"]["last_nightly_day_utc"] = bound["nights"][-1]["scheduled_day_utc"]
    return pinned, raw


def pin_summary(policy: dict, summary_sha256: str) -> dict:
    need(is_sha256(summary_sha256), "the nightly summary digest is malformed")
    pinned = copy.deepcopy(policy)
    pinned["operations"]["nightly_data_parity_summary_sha256"] = summary_sha256
    return pinned


def write_new(path: Path, data: bytes) -> None:
    with path.open("xb") as stream:
        stream.write(data)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    commands = parser.add_subparsers(dest="command", required=True)
    status = commands.add_parser("check-drafts")
    for name in ("policy", "manifest", "scope", "host-profile", "workload"):
        status.add_argument(f"--{name}", type=Path, required=True)
    add = commands.add_parser("add-night")
    add.add_argument("--manifest", type=Path, required=True)
    add.add_argument("--entry", type=Path, required=True, help="manifest-entry.json from a reviewed night")
    add.add_argument("--output", type=Path, required=True)
    manifest = commands.add_parser("pin-manifest")
    manifest.add_argument("--policy", type=Path, required=True)
    manifest.add_argument("--manifest", type=Path, required=True)
    manifest.add_argument("--policy-output", type=Path, required=True)
    manifest.add_argument("--manifest-output", type=Path, required=True)
    summary = commands.add_parser("pin-summary")
    summary.add_argument("--policy", type=Path, required=True)
    summary.add_argument("--summary-sha256", required=True)
    summary.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    try:
        if args.command == "check-drafts":
            result = draft_status(args.policy, args.manifest, args.scope, args.host_profile, args.workload)
        elif args.command == "add-night":
            updated = add_night(json.loads(args.manifest.read_bytes()), json.loads(args.entry.read_bytes()))
            write_new(args.output, (json.dumps(updated, indent=2) + "\n").encode("utf-8"))
            result = {"result": "night-added", "nights": len(updated["nights"])}
        elif args.command == "pin-manifest":
            policy, raw = pin_manifest(json.loads(args.policy.read_bytes()), json.loads(args.manifest.read_bytes()))
            write_new(args.manifest_output, raw)
            write_new(args.policy_output, (json.dumps(policy, indent=2) + "\n").encode("utf-8"))
            result = {"result": "manifest-pinned",
                      "nightly_data_parity_report_sha256": policy["operations"]["nightly_data_parity_report_sha256"]}
        else:
            policy = pin_summary(json.loads(args.policy.read_bytes()), args.summary_sha256)
            write_new(args.output, (json.dumps(policy, indent=2) + "\n").encode("utf-8"))
            result = {"result": "summary-pinned"}
    except (Refused, OSError, ValueError, KeyError) as error:
        print(json.dumps({"result": "policy-refused", "detail": str(error)}))
        return 1
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
