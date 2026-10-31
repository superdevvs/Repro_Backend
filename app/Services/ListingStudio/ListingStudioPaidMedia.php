<?php

namespace App\Services\ListingStudio;

use App\Exceptions\PublicApiException;
use App\Models\ListingStudioOrderItem;
use App\Models\ListingStudioUpload;
use App\Models\User;
use App\Services\UploadValidationService;
use App\Support\LockedWrite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ListingStudioPaidMedia
{
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const MAX_BYTES = 10485760;

    public const MAX_PIXELS = 40000000;

    public function authorizeClient(User $client): void
    {
        if ($client->role !== 'client' || ! $client->isAccountEligibleForAuthentication()) {
            throw new PublicApiException('Sign in to your active client account.', 'account_ineligible', 403);
        }
    }

    public function upload(User $client, UploadedFile $photo, string $key, ?User $actor = null): ListingStudioUpload
    {
        $this->authorizeClient($client);
        if ($actor) {
            ListingStudioDelegatedAccess::assert($actor, $client);
        }
        $metadata = $this->inspectUpload($photo);
        $hash = $metadata['sha256'];
        $lock = Cache::store('database')->lock('listing-studio-paid-upload:'.$client->id.':'.$key, 120);
        if (! $lock->get()) {
            throw new PublicApiException('This photo is still being uploaded. Retry the same upload shortly.', 'upload_in_progress', 409);
        }
        try {
            $record = LockedWrite::run(fn () => DB::transaction(function () use ($client, $metadata, $hash, $key, $actor): ListingStudioUpload {
                // Acquire SQLite's writer before taking a replay/quota snapshot.
                // A SELECT first can make the later INSERT fail immediately with
                // SQLITE_BUSY_SNAPSHOT, bypassing busy_timeout on every retry.
                // Query Builder preserves timestamps and avoids model events;
                // even zero matching rows acquire the SQLite write lock.
                DB::table('listing_studio_uploads')->where('client_id', $client->id)->where('request_key', $key)
                    ->update(['updated_at' => DB::raw('updated_at')]);
                $existing = ListingStudioUpload::where('client_id', $client->id)->where('request_key', $key)->first();
                if ($existing) {
                    abort_if($actor && (int) $existing->actor_id !== (int) $actor->id, 404);
                    if (! hash_equals($existing->request_hash, $hash)) {
                        throw new PublicApiException('This upload key was already used for a different photo.', 'idempotency_conflict', 409);
                    }
                    if ($existing->media_purged_at || $existing->expires_at->isPast()) {
                        throw new PublicApiException('This upload expired. Upload the photo again.', 'source_unavailable', 422);
                    }

                    return $existing;
                }
                if ($actor) {
                    ListingStudioDelegatedAccess::assert(User::findOrFail($actor->id), User::findOrFail($client->id), true);
                }
                if (! config('listing-studio.paid_enabled', false)) {
                    throw new PublicApiException('Paid photo editing is temporarily unavailable.', 'service_unavailable', 503);
                }
                $id = (string) Str::uuid();
                $record = ListingStudioUpload::create(['id' => $id, 'client_id' => $client->id, 'actor_id' => $actor?->id ?? $client->id, 'request_key' => $key, 'request_hash' => $hash,
                    'path' => 'uploads/'.$id.'/original.'.$this->extension($metadata['mime']), 'sha256' => $hash,
                    'mime' => $metadata['mime'], 'width' => $metadata['width'], 'height' => $metadata['height'], 'byte_size' => $metadata['byteSize'],
                    'status' => 'preparing', 'expires_at' => now()->addDay()]);
                // The insert acquires SQLite's write lock before the quota read.
                if (ListingStudioUpload::where('client_id', $client->id)->whereNull('media_purged_at')
                    ->where(function ($sources): void {
                        $sources->where(fn ($unused) => $unused->whereNull('pinned_at')->where('expires_at', '>', now())->whereIn('status', ['preparing', 'ready']))
                            ->orWhereExists(function ($unpaid): void {
                                $unpaid->selectRaw('1')->from('listing_studio_order_items as pending_item')
                                    ->join('listing_studio_orders as pending_order', 'pending_order.id', '=', 'pending_item.order_id')
                                    ->whereColumn('pending_item.source_id', 'listing_studio_uploads.id')
                                    ->whereColumn('pending_item.client_id', 'listing_studio_uploads.client_id')
                                    ->where('pending_item.source_kind', 'upload')->where('pending_item.status', 'awaiting_funding')
                                    ->where('pending_order.funding_method', 'card')->where('pending_order.funding_status', 'pending');
                            })->orWhereExists(function ($terminal): void {
                                $terminal->selectRaw('1')->from('listing_studio_order_items as terminal_item')
                                    ->join('listing_studio_orders as terminal_order', 'terminal_order.id', '=', 'terminal_item.order_id')
                                    ->whereColumn('terminal_item.source_id', 'listing_studio_uploads.id')
                                    ->whereColumn('terminal_item.client_id', 'listing_studio_uploads.client_id')
                                    ->where('terminal_item.source_kind', 'upload')->whereIn('terminal_item.status', ['failed', 'cancelled'])
                                    ->where('terminal_order.funding_method', 'card')->where('terminal_order.paid_amount_cents', 0)
                                    ->whereNotExists(function ($funded): void {
                                        $funded->selectRaw('1')->from('listing_studio_order_items as funded_item')
                                            ->join('listing_studio_orders as funded_order', 'funded_order.id', '=', 'funded_item.order_id')
                                            ->whereColumn('funded_item.source_id', 'listing_studio_uploads.id')->where('funded_item.source_kind', 'upload')
                                            ->where(fn ($paid) => $paid->where('funded_order.paid_amount_cents', '>', 0)->orWhere('funded_order.funding_method', 'credits'));
                                    });
                            });
                    })->count() > max(1, (int) config('listing-studio.max_active_sources_per_client', 20))) {
                    throw new PublicApiException('Please use an existing upload or wait for unused uploads to expire.', 'upload_limit', 429);
                }

                return $record;
            }), 'listing-studio.paid-upload');
            if ($record->status === 'ready') {
                return $record;
            }
            app(ListingStudioPaidStorage::class)->prepareUploadDirectory($record->id);
            $bytes = file_get_contents($photo->getRealPath());
            $disk = Storage::disk(ListingStudioPaidStorage::DISK);
            $disk->put($record->path, $bytes);
            $preview = 'uploads/'.$record->id.'/preview.jpg';
            $disk->put($preview, $this->previewBytes($bytes));
            LockedWrite::run(fn () => $record->update(['preview_path' => $preview, 'status' => 'ready']), 'listing-studio.paid-upload-ready');

            return $record->fresh();
        } finally {
            $lock->release();
        }
    }

    public function inspectUpload(UploadedFile $photo): array
    {
        $size = $photo->getSize();
        $info = @getimagesize($photo->getRealPath());
        if (! is_int($size) || $size < 1 || $size > self::MAX_BYTES || ! $info
            || ! in_array($info['mime'] ?? null, self::MIME_TYPES, true) || ! in_array($photo->getMimeType(), self::MIME_TYPES, true)
            || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS
            || app(UploadValidationService::class)->hasDangerousContentType($photo)) {
            throw new PublicApiException('Choose a JPEG, PNG or WebP photo up to 10 MB and 40 megapixels.', 'validation_failed', 422);
        }
        // Decode before accepting a paid source, rather than trusting a valid image header alone.
        $decoded = @imagecreatefromstring(file_get_contents($photo->getRealPath()));
        if (! $decoded) {
            throw new PublicApiException('This photo could not be decoded. Choose another image.', 'validation_failed', 422);
        }
        imagedestroy($decoded);

        return ['sha256' => hash_file('sha256', $photo->getRealPath()), 'mime' => $info['mime'], 'width' => $info[0], 'height' => $info[1], 'byteSize' => $size];
    }

    public function ownedUpload(User $client, string $id): ListingStudioUpload
    {
        $this->authorizeClient($client);
        $upload = ListingStudioUpload::where('client_id', $client->id)->findOrFail($id);
        if ($upload->status !== 'ready' || $upload->media_purged_at || $upload->expires_at->isPast()) {
            throw new PublicApiException('This upload is unavailable. Upload the photo again.', 'source_unavailable', 422);
        }

        return $upload;
    }

    public function presentUpload(ListingStudioUpload $upload): array
    {
        return ['id' => $upload->id, 'mime' => $upload->mime, 'width' => $upload->width, 'height' => $upload->height,
            'originalPreviewAvailable' => $upload->status === 'ready' && filled($upload->preview_path),
            'expiresAt' => $upload->expires_at->toIso8601String()];
    }

    public function descriptor(string $disk, string $path): array
    {
        $bytes = $this->read(['disk' => $disk, 'path' => $path]);
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'] ?? '', self::MIME_TYPES, true) || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new PublicApiException('This photo is unavailable.', 'source_unavailable', 422);
        }

        return ['disk' => $disk, 'path' => $path, 'sha256' => hash('sha256', $bytes), 'mime' => $info['mime'],
            'width' => $info[0], 'height' => $info[1], 'byteSize' => strlen($bytes)];
    }

    public function read(array $descriptor): string
    {
        $disk = $descriptor['disk'] ?? '';
        $path = $descriptor['path'] ?? '';
        if (! in_array($disk, [ListingStudioPaidStorage::DISK, ListingStudioGuestTrialService::DISK], true)
            || ! is_string($path) || $path === '' || str_starts_with($path, '/') || preg_match('/(?:\.\.|[\\\\:\x00])/', $path)) {
            throw new PublicApiException('This photo is unavailable.', 'source_unavailable', 422);
        }
        $storage = Storage::disk($disk);
        $root = realpath($storage->path(''));
        $real = realpath($storage->path($path));
        if (! $storage->exists($path) || ! $root || ! $real || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)
            || $storage->size($path) > 67108864) {
            throw new PublicApiException('This photo is unavailable.', 'source_unavailable', 422);
        }
        $bytes = $storage->get($path);
        if (isset($descriptor['sha256']) && ! hash_equals($descriptor['sha256'], hash('sha256', $bytes))) {
            throw new PublicApiException('The source photo changed. Please request a new quote.', 'source_unavailable', 422);
        }

        return $bytes;
    }

    public function pruneUnusedUploads(int $limit = 100): int
    {
        $count = 0;
        $ids = ListingStudioUpload::whereNull('media_purged_at')->where('expires_at', '<=', now())
            ->oldest('updated_at')->limit(max(1, min(500, $limit)))->pluck('id');
        foreach ($ids as $id) {
            $upload = ListingStudioUpload::find($id);
            if (! $upload) {
                continue;
            }
            $lock = Cache::store('database')->lock('listing-studio-paid-upload:'.$upload->client_id.':'.$upload->request_key, 120);
            if (! $lock->get()) {
                continue;
            }
            try {
                $purge = LockedWrite::run(fn () => DB::transaction(function () use ($id): bool {
                    $upload = ListingStudioUpload::find($id);
                    // Rotate protected rows through the bounded sweep, and acquire the SQLite
                    // write guard before checking references that another accept may change.
                    $upload?->touch();
                    if (! $upload || $upload->media_purged_at || $upload->expires_at->isFuture() || ! $this->mayPrune($upload)) {
                        return false;
                    }
                    $upload->update(['status' => 'expired']);

                    return true;
                }), 'listing-studio.upload-prune');
                if ($purge && Storage::disk(ListingStudioPaidStorage::DISK)->deleteDirectory('uploads/'.$id)) {
                    LockedWrite::run(fn () => ListingStudioUpload::whereKey($id)->update(['media_purged_at' => now()]));
                    $count++;
                }
            } finally {
                $lock->release();
            }
        }

        return $count;
    }

    /** DB-only proof under the same short guard that blocks new source acceptance. */
    private function mayPrune(ListingStudioUpload $upload): bool
    {
        $references = ListingStudioOrderItem::where('source_kind', 'upload')->where('source_id', $upload->id);
        if (! (clone $references)->exists()) {
            return $upload->pinned_at === null;
        }
        $unsafe = (clone $references)->join('listing_studio_orders as orders', 'orders.id', '=', 'listing_studio_order_items.order_id')
            ->where(function ($query) use ($upload): void {
                $query->whereNotIn('listing_studio_order_items.status', ['failed', 'cancelled'])
                    ->orWhere('orders.funding_method', '!=', 'card')->orWhere('orders.paid_amount_cents', '>', 0)
                    ->orWhereNotNull('listing_studio_order_items.workspace_id')->orWhereNotNull('listing_studio_order_items.output_descriptor')
                    ->orWhere('listing_studio_order_items.client_id', '!=', $upload->client_id);
            })->exists();
        if ($unsafe || ! Schema::hasTable('listing_studio_checkout_attempts')) {
            return false;
        }
        $itemIds = fn () => (clone $references)->select('id');
        if (DB::table('studio_workspaces')->whereIn('listing_studio_order_item_id', $itemIds())->exists()
            || DB::table('listing_studio_revision_tasks')->whereIn('order_item_id', $itemIds())->exists()) {
            return false;
        }

        return ! DB::table('listing_studio_checkout_attempts')->whereIn('order_id', (clone $references)->select('order_id'))
            ->whereNotIn('status', ['expired', 'failed'])->exists();
    }

    public function materialize(ListingStudioOrderItem $item): array
    {
        $lock = Cache::store('database')->lock('listing-studio-materialize:'.$item->id, 120);
        if (! $lock->get()) {
            throw new \RuntimeException('This private source is already being prepared.');
        }
        try {
            $snapshot = $item->source_snapshot;
            app(ListingStudioPaidStorage::class)->prepareItemDirectory($item->id);
            $disk = Storage::disk(ListingStudioPaidStorage::DISK);
            $input = $this->read($snapshot);
            $inputPath = 'items/'.$item->id.'/input.'.$this->extension($snapshot['mime']);
            if (! $disk->exists($inputPath)) {
                $disk->put($inputPath, $input);
            }
            $materialized = $this->descriptor(ListingStudioPaidStorage::DISK, $inputPath);
            if (! hash_equals($snapshot['sha256'], $materialized['sha256'])) {
                throw new PublicApiException('The saved source changed.', 'source_unavailable', 422);
            }
            $original = $snapshot['original'] ?? $snapshot;
            $originalPath = 'items/'.$item->id.'/property-original.'.$this->extension($original['mime']);
            if (! $disk->exists($originalPath)) {
                $disk->put($originalPath, $this->read($original));
            }
            $originalDescriptor = $this->descriptor(ListingStudioPaidStorage::DISK, $originalPath);
            if (! hash_equals($original['sha256'], $originalDescriptor['sha256'])) {
                throw new PublicApiException('The saved original changed.', 'source_unavailable', 422);
            }
            $previewPath = 'items/'.$item->id.'/original-preview.jpg';
            if (! $disk->exists($previewPath)) {
                $disk->put($previewPath, $this->previewBytes($input));
            }

            return ['kind' => $snapshot['kind'], 'id' => $snapshot['id'], 'clientId' => (int) $item->client_id, 'service' => $snapshot['service'] ?? null, 'expiresAt' => $snapshot['expiresAt'] ?? null, ...$materialized,
                'original' => $originalDescriptor, 'preview' => $this->descriptor(ListingStudioPaidStorage::DISK, $previewPath)];
        } finally {
            $lock->release();
        }
    }

    public function completeOutput(ListingStudioOrderItem $item, array $descriptor): array
    {
        $path = $descriptor['path'] ?? '';
        $delivery = $item->kind === 'human_revision' ? \App\Models\ListingStudioRevisionTask::where('order_item_id', $item->id)->value('delivery_attempt_id') : null;
        if (($descriptor['disk'] ?? '') !== ListingStudioPaidStorage::DISK
            || ! (($item->workspace_id && str_starts_with($path, 'studio/workspaces/'.$item->workspace_id.'/'))
                || ($delivery && str_starts_with($path, 'revision-deliveries/'.$delivery.'/')))) {
            throw new PublicApiException('The output does not belong to this order.', 'source_unavailable', 422);
        }
        $bytes = $this->read($descriptor);
        $actual = $this->descriptor($descriptor['disk'], $descriptor['path']);
        app(ListingStudioPaidStorage::class)->prepareItemDirectory($item->id);
        $preview = 'items/'.$item->id.'/result-preview.jpg';
        Storage::disk(ListingStudioPaidStorage::DISK)->put($preview, $this->previewBytes($bytes));

        return ['itemId' => $item->id, 'clientId' => (int) $item->client_id, ...$actual, 'preview' => $this->descriptor(ListingStudioPaidStorage::DISK, $preview)];
    }

    public function previewBytes(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'] ?? '', self::MIME_TYPES, true) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new PublicApiException('This image cannot be previewed.', 'source_unavailable', 422);
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            throw new PublicApiException('This image cannot be previewed.', 'source_unavailable', 422);
        }
        $scale = min(1, 1024 / max($info[0], $info[1]));
        $preview = imagecreatetruecolor(max(1, (int) round($info[0] * $scale)), max(1, (int) round($info[1] * $scale)));
        try {
            imagefill($preview, 0, 0, imagecolorallocate($preview, 255, 255, 255));
            imagecopyresampled($preview, $source, 0, 0, 0, 0, imagesx($preview), imagesy($preview), $info[0], $info[1]);
            ob_start();
            try {
                imagejpeg($preview, null, 85);
                $result = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (! is_string($result) || $result === '') {
                throw new \RuntimeException('Private preview could not be created.');
            }

            return $result;
        } finally {
            imagedestroy($source);
            imagedestroy($preview);
        }
    }

    public function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => throw new \RuntimeException('Unsupported private image format.')
        };
    }
}
