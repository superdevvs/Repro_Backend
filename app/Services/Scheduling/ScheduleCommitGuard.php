<?php

namespace App\Services\Scheduling;

use App\Models\Shoot;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\LockedWrite;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Evaluate remotely before locking; commit only against the evaluated schedule. */
class ScheduleCommitGuard
{
    public function enabled(): bool
    {
        return (bool) config('availability.hybrid_travel_enabled', false);
    }

    public function prepare(array $payload, ?Shoot $shoot = null, ?User $actor = null): array
    {
        return $this->prepareBatch([['payload' => $payload, 'shoot' => $shoot]], $actor);
    }

    public function prepareBatch(array $plans, ?User $actor = null): array
    {
        if (! $this->enabled() || $plans === []) {
            return ['enabled' => false, 'plans' => $plans, 'actor' => $actor];
        }
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Travel feasibility must be prepared before opening a database transaction.');
        }

        $targetVersion = $this->targetVersion($plans);
        $feasibility = app(ScheduleFeasibilityService::class);
        $result = count($plans) === 1
            ? $feasibility->evaluate($plans[0]['payload'], $plans[0]['shoot'] ?? null, $actor)
            : $feasibility->evaluatePlans($plans, $actor);
        $feasibility->assertResult($result, $plans[0]['payload'], $plans[0]['shoot'] ?? null, $actor);

