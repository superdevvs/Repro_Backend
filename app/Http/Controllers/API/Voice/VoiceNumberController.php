<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\SmsNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceNumberController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'numbers' => SmsNumber::query()
                ->orderByDesc('is_default')
                ->orderBy('label')
                ->get(['id', 'phone_number', 'label', 'is_default', 'voice_ai_enabled', 'voice_assistant_id_override', 'sms_ai_enabled']),
        ]);
    }

    public function update(Request $request, SmsNumber $smsNumber): JsonResponse
    {
        $data = $request->validate([
            'voice_ai_enabled' => ['sometimes', 'nullable', 'boolean'],
            'voice_assistant_id_override' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sms_ai_enabled' => ['sometimes', 'nullable', 'boolean'],
        ]);

        $smsNumber->fill($data)->save();

        return response()->json($smsNumber->fresh());
    }
}
