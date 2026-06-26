# Implementation Plan

## Overview

Bugfix workflow for `external-booking-shoot-sync`: explore the bug with a failing test, lock
in legacy behavior with a passing preservation test, then implement the additive fix and
re-run both. Property 1 (Bug Condition) and Property 2 (Preservation) are written BEFORE any
production change. The fix is additive across five areas — persistence, request schema,
mapping pipeline, controller wiring, and frontend — so the existing external site and all
legacy code paths behave exactly as today.

## Tasks

- [x] 1. Write bug condition exploration test
  - **Property 1: Bug Condition** - Conservative, Lossless External Booking Mapping
  - **CRITICAL**: This test MUST FAIL on unfixed code - failure confirms the bug exists
  - **DO NOT attempt to fix the test or the code when it fails**
  - **NOTE**: This test encodes the expected behavior - it validates the fix once it passes after implementation
  - **GOAL**: Surface counterexamples that demonstrate the bug exists on the current `bookShoot`
  - **Scoped PBT Approach**: The input domain is `(S services × P photographers × preferred/alternate presence × explicit assignments)`. Use a property-based generator over this domain, and scope deterministic sub-cases (e.g. `S=1,P=1`; `preferred_date` only) to concrete failing examples for reproducibility
  - Encode `isBugCondition(X)` from the design: selected/requested photographers, explicit `service_assignments`, alternate date/time, multi-service scheduling intent, or a preferred date with no preferred time
  - Post external payloads to `POST /api/external/book-shoot` and assert against the created shoot/pivot:
    - Single photographer + single service → pivot `photographer_id` equals the selected id (fails today: null) (2.3)
    - Multiple photographers + one service → `requested_photographers` persisted and warning recorded (fails today: column/field absent) (2.5)
    - Preferred + alternate, two services → service 2 scheduled at alternate and `alternate_scheduled_at` persisted (fails today: dropped) (2.10)
    - Preferred date only (no time) → `time`/`scheduled_at` null (fails today: stored as `00:00`) (2.12)
    - Any external booking → `external_booking_payload` and `external_booking_mapping_status` set (fails today: columns absent) (2.15, 2.16)
    - `needsReview(shoot)` → a `shoot_assignment_review` activity log row exists (2.19)
    - Shoot status is always `STATUS_REQUESTED` (2.18)
  - Run test on UNFIXED code
  - **EXPECTED OUTCOME**: Test FAILS (this is correct - it proves the bug exists)
  - Document counterexamples found (e.g. "pivot photographer_id null despite selection", "scheduled_at = YYYY-MM-DD 00:00", "missing payload/warnings/status columns")
  - Mark task complete when the test is written, run, and the failure is documented
  - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 2.3, 2.5, 2.10, 2.12, 2.15, 2.16, 2.18, 2.19_

- [x] 2. Write preservation property tests (BEFORE implementing fix)
  - **Property 2: Preservation** - Legacy Bookings Unaffected
  - **IMPORTANT**: Follow observation-first methodology
  - Observe the UNFIXED handler's output for legacy-shaped payloads first, capture it, then assert the fixed handler reproduces it
  - Observe: legacy single preferred date+time, no photographers, single service → `scheduled_at`/`scheduled_date`/`time` derived from preferred (record exact values) (3.1)
  - Observe: payload with no scheduling → `STATUS_REQUESTED` with null scheduling fields (3.2)
  - Observe: existing-fields-only payload → client find/create, pricing, coupon, `service_id`, `property_details`, `source`, payment/product status, `shoot_requested` activity log, and `ProcessExternalShootRequestedJob` dispatch (3.3, 3.4, 3.5, 3.9)
  - Observe: no photographer specified → none assigned, no default photographer (3.6)
  - Observe: existing-fields-only payload → NO `shoot_assignment_review` notification created (3.7, 3.8)
  - Write property-based tests that generate random legacy-shaped payloads (existing fields only, ≤1 service-with-time, no photographer/alternate/assignment fields) and assert the created shoot is field-for-field identical to the observed pre-fix behavior
  - Run tests on UNFIXED code
  - **EXPECTED OUTCOME**: Tests PASS (this confirms the baseline behavior to preserve)
  - Mark task complete when tests are written, run, and passing on unfixed code
  - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9_

