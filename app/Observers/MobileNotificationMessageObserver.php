<?php

namespace App\Observers;

use App\Models\Message;
use App\Services\MobileNotifications\MobileNotificationService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class MobileNotificationMessageObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Message $message): void
    {
        if (config('mobile_notifications.enabled') && $message->channel === 'SMS' && $message->direction === 'INBOUND') {
            app(MobileNotificationService::class)->queueSMS($message->id);
        }
    }

    public function saved(Message $message): void
    {
        // Inbound email may be created before thread assignment/provider acceptance.
        // Reconciliation also catches a thread's later unread-recipient update.
        if (config('mobile_notifications.enabled') && $message->channel === 'EMAIL'
            && $message->direction === 'INBOUND' && in_array($message->status, ['SENT', 'DELIVERED'], true)) {
            app(MobileNotificationService::class)->queueEmail($message->id);
        }
    }
}
