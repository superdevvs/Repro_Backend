<?php

namespace App\Services\Aryeo\Api;

use App\Models\AryeoApiInstallation;

class Requirements
{
    public const KEYS = ['authorization', 'ordinary_relationships', 'showcase_fulfillment', 'delivery_scope', 'recovery', 'media_benefits', 'commercial_notifications', 'correction_removal', 'bridge_readiness'];

    public static function defaults(): array
    {
        $rows = [];
        foreach (self::KEYS as $key) {
            $rows[$key] = ['status' => 'unconfirmed', 'owner' => $key === 'commercial_notifications' ? 'Ajay' : 'Shubham',
                'counterpart' => $key === 'commercial_notifications' ? 'Matt / Rachel (requested)' : 'Ben (requested)',
                'deadline' => in_array($key, ['authorization', 'ordinary_relationships', 'showcase_fulfillment', 'delivery_scope'], true) ? '2026-11-13' : 'Before pilot approval', 'evidence_url' => null];
        }

        return $rows;
    }

    public function blockers(AryeoApiInstallation $installation, string $workflow): array
    {
        $required = ['authorization', 'delivery_scope', 'recovery', 'media_benefits', 'commercial_notifications', 'correction_removal',
            $workflow === 'showcase' ? 'showcase_fulfillment' : 'ordinary_relationships'];

        return array_values(array_map(fn ($key) => 'qualification_'.$key, array_filter($required, function ($key) use ($installation) {
            $row = ($installation->requirements ?? [])[$key] ?? [];

            return ! in_array($row['status'] ?? '', ['sandbox_demonstrated', 'production_verified'], true)
                || ! filter_var($row['evidence_url'] ?? '', FILTER_VALIDATE_URL);
        })));
    }
}
