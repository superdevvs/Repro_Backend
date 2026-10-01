<?php

namespace App\Services\Messaging;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootSalesRepResolver;
use Illuminate\Support\Collection;

/** Internal request handling belongs to the account's sales representative. */
final class ShootRequestRecipientRouting
{
    public const COMPLETED_CANCELLATION_TRIGGERS = ['SHOOT_CANCELED', 'SHOOT_CANCELLED'];

    public const TRIGGERS = [
        'SHOOT_REQUESTED',
        'SHOOT_ON_HOLD',
        'HOLD_REQUESTED',
        'SHOOT_RESCHEDULE_REQUESTED',
        'SHOOT_CANCELLATION_REQUESTED',
        'SHOOT_CANCELED',
        'SHOOT_CANCELLED',
    ];

    public static function role(string $trigger, string $role): string
    {
        if (in_array($trigger, self::TRIGGERS, true)
            && in_array(strtolower(str_replace(['_', '-', ' '], '', $role)), ['photographer', 'previousphotographer', 'newphotographer'], true)) {
            return self::isCompletedCancellation($trigger) || $trigger === 'SHOOT_ON_HOLD' ? 'photographer' : 'rep';
        }

        return $role;
    }

    public static function roles(string $trigger, array $roles): array
    {
        $roles = array_map(fn ($role) => self::role($trigger, (string) $role), $roles);
        if (self::isCompletedCancellation($trigger) && in_array('photographer', $roles, true)) {
            $roles[] = 'rep';
        }

        return array_values(array_unique($roles));
    }

    public static function isCompletedCancellation(string $trigger): bool
    {
        return in_array($trigger, self::COMPLETED_CANCELLATION_TRIGGERS, true);
    }

    /** Resolve current assignments even when a queued workflow contains stale recipients. */
    public static function context(string $trigger, array $context): array
    {
        if (! in_array($trigger, self::TRIGGERS, true)) {
            return $context;
        }

        $shootId = $context['shoot_id'] ?? data_get($context['shoot'] ?? null, 'id');
        if (is_numeric($shootId)) {
            $shoot = Shoot::with(['client', 'rep'])->find((int) $shootId);
            $context['rep'] = $shoot ? app(ShootSalesRepResolver::class)->resolve($shoot) : null;
            if (self::isCompletedCancellation($trigger) || $trigger === 'SHOOT_ON_HOLD') {
                if ($shoot) {
                    $context['shoot'] = $shoot;
                }
                $photographers = $shoot ? self::cancellationPhotographers($shoot) : collect();
                $context['photographers'] = $photographers->all();
                $context['photographer'] = $photographers->first();
            }
        }

        return $context;
    }

    /** Effective service assignments replace a superseded top-level photographer. */
    public static function cancellationPhotographers(Shoot $shoot): Collection
    {
        $shoot->loadMissing(['photographer', 'services']);
        $services = collect($shoot->services);
        $inheritsPrimary = $services->isEmpty() || $services->contains(fn ($service) => empty($service->pivot->photographer_id));
        $ids = $services->pluck('pivot.photographer_id')
            ->when($inheritsPrimary, fn ($ids) => $ids->push($shoot->photographer_id))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values();

        return $ids->isEmpty() ? collect() : User::whereIn('id', $ids)->get();
    }
}
