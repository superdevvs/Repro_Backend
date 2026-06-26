# Requirements Document

## Introduction

This feature delivers an end-to-end browser QA capability for the photographer onboarding
journey of the Dashboard application. The work extends the existing Playwright suite at
`frontend/e2e/` (TypeScript specs matching `**/*.e2e.ts`, chromium, executed via
`npm run test:e2e` from `frontend/`) and reuses the shared login helper at
`frontend/e2e/helpers/auth.ts` together with the documented environment-driven configuration
in `frontend/e2e/README.md`.

The QA suite verifies the full onboarding lifecycle: CubiCasa integration, photographer account
creation (admin-created and self-created with an approval workflow), photographer profile
completeness, settings verification including deterministic service-radius to client-booking
distance gating, calendar conflict and availability checks, admin override behavior, the full
booking lifecycle through every status, the full shoot workflow (upload, upload edge cases, and
processing) exercised across photographer, photo editor, video editor, editing manager, and
sales rep contexts, measurable UI consistency across multiple viewports, concurrent multi-role
operation through separate browser contexts, negative and permission enforcement, a non-live
notification sink, the photographer equipment workflow, the reporting and invoicing flows with a
payment-driven delivery lock, comprehensive settings verification, run-scoped cleanup of every
entity created during a run, and rich evidence-backed reporting.

The suite runs primarily against the production environment in a read-mostly manner; destructive
operations are permitted but each one is individually confirmed, and any step that could trigger
real charges or messages through the live Stripe, Square, Twilio, Telnyx, or CubiCasa keys is
gated behind explicit confirmation. The suite never blocks waiting for free-form human input: when
required data is missing it marks the affected check as blocked, records the missing dependency,
and continues all other checks. On completion the suite produces a green/yellow/red,
evidence-backed report. When a check fails the suite produces failure evidence and continues; if a
developer or coding agent applies a fix, the failing check is re-run and the report records the
latest verified state. Every entity created during a run is identified by its QA_Run_Id and
removed (subject to confirmation) at the end so production is not polluted.

## Glossary

- **QA_Suite**: The Playwright end-to-end test suite under `frontend/e2e/` that executes the
  photographer onboarding QA scenarios and is run via `npm run test:e2e`.
- **Onboarding_System**: The combined Dashboard backend (Laravel) and frontend (React/Vite)
  application surface exercised by the QA_Suite during photographer onboarding.
- **Admin_Context**: A Playwright browser context authenticated as a super admin user.
- **Photographer_Context**: A Playwright browser context authenticated as a photographer user.
- **Client_Context**: A Playwright browser context authenticated as (or acting as) a client user.
- **Photo_Editor_Context**: A Playwright browser context authenticated as a photo editor user
  responsible for processing photo (image) jobs.
- **Video_Editor_Context**: A Playwright browser context authenticated as a video editor user
  responsible for processing video jobs.
- **Editing_Manager_Context**: A Playwright browser context authenticated as an editing manager
  user responsible for reviewing and approving edited work.
- **Sales_Rep_Context**: A Playwright browser context authenticated as a sales representative
  user, used where the onboarding or shoot-processing workflow involves sales-facing surfaces.
- **Role_Context**: Any one of the Admin_Context, Photographer_Context, Client_Context,
  Photo_Editor_Context, Video_Editor_Context, Editing_Manager_Context, or Sales_Rep_Context.
- **Confirmation_Gate**: An explicit, per-step human confirmation required before the QA_Suite
  performs a destructive action or any action that can trigger a real charge or message through
  a live external provider.
- **Destructive_Step**: A QA_Suite action that creates, modifies, or deletes persistent data in
  the target environment, including creating or deleting accounts and creating bookings.
- **Charge_Triggering_Step**: A QA_Suite action that can initiate a real charge or payout through
  the live Stripe or Square keys, including invoice payment, payout, and payment-reminder actions.
- **Blocked_Check**: A check the QA_Suite cannot perform because a required dependency or data is
  missing; the QA_Suite records it as blocked with the missing dependency noted and continues.
- **Service_Radius**: The configured maximum distance from a photographer base location used by
  the Onboarding_System to determine whether a client booking address is eligible for that
  photographer.
- **Service_Specialty**: A photography service a photographer is qualified to perform (for
  example HDR, Floor Plan, Drone, or Video), used by the Onboarding_System to determine service
  match eligibility.
- **Distance_Gating**: The Onboarding_System behavior that includes an address inside a
  photographer Service_Radius and excludes an address outside that Service_Radius during client
  booking and admin assignment.
- **Seeded_Address**: A test address with a fixed, seeded latitude and longitude used so distance
  checks are deterministic and do not depend solely on live geocoding.
- **Truth_Table**: The normative Onboarding QA Truth Table enumerating each canonical test as a
  row of Area, Test, Input, Expected Result, and Green Only If.
- **Booking_Status**: A named state in the booking and shoot lifecycle, one of Requested,
  Scheduled, Photographer Assigned, Shoot Completed, Raw Uploaded, Sent to Editor, Editing In
  Progress, Edited Uploaded, Editing Manager Review, Approved, Finalized, Delivered, Payment
  Due, Payment Paid, and Downloadable.
- **Approval_State**: The review state of a self-registered photographer account, one of Pending,
  Approved, or Rejected.
- **Profile_Completeness**: The set of photographer profile fields and their required state that
  the Onboarding_System evaluates to determine whether a photographer is assignable.
