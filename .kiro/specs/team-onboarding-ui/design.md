# Design Document

## Overview

This feature generalizes the existing client onboarding tour into a single, role-aware subsystem that serves all five roles (`client`, `photographer`, `salesRep`, `editing_manager`, `editor`). The current implementation is hard-wired to the client role across three files:

- `useClientDashboardOnboarding.ts` — state hook (profile + localStorage merge, `PUT /api/profile`, lifecycle actions).
- `ClientDashboardOnboarding.tsx` — welcome dialog + spotlight tour component with a hard-coded `steps` array.
- `clientDashboardOnboardingEvents.ts` — a window event bus that lets the sidebar show a "Take tour" button and request a replay.

The design extracts the behavior into a **config-driven** core (`useDashboardOnboarding`, `DashboardOnboarding`, `dashboardOnboardingEvents`, and a `dashboardOnboardingConfig` map keyed by `Role_Key`). The legacy client modules become thin wrappers over the generalized core, preserving every existing import, the legacy `clientDashboardOnboarding` preference key, the legacy localStorage key, and the established client experience.

Two new behaviors are layered on top for all five roles:

1. A persisted `replayCount` field inside each role's `Onboarding_State`.
2. A uniform **replay cap** of 3: while `replayCount < 3` the prominent sidebar `Replay_Button` is shown; once `replayCount >= 3` the button is hidden and the replay action relocates to a `Settings_Replay_Entry` on the Settings page.

The backend `PUT /api/profile` validation loop (which already iterates the five onboarding keys) is extended with one rule so `replayCount` is accepted rather than stripped.

### Language and Conventions

- Frontend: TypeScript + React (functional components, hooks), matching existing conventions in `frontend/src` (path alias `@/`, `lucide-react` icons, shadcn `ui` primitives).
- Backend: PHP / Laravel, matching `AuthController::updateProfile` validation conventions.

## Architecture

### Module map (after refactor)

```
frontend/src/features/dashboard/
  config/
    dashboardOnboardingConfig.ts        # NEW: Role_Key -> { onboardingKey, version, steps, copy, fallbackPrefix }
  hooks/
    useDashboardOnboarding.ts           # NEW: generalized hook (core logic + replayCount + replay cap)
    useClientDashboardOnboarding.ts     # REFACTORED: thin wrapper -> useDashboardOnboarding(user, 'client')
  components/
    DashboardOnboarding.tsx             # NEW: generalized welcome dialog + spotlight tour (steps/copy params)
    ClientDashboardOnboarding.tsx       # REFACTORED: thin wrapper -> <DashboardOnboarding .../> with client config
  views/
    ClientDashboardView.tsx             # unchanged wiring (uses client wrapper) — backward compatible
    PhotographerDashboardView.tsx       # WIRED: useDashboardOnboarding('photographer') + markers
    SalesDashboardView.tsx              # WIRED: useDashboardOnboarding('salesRep') + markers
    EditingManagerDashboardView.tsx     # WIRED: useDashboardOnboarding('editing_manager') + markers
    EditorDashboardView.tsx             # WIRED: useDashboardOnboarding('editor') + markers
  components/
    RoleDashboardLayout.tsx             # EXTENDED: optional onboarding-target props for internally rendered sections
frontend/src/lib/
  dashboardOnboardingEvents.ts          # NEW: role-aware sidebar state + replay-request bus
  clientDashboardOnboardingEvents.ts    # REFACTORED: re-exports generalized bus (legacy constants preserved)
frontend/src/components/layout/sidebar/
  SidebarFooter.tsx                     # UPDATED: listens to role-aware bus; shows Replay_Button for any eligible role
frontend/src/pages/
  Settings.tsx                          # UPDATED: renders Settings_Replay_Entry when replayCount >= cap & eligible
backend/app/Http/Controllers/API/
  AuthController.php                     # UPDATED: add replayCount rule inside the onboarding keys loop
```

### Data flow

```
                       metadata.preferences.{onboardingKey}        localStorage[{prefix}:{userId}]
                                   │                                          │
                                   └───────────────┬──────────────────────────┘
                                                   ▼  (fallback merged OVER profile)
   Dashboard.tsx (role) ──roleKey──► useDashboardOnboarding(user, roleKey) ──► Onboarding_State (+replayCount)
                                                   │
            ┌──────────────────────────┬──────────┴───────────┬───────────────────────┐
            ▼                          ▼                      ▼                       ▼
   <DashboardOnboarding/>      persistState()         emitDashboardOnboardingState   replay()/complete()
   (welcome + spotlight)       PUT /api/profile        (sidebar visibility)          (cap enforcement)
                                                   │
                                                   ▼
                            SidebarFooter (Replay_Button)  +  Settings (Settings_Replay_Entry)
```

