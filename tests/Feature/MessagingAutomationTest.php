<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagingAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_compose_creates_internal_message(): void
    {
        $user = User::factory()->create(['role' => 'client', 'email' => 'client@example.com']);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/messaging/email/compose', [
            'subject' => 'Need help',
            'body_text' => 'Hello admin',
        ]);

        $response->assertOk();

        $message = Message::first();
        $this->assertNotNull($message);
        $this->assertSame('INTERNAL', $message->provider);
        $this->assertSame('INBOUND', $message->direction);
        $this->assertSame('SENT', $message->status);
        $this->assertSame('MANUAL', $message->send_source);
        $this->assertSame($user->email, $message->from_address);
        $this->assertSame(config('mail.contact_address', 'contact@reprophotos.com'), $message->to_address);
        $this->assertSame($user->id, $message->sender_user_id);
        $this->assertStringContainsString((string) $user->id, (string) $message->sender_display_name);
    }

    public function test_admin_compose_sends_external_email(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.com']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/messaging/email/compose', [
            'to' => 'recipient@example.com',
            'subject' => 'Hello',
            'body_text' => 'Testing',
        ]);

        $response->assertOk();

        $message = Message::first();
        $this->assertNotNull($message);
        $this->assertSame('SENT', $message->status);
        $this->assertSame('OUTBOUND', $message->direction);
        $this->assertSame('MANUAL', $message->send_source);
        $this->assertSame('recipient@example.com', $message->to_address);
        $this->assertSame('LOCAL_SMTP', $message->provider);
    }

    public function test_invoice_reminders_send_due_and_overdue_once(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create(['role' => 'client', 'email' => 'client@example.com']);

        $dueTemplate = $this->createTemplate('Invoice Due');
        $overdueTemplate = $this->createTemplate('Invoice Overdue');

        $this->createAutomation('INVOICE_DUE', $dueTemplate, ['client']);
        $this->createAutomation('INVOICE_OVERDUE', $overdueTemplate, ['client']);

        $dueInvoice = $this->createInvoice([
            'client_id' => $client->id,
            'user_id' => $client->id,
            'role' => Invoice::ROLE_CLIENT,
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'issue_date' => now()->subDays(2),
            'due_date' => now(),
            'total' => 250,
            'amount_paid' => 0,
            'status' => Invoice::STATUS_SENT,
        ]);

        $overdueInvoice = $this->createInvoice([
            'client_id' => $client->id,
            'user_id' => $client->id,
            'role' => Invoice::ROLE_CLIENT,
            'period_start' => now()->subDays(10)->toDateString(),
            'period_end' => now()->subDays(2)->toDateString(),
            'issue_date' => now()->subDays(15),
            'due_date' => now()->subDay(),
            'total' => 180,
            'amount_paid' => 0,
            'status' => Invoice::STATUS_SENT,
        ]);

        Artisan::call('messaging:invoice-reminders');

        $dueMessage = Message::where('related_invoice_id', $dueInvoice->id)->first();
        $this->assertNotNull($dueMessage);
        $this->assertSame('AUTOMATION', $dueMessage->send_source);
        $this->assertContains(
            sprintf('INVOICE_DUE:%s:%s', $dueInvoice->id, now()->toDateString()),
            $dueMessage->tags_json ?? []
        );

        $overdueMessage = Message::where('related_invoice_id', $overdueInvoice->id)->first();
        $this->assertNotNull($overdueMessage);
        $this->assertSame('AUTOMATION', $overdueMessage->send_source);
        $this->assertContains(
            sprintf('INVOICE_OVERDUE:%s:%s', $overdueInvoice->id, now()->toDateString()),
            $overdueMessage->tags_json ?? []
        );

        Artisan::call('messaging:invoice-reminders');

        $this->assertSame(1, Message::where('related_invoice_id', $dueInvoice->id)->count());
        $this->assertSame(1, Message::where('related_invoice_id', $overdueInvoice->id)->count());
    }

    public function test_weekly_invoice_summaries_send_for_clients_and_reps(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create(['role' => 'client', 'email' => 'client@example.com']);
        $rep = User::factory()->create(['role' => 'salesRep', 'email' => 'rep@example.com']);

        $summaryTemplate = $this->createTemplate('Invoice Summary');
        $repTemplate = $this->createTemplate('Rep Invoice Summary');

        $this->createAutomation('INVOICE_SUMMARY', $summaryTemplate, ['client']);
        $this->createAutomation('WEEKLY_REP_INVOICE', $repTemplate, ['rep']);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $rep->id,
            'scheduled_date' => now()->subDays(10),
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
        ]);

        [$start, $end] = $this->lastCompletedWeek();

        $this->createInvoice([
            'client_id' => $client->id,
            'user_id' => $client->id,
            'role' => Invoice::ROLE_CLIENT,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'issue_date' => $start->copy()->addDay(),
            'due_date' => $end->copy()->addDays(7),
            'total' => 300,
            'amount_paid' => 0,
            'status' => Invoice::STATUS_SENT,
            'shoot_id' => $shoot->id,
        ]);

        Artisan::call('messaging:invoice-summaries');

        $clientMessage = Message::where('send_source', 'AUTOMATION')
            ->where('tags_json', 'like', '%INVOICE_SUMMARY:client:' . $client->id . '%')
            ->first();
        $this->assertNotNull($clientMessage);

        $repMessage = Message::where('send_source', 'AUTOMATION')
            ->where('tags_json', 'like', '%WEEKLY_REP_INVOICE:rep:' . $rep->id . '%')
            ->first();
        $this->assertNotNull($repMessage);
    }

    public function test_account_verified_event_triggers_automation(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $user = User::factory()->create(['role' => 'client']);
        $user->forceFill(['email_verified_at' => now()])->save();

        $template = $this->createTemplate('Account Verified');
        $this->createAutomation('ACCOUNT_VERIFIED', $template, ['client']);

        event(new Verified($user));

        $message = Message::where('send_source', 'AUTOMATION')
            ->where('related_account_id', $user->id)
            ->first();

        $this->assertNotNull($message);
    }

    private function createDefaultEmailChannel(): MessageChannel
    {
        return MessageChannel::create([
            'type' => 'EMAIL',
            'provider' => 'LOCAL_SMTP',
            'display_name' => 'Default',
            'from_email' => 'contact@reprophotos.com',
            'is_default' => true,
            'owner_scope' => 'GLOBAL',
        ]);
    }

    private function createTemplate(string $name): MessageTemplate
    {
        return MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => $name,
            'subject' => $name,
            'body_text' => 'Hello {{client_name}}',
            'variables_json' => ['client_name'],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
        ]);
    }

    private function createAutomation(string $trigger, MessageTemplate $template, array $recipients): AutomationRule
    {
        return AutomationRule::create([
            'name' => $trigger . ' Rule',
            'trigger_type' => $trigger,
            'template_id' => $template->id,
            'is_active' => true,
            'scope' => 'GLOBAL',
            'recipients_json' => $recipients,
        ]);
    }

    private function createInvoice(array $overrides): Invoice
    {
        $defaults = [
            'period_start' => now()->subDays(7)->toDateString(),
            'period_end' => now()->toDateString(),
            'role' => Invoice::ROLE_CLIENT,
            'status' => Invoice::STATUS_SENT,
            'total' => 100,
            'amount_paid' => 0,
            'issue_date' => now()->subDays(5),
            'due_date' => now()->addDays(10),
        ];

        return Invoice::create(array_merge($defaults, $overrides));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function lastCompletedWeek(): array
    {
        $end = now()->startOfWeek()->subDay()->endOfDay();
        $start = $end->copy()->startOfWeek();

        return [$start, $end];
    }
}
