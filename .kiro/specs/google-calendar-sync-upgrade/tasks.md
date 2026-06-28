# Implementation Plan: Google Calendar Sync Upgrade

## Overview

The work is confined to two service classes plus config and tests. We start with the
config knob the description link depends on, then rebuild the `GoogleCalendarEventPayloadBuilder`
internals incrementally (title → description → derivation → color/reminders → per-service
timing), then adjust `GoogleCalendarShootSyncService` for cancelled-shoot keep-and-update
and the broadened fingerprint. Tests are written alongside the code they cover. The public
surface (`build(Shoot, ?User): array`, `syncShoot()`) is unchanged; only internals move.

## Tasks

- [x] 1. Add dashboard URL config (and optional mirror flag)
  - In `config/services.php`, under the existing `google.calendar` block, add
    `'dashboard_url' => env('DASHBOARD_URL', 'https://reprodashboard.com')`
  - Add `'mirror_sync_status' => env('GOOGLE_CALENDAR_MIRROR_SYNC_STATUS', false)` for the
    optional Req 10.4 hook (defaults off; no shoot write when off)
  - _Requirements: 3.11, 10.4_

- [x] 2. Rebuild title and client/cancellation helpers in `GoogleCalendarEventPayloadBuilder`
  - [x] 2.1 Implement `isCancelled(Shoot): bool` and client helpers
    - `isCancelled()` returns true when lowercased `status` or `workflow_status` equals
      `Shoot::STATUS_CANCELLED` (`'cancelled'`)
    - `clientName(Shoot)` = trimmed `client?->name`, falling back to `client?->company_name`,
      then `"Client"`
    - `clientPhone(Shoot)` = `client?->phone ?: client?->phonenumber`; `clientEmail(Shoot)` = `client?->email`
    - _Requirements: 1.1, 1.2, 8.2_
  - [x] 2.2 Implement `buildTitle(Shoot)` and wire into `build()`
    - Non-cancelled → `"{clientName}"`; cancelled → `"CANCELLED - {clientName}"`
    - Exclude service names, identifiers, status, photographer names, internal labels
    - Set `summary` key from `buildTitle()`
    - _Requirements: 1.1, 1.2, 1.3, 8.2_
  - [x] 2.3 Write property test for title
    - **Property 1: Title is client name, cancelled is prefixed**
    - **Validates: Requirements 1.1, 1.2, 1.3, 8.2**

- [x] 3. Implement derivation helpers (no schema change)
  - [x] 3.1 Implement `derivePropertyAccess`, `deriveArrivalInstructions`, `deriveOnSiteContact`
    - Property Access: customer-facing note text (`shoot_notes` → `notes`); `null` when empty
    - Arrival Instructions: `photographer_notes` when present, else customer-facing note text
    - On-Site Contact: always falls back to `"{clientName} ({phone}, {email})"` with missing
      parts dropped; `Not provided` only when client name is also empty
    - Helpers never throw on sparse shoots
    - _Requirements: 3.8, 3.9, 3.10, 3.13_

- [x] 4. Rebuild plain-text description in `GoogleCalendarEventPayloadBuilder`
  - [x] 4.1 Implement `buildDescription(Shoot): ?string` with exact section ordering
    - Order: client name, optional `Phone:`/`Email:`, blank line, `Shoot Services:` (one
      `- {label}` per service via `formatServiceLabel()`), optional `Service Timing:` block
      (task 6), optional `Shoot Status: Cancelled`, `Shoot Notes:`, `Property Access:`,
      `Arrival Instructions:`, `On-Site Contact:`, and `View shoot: {base}/shoots/{id}` last
    - Omit `Phone:`/`Email:` lines when missing; render `Not provided` for empty named sections
    - Build internal link from `config('services.google.calendar.dashboard_url', ...)` (rtrim '/')
    - Exclude pricing, payment status, `company_notes`, `editor_notes`, `admin_issue_notes`
    - Wire `description` key in `build()`
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9, 3.10, 3.11, 3.12, 8.2_
  - [x] 4.2 Write property test for description section rendering
    - **Property 2: Description omits empty phone/email lines but always renders named sections**
    - **Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.7, 3.8, 3.9, 3.10**
  - [x] 4.3 Write property test for internal link as final line
    - **Property 3: Internal shoot link is always the final line**
    - **Validates: Requirements 3.11**
  - [x] 4.4 Write property test for no internal/financial data leak
    - **Property 4: Description excludes internal/financial data**
    - **Validates: Requirements 3.12, 3.13**

