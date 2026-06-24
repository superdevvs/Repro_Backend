# Design Document

## Overview

This design replaces the client-only `ClientDashboardOnboardingService` with a single, role-aware `DashboardOnboardingService`. The new service determines onboarding eligibility and writes a role-keyed onboarding block into `metadata.preferences` for all five onboarded roles: `client`, `photographer`, `salesRep`, `editing_manager`, and `editor`.

The service folds the existing client logic in unchanged for the `client` role: the client block continues to live under the existing `clientDashboardOnboarding` preference key (the Legacy_Key), so existing client onboarding consumers and stored data are unaffected. The four new roles each get their own role-keyed block and their own version constant.

A new capability — version-based re-trigger — lets the service re-flag a user as eligible when a newer onboarding version ships for their role, while leaving users at the current version untouched.

All five call sites (registration, admin create, artisan import, API import, external booking) are updated to use the unified service and to apply eligibility for every onboarded role rather than just `client`. `AuthController` validation is extended to accept the role-aware blocks. A new artisan command seeds eligibility for existing users of the four new roles.

Scope is backend only. No frontend tour UI is included.

### Goals

- One service, one mechanism, five roles.
- Byte-for-byte equivalent output for `client` (no migration, no data churn).
- Independent per-role lifecycle tracking.
- Version-based re-trigger that is safe and idempotent.
- Minimal, surgical changes at each call site.

### Non-Goals

- Frontend onboarding tour rendering or step content.
- Changing the meaning or shape of the existing client onboarding fields.
- Reworking the broader `metadata`/`preferences` schema.

## Architecture

```
                         ┌──────────────────────────────────────────┐
   Call Sites            │      DashboardOnboardingService           │
 ┌──────────────────┐    │                                           │
 │ AuthController    │───▶│ applyEligibility(metadata, role, source?) │
 │ Admin/UserCtrl    │───▶│   ├─ role guard (onboarded roles only)    │
 │ ImportAccounts...  │───▶│   ├─ resolve role key + version          │
 │ API/ImportCtrl    │───▶│   ├─ read existing block (role / legacy)   │
 │ ExternalBooking   │───▶│   ├─ version-based re-trigger logic        │
 └──────────────────┘    │   └─ write block under metadata.preferences│
                         │                                           │
 ┌──────────────────┐    │ versionForRole(role): int                 │
 │ SeedDashboard...   │───▶│ keyForRole(role): string                  │
 │ (artisan command) │    │ isOnboardedRole(role): bool               │
 └──────────────────┘    └──────────────────────────────────────────┘
                                          │
                                          ▼
                            metadata.preferences.{roleKey} = {block}
```

The service is a stateless, pure transformer over the `metadata` array. It performs no database I/O; persistence remains the responsibility of the calling code (`User::create(...)` / `$user->save()`). This keeps the core logic trivially testable and lets the same method serve both account-creation call sites and the seeding command.

### Role → key and version mapping

| Role               | Preference key (under `metadata.preferences`) | Version constant         |
| ------------------ | --------------------------------------------- | ------------------------ |
| `client`           | `clientDashboardOnboarding` (Legacy_Key)      | `VERSION_CLIENT`         |
| `photographer`     | `photographerDashboardOnboarding`             | `VERSION_PHOTOGRAPHER`   |
| `salesRep`         | `salesRepDashboardOnboarding`                 | `VERSION_SALES_REP`      |
| `editing_manager`  | `editingManagerDashboardOnboarding`           | `VERSION_EDITING_MANAGER`|
| `editor`           | `editorDashboardOnboarding`                   | `VERSION_EDITOR`         |

The `client` role intentionally maps to the existing `clientDashboardOnboarding` key. Because the role key equals the Legacy_Key for `client`, no data migration is required (satisfies Requirement 6.3 — the migration path is "the keys are identical, so there is nothing to migrate"). Existing consumers keep reading the same key.

## Components and Interfaces

### DashboardOnboardingService

New class at `app/Services/Users/DashboardOnboardingService.php`. Replaces `ClientDashboardOnboardingService`.

