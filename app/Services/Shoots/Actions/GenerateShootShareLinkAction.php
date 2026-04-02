<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootShareLinkService;
use Illuminate\Http\Request;

class GenerateShootShareLinkAction
{
    public function __construct(protected ShootShareLinkService $shareLinkService)
    {
    }

    public function execute(Request $request, Shoot $shoot, User $user): array
    {
        $fileIds = $request->input('file_ids', []);
        $mediaStage = (string) $request->input('media_stage', 'raw');
        if (is_string($fileIds)) {
            $fileIds = array_filter(explode(',', $fileIds));
        }

        return $this->shareLinkService->createShootShareLink($shoot, $user, $fileIds, $mediaStage);
    }
}
