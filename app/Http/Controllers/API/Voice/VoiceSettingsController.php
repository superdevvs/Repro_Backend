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
            'greeting_text' => ['sometimes', 'string', 'max:1000'],
            'fallback_menu_enabled' => ['sometimes', 'boolean'],
            'fallback_menu_delay_seconds' => ['sometimes', 'integer', 'min:5', 'max:120'],
            'human_transfer_confirmation_text' => ['sometimes', 'string', 'max:500'],
            'out_of_hours_message' => ['sometimes', 'string', 'max:500'],
            'holiday_message' => ['sometimes', 'string', 'max:500'],
            'outbound_intro_text' => ['sometimes', 'string', 'max:500'],
            'outbound_script_presets' => ['sometimes', 'array'],
            'outbound_script_presets.*.id' => ['required_with:outbound_script_presets', 'string', 'max:64'],
            'outbound_script_presets.*.label' => ['required_with:outbound_script_presets', 'string', 'max:120'],
            'outbound_script_presets.*.intent' => ['nullable', 'string', 'max:120'],
            'outbound_script_presets.*.prompt' => ['required_with:outbound_script_presets', 'string', 'max:1000'],
            'business_hours' => ['sometimes', 'array'],
            'business_hours.timezone' => ['sometimes', 'string', 'max:64'],
            'business_hours.weekly' => ['sometimes', 'array'],
            'business_hours.weekly.*' => ['array'],
            'business_hours.weekly.*.*' => ['array'],
            'holidays' => ['sometimes', 'array'],
            'holidays.*.date' => ['required_with:holidays', 'date_format:Y-m-d'],
            'holidays.*.label' => ['nullable', 'string', 'max:120'],
            'schedule_overrides' => ['sometimes', 'array'],
            'schedule_overrides.*.starts_at' => ['required_with:schedule_overrides', 'date'],
            'schedule_overrides.*.ends_at' => ['required_with:schedule_overrides', 'date', 'after:schedule_overrides.*.starts_at'],
            'schedule_overrides.*.mode' => ['required_with:schedule_overrides', 'in:open,closed'],
            'schedule_overrides.*.label' => ['nullable', 'string', 'max:120'],
            'intelligence' => ['sometimes', 'array'],
            'intelligence.enabled' => ['sometimes', 'boolean'],
            'intelligence.monthly_llm_budget_usd' => ['sometimes', 'numeric', 'min:0'],
            'intelligence.auto_schedule_follow_ups' => ['sometimes', 'boolean'],
            'intelligence.triggers' => ['sometimes', 'array'],
            'intelligence.triggers.*' => ['boolean'],
            'intelligence.thresholds' => ['sometimes', 'array'],
            'intelligence.thresholds.low_confidence_pct' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'intelligence.thresholds.silence_seconds' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'intelligence.thresholds.sentiment_drop' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'intelligence.thresholds.keywords' => ['sometimes', 'array'],
            'intelligence.thresholds.keywords.*' => ['string', 'max:64'],
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
