<?php

namespace App\Services\GoogleCalendar;

use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\Shoot;
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
        $shoot = Shoot::with(['services'])->find($shootId);
        $mappings = GoogleCalendarEventMapping::query()
            ->where('shoot_id', $shootId)
            ->get()
            ->keyBy(fn (GoogleCalendarEventMapping $mapping) => (string) $mapping->user_id);

        if (!$shoot || !$this->isSyncable($shoot)) {
            $this->removeMappings($mappings);
            return;
        }

        $assignedPhotographerIds = $this->resolveAssignedPhotographerIds($shoot);

        $mappings->each(function (GoogleCalendarEventMapping $mapping, string $userId) use ($assignedPhotographerIds) {
            if (!$assignedPhotographerIds->contains($userId)) {
                $this->removeMapping($mapping);
            }
        });

        foreach ($assignedPhotographerIds as $userId) {
            $connection = GoogleCalendarConnection::with('user')
                ->where('user_id', $userId)
                ->where('sync_enabled', true)
                ->first();

            $mapping = GoogleCalendarEventMapping::query()
                ->where('shoot_id', $shootId)
                ->where('user_id', $userId)
                ->first();

            if (!$connection) {
                if ($mapping) {
                    $mapping->delete();
                }
                continue;
            }

            try {
                $payload = $this->payloadBuilder->build($shoot, $connection->user);
                $fingerprint = sha1(json_encode($payload, JSON_THROW_ON_ERROR));

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
        if (!$shoot->scheduled_at) {
            return false;
        }

        $statuses = collect([
            strtolower((string) $shoot->status),
            strtolower((string) $shoot->workflow_status),
        ]);

        return !$statuses->contains(fn (string $status) => in_array($status, [
            Shoot::STATUS_REQUESTED,
            Shoot::STATUS_CANCELLED,
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
}
