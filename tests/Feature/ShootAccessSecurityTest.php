<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootMessage;
use App\Models\User;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootIssueParsingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootAccessSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function shoot(array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ], $attributes));
    }

    private function file(Shoot $shoot, array $attributes = []): ShootFile
    {
        return $shoot->files()->create(array_merge([
            'filename' => 'security-photo.jpg',
            'stored_filename' => 'security-photo.jpg',
            'path' => 'security/security-photo.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 100,
            'uploaded_by' => $shoot->photographer_id,
            'uploaded_at' => now(),
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'url' => 'https://media.example.test/security-photo.jpg',
            'is_hidden' => false,
            'sort_order' => 1,
        ], $attributes));
    }

    public function test_unrelated_roles_cannot_read_shoots_or_their_auxiliary_endpoints(): void
    {
        $shoot = $this->shoot();
        foreach (['salesRep', 'sales_rep', 'rep', 'representative', 'editor', 'photographer', 'client', 'unknown_role'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            foreach (['', '/files', '/media', '/messages', '/workflow-status', '/issues', '/reschedule-requests'] as $suffix) {
                $this->getJson("/api/shoots/{$shoot->id}{$suffix}")->assertForbidden();
            }
        }
    }

    public function test_denied_writes_leave_database_and_side_effects_untouched(): void
    {
        $shoot = $this->shoot(['is_flagged' => true, 'workflow_status' => Shoot::STATUS_ON_HOLD]);
        $file = $this->file($shoot);
        $before = $shoot->fresh()->getAttributes();
        Queue::fake();
        Event::fake();
        Mail::fake();
        Notification::fake();
        foreach (['salesRep', 'editor', 'photographer', 'client', 'unknown_role'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->postJson("/api/shoots/{$shoot->id}/messages", ['recipient_id' => $shoot->client_id, 'message' => 'forbidden'])->assertForbidden();
            $this->postJson("/api/shoots/{$shoot->id}/issues", ['note' => 'forbidden'])->assertForbidden();
            $this->patchJson("/api/shoots/{$shoot->id}/issues/999999", ['status' => 'resolved'])->assertForbidden();
            $this->postJson("/api/shoots/{$shoot->id}/mark-issues-resolved")->assertForbidden();
            $this->postJson("/api/shoots/{$shoot->id}/reschedule", ['requested_date' => '2026-10-15'])->assertForbidden();
            $this->postJson("/api/shoots/{$shoot->id}/files/{$file->id}/move-to-completed")->assertForbidden();
            $this->postJson("/api/shoots/{$shoot->id}/media/{$file->id}/cover")->assertForbidden();
            $this->postJson("/api/shoots/{$shoot->id}/media/reorder", ['files' => [['id' => $file->id, 'sort_order' => 7]]])->assertForbidden();
            $this->patchJson("/api/shoots/{$shoot->id}/files/reorder", ['file_ids' => [$file->id]])->assertForbidden();
        }
        $this->assertSame($before, $shoot->fresh()->getAttributes());
        $this->assertSame(1, $file->fresh()->sort_order);
        $this->assertDatabaseCount('shoot_messages', 0);
        $this->assertDatabaseCount('shoot_reschedule_requests', 0);
        Queue::assertNothingPushed();
        Event::assertNotDispatched(\App\Events\ShootActivityBroadcast::class);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_photographer_listing_is_role_and_assignment_scoped(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $own = $this->shoot(['photographer_id' => $photographer->id]);
        $other = $this->shoot();
        Sanctum::actingAs($photographer);
        $response = $this->getJson('/api/photographer/shoots')->assertOk();
        $this->assertSame([$own->id], array_column($response->json('data'), 'id'));
        Sanctum::actingAs($own->client);
        $this->getJson('/api/photographer/shoots')->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'salesRep']));
        $this->getJson('/api/photographer/shoots')->assertForbidden();
    }

    public function test_assigned_sales_aliases_have_details_access_and_revocation_is_immediate(): void
    {
        foreach (['salesRep', 'sales_rep', 'rep', 'representative'] as $role) {
            $rep = User::factory()->create(['role' => $role]);
            $shoot = $this->shoot(['rep_id' => $rep->id]);
            Sanctum::actingAs($rep);
            $this->getJson("/api/shoots/{$shoot->id}")->assertOk();
            $this->getJson("/api/shoots/{$shoot->id}/files")->assertOk();
            $shoot->update(['rep_id' => null]);
            $this->getJson("/api/shoots/{$shoot->id}")->assertForbidden();
            $this->getJson("/api/shoots/{$shoot->id}/files")->assertForbidden();
        }
    }

    public function test_messages_are_private_to_participants_and_recipients_require_assignment(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $shoot = $this->shoot(['rep_id' => $rep->id]);
        $other = User::factory()->create(['role' => 'photographer']);
        $own = ShootMessage::create(['shoot_id' => $shoot->id, 'sender_id' => $shoot->client_id, 'recipient_id' => $rep->id, 'message' => 'visible']);
        ShootMessage::create(['shoot_id' => $shoot->id, 'sender_id' => $shoot->client_id, 'recipient_id' => $shoot->photographer_id, 'message' => 'private']);
        Sanctum::actingAs($rep);
        $this->getJson("/api/shoots/{$shoot->id}/messages")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->postJson("/api/shoots/{$shoot->id}/messages", ['recipient_id' => $other->id, 'message' => 'no'])->assertUnprocessable();
        $this->assertDatabaseCount('shoot_messages', 2);
        $this->postJson("/api/shoots/{$shoot->id}/messages", ['recipient_id' => $shoot->client_id, 'message' => 'yes'])->assertCreated();
        $shoot->update(['rep_id' => null]);
        $this->postJson("/api/shoots/messages/{$own->id}/read")->assertForbidden();
        $this->assertNull($own->fresh()->read_at);
    }

    public function test_issue_media_ids_and_assignees_cannot_reference_another_shoot(): void
    {
        $shoot = $this->shoot();
        $other = $this->shoot();
        $foreignFile = $this->file($other);
        Sanctum::actingAs($shoot->client);
        $this->postJson("/api/shoots/{$shoot->id}/issues", ['note' => 'test', 'mediaIds' => [$foreignFile->id]])->assertUnprocessable();
        $this->assertNull($shoot->fresh()->admin_issue_notes);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson("/api/shoots/{$shoot->id}/issues", ['note' => 'test', 'assignedToRole' => 'photographer', 'assignedToUserId' => $other->photographer_id])->assertUnprocessable();
        $this->assertNull($shoot->fresh()->admin_issue_notes);
    }

    public function test_service_assignments_limit_details_files_workflow_and_mutations(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot = $this->shoot();
        $serviceA = Service::factory()->create();
        $serviceB = Service::factory()->create();
        $shoot->services()->attach($serviceA->id, ['price' => 100, 'quantity' => 1, 'photographer_id' => $photographer->id]);
        $shoot->services()->attach($serviceB->id, ['price' => 100, 'quantity' => 1, 'photographer_id' => $shoot->photographer_id]);
        $itemA = $shoot->serviceItems()->where('service_id', $serviceA->id)->firstOrFail();
        $itemB = $shoot->serviceItems()->where('service_id', $serviceB->id)->firstOrFail();
        $visible = $this->file($shoot, ['shoot_service_id' => $itemA->id]);
        $hidden = $this->file($shoot, ['shoot_service_id' => $itemB->id, 'filename' => 'other-lane.jpg']);
        $shoot->update(['hero_image' => 'https://media.example.test/other-lane.jpg', 'edited_photo_count' => 2]);
        Sanctum::actingAs($photographer);
        $this->getJson("/api/shoots/{$shoot->id}")->assertOk()->assertJsonCount(1, 'data.files')->assertJsonPath('data.files.0.id', $visible->id)
            ->assertJsonPath('data.edited_photo_count', 1)->assertJsonMissing(['hero_image' => 'https://media.example.test/other-lane.jpg']);
        $this->getJson("/api/shoots/{$shoot->id}/workflow-status")->assertOk()->assertJsonPath('file_stats.total', 1)->assertJsonCount(0, 'workflow_logs');
        $this->getJson("/api/shoots/{$shoot->id}/files")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/shoots/{$shoot->id}/media?type=edited")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('counts.edited_photo_count', 1);
        $this->postJson("/api/shoots/{$shoot->id}/media/{$hidden->id}/cover")->assertForbidden();
        $this->patchJson("/api/shoots/{$shoot->id}/files/reorder", ['file_ids' => [$visible->id, $hidden->id]])->assertForbidden();
        $this->postJson("/api/shoots/{$shoot->id}/media/reorder", ['files' => [['id' => $visible->id, 'sort_order' => 4], ['id' => $hidden->id, 'sort_order' => 5]]])->assertForbidden();
        $this->assertSame(1, $visible->fresh()->sort_order);
        $this->assertSame(1, $hidden->fresh()->sort_order);
    }

    public function test_client_hidden_media_and_raw_files_are_not_returned_by_any_media_list(): void
    {
        $shoot = $this->shoot();
        $visible = $this->file($shoot);
        $hidden = $this->file($shoot, ['is_hidden' => true]);
        $raw = $this->file($shoot, ['workflow_stage' => ShootFile::STAGE_TODO]);
        Sanctum::actingAs($shoot->client);
        $this->getJson("/api/shoots/{$shoot->id}/files")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/shoots/{$shoot->id}/media?type=edited")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/shoots/{$shoot->id}/media?type=raw")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/shoots/{$shoot->id}/files/{$hidden->id}/preview")->assertForbidden();
        $this->getJson("/api/shoots/{$shoot->id}/media/{$hidden->id}/download")->assertForbidden();
        $authorization = app(ShootAuthorizationSupport::class);
        $this->assertFalse($authorization->canInteractWithShootMediaFile($this->shoot(), $visible, $shoot->client));
    }

    public function test_issue_status_changes_require_the_assigned_participant_and_return_only_visible_media(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $otherEditor = User::factory()->create(['role' => 'editor']);
        $shoot = $this->shoot(['editor_id' => $editor->id]);
        $file = $this->file($shoot);
        $issues = app(ShootIssueParsingService::class);
        $issues->appendIssueRequest($shoot, $issues->buildRequestEntry($shoot->client, 'Own request', [$file->id], 'own_issue', 'open', 'editor', $editor->id));
        $issues->appendIssueRequest($shoot, $issues->buildRequestEntry($shoot->client, 'Other request', [], 'other_issue', 'open', 'editor', $otherEditor->id));
        Sanctum::actingAs($editor);
        $this->getJson("/api/shoots/{$shoot->id}/issues")->assertOk()->assertJsonCount(1, 'data');
        $before = $shoot->fresh()->admin_issue_notes;
        $this->patchJson("/api/shoots/{$shoot->id}/issues/other_issue", ['status' => 'resolved'])->assertNotFound();
        $this->assertSame($before, $shoot->fresh()->admin_issue_notes);
        $this->patchJson("/api/shoots/{$shoot->id}/issues/own_issue", ['status' => 'in-progress'])->assertOk()
            ->assertJsonPath('data.status', 'in-progress')->assertJsonPath('data.mediaFiles.0.id', (string) $file->id);
        Sanctum::actingAs($shoot->client);
        $this->patchJson("/api/shoots/{$shoot->id}/issues/own_issue", ['status' => 'resolved'])->assertForbidden();
    }
}
