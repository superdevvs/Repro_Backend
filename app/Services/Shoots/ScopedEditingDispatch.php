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
            // Before delivery, sending media to a human editor assigns that lane, exactly as Send to
            // editor always has: the editor opens the shoot, edits from its media, uploads in Edited
            // and submits normally. Per-file human tasks remain only for revisions after editing.
            $intakeStage = in_array($locked->workflow_status ?: $locked->status, [Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING], true);
            $human = collect($plan['items'])->where('destination', 'human');
            foreach ($plan['items'] as $item) {
                if ($intakeStage && $item['destination'] === 'human') continue;
                $dispatch->items()->create(['input_key' => $item['key'], 'workflow' => $item['workflow'], 'sources' => $item['sources'], 'shoot_service_id' => $item['shoot_service_id'],
                    'lane' => $item['lane'], 'destination' => $item['destination'], 'editor_id' => $item['editor_id'],
                    'status' => $item['destination'] === 'human' ? 'assigned' : 'queued']);
            }
            if ($intakeStage && ($plan['scope'] !== 'selected' || $human->isNotEmpty())) {
                $this->assignIntakeLanes($locked, $user, $data, $plan);
                $this->noteForEditors($locked, $user, $plan, $human);
            }
            if ($dispatch->items()->where('destination', 'ai')->exists()) PrepareEditingDispatch::dispatch($dispatch->id)->beforeCommit();
            else $dispatch->update(['status' => 'assigned']);
            return $this->response($dispatch);
        }), 'editing-dispatch.create');
    }

    public function assignIntakeLanes(Shoot $shoot, User $user, array $data, array $plan): void
    {
        $scopeLanes = $plan['scope'] === 'selected'
            ? collect($plan['items'])->where('destination', 'human')->pluck('lane')->unique()->values()->all()
            : (['photos' => [ShootEditingAssignmentService::LANE_PHOTO], 'videos' => [ShootEditingAssignmentService::LANE_VIDEO]][$plan['scope']] ?? null);
        // AI photo work leaves any existing photo assignment in place; whole-shoot AI still sends video to people.
        $humanLanes = $plan['destination'] === 'ai'
            ? ($plan['scope'] === 'whole' ? [ShootEditingAssignmentService::LANE_VIDEO] : [])
            : $scopeLanes;
        $tracked = app(ShootEditingAssignmentService::class)->getTrackedServiceAssignments($shoot);
        $planned = collect($plan['items'])->where('destination', 'human');
        $laneEditors = [];
        foreach ([ShootEditingAssignmentService::LANE_PHOTO, ShootEditingAssignmentService::LANE_VIDEO] as $lane) {
            if ($humanLanes !== null && ! in_array($lane, $humanLanes, true)) continue;
            $override = $data[$lane.'_editor_id'] ?? null;
            if ($override) {
                $editor = User::find((int) $override);
                abort_unless($editor && $editor->role === 'editor' && $editor->canEditLane($lane) && $editor->isAccountEligibleForAuthentication(), 422, 'Choose an eligible '.$lane.' editor before sending.');
                $laneEditors[$lane] = (int) $editor->id;
            } elseif ($tracked->where('lane', $lane)->pluck('editor_id')->filter()->isEmpty() && ($chosen = $planned->firstWhere('lane', $lane)['editor_id'] ?? null)) {
                // Keep the editor shown in the reviewed preview.
                $laneEditors[$lane] = (int) $chosen;
            }
        }
        app(\App\Services\ShootWorkflowService::class)->startEditing($shoot, $user, $humanLanes, $laneEditors, []);
    }

    /** Editors read request instructions and the requested files from the shoot's editing notes. */
    private function noteForEditors(Shoot $shoot, User $user, array $plan, \Illuminate\Support\Collection $human): void
    {
        $lines = [];
        if ($plan['scope'] === 'selected' && $human->isNotEmpty()) {
            $names = $human->flatMap(fn ($item) => collect($item['sources'])->pluck('name'))->unique()->values();
            $lines[] = 'Edit these '.$names->count().' file'.($names->count() === 1 ? '' : 's').': '.$names->take(60)->implode(', ').($names->count() > 60 ? ', …' : '');
        }
        if (trim((string) $plan['instructions']) !== '') $lines[] = trim((string) $plan['instructions']);
        if (! $lines) return;
        $shoot->notes()->create(['author_id' => $user->id, 'type' => \App\Models\ShootNote::TYPE_EDITING,
            'visibility' => \App\Models\ShootNote::VISIBILITY_INTERNAL, 'content' => implode("\n\n", $lines)]);
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
            $active = $dispatch->items->where('status', '!=', 'cancelled');
            $complete = $active->every(fn ($item) => $item->status === 'completed');
            $dispatch->update(['status' => $complete ? 'completed' : ($active->contains(fn ($item) => in_array($item->status, ['conflict', 'failed'], true)) ? 'needs_attention' : 'in_progress')]);
            if ($dispatch->scope === 'selected') return;
            $shoot = Shoot::find($dispatch->shoot_id);
            if (!$shoot) return;
            $assignments = app(ShootEditingAssignmentService::class);
            foreach ($active->groupBy(fn ($item) => $item->shoot_service_id.':'.$item->lane) as $group) {
                if (!$group->every(fn ($item) => $item->status === 'completed')) continue;
                $first = $group->first();
                $otherPending = \App\Models\ShootEditingDispatchItem::where('shoot_service_id', $first->shoot_service_id)->where('lane', $first->lane)
                    ->whereNotIn('status', ['completed', 'cancelled'])->whereHas('dispatch', fn ($query) => $query->where('shoot_id', $shoot->id)->where('scope', '!=', 'selected'))->exists();
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
