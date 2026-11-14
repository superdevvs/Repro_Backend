# Analysis recipe snapshots

This foundation is separate from native section editing and does not adopt a new analysis prompt. The original `scene-instructions-baseline-1` remains the only registered and default recipe.

Before dispatch, new reservations privately snapshot exact UTF8 instructions, wire schema, requested model, inference controls, compiler version and view recipe. The worker validates that snapshot against the immutable registry, verifies source/view hashes, compiles through the frozen v1 encoder and checkpoints the provider-body hash before claiming any paid attempt. Quota-only credential fallback uses the same payload. A changed active default cannot alter queued work or idempotent replay; historical request hashes remain unchanged.

Analysis DTOs add nullable `instructionProvenance`:

- `instructionsVersion`, `instructionsSha256`, `schemaSha256`, `executionRecipeSha256`, `compilerVersion`.
- `captureMode`: `reservation_snapshot` for new reservations or `legacy_registry_resolution` for an old queued v1 record.
- `providerBodySha256`: null until a source-bound request has been prepared and checkpointed.

The strict frontend parser must accept this additive field before this separate foundation deploys. Raw instructions, schema, reasoning and private paths remain private. Completed historical records without captured evidence report null; they are not retroactively presented as snapshotted. The DTO uses record-pinned model/protocol attribution rather than a newly selected default. Applied image-run provenance and old run hashes remain unchanged.

Legacy queued records resolve only the known original protocol/view recipe through the archived registry. Unsupported legacy versions, missing marked snapshots or tampered recipe bytes stop before a provider request. New recipes require an explicit registry entry and review; clients cannot supply system instructions, schemas or provider settings through this mechanism.

This mechanism covers an instructions-only upgrade with the existing scene-plan-v1 schema and view algorithm. Parsing and source/view preparation still use the current v1 services. A future schema or view-algorithm upgrade would also need versioned parser and preparer implementations; snapshots alone do not provide that compatibility.
