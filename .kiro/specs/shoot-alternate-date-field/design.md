# Design Document

## Overview

This feature promotes the shoot-level alternate date/time to a first-class field that is
read and written consistently across the dashboard and API, and adds an explicit,
operator-initiated action to copy a shoot's stored alternate onto its live schedule.

The columns already exist on the `shoots` table (`alternate_scheduled_date` date,
`alternate_time` string, `alternate_scheduled_at` datetime), are fillable + cast on the
`Shoot` model, and are already serialized by `ShootResource`. The work is therefore:

1. **New apply endpoint** — `POST /shoots/{shoot}/apply-alternate-date` with `scope`
   (`main` | `all_services`), delegating to a thin `ApplyAlternateDateAction`. This is an
   internal schedule update: it does NOT use the reschedule controller, does NOT create a
   `ShootRescheduleRequest`, and does NOT fire `SHOOT_UPDATED` / `SHOOT_SCHEDULED`
   automations, emails, or SMS. It records one `ShootActivityLog` entry.
2. **External booking behavior change** — `ExternalBookingAutoMapper` schedule case 2 stops
   mapping the alternate onto service #2. The alternate persists only into the alternate
   field; service #2's `scheduled_at` is left unset by the alternate.
3. **Write-path consistency** — the modify-shoot and approve-shoot paths
   (`ShootEditablePayloadService`) accept and persist the three alternate fields, deriving
   `alternate_scheduled_at` from date+time with the same null-time rule the auto-mapper uses.
4. **Frontend** — a shared, low-profile Alternate Date presentation reused by overview,
   modify, approve, external-booking panel, and the detail modal, plus a default
   "Use as main date" control and a secondary "Apply to all services" control for
   multi-service shoots.

### Grounding (verified against the codebase)

- `app/Models/Shoot.php` — fields fillable (lines ~160-162); casts: `alternate_scheduled_date => date`, `alternate_scheduled_at => datetime`, `alternate_time` intentionally left a plain string; `services()` is a `belongsToMany` over the `shoot_service` pivot carrying `scheduled_at`.
- `app/Http/Resources/ShootResource.php` — already emits `alternate_scheduled_date`/`alternateScheduledDate` (`toDateString()`), `alternate_time`/`alternateTime`, `alternate_scheduled_at`/`alternateScheduledAt` (`toIso8601String()`), matching the main-schedule formatters.
- `app/Services/ExternalBooking/ExternalBookingAutoMapper.php` — schedule case 2 currently assigns `$serviceAssignments[$secondServiceId]['scheduled_at'] = $alternate['scheduled_at']` (the line to remove). `alternateSchedule` is already populated independently.
- `app/Services/ExternalBooking/MappingResult.php` — already carries `alternateSchedule`; no shape change needed.
- `app/Http/Controllers/API/ExternalBookingController.php` — persists `alternateSchedule` onto the shoot and calls `attachServices(...)` via `buildServicesPayload(...)`, which is the existing pivot-write path.
- `app/Services/Shoots/ShootMutationSupportService.php::attachServices` — single pivot writer; writes `scheduled_at` per service via `sync($pivotData)`. The apply action reuses this for `scope=all_services`.
- `app/Http/Controllers/API/ShootController.php` — `approve`, `update`, and `assignServicePhotographer` role-gate to `admin/superadmin/editing_manager` and return `ShootResource`. The new method mirrors `assignServicePhotographer`'s authorization shape.
- `app/Services/Shoots/ShootEditablePayloadService.php` — `validationRules()` + `apply()` drive both modify and approve; it does NOT yet handle alternate fields (the gap this design closes for Req 4.2).
- `app/Services/ShootActivityLogger.php` — `log($shoot, $action, $metadata, $user)`. `apply_alternate_date` is a NON-broadcastable action (kept out of `$broadcastableActions`) so no realtime notification fires.
- `app/Http/Controllers/API/ShootRescheduleRequestController.php` — the path to AVOID; it creates `ShootRescheduleRequest` and fires `SHOOT_SCHEDULED`/`SHOOT_UPDATED` + emails.
- `backend/routes/api.php` — shoot routes live in the `auth:sanctum` group (~line 597); per-route `role:` middleware is applied to privileged actions. The new route is added here with `role:admin,superadmin,editing_manager`.
- Frontend: `src/types/shoots.ts` (`ShootData` already has `alternate_scheduled_date|alternate_time|alternate_scheduled_at`), `src/context/ShootsContext.tsx` (`normalizeShoot` already maps camel/snake alternate aliases; `updateShoot` exists), `OverviewExternalBookingSection.tsx` (already renders Preferred/Alternate), `ShootApprovalModal.tsx`, `ShootDetailsModal.tsx`/`ShootDetailsOverviewTab.tsx`.

