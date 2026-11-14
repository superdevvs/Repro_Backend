# Source framing and actual-cut staging checks

This experimental route separates a useful view proposal from permission to generate an image. It is disabled by default and requires a validated private runtime manifest. Existing workflow reservations keep their original policy and route.

The source-only analysis proposes one yaw, pitch and horizontal field of view. The server extracts that exact view without a provider call. A second analysis then checks the actual square cut together with its verified native-parent overview and four cardinal views. Generation is allowed only when all five checks pass: complete group coverage, frame margin, circulation, fixed features and optical dependencies. Any failed or uncertain check stops before the image request. The existing actual-placement assessment, machine segmentation, bounded support and native full-sphere quality checks still apply afterward.

The recipe remains exactly one sofa, one coffee table and one chair. Framing is bounded to yaw −180–180°, pitch −60–15° and HFOV 30–120°. The full group, legs and contact region must fit with a 10% boundary margin. No historical view, manual support, operator coordinates or prose-derived pose is used. A candidate is an unverified same-capture proposal, not measured clearance or calibrated optical geometry.

## Paid and free steps

The existing limit of three analysis calls per item is unchanged: source framing, actual-cut planning and actual-placement assessment. At most one image, two segmentation calls and one independent quality evaluation follow. Local extraction and native composition do not call providers. A source-framing hold uses only the first analysis. An actual-cut hold uses the first two and produces no image.

Every provider attempt uses the existing cancellation claim, stable step request ID and explicit quota-only credential fallback. Unknown submissions are retained and held without retry. A committed but never-submitted queued framing record can recover its attachment and queue dispatch. Known raw framing receipts are reparsed without a repeated request. An interrupted actual-cut analysis follows the existing conservative analysis lifecycle and is never silently resubmitted.

## Internal and read contracts

There is no new public write endpoint. The workflow driver alone creates the typed `IguideLabStagingFraming` record and supplies `stagingFraming` to the internal analysis service. The public controller continues rejecting unknown write fields.

New workflow items may expose `sourceFraming` with version, ID, status, native source hash, nullable result hash/result, `imageGenerationAuthorized: false`, nullable error code and timestamps. A completed result contains the proposed pose and five structured constraints. It always reports machine authorship, no measured geometry and no manual acceptance. The record is separate from scene analyses.

The subsequent scene analysis adds `stagingFraming` and nullable `stagingReadiness`. Its existing proposal and provenance shape remain intact. `stagingReadiness` binds the cut hash, native hash and framing result hash, records all five checks and derives `allRequiredChecksPass`. New provenance versions are `scene-instructions-staging-cut-gate-1` and `responses-staging-cut-gate-v1`. Existing records omit these new fields.

Each image submission—including a credential fallback—rechecks the current native/import authority, exact extraction pose, source context, ordered evidence files, immutable instruction recipe, exact request payload, retained provider response and parsed readiness. Neither a stored `pass` flag alone nor a source-framing candidate grants authority. The final composite retains the framing result and actual-cut readiness fingerprints separately from its scene plan. Original source constraints are carried into independent quality review as model claims.

## Activation and limits

`IGUIDE_LAB_STAGING_FRAMING_ENABLED` defaults to false. `IGUIDE_LAB_STAGING_FRAMING_RUNTIME_MANIFEST` defaults to null. Enabling the flag without a matching validated manifest disables staging readiness; it does not silently fall back to the older route. The manifest binds all owning PHP implementations and diagnostic code, along with Python 3.12.3, NumPy 2.5.4 and Pillow 13.0.0. Existing staging support and workflow runtimes remain separate prerequisites.

This route does not establish real-scene quality from synthetic tests, relax support or reflection checks, add cross-view consistency, or mark a result manually accepted. Source-only framing can still stop safely when the room is occupied, evidence is insufficient or no coherent bounded group is plausible. Actual-cut or placement checks can also hold even after successful extraction.
