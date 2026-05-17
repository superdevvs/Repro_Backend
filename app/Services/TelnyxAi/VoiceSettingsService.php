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
            'quiet_hours' => [
                'enabled' => true,
                'start' => '20:00',
                'end' => '08:00',
                'timezone' => config('app.timezone', 'UTC'),
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
