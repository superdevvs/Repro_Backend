<?php

namespace App\Services\Aryeo\Api;

use App\Models\AryeoApiDestination;
use App\Models\AryeoApiExport;
use App\Models\AryeoRequest;
use App\Services\Aryeo\AryeoCatalog;
use App\Services\Aryeo\AryeoDiscovery;

class Snapshots
{
    public function build(AryeoApiDestination $destination, bool $hashOriginals = true): array
    {
        $destination->refresh();
        $shoot = $destination->shoot()->with('client')->firstOrFail();
        $installation = $destination->installation()->firstOrFail();
        $catalog = app(AryeoCatalog::class);
        $identity = $catalog->identity($shoot);
        abort_unless($identity === $destination->identity && (int) $shoot->client_id === (int) $destination->client_id, 409, 'destination_identity_changed');
        abort_if($destination->on_hold || ! data_get($destination->consent, 'distribution_authorized'), 409, 'distribution_not_authorized');
        if ($destination->workflow === 'showcase') {
            $request = AryeoRequest::findOrFail($destination->request_record_id);
            abort_unless((int) $request->shoot_id === (int) $shoot->id && $request->unit_id === $destination->unit_id
                && app(AryeoDiscovery::class)->verifiedForDelivery($request, $shoot)
                && ($request->discovery['required'] ?? null) === $destination->requirements
                && (! $request->listing_id || $request->listing_id === $destination->legacy_listing_id), 409, 'showcase_identity_changed');
        }
        $ready = $catalog->readiness($shoot, $destination->unit_id, $destination->requirements);
        abort_unless($ready['eligible'], 409, implode(', ', $ready['blockers']));
        $assets = $ready['assets'];
        foreach ($assets as &$asset) {
            // Hash once at preview and again at final approval/delivery; routine
            // progress checks compare database revisions. Downloads hash bytes.
            $asset['content'] = $hashOriginals ? app(Originals::class)->fingerprint($shoot->files()->findOrFail($asset['id'])) : null;
        }
        unset($asset);

        return ['version' => 1, 'destination_id' => $destination->id, 'environment' => $installation->environment,
            'installation_id' => $installation->id, 'company_id' => $installation->company_id,
            'authorization_generation' => (int) $installation->authorization_generation,
            'shoot_id' => $shoot->id, 'unit_id' => $destination->unit_id, 'client_id' => $shoot->client_id,
            'identity' => $identity, 'workflow' => $destination->workflow, 'request_record_id' => $destination->request_record_id,
            'provider_job_id' => $destination->provider_job_id, 'provider_place_id' => $destination->provider_place_id,
            'legacy_listing_id' => $destination->legacy_listing_id, 'listing_url' => $destination->listing_url,
            'association_status' => $destination->association_status, 'address' => $destination->address,
            'recipients' => $destination->recipients, 'requirements' => $destination->requirements,
            'consent' => $destination->consent, 'release' => $ready['dashboard'],
            'media_version' => $ready['media_version'], 'assets' => $assets, 'tours' => $ready['tours'],
            'effects' => ['aryeo_customer_email' => true, 'fulfills_provider_orders' => true, 'changes_repro_payment' => false]];
    }

    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function assertCurrent(AryeoApiExport $export, bool $hashOriginals = false): void
    {
        $current = $this->build($export->destination, $hashOriginals);
        $approved = $export->snapshot;
        if (! $hashOriginals) {
            foreach ($approved['assets'] as &$asset) {
                $asset['content'] = null;
            }
            unset($asset);
        }
        abort_unless(hash_equals($this->hash($approved), $this->hash($current)), 409, 'approval_stale');
    }
}
