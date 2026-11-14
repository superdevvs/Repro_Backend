# Private scan ingestion, version 1

This feature imports source files only. It never calls an image, segmentation or analysis provider and never accepts an edited result. The existing capability authorization scopes every endpoint to one active lab project. The schema 5 project index is unchanged.

## Supported source evidence

- Generic ZIP: full 2:1 JPG, PNG or WebP images. Directory and image basenames do not become room names. Optional strict `panoramas.json` (or a separately uploaded metadata file) supplies explicit IDs/names. Paths in the manifest are exact archive paths, including an optional wrapper directory.
- iGUIDE sphere export: observed `*/spheres/p<ID>-s.jpg` files, optionally accompanied by strict tour JSON or HTML containing one inert `viewObject: {...}` JSON value. `dataV2.buildings[].floors[].layout` supplies rooms and panos. Exported panorama IDs are matched to that metadata; no JavaScript executes. The retained real 43-panorama archive was independently inventoried with 27 assigned rooms and one unassigned exterior panorama.
- Offline viewer ZIP: the same observed full-sphere paths plus supported inert metadata. Tile-only, obfuscated/script-produced metadata or ambiguous viewer roots return `needs_attention` with an unsupported-format explanation. Arbitrary offline-export compatibility is not claimed.
- Link: `https://youriguide.com/<alias>/` or `/embed/<alias>/`, optionally one `accessToken`. Only that host and the observed same-tour `/doc/spheres.zip` path are requested. DNS must resolve entirely to public addresses; cURL pins a validated address, verifies TLS, disables proxies and redirects, and bounds both time and response bytes. Tokens are encrypted at rest and absent from DTOs/log messages. Unsupported/protected/redirected downloads offer ZIP upload instead. Other provider domains and embedded asset URLs are not followed.

Each source is decoded fully and sequentially. Animated images, corrupt/truncated rasters, nonidentity EXIF orientations, embedded ICC profiles, transparency, unsupported color modes, non-2:1 images and unsupported metadata are rejected before any case is published. The supported source encoding is opaque RGB (8-bit RGB for PNG). Rejections request explicit normalization; no silent color conversion, EXIF rotation, source upscaling, room classification or camera calibration occurs. Exact original bytes remain private; a separately hashed 2048×1024 PNG is used for the import gallery. Aspect/decoder checks do not independently prove that a user-supplied image was captured as a true full sphere.

## Limits and admission

ZIP compressed/expanded: 1 GiB each; 5,000 files; 200:1 maximum compression ratio; 256 MiB structural entry limit. Source panorama: 30 MiB, 2048×1024 minimum, 64 megapixels maximum. Metadata: 8 MiB. At most 80 panoramas/import, additionally constrained by the remaining project case capacity. Existing archive inspection rejects unsafe/traversing paths, case collisions, symlinks, encrypted members, nested archives and executable server files.

Two active imports/project, eight imports/project/day. One active chunk-upload session/project, eight uploads/day, fixed 24-hour expiry. An import stages and validates all source files before a short project-write-locked transaction checks the entire batch capacity and publishes cases plus the completed authoritative mapping together. A failed batch publishes no partial cases. Deploy with the shared `LabCaseCapacity` admission change so every competing source writer uses the same project lock. This branch falls back to the preexisting 80-case limit for isolated testing; it does not raise capacity.

Per-source case IDs are UUIDv5 over the project and a source identity bound to tour/archive, exact entry, native SHA and metadata SHA. Same-source replay reuses only a case with a completed same-project import mapping, matching dimensions, exact owned path and unchanged file SHA. Room keys use the tour/archive, building, floor and original room ID; display names do not define identity. Missing room IDs/names stay null. Generic supplied-manifest authority is distinguished from iGUIDE metadata authority.

All staging lives on the existing private group-writable Studio disk. Archive and metadata staging are deleted after import, retaining native files, previews and hashes. Failed/crashed staging is cleaned without deleting any case-referenced file. Expired chunk uploads are pruned hourly by `iguide-lab:prune-import-uploads`; cleanup only enumerates the exact session-owned directory. Jobs use the existing Studio queue and bounded shared import lock. Workers need sufficient GD memory for sequential 64 MP sources (the production Studio worker is already configured at 1 GiB).

