<?php
namespace App\Console\Commands;

use App\Models\IguideLabProject;
use App\Models\IguideLabTourCapture;
use App\Services\IguideLab\LabTourAnalyses;
use App\Services\IguideLab\LabTourConfig;
use App\Services\IguideLab\LabTourDefaults;
use App\Services\IguideLab\LabTourErrors;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Operator entry to paid tour surveys (spec 11.4, D9): creates, reuses or resumes the shared analysis of an import and
 * allows exactly the next N pending captures to be surveyed; every other pending capture is not dispatchable.
 */
class SurveyIguideLabTour extends Command
{
    protected $signature = 'iguide-lab:tour-survey {project} {import} {--limit= : Required: allow exactly the next N pending captures (1-80)} {--preset=golden-hour}';
    protected $description = 'Survey at most N more captures of a lab import (paid provider calls); never raises the survey caps';

    private const STATUS_TEXT = [401 => 'The lab project is not active.', 404 => 'The import was not found.'];

    public function handle(LabTourAnalyses $analyses): int
    {
        $limit = $this->option('limit');
        if (! is_string($limit) || preg_match('/\A[1-9][0-9]?\z/D', $limit) !== 1 || (int) $limit > 80) {
            $this->error('A --limit between 1 and 80 is required.');
            return self::FAILURE;
        }
        $projectId = (string) $this->argument('project');
        $importId = (string) $this->argument('import');
        if (preg_match(LabTourErrors::UUID_RULE, $projectId) !== 1 || preg_match(LabTourErrors::UUID_RULE, $importId) !== 1) {
            $this->error('The project and import must be UUIDs.');
            return self::FAILURE;
        }
        $preset = $this->option('preset');
        if (! in_array($preset, LabTourDefaults::PRESETS, true)) {
            $this->error('The preset must be golden-hour, twilight, grass or declutter.');
            return self::FAILURE;
        }
        if (! LabTourConfig::analysisEnabled()) {
            $this->error('Tour analysis is disabled.');       // the jobs would not run
            return self::FAILURE;
        }
        $project = IguideLabProject::find(strtolower($projectId));
        if ($project === null) {
            $this->error('The lab project was not found.');
            return self::FAILURE;
        }
        try {
            [$analysis] = $analyses->create($project, ['requestId' => (string) Str::uuid(), 'importId' => $importId, 'preset' => $preset], (int) $limit);
        } catch (HttpExceptionInterface|ModelNotFoundException $e) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 404;
            $this->error($e instanceof HttpExceptionInterface && $e->getMessage() !== '' ? $e->getMessage() : (self::STATUS_TEXT[$status] ?? 'The request was refused.'));
            return self::FAILURE;
        }

        $captures = IguideLabTourCapture::where('project_id', $analysis->project_id)->where('analysis_id', $analysis->id)->get(['status', 'dispatch_allowed']);
        $left = $analyses->surveyCallsLeft($analysis->project_id);
        $this->line(json_encode(['version' => 'tour-survey-receipt/1', 'analysisId' => $analysis->id, 'importId' => $analysis->import_id,
            'status' => $analysis->status, 'captures' => $captures->count(),
            'dispatchAllowed' => $captures->filter(fn ($c) => $c->status === 'pending' && $c->dispatch_allowed)->count(),
            'cachedSurveys' => $captures->where('status', 'surveyed')->count(),
            'pending' => $captures->whereIn('status', ['pending', 'surveying'])->count(),
            'surveyCallsLeftProject' => $left['project'], 'surveyCallsLeftToday' => $left['today']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
