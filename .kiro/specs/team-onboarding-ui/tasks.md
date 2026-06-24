# Implementation Plan: team-onboarding-ui

## Overview

This plan implements the role-aware onboarding subsystem using the design's "generalize-then-wrap" strategy. Work proceeds foundation-first: build the config map, role-aware event bus, generalized hook, and generalized component; reduce the legacy client modules to thin backward-compatible wrappers; update the sidebar, layout, and backend validation; then wire each team `Dashboard_View` with markers; and finally surface the Settings replay entry. Each step builds on the previous and ends with integration so no code is left orphaned. Test sub-tasks (marked `*`) cover the 11 correctness properties (fast-check, ≥100 iterations), Vitest unit tests, the backend PHPUnit `replayCount` validation test, and Playwright e2e flows.

## Tasks

- [ ] 1. Build foundational config and event bus
  - [x] 1.1 Create `dashboardOnboardingConfig.ts`
    - Create `frontend/src/features/dashboard/config/dashboardOnboardingConfig.ts`
    - Define `RoleKey`, `OnboardingStep`, `OnboardingCopy`, `RoleOnboardingConfig` types
    - Export `REPLAY_CAP = 3` and the `dashboardOnboardingConfig: Record<RoleKey, RoleOnboardingConfig>` map
    - Encode the canonical `onboardingKey`/`version`/`fallbackPrefix` per role; preserve the legacy `clientDashboardOnboarding` key, legacy `client-dashboard-onboarding` prefix, and the existing 5-step client tour verbatim
    - Define per-role `steps` (with `target` markers + `mobileTab`) and `copy` for photographer (3), salesRep (5), editing_manager (4), editor (5) per the Per-Role Step and Marker Plan
    - Export `getOnboardingConfig(roleKey)` accessor
    - _Requirements: 1.1, 1.2, 1.3, 3.1, 3.2, 5.1_

  - [ ]* 1.2 Write property test for role config mapping
    - **Property 1: Role config selects the canonical key and version**
    - fast-check, ≥100 iterations; assert `getOnboardingConfig(roleKey).onboardingKey`/`version` equal the canonical mapping for every `RoleKey`
    - **Validates: Requirements 1.1, 1.2, 1.3**

  - [ ]* 1.3 Write unit tests for config completeness
    - Vitest: every `RoleKey` has a config; each team role's `steps[].target` set is non-empty; client config preserves legacy `onboardingKey` and `fallbackPrefix`
    - _Requirements: 1.2, 3.2, 5.1_

  - [x] 1.4 Create role-aware `dashboardOnboardingEvents.ts`
    - Create `frontend/src/lib/dashboardOnboardingEvents.ts`
    - Export `DASHBOARD_ONBOARDING_STATE_EVENT`, `DASHBOARD_ONBOARDING_REPLAY_EVENT`, `DashboardOnboardingSidebarState`
    - Implement `emitDashboardOnboardingState`, `getDashboardOnboardingState`, `requestDashboardOnboardingReplay(roleKey)` with `{ roleKey }` event detail
    - _Requirements: 4.1, 4.2, 5.2_

  - [ ]* 1.5 Write unit tests for the event bus
    - Vitest: emit/get round-trips sidebar state; replay request dispatches event carrying the correct `roleKey`
    - _Requirements: 4.2, 5.2_

