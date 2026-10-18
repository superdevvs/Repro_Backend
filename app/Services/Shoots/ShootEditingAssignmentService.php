<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootEditingDispatchItem;
use App\Models\ShootFile;
use App\Models\ShootFileVersion;
use App\Models\ShootService;
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

    /** Editing output lanes are independent of onsite capture and RAW intake. */
    public function serviceEditingLanes(mixed $service): array
    {
        $catalog = $service instanceof ShootService ? $service->service : $service;
        if (! $catalog || ! $this->serviceRequiresEditing($service)) return [];
        if ($catalog->supportsPhotoIntake() && $catalog->supportsVideoIntake()) return [self::LANE_PHOTO, self::LANE_VIDEO];
        if ($catalog->isDigitalEnhancement()) {
            $name = strtolower((string) $catalog->name);
            if (str_contains($name, 'video')) {
                return str_contains($name, 'photo') ? [self::LANE_PHOTO, self::LANE_VIDEO] : [self::LANE_VIDEO];
            }
            return [self::LANE_PHOTO];
        }
        $categoryName = $catalog->category?->name ?? $catalog->category_name ?? $catalog->name;
        $legacyLane = $this->normalizeLane($categoryName);

        return array_values(array_filter([$legacyLane ?? ($catalog->supportsVideoIntake() ? self::LANE_VIDEO
            : ($catalog->supportsPhotoIntake() ? self::LANE_PHOTO : null))]));
    }

    public function scopeAssignedToEditor(Builder $query, int|string $editorId): Builder
    {
        return $query->where(function (Builder $scope) use ($editorId) {
            $scope->where('editor_id', $editorId)
                ->orWhereHas('services', function (Builder $serviceQuery) use ($editorId) {
                    $serviceQuery->where(fn ($query) => $query->where('shoot_service.editor_id', $editorId)->orWhere('shoot_service.video_editor_id', $editorId));
                })
                ->orWhereExists(fn ($exists) => $this->constrainOpenDispatchItems($exists, $editorId));
        });
    }

    /** Human send-to-editing tasks. Reading them must not change shoot or service editor rows. */
    public function hasOpenDispatchAssignment(Shoot $shoot, User $editor): bool
    {
        return $this->openDispatchItemsQuery($shoot->id, $editor->id)->exists();
    }

    public function hasDispatchAccess(Shoot $shoot, User $editor): bool
    {
        return app(ShootDispatchOverviewReturns::class)->items($shoot, $editor, false)->isNotEmpty();
    }

    /** Open send-to-editing task for one service line. Does not write editor_id. */
    public function hasOpenDispatchForService(Shoot $shoot, User $editor, int $shootServiceId): bool
    {
        return $this->openDispatchItemsQuery($shoot->id, $editor->id)
            ->where('shoot_service_id', $shootServiceId)
            ->exists();
    }

    /**
     * Open human task that is not tied to a service line.
     * Shoot 379 is this shape: every file and task has a null shoot_service_id
     * even though the shoot still has booked services. Does not write editor_id.
     */
    public function hasUnscopedOpenDispatch(Shoot $shoot, User $editor): bool
    {
        return $this->openDispatchItemsQuery($shoot->id, $editor->id)
            ->whereNull('shoot_service_id')
            ->exists();
    }

    /** Service-line ids named by this editor's open tasks. Unscoped tasks are omitted. */
    public function openDispatchServiceIds(Shoot $shoot, User $editor): array
    {
        return $this->openDispatchItemsQuery($shoot->id, $editor->id)
            ->whereNotNull('shoot_service_id')
            ->pluck('shoot_service_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** Source files, and a returned image, for this editor's open tasks on the shoot. */
    public function openDispatchFileIds(Shoot $shoot, User $editor): array
    {
        $key = 'dispatch-file-ids:'.$shoot->id.':'.$editor->id;
        $request = request();
        $cache = $request->attributes->get('editor_dispatch_file_ids', []);
        if (! array_key_exists($key, $cache)) {
            $cache[$key] = $this->loadOpenDispatchFileIds($shoot, $editor);
            $request->attributes->set('editor_dispatch_file_ids', $cache);
        }

        return $cache[$key];
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

                return (string) ($service->pivot?->editor_id ?? '') === (string) $editor->id
                    || (string) ($service->pivot?->video_editor_id ?? '') === (string) $editor->id;
            });
        }

        return DB::table('shoot_service')
            ->where('shoot_id', $shoot->id)
            ->where(fn ($query) => $query->where('editor_id', $editor->id)->orWhere('video_editor_id', $editor->id))
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

        // Optional services stay hidden unless their intake was explicitly
        // marked for human editing. Assignment permissions are checked below.
        $services = $services->filter(fn ($service) => $this->serviceRequiresEditing($service));

        $trackedAssignments = $this->getTrackedServiceAssignments($shoot);
        // A dispatch task is an assignment even when the service pivot editor is still null.
        // Unscoped tasks (null shoot_service_id) belong to the shoot's existing services,
        // not to a guessed line. Scoped tasks reveal only the lines they name.
        $unscopedDispatch = $this->hasUnscopedOpenDispatch($shoot, $editor);
        $dispatchLineIds = $this->openDispatchServiceIds($shoot, $editor);
        $assignedToLine = function ($service) use ($editor, $unscopedDispatch, $dispatchLineIds): bool {
            if (! is_object($service)) {
                return false;
            }
            if ($unscopedDispatch) {
                return true;
            }
            $pivotId = (int) ($service->pivot?->id ?? 0);

            return in_array($pivotId, $dispatchLineIds, true)
                || (string) ($service->pivot?->editor_id ?? '') === (string) $editor->id
                || (string) ($service->pivot?->video_editor_id ?? '') === (string) $editor->id;
        };
        if ($trackedAssignments->isEmpty()) {
            if ((string) $shoot->editor_id === (string) $editor->id || $unscopedDispatch) {
                return $services->values();
            }

            return $services->filter($assignedToLine)->values();
        }

        return $services->filter($assignedToLine)->values();
    }

    /**
     * Whether a service (as loaded on a shoot) is worked by the editing lanes.
     * Defaults to true when the flag is unavailable so behaviour is preserved.
     */
    public function serviceRequiresEditing(mixed $service): bool
    {
        if (!is_object($service)) {
            return true;
        }

        $item = $service instanceof ShootService ? $service : null;
        $catalog = $item ? $item->service : $service;
        if (!$catalog) {
            return false;
        }

        $value = $catalog->requires_editing ?? null;
        $required = method_exists($catalog, 'requiresEditing')
            ? $catalog->requiresEditing()
            : ($value === null ? true : (bool) $value);
        if ($required) {
            return true;
        }

        $itemId = $item?->id ?? $catalog->pivot?->id;
        if (!$itemId) {
            return false;
        }
        if (ShootFile::where('shoot_service_id', $itemId)->where('required_for_editing', true)->exists()) {
            return true;
        }

        $item ??= ShootService::with('service')->find($itemId);
        if (!$item) {
            return false;
        }
        // Legacy RAWs have no service id. Infer their scope only when exactly
        // one booked service supports that lane; never guess between services.
        $legacyFiles = ShootFile::where('shoot_id', $item->shoot_id)->whereNull('shoot_service_id')
            ->where('required_for_editing', true)->get(['media_type', 'file_type', 'filename']);
        if ($legacyFiles->isEmpty()) {
            return false;
        }
        $items = ShootService::where('shoot_id', $item->shoot_id)->with('service')->get();
        foreach ($legacyFiles->map(fn (ShootFile $file) => $this->getFileLane($file))->unique() as $lane) {
            $compatible = $items->filter(fn (ShootService $candidate) => $lane === self::LANE_VIDEO
                ? ($candidate->service?->supportsVideoIntake() ?? false)
                : ($candidate->service?->supportsPhotoIntake() ?? false));
            if ($compatible->count() === 1 && (int) $compatible->first()->id === (int) $itemId) {
                return true;
            }
        }
        return false;
    }

    /** An explicit human-editing dispatch includes ordinary intake files, even for optional editing services. */
    public function prepareHumanEditingIntake(Shoot $shoot): void
    {
        $items = $shoot->serviceItems()->with('service')->get()
            ->filter(fn ($item) => $item->service?->supportsPhotoIntake() || $item->service?->supportsVideoIntake());
        $shoot->files()->whereIn('shoot_service_id', $items->modelKeys())
            ->where('workflow_stage', ShootFile::STAGE_TODO)
            ->where(fn ($query) => $query->where('is_extra', false)->orWhereNull('is_extra'))
            ->update(['required_for_editing' => true]);
        $shoot->unsetRelation('services');
    }

    /** Finalization completes only lanes with verified output; undelivered video remains open. */
    public function markVerifiedLanesComplete(Shoot $shoot, ?int $serviceItemId = null): void
    {
        $files = $shoot->files()->where('workflow_stage', ShootFile::STAGE_VERIFIED)->get();
        $assignments = $this->getTrackedServiceAssignments($shoot);
        foreach ($assignments as $assignment) {
            if ($serviceItemId && $assignment['shoot_service_id'] !== $serviceItemId) continue;
            if (!empty($assignment['editing_completed_at'])) continue;
            $outputs = $files->filter(fn ($file) => $this->getFileLane($file) === $assignment['lane']
                && ((int) $file->shoot_service_id === $assignment['shoot_service_id']
                    || (!$file->shoot_service_id && $assignments->where('lane', $assignment['lane'])->count() === 1)));
            if ($outputs->isEmpty()) continue;
            DB::table('shoot_service')->where('shoot_id', $shoot->id)->where('id', $assignment['shoot_service_id'])
                ->whereNull($assignment['completed_column'])->update([$assignment['completed_column'] => $outputs->max('verified_at') ?? now(), 'updated_at' => now()]);
        }
        $shoot->unsetRelation('services');
    }

    public function canEditorAccessFile(Shoot $shoot, ShootFile $file, User $editor): bool
    {
        // A send-to-editing task grants only its source files, not the shoot.
        if (in_array((int) $file->id, $this->openDispatchFileIds($shoot, $editor), true)) {
            return ! $file->is_hidden;
        }

        // Withhold non-required extras from editors regardless of any other
        // assignment they hold (Req 13.2, 13.4).
        if (!$this->isEditable($file)) {
            return false;
        }

        if ($file->shoot_service_id) {
            $serviceItem = $shoot->relationLoaded('serviceItems') ? $shoot->serviceItems->find($file->shoot_service_id) : $shoot->serviceItems()
                ->whereKey($file->shoot_service_id)
                ->first();

            if (!$serviceItem) {
                return false;
            }

            if ($serviceItem->service?->uploadIntakeType() === \App\Models\Service::INTAKE_PHOTO_VIDEO) {
                $assigned = $this->getFileLane($file) === self::LANE_VIDEO ? $serviceItem->video_editor_id : $serviceItem->editor_id;
                return $assigned && (string) $assigned === (string) $editor->id;
            }

            if ($serviceItem->editor_id) {
                return (string) $serviceItem->editor_id === (string) $editor->id;
            }

            // In a mixed AI-photo / human-video shoot the legacy editor is the video editor.
            // Do not let that fallback grant the AI photo services to the video account.
            if (\App\Models\StudioWorkspace::where('shoot_id', $shoot->id)->where('shoot_dispatch_key', 'intake')
                ->get()->contains(fn ($workspace) => \App\Services\Studio\WorkspaceServiceScope::contains($workspace, (int) $serviceItem->id))) {
                return false;
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
        $dispatchFileIds = array_flip($this->openDispatchFileIds($shoot, $editor));
        if (empty($assignedLanes) && $dispatchFileIds === []) {
            return collect();
        }

        return $files->filter(function (ShootFile $file) use ($assignedLanes, $dispatchFileIds, $shoot, $editor) {
            if (isset($dispatchFileIds[(int) $file->id])) {
                return ! $file->is_hidden;
            }
            if (empty($assignedLanes)) {
                return false;
            }
            if ($file->shoot_service_id) return $this->canEditorAccessFile($shoot, $file, $editor);
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

    public function autoAssignEditorsForShoot(Shoot $shoot, ?array $lanes = null): array
    {
        $trackedAssignments = $this->getTrackedServiceAssignments($shoot);
        if ($lanes !== null) {
            $trackedAssignments = $trackedAssignments->whereIn('lane', $lanes);
        }
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
                throw new \App\Exceptions\PublicBusinessRuleException("No {$lane} editor account is configured.");
            }

            if ($editor && $servicesMissingEditor->isNotEmpty()) {
                foreach ($servicesMissingEditor->groupBy('editor_column') as $column => $group) {
                    DB::table('shoot_service')->where('shoot_id', $shoot->id)->whereIn('id', $group->pluck('shoot_service_id')->all())
                        ->update([$column => $editor->id, $group->first()['completed_column'] => null, 'updated_at' => now()]);
                }
            }

            $laneAssignments[$lane] = [
                'lane' => $lane,
                'editor' => $editor,
                'service_ids' => $services->pluck('service_id')->map(fn ($id) => (int) $id)->values()->all(),
            ];
        }

        $freshShoot = $shoot->fresh(['services.category']) ?? $shoot;
        $shoot->editor_id = $this->syncLegacyShootEditor($freshShoot);

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

        app(ShootArchivePrewarmService::class)->whenAssignmentsActionable($shoot->fresh() ?? $shoot);

        return $resolvedEditorId;
    }

    public function markAssignedServicesReadyForUser(Shoot $shoot, User $user): array
    {
        $trackedAssignments = $this->getTrackedServiceAssignments($shoot);
        $allAssignments = $trackedAssignments;
        if ($trackedAssignments->isEmpty()) {
            return [];
        }

        if (! in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            $trackedAssignments = $trackedAssignments->where('editor_id', (int) $user->id);
        }

        if ($trackedAssignments->isEmpty()) {
            if ($this->hasDispatchAccess($shoot, $user)) return [];
            throw new \App\Exceptions\PublicBusinessRuleException('No editing lanes are assigned to this user for the shoot.');
        }

        $capabilities = app(ShootSubmissionCapabilityService::class);
        $files = $capabilities->editedFiles($shoot, $user);
        $trackedAssignments = $trackedAssignments->filter(function ($assignment) use ($shoot, $allAssignments, $capabilities, $files) {
            if (!empty($assignment['editing_completed_at'])) return false;
            if (\App\Models\ShootEditingDispatchItem::where('shoot_service_id', $assignment['shoot_service_id'])
                ->where('lane', $assignment['lane'])->whereNotIn('status', ['completed', 'cancelled'])
                ->whereHas('dispatch', fn ($query) => $query->where('shoot_id', $shoot->id)->where('scope', '!=', 'selected'))->exists()) return false;
            return $capabilities->assignmentHasVideoLink($shoot, $assignment)
                || $files->contains(fn ($file) => $this->getFileLane($file) === $assignment['lane']
                    && ((int) $file->shoot_service_id === $assignment['shoot_service_id']
                        || (!$file->shoot_service_id && $allAssignments->where('lane', $assignment['lane'])->count() === 1)));
        });
        foreach ($trackedAssignments->groupBy('completed_column') as $column => $assignments) {
            DB::table('shoot_service')->where('shoot_id', $shoot->id)->whereIn('id', $assignments->pluck('shoot_service_id')->all())
                ->whereNull($column)->update([$column => now(), 'updated_at' => now()]);
        }

        return $this->buildEditorAssignmentsPayload($shoot->fresh(['services.category']));
    }

    public function allTrackedLanesReady(Shoot $shoot): bool
    {
        if (\App\Models\ShootEditingDispatchItem::whereNotIn('status', ['completed', 'cancelled'])
            ->whereHas('dispatch', fn ($query) => $query->where('shoot_id', $shoot->id)->where('scope', '!=', 'selected'))->exists()) return false;
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

        $editorIds = $trackedAssignments
            ->pluck('editor_id')
            ->filter()
            ->unique()
            ->values();
        $editors = $editorIds->isEmpty()
            ? collect()
            : User::whereIn('id', $editorIds)->get()->keyBy('id');

        $payload = $trackedAssignments
            ->groupBy(fn (array $assignment) => $assignment['lane'].':'.($assignment['editor_id'] ?? 'unassigned'))
            ->map(function (Collection $services, string $lane) use ($editors) {
                $lane = $services->first()['lane'];
                $editorId = $services->pluck('editor_id')->filter()->first();
                $editor = $editorId ? $editors->get($editorId) : null;
                $ready = $services->every(fn (array $service) => !empty($service['editing_completed_at']));

                return [
                    'lane' => $lane,
                    'label' => ucfirst($lane),
                    'editor_id' => $editorId ? (string) $editorId : null,
                    'editor' => $editor ? array_merge([
                        'id' => (string) $editor->id,
                        'name' => $editor->name,
                        'avatar' => $editor->avatar ?? null,
                        'email' => $editor->email,
                        'role' => $editor->role,
                    ], $editor->presentEditingIdentity()) : null,
                    'service_ids' => $services->pluck('service_id')->map(fn ($id) => (string) $id)->values()->all(),
                    'shoot_service_ids' => $services->pluck('shoot_service_id')->map(fn ($id) => (string) $id)->values()->all(),
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
            // Open media tasks keep the card in the editor queue without writing editor_id.
            if ($this->hasOpenDispatchAssignment($shoot, $viewer)) {
                foreach ($this->openDispatchLanes($shoot, $viewer) as $lane) {
                    $index = $payload->search(fn (array $row) => ($row['lane'] ?? null) === $lane);
                    if ($index === false) {
                        $payload->push($this->openDispatchViewerAssignment($viewer, $lane));
                        continue;
                    }
                    $row = $payload[$index];
                    $row['ready'] = false;
                    $row['ready_at'] = null;
                    $payload[$index] = $row;
                }
            }
        }

        return $payload->all();
    }

    private function openDispatchItemsQuery(int|string $shootId, int|string $editorId)
    {
        return ShootEditingDispatchItem::query()
            ->where('editor_id', $editorId)
            ->where('destination', 'human')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereHas('dispatch', fn ($query) => $query->where('shoot_id', $shootId)->where('status', '!=', 'cancelled'));
    }

    public function constrainOpenDispatchItems($query, int|string $editorId): void
    {
        $query->select(DB::raw('1'))
            ->from('shoot_editing_dispatch_items')
            ->join('shoot_editing_dispatches', 'shoot_editing_dispatches.id', '=', 'shoot_editing_dispatch_items.dispatch_id')
            ->whereColumn('shoot_editing_dispatches.shoot_id', 'shoots.id')
            ->where('shoot_editing_dispatch_items.editor_id', $editorId)
            ->where('shoot_editing_dispatch_items.destination', 'human')
            ->where('shoot_editing_dispatches.status', '!=', 'cancelled')
            ->whereNotIn('shoot_editing_dispatch_items.status', ['completed', 'cancelled']);
    }

    public function openDispatchFileIdsByShoot(array $shootIds, int|string $editorId): array
    {
        $shootIds = array_values(array_unique(array_map('intval', $shootIds)));
        $grouped = array_fill_keys($shootIds, []);
        if ($shootIds === []) {
            return $grouped;
        }
        $items = ShootEditingDispatchItem::query()
            ->select('shoot_editing_dispatch_items.sources', 'shoot_editing_dispatch_items.primary_version_id', 'shoot_editing_dispatches.shoot_id')
            ->join('shoot_editing_dispatches', 'shoot_editing_dispatches.id', '=', 'shoot_editing_dispatch_items.dispatch_id')
            ->whereIn('shoot_editing_dispatches.shoot_id', $shootIds)
            ->where('shoot_editing_dispatch_items.editor_id', $editorId)
            ->where('shoot_editing_dispatch_items.destination', 'human')
            ->whereNotIn('shoot_editing_dispatch_items.status', ['completed', 'cancelled'])
            ->get();
        $versionShoots = [];
        foreach ($items as $item) {
            $shootId = (int) $item->shoot_id;
            foreach ((array) $item->sources as $source) {
                if (isset($source['id'])) {
                    $grouped[$shootId][] = (int) $source['id'];
                }
            }
            if ($item->primary_version_id) {
                $versionShoots[(string) $item->primary_version_id] = $shootId;
            }
        }
        if ($versionShoots !== []) {
            $published = ShootFileVersion::query()->whereIn('id', array_keys($versionShoots))->pluck('published_file_id', 'id');
            foreach ($published as $versionId => $fileId) {
                if ($fileId) {
                    $grouped[$versionShoots[(string) $versionId]][] = (int) $fileId;
                }
            }
        }
        foreach ($grouped as $shootId => $ids) {
            $grouped[$shootId] = array_values(array_unique($ids));
        }

        return $grouped;
    }

    private function loadOpenDispatchFileIds(Shoot $shoot, User $editor): array
    {
        $items = app(ShootDispatchOverviewReturns::class)->items($shoot, $editor, false);
        $ids = [];
        foreach ($items as $item) {
            foreach ((array) $item->sources as $source) {
                if (isset($source['id'])) {
                    $ids[] = (int) $source['id'];
                }
            }
            foreach ($item->overview_return_files ?? [] as $file) $ids[] = (int) $file['id'];
        }
        $versionIds = $items->pluck('primary_version_id')->filter()->values();
        if ($versionIds->isNotEmpty()) {
            $ids = array_merge($ids, ShootFileVersion::query()
                ->whereIn('id', $versionIds)
                ->pluck('published_file_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all());
        }

        return array_values(array_unique(array_merge($ids, app(ShootDispatchOverviewReturns::class)->visibleFileIds($shoot, $editor))));
    }

    private function openDispatchLanes(Shoot $shoot, User $editor): array
    {
        $lanes = $this->openDispatchItemsQuery($shoot->id, $editor->id)
            ->pluck('lane')
            ->map(fn ($lane) => $this->normalizeLane($lane))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $lanes === [] ? [self::LANE_PHOTO] : $lanes;
    }

    private function openDispatchViewerAssignment(User $viewer, string $lane): array
    {
        return [
            'lane' => $lane,
            'label' => ucfirst($lane),
            'editor_id' => (string) $viewer->id,
            'editor' => array_merge([
                'id' => (string) $viewer->id,
                'name' => $viewer->name,
                'avatar' => $viewer->avatar ?? null,
                'email' => $viewer->email,
                'role' => $viewer->role,
            ], $viewer->presentEditingIdentity()),
            'service_ids' => [],
            'shoot_service_ids' => [],
            'service_names' => [],
            'ready' => false,
            'ready_at' => null,
        ];
    }

    public function getTrackedServiceAssignments(Shoot $shoot): Collection
    {
        $services = $shoot->relationLoaded('services')
            ? collect($shoot->services)
            : $shoot->services()->with('category')->get();

        return $services->flatMap(function ($service) {
            if (! is_object($service) || ! $this->serviceRequiresEditing($service)) {
                return [];
            }
            $lanes = $this->serviceEditingLanes($service);
            $bundled = count($lanes) === 2;
            return collect($lanes)->map(function ($lane) use ($service, $bundled) {
                $editorColumn = $bundled && $lane === self::LANE_VIDEO ? 'video_editor_id' : 'editor_id';
                $completedColumn = $bundled && $lane === self::LANE_VIDEO ? 'video_editing_completed_at' : 'editing_completed_at';
                $completedAt = $service->pivot?->{$completedColumn};
                return [
                    'service_id' => (int) $service->id, 'service_name' => (string) $service->name, 'lane' => $lane,
                    'shoot_service_id' => (int) $service->pivot?->id, 'shoot_unit_id' => $service->pivot?->shoot_unit_id,
                    'editor_column' => $editorColumn, 'completed_column' => $completedColumn,
                    'editor_id' => $service->pivot?->{$editorColumn} ? (int) $service->pivot->{$editorColumn} : null,
                    'editing_completed_at' => $completedAt instanceof \DateTimeInterface ? $completedAt->format(\DateTimeInterface::ATOM) : ($completedAt ? (string) $completedAt : null),
                ];
            })->all();
        })->values();
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

        // Defense-in-depth: mis-tagged raw video (media_type=raw) still belongs
        // on the video lane so video editors can download/share it.
        $name = strtolower((string) ($file->filename ?? $file->stored_filename ?? ''));
        $mime = strtolower((string) ($file->file_type ?? $file->mime_type ?? ''));
        if ($mime !== '' && str_starts_with($mime, 'video/')) {
            return self::LANE_VIDEO;
        }
        if ($name !== '' && preg_match('/\.(mp4|mov|avi|mkv|wmv|webm)$/', $name) === 1) {
            return self::LANE_VIDEO;
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
