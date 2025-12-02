<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel',
        'contact_id',
        'assigned_to_user_id',
        'last_message_at',
        'last_direction',
        'last_snippet',
        'status',
        'tags_json',
        'unread_for_user_ids_json',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'tags_json' => 'array',
        'unread_for_user_ids_json' => 'array',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id');
    }
}

