# Scheduling buffer settings

Admins and superadmins can open Scheduling Catalog → Service actions → Buffer time.
GET/PUT `/api/admin/scheduling/buffer-settings` returns/saves the policy. PUT requires
the version returned by GET; stale edits return 409. The generic settings writer
cannot write this key. Changes are audited in `user_activity_logs`.

Until the first save, existing deployment settings remain authoritative. Saving
stores `scheduling.travel_buffers` in the settings table; no migration, appointment,
duration snapshot, price, or notification changes occur. Explicit saved policies
use the shared scheduling evaluator for all three modes, including fixed gaps:

- Google: driving minutes + configurable allowance, rounded up to 5 minutes with
  a configurable minimum. Outages/cap exhaustion use mileage or staff review.
- Mileage: existing precise-coordinate distance calculation and 5/15/30-mile
  boundaries, with configurable ascending minute gaps. No Routes requests.
- Fixed: configurable gap without a driving estimate. No Routes requests.

Verified same-building transitions need zero travel; capture overlaps and working
hours remain hard conflicts. Unknown mileage/Google locations, distances beyond
30 miles, and definitive no-route results require review. Fixed mode does not need
a route or coordinates to apply its gap. Existing staff exception rules apply.

Policy versions are part of schedule fingerprints, warning confirmations and
availability cache keys, so saves cannot silently reuse an old travel decision.
The policy overrides the environment's initial mode after it is explicitly saved.
To restore the original rollout flag behavior, remove only this settings row as
an intentional operational rollback; do not rewrite bookings.

Google credentials stay on the server. The dialog reports key configuration and
the existing $100 / 10,000-element rolling 31-day allowance, without making a
billable request on open or save. Key configuration is not a provider readiness
claim. Existing provider provisioning and real-route readiness verification remain
required; this feature does not alter credentials or budget caps.
