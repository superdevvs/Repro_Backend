<?php

namespace App\Services\Scheduling;

use App\Exceptions\PublicApiResponseException;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class ScheduleFeasibilityService
{
    public const POLICY_VERSION = 'hybrid-travel-v1';

    private int $evaluationDepth = 0;

    public function evaluate(array $payload, ?Shoot $shoot = null, ?User $actor = null, bool $alternatives = false): array
    {
        return $this->evaluatePlans([['payload' => $payload, 'shoot' => $shoot]], $actor, $alternatives);
    }

    public function evaluatePlans(array $plans, ?User $actor = null, bool $alternatives = false): array
    {
        if ($this->evaluationDepth === 0) {
            app(TravelLocationResolver::class)->reset();
            app(TravelTimeEstimator::class)->reset();
        }
        $this->evaluationDepth++;
        try {
            return $this->evaluatePreparedPlans($plans, $actor, $alternatives);
        } finally {
            $this->evaluationDepth--;
        }
    }

    private function evaluatePreparedPlans(array $plans, ?User $actor, bool $alternatives): array
    {
        if (! config('availability.hybrid_travel_enabled', false)) {
            return $this->disabled();
        }
        $proposed = [];
        $photographers = [];
        $excluded = [];
        $planResults = [];
        $access = app(TravelScheduleAccess::class);
        $permission = true;
        foreach ($plans as $index => $plan) {
            $shoot = $plan['shoot'] ?? null;
            $payload = $plan['payload'];
            $visits = app(VisitPlanBuilder::class)->build($payload, $shoot, $actor);
            foreach ($visits as $number => &$visit) {
                $visit += ['id' => 'proposed:'.$index.':'.$number, 'plan_index' => $index, 'proposed' => true];
                $photographers[] = $visit['photographer_id'];
            }
            unset($visit);
            if ($shoot) {
                $excluded[] = $shoot->id;
                $photographers = array_merge($photographers, [$shoot->photographer_id], $shoot->serviceItems()->pluck('photographer_id')->all());
            }
            $proposed = array_merge($proposed, $visits);
            $planResults[$index] = ['visits' => $visits, 'location' => [], 'reason_codes' => []];
            $permission = $permission && $access->canOverride($payload, $shoot, $actor);
        }
        $photographers = array_values(array_unique(array_map('intval', array_filter($photographers))));
        sort($photographers);
        // Capture before any network access, so a concurrent insertion cannot be missed.
        $version = $this->scheduleFingerprint($photographers);
        if (count($plans) === 1 && ($plans[0]['payload']['action_mode'] ?? '') === 'update'
            && ($plans[0]['shoot'] ?? null)
            && ! app(WriteSchedulePlan::class)->changesItinerary($plans[0]['payload'], $plans[0]['shoot'], $actor)) {
            return array_merge($this->disabled(), ['enabled' => true, 'visits' => $proposed,
                'schedule_version' => $version, 'photographer_ids' => $photographers,
                'can_override' => $permission, 'can_confirm_location' => $permission,
                'plan_results' => $planResults, '_plans' => $plans]);
        }
        $existing = app(ScheduleVisitRepository::class)->visits($photographers, $excluded);
        $locations = [];
        foreach ($plans as $index => $plan) {
            $locations[$index] = $planResults[$index]['visits'] === [] ? []
                : app(TravelLocationResolver::class)->forPayload($plan['payload'], $plan['shoot'] ?? null, $actor);
            $planResults[$index]['location'] = $locations[$index];
        }
        $assessment = app(ScheduleTransitionEvaluator::class)->evaluate($proposed, $existing, $locations);
        foreach ($planResults as &$planResult) {
            $planResult['reason_codes'] = $assessment['reason_codes'];
        }
        unset($planResult);
        $result = array_merge($assessment, ['enabled' => true, 'available' => $assessment['status'] === 'available',
            'can_override' => $permission && ! $assessment['hard_conflict'], 'can_confirm_location' => $permission,
            'policy_version' => self::POLICY_VERSION, 'schedule_version' => $version,
            'photographer_ids' => $photographers, 'visits' => $proposed, 'alternatives' => [],
            'location' => $locations[0] ?? [], 'plan_results' => $planResults,
            '_plans' => $plans]);
        if ($access->isAdmin($actor)) {
            $result['budget'] = app(GoogleRoutesBudget::class)->status();
        }
        if ($alternatives && count($plans) === 1 && $proposed !== []) {
            $result['alternatives'] = app(ScheduleAlternativeFinder::class)->find($plans[0], $actor, $result);
        }
        Log::channel('scheduling')->info('Travel feasibility evaluated', [
            'status' => $result['status'], 'reason_codes' => $result['reason_codes'],
            'visits' => count($proposed), 'transitions' => count($result['transitions']),
            'sources' => array_values(array_unique(array_column($result['transitions'], 'source'))),
            'policy_version' => self::POLICY_VERSION,
        ]);

        return $result;
    }

    public function scheduleFingerprint(array $photographerIds): string
    {
        return app(ScheduleVisitRepository::class)->fingerprint($photographerIds);
    }

    public function assertResult(array $result, array $payload, ?Shoot $shoot = null, ?User $actor = null): void
    {
        if (! $result['enabled']) {
            return;
        }
        $override = filter_var($payload['travel_override'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($override) {
            abort_unless(app(TravelScheduleAccess::class)->canOverride($payload, $shoot, $actor), 403,
                'You cannot approve a travel exception for this booking.');
            if (mb_strlen(trim((string) ($payload['travel_override_reason'] ?? ''))) < 5) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'travel_override_reason' => ['Explain the travel exception (at least 5 characters).'],
                ]);
            }
        }
        if ($result['available'] || ($override && $result['can_override'])) {
            return;
        }
        $message = $result['status'] === 'review_required'
            ? 'Travel could not be verified. An administrator or sales rep must review this booking.'
            : 'The proposed schedule does not leave enough available time. Review the scheduling details.';
        throw new PublicApiResponseException(response()->json(['message' => $message,
            'errors' => ['travel_schedule' => [$message]], 'feasibility' => $this->publicResult($result)], 422));
    }

    /** Never disclose neighbor identities, locations, internal plans, or signed metadata. */
    public function publicResult(array $result): array
    {
        $public = Arr::only($result, ['enabled', 'status', 'available', 'reason_codes', 'transitions', 'alternatives',
            'can_override', 'can_confirm_location', 'policy_version', 'schedule_version', 'budget']);
        $public['visits'] = array_map(fn ($visit) => Arr::only($visit, ['photographer_id', 'start', 'end', 'scheduled_at', 'duration_minutes', 'timezone']), $result['visits'] ?? []);
        $public['transitions'] = array_map(fn ($edge) => Arr::only($edge, ['id', 'direction', 'photographer_id', 'source',
            'required_minutes', 'available_minutes', 'shortfall_minutes', 'reason_code', 'review_required',
            'drive_minutes', 'distance_miles', 'attribution', 'candidate_start', 'candidate_end', 'earliest_start', 'latest_start']), $result['transitions'] ?? []);

        return $public;
    }

    private function disabled(): array
    {
        return ['enabled' => false, 'available' => true, 'status' => 'available', 'reason_codes' => [],
            'transitions' => [], 'visits' => [], 'alternatives' => [], 'can_override' => false,
            'can_confirm_location' => false, 'policy_version' => self::POLICY_VERSION, 'schedule_version' => null,
            'photographer_ids' => [], 'location' => [], 'plan_results' => []];
    }
}
