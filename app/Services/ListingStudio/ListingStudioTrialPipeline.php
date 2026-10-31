<?php

namespace App\Services\ListingStudio;

use App\Exceptions\StudioProviderException;
use App\Jobs\ProcessStudioWorkspace;
use App\Models\ListingStudioGuestTrial;
use App\Models\StudioWorkspace;
use App\Services\Studio\StudioProviderSettings;
use App\Services\Studio\VirtualStagingOptions;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Reuses Studio processors with a single private source and a server-owned recipe. */
class ListingStudioTrialPipeline
{
    private const PRESETS = ['staging' => 'virtual-staging', 'decluttering' => 'listing-ready', 'grass' => 'green-grass', 'twilight' => 'twilight'];

    private const DECLUTTER_PROMPT = 'Remove loose everyday clutter such as scattered clothing, papers, toys, trash and loose countertop items. Preserve all furniture, permanent fixtures, appliances, cabinetry, doors, windows, flooring, architectural details, room layout and camera perspective. Do not empty the room, add furniture or alter the property.';

    public function start(ListingStudioGuestTrial $trial): StudioWorkspace
    {
        $dispatch = false;
        $workspace = LockedWrite::run(function () use ($trial, &$dispatch): StudioWorkspace {
            return DB::transaction(function () use ($trial, &$dispatch): StudioWorkspace {
                $trial = ListingStudioGuestTrial::lockForUpdate()->findOrFail($trial->id);
                if ($trial->workspace_id) {
                    return StudioWorkspace::where('listing_studio_trial_id', $trial->id)->findOrFail($trial->workspace_id);
                }
                $this->assertTrial($trial);
                if (! config('listing-studio-trials.enabled', false)) {
                    throw new StudioProviderException('Free photo editing is paused.');
                }
                $preset = self::PRESETS[$trial->service];
                $type = $trial->service === 'decluttering' ? 'revision' : 'generate';
                $settings = app(StudioProviderSettings::class);
                $service = $type === 'revision' ? 'revision' : $preset;
                $route = $settings->route($service);
                $ready = $settings->readiness($service, $route);
                if (! $ready['ready']) {
                    throw new StudioProviderException('This editing service is temporarily unavailable.');
                }
                $adjustments = [];
                if ($trial->service === 'staging') {
                    $options = $trial->options ?? [];
                    $adjustments = ['roomType' => $options['roomType'] ?? 'living', 'furnitureStyle' => $options['style'] ?? 'modern',
                        'removal' => 'auto', 'addFurniture' => true, 'variationCount' => 1, 'watermark' => false, 'useDetectedMask' => false];
                    VirtualStagingOptions::assert($adjustments);
                }
                $operationId = (string) Str::uuid();
                $workspace = StudioWorkspace::create([
                    'listing_studio_trial_id' => $trial->id, 'created_by' => null, 'team_id' => null,
                    'name' => 'Listing Studio trial', 'preset_id' => $preset, 'request_id' => $trial->id,
                    'media' => [['id' => 'source', 'mediaRef' => $trial->source_path, 'trialId' => $trial->id, 'kind' => 'image', 'name' => 'Original photo']],
                    'config' => ['prompt' => '', 'ratio' => '16:9', 'frames' => [], 'text' => ['title' => '', 'subtitle' => ''], 'adjustments' => $adjustments],
                    'status' => 'generating', 'progress' => 0,
                    'operation' => ['id' => $operationId, 'key' => $trial->id, 'type' => $type,
                        'payload' => $type === 'revision' ? ['mediaId' => 'source', 'prompt' => self::DECLUTTER_PROMPT] : [],
                        'completed' => [], 'requests' => [], 'routing' => [$service => $route]],
                ]);
                $trial->update(['workspace_id' => $workspace->id, 'status' => 'queued']);
                $dispatch = true;

                return $workspace;
            });
        }, 'listing-studio.trial-start');

        if ($dispatch) {
            try {
                ProcessStudioWorkspace::dispatch($workspace->id, $workspace->operation['id']);
            } catch (\Throwable $exception) {
                report($exception);
                (new ProcessStudioWorkspace($workspace->id, $workspace->operation['id']))->failed(
                    new StudioProviderException('The queue submission could not be confirmed. This edit needs recovery.', true)
                );
            }
        }

        return $workspace->fresh();
    }

