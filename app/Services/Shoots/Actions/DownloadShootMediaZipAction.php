<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootMediaArchiveService;
use App\Services\Shoots\ShootClientReleaseAccessService;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DownloadShootMediaZipAction
{
    public function __construct(
        protected ShootMediaArchiveService $shootMediaArchiveService,
        protected ShootClientReleaseAccessService $shootClientReleaseAccessService,
        protected ShootFileAccessService $shootFileAccessService,
        protected ShootAuthorizationSupport $shootAuthorizationSupport
    ) {
    }

    public function execute(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        if (!$this->shootAuthorizationSupport->canDownloadShootMedia($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Editors use the same async archive pipeline as other production roles.
        // Per-file authorization below still enforces assignment scope for editors.
        return $this->executeArchiveDownload($request, $shoot, true);
    }

    public function executePublic(Request $request, Shoot $shoot)
    {
        return $this->executeArchiveDownload($request, $shoot);
    }

    protected function executeArchiveDownload(Request $request, Shoot $shoot, bool $authorizeFiles = false)
    {
        $validated = $request->validate([
            'type' => 'nullable|in:raw,edited',
            'size' => 'nullable|in:original,small',
            'shoot_service_id' => 'nullable|integer|exists:shoot_service,id',
            'shoot_unit_id' => 'nullable|integer|exists:shoot_units,id',
            'include_extras' => 'nullable|boolean',
            'media_types' => 'nullable|array',
            'media_types.*' => 'string|max:40',
            'asset_type' => 'nullable|in:photos,videos,floorplans',
        ]);

        $baseType = $validated['type'] ?? 'raw';
        // Extras are excluded by default so "Download all" never packages media
        // nobody ordered; callers opt in explicitly. media_types lets a per-tab
        // download restrict the archive to that tab's media.
        $includeExtras = $request->boolean('include_extras', false);
        $mediaTypes = array_values(array_filter(
            (array) ($validated['media_types'] ?? []),
            fn ($value) => is_string($value) && $value !== ''
        ));
        $type = $this->shootMediaArchiveService->buildArchiveTypeToken($baseType, $includeExtras, $mediaTypes, $validated['asset_type'] ?? null);
        $requestedSize = $validated['size'] ?? 'original';
        $resolvedSize = $this->shootMediaArchiveService->normalizeSize($requestedSize);
        $shootServiceId = isset($validated['shoot_service_id']) ? (int) $validated['shoot_service_id'] : null;
        $shootUnitId = isset($validated['shoot_unit_id']) ? (int) $validated['shoot_unit_id'] : null;

        if ($shootUnitId !== null && ! $shoot->units()->whereKey($shootUnitId)->exists()) {
            return response()->json(['message' => 'Selected unit does not belong to this shoot'], 422);
        }

        if ($shootServiceId !== null && !$shoot->serviceItems()->whereKey($shootServiceId)->exists()) {
            return response()->json(['message' => 'Selected service item does not belong to this shoot'], 422);
        }
        if ($shootUnitId !== null && $shootServiceId !== null && ! $shoot->serviceItems()->whereKey($shootServiceId)->where('shoot_unit_id', $shootUnitId)->exists()) {
            return response()->json(['message' => 'Selected service item does not belong to this unit'], 422);
        }

        $releaseScopes = $shootUnitId !== null && $shootServiceId === null
            ? $shoot->serviceItems()->where('shoot_unit_id', $shootUnitId)->where('is_deliverable', true)->where('workflow_status', '!=', 'cancelled')->pluck('id')->all()
            : [$shootServiceId];
        foreach ($releaseScopes as $releaseScope) {
            if ($this->shootClientReleaseAccessService->isArchiveReleaseLocked($shoot, $releaseScope, $request->user())) {
                return $this->shootClientReleaseAccessService->downloadLockedResponse();
            }
        }

        if ($authorizeFiles) {
            if ($this->shootAuthorizationSupport->isClientUser($request->user()) && $baseType === 'raw') {
                return response()->json(['message' => 'Raw files are not available for client download.'], 403);
            }

            // Cached studio archives are shared objects. Check every member before
            // returning their URL so a service assignment cannot unlock other lanes.
            foreach ($this->shootMediaArchiveService->getFilesForType($shoot, $type, $shootServiceId, $shootUnitId) as $file) {
                if (! $this->shootAuthorizationSupport->canDownloadShootMediaFile($shoot, $file, $request->user())) {
                    return response()->json([
                        'message' => 'This archive contains files you cannot access. Download an assigned service or select accessible files.',
                    ], 403);
                }
                if ($this->shootClientReleaseAccessService->isFileReleaseLocked($shoot, $file, $request->user())) {
                    return $this->shootClientReleaseAccessService->downloadLockedResponse();
                }
            }
        }

        try {
            $archiveResponse = $this->shootMediaArchiveService->resolveArchiveResponseData(
                $shoot,
                $type,
                $resolvedSize,
                $request->fullUrl(),
                $shootServiceId,
                $shootUnitId
            );

        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            Log::error('Failed to resolve shoot archive download', [
                'error' => $e->getMessage(),
                'shoot_id' => $shoot->id,
                'type' => $type,
                'size' => $requestedSize,
            ]);

            return response()->json(['error' => 'Failed to prepare ZIP file'], 500);
        }

        // Always return JSON redirect/preparing. Streaming multi-GB ZIPs through
        // PHP as BinaryFileResponse caused multi-minute holds and 500s.
        return response()->json($archiveResponse['payload'], $archiveResponse['status']);
    }

}
