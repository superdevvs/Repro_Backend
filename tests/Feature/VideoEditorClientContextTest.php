<?php

namespace Tests\Feature;

use App\Http\Resources\ShootResource;
use App\Models\{Service, Shoot, ShootEditingDispatch, ShootService, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Queue, Storage};
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VideoEditorClientContextTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Queue::fake();
        Storage::fake('public');
        config(['media.read_from_r2' => false, 'media.r2_only' => false, 'media.dual_write' => false]);
        $client = User::factory()->create([
            'role' => 'client', 'name' => 'Video Brief Client', 'email' => 'private-client@example.test',
            'phonenumber' => '555-123-9988', 'company_name' => 'Private Realty',
        ]);
        $video = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['video']]]);
        $photo = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id, 'editor_id' => null,
            'status' => Shoot::STATUS_EDITING, 'workflow_status' => Shoot::STATUS_EDITING,
            'company_notes' => 'Use the current company video branding.',
            'shoot_notes' => 'Private access instructions.', 'photographer_notes' => 'Private onsite instructions.',
            'editor_notes' => 'Cut a 60-second video.', 'base_quote' => 900, 'total_quote' => 954,
        ]);
        $item = ShootService::create([
            'shoot_id' => $shoot->id, 'service_id' => Service::factory()->photoVideoIntake()->create(['requires_editing' => true])->id,
            'editor_id' => $photo->id, 'video_editor_id' => $video->id, 'quantity' => 1, 'price' => 900,
        ]);
        foreach (['company', 'editing', 'shoot', 'photographer', 'approval'] as $type) {
            $shoot->notes()->create(['type' => $type, 'visibility' => 'internal', 'content' => $type.' history', 'author_id' => $client->id]);
        }
        Sanctum::actingAs($video);

        return [$shoot, $item, $video, $photo, $client];
    }

    public function test_assigned_video_editor_sees_name_and_company_notes_on_detail_list_cards_resource_and_history(): void
    {
        [$shoot, , $video, , $client] = $this->fixture();
        $this->assertFalse($shoot->relationLoaded('serviceItems'));
        $this->assertTrue(app(\App\Services\Shoots\ShootAuthorizationSupport::class)->canVideoEditorReadClientContext($shoot, $video));
        $this->assertFalse($shoot->relationLoaded('serviceItems'));
        $detail = $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->json('data');
        $list = $this->getJson('/api/shoots?tab=completed&no_cache=true')->assertOk()->json('data.0');
        $card = $this->getJson('/api/shoots?tab=completed&view=card&no_cache=true')->assertOk()->json('data.0');
        $request = Request::create('/api/shoots/'.$shoot->id);
        $request->setUserResolver(fn () => $video);
        $resource = (new ShootResource($shoot->fresh()))->resolve($request);
        foreach ([$detail, $list, $card, $resource] as $payload) {
            $this->assertTrue($payload['can_view_video_editor_context']);
            $this->assertTrue($payload['canViewVideoEditorContext']);
            $this->assertSame($client->name, $payload['client']['name']);
            $this->assertSame(['id', 'name'], array_keys($payload['client']));
            $this->assertSame($shoot->company_notes, $payload['company_notes']);
            $this->assertStringNotContainsString($client->email, json_encode($payload, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString($client->phonenumber, json_encode($payload, JSON_THROW_ON_ERROR));
        }
        $this->assertNull($detail['shoot_notes']);
        $this->assertNull($detail['photographer_notes']);
        $this->assertNull($detail['services'][0]['price']);
        $this->assertNull($detail['service_items'][0]['price']);
        $this->assertEquals(0, $resource['orderTotal']);

        $shoot->update(['status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED]);
        $history = $this->getJson('/api/shoots/history')->assertOk()->json('data.0');
        $this->assertTrue($history['canViewVideoEditorContext']);
        $this->assertSame(['id' => $client->id, 'name' => $client->name], $history['client']);
        $this->assertSame($shoot->company_notes, $history['notes']['company']);
        $this->assertStringNotContainsString($client->email, json_encode($history, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($client->phonenumber, json_encode($history, JSON_THROW_ON_ERROR));
        $this->assertNull($history['notes']['shoot']);
        $this->assertEquals(0, $history['financials']['totalQuote']);
    }

    public function test_company_note_history_is_read_only_and_other_note_categories_stay_private(): void
    {
        [$shoot] = $this->fixture();
        $notes = $this->getJson('/api/shoots/'.$shoot->id.'/notes')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['editing', 'company'], array_column($notes, 'type'));
        $this->patchJson('/api/shoots/'.$shoot->id.'/notes', ['company_notes' => 'Changed'])->assertForbidden();
        $this->postJson('/api/shoots/'.$shoot->id.'/notes', [
            'type' => 'company', 'visibility' => 'internal', 'content' => 'Changed',
        ])->assertForbidden();
        $this->patchJson('/api/shoots/'.$shoot->id, ['company_notes' => 'Changed'])->assertForbidden();
        $this->assertSame($shoot->company_notes, $shoot->fresh()->company_notes);
    }

    public function test_search_matches_only_the_client_name_visible_to_the_assigned_video_editor(): void
    {
        [$shoot, $item, $video, $photo, $client] = $this->fixture();
        $search = app(\App\Services\Shoots\ShootSearchFilter::class);
        $this->assertSame(1, $search->apply(Shoot::query()->whereKey($shoot->id), $client->name)->count());
        $this->assertSame(0, $search->apply(Shoot::query()->whereKey($shoot->id), $client->email)->count());
        $this->assertSame(0, $search->apply(Shoot::query()->whereKey($shoot->id), $client->company_name)->count());
        $item->update(['video_editor_id' => $photo->id, 'editor_id' => $video->id]);
        $this->assertSame(0, $search->apply(Shoot::query()->whereKey($shoot->id), $client->name)->count());
    }

    public function test_photo_lane_and_unspecified_legacy_editors_keep_client_context_hidden(): void
    {
        [$shoot, , , $photo] = $this->fixture();
        foreach ([['editing_capabilities' => ['photo']], []] as $metadata) {
            $photo->update(['metadata' => $metadata]);
            Sanctum::actingAs($photo);
            $detail = $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->json('data');
            $this->assertFalse($detail['canViewVideoEditorContext']);
            $this->assertNull($detail['client']);
            $this->assertNull($detail['company_notes']);
            $this->getJson('/api/shoots/'.$shoot->id.'/notes')->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'editing');
        }
    }

    public function test_reassignment_revokes_context_even_while_the_previous_video_editor_keeps_photo_work(): void
    {
        [$shoot, $item, $video, $photo] = $this->fixture();
        $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.canViewVideoEditorContext', true);
        $item->update(['video_editor_id' => $photo->id, 'editor_id' => $video->id]);
        $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.canViewVideoEditorContext', false)
            ->assertJsonPath('data.client', null)->assertJsonPath('data.company_notes', null);
        $this->getJson('/api/shoots?tab=completed&view=card')->assertOk()->assertJsonPath('data.0.canViewVideoEditorContext', false);
        $this->getJson('/api/shoots/'.$shoot->id.'/notes')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'editing');
        $shoot->update(['status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED]);
        $this->getJson('/api/shoots/history')->assertOk()->assertJsonPath('data.0.canViewVideoEditorContext', false)
            ->assertJsonPath('data.0.client', null)->assertJsonPath('data.0.notes.company', null);
        $item->update(['editor_id' => $photo->id]);
        $this->getJson('/api/shoots/'.$shoot->id)->assertForbidden();
        $this->getJson('/api/shoots/'.$shoot->id.'/notes')->assertForbidden();
    }

    public function test_dedicated_video_assignment_does_not_require_capability_metadata_and_legacy_video_assignment_still_works(): void
    {
        [$shoot, $item, $video] = $this->fixture();
        $video->update(['metadata' => []]);
        $item->update(['service_id' => Service::factory()->videoIntake()->create(['requires_editing' => true])->id,
            'editor_id' => $video->id, 'video_editor_id' => null]);
        $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.canViewVideoEditorContext', true);
        $item->delete();
        $shoot->update(['editor_id' => $video->id]);
        $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.canViewVideoEditorContext', false);
        $video->update(['metadata' => ['editing_capabilities' => ['video']]]);
        $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.canViewVideoEditorContext', true);
    }

    public function test_stale_video_editor_column_on_photo_service_does_not_grant_context(): void
    {
        [$shoot, $item] = $this->fixture();
        $item->update(['service_id' => Service::factory()->unbracketedPhoto()->create(['requires_editing' => true])->id]);
        $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.canViewVideoEditorContext', false)
            ->assertJsonPath('data.client', null)->assertJsonPath('data.company_notes', null);
    }

    public function test_scoped_video_task_exposes_a_narrow_brief_without_granting_full_shoot_access_and_revokes_on_reassignment(): void
    {
        [$shoot, $item, $video, $photo, $client] = $this->fixture();
        $item->update(['video_editor_id' => $photo->id]);
        $dispatch = ShootEditingDispatch::create([
            'request_id' => (string) Str::uuid(), 'shoot_id' => $shoot->id, 'created_by' => $client->id,
            'scope' => 'selected', 'destination' => 'human', 'workflow' => 'human-video',
            'input_hash' => str_repeat('a', 64), 'plan' => [], 'status' => 'ready',
        ]);
        $task = $dispatch->items()->create([
            'input_key' => 'video:1', 'workflow' => 'human-video', 'sources' => [],
            'lane' => 'video', 'destination' => 'human', 'editor_id' => $video->id, 'status' => 'ready',
        ]);
        // Keep a separate photo task after video reassignment to test a still-visible summary.
        $dispatch->items()->create([
            'input_key' => 'photo:1', 'workflow' => 'human-photo', 'sources' => [],
            'lane' => 'photo', 'destination' => 'human', 'editor_id' => $video->id, 'status' => 'ready',
        ]);
        foreach (['/api/editing-tasks?open=1&summary=1', '/api/editing-tasks'] as $url) {
            $summary = $this->getJson($url)->assertOk()->json('data.0.shoot');
            $this->assertTrue($summary['canViewVideoEditorContext']);
            $this->assertSame($client->name, $summary['clientName']);
            $this->assertSame($shoot->company_notes, $summary['companyNotes']);
            $this->assertArrayNotHasKey('client', $summary);
        }
        $this->getJson('/api/shoots/'.$shoot->id)->assertForbidden();
        $this->getJson('/api/shoots/'.$shoot->id.'/notes')->assertForbidden();
        $task->update(['status' => 'completed']);
        $this->getJson('/api/editing-tasks')->assertOk()->assertJsonPath('data.0.shoot.canViewVideoEditorContext', false);
        $task->update(['status' => 'ready', 'editor_id' => $photo->id]);
        $this->getJson('/api/editing-tasks?open=1&summary=1')->assertOk()
            ->assertJsonPath('data.0.shoot.canViewVideoEditorContext', false)
            ->assertJsonPath('data.0.shoot.clientName', null)->assertJsonPath('data.0.shoot.companyNotes', null);
    }
}
