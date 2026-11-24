"""Build a nightly parity summary from five independently reviewed runs and CRM audits."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Sequence

from scripts.cutover.readiness import check
from scripts.cutover.runner import digest


SCHEMA = "repro.nightly-data-parity.manifest.v2"
MANIFEST_FIELDS = {"schema", "identity_sha256", "nightly_scope_sha256", "nights"}
NIGHT_FIELDS = {
    "import_and_verify_ms", *check.NIGHTLY_IDENTITY_FIELDS,
    *check.NIGHTLY_COUNTERS, *check.NIGHTLY_PROOFS,
}
SCOPE_SCHEMA = "repro.mysql-migration-scope.v1"
SHARED_CRM_TABLES = (
    "users", "shoots", "messages", "website_email_deliveries", "external_booking_submissions",
    "google_calendar_connections",
)


def crm_scope_table_count(path: Path, scope_sha: str) -> int:
    scope = check.load(path, expected_sha256=scope_sha)
    if set(scope) != {"schema", "tables"} or scope["schema"] != SCOPE_SCHEMA:
        raise check.Failed("Nightly scope must be a reviewed migration-scope manifest")
    tables = check.object_field(scope, "tables")
    crm_tables = {name for name in tables if name.startswith(("crm_", "recruit_")) or name == "email_suppressions"}
    if "email_suppressions" not in crm_tables or len(crm_tables) < check.MIN_CRM_TABLES:
        raise check.Failed("Nightly scope must include the full CRM release tables and email suppressions")
    if any(tables.get(name) != {"class": "A"} for name in (*crm_tables, *SHARED_CRM_TABLES)):
        raise check.Failed("Nightly scope must preserve every CRM and shared table")
    return len(crm_tables)


def summarize(policy: dict, manifest_path: Path, scope_path: Path,
              nightly_paths: Sequence[Path], crm_paths: Sequence[Path]) -> dict:
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
    if any(len(items) != check.NIGHTLY_RUN_COUNT for items in (selected, nightly_paths, crm_paths)):
        raise check.Failed("Supply exactly five nightly reports and five CRM reviews")

    scope_table_count = crm_scope_table_count(scope_path, scope_sha)
    nights = []
    for entry, path, crm_path in zip(selected, nightly_paths, crm_paths, strict=True):
        if not isinstance(entry, dict) or set(entry) != {"scheduled_day_utc", "report_sha256", "crm_report_sha256"}:
            raise check.Failed("Malformed nightly report binding")
        day = check.utc_day(entry, "scheduled_day_utc")
        report_sha = check.sha_field(entry, "report_sha256")
        night = check.load(path, expected_sha256=report_sha)
        if (set(night) != NIGHT_FIELDS
                or check.sha_field(night, "nightly_scope_sha256") != scope_sha
                or check.utc_day(night, "scheduled_day_utc") != day):
            raise check.Failed("Nightly report differs from the reviewed date or scope")
        del night["nightly_scope_sha256"]
        crm_sha = check.sha_field(entry, "crm_report_sha256")
        crm = check.load(crm_path, expected_sha256=crm_sha)
        if set(crm) != check.NIGHTLY_CRM_FIELDS:
            raise check.Failed("Malformed nightly CRM parity review")
        nights.append(night | {"report_sha256": report_sha, "crm": crm | {"report_sha256": crm_sha}})

    check.review_nightly_runs(policy, nights, scope_table_count)
    return {"schema": check.NIGHTLY_SUMMARY_SCHEMA, "identity_sha256": identity_sha, "report_sha256": manifest_sha,
            "nightly_scope_sha256": scope_sha, "crm_scope_table_count": scope_table_count, "nights": nights}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--policy", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--scope", type=Path, required=True, help="reviewed migration scope")
    parser.add_argument("--nights", nargs=check.NIGHTLY_RUN_COUNT, type=Path, required=True,
                        metavar="REPORT", help="reviewed nightly JSON files in date order")
    parser.add_argument("--crm-reports", nargs=check.NIGHTLY_RUN_COUNT, type=Path, required=True,
                        metavar="CRM_REVIEW", help="reviewed CRM JSON files in the same date order")
    args = parser.parse_args()
    try:
        summary = summarize(check.load(args.policy), args.manifest, args.scope, args.nights, args.crm_reports)
    except (check.Missing, check.Failed) as error:
        print(json.dumps({"result": "nightly-parity-summary-refused", "detail": str(error)}))
        return 1
    print(json.dumps({"result": "nightly-parity-summary-ready", "nightly_data_parity": summary,
                      "summary_sha256": digest(summary)}, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
