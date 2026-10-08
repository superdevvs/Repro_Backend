<?php

namespace App\Services\Studio;

use App\Exceptions\StudioProviderException;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Studio\Providers\FotelloClient;
use Illuminate\Support\Facades\DB;
use Intervention\Image\ImageManager;

/** Upload original exposures, submit one enhancement per stack, then collect each result independently. */
class WorkspaceFullShoot
{
    public function __construct(private WorkspaceMediaService $media, private WorkspacePhotoEnhancement $photos, private StudioProviderSettings $settings) {}

    public function run(StudioWorkspace $workspace, string $operationId): bool
    {
        $state = new WorkspaceProviderState($workspace, $operationId);
        $route = $state->route('full-shoot');
        $ready = $this->settings->readiness('full-shoot', $route);
        if (! $ready['ready']) {
            throw new StudioProviderException($ready['reason']);
        }
        $credentials = $this->settings->credentials('fotello');
        $team = hash('sha256', (string) $credentials['team_id']);
        if ($state->get('photo-team') && $state->get('photo-team') !== $team) {
            throw new StudioProviderException('The editing account changed. Restore the original account to resume this shoot.');
        }
        $state->put('photo-team', $team);
        $groups = app(FullShootPhotoGroups::class)->forWorkspace($workspace);
        if ($state->get('full-shoot-groups') && $state->get('full-shoot-groups') !== $groups) {
            throw new StudioProviderException('The HDR stacks changed during editing. Restore the original stacks before resuming.');
        }
        foreach ($groups as $group) {
            if (count($group['sourceMediaIds']) > 7) {
                throw new StudioProviderException('An HDR stack contains more than seven exposures. Correct the shoot stacks before sending to editing.');
            }
        }
        $state->put('full-shoot-groups', $groups);
        $client = app()->makeWith(FotelloClient::class, ['configuration' => $credentials]);
        $items = collect($workspace->media)->keyBy('id');
        $submitted = 0;
        $complete = 0;
        $newSubmissions = 0;
        foreach ($groups as $group) {
            $state->assertActive();
            if (in_array($group['mediaId'], $workspace->operation['completed'] ?? [], true)) {
                $submitted++;
                $complete++;

                continue;
            }
            $key = 'hdr-'.hash('sha256', implode('|', $group['sourceMediaIds']));
            $enhance = $state->get($key.'-enhance');
            if (! $enhance) {
                // Bound each queue turn. All stacks are submitted without waiting for previous renders.
                if ($newSubmissions++ >= 3) {
                    continue;
                }
                $item = $items[$group['mediaId']];
                // Detect before creating a listing or uploading originals. A classifier
                // account failure must not create an otherwise empty provider order.
                $scene = $state->get($key.'-scene');
                if (! $scene) {
                    $selectedScene = $workspace->config['adjustments']['sceneType'] ?? 'auto';
                    $scene = in_array($selectedScene, ['interior', 'exterior'], true) ? $selectedScene : $this->photos->sceneType($workspace,
                        isset($item['fileId']) ? $this->media->filePreview($item['fileId'], User::findOrFail($workspace->created_by), $workspace->team_id) : $this->media->bytes($item));
                    $state->put($key.'-scene', $scene);
                }
                $listingKey = 'hdr-listing-'.($item['shootId'] ?? 'uploads');
                // Mixed services can have different bracket sizes. upload_ids defines each exact stack.
                $sizes = collect($groups)->filter(fn ($candidate) => ($items[$candidate['mediaId']]['shootId'] ?? null) === ($item['shootId'] ?? null))
                    ->map(fn ($candidate) => count($candidate['sourceMediaIds']))->unique()->values();
                $listingPayload = ['name' => mb_substr($workspace->name, 0, 200)];
                if ($sizes->count() === 1) {
                    $listingPayload['num_total_brackets'] = $sizes[0];
                }
                $listing = $this->photos->once($state, $listingKey, fn () => $client->createListing($listingPayload));
                $uploads = [];
                foreach ($group['sourceMediaIds'] as $id) {
                    $uploadKey = 'hdr-upload-'.hash('sha256', $id);
                    $upload = $state->get($uploadKey);
                    if ($upload && ! $state->get($uploadKey.'-sent') && isset($upload['expires']) && strtotime($upload['expires']) <= time()) {
                        $state->put($uploadKey, null);
                    }
                    // Generic uploads are HDR inputs. Registering them as listing photos
                    // also creates gallery entries for the unrenderable RAW exposures.
                    $upload = $this->photos->uploadSlot($state, $uploadKey, fn () => $client->createUpload([
                        'filename' => basename($items[$id]['name']),
                    ]));
                    if (! $state->get($uploadKey.'-sent')) {
                        $client->uploadBytes($upload, $this->media->originalBytes($items[$id]));
                        $state->put($uploadKey.'-sent', true);
                    }
                    $uploads[$id] = $upload['id'];
                }
                $payload = [
                    'listing_id' => $listing['id'], 'upload_ids' => array_values($uploads),
                    'input_preview_image_id' => $uploads[$group['mediaId']], 'shot_type' => $scene,
                ];
                $preferences = PhotoPresetOptions::fotello($workspace->config['adjustments'] ?? []);
                if ($preferences) {
                    $payload['preferences'] = $preferences;
                }
                $enhance = $this->photos->once($state, $key.'-enhance', fn () => $client->createEnhance($payload));
                $state->put($key.'-submitted-at', time());
            }
            if (empty($enhance['id'])) {
                throw new StudioProviderException('A previous HDR submission could not be confirmed. An administrator must check it before another submission.', true);
            }
            $submitted++;
            $result = $client->getEnhance($enhance['id']);
            if ($result['status'] === 'failed') {
                $state->put($key.'-enhance', null);
                throw new StudioProviderException('Fotello could not finish '.$group['name'].'. Retry to reuse the saved uploads and completed images.');
            }
            if ($result['status'] !== 'completed') {
                if (time() - ($state->get($key.'-submitted-at') ?? time()) > 21600) {
                    // A manual retry polls the same paid request with a renewed wait window.
                    $state->put($key.'-submitted-at', time());
                    throw new StudioProviderException('Fotello is taking longer than expected. Retry to check the existing HDR jobs.');
                }

                continue;
            }
            $enhanced = $client->downloadBytes($result['enhanced_image_url']);
            $prompt = trim((string) ($workspace->config['prompt'] ?? ''));
            $prompt .= ' Preserve the actual property structure, materials and photorealism.';
            $enhanced = app(WorkspaceImageOperations::class)->refineEnhanced($workspace, $operationId, $items[$group['mediaId']], $enhanced, $prompt);
            $bytes = (string) ImageManager::gd()->read($enhanced)->toJpeg(96);
            $outputKey = $operationId.'-'.$group['mediaId'];
            $stored = $this->media->store($workspace, $bytes, $operationId.'-'.substr(hash('sha256', $group['mediaId']), 0, 16));
            $stored['sourceFileIds'] = $group['sourceFileIds'];
            $stored['sourceMediaIds'] = $group['sourceMediaIds'];
            $state->assertActive();
            $published = app(WorkspaceShootPublisher::class)->publish($workspace, $items[$group['mediaId']], $stored, $outputKey);
            $stored['name'] = $published?->filename ?? $group['name'];
            DB::transaction(function () use ($workspace, $operationId, $group, $stored, $outputKey) {
                $record = StudioWorkspace::lockForUpdate()->findOrFail($workspace->id);
                if (! $record->isBusy() || ($record->operation['id'] ?? null) !== $operationId) {
                    return;
                }
                $outputs = $record->outputs ?? [];
                if (! collect($outputs)->contains('id', $outputKey)) {
                    $outputs[] = array_merge($stored, ['id' => $outputKey, 'mediaId' => $group['mediaId'],
                        'thumbnailUrl' => $stored['url'], 'kind' => 'image', 'status' => 'completed',
                        'version' => (int) collect($outputs)->where('mediaId', $group['mediaId'])->max('version') + 1]);
                }
                $operation = $record->operation;
                $operation['completed'] = array_values(array_unique(array_merge($operation['completed'] ?? [], [$group['mediaId']])));
                $record->update(['outputs' => $outputs, 'operation' => $operation, 'version' => $record->version + 1]);
            });
            $complete++;
        }
        $state->put('full-shoot-progress', ['total' => count($groups), 'submitted' => $submitted, 'completed' => $complete,
            'phase' => $submitted < count($groups) ? 'submitting' : 'generating',
            'progress' => (int) round((20 * $submitted + 75 * $complete) / max(1, count($groups)))]);

        return $complete === count($groups);
    }
}
