<?php

namespace App\Services\Studio;

use App\Models\AiReelJob;
use App\Models\StudioWorkspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Private, normalized, immutable audio assets. IDs only work inside their owning workspace. */
class WorkspaceVideoAudio
{
    public const DISK = 'studio_audio';

    public const MAX_BYTES = 20971520;

    public const EXTENSIONS = ['mp3', 'm4a', 'aac', 'wav', 'flac'];

    public function store(StudioWorkspace $workspace, UploadedFile $file): array
    {
        abort_unless($workspace->isVideo(), 422, 'Audio is available for video projects.');
        abort_unless($file->getSize() > 0 && $file->getSize() <= self::MAX_BYTES && in_array(strtolower($file->getClientOriginalExtension()), self::EXTENSIONS, true), 422, 'Choose an MP3, M4A, AAC, WAV or FLAC file up to 20 MB.');
        $info = $this->probe($file->getPathname());
        $audio = collect($info['streams'] ?? [])->firstWhere('codec_type', 'audio');
        $duration = (float) ($info['format']['duration'] ?? 0);
        $video = collect($info['streams'] ?? [])->contains(fn ($stream) => ($stream['codec_type'] ?? '') === 'video' && empty($stream['disposition']['attached_pic']));
        abort_unless($audio && ! $video && is_finite($duration) && $duration >= 0.5 && $duration <= 600, 422, 'Choose an audio file between 0.5 seconds and 10 minutes, without video.');
        $disk = Storage::disk(self::DISK);
        abort_if(count($disk->files($workspace->id)) >= 40, 422, 'Remove an unused audio file before adding another.');
        $id = (string) Str::uuid();
        $temporary = tempnam(sys_get_temp_dir(), 'studio-audio-');
        if ($temporary === false) {
            throw new RuntimeException('Audio storage is unavailable.');
        }
        try {
            $process = new Process([config('studio.ffmpeg_path', 'ffmpeg'), '-v', 'error', '-nostdin', '-y', '-threads', '2',
                '-protocol_whitelist', 'file,pipe', '-format_whitelist', 'wav,mp3,mov,aac,flac', '-i', $file->getPathname(),
                '-map', '0:a:0', '-vn', '-map_metadata', '-1', '-t', '600', '-ac', '2', '-ar', '48000', '-c:a', 'aac', '-b:a', '192k', '-threads:a', '2', '-f', 'ipod', $temporary]);
            $process->setTimeout(120);
            $process->run();
            abort_unless($process->isSuccessful(), 422, 'This audio could not be read. Choose another file.');
            $this->prepareDirectory($workspace->id);
            $stream = fopen($temporary, 'rb');
            try {
                if (! $stream || ! $disk->put($this->relative($workspace, $id), $stream, ['visibility' => 'private'])) {
                    throw new RuntimeException('The audio could not be saved.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $data = ['id' => $id, 'workspaceId' => $workspace->id, 'name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 180),
                'mimeType' => 'audio/mp4', 'durationSeconds' => round($duration, 3), 'bytes' => filesize($temporary), 'createdAt' => now()->toIso8601String()];
            $disk->put($workspace->id.'/'.$id.'.json', json_encode($data, JSON_THROW_ON_ERROR), ['visibility' => 'private']);

            return $this->present($workspace, $data);
        } catch (\Throwable $exception) {
            $disk->delete([$this->relative($workspace, $id), $workspace->id.'/'.$id.'.json']);
            throw $exception;
        } finally {
            @unlink($temporary);
        }
    }

    public function metadata(StudioWorkspace $workspace, string $id): array
    {
        $path = $this->relative($workspace, $id);
        $disk = Storage::disk(self::DISK);
        $manifest = $workspace->id.'/'.$id.'.json';
        abort_if(is_link($disk->path($manifest)) || is_link($disk->path($path)) || is_link($disk->path($workspace->id)), 422, 'This audio file is unavailable.');
        abort_unless($disk->exists($path) && $disk->exists($manifest), 422, 'Choose an available audio file from this workspace.');
        $data = json_decode($disk->get($manifest), true);
        abort_unless(is_array($data) && ($data['workspaceId'] ?? null) === $workspace->id && ($data['id'] ?? null) === $id, 422, 'This audio does not belong to this workspace.');

        return $this->present($workspace, $data);
    }

    public function all(StudioWorkspace $workspace): array
    {
        $assets = [];
        foreach (Storage::disk(self::DISK)->files($workspace->id) as $file) {
            if (str_ends_with($file, '.json') && Str::isUuid($id = basename($file, '.json'))) {
                $assets[] = $this->metadata($workspace, $id);
            }
        }

        return collect($assets)->sortBy(fn ($asset) => ($asset['createdAt'] ?? '').'/'.$asset['id'])->values()->all();
    }

    public function path(StudioWorkspace $workspace, ?string $id): ?string
    {
        if (! $id) {
            return null;
        }
        $this->metadata($workspace, $id);
        $disk = Storage::disk(self::DISK);
        $root = realpath($disk->path(''));
        $path = $disk->path($this->relative($workspace, $id));
        $resolved = realpath($path);
        abort_unless($root && $resolved && ! is_link($path) && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR), 422, 'This audio file is unavailable.');

        return $resolved;
    }

