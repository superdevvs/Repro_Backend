<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'phones_json',
        'tags_json',
        'comment',
        'type',
        'user_id',
        'account_id',
        'sms_opt_out',
        'sms_opt_out_at',
        'sms_ai_enabled',
    ];

    protected $casts = [
        'phones_json' => 'array',
        'tags_json' => 'array',
        'sms_opt_out' => 'boolean',
        'sms_opt_out_at' => 'datetime',
        'sms_ai_enabled' => 'boolean',
    ];

    /**
     * A contact can be tied to a dashboard user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Message threads associated with this contact.
     */
    public function threads(): HasMany
    {
        return $this->hasMany(MessageThread::class);
    }

    /**
     * Messages accessible via message threads.
     */
    public function messages(): HasManyThrough
    {
        return $this->hasManyThrough(Message::class, MessageThread::class, 'contact_id', 'thread_id');
    }
}

