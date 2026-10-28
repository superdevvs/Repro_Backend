<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Message;
use App\Models\SystemEmailDispatch;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceApprovalEmailRecipientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_active_accounting_recipients_with_payment_emails_enabled_get_an_alert(): void
    {
        $eligible = User::factory()->create(['role' => 'admin', 'email_status' => 'verified']);
        foreach (['accounting-view', 'email-notifications-payments'] as $denied) {
            User::factory()->create(['role' => 'admin', 'permission_overrides' => ['allow' => [], 'deny' => [$denied]]]);
        }
        User::factory()->create(['role' => 'admin', 'account_status' => 'inactive']);
        $payee = User::factory()->photographer()->create();
        $invoice = Invoice::factory()->create(['user_id' => $payee->id, 'role' => 'photographer',
            'photographer_id' => $payee->id, 'shoot_id' => null, 'client_id' => null,
            'billing_period_start' => '2026-10-18', 'billing_period_end' => '2026-10-24']);
        $this->mock(MessagingService::class)->shouldReceive('sendEmail')->once()
            ->andReturn(new Message(['status' => 'SENT']));

        $this->assertTrue(app(MailService::class)->sendInvoicePendingApprovalEmail($invoice));
        $this->assertSame([$eligible->email], SystemEmailDispatch::where('email_alias', 'INVOICE_PENDING_APPROVAL')->pluck('recipient_email')->all());
    }
}
