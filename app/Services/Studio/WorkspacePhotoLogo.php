<?php

namespace App\Services\Studio;

use App\Models\StudioWorkspace;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

class WorkspacePhotoLogo
{
    public function store(StudioWorkspace $workspace, string $bytes): array
    {
        $size = @getimagesizefromstring($bytes);
        abort_unless($size && $size[0] * $size[1] <= 4000000, 422, 'Choose a logo under 4 megapixels.');
        $id = (string) Str::uuid();
        $path = $this->path($workspace, $id);
        Storage::disk('public')->put($path, (string) ImageManager::gd()->read($bytes)->orient()->scaleDown(width: 1200, height: 1200)->toPng());

        return ['id' => $id, 'url' => Storage::disk('public')->url($path)];
    }

    public function bytes(StudioWorkspace $workspace, array $recipe): ?string
    {
        if (! isset($recipe['logo'])) {
            return null;
        }
        $id = $recipe['logo']['id'];
        abort_unless(Str::isUuid($id), 422, 'Choose a workspace logo.');
        $path = $this->path($workspace, $id);
        abort_unless(Storage::disk('public')->exists($path), 422, 'Choose an available workspace logo.');

        return Storage::disk('public')->get($path);
    }

    private function path(StudioWorkspace $workspace, string $id): string
    {
        return 'studio/workspaces/'.$workspace->id.'/logos/'.$id.'.png';
    }
}
