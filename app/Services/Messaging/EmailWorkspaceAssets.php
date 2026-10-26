<?php

namespace App\Services\Messaging;

use App\Services\LinkPreview\ImageSourceLoader;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class EmailWorkspaceAssets
{
    public function create(int $userId, string $bytes, string $name, array $options = [], string $source = 'uploaded'): object
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'], ['image/jpeg','image/png','image/webp'], true) || strlen($bytes) > 10 * 1024 * 1024 || $info[0] * $info[1] > 40000000) {
            throw ValidationException::withMessages(['file' => 'Choose a JPEG, PNG or WebP image below 10 MB and 40 megapixels.']);
        }
        $id = (string) Str::uuid();
        $path = 'email-workspace/originals/'.$userId.'/'.$id;
        Storage::disk('local')->put($path, $bytes);
        LockedWrite::run(fn () => DB::table('email_template_assets')->insert([
            'id' => $id, 'user_id' => $userId, 'name' => mb_substr($name, 0, 255), 'source' => $source,
            'artwork_id' => $source === 'generated' ? ($options['artwork_id'] ?? null) : null, 'status' => 'processing', 'original_path' => $path, 'original_bytes' => strlen($bytes),
            'width' => $info[0], 'height' => $info[1], 'options' => json_encode($options), 'created_at' => now(), 'updated_at' => now(),
        ]));
        if ($source === 'generated') $this->process(DB::table('email_template_assets')->find($id));
        else \App\Jobs\ProcessEmailTemplateAsset::dispatch($id);
        return DB::table('email_template_assets')->find($id);
    }

    public function process(object $row): void
    {
        $options = json_decode($row->options ?? '{}', true);
        $bytes = Storage::disk('local')->get($row->original_path);
        $image = (new ImageManager(new Driver))->read($bytes)->orient();
        $mime = @getimagesizefromstring($bytes)['mime'] ?? '';
        $png = $row->source === 'generated' || $mime === 'image/png';
        if ($mime === 'image/webp') {
            $pixels = $image->core()->native();
            for ($y = 0; $y < $image->height(); $y++) for ($x = 0; $x < $image->width(); $x++) {
                if (((imagecolorat($pixels, $x, $y) >> 24) & 127) > 0) { $png = true; break 2; }
            }
        }
        if (! empty($options['crop'])) {
            [$w, $h] = array_map('intval', explode(':', $options['crop']));
            $width = min($image->width(), (int) floor($image->height() * $w / $h));
            $height = (int) floor($width * $h / $w);
            $x = (int) floor(($image->width() - $width) * ($options['focal_x'] ?? 50) / 100);
            $y = (int) floor(($image->height() - $height) * ($options['focal_y'] ?? 50) / 100);
            $image->crop($width, $height, $x, $y);
        }
        $bound = match ($options['slot'] ?? 'content') { 'icon' => 320, 'header' => 1600, default => 1200 };
        $image->scaleDown(width: $bound, height: $bound);
        if ($options['play'] ?? false) {
            $raw = $image->core()->native();
            $cx = (int) ($image->width() / 2); $cy = (int) ($image->height() / 2);
            $r = max(12, (int) ($image->width() / 18));
            imagefilledellipse($raw, $cx, $cy, $r * 3, $r * 3, imagecolorallocatealpha($raw, 9, 34, 60, 20));
            imagefilledpolygon($raw, [$cx - (int) ($r / 2), $cy - $r, $cx - (int) ($r / 2), $cy + $r, $cx + $r, $cy], imagecolorallocate($raw, 255, 255, 255));
        }
        $encoded = $png ? $image->toPng() : $image->toJpeg(quality: ($options['quality'] ?? 'balanced') === 'high' ? 92 : 82, progressive: true, strip: true);
        $path = 'email-template-assets/'.$row->id.'.'.($png ? 'png' : 'jpg');
        if (! Storage::disk('public')->put($path, (string) $encoded)) throw new \RuntimeException('Could not publish the email image.');
        LockedWrite::run(fn () => DB::table('email_template_assets')->where('id', $row->id)->update([
            'status' => 'ready', 'path' => $path, 'mime' => $png ? 'image/png' : 'image/jpeg', 'bytes' => strlen((string) $encoded),
            'width' => $image->width(), 'height' => $image->height(), 'error' => null, 'updated_at' => now(),
        ]));
    }

    public function importUrl(int $userId, string $url, string $name, array $options = [], string $source = 'uploaded'): object
    {
        // Only URLs already resolved through an authorized dashboard source or
        // a provider-specific thumbnail resolver may reach this method.
        $bytes = app(ImageSourceLoader::class)->load($url);
        if (! $bytes) throw ValidationException::withMessages(['image' => 'This image could not be imported. Upload a thumbnail instead.']);
        return $this->create($userId, $bytes, $name, $options, $source);
    }

    public static function publicUrl(string $path): string
    {
        $url = Storage::disk('public')->url($path);
        return Str::startsWith($url, ['http://','https://']) ? $url : rtrim(config('app.url'), '/').'/'.ltrim($url, '/');
    }

    public function data(object $row): array
    {
        $stale = in_array($row->status, ['processing','optimizing'], true) && now()->diffInSeconds(\Carbon\Carbon::parse($row->updated_at), true) > 300;
        $size = $row->bytes;
        if (! $size && $row->path) { try { $size = Storage::disk('public')->size($row->path); } catch (\Throwable) { $size = 0; } }
        return ['id' => $row->id, 'name' => $row->name, 'source' => $row->source, 'status' => $stale ? 'failed' : ($row->status === 'optimizing' ? 'processing' : $row->status),
            'url' => $row->path ? self::publicUrl($row->path) : null, 'mime' => $row->mime,
            'legacy' => ! $row->original_path, 'original_bytes' => $row->original_bytes ?: $size, 'bytes' => $size, 'width' => $row->width, 'height' => $row->height,
            'error' => $stale ? 'Image processing could not be confirmed. Create another rendition or upload again.' : $row->error, 'archived' => (bool) $row->archived];
    }
}