```php
<?php

namespace App\Services\Users;

class DashboardOnboardingService
{
    public const VERSION_CLIENT = 1;
    public const VERSION_PHOTOGRAPHER = 1;
    public const VERSION_SALES_REP = 1;
    public const VERSION_EDITING_MANAGER = 1;
    public const VERSION_EDITOR = 1;

    /**
     * Map each onboarded role to its preference key and version constant.
     * 'client' uses the legacy key for backward compatibility.
     */
    private const ROLE_MAP = [
        'client' => ['key' => 'clientDashboardOnboarding', 'version' => self::VERSION_CLIENT],
        'photographer' => ['key' => 'photographerDashboardOnboarding', 'version' => self::VERSION_PHOTOGRAPHER],
        'salesRep' => ['key' => 'salesRepDashboardOnboarding', 'version' => self::VERSION_SALES_REP],
        'editing_manager' => ['key' => 'editingManagerDashboardOnboarding', 'version' => self::VERSION_EDITING_MANAGER],
        'editor' => ['key' => 'editorDashboardOnboarding', 'version' => self::VERSION_EDITOR],
    ];

    /**
     * Lifecycle fields cleared when re-triggering for a newer version.
     */
    private const RESETTABLE_FIELDS = ['completedAt', 'dismissedAt', 'startedAt', 'lastStep'];

    public function isOnboardedRole(?string $role): bool
    {
        return $role !== null && array_key_exists($role, self::ROLE_MAP);
    }

    public function keyForRole(string $role): ?string
    {
        return self::ROLE_MAP[$role]['key'] ?? null;
    }

    public function versionForRole(string $role): ?int
    {
        return self::ROLE_MAP[$role]['version'] ?? null;
    }

    /**
     * Apply (or re-evaluate) the role-aware onboarding block on a metadata array.
     *
     * - Non-onboarded roles return metadata unchanged.
     * - Fresh users get a new block at the current version.
     * - Users whose stored version is below current are re-triggered.
     * - Users already at the current version are left unchanged.
     */
    public function applyEligibility(array $metadata, string $role, ?string $source = null): array
    {
        if (!$this->isOnboardedRole($role)) {
            return $metadata; // Requirement 1.4
        }

        $key = self::ROLE_MAP[$role]['key'];
        $currentVersion = self::ROLE_MAP[$role]['version'];

        $preferences = $metadata['preferences'] ?? [];
        if (!is_array($preferences)) {
            $preferences = [];
        }

        $existing = $preferences[$key] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }

        $storedVersion = isset($existing['version']) ? (int) $existing['version'] : null;

        if ($storedVersion !== null && $storedVersion >= $currentVersion) {
            // Already at (or beyond) current version: leave eligible + lifecycle untouched.
            // Requirement 4.3 — return unchanged.
            $metadata['preferences'] = $preferences;
            return $metadata;
        }

        if ($storedVersion !== null && $storedVersion < $currentVersion) {
            // Version-based re-trigger. Requirement 4.1 / 4.2.
            foreach (self::RESETTABLE_FIELDS as $field) {
                unset($existing[$field]);
            }
        }

        // Fresh application or re-trigger: set core eligibility fields.
        // Preserve any other existing lifecycle values (e.g. createdAt on re-trigger).
        $block = array_replace($existing, array_filter([
            'eligible' => true,
            'version' => $currentVersion,
            'createdAt' => $existing['createdAt'] ?? now()->toISOString(),
            'source' => $source,
        ], fn ($value) => $value !== null));

        $preferences[$key] = $block;
        $metadata['preferences'] = $preferences;

        return $metadata;
    }
}
```

#### Method: `applyEligibility(array $metadata, string $role, ?string $source = null): array`

This is the single entry point used by every call site and by the seeding command. Note the signature change from the legacy service: `role` is now a required second argument, and `source` moves to third.

Behavior, by case:

| Stored state                              | Result                                                                    |
| ----------------------------------------- | ------------------------------------------------------------------------- |
| Role not onboarded                        | Metadata returned unchanged (Req 1.4).                                     |
| No block for role                         | New block at current version, `eligible=true`, fresh `createdAt` (Req 4.4, 2.2). |
| `version < currentVersion`                | Re-trigger: `eligible=true`, `version=current`, resettable fields cleared (Req 4.1, 4.2). |
| `version >= currentVersion`               | Returned unchanged — eligible + lifecycle preserved (Req 4.3, 1.5).       |

`source` is folded in through `array_filter(...)`, so a `null` source is never written (Req 2.4), and a provided source is recorded (Req 2.3). `createdAt` is preserved across re-triggers (the original creation time is kept), and unrelated preference keys are never touched because we only assign `$preferences[$key]` (Req 2.5).

#### Client equivalence (Req 1.2, 6.1, 6.2, 6.4)

For `role === 'client'`, the key is `clientDashboardOnboarding` and the version is `VERSION_CLIENT = 1`, matching the legacy constant. On a fresh client metadata array (no existing block), the produced block is `{eligible: true, version: 1, createdAt: <iso>, source: <source?>}` — identical in shape and key to the legacy `ClientDashboardOnboardingService` output. Existing legacy data under the key is read and preserved, so existing client consumers keep working without migration.

### Call site updates

All five call sites swap `ClientDashboardOnboardingService` for `DashboardOnboardingService`, pass the user's role, and drop any `role === 'client'` gate so eligibility is applied for every onboarded role. For non-onboarded roles the service is a no-op, so call sites can invoke it unconditionally without a manual role check.

**1. `API/AuthController@register` (~line 76)** — registration always creates `role => 'client'`:

```php
'metadata' => app(DashboardOnboardingService::class)->applyEligibility([], 'client', 'registration'),
```

**2. `Admin/UserController` (~line 376)** — remove the `=== 'client'` gate; apply for the created role:

```php
$validated['metadata'] = app(DashboardOnboardingService::class)->applyEligibility(
    $validated['metadata'] ?? [],
    $validated['role'] ?? '',
    'admin_account_created'
);
```

The service's internal role guard makes this safe for any role value (Req 3.6).

**3. `Console/Commands/ImportAccountsFromCsv` (~line 155)** — replace the `$role === 'client'` gate:

```php
$userData['metadata'] = app(DashboardOnboardingService::class)->applyEligibility(
    $userData['metadata'] ?? [],
    $role,
    'artisan_import'
);
```

**4. `API/ImportController` (~line 152)** — replace the `$role === 'client'` gate:

```php
$userData['metadata'] = app(DashboardOnboardingService::class)->applyEligibility(
    $userData['metadata'] ?? [],
    $role,
    'api_import'
);
```

**5. `API/ExternalBookingController` (~line 307)** — external booking always creates `role => 'client'`:

```php
$metadata = app(DashboardOnboardingService::class)->applyEligibility([], 'client', 'external_booking');
```

(The subsequent guest-booking metadata additions remain unchanged.)

In each case the existing `use App\Services\Users\ClientDashboardOnboardingService;` import is replaced with `use App\Services\Users\DashboardOnboardingService;`.

### Validation layer (Req 5)

`AuthController` currently validates only the `clientDashboardOnboarding` block. The rules are generalized to cover all five role keys. To avoid five duplicated blocks, the rules are built programmatically and merged into the validation array:

```php
$onboardingKeys = [
    'clientDashboardOnboarding',
    'photographerDashboardOnboarding',
    'salesRepDashboardOnboarding',
    'editingManagerDashboardOnboarding',
    'editorDashboardOnboarding',
];

$onboardingRules = [];
foreach ($onboardingKeys as $key) {
    $onboardingRules["preferences.{$key}"] = 'nullable|array';
    $onboardingRules["preferences.{$key}.eligible"] = 'nullable|boolean';
    $onboardingRules["preferences.{$key}.version"] = 'nullable|integer|min:1|max:100';
    $onboardingRules["preferences.{$key}.createdAt"] = 'nullable|string|max:100';
    $onboardingRules["preferences.{$key}.startedAt"] = 'nullable|string|max:100';
    $onboardingRules["preferences.{$key}.completedAt"] = 'nullable|string|max:100';
    $onboardingRules["preferences.{$key}.dismissedAt"] = 'nullable|string|max:100';
    $onboardingRules["preferences.{$key}.lastStep"] = 'nullable|integer|min:0|max:100';
    $onboardingRules["preferences.{$key}.source"] = 'nullable|string|max:100';
}

$validated = $request->validate(array_merge([
    // ... existing rules ...
], $onboardingRules));
```

