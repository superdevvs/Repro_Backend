# Implementation Plan: Photographer Onboarding QA

## Overview

This plan extends the existing Playwright suite at `frontend/e2e/` with a shared QA harness, a
set of per-domain spec modules, and a report-and-fix remediation loop that verify the full
photographer onboarding journey end-to-end (primarily against production, read-mostly, with
per-step confirmation gating). The harness is built **first** because every domain spec depends on
it. The cleanup spec is scheduled **last** so it can observe and remove every `QA_Entity` created
during the run. Each spec module references the granular requirement clauses it covers, and each of
the 15 design correctness properties becomes its own property-based test sub-task.

Per Requirement 23, **Properties 1–5 are CORE and REQUIRED** (gate gating, run-scoped cleanup,
distance-gating monotonicity, settings effect, role-access denial). They are NOT marked optional.
The remaining property tests (Properties 6–15) are marked optional with `*`.

**Item totals (so the count is explicit):**
- 24 top-level items: 21 work epics + 3 checkpoints.
- 48 leaf sub-tasks total.
- 33 implementation sub-tasks: 13 harness components + 1 config/wiring smoke + 17 per-domain spec
  modules + 1 cleanup module + 1 report-and-fix loop.
- 15 property-based test sub-tasks (one per design Property 1–15).
  - 5 CORE/REQUIRED (Properties 1–5, no `*`).
  - 10 optional (Properties 6–15, marked `*`).
- 18 per-domain spec modules covered: cubicasa, account-creation, approval-workflow,
  profile-completeness, service-radius, calendar-availability, admin-override, booking-lifecycle,
  shoot-workflow, selectors, ui-consistency, multi-role, negative-permissions, notifications,
  invoicing-reporting, equipment, settings, and cleanup (last).

Only sub-tasks marked `*` are optional. All implementation sub-tasks, the CORE property tests
(1–5), and the checkpoints are required.

## Tasks