- [x] 2. Implement the generalized state hook
  - [x] 2.1 Create `useDashboardOnboarding(user, roleKey)`
    - Create `frontend/src/features/dashboard/hooks/useDashboardOnboarding.ts`
    - Generalize `useClientDashboardOnboarding`: replace hard-coded key/version/prefix with `getOnboardingConfig(roleKey)` lookups
    - Read active state from `metadata.preferences.{onboardingKey}`; merge `LocalStorage_Fallback` over profile state (fallback wins)
    - Add `replayCount` to `DashboardOnboardingState`; normalize via `clamp(state.replayCount ?? 0, 0, 100)`
    - Derive `atReplayCap`, `shouldShowReplay = eligible && !atReplayCap`, `shouldShowSettingsReplay = eligible && atReplayCap`, and `welcomeOpen`
    - Implement `replaySessionRef` tracking; `complete()` increments `replayCount` only when the finished tour was a replay and `replayCount < REPLAY_CAP`
    - Implement `persistState()` always carrying `replayCount` under `preferences.{onboardingKey}` and writing localStorage `{fallbackPrefix}:{userId}`; skip `PUT /api/profile` when no token, swallow `PUT` errors optimistically
    - Emit sidebar state via `emitDashboardOnboardingState`; subscribe to replay-requested events for the matching `roleKey`
    - _Requirements: 1.4, 1.5, 2.1, 2.2, 2.3, 2.4, 3.4, 4.1, 4.3, 5.2, 5.5, 6.1, 6.2, 6.3, 8.2, 8.3, 8.4, 9.1, 9.2_

  - [ ]* 2.2 Write unit tests for the hook
    - Vitest + RTL: merge order, eligibility gating, `welcomeOpen` derivation, persisted body shape, account-switch reset
    - _Requirements: 1.4, 2.1, 2.4, 6.3_

  - [ ]* 2.3 Write property test for active-state merge
    - **Property 2: Active state reads the role key and merges fallback over profile**
    - fast-check, ≥100 iterations; mock `fetch`/`localStorage`
    - **Validates: Requirements 1.4, 8.2**

  - [ ]* 2.4 Write property test for welcome-dialog gating
    - **Property 3: Welcome dialog opens exactly when eligible and untouched**
    - fast-check, ≥100 iterations
    - **Validates: Requirements 2.1, 2.4, 9.2**

  - [ ]* 2.5 Write property test for step-navigation bounds
    - **Property 4: Step navigation stays in bounds and persists the active index**
    - fast-check, ≥100 iterations over random next/back sequences
    - **Validates: Requirements 3.4**

  - [ ]* 2.6 Write property test for replay-button visibility
    - **Property 6: Replay button visibility tracks eligibility and the cap**
    - fast-check, ≥100 iterations
    - **Validates: Requirements 4.1, 5.2, 6.3, 9.2**

  - [ ]* 2.7 Write property test for settings-replay visibility
    - **Property 7: Settings replay entry visibility is the cap-saturated complement**
    - fast-check, ≥100 iterations
    - **Validates: Requirements 5.3, 6.3**

  - [ ]* 2.8 Write property test for replay-completion increment
    - **Property 8: Replay completion increments by one only below the cap**
    - fast-check, ≥100 iterations over random starting `replayCount` and replay/first-run flags
    - **Validates: Requirements 4.3, 5.5**

  - [ ]* 2.9 Write property test for replay-count normalization
    - **Property 9: Replay count normalizes missing and out-of-range values**
    - fast-check, ≥100 iterations; assert effective count equals `clamp(state.replayCount ?? 0, 0, 100)`
    - **Validates: Requirements 6.2, 6.3**

  - [ ]* 2.10 Write property test for persistence payload
    - **Property 10: Persistence always carries replayCount under the role key**
    - fast-check, ≥100 iterations; assert `PUT` body and localStorage write both carry `replayCount` under `preferences.{onboardingKey}` keyed by `{fallbackPrefix}:{userId}`
    - **Validates: Requirements 6.1, 8.3**

  - [ ]* 2.11 Write property test for the no-token path
    - **Property 11: No token persists locally and skips the network request**
    - fast-check, ≥100 iterations; assert localStorage write occurs and no `PUT /api/profile` is issued
    - **Validates: Requirements 8.4**

- [x] 3. Implement the generalized tour component
  - [x] 3.1 Create `DashboardOnboarding.tsx`
    - Create `frontend/src/features/dashboard/components/DashboardOnboarding.tsx`
    - Parameterize `steps`, `copy`, and `roleKey` via props; carry over spotlight/overlay logic, `getTargetRect`, and card positioning unchanged
    - Derive Robbie help context from `roleKey` (`page: ${roleKey}_dashboard`, `source: ${roleKey}_dashboard_onboarding`)
    - When `getTargetRect` returns `null`, render the instructional card without the spotlight rectangle
    - Wire `onStart`/`onDismiss`/`onComplete`/`onProgress`/`onReplay` callbacks
    - _Requirements: 2.2, 2.3, 3.1, 3.3, 3.5, 9.3_

  - [ ]* 3.2 Write unit tests for the component
    - Vitest + RTL: welcome opens on eligible-fresh state; start/skip/complete callbacks fire; missing-marker renders card without spotlight
    - _Requirements: 2.2, 2.3, 3.3, 9.3_

