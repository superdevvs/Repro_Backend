<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiChatSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'topic',
        'intent',
        'step',
        'state_data',
        'state',
        'meta',
        'engine',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'state_data' => 'array',
        'state' => 'array',
        'meta' => 'array',
    ];

    /**
     * Get the user that owns the chat session.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the messages for the chat session.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'chat_session_id')->orderBy('created_at', 'asc');
    }

    /**
     * Get the message count for this session.
     */
    public function getMessageCountAttribute(): int
    {
        return $this->messages()->count();
    }
}
