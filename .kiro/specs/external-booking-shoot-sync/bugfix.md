# Bugfix Requirements Document

## Introduction

External shoot bookings submitted from the reprophotos.com (Lovable) site arrive at the
dashboard through `POST /api/external/book-shoot`, which is validated by
`ExternalBookingRequest` and handled by `ExternalBookingController::bookShoot`.

The external booking form has been redesigned. The old form collected a single
photographer and a single preferred date/time. The new form lets a client provide
**preferred** and **alternate** date/time inputs and select **one or more
photographers**, while the dashboard needs to keep representing scheduling and
photographer assignment in its own richer model.

The dashboard models scheduling and photographer assignment differently from what the
external endpoint currently captures:

- A shoot has shoot-level scheduling fields (`scheduled_at`, `scheduled_date`, `time`).
- A shoot supports **per-service photographer assignment** and **per-service scheduling**
  through the `shoot_service` pivot (`photographer_id`, `scheduled_at`), which is how the
  dashboard represents multiple photographers working one shoot.
- There is also a legacy shoot-level `photographer_id`.

The current endpoint only accepts `preferred_date` and `preferred_time`, has no field for
an alternate date/time, and has no field for photographer(s). The controller hardcodes
`'photographer_id' => null`, never populates the per-service `photographer_id` on the
pivot, and fabricates a `00:00` time when no time is supplied. As a result, when a booking
is made on the new form, the selected photographers and the alternate scheduling
information are lost or mismatched, and the shoot details in the dashboard are incomplete
or misleading.

This bug fixes the mapping so external bookings flow into the dashboard's shoot scheduling
model **safely and conservatively**. The guiding principle is that a *wrong* assignment is
worse than *no* assignment: the system auto-maps photographers and schedules only when the
mapping is obvious and unambiguous, leaves anything unclear unassigned, preserves the raw
external payload, records warnings explaining what needs human attention, and raises a
dashboard notification so a reviewer can finish the assignment. The fix is purely additive
and must not break the existing endpoint contract or any legacy behavior.

## Bug Analysis

### Current Behavior (Defect)

1.1 WHEN an external booking is submitted with one or more selected photographers THEN the system discards the photographer selection and creates the shoot with `photographer_id = null` and no per-service `photographer_id` on the `shoot_service` pivot.

1.2 WHEN an external booking is submitted with multiple photographers THEN the system has no field to receive them (the request rejects/ignores the data), so no photographer is associated with any service on the dashboard shoot.

1.3 WHEN an external booking is submitted with an alternate date/time THEN the system has no field to receive it and the alternate scheduling information is dropped entirely.

1.4 WHEN an external booking is submitted with multiple services and per-service scheduling intent THEN the system collapses all scheduling into a single shoot-level `scheduled_at` derived only from `preferred_date` + `preferred_time` and never populates the per-service `shoot_service.scheduled_at`.

1.5 WHEN an external booking provides a preferred date but no preferred time THEN the system defaults the time to `00:00` and stores it as a real scheduled time, producing a misleading midnight schedule rather than a date-only/time-unspecified shoot.

1.6 WHEN an external booking is submitted THEN the system does not persist the original external payload, does not record any mapping warnings, and does not record a mapping status, so a reviewer has no record of what was requested versus what was auto-mapped.

1.7 WHEN an external booking is submitted and contains photographer/schedule intent that cannot be mapped automatically THEN the system creates no dashboard notification, so the booking can sit unreviewed with missing or ambiguous photographer/schedule assignments.

### Expected Behavior (Correct)

**Request acceptance and normalization**

2.1 WHEN an external booking is submitted THEN the system SHALL continue to accept `preferred_date` and `preferred_time`, and SHALL additionally accept the optional fields `alternate_date`, `alternate_time`, a single photographer (`selected_photographer_id`/`photographer_id`), a list of photographers (`selected_photographers`/`requested_photographers`), and an optional explicit `service_assignments` list of `{service_id, photographer_id, scheduled_date, scheduled_time}`.

2.2 WHEN an external booking is submitted THEN the system SHALL normalize the scheduling and photographer inputs into a consistent internal structure of the shape `{preferred:{date,time}, alternate:{date,time}, requested_photographers:[], selected_services:[]}` before mapping.

**Conservative photographer auto-mapping** (a wrong assignment is worse than none)

2.3 WHEN a booking resolves to exactly one service and exactly one photographer THEN the system SHALL assign that photographer to the service via `shoot_service.photographer_id` and MAY also set the legacy shoot-level `photographer_id`.

2.4 WHEN a booking resolves to exactly one service and zero photographers THEN the system SHALL leave the service photographer unassigned.

2.5 WHEN a booking resolves to exactly one service and multiple photographers THEN the system SHALL leave the photographer unassigned, store all requested photographers as `requested_photographers`, and record the warning "Multiple photographers were requested for one service. Please review manually."

