<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\MailService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayoutInvoiceReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(MailService::class, fn ($mock) => $mock->shouldReceive('sendInvoicePendingApprovalEmail', 'sendInvoiceApprovedEmail')->andReturnTrue());
    }

    private function invoice(User $photographer, array $overrides = []): Invoice
    {
        return Invoice::factory()->create($overrides + [
            'user_id' => $photographer->id, 'photographer_id' => $photographer->id,
            'sales_rep_id' => null, 'client_id' => null, 'shoot_id' => null,
            'role' => 'photographer', 'status' => 'draft', 'approval_status' => 'pending',
            'billing_period_start' => '2026-09-27', 'billing_period_end' => '2026-10-03',
            'period_start' => '2026-09-27', 'period_end' => '2026-10-03',
            'total_amount' => 0, 'amount_paid' => 0, 'is_paid' => false,
            'subtotal' => 0, 'tax' => 0, 'total' => 0,
        ]);
    }

    private function shoot(User $photographer, string $completed = '2026-10-04 09:41:38'): array
    {
        $service = Service::factory()->create(['name' => '25 HDR Photos', 'photographer_pay' => 78.75, 'photographer_pay_type' => Service::PAY_TYPE_FIXED]);
        $shoot = Shoot::factory()->create([
            'photographer_id' => $photographer->id, 'scheduled_date' => '2026-10-03',
            'completed_at' => $completed, 'admin_verified_at' => $completed,
            'status' => 'delivered', 'workflow_status' => Shoot::WORKFLOW_COMPLETED,
            'address' => '3325 Dudley Avenue',
        ]);
        $shoot->services()->attach($service->id, ['photographer_id' => $photographer->id, 'quantity' => 1, 'price' => 175, 'photographer_pay' => 78.75]);

        return [$shoot, $shoot->services()->first()->pivot->id];
    }

    public function test_viewing_queue_is_read_only_and_preserves_manual_lines(): void
    {
        $photographer = User::factory()->photographer()->create();
        $invoice = $this->invoice($photographer);
        Sanctum::actingAs($photographer);
        $this->postJson("/api/photographer/invoices/{$invoice->id}/charges", ['description' => 'Missing legacy shoot', 'amount' => 78.75])->assertCreated();
        $ids = $invoice->items()->pluck('id')->all();
        $auditCount = $invoice->auditEvents()->count();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/admin/invoices/review-queue')->assertOk();
        $this->getJson('/api/admin/invoices/review-queue?start=2026-09-27&end=2026-10-03')->assertOk();
        $this->assertSame($ids, $invoice->items()->pluck('id')->all());
        $this->assertSame($auditCount, $invoice->auditEvents()->count());
    }

    public function test_edits_survive_scheduled_regeneration_and_keep_item_ids(): void
    {
        $photographer = User::factory()->photographer()->create();
        [$shoot] = $this->shoot($photographer, '2026-10-03 12:00:00');
        $service = app(InvoiceService::class);
        $invoice = $service->generateForPeriod(Carbon::parse('2026-09-27'), Carbon::parse('2026-10-03'))->first();
        Sanctum::actingAs($photographer);
        $line = $invoice->items()->first();
        $this->patchJson("/api/photographer/invoices/{$invoice->id}/items/{$line->id}", ['amount' => 60, 'description' => 'Corrected payout'])->assertOk();
        $this->postJson("/api/photographer/invoices/{$invoice->id}/charges", ['description' => 'Legacy work', 'amount' => 78.75])->assertCreated();
        $ids = $invoice->items()->pluck('id')->all();
        $service->generateForPeriod(Carbon::parse('2026-09-27'), Carbon::parse('2026-10-03'));
        $this->assertSame($ids, $invoice->items()->pluck('id')->all());
        $this->assertEquals(138.75, (float) $invoice->fresh()->total_amount);
        $this->deleteJson("/api/photographer/invoices/{$invoice->id}/charges/{$line->id}")->assertOk();
        $service->generateForPeriod(Carbon::parse('2026-09-27'), Carbon::parse('2026-10-03'));
        $this->assertDatabaseMissing('invoice_items', ['id' => $line->id]);
        $this->assertEquals(78.75, (float) $invoice->fresh()->total_amount);
    }

    public function test_changed_invoice_cannot_bypass_note_by_using_normal_confirmation(): void
    {
        $photographer = User::factory()->photographer()->create();
        $invoice = $this->invoice($photographer);
        Sanctum::actingAs($photographer);
        $this->postJson("/api/photographer/invoices/{$invoice->id}/charges", ['description' => 'Missing shoot', 'amount' => 78.75])->assertCreated();
        $this->getJson("/api/photographer/invoices/{$invoice->id}/edit")->assertJsonPath('invoice.payout_review.has_changes', true);
        $this->postJson("/api/photographer/invoices/{$invoice->id}/submit-for-approval", ['notes' => '   '])->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->postJson("/api/photographer/invoices/{$invoice->id}/reject")->assertUnprocessable();
        $this->postJson("/api/photographer/invoices/{$invoice->id}/submit-for-approval", ['notes' => 'Added missing shoot.'])->assertOk()->assertJsonPath('invoice.payout_review.label', 'Changes submitted');
        $this->assertEquals(78.75, $invoice->fresh()->payout_submission_snapshot['total_amount']);
    }

    public function test_unchanged_confirmation_needs_no_note_and_queue_separates_awaiting_payees(): void
    {
        $photographer = User::factory()->photographer()->create();
        $invoice = $this->invoice($photographer);
        $waiting = $this->invoice($photographer, ['billing_period_start' => '2026-09-20']);
        Sanctum::actingAs($photographer);
        $this->postJson("/api/photographer/invoices/{$invoice->id}/submit-for-approval")->assertOk()->assertJsonPath('invoice.payout_review.label', 'Confirmed unchanged');
        Sanctum::actingAs(User::factory()->admin()->create());
        $response = $this->getJson('/api/admin/invoices/review-queue?approval_status=pending_approval')->assertOk();
        $this->assertSame([$invoice->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertFalse(collect($response->json('data'))->pluck('id')->contains($waiting->id));
    }

    public function test_out_of_week_service_requires_reason_and_cannot_be_paid_again_next_week(): void
    {
        $photographer = User::factory()->photographer()->create();
        $invoice = $this->invoice($photographer);
        [$shoot, $serviceId] = $this->shoot($photographer);
        Sanctum::actingAs($photographer);
        $this->getJson("/api/photographer/invoices/{$invoice->id}/shoot-candidates?search=Dudley")->assertOk()->assertJsonPath('data.0.outside_period', true)->assertJsonPath('data.0.amount', 78.75);
        $data = ['action' => 'add_shoot', 'shoot_id' => $shoot->id, 'shoot_service_id' => $serviceId, 'expected_revision' => 0];
        $this->postJson("/api/photographer/invoices/{$invoice->id}/edit/items", $data)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/photographer/invoices/{$invoice->id}/edit/items", $data + ['reason' => 'Shot Saturday; include with this work week.'])->assertCreated()->assertJsonPath('invoice.payout_review.revision', 1);
        $this->assertTrue($invoice->shoots()->where('shoots.id', $shoot->id)->exists());
        $next = app(InvoiceService::class)->generateForPeriod(Carbon::parse('2026-10-04'), Carbon::parse('2026-10-10'));
        $this->assertCount(0, $next);
        $this->assertSame(1, $invoice->items()->count());
        $this->getJson("/api/photographer/invoices/{$invoice->id}/shoot-candidates?search=Dudley")->assertJsonPath('data.0.eligible', false);
        $this->postJson("/api/photographer/invoices/{$invoice->id}/edit/items", array_replace($data, ['expected_revision' => 1, 'reason' => 'Repeated']))->assertUnprocessable();
    }

    public function test_admin_edits_require_reason_and_stale_revision_cannot_overwrite_or_approve(): void
    {
        $photographer = User::factory()->photographer()->create();
        $invoice = $this->invoice($photographer, ['approval_status' => 'pending_approval']);
        $item = $invoice->items()->create(['type' => 'charge', 'description' => 'HDR', 'quantity' => 1, 'unit_amount' => 50, 'total_amount' => 50]);
        $invoice->refreshTotals();
        Sanctum::actingAs(User::factory()->admin()->create());
        $path = "/api/admin/invoices/{$invoice->id}/edit/items/{$item->id}";
        $this->patchJson($path, ['expected_revision' => 0, 'amount' => 78.75])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson($path, ['expected_revision' => 0, 'amount' => 78.75, 'reason' => 'Corrected standard rate'])->assertOk()->assertJsonPath('invoice.payout_review.revision', 1);
        $this->patchJson($path, ['expected_revision' => 0, 'amount' => 99, 'reason' => 'Stale page'])->assertUnprocessable();
        $this->postJson("/api/admin/invoices/{$invoice->id}/approve", ['expected_revision' => 0])->assertUnprocessable();
        $this->assertEquals(78.75, (float) $item->fresh()->total_amount);
        $this->postJson("/api/admin/invoices/{$invoice->id}/approve", ['expected_revision' => 1])->assertOk();
        $this->assertEquals(78.75, $invoice->fresh()->approval_snapshot['total_amount']);
    }

    public function test_external_work_has_verification_warning_and_is_deduplicated(): void
    {
        $photographer = User::factory()->photographer()->create();
        $invoice = $this->invoice($photographer);
        Sanctum::actingAs($photographer);
        $data = ['action' => 'add_external', 'expected_revision' => 0, 'description' => '25 HDR Photos', 'amount' => 78.75,
            'address' => '4953 Edgemere Avenue', 'work_date' => '2026-09-27', 'reference' => 'viewshoot:1626:466152', 'reason' => 'Excluded legacy import'];
        $response = $this->postJson("/api/photographer/invoices/{$invoice->id}/edit/items", $data)->assertCreated();
        $response->assertJsonPath('invoice.unresolved_warnings.0.code', 'external_work_verification');
        $this->postJson("/api/photographer/invoices/{$invoice->id}/edit/items", array_replace($data, ['expected_revision' => 1]))->assertUnprocessable();
        $this->postJson("/api/photographer/invoices/{$invoice->id}/submit-for-approval", ['notes' => 'Added excluded legacy shoot.'])->assertOk();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/admin/invoices/{$invoice->id}/approve", ['expected_revision' => 1])->assertUnprocessable();
        $item = $invoice->items()->first();
        $this->patchJson("/api/admin/invoices/{$invoice->id}/edit/items/{$item->id}", ['expected_revision' => 1, 'verified' => true, 'reason' => 'Checked legacy job and payout'])->assertOk()->assertJsonCount(0, 'invoice.unresolved_warnings');
        $this->postJson("/api/admin/invoices/{$invoice->id}/approve", ['expected_revision' => 2])->assertOk();
    }

    public function test_ownership_assignment_paid_and_client_invoice_boundaries(): void
    {
        $photographer = User::factory()->photographer()->create();
        $other = User::factory()->photographer()->create();
        $invoice = $this->invoice($photographer);
        [$shoot, $serviceId] = $this->shoot($other);
        Sanctum::actingAs($photographer);
        $this->postJson("/api/photographer/invoices/{$invoice->id}/edit/items", ['action' => 'add_shoot', 'expected_revision' => 0, 'shoot_id' => $shoot->id, 'shoot_service_id' => $serviceId, 'reason' => 'Wrong person'])->assertUnprocessable();
        Sanctum::actingAs($other);
        $this->getJson("/api/photographer/invoices/{$invoice->id}/edit")->assertForbidden();
        $this->getJson("/api/photographer/invoices/{$invoice->id}/shoot-candidates")->assertForbidden();
        $invoice->update(['is_paid' => true, 'status' => 'paid']);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/admin/invoices/{$invoice->id}/edit/items", ['action' => 'add_expense', 'description' => 'Extra', 'amount' => 10, 'expected_revision' => 0, 'reason' => 'Too late'])->assertUnprocessable();
        $clientInvoice = $this->invoice($photographer, ['role' => 'client']);
        $this->getJson("/api/admin/invoices/{$clientInvoice->id}/edit")->assertForbidden();
    }

    public function test_previous_return_reason_survives_resubmission_in_payload(): void
    {
        $photographer = User::factory()->photographer()->create();
        $invoice = $this->invoice($photographer, ['approval_status' => 'rejected', 'rejection_reason' => 'Please add the missing shoots.']);
        $invoice->recordAuditEvent('returned', User::factory()->admin()->create(), 'Returned', ['reason' => 'Please add the missing shoots.']);
        Sanctum::actingAs($photographer);
        $this->postJson("/api/photographer/invoices/{$invoice->id}/submit-for-approval", ['notes' => 'Added the requested detail.'])->assertOk()->assertJsonPath('invoice.payout_review.last_return_reason', 'Please add the missing shoots.');
    }

    public function test_stale_submission_and_submitted_edit_are_rejected(): void
    {
        $person = User::factory()->photographer()->create();
        $invoice = $this->invoice($person);
        Sanctum::actingAs($person);
        $this->postJson("/api/photographer/invoices/{$invoice->id}/edit/items", ['action' => 'add_expense', 'expected_revision' => 0, 'amount' => 35, 'description' => 'Travel', 'reference' => '', 'address' => '', 'work_date' => '', 'reason' => ''])->assertCreated();
        $this->postJson("/api/photographer/invoices/{$invoice->id}/submit-for-approval", ['expected_revision' => 0, 'notes' => 'Travel added'])->assertUnprocessable();
        $this->postJson("/api/photographer/invoices/{$invoice->id}/submit-for-approval", ['expected_revision' => 1, 'notes' => 'Travel added'])->assertOk();
        $this->postJson("/api/photographer/invoices/{$invoice->id}/edit/items", ['action' => 'add_expense', 'expected_revision' => 1, 'amount' => 5, 'description' => 'Late edit'])->assertUnprocessable();
        $this->assertEquals(35, $invoice->fresh()->payout_submission_snapshot['total_amount']);
    }

    public function test_sales_rep_adjustments_require_note_and_are_preserved(): void
    {
        $person = User::factory()->create(['role' => 'salesRep']);
        $invoice = $this->invoice($person, ['role' => 'salesRep', 'sales_rep_id' => $person->id, 'photographer_id' => null]);
        Sanctum::actingAs($person);
        $this->postJson("/api/salesrep/invoices/{$invoice->id}/edit/items", ['action' => 'add_expense', 'expected_revision' => 0, 'amount' => 35, 'description' => 'Commission adjustment'])->assertCreated();
        $this->postJson("/api/salesrep/invoices/{$invoice->id}/submit-for-approval")->assertUnprocessable()->assertJsonValidationErrors('notes');
        app(InvoiceService::class)->generateSalesRepInvoicesForPeriod(Carbon::parse('2026-09-27'), Carbon::parse('2026-10-03'));
        $this->assertEquals(35, (float) $invoice->fresh()->total_amount);
        $this->postJson("/api/salesrep/invoices/{$invoice->id}/submit-for-approval", ['notes' => 'Commission correction'])->assertOk()->assertJsonPath('invoice.payout_review.label', 'Changes submitted');
    }

    public function test_legacy_reference_formats_and_future_import_cannot_double_pay(): void
    {
        $person = User::factory()->photographer()->create();
        $invoice = $this->invoice($person);
        Sanctum::actingAs($person);
        $data = ['action' => 'add_external', 'expected_revision' => 0, 'description' => '25 HDR', 'amount' => 78.75, 'address' => 'Edgemere', 'work_date' => '2026-09-27', 'reference' => 'viewshoot:1626:466152', 'reason' => 'Not imported'];
        $this->postJson("/api/photographer/invoices/{$invoice->id}/edit/items", $data)->assertCreated();
        $this->postJson("/api/photographer/invoices/{$invoice->id}/edit/items", array_replace($data, ['expected_revision' => 1, 'reference' => 'https://pro.reprophotos.com/download/466152']))->assertUnprocessable();
        [$shoot] = $this->shoot($person);
        \Illuminate\Support\Facades\DB::table('legacy_shoot_imports')->insert(['source_system' => 'viewshoot', 'source_id' => '466152', 'shoot_id' => $shoot->id, 'batch_id' => 'test', 'action' => 'created', 'source_snapshot' => '{}', 'created_at' => now()]);
        $this->getJson("/api/photographer/invoices/{$invoice->id}/shoot-candidates?search=Dudley")->assertJsonPath('data.0.eligible', false);
        $this->assertCount(0, app(InvoiceService::class)->generateForPeriod(Carbon::parse('2026-10-04'), Carbon::parse('2026-10-10')));
    }

    public function test_service_level_assignment_quantity_and_verified_work_use_payout_not_client_price(): void
    {
        $person = User::factory()->photographer()->create();
        $other = User::factory()->photographer()->create();
        $invoice = $this->invoice($person);
        [$shoot, $serviceId] = $this->shoot($other, '2026-10-03 12:00:00');
        $shoot->update(['workflow_status' => Shoot::WORKFLOW_ADMIN_VERIFIED]);
        \Illuminate\Support\Facades\DB::table('shoot_service')->where('id', $serviceId)->update(['photographer_id' => $person->id, 'quantity' => 2, 'photographer_pay' => 20]);
        Sanctum::actingAs($person);
        $this->getJson("/api/photographer/invoices/{$invoice->id}/shoot-candidates")->assertJsonPath('data.0.amount', 40)->assertJsonPath('data.0.eligible', true);
        $this->postJson("/api/photographer/invoices/{$invoice->id}/edit/items", ['action' => 'add_shoot', 'expected_revision' => 0, 'shoot_id' => $shoot->id, 'shoot_service_id' => $serviceId])->assertCreated()->assertJsonPath('invoice.total_amount', '40.00');
    }

    public function test_historical_lost_edits_are_flagged_and_read_only_audit_does_not_change_them(): void
    {
        $person = User::factory()->photographer()->create();
        $invoice = $this->invoice($person, ['approval_status' => 'pending_approval']);
        $invoice->recordAuditEvent('payee_edit', $person, 'Added missing work', ['description' => 'Edgemere', 'amount' => 78.75]);
        $invoice->recordAuditEvent('recalculated', null, 'Regenerated', ['before' => ['total_amount' => 78.75], 'after' => ['total_amount' => 0]]);
        $invoice->recordAuditEvent('recalculated', null, 'Regenerated again with no change', ['before' => ['total_amount' => 0], 'after' => ['total_amount' => 0]]);
        $this->assertNotNull($invoice->fresh()->payout_review['recovery_required']);
        $events = $invoice->auditEvents()->count();
        $this->artisan('invoices:audit-payout-edits', ['--invoice' => [$invoice->id]])->assertSuccessful();
        $this->assertEquals(0, (float) $invoice->fresh()->total_amount);
        $this->assertSame($events, $invoice->auditEvents()->count());
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/admin/invoices/{$invoice->id}/approve", ['expected_revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('warning_override_reason');
        $item = $invoice->items()->create(['type' => 'charge', 'description' => 'Existing line', 'quantity' => 1, 'unit_amount' => 0, 'total_amount' => 0]);
        $this->patchJson("/api/admin/invoices/{$invoice->id}/edit/items/{$item->id}", ['expected_revision' => 1, 'description' => 'Corrected description', 'reason' => 'Only changed description'])->assertOk();
        $this->assertNotNull($invoice->fresh()->payout_review['recovery_required']);
        $this->patchJson("/api/admin/invoices/{$invoice->id}/edit/items/{$item->id}", ['expected_revision' => 2, 'description' => 'Reconciled line', 'reason' => 'Verified every earlier change', 'reconcile_history' => true])->assertOk()->assertJsonPath('invoice.payout_review.recovery_required', null);
        $this->postJson("/api/admin/invoices/{$invoice->id}/approve", ['expected_revision' => 3])->assertOk();
    }

    public function test_removed_generated_service_cannot_reappear_when_completion_moves_week(): void
    {
        $person = User::factory()->photographer()->create();
        [$shoot] = $this->shoot($person, '2026-10-03 12:00:00');
        $service = app(InvoiceService::class);
        $invoice = $service->generateForPeriod(Carbon::parse('2026-09-27'), Carbon::parse('2026-10-03'))->first();
        $item = $invoice->items()->first();
        Sanctum::actingAs($person);
        $this->deleteJson("/api/photographer/invoices/{$invoice->id}/edit/items/{$item->id}", ['expected_revision' => 0])->assertOk();
        $shoot->update(['completed_at' => '2026-10-04 12:00:00', 'admin_verified_at' => '2026-10-04 12:00:00']);
        $this->assertCount(0, $service->generateForPeriod(Carbon::parse('2026-10-04'), Carbon::parse('2026-10-10')));
    }
}
