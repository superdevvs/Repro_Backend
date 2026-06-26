# External Booking Shoot Sync Bugfix Design

## Overview

External shoot bookings from the reprophotos.com (Lovable) site reach the dashboard through
`POST /api/external/book-shoot`, validated by `ExternalBookingRequest` and handled by
`ExternalBookingController::bookShoot`. The redesigned external form now sends **preferred**
and **alternate** date/time inputs and **one or more photographers**, but the current
endpoint only understands a single `preferred_date`/`preferred_time` and hardcodes
`photographer_id => null`. As a result the selected photographers and alternate scheduling
are silently dropped, a `00:00` time is fabricated when none is provided, and no record of
the original request, the warnings, or the mapping outcome is kept. Nothing prompts a human
to finish an ambiguous assignment.

This fix makes external bookings flow into the dashboard's richer scheduling/assignment
model **safely and conservatively**. The guiding principle, taken directly from the
requirements, is that *a wrong assignment is worse than no assignment*: the system maps
photographers and schedules only when the mapping is obvious, leaves anything ambiguous
unassigned, preserves the raw external payload, records warnings, stamps a mapping status,
and raises a `shoot_assignment_review` dashboard notification when a reviewer is needed.

The fix is purely **additive**. The controller stays thin and delegates to a small set of
new collaborators. All new request fields are optional/nullable and all new shoot columns
are nullable, so the existing external site and every legacy code path behave exactly as
they do today (Requirements 3.1–3.9).

The fix touches three layers:

1. **Backend mapping pipeline** — new DTO + normalizer + auto-mapper + warning builder +
   notification service invoked from a thin `bookShoot`.
2. **Persistence** — a migration adding nullable columns to `shoots`, plus writing
   `photographer_id`/`scheduled_at` onto the `shoot_service` pivot only where safe.
3. **Frontend review experience** — the notification click opens the shoot details modal
   focused on the schedule/assignment section, and the modal shows an "External Booking
   Mapping" panel. The frontend lives in this repository under `frontend/`.

## Glossary

- **Bug_Condition (C)**: `isBugCondition(X)` — an external booking carries scheduling/
  photographer intent the current mapping cannot represent (multiple/selected photographers,
  explicit `service_assignments`, an alternate date/time, multi-service scheduling intent,
  or a preferred date with no preferred time).
- **Property (P)**: the resulting shoot maps photographers and schedules only when
  unambiguous, leaves anything unclear unassigned, preserves the raw payload, requested
  photographers, warnings and mapping status, raises a `shoot_assignment_review`
  notification when review is needed, and remains a `requested` shoot.
- **Preservation**: legacy behavior that must remain byte-for-byte identical — client find/
  create, pricing, service attachment with catalog prices, legacy `service_id`,
  `property_details`, `source`, payment/product status, the `shoot_requested` activity log,
  `STATUS_REQUESTED`, and the `ProcessExternalShootRequestedJob` dispatch.
- **F** (`bookShoot`): the external booking handler before the fix.
- **F'** (`bookShoot'`): the external booking handler after the fix.
- **`bookShoot`**: the controller method in `app/Http/Controllers/API/ExternalBookingController.php`
  that creates a `requested` shoot from an external payload.
- **`attachServices`**: `App\Services\Shoots\ShootMutationSupportService::attachServices`,
  which syncs the `shoot_service` pivot (price, quantity, `photographer_id`, `scheduled_at`,
  workflow/delivery status). It already supports per-service `photographer_id` and
  `scheduled_at` via the `$services` array.
- **Resolved services**: the ordered list of services from the request (`services[]`), used
  to decide "first service", "second service", etc. for schedule/photographer mapping.
- **Requested photographers**: the normalized, de-duplicated list of photographer ids the
  client asked for, persisted on `shoots.requested_photographers` regardless of whether any
  were mapped.
- **Mapping status**: `shoots.external_booking_mapping_status` ∈
  `{fully_mapped, partially_mapped, needs_review}`.
- **Dashboard notification**: an in-app notification surfaced by
  `DashboardController::notifications`, which is derived from `ShootActivityLog` rows via
  `getActivityLogsForRole`. Admin/Super Admin/editing_manager/salesrep see all shoot
  activity logs. There is no separate notifications table; the review notification is a
  `ShootActivityLog` row whose `action = 'shoot_assignment_review'` and whose `metadata`
  carries the structured payload.

## Bug Details

### Bug Condition

The bug manifests whenever an external booking carries scheduling/photographer intent that
the current handler cannot represent on the dashboard shoot, or carries a date-only
preference for which the current code fabricates a midnight time. The current
`bookShoot` discards photographer selections (`photographer_id => null`, no pivot
`photographer_id`), has no field for an alternate date/time, collapses multi-service
scheduling into one shoot-level `scheduled_at`, and fabricates `00:00` when no time is
supplied.

**Formal Specification:**
```
FUNCTION isBugCondition(X)
  INPUT: X of type ExternalBookingRequest payload
  OUTPUT: boolean

  RETURN hasSelectedOrRequestedPhotographers(X)
      OR hasExplicitServiceAssignments(X)
      OR hasAlternateDateOrTime(X)
      OR (resolvesToMultipleServices(X) AND hasSchedulingIntent(X))
      OR (X.preferred_date IS NOT EMPTY AND X.preferred_time IS EMPTY)
