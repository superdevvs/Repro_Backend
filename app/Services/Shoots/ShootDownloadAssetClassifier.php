<?php

namespace App\Services\Shoots;

use App\Models\ShootFile;

/** Classify delivered assets independently from their upload/editing lane. */
class ShootDownloadAssetClassifier
{
    public function type(ShootFile $file): string
    {
        if ($this->isVideo($file)) return 'videos';
        if ($this->isFloorplan($file)) return 'floorplans';
        if ($this->isImage($file)) return 'photos';
        return 'other';
    }

    public function isPdf(ShootFile $file): bool
    {
        return $this->extension($file) === 'pdf' || str_contains($this->mime($file), 'pdf');
    }

    public function isVideo(ShootFile $file): bool
    {
        return strtolower((string) $file->media_type) === 'video'
            || str_starts_with($this->mime($file), 'video/')
            || in_array($this->extension($file), ['mp4', 'mov', 'm4v', 'avi', 'mkv', 'wmv', 'webm'], true);
    }

    public function isImage(ShootFile $file): bool
    {
        return str_starts_with($this->mime($file), 'image/')
            || in_array($this->extension($file), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'heic', 'heif'], true);
    }

    public function isFloorplan(ShootFile $file): bool
    {
        if ($this->isVideo($file) || (! $this->isImage($file) && ! $this->isPdf($file))) return false;
        $metadata = is_array($file->metadata) ? $file->metadata : [];
        $assetKey = strtolower((string) ($metadata['iguide_asset_key'] ?? $metadata['cubicasa_asset_key'] ?? $metadata['asset_key'] ?? $metadata['provider_asset_key'] ?? ''));
        if (str_starts_with($assetKey, 'pdf_home_report_')) return false;
        if (preg_match('/floor[ _-]?plan/i', (string) $file->media_type) || $this->isPdf($file)) return true;
        if (in_array(strtolower((string) ($metadata['source'] ?? '')), ['iguide', 'cubicasa'], true)
            || preg_match('/^(pdf|jpg)_/', $assetKey)) return true;

        $service = $file->serviceItem?->service;
        $description = ($service?->name ?? '').' '.($service?->category?->name ?? '');
        // Bundles such as Photos & Floor Plans also contain ordinary photographs.
        return (bool) preg_match('/floor[ _-]?plan|iguide|cubicasa/i', $description)
            && ! preg_match('/photo|hdr|video|drone/i', $description);
    }

    private function mime(ShootFile $file): string
    {
        return strtolower((string) ($file->file_type ?: $file->mime_type));
    }

    private function extension(ShootFile $file): string
    {
        $name = (string) ($file->filename ?: $file->stored_filename ?: $file->path);
        return strtolower(pathinfo(parse_url($name, PHP_URL_PATH) ?: $name, PATHINFO_EXTENSION));
    }
}
