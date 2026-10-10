<?php

namespace App\Services;

use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NotificationReadStateService
{
    private function context(Request $request): string
    {
        $role = strtolower(str_replace('-', '_', (string) $request->user()->role));
        $role = in_array($role, ['sales_rep', 'rep', 'representative'], true) ? 'salesrep' : $role;
        $actor = $request->attributes->get('original_admin_user');

        // An impersonator's reading must not clear the real user's notifications.
        return $role.($request->attributes->get('is_impersonating') && $actor ? ':imp:'.$actor->id : '');
    }

    public function get(Request $request): array
    {
        $row = DB::table('notification_read_states')->where('user_id', $request->user()->id)
            ->where('context', $this->context($request))->first();

        return $this->format($row);
    }

    public function merge(Request $request, array $ids, ?int $watermark): array
    {
        $userId = $request->user()->id;
        $context = $this->context($request);
        $now = (int) floor(microtime(true) * 1000);

        return LockedWrite::run(fn () => DB::transaction(function () use ($userId, $context, $ids, $watermark, $now) {
            DB::table('notification_read_states')->insertOrIgnore([
                'user_id' => $userId, 'context' => $context, 'last_seen_at' => null,
                'read_ids' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $query = DB::table('notification_read_states')->where('user_id', $userId)->where('context', $context);
            $row = (clone $query)->lockForUpdate()->first();
            $readIds = json_decode($row->read_ids, true) ?: [];
            // Keep a bounded receipt history; the watermark remains permanent.
            $readIds = array_filter($readIds, fn ($stamp) => is_numeric($stamp) && $stamp >= $now - 90 * 86400000);
            foreach ($ids as $id) {
                $readIds[$id] = $now;
            }
            arsort($readIds);
            $readIds = array_slice($readIds, 0, 10000, true);
            $lastSeen = $row->last_seen_at === null ? null : (int) $row->last_seen_at;
            if ($watermark !== null) {
                $lastSeen = max($lastSeen ?? 0, min($watermark, $now));
            }
            $query->update(['read_ids' => json_encode((object) $readIds), 'last_seen_at' => $lastSeen, 'updated_at' => now()]);

            return ['lastSeenAt' => $lastSeen, 'readIds' => (object) $readIds];
        }), 'notifications.read-state');
    }

    private function format(?object $row): array
    {
        return [
            'lastSeenAt' => $row?->last_seen_at === null ? null : (int) $row->last_seen_at,
            'readIds' => (object) ($row ? (json_decode($row->read_ids, true) ?: []) : []),
        ];
    }
}
