<?php

namespace App\Services\Voice;

use App\Models\User;
use App\Models\VoiceBrowserSession;
use App\Models\VoiceCall;
use App\Models\VoiceCallEvent;
use App\Models\VoiceLinePolicy;
use App\Services\Messaging\AiSms\SmsContextResolverService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class VoiceLinePolicyService
{
    public function forNumber(string $phone): ?VoiceLinePolicy
    {
        return VoiceLinePolicy::where('phone_number', app(SmsContextResolverService::class)->normalize($phone))->first();
    }

    public function forCall(VoiceCall $call): ?VoiceLinePolicy
    {
        return $this->forNumber((string) (strtoupper($call->direction) === 'OUTBOUND' ? $call->from_phone : $call->to_phone));
    }

    public function dashboardOnly(VoiceCall $call): bool
    {
        return $this->forCall($call)?->mode === 'staff_voicemail';
    }

    public function open(VoiceLinePolicy $policy, ?CarbonImmutable $instant = null): bool
    {
        $local = ($instant ?? CarbonImmutable::now())->setTimezone($policy->timezone);
        $day = strtolower($local->format('D'));
        foreach ($policy->weekly_hours[$day] ?? [] as $slot) {
            // Wall-clock windows are evaluated in an IANA zone; DST is automatic.
            if ($local->format('H:i') >= $slot['start'] && $local->format('H:i') < $slot['end']) {
                return true;
            }
        }

        return false;
    }

    public function recipients(VoiceLinePolicy $policy): Collection
    {
        if ($policy->mode !== 'staff_voicemail') {
            return collect();
        }

        return $this->assignedRecipients($policy);
    }

    /** Staff assignments are shared by SMS and calls; only voice modes ring. */
    public function assignedRecipients(VoiceLinePolicy $policy): Collection
    {
        if (! in_array($policy->mode, ['staff_voicemail', 'sms_only'], true)) {
            return collect();
        }

        return User::query()->where(function ($q) use ($policy): void {
            $q->whereIn('id', $policy->recipient_ids ?? []);
            foreach ($policy->recipient_roles ?? array_filter([$policy->recipient_role]) as $role) {
                $q->orWhere('role', $role)->orWhereJsonContains('secondary_roles', $role);
            }
        })->get()->filter(fn (User $user) => $user->isAccountEligibleForAuthentication())->values();
    }

    public function voicemailRecipients(VoiceLinePolicy $policy): Collection
    {
        if ($policy->mode !== 'robbie') {
            return $this->recipients($policy);
        }

        return User::where(fn ($q) => $q->where('role', 'superadmin')->orWhereJsonContains('secondary_roles', 'superadmin'))
            ->get()->filter(fn ($user) => $user->isAccountEligibleForAuthentication()
                && app(VoiceBrowserSessionService::class)->canOperate($user))->values();
    }

    public function registered(User $user): bool
    {
        return VoiceBrowserSession::where('user_id', $user->id)->whereNull('revoked_at')->where('status', 'ready')->where('registered', true)
            ->where('heartbeat_at', '>=', now()->subSeconds(60))->where('expires_at', '>', now())->get()
            ->contains(fn ($session) => app(VoiceBrowserSessionService::class)->usable($session))
            || app(VoiceSipDeviceService::class)->reachable($user);
    }

    public function canAccess(User $user, VoiceCall $call): bool
    {
        $policy = $this->forCall($call);
        if (! $policy && ! $call->voicemail) {
            return true; // Preserve existing permissions on unconfigured historical lines.
        }
        if (! app(VoiceBrowserSessionService::class)->canOperate($user)) {
            return false;
        }
        if ($user->role === 'superadmin' || in_array('superadmin', $user->secondary_roles ?? [], true)) {
            return true;
        }

        return $policy?->mode === 'staff_voicemail' && $this->recipients($policy)->contains('id', $user->id);
    }

    public function scope(Builder $query, User $user): void
    {
        if (($user->role === 'superadmin' || in_array('superadmin', $user->secondary_roles ?? [], true)) && app(VoiceBrowserSessionService::class)->canOperate($user)) {
            return;
        }
        // Compare normalized values so historical punctuation cannot bypass line ACLs.
        $policies = VoiceLinePolicy::all();
        $denied = $policies->filter(fn ($p) => $p->mode !== 'staff_voicemail'
            || ! app(VoiceBrowserSessionService::class)->canOperate($user) || ! $this->recipients($p)->contains('id', $user->id));
        foreach ($denied as $policy) {
            $digits = ltrim($policy->phone_number, '+');
            $normalize = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(%s, ''), '+', ''), '-', ''), ' ', ''), '(', ''), ')', '')";
            $query->whereRaw('('.sprintf($normalize, 'to_phone').' NOT IN (?, ?) OR UPPER(direction) = ?) AND ('.sprintf($normalize, 'from_phone').' NOT IN (?, ?) OR UPPER(direction) = ?)',
                [$digits, substr($digits, 1), 'OUTBOUND', $digits, substr($digits, 1), 'INBOUND']);
        }
        $query->whereDoesntHave('voicemail', fn ($q) => $q->whereNotIn('phone_number', $policies->diff($denied)->pluck('phone_number')));
    }

    public function present(?VoiceLinePolicy $policy): ?array
    {
        if (! $policy) {
            return null;
        }
        $staff = $this->recipients($policy)->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'calling_permission' => app(VoiceBrowserSessionService::class)->canOperate($u),
            'registered' => $this->registered($u),
        ]);
        $greetings = collect(['office_greeting', 'closed_greeting'])->mapWithKeys(fn ($key) => [$key => $policy->$key && Storage::disk('voice_private')->exists($policy->$key)]);
        $last = VoiceCallEvent::where('provider', 'telnyx')->whereNotNull('processed_at')->latest('received_at')->first();

        return array_merge($policy->only(['mode', 'recipient_role', 'recipient_roles', 'recipient_ids', 'timezone', 'weekly_hours']), [
            'staff' => $staff, 'greetings' => $greetings, 'open_now' => $this->open($policy),
            'readiness' => [
                'recipients' => $staff->isNotEmpty(), 'calling_permission' => $staff->isNotEmpty() && $staff->every('calling_permission'),
                'device_registration' => $staff->contains('registered', true), 'schedule' => $policy->weekly_hours !== [],
                'greetings' => $greetings->every(fn ($ready) => $ready),
                'webhook_configured' => filled(config('services.telnyx.voice.webhook_url')) && filled(config('services.telnyx.public_key')),
                'last_webhook_at' => $last?->received_at?->toIso8601String(),
                'voice_application_configured' => filled(config('services.telnyx.voice.connection_id')) && config('services.telnyx.voice.browser_enabled'),
                // Provider assignment and end-to-end audio must be verified independently.
                'provider_assignment' => 'requires_live_verification',
            ],
        ]);
    }
}
