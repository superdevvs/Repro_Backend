<?php

namespace App\Services\Messaging;

use App\Models\Shoot;
use App\Services\Shoots\ShootSalesRepResolver;

/** Internal request handling belongs to the account's sales representative. */
final class ShootRequestRecipientRouting
{
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
            return 'rep';
        }

        return $role;
    }

    public static function roles(string $trigger, array $roles): array
    {
        return array_values(array_unique(array_map(fn ($role) => self::role($trigger, (string) $role), $roles)));
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
        }

        return $context;
    }
}