- **Override**: An Admin_Context capability to manually assign a photographer that distance,
  availability, or service rules would otherwise exclude, permitted only when override is allowed.
- **Payment_Lock**: The Onboarding_System behavior that prevents a client from downloading final
  delivered files for an unpaid Invoice while still permitting preview.
- **Notification_Sink**: The non-live notification destination selected by the
  `E2E_NOTIFICATION_MODE`, `E2E_EMAIL_MODE`, `E2E_SMS_MODE`, and `E2E_VOICE_MODE` environment
  variables, which records notification records instead of sending real messages.
- **Notification_Record**: A persisted record of an intended notification, including recipient,
  template, and rendered variables, created in the Notification_Sink instead of a real message.
- **Selector**: A stable `data-testid` attribute exposed by an onboarding-critical UI element that
  the QA_Suite uses to locate that element instead of relying on button text, CSS, or layout.
- **Viewport**: A browser window size the QA_Suite uses for UI consistency checks, one of Desktop
  1440x900, Laptop 1280x800, Tablet 768x1024, or Mobile 390x844.
- **QA_Report**: The evidence-backed report produced by the QA_Suite at the end of a run,
  including a green/yellow/red summary.
- **QA_Run_Id**: The value supplied via `E2E_QA_RUN_ID` used as a suffix for QA-created names,
  emails, addresses, and as the identifying tag for every entity created during the run.
- **QA_Entity**: Any record created by the QA_Suite during a run and tagged with the QA_Run_Id,
  including accounts, shoots, bookings, uploaded raw files, edited files, CubiCasa test orders and
  references, equipment items, equipment assignments, invoices, payment-reminder records,
  notification logs, test clients, test addresses, availability windows, blocked windows, and
  generated reports.
- **Test_Account**: A QA_Entity that is an account created by the QA_Suite during a run.
- **Equipment_Item**: A unit of photographer equipment managed within the Onboarding_System,
  including its creation, listing, and assignment to a photographer or shoot.
- **Equipment_Assignment**: The association the Onboarding_System records between an
  Equipment_Item and a photographer or a shoot.
- **Invoice**: A billing record produced by the Onboarding_System for a photographer or client,
  generated through the backend `GenerateInvoices` command path.
- **Photographer_Setting**: A configurable value owned by a photographer in the Onboarding_System,
  including availability windows, blocked windows, notification preferences, and profile settings.
- **Admin_Setting**: A configurable value owned by an admin in the Onboarding_System that governs
  photographer or platform behavior.
- **Settings_Effect**: The intended downstream behavior change in the Onboarding_System that
  results from a persisted Photographer_Setting or Admin_Setting value.

## Onboarding QA Truth Table

This Truth Table is normative. Each row defines a canonical test that the QA_Suite SHALL execute.
A row is reported green only when its "Green Only If" condition is satisfied with captured
evidence. The acceptance criteria in the Requirements section express these rows as concrete EARS
statements; the Truth Table and the acceptance criteria are intended to remain consistent.

| Row | Area | Test | Input | Expected Result | Green Only If |
|-----|------|------|-------|-----------------|---------------|
| T1 | Radius | Inside radius | Booking address 10mi from Photographer A (radius 25mi) | Photographer A appears in client booking AND in admin assignment list | Photographer A is selectable in both client booking and admin assignment, with screenshot evidence |
| T2 | Radius | Outside radius | Booking address 40mi from Photographer B (radius 5mi) | Photographer B hidden from client booking AND not manually assignable without override warning | Photographer B absent from client booking list; admin assignment without override is rejected or shows the override warning |
| T3 | Availability | Available time | Booking Monday 10:00 with Photographer A available Mon-Fri 09:00-17:00 | Photographer A offered, booking succeeds, photographer dashboard shows the shoot | Booking completes and the shoot appears on Photographer A dashboard |
| T4 | Availability | Blocked window | Photographer A has Monday 10:00 blocked, client books Monday 10:00 | Photographer A excluded; cannot be assigned for that time | Photographer A absent from offered list and assignment for that time is prevented |
| T5 | Service match | Service match | HDR booking, Photographer with HDR specialty | Photographer eligible and appears | Photographer appears as eligible for the HDR booking |
| T6 | Service match | Service mismatch | Drone booking, Photographer lacks Drone specialty | Photographer excluded | Photographer absent from eligible list for the Drone booking |
| T7 | CubiCasa | Positive | Shoot with HDR + Floor Plan services | CubiCasa available; order created exactly once | CubiCasa create-order control is present and a single order is recorded after a create action (including double click) |
| T8 | CubiCasa | Negative | Shoot with HDR only, no Floor Plan | No CubiCasa action appears | No CubiCasa create-order control is present on the shoot |

## Requirements

### Requirement 1: Test execution and configuration

**User Story:** As a QA engineer, I want the onboarding QA to run inside the existing Playwright
suite using documented environment configuration, so that I can execute it consistently without
new tooling.

#### Acceptance Criteria

1. THE QA_Suite SHALL provide specs that match the pattern `**/*.e2e.ts` under `frontend/e2e/`.
2. WHEN a QA engineer runs `npm run test:e2e` from the `frontend/` directory, THE QA_Suite SHALL
   execute the photographer onboarding QA specs in the chromium browser.
3. THE QA_Suite SHALL authenticate users through the shared helper at
   `frontend/e2e/helpers/auth.ts`.
