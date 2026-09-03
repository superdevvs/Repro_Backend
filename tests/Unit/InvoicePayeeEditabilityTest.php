<?php

namespace Tests\Unit;

use App\Models\Invoice;
use Tests\TestCase;

class InvoicePayeeEditabilityTest extends TestCase
{
    public function test_payees_can_modify_pending_or_rejected_unpaid_invoices_even_when_sent(): void
    {
        $pendingSent = new Invoice([
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
            'status' => Invoice::STATUS_SENT,
            'is_paid' => false,
            'paid_at' => null,
        ]);

        $rejectedSent = new Invoice([
            'role' => Invoice::ROLE_PHOTOGRAPHER,
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
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
            'status' => Invoice::STATUS_PAID,
            'is_paid' => false,
            'paid_at' => null,
        ]);

        $paidByFlag = new Invoice([
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
            'status' => Invoice::STATUS_SENT,
            'is_paid' => true,
            'paid_at' => null,
        ]);

        $paidByTimestamp = new Invoice([
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
            'status' => Invoice::STATUS_SENT,
            'is_paid' => false,
            'paid_at' => '2026-07-04 12:00:00',
        ]);

        $accountsApproved = new Invoice([
            'role' => Invoice::ROLE_PHOTOGRAPHER,
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

    public function test_edit_locked_reason_is_null_while_the_invoice_is_editable(): void
    {
        $editable = new Invoice([
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
            'status' => Invoice::STATUS_SENT,
            'is_paid' => false,
            'paid_at' => null,
        ]);

        $this->assertNull($editable->editLockedReason());
    }

    public function test_edit_locked_reason_names_the_specific_blocking_condition(): void
    {
        $paid = new Invoice([
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
            'status' => Invoice::STATUS_SENT,
            'is_paid' => true,
            'paid_at' => null,
        ]);
        $accountsApproved = new Invoice([
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'approval_status' => Invoice::APPROVAL_STATUS_APPROVED,
            'status' => Invoice::STATUS_SENT,
            'is_paid' => false,
            'paid_at' => null,
        ]);
        $pendingApproval = new Invoice([
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
            'status' => Invoice::STATUS_SENT,
            'is_paid' => false,
            'paid_at' => null,
        ]);

        $this->assertStringContainsString('paid', $paid->editLockedReason());
        $this->assertStringContainsString('approved by accounts', $accountsApproved->editLockedReason());
        $this->assertStringContainsString('awaiting accounts approval', $pendingApproval->editLockedReason());
    }

    public function test_payload_exposes_server_computed_can_edit_and_edit_locked_reason(): void
    {
        $editable = (new Invoice([
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
            'status' => Invoice::STATUS_SENT,
            'is_paid' => false,
            'paid_at' => null,
        ]))->toArray();

        $locked = (new Invoice([
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'approval_status' => Invoice::APPROVAL_STATUS_APPROVED,
            'status' => Invoice::STATUS_SENT,
            'is_paid' => false,
            'paid_at' => null,
        ]))->toArray();

        $this->assertArrayHasKey('can_edit', $editable);
        $this->assertArrayHasKey('edit_locked_reason', $editable);
        $this->assertTrue($editable['can_edit']);
        $this->assertNull($editable['edit_locked_reason']);

        $this->assertFalse($locked['can_edit']);
        $this->assertStringContainsString('approved by accounts', $locked['edit_locked_reason']);
    }
}