Chunk publication, finalization, assembly and cleanup share a persistent per-upload filesystem lock outside the cleaned staging directory. Its inode is not unlinked by expiry. This serializes the canonical-file/DB-commit window even for concurrent same-index retries; expiry skips a busy session. Terminal transitions acquire the project write lock and compare the existing status, so a late worker failure cannot replace completion. No long decode/assembly occurs under SQLite's write lock. Both jobs time out at600seconds, the middleware lock expires at660seconds, and the committed Studio retry_after is7260seconds; the focused test asserts retry exceeds the lock budget.

## Small archive or link

`POST /api/iguide-lab/imports` accepts either multipart `{requestId, archive, metadata?}` (archive at most 30 MiB) or JSON `{requestId, url}`. The UUID must be retained across transport uncertainty. Same UUID with changed source returns 409; exact replay returns the existing record. Response is 202 `{data: Import}`. `GET /imports` lists the latest 100 project imports; `GET /imports/{id}` polls one.

Import states: `queued`, `inspecting`, `importing`, `completed`, `needs_attention`. Terminal failure requires a corrected new user request; no paid/provider retry exists. `sources` is empty until atomic publication.

## Large ZIP upload

1. `POST /api/iguide-lab/import-uploads` JSON `{requestId, importRequestId, filename, sizeBytes, sha256, metadataSha256?}`. Both UUIDs are stable; SHA-256 is mandatory and lowercase. `metadataSha256` is null/absent when no sidecar is intended. Response 201 `{data: Upload}` includes the fixed 8 MiB chunk size and received indices.
2. `PUT /import-uploads/{id}/chunks/{index}` streams `application/octet-stream`. Headers: `Content-Range: bytes START-END/TOTAL` and `X-Chunk-SHA256`. Every chunk has exactly the announced size except the final chunk. Hash/length/range mismatch is rejected; the same index+bytes is idempotent; changed bytes at a stored index return 409. No whole ZIP is buffered by this service. A private UI bridge must explicitly allow PUT for these paths, enforce same-origin mutation checks and forward only the required range/hash/type headers.
3. `GET /import-uploads/{id}` resumes with `receivedChunks` and `receivedBytes`.
4. `POST /import-uploads/{id}/finalize`, empty JSON or multipart `{metadata}` matching the initially declared sidecar SHA. It returns 202 and queues streaming assembly, rechecking each chunk and full source SHA/size. Only a verified completed assembly is passed to the fixed `importRequestId`. Poll until `importId` appears, then poll that import. Repeated finalization never queues a second assembly. If a worker dies after reservation, it reconciles that exact import ID/hash before doing any further work.

Upload states: `uploading`, `assembling`, `completed` (handed to import), `needs_attention`, `expired`. Uploaded bytes consume no image-run quota.

## Import DTO

```json
{
  "id": "UUID", "requestId": "UUID", "sourceKind": "zip",
  "status": "completed", "error": null,
  "audit": {
    "parserVersion": "scan-import-json-1", "sourceUrl": null,
    "archiveSha256": "SHA256", "metadataSha256": null,
    "format": "generic_360_zip", "providerCalls": 0,
    "archiveBytes": 123, "expandedBytes": 456, "sourceCount": 1,
    "warnings": ["Some panoramas have no authoritative room assignment."]
  },
  "sources": [{
    "caseId": "UUID", "importSourceKey": "SHA256", "sourceSha256": "SHA256",
    "width": 11008, "height": 5504, "panoId": null, "roomId": null,
    "originalRoomId": null, "roomName": null, "floorId": null, "floorName": null,
    "metadataAuthority": "unassigned", "entry": "scan.jpg", "reused": false,
    "previewSha256": "SHA256", "previewDimensions": [2048, 1024],
    "previewAssetPath": "/api/iguide-lab/imports/UUID/sources/UUID/preview"
  }], "createdAt": "ISO8601", "completedAt": "ISO8601"
}
```

Preview assets use the same project capability and `private, no-store`. Native cases use the normal authenticated case asset endpoint. Consumers must verify the completed `IguideLabImport.sources` mapping and actual source bytes, not trust user-uploadable case metadata such as `serverNativeAuthority` alone. The exact native owned path is `iguide-lab/{project}/imports/{creatingImportId}/{caseUuid}.{jpg|png|webp}`. There is no forged native-attachment backlink.

## Generic sidecar example

```json
{"version":1,"propertyName":"Supplied property name","panoramas":[{"file":"scan.jpg","panoId":"1","roomId":"room-1","roomName":"Kitchen","floorId":"ground","floorName":"Ground floor"}]}
```

These names are supplied metadata, not AI-inferred facts. A sidecar is optional; importing files without it is valid and keeps room identity unknown.