- [x] 3. Persistence: add nullable external-booking columns to shoots

  - [x] 3.1 Create the migration
    - New migration `xxxx_add_external_booking_columns_to_shoots_table.php` adding seven nullable columns: `alternate_scheduled_date` (date), `alternate_time` (string), `alternate_scheduled_at` (datetime), `requested_photographers` (json), `external_booking_payload` (json), `external_booking_warnings` (json), `external_booking_mapping_status` (string)
    - Wrap each column add in a `Schema::hasColumn` guard (matching `2026_02_22_140000_add_photographer_id_to_shoot_service_table.php` style) so re-runs are safe
    - Provide a matching `down()` that drops the columns under `hasColumn` guards
    - All columns nullable for backward compatibility
    - _Requirements: 2.15, 2.16, 3.9_

  - [x] 3.2 Update the Shoot model
    - Add to `$fillable`: `alternate_scheduled_date`, `alternate_time`, `alternate_scheduled_at`, `requested_photographers`, `external_booking_payload`, `external_booking_warnings`, `external_booking_mapping_status`
    - Add to `$casts`: `alternate_scheduled_date => date`, `alternate_scheduled_at => datetime`, `requested_photographers => array`, `external_booking_payload => array`, `external_booking_warnings => array` (leave `alternate_time` and `external_booking_mapping_status` as plain strings)
    - Add `MAPPING_STATUS_FULLY_MAPPED`, `MAPPING_STATUS_PARTIALLY_MAPPED`, `MAPPING_STATUS_NEEDS_REVIEW` constants mirroring the existing status-constant style
    - Add a unit test asserting the new columns are fillable and cast correctly (json↔array, date/datetime)
    - _Requirements: 2.15, 2.16, 3.9_

- [x] 4. Request schema: accept the new optional fields
  - In `app/Http/Requests/ExternalBookingRequest::rules()` add nullable/sometimes rules without touching existing rules: `alternate_date` (nullable|date), `alternate_time` (nullable|string|max:10), `selected_photographer_id` and `photographer_id` (nullable|integer|exists:users,id), `selected_photographers[]` and `requested_photographers[]` (nullable|array of integer|exists:users,id), and `service_assignments[]` with `service_id` (required_with|exists:services,id), `photographer_id` (nullable|exists:users,id), `scheduled_date` (nullable|date), `scheduled_time` (nullable|string|max:10)
  - Add unit tests: new rules accept valid values, reject invalid types (non-integer photographer id, unparseable date, malformed `service_assignments` entry), and a legacy existing-fields-only payload still validates unchanged
  - _Requirements: 2.1, 3.7_

