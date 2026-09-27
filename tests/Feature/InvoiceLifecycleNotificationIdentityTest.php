<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Message;
use App\Models\SystemEmailDispatch;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceLifecycleNotificationIdentityTest extends TestCase
{
    use RefreshDatabase;

    public static function transitions(): array
    {
        return [
            ['sendInvoicePendingApprovalEmail', 'INVOICE_PENDING_APPROVAL', 'modified_at'],
            ['sendInvoiceApprovedEmail', 'INVOICE_APPROVED', 'approved_at'],
            ['sendInvoiceRejectedEmail', 'INVOICE_REJECTED', 'rejected_at'],
        ];
    }

    #[DataProvider('transitions')]
    public function test_each_transition_sends_once_and_a_later_transition_can_send_again(string $method, string $alias, string $timeField): void
    {
        User::factory()->create(['role' => 'admin', 'email_status' => 'verified']);
        $payee = User::factory()->photographer()->create(['email_status' => 'verified']);
        $invoice = Invoice::factory()->create([
            'user_id' => $payee->id, 'role' => 'photographer', 'photographer_id' => $payee->id,
            'shoot_id' => null, 'client_id' => null,
            'billing_period_start' => '2026-09-14', 'billing_period_end' => '2026-09-20',
            $timeField => '2026-09-27 10:00:00',
        ]);
        $this->mock(MessagingService::class)->shouldReceive('sendEmail')->twice()
            ->andReturnUsing(fn () => new Message(['status' => 'SENT']));
        $mail = app(MailService::class);

        $this->assertTrue($mail->$method($invoice));
        $this->assertTrue($mail->$method($invoice));
        $this->assertSame(1, SystemEmailDispatch::where('email_alias', $alias)->count());
        $invoice->update([$timeField => '2026-09-28 10:00:00']);
        $this->assertTrue($mail->$method($invoice->fresh()));
        $this->assertSame(2, SystemEmailDispatch::where('email_alias', $alias)->count());
    }
}
