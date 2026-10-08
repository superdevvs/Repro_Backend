<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\ShootIssueParsingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EditorClientRequestInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_editors_see_the_shared_editor_queue_without_unrelated_media_or_write_access(): void
    {
        Queue::fake();
        $editor = User::factory()->create(['role' => 'editor']);
        $otherEditor = User::factory()->create(['role' => 'editor']);
        $parser = app(ShootIssueParsingService::class);
        $own = Shoot::factory()->create(['editor_id' => $editor->id]);
        $other = Shoot::factory()->create(['editor_id' => $otherEditor->id]);
        $file = ShootFile::create(['shoot_id' => $other->id, 'filename' => 'photo.jpg', 'stored_filename' => 'photo.jpg', 'path' => 'shoots/test/photo.jpg', 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => 100, 'uploaded_by' => $otherEditor->id, 'workflow_stage' => 'verified', 'scan_status' => 'clean']);
        $parser->appendIssueRequest($own, $parser->buildRequestEntry($own->client, 'Own editing request', [], 'own', 'open', 'editor'));
        $parser->appendIssueRequest($other, $parser->buildRequestEntry($other->client, 'Team editing request', [$file->id], 'team', 'open', 'editor'));
        $parser->appendIssueRequest($other, $parser->buildRequestEntry($other->client, 'Assigned editor request', [], 'specific', 'open', 'editor', $otherEditor->id));
        $parser->appendIssueRequest($other, $parser->buildRequestEntry($other->client, 'Photographer request', [], 'photographer', 'open', 'photographer'));
        $parser->appendIssueRequest($other, $parser->buildRequestEntry($other->client, 'General request', [], 'general'));
        $parser->appendIssueRequest($other, $parser->buildRequestEntry($other->client, 'Dismissed request', [], 'dismissed', 'dismissed', 'editor'));
        Shoot::factory()->create(['status' => Shoot::STATUS_IMPORT_DRAFT, 'workflow_status' => Shoot::STATUS_IMPORT_DRAFT, 'admin_issue_notes' => '[Request from Client]: Private draft request'."\n[Assigned: editor]"]);

        Sanctum::actingAs($editor);
        $response = $this->getJson('/api/client-requests')->assertOk()->assertJsonCount(3, 'data');
        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($rows['own']['canOpenShoot']);
        $this->assertTrue($rows['own']['canUpdate']);
        $this->assertFalse($rows['team']['canOpenShoot']);
        $this->assertFalse($rows['team']['canUpdate']);
        $this->assertSame([], $rows['team']['mediaFiles']);
        $this->assertSame([], $rows['team']['mediaIds']);
        $this->assertFalse($rows['specific']['canUpdate']);
        $this->getJson("/api/shoots/{$other->id}/issues")->assertForbidden();
        $this->patchJson("/api/shoots/{$other->id}/issues/team", ['status' => 'resolved'])->assertForbidden();
        $this->postJson("/api/shoots/{$other->id}/issues/team/assign", ['assignedToRole' => 'editor'])->assertForbidden();

        Sanctum::actingAs($otherEditor);
        $this->getJson('/api/client-requests')->assertOk()->assertJsonCount(3, 'data');
    }
}
