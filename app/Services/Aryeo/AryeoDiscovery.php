<?php

namespace App\Services\Aryeo;

use App\Models\AryeoConnection;
use App\Models\AryeoJob;
use App\Models\AryeoRequest;
use App\Models\Shoot;

class AryeoDiscovery
{
    public function __construct(private AryeoCatalog $catalog) {}

    public function record(AryeoConnection $connection, array $data): AryeoRequest
    {
        $data['requester_email'] ??= null;
        $order = ! empty($data['request_id']) ? AryeoRequest::where('connection_id', $connection->id)->where('request_id', $data['request_id'])->first() : null;
        $order ??= AryeoRequest::firstOrNew(['connection_id' => $connection->id, 'source_id' => $data['source_id']]);
        $known = $order->discovery ?? [];
        if ($order->exists && empty($data['requester_email']) && ! empty($known['requester_email'])
            && ! empty($known['requester_name']) && ($data['request_id'] ?? null) === $order->request_id
            && strtolower(trim($data['requester_name'] ?? '')) === strtolower(trim($known['requester_name']))
            && strtolower(trim($data['address'])) === strtolower(trim($known['address'] ?? ''))) {
            // A mail-only retry means unknown provider fields, not evidence
            // that the previously verified requester changed. Preserve them
            // only for this exact request, requester name, and street.
            foreach (['requester_email', 'city', 'state', 'zip'] as $key) {
                if (empty($data[$key]) && isset($known[$key])) {
                    $data[$key] = $known[$key];
                }
            }
        }
        if ($order->shoot_id) {
            $shoot = $this->matchingQuery($connection)->with('client')->findOrFail($order->shoot_id);
            // A metadata-only match must stop following a shoot reassigned to
            // a different client. Explicit existing in-scope aliases retain
            // their separately approved requester identity.
            if (! in_array((int) $shoot->client_id, $connection->client_ids ?? [], true)
                && strtolower(trim((string) $shoot->client?->email)) !== strtolower(trim((string) $data['requester_email']))
                && ! $this->hasConfirmedMatch($order, $shoot, $data)) {
                abort_if(AryeoJob::where('request_record_id', $order->id)->exists(), 409, 'Matched client changed after processing; review required.');
                $order->fill(['shoot_id' => null, 'unit_id' => null, 'match_status' => 'needs_matching', 'match_verification' => null]);
            }
        }
        $identityChanged = $order->exists && collect(['address', 'requester_email', 'unit_label', 'scheduled_date', 'city', 'state', 'zip'])
            ->contains(fn ($key) => strtolower(trim((string) ($order->discovery[$key] ?? ''))) !== strtolower(trim((string) ($data[$key] ?? ''))));
        if ($identityChanged) {
            abort_if(AryeoJob::where('request_record_id', $order->id)->exists(), 409, 'Request identity changed after processing; review required.');
            $order->fill(['shoot_id' => null, 'unit_id' => null, 'match_status' => 'needs_matching', 'match_verification' => null]);
        }
        if ($order->exists && AryeoJob::where('request_record_id', $order->id)->exists()) {
            abort_if(($data['request_id'] ?? $order->request_id) !== $order->request_id, 409, 'Request identity is immutable after a job exists.');
            abort_if($order->listing_id && isset($data['listing_id']) && $data['listing_id'] !== $order->listing_id, 409, 'Existing listing cannot be replaced.');
        }
        $order->fill(['request_id' => $data['request_id'] ?? $order->request_id,
            'listing_id' => $data['listing_id'] ?? $order->listing_id, 'discovery' => $data]);
        if (! $order->shoot_id) {
            $normalize = fn ($s) => strtolower(trim(preg_replace('/\s+/', ' ', (string) $s)));
            $candidates = empty($data['requester_email']) ? collect() : $this->matchingQuery($connection)
                ->whereHas('client', fn ($q) => $q->whereRaw('lower(email) = ?', [strtolower(trim($data['requester_email']))]))
                ->with(['client', 'units'])->get()->filter(fn ($s) => $this->matchesStreet($s, $data)
                    && (! isset($data['scheduled_date']) || $s->scheduled_date?->format('Y-m-d') === $data['scheduled_date']));
            if ($candidates->count() === 1) {
                $shoot = $candidates->first();
                $unit = isset($data['unit_label']) ? $shoot->units->first(fn ($u) => $normalize($u->label) === $normalize($data['unit_label'])) : null;
                if ($unit || (! isset($data['unit_label']) && $shoot->units->count() <= 1)) {
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

    public function hasConfirmedMatch(AryeoRequest $order, Shoot $shoot, array $data): bool
    {
        $proof = $order->match_verification;
        if (! is_array($proof) || $order->match_status !== 'matched'
            || ($proof['client_id'] ?? null) !== (int) $shoot->client_id
            || ($proof['source_id'] ?? null) !== $order->source_id
            || ($proof['request_id'] ?? null) !== $order->request_id
            || ($proof['unit_id'] ?? null) !== $order->unit_id
            || ($order->unit_id && ! $shoot->units()->whereKey($order->unit_id)->exists())
            || strtolower(trim($proof['client_email'] ?? '')) !== strtolower(trim((string) $shoot->client?->email))) {
            return false;
        }
        foreach (['requester_email', 'address', 'unit_label', 'scheduled_date', 'city', 'state', 'zip'] as $key) {
            if (strtolower(trim((string) ($proof[$key] ?? ''))) !== strtolower(trim((string) ($data[$key] ?? '')))) {
                return false;
            }
        }

        return true;
    }

    public function matchesStreet(Shoot $shoot, array $data): bool
    {
        foreach (['city', 'state', 'zip'] as $key) {
            if (! empty($data[$key]) && strtolower(trim($data[$key])) !== strtolower(trim((string) $shoot->$key))) {
                return false;
            }
        }

        return $this->normalizeStreet($shoot->address, $shoot) === $this->normalizeStreet($data['address'] ?? null, $shoot);
    }

    private function matchingQuery(AryeoConnection $connection)
    {
        if (! $connection->discover_all_clients) {
            return $this->catalog->query($connection);
        }

        // This opt-in query is for exact request metadata matching only. The
        // media catalog and every download/job route keep their client scope.
        return Shoot::query()->whereHas('client', fn ($q) => $q->where('role', 'client'));
    }

    private function normalizeStreet(?string $address, Shoot $shoot): string
    {
        $normalize = fn ($value) => strtolower(trim(preg_replace('/\s+/', ' ', str_replace([',', '.'], ' ', (string) $value))));
        $value = $normalize($address);
        $suffixes = ['dr' => 'drive', 'rd' => 'road', 'ln' => 'lane', 'st' => 'street', 'ave' => 'avenue', 'ct' => 'court',
            'cir' => 'circle', 'ter' => 'terrace', 'pl' => 'place', 'blvd' => 'boulevard', 'pkwy' => 'parkway', 'trl' => 'trail', 'hwy' => 'highway'];
        $streetTypes = [...array_keys($suffixes), ...array_values($suffixes), 'way'];
        $city = $normalize($shoot->city);
        if ($city !== '') {
            $localities = array_unique(array_map($normalize, [
                $city.' '.$shoot->state.' '.$shoot->zip, $city.' '.$shoot->state, $city.' '.$shoot->zip, $city,
            ]));
            usort($localities, fn ($a, $b) => strlen($b) <=> strlen($a));
            foreach ($localities as $locality) {
                if (! str_ends_with($value, ' '.$locality)) {
                    continue;
                }
                $street = substr($value, 0, -strlen(' '.$locality));
                // Strip only this shoot's known locality after a complete
                // street suffix. Never turn a street name into a house number
                // or remove a conflicting city, ZIP, or unit from the request.
                if (preg_match('/^\S+\s+.+\s+(\S+)$/', $street, $match) && in_array($match[1], $streetTypes, true)) {
                    $value = $street;
                    break;
                }
            }
        }

        return preg_replace_callback('/\b('.implode('|', array_keys($suffixes)).')$/', fn ($match) => $suffixes[$match[1]], $value);
    }
}
