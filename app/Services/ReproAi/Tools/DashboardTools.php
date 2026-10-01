<?php

namespace App\Services\ReproAi\Tools;

use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DashboardTools
{
    public function __construct(private readonly RobbieRecordAccess $access) {}

    /**
     * Get dashboard statistics
     *
     * @param  array  $params  Parameters from AI tool call
     * @param  array  $context  Additional context
     * @return array Dashboard stats
     */
    public function getDashboardStats(array $params, array $context = []): array
    {
        try {
            $user = $this->access->actor($context);
            $timeRange = $params['time_range'] ?? 'all'; // 'today', 'week', 'month', 'year', 'all'

            if (! $user) {
                return [
                    'success' => false,
                    'error' => 'A verified account is required',
                ];
            }

            // Determine date range
            $dateRange = $this->getDateRange($timeRange);

            // Get shoots based on user role
            $shootsQuery = $this->getShootsQuery($user, $dateRange);
            $shoots = $shootsQuery->get();
            $shoots = $shoots->filter(fn (Shoot $shoot) => $this->access->canRead($shoot, $user));
            $billingShoots = $shoots->filter(fn (Shoot $shoot) => $this->access->canReadBilling($shoot, $user));

            // Calculate statistics
            $stats = [
                'total_shoots' => $shoots->count(),
                'scheduled_shoots' => $shoots->where('status', 'scheduled')->count(),
                'completed_shoots' => $shoots->where('status', 'completed')->count(),
                'pending_shoots' => $shoots->where('status', 'pending')->count(),
                'total_revenue' => $billingShoots->sum('total_paid') ?? 0,
                'total_quoted' => $billingShoots->sum('total_quote') ?? 0,
                'pending_payments' => $billingShoots->sum(function ($shoot) {
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
                ->map(function ($shoot) use ($user) {
                    return [
                        'id' => $shoot->id,
                        'address' => "{$shoot->address}, {$shoot->city}, {$shoot->state}",
                        'status' => $shoot->status,
                        'workflow_status' => $shoot->workflow_status,
                        'scheduled_date' => $shoot->scheduled_date?->toDateString(),
                        'total_quote' => $this->access->canReadBilling($shoot, $user) ? $shoot->total_quote : null,
                        'total_paid' => $this->access->canReadBilling($shoot, $user) ? $shoot->total_paid : null,
                    ];
                })
                ->values()
                ->toArray();

            // Get shoots needing attention
            $needsAttention = $shoots->filter(function ($shoot) use ($user) {
                return $shoot->is_flagged ||
                       $shoot->workflow_status === Shoot::STATUS_ON_HOLD ||
                       ($this->access->canReadBilling($shoot, $user) && $shoot->total_quote > 0 && ($shoot->total_paid ?? 0) < $shoot->total_quote);
            })
                ->take(5)
                ->map(function ($shoot) use ($user) {
                    $issues = [];
                    if ($shoot->is_flagged) {
                        $issues[] = 'Shoot flagged for review';
                    }
                    if ($shoot->workflow_status === Shoot::STATUS_ON_HOLD) {
                        $issues[] = 'Shoot on hold';
                    }
                    if ($this->access->canReadBilling($shoot, $user) && ($shoot->total_paid ?? 0) < $shoot->total_quote) {
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
     * @param  array  $params  Parameters from AI tool call
     * @param  array  $context  Additional context
     * @return array Update result
     */
    public function updateShootStatus(array $params, array $context = []): array
    {
        try {
            $shootId = $params['shoot_id'] ?? null;
            $status = $params['status'] ?? null;
            $workflowStatus = $params['workflow_status'] ?? null;

            if (! $shootId) {
                return [
                    'success' => false,
                    'error' => 'Shoot ID is required',
                ];
            }

            $actor = $this->access->actor($context);
            // General status changes are staff operations, not a privilege
            // conferred by owning or sharing read access to a shoot.
            if (! $this->access->isStaff($actor)) {
                return ['success' => false, 'error' => 'You do not have permission to change shoot status.'];
            }
            $shoot = $this->access->find($shootId, $actor);

            if (! $shoot) {
                return [
                    'success' => false,
                    'error' => 'Shoot not found',
                ];
            }

            $updates = [];
            if ($status && in_array($status, ['pending', 'scheduled', 'completed', 'cancelled', 'on_hold'])) {
                $updates['status'] = $status;
            }
            if ($workflowStatus && in_array($workflowStatus, ['scheduled', 'completed', 'uploaded', 'editing', 'delivered', 'on_hold', 'cancelled'], true)) {
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
        $query = $this->access->query($user);

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
