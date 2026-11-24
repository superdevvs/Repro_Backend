<?php

namespace App\Services\Crm\Listeners;

use App\Events\Crm\CrmContactStageChanged;
use App\Models\Crm\CrmContact;
use App\Models\User;
use App\Services\Crm\CrmEventService;
use Illuminate\Support\Facades\Schema;

/** A lost contact's planned follow-ups are cancelled, so they stop appearing as due. */
class CancelEventsWhenLost
{
    public function handle(CrmContactStageChanged $event): void
    {
        if ($event->toStage !== CrmContact::STAGE_LOST || ! Schema::hasTable('crm_events')) {
            return;
        }
        $actor = $event->actorId ? User::query()->find($event->actorId) : null;
        app(CrmEventService::class)->cancelPlanned($event->contact, $actor, 'contact marked lost');
    }
}
