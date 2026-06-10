<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable Audit_Log facade (Req 12.9, 16.7, 18.4, 19.10).
 *
 * Rather than introduce a parallel generic audit table, this service writes every
 * audited action through the existing UserActivityLog model (table `user_activity_logs`),
 * which was extended in this batch with polymorphic `target_type`/`target_id` columns and a
 * nullable `title` so non-user targets (e.g. a Shoot) can be audited.
 *
 * Every entry has the same uniform shape: an actor (who performed the action), a timestamp
 * (created_at / occurred_at), a target (any model or null), an action, and free-form metadata
 * stored as JSON.
 */
class AuditLogService
{
    /**
     * Record an auditable action.
     *
     * @param  string                      $action    Stable action identifier (e.g. 'notification.manual_send').
     * @param  User|null                   $actor     The user who performed the action, if any.
     * @param  Model|null                  $target    The model the action targets (Shoot, User, ...), or null.
     * @param  array<string, mixed>        $metadata  Action-specific context persisted as JSON.
     */
    public function record(string $action, ?User $actor, ?Model $target, array $metadata = []): UserActivityLog
    {
        $targetType = null;
        $targetId = null;
        $subjectUserId = null;

        if ($target !== null) {
            $targetType = $target->getMorphClass();
            $targetId = $target->getKey();

            // When the target is a user, keep user_id meaningful so existing per-user
            // activity views surface the entry. Non-user targets are addressed solely
            // via the polymorphic target columns.
            if ($target instanceof User) {
                $subjectUserId = $target->getKey();
            }
        }

        return UserActivityLog::create([
            'user_id' => $subjectUserId,
            'actor_user_id' => $actor?->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'event_type' => $action,
            'title' => null,
            'description' => null,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
