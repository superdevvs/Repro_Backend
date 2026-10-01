<?php

namespace App\Services\ReproAi\Tools;

use App\Models\Shoot;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ShootManagementTools
{
    public function __construct(private readonly RobbieRecordAccess $access) {}

    /**
     * Get shoot details
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Shoot details
     */
    public function getShootDetails(array $params, array $context = []): array
    {
        try {
            $shootId = $params['shoot_id'] ?? null;
            
            if (!$shootId) {
                return [
                    'success' => false,
                    'error' => 'Shoot ID is required',
                ];
            }

            $actor = $this->access->actor($context);
            $shoot = $this->access->find($shootId, $actor, ['client', 'photographer', 'services', 'payments']);
            
            if (!$shoot) {
                return [
                    'success' => false,
                    'error' => 'Shoot not found',
                ];
            }

            return [
                'success' => true,
                'shoot' => [
                    'id' => $shoot->id,
                    'address' => "{$shoot->address}, {$shoot->city}, {$shoot->state} {$shoot->zip}",
                    'full_address' => [
                        'street' => $shoot->address,
                        'city' => $shoot->city,
                        'state' => $shoot->state,
                        'zip' => $shoot->zip,
                    ],
                    'client' => $this->access->canReadBilling($shoot, $actor) ? [
                        'id' => $shoot->client_id,
                        'name' => $shoot->client->name ?? 'Unknown',
                        'email' => $shoot->client->email ?? null,
                    ] : null,
                    'photographer' => $shoot->photographer ? [
                        'id' => $shoot->photographer_id,
                        'name' => $shoot->photographer->name,
                    ] : null,
                    'status' => $shoot->status,
                    'workflow_status' => $shoot->workflow_status,
                    'scheduled_date' => $shoot->scheduled_date?->toDateString(),
                    'time' => $shoot->time,
                    'services' => $shoot->services->map(function ($service) use ($shoot, $actor) {
                        return [
                            'id' => $service->id,
                            'name' => $service->name,
                            'price' => $this->access->canReadBilling($shoot, $actor) ? ($service->pivot->price ?? $service->price) : null,
                        ];
                    })->toArray(),
                    'pricing' => $this->access->canReadBilling($shoot, $actor) ? [
                        'base_quote' => $shoot->base_quote,
                        'tax_amount' => $shoot->tax_amount,
                        'total_quote' => $shoot->total_quote,
                        'total_paid' => $shoot->total_paid ?? 0,
                        'amount_remaining' => max(0, $shoot->total_quote - ($shoot->total_paid ?? 0)),
                    ] : null,
                    'notes' => $this->access->description($shoot, $actor),
                    'created_at' => $shoot->created_at->toIso8601String(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('ShootManagementTools::getShootDetails error', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Reschedule a shoot
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Reschedule result
     */
    public function rescheduleShoot(array $params, array $context = []): array
    {
        try {
            $shootId = $params['shoot_id'] ?? null;
            $newDate = $params['new_date'] ?? null;
            $newTime = $params['new_time'] ?? null;
            
            if (!$shootId) {
                return [
                    'success' => false,
                    'error' => 'Shoot ID is required',
                ];
            }

            if (!$newDate && !$newTime) {
                return [
                    'success' => false,
                    'error' => 'New date or time is required',
                ];
            }

            $actor = $this->access->actor($context);
            $shoot = $this->access->find($shootId, $actor);
            if ($shoot && ! $this->access->canChangeBooking($shoot, $actor)) {
                return ['success' => false, 'error' => 'You do not have permission to change this shoot.'];
            }
            
            if (!$shoot) {
                return [
                    'success' => false,
                    'error' => 'Shoot not found',
                ];
            }

            $updates = [];
            if ($newDate) {
                // Accept whatever reached the tool: the model should send ISO, but
                // a passed-through "June 5" or "6-6" must not fail the reschedule.
                $interpreted = \App\Services\ReproAi\DateInterpreter::interpret($newDate);

                if ($interpreted->date === null) {
                    return [
                        'success' => false,
                        'error' => "I could not read \"{$newDate}\" as a date. Try a day and month, for example \"June 5\" or \"6/5\".",
                    ];
                }

                if ($interpreted->ambiguous) {
                    return [
                        'success' => false,
                        'error' => sprintf(
                            'That date could be read more than one way. Did you mean %s? Please confirm before I reschedule.',
                            $interpreted->describe()
                        ),
                        'needs_confirmation' => true,
                        'interpreted_date' => $interpreted->date,
                    ];
                }

                $updates['scheduled_date'] = $interpreted->date;
            }
            if ($newTime) {
                $updates['time'] = $newTime;
            }

            $shoot->update($updates);

            return [
                'success' => true,
                'message' => 'Shoot rescheduled successfully',
                'shoot_id' => $shoot->id,
                'new_scheduled_date' => $shoot->scheduled_date?->toDateString(),
                'new_time' => $shoot->time,
            ];
        } catch (\Exception $e) {
            Log::error('ShootManagementTools::rescheduleShoot error', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Cancel a shoot
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Cancel result
     */
    public function cancelShoot(array $params, array $context = []): array
    {
        try {
            $shootId = $params['shoot_id'] ?? null;
            $reason = $params['reason'] ?? null;
            
            if (!$shootId) {
                return [
                    'success' => false,
                    'error' => 'Shoot ID is required',
                ];
            }

            $actor = $this->access->actor($context);
            $shoot = $this->access->find($shootId, $actor);
            if ($shoot && ! $this->access->canChangeBooking($shoot, $actor)) {
                return ['success' => false, 'error' => 'You do not have permission to change this shoot.'];
            }
            
            if (!$shoot) {
                return [
                    'success' => false,
                    'error' => 'Shoot not found',
                ];
            }

            $shoot->update([
                'status' => 'cancelled',
                'notes' => ($shoot->notes ?? '') . ($reason ? "\n\nCancelled: {$reason}" : ''),
            ]);

            return [
                'success' => true,
                'message' => 'Shoot cancelled successfully',
                'shoot_id' => $shoot->id,
            ];
        } catch (\Exception $e) {
            Log::error('ShootManagementTools::cancelShoot error', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * List shoots with filters
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array List of shoots
     */
    public function listShoots(array $params, array $context = []): array
    {
        try {
            $actor = $this->access->actor($context);
            if (! $actor) return ['success' => false, 'error' => 'An authorized account is required.'];
            $userId = $params['user_id'] ?? $actor->id;
            if ((string) $userId !== (string) $actor->id && ! $this->access->isStaff($actor)) {
                return ['success' => false, 'error' => 'You do not have access to these shoots.'];
            }
            $status = $params['status'] ?? null;
            $limit = max(1, min((int) ($params['limit'] ?? 10), 50));
            $query = $this->access->query($actor);
            if ((string) $userId !== (string) $actor->id) {
                $target = User::find($userId);
                if (! $target) return ['success' => false, 'error' => 'Account not found.'];
                app(\App\Services\Shoots\ShootAuthorizationSupport::class)->scopeAccessibleShootMedia($query, $target);
            }

            if ($status) {
                $query->where('status', $status);
            }

            $shoots = $query->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->filter(fn (Shoot $shoot) => $this->access->canRead($shoot, $actor))
                ->map(function ($shoot) use ($actor) {
                    return [
                        'id' => $shoot->id,
                        'address' => "{$shoot->address}, {$shoot->city}, {$shoot->state}",
                        'status' => $shoot->status,
                        'workflow_status' => $shoot->workflow_status,
                        'scheduled_date' => $shoot->scheduled_date?->toDateString(),
                        'time' => $shoot->time,
                        'total_quote' => $this->access->canReadBilling($shoot, $actor) ? $shoot->total_quote : null,
                        'total_paid' => $this->access->canReadBilling($shoot, $actor) ? ($shoot->total_paid ?? 0) : null,
                    ];
                })
                ->values()->toArray();

            return [
                'success' => true,
                'shoots' => $shoots,
                'count' => count($shoots),
            ];
        } catch (\Exception $e) {
            Log::error('ShootManagementTools::listShoots error', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}