    public function authorize(StudioWorkspace $workspace): void
    {
        $trial = ListingStudioGuestTrial::find($workspace->listing_studio_trial_id);
        if (! $trial || $trial->workspace_id !== $workspace->id || $workspace->created_by !== null || $workspace->team_id !== null
            || $workspace->shoot_id || $workspace->editing_dispatch_id || count($workspace->media ?? []) !== 1
            || ($workspace->preset_id !== (self::PRESETS[$trial->service] ?? null))) {
            throw new StudioProviderException('This trial is not available for processing.');
        }
        $this->assertTrial($trial);
        $expectedType = $trial->service === 'decluttering' ? 'revision' : 'generate';
        $payload = $workspace->operation['payload'] ?? [];
        if (($workspace->operation['type'] ?? '') !== $expectedType
            || ($expectedType === 'revision' && $payload !== ['mediaId' => 'source', 'prompt' => self::DECLUTTER_PROMPT])
            || ($expectedType === 'generate' && $payload !== []) || ($workspace->config['prompt'] ?? '') !== '') {
            throw new StudioProviderException('This trial editing request is not supported.');
        }
        if ($trial->service === 'staging') {
            $options = VirtualStagingOptions::normalize($workspace->config['adjustments'] ?? []);
            if ($options['variationCount'] !== 1 || ! $options['addFurniture'] || $options['useDetectedMask']) {
                throw new StudioProviderException('This trial editing request is not supported.');
            }
        }
        $media = $workspace->media[0];
        if (($media['id'] ?? '') !== 'source' || ($media['trialId'] ?? '') !== $trial->id) {
            throw new StudioProviderException('The trial source is not available.');
        }
        $this->authorizeSource($media);
    }

    /** Administrative recovery only: never discard a paid checkpoint or start another operation. */
    public function resume(ListingStudioGuestTrial $trial): StudioWorkspace
    {
        $trial = $trial->fresh();
        if (! $trial?->workspace_id) {
            throw new StudioProviderException('This trial has no operation to recover.');
        }
        $job = new ProcessStudioWorkspace($trial->workspace_id, 'recovery');
        $lock = Cache::store('database')->lock($job->middleware()[0]->getLockKey($job), 30);
        if (! $lock->get()) {
            throw new StudioProviderException('This trial is still being processed. Wait before recovering it.');
        }
        try {
            $workspace = LockedWrite::run(fn () => DB::transaction(function () use ($trial): StudioWorkspace {
                $trial = ListingStudioGuestTrial::lockForUpdate()->findOrFail($trial->id);
                $workspace = StudioWorkspace::where('listing_studio_trial_id', $trial->id)->lockForUpdate()->findOrFail($trial->workspace_id);
                if ($trial->hasExpired() || ! in_array($trial->status, ['needs_attention', 'failed'], true)
                    || ($workspace->isBusy() && ($trial->status !== 'needs_attention' || $workspace->updated_at->gte(now()->subMinutes(150))))) {
                    throw new StudioProviderException('This trial is not ready for recovery.');
                }
                if (! $this->hasRecoverableCheckpoint($workspace) || $this->hasUnconfirmedSubmission($workspace)) {
                    throw new StudioProviderException('Verify the provider-side outcome before recovering this submission. No new edit was started.', true);
                }
                $operation = $workspace->operation;
                unset($operation['trialFailureAmbiguous'], $operation['trialStartedAt']);
                $workspace->update(['status' => 'generating', 'operation' => $operation, 'error' => null, 'version' => $workspace->version + 1]);
                $trial->update(['status' => 'queued', 'error_code' => null]);
                $this->authorize($workspace);

                return $workspace;
            }), 'listing-studio.trial-resume');
        } finally {
            $lock->release();
        }
        try {
            ProcessStudioWorkspace::dispatch($workspace->id, $workspace->operation['id']);
        } catch (\Throwable $exception) {
            report($exception);
            (new ProcessStudioWorkspace($workspace->id, $workspace->operation['id']))->failed(new StudioProviderException('The queue submission could not be confirmed. This edit needs recovery.', true));
        }

        return $workspace->fresh();
    }

