<?php

namespace App\Services\IguideLab;

use App\Exceptions\OpenAiImageException;
use App\Models\IguideLabRun;
use App\Services\Accounting\AiUsageRecorder;
use App\Services\FalService;
use App\Services\Studio\StudioProviderSettings;
use App\Support\LockedWrite;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Intervention\Image\ImageManager;
use RuntimeException;

/** Exact lab allowlist; no automatic paid retries, fallback, or model substitution. */
class LabProvider
{
    public function __construct(private StudioProviderSettings $settings, private FalService $fal) {}

    public function submit(IguideLabRun $run, array $images): array
    {
        if (! isset(LabCatalog::MODELS[$run->model_id])) throw new RuntimeException('Unsupported lab model.');
        $options = $run->options;
        $prepared = array_map(fn ($bytes) => (string) ImageManager::gd()->read($bytes)->scaleDown(width: 2048, height: 2048)->toPng(), $images);
        $options['preparedInputSha256'] = array_map(fn ($bytes) => hash('sha256', $bytes), $prepared);
        // Persist the exact derivative fingerprints before either paid request leaves this process.
        LockedWrite::run(fn () => $run->update(['options' => $options]), 'iguide-lab.input-fingerprints');
        if ($run->provider === 'fal') {
            $id = $this->fal->submitModel($run->model_id, [
                'image_urls' => array_map(fn ($bytes) => 'data:image/png;base64,'.base64_encode($bytes), $prepared),
                'prompt' => $run->prompt, 'num_images' => 1,
                'image_size' => ['width' => $options['width'], 'height' => $options['height']],
                'output_format' => 'png', 'enhance_prompt_mode' => 'standard',
            ]);

            return ['requestId' => $id];
        }
        $key = $this->settings->credentials('openai')['api_key'] ?? null;
        if (! filled($key)) throw new OpenAiImageException(reason: 'not_configured');
        $usage = app(AiUsageRecorder::class);
        $usageId = $usage->begin('image_edit', $run->model_id, 'images/edits');
        $start = hrtime(true);
        try {
            $request = Http::withToken($key)->acceptJson()->connectTimeout(15)->timeout(540)->withOptions(['allow_redirects' => false]);
            foreach ($prepared as $index => $bytes) $request->attach('image[]', $bytes, 'input-'.$index.'.png', ['Content-Type' => 'image/png']);
            $response = $request->post('https://api.openai.com/v1/images/edits', [
                'model' => $run->model_id, 'prompt' => $run->prompt, 'n' => 1,
                'size' => $options['width'].'x'.$options['height'], 'quality' => 'high', 'complexity' => 'medium',
                'output_format' => 'png', 'background' => 'opaque',
            ]);
        } catch (ConnectionException) {
            $usage->finish($usageId, $run->model_id, null, 'unknown', durationMs: (int) ((hrtime(true) - $start) / 1e6));
            throw new OpenAiImageException(ambiguous: true, reason: 'transport');
        }
        $metering = $response->json('usage');
        $metering = is_array($metering) ? $metering : null;
        $usage->finish($usageId, $run->model_id, $metering, $response->successful() ? 'success' : 'failed', $response->status(), $response->header('x-request-id'), (int) ((hrtime(true) - $start) / 1e6));
        if (! $response->successful()) throw new OpenAiImageException($response->status(), $response->status() >= 500 || $response->status() === 408);
        $encoded = $response->json('data.0.b64_json');
        if (! is_string($encoded) || strlen($encoded) > 40_000_000 || ! ($bytes = base64_decode($encoded, true))) throw new OpenAiImageException(ambiguous: true, reason: 'invalid_result');

        return ['bytes' => $bytes, 'requestId' => $response->header('x-request-id'), 'usage' => $metering];
    }

    public function poll(IguideLabRun $run): ?array
    {
        $status = $this->fal->modelStatus($run->model_id, $run->provider_request_id);
        if (in_array($status, ['IN_QUEUE', 'IN_PROGRESS'], true)) return null;
        if ($status !== 'COMPLETED') throw new RuntimeException('The queued model did not complete.');
        $url = $this->fal->modelImageResult($run->model_id, $run->provider_request_id);
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! ($host === 'fal.media' || str_ends_with($host, '.fal.media'))) throw new RuntimeException('Unexpected result host.');
        $response = Http::connectTimeout(10)->timeout(90)->withOptions(['allow_redirects' => false])->get($url);
        if (! $response->successful() || strlen($response->body()) > 30_000_000) throw new RuntimeException('The result could not be downloaded.');

        return ['bytes' => $response->body(), 'requestId' => $run->provider_request_id, 'usage' => null];
    }
}
