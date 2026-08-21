<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalAcceptance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role_at_acceptance',
        'document_key',
        'document_version',
        'content_hash',
        'effective_date',
        'accepted_at',
        'ip_address',
        'user_agent',
        'audit_metadata',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'accepted_at' => 'datetime',
            'audit_metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