## Architecture

```
Frontend (React)
  AlternateDateField (shared, low-profile presentation + controls)
    ├─ used by: ShootDetailsOverviewTab, OverviewExternalBookingSection,
    │           ShootApprovalModal (approve), ShootEditModal (modify), ShootDetailsModal
    ├─ "Use as main date"  ──▶ POST /shoots/{id}/apply-alternate-date { scope: 'main' }        (default)
    └─ "Apply to all services" ▶ POST /shoots/{id}/apply-alternate-date { scope: 'all_services' } (multi-service only)
                                   │
                                   ▼  returns ShootResource
  ShootsContext.normalizeShoot(...) ──▶ updateShoot(id, normalized, { skipApi:true })  (refresh state)

Backend (Laravel)
  routes/api.php  (auth:sanctum, role:admin,superadmin,editing_manager)
    └─ ShootController::applyAlternateDate(Request, Shoot)
          └─ ApplyAlternateDateAction::execute(Shoot, scope, actor)
                ├─ guard: alternate present? (else ValidationException → 422, no writes)
                ├─ set main schedule from stored alternate (scheduled_date/time/scheduled_at)
                ├─ scope=all_services → attachServices(...) sets every pivot scheduled_at   (ShootMutationSupportService)
                ├─ ShootActivityLogger::log(shoot, 'apply_alternate_date', { scope, by }, actor)   (non-broadcastable)
                └─ return $shoot (fresh, relations loaded)  ──▶ ShootResource

  ExternalBookingAutoMapper::map(...)  (schedule case 2: alternate no longer → service #2)
```

### Why a dedicated action (not the reschedule path)

The reschedule controller is deliberately bypassed because it carries client/photographer
notification and automation side effects. `ApplyAlternateDateAction` performs only the
schedule mutation + activity log inside a single DB transaction, so the internal-update
guarantees (Req 6) hold by construction: it never references `AutomationService`,
`MailService`, or `ShootRescheduleRequest`.

## Components and Interfaces

### Backend

#### Route (`routes/api.php`, inside the `auth:sanctum` group)

```php
Route::post('/shoots/{shoot}/apply-alternate-date', [ShootController::class, 'applyAlternateDate'])
    ->middleware('role:admin,superadmin,editing_manager');
```

#### Controller method (`ShootController::applyAlternateDate`)

Thin; mirrors `assignServicePhotographer`'s shape. Validates scope, delegates, returns resource.

```php
public function applyAlternateDate(Request $request, Shoot $shoot)
{
    $user = $request->user();
    if (!$user || !in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'scope' => 'nullable|in:main,all_services',
    ]);
    $scope = $validated['scope'] ?? 'main'; // Req 5.2 default

    try {
        $shoot = $this->applyAlternateDateAction->execute($shoot, $scope, $user);
    } catch (ValidationException $e) {
        return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
    }

    return response()->json([
        'message' => 'Alternate date applied successfully',
        'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services'])),
    ]);
}
```

#### `ApplyAlternateDateAction` (`app/Services/Shoots/Actions/ApplyAlternateDateAction.php`)

