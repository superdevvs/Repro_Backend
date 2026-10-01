<?php

namespace App\Services\Studio;

use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\StudioWorkspace;
use App\Services\Shoots\BracketModeResolver;

/** Group only authoritative shoot stacks; identical stack numbers in different services are unrelated. */
class FullShootPhotoGroups
{
    public function forWorkspace(StudioWorkspace $workspace): array
    {
        $media = collect($workspace->media ?? []);
        $files = ShootFile::whereIn('id', $media->pluck('fileId')->filter())->get()->keyBy('id');
        $services = ShootService::with('service')->whereIn('id', $files->pluck('shoot_service_id')->filter())->get()->keyBy('id');
        $groups = $media->groupBy(function ($item) use ($files, $services) {
            $file = $files->get($item['fileId'] ?? null);
            $service = $services->get($file?->shoot_service_id);
            if ($file && $file->bracket_group && ($item['kind'] ?? '') === 'raw'
                && (! $service || app(BracketModeResolver::class)->serviceUsesBrackets($service))) {
                return 'stack:'.$file->shoot_id.':'.$file->shoot_service_id.':'.$file->bracket_group;
            }
            return 'single:'.$item['id'];
        });

        return $groups->map(function ($items) use ($files) {
            $items = $items->sortBy(fn ($item) => $files->get($item['fileId'] ?? null)?->sequence ?? 0)->values();
            $representative = $items[intdiv($items->count(), 2)];
            return [
                'mediaId' => $representative['id'], 'sourceMediaIds' => $items->pluck('id')->all(),
                'sourceFileIds' => $items->pluck('fileId')->filter()->values()->all(),
                'name' => $items->count() > 1 ? pathinfo(($representative['name'] ?? 'Photo'), PATHINFO_FILENAME).'-HDR.jpg' : ($representative['name'] ?? 'Photo'),
            ];
        })->values()->all();
    }
}