- [x] 4. Refactor legacy client modules into backward-compatible wrappers
  - [x] 4.1 Refactor `useClientDashboardOnboarding.ts` to a wrapper
    - Delegate to `useDashboardOnboarding(user, "client")`
    - Re-export `CLIENT_DASHBOARD_ONBOARDING_VERSION` from config and preserve `ClientDashboardOnboardingState` as a type alias
    - _Requirements: 8.1_

  - [x] 4.2 Refactor `ClientDashboardOnboarding.tsx` to a wrapper
    - Render `<DashboardOnboarding>` with `dashboardOnboardingConfig.client` steps/copy and `roleKey="client"`
    - Preserve the existing props surface so `ClientDashboardView` compiles unchanged
    - _Requirements: 8.1_

  - [x] 4.3 Refactor `clientDashboardOnboardingEvents.ts` to re-export the generalized bus
    - Re-export the generalized symbols; keep `CLIENT_DASHBOARD_ONBOARDING_STATE_EVENT`/`CLIENT_DASHBOARD_ONBOARDING_REPLAY_EVENT` as aliases to the same event names
    - _Requirements: 8.1_

- [x] 5. Checkpoint - core + wrappers
  - Ensure all tests pass, ask the user if questions arise.

- [x] 6. Update sidebar, layout, and backend validation
  - [x] 6.1 Update `SidebarFooter.tsx`
    - Subscribe to `DASHBOARD_ONBOARDING_STATE_EVENT`; render the `Replay_Button` whenever the latest emitted state has `visible === true`, using the emitted `label`
    - Selecting it calls `requestDashboardOnboardingReplay(roleKey)`; button is hidden at cap and for ineligible users
    - _Requirements: 4.1, 4.2, 5.2, 9.2_

  - [x] 6.2 Extend `RoleDashboardLayout.tsx` with onboarding-target props
    - Add optional `metricsOnboardingTarget`, `upcomingOnboardingTarget`, `pendingOnboardingTarget`, `leftColumnOnboardingTarget` props
    - Apply each as `data-onboarding-target` on the wrapping `div` of the corresponding section when provided
    - Support controlled mobile-tab mode (`mobileTab`/`onMobileTabChange`) for tour-driven tab focus
    - _Requirements: 3.2, 3.3_

  - [x] 6.3 Add `replayCount` validation in `AuthController.php`
    - In `updateProfile`, inside the existing `foreach ($onboardingKeys as $onboardingKey)` loop, add the rule `preferences.{$onboardingKey}.replayCount => 'nullable|integer|min:0|max:100'`
    - Applies uniformly to all five onboarding keys so the field is persisted, not stripped
    - _Requirements: 7.1, 7.2, 7.3, 7.4_

  - [ ]* 6.4 Write PHPUnit test for `replayCount` validation
    - `AuthControllerTest`: valid `replayCount` persists for each of the five keys; out-of-range/non-integer rejected with 422
    - _Requirements: 7.1, 7.2, 7.3, 7.4_

