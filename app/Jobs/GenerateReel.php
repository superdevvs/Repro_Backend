<?php

namespace App\Jobs;

use App\Models\AiReelJob;
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

class GenerateReel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;
    public int $tries = 1;

    private const NATIVE_CLIP_SECONDS = 5;
    private const REEL_TARGET_SECONDS = 30;

    public function __construct(public int $jobId)
    {
    }

    public function handle(FalService $fal, ShootFileAccessService $fileAccess): void
    {
        $job = AiReelJob::findOrFail($this->jobId);

        if ($job->status === AiReelJob::STATUS_CANCELLED) {
            return;
        }

        $tempImages = [];
        $tempClips = [];

        try {
            $job->markAsProcessing();

            $prompt = 'Energetic, dynamic short-form vertical reel motion. Smooth camera movement showcasing the space, photorealistic, natural lighting, no distortion, social-media ready property highlight.';
            $clipSources = [];
            $requestIds = [];

            if (config('services.fal.test_mode')) {
                foreach ($job->selected_file_ids as $fileId) {
                    $this->stopIfCancelled($job);
                    $imagePath = $this->resolveImagePath((int) $fileId, $fileAccess, $tempImages);
                    $clipPath = $this->fakeClipFromImage($imagePath);
                    $tempClips[] = $clipPath;
                    $clipSources[] = $clipPath;
                    sleep(1);
                }
            } else {
                foreach ($job->selected_file_ids as $fileId) {
                    $this->stopIfCancelled($job);
                    $imagePath = $this->resolveImagePath((int) $fileId, $fileAccess, $tempImages);
                    $bytes = file_get_contents($imagePath);
                    if ($bytes === false) {
                        throw new RuntimeException("Could not read image {$fileId}.");
                    }
                    $mime = $this->sniffMime($imagePath);
                    $hostedImageUrl = $fal->uploadImage($bytes, $mime);
                    $requestIds[] = $fal->submit($hostedImageUrl, $prompt);
                }

                foreach ($requestIds as $requestId) {
                    while (true) {
                        $this->stopIfCancelled($job);
                        $status = $fal->status($requestId);

                        if ($status === 'COMPLETED') {
                            $clipSources[] = $fal->result($requestId);
                            break;
                        }

                        if (in_array($status, ['FAILED', 'ERROR'], true)) {
                            throw new RuntimeException("A reel clip failed to generate ({$requestId}).");
                        }

                        sleep(8);
                    }
                }
            }

            $this->stopIfCancelled($job);
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

    private function resolveImagePath(int $fileId, ShootFileAccessService $fileAccess, array &$tempImages): string
    {
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

        $secondsPerClip = self::REEL_TARGET_SECONDS / max(1, count($localClips));
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

        $listFile = "{$workDir}/concat.txt";
        file_put_contents($listFile, implode("\n", array_map(
            fn (string $path) => "file '" . str_replace("'", "'\\''", str_replace('\\', '/', $path)) . "'",
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
        $clipPath = sys_get_temp_dir() . '/reel-' . uniqid('', true) . '.mp4';
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
