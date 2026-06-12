<?php

namespace App\Services;

use App\Models\FeaturedShootImage;
use App\Models\Shoot;
use App\Models\ShootFile;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FeaturedShootPayloadService
{
    public function payload(): ?array
    {
        $shoot = Shoot::query()
            ->with(['featuredHomepageImages.file'])
            ->where('is_featured', true)
            ->latest('updated_at')
            ->first();

        if (!$shoot) {
            return null;
        }

        $images = $shoot->featuredHomepageImages
            ->filter(fn (FeaturedShootImage $image) => $this->isUsableImage($image->file))
            ->sortBy('sort_order')
            ->values()
            ->map(fn (FeaturedShootImage $image) => $this->serializeImage($image, $shoot))
            ->filter()
            ->values()
            ->all();

        if (empty($images)) {
            return null;
        }

        return [
            'id' => 'shoot_' . $shoot->id,
            'title' => $shoot->featured_homepage_title ?: $this->defaultTitle($shoot),
            'location' => $shoot->featured_homepage_location ?: $this->defaultLocation($shoot),
            'subtitle' => $shoot->featured_homepage_subtitle,
            'updated_at' => $this->updatedAt($shoot)?->toIso8601String(),
            'images' => $images,
            'cta' => [
                'label' => $shoot->featured_homepage_cta_label ?: 'See the shoot',
                'href' => $shoot->featured_homepage_cta_href ?: $this->defaultCtaHref($shoot),
            ],
        ];
    }

    protected function serializeImage(FeaturedShootImage $image, Shoot $shoot): ?array
    {
        $file = $image->file;
        if (!$file) {
            return null;
        }

        $srcset = [
            '640' => $this->resolvePublicUrl($image->variant_640_path ?: $file->thumbnail_path ?: $file->web_path ?: $file->path ?: $file->storage_path),
            '1280' => $this->resolvePublicUrl($image->variant_1280_path ?: $file->web_path ?: $file->thumbnail_path ?: $file->path ?: $file->storage_path),
            '1920' => $this->resolvePublicUrl($image->variant_1920_path ?: $file->storage_path ?: $file->path ?: $file->web_path ?: $file->thumbnail_path),
        ];

        $srcset = array_filter($srcset);
        $url = $srcset['1920'] ?? $srcset['1280'] ?? $srcset['640'] ?? null;
        if (!$url) {
            return null;
        }

        return [
            'url' => $url,
            'srcset' => $srcset,
            'width' => $image->width ?: $this->metadataInt($file, 'width') ?: 1920,
            'height' => $image->height ?: $this->metadataInt($file, 'height') ?: 1080,
            'alt' => $image->alt_text ?: $this->defaultAlt($file, $shoot),
            'focal' => $image->focal_point ?: '50% 50%',
            'sort' => (int) $image->sort_order,
        ];
    }

    protected function isUsableImage(?ShootFile $file): bool
    {
        if (!$file || (bool) ($file->is_hidden ?? false) || $file->isBlockedFromDelivery()) {
            return false;
        }

        if (!in_array((string) $file->workflow_stage, [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED], true)) {
            return false;
        }

        $mime = strtolower((string) ($file->mime_type ?? $file->file_type ?? ''));
        if ($mime && Str::startsWith($mime, 'image/')) {
            return true;
        }

        $filename = strtolower((string) ($file->filename ?? $file->stored_filename ?? $file->path ?? $file->storage_path ?? ''));

        return (bool) preg_match('/\.(jpg|jpeg|png|webp)$/i', $filename);
    }

    protected function resolvePublicUrl(?string $path): ?string
    {
        if (!$path || trim($path) === '') {
            return null;
        }

        $path = trim($path);
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $clean = ltrim($path, '/');
        if (Str::startsWith($clean, 'storage/')) {
            $clean = Str::after($clean, 'storage/');
        }

        if (Storage::disk('public')->exists($clean)) {
            return Storage::disk('public')->url($clean);
        }

        if (Str::startsWith($path, ['/storage/', 'storage/'])) {
            return url('/' . ltrim($path, '/'));
        }

        return null;
    }

    protected function updatedAt(Shoot $shoot): ?CarbonInterface
    {
        $dates = collect([$shoot->updated_at]);

        $shoot->featuredHomepageImages->each(function (FeaturedShootImage $image) use ($dates) {
            $dates->push($image->updated_at);
            if ($image->file?->updated_at) {
                $dates->push($image->file->updated_at);
            }
        });

        return $dates->filter()->max();
    }

    protected function defaultTitle(Shoot $shoot): string
    {
        return trim((string) ($shoot->address ?: 'Featured Shoot'));
    }

    protected function defaultLocation(Shoot $shoot): string
    {
        return collect([$shoot->city, $shoot->state])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->implode(', ');
    }

    protected function defaultCtaHref(Shoot $shoot): string
    {
        $slug = trim((string) ($shoot->property_slug ?: ''));

        return $slug !== '' ? '/projects/' . $slug : '/projects/shoot-' . $shoot->id;
    }

    protected function defaultAlt(ShootFile $file, Shoot $shoot): string
    {
        return trim((string) ($file->filename ?: $shoot->address ?: 'Featured shoot image'));
    }

    protected function metadataInt(ShootFile $file, string $key): ?int
    {
        $metadata = is_array($file->metadata) ? $file->metadata : [];
        $value = $metadata[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
