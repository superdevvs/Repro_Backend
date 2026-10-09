<?php

namespace Tests\Feature\Copilot;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Copilot\CopilotSettings;
use App\Services\Copilot\CopilotWatches;
use Illuminate\Support\Facades\DB;

final class SettingsTest extends CopilotTestCase
{
    public function test_existing_admin_permission_upgrade_preserves_user_denials(): void
    {
        DB::table('settings')->insert(['key' => 'permissions.role_map.v1', 'type' => 'json',
            'value' => json_encode(['version' => 1, 'roles' => ['admin' => ['integrations-view'], 'editing_manager' => ['integrations-view']]])]);
        $migration = require database_path('migrations/2026_10_09_180000_add_copilot_integration_manage_permission.php');
        $migration->up();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue(app(CopilotSettings::class)->canManage($admin));
        $admin->update(['permission_overrides' => ['allow' => [], 'deny' => ['integrations-edit']]]);
        $this->assertFalse(app(CopilotSettings::class)->canManage($admin->fresh()));
        $this->actingAs($admin->fresh(), 'sanctum')->putJson('/api/copilot/settings', $this->controls(['enabled' => false]))->assertForbidden();
        $this->assertFalse(app(CopilotSettings::class)->canManage(User::factory()->create(['role' => 'editing_manager'])));
    }

    private function controls(array $changes = []): array
    {
        $service = app(CopilotSettings::class);

        return array_replace($service->current(), ['version' => $service->version()], $changes);
    }

    private function persist(array $changes): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->putJson('/api/copilot/settings', $this->controls($changes))->assertOk();
        auth()->forgetGuards();
    }

    public function test_admin_can_save_controls_and_stale_saves_are_rejected_and_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/copilot/settings')->assertOk()->assertJsonPath('data.can_manage', true);
        $input = $this->controls(['allow_changes' => false, 'listing_url' => 'https://chatgpt.com/plugins/repro-copilot']);
        $this->putJson('/api/copilot/settings', $input)->assertOk()->assertJsonPath('data.connection_mode', 'listing')
            ->assertJsonPath('data.connection_url', $input['listing_url']);
        $this->putJson('/api/copilot/settings', $input)->assertStatus(409);
        $this->assertDatabaseHas('settings', ['key' => CopilotSettings::KEY, 'type' => 'json']);
        $this->assertDatabaseHas('user_activity_logs', ['event_type' => 'copilot.settings_updated', 'actor_user_id' => $admin->id]);
    }

    public function test_editing_manager_can_inspect_but_cannot_change_global_controls(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editing_manager']), 'sanctum');
        $this->getJson('/api/copilot/settings')->assertOk()->assertJsonPath('data.can_manage', false);
        $this->putJson('/api/copilot/settings', $this->controls(['enabled' => false]))->assertForbidden();
        $this->postJson('/api/admin/settings', ['key' => CopilotSettings::KEY, 'type' => 'json', 'value' => ['enabled' => false]])->assertForbidden();
        $this->assertTrue(app(CopilotSettings::class)->enabled());
    }

    public function test_client_and_unauthenticated_settings_access_are_denied(): void
    {
        $this->getJson('/api/copilot/settings')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'client']), 'sanctum')->getJson('/api/copilot/settings')->assertForbidden();
        $this->putJson('/api/copilot/settings', $this->controls())->assertForbidden();
    }

    public function test_listing_url_rejects_untrusted_hosts_credentials_and_redirect_queries(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        foreach (['https://evil.test/plugins/repro', 'https://chatgpt.com.evil.test/plugins/repro',
            'https://user:secret@chatgpt.com/plugins/repro', 'https://chatgpt.com/plugins/repro?redirect=https://evil.test', 'javascript:alert(1)'] as $url) {
            $this->putJson('/api/copilot/settings', $this->controls(['listing_url' => $url]))->assertUnprocessable();
        }
        $this->assertDatabaseMissing('settings', ['key' => CopilotSettings::KEY]);
    }

    public function test_master_off_blocks_existing_tokens_new_connections_and_tool_discovery_but_allows_revocation(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($client);
        $this->persist(['enabled' => false]);
        $this->tool($connection['access_token'], 'get_profile')->assertJsonPath('result.structuredContent.error.status', 403);
        $this->postJson('/api/copilot/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertJsonPath('result.tools', []);
        $this->postJson('/api/copilot/oauth/register', ['redirect_uris' => ['https://chatgpt.com/connector/oauth/test']])->assertForbidden();
        $this->actingAs($client, 'sanctum')->deleteJson('/api/copilot/oauth/connections/'.$connection['grant_id'])->assertOk();
        $this->assertNotNull(DB::table('copilot_grants')->find($connection['grant_id'])->revoked_at);
    }

    public function test_read_only_mode_keeps_search_and_blocks_preparing_or_committing_changes(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($client);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $draft = $this->tool($connection['access_token'], 'prepare_note', ['shoot_id' => $shoot->id, 'field' => 'shoot_notes', 'text' => 'Reviewed note'])->json('result.structuredContent');
        $this->persist(['allow_changes' => false]);
        $this->tool($connection['access_token'], 'search', ['query' => 'Oak'])->assertJsonMissingPath('result.isError');
        $this->tool($connection['access_token'], 'prepare_note', ['shoot_id' => $shoot->id, 'field' => 'shoot_notes', 'text' => 'New note'])
            ->assertJsonPath('result.structuredContent.error.status', 403);
        $this->tool($connection['access_token'], 'commit_action', ['draft_id' => $draft['draft_id'], 'review_hash' => $draft['review_hash']])
            ->assertJsonPath('result.structuredContent.error.status', 403);
        $this->tool($connection['access_token'], 'get_draft', ['draft_id' => $draft['draft_id']])->assertJsonMissingPath('result.isError');
        $this->assertSame('prepared', DB::table('copilot_drafts')->find($draft['draft_id'])->status);
        $this->assertNotSame('Reviewed note', $shoot->fresh()->shoot_notes);
    }

    public function test_disabled_note_feature_blocks_previously_reviewed_note_and_hides_its_tool(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($client);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $draft = $this->tool($connection['access_token'], 'prepare_note', ['shoot_id' => $shoot->id, 'field' => 'shoot_notes', 'text' => 'Reviewed note'])->json('result.structuredContent');
        $features = app(CopilotSettings::class)->current()['features']; $features['notes'] = false;
        $this->persist(['features' => $features]);
        $this->tool($connection['access_token'], 'commit_action', ['draft_id' => $draft['draft_id'], 'review_hash' => $draft['review_hash']])
            ->assertJsonPath('result.structuredContent.error.status', 403);
        $names = array_column($this->postJson('/api/copilot/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'), 'name');
        $this->assertNotContains('prepare_note', $names);
        $this->assertContains('prepare_booking', $names);
    }

    public function test_watches_pause_without_disabling_saved_watches_then_resume_without_duplicate_alerts(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($client);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $watches = app(CopilotWatches::class);
        $watches->configure($shoot->id, true, $client, $connection['grant_id']);
        $features = app(CopilotSettings::class)->current()['features']; $features['watches'] = false;
        $this->persist(['features' => $features]);
        $shoot->update(['workflow_status' => 'editing']);
        $this->assertSame(0, $watches->check());
        $this->assertDatabaseHas('copilot_watches', ['shoot_id' => $shoot->id, 'enabled' => true]);
        $this->assertCount(0, $watches->notifications($client));
        $features['watches'] = true; $this->persist(['features' => $features]);
        $this->assertSame(1, $watches->check());
        $this->assertSame(0, $watches->check());
    }
}
