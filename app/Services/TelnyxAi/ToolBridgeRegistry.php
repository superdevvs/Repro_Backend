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

    public function isAllowed(string $tool): bool
    {
        return in_array($tool, self::ALLOWED_TOOLS, true);
    }

    public function requiresConfirmation(string $tool): bool
    {
        return in_array($tool, self::CONFIRMATION_GATED, true);
    }

    public function isVoiceOnly(string $tool): bool
    {
        return in_array($tool, self::VOICE_ONLY, true);
    }

    public function requiresVerified(string $tool): bool
    {
        return !in_array($tool, ['verify_caller', 'handoff_to_staff'], true);
    }
}
