<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One read snapshot of an analysis (spec 10.7): the analysis, its captures by position, and its runs (newest first)
 * with targets and chunks by position. Every DTO of a response is derived from one of these, so counts, statuses,
 * activeRunId and the selection sha can never mix rows from before and after a monitor commit.
 *
 * Database reads only. Call it inside the transaction that frames the response (the lab write transaction that made
 * the change, or DB::transaction() for a read); at the baseline level it opens a read transaction of its own, so its
 * own reads still share one SQLite snapshot. Anything that may write (GET kicks, policy facts) runs before it.
 */
final class LabTourView
{
    /**
     * @param Collection<int, IguideLabTourCapture> $captures
     * @param Collection<int, IguideLabTourRun> $runs
     */
    private function __construct(
        public readonly IguideLabTourAnalysis $analysis,
        public readonly Collection $captures,
        public readonly Collection $runs,
    ) {}

    /** @throws \Illuminate\Database\Eloquent\ModelNotFoundException when the analysis is not in this project */
    public static function load(string $projectId, string $analysisId): self
    {
        $read = static function () use ($projectId, $analysisId): self {
            $analysis = IguideLabTourAnalysis::where('project_id', $projectId)->findOrFail(strtolower($analysisId));
            $captures = IguideLabTourCapture::where('project_id', $projectId)->where('analysis_id', $analysis->id)->orderBy('position')->get();
            $runs = IguideLabTourRun::where('project_id', $projectId)->where('analysis_id', $analysis->id)
                ->with(['targets', 'chunks'])->orderByDesc('created_at')->orderByDesc('id')->get();

            return new self($analysis, $captures, $runs);
        };

        return DB::transactionLevel() > 0 ? $read() : DB::transaction($read);
    }
}
