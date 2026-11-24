<?php

namespace Tests\Feature\Crm;

use App\Models\Message;
use App\Models\SmsNumber;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CrmSmsTest extends CrmTestCase
{
    public function test_consent_binds_to_the_phone_and_one_daytime_send_reaches_the_test_provider_once(): void
    {
        Carbon::setTestNow('2026-11-24 18:00:00 UTC');
        config(['crm.sms.enabled' => true]);
        $rep = $this->actAs($this->rep());
        $contact = $this->contactFor($rep, ['phone' => '301-555-0188']);
        $url = "/api/crm/contacts/{$contact->id}";
        $input = ['body' => 'Requested follow-up', 'request_id' => (string) Str::uuid()];
        SmsNumber::create(['phone_number' => '+12025550180', 'provider' => 'TELNYX', 'is_default' => true]);
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        $this->postJson($url.'/sms-consent', ['consent' => true, 'source' => 'written'])->assertOk();
        $id = $this->postJson($url.'/conversation/sms', $input)->assertOk()->assertJsonPath('status', 'sent')->json('message_id');
        $this->postJson($url.'/conversation/sms', $input)->assertOk()->assertJsonPath('message_id', $id);
        $this->assertCount(1, \App\Services\Messaging\Providers\FakeSmsProvider::sent());
        $this->patchJson($url, ['phone' => '301-555-0177', 'sms_consent' => true])->assertOk();
        $this->getJson($url.'/conversation')->assertJsonPath('sms.consent', false);
        $this->postJson($url.'/conversation/sms', [...$input, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_consent_and_release_state_are_checked_and_deferred_text_is_cancelled_on_revocation(): void
    {
        Carbon::setTestNow('2026-11-24 10:00:00 UTC');
        $rep = $this->actAs($this->rep());
        $contact = $this->contactFor($rep, ['phone' => '301-555-0189']);
        $url = "/api/crm/contacts/{$contact->id}";
        $input = ['body' => 'Your requested follow-up', 'request_id' => (string) Str::uuid(), 'timezone' => 'America/New_York'];
        $this->postJson($url.'/conversation/sms', $input)->assertConflict();
        config(['crm.sms.enabled' => true]);
        $this->postJson($url.'/conversation/sms', $input)->assertUnprocessable();
        $this->postJson($url.'/sms-consent', ['consent' => true, 'source' => 'written'])->assertOk();
        SmsNumber::create(['phone_number' => '+12025550180', 'provider' => 'TELNYX', 'is_default' => true]);
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        $id = $this->postJson($url.'/conversation/sms', $input)->assertOk()->assertJsonPath('status', 'scheduled')->json('message_id');
        $this->postJson($url.'/conversation/sms', $input)->assertOk()->assertJsonPath('message_id', $id);
        $this->postJson($url.'/conversation/sms', [...$input, 'body' => 'different'])->assertConflict();
        $this->assertDatabaseCount('crm_sms_requests', 1);
        $this->postJson($url.'/sms-consent', ['consent' => false])->assertOk();
        Carbon::setTestNow('2026-11-24 16:00:00 UTC');
        $this->assertSame('CANCELLED', app(MessagingService::class)->dispatchStoredSmsMessage(Message::findOrFail($id))->status);
        $this->actAs($this->rep());
        $this->postJson($url.'/conversation/sms', $input)->assertNotFound();
    }
}
