<?php

namespace App\Services\Shoots;

use App\Jobs\GenerateShootMediaArchiveJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ShootMediaArchiveService
{
    private const ARCHIVE_DISK = 'public';
    private const LOCK_TTL_SECONDS = 600;
    public const POLL_AFTER_MS = 3000;
    private const DEFAULT_FRONTEND_URL = 'https://reprodashboard.com';
    private const DEFAULT_API_URL = 'https://api.reprodashboard.com';

    public function __construct(
        protected DropboxWorkflowService $dropboxService,
        protected ShootFileAccessService $shootFileAccessService,
        protected ShootAuthorizationSupport $shootAuthorizationSupport,
        protected DeliveryMediaOrderService $deliveryMediaOrderService,
        protected DeliveryFilenameFormatter $deliveryFilenameFormatter
    ) {
    }

    public function normalizeSize(?string $size): string
    {
        return strtolower((string) $size) === 'small' ? 'small' : 'original';
    }

    public function resolveArchiveResponseData(
        Shoot $shoot,
        string $type,
        string $size,
        string $statusUrl,
        ?int $shootServiceId = null
    ): array {
        $type = $this->canonicalizeType($type);
        $size = $this->normalizeSize($size);

        if (!$this->hasDownloadableFiles($shoot, $type, $size, $shootServiceId)) {
            throw new \RuntimeException('No downloadable files available');
        }

        if ($this->hasFreshArchive($shoot, $type, $size, $shootServiceId)) {
            return [
                'status' => 200,
                'payload' => [
                    'type' => 'redirect',
                    'url' => $this->getArchiveUrl($shoot, $type, $size, $shootServiceId),
                ],
            ];
        }

        $this->queueArchiveGeneration($shoot, $type, $size, $shootServiceId);

        return [
            'status' => 202,
            'payload' => [
                'type' => 'preparing',
                'message' => $this->buildPreparingMessage($size),
                'poll_after_ms' => self::POLL_AFTER_MS,
                'status_url' => $statusUrl,
            ],
        ];
    }

    public function queueArchiveGeneration(Shoot $shoot, string $type, string $size, ?int $shootServiceId = null): bool
    {
        $type = $this->canonicalizeType($type);
        $size = $this->normalizeSize($size);

        if (!$this->hasDownloadableFiles($shoot, $type, $size, $shootServiceId)) {
            return false;
        }

        if ($this->hasFreshArchive($shoot, $type, $size, $shootServiceId)) {
            return false;
        }

        if (!$this->acquireGenerationLock($shoot, $type, $size, $shootServiceId)) {
            return false;
        }

        GenerateShootMediaArchiveJob::dispatch($shoot->id, $type, $size, $shootServiceId);

        return true;
    }

    public function generateArchive(
        Shoot $shoot,
        string $type,
        string $size,
        bool $lockAlreadyHeld = false,
        ?int $shootServiceId = null
    ): array {
        $type = $this->canonicalizeType($type);
        $size = $this->normalizeSize($size);

        $lockAcquiredHere = false;
        if (!$lockAlreadyHeld) {
            $lockAcquiredHere = $this->acquireGenerationLock($shoot, $type, $size, $shootServiceId);

            if (!$lockAcquiredHere) {
                return $this->readManifest($shoot, $type, $size, $shootServiceId) ?? [];
            }
        }

        $plan = [
            'entries' => [],
            'source_signature' => null,
        ];

        try {
            $plan = $this->buildArchivePlan($shoot, $type, $size, $shootServiceId);
            if ($plan['entries'] === []) {
                $this->writeManifest($shoot, $type, $size, [
                    'type' => $type,
                    'size' => $size,
                    'shoot_service_id' => $shootServiceId,
                    'source_signature' => null,
                    'generated_at' => null,
                    'file_count' => 0,
                    'last_error' => 'No downloadable files available',
                ], $shootServiceId);

                throw new \RuntimeException('No downloadable files available');
            }

            $manifest = $this->readManifest($shoot, $type, $size, $shootServiceId);
            if (
                is_array($manifest)
                && ($manifest['source_signature'] ?? null) === $plan['source_signature']
                && empty($manifest['last_error'])
                && $this->archiveExists($shoot, $type, $size, $shootServiceId)
            ) {
                return $manifest;
            }

            $archivePath = $this->getArchivePath($shoot, $type, $size, $shootServiceId);
            Storage::disk(self::ARCHIVE_DISK)->makeDirectory(dirname($archivePath));

            $zipAbsolutePath = Storage::disk(self::ARCHIVE_DISK)->path($archivePath);

            // ZipArchive writes through the native filesystem, not the Storage
            // abstraction, and fails outright if the parent directory is missing.
            // The makeDirectory() above covers the disk's own view, but that is
            // not always the same thing (custom roots, a disk that reports an
            // ancestor as present, or a directory removed between the two calls).
            // mkdir(recursive) is idempotent, so asserting the real path here is
            // free insurance against an archive build failing on a missing folder.
            $zipDirectory = dirname($zipAbsolutePath);
            if (!is_dir($zipDirectory)) {
                @mkdir($zipDirectory, 0775, true);
            }

            $zip = new \ZipArchive();
            if ($zip->open($zipAbsolutePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Failed to create ZIP file');
            }

            $addedFiles = 0;
            $tempFiles = [];

            try {
                foreach ($plan['entries'] as $entry) {
                    $localPath = $this->resolveArchiveEntryLocalPath($entry);
                    if (!$localPath || !file_exists($localPath)) {
                        continue;
                    }

                    $zip->addFile($localPath, $entry['archive_name']);
                    $addedFiles++;

                    if (!empty($entry['temp_path'])) {
                        $tempFiles[] = $entry['temp_path'];
                    }
                }
            } finally {
                $zip->close();

                foreach ($tempFiles as $tempFile) {
                    @unlink($tempFile);
                }
            }

            if ($addedFiles === 0) {
                Storage::disk(self::ARCHIVE_DISK)->delete($archivePath);
                throw new \RuntimeException('No downloadable files available');
            }

            $manifest = [
                'type' => $type,
                'size' => $size,
                'shoot_service_id' => $shootServiceId,
                'source_signature' => $plan['source_signature'],
                'generated_at' => now()->toIso8601String(),
                'file_count' => $addedFiles,
                // Recorded so a support question about "why is the client's ZIP
                // in this order" can be answered without rebuilding the archive.
                'media_order_version' => $this->deliveryMediaOrderService->currentVersion($shoot),
                'entry_names' => array_column($plan['entries'], 'archive_name'),
                'last_error' => null,
            ];

            $this->writeManifest($shoot, $type, $size, $manifest, $shootServiceId);

            return $manifest;
        } catch (\Throwable $exception) {
            $this->writeManifest($shoot, $type, $size, [
                'type' => $type,
                'size' => $size,
                'shoot_service_id' => $shootServiceId,
                'source_signature' => $plan['source_signature'],
                'generated_at' => now()->toIso8601String(),
                'file_count' => 0,
                'last_error' => $exception->getMessage(),
            ], $shootServiceId);

            Log::warning('Shoot media archive generation failed', [
                'shoot_id' => $shoot->id,
                'shoot_service_id' => $shootServiceId,
                'type' => $type,
                'size' => $size,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            if ($lockAlreadyHeld || $lockAcquiredHere) {
                $this->releaseGenerationLock($shoot, $type, $size, $shootServiceId);
            }
        }
    }

    public function hasFreshArchive(Shoot $shoot, string $type, string $size, ?int $shootServiceId = null): bool
    {
        $type = $this->canonicalizeType($type);
        $size = $this->normalizeSize($size);

        if (!$this->archiveExists($shoot, $type, $size, $shootServiceId)) {
            return false;
        }

        $manifest = $this->readManifest($shoot, $type, $size, $shootServiceId);
        if (!is_array($manifest) || !empty($manifest['last_error'])) {
            return false;
        }

        $plan = $this->buildArchivePlan($shoot, $type, $size, $shootServiceId);

        return $plan['entries'] !== []
            && ($manifest['source_signature'] ?? null) === $plan['source_signature'];
    }

    public function hasDownloadableFiles(Shoot $shoot, string $type, string $size, ?int $shootServiceId = null): bool
    {
        return $this->buildArchivePlan($shoot, $type, $size, $shootServiceId)['entries'] !== [];
    }

    public function getArchivePath(Shoot $shoot, string $type, string $size, ?int $shootServiceId = null): string
    {
        $typeToken = $this->typeToken($type);
        $size = $this->normalizeSize($size);
        $scope = $shootServiceId ? "service-{$shootServiceId}/" : '';
        $slug = $this->buildArchiveFilenameSlug($shoot);

        return "shoots/{$shoot->id}/archives/{$scope}{$slug}-{$typeToken}-{$size}.zip";
    }

    public function getManifestPath(Shoot $shoot, string $type, string $size, ?int $shootServiceId = null): string
    {
        $typeToken = $this->typeToken($type);
        $size = $this->normalizeSize($size);
        $scope = $shootServiceId ? "service-{$shootServiceId}/" : '';
        $slug = $this->buildArchiveFilenameSlug($shoot);

        return "shoots/{$shoot->id}/archives/{$scope}{$slug}-{$typeToken}-{$size}.json";
    }

    /**
     * Build a URL-safe slug from the shoot's property address so generated archive
     * downloads carry a human-readable filename (e.g., "123-main-st-mclean-va").
     */
    public function buildArchiveFilenameSlug(Shoot $shoot): string
    {
        $parts = array_filter([
            $shoot->address,
            $shoot->city,
            $shoot->state,
            $shoot->zip,
        ], fn ($value) => is_string($value) ? trim($value) !== '' : !empty($value));

        $candidate = trim((string) implode(' ', array_map('strval', $parts)));
        if ($candidate === '') {
            return "shoot-{$shoot->id}";
        }

        $slug = \Illuminate\Support\Str::slug($candidate, '-');

        return $slug !== '' ? $slug : "shoot-{$shoot->id}";
    }

    public function getArchiveUrl(Shoot $shoot, string $type, string $size, ?int $shootServiceId = null): string
    {
        $archivePath = $this->getArchivePath($shoot, $type, $size, $shootServiceId);
        $diskUrl = Storage::disk(self::ARCHIVE_DISK)->url($archivePath);

        if (preg_match('/^https?:\/\//i', $diskUrl)) {
            return $diskUrl;
        }

        return $this->shootFileAccessService->resolvePublicStorageUrl($diskUrl) ?? $diskUrl;
    }

    public function buildPublicDownloadUrl(
        Shoot $shoot,
        string $type,
        string $size,
        ?\DateTimeInterface $expiresAt = null
    ): string {
        $signedUrl = $this->buildSignedStatusUrl(
            $shoot,
            $type,
            $size,
            $expiresAt
        );

        return rtrim($this->resolveFrontendUrl(), '/')
            . '/download/media?url='
            . rawurlencode($signedUrl);
    }

    public function buildSignedStatusUrl(
        Shoot $shoot,
        string $type,
        string $size,
        ?\DateTimeInterface $expiresAt = null
    ): string {
        return URL::temporarySignedRoute(
            'api.public.shoot-media.download',
            $expiresAt ?? now()->addDays(7),
            [
                'shoot' => $shoot->id,
                'type' => $this->normalizeType($type),
                'size' => $this->normalizeSize($size),
            ]
        );
    }

    public function getFilesForType(Shoot $shoot, string $type, ?int $shootServiceId = null): Collection
    {
        // Delivery order, not upload order. Previously this read
        // `sort_order asc, created_at desc`, which meant a shoot nobody had
        // manually arranged (every sort_order still 0) was packaged newest-first
        // — the reverse of what the admin sees in the media grid.
        $query = $shoot->files()->inDeliveryOrder();

        if ($shootServiceId !== null) {
            $query->where('shoot_service_id', $shootServiceId);
        }

        if ($this->normalizeType($type) === 'raw') {
            $query->where(function ($builder) {
                $builder->where('workflow_stage', ShootFile::STAGE_TODO)
                    ->orWhereNull('workflow_stage');
            });
        } else {
            $query->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED]);
        }

        $files = $query->get();

        // Infected files are never packaged for delivery (Req 15.7). Excluding
        // them here keeps every archive path consistent — buildArchivePlan,
        // hasDownloadableFiles, the authenticated ZIP download, and the public
        // share-link archive all funnel through getFilesForType. Legacy/unscanned
        // files (null status) and not-yet-clean files remain in the archive set so
        // existing delivery is not broken; only a positive infected verdict blocks.
        $files = $files
            ->reject(fn (ShootFile $file) => $file->isBlockedFromDelivery())
            ->reject(fn (ShootFile $file) => $file->isIguideOfflinePackage())
            ->values();

        if ($this->normalizeType($type) === 'raw') {
            $files = $files
                ->filter(fn (ShootFile $file) => $file->isRequiredForEditing())
                ->values();
        }

        if ($this->normalizeType($type) === 'edited') {
            $files = $files
                ->reject(fn (ShootFile $file) => $this->shootAuthorizationSupport->isRawCameraFile($file))
                ->values();
        }

        // Public archive filters: exclude un-ordered extras unless explicitly
        // requested, and optionally restrict to specific media types so a
        // per-tab "Download all" never packages media nobody ordered. Extras
        // flagged required_for_editing are still packaged (Req 4.8) — this
        // mirrors isRequiredForEditing() so the raw hand-off keeps the extras
        // an editor genuinely needs while dropping the optional ones.
        $filters = $this->parseTypeFilters($type);
        if (! $filters['include_extras']) {
            $files = $files
                ->reject(fn (ShootFile $file) => ! $file->isRequiredForEditing())
                ->values();
        }
        if (! empty($filters['media_types'])) {
            $allowed = $filters['media_types'];
            $files = $files
                ->filter(fn (ShootFile $file) => in_array((string) $file->media_type, $allowed, true))
                ->values();
        }

        // Replay the frozen delivery order last, after every access/scan/extras
        // filter above has run. Applying it here (rather than in the query) keeps
        // the snapshot from ever re-introducing a file those filters removed,
        // while still guaranteeing the ZIP, the Bright MLS manifest and the
        // Dropbox hand-off all sequence the same delivered set identically.
        return $this->deliveryMediaOrderService->applyTo($shoot, $files);
    }

    protected function buildArchivePlan(Shoot $shoot, string $type, string $size, ?int $shootServiceId = null): array
    {
        // Resolve every source path first so the numbering runs over the files
        // that will actually make it into the archive. Numbering before this
        // filter would leave gaps (001, 003, 004) whenever a file has no
        // resolvable source.
        $deliverable = [];
        foreach ($this->getFilesForType($shoot, $type, $shootServiceId) as $file) {
            $sourcePath = $this->resolveDownloadPath($file, $size);
            if (!$sourcePath) {
                continue;
            }

            $deliverable[] = ['file' => $file, 'source_path' => $sourcePath];
        }

        $total = count($deliverable);
        $entries = [];
        $usedNames = [];
        $position = 1;

        foreach ($deliverable as $item) {
            /** @var ShootFile $file */
            $file = $item['file'];

            // The entry name carries the position because nothing downstream
            // honors ZIP entry order — see DeliveryFilenameFormatter. The stored
            // master filename is untouched; only this delivered copy is renamed.
            $archiveName = $this->deliveryFilenameFormatter->deduplicate(
                $this->deliveryFilenameFormatter->formatForFile(
                    $file,
                    $position,
                    $total,
                    basename($item['source_path'])
                ),
                $usedNames
            );

            $entries[] = [
                'file_id' => $file->id,
                'shoot_service_id' => $file->shoot_service_id,
                'position' => $position,
                'archive_name' => $archiveName,
                'source_name' => $this->deliveryFilenameFormatter->baseNameFor($file, basename($item['source_path'])),
                'source_path' => $item['source_path'],
                'updated_at' => optional($file->updated_at)->toIso8601String(),
                'file_size' => $file->file_size,
                'temp_path' => null,
            ];

            $position++;
        }

        return [
            'entries' => $entries,
            'source_signature' => $entries === []
                ? null
                : $this->buildSourceSignature($entries, $this->deliveryMediaOrderService->orderFingerprint($shoot)),
        ];
    }

    protected function resolveDownloadPath(ShootFile $file, string $size): ?string
    {
        $candidates = $size === 'small'
            ? [
                $file->web_path,
                $file->thumbnail_path,
                $file->placeholder_path,
                $file->storage_path,
                $file->path,
                $file->dropbox_path,
            ]
            : [
                $file->storage_path,
                $file->path,
                $file->dropbox_path,
            ];

        foreach ($candidates as $candidate) {
            if (!$candidate) {
                continue;
            }

            if ($candidate === $file->dropbox_path) {
                if ($this->dropboxService->isEnabled()) {
                    return $candidate;
                }

                continue;
            }

            if ($this->shootFileAccessService->resolveLocalPath($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function resolveArchiveEntryLocalPath(array &$entry): ?string
    {
        $sourcePath = $entry['source_path'] ?? null;
        if (!is_string($sourcePath) || trim($sourcePath) === '') {
            return null;
        }

        $localPath = $this->shootFileAccessService->resolveLocalPath($sourcePath);
        if ($localPath) {
            return $localPath;
        }

        // Stage the Dropbox download under the master filename, not the
        // position-prefixed archive name — the prefix belongs to the delivered
        // ZIP entry only, and the temp file is keyed off the source.
        $filename = basename($entry['source_name'] ?? $entry['archive_name'] ?? 'file');
        $downloaded = $this->shootFileAccessService->downloadDropboxPathToTemp($sourcePath, $filename);
        if ($downloaded) {
            $entry['temp_path'] = $downloaded;
        }

        return $downloaded;
    }

    protected function readManifest(Shoot $shoot, string $type, string $size, ?int $shootServiceId = null): ?array
    {
        $manifestPath = $this->getManifestPath($shoot, $type, $size, $shootServiceId);
        if (!Storage::disk(self::ARCHIVE_DISK)->exists($manifestPath)) {
            return null;
        }

        try {
            $decoded = json_decode((string) Storage::disk(self::ARCHIVE_DISK)->get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    protected function writeManifest(Shoot $shoot, string $type, string $size, array $manifest, ?int $shootServiceId = null): void
    {
        Storage::disk(self::ARCHIVE_DISK)->put(
            $this->getManifestPath($shoot, $type, $size, $shootServiceId),
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    protected function archiveExists(Shoot $shoot, string $type, string $size, ?int $shootServiceId = null): bool
    {
        return Storage::disk(self::ARCHIVE_DISK)->exists($this->getArchivePath($shoot, $type, $size, $shootServiceId));
    }

    protected function buildPreparingMessage(string $size): string
    {
        return $size === 'small'
            ? 'Preparing your MLS files.'
            : 'Preparing your full-size files.';
    }

    /**
     * Fingerprint of everything that can change the bytes of the archive.
     *
     * Idempotency: two builds of the same delivered set at the same order produce
     * the same signature, so hasFreshArchive() short-circuits and the cached ZIP
     * is reused.
     *
     * Invalidation: `position` and the order fingerprint are both included, so a
     * reorder changes the signature and the cached ZIP is genuinely rebuilt.
     * Before positions existed the signature covered only file identity and
     * mtimes, which meant a pure reorder hashed identically — the observer
     * dispatched a regeneration, generateArchive() saw a matching signature and
     * returned the stale archive, so a re-sorted shoot kept serving the old
     * sequence.
     */
    protected function buildSourceSignature(array $entries, string $orderFingerprint = ''): string
    {
        return hash('sha256', json_encode([
            'order' => $orderFingerprint,
            'entries' => array_map(function (array $entry) {
                return [
                    'file_id' => $entry['file_id'],
                    'shoot_service_id' => $entry['shoot_service_id'] ?? null,
                    'position' => $entry['position'] ?? null,
                    'archive_name' => $entry['archive_name'],
                    'source_path' => $entry['source_path'],
                    'updated_at' => $entry['updated_at'],
                    'file_size' => $entry['file_size'],
                ];
            }, $entries),
        ], JSON_UNESCAPED_SLASHES));
    }

    protected function acquireGenerationLock(Shoot $shoot, string $type, string $size, ?int $shootServiceId = null): bool
    {
        return Cache::add(
            $this->getGenerationLockKey($shoot, $type, $size, $shootServiceId),
            now()->toIso8601String(),
            now()->addSeconds(self::LOCK_TTL_SECONDS)
        );
    }

    protected function releaseGenerationLock(Shoot $shoot, string $type, string $size, ?int $shootServiceId = null): void
    {
        Cache::forget($this->getGenerationLockKey($shoot, $type, $size, $shootServiceId));
    }

    protected function getGenerationLockKey(Shoot $shoot, string $type, string $size, ?int $shootServiceId = null): string
    {
        $scope = $shootServiceId ? ':service:' . $shootServiceId : '';

        return 'shoot-media-archive:' . $shoot->id . $scope . ':' . $this->typeToken($type) . ':' . $this->normalizeSize($size);
    }

    protected function normalizeType(?string $type): string
    {
        $base = explode('|', strtolower((string) $type), 2)[0];

        return $base === 'edited' ? 'edited' : 'raw';
    }

    /**
     * Public archive selectors (raw/edited) may carry a filter segment after a
     * pipe, e.g. "edited|we" (include extras) or "edited|we;mt=photos,video".
     * The plain token is the default delivery scope: extras are excluded unless
     * the caller explicitly opts in (Requirement 4.8 / task 9.1). canonicalizeType
     * sanitises the base while preserving the filter so it flows through the
     * async generation pipeline and cache keys unchanged.
     */
    public function canonicalizeType(?string $type): string
    {
        $filters = $this->parseTypeFilters($type);

        return $this->buildArchiveTypeToken(
            $this->normalizeType($type),
            $filters['include_extras'],
            $filters['media_types']
        );
    }

    /**
     * Build the canonical archive selector token from explicit filters. The
     * default scope excludes extras, so the plain token (e.g. "edited") is the
     * no-extras delivery archive that observers pre-warm and the endpoint uses
     * by default; extras are added only when explicitly requested via the "we"
     * (with-extras) segment. Media types are lower-cased and sorted so the same
     * selection always yields the same token (and the same cache key).
     */
    public function buildArchiveTypeToken(string $base, bool $includeExtras = false, array $mediaTypes = []): string
    {
        $token = $this->normalizeType($base);
        $segments = [];

        if ($includeExtras) {
            $segments[] = 'we';
        }

        $mediaTypes = array_values(array_unique(array_filter(
            array_map(fn ($value) => strtolower(trim((string) $value)), $mediaTypes),
            fn ($value) => $value !== ''
        )));
        if (! empty($mediaTypes)) {
            sort($mediaTypes);
            $segments[] = 'mt=' . implode(',', $mediaTypes);
        }

        return empty($segments) ? $token : $token . '|' . implode(';', $segments);
    }

    protected function parseTypeFilters(?string $type): array
    {
        $includeExtras = false;
        $mediaTypes = [];

        $parts = explode('|', (string) $type, 2);
        if (isset($parts[1]) && $parts[1] !== '') {
            foreach (explode(';', $parts[1]) as $segment) {
                $segment = trim($segment);
                if ($segment === 'we') {
                    $includeExtras = true;
                } elseif (str_starts_with($segment, 'mt=')) {
                    $mediaTypes = array_values(array_filter(
                        array_map('trim', explode(',', substr($segment, 3))),
                        fn ($value) => $value !== ''
                    ));
                }
            }
        }

        return ['include_extras' => $includeExtras, 'media_types' => $mediaTypes];
    }

    /**
     * Filesystem/cache-safe fragment identifying the archive variant, so a
     * filtered archive never collides with the unfiltered one.
     */
    protected function typeToken(?string $type): string
    {
        $base = $this->normalizeType($type);
        $filters = $this->parseTypeFilters($type);

        $suffix = '';
        if ($filters['include_extras']) {
            $suffix .= '-withextras';
        }
        if (! empty($filters['media_types'])) {
            $types = $filters['media_types'];
            sort($types);
            $suffix .= '-mt-' . implode('_', array_map(
                fn ($value) => preg_replace('/[^a-z0-9]+/', '', strtolower($value)),
                $types
            ));
        }

        return $base . $suffix;
    }

    protected function resolveFrontendUrl(): string
    {
        try {
            $frontendUrl = trim((string) config('app.frontend_url', config('app.url', self::DEFAULT_FRONTEND_URL)));
        } catch (\Throwable $exception) {
            return self::DEFAULT_FRONTEND_URL;
        }

        return $frontendUrl !== '' ? $frontendUrl : self::DEFAULT_FRONTEND_URL;
    }

    public function buildAbsoluteApiUrl(string $path): string
    {
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return rtrim((string) config('app.url', self::DEFAULT_API_URL), '/') . '/' . ltrim($path, '/');
    }
}