This keeps the existing `clientDashboardOnboarding` rules intact (backward compatible) while adding identical rules for the four new keys. Field constraints map directly to Req 5.2 / 5.3, and Laravel's validator rejects type/range violations (Req 5.4).

### Seeding command (Req 7)

New artisan command `app/Console/Commands/SeedDashboardOnboardingForTeam.php`, following the existing `Seed*` command conventions in the codebase.

```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Users\DashboardOnboardingService;
use Illuminate\Console\Command;

class SeedDashboardOnboardingForTeam extends Command
{
    protected $signature = 'onboarding:seed-team {--dry-run} {--role=*}';

    protected $description = 'Apply dashboard onboarding eligibility to existing photographer, salesRep, editing_manager, and editor users.';

    private const SEED_ROLES = ['photographer', 'salesRep', 'editing_manager', 'editor'];

    public function handle(DashboardOnboardingService $service): int
    {
        $roles = $this->option('role') ?: self::SEED_ROLES;
        $roles = array_values(array_intersect($roles, self::SEED_ROLES));
        $dryRun = (bool) $this->option('dry-run');

        $updated = 0;
        $unchanged = 0;

        User::query()
            ->whereIn('role', $roles)
            ->chunkById(200, function ($users) use ($service, $dryRun, &$updated, &$unchanged) {
                foreach ($users as $user) {
                    $before = $user->metadata ?? [];
                    $after = $service->applyEligibility($before, $user->role, 'seed_team_command');

                    if ($after === $before) {
                        $unchanged++;
                        continue;
                    }

                    if (!$dryRun) {
                        $user->metadata = $after;
                        $user->save();
                    }
                    $updated++;
                }
            });

        $this->info(sprintf('Onboarding seed complete. Updated: %d, Unchanged: %d%s',
            $updated, $unchanged, $dryRun ? ' (dry run)' : ''));

        return self::SUCCESS;
    }
}
```

Because the command delegates to `applyEligibility`, idempotence and the version-aware behavior come for free: users already at the current version produce identical metadata (`$after === $before`) and are left unchanged (Req 7.2), the seeding `source` is recorded (Req 7.3), and the `whereIn('role', ...)` filter plus the service's role guard ensure non-onboarded users are never touched (Req 7.4).

## Data Models

### Onboarding_Block

Stored at `metadata.preferences.{roleKey}`. All fields optional except those set on application.

| Field         | Type           | Set when                        | Notes                                            |
| ------------- | -------------- | ------------------------------- | ------------------------------------------------ |
| `eligible`    | boolean        | on apply / re-trigger           | `true` when the user should see onboarding.      |
| `version`     | integer (1–100)| on apply / re-trigger           | Current Onboarding_Version for the role.         |
| `createdAt`   | string (ISO-8601, ≤100)| first apply             | Preserved across re-triggers.                    |
| `startedAt`   | string (≤100)  | by frontend later               | Cleared on re-trigger.                           |
| `completedAt` | string (≤100)  | by frontend later               | Cleared on re-trigger.                           |
| `dismissedAt` | string (≤100)  | by frontend later               | Cleared on re-trigger.                           |
| `lastStep`    | integer (0–100)| by frontend later               | Cleared on re-trigger.                           |
| `source`      | string (≤100)  | when a source is provided       | Omitted when no source given.                    |

Example metadata after applying eligibility to a photographer at registration-style creation:

