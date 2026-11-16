<?php
namespace App\Services\IguideLab;

use App\Jobs\MonitorIguideLabTourAnalysis;
use App\Jobs\ProcessIguideLabTourSurvey;
use App\Models\IguideLabImport;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourAnalysisRequest;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourRunTarget;
use App\Models\IguideLabTourSurvey;
use App\Models\IguideLabTourSurveyAttempt;
use App\Models\IguideLabWorkflow;
use App\Support\LockedWrite;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tour analysis records (spec 9.6-9.8, 10.3 E2): create, reuse and resume of the one shared record per import (D9),
 * cancel, survey completion fan-out, the analysis monitor tick, and the capture parts of the record DTO (10.7).
 *
 * Every write is a lab write transaction (spec conventions): the project row is touched first to take the SQLite writer
 * lock, every row the decision depends on is re-read, and writes carry predicates on the expected prior state. Nothing
 * inside a transaction pushes a job, calls the workflow layer, writes a cache or does file or HTTP I/O: closures return
 * what to push, and the caller pushes with LabTourQueue::push() at the baseline transaction level.
 */
class LabTourAnalyses
{
    public const SURVEY_VERSION = 'tour-survey/1';
    public const ACTIVE = ['queued', 'analysing'];
    /** "Outdoor photos go first" hint from the room or floor name (spec 9.7), computed at create. */
    public const EXTERIOR_HINT = '/\b(exterior|outside|outdoor|yard|garden|deck|patio|porch|balcony|terrace|driveway|lawn|pool)\b/i';
    /** Capture errors that came from this survey's parse; a re-parse can turn them surveyed (5.13). */
    public const PARSE_ERRORS = ['survey_unreadable', 'survey_too_large', 'survey_refused', 'survey_incomplete'];
    private const LIVE = ['pending', 'surveying'];
    private const SHA256 = '/\A[a-f0-9]{64}\z/D';

    // ------------------------------------------------------------------ E2 create (10.3)