- [x] 5. Mapping pipeline: DTO, normalizer, auto-mapper, warnings, notification (with unit tests)

  - [x] 5.1 ExternalBookingData DTO
    - Create `App\Services\ExternalBooking\Data\ExternalBookingData` as an immutable carrier of validated input plus the raw payload, with `fromRequest(ExternalBookingRequest $request): self`
    - Capture `rawPayload`, `services`, `preferredDate`, `preferredTime`, `alternateDate`, `alternateTime`, `requestedPhotographerIds`, `serviceAssignments`, `source`
    - Unit test `fromRequest` builds the expected shape from a representative request (and preserves the raw payload for provenance)
    - _Requirements: 2.1, 2.15_

  - [x] 5.2 ExternalBookingScheduleNormalizer
    - Create `App\Services\ExternalBooking\ExternalBookingScheduleNormalizer::normalize($data): NormalizedBooking` producing `{preferred:{date,time}, alternate:{date,time}, requested_photographers:[], selected_services:[], service_assignments:[]}`
    - Resolve photographer aliases (`selected_photographer_id` vs `photographer_id`, `selected_photographers` vs `requested_photographers`), de-duplicate ids, preserve ordered `selected_services` and explicit `service_assignments`
    - Unit tests: alias resolution, de-duplication, empty/absent inputs
    - _Requirements: 2.2_

  - [x] 5.3 ExternalBookingAutoMapper
    - Create `App\Services\ExternalBooking\ExternalBookingAutoMapper::map(NormalizedBooking $booking): MappingResult` as a pure function (no DB writes)
    - If explicit `service_assignments` present, apply them directly per service and skip inference (2.8)
    - Photographer decision table cases A–E (A: S=1,P=1 assign pivot + legacy; B: S=1,P=0 unassigned; C: S=1,P>1 unassigned + flag; D: S>1,P=1 assign first only if `isPhotographerEligibleForService` returns true, default false → unassigned; E: S>1,P>1 leave all null) (2.3–2.7)
    - Schedule decision table cases 1–4 (1: S=1 preferred→service + shoot-level; 2: S>1 with alt → preferred→s1, alternate→s2, s3+ unscheduled; 3: S>1 preferred only → s1 only, never copy to all; 4: explicit assignments → per-service schedules) (2.9, 2.10, 2.11, 2.8)
    - No-fabricated-time rule via `resolveSchedule(date,time)` at both shoot and pivot level: date empty → all null; time empty → date kept, time/scheduled_at null + set `dateMissingTime` flag; both present → combine (2.12, 2.13, 2.14)
    - Persist full normalized `requested_photographers` regardless of case; set `legacyPhotographerId` only in case A
    - Derive `mappingStatus` (`fully_mapped`/`partially_mapped`/`needs_review`); a date-only preference forces at least `partially_mapped` (2.16)
    - Emit `flags` (`multiplePhotographersForOneService`, `unmappablePhotographers`, `unscheduledServices[]`, `preferredDateMissingTime`, `alternateDateMissingTime`)
    - Unit + property tests: photographer cases A–E and schedule cases 1–4 as a decision table; no-fabricated-time for preferred and alternate; explicit-assignment bypass; mapping-status derivation; property that no photographer assignment is ever fabricated and preferred schedule is never copied onto services beyond the first in case 3
    - _Requirements: 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 2.10, 2.11, 2.12, 2.13, 2.14, 2.16_

  - [x] 5.4 ExternalBookingWarningBuilder
    - Create `App\Services\ExternalBooking\ExternalBookingWarningBuilder::build($normalized, $result): array` turning mapper flags into the exact warning strings from the design catalog
    - Unit tests: each warning string emitted for the right flag combination, and no warnings when fully mapped
    - _Requirements: 2.5, 2.6, 2.7, 2.10, 2.11, 2.12, 2.13_

  - [x] 5.5 ExternalBookingNotificationService
    - Create `App\Services\ExternalBooking\ExternalBookingNotificationService::notifyIfNeeded(Shoot $shoot, MappingResult $result, array $warnings): bool`
    - `needsReview` = source is external AND (any pivot photographer/schedule unassigned OR schedules auto-mapped OR multiple photographers requested OR warnings non-empty OR mappingStatus IN {needs_review, partially_mapped})
    - When review is needed, create a `ShootActivityLog` row with `action = 'shoot_assignment_review'`, `shoot_id`, `description` = message, and `metadata` `{type, shoot_id, title, message, action_type: 'open_shoot_details_popup', action_payload:{shoot_id, focus:'schedule_assignments'}}`
    - Unit tests: `needsReview` truth table; notification created/not created accordingly; payload shape (`type`, `shoot_id`, `title`, `message`, `action_type`, `action_payload`)
    - _Requirements: 2.19, 2.20_

