<?php

namespace App\Services\Studio;

use App\Exceptions\StudioProviderException;
use App\Models\StudioWorkspace;
use App\Services\Studio\Providers\FotelloClient;
use App\Services\Studio\Providers\FotelloException;
use RuntimeException;

/** Listing/upload/enhance is the public API's complete, resumable photo contract. */
class WorkspacePhotoEnhancement
{
    public function __construct(private StudioProviderSettings $settings) {}

    public function run(StudioWorkspace $workspace, string $operationId, array $item, string $source, array $route): string
    {
        if ($workspace->preset_id !== 'full-shoot') {
            throw new StudioProviderException('Fotello is reserved for Full Shoot editing.');
        }
        app(\App\Services\Shoots\ShootPhotoSet::class)->assertFullWorkspace($workspace);
        if (! collect($workspace->media)->contains('id', $item['id'])) {
            throw new StudioProviderException('This photo is outside the Full Shoot project.');
        }
        $ready = $this->settings->readiness($workspace->preset_id, $route);
        if (! $ready['ready']) {
            throw new StudioProviderException($ready['reason']);
        }
        $state = new WorkspaceProviderState($workspace, $operationId);
        $credentials = $this->settings->credentials('fotello');
        $fingerprint = hash('sha256', (string) $credentials['team_id']);
        $originalTeam = $state->get('photo-team');
        if ($originalTeam && $originalTeam !== $fingerprint) {
            throw new StudioProviderException('The editing account changed while this job was running. Restore the original account to resume it.');
        }
        $state->put('photo-team', $fingerprint);
        $client = app()->makeWith(FotelloClient::class, ['configuration' => $credentials]);
        // Keep shoots distinct even when an administrator edits multiple shoots in one workspace.
        $listingKey = 'photo-listing-'.($item['shootId'] ?? 'uploads');
        $listing = $this->once($state, $listingKey, fn () => $client->createListing(['name' => mb_substr($workspace->name, 0, 200), 'num_total_brackets' => 1]));
        $key = 'photo-'.hash('sha256', $item['id']);
        $savedUpload = $state->get($key.'-upload');
        if (! $state->get($key.'-uploaded') && ! $state->get($key.'-enhance') && isset($savedUpload['expires'])) {
            $expires = is_numeric($savedUpload['expires']) ? (int) $savedUpload['expires'] : strtotime((string) $savedUpload['expires']);
            if ($expires !== false && $expires <= time()) {
                $state->put($key.'-upload', null);
            }
        }
        $upload = $this->uploadSlot($state, $key.'-upload', fn () => $client->createUpload(['filename' => $key.'.jpg', 'listingId' => $listing['id']]));
        if (! $state->get($key.'-uploaded')) {
            $client->uploadBytes($upload, $source);
            $state->put($key.'-uploaded', true);
        }
        $shotType = $state->get($key.'-scene');
        if (! $shotType) {
            $shotType = $this->sceneType($workspace, $source);
            $state->put($key.'-scene', $shotType);
        }
        $enhance = $this->once($state, $key.'-enhance', fn () => $client->createEnhance([
            'listing_id' => $listing['id'], 'upload_ids' => [$upload['id']], 'input_preview_image_id' => $upload['id'], 'shot_type' => $shotType,
        ]));
        $deadline = microtime(true) + (int) config('services.fal.video_poll_timeout', 900);
        do {
            $state->assertActive();
            $result = $client->getEnhance($enhance['id']);
            if ($result['status'] === 'completed') {
                return $client->downloadBytes($result['enhanced_image_url']);
            }
            if ($result['status'] === 'failed') {
                $state->put($key.'-enhance', null);
                throw new StudioProviderException('This photo could not be enhanced. Retry to reuse its uploaded source.');
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Photo processing is taking longer than expected. Retry to check its existing job.');
            }
            sleep(max(1, (int) config('services.fal.video_poll_interval', 5)));
        } while (true);
    }

    /** An unused upload reservation can be replaced; it neither uploads pixels nor starts an edit. */
    public function uploadSlot(WorkspaceProviderState $state, string $key, callable $reserve): array
    {
        $saved = $state->get($key);
        if ($saved && ! isset($saved['id'])) {
            $state->put($key, null);
        }

        return $this->once($state, $key, $reserve);
    }

    public function once(WorkspaceProviderState $state, string $key, callable $submit): array
    {
        if ($saved = $state->get($key)) {
            if (isset($saved['id'])) {
                return $saved;
            }
            throw new StudioProviderException('The previous photo submission could not be confirmed. An administrator must check it before another submission.', true);
        }
        $state->put($key, ['submitting' => true]);
        try {
            $result = $submit();
        } catch (FotelloException $exception) {
            if (! $exception->ambiguousOutcome) {
                $state->put($key, null);
            }
            throw $exception;
        }
        $state->put($key, $result);

        return $result;
    }

    public function sceneType(StudioWorkspace $workspace, string $source): string
    {
        $selected = $workspace->config['adjustments']['sceneType'] ?? 'auto';
        if (in_array($selected, ['interior', 'exterior'], true)) {
            return $selected;
        }
        return app(WorkspaceSceneClassifier::class)->classify($source);
    }
}
