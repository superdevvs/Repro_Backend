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
        if (! Storage::disk('public')->exists($path)) {
            // Preserve recipes created before logos were separated from worker outputs.
            $path = 'studio/workspaces/'.$workspace->id.'/logos/'.$id.'.png';
        }
        abort_unless(Storage::disk('public')->exists($path), 422, 'Choose an available workspace logo.');

        return Storage::disk('public')->get($path);
    }

    private function path(StudioWorkspace $workspace, string $id): string
    {
        // PHP owns uploaded logos; queue workers only need to read them. A worker's
        // output directory can be readable by PHP without allowing PHP to write it.
        return 'studio/logos/'.$workspace->id.'/'.$id.'.png';
    }
}
