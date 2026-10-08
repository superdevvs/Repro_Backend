<?php

namespace App\Services\Studio;

use App\Jobs\MergeStudioHdr;
use App\Models\ShootFile;
use App\Models\StudioWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WorkspaceHdrService
{
    public function describe(array $ids, User $user, int $teamId): array
    {
        Validator::make(['fileIds' => $ids], ['fileIds' => 'required|array|min:2|max:7', 'fileIds.*' => 'required|integer|min:1|distinct'])->validate();
        $ids = array_map('intval', $ids);
        sort($ids);
        app(WorkspaceMediaService::class)->authorize(array_map(fn ($id) => ['id' => 'file:'.$id, 'fileId' => $id], $ids), $user, $teamId);
        $files = ShootFile::whereIn('id', $ids)->orderBy('id')->get();
        $first = $files->first();
        if ($files->count() !== count($ids) || $files->contains(fn ($file) => $file->shoot_id !== $first->shoot_id
            || $file->shoot_service_id !== $first->shoot_service_id || $file->bracket_group !== $first->bracket_group
            || $file->isExtra() || ! in_array($file->workflow_stage, [null, ShootFile::STAGE_TODO], true))) {
            throw ValidationException::withMessages(['fileIds' => 'Choose exposures from one raw HDR stack in one shoot service.']);
        }
        if ($first->bracket_group) {
            $members = ShootFile::where('shoot_id', $first->shoot_id)->where('shoot_service_id', $first->shoot_service_id)
                ->where('bracket_group', $first->bracket_group)->where(fn ($query) => $query->whereNull('workflow_stage')->orWhere('workflow_stage', ShootFile::STAGE_TODO))
                ->orderBy('id')->pluck('id')->all();
            if ($members !== $ids) {
                throw ValidationException::withMessages(['fileIds' => 'Every exposure in this HDR stack must be available before merging.']);
            }
        }
        // Preview generation, scan metadata and storage promotion touch updated_at
        // without replacing a source. Published replacements increment content_version.
        $versions = $files->map(fn ($file) => [$file->id, $file->content_version ?: 1, $file->file_size, $file->shoot_service_id, $file->bracket_group, $file->sequence])->all();
        $key = hash('sha256', json_encode(['hdr-v2', $versions]));
        $url = url('/api/studio/workspaces/sources/hdr/preview').'?'.http_build_query(['fileIds' => $ids]);

        $media = ['id' => 'hdr:'.$key, 'stackFileIds' => $ids, 'shootId' => $first->shoot_id, 'name' => pathinfo($first->filename, PATHINFO_FILENAME).'-HDR.jpg', 'kind' => 'image', 'url' => $url, 'thumbnailUrl' => $url];
        if (! Storage::disk('studio_hdr')->exists($this->path($media))) {
            $legacy = $this->legacyCache($files);
            if ($legacy) {
                $disk = Storage::disk('studio_hdr');
                $temporary = $this->path($media).'.'.bin2hex(random_bytes(8)).'.tmp';
                try {
                    if (! $disk->copy($this->path($legacy), $temporary) || ! $disk->move($temporary, $this->path($media))) {
                        throw new \RuntimeException('The existing HDR merge could not be recovered.');
                    }
                } finally {
                    $disk->delete($temporary);
                }
            }
        }

        return $media;
    }

    public function matchesSource(array $media, array $current): bool
    {
        if (($media['id'] ?? '') === $current['id']) {
            return true;
        }
        $files = ShootFile::whereIn('id', $current['stackFileIds'])->orderBy('id')->get();

        return ($files->every(fn ($file) => ($file->content_version ?: 1) === 1) && ($media['id'] ?? '') === $this->legacyId($files))
            || $this->legacyCache($files, $media['id'] ?? '') !== null;
    }

    private function legacyId(Collection $files): string
    {
        $versions = $files->map(fn ($file) => [$file->id, $file->updated_at?->format('U.u'), $file->storage_path, $file->path, $file->dropbox_path, $file->file_size, $file->bracket_group, $file->sequence])->all();

        return 'hdr:'.hash('sha256', json_encode(['hdr-v1', $versions]));
    }

    /** Recover only a known merge of original, never-replaced exposures. */
    private function legacyCache(Collection $files, ?string $reference = null): ?array
    {
        $disk = Storage::disk('studio_hdr');
        if ($files->contains(fn ($file) => ($file->content_version ?: 1) !== 1)) {
            return null;
        }
        $exact = ['id' => $this->legacyId($files)];
        if (($reference === null || $reference === $exact['id']) && $disk->exists($this->path($exact))) {
            return $exact;
        }
        $ids = $files->pluck('id')->all();
        $latestUpload = $files->max('uploaded_at');
        $workspaces = StudioWorkspace::where('media', 'like', '%'.($reference ?: $ids[0]).'%')->orderByDesc('created_at')->cursor();
        foreach ($workspaces as $workspace) {
            if ($latestUpload && $workspace->created_at->lt($latestUpload)) {
                continue;
            }
            foreach ($workspace->media ?? [] as $item) {
                $stack = $item['stackFileIds'] ?? [];
                sort($stack);
                if ($stack === $ids && preg_match('/^hdr:[a-f0-9]{64}$/', $item['id'] ?? '')
                    && ($reference === null || $reference === $item['id']) && $disk->exists($this->path($item))) {
                    return $item;
                }
            }
        }

        return null;
    }

    public function path(array $media): string
    {
        abort_unless(preg_match('/^hdr:[a-f0-9]{64}$/', $media['id'] ?? ''), 422, 'Invalid HDR image reference.');

        return substr($media['id'], 4).'.jpg';
    }

    public function status(array $media): array
    {
        if (Storage::disk('studio_hdr')->exists($this->path($media))) {
            return ['status' => 'ready', 'media' => $media];
        }

        return array_merge(['status' => 'pending', 'media' => $media], Cache::get($media['id'], []));
    }

    public function start(array $media, User $user, int $teamId): array
    {
        return Cache::lock($media['id'].':dispatch', 10)->block(3, function () use ($media, $user, $teamId) {
            $status = $this->status($media);
            if (in_array($status['status'], ['ready', 'processing'], true)) {
                return $status;
            }
            Cache::put($media['id'], ['status' => 'processing'], 900);
            try {
                MergeStudioHdr::dispatch($media, $user->id, $teamId);
            } catch (\Throwable $exception) {
                Cache::forget($media['id']);
                throw $exception;
            }

            return $this->status($media);
        });
    }

    public function process(array $media, int $userId, int $teamId): void
    {
        $current = $this->describe($media['stackFileIds'], User::findOrFail($userId), $teamId);
        if (! $this->matchesSource($media, $current)) {
            throw ValidationException::withMessages(['media' => 'The raw stack changed. Reopen the picker to merge it again.']);
        }
        if ($this->status($current)['status'] === 'ready') {
            return;
        }
        $sources = array_map(fn ($id) => app(WorkspaceMediaService::class)->bytes(['fileId' => $id]), $current['stackFileIds']);
        $bytes = app(HdrExposureFusion::class)->merge($sources);
        $latest = $this->describe($media['stackFileIds'], User::findOrFail($userId), $teamId);
        if ($latest['id'] !== $current['id'] || ! @getimagesizefromstring($bytes)) {
            throw ValidationException::withMessages(['media' => 'The HDR merge is invalid or its source stack changed.']);
        }
        $disk = Storage::disk('studio_hdr');
        $path = $this->path($current);
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if (! $disk->put($temporary, $bytes) || ! $disk->move($temporary, $path)) {
                throw new \RuntimeException('The merged HDR image could not be saved.');
            }
        } finally {
            $disk->delete($temporary);
        }
        Cache::forget($media['id']);
    }

    public function failed(array $media): void
    {
        Cache::put($media['id'], ['status' => 'failed', 'error' => 'HDR merge failed. Check that the worker has align_image_stack and enfuse, and that all exposures can be decoded, then retry.'], 3600);
    }
}
