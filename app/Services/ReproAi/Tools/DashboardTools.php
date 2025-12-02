<?php

namespace App\Services\ReproAi\Tools;

use App\Models\Shoot;
use App\Models\User;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardTools
{
    /**
     * Get dashboard statistics
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Dashboard stats
     */
    public function getDashboardStats(array $params, array $context = []): array
    {
        try {
            $userId = $params['user_id'] ?? $context['user_id'] ?? auth()->id();
            $timeRange = $params['time_range'] ?? 'all'; // 'today', 'week', 'month', 'year', 'all'
            
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

            // Determine date range
            $dateRange = $this->getDateRange($timeRange);
            
            // Get shoots based on user role
            $shootsQuery = $this->getShootsQuery($user, $dateRange);
            $shoots = $shootsQuery->get();

            // Calculate statistics
            $stats = [
                'total_shoots' => $shoots->count(),
                'scheduled_shoots' => $shoots->where('status', 'scheduled')->count(),
                'completed_shoots' => $shoots->where('status', 'completed')->count(),
                'pending_shoots' => $shoots->where('status', 'pending')->count(),
                'total_revenue' => $shoots->sum('total_paid') ?? 0,
                'total_quoted' => $shoots->sum('total_quote') ?? 0,
                'pending_payments' => $shoots->sum(function ($shoot) {
                    return max(0, ($shoot->total_quote ?? 0) - ($shoot->total_paid ?? 0));
                }),
                'scheduled_today' => $shoots->filter(function ($shoot) {
                    return $shoot->scheduled_date && 
                           $shoot->scheduled_date->isToday() &&
                           $shoot->status === 'scheduled';
                })->count(),
                'upcoming_this_week' => $shoots->filter(function ($shoot) {
                    return $shoot->scheduled_date && 
                           $shoot->scheduled_date->isFuture() &&
                           $shoot->scheduled_date->isBefore(Carbon::now()->addWeek()) &&
                           $shoot->status === 'scheduled';
                })->count(),
            ];

            // Get recent shoots
            $recentShoots = $shoots->sortByDesc('created_at')
                ->take(5)
                ->map(function ($shoot) {
                    return [
                        'id' => $shoot->id,
                        'address' => "{$shoot->address}, {$shoot->city}, {$shoot->state}",
                        'status' => $shoot->status,
                        'workflow_status' => $shoot->workflow_status,
                        'scheduled_date' => $shoot->scheduled_date?->toDateString(),
                        'total_quote' => $shoot->total_quote,
                        'total_paid' => $shoot->total_paid,
                    ];
                })
                ->values()
                ->toArray();

            // Get shoots needing attention
            $needsAttention = $shoots->filter(function ($shoot) {
                return $shoot->workflow_status === Shoot::WORKFLOW_RAW_ISSUE ||
                       $shoot->workflow_status === Shoot::WORKFLOW_EDITING_ISSUE ||
                       ($shoot->total_quote > 0 && ($shoot->total_paid ?? 0) < $shoot->total_quote);
            })
            ->take(5)
            ->map(function ($shoot) {
                $issues = [];
                if ($shoot->workflow_status === Shoot::WORKFLOW_RAW_ISSUE) {
                    $issues[] = 'Raw upload issue';
                }
                if ($shoot->workflow_status === Shoot::WORKFLOW_EDITING_ISSUE) {
                    $issues[] = 'Editing issue';
                }
                if (($shoot->total_paid ?? 0) < $shoot->total_quote) {
                    $issues[] = 'Payment pending';
                }
                
                return [
                    'id' => $shoot->id,
                    'address' => "{$shoot->address}, {$shoot->city}",
                    'issues' => $issues,
                ];
            })
            ->values()
            ->toArray();

            return [
                'success' => true,
                'time_range' => $timeRange,
                'stats' => $stats,
                'recent_shoots' => $recentShoots,
                'needs_attention' => $needsAttention,
            ];
        } catch (\Exception $e) {
            Log::error('DashboardTools::getDashboardStats error', [
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
     * Update shoot status
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Update result
     */
    public function updateShootStatus(array $params, array $context = []): array
    {
        try {
            $shootId = $params['shoot_id'] ?? null;
            $status = $params['status'] ?? null;
            $workflowStatus = $params['workflow_status'] ?? null;
            
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

            $updates = [];
            if ($status && in_array($status, ['pending', 'scheduled', 'completed', 'cancelled', 'on_hold'])) {
                $updates['status'] = $status;
            }
            if ($workflowStatus) {
                $updates['workflow_status'] = $workflowStatus;
            }

            if (empty($updates)) {
                return [
                    'success' => false,
                    'error' => 'No valid status updates provided',
                ];
            }

            $shoot->update($updates);

            return [
                'success' => true,
                'message' => 'Shoot status updated successfully',
                'shoot_id' => $shoot->id,
                'updated_fields' => array_keys($updates),
                'current_status' => $shoot->status,
                'current_workflow_status' => $shoot->workflow_status,
            ];
        } catch (\Exception $e) {
            Log::error('DashboardTools::updateShootStatus error', [
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
     * Get shoots for a user based on their role
     */
    private function getShootsQuery(User $user, ?array $dateRange = null)
    {
        $query = Shoot::query();
        
        if ($user->role === 'client') {
            $query->where('client_id', $user->id);
        } elseif ($user->role === 'photographer') {
            $query->where('photographer_id', $user->id);
        } elseif ($user->role === 'admin') {
            // Admins see all shoots
        } else {
            // Default: show user's shoots
            $query->where('client_id', $user->id);
        }

        if ($dateRange) {
            $query->whereBetween('created_at', [$dateRange['start'], $dateRange['end']]);
        }

        return $query;
    }

    /**
     * Get date range based on time range parameter
     */
    private function getDateRange(string $timeRange): ?array
    {
        return match ($timeRange) {
            'today' => [
                'start' => Carbon::today()->startOfDay(),
                'end' => Carbon::today()->endOfDay(),
            ],
            'week' => [
                'start' => Carbon::now()->startOfWeek(),
                'end' => Carbon::now()->endOfWeek(),
            ],
            'month' => [
                'start' => Carbon::now()->startOfMonth(),
                'end' => Carbon::now()->endOfMonth(),
            ],
            'year' => [
                'start' => Carbon::now()->startOfYear(),
                'end' => Carbon::now()->endOfYear(),
            ],
            default => null, // 'all' or unknown
        };
    }
}


