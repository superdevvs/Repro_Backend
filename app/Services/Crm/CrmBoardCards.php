<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Compact contact cards for the pipeline board. The footer line shows the most
 * useful next thing: an overdue follow-up, the next event, the drip, or the
 * absence of any follow-up.
 */
class CrmBoardCards
{
    public function __construct(private readonly CrmPresenter $presenter) {}

    /** @param  Collection<int, CrmContact>  $contacts */
    public function cards(Collection $contacts): array
    {
        $ids = $contacts->pluck('id')->all();
        $nextEvents = $this->nextEvents($ids);
        $drips = $this->drips($ids);

        return $contacts->map(function (CrmContact $c) use ($nextEvents, $drips) {
            $event = $nextEvents[$c->id] ?? null;
            $drip = $drips[$c->id] ?? null;

            $footer = match (true) {
                $event && $event['overdue'] => ['kind' => 'overdue', 'icon' => $event['icon'], 'text' => $event['label'].' overdue '.$event['late']],
                $c->stage === CrmContact::STAGE_BOOKED && $event && $event['type_key'] === 'shoot' => ['kind' => 'event', 'icon' => 'camera', 'text' => 'First shoot · '.$event['when']],
                $event !== null => ['kind' => 'event', 'icon' => $event['icon'], 'text' => $event['label'].' · '.$event['when']],
                $drip !== null => ['kind' => 'drip', 'icon' => 'mail', 'text' => $drip],
                default => ['kind' => 'none', 'icon' => 'calendar', 'text' => 'No follow-up scheduled'],
            };

            return [
                'id' => $c->id,
                'name' => $c->name,
                'company' => $c->company,
                'segment' => $c->segment,
                'market' => CrmPresenter::market($c),
                'stage' => $c->stage,
                'lead_source' => $c->lead_source,
                'is_new_website_lead' => $c->lead_source === 'website' && $c->first_contact_due_at !== null,
                'assigned_rep' => $this->presenter->user($c->assigned_rep_id),
                'footer' => $footer,
            ];
        })->values()->all();
    }

    private function nextEvents(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('crm_events')) {
            return [];
        }

        return app(CrmEventPresenter::class)->nextForMany($ids);
    }

    private function drips(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('crm_drip_enrollments')) {
            return [];
        }

        return app(\App\Services\Crm\Email\CrmDripPresenter::class)->shortLabels($ids);
    }
}
