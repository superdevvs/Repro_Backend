<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\VoiceCall;
use App\Services\TelnyxAi\VoiceRoutingService;
use App\Services\TelnyxAi\VoiceSettingsService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VoiceTransferController extends Controller
{
    public function __invoke(Request $request, VoiceCall $call, VoiceRoutingService $routing, VoiceSettingsService $settings): JsonResponse
    {
        $data = $request->validate(['reason' => ['sometimes', 'string', 'max:500']]);
        if (strtolower((string) $call->provider) !== 'telnyx') {
            return response()->json(['message' => 'Live transfer is only available for Telnyx calls.'], 422);
        }
        if (! filled($settings->all()['support_handoff_number'] ?? null)) {
            return response()->json(['message' => 'Configure a support handoff number before transferring.'], 422);
        }

        try {
            return Cache::lock('voice:operator-transfer:'.$call->id, 40)->block(2, function () use ($call, $routing, $data): JsonResponse {
                $call->refresh();
                if ($call->ended_at || in_array($call->status, ['completed', 'failed', 'missed', 'cancelled', 'exhausted'], true)) {
                    return response()->json(['message' => 'This call has ended. Schedule a callback instead.'], 409);
                }
                if (! filled($call->call_control_id)) {
                    return response()->json(['message' => 'The carrier has not connected this call yet.'], 409);
                }
                if (data_get($call->metadata, 'transfer_requested_at')) {
                    return response()->json($call);
                }
                $updated = $routing->transferToStaff($call, $data['reason'] ?? 'operator_requested');

                return response()->json($updated);
            });
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'A transfer is already being processed for this call. Refresh its status before trying again.'], 409);
        }
    }
}