4. THE QA_Suite SHALL read target configuration from the environment variables `E2E_BASE_URL`,
   `E2E_API_BASE_URL`, `E2E_NO_SERVER`, `E2E_ADMIN_EMAIL`, `E2E_ADMIN_PASSWORD`,
   `E2E_PREVIEW_STORAGE_STATE`, `E2E_QA_RUN_ID`, and `E2E_EXTERNAL_BOOKING_API_KEY`.
5. THE QA_Suite SHALL read notification sink configuration from the environment variables
   `E2E_NOTIFICATION_MODE`, `E2E_EMAIL_MODE`, `E2E_SMS_MODE`, and `E2E_VOICE_MODE`.
6. WHERE `E2E_NO_SERVER` is set to `1`, THE QA_Suite SHALL run against the external stack
   identified by `E2E_BASE_URL` without starting a managed development server.
7. WHERE `E2E_QA_RUN_ID` is provided, THE QA_Suite SHALL append the `E2E_QA_RUN_ID` value as a
   suffix to every QA-created name, email, and address, and SHALL tag every QA_Entity with the
   `E2E_QA_RUN_ID` value.

### Requirement 2: Production safety, confirmation gating, and missing-data handling

**User Story:** As a QA engineer running against production, I want destructive and
charge-triggering steps individually confirmed and missing data handled without blocking, so that
I avoid unintended data changes, real charges, and real messages while the run still completes.

#### Acceptance Criteria

1. THE QA_Suite SHALL default to read-only operations against the target environment.
2. WHEN the QA_Suite reaches a Destructive_Step, THE QA_Suite SHALL request confirmation through
   the Confirmation_Gate before performing that Destructive_Step.
3. IF the Confirmation_Gate for a Destructive_Step is declined, THEN THE QA_Suite SHALL skip that
   Destructive_Step and record the step as skipped in the QA_Report.
4. WHEN a QA step can trigger a charge or message through a live Stripe, Square, Twilio, Telnyx,
   or CubiCasa key, THE QA_Suite SHALL request confirmation through the Confirmation_Gate before
   performing that step.
5. WHERE a payment-triggering or message-triggering step provides a non-charging path, THE
   QA_Suite SHALL use the non-charging path.
6. IF required data for a check is missing, THEN THE QA_Suite SHALL mark that check as a
   Blocked_Check with the missing dependency noted and SHALL continue all other checks.

### Requirement 3: Exact test data and accounts

**User Story:** As a QA engineer, I want a fixed, run-id-suffixed dataset of accounts, so that
every onboarding scenario runs against deterministic, identifiable test data.

#### Acceptance Criteria

1. WHEN the QA_Suite provisions test data, THE QA_Suite SHALL create an admin account identified
   as `admin.qa` for the Admin_Context.
2. WHEN the QA_Suite provisions test data, THE QA_Suite SHALL create a client account with the
   email pattern `client.qa.{RUN_ID}@example.test` for the Client_Context.
3. WHEN the QA_Suite provisions test data, THE QA_Suite SHALL create Photographer A as an
   inside-radius photographer with Service_Specialties HDR, Floor Plan, and Drone, a
   Service_Radius of 25 miles, and availability Monday through Friday 09:00 to 17:00, suffixed
   with the QA_Run_Id.
4. WHEN the QA_Suite provisions test data, THE QA_Suite SHALL create Photographer B as an
   outside-radius photographer with a Service_Radius of 5 miles, suffixed with the QA_Run_Id.
5. WHEN the QA_Suite provisions test data, THE QA_Suite SHALL create Photographer C as a
   wrong-specialty photographer with the Video Service_Specialty only, suffixed with the
   QA_Run_Id.
6. WHEN the QA_Suite provisions test data, THE QA_Suite SHALL create a photo editor, a video
   editor, and an editing manager account, each suffixed with the QA_Run_Id, for the
   Photo_Editor_Context, the Video_Editor_Context, and the Editing_Manager_Context.
7. WHERE a sales rep account is required by a scenario, THE QA_Suite SHALL create a sales rep
   account suffixed with the QA_Run_Id for the Sales_Rep_Context.
8. THE QA_Suite SHALL tag every account it creates as a QA_Entity with the QA_Run_Id suffix.

### Requirement 4: CubiCasa integration verification

**User Story:** As a QA engineer, I want to verify the CubiCasa integration during onboarding,
so that floor-plan ordering, idempotency, and error and disabled states all behave correctly.

#### Acceptance Criteria

1. WHERE a shoot includes the Floor Plan Service_Specialty, THE Onboarding_System SHALL present
   the CubiCasa create-order control on that shoot.
2. WHERE a shoot does not include the Floor Plan Service_Specialty, THE Onboarding_System SHALL
   omit the CubiCasa create-order control on that shoot.
3. WHEN the QA_Suite places a CubiCasa manual order through the Onboarding_System, THE
   Onboarding_System SHALL record exactly one order with a pending status.
4. WHEN the QA_Suite activates the CubiCasa create-order control more than once for the same
   shoot, THE Onboarding_System SHALL record no additional order beyond the first.
5. IF a CubiCasa order fails, THEN THE Onboarding_System SHALL present a recoverable error state
   for that order.
6. IF a CubiCasa order is not linked to its shoot, THEN THE Onboarding_System SHALL present a
   warning for that unlinked order.
7. WHEN the QA_Suite triggers a CubiCasa asset resync, THE Onboarding_System SHALL retry the
   resync safely and update the associated order status from pending to a synced state.
