# Studio V4 backend verification

Existing Studio endpoints retain their contracts. The persisted V4 workspace API, authorization, queue processing and source previews are implemented in the backend. Contract and runtime instructions are in `studio-workspaces-v4.md`.

Client access is currently paused by the default-off `STUDIO_CLIENT_ACCESS_ENABLED` rollout setting. The workspace API and queued client work enforce this gate, and effective client permissions omit AI Editing without deleting saved grants, drafts or outputs. Staff access is unchanged. The frontend also hides client entry points; a future client launch must deliberately restore both UI and API access. Existing client media-policy tests explicitly enable the rollout to retain coverage of ownership and release rules.

## Checks

- Final combined suite after clip reuse, download and reserved-field protection: **48 tests, 377 assertions passed**, with warnings/risky tests treated as failures. Executed from Linux container storage to avoid Windows bind-mount latency.
- Authenticated attachment download separately passed **1 test, 10 assertions**, including exact bytes, source ownership and missing-file handling.

- Strict regression suite: **59 tests, 616 assertions passed** (workspace, Studio foundation/access/project submission, ReelController, fal queue compatibility and walkthrough payload).
- Permission regression suite: **6 tests, 37 assertions passed**.
- Final workspace subset after authorization recheck hardening: **16 tests, 114 assertions passed**.
- Final HTTP contract regression after optional-string normalization: **17 tests, 135 assertions passed**. Blank and explicit-null prompt/title/subtitle/frame prompt values return strings after create, PATCH, existing-draft GET and list; no generation was triggered.
- Uploaded RAW preview and existing source regression: **28 tests, 241 assertions passed**. RAW conversion was mocked with a generated JPEG fixture; the real preview endpoint served valid JPEG bytes, reused its private cache, rejected cross-owner/traversal/deleted sources and recovered after conversion failure. New uploads and existing workspace reads expose the same authenticated preview URL. No paid provider call was made.
- PHP syntax checks passed; new PHP files formatted with Laravel Pint; `git diff --check` clean.
- Coverage includes client paid/unpaid and released-file checks, team/editor isolation, source alias routes, URL/traversal rejection, duplicate submission, stale draft writes, cancel, terminal provider retry with completed-output reuse, exact photo subset outputs, 6 selected frames from a 52-photo library, 156-source full-shoot persistence, ratio invalidation and review-only saves.

## Live integration evidence

Used only the task's public `frontend/public/studio-assets/hero-before.webp` and `hero-after.webp` fixture images. No customer media, shoot delivery state, external messages or purchases were involved.

- Configured fal photo edit: one request completed and returned a valid image.
- Configured fal outpainting: one request completed and returned a valid expanded image, visually inspected.
- Configured OpenAI vision: one request returned 8 valid labeled normalized boxes.
- Kling start/end-conditioned video: one request completed; FFprobe verified a nominal five-second clip, measured **5.083333 seconds**.
- Real FFmpeg transition + graphic title composition: **1920×1080, 6 seconds**, using synthetic clips.
- Final composition using the generated clip twice locally: **1920×1080, 10 seconds**, with no added transition effect and a graphic lower third. Visually inspected the rendered frame. Reusing the clip incurred no additional provider call.

Evidence is retained under ignored `storage/app/studio-smoke/`: `providers.json`, `photo.jpg`, `outpaint.jpg`, `walkthrough.json`, `walkthrough.mp4`, `render/verification.json`, and `real-render/verification.json` plus `frame.jpg`.

## Local database and runtime

`studio-migrate-local.php` verified that the configured SQLite file was inside the backend workspace, retained a backup at `storage/app/studio-smoke/database-before-v4.sqlite`, and applied the new migration. It refuses unverified/remote databases. No remote migration was performed.

Earlier tests used Docker image `codex-studio-tests:php83-ffmpeg`; the final combined suite ran from a Linux copy of the finalized source and dependencies. The local Compose runtime in `docker/compose.studio-local.yml` is running as `codex-v4-api` and `codex-v4-worker`. The API health check and public generated-media storage return HTTP 200. The finalized image serves the health check in 0.34 seconds; unauthenticated RAW preview requests return 401. Code and dependencies run inside the image while configuration, database and media remain in the workspace. The API accepts uploads up to 128 MB at the PHP runtime boundary, subject to each workflow's smaller application limit. Workspace work runs on the dedicated `studio` database connection and queue with `queue:work studio --queue=studio --tries=3 --timeout=7200`.

Known product boundaries remain explicit: RAW enhancement starts from the embedded full-size JPEG (or existing conversion fallback); this is not HDR bracket fusion. Object detections are approximate boxes, not pixel segmentation. Start/end conditioning encourages continuity, but cannot guarantee a physically accurate camera path. Legacy project history and shoot delivery are separate from new versioned workspace outputs.

## Final frontend contract audit

The source mapper sends canonical `fileId`/`shootId` or upload `mediaRef`, and the backend ignores submitted source URLs. Client source aliases, ownership/release checks, selected frame subsets, prepared-frame ratio/version fields, revision regions and response envelopes match the frontend. API source previews use authenticated browser blob URLs; generated media uses the configured public storage origin. The local worker reports `http://127.0.0.1:8000`, matching the API runtime and Vite proxy.

Photo-batch duration and zero-effect-duration payload mismatches are corrected, along with PATCH-success/POST-failure version recovery. Backend optional-string normalization covers Laravel's actual `ConvertEmptyStringsToNull` middleware. Existing Studio authorization uses the same role/team/owner check for every action string; there is no separate baseline create-vs-view permission policy to apply.

Cross-version reel reuse stores original motion clips privately before finishing and persists an internal workspace/job association. Cache access requires the persisted association and matching owner; copied runtime metadata alone is insufficient. Legacy project requests and template configurations strip reserved workspace/runtime fields before creating jobs. Passing tests cover six initial walkthrough clips, zero new clips for styling, only scenes 3–4 regenerated after scene 4 changes, input/model fingerprint invalidation, forged runtime data and cross-workspace isolation. These cases are included in the final 48-test suite above.
# September 6 provider failure fixes

Production logs identified an outpaint canvas of1600×2845 exceeding fal's2560-pixel limit, and a90×480 region below the image editor's256-pixel minimum. The processor now bounds the entire extended canvas and normalizes small edits without changing their final scope. Terminal provider rejections discard only the rejected request checkpoint and stop automatic retries; transient errors keep existing request IDs. Provider payloads and URLs are excluded from failure messages.

Video responses now include submitted/completed scene counts and rendering phase. Rejected video results can be retried without restarting unaffected requests. The frontend exposes a failed-preparation retry and prevents stale polling responses from rolling back newer state.

- Focused backend aggregate:61 tests,511 assertions passed, including geometry, rejected-result recovery, credential/transient failures, clip reuse and progress isolation.
- Frontend:10 focused unit tests and2 desktop/mobile Playwright flows passed; TypeScript and changed-scope ESLint passed.
- Real configured fal calls through `WorkspaceProcessor`, using an isolated local database and public fixture:1600×1067 landscape extended to900×1600;90×480 region revision returned the original1600×1067 frame. Resumable check: `scripts/studio-provider-fix-check.php`.
- Read-only inspection confirmed existing WAN requests completed. Production FFmpeg assembled two existing clips into a10-second1080×1920 MP4. No new video provider requests were submitted, and the cancelled workspace/job stayed cancelled.
