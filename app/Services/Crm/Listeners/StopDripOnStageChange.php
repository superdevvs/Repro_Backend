<?php

namespace App\Services\Crm\Listeners;

use App\Events\Crm\CrmContactReplied;
use App\Events\Crm\CrmContactStageChanged;
use App\Events\Crm\CrmEmailBlocked;
use App\Models\Crm\CrmContact;
use App\Models\User;
use App\Services\Crm\Email\CrmDripService;
use Illuminate\Support\Facades\Schema;

/** A drip stops when the contact replies, unsubscribes, bounces, books a shoot or is marked lost. */
class StopDripOnStageChange
{
    public function handle(CrmContactStageChanged|CrmEmailBlocked|CrmContactReplied $event): void
    {
        if (! Schema::hasTable('crm_drip_enrollments')) {
            return;
        }
        $drips = app(CrmDripService::class);

        if ($event instanceof CrmContactStageChanged) {
            $reason = match ($event->toStage) {
                CrmContact::STAGE_BOOKED, CrmContact::STAGE_CLIENT_POOL => 'booked',
                CrmContact::STAGE_LOST => 'lost',
                default => null,
            };
            if ($reason) {
                $drips->stopForContact($event->contact, $reason, $event->actorId ? User::query()->find($event->actorId) : null);
            }

            return;
        }

        if ($event instanceof CrmEmailBlocked) {
            $drips->stopForContact($event->contact, match ($event->reason) {
                'unsubscribe' => 'unsubscribe',
                'complaint' => 'complaint',
                default => 'bounce',
            });

            return;
        }

        $drips->stopForContact($event->contact, 'reply');
    }
}
