<?php

namespace App\Services\Shoots;

use App\Exceptions\PublicApiResponseException;
use App\Models\{Shoot, User};
use App\Services\Shoots\Actions\{FinalizeEditedUploadAction, FinalizeRawUploadAction};

/** Reuses ordinary submission safeguards before the explicit staff delivery commit. */
class SubmitUploadsBeforeDelivery
{
    public function prepare(Shoot $shoot, User $actor, array $intent): array
    {
        if (($intent['mode'] ?? null) !== 'auto') return $intent;
        $planner = app(DeliveryPlanService::class);
        abort_unless($planner->canFinalize($actor), 403);
        $before = $this->selectionEvidence($planner->plan($shoot, $actor), $intent['component_ids']);
        $capabilities = app(ShootSubmissionCapabilityService::class);
        $changed = false;
        foreach ([
            ['canSubmitRaw', FinalizeRawUploadAction::class],
            ['canSubmitEdits', FinalizeEditedUploadAction::class],
        ] as [$capability, $action]) {
            $shoot = $shoot->fresh();
            if (! $capabilities->$capability($shoot, $actor)) continue;
            $result = app($action)->execute($shoot, $actor);
            if ($result['status'] >= 400) throw new PublicApiResponseException(response()->json($result['payload'], $result['status']));
            $changed = true;
        }
        if (! $changed) return $intent;
        $shoot = $shoot->fresh();
        $planner->forgetReleasedFiles($shoot);
        $after = $planner->plan($shoot, $actor);
        // Submission can change the workflow revision, but it must never silently
        // approve outputs that arrived or were replaced during the handoff.
        if ($before !== $this->selectionEvidence($after, $intent['component_ids'])) {
            throw new PublicApiResponseException(response()->json([
                'message' => 'Uploads were submitted, but delivery content changed. Review the current plan.',
                'delivery_plan' => $after,
            ], 409));
        }
        $intent['plan_revision'] = $after['revision'];
        return $planner->validateSelection($shoot, $actor, $intent);
    }

    private function selectionEvidence(array $plan, array $ids): array
    {
        return collect($plan['components'])->whereIn('id', $ids)->map(fn ($component) => [
            'id' => $component['id'], 'execution_key' => $component['execution_key'],
            'resolved' => $component['resolved'], 'versions' => $component['versions'],
            'external_evidence' => $component['external_evidence'],
        ])->keyBy('id')->sortKeys()->all();
    }
}
