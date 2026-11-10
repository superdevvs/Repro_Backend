<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AryeoApiCase;
use App\Models\AryeoApiDestination;
use App\Models\AryeoApiEvidence;
use App\Models\AryeoApiExport;
use App\Models\AryeoApiInstallation;
use App\Models\Shoot;
use App\Services\Aryeo\Api\Client;
use App\Services\Aryeo\Api\Exports;
use App\Services\Aryeo\Api\Guard;
use App\Services\Aryeo\Api\Operations;
use App\Services\Aryeo\Api\Requirements;
use App\Services\Aryeo\AryeoCatalog;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AryeoApiController extends Controller
{
    private function actor(Request $r): int
    {
        abort_if($r->attributes->get('is_impersonating') || $r->header('X-Impersonate-User-Id'), 403);

        return $r->user()->id;
    }

    private function access(Request $r, Shoot $shoot): void
    {
        app(ShootAuthorizationSupport::class)->ensureShootAccess($shoot, $r->user());
    }

    public function installations()
    {
        return response()->json(['installations' => AryeoApiInstallation::all(), 'requirements' => Requirements::defaults(),
            'provider_enabled' => (bool) config('aryeo_api.enabled'), 'writes_enabled' => (bool) config('aryeo_api.writes_enabled'),
            'production_enabled' => (bool) config('aryeo_api.production_enabled'), 'automation_supported' => false]);
    }

    public function createInstallation(Request $r)
    {
        $actor = $this->actor($r);
        $data = $r->validate(['environment' => 'required|in:sandbox,production', 'company_id' => 'required|uuid', 'name' => 'required|string|max:120']);
        $i = AryeoApiInstallation::firstOrCreate(['environment' => $data['environment'], 'company_id' => $data['company_id']],
            $data + ['requirements' => Requirements::defaults(), 'capabilities' => ['enforced_package' => false, 'media' => []], 'updated_by' => $actor]);

        return response()->json(['installation' => $i], 201);
    }

    public function updateInstallation(Request $r, AryeoApiInstallation $installation)
    {
        $actor = $this->actor($r);
        $data = $r->validate(['paused' => 'sometimes|boolean', 'enrolled' => 'sometimes|boolean',
            'paused_workflows' => 'sometimes|array', 'paused_workflows.*' => 'in:ordinary,showcase',
            'allowed_client_ids' => 'sometimes|array', 'allowed_client_ids.*' => 'integer|exists:users,id',
            'allowed_shoot_ids' => 'sometimes|array', 'allowed_shoot_ids.*' => 'integer|exists:shoots,id',
            'requirements' => 'sometimes|array:'.implode(',', Requirements::KEYS),
            'requirements.*.status' => 'required|in:unconfirmed,sandbox_demonstrated,production_verified',
            'requirements.*.owner' => 'required|string|max:120', 'requirements.*.counterpart' => 'nullable|string|max:120',
            'requirements.*.deadline' => 'required|string|max:100', 'requirements.*.evidence_url' => 'nullable|url:https|max:2048',
            'capabilities' => 'sometimes|array:media', 'capabilities.media' => 'array',
            'capabilities.media.*.key' => 'required|string|max:100', 'capabilities.media.*.workflow' => 'required|in:ordinary,showcase',
            'capabilities.media.*.ingest' => 'required|boolean', 'capabilities.media.*.listing' => 'required|boolean',
            'capabilities.media.*.product' => 'required|boolean', 'capabilities.media.*.evidence_url' => 'required|url:https|max:2048']);
        foreach ($data['requirements'] ?? [] as $row) {
            abort_if($row['status'] !== 'unconfirmed' && empty($row['evidence_url']), 422, 'demonstration_evidence_required');
        }
        if (isset($data['requirements'])) {
            $data['requirements'] = array_replace($installation->requirements ?? Requirements::defaults(), $data['requirements']);
        }
        if (isset($data['capabilities'])) {
            $data['capabilities']['enforced_package'] = false;
        }
        LockedWrite::run(fn () => DB::transaction(function () use ($installation, $data, $actor) {
            $installation->refresh();
            $unpausing = (array_key_exists('paused', $data) && ! $data['paused'])
                || (isset($data['paused_workflows']) && array_diff($installation->paused_workflows ?? [], $data['paused_workflows']));
            if ($unpausing) {
                abort_if(AryeoApiCase::whereIn('export_id', AryeoApiExport::whereHas('destination', fn ($q) => $q->where('installation_id', $installation->id))->select('id'))
                    ->whereIn('kind', ['wrong_destination', 'unauthorized_release', 'duplicate_delivery'])->where('status', 'open')->exists(), 409, 'integrity_incident_acceptance_required');
            }
            $installation->update($data + ['updated_by' => $actor]);
        }), 'aryeo-api.configure-installation');

        return response()->json(['installation' => $installation]);
    }

    public function panel(Request $r, Shoot $shoot)
    {
        $this->access($r, $shoot);
        $r->validate(['unit_id' => 'nullable|integer']);
        $unit = $r->filled('unit_id') ? $r->integer('unit_id') : null;
        if ($unit) {
            $shoot->units()->findOrFail($unit);
        }
        $destinations = AryeoApiDestination::where('shoot_id', $shoot->id)->where('unit_id', $unit)->get();

        return response()->json(['destinations' => $destinations->map(fn ($d) => ['destination' => $d, 'blockers' => app(Guard::class)->blockers($d),
            'exports' => AryeoApiExport::where('destination_id', $d->id)->latest()->limit(30)->get()->map(fn ($e) => $this->exportData($e))]),
            'installations' => AryeoApiInstallation::all(['id', 'name', 'environment', 'company_id', 'status', 'paused'])]);
    }

    public function bind(Request $r, Shoot $shoot)
    {
        $actor = $this->actor($r);
        $this->access($r, $shoot);
        $data = $r->validate(['installation_id' => 'required|uuid|exists:aryeo_api_installations,id', 'unit_id' => 'nullable|integer',
            'workflow' => 'required|in:ordinary,showcase', 'request_record_id' => 'required_if:workflow,showcase|nullable|integer|exists:aryeo_requests,id',
            'provider_job_id' => 'nullable|uuid', 'provider_place_id' => 'required_with:provider_job_id|nullable|uuid', 'legacy_listing_id' => 'required_with:provider_job_id|nullable|uuid',
            'listing_url' => 'required_with:provider_job_id|nullable|url:https|max:2048',
            'recipients' => 'required|array|min:1|max:20', 'recipients.*.email' => 'required|email|max:254', 'recipients.*.provider_customer_id' => 'required|string|max:100',
            'requirements' => 'required|array:photos,floorplans,videos,tours', 'requirements.*' => 'nullable|integer|min:0|max:2000',
            'consent' => 'required|array:distribution_authorized,evidence_url,dual_email_acknowledged,intent',
            'consent.distribution_authorized' => 'required|accepted', 'consent.dual_email_acknowledged' => 'required|accepted',
            'consent.evidence_url' => 'required|url:https|max:2048', 'consent.intent' => 'required|in:initial,update',
            'relationship_evidence_url' => 'required|url:https|max:2048']);
        if (! empty($data['unit_id'])) {
            $shoot->units()->findOrFail($data['unit_id']);
        }
        abort_if($shoot->units()->count() > 1 && empty($data['unit_id']), 422, 'unit_required');
        if ($data['workflow'] === 'showcase') {
            $order = \App\Models\AryeoRequest::findOrFail($data['request_record_id']);
            abort_unless((int) $order->shoot_id === (int) $shoot->id && $order->unit_id === ($data['unit_id'] ?? null)
                && app(\App\Services\Aryeo\AryeoDiscovery::class)->verifiedForDelivery($order, $shoot)
                && ($order->discovery['required'] ?? null) === $data['requirements'], 409, 'showcase_relationship_unverified');
        }
        $i = AryeoApiInstallation::findOrFail($data['installation_id']);
        $address = ['repro' => ['address' => $shoot->address, 'city' => $shoot->city, 'state' => $shoot->state, 'zip' => $shoot->zip], 'relationship_evidence_url' => $data['relationship_evidence_url']];
        if (! empty($data['provider_job_id'])) {
            $job = app(Client::class)->get($i, '/jobs/'.$data['provider_job_id'])['data'];
            $place = app(Client::class)->get($i, '/places/'.$data['provider_place_id'])['data'];
            abort_unless(($job['company_id'] ?? null) === $i->company_id && ($job['legacy_listing_id'] ?? null) === $data['legacy_listing_id']
                && ($place['job_id'] ?? null) === $job['id'] && ($place['company_id'] ?? null) === $i->company_id, 409, 'provider_relationship_mismatch');
            $address['provider'] = $place['address'];
        }
        $key = hash('sha256', json_encode([$shoot->id, $shoot->client_id, $data, $address]));
        $binding = collect($data)->except('relationship_evidence_url')->all();
        $binding['consent'] += ['verified_by' => $actor, 'verified_at' => now()->toIso8601String(), 'automation_opt_in' => false];
        $attributes = $binding + ['shoot_id' => $shoot->id, 'client_id' => $shoot->client_id,
            'identity' => app(AryeoCatalog::class)->identity($shoot->loadMissing('client')), 'address' => $address,
            'association_status' => empty($data['provider_job_id']) ? 'awaiting_listing' : 'matched', 'verified_by' => $actor, 'verified_at' => now()];
        $d = LockedWrite::run(fn () => DB::transaction(function () use ($key, $attributes, $shoot, $data) {
            if ($existing = AryeoApiDestination::where('binding_key', $key)->first()) {
                abort_if($existing->superseded_at, 409, 'binding_superseded_requires_fresh_review');

                return $existing;
            }
            $previous = AryeoApiDestination::where('shoot_id', $shoot->id)->whereNull('superseded_at')
                ->where('unit_id', $data['unit_id'] ?? null)->where('workflow', $data['workflow']);
            abort_if((clone $previous)->whereNotNull('active_export_id')->exists(), 409, 'previous_binding_requires_resolution');
            $previous->update(['on_hold' => true, 'superseded_at' => now()]);

            return AryeoApiDestination::create($attributes + ['binding_key' => $key]);
        }), 'aryeo-api.bind');

        return response()->json(['destination' => $d], 201);
    }

    public function hold(Request $r, AryeoApiDestination $destination)
    {
        $this->actor($r);
        $this->access($r, $destination->shoot);
        $data = $r->validate(['on_hold' => 'required|boolean', 'distribution_authorized' => 'sometimes|declined']);
        abort_if(! $data['on_hold'] && $destination->superseded_at, 409, 'destination_superseded');
        $changes = ['on_hold' => $data['on_hold']];
        if (array_key_exists('distribution_authorized', $data)) {
            $changes['consent'] = array_replace($destination->consent, ['distribution_authorized' => false]);
        }
        $destination->update($changes);

        // Consent withdrawal never erases already transferred copies.
        return response()->json(['destination' => $destination]);
    }

    public function preview(Request $r, AryeoApiDestination $destination)
    {
        $actor = $this->actor($r);
        $this->access($r, $destination->shoot);

        return response()->json(['export' => $this->exportData(app(Exports::class)->preview($destination, $actor))], 201);
    }

    public function handoff(Request $r, AryeoApiDestination $destination)
    {
        $actor = $this->actor($r);
        $this->access($r, $destination->shoot);
        $data = $r->validate(['executor' => 'required|in:api,bridge', 'old_executor_stopped' => 'required|accepted', 'evidence_url' => 'required|url:https|max:2048']);
        abort_if($destination->active_export_id || $destination->executor_token, 409, 'unfinished_export_requires_resolution');
        $requestIds = \App\Models\AryeoRequest::where(fn ($q) => $q->where('shoot_id', $destination->shoot_id)->orWhere('listing_id', $destination->legacy_listing_id))->pluck('id');
        abort_if(\App\Models\AryeoJob::whereIn('request_record_id', $requestIds)->whereNotIn('status', ['completed', 'cancelled'])->exists(), 409, 'legacy_operation_unresolved');
        $exports = AryeoApiExport::where('destination_id', $destination->id)->get();
        abort_if(\App\Models\AryeoApiOperation::whereIn('export_id', $exports->pluck('id'))->whereIn('status', ['sent', 'unknown'])->exists()
            || AryeoApiCase::whereIn('export_id', $exports->pluck('id'))->where('status', 'open')->exists(), 409, 'outcome_unknown');
        $inventory = app(\App\Services\Aryeo\Api\Inventory::class)->read($destination);
        abort_if(collect($inventory)->contains(fn ($a) => ! $a['delivery_id']), 409, 'undelivered_assets_require_resolution');
        foreach ($exports->where('status', 'delivered') as $e) {
            foreach ($e->assets as $asset) {
                $row = collect($inventory)->firstWhere('id', $asset->place_asset_id);
                abort_unless($row && $row['asset_id'] === $asset->provider_asset_id && $row['delivery_id'], 409, 'provider_delivery_mismatch');
            }
        }
        LockedWrite::run(fn () => DB::transaction(function () use ($destination, $data) {
            $destination->refresh();
            abort_if($destination->active_export_id || $destination->executor_token, 409, 'unfinished_export_requires_resolution');
            $ids = \App\Models\AryeoRequest::where(fn ($q) => $q->where('shoot_id', $destination->shoot_id)->orWhere('listing_id', $destination->legacy_listing_id))->select('id');
            abort_if(\App\Models\AryeoJob::whereIn('request_record_id', $ids)->whereNotIn('status', ['completed', 'cancelled'])->exists(), 409, 'legacy_operation_unresolved');
            $destination->update(['executor' => $data['executor'] === 'bridge' ? 'bridge' : null]);
        }), 'aryeo-api.handoff');
        if ($e = $exports->sortByDesc('created_at')->first()) {
            AryeoApiCase::create(['export_id' => $e->id, 'kind' => 'executor_handoff', 'status' => 'resolved', 'reason' => 'Verified destination handoff to '.$data['executor'],
                'resolution_url' => $data['evidence_url'], 'resolution' => ['inventory' => $inventory], 'created_by' => $actor, 'escalate_at' => now(), 'resolved_at' => now()]);
        }

        return response()->json(['destination' => $destination->fresh()]);
    }

    public function action(Request $r, AryeoApiExport $export, string $action)
    {
        $actor = $this->actor($r);
        $this->access($r, $export->destination->shoot);
        if ($action === 'approve') {
            $data = $r->validate(['revision' => 'required|string|size:64', 'editing_window_until' => 'required|date|after:now|before:'.now()->addHours(8)->toIso8601String(),
                'no_external_editing_acknowledged' => 'required|accepted']);
            app(Exports::class)->approve($export, $data['revision'], $actor, $data['editing_window_until']);
        } elseif ($action === 'submit') {
            app(Exports::class)->submit($export);
        } elseif ($action === 'renew-approval') {
            $data = $r->validate(['revision' => 'required|string|size:64', 'editing_window_until' => 'required|date|after:now|before:'.now()->addHours(8)->toIso8601String(),
                'no_external_editing_acknowledged' => 'required|accepted']);
            abort_unless($export->approved_at && $export->revision === $data['revision'] && in_array($export->status, ['approved', 'blocked'], true)
                && ! $export->cancelled_at && ! $export->destination->executor_token, 409, 'approval_not_renewable');
            abort_if($export->operations()->whereIn('status', ['sent', 'unknown'])->exists() || $export->cases()->where('status', 'open')->exists(), 409, 'outcome_unknown');
            app(\App\Services\Aryeo\Api\Snapshots::class)->assertCurrent($export, true);
            $remote = app(\App\Services\Aryeo\Api\Inventory::class)->read($export->destination);
            $owned = $export->assets()->where('ownership', 'created')->pluck('place_asset_id')->all();
            abort_unless(array_values(array_filter($remote, fn ($a) => ! in_array($a['id'], $owned, true))) === $export->remote_baseline, 409, 'remote_inventory_changed');
            $export->update(['editing_window_until' => $data['editing_window_until']]);
            AryeoApiCase::create(['export_id' => $export->id, 'kind' => 'approval_renewal', 'status' => 'resolved', 'reason' => 'Same immutable revision reviewed; supervised editing window renewed',
                'resolution' => ['revision' => $export->revision, 'editing_window_until' => $data['editing_window_until']], 'created_by' => $actor, 'escalate_at' => now(), 'resolved_at' => now()]);
        } elseif ($action === 'reconcile') {
            app(Operations::class)->reconcile($export);
        } elseif ($action === 'cancel') {
            $data = $r->validate(['reason' => 'required|string|max:1000']);
            app(Exports::class)->cancel($export, $actor, $data['reason']);
        } elseif ($action === 'release-executor') {
            $r->validate(['previous_executor_stopped' => 'required|accepted', 'evidence_url' => 'required|url:https|max:2048']);
            LockedWrite::run(fn () => DB::transaction(function () use ($export, $r, $actor) {
                $export->refresh();
                $destination = $export->destination()->firstOrFail();
                abort_unless($destination->active_export_id === $export->id, 409, 'another_export_owns_destination');
                abort_if($export->operations()->whereIn('status', ['sent', 'unknown'])->exists(), 409, 'outcome_unknown');
                abort_if($destination->executor_expires_at?->isFuture(), 409, 'executor_still_active');
                // Operator attests this revision's process stopped; expiry alone is insufficient.
                $destination->update(['executor_token' => null, 'executor_expires_at' => null]);
                if (in_array($export->status, ['running', 'queued'], true)) {
                    $export->update(['status' => 'blocked', 'blocker' => 'stopped_executor_requires_resume']);
                    DB::table('aryeo_api_attempts')->where('export_id', $export->id)->whereNull('finished_at')->update(['result' => 'stopped', 'finished_at' => now()]);
                }
                AryeoApiCase::create(['export_id' => $export->id, 'kind' => 'executor_handoff', 'status' => 'resolved', 'reason' => 'Previous executor stopped by operator',
                    'resolution_url' => $r->input('evidence_url'), 'created_by' => $actor, 'escalate_at' => now(), 'resolved_at' => now()]);
            }), 'aryeo-api.release-executor');
        } else {
            abort(404);
        }

        return response()->json(['export' => $this->exportData($export->fresh())]);
    }

    public function evidence(Request $r, AryeoApiExport $export)
    {
        $actor = $this->actor($r);
        $this->access($r, $export->destination->shoot);
        $states = ['association' => ['matched', 'ambiguous', 'needs_correction'], 'media' => ['verified', 'incomplete'],
            'branding' => ['verified', 'missing', 'unable_to_verify'], 'hd' => ['eligible', 'verified', 'not_eligible'],
            'staging' => ['processing', 'available', 'not_eligible'], 'notification' => ['verified', 'unexpected', 'unverified']];
        $data = $r->validate(['feature' => ['required', Rule::in(array_keys($states))], 'status' => ['required', Rule::in($states[$r->input('feature')] ?? [])],
            'listing_url' => 'required|url:https|max:2048', 'evidence_url' => 'required|url:https|max:2048', 'revision' => 'required|string|size:64', 'observed_at' => 'required|date|before_or_equal:now']);
        abort_unless(hash_equals($export->revision, $data['revision']) && $data['listing_url'] === $export->snapshot['listing_url'], 409, 'evidence_identity_mismatch');
        LockedWrite::run(fn () => DB::transaction(function () use ($data, $export, $actor) {
            AryeoApiEvidence::create($data + ['export_id' => $export->id, 'verified_by' => $actor]);
            $export->refresh();
            $export->update(['outcomes' => array_replace($export->outcomes, [$data['feature'] => $data['status']])]);
        }), 'aryeo-api.evidence');

        return response()->json(['export' => $this->exportData($export->fresh())]);
    }

    public function correction(Request $r, AryeoApiExport $export)
    {
        $actor = $this->actor($r);
        $this->access($r, $export->destination->shoot);
        $data = $r->validate(['kind' => 'required|in:correction,removal,wrong_destination,unauthorized_release,duplicate_delivery', 'reason' => 'required|string|max:1000',
            'owner' => 'required|string|max:120', 'next_action' => 'required|string|max:255']);
        $case = LockedWrite::run(fn () => DB::transaction(function () use ($data, $export, $actor) {
            $destination = $export->destination()->firstOrFail();
            $case = AryeoApiCase::create($data + ['export_id' => $export->id, 'created_by' => $actor, 'escalate_at' => now()->addMinutes(30)]);
            // A correction of an older revision cannot steal a newer revision's
            // durable ownership, including when its provider request is in flight.
            $destination->update(['on_hold' => true, 'active_export_id' => $destination->active_export_id ?? $export->id]);
            $export->assets()->update(['grant_revoked_at' => now()]);
            if (in_array($data['kind'], ['wrong_destination', 'unauthorized_release', 'duplicate_delivery'], true)) {
                $i = $destination->installation;
                $i->update(['paused_workflows' => array_values(array_unique([...($i->paused_workflows ?? []), $destination->workflow]))]);
            }

            return $case;
        }), 'aryeo-api.open-correction');

        return response()->json(['case' => $case], 201);
    }

    private function exportData(AryeoApiExport $e): array
    {
        return $e->toArray() + ['assets' => $e->assets()->get()->toArray(), 'operations' => $e->operations()->get()->toArray(),
            'cases' => $e->cases()->get()->toArray(), 'attempts' => DB::table('aryeo_api_attempts')->where('export_id', $e->id)->orderBy('id')->get()->toArray(),
            'evidence' => AryeoApiEvidence::where('export_id', $e->id)->orderBy('observed_at')->get()->toArray()];
    }

    public function resolveCase(Request $r, AryeoApiCase $case)
    {
        $actor = $this->actor($r);
        $e = AryeoApiExport::findOrFail($case->export_id);
        $this->access($r, $e->destination->shoot);
        abort_if($case->kind === 'unknown_operation', 409, 'use_operation_reconciliation');
        $data = $r->validate(['evidence_url' => 'required|url:https|max:2048', 'provider_objects_accounted_for' => 'required|accepted',
            'downstream_verified' => 'required|accepted', 'fix_url' => 'nullable|url:https|max:2048', 'regression_test_url' => 'nullable|url:https|max:2048',
            'supervised_runs' => 'sometimes|array|size:2', 'supervised_runs.*' => 'required|url:https|distinct|max:2048']);
        $critical = in_array($case->kind, ['wrong_destination', 'unauthorized_release', 'duplicate_delivery'], true);
        abort_if($critical && (empty($data['fix_url']) || empty($data['regression_test_url']) || count($data['supervised_runs'] ?? []) !== 2), 422, 'fix_regression_and_two_supervised_runs_required');
        abort_if($e->operations()->whereIn('status', ['sent', 'unknown'])->exists() || $e->destination->executor_token, 409, 'executor_or_outcome_unresolved');
        $remote = app(\App\Services\Aryeo\Api\Inventory::class)->read($e->destination);
        abort_if(collect($remote)->contains(fn ($a) => ! $a['delivery_id']), 409, 'undelivered_assets_require_resolution');
        LockedWrite::run(fn () => DB::transaction(function () use ($case, $e, $data, $actor) {
            $destination = $e->destination()->firstOrFail();
            abort_unless($case->fresh()->status === 'open', 409, 'case_already_resolved');
            abort_if($destination->executor_token || $e->operations()->whereIn('status', ['sent', 'unknown'])->exists(), 409, 'executor_or_outcome_unresolved');
            $case->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_url' => $data['evidence_url'], 'resolution' => $data + ['verified_by' => $actor, 'verified_at' => now()->toIso8601String()]]);
            if (! $e->cases()->where('status', 'open')->exists()) {
                $otherCases = AryeoApiCase::whereIn('export_id', AryeoApiExport::where('destination_id', $destination->id)->select('id'))->where('status', 'open')->exists();
                if (! $otherCases && (! $destination->active_export_id || $destination->active_export_id === $e->id)) {
                    $destination->update(['active_export_id' => null, 'executor' => null, 'on_hold' => true]);
                }
                if ($e->cancelled_at) {
                    $e->update(['status' => 'cancelled', 'blocker' => null]);
                }
                // Deliberately retain the hold and workflow pause for explicit review.
            }
        }), 'aryeo-api.resolve-case');

        return response()->json(['case' => $case->fresh()]);
    }

    public function metrics(Request $r, AryeoApiExport $export)
    {
        $actor = $this->actor($r);
        $this->access($r, $export->destination->shoot);
        $data = $r->validate(['operator_minutes' => 'required|numeric|min:0|max:10000', 'recovery_actions' => 'required|string|max:2000',
            'listing_live_at' => 'nullable|date', 'benefits_verified_at' => 'nullable|date', 'expected_notifications' => 'required|integer|min:0|max:100',
            'observed_notifications' => 'required|integer|min:0|max:100', 'evidence_url' => 'required|url:https|max:2048']);
        $export->update(['metrics' => $data + ['recorded_by' => $actor, 'recorded_at' => now()->toIso8601String()]]);

        return response()->json(['export' => $this->exportData($export)]);
    }

    public function alerts()
    {
        return response()->json(['alerts' => DB::table('aryeo_api_alerts')->whereNull('acknowledged_at')->orderBy('created_at')->limit(100)->get()]);
    }

    public function acknowledgeAlert(Request $r)
    {
        $actor = $this->actor($r);
        $r->validate(['key' => 'required|string|max:255']);
        DB::table('aryeo_api_alerts')->where('key', $r->input('key'))->update(['acknowledged_at' => now(), 'acknowledged_by' => $actor]);

        return response()->json(['acknowledged' => true]);
    }

    public function support(Request $r, AryeoApiExport $export)
    {
        $this->access($r, $export->destination->shoot);
        $data = $this->exportData($export);
        // Omit recipient PII and hosted URLs from the automatically shareable packet.
        unset($data['snapshot'], $data['assets']);
        $data['destination'] = $export->destination->only(['id', 'shoot_id', 'unit_id', 'workflow', 'installation_id', 'provider_job_id', 'provider_place_id', 'legacy_listing_id']);
        $data['generated_at'] = now()->toIso8601String();

        return response()->json($data)->header('Content-Disposition', 'attachment; filename="aryeo-support-'.$export->id.'.json"');
    }
}
