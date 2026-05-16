<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceCallVerification extends Model
{
    protected $fillable = [
        'phone_e164',
        'voice_call_id',
        'user_id',
        'contact_id',
        'method',
        'success',
        'attempts',
        'metadata',
    ];

    protected $casts = [
        'success' => 'boolean',
        'metadata' => 'array',
    ];

    public function voiceCall(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
