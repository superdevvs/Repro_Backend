<?php

namespace App\Jobs;

use App\Models\AryeoApiExport;
use App\Services\Aryeo\Api\Client;
use App\Services\Aryeo\Api\Grants;
use App\Services\Aryeo\Api\Guard;
use App\Services\Aryeo\Api\Inventory;
use App\Services\Aryeo\Api\Operations;
use App\Services\Aryeo\Api\Snapshots;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunAryeoApiExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public string $exportId)
    {
        $this->onQueue(config('aryeo_api.queue'));
    }

    public function handle(): void
    {
        $export = AryeoApiExport::find($this->exportId);
        if (! $export || ! in_array($export->status, ['queued', 'running'], true)) {
            return;
        }
        $owner = null;
        $again = false;
        try {
            app(Guard::class)->assertWrite($export);
            $owner = app(Guard::class)->claim($export);
            $export->update(['status' => 'running', 'attempts' => $export->attempts + 1]);
            $again = $this->advance($export, $owner);
        } catch (\Throwable $e) {
            $export->refresh();
            if ($owner === null && $e->getMessage() === 'executor_stop_and_reconciliation_required'
                && $export->destination()->first()->executor_expires_at?->isFuture()) {
                return;
            }
            if (! in_array($export->status, ['outcome_unknown', 'cancelled', 'correction_required', 'delivered'], true)) {
                // Do not persist provider errors, URLs, tokens, or raw exception text.
                $safe = preg_match('/^[a-z_]+(?:, [a-z_]+)*$/D', $e->getMessage()) ? $e->getMessage() : 'operator_review_required';
                $export->update(['status' => 'blocked', 'blocker' => substr($safe, 0, 250)]);
            }
        } finally {
            if ($owner) {
                app(Guard::class)->yield($export, $owner);
            }
            $export->refresh();
            if (in_array($export->status, ['blocked', 'outcome_unknown', 'cancelled', 'correction_required', 'delivered'], true)) {
                \Illuminate\Support\Facades\DB::table('aryeo_api_attempts')->where('export_id', $export->id)->whereNull('finished_at')
                    ->update(['result' => $export->status, 'blocker' => $export->blocker, 'finished_at' => now()]);
            }
        }
        if ($again && $export->fresh()->status === 'running') {
            self::dispatch($export->id)->delay(now()->addSeconds(15));
        }
    }

    private function advance(AryeoApiExport $export, string $owner): bool
    {
        $d = $export->destination;
        $i = $d->installation;
        $client = app(Client::class);
        $ops = app(Operations::class);
        $asset = $export->assets()->whereNotIn('status', ['staged', 'delivered'])->orderBy('created_at')->orderBy('id')->get()
            ->sortBy(fn ($a) => [$a->source['section'], $a->source['order']])->first();
        if ($asset) {
            $export->update(['phase' => 'ingestion']);
            $source = $asset->source;
            if (! $asset->provider_asset_id) {
                if ($source['kind'] === 'file') {
                    if (! $asset->ingest_id) {
                        $known = $export->operations()->where('operation_key', 'ingest:'.$asset->id)->where('status', 'confirmed')->first();
                        if ($known) {
                            $intent = $known->result;
                        } else {
                            $url = app(Grants::class)->issue($asset, $owner);
                            $intent = $ops->write($export, $owner, 'ingest:'.$asset->id, '/file-ingest-intents', ['source_url' => $url]);
                        }
                        abort_unless(! empty($intent['id']), 409, 'ingest_identity_missing');
                        $asset->update(['ingest_id' => $intent['id'], 'status' => 'ingesting']);
                    }
                    $intent = $client->get($i, '/file-ingest-intents/'.$asset->ingest_id)['data'];
                    if (($intent['status'] ?? '') === 'ingesting') {
                        abort_unless($asset->grant_expires_at?->isFuture() && $asset->created_at->gt(now()->subHours(48)), 409, 'grant_expired_reconcile_existing_intent');

                        return true;
                    }
                    abort_unless(($intent['status'] ?? '') === 'completed' && ! empty($intent['file_id']), 409, 'ingestion_requires_review');
                    $asset->update(['provider_asset_id' => $intent['file_id'], 'status' => 'ingested']);
                } else {
                    $baseline = $export->operations()->where('operation_key', 'link:'.$asset->id)->first()?->baseline;
                    $baseline ??= array_column($client->listing($i, '/links'), 'id');
                    $link = $ops->write($export, $owner, 'link:'.$asset->id, '/links', ['url' => $source['url'], 'type' => 'tour'], $baseline);
                    abort_unless(! empty($link['id']), 409, 'link_identity_missing');
                    $asset->update(['provider_asset_id' => $link['id'], 'status' => 'ingested']);
                }

                return true;
            }
            $placed = $ops->write($export, $owner, 'attach:'.$asset->id, '/place-assets', [
                'asset_type' => $source['kind'], 'asset_id' => $asset->provider_asset_id,
                'association' => ['place_id' => $d->provider_place_id, 'section_type' => $source['section']],
                'display_type' => 'both', 'displays_on_property_websites' => true,
            ]);
            abort_unless(($placed['place_id'] ?? null) === $d->provider_place_id && data_get($placed, 'asset.id') === $asset->provider_asset_id, 409, 'asset_association_mismatch');
            $asset->update(['place_asset_id' => $placed['id'], 'ownership' => 'created', 'status' => 'staged', 'presentation' => app(Inventory::class)->project($placed)]);

            return true;
        }
        $export->update(['phase' => 'delivery']);
        app(Snapshots::class)->assertCurrent($export, true);
        $job = $client->get($i, '/jobs/'.$d->provider_job_id)['data'];
        $place = $client->get($i, '/places/'.$d->provider_place_id)['data'];
        abort_unless(($job['company_id'] ?? null) === $i->company_id && ($job['legacy_listing_id'] ?? null) === $d->legacy_listing_id
            && ($place['company_id'] ?? null) === $i->company_id && ($place['job_id'] ?? null) === $d->provider_job_id
            && ($job['lifecycle_status'] ?? null) !== 'archived', 409, 'provider_relationship_changed');
        if (isset($d->address['provider'])) {
            abort_unless($d->address['provider'] === ($place['address'] ?? null), 409, 'provider_property_changed');
        }
        $inventory = app(Inventory::class);
        $alreadyDelivered = $export->operations()->where('operation_key', 'deliver')->where('status', 'confirmed')->exists();
        $inventory->assertPackage($export, $inventory->read($d), $alreadyDelivered);
        if (! $export->provider_delivery_id) {
            abort_unless($export->assets()->where('ownership', 'created')->exists(), 409, 'no_new_media_to_deliver');
            $baseline = array_column($client->listing($i, '/deliveries', ['filters[job_id]' => $d->provider_job_id]), 'id');
            $delivery = $ops->write($export, $owner, 'create-delivery', '/deliveries', ['job_id' => $d->provider_job_id], $baseline);
            abort_unless(($delivery['job_id'] ?? null) === $d->provider_job_id && ($delivery['status'] ?? '') === 'undelivered', 409, 'delivery_identity_mismatch');
            $export->update(['provider_delivery_id' => $delivery['id']]);

            return true;
        }
        if (! $alreadyDelivered) {
            // This last read narrows but does not close the documented outside-edit race.
            $inventory->assertPackage($export, $inventory->read($d));
            $ops->write($export, $owner, 'deliver', '/deliveries/'.$export->provider_delivery_id.'/actions/deliver', []);
        }
        $delivery = $client->get($i, '/deliveries/'.$export->provider_delivery_id)['data'];
        abort_unless(($delivery['status'] ?? '') === 'delivered' && ($delivery['job_id'] ?? null) === $d->provider_job_id, 409, 'delivery_evidence_incomplete');
        $remote = $inventory->read($d);
        $inventory->assertPackage($export, $remote, true);
        \App\Support\LockedWrite::run(fn () => \Illuminate\Support\Facades\DB::transaction(function () use ($export, $d, $delivery, $remote) {
            $export->refresh();
            $d->refresh();
            $review = (bool) $export->cancelled_at;
            try {
                app(Snapshots::class)->assertCurrent($export);
            } catch (\Throwable) {
                $review = true;
            }
            $export->update(['status' => $review ? 'correction_required' : 'delivered', 'phase' => $review ? 'correction' : 'verification', 'completed_at' => now(), 'blocker' => $review ? 'conditions_changed_during_delivery' : null,
                'receipt' => ['delivery_id' => $delivery['id'], 'inventory' => $remote, 'verified_at' => now()->toIso8601String()],
                'outcomes' => array_replace($export->outcomes, ['delivery' => 'confirmed', 'revision' => 'verification_pending'])]);
            $export->assets()->update(['status' => 'delivered', 'grant_revoked_at' => now()]);
            $d->update(['active_export_id' => $review ? $export->id : null, 'on_hold' => $review || $d->on_hold,
                'executor' => null, 'executor_token' => null, 'executor_expires_at' => null]);
            if ($review) {
                \App\Models\AryeoApiCase::firstOrCreate(['export_id' => $export->id, 'kind' => 'post_delivery_change', 'status' => 'open'],
                    ['reason' => 'Release or destination conditions changed while delivery was in flight', 'escalate_at' => now()->addMinutes(30)]);
                \Illuminate\Support\Facades\DB::table('aryeo_api_alerts')->insertOrIgnore(['key' => 'changed:'.$export->id, 'export_id' => $export->id,
                    'owner' => 'Shubham', 'message' => 'Delivery completed while conditions changed. Review the receipt and correction case.', 'created_at' => now()]);
            }
        }), 'aryeo-api.receipt');

        return false;
    }
}
