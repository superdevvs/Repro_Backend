<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShootEditingAssignmentService
{
    public const LANE_PHOTO = 'photo';
    public const LANE_VIDEO = 'video';

    public function normalizeLane(?string $value): ?string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/[^a-z]+/', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        return match (true) {
            $normalized === 'p',
            $normalized === 'photo',
            $normalized === 'photos',
            str_contains($normalized, 'photo') => self::LANE_PHOTO,
            $normalized === 'video',
            $normalized === 'videos',
            str_contains($normalized, 'video') => self::LANE_VIDEO,
            default => null,
        };
    }

    public function scopeAssignedToEditor(Builder $query, int|string $editorId): Builder
    {
        return $query->where(function (Builder $scope) use ($editorId) {
            $scope->where('editor_id', $editorId)
                ->orWhereHas('services', function (Builder $serviceQuery) use ($editorId) {
                    $serviceQuery->where('shoot_service.editor_id', $editorId);
                });
        });
    }

    public function scopeUnassignedEditing(Builder $query): Builder
    {
        return $query->where(function (Builder $scope) {
            $scope->whereNull('editor_id')
                ->whereDoesntHave('services', function (Builder $serviceQuery) {
                    $serviceQuery
                        ->whereNotNull('shoot_service.editor_id')
                        ->where(function (Builder $categoryScope) {
                            $categoryScope
                                ->whereRaw('LOWER(categories.name) like ?', ['%photo%'])
                                ->orWhereRaw('LOWER(categories.name) like ?', ['%video%']);
                        });
                });
        });
    }

    public function editorHasAssignment(Shoot $shoot, User $editor): bool
    {
        if ((string) $shoot->editor_id === (string) $editor->id) {
            return true;
        }

        if ($shoot->relationLoaded('services')) {
            return collect($shoot->services)->contains(function ($service) use ($editor) {
                if (!is_object($service)) {
                    return false;
                }

                return (string) ($service->pivot?->editor_id ?? '') === (string) $editor->id;
            });
        }

        return DB::table('shoot_service')
            ->where('shoot_id', $shoot->id)
            ->where('editor_id', $editor->id)
            ->exists();
    }

    public function getAssignedLanesForEditor(Shoot $shoot, User $editor): array
    {
        $trackedAssignments = $this->getTrackedServiceAssignments($shoot)
            ->where('editor_id', (int) $editor->id);

        if ($trackedAssignments->isEmpty() && (string) $shoot->editor_id === (string) $editor->id) {
            return [self::LANE_PHOTO, self::LANE_VIDEO];
        }

        return $trackedAssignments
            ->pluck('lane')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function filterServicesForEditor(Shoot $shoot, User $editor): Collection
    {
        $services = $shoot->relationLoaded('services')
            ? collect($shoot->services)
            : $shoot->services()->with('category')->get();

        $trackedAssignments = $this->getTrackedServiceAssignments($shoot);
        if ($trackedAssignments->isEmpty()) {
            return (string) $shoot->editor_id === (string) $editor->id ? $services->values() : collect();
        }

        return $services->filter(function ($service) use ($editor) {
            if (!is_object($service)) {
                return false;
            }

            return (string) ($service->pivot?->editor_id ?? '') === (string) $editor->id;
        })->values();
    }

    public function canEditorAccessFile(Shoot $shoot, ShootFile $file, User $editor): bool
    {
        // Withhold non-required extras from editors regardless of any other
        // assignment they hold (Req 13.2, 13.4).
        if (!$this->isEditable($file)) {
            return false;
        }

        if ($file->shoot_service_id) {
            $serviceItem = $shoot->serviceItems()
                ->whereKey($file->shoot_service_id)
                ->first();

            if (!$serviceItem) {
                return false;
            }

            if ($serviceItem->editor_id) {
                return (string) $serviceItem->editor_id === (string) $editor->id;
            }

            return (string) $shoot->editor_id === (string) $editor->id;
        }

        $assignedLanes = $this->getAssignedLanesForEditor($shoot, $editor);
        if (empty($assignedLanes)) {
            return false;
        }

        $fileLane = $this->getFileLane($file);
        if ($fileLane === null) {
            return true;
        }

        return in_array($fileLane, $assignedLanes, true);
    }

    /**
     * Determine whether a file belongs in the editing payload.
     *
     * A file is editable unless it is an extra that is not marked as required for
     * editing. Required extras are always included. (Req 13.1, 13.3)
     */
    public function isEditable(ShootFile $file): bool
    {
        return $file->isRequiredForEditing();
    }

    /**
     * Build the editing payload for a shoot, excluding non-required extras and
     * always including extras flagged as required for editing. (Req 13.1, 13.3)
     */
    public function editableFiles(Shoot $shoot): Collection
    {
        $files = $shoot->relationLoaded('files')
            ? collect($shoot->files)
            : $shoot->files()->get();

        return $files
            ->filter(fn (ShootFile $file) => $this->isEditable($file))
            ->values();
    }

    public function filterFilesForEditor(Collection $files, Shoot $shoot, User $editor): Collection
    {
        $assignedLanes = $this->getAssignedLanesForEditor($shoot, $editor);
        if (empty($assignedLanes)) {
            return collect();
        }

        return $files->filter(function (ShootFile $file) use ($assignedLanes) {
            // Withhold non-required extras from the editor view in addition to
            // lane filtering (Req 13.2, 13.4).
            if (!$this->isEditable($file)) {
                return false;
            }

            $fileLane = $this->getFileLane($file);
            if ($fileLane === null) {
                return true;
            }

            return in_array($fileLane, $assignedLanes, true);
        })->values();
    }

    public function autoAssignEditorsForShoot(Shoot $shoot): array
    {
        $trackedAssignments = $this->getTrackedServiceAssignments($shoot);
        if ($trackedAssignments->isEmpty()) {
            return [];
        }

        $laneAssignments = [];
        foreach ($trackedAssignments->groupBy('lane') as $lane => $services) {
            $existingEditorIds = $services->pluck('editor_id')->filter()->unique()->values();
            $servicesMissingEditor = $services
                ->filter(fn (array $service) => empty($service['editor_id']))
                ->values();

            $editor = $existingEditorIds->isNotEmpty()
                ? User::find((int) $existingEditorIds->first())
                : $this->resolveEditorForLane($lane);

            if (!$editor && $servicesMissingEditor->isNotEmpty()) {
                throw new \InvalidArgumentException("No {$lane} editor account is configured.");
            }

            if ($editor && $servicesMissingEditor->isNotEmpty()) {
                DB::table('shoot_service')
                    ->where('shoot_id', $shoot->id)
                    ->whereIn('service_id', $servicesMissingEditor->pluck('service_id')->all())
                    ->update([
                        'editor_id' => $editor->id,
                        'editing_completed_at' => null,
                        'updated_at' => now(),
                    ]);
            }

            $laneAssignments[$lane] = [
                'lane' => $lane,
                'editor' => $editor,
                'service_ids' => $services->pluck('service_id')->map(fn ($id) => (int) $id)->values()->all(),
            ];
        }

        $this->syncLegacyShootEditor($shoot);

        return $laneAssignments;
    }

    public function syncLegacyShootEditor(Shoot $shoot): ?int
    {
        $trackedAssignments = $this->getTrackedServiceAssignments($shoot);
        if ($trackedAssignments->isEmpty()) {
            return $shoot->editor_id ? (int) $shoot->editor_id : null;
        }

        $editorIds = $trackedAssignments
            ->pluck('editor_id')
            ->filter()
            ->unique()
            ->values();

        $resolvedEditorId = $editorIds->count() === 1
            ? (int) $editorIds->first()
            : null;

        if ((int) ($shoot->editor_id ?? 0) !== (int) ($resolvedEditorId ?? 0)) {
            $shoot->editor_id = $resolvedEditorId;
            $shoot->save();
        }

        return $resolvedEditorId;
    }

    public function markAssignedServicesReadyForUser(Shoot $shoot, User $user): array
    {
        $trackedAssignments = $this->getTrackedServiceAssignments($shoot);
        if ($trackedAssignments->isEmpty()) {
            return [];
        }

        if (in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            $serviceIds = $trackedAssignments->pluck('service_id')->all();
        } else {
            $serviceIds = $trackedAssignments
                ->where('editor_id', (int) $user->id)
                ->pluck('service_id')
                ->all();
        }

        if (empty($serviceIds)) {
            throw new \InvalidArgumentException('No editing lanes are assigned to this user for the shoot.');
        }

        DB::table('shoot_service')
            ->where('shoot_id', $shoot->id)
            ->whereIn('service_id', $serviceIds)
            ->update([
                'editing_completed_at' => now(),
                'updated_at' => now(),
            ]);

        return $this->buildEditorAssignmentsPayload($shoot->fresh(['services.category']));
    }

    public function allTrackedLanesReady(Shoot $shoot): bool
    {
        $trackedAssignments = $this->getTrackedServiceAssignments($shoot);
        if ($trackedAssignments->isEmpty()) {
            return true;
        }

        return $trackedAssignments->groupBy('lane')->every(function (Collection $services) {
            return $services->every(fn (array $service) => !empty($service['editing_completed_at']));
        });
    }

    public function buildEditorAssignmentsPayload(Shoot $shoot, ?User $viewer = null): array
    {
        $trackedAssignments = $this->getTrackedServiceAssignments($shoot);
        if ($trackedAssignments->isEmpty()) {
            return [];
        }

        $editorIds = $trackedAssignments
            ->pluck('editor_id')
            ->filter()
            ->unique()
            ->values();
        $editors = $editorIds->isEmpty()
            ? collect()
            : User::whereIn('id', $editorIds)->get()->keyBy('id');

        $payload = $trackedAssignments
            ->groupBy('lane')
            ->map(function (Collection $services, string $lane) use ($editors) {
                $editorId = $services->pluck('editor_id')->filter()->first();
                $editor = $editorId ? $editors->get($editorId) : null;
                $ready = $services->every(fn (array $service) => !empty($service['editing_completed_at']));

                return [
                    'lane' => $lane,
                    'label' => ucfirst($lane),
                    'editor_id' => $editorId ? (string) $editorId : null,
                    'editor' => $editor ? [
                        'id' => (string) $editor->id,
                        'name' => $editor->name,
                        'avatar' => $editor->avatar ?? null,
                        'email' => $editor->email,
                    ] : null,
                    'service_ids' => $services->pluck('service_id')->map(fn ($id) => (string) $id)->values()->all(),
                    'service_names' => $services->pluck('service_name')->filter()->values()->all(),
                    'ready' => $ready,
                    'ready_at' => $ready
                        ? collect($services->pluck('editing_completed_at')->filter())->max()
                        : null,
                ];
            })
            ->values();

        if ($viewer && $viewer->role === 'editor') {
            $payload = $payload
                ->filter(fn (array $assignment) => (string) ($assignment['editor_id'] ?? '') === (string) $viewer->id)
                ->values();
        }

        return $payload->all();
    }

    public function getTrackedServiceAssignments(Shoot $shoot): Collection
    {
        $services = $shoot->relationLoaded('services')
            ? collect($shoot->services)
            : $shoot->services()->with('category')->get();

        return $services
            ->map(function ($service) {
                if (!is_object($service)) {
                    return null;
                }

                $categoryName = $service->category?->name
                    ?? ($service->category_name ?? null)
                    ?? $service->name
                    ?? null;
                $lane = $this->normalizeLane($categoryName);
                if ($lane === null) {
                    return null;
                }

                $completedAt = $service->pivot?->editing_completed_at ?? null;

                return [
                    'service_id' => (int) $service->id,
                    'service_name' => (string) ($service->name ?? ''),
                    'lane' => $lane,
                    'editor_id' => $service->pivot?->editor_id ? (int) $service->pivot->editor_id : null,
                    'editing_completed_at' => $completedAt instanceof \DateTimeInterface
                        ? $completedAt->format(\DateTimeInterface::ATOM)
                        : ($completedAt ? (string) $completedAt : null),
                ];
            })
            ->filter()
            ->values();
    }

    public function getFileLane(ShootFile $file): ?string
    {
        $mediaType = strtolower((string) ($file->media_type ?? ''));

        if ($mediaType === 'video') {
            return self::LANE_VIDEO;
        }

        if ($mediaType === 'floorplan') {
            return self::LANE_PHOTO;
        }

        return self::LANE_PHOTO;
    }

    protected function resolveEditorForLane(string $lane): ?User
    {
        return User::query()
            ->where('role', 'editor')
            ->orderBy('id')
            ->get(['id', 'name', 'role', 'metadata'])
            ->first(fn (User $editor) => $editor->canEditLane($lane));
    }
}
