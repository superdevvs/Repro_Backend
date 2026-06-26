# Design Document

## Overview

This feature adds an end-to-end browser QA capability for the photographer onboarding journey
by **extending the existing Playwright suite** at `frontend/e2e/`. It does not introduce a new
test runner, a new auth mechanism, or a new configuration scheme. New specs match the existing
`**/*.e2e.ts` glob, run in the single `chromium` project defined in `playwright.config.ts`, and
are executed via `npm run test:e2e` from `frontend/`. They authenticate through the shared
`frontend/e2e/helpers/auth.ts` helper and read the documented environment configuration from
`frontend/e2e/README.md`.

The suite is designed to run **primarily against production in a read-mostly manner**. Every
action that mutates persistent data (a `Destructive_Step`) or that can trigger a real charge or
message through a live external provider (Stripe, Square, Twilio, Telnyx, CubiCasa) is routed
through a single **`Confirmation_Gate`** abstraction; when a non-charging path exists, the suite
takes it. The suite **never blocks waiting for free-form human input**: when a required dependency
or `data-testid` is missing, the affected check is recorded as a `Blocked_Check` with the missing
dependency noted and all other checks continue. QA-created data is tagged with the `E2E_QA_RUN_ID`
suffix so every `QA_Entity` is identifiable and can be cleaned up run-scoped at the end of the run.
The suite produces a green/yellow/red, **evidence-backed `QA_Report`** (Markdown + JSON +
screenshots + trace + video-on-failure + console/network logs + API excerpts + created-entity IDs
+ cleanup status), and when a check fails because of a real defect the underlying Laravel/React
code is fixed and the failing check is re-run (report-and-fix), with the report recording the
latest verified state.

The normative **Onboarding QA Truth Table** (rows T1–T8 in `requirements.md`) is the canonical
source for the radius, availability, service-match, and CubiCasa rows; this design maps those rows
onto concrete harness behavior, spec modules, and correctness properties. A row is reported green
only when its "Green Only If" condition is satisfied with captured evidence.

The work is organized into two layers:

1. **A shared QA harness** (helpers/fixtures): multi-role contexts (up to 7 roles), the
   confirmation gate, the run-id data factory, the fixed-persona provisioner, the seeded-address
   provider, the notification-sink reader, the stable-selector resolver, the UI-consistency probe,
   the run-scoped entity tracker, and the evidence-backed report collector.
2. **Per-domain spec modules**, one per onboarding area, each consuming the harness and
   asserting the relevant requirements. Existing specs are reused as building blocks rather than
   duplicated.

### Technology and conventions

- **Language:** TypeScript (Playwright Test), consistent with the existing suite.
- **Browser:** chromium (single project), headless by default.
- **Auth:** `helpers/auth.ts` (`loginAsAdmin`, `loginAsEditor`, and the shared `login`). The bearer
  token is read from `localStorage` (`authToken` / `token`) for API-level assertions, matching the
  pattern already used in `account-delete-cache-access.e2e.ts` and `team-onboarding-admin-create.e2e.ts`.
- **API base resolution:** `E2E_API_BASE_URL ?? E2E_BASE_URL ?? <default>`, matching
  `qa-acceptance.e2e.ts`.
- **Server management:** governed by `E2E_NO_SERVER` / `E2E_BASE_URL` in `playwright.config.ts`
  (no change to the config branch logic).

### Reused existing specs (building blocks)

| Domain | Existing spec reused | Reuse intent |
| --- | --- | --- |
| Service area / distance | `service-area-assignment.e2e.ts` | filter → preview → commit flow; preview-persists-nothing pattern |
| CubiCasa | `cubicasa-manual-order.e2e.ts` | admin-gated manual order button + `E2E_CUBICASA_SHOOT_ID` |
| Account creation | `team-onboarding-admin-create.e2e.ts` | `POST /api/admin/users` admin-create + onboarding block assertions |
| Cleanup | `account-delete-cache-access.e2e.ts` | `DELETE /api/admin/users/{id}` + token revocation/cache eviction |
| Settings (status/convert) | `account-lock-convert.e2e.ts` | `PATCH .../status`, `.../convert-type` |
| Multi-role / test shoots | `qa-acceptance.e2e.ts` | admin API token mint, test-shoot simulator, editor dashboards |

## Architecture

```
frontend/e2e/
├── helpers/
│   ├── auth.ts                      # EXISTING — reused as-is
│   └── onboarding-qa/               # NEW shared QA harness
│       ├── env.ts                   # documented env resolution incl. notification + seed flags (Req 1.4/1.5, 17.1)
│       ├── contexts.ts              # up to 7 Role_Contexts (Req 15)
│       ├── personas.ts              # fixed persona definitions (Req 3)
│       ├── test-data.ts             # run-id provisioning of the exact persona set + QA_Entity tagging (Req 3, 1.7)
│       ├── seeded-address.ts        # Seeded_Address provider w/ fixed lat/long + boundary fixtures (Req 8.2)
│       ├── confirmation-gate.ts     # Confirmation_Gate (Req 2, 18.11, 21.2)
│       ├── data-factory.ts          # QA_Run_Id suffixing factory (Req 1.7)
│       ├── selectors.ts             # stable data-testid resolver + Blocked_Check on missing (Req 13)
│       ├── notification-sink.ts     # Notification_Sink reader/asserter (Req 17)
│       ├── ui-probe.ts              # UI-consistency signal probe across 4 viewports (Req 14)
│       ├── entity-tracker.ts        # run-scoped QA_Entity tracker across ALL types (Req 21)
│       ├── report.ts                # evidence-backed QA_Report collector (Req 22)
│       └── backend-fixtures.ts      # wrappers over seed/admin artisan commands
└── onboarding/                      # NEW per-domain spec modules (**/*.e2e.ts)
    ├── cubicasa.e2e.ts              # Req 4
    ├── account-creation.e2e.ts     # Req 5
    ├── approval-workflow.e2e.ts    # Req 6
    ├── profile-completeness.e2e.ts # Req 7
    ├── service-radius.e2e.ts       # Req 8 (+ Truth Table T1, T2, T5, T6)
    ├── calendar-availability.e2e.ts# Req 9 (+ Truth Table T3, T4)
    ├── admin-override.e2e.ts       # Req 10
    ├── booking-lifecycle.e2e.ts    # Req 11
    ├── shoot-workflow.e2e.ts       # Req 12
    ├── selectors.e2e.ts            # Req 13
    ├── ui-consistency.e2e.ts       # Req 14
    ├── multi-role.e2e.ts           # Req 15
    ├── negative-permissions.e2e.ts # Req 16
    ├── notifications.e2e.ts        # Req 17
    ├── invoicing-reporting.e2e.ts  # Req 18
    ├── equipment.e2e.ts            # Req 19
    ├── settings.e2e.ts             # Req 20
    └── cleanup.e2e.ts              # Req 21 (runs last)
```

