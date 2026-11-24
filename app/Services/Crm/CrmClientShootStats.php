<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Shoot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Real shoot history for contacts linked to a Dashboard client: how many shoots,
 * the first and last completed one. Ignores import drafts, internal test shoots,
 * and cancelled or declined shoots.
 */
class CrmClientShootStats
{
    public const IGNORED_STATUSES = [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED, Shoot::STATUS_IMPORT_DRAFT, 'canceled'];

    /** @param  Collection<int, CrmContact>  $contacts */
    public function forContacts(Collection $contacts): array
    {
        $byClient = $contacts->filter(fn (CrmContact $c) => $c->client_user_id)->keyBy('client_user_id');
        if ($byClient->isEmpty()) {
            return [];
        }

        $rows = DB::table('shoots')
            ->whereIn('client_id', $byClient->keys())
            ->whereNotIn('status', self::IGNORED_STATUSES)
            ->where(fn ($q) => $q->whereNull('shoot_type')->orWhere('shoot_type', '!=', Shoot::SHOOT_TYPE_INTERNAL_TEST))
            ->selectRaw('client_id, COUNT(*) as total, MIN(completed_at) as first_done, MAX(COALESCE(completed_at, scheduled_at)) as last_shoot')
            ->groupBy('client_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $contact = $byClient->get($row->client_id);
            if (! $contact) {
                continue;
            }
            $out[$contact->id] = [
                'total' => (int) $row->total,
                'first_completed' => $row->first_done ? substr((string) $row->first_done, 0, 10) : null,
                'last_shoot' => $row->last_shoot ? substr((string) $row->last_shoot, 0, 10) : null,
            ];
        }

        return $out;
    }

    /** Completed shoot dates for one client, oldest first. Used by client health. */
    public function completedDates(int $clientUserId, int $limit = 60): array
    {
        return DB::table('shoots')
            ->where('client_id', $clientUserId)
            ->whereNotNull('completed_at')
            ->whereNotIn('status', self::IGNORED_STATUSES)
            ->where(fn ($q) => $q->whereNull('shoot_type')->orWhere('shoot_type', '!=', Shoot::SHOOT_TYPE_INTERNAL_TEST))
            ->orderByDesc('completed_at')
            ->limit($limit)
            ->pluck('completed_at')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->reverse()
            ->values()
            ->all();
    }
}
