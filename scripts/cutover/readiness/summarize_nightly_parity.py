"""Build a nightly parity summary from five independently reviewed local files."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Sequence

from scripts.cutover.readiness import check
from scripts.cutover.runner import digest


SCHEMA = "repro.nightly-data-parity.manifest.v1"
MANIFEST_FIELDS = {"schema", "identity_sha256", "nightly_scope_sha256", "nights"}
NIGHT_FIELDS = {
    "scheduled_day_utc", "release_sha", "snapshot_sha256", "import_and_verify_ms",
    *check.NIGHTLY_COUNTERS, *check.NIGHTLY_PROOFS,
}


def summarize(policy: dict, manifest_path: Path, nightly_paths: Sequence[Path]) -> dict:
    check.version(policy)
    identity_sha = digest(check.load_identity(policy))
    operations = check.object_field(policy, "operations")
    scope_sha = check.sha_field(operations, "nightly_scope_sha256")
    manifest_sha = check.sha_field(operations, "nightly_data_parity_report_sha256")
    manifest = check.load(manifest_path, expected_sha256=manifest_sha)
    if (set(manifest) != MANIFEST_FIELDS or manifest["schema"] != SCHEMA
            or check.sha_field(manifest, "identity_sha256") != identity_sha
            or check.sha_field(manifest, "nightly_scope_sha256") != scope_sha):
        raise check.Failed("Nightly evidence manifest differs from the reviewed identity or scope")
    selected = check.field(manifest, "nights")
    if not isinstance(selected, list):
        raise check.Failed("Nightly manifest must list the reviewed reports in date order")
    if len(selected) != check.NIGHTLY_RUN_COUNT or len(nightly_paths) != check.NIGHTLY_RUN_COUNT:
        raise check.Failed("Supply exactly five reviewed nightly reports")

    nights = []
    for entry, path in zip(selected, nightly_paths, strict=True):
        if not isinstance(entry, dict) or set(entry) != {"scheduled_day_utc", "report_sha256"}:
            raise check.Failed("Malformed nightly report binding")
        day = check.utc_day(entry, "scheduled_day_utc")
        report_sha = check.sha_field(entry, "report_sha256")
        night = check.load(path, expected_sha256=report_sha)
        if (set(night) != NIGHT_FIELDS | {"nightly_scope_sha256"}
                or check.sha_field(night, "nightly_scope_sha256") != scope_sha
                or check.utc_day(night, "scheduled_day_utc") != day):
            raise check.Failed("Nightly report differs from the reviewed date or scope")
        del night["nightly_scope_sha256"]
        nights.append(night | {"report_sha256": report_sha})

    check.review_nightly_runs(policy, nights)
    return {"identity_sha256": identity_sha, "report_sha256": manifest_sha,
            "nightly_scope_sha256": scope_sha, "nights": nights}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--policy", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--nights", nargs=check.NIGHTLY_RUN_COUNT, type=Path, required=True,
                        metavar="REPORT", help="reviewed nightly JSON files in date order")
    args = parser.parse_args()
    try:
        summary = summarize(check.load(args.policy), args.manifest, args.nights)
    except (check.Missing, check.Failed) as error:
        print(json.dumps({"result": "nightly-parity-summary-refused", "detail": str(error)}))
        return 1
    print(json.dumps({"result": "nightly-parity-summary-ready", "nightly_data_parity": summary,
                      "summary_sha256": digest(summary)}, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
