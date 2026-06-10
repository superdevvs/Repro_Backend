<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scheduled-reminder tracking row for the Payment_Reminder cadence (Req 12).
 *
 * AutomationService upserts one row per (shoot_id, scheduled_date) so duplicate reminders
 * cannot be created (Req 12.15), and the dispatcher records the send + links the Message.
 */
class PaymentReminder extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'shoot_id',
        'scheduled_date',
        'scheduled_at',
        'status',
        'message_id',
        'sent_at',
    ];

    protected $casts = [
        // Format-pinned to Y-m-d so the stored value matches the (shoot_id, scheduled_date)
        // lookup AutomationService uses for its no-duplicate upsert (Req 12.15). Without the
        // explicit format the date cast round-trips as Y-m-d H:i:s, which causes the lookup to
        // miss the existing row and trip the unique constraint on the second run.
        'scheduled_date' => 'date:Y-m-d',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
