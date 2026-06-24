# Implementation Plan: Team Onboarding Flow

## Overview

This plan implements a unified, role-aware `DashboardOnboardingService` (replacing `ClientDashboardOnboardingService`) in PHP/Laravel. Work starts with the pure service logic (constants, `ROLE_MAP`, role helpers, `applyEligibility` with version-based re-trigger), validated by property-based tests against the 9 correctness properties. It then wires the service into all five call sites, generalizes `AuthController` validation, adds the `SeedDashboardOnboardingForTeam` artisan command, and finally removes the legacy service. Each task builds on the previous and ends with integration wiring so no code is left orphaned.

## Verification Findings

These findings come from a verification pass run after the implementation tasks completed. They document the verification path used, the results, and the coverage gaps that the new tasks (section 8) close.

### Verification path

- Browser tests (Laravel Dusk) are **not** installed in this project. The test suite is PHPUnit 11 + Pest. This feature is backend-only with no UI surface, so there is no browser-testable behavior. The verification path is the HTTP feature-test suite under `tests/Feature`.

### Verification run results

- `tests/Feature/Auth` — 18 passed, including `UpdateProfileTest`, which exercises the generalized onboarding validation.
- `ExternalBookingControllerTest` — 2 passed.
- No regressions were introduced by the call-site changes, and no bugs were found.

### Coverage gaps found

- **(a) No onboarding-specific automated tests exist yet.** The optional test tasks (1.2–1.9, 3.6, 4.2, 5.2) remain unwritten, so the service's correctness properties and the call-site wiring are not yet covered by dedicated tests.
- **(b) Three call sites are unexercised by existing feature tests.** No current feature tests cover the `API/ImportController`, `Console/Commands/ImportAccountsFromCsv`, or `Admin/UserController` call sites, so role-based eligibility application at those three paths is unverified. Tasks 8.1–8.3 add the missing coverage; these are not optional because they close real coverage holes rather than duplicating existing tests.

### Comprehensive feature test added (gaps closed)

- A comprehensive end-to-end feature test now exists and passes: `tests/Feature/TeamOnboardingFlowComprehensiveTest.php` — **10 tests, 77 assertions, all green**. It exercises every onboarding flow against the real framework:
  - Registration (`POST /api/register`) → client block, source `registration`
  - Admin create (`POST /api/admin/users`) → all 5 onboarded roles get the correct role-keyed block, source `admin_account_created`; the non-onboarded `admin` role gets no block
  - API import (`POST /api/import/accounts`, CSV upload) → source `api_import`
  - CSV import artisan command (`accounts:import`) → source `artisan_import`
  - External booking (`POST /api/external/book-shoot`) → client block, source `external_booking`
  - Profile validation (`PUT /api/profile`) accepts valid role-aware blocks and rejects malformed ones (422)
  - Version-based re-trigger: lower stored version re-triggers, clears `completedAt`/`dismissedAt`/`startedAt`/`lastStep`, bumps version, preserves `createdAt`
  - Seeding command (`onboarding:seed-team`): seeds the 4 team roles with source `seed_team_command`, dry-run writes nothing, non-onboarded role untouched
  - Service core: non-onboarded passthrough, unrelated-key preservation, idempotence
- This closes coverage gap (b) entirely (admin/API-import/CSV-import call sites) and substantially covers gap (a) for call-site wiring, validation, the seed command, and core service behaviors.
- A **Playwright/real-Chrome live run** validated the admin-create flow end-to-end through the actual login + UI session for all roles.
- **Still pending:** Laravel Dusk is still not installed, and the 4 new team roles still have no frontend onboarding UI. True browser-tour testing therefore remains pending frontend work.

## Tasks

