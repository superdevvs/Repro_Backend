<?php
namespace App\Services\IguideLab;

use App\Exceptions\OpenAiImageException;
use App\Models\IguideLabLifestyleAssessment;
use App\Services\Accounting\AiUsageRecorder;
use App\Support\LockedWrite;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Typed placement assessment only; exact saved request bytes and per-attempt cancellation claims. */
class LabLifestyleAssessmentProvider
{
    private const QUOTA_CODES = ['insufficient_quota', 'credit_balance_exhausted', 'organization_usage_limit_exceeded', 'organization_spend_limit_exceeded', 'project_spend_limit_exceeded'];
    public function __construct(private LabCredentials $credentials) {}
    public function assess(IguideLabLifestyleAssessment $record, string $payload): array
    {
        abort_unless($record->fresh()->status === 'submitting' && empty($record->fresh()->provider_attempts), 409, 'This assessment already has a submission attempt.');
        abort_unless(strlen($payload) <= 40 * 1024 * 1024 && hash_equals($record->data['providerBodySha256'] ?? '', hash('sha256', $payload)), 422, 'The reserved assessment payload changed.');
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($decoded['model'] ?? null) === 'gpt-6-astra' && ($decoded['store'] ?? null) === false, 422);
        // This tick also allows two bounded 90-second local preflights within the 600-second job.
        $primary = $this->credentials->primary(); $deadline = hrtime(true) + 360 * 1_000_000_000;
        $result = $this->attempt($record, $payload, $primary, 'lab-primary', $deadline);
        if ($result['quotaRejected'] ?? false) {
            $fallback = $this->credentials->quotaFallback($primary);
            if ($fallback === null) throw new OpenAiImageException(429, reason: 'quota_exhausted');
            $result = $this->attempt($record, $payload, $fallback, 'dashboard-fallback', $deadline);
            if ($result['quotaRejected'] ?? false) throw new OpenAiImageException(429, reason: 'quota_exhausted');
        }
        return $result;
    }
    private function attempt(IguideLabLifestyleAssessment $record, string $payload, #[\SensitiveParameter] string $key, string $slot, int $deadline): array
    {
        $remaining = (int) floor(($deadline - hrtime(true)) / 1e9);
        if ($remaining < 15) throw new OpenAiImageException(reason: 'attempt_budget_exhausted');
        $attempts = $record->provider_attempts ?? []; $index = count($attempts);
        $attempts[] = ['credentialSource' => $slot, 'requestedModelId' => 'gpt-6-astra', 'status' => 'submitting', 'providerRequestId' => null,
            'httpStatus' => null, 'startedAt' => now()->toIso8601String(), 'completedAt' => null];
        app(LabWorkflowInvocation::class)->claim($record->data['workflowInvocation'], $record->project_id, $record->request_id, 'analysis',
            fn () => $this->save($record, ['provider_attempts' => $attempts]));
        $meter = app(AiUsageRecorder::class); $usageId = $meter->begin('workflow_lifestyle_assessment', 'gpt-6-astra', 'responses'); $start = hrtime(true);
        try {
            $response = Http::withToken($key)->acceptJson()->connectTimeout(15)->timeout($remaining)->withOptions(['allow_redirects' => false])
                ->withBody($payload, 'application/json')->post('https://api.openai.com/v1/responses');
        } catch (ConnectionException) {
            $attempts[$index]['status'] = 'unknown'; $attempts[$index]['completedAt'] = now()->toIso8601String();
            $this->save($record, ['provider_attempts' => $attempts]);
            $meter->finish($usageId, 'gpt-6-astra', null, 'unknown', durationMs: (int) ((hrtime(true) - $start) / 1e6));
            throw new OpenAiImageException(ambiguous: true, reason: 'transport');
        }
        $duration = (int) ((hrtime(true) - $start) / 1e6); $usage = $response->json('usage'); $usage = is_array($usage) ? $usage : null;
        $quota = $response->status() === 429 && (in_array($response->json('error.code'), self::QUOTA_CODES, true)
            || ($response->json('error.code') === null && $response->json('error.type') === 'insufficient_quota'));
        $attempts[$index] = array_merge($attempts[$index], ['status' => $response->successful() ? 'received' : ($quota ? 'quota_rejected' : (($response->status() >= 500 || $response->status() === 408) ? 'unknown' : 'rejected')),
            'httpStatus' => $response->status(), 'providerRequestId' => $response->header('x-request-id'), 'durationMs' => $duration, 'completedAt' => now()->toIso8601String()]);
        $this->save($record, ['provider_attempts' => $attempts, 'provider_request_id' => $response->header('x-request-id'), 'usage' => $usage]);
        $meter->finish($usageId, 'gpt-6-astra', $usage, $response->successful() ? 'success' : 'failed', $response->status(), $response->header('x-request-id'), $duration);
        $body = $response->body();
        abort_unless(strlen($body) <= 128 * 1024, 422, 'The assessment response exceeded its retained limit.');
        $path = 'iguide-lab/'.$record->project_id.'/lifestyle-assessments/'.$record->id.'/response-'.$index.'.json';
        abort_unless(Storage::disk(LabService::DISK)->put($path, $body, ['visibility' => 'private']), 503, 'Assessment receipt storage failed.');
        if ($quota) return ['quotaRejected' => true];
        if (! $response->successful()) throw new OpenAiImageException($response->status(), $response->status() >= 500 || $response->status() === 408);
        $receipt = ['response' => ['path' => $path, 'sha256' => hash('sha256', $body)], 'providerResponseId' => $response->json('id'), 'model' => $response->json('model')];
        $data = $record->fresh()->data; $data['providerReceipt'] = $receipt;
        $this->save($record, ['data' => $data, 'status' => 'received']);
        return $receipt;
    }
    private function save(IguideLabLifestyleAssessment $record, array $values): void
    {
        LockedWrite::run(fn () => $record->update($values), 'iguide-lab.lifestyle-assessment-attempt');
    }
}
