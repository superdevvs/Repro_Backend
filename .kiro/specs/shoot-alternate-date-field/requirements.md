# Requirements Document

## Introduction

This feature promotes the alternate date/time on a shoot to a first-class, shoot-level
field that is read and written consistently across the admin dashboard (overview, modify,
approve, external booking mapping/review, shoot detail modal, and schedule summaries) and
the API. It introduces an explicit, operator-initiated action to copy a shoot's stored
alternate date/time onto the live schedule (the main shoot schedule and, optionally, every
selected service), and it changes external booking auto-mapping so that an alternate date
is persisted to the alternate field only and is never auto-applied to the second service.

Applying the alternate date is treated as an internal schedule update: it does not create a
reschedule request, does not trigger client or photographer notifications (email/SMS), and
does not run shoot-update or shoot-scheduled automations. It does record an activity log
entry for audit.

The feature reuses the existing nullable columns `alternate_scheduled_date` (date),
`alternate_time` (string), and `alternate_scheduled_at` (datetime) on the `shoots` table.

## Glossary

- **Shoot**: A scheduled photography job represented by the `Shoot` model and `shoots` table.
- **Shoot_Service**: A service attached to a shoot via the `shoot_service` pivot, carrying its own `scheduled_at`.
- **Main_Schedule**: The shoot's primary schedule fields: `scheduled_date`, `time`, `scheduled_at`.
- **Alternate_Field**: The shoot-level alternate schedule stored in `alternate_scheduled_date`, `alternate_time`, and `alternate_scheduled_at`.
- **Apply_Alternate_Endpoint**: The backend endpoint `POST /shoots/{shoot}/apply-alternate-date`.
- **Scope**: The request parameter controlling apply breadth, with value `main` or `all_services`.
- **Auto_Mapper**: The `ExternalBookingAutoMapper` service that maps external bookings to shoot/service schedules.
- **Shoot_Resource**: The `ShootResource` API resource that serializes a shoot for API responses.
- **Activity_Logger**: The `ShootActivityLogger` service that records `ShootActivityLog` entries.
- **Reschedule_Request**: A `ShootRescheduleRequest` record produced by the standard reschedule flow.
- **Authorized_Editor**: A user whose role is `admin`, `superadmin`, or `editing_manager`.
- **Actor**: The authenticated user performing the apply action.
- **External_Booking**: An inbound booking processed through the external booking mapping pipeline.

## Requirements

### Requirement 1: First-class alternate date/time field

**User Story:** As an operations admin, I want every shoot to carry a shoot-level alternate date and time, so that a backup slot is recorded consistently regardless of how the shoot was created.

#### Acceptance Criteria

1. THE Shoot SHALL store the alternate schedule in the existing columns `alternate_scheduled_date`, `alternate_time`, and `alternate_scheduled_at`.
2. THE Shoot_Resource SHALL expose `alternate_scheduled_date`, `alternate_time`, and `alternate_scheduled_at` for every shoot, using the same formatting applied to `scheduled_date`/`scheduled_at`.
3. WHERE the Alternate_Field has no stored value, THE Shoot_Resource SHALL return `null` for `alternate_scheduled_date`, `alternate_time`, and `alternate_scheduled_at`.
4. WHERE the Alternate_Field is empty, THE dashboard SHALL keep the alternate date presentation low-profile and SHALL NOT display an empty alternate value as if a slot were set.

### Requirement 2: External booking maps alternate to the alternate field only

**User Story:** As an operations admin, I want an external booking's alternate date to be stored only as the shoot's alternate, so that a backup slot is never mistaken for a real second-service booking.

#### Acceptance Criteria

1. WHEN the Auto_Mapper processes an External_Booking that includes an alternate date, THE Auto_Mapper SHALL write the alternate date and time to `alternate_scheduled_date`, `alternate_time`, and `alternate_scheduled_at` only.
2. WHEN the Auto_Mapper processes an External_Booking with a preferred date, THE Auto_Mapper SHALL map the preferred date and time to the Main_Schedule.
3. IF an External_Booking has multiple services and includes an alternate date, THEN THE Auto_Mapper SHALL leave the second service's `scheduled_at` unset by the alternate value.
4. WHEN the Auto_Mapper resolves an alternate date that has no accompanying time, THE Auto_Mapper SHALL store `alternate_scheduled_date` and leave `alternate_time` and `alternate_scheduled_at` null.

### Requirement 3: Service schedules remain independent

**User Story:** As an operations admin, I want each service's date to stay independent, so that applying or recording an alternate never silently moves a service.

#### Acceptance Criteria

1. THE Shoot_Service `scheduled_at` SHALL change only when explicitly edited or when the Apply_Alternate_Endpoint is invoked with Scope `all_services`.
2. WHEN the Alternate_Field is set or updated on a Shoot, THE system SHALL leave every Shoot_Service `scheduled_at` unchanged.
3. WHEN the Apply_Alternate_Endpoint is invoked with Scope `main`, THE system SHALL leave every Shoot_Service `scheduled_at` unchanged.

### Requirement 4: Single shared field across all surfaces

**User Story:** As an operations admin, I want the alternate field to read and write the same data everywhere, so that the value I see in one screen matches every other screen.

#### Acceptance Criteria

1. THE overview cards, modify shoot form, approve shoot flow, external booking mapping/review panel, shoot detail modal, and schedule summary SHALL read the Alternate_Field from the Shoot_Resource.
2. WHEN an Authorized_Editor changes the alternate date or time through the modify shoot form or the approve shoot flow, THE system SHALL persist the change to `alternate_scheduled_date`, `alternate_time`, and `alternate_scheduled_at`.
3. WHEN the Alternate_Field is updated through any surface, THE system SHALL return the updated value to all surfaces through the Shoot_Resource so the displayed alternate stays in sync.

