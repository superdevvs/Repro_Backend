<?php

namespace Tests\Feature\Copilot;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Copilot\CopilotWatches;
use Illuminate\Support\Facades\DB;

final class ActionsTest extends CopilotTestCase
{
    private function booking(Service $service): array
    {
        return ['address' => '24 Oak Lane', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201',
            'services' => [['id' => $service->id, 'quantity' => 1]], 'scheduled_at' => now()->addDays(7)->setTime(10, 0)->toIso8601String(),
            'timezone' => 'UTC', 'notify_client' => false, 'notify_photographer' => false];
    }

    public function test_client_booking_preview_creates_nothing_then_commits_once_as_requested(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($client);
        $service = Service::factory()->create(['price' => 100, 'pricing_type' => 'fixed']);
        $draft = $this->tool($connection['access_token'], 'prepare_booking', $this->booking($service))->assertJsonMissingPath('result.isError')->json('result.structuredContent');
        $this->assertDatabaseCount('shoots', 0);
        $args = ['draft_id' => $draft['draft_id'], 'review_hash' => $draft['review_hash']];
        $created = $this->tool($connection['access_token'], 'commit_action', $args)->assertJsonMissingPath('result.isError')->json('result.structuredContent');
        $this->assertSame('requested', $created['status']);
        $this->assertDatabaseCount('shoots', 1);
        $this->assertDatabaseHas('shoots', ['id' => $created['shoot_id'], 'copilot_operation_id' => $draft['draft_id']]);
        $this->tool($connection['access_token'], 'commit_action', $args)->assertJsonPath('result.structuredContent.shoot_id', $created['shoot_id']);
        $this->assertDatabaseCount('shoots', 1);
    }

    public function test_catalog_price_change_rejects_stale_booking_review(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($client);
        $service = Service::factory()->create(['price' => 100, 'pricing_type' => 'fixed']);
        $draft = $this->tool($connection['access_token'], 'prepare_booking', $this->booking($service))->json('result.structuredContent');
        $service->update(['price' => 200]);
        $this->tool($connection['access_token'], 'commit_action', ['draft_id' => $draft['draft_id'], 'review_hash' => $draft['review_hash']])->assertJsonPath('result.structuredContent.error.status', 409);
        $this->assertDatabaseCount('shoots', 0);
    }

    public function test_reschedule_uses_normal_update_and_retains_requested_approval(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $connection = $this->connection($admin);
        $shoot = Shoot::factory()->create(['status' => 'requested', 'workflow_status' => 'requested', 'photographer_id' => null,
            'scheduled_at' => now()->addWeek(), 'timezone' => 'UTC']);
        $at = now()->addDays(9)->setTime(10, 0)->toIso8601String();
        $draft = $this->tool($connection['access_token'], 'prepare_reschedule', ['shoot_id' => $shoot->id,
            'scheduled_at' => $at, 'timezone' => 'UTC', 'notify_client' => false, 'notify_photographer' => false])
            ->assertJsonMissingPath('result.isError')->json('result.structuredContent');
        $this->assertNotSame($at, $shoot->fresh()->scheduled_at->toIso8601String());
        $this->tool($connection['access_token'], 'commit_action', ['draft_id' => $draft['draft_id'], 'review_hash' => $draft['review_hash']])
            ->assertJsonMissingPath('result.isError')->assertJsonPath('result.structuredContent.status', 'requested');
        $this->assertSame($at, $shoot->fresh()->scheduled_at->toIso8601String());
    }