8. WHEN the Onboarding_System receives a CubiCasa webhook callback for an order, THE
   Onboarding_System SHALL update that order status from the callback.
9. IF CubiCasa credentials are missing, THEN THE Onboarding_System SHALL present a blocked state
   for the CubiCasa action rather than a failed state.
10. IF the CubiCasa provider is disabled, THEN THE Onboarding_System SHALL present a skipped or
    blocked state for the CubiCasa action.
11. THE QA_Suite SHALL capture a screenshot of the CubiCasa order state for inclusion in the
    QA_Report.

### Requirement 5: Photographer account creation

**User Story:** As a QA engineer, I want to verify both admin-created and self-created
photographer accounts, so that both onboarding entry paths are validated.

#### Acceptance Criteria

1. WHEN an admin in the Admin_Context creates a photographer account through the super-admin
   account-creation flow, THE Onboarding_System SHALL create a photographer Test_Account with
   the QA_Run_Id suffix.
2. WHEN a photographer self-registers through the Onboarding_System, THE Onboarding_System SHALL
   create a photographer Test_Account with the QA_Run_Id suffix.
3. WHEN a phone number supplied at execution time is submitted during account creation, THE
   Onboarding_System SHALL associate that phone number with the created photographer
   Test_Account.
4. WHEN a created photographer Test_Account completes login through the shared auth helper, THE
   Onboarding_System SHALL navigate the account to the photographer dashboard.

### Requirement 6: Self-registration approval workflow

**User Story:** As a QA engineer, I want to verify the self-registration approval workflow, so
that only approved photographers become assignable.

#### Acceptance Criteria

1. WHEN a photographer self-registers, THE Onboarding_System SHALL set the photographer
   Approval_State to Pending.
2. WHILE a photographer Approval_State is Pending, THE Onboarding_System SHALL exclude that
   photographer from assignment.
3. WHEN an admin in the Admin_Context reviews a pending photographer profile, THE
   Onboarding_System SHALL present the photographer profile for review.
4. WHEN an admin in the Admin_Context approves a pending photographer, THE Onboarding_System SHALL
   set the photographer Approval_State to Approved.
5. WHEN an admin in the Admin_Context rejects a pending photographer, THE Onboarding_System SHALL
   set the photographer Approval_State to Rejected.
6. WHILE a photographer Approval_State is Approved, THE Onboarding_System SHALL make that
   photographer assignable subject to Profile_Completeness, distance, availability, and service
   rules.
7. WHILE a photographer Approval_State is Rejected, THE Onboarding_System SHALL prevent that
   photographer from receiving shoots.

### Requirement 7: Photographer profile completeness

**User Story:** As a QA engineer, I want to verify photographer profile completeness, so that
only photographers with the required fields become assignable.

#### Acceptance Criteria

1. THE QA_Suite SHALL verify the presence and required state of the photographer profile photo,
   phone, email, base location address, Service_Radius, Service_Specialties, availability,
   blocked dates, equipment, and portfolio or sample work fields.
2. WHERE the Onboarding_System exposes insurance, tax, or payment information fields, THE QA_Suite
   SHALL verify the presence and required state of those fields.
3. THE QA_Suite SHALL verify the presence and required state of the photographer notification
   preference and active or inactive status fields.
4. IF a field that the Onboarding_System marks as required for assignment is incomplete, THEN THE
   Onboarding_System SHALL prevent that photographer from being assignable.
5. WHILE all fields required for assignment are complete and the photographer Approval_State is
   Approved, THE Onboarding_System SHALL make that photographer assignable.

### Requirement 8: Service-radius and deterministic distance gating

**User Story:** As a QA engineer, I want deterministic, boundary-aware verification that a
configured service radius and service area control which photographers are offered, so that
distance gating works end-to-end rather than only persisting a setting.

#### Acceptance Criteria

1. WHEN an admin or photographer sets a Service_Radius for a photographer, THE Onboarding_System
   SHALL persist the Service_Radius value for that photographer.
2. THE QA_Suite SHALL use Seeded_Addresses with fixed latitude and longitude so distance checks
   do not depend solely on live geocoding.
3. WHEN a client in the Client_Context books a Seeded_Address whose distance is less than the
   photographer Service_Radius, THE Onboarding_System SHALL offer that photographer for the
   booking and present that photographer in the admin assignment list.
4. WHEN a client in the Client_Context books a Seeded_Address whose distance equals the
   photographer Service_Radius, THE Onboarding_System SHALL apply the documented boundary rule
   consistently for that photographer.
5. WHEN a client in the Client_Context books a Seeded_Address whose distance is greater than the
   photographer Service_Radius, THE Onboarding_System SHALL exclude that photographer from the
   booking.
6. WHEN the Onboarding_System computes distance, THE Onboarding_System SHALL apply the configured
   distance unit of miles or kilometers and the documented rounding rule consistently.
7. WHERE a photographer Service_Radius is zero, THE Onboarding_System SHALL offer that
   photographer for no booking address.
8. IF a photographer Service_Radius is empty, THEN THE Onboarding_System SHALL apply the
   documented default-radius rule for that photographer.
9. WHERE a photographer Service_Radius is very large, THE Onboarding_System SHALL offer that
   photographer for booking addresses within that very large radius.
10. WHEN more than one photographer is eligible for a booking, THE Onboarding_System SHALL offer
    every eligible photographer.