The harness is the only place that talks to the confirmation gate, the report collector, the
run-id factory, the persona provisioner, the seeded-address provider, the notification sink, the
selector resolver, the UI probe, and the entity tracker. Spec modules depend on the harness, never
on each other, so they remain independently runnable (consistent with `fullyParallel: true`, though
`cleanup.e2e.ts` is ordered last so it observes every entity the run created).

### Data and control flow

```
                 ┌─────────────────────────────────────────────────────────┐
                 │                  QA harness (fixtures)                    │
 env vars ─────► │  env.ts → data-factory.ts (run-id suffix)                 │
                 │  test-data.ts → personas.ts (admin.qa, client.qa, A/B/C,  │
                 │     photo/video editor, editing mgr, optional sales rep)  │
                 │  contexts.ts → {admin, photographer, client, photoEditor, │
                 │     videoEditor, editingManager, salesRep?}               │
                 │  seeded-address.ts → inside/boundary/outside fixtures     │
                 │  selectors.ts → byTestId() | record Blocked_Check         │
                 │  notification-sink.ts → readRecords()                     │
                 │  ui-probe.ts → signals × 4 viewports                      │
                 │  entity-tracker.ts → track(type, id) for cleanup          │
                 │  confirmation-gate.ts ── gate(step) ──┐                   │
                 │  report.ts ── record(check, result, evidence)             │
                 └───────────────────────────────────────┼──────────────────┘
                                                          │
   spec module check ──► read-only assertion ─────────────┘ (always runs)
                    └──► guarded action ──► gate.confirm? ──► non-charging path? ──► execute
                                              │ declined → skip + record "skipped"
                                              │ missing data/selector → record "blocked" + continue
                                              ▼
                                       Onboarding_System (Laravel API + React UI)
                                       providers: Stripe/Square/Twilio/Telnyx/CubiCasa
                                       notifications → Notification_Sink (log/disabled modes)
```

## Components and Interfaces

### 1. Environment resolution (`env.ts`)

Centralizes the documented variables (Req 1.4), the notification-sink variables (Req 1.5, 17.1),
the managed-server toggle (Req 1.6), and the gate/seed flags. Values and defaults match
`README.md` exactly so behavior is unchanged from the existing suite.

```typescript
export type NotificationMode = 'log' | 'live';
export type VoiceMode = 'disabled' | 'live';

export interface QaEnv {
  baseUrl: string;            // E2E_BASE_URL ?? http://localhost:5173
  apiBaseUrl: string;         // E2E_API_BASE_URL ?? E2E_BASE_URL ?? default
  noServer: boolean;          // E2E_NO_SERVER === '1'
  adminEmail: string;         // E2E_ADMIN_EMAIL
  adminPassword: string;      // E2E_ADMIN_PASSWORD
  previewStorageState?: string; // E2E_PREVIEW_STORAGE_STATE
  runId: string;              // E2E_QA_RUN_ID ?? timestamp
  externalBookingApiKey?: string; // E2E_EXTERNAL_BOOKING_API_KEY

  // Notification sink (Req 1.5, 17.1)
  notificationMode: NotificationMode; // E2E_NOTIFICATION_MODE ?? 'log'
  emailMode: NotificationMode;        // E2E_EMAIL_MODE ?? 'log'
  smsMode: NotificationMode;          // E2E_SMS_MODE ?? 'log'
  voiceMode: VoiceMode;               // E2E_VOICE_MODE ?? 'disabled'

  // Confirmation gate allow-flags (Req 2), default declined → read-only
  confirmDestructive: boolean; // E2E_CONFIRM_DESTRUCTIVE === '1'
  confirmCharge: boolean;      // E2E_CONFIRM_CHARGE === '1'
  confirmMessage: boolean;     // E2E_CONFIRM_MESSAGE === '1'

  // Optional category-scoped confirm + seeded-address pin
  confirmCategories?: string[]; // E2E_CONFIRM_CATEGORIES (comma list)
  seededAddressSet?: string;    // E2E_SEEDED_ADDRESS_SET (fixture id)
}

export function resolveQaEnv(): QaEnv;
```

### 2. Run-id data factory (`data-factory.ts`)

Produces identifiable QA data by appending the run-id suffix to every generated name, email, and
address (Req 1.7, applied transitively by Req 5.1/5.2, 19.1). Email generation preserves a valid
`local@domain` shape by inserting the suffix into the local part (so `client.qa` becomes
`client.qa.{RUN_ID}@example.test`).

```typescript
export interface DataFactory {
  name(base: string): string;     // `${base} ${runId}`
  email(base: string): string;    // `${base}.${runId}@example.test`
  address(base: string): string;  // `${base} ${runId}`
  /** True iff the value carries this run's suffix (used by cleanup selection, Req 21.1). */
  belongsToRun(value: string): boolean;
}

export function createDataFactory(runId: string): DataFactory;
```

### 3. Fixed personas + test-data provisioning (`personas.ts`, `test-data.ts`)

