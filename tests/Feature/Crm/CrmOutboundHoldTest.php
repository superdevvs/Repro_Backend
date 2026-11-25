<?php

namespace Tests\Feature\Crm;

use App\Exceptions\Messaging\EmailProviderRejectedException;
use App\Models\Crm\CrmCampaign;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmDripCampaign;
use App\Models\Crm\CrmDripEnrollment;
use App\Models\Crm\CrmEmailEvent;
use App\Models\Crm\CrmEmailSend;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\EmailSuppression;
use App\Models\User;
use App\Services\Crm\Email\CrmDripService;
use App\Services\Crm\Email\CrmEmailSender;
use App\Services\Crm\Email\CrmSendBlocked;
use App\Services\Crm\Email\CrmSignatureService;
use App\Services\Crm\Email\CrmTemplateService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\ResendProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Producer replay (P2-12): CRM_OUTBOUND_HOLD pauses drips, campaigns, the
 * digest and marketing sends, while replies, delivery events and opt-outs
 * keep being recorded. crm:outbound-release-check gates the release.
 */
class CrmOutboundHoldTest extends CrmTestCase
{
    private const SECRET = 'whsec_dGhyb3dhd2F5LXdlYmhvb2stc2VjcmV0'; // throwaway test secret

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.resend.key' => 're_test_key', 'services.resend.webhook_secret' => self::SECRET, 'crm.email.reply_tracking' => true]);
        $this->fakeAcceptedCrmMail();
    }

    protected function tearDown(): void
    {
        \App\Services\Messaging\OutboundDeliveryGuard::resetTestingOverrides();
        parent::tearDown();
    }

    private function webhook(array $payload, string $id)
    {
        $body = json_encode($payload);
        $ts = (string) now()->timestamp;
        $sig = base64_encode(hash_hmac('sha256', $id.'.'.$ts.'.'.$body, base64_decode(substr(self::SECRET, 6)), true));

        return $this->call('POST', '/api/webhooks/resend', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => $id, 'HTTP_SVIX_TIMESTAMP' => $ts, 'HTTP_SVIX_SIGNATURE' => 'v1,'.$sig,
        ], $body);
    }

    private function signedRep(): User
    {
        $rep = $this->rep(['email' => 'maya@reprophotos.com']);
        $sig = app(CrmSignatureService::class)->submit($rep, ['name' => 'Maya Torres', 'title' => 'Sales', 'sender_email' => 'maya@reprophotos.com']);
        app(CrmSignatureService::class)->approve($sig, $this->superAdmin());

        return $rep;
    }

    /** A contact enrolled in a published two-step drip whose first step has been sent. */
    private function enrolledAfterFirstStep(User $rep): array
    {
        $admin = $this->admin();
        $contact = $this->contactWithMarketingOptIn($rep, ['email' => 'dana@whitfield.com', 'name' => 'Dana Whitfield']);
        $t = app(CrmTemplateService::class)->create('Welcome', 'drip', ['subject' => 'Welcome', 'body_html' => '<p>Hi</p>'], $admin);
        app(CrmTemplateService::class)->approve(app(CrmTemplateService::class)->submit($t, $admin), $admin);
        $drip = CrmDripCampaign::query()->where('length_key', '12_weeks')->firstOrFail();
        app(CrmDripService::class)->saveSteps($drip, [['send_day' => 0, 'template_id' => $t->id], ['send_day' => 7, 'template_id' => $t->id]], $admin);
        app(CrmDripService::class)->publish($drip, $admin);
        app(CrmDripService::class)->enrol($contact, $drip->fresh(), $rep);
        $this->artisan('crm:send-due')->assertSuccessful();

        return [$contact, CrmEmailSend::query()->where('source_type', 'drip')->sole()];
    }

    public function test_hold_is_off_by_default(): void
    {
        $this->assertFalse(config('crm.outbound_hold'));
    }

    public function test_held_drip_sends_nothing_while_replies_and_bounces_are_still_recorded(): void
    {
        $rep = $this->signedRep();
        [$contact, $first] = $this->enrolledAfterFirstStep($rep);
        $enrollment = CrmDripEnrollment::query()->sole();
        $enrollment->forceFill(['next_send_at' => now()->subMinute()])->save();

        config(['crm.outbound_hold' => true]);
        $this->artisan('crm:send-due')->expectsOutputToContain('outbound is held')->assertSuccessful();
        $this->assertSame(1, CrmEmailSend::query()->where('source_type', 'drip')->count());
        $this->assertNull($enrollment->fresh()->claimed_at);
        $this->assertSame(['sent' => 0, 'skipped' => 0, 'stopped' => 0, 'failed' => 0], app(CrmDripService::class)->runDue());

        Http::fake(['api.resend.com/emails/receiving/*' => Http::response(['text' => 'Call me Thursday'])]);
        config(['crm.email.defer_reply_forwarding' => true]);
        $this->webhook(['type' => 'email.received', 'data' => ['email_id' => 'in_hold', 'from' => 'dana@whitfield.com',
            'to' => ['r+'.$first->reply_token.'@reply.reprophotos.com'], 'subject' => 'Re: Welcome']], 'msg_hold_reply')
            ->assertOk()->assertJsonPath('status', 'ok');
        $this->assertSame('reply', $enrollment->fresh()->stop_reason);

        $this->webhook(['type' => 'email.bounced', 'data' => ['email_id' => $first->provider_message_id,
            'bounce' => ['type' => 'Permanent', 'message' => 'No such user']]], 'msg_hold_bounce')->assertOk();
        $this->assertTrue(EmailSuppression::query()->where('email_normalized', 'dana@whitfield.com')->exists());
    }

    public function test_held_campaigns_stay_scheduled_and_marketing_sends_are_refused(): void
    {
        $rep = $this->signedRep();
        $contact = $this->contactWithMarketingOptIn($rep, ['email' => 'pat@example.test']);
        config(['crm.outbound_hold' => true]);
        $campaign = CrmCampaign::query()->forceCreate(['name' => 'Spring', 'owner_id' => $rep->id, 'status' => 'scheduled',
            'template_id' => 1, 'template_version_id' => 1, 'audience' => [],
            'scheduled_at' => now()->subMinute(), 'subject' => 'Hello', 'body_html' => '<p>Hello</p>']);

        $this->artisan('crm:send-due')->assertSuccessful();
        $this->assertSame(['campaigns' => 0, 'sent' => 0, 'skipped' => 0], app(\App\Services\Crm\Email\CrmCampaignService::class)->runDue());
        $this->assertSame('scheduled', $campaign->fresh()->status);

        try {
            app(CrmEmailSender::class)->send(['contact' => $contact, 'sender' => $rep, 'source_type' => 'one_off', 'marketing' => true,
                'content' => ['subject' => 'Hi', 'body_html' => '<p>Hi</p>'], 'idempotency_key' => 'held-marketing']);
            $this->fail('A marketing send must be refused while outbound is held.');
        } catch (CrmSendBlocked $e) {
            $this->assertSame('outbound_hold', $e->reason);
        }
        $this->assertSame(0, CrmEmailSend::query()->count());

        $plain = app(CrmEmailSender::class)->send(['contact' => $contact, 'sender' => $rep, 'source_type' => 'one_off', 'marketing' => false,
            'content' => ['subject' => 'Your shoot', 'body_html' => '<p>Booked</p>'], 'idempotency_key' => 'held-transactional']);
        $this->assertSame('sent', $plain->status);
    }

    public function test_held_follow_up_digest_sends_nothing(): void
    {
        $rep = $this->rep(['email' => 'rep@example.test']);
        $contact = $this->contactFor($rep);
        CrmEvent::query()->forceCreate(['contact_id' => $contact->id, 'assigned_user_id' => $rep->id,
            'type_id' => (int) DB::table('crm_event_types')->value('id'), 'status' => 'planned', 'starts_at' => now()->subHour()]);
        config(['crm.outbound_hold' => true]);

        $this->artisan('crm:follow-up-digest')->expectsOutputToContain('outbound is held')->assertSuccessful();
        $this->assertSame(['sent' => 0, 'skipped' => 0], app(\App\Services\Crm\CrmFollowUpDigest::class)->run());
        $this->assertSame(0, CrmEmailSend::query()->count());
    }

    public function test_release_check_fails_until_an_uncertain_send_has_a_recorded_disposition(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep, ['email' => 'lee@example.test']);
        $since = now()->subHour()->toIso8601String();
        $send = CrmEmailSend::create(['contact_id' => $contact->id, 'sender_user_id' => $rep->id, 'source_type' => 'drip',
            'from_email' => 'rep@reprophotos.com', 'to_email' => 'lee@example.test', 'subject' => 'Hi', 'html' => 'x', 'text' => 'x',
            'transport' => 'resend', 'status' => 'uncertain']);

        $this->assertSame(1, Artisan::call('crm:outbound-release-check', ['--since' => $since, '--json' => true]));
        $report = json_decode(Artisan::output(), true);
        $this->assertSame(1, $report['blocking']['uncertain_sends_without_disposition']);
        $this->assertFalse($report['ready']);

        $this->artisan('crm:send-disposition', ['send' => $send->id, 'disposition' => 'not_delivered', '--note' => 'Not in the Resend log'])
            ->assertSuccessful();
        $this->assertSame(0, Artisan::call('crm:outbound-release-check', ['--since' => $since, '--json' => true]));
        $this->assertTrue(json_decode(Artisan::output(), true)['ready']);
        $this->assertSame('uncertain', $send->fresh()->status);
    }

    public function test_release_check_counts_unforwarded_replies_unsuppressed_bounces_and_due_drips_that_should_stop(): void
    {
        $rep = $this->signedRep();
        [$contact, $first] = $this->enrolledAfterFirstStep($rep);
        $since = now()->subMinutes(5)->toIso8601String();
        CrmDripEnrollment::query()->update(['next_send_at' => now()->subMinute()]);
        CrmEmailEvent::query()->create(['send_id' => $first->id, 'provider_event_id' => 'msg_r1', 'type' => 'replied',
            'occurred_at' => now(), 'payload' => ['email_id' => 'in-x', 'text' => 'Yes']]);
        CrmEmailEvent::query()->create(['send_id' => $first->id, 'provider_event_id' => 'msg_b1', 'type' => 'bounced',
            'occurred_at' => now(), 'payload' => ['bounce' => ['type' => 'Permanent']]]);
        $first->forceFill(['replied_at' => now()])->save();

        $this->assertSame(1, Artisan::call('crm:outbound-release-check', ['--since' => $since, '--json' => true]));
        $report = json_decode(Artisan::output(), true);
        $this->assertSame(1, $report['blocking']['replies_not_forwarded']);
        $this->assertSame(1, $report['blocking']['permanent_bounces_or_complaints_not_suppressed']);
        $this->assertSame(1, $report['blocking']['due_enrolments_that_should_stop']);
        $this->assertSame(1, $report['info']['due_enrolments']);
    }

    /**
     * Only a forward Resend accepted puts a reply in the rep's inbox. A forward
     * that was blocked, rejected or only logged keeps the reply blocking until
     * a person records its disposition; a reply that could not be forwarded at
     * all (no rep to send it to) is reviewed on the send it answered.
     */
    public function test_a_reply_blocks_until_its_forward_is_accepted_or_reviewed(): void
    {
        $rep = $this->signedRep();
        $contact = $this->contactFor($rep, ['email' => 'kim@example.test']);
        $since = now()->subMinute()->toIso8601String();
        Http::fake(['api.resend.com/emails/receiving/*' => Http::response(['text' => 'Thursday works'])]);
        $reply = function (string $token, ?CrmContact $contact, ?User $sender): array {
            $send = CrmEmailSend::create(['contact_id' => $contact?->id, 'sender_user_id' => $sender?->id, 'source_type' => 'one_off',
                'from_email' => 'maya@reprophotos.com', 'to_email' => 'kim@example.test', 'subject' => 'Hello', 'html' => 'x', 'text' => 'x',
                'transport' => 'resend', 'status' => 'sent', 'provider_message_id' => 're-'.$token, 'reply_token' => $token]);
            $this->webhook(['type' => 'email.received', 'data' => ['email_id' => 'in-'.$token, 'from' => 'kim@example.test',
                'to' => ['r+'.$token.'@reply.reprophotos.com'], 'subject' => 'Re: Hello']], 'msg-'.$token)->assertOk();

            return [$send, CrmEmailSend::query()->where('source_type', 'reply_fwd')->where('source_id', $send->id)->first()];
        };
        [, $accepted] = $reply('acceptedtoken0001', $contact, $rep);
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting(false);
        [, $blocked] = $reply('blockedtoken00001', $contact, $rep);
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        config(['crm.email.transport' => 'log']);
        [, $logged] = $reply('loggedtoken000001', $contact, $rep);
        config(['crm.email.transport' => 'resend']);
        $this->mock(ResendProvider::class)->shouldReceive('send')->andThrow(new EmailProviderRejectedException('Recipient rejected'));
        [, $rejected] = $reply('rejectedtoken0001', $contact, $rep);
        [$noRep, $none] = $reply('noreptoken0000001', null, null);
        $this->assertSame(['sent', 'blocked', 'logged', 'failed', null],
            [$accepted->status, $blocked->status, $logged->status, $rejected->status, $none?->status]);
        $check = function () use ($since): array {
            $exit = Artisan::call('crm:outbound-release-check', ['--since' => $since, '--json' => true]);

            return [$exit, json_decode(Artisan::output(), true)['blocking']['replies_not_forwarded']];
        };

        $this->assertSame([1, 4], $check());

        foreach ([$blocked, $logged, $rejected, $noRep] as $send) {
            $this->artisan('crm:send-disposition', ['send' => $send->id, 'disposition' => 'not_delivered', '--note' => 'Rep read it in Conversations'])
                ->assertSuccessful();
        }
        $this->assertSame([0, 0], $check());
    }

    public function test_release_check_is_read_only(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep, ['email' => 'ro@example.test']);
        CrmEmailSend::create(['contact_id' => $contact->id, 'sender_user_id' => $rep->id, 'source_type' => 'drip',
            'from_email' => 'rep@reprophotos.com', 'to_email' => 'ro@example.test', 'subject' => 'Hi', 'html' => 'x', 'text' => 'x',
            'transport' => 'resend', 'status' => 'queued']);
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        Artisan::call('crm:outbound-release-check', ['--since' => now()->subDay()->toIso8601String(), '--json' => true]);

        $this->assertSame([], $writes);
    }
}
