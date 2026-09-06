<?php

namespace App\Jobs;

use App\Exceptions\FalTerminalException;
use App\Models\AiReelJob;
use App\Models\ShootFile;
use App\Services\FalService;
use App\Services\Shoots\ShootFileAccessService;
use App\Services\Studio\WorkspaceClipReuse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class GenerateReel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public int $tries = 1;

    private const NATIVE_CLIP_SECONDS = 5;

    private const REEL_TARGET_SECONDS = 30;

    private string $sourceDisk = 'public';

    public function __construct(public int $jobId) {}

    public function handle(FalService $fal, ShootFileAccessService $fileAccess): void
    {
        $job = AiReelJob::findOrFail($this->jobId);

        if (in_array($job->status, [AiReelJob::STATUS_CANCELLED, AiReelJob::STATUS_COMPLETED], true)) {
            return;
        }

        $tempImages = [];
        $tempClips = [];

        try {
            $job->markAsProcessing();
            $this->sourceDisk = ($job->workflow_config['sourceDisk'] ?? null) === 'public' ? 'public' : (string) config('studio_uploads.disk', 'public');
            $reuse = app(WorkspaceClipReuse::class);
            $clipSources = [];
            $requestIds = [];
            $sources = [
                ...($job->selected_file_ids ?? []),
                ...($job->source_media_refs ?? []),
            ];
            $walkthroughModel = ($job->workflow_config['presetId'] ?? null) === 'walkthrough'
                ? (string) config('services.fal.walkthrough_model') : '';

            if (config('services.fal.test_mode')) {
                foreach ($sources as $index => $fileId) {
                    $this->stopIfCancelled($job);
                    if ($cached = $reuse->localPath($job, $index)) {
                        $clipSources[$index] = $cached;

                        continue;
                    }
                    $imagePath = $this->resolveImagePath($fileId, $fileAccess, $tempImages);
                    $clipPath = $this->fakeClipFromImage($imagePath);
                    $tempClips[] = $clipPath;
                    $clipSources[$index] = $clipPath;
                    sleep(1);
                }
            } else {
                foreach ($sources as $index => $fileId) {
                    $this->stopIfCancelled($job);
                    if ($cached = $reuse->localPath($job, $index)) {
                        $clipSources[$index] = $cached;

                        continue;
                    }
                    if ($existingClip = data_get($job->workflow_config, '_studioRuntime.clips.'.$index)) {
                        $clipSources[$index] = $existingClip;

                        continue;
                    }
                    $existingRequest = data_get($job->workflow_config, '_studioRuntime.requests.'.$index);
                    if ($existingRequest) {
                        $requestIds[$index] = $existingRequest;

                        continue;
                    }
                    $imagePath = $this->resolveImagePath($fileId, $fileAccess, $tempImages);
                    $bytes = file_get_contents($imagePath);
                    if ($bytes === false) {
                        throw new RuntimeException("Could not read image {$fileId}.");
                    }
                    $mime = $this->sniffMime($imagePath);
                    $hostedImageUrl = $fal->uploadImage($bytes, $mime);
                    $prompt = WorkspaceClipReuse::prompt($job->workflow_config ?? [], $index);
                    if ($walkthroughModel !== '') {
                        $tail = null;
                        if (isset($sources[$index + 1])) {
                            $endPath = $this->resolveImagePath($sources[$index + 1], $fileAccess, $tempImages);
                            $tail = $fal->uploadImage(file_get_contents($endPath), $this->sniffMime($endPath));
                        }
                        $requestId = $fal->submitWalkthroughClip($hostedImageUrl, $tail, $prompt);
                    } else {
                        $requestId = $fal->submit($hostedImageUrl, $prompt);
                    }
                    $requestIds[$index] = $requestId;
                    $config = $job->workflow_config ?? [];
                    $config['_studioRuntime']['requests'][$index] = $requestId;
                    $job->update(['workflow_config' => $config]);
                }

                foreach ($requestIds as $index => $requestId) {
                    $existingClip = data_get($job->workflow_config, '_studioRuntime.clips.'.$index);
                    if ($existingClip) {
                        $clipSources[$index] = $existingClip;

                        continue;
                    }
                    $deadline = microtime(true) + (int) config('services.fal.video_poll_timeout', 900);
                    while (true) {
                        $this->stopIfCancelled($job);
                        try {
                            $status = $walkthroughModel !== '' ? $fal->modelStatus($walkthroughModel, $requestId) : $fal->status($requestId);
                            $clipUrl = $status === 'COMPLETED'
                                ? ($walkthroughModel !== '' ? $fal->modelVideoResult($walkthroughModel, $requestId) : $fal->result($requestId))
                                : null;
                        } catch (FalTerminalException $exception) {
                            // A completed queue request can still have a rejected result (422).
                            // Keep other scenes and in-flight requests available for a manual retry.
                            if ($exception->canDiscardRequest()) {
                                $config = $job->workflow_config ?? [];
                                unset($config['_studioRuntime']['requests'][$index]);
                                $job->update(['workflow_config' => $config]);
                            }
                            throw $exception;
                        }

                        if ($status === 'COMPLETED') {
                            $clipSources[$index] = $clipUrl;
                            $config = $job->workflow_config ?? [];
                            $config['_studioRuntime']['clips'][$index] = $clipUrl;
                            $job->update(['workflow_config' => $config]);
                            break;
                        }

                        if (in_array($status, ['FAILED', 'ERROR'], true)) {
                            $config = $job->workflow_config ?? [];
                            unset($config['_studioRuntime']['requests'][$index]);
                            $job->update(['workflow_config' => $config]);
                            throw new RuntimeException("A reel clip failed to generate ({$requestId}).");
                        }

                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('A reel clip is still processing. Retry to resume the existing provider request.');
                        }
                        sleep(max(1, (int) config('services.fal.video_poll_interval', 8)));
                    }
                }
            }

            $this->stopIfCancelled($job);
            ksort($clipSources);
            $job->markAsStitching();
            $outputs = $this->stitch($job, $clipSources);
            $job->markAsCompleted($outputs);

            $this->cleanupTempFiles($tempImages, $tempClips);
        } catch (\Throwable $e) {
            $this->cleanupTempFiles($tempImages, $tempClips);

            if ($job->fresh()?->status !== AiReelJob::STATUS_CANCELLED) {
                $job->markAsFailed($e->getMessage());
                Log::error('GenerateReel failed', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }

    private function resolveImagePath(int|string $source, ShootFileAccessService $fileAccess, array &$tempImages): string
    {
        if (is_string($source) && ! ctype_digit($source)) {
            $disk = Storage::disk($this->sourceDisk);
            if (! $disk->exists($source)) {
                throw new RuntimeException("Uploaded photo {$source} missing.");
            }
            try {
                $path = $disk->path($source);
                if (is_file($path)) {
                    return $path;
                }
            } catch (\Throwable) {
                // Remote disks are copied to a temporary local file below.
            }
            $path = tempnam(sys_get_temp_dir(), 'reel-source-');
            if ($path === false || file_put_contents($path, $disk->get($source)) === false) {
                throw new RuntimeException("Could not resolve uploaded photo {$source}.");
            }
            $tempImages[] = $path;

            return $path;
        }

        $fileId = (int) $source;
        $file = ShootFile::find($fileId);
        if (! $file) {
            throw new RuntimeException("Photo {$fileId} missing.");
        }

        $path = $fileAccess->findLocalFilePath($file);
        if ($path && is_file($path)) {
            return $path;
        }

        $downloaded = $fileAccess->downloadFromDropbox($file);
        if ($downloaded && is_file($downloaded)) {
            $tempImages[] = $downloaded;

            return $downloaded;
        }

        throw new RuntimeException("Could not resolve photo {$fileId} on disk.");
    }

    private function stopIfCancelled(AiReelJob $job): void
    {
        if ($job->fresh()?->status === AiReelJob::STATUS_CANCELLED) {
            throw new RuntimeException('Reel job was cancelled.');
        }
    }

    private function stitch(AiReelJob $job, array $clipSources): array
    {
        if (count($clipSources) === 0) {
            throw new RuntimeException('No generated clips were available to stitch.');
        }

        // Reels are short-form vertical (9:16) videos.
        [$width, $height, $label] = [1080, 1920, 'Vertical 9:16 (Reels / TikTok)'];

        $publicDisk = Storage::disk('public');
        $workDir = storage_path("app/reel-work/reel-{$job->id}");
        if (! is_dir($workDir)) {
            mkdir($workDir, 0755, true);
        }

        $localClips = [];
        foreach ($clipSources as $index => $source) {
            $path = $workDir.'/clip_'.str_pad((string) $index, 3, '0', STR_PAD_LEFT).'.mp4';
            if (preg_match('/^https?:\/\//i', $source)) {
                $bytes = file_get_contents($source);
                if ($bytes === false) {
                    throw new RuntimeException("Could not download generated clip {$index}.");
                }
                file_put_contents($path, $bytes);
            } else {
                copy($source, $path);
            }
            $localClips[] = $path;
            app(WorkspaceClipReuse::class)->remember($job, $index, $path);
        }

        $secondsPerClip = self::REEL_TARGET_SECONDS / max(1, count($localClips));
        if (! empty($job->workflow_config['studioWorkspace'])) {
            $outputDir = "reels/{$job->id}";
            $publicDisk->makeDirectory($outputDir);
            $relativePath = "{$outputDir}/reprophotos_reel.mp4";
            app(\App\Services\Studio\ReelCompositionService::class)->compose($localClips, $publicDisk->path($relativePath), $workDir, $job->workflow_config);
            $this->removeDirectory($workDir);

            return ['reel' => ['label' => ($job->workflow_config['ratio'] ?? '9:16').' reel', 'url' => $publicDisk->url($relativePath)]];
        }
        $clipSpeedFactor = $secondsPerClip > self::NATIVE_CLIP_SECONDS
            ? $secondsPerClip / self::NATIVE_CLIP_SECONDS
            : 1.0;
        $outputDir = "reels/{$job->id}";
        $publicDisk->makeDirectory($outputDir);

        $scaledClips = [];
        foreach ($localClips as $index => $clip) {
            $scaledPath = "{$workDir}/scaled_{$index}.mp4";
            $videoFilter = "scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},setsar=1";
            if ($clipSpeedFactor > 1.0) {
                $videoFilter .= ',setpts='.number_format($clipSpeedFactor, 6, '.', '').'*PTS';
            }

            $this->ffmpeg([
                '-y',
                '-i', $clip,
                '-t', number_format($secondsPerClip, 3, '.', ''),
                '-vf', $videoFilter,
                '-r', '30',
                '-c:v', 'libx264',
                '-preset', 'veryfast',
                '-pix_fmt', 'yuv420p',
                '-an',
                $scaledPath,
            ]);
            $scaledClips[] = $scaledPath;
        }

        $listFile = "{$workDir}/concat.txt";
        file_put_contents($listFile, implode("\n", array_map(
            fn (string $path) => "file '".str_replace("'", "'\\''", str_replace('\\', '/', $path))."'",
            $scaledClips
        )));

        $finalRelativePath = "{$outputDir}/reprophotos_reel.mp4";
        $finalAbsolutePath = $publicDisk->path($finalRelativePath);
        $this->ffmpeg([
            '-y',
            '-f', 'concat',
            '-safe', '0',
            '-i', $listFile,
            '-c', 'copy',
            $finalAbsolutePath,
        ]);

        $outputs = [
            'reel' => [
                'label' => $label,
                'url' => $publicDisk->url($finalRelativePath),
            ],
        ];

        $this->removeDirectory($workDir);

        return $outputs;
    }

    private function fakeClipFromImage(string $imagePath): string
    {
        $clipPath = sys_get_temp_dir().'/reel-'.uniqid('', true).'.mp4';
        $this->ffmpeg([
            '-y',
            '-loop', '1',
            '-i', $imagePath,
            '-vf', "zoompan=z='min(zoom+0.001,1.15)':d=150:s=1080x1920:fps=30,format=yuv420p",
            '-t', (string) self::NATIVE_CLIP_SECONDS,
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-pix_fmt', 'yuv420p',
            '-an',
            $clipPath,
        ]);

        return $clipPath;
    }

    private function cleanupTempFiles(array $tempImages, array $tempClips): void
    {
        foreach ($tempImages as $tempImage) {
            @unlink($tempImage);
        }
        foreach ($tempClips as $tempClip) {
            @unlink($tempClip);
        }
    }

    private function ffmpeg(array $args): void
    {
        $process = new Process(array_merge(['ffmpeg'], $args));
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('ffmpeg failed: '.substr($process->getErrorOutput(), -600));
        }
    }

    private function sniffMime(string $path): string
    {
        $info = @getimagesize($path);

        return $info['mime'] ?? mime_content_type($path) ?: 'image/jpeg';
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = "{$dir}/{$file}";
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
