# Full-tour sequential scheduler candidate

Local integration candidate based on frozen recipes `c54e6638e174566c0abd8a1aa47831588c28bab1`, applying scheduler `537c49cc14d22a82c1f60fa6b3afa83241780275`. The earlier isolated scheduler and recipes proof packets are retained unchanged. No provider requests, runtime changes, push, or production activation are part of this integration.

## Observed limit

The workflow controller and capability currently accept at most 20 items. The workflow service already processes only the first nonterminal item on each claim, persists all child UUIDs, and retains cancellation checks before provider submission. However, one `ProcessIguideLabWorkflow` job has only 480 queue deliveries for the entire tour. Fifteen-second polling and overlap-lock releases consume that same count. Raising only the API limit would therefore leave a second failure for longer tours.

Provider attempts have their own bounded deadlines. The workflow currently has no separate item wall-time watchdog. The existing 120-image rolling daily limit and each project's configured image allowance are additional admission constraints, not a reason to silently disable Generate.

## Implemented scheduling policy

One public Generate request reserves one persisted workflow with the same ordered item records and existing sequential driver. `IGUIDE_LAB_TOUR_SCHEDULER_ENABLED` defaults to false. Enabled capability reports 80 items; disabled capability reports 20. The controller always accepts the hard maximum of 80 for exact replay. The service looks up the original request fingerprint before applying the current new-admission limit. Consequently disabling the flag stops new large-tour admission but does not cancel, change, or prevent replay of a previously admitted tour. Changed choices or order under the same request ID still conflict. Eighty-one items are always rejected; nothing is truncated.

The new policy captures one server-authored `retryUntil` timestamp and policy digest in the queued job. It is not recomputed on deserialization or release. Laravel's actual worker guard permits polling beyond 480 deliveries while that timestamp remains in the future. New jobs retain a three-exception limit, the 600-second execution timeout and existing overlap lock. Legacy jobs return null from `retryUntil`; Laravel then continues to enforce their existing 480-delivery ceiling. A local test invokes the actual Laravel guard for both paths.

Durable per-item state lives in the private `scheduler` column: first-admitted time, active deadline, capacity-wait reason, next eligible time, and accumulated wait time. Each item has two hours of admitted time. An item queued behind earlier captures does not spend that allowance. A capacity wait pauses only its remaining active allowance. The current item's deadline is checked at every workflow tick and every provider claim. Timeout holds that item and retains all children and receipts; it never refunds a submitted or ambiguous operation.

The fixed tour deadline is the earlier of project session expiry and reservation time plus two hours per item plus 25 hours. Each item also has a 25-hour cumulative capacity-wait ceiling. Neither bound extends authentication. A tour that requires additional rolling-window periods can hold rather than finish; these are operational bounds, not a guarantee that all 80 captures finish. One active item is allowed at a time, with 15-second normal polls and at most 60-second polls during waits.

## Capacity is a visible queue condition

Keep the project image allowance and the global rolling 120-image cap unchanged. Root may configure this lab's allowance within the existing authorization; the browser does not grant budget. Report remaining project allowance and the rolling-window constraint truthfully. Never reserve or bill an image merely to hold capacity.

A temporary rolling-window limit leaves the current item queued with a next eligible timestamp from the oldest counted record. The same reserved child UUID resumes after capacity becomes available; no child record, provider call or image charge is created by the rejected reservation. A project image allowance that cannot admit another image holds with a specific reason. The global daily limit and configured project image allowance are unchanged.

`LabWorkflowCapacity` carries a typed temporary wait or permanent hold. Existing locked image and analysis/evaluation reservation checks stay authoritative: a race after preflight returns that same condition without creating a record or incrementing usage. Cancellation, source and receipt revalidation remain required when the wait ends.

## Server-owned record allowances

Historical per-project analysis, section, evaluation and composite ceilings of 120 would prevent later captures from progressing even when provider capacity is available. For a new scheduler workflow only, `LabWorkflowSteps::limits` is the single definition of each preset's existing bounded child budget. Its sum is frozen in the workflow policy and independently recomputed from the owned item records. `LabWorkflowRecordCapacity` accepts only an internal, exact workflow invocation and an owned reserved child UUID. It then applies this tour's allowance rather than counting unrelated historical records. Daily limits remain exactly the existing per-model rolling limits; public/manual and legacy operations retain their original lifetime limits. Image `max_runs` is unchanged. SAM already uses owned per-item step bounds and has no additional lifetime ceiling to remove.

