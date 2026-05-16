<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'channel',
        'phone_e164',
        'contact_id',
        'last_inbound_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'state_data' => 'array',
        'state' => 'array',
        'meta' => 'array',
        'last_inbound_at' => 'datetime',
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
     * Get the most recent message for this session (used to render a chat preview/snippet).
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(AiMessage::class, 'chat_session_id')->latestOfMany();
    }

    /**
     * Get the message count for this session.
     */
    public function getMessageCountAttribute(): int
    {
        return $this->messages()->count();
    }
}
