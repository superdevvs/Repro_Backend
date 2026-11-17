<?php
namespace App\Services\IguideLab;

use App\Exceptions\OpenAiImageException;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourSurvey;
use App\Models\IguideLabTourSurveyAttempt;
use App\Services\Accounting\AiUsageRecorder;
use App\Support\LockedWrite;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The paid survey call (spec 5.6, D6, D8). A copy of the LabGrassSourceAdmissionProvider pattern (never an edit of it),
 * with these differences: no workflow claim (the tour claim of LabTourSurveys replaces it); every attempt is a
 * write-first ledger row checked against the caps under the project lock (a quota fallback counts); the raw body is
 * stored before any size check or database write; feature key tour_survey. One fallback only, never ->retry().
 */
class LabTourSurveyProvider
{
    private const QUOTA_CODES = ['insufficient_quota', 'credit_balance_exhausted', 'organization_usage_limit_exceeded', 'organization_spend_limit_exceeded', 'project_spend_limit_exceeded'];
    private const ENDPOINT = 'https://api.openai.com/v1/responses';
    private const MAX_PAYLOAD_BYTES = 40 * 1024 * 1024;

    public function __construct(private LabCredentials $credentials) {}

    /**
     * @return array the provider receipt of the received answer
     * @throws OpenAiImageException|LabTourSurveyCapReached|LabTourReceiptException|\LogicException
     */
    public function assess(IguideLabTourSurvey $survey, string $payload): array
    {
        $fresh = IguideLabTourSurvey::findOrFail($survey->id);
        if ($fresh->status !== 'submitting' || IguideLabTourSurveyAttempt::where('survey_id', $fresh->id)->exists()) {
            throw new \LogicException('tour_survey_not_claimed');
        }
        $bodySha = $fresh->data['providerBodySha256'] ?? null;
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES || ! is_string($bodySha) || ! hash_equals($bodySha, hash('sha256', $payload))) {
            throw new \LogicException('tour_survey_payload_changed');
        }
        // The model this payload was reserved with (saved with it in protocol), not the current setting.
        $model = $fresh->protocol['model'] ?? null;
        $decoded = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        if (! LabReasoningModel::requestable($model) || ($decoded['model'] ?? null) !== $model || ($decoded['store'] ?? null) !== false) {
            throw new \LogicException('tour_survey_payload_changed');
        }
        unset($decoded);

        $primary = $this->credentials->primary();
        $deadline = hrtime(true) + LabTourConfig::surveyDeadlineSeconds() * 1_000_000_000;
        $result = $this->attempt($fresh, $payload, $primary, 'lab-primary', $deadline, $model);
        if ($result['quotaRejected'] ?? false) {
            $fallback = $this->credentials->quotaFallback($primary);
            if ($fallback === null) throw new OpenAiImageException(429, reason: 'quota_exhausted');
            $result = $this->attempt($fresh, $payload, $fallback, 'dashboard-fallback', $deadline, $model);
            if ($result['quotaRejected'] ?? false) throw new OpenAiImageException(429, reason: 'quota_exhausted');
        }

