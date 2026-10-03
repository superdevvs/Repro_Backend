<?php

namespace App\Services\Aryeo;

use App\Models\AryeoConnection;
use App\Models\AryeoJob;
use App\Models\AryeoRequest;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AryeoJobs
{
    public const STEPS = ['upload', 'delivery', 'completion_email', 'forwarding', 'summary_forwarding', 'filing'];

    public function __construct(private AryeoCatalog $catalog) {}

    public function enqueue(AryeoRequest $order, AryeoConnection $connection, int $userId): AryeoJob
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($order, $connection, $userId) {
            abort_unless($connection->enabled && $connection->processing_enabled, 409, 'Delivery is disabled for this connection.');
            abort_unless($connection->last_seen_at?->gt(now()->subMinutes(2)), 409, 'Aryeo worker is offline.');
            abort_unless(data_get($connection->capabilities, 'processing_mode') === 'dashboard_jobs'
                && data_get($connection->capabilities, 'shoot_executor') === true, 409, 'Worker is not in dashboard-job mode.');
            $order->refresh();
            abort_unless(in_array((int) $order->shoot_id, $connection->delivery_shoot_ids ?? [], true), 409, 'This shoot is not enabled for delivery.');
            abort_unless($order->match_status === 'matched' && $order->request_id, 409, 'An exact Aryeo request match is required.');
            $current = AryeoJob::where('request_record_id', $order->id)->whereNotIn('status', ['completed', 'cancelled'])->first();
            // Network retries and further clicks always reuse an unfinished job.
            if ($current) {
                return $current;
            }
            $shoot = $this->catalog->shoot($connection, $order->shoot_id);
            $ready = $this->catalog->readiness($shoot, $order->unit_id, $order->discovery['required'] ?? null);
            abort_unless($ready['eligible'], 409, implode(', ', $ready['blockers']));
            $key = hash('sha256', $connection->id.'|'.$order->request_id.'|'.$ready['media_version']);

            return AryeoJob::firstOrCreate(['operation_key' => $key], [
                'id' => (string) Str::uuid(), 'request_record_id' => $order->id, 'connection_id' => $connection->id,
                'media_version' => $ready['media_version'], 'created_by' => $userId, 'status' => 'queued',
                'snapshot' => [...$ready, 'request_id' => $order->request_id, 'listing_id' => $order->listing_id,
                    'source_id' => $order->source_id, 'identity' => $this->catalog->identity($shoot)],
                'steps' => array_fill_keys(self::STEPS, 'pending'),
            ]);
        }), 'aryeo.enqueue');
    }

    public function claim(AryeoConnection $connection, string $claimId, string $leaseToken): ?AryeoJob
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($connection, $claimId, $leaseToken) {
            abort_unless($connection->processing_enabled
                && data_get($connection->capabilities, 'processing_mode') === 'dashboard_jobs'
                && data_get($connection->capabilities, 'shoot_executor') === true, 409, 'Worker processing is disabled.');
            $same = AryeoJob::where('connection_id', $connection->id)->where('claim_id', $claimId)->first();
            if ($same) {
                abort_unless(hash_equals((string) $same->lease_hash, hash('sha256', $leaseToken)), 409, 'Claim ID already used.');
                $this->catalog->shoot($connection, $same->snapshot['shoot_id']);

                return $same;
            }
            // One browser executor per connection: separate orders still share
            // the Mac's signed-in tabs and must not drive them concurrently.
            if (AryeoJob::where('connection_id', $connection->id)->whereIn('status', ['running', 'reconciling'])->where('lease_expires_at', '>', now())->exists()) {
                return null;
            }
            $job = AryeoJob::where('connection_id', $connection->id)->where(function ($q) {
                $q->where('status', 'queued')->orWhere(function ($q) {
                    $q->whereIn('status', ['running', 'reconciling'])->where('lease_expires_at', '<', now());
                });
            })->oldest()->first();
            if (! $job) {
                return null;
            }
            $this->catalog->shoot($connection, $job->snapshot['shoot_id']);
            $recovery = $job->attempts > 0;
            $job->update(['status' => $recovery ? 'reconciling' : 'running', 'claim_id' => $claimId,
                'lease_hash' => hash('sha256', $leaseToken), 'lease_expires_at' => now()->addSeconds(90), 'attempts' => $job->attempts + 1]);

            return $job;
        }), 'aryeo.claim');
    }

    public function leased(AryeoConnection $connection, string $id, string $token): AryeoJob
    {
        $job = AryeoJob::where('connection_id', $connection->id)->findOrFail($id);
        $this->catalog->shoot($connection, $job->snapshot['shoot_id']);
        abort_unless($job->lease_hash && hash_equals($job->lease_hash, hash('sha256', $token)), 409, 'Lease ownership lost.');
        abort_unless(in_array($job->status, ['running', 'reconciling']) && $job->lease_expires_at?->isFuture(), 409, 'Lease expired or job stopped.');

        return $job;
    }

    public function authorizeDelivery(AryeoJob $job, AryeoConnection $connection): array
    {
        abort_unless($job->status === 'running' && $connection->processing_enabled
            && data_get($connection->capabilities, 'processing_mode') === 'dashboard_jobs'
            && data_get($connection->capabilities, 'shoot_executor') === true, 409, 'Reconciliation or worker setup is required before proceeding.');
        abort_if(($job->steps['delivery'] ?? '') === 'success', 409, 'Delivery is already confirmed; resume only unfinished follow-up steps.');
        abort_unless(in_array((int) $job->snapshot['shoot_id'], $connection->delivery_shoot_ids ?? [], true), 409, 'Delivery is not enabled for this shoot.');
        $order = AryeoRequest::findOrFail($job->request_record_id);
        abort_unless($order->match_status === 'matched' && $order->request_id === $job->snapshot['request_id'], 409, 'Order match changed.');
        abort_unless(($order->discovery['required'] ?? null) === $job->snapshot['required'], 409, 'Order requirements changed; review required.');
        abort_unless($order->inventory_checked_at?->gt(now()->subMinutes(2)) && ($order->inventory['complete'] ?? false), 409, 'Refresh Aryeo inventory before delivery.');
        $ready = $this->catalog->readiness($this->catalog->shoot($connection, $job->snapshot['shoot_id']), $job->snapshot['unit_id'], $order->discovery['required'] ?? null);
        abort_unless($ready['eligible'] && $ready['media_version'] === $job->media_version, 409, 'Readiness or approved media changed; do not deliver.');

        return $ready;
    }

    public function result(AryeoJob $job, array $data): AryeoJob
    {
        $steps = $job->steps;
        foreach ($data['steps'] as $name => $state) {
            abort_if(($steps[$name] ?? null) === 'success' && $state !== 'success', 409, 'Successful steps cannot be reset.');
            $steps[$name] = $state;
        }
        if (($steps['delivery'] ?? '') === 'success') {
            $receipt = $data['receipt'] ?? $job->receipt;
            abort_unless(is_array($receipt) && ! empty($receipt['listing_id']) && ! empty($receipt['verified_at']), 422, 'Verified delivery receipt required.');
            abort_if(! empty($job->snapshot['listing_id']) && $receipt['listing_id'] !== $job->snapshot['listing_id'], 422, 'Receipt is for another listing.');
            $order = AryeoRequest::findOrFail($job->request_record_id);
            abort_if($order->listing_id && $order->listing_id !== $receipt['listing_id'], 422, 'Receipt conflicts with existing listing.');
            abort_if(\Carbon\Carbon::parse($receipt['verified_at'])->lt($job->created_at), 422, 'Receipt verification predates this operation.');
            abort_unless(($receipt['request_id'] ?? null) === $job->snapshot['request_id']
                && ($receipt['media_version'] ?? null) === $job->media_version, 422, 'Receipt identity mismatch.');
            $expected = array_map('strval', array_column($job->snapshot['assets'], 'id'));
            abort_if(array_diff($expected, array_map('strval', $receipt['asset_ids'] ?? [])), 422, 'Delivery receipt is missing approved assets.');
            abort_if(array_diff(array_column($job->snapshot['tours'], 'id'), $receipt['tour_ids'] ?? []), 422, 'Delivery receipt is missing approved tours.');
            $job->receipt = $receipt;
            AryeoRequest::whereKey($job->request_record_id)->update(['listing_id' => $receipt['listing_id']]);
        }
        $allDone = collect(self::STEPS)->every(fn ($s) => in_array($steps[$s] ?? '', ['success', 'not_applicable']));
        $job->steps = $steps;
        $job->status = ($steps['delivery'] ?? '') === 'success' ? ($allDone ? 'completed' : 'followup_pending') : 'failed';
        $job->error = $data['error'] ?? null;
        $job->lease_expires_at = null;
        $job->save();

        return $job;
    }
}
