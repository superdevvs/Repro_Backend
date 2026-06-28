# Design Document

## Overview

This design upgrades the content and sync behavior of the existing Google Calendar
integration so synced shoot events are clean, photographer-oriented, and stable. The
change is confined to two collaborators:

- `GoogleCalendarEventPayloadBuilder` — new title, location, plain-text description,
  duration, reminders, and color logic, plus an optional per-service timing block.
- `GoogleCalendarShootSyncService` — cancelled shoots are now kept-and-updated rather
  than deleted, and the sync fingerprint is broadened so the newly surfaced fields
  drive update detection.

No OAuth, dispatch, job, schema, or HTTP transport (`GoogleCalendarService`) changes are
required. The current **per-photographer, per-event** model is retained: one shoot maps
to one `GoogleCalendarEventMapping` per photographer calendar, and `updateOrCreate`
keyed on `(shoot_id, shoot_service_id, user_id)` remains the source of truth for
duplicate prevention (Requirement 10).

The deferred admin/internal calendar mode and the multi-photographer shared-event model
are explicitly out of scope.

## Architecture

```
Shoot change ─► (existing dispatcher + jobs, unchanged)
                     │
                     ▼
        GoogleCalendarShootSyncService.syncShoot()
          - isSyncable(): cancelled is now syncable-with-cancel-state
          - resolve photographers / service items (unchanged)
          - check Event_Mapping before create (unchanged)
          - fingerprint = sha1(canonical signature)  ◄── broadened inputs
                     │ build payload
                     ▼
        GoogleCalendarEventPayloadBuilder.build()
          - title / location / description / start+end / reminders / colorId
                     │ payload array
                     ▼
        GoogleCalendarService.create|update|delete (unchanged HTTP layer)
```

### Component Responsibilities

| Component | Responsibility | Changing? |
|-----------|----------------|-----------|
| `GoogleCalendarEventPayloadBuilder` | Build event title, location, plain-text description, start/end, reminders, colorId, optional per-service block | Yes |
| `GoogleCalendarShootSyncService` | Treat cancelled as syncable-with-cancel-state; compute broadened fingerprint; check-before-create | Yes |
| `GoogleCalendarService` | HTTP create/update/delete | No |
| `GoogleCalendarConnection` / `GoogleCalendarEventMapping` | Connection state + `last_error`; mapping as source of truth + `sync_fingerprint` | No (reused) |
| `ShootMutationSupportService::formatFullAddress` / `::calculateShootDurationFromShoot` | Address string and clamped duration | No (reused) |

## Components and Interfaces

### GoogleCalendarEventPayloadBuilder (changes)

Public surface is unchanged (`build(Shoot, ?User): array`). The internals are rewritten.
New/changed private helpers:

```php
protected function buildTitle(Shoot $shoot): string;            // client name; CANCELLED prefix
protected function buildDescription(Shoot $shoot): ?string;     // rebuilt plain-text sections
protected function resolveColorId(Shoot $shoot): string;        // status → colorId
protected function buildReminders(): array;                     // 24h + 30min popups
protected function isCancelled(Shoot $shoot): bool;             // status/workflow_status check
protected function clientName(Shoot $shoot): string;
protected function clientPhone(Shoot $shoot): ?string;
protected function clientEmail(Shoot $shoot): ?string;
protected function derivePropertyAccess(Shoot $shoot): ?string;
protected function deriveArrivalInstructions(Shoot $shoot): ?string;
protected function deriveOnSiteContact(Shoot $shoot): string;
protected function buildPerServiceTimingBlock(Shoot $shoot, string $timezone): ?string;
```

`build()` returns the same array shape with these added/changed keys:

```php
return array_filter([
    'summary'     => $this->buildTitle($shoot),                 // Req 1, 8.2
    'location'    => $this->support->formatFullAddress($shoot), // Req 2 (unchanged)
    'description' => $this->buildDescription($shoot),           // Req 3, 7, 8.2
    'start'       => ['dateTime' => $start->toRfc3339String(), 'timeZone' => $timezone],
    'end'         => ['dateTime' => $end->toRfc3339String(),   'timeZone' => $timezone], // Req 4
    'colorId'     => $this->resolveColorId($shoot),             // Req 6
    'reminders'   => $this->buildReminders(),                   // Req 5
    'extendedProperties' => [ /* unchanged repro_shoot_id / repro_photographer_id */ ],
], static fn ($value) => $value !== null && $value !== '');
```

