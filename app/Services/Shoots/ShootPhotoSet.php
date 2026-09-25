<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\StudioWorkspace;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** The authoritative original photo set, independent of media-panel filters and pagination. */
class ShootPhotoSet
{
    public function files(Shoot $shoot): Collection
    {
        $access = app(ShootAuthorizationSupport::class);

        return $shoot->files()->where('workflow_stage', ShootFile::STAGE_TODO)->orderBy('id')->get()
            ->filter(fn (ShootFile $file) => ! $file->is_hidden && ! $file->is_ai_edited
                && ! in_array($file->media_type, ['floorplan', 'iguide'], true)
                && ($access->isRawCameraFile($file) || $access->isImageMediaFile($file)))->values();
    }

    public function assertFullWorkspace(StudioWorkspace $workspace): void
    {
        if ($workspace->preset_id !== 'full-shoot' || ($workspace->operation['type'] ?? 'generate') !== 'generate') {
            return;
        }
        $media = collect($workspace->media ?? []);
        $frames = collect($workspace->config['frames'] ?? [])->pluck('mediaId');
        if ($media->isEmpty() || ($frames->isNotEmpty() && $frames->sort()->values()->all() !== $media->pluck('id')->sort()->values()->all())) {
            throw ValidationException::withMessages(['media' => 'Full Shoot requires every photo. Use photo enhancement for a selection.']);
        }
        foreach ($media->filter(fn ($item) => ! empty($item['shootId']))->groupBy('shootId') as $shootId => $items) {
            $expected = $this->files(Shoot::findOrFail($shootId))->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $actual = $items->flatMap(fn ($item) => $item['stackFileIds'] ?? [$item['fileId'] ?? 0])->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($actual !== $expected) {
                throw ValidationException::withMessages(['media' => 'The shoot photo set changed or is incomplete. Select all original shoot photos for Full Shoot.']);
            }
        }
    }
}
