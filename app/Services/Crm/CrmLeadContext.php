<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmActivity;
use App\Models\Crm\CrmContact;

/** The latest inbound message on a lead (website inquiry or booking), for the Unassigned queue. */
class CrmLeadContext
{
    public function latestInquiry(CrmContact $contact): ?array
    {
        $activity = CrmActivity::query()
            ->where('contact_id', $contact->id)
            ->whereIn('type', ['website_inquiry', 'website_booking'])
            ->orderByDesc('occurred_at')
            ->first();

        if (! $activity) {
            return null;
        }

        return [
            'type' => $activity->type,
            'message' => $activity->data['message'] ?? $activity->summary,
            'received_at' => $activity->occurred_at?->toIso8601String(),
        ];
    }
}
