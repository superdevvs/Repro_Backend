<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemEmailDispatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_type',
        'email_alias',
        'email_version',
        'category',
        'idempotency_key',
        'correlation_id',
        'recipient_email',
        'recipient_type',
        'related_account_id',
        'related_shoot_id',
        'related_invoice_id',
        'message_id',
        'provider',
        'provider_message_id',
        'send_source',
        'delivery_mode',
        'template_view',
        'template_version',
        'status',
        'attempt_count',
        'payload_snapshot',
        'transport_snapshot',
        'metadata',
        'error_code',
        'error_message',
        'sent_at',
        'failed_at',
    ];

    protected $casts = [
        'payload_snapshot' => 'array',
        'transport_snapshot' => 'array',
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }
}
