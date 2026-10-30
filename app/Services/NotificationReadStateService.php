<?php

namespace App\Services;

use App\Models\User;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class NotificationReadStateService
{
    public const RECEIPT_RETENTION_DAYS = 90;

    public const MAX_READ_IDS = 10000;

    /**
     * Badge history must not outlive the evidence that can distinguish read
     * from unread. A full map may have evicted receipts across any channel;
     * retire events at/before its oldest retained read time as well. This is
     * a badge-history boundary, not a fabricated read receipt or watermark.
     */
    public function unreadHistoryFloor(array $state): Carbon
    {
        $now = (int) now()->getTimestampMs();
        $floor = $now - self::RECEIPT_RETENTION_DAYS * 86400000;
        $receipts = array_filter((array) ($state['readIds'] ?? []), fn ($stamp) => is_numeric($stamp));
        if (count($receipts) >= self::MAX_READ_IDS) {
            $floor = max($floor, min($now, (int) min($receipts)));
        }

        return Carbon::createFromTimestampMs($floor);
    }

    private function context(Request $request): string
    {
        $actor = $request->attributes->get('original_admin_user');

        // An impersonator's reading must not clear the real user's notifications.
        return $this->roleContext($request->user()).($request->attributes->get('is_impersonating') && $actor ? ':imp:'.$actor->id : '');
    }

    private function roleContext(User $user): string
    {
        $role = strtolower(str_replace('-', '_', (string) $user->role));

        return in_array($role, ['sales_rep', 'rep', 'representative'], true) ? 'salesrep' : $role;
    }

    /** Non-HTTP consumers use the account's own receipts, never an impersonator's. */
    public function forUser(User $user): array
    {
        return $this->format(DB::table('notification_read_states')->where('user_id', $user->id)
            ->where('context', $this->roleContext($user))->first());
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
        $now = (int) now()->getTimestampMs();

        return LockedWrite::run(fn () => DB::transaction(function () use ($userId, $context, $ids, $watermark, $now) {
            DB::table('notification_read_states')->insertOrIgnore([
                'user_id' => $userId, 'context' => $context, 'last_seen_at' => null,
                'read_ids' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $query = DB::table('notification_read_states')->where('user_id', $userId)->where('context', $context);
            $row = (clone $query)->lockForUpdate()->first();
            $readIds = json_decode($row->read_ids, true) ?: [];
            // Keep a bounded receipt history; the watermark remains permanent.
            $readIds = array_filter($readIds, fn ($stamp) => is_numeric($stamp) && $stamp >= $now - self::RECEIPT_RETENTION_DAYS * 86400000);
            foreach ($ids as $id) {
                $readIds[$id] = $now;
            }
            arsort($readIds);
            $readIds = array_slice($readIds, 0, self::MAX_READ_IDS, true);
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
