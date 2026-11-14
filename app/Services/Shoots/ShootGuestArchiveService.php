<?php

namespace App\Services\Shoots;

use App\Jobs\GenerateShootGuestArchive;
use App\Models\ShootGuestShare;
use App\Services\Media\{ArchiveCompressionPolicy, LocalMediaDelivery};
use App\Support\ShootAddress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ShootGuestArchiveService
{
    private const TTL = 3600;

    public function create(ShootGuestShare $share, ?array $ids, string $size): array
    {
        $shoot = $share->shoot->fresh();
        app(DeliveryPlanService::class)->forgetReleasedFiles($shoot);
        $files = app(ShootGuestOverview::class)->files($shoot);
        if ($ids !== null) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $files = $files->whereIn('id', $ids)->values();
            abort_unless($files->count() === count($ids), 404, 'Some selected files are no longer available.');
        }
        abort_if($files->isEmpty(), 422, 'No released files are available to download.');
        abort_if($files->count() > 5000, 422, 'Select fewer files for this download.');
        $plan = app(ShootGuestArchiveFiles::class)->selectionPlan($shoot, $files, $size);
        abort_unless(count($plan['entries']) === $files->count(), 409, 'A selected file is not ready to download.');
        $id = bin2hex(random_bytes(16));
        $label = Str::slug(ShootAddress::formatFullAddress($shoot)) ?: 'property';
        $record = ['id' => $id, 'share_id' => (int) $share->id, 'size' => $size,
            'ids' => $files->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'signature' => $this->signature($plan, $files), 'status' => 'preparing',
            'filename' => substr($label, 0, 180).($size === 'small' ? '-mls.zip' : '-full-size.zip'),
            'expires_at' => now()->addSeconds(self::TTL)->timestamp];
        $this->put($record);
        try {
            GenerateShootGuestArchive::dispatch((int) $share->id, $id, (int) $shoot->id);
        } catch (\Throwable $error) {
            Cache::forget($this->key((int) $share->id, $id));
            throw $error;
        }
        return $this->record($share, $id);
    }

    public function record(ShootGuestShare $share, string $id): array
    {
        abort_unless(preg_match('/^[a-f0-9]{32}$/D', $id), 404);
        $record = Cache::get($this->key((int) $share->id, $id));
        abort_unless(is_array($record) && ($record['share_id'] ?? null) === (int) $share->id
            && ($record['expires_at'] ?? 0) > now()->timestamp, 404, 'This download has expired. Start a new download.');
        return $record;
    }

    public function revalidate(ShootGuestShare $share, array $record): array
    {
        app(ShootGuestShareService::class)->authorizeResolvedShare($share);
        $shoot = $share->shoot->fresh();
        app(DeliveryPlanService::class)->forgetReleasedFiles($shoot);
        $files = app(ShootGuestOverview::class)->files($shoot)->whereIn('id', $record['ids'])->values();
        abort_unless($files->count() === count($record['ids']), 409, 'File access changed. Start a new download.');
        $plan = app(ShootGuestArchiveFiles::class)->selectionPlan($shoot, $files, $record['size']);
        abort_unless(hash_equals($record['signature'], $this->signature($plan, $files)), 409,
            'The selected media changed. Start a new download.');
        return [$shoot, $plan];
    }

    public function generate(int $shareId, string $id): void
    {
        $share = ShootGuestShare::find($shareId);
        if (! $share) return;
        $record = Cache::get($this->key($shareId, $id));
        if (! is_array($record) || ($record['status'] ?? null) !== 'preparing'
            || ($record['expires_at'] ?? 0) <= now()->timestamp) return;
        $temporary = null;
        $published = null;
        $staged = [];
        try {
            [$shoot, $plan] = $this->revalidate($share, $record);
            $temporary = tempnam(sys_get_temp_dir(), 'guest-archive-');
            if ($temporary === false) throw new \RuntimeException('Could not create archive workspace.');
            $zip = new \ZipArchive;
            if ($zip->open($temporary, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not open archive workspace.');
            }
            try {
                foreach ($plan['entries'] as $entry) {
                    $source = app(ShootGuestArchiveFiles::class)->stage($entry);
                    if (! empty($entry['temp_path'])) $staged[] = $entry['temp_path'];
                    if (! $source || ! is_file($source)) throw new \RuntimeException('A selected source is unavailable.');
                    app(ArchiveCompressionPolicy::class)->addFile($zip, $source, $entry['archive_name'], (int) $shoot->id);
                }
            } finally {
                $closed = $zip->close();
            }
            if (! $closed) throw new \RuntimeException('Could not finish the archive.');
            // A long-running job must not publish an obsolete or revoked selection.
            $this->revalidate($share->fresh(), $record);
            $directory = app(LocalMediaDelivery::class)->temporaryDirectory();
            if (! is_dir($directory)) {
                if (! mkdir($directory, 02770, true) && ! is_dir($directory)) throw new \RuntimeException('Could not create download directory.');
                chmod($directory, 02770);
            }
            // Matches the existing 24-hour temporary-download pruning policy.
            $basename = bin2hex(random_bytes(24));
            $published = $directory.'/'.$basename;
            if (! @rename($temporary, $published)) {
                if (! copy($temporary, $published)) throw new \RuntimeException('Could not publish the archive.');
                unlink($temporary);
            }
            $temporary = null;
            if (! chmod($published, 0660)) throw new \RuntimeException('Could not set download permissions.');
            // Serialize only publication with cancellation. Never hold a cache
            // lock while copying or compressing large media files.
            Cache::lock($this->key($shareId, $id).':publish', 15)->block(5, function () use ($shareId, $id, $basename): void {
                $current = Cache::get($this->key($shareId, $id));
                abort_unless(is_array($current) && ($current['status'] ?? null) === 'preparing'
                    && ($current['expires_at'] ?? 0) > now()->timestamp, 409, 'This download is no longer active.');
                $currentShare = ShootGuestShare::find($shareId);
                abort_unless($currentShare, 410, 'This invitation is no longer available.');
                $this->revalidate($currentShare, $current);
                $current['basename'] = $basename;
                $current['status'] = 'ready';
                $this->put($current);
            });
            $published = null;
        } catch (\Throwable $error) {
            $this->failed($shareId, $id);
            report($error);
        } finally {
            foreach (array_filter(array_merge([$temporary, $published], $staged)) as $path) {
                if (is_file($path)) @unlink($path);
            }
        }
    }

    public function payload(array $record, string $token): array
    {
        $parameters = ['token' => $token, 'archive' => $record['id']];
        $payload = ['id' => $record['id'], 'status' => $record['status'], 'size' => $record['size'],
            'file_count' => count($record['ids']), 'filename' => $record['filename'],
            'status_url' => route('api.guest-shares.downloads.show', $parameters), 'poll_after_ms' => 3000];
        if ($record['status'] === 'ready') $payload['download_url'] = route('api.guest-shares.downloads.file', $parameters);
        if ($record['status'] === 'failed') $payload['message'] = 'This download could not be prepared. Please start a new download.';
        return $payload;
    }

    public function failed(int $shareId, string $id): void
    {
        Cache::lock($this->key($shareId, $id).':publish', 15)->block(5, function () use ($shareId, $id): void {
            $record = Cache::get($this->key($shareId, $id));
            if (is_array($record) && ($record['status'] ?? null) === 'preparing'
                && ($record['expires_at'] ?? 0) > now()->timestamp) {
                $record['status'] = 'failed';
                $this->put($record);
            }
        });
    }

    /** Remove one internal archive recipe and its exact artifact, including an in-flight job. */
    public function discard(int $shareId, string $id): void
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $id)) throw new \InvalidArgumentException('Invalid archive identifier.');
        Cache::lock($this->key($shareId, $id).':publish', 15)->block(5, function () use ($shareId, $id): void {
            $record = Cache::get($this->key($shareId, $id));
            if (is_array($record) && ($record['share_id'] ?? null) === $shareId
                && preg_match('/^[a-f0-9]{48}$/D', $record['basename'] ?? '')) {
                $path = app(LocalMediaDelivery::class)->temporaryDirectory().'/'.$record['basename'];
                if (is_file($path) && ! is_link($path) && ! unlink($path)) {
                    throw new \RuntimeException('Could not remove the archive artifact.');
                }
            }
            Cache::forget($this->key($shareId, $id));
        });
    }

    public function path(array $record): string
    {
        abort_unless($record['status'] === 'ready' && preg_match('/^[a-f0-9]{48}$/D', $record['basename'] ?? ''), 409, 'This download is not ready.');
        $path = app(LocalMediaDelivery::class)->temporaryDirectory().'/'.$record['basename'];
        abort_unless(is_file($path) && ! is_link($path), 404, 'This download has expired.');
        return $path;
    }

    private function signature(array $plan, $files): string
    {
        return hash('sha256', json_encode([$plan['source_signature'], $files->map(fn ($file) =>
            [(int) $file->id, (int) ($file->content_version ?: 1), (int) $file->shoot_service_id])->all()], JSON_THROW_ON_ERROR));
    }

    private function key(int $shareId, string $id): string { return 'guest-archive:'.$shareId.':'.$id; }

    private function put(array $record): void
    {
        Cache::put($this->key($record['share_id'], $record['id']), $record, max(1, $record['expires_at'] - now()->timestamp));
    }
}
