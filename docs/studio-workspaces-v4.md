# Studio V4 workspaces

All endpoints use Sanctum and return `{success:true,data:...}`. Existing Studio project, job and management APIs are unchanged. Workspaces are persisted separately; legacy projects can remain in the existing history view.

## API

Base `/api/studio/workspaces`. GET lists the latest 100 authorized workspaces; POST creates a draft; GET/PATCH `/{id}` loads/saves. POST `/{id}/prepare`, `/generate`, `/revisions`, `/cancel`, `/segments` perform the named operation. Creation and queued operations accept `requestId` or `Idempotency-Key` (64 characters maximum). PATCH optionally takes `version`, with 409 on stale edits. An in-flight operation prevents draft mutation; cancel first.

The camelCase payload is `{name,presetId,media:[{id,fileId?,shootId?,mediaRef?}],config:{prompt,ratio,duration,transition,transitionDuration,text:{title,subtitle,style,position,timing},adjustments,frames:[{mediaId,method,duration,prompt?}]}}`. Client URL/name/type hints are ignored; source URLs and identity are resolved on the server. Authorized uploads use the existing `studio/uploads/{team}/{user}/...` namespace. URL-only sources, traversal paths, another user's uploads, unrelated shoot files, quarantined/hidden files and unreleased/unpaid client media are rejected.

Response fields: `id,name,presetId,media,config,status,progress,error,version,outputs,preparedFrames,history,createdAt,updatedAt,capabilities`. Output and prepared-frame records have stable operation IDs, exact `mediaId`, `url,thumbnailUrl,kind,status,version`; prepared frames also include `method,ratio`. Outputs retain historical versions. `history` retains the last 100 completed operations and feedback scope.

