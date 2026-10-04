<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Studio\StudioProviderSettings;
use Illuminate\Support\Collection;

/** Explicit scope and immutable source versions; selection size never implies whole-shoot work. */
class ScopedEditingPlan
{
    public const WORKFLOWS = ['full-shoot' => 'Full shoot enhancement', 'listing-ready' => 'Enhancement', 'green-grass' => 'Grass greening',
        'twilight' => 'Twilight', 'sky-replacement' => 'Sky replacement', 'color-correction' => 'Colour correction',
        'perspective-correction' => 'Perspective correction', 'virtual-staging' => 'Virtual staging', 'upscale' => 'Upscale', 'revision' => 'Custom revision'];

    public function catalog(Shoot $shoot, User $user): array
    {
        $access = app(ShootAuthorizationSupport::class);
        $assignments = app(ShootEditingAssignmentService::class);
        $files = $shoot->files()->whereIn('workflow_stage', ['todo', 'completed', 'verified'])->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn ($file) => !$file->is_hidden && !$file->isIguideOfflinePackage() && $access->canInteractWithShootMediaFile($shoot, $file, $user));
        $media = $files->map(function ($file) use ($assignments, $files) {
            $stack = $file->workflow_stage === 'todo' && $file->bracket_group
                ? $files->filter(fn ($member) => $member->workflow_stage === 'todo' && $member->shoot_service_id === $file->shoot_service_id && $member->bracket_group === $file->bracket_group)
                : collect([$file]);
            return ['id' => $file->id, 'version' => $file->content_version, 'name' => $file->filename, 'lane' => $assignments->getFileLane($file),
                'source' => $file->workflow_stage === 'todo' ? 'raw' : 'edited', 'shootServiceId' => $file->shoot_service_id,
                'unitId' => $file->serviceItem?->shoot_unit_id, 'available' => $file->isClearedForProcessing(),
                'unavailableReason' => $file->isClearedForProcessing() ? null : 'This file has not passed its safety scan.',
                'url' => url("/api/studio/workspaces/sources/files/{$file->id}/preview"),
                'stack' => $stack->map(fn ($member) => ['id' => $member->id, 'version' => $member->content_version, 'name' => $member->filename])->values()->all()];
        })->values()->all();
        $settings = app(StudioProviderSettings::class);
        return ['media' => $media, 'scopes' => ['whole', 'photos', 'videos', 'selected'],
            'workflows' => collect(self::WORKFLOWS)->map(function ($label, $id) use ($settings) {
                $route = $settings->route($id);
                $readiness = $settings->readiness($id, $route);
                return ['id' => $id, 'label' => $label, 'provider' => $route['provider'], 'available' => $readiness['ready'], 'reason' => $readiness['reason'] ?? null];
            })->values()->all(),
            'editors' => $this->editors()->map(fn ($editor) => ['id' => $editor->id, 'name' => $editor->name, 'lanes' => $editor->getEditingCapabilities()])->all(),
            'assignments' => $assignments->buildEditorAssignmentsPayload($shoot, $user),
            'lanes' => $this->lanes($shoot),
            'videoAi' => ['available' => false, 'reason' => 'Video AI workflows are not enabled yet. Choose a human video editor.']];
    }

    /** Uploaded intake that a whole-shoot or whole-lane request would send. */
    public function intakeFiles(Shoot $shoot): Collection
    {
        $assignments = app(ShootEditingAssignmentService::class);
        $access = app(ShootAuthorizationSupport::class);
        return $shoot->files()->where('workflow_stage', 'todo')->where('is_hidden', false)->get()
            ->filter(fn ($file) => !$file->is_ai_edited && !$file->isIguideOfflinePackage() && $file->media_type !== 'floorplan'
                && ($assignments->getFileLane($file) === 'video' || $access->isImageMediaFile($file) || $access->isRawCameraFile($file)));
    }

    /** Whether each lane has intake to send and whether it was already sent to editing. */
    public function lanes(Shoot $shoot): array
    {
        $assignments = app(ShootEditingAssignmentService::class);
        $present = $this->intakeFiles($shoot)->map(fn ($file) => $assignments->getFileLane($file))->unique();
        $stage = $shoot->workflow_status ?: $shoot->status;
        $sent = match (true) {
            $stage === Shoot::STATUS_UPLOADED => [],
            $stage !== Shoot::STATUS_EDITING => ['photo', 'video'],
            default => $this->sentLanes($shoot),
        };
        return collect(['photo', 'video'])->mapWithKeys(fn ($lane) => [$lane => ['available' => $present->contains($lane), 'sent' => in_array($lane, $sent, true)]])->all();
    }

    private function sentLanes(Shoot $shoot): array
    {
        $requests = \App\Models\ShootEditingDispatch::where('shoot_id', $shoot->id)->where('scope', '!=', 'selected')->get(['scope']);
        // Shoots sent before lane-level requests existed went to editing as a whole.
        if ($requests->isEmpty()) return ['photo', 'video'];
        return $requests->flatMap(fn ($request) => ['whole' => ['photo', 'video'], 'photos' => ['photo'], 'videos' => ['video']][$request->scope] ?? [])
            ->unique()->values()->all();
    }

    public function preview(Shoot $shoot, User $user, array $data, bool $enforceVersions = false): array
    {
        $scope = $data['scope'];
        $destination = $data['mode'] === 'editor' ? 'human' : 'ai';
        abort_if($destination === 'ai' && $scope === 'videos', 422, 'Video AI workflows are not enabled yet. Choose a human video editor.');
        $assignments = app(ShootEditingAssignmentService::class);
        $access = app(ShootAuthorizationSupport::class);
        $files = $scope === 'selected' ? $shoot->files()->whereIn('id', $data['file_ids'] ?? [])->get() : $this->intakeFiles($shoot);
        if ($scope === 'selected') abort_unless($files->isNotEmpty() && $files->count() === count($data['file_ids'] ?? []), 422, 'Select existing files from this shoot.');
        if ($scope === 'photos') $files = $files->filter(fn ($file) => $assignments->getFileLane($file) === 'photo');
        if ($scope === 'videos') $files = $files->filter(fn ($file) => $assignments->getFileLane($file) === 'video');
        abort_if($destination === 'ai' && $scope === 'selected' && $files->contains(fn ($file) => $assignments->getFileLane($file) === 'video'), 422, 'Video AI workflows are not enabled yet. Choose a human video editor.');
        $workflow = $data['preset'] ?? (in_array($scope, ['whole', 'photos'], true) ? 'full-shoot' : 'listing-ready');
        abort_if($workflow === 'full-shoot' && $scope === 'selected', 422, 'Full shoot enhancement requires an explicit Whole shoot or Photos only scope.');
        if ($destination === 'ai' && $workflow === 'revision') abort_unless(trim($data['instructions'] ?? '') !== '', 422, 'Describe the custom revision.');
        if ($workflow === 'virtual-staging') \App\Services\Studio\VirtualStagingOptions::assert($data['staging'] ?? []);
        $editors = $this->editors()->keyBy('id');
        $tracked = $assignments->getTrackedServiceAssignments($shoot);
        $items = [];
        foreach ($files as $file) {
            $lane = $assignments->getFileLane($file);
            $stack = $lane === 'photo' && $file->workflow_stage === 'todo' && $file->bracket_group
                ? $shoot->files()->where('workflow_stage', 'todo')->where('shoot_service_id', $file->shoot_service_id)->where('bracket_group', $file->bracket_group)->orderBy('id')->get()
                : collect([$file]);
            $key = $stack->count() > 1 ? 'stack:'.($file->shoot_service_id ?? 'none').':'.$file->bracket_group : 'file:'.$file->id;
            if (isset($items[$key])) continue;
            foreach ($stack as $member) {
                abort_unless(in_array($member->workflow_stage, ['todo', 'completed', 'verified'], true) && !$member->is_hidden && $member->isClearedForProcessing()
                    && $access->canInteractWithShootMediaFile($shoot, $member, $user), 422, 'A selected file or HDR exposure is unavailable. Refresh the selection.');
                $expected = $data['source_versions'][(string) $member->id] ?? null;
                if ($enforceVersions) abort_unless($expected !== null && (int) $expected === (int) $member->content_version, 409, 'A selected image changed. Review the latest files before sending.');
            }
            $route = $destination === 'ai' && $lane === 'photo' ? 'ai' : 'human';
            $editor = null;
            if ($route === 'human') {
                $override = $data[$lane.'_editor_id'] ?? null;
                $assignedId = $tracked->first(fn ($assignment) => $assignment['shoot_service_id'] === (int) $file->shoot_service_id && $assignment['lane'] === $lane)['editor_id'] ?? $shoot->editor_id;
                $editor = $override ? $editors->get((int) $override) : $editors->get((int) $assignedId);
                if (!$override && (!$editor || !$editor->canEditLane($lane))) $editor = $editors->first(fn ($candidate) => $candidate->canEditLane($lane));
                abort_unless($editor && $editor->canEditLane($lane), 422, 'Choose an eligible '.$lane.' editor before sending.');
            }
            $items[$key] = ['key' => $workflow.':'.$key, 'workflow' => $workflow, 'sources' => $stack->map(fn ($member) => ['id' => $member->id, 'version' => $member->content_version, 'name' => $member->filename])->values()->all(),
                'shoot_service_id' => $file->shoot_service_id, 'lane' => $lane, 'destination' => $route,
                'editor_id' => $editor?->id, 'editor_name' => $editor?->name, 'source' => $file->workflow_stage === 'todo' ? 'raw' : 'edited',
                'publication' => $lane === 'video' ? 'Return a finished video link' : ($file->workflow_stage === 'todo' ? 'Create linked edited image' : 'Replace current image and keep its previous version')];
        }
        abort_unless($items, 422, 'No media is available for this scope. Select individual edited images to re-edit them.');
        $route = null;
        if (collect($items)->contains('destination', 'ai')) {
            $settings = app(StudioProviderSettings::class);
            $route = $settings->route($workflow);
            $ready = $settings->readiness($workflow, $route);
            abort_unless($ready['ready'], 422, $ready['reason'] ?? 'This workflow is not configured.');
        }
        $routes = $route ? [$workflow => $route] : [];
        if ($destination === 'ai' && $workflow === 'full-shoot') {
            foreach (app(ShootEditingDispatchService::class)->plan($shoot)['addons'] as $addon) {
                $ids = $data['targets'][$addon['preset']] ?? $addon['fileIds'];
                abort_unless($ids && !array_diff($ids, $files->pluck('id')->all()), 422, 'Select the photos for '.$addon['label'].' before sending.');
                $extra = $this->preview($shoot, $user, array_merge($data, ['scope' => 'selected', 'file_ids' => $ids, 'preset' => $addon['preset']]), $enforceVersions);
                foreach ($extra['items'] as $item) $items[$item['key']] = $item;
                $routes += $extra['routes'];
            }
        }
        return ['scope' => $scope, 'destination' => $destination, 'workflow' => $workflow, 'instructions' => $data['instructions'] ?? '',
            'items' => array_values($items), 'inputCount' => count($items), 'sourceCount' => collect($items)->sum(fn ($item) => count($item['sources'])),
            'photoCount' => collect($items)->where('lane', 'photo')->count(), 'videoCount' => collect($items)->where('lane', 'video')->count(),
            'provider' => $route, 'routes' => $routes, 'staging' => $data['staging'] ?? [], 'targets' => $data['targets'] ?? [],
            'eligible' => true, 'videoAiEnabled' => false];
    }

    private function editors(): Collection
    {
        return User::where('role', 'editor')->orderBy('name')->get()->filter(fn ($editor) => $editor->isAccountEligibleForAuthentication())->values();
    }
}