    /** @return array{0:IguideLabTourAnalysis,1:bool} [record, queuedWork] */
    public function create(IguideLabProject $project, array $input, ?int $operatorLimit = null): array
    {
        abort_unless($project->active(), 401);
        $requestId = $input['requestId'] ?? null;
        $importId = $input['importId'] ?? null;
        $preset = $input['preset'] ?? null;
        foreach ([$requestId, $importId] as $uuid) {
            if (! is_string($uuid) || preg_match(LabTourErrors::UUID_RULE, $uuid) !== 1) throw LabTourErrors::unprocessable('tour_invalid_type');
        }
        if (! in_array($preset, LabTourDefaults::PRESETS, true)) throw LabTourErrors::unprocessable('tour_invalid_type');
        if ($operatorLimit !== null && ($operatorLimit < 1 || $operatorLimit > 80)) throw new \InvalidArgumentException('An operator limit is 1..80.');
        $requestId = strtolower($requestId);
        $importId = strtolower($importId);
        $hash = hash('sha256', json_encode(['importId' => $importId], JSON_THROW_ON_ERROR));

        // Replay before any check (pattern LabImports.php:32-34): no side effects.
        if ($replay = $this->replay($project->id, $requestId, $hash)) return [$replay, false];
        [$analysis, $queuedWork] = LockedWrite::run(fn () => DB::transaction(function () use ($project, $requestId, $importId, $preset, $hash, $operatorLimit) {
            IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]);
            abort_unless($project->fresh()?->active(), 401);
            if ($replay = $this->replay($project->id, $requestId, $hash)) return [$replay, false];
            $import = IguideLabImport::where('project_id', $project->id)->find($importId);
            abort_if($import === null, 404);
            if ($import->status !== 'completed') throw LabTourErrors::public('importNotReady');
            $sources = $this->sources($project->id, $import);
            $existing = IguideLabTourAnalysis::where('project_id', $project->id)->where('import_id', $import->id)->first();

            return $existing
                ? $this->reuse($existing, $requestId, $hash, $operatorLimit)
                : $this->createRecord($project, $import, $sources, $requestId, $hash, $preset, $operatorLimit);
        }), 'iguide-lab.tour-analysis-create');
        if ($queuedWork) $this->pushMonitor($analysis->id);

        return [$analysis, $queuedWork];
    }

    private function replay(string $projectId, string $requestId, string $hash): ?IguideLabTourAnalysis
    {
        $request = IguideLabTourAnalysisRequest::where('project_id', $projectId)->where('request_id', $requestId)->first();
        if ($request === null) return null;
        if (! hash_equals($request->request_hash, $hash)) throw LabTourErrors::public('requestReused');

        return IguideLabTourAnalysis::where('project_id', $projectId)->findOrFail($request->analysis_id);
    }

    /**
     * The import's sources as capture seeds, in sources[] order (= contract order, D9).
     *
     * @return list<array{caseId:string,sourceSha256:string,previewSha256:string,roomName:?string,floorName:?string}>
     */
    private function sources(string $projectId, IguideLabImport $import): array
    {
        $sources = is_array($import->sources) ? array_values($import->sources) : [];
        if (count($sources) > LabTourConfig::maxCaptures()) throw LabTourErrors::unprocessable('tour_too_many_captures');
        // A completed import always has sources; one without them, or with an unusable entry, cannot be a tour.
        if ($sources === []) throw LabTourErrors::unprocessable('tour_invalid_type');
        $out = [];
        foreach ($sources as $source) {
            $caseId = is_array($source) && is_string($source['caseId'] ?? null) ? strtolower($source['caseId']) : null;
            if ($caseId === null || preg_match(LabTourErrors::UUID_RULE, $caseId) !== 1 || isset($out[$caseId])
                || ! is_string($source['sourceSha256'] ?? null) || preg_match(self::SHA256, $source['sourceSha256']) !== 1
                || ! is_string($source['previewSha256'] ?? null) || preg_match(self::SHA256, $source['previewSha256']) !== 1) {
                throw LabTourErrors::unprocessable('tour_invalid_type');
            }
            $out[$caseId] = ['caseId' => $caseId, 'sourceSha256' => $source['sourceSha256'], 'previewSha256' => $source['previewSha256'],
                'roomName' => $this->name($source['roomName'] ?? null), 'floorName' => $this->name($source['floorName'] ?? null)];
        }
        if (DB::table('iguide_lab_cases')->where('project_id', $projectId)->whereIn('id', array_keys($out))->count() !== count($out)) {
            throw LabTourErrors::unprocessable('tour_invalid_type');
        }

        return array_values($out);
    }

    private function name(mixed $value): ?string
    {
        return is_string($value) ? LabTourText::truncate($value, 200) : null;
    }

    /** E2 step 5: a new shared record. */
    private function createRecord(IguideLabProject $project, IguideLabImport $import, array $sources, string $requestId, string $hash, string $preset, ?int $operatorLimit): array
    {
        $analysis = IguideLabTourAnalysis::create(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'import_id' => $import->id,
            'request_id' => $requestId, 'request_hash' => $hash, 'priority_preset' => $preset, 'status' => 'queued', 'record_revision' => 1,
            'selections' => LabTourSelections::initialState()]);
        $captures = new Collection;
        foreach ($sources as $position => $source) {
            $captures->push(IguideLabTourCapture::create(['id' => (string) Str::uuid(), 'analysis_id' => $analysis->id, 'project_id' => $project->id,
                'case_id' => $source['caseId'], 'position' => $position, 'room_name' => $source['roomName'], 'floor_name' => $source['floorName'],
                'source_sha256' => $source['sourceSha256'], 'preview_sha256' => $source['previewSha256'],
                'exterior_hint' => $this->exteriorHint($source['roomName'], $source['floorName']), 'status' => 'pending', 'dispatch_allowed' => true]));
        }
        // Captures whose cached survey is completed become surveyed at once (5.12 lookup, read-only).
        $flags = LabTourDefaults::currentFlags();
        foreach ($captures as $capture) {
            $survey = $this->latestSurvey($capture);
            if ($survey?->status === 'completed' && is_array($survey->result)) {
                IguideLabTourCapture::whereKey($capture->id)->where('status', 'pending')->update($this->surveyedValues($capture, $survey, $flags));
            }
        }
        $captures = $this->captures($analysis);
        if ($operatorLimit !== null) $this->allowNext($analysis, $captures, $operatorLimit);
        $captures = $this->captures($analysis);
        $dispatchable = $captures->filter(fn ($c) => $c->status === 'pending' && $c->dispatch_allowed);
        $this->capsPreCheck($project->id, $dispatchable);
        $status = $this->statusFor($captures);
        if ($status !== 'queued') {
            IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['status' => $status,
                'completed_at' => in_array($status, self::ACTIVE, true) ? null : now()]);
        }
        $this->insertRequest($analysis, $requestId, $hash);

        return [IguideLabTourAnalysis::findOrFail($analysis->id), $dispatchable->isNotEmpty()];
    }

    /** E2 step 4: the shared record of this import (D9): resume, dispatch permissions, caps pre-check. */
    private function reuse(IguideLabTourAnalysis $analysis, string $requestId, string $hash, ?int $operatorLimit): array
    {
        $this->insertRequest($analysis, $requestId, $hash);
        $captures = $this->captures($analysis);
        $before = $captures->mapWithKeys(fn ($c) => [$c->id => [$c->status, (bool) $c->dispatch_allowed]])->all();
        $surveys = app(LabTourSurveys::class);
        $resumed = false;
        if (in_array($analysis->status, ['cancelled', 'failed'], true)) {
            $ids = $captures->filter(fn ($c) => in_array($c->status, ['skipped', 'failed'], true) && $surveys->retryable($c))->pluck('id')->all();
            if ($ids !== []) {
                IguideLabTourCapture::whereIn('id', $ids)->where('analysis_id', $analysis->id)->whereIn('status', ['skipped', 'failed'])
                    ->update(['status' => 'pending', 'error_code' => null]);
                $resumed = true;
                $captures = $this->captures($analysis);
            }
        }
        if ($operatorLimit === null) {
            IguideLabTourCapture::where('analysis_id', $analysis->id)->where('status', 'pending')->where('dispatch_allowed', false)->update(['dispatch_allowed' => true]);
        } else {
            $this->allowNext($analysis, $captures, $operatorLimit);
        }
        $captures = $this->captures($analysis);
        $after = $captures->mapWithKeys(fn ($c) => [$c->id => [$c->status, (bool) $c->dispatch_allowed]])->all();
        if ($after === $before) return [IguideLabTourAnalysis::findOrFail($analysis->id), false];

        // Caps pre-check on the captures that became dispatchable (D8); a 429 rolls the whole transaction back.
        $became = $captures->filter(fn ($c) => $c->status === 'pending' && $c->dispatch_allowed && $before[$c->id] !== ['pending', true]);
        $this->capsPreCheck($analysis->project_id, $became);
        $values = ['monitor_idle' => false];
        if ($resumed) {
            $status = $this->statusFor($captures);
            $values += ['status' => $status, 'cancelled_at' => null, 'completed_at' => in_array($status, self::ACTIVE, true) ? null : now()];
        }
        IguideLabTourAnalysis::whereKey($analysis->id)->where('status', $analysis->status)->toBase()->update($values);
        LabTourRevisions::bump($analysis->id);

        return [IguideLabTourAnalysis::findOrFail($analysis->id), true];
    }

    /** Operator create (D9): no pending capture may dispatch except exactly the next N in dispatch order. */
    private function allowNext(IguideLabTourAnalysis $analysis, Collection $captures, int $limit): void
    {
        $pending = $this->dispatchOrder($captures->filter(fn ($c) => $c->status === 'pending'), $analysis->priority_preset);
        $allowed = $pending->take($limit)->pluck('id')->all();
        IguideLabTourCapture::where('analysis_id', $analysis->id)->where('status', 'pending')->whereNotIn('id', $allowed)->where('dispatch_allowed', true)
            ->update(['dispatch_allowed' => false]);
        if ($allowed !== []) {
            IguideLabTourCapture::where('analysis_id', $analysis->id)->where('status', 'pending')->whereIn('id', $allowed)->where('dispatch_allowed', false)
                ->update(['dispatch_allowed' => true]);
        }
    }

    /**
     * 429 when the dispatchable captures that need a new paid generation (no reusable completed or linked survey, and
     * retryable) exceed the calls left in the project, or when the lab has no call left today (D8, 10.3 E2 step 4).
     */
    private function capsPreCheck(string $projectId, Collection $captures): void
    {
        $surveys = app(LabTourSurveys::class);
        $needed = 0;
        foreach ($captures as $capture) {
            $latest = $this->latestSurvey($capture);
            if ($latest && in_array($latest->status, ['completed', 'queued', 'submitting', 'received', 'cancelled'], true)) continue;
            if ($surveys->retryable($capture)) $needed++;
        }
        if ($needed === 0) return;
        $left = $this->surveyCallsLeft($projectId);
        if ($left['project'] < $needed || $left['today'] === 0) throw LabTourErrors::public('surveyCapReached');
    }

    /**
     * Paid survey calls left (5.11, D8): project lifetime and lab-wide rolling 24 h, clamped to the reader maxima.
     *
     * @return array{project:int,today:int}
     */
    public function surveyCallsLeft(string $projectId): array
    {
        $surveys = app(LabTourSurveys::class);

        return ['project' => max(0, min(240, LabTourConfig::surveyCallsPerProject() - $surveys->attemptsUsedByProject($projectId))),
            'today' => max(0, min(160, LabTourConfig::surveyCallsPerDay() - $surveys->attemptsUsedToday()))];
    }

    private function insertRequest(IguideLabTourAnalysis $analysis, string $requestId, string $hash): void
    {
        IguideLabTourAnalysisRequest::create(['id' => (string) Str::uuid(), 'project_id' => $analysis->project_id, 'analysis_id' => $analysis->id,
            'request_id' => $requestId, 'request_hash' => $hash]);
    }

    private function exteriorHint(?string $room, ?string $floor): bool
    {
        foreach ([$room, $floor] as $name) if (is_string($name) && preg_match(self::EXTERIOR_HINT, $name) === 1) return true;

        return false;
    }

    // ------------------------------------------------------------------ cancel (9.6)

    public function cancel(IguideLabTourAnalysis $analysis): IguideLabTourAnalysis
    {
        $workflowIds = LockedWrite::run(fn () => DB::transaction(function () use ($analysis) {
            IguideLabProject::whereKey($analysis->project_id)->update(['updated_at' => now()]);
            $fresh = IguideLabTourAnalysis::where('project_id', $analysis->project_id)->findOrFail($analysis->id);
            $changed = false;
            if (in_array($fresh->status, self::ACTIVE, true)) {
                $live = IguideLabTourCapture::where('analysis_id', $fresh->id)->whereIn('status', self::LIVE)->get();
                IguideLabTourCapture::where('analysis_id', $fresh->id)->whereIn('status', self::LIVE)->update(['status' => 'skipped', 'error_code' => 'cancelled']);
                foreach ($live->pluck('survey_id')->filter()->unique() as $surveyId) $this->cancelUnusedSurvey($fresh->project_id, $surveyId);
                IguideLabTourAnalysis::whereKey($fresh->id)->whereIn('status', self::ACTIVE)->toBase()->update(['status' => 'cancelled',
                    'cancelled_at' => now(), 'completed_at' => now(), 'monitor_idle' => false, 'monitor_heartbeat_at' => null, 'monitor_token' => null]);
                $changed = true;
            }
            // Runs (WP4): waiting targets cancelled, reserved chunks resolved; workflows to cancel are returned (9.6 step 2).
            $runs = app(LabTourRuns::class)->cancelWaiting($fresh);
            if (($runs['changed'] ?? false) === true) $changed = true;
            if ($changed) LabTourRevisions::bump($fresh->id);

            return array_values(array_unique(array_filter($runs['cancelWorkflowIds'] ?? [], 'is_string')));
        }), 'iguide-lab.tour-analysis-cancel');

        foreach ($workflowIds as $workflowId) {
            try {
                $workflow = IguideLabWorkflow::where('project_id', $analysis->project_id)->find($workflowId);
                if ($workflow) app(LabWorkflows::class)->cancel($workflow);
            } catch (Throwable $e) {
                // The workflow is bound to its run target, so the record shows it and the existing cancel route still works.
                Log::warning('A tour workflow could not be cancelled after the analysis cancel.', ['analysisId' => $analysis->id,
                    'workflowId' => $workflowId, 'exception' => get_class($e)]);
            }
        }

        return IguideLabTourAnalysis::where('project_id', $analysis->project_id)->findOrFail($analysis->id);
    }

    /** A queued survey with no attempt and no other active capture is cancelled (free); its job will no-op (9.6 step 1). */
    private function cancelUnusedSurvey(string $projectId, string $surveyId): void
    {
        if (IguideLabTourSurveyAttempt::where('survey_id', $surveyId)->exists()) return;
        $linked = IguideLabTourCapture::where('project_id', $projectId)->where('survey_id', $surveyId)->whereIn('status', self::LIVE)
            ->whereIn('analysis_id', IguideLabTourAnalysis::select('id')->where('project_id', $projectId)->whereIn('status', self::ACTIVE))->exists();
        if ($linked) return;
        IguideLabTourSurvey::whereKey($surveyId)->where('project_id', $projectId)->where('status', 'queued')->toBase()
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    // ------------------------------------------------------------------ survey completion (9.7, 5.13)

    /** Live transitions only: pending/surveying captures linked to this survey, one transaction per active analysis. */
    public function surveyFinished(IguideLabTourSurvey $survey): void
    {
        $analysisIds = IguideLabTourCapture::where('project_id', $survey->project_id)->where('survey_id', $survey->id)->whereIn('status', self::LIVE)
            ->distinct()->pluck('analysis_id');
        foreach ($analysisIds as $analysisId) {
            LockedWrite::run(fn () => DB::transaction(function () use ($survey, $analysisId) {
                IguideLabProject::whereKey($survey->project_id)->update(['updated_at' => now()]);
                $analysis = IguideLabTourAnalysis::where('project_id', $survey->project_id)->find($analysisId);
                if (! $analysis || ! in_array($analysis->status, self::ACTIVE, true)) return;
                $fresh = IguideLabTourSurvey::where('project_id', $survey->project_id)->find($survey->id);
                if (! $fresh || ! in_array($fresh->status, ['completed', 'failed', 'needs_attention', 'cancelled'], true)) return;
                $captures = $this->captures($analysis);
                $flags = LabTourDefaults::currentFlags();
                $visible = false;
                foreach ($captures as $capture) {
                    if ($capture->survey_id !== $fresh->id || ! in_array($capture->status, self::LIVE, true)) continue;
                    $values = $this->terminalValues($capture, $fresh, $flags);
                    if (IguideLabTourCapture::whereKey($capture->id)->whereIn('status', self::LIVE)->update($values) === 1) $visible = true;
                }
                if (! $visible) return;
                $this->applyStatus($analysis, $this->captures($analysis));
                LabTourRevisions::bump($analysis->id);
            }), 'iguide-lab.tour-survey-finished');
        }
    }

    /** 5.13: after a free re-parse made the survey completed. Returns the number of captures changed. */
    public function surveyReparsed(IguideLabTourSurvey $survey, bool $force): int
    {
        $analysisIds = IguideLabTourCapture::where('project_id', $survey->project_id)->where('survey_id', $survey->id)->distinct()->pluck('analysis_id');
        $updated = 0;
        foreach ($analysisIds as $analysisId) {
            $updated += LockedWrite::run(fn () => DB::transaction(function () use ($survey, $analysisId, $force) {
                IguideLabProject::whereKey($survey->project_id)->update(['updated_at' => now()]);
                $analysis = IguideLabTourAnalysis::where('project_id', $survey->project_id)->find($analysisId);
                if (! $analysis || $analysis->status === 'cancelled') return 0;
                $fresh = IguideLabTourSurvey::where('project_id', $survey->project_id)->find($survey->id);
                if (! $fresh || $fresh->status !== 'completed' || ! is_array($fresh->result)) return 0;
                $defaults = app(LabTourDefaults::class);
                $selections = app(LabTourSelections::class);
                $changed = 0;
                foreach ($this->captures($analysis) as $capture) {
                    if ($capture->survey_id !== $fresh->id) continue;
                    if ($force && $capture->status === 'surveyed') {
                        // Historical runs freeze candidates; a referenced capture is never rebuilt (tourContract.ts:500-503).
                        $referenced = IguideLabTourRunTarget::where('project_id', $analysis->project_id)->where('case_id', $capture->case_id)
                            ->whereIn('run_id', DB::table('iguide_lab_tour_runs')->select('id')->where('analysis_id', $analysis->id))->exists();
                        if ($referenced) continue;
                        $flags = is_array($capture->defaults_flags) ? $capture->defaults_flags : LabTourDefaults::currentFlags();
                        try {
                            $built = $defaults->build($fresh->result, $capture->position, $flags);
                            $defaults->assertCapture($built);
                        } catch (Throwable $e) {
                            $this->logDefaults($capture, $fresh, $e);
                            continue; // a surveyed capture never leaves surveyed (5.13)
                        }
                        if ($built['survey'] === $capture->survey && $built['applicability'] === $capture->applicability && $built['candidates'] === $capture->candidates) continue;
                        IguideLabTourCapture::whereKey($capture->id)->where('status', 'surveyed')->update(['survey' => $this->json($built['survey']),
                            'applicability' => $this->json($built['applicability']), 'candidates' => $this->json($built['candidates']),
                            'defaults_version' => LabTourDefaults::VERSION]);
                        $selections->reconcile($analysis, $capture->case_id);
                        $changed++;
                    } elseif ($capture->status === 'failed' && in_array($capture->error_code, self::PARSE_ERRORS, true)) {
                        $values = $this->surveyedValues($capture, $fresh, LabTourDefaults::currentFlags());
                        if ($values['status'] === 'failed' && $capture->error_code === $values['error_code']) continue;
                        IguideLabTourCapture::whereKey($capture->id)->where('status', 'failed')->update($values);
                        $changed++;
                    }
                }
                if ($changed === 0) return 0;
                $captures = $this->captures($analysis);
                if ($analysis->status === 'failed') {
                    // failed -> ready once a capture is surveyed; completed_at stays (5.13 step 4).
                    if ($captures->contains(fn ($c) => $c->status === 'surveyed')) {
                        IguideLabTourAnalysis::whereKey($analysis->id)->where('status', 'failed')->toBase()->update(['status' => 'ready']);
                    }
                } elseif (in_array($analysis->status, self::ACTIVE, true)) {
                    $this->applyStatus($analysis, $captures);
                }
                LabTourRevisions::bump($analysis->id);

                return $changed;
            }), 'iguide-lab.tour-survey-reparsed');
        }

        return $updated;
    }

    // ------------------------------------------------------------------ monitor (9.7, 9.8)

    public function tick(string $analysisId): bool
    {
        $analysis = IguideLabTourAnalysis::find($analysisId);
        if (! LabTourConfig::analysisEnabled() || ! $analysis || ! in_array($analysis->status, self::ACTIVE, true)) {
            if ($analysis) $this->stopMonitor($analysis->id, false);
            return false;
        }
        // Lab-wide survey sweep (9.9), with its own transactions and pushes.
        app(LabTourSurveys::class)->sweep();

        $state = LockedWrite::run(fn () => DB::transaction(fn () => $this->tickTransaction($analysis->project_id, $analysis->id)),
            'iguide-lab.tour-analysis-tick');
        foreach ($state['push'] as $surveyId) {
            try {
                LabTourQueue::push(new ProcessIguideLabTourSurvey($surveyId));
            } catch (Throwable $e) {
                // dispatched_at was written before the push, so the lab-wide sweep re-dispatches a lost push (9.9).
                Log::warning('A tour survey job could not be queued; the sweep will retry it.', ['surveyId' => $surveyId, 'exception' => get_class($e)]);
            }
        }
        if (! $state['active']) {
            $this->stopMonitor($analysis->id, false);
            return false;
        }
        if ($state['idle']) {
            $this->stopMonitor($analysis->id, true);
            return false;
        }

        return true;
    }

    /** T1 of 9.7 step 3. Returns what to push after the commit. */
    private function tickTransaction(string $projectId, string $analysisId): array
    {
        IguideLabProject::whereKey($projectId)->update(['updated_at' => now()]);
        $analysis = IguideLabTourAnalysis::where('project_id', $projectId)->find($analysisId);
        if (! $analysis || ! in_array($analysis->status, self::ACTIVE, true)) return ['active' => false, 'idle' => false, 'push' => []];
        $captures = $this->captures($analysis);
        $surveyRows = IguideLabTourSurvey::where('project_id', $projectId)->whereIn('id', $captures->pluck('survey_id')->filter()->unique()->values())
            ->get()->keyBy('id');
        $service = app(LabTourSurveys::class);
        $flags = LabTourDefaults::currentFlags();
        $slots = max(0, LabTourConfig::workers() - $service->inFlightCount()); // computed once (5.11)
        $visible = false;
        $push = [];

        // Sync captures that are surveying.
        foreach ($captures as $capture) {
            if ($capture->status !== 'surveying') continue;
            $survey = $capture->survey_id ? $surveyRows->get($capture->survey_id) : null;
            if ($survey === null) {
                Log::warning('A surveying tour capture has no survey row; it is dispatched again.', ['captureId' => $capture->id]);
                $visible = $this->writeCapture($capture, ['status' => 'pending', 'survey_id' => null]) || $visible;
                continue;
            }
            if (in_array($survey->status, ['completed', 'failed', 'needs_attention', 'cancelled'], true)) {
                $visible = $this->writeCapture($capture, $this->terminalValues($capture, $survey, $flags)) || $visible;
            } elseif ($survey->status === 'queued' && $survey->dispatched_at === null && $slots > 0) {
                // A released dispatch marker (flag-off job, 5.15): the row is pushed again.
                if ($this->markDispatched($survey)) { $push[] = $survey->id; $slots--; }
            }
        }

        // Dispatch (D8): pending captures that may dispatch, exterior first for golden hour and grass, then tour order.
        $candidates = $this->dispatchOrder($this->captures($analysis)->filter(fn ($c) => $c->status === 'pending' && $c->dispatch_allowed), $analysis->priority_preset);
        foreach ($candidates as $capture) {
            // With no slot left, a capture that would need a new survey row stays pending without one (it could not be
            // pushed this tick); reusable completed and in-flight rows still link without a slot.
            if ($slots === 0 && $this->needsNewRow($capture)) continue;
            $survey = $service->findOrReserve($capture, $analysis->import_id, true);
            if (in_array($survey->status, ['completed', 'failed', 'needs_attention', 'cancelled'], true)) {
                $visible = $this->writeCapture($capture, $this->terminalValues($capture, $survey, $flags)) || $visible;
            } elseif ($survey->status === 'queued' && $survey->dispatched_at === null) {
                if ($slots > 0) {
                    if ($this->markDispatched($survey)) {
                        $push[] = $survey->id;
                        $slots--;
                    }
                    $visible = $this->writeCapture($capture, ['status' => 'surveying', 'survey_id' => $survey->id]) || $visible;
                } elseif ($capture->survey_id !== $survey->id) {
                    // No slot: the capture stays pending but links the row, so the row is not cancelled as unused (5.6 step 4).
                    IguideLabTourCapture::whereKey($capture->id)->where('status', 'pending')->update(['survey_id' => $survey->id]);
                }
            } else {
                $visible = $this->writeCapture($capture, ['status' => 'surveying', 'survey_id' => $survey->id]) || $visible;
            }
        }

        $captures = $this->captures($analysis);
        $visible = $this->applyStatus($analysis, $captures) || $visible;
        if ($visible) LabTourRevisions::bump($analysis->id);
        $status = $this->statusFor($captures);
        $pending = $captures->where('status', 'pending');

        return ['active' => in_array($status, self::ACTIVE, true), 'push' => $push,
            'idle' => $captures->where('status', 'surveying')->isEmpty() && $pending->isNotEmpty() && $pending->where('dispatch_allowed', true)->isEmpty()];
    }

    private function needsNewRow(IguideLabTourCapture $capture): bool
    {
        $latest = $this->latestSurvey($capture);

        return $latest === null || in_array($latest->status, ['failed', 'needs_attention'], true);
    }

    /** Bookkeeping write when a chain stops: clear heartbeat and token so the next new chain proceeds at once. */
    private function stopMonitor(string $analysisId, bool $idle): void
    {
        LockedWrite::run(fn () => IguideLabTourAnalysis::whereKey($analysisId)->toBase()->update(['monitor_heartbeat_at' => null, 'monitor_token' => null]
            + ($idle ? ['monitor_idle' => true] : [])), 'iguide-lab.tour-analysis-monitor-stop');
    }

    private function markDispatched(IguideLabTourSurvey $survey): bool
    {
        return IguideLabTourSurvey::whereKey($survey->id)->where('status', 'queued')->whereNull('dispatched_at')->toBase()
            ->update(['dispatched_at' => now(), 'dispatch_count' => DB::raw('dispatch_count + 1')]) === 1;
    }

    /** GET exception (9.8, D4): one new chain when stale, no job pending and the per-minute gate is open. Never throws. */
    public function kickIfStale(IguideLabTourAnalysis $analysis): void
    {
        try {
            $fresh = IguideLabTourAnalysis::where('project_id', $analysis->project_id)->find($analysis->id);
            if (! $fresh || ! LabTourConfig::analysisEnabled() || ! $this->needsMonitor($fresh)) return;
            $last = $fresh->monitor_heartbeat_at ?? $fresh->created_at;
            if ($last === null || ! $last->lt(now()->subSeconds(LabTourConfig::heartbeatStaleSeconds()))) return;
            if (LabTourQueue::pendingJobExists(LabTourConfig::queueConnection(), LabTourConfig::queue(), 'MonitorIguideLabTourAnalysis', $fresh->id)) return;
            if (! Cache::add('iguide-lab-tour:kick:analysis:'.$fresh->id, 1, LabTourConfig::kickGateSeconds())) return;
            LabTourQueue::push(new MonitorIguideLabTourAnalysis($fresh->id));
        } catch (Throwable $e) {
            Log::warning('A stale tour analysis monitor could not be restarted.', ['analysisId' => $analysis->id, 'exception' => get_class($e)]);
        }
    }

    public function needsMonitor(IguideLabTourAnalysis $analysis): bool
    {
        return in_array($analysis->status, self::ACTIVE, true) && ! $analysis->monitor_idle;
    }

    private function pushMonitor(string $analysisId): void
    {
        try {
            LabTourQueue::push(new MonitorIguideLabTourAnalysis($analysisId));
        } catch (Throwable $e) {
            // The scheduled sweeper or a GET kick restarts the chain (9.8-9.9).
            Log::warning('A tour analysis monitor could not be queued.', ['analysisId' => $analysisId, 'exception' => get_class($e)]);
        }
    }

    // ------------------------------------------------------------------ DTO parts (10.7)

    /** @return array{total:int,surveyed:int,failed:int,skipped:int,pending:int} */
    public function countsDto(LabTourView $view): array
    {
        $by = fn (string ...$states) => $view->captures->filter(fn ($c) => in_array($c->status, $states, true))->count();

        return ['total' => $view->captures->count(), 'surveyed' => $by('surveyed'), 'failed' => $by('failed'), 'skipped' => $by('skipped'),
            'pending' => $by('pending', 'surveying')];
    }

    /** @return list<array> contract captures by position */
    public function capturesDto(LabTourView $view): array
    {
        $out = [];
        foreach ($view->captures->sortBy('position') as $capture) {
            $surveyed = $capture->status === 'surveyed';
            $settled = in_array($capture->status, ['failed', 'skipped'], true);
            if ($settled && ! is_string($capture->error_code)) {
                throw new \LogicException('A failed or skipped tour capture has no error code (tourContract.ts:200).');
            }
            $out[] = ['caseId' => $capture->case_id, 'order' => (int) $capture->position, 'roomName' => $capture->room_name, 'floorName' => $capture->floor_name,
                'status' => $capture->status, 'error' => $settled ? $capture->error_code : null,
                'survey' => $surveyed ? $capture->survey : null, 'applicability' => $surveyed ? $capture->applicability : null,
                'candidates' => $surveyed ? array_values($capture->candidates ?? []) : []];
        }

        return array_values($out);
    }

    // ------------------------------------------------------------------ shared helpers

    /** @return Collection<int, IguideLabTourCapture> */
    private function captures(IguideLabTourAnalysis $analysis): Collection
    {
        return IguideLabTourCapture::where('project_id', $analysis->project_id)->where('analysis_id', $analysis->id)->orderBy('position')->get();
    }

    private function dispatchOrder(Collection $captures, ?string $priorityPreset): Collection
    {
        $exteriorFirst = in_array($priorityPreset, ['golden-hour', 'grass'], true);

        return $captures->sort(fn ($a, $b) => ($exteriorFirst ? ((int) $b->exterior_hint <=> (int) $a->exterior_hint) : 0) ?: ($a->position <=> $b->position))->values();
    }

    /** Highest generation for the D8 cache key (5.12 lookup), read-only. */
    private function latestSurvey(IguideLabTourCapture $capture): ?IguideLabTourSurvey
    {
        return IguideLabTourSurvey::where('project_id', $capture->project_id)->where('case_id', $capture->case_id)->where('preview_sha256', $capture->preview_sha256)
            ->where('survey_version', self::SURVEY_VERSION)->orderByDesc('generation')->first();
    }

    /** 9.7 step 3 status rule (contract statusIssue, tourContract.ts:358-366). */
    private function statusFor(Collection $captures): string
    {
        $pending = $captures->where('status', 'pending')->count();
        $surveying = $captures->where('status', 'surveying')->count();
        if ($pending + $surveying === $captures->count() && $surveying === 0) return 'queued';
        if ($pending + $surveying > 0) return 'analysing';

        return $captures->where('status', 'surveyed')->isNotEmpty() ? 'ready' : 'failed';
    }

    /** Writes the status rule for an active analysis (predicate on its prior status). Returns true when it changed. */
    private function applyStatus(IguideLabTourAnalysis $analysis, Collection $captures): bool
    {
        $status = $this->statusFor($captures);
        if ($status === $analysis->status) return false;

        return IguideLabTourAnalysis::whereKey($analysis->id)->whereIn('status', self::ACTIVE)->toBase()
            ->update(['status' => $status, 'completed_at' => in_array($status, self::ACTIVE, true) ? null : now()]) === 1;
    }

    /** Capture values once its survey is terminal: surveyed with frozen defaults, or the mapped status and error (5.10). */
    private function terminalValues(IguideLabTourCapture $capture, IguideLabTourSurvey $survey, array $flags): array
    {
        if ($survey->status === 'completed' && is_array($survey->result)) return $this->surveyedValues($capture, $survey, $flags);
        [$status, $error] = app(LabTourSurveys::class)->captureOutcome($survey);
        if (! in_array($status, ['failed', 'skipped'], true)) $status = 'failed';
        if (! is_string($error) || preg_match('/\A[a-z0-9_]{1,64}\z/D', $error) !== 1) {
            Log::warning('A tour survey outcome had no usable capture error.', ['surveyId' => $survey->id]);
            $error = $status === 'skipped' ? 'cancelled' : 'survey_unavailable';
        }

        return ['status' => $status, 'error_code' => $error, 'survey_id' => $survey->id];
    }

    /** Defaults are built and asserted now, with the flags frozen in the capture (6, 6.7). */
    private function surveyedValues(IguideLabTourCapture $capture, IguideLabTourSurvey $survey, array $flags): array
    {
        $defaults = app(LabTourDefaults::class);
        try {
            $built = $defaults->build($survey->result, (int) $capture->position, $flags);
            $defaults->assertCapture($built);
        } catch (Throwable $e) {
            $this->logDefaults($capture, $survey, $e);

            return ['status' => 'failed', 'error_code' => 'defaults_invalid', 'survey_id' => $survey->id];
        }

        return ['status' => 'surveyed', 'error_code' => null, 'survey_id' => $survey->id, 'survey' => $this->json($built['survey']),
            'applicability' => $this->json($built['applicability']), 'candidates' => $this->json($built['candidates']),
            'defaults_version' => LabTourDefaults::VERSION, 'defaults_flags' => $this->json($flags), 'surveyed_at' => now()];
    }

    private function logDefaults(IguideLabTourCapture $capture, IguideLabTourSurvey $survey, Throwable $e): void
    {
        // Class and field path only; never model text (6.7).
        Log::warning('Tour defaults rejected a survey result.', ['captureId' => $capture->id, 'surveyId' => $survey->id, 'exception' => get_class($e),
            'path' => $e instanceof \UnexpectedValueException ? $e->getMessage() : null]);
    }

    /** Live capture write with the prior-state predicate; true when a visible field (status, error) changed. */
    private function writeCapture(IguideLabTourCapture $capture, array $values): bool
    {
        $updated = IguideLabTourCapture::whereKey($capture->id)->whereIn('status', self::LIVE)->update($values) === 1;

        return $updated && (($values['status'] ?? $capture->status) !== $capture->status || array_key_exists('error_code', $values) && $values['error_code'] !== $capture->error_code);
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