END FUNCTION
```

### Examples

- **Single photographer, single service** — client books 1 service and selects photographer
  #42. Today: shoot created with `photographer_id = null` and pivot `photographer_id = null`.
  Expected: pivot `photographer_id = 42` (and legacy shoot `photographer_id` may be set).
- **Multiple photographers, one service** — client books 1 service and selects photographers
  #42 and #57. Today: data rejected/ignored. Expected: no photographer assigned, both stored
  in `requested_photographers`, warning "Multiple photographers were requested for one
  service. Please review manually." recorded.
- **Alternate date/time, two services** — preferred 2026-03-01 09:00, alternate 2026-03-02
  13:00, services A and B. Today: alternate dropped, both services collapse to one
  shoot-level time. Expected: service A scheduled at preferred, service B at alternate,
  alternate persisted on the shoot.
- **Preferred date without time** — preferred_date 2026-03-01, no preferred_time. Today:
  stored as `2026-03-01 00:00`. Expected: `scheduled_date = 2026-03-01`, `time = null`,
  `scheduled_at = null`, pivot `scheduled_at = null`, warning "Preferred date was provided
  without a time. Time requires manual review." recorded.
- **Edge case — legacy payload** (preferred date + time only, no photographers, single
  service): not a bug condition; must produce exactly today's shoot (Requirement 3.7/3.8).

## Expected Behavior

### Preservation Requirements

**Unchanged Behaviors (Requirements 3.1–3.9):**
- Legacy single preferred date + time with no photographers still sets `scheduled_at`,
  `scheduled_date`, `time` from the preferred values (3.1).
- A booking with no scheduling still creates a `STATUS_REQUESTED` shoot with null scheduling
  fields (3.2).
- Client find-or-create by email, guest vs. account rules, and rep resolution are unchanged
  (3.3).
- Pricing (base quote, discounts, coupons, taxes, total) and service attachment with catalog
  prices are unchanged (3.4).
- Legacy `service_id`, `property_details`, `source`, payment status, product status, and the
  `shoot_requested` activity log are unchanged (3.5).
- No photographer specified still means no photographer assigned (3.6).
- A payload with only the existing fields is accepted and produces the same shoot as today
  (3.7, 3.8).
- All other shoot behavior, queued jobs (`ProcessExternalShootRequestedJob`), and email/
  account-setup flows are unchanged (3.9).

**Scope:** All inputs where `isBugCondition(X)` is false must be completely unaffected. This
includes legacy payloads (existing fields only), bookings with no scheduling, and bookings
with a single service + single preferred date/time + at most one photographer where the
photographer field is absent.

The actual expected correct behavior for buggy inputs is defined in
[Correctness Properties](#correctness-properties) (Property 1) and the
[Auto-Mapping Algorithm](#auto-mapping-algorithm).

## Hypothesized Root Cause

The defect is not a single broken line; it is **missing capability** in the request schema,
the controller, and the persistence layer. The most likely contributing causes:

1. **Request schema gap**: `ExternalBookingRequest::rules()` has no fields for
   `alternate_date`, `alternate_time`, photographer(s), or `service_assignments`, so even
   when the new form sends them, `$request->validated()` strips them before the controller
   sees them.

2. **Hardcoded null photographer**: `bookShoot` literally sets `'photographer_id' => null`
   and never passes a `photographer_id` into `attachServices`, so the pivot column added by
   `2026_02_22_140000_add_photographer_id_to_shoot_service_table` is always null for external
   bookings.

3. **Single shoot-level schedule only**: `bookShoot` derives one `$scheduledAt` from
   `preferred_date`/`preferred_time` and never sets per-service `shoot_service.scheduled_at`
   for multi-service bookings, collapsing all scheduling intent.

4. **Fabricated midnight time**: `$time = $validated['preferred_time'] ?? '00:00';` invents a
   real time when none was provided, producing a misleading midnight schedule.

5. **No provenance / no review signal**: `shoots` has no columns for the raw payload,
   requested photographers, warnings, alternate schedule, or mapping status, and nothing
   creates a notification, so ambiguous bookings sit unreviewed.

## Correctness Properties

Property 1: Bug Condition - Conservative, Lossless External Booking Mapping

_For any_ external booking payload where the bug condition holds (`isBugCondition` returns
true), the fixed `bookShoot` SHALL produce a `STATUS_REQUESTED` shoot that: assigns a
photographer to a service via `shoot_service.photographer_id` only when exactly one service
and exactly one photographer resolve (and otherwise leaves pivot `photographer_id` null
unless an explicit `service_assignments` mapping was given); maps preferred/alternate
schedules per-service without copying preferred values onto every service; never fabricates a
time when only a date is provided (`time`, `scheduled_at`, and pivot `scheduled_at` are null
in that case); persists the raw external payload, the normalized `requested_photographers`,
and the generated warnings; records an `external_booking_mapping_status` of `fully_mapped`,
`partially_mapped`, or `needs_review`; and creates a `shoot_assignment_review` dashboard
notification whenever review is needed.

**Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 2.10, 2.11, 2.12, 2.13, 2.14, 2.15, 2.16, 2.17, 2.18, 2.19, 2.20**

Property 2: Preservation - Legacy Bookings Unaffected

_For any_ external booking payload where the bug condition does NOT hold (`isBugCondition`
returns false) — that is, payloads containing only the existing fields with at most a single
preferred date+time and no new photographer/alternate/service-assignment intent — the fixed
`bookShoot` SHALL produce the same shoot as the original handler, preserving client find/
create, pricing, service attachment with catalog prices, legacy `service_id`,
`property_details`, `source`, payment/product status, the `shoot_requested` activity log,
`STATUS_REQUESTED`, and the `ProcessExternalShootRequestedJob` dispatch.

**Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9**

## Architecture

### Component Diagram

```
                          POST /api/external/book-shoot
                                      │
                                      ▼
                        ┌─────────────────────────────┐
                        │   ExternalBookingRequest     │  (+ new optional/nullable rules)
                        │   validate() → validated()   │
                        └──────────────┬──────────────┘
                                       ▼
                        ┌─────────────────────────────┐
                        │  ExternalBookingController   │  thin orchestrator
                        │        ::bookShoot           │  (DB::transaction)
                        └──────────────┬──────────────┘
            ┌──────────────────────────┼───────────────────────────────┐
            ▼                          ▼                                ▼
 ┌────────────────────┐  ┌─────────────────────────────┐  ┌──────────────────────────┐
 │ ExternalBookingData │  │ ExternalBookingSchedule     │  │  (existing collaborators) │
 │  (DTO from request) │─▶│ Normalizer                  │  │  ShootMutationSupport     │
 └────────────────────┘  │  → normalized structure     │  │  Service (pricing,        │
                         └──────────────┬──────────────┘  │  attachServices), tax,    │
                                        ▼                  │  activity logger          │
                         ┌─────────────────────────────┐  └──────────────────────────┘
                         │ ExternalBookingAutoMapper   │
                         │  → per-service assignments  │
                         │    + shoot-level schedule   │
                         │    + mapping_status         │
                         └──────────────┬──────────────┘
                                        ▼
                         ┌─────────────────────────────┐
                         │ ExternalBookingWarningBuilder│
                         │  → warnings[]                │
                         └──────────────┬──────────────┘
                                        ▼
                         Shoot::create(...) + attachServices(pivot w/ photographer_id,
                                        │                     scheduled_at where safe)
                                        ▼
                         ┌─────────────────────────────┐
                         │ ExternalBookingNotification │
                         │ Service → shoot_assignment_  │
                         │ review ShootActivityLog row  │
                         └──────────────┬──────────────┘
                                        ▼
                          DashboardController::notifications
                                        │
                                        ▼
                  Frontend: useNotifications → NotificationCenter
                   → open ShootDetailsModal focused on assignments
                   → "External Booking Mapping" panel
