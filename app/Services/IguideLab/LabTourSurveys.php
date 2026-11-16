<?php
namespace App\Services\IguideLab;

use App\Exceptions\OpenAiImageException;
use App\Jobs\ProcessIguideLabTourSurvey;
use App\Models\IguideLabCase;
use App\Models\IguideLabImport;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourRun;
use App\Models\IguideLabTourRunTarget;
use App\Models\IguideLabTourSurvey;
use App\Models\IguideLabTourSurveyAttempt;
use App\Support\LockedWrite;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Survey state machine (spec 5.6-5.15, 9.9, D6, D8): reserve, claim, the one paid call per survey row, parse, re-parse,
 * attention and the lab-wide sweep.
 *
 * Every state transition of a survey row is a lab write transaction on the survey's project with a conditional update
 * on the expected prior status (5.6 "Survey-row writes"). Nothing inside those transactions pushes a job, calls HTTP or
 * touches a file; pushes and LabTourAnalyses calls happen after the commit, at the caller's transaction level.
 * "Unknown outcome" always means an attempt row in submitting or unknown exists; such a survey is never sent again.
 */
class LabTourSurveys
{
    public const TERMINAL = ['completed', 'failed', 'needs_attention', 'cancelled'];
    private const RESPONSE_FILE_MAX_BYTES = 8 * 1024 * 1024;
    private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;

    private int $lastCapturesUpdated = 0;

    /**
     * Cache lookup and reservation (5.12, D8), inside the caller's lab write transaction. Links the capture to the
     * returned row (capture.survey_id) and counts a new row in capture.paid_survey_requests. A failed or
     * needs_attention row is returned unchanged when no new generation is allowed; the caller maps it with
     * captureOutcome().
     */
    public function findOrReserve(IguideLabTourCapture $capture, string $sourceImportId, bool $allowNewGeneration): IguideLabTourSurvey
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('findOrReserve runs inside the caller\'s lab write transaction.');
        $latest = $this->latest($capture);
        if ($latest === null) return $this->reserve($capture, $sourceImportId, 1);
        if ($latest->status === 'cancelled') {
            // Never claimed and no attempt: a fresh reservation again, pushed like a new one (S18).
            IguideLabTourSurvey::whereKey($latest->id)->where('status', 'cancelled')->update(['status' => 'queued', 'dispatched_at' => null,
                'dispatch_count' => 0, 'error_code' => null, 'parse_error' => null, 'started_at' => null, 'completed_at' => null]);
            $latest->refresh();
        } elseif (in_array($latest->status, ['failed', 'needs_attention'], true) && $allowNewGeneration && $this->newGenerationAllowed($capture, $latest)) {
            return $this->reserve($capture, $sourceImportId, $latest->generation + 1);
        }
        $this->link($capture, $latest->id, false);