## Migration / Refactor Strategy

The chosen strategy is **generalize-then-wrap**: build the generalized core and reduce the legacy client modules to thin wrappers. This is preferred over leaving the client implementation untouched because it guarantees a single maintained code path (Requirement 1) while bounding regression risk for the client (Requirement 8).

### 1. `dashboardOnboardingConfig.ts` (new, source of truth)

Holds one entry per `Role_Key`. The client entry encodes the **legacy** onboarding key, the legacy localStorage prefix, and the existing 5-step client tour verbatim, so client behavior is byte-for-byte preserved.

### 2. `useDashboardOnboarding(user, roleKey)` (new core hook)

A direct generalization of `useClientDashboardOnboarding`:
- The hard-coded `clientDashboardOnboarding` key, `CLIENT_DASHBOARD_ONBOARDING_VERSION`, and localStorage prefix become lookups into `dashboardOnboardingConfig[roleKey]`.
- Adds `replayCount` to the state type, replay-cap evaluation, and replay-completion increment logic.

### 3. `useClientDashboardOnboarding(user)` (refactored to wrapper)

```ts
export const CLIENT_DASHBOARD_ONBOARDING_VERSION =
  dashboardOnboardingConfig.client.version; // re-export preserved
export type ClientDashboardOnboardingState = DashboardOnboardingState; // alias preserved

export const useClientDashboardOnboarding = (user: UserLike) =>
  useDashboardOnboarding(user, "client");
```

All existing call sites (e.g. `ClientDashboardView`) keep working unchanged.

### 4. `DashboardOnboarding.tsx` (new) + `ClientDashboardOnboarding.tsx` (wrapper)

`DashboardOnboarding` is `ClientDashboardOnboarding` with `steps`, the welcome copy, and the Robbie context derived from props instead of module constants. The client component becomes:

```tsx
export const ClientDashboardOnboarding: React.FC<ClientDashboardOnboardingProps> = (props) => (
  <DashboardOnboarding
    {...props}
    steps={dashboardOnboardingConfig.client.steps}
    copy={dashboardOnboardingConfig.client.copy}
    roleKey="client"
  />
);
```

### 5. Events module

`dashboardOnboardingEvents.ts` generalizes the bus to carry a `roleKey`. `clientDashboardOnboardingEvents.ts` re-exports the new symbols and keeps the original constant names as aliases, so `SidebarFooter` and `ClientDashboardView` continue to compile while they are migrated.

## Components and Interfaces

### Role configuration

```ts
// dashboardOnboardingConfig.ts
export type RoleKey = "client" | "photographer" | "salesRep" | "editing_manager" | "editor";

export type OnboardingStep = {
  title: string;
  description: string;
  target: string;          // matches a data-onboarding-target value
  mobileTab?: string;      // role-specific mobile tab id (best-effort focus)
};

export type OnboardingCopy = {
  welcomeTitle: string;
  welcomeDescription: string;
  checklistItems: string[];
  replayLabel: string;     // sidebar/settings button label, e.g. "Take tour"
};

export type RoleOnboardingConfig = {
  roleKey: RoleKey;
  onboardingKey: string;       // metadata.preferences.{onboardingKey}
  version: number;
  fallbackPrefix: string;      // localStorage key prefix
  steps: OnboardingStep[];
  copy: OnboardingCopy;
};

export const REPLAY_CAP = 3;

export const dashboardOnboardingConfig: Record<RoleKey, RoleOnboardingConfig>;

export const getOnboardingConfig: (roleKey: RoleKey) => RoleOnboardingConfig;
```

`onboardingKey` mapping (mirrors backend `DashboardOnboardingService::ROLE_MAP`):

| Role_Key | onboardingKey | fallbackPrefix |
| --- | --- | --- |
| `client` | `clientDashboardOnboarding` | `client-dashboard-onboarding` (legacy, preserved) |
| `photographer` | `photographerDashboardOnboarding` | `photographer-dashboard-onboarding` |
| `salesRep` | `salesRepDashboardOnboarding` | `salesRep-dashboard-onboarding` |
| `editing_manager` | `editingManagerDashboardOnboarding` | `editingManager-dashboard-onboarding` |
| `editor` | `editorDashboardOnboarding` | `editor-dashboard-onboarding` |