```

### Data Flow (booking submission → mapping → notification)

1. **Submit & validate**: The external site posts the payload. `ExternalBookingRequest`
   validates it. New fields are optional/nullable, so legacy payloads pass unchanged
   (2.1, 3.7).
2. **Build DTO**: `ExternalBookingData::fromRequest($request)` captures the validated input
   plus the raw payload for provenance (2.15).
3. **Normalize**: `ExternalBookingScheduleNormalizer::normalize($data)` produces the internal
   structure `{preferred:{date,time}, alternate:{date,time}, requested_photographers:[],
   selected_services:[]}` (2.2).
4. **Auto-map**: `ExternalBookingAutoMapper::map($normalized)` applies the conservative
   photographer rules (cases A–E) and schedule rules (cases 1–4) plus the no-fabricated-time
   rule, producing `{shootSchedule, serviceAssignments[], mappingStatus}` (2.3–2.14, 2.16).
5. **Build warnings**: `ExternalBookingWarningBuilder::build($normalized, $mappingResult)`
   collects human-readable warnings (2.5, 2.7, 2.10, 2.12, 2.13).
6. **Persist**: Inside the existing `DB::transaction`, the controller creates the shoot with
   shoot-level schedule + new metadata columns, then calls `attachServices($shoot,
   $servicesWithAssignments)` so the pivot carries `photographer_id`/`scheduled_at` only
   where safely mapped (2.15, 2.17, 2.18).
7. **Notify**: If `ExternalBookingAutoMapper`/`WarningBuilder` flagged review,
   `ExternalBookingNotificationService::notifyIfNeeded($shoot, $mappingResult, $warnings)`
   writes a `shoot_assignment_review` `ShootActivityLog` row whose metadata carries
   `{type, shoot_id, title, message, action_type, action_payload}` (2.19, 2.20).
8. **Review**: The reviewer sees the notification (admin/superadmin role visibility via the
   existing `getActivityLogsForRole`), clicks it, the shoot details modal opens focused on
   the schedule/assignment section, warnings and the "External Booking Mapping" panel are
   shown (2.21, 2.22).

The controller keeps all existing steps (client, pricing, coupon, activity log, job dispatch)
exactly where they are; the new collaborators only add behavior.

## Components and Interfaces

> Namespacing: new backend classes live under `App\Services\ExternalBooking\` except the DTO
> which lives under `App\Services\ExternalBooking\Data`. Method signatures below are the
> contract; concrete return types are simple value objects/arrays to keep them testable in
> isolation.

### `ExternalBookingData` (DTO)

Responsibility: immutable carrier of the validated request plus the raw payload. Built once,
passed to the normalizer. No business logic.

```php
namespace App\Services\ExternalBooking\Data;

final class ExternalBookingData
{
    public function __construct(
        public readonly array $rawPayload,          // full original request (provenance, 2.15)
        public readonly array $services,            // [['id'=>int,'quantity'=>?int], ...]
        public readonly ?string $preferredDate,     // 'Y-m-d' or null
        public readonly ?string $preferredTime,     // 'HH:MM' or null
        public readonly ?string $alternateDate,     // 'Y-m-d' or null (2.1)
        public readonly ?string $alternateTime,     // 'HH:MM' or null (2.1)
        public readonly array $requestedPhotographerIds, // normalized, de-duped (2.1, 2.2)
        public readonly array $serviceAssignments,  // explicit [{service_id, photographer_id,
                                                     //  scheduled_date, scheduled_time}] (2.8)
        public readonly string $source,
    ) {}

    public static function fromRequest(\App\Http\Requests\ExternalBookingRequest $request): self;
}
```

### `ExternalBookingScheduleNormalizer`

Responsibility: collapse the several accepted input shapes into one consistent internal
structure before mapping (2.2). Handles the single/list photographer aliases
(`selected_photographer_id`/`photographer_id` and `selected_photographers`/
`requested_photographers`), de-duplicates photographer ids, and preserves explicit
`service_assignments`.

```php
namespace App\Services\ExternalBooking;