    public function assertMaySubmit(StudioWorkspace $workspace): void
    {
        if ($workspace->listing_studio_trial_id && ! config('listing-studio-trials.enabled', false)) {
            throw new StudioProviderException('Free photo editing is paused. Existing submitted photos can still finish.', $this->hasProviderCheckpoint($workspace));
        }
    }

    public function hasProviderCheckpoint(StudioWorkspace $workspace): bool
    {
        return ! empty($workspace->operation['requests']) || ! empty($workspace->operation['providerState'])
            || ! empty($workspace->operation['completed']) || ! empty($workspace->outputs);
    }

    private function hasRecoverableCheckpoint(StudioWorkspace $workspace): bool
    {
        if (in_array('source', $workspace->operation['completed'] ?? [], true) && ! empty($workspace->outputs)) {
            return true;
        }
        if (array_filter($workspace->operation['requests'] ?? [], fn ($id) => is_string($id) && $id !== '')) {
            return true;
        }
        foreach ($workspace->operation['providerState'] ?? [] as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if ((str_starts_with($key, 'autoenhance-') && ! empty($value['imageId']))
                || (str_starts_with($key, 'vsai-') && ! str_ends_with($key, '-submitting') && ! empty($value['renderId']))
                || (str_starts_with($key, 'openai-') && ! empty($value['path']) && Storage::disk('listing_studio_trials')->exists($value['path']))) {
                return true;
            }
        }

        return false;
    }

    private function hasUnconfirmedSubmission(StudioWorkspace $workspace): bool
    {
        $state = $workspace->operation['providerState'] ?? [];
        foreach ($state as $key => $value) {
            if (! $value) {
                continue;
            }
            if (str_starts_with($key, 'fal-submitting-') && empty($workspace->operation['requests'][substr($key, strlen('fal-submitting-'))])) {
                return true;
            }
            if (str_starts_with($key, 'autoenhance-') && empty($value['imageId'])) {
                return true;
            }
            if (str_starts_with($key, 'openai-') && empty($value['path'])) {
                return true;
            }
            if (str_starts_with($key, 'vsai-') && str_ends_with($key, '-submitting') && empty($state[substr($key, 0, -strlen('-submitting'))]['renderId'])) {
                return true;
            }
        }

        return false;
    }

    public function authorizeSource(array $media): ListingStudioGuestTrial
    {
        $trial = ListingStudioGuestTrial::find($media['trialId'] ?? null);
        if (! $trial || ($media['mediaRef'] ?? null) !== $trial->source_path || ! empty($media['fileId'])
            || ! empty($media['shootId']) || ! empty($media['stackFileIds'])) {
            throw new StudioProviderException('The trial source is not available.');
        }
        $this->assertTrial($trial);

        return $trial;
    }

    private function assertTrial(ListingStudioGuestTrial $trial): void
    {
        $path = (string) $trial->source_path;
        if (! isset(self::PRESETS[$trial->service]) || ! in_array($trial->status, ['queued', 'processing'], true)
            || ! $trial->expires_at || $trial->hasExpired()
            || ! str_starts_with($path, 'trials/'.$trial->id.'/') || str_contains($path, '..')
            || str_contains($path, '\\') || str_contains($path, "\0") || ! Storage::disk('listing_studio_trials')->exists($path)) {
            throw new StudioProviderException('This trial or its source has expired or is unavailable.');
        }
    }
}