- [x] 6. Controller: wire collaborators into a thin bookShoot

  - [x] 6.1 Refactor bookShoot to orchestrate the pipeline
    - Inject the new collaborators; build the DTO, run normalize → map → warnings
    - Replace `$time = $validated['preferred_time'] ?? '00:00';` with the mapping result's shoot-level schedule (null time/`scheduled_at` when no time, 2.12)
    - Replace `'photographer_id' => null` with `$mapping->legacyPhotographerId` (null unless case A)
    - Persist the new metadata columns on `Shoot::create` (`alternate_*`, `requested_photographers`, `external_booking_payload`, `external_booking_warnings`, `external_booking_mapping_status`); keep `status => STATUS_REQUESTED`
    - Add `buildServicesPayload($normalized, $mapping)` to inject per-service `photographer_id`/`scheduled_at` (null where unsafe) and pass into the existing `attachServices`
    - Call `notificationService->notifyIfNeeded($shoot, $mapping, $warnings)` AFTER the transaction commits, wrapped in try/catch with `Log::warning` so a notification failure never rolls back the booking
    - Keep all existing client/pricing/coupon/activity-log/job-dispatch logic verbatim
    - _Bug_Condition: isBugCondition(X) from design (selected/requested photographers, explicit service_assignments, alternate date/time, multi-service scheduling intent, or preferred date without time)_
    - _Expected_Behavior: conservative per-service mapping, no fabricated time, persisted payload/warnings/status, STATUS_REQUESTED, notification when review needed (expectedBehavior from design)_
    - _Preservation: client find/create, pricing, service attachment with catalog prices, legacy service_id/property_details/source/payment/product status, shoot_requested log, ProcessExternalShootRequestedJob dispatch unchanged_
    - _Requirements: 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 2.10, 2.11, 2.12, 2.13, 2.14, 2.15, 2.16, 2.17, 2.18, 2.19_

  - [x] 6.2 Expose the new columns in ShootResource
    - Add `alternate_scheduled_date`, `alternate_time`, `alternate_scheduled_at`, `requested_photographers`, `external_booking_payload`, `external_booking_warnings`, `external_booking_mapping_status` to `ShootResource` so the popup can render them
    - _Requirements: 2.22_

  - [x] 6.3 Verify bug condition exploration test now passes
    - **Property 1: Expected Behavior** - Conservative, Lossless External Booking Mapping
    - **IMPORTANT**: Re-run the SAME test from task 1 - do NOT write a new test
    - The test from task 1 encodes the expected behavior; when it passes, the fix is confirmed
    - Run the bug condition exploration test from step 1
    - **EXPECTED OUTCOME**: Test PASSES (confirms the bug is fixed)
    - _Requirements: 2.3, 2.5, 2.10, 2.12, 2.15, 2.16, 2.18, 2.19_

  - [x] 6.4 Verify preservation tests still pass
    - **Property 2: Preservation** - Legacy Bookings Unaffected
    - **IMPORTANT**: Re-run the SAME tests from task 2 - do NOT write new tests
    - Run the preservation property tests from step 2
    - **EXPECTED OUTCOME**: Tests PASS (confirms no regressions)
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9_

- [x] 7. Frontend: review experience

  - [x] 7.1 Types + notification metadata and title
    - `frontend/src/types/dashboard.ts`: add optional `openShootFocus?: 'schedule_assignments'` to `DashboardShootModalNavigationState` and optional `metadata?: Record<string, unknown>` to `DashboardActivityItem`
    - `frontend/src/hooks/useNotifications.ts`: in `normalizeActivity` forward `metadata` onto the `NotificationItem`, and add `shoot_assignment_review: 'Booking Needs Review'` to `SHOOT_ACTIVITY_TITLES`
    - _Requirements: 2.20, 2.21_

  - [x] 7.2 NotificationCenter click handler opens the shoot details modal focused on assignments
    - In `frontend/src/components/notifications/NotificationCenter.tsx` `handleNotificationClick`: when `notification.action === 'shoot_assignment_review'` (or `metadata.action_type === 'open_shoot_details_popup'`), mark the notification read, then navigate to `/dashboard` with `DashboardShootModalNavigationState { openShootId, openShootTab: 'overview', openShootFocus: 'schedule_assignments' }`
    - Reuse the existing modal-open-via-navigation-state mechanism; the modal reads `openShootFocus` and scrolls/expands the photographer/schedule assignment section and displays the recorded warnings
    - _Requirements: 2.21_

  - [x] 7.3 "External Booking Mapping" panel in the shoot details modal overview tab
    - When the loaded shoot has a non-null `external_booking_payload` (or `external_booking_mapping_status`), render an "External Booking Mapping" panel in the overview tab showing: preferred schedule (`scheduled_date` + `time`) and alternate schedule (`alternate_scheduled_date` + `alternate_time`) with "time not specified" when null; auto-mapped services with per-service photographer + schedule from the pivot; requested photographers resolved to names; warnings (`external_booking_warnings`) and the mapping-status badge
    - _Requirements: 2.22_

