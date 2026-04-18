<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShootEmailDelivery extends Model
{
    use HasFactory;

    public const EVENT_SHOOT_SCHEDULED_CONFIRMATION = 'SHOOT_SCHEDULED_CONFIRMATION';

    public const RECIPIENT_CLIENT = 'client';

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const SOURCE_AUTOMATION = 'automation';
    public const SOURCE_FALLBACK = 'fallback';
    public const SOURCE_REPLAY = 'replay';

    public const REASON_MISSING_EMAIL = 'missing_email';
    public const REASON_PROVIDER_ERROR = 'provider_error';
    public const REASON_NO_DELIVERY_PATH = 'no_delivery_path';

    protected $fillable = [
        'shoot_id',
        'recipient_user_id',
        'event_type',
        'recipient_type',
        'status',
        'source',
        'reason_code',
        'attempt_count',
        'last_attempted_at',
        'sent_at',
        'recovered_at',
        'last_message_id',
        'last_error_message',
    ];

    protected $casts = [
        'last_attempted_at' => 'datetime',
        'sent_at' => 'datetime',
        'recovered_at' => 'datetime',
    ];

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }
}
