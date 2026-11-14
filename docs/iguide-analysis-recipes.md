# Analysis recipe snapshots

This candidate is separate from native section editing. It registers the exact frozen `scene-instructions-physical-dependencies-1` instructions as the next experimental Lab default, while retaining the immutable original `scene-instructions-baseline-1` for queued work and historical resolution. A coordinated frontend contract change is required before deployment.

Before dispatch, new reservations privately snapshot exact UTF8 instructions, wire schema, requested model, inference controls, compiler version and view recipe. The worker validates that snapshot against the immutable registry, verifies source/view hashes, compiles through the frozen v1 encoder and checkpoints the provider-body hash before claiming any paid attempt. Quota-only credential fallback uses the same payload. A changed active default cannot alter queued work or idempotent replay; historical request hashes remain unchanged.

Analysis DTOs add nullable `instructionProvenance`:

- `instructionsVersion`, `instructionsSha256`, `schemaSha256`, `executionRecipeSha256`, `compilerVersion`.
- `captureMode`: `reservation_snapshot` for new reservations or `legacy_registry_resolution` for an old queued v1 record.
- `providerBodySha256`: null until a source-bound request has been prepared and checkpointed.

The strict frontend parser must accept this additive field before this separate foundation deploys. Raw instructions, schema, reasoning and private paths remain private. Completed historical records without captured evidence report null; they are not retroactively presented as snapshotted. The DTO uses record-pinned model/protocol attribution rather than a newly selected default. Applied image-run provenance and old run hashes remain unchanged.

Legacy queued records resolve only the known original protocol/view recipe through the archived registry. Unsupported legacy versions, missing marked snapshots or tampered recipe bytes stop before a provider request. New recipes require an explicit registry entry and review; clients cannot supply system instructions, schemas or provider settings through this mechanism.

This mechanism covers an instructions-only upgrade with the existing scene-plan-v1 schema and view algorithm. Parsing and source/view preparation still use the current v1 services. A future schema or view-algorithm upgrade would also need versioned parser and preparer implementations; snapshots alone do not provide that compatibility.

The candidate instructions have SHA256 `42461b443760cdfe41eeb166c03d227ea5f9cf537ffef63e16854ad02356b626`; baseline instructions retain `f25a88708519f3f704ca957787d8b815358780be8c669f7bcb4a574fc822d514`. The five-pair blinded holdout used one response per arm and two independent reviewers: both preferred the candidate on four pairs and tied golden-hour. Case scores were40-to48 and41-to49 out of50; shared checks passed for both. Median recorded transport-plus-provider time increased from44.8s to94.9s. These observations support planning quality for this small sample, not generated-image success, reliability or automatic quality acceptance.