2.6 WHEN a booking resolves to multiple services and exactly one photographer THEN the system SHALL assign that photographer to the first service only if eligibility confirms the assignment is safe, and SHALL otherwise leave all services unassigned and store the photographer in `requested_photographers`.

2.7 WHEN a booking resolves to multiple services and multiple photographers THEN the system SHALL NOT guess any assignment, SHALL store all photographers as `requested_photographers`, and SHALL leave every pivot `photographer_id` null unless an explicit `service_assignments` mapping was provided, and SHALL record a warning.

2.8 WHEN an external booking includes an explicit `service_assignments` list THEN the system SHALL apply those per-service photographer and schedule assignments directly instead of inferring them.

**Schedule auto-mapping**

2.9 WHEN a booking resolves to exactly one service THEN the system SHALL assign the preferred date/time to that service and SHALL set the shoot-level schedule from the preferred values (subject to the no-fabricated-time rule below).

2.10 WHEN a booking resolves to multiple services with both a preferred and an alternate date/time THEN the system SHALL assign the preferred values to the first service, the alternate values to the second service (if it exists), leave the third and subsequent services unscheduled, and record warnings describing the unscheduled services.

2.11 WHEN a booking resolves to multiple services with a preferred date/time only THEN the system SHALL assign the preferred values to the first service and leave the second and subsequent services unscheduled, and SHALL NOT copy the preferred values onto every service.

**No fabricated midnight time**

2.12 WHEN a preferred date is provided without a preferred time THEN the system SHALL set `shoot.scheduled_date` to the date, set `shoot.time` to null, set `shoot.scheduled_at` to null, and set the corresponding pivot `scheduled_at` to null, SHALL preserve the date-only preference in metadata, and SHALL record the warning "Preferred date was provided without a time. Time requires manual review."

2.13 WHEN an alternate date is provided without an alternate time THEN the system SHALL apply the same date-only handling as 2.12 (no fabricated time, preserved in metadata, warning recorded).

2.14 WHEN a preferred date and preferred time are both present THEN the system SHALL set the shoot-level `scheduled_at`, `scheduled_date`, and `time` consistently from those values.

**Payload, metadata, and mapping status**

2.15 WHEN an external booking is processed THEN the system SHALL persist the original external booking payload, the normalized `requested_photographers`, and the generated warnings on the shoot (using nullable columns such as `external_booking_payload`, `requested_photographers`, `external_booking_warnings`, `alternate_scheduled_date`, `alternate_time`, `alternate_scheduled_at`).

2.16 WHEN auto-mapping completes THEN the system SHALL record an `external_booking_mapping_status` of `fully_mapped`, `partially_mapped`, or `needs_review` reflecting whether all photographer and schedule values were unambiguously assigned.

2.17 WHEN an external booking is processed THEN the system SHALL still attach the selected services and SHALL set each pivot's `photographer_id` and `scheduled_at` only where safely mapped, leaving them null otherwise, and SHALL NEVER reject the booking because the mapping is incomplete.

2.18 WHEN an external booking with the new fields is processed THEN the system SHALL continue to create the shoot with `STATUS_REQUESTED` regardless of how much of the mapping could be completed.

**Notification for review**

2.19 WHEN a processed external booking needs review — that is, the source is external AND any of the following hold: a photographer or schedule is unassigned, schedules were auto-mapped, multiple photographers were requested, warnings are non-empty, or the mapping status is `needs_review`/`partially_mapped` — THEN the system SHALL create a dashboard notification of type `shoot_assignment_review`.

2.20 WHEN the review notification is created THEN it SHALL be visible to Admin/Super Admin users (or the existing scheduling-review role group) and SHALL include `type`, `shoot_id`, `title`, `message`, `action_type = open_shoot_details_popup`, and `action_payload = {shoot_id, focus:"schedule_assignments"}`.

**Frontend review experience**

2.21 WHEN a reviewer clicks the `shoot_assignment_review` notification THEN the system SHALL mark the notification read, fetch the shoot, open the shoot details popup directly (not merely navigate to a list), focus/scroll to the photographer/schedule assignment section, and display the recorded warnings.

2.22 WHEN the shoot details popup is shown for a shoot that originated from the external site THEN the system SHALL display an "External Booking Mapping" section presenting the preferred and alternate schedule, the auto-mapped services, the requested photographers, and the warnings.

### Unchanged Behavior (Regression Prevention)

3.1 WHEN a legacy external booking provides only a single preferred date and time and no photographers THEN the system SHALL CONTINUE TO create the shoot with `scheduled_at`, `scheduled_date`, and `time` derived from the preferred date/time.

3.2 WHEN an external booking omits all scheduling information THEN the system SHALL CONTINUE TO create the shoot in `STATUS_REQUESTED` with null scheduling fields.

