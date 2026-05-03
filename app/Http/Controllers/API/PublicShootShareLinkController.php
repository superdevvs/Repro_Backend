<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ShootShareLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class PublicShootShareLinkController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $link = $this->resolveActiveLink($token);
        if ($link instanceof JsonResponse) {
            return $link;
        }

        if (!is_string($link->share_url) || trim($link->share_url) === '') {
            return response()->json([
                'error' => 'Share link destination is unavailable',
            ], 404);
        }

        if ($this->isLocalPublicZip($link)) {
            return response()->json([
                'redirect_url' => route('api.public.share-links.download', ['token' => $link->public_token]),
            ]);
        }

        $link->incrementDownloadCount();

        return response()->json([
            'redirect_url' => $link->share_url,
        ]);
    }

    public function download(string $token)
    {
        $link = $this->resolveActiveLink($token);
        if ($link instanceof JsonResponse) {
            return $link;
        }

        if (!$this->isLocalPublicZip($link)) {
            return response()->json([
                'error' => 'Share link download is unavailable',
            ], 404);
        }

        $link->incrementDownloadCount();

        return response()->download(
            Storage::disk('public')->path($link->dropbox_path),
            $this->buildDownloadFilename($link),
            ['Content-Type' => 'application/zip']
        );
    }

    private function resolveActiveLink(string $token): ShootShareLink|JsonResponse
    {
        $link = ShootShareLink::query()
            ->with('shoot')
            ->where('public_token', $token)
            ->first();

        if (!$link) {
            return response()->json([
                'error' => 'Share link not found',
            ], 404);
        }

        if ($link->is_revoked) {
            return response()->json([
                'error' => 'This share link has been revoked',
            ], 410);
        }

        if ($link->isExpired()) {
            return response()->json([
                'error' => 'This share link has expired',
            ], 410);
        }

        return $link;
    }

    private function isLocalPublicZip(ShootShareLink $link): bool
    {
        return is_string($link->dropbox_path)
            && str_starts_with($link->dropbox_path, 'share-links/')
            && Storage::disk('public')->exists($link->dropbox_path);
    }

    private function buildDownloadFilename(ShootShareLink $link): string
    {
        $shoot = $link->shoot;
        $address = implode(' ', array_filter([
            $shoot?->address,
            $shoot?->city,
            $shoot?->state,
            $shoot?->zip,
        ]));
        $addressSegment = $this->sanitizeFilenameSegment($address ?: 'shoot');
        $date = $shoot?->scheduled_date
            ?? $shoot?->scheduled_at
            ?? $shoot?->created_at;
        $dateSegment = $date ? $date->format('Y-m-d') : 'shoot-date';

        return "{$addressSegment}_{$dateSegment}.zip";
    }

    private function sanitizeFilenameSegment(string $value): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?: 'shoot';

        return trim($normalized, '_') ?: 'shoot';
    }
}
