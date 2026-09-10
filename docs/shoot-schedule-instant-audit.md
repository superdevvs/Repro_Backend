# Shoot schedule instant audit

Production shoot 86 and service item 126 store `2026-09-09 10:00:00` with no shoot timezone, plus local date September 9 and time 10:00. The assigned photographer uses America/New_York. The booking is therefore 10:00 EDT / 14:00 UTC. Eloquent's UTC cast alone labels the legacy clock 10:00 UTC, causing a four-hour shift in consumers that treat it as an instant.

The existing Google Calendar boundary already distinguishes these records: absent/blank shoot timezone means a local clock anchored to the assigned photographer's timezone, then the configured application timezone if absent. A nonempty shoot timezone means an absolute stored instant; conversion must preserve it. Service-specific assignments use their assigned photographer, falling back to the shoot photographer. This change shares that interpretation with reminder timing and cancellation-window calculations without modifying stored schedules or Google Calendar behavior.

## Changed consumers

- Automation appointment anchors, service reminder anchors, and automation service-item schedule context use `ScheduleInstantResolver`.
- The 24-hour reminder sweep reads a bounded one-day margin on either side of the target so local-clock records are candidates, then enforces the existing five-minute due window against resolved instants. Reminder identity uses a canonical UTC timestamp to preserve existing explicit-instant deduplication keys.
- Cancellation notice eligibility and photographer cancellation payouts use the same existing zero-to-four-hour rule against corrected instants. Service payout eligibility respects the service photographer's timezone.
- Detailed shoot responses expose `scheduled_instant` (UTC ISO8601 or null), `schedule_timezone` (the resolved timezone, separate from the storage convention flag), and `cancellation_fee_window` (boolean), with camelCase aliases. Clients can use these for elapsed-time decisions without treating browser timezone as appointment timezone.
- Dashboard summaries expose `scheduled_instant` while preserving the existing `start_time`. Dashboard and shoot-list photographer projections include timezone so partial eager loads retain the necessary context.
- Client-phone redaction resolves the same appointment instant; all authorization roles and the inclusive two-hours-before through three-hours-after window are unchanged.

Date-only invoice labels, stored schedule fields, booking/update write semantics, and existing calendar sync code are unchanged. This patch does not run reminders, send messages, reschedule calendar events, or modify production data.

## Baseline verification

The isolated worktree starts at `e47403d59ecbd0eff8aed607e1cc0d831e1e098d`. Before edits, each modified existing production source matched the worktree SHA256:

| File | SHA256 |
| --- | --- |
| app/Services/Messaging/AutomationService.php | 4b398b4e20bbc6f6f4a50d016c69841add72234358e671fb0d7130d995709f35 |
| app/Services/InvoiceService.php | 99248c5b400bad7137de0329a406effe60b24f7a48fe5772563f265d91c2da9f |
| app/Services/Shoots/Actions/RequestCancellationAction.php | a94f8ac8fdd14618f2ecf80888165fd653f10033fc612c70741966cff03fc67c |
| app/Http/Resources/ShootResource.php | 6a56cdcb95bf8ba52958db3f8e52c21af798c680ff1693b3bc51cfed494473cf |
| app/Services/Shoots/ShootPresenter.php | b5296beb7665716e37c0786b09052d1328398ddcfc37ae3afedb71326c63dab7 |
| app/Http/Controllers/API/DashboardController.php | f4c3d7eeb98729d17aa677d24cc988d4ed912bca78c5abbf3c50e64d95a4c690 |
| app/Services/Shoots/ShootClientContactVisibility.php | 3bc227783849e241f1a5fb895b1bc356ca3f6da31ccd5dcd79eaf854ea8ff623 |
| app/Services/Shoots/ShootListingService.php | 9a0e6d659fccca950efb875b8b4ab19e3c237773b19fbd172c30f663b4382b7d |

Focused tests cover legacy and explicit schedules, summer/winter offsets, both DST transition days, UTC date crossings, service photographer overrides, the five-minute 24-hour reminder boundary, and cancellation notice/payout/API agreement at the four-hour boundary. Existing calendar, messaging, and workflow tests are included in the focused regression run.

Actual detailed API responses are checked for admin, photographer, and client roles, including role redaction ordering. Actual dashboard responses verify legacy and explicit instants while preserving the start-time field. Contact API checks cover both inclusive boundaries and one second outside each. Existing absolute-UTC reminder/cancellation fixtures now mark their timezone explicitly, preserving their original scenario under the documented storage convention.

## Validation

- Focused regression: **94 tests, 790 assertions passed** (PHP 8.3.33, PHPUnit 11.5.56), including resolver/consumer regressions and existing calendar, messaging, workflow, schedule-date, resource phone, and overview phone suites.
- After the final shoot-list photographer timezone projection, the two actual dashboard/list API cases were rerun: **2 tests, 16 assertions passed**. Both null-zone legacy and explicit-zone schedules serialize `14:00 UTC` for the intended `10:00 America/New_York` appointment.
- Both runs used the `codex-calendar-tests:php83-gd` container with network disabled, in-memory SQLite, read-only existing vendor dependencies, and an ignored synthetic testing environment. No production jobs or messages were run. Logs are in the parent output directory: `backend-focused-tests.log` and `backend-summary-tests.log`.
- `git diff --check` passed. No schema changes or data migration are needed.
