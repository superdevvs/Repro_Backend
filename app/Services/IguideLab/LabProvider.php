<?php

namespace App\Services\IguideLab;

use App\Exceptions\OpenAiImageException;
use App\Models\IguideLabRun;
use App\Services\Accounting\AiUsageRecorder;
use App\Services\FalService;
use App\Support\LockedWrite;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Exact model allowlist; only an explicit rejected primary quota may select the fallback key. */
class LabProvider
{
    private const QUOTA_CODES = ['insufficient_quota', 'credit_balance_exhausted', 'organization_usage_limit_exceeded', 'organization_spend_limit_exceeded', 'project_spend_limit_exceeded'];
    public function __construct(private LabCredentials $credentials, private FalService $fal) {}

    public function submit(IguideLabRun $run, array $images): array
    {
        if (! isset(LabCatalog::MODELS[$run->model_id])) throw new RuntimeException('Unsupported lab model.');
        if (! empty($run->options['sectionMaskId']) && ! LabCatalog::supportsSectionMask($run->model_id)) throw new RuntimeException('Unsupported masked-edit model.');
        $options = $run->options;
        $prepared = array_map(fn ($bytes) => LabImages::prepared($bytes), $images);
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
        $key = $this->credentials->primary();
        $deadline = hrtime(true) + 540 * 1_000_000_000;
        $result = $this->openAiAttempt($run, $prepared, $key, 'lab-primary', $deadline);
        if ($result['quotaRejected'] ?? false) {
            $fallback = $this->credentials->quotaFallback($key);
            if ($fallback === null) throw new OpenAiImageException(429, reason: 'quota_exhausted');
            // The primary explicitly rejected the request: no accepted generation is being repeated.
            $result = $this->openAiAttempt($run, $prepared, $fallback, 'dashboard-fallback', $deadline);
            if ($result['quotaRejected'] ?? false) throw new OpenAiImageException(429, reason: 'quota_exhausted');
        }

        return $result;
    }

    private function openAiAttempt(IguideLabRun $run, array $prepared, #[\SensitiveParameter] string $key, string $credentialSource, int $deadline): array
    {
        $remainingSeconds = (int) floor(($deadline - hrtime(true)) / 1e9);
        if ($remainingSeconds < 15) throw new OpenAiImageException(reason: 'attempt_budget_exhausted');
        $options = $run->options;
        $attempts = $options['providerAttempts'] ?? [];
        $index = count($attempts);
        $attempts[] = ['credentialSource' => $credentialSource, 'requestedModelId' => $run->model_id,
            'status' => 'submitting', 'providerRequestId' => null, 'httpStatus' => null,
            'startedAt' => now()->toIso8601String(), 'completedAt' => null];
        app(LabWorkflowInvocation::class)->claim($run->options['workflowInvocation'] ?? null, $run->project_id, $run->request_id, 'image', fn () => $this->saveAttempts($run, $attempts));
        // Typed source gates may perform bounded local evidence verification.
        // That time belongs to the same total deadline as the HTTP attempt.
        $remainingSeconds = (int) floor(($deadline - hrtime(true)) / 1e9);
        if ($remainingSeconds < 15) throw new OpenAiImageException(reason: 'attempt_budget_exhausted');
        $usage = app(AiUsageRecorder::class);
        $usageId = $usage->begin('image_edit', $run->model_id, 'images/edits');
        $start = hrtime(true);
        try {
            $request = Http::withToken($key)->acceptJson()->connectTimeout(15)->timeout($remainingSeconds)->withOptions(['allow_redirects' => false]);
            foreach ($prepared as $imageIndex => $bytes) $request->attach('image[]', $bytes, 'input-'.$imageIndex.'.png', ['Content-Type' => 'image/png']);
            if ($maskId = ($options['sectionMaskId'] ?? null)) {
                $mask = app(LabMasks::class)->verified($run->project_id, 'section', $options['sectionId'], $maskId);
                // This is a distinct mask field, never an image reference. Provider guidance is not a hard pixel guarantee.
                $request->attach('mask', \Illuminate\Support\Facades\Storage::disk(LabService::DISK)->get($mask->provider_path), 'edit-mask.png', ['Content-Type' => 'image/png']);
            }
            $payload = [
                'model' => $run->model_id, 'prompt' => $run->prompt, 'n' => 1,
                'size' => $options['width'].'x'.$options['height'], 'quality' => 'high',
                'output_format' => 'png', 'background' => 'opaque',
            ];
            if (LabCatalog::supportsComplexity($run->model_id)) $payload['complexity'] = $options['complexity'] ?? 'medium';
            $response = $request->post('https://api.openai.com/v1/images/edits', $payload);
        } catch (ConnectionException) {
            $attempts[$index] = array_merge($attempts[$index], ['status' => 'unknown', 'completedAt' => now()->toIso8601String()]);
            $this->saveAttempts($run, $attempts);
            $usage->finish($usageId, $run->model_id, null, 'unknown', durationMs: (int) ((hrtime(true) - $start) / 1e6));
            throw new OpenAiImageException(ambiguous: true, reason: 'transport');
        }
        $metering = $response->json('usage');
        $metering = is_array($metering) ? $metering : null;
        // Do not infer quota exhaustion from status alone or from free-text error messages.
        $quotaRejected = $response->status() === 429 && (in_array($response->json('error.code'), self::QUOTA_CODES, true)
            || ($response->json('error.code') === null && $response->json('error.type') === 'insufficient_quota'));
        $attempts[$index] = array_merge($attempts[$index], [
            'status' => $response->successful() ? 'received' : ($quotaRejected ? 'quota_rejected' : (($response->status() >= 500 || $response->status() === 408) ? 'unknown' : 'rejected')),
            'httpStatus' => $response->status(), 'providerRequestId' => $response->header('x-request-id'),
            'errorCode' => $quotaRejected ? ($response->json('error.code') ?? 'insufficient_quota') : ($response->successful() ? null : 'provider_rejection'),
            'completedAt' => now()->toIso8601String(), 'durationMs' => (int) ((hrtime(true) - $start) / 1e6),
        ]);
        $this->saveAttempts($run, $attempts);
        $usage->finish($usageId, $run->model_id, $metering, $response->successful() ? 'success' : 'failed', $response->status(), $response->header('x-request-id'), (int) ((hrtime(true) - $start) / 1e6));
        if ($quotaRejected) return ['quotaRejected' => true];
        if (! $response->successful()) throw new OpenAiImageException($response->status(), $response->status() >= 500 || $response->status() === 408);
        $encoded = $response->json('data.0.b64_json');
        if (! is_string($encoded) || strlen($encoded) > 40_000_000 || ! ($bytes = base64_decode($encoded, true))) throw new OpenAiImageException(ambiguous: true, reason: 'invalid_result');

        return ['bytes' => $bytes, 'requestId' => $response->header('x-request-id'), 'usage' => $metering];
    }

    private function saveAttempts(IguideLabRun $run, array $attempts): void
    {
        $options = $run->options;
        $options['providerAttempts'] = $attempts;
        LockedWrite::run(fn () => $run->update(['options' => $options]), 'iguide-lab.provider-attempt');
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
