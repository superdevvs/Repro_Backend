<?php

namespace App\Services\IguideLab;

use App\Exceptions\OpenAiImageException;
use App\Models\IguideLabAnalysis;
use App\Services\Accounting\AiUsageRecorder;
use App\Support\LockedWrite;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Responses analysis only; this class cannot enqueue or submit image generation. */
class LabAnalysisProvider
{
    private const QUOTA_CODES = ['insufficient_quota', 'credit_balance_exhausted', 'organization_usage_limit_exceeded', 'organization_spend_limit_exceeded', 'project_spend_limit_exceeded'];
    public function __construct(private LabCredentials $credentials) {}

    public function analyze(IguideLabAnalysis $analysis, array $payload): array
    {
        // The worker already validated and checkpointed this pinned payload before claiming submission.
        abort_unless(hash_equals($analysis->data['providerBodySha256'] ?? '', LabAnalysisRecipes::hash($payload)), 422, 'Analysis payload changed before submission.');
        $key = $this->credentials->primary(); $deadline = hrtime(true) + 540 * 1_000_000_000;
        $result = $this->attempt($analysis, $payload, $key, 'lab-primary', $deadline);
        if ($result['quotaRejected'] ?? false) {
            $fallback = $this->credentials->quotaFallback($key);
            if ($fallback === null) throw new OpenAiImageException(429, reason: 'quota_exhausted');
            $result = $this->attempt($analysis, $payload, $fallback, 'dashboard-fallback', $deadline);
            if ($result['quotaRejected'] ?? false) throw new OpenAiImageException(429, reason: 'quota_exhausted');
        }
        return $result;
    }

    private function attempt(IguideLabAnalysis $analysis, array $payload, #[\SensitiveParameter] string $key, string $slot, int $deadline): array
    {
        $remaining = (int) floor(($deadline - hrtime(true)) / 1e9);
        if ($remaining < 15) throw new OpenAiImageException(reason: 'attempt_budget_exhausted');
        $attempts = $analysis->provider_attempts ?? []; $index = count($attempts);
        $attempts[] = ['credentialSource' => $slot, 'requestedModelId' => $payload['model'], 'status' => 'submitting',
            'providerRequestId' => null, 'httpStatus' => null, 'startedAt' => now()->toIso8601String(), 'completedAt' => null];
        app(LabWorkflowInvocation::class)->claim($analysis->data['workflowInvocation'] ?? null, $analysis->project_id, $analysis->request_id, 'analysis', fn () => $this->save($analysis, ['provider_attempts' => $attempts]));
        $usage = app(AiUsageRecorder::class); $usageId = $usage->begin('scene_analysis', $payload['model'], 'responses'); $start = hrtime(true);
        try {
            $response = Http::withToken($key)->acceptJson()->connectTimeout(15)->timeout($remaining)->withOptions(['allow_redirects' => false])
                ->post('https://api.openai.com/v1/responses', $payload);
        } catch (ConnectionException) {
            $attempts[$index]['status'] = 'unknown'; $attempts[$index]['completedAt'] = now()->toIso8601String();
            $this->save($analysis, ['provider_attempts' => $attempts]);
            $usage->finish($usageId, $payload['model'], null, 'unknown', durationMs: (int) ((hrtime(true) - $start) / 1e6));
            throw new OpenAiImageException(ambiguous: true, reason: 'transport');
        }
        $duration = (int) ((hrtime(true) - $start) / 1e6);
        $metering = $response->json('usage'); $metering = is_array($metering) ? $metering : null;
        $quota = $response->status() === 429 && (in_array($response->json('error.code'), self::QUOTA_CODES, true)
            || ($response->json('error.code') === null && $response->json('error.type') === 'insufficient_quota'));
        $attempts[$index] = array_merge($attempts[$index], ['status' => $response->successful() ? 'received' : ($quota ? 'quota_rejected' : (($response->status() >= 500 || $response->status() === 408) ? 'unknown' : 'rejected')),
            'httpStatus' => $response->status(), 'providerRequestId' => $response->header('x-request-id'), 'completedAt' => now()->toIso8601String(), 'durationMs' => $duration]);
        $this->save($analysis, ['provider_attempts' => $attempts, 'provider_request_id' => $response->header('x-request-id'), 'usage' => $metering]);
        $usage->finish($usageId, $payload['model'], $metering, $response->successful() ? 'success' : 'failed', $response->status(), $response->header('x-request-id'), $duration);
        // Raw reasoning is retained privately for diagnosis, never included in DTOs or logs.
        $path = 'iguide-lab/'.$analysis->project_id.'/analyses/'.$analysis->id.'/response-'.$index.'.json';
        $compiler = $analysis->data['executionRecipe']['recipe']['compilerVersion'] ?? null;
        $lifestyleGate = $compiler === LabLifestyleCutProtocol::VERSION;
        $stagingGate = $compiler === LabStagingCutProtocol::VERSION || $lifestyleGate;
        if ($stagingGate) abort_unless(strlen($response->body()) <= 512 * 1024, 422, 'Cut analysis receipt exceeded its limit.');
        $stored = Storage::disk(LabService::DISK)->put($path, $response->body(), ['visibility' => 'private']);
        if ($stagingGate) {
            abort_unless($stored, 503, 'Cut analysis receipt storage failed.');
            if ($response->successful()) {
                $data = $analysis->fresh()->data;
                $data[$lifestyleGate ? 'lifestyleCutReceipt' : 'stagingCutReceipt'] = ['path' => $path, 'sha256' => hash('sha256', $response->body()),
                    'payloadSha256' => $data['providerBodySha256'], 'providerRequestId' => $response->header('x-request-id'), 'responseId' => $response->json('id')];
                $this->save($analysis, ['data' => $data]);
            }
        }
        if ($quota) return ['quotaRejected' => true];
        if (! $response->successful()) throw new OpenAiImageException($response->status(), $response->status() >= 500 || $response->status() === 408);
        $result = $response->json();
        if (! is_array($result)) throw new OpenAiImageException(ambiguous: true, reason: 'invalid_response');
        return $result;
    }

    private function save(IguideLabAnalysis $analysis, array $values): void
    {
        LockedWrite::run(fn () => $analysis->update($values), 'iguide-lab.analysis-attempt');
    }
}
