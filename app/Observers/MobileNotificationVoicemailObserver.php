<?php

namespace App\Observers;

use App\Models\VoiceVoicemail;
use App\Services\MobileNotifications\MobileNotificationService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class MobileNotificationVoicemailObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(VoiceVoicemail $voicemail): void
    {
        if (config('mobile_notifications.enabled') && $voicemail->saved_at && ($voicemail->wasRecentlyCreated || $voicemail->wasChanged('saved_at'))) {
            app(MobileNotificationService::class)->queueVoicemail($voicemail->id);
        }
    }
}