final class ExternalBookingScheduleNormalizer
{
    /**
     * @return NormalizedBooking {
     *   preferred: {date: ?string, time: ?string},
     *   alternate: {date: ?string, time: ?string},
     *   requested_photographers: int[],
     *   selected_services: array<{id:int, quantity:int}>,
     *   service_assignments: array<{service_id:int, photographer_id:?int,
     *                               scheduled_date:?string, scheduled_time:?string}>
     * }
     */
    public function normalize(\App\Services\ExternalBooking\Data\ExternalBookingData $data): NormalizedBooking;
}
```

### `ExternalBookingAutoMapper`

Responsibility: apply the conservative photographer + schedule mapping rules and produce
per-service assignments, the shoot-level schedule, and the mapping status (2.3–2.14, 2.16).
Pure function of the normalized booking — no DB writes — so it is exhaustively unit/property
testable. When explicit `service_assignments` are present, they are applied directly and
inference is skipped (2.8).

```php
namespace App\Services\ExternalBooking;

final class ExternalBookingAutoMapper
{
    /**
     * @return MappingResult {
     *   shootSchedule: {scheduled_date: ?string, time: ?string, scheduled_at: ?string},
     *   alternateSchedule: {alternate_scheduled_date: ?string, alternate_time: ?string,
     *                       alternate_scheduled_at: ?string},
     *   serviceAssignments: array<int serviceId, {photographer_id:?int, scheduled_at:?string}>,
     *   legacyPhotographerId: ?int,   // for shoot-level photographer_id (case A only)
     *   mappingStatus: 'fully_mapped'|'partially_mapped'|'needs_review',
     *   flags: {                       // signals used by WarningBuilder + Notification
     *     multiplePhotographersForOneService: bool,
     *     unmappablePhotographers: bool,
     *     unscheduledServices: int[],
     *     preferredDateMissingTime: bool,
     *     alternateDateMissingTime: bool
     *   }
     * }
     */
    public function map(NormalizedBooking $booking): MappingResult;
}
```

### `ExternalBookingWarningBuilder`

Responsibility: turn the mapper's `flags` into the human-readable warnings list persisted on
the shoot (2.5, 2.7, 2.10, 2.12, 2.13). Stateless.

```php
namespace App\Services\ExternalBooking;

final class ExternalBookingWarningBuilder
{
    /** @return string[] warnings */
    public function build(NormalizedBooking $booking, MappingResult $result): array;
}
```

Warning catalog (exact strings):
- `"Multiple photographers were requested for one service. Please review manually."` (2.5)
- `"Multiple photographers were requested across multiple services. Please assign manually."` (2.7)
- `"Service #{n} could not be scheduled automatically and needs manual scheduling."` (2.10, 2.11)
- `"Preferred date was provided without a time. Time requires manual review."` (2.12)
- `"Alternate date was provided without a time. Time requires manual review."` (2.13)
- `"A single photographer was requested for multiple services. Assignment left for manual review."` (2.6, when eligibility cannot confirm)

### `ExternalBookingNotificationService`

Responsibility: decide whether review is needed and, if so, create the
`shoot_assignment_review` dashboard notification (2.19, 2.20). The notification is persisted
as a `ShootActivityLog` row because `DashboardController::notifications` derives dashboard
notifications from `ShootActivityLog` via `getActivityLogsForRole` (admin/superadmin/
editing_manager/salesrep already see all shoot activity logs — this is the existing
scheduling-review role group). The structured payload lives in `metadata`.

```php
namespace App\Services\ExternalBooking;

