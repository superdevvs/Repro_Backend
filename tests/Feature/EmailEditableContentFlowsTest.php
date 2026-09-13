<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\MessageTemplate;
use App\Models\SystemEmailDispatch;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\PayoutReportService;
use App\Services\SystemEmails\EditableEmailContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailEditableContentFlowsTest extends TestCase
{
    use RefreshDatabase;

    private const DIGEST_ORIGINAL = 'Please review these totals and approve any final adjustments so accounting can release payments on schedule.';

    private const VERIFIED_ORIGINAL = 'You can review and modify your notification preferences anytime in dashboard settings.';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        config(['mail.accounting_address' => 'accounting@example.test', 'app.frontend_url' => 'https://workspace.example.test/real-team']);
    }

    public static function enabledCases(): array
    {
        return ['enabled' => [true], 'disabled' => [false]];
    }

    #[DataProvider('enabledCases')]
    public function test_real_payout_command_uses_edited_paragraph_and_preserves_each_live_row(bool $enabled): void
    {
        $template = MessageTemplate::where('channel', 'EMAIL')->where('slug', 'payout-digest')->firstOrFail();
        $this->editParagraph($template, self::DIGEST_ORIGINAL,
            'Please route these finalized totals to the finance reviewer.',
            'Send the finalized payout totals to our finance reviewer.');
        $template->update(['is_active' => $enabled]);

        $report = Mockery::mock(PayoutReportService::class);
        $report->shouldReceive('lastCompletedWeekRange')->once()->andReturn([Carbon::parse('2026-09-07'), Carbon::parse('2026-09-13')]);
        foreach ([
            ['Photographer', 'photographer', 'Actual Photo Recipient', 541.23],
            ['Editor', 'editor', 'Actual Editing Recipient', 87.65],
            ['SalesRep', 'salesRep', 'Actual Sales Recipient', 119.80],
        ] as [$method, $role, $name, $amount]) {
            $report->shouldReceive('build'.$method.'Summaries')->once()->andReturn(collect([[
                'id' => 42, 'name' => $name, 'email' => $role.'@example.test', 'role' => $role,
                'shoot_count' => 7, 'service_count' => 12, 'gross_total' => $amount, 'average_value' => $amount / 7,
                'commission_rate' => 10, 'commission_total' => $amount, 'compensation_total' => 0, 'payout_total' => $amount,
            ]]));
        }
        $this->app->instance(PayoutReportService::class, $report);
        $sent = (object) ['items' => []];
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->times($enabled ? 4 : 3)->andReturnUsing(function (array $payload) use ($sent): Message {
            $sent->items[] = $payload;

            return new Message;
        });
        $this->app->instance(MessagingService::class, $messaging);

        $this->artisan('payouts:send')->assertExitCode(0);

        $digests = collect($sent->items)->where('send_source', 'PAYOUT_DIGEST')->values();
        $this->assertCount($enabled ? 1 : 0, $digests);
        $this->assertCount(3, collect($sent->items)->where('send_source', 'PAYOUT_REPORT'));
        if ($enabled) {
            $digest = $digests->first();
            $this->assertSame('accounting@example.test', $digest['to']);
            $this->assertStringContainsString('Please route these finalized totals to the finance reviewer.', $digest['body_html']);
            $this->assertStringContainsString('Send the finalized payout totals to our finance reviewer.', $digest['body_text']);
            $this->assertStringNotContainsString(self::DIGEST_ORIGINAL, $digest['body_html']);
            $this->assertStringNotContainsString(self::DIGEST_ORIGINAL, $digest['body_text']);
            foreach (['Actual Photo Recipient', 'Actual Editing Recipient', 'Actual Sales Recipient', '$541.23', '$87.65', '$119.80'] as $value) {
                $this->assertStringContainsString($value, $digest['body_html']);
                $this->assertStringContainsString($value, $digest['body_text']);
            }
            $this->assertCanonical($digest['body_html']);
        }
        $this->assertSame($enabled, $template->fresh()->is_active);
        $this->assertNotEmpty($template->fresh()->content_blocks_json);
        Http::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('enabledCases')]
    public function test_real_verified_email_pipeline_applies_copy_only_with_enabled_protected_override(bool $overrideEnabled): void
    {
        $template = MessageTemplate::where('channel', 'EMAIL')->where('email_type', 'CLIENT_EMAIL_VERIFIED')->firstOrFail();
        $this->editParagraph($template, self::VERIFIED_ORIGINAL,
            'Choose which account updates reach you in your notification settings.',
            'Manage the account updates you receive from notification settings.');
        $template->update(['override_enabled' => $overrideEnabled]);
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        MessageChannel::create([
            'type' => 'EMAIL', 'provider' => 'LOCAL_SMTP', 'display_name' => 'Local test mail',
            'from_email' => 'contact@example.test', 'is_default' => true, 'owner_scope' => 'GLOBAL',
        ]);
        $user = User::factory()->create([
            'name' => 'Morgan Real Recipient', 'email' => 'morgan.real@example.test',
            'role' => 'client', 'email_status' => 'verified',
        ]);

        $this->assertTrue(app(MailService::class)->sendClientEmailVerifiedEmail($user, ['verification_token_id' => 781]));

        $message = Message::where('send_source', 'CLIENT_EMAIL_VERIFIED')->where('related_account_id', $user->id)->sole();
        $dispatch = SystemEmailDispatch::where('email_alias', 'CLIENT_EMAIL_VERIFIED')->sole();
        $this->assertSame('sent', $dispatch->status);
        $this->assertSame($message->id, $dispatch->message_id);
        $this->assertSame($user->email, $message->to_address);
        $this->assertStringContainsString($user->name, $message->body_html);
        $this->assertStringContainsString($user->email, $message->body_html);
        $this->assertStringContainsString('href="https://workspace.example.test/real-team"', $message->body_html);
        $this->assertStringContainsString('href="https://workspace.example.test/real-team/settings"', $message->body_html);
        if ($overrideEnabled) {
            $this->assertStringContainsString('Choose which account updates reach you in your notification settings.', $message->body_html);
            $this->assertStringContainsString('Manage the account updates you receive from notification settings.', $message->body_text);
            $this->assertStringNotContainsString(self::VERIFIED_ORIGINAL, $message->body_html);
            $this->assertStringNotContainsString(self::VERIFIED_ORIGINAL, $message->body_text);
        } else {
            $this->assertStringContainsString(self::VERIFIED_ORIGINAL, $message->body_html);
            $this->assertStringContainsString(self::VERIFIED_ORIGINAL, $message->body_text);
            $this->assertStringNotContainsString('Choose which account updates reach you', $message->body_html);
            $this->assertStringNotContainsString('Manage the account updates you receive', $message->body_text);
        }
        $this->assertCanonical($message->body_html);
        $this->assertSame($overrideEnabled, $template->fresh()->override_enabled);
        $this->assertNotEmpty($template->fresh()->content_blocks_json);
        Http::assertNothingSent();
        Notification::assertNothingSent();
        Mail::assertNothingQueued();
        // The real messaging pipeline broadcasts its saved-message event; the
        // fake queue absorbs that normal event without sending it externally.
        foreach (array_keys(Queue::pushedJobs()) as $job) {
            $this->assertSame(\Illuminate\Broadcasting\BroadcastEvent::class, $job);
        }
    }

    private function editParagraph(MessageTemplate $template, string $original, string $htmlCopy, string $textCopy): void
    {
        $blocks = collect(app(EditableEmailContent::class)->blocks($template));
        $matches = $blocks->filter(fn (array $block): bool => str_contains($block['body_html'], $original));
        $this->assertCount(1, $matches, 'Expected one editable paragraph for the live sentence.');
        $block = $matches->first();
        $edited = [
            'body_html' => str_replace($original, $htmlCopy, $block['body_html']),
            'body_text' => str_replace($original, $textCopy, $block['body_text']),
        ];
        $this->assertStringContainsString($textCopy, $edited['body_text']);
        $template->update(['content_blocks_json' => [$block['key'] => $edited]]);
        $saved = collect(app(EditableEmailContent::class)->blocks($template->fresh()))->firstWhere('key', $block['key']);
        $this->assertSame($edited['body_html'], $saved['body_html']);
        $this->assertSame($edited['body_text'], $saved['body_text']);
    }

    private function assertCanonical(string $html): void
    {
        $this->assertStringContainsString('data-email-design="atelier-v6"', $html);
        $this->assertSame(1, substr_count($html, 'data-email-content="true"'));
        $this->assertSame(1, preg_match_all('/<!doctype html/i', $html));
        $this->assertDoesNotMatchRegularExpression('/\{\{[a-z_]+\}\}/i', $html);
        $this->assertStringNotContainsString('Preview example', $html);
        $this->assertStringNotContainsString('Jamie Example', $html);
    }
}
