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
        'set_recording_consent',
    ];

    public const CONFIRMATION_GATED = [
        'book_shoot',
        'reschedule_shoot',
        'cancel_shoot',
        'create_payment_link',
    ];

    public const VOICE_ONLY = [
        'transfer_to_staff',
        'set_recording_consent',
    ];

    public function __construct(private readonly VoiceSettingsService $settings) {}

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
        if (in_array($tool, ['verify_caller', 'handoff_to_staff', 'set_recording_consent'], true)) {
            return false;
        }

        if ($tool === 'transfer_to_staff' && ($this->settings->all()['allow_unverified_transfer'] ?? false)) {
            return false;
        }

        return true;
    }

    public function allowedTools(): array
    {
        $configured = $this->settings->all()['tool_allowlist'] ?? self::ALLOWED_TOOLS;
        $configured = is_array($configured) ? array_values(array_filter($configured, 'is_string')) : self::ALLOWED_TOOLS;

        // Consent is a mandatory safety control, including for installations
        // whose persisted allowlist predates this tool.
        $configured[] = 'set_recording_consent';

        return array_values(array_intersect(self::ALLOWED_TOOLS, array_unique($configured)));
    }

    public function confirmationGatedTools(): array
    {
        $configured = $this->settings->all()['confirmation_gated_tools'] ?? self::CONFIRMATION_GATED;
        $configured = is_array($configured) ? array_values(array_filter($configured, 'is_string')) : self::CONFIRMATION_GATED;

        return array_values(array_intersect(self::ALLOWED_TOOLS, $configured));
    }

    /** @return array<string,array{description:string,schema:array<string,mixed>}> */
    public function definitions(): array
    {
        $integer = static fn (string $description): array => ['type' => 'integer', 'description' => $description];
        $string = static fn (string $description): array => ['type' => 'string', 'description' => $description];
        $schema = static fn (array $properties, array $required = []): array => [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
        $confirmation = $string('Opaque token returned by the previous requires_confirmation response.');

        return [
            'verify_caller' => [
                'description' => 'Send an SMS verification code or verify a code spoken by the caller.',
                'schema' => $schema([
                    'request_otp' => ['type' => 'boolean', 'description' => 'Set true to send a new SMS code.'],
                    'otp_code' => $string('Verification code provided by the caller.'),
                    'method' => ['type' => 'string', 'enum' => ['sms_otp'], 'description' => 'Verification method.'],
                ]),
            ],
            'get_shoot_details' => [
                'description' => 'Get authorized details for one shoot after caller verification.',
                'schema' => $schema(['shoot_id' => $integer('Shoot ID.')], ['shoot_id']),
            ],
            'list_shoots' => [
                'description' => 'List shoots belonging to the verified caller.',
                'schema' => $schema([
                    'status' => ['type' => 'string', 'description' => 'Optional status filter.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'Maximum results.'],
                ]),
            ],
            'get_payment_status' => [
                'description' => 'Get payment status for an authorized shoot.',
                'schema' => $schema(['shoot_id' => $integer('Shoot ID.')], ['shoot_id']),
            ],
            'get_availability' => [
                'description' => 'Get photographer availability for a date or the next week.',
                'schema' => $schema([
                    'date' => $string('Specific date in YYYY-MM-DD format.'),
                    'date_range' => ['type' => 'string', 'enum' => ['today', 'week'], 'description' => 'Range hint.'],
                    'service_id' => $integer('Optional service ID.'),
                    'photographer_id' => $integer('Optional photographer ID.'),
                ]),
            ],
            'book_shoot' => [
                'description' => 'Prepare or confirm a new shoot booking for the verified caller. Never execute before explicit confirmation.',
                'schema' => $schema([
                    'address' => $string('Property street address.'),
                    'city' => $string('Property city.'),
                    'state' => $string('State or province.'),
                    'zip' => $string('Postal code.'),
                    'services' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Service IDs.'],
                    'date' => $string('Optional date in YYYY-MM-DD format.'),
                    'time' => $string('Optional appointment time or window.'),
                    'photographer_id' => $integer('Optional photographer ID.'),
                    'notes' => $string('Optional booking notes.'),
                    'confirmation_token' => $confirmation,
                ], ['address', 'city', 'state', 'zip', 'services']),
            ],
            'reschedule_shoot' => [
                'description' => 'Prepare or confirm a date or time change for an authorized shoot.',
                'schema' => $schema([
                    'shoot_id' => $integer('Shoot ID.'),
                    'new_date' => $string('New date in YYYY-MM-DD format.'),
                    'new_time' => $string('New appointment time or window.'),
                    'confirmation_token' => $confirmation,
                ], ['shoot_id']),
            ],
            'cancel_shoot' => [
                'description' => 'Prepare or confirm cancellation of an authorized shoot.',
                'schema' => $schema([
                    'shoot_id' => $integer('Shoot ID.'),
                    'reason' => $string('Cancellation reason.'),
                    'confirmation_token' => $confirmation,
                ], ['shoot_id']),
            ],
            'create_payment_link' => [
                'description' => 'Prepare or confirm creation of a payment link for an authorized shoot.',
                'schema' => $schema([
                    'shoot_id' => $integer('Shoot ID.'),
                    'confirmation_token' => $confirmation,
                ], ['shoot_id']),
            ],
            'handoff_to_staff' => [
                'description' => 'Request staff follow-up when the caller needs a person or a transfer cannot complete.',
                'schema' => $schema(['reason' => $string('Reason for staff follow-up.')]),
            ],
            'transfer_to_staff' => [
                'description' => 'Transfer the active call to the configured RePro support number.',
                'schema' => $schema(['reason' => $string('Reason for the transfer.')]),
            ],
            'set_recording_consent' => [
                'description' => 'Record the caller’s explicit recording choice. Call this before any recording can start.',
                'schema' => $schema([
                    'consented' => ['type' => 'boolean', 'description' => 'True only after an explicit yes; false after no.'],
                ], ['consented']),
            ],
        ];
    }

    public function definition(string $tool): ?array
    {
        return $this->definitions()[$tool] ?? null;
    }

    /** @return array<string,list<string>> */
    public function validationRules(string $tool): array
    {
        return match ($tool) {
            'verify_caller' => [
                'request_otp' => ['nullable', 'boolean'],
                'otp_code' => ['nullable', 'string', 'max:32'],
                'method' => ['nullable', 'in:sms_otp'],
            ],
            'get_shoot_details', 'get_payment_status' => ['shoot_id' => ['required', 'integer', 'min:1']],
            'list_shoots' => ['status' => ['nullable', 'string', 'max:64'], 'limit' => ['nullable', 'integer', 'between:1,10']],
            'get_availability' => [
                'date' => ['nullable', 'date_format:Y-m-d'],
                'date_range' => ['nullable', 'in:today,week'],
                'service_id' => ['nullable', 'integer', 'min:1'],
                'photographer_id' => ['nullable', 'integer', 'min:1'],
            ],
            'book_shoot' => [
                'address' => ['required_without:confirmation_token', 'string', 'max:255'],
                'city' => ['required_without:confirmation_token', 'string', 'max:120'],
                'state' => ['required_without:confirmation_token', 'string', 'max:80'],
                'zip' => ['required_without:confirmation_token', 'string', 'max:24'],
                'services' => ['required_without:confirmation_token', 'array', 'min:1'],
                'services.*' => ['integer', 'min:1'],
                'date' => ['nullable', 'date_format:Y-m-d'],
                'time' => ['nullable', 'string', 'max:80'],
                'photographer_id' => ['nullable', 'integer', 'min:1'],
                'notes' => ['nullable', 'string', 'max:1000'],
                'confirmation_token' => ['nullable', 'string', 'max:128'],
            ],
            'reschedule_shoot' => [
                'shoot_id' => ['required_without:confirmation_token', 'integer', 'min:1'],
                'new_date' => ['nullable', 'date_format:Y-m-d'],
                'new_time' => ['nullable', 'string', 'max:80'],
                'confirmation_token' => ['nullable', 'string', 'max:128'],
            ],
            'cancel_shoot' => [
                'shoot_id' => ['required_without:confirmation_token', 'integer', 'min:1'],
                'reason' => ['nullable', 'string', 'max:1000'],
                'confirmation_token' => ['nullable', 'string', 'max:128'],
            ],
            'create_payment_link' => [
                'shoot_id' => ['required_without:confirmation_token', 'integer', 'min:1'],
                'confirmation_token' => ['nullable', 'string', 'max:128'],
            ],
            'handoff_to_staff', 'transfer_to_staff' => ['reason' => ['nullable', 'string', 'max:1000']],
            'set_recording_consent' => ['consented' => ['required', 'boolean']],
            default => [],
        };
    }
}