### Generalized state hook

```ts
// useDashboardOnboarding.ts
export type DashboardOnboardingState = {
  eligible?: boolean;
  version?: number;
  createdAt?: string;
  startedAt?: string;
  completedAt?: string;
  dismissedAt?: string;
  lastStep?: number;
  source?: string;
  replayCount?: number;     // NEW (Requirement 1.5)
};

type UserLike = {
  id?: string | number | null;
  metadata?: { preferences?: Record<string, DashboardOnboardingState | undefined> } | null;
} | null;

export type UseDashboardOnboarding = {
  onboardingState: DashboardOnboardingState;
  replayCount: number;          // normalized (missing -> 0)
  atReplayCap: boolean;         // replayCount >= REPLAY_CAP
  shouldShowReplay: boolean;    // eligible && !atReplayCap
  shouldShowSettingsReplay: boolean; // eligible && atReplayCap
  welcomeOpen: boolean;
  tourOpen: boolean;
  startTour: () => Promise<void>;
  dismiss: () => Promise<void>;
  complete: (options?: { lastStep?: number }) => Promise<void>;
  saveProgress: (lastStep: number) => Promise<void>;
  replay: () => void;
  setTourOpen: (open: boolean) => void;
  setWelcomeOpen: (open: boolean) => void;
};

export const useDashboardOnboarding: (user: UserLike, roleKey: RoleKey) => UseDashboardOnboarding;
```

Key behavioral additions versus the legacy hook:

- **State source**: reads `user.metadata.preferences[config.onboardingKey]`; merges `LocalStorage_Fallback` over the profile state (unchanged merge order).
- **replayCount normalization**: `replayCount = clamp(state.replayCount ?? 0, 0, 100)`.
- **Replay session tracking**: an internal `replaySessionRef` is set `true` by `replay()` (and by the Settings entry path) and `false` when a tour is started fresh from the welcome dialog or after completion.
- **complete()**: persists `completedAt`. If the just-finished tour was a replay session **and** `replayCount < REPLAY_CAP`, it increments `replayCount` by 1 in the same persisted patch; otherwise `replayCount` is unchanged. First-run completion never increments (replay session flag is false).
- **persistState()**: always includes `replayCount` in the `preferences[onboardingKey]` body and in the localStorage write. No token → localStorage only, skip `PUT`.

```ts
// completion increment (pseudocode)
const complete = async (options = {}) => {
  setWelcomeOpen(false);
  setTourOpen(false);
  const wasReplay = replaySessionRef.current;
  replaySessionRef.current = false;
  const current = clamp(localState.replayCount ?? 0, 0, 100);
  const nextReplayCount =
    wasReplay && current < REPLAY_CAP ? current + 1 : current; // Req 4.3, 5.5
  await persistState({ completedAt: new Date().toISOString(), lastStep: options.lastStep, replayCount: nextReplayCount });
};
```

### Generalized tour component

```tsx
// DashboardOnboarding.tsx
interface DashboardOnboardingProps {
  roleKey: RoleKey;
  steps: OnboardingStep[];
  copy: OnboardingCopy;
  welcomeOpen: boolean;
  tourOpen: boolean;
  isMobile: boolean;
  currentMobileTab?: string;
  lastStep?: number;
  showReplay: boolean;                 // sidebar replay handled separately; this is the inline replay
  onStart: () => void;
  onDismiss: () => void;
  onComplete: (lastStep: number) => void;
  onProgress: (lastStep: number) => void;
  onReplay: () => void;
  onSetMobileTab?: (tab: string) => void;
}

export const DashboardOnboarding: React.FC<DashboardOnboardingProps>;
```

The spotlight/overlay logic, `getTargetRect`, and card positioning are carried over unchanged. The only differences: `steps`/copy come from props; the Robbie help context uses `roleKey` (`page: \`${roleKey}_dashboard\``, `source: \`${roleKey}_dashboard_onboarding\``). When `getTargetRect` returns `null` (marker absent), the spotlight rectangle is not rendered but the instructional card still renders (Requirement 9.3 — already the existing behavior, made explicit).

### Role-aware events bus

