<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolBridgeInvocation extends Model
{
    protected $fillable = [
        'tool',
        'channel',
        'phone_e164',
        'contact_id',
        'user_id',
        'telnyx_event_id',
        'telnyx_conversation_id',
        'call_control_id',
        'idempotency_key',
        'status',
        'error_code',
        'latency_ms',
        'request_json',
        'response_json',
        'metadata',
        'raw_request_path',
        'raw_response_path',
    ];

    protected $casts = [
        'request_json' => 'array',
        'response_json' => 'array',
        'metadata' => 'array',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
