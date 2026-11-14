# Automatic lab workflows

This private, capability-scoped API adds durable background editing without changing the existing schema 5 project index. `GET /api/iguide-lab/workflows` returns `{data:{capabilities,workflows}}`. Only use recipes whose capability is ready. The initial implementation supports twilight and golden-hour lighting; other presets stop with `needs_review` before any provider request.

Reserve a UUID before posting the normalized source choices:

```json
{"requestId":"UUID","items":[{"caseId":"UUID","preset":"twilight","style":null,"instructions":"Keep the existing building and surrounding details."}]}
```

`POST /api/iguide-lab/workflows` returns HTTP 202 with `{data:workflow}`. The same UUID and choices return the existing record; changed choices or source bytes return 409. Each workflow accepts 1–20 owned full panoramas, with at most 2,000 characters of original instructions per item. Poll `GET /api/iguide-lab/workflows/{id}`. After an uncertain response, recover the existing UUID from the index instead of inventing another request. `POST /workflows/{id}/cancel` with an empty body blocks new paid claims; an already claimed provider request may still finish and retain its receipt.

The public stages are Preparing, Editing, Checking, Ready, Needs review, Failed, and Cancelled. Internal states are `queued`, `analyzing`, `preparing`, `editing`, `checking`, `repairing`, `ready`, `needs_review`, `failed`, and `cancelled`. Ready means `passed_automated_checks`; it never creates an operator acceptance review or claims cross-view consistency. A held candidate can still have private preview/native assets for comparison and diagnosis.

Every child UUID is persisted before dispatch. Each job advances one item and at most one heavy native phase; immutable composition and evidence are reused while evaluation waits. The server binds source bytes, preparation, recipes, runtime code, and evidence before paid claims. Ambiguous provider submissions stop rather than retry. Explicit quota exhaustion can use the configured fallback credential under the same request and cancellation guard. Repairs are bounded to three image attempts and two scene proposals per item. Existing image, source, and capability quotas remain in force.

Lighting results are derived artifacts: a bounded low-frequency field from the generated 2K image changes the immutable source panorama while preserving its spatial detail. Native canvas size does not mean native generated detail. A fixed angular nadir policy, numerical QA, and independent source/candidate visual checks are recorded. The evaluator checks the original user's source/preset/style/instructions, retaining planner uncertainty separately. No model output alone creates acceptance, and no manual mask or intervention is fabricated.

The runtime is separately enabled only after the private manifest binds the validated worker, geometry, source preflight, lighting implementation, and GD preparer hashes. Missing or changed dependencies fail closed. All assets stay on the shared private Studio disk with 2770 directories and 0660 files. Workflow, scene-analysis, image, and evaluation records retain separate provenance; automatic scene-plan use is explicitly marked as automatic instead of masquerading as a manually applied draft.
