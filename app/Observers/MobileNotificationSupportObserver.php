<?php

namespace App\Observers;

use App\Models\SupportTicketMessage;
use App\Services\MobileNotifications\MobileNotificationService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class MobileNotificationSupportObserver implements ShouldHandleEventsAfterCommit
{
    public function created(SupportTicketMessage $message): void
    {
        if (config('mobile_notifications.enabled')) app(MobileNotificationService::class)->queueSupport($message->id);
    }
}
