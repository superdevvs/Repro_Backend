<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'provider',
        'display_name',
        'label',
        'from_email',
        'reply_to_email',
        'from_number',
        'is_default',
        'owner_scope',
        'owner_id',
        'config_json',
    ];

    protected $casts = [
        'is_default' => 'bool',
        'config_json' => 'array',
    ];

    /**
     * Convenience accessor for scope specific owner relation.
     */
    public function owner(): ?BelongsTo
    {
        if ($this->owner_scope === 'USER') {
            return $this->belongsTo(User::class, 'owner_id');
        }

        return null;
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}

