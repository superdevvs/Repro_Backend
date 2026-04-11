<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootShareLink;

class ShootShareLinkReadService
{
    public function formatLink(ShootShareLink $link): array
    {
        $link->loadMissing('creator:id,name');
        $frontendBaseUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $publicShareUrl = "{$frontendBaseUrl}/share/{$link->public_token}";

        return [
            'id' => $link->id,
            'share_url' => $publicShareUrl,
            'public_token' => $link->public_token,
            'media_stage' => $link->media_stage ?: 'raw',
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
        ];
    }

    public function listLinks(Shoot $shoot): array
    {
        return $shoot->shareLinks()
            ->with('creator:id,name')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (ShootShareLink $link) => $this->formatLink($link))
            ->all();
    }
}
