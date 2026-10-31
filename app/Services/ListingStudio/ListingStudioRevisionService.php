<?php

namespace App\Services\ListingStudio;

use App\Exceptions\PublicApiException;
use App\Models\ListingStudioOrderItem;
use App\Models\ListingStudioOutbox;
use App\Models\ListingStudioRevisionDelivery;
use App\Models\ListingStudioRevisionTask;
use App\Models\User;
use App\Support\LockedWrite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ListingStudioRevisionService
{
    public const MANAGERS = ['admin', 'superadmin', 'editing_manager'];

    public const REASONS = ['quality_review', 'source_issue', 'instructions_unclear', 'unable_to_complete', 'client_cancelled'];

    public function manager(User $user): bool
    {
        return in_array($user->role, self::MANAGERS, true);
    }

    public function authorize(User $user, ListingStudioRevisionTask $task): void
    {
        abort_unless($user->isAccountEligibleForAuthentication() && ! request()->attributes->get('is_impersonating')
            && ! request()->headers->has('X-Impersonate-User-Id'), 403);
        $team = $user->team_id ?? data_get($user->metadata, 'team_id');
        abort_unless(($this->manager($user) || ($user->role === 'editor' && (int) $task->assignee_id === (int) $user->id))
            && (! $task->team_id || (int) $team === (int) $task->team_id), 404);
    }

    public function visible(User $user)
    {
        abort_unless($user->isAccountEligibleForAuthentication() && in_array($user->role, [...self::MANAGERS, 'editor'], true) && ! request()->attributes->get('is_impersonating') && ! request()->headers->has('X-Impersonate-User-Id'), 403);
        $team = $user->team_id ?? data_get($user->metadata, 'team_id');
        $query = ListingStudioRevisionTask::query()->where(fn ($q) => $q->whereNull('team_id')->when($team, fn ($q) => $q->orWhere('team_id', $team)));
        if (! $this->manager($user)) {
            $query->where('assignee_id', $user->id);
        }

        return $query;
    }

    public function create(string $itemId): void
    {
        $item = ListingStudioOrderItem::findOrFail($itemId);
        if ($item->kind !== 'human_revision' || ! in_array($item->status, ['queued', 'processing', 'needs_attention'], true)) {
            return;
        }
        try {
            $snapshot = app(ListingStudioPaidMedia::class)->materialize($item);
            app(ListingStudioOrderService::class)->replaceSourceSnapshot($item->id, $item->source_snapshot['sha256'], $snapshot);
        } catch (\Throwable $exception) {
            app(ListingStudioOrderService::class)->holdItem($item->id, 'source_preparation_failed');
            throw $exception;
        }
        LockedWrite::run(fn () => DB::transaction(function () use ($item): void {
            $fresh = ListingStudioOrderItem::findOrFail($item->id);
            if (! in_array($fresh->status, ['queued', 'processing', 'needs_attention'], true)) {
                return;
            }
            ListingStudioRevisionTask::firstOrCreate(['order_item_id' => $item->id], ['id' => (string) Str::uuid(), 'status' => 'queued', 'version' => 1]);
        }), 'listing-studio.revision-create');
    }

    public function actions(User $user, ListingStudioRevisionTask $task): array
    {
        $item = $task->item;
        $working = ! in_array($task->status, ['delivered', 'cancelled'], true) && ! in_array($item->status, ['completed', 'failed', 'cancelled', 'awaiting_funding'], true);
        $attempt = $task->deliveryAttempt;
        $unresolved = $attempt && in_array($attempt->status, ['pending', 'finalizing', 'needs_attention', 'completed'], true);
        $funded = $item->order && in_array($item->order->funding_status, ['reserved', 'settled'], true) && $item->order->refunded_amount_cents === 0;
        $enabled = $funded && config('listing-studio.paid_enabled', false) && config('listing-studio.human_revisions_enabled', false);

        return ['canAssign' => $enabled && $working && ! $unresolved && $this->manager($user),
            'canStart' => $enabled && $working && ! $unresolved && ((in_array($task->status, ['queued', 'assigned'], true) && $item->status === 'queued') || (in_array($task->status, ['queued', 'assigned', 'in_progress'], true) && $item->status === 'needs_attention')),
            'canDeliver' => $enabled && $working && ! $unresolved && $task->status === 'in_progress' && $item->status === 'processing',
            'canHold' => $working && ! $unresolved, 'canFail' => $working && ! $unresolved && $this->manager($user),
            'canCancel' => $working && ! $unresolved && $this->manager($user),
            'canResumeDelivery' => $working && $attempt?->status === 'needs_attention',
            'canViewSource' => ! empty($item->source_snapshot['path']), 'canViewDelivery' => $item->status === 'completed' && ! empty($item->output_descriptor)];
    }

    public function present(User $user, ListingStudioRevisionTask $task): array
    {
        $this->authorize($user, $task);
        $task->load(['item.client', 'assignee', 'deliveryAttempt']);
        $item = $task->item;
        $attempt = $task->deliveryAttempt;

        return ['id' => $task->id, 'orderId' => $item->order_id, 'itemId' => $item->id, 'version' => $task->version,
            'status' => $task->status, 'itemStatus' => $item->status, 'service' => $item->service,
            'client' => ['id' => (string) $item->client_id, 'name' => $item->client?->name],
            'assignee' => $task->assignee ? ['id' => (string) $task->assignee->id, 'name' => $task->assignee->name] : null,
            'instructions' => $item->instructions, 'createdAt' => $task->created_at->toIso8601String(),
            'updatedAt' => $task->updated_at->toIso8601String(), 'assignedAt' => $task->assigned_at?->toIso8601String(),
            'startedAt' => $task->started_at?->toIso8601String(), 'deliveredAt' => $task->delivered_at?->toIso8601String(),
            'source' => ['originalPreviewAvailable' => ! empty($item->source_snapshot['original']), 'resultPreviewAvailable' => ! empty($item->source_snapshot['path'])],
            'deliveryPreviewAvailable' => $item->status === 'completed' && ! empty($item->output_descriptor['preview']['path']),
            'deliveryConstraints' => ['mimeTypes' => ListingStudioPaidMedia::MIME_TYPES, 'maxFileBytes' => ListingStudioPaidMedia::MAX_BYTES, 'maxPixels' => ListingStudioPaidMedia::MAX_PIXELS, 'maxFiles' => 1],
            'actions' => $this->actions($user, $task),
            'recovery' => ['state' => $item->status === 'needs_attention' ? 'needs_attention' : 'none', 'reasonCode' => $item->error_code,
                'message' => $item->status === 'needs_attention' ? 'This revision needs review before continuing.' : null],
            'deliveryAttempt' => $attempt ? ['id' => $attempt->id, 'status' => $attempt->status, 'reasonCode' => $attempt->error_code,
                'message' => $attempt->status === 'needs_attention' ? 'The saved delivery needs local recovery. No new purchase is needed.' : null,
                'canResume' => $attempt->status === 'needs_attention'] : null];
    }

    public function assignees(User $user, ListingStudioRevisionTask $task): array
    {
        $this->authorize($user, $task);
        abort_unless($this->manager($user), 403);

        return User::whereIn('role', [...self::MANAGERS, 'editor'])->get()->filter(function (User $candidate) use ($task) {
            $team = $candidate->team_id ?? data_get($candidate->metadata, 'team_id');

            return $candidate->isAccountEligibleForAuthentication() && (! $task->team_id || (int) $team === (int) $task->team_id);
        })->map(fn (User $candidate) => ['id' => (string) $candidate->id, 'name' => $candidate->name, 'role' => $candidate->role])->values()->all();
    }

    public function mutate(User $user, string $taskId, string $action, array $input, string $key): ListingStudioRevisionTask
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($user, $taskId, $action, $input, $key) {
            $task = ListingStudioRevisionTask::findOrFail($taskId);
            $this->authorize($user, $task);
            $hash = hash('sha256', json_encode([$action, $input]));
            $prior = DB::table('listing_studio_revision_actions')->where('task_id', $taskId)->where('request_key', $key)->first();
            if ($prior) {
                if (! hash_equals($prior->request_hash, $hash) || (int) $prior->actor_id !== (int) $user->id) {
                    $this->conflict();
                }

                return $task;
            }
            $this->version($task, $input['expectedVersion']);
            $actions = $this->actions($user, $task);
            if (! ($actions['can'.ucfirst($action)] ?? false)) {
                throw new PublicApiException('This revision cannot perform that action now.', 'revision_state_conflict', 409);
            }
            $orders = app(ListingStudioOrderService::class);
            if ($action === 'assign') {
                $assignee = $input['assigneeId'] ?? null;
                if ($assignee !== null && ! in_array((int) $assignee, array_map('intval', array_column($this->assignees($user, $task), 'id')), true)) {
                    throw new PublicApiException('Choose an available team member.', 'validation_failed', 422);
                }
                if ($task->status === 'in_progress' && $assignee === null) {
                    throw new PublicApiException('Assign an active revision to a team member.', 'revision_state_conflict', 409);
                }
                $task->assignee_id = $assignee;
                $task->assigned_at = $assignee ? now() : null;
                if ($task->status !== 'in_progress') {
                    $task->status = $assignee ? 'assigned' : 'queued';
                }
            } elseif ($action === 'start') {
                $task->assignee_id ??= $user->id;
                $task->assigned_at ??= now();
                $task->started_at ??= now();
                $task->status = 'in_progress';
                $started = $orders->beginItem($task->order_item_id, $task->item->processing_attempt_id ?? (string) Str::uuid());
                if (! $started) {
                    throw new PublicApiException('This revision still has an active processing lease.', 'revision_state_conflict', 409);
                }
                $orders->releaseProcessingLease($task->order_item_id, $started->processing_lease_token);
            } elseif ($action === 'hold') {
                $orders->holdItem($task->order_item_id, $input['reasonCode']);
            } elseif ($action === 'fail') {
                $orders->failItem($task->order_item_id, $input['reasonCode']);
                $task->status = 'cancelled';
            } elseif ($action === 'cancel') {
                $orders->cancelItem($task->order_item_id, $input['reasonCode']);
                $task->status = 'cancelled';
            }
            $task->version++;
            $task->save();
            DB::table('listing_studio_revision_actions')->insert(['task_id' => $taskId, 'actor_id' => $user->id, 'request_key' => $key,
                'request_hash' => $hash, 'action' => $action, 'reason_code' => $input['reasonCode'] ?? null, 'note' => $input['note'] ?? null, 'created_at' => now(), 'updated_at' => now()]);

            return $task->fresh();
        }), 'listing-studio.revision-action');
    }

    public function deliver(User $user, string $taskId, UploadedFile $photo, int $version, string $key): array
    {
        $task = ListingStudioRevisionTask::findOrFail($taskId);
        $this->authorize($user, $task);
        $media = app(ListingStudioPaidMedia::class);
        $metadata = $media->inspectUpload($photo);
        $hash = hash('sha256', json_encode([$metadata['sha256'], $version]));
        $lock = Cache::store('database')->lock('listing-studio-delivery-upload:'.$taskId.':'.$key, 120);
        if (! $lock->get()) {
            throw new PublicApiException('This delivery is being accepted. Retry the same request shortly.', 'delivery_in_progress', 409);
        }
        try {
            $prior = ListingStudioRevisionDelivery::where('task_id', $taskId)->where('request_key', $key)->first();
            if ($prior) {
                if (! hash_equals($prior->request_hash, $hash) || (int) $prior->actor_id !== (int) $user->id) {
                    $this->conflict();
                }

                return ['task' => $task->fresh(), 'created' => false];
            }
            $this->version($task, $version);
            if (! $this->actions($user, $task)['canDeliver']) {
                throw new PublicApiException('Start this revision before delivering it.', 'revision_state_conflict', 409);
            }
            $id = (string) Str::uuid();
            app(ListingStudioPaidStorage::class)->prepareDeliveryDirectory($id);
            $path = 'revision-deliveries/'.$id.'/result.'.$media->extension($metadata['mime']);
            Storage::disk(ListingStudioPaidStorage::DISK)->put($path, file_get_contents($photo->getRealPath()));
            $descriptor = ['disk' => ListingStudioPaidStorage::DISK, 'path' => $path, ...$metadata];

            return LockedWrite::run(fn () => DB::transaction(function () use ($user, $taskId, $version, $key, $hash, $id, $descriptor) {
                $task = ListingStudioRevisionTask::findOrFail($taskId);
                $this->authorize($user, $task);
                $this->version($task, $version);
                if (! $this->actions($user, $task)['canDeliver']) {
                    throw new PublicApiException('This delivery cannot be accepted now.', 'revision_state_conflict', 409);
                }
                ListingStudioRevisionDelivery::create(['id' => $id, 'task_id' => $taskId, 'actor_id' => $user->id, 'request_key' => $key,
                    'request_hash' => $hash, 'expected_version' => $version, 'descriptor' => $descriptor, 'status' => 'pending']);
                $task->update(['delivery_attempt_id' => $id, 'version' => $task->version + 1]);
                $this->enqueueDelivery($task, $id);

                return ['task' => $task->fresh(), 'created' => true];
            }), 'listing-studio.delivery-accept');
        } catch (\Throwable $exception) {
            if (isset($id)) {
                try {
                    if (! ListingStudioRevisionDelivery::whereKey($id)->exists()) {
                        Storage::disk(ListingStudioPaidStorage::DISK)->deleteDirectory('revision-deliveries/'.$id);
                    }
                } catch (\Throwable) { /* Preserve candidate if its durable acceptance cannot be checked. */
                }
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function resume(User $user, string $taskId, string $deliveryId, int $version): ListingStudioRevisionTask
    {
        $lock = Cache::store('database')->lock('listing-studio-delivery:'.$deliveryId, 60);
        if (! $lock->get()) {
            throw new PublicApiException('This delivery is still being finalized.', 'delivery_in_progress', 409);
        }
        try {
            return LockedWrite::run(fn () => DB::transaction(function () use ($user, $taskId, $deliveryId, $version) {
                $task = ListingStudioRevisionTask::findOrFail($taskId);
                $this->authorize($user, $task);
                $this->version($task, $version);
                if ($task->delivery_attempt_id !== $deliveryId || ! $this->actions($user, $task)['canResumeDelivery']) {
                    throw new PublicApiException('This delivery cannot be resumed.', 'delivery_not_retryable', 409);
                }
                $task->deliveryAttempt->update(['status' => 'pending', 'error_code' => null]);
                $task->update(['version' => $task->version + 1]);
                $this->enqueueDelivery($task, $deliveryId, true);

                return $task->fresh();
            }), 'listing-studio.delivery-resume');
        } finally {
            $lock->release();
        }
    }

    private function enqueueDelivery(ListingStudioRevisionTask $task, string $id, bool $resume = false): void
    {
        $event = ListingStudioOutbox::firstOrCreate(['idempotency_key' => 'revision-delivery:'.$id], ['id' => (string) Str::uuid(),
            'type' => 'finalize_revision_delivery', 'aggregate_id' => $task->order_item_id,
            'payload' => ['orderId' => $task->item->order_id, 'itemId' => $task->order_item_id, 'attemptId' => $id, 'deliveryId' => $id],
            'status' => 'pending', 'available_at' => now()]);
        if ($resume) {
            $event->update(['status' => 'pending', 'available_at' => now(), 'reserved_at' => null, 'lease_token' => null, 'error_code' => null]);
        }
    }

    public function finalize(string $id): void
    {
        $lock = Cache::store('database')->lock('listing-studio-delivery:'.$id, 180);
        if (! $lock->get()) {
            throw new \RuntimeException('Delivery finalization is already active.');
        }
        try {
            $delivery = ListingStudioRevisionDelivery::findOrFail($id);
            if ($delivery->status === 'completed') {
                return;
            }
            $task = $delivery->task()->with('item')->firstOrFail();
            if ($task->delivery_attempt_id !== $id || ! in_array($delivery->status, ['pending', 'finalizing'], true)) {
                return;
            }
            LockedWrite::run(fn () => $delivery->update(['status' => 'finalizing', 'started_at' => $delivery->started_at ?? now()]));
            $descriptor = app(ListingStudioPaidMedia::class)->completeOutput($task->item, $delivery->descriptor);
            LockedWrite::run(fn () => DB::transaction(function () use ($id, $task, $descriptor): void {
                $delivery = ListingStudioRevisionDelivery::findOrFail($id);
                $task = ListingStudioRevisionTask::findOrFail($task->id);
                if ($delivery->status === 'completed') {
                    return;
                }
                if ($task->delivery_attempt_id !== $id || ! in_array($delivery->status, ['pending', 'finalizing'], true)) {
                    throw new \RuntimeException('Delivery changed.');
                }
                app(ListingStudioOrderService::class)->completeItem($task->order_item_id, $descriptor);
                $delivery->update(['status' => 'completed', 'completed_at' => now(), 'error_code' => null]);
                $task->update(['status' => 'delivered', 'delivered_at' => now(), 'version' => $task->version + 1]);
            }), 'listing-studio.delivery-complete');
        } catch (\Throwable $exception) {
            $this->holdDelivery($id);
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function holdDelivery(string $id): void
    {
        LockedWrite::run(fn () => DB::transaction(function () use ($id): void {
            $delivery = ListingStudioRevisionDelivery::find($id);
            if (! $delivery || $delivery->status === 'completed') {
                return;
            }
            $delivery->update(['status' => 'needs_attention', 'error_code' => 'delivery_finalization_failed']);
            app(ListingStudioOrderService::class)->holdItem($delivery->task->order_item_id, 'delivery_finalization_failed');
        }), 'listing-studio.delivery-hold');
    }

    private function version(ListingStudioRevisionTask $task, int $version): void
    {
        if ($task->version !== $version) {
            throw new PublicApiException('This revision changed. Refresh it before continuing.', 'version_conflict', 409, ['currentVersion' => $task->version]);
        }
    }

    private function conflict(): never
    {
        throw new PublicApiException('This request key was already used for a different action.', 'idempotency_conflict', 409);
    }
}
