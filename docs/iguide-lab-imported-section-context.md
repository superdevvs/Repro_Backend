# Imported native context for section analysis

This extends the existing section-parent-context v1 input contract with one explicit source origin. It does not change the frozen section instruction recipe, six-image ordering, projection algorithm, old attachment snapshots, or old request-ID replay identity.

Normal `LabAnalyses::start` selects this path automatically for a completed server-extracted section of an imported native panorama. No client option, context ID, path or assertion enables it. The creating import must be completed in the same project and must uniquely map the exact native case ID, source SHA and dimensions at `iguide-lab/{project}/imports/{creatingImport}/{case}.{png|jpg|webp}`. The native case's original `metadata.importId` must agree. Actual file bytes, source geometry, cut bytes and pose are rechecked. User-editable metadata alone is insufficient.

The imported panorama itself is the visible parent, so `visibleParentCaseId === nativeCaseId`. No uploaded native attachment or second preview case is invented. Existing attachment origins continue to require the actual `native_source` backlink.

The analysis DTO's nullable `sourceContext` retains every existing field. For imported sections it has:

```json
{
  "version": "section-parent-context-v1",
  "sourceOrigin": "completed_scan_import",
  "sourceAuthority": {
    "version": "completed-import-native/1",
    "importId": "creating-import-uuid",
    "importSourceKey": "immutable-source-key",
    "mappingSha256": "sha256-of-the-persisted-creating-import-source-mapping"
  }
}
```

The example is a fragment; existing section/native/source/geometry hashes, roles, pose, dimensions, preparation hashes and binding SHA are still required. `manifestSha256` remains null before preparation. For `uploaded_native_attachment`, `sourceAuthority` is omitted, not null. A compiler rejects mixing imported authority into an attachment origin. Historical or cut-only analyses still expose `sourceContext: null`.

Only the exact square cut is an editing target. The original parent overview and four cardinal diagnostics are context from the same capture, not independent views or additional editing targets. Off-cut dependencies still require explicit review. Import mapping or asset drift before provider submission, recovery or Apply stops the association; it does not silently replace the reserved source. Existing queued records keep their captured recipe and input representation.

This candidate requires the ingestion model/migration and reviewed section-parent-context implementation. It does not enable automatic staging, dispatch providers, or claim final quality.
