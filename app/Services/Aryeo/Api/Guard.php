<?php

namespace App\Services\Aryeo\Api;

use App\Models\AryeoApiDestination;
use App\Models\AryeoApiExport;
use App\Models\AryeoJob;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Guard
{
    public function blockers(AryeoApiDestination $destination): array
    {
        $i = $destination->installation()->firstOrFail();
        $blockers = app(Requirements::class)->blockers($i, $destination->workflow);
        if (! config('aryeo_api.enabled') || ! config('aryeo_api.writes_enabled')) {
            $blockers[] = 'provider_writes_disabled';
        }
        if ($i->environment === 'production' && ! config('aryeo_api.production_enabled')) {
            $blockers[] = 'production_not_enabled';
        }
        if ($i->status !== 'connected') {
            $blockers[] = 'connection_required';
        }
        if (! $i->enrolled) {
            $blockers[] = 'program_enrollment_required';
        }
        if ($i->paused || in_array($destination->workflow, $i->paused_workflows ?? [], true)) {
            $blockers[] = 'integration_paused';
        }
        if (! in_array((int) $destination->client_id, $i->allowed_client_ids ?? [], true)
            || ! in_array((int) $destination->shoot_id, $i->allowed_shoot_ids ?? [], true)) {
            $blockers[] = 'pilot_scope_required';
        }
        if ($destination->association_status !== 'matched' || ! $destination->legacy_listing_id) {
            $blockers[] = 'awaiting_listing';
        }
        if (! $destination->provider_job_id || ! $destination->provider_place_id) {
            $blockers[] = 'partner_destination_binding_required';
        }
        if ($destination->on_hold) {
            $blockers[] = 'shoot_on_hold';
        }
        if ($destination->executor === 'bridge') {
            $blockers[] = 'bridge_handoff_requires_reconciliation';
        }
        if (AryeoJob::whereIn('request_record_id', \App\Models\AryeoRequest::where(fn ($q) => $q->where('shoot_id', $destination->shoot_id)
            ->when($destination->legacy_listing_id, fn ($q) => $q->orWhere('listing_id', $destination->legacy_listing_id)))->select('id'))
            ->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
            $blockers[] = 'legacy_operation_unresolved';
        }

        return array_values(array_unique($blockers));
    }

    public function assertWrite(AryeoApiExport $export, ?string $owner = null): void
    {
        $export->refresh();
        $destination = $export->destination()->firstOrFail();
        abort_if($export->cancelled_at || in_array($export->status, ['cancelled', 'delivered', 'draft', 'correction_required'], true), 409, 'export_not_executable');
        abort_unless($export->approved_at && $export->approved_by, 409, 'approval_required');
        abort_if($export->cases()->where('status', 'open')->exists(), 409, 'unresolved_case');
        $blockers = $this->blockers($destination);
        abort_if($blockers, 409, implode(', ', $blockers));
        $i = $destination->installation;
        // v2beta1 has no enforceable package protection. A registry flag must
        // never pretend that this adapter implements a future capability.
        abort_unless($export->editing_window_until?->isFuture(), 409, 'supervised_editing_window_expired');
        foreach ($export->assets as $asset) {
            $key = $asset->source['kind'] === 'link' ? 'tour:'.$asset->source['id'] : $asset->source['section'].':'.($asset->source['mime_type'] ?? 'unknown');
            $qualified = collect(data_get($i->capabilities, 'media', []))->first(fn ($r) => ($r['key'] ?? null) === $key && ($r['workflow'] ?? null) === $destination->workflow);
            abort_unless($qualified && ($qualified['ingest'] ?? false) && ($qualified['listing'] ?? false)
                && ($qualified['product'] ?? false) && filter_var($qualified['evidence_url'] ?? '', FILTER_VALIDATE_URL), 409, 'media_capability_unqualified');
        }
        if ($owner !== null) {
            abort_unless($destination->active_export_id === $export->id && $destination->executor === 'api'
                && hash_equals((string) $destination->executor_token, $owner)
                && $destination->executor_expires_at?->isFuture(), 409, 'executor_ownership_lost');
        }
        app(Snapshots::class)->assertCurrent($export);
    }

    public function claim(AryeoApiExport $export): string
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($export) {
            $d = $export->destination()->firstOrFail();
            abort_if(AryeoApiDestination::where('installation_id', $d->installation_id)->where('id', '!=', $d->id)
                ->whereNotNull('active_export_id')->where(fn ($q) => $q->where('provider_job_id', $d->provider_job_id)->orWhere('provider_place_id', $d->provider_place_id))
                ->exists(), 409, 'destination_owned');
            abort_if($d->active_export_id && $d->active_export_id !== $export->id, 409, 'destination_owned');
            // An expired owner with a potentially accepted write is NOT free.
            abort_if($export->operations()->whereIn('status', ['sent', 'unknown'])->exists(), 409, 'outcome_unknown');
            abort_if($d->executor_token, 409, 'executor_stop_and_reconciliation_required');
            $token = (string) Str::uuid();
            $d->update(['active_export_id' => $export->id, 'executor' => 'api', 'executor_token' => $token, 'executor_expires_at' => now()->addMinutes(5)]);

            return $token;
        }), 'aryeo-api.claim');
    }

    public function yield(AryeoApiExport $export, string $owner): void
    {
        AryeoApiDestination::whereKey($export->destination_id)->where('executor_token', $owner)
            ->update(['executor_token' => null, 'executor_expires_at' => null]);
    }
}