11. WHEN two eligible photographers tie under the ordering rule, THE Onboarding_System SHALL apply
    the documented tie-breaker rule.
12. WHEN a photographer has both a service-area restriction and a Service_Radius, THE
    Onboarding_System SHALL apply the state, region, or area restriction before applying the
    Service_Radius.
13. IF geocoding is disabled and no Seeded_Address is available for a check, THEN THE QA_Suite
    SHALL record that Distance_Gating check as a Blocked_Check with the geocoding dependency
    noted.

### Requirement 9: Calendar conflict and availability checks

**User Story:** As a QA engineer, I want to verify calendar conflict handling and booking time
rules, so that double-bookings and out-of-policy times are prevented.

#### Acceptance Criteria

1. WHEN a photographer already has a shoot at a requested time and a client attempts to book that
   photographer at the same time, THE Onboarding_System SHALL exclude that photographer or present
   a conflict warning for that time.
2. WHERE a travel buffer between shoots is configured, THE Onboarding_System SHALL apply the
   travel buffer when determining photographer availability between consecutive shoots.
3. WHERE a same-day booking cutoff is configured, THE Onboarding_System SHALL exclude bookings
   submitted after the same-day booking cutoff for that day.
4. WHERE a minimum lead time is configured, THE Onboarding_System SHALL exclude bookings that do
   not meet the minimum lead time.
5. WHEN a client attempts to book outside the photographer business hours, THE Onboarding_System
   SHALL exclude that time from the offered times.
6. WHEN the Onboarding_System presents booking times across time zones, THE Onboarding_System
   SHALL apply the documented timezone conversion consistently.

### Requirement 10: Admin override behavior

**User Story:** As a QA engineer, I want to verify admin override and reassignment behavior, so
that manual assignment is controlled and access transfers correctly.

#### Acceptance Criteria

1. WHERE Override is allowed, THE Onboarding_System SHALL permit an admin in the Admin_Context to
   manually assign an out-of-radius photographer.
2. WHEN an admin in the Admin_Context assigns an out-of-radius photographer under an allowed
   Override, THE Onboarding_System SHALL present a warning for that assignment.
3. IF Override is not allowed and an admin attempts to assign an out-of-radius photographer, THEN
   THE Onboarding_System SHALL reject that assignment.
4. WHEN an admin in the Admin_Context reassigns a shoot from one photographer to another, THE
   Onboarding_System SHALL grant the new photographer access to that shoot.
5. WHEN an admin in the Admin_Context reassigns a shoot from one photographer to another, THE
   Onboarding_System SHALL remove the previous photographer access to that shoot.

### Requirement 11: Full booking lifecycle statuses

**User Story:** As a QA engineer, I want to verify every booking lifecycle status, so that each
transition exposes the correct trigger, visibility, control, notification, and file access.

#### Acceptance Criteria

1. THE Onboarding_System SHALL represent the booking lifecycle as the ordered Booking_Status path
   Requested, Scheduled, Photographer Assigned, Shoot Completed, Raw Uploaded, Sent to Editor,
   Editing In Progress, Edited Uploaded, Editing Manager Review, Approved, Finalized, Delivered,
   Payment Due or Payment Paid, and Downloadable.
2. WHEN a booking transitions to a new Booking_Status, THE Onboarding_System SHALL permit only the
   role authorized for that transition to trigger it.
3. WHILE a booking is in a given Booking_Status, THE Onboarding_System SHALL display that
   Booking_Status only to the roles authorized to view it.
4. WHEN a booking is in a given Booking_Status, THE Onboarding_System SHALL present the action
   control defined for that Booking_Status to the authorized role.
5. WHEN a booking transitions to a new Booking_Status, THE Onboarding_System SHALL create the
   Notification_Record defined for that transition.
6. WHEN a booking reaches a Booking_Status that exposes files, THE Onboarding_System SHALL make
   the files defined for that Booking_Status visible to the authorized role.
7. WHILE a booking has not reached the Booking_Status that unlocks a file, THE Onboarding_System
   SHALL keep that file locked.

### Requirement 12: Full shoot workflow and upload edge cases

**User Story:** As a QA engineer, I want to verify the full shoot workflow including upload edge
cases and processing, so that a photographer can deliver a completed shoot end-to-end and uploads
behave correctly under stress and error conditions.

#### Acceptance Criteria

1. WHEN a photographer in the Photographer_Context uploads shoot files for an assigned shoot, THE
   Onboarding_System SHALL accept the uploaded files for that shoot.
2. WHEN a photographer uploads 30 raw images for an assigned shoot, THE Onboarding_System SHALL
   record a file count of 30 for that shoot.
3. WHEN a photographer uploads a single large file for an assigned shoot, THE Onboarding_System
   SHALL accept the large file for that shoot.
4. WHEN a photographer uploads files with duplicate filenames, THE Onboarding_System SHALL apply
   the documented duplicate-filename rule without losing files.
5. IF a photographer uploads an unsupported file type, THEN THE Onboarding_System SHALL reject the
   unsupported file.
6. WHEN an upload is interrupted and retried, THE Onboarding_System SHALL complete the retried
   upload without duplicating accepted files.
7. WHEN the upload surface is refreshed after an upload completes, THE Onboarding_System SHALL
   display the previously uploaded files for that shoot.
8. IF a photographer uploads from a role not authorized to upload, THEN THE Onboarding_System
   SHALL reject the upload.
