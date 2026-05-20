<?php

namespace App\Services\TelnyxAi;

use App\Models\Setting;

class VoiceSettingsService
{
    public const SETTINGS_KEY = 'messaging.telnyx_voice';

    public function all(): array
    {
        $stored = $this->stored();

        return array_merge([
            'enabled' => (bool) config('services.telnyx.voice.enabled', false),
            'assistant_id' => config('services.telnyx.voice.assistant_id'),
            'webhook_url' => config('services.telnyx.voice.webhook_url'),
            'recording_enabled' => (bool) config('services.telnyx.voice.recording_enabled', false),
            'support_handoff_number' => config('services.telnyx.voice.support_handoff_number'),
            'allow_unverified_transfer' => (bool) config('services.telnyx.voice.allow_unverified_transfer', true),
            'disclosure_text' => config('services.telnyx.voice.disclosure_text'),
            'gather_prompt' => 'Tell me what you need, or press 1 for booking, 2 for order status, 3 for billing, or 0 for a person.',
            'greeting_text' => "Hi, you've reached RePro Photos. I'm Robbie. I can help with bookings, reschedules, payments, or connect you to the team. What can I help with?",
            'fallback_menu_enabled' => true,
            'fallback_menu_delay_seconds' => 15,
            'human_transfer_confirmation_text' => "Sure, I'll connect you to a teammate. Please stay on the line.",
            'out_of_hours_message' => "Our team is offline right now \u2014 but I can schedule a callback for the next business morning.",
            'holiday_message' => "Our office is closed today for {holiday_label}. I can help you now or schedule a callback.",
            'outbound_intro_text' => "Hi {caller_first_name}, this is Robbie from RePro Photos. Quick call about {topic}.",
            'outbound_script_presets' => [
                ['id' => 'greeting', 'label' => 'Friendly intro', 'intent' => 'general_support', 'prompt' => "Hi {caller_first_name}, this is Robbie from RePro Photos. I'm calling to check in \u2014 how can I help today?"],
                ['id' => 'booking_confirmation', 'label' => 'Booking confirmation', 'intent' => 'booking_or_reschedule', 'prompt' => "Hi {caller_first_name}, calling to confirm your shoot on {shoot_date}. Does that time still work for you?"],
                ['id' => 'payment_reminder', 'label' => 'Payment reminder', 'intent' => 'billing_payment', 'prompt' => "Hi {caller_first_name}, this is a quick reminder that invoice {invoice_number} is due. Want me to email a payment link?"],
                ['id' => 'reschedule', 'label' => 'Reschedule offer', 'intent' => 'booking_or_reschedule', 'prompt' => "Hi {caller_first_name}, I'm reaching out about your shoot on {shoot_date}. We need to reschedule \u2014 what days work for you this week?"],
            ],
            'business_hours' => [
                'timezone' => config('app.timezone', 'UTC'),
                'weekly' => [
                    'monday'    => [['09:00', '18:00']],
                    'tuesday'   => [['09:00', '18:00']],
                    'wednesday' => [['09:00', '18:00']],
                    'thursday'  => [['09:00', '18:00']],
                    'friday'    => [['09:00', '18:00']],
                    'saturday'  => [['10:00', '14:00']],
                    'sunday'    => [],
                ],
            ],
            'holidays' => [],
            'schedule_overrides' => [],
            'quiet_hours' => [
                'enabled' => true,
                'start' => '20:00',
                'end' => '08:00',
                'timezone' => config('app.timezone', 'UTC'),
            ],
            'intelligence' => [
                'enabled' => (bool) env('VOICE_INSIGHTS_LLM_ENABLED', true),
                'monthly_llm_budget_usd' => 50,
                'triggers' => [
                    'low_confidence' => true,
                    'silence' => true,
                    'sentiment_shift' => true,
                    'keyword' => true,
                    'transfer_requested' => true,
                    'cockpit_opened' => true,
                    'call_ending' => true,
                ],
                'thresholds' => [
                    'low_confidence_pct' => 70,
                    'silence_seconds' => 8,
                    'sentiment_drop' => 0.4,
                    'keywords' => ['human', 'manager', 'refund', 'complaint', 'lawyer', 'supervisor', 'escalate', 'representative', 'agent', 'owner', 'admin'],
                ],
                'auto_schedule_follow_ups' => true,
            ],
            'callback_retry_delay_minutes' => 60,
            'callback_max_attempts' => 3,
            'automation_toggles' => [
                'missed_call_callback' => true,
                'failed_transfer_callback' => true,
                'shoot_reminder' => false,
                'delivery_follow_up' => false,
                'unpaid_invoice_reminder' => false,
            ],
            'tool_allowlist' => ToolBridgeRegistry::ALLOWED_TOOLS,
            'confirmation_gated_tools' => ToolBridgeRegistry::CONFIRMATION_GATED,
            'debug_capture' => (bool) config('services.telnyx.tool_bridge.debug_capture', false),
        ], $stored);
    }

    public function update(array $settings): array
    {
        $current = $this->all();
        $allowed = array_intersect_key($settings, array_flip([
            'recording_enabled',
            'disclosure_text',
            'support_handoff_number',
            'allow_unverified_transfer',
            'enabled',
            'gather_prompt',
            'greeting_text',
            'fallback_menu_enabled',
            'fallback_menu_delay_seconds',
            'human_transfer_confirmation_text',
            'out_of_hours_message',
            'holiday_message',
            'outbound_intro_text',
            'outbound_script_presets',
            'business_hours',
            'holidays',
            'schedule_overrides',
            'intelligence',
            'quiet_hours',
            'callback_retry_delay_minutes',
            'callback_max_attempts',
            'automation_toggles',
            'tool_allowlist',
            'confirmation_gated_tools',
            'debug_capture',
        ]));

        $next = array_merge($current, $allowed);

        Setting::query()->updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            [
                'value' => json_encode($next, JSON_PRETTY_PRINT),
                'type' => 'json',
                'description' => 'Telnyx AI voice runtime settings.',
            ]
        );

        return $next;
    }

    private function stored(): array
    {
        try {
            $value = Setting::query()->where('key', self::SETTINGS_KEY)->value('value');
            $decoded = is_string($value) ? json_decode($value, true) : null;
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
