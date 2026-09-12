<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\EditorPayoutController as AdminEditorPayoutController;
use App\Http\Controllers\EditorPayoutController;
use App\Http\Controllers\PayoutReportController;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\EditorPayoutService;
use App\Services\MailService;
use App\Services\Messaging\MessagingService;
use App\Services\PayoutReportService;
use App\Services\SystemEmails\DirectEmailTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailAtelierDirectFlowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        config(['mail.editing_team_address' => 'editing@example.test', 'mail.accounting_address' => 'accounting@example.test']);
    }

    public static function contactCases(): array
    {
        return ['both enabled' => [null], 'owner disabled' => ['contact-notification'], 'sender disabled' => ['contact-confirmation']];
    }

    #[DataProvider('contactCases')]
    public function test_public_contact_submission_uses_saved_copy_and_preserves_independent_recipient_routing(?string $disabled): void
    {
        $this->edit('contact-notification', 'contact_inquiry_html');
        $this->edit('contact-confirmation', 'contact_confirmation_html');
        $this->disable($disabled);
        $owner = User::factory()->create(['name' => 'Portfolio Owner', 'username' => 'atelier-owner', 'email' => 'owner@example.test']);
        $sent = $this->capture($disabled ? 1 : 2);

        $this->postJson('/api/public/client/atelier-owner/contact', [
            'name' => 'Casey Visitor', 'email' => 'visitor@example.test', 'phone' => '2025550184',
            'message' => 'Please photograph my distinctive blue entryway.',
        ])->assertCreated();

        $this->assertDatabaseHas('contact_submissions', ['client_id' => $owner->id, 'sender_email' => 'visitor@example.test']);
        $this->assertCount($disabled ? 1 : 2, $sent->items);
        foreach ($sent->items as $payload) {
            $slug = $payload['to'] === $owner->email ? 'contact-notification' : 'contact-confirmation';
            $this->assertNotSame($disabled, $slug);
            $this->assertSame($slug === 'contact-notification' ? 'owner@example.test' : 'visitor@example.test', $payload['to']);
            $this->assertEdited($payload, $slug, 'Please photograph my distinctive blue entryway.');
            $this->assertStringContainsString('data-artwork="'.str_replace('-', '_', $slug).'"', $payload['body_html']);
        }
        $this->assertNoOutbound();
    }

    public static function mailServiceCases(): array
    {
        return [
            'terms enabled' => ['terms-accepted', 'terms_details_html', false],
            'terms disabled' => ['terms-accepted', 'terms_details_html', true],
            'weekly report enabled' => ['weekly-sales-report', 'weekly_sales_report_html', false],
            'weekly report disabled' => ['weekly-sales-report', 'weekly_sales_report_html', true],
        ];
    }

    #[DataProvider('mailServiceCases')]
    public function test_public_mail_service_preserves_saved_copy_and_live_terms_or_report(string $slug, string $token, bool $disabled): void
    {
        $this->edit($slug, $token);
        $this->disable($disabled ? $slug : null);
        $user = User::factory()->create(['name' => 'Scoped Recipient', 'email' => 'scoped@example.test', 'role' => $slug === 'terms-accepted' ? 'client' : 'salesRep']);
        $sent = $this->capture($disabled ? 0 : 1);

        $result = $slug === 'terms-accepted'
            ? app(MailService::class)->sendTermsAcceptedEmail($user)
            : app(MailService::class)->sendWeeklySalesReportEmail($user, $this->weeklyReport());

        $this->assertSame(! $disabled, $result);
        if (! $disabled) {
            $payload = $sent->items[0];
            $this->assertSame($user->email, $payload['to']);
            $this->assertEdited($payload, $slug, $slug === 'terms-accepted' ? 'Payment is due in full at the time of booking.' : 'Distinctive Client Brokerage');
            if ($slug === 'terms-accepted') {
                $this->assertSame($user->id, $payload['related_account_id']);
                $this->assertSame('TERMS_ACCEPTED', $payload['send_source']);
            } else {
                $this->assertStringContainsString('$8,642.00', $payload['body_html']);
            }
        }
        $this->assertNoOutbound();
    }

    public static function enabledCases(): array
    {
        return ['enabled' => [false], 'disabled' => [true]];
    }

    #[DataProvider('enabledCases')]
    public function test_real_editing_request_endpoint_preserves_request_details_and_respects_disabled_mail(bool $disabled): void
    {
        $this->edit('editing-request', 'editing_request_html');
        $this->disable($disabled ? 'editing-request' : null);
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin', 'name' => 'Editing Requester']));
        $sent = $this->capture($disabled ? 0 : 1);

        $response = $this->postJson('/api/editing-requests', [
            'summary' => 'Remove the blue garden hose', 'details' => 'Keep the stone texture and original crop.',
            'priority' => 'high', 'target_team' => 'editor',
        ])->assertCreated();

        $this->assertDatabaseHas('editing_requests', ['summary' => 'Remove the blue garden hose']);
        if (! $disabled) {
            $payload = $sent->items[0];
            $this->assertSame('editing@example.test', $payload['to']);
            $this->assertEdited($payload, 'editing-request', 'Remove the blue garden hose');
            $this->assertStringContainsString('Keep the stone texture and original crop.', $payload['body_html']);
            $this->assertStringContainsString($response->json('data.tracking_code'), $payload['body_html']);
            $this->assertStringContainsString('data-artwork="editing"', $payload['body_html']);
        }
        $this->assertNoOutbound();
    }

    public static function payoutCommandCases(): array
    {
        return ['all enabled' => [null], 'individual reports disabled' => ['payout-report'], 'digest disabled' => ['payout-digest']];
    }

    #[DataProvider('payoutCommandCases')]
    public function test_real_payout_command_keeps_scoped_reports_and_digest_independently_editable(?string $disabled): void
    {
        $this->edit('payout-report', 'payout_report_html');
        $this->edit('payout-digest', 'payout_digest_html');
        $this->disable($disabled);
        $service = Mockery::mock(PayoutReportService::class);
        $service->shouldReceive('lastCompletedWeekRange')->once()->andReturn([Carbon::parse('2026-09-07'), Carbon::parse('2026-09-13')]);
        foreach (['Photographer' => 'photographer', 'Editor' => 'editor', 'SalesRep' => 'salesRep'] as $method => $role) {
            $service->shouldReceive('build'.$method.'Summaries')->once()->andReturn(collect([$this->payoutSummary($role)]));
        }
        $this->app->instance(PayoutReportService::class, $service);
        $sent = $this->capture($disabled === 'payout-report' ? 1 : ($disabled === 'payout-digest' ? 3 : 4));

        $this->artisan('payouts:send')->assertExitCode(0);

        foreach ($sent->items as $payload) {
            $digest = $payload['to'] === 'accounting@example.test';
            $slug = $digest ? 'payout-digest' : 'payout-report';
            $this->assertNotSame($disabled, $slug);
            $this->assertEdited($payload, $slug, '$321.45');
            if ($digest) {
                foreach (['Scoped photographer', 'Scoped editor', 'Scoped salesRep'] as $name) {
                    $this->assertStringContainsString($name, $payload['body_html']);
                }
            } else {
                $this->assertContains($payload['to'], ['photographer@example.test', 'editor@example.test', 'salesRep@example.test']);
            }
        }
        $this->assertNoOutbound();
    }

    public static function payoutControllers(): array
    {
        return [
            'payout send enabled' => ['payout', false], 'payout send disabled' => ['payout', true],
            'editor self enabled' => ['editor', false], 'editor self disabled' => ['editor', true],
            'admin editor enabled' => ['admin-editor', false], 'admin editor disabled' => ['admin-editor', true],
        ];
    }

    #[DataProvider('payoutControllers')]
    public function test_all_public_payout_send_controllers_use_saved_scoped_content(string $route, bool $disabled): void
    {
        $this->edit('payout-report', 'payout_report_html');
        $this->disable($disabled ? 'payout-report' : null);
        $user = User::factory()->create(['role' => $route === 'editor' ? 'editor' : 'superadmin', 'email' => 'editor@example.test']);
        $request = Request::create('/local-payout-qa', 'POST', ['start' => '2026-09-07', 'end' => '2026-09-13', 'role' => 'editor']);
        $request->setUserResolver(fn () => $user);
        $sent = $this->capture($disabled ? 0 : 1);
        if ($route === 'payout') {
            $service = Mockery::mock(PayoutReportService::class);
            $service->shouldReceive('buildEditorSummaries')->once()->andReturn(collect([$this->payoutSummary('editor')]));
            $this->app->instance(PayoutReportService::class, $service);
            $response = app(PayoutReportController::class)->send($request);
        } else {
            $service = Mockery::mock(EditorPayoutService::class);
            if ($route === 'editor') {
                $service->shouldReceive('getEditorDetail')->once()->andReturn(['summary' => [
                    'shoot_count' => 7, 'service_count' => 12, 'total_earned' => 321.45, 'unpaid_amount' => 321.45, 'paid_amount' => 0,
                ]]);
            } else {
                $service->shouldReceive('buildEmailSummaries')->once()->andReturn(collect([$this->payoutSummary('editor')]));
            }
            $this->app->instance(EditorPayoutService::class, $service);
            $response = $route === 'editor'
                ? app(EditorPayoutController::class)->sendReport($request)
                : app(AdminEditorPayoutController::class)->sendReport($request);
        }
        $this->assertSame(200, $response->getStatusCode());
        if (! $disabled) {
            $this->assertSame('editor@example.test', $sent->items[0]['to']);
            $this->assertEdited($sent->items[0], 'payout-report', '$321.45');
        }
        $this->assertNoOutbound();
    }

    private function edit(string $slug, string $token): void
    {
        $template = MessageTemplate::where('channel', 'EMAIL')->where('slug', $slug)->firstOrFail();
        $template->update([
            'subject' => 'Edited '.$slug,
            'body_html' => '<p>Saved HTML '.$slug.'</p>{{'.$token.'}}',
            'body_text' => 'Saved TEXT '.$slug.' {{'.str_replace('_html', '_text', $token).'}}',
        ]);
        DirectEmailTemplates::installMissing();
    }

    private function disable(?string $slug): void
    {
        if ($slug) {
            MessageTemplate::where('channel', 'EMAIL')->where('slug', $slug)->update(['is_active' => false]);
            DirectEmailTemplates::installMissing();
        }
    }

    private function capture(int $count): \stdClass
    {
        $sent = (object) ['items' => []];
        $messaging = Mockery::mock(MessagingService::class);
        if ($count === 0) {
            $messaging->shouldNotReceive('sendEmail');
        } else {
            $messaging->shouldReceive('sendEmail')->times($count)->andReturnUsing(function (array $payload) use ($sent): Message {
                $sent->items[] = $payload;

                return new Message;
            });
        }
        $this->app->instance(MessagingService::class, $messaging);

        return $sent;
    }

    private function assertEdited(array $payload, string $slug, string $liveDetail): void
    {
        $this->assertSame('Edited '.$slug, $payload['subject']);
        $this->assertStringContainsString('Saved HTML '.$slug, $payload['body_html']);
        $this->assertStringContainsString('Saved TEXT '.$slug, $payload['body_text']);
        $this->assertStringContainsString($liveDetail, $payload['body_html']);
        $this->assertStringContainsString('data-email-design="atelier-v6"', $payload['body_html']);
        $this->assertSame(1, substr_count($payload['body_html'], 'data-email-content="true"'));
        $this->assertSame(1, preg_match_all('/<!doctype html/i', $payload['body_html']));
        $this->assertDoesNotMatchRegularExpression('/\{\{[a-z_]+\}\}/', $payload['body_html']);
        $this->assertStringNotContainsString('Jamie Example', $payload['body_html']);
        $this->assertStringNotContainsString('Preview example', $payload['body_html']);
    }

    private function payoutSummary(string $role): array
    {
        return [
            'id' => 42, 'name' => 'Scoped '.$role, 'email' => $role.'@example.test', 'role' => $role,
            'shoot_count' => 7, 'service_count' => 12, 'gross_total' => 321.45, 'average_value' => 45.92,
            'commission_rate' => 10, 'commission_total' => 32.15, 'compensation_total' => 0, 'payout_total' => 321.45,
        ];
    }

    private function weeklyReport(): array
    {
        return [
            'period' => ['week_number' => 37, 'year' => 2026],
            'summary' => ['total_shoots' => 24, 'completion_rate' => 92, 'total_revenue' => 8642, 'completed_shoots' => 22, 'total_paid' => 7418, 'outstanding_balance' => 1224],
            'clients' => [['client_name' => 'Distinctive Client Brokerage', 'shoot_count' => 6, 'total_paid' => 1845, 'total_revenue' => 2214]],
            'top_shoots' => [['shoot_id' => 10482, 'client_name' => 'Scoped Client', 'workflow_status' => 'Delivered', 'scheduled_date' => 'Sep 12, 2026', 'total_quote' => 645]],
        ];
    }

    private function assertNoOutbound(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
