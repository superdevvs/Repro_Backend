<?php

namespace Tests\Feature;

use App\Jobs\ProcessFalEditingJob;
use App\Models\AccountLink;
use App\Models\AiChatSession;
use App\Models\AiEditingJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\ReproAi\Flows\EditPhotosFlow;
use App\Services\ReproAi\ToolDispatcher;
use App\Services\ReproAi\Tools\AiEditingTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RobbieDashboardEditingAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        Storage::fake('public');
    }

    public function test_dashboard_does_not_accept_model_or_context_identity_over_signed_in_actor(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $own = Shoot::factory()->create(['client_id' => $client->id, 'total_quote' => 123]);
        $other = Shoot::factory()->create(['address' => 'Private unrelated address', 'total_quote' => 98765]);
        $this->actingAs($client);
        $result = $this->tool('get_dashboard_stats', ['user_id' => $admin->id], ['user_id' => $admin->id, 'user_role' => 'admin']);
        $this->assertTrue($result['success']);
        $this->assertSame([$own->id], array_column($result['recent_shoots'], 'id'));
        $this->assertEquals(123, $result['stats']['total_quoted']);
        $this->assertStringNotContainsString($other->address, json_encode($result));
    }

    public function test_shared_shoot_and_assigned_photographer_stats_exclude_private_billing(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id, 'total_quote' => 98765,
            'status' => 'scheduled', 'workflow_status' => 'scheduled']);
        AccountLink::create(['main_account_id' => $client->id, 'linked_account_id' => $shoot->client_id, 'shared_details' => ['shoots' => true], 'status' => 'active', 'created_by' => $client->id]);
        foreach ([$client, $photographer] as $actor) {
            $this->actingAs($actor);
            $result = $this->tool('get_dashboard_stats');
            $this->assertSame([$shoot->id], array_column($result['recent_shoots'], 'id'));
            $this->assertNull($result['recent_shoots'][0]['total_quote']);
            $this->assertNull($result['recent_shoots'][0]['total_paid']);
            $this->assertEquals(0, $result['stats']['total_quoted']);
            $this->assertEquals(0, $result['stats']['pending_payments']);
            $this->assertSame([], $result['needs_attention']);
        }
    }

    public function test_guest_and_unverified_voice_cannot_read_stats_even_with_forged_context(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $forged = ['user_id' => $client->id, 'user_role' => 'admin', 'verified' => true];
        $this->assertFalse($this->tool('get_dashboard_stats', ['user_id' => $client->id], $forged)['success']);
        $call = VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'in_progress', 'caller_user_id' => $client->id]);
        $context = [...$forged, 'channel' => 'VOICE', 'voice_call_id' => $call->id];
        $this->assertFalse($this->tool('get_dashboard_stats', [], $context)['success']);
        $call->update(['verified_at' => now()]);
        $this->assertSame([$shoot->id], array_column($this->tool('get_dashboard_stats', [], $context)['recent_shoots'], 'id'));
        $call->update(['status' => 'completed']);
        $this->assertFalse($this->tool('get_dashboard_stats', [], $context)['success']);
    }

    public function test_read_access_and_forged_role_do_not_grant_status_mutations(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled']);
        foreach (['client', 'photographer', 'editor', 'salesRep'] as $role) {
            $client->update(['role' => $role]);
            $this->actingAs($client->fresh());
            $this->assertFalse($this->tool('update_shoot_status', ['shoot_id' => $shoot->id, 'status' => 'cancelled'], ['user_role' => 'admin'])['success']);
        }
        $this->assertSame('scheduled', $shoot->fresh()->status);
        $this->actingAs(User::factory()->create(['role' => 'editing_manager']));
        $this->assertTrue($this->tool('update_shoot_status', ['shoot_id' => $shoot->id, 'status' => 'on_hold'])['success']);
        $this->assertSame('on_hold', $shoot->fresh()->status);
        $this->assertFalse($this->tool('update_shoot_status', ['shoot_id' => $shoot->id, 'workflow_status' => 'invented-state'])['success']);
        $this->assertSame('scheduled', $shoot->fresh()->workflow_status);
    }

    public function test_client_cannot_submit_own_or_shared_paid_editing_even_with_staff_context(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $own = Shoot::factory()->create(['client_id' => $client->id]);
        $other = Shoot::factory()->create();
        AccountLink::create(['main_account_id' => $client->id, 'linked_account_id' => $other->client_id, 'shared_details' => ['shoots' => true], 'status' => 'active', 'created_by' => $client->id]);
        $this->actingAs($client);
        foreach ([$own, $other] as $shoot) {
            $file = $this->file($shoot);
            $this->assertFalse($this->tool('submit_ai_editing', ['shoot_id' => $shoot->id, 'file_ids' => [$file->id]], ['user_role' => 'admin'])['success']);
        }
        $this->assertDatabaseCount('ai_editing_jobs', 0);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_editor_can_only_submit_assigned_shoot_and_permitted_files(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $assigned = Shoot::factory()->create(['editor_id' => $editor->id]);
        $other = Shoot::factory()->create();
        $allowed = $this->file($assigned);
        $hiddenExtra = $this->file($assigned, ['is_extra' => true, 'required_for_editing' => false]);
        $otherFile = $this->file($other);
        $this->actingAs($editor);
        $this->assertFalse($this->tool('submit_ai_editing', ['shoot_id' => $other->id, 'file_ids' => [$otherFile->id]], ['user_role' => 'admin'])['success']);
        $result = $this->tool('submit_ai_editing', ['shoot_id' => $assigned->id, 'file_ids' => [$allowed->id, $hiddenExtra->id, $otherFile->id], 'provider' => 'fal']);
        $this->assertTrue($result['success']);
        $this->assertSame([$allowed->id], array_column($result['jobs'], 'file_id'));
        $this->assertDatabaseCount('ai_editing_jobs', 1);
        $this->assertDatabaseHas('ai_editing_jobs', ['shoot_id' => $assigned->id, 'shoot_file_id' => $allowed->id, 'user_id' => $editor->id]);
        Bus::assertDispatchedTimes(ProcessFalEditingJob::class, 1);
    }

    public function test_admin_editing_can_submit_and_view_jobs_without_accepting_forged_owner(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();
        $file = $this->file($shoot);
        $this->actingAs($admin);
        $result = $this->tool('submit_ai_editing', ['shoot_id' => $shoot->id], ['user_id' => $shoot->client_id]);
        $this->assertTrue($result['success']);
        $job = AiEditingJob::firstOrFail();
        $this->assertSame($admin->id, (int) $job->user_id);
        $this->assertSame($file->id, (int) $job->shoot_file_id);
        $this->assertSame($job->id, $this->tool('get_ai_editing_status', ['job_id' => $job->id])['job']['id']);
    }

    public function test_job_read_requires_actual_operator_owner_and_current_shoot_access(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create(['editor_id' => $editor->id]);
        $job = $this->job($shoot, $editor);
        $this->assertFalse($this->tool('get_ai_editing_status', ['job_id' => $job->id], ['user_id' => $editor->id, 'user_role' => 'admin'])['success']);
        $this->actingAs($other);
        $this->assertFalse($this->tool('get_ai_editing_status', ['job_id' => $job->id], ['user_role' => 'admin'])['success']);
        $this->assertSame([], $this->tool('get_ai_editing_status', ['shoot_id' => $shoot->id])['jobs']);
        $this->actingAs($editor);
        $this->assertTrue($this->tool('get_ai_editing_status', ['job_id' => $job->id])['success']);
        $shoot->update(['editor_id' => $other->id]);
        $this->assertFalse($this->tool('get_ai_editing_status', ['job_id' => $job->id])['success']);
        $this->actingAs(User::factory()->create(['role' => 'editing_manager']));
        $this->assertTrue($this->tool('get_ai_editing_status', ['job_id' => $job->id])['success']);
    }

    public function test_rule_flow_helpers_cannot_read_or_retry_another_operators_jobs(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create(['editor_id' => $other->id, 'address' => 'Private editor property']);
        $file = $this->file($shoot);
        $failed = $this->job($shoot, $other);
        $pending = $this->job($shoot, $other, ['status' => 'pending']);
        $this->actingAs($editor);
        $tools = app(AiEditingTools::class);
        $this->assertNull($tools->findShootByAddress($shoot->address, $other->id, 'admin'));
        $this->assertSame(0, $tools->countRawPhotos($shoot->id));
        $this->assertSame(0, $tools->summarizeEditingForShoot($shoot->id)['total']);
        $this->assertSame([], $tools->getRecentJobsForUser($other->id));
        $this->assertSame([], $tools->getJobsByIds([$failed->id], $other->id));
        $this->assertSame(0, $tools->retryJobs([$failed->id], $other->id)['retried']);
        $this->assertSame(0, $tools->retryFailedJobsForShoot($shoot->id)['retried']);
        $this->assertSame(0, $tools->cancelJobs([$pending->id], $other->id)['cancelled']);
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame('pending', $pending->fresh()->status);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_staged_upload_bypass_is_denied_before_any_storage_or_provider_submission(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client']);
        Storage::disk('public')->put('autoenhance-uploads/'.$admin->id.'/staging/known.jpg', 'private image');
        foreach ([$client, User::factory()->create(['role' => 'editor'])] as $actor) {
            $this->actingAs($actor);
            $this->assertFalse(app(AiEditingTools::class)->submitStagedQuickEdit(['known'], $admin->id, 'enhance')['success']);
        }
        $this->actingAs($client);
        $this->assertFalse(app(AiEditingTools::class)->submitStagedQuickEdit(['known'], $client->id, 'enhance')['success']);
        $this->assertDatabaseCount('ai_editing_jobs', 0);
        Http::assertNothingSent();
    }

    public function test_authorized_editing_helpers_keep_own_retry_cancel_and_staged_submission_available(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create(['editor_id' => $editor->id]);
        $failed = $this->job($shoot, $editor);
        $pending = $this->job($shoot, $editor, ['status' => 'pending']);
        $this->actingAs($editor);
        $tools = app(AiEditingTools::class);
        $this->assertSame(2, $tools->countRawPhotos($shoot->id));
        $this->assertSame(2, $tools->summarizeEditingForShoot($shoot->id, $editor->id)['total']);
        $this->assertSame(1, $tools->retryJobs([$failed->id], $editor->id)['retried']);
        $this->assertSame(1, $tools->cancelJobs([$pending->id], $editor->id)['cancelled']);
        $this->assertSame('pending', $failed->fresh()->status);
        $this->assertSame('cancelled', $pending->fresh()->status);
        Bus::assertDispatchedTimes(ProcessFalEditingJob::class, 1);
        Storage::disk('public')->put('autoenhance-uploads/'.$editor->id.'/staging/own-image.jpg', 'test-only image bytes');
        $this->mock(\App\Services\FalService::class)->shouldReceive('submitImageEditFromBuffer')->once()
            ->withArgs(fn ($bytes, $name, $type, $mode) => $bytes === 'test-only image bytes' && $name === 'own-image.jpg' && $type === 'image/jpeg' && $mode === 'enhance')
            ->andReturn(['request_id' => 'fake-provider-result', 'data' => []]);
        $result = app(AiEditingTools::class)->submitStagedQuickEdit(['own-image'], $editor->id, 'enhance', ['provider' => 'fal']);
        $this->assertTrue($result['success']);
        $this->assertSame($editor->id, (int) $result['jobs'][0]->user_id);
        $this->assertNull($result['jobs'][0]->shoot_id);
        Http::assertNothingSent();
    }

    public function test_editing_flow_clears_client_forged_staff_state_without_record_or_provider_access(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $other = Shoot::factory()->create(['address' => 'Hidden existing address']);
        $this->actingAs($client);
        $session = AiChatSession::create(['user_id' => $client->id, 'title' => 'Editing access test', 'step' => 'confirm', 'state_data' => ['shoot_id' => $other->id]]);
        $response = app(EditPhotosFlow::class)->handle($session, 'yes', ['user_id' => $client->id, 'user_role' => 'admin']);
        $this->assertStringContainsString('authorized editing account', $response['assistant_messages'][0]['content']);
        $this->assertStringNotContainsString($other->address, json_encode($response));
        $this->assertNull($session->fresh()->step);
        $this->assertSame([], $session->fresh()->state_data);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_editing_flow_suggestions_and_address_search_respect_editor_assignment(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $own = Shoot::factory()->create(['editor_id' => $editor->id, 'address' => '123 Allowed Lane']);
        $other = Shoot::factory()->create(['address' => 'Private unrelated address']);
        $this->actingAs($editor);
        $session = AiChatSession::create(['user_id' => $editor->id, 'title' => 'Editing access test', 'step' => 'ask_property']);
        $response = app(EditPhotosFlow::class)->handle($session, 'list shoots', ['user_id' => $editor->id, 'user_role' => 'admin']);
        $this->assertCount(1, $response['suggestions']);
        $this->assertStringContainsString($own->address, $response['suggestions'][0]);
        $this->assertStringNotContainsString($other->address, json_encode($response));
        $this->assertSame($own->id, app(AiEditingTools::class)->findShootByAddress($own->address, $editor->id, 'admin')->id);
        $this->assertNull(app(AiEditingTools::class)->findShootByAddress($other->address, $editor->id, 'admin'));
    }

    private function tool(string $name, array $params = [], array $context = []): array
    {
        return app(ToolDispatcher::class)->dispatch($name, $params, $context);
    }

    private function file(Shoot $shoot, array $attributes = []): ShootFile
    {
        return ShootFile::create(['shoot_id' => $shoot->id, 'filename' => 'raw.jpg', 'stored_filename' => uniqid().'.jpg',
            'path' => 'shoots/'.$shoot->id.'/raw.jpg', 'file_type' => 'image', 'file_size' => 100, 'uploaded_by' => $shoot->client_id,
            'workflow_stage' => 'raw', ...$attributes]);
    }

    private function job(Shoot $shoot, User $owner, array $attributes = []): AiEditingJob
    {
        return AiEditingJob::create(['shoot_id' => $shoot->id, 'shoot_file_id' => $this->file($shoot)->id, 'user_id' => $owner->id,
            'provider' => 'fal', 'status' => 'failed', 'editing_type' => 'enhance', 'original_image_url' => 'https://example.test/private.jpg',
            'edited_image_url' => 'https://example.test/edited-private.jpg', ...$attributes]);
    }
}
