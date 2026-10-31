# Studio video finishing controls

These options apply to `property-reel`, `walkthrough`, and `social-teaser` workspaces. They use the existing selected/prepared frames (maximum 12) and finishing pipeline. They do not submit additional motion requests when durable clips can be reused. Originals and earlier output versions are retained.

## Workspace configuration

```json
{
  "resolution": "1080p",
  "audio": {
    "original": false,
    "musicId": null,
    "musicVolume": 0.35,
    "fade": true
  }
}
```

Defaults preserve silent 1080p exports. Resolution accepts `720p` or `1080p` and specifies the shorter edge; the existing `ratio` selects 9:16, 16:9, 1:1, or 4:5. Duration remains 5–120 seconds and existing per-frame duration weights remain supported. Original audio is retained only if a generated source clip actually contains audio. No bundled or licensed music collection is supplied. With no actual input, Sound stays off. Volume 0 omits the music track. Fade applies a 0.5-second fade at each end of the final mix.

The workspace's `capabilities.video`, also available to catalog code through `WorkspaceVideoOptions::capabilities()`, advertises the supported controls. This schema is not a successful production binary/provider probe.

## Private audio assets

All endpoints use the existing workspace/role authorization. Audio from another workspace cannot be selected, including by a user who can access both projects.

- `POST /api/studio/workspaces/{id}/audio`: multipart fields `audio` and current `version`. MP3/M4A/AAC/WAV/FLAC, at most 20 MiB, 0.5–600 seconds, actual audio content without a video stream. Metadata-only cover art is allowed. The service transcodes to stereo 48 kHz AAC in M4A, removes metadata, and stores it privately. It performs no provider calls.
- `GET /api/studio/workspaces/{id}/audio`: `{success,data:[asset...],workspaceVersion}`. Includes unused uploaded assets so users can reselect or remove them after relaunch. Stable creation/id order.
- `GET /api/studio/workspaces/{id}/audio/{assetId}`: authenticated private preview, never a public storage URL.
- `DELETE /api/studio/workspaces/{id}/audio/{assetId}`: JSON current `version`. Rejects selected assets, busy workspaces, and stale versions. Deselect through PATCH first. Returns the updated workspace.

Upload responds with HTTP 201 and `{success,data:{id,name,mimeType,durationSeconds,bytes,createdAt,previewUrl,workspaceVersion}}`. Successful upload increments the workspace version. Use this new version in the following workspace PATCH setting `config.audio.musicId`. Upload alone does not change Sound or invalidate the finished output. Busy/stale races delete the unaccepted upload; selecting audio or changing finishing settings invalidates the generation operation while preserving prepared frames and previous exports. At most 20 audio assets are retained per workspace.

## Export and runtime

Every newly composed video output includes actual probed `width`, `height`, `durationSeconds`, and `hasAudio`, plus `resolution`, `originalAudio`, and `musicAudio`. Polling and authenticated output download preserve this output-specific metadata. Older exports may lack these fields; do not infer audio availability from a draft toggle or invent metadata for historical output.

Operators may configure `STUDIO_FFMPEG_PATH` and `STUDIO_FFPROBE_PATH`; defaults are `ffmpeg` and `ffprobe`. Both paths must be executable by the HTTP and Studio worker identities. The build must include libx264, AAC, text/drawtext, xfade, acrossfade, atempo, amix, and afade support. Validate real composition before declaring runtime ready.

Audio lives on `studio_audio` under `storage/app/private/studio-audio`, outside public storage. The dedicated root/workspace directories use 2770 and the configured `STUDIO_AUDIO_GROUP` (default `www-data`); files use 0660. Preserve these permissions during deployment so PHP-FPM and the Studio worker can access the same private assets. Never include user audio in Git or deployment archives.
