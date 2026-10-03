<?php

namespace App\Services\Studio;

use App\Models\ShootEditingDispatchItem;
use App\Models\ShootFile;
use App\Models\ShootFileVersion;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Shoots\MediaVersionPublisher;
use Illuminate\Support\Facades\Storage;

class ScopedWorkspacePublisher
{
    public function publish(StudioWorkspace $workspace, array $media, array $output, string $outputKey): ?ShootFile
    {
        $key = 'studio:'.$workspace->id.':'.hash('sha256', $outputKey);
        $existing = ShootFileVersion::where('request_key', $key)->first();
        if ($existing) return $existing->published_file_id ? ShootFile::find($existing->published_file_id) : null;
        $group = collect($workspace->operation['providerState']['full-shoot-groups'] ?? [])->firstWhere('mediaId', $media['id']);
        $ids = array_map('intval', $output['sourceFileIds'] ?? $group['sourceFileIds'] ?? $media['stackFileIds'] ?? [$media['fileId'] ?? 0]);
        $items = ShootEditingDispatchItem::where('dispatch_id', $workspace->editing_dispatch_id)->where('workspace_id', $workspace->id)->where('destination', 'ai')->get()
            ->filter(fn ($item) => count(array_intersect(array_column($item->sources, 'id'), $ids)) > 0);
        abort_unless($items->isNotEmpty(), 409, 'The AI result does not match the dispatched source files.');
        $sources = $items->flatMap(fn ($item) => $item->sources)->unique('id');
        $source = ShootFile::where('shoot_id', $workspace->shoot_id)->findOrFail($sources->first()['id']);
        $path = Storage::disk('public')->path($output['path']);
        $version = app(MediaVersionPublisher::class)->stage($source, $path, pathinfo($source->filename, PATHINFO_FILENAME).'-edited.jpg',
            (int) $sources->first()['version'], User::findOrFail($workspace->created_by), $key, [
                'origin' => 'ai', 'dispatch_item_id' => $items->first()->id, 'dispatch_item_ids' => $items->modelKeys(),
                'source_versions' => $sources->pluck('version', 'id')->all(),
                'ai_editing_metadata' => ['workspace_id' => $workspace->id, 'output_key' => $key, 'source_file_ids' => $ids,
                    'provider' => data_get($workspace->operation, 'routing.'.$workspace->preset_id.'.provider'), 'workflow' => $workspace->preset_id],
            ]);
        return $version->published_file_id ? ShootFile::find($version->published_file_id) : null;
    }
}