- [x] 1. Implement the unified role-aware onboarding service
  - Core service behaviors (idempotence, non-onboarded passthrough, unrelated-key preservation, and version-based re-trigger) are now also exercised end-to-end by `TeamOnboardingFlowComprehensiveTest`, in addition to the dedicated property tests below.
  - [x] 1.1 Create `DashboardOnboardingService` with constants, role map, helpers, and `applyEligibility`
    - Create `app/Services/Users/DashboardOnboardingService.php`
    - Define per-role version constants (`VERSION_CLIENT`, `VERSION_PHOTOGRAPHER`, `VERSION_SALES_REP`, `VERSION_EDITING_MANAGER`, `VERSION_EDITOR`) and the `ROLE_MAP` (client → legacy `clientDashboardOnboarding` key) and `RESETTABLE_FIELDS`
    - Implement `isOnboardedRole`, `keyForRole`, `versionForRole`
    - Implement `applyEligibility(array $metadata, string $role, ?string $source = null): array` with role guard, defensive array coercion, fresh-apply, version-based re-trigger (clear resettable fields), and "current version unchanged" branches; fold `source` via `array_filter` and preserve `createdAt` and unrelated preference keys
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.2, 2.3, 2.4, 2.5, 4.1, 4.2, 4.3, 4.4, 6.1, 6.2, 6.4_

  - [ ]* 1.2 Write property test: application writes a role-keyed block
    - **Property 1: Application writes a role-keyed block**
    - **Validates: Requirements 1.1, 2.1**
    - Minimum 100 generated iterations over metadata arrays and onboarded roles

  - [ ]* 1.3 Write property test: client output equals legacy service output
    - **Property 2: Client output equals legacy service output**
    - **Validates: Requirements 1.2, 6.1, 6.2, 6.4**

  - [ ]* 1.4 Write property test: non-onboarded roles pass through unchanged
    - **Property 3: Non-onboarded roles pass through unchanged**
    - **Validates: Requirements 1.4**

  - [ ]* 1.5 Write property test: eligibility fields set correctly with source handling
    - **Property 4: Eligibility fields are set correctly with source handling**
    - **Validates: Requirements 2.2, 2.3, 2.4, 4.4**

  - [ ]* 1.6 Write property test: application is idempotent and preserves lifecycle values
    - **Property 5: Application is idempotent and preserves existing lifecycle values**
    - **Validates: Requirements 1.5**

  - [ ]* 1.7 Write property test: unrelated preference keys are preserved
    - **Property 6: Unrelated preference keys are preserved**
    - **Validates: Requirements 2.5**

  - [ ]* 1.8 Write property test: lower stored version re-triggers and clears progress
    - **Property 7: Lower stored version re-triggers eligibility and clears progress**
    - **Validates: Requirements 4.1, 4.2**

  - [ ]* 1.9 Write property test: current stored version is left unchanged
    - **Property 8: Current stored version is left unchanged**
    - **Validates: Requirements 4.3**

- [x] 2. Checkpoint - service logic verified
  - Ensure all tests pass, ask the user if questions arise.

- [x] 3. Wire the service into all five call sites
  - [x] 3.1 Update `API/AuthController@register` to use the unified service
    - Replace the `ClientDashboardOnboardingService` import with `DashboardOnboardingService`
    - Apply eligibility for `client` with source `registration` on the created metadata
    - _Requirements: 3.1_

  - [x] 3.2 Update `Admin/UserController` to apply eligibility for any onboarded role
    - Swap the service import; remove the `role === 'client'` gate
    - Call `applyEligibility($validated['metadata'] ?? [], $validated['role'] ?? '', 'admin_account_created')`, relying on the service role guard
    - _Requirements: 3.2, 3.6_

  - [x] 3.3 Update `Console/Commands/ImportAccountsFromCsv` to apply eligibility for any onboarded role
    - Swap the service import; replace the `$role === 'client'` gate
    - Call `applyEligibility($userData['metadata'] ?? [], $role, 'artisan_import')`
    - _Requirements: 3.3, 3.6_

  - [x] 3.4 Update `API/ImportController` to apply eligibility for any onboarded role
    - Swap the service import; replace the `$role === 'client'` gate
    - Call `applyEligibility($userData['metadata'] ?? [], $role, 'api_import')`
    - _Requirements: 3.4, 3.6_

  - [x] 3.5 Update `API/ExternalBookingController` to use the unified service
    - Swap the service import
    - Apply eligibility for `client` with source `external_booking`; leave subsequent guest-booking metadata additions unchanged
    - _Requirements: 3.5_

  - [x]* 3.6 Write integration tests for all five call sites (covered by TeamOnboardingFlowComprehensiveTest)
    - Drive each call site with each relevant onboarded role and assert the block is applied under the correct role key with the correct source
    - Assert non-onboarded roles produce no onboarding block at the admin/import call sites
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6_

- [x] 4. Generalize onboarding validation in AuthController
  - [x] 4.1 Extend `AuthController` validation to cover all five role keys
    - Build onboarding rules programmatically for all five `*DashboardOnboarding` keys and merge into the validation array
    - Apply rules: block as `nullable|array`; `eligible` nullable boolean; `version` nullable integer 1–100; `lastStep` nullable integer 0–100; `createdAt`/`startedAt`/`completedAt`/`dismissedAt`/`source` nullable string max 100
    - _Requirements: 5.1, 5.2, 5.3, 5.4_

  - [x]* 4.2 Write validation tests for well-formed and malformed blocks (covered by TeamOnboardingFlowComprehensiveTest)
    - **Property 9: Validation accepts well-formed blocks and rejects malformed fields**
    - **Validates: Requirements 5.1, 5.2, 5.3, 5.4**