Defines the **exact** persona set required by Req 3 and provisions them once per run, each suffixed
with the run id and registered with the entity tracker as a `QA_Entity` (Req 3.8). Provisioning is
a `Destructive_Step` and is therefore routed through the gate.

```typescript
export interface PersonaSpec {
  key: 'admin' | 'client' | 'photographerA' | 'photographerB' | 'photographerC'
     | 'photoEditor' | 'videoEditor' | 'editingManager' | 'salesRep';
  role: string;
  baseLabel: string;                 // suffixed by data-factory at provision time
  specialties?: string[];            // e.g. ['HDR','Floor Plan','Drone']
  serviceRadiusMiles?: number;       // A=25, B=5
  availability?: { days: string[]; start: string; end: string }; // A: Mon–Fri 09:00–17:00
  optional?: boolean;                // salesRep is optional (Req 3.7)
}

export const PERSONAS: PersonaSpec[]; // fixed set per Req 3.1–3.7

export interface ProvisionedPersona {
  key: PersonaSpec['key'];
  id: string | number;
  email: string;                     // carries run-id suffix
  role: string;
}

export interface TestData {
  provisionAll(): Promise<ProvisionedPersona[]>;  // gated; tags each as QA_Entity
  get(key: PersonaSpec['key']): ProvisionedPersona | undefined;
}

export function createTestData(
  env: QaEnv, factory: DataFactory, gate: ConfirmationGate, tracker: EntityTracker,
): TestData;
```

The fixed set: `admin.qa`; `client.qa.{RUN_ID}@example.test`; Photographer A (HDR, Floor Plan,
Drone; radius 25mi; Mon–Fri 09:00–17:00); Photographer B (radius 5mi, outside-radius); Photographer
C (Video specialty only, wrong-specialty); a photo editor; a video editor; an editing manager; and
an optional sales rep created only when a scenario requires it.

### 4. Seeded-address provider (`seeded-address.ts`)

Supplies addresses with fixed latitude/longitude so distance gating is deterministic and does not
depend solely on live geocoding (Req 8.2). For each photographer it exposes `inside`, `boundary`
(distance equal to the radius), and `outside` fixtures, plus zero/empty/very-large radius scenarios
and a multi-eligible/tie-breaker fixture, mapping directly onto Truth Table rows T1/T2.

```typescript
export interface SeededAddress {
  label: string;                 // run-id suffixed for cleanup
  lat: number;
  lng: number;
  distanceMiles: number;         // precomputed distance from the target photographer base
}

export interface AddressFixtures {
  inside(photographerKey: string): SeededAddress;    // distance < radius (T1)
  boundary(photographerKey: string): SeededAddress;  // distance == radius (Req 8.4)
  outside(photographerKey: string): SeededAddress;   // distance > radius (T2)
  multiEligible(): SeededAddress;                     // ≥2 photographers eligible (Req 8.10/8.11)
}

export function createAddressFixtures(env: QaEnv, factory: DataFactory): AddressFixtures;
```

When geocoding is disabled and no Seeded_Address is available for a check, the distance-gating
check is recorded as a `Blocked_Check` with the geocoding dependency noted (Req 8.13).

### 5. Confirmation gate (`confirmation-gate.ts`)

The single choke point for every `Destructive_Step` and `Charge_Triggering_Step` (Req 2.2, 2.4,
18.11, 21.2). A guarded action only executes when the gate is confirmed; otherwise it is skipped and
recorded (Req 2.3). The gate is non-interactive in CI: confirmation is supplied per-category via
the env allow-flags (`E2E_CONFIRM_DESTRUCTIVE`, `E2E_CONFIRM_CHARGE`, `E2E_CONFIRM_MESSAGE`, with
optional `E2E_CONFIRM_CATEGORIES`), defaulting to **declined** so the suite is read-only by default
(Req 2.1). Where a step exposes a non-charging path, the gate executor prefers it (Req 2.5, 18.12).

```typescript
export type StepKind = 'destructive' | 'charge' | 'message';

export interface GuardedStep<T> {
  name: string;
  kind: StepKind;
  category?: string;             // optional fine-grained category for E2E_CONFIRM_CATEGORIES
  /** Preferred non-charging path; chosen automatically when present (Req 2.5/18.12). */
  nonChargingPath?: () => Promise<T>;
  /** The real (charging/destructive) action; only run when confirmed and no safe path. */
  action: () => Promise<T>;
}

export interface GateResult<T> {
  status: 'executed' | 'skipped' | 'blocked';
  value?: T;
  reason?: string;
}

export interface ConfirmationGate {
  isConfirmed(kind: StepKind, category?: string): boolean;
  run<T>(step: GuardedStep<T>): Promise<GateResult<T>>;
}
```

`run` semantics:
- If a `nonChargingPath` is present → execute it (no confirmation needed).
- Else if `isConfirmed(kind, category)` → execute `action`, return `executed`.
- Else → do not execute; return `skipped`. The report records the step as skipped.

### 6. Multi-role contexts (`contexts.ts`)

Creates up to **seven** independent Playwright `BrowserContext` instances within one run (Req 15.1)
so admin, photographer, client, photo editor, video editor, editing manager, and (optionally) sales
rep sessions are authenticated simultaneously and maintained independently (Req 15.2). Each context
logs in through `helpers/auth.ts` and exposes its own page + API request context. The sales rep
context is created lazily only when a scenario requires it.

```typescript
export interface RoleSession { context: BrowserContext; page: Page; token: string }

export interface RoleContexts {
  admin: RoleSession;
  photographer: RoleSession;       // Photographer A by default
  client: RoleSession;
  photoEditor: RoleSession;
  videoEditor: RoleSession;
  editingManager: RoleSession;
  salesRep?: RoleSession;          // optional (Req 3.7, 15.1)
  /** Lazily authenticate a named photographer (A/B/C) when a scenario needs it. */
  asPhotographer(key: 'photographerA' | 'photographerB' | 'photographerC'): Promise<RoleSession>;
  ensureSalesRep(): Promise<RoleSession>;
  dispose(): Promise<void>;
}

export async function createRoleContexts(
  browser: Browser, env: QaEnv, data: TestData,
): Promise<RoleContexts>;
```

