<?php

namespace App\Services\Shoots;

use App\Jobs\ProcessStudioWorkspace;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\ShootWorkflowService;
use App\Services\Studio\StudioProviderSettings;
use App\Services\Studio\VirtualStagingOptions;
use App\Services\Studio\WorkspaceMediaService;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShootEditingDispatchService
{
    private const ADDONS = ['virtual-staging' => 'Virtual staging', 'green-grass' => 'Grass greening'];

    public function __construct(private ShootPhotoSet $photos, private WorkspaceMediaService $media) {}

    public function plan(Shoot $shoot, ?User $user = null): array
    {
        $files = $this->photos->files($shoot);
        $photoCount = $files->count();
        if ($user) {
            $files = $files->filter(fn ($file) => app(ShootAuthorizationSupport::class)->canInteractWithShootMediaFile($shoot, $file, $user))->values();
        }
        $shoot->loadMissing('services.category');
        $addons = [];
        foreach (self::ADDONS as $preset => $label) {
            $treatment = str_replace('-', '_', $preset);
            $services = $shoot->services->filter(function ($service) use ($preset) {
                $name = strtolower($service->name.' '.$service->category?->name);
                return $preset === 'virtual-staging' ? str_contains($name, 'staging') : (str_contains($name, 'grass') || str_contains($name, 'lawn'));
            });
            $tagged = $files->filter(fn ($file) => $file->treatment === $treatment || $file->media_type === $treatment
                || $services->contains(fn ($service) => (int) $service->pivot->id === (int) $file->shoot_service_id));
            if ($services->isNotEmpty() || $tagged->isNotEmpty()) {
                $addons[] = ['preset' => $preset, 'label' => $label, 'fileIds' => $tagged->pluck('id')->values()->all(), 'serviceIds' => $services->pluck('id')->values()->all()];
            }
        }

        return [
            'shootId' => $shoot->id, 'status' => $shoot->status, 'photoCount' => $photoCount,
            'photos' => $files->map(fn ($file) => ['id' => $file->id, 'name' => $file->filename, 'available' => $file->isClearedForProcessing(),
                'url' => url("/api/studio/workspaces/sources/files/{$file->id}/preview")])->all(),
            'services' => $shoot->services->pluck('name')->values()->all(), 'addons' => $addons,
            'hasVideo' => app(ShootEditingAssignmentService::class)->getTrackedServiceAssignments($shoot)->contains('lane', 'video'),
        ];
    }

    public function dispatch(Shoot $shoot, User $user, array $data): array
    {
        $initial = ! array_key_exists('file_ids', $data);
        $key = $initial ? 'intake' : $data['request_id'];
        $inputHash = hash('sha256', json_encode(\Illuminate\Support\Arr::except($data, ['request_id'])));
        $existing = StudioWorkspace::where('shoot_id', $shoot->id)->where('shoot_dispatch_key', $key)->get();
        if ($existing->isNotEmpty()) {
            abort_if(! $initial && $existing->first()->shoot_dispatch_hash !== $inputHash, 409, 'This request was already sent with different photos or settings. Close and reopen the dialog to create another edit.');
            foreach ($existing as $workspace) {
                $this->media->authorize($workspace->media, $user, $workspace->team_id);
            }
            return ['workspaces' => $existing->map->present()->all(), 'mode' => 'ai'];
        }
        if ($data['mode'] === 'editor') {
            abort_unless($initial, 422, 'Use Send to Editing to assign the shoot to editors.');
            app(ShootWorkflowService::class)->startEditing($shoot, $user);
            return ['workspaces' => [], 'mode' => 'editor'];
        }
        abort_if($initial && $shoot->status !== Shoot::STATUS_UPLOADED, 422, 'Only uploaded shoots can be sent to editing.');
        $all = $this->photos->files($shoot);
        $selectedIds = $initial ? $all->pluck('id')->all() : array_map('intval', $data['file_ids']);
        $access = app(ShootAuthorizationSupport::class);
        $selected = $initial ? $all : $shoot->files()->whereIn('id', $selectedIds)->get()->filter(fn ($file) => ! $file->is_hidden
            && ($access->isImageMediaFile($file) || $access->isRawCameraFile($file)))->values();
        if ($selected->isEmpty() || $selected->count() !== count($selectedIds)) {
            throw ValidationException::withMessages(['file_ids' => 'Select original photos from this shoot. Refresh the media panel if it changed.']);
        }
        $full = $selected->count() === $all->count() && $all->whereIn('id', $selectedIds)->count() === $all->count();
        $preset = $full ? 'full-shoot' : ($data['preset'] ?? 'listing-ready');
        $plan = $this->plan($shoot);
        $projects = [['preset' => $preset, 'label' => $full ? 'Full Shoot' : 'Selected photos', 'files' => $selected,
            'serviceIds' => $full ? app(ShootEditingAssignmentService::class)->getTrackedServiceAssignments($shoot)->where('lane', 'photo')->pluck('service_id')->all() : []]];
        if ($full) {
            foreach ($plan['addons'] as $addon) {
                $ids = array_map('intval', $data['targets'][$addon['preset']] ?? $addon['fileIds']);
                $targets = $all->whereIn('id', $ids)->values();
                if (! $ids || count($ids) !== $targets->count()) {
                    throw ValidationException::withMessages(['targets.'.$addon['preset'] => 'Select the photos for '.$addon['label'].' before sending.']);
                }
                $projects[] = ['preset' => $addon['preset'], 'label' => $addon['label'], 'files' => $targets, 'serviceIds' => $addon['serviceIds']];
            }
        }
        $teamId = (int) ($user->team_id ?? $user->metadata['team_id'] ?? $user->id);
        $settings = app(StudioProviderSettings::class);
        foreach ($projects as &$project) {
            $project['media'] = $this->media->authorize($project['files']->map(fn ($file) => ['id' => 'file:'.$file->id, 'fileId' => $file->id, 'shootId' => $shoot->id])->all(), $user, $teamId);
            $project['route'] = $settings->route($project['preset']);
            $ready = $settings->readiness($project['preset'], $project['route']);
            abort_unless($ready['ready'], 422, $ready['reason'] ?? 'This editing service is not configured.');
        }
        unset($project);
        $staging = $data['staging'] ?? [];
        VirtualStagingOptions::assert($staging);

        return LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $user, $initial, $key, $inputHash, $projects, $teamId, $staging) {
            $locked = Shoot::lockForUpdate()->findOrFail($shoot->id);
            $existing = StudioWorkspace::where('shoot_id', $shoot->id)->where('shoot_dispatch_key', $key)->get();
            if ($existing->isNotEmpty()) {
                abort_if(! $initial && $existing->first()->shoot_dispatch_hash !== $inputHash, 409, 'This request was already sent with different photos or settings.');
                return ['mode' => 'ai', 'workspaces' => $existing->map->present()->all()];
            }
            if ($initial) {
                abort_unless($locked->status === Shoot::STATUS_UPLOADED, 409, 'This shoot has already been sent to editing.');
                app(ShootWorkflowService::class)->startEditing($locked, $user, ['video']);
            }
            $parent = null;
            $result = [];
            foreach ($projects as $project) {
                $operationId = (string) Str::uuid();
                $workspace = StudioWorkspace::create([
                    'team_id' => $teamId, 'created_by' => $user->id, 'shoot_id' => $shoot->id,
                    'shoot_dispatch_key' => $key, 'shoot_dispatch_hash' => $inputHash, 'parent_workspace_id' => $parent, 'shoot_service_ids' => $project['serviceIds'],
                    'name' => 'Shoot #'.$shoot->id.' · '.$project['label'], 'preset_id' => $project['preset'],
                    'media' => $project['media'], 'config' => ['prompt' => '', 'ratio' => '16:9', 'duration' => 30, 'transition' => 'none', 'transitionDuration' => 0.4, 'text' => ['title' => '', 'subtitle' => '', 'style' => 'none', 'position' => 'bottom'], 'frames' => array_map(fn ($item) => ['mediaId' => $item['id'], 'method' => 'fit', 'duration' => 5], $project['media']), 'adjustments' => $project['preset'] === 'virtual-staging' ? $staging : []],
                    'status' => 'generating', 'progress' => 0,
                    'operation' => ['id' => $operationId, 'key' => $key, 'type' => 'generate', 'payload' => [], 'completed' => [], 'requests' => [], 'routing' => [$project['preset'] => $project['route']]],
                ]);
                $parent ??= $workspace->id;
                // The database queue insert participates in this short transaction: no lost enqueue window.
                ProcessStudioWorkspace::dispatch($workspace->id, $operationId)->beforeCommit();
                $result[] = $workspace->fresh()->present();
            }
            return ['mode' => 'ai', 'workspaces' => $result];
        }), 'shoot.editing.dispatch');
    }
}
