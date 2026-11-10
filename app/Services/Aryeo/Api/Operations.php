<?php

namespace App\Services\Aryeo\Api;

use App\Models\AryeoApiCase;
use App\Models\AryeoApiExport;
use App\Models\AryeoApiOperation;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;

class Operations
{
    public function write(AryeoApiExport $export, string $owner, string $key, string $path, array $body, array $baseline = [], string $method = 'POST'): array
    {
        app(Guard::class)->assertWrite($export, $owner);
        $hash = hash('sha256', json_encode([$method, $path, $body], JSON_THROW_ON_ERROR));
        $op = LockedWrite::run(fn () => DB::transaction(fn () => AryeoApiOperation::firstOrCreate(
            ['export_id' => $export->id, 'operation_key' => $key],
            ['method' => $method, 'path' => $path, 'request_hash' => $hash, 'request' => $body, 'baseline' => $baseline, 'status' => 'prepared']
        )), 'aryeo-api.prepare-operation');
        abort_unless(hash_equals($op->request_hash, $hash), 409, 'operation_request_changed');
        if ($op->status === 'confirmed') {
            return $op->result ?? [];
        }
        abort_unless($op->status === 'prepared', 409, 'operation_requires_reconciliation');
        // Local throttling or a failed credential refresh is known not to have
        // sent this mutation. Keep it prepared instead of inventing uncertainty.
        $installation = $export->destination->installation;
        $prepared = app(Client::class)->prepare($installation, $method, $path);
        // A database reservation is durable before the request can leave RePro.
        $claimed = LockedWrite::run(fn () => DB::transaction(function () use ($export, $owner, $op) {
            app(Guard::class)->assertWrite($export, $owner);

            return AryeoApiOperation::whereKey($op->id)->where('status', 'prepared')->update(['status' => 'sent', 'sent_at' => now()]);
        }), 'aryeo-api.reserve-write');
        abort_unless($claimed === 1, 409, 'operation_already_started');
        try {
            $r = app(Client::class)->sendPrepared($installation, $prepared, $method, $path, $body);
        } catch (\Throwable) {
            $this->unknown($export, $op, 'response_unavailable');
            abort(409, 'outcome_unknown');
        }
        $op->update(['http_status' => $r->status(), 'provider_request_id' => substr((string) $r->header('X-Request-Id'), 0, 200)]);
        if ($r->successful() && is_array($r->json('data'))) {
            $result = $r->json('data');
            $op->update(['status' => 'confirmed', 'result' => $result, 'resolved_at' => now()]);

            return $result;
        }
        // Even a provider error can follow a committed side effect. Preserve the
        // uncertainty; never convert arbitrary 4xx/5xx into retry permission.
        $this->unknown($export, $op, 'provider_response_requires_review');
        abort(409, 'outcome_unknown');
    }

    public function unknown(AryeoApiExport $export, AryeoApiOperation $op, string $code): void
    {
        $op->update(['status' => 'unknown', 'error_code' => $code]);
        $export->update(['status' => $export->cancelled_at ? 'correction_required' : 'outcome_unknown', 'blocker' => 'outcome_unknown',
            'outcomes' => array_replace($export->outcomes, $op->operation_key === 'deliver' ? ['delivery' => 'outcome_unknown'] : [])]);
        DB::table('aryeo_api_alerts')->insertOrIgnore(['key' => 'unknown:'.$op->id, 'export_id' => $export->id, 'owner' => 'Shubham',
            'message' => 'Provider outcome unknown. Reconcile before another executor or retry.', 'created_at' => now()]);
        AryeoApiCase::firstOrCreate(['export_id' => $export->id, 'kind' => 'unknown_operation', 'status' => 'open'],
            ['reason' => 'Provider outcome requires reconciliation: '.$op->operation_key, 'escalate_at' => now()->addMinutes(30)]);
    }

    public function reconcile(AryeoApiExport $export): array
    {
        $client = app(Client::class);
        $d = $export->destination;
        $i = $d->installation;
        foreach ($export->operations()->whereIn('status', ['sent', 'unknown'])->get() as $op) {
            $matches = [];
            $body = $op->request;
            if ($op->path === '/jobs') {
                $marker = $body['internal_notes'] ?? null;
                if ($marker) {
                    $matches = array_values(array_filter($client->listing($i, '/jobs', ['filters[company_id]' => $i->company_id]), fn ($r) => ($r['internal_notes'] ?? null) === $marker));
                }
            } elseif ($op->path === '/places') {
                $matches = array_values(array_filter($client->listing($i, '/places', ['filters[job_id]' => $body['job_id']]), fn ($r) => ($r['name'] ?? null) === $body['name'] && ($r['job_id'] ?? null) === $body['job_id'] && ($r['address'] ?? null) === ($body['address'] ?? null) && ($r['company_id'] ?? null) === $i->company_id));
            } elseif ($op->path === '/file-ingest-intents') {
                $matches = array_values(array_filter($client->listing($i, '/file-ingest-intents'), fn ($r) => ($r['source_url'] ?? null) === $body['source_url']));
            } elseif ($op->path === '/links') {
                $before = $op->baseline ?? [];
                $matches = array_values(array_filter($client->listing($i, '/links'), fn ($r) => ($r['url'] ?? null) === $body['url'] && ($r['type'] ?? null) === $body['type'] && ! in_array($r['id'], $before, true)));
            } elseif ($op->path === '/place-assets') {
                $matches = $client->listing($i, '/place-assets', ['filters[place_id]' => $d->provider_place_id, 'filters[asset_id]' => $body['asset_id'], 'filters[section_type]' => $body['association']['section_type']]);
                $matches = array_values(array_filter($matches, fn ($r) => data_get($r, 'asset.id') === $body['asset_id'] && data_get($r, 'asset.resource_type') === $body['asset_type']
                    && ($r['place_id'] ?? null) === $d->provider_place_id && ($r['display_type'] ?? null) === $body['display_type'] && ($r['displays_on_property_websites'] ?? null) === $body['displays_on_property_websites']));
            } elseif ($op->path === '/deliveries') {
                if (! $export->editing_window_until?->isFuture()) {
                    continue;
                }
                app(Inventory::class)->assertPackage($export, app(Inventory::class)->read($d));
                $matches = array_values(array_filter($client->listing($i, '/deliveries', ['filters[job_id]' => $d->provider_job_id]), fn ($r) => ($r['status'] ?? null) === 'undelivered'));
            } elseif (preg_match('~^/deliveries/([a-f0-9-]+)/actions/deliver$~', $op->path, $match)) {
                $row = $client->get($i, '/deliveries/'.$match[1])['data'] ?? [];
                if (($row['status'] ?? null) === 'delivered' && ($row['job_id'] ?? null) === $d->provider_job_id) {
                    app(Inventory::class)->assertPackage($export, app(Inventory::class)->read($d), true);
                    $matches = [$row];
                }
            }
            if (count($matches) === 1 && is_string($matches[0]['id'] ?? null)) {
                $op->update(['status' => 'confirmed', 'result' => $matches[0], 'resolved_at' => now(), 'error_code' => null]);
            }
        }
        $unknown = $export->operations()->whereIn('status', ['sent', 'unknown'])->count();
        if (! $unknown) {
            $export->cases()->where('kind', 'unknown_operation')->where('status', 'open')->update(['status' => 'resolved', 'resolved_at' => now()]);
            if ($export->status === 'outcome_unknown') {
                $export->update(['status' => 'approved', 'blocker' => null]);
            }
        }

        return ['unresolved_operations' => $unknown, 'may_resume' => $unknown === 0];
    }
}
