<?php

namespace App\Services\Shoots\Actions;

use App\Jobs\SyncShootIguideJob;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Support\Facades\DB;

class FinalizeRawUploadAction
{
    public function __construct(
        protected ShootMediaMutationSupportService $support
    ) {
    }

    public function execute(Shoot $shoot, ?User $user): array
    {
        $workflowStatusChanged = false;
        $shouldQueueIguideSync = false;

        DB::beginTransaction();

        try {
            $shoot = $this->support->refreshMediaCounters($shoot->fresh());
            $this->support->clearShootFilesCache($shoot);

            if (
                $shoot->raw_photo_count > 0 &&
                in_array($shoot->workflow_status, [Shoot::STATUS_SCHEDULED, 'scheduled', 'booked'], true)
            ) {
                $shoot->updateWorkflowStatus(Shoot::STATUS_UPLOADED, $user?->id ?? auth()->id());
                $workflowStatusChanged = true;
                $shouldQueueIguideSync = true;
            }

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();

            return [
                'status' => 500,
                'payload' => [
                    'error_type' => 'server_error',
                    'message' => 'Failed to finalize raw upload queue',
                    'error' => $exception->getMessage(),
                ],
            ];
        }

        if ($shouldQueueIguideSync) {
            SyncShootIguideJob::dispatch($shoot->id);
        }

        return [
            'status' => 200,
            'payload' => [
                'message' => $workflowStatusChanged
                    ? 'Raw upload queue finalized successfully'
                    : 'Raw upload queue finalized with no workflow change',
                'workflow_status_changed' => $workflowStatusChanged,
                'shoot_status' => $shoot->workflow_status,
                'raw_photo_count' => $shoot->raw_photo_count,
                'edited_photo_count' => $shoot->edited_photo_count,
                'raw_missing_count' => $shoot->raw_missing_count,
                'edited_missing_count' => $shoot->edited_missing_count,
                'missing_raw' => $shoot->missing_raw,
                'missing_final' => $shoot->missing_final,
            ],
        ];
    }
}