        return $latest;
    }

    /** Read only: would findOrReserve($capture, ..., true) reuse, link or create a row rather than fail (5.12)? */
    public function retryable(IguideLabTourCapture $capture): bool
    {
        $latest = $this->latest($capture);
        if ($latest === null || ! in_array($latest->status, ['failed', 'needs_attention'], true)) return true;

        return $this->newGenerationAllowed($capture, $latest);
    }

    /** Job entry (5.6). The flag check lives in the job (5.15). */
    public function process(IguideLabTourSurvey $survey): void
    {
        $survey->refresh();
        if (in_array($survey->status, self::TERMINAL, true)) return;
        if ($survey->status === 'submitting') {
            if ($this->resolveSubmitting($survey, 'submission_outcome_unconfirmed', false) !== 'queued') return;
            $survey->refresh();
        }
        if ($survey->status === 'received') {
            $this->parseTick($survey);

            return;
        }
        if ($survey->status !== 'queued' || $this->cancelUnlinked($survey)) return;

        try {
            $payload = $this->prepare($survey);
        } catch (LabTourSourceException) {
            $this->fail($survey, ['queued'], 'source_unavailable');

            return;
        } catch (LabTourConfigException) {
            $this->fail($survey, ['queued'], 'survey_misconfigured');

            return;
        } catch (\Throwable $error) {
            Log::warning('Tour survey preparation failed.', ['surveyId' => $survey->id, 'exception' => $error::class]);
            $this->fail($survey, ['queued'], 'survey_preparation_failed');

            return;
        }
        if ($payload === null || ! $this->claim($survey)) return;

        try {
            app(LabTourSurveyProvider::class)->assess($survey, $payload);
        } catch (\Throwable $error) {
            $this->afterProviderError($survey, $error);
        }
    }

    /** Parse tick of a received row (5.8 steps 1-9). Free: no HTTP, no attempt, no usage row. */
    public function parseTick(IguideLabTourSurvey $survey): void
    {
        $survey->refresh();
        if ($survey->status !== 'received') return;
        try {
            $values = $this->completedValues($this->parseStored($survey));
        } catch (LabTourSurveyParseException $error) {
            $values = ['status' => 'failed', 'error_code' => $error->reasonCode, 'parse_error' => $error->parseError,
                'parser_version' => LabTourSurveyProtocol::PARSER_VERSION, 'completed_at' => now()];
        } catch (QueryException|\PDOException $error) {
            throw $error; // a database error keeps the row received; the job is retried
        } catch (\Throwable $error) {
            // Anything else (a changed recipe, an encode error) would fail every retry and be re-pushed by the sweep forever.
            // The stored answer is kept, so the free re-parse (5.13) can recover it.
            Log::warning('Tour survey parse failed unexpectedly.', ['surveyId' => $survey->id, 'exception' => $error::class]);
            $values = ['status' => 'failed', 'error_code' => 'survey_unreadable', 'parse_error' => 'internal',
                'parser_version' => LabTourSurveyProtocol::PARSER_VERSION, 'completed_at' => now()];
        }
        if ($this->labWrite($survey->project_id, 'survey-parse', fn () => IguideLabTourSurvey::whereKey($survey->id)->where('status', 'received')->update($values) === 1)) {
            $this->finished($survey);
        }
    }

    /**
     * Free re-parse (5.13, D6) of a parse-class failure, a received row, or (with $force) a completed row that no run
     * target references. A forced re-parse that fails commits nothing and throws the parse error.
     *
     * @throws \DomainException when the state does not allow a re-parse (nothing changed)
     * @throws LabTourSurveyParseException for a forced re-parse that failed (nothing changed)
     */
    public function reparse(IguideLabTourSurvey $survey, bool $force): IguideLabTourSurvey
    {
        $this->lastCapturesUpdated = 0;
        $survey->refresh();
        $status = $survey->status;
        $sha = $survey->result_sha256;
        if ($status === 'completed' && ! $force) throw new \DomainException('This survey is completed; re-parsing it needs --force.');
        if (! ($status === 'received' || $status === 'completed' || ($status === 'failed' && in_array($survey->error_code, LabTourSurveyProtocol::PARSE_ERRORS, true)))) {
            throw new \DomainException('Only a received survey, a completed one with --force, or one that failed to parse its stored answer can be re-parsed.');
        }
        if ($status === 'completed' && $this->runTargetExists($survey)) {
            throw new \DomainException('A tour run references a capture of this survey, so its result cannot be re-parsed.');
        }
        try {
            $values = $this->completedValues($this->parseStored($survey));
            $values['completed_at'] = $survey->completed_at ?? now();
        } catch (LabTourSurveyParseException $error) {
            if ($status === 'completed') throw $error;
            $values = ['status' => 'failed', 'error_code' => $error->reasonCode, 'parse_error' => $error->parseError,
                'parser_version' => LabTourSurveyProtocol::PARSER_VERSION, 'completed_at' => $survey->completed_at ?? now()];
        }
        $refusal = $this->labWrite($survey->project_id, 'survey-reparse', function () use ($survey, $status, $sha, $values) {
            $query = IguideLabTourSurvey::whereKey($survey->id)->where('status', $status);
            if ($status === 'completed') {
                // Re-checked under the project lock: a run may have been created since the first check.
                if ($this->runTargetExists($survey)) return 'A tour run references a capture of this survey, so its result cannot be re-parsed.';
                $query->where('result_sha256', $sha);
            }

            return $query->update($values) === 1 ? null : 'The survey changed while it was re-parsed; nothing was written.';
        });
        if ($refusal !== null) throw new \DomainException($refusal);
        $survey->refresh();
        if ($survey->status === 'completed') $this->lastCapturesUpdated = app(LabTourAnalyses::class)->surveyReparsed($survey, $force);

        return $survey;
    }

    /** capturesUpdated of the last reparse() (the WP3 surveyReparsed result), for the operator receipt (5.13). */
    public function lastCapturesUpdated(): int
    {
        return $this->lastCapturesUpdated;
    }

    /** needs_attention for a queued or submitting row (never for a received one: paid answers are never discarded). */
    public function attention(IguideLabTourSurvey $survey, string $code): void
    {
        if ($this->transition($survey, ['queued', 'submitting'], ['status' => 'needs_attention', 'error_code' => $code, 'completed_at' => now()], 'survey-attention')) {
            $this->finished($survey);
        }
    }

    /** Called by the job's failed() only while it holds the survey lock (5.15). */
    public function workerStopped(IguideLabTourSurvey $survey): void
    {
        $survey->refresh();
        if ($survey->status === 'submitting') $this->resolveSubmitting($survey, 'worker_stopped_unknown_outcome', false);
        elseif ($survey->status === 'queued') $this->fail($survey, ['queued'], 'survey_preparation_failed');
    }

    /** Flag off (D10): the consumed job is a known non-loss, so its dispatch markers are released (bookkeeping write). */
    public function releaseDispatch(string $surveyId): void
    {
        LockedWrite::run(fn () => IguideLabTourSurvey::whereKey($surveyId)->where('status', 'queued')->toBase()
            ->update(['dispatched_at' => null, 'dispatch_count' => 0]), 'iguide-lab.tour-survey-release');
    }

    /** Lab-wide rows that hold a worker slot (5.11). */
    public function inFlightCount(): int
    {
        return IguideLabTourSurvey::where(fn ($query) => $query->whereIn('status', ['submitting', 'received'])
            ->orWhere(fn ($queued) => $queued->where('status', 'queued')->whereNotNull('dispatched_at')))->count();
    }

    /** Paid attempts of the project over its lifetime (D8). */
    public function attemptsUsedByProject(string $projectId): int
    {
        return IguideLabTourSurveyAttempt::where('project_id', $projectId)->count();
    }

    /** Paid attempts of the whole lab in the rolling 24 h (D8). */
    public function attemptsUsedToday(): int
    {
        return IguideLabTourSurveyAttempt::where('started_at', '>=', now()->subDay())->count();
    }

    /** @return array{0:string,1:?string} [captureStatus, captureError] for a terminal survey (5.10) */
    public function captureOutcome(IguideLabTourSurvey $survey): array
    {
        $code = $survey->error_code;

        return match ($survey->status) {
            'completed' => ['surveyed', null],
            'cancelled' => ['skipped', 'cancelled'],
            'needs_attention' => ['failed', $code === 'queue_unconfirmed' ? 'survey_queue_lost' : 'survey_outcome_unknown'],
            'failed' => match ($code) {
                'survey_cap_reached', 'survey_daily_limit' => ['skipped', $code],
                'survey_unreadable', 'survey_incomplete', 'survey_too_large', 'survey_refused', 'survey_rejected', 'survey_quota_exhausted', 'source_unavailable' => ['failed', $code],
                default => ['failed', 'survey_unavailable'],
            },
            default => throw new \LogicException('A survey outcome exists only for a terminal survey.'),
        };
    }

    /**
     * Lab-wide survey sweep (9.9), from every analysis tick and from the scheduled sweeper. Compare-and-set lab write
     * transactions per row; pushes after the commits, each in try/catch. True when anything changed.
     */
    public function sweep(): bool
    {
        $changed = false;
        $push = [];
        $connection = LabTourConfig::queueConnection();
        $queue = LabTourConfig::queue();
        $pending = fn (string $id) => LabTourQueue::pendingJobExists($connection, $queue, 'ProcessIguideLabTourSurvey', $id);

        $staleSubmitting = now()->subSeconds(LabTourConfig::staleness('survey_submitting_seconds'));
        foreach (IguideLabTourSurvey::where('status', 'submitting')->where('started_at', '<', $staleSubmitting)->orderBy('started_at')->limit(100)->get() as $survey) {
            if ($pending($survey->id)) continue;
            $changed = $this->resolveSubmitting($survey, 'submission_outcome_unconfirmed', true) !== null || $changed;
        }

        $staleQueued = now()->subSeconds(LabTourConfig::staleness('survey_queued_seconds'));
        $queued = IguideLabTourSurvey::where('status', 'queued')->where('dispatched_at', '<', $staleQueued)->orderBy('dispatched_at')->limit(100)->get()
            ->concat(IguideLabTourSurvey::where('status', 'queued')->whereNull('dispatched_at')
                ->whereIn('id', IguideLabTourCapture::select('survey_id')->where('status', 'surveying')->whereNotNull('survey_id'))
                ->orderBy('created_at')->limit(100)->get());
        foreach ($queued as $survey) {
            $outcome = $this->sweepQueued($survey, $pending);
            if ($outcome === 'push') $push[] = $survey->id;
            $changed = $outcome !== null || $changed;
        }

        $staleReceived = now()->subSeconds(LabTourConfig::staleness('survey_received_seconds'));
        $received = IguideLabTourSurvey::where('status', 'received')->where('updated_at', '<', $staleReceived)
            ->where(fn ($query) => $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<', $staleReceived))->orderBy('updated_at')->limit(100)->get();
        foreach ($received as $survey) {
            if ($pending($survey->id)) continue;
            $seen = $survey->getRawOriginal('dispatched_at');
            // Bookkeeping compare-and-set; never counted toward max_survey_dispatches, never marked lost (D6).
            $marked = $this->labWrite($survey->project_id, 'survey-sweep-received', fn () => $this->whereSeen(IguideLabTourSurvey::whereKey($survey->id)
                ->where('status', 'received'), $seen)->toBase()->update(['dispatched_at' => now()]) === 1);
            if ($marked) { $push[] = $survey->id; $changed = true; }
        }

        foreach ($push as $id) {
            try {
                LabTourQueue::push(new ProcessIguideLabTourSurvey($id));
            } catch (\Throwable $error) {
                // dispatched_at was written first, so the next sweep finds a lost push.
                Log::warning('Tour survey push failed; the sweep will retry.', ['surveyId' => $id, 'exception' => $error::class]);
            }
        }

        return $changed;
    }

    /**
     * One queued row: cancel when nothing live links it, wait while a job is pending, re-dispatch while under
     * max_survey_dispatches, else queue_unconfirmed. A row whose markers were cleared (flag-off release, or the
     * submitting reset above) and that a surveying capture links is pushed again too: the analysis dispatch pass
     * (9.7 step 3) only looks at pending captures.
     *
     * @return ?string 'push', 'terminal' or null (nothing changed)
     */
    private function sweepQueued(IguideLabTourSurvey $survey, \Closure $pending): ?string
    {
        $seen = $survey->getRawOriginal('dispatched_at');
        $jobPending = $pending($survey->id);
        $outcome = $this->labWrite($survey->project_id, 'survey-sweep-queued', function () use ($survey, $seen, $jobPending) {
            $row = IguideLabTourSurvey::whereKey($survey->id)->first();
            if ($row === null || $row->status !== 'queued' || $row->getRawOriginal('dispatched_at') !== $seen
                || IguideLabTourSurveyAttempt::where('survey_id', $survey->id)->exists()) {
                return null;
            }
            $current = fn () => $this->whereSeen(IguideLabTourSurvey::whereKey($survey->id)->where('status', 'queued'), $seen);
            if ($seen === null) {
                // Not in flight and holding no slot; only a surveying link makes it this sweep's job (else the dispatch pass's).
                if (! $this->linkedLive($survey, ['surveying'])) return null;
            } elseif (! $this->linkedLive($survey)) {
                return $current()->update(['status' => 'cancelled', 'completed_at' => now()]) === 1 ? 'terminal' : null;
            }
            if ($jobPending) return null;
            if ($row->dispatch_count < LabTourConfig::staleness('max_survey_dispatches')) {
                return $current()->toBase()->update(['dispatched_at' => now(), 'dispatch_count' => $row->dispatch_count + 1]) === 1 ? 'push' : null;
            }

            return $current()->update(['status' => 'needs_attention', 'error_code' => 'queue_unconfirmed', 'completed_at' => now()]) === 1 ? 'terminal' : null;
        });
        if ($outcome === 'terminal') $this->finished($survey);

        return $outcome;
    }

    /**
     * A submitting row whose worker is gone or retried: zero attempt rows sent nothing and go back to queued for free;
     * an attempt in submitting/unknown is an unknown outcome (never re-sent); otherwise every attempt finished without a
     * stored answer (failed, survey_quota_exhausted after a quota rejection, else survey_rejected).
     *
     * @return ?string 'queued', 'terminal' or null when nothing changed
     */
    private function resolveSubmitting(IguideLabTourSurvey $survey, string $unknownCode, bool $clearDispatch): ?string
    {
        $outcome = $this->labWrite($survey->project_id, 'survey-resolve', function () use ($survey, $unknownCode, $clearDispatch) {
            $submitting = IguideLabTourSurvey::whereKey($survey->id)->where('status', 'submitting');
            if (! (clone $submitting)->exists()) return null;
            $attempts = IguideLabTourSurveyAttempt::where('survey_id', $survey->id)->orderBy('attempt_index')->get();
            if ($attempts->isEmpty()) {
                return $submitting->update(['status' => 'queued'] + ($clearDispatch ? ['dispatched_at' => null] : [])) === 1 ? 'queued' : null;
            }
            $values = $attempts->contains(fn ($attempt) => in_array($attempt->status, ['submitting', 'unknown'], true))
                ? ['status' => 'needs_attention', 'error_code' => $unknownCode]
                : ['status' => 'failed', 'error_code' => $attempts->last()->status === 'quota_rejected' ? 'survey_quota_exhausted' : 'survey_rejected'];

            return $submitting->update($values + ['completed_at' => now()]) === 1 ? 'terminal' : null;
        });
        if ($outcome === 'terminal') $this->finished($survey);

        return $outcome;
    }

    /** 5.6 step 8: map a provider failure. Every transition is conditioned on status submitting. */
    private function afterProviderError(IguideLabTourSurvey $survey, \Throwable $error): void
    {
        if ($error instanceof LabTourReceiptException) {
            $this->attention($survey, 'receipt_unconfirmed');

            return;
        }
        if ($error instanceof LabTourSurveyCapReached || $error instanceof OpenAiImageException) {
            // Defensive: whatever was thrown, an attempt still in submitting/unknown is an unknown outcome.
            if (($error instanceof OpenAiImageException && $error->ambiguous) || $this->outcomeUnknown($survey)) {
                $this->transitionToAttention($survey, 'survey_outcome_unknown');

                return;
            }
            $known = fn () => ! $this->outcomeUnknown($survey);
            if ($error instanceof LabTourSurveyCapReached && $error->reasonCode === 'project_inactive') {
                $zero = fn () => ! IguideLabTourSurveyAttempt::where('survey_id', $survey->id)->exists();
                if ($this->transition($survey, ['submitting'], ['status' => 'cancelled', 'completed_at' => now()], 'survey-cancel', $zero)) $this->finished($survey);
                else $this->fail($survey, ['submitting'], 'survey_rejected', $known);

                return;
            }
            $code = $error instanceof LabTourSurveyCapReached ? $error->reasonCode : match ($error->reason) {
                'quota_exhausted', 'attempt_budget_exhausted' => 'survey_quota_exhausted',
                'not_configured' => 'survey_misconfigured',
                default => 'survey_rejected',
            };
            $this->fail($survey, ['submitting'], $code, $known);

            return;
        }
        // Anything else: nothing sent goes back to queued and the job retries; an unknown outcome needs attention.
        $outcome = $this->resolveSubmitting($survey, 'survey_outcome_unknown', false);
        if ($outcome === 'queued') throw $error;
        Log::warning('Tour survey stopped after an unexpected error.', ['surveyId' => $survey->id, 'exception' => $error::class]);
    }

    /** 5.6 step 4: a queued row that no live capture links is cancelled for free. */
    private function cancelUnlinked(IguideLabTourSurvey $survey): bool
    {
        $cancelled = $this->transition($survey, ['queued'], ['status' => 'cancelled', 'completed_at' => now()], 'survey-cancel',
            fn () => ! $this->linkedLive($survey));
        if ($cancelled) $this->finished($survey);

        return $cancelled;
    }

    /**
     * 5.6 step 5 (free): views, context, exact request bytes, payload file, then data.payload saved while queued.
     *
     * @return ?string the payload bytes, or null when the row left queued meanwhile
     */
    private function prepare(IguideLabTourSurvey $survey): ?string
    {
        $snapshot = LabTourSurveyProtocol::snapshot();
        $views = app(LabTourSurveyViews::class)->prepare($survey);
        [$roomName, $floorName] = $this->names($survey);
        $context = LabTourSurveyProtocol::context($roomName, $floorName);
        $storage = app(LabTourSurveyStorage::class);
        $base = LabTourSurveyStorage::base($survey->project_id, $survey->id);
        $storage->putOnce($base.'/context.json', json_encode($context, self::JSON | JSON_UNESCAPED_UNICODE));
        $images = [];
        foreach ([$views['source'], ...$views['views']] as $image) {
            $file = app(LabStagingAssessmentContext::class)->file($survey->project_id, $image['path'], $image['sha256'], 24 * 1024 * 1024);
            $images[] = ['id' => $image['id'], 'sha256' => $image['sha256'], 'bytes' => (string) file_get_contents($file)];
        }
        $bytes = LabTourSurveyProtocol::request($context, $images);
        unset($images);
        $storage->prepareCompiled($survey->project_id, $survey->id);
        $storage->putOnce($base.'/compiled/payload.json', $bytes);
        $payload = ['path' => $base.'/compiled/payload.json', 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
        $saved = $this->labWrite($survey->project_id, 'survey-payload', function () use ($survey, $payload, $views, $snapshot) {
            $row = IguideLabTourSurvey::whereKey($survey->id)->first();
            if ($row === null || $row->status !== 'queued' || IguideLabTourSurveyAttempt::where('survey_id', $survey->id)->exists()) return false;
            $data = $row->data ?? [];
            if (isset($data['payload']) && $data['payload'] !== $payload) throw new \RuntimeException('tour_survey_payload_changed');
            $data = array_merge($data, ['base' => LabTourSurveyStorage::base($survey->project_id, $survey->id), 'preparedFrom' => $views['preparedFrom'],
                'views' => ['adapterSha256' => $views['adapterSha256'], 'geometrySha256' => $views['geometrySha256'], 'ids' => array_column($views['views'], 'id')],
                'payload' => $payload, 'providerBodySha256' => $payload['sha256'], 'warnings' => array_values($data['warnings'] ?? [])]);

            return IguideLabTourSurvey::whereKey($survey->id)->where('status', 'queued')
                ->update(['data' => json_encode($data, self::JSON), 'protocol' => json_encode($snapshot, self::JSON)]) === 1;
        });

        return $saved ? $bytes : null;
    }

    /**
     * 5.6 step 6: the claim. Requires an active project and room under both caps (D8, counted from the attempt ledger);
     * a revoked or expired project cancels for free, a cap fails for free (no attempt row).
     */
    private function claim(IguideLabTourSurvey $survey): bool
    {
        $outcome = $this->labWrite($survey->project_id, 'survey-claim', function () use ($survey) {
            $row = IguideLabTourSurvey::whereKey($survey->id)->first();
            if ($row === null || $row->status !== 'queued' || IguideLabTourSurveyAttempt::where('survey_id', $survey->id)->exists()) return null;
            $queued = IguideLabTourSurvey::whereKey($survey->id)->where('status', 'queued');
            $now = now();
            if (! IguideLabProject::find($survey->project_id)?->active()) return $queued->update(['status' => 'cancelled', 'completed_at' => $now]) === 1 ? 'terminal' : null;
            if ($this->attemptsUsedByProject($survey->project_id) >= LabTourConfig::surveyCallsPerProject()) {
                return $queued->update(['status' => 'failed', 'error_code' => 'survey_cap_reached', 'completed_at' => $now]) === 1 ? 'terminal' : null;
            }
            if ($this->attemptsUsedToday() >= LabTourConfig::surveyCallsPerDay()) {
                return $queued->update(['status' => 'failed', 'error_code' => 'survey_daily_limit', 'completed_at' => $now]) === 1 ? 'terminal' : null;
            }

            return $queued->update(['status' => 'submitting', 'started_at' => $now]) === 1 ? 'claimed' : null;
        });
        if ($outcome === 'terminal') $this->finished($survey);

        return $outcome === 'claimed';
    }

    /**
     * 5.8 steps 1-8 on the stored answer: verify the retained receipt, check the size after storage, then parse.
     *
     * @throws LabTourSurveyParseException
     */
    private function parseStored(IguideLabTourSurvey $survey): array
    {
        $data = $survey->data ?? [];
        $receipt = $data['providerReceipt'] ?? null;
        $model = $survey->protocol['model'] ?? null;
        $base = LabTourSurveyStorage::base($survey->project_id, $survey->id);
        $attempts = IguideLabTourSurveyAttempt::where('survey_id', $survey->id)->orderBy('attempt_index')->get()->values();
        $last = $attempts->count() - 1;
        $verified = is_string($model) && is_array($receipt) && in_array($attempts->count(), [1, 2], true) && $attempts->pluck('attempt_index')->all() === range(0, $last)
            && $attempts[$last]->status === 'received' && is_int($attempts[$last]->http_status) && $attempts[$last]->http_status >= 200 && $attempts[$last]->http_status < 300
            && $attempts[$last]->requested_model === $model && $attempts[$last]->provider_request_id === $survey->provider_request_id
            && $attempts[$last]->credential_source === ($last === 0 ? 'lab-primary' : 'dashboard-fallback')
            && ($last === 0 || ($attempts[0]->status === 'quota_rejected' && $attempts[0]->http_status === 429 && $attempts[0]->credential_source === 'lab-primary'
                && $attempts[0]->requested_model === $model))
            && ($receipt['response']['path'] ?? null) === $base.'/response-'.$last.'.json' && is_int($receipt['response']['bytes'] ?? null)
            && is_string($receipt['response']['sha256'] ?? null) && is_string($receipt['providerResponseId'] ?? null);
        if (! $verified) throw new LabTourSurveyParseException('survey_unreadable', 'receipt');
        // D6: the size is checked after storage; the body stays on disk, and a raised limit plus a re-parse recovers it.
        if ($receipt['response']['bytes'] > LabTourConfig::surveyMaxResponseBytes()) throw new LabTourSurveyParseException('survey_too_large', 'response_bytes');
        try {
            $file = app(LabStagingAssessmentContext::class)->file($survey->project_id, $receipt['response']['path'], $receipt['response']['sha256'], self::RESPONSE_FILE_MAX_BYTES);
            $body = file_get_contents($file);
        } catch (\Throwable) {
            throw new LabTourSurveyParseException('survey_unreadable', 'receipt_file');
        }
        if (! is_string($body) || strlen($body) !== $receipt['response']['bytes']) throw new LabTourSurveyParseException('survey_unreadable', 'receipt_file');

        // Parse seam: the protocol is resolved from the container (a plain instance unless a test binds a double).
        return app(LabTourSurveyProtocol::class)::parse($body, $model, $receipt['providerResponseId']);
    }

    private function completedValues(array $result): array
    {
        $json = json_encode($result, JSON_THROW_ON_ERROR);

        return ['status' => 'completed', 'result' => $json, 'result_sha256' => hash('sha256', $json), 'parser_version' => LabTourSurveyProtocol::PARSER_VERSION,
            'error_code' => null, 'parse_error' => null, 'completed_at' => now()];
    }

    /** Room and floor names from the source import entry (as the capture copied them), else the case metadata. */
    private function names(IguideLabTourSurvey $survey): array
    {
        $import = IguideLabImport::query()->select(['id', 'project_id', 'status', 'sources'])->where('project_id', $survey->project_id)
            ->where('status', 'completed')->find($survey->source_import_id);
        foreach ($import?->sources ?? [] as $source) {
            if (is_array($source) && ($source['caseId'] ?? null) === $survey->case_id) return [self::name($source['roomName'] ?? null), self::name($source['floorName'] ?? null)];
        }
        $metadata = IguideLabCase::where('project_id', $survey->project_id)->find($survey->case_id)?->metadata ?? [];

        return [self::name($metadata['roomName'] ?? null), self::name($metadata['floorName'] ?? null)];
    }

    private static function name(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function latest(IguideLabTourCapture $capture): ?IguideLabTourSurvey
    {
        return IguideLabTourSurvey::where('project_id', $capture->project_id)->where('case_id', $capture->case_id)
            ->where('preview_sha256', $capture->preview_sha256)->where('survey_version', LabTourSurveyProtocol::VERSION)->orderByDesc('generation')->first();
    }

    /** generation + 1 only without a paid answer or an unknown outcome on the latest row, and under the per-capture cap. */
    private function newGenerationAllowed(IguideLabTourCapture $capture, IguideLabTourSurvey $latest): bool
    {
        $paid = IguideLabTourCapture::whereKey($capture->id)->value('paid_survey_requests') ?? $capture->paid_survey_requests;

        return (int) $paid < LabTourConfig::maxPaidSurveysPerCapture()
            && ! IguideLabTourSurveyAttempt::where('survey_id', $latest->id)->whereIn('status', ['received', 'submitting', 'unknown'])->exists();
    }

    private function reserve(IguideLabTourCapture $capture, string $sourceImportId, int $generation): IguideLabTourSurvey
    {
        $case = IguideLabCase::where('project_id', $capture->project_id)->findOrFail($capture->case_id);
        $id = (string) Str::uuid();
        $survey = IguideLabTourSurvey::create(['id' => $id, 'project_id' => $capture->project_id, 'case_id' => $capture->case_id,
            'source_import_id' => strtolower($sourceImportId), 'case_source_sha256' => $case->source_sha256, 'preview_sha256' => $capture->preview_sha256,
            'survey_version' => LabTourSurveyProtocol::VERSION, 'generation' => $generation, 'status' => 'queued', 'protocol' => $this->reservationSnapshot(),
            'data' => ['base' => LabTourSurveyStorage::base($capture->project_id, $id), 'warnings' => []]]);
        $this->link($capture, $id, true);

        return $survey->fresh();
    }

    private function link(IguideLabTourCapture $capture, string $surveyId, bool $paid): void
    {
        $values = ['survey_id' => $surveyId] + ($paid ? ['paid_survey_requests' => DB::raw('paid_survey_requests + 1')] : []);
        if (! $paid && $capture->survey_id === $surveyId) return;
        IguideLabTourCapture::whereKey($capture->id)->update($values);
        $fresh = IguideLabTourCapture::whereKey($capture->id)->first(['survey_id', 'paid_survey_requests']);
        $capture->forceFill(['survey_id' => $fresh->survey_id, 'paid_survey_requests' => $fresh->paid_survey_requests])->syncOriginalAttributes(['survey_id', 'paid_survey_requests']);
    }

    /** The protocol snapshot at reservation; a misconfiguration is recorded and fails the survey later without a paid call. */
    private function reservationSnapshot(): array
    {
        try {
            return LabTourSurveyProtocol::snapshot();
        } catch (\Throwable) {
            return ['version' => LabTourSurveyProtocol::VERSION, 'recipeFile' => LabTourSurveyProtocol::RECIPE[0], 'recipeSha256' => LabTourSurveyProtocol::RECIPE[1],
                'instructionsSha256' => null, 'schemaSha256' => null, 'protocolSha256' => null, 'model' => null, 'effort' => null, 'maxOutputTokens' => null,
                'wireName' => LabTourSurveyProtocol::WIRE_NAME];
        }
    }

    /** Some capture with one of $statuses links the row in a queued or analysing analysis of its project. */
    private function linkedLive(IguideLabTourSurvey $survey, array $statuses = ['pending', 'surveying']): bool
    {
        return IguideLabTourCapture::where('project_id', $survey->project_id)->where('survey_id', $survey->id)->whereIn('status', $statuses)
            ->whereIn('analysis_id', IguideLabTourAnalysis::select('id')->where('project_id', $survey->project_id)->whereIn('status', ['queued', 'analysing']))
            ->exists();
    }

    private function runTargetExists(IguideLabTourSurvey $survey): bool
    {
        foreach (IguideLabTourCapture::where('project_id', $survey->project_id)->where('survey_id', $survey->id)->get(['analysis_id', 'case_id']) as $capture) {
            $runs = IguideLabTourRun::select('id')->where('project_id', $survey->project_id)->where('analysis_id', $capture->analysis_id);
            if (IguideLabTourRunTarget::where('project_id', $survey->project_id)->where('case_id', $capture->case_id)->whereIn('run_id', $runs)->exists()) return true;
        }

        return false;
    }

    private function outcomeUnknown(IguideLabTourSurvey $survey): bool
    {
        return IguideLabTourSurveyAttempt::where('survey_id', $survey->id)->whereIn('status', ['submitting', 'unknown'])->exists();
    }

    private function transitionToAttention(IguideLabTourSurvey $survey, string $code): void
    {
        if ($this->transition($survey, ['submitting'], ['status' => 'needs_attention', 'error_code' => $code, 'completed_at' => now()], 'survey-attention')) {
            $this->finished($survey);
        }
    }

    private function fail(IguideLabTourSurvey $survey, array $from, string $code, ?\Closure $guard = null): void
    {
        if ($this->transition($survey, $from, ['status' => 'failed', 'error_code' => $code, 'completed_at' => now()], 'survey-fail', $guard)) {
            $this->finished($survey);
        }
    }

    /** One conditional transition in a lab write transaction; true when this call changed the row. */
    private function transition(IguideLabTourSurvey $survey, array $from, array $values, string $context, ?\Closure $guard = null): bool
    {
        return $this->labWrite($survey->project_id, $context, function () use ($survey, $from, $values, $guard) {
            if ($guard !== null && ! $guard()) return false;

            return IguideLabTourSurvey::whereKey($survey->id)->whereIn('status', $from)->update($values) === 1;
        });
    }

    private function whereSeen($query, ?string $seen)
    {
        return $seen === null ? $query->whereNull('dispatched_at') : $query->where('dispatched_at', $seen);
    }

    /** After every terminal transition (5.6 step 9), at the caller's level. The analysis tick re-syncs if this fails. */
    private function finished(IguideLabTourSurvey $survey): void
    {
        try {
            app(LabTourAnalyses::class)->surveyFinished(IguideLabTourSurvey::findOrFail($survey->id));
        } catch (\Throwable $error) {
            Log::warning('Tour survey capture sync failed; the analysis monitor will retry it.', ['surveyId' => $survey->id, 'exception' => $error::class]);
        }
    }

    /** "Lab write transaction" (spec conventions): touch the project row first, then re-read, decide and write. */
    private function labWrite(string $projectId, string $context, callable $write): mixed
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($projectId, $write) {
            IguideLabProject::whereKey($projectId)->update(['updated_at' => now()]);

            return $write();
        }), 'iguide-lab.tour-'.$context);
    }
}