final class ExternalBookingNotificationService
{
    /**
     * Creates a shoot_assignment_review notification when review is needed.
     * needsReview = source is external AND (
     *   any pivot photographer/schedule unassigned OR schedules auto-mapped OR
     *   multiple photographers requested OR warnings non-empty OR
     *   mappingStatus IN {needs_review, partially_mapped})
     * @return bool whether a notification was created
     */
    public function notifyIfNeeded(\App\Models\Shoot $shoot, MappingResult $result, array $warnings): bool;
}
```

### `ExternalBookingController::bookShoot` (refactored, thin)

Responsibility: orchestrate only. It builds the DTO, runs normalize → map → warnings, creates
the shoot with the mapped shoot-level schedule + new metadata columns, attaches services with
per-service pivot assignments, logs the existing `shoot_requested` activity, dispatches the
existing job, then calls the notification service. All existing client/pricing/coupon logic
is preserved verbatim.

```php
public function bookShoot(ExternalBookingRequest $request)
{
    $validated = $request->validated();
    $data       = ExternalBookingData::fromRequest($request);
    $normalized = $this->scheduleNormalizer->normalize($data);
    $mapping    = $this->autoMapper->map($normalized);
    $warnings   = $this->warningBuilder->build($normalized, $mapping);

    $result = DB::transaction(function () use ($validated, $data, $normalized, $mapping, $warnings) {
        // ... unchanged: client find/create, pricing, rep, property_details ...
        $shoot = Shoot::create([ /* unchanged fields */
            'photographer_id' => $mapping->legacyPhotographerId,           // null unless case A
            'scheduled_at'    => $mapping->shootSchedule['scheduled_at'],   // null when no time (2.12)
            'scheduled_date'  => $mapping->shootSchedule['scheduled_date'],
            'time'            => $mapping->shootSchedule['time'],
            'alternate_scheduled_date' => $mapping->alternateSchedule['alternate_scheduled_date'],
            'alternate_time'           => $mapping->alternateSchedule['alternate_time'],
            'alternate_scheduled_at'   => $mapping->alternateSchedule['alternate_scheduled_at'],
            'requested_photographers'        => $normalized->requested_photographers,
            'external_booking_payload'       => $data->rawPayload,
            'external_booking_warnings'      => $warnings,
            'external_booking_mapping_status'=> $mapping->mappingStatus,
            'status' => Shoot::STATUS_REQUESTED,            // unchanged (2.18)
            // ... unchanged pricing/product/source fields ...
        ]);

        $this->shootSupport->attachServices(
            $shoot,
            $this->buildServicesPayload($normalized, $mapping)  // injects photographer_id + scheduled_at per service
        );
        // ... unchanged: coupon increment, activity log, ghost users ...
        return [ /* unchanged */ ];
    });

    // ... unchanged: account setup email, job dispatch ...
    $this->notificationService->notifyIfNeeded($result['shoot'], $mapping, $warnings);
    // ... unchanged JSON response ...
}
```

`buildServicesPayload` merges each resolved service with the mapper's per-service assignment,
producing the array shape `attachServices` already understands (`id`, `quantity`,
`photographer_id`, `scheduled_at`), passing `null` where the mapping is unsafe (2.17).

## Data Models

### Normalized internal structure (2.2)

```
NormalizedBooking {
  preferred:  { date: 'Y-m-d'|null, time: 'HH:MM'|null }
  alternate:  { date: 'Y-m-d'|null, time: 'HH:MM'|null }
  requested_photographers: int[]                       // de-duplicated
  selected_services: [ { id: int, quantity: int } ]    // ordered
  service_assignments: [ { service_id:int, photographer_id:?int,
                           scheduled_date:?string, scheduled_time:?string } ]
}
```

### New `shoots` columns (migration)

Investigation of `2024_12_31_200533_create_shoots_table.php` plus the `Shoot` model shows the
table has grown via many additive migrations; `scheduled_at`, `timezone`, etc. were added
later and are already in `$fillable`/`$casts`. The shoots table currently has **no**
alternate-schedule, payload, warnings, requested-photographers, or mapping-status columns.
A new migration `xxxx_add_external_booking_columns_to_shoots_table.php` adds (all nullable for
backward compatibility, 2.15, 2.16, 3.9):

| Column | Type | Purpose | Req |
|---|---|---|---|
| `alternate_scheduled_date` | `date` nullable | alternate preferred date | 2.1, 2.15 |
| `alternate_time` | `string` nullable | alternate time `HH:MM`, null when not provided | 2.13, 2.15 |
| `alternate_scheduled_at` | `datetime` nullable | combined alternate datetime, null when no time | 2.13, 2.15 |
| `requested_photographers` | `json` nullable | normalized requested photographer ids | 2.5, 2.7, 2.15 |
| `external_booking_payload` | `json` nullable | raw external payload (provenance) | 2.15 |
| `external_booking_warnings` | `json` nullable | generated warnings list | 2.15 |
| `external_booking_mapping_status` | `string` nullable | `fully_mapped`/`partially_mapped`/`needs_review` | 2.16 |

The migration uses `Schema::hasColumn` guards (matching the existing migration style, e.g.
`2026_02_22_140000_add_photographer_id_to_shoot_service_table.php`) so re-runs are safe.
`external_booking_mapping_status` is a `string` (not a DB enum) for portability; the allowed
values are enforced in application code/validation.

**`Shoot` model additions** (`app/Models/Shoot.php`):
- Add to `$fillable`: `alternate_scheduled_date`, `alternate_time`, `alternate_scheduled_at`,
  `requested_photographers`, `external_booking_payload`, `external_booking_warnings`,
  `external_booking_mapping_status`.
- Add to `$casts`: `'alternate_scheduled_date' => 'date'`,
  `'alternate_scheduled_at' => 'datetime'`, `'requested_photographers' => 'array'`,
  `'external_booking_payload' => 'array'`, `'external_booking_warnings' => 'array'`.
  (`alternate_time` and `external_booking_mapping_status` remain plain strings.)
- Optionally add a `const MAPPING_STATUS_FULLY_MAPPED/PARTIALLY_MAPPED/NEEDS_REVIEW` for
  type-safety, mirroring the existing status constant style.

### `shoot_service` pivot (no schema change)

The pivot already has `photographer_id` (migration `2026_02_22_140000`) and `scheduled_at`,
and `attachServices` already writes both from the `$services` array
(`array_key_exists('photographer_id', $service)` and `array_key_exists('scheduled_at',
$service)`). No migration is needed; the fix simply **provides** these keys where safely
mapped and omits/passes null otherwise (2.17). When a key is absent, `attachServices`
preserves the current value (irrelevant for a brand-new shoot, where it resolves to null).

### `ExternalBookingRequest` rule additions (2.1, backward compatible)

All new rules are `nullable`/`sometimes` so legacy payloads validate unchanged (3.7):

```php
// Alternate scheduling
'alternate_date' => 'nullable|date',
'alternate_time' => 'nullable|string|max:10',

// Single photographer (either alias accepted)
'selected_photographer_id' => 'nullable|integer|exists:users,id',
'photographer_id'          => 'nullable|integer|exists:users,id',

// Photographer list (either alias accepted)
'selected_photographers'   => 'nullable|array',
'selected_photographers.*' => 'integer|exists:users,id',
'requested_photographers'   => 'nullable|array',
'requested_photographers.*' => 'integer|exists:users,id',

