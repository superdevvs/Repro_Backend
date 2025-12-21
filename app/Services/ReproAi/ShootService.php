<?php

namespace App\Services\ReproAi;

use App\Models\Shoot;
use App\Models\Service;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\ShootWorkflowService;
use App\Services\ShootTaxService;
use App\Services\ShootActivityLogger;
use Illuminate\Support\Facades\DB;

class ShootService
{
    private DropboxWorkflowService $dropboxService;
    private ShootWorkflowService $workflowService;
    private ShootTaxService $taxService;
    private ShootActivityLogger $activityLogger;

    public function __construct()
    {
        $this->dropboxService = app(DropboxWorkflowService::class);
        $this->workflowService = app(ShootWorkflowService::class);
        $this->taxService = app(ShootTaxService::class);
        $this->activityLogger = app(ShootActivityLogger::class);
    }

    /**
     * Create a shoot from Robbie flow data
     */
    public function createFromReproAi(int $userId, array $data): Shoot
    {
        return DB::transaction(function () use ($userId, $data) {
            $user = User::findOrFail($userId);

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
            if (!empty($data['date'])) {
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
                'client_id' => $userId, // Assuming user is the client for now
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

            // Create Dropbox folders if scheduled
            if ($scheduledAt) {
                $this->dropboxService->createShootFolders($shoot);
            }

            return $shoot->load(['client', 'services']);
        });
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
        return DB::transaction(function () use ($shoot, $data, $user) {
            // Update scheduled date/time if provided
            if (!empty($data['date'])) {
                $date = \Carbon\Carbon::parse($data['date']);
                $timeWindow = $data['time_window'] ?? $shoot->time ?? '12:00';
                $time = $this->parseTimeWindow($timeWindow);
                
                if ($time) {
                    $scheduledAt = $date->copy()->setTimeFromTimeString($time);
                } else {
                    $scheduledAt = $date->copy()->setTime(12, 0);
                }

                $shoot->scheduled_at = $scheduledAt;
                $shoot->scheduled_date = $scheduledAt->format('Y-m-d');
                $shoot->time = $scheduledAt->format('H:i');
            }

            // Update services if provided
            if (!empty($data['service_ids'])) {
                $services = Service::whereIn('id', $data['service_ids'])->get();
                if ($services->isNotEmpty()) {
                    $baseQuote = $this->calculateBaseQuote($services);
                    $taxCalculation = $this->taxService->calculateTotal(
                        $baseQuote,
                        $shoot->tax_region ?? 'CA'
                    );

                    $shoot->base_quote = $taxCalculation['base_quote'];
                    $shoot->tax_amount = $taxCalculation['tax_amount'];
                    $shoot->total_quote = $taxCalculation['total_quote'];

                    $pivotData = $services->mapWithKeys(function ($service) {
                        return [
                            $service->id => [
                                'price' => $service->price ?? 0,
                                'quantity' => 1,
                                'photographer_pay' => $service->photographer_pay ?? null,
                            ],
                        ];
                    })->toArray();

                    $shoot->services()->sync($pivotData);
                }
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
        });
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
            if (!in_array($timeStr, $bookedTimes)) {
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

