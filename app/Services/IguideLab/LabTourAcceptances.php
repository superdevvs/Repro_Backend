<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourRun;
use App\Models\IguideLabTourRunTarget;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;

/**
 * "Use this edit anyway" (spec 10.8, D13). Stored only on the run target: accepted_at plus an append-only audit list
 * of {action: accept|undo, at}. Allowed only for a held target with an attempt. Never writes workflow, item, review or
 * composite tables, so the workflow DTO keeps manualAcceptance:false (LabWorkflows.php:413).
 */
class LabTourAcceptances
{
    /** @return array the updated run DTO (tourRunSchema), built inside the same transaction (10.7) */
    public function accept(IguideLabTourAnalysis $analysis, array $input): array
    {
        $projectId = $analysis->project_id;
        $analysisId = $analysis->id;
        $runId = strtolower((string) ($input['runId'] ?? ''));
        $caseId = strtolower((string) ($input['caseId'] ?? ''));
        $accepted = $input['accepted'] ?? null;
        if (! is_bool($accepted)) throw LabTourErrors::unprocessable('tour_invalid_type');

        return LockedWrite::run(fn () => DB::transaction(function () use ($projectId, $analysisId, $runId, $caseId, $accepted) {
            IguideLabProject::whereKey($projectId)->update(['updated_at' => now()]);
            $run = IguideLabTourRun::where('project_id', $projectId)->where('analysis_id', $analysisId)->findOrFail($runId);
            $target = IguideLabTourRunTarget::where('project_id', $projectId)->where('run_id', $run->id)->where('case_id', $caseId)->firstOrFail();
            $log = array_values(is_array($target->acceptance_log) ? $target->acceptance_log : []);
            $entry = ['at' => now()->toIso8601String()];
            $changed = false;
            if ($accepted) {
                if ($target->state !== 'held' || ! $target->has_attempt) throw LabTourErrors::public('notHeld');
                if ($target->accepted_at === null) {
                    $changed = IguideLabTourRunTarget::whereKey($target->id)->where('state', 'held')->where('has_attempt', true)->whereNull('accepted_at')->toBase()
                        ->update(['accepted_at' => now(), 'acceptance_log' => json_encode([...$log, ['action' => 'accept'] + $entry]), 'updated_at' => now()]) === 1;
                }
            } elseif ($target->accepted_at !== null) {
                $changed = IguideLabTourRunTarget::whereKey($target->id)->whereNotNull('accepted_at')->toBase()
                    ->update(['accepted_at' => null, 'acceptance_log' => json_encode([...$log, ['action' => 'undo'] + $entry]), 'updated_at' => now()]) === 1;
            }
            if ($changed) {
                IguideLabTourRun::whereKey($run->id)->toBase()->update(['updated_at' => now()]);
                LabTourRevisions::bump($analysisId);
            }
            $view = LabTourView::load($projectId, $analysisId);

            return app(LabTourRuns::class)->dto($view, $view->runs->firstWhere('id', $run->id));
        }), 'iguide-lab.tour-accept');
    }
}
