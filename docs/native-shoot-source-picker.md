# Native Studio shoot picker

`GET /api/studio/workspaces/sources/shoots` keeps its existing media-access and
client rollout gates. Paging does not grant access to a shoot or its files.

New callers opt in with `page=1&per_page=20` (maximum 50 rows per page). Search
uses the existing `q` parameter. The default is recently updated shoots, with
descending IDs as a stable tie-breaker. An optional
`scheduled_local_date=YYYY-MM-DD` selects the **property-local booked day**,
including scheduled-at-only and legacy date-only records, before paging.

Paged responses preserve `success` and `data`, and return these `meta` fields:

* `query`, `current_page`, `per_page`, `returned_count`;
* `has_more` and `next_page` (null on the last page);
* `scheduled_local_date` (null without a date filter);
* `calendar_context.timezone` and `calendar_context.today` for calendar controls.

`returned_count` is only the page size. There is deliberately no pretend
whole-library `total`: one look-ahead row determines whether another page exists.
Paged source rows additionally include nullable `scheduledDate` and canonical
`timezone`. Existing source fields remain property-only; no client, billing,
private notes, or service payload is added.

The native client cancels/debounces a replaced search, resets the page when the
date/account/query changes, ignores stale replies, and deduplicates appended IDs.
Recent browsing and source selection do not create an editing workspace or job.

Callers omitting paging parameters retain the original maximum-20 result shape
and `meta.total` meaning. Legacy `/studio/shoots/search` still requires a nonempty
query; it does not acquire the workspace route's recent-browsing behavior.