- [x] 7. Wire each team Dashboard_View
  - [x] 7.1 Wire `PhotographerDashboardView.tsx`
    - Call `useDashboardOnboarding(user, "photographer")`; render `<DashboardOnboarding>` with photographer steps/copy
    - Add markers: `photographer-upcoming-shoots` (layout `upcomingOnboardingTarget`), `photographer-requests` (`requests-queue` div), `photographer-completed` (`rightColumnCards[0]` wrapper); use controlled mobile tabs
    - _Requirements: 3.1, 3.2, 3.3, 4.1_

  - [x] 7.2 Wire `SalesDashboardView.tsx`
    - Call `useDashboardOnboarding(user, "salesRep")`; render `<DashboardOnboarding>` with salesRep steps/copy
    - Add markers: `salesrep-metrics` (layout `metricsOnboardingTarget`), `salesrep-assign` (`assign-card` div), `salesrep-upcoming` (layout `upcomingOnboardingTarget`), `salesrep-requests` (`requests-queue` div), `salesrep-delivered` (`rightColumnCards[0]` wrapper)
    - _Requirements: 3.1, 3.2, 3.3, 4.1_

  - [x] 7.3 Wire `EditingManagerDashboardView.tsx`
    - Call `useDashboardOnboarding(user, "editing_manager")`; render `<DashboardOnboarding>` with editing_manager steps/copy
    - Add markers on the custom layout wrappers: `editingmanager-shoots`, `editingmanager-requests`, `editingmanager-ready`, `editingmanager-pipeline`
    - _Requirements: 3.1, 3.2, 3.3, 4.1_

  - [x] 7.4 Wire `EditorDashboardView.tsx`
    - Call `useDashboardOnboarding(user, "editor")`; render `<DashboardOnboarding>` with editor steps/copy
    - Add markers: `editor-metrics` (layout `metricsOnboardingTarget`), `editor-raw-links` (`editor-raw-links-card` div), `editor-queue` (layout `upcomingOnboardingTarget`), `editor-requests` (`requests-queue` div), `editor-delivered` (`rightColumnCards[0]` wrapper); use controlled mobile tabs
    - _Requirements: 3.1, 3.2, 3.3, 4.1_

  - [ ]* 7.5 Write property test for step-marker coverage across views
    - **Property 5: Every step has a marker in its role's view**
    - fast-check, ≥100 iterations; for each team `RoleKey`, render the `Dashboard_View` and assert every configured `target` is present as a `data-onboarding-target` marker
    - **Validates: Requirements 3.1, 3.2**

- [x] 8. Surface the Settings replay entry
  - [x] 8.1 Add `Settings_Replay_Entry` to `Settings.tsx`
    - Resolve role/onboarding block from `useAuth()` user via `getOnboardingConfig`; render the entry only when `shouldShowSettingsReplay` (`eligible && replayCount >= REPLAY_CAP`)
    - Selecting it calls `requestDashboardOnboardingReplay(roleKey)` and navigates to the dashboard
    - _Requirements: 5.3, 5.4_

- [ ] 9. End-to-end coverage
  - [ ]* 9.1 Write Playwright per-role tour e2e
    - For each team role: welcome auto-opens for eligible-fresh user; start runs tour; each step spotlights the correct marker; finishing persists `completedAt`
    - _Requirements: 2.1, 2.2, 3.1, 3.3, 3.5_

  - [ ]* 9.2 Write Playwright replay-cap relocation e2e
    - Replay three times → `Replay_Button` disappears from sidebar and `Settings_Replay_Entry` appears; replaying from Settings does not increase the count further
    - _Requirements: 5.2, 5.3, 5.4, 5.5_

  - [ ]* 9.3 Write Playwright client backward-compatibility e2e
    - Existing client tour flow (welcome → start → steps → complete → replay) still works end to end
    - _Requirements: 8.1_

- [x] 10. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional test sub-tasks and can be skipped for a faster MVP.
- Each task references specific requirements for traceability; property tests cite their design property number.
- The "generalize-then-wrap" order guarantees a single maintained code path while preserving the legacy client experience (wrappers in task 4).
- Property tests target the pure hook logic (merge, cap arithmetic, eligibility, persistence shape) with `fetch`/`localStorage` mocked; Playwright covers DOM/spotlight wiring and cross-component integration.
- Files touched by multiple concerns (hook, component, events, each view, sidebar, layout, settings, backend) are distinct, and the dependency graph schedules dependents in later waves than their dependencies.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.4"] },
    { "id": 1, "tasks": ["1.2", "1.3", "1.5", "2.1", "3.1"] },
    { "id": 2, "tasks": ["2.2", "2.3", "2.4", "2.5", "2.6", "2.7", "2.8", "2.9", "2.10", "2.11", "3.2", "4.1", "4.2", "4.3"] },
    { "id": 3, "tasks": ["6.1", "6.2", "6.3"] },
    { "id": 4, "tasks": ["6.4", "7.1", "7.2", "7.3", "7.4"] },
    { "id": 5, "tasks": ["7.5", "8.1"] },
    { "id": 6, "tasks": ["9.1", "9.2", "9.3"] }
  ]
}
```
