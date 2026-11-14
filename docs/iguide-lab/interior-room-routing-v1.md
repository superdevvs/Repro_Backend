# Interior room routing candidate

This default-off candidate keeps the public preset **Interior design**, the selected existing style, and the original user instructions. It does not replace historical material-only records or reinterpret their holds. No completed result is a human acceptance or a cross-view consistency claim.

An original-only classifier uses the immutable overview and six directional images. Its model/budget is `gpt-6-astra`, medium reasoning, 4000 output tokens. Server-parsed room metadata corroborates the images; unknown metadata remains null. Classification never authorizes image generation or support by itself.

The closed routes are:

| Source | Profile | Bounded result |
| --- | --- | --- |
| Furnished supported room | `existing-materials/1` | Existing two-to-five opaque matte furnishing surfaces through the original material workflow; all source forms and contacts retained. |
| Empty living area | `empty-living-three-piece/1` | One sofa, one coffee table and one chair; unchanged living support core, new route authority. |
| Empty bedroom | `empty-bedroom-platform-three-piece/1` | One low platform bed with integral headboard/simple bedding, plus two bedside tables. The new support core requires actual visible plinth contacts; it never renames living roles or uses furniture-leg hypotheses as bed evidence. |

Partially furnished, unknown, unsupported requested scope, uncertain room identity, metadata conflict, or unsupported optical dependencies stop with retained source findings. A route is fixed before child branch work and cannot switch after a held branch. No branch retry or second image exists.

The persisted reservation ceiling is five analyses and one image before classification. Each currently implemented branch uses four analysis records: classifier plus three branch assessments. **There is no native-detail follow-up, reframe, or fifth analysis implementation.** An unresolved resolution-only question remains held. The unused ceiling is not recovery authority.

Furnishing uses source framing, one exact extraction, actual-cut layout, original-floor segmentation, the single image, realized placement, actual-object segmentation, source-floor contact support, native composition and full independent quality checks. Original floor support precedes the image. Every primary or explicit-quota fallback revalidates the current typed parent receipts, assets and cancellation before the claim. Unknown paid submissions are never retried.

All current vision inputs retain declared `high` transport: original overview 2048×1024, source directions 1024-square, actual cut/candidate 2048-square. The provider may downsample the square images. These are not an original-native detail path and do not guarantee visual acuity. No optics clearance can be inferred from cropping a reflecting surface away.

The first bedroom profile permits opaque matte furniture, clear source floor, no required foreground occlusion or changed reflections, and only tiny local contact attenuation. The source native floor is attenuated separately; generated RGB enters only through bounded actual object alpha. Existing architecture, fixtures, floor texture and untouched panorama pixels remain protected. Broad shadows, hidden contact certification, uncertain reflections and architecture/finish redesign are unsupported.

The final evaluator receives the unchanged original user intent plus the immutable profile constraints, all seven native-derived paired boards, the actual edited view, and retained source framing caveats. Numerical support scores and synthetic fake-provider tests are not real scene/style acceptance.

New read-only output consists of typed `roomRouting` workflow data and `sourceMachinePlan` version `machine-interior-furnishing-plan/1`; public write controls gain no machine-approval or class-selection fields. Strict controller DTO fixtures must be frozen before frontend read support is requested.

## Reader boundary

The main flow remains Add scan → Interior design/style → Generate → Compare/download. Keep the classifier, internal profile selection and typed generation prompt out of primary controls. The new records are provenance, optionally shown in Advanced; they never become client approval inputs.

`roomRouting.version` is `interior-room-routing/1`, with one of the three closed profile IDs or null before a route is bound. It preserves `publicPreset=interior-design`, the item's style and native source SHA. It always reports `nativeDetailFollowupAvailable=false` and `branchSwitchAllowed=false`. Its ordered assessment list uses these phase/version pairs:

| Phase | Version | Successful structured state |
| --- | --- | --- |
| route | `interior-source-room-classifier/1` | `route_candidate` inside `result.route` |
| framing | `interior-room-source-framing/1` | `framing_candidate` |
| layout | `interior-room-source-layout/1` | `ready_for_image` |
| candidate | `interior-room-realized-placement/1` | `ready_for_masks` |

Record lifecycle is separate from its structured finding: queued, submitting, received, completed or needs_attention. A completed record may contain a semantic needs_review result. Only the final workflow/evaluator determines Ready. Assessment DTO flags `imageGenerationAuthorized`, `nativeCompositionAuthorized` and `automaticAcceptance` are false.

The closed `provenance` version is `interior-room-assessment-read/1`. It binds native case, optional cut/section, original authority and intent hashes, optional route/framing/layout receipt references, normalized source geometry, actual section-parent binding, and optional actual candidate run/hash. The candidate refers to its verified layout for the section-parent context; it does not substitute a new context. These descriptors have no private filesystem paths.

Strict readers must retain and cross-check all these fields: workflow/item/request/step/kind in the invocation; native source SHA across item, record and invocation; raw cut SHA across layout/candidate, section and run; prepared SHA as a distinct identity; native/cut/section IDs against actual section-parent authority; route/profile/style and parent receipt IDs/hashes; candidate run/output SHA against the actual completed run. `sourceGeometrySha256` is the normalized support geometry file, not the section engine's geometry implementation hash. Unknown versions, wrong phase/version pairs, mixed profiles, role sets, source hashes, parent IDs or candidate provenance must reject, rather than strip fields or reinterpret records as legacy scene analysis.

The machine image read shape remains distinct from `sourceAnalysis`; its `sourceMachinePlan` has `humanReviewed=false`, the exact layout receipt, original floor receipt hash, route binding and three correct profile target IDs. Public write `promptMode` remains only preset/scene-plan. No public classifier, profile, sourceMachinePlan or manualAcceptance write is added. Old workflow/read shapes remain valid and unchanged. When the new flag is selected but its runtime is unavailable, a new request cannot silently fall back to the old material route; historical legacy-policy records keep their original path.

## Furnished-route validation cost

A measured synthetic four-kilopixel chain took 397.825 seconds on the unchanged material path and 757.872 seconds with the initial room adapter. The added cost was repeated source-route/parent validation, not repeated panorama projection. Both baselines and the earlier 600-second interrupted run remain retained; these timings are local mechanics, not provider latency or product throughput promises.

For the new room material route only, `LabInteriorRoomReadScope` deduplicates already verified route and material parent records inside one synchronous top-level `completed()` traversal. It clears in `finally`, returns independent model instances, segregates source/item/style/intent, and is never retained across phase, job or request boundaries. Paid-attempt verification suspends and clears this scope before and after every primary/fallback check. Original bytes, source authority, raw receipts and payloads are freshly verified again at those boundaries. Legacy material policies do not activate the scope. No image, mask, native worker or geometry algorithm is changed by this optimization.
