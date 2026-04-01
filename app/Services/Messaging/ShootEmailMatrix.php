<?php

namespace App\Services\Messaging;

final class ShootEmailMatrix
{
    public const SHOOT_SCHEDULED = 'SHOOT_SCHEDULED';
    public const SHOOT_UPDATED = 'SHOOT_UPDATED';
    public const SHOOT_REMINDER = 'SHOOT_REMINDER';
    public const SHOOT_DELIVERED = 'SHOOT_DELIVERED';
    public const SHOOT_REMOVED = 'SHOOT_REMOVED';
    public const SHOOT_CANCELLED = 'SHOOT_CANCELLED';
    public const SHOOT_PAID = 'SHOOT_PAID';
    public const PAYMENT_CONFIRM = 'PAYMENT_CONFIRM';
    public const PHOTOGRAPHER_CHANGED = 'PHOTOGRAPHER_CHANGED';

    private const MATRIX = [
        self::SHOOT_SCHEDULED => ['client' => true, 'photographer' => true],
        self::SHOOT_UPDATED => ['client' => true, 'photographer' => true],
        self::SHOOT_REMINDER => ['client' => true, 'photographer' => true],
        self::SHOOT_DELIVERED => ['client' => true, 'photographer' => false],
        self::SHOOT_REMOVED => ['client' => true, 'photographer' => true],
        self::SHOOT_CANCELLED => ['client' => true, 'photographer' => true],
        self::SHOOT_PAID => ['client' => true, 'photographer' => false],
        self::PAYMENT_CONFIRM => ['client' => true, 'photographer' => false],
        self::PHOTOGRAPHER_CHANGED => ['client' => false, 'photographer' => true],
    ];

    public static function includesClient(string $event): bool
    {
        return (bool) (self::MATRIX[$event]['client'] ?? false);
    }

    public static function hasEvent(string $event): bool
    {
        return array_key_exists($event, self::MATRIX);
    }

    public static function includesPhotographer(string $event): bool
    {
        return (bool) (self::MATRIX[$event]['photographer'] ?? false);
    }
}
