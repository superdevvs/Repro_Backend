<?php

namespace App\Services\Studio;

use App\Exceptions\StudioProviderException;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\StudioWorkspace;
use App\Services\Media\MediaStorage;
use App\Services\Studio\Providers\VirtualStagingAiClient;
use App\Services\Studio\Providers\VirtualStagingAiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use RuntimeException;

/** Runs virtual staging through Virtual Staging AI and stores every finished variation locally. */
class VirtualStagingProcessor
{
    private ImageManager $images;

    public function __construct(private WorkspaceMediaService $media, private StudioProviderSettings $settings)
    {
        $this->images = ImageManager::gd();
    }

    /** @return bool false when the operation was cancelled before it finished */
    public function run(StudioWorkspace $workspace, string $operationId, array $items, bool $singleVariation = false): bool
    {
        $ready = $this->settings->readiness('virtual-staging', $this->settings->route('virtual-staging'));
        if (! $ready['ready']) {
            throw new StudioProviderException($ready['reason']);
        }
        $options = VirtualStagingOptions::normalize($workspace->config['adjustments'] ?? []);
        if ($singleVariation) {
            $options['variationCount'] = 1;
        }
        $client = $this->client();
        $total = count($items);
        foreach ($items as $index => $item) {
            $workspace->refresh();
            if (! $workspace->isBusy() || ($workspace->operation['id'] ?? null) !== $operationId) {
                return false;
            }
            if (in_array($item['id'], $workspace->operation['completed'] ?? [], true)) {
                continue;
            }
            if (! $this->stageItem($workspace, $operationId, $item, $options, $client, $index, $total)) {
                return false;
            }
        }

        return true;
    }

    public function analyze(StudioWorkspace $workspace, array $item): array
    {
        $ready = $this->settings->readiness('virtual-staging', $this->settings->route('virtual-staging'));
        if (! $ready['ready']) {
            throw new StudioProviderException($ready['reason']);
        }
        $analysis = $this->client()->analyze($this->dataUrl($this->media->bytes($item)));
        $resultUrl = $analysis['result_url'] ?? null;
        $percentage = $analysis['percentage_masked'] ?? null;
        if (($analysis['status'] ?? '') === 'error' || ! is_string($resultUrl) || ! is_numeric($percentage)) {
            throw new VirtualStagingAiException('Furniture in this photo could not be detected. Please retry.');
        }
        $bytes = $this->client()->download($resultUrl);
        $png = (string) $this->images->read($bytes)->toPng();
        $path = $this->maskPath($workspace, $item['id']);
        Storage::disk('public')->put($path, $png);

        return [
            'percentageMasked' => round((float) $percentage, 1),
            'previewUrl' => Storage::disk('public')->url($path),
        ];
    }

    private function stageItem(StudioWorkspace $workspace, string $operationId, array $item, array $options, VirtualStagingAiClient $client, int $index, int $total): bool
    {
        $state = new WorkspaceProviderState($workspace, $operationId);
        $key = $this->checkpointKey($item['id']);
        $bytes = $this->media->bytes($item);
        $sourceHash = hash('sha256', $bytes);
        $checkpoint = $state->get($key);
        if (! is_array($checkpoint) || empty($checkpoint['awaiting']) || empty($checkpoint['renderId'])) {
            $checkpoint = $this->submit($workspace, $state, $key, $item['id'], $options, $client, $bytes, $sourceHash);
            if ($checkpoint === null) {
                return false;
            }
        }
        $variations = $this->await($client, $state, $checkpoint);
        if ($variations === null) {
            return false;
        }
        $done = array_values(array_filter($variations, fn ($variation) => ($variation['status'] ?? '') === 'done'));
        $failed = array_values(array_filter($variations, fn ($variation) => in_array($variation['status'] ?? '', ['error', 'deleted'], true)));
        $stored = $this->storeVariations($workspace, $operationId, $item, $done, $sourceHash, $options, (string) $checkpoint['renderId']);
        if ($failed) {
            $this->persist($workspace, $operationId, function (StudioWorkspace $record) use ($key, $checkpoint, $stored, $failed): void {
                $this->appendOutputs($record, $stored);
                $operation = $record->operation;
                $operation['providerState'][$key] = array_merge($checkpoint, ['awaiting' => false, 'failedIds' => array_column($failed, 'id')]);
                $record->operation = $operation;
            });
            throw new StudioProviderException('Virtual staging could not finish one of these arrangements. Finished photos were saved. Generate again to try another.');
        }
        $this->persist($workspace, $operationId, function (StudioWorkspace $record) use ($key, $stored, $item, $index, $total): void {
            $this->appendOutputs($record, $stored);
            $operation = $record->operation;
            $operation['completed'][] = $item['id'];
            unset($operation['providerState'][$key]);
            $record->operation = $operation;
            $record->progress = (int) round(95 * ($index + 1) / max(1, $total));
        });

        return true;
    }

