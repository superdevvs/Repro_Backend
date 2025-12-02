<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\MessageChannel;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel',
        'direction',
        'provider',
        'provider_message_id',
        'message_channel_id',
        'from_address',
        'to_address',
        'reply_to_email',
        'subject',
        'body_text',
        'body_html',
        'attachments_json',
        'status',
        'send_source',
        'tags_json',
        'error_message',
        'scheduled_at',
        'sent_at',
        'delivered_at',
        'failed_at',
        'created_by',
        'template_id',
        'related_shoot_id',
        'related_account_id',
        'related_invoice_id',
        'thread_id',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'attachments_json' => 'array',
        'tags_json' => 'array',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function channelConfig(): BelongsTo
    {
        return $this->belongsTo(MessageChannel::class, 'message_channel_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class, 'related_shoot_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'related_invoice_id');
    }
}

