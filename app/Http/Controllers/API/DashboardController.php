<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\PhotographerAvailability;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\ShootFile;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\WorkflowLog;
use App\Services\Shoots\ShootEditingAssignmentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    /**
     * Return an aggregated snapshot for the admin / superadmin dashboard.
     */
    public function overview(Request $request)
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, ['admin', 'superadmin', 'editing_manager'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Cache key includes user role to ensure proper access control
        $cacheKey = 'dashboard_overview_' . $user->role . '_' . $user->id;
        
        $data = Cache::remember($cacheKey, now()->addSeconds(60), function () use ($user) {
            $today = now()->startOfDay();

        // Optimize: Use select to only load necessary columns
        $upcomingShoots = $this->formatShoots(
            Shoot::select('id', 'client_id', 'photographer_id', 'service_id', 'service_category', 'address', 'city', 'state', 'zip', 
                         'scheduled_date', 'time', 'status', 'workflow_status', 'is_flagged', 'admin_issue_notes',
                         'editing_completed_at', 'submitted_for_review_at', 'shoot_notes', 'company_notes',
                         'photographer_notes', 'editor_notes', 'property_details', 'created_by', 'hero_image')
                ->with([
                    'client:id,name,company_name,phonenumber',
                    'photographer:id,name,avatar',
                    'service:id,name,icon,category_id',
                    'service.category:id,name,icon',
                ])
                ->whereDate('scheduled_date', '>=', $today->toDateString())
                ->orderBy('scheduled_date')
                ->orderBy('time')
                ->limit(30)
                ->get(),
            $today
        );

        $photographers = $this->buildPhotographerSummaries($today);

        // Pending reviews removed - avoid a no-op query
        $pendingReviews = collect([]);

        // Merge activities from both WorkflowLog and ShootActivityLog
        $workflowActivities = WorkflowLog::with(['user:id,name'])
            ->latest()
            ->limit(15)
            ->get()
            ->map(function (WorkflowLog $log) {
                return [
                    'id' => 'wf-' . $log->id,
                    'message' => $log->details ?? $log->action,
                    'action' => $log->action,
                    'type' => $this->inferActivityType($log->action),
                    'timestamp' => optional($log->created_at)->toDateTimeString(),
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                    ] : null,
                    'shootId' => $log->shoot_id,
                ];
            });

        $shootActivities = ShootActivityLog::with(['user:id,name', 'shoot:id,address'])
            ->latest()
            ->limit(15)
            ->get()
            ->map(function (ShootActivityLog $log) {
                return [
                    'id' => 'sa-' . $log->id,
                    'message' => $log->description ?? $log->action,
                    'action' => $log->action,
                    'type' => $this->inferActivityType($log->action),
                    'timestamp' => optional($log->created_at)->toDateTimeString(),
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                    ] : null,
                    'shootId' => $log->shoot_id,
                    'address' => $log->shoot?->address,
                ];
            });

        $activity = $workflowActivities->concat($shootActivities)
            ->sortByDesc('timestamp')
            ->take(20)
            ->values();

        $issues = $this->buildIssueFeed($today);

        $workflow = $this->buildWorkflowColumns($today);

        $stats = [
            'total_shoots' => Shoot::count(),
            'scheduled_today' => Shoot::whereDate('scheduled_date', $today->toDateString())->count(),
            'flagged_shoots' => Shoot::where('is_flagged', true)->count(),
        ];

        // Pending cancellation requests
        $pendingCancellations = $this->formatShoots(
            Shoot::select('id', 'client_id', 'photographer_id', 'service_id', 'service_category', 'address', 'city', 'state', 'zip', 
                         'scheduled_date', 'time', 'status', 'workflow_status', 'is_flagged', 'admin_issue_notes',
                         'cancellation_requested_at', 'cancellation_requested_by', 'cancellation_reason',
                         'shoot_notes', 'company_notes', 'photographer_notes', 'editor_notes', 'property_details', 'created_by', 'hero_image')
                ->with([
                    'client:id,name,company_name,phonenumber',
                    'photographer:id,name,avatar',
                    'service:id,name,icon,category_id',
                    'service.category:id,name,icon',
                ])
                ->whereNotNull('cancellation_requested_at')
                ->whereNotIn('status', [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED])
                ->orderBy('cancellation_requested_at', 'desc')
                ->get(),
            $today
        );

            return [
                'stats' => $stats,
                'upcoming_shoots' => $upcomingShoots->values()->all(), // Convert Collection to array
                'photographers' => $photographers,
                'activity_log' => $activity->values()->all(), // Convert Collection to array
                'issues' => $issues->values()->all(), // Convert Collection to array
                'workflow' => $workflow,
                'pending_reviews' => $pendingReviews->values()->all(), // Convert Collection to array
                'pending_cancellations' => $pendingCancellations->values()->all(), // Pending cancellation requests
            ];
        });
        
        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Normalize shoot records for the dashboard cards.
     */
    protected function formatShoots(Collection $shoots, Carbon $today, bool $includeMedia = false): Collection
    {
        if ($includeMedia && $shoots->isNotEmpty()) {
            $shoots->loadMissing(['files' => function ($query) {
                $query->select(
                    'id',
                    'shoot_id',
                    'workflow_stage',
                    'is_cover',
                    'url',
                    'path',
                    'dropbox_path',
                    'thumbnail_path',
                    'web_path',
                    'placeholder_path',
                    'file_type',
                    'mime_type',
                    'filename',
                    'stored_filename'
                )
                ->orderBy('sort_order', 'asc')
                ->orderBy('created_at', 'desc');
            }]);
        }

        return $shoots->map(function (Shoot $shoot) use ($today, $includeMedia) {
            $date = $shoot->scheduled_date ? Carbon::parse($shoot->scheduled_date) : null;
            $dateTime = $this->combineDateAndTime($shoot->scheduled_date, $shoot->time);

            $summary = [
                'id' => $shoot->id,
                'day_label' => $this->getDayLabel($date, $today),
                'time_label' => $dateTime ? $dateTime->format('h:i A') : null,
                'start_time' => $dateTime ? $dateTime->toIso8601String() : null,
                'address_line' => $shoot->address,
                'city_state_zip' => $this->formatLocationLine($shoot),
                'status' => $shoot->status,
                'workflow_status' => $shoot->workflow_status,
                'client_name' => optional($shoot->client)->name,
                'client_id' => $shoot->client_id,
                'client_phone' => optional($shoot->client)->phonenumber,
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
                'created_by' => $shoot->created_by,
                // Notes fields
                'shoot_notes' => $shoot->shoot_notes,
                'company_notes' => $shoot->company_notes,
                'photographer_notes' => $shoot->photographer_notes,
                'editor_notes' => $shoot->editor_notes,
                // Property details
                'property_details' => $shoot->property_details,
                // Cancellation fields (present when cancellation_requested_at is selected)
                'cancellation_reason' => $shoot->cancellation_reason ?? null,
                'cancellation_requested_at' => optional($shoot->cancellation_requested_at)?->toIso8601String(),
            ];

            if ($includeMedia) {
                $previewImages = $this->buildShootPreviewImages($shoot);
                $heroImage = $this->resolveMediaUrl($shoot->hero_image) ?? ($previewImages[0] ?? null);
                $summary['hero_image'] = $heroImage;
                $summary['preview_images'] = $previewImages;
            }
            return $summary;
        })->values();
    }

    protected function buildShootPreviewImages(Shoot $shoot): array
    {
        if (!$shoot->relationLoaded('files') || $shoot->files->isEmpty()) {
            return [];
        }

        $editedFiles = $shoot->files->filter(function (ShootFile $file) {
            return in_array($file->workflow_stage, [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED], true);
        });

        $renderable = $editedFiles->filter(function (ShootFile $file) {
            return $this->isRenderableImage($file);
        });

        if ($renderable->isEmpty()) {
            $renderable = $shoot->files->filter(function (ShootFile $file) {
                return $this->isRenderableImage($file);
            });
        }

        if ($renderable->isEmpty()) {
            return [];
        }

        $cover = $renderable->firstWhere('is_cover', true);
        if ($cover) {
            $renderable = $renderable
                ->reject(fn (ShootFile $file) => $file->id === $cover->id)
                ->prepend($cover);
        }

        return $renderable
            ->map(function (ShootFile $file) {
                return $this->resolveFilePreviewUrl($file);
            })
            ->filter()
            ->unique()
            ->values()
            ->take(6)
            ->all();
    }

    protected function isRenderableImage(ShootFile $file): bool
    {
        $mime = strtolower((string) ($file->file_type ?? $file->mime_type ?? ''));
        if ($mime && Str::startsWith($mime, 'image/')) {
            return true;
        }

        $filename = strtolower((string) ($file->filename ?? $file->stored_filename ?? $file->path ?? ''));
        return Str::endsWith($filename, ['.jpg', '.jpeg', '.png', '.webp', '.gif']);
    }

    protected function resolveFilePreviewUrl(ShootFile $file): ?string
    {
        if ($file->url) {
            return $this->resolveMediaUrl($file->url);
        }

        $path = $file->thumbnail_path
            ?: $file->web_path
            ?: $file->placeholder_path
            ?: $file->path;

        if ($path) {
            return $this->resolveMediaUrl($path);
        }

        if ($file->dropbox_path) {
            return url('/api/shoots/' . $file->shoot_id . '/files/' . $file->id . '/preview');
        }

        return null;
    }

    protected function resolveMediaUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $clean = ltrim($path, '/');
        if (Str::startsWith($clean, ['storage/', 'api/'])) {
            return url($clean);
        }

        if (Storage::disk('public')->exists($clean)) {
            return Storage::disk('public')->url($clean);
        }

        return url('storage/' . $clean);
    }

    /**
     * Build quick stats for each photographer (load, availability, next slot).
     */
    protected function buildPhotographerSummaries(Carbon $today): array
    {
        $photographers = User::where('role', 'photographer')
            ->select('id', 'name', 'company_name', 'phonenumber', 'avatar', 'email', 'metadata')
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
                'travel_range' => $this->extractMetadataField($photographer, 'travel_range'),
                'travel_range_unit' => $this->extractMetadataField($photographer, 'travel_range_unit') ?? 'miles',
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
                    'shoot_id' => $shoot->id,
                    'shootId' => $shoot->id,
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
                'statuses' => [Shoot::STATUS_SCHEDULED],
                'accent' => '#3b82f6',
            ],
            [
                'key' => 'uploaded',
                'label' => 'Photos Uploaded',
                'statuses' => [
                    Shoot::STATUS_UPLOADED,
                ],
                'accent' => '#0ea5e9',
            ],
            [
                'key' => 'editing',
                'label' => 'Editing',
                'statuses' => [
                    Shoot::STATUS_EDITING,
                ],
                'accent' => '#a855f7',
            ],
            [
                'key' => 'ready',
                'label' => 'Ready / Delivered',
                'statuses' => [Shoot::STATUS_READY, Shoot::STATUS_DELIVERED, 'ready_for_client', 'admin_verified', 'completed', 'finalised', 'finalized'],
                'accent' => '#22c55e',
                'check_status_column' => true,
            ],
        ];

        $columns = collect($config)->map(function (array $column) use ($today) {
            // Optimize: Use select to only load necessary columns
            $query = Shoot::select('id', 'client_id', 'photographer_id', 'service_id', 'service_category', 'address', 'city', 'state', 'zip', 
                             'scheduled_date', 'time', 'status', 'workflow_status', 'is_flagged', 'admin_issue_notes',
                             'editing_completed_at', 'submitted_for_review_at', 'shoot_notes', 'company_notes',
                             'photographer_notes', 'editor_notes', 'property_details', 'created_by', 'hero_image')
                    ->with([
                        'client:id,name,company_name',
                        'photographer:id,name,avatar',
                        'service:id,name,icon,category_id',
                        'service.category:id,name,icon',
                    ]);
            
            // For delivered/ready column, check both workflow_status AND status columns
            if (!empty($column['check_status_column'])) {
                $query->where(function ($q) use ($column) {
                    $q->whereIn('workflow_status', $column['statuses'])
                      ->orWhereIn('status', $column['statuses']);
                });
            } else {
                $query->whereIn('workflow_status', $column['statuses']);
            }
            
            // For scheduled/booked column, only show shoots from today onwards
            if ($column['key'] === 'booked') {
                $query->where('scheduled_date', '>=', $today->copy()->startOfDay()->toDateString());
                $query->orderBy('scheduled_date', 'asc')->orderBy('time', 'asc');
            } else {
                $query->orderByDesc('updated_at');
            }
            
            $shoots = $this->formatShoots(
                $query->limit(15)->get(),
                $today,
                $column['key'] === 'ready'
            );

            return [
                'key' => $column['key'],
                'label' => $column['label'],
                'accent' => $column['accent'],
                'count' => $shoots->count(),
                'shoots' => $shoots->values()->all(), // Convert Collection to array
            ];
        });

        return [
            'columns' => $columns->values()->all(),
        ];
    }

    protected function buildServiceTags(Shoot $shoot): array
    {
        $tags = [];

        if ($shoot->service && $shoot->service->name) {
            $tags[] = [
                'label' => $shoot->service->name,
                'type' => 'primary',
                'icon' => $shoot->service->icon ?? null,
            ];
        }

        if ($shoot->service_category) {
            // Try to resolve category icon from the service's category relation
            $categoryIcon = null;
            if ($shoot->service && $shoot->service->category) {
                $categoryIcon = $shoot->service->category->icon ?? null;
            }
            $tags[] = [
                'label' => $shoot->service_category,
                'type' => 'secondary',
                'icon' => $categoryIcon,
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

        try {
            $datePart = $date instanceof Carbon ? $date : Carbon::parse($date);
            $timeString = $this->normalizeTimeString($time);

            // Ensure time string doesn't have AM/PM if hour is >= 13
            if (preg_match('/(\d{1,2}):(\d{2})\s*(AM|PM)/i', $timeString, $matches)) {
                $hour = (int) $matches[1];
                if ($hour >= 13) {
                    // Remove AM/PM for 24-hour format
                    $timeString = $matches[1] . ':' . $matches[2];
                }
            }

            // Try parsing with the normalized time string
            $dateTimeString = $datePart->format('Y-m-d') . ' ' . $timeString;
            return Carbon::parse($dateTimeString);
        } catch (\Throwable $e) {
            // Log the error for debugging but don't fail the request
            \Log::warning('Failed to parse date/time', [
                'date' => $date,
                'time' => $time,
                'error' => $e->getMessage(),
            ]);
            
            // Final fallback to default time if parsing still fails
            try {
                $datePart = $date instanceof Carbon ? $date : Carbon::parse($date);
                return Carbon::parse($datePart->format('Y-m-d') . ' 09:00');
            } catch (\Throwable $e2) {
                // If even the fallback fails, return null
                return null;
            }
        }
    }

    protected function normalizeTimeString(?string $time): string
    {
        $timeString = trim($time ?: '09:00');

        // If both 24-hour value and AM/PM suffix are present (e.g. "14:00 PM"),
        // drop the suffix so Carbon can parse the 24-hour value.
        if (preg_match('/\b(AM|PM)\b/i', $timeString)) {
            // Extract hour from time string
            if (preg_match('/(\d{1,2}):(\d{2})/i', $timeString, $matches)) {
                $hour = (int) $matches[1];
                
                // If hour is 13 or greater, it's already 24-hour format, remove AM/PM
                if ($hour >= 13) {
                    $timeString = preg_replace('/\s*(AM|PM)\b/i', '', $timeString);
                } else {
                    // For 12-hour format, keep AM/PM but ensure proper format
                    $timeString = preg_replace('/\s+/', ' ', $timeString);
                }
            } else {
                // If we can't parse the time, remove AM/PM and use default
                $timeString = preg_replace('/\s*(AM|PM)\b/i', '', $timeString);
            }
        }

        // Final validation - ensure we have a valid time format
        if (!preg_match('/^\d{1,2}:\d{2}(\s*(AM|PM))?$/i', $timeString)) {
            // If format is still invalid, try to extract just the time part
            if (preg_match('/(\d{1,2}):(\d{2})/i', $timeString, $matches)) {
                $timeString = $matches[1] . ':' . $matches[2];
            } else {
                $timeString = '09:00';
            }
        }

        return $timeString === '' ? '09:00' : trim($timeString);
    }

    protected function extractMetadataField(User $user, string $field)
    {
        $metadata = $user->metadata ?? [];
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?? [];
        }
        return $metadata[$field] ?? null;
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
            str_contains($action, 'requested') => 'shoot_request',
            str_contains($action, 'created') => 'shoot_created',
            str_contains($action, 'approved') => 'shoot_approved',
            str_contains($action, 'scheduled') => 'shoot_scheduled',
            str_contains($action, 'completed') => 'shoot_completed',
            str_contains($action, 'cancelled') || str_contains($action, 'canceled') => 'shoot_cancelled',
            str_contains($action, 'hold') => 'shoot_hold',
            str_contains($action, 'payment') => 'payment',
            str_contains($action, 'upload') => 'upload',
            str_contains($action, 'qc') => 'qc',
            str_contains($action, 'assign') => 'assignment',
            str_contains($action, 'review') => 'review',
            str_contains($action, 'issue') => 'alert',
            str_contains($action, 'editing') => 'editing',
            default => 'info',
        };
    }

    /**
     * Return notifications based on user role.
     * - Admin/Superadmin: All activity logs
     * - Client: Only activity logs for their shoots
     * - Photographer: Only activity logs for their assigned shoots
     * - Editor: Only activity logs for their assigned shoots
     */
    public function notifications(Request $request)
    {
        try {
            // ImpersonationMiddleware handles user swap - $request->user() returns impersonated user if applicable
            $user = $request->user();

            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $role = $this->normalizeNotificationRole($user->role ?? 'client');
            $userId = $user->id;
            $isImpersonating = $request->attributes->get('is_impersonating', false);

            // Cache key includes user ID and role for proper access control
            $cacheKey = 'notifications_' . $role . '_' . $userId . ($isImpersonating ? '_impersonate' : '');
            
            $activityLogs = Cache::remember($cacheKey, now()->addSeconds(15), function () use ($role, $userId) {
                return $this->getActivityLogsForRole($role, $userId);
            });

            return response()->json([
                'data' => [
                    'activity_log' => $activityLogs,
                    'user_role' => $role,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch notifications', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Failed to load notifications',
                'data' => [
                    'activity_log' => [],
                    'user_role' => $request->user()?->role ?? 'unknown',
                ],
            ], 200); // Return empty array instead of 500
        }
    }

    /**
     * Get activity logs filtered by user role.
     */
    protected function getActivityLogsForRole(string $role, int $userId): Collection
    {
        // Define which actions are visible to each role
        $clientVisibleActions = [
            'shoot_requested',
            'shoot_created',
            'shoot_scheduled',
            'shoot_approved',
            'shoot_started',
            'shoot_completed',
            'shoot_delivered',
            'shoot_cancelled',
            'shoot_put_on_hold',
            'hold_requested',
            'hold_approved',
            'hold_rejected',
            'shoot_submitted_for_review',
            'payment_done',
            'media_uploaded',
        ];

        $photographerVisibleActions = [
            'shoot_created',
            'shoot_scheduled',
            'shoot_approved',
            'shoot_started',
            'shoot_completed',
            'shoot_cancelled',
            'shoot_put_on_hold',
            'hold_approved',
            'media_uploaded',
        ];

        $editorVisibleActions = [
            'shoot_editing_started',
            'shoot_submitted_for_review',
            'media_uploaded',
            'hold_approved',
        ];

        // Build the query based on role
        if (in_array($role, ['admin', 'superadmin', 'salesrep', 'editing_manager'], true)) {
            // Admins and sales reps see all activity logs
            $shootActivityLogs = ShootActivityLog::with(['user:id,name', 'shoot:id,address'])
                ->latest()
                ->limit(30)
                ->get();
        } elseif ($role === 'client') {
            // Clients only see logs for their own shoots
            $shootActivityLogs = ShootActivityLog::with(['user:id,name', 'shoot:id,address'])
                ->whereHas('shoot', function ($query) use ($userId) {
                    $query->where('client_id', $userId);
                })
                ->whereIn('action', $clientVisibleActions)
                ->latest()
                ->limit(30)
                ->get();
        } elseif ($role === 'photographer') {
            // Photographers only see logs for shoots they're assigned to
            $shootActivityLogs = ShootActivityLog::with(['user:id,name', 'shoot:id,address'])
                ->whereHas('shoot', function ($query) use ($userId) {
                    $query->where(function ($shootQuery) use ($userId) {
                        $shootQuery->where('photographer_id', $userId)
                            ->orWhereHas('servicePhotographers', function ($photographerQuery) use ($userId) {
                                $photographerQuery->where('users.id', $userId);
                            });
                    });
                })
                ->whereIn('action', $photographerVisibleActions)
                ->latest()
                ->limit(30)
                ->get();
        } elseif ($role === 'editor') {
            // Editors only see logs for shoots they're assigned to
            $shootActivityLogs = ShootActivityLog::with(['user:id,name', 'shoot:id,address'])
                ->whereHas('shoot', function ($query) use ($userId) {
                    app(ShootEditingAssignmentService::class)->scopeAssignedToEditor($query, $userId);
                })
                ->whereIn('action', $editorVisibleActions)
                ->latest()
                ->limit(30)
                ->get();
        } else {
            // Unknown role - return empty collection
            return collect([]);
        }

        // Format and sanitize the activity logs, filtering out logs with deleted shoots
        $formattedShootLogs = $shootActivityLogs
            ->filter(fn ($log) => $log->shoot !== null && !($log->metadata['suppress_notifications'] ?? false))
            ->map(function (ShootActivityLog $log) use ($role) {
                return $this->formatActivityLogForRole($log, $role);
            });

        // Fetch email notifications based on role
        $emailNotifications = $this->getEmailNotificationsForRole($role, $userId);
        $userAccountNotifications = $this->getUserAccountNotificationsForRole($role, $userId);

        // Merge and sort by timestamp
        return $formattedShootLogs
            ->concat($emailNotifications)
            ->concat($userAccountNotifications)
            ->sortByDesc('timestamp')
            ->take(50)
            ->values();
    }

    /**
     * Get email notifications filtered by user role.
     */
    protected function getEmailNotificationsForRole(string $role, int $userId): Collection
    {
        $user = User::find($userId);
        if (!$user) {
            return collect([]);
        }

        // Admins see all inbound emails (messages sent TO the system)
        if (in_array($role, ['admin', 'superadmin', 'editing_manager', 'salesrep'], true)) {
            $emails = Message::where('channel', 'EMAIL')
                ->where('direction', 'INBOUND')
                ->whereIn('status', ['SENT', 'DELIVERED'])
                ->latest()
                ->limit(20)
                ->get();
        } elseif (in_array($role, ['photographer', 'editor'])) {
            // Photographers/editors only see inbound emails addressed to them
            $emails = Message::where('channel', 'EMAIL')
                ->where('direction', 'INBOUND')
                ->where('to_address', $user->email)
                ->whereIn('status', ['SENT', 'DELIVERED'])
                ->latest()
                ->limit(10)
                ->get();
        } else {
            // Clients don't need email notifications — they already receive the actual emails
            $emails = collect([]);
        }

        return $emails->map(function (Message $email) {
            $isInbound = $email->direction === 'INBOUND';
            
            return [
                'id' => 'email-' . $email->id,
                'message' => $isInbound 
                    ? "New message from {$email->sender_display_name}: " . \Illuminate\Support\Str::limit($email->subject ?? '(No Subject)', 50)
                    : "Email sent to {$email->to_address}: " . \Illuminate\Support\Str::limit($email->subject ?? '(No Subject)', 50),
                'action' => $isInbound ? 'email_received' : 'email_sent',
                'type' => 'message',
                'timestamp' => optional($email->created_at)->toDateTimeString(),
                'shootId' => $email->related_shoot_id,
                'emailId' => $email->id,
                'from' => $email->from_address,
                'to' => $email->to_address,
                'subject' => $email->subject,
                'direction' => $email->direction,
            ];
        });
    }

    protected function getUserAccountNotificationsForRole(string $role, int $userId): Collection
    {
        $baseQuery = UserActivityLog::query()
            ->with('user:id,name,email,email_status')
            ->latest('occurred_at')
            ->limit(40);

        if (in_array($role, ['admin', 'superadmin', 'editing_manager'], true)) {
            $logs = $baseQuery
                ->whereIn('event_type', [
                    'account_created',
                    'email_bounced',
                    'email_delivery_risky',
                    'email_verification_requested',
                    'email_corrected_after_bounce',
                ])
                ->get();
        } elseif ($role === 'salesrep') {
            $logs = $baseQuery
                ->whereIn('event_type', [
                    'email_bounced',
                    'email_delivery_risky',
                    'email_verification_requested',
                    'email_corrected_after_bounce',
                ])
                ->get()
                ->filter(fn (UserActivityLog $log) => (int) ($log->metadata['sales_rep_id'] ?? 0) === $userId)
                ->values();
        } elseif ($role === 'client') {
            $logs = $baseQuery
                ->where('user_id', $userId)
                ->whereIn('event_type', [
                    'email_bounced',
                    'email_delivery_risky',
                    'email_verification_requested',
                    'email_corrected_after_bounce',
                ])
                ->get();
        } else {
            return collect([]);
        }

        return $logs->map(function (UserActivityLog $log) use ($role) {
            $accountSearch = rawurlencode((string) ($log->user?->email ?: $log->user?->name ?: ''));

            return [
                'id' => 'email-issue-' . $log->id,
                'message' => $log->description ?: $log->title,
                'action' => $log->event_type,
                'type' => 'system',
                'timestamp' => optional($log->occurred_at ?? $log->created_at)->toDateTimeString(),
                'emailId' => null,
                'accountId' => $log->user_id,
                'accountName' => $log->user?->name,
                'to' => $log->user?->email,
                'subject' => $log->title,
                'direction' => 'SYSTEM',
                'actionUrl' => $role === 'client'
                    ? '/settings?tab=profile'
                    : ($accountSearch !== '' ? '/accounts?role=client&search=' . $accountSearch : '/accounts?role=client'),
                'actionLabel' => $role === 'client' ? 'Update email' : 'View account',
                'metadata' => array_merge($log->metadata ?? [], [
                    'email_status' => $log->user?->email_status,
                ]),
            ];
        });
    }

    /**
     * Format activity log entry and remove sensitive data based on role.
     */
    protected function formatActivityLogForRole(ShootActivityLog $log, string $role): array
    {
        $baseData = [
            'id' => 'sa-' . $log->id,
            'message' => $log->description ?? $log->action ?? 'Activity',
            'action' => $log->action ?? '',
            'type' => $this->inferActivityType($log->action ?? ''),
            'timestamp' => optional($log->created_at)->toDateTimeString(),
            'shootId' => $log->shoot_id,
            'address' => $log->shoot?->address ?? '',
        ];

        // Admins get full data
        if (in_array($role, ['admin', 'superadmin', 'editing_manager', 'salesrep'], true)) {
            $baseData['user'] = $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
            ] : null;
            $baseData['metadata'] = is_array($log->metadata) ? $log->metadata : [];
        } else {
            // Non-admins get sanitized data
            $baseData['user'] = null; // Don't expose who performed the action
            $metadata = is_array($log->metadata) ? $log->metadata : [];
            $baseData['metadata'] = $this->sanitizeMetadataForRole($metadata, $role);
        }

        return $baseData;
    }

    /**
     * Remove sensitive metadata based on user role.
     */
    protected function sanitizeMetadataForRole(array $metadata, string $role): array
    {
        // Keys to always remove for non-admins
        $sensitiveKeys = [
            'company_notes',
            'photographer_notes',
            'editor_notes',
            'internal_notes',
            'admin_notes',
        ];

        // Additional keys to remove based on role
        if ($role === 'photographer' || $role === 'editor') {
            // Photographers and editors shouldn't see payment details
            $sensitiveKeys = array_merge($sensitiveKeys, [
                'amount',
                'payment_amount',
                'payment_details',
                'invoice_amount',
            ]);
        }

        return array_diff_key($metadata, array_flip($sensitiveKeys));
    }

    protected function normalizeNotificationRole(?string $role): string
    {
        $normalized = strtolower((string) $role);
        $normalized = str_replace('-', '_', $normalized);

        return match ($normalized) {
            'sales_rep', 'salesrep', 'rep', 'representative' => 'salesrep',
            default => $normalized ?: 'client',
        };
    }

    /**
     * Get dynamic, context-aware insights for the Robbie AI strip.
     */
    public function robbieInsights(Request $request)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $role = $user->role;
        $insights = [];

        switch ($role) {
            case 'admin':
            case 'superadmin':
                $insights = $this->getAdminInsights($user);
                break;
            case 'client':
                $insights = $this->getClientInsights($user);
                break;
            case 'photographer':
                $insights = $this->getPhotographerInsights($user);
                break;
            case 'editor':
                $insights = $this->getEditorInsights($user);
                break;
            case 'salesRep':
                $insights = $this->getSalesRepInsights($user);
                break;
            case 'editing_manager':
                $insights = $this->getEditingManagerInsights($user);
                break;
            default:
                $insights = [];
        }

        return response()->json([
            'success' => true,
            'insights' => $insights,
            'role' => $role,
        ]);
    }

    /**
     * Get insights for admin/superadmin users.
     */
    protected function getAdminInsights(User $user): array
    {
        $insights = [];
        $today = now()->startOfDay();
        $tomorrow = now()->addDay()->startOfDay();

        // New booking requests from clients (highest priority)
        $newRequests = Shoot::where('status', Shoot::STATUS_REQUESTED)->count();
        if ($newRequests > 0) {
            $insights[] = [
                'id' => 'admin-new-requests',
                'priority' => 'blocking',
                'message' => $newRequests === 1 
                    ? "1 new booking request from a client awaiting review."
                    : "{$newRequests} new booking requests from clients awaiting review.",
                'prompt' => "Show me new booking requests.",
                'intent' => 'manage_booking',
                'action' => 'Review requests',
                'insightType' => 'new_requests',
                'entity' => 'shoot',
                'filters' => [
                    'status' => Shoot::STATUS_REQUESTED,
                ],
            ];
        }

        // Flagged shoots requiring attention
        $flaggedCount = Shoot::where('is_flagged', true)->count();
        if ($flaggedCount > 0) {
            $insights[] = [
                'id' => 'admin-flagged',
                'priority' => 'blocking',
                'message' => $flaggedCount === 1 
                    ? "1 shoot has been flagged and needs your attention."
                    : "{$flaggedCount} shoots have issues that need your attention.",
                'prompt' => "Show me shoots with issues.",
                'intent' => 'manage_booking',
                'action' => 'View issues',
                'insightType' => 'flagged_shoots',
                'entity' => 'shoot',
                'filters' => [
                    'flagged' => true,
                ],
            ];
        }

        // Shoots ready but not yet delivered
        $pendingDelivery = Shoot::whereDate('scheduled_date', '<=', $today)
            ->whereIn('workflow_status', [
                Shoot::STATUS_SCHEDULED,
                Shoot::STATUS_UPLOADED,
                Shoot::STATUS_EDITING,
            ])
            ->count();
        if ($pendingDelivery > 0) {
            $insights[] = [
                'id' => 'admin-pending-delivery',
                'priority' => 'attention',
                'message' => $pendingDelivery === 1 
                    ? "1 shoot is past due and awaiting delivery."
                    : "{$pendingDelivery} shoots are past due and need to be delivered.",
                'prompt' => "Show me shoots pending delivery.",
                'intent' => 'manage_booking',
                'action' => 'View pending',
                'insightType' => 'pending_delivery',
                'entity' => 'shoot',
                'filters' => [
                    'date' => $today->toDateString(),
                    'workflowStatus' => [
                        Shoot::STATUS_SCHEDULED,
                        Shoot::STATUS_UPLOADED,
                        Shoot::STATUS_EDITING,
                    ],
                ],
            ];
        }

        // Editing taking too long
        $stuckInEditing = Shoot::where('workflow_status', Shoot::STATUS_EDITING)
            ->where('updated_at', '<', now()->subHours(24))
            ->count();
        if ($stuckInEditing > 0) {
            $insights[] = [
                'id' => 'admin-stuck-editing',
                'priority' => 'attention',
                'message' => $stuckInEditing === 1 
                    ? "1 shoot has been in editing for over 24 hours."
                    : "{$stuckInEditing} shoots are stuck in editing — may need follow-up.",
                'prompt' => "Show me shoots stuck in editing.",
                'intent' => 'manage_booking',
                'action' => 'Review editing',
                'insightType' => 'stuck_editing',
                'entity' => 'shoot',
                'filters' => [
                    'minHours' => 24,
                ],
            ];
        }

        // Photographers haven't uploaded RAW files
        $lateRawUploads = Shoot::whereNull('photos_uploaded_at')
            ->whereDate('scheduled_date', '<', $today)
            ->whereNotIn('status', [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED])
            ->count();
        if ($lateRawUploads > 0) {
            $insights[] = [
                'id' => 'admin-late-raw',
                'priority' => 'attention',
                'message' => $lateRawUploads === 1 
                    ? "1 completed shoot is still waiting for RAW uploads."
                    : "{$lateRawUploads} completed shoots are waiting for RAW uploads.",
                'prompt' => "Show me shoots missing uploads.",
                'intent' => 'manage_booking',
                'action' => 'Review uploads',
                'insightType' => 'late_raw_uploads',
                'entity' => 'shoot',
                'filters' => [
                    'beforeDate' => $today->toDateString(),
                    'photosUploaded' => false,
                ],
            ];
        }

        // Photographer has heavy schedule tomorrow
        $overloadedPhotographer = Shoot::whereDate('scheduled_date', $tomorrow)
            ->where('status', Shoot::STATUS_SCHEDULED)
            ->whereNotNull('photographer_id')
            ->select('photographer_id', DB::raw('count(*) as total'))
            ->groupBy('photographer_id')
            ->having('total', '>=', 5)
            ->orderByDesc('total')
            ->first();

        if ($overloadedPhotographer) {
            $photographer = User::find($overloadedPhotographer->photographer_id);
            $photographerName = $photographer?->name ?? 'A photographer';
            $insights[] = [
                'id' => 'admin-photographer-overload',
                'priority' => 'attention',
                'message' => "{$photographerName} has {$overloadedPhotographer->total} shoots tomorrow — consider reassigning.",
                'prompt' => "Show me tomorrow's schedule for {$photographerName}.",
                'intent' => 'manage_booking',
                'action' => 'Review schedule',
                'insightType' => 'photographer_overload',
                'entity' => 'photographer',
                'filters' => [
                    'date' => $tomorrow->toDateString(),
                    'photographerId' => $overloadedPhotographer->photographer_id,
                    'threshold' => 5,
                ],
            ];
        }

        // Editor workload imbalance
        $editingQueueMetrics = $this->buildEditingQueueMetrics();
        $editorLoads = $editingQueueMetrics['lane_assignments']
            ->groupBy('editor_id')
            ->map(function (Collection $assignments, int|string $editorId) {
                return (object) [
                    'editor_id' => (int) $editorId,
                    'total' => $assignments->count(),
                ];
            })
            ->values();

        if ($editorLoads->count() >= 2) {
            $maxEditor = $editorLoads->sortByDesc('total')->first();
            $minEditor = $editorLoads->sortBy('total')->first();
            $diff = ($maxEditor?->total ?? 0) - ($minEditor?->total ?? 0);

            if ($diff >= 5) {
                $maxUser = User::find($maxEditor->editor_id);
                $unassigned = $editingQueueMetrics['unassigned_shoots'];

                $msg = ($maxUser?->name ?? 'An editor') . " has {$maxEditor->total} in queue";
                if ($unassigned > 0) {
                    $msg .= " — {$unassigned} unassigned";
                }
                $insights[] = [
                    'id' => 'admin-editor-imbalance',
                    'priority' => 'attention',
                    'message' => $msg . ". Consider rebalancing.",
                    'prompt' => 'Show me editor workloads.',
                    'intent' => 'manage_booking',
                    'action' => 'Balance queue',
                    'insightType' => 'editor_imbalance',
                    'entity' => 'editor',
                    'filters' => [
                        'workflowStatus' => [Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING],
                        'difference' => $diff,
                    ],
                ];
            }
        }

        // Cancellation requests need approval
        $pendingCancellations = Shoot::whereNotNull('cancellation_requested_at')
            ->whereNotIn('status', [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED])
            ->count();
        if ($pendingCancellations > 0) {
            $insights[] = [
                'id' => 'admin-pending-cancel',
                'priority' => 'attention',
                'message' => $pendingCancellations === 1 
                    ? "1 client requested a cancellation — needs your approval."
                    : "{$pendingCancellations} cancellation requests need your approval.",
                'prompt' => "Show me cancellation requests.",
                'intent' => 'manage_booking',
                'action' => 'Review',
                'insightType' => 'pending_cancellations',
                'entity' => 'shoot',
                'filters' => [
                    'hasCancellationRequest' => true,
                ],
            ];
        }

        // Today's schedule overview
        $todayShoots = Shoot::whereDate('scheduled_date', $today)->count();
        if ($todayShoots > 0) {
            $insights[] = [
                'id' => 'admin-today-shoots',
                'priority' => 'insight',
                'message' => $todayShoots === 1 
                    ? "1 shoot on today's schedule."
                    : "{$todayShoots} shoots on today's schedule.",
                'prompt' => "Show me today's shoots.",
                'intent' => 'manage_booking',
                'action' => 'View today',
                'insightType' => 'todays_shoots',
                'entity' => 'shoot',
                'filters' => [
                    'date' => $today->toDateString(),
                ],
            ];
        }

        // Default when everything is clear
        if (empty($insights)) {
            $totalShoots = Shoot::count();
            $insights[] = [
                'id' => 'admin-all-clear',
                'priority' => 'assistive',
                'message' => $totalShoots > 0 
                    ? "All clear! {$totalShoots} shoots in the system, no issues."
                    : "Ready to get started — book your first shoot!",
                'prompt' => "Give me a system overview.",
                'intent' => 'manage_booking',
                'action' => 'View dashboard',
                'insightType' => 'all_clear',
                'entity' => 'shoot',
            ];
        }

        return array_slice($insights, 0, 5);
    }

    /**
     * Get insights for client users.
     */
    protected function getClientInsights(User $user): array
    {
        $insights = [];

        // Get client's shoots
        $clientShoots = Shoot::where('client_id', $user->id);

        // Shoots awaiting payment
        $awaitingPayment = (clone $clientShoots)
            ->where('payment_status', '!=', 'paid')
            ->where('workflow_status', Shoot::STATUS_DELIVERED)
            ->count();
        if ($awaitingPayment > 0) {
            $insights[] = [
                'id' => 'client-payment',
                'priority' => 'blocking',
                'message' => "Payment is required to release delivery for {$awaitingPayment} shoot(s).",
                'prompt' => "Show me shoots that need payment.",
                'intent' => 'accounting',
                'action' => 'View payment',
                'insightType' => 'pending_payment',
                'entity' => 'shoot',
                'filters' => [
                    'workflowStatus' => [Shoot::STATUS_DELIVERED],
                    'paymentStatus' => 'unpaid',
                ],
            ];
        }

        // Shoots awaiting approval
        $awaitingApproval = (clone $clientShoots)
            ->where('status', Shoot::STATUS_REQUESTED)
            ->count();
        if ($awaitingApproval > 0) {
            $insights[] = [
                'id' => 'client-approval',
                'priority' => 'attention',
                'message' => "Your approval is needed for {$awaitingApproval} shoot(s).",
                'prompt' => "Show me shoots that need my approval.",
                'intent' => 'manage_booking',
                'action' => 'Review approval',
                'insightType' => 'pending_approval',
                'entity' => 'shoot',
                'filters' => [
                    'status' => Shoot::STATUS_REQUESTED,
                ],
            ];
        }

        // Upcoming shoots
        $upcomingShoots = (clone $clientShoots)
            ->whereDate('scheduled_date', '>=', now()->startOfDay())
            ->where('status', Shoot::STATUS_SCHEDULED)
            ->count();
        if ($upcomingShoots > 0) {
            $insights[] = [
                'id' => 'client-upcoming',
                'priority' => 'insight',
                'message' => "You have {$upcomingShoots} upcoming shoot(s).",
                'prompt' => "Show me my upcoming shoots.",
                'intent' => 'manage_booking',
                'action' => 'View schedule',
                'insightType' => 'upcoming_shoots',
                'entity' => 'shoot',
                'filters' => [
                    'startDate' => now()->startOfDay()->toDateString(),
                ],
            ];
        }

        // Default insight
        if (empty($insights)) {
            $insights[] = [
                'id' => 'client-default',
                'priority' => 'assistive',
                'message' => "Need help booking a shoot or checking status?",
                'prompt' => "Help me book a new shoot.",
                'intent' => 'manage_booking',
                'action' => 'Get help',
                'insightType' => 'general_help',
                'entity' => 'shoot',
            ];
        }

        return array_slice($insights, 0, 5);
    }

    /**
     * Get insights for photographer users.
     */
    protected function getPhotographerInsights(User $user): array
    {
        $insights = [];
        $today = now()->startOfDay();

        // Today's assigned shoots
        $todayShoots = Shoot::where('photographer_id', $user->id)
            ->whereDate('scheduled_date', $today)
            ->where('status', Shoot::STATUS_SCHEDULED)
            ->count();
        if ($todayShoots > 0) {
            $insights[] = [
                'id' => 'photographer-today',
                'priority' => 'attention',
                'message' => "You have {$todayShoots} shoot(s) scheduled for today.",
                'prompt' => "Show me my shoots for today.",
                'intent' => 'manage_booking',
                'action' => 'View today',
                'insightType' => 'todays_shoots',
                'entity' => 'shoot',
                'filters' => [
                    'date' => $today->toDateString(),
                ],
            ];
        }

        // Shoots needing upload
        $needsUpload = Shoot::where('photographer_id', $user->id)
            ->whereNull('photos_uploaded_at')
            ->whereDate('scheduled_date', '<', $today)
            ->whereNotIn('status', [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED])
            ->count();
        if ($needsUpload > 0) {
            $insights[] = [
                'id' => 'photographer-upload',
                'priority' => 'blocking',
                'message' => "{$needsUpload} shoot(s) need raw files uploaded.",
                'prompt' => "Show me shoots that need files uploaded.",
                'intent' => 'manage_booking',
                'action' => 'Upload files',
                'insightType' => 'missing_uploads',
                'entity' => 'shoot',
                'filters' => [
                    'beforeDate' => $today->toDateString(),
                    'photosUploaded' => false,
                ],
            ];
        }

        // Upcoming shoots this week
        $weekShoots = Shoot::where('photographer_id', $user->id)
            ->whereBetween('scheduled_date', [$today, now()->endOfWeek()])
            ->where('status', Shoot::STATUS_SCHEDULED)
            ->count();
        if ($weekShoots > 0 && $todayShoots === 0) {
            $insights[] = [
                'id' => 'photographer-week',
                'priority' => 'insight',
                'message' => "You have {$weekShoots} shoot(s) this week.",
                'prompt' => "Show me my schedule for this week.",
                'intent' => 'availability',
                'action' => 'View week',
                'insightType' => 'upcoming_shoots',
                'entity' => 'shoot',
                'filters' => [
                    'startDate' => $today->toDateString(),
                    'endDate' => now()->endOfWeek()->toDateString(),
                ],
            ];
        }

        // Default insight
        if (empty($insights)) {
            $insights[] = [
                'id' => 'photographer-default',
                'priority' => 'assistive',
                'message' => "No scheduled shoots. Update your availability to get more bookings!",
                'prompt' => "Help me update my availability.",
                'intent' => 'availability',
                'action' => 'Set availability',
                'insightType' => 'general_help',
                'entity' => 'shoot',
            ];
        }

        return array_slice($insights, 0, 5);
    }

    /**
     * Get insights for editor users.
     */
    protected function getEditorInsights(User $user): array
    {
        $insights = [];

        // Editing queue - only shoots assigned to this editor
        $editingQueue = $this->buildEditorQueueQuery($user->id)->count();
        if ($editingQueue > 0) {
            $insights[] = [
                'id' => 'editor-queue',
                'priority' => 'attention',
                'message' => "{$editingQueue} shoot(s) in your editing queue.",
                'prompt' => "Show me my editing queue.",
                'intent' => 'manage_booking',
                'action' => 'View queue',
                'insightType' => 'editing_queue',
                'entity' => 'shoot',
                'filters' => [
                    'workflowStatus' => [Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING],
                ],
            ];
        }

        // Shoots assigned to this editor
        $assignedShoots = $editingQueue;
        if ($assignedShoots > 0) {
            $insights[] = [
                'id' => 'editor-assigned',
                'priority' => 'insight',
                'message' => "{$assignedShoots} shoot(s) assigned to you.",
                'prompt' => "Show me shoots assigned to me.",
                'intent' => 'manage_booking',
                'action' => 'View assigned',
                'insightType' => 'editing_queue',
                'entity' => 'shoot',
                'filters' => [
                    'assigned' => true,
                    'workflowStatus' => [Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING],
                ],
            ];
        }

        // Default insight
        if (empty($insights)) {
            $insights[] = [
                'id' => 'editor-default',
                'priority' => 'assistive',
                'message' => "No shoots in your queue. Check back soon for new assignments!",
                'prompt' => "Show me the editing workflow.",
                'intent' => 'manage_booking',
                'action' => 'View workflow',
                'insightType' => 'general_help',
                'entity' => 'shoot',
            ];
        }

        return array_slice($insights, 0, 5);
    }

    /**
     * Get insights for sales rep users.
     */
    protected function getSalesRepInsights(User $user): array
    {
        $insights = [];

        // Get clients created by this sales rep
        $clientIds = User::where('created_by_id', $user->id)
            ->where('role', 'client')
            ->pluck('id');

        // Shoots for their clients
        $clientShoots = Shoot::whereIn('client_id', $clientIds);

        // Pending bookings
        $pendingBookings = (clone $clientShoots)
            ->where('status', Shoot::STATUS_REQUESTED)
            ->count();
        if ($pendingBookings > 0) {
            $insights[] = [
                'id' => 'rep-pending',
                'priority' => 'attention',
                'message' => "{$pendingBookings} booking(s) pending for your clients.",
                'prompt' => "Show me pending bookings for my clients.",
                'intent' => 'manage_booking',
                'action' => 'Review bookings',
                'insightType' => 'pending_approval',
                'entity' => 'shoot',
                'filters' => [
                    'status' => Shoot::STATUS_REQUESTED,
                ],
            ];
        }

        // Shoots awaiting payment
        $awaitingPayment = (clone $clientShoots)
            ->where('payment_status', '!=', 'paid')
            ->where('workflow_status', Shoot::STATUS_DELIVERED)
            ->count();
        if ($awaitingPayment > 0) {
            $insights[] = [
                'id' => 'rep-payment',
                'priority' => 'attention',
                'message' => "{$awaitingPayment} shoot(s) awaiting payment.",
                'prompt' => "Show me shoots awaiting payment.",
                'intent' => 'accounting',
                'action' => 'View payments',
                'insightType' => 'pending_payment',
                'entity' => 'shoot',
                'filters' => [
                    'workflowStatus' => [Shoot::STATUS_DELIVERED],
                    'paymentStatus' => 'unpaid',
                ],
            ];
        }

        // Active clients count
        $activeClients = $clientIds->count();
        if ($activeClients > 0) {
            $insights[] = [
                'id' => 'rep-clients',
                'priority' => 'insight',
                'message' => "You have {$activeClients} active client(s).",
                'prompt' => "Show me my clients.",
                'intent' => 'client_stats',
                'action' => 'View clients',
                'insightType' => 'client_activity',
                'entity' => 'client',
                'filters' => [
                    'clientCount' => $activeClients,
                ],
            ];
        }

        // Default insight
        if (empty($insights)) {
            $insights[] = [
                'id' => 'rep-default',
                'priority' => 'assistive',
                'message' => "Ready to help your clients book their next shoot?",
                'prompt' => "Help me create a new booking for a client.",
                'intent' => 'manage_booking',
                'action' => 'New booking',
                'insightType' => 'general_help',
                'entity' => 'client',
            ];
        }

        return array_slice($insights, 0, 5);
    }

    /**
     * Get insights for editing manager users.
     * Focuses on supervising editors and managing the editing workflow.
     */
    protected function getEditingManagerInsights(User $user): array
    {
        $insights = [];
        $editingQueueMetrics = $this->buildEditingQueueMetrics();

        // Unassigned editing queue (highest priority - needs immediate action)
        $unassignedQueue = $editingQueueMetrics['unassigned_shoots'];
        if ($unassignedQueue > 0) {
            $insights[] = [
                'id' => 'em-unassigned-queue',
                'priority' => 'blocking',
                'message' => $unassignedQueue === 1 
                    ? "1 shoot is waiting to be assigned to an editor."
                    : "{$unassignedQueue} shoots are waiting to be assigned to editors.",
                'prompt' => "Show me unassigned editing queue.",
                'intent' => 'manage_booking',
                'action' => 'Assign editors',
                'insightType' => 'unassigned_editing',
                'entity' => 'shoot',
                'filters' => [
                    'editorId' => null,
                    'workflowStatus' => [Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING],
                ],
            ];
        }

        // Shoots stuck in editing for over 24 hours
        $stuckInEditing = Shoot::where('workflow_status', Shoot::STATUS_EDITING)
            ->where('updated_at', '<', now()->subHours(24))
            ->count();
        if ($stuckInEditing > 0) {
            $insights[] = [
                'id' => 'em-stuck-editing',
                'priority' => 'blocking',
                'message' => $stuckInEditing === 1 
                    ? "1 shoot has been in editing for over 24 hours."
                    : "{$stuckInEditing} shoots have been in editing over 24 hours — may need follow-up.",
                'prompt' => "Show me shoots stuck in editing.",
                'intent' => 'manage_booking',
                'action' => 'Review editing',
                'insightType' => 'stuck_editing',
                'entity' => 'shoot',
                'filters' => [
                    'workflowStatus' => [Shoot::STATUS_EDITING],
                    'minHours' => 24,
                ],
            ];
        }

        // Editor workload imbalance
        $editorLoads = $editingQueueMetrics['lane_assignments']
            ->groupBy('editor_id')
            ->map(function (Collection $assignments, int|string $editorId) {
                return (object) [
                    'editor_id' => (int) $editorId,
                    'total' => $assignments->count(),
                ];
            })
            ->values();

        if ($editorLoads->count() >= 2) {
            $maxEditor = $editorLoads->sortByDesc('total')->first();
            $minEditor = $editorLoads->sortBy('total')->first();
            $diff = ($maxEditor?->total ?? 0) - ($minEditor?->total ?? 0);

            if ($diff >= 3) {
                $maxUser = User::find($maxEditor->editor_id);
                $insights[] = [
                    'id' => 'em-editor-imbalance',
                    'priority' => 'attention',
                    'message' => ($maxUser?->name ?? 'An editor') . " has {$maxEditor->total} in queue — workload may be imbalanced.",
                    'prompt' => 'Show me editor workloads.',
                    'intent' => 'manage_booking',
                    'action' => 'Balance queue',
                    'insightType' => 'editor_imbalance',
                    'entity' => 'editor',
                    'filters' => [
                        'workflowStatus' => [Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING],
                        'difference' => $diff,
                    ],
                ];
            }
        }

        // Total editing queue overview
        $totalQueue = $editingQueueMetrics['total_queue'];
        if ($totalQueue > 0) {
            $assignedCount = $editingQueueMetrics['assigned_shoots'];
            $insights[] = [
                'id' => 'em-total-queue',
                'priority' => 'insight',
                'message' => "{$totalQueue} shoot(s) in editing queue — {$assignedCount} assigned, " . ($totalQueue - $assignedCount) . " unassigned.",
                'prompt' => "Show me the full editing queue.",
                'intent' => 'manage_booking',
                'action' => 'View queue',
                'insightType' => 'editing_queue',
                'entity' => 'shoot',
                'filters' => [
                    'workflowStatus' => [Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING],
                ],
            ];
        }

        // Shoots ready for QA/review (recently completed editing)
        $readyForReview = Shoot::where('workflow_status', Shoot::STATUS_READY)
            ->count();
        if ($readyForReview > 0) {
            $insights[] = [
                'id' => 'em-ready-review',
                'priority' => 'attention',
                'message' => $readyForReview === 1 
                    ? "1 shoot is ready for quality review."
                    : "{$readyForReview} shoots are ready for quality review.",
                'prompt' => "Show me shoots ready for review.",
                'intent' => 'manage_booking',
                'action' => 'Review edits',
                'insightType' => 'qa_ready',
                'entity' => 'shoot',
                'filters' => [
                    'workflowStatus' => [Shoot::STATUS_QA],
                ],
            ];
        }

        // Default insight when queue is clear
        if (empty($insights)) {
            $totalEditors = User::where('role', 'editor')->where('is_active', true)->count();
            $insights[] = [
                'id' => 'em-all-clear',
                'priority' => 'assistive',
                'message' => $totalEditors > 0 
                    ? "All clear! {$totalEditors} active editor(s), no backlog."
                    : "Editing queue is empty. Great work!",
                'prompt' => "Show me editor performance this week.",
                'intent' => 'manage_booking',
                'action' => 'View stats',
                'insightType' => 'all_clear',
                'entity' => 'editor',
            ];
        }

        return array_slice($insights, 0, 5);
    }

    protected function buildEditorQueueQuery(int $editorId)
    {
        $query = Shoot::query()
            ->whereIn('workflow_status', [Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING]);

        app(ShootEditingAssignmentService::class)->scopeAssignedToEditor($query, $editorId);

        return $query;
    }

    protected function buildEditingQueueMetrics(): array
    {
        $assignmentService = app(ShootEditingAssignmentService::class);
        $shoots = Shoot::with(['services.category'])
            ->whereIn('workflow_status', [Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING])
            ->get();

        $assignedShootIds = [];
        $unassignedShootIds = [];
        $laneAssignments = collect();

        foreach ($shoots as $shoot) {
            $trackedAssignments = $assignmentService->getTrackedServiceAssignments($shoot);

            if ($trackedAssignments->isEmpty()) {
                if ($shoot->editor_id) {
                    $assignedShootIds[(int) $shoot->id] = true;
                    $laneAssignments->push([
                        'shoot_id' => (int) $shoot->id,
                        'editor_id' => (int) $shoot->editor_id,
                        'lane' => 'legacy',
                    ]);
                } else {
                    $unassignedShootIds[(int) $shoot->id] = true;
                }

                continue;
            }

            foreach ($trackedAssignments->groupBy('lane') as $lane => $services) {
                $editorId = $services->pluck('editor_id')->filter()->first();
                if ($editorId) {
                    $assignedShootIds[(int) $shoot->id] = true;
                    $laneAssignments->push([
                        'shoot_id' => (int) $shoot->id,
                        'editor_id' => (int) $editorId,
                        'lane' => (string) $lane,
                    ]);
                } else {
                    $unassignedShootIds[(int) $shoot->id] = true;
                }
            }
        }

        return [
            'total_queue' => $shoots->count(),
            'assigned_shoots' => count($assignedShootIds),
            'unassigned_shoots' => count($unassignedShootIds),
            'lane_assignments' => $laneAssignments,
        ];
    }
}