    private function submit(StudioWorkspace $workspace, WorkspaceProviderState $state, string $key, string $mediaId, array $options, VirtualStagingAiClient $client, string $bytes, string $sourceHash): ?array
    {
        if (! $this->active($state)) {
            return null;
        }
        $maskUrl = null;
        $maskHash = null;
        if ($options['useDetectedMask']) {
            $maskPath = $this->maskPath($workspace, $mediaId);
            if (! Storage::disk('public')->exists($maskPath)) {
                throw new StudioProviderException('Detect furniture on this photo before using a custom removal mask.');
            }
            $maskBytes = Storage::disk('public')->get($maskPath);
            $maskHash = hash('sha256', $maskBytes);
            $maskUrl = 'data:image/png;base64,'.base64_encode($maskBytes);
        }
        $existing = $this->existingRender($workspace, $mediaId, $sourceHash, $maskHash, $options);
        $reuse = $existing && $existing['count'] + $options['variationCount'] <= 20;
        $baseId = $reuse ? ($existing['removalId'] ?? null) : null;
        $config = VirtualStagingOptions::config($options, $baseId ? null : $maskUrl, $baseId);
        if ($reuse) {
            $created = $client->createVariations($existing['renderId'], $config, $options['variationCount']);
            $renderId = $existing['renderId'];
            $knownIds = $existing['knownIds'];
        } else {
            $created = $client->createRender($this->dataUrl($bytes), $config, $options['variationCount']);
            $renderId = (string) ($created['id'] ?? '');
            $knownIds = [];
        }
        if ($renderId === '') {
            throw new RuntimeException('Virtual staging returned an unreadable response. Retry to resume this photo.');
        }
        $checkpoint = [
            'renderId' => $renderId,
            'sourceHash' => $sourceHash,
            'maskHash' => $maskHash,
            'knownIds' => $knownIds,
            'variationIds' => $this->variationIds($created),
            'expected' => $options['variationCount'],
            'awaiting' => true,
        ];
        if (! $this->active($state)) {
            return null;
        }
        $state->put($key, $checkpoint);

        return $checkpoint;
    }