// Explicit per-service assignments
'service_assignments'                   => 'nullable|array',
'service_assignments.*.service_id'      => 'required_with:service_assignments|integer|exists:services,id',
'service_assignments.*.photographer_id' => 'nullable|integer|exists:users,id',
'service_assignments.*.scheduled_date'  => 'nullable|date',
'service_assignments.*.scheduled_time'  => 'nullable|string|max:10',
```

### Notification payload (2.20)

Stored as a `ShootActivityLog` row: `action = 'shoot_assignment_review'`, `shoot_id`,
`description` = the message, and `metadata`:

```json
{
  "type": "shoot_assignment_review",
  "shoot_id": 123,
  "title": "New booking needs photographer/schedule review",
  "message": "External booking was auto-mapped and needs schedule/photographer review.",
  "action_type": "open_shoot_details_popup",
  "action_payload": { "shoot_id": 123, "focus": "schedule_assignments" }
}
```

`DashboardController::formatActivityLogForRole` already forwards `action` and full `metadata`
to admin/superadmin/editing_manager/salesrep, so the structured payload reaches the frontend
without changing the notifications endpoint. The frontend `useNotifications.normalizeActivity`
maps `action` → `action` and forwards `metadata`; the `SHOOT_ACTIVITY_TITLES` map gains a
`shoot_assignment_review: 'Booking Needs Review'` entry, and `DashboardActivityItem` is
extended with optional `metadata` so the click handler can read `action_type`/`action_payload`.

## Auto-Mapping Algorithm

`ExternalBookingAutoMapper::map` first checks for explicit `service_assignments`. If present,
it applies them directly per service and skips inference (2.8). Otherwise it runs the
photographer decision table then the schedule decision table. "First service" = first element
of the ordered `selected_services`.

### Photographer mapping (cases A–E)

Let `S` = number of resolved services, `P` = number of requested photographers.

| Case | Condition | Action | Req |
|---|---|---|---|
| A | `S == 1` and `P == 1` | Assign that photographer to the service via pivot `photographer_id`; set legacy shoot `photographer_id` | 2.3 |
| B | `S == 1` and `P == 0` | Leave service photographer unassigned | 2.4 |
| C | `S == 1` and `P > 1` | Leave unassigned; store all in `requested_photographers`; warning (multi-photographer-one-service); flag `multiplePhotographersForOneService` | 2.5 |
| D | `S > 1` and `P == 1` | Assign to the first service **only if** eligibility confirms it is safe; otherwise leave all unassigned and keep the photographer in `requested_photographers` + warning | 2.6 |
| E | `S > 1` and `P > 1` | Do not guess: leave every pivot `photographer_id` null (unless explicit `service_assignments`); store all in `requested_photographers`; warning; flag `unmappablePhotographers` | 2.7 |

Eligibility for case D is intentionally conservative: in the absence of an availability/skill
check, "safe" defaults to **not safe** (leave unassigned) so the system never fabricates an
assignment. The hook `isPhotographerEligibleForService(photographerId, serviceId)` is the
single extension point; until a real check exists it returns false, satisfying the
"a wrong assignment is worse than none" principle.

`requested_photographers` is always persisted with the full normalized list regardless of
case (2.5, 2.7, 2.15).

### Schedule mapping (cases 1–4)

Let `pref = {date, time}`, `alt = {date, time}`.

| Case | Condition | Action | Req |
|---|---|---|---|
| 1 | `S == 1` | Assign preferred date/time to the service; set shoot-level schedule from preferred (subject to no-fabricated-time) | 2.9 |
| 2 | `S > 1` and `pref` and `alt` both present | Preferred → service 1; alternate → service 2 (if exists); services 3+ unscheduled + warning per unscheduled service | 2.10 |
| 3 | `S > 1` and `pref` only | Preferred → service 1; services 2+ unscheduled (do NOT copy preferred to all) + warning | 2.11 |
| 4 | `S > 1` with explicit `service_assignments` | Use per-service schedules from the assignments directly | 2.8 |

Shoot-level schedule for multi-service bookings is set from the preferred values (service 1),
with the alternate persisted into the dedicated `alternate_*` columns.

### No-fabricated-time rule (2.12, 2.13, 2.14)

Applied to both preferred and alternate, at both shoot level and pivot level:

```
FUNCTION resolveSchedule(date, time)
  IF date IS EMPTY THEN
    RETURN { scheduled_date: null, time: null, scheduled_at: null }     // (3.2)
  ELSE IF time IS EMPTY THEN
    flag dateMissingTime = true                                          // → warning (2.12/2.13)
    RETURN { scheduled_date: date, time: null, scheduled_at: null }      // NEVER 00:00
  ELSE
    RETURN { scheduled_date: date, time: time,
             scheduled_at: combine(date, time) }                         // (2.14)
  END IF
END FUNCTION
```

The corresponding pivot `scheduled_at` is set only in the third branch; it is null whenever
the date-only or no-date branch is taken (2.12).

### Mapping status (2.16)

```
IF needsReview signals present (unassigned where intent existed, multi-photographer,
   unscheduled services, missing times, or non-empty warnings) THEN
  IF nothing could be mapped at all → 'needs_review'
  ELSE → 'partially_mapped'
ELSE
  → 'fully_mapped'
