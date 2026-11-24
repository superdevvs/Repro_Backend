<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmDripCampaign;
use App\Models\Crm\CrmDripEnrollment;
use App\Models\Crm\CrmEmailSend;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmNotice;
use App\Models\Crm\EmailSuppression;
use App\Models\GoogleCalendarConnection;
use App\Models\Recruiting\RecruitApplicant;
use App\Models\User;
use App\Services\Crm\Calendar\CrmCalendarSync;
use App\Services\Crm\CrmEventService;
use App\Services\Crm\CrmWebsiteIntake;
use App\Services\Crm\Email\CrmDripService;
use App\Services\Crm\Email\CrmEmailSender;
use App\Services\Crm\Email\CrmSignatureService;
use App\Services\Crm\Email\CrmTemplateService;
use Illuminate\Support\Facades\Http;

/** The final-stage integrations, with every outside service faked. */
class CrmIntegrationsTest extends CrmTestCase
{
    private const SECRET = 'whsec_dGhyb3dhd2F5LXdlYmhvb2stc2VjcmV0'; // throwaway test secret

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.resend.key' => 're_test_key', 'services.resend.webhook_secret' => self::SECRET, 'crm.email.reply_tracking' => true]);
    }

    protected function tearDown(): void
    {
        \App\Services\Messaging\OutboundDeliveryGuard::resetTestingOverrides();
        parent::tearDown();
    }

    private function signedRep(): User
    {
        $rep = $this->rep(['email' => 'maya@reprophotos.com']);
        $sig = app(CrmSignatureService::class)->submit($rep, ['name' => 'Maya Torres', 'title' => 'Sales', 'sender_email' => 'maya@reprophotos.com']);
        app(CrmSignatureService::class)->approve($sig, $this->superAdmin());

        return $rep;
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

    public function test_website_inquiry_lands_in_the_unassigned_queue_once(): void
    {
        $admin = $this->admin();
        $intake = app(CrmWebsiteIntake::class);
        $form = ['first_name' => 'Tamara', 'last_name' => 'Okafor', 'email' => 'tamara@okafor.com', 'phone' => '301-555-0101', 'message' => 'Three listings in March', 'sms_consent' => true];

        $contact = $intake->inquiry($form, '2b0f9f1e-3c1d-4a37-9a3e-2f2f8d0c1a11');
        $this->assertNull($contact->assigned_rep_id);
        $this->assertSame(['inquiry', 'website', true], [$contact->stage, $contact->lead_source, (bool) $contact->sms_consent]);
        $this->assertNotNull($contact->first_contact_due_at);
        $this->assertSame(1, CrmNotice::query()->where('user_id', $admin->id)->count());
        $this->assertNull($intake->inquiry($form, '2B0F9F1E-3C1D-4A37-9A3E-2F2F8D0C1A11'));
        $this->assertSame(1, CrmContact::query()->count());

        // The same person writing again goes onto their record and their rep hears about it.
        $rep = $this->rep();
        app(\App\Services\Crm\CrmAssignmentService::class)->assign($contact, $rep->id, $admin);
        $intake->inquiry($form + [], 'c3d1a7b2-0d6e-4f7a-8b9c-1e2f3a4b5c6d');
        $this->assertSame(1, CrmContact::query()->count());
        $this->assertSame(1, CrmNotice::query()->where('user_id', $rep->id)->where('type', 'website_inquiry')->count());
    }

    public function test_crm_email_goes_through_resend_with_unsubscribe_headers(): void
    {
        config(['crm.email.transport' => 'resend']);
        \App\Services\Messaging\OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        Http::fake(['api.resend.com/emails' => Http::response(['id' => 're_123'], 200)]);
        // Tests normally get a recording fake; use the real provider so the HTTP request itself is checked.
        $this->app->instance(\App\Services\Messaging\Providers\ResendProvider::class, new \App\Services\Messaging\Providers\ResendProvider);
        $rep = $this->signedRep();
        $contact = $this->contactFor($rep, ['email' => 'dana@whitfield.com']);

        $send = app(CrmEmailSender::class)->send(['contact' => $contact, 'sender' => $rep, 'source_type' => 'one_off', 'content' => ['subject' => 'Hi', 'body_html' => '<p>Hi {{first_name}}</p>'], 'marketing' => true, 'idempotency_key' => 'k-1']);

        $this->assertSame(['sent', 're_123'], [$send->status, $send->provider_message_id]);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.resend.com/emails'
                && $request->hasHeader('Idempotency-Key', 'k-1')
                && str_contains($body['headers']['List-Unsubscribe'] ?? '', '/one-click')
                && ($body['headers']['List-Unsubscribe-Post'] ?? '') === 'List-Unsubscribe=One-Click'
                && str_starts_with((string) $body['reply_to'], 'r+')
                && str_contains($body['from'], 'maya@reprophotos.com');
        });
    }

    public function test_resend_events_update_the_send_and_a_bounce_stops_the_drip(): void
    {
        $rep = $this->signedRep();
        $contact = $this->contactFor($rep, ['email' => 'dana@whitfield.com']);
        $send = CrmEmailSend::create(['contact_id' => $contact->id, 'sender_user_id' => $rep->id, 'source_type' => 'one_off', 'from_email' => 'maya@reprophotos.com', 'to_email' => 'dana@whitfield.com', 'subject' => 'Hi', 'html' => '<p>x</p>', 'text' => 'x', 'transport' => 'resend', 'status' => 'sent', 'provider_message_id' => 're_9']);

        $this->webhook(['type' => 'email.opened', 'created_at' => now()->toIso8601String(), 'data' => ['email_id' => 're_9']], 'msg_1')->assertOk();
        $this->webhook(['type' => 'email.opened', 'created_at' => now()->toIso8601String(), 'data' => ['email_id' => 're_9']], 'msg_1')->assertOk();
        $this->assertSame(1, $send->fresh()->open_count);

        $this->webhook(['type' => 'email.bounced', 'data' => ['email_id' => 're_9', 'bounce' => ['type' => 'Permanent', 'message' => 'No such user']]], 'msg_2')->assertOk();
        $this->assertSame('bounced', $send->fresh()->status);
        $this->assertTrue(EmailSuppression::query()->where('email_normalized', 'dana@whitfield.com')->exists());
        $this->assertSame('bounced', $contact->fresh()->email_status);
    }

    public function test_a_reply_stops_the_drip_logs_it_and_reaches_the_rep(): void
    {
        $rep = $this->signedRep();
        $admin = User::query()->where('role', 'admin')->first() ?? $this->admin();
        $contact = $this->contactFor($rep, ['email' => 'dana@whitfield.com', 'name' => 'Dana Whitfield']);
        $t = app(CrmTemplateService::class)->create('Welcome', 'drip', ['subject' => 'Welcome', 'body_html' => '<p>Hi</p>'], $admin);
        app(CrmTemplateService::class)->approve(app(CrmTemplateService::class)->submit($t, $admin), $admin);
        $drip = CrmDripCampaign::query()->where('length_key', '12_weeks')->firstOrFail();
        app(CrmDripService::class)->saveSteps($drip, [['send_day' => 0, 'template_id' => $t->id], ['send_day' => 7, 'template_id' => $t->id]], $admin);
        app(CrmDripService::class)->publish($drip, $admin);
        app(CrmDripService::class)->enrol($contact, $drip->fresh(), $rep);
        $this->artisan('crm:send-due');
        $send = CrmEmailSend::query()->where('source_type', 'drip')->firstOrFail();
        Http::fake(['api.resend.com/emails/receiving/*' => Http::response(['text' => "Yes, let's talk Thursday.\n\nOn Mon, Maya wrote:\n> Hi"], 200)]);

        $this->webhook(['type' => 'email.received', 'data' => ['email_id' => 'in_1', 'from' => 'dana@whitfield.com', 'to' => ['r+'.$send->reply_token.'@reply.reprophotos.com'], 'subject' => 'Re: Welcome']], 'msg_in_1')
            ->assertOk()->assertJsonPath('status', 'ok');

        $this->assertNotNull($send->fresh()->replied_at);
        $this->assertSame('reply', CrmDripEnrollment::query()->value('stop_reason'));
        $this->assertSame(1, CrmNotice::query()->where('user_id', $rep->id)->where('type', 'email_reply')->count());
        $forward = CrmEmailSend::query()->where('source_type', 'reply_fwd')->firstOrFail();
        $this->assertSame('maya@reprophotos.com', $forward->to_email);
        $this->assertStringContainsString('Thursday', $forward->html);
        $this->webhook(['type' => 'email.received', 'data' => ['to' => ['someone@reprophotos.com']]], 'msg_in_2')->assertJsonPath('status', 'ignored');
    }

    public function test_follow_ups_sync_both_ways_with_google_calendar(): void
    {
        config(['crm.google_sync.enabled' => true]);
        $rep = $this->rep();
        GoogleCalendarConnection::create(['user_id' => $rep->id, 'provider_email' => 'maya@gmail.com', 'calendar_id' => 'primary', 'access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'token_expires_at' => now()->addHour(), 'sync_enabled' => true]);
        $moved = now()->addDays(2)->setTime(15, 0)->utc();
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'g_1'], 200),
            'www.googleapis.com/calendar/v3/calendars/primary/events/g_1' => Http::response(['id' => 'g_1', 'status' => 'confirmed', 'start' => ['dateTime' => $moved->toRfc3339String()], 'end' => ['dateTime' => $moved->copy()->addMinutes(30)->toRfc3339String()]], 200),
        ]);
        $contact = $this->contactFor($rep, ['name' => 'Dana Whitfield']);

        $event = app(CrmEventService::class)->schedule($contact, ['type' => 'call', 'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String()], $rep);
        $this->assertSame('g_1', \Illuminate\Support\Facades\DB::table('crm_event_calendar_links')->where('event_id', $event->id)->value('google_event_id'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r['summary'] ?? '', 'Dana Whitfield'));

        $result = app(CrmCalendarSync::class)->pollAll();
        $this->assertSame(1, $result['moved']);
        $this->assertTrue($event->fresh()->starts_at->equalTo($moved));
        $this->assertSame(30, $event->fresh()->duration_min);

        $this->artisan('crm:calendar-sync')->assertSuccessful();
        $this->assertSame('planned', CrmEvent::query()->find($event->id)->status);
    }

    public function test_the_website_careers_form_posts_with_the_api_key(): void
    {
        config(['services.external_booking.api_key' => 'test-external-key']);
        $this->postJson('/api/external/recruiting/application', ['name' => 'Ava Chen', 'email' => 'ava@example.com', 'role' => 'photographer'])->assertUnauthorized();

        $this->postJson('/api/external/recruiting/application', ['name' => 'Ava Chen', 'email' => 'ava@example.com', 'role' => 'photographer', 'external_reference' => 'lovable-77'], ['X-API-Key' => 'test-external-key'])
            ->assertCreated()->assertJsonPath('stage', 'applied');
        $this->postJson('/api/external/recruiting/application', ['name' => 'Ava Chen', 'email' => 'ava@example.com', 'role' => 'photographer', 'external_reference' => 'lovable-77'], ['X-API-Key' => 'test-external-key'])
            ->assertCreated();
        $this->assertSame(1, RecruitApplicant::query()->count());
        $this->assertSame('website', RecruitApplicant::query()->value('source'));
    }
}
