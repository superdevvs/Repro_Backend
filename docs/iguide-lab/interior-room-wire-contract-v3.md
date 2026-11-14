# Interior furnishing request contract 3

This changes only the producer request for the existing framing, layout and actual-candidate phases. Public response versions, the parser, canonical hashing, profile definitions, workflow budgets, image preparation, support and final quality gates are unchanged. Failed historical receipts remain failed. A semantic hold remains a hold.

The observed request-2 layout response copied its prior framing pose into `view`. The unchanged parser rejected it with `Held or actual-cut result has unauthorized framing.` An offline diagnostic that changed only that field to null parsed as `needs_review`, with the original fit and optical concerns intact. Request 3 makes every layout/candidate `view` schema null and states the rule for both success and held outcomes.

## Complete field and phase audit

| Invariant | Request enforcement | Unchanged parser enforcement |
| --- | --- | --- |
| Exact phase, context and profile | Phase version enum; separate exact context-hash binding and one-value enum; one-value bound profile enum | Exact version, canonical context hash, bound profile |
| Framing pose | Bounded pose or null; prompt permits pose only for `framing_candidate` | Successful framing must have a valid pose; held framing must use null |
| Actual-cut pose | Null schema for every layout/candidate result | No actual-cut result can authorize a framing |
| Framing collections | Objects and styles max 0; floor null schema | Objects/styles empty and floor null |
| Successful/held collections | Prompt: success exactly 3 profile-ordered objects, floor, 2–5 styles; hold empty objects/styles and null floor | Exact role order/count, floor and style bounds; no held mask authority |
| Profile roles and style targets | Per-request IDs restricted to the bound profile; explicit unique IDs/order | Exact object role sequence; unique nonempty style target IDs |
| Layout/living contact segments | Layout schema max 0; living candidate per-request max 0 | No prospective contacts or borrowed bedroom support |
| Bedroom candidate segments | Per-request 1–3 segments per object; integer points 205–1842 | Endpoints inside each actual object box; segment length 8–600 |
| Original source floor | Prompt requires candidate to copy prior `layout.result.sourceFloor` exactly; held floor is null | Entire candidate floor object must canonically equal the prior selection |
| Check names/statuses | Exact required phase check keys; phase-specific status enums | All required keys; success forbids blocked/uncertain/failed checks |
| Evidence IDs | Phase enum, list limit equal to supplied view count; optical list exact count; explicit uniqueness and source/candidate citations | Unique allowed IDs; source in every check, candidate in candidate checks, every supplied view in optical check |
| Foreground/optics/shadows | Framing `not_assessed`; later closed status enums; explicit supported-success/held rules | Success requires `none_observed`, `no_required_changes`, `tiny_local_only` |
| Object/floor boxes | Four integer coordinates with existing bounds; explicit `[xMin,yMin,xMax,yMax]` order | Strict ordered extents, object margin and original-floor bounds |
| Text and style | Existing nonblank/max lengths, bounded style kinds, non-colour style instruction | Trimmed nonempty text; 900/550/300/220/160 limits; at least one non-colour feature |
| Input/parent authority | Unchanged source, prepared image, ordered views and retained parent context | Exact bytes/dimensions/receipt hashes and phase ancestry; no client authority |

The portable schema subset deliberately does not attempt root-level conditional branches, `if/then`, `contains`, `uniqueItems`, coordinate arithmetic or dynamic equality between separate response fields. Status-conditioned collection counts, mandatory evidence members/uniqueness, role order, coordinate order, contact length/containment, prior floor equality and non-colour style content remain explicit instructions plus unchanged parser checks. Schema validation is necessary but never sufficient for eligibility. Actual contact coverage, occlusion, optical dependencies, native composition and independent quality remain downstream gates; no new clearance or acceptance claim is introduced.

The classifier is unchanged: its existing source-bound identity fields are supplied directly and checked separately. Request 3 applies only after the classifier selects an existing furnishing profile. The model, medium reasoning, 6,000 output-token ceiling, image detail/order/bytes and one-image budget are unchanged. The private request identity and implementation/schema/instruction hashes require one room-assessment runtime-manifest refresh before any future activation; this document grants no deployment or provider authorization.
