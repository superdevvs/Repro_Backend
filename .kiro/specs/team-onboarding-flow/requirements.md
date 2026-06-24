# Requirements Document

## Introduction

This feature extends the existing first-time dashboard onboarding capability from the client role to four additional team roles: photographer, salesRep, editing_manager, and editor. The scope is backend only — eligibility flags, onboarding metadata, validation rules, and seeding for the four new roles. Frontend tour UI is out of scope.

The existing client-only `ClientDashboardOnboardingService` is replaced by one unified, role-aware service (`DashboardOnboardingService`) that writes a role-aware onboarding block into user metadata. The existing client onboarding logic is folded into this service and all existing client call sites are updated to use it. The unified service preserves backward compatibility with the existing `clientDashboardOnboarding` preference key (or defines an explicit migration path).

The feature also improves client onboarding with version-based re-trigger: today the onboarding version is fixed (`const VERSION = 1`) and eligibility is set once at account creation. With this change, when a new onboarding version ships, existing users whose stored version is lower become eligible again.

## Glossary

- **Onboarding_Service**: The unified, role-aware backend service (`DashboardOnboardingService`) that determines onboarding eligibility and writes the role-aware onboarding metadata block. Replaces `ClientDashboardOnboardingService`.
- **Onboarded_Role**: One of the roles supported by onboarding: `client`, `photographer`, `salesRep`, `editing_manager`, `editor`.
- **Onboarding_Block**: The role-aware metadata structure written under `metadata.preferences`, keyed per role, containing lifecycle fields (`eligible`, `version`, `createdAt`, `startedAt`, `completedAt`, `dismissedAt`, `lastStep`, `source`).
- **Onboarding_Version**: The integer version number of the onboarding experience for a given role, defined as a constant in the Onboarding_Service.
- **Eligibility**: The boolean state (`eligible`) indicating that a user should be shown the onboarding experience.
- **Source**: The optional origin label recorded when eligibility is applied (e.g. `registration`, `admin_account_created`, `artisan_import`, `api_import`, `external_booking`).
- **Call_Site**: A backend location that applies onboarding eligibility to a user during account creation or import (AuthController registration, Admin/UserController, ImportAccountsFromCsv, API/ImportController, API/ExternalBookingController).
- **Validation_Layer**: The request validation rules in `AuthController` that govern accepted onboarding lifecycle fields.
- **Legacy_Key**: The existing `metadata.preferences.clientDashboardOnboarding` preference key used before this feature.

## Requirements

### Requirement 1: Unified role-aware onboarding service

**User Story:** As a backend developer, I want a single role-aware onboarding service, so that all roles share one consistent eligibility and metadata mechanism instead of client-specific logic.

#### Acceptance Criteria

1. THE Onboarding_Service SHALL expose a method that accepts a user metadata array, an Onboarded_Role, and an optional Source, and returns the metadata array with the role-aware Onboarding_Block applied.
2. WHERE the Onboarded_Role is `client`, THE Onboarding_Service SHALL produce onboarding metadata equivalent to the prior `ClientDashboardOnboardingService` output.
3. THE Onboarding_Service SHALL define a distinct Onboarding_Version constant for each Onboarded_Role.
4. IF the provided role is not an Onboarded_Role, THEN THE Onboarding_Service SHALL return the metadata array unchanged without applying an Onboarding_Block.
5. THE Onboarding_Service SHALL apply the Onboarding_Block idempotently, preserving any existing lifecycle field values already present in the metadata.

### Requirement 2: Role-aware onboarding block structure

**User Story:** As a backend developer, I want onboarding metadata keyed per role, so that each role's onboarding lifecycle is tracked independently.

#### Acceptance Criteria

1. THE Onboarding_Service SHALL write the Onboarding_Block under `metadata.preferences` using a key that identifies the Onboarded_Role.
2. WHEN applying eligibility, THE Onboarding_Service SHALL set `eligible` to true, set `version` to the Onboarding_Version for the Onboarded_Role, and set `createdAt` to the current timestamp in ISO-8601 format.
3. WHERE a Source is provided, THE Onboarding_Service SHALL record the Source value in the Onboarding_Block.
4. WHERE a Source is not provided, THE Onboarding_Service SHALL omit the `source` field from the Onboarding_Block.
5. THE Onboarding_Service SHALL preserve unrelated keys under `metadata.preferences` when writing the Onboarding_Block.

### Requirement 3: Eligibility applied at all call sites for the four new roles

**User Story:** As a new photographer, salesRep, editing_manager, or editor, I want onboarding eligibility set when my account is created, so that I am eligible for onboarding on first use just like clients.

