<?php

namespace App\Services\Messaging;

use App\Models\GroundwireSmsSend;
use App\Models\Message;
use App\Models\VoiceSipDevice;
use App\Support\LockedWrite;
use App\Support\VoiceLocks;
use Illuminate\Support\Facades\RateLimiter;

class GroundwireSmsSender
{
    public function send(VoiceSipDevice $d, array $data): array
    {
        $sms = app(GroundwireSmsService::class);
        $to = $sms->normalize($data['to']);
        abort_unless(preg_match('/^\+[1-9]\d{7,14}$/', $to), 422, 'Use the recipient country code, for example +1.');
        abort_if($to === $sms->normalize($d->sms_number), 422, 'Choose a different recipient number.');
        $body = trim($data['body']);
        abort_if($body === '', 422, 'Enter a text message.');

        return VoiceLocks::lock('groundwire-send:'.$d->id, 45)->block(3, function () use ($d, $data, $sms, $to, $body) {
            $d->refresh();
            abort_unless($sms->permitted($d), 403);
            $fingerprint = hash('sha256', $d->id.'|'.$d->sms_number.'|'.$to.'|'.$body);
            $clientId = trim((string) ($data['client_id'] ?? ''));
            // IMDN identifiers, when available, are stable across the app's retries.
            $key = $clientId !== '' && ! str_contains($clientId, '%') ? hash('sha256', $clientId) : null;
            $prior = GroundwireSmsSend::where('device_id', $d->id)->where(function ($q) use ($key, $fingerprint) {
                if ($key) {
                    $q->where('request_key', $key);
                } else {
                    // Acrobits does not guarantee a client ID. Coalesce rapid identical sends;
                    // hold ambiguous results for reconciliation rather than charging twice.
                    $q->where('fingerprint', $fingerprint)->where(function ($q) {
                        $q->where('created_at', '>=', now()->subSeconds(60))
                            ->orWhere(fn ($q) => $q->whereIn('status', ['uncertain', 'attempting'])->where('created_at', '>=', now()->subDay()));
                    });
                }
            })->latest('created_at')->first();
            if ($prior) {
                abort_if($prior->fingerprint !== $fingerprint, 409, 'This message identifier has different text.');
                abort_unless($prior->status === 'sent' && $prior->message_id, 409,
                    'Send not confirmed. Check Dashboard Messages before sending again.');

                return ['sms_id' => (string) $prior->message_id];
            }
            foreach (['gw-send:'.$d->id => [12, 60], 'gw-recipient:'.$d->id.':'.$to => [10, 600], 'gw-send-total' => [150, 3600]] as $limit => [$max, $seconds]) {
                abort_if(RateLimiter::tooManyAttempts($limit, $max), 429, 'Message limit reached. Try again later.');
                RateLimiter::hit($limit, $seconds);
            }
            $attempt = LockedWrite::run(fn () => GroundwireSmsSend::create(['device_id' => $d->id,
                'request_key' => $key, 'fingerprint' => $fingerprint]));
            try {
                $m = app(MessagingService::class)->sendSms(['sms_number_id' => $sms->number($d->sms_number)->id,
                    'to' => $to, 'body_text' => $body, 'user_id' => $d->user_id, 'sender_user_id' => $d->user_id,
                    'send_source' => 'MANUAL', 'single_attempt' => true,
                    'metadata' => ['groundwire_request_id' => $attempt->id, 'groundwire_device_id' => $d->id]]);
                abort_unless(in_array($m->status, ['SENT', 'DELIVERED'], true), 422, 'Message not sent. Check Dashboard for the reason.');
                LockedWrite::run(fn () => $attempt->update(['status' => 'sent', 'message_id' => $m->id]));
                LockedWrite::run(fn () => $d->update(['sms_last_send_at' => now()]));

                return ['sms_id' => (string) $m->id];
            } catch (\Throwable) {
                $m = Message::where('metadata->groundwire_request_id', $attempt->id)->first();
                LockedWrite::run(fn () => $attempt->update(['status' => 'uncertain', 'message_id' => $m?->id]));
                abort(409, 'Send not confirmed. Check Dashboard Messages before sending again.');
            }
        });
    }
}
