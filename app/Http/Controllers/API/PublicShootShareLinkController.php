<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use Illuminate\Http\JsonResponse;

class PublicShootShareLinkController extends Controller
{
    public function show(Shoot $shoot, int $linkId): JsonResponse
    {
        $link = $shoot->shareLinks()->find($linkId);

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

        if (!is_string($link->share_url) || trim($link->share_url) === '') {
            return response()->json([
                'error' => 'Share link destination is unavailable',
            ], 404);
        }

        $link->incrementDownloadCount();

        return response()->json([
            'redirect_url' => $link->share_url,
        ]);
    }
}
