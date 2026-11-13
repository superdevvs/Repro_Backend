<?php

namespace App\Services\Messaging;

use App\Http\Controllers\API\Messaging\EmailMessagingController;
use App\Models\{Message, SmsSharedMessage, SupportTicketMessage, User};
use App\Services\SupportTicketService;

class MessageContentAccess
{
    public function channel(User $user, string $channel, bool $write = false): void
    {
        abort_unless($user->isAccountEligibleForAuthentication(), 403);
        match ($channel) {
            'sms' => abort_unless(app(SmsInboxPolicy::class)->canSms($user), 403),
            'shared' => app(SharedMmsService::class)->authorize($user),
            'email' => app(DashboardMessagingPolicy::class)->authorizeEmail($user, $write),
            'support' => abort_unless(app(\App\Services\RolePermissionService::class)->userCan($user, 'support', 'view'), 403),
            default => abort(422, 'Choose a messaging channel.'),
        };
    }

    public function message(User $user, string $kind, string $id, bool $write = false): Message|SmsSharedMessage|SupportTicketMessage
    {
        $this->channel($user, $kind, $write);
        if ($kind === 'shared') {
            $m = SmsSharedMessage::findOrFail($id);
            app(SharedMmsService::class)->authorize($user, $m->group);
        } elseif ($kind === 'support') {
            $m = SupportTicketMessage::findOrFail($id);
            $service = app(SupportTicketService::class);
            $service->find($user, $m->support_ticket_id);
            abort_if($m->internal && ! $service->canManage($user), 404);
        } else {
            $m = Message::where('channel', strtoupper($kind))->findOrFail($id);
            abort_unless($kind === 'sms'
                ? $m->thread && app(SmsInboxPolicy::class)->canView($user, $m->thread)
                : app(EmailMessagingController::class)->canAccessMessage($user, $m), 404);
        }
        return $m;
    }
}
