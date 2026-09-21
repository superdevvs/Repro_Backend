<?php

namespace App\Services\TelnyxAi;

use App\Models\SmsNumber;
use App\Models\VoiceCall;
use App\Services\Messaging\AiSms\SmsContextResolverService;
use Illuminate\Support\Collection;

/** Physical phone-line policy; duplicate CRM rows must not bypass an opt-out. */
class VoiceNumberSettingsResolver
{
    public function __construct(
        private readonly VoiceSettingsService $settings,
        private readonly SmsContextResolverService $phones,
    ) {}

    public function forNumber(string $phone, string $provider = 'TELNYX'): array
    {
        $normalized = $this->phones->normalize($phone);
        $rows = $this->matching($normalized, $provider);
        $number = $rows->first();
        $settings = $this->settings->all();
        $override = $this->voiceOverride($rows);
        $assistant = trim((string) ($number?->voice_assistant_id_override ?: ($settings['assistant_id'] ?? '')));

        return [
            'number_id' => $number?->id,
            'phone_number' => $normalized,
            'voice_ai_enabled' => $override,
            'enabled' => (bool) ($settings['enabled'] ?? false) && $override !== false,
            'assistant_id' => $assistant !== '' ? $assistant : null,
        ];
    }

    public function normalize(string $phone): string
    {
        return $this->phones->normalize($phone);
    }

    public function forCall(VoiceCall $call): array
    {
        return $this->forNumber((string) (strtoupper($call->direction) === 'OUTBOUND' ? $call->from_phone : $call->to_phone));
    }

    public function aiEnabledForCall(VoiceCall $call): bool
    {
        return $this->forCall($call)['enabled']
            && ($call->metadata['voice_routing']['enabled'] ?? true) !== false;
    }

    /** @return Collection<int, SmsNumber> */
    public function matching(string $phone, string $provider = 'TELNYX'): Collection
    {
        $normalized = $this->phones->normalize($phone);
        if ($normalized === '') {
            return collect();
        }

        return $this->providerRows($provider)
            ->filter(fn (SmsNumber $number) => $this->phones->normalize($number->phone_number) === $normalized)->values();
    }

    /** @return Collection<int, SmsNumber> */
    public function canonicalNumbers(string $provider = 'TELNYX'): Collection
    {
        return $this->providerRows($provider)
            ->groupBy(fn (SmsNumber $number) => $this->phones->normalize($number->phone_number) ?: 'invalid:'.$number->id)
            ->map(function (Collection $duplicates): SmsNumber {
                $canonical = $duplicates->first();
                // Read projection only. Updates explicitly align every duplicate.
                $canonical->setAttribute('voice_ai_enabled', $this->voiceOverride($duplicates));

                return $canonical;
            })->values();
    }

    private function providerRows(string $provider): Collection
    {
        return SmsNumber::query()->whereRaw('UPPER(TRIM(provider)) = ?', [strtoupper(trim($provider))])
            ->orderByDesc('is_default')->orderBy('id')->get();
    }

    private function voiceOverride(Collection $rows): ?bool
    {
        // Any explicit disable wins until the operator updates the logical line.
        if ($rows->contains(fn (SmsNumber $number) => $number->voice_ai_enabled === false)) {
            return false;
        }

        return $rows->first()?->voice_ai_enabled;
    }
}
