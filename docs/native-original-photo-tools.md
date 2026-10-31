# Original photo tools contract

The additive `controls.originalPhotoTools` catalog flag and workspace `capabilities.originalPhotoTools` flag allow local photo tools before an AI result exists. Historical targets `{mediaId, outputId}` retain their exact-output behavior.

## Open original tools

Persist an authorized photo workspace, then call:

```json
POST /api/studio/workspaces/{id}/edit-preview
{"version":1,"mediaId":"photo-id","original":true,"edits":{"exposure":0}}
```

The private, noncached JPEG is oriented and at most 1000px on either edge. Display these pixels and retain the `X-Studio-Source-Version` response header. This opaque 64-character lowercase hex token pins the selected original's identity and decoded bytes. A plain source thumbnail is not an original-tools version receipt.

For subsequent adjustment previews send `sourceVersion` alongside `mediaId`, `original`, `version` and `edits`. A replaced source returns 409 even if workspace version did not change. Coordinates are normalized to the oriented original before crop, horizontal flip and clockwise rotation.

## Save deterministic adjustments

```json
POST /api/studio/workspaces/{id}/edits
{"version":1,"requestId":"stable-uuid","edits":{"exposure":0.5},"targets":[{"mediaId":"photo-id","original":true,"sourceVersion":"token-from-preview"}]}
```

The operation creates a new completed image output without changing source files or submitting a provider request. Original and historical targets may be mixed; an original target cannot also contain `outputId`. Each original requires its own preview token. Existing limits on per-photo area selections still apply.

## Refine an original area

```json
POST /api/studio/workspaces/{id}/revisions
{"version":1,"requestId":"stable-uuid","mediaId":"photo-id","original":true,"sourceVersion":"token-from-preview","customEdit":true,"prompt":"Remove the chair","region":{"x":0.2,"y":0.2,"width":0.4,"height":0.4}}
```

This uses the configured custom revision provider and preserves the exact selected original as its source. Do not submit automatically. Existing permission, provider availability, reference-image and region validation remains effective.

## Recovery and authorization

Original mutations require the current workspace version and a matching source token. Exact accepted request-key replays return the same operation receipt even after its version advances. A conflicting payload under that key returns 409. Failed requests resume with the original server-owned source receipt and provider checkpoints; they never recapture a replacement source. A stale new request returns 409.

The server records source identity and a decoded-byte SHA256 outside the SQLite write transaction, rechecks identity/version under reservation, and reauthorizes current plus retained sources in the worker. The worker verifies identity and bytes again before editing. Private source receipts are not included in public workspace DTOs. Changed, revoked, unavailable or unready HDR sources fail closed. RAW inputs use the existing supported full-size embedded JPEG extraction; original RAW files remain untouched.
