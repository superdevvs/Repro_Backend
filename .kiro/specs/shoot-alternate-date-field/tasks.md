# Implementation Plan: shoot-alternate-date-field

## Overview

This plan implements the shoot-level alternate date/time as a first-class field that is read
and written consistently across the dashboard and API, plus an explicit, operator-initiated
"apply alternate date" action. Work is ordered so the external-booking auto-mapper behavior
change (which touches existing passing tests) lands first, followed by the apply action and
endpoint, the editable-payload write path, the frontend, the cross-cutting tests, and a final
checkpoint that runs both suites.

The reused columns already exist on `shoots` (`alternate_scheduled_date`, `alternate_time`,
`alternate_scheduled_at`), are fillable/cast on the `Shoot` model, and are already serialized
by `ShootResource`, so no migration is required.

## Tasks

- [x] 1. External booking auto-mapper behavior change (case 2) and regression test updates
  - [x] 1.1 Change `ExternalBookingAutoMapper` schedule case 2 to stop mapping alternate → service #2
    - In `app/Services/ExternalBooking/ExternalBookingAutoMapper.php::map()`, remove the
      `$serviceAssignments[$secondServiceId]['scheduled_at'] = $alternate['scheduled_at']`
      assignment so the alternate is never applied to any service
    - Mark services #2..N as `unscheduledServices` regardless of alternate presence (the two
      branches collapse), leaving service #1 on the preferred date
    - Keep the `alternateSchedule` block (alternate persisted on the shoot) and the
      no-fabricated-time / `alternateDateMissingTime` rule unchanged
    - Update the class docblock decision table (cases 2/3) to reflect the new contract
    - _Requirements: 2.1, 2.3, 2.4, 9.1_

  - [x] 1.2 Update `ExternalBookingAutoMapperTest` case-2 regression test to the new contract
    - Revise `schedule_case_2_multi_service_with_alternate_maps_pref_s1_alt_s2` in
      `tests/Unit/Services/ExternalBooking/ExternalBookingAutoMapperTest.php` to assert
      service #2 `scheduled_at` is now `null`, `unscheduledServices` is `[2, 3]`, and the
      alternate is still persisted in `alternateSchedule['alternate_scheduled_at']`
    - _Requirements: 2.1, 2.3, 9.1_

  - [x] 1.3 Update `ExternalBookingWarningBuilderTest` to expect service #2 unscheduled
    - Revise `emits_unscheduled_service_warning_for_third_service_with_alternate` in
      `tests/Unit/Services/ExternalBooking/ExternalBookingWarningBuilderTest.php` so the
      "Service #2 could not be scheduled" warning is now expected alongside service #3
    - _Requirements: 2.3, 9.1_

  - [x] 1.4 Update the auto-mapper property test and notification fixtures for case 2
    - Update the auto-mapper property test's case-2 expectations (alternate no longer on
      service #2; alternate persisted on `alternateSchedule`)
    - Update any `ExternalBookingNotificationServiceTest` fixtures referencing the old
      service #2 assignment so they match the new unscheduled-#2 output
    - _Requirements: 2.3, 9.1_

- [x] 2. Checkpoint - external booking suite green on the new contract
  - Ensure all tests pass, ask the user if questions arise.

- [x] 3. Apply alternate date action and endpoint
  - [x] 3.1 Implement `ApplyAlternateDateAction`
    - Create `app/Services/Shoots/Actions/ApplyAlternateDateAction.php` with
      `execute(Shoot $shoot, string $scope, User $actor): Shoot`
    - Guard: when no stored alternate (`alternate_scheduled_date` empty), throw
      `ValidationException` before entering the transaction so no schedule changes occur
    - Inside one `DB::transaction`: set main schedule (`scheduled_date`, `time`,
      `scheduled_at`) from the stored alternate using the null-time rule (null time → null
      `scheduled_at`); retain the alternate field values unchanged
    - For `scope=all_services`, push the alternate `scheduled_at` onto every selected service
      pivot via `ShootMutationSupportService::attachServices`, preserving all other pivot
      values; for `scope=main`, build no service payload so pivots are untouched
    - Record exactly one `ShootActivityLogger::log($shoot, 'apply_alternate_date', {scope, by,
      applied_scheduled_at}, $actor)` entry; reference no `ShootRescheduleRequest`,
      `AutomationService`, `MailService`, or notification flow
    - Return a fresh shoot with `client`, `rep`, `photographer`, `services` loaded
    - _Requirements: 5.3, 5.4, 5.5, 5.6, 5.7, 5.9, 3.1, 3.3, 6.1, 6.2, 6.3, 6.4, 9.2, 9.3, 9.4, 9.5, 9.6_

  - [x] 3.2 Add `apply_alternate_date` description to `ShootActivityLogger`
    - In `app/Services/ShootActivityLogger.php::generateDescription`, add descriptions for
      `apply_alternate_date` (e.g. "Alternate date applied to main schedule" /
      "...to all services")
    - Confirm `apply_alternate_date` is NOT in `$broadcastableActions` so no realtime
      broadcast/notification fires
    - _Requirements: 5.6, 5.7, 6.2_

  - [x] 3.3 Add `ShootController::applyAlternateDate` and the route
    - Add `applyAlternateDate(Request, Shoot)` to
      `app/Http/Controllers/API/ShootController.php`: enforce role
      `admin/superadmin/editing_manager` (403 otherwise), validate
      `scope` as `nullable|in:main,all_services` defaulting to `main`, delegate to
      `ApplyAlternateDateAction`, catch `ValidationException` → 422, and return the updated
      `ShootResource`
    - Register `POST /shoots/{shoot}/apply-alternate-date` in `routes/api.php` inside the
      `auth:sanctum` group with `role:admin,superadmin,editing_manager`
    - _Requirements: 5.1, 5.2, 5.8, 8.1, 8.2_