An extracted section may need a source helper after an 80-capture import has consumed the ordinary case allowance. Only a hash-bound internal section invocation can create its one helper: the saved section request, parent, native hash, section ID and invocation must match, and no different helper may already exist for that section. Exact helper replay returns the existing case. Client-supplied lineage metadata alone does not grant this allowance. Public upload/import capacity is unchanged.

Separately, the existing operator-only `iguide-lab:set-case-limit` command may explicitly set one project's source-record limit from 80 through 1,024 (previous ceiling 160). Its default stays 80 and existing stored values do not change. No HTTP field grants this increase. The project must be active; a new limit cannot be below its retained record count. Paid quotas, authentication, per-file size, ZIP limits, imported identity/dedup and atomic publication stay unchanged. The larger finite bound supports retained historical tours plus a new metadata-bound full-tour import; it is not an automatic capacity increase or a promise of unlimited storage.

The optional invocation arguments on section, helper-source and composite creation are server-only. No public request accepts them. Their existing file, geometry, source and output validation remains in force.

## Public contract

The existing request shape stays `{requestId, items}` and status vocabulary stays intact. Scheduler policy/state remains private. Capability `maxItems` changes only when enabled. The existing UI must use that capability and clearly show a queued item; any optional public scheduling descriptor needs its own strict read-contract review before exposure. The full ordered request remains the idempotency identity.

The API's existing `sceneAnalysis.usedAnalyses` remains the truthful total retained analysis count. It can now exceed `maxAnalyses: 120`, which still governs manual analysis creation. The current strict reader incorrectly caps `usedAnalyses` itself at 120; before enabling this candidate, widen that read validation without clamping the count or raising the manual budget. Local tests export actual synthetic controller DTOs with 121 retained analyses and an 80-item workflow for this read-contract review. Existing project collection-size bounds and future pagination must be checked separately as history grows.

## Verification and remaining integration

The candidate adds scheduler/record-capacity classes and a private state migration, with narrow calls at the existing locked reservation points. No preset algorithm, support mask, source transform, evaluator, provider retry policy, or paid-attempt limit changes. All c54 recipe implementations, source/placement protocols, provider logic and numerical workers are retained. Their owning runtime hashes will require a separate integration audit before release; the new scheduler flag remains off.

This integration retains staging-layout's four analyses, two sections and one composition plus its exact allowed step keys; Interior's three analyses, 15 segmentations, one image/section/composition/evaluation; and all legacy, lighting-repair, Lifestyle, grass-admission and declutter budgets. Internal invocation now reaches `LabStagingLayouts` reframe extraction and Interior section/composite creation. The Interior section input includes its unchanged reserved `framingId`; otherwise the new exact-input guard would correctly reject a reduced subset. `IguideLabInteriorAssessment` participates in owned tour capacity while retaining its original 240 lifetime records for legacy operations and 240 global rolling daily records for every path. New tests cover the actual Interior record reservation, daily wait/resume identity and both owned layout cuts.

The integration uses the same public DTO contract: actual synthetic controller exports show 121 retained analyses with `maxAnalyses: 120`, and the explicitly enabled capability reports `maxItems: 80`. No scheduler status or internal policy field is added to the public reader. These are transport fixtures, not quality or paid-provider success evidence.

Focused tests cover 80 distinct capture reservations and cancellation, disabled-flag exact replay, API/service rejection at 81, one active item, fixed deadlines beyond 480 polls and legacy null semantics, capacity-race replay with one image charge, daily-analysis waits, source drift, changed allowances and owned section-helper capacity. All provider HTTP is prohibited or intercepted. Final test counts and exact source hashes belong in the candidate proof, not inferred from this document. A complete 80-item real-worker synthetic chain, current frontend contract, merged-runtime audit and deployment review remain separate readiness work; no fresh real-scene success is claimed.