- [x] 8. Integration tests for the full POST /api/external/book-shoot flow
  - For each photographer case (A–E) and schedule case (1–4): post the payload and assert the persisted shoot, pivot rows, metadata columns, and (when review is needed) the `shoot_assignment_review` `ShootActivityLog` row
  - A needs-review booking → `GET /api/notifications` (or the dashboard notifications endpoint) as an admin returns the notification with the correct `action_type`/`action_payload` metadata and role visibility
  - Backward-compat: a legacy existing-fields-only payload produces the same response and shoot as before, with NO notification created
  - Optional frontend integration: clicking the notification opens the shoot details modal focused on the schedule/assignment section and renders the "External Booking Mapping" panel with warnings
  - _Requirements: 2.17, 2.19, 2.20, 2.21, 2.22, 3.7_

- [x] 9. Verification - fix-checking and preservation-checking pass end to end
  - Re-run the Property 1 fix-checking suite over `isBugCondition(X)` inputs (conservative photographer/schedule mapping, no fabricated time, persisted payload/requested_photographers/status, notification when review needed, `STATUS_REQUESTED`)
  - Re-run the Property 2 preservation suite over `NOT isBugCondition(X)` inputs (fixed == original)
  - Run the property-based suites: no fabricated photographer assignment, no copy-to-all schedule in case 3, `scheduled_at` null whenever time empty (no-midnight invariant)
  - _Requirements: 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 2.10, 2.11, 2.12, 2.13, 2.14, 2.15, 2.16, 2.17, 2.18, 2.19, 2.20, 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9_

- [x] 10. Checkpoint - Ensure all tests pass
  - Run the full backend test suite (unit + property + integration) and the frontend tests
  - Ensure all tests pass; ask the user if questions arise

## Task Dependency Graph

- Tasks 1–2 (exploration + preservation tests) run on the UNFIXED code first; task 1 must
  fail and task 2 must pass before any production change.
- Task 3 (persistence) and Task 4 (request schema) are independent of each other.
- Task 5 (mapping pipeline) depends on Task 4 (DTO reads validated request fields).
- Task 6 (controller wiring) depends on Tasks 3, 4, and 5.
- Task 7 (frontend) depends on Task 6 (controller + `ShootResource` exposing new columns).
- Task 8 (integration tests) depends on the full backend (Tasks 3–6).
- Task 9 (verification) re-runs Property 1 and Property 2 after Tasks 6–8.
- Task 10 (checkpoint) is last.

```
1 (explore, fails) ─┐
2 (preserve, passes)─┤
                     ▼
        3 (persistence) ──┐
        4 (request schema)─┤
                           ▼
                  5 (mapping pipeline)
                           ▼
                  6 (controller wiring) ──► 6.3 verify Property 1 / 6.4 verify Property 2
                           ▼
                  7 (frontend)
                           ▼
                  8 (integration tests)
                           ▼
                  9 (verification) ──► 10 (checkpoint)
```

```json
{
  "waves": [
    { "wave": 1, "tasks": ["1", "2"], "dependsOn": [] },
    { "wave": 2, "tasks": ["3", "4"], "dependsOn": ["1", "2"] },
    { "wave": 3, "tasks": ["5"], "dependsOn": ["4"] },
    { "wave": 4, "tasks": ["6"], "dependsOn": ["3", "5"] },
    { "wave": 5, "tasks": ["7"], "dependsOn": ["6"] },
    { "wave": 6, "tasks": ["8"], "dependsOn": ["6"] },
    { "wave": 7, "tasks": ["9"], "dependsOn": ["7", "8"] },
    { "wave": 8, "tasks": ["10"], "dependsOn": ["9"] }
  ]
}
```

## Notes

- Tasks 1 and 2 are standalone property-based tests that MUST be authored and run against the
  unfixed code before implementation begins.
- The fix is purely additive: every new request field is optional/nullable and every new
  shoot column is nullable, preserving full backward compatibility (Requirements 3.1–3.9).
- The `ExternalBookingAutoMapper` is a pure function (no DB writes), making the photographer
  cases A–E and schedule cases 1–4 exhaustively unit/property testable.
- `notifyIfNeeded` runs after the transaction commits and is wrapped in try/catch so a
  notification failure never rolls back the booking.
- No `shoot_service` migration is needed — the pivot already supports `photographer_id` and
  `scheduled_at`; the fix only provides those keys where safely mapped.
