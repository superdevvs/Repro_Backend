<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ShootFileVersion extends Model
{
    use HasUuids;

    protected $guarded = [];
    protected $casts = ['snapshot' => 'array', 'metadata' => 'array', 'expected_version' => 'integer', 'version' => 'integer'];

    public function present(): array
    {
        $mime = $this->snapshot['mime_type'] ?? $this->snapshot['file_type'] ?? null;
        $video = \App\Services\Shoots\MediaVersionVideo::extension((string) $mime) !== null;
        $previewable = in_array($this->status, ['published', 'archived', 'conflict', 'alternative'], true)
            && (($this->snapshot['scan_status'] ?? null) === 'clean'
                || (in_array($this->status, ['published', 'archived'], true) && ($this->snapshot['scan_status'] ?? null) === null));
        $actor = auth()->user();
        return $this->only(['id', 'source_file_id', 'target_file_id', 'published_file_id', 'expected_version', 'version', 'status', 'error_code', 'error', 'created_at', 'updated_at'])
            + ['filename' => $this->snapshot['filename'] ?? null, 'mime_type' => $mime,
                'stream_url' => $video && $previewable && $actor && in_array($actor->role, ['admin', 'superadmin', 'editing_manager'], true)
                    ? \Illuminate\Support\Facades\URL::temporarySignedRoute('api.media-version.video-preview', now()->addMinutes(15),
                        ['shoot' => $this->shoot_id, 'version' => $this->id, 'viewer' => $actor->id]) : null,
                'can_dismiss' => !$this->dispatch_item_id && in_array($this->status, ['failed', 'conflict', 'alternative'], true),
                'preview_url' => ! $video && in_array($this->status, ['published', 'archived', 'conflict', 'alternative'], true)
                    ? url("/api/shoots/{$this->shoot_id}/media-versions/{$this->id}/preview") : null];
    }
}
