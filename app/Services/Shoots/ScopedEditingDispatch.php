<?php

namespace App\Services\Shoots;

use App\Jobs\PrepareEditingDispatch;
use App\Models\Shoot;
use App\Models\ShootEditingDispatch;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Support\LockedWrite;
use App\Support\EditingDispatchWriteLock;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ScopedEditingDispatch
{
    public function create(Shoot $shoot, User $user, array $data): array
    {
        $hash = hash('sha256', json_encode(StudioWorkspace::canonicalConfig(Arr::except($data, ['request_id']))));
        return LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $user, $data, $hash) {
            EditingDispatchWriteLock::acquire((int) $shoot->id);
            $existing = ShootEditingDispatch::where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless((int) $existing->shoot_id === (int) $shoot->id && (int) $existing->created_by === (int) $user->id && $existing->input_hash === $hash, 409, 'This request ID was already used with different editing choices.');
                return $this->response($existing);
            }
            $locked = Shoot::findOrFail($shoot->id);
            $plan = app(ScopedEditingPlan::class)->preview($locked, $user, $data, true);
            $dispatch = ShootEditingDispatch::create(['shoot_id' => $shoot->id, 'created_by' => $user->id, 'request_id' => $data['request_id'],
                'scope' => $plan['scope'], 'destination' => $plan['destination'], 'workflow' => $plan['workflow'],
                'instructions' => $plan['instructions'], 'input_hash' => $hash, 'plan' => $plan, 'status' => 'queued']);
            foreach ($plan['items'] as $item) {
                $dispatch->items()->create(['input_key' => $item['key'], 'workflow' => $item['workflow'], 'sources' => $item['sources'], 'shoot_service_id' => $item['shoot_service_id'],
                    'lane' => $item['lane'], 'destination' => $item['destination'], 'editor_id' => $item['editor_id'],
                    'status' => $item['destination'] === 'human' ? 'assigned' : 'queued']);
            }
            // Explicit intake changes only the shoot stage. Request overrides never change permanent assignments.
            if (in_array($plan['scope'], ['whole', 'photos', 'videos'], true) && $locked->workflow_status === Shoot::STATUS_UPLOADED) {
                $locked->updateWorkflowStatus(Shoot::STATUS_EDITING, $user->id);
            }
            if ($dispatch->items()->where('destination', 'ai')->exists()) PrepareEditingDispatch::dispatch($dispatch->id)->beforeCommit();
            else $dispatch->update(['status' => 'assigned']);
            return $this->response($dispatch);
        }), 'editing-dispatch.create');
    }

    public function response(ShootEditingDispatch $dispatch): array
    {
        return ['dispatchId' => $dispatch->id, 'dispatch' => $dispatch->fresh('items')->present(),
            'mode' => $dispatch->destination === 'human' ? 'editor' : 'ai',
            'workspaces' => StudioWorkspace::where('editing_dispatch_id', $dispatch->id)->get()->map->present()->all()];
    }

    public function reconcile(string $dispatchId): void
    {
        LockedWrite::run(fn () => DB::transaction(function () use ($dispatchId) {
            $dispatch = ShootEditingDispatch::with('items')->find($dispatchId);
            if (!$dispatch) return;
            $complete = $dispatch->items->every(fn ($item) => $item->status === 'completed');
            $dispatch->update(['status' => $complete ? 'completed' : ($dispatch->items->contains(fn ($item) => in_array($item->status, ['conflict', 'failed'], true)) ? 'needs_attention' : 'in_progress')]);
            if ($dispatch->scope === 'selected') return;
            $shoot = Shoot::find($dispatch->shoot_id);
            if (!$shoot) return;
            $assignments = app(ShootEditingAssignmentService::class);
            foreach ($dispatch->items->groupBy(fn ($item) => $item->shoot_service_id.':'.$item->lane) as $group) {
                if (!$group->every(fn ($item) => $item->status === 'completed')) continue;
                $first = $group->first();
                $otherPending = \App\Models\ShootEditingDispatchItem::where('shoot_service_id', $first->shoot_service_id)->where('lane', $first->lane)
                    ->where('status', '!=', 'completed')->whereHas('dispatch', fn ($query) => $query->where('shoot_id', $shoot->id)->where('scope', '!=', 'selected'))->exists();
                if ($otherPending) continue;
                $assignment = $assignments->getTrackedServiceAssignments($shoot)->first(fn ($assignment) => $assignment['shoot_service_id'] === (int) $first->shoot_service_id && $assignment['lane'] === $first->lane);
                if ($assignment) DB::table('shoot_service')->where('shoot_id', $shoot->id)->where('id', $first->shoot_service_id)
                    ->whereNull($assignment['completed_column'])->update([$assignment['completed_column'] => now(), 'updated_at' => now()]);
            }
            // Delivered revisions never reopen the shoot or resend delivery notifications.
            if ($complete && $shoot->workflow_status === Shoot::STATUS_EDITING && $assignments->allTrackedLanesReady($shoot->fresh(['services.category']))) {
                $shoot->updateWorkflowStatus(Shoot::STATUS_READY, $dispatch->created_by);
            }
        }), 'editing-dispatch.reconcile');
    }

    public function assertWorkspaceSources(StudioWorkspace $workspace): void
    {
        if (!$workspace->editing_dispatch_id) return;
        foreach (\App\Models\ShootEditingDispatchItem::where('workspace_id', $workspace->id)->get() as $item) {
            // Completed provider outputs can resume publication even if a later edit became current.
            if ($item->primary_version_id) continue;
            foreach ($item->sources as $source) {
                $file = \App\Models\ShootFile::find($source['id']);
                abort_unless($file && (int) $file->shoot_id === (int) $workspace->shoot_id
                    && (int) $file->content_version === (int) $source['version'], 409, 'A source changed before editing. Review the latest media and create a new request.');
            }
        }
    }
}
