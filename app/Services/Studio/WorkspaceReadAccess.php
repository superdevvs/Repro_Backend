<?php

namespace App\Services\Studio;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootClientReleaseAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Recheck current source permissions without requiring old upload files or HDR caches. */
class WorkspaceReadAccess
{
    public function __construct(private ShootAuthorizationSupport $authorization, private ShootClientReleaseAccessService $release) {}

    public function visibleIds(Collection $workspaces, User $user): array
    {
        $fileIds = $workspaces->flatMap(fn ($workspace) => collect($this->sources($workspace))->flatMap(fn ($item) => array_filter([$item['fileId'] ?? null, ...($item['stackFileIds'] ?? [])])))->unique()->values();
        $files = collect();
        foreach ($fileIds->chunk(500) as $batch) {
            $files->push(...ShootFile::with('serviceItem')->whereIn('id', $batch)->get()->all());
        }
        $files = $files->keyBy('id');
        $shootIds = $workspaces->flatMap(fn ($workspace) => [$workspace->shoot_id, ...array_column($this->sources($workspace), 'shootId')])->merge($files->pluck('shoot_id'))->filter()->unique()->values();
        $shoots = collect();
        foreach ($shootIds->chunk(500) as $batch) {
            $shoots->push(...Shoot::whereIn('id', $batch)->get()->all());
        }
        $shoots = $shoots->keyBy('id');
        $shootAllowed = [];
        $fileAllowed = [];
        $canShoot = function (int $id) use ($user, $shoots, &$shootAllowed): bool {
            if (! array_key_exists($id, $shootAllowed)) {
                $shoot = $shoots->get($id);
                $shootAllowed[$id] = $shoot && $this->authorization->canAccessShootMedia($shoot, $user);
            }

            return $shootAllowed[$id];
        };
        $canFile = function (int $id) use ($user, $files, $shoots, &$fileAllowed): bool {
            if (! array_key_exists($id, $fileAllowed)) {
                $file = $files->get($id);
                $shoot = $file ? $shoots->get($file->shoot_id) : null;
                $fileAllowed[$id] = $file && $shoot && ! $file->is_hidden && $file->isClearedForProcessing()
                    && $this->authorization->canInteractWithShootMediaFile($shoot, $file, $user)
                    && ($user->role !== 'client' || ($this->authorization->canDownloadShootMediaFile($shoot, $file, $user)
                        && ! $this->release->isFileReleaseLocked($shoot, $file, $user)));
            }

            return $fileAllowed[$id];
        };

        return $workspaces->filter(function ($workspace) use ($user, $canShoot, $canFile, $files, $shoots): bool {
            if ($workspace->shoot_id && ! $canShoot((int) $workspace->shoot_id)) {
                return false;
            }
            $hasFile = false;
            foreach ($this->sources($workspace) as $item) {
                $shootId = (int) ($item['shootId'] ?? 0);
                if ($shootId && ! $canShoot($shootId)) {
                    return false;
                }
                $itemFileIds = array_filter([$item['fileId'] ?? null, ...($item['stackFileIds'] ?? [])]);
                foreach ($itemFileIds as $id) {
                    $hasFile = true;
                    if (! $canFile((int) $id) || ($shootId && (int) $files->get($id)?->shoot_id !== $shootId)) {
                        return false;
                    }
                }
                if (! $itemFileIds && $shootId && $this->release->isClientReleaseLocked($shoots->get($shootId), $user)) {
                    return false;
                }
            }
            if (! $hasFile && $workspace->shoot_id && $this->release->isClientReleaseLocked($shoots->get($workspace->shoot_id), $user)) {
                return false;
            }

            return true;
        })->pluck('id')->all();
    }

    public function authorize(StudioWorkspace $workspace, User $user): void
    {
        if (! in_array($workspace->id, $this->visibleIds(collect([$workspace]), $user), true)) {
            throw new AuthorizationException('This edit is no longer within your media access.');
        }
    }

    /** Source identities, not client IDs/URLs, survive selection changes. */
    public function retainedSources(StudioWorkspace $workspace): array
    {
        $snapshots = [];
        foreach ($this->sources($workspace) as $item) {
            $source = [];
            foreach (['shootId', 'fileId'] as $key) {
                if (! empty($item[$key])) {
                    $source[$key] = (int) $item[$key];
                }
            }
            if (! empty($item['stackFileIds'])) {
                $source['stackFileIds'] = array_values(array_unique(array_map('intval', $item['stackFileIds'])));
                sort($source['stackFileIds']);
            }
            if (! empty($item['mediaRef'])) {
                $source['mediaRef'] = $item['mediaRef'];
            }
            if ($source !== []) {
                $snapshots[hash('sha256', json_encode($source))] = $source;
            }
        }
        if (count($snapshots) > 3000) {
            throw ValidationException::withMessages(['media' => 'This edit has reached its source-history limit. Start a new edit to choose more photos.']);
        }

        return array_values($snapshots);
    }

    private function sources(StudioWorkspace $workspace): array
    {
        return [...($workspace->access_media ?? []), ...($workspace->media ?? [])];
    }
}
