<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IguideLabProject extends Model
{
    use HasUuids;

    protected $guarded = [];
    protected $hidden = ['token_hash'];
    protected $casts = ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'max_runs' => 'integer', 'used_runs' => 'integer', 'max_cases' => 'integer'];

    public function active(): bool
    {
        return ! $this->revoked_at && $this->expires_at->isFuture();
    }
}
