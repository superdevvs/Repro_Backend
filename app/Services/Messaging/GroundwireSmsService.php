<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Models\SmsNumber;
use App\Models\VoiceLinePolicy;
use App\Models\VoiceSipDevice;
use App\Services\Voice\VoiceLinePolicyService;
use App\Services\Voice\VoiceSipDeviceService;
use App\Support\LockedWrite;
use App\Support\VoiceLocks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** Acrobits HTTPS messaging uses the existing, individually revocable SIP login. */
class GroundwireSmsService
{
    public function normalize(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return '+'.(strlen($digits) === 10 ? '1'.$digits : $digits);
    }

    public function number(string $phone): ?SmsNumber
    {
        $matches = SmsNumber::all()->filter(fn ($n) => $this->normalize($n->phone_number) === $phone);

        // Duplicate CRM rows must not pick conflicting routes or profiles.
        return $matches->count() === 1 && filled($matches->first()->messaging_profile_id) ? $matches->first() : null;
    }

    public function lines(VoiceSipDevice $device): array
    {
        if (! app(VoiceSipDeviceService::class)->allowed($device)) {
            return [];
        }
        $policies = app(VoiceLinePolicyService::class);

        return VoiceLinePolicy::where('mode', 'staff_voicemail')->get()
            ->filter(fn ($p) => $policies->recipients($p)->contains('id', $device->user_id) && $this->number($p->phone_number))
            ->map(fn ($p) => $p->phone_number)->values()->all();
    }

    public function permitted(VoiceSipDevice $device): bool
    {
        return $device->sms_number && in_array($device->sms_number, $this->lines($device), true);
    }

    public function state(VoiceSipDevice $device): array
    {
        $lines = $this->lines($device);

        return ['number' => $device->sms_number, 'available_numbers' => $lines,
            'ready' => $this->permitted($device), 'phone_connected' => (bool) $device->sms_install_hash,
            'push_registered' => (bool) $device->sms_push, 'last_fetch_at' => $device->sms_last_fetch_at?->toIso8601String(),
            'last_send_at' => $device->sms_last_send_at?->toIso8601String(),
            'setup_url' => rtrim(config('app.url'), '/').'/api/groundwire/sms/provision'];
    }

    public function configure(VoiceSipDevice $device, ?string $number, bool $reset = false): void
    {
        abort_unless(app(VoiceSipDeviceService::class)->allowed($device), 403);
        abort_if($number !== null && ! in_array($number, $this->lines($device), true), 422, 'Choose an assigned SMS-ready staff line.');
        VoiceLocks::lock('groundwire-bind:'.$device->id, 20)->block(3, function () use ($device, $number, $reset) {
            $device->refresh();
            abort_if($device->sms_install_hash && $device->sms_number !== $number && ! $reset, 409,
                'Remove this account from Groundwire before changing its SMS line. Existing conversations keep their original line.');
            $data = ['sms_number' => $number];
            if ($reset || ! $device->sms_bound_at) {
                $data += ['sms_bound_at' => now(), 'sms_install_hash' => null, 'sms_push' => null,
                    'sms_last_fetch_at' => null, 'sms_last_send_at' => null];
            }
            LockedWrite::run(fn () => $device->update($data));
        });
    }

    public function authenticate(Request $r): VoiceSipDevice
    {
        // Never accept credentials in a URL (access logs and copied links).
        abort_if($r->query('password') !== null || $r->query('username') !== null, 400, 'Use the secure POST configuration.');
        $data = validator($r->post(), ['username' => 'required|string|max:100', 'password' => 'required|string|max:256',
            'installid' => 'required|string|max:256'])->validate();
        $key = 'gw-auth:'.hash('sha256', $data['username'].'|'.$r->ip());
        abort_if(RateLimiter::tooManyAttempts($key, 90), 429, 'Too many attempts. Try again shortly.');
        RateLimiter::hit($key, 60);
        $d = VoiceSipDevice::where('sip_username', $data['username'])->first();
        abort_unless($d && hash_equals((string) $d->sip_password, $data['password']) && $this->permitted($d), 403,
            'SMS access unavailable. Check your device and line in Dashboard Calls settings.');
        VoiceLocks::lock('groundwire-bind:'.$d->id, 15)->block(3, function () use ($d, $data) {
            $d->refresh();
            abort_unless($this->permitted($d), 403, 'SMS access unavailable.');
            $hash = hash('sha256', $data['installid']);
            abort_if($d->sms_install_hash && ! hash_equals($d->sms_install_hash, $hash), 403,
                'This SMS account is linked to another phone. Reset SMS pairing in Dashboard.');
            if (! $d->sms_install_hash) {
                LockedWrite::run(fn () => $d->update(['sms_install_hash' => $hash]));
            }
        });

        return $d;
    }

