<?php

namespace App\Services\SystemEmails;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\ShootClientReleaseAccessService;
use Illuminate\Support\Facades\Schema;

class EmailShootPhotos
{
    /** Only current, approved, same-shoot media may enter an email. */
    public function resolve(array $data): array
    {
        $shootId = data_get($data, 'shoot.id') ?? $data['shoot_id'] ?? null;
        $recipientId = data_get($data, 'recipient.id') ?? data_get($data, 'user.id') ?? $data['recipient_id'] ?? null;
        $recipientEmail = data_get($data, 'recipient.email') ?? data_get($data, 'user.email') ?? $data['recipient_email'] ?? null;
        if (! $shootId || (! $recipientId && ! $recipientEmail) || ! Schema::hasTable('shoot_files')) {
            return [];
        }

        $shoot = Shoot::find($shootId);
        $recipient = $recipientId ? User::find($recipientId) : User::where('email', $recipientEmail)->first();
        if (! $shoot || ! $recipient || ($recipientEmail && strcasecmp($recipient->email, $recipientEmail) !== 0)) {
            return [];
        }
        if (! in_array($recipient->role, ['admin', 'superadmin'], true) && (int) $shoot->client_id !== (int) $recipient->id) {
            return [];
        }
        $status = strtolower((string) ($shoot->workflow_status ?: $shoot->status));
        if (! $shoot->editing_completed_at && $status !== 'delivered') {
            return [];
        }
        if (! in_array($status, ['ready', 'delivered'], true)) {
            return [];
        }
        $release = app(ShootClientReleaseAccessService::class);
        if ($release->isPublicReleaseLocked($shoot)) {
            return [];
        }

        $files = $shoot->files()->where('media_type', 'edited')
            ->where('is_hidden', false)->where('is_extra', false)
            ->where(function ($query) {
                $query->where('workflow_stage', ShootFile::STAGE_VERIFIED)->orWhereNotNull('verified_at');
            })
            ->where(function ($query) {
                $query->whereNull('scan_status')->orWhere('scan_status', ShootFile::SCAN_STATUS_CLEAN);
            })
            ->orderByDesc('is_cover')->inDeliveryOrder()->limit(12)->get();
        $media = app(MediaStorage::class);
        $photos = [];
        foreach ($files as $file) {
            if ($release->isFileReleaseLocked($shoot, $file, $recipient) || $file->isBlockedFromDelivery()) {
                continue;
            }
            $key = $media->normalizeKey($file->web_path ?: $file->thumbnail_path);
            // Validate before Flysystem or an HTTP client can normalize traversal.
            if ($key && (array_intersect(explode('/', $key), ['.', '..', '']) || str_contains($key, '\\') || str_contains($key, '%') || str_contains($key, '?') || str_contains($key, '#') || preg_match('/[\x00-\x1F\x7F]/', $key))) {
                continue;
            }
            // Only derived images from this shoot; never raw originals, external URLs or generic covers.
            if (! $key || ! str_starts_with($key, 'shoots/'.$shoot->id.'/') || ! preg_match('/\.(?:jpe?g|png|webp)$/i', $key) || ! $media->exists($key)) {
                continue;
            }
            $url = $media->publicUrl($key);
            if (str_starts_with($url, '/')) {
                $url = rtrim((string) config('app.url'), '/').$url;
            }
            if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) {
                continue;
            }
            $photos[] = ['src' => $url, 'alt' => 'Edited property photograph for '.$shoot->address, 'file_id' => $file->id, 'shoot_id' => $shoot->id];
            if (count($photos) === 4) {
                break;
            }
        }

        return $photos;
    }
}
