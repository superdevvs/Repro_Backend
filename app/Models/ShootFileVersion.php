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
        return $this->only(['id', 'source_file_id', 'target_file_id', 'published_file_id', 'expected_version', 'version', 'status', 'error_code', 'error', 'created_at', 'updated_at'])
            + ['filename' => $this->snapshot['filename'] ?? null, 'can_dismiss' => !$this->dispatch_item_id && in_array($this->status, ['failed', 'conflict', 'alternative'], true),
                'preview_url' => in_array($this->status, ['published', 'archived', 'conflict', 'alternative'], true)
                    ? url("/api/shoots/{$this->shoot_id}/media-versions/{$this->id}/preview") : null];
    }
}
