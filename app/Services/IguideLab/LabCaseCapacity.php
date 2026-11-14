<?php

namespace App\Services\IguideLab;

use App\Models\IguideLabCase;
use App\Models\IguideLabProject;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;

/** Source records only. Does not change paid quotas, credentials or capability lifetime. */
class LabCaseCapacity
{
    public const DEFAULT_LIMIT = 80;
    public const MAX_LIMIT = 160;

    public function limit(IguideLabProject $project): int
    {
        $limit = $project->max_cases ?? self::DEFAULT_LIMIT;
        abort_unless(is_int($limit) && $limit >= self::DEFAULT_LIMIT && $limit <= self::MAX_LIMIT, 503, 'Source capacity configuration needs operator review.');
        return $limit;
    }

    /** Call after obtaining the project write lock, inside the short admission transaction. */
    public function assertRoom(IguideLabProject $project): void
    {
        $limit = $this->limit($project);
        abort_if(IguideLabCase::where('project_id', $project->id)->count() >= $limit, 429, 'This lab has reached its '.$limit.' source image limit.');
    }

    /** Operator-only mutation; no HTTP route exposes this method. */
    public function set(string $projectId, int $limit): array
    {
        abort_unless($limit >= self::DEFAULT_LIMIT && $limit <= self::MAX_LIMIT, 422, 'Use a source limit between 80 and 160.');
        return LockedWrite::run(fn () => DB::transaction(function () use ($projectId, $limit) {
            // Acquire SQLite's write lock before reading counts or prior configuration.
            IguideLabProject::whereKey($projectId)->update(['updated_at' => now()]);
            $project = IguideLabProject::findOrFail($projectId);
            abort_unless($project->active(), 422, 'The lab project is inactive.');
            $count = IguideLabCase::where('project_id', $projectId)->count();
            abort_if($limit < $count, 422, 'The new limit cannot be below the existing source count.');
            $previous = $this->limit($project);
            $project->update(['max_cases' => $limit]);
            return ['projectId' => $projectId, 'previousMaxCases' => $previous, 'maxCases' => $limit, 'caseCount' => $count,
                'maxRuns' => $project->max_runs, 'usedRuns' => $project->used_runs, 'capabilityChanged' => false, 'providerCalls' => 0];
        }), 'iguide-lab.case-capacity');
    }
}
