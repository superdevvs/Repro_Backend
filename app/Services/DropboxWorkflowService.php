<?php

namespace App\Services;

/** @deprecated Compatibility for old integrations and queued consumers; no provider access. */
class DropboxWorkflowService extends ShootMediaStorageService
{
    public function __construct($unusedLegacyTokenService = null, ?RawThumbnailService $rawThumbnailService = null)
    {
        parent::__construct($rawThumbnailService);
    }

    public function isEnabled(): bool { return false; }
    public function getTemporaryLink(?string $path): ?string { return null; }
    public function downloadToTemp(?string $path): ?string { return null; }
    public function uploadFromPath(string $localPath, string $path): ?string { return null; }
    public function downloadFile(string $path): ?string { return null; }
    public function downloadFileContent(string $path): ?string { return null; }
    public function listShootFiles(\App\Models\Shoot $shoot, string $type): array { return []; }
    public function listFolderFiles($path): array { return []; }
    public function getDropboxZipLink(string $path): ?string { return null; }
    public function createSharedLink(string $path, int $expiresInHours = 72): ?string { return null; }
    public function generateZipOnFly(\App\Models\Shoot $shoot, string $type): ?string { return null; }
    public function archiveShoot(\App\Models\Shoot $shoot, $userId = null): bool { return false; }
    public function testConnection(): array { return ['success' => false, 'message' => 'Dropbox has been retired.']; }
    public function healthCheck(?string $probePath = null, ?string $probeFolder = null): array { return ['overall_success' => false, 'enabled' => false, 'steps' => []]; }
}