#### Acceptance Criteria

1. WHEN a user with an Onboarded_Role is created through registration, THE Onboarding_Service SHALL apply the Onboarding_Block with Source `registration`.
2. WHEN an admin creates a user with an Onboarded_Role through Admin/UserController, THE Onboarding_Service SHALL apply the Onboarding_Block with Source `admin_account_created`.
3. WHEN a user with an Onboarded_Role is imported through ImportAccountsFromCsv, THE Onboarding_Service SHALL apply the Onboarding_Block with Source `artisan_import`.
4. WHEN a user with an Onboarded_Role is imported through API/ImportController, THE Onboarding_Service SHALL apply the Onboarding_Block with Source `api_import`.
5. WHEN a user with an Onboarded_Role is created through API/ExternalBookingController, THE Onboarding_Service SHALL apply the Onboarding_Block with Source `external_booking`.
6. WHERE a Call_Site previously restricted eligibility to `role === client`, THE Call_Site SHALL apply eligibility for every Onboarded_Role.

### Requirement 4: Version-based re-trigger

**User Story:** As an existing user, I want to become eligible for onboarding again when a new onboarding version ships, so that I see updated onboarding content after changes.

#### Acceptance Criteria

1. WHEN the Onboarding_Service evaluates a user whose stored Onboarding_Block `version` is less than the current Onboarding_Version for that role, THE Onboarding_Service SHALL set `eligible` to true and update `version` to the current Onboarding_Version.
2. WHEN re-triggering eligibility for a new Onboarding_Version, THE Onboarding_Service SHALL clear the `completedAt`, `dismissedAt`, `startedAt`, and `lastStep` lifecycle fields.
3. WHILE a user's stored Onboarding_Block `version` equals the current Onboarding_Version, THE Onboarding_Service SHALL leave the existing `eligible` value and lifecycle fields unchanged.
4. IF a user has no stored Onboarding_Block for the Onboarded_Role, THEN THE Onboarding_Service SHALL apply a new Onboarding_Block at the current Onboarding_Version.

### Requirement 5: Validation of onboarding lifecycle fields

**User Story:** As a backend developer, I want validation rules to accept onboarding lifecycle fields for all onboarded roles, so that clients can persist onboarding state for each role without rejection.

#### Acceptance Criteria

1. THE Validation_Layer SHALL accept the role-aware Onboarding_Block as a nullable array for each Onboarded_Role.
2. THE Validation_Layer SHALL accept `eligible` as a nullable boolean, `version` as a nullable integer between 1 and 100, and `lastStep` as a nullable integer between 0 and 100 within each Onboarding_Block.
3. THE Validation_Layer SHALL accept `createdAt`, `startedAt`, `completedAt`, `dismissedAt`, and `source` as nullable strings of at most 100 characters within each Onboarding_Block.
4. IF a submitted onboarding lifecycle field violates its type or range rule, THEN THE Validation_Layer SHALL reject the request with a validation error.

### Requirement 6: Backward compatibility and migration

**User Story:** As a product owner, I want existing client onboarding data preserved, so that current clients are not disrupted by the unified service.

#### Acceptance Criteria

1. THE Onboarding_Service SHALL continue to read existing onboarding state stored under the Legacy_Key for the `client` role.
2. WHERE existing client onboarding data is stored under the Legacy_Key, THE Onboarding_Service SHALL preserve that data's lifecycle field values when applying or re-evaluating eligibility.
3. THE feature SHALL define an explicit migration path WHERE the role-aware key for the `client` role differs from the Legacy_Key.
4. WHEN the Onboarding_Service processes a `client` user, THE resulting onboarding metadata SHALL remain readable by existing client onboarding consumers.

### Requirement 7: Seeding for the four new roles

**User Story:** As a backend developer, I want a seeding mechanism to apply onboarding eligibility to existing photographer, salesRep, editing_manager, and editor users, so that current team members become eligible without manual edits.

#### Acceptance Criteria

1. THE feature SHALL provide a seeding mechanism that applies the Onboarding_Block to existing users whose role is photographer, salesRep, editing_manager, or editor.
2. WHEN the seeding mechanism processes a user that already has an Onboarding_Block at the current Onboarding_Version, THE seeding mechanism SHALL leave that user's Onboarding_Block unchanged.
3. WHEN the seeding mechanism applies eligibility, THE seeding mechanism SHALL record a Source identifying the seeding origin.
4. THE seeding mechanism SHALL leave users whose role is not an Onboarded_Role unchanged.
