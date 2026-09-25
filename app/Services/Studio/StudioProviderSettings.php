<?php

namespace App\Services\Studio;

use App\Models\StudioProviderSetting;
use App\Services\Studio\Providers\VirtualStagingAiClient;
use App\Services\Studio\Providers\VirtualStagingAiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StudioProviderSettings
{
    public const AUTOENHANCE_SERVICES = ['listing-ready', 'color-correction', 'sky-replacement', 'perspective-correction', 'green-grass', 'upscale'];
    public const PHOTO_SERVICES = ['listing-ready', 'color-correction', 'full-shoot', 'sky-replacement', 'perspective-correction', 'twilight', 'virtual-staging', 'green-grass', 'upscale'];

    private const LABELS = [
        'listing-ready' => 'Listing ready', 'color-correction' => 'Color correction', 'full-shoot' => 'Full shoot',
        'sky-replacement' => 'Sky replacement', 'perspective-correction' => 'Perspective correction',
        'twilight' => 'Twilight', 'virtual-staging' => 'Virtual staging', 'green-grass' => 'Green grass',
        'revision' => 'Photo revisions', 'upscale' => 'Upscale', 'outpaint' => 'AI Extend',
        'walkthrough' => 'Walkthrough', 'property-reel' => 'Property reel', 'social-teaser' => 'Social teaser',
    ];

    public function stored(): array
    {
        // Read-only compatibility during a rolling deployment, before this migration runs.
        return Schema::hasTable('studio_provider_settings') ? (StudioProviderSetting::find(1)?->payload ?? []) : [];
    }

    public function credentials(string $provider, ?array $stored = null): array
    {
        $stored ??= $this->stored();
        $saved = $stored['credentials'][$provider] ?? [];

        $virtualStaging = $stored['credentials']['virtualStagingAi'] ?? [];

        return match ($provider) {
            'autoenhance' => ['api_key' => $saved['apiKey'] ?? config('services.autoenhance.api_key'), 'webhook_secret' => $saved['webhookSecret'] ?? config('services.autoenhance.webhook_secret')],
            'fotello' => ['api_key' => $saved['apiKey'] ?? config('studio_providers.fotello.api_key'), 'team_id' => $saved['teamId'] ?? config('studio_providers.fotello.team_id')],
            'virtualstagingai' => ['api_key' => $virtualStaging['apiKey'] ?? config('studio_providers.virtualstagingai.api_key')],
            'openai' => ['api_key' => config('services.openai.api_key')],
            'fal' => ['api_key' => config('services.fal.key')],
            default => [],
        };
    }

    public function route(string $service, ?array $stored = null): array
    {
        if (in_array($service, self::AUTOENHANCE_SERVICES, true)) {
            return ['provider' => 'autoenhance', 'model' => 'enhance', 'fallback' => null];
        }
        if ($service === 'full-shoot') {
            return ['provider' => 'fotello', 'model' => 'enhance', 'fallback' => null];
        }
        // Virtual staging always uses Virtual Staging AI, including jobs saved against an older route.
        if ($service === 'virtual-staging') {
            return ['provider' => 'virtualstagingai', 'model' => 'staging', 'fallback' => null];
        }
        $stored ??= $this->stored();
        if (isset($stored['routes'][$service]) && ($stored['routes'][$service]['provider'] ?? '') !== 'fotello') {
            return $stored['routes'][$service];
        }
        $model = match ($service) {
            'outpaint' => config('services.fal.outpaint_model', 'fal-ai/flux-2-pro/outpaint'),
            'walkthrough' => config('services.fal.walkthrough_model', 'fal-ai/kling-video/v2.5-turbo/pro/image-to-video'),
            'property-reel', 'social-teaser' => config('services.fal.model', 'fal-ai/wan-pro/image-to-video'),
            'upscale' => 'fal-ai/clarity-upscaler',
            default => config('services.fal.image_model', 'fal-ai/flux-kontext/dev'),
        };

        return ['provider' => 'fal', 'model' => (string) $model, 'fallback' => $service === 'outpaint' ? ['provider' => 'openai', 'model' => 'gpt-image-2'] : null];
    }

    public function providers(string $service): array
    {
        if (in_array($service, self::AUTOENHANCE_SERVICES, true)) {
            return [['id' => 'autoenhance', 'label' => 'Autoenhance', 'models' => [['id' => 'enhance', 'label' => 'Photo enhancement']]]];
        }
        if ($service === 'full-shoot') {
            return [['id' => 'fotello', 'label' => 'Fotello', 'models' => [['id' => 'enhance', 'label' => 'Full shoot enhancement']]]];
        }
        if ($service === 'virtual-staging') {
            return [['id' => 'virtualstagingai', 'label' => 'Virtual Staging AI', 'models' => [['id' => 'staging', 'label' => 'Virtual staging']]]];
        }
        $photoModels = ['fal-ai/flux-kontext/dev' => 'Standard image editing', 'fal-ai/nano-banana-pro/edit' => 'Nano Banana Pro'];
        $models = match ($service) {
            'outpaint' => ['fal' => ['fal-ai/flux-2-pro/outpaint' => 'FLUX.2 Pro Outpaint'], 'openai' => ['gpt-image-2' => 'GPT Image 2']],
            'walkthrough' => ['fal' => [(string) config('services.fal.walkthrough_model', 'fal-ai/kling-video/v2.5-turbo/pro/image-to-video') => 'Start/end-frame walkthrough']],
            'property-reel', 'social-teaser' => ['fal' => [(string) config('services.fal.model', 'fal-ai/wan-pro/image-to-video') => 'Image to video']],
            'upscale' => ['fal' => ['fal-ai/clarity-upscaler' => 'Clarity Upscaler'], 'fotello' => ['upscale' => 'Upscale']],
            default => ['fal' => $photoModels, 'openai' => ['gpt-image-2' => 'GPT Image 2'], 'fotello' => match ($service) {
                'twilight' => ['twilight' => 'Twilight'],
                'revision', 'green-grass' => ['standard' => 'Standard revision', 'pro' => 'Pro revision'], default => ['enhance' => 'Photo enhancement'],
            }],
        };
        $names = ['fal' => 'fal.ai', 'openai' => 'OpenAI', 'fotello' => 'Fotello'];
        unset($models['fotello']);

        return collect($models)->map(fn ($choices, $id) => ['id' => $id, 'label' => $names[$id], 'models' => collect($choices)->map(fn ($label, $model) => ['id' => $model, 'label' => $label])->values()->all()])->values()->all();
    }

    public function readiness(string $service, array $route, ?array $stored = null): array
    {
        if ($route['provider'] === 'fotello' && $service !== 'full-shoot') {
            return ['ready' => false, 'reason' => 'This service is available only for full shoot editing.'];
        }
        if (($route['provider'] ?? null) === 'virtualstagingai') {
            return filled($this->credentials('virtualstagingai', $stored)['api_key'] ?? null)
                ? ['ready' => true]
                : ['ready' => false, 'reason' => 'An administrator needs to finish configuring this service.'];
        }
        $credentials = $this->credentials($route['provider'], $stored);
        if (in_array($service, ['revision', 'twilight'], true) && ! filled($this->credentials('autoenhance', $stored)['api_key'] ?? null)) {
            return ['ready' => false, 'reason' => 'An administrator needs to finish configuring photo enhancement.'];
        }
        if ($service === 'outpaint' && $route['provider'] === 'fal' && ! filled($credentials['api_key'] ?? null)
            && ($route['fallback']['provider'] ?? null) === 'openai' && filled($route['fallback']['model'] ?? null)
            && filled($this->credentials('openai', $stored)['api_key'] ?? null)) {
            return ['ready' => true];
        }
        if (! filled($route['model'] ?? null) || ! filled($credentials['api_key'] ?? null) || ($route['provider'] === 'fotello' && ! $this->hasTeamId($credentials['team_id'] ?? null))) {
            return ['ready' => false, 'reason' => 'An administrator needs to finish configuring this service.'];
        }
        if ($route['provider'] === 'fotello' && in_array($service, ['twilight', 'virtual-staging', 'green-grass', 'revision', 'upscale', 'sky-replacement', 'perspective-correction'], true)) {
            // Public v1 documents submission, but not how to retrieve the exact variant/revision result.
            return ['ready' => false, 'reason' => 'This integration requires a verified render configuration and result-retrieval contract before it can be enabled.'];
        }

        return ['ready' => true];
    }

    /** Superadmin response: credentials are write-only and never decrypted into a response. */
    public function present(): array
    {
        $stored = $this->stored();
        $credentials = $this->credentials('fotello', $stored);
        $virtualStaging = $this->credentials('virtualstagingai', $stored);
        $services = [];
        foreach (self::LABELS as $id => $label) {
            $route = $this->route($id, $stored);
            $providers = $this->providers($id);
            foreach ($providers as &$provider) {
                foreach ($provider['models'] as &$model) {
                    $model = array_merge($model, $this->readiness($id, ['provider' => $provider['id'], 'model' => $model['id']], $stored));
                }
                unset($model);
            }
            unset($provider);
            $services[] = array_merge(['id' => $id, 'label' => $label, 'providers' => $providers], $route, $this->readiness($id, $route, $stored));
        }

        return ['services' => $services, 'credentials' => [
            'autoenhance' => [
                'keyConfigured' => filled($this->credentials('autoenhance', $stored)['api_key']),
                'webhookConfigured' => filled($this->credentials('autoenhance', $stored)['webhook_secret']),
            ],
            'fotello' => ['keyConfigured' => filled($credentials['api_key']), 'teamIdConfigured' => $this->hasTeamId($credentials['team_id'])],
            'virtualStagingAi' => [
                'keyConfigured' => filled($virtualStaging['api_key']),
                'usage' => $stored['credentials']['virtualStagingAi']['usage'] ?? null,
            ],
        ]];
    }

    /** Staff response deliberately contains no provider identifiers or credentials. */
    public function capabilities(): array
    {
        $stored = $this->stored();
        $ready = fn ($id) => $this->readiness($id, $this->route($id, $stored), $stored);
        $presets = [];
        foreach (array_merge(self::PHOTO_SERVICES, ['walkthrough', 'property-reel', 'social-teaser']) as $id) {
            $presets[$id] = $ready($id);
        }

        $revision = $this->route('revision', $stored);
        $references = $revision['provider'] === 'openai' || $revision['model'] === 'fal-ai/nano-banana-pro/edit';

        return ['presets' => $presets, 'revision' => array_merge($ready('revision'), ['referenceImages' => $references]), 'upscale' => $ready('upscale'), 'outpaint' => $ready('outpaint')];
    }

    public function save(array $input): array
    {
        DB::transaction(function () use ($input): void {
            $record = StudioProviderSetting::query()->firstOrCreate(['id' => 1], ['payload' => []]);
            $record = StudioProviderSetting::lockForUpdate()->findOrFail($record->id);
            $stored = $record->payload ?? [];
            foreach ($input['credentials']['autoenhance'] ?? [] as $key => $value) {
                if (in_array($key, ['apiKey', 'webhookSecret'], true) && is_string($value) && trim($value) !== '') {
                    $stored['credentials']['autoenhance'][$key] = trim($value);
                }
            }
            foreach ($input['credentials']['fotello'] ?? [] as $key => $value) {
                if (in_array($key, ['apiKey', 'teamId'], true) && is_string($value) && trim($value) !== '') {
                    $stored['credentials']['fotello'][$key] = trim($value);
                }
            }
            $virtualStaging = $input['credentials']['virtualStagingAi'] ?? [];
            $apiKey = isset($virtualStaging['apiKey']) && is_string($virtualStaging['apiKey']) ? trim($virtualStaging['apiKey']) : '';
            if ($apiKey !== '' || ! empty($virtualStaging['refresh'])) {
                $key = $apiKey !== '' ? $apiKey : (string) ($stored['credentials']['virtualStagingAi']['apiKey'] ?? config('studio_providers.virtualstagingai.api_key'));
                if (! filled($key)) {
                    throw ValidationException::withMessages(['credentials.virtualStagingAi.apiKey' => 'Enter the Virtual Staging AI API key first.']);
                }
                try {
                    $usage = app()->makeWith(VirtualStagingAiClient::class, ['configuration' => ['api_key' => $key]])->account();
                } catch (VirtualStagingAiException $exception) {
                    throw ValidationException::withMessages(['credentials.virtualStagingAi.apiKey' => $exception->getMessage()]);
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['credentials.virtualStagingAi.apiKey' => 'Virtual Staging AI could not be reached. The key was not saved.']);
                }
                if ($apiKey !== '') {
                    $stored['credentials']['virtualStagingAi']['apiKey'] = $apiKey;
                }
                $stored['credentials']['virtualStagingAi']['usage'] = $usage;
            }
            foreach ($input['services'] ?? [] as $index => $route) {
                $id = $route['id'];
                $this->validateRoute($id, $route, "services.$index");
                if (! empty($route['fallback'])) {
                    if ($id !== 'outpaint' || $route['fallback']['provider'] !== 'openai') {
                        throw ValidationException::withMessages(["services.$index.fallback" => 'Only the configured image-extension fallback is supported.']);
                    }
                    $this->validateRoute('outpaint', $route['fallback'], "services.$index.fallback");
                }
                $candidate = ['provider' => $route['provider'], 'model' => $route['model'], 'fallback' => $route['fallback'] ?? null];
                $availability = $this->readiness($id, $candidate, $stored);
                // Credentials may be saved separately without changing any active route.
                // Unchanged rows in a full settings save need not be reconfigured.
                if (! $availability['ready'] && $candidate !== $this->route($id, $stored)) {
                    throw ValidationException::withMessages(["services.$index" => $availability['reason']]);
                }
                $stored['routes'][$id] = $candidate;
            }
            $record->payload = $stored;
            $record->save();
        });

        return $this->present();
    }

    private function hasTeamId(mixed $teamId): bool
    {
        return is_string($teamId) && trim($teamId) !== ''
            && ! in_array(strtolower(trim($teamId)), ['pending', 'unknown', 'tbd', 'none', 'null', 'undefined', 'your-team-id', 'team-id'], true);
    }

    private function validateRoute(string $id, array $route, string $key): void
    {
        if (! isset(self::LABELS[$id])) {
            throw ValidationException::withMessages([$key => 'Choose a supported service.']);
        }
        $provider = collect($this->providers($id))->firstWhere('id', $route['provider']);
        if (! $provider || ! collect($provider['models'])->contains('id', $route['model'])) {
            throw ValidationException::withMessages([$key => 'Choose a supported service provider and model.']);
        }
    }
}