9. IF a photographer uploads to a shoot not assigned to that photographer, THEN THE
   Onboarding_System SHALL reject the upload.
10. WHEN the QA_Suite verifies an uploaded shoot, THE Onboarding_System SHALL expose the recorded
    file count, the storage path, and the thumbnails or previews for the uploaded files.
11. WHEN an editor opens an assigned shoot, THE Onboarding_System SHALL display the correct
    uploaded files for that shoot to that editor.
12. WHEN a photographer deletes or replaces an uploaded file, THE Onboarding_System SHALL reflect
    the deletion or replacement consistently for that shoot.
13. IF an uploaded file is detected as malware or unsafe, THEN THE Onboarding_System SHALL block
    that file.
14. WHEN uploaded shoot files enter processing, THE Onboarding_System SHALL advance the shoot to a
    processed state.
15. THE QA_Suite SHALL capture a screenshot of the completed shoot state for inclusion in the
    QA_Report.

### Requirement 13: Stable selectors

**User Story:** As a QA engineer, I want every onboarding-critical UI element to expose a stable
selector, so that tests target `data-testid` rather than brittle text, CSS, or layout.

#### Acceptance Criteria

1. THE Onboarding_System SHALL expose a stable `data-testid` Selector on every onboarding-critical
   button, status badge, form field, upload input, table row, and action menu.
2. THE Onboarding_System SHALL expose the Selectors `create-photographer-button`,
   `photographer-radius-input`, `booking-address-input`, `eligible-photographer-row`,
   `cubicasa-create-order-button`, `shoot-status-badge`, `raw-upload-input`,
   `submit-to-editor-button`, and `finalize-delivery-button`.
3. WHEN the QA_Suite locates an onboarding-critical UI element, THE QA_Suite SHALL use the element
   `data-testid` Selector rather than button text, CSS class, or layout position.
4. IF an onboarding-critical UI element does not expose a `data-testid` Selector, THEN THE
   QA_Suite SHALL record that check as a Blocked_Check with the missing Selector noted.

### Requirement 14: Measurable UI consistency and viewport testing

**User Story:** As a QA engineer, I want measurable UI consistency checks across multiple
viewports, so that UI quality is verified by signals rather than screenshots alone.

#### Acceptance Criteria

1. WHEN the QA_Suite navigates an onboarding surface, THE Onboarding_System SHALL render that
   surface with no console error.
2. WHEN the QA_Suite navigates an onboarding surface, THE Onboarding_System SHALL produce no
   failed network request other than requests on the allowed list.
3. WHEN the QA_Suite navigates an onboarding surface, THE Onboarding_System SHALL render that
   surface with no React crash boundary displayed.
4. WHILE an onboarding surface is displayed at the Mobile 390x844 Viewport, THE Onboarding_System
   SHALL render that surface with no horizontal overflow.
5. WHEN an onboarding surface has no data to display, THE Onboarding_System SHALL render a defined
   empty state for that surface.
6. WHEN the QA_Suite inspects an onboarding surface, THE Onboarding_System SHALL render no
   duplicate primary action button on that surface.
7. THE Onboarding_System SHALL render consistent status-badge text for a given Booking_Status
   across surfaces.
8. WHEN the QA_Suite inspects an onboarding form, THE Onboarding_System SHALL expose no hidden
   required field on that form.
9. WHEN a save completes on an onboarding surface, THE Onboarding_System SHALL display the saved
   data without stale data on that surface.
10. WHEN the QA_Suite triggers an action on an onboarding surface, THE Onboarding_System SHALL
    present a loading, success, or error feedback state for that action.
11. THE QA_Suite SHALL execute the UI consistency checks at the Desktop 1440x900, Laptop 1280x800,
    Tablet 768x1024, and Mobile 390x844 Viewports.
12. THE QA_Suite SHALL capture a screenshot of each verified onboarding surface at each Viewport
    for inclusion in the QA_Report.

### Requirement 15: Concurrent multi-role operation

**User Story:** As a QA engineer, I want admin, photographer, client, editor, editing manager,
and sales rep sessions active at the same time, so that I can verify cross-role interactions
through the full shoot-processing workflow.

#### Acceptance Criteria

1. THE QA_Suite SHALL maintain the Admin_Context, Photographer_Context, Client_Context,
   Photo_Editor_Context, Video_Editor_Context, Editing_Manager_Context, and, where required, the
   Sales_Rep_Context as separate browser contexts within a single run.
2. WHILE multiple Role_Contexts are authenticated simultaneously, THE Onboarding_System SHALL
   maintain each session independently.
3. WHEN an action performed in one Role_Context changes shared onboarding data, THE
   Onboarding_System SHALL reflect that change in the other Role_Contexts upon refresh.

### Requirement 16: Negative and permission tests

**User Story:** As a QA engineer, I want negative and permission enforcement verified, so that
roles cannot access data or actions outside their authorization.

#### Acceptance Criteria

1. IF a photographer attempts to open another photographer shoot, THEN THE Onboarding_System SHALL
   deny access.
2. IF a photographer attempts to upload to a shoot not assigned to that photographer, THEN THE
   Onboarding_System SHALL reject the upload.
3. IF a client attempts to open another client shoot URL, THEN THE Onboarding_System SHALL deny
   access.
4. IF an editor attempts to view a hidden extra, THEN THE Onboarding_System SHALL deny access to
   that hidden extra.
