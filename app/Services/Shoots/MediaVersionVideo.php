<?php

namespace App\Services\Shoots;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;

/** Validate a saved replacement without decoding it as an image or rewriting its bytes. */
class MediaVersionVideo
{
    public static function extension(string $mime): ?string
    {
        return ['video/mp4' => 'mp4', 'application/mp4' => 'mp4', 'video/quicktime' => 'mov',
            'video/webm' => 'webm', 'video/x-matroska' => 'mkv', 'video/x-msvideo' => 'avi',
            'video/x-ms-wmv' => 'wmv', 'video/mpeg' => 'mpeg'][$mime] ?? null;
    }

    public function inspect(string $path): array
    {
        // Match ordinary video intake's byte-derived MIME boundary. ffprobe is
        // optional on existing workers; its absence must not strand replacements.
        $mime = app(UploadIntakeResolver::class)->detectedMimeType(new \Illuminate\Http\UploadedFile($path, basename($path), null, null, true));
        if (! self::extension((string) $mime)) throw new \RuntimeException('The saved replacement is not a supported video.');
        $binary = (new ExecutableFinder)->find(config('studio.ffprobe_path', 'ffprobe'));
        if (! $binary) return ['validation' => 'mime_and_scan'];
        $probe = new Process([$binary, '-v', 'error', '-protocol_whitelist', 'file,pipe',
            '-show_entries', 'format=duration:stream=codec_type,width,height', '-of', 'json', $path]);
        $probe->setTimeout(60)->mustRun();
        $info = json_decode($probe->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $video = collect($info['streams'] ?? [])->firstWhere('codec_type', 'video');
        $duration = (float) ($info['format']['duration'] ?? 0);
        if (! $video || (int) ($video['width'] ?? 0) < 1 || (int) ($video['height'] ?? 0) < 1 || ! is_finite($duration) || $duration <= 0) {
            throw new \RuntimeException('The replacement must contain a readable video stream with a positive duration.');
        }
        return ['validation' => 'probe', 'width' => (int) $video['width'], 'height' => (int) $video['height'], 'duration' => $duration];
    }
}
