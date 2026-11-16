<?php

namespace Tests\Support\TourAnalysis;

use App\Jobs\ProcessIguideLabTourSurvey;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourSurvey;
use App\Models\IguideLabTourSurveyAttempt;
use App\Services\IguideLab\LabTourAnalyses;
use App\Services\IguideLab\LabTourConfig;
use App\Services\IguideLab\LabTourQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Test double of WP2's LabTourSurveys with the exact 14.1 signatures used by WP3 (findOrReserve, retryable,
 * inFlightCount, attemptsUsedByProject, attemptsUsedToday, captureOutcome, sweep). It follows the 5.12 reuse rules
 * and the 5.10 outcome table on the real WP1 tables, so the analysis side can be tested before WP2 lands.
 * The 9.9 sweep transitions for stale queued and submitting rows are applied only when $applySweepRules is true.
 */
class FakeTourSurveys
{
    public int $sweeps = 0;
    /** @var list<string> caseIds passed to findOrReserve, in call order */
    public array $reserved = [];
    /** Runs at the start of sweep() (outside any transaction), for example to throw once or commit a cancel. */
    public ?\Closure $beforeSweep = null;
    public bool $applySweepRules = false;
    /** The test's baseline transaction level (1 under RefreshDatabase). */
    public int $baselineLevel = 0;

    public function findOrReserve(IguideLabTourCapture $capture, string $sourceImportId, bool $allowNewGeneration): IguideLabTourSurvey
    {
        Assert::assertGreaterThan(0, DB::transactionLevel(), 'findOrReserve runs inside the caller\'s lab write transaction (5.12).');
        $this->reserved[] = $capture->case_id;
        $latest = $this->latest($capture);
        if ($latest && in_array($latest->status, ['completed', 'queued', 'submitting', 'received'], true)) return $latest;
        if ($latest && $latest->status === 'cancelled') {
            IguideLabTourSurvey::whereKey($latest->id)->where('status', 'cancelled')->toBase()
                ->update(['status' => 'queued', 'dispatched_at' => null, 'dispatch_count' => 0]);

            return $latest->fresh();
        }
        if ($latest && ! ($allowNewGeneration && $this->newGenerationAllowed($capture, $latest))) return $latest;
        $survey = IguideLabTourSurvey::create(['id' => (string) Str::uuid(), 'project_id' => $capture->project_id, 'case_id' => $capture->case_id,
            'source_import_id' => $sourceImportId, 'case_source_sha256' => $capture->source_sha256, 'preview_sha256' => $capture->preview_sha256,
            'survey_version' => 'tour-survey/1', 'generation' => ($latest?->generation ?? 0) + 1, 'status' => 'queued',
            'protocol' => ['version' => 'tour-survey/1'], 'data' => ['base' => 'fixture', 'warnings' => []]]);
        IguideLabTourCapture::whereKey($capture->id)->toBase()->update(['paid_survey_requests' => DB::raw('paid_survey_requests + 1')]);

        return $survey->fresh();
    }

    public function retryable(IguideLabTourCapture $capture): bool
    {
        $latest = $this->latest($capture);
        if (! $latest || in_array($latest->status, ['completed', 'queued', 'submitting', 'received', 'cancelled'], true)) return true;

        return $this->newGenerationAllowed($capture, $latest);
    }

    public function inFlightCount(): int
    {
        return IguideLabTourSurvey::where(fn ($q) => $q->whereIn('status', ['submitting', 'received'])
            ->orWhere(fn ($q) => $q->where('status', 'queued')->whereNotNull('dispatched_at')))->count();
    }

    public function attemptsUsedByProject(string $projectId): int
    {
        return IguideLabTourSurveyAttempt::where('project_id', $projectId)->count();
    }

    public function attemptsUsedToday(): int
    {
        return IguideLabTourSurveyAttempt::where('started_at', '>=', now()->subDay())->count();
    }