```ts
// dashboardOnboardingEvents.ts
export const DASHBOARD_ONBOARDING_STATE_EVENT = "dashboard-onboarding-state";
export const DASHBOARD_ONBOARDING_REPLAY_EVENT = "dashboard-onboarding-replay-requested";

export type DashboardOnboardingSidebarState = {
  roleKey: RoleKey;
  visible: boolean;      // show sidebar Replay_Button (eligible && !atReplayCap && dialogs closed)
  label: string;         // copy.replayLabel
};

export const emitDashboardOnboardingState: (state: DashboardOnboardingSidebarState) => void;
export const getDashboardOnboardingState: () => DashboardOnboardingSidebarState | null;
export const requestDashboardOnboardingReplay: (roleKey: RoleKey) => void;
```

The replay-request event detail carries `{ roleKey }` so the active dashboard view can ignore events for other roles. `clientDashboardOnboardingEvents.ts` re-exports these and keeps `CLIENT_DASHBOARD_ONBOARDING_STATE_EVENT` / `CLIENT_DASHBOARD_ONBOARDING_REPLAY_EVENT` as aliases pointing at the same event names for backward compatibility.

### SidebarFooter

`SidebarFooter` subscribes to `DASHBOARD_ONBOARDING_STATE_EVENT` and renders the `Replay_Button` whenever the latest emitted state has `visible === true`, using the emitted `label`. Selecting it calls `requestDashboardOnboardingReplay(roleKey)`. Because visibility is computed by the hook (`shouldShowReplay = eligible && !atReplayCap`), the button is automatically hidden at the cap (Requirement 5.2) and for ineligible users (Requirement 9.2).

### Settings_Replay_Entry

A new card/row in `Settings.tsx`, rendered only when `shouldShowSettingsReplay` is true for the current user/role (`eligible && replayCount >= REPLAY_CAP`). Selecting it calls `requestDashboardOnboardingReplay(roleKey)` and navigates to the dashboard (so the spotlight has targets to anchor to). Settings reads the role/onboarding block from the authenticated `user` via `useAuth()`; the role→onboardingKey resolution reuses `getOnboardingConfig`.

### RoleDashboardLayout extension

To anchor markers on sections that `RoleDashboardLayout` renders internally (metric tiles, upcoming shoots, pending card), the layout gains optional target props:

```ts
// added to RoleDashboardLayoutProps
metricsOnboardingTarget?: string;
upcomingOnboardingTarget?: string;
pendingOnboardingTarget?: string;
leftColumnOnboardingTarget?: string;
```

Each, when provided, is applied as `data-onboarding-target` on the wrapping `div` of the corresponding section. Cards that the view itself owns (e.g. `requests-queue`, `assign-card`, `editor-raw-links-card`, `rightColumnCards`) get markers wrapped directly in the view. For controlled mobile tab focus during the tour, `photographer` and `editor` views switch `RoleDashboardLayout` to controlled mode (`mobileTab` / `onMobileTabChange`) so the tour can drive the active tab.

## Per-Role Step and Marker Plan

Each team `Dashboard_View` includes a marker for every step of its role (Requirement 3.2). The `client` role is unchanged.

### photographer (3 steps)

| # | target marker | anchor location | mobileTab |
| --- | --- | --- | --- |
| 1 | `photographer-upcoming-shoots` | RoleDashboardLayout `upcomingOnboardingTarget` | `shoots` |
| 2 | `photographer-requests` | view's `requests-queue` div (`pendingCard`) | `requests` |
| 3 | `photographer-completed` | `rightColumnCards[0]` wrapper (completed shoots) | `completed` |

### salesRep (5 steps)

| # | target marker | anchor location | mobileTab |
| --- | --- | --- | --- |
| 1 | `salesrep-metrics` | RoleDashboardLayout `metricsOnboardingTarget` | — |
| 2 | `salesrep-assign` | view's `assign-card` div (`leftColumnCard`) | — |
| 3 | `salesrep-upcoming` | RoleDashboardLayout `upcomingOnboardingTarget` | — |
| 4 | `salesrep-requests` | view's `requests-queue` div | — |
| 5 | `salesrep-delivered` | `rightColumnCards[0]` wrapper (delivered) | — |

### editing_manager (4 steps; custom layout, not RoleDashboardLayout)

