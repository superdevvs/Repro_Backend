# Native shoot calendar list

The existing authenticated `GET /api/shoots` accepts the optional
`scheduled_local_date` query: `today` or a strict `YYYY-MM-DD`. Invalid dates
return 422. It composes with existing tabs, search, subfilters, and authorization.

For the dashboard, request `tab=all&scheduled_local_date=today&page=1&per_page=50`
with `view=card&include_files=false&include_filters=false`. This includes every
authorized workflow status booked for that calendar day; no upcoming-list cap
or delivery-date filter applies. Unscheduled records do not match.

Normal list responses include `meta.calendar_context` with the server's business
calendar `timezone` and `today` date. The zone is configured by
`SCHEDULE_CALENDAR_TIMEZONE`, defaulting to America/New_York, consistent with
dashboard business reporting. The phone's timezone does not select this date.

Date-filtered responses also echo the resolved `meta.scheduled_local_date`.
Clients must require this field to detect servers without the filter. Pin the
first page's resolved date on later page requests and require the same date on
every page. Use `meta.count`, `last_page` and `current_page` to load all results;
a failed page must not be presented as a complete list or zero-count success.

The property timezone determines the booked date from `scheduled_at`. Legacy
`scheduled_date` values are calendar dates, including midnight timestamps from
Eloquent's date cast. Filtering occurs before pagination, after role scopes.
`sort=date_asc` provides existing server-side chronological schedule ordering.

This filter is separate from `date_from` / `date_to`, which retain their existing
delivery-date meaning on the Delivered tab. Existing `scheduled_start` and
`scheduled_end` behavior is unchanged.
