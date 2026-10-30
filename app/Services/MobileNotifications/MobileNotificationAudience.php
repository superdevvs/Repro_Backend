<?php

namespace App\Services\MobileNotifications;

use App\Models\Message;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Messaging\ClientNotificationDeliveryWindow;
use App\Services\Messaging\DashboardMessagingPolicy;
use App\Services\NotificationReadStateService;
use App\Services\RolePermissionService;
use App\Services\Shoots\ShootEditingAssignmentService;
use App\Services\SupportTicketService;
use Carbon\CarbonImmutable;

/** Fresh persisted ownership and receipts; never an HTTP/auth-global notification feed. */
class MobileNotificationAudience
{
    private const CLIENT_ACTIONS = ['shoot_requested', 'shoot_created', 'shoot_scheduled', 'shoot_approved',
        'shoot_started', 'shoot_completed', 'shoot_delivered', 'shoot_cancelled', 'shoot_put_on_hold',
        'hold_requested', 'hold_approved', 'hold_rejected', 'shoot_submitted_for_review', 'payment_done', 'media_uploaded'];
    private const PHOTOGRAPHER_ACTIONS = ['shoot_created', 'shoot_scheduled', 'shoot_approved', 'shoot_started',
        'shoot_completed', 'shoot_cancelled', 'shoot_put_on_hold', 'hold_approved', 'media_uploaded'];
    private const EDITOR_ACTIONS = ['shoot_editing_started', 'shoot_submitted_for_review', 'media_uploaded', 'hold_approved'];
    private const ACCOUNT_ACTIONS = ['email_bounced', 'email_delivery_risky', 'email_verification_requested',
        'email_corrected_after_bounce', 'email_verified'];

    public function pushEnabled(User $user): bool
    {
        $value = data_get($user->metadata, 'preferences.notificationSettings.push');
        return $value === null || filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    public function allowed(User $user, string $kind, string $id): bool
    {
        return match ($kind) {
            'email' => $this->email($user, $id),
            'support' => $this->support($user, $id),
            'shoot_activity' => $this->shoot($user, $id),
            'account_activity' => $this->account($user, $id),
            default => false,
        };
    }

    private function unread(User $user, string $receiptID, mixed $createdAt): bool
    {
        if (! $createdAt) return false;
        $service = app(NotificationReadStateService::class);
        $state = $service->forUser($user);
        return ! array_key_exists($receiptID, (array) $state['readIds'])
            && $createdAt->gt($service->unreadHistoryFloor($state))
            && (! is_numeric($state['lastSeenAt']) || $createdAt->getTimestampMs() > (int) $state['lastSeenAt']);
    }

    private function email(User $user, string $id): bool
    {
        if (! app(DashboardMessagingPolicy::class)->canEmail($user)) return false;
        $message = Message::find($id);
        return $message && $message->channel === 'EMAIL' && $message->direction === 'INBOUND'
            && in_array($message->status, ['SENT', 'DELIVERED'], true) && ! $message->hidden_from_inbox
            && (int) $message->sender_user_id !== (int) $user->id
            && $message->send_source !== 'INTERNAL_MESSAGE_NOTIFICATION'
            && ! SupportTicketMessage::where('source_message_id', $message->id)->exists()
            && $message->thread && $message->thread->channel === 'EMAIL'
            && $message->thread->isUnreadForUser($user->id)
            && $this->unread($user, 'email-'.$message->id, $message->created_at);
    }

    private function support(User $user, string $id): bool
    {
        if (! app(RolePermissionService::class)->userCan($user, 'support', 'view')) return false;
        $message = SupportTicketMessage::find($id);
        if (! $message || (int) $message->author_id === (int) $user->id) return false;
        $service = app(SupportTicketService::class);
        return $service->visible($user)->whereKey($message->support_ticket_id)->exists()
            && (! $message->internal || $service->canManage($user))
            && $this->unread($user, 'support-'.$message->id, $message->created_at);
    }

    /** Match DashboardController's primary-role activity scope and action allowlists. */
    private function shoot(User $user, string $id): bool
    {
        $log = ShootActivityLog::with('shoot')->find($id);
        if (! $log?->shoot || data_get($log->metadata, 'suppress_notifications', false)
            || ((int) $log->user_id === (int) $user->id && ! str_ends_with($log->action, '_failed'))
            || ! $this->unread($user, 'sa-'.$log->id, $log->created_at)) return false;
        $role = $this->role($user);
        if (in_array($role, ['admin', 'superadmin', 'editing_manager', 'salesrep'], true)) return true;
        if ($role === 'client') {
            return (int) $log->shoot->client_id === (int) $user->id && in_array($log->action, self::CLIENT_ACTIONS, true);
        }
        if ($role === 'photographer' && in_array($log->action, self::PHOTOGRAPHER_ACTIONS, true)) {
            return (int) $log->shoot->photographer_id === (int) $user->id
                || $log->shoot->servicePhotographers()->where('users.id', $user->id)->exists();
        }
        if ($role === 'editor' && in_array($log->action, self::EDITOR_ACTIONS, true)) {
            return app(ShootEditingAssignmentService::class)->scopeAssignedToEditor(Shoot::whereKey($log->shoot_id), $user->id)->exists();
        }
        return false;
    }

    private function account(User $user, string $id): bool
    {
        $log = UserActivityLog::find($id);
        if (! $log || ! $log->user || ! $this->unread($user, 'email-issue-'.$log->id, $log->occurred_at ?? $log->created_at)) return false;
        $role = $this->role($user);
        $staff = in_array($role, ['admin', 'superadmin', 'editing_manager'], true);
        if (! in_array($log->event_type, self::ACCOUNT_ACTIONS, true) && ! ($staff && $log->event_type === 'account_created')) return false;
        return $staff || ($role === 'client' && (int) $log->user_id === (int) $user->id)
            || ($role === 'salesrep' && (int) data_get($log->metadata, 'sales_rep_id', 0) === (int) $user->id);
    }

    /** Routine client completion/delivery alerts use the existing local-morning policy. */
    public function notBefore(User $user, string $kind, string $id): ?CarbonImmutable
    {
        if ($kind !== 'shoot_activity' || $this->role($user) !== 'client') return null;
        $log = ShootActivityLog::find($id);
        if (! $log || ! in_array($log->action, ['shoot_completed', 'shoot_delivered'], true)) return null;
        // This unsaved purpose descriptor is only evaluated by the timing policy;
        // it never enters the email/SMS dispatch pipeline or changes business state.
        $purpose = new Message(['channel' => 'PUSH', 'direction' => 'OUTBOUND', 'provider' => 'MOBILE_PUSH',
            'to_address' => $user->email, 'related_shoot_id' => $log->shoot_id, 'send_source' => strtoupper($log->action)]);
        return app(ClientNotificationDeliveryWindow::class)->nextAllowedAt($purpose);
    }

    private function role(User $user): string
    {
        $role = strtolower(str_replace('-', '_', (string) $user->role));
        return in_array($role, ['sales_rep', 'rep', 'representative'], true) ? 'salesrep' : $role;
    }
}
