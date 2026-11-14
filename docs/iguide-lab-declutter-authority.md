# Isolated declutter authority candidate

This candidate implements the initial source-removal plan and machine segmentation authority only. It registers no API route, workflow driver, public capability, configuration default or provider execution. The existing lighting, staging and grass code is unchanged.

## Supported proposal scope

One compact, opaque, clearly decorative tabletop object on a simple continuous matte receiver. The complete object and any attributable short local shadow must fit inside a 10% source-cut margin. The plan explicitly describes hidden receiver pixels as a generated estimate, never recovered ground truth.

Transparent objects, reflective or patterned receivers, multiple or functional objects, thin foliage or open silhouettes, shared/long shadows, uncertain foreground ordering and off-cut optical dependencies hold for review. Unresolved original source-plan caveats also hold; the second proposal cannot silently discard them.

## Source authority and inputs

`LabDeclutterRemovalContext` loads an actual workflow-owned completed source analysis and section. It verifies the native source through `LabWorkflowSources`, the exact native/cut/extraction receipt and pose, and the imported-native or server-owned attachment context through `LabAnalysisSourceContext`. A client metadata assertion cannot substitute for a completed creating import. Unknown room metadata is not inferred.

The typed protocol receives exactly six labelled images: the immutable source cut, its original parent overview and front/right/back/left projections. All are views of one capture, not additional calibrated cameras. Original source-plan constraints are retained in full within a bounded context; oversized input holds instead of being truncated. The exact actual cut hash and separately prepared future image-input hash remain distinct.

## Durable execution

The `removal-plan` workflow step owns the sole request UUID. A separate `IguideLabDeclutterRemovalPlan` record stores the exact context, compiled payload, model response, provider attempt and parsed result. It is not a successful ordinary `IguideLabAnalysis` and must never be represented as one.

Only a never-submitted queued record may be dispatched on reservation replay. An ambiguous submission holds. A known durable receipt can be parsed without another provider call after fresh source and cancellation checks. Fallback is permitted only after an explicit primary quota rejection, using the same payload. Completed authority rehashes its own response file, payload and execution record, verifies provider identity and attempt history, and compares parsed JSON canonically while retaining its exact-byte hash.

The plan yields `target_object` and, only when positively declared, `target_local_shadow`. Both SAM inputs are the exact original 2048-square cut. Each role permits one box, one mask and at most one POST. Persisted workflow-step UUIDs are injected into the otherwise unreserved logical plans. Shadow execution requires a current verified object receipt. Mask return order is not identity, empty masks hold, and machine selection never carries a human-review claim.

## Integration work still required

The closed capability handler is deliberately absent from the shared SAM router. The existing workflow policy and dispatcher are untouched. A later reviewed integration must add the explicit two-analysis / one-image / two-SAM / one-evaluation budget and its own false-default readiness gate.

The source routing/framing driver, typed-plan exact-prompt image transport, object/shadow support construction, generated candidate assessment, bounded harmonic/native composition, full-sphere evaluator and download artifact are not implemented here. In particular, using this plan's prompt must not bypass the existing scene-analysis association contract by pretending it is an applied scene analysis. The public one-click declutter preset remains unavailable.

Tests use synthetic evidence and intercepted HTTP. Passing authority or receipt tests establishes no real-scene removal quality or unattended end-to-end success.