Notes:
- `end` already uses `start + calculateShootDurationFromShoot()`, which clamps to 60–240
  and defaults to 120. This satisfies Requirement 4 as-is; the design only documents it.
- `reminders` is a literal array (see below), so `array_filter` on scalar emptiness must
  not strip it — it is a non-empty array and passes the filter.

#### Title logic (Req 1, 8)

```
isCancelled → "CANCELLED - {clientName}"
otherwise   → "{clientName}"
```

`clientName()` = `trim($shoot->client?->name)`, falling back to `$shoot->client?->company_name`,
then `"Client"` if both empty. No service names, statuses, photographer names, or internal
labels appear in the title (Req 1.2).

`isCancelled()` returns true when `strtolower($shoot->status)` or
`strtolower($shoot->workflow_status)` equals `Shoot::STATUS_CANCELLED` (`'cancelled'`).

#### Reminders block (Req 5)

```php
protected function buildReminders(): array
{
    return [
        'useDefault' => false,
        'overrides'  => [
            ['method' => 'popup', 'minutes' => 24 * 60], // 24 hours
            ['method' => 'popup', 'minutes' => 30],      // 30 minutes
        ],
    ];
}
```

#### Color mapping (Req 6)

`resolveColorId()` maps the lowercased shoot status to a Google `colorId`. Google supports
event `colorId` values `"1"`–`"11"`. Unknown statuses fall back to the default.

| Shoot status | colorId | Google color (approx) |
|--------------|---------|-----------------------|
| `scheduled` | `"9"` | Blueberry (blue) |
| `requested` | `"5"` | Banana (yellow) |
| `on_hold` | `"5"` | Banana (yellow) |
| `uploaded` / `completed` | `"2"` | Sage (green) |
| `editing` | `"7"` | Peacock (cyan) |
| `review` | `"7"` | Peacock (cyan) |
| `ready` | `"10"` | Basil (dark green) |
| `delivered` | `"2"` | Sage (green) |
| `cancelled` | `"11"` | Tomato (red) |
| `declined` | `"11"` | Tomato (red) |
| (anything else) | `"9"` | Blueberry (default) |

Implemented as a private `const STATUS_COLOR_MAP` array with a default fallback.

#### Per-service timing block (Req 7)

`buildPerServiceTimingBlock()` is included **only** when service items carry differing
`scheduled_at` values:

```
distinct = serviceItems
    ->map(scheduled_at ?? shoot.scheduled_at)
    ->map(toIso)->unique()
if distinct.count() <= 1 → return null   // omit block (Req 7.2)
else → render one line per service:
    "- {serviceName}: {scheduled_at formatted in timezone}"
```

When all services share the shoot time (the standard case), the block is omitted so the
description stays concise.

### Description format specification (Req 3, 7, 8.2)

Plain text only (no HTML, no markdown). Sections are separated by a single blank line.
Lines whose value is missing are omitted only where the requirements allow (phone/email);
the named sections always render with `Not provided` when empty.

Exact line layout (in order):

```
{Client Name}
Phone: {client phone}            ← omitted entirely if phone missing (Req 3.3)
Email: {client email}            ← omitted entirely if email missing (Req 3.5)

Shoot Services:
- {service A}
- {service B}

Service Timing:                  ← entire block present only if per-service times differ (Req 7)
- {service A}: {Mon, Jan 6 2025 9:00 AM}
- {service B}: {Mon, Jan 6 2025 1:00 PM}

Shoot Status: Cancelled          ← present only when the shoot is cancelled (Req 8.2)

Shoot Notes:
{shoot notes, or "Not provided"}

Property Access:
{derived, or "Not provided"}

Arrival Instructions:
{derived, or "Not provided"}

On-Site Contact:
{derived contact, or fallback to client name + phone/email}

View shoot: https://reprodashboard.com/shoots/{shoot_id}
```

Rules:
- The client name is always the first line (Req 3.1).
- `Phone:` / `Email:` lines are included only when present (Req 3.2–3.5).
- A blank line precedes the `Shoot Services:` section (Req 3.6); services are listed one
  per line as `- {name}` using the existing `formatServiceLabel()` normalizer.