- [x] 5. Implement the seeding artisan command
  - [x] 5.1 Create `SeedDashboardOnboardingForTeam` command
    - Create `app/Console/Commands/SeedDashboardOnboardingForTeam.php` with signature `onboarding:seed-team {--dry-run} {--role=*}`
    - Chunk users by the four new roles, delegate to `applyEligibility` with source `seed_team_command`, skip when `$after === $before`, save when not a dry run, and report updated/unchanged counts
    - _Requirements: 7.1, 7.2, 7.3, 7.4_

  - [x]* 5.2 Write integration tests for the seeding command (covered by TeamOnboardingFlowComprehensiveTest)
    - Seed users of each new role and assert blocks applied with the seed source; assert users already at the current version are unchanged; assert non-onboarded users untouched; assert `--dry-run` writes nothing
    - _Requirements: 7.1, 7.2, 7.3, 7.4_

- [x] 6. Remove the legacy service and finalize wiring
  - [x] 6.1 Delete `ClientDashboardOnboardingService` and confirm no remaining references
    - Remove `app/Services/Users/ClientDashboardOnboardingService.php`
    - Search the codebase for any remaining references and update or remove them
    - _Requirements: 1.2, 6.1_

- [x] 7. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 8. Close call-site coverage gaps (verification findings)
  - [x] 8.1 Add feature-test coverage for the `Admin/UserController` call site (covered by TeamOnboardingFlowComprehensiveTest)
    - Add a feature test under `tests/Feature` (e.g. `tests/Feature/Admin/UserControllerOnboardingTest.php`) following the established PHPUnit/Pest conventions (in-memory sqlite per `phpunit.xml`)
    - Drive the admin user-creation endpoint with each onboarded role (`photographer`, `salesRep`, `editing_manager`, `editor`, `client`) and assert the persisted user's metadata contains the correct role-keyed onboarding block (`eligible=true`, current `version`) with `source` equal to `'admin_account_created'`
    - Assert that creating a user with a non-onboarded role writes no onboarding block to `metadata.preferences`
    - _Requirements: 3.2, 3.6_

  - [x] 8.2 Add feature/integration-test coverage for the `API/ImportController` call site (covered by TeamOnboardingFlowComprehensiveTest)
    - Add a feature/integration test under `tests/Feature` following the established PHPUnit/Pest conventions
    - Drive the API import path with onboarded roles and assert the role-keyed onboarding block is applied with `source` equal to `'api_import'`
    - Assert that importing a non-onboarded role produces no onboarding block
    - _Requirements: 3.4, 3.6_

  - [x] 8.3 Add command-test coverage for `ImportAccountsFromCsv` (covered by TeamOnboardingFlowComprehensiveTest)
    - Add a command test under `tests/Feature` (e.g. `tests/Feature/Console/ImportAccountsFromCsvOnboardingTest.php`) that invokes the `ImportAccountsFromCsv` artisan command following the established conventions
    - Assert that imported users with onboarded roles receive the correct role-keyed onboarding block with `source` equal to `'artisan_import'`
    - _Requirements: 3.3, 3.6_

## Notes

- Tasks marked with `*` are optional test tasks and can be skipped for a faster MVP.
- Each task references specific requirements for traceability.
- Property tests (1.2–1.9, 4.2) validate the universal correctness properties from the design; integration tests (3.6, 5.2) validate call-site wiring, validation, and the seeding command.
- Property and service-test sub-tasks share `DashboardOnboardingServiceTest`, so they are scheduled in separate waves to avoid file conflicts.
- `AuthController` is touched by both 3.1 (register) and 4.1 (validation), so those are scheduled in different waves.
- Tasks 8.1–8.3 are non-optional follow-ups from the verification pass; they add feature/command-test coverage for the three previously unexercised call sites and depend on the already-completed call-site wiring (3.2, 3.3, 3.4). Each writes to a distinct new test file, so they can run in parallel.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["3.1", "3.2", "3.3", "3.4", "3.5", "5.1", "1.2"] },
    { "id": 2, "tasks": ["4.1", "5.2", "3.6", "1.3", "8.1", "8.2", "8.3"] },
    { "id": 3, "tasks": ["4.2", "1.4"] },
    { "id": 4, "tasks": ["6.1", "1.5"] },
    { "id": 5, "tasks": ["1.6"] },
    { "id": 6, "tasks": ["1.7"] },
    { "id": 7, "tasks": ["1.8"] },
    { "id": 8, "tasks": ["1.9"] }
  ]
}
```
