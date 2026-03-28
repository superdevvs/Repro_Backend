<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootActivityLogger;

class RevokeShootShareLinkAction
{
    public function __construct(protected ShootActivityLogger $activityLogger)
    {
    }

    public function execute(Shoot $shoot, $link, User $user): array
    {
        $link->revoke($user->id);

        $this->activityLogger->log(
            $shoot,
            'share_link_revoked',
            [
                'editor_id' => $user->id,
                'editor_name' => $user->name,
                'share_link_id' => $link->id,
            ],
            $user
        );

        return [
            'message' => 'Share link revoked successfully',
            'data' => [
                'id' => $link->id,
                'is_revoked' => true,
                'revoked_at' => $link->revoked_at->toIso8601String(),
            ],
        ];
    }
}
