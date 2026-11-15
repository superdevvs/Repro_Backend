# Finite full-tour processing budget candidate

Local successor of frozen scheduler `62e90158`. Nothing in this candidate raises production source/image capacity, enables a flag, submits providers, changes quality gates or publishes a UI. `IGUIDE_LAB_TOUR_PROCESSING_BUDGET_ENABLED` defaults to false. The scheduler remains serial, at most80 items with default20 unchanged.

## Why the scheduler alone is insufficient

Legacy daily ceilings are global **per record table**, not a single combined analysis pool. Most analysis/framing/assessment/plan tables and quality evaluations use120 per rolling24hours; Interior room and existing-material assessments use240. Images independently use120 per24hours and the project's retained `max_runs`. Sections/composites have120 lifetime ceilings and no daily ceiling; the scheduler already replaces their lifetime bound for exact owned tour steps. SAM has no independent global daily/lifetime record ceiling: each child is bounded by its owned step, immutable plan, source/receipt/mask authority and paid claim. Returned mask count is not additional child-call authority.

Worst implemented counts for80 homogeneous items, assuming every applicable branch succeeds far enough to consume its full attempts:

| Preset/branch | Analysis records, by table | Images | SAM children | Sections | Composites / evaluations |
|---|---|---:|---:|---:|---:|
| Golden hour, repair enabled |160 general|240|0|0|400 /400|
| Twilight, repair enabled |160 general|240|0|0|400 /400|
| Staging, combined layout |80 framing +160 general layout +80 placement|80|160|160|80 /80|
| Lifestyle, optical follow-up |160 framing +80 general cut +80 placement|80|160|80|80 /80|
| Grass, source admission |160 source admissions +80 general +80 candidate|80|480|0|80 /80|
| Declutter |80 general +80 removal plans|80|160|80|80 /80|
| Interior, furnishing and one correction |400 room assessments|160|160|80|80 /80|
| Interior, classified materials |80 room classifier +240 material assessments|80|≤1200|80|80 /80|

Holds consume less; quota-only fallback can add an existing bounded transport attempt within one record. Generic step reservations are sometimes more conservative than the implemented path (lighting/grass reserve unused section/SAM capacity; room preclassification keeps material15-mask capacity and section2). The allocation uses those exact saved per-item budgets, not guessed success counts. It never converts the unused allowance into a new step, branch switch, third furnishing image or new support authority. An80-item lighting tour's400 evaluations already require four legacy daily windows, so a25-hour capacity wait is not a general80-item solution.

## Explicit server-side session allocation

One private `iguide_lab_tour_budgets` record grants a project at most80 capture-items across its allocations, six finite record totals, and an expiry no later than the existing session. Hard per-item maxima are the maxima of current recipe budgets: analysis5, section8, image3, segmentation15, composition8, evaluation8. The grant freezes its authority digest and owning implementation hashes. A server service/CLI creates the grant; no public request accepts a grant or a larger allowance. Future workspace/billing automation can call that same server service without adding a manual step to the simple UI.

All six counts and expiry are explicit operator inputs. For example, an80 furnishing-capable Interior tour reserves the componentwise branch maximum: analysis400, section160, image160, segmentation1200, composition80, evaluation80. A grant does **not** change `max_runs`: existing remaining project capacity must cover the grant's image bound before creation, and must again cover a workflow's image allowance at admission. The observed production150/160 cases and83/120 images remain unchanged and cannot support that80-generation experiment. Import/case capacity remains a separate explicit choice.

When the budget flag is enabled, a new scheduler workflow requires exactly one active grant. Inside the existing project write transaction, after exact replay reconciliation, it reserves its recomputed recipe/item allocation plus item count. The workflow captures the exact grant ID, project, workflow ID, digest, expiry and allowances. Concurrent/competing admissions cannot allocate the same remaining allowance twice. Cancelled/failed/held/terminal work never silently refunds an allocation; new capacity requires a separate explicit grant after expiry/revocation. Prior grants and every workflow remain retained.

Only a verified grant-bound workflow substitutes this finite allocation for legacy daily table limits. It still uses the same locked child reservation, request UUID, per-item step limits, source/cancellation/paid-claim checks and unchanged `used_runs` accounting. Manual operations and workflows without the new allocation retain their original120/240 predicates, including counting the granted records in those legacy windows. Project image exhaustion still holds even when a grant has space. A later manual spend or explicit project cap reduction can therefore prevent a tour finishing; it never permits spending above `max_runs`.

Execution rechecks grant expiry/revocation, current implementation and the exact aggregate allocation ledger. A disabled **new-admission** flag does not rewrite an existing grant; explicit revocation stops its future claims. Existing records are readable, no submitted request is refunded, and an in-flight provider request is never retried by revocation. The scheduler's fixed session/tour deadlines, one-active-item rule and two-hour item clock are unchanged.

## Verification scope

Targeted tests perform actual locked160 general-analysis,160 image and160 evaluation reservations across80 scheduler items, with exact replay and image charges. Another test creates400 room-assessment records across the five closed phases for80 furnishing items. Semantic contexts/payloads are explicitly synthetic stubs; no provider is called and no image/support/Ready claim follows. Existing recipe correctness and full0d correction proofs remain separate. Boundary tests cover grant exhaustion, competing admission, no refund, current project cap, public field rejection, ledger/identity tamper, expiry/revocation and unchanged legacy120/240 limits. Compatibility tests retain62e's defaults, closed correction keys, cancellation, admission clocks and actual controller historical reads.

Before any release: review exact source/proof and runtime binding impacts; establish actual source/import/image/session allowances; use the owning deployment wrapper and exact CI; verify runtime and worker readiness with a fresh protected baseline. This document and the local grant service authorize none of those production mutations.
