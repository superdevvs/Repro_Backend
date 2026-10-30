<?php

namespace App\Services\Voice;

use App\Models\User;
use App\Services\TelnyxAi\VoiceNumberSettingsResolver;

/** Native caller IDs are assigned voice lines, never an implicit SMS fallback. */
class VoiceMobileCallerIdService
{
    public function __construct(private VoiceNumberSettingsResolver $numbers) {}

    public function options(User $user): array
    {
        abort_unless(app(VoiceBrowserSessionService::class)->canOperate($user), 403);
        $configured = $this->numbers->normalize((string) config('services.telnyx.from_number'));
        $companyNumbers = $this->numbers->canonicalNumbers()->map(fn ($number) => $this->numbers->normalize($number->phone_number))->all();
        $companyNumbers[] = $configured;

        // Groundwire's line list already requires an explicit voice policy and
        // current assignment. Intersect it with the web lane's company sources:
        // a policy alone cannot authorize an unowned/spoofed caller ID.
        $choices = collect(app(VoiceSipDeviceService::class)->lines($user))
            ->map(fn ($line) => ['number' => $this->numbers->normalize($line['number']), 'label' => $line['label']])
            ->filter(fn ($line) => preg_match('/^\+[1-9]\d{7,14}$/', $line['number'])
                && in_array($line['number'], $companyNumbers, true))
            ->unique('number')->sortBy('number')->values()->all();
        $numbers = array_column($choices, 'number');
        // Preserve an explicitly configured company default only when it is a
        // permitted voice line. SmsNumber.is_default is an SMS setting, not a
        // voice preference; multiple alternatives always require a choice.
        $default = in_array($configured, $numbers, true) ? $configured
            : (count($numbers) === 1 ? $numbers[0] : null);

        return ['caller_ids' => $choices, 'default_caller_id' => $default,
            'selection_required' => count($choices) > 1 && $default === null];
    }

    public function resolve(User $user, ?string $requested = null): string
    {
        $options = $this->options($user);
        $number = $requested === null ? $options['default_caller_id'] : $this->numbers->normalize($requested);
        abort_unless($number !== null && in_array($number, array_column($options['caller_ids'], 'number'), true), 422,
            'Choose an available voice business line.');

        return $number;
    }
}
