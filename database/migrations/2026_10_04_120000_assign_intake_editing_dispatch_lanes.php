<?php

use App\Models\Shoot;
use App\Models\ShootEditingDispatch;
use App\Models\User;
use App\Services\Shoots\ScopedEditingDispatch;
use App\Services\Shoots\ShootListingService;
use App\Support\LockedWrite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * From 2026-10-03 23:44 UTC, Send to editing for whole shoots / whole lanes created one
 * human task per file or HDR stack and left the service lines without an editor
 * (shoots 379, 641, 668 and 670 on production). The editor could not open, upload or
 * submit those shoots through the normal workflow. Convert untouched intake tasks into
 * the lane assignment that Send to editor has always made.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shoot_editing_dispatches') || ! Schema::hasTable('shoot_editing_dispatch_items')) {
            return;
        }

        $repaired = [];
        $skipped = [];
        $dispatches = ShootEditingDispatch::with('items')->where('scope', '!=', 'selected')
            ->whereHas('items', fn ($query) => $query->where('destination', 'human')->where('status', 'assigned'))->get();

        foreach ($dispatches as $dispatch) {
            $human = $dispatch->items->where('destination', 'human');
            $shoot = Shoot::find($dispatch->shoot_id);
            $creator = User::find($dispatch->created_by);
            $stage = $shoot ? ($shoot->workflow_status ?: $shoot->status) : null;
            if (! $shoot || ! $creator || ! in_array($stage, [Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING], true)
                || $human->contains(fn ($item) => $item->status !== 'assigned' || $item->primary_version_id || $item->return_url)) {
                $skipped[] = ['dispatch_id' => $dispatch->id, 'shoot_id' => $dispatch->shoot_id, 'stage' => $stage];
                continue;
            }

            LockedWrite::run(fn () => DB::transaction(function () use ($dispatch, $human, $shoot, $creator) {
                $plan = ['scope' => $dispatch->scope, 'destination' => $dispatch->destination,
                    'items' => $human->map(fn ($item) => ['destination' => 'human', 'lane' => $item->lane, 'editor_id' => $item->editor_id])->values()->all()];
                app(ScopedEditingDispatch::class)->assignIntakeLanes($shoot, $creator, [], $plan);
                $dispatch->items()->whereIn('id', $human->modelKeys())
                    ->update(['status' => 'cancelled', 'error' => 'Moved to the shoot editor assignment.', 'updated_at' => now()]);
                if ($dispatch->destination === 'human') {
                    $dispatch->update(['status' => 'assigned']);
                }
            }), 'migration.assign-intake-dispatch-lanes');
            $repaired[] = ['dispatch_id' => $dispatch->id, 'shoot_id' => $shoot->id, 'editor_ids' => $human->pluck('editor_id')->unique()->values()->all()];
        }

        if ($repaired) {
            ShootListingService::flushCachedListings();
        }
        // Production logs only error level; keep this audit record visible.
        Log::error('Intake editing dispatch lanes reassigned.', ['repaired' => $repaired, 'skipped' => $skipped]);
    }

    public function down(): void
    {
        // Irreversible data correction; left intentionally empty.
    }
};
