<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\IguideOfflineViewerService;
use Illuminate\Http\JsonResponse;

class IguideOfflineViewerLinkController extends Controller
{
    public function __invoke(Shoot $shoot, IguideOfflineViewerService $viewer): JsonResponse
    {
        $link = $viewer->issueViewerLink($shoot);

        return response()->json([
            'message' => 'Secure iGUIDE viewer link created.',
            'viewer_url' => $link['url'],
            'expires_at' => $link['expires_at'],
            'file_id' => $link['file_id'],
        ]);
    }
}
