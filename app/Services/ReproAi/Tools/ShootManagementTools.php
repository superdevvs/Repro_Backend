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

            $shoot = Shoot::with(['client', 'photographer', 'services', 'payments'])->find($shootId);
            
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
                    'client' => [
                        'id' => $shoot->client_id,
                        'name' => $shoot->client->name ?? 'Unknown',
                        'email' => $shoot->client->email ?? null,
                    ],
                    'photographer' => $shoot->photographer ? [
                        'id' => $shoot->photographer_id,
                        'name' => $shoot->photographer->name,
                    ] : null,
                    'status' => $shoot->status,
                    'workflow_status' => $shoot->workflow_status,
                    'scheduled_date' => $shoot->scheduled_date?->toDateString(),
                    'time' => $shoot->time,
                    'services' => $shoot->services->map(function ($service) {
                        return [
                            'id' => $service->id,
                            'name' => $service->name,
                            'price' => $service->pivot->price ?? $service->price,
                        ];
                    })->toArray(),
                    'pricing' => [
                        'base_quote' => $shoot->base_quote,
                        'tax_amount' => $shoot->tax_amount,
                        'total_quote' => $shoot->total_quote,
                        'total_paid' => $shoot->total_paid ?? 0,
                        'amount_remaining' => max(0, $shoot->total_quote - ($shoot->total_paid ?? 0)),
                    ],
                    'notes' => $shoot->notes ?? $shoot->shoot_notes ?? null,
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

            $shoot = Shoot::find($shootId);
            
            if (!$shoot) {
                return [
                    'success' => false,
                    'error' => 'Shoot not found',
                ];
            }

            $updates = [];
            if ($newDate) {
                try {
                    $parsedDate = Carbon::parse($newDate);
                    $updates['scheduled_date'] = $parsedDate->toDateString();
                } catch (\Exception $e) {
                    return [
                        'success' => false,
                        'error' => 'Invalid date format. Please use YYYY-MM-DD format.',
                    ];
                }
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

            $shoot = Shoot::find($shootId);
            
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
            $userId = $params['user_id'] ?? $context['user_id'] ?? auth()->id();
            $status = $params['status'] ?? null;
            $limit = min($params['limit'] ?? 10, 50);
            
            if (!$userId) {
                return [
                    'success' => false,
                    'error' => 'User ID is required',
                ];
            }

            $user = User::find($userId);
            if (!$user) {
                return [
                    'success' => false,
                    'error' => 'User not found',
                ];
            }

            $query = Shoot::query();
            
            if ($user->role === 'client') {
                $query->where('client_id', $user->id);
            } elseif ($user->role === 'photographer') {
                $query->where('photographer_id', $user->id);
            }

            if ($status) {
                $query->where('status', $status);
            }

            $shoots = $query->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($shoot) {
                    return [
                        'id' => $shoot->id,
                        'address' => "{$shoot->address}, {$shoot->city}, {$shoot->state}",
                        'status' => $shoot->status,
                        'workflow_status' => $shoot->workflow_status,
                        'scheduled_date' => $shoot->scheduled_date?->toDateString(),
                        'time' => $shoot->time,
                        'total_quote' => $shoot->total_quote,
                        'total_paid' => $shoot->total_paid ?? 0,
                    ];
                })
                ->toArray();

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