| # | target marker | anchor location | mobileTab |
| --- | --- | --- | --- |
| 1 | `editingmanager-shoots` | wrapper around `renderEditingManagerShootsTabsCard()` | `shoots` |
| 2 | `editingmanager-requests` | wrapper around `renderPendingReviewsCard()` | `requests` |
| 3 | `editingmanager-ready` | wrapper around `renderEditingManagerReadyToDeliverCard()` | `ready` |
| 4 | `editingmanager-pipeline` | wrapper around `renderPipelineSection()` | `pipeline` |

### editor (5 steps)

| # | target marker | anchor location | mobileTab |
| --- | --- | --- | --- |
| 1 | `editor-metrics` | RoleDashboardLayout `metricsOnboardingTarget` | — |
| 2 | `editor-raw-links` | view's `editor-raw-links-card` div (`leftColumnCard`) | — |
| 3 | `editor-queue` | RoleDashboardLayout `upcomingOnboardingTarget` | `queue` |
| 4 | `editor-requests` | view's `requests-queue` div | `requests` |
| 5 | `editor-delivered` | `rightColumnCards[0]` wrapper (delivered edits) | `delivered` |

The exact copy strings live in `dashboardOnboardingConfig` and are out of scope for the data flow; each step's `title`/`description` describes the anchored card in the role's own language.

## Data Models

### Onboarding_State (per role, persisted)

```ts
type DashboardOnboardingState = {
  eligible?: boolean;     // gate for welcome dialog + replay button
  version?: number;       // role config version
  createdAt?: string;     // ISO timestamp
  startedAt?: string;     // ISO timestamp (set on start)
  completedAt?: string;   // ISO timestamp (set on finish)
  dismissedAt?: string;   // ISO timestamp (set on skip)
  lastStep?: number;      // 0-based step index
  source?: string;        // origin tag, e.g. "registration"
  replayCount?: number;   // NEW: 0..100, missing treated as 0
};
```

- **Storage (server)**: `users.metadata.preferences.{onboardingKey}`.
- **Storage (fallback)**: `localStorage["{fallbackPrefix}:{userId|anonymous}"]` holding the same JSON shape.
- **Active state**: `{ ...profileState, ...fallbackState }` (fallback wins) — Requirement 8.2.
- **Replay_Count derivation**: `clamp(state.replayCount ?? 0, 0, 100)` — Requirement 6.2.

### Backend validation (per onboarding key)

Existing rules plus one new rule inside the `foreach ($onboardingKeys as $onboardingKey)` loop:

```php
$onboardingRules["preferences.{$onboardingKey}.replayCount"] = 'nullable|integer|min:0|max:100';
```

This applies uniformly to all five keys (Requirement 7.1–7.4). No other backend change is required; the existing merge logic persists the whole validated `preferences` block.

## Error Handling

| Condition | Handling | Requirement |
| --- | --- | --- |
| `PUT /api/profile` fails or throws | Swallow error; localState + localStorage already updated (optimistic) | 8.3 |
| No auth token | Skip `PUT`; persist to localStorage only | 8.4 |
| `Onboarding_State` absent / not an object | Treat as `{}`; no welcome dialog, no tour, no replay button | 9.1 |
| `eligible` false or absent | Welcome stays closed; `shouldShowReplay`/`shouldShowSettingsReplay` false | 9.2 |
| Step marker not in DOM (`getTargetRect` null) | Render instructional card without spotlight rectangle | 9.3 |
| `replayCount` missing on read | Normalize to 0 | 6.2 |
| `replayCount` already at cap on completion | Do not increment | 5.5 |
| Backend receives `replayCount` out of range / non-integer | Reject request with validation error (state remains optimistic locally) | 7.4 |
| Account switch (user id change) | Reset welcome/tour open flags; recompute merged state | — |

## Testing Strategy

### Unit tests (Vitest + React Testing Library)

- `dashboardOnboardingConfig`: every `RoleKey` has a config; each team role's `steps[].target` set is non-empty; client config preserves the legacy `onboardingKey` and `fallbackPrefix`.
- `useDashboardOnboarding`: state merge order, `replayCount` normalization/clamping, eligibility gating of `shouldShowReplay`/`shouldShowSettingsReplay`, `welcomeOpen` derivation, no-token localStorage-only path, persisted body shape.
- `DashboardOnboarding`: welcome opens on eligible-fresh state; start/skip/complete callbacks fire; missing-marker renders card without spotlight.
- Backend `AuthControllerTest` (PHPUnit): `replayCount` within range persists for each of the five keys; out-of-range/non-integer rejected with 422.

### Property-based tests (fast-check, ≥100 iterations each)

