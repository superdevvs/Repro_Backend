<?php

namespace App\Services\Messaging;

use App\Models\SmsNumber;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** Dedicated group endpoint; never delegates to the individual SMS fan-out. */
class TelnyxSharedMmsGateway
{
    private function client(): PendingRequest
    {
        abort_unless(filled(config('services.telnyx.api_key')), 503, 'Group messaging is not configured.');

        return Http::baseUrl('https://api.telnyx.com/v2')->withToken((string) config('services.telnyx.api_key'))
            ->acceptJson()->asJson()->connectTimeout(5)->timeout(15)->withoutRedirecting();
    }

    private function read(string $path, array $query = []): array
    {
        $r = $this->client()->get($path, $query);
        abort_unless($r->successful() && strlen($r->body()) <= 262144 && is_array($r->json('data')), 503, 'Group messaging eligibility could not be verified.');

        return $r->json('data');
    }

    public function sender(SmsNumber $number): array
    {
        abort_unless($number->provider === 'TELNYX' && preg_match('/^\+1[2-9][0-9]{9}$/D', (string) $number->phone_number)
            && preg_match('/^[A-Za-z0-9-]{1,128}$/D', (string) $number->telnyx_phone_number_id), 422, 'Choose a verified group messaging number.');
        $id = rawurlencode($number->telnyx_phone_number_id);
        $phone = $this->read('/phone_numbers/'.$id);
        $settings = $this->read('/phone_numbers/'.$id.'/messaging');
        $profileID = (string) ($settings['messaging_profile_id'] ?? '');
        abort_unless(($phone['phone_number'] ?? '') === $number->phone_number && ($phone['status'] ?? '') === 'active'
            && in_array($phone['country_iso_alpha2'] ?? '', ['US', 'CA'], true)
            && ($phone['phone_number_type'] ?? '') === 'local'
            && ($settings['phone_number'] ?? '') === $number->phone_number
            && in_array($settings['country_code'] ?? '', ['US', 'CA'], true)
            && in_array($settings['type'] ?? '', ['long-code', 'longcode'], true)
            && data_get($settings, 'features.mms.domestic_two_way') === true
            && preg_match('/^[A-Za-z0-9-]{1,128}$/D', $profileID)
            && $profileID === $number->messaging_profile_id, 422, 'This business number is not verified for group MMS.');
        $profile = $this->read('/messaging_profiles/'.rawurlencode($profileID));
        abort_unless(($profile['enabled'] ?? false) === true && (string) ($profile['webhook_api_version'] ?? '') === '2'
            && ($profile['mms_fall_back_to_sms'] ?? true) === false
            && ($profile['webhook_url'] ?? '') === route('webhooks.telnyx.messaging')
            && str_starts_with((string) ($profile['webhook_url'] ?? ''), 'https://'), 422, 'Group MMS requires an enabled v2 messaging profile without SMS fallback.');

        return ['country' => $settings['country_code'], 'international_outbound' => data_get($settings, 'features.mms.international_outbound') === true,
            'destinations' => $profile['whitelisted_destinations'] ?? []];
    }

    public function recipients(array $numbers, array $sender): void
    {
        foreach ($numbers as $number) {
            $lookup = $this->read('/number_lookup/'.rawurlencode($number), ['type' => 'carrier']);
            $country = $lookup['country_code'] ?? '';
            if (($lookup['phone_number'] ?? '') !== $number || ($lookup['valid_number'] ?? false) !== true
                || ! in_array($country, ['US', 'CA'], true) || data_get($lookup, 'carrier.type') !== 'mobile'
                || ($country !== $sender['country'] && ! $sender['international_outbound'])
                || ! (in_array('*', $sender['destinations'], true) || in_array($country, $sender['destinations'], true))) {
                throw ValidationException::withMessages(['participants' => 'All participants must have supported US or Canadian mobile numbers.']);
            }
        }
    }

    public function send(string $from, array $to, string $text, string $id): array
    {
        // No retry: a lost response leaves a durable uncertain request. A signed
        // per-message webhook can reconcile it without issuing a second send.
        try {
            $r = $this->client()->timeout(20)->withHeaders(['Idempotency-Key' => $id])->post('/messages/group_mms', [
                'from' => $from, 'to' => $to, 'text' => $text, 'use_profile_webhooks' => true,
                'webhook_url' => route('webhooks.telnyx.shared-mms', ['message' => $id]),
            ]);
            if ($r->successful() && strlen($r->body()) <= 262144 && is_array($r->json('data'))
                && preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', (string) $r->json('data.id'))
                && ($r->json('data.group_message_id') === null || preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', (string) $r->json('data.group_message_id')))) {
                return ['status' => 'accepted', 'data' => $r->json('data')];
            }
            return ['status' => $r->clientError() && ! in_array($r->status(), [408, 409, 429], true) ? 'failed' : 'uncertain'];
        } catch (\Throwable) {
            return ['status' => 'uncertain'];
        }
    }
}
