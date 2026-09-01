<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\IguideOfflineViewerService;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IguideOfflineViewerLinkController extends Controller
{
    public function __invoke(
        Request $request,
        Shoot $shoot,
        IguideOfflineViewerService $viewer,
        ShootAuthorizationSupport $shootAuthorization
    ): JsonResponse {
        if (! $shootAuthorization->canAccessShootMedia($shoot, $request->user())) {
            abort(403, 'Forbidden');
        }

        $link = $viewer->issueViewerLink($shoot);

        return response()->json([
            'message' => 'Secure iGUIDE viewer link created.',
            'viewer_url' => $link['url'],
            'expires_at' => $link['expires_at'],
            'file_id' => $link['file_id'],
        ]);
    }
}
