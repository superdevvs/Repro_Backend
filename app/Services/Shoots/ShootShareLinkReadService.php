<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootShareLink;

class ShootShareLinkReadService
{
    public function __construct(protected ShootShareLinkService $shareLinks)
    {
    }

    public function formatLink(ShootShareLink $link): array
    {
        $link->loadMissing('creator:id,name');

        return [
            'id' => $link->id,
            'share_url' => $this->shareLinks->buildPublicShareUrl($link),
            'public_token' => $link->public_token,
            'media_stage' => $link->media_stage ?: 'raw',
            'download_count' => $link->download_count,
            'created_at' => $link->created_at->toIso8601String(),
            'expires_at' => $link->expires_at?->toIso8601String(),
            'is_expired' => $link->isExpired(),
            'is_revoked' => $link->is_revoked,
            'is_active' => $link->isActive(),
            'is_ready' => $this->isShareLinkPackageReady($link),
            'created_by' => $link->creator ? [
                'id' => $link->creator->id,
                'name' => $link->creator->name,
            ] : null,
        ];
    }


    /**
     * True once the share package can be downloaded. Pending async ZIPs keep a
     * token download URL but no dropbox_path until the queue job finishes.
     */
    protected function isShareLinkPackageReady(ShootShareLink $link): bool
    {
        if ($link->is_revoked || $link->isExpired()) {
            return false;
        }

        if (is_string($link->dropbox_path) && str_starts_with($link->dropbox_path, 'share-links/')) {
            return app(\App\Services\Media\MediaStorage::class)->exists($link->dropbox_path);
        }

        // Archive-backed links store a public archive URL and intentionally leave
        // dropbox_path null; the public archive endpoint handles preparing/ready.
        if ($link->dropbox_path === null && is_string($link->share_url) && trim($link->share_url) !== '') {
            return ! str_contains($link->share_url, '/api/public/share-links/');
        }

        return false;
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