- [x] 5. Implement color mapping, reminders, and confirm duration
  - [x] 5.1 Implement `resolveColorId(Shoot)` and `buildReminders()`; wire into `build()`
    - `const STATUS_COLOR_MAP` per design (scheduled→"9", cancelled/declined→"11", etc.) with
      default `"9"`; all values within Google `"1"`–`"11"`
    - `buildReminders()` returns `useDefault=false` with popup overrides at 1440 and 30 minutes
    - Confirm `end` = `start + calculateShootDurationFromShoot()` (clamped 60–240, default 120);
      add the documenting comment, no behavior change
    - _Requirements: 4.1, 4.2, 5.1, 6.1_
  - [x] 5.2 Write property/unit tests for colorId, reminders, and duration
    - **Property 7: colorId is a supported value determined by status**
    - **Property 6: Reminders are explicit 24h and 30min popups**
    - **Property 5: End time equals start plus clamped duration**
    - **Validates: Requirements 4.1, 4.2, 5.1, 6.1**

- [x] 6. Implement optional per-service timing block
  - [x] 6.1 Implement `buildPerServiceTimingBlock(Shoot, timezone): ?string`
    - Compute distinct effective `scheduled_at` (service item `scheduled_at` ?? shoot
      `scheduled_at`); return `null` when `<= 1` distinct value
    - Otherwise render `Service Timing:` with one `- {serviceName}: {time in timezone}` line
    - Insert the block into the description at the position defined in task 4
    - _Requirements: 7.1, 7.2_
  - [x] 6.2 Write property test for per-service timing block
    - **Property 8: Per-service timing block appears iff schedules differ**
    - **Validates: Requirements 7.1, 7.2**

- [x] 7. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 8. Cancelled shoots: keep-and-update in `GoogleCalendarShootSyncService`
  - [x] 8.1 Make cancelled shoots syncable-with-cancel-state
    - Remove `STATUS_CANCELLED` from the non-syncable status list in `isSyncable()` (requested
      / declined / on_hold / hold_on stay non-syncable and continue to delete)
    - Confirm `syncShoot()` then follows the existing update-not-delete path for cancelled
      shoots (mapping check → update); leave `removeShoot()` and `disconnectUser()` unchanged
    - _Requirements: 8.1, 8.2_
  - [x] 8.2 Write example test for cancelled keep-and-update
    - Cancelled shoot is treated as syncable and produces an update payload (title prefixed,
      `Shoot Status: Cancelled` present) rather than a delete; mock `GoogleCalendarService`
    - _Requirements: 8.1, 8.2_

- [x] 9. Broaden sync fingerprint and preserve check-before-create
  - [x] 9.1 Implement canonical signature fingerprint in `fingerprintFor()`
    - Build the canonical signature array (client name/phone/email, address, scheduled_at,
      photographer, services, service_times, notes, photographer_notes, status,
      workflow_status, cancelled, calendar_id); hash via `sha1(json_encode(...))`
    - Apply the same fingerprint to the per-service-item path (`syncServiceItemEvent`)
    - Keep comparison logic: recompute, compare to `mapping->sync_fingerprint`, skip HTTP when
      matched and `calendar_id` unchanged; store new fingerprint via existing `updateOrCreate`
    - _Requirements: 9.1, 9.2, 9.3, 10.1, 10.2, 10.3_
  - [x] 9.2 Write property test for fingerprint change detection
    - **Property 9: Fingerprint changes iff a tracked field changes**
    - **Validates: Requirements 9.1, 9.2, 9.3**
  - [x] 9.3 Write property test for single-mapping idempotency
    - **Property 10: One mapping per shoot/photographer (no duplicates)**
    - Re-running identical state yields identical fingerprint and no duplicate create; mock
      `GoogleCalendarService`
    - **Validates: Requirements 10.1, 10.2, 10.3**

- [x] 10. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional test sub-tasks and can be skipped for a faster MVP.
- Implementation is confined to `GoogleCalendarEventPayloadBuilder.php`,
  `GoogleCalendarShootSyncService.php`, and `config/services.php`; tests live under
  `tests/Unit` / `tests/Feature` following the `*PropertyTest.php` convention.
- Property tests run a minimum of 100 generated inputs and mock `GoogleCalendarService`
  (no live HTTP). Tag: `Feature: google-calendar-sync-upgrade, Property {n}`.
- No schema changes; Property Access / Arrival Instructions / On-Site Contact derive from
  existing fields and may render identical text by design.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "3.1"] },
    { "id": 1, "tasks": ["2.1"] },
    { "id": 2, "tasks": ["2.2"] },
    { "id": 3, "tasks": ["2.3", "4.1"] },
    { "id": 4, "tasks": ["4.2", "4.3", "4.4", "5.1"] },
    { "id": 5, "tasks": ["5.2", "6.1"] },
    { "id": 6, "tasks": ["6.2", "8.1"] },
    { "id": 7, "tasks": ["8.2", "9.1"] },
    { "id": 8, "tasks": ["9.2", "9.3"] }
  ]
}
```