```

`fully_mapped` requires every resolved service to have its photographer (when exactly one was
requested) and schedule resolved with no warnings; a date-only preference (no time) forces at
least `partially_mapped` because a time still needs manual review.

## Frontend Design

The frontend is in this repository (`frontend/`). Two integration points (2.21, 2.22):

### Notification click → open shoot details modal focused on assignments (2.21)

- `frontend/src/types/dashboard.ts`: extend `DashboardShootModalTab`/navigation with a focus
  hint. Add an optional `openShootFocus?: 'schedule_assignments'` to
  `DashboardShootModalNavigationState`, and extend `DashboardActivityItem` with optional
  `metadata?: Record<string, unknown>`.
- `frontend/src/hooks/useNotifications.ts`: in `normalizeActivity`, forward `metadata` onto
  the `NotificationItem` and add `shoot_assignment_review` to `SHOOT_ACTIVITY_TITLES`.
- `frontend/src/components/notifications/NotificationCenter.tsx` `handleNotificationClick`:
  when `notification.action === 'shoot_assignment_review'` (or
  `metadata.action_type === 'open_shoot_details_popup'`), mark read, then navigate to
  `/dashboard` with `DashboardShootModalNavigationState { openShootId, openShootTab:
  'overview', openShootFocus: 'schedule_assignments' }`. This reuses the existing
  modal-open-via-navigation-state mechanism (the same path used today for `shootId`).
- The shoot details modal (opened via `useDashboardShootModal`/`ShootDetailsModalWrapper`)
  reads `openShootFocus` and scrolls/expands the photographer/schedule assignment section,
  and surfaces the recorded warnings.

If a deployment runs the dashboard frontend from a separate repository, the **contract** the
frontend must honor is: read `action_type === 'open_shoot_details_popup'` and
`action_payload.{shoot_id, focus}` from the notification, open the shoot details popup for
`shoot_id` directly (not merely a list), scroll to the section identified by `focus`
(`schedule_assignments`), and display `external_booking_warnings`.

### "External Booking Mapping" section in the shoot details popup (2.22)

When the loaded shoot has a non-null `external_booking_payload` (or
`external_booking_mapping_status`), the overview tab renders an "External Booking Mapping"
panel showing:
- Preferred schedule (`scheduled_date` + `time`) and alternate schedule
  (`alternate_scheduled_date` + `alternate_time`), with "time not specified" when null.
- Auto-mapped services with their per-service photographer + schedule (from the pivot).
- Requested photographers (`requested_photographers`), resolved to names.
- Warnings (`external_booking_warnings`) and the mapping status badge.

The shoot serializer (`ShootResource`) must expose the new columns so the popup can render
them.

## Fix Implementation

### Changes Required

Assuming the root-cause analysis is correct, the fix is additive across five areas. Detailed
contracts are in [Components and Interfaces](#components-and-interfaces),
[Data Models](#data-models), and [Frontend Design](#frontend-design).

1. **Request schema** — `app/Http/Requests/ExternalBookingRequest.php`
   - Add the optional/nullable rules for `alternate_date`, `alternate_time`,
     `selected_photographer_id`/`photographer_id`, `selected_photographers`/
     `requested_photographers`, and `service_assignments[]` (2.1). All existing rules
     unchanged so legacy payloads validate as today (3.7).

2. **Persistence** — new migration + `app/Models/Shoot.php`
   - Migration `xxxx_add_external_booking_columns_to_shoots_table.php` adds the seven nullable
     columns (`alternate_scheduled_date`, `alternate_time`, `alternate_scheduled_at`,
     `requested_photographers`, `external_booking_payload`, `external_booking_warnings`,
     `external_booking_mapping_status`) with `Schema::hasColumn` guards (2.15, 2.16).
   - `Shoot` model `$fillable` + `$casts` updated for the new columns. No `shoot_service`
     migration needed (pivot already supports `photographer_id` + `scheduled_at`).

3. **Mapping pipeline** — new classes under `App\Services\ExternalBooking\`
   - `Data\ExternalBookingData` (DTO), `ExternalBookingScheduleNormalizer`,
     `ExternalBookingAutoMapper` (photographer cases A–E + schedule cases 1–4 +
     no-fabricated-time rule + mapping status), `ExternalBookingWarningBuilder`,
     `ExternalBookingNotificationService` (2.2–2.16, 2.19, 2.20).

4. **Controller** — `app/Http/Controllers/API/ExternalBookingController.php`
   - Inject the new collaborators; keep `bookShoot` thin. Replace the `?? '00:00'` fabrication
     and `'photographer_id' => null` with values from the mapping result; persist the new
     metadata columns; pass per-service `photographer_id`/`scheduled_at` into the existing
     `attachServices`; call `notifyIfNeeded` after commit (2.3–2.18, 2.19). All existing
     client/pricing/coupon/activity/job logic preserved verbatim (3.3–3.9).
   - `ShootResource` exposes the new columns so the popup can render them (2.22).

5. **Frontend** — `frontend/`
   - `types/dashboard.ts` (focus hint + activity metadata), `hooks/useNotifications.ts`
     (forward metadata + title), `components/notifications/NotificationCenter.tsx` (click
     handler opens the modal focused on `schedule_assignments`), and the shoot details modal
     overview tab ("External Booking Mapping" panel) (2.21, 2.22).

## Error Handling

- **Validation**: new fields are `nullable`; invalid types (non-integer photographer id,
  unparseable date) are rejected by `ExternalBookingRequest` with field-level messages, the
  same as existing fields. A malformed `service_assignments` entry fails validation rather
  than being silently dropped.
- **Mapping never rejects a booking**: per 2.17, ambiguous or incomplete mappings never throw
  — they fall through to "unassigned + warning". The auto-mapper is a pure function and
  cannot fail the transaction.
- **Transaction integrity**: shoot creation, service attachment, and metadata writes stay
  inside the existing `DB::transaction`, so a persistence failure rolls back the whole
  booking exactly as today.
- **Notification failure isolation**: `notifyIfNeeded` runs **after** the transaction commits
  (like the existing job dispatch and account-setup email) and is wrapped in try/catch with a
  `Log::warning`; a notification failure must never fail the booking or roll back the shoot.
- **Unknown photographer ids**: rejected at validation (`exists:users,id`). If an id passes
  validation but is later not eligible, case B/D/E leave it unassigned — no exception.
- **Backward compatibility**: a payload with only legacy fields exercises the
  non-bug-condition path; the new collaborators produce empty/null results and the shoot is
  identical to today's (Property 2).

## Testing Strategy

### Validation Approach

Two phases: first surface counterexamples that demonstrate the bug on the **unfixed** code,
confirming the root-cause analysis; then verify the fix maps conservatively and preserves
legacy behavior. Property-based testing is used for preservation and for the exhaustive
photographer/schedule case matrix, because the input domain (S × P × schedule combinations)
is large and edge-case heavy.

### Exploratory Bug Condition Checking

**Goal**: Surface counterexamples demonstrating the bug BEFORE implementing the fix; confirm
or refute the root-cause analysis (re-hypothesize if refuted).

**Test Plan**: Post external payloads carrying the new intent to `bookShoot` on the UNFIXED
code and assert against the created shoot/pivot. These will fail today.

**Test Cases**:
1. **Single photographer dropped**: 1 service + 1 photographer → assert pivot
   `photographer_id` equals the selected id (fails: it is null). (2.3)
2. **Multiple photographers lost**: 1 service + 2 photographers → assert
   `requested_photographers` persisted and warning recorded (fails: column/field absent). (2.5)
3. **Alternate schedule dropped**: preferred + alternate, 2 services → assert service 2
   scheduled at alternate and `alternate_scheduled_at` persisted (fails: dropped). (2.10)
4. **Fabricated midnight**: preferred_date only → assert `time`/`scheduled_at` null (fails:
   stored as `00:00`). (2.12)
5. **No provenance/status**: any external booking → assert `external_booking_payload` and
   `external_booking_mapping_status` set (fails: columns absent). (2.15, 2.16)

**Expected Counterexamples**: pivot `photographer_id` null despite selection; alternate
date/time absent from the shoot; `scheduled_at = YYYY-MM-DD 00:00`; missing payload/warnings/
status. Likely causes: request schema gap, hardcoded null photographer, single shoot-level
schedule, `?? '00:00'` fallback, missing columns.

### Fix Checking

**Goal**: For all inputs where the bug condition holds, the fixed handler produces the
expected conservative mapping.

**Pseudocode:**
```
FOR ALL X WHERE isBugCondition(X) DO
  shoot := bookShoot_fixed(X)
  ASSERT (oneService(X) AND onePhotographer(X)) IMPLIES pivot(shoot, s1).photographer_id = thatPhotographer
  ASSERT (oneService(X) AND multiPhotographers(X)) IMPLIES pivot(shoot, s1).photographer_id = null
  ASSERT (multiServices(X) AND multiPhotographers(X) AND NOT explicit(X)) IMPLIES allPivotPhotographerIds(shoot) = null
  ASSERT oneService(X) IMPLIES pivot(shoot, s1).schedule = preferred(X)
  ASSERT (multiServices(X) AND hasAlternate(X)) IMPLIES pivot(shoot, s2).schedule = alternate(X)
  ASSERT (multiServices(X) AND preferredOnly(X)) IMPLIES servicesAfterFirstUnscheduled(shoot)
  ASSERT (X.preferred_date set AND X.preferred_time empty) IMPLIES shoot.time = null AND shoot.scheduled_at = null AND pivot(shoot, s1).scheduled_at = null
  ASSERT shoot.external_booking_payload = rawPayload(X)
  ASSERT shoot.requested_photographers = normalizedPhotographers(X)
  ASSERT shoot.external_booking_mapping_status IN {fully_mapped, partially_mapped, needs_review}
  ASSERT needsReview(shoot) IMPLIES notificationCreated(shoot, type = shoot_assignment_review)
  ASSERT shoot.status = STATUS_REQUESTED