3.3 WHEN an external booking is processed THEN the system SHALL CONTINUE TO find-or-create the client by email, apply guest vs. account creation rules, and resolve the client's rep exactly as before.

3.4 WHEN an external booking is processed THEN the system SHALL CONTINUE TO compute pricing (base quote, discounts, coupons, taxes, total) and attach the selected services with catalog prices exactly as before.

3.5 WHEN an external booking is processed THEN the system SHALL CONTINUE TO set the legacy `service_id`, `property_details`, `source`, payment status, product status, and log the `shoot_requested` activity as before.

3.6 WHEN an external booking specifies no photographer THEN the system SHALL CONTINUE TO create the shoot with no photographer assigned (no regression to a default or incorrect photographer).

3.7 WHEN an external booking is submitted with a payload that contains only the existing fields and none of the new photographer or alternate-scheduling fields THEN the system SHALL CONTINUE TO accept and process it without error and produce the same shoot it produces today (full backward compatibility for the existing site).

3.8 WHEN an external booking provides only `preferred_date`/`preferred_time` THEN the system SHALL CONTINUE TO treat those as the booking schedule, with the new optional fields defaulting to absent.

3.9 WHEN the new nullable shoot columns and notification are added THEN the system SHALL CONTINUE TO leave all other shoot behavior, queued jobs (e.g. `ProcessExternalShootRequestedJob`), and email/account-setup flows unchanged.

## Bug Condition Specification

```pascal
FUNCTION isBugCondition(X)
  INPUT: X of type ExternalBookingRequest
  OUTPUT: boolean

  // The bug is triggered whenever the booking carries scheduling/photographer
  // intent that the current mapping cannot represent on the dashboard shoot,
  // or carries a date-only preference the current code fabricates a time for.
  RETURN (X has one or more selected/requested photographers)
      OR (X has explicit service_assignments)
      OR (X has an alternate date/time)
      OR (X resolves to multiple services with scheduling intent)
      OR (X has a preferred_date with no preferred_time)
END FUNCTION
```

```pascal
// Property: Fix Checking - safe, conservative mapping onto the dashboard shoot
FOR ALL X WHERE isBugCondition(X) DO
  shoot ← bookShoot'(X)

  // Conservative photographer assignment: assign only when unambiguous.
  ASSERT (oneService(X) AND onePhotographer(X))
            IMPLIES pivot(shoot, service1).photographer_id = thatPhotographer
  ASSERT (oneService(X) AND multiplePhotographers(X))
            IMPLIES pivot(shoot, service1).photographer_id = null
  ASSERT (multipleServices(X) AND multiplePhotographers(X) AND NOT hasExplicitAssignments(X))
            IMPLIES allPivotPhotographerIds(shoot) = null
  ASSERT NOT anyFabricatedPhotographerAssignment(shoot, X)

  // Schedule auto-mapping: predictable per-service mapping, no copy-to-all.
  ASSERT oneService(X) IMPLIES pivot(shoot, service1).schedule = preferred(X)
  ASSERT (multipleServices(X) AND hasAlternate(X))
            IMPLIES pivot(shoot, service2).schedule = alternate(X)
  ASSERT (multipleServices(X) AND preferredOnly(X))
            IMPLIES servicesAfterFirstUnscheduled(shoot)

  // Never fabricate a midnight time.
  ASSERT (X.preferred_date set AND X.preferred_time empty)
            IMPLIES shoot.time = null AND shoot.scheduled_at = null
                    AND pivot(shoot, service1).scheduled_at = null

  // Provenance, warnings, status, and review signal preserved.
  ASSERT shoot.external_booking_payload = rawPayload(X)
  ASSERT shoot.requested_photographers = normalizedPhotographers(X)
  ASSERT shoot.external_booking_mapping_status IN {fully_mapped, partially_mapped, needs_review}
  ASSERT needsReview(shoot) IMPLIES notificationCreated(shoot, type = shoot_assignment_review)
  ASSERT shoot.status = STATUS_REQUESTED
END FOR
```

```pascal
// Property: Preservation Checking - legacy bookings are unaffected
FOR ALL X WHERE NOT isBugCondition(X) DO
  ASSERT bookShoot(X) = bookShoot'(X)
END FOR
```

**Key Definitions:**

- **F** (`bookShoot`): the current external booking handler before the fix.
- **F'** (`bookShoot'`): the external booking handler after the fix.
- **C(X)** `isBugCondition`: external bookings carrying multi-photographer, explicit or
  per-service scheduling, alternate date/time, multi-service scheduling intent, or a
  date-only preferred value.
- **P(result)**: the resulting dashboard shoot maps photographers and schedules only when
  unambiguous, leaves anything unclear unassigned, preserves the raw payload, requested
  photographers, warnings and mapping status, raises a `shoot_assignment_review`
  notification when review is needed, and remains a `requested` shoot.