### Requirement 5: Apply alternate date endpoint

**User Story:** As an Authorized_Editor, I want to apply a shoot's stored alternate date to its live schedule on demand, so that I can promote a backup slot without going through the full reschedule flow.

#### Acceptance Criteria

1. THE Apply_Alternate_Endpoint SHALL accept `POST /shoots/{shoot}/apply-alternate-date` with a body containing `scope` whose value is `main` or `all_services`.
2. WHERE `scope` is omitted, THE Apply_Alternate_Endpoint SHALL default Scope to `main`.
3. IF the Shoot has no stored Alternate_Field value, THEN THE Apply_Alternate_Endpoint SHALL reject the request with an error and SHALL leave the Main_Schedule and all Shoot_Service schedules unchanged.
4. WHEN the Apply_Alternate_Endpoint is invoked with Scope `main`, THE system SHALL set the Main_Schedule `scheduled_date`, `time`, and `scheduled_at` from the stored Alternate_Field and SHALL leave every Shoot_Service `scheduled_at` unchanged.
5. WHEN the Apply_Alternate_Endpoint is invoked with Scope `all_services`, THE system SHALL set the Main_Schedule from the stored Alternate_Field AND set every selected Shoot_Service `scheduled_at` to the stored alternate value, kept consistent with the Main_Schedule.
6. WHEN the Apply_Alternate_Endpoint applies the alternate with Scope `main`, THE Activity_Logger SHALL record a `ShootActivityLog` entry identifying the Actor and the main-schedule scope.
7. WHEN the Apply_Alternate_Endpoint applies the alternate with Scope `all_services`, THE Activity_Logger SHALL record a `ShootActivityLog` entry identifying the Actor and the all-service scope.
8. WHEN the Apply_Alternate_Endpoint completes successfully, THE Apply_Alternate_Endpoint SHALL return the updated Shoot_Resource.
9. WHEN the Apply_Alternate_Endpoint applies the alternate, THE system SHALL retain the Alternate_Field values unchanged for audit.

### Requirement 6: Apply action runs as an internal update

**User Story:** As an operations admin, I want applying an alternate date to be an internal change, so that clients and photographers are not notified and no reschedule automations fire.

#### Acceptance Criteria

1. WHEN the Apply_Alternate_Endpoint applies the alternate, THE system SHALL NOT create a Reschedule_Request.
2. WHEN the Apply_Alternate_Endpoint applies the alternate, THE system SHALL NOT invoke the reschedule or shoot-update notification flow.
3. WHEN the Apply_Alternate_Endpoint applies the alternate, THE system SHALL NOT send any email or SMS message.
4. WHEN the Apply_Alternate_Endpoint applies the alternate, THE system SHALL NOT trigger `SHOOT_UPDATED` or `SHOOT_SCHEDULED` automations.

### Requirement 7: Button visibility and default action

**User Story:** As an Authorized_Editor, I want the apply controls to appear only when they are usable, so that the interface reflects what actions are available for the shoot.

#### Acceptance Criteria

1. WHERE the Shoot has a stored Alternate_Field value, THE dashboard SHALL display the "Use as main date" control.
2. WHERE the Shoot has no stored Alternate_Field value, THE dashboard SHALL hide the "Use as main date" control.
3. WHERE the Shoot has a stored Alternate_Field value AND the Shoot has more than one service, THE dashboard SHALL display the "Apply to all services" control.
4. IF the Shoot has one or zero services, THEN THE dashboard SHALL hide the "Apply to all services" control.
5. WHEN an Authorized_Editor activates the default apply action, THE dashboard SHALL invoke the Apply_Alternate_Endpoint with Scope `main`.

### Requirement 8: Permissions

**User Story:** As a system owner, I want the apply action restricted to schedule editors, so that only authorized roles can change a shoot's live schedule.

#### Acceptance Criteria

1. WHERE the Actor is an Authorized_Editor, THE Apply_Alternate_Endpoint SHALL permit the apply action.
2. IF the Actor is not an Authorized_Editor, THEN THE Apply_Alternate_Endpoint SHALL reject the request as unauthorized and SHALL leave the Main_Schedule and all Shoot_Service schedules unchanged.

### Requirement 9: Regression coverage

**User Story:** As a maintainer, I want the documented regression cases verified, so that the behavior change and internal-update guarantees stay protected.

#### Acceptance Criteria

1. WHEN an External_Booking with multiple services and an alternate date is auto-mapped, THE Auto_Mapper SHALL leave the second service's `scheduled_at` unset by the alternate value and SHALL persist the alternate to `alternate_scheduled_at` (regression for the case-2 behavior change).
2. WHEN the Apply_Alternate_Endpoint is invoked with Scope `main` on a Shoot whose Alternate_Field is set, THE system SHALL update the Main_Schedule and SHALL leave every Shoot_Service `scheduled_at` unchanged.
3. WHEN the Apply_Alternate_Endpoint is invoked with Scope `all_services` on a multi-service Shoot whose Alternate_Field is set, THE system SHALL update the Main_Schedule and every selected Shoot_Service `scheduled_at` to the stored alternate value.
4. IF the Apply_Alternate_Endpoint is invoked on a Shoot with no stored Alternate_Field value, THEN THE system SHALL return an error and SHALL make no schedule changes.
5. WHEN the Apply_Alternate_Endpoint applies the alternate, THE system SHALL record exactly one `ShootActivityLog` entry, create no Reschedule_Request, and send no email or SMS.
6. WHEN the Apply_Alternate_Endpoint applies the alternate, THE system SHALL leave the Alternate_Field values unchanged after the operation completes.

