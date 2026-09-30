<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShootRescheduleRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'shoot_id',
        'requested_by',
        'approved_by',
        'original_date',
        'original_time',
        'requested_date',
        'requested_time',
        'reason',
        'review_notes',
        'status',
        'reviewed_at',
        'applied_at',
        'units_revision',
    ];

    protected $casts = [
        'original_date' => 'date',
        'requested_date' => 'date',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
        'units_revision' => 'integer',
    ];

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Whether the requested change has already been written to the shoot.
     *
     * This is the idempotency guard: approving an already-applied request must
     * not move the shoot a second time or re-send notifications. It is tracked
     * separately from `status` because a request can be approved and applied in
     * one step (staff rescheduling directly) or in two (client requests, staff
     * approves later).
     */
    public function hasBeenApplied(): bool
    {
        return $this->applied_at !== null;
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * After staff (or an assigned rep) moves the shoot via edit/update, reconcile
     * still-pending client/photographer reschedule requests:
     *
     * - If the new schedule already matches a request's date/time, mark it
     *   approved+applied (fulfilled) so it leaves the Requests queue cleanly.
     * - Otherwise leave it pending so staff can still review it.
     *
     * Does not auto-reject, and never touches already-reviewed rows.
     */
    public static function reconcilePendingForManualScheduleChange(
        Shoot $shoot,
        ?User $actor = null
    ): int {
        $pending = static::query()
            ->where('shoot_id', $shoot->id)
            ->where('status', self::STATUS_PENDING)
            ->get();

        $fulfilled = 0;
        $newDate = $shoot->scheduled_date?->toDateString();
        $newTimeKey = static::normalizeClockTime($shoot->time);

        foreach ($pending as $request) {
            if (! static::matchesManualSchedule($request, $newDate, $newTimeKey)) {
                continue;
            }

            $request->status = self::STATUS_APPROVED;
            $request->reviewed_at = now();
            $request->applied_at = $request->applied_at ?? now();
            $request->approved_by = $actor?->id;
            $request->review_notes = $newDate
                ? "Fulfilled by manual schedule change to {$newDate}."
                : 'Fulfilled by manual schedule change.';
            $request->save();
            $fulfilled++;
        }

        return $fulfilled;
    }

    /**
     * True when the shoot's new schedule already realizes this request.
     */
    public static function matchesManualSchedule(
        self $request,
        ?string $newDate,
        ?string $newTimeKey
    ): bool {
        $requestedDate = $request->requested_date?->toDateString();
        if ($requestedDate === null || $newDate === null || $requestedDate !== $newDate) {
            return false;
        }

        $requestedTimeKey = static::normalizeClockTime($request->requested_time);
        // Request with no usable time: date match alone is enough.
        if ($requestedTimeKey === null) {
            return true;
        }

        return $newTimeKey !== null && $requestedTimeKey === $newTimeKey;
    }

    /**
     * Normalize booking clock strings ("2:30 PM", "14:30", "14:30:00") to H:i.
     */
    public static function normalizeClockTime(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $parsed = date_parse($trimmed);
        if (($parsed['error_count'] ?? 0) > 0 || ($parsed['hour'] === false) || $parsed['hour'] === null) {
            return null;
        }

        $hour = (int) $parsed['hour'];
        $minute = (int) ($parsed['minute'] ?? 0);
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

}
