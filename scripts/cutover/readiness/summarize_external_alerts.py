"""Derive the external alert receipt from reviewed, pinned phone observations."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import time

from scripts.cutover.readiness import check
from scripts.cutover.runner import digest


SCHEMA = "repro.external-alerts.observations.v1"
SCOPE_FIELDS = ("identity_sha256", "monitor_profile_sha256", "phone_recipient_sha256")
EVENT_FIELDS = {
    "triggered_at_unix_ms", "condition_evidence_sha256",
    "phone_observed_at_unix_ms", "delivery_receipt_sha256",
}


def require_fields(record: dict, fields: set[str], description: str) -> None:
    if not fields <= record.keys():
        raise check.Missing(f"{description} is incomplete")
    if record.keys() != fields:
        raise check.Failed(f"{description} contains unexpected fields")


def summarize(policy: dict, path: Path, *, now_unix_ms: int) -> dict:
    check.version(policy)
    operations = check.object_field(policy, "operations")
    pin = check.sha_field(operations, "external_alerts_report_sha256")
    observations = check.load(path, expected_sha256=pin)
    require_fields(observations, {"schema", "rounds", *SCOPE_FIELDS}, "Alert observation report")
    if observations["schema"] != SCHEMA:
        raise check.Failed("Alert observations use an unsupported schema")
    scope = {
        "identity_sha256": digest(check.load_identity(policy)),
        "monitor_profile_sha256": check.sha_field(operations, "monitor_profile_sha256"),
        "phone_recipient_sha256": check.sha_field(operations, "phone_recipient_sha256"),
    }
    if any(observations[field] != expected for field, expected in scope.items()):
        raise check.Failed("Alert observations describe a different identity, monitor or phone")
    rounds = check.object_field(observations, "rounds")
    require_fields(rounds, set(check.ALERT_MILESTONES), "Alert rounds")
    summary = scope | {"report_sha256": pin}
    conditions = set()
    for milestone in check.ALERT_MILESTONES:
        round_report = check.object_field(rounds, milestone)
        require_fields(round_report, {"report_sha256", "cases"}, milestone)
        cases = check.object_field(round_report, "cases")
        require_fields(cases, set(check.ALERT_LIMITS_MS), f"{milestone} cases")
        reviewed = {}
        for name in check.ALERT_LIMITS_MS:
            event = check.object_field(cases, name)
            label = f"{milestone} {name}"
            require_fields(event, EVENT_FIELDS, label)
            triggered = check.number(event, "triggered_at_unix_ms", minimum=1)
            if event["phone_observed_at_unix_ms"] is None or event["delivery_receipt_sha256"] is None:
                raise check.Missing(f"{label} has no confirmed handset observation")
            observed = check.number(event, "phone_observed_at_unix_ms", minimum=triggered)
            if observed > now_unix_ms:
                raise check.Failed(f"{label} phone receipt is in the future")
            condition = check.sha_field(event, "condition_evidence_sha256")
            if condition in conditions:
                raise check.Failed("An induced condition receipt was reused across alert checks")
            conditions.add(condition)
            reviewed[name] = {
                "condition_tested": True,
                "phone_delivery_verified": True,
                "delivery_receipt_sha256": check.sha_field(event, "delivery_receipt_sha256"),
                "delivery_ms": observed - triggered,
            }
        summary[milestone] = {"report_sha256": check.sha_field(round_report, "report_sha256"), "cases": reviewed}
    check.review_alert_rounds(summary)
    return summary


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--policy", required=True, type=Path)
    parser.add_argument("--observations", required=True, type=Path)
    args = parser.parse_args()
    try:
        summary = summarize(check.load(args.policy), args.observations, now_unix_ms=time.time_ns() // 1_000_000)
    except (check.Missing, check.Failed) as error:
        print(json.dumps({"result": "alert-summary-refused", "detail": str(error)}))
        return 1
    print(json.dumps({"result": "alert-summary-ready", "external_alerts": summary,
                      "summary_sha256": digest(summary)}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
