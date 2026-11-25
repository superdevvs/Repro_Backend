<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Message;
use App\Models\Recruiting\RecruitApplicant;
use App\Services\Crm\CrmWebsiteIntakeReceipt;
use App\Services\Messaging\MessagingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;

/**
 * Producer replay (P2-04, P2-07): a freeze or a CRM switch-off must answer
 * website producers and Resend with a retryable 503, never a permanent 4xx or
 * an "ignored" acknowledgement, and must not lose a website contact.
 */
class ProducerIngressHoldTest extends CrmTestCase
{
    private const KEY = 'synthetic-producer-hold';

    private const SECRET = 'whsec_dGhyb3dhd2F5LXdlYmhvb2stc2VjcmV0'; // throwaway test secret

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.external_booking.api_key' => self::KEY, 'services.resend.webhook_secret' => self::SECRET]);
    }

    private function application(): array
    {
        return ['name' => 'Sample Applicant', 'email' => 'applicant@example.test', 'role' => 'photographer',
            'external_reference' => (string) Str::uuid()];
    }

    private function inquiry(string $reference): array
    {
        return ['type' => 'contact', 'reference' => $reference, 'contact' => [
            'first_name' => 'Casey', 'last_name' => 'Visitor', 'email' => 'visitor@example.test', 'phone' => '2025550184',
            'message' => 'Two listings next week', 'sms_consent' => false, 'created_at' => '2026-11-25T10:00:00Z',
        ]];
    }

    private function resend(array $payload, string $id)
    {
        $body = json_encode($payload);
        $ts = (string) now()->timestamp;
        $sig = base64_encode(hash_hmac('sha256', $id.'.'.$ts.'.'.$body, base64_decode(substr(self::SECRET, 6)), true));

        return $this->call('POST', '/api/webhooks/resend', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => $id, 'HTTP_SVIX_TIMESTAMP' => $ts, 'HTTP_SVIX_SIGNATURE' => 'v1,'.$sig,
        ], $body);
    }

    private function telemetryRows(): int
    {
        return DB::table('system_overview_route_events')->count() + DB::table('system_overview_request_traces')->count();
    }

    private function assertRetryable($response): void
    {
        $response->assertStatus(503);
        $this->assertTrue($response->headers->has('Retry-After'), 'A held producer must be told when to retry.');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function test_crm_switched_off_answers_the_careers_form_with_a_retryable_503(): void
    {
        config(['crm.enabled' => false]);

        $this->assertRetryable($this->postJson('/api/external/recruiting/application', $this->application(), ['X-API-Key' => self::KEY]));

        $this->assertSame(0, RecruitApplicant::query()->count());
        $this->assertSame(0, DB::table('recruit_application_receipts')->count());
    }

    public function test_crm_staff_routes_still_answer_404_when_switched_off(): void
    {
        config(['crm.enabled' => false]);
        $this->actAs($this->admin());

        $this->getJson('/api/crm/contacts')->assertNotFound();
    }

    public function test_website_contact_with_crm_off_keeps_a_receipt_that_is_replayed_once_after_the_switch(): void
    {
        config(['crm.enabled' => false]);
        $this->admin();
        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendEmail')->once()->andReturn(new Message(['id' => 7, 'status' => 'SENT']));
        $this->app->instance(MessagingService::class, $mock);
        $reference = (string) Str::uuid();

        $this->withHeader('X-API-Key', self::KEY)->postJson('/api/external/website-email', $this->inquiry($reference))
            ->assertOk()->assertJsonPath('outcome', 'delivered');

        $receipt = DB::table('crm_website_intake_receipts')->where('kind', 'inquiry')->sole();
        $this->assertSame($reference, $receipt->reference);
        $this->assertNull($receipt->completed_at);
        $this->assertSame(0, CrmContact::query()->count());

        config(['crm.enabled' => true]);
        app(CrmWebsiteIntakeReceipt::class)->retryPending();
        app(CrmWebsiteIntakeReceipt::class)->retryPending();

        $this->assertSame(1, CrmContact::query()->count());
        $this->assertNotNull(DB::table('crm_website_intake_receipts')->value('completed_at'));
    }

    public function test_resend_inbound_is_never_answered_ignored_while_crm_is_off(): void
    {
        config(['crm.enabled' => false]);

        $response = $this->resend(['type' => 'email.received', 'data' => ['email_id' => 'in-held',
            'to' => ['r+abcdefabcdef12@reply.reprophotos.com']]], 'msg-held-1');

        $this->assertRetryable($response);
        $this->assertNotSame('ignored', $response->json('status'));
        $this->assertSame(0, DB::table('crm_email_events')->count());
    }

    public function test_ingress_hold_answers_every_website_producer_and_resend_with_503_and_writes_nothing(): void
    {
        config(['producer_replay.ingress_hold' => true]);
        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldNotReceive('sendEmail');
        $this->app->instance(MessagingService::class, $mock);
        $headers = ['X-API-Key' => self::KEY];
        $before = $this->telemetryRows();

        $this->assertRetryable($this->postJson('/api/external/recruiting/application', $this->application(), $headers));
        $this->assertRetryable($this->postJson('/api/external/website-email', $this->inquiry((string) Str::uuid()), $headers));
        $this->assertRetryable($this->postJson('/api/external/book-shoot', ['external_reference' => (string) Str::uuid()], $headers));
        $this->assertRetryable($this->resend(['type' => 'email.received', 'data' => ['email_id' => 'in-1']], 'msg-hold-1'));
        $this->assertRetryable($this->resend(['type' => 'email.delivered', 'data' => ['email_id' => 're-1']], 'msg-hold-2'));

        $this->assertSame(0, DB::table('recruit_application_receipts')->count());
        $this->assertSame(0, DB::table('website_email_deliveries')->count());
        $this->assertSame(0, DB::table('crm_website_intake_receipts')->count());
        $this->assertSame(0, DB::table('external_booking_submissions')->count());
        $this->assertSame($before, $this->telemetryRows());
    }

    public function test_ingress_hold_is_off_by_default_and_leaves_other_routes_alone(): void
    {
        $this->assertFalse(config('producer_replay.ingress_hold'));
        config(['producer_replay.ingress_hold' => true]);

        $this->withHeader('X-API-Key', self::KEY)->getJson('/api/external/services')->assertOk();
        $this->getJson('/api/crm/contacts')->assertUnauthorized();
    }
}
