<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Media\MediaStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ShootArchivePrewarmService
{
    public function afterRawSubmission(Shoot $shoot): void
    {
        $this->schedule($shoot, true);
    }

    public function whenAssignmentsActionable(Shoot $shoot): void
    {
        if (strtolower((string) ($shoot->workflow_status ?: $shoot->status)) !== Shoot::STATUS_EDITING) {
            return;
        }
        $this->schedule($shoot, false);
    }

    private function schedule(Shoot $shoot, bool $includeFull): void
    {
        if (! app(MediaStorage::class)->performanceEnabled('archive_prewarm', (int) $shoot->id)) {
            return;
        }
        $canaries = array_map('intval', config('media.archive_prewarm_shoot_ids', []));
        if ($canaries !== [] && ! in_array((int) $shoot->id, $canaries, true)) {
            return;
        }
        $shootId = (int) $shoot->id;
        $dispatch = function () use ($shootId, $includeFull): void {
            try {
                $shoot = Shoot::find($shootId);
                if (! $shoot) {
                    return;
                }
                $archives = app(ShootMediaArchiveService::class);
                if ($includeFull) {
                    $archives->queueArchiveGeneration($shoot, 'raw', 'original');
                }
                if (strtolower((string) ($shoot->workflow_status ?: $shoot->status)) !== Shoot::STATUS_EDITING) {
                    return;
                }
                $editorIds = app(ShootEditingAssignmentService::class)->getTrackedServiceAssignments($shoot)
                    ->pluck('editor_id')->push($shoot->editor_id)->filter()->unique();
                $fullIds = $archives->getFilesForType($shoot, 'raw')->pluck('id')->sort()->values()->all();
                foreach (User::query()->whereIn('id', $editorIds)->get() as $editor) {
                    $scoped = app(EditorRawArchiveService::class);
                    $files = $scoped->authorizedFiles($shoot, $editor);
                    if ($files->isEmpty()) {
                        continue;
                    }
                    if ($files->pluck('id')->sort()->values()->all() === $fullIds) {
                        $archives->queueArchiveGeneration($shoot, 'raw', 'original');
                    } else {
                        $scoped->queue($shoot, $editor, $files);
                    }
                }
            } catch (\Throwable $exception) {
                // A queue outage must not turn a successful media submission into a retry.
                Log::error('Archive prewarm dispatch failed', ['shoot_id' => $shootId, 'error' => $exception->getMessage()]);
            }
        };
        DB::transactionLevel() > 0 ? DB::afterCommit($dispatch) : $dispatch();
    }
}