- [x] 1. Build the shared QA harness (`frontend/e2e/helpers/onboarding-qa/`)
  - [x] 1.1 Implement environment resolution (`env.ts`)
    - Resolve `E2E_BASE_URL`, `E2E_API_BASE_URL`, `E2E_NO_SERVER`, `E2E_ADMIN_EMAIL`,
      `E2E_ADMIN_PASSWORD`, `E2E_PREVIEW_STORAGE_STATE`, `E2E_QA_RUN_ID`,
      `E2E_EXTERNAL_BOOKING_API_KEY`
    - Resolve notification-sink vars `E2E_NOTIFICATION_MODE`, `E2E_EMAIL_MODE`, `E2E_SMS_MODE`,
      `E2E_VOICE_MODE`, and the gate allow-flags `E2E_CONFIRM_DESTRUCTIVE`, `E2E_CONFIRM_CHARGE`,
      `E2E_CONFIRM_MESSAGE`, `E2E_CONFIRM_CATEGORIES`, plus `E2E_SEEDED_ADDRESS_SET`
    - Match `README.md` defaults exactly; `apiBaseUrl = E2E_API_BASE_URL ?? E2E_BASE_URL ?? default`;
      honor the managed-server toggle without changing `playwright.config.ts` branch logic
    - Export `resolveQaEnv(): QaEnv`
    - _Requirements: 1.4, 1.5, 1.6_

  - [x] 1.2 Implement the run-id data factory (`data-factory.ts`)
    - `name`/`email`/`address` append the `E2E_QA_RUN_ID` suffix (email keeps a valid
      `local@domain` shape, e.g. `client.qa.{RUN_ID}@example.test`)
    - Implement `belongsToRun(value)` for run-scoped cleanup selection
    - Export `createDataFactory(runId): DataFactory`
    - _Requirements: 1.7_

  - [x]* 1.3 Write property test for run-id suffixing of generated data
    - **Property 6: Run-id suffixing of generated data**
    - **Validates: Requirements 1.7, 5.1, 5.2, 19.1**

  - [x] 1.4 Implement the confirmation gate (`confirmation-gate.ts`)
    - Single choke point for `destructive`/`charge`/`message` steps; defaults to **declined**
      (read-only by default) using the per-category env allow-flags and `E2E_CONFIRM_CATEGORIES`
    - `run(step)`: prefer `nonChargingPath`; else execute `action` only when confirmed; else return
      `skipped` and let the report record the skip
    - _Requirements: 2.1, 2.2, 2.4, 2.5, 18.11, 18.12, 21.2_

  - [x] 1.5 Write property test for the confirmation gate (CORE — Requirement 23.1)
    - **Property 1: The confirmation gate gates execution**
    - **Validates: Requirements 2.2, 2.3, 2.4, 2.5, 18.11, 18.12, 21.2, 23.1**

  - [x] 1.6 Implement the run-scoped entity tracker (`entity-tracker.ts`)
    - `track(type, id, label)` across ALL `QA_Entity` types (accounts, shoots, bookings, raw/edited
      files, CubiCasa orders/references, equipment, assignments, invoices, reminder records,
      notification logs, clients, addresses, availability/blocked windows, reports)
    - `belongingToRun(factory)` selects exactly the entities whose identifier carries the run-id
    - Export `createEntityTracker(runId): EntityTracker`
    - _Requirements: 21.1_

  - [x] 1.7 Write property test for run-scoped cleanup selection (CORE — Requirement 23.2)
    - **Property 2: Run-id run-scoped cleanup selects exactly the run's entities across all types**
    - **Validates: Requirements 21.1, 21.3, 21.4, 23.2**

  - [x] 1.8 Implement the evidence-backed report collector (`report.ts`)
    - One entry per check; record `pass`/`fail`/`blocked`/`skipped`; a `pass` requires associated
      evidence; support continue-on-failure and `override` (latest result wins) for re-runs
    - Emit the full bundle (Markdown + JSON + screenshots + trace + video-on-failure + console/
      network logs + API excerpts + created-entity IDs + cleanup status) with a green/yellow/red
      summary under `../output/playwright/`
    - _Requirements: 22.1, 22.2, 22.3, 22.4, 22.6, 21.5_

  - [x]* 1.9 Write property test for continue-on-failure
    - **Property 8: Continue-on-failure**
    - **Validates: Requirements 22.4**

  - [x]* 1.10 Write property test for re-run result override
    - **Property 9: Re-run result override**
    - **Validates: Requirements 22.5, 22.6**

  - [x]* 1.11 Write property test for report completeness and evidence association
    - **Property 10: Report completeness and evidence association**
    - **Validates: Requirements 22.1, 22.2, 22.3, 21.5**

  - [x] 1.12 Implement the stable-selector resolver (`selectors.ts`)
    - Resolve onboarding-critical elements by `data-testid`; expose the `REQUIRED_TESTIDS` contract
    - `byTestId(page, testId, checkId)` records a `Blocked_Check` and returns null when absent
      (no brittle text/CSS/layout fallback)
    - _Requirements: 13.1, 13.2, 13.3, 13.4_

  - [x]* 1.13 Write property test for missing-data and missing-selector blocked-and-continue
    - **Property 7: Missing-data and missing-selector yield blocked-and-continue**
    - **Validates: Requirements 2.6, 8.13, 13.4**

  - [x] 1.14 Implement the fixed personas (`personas.ts`)
    - Define the exact persona set: `admin.qa`; `client.qa`; Photographer A (HDR, Floor Plan,
      Drone; radius 25mi; Mon–Fri 09:00–17:00); Photographer B (radius 5mi, outside-radius);
      Photographer C (Video specialty only); photo editor; video editor; editing manager; optional
      sales rep
    - Export `PERSONAS: PersonaSpec[]`
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7_

  - [x] 1.15 Implement test-data provisioning (`test-data.ts`)
    - Provision the persona set once per run, each suffixed by the data factory and registered with
      the entity tracker as a `QA_Entity`; provisioning is a `Destructive_Step` routed through the gate
    - Export `createTestData(env, factory, gate, tracker): TestData`
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 1.7_

  - [x] 1.16 Implement the seeded-address provider (`seeded-address.ts`)
    - Expose `inside`/`boundary`/`outside` fixtures per photographer plus zero/empty/very-large
      radius and a multi-eligible/tie-breaker fixture, each with fixed lat/long (Truth Table T1/T2)
    - Export `createAddressFixtures(env, factory): AddressFixtures`
    - _Requirements: 8.2_

  - [x] 1.17 Implement the multi-role contexts (`contexts.ts`)
    - Create up to seven independent `BrowserContext` sessions (admin, photographer, client, photo
      editor, video editor, editing manager, optional/lazy sales rep) authenticated via
      `helpers/auth.ts`; expose per-role page + API request context + bearer token; provide
      `asPhotographer(A/B/C)`, `ensureSalesRep()`, and `dispose()`
    - Export `createRoleContexts(browser, env, data): RoleContexts`
    - _Requirements: 15.1, 15.2_

  - [x] 1.18 Implement the notification-sink reader (`notification-sink.ts`)
    - Read `Notification_Record`s when sink modes are active (`log`/`disabled`); support filtering
      by recipient/template/variables/channel and `assertNoLiveSend()`
    - Export `createNotificationSink(env): NotificationSink`
    - _Requirements: 17.1_

  - [x] 1.19 Implement the UI-consistency probe (`ui-probe.ts`)
    - Capture the measurable signal set (console errors, allowed-list-aware failed requests, React
      crash boundary, mobile horizontal overflow, empty state, duplicate primary buttons, status
      badge text, hidden required fields, stale data, action feedback)
    - Run signals across the four viewports (Desktop 1440x900, Laptop 1280x800, Tablet 768x1024,
      Mobile 390x844) via `probeAllViewports`
    - _Requirements: 14.1, 14.2, 14.3, 14.4, 14.5, 14.6, 14.7, 14.8, 14.9, 14.10, 14.11_

  - [x] 1.20 Implement backend fixture wrappers (`backend-fixtures.ts`)
    - Document/wrap the existing artisan commands per the design table (seed availability/blocked/
      addresses/previous-shoot/onboarding; CubiCasa webhook/sync; invoicing/reporting commands)
    - Introduce no new backend commands
    - _Requirements: 3.x, 5.x, 8.x, 9.x, 12.x, 18.x, 20.x (fixture arrangement only)_

