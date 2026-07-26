<?php

namespace App\Jobs;

use App\Models\AiListingVideoJob;
use App\Models\ShootFile;
use App\Services\FalService;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class GenerateListingVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;
    public bool $failOnTimeout = true;

    private const NATIVE_CLIP_SECONDS = 5;

    public function __construct(public int $jobId)
    {
    }

    public function handle(FalService $fal, ShootFileAccessService $fileAccess): void
    {
        $job = AiListingVideoJob::findOrFail($this->jobId);
        $tempImages = [];
        $tempClips = [];

        if ($job->status === AiListingVideoJob::STATUS_CANCELLED) {
            return;
        }

        try {
            $job->markAsProcessing();

            $prompt = 'Slow, smooth cinematic camera glide through the space. Gentle forward dolly motion, photorealistic, natural lighting, no distortion, like a luxury property tour.';
            $clipSources = [];
            $requestIds = [];
            $sources = [
                ...($job->selected_file_ids ?? []),
                ...($job->source_media_refs ?? []),
            ];

            if (config('services.fal.test_mode')) {
                foreach ($sources as $fileId) {
                    $this->stopIfCancelled($job);
                    $imagePath = $this->resolveImagePath($fileId, $fileAccess, $tempImages);
                    $clipPath = $this->fakeClipFromImage($imagePath);
                    $tempClips[] = $clipPath;
                    $clipSources[] = $clipPath;
                    $this->incrementCompleted($job);
                    sleep(1);
                }
            } else {
                foreach ($sources as $fileId) {
                    $this->stopIfCancelled($job);
                    $imagePath = $this->resolveImagePath($fileId, $fileAccess, $tempImages);
                    $bytes = file_get_contents($imagePath);
                    if ($bytes === false) {
                        throw new RuntimeException("Could not read image {$fileId}.");
                    }
                    $mime = $this->sniffMime($imagePath);
                    $hostedImageUrl = $fal->uploadImage($bytes, $mime);
                    $requestIds[] = $fal->submit($hostedImageUrl, $prompt);
                    $job->forceFill(['provider_request_ids' => $requestIds])->save();
                }

                $clipSourcesByRequest = [];
                $pendingRequestIds = array_fill_keys($requestIds, true);
                $pollDeadline = microtime(true) + max(
                    1,
                    (int) config('services.fal.video_poll_timeout', 900)
                );
                $pollInterval = max(1, (int) config('services.fal.video_poll_interval', 5));

                while ($pendingRequestIds !== []) {
                    $this->stopIfCancelled($job);

                    if (microtime(true) >= $pollDeadline) {
                        throw new RuntimeException(
                            'Video generation timed out while waiting for fal.ai. Please try again.'
                        );
                    }

                    foreach (array_keys($pendingRequestIds) as $requestId) {
                        $status = $fal->status($requestId);

                        if ($status === 'COMPLETED') {
                            $clipSourcesByRequest[$requestId] = $fal->result($requestId);
                            unset($pendingRequestIds[$requestId]);
                            $this->incrementCompleted($job);
                            continue;
                        }

                        if (in_array($status, ['FAILED', 'ERROR', 'CANCELLED'], true)) {
                            throw new RuntimeException("A clip failed to generate ({$requestId}).");
                        }
                    }

                    if ($pendingRequestIds !== []) {
                        sleep($pollInterval);
                    }
                }

                $clipSources = array_map(
                    fn (string $requestId): string => $clipSourcesByRequest[$requestId],
                    $requestIds
                );
            }

            $this->stopIfCancelled($job);
            $job->markAsStitching();
            $outputs = $this->stitch($job, $clipSources);
            $job->markAsCompleted($outputs);

        } catch (Throwable $e) {
            if ($job->fresh()?->status !== AiListingVideoJob::STATUS_CANCELLED) {
                $job->markAsFailed($this->failureMessage($e));
                Log::error('GenerateListingVideo failed', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        } finally {
            foreach ($tempImages as $tempImage) {
                @unlink($tempImage);
            }
            foreach ($tempClips as $tempClip) {
                @unlink($tempClip);
            }
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        $job = AiListingVideoJob::find($this->jobId);
        if (! $job || ! $job->isActive()) {
            return;
        }

        $job->markAsFailed($this->failureMessage(
            $exception ?? new RuntimeException('The listing video worker stopped unexpectedly.')
        ));
    }

    private function failureMessage(Throwable $exception): string
    {
        if (str_contains(strtolower($exception->getMessage()), 'timed out')) {
            return 'Video generation took too long and was stopped. Please try again.';
        }

        return $exception->getMessage();
    }

    private function resolveImagePath(int|string $source, ShootFileAccessService $fileAccess, array &$tempImages): string
    {
        if (is_string($source) && !ctype_digit($source)) {
            $disk = Storage::disk((string) config('studio_uploads.disk', 'public'));
            if (!$disk->exists($source)) {
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
            $path = tempnam(sys_get_temp_dir(), 'listing-source-');
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

    private function incrementCompleted(AiListingVideoJob $job): void
    {
        $fresh = $job->fresh();
        if (! $fresh) {
            return;
        }

        $fresh->forceFill([
            'completed_clips' => min($fresh->total_clips, $fresh->completed_clips + 1),
        ])->save();
    }

    private function stopIfCancelled(AiListingVideoJob $job): void
    {
        if ($job->fresh()?->status === AiListingVideoJob::STATUS_CANCELLED) {
            throw new RuntimeException('Listing video job was cancelled.');
        }
    }

    private function stitch(AiListingVideoJob $job, array $clipSources): array
    {
        if (count($clipSources) === 0) {
            throw new RuntimeException('No generated clips were available to stitch.');
        }

        $formats = [
            'reels' => [1080, 1920, 'Vertical 9:16 (Reels / TikTok)'],
            'youtube' => [1920, 1080, 'Horizontal 16:9 (YouTube)'],
            'square' => [1080, 1080, 'Square 1:1 (Feed post)'],
        ];

        $publicDisk = Storage::disk('public');
        $workDir = storage_path("app/video-work/listing-{$job->id}");
        if (! is_dir($workDir)) {
            mkdir($workDir, 0755, true);
        }

        $localClips = [];
        foreach ($clipSources as $index => $source) {
            $path = $workDir . '/clip_' . str_pad((string) $index, 3, '0', STR_PAD_LEFT) . '.mp4';
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
        }

        $secondsPerClip = $job->target_seconds / max(1, count($localClips));
        $clipSpeedFactor = $secondsPerClip > self::NATIVE_CLIP_SECONDS
            ? $secondsPerClip / self::NATIVE_CLIP_SECONDS
            : 1.0;
        $outputDir = "listing-videos/{$job->id}";
        $publicDisk->makeDirectory($outputDir);
        $outputs = [];

        foreach ($formats as $key => [$width, $height, $label]) {
            $scaledClips = [];
            foreach ($localClips as $index => $clip) {
                $scaledPath = "{$workDir}/scaled_{$key}_{$index}.mp4";
                $videoFilter = "scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},setsar=1";
                if ($clipSpeedFactor > 1.0) {
                    $videoFilter .= ',setpts=' . number_format($clipSpeedFactor, 6, '.', '') . '*PTS';
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

            $listFile = "{$workDir}/concat_{$key}.txt";
            file_put_contents($listFile, implode("\n", array_map(
                fn (string $path) => "file '" . str_replace("'", "'\\''", str_replace('\\', '/', $path)) . "'",
                $scaledClips
            )));

            $finalRelativePath = "{$outputDir}/reprophotos_{$key}.mp4";
            $finalAbsolutePath = $publicDisk->path($finalRelativePath);
            $this->ffmpeg([
                '-y',
                '-f', 'concat',
                '-safe', '0',
                '-i', $listFile,
                '-c', 'copy',
                $finalAbsolutePath,
            ]);

            $outputs[$key] = [
                'label' => $label,
                'url' => $publicDisk->url($finalRelativePath),
            ];
        }

        $this->removeDirectory($workDir);

        return $outputs;
    }

    private function fakeClipFromImage(string $imagePath): string
    {
        $clipPath = sys_get_temp_dir() . '/listing-video-' . uniqid('', true) . '.mp4';
        $this->ffmpeg([
            '-y',
            '-loop', '1',
            '-i', $imagePath,
            '-vf', "zoompan=z='min(zoom+0.001,1.15)':d=150:s=1920x1080:fps=30,format=yuv420p",
            '-t', (string) self::NATIVE_CLIP_SECONDS,
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-pix_fmt', 'yuv420p',
            '-an',
            $clipPath,
        ]);

        return $clipPath;
    }

    private function ffmpeg(array $args): void
    {
        $process = new Process(array_merge(['ffmpeg'], $args));
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('ffmpeg failed: ' . substr($process->getErrorOutput(), -600));
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
