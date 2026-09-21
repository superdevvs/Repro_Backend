<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Services\ApiErrorResponder;
use App\Services\TelnyxAi\TelnyxAssistantSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class VoiceAssistantSyncController extends Controller
{
    public function __invoke(Request $request, TelnyxAssistantSyncService $sync): JsonResponse
    {
        $data = $request->validate([
            'promote_to_main' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $sync->sync(true, null, (bool) ($data['promote_to_main'] ?? true));
        } catch (Throwable $exception) {
            ApiErrorResponder::log($exception, 'warning');

            return response()->json([
                'message' => 'Unable to sync the Telnyx assistant.',
                'error' => ApiErrorResponder::publicMessage($exception),
            ], 502);
        }

        return response()->json($result);
    }
}