- [x] 2. Add configuration and spec-wiring smoke checks
  - [x] 2.1 Add config/wiring smoke checks
    - Assert new specs match `**/*.e2e.ts`, run under the single chromium project, authenticate via
      `frontend/e2e/helpers/auth.ts`, and execute via `npm run test:e2e` from `frontend/`
    - Verify documented env resolution and the managed-server toggle path (`E2E_NO_SERVER` /
      `E2E_BASE_URL`)
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6_

- [x] 3. Checkpoint - harness ready
  - Ensure all harness checks pass and produce evidence; verify any blocked/skipped items are
    recorded with their missing dependency noted; do NOT block on human input — continue.

- [x] 4. Implement CubiCasa spec module (`onboarding/cubicasa.e2e.ts`)
  - [x] 4.1 Implement CubiCasa visibility, ordering, idempotency, and recovery checks
    - Floor-plan-gated visibility of the create-order control (present with Floor Plan, omitted
      without); manual order (charge/message → gated) records exactly one pending order; repeated
      activation creates no additional order; recoverable error state; unlinked-order warning; safe
      resync `pending→synced` via `ResyncPendingCubiCasaCommand`; webhook-callback status update;
      missing-credentials **blocked** and provider-disabled **skipped/blocked** states; screenshot
      the order state (Truth Table T7/T8)
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8, 4.9, 4.10, 4.11_

  - [x]* 4.2 Write property test for CubiCasa order idempotency
    - **Property 12: CubiCasa order idempotency**
    - **Validates: Requirements 4.3, 4.4, 16.12**

- [x] 5. Implement account-creation spec module (`onboarding/account-creation.e2e.ts`)
  - [x] 5.1 Implement admin-create and self-register checks
    - Admin-create via `POST /api/admin/users` (reusing the `team-onboarding-admin-create` pattern)
      and photographer self-registration (both destructive → gated)
    - Assert run-id suffix on the account, phone association, and login → photographer dashboard
    - _Requirements: 5.1, 5.2, 5.3, 5.4_

- [x] 6. Implement approval-workflow spec module (`onboarding/approval-workflow.e2e.ts`)
  - [x] 6.1 Implement self-registration approval-state checks
    - Self-registration sets `Approval_State` Pending and excludes from assignment; admin reviews
      the pending profile; approve → Approved (assignable subject to profile/distance/availability/
      service); reject → Rejected (never receives shoots)
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7_

  - [x]* 6.2 Write property test for approval-state assignability
    - **Property 14: Approval-state assignability**
    - **Validates: Requirements 6.2, 6.6, 6.7, 7.4, 7.5**