        return $result;
    }

    private function attempt(IguideLabTourSurvey $survey, string $payload, #[\SensitiveParameter] string $key, string $slot, int $deadline, string $model): array
    {
        $remaining = (int) floor(($deadline - hrtime(true)) / 1e9);
        if ($remaining < 15) throw new OpenAiImageException(reason: 'attempt_budget_exhausted');
        $surveys = app(LabTourSurveys::class);
        // Write-first attempt row under the project lock: the caps are re-checked here, so a quota fallback counts (D8).
        [$attemptId, $index] = $this->labWrite($survey->project_id, 'survey-attempt', function () use ($survey, $slot, $model, $surveys) {
            if (! IguideLabProject::find($survey->project_id)?->active()) throw new LabTourSurveyCapReached('project_inactive');
            if ($surveys->attemptsUsedByProject($survey->project_id) >= LabTourConfig::surveyCallsPerProject()) throw new LabTourSurveyCapReached('survey_cap_reached');
            if ($surveys->attemptsUsedToday() >= LabTourConfig::surveyCallsPerDay()) throw new LabTourSurveyCapReached('survey_daily_limit');
            if (IguideLabTourSurvey::whereKey($survey->id)->value('status') !== 'submitting') throw new \LogicException('tour_survey_not_submitting');
            $index = IguideLabTourSurveyAttempt::where('survey_id', $survey->id)->count();
            $id = (string) Str::uuid();
            IguideLabTourSurveyAttempt::create(['id' => $id, 'project_id' => $survey->project_id, 'survey_id' => $survey->id, 'attempt_index' => $index,
                'credential_source' => $slot, 'requested_model' => $model, 'status' => 'submitting', 'started_at' => now()]);

            return [$id, $index];
        });
        $meter = app(AiUsageRecorder::class);
        $usageId = $meter->begin('tour_survey', $model, 'responses');
        $start = hrtime(true);
        try {
            $response = Http::withToken($key)->acceptJson()->connectTimeout(15)->timeout($remaining)->withOptions(['allow_redirects' => false])
                ->withBody($payload, 'application/json')->post(self::ENDPOINT);
        } catch (ConnectionException) {
            $duration = (int) ((hrtime(true) - $start) / 1e6);
            $this->labWrite($survey->project_id, 'survey-attempt-unknown', fn () => IguideLabTourSurveyAttempt::whereKey($attemptId)->where('status', 'submitting')
                ->update(['status' => 'unknown', 'duration_ms' => $duration, 'completed_at' => now()]));
            $this->finishUsage($meter, $usageId, $model, null, 'unknown', null, null, $duration);
            throw new OpenAiImageException(ambiguous: true, reason: 'transport');
        }
        $duration = (int) ((hrtime(true) - $start) / 1e6);
        $status = $response->status();
        $requestId = self::requestId($response->header('x-request-id'));
        $usage = null;
        $quota = false;
        try {
            // D6: the body is stored first, for every status, before any size check or database write.
            $body = $response->body();
            $path = LabTourSurveyStorage::base($survey->project_id, $survey->id).'/response-'.$index.'.json';
            if (Storage::disk(LabService::DISK)->put($path, $body, ['visibility' => 'private']) !== true) throw new LabTourReceiptException();
            $usage = $response->json('usage');
            $usage = is_array($usage) ? $usage : null;
            $quota = $status === 429 && (in_array($response->json('error.code'), self::QUOTA_CODES, true)
                || ($response->json('error.code') === null && $response->json('error.type') === 'insufficient_quota'));
            $attemptStatus = $response->successful() ? 'received' : ($quota ? 'quota_rejected' : (($status >= 500 || $status === 408) ? 'unknown' : 'rejected'));
            $receipt = $response->successful() ? ['response' => ['path' => $path, 'sha256' => hash('sha256', $body), 'bytes' => strlen($body)],
                'providerResponseId' => self::text($response->json('id')), 'model' => self::text($response->json('model')), 'status' => self::text($response->json('status')),
                'incompleteReason' => self::text($response->json('incomplete_details.reason'))] : null;
            $committed = $this->labWrite($survey->project_id, 'survey-receipt', function () use ($survey, $attemptId, $attemptStatus, $status, $requestId, $duration, $usage, $receipt) {
                IguideLabTourSurveyAttempt::whereKey($attemptId)->update(['status' => $attemptStatus, 'http_status' => $status, 'provider_request_id' => $requestId,
                    'duration_ms' => $duration, 'completed_at' => now()]);
                $values = ['provider_request_id' => $requestId, 'usage' => $usage === null ? null : json_encode($usage, JSON_THROW_ON_ERROR)];
                if ($receipt !== null) {
                    $data = IguideLabTourSurvey::findOrFail($survey->id)->data;
                    $data['providerReceipt'] = $receipt;
                    $values += ['data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'status' => 'received'];
                }

                return IguideLabTourSurvey::whereKey($survey->id)->where('status', 'submitting')->update($values) === 1;
            });
            if (! $committed) Log::warning('survey_receipt_conflict', ['surveyId' => $survey->id, 'attemptIndex' => $index]);
        } catch (\Throwable $error) {
            // A response arrived: the answer may be paid and stored. Never classify it as rejected (that is resumable).
            throw $error instanceof LabTourReceiptException ? $error : new LabTourReceiptException('receipt_unconfirmed', $error);
        }
        $this->finishUsage($meter, $usageId, $model, $usage, $response->successful() ? 'success' : 'failed', $status, $requestId, $duration);
        if ($quota) return ['quotaRejected' => true];
        if (! $response->successful()) throw new OpenAiImageException($status, $status >= 500 || $status === 408);

        return $receipt;
    }

    /** The attempt ledger, not the usage row, is the cap counter (5.11): a usage failure only logs. */
    private function finishUsage(AiUsageRecorder $meter, ?string $usageId, string $model, ?array $usage, string $status, ?int $http, ?string $requestId, int $duration): void
    {
        try {
            $meter->finish($usageId, $model, $usage, $status, $http, $requestId, $duration);
        } catch (\Throwable $error) {
            Log::warning('Tour survey usage could not be finished.', ['exception' => $error::class]);
        }
    }

    private function labWrite(string $projectId, string $context, callable $write): mixed
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($projectId, $write) {
            IguideLabProject::whereKey($projectId)->update(['updated_at' => now()]);

            return $write();
        }), 'iguide-lab.tour-'.$context);
    }

    private static function requestId(mixed $header): ?string
    {
        return is_string($header) && $header !== '' ? substr($header, 0, 255) : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
