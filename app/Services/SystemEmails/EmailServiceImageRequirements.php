<?php

namespace App\Services\SystemEmails;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Services\Shoots\BracketModeResolver;
use App\Services\Shoots\UploadIntakeResolver;

/** Email requirements use the same booked quantities and capture rules as the dashboard. */
class EmailServiceImageRequirements
{
    public function forService(Shoot $shoot, Service $service): array
    {
        if (! $shoot->exists || ! $service->pivot?->id) {
            return [];
        }

        $shoot->loadMissing(['serviceItems.service', 'serviceItems.photographer']);
        $item = $shoot->serviceItems->firstWhere('id', $service->pivot->id);
        if (! $item) {
            return [];
        }

        return $this->forItem($shoot, $item);
    }

    public function forItem(Shoot $shoot, ShootService $item): array
    {
        $item->setRelation('shoot', $shoot);
        $intake = app(UploadIntakeResolver::class);
        if (! $intake->supportsPhotoIntake($item)) {
            return [];
        }

        return [
            'photo_count' => $intake->contractedPhotoCount($item),
            'expected_raw_count' => app(BracketModeResolver::class)->expectedRawForService($item),
        ];
    }

    public static function label(array $counts, ?string $recipientType): string
    {
        if (! array_key_exists('photo_count', $counts)) {
            return '';
        }
        $final = $counts['photo_count'] === null
            ? 'Final photo count not set'
            : $counts['photo_count'].' final '.((int) $counts['photo_count'] === 1 ? 'photo' : 'photos');
        $role = strtolower(str_replace(['_', '-', ' '], '', $recipientType ?? 'client'));
        if (! in_array($role, ['photographer', 'admin', 'superadmin', 'editingmanager', 'rep', 'salesrep', 'editor'], true)) {
            return $final;
        }
        $raw = $counts['expected_raw_count'] ?? null;

        return $final.' · '.($raw === null ? 'Raw image requirement not set' : $raw.' raw '.((int) $raw === 1 ? 'image' : 'images').' required');
    }
}