- [x] 7. Implement profile-completeness spec module (`onboarding/profile-completeness.e2e.ts`)
  - [x] 7.1 Implement profile field presence/required-state checks
    - Verify presence/required state of profile photo, phone, email, base location, radius,
      specialties, availability, blocked dates, equipment, portfolio; optional insurance/tax/payment
      where exposed; notification preference and active/inactive status; incomplete required field →
      not assignable; complete + Approved → assignable
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5_

- [x] 8. Implement service-radius / distance-gating spec module (`onboarding/service-radius.e2e.ts`)
  - [x] 8.1 Implement radius persistence and deterministic distance-gating checks
    - Persist `Service_Radius`; drive eligibility/booking (reusing `service-area-assignment` blocks)
      with seeded inside/boundary/outside addresses; assert unit + rounding rule, zero radius offers
      nobody, empty radius default rule, very-large radius offers within it, multiple eligible all
      offered, tie-breaker, area-restriction before radius; geocoding off + no seeded address →
      **blocked** with geocoding note (Truth Table T1/T2/T5/T6)
    - _Requirements: 8.1, 8.3, 8.4, 8.5, 8.6, 8.7, 8.8, 8.9, 8.10, 8.11, 8.12, 8.13_

  - [x] 8.2 Write property test for distance-gating monotonicity (CORE — Requirement 23.3)
    - **Property 3: Distance-gating monotonicity across inside/boundary/outside**
    - **Validates: Requirements 8.3, 8.4, 8.5, 8.6, 8.7, 8.8, 8.9, 23.3**

- [x] 9. Implement calendar-availability spec module (`onboarding/calendar-availability.e2e.ts`)
  - [x] 9.1 Implement conflict and availability-policy checks
    - Existing-shoot conflict exclusion/warning, travel-buffer between consecutive shoots, same-day
      cutoff, minimum lead time, outside-business-hours exclusion, timezone conversion consistency
      (Truth Table T3/T4)
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 9.6_

- [x] 10. Implement admin-override spec module (`onboarding/admin-override.e2e.ts`)
  - [x] 10.1 Implement override and reassignment checks
    - Override-allowed manual assignment of an out-of-radius photographer with warning;
      override-not-allowed rejection; reassignment grants the new photographer access and removes
      the previous photographer's access
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5_

- [x] 11. Implement booking-lifecycle spec module (`onboarding/booking-lifecycle.e2e.ts`)
  - [x] 11.1 Implement per-status lifecycle checks
    - Walk the ordered `Booking_Status` path; per status assert the authorized trigger role, status
      visibility to authorized roles only, the status-specific action control, the
      `Notification_Record` for the transition, file visibility when a status exposes files, and
      files-locked before the unlocking status; gate mutating/charging/messaging transitions
    - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.5, 11.6, 11.7_

- [x] 12. Implement shoot-workflow spec module (`onboarding/shoot-workflow.e2e.ts`)
  - [x] 12.1 Implement upload, upload-edge-case, and processing checks
    - Photographer uploads for an assigned shoot (seeded via `SeedPhotographerPreviousShoot`); cover
      30 images → count 30, single large file, duplicate-filename rule, unsupported type rejected,
      interrupted-then-retried without duplicates, refresh shows uploads, wrong-role/wrong-shoot
      rejected, count/storage-path/thumbnails exposed, editor sees correct files, delete/replace
      reflected, malware/unsafe blocked, processing advances to processed; screenshot completed shoot
    - _Requirements: 12.1, 12.2, 12.3, 12.4, 12.5, 12.6, 12.7, 12.8, 12.9, 12.10, 12.11, 12.12, 12.13, 12.14, 12.15_

- [x] 13. Implement selectors spec module (`onboarding/selectors.e2e.ts`)
  - [x] 13.1 Implement the named selector-contract checks
    - Assert the named `data-testid` contract is present and record a `Blocked_Check` for any
      missing required selector; confirm other modules consume the resolver to target `data-testid`
    - _Requirements: 13.1, 13.2, 13.3, 13.4_

