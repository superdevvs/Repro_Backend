<?php

namespace App\Services\Studio;

use App\Exceptions\StudioProviderException;
use App\Models\ShootFile;
use App\Models\StudioWorkspace;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Server-owned identity and byte receipts. They are never accepted from a client. */
class WorkspaceOriginalPhoto
{
    public function __construct(private WorkspaceMediaService $media) {}

    public function selected(StudioWorkspace $workspace, string $mediaId): array
    {
        abort_if($workspace->isVideo(), 422, 'Original photo tools require a photo workspace.');
        $item = collect($workspace->media ?? [])->firstWhere('id', $mediaId);
        abort_unless(is_array($item), 422, 'Choose an original photo from this workspace.');

        return $item;
    }

    public function identity(array $item): array
    {
        $ids = array_map('intval', $item['stackFileIds'] ?? (isset($item['fileId']) ? [$item['fileId']] : []));
        sort($ids);

        return [
            'id' => $item['id'], 'fileId' => isset($item['fileId']) ? (int) $item['fileId'] : null,
            'shootId' => isset($item['shootId']) ? (int) $item['shootId'] : null,
            'mediaRef' => $item['mediaRef'] ?? null, 'stackFileIds' => $ids,
            'uploadDisk' => isset($item['mediaRef']) ? config('studio_uploads.disk', 'public') : null,
            'files' => ShootFile::whereIn('id', $ids)->orderBy('id')->get()->map(fn ($file) => [
                $file->id, $file->shoot_id, (int) ($file->content_version ?: 1),
                $file->file_size, $file->path, $file->storage_path,
            ])->all(),
        ];
    }

    /** Read outside the SQLite write transaction; later reservation verifies identity again. */
    public function capture(StudioWorkspace $workspace, string $mediaId): array
    {
        $item = $this->selected($workspace, $mediaId);
        $identity = $this->identity($item);
        $bytes = $this->media->bytes($item);
        $size = @getimagesizefromstring($bytes);
        abort_unless($size && $size[0] * $size[1] <= 40000000, 422, 'This photo exceeds the 40 megapixel editing limit.');
        abort_unless($identity === $this->identity($item), 409, 'The original photo changed. Reopen the picker.');

        return ['bytes' => $bytes, 'receipt' => ['identity' => $identity, 'sha256' => hash('sha256', $bytes)]];
    }

    public function assertIdentity(StudioWorkspace $workspace, string $mediaId, array $receipt): void
    {
        abort_unless(($receipt['identity'] ?? null) === $this->identity($this->selected($workspace, $mediaId)), 409, 'The original photo changed. Reopen the picker.');
    }

    public function sourceVersion(array $receipt): string
    {
        return hash('sha256', json_encode(StudioWorkspace::canonicalConfig($receipt), JSON_THROW_ON_ERROR));
    }

    /** The worker has already reauthorized the workspace; do not trust an old URL or newest output. */
    public function bytes(StudioWorkspace $workspace, array $item): string
    {
        try {
            $receipt = $workspace->operation['originalSources'][$item['id']] ?? [];
            $this->assertIdentity($workspace, $item['id'], $receipt);
            $bytes = $this->media->bytes($item);
            abort_unless(isset($receipt['sha256']) && hash_equals($receipt['sha256'], hash('sha256', $bytes)), 409, 'The original photo content changed. Reopen the picker.');
            $fresh = $workspace->fresh();
            abort_unless($fresh->isBusy() && ($fresh->operation['id'] ?? null) === ($workspace->operation['id'] ?? null), 409, 'This photo operation is no longer active.');
            $this->assertIdentity($fresh, $item['id'], $receipt);
            $this->media->authorizeWorkspace($fresh);

            return $bytes;
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [409, 422], true)) {
                throw new StudioProviderException('The selected original photo changed. Reopen photo tools before starting another edit.');
            }
            throw $exception;
        }
    }
}
