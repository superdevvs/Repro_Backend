<?php

namespace App\Services\Aryeo\Api;

use App\Models\AryeoApiAsset;
use App\Models\AryeoApiCase;
use App\Models\AryeoApiDestination;
use App\Models\AryeoApiExport;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;

class Exports
{
    public const OUTCOMES = ['delivery' => 'pending', 'association' => 'awaiting_listing', 'media' => 'awaiting_verification',
        'branding' => 'pending', 'hd' => 'eligibility_unknown', 'staging' => 'eligibility_unknown', 'revision' => 'approval_required', 'notification' => 'unverified'];

    public function preview(AryeoApiDestination $d, int $actor): AryeoApiExport
    {
        $snapshot = app(Snapshots::class)->build($d);
        $baseline = null;
        if ($d->provider_place_id && $d->installation->status === 'connected' && config('aryeo_api.enabled')) {
            $baseline = app(Inventory::class)->read($d);
        }
        $revision = app(Snapshots::class)->hash([$snapshot, $baseline]);

        return LockedWrite::run(fn () => DB::transaction(function () use ($d, $snapshot, $baseline, $revision, $actor) {
            abort_if($d->fresh()->active_export_id, 409, 'destination_has_unfinished_export');
            $existing = AryeoApiExport::where('destination_id', $d->id)->where('revision', $revision)->whereIn('status', ['draft', 'approved'])->first();
            if ($existing) {
                return $existing;
            }
            $export = AryeoApiExport::create(['destination_id' => $d->id, 'revision' => $revision, 'snapshot' => $snapshot,
                'remote_baseline' => $baseline, 'status' => 'draft', 'outcomes' => array_replace(self::OUTCOMES, ['association' => $d->association_status]), 'created_by' => $actor]);
            $sources = [];
            foreach ($snapshot['assets'] as $index => $a) {
                $sources[] = ['key' => 'file:'.$a['id'], 'kind' => 'file', 'id' => $a['id'], 'filename' => $a['filename'],
                    'revision' => $a['revision'], 'content' => $a['content'], 'mime_type' => $a['mime_type'], 'section' => ['photos' => 'photos', 'floorplans' => 'floor_plans', 'videos' => 'videos'][$a['type']], 'order' => $index];
            }
            foreach ($snapshot['tours'] as $index => $a) {
                $sources[] = ['key' => 'tour:'.$a['id'], 'kind' => 'link', 'id' => $a['id'], 'url' => $a['url'], 'section' => 'interactive_content', 'order' => $index];
            }
            foreach ($sources as $source) {
                $asset = AryeoApiAsset::create(['export_id' => $export->id, 'source_key' => $source['key'], 'source' => $source]);
                // Reuse only previously receipted exact revisions in this same
                // destination, and only if the complete baseline still has them.
                $previous = AryeoApiAsset::whereIn('export_id', AryeoApiExport::where('destination_id', $d->id)->where('status', 'delivered')->select('id'))
                    ->where('source_key', $source['key'])->get()->first(fn ($a) => $a->source === $source && collect($baseline ?? [])->contains(fn ($r) => $r['id'] === $a->place_asset_id && $r['asset_id'] === $a->provider_asset_id && $r['delivery_id']));
                if ($previous) {
                    $asset->update(['provider_asset_id' => $previous->provider_asset_id, 'place_asset_id' => $previous->place_asset_id, 'status' => 'delivered', 'ownership' => 'reused']);
                }
            }

            return $export;
        }), 'aryeo-api.preview');
    }

    public function approve(AryeoApiExport $export, string $revision, int $actor, string $window): AryeoApiExport
    {
        abort_unless($export->status === 'draft' && hash_equals($export->revision, $revision), 409, 'approval_stale');
        app(Snapshots::class)->assertCurrent($export, true);
        abort_unless(is_array($export->remote_baseline), 409, 'complete_provider_inventory_required');
        $remote = app(Inventory::class)->read($export->destination);
        abort_unless($remote === $export->remote_baseline, 409, 'remote_inventory_changed');
        abort_if(collect($remote)->contains(fn ($a) => ! $a['delivery_id']), 409, 'unexpected_undelivered_assets');
        $changed = AryeoApiExport::whereKey($export->id)->where('status', 'draft')->whereNull('cancelled_at')
            ->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now(), 'editing_window_until' => $window,
                'outcomes' => json_encode(array_replace($export->outcomes, ['revision' => 'verification_pending']))]);
        abort_unless($changed === 1, 409, 'approval_stale');
        $export->refresh();

        return $export;
    }

    public function submit(AryeoApiExport $export): void
    {
        app(Guard::class)->assertWrite($export);
        LockedWrite::run(fn () => DB::transaction(function () use ($export) {
            $export->refresh();
            if (in_array($export->status, ['queued', 'running'], true)) {
                return;
            }
            app(Guard::class)->assertWrite($export);
            abort_unless(in_array($export->status, ['approved', 'blocked', 'queued'], true), 409, 'export_not_submittable');
            $d = $export->destination()->firstOrFail();
            abort_if(AryeoApiDestination::where('installation_id', $d->installation_id)->where('id', '!=', $d->id)
                ->whereNotNull('active_export_id')->where(fn ($q) => $q->where('provider_job_id', $d->provider_job_id)->orWhere('provider_place_id', $d->provider_place_id))->exists(), 409, 'destination_owned');
            abort_if($d->active_export_id && $d->active_export_id !== $export->id, 409, 'destination_owned');
            abort_if($export->operations()->whereIn('status', ['sent', 'unknown'])->exists(), 409, 'outcome_unknown');
            $d->update(['active_export_id' => $export->id, 'executor' => 'api']);
            $export->update(['status' => 'queued', 'submitted_at' => $export->submitted_at ?? now(), 'blocker' => null]);
            DB::table('aryeo_api_attempts')->insert(['export_id' => $export->id, 'result' => 'pending', 'started_at' => now()]);
            \App\Jobs\RunAryeoApiExport::dispatch($export->id)->afterCommit();
        }), 'aryeo-api.submit');
    }

    public function cancel(AryeoApiExport $export, int $actor, string $reason): void
    {
        LockedWrite::run(fn () => DB::transaction(function () use ($export, $actor, $reason) {
            $export->refresh();
            $staged = $export->operations()->whereIn('status', ['sent', 'unknown', 'confirmed'])->exists();
            $export->update(['cancelled_at' => now(), 'status' => $staged ? 'correction_required' : 'cancelled', 'blocker' => $staged ? 'provider_cleanup_required' : null]);
            $export->assets()->update(['grant_revoked_at' => now()]);
            DB::table('aryeo_api_attempts')->where('export_id', $export->id)->whereNull('finished_at')->update(['result' => 'cancelled', 'finished_at' => now()]);
            if ($staged) {
                AryeoApiCase::firstOrCreate(['export_id' => $export->id, 'kind' => 'cancellation', 'status' => 'open'],
                    ['reason' => $reason, 'created_by' => $actor, 'escalate_at' => now()->addMinutes(30)]);
            } else {
                AryeoApiDestination::whereKey($export->destination_id)->where('active_export_id', $export->id)
                    ->update(['active_export_id' => null, 'executor' => null, 'executor_token' => null, 'executor_expires_at' => null]);
            }
        }), 'aryeo-api.cancel');
    }
}