- [x] 4. Editable payload write path persists the alternate
  - [x] 4.1 Accept and persist alternate fields in `ShootEditablePayloadService`
    - Add `alternate_scheduled_date` (`nullable|date`), `alternate_time` (`nullable|string`),
      and `alternate_scheduled_at` (`nullable|date`) to `validationRules()`
    - In `apply()`, persist the three fields; derive `alternate_scheduled_at` from date+time
      with the null-time rule (null time → null), mirroring `resolveSchedule`; touch no
      service pivots so both modify and approve flows round-trip the alternate
    - _Requirements: 4.2, 4.3, 2.4, 3.2_

- [x] 5. Checkpoint - backend apply + write path green
  - Ensure all tests pass, ask the user if questions arise.

- [x] 6. Frontend shared field, API helper, and surface wiring
  - [x] 6.1 Implement the shared `AlternateDateField` component
    - Create `src/components/shoots/AlternateDateField.tsx` reading normalized `ShootData`
      alternate fields; render nothing when `alternate_scheduled_date` is absent (low-profile)
    - Render the default "Use as main date" control (always when alternate present) and the
      secondary "Apply to all services" control only when an alternate exists AND the shoot
      has more than one service
    - On success, normalize the returned resource and refresh state via `onApplied`
    - _Requirements: 1.4, 7.1, 7.2, 7.3, 7.4, 7.5_

  - [x] 6.2 Add the `applyAlternateDate` API helper and state refresh
    - Add `applyAlternateDate(shootId, scope = 'main')` posting to
      `/shoots/{id}/apply-alternate-date` and returning the raw shoot resource
    - After success, pass the resource through `ShootsContext.normalizeShoot` and merge with
      `updateShoot(id, normalized, { skipApi: true })` so all surfaces refresh without a
      second round-trip; the default action uses `scope=main`
    - _Requirements: 4.3, 7.5_

  - [x] 6.3 Wire `AlternateDateField` into overview surfaces
    - Mount the shared field in `ShootDetailsOverviewTab.tsx` (schedule summary area) and
      below the existing Alternate row in `OverviewExternalBookingSection.tsx` (reuse, do not
      duplicate the read-only presentation)
    - _Requirements: 4.1, 1.4, 7.1, 7.2, 7.3, 7.4_

  - [x] 6.4 Wire the alternate field into approve, modify, and detail surfaces
    - In `ShootApprovalModal.tsx` (approve) and `ShootEditModal` (modify), submit
      `alternate_scheduled_date` / `alternate_time` through the existing update/approve
      payloads (now persisted by `ShootEditablePayloadService`)
    - Mount the shared field in `ShootDetailsModal.tsx`
    - _Requirements: 4.1, 4.2, 4.3_

- [x] 7. Checkpoint - frontend wiring renders and applies
  - Ensure all tests pass, ask the user if questions arise.

