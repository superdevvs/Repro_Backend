<?php

namespace App\Services\Messaging;

use App\Jobs\DeliverGroundwireSmsPush;
use App\Models\GroundwireSmsPush;
use App\Models\Message;
use App\Models\VoiceSipDevice;
use App\Support\LockedWrite;
use App\Support\VoiceLocks;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GroundwireSmsPushService
{
    public function queueIncoming(int $messageId): void
    {
        $m = Message::find($messageId);
        if (! $m || $m->channel !== 'SMS' || $m->direction !== 'INBOUND' || $m->hidden_from_inbox || $m->created_at->lt(now()->subMinutes(10))) {
            return;
        }
        $sms = app(GroundwireSmsService::class);
        foreach (VoiceSipDevice::where('sms_number', $m->to_address)->whereNotNull('sms_push')->get() as $d) {
            if (! $sms->permitted($d) || ! $d->sms_bound_at || $m->created_at->lt($d->sms_bound_at)) {
                continue;
            }
            $push = LockedWrite::run(fn () => GroundwireSmsPush::firstOrCreate(['device_id' => $d->id, 'message_id' => $m->id],
                ['binding_hash' => $this->binding($d)]));
            if ($push->status === 'queued') {
                DeliverGroundwireSmsPush::dispatch($push->id)->afterCommit();
            }
        }
    }

    public function binding(VoiceSipDevice $d): string
    {
        return hash('sha256', $d->sms_install_hash.'|'.json_encode($d->sms_push));
    }

    public function deliver(int $id): void
    {
        VoiceLocks::lock('groundwire-push:'.$id, 30)->block(3, function () use ($id) {
            $push = GroundwireSmsPush::find($id);
            if (! $push || $push->status !== 'queued') {
                return;
            }
            $d = VoiceSipDevice::find($push->device_id);
            $m = Message::find($push->message_id);
            if (! $d || ! $m || ! app(GroundwireSmsService::class)->permitted($d) || ! $d->sms_push
                || $m->to_address !== $d->sms_number || $m->hidden_from_inbox || $m->created_at->lt(now()->subMinutes(10))
                || ! hash_equals($push->binding_hash, $this->binding($d))) {
                LockedWrite::run(fn () => $push->update(['status' => 'expired']));

                return;
            }
            // Fixed provider endpoint; device input can never choose a URL. No SMS text
            // is disclosed to the push mediator. Fetch uses the authenticated HTTPS API.
            $response = Http::asJson()->timeout(10)->post('https://pnm.cloudsoftphone.com/pnm2/send', [
                'verb' => 'NotifyTextMessage', 'AppId' => $d->sms_push['app_id'], 'DeviceToken' => $d->sms_push['token'],
                'Selector' => $d->sms_push['selector'], 'Id' => (string) $m->id, 'ThreadId' => $m->from_address,
                'UserName' => $m->from_address, 'UserDisplayName' => 'REPro business message',
                'Message' => 'New business text. Open Groundwire to read it.', 'ContentType' => 'text/plain', 'Badge' => 1, 'Sound' => 'default',
            ]);
            $code = (int) ($response->json('status') ?? $response->json('code') ?? 0);
            if ($code === 404) {
                // Do not erase a new token reported while this request was in flight.
                $d->refresh();
                if (hash_equals($push->binding_hash, $this->binding($d))) {
                    LockedWrite::run(fn () => $d->update(['sms_push' => null]));
                }
                LockedWrite::run(fn () => $push->update(['status' => 'expired']));

                return;
            }
            if (! $response->successful() || $code !== 200) {
                throw new RuntimeException('Groundwire notification not accepted.');
            }
            // Acceptance is not proof of delivery. Stable Id deduplicates retries on
            // the phone if the mediator accepted but our response/database write failed.
            LockedWrite::run(fn () => $push->update(['status' => 'accepted']));
        });
    }

    public function recover(): void
    {
        Message::where('channel', 'SMS')->where('direction', 'INBOUND')->where('created_at', '>=', now()->subMinutes(10))
            ->orderBy('id')->limit(200)->pluck('id')->each(fn ($id) => $this->queueIncoming($id));
        GroundwireSmsPush::where('status', 'queued')->limit(100)->pluck('id')->each(fn ($id) => DeliverGroundwireSmsPush::dispatch($id));
        GroundwireSmsPush::where('created_at', '<', now()->subDays(90))->delete();
        \App\Models\GroundwireSmsSend::where('created_at', '<', now()->subDays(90))->delete();
    }
}
