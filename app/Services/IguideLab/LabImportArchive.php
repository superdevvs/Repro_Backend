<?php

namespace App\Services\IguideLab;

use App\Services\IguideOfflinePackageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class LabImportArchive
{
    public const MAX_PANORAMAS = 80;
    public const MAX_IMAGE_BYTES = 30 * 1024 * 1024;

    /** Inventory first; no case or archive-controlled path is written here. */
    public function inventory(string $path, ?string $externalMetadata = null): array
    {
        $inspection = app(IguideOfflinePackageService::class)->inspect(new UploadedFile($path, 'scan.zip', 'application/zip', null, true), false);
        $zip = new ZipArchive; $zip->open($path, ZipArchive::CHECKCONS);
        try {
            $paths = []; $metadataFiles = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i); $name = $stat['name'];
                if (str_ends_with($name, '/')) continue;
                $paths[$name] = $stat;
                if (in_array(strtolower(basename($name)), ['panoramas.json', 'view-data.json', 'index.html'], true)) $metadataFiles[] = $name;
            }
            $metadata = null; $metadataHash = null;
            if ($externalMetadata !== null) {
                $metadata = app(LabImportMetadata::class)->parse($externalMetadata); $metadataHash = hash('sha256', $externalMetadata);
            } elseif ($metadataFiles) {
                // Never silently choose between multiple manifests or viewer roots.
                $this->require(count($metadataFiles) === 1, 'Provide one unambiguous panorama manifest or viewer index.');
                $bytes = $this->read($zip, $metadataFiles[0], LabImportMetadata::MAX_BYTES);
                $metadata = app(LabImportMetadata::class)->parse($bytes); $metadataHash = hash('sha256', $bytes);
            }
            $sphereNames = array_values(array_filter(array_keys($paths), fn ($name) => preg_match('~(?:^|/)spheres/p([0-9]+)-s\.(?:jpg|jpeg|png|webp)$~iD', $name)));
            $candidates = $metadata && $metadata['kind'] === 'generic_manifest' ? array_keys($metadata['byFile']) : ($sphereNames ?: array_values(array_filter(array_keys($paths), fn ($name) => preg_match('/\.(jpg|jpeg|png|webp)$/iD', $name))));
            if ($metadata && $metadata['kind'] === 'iguide_viewObject') $this->require(count($sphereNames) > 0, 'This iGUIDE package does not contain supported original full-sphere files. Tiled or script-only exports are unsupported.');
            $this->require(count($candidates) > 0 && count($candidates) <= self::MAX_PANORAMAS, 'Use an archive containing 1 to 80 full panoramas.');
            $sources = []; $seenPanos = [];
            foreach ($candidates as $name) {
                $this->require(isset($paths[$name]), 'A manifest image is missing from the archive.');
                $bytes = $this->read($zip, $name, self::MAX_IMAGE_BYTES); $size = @getimagesizefromstring($bytes);
                $this->require($size && in_array($size['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
                    && $size[0] >= 2048 && $size[1] >= 1024 && $size[0] === 2 * $size[1] && $size[0] * $size[1] <= 64_000_000,
                    'Each source must be a full 2:1 JPG, PNG or WebP panorama from 2048×1024 to 64 megapixels. Tiles and ordinary photos are unsupported.');
                $this->require(! ($size['mime'] === 'image/png' && str_contains(substr($bytes, 0, 1024), 'acTL'))
                    && ! ($size['mime'] === 'image/webp' && str_contains(substr($bytes, 0, 1024), 'ANIM')), 'Animated source images are unsupported.');
                app(LabImportImage::class)->validate($bytes, $size);
                $pano = null;
                if (preg_match('~(?:^|/)spheres/p([0-9]+)-s\.(?:jpg|jpeg|png|webp)$~iD', $name, $m)) $pano = $m[1];
                $details = $metadata['byFile'][$name] ?? ($pano !== null ? ($metadata['byPano'][$pano] ?? null) : null);
                if ($metadata && $metadata['kind'] === 'iguide_viewObject') $this->require($details !== null, 'An exported sphere cannot be matched to its iGUIDE metadata.');
                $details ??= ['panoId' => $pano, 'roomId' => null, 'roomName' => null, 'floorId' => null, 'floorName' => null,
                    'buildingId' => null, 'camera' => [], 'authority' => $pano !== null ? 'iguide_export_filename' : 'unassigned'];
                if ($details['panoId'] !== null) {
                    $key = ($details['buildingId'] ?? '').'/'.($details['floorId'] ?? '').'/'.$details['panoId'];
                    $this->require(! isset($seenPanos[$key]), 'The source contains duplicate panorama identities.'); $seenPanos[$key] = true;
                }
                $sources[] = $details + ['entry' => $name, 'sha256' => hash('sha256', $bytes), 'sizeBytes' => strlen($bytes),
                    'width' => $size[0], 'height' => $size[1], 'mime' => $size['mime']];
                unset($bytes);
            }
            return ['format' => $metadata['kind'] ?? ($sphereNames ? 'iguide_spheres' : 'generic_360_zip'), 'inspection' => $inspection,
                'metadataSha256' => $metadataHash, 'tourId' => $metadata['tourId'] ?? null, 'alias' => $metadata['alias'] ?? null,
                'propertyName' => $metadata['propertyName'] ?? null, 'sources' => $sources,
                'warnings' => collect($sources)->contains(fn ($source) => $source['roomId'] === null) ? ['Some panoramas have no authoritative room assignment.'] : []];
        } finally { $zip->close(); }
    }

    public function read(ZipArchive $zip, string $name, int $max): string
    {
        $stat = $zip->statName($name);
        $this->require($stat && $stat['size'] > 0 && $stat['size'] <= $max, 'An import entry exceeds its bounded size.');
        $stream = $zip->getStream($name); $this->require(is_resource($stream), 'An import entry cannot be read.');
        try { $bytes = stream_get_contents($stream, $max + 1); }
        finally { fclose($stream); }
        $this->require(is_string($bytes) && strlen($bytes) === $stat['size'] && strlen($bytes) <= $max && sprintf('%u', crc32($bytes)) === sprintf('%u', $stat['crc']), 'An import entry is truncated or corrupt.');
        return $bytes;
    }
    private function require(bool $condition, string $message): void { if (! $condition) throw ValidationException::withMessages(['source' => $message]); }
}