- [x] 8. Property and example/feature/integration tests
  - [x]* 8.1 Property test for alternate serialization formatting and emptiness
    - **Property 1: Alternate serialization formatting and emptiness**
    - **Validates: Requirements 1.2, 1.3**

  - [x]* 8.2 Property test for external booking mapping alternate to alternate field only
    - **Property 2: External booking maps the alternate to the alternate field only**
    - **Validates: Requirements 2.1, 2.3, 9.1**

  - [x]* 8.3 Property test for preferred date mapping to the main schedule
    - **Property 3: Preferred date maps to the main schedule**
    - **Validates: Requirements 2.2**

  - [x]* 8.4 Property test for the no-fabricated-time rule for the alternate
    - **Property 4: No-fabricated-time rule for the alternate**
    - **Validates: Requirements 2.4**

  - [x]* 8.5 Property test that setting the alternate never moves a service
    - **Property 5: Setting the alternate never moves a service**
    - **Validates: Requirements 3.1, 3.2**

  - [x]* 8.6 Property test for modify/approve persisting the alternate (round-trip)
    - **Property 6: Modify/approve persist the alternate (round-trip)**
    - **Validates: Requirements 4.2, 4.3**

  - [x]* 8.7 Property test for apply scope=main setting main and leaving services unchanged
    - **Property 7: Apply with scope=main sets the main schedule and leaves services unchanged**
    - **Validates: Requirements 5.4, 3.3, 9.2**

  - [x]* 8.8 Property test for apply scope=all_services setting main and every service
    - **Property 8: Apply with scope=all_services sets main and every service from the alternate**
    - **Validates: Requirements 5.5, 9.3**

  - [x]* 8.9 Property test that apply retains the alternate unchanged
    - **Property 9: Apply retains the alternate unchanged**
    - **Validates: Requirements 5.9, 9.6**

  - [x]* 8.10 Property test for exactly one activity log and no reschedule request
    - **Property 10: Apply creates exactly one activity log and no reschedule request**
    - **Validates: Requirements 5.6, 5.7, 6.1, 9.5**

  - [x]* 8.11 Property test that apply on a shoot with no alternate is rejected with no changes
    - **Property 11: Apply on a shoot with no alternate is rejected with no changes**
    - **Validates: Requirements 5.3, 9.4**

  - [x]* 8.12 Property test for the authorization gate on apply
    - **Property 12: Authorization gate on apply**
    - **Validates: Requirements 8.1, 8.2**

  - [x]* 8.13 Component property test for apply control visibility
    - **Property 13: Apply control visibility**
    - Cover the (alternate-present × service-count × role) matrix
    - **Validates: Requirements 7.1, 7.2, 7.3, 7.4**

  - [x]* 8.14 Example/feature tests for the apply endpoint
    - Assert `scope` accepts `main` and `all_services` and defaults to `main` when omitted
      (5.1/5.2); the endpoint returns the updated `ShootResource` (5.8); 422 when the shoot
      has no stored alternate (5.3); 403 for an unauthorized role (8.2)
    - _Requirements: 5.1, 5.2, 5.3, 5.8, 8.2_

  - [x]* 8.15 Integration tests for the internal-update guarantees
    - Spy/fake `AutomationService` and `Mail`/notification channels; assert apply creates NO
      `ShootRescheduleRequest`, fires NO `SHOOT_UPDATED`/`SHOOT_SCHEDULED` automation, sends
      NO email/SMS, and records exactly one `ShootActivityLog` entry
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 9.5_

  - [x]* 8.16 Frontend component tests for visibility and apply behavior
    - Test that "Use as main date" shows iff an alternate exists, "Apply to all services"
      shows iff an alternate exists AND service count > 1, the default action posts
      `scope=main`, and state refreshes from the returned resource
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5_

- [x] 9. Final checkpoint - run backend and frontend suites
  - Ensure all backend tests pass; ensure all frontend tests pass. Ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP.
- Each task references specific requirements for traceability.
- Item 1 updates existing passing tests to the deliberate case-2 behavior change and is
  sequenced first so the regression contract is locked before new code is added.
- Property tests (8.1–8.13) validate the universal correctness properties from the design;
  each is tagged **Feature: shoot-alternate-date-field, Property {n}**.
- Example, feature, and integration tests (8.14–8.16) cover specific scenarios and the
  deterministic internal-update negatives.
- No migration is required — the alternate columns already exist on `shoots`.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["1.2", "1.3", "1.4"] },
    { "id": 2, "tasks": ["3.1", "4.1"] },
    { "id": 3, "tasks": ["3.2", "3.3"] },
    { "id": 4, "tasks": ["6.1", "6.2"] },
    { "id": 5, "tasks": ["6.3", "6.4"] },
    { "id": 6, "tasks": ["8.1", "8.2", "8.3", "8.4", "8.5", "8.6", "8.7", "8.8", "8.9", "8.10", "8.11", "8.12", "8.13", "8.14", "8.15", "8.16"] }
  ]
}
```
