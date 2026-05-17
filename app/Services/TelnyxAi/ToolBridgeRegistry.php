<?php

namespace App\Services\TelnyxAi;

class ToolBridgeRegistry
{
    public const ALLOWED_TOOLS = [
        'verify_caller',
        'get_shoot_details',
        'list_shoots',
        'get_payment_status',
        'get_availability',
        'book_shoot',
        'reschedule_shoot',
        'cancel_shoot',
        'create_payment_link',
        'handoff_to_staff',
        'transfer_to_staff',
    ];

    public const CONFIRMATION_GATED = [
        'book_shoot',
        'reschedule_shoot',
        'cancel_shoot',
        'create_payment_link',
    ];

    public const VOICE_ONLY = [
        'transfer_to_staff',
    ];

    public function __construct(private readonly VoiceSettingsService $settings)
    {
    }

    public function isAllowed(string $tool): bool
    {
        return in_array($tool, $this->allowedTools(), true);
    }

    public function requiresConfirmation(string $tool): bool
    {
        return in_array($tool, $this->confirmationGatedTools(), true);
    }

    public function isVoiceOnly(string $tool): bool
    {
        return in_array($tool, self::VOICE_ONLY, true);
    }

    public function requiresVerified(string $tool): bool
    {
        return !in_array($tool, ['verify_caller', 'handoff_to_staff'], true);
    }

    public function allowedTools(): array
    {
        $configured = $this->settings->all()['tool_allowlist'] ?? self::ALLOWED_TOOLS;
        $configured = is_array($configured) ? array_values(array_filter($configured, 'is_string')) : self::ALLOWED_TOOLS;

        return array_values(array_intersect(self::ALLOWED_TOOLS, $configured));
    }

    public function confirmationGatedTools(): array
    {
        $configured = $this->settings->all()['confirmation_gated_tools'] ?? self::CONFIRMATION_GATED;
        $configured = is_array($configured) ? array_values(array_filter($configured, 'is_string')) : self::CONFIRMATION_GATED;

        return array_values(array_intersect(self::ALLOWED_TOOLS, $configured));
    }
}