### 7. Stable-selector resolver (`selectors.ts`)

Resolves onboarding-critical UI elements by `data-testid` rather than text/CSS/layout (Req 13.3),
exposes the named selector contract (Req 13.2), and records a `Blocked_Check` when a required
`data-testid` is missing rather than falling back to a brittle locator (Req 13.4).

```typescript
export const REQUIRED_TESTIDS = [
  'create-photographer-button', 'photographer-radius-input', 'booking-address-input',
  'eligible-photographer-row', 'cubicasa-create-order-button', 'shoot-status-badge',
  'raw-upload-input', 'submit-to-editor-button', 'finalize-delivery-button',
] as const;

export interface SelectorResolver {
  /** Locator by data-testid; records a Blocked_Check (Req 13.4) and returns null if absent. */
  byTestId(page: Page, testId: string, checkId: string): Promise<Locator | null>;
}

export function createSelectorResolver(report: QaReport): SelectorResolver;
```

### 8. Notification sink reader (`notification-sink.ts`)

When `E2E_NOTIFICATION_MODE` / `E2E_EMAIL_MODE` / `E2E_SMS_MODE` are `log` and `E2E_VOICE_MODE` is
`disabled`, notifications are routed to the `Notification_Sink` instead of being sent (Req 17.1).
This reader retrieves `Notification_Record`s so specs can assert recipient (Req 17.3), template
(Req 17.4), and rendered variables (Req 17.5), and can assert that **no real message was sent**
(Req 17.6).

```typescript
export interface NotificationRecord {
  recipient: string;
  template: string;
  variables: Record<string, unknown>;
  channel: 'email' | 'sms' | 'voice';
}

export interface NotificationSink {
  records(filter?: Partial<NotificationRecord>): Promise<NotificationRecord[]>;
  /** Asserts no record exists on a live channel (i.e., no real send occurred). Req 17.6 */
  assertNoLiveSend(): Promise<void>;
}

export function createNotificationSink(env: QaEnv): NotificationSink;
```

### 9. UI-consistency probe (`ui-probe.ts`)

Captures measurable UI signals on each navigated surface (Req 14) rather than relying on
screenshots alone, and runs them across the four viewports Desktop 1440x900, Laptop 1280x800,
Tablet 768x1024, Mobile 390x844 (Req 14.11), screenshotting each surface at each viewport
(Req 14.12).

```typescript
export const VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'laptop', width: 1280, height: 800 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'mobile', width: 390, height: 844 },
] as const;

export interface UiSignals {
  consoleErrors: string[];          // Req 14.1
  failedRequests: string[];         // minus allowed-list (Req 14.2)
  reactCrashBoundary: boolean;      // Req 14.3
  horizontalOverflow: boolean;      // mobile (Req 14.4)
  emptyStateRendered: boolean;      // when no data (Req 14.5)
  duplicatePrimaryButtons: number;  // Req 14.6
  statusBadgeText: string | null;   // consistency across surfaces (Req 14.7)
  hiddenRequiredFields: string[];   // Req 14.8
  staleData: boolean;               // post-save (Req 14.9)
  actionFeedback: 'loading' | 'success' | 'error' | 'none'; // Req 14.10
}

export interface UiProbe {
  probe(page: Page, surfaceId: string, allowList?: string[]): Promise<UiSignals>;
  probeAllViewports(open: (vp: typeof VIEWPORTS[number]) => Promise<Page>, surfaceId: string): Promise<UiSignals[]>;
}

export function createUiProbe(report: QaReport): UiProbe;
```

### 10. Run-scoped entity tracker (`entity-tracker.ts`)

Tracks **every** `QA_Entity` type created during the run — not just accounts — so cleanup is
run-scoped across all of them (Req 21.1). Each create path registers the entity; cleanup iterates
the tracker, selecting exactly the entities whose identifier carries the current run-id suffix.

```typescript
export type EntityType =
  | 'account' | 'shoot' | 'booking' | 'rawFile' | 'editedFile'
  | 'cubicasaOrder' | 'cubicasaReference' | 'equipment' | 'equipmentAssignment'
  | 'invoice' | 'reminderRecord' | 'notificationLog' | 'client' | 'address'
  | 'availabilityWindow' | 'blockedWindow' | 'report';

export interface TrackedEntity {
  type: EntityType;
  id: string | number;
  label?: string;                 // carries run-id suffix where applicable
}

export interface EntityTracker {
  track(type: EntityType, id: string | number, label?: string): void;
  all(): TrackedEntity[];
  /** Entities whose label/id carries the current run-id suffix (Req 21.1). */
  belongingToRun(factory: DataFactory): TrackedEntity[];
}

export function createEntityTracker(runId: string): EntityTracker;
```

### 11. Evidence-backed report collector (`report.ts`)

Accumulates one entry per check (Req 22.1), records pass/fail/blocked/skipped (Req 2.3, 8.13, 13.4,
22.1), supports continue-on-failure (Req 22.4), and lets a re-run override a prior failing result
with the latest pass (Req 22.6). It emits the full evidence bundle (Req 22.2): a Markdown report, a
JSON report, screenshots, the Playwright trace, video-on-failure, console logs, the network-failure
list, API response excerpts, the created `QA_Entity` identifiers (from the entity tracker), and the
cleanup status — plus a green/yellow/red summary. A `pass` requires associated evidence (Req 22.3).

