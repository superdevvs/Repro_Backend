<?php

namespace App\Services\Aryeo;

use App\Models\AryeoConnection;
use App\Models\AryeoJob;
use App\Models\AryeoRequest;

class AryeoDiscovery
{
    public function __construct(private AryeoCatalog $catalog) {}

    public function record(AryeoConnection $connection, array $data): AryeoRequest
    {
        $order = ! empty($data['request_id']) ? AryeoRequest::where('connection_id', $connection->id)->where('request_id', $data['request_id'])->first() : null;
        $order ??= AryeoRequest::firstOrNew(['connection_id' => $connection->id, 'source_id' => $data['source_id']]);
        if ($order->shoot_id) {
            $this->catalog->shoot($connection, $order->shoot_id);
        }
        $identityChanged = $order->exists && collect(['address', 'requester_email', 'unit_label', 'scheduled_date'])
            ->contains(fn ($key) => strtolower(trim((string) ($order->discovery[$key] ?? ''))) !== strtolower(trim((string) ($data[$key] ?? ''))));
        if ($identityChanged) {
            abort_if(AryeoJob::where('request_record_id', $order->id)->exists(), 409, 'Request identity changed after processing; review required.');
            $order->fill(['shoot_id' => null, 'unit_id' => null, 'match_status' => 'needs_matching']);
        }
        if ($order->exists && AryeoJob::where('request_record_id', $order->id)->exists()) {
            abort_if(($data['request_id'] ?? $order->request_id) !== $order->request_id, 409, 'Request identity is immutable after a job exists.');
            abort_if($order->listing_id && isset($data['listing_id']) && $data['listing_id'] !== $order->listing_id, 409, 'Existing listing cannot be replaced.');
        }
        $order->fill(['request_id' => $data['request_id'] ?? $order->request_id,
            'listing_id' => $data['listing_id'] ?? $order->listing_id, 'discovery' => $data]);
        if (! $order->shoot_id) {
            $normalize = fn ($s) => strtolower(trim(preg_replace('/\s+/', ' ', (string) $s)));
            $candidates = $this->catalog->query($connection)->whereHas('client', fn ($q) => $q->whereRaw('lower(email) = ?', [strtolower($data['requester_email'])]))
                ->with(['client', 'units'])->get()->filter(fn ($s) => $this->normalizeStreet($s->address) === $this->normalizeStreet($data['address'])
                    && (! isset($data['scheduled_date']) || $s->scheduled_date?->format('Y-m-d') === $data['scheduled_date']));
            if ($candidates->count() === 1) {
                $shoot = $candidates->first();
                $unit = isset($data['unit_label']) ? $shoot->units->first(fn ($u) => $normalize($u->label) === $normalize($data['unit_label'])) : null;
                if ($shoot->units->count() <= 1 || $unit) {
                    $order->shoot_id = $shoot->id;
                    $order->unit_id = $unit?->id ?? $shoot->units->first()?->id;
                    $order->match_status = 'matched';
                } else {
                    $order->match_status = 'needs_matching';
                }
            } else {
                $order->match_status = $candidates->isEmpty() ? 'unmatched' : 'needs_matching';
            }
        }
        $order->save();

        return $order;
    }

    private function normalizeStreet(?string $address): string
    {
        $value = strtolower(trim(preg_replace('/\s+/', ' ', (string) $address)));

        // Aryeo uses Dr/Rd while the dashboard stores Drive/Road. Only expand
        // a trailing street suffix; email, unit, date and uniqueness still gate matching.
        return preg_replace_callback('/\b(dr|rd)\.?$/', fn ($m) => $m[1] === 'dr' ? 'drive' : 'road', $value);
    }
}
