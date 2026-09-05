<?php

namespace App\Services\GoogleCalendar;

use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\Shoot;
use App\Models\ShootService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleCalendarShootSyncService
{
    public function __construct(
        protected GoogleCalendarService $calendarService,
        protected GoogleCalendarEventPayloadBuilder $payloadBuilder
    ) {
    }

    public function syncShoot(int $shootId): void
    {
        $shoot = Shoot::with(['services', 'serviceItems.service', 'serviceItems.photographer'])->find($shootId);
        $mappings = GoogleCalendarEventMapping::query()
            ->where('shoot_id', $shootId)
            ->get();

        if (!$shoot || !$this->isSyncable($shoot)) {
            $this->removeMappings($mappings);
            return;
        }

        $serviceItems = $this->resolveSyncableServiceItems($shoot);

        if ($serviceItems->isNotEmpty()) {
            $this->syncServiceItemEvents($shoot, $serviceItems, $mappings);
            return;
        }

        $assignedPhotographerIds = $this->resolveAssignedPhotographerIds($shoot);

        // Deleting service rows nulls their mapping foreign keys. Keep at most
        // one of those events as the shoot-level photographer event and remove
        // every obsolete duplicate left by formerly separate service events.
        $keptShootLevelUsers = collect();
        $mappings->each(function (GoogleCalendarEventMapping $mapping) use (
            $assignedPhotographerIds,
            $keptShootLevelUsers
        ) {
            if ($mapping->shoot_service_id || !$assignedPhotographerIds->contains((string) $mapping->user_id)) {
                $this->removeMapping($mapping);

                return;
            }

            $userId = (string) $mapping->user_id;
            if ($keptShootLevelUsers->contains($userId)) {
                $this->removeMapping($mapping);

                return;
            }

            $keptShootLevelUsers->push($userId);
        });

        foreach ($assignedPhotographerIds as $userId) {
            $connection = GoogleCalendarConnection::with('user')
                ->where('user_id', $userId)
                ->where('sync_enabled', true)
                ->first();

            $mapping = GoogleCalendarEventMapping::query()
                ->where('shoot_id', $shootId)
                ->where('user_id', $userId)
                ->whereNull('shoot_service_id')
                ->first();

            if (!$connection) {
                if ($mapping) {
                    $mapping->delete();
                }
                continue;
            }

            try {
                $payload = $this->payloadBuilder->build($shoot, $connection->user);
                $fingerprint = $this->fingerprintFor($shoot, $connection, $payload);

                if (
                    $mapping
                    && $mapping->google_event_id
                    && $mapping->sync_fingerprint === $fingerprint
                    && $mapping->calendar_id === $connection->calendar_id
                ) {
                    $mapping->forceFill([
                        'last_synced_at' => now(),
                    ])->save();

                    $connection->forceFill([
                        'last_synced_at' => now(),
                        'last_error' => null,
                    ])->save();

                    continue;
                }

                $event = $mapping && $mapping->google_event_id
                    ? $this->calendarService->updateEvent($connection, $mapping->google_event_id, $payload)
                    : $this->calendarService->createEvent($connection, $payload);

                GoogleCalendarEventMapping::updateOrCreate(
                    [
                        'shoot_id' => $shoot->id,
                        'shoot_service_id' => null,
                        'user_id' => $userId,
                    ],
                    [
                        'calendar_id' => $connection->calendar_id,
                        'google_event_id' => (string) $event['id'],
                        'sync_fingerprint' => $fingerprint,
                        'last_synced_at' => now(),
                    ]
                );

                $connection->forceFill([
                    'last_synced_at' => now(),
                    'last_error' => null,
                ])->save();
            } catch (Throwable $exception) {
                Log::warning('Google Calendar shoot sync failed.', [
                    'shoot_id' => $shootId,
                    'user_id' => $userId,
                    'error' => $exception->getMessage(),
                ]);

                $connection->forceFill([
                    'last_error' => $exception->getMessage(),
                ])->save();
            }
        }
    }

    public function removeShoot(int $shootId): void
    {
        $this->removeMappings(
            GoogleCalendarEventMapping::query()
                ->where('shoot_id', $shootId)
                ->get()
        );
    }

    public function resyncUser(int $userId): void
    {
        $shootIds = Shoot::query()
            ->where('photographer_id', $userId)
            ->orWhereIn('id', function ($query) use ($userId) {
                $query->select('shoot_id')
                    ->from('shoot_service')
                    ->where('photographer_id', $userId);
            })
            ->pluck('id')
            ->merge(
                GoogleCalendarEventMapping::query()
                    ->where('user_id', $userId)
                    ->pluck('shoot_id')
            )
            ->unique()
            ->values();

        foreach ($shootIds as $shootId) {
            $this->syncShoot((int) $shootId);
        }
    }

    public function disconnectUser(int $userId): void
    {
        $this->removeMappings(
            GoogleCalendarEventMapping::query()
                ->where('user_id', $userId)
                ->get()
        );
    }

    protected function removeMappings(Collection $mappings): void
    {
        $mappings->each(fn (GoogleCalendarEventMapping $mapping) => $this->removeMapping($mapping));
    }

    protected function removeMapping(GoogleCalendarEventMapping $mapping): void
    {
        $connection = GoogleCalendarConnection::with('user')
            ->where('user_id', $mapping->user_id)
            ->first();

        if ($connection) {
            try {
                $this->calendarService->deleteEvent($connection, $mapping->calendar_id, $mapping->google_event_id);

                $connection->forceFill([
                    'last_synced_at' => now(),
                    'last_error' => null,
                ])->save();
            } catch (Throwable $exception) {
                Log::warning('Google Calendar event removal failed.', [
                    'shoot_id' => $mapping->shoot_id,
                    'user_id' => $mapping->user_id,
                    'error' => $exception->getMessage(),
                ]);

                $connection->forceFill([
                    'last_error' => $exception->getMessage(),
                ])->save();
            }
        }

        $mapping->delete();
    }

    protected function isSyncable(Shoot $shoot): bool
    {
        $hasServiceItemSchedule = $shoot->serviceItems
            ->contains(fn (ShootService $item) => $item->scheduled_at !== null);

        if (!$shoot->scheduled_at && !$hasServiceItemSchedule) {
            return false;
        }

        $statuses = collect([
            strtolower((string) $shoot->status),
            strtolower((string) $shoot->workflow_status),
        ]);

        // Cancelled shoots remain syncable (keep-and-update): the existing calendar
        // event is updated in place with a "CANCELLED - {client}" title rather than
        // deleted (Requirements 8.1, 8.2). Requested / declined / on_hold / hold_on
        // stay non-syncable and continue to be removed.
        return !$statuses->contains(fn (string $status) => in_array($status, [
            Shoot::STATUS_REQUESTED,
            Shoot::STATUS_DECLINED,
            Shoot::STATUS_ON_HOLD,
            'hold_on',
        ], true));
    }

    protected function resolveAssignedPhotographerIds(Shoot $shoot): Collection
    {
        return collect([$shoot->photographer_id])
            ->merge($shoot->services->pluck('pivot.photographer_id')->all())
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();
    }

    protected function syncServiceItemEvents(Shoot $shoot, Collection $serviceItems, Collection $mappings): void
    {
        $eventTargets = $serviceItems
            ->map(function (ShootService $serviceItem) use ($shoot) {
                $photographerId = $serviceItem->photographer_id ?: $shoot->photographer_id;

                if (!$photographerId || !$serviceItem->scheduled_at) {
                    return null;
                }

                return [
                    'service_item' => $serviceItem,
                    'user_id' => (int) $photographerId,
                    'key' => $this->mappingKey($photographerId, $serviceItem->id),
                ];
            })
            ->filter()
            ->values();

        $validKeys = $eventTargets->pluck('key');

        $mappings->each(function (GoogleCalendarEventMapping $mapping) use ($validKeys) {
            $mappingKey = $this->mappingKey($mapping->user_id, $mapping->shoot_service_id);
            if (!$validKeys->contains($mappingKey)) {
                $this->removeMapping($mapping);
            }
        });

        foreach ($eventTargets as $target) {
            $this->syncServiceItemEvent(
                $shoot,
                $target['service_item'],
                (int) $target['user_id']
            );
        }
    }

    protected function syncServiceItemEvent(Shoot $shoot, ShootService $serviceItem, int $userId): void
    {
        $connection = GoogleCalendarConnection::with('user')
            ->where('user_id', $userId)
            ->where('sync_enabled', true)
            ->first();

        $mapping = GoogleCalendarEventMapping::query()
            ->where('shoot_id', $shoot->id)
            ->where('shoot_service_id', $serviceItem->id)
            ->where('user_id', $userId)
            ->first();

        if (!$connection) {
            if ($mapping) {
                $mapping->delete();
            }
            return;
        }

        try {
            $payload = $this->payloadBuilder->buildForServiceItem($shoot, $serviceItem, $connection->user);
            $fingerprint = $this->fingerprintFor($shoot, $connection, $payload);

            if (
                $mapping
                && $mapping->google_event_id
                && $mapping->sync_fingerprint === $fingerprint
                && $mapping->calendar_id === $connection->calendar_id
            ) {
                $mapping->forceFill([
                    'last_synced_at' => now(),
                ])->save();

                $connection->forceFill([
                    'last_synced_at' => now(),
                    'last_error' => null,
                ])->save();

                return;
            }

            $event = $mapping && $mapping->google_event_id
                ? $this->calendarService->updateEvent($connection, $mapping->google_event_id, $payload)
                : $this->calendarService->createEvent($connection, $payload);

            GoogleCalendarEventMapping::updateOrCreate(
                [
                    'shoot_id' => $shoot->id,
                    'shoot_service_id' => $serviceItem->id,
                    'user_id' => $userId,
                ],
                [
                    'calendar_id' => $connection->calendar_id,
                    'google_event_id' => (string) $event['id'],
                    'sync_fingerprint' => $fingerprint,
                    'last_synced_at' => now(),
                ]
            );

            $connection->forceFill([
                'last_synced_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            Log::warning('Google Calendar service item sync failed.', [
                'shoot_id' => $shoot->id,
                'shoot_service_id' => $serviceItem->id,
                'user_id' => $userId,
                'error' => $exception->getMessage(),
            ]);

            $connection->forceFill([
                'last_error' => $exception->getMessage(),
            ])->save();
        }
    }

    protected function resolveSyncableServiceItems(Shoot $shoot): Collection
    {
        return $shoot->serviceItems
            ->filter(function (ShootService $serviceItem) use ($shoot) {
                if (!$serviceItem->scheduled_at) {
                    return false;
                }

                if (!$serviceItem->photographer_id && !$shoot->photographer_id) {
                    return false;
                }

                return !in_array($serviceItem->workflow_status, [
                    ShootService::WORKFLOW_CANCELLED,
                ], true);
            })
            ->values();
    }

    protected function mappingKey(int|string|null $userId, int|string|null $serviceItemId = null): string
    {
        return (string) $userId . ':' . ($serviceItemId ? (string) $serviceItemId : 'legacy');
    }

    /**
     * Compute the broadened sync fingerprint from a canonical signature of the underlying
     * shoot/connection fields rather than the full rendered payload, so update detection is
     * robust against payload formatting tweaks (Req 9.1-9.3). The signature captures every
     * tracked field: client name/phone/email, full address, schedule, photographer,
     * services, per-service times, notes, status, workflow status, cancellation state, and
     * the target calendar id. Resolved event start/end also capture timezone and calculated
     * duration changes, which may change without editing the stored schedule. The same
     * fingerprint is applied to both the whole-shoot path
     * (syncShoot) and the per-service-item path (syncServiceItemEvent).
     *
     * The address reuses the already-built payload location (formatFullAddress output) to
     * avoid recomputing it here.
     */
    protected function fingerprintFor(Shoot $shoot, GoogleCalendarConnection $connection, array $payload): string
    {
        $client = $shoot->client;

        $signature = [
            'client_name' => $client?->name,
            'client_phone' => $client?->phone ?: $client?->phonenumber,
            'client_email' => $client?->email,
            'address' => $payload['location'] ?? null,
            'scheduled_at' => optional($shoot->scheduled_at)?->toIso8601String(),
            'event_start' => $payload['start'] ?? null,
            'event_end' => $payload['end'] ?? null,
            'photographer' => $connection->user_id,
            'services' => $shoot->services->pluck('name')->sort()->values()->all(),
            'service_times' => $shoot->serviceItems
                ->mapWithKeys(fn (ShootService $item) => [
                    $item->id => optional($item->scheduled_at)?->toIso8601String(),
                ])
                ->all(),
            'notes' => $shoot->shoot_notes ?: $shoot->notes,
            'photographer_notes' => $shoot->photographer_notes,
            'status' => $shoot->status,
            'workflow_status' => $shoot->workflow_status,
            'cancelled' => $this->isCancelledStatus($shoot),
            'calendar_id' => $connection->calendar_id,
        ];

        return sha1(json_encode($signature, JSON_THROW_ON_ERROR));
    }

    /**
     * Replicates the payload builder's cancellation check: a shoot is cancelled when its
     * lowercased status or workflow_status equals Shoot::STATUS_CANCELLED ('cancelled').
     */
    protected function isCancelledStatus(Shoot $shoot): bool
    {
        $status = strtolower(trim((string) $shoot->status));
        $workflowStatus = strtolower(trim((string) $shoot->workflow_status));

        return $status === Shoot::STATUS_CANCELLED
            || $workflowStatus === Shoot::STATUS_CANCELLED;
    }
}
