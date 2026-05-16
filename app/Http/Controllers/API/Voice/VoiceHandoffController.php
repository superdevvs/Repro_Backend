<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\VoiceCall;
use Illuminate\Http\JsonResponse;

class VoiceHandoffController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'handoffs' => VoiceCall::query()
                ->with(['callerUser:id,name,email', 'callerContact:id,name,email,phone'])
                ->whereIn('disposition', ['handoff_to_staff', 'transferred'])
                ->latest('updated_at')
                ->limit(10)
                ->get(),
        ]);
    }
}