- [x] 14. Implement UI-consistency spec module (`onboarding/ui-consistency.e2e.ts`)
  - [x] 14.1 Implement measurable cross-viewport UI checks
    - Run the `ui-probe` signal set across all four viewports (no console errors, allowed-list-aware
      network failures, no React crash boundary, no mobile horizontal overflow, defined empty
      states, no duplicate primary buttons, consistent status-badge text, no hidden required fields,
      no stale data post-save, action feedback); screenshot each surface at each viewport
    - _Requirements: 14.1, 14.2, 14.3, 14.4, 14.5, 14.6, 14.7, 14.8, 14.9, 14.10, 14.11, 14.12_

- [x] 15. Implement concurrent multi-role spec module (`onboarding/multi-role.e2e.ts`)
  - [x] 15.1 Implement concurrent-session and cross-context propagation checks
    - Hold all (up to seven) contexts open; assert independent identities; verify a change in one
      context appears in another after refresh
    - _Requirements: 15.1, 15.2, 15.3_

  - [x]* 15.2 Write property test for context identity isolation
    - **Property 11: Context identity isolation**
    - **Validates: Requirements 15.1, 15.2**

- [x] 16. Implement negative-permissions spec module (`onboarding/negative-permissions.e2e.ts`)
  - [x] 16.1 Implement negative and permission-enforcement checks
    - Photographer cannot open another photographer's shoot or upload to an unassigned shoot; client
      cannot open another client's shoot URL; editor cannot view a hidden extra; photo editor denied
      a video-only job and video editor denied a photo-only job; inactive/out-of-radius/blocked/
      service-mismatch photographers not offered; no CubiCasa action without Floor Plan; no duplicate
      order on repeated activation; payment-lock prevents download of unpaid final files including a
      direct file URL bypass attempt
    - _Requirements: 16.1, 16.2, 16.3, 16.4, 16.5, 16.6, 16.7, 16.8, 16.9, 16.10, 16.11, 16.12, 16.13, 16.14_

  - [x] 16.2 Write property test for role-access denial across Role_Contexts (CORE — Requirement 23.5)
    - **Property 5: Role-access denial across Role_Contexts**
    - **Validates: Requirements 16.1, 16.3, 16.4, 16.5, 16.6, 23.5**

- [x] 17. Implement notifications spec module (`onboarding/notifications.e2e.ts`)
  - [x] 17.1 Implement notification-sink correctness checks
    - Confirm sink routing under `log`/`disabled` modes; assert a `Notification_Record` is created
      on a triggering event with correct recipient, template, and rendered variables; assert no real
      SMS/email/voice send occurred
    - _Requirements: 17.1, 17.2, 17.3, 17.4, 17.5, 17.6_

- [x] 18. Implement invoicing & reporting spec module (`onboarding/invoicing-reporting.e2e.ts`)
  - [x] 18.1 Implement invoicing, payment-lock, reminder, and reporting checks
    - `GenerateInvoices` produces an invoice; payment-lock permits preview but prevents download
      while unpaid and permits download when paid; reminder paths produce a reminder for unpaid and
      none for paid; refund/cancel produces no incorrect invoice; zero-dollar applies no lock; weekly
      invoice summary, weekly sales report, and payout report paths; gate every
      `Charge_Triggering_Step` and prefer the non-charging path; screenshot each result
    - _Requirements: 18.1, 18.2, 18.3, 18.4, 18.5, 18.6, 18.7, 18.8, 18.9, 18.10, 18.11, 18.12, 18.13_

  - [x]* 18.2 Write property test for the payment-lock invariant
    - **Property 13: Payment-lock invariant**
    - **Validates: Requirements 16.13, 16.14, 18.2, 18.3, 18.7**

- [x] 19. Implement equipment spec module (`onboarding/equipment.e2e.ts`)
  - [x] 19.1 Implement add / list / assign / track + setting persistence checks
    - Admin adds an `Equipment_Item` (run-id suffix), lists it, assigns it to a photographer or
      shoot, reads the assignment back (round-trip), persists an equipment-related setting;
      screenshot listing + assignment
    - _Requirements: 19.1, 19.2, 19.3, 19.4, 19.5, 19.6_

  - [x]* 19.2 Write property test for equipment assignment round-trip
    - **Property 15: Equipment assignment round-trip**
    - **Validates: Requirements 19.3, 19.4**

