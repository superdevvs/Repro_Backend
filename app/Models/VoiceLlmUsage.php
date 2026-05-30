<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceLlmUsage extends Model
{
    public $timestamps = false;

    protected $table = 'voice_llm_usage';

    protected $fillable = [
        'voice_call_id',
        'purpose',
        'model',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'created_at',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cost_usd' => 'float',
        'created_at' => 'datetime',
    ];

    public function voiceCall(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class, 'voice_call_id');
    }
}
