<?php

namespace App\Services\Studio;

use App\Exceptions\StudioProviderException;
use App\Models\StudioWorkspace;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Checkpoint image creation before uploading, so retries cannot silently create paid duplicates. */
class WorkspaceAutoenhance
{
    public function __construct(private StudioProviderSettings $settings) {}

    public function run(StudioWorkspace $workspace, string $operationId, array $item, string $source, string $service): string
    {
        $credentials = $this->settings->credentials('autoenhance');
        if (! filled($credentials['api_key'] ?? null)) {
            throw new StudioProviderException('An administrator needs to finish configuring photo enhancement.');
        }
        $state = new WorkspaceProviderState($workspace, $operationId);
        $key = 'autoenhance-'.hash('sha256', $item['id']);
        $saved = $state->get($key);
        $fingerprint = hash('sha256', $credentials['api_key']);
        if ($saved && ($saved['account'] ?? null) !== $fingerprint) {
            throw new StudioProviderException('The editing account changed. Restore the original account to resume this photo.');
        }
        if (! $saved) {
            $state->put($key, ['submitting' => true, 'account' => $fingerprint]);
            try {
                $response = $this->api($credentials)->post($this->base().'/v3/images/', $this->payload($workspace, $item, $service));
            } catch (\Throwable) {
                throw new StudioProviderException('The photo submission could not be confirmed. An administrator must check it before another submission.', true);
            }
            if (! $response->successful()) {
                // A timeout/server error may have accepted the image. Do not recreate it automatically.
                if ($response->clientError() && ! in_array($response->status(), [408, 409], true)) {
                    $state->put($key, null);
                }
                throw new StudioProviderException('The photo service did not accept this request. Check the account and retry.', $response->serverError() || in_array($response->status(), [408, 409], true));
            }
            $data = $response->json();
            if (! is_string($data['image_id'] ?? null) || ! is_string($data['upload_url'] ?? null)) {
                throw new StudioProviderException('The photo submission could not be confirmed. An administrator must check it before another submission.', true);
            }
            $saved = ['imageId' => $data['image_id'], 'uploadUrl' => $data['upload_url'], 'account' => $fingerprint];
            $state->put($key, $saved);
        }
        if (empty($saved['imageId'])) {
            throw new StudioProviderException('The previous photo submission could not be confirmed. An administrator must check it before another submission.', true);
        }
        if (empty($saved['uploaded'])) {
            $url = $saved['uploadUrl'];
            $host = (string) parse_url($url, PHP_URL_HOST);
            $apiUpload = $host === parse_url($this->base(), PHP_URL_HOST);
            $signedUpload = (bool) preg_match('/^[a-z0-9.-]+\.s3(?:-accelerate|[.-][a-z0-9-]+)?\.amazonaws\.com$/i', $host);
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || (! $apiUpload && ! $signedUpload) || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PORT)) {
                throw new StudioProviderException('The photo service returned an unsupported upload location.');
            }
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $contentType = $apiUpload ? 'application/octet-stream' : ($query['content-type'] ?? 'application/octet-stream');
            // Signed S3 uploads authenticate via their URL. Never send the API key to S3.
            $upload = $apiUpload ? $this->api($credentials) : Http::timeout((int) config('services.autoenhance.timeout', 120))->withOptions(['allow_redirects' => false]);
            try {
                $response = $upload->withBody($source, $contentType)->put($url);
            } catch (\Throwable) {
                throw new RuntimeException('The photo upload was interrupted. Retry to resume the saved image.');
            }
            if (! $response->successful()) {
                throw new RuntimeException('The photo upload could not finish. Retry to resume the saved image.');
            }
            $saved['uploaded'] = true;
            $state->put($key, $saved);
        }
        $deadline = microtime(true) + (int) config('services.autoenhance.poll_timeout', 900);
        $path = $this->base().'/v3/images/'.rawurlencode($saved['imageId']);
        do {
            $state->assertActive();
            try {
                $response = $this->api($credentials)->get($path);
            } catch (\Throwable) {
                throw new RuntimeException('Photo status is temporarily unavailable. Retry to resume the saved image.');
            }
            $status = $response->successful() ? strtolower((string) $response->json('status')) : 'processing';
            if (in_array($status, ['processed', 'completed', 'ready', 'downloaded', 'finished', 'done', 'success'], true)) {
                try {
                    $result = $this->api($credentials)->get($path.'/enhanced', ['format' => 'jpeg', 'quality' => 90, 'preview' => 'false']);
                } catch (\Throwable) {
                    throw new RuntimeException('The enhanced photo could not be downloaded. Retry to retrieve the saved result.');
                }
                if (! $result->successful() || ! @getimagesizefromstring($result->body())) {
                    throw new RuntimeException('The enhanced photo could not be downloaded. Retry to retrieve the saved result.');
                }

                return $result->body();
            }
            if (in_array($status, ['failed', 'error', 'cancelled', 'rejected'], true)) {
                throw new StudioProviderException('This photo could not be enhanced. Review its source before starting a new edit.');
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Photo processing is taking longer than expected. Retry to check its existing job.');
            }
            sleep(max(1, (int) config('services.autoenhance.poll_interval', 5)));
        } while (true);
    }

    private function payload(StudioWorkspace $workspace, array $item, string $service): array
    {
        $options = $workspace->config['adjustments'] ?? [];
        $payload = [
            'image_name' => 'photo-'.substr(hash('sha256', $item['id']), 0, 24).'.jpg',
            'enhance' => $service !== 'upscale',
            'lens_correction' => $service !== 'upscale' && ($options['lensCorrection'] ?? true),
            'vertical_correction' => $service !== 'upscale' && ($service === 'perspective-correction' || ($options['verticalCorrection'] ?? true)),
            'sky_replacement' => $service === 'sky-replacement' || ($options['skyReplacement'] ?? false),
            'upscale' => $service === 'upscale',
            'metadata' => ['workspace_id' => $workspace->id, 'operation_id' => $workspace->operation['id'], 'media_id' => $item['id']],
        ];
        if ($service === 'green-grass') {
            $payload['restage'] = ['grass' => 'GREEN'];
        }

        return $payload;
    }

    private function base(): string
    {
        return rtrim((string) config('services.autoenhance.base_url', 'https://api.autoenhance.ai'), '/');
    }

    private function api(array $credentials): PendingRequest
    {
        $request = Http::timeout((int) config('services.autoenhance.timeout', 120))
            ->withHeaders(['x-api-key' => $credentials['api_key'], 'x-api-version' => config('services.autoenhance.api_version', '2026-09-02')])
            ->withOptions(['allow_redirects' => false]);

        return config('services.autoenhance.dev_mode') ? $request->withHeaders(['x-dev-mode' => 'true']) : $request;
    }
}
