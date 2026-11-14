# Automatic source-native grass candidate

This candidate extends the existing workflow API. It is disabled by default and does not add a public assessment or segmentation endpoint.

The initial scope is **recolor all visible established turf**. It does not add grass texture, infer a legal property boundary, repair soil, or verify another camera position. Custom instructions are held for review because the fixed assessment policy does not support a narrower scope.

## Execution

1. Verify the imported or uploaded original sphere and generate the exact production GD preparation.
2. Run a source scene analysis, then one Sunburst 2.6 image request with its bound prompt. A section route is unsupported here.
3. Create a separate durable material/exclusion assessment from the original prepared sphere, candidate and six source-derived directions. Source, candidate, analysis, generation settings and context hashes are mandatory.
4. Reserve all required server request IDs. Segment the original source only, in fixed order: lawn, paving, plants, loose debris, non-turf ground, capture artifact. A class may be skipped only when the assessment explicitly finds none observed. Missing or ambiguous returned masks hold the workflow; they never establish class absence.
5. Revalidate receipts, raw mask bytes, decoded bounds and machine associations. Apply the frozen native chroma worker. It uses native source luminance/detail, candidate color guidance and effective support; it copies no generated spatial texture. Protected pixels must remain exact.
6. Build seven source-left/candidate-right boards from actual source and composed native pixels. A separate evaluator inspects the full sphere. Numeric checks cannot accept semantic mask correctness, appearance or optical consistency.

The explicit source-sphere capability is separate from staging's 2048-square validator. It accepts only 2048×1024 source masks and never selects a handler from a client class name. The independent per-item budget is two analyses, one image, at most six segmentation requests and one evaluation. Ambiguous paid outcomes retain their receipt state; local failure does not reroll.

`grass_workflow_enabled` remains false. Enabling also requires complete credentials and hash-bound validated assessment/composition runtimes. Runtime manifests bind the PHP preparation/context/adapters and frozen Python code. Local composition and evaluator completion do not create human acceptance records.

## Verification

Run the focused PHP files `LabGrassSegmentationTest`, `IguideLabGrassAssessmentTest`, `IguideLabGrassContextTest`, `IguideLabGrassArtifactBindingTest` and the existing `IguideLabMachineSegmentationTest`. Run `tests/Python/test_grass_assessment_bridge.py` for the filesystem bridge. Do not run two Laravel test processes against the same worktree's fake storage simultaneously.

The opt-in Linux integration harness uses a new disposable directory and intercepts every HTTP call. It runs real ZIP import, GD preparation, pinned Python context/assessment/native composition/evidence and durable PHP records. It does not contact a provider or establish real-scene quality:

```bash
IGUIDE_TEST_PYTHON=/absolute/pinned/runtime/bin/python \
IGUIDE_TEST_AUTOLOAD=/absolute/read-only/vendor/autoload.php \
php tests/Integration/grass_workflow.php /absolute/new/private-test-directory success
```

Use a different new directory for `empty-lawn` and `empty-paving`. These scenarios must stop before later role spending or composition. The success scenario also verifies source-only SAM inputs, reversed mask order, terminal replay, source/candidate/preparation/mask/receipt/association tampering, composed alpha/report drift, seven-board order and absence of manual acceptance. Test manifests are explicitly fixture-only, not deployment readiness evidence.

The frozen native worker has separate 60 MP synthetic resource validation. The full-chain fixture is 4096×2048; neither proof is a fresh autonomous property success. Fresh-source evaluation and explicit enablement remain separate work.
