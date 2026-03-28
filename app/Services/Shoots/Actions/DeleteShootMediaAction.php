<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Shoots\ShootMediaMutationSupportService;

class DeleteShootMediaAction
{
    public function __construct(protected ShootMediaMutationSupportService $support)
    {
    }

    public function execute(Shoot $shoot, ShootFile $file): array
    {
        $shoot = $this->support->deleteFile($shoot, $file);

        return [
            'message' => 'File deleted',
            'shoot_status' => $shoot->workflow_status,
            'raw_photo_count' => $shoot->raw_photo_count,
            'edited_photo_count' => $shoot->edited_photo_count,
        ];
    }
}
