# Native availability (option B)

The iPhone app uses the existing availability API. It must not use the illustrative Figma people or schedules as runtime data.

## Access

- Admin, superadmin and editing manager retain existing calendar management.
- Representatives (normalized salesRep/sales_rep/sales-rep/rep/representative) can manage primary or secondary photographer calendars. This does not grant management of arbitrary client, editor or administrator calendars.
- Photographer self access is unchanged; unrelated photographer/client/editor mutations remain forbidden.
- Google connection permissions are separate and unchanged. Reps cannot connect, inspect, resync or disconnect Google calendars.

## Reads

- GET `/api/photographers`: lightweight public roster with real id/name/avatar/about. It intentionally does not expose account roles or timezones. Staff `/api/admin/photographers` is a separate richer endpoint.
- POST `/api/photographer/availability/bulk-index`: `{photographer_ids:[...],from_date:"YYYY-MM-DD",to_date:"YYYY-MM-DD"}`. Response `data` is keyed by photographer ID. Each value is an array, including `[]` when no hours or appointments exist. Authenticated `meta.timezones` and `meta.can_manage` are objects keyed by the same IDs, with canonical IANA zones and authoritative edit permissions. Use one bounded period request instead of one request per photographer. Neither metadata map adds access to calendars denied by the endpoint.
- GET `/api/photographer/availability/{photographerId}`: raw stored rules and date entries in `data`, plus the authorized photographer `timezone`.
- POST `/api/photographer/availability/booked-slots`: `{photographer_id,from_date,to_date}`. Returns computed booked appointment windows in `data`.

Raw availability `id` is numeric, `date` is null (weekly) or a calendar-date string, `day_of_week` is lowercase, and clock strings are local HH:mm. `status` is available/unavailable. Computed booked rows have string IDs such as `shoot_123_main`, status booked, shoot_id, optional shoot_service_id, duration_minutes, timezone, starts_at/ends_at (ISO instants with offset), and shoot_details. Those instants preserve the actual appointment zone even when a photographer has no configured timezone. Do not send computed booking IDs to availability CRUD.

`shoot_details` may contain address/latitude/longitude/shoot_status/client/services/duration_minutes. Treat every optional value defensively and render only authorized UI. A missing coordinate omits a map pin, not the appointment. Map appointments only; never infer live photographer locations.

Apply raw dated rules before weekly fallback. Computed booked rows appended to a period response are not themselves a raw dated override. Subtract unavailable and booked windows to show open gaps. Preserve the weekly source and distinguish gaps from editable stored slots. Display photographer-local dates/clocks and explicit timezone labels; export with the correct timezone/DST conversion.

## Writes

- POST base: one photographer_id, date OR day_of_week, start_time/end_time, available/unavailable and optional note.
- POST bulk: one photographer_id and availabilities array for weekly/day entries.
- POST replace-weekly: one photographer_id and availabilities array, possibly empty. Existing dated exceptions, booked work and recurring unavailable blocks are retained.
- PUT `/{id}` / DELETE `/{id}`: stored availability ID only. Deleting a dated row differs from deleting a recurring rule.
- Date-only blocks preserve underlying regular hours. A dated override must not delete the weekly rule. Existing bookings are scheduled through their shoot workflow.
- Server validation rejects overlaps and invalid ranges; the app must retain a failed draft and show the server error, rather than reporting success.

## Connections and export

GET `/api/google-calendar/status?user_id=...` and POST `/api/google-calendar/connect` with `{user_id,source:"availability"}` support allowed staff acting on a selected photographer and photographer self. Status includes available/connected/provider_email/calendar_id/sync_enabled/last_synced_at/last_error. DELETE disconnect and POST resync remain photographer-self only. Browser OAuth consent is an explicit user action.

Apple/Outlook export is a one-time ICS file, not continuous sync. Include only computed available windows for the selected person and 30/90/custom days, excluding booked and unavailable time. No new provider connection or real calendar modification is required to test the file.
