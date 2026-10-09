<?php

namespace App\Services\GoogleCalendar;

use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\Shoot;
use App\Models\ShootService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
        // The observer and booking action can enqueue the same shoot together.
        // Hold a shared lock through the remote request AND mapping persistence.
        // Do not hold a SQLite write transaction while contacting Google.
        Cache::store('scheduling')->lock('google-calendar:shoot:'.$shootId, 300)
            ->block(5, fn () => $this->syncShootUnderLock($shootId));
    }

    protected function syncShootUnderLock(int $shootId): void
    {
        $shoot = Shoot::with(['client', 'services', 'units', 'serviceItems.service', 'serviceItems.unit', 'serviceItems.photographer', 'rep'])->find($shootId);
        if ($shoot?->isInternalTestShoot()) {
            return;
        }

        $mappings = GoogleCalendarEventMapping::query()
            ->where('shoot_id', $shootId)
            ->get();

        if (!$shoot || !$this->isSyncable($shoot)) {
            $this->removeMappings($mappings);
            return;
        }

        // Always sync one Google Calendar event per shoot per photographer.
        // Per-service item events (titles like "HDR Photos" / "Drone") caused
        // overlapping calendar clutter; service names/times live in the
        // shoot-level description instead (including optional Service Timing).
        // Existing per-service mappings are removed below so resync/update
        // collapses old multi-events automatically.
        $assignedPhotographerIds = $this->resolveAssignedPhotographerIds($shoot);

        // Keep at most one shoot-level event per photographer and remove every
        // obsolete per-service duplicate left by the former sync path.
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

        $deferredFailures = [];

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
                if ($mapping && $mapping->calendar_id !== $connection->calendar_id) {
                    $this->removeMapping($mapping);
                    $mapping = null;
                }
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
                // Collect retryable failures and rethrow after every photographer
                // has been attempted, so one bad first-push cannot skip the rest.
                $retry = $this->recordSyncFailure($connection, $exception, [
                    'shoot_id' => $shootId,
                    'user_id' => $userId,
                    'phase' => 'sync_shoot',
                    // First push (no Google event yet) must fail the job so the
                    // queue retries — soft-success left brand-new bookings missing
                    // from photographers' calendars forever.
                    'had_google_event' => (bool) ($mapping?->google_event_id),
                ]);

                if ($retry) {
                    $deferredFailures[] = $exception;
                }
            }
        }

        if ($deferredFailures !== []) {
            throw $deferredFailures[0];
        }
    }

    public function removeShoot(int $shootId): void
    {
        Cache::store('scheduling')->lock('google-calendar:shoot:'.$shootId, 300)
            ->block(5, fn () => $this->removeShootUnderLock($shootId));
    }

    protected function removeShootUnderLock(int $shootId): void
    {
        if (Shoot::find($shootId)?->isInternalTestShoot()) {
            return;
        }

        $this->removeMappings(
            GoogleCalendarEventMapping::query()
                ->where('shoot_id', $shootId)
                ->get()
        );
    }

    public function resyncUser(int $userId): void
    {
        $connection = GoogleCalendarConnection::query()
            ->where('user_id', $userId)
            ->where('sync_enabled', true)
            ->first();

        if (!$connection) {
            // Disconnected / disabled: drop leftover mappings and skip shoot walks.
            GoogleCalendarEventMapping::query()
                ->where('user_id', $userId)
                ->delete();

            return;
        }

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

        // Force-clear any mappings left when a remote delete failed; intentional
        // disconnect must not leave local rows that future jobs could reopen.
        GoogleCalendarEventMapping::query()
            ->where('user_id', $userId)
            ->delete();
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
                $connection->forceFill([
                    'last_error' => $exception->getMessage(),
                ])->save();

                $context = [
                    'shoot_id' => $mapping->shoot_id,
                    'user_id' => $mapping->user_id,
                    'google_event_id' => $mapping->google_event_id,
                    'error' => $exception->getMessage(),
                    'exception' => $exception::class,
                    'phase' => 'remove_mapping',
                ];

                if ($this->isInfrastructureFailure($exception)) {
                    Log::error('Google Calendar event removal blocked by application encryption/config failure; job will retry.', $context);
                    throw $exception;
                }

                Log::error('Google Calendar event removal failed.', $context);

                // Retain the mapping AND fail the queued job so deletion is retried.
                // Returning normally marked failed remote removals as completed.
                throw $exception;
            }
        } else {
            Log::warning('Google Calendar mapping removed without an active connection; Google event may remain.', [
                'shoot_id' => $mapping->shoot_id,
                'user_id' => $mapping->user_id,
                'google_event_id' => $mapping->google_event_id,
                'calendar_id' => $mapping->calendar_id,
            ]);
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

        // Cancelled, requested, declined and held bookings must not reserve
        // time on the photographer's calendar.
        return !$statuses->contains(fn (string $status) => in_array($status, [
            Shoot::STATUS_CANCELLED,
            Shoot::STATUS_REQUESTED,
            Shoot::STATUS_DECLINED,
            Shoot::STATUS_ON_HOLD,
            'hold_on',
        ], true));
    }

    protected function resolveAssignedPhotographerIds(Shoot $shoot): Collection
    {
        // Digital artists are assignments, not onsite calendar/travel reservations.
        return collect(app(\App\Services\Shoots\ShootDurationResolver::class)->windowsForShoot($shoot))
            ->pluck('photographer_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();
    }

    /**
     * @deprecated Per-service calendar events are retired; syncShoot always uses one event per shoot.
     */
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
            if ($this->recordSyncFailure($connection, $exception, [
                'shoot_id' => $shoot->id,
                'shoot_service_id' => $serviceItem->id,
                'user_id' => $userId,
                'phase' => 'sync_service_item',
                'had_google_event' => (bool) ($mapping?->google_event_id),
            ])) {
                throw $exception;
            }
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
     * tracked field: client name, full address, schedule, photographer, services,
     * per-service times, notes, status, workflow status, cancellation state, and
     * the target calendar id. Client phone/email are intentionally omitted — they are no
     * longer rendered in the calendar description. Resolved event start/end also capture
     * timezone and calculated duration changes, which may change without editing the stored
     * schedule. The same fingerprint is applied to both the whole-shoot path
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
            'address' => $payload['location'] ?? null,
            'scheduled_at' => optional($shoot->scheduled_at)?->toIso8601String(),
            'event_start' => $payload['start'] ?? null,
            'event_end' => $payload['end'] ?? null,
            'photographer' => $connection->user_id,
            'services' => $shoot->services->pluck('name')->sort()->values()->all(),
            'unit_context' => $shoot->units->map(fn ($unit) => [$unit->id, $unit->label, $unit->access_notes, $unit->sqft])->all(),
            'service_times' => $shoot->serviceItems
                ->mapWithKeys(fn (ShootService $item) => [
                    $item->id => optional($item->scheduled_at)?->toIso8601String(),
                ])
                ->all(),
            'notes' => $shoot->shoot_notes ?: $shoot->notes,
            'photographer_notes' => $shoot->photographer_notes,
            'property_details' => [
                'presenceOption' => data_get($shoot->property_details, 'presenceOption')
                    ?? data_get($shoot->property_details, 'presence_option'),
                'lockboxCode' => data_get($shoot->property_details, 'lockboxCode')
                    ?? data_get($shoot->property_details, 'lockbox_code'),
                'lockboxLocation' => data_get($shoot->property_details, 'lockboxLocation')
                    ?? data_get($shoot->property_details, 'lockbox_location'),
                'accessNotes' => data_get($shoot->property_details, 'accessNotes')
                    ?? data_get($shoot->property_details, 'access_notes'),
                'accessContactName' => data_get($shoot->property_details, 'accessContactName')
                    ?? data_get($shoot->property_details, 'access_contact_name'),
                'accessContactPhone' => data_get($shoot->property_details, 'accessContactPhone')
                    ?? data_get($shoot->property_details, 'access_contact_phone'),
            ],
            'rep_id' => $shoot->rep_id,
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

    /**
     * Persist last_error and log. Returns true when the queue job should retry
     * for both initial creation and updates to existing events.
     *
     * @param  array<string, mixed>  $context
     */
    protected function recordSyncFailure(GoogleCalendarConnection $connection, Throwable $exception, array $context): bool
    {
        $message = $exception->getMessage();

        $connection->forceFill([
            'last_error' => $message,
            'sync_enabled' => $message === GoogleCalendarService::MISSING_PERMISSION_MESSAGE
                ? false : $connection->sync_enabled,
        ])->save();

        $context = array_merge($context, [
            'error' => $message,
            'exception' => $exception::class,
        ]);

        if ($this->isInfrastructureFailure($exception)) {
            Log::error('Google Calendar sync blocked by application encryption/config failure; job will retry.', $context);

            return true;
        }

        // LOG_LEVEL=error drops warnings; use error so ops see provider failures.
        Log::error('Google Calendar shoot sync failed.', $context);

        // Updates need retries just as first pushes do. syncShoot collects the
        // failure and still attempts the other photographers before rethrowing.
        return true;
    }

    protected function isInfrastructureFailure(Throwable $exception): bool
    {
        if ($exception instanceof MissingAppKeyException) {
            return true;
        }

        // Any failure decrypting the stored OAuth tokens is infrastructure: stale
        // workers, rotated APP_KEY, or corrupt ciphertext. Rethrow so the job
        // retries instead of marking DONE in a few ms with no Google API call.
        if ($exception instanceof DecryptException) {
            return true;
        }

        // Encrypted casts can also surface the missing-key message as a generic
        // RuntimeException depending on Laravel bootstrap order in long-lived workers.
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'no application encryption key');
    }

}
