<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootRescheduleRequest;
use App\Models\User;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootIssueParsingService;
use App\Services\Shoots\ShootWorkflowTransitionSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesRepRequestAccessTest extends TestCase
{
    use RefreshDatabase;

    private function reviewerAttributes(): array
    {
        return [
            ['role' => 'salesRep'],
            ['role' => 'sales_rep'],
            ['role' => 'rep'],
            ['role' => 'representative'],
            ['role' => 'photographer', 'secondary_roles' => ['sales_rep']],
            ['role' => 'editor', 'secondary_roles' => ['salesRep']],
        ];
    }

    private function user(array $attributes): User
    {
        $user = User::factory()->create($attributes);
        $user->forceFill(['email_verified_at' => now(), 'email_verification_required_at' => null])->save();

        return $user;
    }

    private function shoot(array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ], $attributes));
    }

    public function test_sales_aliases_and_secondary_roles_can_triage_the_shared_client_request_inbox(): void
    {
        $shoot = $this->shoot();
        $parser = app(ShootIssueParsingService::class);
        $parser->appendIssueRequest($shoot, $parser->buildRequestEntry($shoot->client, 'Please arrange access', [], 'client_request'));
        $parser->appendIssueRequest($shoot, $parser->buildRequestEntry($shoot->client, 'Please retake the exterior', [], 'fulfilment_request', 'open', 'photographer', $shoot->photographer_id));

        foreach ($this->reviewerAttributes() as $attributes) {
            $rep = $this->user($attributes);
            Sanctum::actingAs($rep);
            $this->getJson('/api/client-requests')->assertOk()->assertJsonCount(2, 'data');
            $this->getJson("/api/shoots/{$shoot->id}/issues")->assertOk()->assertJsonCount(2, 'data');
            $this->patchJson("/api/shoots/{$shoot->id}/issues/client_request", ['status' => 'in-progress'])
                ->assertOk()->assertJsonPath('data.status', 'in-progress');
            $this->postJson("/api/shoots/{$shoot->id}/issues/fulfilment_request/assign", [
                'assignedToRole' => 'photographer',
                'assignedToUserId' => $shoot->photographer_id,
            ])->assertOk()->assertJsonPath('data.assignedToUser.id', (string) $shoot->photographer_id);
        }

        $this->patchJson("/api/shoots/{$shoot->id}/issues/client_request", ['status' => 'dismissed'])
            ->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->getJson('/api/client-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->fresh()->workflow_status);
    }

    public function test_client_request_review_does_not_grant_unrelated_media_or_workflow_writes(): void
    {
        $shoot = $this->shoot(['is_flagged' => true]);
        $authorization = app(ShootAuthorizationSupport::class);

        foreach ($this->reviewerAttributes() as $attributes) {
            $rep = $this->user($attributes);
            Sanctum::actingAs($rep);
            $this->assertTrue($authorization->canTriageShootRequests($shoot, $rep));
            $this->assertFalse($authorization->canManageShootOperations($rep));
            $this->assertFalse($authorization->canUploadShootMedia($shoot, $rep));
            $this->assertFalse($authorization->canResolveShootIssues($shoot, $rep));
            $this->postJson("/api/shoots/{$shoot->id}/mark-issues-resolved")->assertForbidden();
            $this->postJson("/api/shoots/{$shoot->id}/issues", ['note' => 'Global rep follow-up'])->assertCreated();

            $shoot->update(['rep_id' => $rep->id]);
            $this->postJson("/api/shoots/{$shoot->id}/issues", ['note' => 'Assigned rep follow-up'])->assertCreated();
            $shoot->update(['rep_id' => null]);
        }
    }

    public function test_contractors_receive_only_explicitly_assigned_requests_and_cannot_reassign_them(): void
    {
        $editor = $this->user(['role' => 'editor']);
        $shoot = $this->shoot(['editor_id' => $editor->id]);
        $parser = app(ShootIssueParsingService::class);
        $parser->appendIssueRequest($shoot, $parser->buildRequestEntry($shoot->client, 'General client request', [], 'general'));
        $parser->appendIssueRequest($shoot, $parser->buildRequestEntry($shoot->client, 'Photographer follow-up', [], 'photographer', 'open', 'photographer', $shoot->photographer_id));
        $parser->appendIssueRequest($shoot, $parser->buildRequestEntry($shoot->client, 'Editing follow-up', [], 'editor', 'open', 'editor', $editor->id));

        foreach ([[$shoot->photographer, 'photographer'], [$editor, 'editor']] as [$contractor, $issueId]) {
            Sanctum::actingAs($contractor);
            $this->getJson('/api/client-requests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $issueId);
            $this->patchJson("/api/shoots/{$shoot->id}/issues/{$issueId}", ['status' => 'in-progress'])->assertOk();
            $this->postJson("/api/shoots/{$shoot->id}/issues/{$issueId}/assign", ['assignedToRole' => 'photographer'])->assertForbidden();
        }

        Sanctum::actingAs($this->user(['role' => 'salesRep']));
        $this->postJson("/api/shoots/{$shoot->id}/issues/general/assign", [
            'assignedToRole' => 'photographer',
            'assignedToUserId' => $this->user(['role' => 'photographer'])->id,
        ])->assertUnprocessable();
    }

    public function test_sales_review_queues_include_aliases_and_secondary_roles_but_exclude_contractors(): void
    {
        $shoot = $this->shoot(['cancellation_requested_at' => now(), 'hold_requested_at' => now()]);
        $reschedule = ShootRescheduleRequest::create([
            'shoot_id' => $shoot->id,
            'requested_by' => $shoot->client_id,
            'original_date' => '2026-10-01',
            'requested_date' => '2026-10-08',
            'status' => ShootRescheduleRequest::STATUS_PENDING,
        ]);

        foreach ($this->reviewerAttributes() as $attributes) {
            Sanctum::actingAs($this->user($attributes));
            $this->getJson('/api/shoots/pending-cancellations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $shoot->id);
            $this->getJson('/api/shoots/pending-holds')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $shoot->id);
            $this->getJson('/api/shoots/pending-reschedules?status=pending')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $reschedule->id);
        }

        Sanctum::actingAs($shoot->photographer);
        foreach (['pending-cancellations', 'pending-holds', 'pending-reschedules'] as $queue) {
            $this->getJson("/api/shoots/{$queue}")->assertForbidden();
        }
    }

    public function test_secondary_sales_reviewer_can_decide_requests_without_a_contractor_assignment(): void
    {
        $this->mock(ShootWorkflowTransitionSupportService::class)
            ->shouldReceive('sendCancellationRejectionSideEffects')->once();
        $rep = $this->user(['role' => 'photographer', 'secondary_roles' => ['sales_rep']]);
        $shoot = $this->shoot(['hold_requested_at' => now(), 'cancellation_requested_at' => now()]);
        Sanctum::actingAs($rep);
        $this->postJson("/api/shoots/{$shoot->id}/reject-hold", ['reason' => 'Ready to proceed'])->assertOk();
        $this->assertNull($shoot->fresh()->hold_requested_at);

        $this->postJson("/api/shoots/{$shoot->id}/reject-cancellation")->assertOk();
        $this->assertNull($shoot->fresh()->cancellation_requested_at);
        // A missing request reaches the existing business-rule check after authorization.
        $this->postJson("/api/shoots/{$shoot->id}/approve-cancellation")->assertUnprocessable();

        $reschedule = ShootRescheduleRequest::create([
            'shoot_id' => $shoot->id,
            'requested_by' => $shoot->client_id,
            'requested_date' => '2026-10-08',
            'status' => ShootRescheduleRequest::STATUS_PENDING,
        ]);
        $this->patchJson("/api/shoots/reschedule-requests/{$reschedule->id}", ['status' => 'rejected'])
            ->assertOk()->assertJsonPath('data.status', ShootRescheduleRequest::STATUS_REJECTED);
        $this->assertSame($rep->id, $reschedule->fresh()->approved_by);
    }

    public function test_sales_request_review_keeps_private_import_drafts_hidden(): void
    {
        $draft = $this->shoot([
            'status' => Shoot::STATUS_IMPORT_DRAFT,
            'workflow_status' => Shoot::STATUS_IMPORT_DRAFT,
            'admin_issue_notes' => '[Request from Private Client]: Private draft request',
            'is_flagged' => true,
            'cancellation_requested_at' => now(),
            'hold_requested_at' => now(),
        ]);
        ShootRescheduleRequest::create([
            'shoot_id' => $draft->id,
            'requested_by' => $draft->client_id,
            'requested_date' => '2026-10-08',
            'status' => ShootRescheduleRequest::STATUS_PENDING,
        ]);

        foreach ($this->reviewerAttributes() as $attributes) {
            $rep = $this->user($attributes);
            Sanctum::actingAs($rep);
            $authorization = app(ShootAuthorizationSupport::class);
            $this->assertFalse($authorization->canViewShootRequests($draft, $rep));
            $this->assertFalse($authorization->scopeAccessibleShootRequests(Shoot::withoutGlobalScopes(), $rep)->whereKey($draft->id)->exists());
            foreach (['/api/client-requests', '/api/shoots/pending-cancellations', '/api/shoots/pending-holds', '/api/shoots/pending-reschedules'] as $endpoint) {
                $this->getJson($endpoint)->assertOk()->assertJsonCount(0, 'data');
            }
            $this->getJson("/api/shoots/{$draft->id}/issues")->assertNotFound();
        }
    }
}
