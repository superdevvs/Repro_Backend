<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use App\Services\Shoots\ShootMediaMutationSupportService;

class MoveShootFileToCompletedAction
{
    public function __construct(
        protected DropboxWorkflowService $dropboxService,
        protected ShootMediaMutationSupportService $support
    ) {
    }

    public function execute(Shoot $shoot, ShootFile $file, ?int $userId): array
    {
        $this->dropboxService->moveToCompleted($file, $userId);
        $shoot = $this->support->refreshMediaCounters($shoot->fresh());

        return [
            'message' => 'File moved to completed folder successfully',
            'file' => $file->fresh(),
            'shoot_status' => $shoot->workflow_status,
        ];
    }
}