    public function forJob(AiReelJob $job): ?string
    {
        $id = data_get($job->workflow_config, 'audio.musicId');
        if (! $id) {
            return null;
        }
        $workspace = StudioWorkspace::whereKey($job->workflow_config['studioWorkspaceId'] ?? '')->where('created_by', $job->user_id)->first();
        $linked = $workspace && ((int) data_get($workspace->operation, 'reelJobId') === $job->id
            || collect(array_merge($workspace->outputs ?? [], $workspace->history ?? []))->contains(fn ($item) => (int) ($item['reelJobId'] ?? 0) === $job->id));
        abort_unless($linked, 403, 'This audio is outside this video project.');

        return $this->path($workspace, $id);
    }

    public function delete(StudioWorkspace $workspace, string $id): void
    {
        Storage::disk(self::DISK)->delete([$this->relative($workspace, $id), $workspace->id.'/'.$id.'.json']);
    }

    private function present(StudioWorkspace $workspace, array $data): array
    {
        $data = \Illuminate\Support\Arr::only($data, ['id', 'name', 'mimeType', 'durationSeconds', 'bytes', 'createdAt']);

        return $data + ['previewUrl' => url('/api/studio/workspaces/'.$workspace->id.'/audio/'.$data['id']), 'workspaceVersion' => $workspace->version];
    }

    private function relative(StudioWorkspace $workspace, string $id): string
    {
        abort_unless(Str::isUuid($workspace->id) && Str::isUuid($id), 422, 'Choose an available workspace audio file.');

        return $workspace->id.'/'.$id.'.m4a';
    }

    private function probe(string $path): array
    {
        $process = new Process([config('studio.ffprobe_path', 'ffprobe'), '-v', 'error', '-protocol_whitelist', 'file,pipe', '-format_whitelist', 'wav,mp3,mov,aac,flac', '-show_streams', '-show_format', '-of', 'json', $path]);
        $process->setTimeout(30);
        $process->run();
        abort_unless($process->isSuccessful(), 422, 'This file is not supported audio.');

        return json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
    }

    private function prepareDirectory(string $workspaceId): void
    {
        $disk = Storage::disk(self::DISK);
        $root = rtrim($disk->path(''), DIRECTORY_SEPARATOR);
        $shared = config('filesystems.disks.'.self::DISK.'.shared_group');
        $group = $shared && function_exists('posix_getgrnam') ? posix_getgrnam($shared) : false;
        if ($shared && function_exists('posix_getgrnam') && ! $group) {
            throw new RuntimeException('The private Studio audio group is unavailable.');
        }
        foreach ([$root, $disk->path($workspaceId)] as $path) {
            if (is_link($path) || (! is_dir($path) && ! @mkdir($path, 02770, true) && ! is_dir($path))) {
                throw new RuntimeException('Private Studio audio storage is unavailable.');
            }
            clearstatcache(true, $path);
            if (PHP_OS_FAMILY !== 'Windows' && (($group && filegroup($path) !== $group['gid'] && ! @chgrp($path, $group['gid']))
                || ((fileperms($path) & 07777) !== 02770 && ! @chmod($path, 02770)))) {
                throw new RuntimeException('Private Studio audio permissions require operator review.');
            }
        }
    }
}