```json
{
  "preferences": {
    "photographerDashboardOnboarding": {
      "eligible": true,
      "version": 1,
      "createdAt": "2025-01-15T10:30:00.000000Z",
      "source": "admin_account_created"
    }
  }
}
```

## Error Handling

- **Defensive array coercion**: `preferences` and the existing block are coerced to arrays when malformed (non-array), mirroring the legacy service, so corrupt metadata never throws.
- **Unknown roles**: returned unchanged rather than raising — call sites can call unconditionally.
- **Validation errors**: handled by Laravel's validator; malformed onboarding fields produce a standard `422` validation response (Req 5.4).
- **Seeding failures**: chunked iteration isolates per-user work; a save failure on one user surfaces as a thrown exception from `save()` — the command runs within normal artisan error reporting. A `--dry-run` flag allows safe previews before writing.

## Testing Strategy

Dual approach: property-based tests for the pure service logic (high input variation, cheap to run), and example/integration tests for call-site wiring, validation, and the seeding command (behavior that does not vary meaningfully with input).

- **Property tests** target `DashboardOnboardingService` directly with generated metadata arrays, roles, sources, and stored versions. Minimum 100 iterations per property.
- **Integration/example tests** drive each of the five call sites with each onboarded role and assert the block is applied with the correct source, exercise the validation rules with valid and invalid payloads, and run the seeding command against seeded users.
- **Smoke checks** confirm a version constant and key resolve for every onboarded role and that `client` resolves to the legacy key.

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system-essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Application writes a role-keyed block

For any metadata array and any onboarded role, applying eligibility produces metadata containing an onboarding block under `metadata.preferences` at the role's designated key.

**Validates: Requirements 1.1, 2.1**

### Property 2: Client output equals legacy service output

For any metadata array, applying eligibility for the `client` role produces a block under the `clientDashboardOnboarding` legacy key whose lifecycle fields are equivalent to the prior `ClientDashboardOnboardingService` output, and existing legacy values are preserved.

**Validates: Requirements 1.2, 6.1, 6.2, 6.4**

### Property 3: Non-onboarded roles pass through unchanged

For any metadata array and any role that is not an onboarded role, applying eligibility returns the metadata array unchanged.

**Validates: Requirements 1.4**

### Property 4: Eligibility fields are set correctly with source handling

For any fresh metadata array and any onboarded role, applying eligibility sets `eligible` to true, sets `version` to that role's Onboarding_Version, and sets `createdAt` to a valid ISO-8601 timestamp; the `source` field equals the provided source when one is given and is absent when no source is given.

**Validates: Requirements 2.2, 2.3, 2.4, 4.4**

### Property 5: Application is idempotent and preserves existing lifecycle values

For any metadata array and onboarded role, applying eligibility twice yields the same result as applying it once, and any lifecycle field values already present at the current version are preserved.

**Validates: Requirements 1.5**

### Property 6: Unrelated preference keys are preserved

For any metadata array containing arbitrary unrelated keys under `metadata.preferences`, applying eligibility leaves those unrelated keys unchanged.

**Validates: Requirements 2.5**

### Property 7: Lower stored version re-triggers eligibility and clears progress

For any stored onboarding block whose `version` is less than the current Onboarding_Version for its role, re-evaluating sets `eligible` to true, updates `version` to the current Onboarding_Version, and clears the `completedAt`, `dismissedAt`, `startedAt`, and `lastStep` fields.

**Validates: Requirements 4.1, 4.2**

### Property 8: Current stored version is left unchanged

For any stored onboarding block whose `version` equals (or exceeds) the current Onboarding_Version for its role, re-evaluating leaves the existing `eligible` value and all lifecycle fields unchanged.

**Validates: Requirements 4.3**

### Property 9: Validation accepts well-formed blocks and rejects malformed fields

For any onboarding block whose fields are within their defined types and ranges, the validation layer accepts the request; for any block where a field violates its type or range rule, the validation layer rejects the request with a validation error.

**Validates: Requirements 5.1, 5.2, 5.3, 5.4**