- `Shoot Notes:`, `Property Access:`, `Arrival Instructions:` always render their header
  and either the value or `Not provided` (Req 3.7–3.9).
- `On-Site Contact:` always renders; if no discrete contact is derivable it falls back to
  the client name plus available phone/email (Req 3.10).
- `Service Timing:` appears only when per-service schedules differ (Req 7).
- `Shoot Status: Cancelled` appears only for cancelled shoots (Req 8.2).
- The internal shoot link is always the last line (Req 3.11).
- Pricing, payment status, admin/internal notes (`company_notes`, `editor_notes`,
  `admin_issue_notes`) are never included (Req 3.12).

### Derivation rules — no schema changes (Req 3.13, 13)

The repo has **no discrete columns** for property access, arrival instructions, or an
on-site contact. These three sections are derived pragmatically from existing fields. The
rule is explicit and deterministic so it is testable:

- **Source note text** = first non-empty of `shoot->shoot_notes`, then `shoot->notes`
  (the same customer-facing note already used today). `photographer_notes` is a secondary
  source for arrival instructions only. Internal note columns are never used.
- **Property Access** (Req 3.8): reuse the customer-facing note text (`shoot_notes` →
  `notes`). This is where access/lockbox/gate-code information is captured in this system.
  Render `Not provided` when that text is empty.
- **Arrival Instructions** (Req 3.9): reuse `photographer_notes` when present, otherwise
  fall back to the same customer-facing note text. Render `Not provided` when neither
  exists.
- **On-Site Contact** (Req 3.10): there is no dedicated on-site contact field, so this
  always falls back to the **client**: `clientName()` plus any available phone/email,
  formatted as `"{name} ({phone}, {email})"` with missing parts dropped. If even the
  client name is empty, render `Not provided`.

Because Property Access and Arrival Instructions both draw on the same small set of note
fields, they may render identical text; that is acceptable and intentional given no
discrete fields exist. This behavior is documented here so reviewers understand it is by
design, not a bug.

### Internal shoot link base URL (Req 3.11)

The link is `{base}/shoots/{shoot_id}` with `base` defaulting to
`https://reprodashboard.com`. Recommendation: read it from config rather than hardcoding,
so non-production environments can override it.

```php
// config/services.php  (under the existing google.calendar block)
'dashboard_url' => env('DASHBOARD_URL', 'https://reprodashboard.com'),

// builder
$base = rtrim((string) config('services.google.calendar.dashboard_url', 'https://reprodashboard.com'), '/');
$link = $base . '/shoots/' . $shoot->id;
```

If the team prefers no config change, a private class constant
`DASHBOARD_BASE_URL = 'https://reprodashboard.com'` is the fallback. The config approach
is recommended.

## GoogleCalendarShootSyncService changes

### Cancelled shoots: keep-and-update (Req 8.1, 8.2)

Today `isSyncable()` returns `false` for `STATUS_CANCELLED`, which causes `syncShoot()`
to call `removeMappings()` and delete the calendar event. The minimal control-flow change:

1. **Stop deleting on cancel.** Remove `STATUS_CANCELLED` from the non-syncable status
   list in `isSyncable()` so a cancelled shoot remains syncable. (Requested / declined /
   on-hold / hold_on stay non-syncable and continue to be deleted — only `cancelled`
   moves to syncable-with-cancel-state.)
2. The shoot still needs a schedule. A cancelled shoot retains its `scheduled_at`, so the
   existing `scheduled_at`/service-item-schedule guard is unaffected.
3. The rest of `syncShoot()` is unchanged: it resolves photographers, checks the mapping,
   and performs `update` (event exists) or `create`. The payload now carries the
   `CANCELLED - {client}` title and the `Shoot Status: Cancelled` description line, so the
   existing event is updated in place rather than removed (Req 8.1).

No change is needed to `removeShoot()` (hard delete) or `disconnectUser()` — those remain
deletion paths for genuinely removed shoots and disconnected users.

### Sync fingerprint (Req 9)

Currently the fingerprint is `sha1(json_encode($payload))`. Because the payload now
includes title, description, color, and reminders, most of the required signals are
already captured transitively. To make Requirement 9.3 explicit and robust against
formatting tweaks, the fingerprint is computed from a **canonical signature array** of the
underlying fields rather than the rendered payload:

```php
protected function fingerprintFor(Shoot $shoot, GoogleCalendarConnection $connection, array $payload): string
{
    $signature = [
        'client_name'  => $shoot->client?->name,
        'client_phone' => $shoot->client?->phone ?: $shoot->client?->phonenumber,
        'client_email' => $shoot->client?->email,
        'address'      => $this->payloadBuilder ? ... formatFullAddress ...,   // reuse payload['location']
        'scheduled_at' => optional($shoot->scheduled_at)?->toIso8601String(),
        'photographer' => $connection->user_id,
        'services'     => $shoot->services->pluck('name')->sort()->values()->all(),
        'service_times'=> $shoot->serviceItems->mapWithKeys(fn ($i) => [$i->id => optional($i->scheduled_at)?->toIso8601String()])->all(),
        'notes'        => $shoot->shoot_notes ?: $shoot->notes,
        'photographer_notes' => $shoot->photographer_notes,
        'status'       => $shoot->status,
        'workflow_status' => $shoot->workflow_status,
        'cancelled'    => $this->isCancelledStatus($shoot),
        'calendar_id'  => $connection->calendar_id,
    ];

    return sha1(json_encode($signature, JSON_THROW_ON_ERROR));
}
```

Simplest viable implementation: keep `sha1(json_encode($payload))` and **append** the
extra signal fields not otherwise visible in the payload (e.g. raw status, cancellation
flag) into the hashed input. Either way, the comparison logic is unchanged: recompute the
fingerprint, compare against `mapping->sync_fingerprint`, and skip the HTTP call when it
matches and `calendar_id` is unchanged (Req 9.2). Store the new fingerprint on the mapping
via the existing `updateOrCreate` after create/update.

This keeps `GoogleCalendarEventMapping` as the single source of truth and preserves the
existing **check-before-create → update else create** flow (Req 10.1–10.3). The same
broadened fingerprint applies to the per-service-item path (`syncServiceItemEvent`).

### Optional summary sync status mirror (Req 10.4)

Kept optional and minimal. If a summary mirror is desired, after a successful
create/update set a lightweight status string on the shoot (e.g. an existing
`metadata`/status field) guarded by a config flag
`config('services.google.calendar.mirror_sync_status', false)`. When the flag is off (the
default), no shoot write occurs. No schema change is introduced; if no suitable existing
column exists, this requirement is satisfied by the flag defaulting to off and is left as
a no-op hook. This is intentionally not on the critical path.

## Data Models

No schema changes. Existing models are reused as-is:

- `GoogleCalendarEventMapping`: `shoot_id`, `shoot_service_id`, `user_id`, `calendar_id`,
  `google_event_id`, `sync_fingerprint`, `last_synced_at`.
- `GoogleCalendarConnection`: `sync_enabled`, `calendar_id`, tokens, `last_synced_at`,
  `last_error`.
- `Shoot`: `client_id` (→ `client` User), `scheduled_at`, `status`, `workflow_status`,
  `address`/`city`/`state`/`zip`, `notes`, `shoot_notes`, `photographer_notes`, services /
  serviceItems.
- Client (`User`): `name`, `company_name`, `phone`, `phonenumber`, `email`.

## Error Handling

Reuse the existing pattern unchanged:

- All create/update/delete calls remain inside the existing `try/catch (Throwable)` blocks
  in `GoogleCalendarShootSyncService`. On failure, the message is written to
  `GoogleCalendarConnection.last_error` and logged via `Log::warning`; on success
  `last_error` is cleared and `last_synced_at` is set.
- `GoogleCalendarEventPayloadBuilder::build()` still throws `RuntimeException` when a shoot
  has no `scheduled_at`; the caller's existing guards (`isSyncable`) prevent that path for
  syncable shoots.
- Derivation helpers never throw: missing client/notes resolve to `Not provided` or an
  omitted line, so a sparse shoot still produces a valid payload.

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid
executions of a system — a formal statement about what the system should do.*

### Property 1: Title is client name, cancelled is prefixed

