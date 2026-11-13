<?php

namespace App\Services\Shoots;

use App\Models\{Shoot, ShootFile, ShootService, User};
use App\Services\RolePermissionService;
use App\Services\Schedule\ScheduleInstantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** One read policy and one locked commit policy for web, workers and repairs. */
class DeliveryPlanService
{
    private array $releasedCache = [];
    private array $releasedEvidence = [];
    private array $managedCache = [];
    private array $readPlanCache = [];
    private ?bool $ledgerExists = null;
    private function ledgerExists(): bool { return $this->ledgerExists ??= \Illuminate\Support\Facades\Schema::hasTable('shoot_delivery_receipts'); }

    public function forgetReleasedFiles(?Shoot $shoot = null): void { if ($shoot) { unset($this->releasedCache[$shoot->id], $this->releasedEvidence[$shoot->id], $this->managedCache[$shoot->id], $this->readPlanCache[$shoot->id]); } else { $this->releasedCache = []; $this->releasedEvidence = []; $this->managedCache = []; $this->readPlanCache = []; $this->ledgerExists = null; } }

    public function usesComponents(Shoot $shoot): bool
    {
        if (! $shoot->id || ! $this->ledgerExists()) return false;
        return $this->managedCache[$shoot->id] ??= $shoot->serviceItems()->get()->contains(fn ($item) => collect($item->delivery_requirements ?? [])->contains(fn ($component) => ($component['kind'] ?? '') !== 'legacy'));
    }
    /** Only the full authorized batch loader may prime this root-wide fact. */
    public function primeRequirements(Shoot $shoot, iterable $fullItems): void
    {
        $this->managedCache[$shoot->id] = collect($fullItems)->contains(fn ($item) => collect($item->delivery_requirements ?? [])->contains(fn ($component) => ($component['kind'] ?? '') !== 'legacy'));
    }
    public function canFinalize(?User $actor): bool
    {
        return $actor && in_array($actor->role, ['admin', 'superadmin', 'super_admin', 'editing_manager'], true)
            && app(RolePermissionService::class)->userCan($actor, 'shoots', 'manage');
    }