Revisions accept `{mediaId,prompt,region?:{x,y,width,height},drawing?:[{x,y}[]]}` with normalized coordinates. A region edits only that rectangle and composites the result back into the original. Drawings constrain the bounding rectangle around the strokes; they are not pixel masks. POST segments returns an array `{id,label,region}` of approximate OpenAI visual detections, not pixel-accurate segmentation. Precise localization remains a model limitation; see [official OpenAI vision documentation](https://developers.openai.com/api/docs/guides/images-vision).

Source aliases (including clients): GET `/sources/shoots?q=...`, GET `/sources/shoots/{shoot}/media?workflow=photo-enhancement|listing-video|reel-generator`, POST `/sources/uploads` multipart `workflow` + `files[]`. POST `/sources/resolve` takes the prior deep-link shape `{destination,recordType:'shoot',recordId}` and returns `data.record`. Existing staff-only administration/source endpoints retain their original roles.

Uploaded RAW media exposes an authenticated JPEG preview at GET `/sources/uploads/preview?mediaRef=...`. Upload responses and saved workspace media both use this URL, including older drafts. The endpoint rechecks exact upload ownership and source existence before reading a private local cache. It extracts a JPEG through the existing RAW converter and limits the browser preview to 2048 pixels on its longest side. Source changes invalidate the cache; failed conversion returns 422 and remains retryable. Preview generation does not call an AI provider or alter the original RAW file.

## Processing

Presets: listing-ready, color-correction, twilight, green-grass, virtual-staging, full-shoot, walkthrough, property-reel, social-teaser. Photos use configured fal image editing with concrete preset prompts. RAW files use their embedded full-size preview before individual enhancement; HDR bracket merging is not claimed. Photo generation processes `config.frames[].mediaId` when present, otherwise all selected photos. A workspace retains up to 300 source files; a reel selects up to 12 from that library. Upload large libraries in bounded multipart batches.

Preparation: Crop performs an actual image crop; Fit preserves the entire image with a neutral matte; Extend invokes fal outpainting for the missing edges. Every selected storyboard source must have a completed prepared frame before generation; unselected library photos are not processed. Ratio/source/framing changes invalidate preparation. Changing text, motion, review choices or styling does not discard prepared pixels.

Video uses ordered frames and per-frame motion prompts. Walkthrough uses Kling 2.5 Turbo's start/end-image conditioning between neighboring prepared frames, followed by a final shot of the last frame. This encourages matching joins but does not guarantee a physically coherent camera path. Other video presets use configured fal image-to-video. `none` adds no transition effects.

Finishing uses FFmpeg with aspect ratios 9:16,16:9,1:1,4:5 and the requested duration. Per-frame durations are weights normalized to the total. Optional transitions: fade, fadeblack, dissolve, slideleft/right, smoothleft/right, wipeleft/right. Transition handles preserve the total duration. Text styles: none, minimal, editorial, lower-third, graphic; position top/center/bottom; timing last-scene (first 3 seconds of final scene) or all (first 3 seconds of every scene). Text is written to local files with FFmpeg expansion disabled; no user-controlled filter fragments or shell commands are accepted.

Provider request IDs, completed source outputs, and reel clip URLs are checkpointed. Retrying the same failed request resumes retained work. Cancellation prevents later job writes from resurrecting the workspace; it cannot refund provider work already submitted. No deliver/share action changes a shoot's delivered status. The generated output URL can be downloaded/shared explicitly; existing shoot delivery APIs remain separate.

Completed reel versions retain an internal job association and a private cache of original motion clips before finishing. A later version reuses a clip only from the same workspace and owner when its effective start/end image references, motion prompt and provider model match. Text, transitions and output-duration changes rerender locally without resubmitting unchanged motion. Revising walkthrough scene 4 invalidates scene 4 and the preceding join into it; the other four clips remain reusable. Independent-shot presets invalidate only the revised scene. Missing caches or older untracked jobs are conservatively regenerated. Clients cannot supply runtime clip references or cache paths.

## Runtime

Run migrations, then a queue worker with `php artisan queue:work studio --queue=studio --timeout=7200 --tries=3`. Workspace jobs use `STUDIO_QUEUE_CONNECTION` (default studio), independently of a legacy sync queue configuration. The dedicated connection uses queue `studio` and `retry_after=7260` to avoid duplicate worker reservations. Keep the database worker running under a process supervisor.

Required: PHP 8.2+, GD, FFmpeg, FFprobe, ExifTool for RAW previews, and a system font. `docker/StudioWorker.Dockerfile` installs these production dependencies. `FAL_KEY` and `OPENAI_API_KEY` remain server-side. Optional `FAL_OUTPAINT_MODEL` defaults to `fal-ai/flux-2-pro/outpaint`; `FAL_WALKTHROUGH_MODEL` defaults to `fal-ai/kling-video/v2.5-turbo/pro/image-to-video`; `FAL_REEL_FONT` can point to an installed font. `php scripts/studio-runtime-check.php` prints presence booleans only.

Provider schemas verified against [fal outpainting](https://fal.ai/models/fal-ai/flux-2-pro/outpaint/api), [Kling start/end-frame video](https://fal.ai/models/fal-ai/kling-video/v2.5-turbo/pro/image-to-video/api), and [FFmpeg filters](https://ffmpeg.org/ffmpeg-filters.html). No browser receives provider credentials.
# Production worker installation

After deploying the API and migration, run `bash scripts/install-studio-runtime.sh` as the existing `maverick` deployment account on `/var/www/backend`. The installer downloads exact FFmpeg package versions from the host's authenticated Ubuntu APT indexes into `~/.local/share/repro-studio`, leaving system packages unchanged. It verifies FFmpeg/FFprobe, the H.264 encoder, transition/text filters, and the render font. `--prepare-only` installs and checks the renderer before the API release.

The dedicated user-owned Supervisor runs the `studio` connection and queue with a 7200-second timeout. It switches to the existing `www-data` group and uses group-writable output permissions. The root-owned mail/default worker stays independent. A preserved user crontab starts Supervisor at boot and checks it each minute, so it does not depend on an open SSH session or user-systemd linger. Logs rotate in `~/.local/share/repro-studio/logs`; inspect status with `supervisorctl -c ~/.local/share/repro-studio/supervisord.conf status`.

Before reverting to pre-V4 code, create `~/.local/share/repro-studio/paused`, drain/stop `repro-studio` through that Supervisor, and retain the database, queued jobs, and storage for roll-forward. Remove the pause marker and start the program after restoring V4. Do not run the migration's destructive `down()` during a source rollback. `public/.user.ini` raises PHP-FPM's memory ceiling for large listing photos while preserving the existing upload limits.
