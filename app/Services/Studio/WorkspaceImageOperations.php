<?php

namespace App\Services\Studio;

use App\Exceptions\FalTerminalException;
use App\Exceptions\OpenAiImageException;
use App\Exceptions\StudioProviderException;
use App\Models\StudioWorkspace;
use App\Services\FalService;
use App\Services\Studio\Providers\OpenAiImageProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class WorkspaceImageOperations
{
    public function __construct(private StudioProviderSettings $settings, private FalService $fal, private OpenAiImageProvider $openai) {}

    public function edit(StudioWorkspace $workspace, string $operationId, array $item, string $source, string $prompt, array $references = [], ?array $routeOverride = null): string
    {
        $state = new WorkspaceProviderState($workspace, $operationId);
        $service = $workspace->operation['type'] === 'revision' ? 'revision' : $workspace->preset_id;
        $route = $routeOverride ?? $state->route($service);
        if ($route['provider'] === 'fotello') {
            $enhanced = app(WorkspacePhotoEnhancement::class)->run($workspace, $operationId, $item, $source, $route);
            $defaults = ['brightness' => 0, 'warmth' => 0, 'windows' => 50, 'look' => 'Natural', 'lensCorrection' => true, 'verticalCorrection' => true, 'skyReplacement' => false, 'preserveStructure' => true, 'strength' => 50, 'roomType' => 'living-room', 'furnitureStyle' => 'modern'];
            $adjustments = \Illuminate\Support\Arr::except($workspace->config['adjustments'] ?? [], ['sceneType']);
            $adjustments = array_filter($adjustments, fn ($value, $key) => $value !== null && $value !== '' && (! array_key_exists($key, $defaults) || $value !== $defaults[$key]), ARRAY_FILTER_USE_BOTH);
            if (trim((string) ($workspace->config['prompt'] ?? '')) !== '' || $adjustments || $references) {
                $item['id'] .= '-refine';

                return $this->edit($workspace, $operationId, $item, $enhanced, $prompt, $references, $state->route('revision'));
            }

            return $enhanced;
        }
        if ($route['provider'] === 'openai') {
            return $this->openAiOnce($workspace, $state, $item['id'], $source, $prompt, $route, $references);
        }
        if ($references && $route['model'] !== 'fal-ai/nano-banana-pro/edit') {
            throw new StudioProviderException('Reference photos are not supported by the selected editing service. Ask an administrator to choose a compatible service.');
        }
        $data = fn ($bytes) => 'data:image/jpeg;base64,'.base64_encode($bytes);
        if ($route['model'] === 'fal-ai/nano-banana-pro/edit') {
            $payload = ['image_urls' => array_map($data, [$source, ...$references]), 'prompt' => $prompt, 'num_images' => 1, 'aspect_ratio' => 'auto', 'resolution' => '2K', 'output_format' => 'png', 'limit_generations' => true];
            $url = $this->falOnce($state, $item['id'], $route['model'], $payload);
        } else {
            $legacy = $route['model'] === config('services.fal.image_model', 'fal-ai/flux-kontext/dev') ? ['source' => $source, 'prompt' => $prompt] : null;
            $url = $this->falOnce($state, $item['id'], $route['model'], ['image_url' => $data($source), 'prompt' => $prompt, 'num_images' => 1, 'output_format' => 'jpeg'], $legacy);
        }

        return $this->download($url);
    }

    public function outpaint(StudioWorkspace $workspace, string $operationId, string $mediaId, string $source, string $ratio, array $expansion): string
    {
        $state = new WorkspaceProviderState($workspace, $operationId);
        $route = $state->route('outpaint');
        $fallback = $route['fallback'] ?? null;
        if ($route['provider'] === 'openai' || $state->get('fallback-'.$mediaId)) {
            return $this->openAiOnce($workspace, $state, $mediaId, $source, '', $route['provider'] === 'openai' ? $route : $fallback, [], $ratio);
        }
        // Missing configuration is known before submission and cannot duplicate a charged request.
        if (! filled($this->settings->credentials('fal')['api_key'] ?? null) && ! $state->requestId($mediaId) && ! $state->get('fal-submitting-'.$mediaId) && $fallback && $this->openai->configured()) {
            $state->put('fallback-'.$mediaId, true);

            return $this->openAiOnce($workspace, $state, $mediaId, $source, '', $fallback, [], $ratio);
        }
        try {
            $url = $this->falOnce($state, $mediaId, $route['model'], array_merge(['image_url' => 'data:image/jpeg;base64,'.base64_encode($source), 'auto_crop' => false, 'output_format' => 'jpeg', 'mode' => 'high'], $expansion));

            return $this->download($url);
        } catch (FalTerminalException $exception) {
            // Never send a second paid request after an ambiguous timeout or a content rejection.
            // An authentication/account rejection with no accepted request is safe to route elsewhere.
            if ($fallback && in_array($exception->httpStatus, [401, 402], true) && ! $state->requestId($mediaId) && $this->openai->configured()) {
                $state->put('fallback-'.$mediaId, true);

                return $this->openAiOnce($workspace, $state, $mediaId, $source, '', $fallback, [], $ratio);
            }
            throw $exception;
        }
    }

    public function upscale(StudioWorkspace $workspace, string $operationId, string $mediaId, string $source): string
    {
        $state = new WorkspaceProviderState($workspace, $operationId);
        $route = $state->route('upscale');
        if ($route['provider'] !== 'fal') {
            throw new StudioProviderException('Upscaling needs a compatible result-retrieval integration before this service can be enabled.');
        }
        $url = $this->falOnce($state, $mediaId, $route['model'], [
            'image_url' => 'data:image/jpeg;base64,'.base64_encode($source), 'upscale_factor' => 2,
            'prompt' => 'Faithfully upscale this real estate photograph. Preserve every architectural line, object, material and texture. Add no new content.',
            'creativity' => 0, 'resemblance' => 1,
        ]);

        return $this->download($url);
    }

    private function falOnce(WorkspaceProviderState $state, string $mediaId, string $model, array $payload, ?array $legacy = null): string
    {
        $id = $state->requestId($mediaId);
        if (! $id) {
            if ($state->get('fal-submitting-'.$mediaId)) {
                throw new StudioProviderException('The previous image submission could not be confirmed. An administrator must check it before another submission.', true);
            }
            $state->put('fal-submitting-'.$mediaId, true);
            try {
                $id = $legacy
                    ? $this->fal->submitImageEditFromBuffer($legacy['source'], 'property.jpg', 'image/jpeg', 'enhance', ['prompt' => $legacy['prompt']])['request_id']
                    : $this->fal->submitModel($model, $payload);
                $state->saveRequest($mediaId, $id);
                $state->put('fal-submitting-'.$mediaId, null);
            } catch (FalTerminalException $exception) {
                $state->put('fal-submitting-'.$mediaId, null);
                throw $exception;
            }
        }
        $deadline = microtime(true) + (int) config('services.fal.video_poll_timeout', 900);
        do {
            $state->assertActive();
            try {
                $status = $legacy ? strtoupper($this->fal->imageEditStatus($id)['status'] ?? 'PROCESSING') : $this->fal->modelStatus($model, $id);
                if ($status === 'COMPLETED') {
                    return $legacy ? $this->fal->imageEditResult($id)['edited_image_url'] : $this->fal->modelImageResult($model, $id);
                }
            } catch (FalTerminalException $exception) {
                if ($exception->canDiscardRequest()) {
                    $state->saveRequest($mediaId, null);
                }
                throw $exception;
            }
            if (in_array($status, ['FAILED', 'ERROR', 'CANCELLED'], true)) {
                $state->saveRequest($mediaId, null);
                throw new StudioProviderException('The image service could not complete this request. Review the image and settings before retrying.');
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The image is still processing. Retry to resume its saved request.');
            }
            sleep(max(1, (int) config('services.fal.video_poll_interval', 5)));
        } while (true);
    }

    private function openAiOnce(StudioWorkspace $workspace, WorkspaceProviderState $state, string $mediaId, string $source, string $prompt, array $route, array $references = [], ?string $ratio = null): string
    {
        $key = 'openai-'.hash('sha256', $mediaId);
        $saved = $state->get($key);
        if (isset($saved['path']) && Storage::disk('local')->exists($saved['path'])) {
            return Storage::disk('local')->get($saved['path']);
        }
        if ($saved) {
            throw new StudioProviderException('The previous image submission could not be confirmed. An administrator must check it before another submission.', true);
        }
        $state->put($key, ['started' => true]);
        try {
            $options = ['model' => $route['model'], 'reference_images' => $references];
            $bytes = $ratio ? $this->openai->outpaint($source, $ratio, $prompt, $options) : $this->openai->edit($source, $prompt, $options);
        } catch (OpenAiImageException $exception) {
            if (! $exception->ambiguous) {
                $state->put($key, null);
            }
            throw $exception;
        }
        $path = 'studio/workspaces/'.$workspace->id.'/provider-results/'.$workspace->operation['id'].'-'.$key.'.png';
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new StudioProviderException('The generated image could not be saved. Contact an administrator before retrying.', true);
        }
        $state->put($key, ['path' => $path]);

        return $bytes;
    }

    private function download(string $url): string
    {
        if (preg_match('#^data:image/[^;]+;base64,(.+)$#s', $url, $matches)) {
            $bytes = base64_decode($matches[1], true);
        } else {
            if (! str_starts_with($url, 'https://')) {
                throw new StudioProviderException('The image service returned an invalid result location.');
            }
            try {
                $response = Http::timeout(120)->withOptions(['allow_redirects' => false])->get($url);
                $bytes = $response->successful() ? $response->body() : false;
            } catch (\Throwable) {
                throw new RuntimeException('The generated image could not be downloaded. Retry to retrieve the saved result.');
            }
        }
        if (! is_string($bytes) || ! @getimagesizefromstring($bytes)) {
            throw new StudioProviderException('The image service did not return a usable image.');
        }

        return $bytes;
    }
}
