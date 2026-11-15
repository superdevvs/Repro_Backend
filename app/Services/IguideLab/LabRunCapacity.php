<?php

namespace App\Services\IguideLab;

use App\Models\IguideLabProject;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;

/** Operator-only lifetime image capacity. Never charges, refunds or grants a workflow. */
class LabRunCapacity
{
    public const MAX_LIMIT = 1024;

    public function extend(string $projectId, int $limit, int $expectedCurrent): array
    {
        abort_unless($limit >= 1 && $limit <= self::MAX_LIMIT
            && $expectedCurrent >= 1 && $expectedCurrent <= self::MAX_LIMIT, 422, 'Use finite generation limits between 1 and 1024.');

        return LockedWrite::run(fn () => DB::transaction(function () use ($projectId, $limit, $expectedCurrent) {
            // Acquire SQLite's writer lock before observing either quota or usage.
            // This no-op leaves updated_at unchanged for rejected or no-op requests.
            IguideLabProject::whereKey($projectId)->update(['updated_at' => DB::raw('updated_at')]);
            $project = IguideLabProject::findOrFail($projectId);
            abort_unless($project->active(), 422, 'The lab project is inactive.');
            $previous = $project->max_runs;
            abort_unless(is_int($previous) && $previous >= 1 && $previous <= self::MAX_LIMIT
                && is_int($project->used_runs) && $project->used_runs >= 0 && $project->used_runs <= $previous,
                503, 'Generation capacity or usage needs operator review.');
            abort_unless($previous === $expectedCurrent, 409, 'The generation limit changed; inspect the current project before retrying.');
            abort_unless($limit >= $previous, 422, 'Generation capacity can only be extended.');

            if ($limit !== $previous) $project->update(['max_runs' => $limit]);
            return ['version' => 'project-run-capacity-change/1', 'projectId' => $projectId,
                'previousMaxRuns' => $previous, 'maxRuns' => $project->max_runs, 'usedRuns' => $project->used_runs,
                'maxCases' => $project->max_cases, 'changed' => $limit !== $previous,
                'observedAt' => now()->toIso8601String(), 'capabilityChanged' => false, 'expiryChanged' => false,
                'providerCalls' => 0];
        }), 'iguide-lab.run-capacity');
    }
}
