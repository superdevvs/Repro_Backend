<?php

namespace App\Services\Messaging\AiSms;

use App\Models\Setting;

class SmsToolPolicy
{
    /** Read-only tools always allowed for verified, identified senders. */
    public const READ_ONLY_VERIFIED = [
        'get_shoot_details',
        'list_shoots',
        'get_payment_status',
        'get_availability',
        'get_property',
        'get_listing',
        'get_editing_types',
    ];

    /** Destructive / state-changing tools that require explicit YES confirmation. */
    public const CONFIRMATION_GATED = [
        'book_shoot',
        'reschedule_shoot',
        'cancel_shoot',
        'create_payment_link',
        'update_shoot_status',
    ];

    /** Always denied over SMS. */
    public const SMS_DENIED = [
        'update_listing_copy',
        'submit_ai_editing',
        'get_ai_editing_status',
    ];

    /** Staff-only-over-SMS tools (allowed for verified internal staff). */
    public const STAFF_ONLY_OVER_SMS = [
        'get_dashboard_stats',
    ];

    public const STAFF_ROLES = ['admin', 'superadmin', 'editing_manager', 'sales_rep', 'salesRep'];

    /**
     * Compute allow/deny lists for the orchestrator given the SMS context.
     *
     * @param  array<string, mixed>  $context
     * @return array{enabled: bool, allow: list<string>, deny: list<string>}
     */
    public function policyFor(array $context): array
    {
        $identified = (bool) ($context['identified'] ?? false);
        $verified = (bool) ($context['verified'] ?? false);
        $role = strtolower((string) ($context['user_role'] ?? ''));

        if (!$identified || !$verified) {
            // Unverified senders: only public/intake-style tools allowed.
            return [
                'enabled' => true,
                'allow' => ['get_editing_types', 'get_availability', 'book_shoot'],
                'deny' => [],
            ];
        }

        $allow = array_merge($this->readOnlyVerifiedTools(), self::CONFIRMATION_GATED);

        if (in_array($role, self::STAFF_ROLES, true)) {
            $allow = array_merge($allow, self::STAFF_ONLY_OVER_SMS);
        }

        return [
            'enabled' => true,
            'allow' => array_values(array_unique($allow)),
            'deny' => self::SMS_DENIED,
        ];
    }

    public function isConfirmationGated(string $tool): bool
    {
        return in_array($tool, self::CONFIRMATION_GATED, true);
    }

    /**
     * @return list<string>
     */
    private function readOnlyVerifiedTools(): array
    {
        try {
            $setting = Setting::query()->where('key', 'messaging.telnyx_ai_sms')->value('value');
            $decoded = is_string($setting) ? json_decode($setting, true) : null;
            $tools = is_array($decoded) ? ($decoded['allowed_tools'] ?? null) : null;
        } catch (\Throwable $e) {
            $tools = null;
        }

        if (!is_array($tools)) {
            return self::READ_ONLY_VERIFIED;
        }

        return array_values(array_intersect(
            array_map('strval', $tools),
            self::READ_ONLY_VERIFIED
        ));
    }
}
