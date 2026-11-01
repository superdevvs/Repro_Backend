# Native shoot filters and booking requests

`GET /api/shoots` retains its existing role, assignment, account, and media
scopes. Filters narrow that scope; they never confer permission to see a shoot.

The optional `statuses[]` array selects canonical workflow statuses. Its values
come from `GET /api/shoots/filters` → `data.statuses`, an array of `{value,label}`.
Multiple selections are ORed together and intersect all other filters and the
selected tab. Legacy status aliases resolve before pagination. A nonempty active
`workflow_status` takes precedence over the legacy `status` column.

`unassigned_only=1` selects shoots with no main photographer, matching the web
filter. A service may already have an assignee on such a shoot; conversely, an
assigned main photographer does not guarantee every service is assigned. Use the
existing service-assignment workflow for individual service/unit changes.

`flagged_only=1` selects the existing `is_flagged` marker. It does not search
private notes or invent a missing-editor state. Both boolean filters accept
`0`, `1`, `true`, and `false`. Invalid values and unsupported canonical statuses
return 422 rather than silently disabling a filter.

Filter metadata additionally returns `data.filter_capabilities`:

* `assignments`: admin, superadmin, editing manager, sales representative;
* `priority`: the preceding roles, editor, photographer.

Enabling a capability-restricted filter returns 403 for another role. Existing
client, photographer, service, and sales representative metadata shapes remain
unchanged. Use existing role rules to hide client names where required.

All three new filters run before pagination, contribute to the response-cache
key, and affect `meta.count`. They combine with the existing address/search,
people, service, payment, date, and missing-raw/final filters. Service selections
can use names from `data.services`, which remains an array of strings. There is
no `missing_editor` query parameter.

For pending booking requests, use
`tab=scheduled&scheduled_status=requested&view=card&include_files=false` and read
`meta.count`, not the loaded page length. Fetch fresh shoot details before any
decision to obtain current permissions and `editVersion`. Approve through
`POST /shoots/{id}/approve`; decline through `POST /shoots/{id}/decline` with a
reason. Do not substitute the generic editing-workflow reject route. Scheduling,
travel confirmations, qualifications, unit revision, and version checks still
apply to approval and photographer changes.

Both photographer-assignment endpoints accept optional `expected_edit_version`
from fresh shoot details. It is checked again after schedule planning, under the
same database writer lock as the assignment. A changed token returns 409 with
`code=shoot_edit_conflict`, leaving all assignments unchanged. This supplements
the multi-unit revision check; older callers omitting the token remain compatible.
The batch route accepts `assignments`, `service_photographers`, or `services`.
Keep both catalog `service_id` and booked `shoot_service_id` when available.

Native dashboards can show Upcoming, Requested, and Completed/Delivered without
removing other request categories or granting new approval permissions. Counts
should represent pending items in accessible queues, never historical decisions
or only the currently loaded page.