    public function executionKey(Shoot $shoot): string
    {
        return app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot)
            ? $shoot->active_work_started_at->toIso8601String() : 'initial';
    }

    public function itemExecutionKey(Shoot $shoot, ShootService $item): string
    {
        return data_get($item->delivery_requirements, '0.execution_key') ?: $this->executionKey($shoot);
    }

    public function plan(Shoot $shoot, ?User $actor): array
    {
        if (! $actor && isset($this->readPlanCache[$shoot->id])) return $this->readPlanCache[$shoot->id];
        // Client and unit projections are not authoritative provider state.
        // Actor preflight and queued validation always read the persisted root.
        $shoot = $shoot->fresh() ?? $shoot;
        $items = $shoot->serviceItems()->with(['service.category', 'unit'])->get();
        $all = $shoot->files()->get();
        $current = app(ServiceDeliveryEligibility::class)->currentCycleFiles($shoot, $all)->keyBy('id');
        $receipts = DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->whereNull('revoked_at')->orderByDesc('id')->get();
        $suspended = collect([$shoot->status, $shoot->workflow_status])->contains(fn ($state) => in_array(strtolower((string) $state), ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined', 'requested', 'import_draft'], true));
        $components = [];
        foreach ($items as $item) {
            $cancelled = $item->cancelled_at || $item->workflow_status === 'cancelled' || $item->delivery_status === 'cancelled';
            $future = $item->scheduled_at && app(ScheduleInstantResolver::class)->forServiceItem($shoot, $item)?->isFuture();
            foreach (app(DeliveryComponentRequirements::class)->forItem($item) as $requirement) {
                $kind = $requirement['kind'];
                $componentExecution = $requirement['execution_key'] ?? $this->executionKey($shoot);
                $files = $current->filter(fn ($file) => ((int) $file->shoot_service_id === (int) $item->id || (! $file->shoot_service_id && $items->count() === 1))
                    && $this->contentKind($file) === $kind
                    && in_array($file->workflow_stage, ['completed', 'verified'], true) && $this->safe($file));
                $receipt = $receipts->first(fn ($row) => (int) $row->shoot_service_id === (int) $item->id && $row->component_key === $requirement['key']
                    && ($row->execution_key === $componentExecution || (empty($requirement['execution_key']) && $item->workflow_status === 'delivered' && $item->delivery_status === 'delivered')));
                $external = $this->externalEvidence($shoot, $item, $kind, $items);
                $resolved = $cancelled || ($kind === 'legacy' && $item->workflow_status === 'delivered' && $item->delivery_status === 'delivered')
                    || ($receipt && $this->validReceipt($receipt, $all->keyBy('id'), $external,
                        $receipt->execution_key !== $componentExecution && $item->workflow_status === 'delivered' && $item->delivery_status === 'delivered' ? null : $current));
                // A receipt approves exact file versions, not every future upload
                // to this service. Keep old releases available while exposing the
                // added outputs to the ordinary reviewed finalization path.
                $approvedVersions = collect($receipt ? (json_decode($receipt->evidence, true)['files'] ?? []) : []);
                $additionalOutput = $resolved && ! $cancelled && $kind !== 'legacy' && $files->contains(fn ($file) =>
                    ! $this->releasedFile($file) && ! $approvedVersions->contains(fn ($entry) =>
                        (int) $entry['id'] === (int) $file->id && (int) $entry['version'] === (int) ($file->content_version ?: 1)));
                if ($additionalOutput) $resolved = false;
                $reasons = $additionalOutput ? ['additional_output'] : [];
                if (! $resolved) {
                    if ($suspended) $reasons[] = 'work_suspended';
                    if ($future) $reasons[] = 'future_appointment';
                    if ($files->isEmpty() && $all->contains(fn ($file) => (int) $file->shoot_service_id === (int) $item->id && $this->contentKind($file) === $kind && $file->isBlockedFromDelivery())) $reasons[] = 'unsafe_scan';
                    if ($files->isEmpty() && $items->count() > 1 && $all->contains(fn ($file) => ! $file->shoot_service_id && $this->contentKind($file) === $kind)) $reasons[] = 'unassigned_media';
                    if ($files->isEmpty()) {
                        if ($external && ($external['readiness'] ?? null) === 'not_ready') $reasons[] = 'provider_not_ready';
                        elseif ($external && ($external['readiness'] ?? null) !== 'ready') $reasons[] = 'external_review_required';
                        elseif (! $external) $reasons[] = $kind === 'video' ? 'video_pending' : ($kind === 'floorplan' || $kind === 'tour' ? 'provider_output_pending' : 'output_pending');
                    }
                    if ($receipt && ! $resolved) $reasons[] = 'outdated_approval';
                }
                $ready = ! $resolved && ! $suspended && ! $future && ($files->isNotEmpty() || ($external && ($external['readiness'] ?? null) === 'ready'));
                $methods = [];
                if ($ready && $files->isNotEmpty()) $methods[] = 'media';
                if (! $resolved && ! $suspended && ! $future && $external && ($external['readiness'] ?? null) !== 'not_ready') $methods[] = 'external';
                if (! $resolved && ! $suspended && ! $future && $this->canFinalize($actor)) $methods[] = 'manual_no_content';
                $components[] = ['id' => $item->id.':'.$requirement['key'], 'shoot_service_id' => $item->id,
                    'unit_id' => $item->shoot_unit_id, 'service_name' => $item->service?->name, 'label' => $requirement['label'], 'kind' => $kind,
                    'status' => $cancelled ? 'cancelled' : ($resolved ? 'delivered' : ($ready ? 'ready' : 'pending')),
                    'resolved' => (bool) $resolved, 'required' => true, 'file_ids' => $files->modelKeys(), 'output_count' => $files->count(),
                    'reason_codes' => $reasons, 'reason' => $this->reason($reasons[0] ?? null, $requirement['label']), 'methods' => $methods,
                    'external_url' => $external['url'] ?? null, 'external_evidence' => $external,
                    'photographer_id' => $item->photographer_id, 'editor_id' => $kind === 'video' ? ($item->video_editor_id ?: $item->editor_id) : $item->editor_id,
                    'scheduled_at' => $item->scheduled_at?->toIso8601String(), 'receipt_id' => $receipt?->id,
                    'execution_key' => $componentExecution, 'delivery_method' => $resolved ? $receipt?->method : null,
                    'versions' => $files->map(fn ($file) => ['id' => $file->id, 'version' => (int) ($file->content_version ?: 1)])->values()->all()];
            }
        }
        $revision = hash('sha256', json_encode([$shoot->id, $this->executionKey($shoot), $shoot->workflow_status, $components], JSON_THROW_ON_ERROR));
        $ready = collect($components)->where('status', 'ready')->pluck('id')->all();
        $pending = collect($components)->where('resolved', false)->pluck('id')->all();
        $result = ['shoot_id' => $shoot->id, 'revision' => $revision, 'components' => $components,
            'ready_component_ids' => $ready, 'remaining_component_ids' => $pending,
            'capabilities' => ['can_finalize' => $this->canFinalize($actor), 'can_manual_no_content' => $this->canFinalize($actor), 'can_assign_service' => app(ShootMediaServiceAssignment::class)->canAssign($shoot, $actor)],
            'summary' => $pending === [] ? 'All required work is resolved.' : ($ready ? 'Ready content can be delivered; other components remain pending.' : ($components ? ($this->reasonForPlan($components)) : 'No required delivery components.'))];
        if (! $actor) $this->readPlanCache[$shoot->id] = $result;
        return $result;
    }

    /** Client-safe progress only: no provider URLs, evidence, file IDs or staff capabilities. */
    public function componentSummaries(Shoot $shoot): array
    {
        if (! $this->usesComponents($shoot)) return [];
        return collect($this->plan($shoot, null)['components'])->groupBy('shoot_service_id')->map(fn ($components) => $components->map(fn ($component) => [
            'kind' => $component['kind'], 'label' => $component['label'], 'status' => $component['status'],
            'without_content' => ($component['delivery_method'] ?? null) === 'manual_no_content',
        ])->values()->all())->all();
    }

    public function validateSelection(Shoot $shoot, User $actor, array $intent): array
    {
        abort_unless($this->canFinalize($actor->fresh() ?? $actor), 403, 'You do not have permission to finalize this work.');
        $plan = $this->plan($shoot, $actor);
        if (! empty($intent['plan_revision']) && ! hash_equals($plan['revision'], $intent['plan_revision'])) {
            throw new \App\Exceptions\PublicApiResponseException(response()->json(['message' => 'Delivery changed. Review the current plan.', 'delivery_plan' => $plan], 409));
        }
        $mode = $intent['mode'] ?? 'media';
        if (! in_array($mode, ['media', 'external', 'manual_no_content', 'auto'], true)) throw ValidationException::withMessages(['mode' => 'Choose a delivery method.']);
        if ($mode !== 'media' && empty($intent['component_ids'])) throw ValidationException::withMessages(['component_ids' => 'Select the exact components for this delivery method.']);
        $ids = $intent['component_ids'] ?? collect($plan['components'])->filter(fn ($c) => in_array($mode, $c['methods'], true) && $c['status'] === 'ready')->pluck('id')->all();
        if (isset($intent['shoot_service_id'])) $ids = array_values(array_filter($ids, fn ($id) => str_starts_with($id, $intent['shoot_service_id'].':')));
        if (! is_array($ids) || $ids === [] || count($ids) !== count(array_unique($ids))) throw ValidationException::withMessages(['component_ids' => $plan['summary']]);
        $methods = [];
        foreach ($ids as $id) {
            $component = collect($plan['components'])->firstWhere('id', $id);
            $method = $mode === 'auto' && $component ? $this->automaticMethod($component, ! empty($intent['allow_without_content'])) : $mode;
            if (! $component || $component['resolved'] || ! in_array($method, $component['methods'], true)) throw ValidationException::withMessages(['component_ids' => $component['reason'] ?? 'This component cannot be delivered with the selected method.']);
            $methods[$id] = $method;
        }
        if (in_array('manual_no_content', $methods, true) && (mb_strlen(trim($intent['reason'] ?? '')) < 5 || empty($intent['confirmed']))) {
            throw ValidationException::withMessages(['reason' => 'Confirm the selected missing work and provide a reason of at least five characters.']);
        }
        if (in_array('external', $methods, true) && empty($intent['confirmed'])) throw ValidationException::withMessages(['confirmed' => 'Confirm that the selected requirements are present at the reviewed external destination.']);
        return ['component_ids' => array_values($ids), 'mode' => $mode, 'reason' => trim($intent['reason'] ?? ''), 'confirmed' => (bool) ($intent['confirmed'] ?? false),
            'allow_without_content' => $mode === 'auto' && ! empty($intent['allow_without_content']), 'component_methods' => $methods,
            'plan_revision' => $plan['revision'], 'idempotency_key' => $intent['idempotency_key'] ?? (string) Str::uuid()];
    }

    /** Choose from server-owned readiness; a provider URL alone never implies delivery. */
    private function automaticMethod(array $component, bool $allowWithoutContent): ?string
    {
        if ($component['status'] === 'ready') {
            foreach (['media', 'external'] as $method) if (in_array($method, $component['methods'], true)) return $method;
        }
        // Explicitly selected, reviewed external work keeps the existing confirmation
        // path. It is never included in the automatically selected ready set.
        if (! $allowWithoutContent && in_array('external', $component['methods'], true)
            && in_array('external_review_required', $component['reason_codes'], true)) return 'external';
        return $allowWithoutContent && in_array('manual_no_content', $component['methods'], true) ? 'manual_no_content' : null;
    }

    public function isReplay(Shoot $shoot, User $actor, array $intent): bool
    {
        abort_unless($this->canFinalize($actor->fresh() ?? $actor), 403, 'You do not have permission to finalize this work.');
        if (empty($intent['idempotency_key'])) return false;
        $rows = DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->where('operation_key', $intent['idempotency_key'])->get();
        if ($rows->isEmpty()) return false;
        $expected = $rows->map(fn ($row) => $row->shoot_service_id.':'.$row->component_key)->sort()->values()->all();
        $requested = collect($intent['component_ids'] ?? $expected)->sort()->values()->all();
        $same = $expected === $requested && $rows->every(fn ($row) => (int) $row->actor_id === (int) $actor->id
            && (json_decode($row->evidence, true)['operation_mode'] ?? $row->method) === ($intent['mode'] ?? 'media')
            && (bool) (json_decode($row->evidence, true)['allow_without_content'] ?? false) === (bool) ($intent['allow_without_content'] ?? false)
            && trim((string) $row->reason) === trim((string) ($intent['reason'] ?? ''))
            && (! isset($intent['plan_revision']) || hash_equals($row->plan_revision, $intent['plan_revision']))
            && ((bool) (json_decode($row->evidence, true)['confirmed'] ?? false)) === (bool) ($intent['confirmed'] ?? false));
        if (! $same) throw new \App\Exceptions\PublicApiResponseException(response()->json(['message' => 'This delivery operation key was already used for a different selection or method.'], 409));
        return true;
    }

    /** Owning caller holds the short write transaction. This method has no external side effects. */
    public function commitSelection(Shoot $shoot, User $actor, array $intent): array
    {
        if ($this->isReplay($shoot, $actor, $intent)) return ['file_ids' => [], 'service_ids' => [], 'already_applied' => true];
        $intent = $this->validateSelection($shoot, $actor, $intent);
        $plan = $this->plan($shoot, $actor);
        $ids = []; $services = []; $approvedComponents = [];
        foreach ($plan['components'] as $component) {
            if (! in_array($component['id'], $intent['component_ids'], true)) continue;
            [$serviceId, $key] = explode(':', $component['id'], 2);
            $method = $intent['component_methods'][$component['id']];
            $evidence = ['files' => $method === 'media' ? $component['versions'] : [],
                'external' => $method === 'external' ? $component['external_evidence'] : null,
                'missing_components' => $method === 'manual_no_content' ? [$component['label']] : [], 'confirmed' => $intent['confirmed'],
                'operation_mode' => $intent['mode'], 'allow_without_content' => $intent['allow_without_content']];
            // The historical single-line fallback is an explicit reviewed scope.
            // Bind its exact approved files atomically; never guess across lines.
            if ($method === 'media' && $shoot->serviceItems()->count() === 1) {
                $bound = DB::table('shoot_files')->where('shoot_id', $shoot->id)->whereIn('id', $component['file_ids'])->whereNull('shoot_service_id')->update(['shoot_service_id' => $serviceId]);
                if ($bound) $shoot->workflowLogs()->create(['user_id' => $actor->id, 'action' => 'legacy_output_scope_reviewed', 'details' => 'Approved the unique booked service ownership.', 'metadata' => ['shoot_service_id' => (int) $serviceId, 'file_ids' => $component['file_ids']]]);
            }
            DB::table('shoot_delivery_receipts')->insert(['shoot_id' => $shoot->id, 'shoot_service_id' => $serviceId, 'component_key' => $key,
                'execution_key' => $component['execution_key'], 'method' => $method, 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR),
                'actor_id' => $actor->id, 'reason' => $intent['reason'] ?: null, 'plan_revision' => $intent['plan_revision'],
                'operation_key' => $intent['idempotency_key'], 'delivered_at' => now()]);
            if ($method === 'media') $ids = array_merge($ids, $component['file_ids']);
            $services[] = (int) $serviceId;
            $approvedComponents[] = ['shoot_service_id' => (int) $serviceId, 'kind' => $component['kind'], 'method' => $method];
        }
        $shoot->workflowLogs()->create(['user_id' => $actor->id, 'action' => 'delivery_components_approved', 'details' => $intent['reason'] ?: 'Reviewed component delivery',
            'metadata' => ['component_ids' => $intent['component_ids'], 'method' => $intent['mode'], 'operation_key' => $intent['idempotency_key'], 'plan_revision' => $intent['plan_revision']]]);
        // A scoped manual approval may change no file or aggregate status.
        // Still publish the new root revision through ordinary cache observers.
        $shoot->touch();
        $this->forgetReleasedFiles($shoot);
        return ['file_ids' => array_values(array_unique($ids)), 'service_ids' => array_values(array_unique($services)), 'components' => $approvedComponents, 'already_applied' => false];
    }

    /** Trusted provider job only; the outer finalizer owns the short writer transaction. */
    public function commitProviderSelection(Shoot $shoot, array $selections, string $operationKey): array
    {
        // Roll back the entire provider batch before the finalizer catches a
        // stale evidence exception inside its owning transaction.
        return DB::transaction(fn () => $this->commitProviderSelectionAtomically($shoot, $selections, $operationKey));
    }

    private function commitProviderSelectionAtomically(Shoot $shoot, array $selections, string $operationKey): array
    {
        $provider = app(ProviderAutoDeliveryService::class);
        abort_if($provider->blocked($shoot), 409, 'Provider auto delivery is disabled or suspended.');
        $existing = DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->where('operation_key', $operationKey)->exists();
        if ($existing) return ['already_applied' => true, 'file_ids' => [], 'service_ids' => [], 'components' => []];
        $ids = []; $serviceIds = []; $approved = [];
        $bytes = 0;
        foreach ($selections as $selection) foreach ($selection['files'] as $entry) {
            abort_unless(($entry['local_bytes'] ?? 0) > 0 && ($entry['local_preview_bytes'] ?? 0) > 0, 409, 'Local provider output verification is unavailable.');
            $bytes += $entry['local_bytes'] + $entry['local_preview_bytes'];
            abort_if($bytes > ProviderAutoDeliveryService::MAX_LOCAL_VERIFICATION_BYTES, 409, 'Provider output requires a smaller reviewed delivery batch.');
        }
        $this->forgetReleasedFiles($shoot);
        $plan = $this->plan($shoot, null);
        foreach ($selections as $selection) {
            [$lineId, $key] = explode(':', $selection['component_id'], 2);
            $item = $shoot->serviceItems()->with(['service.category', 'unit'])->findOrFail($lineId);
            abort_unless(app(ProviderAutoDeliverySettings::class)->supportedItems($shoot)->contains('id', $item->id), 409, 'The service is no longer eligible provider work.');
            abort_if($provider->blocked($shoot, $item), 409, 'The provider service is suspended or has a future appointment.');
            $requirement = collect($provider->parts($item, $selection['provider']))->firstWhere('key', $key);
            abort_unless($requirement && $this->itemExecutionKey($shoot, $item) === $selection['execution_key'], 409, 'The booked provider execution changed.');
            $component = collect($plan['components'])->firstWhere('id', $selection['component_id']);
            if ($component && $component['resolved']) continue;
            $proof = DB::table('shoot_provider_delivery_verifications')->find($selection['verification_id']);
            $latest = DB::table('shoot_provider_delivery_verifications')->where('shoot_service_id', $item->id)->where('provider', $selection['provider'])
                ->where('execution_key', $selection['execution_key'])->orderByDesc('id')->first();
            $view = $provider->scope($shoot, $item, $selection['provider']);
            $identity = $view?->getAttribute($selection['provider'] === 'iguide' ? 'iguide_property_id' : 'cubicasa_order_id');
            abort_unless($proof && $latest && $latest->id === $proof->id && $proof->state === 'ready' && (int) $proof->shoot_id === (int) $shoot->id
                && (int) $proof->shoot_service_id === (int) $item->id && $proof->execution_key === $selection['execution_key']
                && (int) data_get(json_decode($proof->evidence, true), 'unit_id') === (int) $item->shoot_unit_id
                && $identity === $proof->identity_key && $identity === $selection['identity_key'], 409, 'Verified provider evidence changed.');
            $cutoff = data_get($item->delivery_requirements, '0.execution_started_at') ?: (app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot) ? $shoot->active_work_started_at : $shoot->created_at);
            abort_if(\Carbon\Carbon::parse($proof->verified_at)->lt($cutoff), 409, 'Provider evidence predates this execution.');
            $files = app(ServiceDeliveryEligibility::class)->currentCycleFiles($shoot, $shoot->files()->where('shoot_service_id', $item->id)->get())->keyBy('id');
            if ($key === 'floorplan') {
                $manifest = $provider->assetManifest(json_decode($proof->evidence, true)['floorplans'] ?? []);
                $selectedAssets = array_map(fn ($entry) => ['key' => $entry['asset_key'], 'url' => $entry['original_url']], $selection['files']);
                usort($selectedAssets, fn ($a, $b) => [$a['key'], $a['url']] <=> [$b['key'], $b['url']]);
                abort_unless($manifest !== [] && $manifest === ($selection['expected_assets'] ?? []) && $manifest === $selectedAssets
                    && count(array_unique(array_column($selection['files'], 'id'))) === count($manifest), 409, 'The complete provider asset set changed.');
            }
            foreach ($selection['files'] as $entry) {
                $file = $files->get($entry['id']);
                abort_unless($file && $file->scan_status === ShootFile::SCAN_STATUS_CLEAN && $this->usableOutput($file)
                    && $this->contentKind($file) === $key && (int) ($file->content_version ?: 1) === $entry['version']
                    && $file->path === $entry['path'] && $file->web_path === $entry['web_path'] && $file->storage_path === $entry['storage_path']
                    && $file->getRawOriginal('updated_at') === $entry['updated_at'] && hash_equals(hash('sha256', json_encode($file->metadata)), $entry['metadata_hash'])
                    && data_get($file->metadata, 'source') === $selection['provider'] && data_get($file->metadata, $selection['provider'].'_asset_key') === $entry['asset_key']
                    && ProviderDeliveryUrl::canonical((string) data_get($file->metadata, 'original_url')) === $entry['original_url'], 409, 'Provider output changed or has not cleared scanning and previews.');
                $snapshot = $provider->localOutputSnapshot($file);
                abort_unless($snapshot && collect($snapshot)->every(fn ($value, $key) => ($entry[$key] ?? null) === $value), 409, 'Provider output bytes or previews changed during verification.');
            }
            $external = null;
            if ($key === 'tour') {
                $viewer = $selection['public_viewer'] ?? null;
                abort_unless($viewer && ($viewer['property_id'] ?? null) === $identity
                    && \Carbon\Carbon::parse($viewer['checked_at'])->betweenIncluded(now()->subMinutes(5), now())
                    && \Carbon\Carbon::parse($viewer['updated_at'])->gte($cutoff), 409, 'The current published provider viewer could not be verified.');
                $external = $this->externalEvidence($shoot, $item, $key, $shoot->serviceItems()->with('unit')->get());
                abort_unless($external && ($external['property_id'] ?? null) === $identity
                    && ProviderDeliveryUrl::canonical($external['url']) === ($viewer['checked_url'] ?? null), 409, 'The provider viewer identity or checked URL changed.');
            } else abort_unless($key === 'floorplan' && $selection['files'] !== [], 409, 'No usable provider output was selected.');
            $method = $key === 'tour' ? 'external' : 'media';
            $evidence = ['files' => array_map(fn ($entry) => ['id' => $entry['id'], 'version' => $entry['version']], $selection['files']), 'external' => $external,
                'provider_verified' => ['provider' => $selection['provider'], 'identity_key' => $identity, 'verification_id' => $proof->id,
                    'execution_key' => $selection['execution_key'], 'source' => $proof->source, 'public_viewer' => $selection['public_viewer'] ?? null,
                    'canonical_url' => $external ? $this->canonicalProviderUrl($external['url']) : null,
                    'file_proofs' => $selection['files']]];
            DB::table('shoot_delivery_receipts')->insert(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'component_key' => $key,
                'execution_key' => $selection['execution_key'], 'method' => $method, 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'actor_id' => null,
                'reason' => 'Automatically delivered verified '.$selection['provider'].' output.', 'plan_revision' => $plan['revision'], 'operation_key' => $operationKey, 'delivered_at' => now()]);
            $ids = array_merge($ids, array_column($selection['files'], 'id')); $serviceIds[] = $item->id;
            $approved[] = ['shoot_service_id' => $item->id, 'kind' => $key, 'method' => $method];
        }
        $this->forgetReleasedFiles($shoot);
        return ['already_applied' => $approved === [], 'file_ids' => array_values(array_unique($ids)), 'service_ids' => array_values(array_unique($serviceIds)), 'components' => $approved];
    }

    private function canonicalProviderUrl(string $url): string
    {
        return ProviderDeliveryUrl::canonical($url);
    }

    public function itemResolved(Shoot $shoot, ShootService $item): bool
    {
        if (! $this->ledgerExists()) return $item->workflow_status === 'delivered' && $item->delivery_status === 'delivered';
        if (! $item->is_deliverable || $item->cancelled_at || $item->workflow_status === 'cancelled' || $item->delivery_status === 'cancelled'
            || data_get($item->payout_snapshot, 'billing_correction_only') || data_get($item->payout_snapshot, 'delivery_requirement') === 'financial_only') return true;
        $requirements = app(DeliveryComponentRequirements::class)->forItem($item);
        if ($requirements === []) return true;
        if (count($requirements) === 1 && $requirements[0]['kind'] === 'legacy') return $item->workflow_status === 'delivered' && $item->delivery_status === 'delivered';
        if (! DB::table('shoot_delivery_receipts')->where('shoot_service_id', $item->id)->whereNull('revoked_at')->exists()) return false;
        return collect($this->plan($shoot, null)['components'])->where('shoot_service_id', $item->id)->every(fn ($c) => $c['resolved']);
    }

    /**
     * Explicit staff publication may replace one already approved file. The
     * publisher owns the transaction and supplies the locked pre-mutation copy.
     * Ordinary returned edits never call this method.
     */
    public function recordReviewedVersion(Shoot $shoot, User $actor, ShootFile $publishedAfter, string $operationKey, ?ShootFile $approvedBefore = null): ?int
    {
        $actor = $actor->fresh() ?? $actor;
        abort_unless($this->canFinalize($actor), 403, 'Delivery review permission is required.');
        $shoot = $shoot->fresh() ?? $shoot;
        $after = $publishedAfter->fresh();
        if (! $after || (int) $after->shoot_id !== (int) $shoot->id || ! $after->shoot_service_id
            || $after->workflow_stage !== 'verified' || ! $this->usableOutput($after)
            || collect([$shoot->status, $shoot->workflow_status])->contains(fn ($state) => in_array($state, ['on_hold','hold_on','cancelled','canceled','declined','requested','import_draft'], true))) return null;
        $before = $approvedBefore ? clone $approvedBefore : clone $after;
        if (! $approvedBefore) $before->content_version = (int) ($after->content_version ?: 1) - 1;
        if ((int) ($before->content_version ?: 0) < 1 || $before->workflow_stage !== 'verified' || ! $this->usableOutput($before)
            || (int) $before->shoot_id !== (int) $shoot->id || (int) $before->shoot_service_id !== (int) $after->shoot_service_id
            || $this->contentKind($before) !== $this->contentKind($after)
            || ($before->id === $after->id && (int) $after->content_version !== (int) $before->content_version + 1)) return null;
        $item = $shoot->serviceItems()->with('service.category')->find($after->shoot_service_id);
        if (! $item || ! $item->is_deliverable || $item->cancelled_at || $item->workflow_status === 'cancelled' || $item->delivery_status === 'cancelled'
            || data_get($item->payout_snapshot, 'billing_correction_only') || data_get($item->payout_snapshot, 'delivery_requirement') === 'financial_only'
            || ($item->scheduled_at && app(ScheduleInstantResolver::class)->forServiceItem($shoot, $item)?->isFuture())) return null;
        $kind = $this->contentKind($after);
        $requirement = collect(app(DeliveryComponentRequirements::class)->forItem($item))->firstWhere('kind', $kind);
        if (! $requirement || $kind === 'legacy') return null;
        $execution = $requirement['execution_key'] ?? $this->executionKey($shoot);
        $existing = DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->where('operation_key', $operationKey)->get();
        if ($existing->isNotEmpty()) {
            $row = $existing->first(); $proof = json_decode($row->evidence, true);
            if ($existing->count() === 1 && $row->revoked_at === null && (int) $row->actor_id === (int) $actor->id && (int) $row->shoot_service_id === (int) $item->id
                && $row->component_key === $requirement['key'] && $row->execution_key === $execution && $row->method === 'media'
                && ($proof['files'] ?? []) === [['id' => $after->id, 'version' => (int) ($after->content_version ?: 1)]]) return (int) $row->id;
            throw new \App\Exceptions\PublicApiResponseException(response()->json(['message' => 'This publication operation key was already used for a different output.'], 409));
        }
        $prior = DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->where('shoot_service_id', $item->id)
            ->where('component_key', $requirement['key'])->where('method', 'media')->whereNull('revoked_at')->orderByDesc('id')->get()
            ->first(function ($row) use ($before, $requirement, $item, $execution) {
                if ($row->execution_key !== $execution && (! empty($requirement['execution_key']) || $item->workflow_status !== 'delivered' || $item->delivery_status !== 'delivered')) return false;
                $evidence = json_decode($row->evidence, true);
                // Approval is per file. Another file may have its own newer
                // reviewed receipt, so a stale sibling version cannot revoke
                // this exact unchanged source's historical approval.
                return collect($evidence['files'] ?? [])->contains(fn ($entry) => (int) $entry['id'] === (int) $before->id
                    && (int) $entry['version'] === (int) ($before->content_version ?: 1));
            });
        if (! $prior) return null;
        $receiptId = DB::table('shoot_delivery_receipts')->insertGetId(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id,
            'component_key' => $requirement['key'], 'execution_key' => $execution, 'method' => 'media',
            'evidence' => json_encode(['files' => [['id' => $after->id, 'version' => (int) ($after->content_version ?: 1)]], 'unit_id' => $item->shoot_unit_id,
                'reviewed_replacement' => ['source_file_id' => $before->id, 'source_version' => (int) ($before->content_version ?: 1), 'previous_receipt_id' => $prior->id]], JSON_THROW_ON_ERROR),
            'actor_id' => $actor->id, 'reason' => 'Explicitly reviewed published replacement.', 'plan_revision' => $this->plan($shoot, $actor)['revision'],
            'operation_key' => $operationKey, 'delivered_at' => now()]);
        $this->forgetReleasedFiles($shoot);
        return (int) $receiptId;
    }

    public function contentKind(ShootFile $file): ?string
    {
        $mime = strtolower((string) ($file->mime_type ?: $file->file_type));
        if (str_starts_with($mime, 'video/')) return 'video';
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif', 'image/tiff', 'application/pdf'], true)) return null;
        if ($file->media_type === 'floorplan') return 'floorplan';
        return str_starts_with($mime, 'image/') ? 'photo' : null;
    }

    private function safe(ShootFile $file): bool { return ! $file->is_hidden && ! $file->flag_reason && ! $file->isBlockedFromDelivery() && ! $file->is_extra
        && ! app(ShootAuthorizationSupport::class)->isRawCameraFile($file)
        && ! in_array($file->media_type, ['raw', 'extra', 'reference', 'additional'], true) && ! data_get($file->metadata, 'reference_only') && ! $file->isIguideOfflinePackage(); }
    public function usableOutput(ShootFile $file): bool { return $this->safe($file) && $this->contentKind($file) !== null; }

    private function validReceipt($receipt, $files, ?array $external = null, $current = null): bool
    {
        $evidence = json_decode($receipt->evidence, true) ?: [];
        if ($receipt->method === 'manual_no_content') return ! empty($receipt->reason) && ! empty($evidence['confirmed']);
        if ($receipt->method === 'external') {
            $stored = $evidence['external'] ?? null;
            if (! $external || ! is_array($stored)) return false;
            if (isset($evidence['provider_verified'])) {
                $proof = $evidence['provider_verified'];
                return ($proof['provider'] ?? null) === ($external['provider'] ?? null)
                    && ($proof['identity_key'] ?? null) === ($external['property_id'] ?? null)
                    && ($proof['canonical_url'] ?? null) === $this->canonicalProviderUrl($external['url']);
            }
            // Availability metadata is not a new provider output revision.
            unset($external['readiness'], $stored['readiness']);
            return hash_equals(hash('sha256', json_encode($external)), hash('sha256', json_encode($stored)));
        }
        if (empty($evidence['files'])) return false;
        foreach ($evidence['files'] as $entry) {
            $file = $files->get($entry['id']);
            if (! $file || (int) $file->shoot_id !== (int) $receipt->shoot_id || (int) $file->shoot_service_id !== (int) $receipt->shoot_service_id
                || (int) ($file->content_version ?: 1) !== (int) $entry['version'] || ! $this->safe($file)
                || ! in_array($file->workflow_stage, ['completed', 'verified'], true) || $this->contentKind($file) !== $receipt->component_key
                || ($current && ! $current->has($file->id))) return false;
        }
        return true;
    }

    public function releasedFileIds(Shoot $shoot): array
    {
        if (! $shoot->id || ! $this->ledgerExists()) return [];
        if (array_key_exists($shoot->id, $this->releasedCache)) return $this->releasedCache[$shoot->id];
        $receipts = DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->where('method', 'media')->whereNull('revoked_at')->get();
        if ($receipts->isEmpty()) return $this->releasedCache[$shoot->id] = [];
        $files = $shoot->files()->get()->keyBy('id'); $ids = [];
        $items = $shoot->serviceItems()->get()->keyBy('id');
        foreach ($receipts as $receipt) {
            $item = $items->get($receipt->shoot_service_id);
            if (! $item || $item->cancelled_at || $item->workflow_status === 'cancelled' || $item->delivery_status === 'cancelled') continue;
            foreach (json_decode($receipt->evidence, true)['files'] ?? [] as $entry) {
                $file = $files->get($entry['id']);
                if ($file && $file->workflow_stage === 'verified' && (int) $file->shoot_service_id === (int) $receipt->shoot_service_id
                    && (int) ($file->content_version ?: 1) === (int) $entry['version'] && $this->safe($file)
                    && $this->contentKind($file) === $receipt->component_key) {
                    $ids[] = (int) $entry['id'];
                    $this->releasedEvidence[$shoot->id][$file->id] = ['version' => (int) $entry['version'], 'owner' => (int) $receipt->shoot_service_id, 'kind' => $receipt->component_key];
                }
            }
        }
        return $this->releasedCache[$shoot->id] = array_values(array_unique($ids));
    }
    public function releasedFile(ShootFile $file): bool
    {
        if (! $file->id || ! $file->shoot_id) return false;
        $shoot = $file->relationLoaded('shoot') ? $file->shoot : $file->shoot()->first();
        if (! $shoot || ! in_array($file->id, $this->releasedFileIds($shoot), true)) return false;
        $proof = $this->releasedEvidence[$shoot->id][$file->id] ?? null;
        return $proof && $file->workflow_stage === 'verified' && $this->safe($file)
            && (int) ($file->content_version ?: 1) === $proof['version'] && (int) $file->shoot_service_id === $proof['owner'] && $this->contentKind($file) === $proof['kind'];
    }
    public function releasedServiceIds(Shoot $shoot): array
    {
        if (! $shoot->id || ! $this->ledgerExists()) return [];
        $receipts = DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->whereNull('revoked_at')->get();
        if ($receipts->isEmpty()) return [];
        $shoot = $shoot->fresh() ?? $shoot;
        $items = $shoot->serviceItems()->with(['service.category', 'unit'])->get();
        $files = $shoot->files()->get()->keyBy('id');
        $released = array_values(array_filter($this->releasedFileIds($shoot), fn ($id) => $files->has($id) && $this->releasedFile($files->get($id)->setRelation('shoot', $shoot)))); $ids = [];
        foreach ($receipts as $receipt) {
            $item = $items->firstWhere('id', $receipt->shoot_service_id);
            if (! $item || $item->cancelled_at || $item->workflow_status === 'cancelled' || $item->delivery_status === 'cancelled') continue;
            $evidence = json_decode($receipt->evidence, true) ?: [];
            if ($receipt->method === 'media' ? array_intersect(array_column($evidence['files'] ?? [], 'id'), $released)
                : $this->validReceipt($receipt, $files, $this->externalEvidence($shoot, $item, $receipt->component_key, $items))) $ids[] = (int) $item->id;
        }
        return array_values(array_unique($ids));
    }
    public function hasReleasedComponents(Shoot $shoot): bool { return $this->releasedServiceIds($shoot) !== []; }

    private function externalEvidence(Shoot $shoot, ShootService $item, string $kind, $items): ?array
    {
        if ($kind === 'video') {
            if (app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot)
                && ! app(ActiveVideoLinkOutput::class)->hasReviewedOutput($shoot, $item)) return null;
            if (! app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot) && $items->filter(fn ($peer) => (int) $peer->shoot_unit_id === (int) $item->shoot_unit_id && in_array('video', app(DeliveryComponentRequirements::class)->kinds($peer), true))->count() !== 1) return null;
            $links = $item->shoot_unit_id ? $item->unit?->tour_links : $shoot->tour_links;
            foreach (['video_link','video_branded','video_mls','video_generic'] as $key) if ($this->url($links[$key] ?? null)) return ['url' => $links[$key], 'service_id' => $item->id, 'unit_id' => $item->shoot_unit_id, 'readiness' => 'review_required'];
            return null;
        }
        if (! in_array($kind, ['floorplan', 'tour', 'external'], true)) return null;
        $requiredProvider = collect(app(DeliveryComponentRequirements::class)->forItem($item))->firstWhere('kind', $kind)['provider'] ?? null;
        $provider = $item->shoot_unit_id ? ($item->unit?->provider_data ?? []) : $shoot->getAttributes();
        if ($item->shoot_unit_id) $provider = array_replace($provider, data_get($provider, 'lines.'.$item->id, []));
        if (! $item->shoot_unit_id) {
            $pin = $shoot->iguide_data ?? [];
            if (is_array($pin) && ! empty($pin['service_line_id'])
                && ($pin['ownership_property_id'] ?? null) === $shoot->iguide_property_id
                && ($pin['ownership_tour_url'] ?? null) === $shoot->iguide_tour_url) $provider['iguide_service_line_id'] = (int) $pin['service_line_id'];
        }
        foreach (['iguide', 'cubicasa'] as $name) {
            if ($requiredProvider && $requiredProvider !== $name) continue;
            if (isset($provider[$name.'_service_line_id']) && (int) $provider[$name.'_service_line_id'] !== (int) $item->id) continue;
            $peers = $items->filter(fn ($peer) => (int) $peer->shoot_unit_id === (int) $item->shoot_unit_id && array_intersect(app(DeliveryComponentRequirements::class)->kinds($peer), ['floorplan','tour']));
            if (! isset($provider[$name.'_service_line_id']) && $peers->count() !== 1) continue;
            $url = $provider[$name.'_tour_url'] ?? null;
            if (! $this->url($url)) continue;
            // Floorplans hosted in an iGuide require the actor's explicit external
            // review confirmation; a generic tour is not automatic media evidence.
            if ($kind === 'floorplan' && $name !== 'iguide') continue;
            $readiness = $item->shoot_unit_id ? data_get($provider, $name.'_data.delivery_readiness', []) : data_get($shoot->getAttribute($name.'_data'), 'delivery_readiness', []);
            $state = is_array($readiness) && ($readiness['property_id'] ?? null) === ($provider[$name.'_property_id'] ?? null)
                && ($readiness['tour_url'] ?? null) === $url ? ($readiness['state'] ?? 'review_required') : 'review_required';
            return ['provider' => $name, 'url' => $url, 'property_id' => $provider[$name.'_property_id'] ?? null,
                'order_id' => $provider[$name.'_work_order_id'] ?? $provider[$name.'_order_id'] ?? null, 'service_id' => $item->id, 'kind' => $kind, 'readiness' => $state];
        }
        if ($kind === 'tour') {
            $peers = $items->filter(fn ($peer) => (int) $peer->shoot_unit_id === (int) $item->shoot_unit_id && in_array('tour', app(DeliveryComponentRequirements::class)->kinds($peer), true));
            if ($peers->count() === 1) {
                $links = $item->shoot_unit_id ? ($item->unit?->tour_links ?? []) : ($shoot->tour_links ?? []);
                foreach (['zillow_3d', 'matterport_branded', 'matterport_mls', 'iguide_branded', 'iguide_mls'] as $key) {
                    if ($requiredProvider && ! str_starts_with($key, $requiredProvider)) continue;
                    if ($this->url($links[$key] ?? null)) return ['provider' => $key, 'url' => $links[$key], 'service_id' => $item->id, 'unit_id' => $item->shoot_unit_id, 'kind' => $kind, 'readiness' => 'review_required'];
                }
            }
        }
        return null;
    }
    private function url($value): bool { return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['https','http'], true); }
    private function reason(?string $code, string $label): ?string { return match ($code) { 'additional_output' => 'Additional files await review and finalization.', 'work_suspended' => 'Resume this work before delivery.', 'future_appointment' => 'The service appointment has not happened yet.', 'unsafe_scan' => 'Output is awaiting a clean safety scan.', 'unassigned_media' => 'Assign the available output to its booked service.', 'provider_not_ready' => 'The provider reports that this output is not ready.', 'external_review_required' => 'Open the destination and verify the selected deliverables are actually available.', 'video_pending' => 'Video pending: upload or review the finished video.', 'provider_output_pending' => $label.' pending: no reviewed provider output.', 'output_pending' => $label.' pending: no eligible finished output.', 'outdated_approval' => 'Output changed after its approval. Review it again.', default => null }; }
    private function reasonForPlan(array $components): string { return collect($components)->first(fn ($c) => ! $c['resolved'])['reason'] ?? 'No ready components.'; }
}