- [x] 20. Implement comprehensive settings spec module (`onboarding/settings.e2e.ts`)
  - [x] 20.1 Implement persistence + Settings_Effect checks
    - Set availability (booking-offered effect), blocked window (booking-excluded effect),
      notification preference (notification-record effect), profile setting (persisted + reflected),
      and a settings-UI toggle (persisted + applied on the governed surface); gate live notification
      sends; screenshot each setting and its effect
    - _Requirements: 20.1, 20.2, 20.3, 20.4, 20.5, 20.6, 20.7, 20.8, 20.9_

  - [x] 20.2 Write property test for the settings effect (CORE — Requirement 23.4)
    - **Property 4: Settings effect**
    - **Validates: Requirements 20.2, 20.4, 20.7, 20.8, 23.4**

- [x] 21. Checkpoint - all domain specs implemented
  - Ensure all domain checks pass and produce evidence; verify blocked/skipped items are recorded
    with their missing dependency noted; do NOT block on human input — continue to cleanup.

- [x] 22. Implement cleanup spec module (`onboarding/cleanup.e2e.ts`) — runs last
  - [x] 22.1 Implement run-scoped, all-entity-type gated cleanup
    - Iterate the entity tracker to identify every `QA_Entity` created during the run across all
      types; gate each deletion; remove confirmed entities; assert no run-tagged entity remains;
      record the cleanup outcome per entity in the QA_Report (account deletions reuse
      `account-delete-cache-access` token-revocation/eviction checks)
    - _Requirements: 21.1, 21.2, 21.3, 21.4, 21.5_

- [x] 23. Wire the report-and-fix remediation loop
  - [x] 23.1 Implement continue-on-failure run and re-run override flow
    - Ensure remaining checks run after a failure and produce failure evidence; when a failure
      traces to a real defect, fix it in Laravel/React code and re-execute the failing check; override
      the report result for that check id to the latest verified pass
    - _Requirements: 22.4, 22.5, 22.6_

- [x] 24. Final checkpoint - verified, evidence-backed green/yellow/red report
  - Ensure all checks pass and produce evidence; confirm every blocked/skipped item is recorded with
    its missing dependency, cleanup outcomes are recorded per entity, and the report reflects the
    latest verified state; do NOT block on human input — finalize the report.

## Notes

- Tasks marked with `*` are optional property-based tests (Properties 6–15) and can be skipped for a
  faster MVP. Properties 1–5 are CORE per Requirement 23 and are REQUIRED (not marked `*`).
- Each task references the specific granular requirement clauses it covers; each property-test
  sub-task names its design Property and the requirements it validates.
- The harness (item 1) is a hard dependency for every domain spec; cleanup (item 22) is scheduled
  last so it observes and removes every `QA_Entity` created during the run.
- Property tests run a minimum of 100 generated iterations and are tagged
  `Feature: photographer-onboarding-qa, Property {number}: {property_text}`.
- Pure/in-memory properties (1, 2, 6, 7, 8, 9, 10, 12) run fast against generated inputs;
  system-observing properties (3, 4, 5, 11, 13, 14, 15) run against the target environment using
  generated inputs, routed through the gate and pinned to seeded fixtures / the notification sink.
- All runs default to read-only; destructive and charge/message steps execute only through the
  confirmation gate, preferring non-charging paths; missing data/selectors are recorded as blocked
  and the run continues without waiting for human input.
- Run the suite with `npm run test:e2e` from `frontend/` (single, non-watch execution).

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "1.4", "1.6", "1.8", "1.14", "1.20"] },
    { "id": 1, "tasks": ["1.3", "1.5", "1.7", "1.9", "1.10", "1.11", "1.12", "1.15", "1.16", "1.18", "1.19"] },
    { "id": 2, "tasks": ["1.13", "1.17", "2.1"] },
    { "id": 3, "tasks": ["4.1", "5.1", "6.1", "7.1", "8.1", "9.1", "10.1", "11.1", "12.1", "13.1", "14.1", "15.1", "16.1", "17.1", "18.1", "19.1", "20.1"] },
    { "id": 4, "tasks": ["4.2", "6.2", "8.2", "15.2", "16.2", "18.2", "19.2", "20.2"] },
    { "id": 5, "tasks": ["22.1"] },
    { "id": 6, "tasks": ["23.1"] }
  ]
}
```
