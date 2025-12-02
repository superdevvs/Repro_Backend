<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PhotographerAvailability;
use App\Models\Shoot;
use App\Models\User;
use App\Models\WorkflowLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Return an aggregated snapshot for the admin / superadmin dashboard.
     */
    public function overview(Request $request)
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, ['admin', 'super_admin', 'superadmin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $today = now()->startOfDay();

        $upcomingShoots = $this->formatShoots(
            Shoot::with([
                'client:id,name,company_name',
                'photographer:id,name,avatar',
                'service:id,name',
            ])
                ->whereDate('scheduled_date', '>=', $today->toDateString())
                ->orderBy('scheduled_date')
                ->orderBy('time')
                ->limit(30)
                ->get(),
            $today
        );

        $photographers = $this->buildPhotographerSummaries($today);

        $pendingReviews = $this->formatShoots(
            Shoot::with([
                'client:id,name,company_name',
                'photographer:id,name,avatar',
                'service:id,name',
            ])
                ->whereIn('workflow_status', [
                    Shoot::WORKFLOW_PENDING_REVIEW,
                    Shoot::WORKFLOW_EDITING_UPLOADED,
                ])
                ->orderByDesc('submitted_for_review_at')
                ->limit(12)
                ->get(),
            $today
        );

        $activity = WorkflowLog::with(['user:id,name'])
            ->latest()
            ->limit(15)
            ->get()
            ->map(function (WorkflowLog $log) {
                return [
                    'id' => $log->id,
                    'message' => $log->details ?? $log->action,
                    'action' => $log->action,
                    'type' => $this->inferActivityType($log->action),
                    'timestamp' => optional($log->created_at)->toDateTimeString(),
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                    ] : null,
                ];
            })
            ->values();

        $issues = $this->buildIssueFeed($today);

        $workflow = $this->buildWorkflowColumns($today);

        $stats = [
            'total_shoots' => Shoot::count(),
            'scheduled_today' => Shoot::whereDate('scheduled_date', $today->toDateString())->count(),
            'flagged_shoots' => Shoot::where('is_flagged', true)->count(),
            'pending_reviews' => Shoot::where('workflow_status', Shoot::WORKFLOW_PENDING_REVIEW)->count(),
        ];

        return response()->json([
            'data' => [
                'stats' => $stats,
                'upcoming_shoots' => $upcomingShoots,
                'photographers' => $photographers,
                'pending_reviews' => $pendingReviews,
                'activity_log' => $activity,
                'issues' => $issues,
                'workflow' => $workflow,
            ],
        ]);
    }

    /**
     * Normalize shoot records for the dashboard cards.
     */
    protected function formatShoots(Collection $shoots, Carbon $today): Collection
    {
        return $shoots->map(function (Shoot $shoot) use ($today) {
            $date = $shoot->scheduled_date ? Carbon::parse($shoot->scheduled_date) : null;
            $dateTime = $this->combineDateAndTime($shoot->scheduled_date, $shoot->time);

            return [
                'id' => $shoot->id,
                'day_label' => $this->getDayLabel($date, $today),
                'time_label' => $dateTime ? $dateTime->format('h:i A') : null,
                'start_time' => $dateTime ? $dateTime->toIso8601String() : null,
                'address_line' => $shoot->address,
                'city_state_zip' => $this->formatLocationLine($shoot),
                'status' => $shoot->status,
                'workflow_status' => $shoot->workflow_status,
                'client_name' => optional($shoot->client)->name,
                'temperature' => null,
                'services' => $this->buildServiceTags($shoot),
                'photographer' => $shoot->photographer ? [
                    'id' => $shoot->photographer->id,
                    'name' => $shoot->photographer->name,
                    'avatar' => $shoot->photographer->avatar,
                ] : null,
                'is_flagged' => (bool) $shoot->is_flagged,
                'delivery_deadline' => optional($shoot->editing_completed_at)->toIso8601String(),
                'submitted_for_review_at' => optional($shoot->submitted_for_review_at)->toIso8601String(),
                'admin_issue_notes' => $shoot->admin_issue_notes,
            ];
        })->values();
    }

    /**
     * Build quick stats for each photographer (load, availability, next slot).
     */
    protected function buildPhotographerSummaries(Carbon $today): array
    {
        $photographers = User::where('role', 'photographer')
            ->select('id', 'name', 'company_name', 'phonenumber', 'avatar', 'email')
            ->orderBy('name')
            ->get();

        if ($photographers->isEmpty()) {
            return [];
        }

        $ids = $photographers->pluck('id');

        $todayCounts = Shoot::select('photographer_id', DB::raw('count(*) as total'))
            ->whereIn('photographer_id', $ids)
            ->whereDate('scheduled_date', $today->toDateString())
            ->groupBy('photographer_id')
            ->pluck('total', 'photographer_id');

        $nextShoots = Shoot::select('id', 'photographer_id', 'scheduled_date', 'time')
            ->whereIn('photographer_id', $ids)
            ->whereDate('scheduled_date', '>=', $today->toDateString())
            ->orderBy('scheduled_date')
            ->orderBy('time')
            ->get()
            ->groupBy('photographer_id')
            ->map->first();

        $availability = PhotographerAvailability::select('photographer_id', 'date', 'start_time')
            ->whereIn('photographer_id', $ids)
            ->whereDate('date', '>=', $today->toDateString())
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->groupBy('photographer_id')
            ->map->first();

        return $photographers->map(function (User $photographer) use ($todayCounts, $nextShoots, $availability) {
            $loadToday = (int) ($todayCounts[$photographer->id] ?? 0);
            $nextShoot = $nextShoots[$photographer->id] ?? null;
            $availabilitySlot = $availability[$photographer->id] ?? null;

            $nextTime = $nextShoot
                ? $this->combineDateAndTime($nextShoot->scheduled_date, $nextShoot->time)
                : ($availabilitySlot
                    ? $this->combineDateAndTime($availabilitySlot->date, $availabilitySlot->start_time)
                    : null);

            return [
                'id' => $photographer->id,
                'name' => $photographer->name,
                'region' => $photographer->company_name ?: 'Unassigned region',
                'load_today' => $loadToday,
                'available_from' => $nextTime ? $nextTime->format('H:i') : null,
                'next_slot' => $nextTime ? $nextTime->format('H:i') : null,
                'avatar' => $photographer->avatar,
                'status' => $this->inferPhotographerStatus($loadToday, (bool) $nextShoot, (bool) $availabilitySlot),
                'next_shoot_distance' => null,
                'email' => $photographer->email,
                'phone' => $photographer->phonenumber,
            ];
        })->values()->all();
    }

    protected function buildIssueFeed(Carbon $today): Collection
    {
        return Shoot::with(['client:id,name'])
            ->where(function ($query) use ($today) {
                $query->where('is_flagged', true)
                    ->orWhere(function ($nested) use ($today) {
                        $nested->whereNotNull('scheduled_date')
                            ->whereDate('scheduled_date', '<', $today->toDateString())
                            ->whereNull('admin_verified_at');
                    });
            })
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->map(function (Shoot $shoot) {
                return [
                    'id' => $shoot->id,
                    'message' => $shoot->admin_issue_notes ?: "Delivery risk • {$shoot->address}",
                    'severity' => $shoot->is_flagged ? 'high' : 'medium',
                    'status' => $shoot->status,
                    'client' => optional($shoot->client)->name,
                    'updated_at' => optional($shoot->updated_at)->toDateTimeString(),
                ];
            })
            ->values();
    }

    protected function buildWorkflowColumns(Carbon $today): array
    {
        $config = [
            [
                'key' => 'booked',
                'label' => 'Booked',
                'statuses' => [Shoot::WORKFLOW_BOOKED],
                'accent' => '#3b82f6',
            ],
            [
                'key' => 'raw_upload',
                'label' => 'Raw Upload',
                'statuses' => [
                    Shoot::WORKFLOW_RAW_UPLOAD_PENDING,
                    Shoot::WORKFLOW_RAW_UPLOADED,
                    Shoot::WORKFLOW_RAW_ISSUE,
                ],
                'accent' => '#0ea5e9',
            ],
            [
                'key' => 'editing',
                'label' => 'Editing',
                'statuses' => [
                    Shoot::WORKFLOW_EDITING,
                    Shoot::WORKFLOW_EDITING_UPLOADED,
                    Shoot::WORKFLOW_EDITING_ISSUE,
                ],
                'accent' => '#a855f7',
            ],
            [
                'key' => 'pending_review',
                'label' => 'Pending Review',
                'statuses' => [Shoot::WORKFLOW_PENDING_REVIEW],
                'accent' => '#f97316',
            ],
            [
                'key' => 'ready',
                'label' => 'Ready / Delivered',
                'statuses' => [Shoot::WORKFLOW_ADMIN_VERIFIED, Shoot::WORKFLOW_COMPLETED],
                'accent' => '#22c55e',
            ],
        ];

        $columns = collect($config)->map(function (array $column) use ($today) {
            $shoots = $this->formatShoots(
                Shoot::with([
                    'client:id,name,company_name',
                    'photographer:id,name,avatar',
                    'service:id,name',
                ])
                    ->whereIn('workflow_status', $column['statuses'])
                    ->orderByDesc('updated_at')
                    ->limit(15)
                    ->get(),
                $today
            );

            return [
                'key' => $column['key'],
                'label' => $column['label'],
                'accent' => $column['accent'],
                'count' => $shoots->count(),
                'shoots' => $shoots,
            ];
        });

        return [
            'columns' => $columns->values(),
        ];
    }

    protected function buildServiceTags(Shoot $shoot): array
    {
        $tags = [];

        if ($shoot->service && $shoot->service->name) {
            $tags[] = [
                'label' => $shoot->service->name,
                'type' => 'primary',
            ];
        }

        if ($shoot->service_category) {
            $tags[] = [
                'label' => $shoot->service_category,
                'type' => 'secondary',
            ];
        }

        return $tags;
    }

    protected function formatLocationLine(Shoot $shoot): string
    {
        $pieces = array_filter([
            $shoot->city,
            $shoot->state,
            $shoot->zip,
        ]);

        return trim($shoot->address . ', ' . implode(' ', $pieces));
    }

    protected function getDayLabel(?Carbon $date, Carbon $today): string
    {
        if (!$date) {
            return 'Unscheduled';
        }

        if ($date->isSameDay($today)) {
            return 'Today';
        }

        if ($date->isSameDay($today->copy()->addDay())) {
            return 'Tomorrow';
        }

        if ($date->isSameWeek($today)) {
            return $date->format('l');
        }

        return $date->format('M j');
    }

    protected function combineDateAndTime($date, ?string $time): ?Carbon
    {
        if (!$date) {
            return null;
        }

        $datePart = $date instanceof Carbon ? $date : Carbon::parse($date);
        $timeString = $time ?: '09:00';

        return Carbon::parse($datePart->format('Y-m-d') . ' ' . $timeString);
    }

    protected function inferPhotographerStatus(int $loadToday, bool $hasUpcomingShoot, bool $hasAvailability): string
    {
        if (!$hasUpcomingShoot && !$hasAvailability) {
            return 'offline';
        }

        if ($loadToday >= 4) {
            return 'busy';
        }

        if ($loadToday >= 1) {
            return 'editing';
        }

        return 'free';
    }

    protected function inferActivityType(string $action): string
    {
        return match (true) {
            str_contains($action, 'upload') => 'upload',
            str_contains($action, 'qc') => 'qc',
            str_contains($action, 'assign') => 'assignment',
            str_contains($action, 'review') => 'review',
            str_contains($action, 'issue') => 'alert',
            default => 'info',
        };
    }
}

