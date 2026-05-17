<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Services\TelnyxAi\VoiceSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceSettingsController extends Controller
{
    public function show(VoiceSettingsService $settings): JsonResponse
    {
        return response()->json($settings->all());
    }

    public function update(Request $request, VoiceSettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'recording_enabled' => ['sometimes', 'boolean'],
            'disclosure_text' => ['sometimes', 'string', 'max:1000'],
            'gather_prompt' => ['sometimes', 'string', 'max:1000'],
            'support_handoff_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'allow_unverified_transfer' => ['sometimes', 'boolean'],
            'quiet_hours' => ['sometimes', 'array'],
            'quiet_hours.enabled' => ['sometimes', 'boolean'],
            'quiet_hours.start' => ['sometimes', 'date_format:H:i'],
            'quiet_hours.end' => ['sometimes', 'date_format:H:i'],
            'quiet_hours.timezone' => ['sometimes', 'string', 'max:64'],
            'callback_retry_delay_minutes' => ['sometimes', 'integer', 'min:5', 'max:10080'],
            'callback_max_attempts' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'automation_toggles' => ['sometimes', 'array'],
            'automation_toggles.missed_call_callback' => ['sometimes', 'boolean'],
            'automation_toggles.failed_transfer_callback' => ['sometimes', 'boolean'],
            'automation_toggles.shoot_reminder' => ['sometimes', 'boolean'],
            'automation_toggles.delivery_follow_up' => ['sometimes', 'boolean'],
            'automation_toggles.unpaid_invoice_reminder' => ['sometimes', 'boolean'],
            'tool_allowlist' => ['sometimes', 'array'],
            'tool_allowlist.*' => ['string', 'max:255'],
            'confirmation_gated_tools' => ['sometimes', 'array'],
            'confirmation_gated_tools.*' => ['string', 'max:255'],
            'debug_capture' => ['sometimes', 'boolean'],
        ]);

        return response()->json($settings->update($data));
    }
}