For any shoot, the event title equals the client display name when the shoot is not
cancelled, and equals `"CANCELLED - {client name}"` when it is cancelled; in no case does
the title contain service names, statuses, or photographer names.

**Validates: Requirements 1.1, 1.2, 1.3, 8.2**

### Property 2: Description omits empty phone/email lines but always renders named sections

For any shoot, the description contains a `Phone:` line iff the client phone is non-empty
and an `Email:` line iff the client email is non-empty, while the `Shoot Notes:`,
`Property Access:`, `Arrival Instructions:`, and `On-Site Contact:` sections are always
present, rendering `Not provided` when their derived value is empty.

**Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.7, 3.8, 3.9, 3.10**

### Property 3: Internal shoot link is always the final line

For any shoot, the last line of the description is
`View shoot: {base}/shoots/{shoot_id}` with the configured base URL.

**Validates: Requirements 3.11**

### Property 4: Description excludes internal/financial data

For any shoot, the description never contains pricing, payment status, or the contents of
`company_notes`, `editor_notes`, or `admin_issue_notes`.

**Validates: Requirements 3.12, 3.13**

### Property 5: End time equals start plus clamped duration

For any schedulable shoot, the event end time equals the start time plus
`calculateShootDurationFromShoot()`, a value clamped to 60–240 minutes and defaulting to
120 when no duration is derivable.

**Validates: Requirements 4.1, 4.2**

### Property 6: Reminders are explicit 24h and 30min popups

For any built event, `reminders.useDefault` is false and `reminders.overrides` contains
exactly popup entries at 1440 and 30 minutes.

**Validates: Requirements 5.1**

### Property 7: colorId is a supported value determined by status

For any shoot, the event `colorId` is one of Google's supported values (`"1"`–`"11"`) and
is determined solely by the shoot status per the color map.

**Validates: Requirements 6.1**

### Property 8: Per-service timing block appears iff schedules differ

For any shoot, the `Service Timing:` block is present iff its service items have more than
one distinct effective `scheduled_at`, and is absent otherwise.

**Validates: Requirements 7.1, 7.2**

### Property 9: Fingerprint changes iff a tracked field changes

For any two shoot states, the recomputed sync fingerprint differs iff at least one tracked
field differs (client name, phone, email, full address, date/time, photographer, services,
notes, status, or cancellation state).

**Validates: Requirements 9.1, 9.2, 9.3**

### Property 10: One mapping per shoot/photographer (no duplicates)

For any shoot processed any number of times without underlying changes, exactly one
`GoogleCalendarEventMapping` exists per `(shoot_id, shoot_service_id, user_id)`, and a
matching fingerprint produces no additional create call (update-or-create is idempotent).

**Validates: Requirements 10.1, 10.2, 10.3**

## Testing Strategy

The changed logic is pure string/array construction plus a deterministic hash, so it is
well suited to unit and property tests with no live HTTP.

**Unit tests (`GoogleCalendarEventPayloadBuilder`):**
- Title for non-cancelled vs cancelled shoots (Property 1).
- Description with/without phone/email; verify omitted lines and `Not provided` rendering
  for the named sections (Property 2).
- Internal link is the last line and uses the configured base URL (Property 3).
- Internal/financial fields never leak into the description (Property 4).
- `colorId` mapping table coverage including the default fallback (Property 7).
- Reminders block shape (Property 6).
- Per-service timing block present only when schedules differ (Property 8).
- End = start + clamped duration, including the 120-minute default (Property 5).

**Unit/property tests (fingerprint):**
- Mutating each tracked field changes the fingerprint; mutating an untracked field
  (e.g. `editor_notes`) does not (Property 9).
- Re-running with identical state yields an identical fingerprint and no duplicate
  mapping (Property 10) — exercised against the mapping resolution with a mocked
  `GoogleCalendarService`.

**Cancelled-shoot behavior:**
- A small example test asserting a cancelled shoot is treated as syncable and produces an
  update payload (title prefixed, `Shoot Status: Cancelled` present) rather than a delete
  (Requirements 8.1, 8.2). External HTTP is mocked.

Property tests run a minimum of 100 generated inputs and are tagged
`Feature: google-calendar-sync-upgrade, Property {n}: {property text}`. External services
(`GoogleCalendarService` HTTP calls) are always mocked; nothing in this feature warrants
live integration tests.
