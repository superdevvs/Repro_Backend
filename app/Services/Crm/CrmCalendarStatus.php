<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Whether a user's own Google Calendar is connected, for the follow-ups header. */
class CrmCalendarStatus
{
    public function for(int $userId): array
    {
        if (! Schema::hasTable('google_calendar_connections')) {
            return ['connected' => false, 'email' => null, 'sync_enabled' => false, 'crm_sync' => false];
        }
        $row = DB::table('google_calendar_connections')->where('user_id', $userId)->first();

        return [
            'connected' => $row !== null,
            'email' => $row->provider_email ?? null,
            'sync_enabled' => (bool) ($row->sync_enabled ?? false),
            'crm_sync' => (bool) config('crm.google_sync.enabled'),
        ];
    }
}
