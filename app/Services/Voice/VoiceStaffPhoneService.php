<?php

namespace App\Services\Voice;

use App\Models\User;
use App\Models\VoiceStaffPhone;
use App\Services\Messaging\MessagingService;
use App\Services\TelnyxAi\VoiceNumberSettingsResolver;
use App\Support\LockedWrite;
use App\Support\VoiceLocks;
use Illuminate\Support\Facades\Hash;

class VoiceStaffPhoneService
{
    public function state(User $user): array
    {
        $phone = VoiceStaffPhone::where('user_id', $user->id)->first();

        return ['available' => $phone?->available ?? true, 'phone_enabled' => $phone?->phone_enabled ?? false,
            'phone_number' => $phone?->phone, 'phone_verified_at' => $phone?->verified_at?->toIso8601String(),
            'pending_phone' => $phone?->pending_phone];
    }

    public function update(User $user, array $data): array
    {
        $phone = VoiceStaffPhone::firstOrCreate(['user_id' => $user->id]);
        abort_if(($data['phone_enabled'] ?? false) && (! $phone->verified_at || ! $phone->phone), 422, 'Verify your staff phone first.');
        LockedWrite::run(fn () => $phone->update($data));

        return $this->state($user);
    }

    public function requestVerification(User $user, string $rawPhone): array
    {
        $numbers = app(VoiceNumberSettingsResolver::class);
        $destination = $numbers->normalize($rawPhone);
        abort_unless(preg_match('/^\+[1-9]\d{7,14}$/', $destination), 422, 'Enter a valid phone number.');
        abort_if($destination === $numbers->normalize((string) config('services.telnyx.from_number')) || $numbers->matching($destination)->isNotEmpty(), 422, 'Use your personal staff phone, not a business line.');

        return VoiceLocks::lock('voice-phone-verify:'.$user->id, 30)->block(3, function () use ($user, $destination): array {
            $phone = VoiceStaffPhone::firstOrCreate(['user_id' => $user->id]);
            abort_if($phone->verification_expires_at?->gt(now()->addMinutes(9)), 429, 'Wait a minute before requesting another code.');
            $code = (string) random_int(100000, 999999);
            LockedWrite::run(fn () => $phone->update(['pending_phone' => $destination, 'verification_hash' => Hash::make($code),
                'verification_expires_at' => now()->addMinutes(10), 'verification_attempts' => 0]));
            $message = app(MessagingService::class)->sendSms(['to' => $destination, 'body_text' => 'REPro staff phone code: '.$code.'. Expires in 10 minutes. Do not share it.',
                'send_source' => 'VOICE_PHONE_VERIFICATION', 'hidden_from_inbox' => true, 'user_id' => $user->id]);
            if ($message->status !== 'SENT') {
                $phone->update(['verification_hash' => null, 'verification_expires_at' => null]);
                abort(422, 'The verification message was not sent. Check SMS delivery settings.');
            }

            return $this->state($user);
        });
    }

    public function verify(User $user, string $code): array
    {
        return VoiceLocks::lock('voice-phone-verify:'.$user->id, 15)->block(3, function () use ($user, $code): array {
            $phone = VoiceStaffPhone::where('user_id', $user->id)->first();
            abort_unless($phone && $phone->verification_expires_at?->isFuture() && $phone->verification_hash && $phone->verification_attempts < 5, 422, 'Request a new verification code.');
            $phone->increment('verification_attempts');
            abort_unless(Hash::check($code, $phone->verification_hash), 422, 'The verification code is incorrect.');
            $phone->update(['phone' => $phone->pending_phone, 'verified_at' => now(), 'phone_enabled' => true,
                'pending_phone' => null, 'verification_hash' => null, 'verification_expires_at' => null]);

            return $this->state($user);
        });
    }

    public function destination(User $user, ?\App\Models\VoiceCall $call = null): ?string
    {
        $phone = VoiceStaffPhone::where('user_id', $user->id)->where('available', true)->where('phone_enabled', true)->whereNotNull('verified_at')->first();
        $destination = $phone?->phone;
        if (! $destination || ! preg_match('/^\+[1-9]\d{7,14}$/', $destination)) {
            return null;
        }
        $numbers = app(VoiceNumberSettingsResolver::class);
        if ($numbers->matching($destination)->isNotEmpty() || $destination === $numbers->normalize((string) config('services.telnyx.from_number'))
            || ($call && in_array($destination, [$numbers->normalize($call->from_phone), $numbers->normalize($call->to_phone)], true))) {
            return null;
        }

        return $destination;
    }
}
