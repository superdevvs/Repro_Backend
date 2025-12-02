<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel',
        'name',
        'slug',
        'description',
        'category',
        'subject',
        'body_html',
        'body_text',
        'variables_json',
        'created_by',
        'updated_by',
        'scope',
        'owner_id',
        'is_system',
        'is_active',
    ];

    protected $casts = [
        'variables_json' => 'array',
        'is_system' => 'bool',
        'is_active' => 'bool',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