5. IF a photo editor attempts to view a video-only job, THEN THE Onboarding_System SHALL deny
   access to that job.
6. IF a video editor attempts to view a photo-only job, THEN THE Onboarding_System SHALL deny
   access to that job.
7. WHILE a photographer is inactive, THE Onboarding_System SHALL prevent that photographer from
   being assignable.
8. WHEN a booking address is out of a photographer Service_Radius, THE Onboarding_System SHALL not
   offer that photographer.
9. WHEN a booking time falls in a photographer blocked window, THE Onboarding_System SHALL not
   offer that photographer.
10. WHEN a booking service does not match a photographer Service_Specialties, THE Onboarding_System
    SHALL not offer that photographer.
11. WHERE a shoot does not include the Floor Plan Service_Specialty, THE Onboarding_System SHALL
    show no CubiCasa action for that shoot.
12. WHEN the CubiCasa create-order control is activated more than once, THE Onboarding_System SHALL
    create no duplicate order.
13. WHILE Payment_Lock is enabled and an Invoice is unpaid, THE Onboarding_System SHALL prevent the
    client from downloading the final files.
14. IF a client requests a final file through a direct file URL while Payment_Lock applies, THEN
    THE Onboarding_System SHALL deny that direct file request.

### Requirement 17: Non-live notification sink

**User Story:** As a QA engineer, I want notifications routed to a non-live sink, so that I can
assert notification correctness without sending real messages.

#### Acceptance Criteria

1. WHERE `E2E_NOTIFICATION_MODE`, `E2E_EMAIL_MODE`, or `E2E_SMS_MODE` is set to `log` and
   `E2E_VOICE_MODE` is set to `disabled`, THE Onboarding_System SHALL route notifications to the
   Notification_Sink instead of sending real messages.
2. WHEN an event that triggers a notification occurs, THE Onboarding_System SHALL create a
   Notification_Record in the Notification_Sink.
3. WHEN a Notification_Record is created, THE QA_Suite SHALL assert that the Notification_Record
   selects the correct recipient.
4. WHEN a Notification_Record is created, THE QA_Suite SHALL assert that the Notification_Record
   uses the correct template.
5. WHEN a Notification_Record is created, THE QA_Suite SHALL assert that the Notification_Record
   renders the correct variables.
6. WHEN an event that triggers a notification occurs, THE Onboarding_System SHALL send no real SMS,
   email, or voice message.

### Requirement 18: Invoicing, payment delivery lock, and reporting workflow

**User Story:** As a QA engineer, I want to verify invoicing, the payment-driven delivery lock,
payout and sales reports, weekly summaries, and payment reminders end-to-end against production,
so that billing and reporting flows are validated without triggering unintended real charges.

#### Acceptance Criteria

1. WHEN the QA_Suite triggers invoice generation through the `GenerateInvoices` command path, THE
   Onboarding_System SHALL produce an Invoice for the targeted photographer or client.
2. WHILE a shoot Invoice is unpaid and Payment_Lock applies, THE Onboarding_System SHALL permit
   the client to preview the final files and prevent the client from downloading the final files.
3. WHILE a shoot Invoice is paid, THE Onboarding_System SHALL permit the client to download the
   final files.
4. WHEN the QA_Suite triggers the `ProcessInvoiceReminders` or `PaymentRemindersSweep` path for an
   unpaid Invoice, THE Onboarding_System SHALL produce a payment reminder for that Invoice.
5. WHILE an Invoice is paid, THE Onboarding_System SHALL produce no payment reminder for that
   Invoice.
6. WHEN a shoot is refunded or cancelled, THE Onboarding_System SHALL produce no incorrect Invoice
   for that shoot.
7. WHERE a product is zero-dollar, THE Onboarding_System SHALL apply no Payment_Lock to the
   delivery of that product.
8. WHEN the QA_Suite triggers the `SendWeeklyInvoiceSummaries` path, THE Onboarding_System SHALL
   produce a weekly invoice summary for the targeted recipient.
9. WHEN the QA_Suite triggers the `SendWeeklySalesReports` path, THE Onboarding_System SHALL
   produce a weekly sales report for the targeted recipient.
10. WHEN the QA_Suite triggers the `SendPayoutReports` path, THE Onboarding_System SHALL produce a
    payout report for the targeted recipient.
11. WHEN the QA_Suite reaches a Charge_Triggering_Step, THE QA_Suite SHALL request confirmation
    through the Confirmation_Gate before performing that Charge_Triggering_Step.
12. WHERE an invoicing or reporting step provides a non-charging path, THE QA_Suite SHALL use the
    non-charging path.
13. THE QA_Suite SHALL capture a screenshot of each verified invoicing and reporting result for
    inclusion in the QA_Report.

### Requirement 19: Equipment workflow verification

**User Story:** As a QA engineer, I want to verify the photographer equipment workflow
end-to-end, so that adding, listing, assigning, and tracking equipment work as exposed in the
application rather than only at the data layer.

#### Acceptance Criteria

1. WHEN an admin in the Admin_Context adds an Equipment_Item through the equipment management
   surface, THE Onboarding_System SHALL create the Equipment_Item with the QA_Run_Id suffix.
2. WHEN the QA_Suite opens the equipment listing surface, THE Onboarding_System SHALL display the
   created Equipment_Item in the equipment list.
3. WHEN the QA_Suite assigns an Equipment_Item to a photographer or a shoot, THE Onboarding_System
   SHALL record the Equipment_Assignment for that Equipment_Item.
