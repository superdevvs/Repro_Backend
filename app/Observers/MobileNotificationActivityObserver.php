<?php

namespace App\Observers;

use App\Models\ShootActivityLog;
use App\Models\UserActivityLog;
use App\Services\MobileNotifications\MobileNotificationService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class MobileNotificationActivityObserver implements ShouldHandleEventsAfterCommit
{
    public function created(ShootActivityLog|UserActivityLog $event): void
    {
        if (! config('mobile_notifications.enabled')) return;
        $service = app(MobileNotificationService::class);
        if ($event instanceof ShootActivityLog) $service->queueShootActivity($event->id);
        else $service->queueAccountActivity($event->id);
    }
}