END FOR
```

### Preservation Checking

**Goal**: For all inputs where the bug condition does NOT hold, the fixed handler produces the
same result as the original.

**Pseudocode:**
```
FOR ALL X WHERE NOT isBugCondition(X) DO
  ASSERT bookShoot_original(X) = bookShoot_fixed(X)
END FOR
```

**Testing Approach**: Property-based testing — generate legacy-shaped payloads (existing
fields only, ≤1 service-with-time, no photographer/alternate/assignment fields) and assert the
created shoot is field-for-field identical to the pre-fix behavior, including pricing,
`service_id`, `property_details`, source, payment/product status, the `shoot_requested` log,
`STATUS_REQUESTED`, and that no notification is created.

**Test Plan**: Observe the unfixed handler's output for legacy payloads first, capture it,
then assert the fixed handler reproduces it.

**Test Cases**:
1. **Legacy single date+time** preserved (`scheduled_at`/`scheduled_date`/`time` from
   preferred). (3.1)
2. **No scheduling** → `STATUS_REQUESTED`, null scheduling. (3.2)
3. **Client/pricing/coupon/activity/job** paths unchanged for a legacy payload. (3.3–3.5, 3.9)
4. **No photographer** → none assigned, no default. (3.6)
5. **Existing-fields-only payload** → identical shoot, no notification. (3.7, 3.8)

### Unit Tests

- `ExternalBookingScheduleNormalizer`: alias resolution (`selected_photographer_id` vs
  `photographer_id`, `selected_photographers` vs `requested_photographers`), de-duplication,
  empty/absent inputs.
- `ExternalBookingAutoMapper`: photographer cases A–E and schedule cases 1–4 as a decision
  table; the no-fabricated-time rule for preferred and alternate; explicit
  `service_assignments` bypass; mapping-status derivation.
- `ExternalBookingWarningBuilder`: each warning string emitted for the right flag combination.
- `ExternalBookingNotificationService`: `needsReview` truth table; notification created/not
  created accordingly; payload shape (`type`, `shoot_id`, `title`, `message`, `action_type`,
  `action_payload`).
- `ExternalBookingRequest`: new rules accept valid values, reject invalid, and a legacy
  payload still validates.
- `Shoot` model: new columns fillable and cast correctly.

### Property-Based Tests

- Generate random `(S, P, preferred, alternate, explicit?)` combinations; assert the mapper
  never produces a photographer assignment that wasn't unambiguously requested (no
  fabrication) and never copies preferred schedule onto services beyond the first in case 3.
- Generate random legacy payloads; assert fixed == original (Preservation, Property 2).
- Generate random date/time presence combinations; assert `scheduled_at` is null whenever the
  time is empty (no-midnight invariant).

### Integration Tests

- Full `POST /api/external/book-shoot` flow for each photographer/schedule case, asserting the
  persisted shoot, pivot rows, metadata columns, and the `shoot_assignment_review`
  `ShootActivityLog` row (and its visibility for an admin via the notifications endpoint).
- A needs-review booking → `GET /api/notifications` as admin returns the notification with the
  correct `action_type`/`action_payload` metadata.
- Frontend: clicking the notification opens the shoot details modal focused on the
  schedule/assignment section and renders the "External Booking Mapping" panel with warnings.
- Backward-compat: a legacy payload produces the same response and shoot as before, with no
  notification created.
