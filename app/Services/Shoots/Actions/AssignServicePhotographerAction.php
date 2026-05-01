<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootMutationSupportService;

class AssignServicePhotographerAction
{
    public function __construct(protected ShootMutationSupportService $shootMutationSupportService)
    {
    }

    public function execute(Shoot $shoot, array $payload, User $actor): Shoot
    {
        $assignments = $this->normalizeAssignments($payload);
        $this->shootMutationSupportService->checkServiceItemPhotographerAvailability(
            $this->buildTargetServices($shoot, $assignments),
            $shoot->photographer_id,
            $shoot->id
        );
        $this->shootMutationSupportService->assignServicePhotographers($shoot, $assignments);

        return $shoot->fresh(['client', 'rep', 'photographer', 'services.category'])
            ?? $shoot->load(['client', 'rep', 'photographer', 'services.category']);
    }

    protected function normalizeAssignments(array $payload): array
    {
        if (isset($payload['service_id'])) {
            return [[
                'service_id' => $payload['service_id'],
                'photographer_id' => $payload['photographer_id'] ?? null,
            ]];
        }

        $assignments = $payload['service_photographers']
            ?? $payload['assignments']
            ?? $payload['services']
            ?? $payload;

        return collect($assignments)
            ->filter(fn ($assignment) => is_array($assignment) && !empty($assignment['service_id']))
            ->map(fn (array $assignment) => [
                'service_id' => (int) $assignment['service_id'],
                'photographer_id' => array_key_exists('photographer_id', $assignment) && $assignment['photographer_id'] !== ''
                    ? (int) $assignment['photographer_id']
                    : null,
            ])
            ->values()
            ->all();
    }

    protected function buildTargetServices(Shoot $shoot, array $assignments): array
    {
        $shoot->loadMissing('services');
        $assignmentsByService = collect($assignments)->keyBy('service_id');

        return $shoot->services->map(function ($service) use ($assignmentsByService) {
            $assignment = $assignmentsByService->get((int) $service->id, []);

            return [
                'id' => (int) $service->id,
                'price' => $service->pivot?->price,
                'quantity' => $service->pivot?->quantity ?? 1,
                'photographer_id' => array_key_exists('photographer_id', $assignment)
                    ? $assignment['photographer_id']
                    : $service->pivot?->photographer_id,
                'scheduled_at' => $service->pivot?->scheduled_at,
            ];
        })->values()->all();
    }
}
