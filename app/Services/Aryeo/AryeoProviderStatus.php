<?php

namespace App\Services\Aryeo;

use App\Models\AryeoConnection;
use App\Models\AryeoRequest;
use App\Models\Shoot;
use App\Support\LockedWrite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AryeoProviderStatus
{
    public function record(AryeoConnection $connection, int $id, array $data): array
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($connection, $id, $data) {
            $order = AryeoRequest::where('connection_id', $connection->id)->findOrFail($id);
            abort_unless($order->request_id === $data['request_id'], 409, 'Provider request mismatch.');
            $email = strtolower(trim($order->discovery['requester_email'] ?? ''));
            abort_unless($email !== '' && $email === strtolower(trim($data['requester_email'])), 409, 'Verify the exact provider requester first.');
            if ($order->shoot_id) {
                $shoot = Shoot::with('client')->findOrFail($order->shoot_id);
                abort_if($order->unit_id && ! $shoot->units()->whereKey($order->unit_id)->exists(), 409, 'Matched unit changed; review required.');
                if (! in_array((int) $shoot->client_id, $connection->client_ids ?? [], true)) {
                    abort_unless($connection->discover_all_clients, 404);
                    $discovery = app(AryeoDiscovery::class);
                    abort_unless($order->match_status === 'matched'
                        && ($discovery->hasConfirmedMatch($order, $shoot, $order->discovery)
                            || ($email === strtolower(trim((string) $shoot->client?->email))
                                && $discovery->matchesStreet($shoot, $order->discovery))), 409, 'Matched client changed; review required.');
                }
            } else {
                abort_unless($connection->discover_all_clients, 404);
            }
            $listing = $data['listing_id'] ?? $order->listing_id;
            abort_if($order->listing_id && $listing !== $order->listing_id, 409, 'Existing listing cannot be replaced.');
            abort_if($data['delivery_status'] === 'delivered' && ! $listing, 422, 'A verified listing ID is required for delivered status.');
            $counts = fn ($values) => collect(['photos', 'floorplans', 'videos', 'tours'])
                ->mapWithKeys(fn ($kind) => [$kind => isset($values[$kind]) ? (int) $values[$kind] : null])->all();
            $status = ['origin' => 'aryeo_provider', 'request_id' => $order->request_id,
                'requester_email' => $email, 'listing_id' => $listing,
                'checked_at' => Carbon::parse($data['checked_at'])->utc()->toISOString(),
                'delivery_status' => $data['delivery_status'], 'counts' => $counts($data['counts']),
                'delivered_counts' => isset($data['delivered_counts']) ? $counts($data['delivered_counts']) : null];
            foreach ($status['delivered_counts'] ?? [] as $kind => $count) {
                abort_if($count !== null && $status['counts'][$kind] !== null && $count > $status['counts'][$kind], 422, 'Delivered count exceeds provider inventory.');
            }
            $existing = $order->inventory['provider_status'] ?? null;
            if (isset($existing['checked_at'])) {
                $previous = Carbon::parse($existing['checked_at']);
                $incoming = Carbon::parse($status['checked_at']);
                abort_if($previous->gt($incoming) || ($previous->eq($incoming) && $existing !== $status), 409, 'Older or conflicting provider observations cannot replace newer evidence.');
            }
            // This is provider metadata, never a media manifest, job receipt,
            // execution grant, or evidence that every Dashboard file was sent.
            $order->update(['listing_id' => $listing, 'inventory' => [...($order->inventory ?? []), 'provider_status' => $status]]);

            return ['id' => $order->id, 'request_id' => $order->request_id, 'listing_id' => $order->listing_id, 'provider_status' => $status];
        }), 'aryeo.provider_status');
    }
}