```php
final class ApplyAlternateDateAction
{
    public function __construct(
        protected ShootMutationSupportService $support,
        protected ShootActivityLogger $activityLogger,
    ) {}

    /** @param 'main'|'all_services' $scope */
    public function execute(Shoot $shoot, string $scope, User $actor): Shoot
    {
        // Req 5.3 / 9.4 — reject when no stored alternate; make NO schedule changes.
        if (empty($shoot->alternate_scheduled_date)) {
            throw ValidationException::withMessages([
                'alternate' => ['This shoot has no alternate date to apply.'],
            ]);
        }

        return DB::transaction(function () use ($shoot, $scope, $actor) {
            $shoot->loadMissing('services');

            // Snapshot the stored alternate (retained unchanged — Req 5.9 / 9.6).
            $altDate = $shoot->alternate_scheduled_date?->toDateString();
            $altTime = $shoot->alternate_time;                       // plain string or null
            $altAt   = $shoot->alternate_scheduled_at;               // Carbon|null (derived)

            // Set main schedule from the alternate (Req 5.4). Keep scheduled_at consistent
            // with date+time using the same null-time rule as resolveSchedule:
            // null time => null scheduled_at.
            $shoot->scheduled_date = $altDate;
            $shoot->time           = $altTime;
            $shoot->scheduled_at   = $altTime ? $altAt : null;
            $shoot->save();

            // Req 5.5 — push the alternate onto every selected service pivot via the
            // existing pivot-write path. Reuses attachServices so scheduled_at is the only
            // field changed; all other pivot values are preserved by passing current values.
            if ($scope === 'all_services') {
                $servicesPayload = $shoot->services->map(fn ($service) => [
                    'id'             => (int) $service->id,
                    'price'          => $service->pivot?->price,
                    'quantity'       => $service->pivot?->quantity ?? 1,
                    'photographer_id'=> $service->pivot?->photographer_id,
                    'editor_id'      => $service->pivot?->editor_id,
                    'scheduled_at'   => $altAt?->format('Y-m-d H:i:s'), // null when alternate has no time
                ])->all();

                $this->support->attachServices($shoot, $servicesPayload);
            }

            // Req 5.6 / 5.7 / 9.5 — exactly one activity log entry; actor + scope captured.
            // 'apply_alternate_date' is NOT in $broadcastableActions, so no broadcast/notify.
            $this->activityLogger->log(
                $shoot,
                'apply_alternate_date',
                ['scope' => $scope, 'by' => $actor->name, 'applied_scheduled_at' => $altAt?->toIso8601String()],
                $actor
            );

            return $shoot->fresh(['client', 'rep', 'photographer', 'services']);
        });
    }
}
```

Notes:
- No `ShootRescheduleRequest`, `AutomationService`, or `MailService` references anywhere in
  this action — the internal-update guarantees (Req 6) are structural.
- `attachServices` already preserves untouched pivot columns when current values are passed
  back, so `scope=all_services` changes only `scheduled_at` (Req 3.1).
- For `scope=main`, no service payload is built, so pivots are untouched (Req 3.3 / 5.4).
- Register a description for `apply_alternate_date` in `ShootActivityLogger::generateDescription`
  (e.g. "Alternate date applied to main schedule" / "...to all services").

#### `ExternalBookingAutoMapper` — schedule case 2 change

In `map()`, the multi-service + alternate branch currently does:

```php
if ($alternatePresent) {
    if ($serviceCount >= 2) {
        $secondServiceId = (int) $services[1]['id'];
        $serviceAssignments[$secondServiceId]['scheduled_at'] = $alternate['scheduled_at']; // REMOVE
    }
    for ($position = 3; $position <= $serviceCount; $position++) {
        $flags['unscheduledServices'][] = $position;
    }
} else { ... }
```

