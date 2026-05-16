<?php

namespace App\Services\Messaging\AiSms;

use App\Models\Contact;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;

class SmsComplianceService
{
    /** @var list<string> */
    public const STOP_KEYWORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'OPTOUT'];

    /** @var list<string> */
    public const START_KEYWORDS = ['START', 'UNSTOP', 'SUBSCRIBE'];

    /** @var list<string> */
    public const HELP_KEYWORDS = ['HELP', 'INFO'];

    /**
     * Detect a compliance keyword. Returns 'stop'|'start'|'help'|null.
     */
    public function detectKeyword(string $body): ?string
    {
        $token = Str::upper(trim((string) $body));

        if ($token === '') {
            return null;
        }

        if (in_array($token, self::STOP_KEYWORDS, true)) {
            return 'stop';
        }

        if (in_array($token, self::START_KEYWORDS, true)) {
            return 'start';
        }

        if (in_array($token, self::HELP_KEYWORDS, true)) {
            return 'help';
        }

        return null;
    }

    public function applyOptOut(?Contact $contact, ?User $user): void
    {
        $now = now();

        if ($contact) {
            $contact->forceFill([
                'sms_opt_out' => true,
                'sms_opt_out_at' => $now,
                'sms_ai_enabled' => false,
            ])->save();
        }

        if ($user) {
            $user->forceFill([
                'sms_opt_out' => true,
                'sms_opt_out_at' => $now,
                'sms_ai_enabled' => false,
            ])->save();
        }
    }

    public function applyOptIn(?Contact $contact, ?User $user): void
    {
        if ($contact) {
            $contact->forceFill([
                'sms_opt_out' => false,
                'sms_opt_out_at' => null,
            ])->save();
        }

        if ($user) {
            $user->forceFill([
                'sms_opt_out' => false,
                'sms_opt_out_at' => null,
            ])->save();
        }
    }

    public function staticReplyFor(string $keyword): string
    {
        $key = match ($keyword) {
            'stop' => 'stop',
            'start' => 'start',
            default => 'help',
        };

        $configured = config('services.telnyx.ai_static_replies.' . $key);

        try {
            $stored = Setting::query()->where('key', 'messaging.telnyx_ai_sms')->value('value');
            $decoded = is_string($stored) ? json_decode($stored, true) : null;
            if (is_array($decoded) && isset($decoded['static_replies'][$key]) && is_string($decoded['static_replies'][$key])) {
                $configured = $decoded['static_replies'][$key];
            }
        } catch (\Throwable $e) {
            // Settings table may be unavailable (e.g. unit tests); fall back to config defaults.
        }

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return match ($key) {
            'stop' => "You're unsubscribed from RepRO SMS. Reply START to resume.",
            'start' => "You're resubscribed. Standard rates may apply.",
            default => 'RepRO support: contact@reprophotos.com. Reply STOP to opt out.',
        };
    }

    public function isOptedOut(?Contact $contact, ?User $user): bool
    {
        if ($contact && $contact->sms_opt_out) {
            return true;
        }

        if ($user && $user->sms_opt_out) {
            return true;
        }

        return false;
    }
}