    public function fetch(VoiceSipDevice $d, array $data): array
    {
        abort_unless($this->permitted($d->fresh()), 403, 'SMS access unavailable.');
        $result = ['date' => now()->toIso8601String()];
        foreach (['received_smss' => ['INBOUND', 'to_address', 'last_known_sms_id'],
            'sent_smss' => ['OUTBOUND', 'from_address', 'last_known_sent_sms_id']] as $key => [$direction, $address, $cursor]) {
            $rows = Message::where('channel', 'SMS')->where('direction', $direction)->where($address, $d->sms_number)
                ->where('hidden_from_inbox', false)->whereIn('status', ['SENT', 'DELIVERED'])
                ->where('created_at', '>=', now()->subDays(90))->where('id', '>', (int) ($data[$cursor] ?? 0))
                ->orderBy('id')->limit(250)->get();
            $result[$key] = $rows->map(function ($m) use ($direction) {
                return ['sms_id' => (string) $m->id, 'sending_date' => $m->created_at->toIso8601String(),
                    $direction === 'INBOUND' ? 'sender' : 'recipient' => $direction === 'INBOUND' ? $m->from_address : $m->to_address,
                    'sms_text' => $m->body_text ?? '', 'content_type' => 'text/plain'];
            })->all();
        }
        LockedWrite::run(fn () => $d->update(['sms_last_fetch_at' => now()]));

        return $result;
    }

    public function reportPush(VoiceSipDevice $d, array $data): void
    {
        abort_unless($this->permitted($d->fresh()), 403, 'SMS access unavailable.');
        // The non-VoIP token pair is mandatory. Never use the incoming-call PushKit token.
        if (empty($data['token']) || empty($data['app_id']) || empty($data['selector'])) {
            return; // iOS may report before notification permission has been granted.
        }
        $push = ['token' => $data['token'], 'app_id' => $data['app_id'], 'selector' => $data['selector']];
        LockedWrite::run(fn () => $d->update(['sms_push' => $push]));
    }

    public function provision(): string
    {
        $base = rtrim(config('app.url'), '/').'/api/groundwire/sms/';
        $auth = 'username=%account[username]%&password=%account[password]%&installid=%installid%';
        $nodes = ['allowMessage' => '1'];
        foreach ([
            'genericSmsSend' => ['send', '&to=%sms_to%&body=%sms_body%&content_type=%content_type%&client_id=%disposition_notification%'],
            'genericSmsFetch' => ['fetch', '&last_known_sms_id=%last_known_sms_id%&last_known_sent_sms_id=%last_known_sent_sms_id%'],
            'pushTokenReporter' => ['push-token', '&token=%pushTokenOther%&app_id=%pushappid_other%&selector=%selector%'],
            'reportAccountRemoval' => ['remove', ''],
        ] as $prefix => [$path, $extra]) {
            $nodes[$prefix.'Url'] = $base.$path;
            $nodes[$prefix.'PostData'] = $auth.$extra;
            $nodes[$prefix.'ContentType'] = 'application/x-www-form-urlencoded';
            $nodes[$prefix.'Method'] = 'POST';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><account>';
        foreach ($nodes as $key => $value) {
            $xml .= '<'.$key.' priority="40">'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</'.$key.'>';
        }

        return $xml.'</account>';
    }
}