    public function test_note_visibility_permissions_stale_records_and_idempotence(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($client);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'shoot_notes' => 'Old']);
        $this->tool($connection['access_token'], 'prepare_note', ['shoot_id' => $shoot->id, 'field' => 'company_notes', 'text' => 'Bad'])->assertJsonPath('result.structuredContent.error.status', 403);
        $draft = $this->tool($connection['access_token'], 'prepare_note', ['shoot_id' => $shoot->id, 'field' => 'shoot_notes', 'text' => 'Approved notes'])->json('result.structuredContent');
        $args = ['draft_id' => $draft['draft_id'], 'review_hash' => $draft['review_hash']];
        $this->tool($connection['access_token'], 'commit_action', $args)->assertJsonMissingPath('result.isError');
        $this->assertSame('Approved notes', $shoot->fresh()->shoot_notes);
        $this->tool($connection['access_token'], 'commit_action', $args)->assertJsonPath('result.structuredContent.message', 'Note updated.');
        $draft = $this->tool($connection['access_token'], 'prepare_note', ['shoot_id' => $shoot->id, 'field' => 'shoot_notes', 'text' => 'Next'])->json('result.structuredContent');
        $shoot->update(['shoot_notes' => 'Changed elsewhere']);
        $this->tool($connection['access_token'], 'commit_action', ['draft_id' => $draft['draft_id'], 'review_hash' => $draft['review_hash']])->assertJsonPath('result.structuredContent.error.status', 409);
    }

    public function test_cross_account_drafts_and_expired_previews_cannot_commit(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($client);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $draft = $this->tool($connection['access_token'], 'prepare_note', ['shoot_id' => $shoot->id, 'field' => 'shoot_notes', 'text' => 'Next'])->json('result.structuredContent');
        $args = ['draft_id' => $draft['draft_id'], 'review_hash' => $draft['review_hash']];
        $other = $this->connection(User::factory()->create(['role' => 'admin']));
        $this->tool($other['access_token'], 'commit_action', $args)->assertJsonPath('result.structuredContent.error.status', 404);
        DB::table('copilot_drafts')->where('id', $draft['draft_id'])->update(['expires_at' => now()->subMinute()]);
        $this->tool($connection['access_token'], 'commit_action', $args)->assertJsonPath('result.structuredContent.error.status', 410);
    }

    public function test_uncertain_booking_is_reconciled_without_duplicate_creation(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($client);
        $service = Service::factory()->create(['price' => 100, 'pricing_type' => 'fixed']);
        $draft = $this->tool($connection['access_token'], 'prepare_booking', $this->booking($service))->json('result.structuredContent');
        DB::table('copilot_drafts')->where('id', $draft['draft_id'])->update(['status' => 'processing']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $shoot->forceFill(['copilot_operation_id' => $draft['draft_id']])->save();
        $this->tool($connection['access_token'], 'get_draft', ['draft_id' => $draft['draft_id']])->assertJsonPath('result.structuredContent.result.shoot_id', $shoot->id);
        $this->tool($connection['access_token'], 'commit_action', ['draft_id' => $draft['draft_id'], 'review_hash' => $draft['review_hash']])->assertJsonPath('result.structuredContent.error.status', 409);
        $this->assertDatabaseCount('shoots', 1);
    }

    public function test_watch_notifies_only_on_change_and_stops_when_connection_revoked(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($client);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $draft = $this->tool($connection['access_token'], 'prepare_watch', ['shoot_id' => $shoot->id, 'enabled' => true])->json('result.structuredContent');
        $this->tool($connection['access_token'], 'commit_action', ['draft_id' => $draft['draft_id'], 'review_hash' => $draft['review_hash']])->assertJsonPath('result.structuredContent.watch_enabled', true);
        $watches = app(CopilotWatches::class);
        $this->assertSame(0, $watches->check());
        $shoot->update(['workflow_status' => 'editing']);
        $this->assertSame(1, $watches->check());
        $this->assertSame(0, $watches->check());
        $this->assertDatabaseCount('copilot_watch_events', 1);
        $this->assertCount(1, $watches->notifications($client));
        $this->assertCount(0, $watches->notifications(User::factory()->create(['role' => 'client'])));
        DB::table('copilot_grants')->where('id', $connection['grant_id'])->update(['revoked_at' => now()]);
        $shoot->update(['workflow_status' => 'delivered']);
        $this->assertSame(0, $watches->check());
        $this->assertDatabaseCount('copilot_watch_events', 1);
        $this->assertCount(0, $watches->notifications($client));
    }
}