    private function await(VirtualStagingAiClient $client, WorkspaceProviderState $state, array $checkpoint): ?array
    {
        $deadline = microtime(true) + (int) config('studio_providers.virtualstagingai.poll_timeout', 900);
        $interval = (int) config('studio_providers.virtualstagingai.poll_interval', 3);
        $expected = max(1, (int) ($checkpoint['expected'] ?? 1));
        $lastSignature = null;
        do {
            if (! $this->active($state)) {
                return null;
            }
            $render = $client->render($checkpoint['renderId']);
            $items = $render['variations']['items'] ?? [];
            $fresh = array_values(array_filter($items, fn ($item) => ($item['id'] ?? '') !== '' && ! in_array($item['id'], $checkpoint['knownIds'] ?? [], true)));
            $pending = array_filter($items, fn ($item) => in_array($item['status'] ?? '', ['queued', 'rendering', 'idle'], true));
            $terminal = array_filter($fresh, fn ($item) => in_array($item['status'] ?? '', ['done', 'error', 'deleted'], true));
            $signature = implode(',', array_map(fn ($item) => ($item['id'] ?? '').':'.($item['status'] ?? ''), $items));
            if ($fresh && count($terminal) >= $expected && $pending === [] && $signature === $lastSignature) {
                return $fresh;
            }
            $lastSignature = $signature;
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Virtual staging is taking longer than expected. Retry to check this photo.');
            }
            if ($interval > 0) {
                sleep($interval);
            }
        } while (true);
    }

    private function storeVariations(StudioWorkspace $workspace, string $operationId, array $item, array $variations, string $sourceHash, array $options, string $renderId): array
    {
        $stored = [];
        $version = (int) collect($workspace->outputs ?? [])->where('mediaId', $item['id'])->max('version');
        foreach ($variations as $variation) {
            $variationId = (string) ($variation['id'] ?? '');
            if ($variationId === '') {
                continue;
            }
            foreach ($this->resultUrls($variation) as $index => $url) {
                $exists = collect($workspace->outputs ?? [])->contains(fn ($output) => ($output['vsai']['variationId'] ?? '') === $variationId && (int) ($output['vsai']['resultIndex'] ?? 0) === $index)
                    || collect($stored)->contains(fn ($output) => $output['vsai']['variationId'] === $variationId && $output['vsai']['resultIndex'] === $index);
                if ($exists) {
                    continue;
                }
                try {
                    $bytes = (string) $this->images->read($this->client()->download($url))->orient()->toJpeg(92);
                } catch (VirtualStagingAiException $exception) {
                    throw $exception;
                } catch (\Throwable) {
                    throw new VirtualStagingAiException('Virtual staging returned an unreadable image.');
                }
                $file = $this->media->store($workspace, $bytes, $operationId.'-'.substr(hash('sha256', $variationId.'-'.$index), 0, 20));
                $type = (string) ($variation['type'] ?? 'staging');
                $style = $variation['config']['add_furniture']['style'] ?? $variation['config']['style'] ?? $options['style'];
                $room = $variation['config']['add_furniture']['room_type'] ?? $variation['config']['room_type'] ?? $options['roomType'];
                $version++;
                $stored[] = [
                    'id' => $operationId.'-'.$variationId.'-'.$index,
                    'mediaId' => $item['id'],
                    'url' => $file['url'],
                    'thumbnailUrl' => $file['url'],
                    'path' => $file['path'],
                    'kind' => 'image',
                    'method' => 'fit',
                    'ratio' => $workspace->config['ratio'] ?? '16:9',
                    'status' => 'completed',
                    'version' => $version,
                    'label' => VirtualStagingOptions::label($type, is_string($style) ? $style : null, is_string($room) ? $room : null),
                    'vsai' => [
                        'renderId' => (string) (($variation['render_id'] ?? '') !== '' ? $variation['render_id'] : $renderId),
                        'variationId' => $variationId,
                        'resultIndex' => $index,
                        'type' => $type,
                        'baseVariationId' => $variation['base_variation_id'] ?? null,
                        'sourceHash' => $sourceHash,
                        'maskHash' => $type === 'removal' ? ($this->checkpointMask($workspace, $item['id'])) : null,
                    ],
                ];
            }
        }
        $this->publishEdited($workspace, $item, $stored);

        return $stored;
    }

    /** Copy finished staging results into the shoot's Edited → Virtual Staging tab. Intermediate removals stay in the project. */
    private function publishEdited(StudioWorkspace $workspace, array $item, array $stored): void
    {
        $shootId = (int) ($item['shootId'] ?? 0);
        if ($shootId <= 0) {
            return;
        }
        $shoot = Shoot::query()->find($shootId);
        if (! $shoot) {
            throw new StudioProviderException('The shoot for this photo is no longer available.');
        }
        $hasStaging = collect($stored)->contains(fn ($output) => ($output['vsai']['type'] ?? '') === 'staging');
        foreach ($stored as $output) {
            $type = (string) ($output['vsai']['type'] ?? '');
            $finished = $type === 'staging' || ($type === 'removal' && ! $hasStaging);
            if (! $finished) {
                continue;
            }
            $variationId = (string) ($output['vsai']['variationId'] ?? '');
            $resultIndex = (int) ($output['vsai']['resultIndex'] ?? 0);
            if ($variationId === '') {
                continue;
            }
            $outputKey = $variationId.':'.$resultIndex;
            $already = ShootFile::query()->where('shoot_id', $shoot->id)
                ->where('ai_editing_metadata->output_key', $outputKey)
                ->exists();
            if ($already) {
                continue;
            }
            $bytes = Storage::disk('public')->get((string) $output['path']);
            if (! is_string($bytes) || $bytes === '') {
                throw new StudioProviderException('The staged photo could not be saved to the shoot.');
            }
            $source = ShootFile::query()->find($item['fileId'] ?? 0);
            $base = Str::slug(pathinfo($source?->filename ?: ($item['name'] ?? 'staged'), PATHINFO_FILENAME)) ?: 'staged';
            $suffix = substr(preg_replace('/[^A-Za-z0-9]/', '', $variationId) ?: 'photo', 0, 12);
            $filename = $base.'-staged-'.$suffix.($resultIndex > 0 ? '-'.$resultIndex : '').'.jpg';
            $path = "shoots/{$shoot->id}/virtual_staging/{$filename}";
            if (! app(MediaStorage::class)->put($path, $bytes)) {
                throw new StudioProviderException('The staged photo could not be saved to the shoot.');
            }
            $attributes = [
                'shoot_id' => $shoot->id,
                'filename' => $filename,
                'stored_filename' => $filename,
                'path' => $path,
                'storage_path' => $path,
                'file_type' => 'image/jpeg',
                'mime_type' => 'image/jpeg',
                'media_type' => ShootFile::TREATMENT_VIRTUAL_STAGING,
                'file_size' => strlen($bytes),
                'uploaded_by' => $workspace->created_by,
                'uploaded_at' => now(),
                'workflow_stage' => ShootFile::STAGE_COMPLETED,
                'is_ai_edited' => true,
                'ai_editing_metadata' => [
                    'provider' => 'virtualstagingai',
                    'source_file_id' => $source?->id,
                    'variation_id' => $variationId,
                    'result_index' => $resultIndex,
                    'output_key' => $outputKey,
                    'render_id' => $output['vsai']['renderId'] ?? null,
                    'variation_type' => $type,
                    'label' => $output['label'] ?? null,
                    'workspace_id' => $workspace->id,
                    'completed_at' => now()->toIso8601String(),
                ],
            ];
            if (Schema::hasColumn('shoot_files', 'scan_status')) {
                $attributes['scan_status'] = ShootFile::SCAN_STATUS_CLEAN;
                $attributes['scan_result'] = 'generated by virtual staging';
                $attributes['scanned_at'] = now();
            }
            if (Schema::hasColumn('shoot_files', 'required_for_editing')) {
                $attributes['required_for_editing'] = false;
            }
            ShootFile::create($attributes);
        }
    }

    private function existingRender(StudioWorkspace $workspace, string $mediaId, string $sourceHash, ?string $maskHash, array $options): ?array
    {
        $matches = collect($workspace->outputs ?? [])->filter(fn ($output) => ($output['mediaId'] ?? '') === $mediaId
            && ($output['vsai']['renderId'] ?? '') !== ''
            && ($output['vsai']['sourceHash'] ?? '') === $sourceHash);
        if ($matches->isEmpty()) {
            return null;
        }
        $renderId = (string) $matches->last()['vsai']['renderId'];
        $knownIds = $matches->where('vsai.renderId', $renderId)->pluck('vsai.variationId')->filter()->unique()->values()->all();
        $removal = $matches->filter(fn ($output) => ($output['vsai']['renderId'] ?? '') === $renderId
            && ($output['vsai']['type'] ?? '') === 'removal'
            && ($output['vsai']['maskHash'] ?? null) === $maskHash)->last();
        $useBase = $options['addFurniture'] && $options['removal'] !== 'off' && is_array($removal);

        return [
            'renderId' => $renderId,
            'count' => count($knownIds),
            'knownIds' => $knownIds,
            'removalId' => $useBase ? ($removal['vsai']['variationId'] ?? null) : null,
        ];
    }

    private function checkpointMask(StudioWorkspace $workspace, string $mediaId): ?string
    {
        $state = $workspace->operation['providerState'][$this->checkpointKey($mediaId)] ?? null;

        return is_array($state) ? ($state['maskHash'] ?? null) : null;
    }

    private function variationIds(array $payload): array
    {
        $items = $payload['variations']['items'] ?? $payload['variations'] ?? [];
        if (! is_array($items) || ! array_is_list($items)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($item) => is_array($item) ? ($item['id'] ?? null) : null, $items)));
    }

    /** @return list<string> */
    private function resultUrls(array $variation): array
    {
        $result = $variation['result'] ?? null;
        if (! is_array($result)) {
            return [];
        }
        $rows = isset($result['url']) ? [$result] : $result;
        $urls = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['url'] ?? null) && $row['url'] !== '') {
                $urls[] = $row['url'];
            }
        }

        return $urls;
    }

    private function appendOutputs(StudioWorkspace $record, array $stored): void
    {
        if ($stored) {
            $record->outputs = array_merge($record->outputs ?? [], $stored);
        }
    }

    private function dataUrl(string $bytes): string
    {
        $jpeg = (string) $this->images->read($bytes)->orient()->scaleDown(width: 3072, height: 3072)->toJpeg(90);

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }

    private function maskPath(StudioWorkspace $workspace, string $mediaId): string
    {
        return 'studio/workspaces/'.$workspace->id.'/masks/'.hash('sha256', $mediaId).'.png';
    }

    private function checkpointKey(string $mediaId): string
    {
        return 'vsai-'.hash('sha256', $mediaId);
    }

    private function client(): VirtualStagingAiClient
    {
        return app()->makeWith(VirtualStagingAiClient::class, [
            'configuration' => $this->settings->credentials('virtualstagingai'),
        ]);
    }

    private function active(WorkspaceProviderState $state): bool
    {
        try {
            $state->assertActive();

            return true;
        } catch (StudioProviderException) {
            return false;
        }
    }

    private function persist(StudioWorkspace $workspace, string $operationId, callable $mutation): void
    {
        DB::transaction(function () use ($workspace, $operationId, $mutation): void {
            $record = StudioWorkspace::lockForUpdate()->findOrFail($workspace->id);
            if (! $record->isBusy() || ($record->operation['id'] ?? null) !== $operationId) {
                return;
            }
            $mutation($record);
            $record->version++;
            $record->save();
        });
        $workspace->refresh();
    }
}