Property tests target the pure logic of the hook (state merge, replay-cap arithmetic, eligibility). External I/O (`fetch`, `localStorage`) is mocked. Each property test is tagged `Feature: team-onboarding-ui, Property {n}: {text}` and references its design property.

### End-to-end tests (Playwright)

- Per team role: welcome dialog auto-opens for an eligible-fresh user; start runs the tour; each step spotlights the correct marker; finishing persists `completedAt`.
- Replay cap: replay three times → `Replay_Button` disappears from sidebar and `Settings_Replay_Entry` appears; replaying from Settings does not increase the count further.
- Backward compatibility: existing client tour flow still works end to end.

### Testing balance

Property tests cover universal hook logic (cap arithmetic, merge, eligibility). Example/unit tests cover specific lifecycle callbacks and edge cases. Playwright covers DOM/spotlight wiring and cross-component (sidebar/settings) integration, which is not suitable for property testing.

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system-essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Role config selects the canonical key and version

For any `Role_Key`, `getOnboardingConfig(roleKey)` returns a config whose `onboardingKey` and `version` equal the canonical mapping (`client` → `clientDashboardOnboarding`, `photographer` → `photographerDashboardOnboarding`, `salesRep` → `salesRepDashboardOnboarding`, `editing_manager` → `editingManagerDashboardOnboarding`, `editor` → `editorDashboardOnboarding`).

**Validates: Requirements 1.1, 1.2, 1.3**

### Property 2: Active state reads the role key and merges fallback over profile

For any `Role_Key`, any profile preferences object, and any localStorage fallback object, the hook's active `Onboarding_State` equals `{ ...profileState[onboardingKey], ...fallbackState }` — read from `metadata.preferences.{onboardingKey}` with the fallback taking precedence on conflicting fields.

**Validates: Requirements 1.4, 8.2**

### Property 3: Welcome dialog opens exactly when eligible and untouched

For any `Onboarding_State`, the welcome dialog open state on dashboard load equals `eligible === true && startedAt is absent && completedAt is absent && dismissedAt is absent`.

**Validates: Requirements 2.1, 2.4, 9.2**

### Property 4: Step navigation stays in bounds and persists the active index

For any sequence of next/back actions during a tour, the active step index always remains within `[0, steps.length - 1]`, and the value persisted as `lastStep` after each action equals the resulting active step index.

**Validates: Requirements 3.4**

### Property 5: Every step has a marker in its role's view

For any team `Role_Key`, every `target` referenced by that role's configured steps is present as a `data-onboarding-target` marker in the rendered `Dashboard_View` for that role.

**Validates: Requirements 3.1, 3.2**

### Property 6: Replay button visibility tracks eligibility and the cap

For any `Role_Key` and any `Onboarding_State`, `shouldShowReplay` is true if and only if `eligible === true && replayCount < REPLAY_CAP`.

**Validates: Requirements 4.1, 5.2, 6.3, 9.2**

### Property 7: Settings replay entry visibility is the cap-saturated complement

For any `Role_Key` and any `Onboarding_State`, `shouldShowSettingsReplay` is true if and only if `eligible === true && replayCount >= REPLAY_CAP`.

**Validates: Requirements 5.3, 6.3**

### Property 8: Replay completion increments by one only below the cap

For any starting `replayCount` value, completing a tour yields a persisted `replayCount` equal to `start + 1` when the completed tour was opened as a replay and `start < REPLAY_CAP`, and equal to `start` otherwise (first-run completion, or replay completion at/above the cap).

**Validates: Requirements 4.3, 5.5**

### Property 9: Replay count normalizes missing and out-of-range values

For any `Onboarding_State`, the effective `Replay_Count` equals `clamp(state.replayCount ?? 0, 0, 100)`; a missing `replayCount` yields 0.

**Validates: Requirements 6.2, 6.3**

### Property 10: Persistence always carries replayCount under the role key

For any `Role_Key` and any `Onboarding_State`, when the system persists, the `PUT /api/profile` request body and the localStorage write both contain the effective `replayCount` under `preferences.{onboardingKey}`, and the localStorage entry is keyed by `{fallbackPrefix}:{userId}`.

**Validates: Requirements 6.1, 8.3**

### Property 11: No token persists locally and skips the network request

For any `Onboarding_State`, when no authentication token is available, persistence writes the state to the localStorage fallback and does not issue the `PUT /api/profile` request.

**Validates: Requirements 8.4**
