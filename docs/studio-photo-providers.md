# AI Editing provider routing

Superadmin → Settings → AI Editing controls each photo workflow, revisions, upscaling, AI Extend and video service. Credentials are write-only and encrypted in `studio_provider_settings`; staff capability responses contain no provider identifiers. The client rollout remains paused.

Saving a connection does not switch any workflow. Add the API key first, deploy, then add the team ID. Both are required before selecting the native photo service. Existing fal.ai routes remain the defaults. Blank credential inputs preserve saved values. Existing fal.ai and OpenAI environment keys are reused.

## Supported execution

- Listing ready, color correction and full-shoot editing can use the native listing → upload → enhance → retrieve-result pipeline. Each shoot gets a separate remote listing. The current source pipeline enhances JPEG images, including available RAW previews; it does not claim native RAW development or automatic bracket/HDR merging.
- Custom instructions and non-default adjustments on native enhancements run as a subsequent edit through the selected revision service. Unchanged controls incur no second revision. Revisions accept up to four authorized workspace reference photos with compatible models.
- Photo presets and revisions can also use the existing fal.ai model, Nano Banana Pro, or GPT Image 2. Sky replacement and perspective correction have explicit presets. They use prompt-based image editing unless a native parameter contract is verified.
- Upscaling uses Clarity Upscaler on the exact selected completed output and saves a separate version.
- AI Extend uses FLUX.2 Pro Outpaint, with GPT Image 2 as the default fallback. The original photograph is composited back into the expanded canvas. Fallback is allowed before submission for missing configuration, or after an unaccepted authentication/account rejection. Timeouts and ambiguous submissions never silently trigger a second paid request.
- A tapered color correction in the generated padding reduces visible joins after restoring the source. Original pixels are unchanged; this does not guarantee perfect scene continuity, so prepared frames remain available for review and revision.
- Videos remain on fal.ai. Accepted work snapshots the selected model; subsequent settings changes do not reroute in-flight work or incorrectly reuse clips from another model.

## Public API boundaries

The Fotello v1 adapter implements the documented create-listing, create-upload, create-enhance, get-enhance, update-enhance, update-asset, create-ai-revision, create-upscale and prepare-download calls. Only the enhancement contract documents retrieval of the exact completed image. The staging/twilight/revision/upscale variant submission responses are not sufficient to identify a final result. Those native routes remain disabled pending a verified result-retrieval contract. Opaque render preferences and undocumented enum values are not guessed.

Existing working providers handle those user-facing features. Exact-version workspace downloads remain available; the remote listing download API is not presented as an exact-version export.

## Recovery and security

Operations persist server-owned provider routes, remote IDs and submission checkpoints. Failed enhancements retain their uploaded source; expired, unused upload slots may be refreshed. Completed uploads and submitted enhancements are never recreated solely because a signed upload URL expired. Changing the configured team blocks an existing native operation until its original team is restored.

An ambiguous paid mutation is deliberately held for reconciliation. Do not clear its checkpoint unless its provider-side outcome has been verified. Provider credentials and raw provider error bodies are not returned to users. Native transfers use HTTPS, public-IP validation and pinned DNS resolution; API authentication is never forwarded to signed storage URLs.

Tests cover access boundaries, encrypted storage, missing-team readiness, model routing, reference authorization, exact-version upscaling, fallback safety, interrupted jobs and mobile settings/revision controls. Provider calls in automated tests are faked.

Sources reviewed 2026-09-08: https://app.fotello.co/api-docs; https://fal.ai/models/fal-ai/flux-2-pro/outpaint/api; https://fal.ai/models/fal-ai/nano-banana-pro/edit/api; https://fal.ai/models/fal-ai/clarity-upscaler/api; https://developers.openai.com/api/docs/guides/image-generation.
