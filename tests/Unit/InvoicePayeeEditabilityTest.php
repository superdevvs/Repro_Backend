<?php

namespace Tests\Unit;

use App\Models\Invoice;
use Tests\TestCase;

class InvoicePayeeEditabilityTest extends TestCase
{
    public function test_payees_can_modify_pending_or_rejected_unpaid_invoices_even_when_sent(): void
    {
        $pendingSent = new Invoice([
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
            'status' => Invoice::STATUS_SENT,
            'is_paid' => false,
            'paid_at' => null,
        ]);

        $rejectedSent = new Invoice([
            'approval_status' => Invoice::APPROVAL_STATUS_REJECTED,
            'status' => Invoice::STATUS_SENT,
            'is_paid' => false,
            'paid_at' => null,
        ]);

        $this->assertTrue($pendingSent->canBeModifiedByPayee());
        $this->assertTrue($pendingSent->canBeModifiedByPhotographer());
        $this->assertTrue($rejectedSent->canBeModifiedByPayee());
    }

    public function test_payees_cannot_modify_paid_or_accounts_approved_invoices(): void
    {
        $paidByStatus = new Invoice([
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
            'status' => Invoice::STATUS_PAID,
            'is_paid' => false,
            'paid_at' => null,
        ]);

        $paidByFlag = new Invoice([
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
            'status' => Invoice::STATUS_SENT,
            'is_paid' => true,
            'paid_at' => null,
        ]);

        $paidByTimestamp = new Invoice([
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
            'status' => Invoice::STATUS_SENT,
            'is_paid' => false,
            'paid_at' => '2026-07-04 12:00:00',
        ]);

        $accountsApproved = new Invoice([
            'approval_status' => Invoice::APPROVAL_STATUS_APPROVED,
            'status' => Invoice::STATUS_SENT,
            'is_paid' => false,
            'paid_at' => null,
        ]);

        $this->assertFalse($paidByStatus->canBeModifiedByPayee());
        $this->assertFalse($paidByFlag->canBeModifiedByPayee());
        $this->assertFalse($paidByTimestamp->canBeModifiedByPayee());
        $this->assertFalse($accountsApproved->canBeModifiedByPayee());
    }
}