After the change, the alternate is no longer mapped onto service #2. Services #2..N are all
left unscheduled by the alternate (service #1 still receives the preferred date). The
`alternateSchedule` block at the top of `map()` is unchanged, so the alternate still persists
onto the shoot via the controller. Concretely:

```php
if ($alternatePresent) {
    // Alternate persists only into the shoot-level alternate field (alternateSchedule).
    // It is NOT applied to any service. Services #2..N remain unscheduled.
    for ($position = 2; $position <= $serviceCount; $position++) {
        $flags['unscheduledServices'][] = $position;
    }
} else {
    for ($position = 2; $position <= $serviceCount; $position++) {
        $flags['unscheduledServices'][] = $position;
    }
}
```

Since both branches now produce the same `unscheduledServices`, they collapse — multi-service
with a preferred date always leaves #2..N unscheduled regardless of alternate presence. Update
the class docblock decision table (case 2/3) accordingly. The no-fabricated-time rule and the
`alternateDateMissingTime` flag are unchanged.

**Impacted existing tests (must be updated to reflect the deliberate change):**
- `tests/Unit/Services/ExternalBooking/ExternalBookingAutoMapperTest.php`
  → `schedule_case_2_multi_service_with_alternate_maps_pref_s1_alt_s2`: assert service #2
    `scheduled_at` is now `null`, `unscheduledServices` is `[2, 3]`, and the alternate is still
    persisted in `alternateSchedule['alternate_scheduled_at']`.
- `tests/Unit/Services/ExternalBooking/ExternalBookingWarningBuilderTest.php`
  → `emits_unscheduled_service_warning_for_third_service_with_alternate`: service #2 is now
    unscheduled too, so the "Service #2 could not be scheduled" warning is now expected.
- The auto-mapper property test's case-2 expectations and any
  `ExternalBookingNotificationServiceTest` fixtures referencing the old #2 assignment.

#### `ShootEditablePayloadService` — accept + persist alternate (Req 4.2)

Add to `validationRules()`:

```php
'alternate_scheduled_date' => 'nullable|date',
'alternate_time'           => 'nullable|string',
'alternate_scheduled_at'   => 'nullable|date',
```

Add to `apply()` (near the main-schedule handling), deriving `alternate_scheduled_at` with
the same null-time rule used everywhere else:

```php
$altDateProvided = array_key_exists('alternate_scheduled_date', $validated);
$altTimeProvided = array_key_exists('alternate_time', $validated);
$altAtProvided   = array_key_exists('alternate_scheduled_at', $validated);

if ($altDateProvided) {
    $shoot->alternate_scheduled_date = $validated['alternate_scheduled_date'] ?: null;
}
if ($altTimeProvided) {
    $shoot->alternate_time = $validated['alternate_time'] ?: null;
}
if ($altAtProvided && $validated['alternate_scheduled_at']) {
    $shoot->alternate_scheduled_at = new \DateTime($validated['alternate_scheduled_at']);
} elseif ($altDateProvided || $altTimeProvided) {
    // Derive from date+time; null time => null scheduled_at (mirrors resolveSchedule).
    $date = $shoot->alternate_scheduled_date
        ? $shoot->alternate_scheduled_date->toDateString()
        : null;
    $time = $shoot->alternate_time;
    $shoot->alternate_scheduled_at = ($date && $time)
        ? Carbon::parse("{$date} {$time}")
        : null;
}
```

This keeps both the approve flow (`ApproveShootAction`) and modify flow (`UpdateShootAction`)
in sync since both call `ShootEditablePayloadService::apply()`. No service pivots are touched
by this block (Req 3.2).

### Frontend

#### Shared component `AlternateDateField` (`src/components/shoots/AlternateDateField.tsx`)

A single low-profile presentation + control cluster, consumed by every surface. Reads the
already-normalized `ShootData` alternate fields; renders nothing when absent (Req 1.4 / 7.2).

```tsx
interface AlternateDateFieldProps {
  shoot: ShootData;
  formatDate: (d?: string | null) => string;
  formatTime: (t?: string | null) => string;
  /** Hide controls in read-only contexts (e.g. client view); default true for editors. */
  showControls?: boolean;
  onApplied?: (updated: ShootData) => void;
}
```

Behavior:
- Visible only when `shoot.alternate_scheduled_date` is set (Req 7.1/7.2).
- "Use as main date" — default/primary control; calls `applyAlternateDate(id, 'main')` (Req 7.5).
- "Apply to all services" — secondary; rendered only when an alternate exists AND
  `serviceObjects.length > 1` (Req 7.3/7.4).
- On success, normalize the returned resource and refresh shoot state.

#### API helper (`src/services/shoots.ts` or alongside existing `apiClient` usage)

```ts
import { apiClient } from '@/services/api';

export async function applyAlternateDate(
  shootId: string,
  scope: 'main' | 'all_services' = 'main',
): Promise<unknown /* raw shoot resource */> {
  const res = await apiClient.post(`/shoots/${shootId}/apply-alternate-date`, { scope });
  return res.data?.data ?? res.data;
}
```

#### State refresh

After a successful apply, pass the returned resource through `ShootsContext`'s existing
`normalizeShoot` and merge with `updateShoot(id, normalized, { skipApi: true })` so all
mounted surfaces reflect the new main schedule without a second round-trip. The alternate
fields remain present in the response (retained unchanged), so the controls stay visible.

#### Surface wiring

- `ShootDetailsOverviewTab.tsx` — render `AlternateDateField` in the schedule summary area.
- `OverviewExternalBookingSection.tsx` — keep the existing Preferred/Alternate read-only rows;
  mount `AlternateDateField` controls below the Alternate row (reuse, do not duplicate the
  presentation logic).
- `ShootApprovalModal.tsx` (approve flow) and `ShootEditModal` (modify form) — the alternate
  date/time inputs submit `alternate_scheduled_date` / `alternate_time` through the existing
  update/approve payloads (now persisted by `ShootEditablePayloadService`).
- `ShootDetailsModal.tsx` — surface the shared field in the detail view.

## Data Models

No migration required — the columns exist:

| Column | Type | Cast | Notes |
| --- | --- | --- | --- |
| `alternate_scheduled_date` | date | `date` | Y-m-d |
| `alternate_time` | string | (none, plain string) | `HH:mm` |
| `alternate_scheduled_at` | datetime | `datetime` | derived from date+time; null when time is null |

Invariant: `alternate_scheduled_at` is non-null only when both `alternate_scheduled_date` and
`alternate_time` are present (mirrors `ExternalBookingAutoMapper::resolveSchedule`). The same
rule applies to the main schedule after an apply.

`shoot_service.scheduled_at` is mutated only by `attachServices` (explicit edits, or
`scope=all_services` apply). Setting the alternate never touches it.

## Error Handling

- **No alternate present** — `ApplyAlternateDateAction` throws `ValidationException` →
  controller returns `422` with a clear message; the DB transaction is never entered, so the
  main schedule and all service pivots are untouched (Req 5.3 / 9.4).
- **Invalid scope** — request validation rejects anything other than `main`/`all_services`
  with `422`; omitted scope defaults to `main` (Req 5.1 / 5.2).
- **Unauthorized role** — controller returns `403` before any mutation (Req 8.2).
- **Transaction safety** — schedule write + pivot write + activity log run inside one
  `DB::transaction`; a failure rolls back all of them, so no partial apply is observable.
- **Frontend** — surface a toast on non-2xx; do not optimistically mutate main schedule until
  the resource returns (avoids showing an applied date that the server rejected).

## Component / Data-Flow Summary

1. Operator clicks "Use as main date" (default) or "Apply to all services" on any surface.
2. Frontend POSTs `/shoots/{id}/apply-alternate-date` with `scope`.
3. `ApplyAlternateDateAction` validates the alternate exists, copies it to the main schedule
   (and, for `all_services`, to every pivot via `attachServices`), logs one activity entry,
   and returns the shoot.
4. `ShootResource` serializes the updated shoot (alternate fields retained); frontend
   normalizes and refreshes state across all surfaces.

### Deliberate behavior change (call-out)

External booking auto-mapping no longer copies an alternate date onto the second service.
The alternate persists only into the shoot-level alternate field and is applied to the live
schedule only through the explicit apply endpoint. This changes the auto-mapper case-2
contract and the warning output for multi-service bookings with an alternate; the tests listed
under the auto-mapper section must be updated to match.

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Alternate serialization formatting and emptiness

For any shoot, `ShootResource` exposes `alternate_scheduled_date` (Y-m-d), `alternate_time`
(string), and `alternate_scheduled_at` (ISO-8601) using the same formatters as the main
schedule when the alternate is set, and returns `null` for all three when it is empty.

**Validates: Requirements 1.2, 1.3**

### Property 2: External booking maps the alternate to the alternate field only

For any external booking that includes an alternate date, the auto-mapper writes that value
only into `alternateSchedule` (`alternate_scheduled_date`/`alternate_time`/
`alternate_scheduled_at`) and never assigns it to any service's `scheduled_at`.

**Validates: Requirements 2.1, 2.3, 9.1**

### Property 3: Preferred date maps to the main schedule

For any external booking with a preferred date, the resulting shoot schedule
(`scheduled_date`/`time`/`scheduled_at`) is derived from the preferred date and time.

**Validates: Requirements 2.2**

### Property 4: No-fabricated-time rule for the alternate

For any alternate date provided without a time, the mapper (and the modify/approve write path)
stores the alternate date while leaving `alternate_time` and `alternate_scheduled_at` null.

**Validates: Requirements 2.4**

### Property 5: Setting the alternate never moves a service

For any shoot, setting or updating the alternate field (via the auto-mapper, modify form, or
approve flow) leaves every `shoot_service.scheduled_at` unchanged.

**Validates: Requirements 3.1, 3.2**

### Property 6: Modify/approve persist the alternate (round-trip)

For any alternate date/time submitted through the modify form or approve flow, reloading the
shoot returns the same alternate date and time, with `alternate_scheduled_at` derived as
date+time (null when time is absent), and the serialized resource reflects it.

**Validates: Requirements 4.2, 4.3**

### Property 7: Apply with scope=main sets the main schedule and leaves services unchanged

For any shoot whose alternate is set, invoking apply with `scope=main` sets the main schedule
equal to the stored alternate and leaves every `shoot_service.scheduled_at` unchanged.

**Validates: Requirements 5.4, 3.3, 9.2**

### Property 8: Apply with scope=all_services sets main and every service from the alternate

For any multi-service shoot whose alternate is set, invoking apply with `scope=all_services`
sets the main schedule and every selected `shoot_service.scheduled_at` to the stored alternate
value, kept consistent with the main schedule.

**Validates: Requirements 5.5, 9.3**

### Property 9: Apply retains the alternate unchanged

For any apply invocation (either scope), the stored alternate field
(`alternate_scheduled_date`/`alternate_time`/`alternate_scheduled_at`) is unchanged after the
operation completes.

**Validates: Requirements 5.9, 9.6**

### Property 10: Apply creates exactly one activity log and no reschedule request

For any successful apply invocation, the system records exactly one `ShootActivityLog` entry
identifying the actor and the scope, and creates no `ShootRescheduleRequest`.

**Validates: Requirements 5.6, 5.7, 6.1, 9.5**

### Property 11: Apply on a shoot with no alternate is rejected with no changes

For any shoot with no stored alternate, invoking apply returns an error and leaves the main
schedule and all service schedules unchanged.

**Validates: Requirements 5.3, 9.4**

### Property 12: Authorization gate on apply

For any actor, the apply endpoint permits the action when the actor's role is `admin`,
`superadmin`, or `editing_manager`, and rejects it as unauthorized (leaving the main schedule
and all service schedules unchanged) for every other role.

**Validates: Requirements 8.1, 8.2**

### Property 13: Apply control visibility

For any shoot, the "Use as main date" control is shown iff a stored alternate exists, and the
"Apply to all services" control is shown iff a stored alternate exists AND the shoot has more
than one service.

**Validates: Requirements 7.1, 7.2, 7.3, 7.4**

## Testing Strategy

**Property tests (≥100 iterations each, randomized inputs):** Properties 1–13 above. Backend
property tests follow the existing seeded-deterministic generator approach already used in
`ExternalBookingAutoMapperTest` (the repo has no PHP PBT library beyond faker). Frontend
visibility properties (13) and component reads use component tests across the
(alternate-present × service-count × role) matrix.

**Example / feature tests:** route accepts `main`/`all_services` and defaults to `main`
(5.1/5.2); endpoint returns `ShootResource` with the updated main schedule (5.8); the default
button posts `scope=main` (7.5); low-profile rendering when alternate absent (1.4).

**Integration tests (deterministic negatives — internal-update guarantees, Req 6.2/6.3/6.4):**
spy/fake `AutomationService` and `Mail`/notification channels and assert the apply path invokes
no notification or automation flow and sends no email or SMS. These are deterministic and not
suited to property-based iteration.

**Regression updates:** update the external-booking auto-mapper and warning-builder tests
enumerated above to reflect the case-2 behavior change (alternate no longer → service #2,
service #2 now unscheduled, alternate still persisted on the shoot).

Each property test is tagged **Feature: shoot-alternate-date-field, Property {number}: {property_text}**
and references its design property.
