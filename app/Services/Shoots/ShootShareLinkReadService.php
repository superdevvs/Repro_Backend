<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootShareLink;

class ShootShareLinkReadService
{
    public function listLinks(Shoot $shoot): array
    {
        return $shoot->shareLinks()
            ->with('creator:id,name')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (ShootShareLink $link) => [
                'id' => $link->id,
                'share_url' => $link->share_url,
                'download_count' => $link->download_count,
                'created_at' => $link->created_at->toIso8601String(),
                'expires_at' => $link->expires_at?->toIso8601String(),
                'is_expired' => $link->isExpired(),
                'is_revoked' => $link->is_revoked,
                'is_active' => $link->isActive(),
                'created_by' => $link->creator ? [
                    'id' => $link->creator->id,
                    'name' => $link->creator->name,
                ] : null,
            ])
            ->all();
    }
}