    /** @return array{0:string,1:?string} spec 5.10 */
    public function captureOutcome(IguideLabTourSurvey $survey): array
    {
        $code = $survey->error_code;
        return match (true) {
            $survey->status === 'cancelled' => ['skipped', 'cancelled'],
            $survey->status === 'failed' && in_array($code, ['survey_cap_reached', 'survey_daily_limit'], true) => ['skipped', $code],
            $survey->status === 'failed' && in_array($code, ['survey_misconfigured', 'survey_preparation_failed'], true) => ['failed', 'survey_unavailable'],
            $survey->status === 'failed' => ['failed', $code],
            $survey->status === 'needs_attention' && $code === 'queue_unconfirmed' => ['failed', 'survey_queue_lost'],
            default => ['failed', 'survey_outcome_unknown'],
        };
    }

    public function sweep(): bool
    {
        Assert::assertSame($this->baselineLevel, DB::transactionLevel(), 'The lab-wide sweep runs outside the analysis transaction (9.7 step 2).');
        $this->sweeps++;
        if ($this->beforeSweep) ($this->beforeSweep)();
        if (! $this->applySweepRules) return false;
        $changed = false;
        $connection = LabTourConfig::queueConnection();
        $queue = LabTourConfig::queue();
        $stale = now()->subSeconds(LabTourConfig::staleness('survey_queued_seconds'));
        foreach (IguideLabTourSurvey::where('status', 'queued')->whereNotNull('dispatched_at')->where('dispatched_at', '<', $stale)->get() as $survey) {
            if (IguideLabTourSurveyAttempt::where('survey_id', $survey->id)->exists()) continue;
            if (LabTourQueue::pendingJobExists($connection, $queue, 'ProcessIguideLabTourSurvey', $survey->id)) continue;
            if ($survey->dispatch_count < LabTourConfig::staleness('max_survey_dispatches')) {
                IguideLabTourSurvey::whereKey($survey->id)->where('dispatched_at', $survey->getRawOriginal('dispatched_at'))->toBase()
                    ->update(['dispatched_at' => now(), 'dispatch_count' => DB::raw('dispatch_count + 1')]);
                LabTourQueue::push(new ProcessIguideLabTourSurvey($survey->id));
            } else {
                $this->attention($survey, 'queue_unconfirmed');
            }
            $changed = true;
        }
        $stale = now()->subSeconds(LabTourConfig::staleness('survey_submitting_seconds'));
        foreach (IguideLabTourSurvey::where('status', 'submitting')->where('started_at', '<', $stale)->get() as $survey) {
            if (LabTourQueue::pendingJobExists($connection, $queue, 'ProcessIguideLabTourSurvey', $survey->id)) continue;
            $attempts = IguideLabTourSurveyAttempt::where('survey_id', $survey->id)->pluck('status');
            if ($attempts->isEmpty()) {
                IguideLabTourSurvey::whereKey($survey->id)->where('status', 'submitting')->toBase()->update(['status' => 'queued', 'dispatched_at' => null]);
            } elseif ($attempts->intersect(['submitting', 'unknown'])->isNotEmpty()) {
                $this->attention($survey, 'submission_outcome_unconfirmed');
            }
            $changed = true;
        }

        return $changed;
    }

    private function attention(IguideLabTourSurvey $survey, string $code): void
    {
        IguideLabTourSurvey::whereKey($survey->id)->whereIn('status', ['queued', 'submitting'])->toBase()
            ->update(['status' => 'needs_attention', 'error_code' => $code, 'completed_at' => now(), 'updated_at' => now()]);
        app(LabTourAnalyses::class)->surveyFinished($survey->fresh());
    }

    private function latest(IguideLabTourCapture $capture): ?IguideLabTourSurvey
    {
        return IguideLabTourSurvey::where('project_id', $capture->project_id)->where('case_id', $capture->case_id)
            ->where('preview_sha256', $capture->preview_sha256)->where('survey_version', 'tour-survey/1')->orderByDesc('generation')->first();
    }

    private function newGenerationAllowed(IguideLabTourCapture $capture, IguideLabTourSurvey $latest): bool
    {
        $paid = (int) IguideLabTourCapture::whereKey($capture->id)->value('paid_survey_requests');

        return $paid < LabTourConfig::maxPaidSurveysPerCapture()
            && ! IguideLabTourSurveyAttempt::where('survey_id', $latest->id)->whereIn('status', ['received', 'submitting', 'unknown'])->exists();
    }
}
