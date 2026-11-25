<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmEmailEvent;
use App\Models\Crm\CrmEmailSend;
use App\Models\Crm\EmailSuppression;
use Illuminate\Support\Facades\Http;

/**
 * Producer replay (P2-07): Resend is held at ingress during the freeze and
 * afterwards redelivers each event with its original svix-id, re-signed with
 * a fresh timestamp. Each original event must take effect exactly once.
 */
class ResendRedeliveryTest extends CrmTestCase
{
    private const SECRET = 'whsec_dGhyb3dhd2F5LXdlYmhvb2stc2VjcmV0'; // throwaway test secret

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.resend.key' => 'test-sending-only', 'services.resend.webhook_secret' => self::SECRET,
            'crm.email.reply_tracking' => true, 'crm.email.receiving_key' => 'test-receiving-only']);
        $this->fakeAcceptedCrmMail();
        $this->app->instance(\App\Services\Messaging\Providers\ResendProvider::class, new \App\Services\Messaging\Providers\ResendProvider);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        \App\Services\Messaging\OutboundDeliveryGuard::resetTestingOverrides();
        parent::tearDown();
    }

    /** Sign exactly as Svix does: id.timestamp.body under the endpoint secret. */
    private function deliver(array $payload, string $svixId, ?int $signedAt = null)
    {
        $body = json_encode($payload);
        $ts = (string) ($signedAt ?? now()->timestamp);
        $sig = base64_encode(hash_hmac('sha256', $svixId.'.'.$ts.'.'.$body, base64_decode(substr(self::SECRET, 6)), true));

        return $this->call('POST', '/api/webhooks/resend', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => $svixId, 'HTTP_SVIX_TIMESTAMP' => $ts, 'HTTP_SVIX_SIGNATURE' => 'v1,'.$sig,
        ], $body);
    }

    private function sentCrmEmail(string $token): CrmEmailSend
    {
        $rep = $this->rep(['email' => 'rep@example.test']);
        $contact = $this->contactFor($rep, ['email' => 'client@example.test']);

        return CrmEmailSend::create(['contact_id' => $contact->id, 'sender_user_id' => $rep->id, 'source_type' => 'one_off',
            'from_email' => 'rep@reprophotos.com', 'to_email' => 'client@example.test', 'subject' => 'Hello', 'html' => 'Hello',
            'text' => 'Hello', 'transport' => 'resend', 'status' => 'sent', 'reply_token' => $token, 'provider_message_id' => 're-sent-1']);
    }

    public function test_held_reply_is_recorded_and_forwarded_once_across_fresh_signed_redeliveries(): void
    {
        Http::fake([
            'api.resend.com/emails/receiving/in-held' => Http::response(['text' => 'Thursday works']),
            'api.resend.com/emails' => Http::response(['id' => 'test-forward']),
        ]);
        $send = $this->sentCrmEmail('heldreplytoken12');
        $payload = ['type' => 'email.received', 'data' => ['email_id' => 'in-held', 'from' => 'client@example.test',
            'to' => ['r+heldreplytoken12@reply.reprophotos.com'], 'subject' => 'Re: Hello']];
        $originalAt = now()->timestamp;

        config(['producer_replay.ingress_hold' => true]);
        $this->deliver($payload, 'msg_original_1', $originalAt)->assertStatus(503)->assertHeader('Retry-After');
        $this->assertSame(0, CrmEmailEvent::query()->count());
        Http::assertNothingSent();

        // Release after the freeze: the old signature is stale, a fresh one is accepted once.
        config(['producer_replay.ingress_hold' => false]);
        $this->travel(40)->minutes();
        $this->deliver($payload, 'msg_original_1', $originalAt)->assertUnauthorized();
        $this->deliver($payload, 'msg_original_1')->assertOk()->assertJsonPath('status', 'ok');
        $this->travel(7)->minutes();
        $this->deliver($payload, 'msg_original_1')->assertOk()->assertJsonPath('status', 'ok');

        $this->assertSame(1, CrmEmailEvent::query()->where('provider_event_id', 'msg_original_1')->count());
        $this->assertSame(1, CrmEmailSend::query()->where('source_type', 'reply_fwd')->count());
        $this->assertNotNull($send->fresh()->replied_at);
        Http::assertSentCount(2);
    }

    public function test_crm_delivery_event_redelivered_with_its_original_svix_id_applies_once(): void
    {
        $send = $this->sentCrmEmail('bouncetoken1234');
        $payload = ['type' => 'email.bounced', 'data' => ['email_id' => 're-sent-1',
            'bounce' => ['type' => 'Permanent', 'message' => 'No such user']]];

        $this->deliver($payload, 'msg_bounce_1')->assertOk();
        $this->travel(2)->hours();
        $this->deliver($payload, 'msg_bounce_1')->assertOk();

        $this->assertSame(1, CrmEmailEvent::query()->where('provider_event_id', 'msg_bounce_1')->count());
        $this->assertSame('bounced', $send->fresh()->status);
        $this->assertSame(1, EmailSuppression::query()->where('email_normalized', 'client@example.test')->count());
    }
}