        return ['enabled' => (bool) ($result['enabled'] ?? true), 'plans' => $plans,
            'actor' => $actor, 'result' => $result, 'target_version' => $targetVersion];
    }

    /** $targets optionally maps the callback result to one saved Shoot per prepared plan. */
    public function commit(array $prepared, callable $write, ?callable $targets = null): mixed
    {
        if (! ($prepared['enabled'] ?? false)) {
            return $write();
        }
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('ScheduleCommitGuard must own the outer scheduling transaction.');
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $locks = [];
            $changed = false;
            try {
                $ids = $this->photographerIds($prepared);
                foreach ($ids as $id) {
                    $lock = Cache::store(config('availability.scheduling_lock_store', 'scheduling'))
                        ->lock('schedule:photographer:'.$id, (int) config('availability.scheduling_lock_seconds', 30));
                    $lock->block((int) config('availability.scheduling_lock_wait_seconds', 5));
                    $locks[] = $lock;
                }
                if (! hash_equals($prepared['target_version'], $this->targetVersion($prepared['plans']))) {
                    throw new ConflictHttpException('This booking changed. Reload it before updating its schedule.');
                }
                if (! hash_equals((string) ($prepared['result']['schedule_version'] ?? ''),
                    app(ScheduleFeasibilityService::class)->scheduleFingerprint($ids))) {
                    $changed = true;
                } else {
                    return LockedWrite::run(fn () => DB::transaction(function () use ($write, $targets, $prepared, $ids) {
                        // Recheck after acquiring the write transaction as well. No network here.
                        if (! hash_equals($prepared['target_version'], $this->targetVersion($prepared['plans']))) {
                            throw new ConflictHttpException('This booking changed. Reload it before updating its schedule.');
                        }
                        if (! hash_equals((string) $prepared['result']['schedule_version'],
                            app(ScheduleFeasibilityService::class)->scheduleFingerprint($ids))) {
                            throw new ConflictHttpException('The photographer schedule changed. Refresh availability and try again.');
                        }
                        $value = $write();
                        $saved = $targets ? $targets($value) : $this->defaultTargets($value, $prepared);
                        $this->persistLocations($saved, $prepared);
                        DB::afterCommit(fn () => $this->auditOverride($saved, $prepared));

                        return $value;
                    }), 'hybrid-schedule-commit');
                }
            } catch (LockTimeoutException $exception) {
                throw new ConflictHttpException('Another scheduling change is in progress. Please try again.', $exception);
            } finally {
                foreach (array_reverse($locks) as $lock) {
                    $lock->release();
                }
            }
            if ($changed && $attempt === 0) {
                $refreshed = $this->prepareBatch($prepared['plans'], $prepared['actor']);
                if (! hash_equals($prepared['target_version'], $refreshed['target_version'])) {
                    throw new ConflictHttpException('This booking changed. Reload it before updating its schedule.');
                }
                $prepared = $refreshed;
            }
        }

        throw new ConflictHttpException('The photographer schedule changed. Refresh availability and try again.');
    }

    private function photographerIds(array $prepared): array
    {
        $ids = $prepared['result']['photographer_ids'] ?? collect($prepared['result']['visits'] ?? [])->pluck('photographer_id')->all();
        foreach ($prepared['plans'] as $plan) {
            $shoot = $plan['shoot'] ?? null;
            if ($shoot) {
                $ids[] = $shoot->photographer_id;
                $ids = array_merge($ids, $shoot->serviceItems()->pluck('photographer_id')->all());
            }
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function defaultTargets(mixed $value, array $prepared): array
    {
        $shoot = $value instanceof Shoot ? $value
            : (is_object($value) ? ($value->shoot ?? null) : (is_array($value) ? ($value['shoot'] ?? null) : null));

        return [$shoot instanceof Shoot ? $shoot : ($prepared['plans'][0]['shoot'] ?? null)];
    }

    private function persistLocations(array $targets, array $prepared): void
    {
        foreach ($targets as $index => $shoot) {
            if (! $shoot instanceof Shoot || ! $shoot->exists) {
                continue;
            }
            $result = $prepared['result']['plan_results'][$index] ?? ($index === 0 ? $prepared['result'] : []);
            if (! is_array($result['location'] ?? null) || $result['location'] === []) {
                continue;
            }
            $metadata = app(TravelLocationResolver::class)->persistedMetadata($result['location']);
            if ($metadata === []) {
                continue;
            }
            $current = $shoot->fresh();
            $details = is_array($current->property_details) ? $current->property_details : [];
            $details['schedule_location'] = $metadata;
            $current->forceFill(['property_details' => $details])->saveQuietly();
            $shoot->property_details = $details;
        }
    }

    private function auditOverride(array $targets, array $prepared): void
    {
        $payload = $prepared['plans'][0]['payload'];
        if (! filter_var($payload['travel_override'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || ($prepared['result']['status'] ?? 'available') === 'available') {
            return;
        }
        // Persist the decision and application identifiers, never Google route/ETA content.
        $transitions = array_map(fn (array $transition) => array_intersect_key($transition, array_flip([
            'from_visit_id', 'to_visit_id', 'from_visit_key', 'to_visit_key', 'photographer_id',
            'reason_code', 'reason_codes', 'source', 'status',
            'id', 'direction',
        ])), $prepared['result']['transitions'] ?? []);
        $shootIds = collect($targets)->filter(fn ($target) => $target instanceof Shoot)->pluck('id')->all();
        app(AuditLogService::class)->record('schedule.travel_override', $prepared['actor'], $targets[0] ?? null, [
            'reason' => trim((string) ($payload['travel_override_reason'] ?? '')),
            'reason_codes' => $prepared['result']['reason_codes'] ?? [],
            'policy_version' => $prepared['result']['policy_version'] ?? null,
            'shoot_ids' => $shootIds,
            'transitions' => $transitions,
        ]);
        Log::channel('scheduling')->notice('schedule_travel_override', [
            'actor_id' => $prepared['actor']?->id,
            'shoot_ids' => $shootIds,
            'transition_ids' => collect($transitions)->pluck('id')->filter()->unique()->values()->all(),
            'reason_codes' => $prepared['result']['reason_codes'] ?? [],
            'policy_version' => $prepared['result']['policy_version'] ?? null,
        ]);
    }

    private function targetVersion(array $plans): string
    {
        $rows = [];
        foreach ($plans as $plan) {
            $id = ($plan['shoot'] ?? null)?->id ?? ($plan['payload']['source_shoot_id'] ?? null);
            if ($id) {
                $rows[] = DB::table('shoots')->where('id', $id)->first();
                $rows[] = DB::table('shoot_service')->where('shoot_id', $id)->orderBy('id')->get()->all();
            }
        }
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
