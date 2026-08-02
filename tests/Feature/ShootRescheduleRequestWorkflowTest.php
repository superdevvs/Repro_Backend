<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootRescheduleRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A1.docx item 4: "Request to reschedule" must actually be a request.
 *
 * Before: `store()` created every row already `approved` and applied the new date
 * immediately, so a client silently rescheduled their own shoot while the button
 * claimed to be asking permission.
 *
 * After: a request-only actor produces a `pending` row and the shoot is
 * untouched; staff approve or reject; approval applies once.
 */
class ShootRescheduleRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL_DATE = '2026-09-10';
    private const ORIGINAL_TIME = '10:00 AM';
    private const REQUESTED_DATE = '2026-09-24';
    private const REQUESTED_TIME = '02:30 PM';

    private function makeShoot(User $client): Shoot
    {
        return Shoot::factory()->create([
            'client_id' => $client->id,
            'scheduled_date' => self::ORIGINAL_DATE,
            'time' => self::ORIGINAL_TIME,
            'status' => Shoot::STATUS_SCHEDULED,
        ]);
    }

    private function submitRequest(Shoot $shoot): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/shoots/{$shoot->id}/reschedule", [
            'requested_date' => self::REQUESTED_DATE,
            'requested_time' => self::REQUESTED_TIME,
            'reason' => 'Sellers need another week.',
        ]);
    }

    // --- Submission -------------------------------------------------------

    public function test_a_client_submission_is_pending_and_does_not_move_the_shoot(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = $this->makeShoot($client);

        Sanctum::actingAs($client);
        $response = $this->submitRequest($shoot);

        $response->assertCreated()
            ->assertJsonPath('applied', false)
            ->assertJsonPath('data.status', ShootRescheduleRequest::STATUS_PENDING);

        $record = ShootRescheduleRequest::firstOrFail();
        $this->assertTrue($record->isPending());
        $this->assertNull($record->applied_at);
        $this->assertNull($record->approved_by);
        $this->assertNull($record->reviewed_at);

        // The confirmed schedule is untouched — this is the bug being fixed.
        $shoot->refresh();
        $this->assertSame(self::ORIGINAL_DATE, $shoot->scheduled_date->toDateString());
        $this->assertSame(self::ORIGINAL_TIME, $shoot->time);
        $this->assertNotSame(Shoot::STATUS_CANCELLED, $shoot->status);
    }

    public function test_the_requested_values_are_stored_separately_from_the_confirmed_ones(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = $this->makeShoot($client);

        Sanctum::actingAs($client);
        $this->submitRequest($shoot);

        $record = ShootRescheduleRequest::firstOrFail();

        $this->assertSame(self::REQUESTED_DATE, $record->requested_date->toDateString());
        $this->assertSame(self::REQUESTED_TIME, $record->requested_time);
        // Snapshot of what was confirmed at submission time.
        $this->assertSame(self::ORIGINAL_DATE, $record->original_date->toDateString());
        $this->assertSame(self::ORIGINAL_TIME, $record->original_time);
    }

    public function test_staff_keep_their_existing_direct_reschedule_ability(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->makeShoot($client);

        Sanctum::actingAs($admin);
        $response = $this->submitRequest($shoot);

        $response->assertCreated()->assertJsonPath('applied', true);

        $record = ShootRescheduleRequest::firstOrFail();
        $this->assertTrue($record->isApproved());
        $this->assertNotNull($record->applied_at);

        $shoot->refresh();
        $this->assertSame(self::REQUESTED_DATE, $shoot->scheduled_date->toDateString());
        $this->assertSame(self::REQUESTED_TIME, $shoot->time);
    }

    public function test_a_photographer_submission_is_a_request_not_a_direct_change(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot = $this->makeShoot($client);

        Sanctum::actingAs($photographer);
        $this->submitRequest($shoot)->assertCreated()->assertJsonPath('applied', false);

        $shoot->refresh();
        $this->assertSame(self::ORIGINAL_DATE, $shoot->scheduled_date->toDateString());
    }

    // --- Approval ---------------------------------------------------------

    private function pendingRequestFor(Shoot $shoot, User $client): ShootRescheduleRequest
    {
        return ShootRescheduleRequest::create([
            'shoot_id' => $shoot->id,
            'requested_by' => $client->id,
            'original_date' => self::ORIGINAL_DATE,
            'original_time' => self::ORIGINAL_TIME,
            'requested_date' => self::REQUESTED_DATE,
            'requested_time' => self::REQUESTED_TIME,
            'status' => ShootRescheduleRequest::STATUS_PENDING,
        ]);
    }

    public function test_approval_applies_the_requested_change(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->makeShoot($client);
        $record = $this->pendingRequestFor($shoot, $client);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/shoots/reschedule-requests/{$record->id}", [
            'status' => 'approved',
        ])->assertOk()->assertJsonPath('applied', true);

        $shoot->refresh();
        $this->assertSame(self::REQUESTED_DATE, $shoot->scheduled_date->toDateString());
        $this->assertSame(self::REQUESTED_TIME, $shoot->time);

        $record->refresh();
        $this->assertTrue($record->isApproved());
        $this->assertNotNull($record->applied_at);
        $this->assertSame($admin->id, $record->approved_by);
    }

    public function test_an_editing_manager_may_also_review(): void
    {
        // The route middleware already admitted editing_manager while the
        // controller check did not, so this endpoint returned 403 to a role it
        // had routed through. Reconciled to the route.
        $client = User::factory()->create(['role' => 'client']);
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $shoot = $this->makeShoot($client);
        $record = $this->pendingRequestFor($shoot, $client);

        Sanctum::actingAs($manager);
        $this->patchJson("/api/shoots/reschedule-requests/{$record->id}", [
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame(self::REQUESTED_DATE, $shoot->refresh()->scheduled_date->toDateString());
    }

    // --- Idempotency ------------------------------------------------------

    public function test_repeated_approval_is_idempotent(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->makeShoot($client);
        $record = $this->pendingRequestFor($shoot, $client);

        Sanctum::actingAs($admin);
        $url = "/api/shoots/reschedule-requests/{$record->id}";

        $this->patchJson($url, ['status' => 'approved'])->assertOk()->assertJsonPath('applied', true);
        $appliedAt = $record->refresh()->applied_at;

        // Second and third approval must not re-apply or re-notify.
        $this->patchJson($url, ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('applied', false)
            ->assertJsonPath('already_applied', true);

        $this->patchJson($url, ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('applied', false);

        $record->refresh();
        $this->assertEquals($appliedAt, $record->applied_at, 'applied_at must not move on re-approval.');
        $this->assertSame(self::REQUESTED_DATE, $shoot->refresh()->scheduled_date->toDateString());
    }

    public function test_an_approved_request_cannot_be_flipped_to_rejected(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->makeShoot($client);
        $record = $this->pendingRequestFor($shoot, $client);

        Sanctum::actingAs($admin);
        $url = "/api/shoots/reschedule-requests/{$record->id}";

        $this->patchJson($url, ['status' => 'approved'])->assertOk();
        // Rejecting after the change is live would leave the shoot on the new
        // date with a "rejected" record — a lie either way.
        $this->patchJson($url, ['status' => 'rejected'])->assertStatus(409);

        $this->assertTrue($record->refresh()->isApproved());
    }

    // --- Rejection --------------------------------------------------------

    public function test_rejection_leaves_the_shoot_unchanged(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->makeShoot($client);
        $record = $this->pendingRequestFor($shoot, $client);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/shoots/reschedule-requests/{$record->id}", [
            'status' => 'rejected',
            'review_notes' => 'Photographer unavailable that week.',
        ])->assertOk()->assertJsonPath('applied', false);

        $shoot->refresh();
        $this->assertSame(self::ORIGINAL_DATE, $shoot->scheduled_date->toDateString());
        $this->assertSame(self::ORIGINAL_TIME, $shoot->time);

        $record->refresh();
        $this->assertTrue($record->isRejected());
        $this->assertNull($record->applied_at);
        $this->assertSame('Photographer unavailable that week.', $record->review_notes);
    }

    public function test_a_rejected_request_cannot_later_be_approved(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->makeShoot($client);
        $record = $this->pendingRequestFor($shoot, $client);

        Sanctum::actingAs($admin);
        $url = "/api/shoots/reschedule-requests/{$record->id}";

        $this->patchJson($url, ['status' => 'rejected'])->assertOk();
        $this->patchJson($url, ['status' => 'approved'])->assertStatus(409);

        $this->assertSame(self::ORIGINAL_DATE, $shoot->refresh()->scheduled_date->toDateString());
        $this->assertNull($record->refresh()->applied_at);
    }

    // --- Authorization ----------------------------------------------------

    public function test_a_client_cannot_approve_their_own_request(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = $this->makeShoot($client);
        $record = $this->pendingRequestFor($shoot, $client);

        Sanctum::actingAs($client);
        $this->patchJson("/api/shoots/reschedule-requests/{$record->id}", [
            'status' => 'approved',
        ])->assertForbidden();

        $this->assertSame(self::ORIGINAL_DATE, $shoot->refresh()->scheduled_date->toDateString());
        $this->assertTrue($record->refresh()->isPending());
    }

    public function test_a_photographer_cannot_approve_a_request(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot = $this->makeShoot($client);
        $record = $this->pendingRequestFor($shoot, $client);

        Sanctum::actingAs($photographer);
        $this->patchJson("/api/shoots/reschedule-requests/{$record->id}", [
            'status' => 'approved',
        ])->assertForbidden();

        $this->assertTrue($record->refresh()->isPending());
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = $this->makeShoot($client);
        $record = $this->pendingRequestFor($shoot, $client);

        $this->patchJson("/api/shoots/reschedule-requests/{$record->id}", [
            'status' => 'approved',
        ])->assertUnauthorized();

        $this->assertTrue($record->refresh()->isPending());
    }

    // --- Visibility -------------------------------------------------------

    public function test_the_request_list_exposes_the_status_for_the_ui(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->makeShoot($client);
        $this->pendingRequestFor($shoot, $client);

        Sanctum::actingAs($admin);
        $this->getJson("/api/shoots/{$shoot->id}/reschedule-requests")
            ->assertOk()
            ->assertJsonPath('data.0.status', ShootRescheduleRequest::STATUS_PENDING)
            ->assertJsonPath('data.0.requested_date', self::REQUESTED_DATE . 'T00:00:00.000000Z');
    }
}