```typescript
export type CheckResult = 'pass' | 'fail' | 'skipped' | 'blocked';

export interface Evidence {
  screenshots: string[];
  consoleLogs?: string[];
  networkFailures?: string[];
  apiExcerpts?: string[];
  tracePath?: string;
  videoPath?: string;             // present on failure
}

export interface CheckEntry {
  id: string;
  requirement: string;            // e.g. "8.3"
  result: CheckResult;
  evidence: Evidence;
  note?: string;                  // e.g. geocoding/selector dependency for blocked checks
}

export interface QaReport {
  record(id: string, requirement: string, result: CheckResult, note?: string): void;
  attachScreenshot(id: string, path: string): void;
  attachEvidence(id: string, evidence: Partial<Evidence>): void;
  /** Re-run override: latest result for an id wins (Req 22.6). */
  override(id: string, result: CheckResult): void;
  recordCleanup(entity: TrackedEntity, outcome: 'removed' | 'skipped' | 'failed'): void;
  entries(): CheckEntry[];
  summary(): 'green' | 'yellow' | 'red';
  write(markdownPath: string, jsonPath: string): Promise<void>;
}
```

Screenshots and artifacts are written under `../output/playwright/` consistent with
`qa-acceptance.e2e.ts`.

### 12. Backend fixtures (`backend-fixtures.ts`)

