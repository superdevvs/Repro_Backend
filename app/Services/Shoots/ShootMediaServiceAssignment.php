<?php

namespace App\Services\Shoots;

use App\Models\{AiEditingJob, Shoot, ShootEditingDispatchItem, ShootFile, ShootFileVersion, ShootService, StudioWorkspace, User};
use App\Services\RolePermissionService;
use App\Support\{EditingDispatchWriteLock, LockedWrite};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** An ownership correction changes neither file content nor delivery approval. */
final class ShootMediaServiceAssignment
{
    public function canAssign(Shoot $shoot, ?User $actor): bool
    {
        $actor = $actor?->fresh();
        if (! $actor || $shoot->isImportDraft()) return false;
        $permissions = app(RolePermissionService::class);
        return $permissions->normalizedUserRoles($actor)->intersect(['admin', 'superadmin', 'editing_manager'])->isNotEmpty()
            && $permissions->userCan($actor, 'shoots', 'manage')
            && $permissions->userCan($actor, 'media', 'upload');
    }

    public function execute(Shoot $shoot, ?User $actor, array $data): array
    {
        $dry = (bool) $data['dry_run'];
        if (! $this->canAssign($shoot, $actor)) {
            abort_unless($dry && empty($data['file_ids']) && empty($data['shoot_service_id']), 403, 'Service assignment is not permitted.');
            return ['capabilities' => ['can_assign_service' => false], 'targets' => [], 'files' => [], 'blockers' => [], 'can_apply' => false, 'updated_count' => 0];
        }
        if ($dry) return $this->preview($shoot->fresh(), $data);
        foreach (['file_ids', 'shoot_service_id', 'expected_files', 'idempotency_key'] as $key) {
            if (empty($data[$key])) throw ValidationException::withMessages([$key => 'This field is required to apply the assignment.']);
        }
        if (! array_key_exists('expected_target_unit_id', $data)) throw ValidationException::withMessages(['expected_target_unit_id' => 'Review the target unit before applying.']);
        $fingerprint = $this->fingerprint($data);
        $result = LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $actor, $data, $fingerprint) {
            EditingDispatchWriteLock::acquire((int) $shoot->id);
            $shoot = Shoot::lockForUpdate()->findOrFail($shoot->id);
            $actor = $actor->fresh();
            abort_unless($this->canAssign($shoot, $actor), 403, 'Your service-assignment permission changed.');
            $receipt = $shoot->workflowLogs()->where('action', 'media_service_assigned')->where('user_id', $actor->id)
                ->where('metadata->idempotency_key', $data['idempotency_key'])->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->metadata['fingerprint'], $fingerprint), 409, 'This assignment key was already used for different choices.');
                $current = $shoot->files()->whereIn('id', $data['file_ids'])->get();
                abort_unless($current->count() === count($data['file_ids']) && $current->every(fn ($file) => (int) $file->shoot_service_id === (int) $data['shoot_service_id']),
                    409, 'These files changed after this assignment. Reload before correcting them again.');
                return ['shoot' => $shoot, 'updated_count' => 0, 'already_applied' => true];
            }
            $targetUnit = $shoot->serviceItems()->whereKey($data['shoot_service_id'])->value('shoot_unit_id');
            abort_unless((string) $targetUnit === (string) $data['expected_target_unit_id'], 409, 'The target unit changed. Review the assignment again.');
            $preview = $this->preview($shoot, $data);
            if (! $preview['can_apply']) throw ValidationException::withMessages(['assignment' => array_column($preview['blockers'], 'message')]);
            $files = $shoot->files()->whereIn('id', $data['file_ids'])->get();
            $expected = collect($data['expected_files'])->keyBy('id');
            abort_unless($expected->count() === $files->count() && $files->every(fn ($file) => $expected->has($file->id)
                && ($expected[$file->id]['shoot_service_id'] === null ? $file->shoot_service_id === null : (int) $expected[$file->id]['shoot_service_id'] === (int) $file->shoot_service_id)
                && (int) $expected[$file->id]['content_version'] === (int) ($file->content_version ?: 1)),
                409, 'The selected files changed. Reload and review the assignment again.');
            if ($data['confirm_historical'] ?? false) {
                abort_unless(isset($data['expected_receipt_revision']) && hash_equals($this->receiptRevision($shoot), $data['expected_receipt_revision']),
                    409, 'Delivery approvals changed. Review the historical assignment again.');
            }
            $before = $files->map(fn ($file) => $this->identity($file))->all();
            $changed = $files->filter(fn ($file) => (int) $file->shoot_service_id !== (int) $data['shoot_service_id']);
            // No model save: ownership corrections must not enqueue delivery notifications,
            // reset scan/review state, replace bytes or make old content appear freshly created.
            if ($changed->isNotEmpty()) DB::table('shoot_files')->whereIn('id', $changed->modelKeys())
                ->update(['shoot_service_id' => $data['shoot_service_id']]);
            $shoot->workflowLogs()->create(['user_id' => $actor->id, 'action' => 'media_service_assigned',
                'details' => 'Booked service ownership corrected; delivery review remains required.',
                'metadata' => ['idempotency_key' => $data['idempotency_key'], 'fingerprint' => $fingerprint,
                    'before' => $before, 'target_service_id' => (int) $data['shoot_service_id'], 'reason' => $data['reason'] ?? null,
                    'after' => $shoot->files()->whereIn('id', $data['file_ids'])->get()->map(fn ($file) => $this->identity($file))->all()]]);
            app(DeliveryPlanService::class)->forgetReleasedFiles($shoot);
            // The component plan validates exact current owner/content versions. Existing
            // immutable receipts remain in history and cannot approve the new scope.
            app(ShootActiveWorkLifecycle::class)->reconcile($shoot);
            return ['shoot' => $shoot->fresh(), 'updated_count' => $changed->count(), 'already_applied' => false];
        }), 'shoot.media.assign-service');
        // Also retry invalidation on an idempotent replay after a cache outage.
        $support = app(ShootMediaMutationSupportService::class);
        $support->clearShootFilesCache($result['shoot'], $actor);
        ShootListingService::flushCachedListings();
        $response = $this->preview($result['shoot']->fresh(), $data);
        $response['updated_count'] = $result['updated_count'];
        $response['already_applied'] = $result['already_applied'];
        $response['media_revision'] = $support->currentMediaRevision($result['shoot']);
        return $response;
    }

    private function preview(Shoot $shoot, array $data): array
    {
        $policy = app(ShootUploadTargetPolicy::class);
        $items = $shoot->serviceItems()->with(['service.category', 'unit', 'photographer', 'editor'])->get();
        $ids = $data['file_ids'] ?? [];
        $files = $shoot->files()->with(['serviceItem', 'album'])->whereIn('id', $ids)->get();
        $receiptedFileIds = [];
        if ($files->isNotEmpty()) {
            foreach (DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->pluck('evidence') as $evidence) {
                $decoded = json_decode($evidence, true);
                // Even a superseded/revoked approval is historical evidence.
                // Malformed history cannot prove these files were unapproved.
                if (! is_array($decoded)) { $receiptedFileIds += array_fill_keys($files->modelKeys(), true); continue; }
                foreach ($decoded['files'] ?? [] as $entry) if (isset($entry['id'])) $receiptedFileIds[(int) $entry['id']] = true;
            }
        }
        $target = $items->firstWhere('id', $data['shoot_service_id'] ?? null);
        $blockers = [];
        $add = function (string $code, string $message, ?int $id = null) use (&$blockers) { $blockers[] = ['code' => $code, 'message' => $message, 'file_id' => $id]; };
        if ($ids && $files->count() !== count($ids)) $add('foreign_files', 'Every selected file must belong to this shoot.');
        if (isset($data['shoot_service_id']) && ! $target) $add('foreign_target', 'The selected booked service must belong to this shoot.');
        $historical = (bool) ($data['confirm_historical'] ?? false);
        if ($target && ! $policy->isOutstanding($target) && ! ($historical && $this->historicalTarget($shoot, $target))) $add('closed_target', 'Choose an unfinished deliverable service; cancelled and financial-only rows cannot receive work.');
        if ($target) foreach ($files as $file) {
            if ((int) $file->shoot_service_id === (int) $target->id) continue;
            if ($file->isIguideOfflinePackage() || $file->isInvalidProviderFloorplan()) $add('integration_attachment', 'Use the dedicated provider recovery workflow for this attachment.', $file->id);
            if ($this->requiresHistoricalReview($file, $receiptedFileIds)) {
                if (! $historical) $add('historical_confirmation_required', 'Review and explicitly confirm this historical ownership correction.', $file->id);
                elseif (! $this->safeHistoricalMove($shoot, $file, $target)) $add('needs_guarded_repair', 'This move affects current work, approval receipts, unit scope, or client entitlement. A guarded reviewed repair is required.', $file->id);
            }
            if ($file->shoot_service_id && ! trim((string) ($data['reason'] ?? ''))) $add('reason_required', 'Enter a reason before changing existing service ownership.', $file->id);
            if ($file->serviceItem && (string) $file->serviceItem->shoot_unit_id !== (string) $target->shoot_unit_id) $add('cross_unit', 'Cross-unit moves require a reviewed correction; choose a service in the current unit.', $file->id);
            if ($file->album?->shoot_service_id && (int) $file->album->shoot_service_id !== (int) $target->id) $add('album_scope', 'The album has a different service owner. Review the album before moving its media.', $file->id);
            $family = $policy->family($file->file_type ?: $file->mime_type, $file->media_type);
            $type = $file->workflow_stage === ShootFile::STAGE_TODO ? 'raw' : 'edited';
            if (! $policy->accepts($target, $family, $type)) $add('incompatible_content', 'The selected service does not accept this content family.', $file->id);
            if ($file->workflow_stage === ShootFile::STAGE_TODO && $file->bracket_group) {
                $group = $shoot->files()->where('shoot_service_id', $file->shoot_service_id)->where('workflow_stage', ShootFile::STAGE_TODO)->where('bracket_group', $file->bracket_group)->pluck('id')->all();
                if (array_diff($group, $ids)) $add('incomplete_hdr_group', 'Select every member of the HDR bracket group.', $file->id);
                $oldMode = $file->serviceItem ? app(BracketModeResolver::class)->effectiveBracketMode($file->serviceItem) : $shoot->bracket_mode;
                if ((int) $oldMode !== (int) app(BracketModeResolver::class)->effectiveBracketMode($target)) $add('hdr_mode_change', 'This move would change HDR grouping. Use an explicit restack workflow.', $file->id);
            }
        }
        if ($ids && $this->hasInflightWork($shoot, $ids)) $add('inflight_work', 'Finish or withdraw pending editing/version work before changing ownership.');
        return ['capabilities' => ['can_assign_service' => true],
            'targets' => $items->map(fn ($item) => ['id' => $item->id, 'service_id' => $item->service_id,
                'name' => $item->service?->name, 'unit_id' => $item->shoot_unit_id, 'unit_label' => $item->unit?->label,
                'photographer' => $item->photographer?->only(['id', 'name']), 'editor' => $item->editor?->only(['id', 'name']),
                'timezone' => $shoot->timezone ?: 'UTC', 'scheduled_at' => $item->scheduled_at?->toIso8601String(), 'workflow_status' => $item->workflow_status,
                'delivery_status' => $item->delivery_status, 'accepted_types' => $policy->kinds($item),
                'historical' => $this->historicalTarget($shoot, $item),
                'assignable' => $policy->isOutstanding($item) || ($historical && $this->historicalTarget($shoot, $item)),
                'blockers' => $policy->isOutstanding($item) || ($historical && $this->historicalTarget($shoot, $item)) ? [] : ['Service is completed, cancelled, or financial-only.']])->all(),
            'files' => $files->map(fn ($file) => app(ShootMediaMutationSupportService::class)->transformFile($file))->all(),
            'requires_historical_confirmation' => $files->contains(fn ($file) => $this->requiresHistoricalReview($file, $receiptedFileIds)),
            'receipt_revision' => $this->receiptRevision($shoot),
            'blockers' => $blockers, 'can_apply' => $target && $ids !== [] && $blockers === [], 'updated_count' => 0,
            'unassigned_count' => $shoot->files()->whereNull('shoot_service_id')->count(),
            'media_revision' => app(ShootMediaMutationSupportService::class)->currentMediaRevision($shoot)];
    }

    private function requiresHistoricalReview(ShootFile $file, array $receiptedFileIds): bool
    {
        if ($file->workflow_stage === ShootFile::STAGE_VERIFIED || isset($receiptedFileIds[$file->id])) return true;
        $source = $file->serviceItem;
        if (! $source || $source->workflow_status !== 'delivered' || $source->delivery_status !== 'delivered') return false;
        // A line's aggregate Delivered state does not approve every later upload.
        // Only managed, never-approved unfinished media has this narrow escape.
        $kinds = app(DeliveryComponentRequirements::class)->kinds($source);
        return ! in_array($file->workflow_stage, [ShootFile::STAGE_TODO, ShootFile::STAGE_COMPLETED], true)
            || $kinds === [] || in_array('legacy', $kinds, true);
    }

    private function historicalTarget(Shoot $shoot, ShootService $item): bool
    {
        return ! app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot)
            && $item->workflow_status === 'delivered' && $item->delivery_status === 'delivered'
            && app(ShootUploadTargetPolicy::class)->isWorkItem($item)
            && ! data_get($item->payout_snapshot, 'additional_work');
    }

    private function safeHistoricalMove(Shoot $shoot, ShootFile $file, ShootService $target): bool
    {
        $source = $file->serviceItem;
        if (! $source || ! $this->historicalTarget($shoot, $source) || ! $this->historicalTarget($shoot, $target)
            || $file->workflow_stage !== ShootFile::STAGE_VERIFIED || $file->isBlockedFromDelivery()
            || (string) $source->shoot_unit_id !== (string) $target->shoot_unit_id
            || ! $file->created_at || ! $target->created_at || $target->created_at->gt($file->created_at)) return false;
        // Receipt-based releases cannot be transferred by a metadata mutation.
        // They require a separately reviewed target-component correction receipt.
        if (DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->whereNull('revoked_at')
            ->whereIn('shoot_service_id', [$source->id, $target->id])->exists()) return false;
        if (app(DeliveryComponentRequirements::class)->kinds($source) !== ['legacy']
            || app(DeliveryComponentRequirements::class)->kinds($target) !== ['legacy']) return false;
        $client = $shoot->client;
        if (! $client) return false;
        $access = app(ShootClientReleaseAccessService::class);
        $candidate = clone $file;
        $candidate->shoot_service_id = $target->id;
        $candidate->setRelation('serviceItem', $target);
        return $access->isFileReleaseLocked($shoot, $file, $client) === $access->isFileReleaseLocked($shoot, $candidate, $client);
    }

    private function receiptRevision(Shoot $shoot): string
    {
        return hash('sha256', json_encode(DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)
            ->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
    }

    private function hasInflightWork(Shoot $shoot, array $ids): bool
    {
        if (AiEditingJob::where('shoot_id', $shoot->id)->whereIn('shoot_file_id', $ids)->whereIn('status', ['pending', 'processing'])->exists()) return true;
        foreach (ShootFileVersion::where('shoot_id', $shoot->id)->whereIn('status', ['queued', 'processing', 'ready'])->get() as $version) {
            if (in_array($version->source_file_id, $ids) || in_array($version->target_file_id, $ids)
                || array_intersect(array_map('intval', array_keys($version->metadata['source_versions'] ?? [])), $ids)) return true;
        }
        foreach (ShootEditingDispatchItem::whereHas('dispatch', fn ($q) => $q->where('shoot_id', $shoot->id))
            ->whereNotIn('status', ['completed', 'cancelled', 'failed', 'withdrawn'])->get() as $item) {
            if (array_intersect(array_map('intval', array_column($item->sources ?? [], 'id')), $ids)) return true;
        }
        $review = app(\App\Services\Studio\WorkspaceShootReview::class);
        return $review->isPending($shoot) || $review->isProcessing($shoot)
            || StudioWorkspace::where('shoot_id', $shoot->id)->whereIn('status', ['preparing', 'generating'])->exists();
    }

    private function identity(ShootFile $file): array
    {
        return ['id' => $file->id, 'shoot_service_id' => $file->shoot_service_id, 'content_version' => (int) ($file->content_version ?: 1)];
    }

    private function fingerprint(array $data): string
    {
        $expected = collect($data['expected_files'])->sortBy('id')->values()->all();
        $ids = array_map('intval', $data['file_ids']); sort($ids);
        return hash('sha256', json_encode([(int) $data['shoot_service_id'], $ids, $expected, trim((string) ($data['reason'] ?? '')), (bool) ($data['confirm_historical'] ?? false), $data['expected_receipt_revision'] ?? null, $data['expected_target_unit_id'] ?? null], JSON_THROW_ON_ERROR));
    }
}
