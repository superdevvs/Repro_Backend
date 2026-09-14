<?php

namespace App\Services\Studio;

use App\Jobs\MergeStudioHdr;
use App\Models\ShootFile;
use App\Models\User;
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
        $versions = $files->map(fn ($file) => [$file->id, $file->updated_at?->format('U.u'), $file->storage_path, $file->path, $file->dropbox_path, $file->file_size, $file->bracket_group, $file->sequence])->all();
        $key = hash('sha256', json_encode(['hdr-v1', $versions]));
        $url = url('/api/studio/workspaces/sources/hdr/preview').'?'.http_build_query(['fileIds' => $ids]);

        return ['id' => 'hdr:'.$key, 'stackFileIds' => $ids, 'shootId' => $first->shoot_id, 'name' => pathinfo($first->filename, PATHINFO_FILENAME).'-HDR.jpg', 'kind' => 'image', 'url' => $url, 'thumbnailUrl' => $url];
    }

    public function path(array $media): string
    {
        abort_unless(preg_match('/^hdr:[a-f0-9]{64}$/', $media['id'] ?? ''), 422, 'Invalid HDR image reference.');

        return 'studio/hdr/'.substr($media['id'], 4).'.jpg';
    }

    public function status(array $media): array
    {
        if (Storage::disk('local')->exists($this->path($media))) {
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
        if ($current['id'] !== $media['id']) {
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
        $disk = Storage::disk('local');
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
