<?php

namespace Tests\Feature\Copilot;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Copilot\ToolCatalog;

final class McpTest extends CopilotTestCase
{
    public function test_initialize_catalog_and_widget_are_available_without_private_data(): void
    {
        $this->postJson('/api/copilot/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']])
            ->assertOk()->assertJsonPath('result.protocolVersion', '2025-06-18')->assertJsonPath('result.serverInfo.name', 'repro-copilot');
        $this->postJson('/api/copilot/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])->assertJsonCount(19, 'result.tools');
        $this->postJson('/api/copilot/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/read', 'params' => ['uri' => ToolCatalog::UI]])
            ->assertOk()->assertJsonPath('result.contents.0.mimeType', 'text/html;profile=mcp-app');
        $this->tool('', 'get_profile')->assertJsonPath('result.isError', true)->assertJsonStructure(['result' => ['_meta' => ['mcp/www_authenticate']]]);
    }

    public function test_transport_errors_and_notifications(): void
    {
        $this->get('/api/copilot/mcp')->assertStatus(405);
        $this->delete('/api/copilot/mcp')->assertStatus(405);
        $this->postJson('/api/copilot/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
        $this->postJson('/api/copilot/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'unknown'])->assertJsonPath('error.code', -32601);
        $this->postJson('/api/copilot/mcp', ['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping'])->assertJsonPath('error.code', -32600);
        $this->postJson('/api/copilot/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Origin' => 'https://evil.example'])->assertStatus(403);
    }

    public function test_client_search_and_fetch_never_expose_another_clients_notes(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($user);
        $own = Shoot::factory()->create(['client_id' => $user->id, 'address' => '24 Oak Lane', 'shoot_notes' => 'Gate code', 'company_notes' => 'PRIVATE COMPANY']);
        $other = Shoot::factory()->create(['address' => '87 Secret Lane', 'shoot_notes' => 'OTHER CLIENT SECRET']);
        $this->tool($connection['access_token'], 'search', ['query' => 'Lane'])->assertJsonCount(1, 'result.structuredContent.results');
        $this->tool($connection['access_token'], 'fetch', ['id' => 'shoot:'.$own->id])->assertJsonPath('result.structuredContent.shoot.notes.shoot_notes', 'Gate code')->assertDontSee('PRIVATE COMPANY');
        $this->tool($connection['access_token'], 'fetch', ['id' => 'shoot:'.$other->id])->assertJsonPath('result.structuredContent.error.status', 404)->assertDontSee('OTHER CLIENT SECRET');
    }

    public function test_forged_actor_override_and_unknown_arguments_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($user);
        $this->tool($connection['access_token'], 'search', ['query' => 'Lane', 'user_id' => 999, 'role' => 'superadmin'])->assertJsonPath('result.structuredContent.error.status', 422);
        $this->tool($connection['access_token'], 'fetch', ['id' => '../private'])->assertJsonPath('result.structuredContent.error.status', 422);
        $this->tool($connection['access_token'], 'operations_brief', ['from' => '2026-02-31', 'to' => '2026-03-10'])->assertJsonPath('result.structuredContent.error.status', 422);
        $this->tool($connection['access_token'], 'get_profile')->assertJsonPath('result.structuredContent.id', (string) $user->id);
    }

    public function test_photographer_can_read_only_assigned_shoots_and_no_billing(): void
    {
        $user = User::factory()->create(['role' => 'photographer']);
        $connection = $this->connection($user);
        $own = Shoot::factory()->create(['photographer_id' => $user->id]);
        $other = Shoot::factory()->create();
        $this->tool($connection['access_token'], 'fetch', ['id' => 'shoot:'.$own->id])->assertJsonMissingPath('result.structuredContent.shoot.billing');
        $this->tool($connection['access_token'], 'fetch', ['id' => 'shoot:'.$other->id])->assertJsonPath('result.isError', true);
    }

    public function test_accounting_denied_admin_does_not_receive_billing_or_reports(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'permission_overrides' => ['allow' => [], 'deny' => ['accounting-view']]]);
        $connection = $this->connection($user, 'repro.read repro.finance');
        $shoot = Shoot::factory()->create();
        $this->tool($connection['access_token'], 'fetch', ['id' => 'shoot:'.$shoot->id])->assertJsonMissingPath('result.structuredContent.shoot.billing');
        $this->tool($connection['access_token'], 'finance_report', ['from' => '2026-01-01', 'to' => '2026-01-31'])->assertJsonPath('result.structuredContent.error.status', 403);
    }

    public function test_operation_list_excludes_held_and_cancelled_shoots(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $connection = $this->connection($user);
        foreach (['on_hold', 'cancelled', 'scheduled'] as $status) {
            Shoot::factory()->create(['status' => $status, 'scheduled_date' => '2026-10-10', 'photographer_id' => null]);
        }
        $this->tool($connection['access_token'], 'operations_brief', ['from' => '2026-10-09', 'to' => '2026-10-11'])->assertJsonCount(1, 'result.structuredContent.data');
    }

    public function test_pagination_reports_incomplete_results_and_supports_next_page(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($user);
        Shoot::factory()->count(21)->create(['client_id' => $user->id]);
        $this->tool($connection['access_token'], 'list_shoots')->assertJsonCount(20, 'result.structuredContent.data')->assertJsonPath('result.structuredContent.has_more', true);
        $this->tool($connection['access_token'], 'list_shoots', ['page' => 2])->assertJsonCount(1, 'result.structuredContent.data')->assertJsonPath('result.structuredContent.total', 21);
    }
}
