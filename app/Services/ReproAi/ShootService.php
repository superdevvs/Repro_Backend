<?php

namespace App\Services\ReproAi;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\Invoices\InvoiceAdjustmentService;
use App\Services\ShootActivityLogger;
use App\Services\ShootTaxService;
use App\Services\ShootWorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShootService
{
    private ShootMediaStorageService $mediaStorageService;

    private ShootWorkflowService $workflowService;

    private ShootTaxService $taxService;

    private ShootActivityLogger $activityLogger;

    public function __construct()
    {
        $this->mediaStorageService = app(ShootMediaStorageService::class);
        $this->workflowService = app(ShootWorkflowService::class);
        $this->taxService = app(ShootTaxService::class);
        $this->activityLogger = app(ShootActivityLogger::class);
    }

    /**
     * Create a shoot from Robbie flow data
     */
    public function createFromReproAi(int $userId, array $data): Shoot
    {
        $guard = app(\App\Services\Scheduling\ScheduleCommitGuard::class);
        $prepared = $guard->prepare(['action_mode' => 'create', '_schedule_visits' => [],
            'client_id' => $data['client_id'] ?? $userId], null, User::findOrFail($userId));
        $shoot = $guard->commit($prepared, fn () => DB::transaction(function () use ($userId, $data) {
            $user = User::findOrFail($userId);
            $clientId = (int) ($data['client_id'] ?? $userId);
            $client = User::whereKey($clientId)->where('role', 'client')->first();
            if (! $client) {
                throw new \Exception('A valid client is required before booking a shoot');
            }

            // Parse services
            $serviceIds = $data['service_ids'] ?? [];
            if (empty($serviceIds)) {
                throw new \Exception('No services selected');
            }

            $services = Service::whereIn('id', $serviceIds)->get();
            if ($services->isEmpty()) {
                throw new \Exception('Invalid services selected');
            }

            // Calculate base quote
            $baseQuote = $this->calculateBaseQuote($services);

            // Determine tax
            $state = $data['property_state'] ?? 'CA';
            $taxRegion = $this->taxService->determineTaxRegion($state);
            $taxCalculation = $this->taxService->calculateTotal($baseQuote, $taxRegion);

            // Parse date and time
            $scheduledAt = null;
            if (! empty($data['date'])) {
                $date = \Carbon\Carbon::parse($data['date']);
                $timeWindow = $data['time_window'] ?? 'Flexible';
                $time = $this->parseTimeWindow($timeWindow);

                if ($time) {
                    $scheduledAt = $date->copy()->setTimeFromTimeString($time);
                } else {
                    $scheduledAt = $date->copy()->setTime(12, 0); // Default to noon
                }
            }

            // Use ShootWorkflowService constants for status
            $initialStatus = $scheduledAt
                ? ShootWorkflowService::STATUS_SCHEDULED
                : ShootWorkflowService::STATUS_HOLD_ON;

            // Create shoot
            $shoot = Shoot::create([
                'client_id' => $client->id,
                'rep_id' => null, // Can be enhanced later
                'photographer_id' => null, // Can be selected in flow later
                'service_id' => $services->first()->id, // Legacy support
                'address' => $data['property_address'] ?? '',
                'city' => $data['property_city'] ?? '',
                'state' => $state,
                'zip' => $data['property_zip'] ?? '',
                'scheduled_at' => $scheduledAt,
                'scheduled_date' => $scheduledAt ? $scheduledAt->format('Y-m-d') : null,
                'time' => $scheduledAt ? $scheduledAt->format('H:i') : null,
                'status' => $initialStatus,
                'workflow_status' => Shoot::WORKFLOW_BOOKED, // Use Shoot model constant
                'base_quote' => $taxCalculation['base_quote'],
                'tax_region' => $taxCalculation['tax_region'],
                'tax_percent' => $taxCalculation['tax_percent'],
                'tax_amount' => $taxCalculation['tax_amount'],
                'total_quote' => $taxCalculation['total_quote'],
                'bypass_paywall' => false,
                'payment_status' => 'unpaid',
                'created_by' => $user->name,
                'updated_by' => $user->name,
            ]);

            // Attach services
            $pivotData = $services->mapWithKeys(function ($service) {
                return [
                    $service->id => [
                        'price' => $service->price ?? 0,
                        'quantity' => 1,
                        'duration_minutes' => $service->getShootDurationMinutes(),
                        'photographer_pay' => $service->photographer_pay ?? null,
                    ],
                ];
            })->toArray();

            $shoot->services()->sync($pivotData);

            // Initialize workflow
            if ($scheduledAt) {
                $this->workflowService->schedule($shoot, $scheduledAt, $user);
            }

            // Log activity
            $this->activityLogger->log(
                $shoot,
                'shoot_created',
                [
                    'by' => $user->name,
                    'status' => $initialStatus,
                    'scheduled_at' => $scheduledAt?->toIso8601String(),
                    'source' => 'ai_chat',
                ],
                $user
            );

            return $shoot->load(['client', 'services']);
        }));
        // Create Dropbox folders if scheduled
        if ($shoot->scheduled_at) {
            try {
                $this->mediaStorageService->createShootFolders($shoot);
            } catch (\Throwable $mediaStorageServiceError) {
                Log::warning('Robbie booking Dropbox folder creation failed', [
                    'shoot_id' => $shoot->id,
                    'error' => $mediaStorageServiceError->getMessage(),
                ]);
            }
        }

        return $shoot;
    }

    private function calculateBaseQuote($services): float
    {
        $total = 0;
        foreach ($services as $service) {
            $total += ($service->price ?? 0);
        }

        return round($total, 2);
    }

    private function parseTimeWindow(string $timeWindow): ?string
    {
        $timeWindow = trim($timeWindow);
        if (preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $timeWindow)
            || preg_match('/^(?:[1-9]|1[0-2])(?::[0-5]\d)?\s*[ap]m$/i', $timeWindow)) {
            return \Carbon\Carbon::parse($timeWindow)->format('H:i');
        }
        if (str_contains($timeWindow, 'Morning')) {
            return '10:00'; // Default morning time
        } elseif (str_contains($timeWindow, 'Afternoon')) {
            return '14:00'; // Default afternoon time
        } elseif (str_contains($timeWindow, 'Evening')) {
            return '17:00'; // Default evening time
        }

        return '12:00'; // Default to noon
    }

    /**
     * List upcoming shoots for a user (next 30 days)
     */
    public function listUpcomingForUser(int $userId, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return Shoot::where(function ($query) use ($userId) {
            $query->where('client_id', $userId)
                ->orWhere('rep_id', $userId);
        })
            ->where('scheduled_at', '>=', now())
            ->where('scheduled_at', '<=', now()->addDays(30))
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->orderBy('scheduled_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Update a shoot from AI conversation data
     */
    public function updateFromAiConversation(Shoot $shoot, array $data, User $user): Shoot
    {
        $guard = app(\App\Services\Scheduling\ScheduleCommitGuard::class);
        $prepared = ['enabled' => false];
        $scheduleChanges = [];
        $unitPlan = null;
        $unitRequest = null;
        $isMultiUnit = app(\App\Services\Shoots\MultiUnitBookingService::class)->handles($shoot, []);
        if ($isMultiUnit && ! empty($data['service_ids'])) {
            throw ValidationException::withMessages(['services' => [
                'Use the unit service editor to change services on a multi-unit booking.',
            ]]);
        }
        if (! empty($data['date'])) {
            $time = $this->parseTimeWindow($data['time_window'] ?? $shoot->time ?? '12:00') ?: '12:00';
            $rescheduler = app(\App\Services\Shoots\MultiUnitRescheduleService::class);
            if ($isMultiUnit) {
                $unitRequest = new \App\Models\ShootRescheduleRequest(['requested_date' => $data['date'],
                    'requested_time' => $time, 'units_revision' => $shoot->units_revision]);
                $unitPlan = $rescheduler->plan($shoot, $unitRequest, $user);
                $scheduleChanges = $unitPlan['changes'];
            } else {
                // The wall-clock resolver also supplies scheduled_at for legacy
                // unzoned bookings, whose normalizer intentionally does not infer it.
                $wallClock = $rescheduler->resolveRequestedWallClock($shoot, $data['date'], $time);
                $scheduleChanges = app(\App\Services\Schedule\ShootScheduleUpdateInput::class)->normalize($shoot,
                    \Illuminate\Support\Arr::only($wallClock, ['scheduled_at', 'scheduled_date', 'time']));
            }
        }
        $at = ! empty($scheduleChanges['scheduled_at']) ? \Carbon\Carbon::parse($scheduleChanges['scheduled_at']) : $shoot->scheduled_at;
        $planner = app(\App\Services\Scheduling\WriteSchedulePlan::class);
        $rows = $unitPlan['services'] ?? $planner->storedServices($shoot);
        $movedServices = collect($scheduleChanges['services'] ?? [])->keyBy('id');
        foreach ($rows as &$row) {
            $moved = $movedServices->get($row['id']);
            if ($moved && array_key_exists('scheduled_at', $moved)) {
                $row['scheduled_at'] = $moved['scheduled_at'];
            }
        }
        unset($row);
        if (! empty($data['service_ids'])) {
            $booked = collect($rows)->keyBy('id');
            $rows = Service::whereIn('id', $data['service_ids'])->get()->map(fn ($service) => $booked->get($service->id)
                ?? ['id' => $service->id, 'duration_minutes' => $service->getShootDurationMinutes($shoot->propertySqft())])->all();
        }
        if ($guard->enabled() && (! empty($data['date']) || ! empty($data['service_ids']))) {
            $prepared = $guard->prepare($planner->services(\Illuminate\Support\Arr::only($data, ['travel_override', 'travel_override_confirmed', 'travel_override_confirmation_version', 'travel_override_reason']),
                $rows, $at, $shoot->photographer_id, $shoot->timezone, 'update'), $shoot, $user);
        }
        return $guard->commit($prepared, fn () => DB::transaction(function () use ($shoot, $data, $user, $scheduleChanges, $movedServices, $unitRequest) {
            $shoot = Shoot::query()->lockForUpdate()->findOrFail($shoot->id);

            if ($unitRequest) {
                app(\App\Services\Shoots\MultiUnitRescheduleService::class)->apply($shoot, $unitRequest, $user);
            }

            if ($shoot->isComplimentaryReshoot() && array_key_exists('service_ids', $data)) {
                throw ValidationException::withMessages([
                    'services' => [
                        'Robbie cannot change services on a booked complimentary reshoot. Book separate additional work or another complimentary reshoot instead.',
                    ],
                ]);
            }

            // Update scheduled date/time if provided
            if (! empty($data['date'])) {
                $shoot->fill(\Illuminate\Support\Arr::only($scheduleChanges, ['scheduled_at', 'scheduled_date', 'time']));
            }

            // Update services if provided
            if (! empty($data['service_ids'])) {
                $services = Service::whereIn('id', $data['service_ids'])->get();
                if ($services->isNotEmpty()) {
                    $baseQuote = $this->calculateBaseQuote($services);
                    $taxCalculation = $this->taxService->calculateTotal(
                        $baseQuote,
                        $shoot->tax_region ?? 'CA'
                    );

                    $billableAdjustments = app(InvoiceAdjustmentService::class)
                        ->billableItemsForShoot($shoot)
                        ->sum(fn ($item) => (float) $item->total_amount);

                    $shoot->base_quote = $taxCalculation['base_quote'];
                    $shoot->tax_amount = $taxCalculation['tax_amount'];
                    $shoot->total_quote = round(
                        (float) $taxCalculation['total_quote'] + $billableAdjustments,
                        2
                    );

                    $existingDurations = $shoot->serviceItems()->pluck('duration_minutes', 'service_id');
                    $pivotData = $services->mapWithKeys(function ($service) use ($shoot, $existingDurations) {
                        return [
                            $service->id => [
                                'price' => $service->price ?? 0,
                                'quantity' => 1,
                                'photographer_pay' => $service->photographer_pay ?? null,
                                'duration_minutes' => $existingDurations->has($service->id) ? $existingDurations->get($service->id) : $service->getShootDurationMinutes($shoot->propertySqft()),
                            ],
                        ];
                    })->toArray();

                    $shoot->services()->sync($pivotData);
                }
            }

            // Persist the same inherited moves evaluated above. Explicit split
            // appointments keep their relative offsets through the normalizer.
            foreach ($movedServices as $serviceId => $row) {
                if (array_key_exists('scheduled_at', $row)) {
                    $shoot->serviceItems()->where('service_id', $serviceId)->get()->each(function ($item) use ($row) {
                        $item->scheduled_at = $row['scheduled_at'] ? \Carbon\Carbon::parse($row['scheduled_at'])->utc() : null;
                        $item->save();
                    });
                }
            }
            if ($scheduleChanges !== []) {
                app(\App\Services\Schedule\ShootScheduleFromServices::class)->applyEarliestToShoot($shoot, $shoot->serviceItems()->get());
            }

            $shoot->updated_by = $user->name;
            $shoot->save();

            // Log activity
            $this->activityLogger->log(
                $shoot,
                'shoot_updated',
                [
                    'by' => $user->name,
                    'source' => 'ai_chat',
                    'changes' => $data,
                ],
                $user
            );

            return $shoot->fresh(['client', 'services']);
        }));
    }

    /**
     * Cancel a shoot
     */
    public function cancelShoot(Shoot $shoot, User $user): Shoot
    {
        return DB::transaction(function () use ($shoot, $user) {
            $shoot->status = 'cancelled';
            $shoot->workflow_status = 'cancelled';
            $shoot->updated_by = $user->name;
            $shoot->save();

            // Log activity
            $this->activityLogger->log(
                $shoot,
                'shoot_cancelled',
                [
                    'by' => $user->name,
                    'source' => 'ai_chat',
                ],
                $user
            );

            return $shoot->fresh();
        });
    }

    /**
     * Get availability slots for a date
     */
    public function getAvailabilityForDate(?\Carbon\Carbon $date = null, ?int $photographerId = null): array
    {
        $date = $date ?? now();
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        // Get booked shoots for the date
        $bookedShoots = Shoot::where('scheduled_at', '>=', $startOfDay)
            ->where('scheduled_at', '<=', $endOfDay)
            ->whereNotIn('status', ['cancelled'])
            ->when($photographerId, function ($query) use ($photographerId) {
                $query->where('photographer_id', $photographerId);
            })
            ->get(['scheduled_at']);

        $bookedTimes = $bookedShoots->map(function ($shoot) {
            return $shoot->scheduled_at->format('H:i');
        })->toArray();

        // Generate available slots (every 2 hours from 9 AM to 6 PM)
        $availableSlots = [];
        $current = $startOfDay->copy()->setTime(9, 0);
        $endTime = $startOfDay->copy()->setTime(18, 0);

        while ($current <= $endTime) {
            $timeStr = $current->format('H:i');
            if (! in_array($timeStr, $bookedTimes)) {
                $availableSlots[] = [
                    'time' => $timeStr,
                    'display' => $current->format('g:i A'),
                ];
            }
            $current->addHours(2);
        }

        return $availableSlots;
    }
}
