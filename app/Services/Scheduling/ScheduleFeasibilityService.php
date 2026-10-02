<?php

namespace App\Services\Scheduling;

use App\Exceptions\PublicApiResponseException;
use App\Models\Shoot;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        $existingById = array_column($existing, null, 'id');
        foreach ($assessment['transitions'] as &$transition) {
            $neighbor = $existingById[$transition['from_visit_id'] ?? '']
                ?? $existingById[$transition['to_visit_id'] ?? ''] ?? null;
            $context = $neighbor ? app(ScheduleVisitRepository::class)->neighborContext($neighbor, $actor) : null;
            if ($context !== null) {
                $transition['neighbor'] = $context;
            }
        }
        unset($transition);
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
        if ($result['can_override'] && ! $result['available']) {
            $result['confirmation_version'] = $this->confirmationVersion($result, $actor);
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

    /** Sign the warning's meaning, not preview-only row IDs or provider seconds. */
    public function confirmationVersion(array $result, ?User $actor): string
    {
        $visits = array_map(function (array $visit) use ($result) {
            $location = $result['plan_results'][$visit['plan_index'] ?? 0]['location'] ?? $result['location'] ?? [];

            return ['photographer_id' => (int) $visit['photographer_id'],
                'start' => $this->confirmationInstant($visit['start']),
                'end' => $this->confirmationInstant($visit['end']),
                'duration_minutes' => (int) $visit['duration_minutes'], 'timezone' => $visit['timezone'] ?? null,
                'location' => [
                    'address_hash' => $location['address_hash'] ?? null,
                    'building_key' => $location['building_key'] ?? null,
                    'verified' => (bool) ($location['verified'] ?? false), 'precision' => $location['precision'] ?? null,
                    'latitude' => isset($location['latitude']) ? (float) $location['latitude'] : null,
                    'longitude' => isset($location['longitude']) ? (float) $location['longitude'] : null,
                ]];
        }, $result['visits'] ?? []);
        $transitions = array_map(function (array $edge) {
            $neighbor = $edge['neighbor'] ?? null;
            $services = $neighbor ? array_map(fn (array $service) => [
                'id' => (int) $service['id'], 'name' => (string) $service['name'],
            ], $neighbor['services'] ?? []) : [];

            return ['direction' => $edge['direction'] ?? null, 'photographer_id' => (int) ($edge['photographer_id'] ?? 0),
                'source' => $edge['source'] ?? null,
                'required_minutes' => isset($edge['required_minutes']) ? (float) $edge['required_minutes'] : null,
                'available_minutes' => isset($edge['available_minutes']) ? (float) $edge['available_minutes'] : null,
                'reason_code' => $edge['reason_code'] ?? null, 'review_required' => (bool) ($edge['review_required'] ?? false),
                // The warning displays rounded-up whole minutes. Sub-minute noise is not a new warning.
                'drive_minutes' => isset($edge['drive_minutes']) ? (int) ceil($edge['drive_minutes']) : null,
                'candidate_start' => $this->confirmationInstant($edge['candidate_start'] ?? null),
                'candidate_end' => $this->confirmationInstant($edge['candidate_end'] ?? null),
                'neighbor' => $neighbor ? ['shoot_id' => (int) $neighbor['shoot_id'],
                    'scheduled_at' => $this->confirmationInstant($neighbor['scheduled_at']),
                    'end_at' => $this->confirmationInstant($neighbor['end_at']), 'timezone' => $neighbor['timezone'] ?? null,
                    'photographer' => isset($neighbor['photographer']) ? [
                        'id' => (int) $neighbor['photographer']['id'], 'name' => (string) $neighbor['photographer']['name'],
                    ] : null,
                    'services' => $this->sortedConfirmationRows($services)] : null];
        }, $result['transitions'] ?? []);
        $reasons = $result['reason_codes'] ?? [];
        sort($reasons, SORT_STRING);
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \LogicException('Application key is required to confirm a scheduling warning.');
        }

        return hash_hmac('sha256', json_encode([
            'actor_id' => $actor?->id, 'policy_version' => $result['policy_version'] ?? self::POLICY_VERSION,
            'schedule_version' => $result['schedule_version'] ?? null, 'status' => $result['status'] ?? null,
            'reason_codes' => $reasons, 'visits' => $this->sortedConfirmationRows($visits),
            'transitions' => $this->sortedConfirmationRows($transitions),
        ], JSON_THROW_ON_ERROR), $key);
    }

    private function confirmationInstant(?string $instant): ?string
    {
        return $instant === null ? null : CarbonImmutable::parse($instant)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function sortedConfirmationRows(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));

        return $rows;
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
            if (! filter_var($payload['travel_override_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'travel_override_confirmed' => ['Review the scheduling warning and deliberately confirm the travel exception before saving.'],
                ]);
            }
            if (mb_strlen(trim((string) ($payload['travel_override_reason'] ?? ''))) < 5) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'travel_override_reason' => ['Explain the travel exception (at least 5 characters).'],
                ]);
            }
            if (! $result['available'] && $result['can_override']) {
                $expected = $this->confirmationVersion($result, $actor);
                $submitted = $payload['travel_override_confirmation_version'] ?? null;
                if (! is_string($submitted) || ! hash_equals($expected, $submitted)) {
                    $message = 'The scheduling warning changed or was not confirmed. Review the current warning and confirm again.';
                    $result['confirmation_version'] = $expected;
                    throw new PublicApiResponseException(response()->json(['message' => $message,
                        'errors' => ['travel_override_confirmation_version' => [$message]],
                        'feasibility' => $this->publicResult($result)], 422));
                }
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

    /** Neighbor context was authorized per booking; never expose internal plans or metadata. */
    public function publicResult(array $result): array
    {
        $public = Arr::only($result, ['enabled', 'status', 'available', 'reason_codes', 'transitions', 'alternatives',
            'can_override', 'can_confirm_location', 'policy_version', 'schedule_version', 'confirmation_version', 'budget']);
        $public['visits'] = array_map(fn ($visit) => Arr::only($visit, ['photographer_id', 'start', 'end', 'scheduled_at', 'duration_minutes', 'timezone']), $result['visits'] ?? []);
        $public['transitions'] = array_map(fn ($edge) => Arr::only($edge, ['id', 'direction', 'photographer_id', 'source',
            'required_minutes', 'available_minutes', 'shortfall_minutes', 'reason_code', 'review_required',
            'drive_minutes', 'distance_miles', 'attribution', 'candidate_start', 'candidate_end', 'earliest_start', 'latest_start', 'neighbor']), $result['transitions'] ?? []);

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