Thin wrappers that invoke the existing Laravel seed/admin commands as test fixtures, so domain
specs can arrange realistic state without bespoke setup. These are invoked out-of-band (the
operator runs them, or they are shelled per the suite's deployment), and the wrappers document the
exact command and arguments each domain relies on. No new backend commands are introduced.

| Concern | Command(s) | Used by |
| --- | --- | --- |
| Availability windows | `SeedPhotographerAvailability` | settings (20.1/20.2), calendar (9.x) |
| Blocked windows | `SeedPhotographerBlockedWindows` | settings (20.3/20.4), calendar (9.x) |
| Test addresses | `SeedPhotographerTestAddresses` | service-radius (8.x) |
| Previous shoot | `SeedPhotographerPreviousShoot` | shoot-workflow (12.x), booking-lifecycle (11.x) |
| Onboarding blocks | `SeedDashboardOnboardingForTeam` | account-creation (5.x), profile (7.x) |
| CubiCasa webhook/sync | `RegisterCubiCasaWebhookCommand`, `ResyncPendingCubiCasaCommand`, `BackfillCubiCasaAssetsCommand` | cubicasa (4.x) |
| Invoicing/reporting | `GenerateInvoices`, `SendWeeklyInvoiceSummaries`, `SendWeeklySalesReports`, `SendPayoutReports`, `ProcessInvoiceReminders`, `PaymentRemindersSweep` | invoicing-reporting (18.x) |

### 13. Per-domain spec modules

Each module is a `*.e2e.ts` file that imports the harness, performs read-only assertions directly,
routes every mutation/charge through the gate, resolves elements via the selector resolver, and
captures evidence into the report. Highlights per module:

- **`cubicasa.e2e.ts` (Req 4, Truth Table T7/T8):** asserts the floor-plan-gated visibility of the
  create-order control (present with Floor Plan, omitted without — 4.1/4.2); placing a manual order
  is a charge/message step → gated, and records exactly one pending order (4.3); double-click /
  repeated activation creates no additional order (4.4, idempotency); asserts the recoverable error
  state (4.5), the unlinked-order warning (4.6), a safe resync retry advancing pending→synced via
  `ResyncPendingCubiCasaCommand` (4.7), the webhook-callback status update (4.8), the
  missing-credentials **blocked** state (4.9), the provider-disabled **skipped/blocked** state
  (4.10); screenshots the order state (4.11).
- **`account-creation.e2e.ts` (Req 5):** admin-create via `POST /api/admin/users` (reusing the
  `team-onboarding-admin-create` pattern) and self-registration; both are destructive → gated;
  asserts run-id suffix, phone association (5.3), and login → photographer dashboard (5.4).
- **`approval-workflow.e2e.ts` (Req 6):** self-registration sets `Approval_State` Pending (6.1) and
  excludes from assignment while Pending (6.2); admin reviews the pending profile (6.3); approve →
  Approved (6.4) and assignable subject to profile/distance/availability/service (6.6); reject →
  Rejected (6.5) and never receives shoots (6.7).
- **`profile-completeness.e2e.ts` (Req 7):** verifies presence/required-state of profile photo,
  phone, email, base location, radius, specialties, availability, blocked dates, equipment,
  portfolio (7.1), optional insurance/tax/payment fields where exposed (7.2), notification
  preference and active/inactive status (7.3); incomplete required field → not assignable (7.4);
  complete + Approved → assignable (7.5).
- **`service-radius.e2e.ts` (Req 8, Truth Table T1/T2/T5/T6):** persists `Service_Radius` (8.1);
  drives eligibility/booking (reusing `service-area-assignment` building blocks) with
  `seeded-address` inside/boundary/outside fixtures (8.3/8.4/8.5); asserts unit + rounding rule
  (8.6), zero radius offers nobody (8.7), empty radius applies the default rule (8.8), very-large
  radius offers within it (8.9), multiple eligible photographers all offered (8.10), tie-breaker
  (8.11), area-restriction applied before radius (8.12); geocoding off + no seeded address →
  **blocked** with geocoding note (8.13).
- **`calendar-availability.e2e.ts` (Req 9, Truth Table T3/T4):** existing-shoot conflict
  exclusion/warning (9.1), travel-buffer between consecutive shoots (9.2), same-day cutoff (9.3),
  minimum lead time (9.4), outside-business-hours exclusion (9.5), timezone conversion consistency
  (9.6).
- **`admin-override.e2e.ts` (Req 10):** override-allowed manual assignment of an out-of-radius
  photographer with warning (10.1/10.2); override-not-allowed rejection (10.3); reassignment grants
  the new photographer access (10.4) and removes the previous photographer's access (10.5).
- **`booking-lifecycle.e2e.ts` (Req 11):** walks the ordered `Booking_Status` path and, per status,
  asserts the authorized trigger role (11.2), status visibility to authorized roles only (11.3),
  the status-specific action control (11.4), the `Notification_Record` for the transition (11.5),
  file visibility when a status exposes files (11.6), and files-locked before the unlocking status
  (11.7). Status transitions that mutate/charge/message are gated.
- **`shoot-workflow.e2e.ts` (Req 12):** photographer uploads files for an assigned shoot (seeded via
  `SeedPhotographerPreviousShoot`); covers upload edge cases: 30 images → count 30 (12.2), single
  large file (12.3), duplicate filenames rule (12.4), unsupported type rejected (12.5),
  interrupted-then-retried upload without duplicates (12.6), refresh shows uploaded files (12.7),
  wrong-role upload rejected (12.8), wrong-shoot upload rejected (12.9), verifies count/storage
  path/thumbnails (12.10), editor sees correct files (12.11), delete/replace reflected (12.12),
  malware/unsafe file blocked (12.13), processing advances to processed state (12.14); screenshots
  completed shoot (12.15).
- **`selectors.e2e.ts` (Req 13):** asserts the named `data-testid` contract is present (13.2) and
  records a `Blocked_Check` for any missing required selector (13.4); all other modules consume the
  resolver so they target `data-testid` (13.3).
- **`ui-consistency.e2e.ts` (Req 14):** runs the `ui-probe` signals across all four viewports
  (14.1–14.10), enforcing no console errors, allowed-list-aware network failures, no React crash
  boundary, no mobile horizontal overflow, defined empty states, no duplicate primary buttons,
  consistent status-badge text, no hidden required fields, no stale data post-save, and action
  feedback; screenshots each surface at each viewport (14.12).
- **`multi-role.e2e.ts` (Req 15):** holds all (up to seven) contexts open, asserts independent
  identities (15.2), and verifies a change in one context appears in another after refresh (15.3).
- **`negative-permissions.e2e.ts` (Req 16):** photographer cannot open another photographer's shoot
  (16.1) or upload to an unassigned shoot (16.2); client cannot open another client's shoot URL
  (16.3); editor cannot view a hidden extra (16.4); photo editor denied a video-only job (16.5) and
  video editor denied a photo-only job (16.6); inactive photographer not assignable (16.7);
  out-of-radius (16.8), blocked-window (16.9), and service-mismatch (16.10) photographers not
  offered; no CubiCasa action without Floor Plan (16.11); no duplicate order on repeated activation
  (16.12); payment-lock prevents download of unpaid final files (16.13) including a **direct file
  URL bypass** attempt (16.14).
- **`notifications.e2e.ts` (Req 17):** confirms sink routing under `log`/`disabled` modes (17.1),
  asserts a `Notification_Record` is created on a triggering event (17.2) with correct recipient
  (17.3), template (17.4), and rendered variables (17.5), and asserts no real SMS/email/voice send
  occurred (17.6).
- **`invoicing-reporting.e2e.ts` (Req 18):** `GenerateInvoices` produces an invoice (18.1);
  payment-lock permits preview but prevents download while unpaid (18.2) and permits download when
  paid (18.3); reminder paths produce a reminder for unpaid invoices (18.4) and none for paid
  (18.5); refund/cancel produces no incorrect invoice (18.6); zero-dollar product applies no lock
  (18.7); weekly invoice summary (18.8), weekly sales report (18.9), and payout report (18.10)
  paths; every `Charge_Triggering_Step` is gated (18.11) and prefers a non-charging path (18.12);
  screenshots each result (18.13).
- **`equipment.e2e.ts` (Req 19):** admin adds an `Equipment_Item` (run-id suffix, 19.1), lists it
  (19.2), assigns it to a photographer or shoot (19.3), reads the assignment back (round-trip,
  19.4), persists an equipment-related setting (19.5); screenshots listing + assignment (19.6).
- **`settings.e2e.ts` (Req 20):** sets availability (20.1) with booking-offered effect (20.2),
  blocked window (20.3) with booking-excluded effect (20.4), notification preference (20.5) with
  notification-record effect (20.6), profile setting persisted + reflected on profile (20.7), and a
  settings-UI toggle persisted + applied on the governed surface (20.8); screenshots each setting
  and its effect (20.9); live notification sends are gated.
- **`cleanup.e2e.ts` (Req 21):** runs last; iterates the **entity tracker** to identify every
  `QA_Entity` created during the run across all types (21.1), gates each deletion (21.2), removes
  confirmed entities (21.3), asserts no run-tagged entity remains (21.4), and records the cleanup
  outcome per entity in the report (21.5). Account deletions reuse `account-delete-cache-access`
  token-revocation/eviction checks.

## Data Models

```typescript
// A single QA check, the unit the report tracks.
interface Check {
  id: string;             // stable id, e.g. "service-radius.inside-offered"
  requirement: string;    // "8.3"
  result: 'pass' | 'fail' | 'skipped' | 'blocked';
  evidence: Evidence;
  note?: string;
}

// A QA-created account marked for cleanup.
interface TestAccount {
  id: string | number;
  email: string;          // carries the run-id suffix
  role: 'photographer' | 'client' | 'photo_editor' | 'video_editor'
      | 'editing_manager' | 'sales_rep' | 'admin' | string;
  approvalState?: 'Pending' | 'Approved' | 'Rejected';  // Req 6
}

// Distance-gating inputs (Req 8).
interface ServiceRadius { photographerId: string | number; radiusMiles: number; unit: 'mi' | 'km'; }
interface BookingAddress { line: string; lat: number; lng: number; distanceMiles: number; } // seeded

// Booking lifecycle (Req 11).
type BookingStatus =
  | 'Requested' | 'Scheduled' | 'Photographer Assigned' | 'Shoot Completed'
  | 'Raw Uploaded' | 'Sent to Editor' | 'Editing In Progress' | 'Edited Uploaded'
  | 'Editing Manager Review' | 'Approved' | 'Finalized' | 'Delivered'
  | 'Payment Due' | 'Payment Paid' | 'Downloadable';

interface BookingStatusRule {
  status: BookingStatus;
  triggerRole: string;          // 11.2
  visibleToRoles: string[];     // 11.3
  actionControl?: string;       // 11.4 (data-testid)
  notificationTemplate?: string;// 11.5
  unlocksFiles?: string[];      // 11.6/11.7
}

// Equipment (Req 19).
interface EquipmentItem { id: string | number; name: string; /* run-id suffix */ }
interface EquipmentAssignment { equipmentId: string | number; target: { type: 'photographer' | 'shoot'; id: string | number }; }

// Invoice + payment lock (Req 18).
interface Invoice { id: string | number; status: 'unpaid' | 'paid'; zeroDollar: boolean; }
```

The QA suite owns no persistent schema of its own; these are in-memory views of `Onboarding_System`
records observed through its existing APIs, plus the harness's own `Check`, `Evidence`,
`TrackedEntity`, and `NotificationRecord` structures.

## Error Handling

- **Continue-on-failure (Req 22.4):** spec modules record a `fail` and proceed; the runner does not
  abort the remaining checks. Playwright's `expect.soft` is used inside multi-assertion checks so a
  single failed assertion does not short-circuit the rest of that check.
- **Declined gate (Req 2.3):** guarded actions return `skipped`; the check is recorded as skipped,
  not failed, so a read-only run is green-by-design rather than a wall of failures.
- **Missing-data handling without human blocking (Req 2.6):** when required data for a check is
  missing, the check is recorded as `blocked` with the missing dependency noted and **all other
  checks continue**; the suite never waits for free-form human input.
- **Blocked dependency — geocoding (Req 8.13):** when geocoding is disabled and no Seeded_Address
  is available, the distance-gating check is recorded as `blocked` with the geocoding dependency
  noted, distinguishing an environmental limitation from a defect.
- **Blocked dependency — selectors (Req 13.4):** when a required `data-testid` is missing, the
  selector resolver records a `blocked` check with the missing selector noted rather than falling
  back to a brittle text/CSS locator.
- **Missing fixture data:** when a required env pin is absent (e.g. `E2E_CUBICASA_SHOOT_ID`), the
  spec **skips with an explanatory message** rather than faking a pass — matching the existing
  `test.skip(...)` convention in `cubicasa-manual-order` / `account-delete-cache-access`.
- **Missing CubiCasa credentials / disabled provider (Req 4.9/4.10):** the CubiCasa action is
  reported as `blocked` (credentials) or `skipped/blocked` (provider disabled) rather than `fail`.
- **Preview/auth wall:** the Lovable login-wall detection from `qa-acceptance.e2e.ts` is reused;
  affected checks skip with instructions to supply `E2E_PREVIEW_STORAGE_STATE`.
- **Report-and-fix (Req 22.5/22.6, and the per-domain re-run/override behavior):** when a failure is
  traced to a real defect, the fix is made in Laravel (backend) or React (frontend) code, and the
  failing check is re-run; the report's result for that check id is overridden to the latest pass.
- **External provider safety:** live charge/message paths are never executed without an explicit
  per-category confirmation; the non-charging path is preferred whenever one exists, and the
  notification sink (`log`/`disabled` modes) guarantees no real SMS/email/voice is sent (Req 17.6).

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a
system — essentially, a formal statement about what the system should do. Properties serve as the
bridge between human-readable specifications and machine-verifiable correctness guarantees.*

These properties target the **QA harness logic** (the parts with genuine input-varying behavior and
clear input/output contracts) and the observable gating, scheduling, distance, access, and
payment-lock behavior of the `Onboarding_System`. Infrastructure/integration criteria (live
CubiCasa order placement, upload processing, invoice/report command execution, notification content
delivery, per-surface UI render health) are validated by example/integration checks within the
relevant spec modules rather than by property tests.

Properties 1–5 are the **core properties mandated by Requirement 23**. They are **required, not
optional-for-MVP**, and each runs a minimum of 100 generated iterations.

### Property 1: The confirmation gate gates execution (CORE — Req 23.1)

*For any* guarded step and *any* confirmation state: when the step exposes a non-charging path the
executor invokes that path and never the charging action; otherwise the underlying
mutating/charging action executes **if and only if** the gate is confirmed for that step's kind and
category, and when not confirmed the step performs no mutation and the report records the check as
`skipped`.

**Validates: Requirements 2.2, 2.3, 2.4, 2.5, 18.11, 18.12, 21.2, 23.1**

### Property 2: Run-id run-scoped cleanup selects exactly the run's entities across all types (CORE — Req 23.2)

*For any* set of tracked entities spanning every `QA_Entity` type (accounts, shoots, bookings, raw
files, edited files, CubiCasa orders/references, equipment, assignments, invoices, reminder
records, notification logs, clients, addresses, availability windows, blocked windows, reports),
containing some created during the run and some not, cleanup selects exactly the entities whose
identifier carries the current run-id suffix, and after cleanup no run-tagged entity remains.

**Validates: Requirements 21.1, 21.3, 21.4, 23.2**

### Property 3: Distance-gating monotonicity across inside/boundary/outside (CORE — Req 23.3)

*For any* photographer `Service_Radius` (including zero, empty/default, and very-large), *any*
configured distance unit (miles or kilometers) and rounding rule, and *any* Seeded_Address, the
photographer is offered for that booking **if and only if** the address's computed distance is
within the `Service_Radius`, with the documented boundary rule applied consistently for the
inside/boundary/outside cases.

**Validates: Requirements 8.3, 8.4, 8.5, 8.6, 8.7, 8.8, 8.9, 23.3**

### Property 4: Settings effect (CORE — Req 23.4)

*For any* persisted availability window and blocked window, a client booking time results in the
photographer being offered **if and only if** the time falls inside an availability window and
outside every blocked window; and *for any* persisted profile setting or settings-UI toggle value,
the value is persisted and the governed surface (profile surface or toggle-governed surface)
reflects exactly that value.

**Validates: Requirements 20.2, 20.4, 20.7, 20.8, 23.4**

### Property 5: Role-access denial across Role_Contexts (CORE — Req 23.5)

*For any* `Role_Context` (admin, photographer, client, photo editor, video editor, editing manager,
sales rep) and *any* page, action, or resource restricted to roles that context does not hold,
navigating to or invoking it yields an access-denied result rather than the protected content or
effect.

**Validates: Requirements 16.1, 16.3, 16.4, 16.5, 16.6, 23.5**

### Property 6: Run-id suffixing of generated data

*For any* run id and *any* base label, every identifier produced by the data factory (name, email,
address) carries that run id as a suffix, and `belongsToRun` returns true for exactly those
identifiers produced with the current run id.

**Validates: Requirements 1.7, 5.1, 5.2, 19.1**

### Property 7: Missing-data and missing-selector yield blocked-and-continue

*For any* check whose required data or required `data-testid` selector is missing, the suite records
that check as `blocked` with the missing dependency noted and continues every other check without
waiting for human input.

**Validates: Requirements 2.6, 8.13, 13.4**

### Property 8: Continue-on-failure

*For any* run containing a failing check, every other check in the run is still executed.

**Validates: Requirements 22.4**

### Property 9: Re-run result override

*For any* check that is recorded as failing and later re-executed with a passing outcome, the
report's final stored result for that check is `pass`.

**Validates: Requirements 22.5, 22.6**

### Property 10: Report completeness and evidence association

*For any* completed run, the report contains exactly one entry per executed check, each carrying a
result; every check recorded as `pass` carries associated evidence; every screenshot captured for a
check is referenced under that check's entry; and every tracked `QA_Entity` carries a cleanup
outcome entry.

**Validates: Requirements 22.1, 22.2, 22.3, 21.5**

### Property 11: Context identity isolation

*For any* two of the simultaneously authenticated `Role_Contexts`, each context resolves its own
authenticated identity independently of the others while all are active.

**Validates: Requirements 15.1, 15.2**

### Property 12: CubiCasa order idempotency

*For any* number of repeated activations of the CubiCasa create-order control for the same shoot,
the system records exactly one order beyond the initial state (no duplicate orders).

**Validates: Requirements 4.3, 4.4, 16.12**

### Property 13: Payment-lock invariant

*For any* shoot invoice, while the invoice is unpaid and `Payment_Lock` applies the client may
preview but cannot download the final files — including through a direct file URL — and while the
invoice is paid (or the product is zero-dollar) the client may download the final files.

**Validates: Requirements 16.13, 16.14, 18.2, 18.3, 18.7**

### Property 14: Approval-state assignability

*For any* photographer, the photographer is assignable only while the `Approval_State` is Approved
(subject to profile, distance, availability, and service rules); a Pending or Rejected photographer
is never assignable and never receives shoots.

**Validates: Requirements 6.2, 6.6, 6.7, 7.4, 7.5**

### Property 15: Equipment assignment round-trip

*For any* `Equipment_Item` and *any* assignment target (photographer or shoot), recording the
assignment and then reading the tracking surface yields that same target as the item's current
`Equipment_Assignment`.

**Validates: Requirements 19.3, 19.4**

## Testing Strategy

**Dual approach.** Property tests cover the harness logic and the observable
gating/distance/scheduling/access/payment behavior (Properties 1–15, with 1–5 mandated as core by
Req 23). Example/integration checks within each spec module cover the live, non-input-varying
criteria: CubiCasa floor-plan visibility, order placement, resync, webhook, and credential/disabled
states (4.1/4.2/4.5–4.10); account phone + dashboard navigation (5.3/5.4); admin review surface
(6.3); profile field presence (7.1–7.3); calendar travel-buffer/cutoff/lead-time/business-hours/
timezone (9.2–9.6); admin override/reassignment surfaces (10.x); per-status booking controls,
visibility, notifications, and file locks (11.x); upload edge cases and processing (12.x); the named
selector contract (13.2); per-surface UI signals across 4 viewports (14.x); cross-context
propagation (15.3); inactive/out-of-radius/blocked/service-mismatch non-offer (16.7–16.11);
notification recipient/template/variables and no-live-send (17.x); invoice/reminder/summary/report
command paths (18.1/18.4–18.6/18.8–18.10); equipment listing/setting persistence (19.2/19.5);
notification-preference effect (20.6); and confirmed deletion (21.3).

**Property test configuration.** Each property test runs a minimum of 100 generated iterations and
is tagged with its design property:

`Feature: photographer-onboarding-qa, Property {number}: {property_text}`

Harness properties (1–2, 6–10, 12) are pure/in-memory and run fast against generated inputs.
System-observing properties (3–5, 11, 13–15) are exercised against the target environment using
generated inputs (seeded addresses, radii/units, availability/blocked windows, toggle states, role
pairs, invoice states); where they would trigger live charges or messages they run through the gate
and prefer non-charging paths, and they are pinned by env to seeded fixtures and the notification
sink so a green run is a genuine verification rather than a skip.

**Viewport coverage.** UI-consistency checks (Req 14) execute the `ui-probe` signal set at Desktop
1440x900, Laptop 1280x800, Tablet 768x1024, and Mobile 390x844, capturing a screenshot of each
verified surface at each viewport.

**Truth Table coverage.** The normative rows T1–T8 are realized by `service-radius.e2e.ts`
(T1/T2/T5/T6), `calendar-availability.e2e.ts` (T3/T4), and `cubicasa.e2e.ts` (T7/T8); each row is
reported green only when its "Green Only If" condition is met with captured evidence.

**Smoke/config checks.** Spec placement and the chromium/`npm run test:e2e` wiring (1.1/1.2/1.3),
the documented env resolution (1.4/1.5), and the managed-server toggle (1.6) are verified once as
configuration smoke checks.