4. WHEN the QA_Suite opens the equipment tracking surface for an assigned Equipment_Item, THE
   Onboarding_System SHALL display the current Equipment_Assignment for that Equipment_Item.
5. WHEN a QA step modifies an equipment-related setting, THE Onboarding_System SHALL persist the
   equipment-related setting value.
6. THE QA_Suite SHALL capture a screenshot of the equipment listing and assignment state for
   inclusion in the QA_Report.

### Requirement 20: Comprehensive settings verification

**User Story:** As a QA engineer, I want to verify that each photographer and admin setting both
persists and produces its intended downstream effect, so that settings are validated for behavior
rather than storage alone.

#### Acceptance Criteria

1. WHEN the QA_Suite sets an availability window as a Photographer_Setting, THE Onboarding_System
   SHALL persist the availability window value.
2. WHEN a client in the Client_Context books within a persisted availability window, THE
   Onboarding_System SHALL apply the availability window as the Settings_Effect by offering the
   photographer for that time.
3. WHEN the QA_Suite sets a blocked window as a Photographer_Setting, THE Onboarding_System SHALL
   persist the blocked window value.
4. WHEN a client in the Client_Context books within a persisted blocked window, THE
   Onboarding_System SHALL apply the blocked window as the Settings_Effect by excluding the
   photographer for that time.
5. WHEN the QA_Suite sets a notification preference as a Photographer_Setting, THE
   Onboarding_System SHALL persist the notification preference value.
6. WHEN an event matching a persisted notification preference occurs, THE Onboarding_System SHALL
   apply the notification preference as the Settings_Effect by creating Notification_Records
   according to that preference.
7. WHEN the QA_Suite sets a profile setting as a Photographer_Setting, THE Onboarding_System SHALL
   persist the profile setting value and reflect the profile setting on the photographer profile
   surface.
8. WHEN the QA_Suite changes a toggle surfaced in the settings UI, THE Onboarding_System SHALL
   persist the toggle value and apply the toggle Settings_Effect on the surface the toggle
   governs.
9. THE QA_Suite SHALL capture a screenshot of each verified setting and its Settings_Effect for
   inclusion in the QA_Report.

### Requirement 21: Run-scoped cleanup for all entities

**User Story:** As a QA engineer, I want every entity created during a run removed at the end, so
that the production environment is not polluted with any test data.

#### Acceptance Criteria

1. WHEN a QA run completes, THE QA_Suite SHALL identify every QA_Entity created during the run by
   the QA_Run_Id, including accounts, shoots, bookings, uploaded raw files, edited files, CubiCasa
   test orders and references, equipment items, equipment assignments, invoices, payment-reminder
   records, notification logs, test clients, test addresses, availability windows, blocked
   windows, and generated reports.
2. WHEN deleting an identified QA_Entity, THE QA_Suite SHALL request confirmation through the
   Confirmation_Gate before performing the deletion.
3. WHEN a QA_Entity deletion is confirmed, THE Onboarding_System SHALL remove that QA_Entity.
4. WHEN cleanup completes, THE Onboarding_System SHALL retain no QA_Entity tagged with the
   QA_Run_Id.
5. THE QA_Suite SHALL record the cleanup outcome for each QA_Entity in the QA_Report.

### Requirement 22: Reporting, evidence format, and report-and-fix

**User Story:** As a QA engineer, I want a green/yellow/red, evidence-backed report and a
realistic report-and-fix loop, so that I get a verified result with proof rather than just a list
of pass or fail labels.

#### Acceptance Criteria

1. WHEN a QA run completes, THE QA_Suite SHALL produce a QA_Report that lists each check with a
   pass, fail, blocked, or skipped result and a green, yellow, or red summary.
2. THE QA_Report SHALL include a Markdown report, a JSON report, screenshots, a Playwright trace,
   a video on failure, console logs, a network-failure list, API response excerpts, the created
   QA_Entity identifiers, and the cleanup status.
3. WHEN a check passes, THE QA_Report SHALL include the evidence that proves the pass result for
   that check.
4. WHEN a check fails, THE QA_Suite SHALL continue the remaining checks and produce failure
   evidence for the failing check.
5. IF a developer or coding agent applies a fix for a failing check, THEN THE failing check SHALL
   be re-run.
6. WHEN a re-run of a previously failing check passes, THE QA_Report SHALL mark the latest verified
   state for that check as pass.

### Requirement 23: Core property-test coverage

**User Story:** As a QA engineer, I want the highest-risk behaviors covered as core properties, so
that the confirmation gate, run-scoped cleanup, distance calculation, settings effect, and
role-access denial are verified as invariants rather than single examples.

#### Acceptance Criteria

1. THE QA_Suite SHALL cover the Confirmation_Gate behavior for Destructive_Steps and
   Charge_Triggering_Steps as a core property.
2. THE QA_Suite SHALL cover the QA_Run_Id run-scoped cleanup behavior across all QA_Entity types
   as a core property.
3. THE QA_Suite SHALL cover the distance calculation and Distance_Gating behavior across inside,
   boundary, and outside Seeded_Addresses as a core property.
4. THE QA_Suite SHALL cover the Settings_Effect behavior for persisted Photographer_Settings and
   Admin_Settings as a core property.
5. THE QA_Suite SHALL cover the role-access-denial behavior across Role_Contexts as a core
   property.
