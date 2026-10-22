<?php

namespace App\Services\Photographers;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Booking capability is affirmative profile evidence and cannot be overridden. */
class PhotographerServiceEligibility
{
    public function assertPayload(array $payload, ?Shoot $shoot = null, ?User $actor = null): void
    {
        $fallback = $payload['photographer_id'] ?? $shoot?->photographer_id;
        if (array_key_exists('_schedule_services', $payload)) {
            $rows = $payload['_schedule_services'];
        } elseif ($shoot) {
            $rows = app(\App\Services\Shoots\ShootEditablePayloadService::class)->targetServicesFor($shoot, $payload, $actor);
        } else {
            $rows = app(\App\Services\Shoots\ShootMutationSupportService::class)->mergeServiceItemPayload(
                $payload['services'] ?? array_map(fn ($row) => ['id' => $row['service_id']] + $row, $payload['service_items'] ?? []),
                $payload['service_items'] ?? null, $payload['service_photographers'] ?? null
            );
        }
        if ($shoot && ($payload['action_mode'] ?? 'update') === 'update'
            && ! app(\App\Services\Scheduling\WriteSchedulePlan::class)->changesItinerary($payload, $shoot, $actor)
            && ! $this->changesAssignments($rows, $shoot, $fallback ? (int) $fallback : null)) {
            return;
        }
        $this->assertServices($rows, $fallback ? (int) $fallback : null);
    }

    private function changesAssignments(array $rows, Shoot $shoot, ?int $fallback): bool
    {
        $normalize = fn (array $items) => collect($items)->map(fn ($row) => (int) ($row['service_id'] ?? $row['id']).':'.(int) ($row['photographer_id'] ?? $fallback))
            ->sort()->values()->all();

        return $normalize($rows) !== $normalize($shoot->serviceItems()->get()->map(fn ($item) => [
            'service_id' => $item->service_id, 'photographer_id' => $item->photographer_id,
        ])->all());
    }

    public function canPerform(?User $photographer, Service $service): bool
    {
        if (! $service->requiresPhotographer()) {
            return true;
        }

        return $photographer && $photographer->role === 'photographer'
            && $photographer->isAccountEligibleForAuthentication()
            && $photographer->getServiceCapabilities() !== []
            && $photographer->canPerformService($service->id);
    }

    public function assertServices(array $rows, ?int $fallbackPhotographerId = null): void
    {
        $catalog = Service::with('category')->whereIn('id', collect($rows)
            ->map(fn ($row) => $row['service_id'] ?? $row['id'] ?? null)->filter()->unique())->get()->keyBy('id');
        $photographers = User::whereIn('id', collect($rows)
            ->map(fn ($row) => $row['photographer_id'] ?? $fallbackPhotographerId)->filter()->unique())->get()->keyBy('id');
        foreach ($rows as $index => $row) {
            $service = $catalog->get($row['service_id'] ?? $row['id'] ?? null);
            $photographerId = $row['photographer_id'] ?? $fallbackPhotographerId;
            if (! $service || ! $service->requiresPhotographer() || ! $photographerId
                || ($row['workflow_status'] ?? '') === 'cancelled') {
                continue;
            }
            $photographer = $photographers->get($photographerId);
            if (! $this->canPerform($photographer, $service)) {
                $name = $photographer?->name ?? 'The selected photographer';
                $category = $service->category?->name ?: $service->name;
                throw ValidationException::withMessages([
                    "services.$index.photographer_id" => ["{$name} has no verified capability for {$category}. Choose a qualified photographer or update their profile. This cannot be force scheduled."],
                ]);
            }
        }
    }
}
