<?php

namespace App\Services\Aryeo\Api;

use App\Models\AryeoApiAsset;
use Illuminate\Support\Str;

class Grants
{
    public function issue(AryeoApiAsset $asset, string $owner): string
    {
        app(Guard::class)->assertWrite($asset->export, $owner);
        app(FrozenOriginals::class)->freeze($asset);
        if ($asset->source_url) {
            abort_unless(! $asset->grant_revoked_at && $asset->grant_expires_at?->isFuture(), 409, 'grant_expired_reconcile_existing_intent');

            return $asset->source_url;
        }
        $token = Str::random(64);
        $url = rtrim(config('aryeo_api.public_origin'), '/').'/api/integrations/aryeo/download/'.$token;
        $asset->update(['source_url' => $url, 'grant_hash' => hash('sha256', $token), 'grant_expires_at' => now()->addHours(config('aryeo_api.grant_hours'))]);

        return $url;
    }

    public function authorize(AryeoApiAsset $asset): void
    {
        $asset->refresh();
        abort_unless(config('aryeo_api.enabled') && ! $asset->grant_revoked_at && $asset->grant_expires_at?->isFuture(), 410);
        $export = $asset->export()->firstOrFail();
        abort_if($export->cancelled_at, 410);
        abort_unless($export->approved_at, 403);
        $d = $export->destination;
        $i = $d->installation;
        abort_if(app(Guard::class)->blockers($d), 403);
        abort_if($i->paused || $i->status !== 'connected' || in_array($d->workflow, $i->paused_workflows ?? [], true), 403);
        app(Snapshots::class)->assertCurrent($export);
    }
}
