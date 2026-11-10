<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AryeoApiAsset extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['source_url', 'grant_hash'];

    protected $casts = ['source' => 'array', 'presentation' => 'array', 'source_url' => 'encrypted', 'grant_expires_at' => 'datetime', 'grant_revoked_at' => 'datetime'];

    public function export()
    {
        return $this->belongsTo(AryeoApiExport::class, 'export_id');
    }
}
