<?php

namespace Tests\Feature\Copilot;

use App\Models\User;
use App\Services\Copilot\CopilotOAuth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

final class OAuthTest extends CopilotTestCase
{
    private function authorization(User $user, string $scope = 'repro.read repro.write'): array
    {
        $client = $this->postJson('/api/copilot/oauth/register', ['redirect_uris' => ['https://chatgpt.com/connector/oauth/test'], 'token_endpoint_auth_method' => 'none'])->assertCreated()->json('client_id');
        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = ['client_id' => $client, 'redirect_uri' => 'https://chatgpt.com/connector/oauth/test', 'response_type' => 'code',
            'state' => 'opaque-state', 'scope' => $scope, 'resource' => app(CopilotOAuth::class)->resource(),
            'code_challenge_method' => 'S256', 'code_challenge' => $challenge];
        $response = $this->get('/api/copilot/oauth/authorize?'.http_build_query($query))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);
        Sanctum::actingAs($user);
        $redirect = $this->postJson('/api/copilot/oauth/requests/'.$params['request_id'], ['approve' => true])->assertOk()->json('redirect_url');
        parse_str(parse_url($redirect, PHP_URL_QUERY), $callback);
        $this->assertSame($query['state'], $callback['state']);
        $this->assertSame(config('copilot.issuer'), $callback['iss']);

        return ['grant_type' => 'authorization_code', 'client_id' => $client, 'resource' => $query['resource'],
            'code' => $callback['code'], 'redirect_uri' => $query['redirect_uri'], 'code_verifier' => $verifier];
    }

    public function test_complete_pkce_linking_and_one_use_code(): void
    {
        $exchange = $this->authorization(User::factory()->create(['role' => 'client']));
        $response = $this->postJson('/api/copilot/oauth/token', $exchange)->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $token = $response->json('access_token');
        $this->assertNotEmpty($token);
        $this->assertDatabaseMissing('copilot_tokens', ['access_hash' => $token]);
        $this->postJson('/api/copilot/oauth/token', $exchange)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->tool($token, 'get_profile')->assertJsonPath('result.structuredContent.role', 'client');
    }

    public function test_finance_is_omitted_when_host_requests_all_scopes_for_a_client(): void
    {
        $exchange = $this->authorization(User::factory()->create(['role' => 'client']), 'repro.read repro.write repro.finance');
        $this->postJson('/api/copilot/oauth/token', $exchange)->assertOk()->assertJsonPath('scope', 'repro.read repro.write');
    }

    public function test_token_validation_uses_oauth_errors(): void
    {
        $this->postJson('/api/copilot/oauth/token', [])->assertStatus(400)->assertJsonPath('error', 'invalid_request');
        $this->postJson('/api/copilot/oauth/token', ['grant_type' => 'password'])->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
    }

    public function test_wrong_pkce_does_not_consume_code(): void
    {
        $exchange = $this->authorization(User::factory()->create(['role' => 'client']));
        $this->postJson('/api/copilot/oauth/token', [...$exchange, 'code_verifier' => str_repeat('b', 64)])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->postJson('/api/copilot/oauth/token', $exchange)->assertOk();
    }

    public function test_code_is_bound_to_redirect_client_and_resource(): void
    {
        $exchange = $this->authorization(User::factory()->create(['role' => 'client']));
        $this->postJson('/api/copilot/oauth/token', [...$exchange, 'resource' => 'https://evil.example/mcp'])->assertStatus(400)->assertJsonPath('error', 'invalid_target');
        $this->postJson('/api/copilot/oauth/token', [...$exchange, 'redirect_uri' => 'https://chatgpt.com/connector/oauth/other'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->postJson('/api/copilot/oauth/token', $exchange)->assertOk();
    }

    public function test_refresh_rotates_and_replay_revokes_the_family(): void
    {
        $connection = $this->connection(User::factory()->create(['role' => 'client']));
        $args = ['grant_type' => 'refresh_token', 'client_id' => $connection['client_id'], 'resource' => app(CopilotOAuth::class)->resource(), 'refresh_token' => $connection['refresh_token']];
        $next = $this->postJson('/api/copilot/oauth/token', $args)->assertOk()->json();
        $this->assertNotSame($connection['access_token'], $next['access_token']);
        $this->tool($connection['access_token'], 'get_profile')->assertJsonPath('result.isError', true);
        $this->tool($next['access_token'], 'get_profile')->assertJsonMissingPath('result.isError');
        $this->postJson('/api/copilot/oauth/token', $args)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertNotNull(DB::table('copilot_grants')->find($connection['grant_id'])->revoked_at);
        $this->tool($next['access_token'], 'get_profile')->assertJsonPath('result.isError', true);
    }

    public function test_disconnect_blocks_tokens_and_is_owner_scoped(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($user);
        Sanctum::actingAs($other);
        $this->deleteJson('/api/copilot/oauth/connections/'.$connection['grant_id'])->assertOk();
        $this->assertNull(DB::table('copilot_grants')->find($connection['grant_id'])->revoked_at);
        Sanctum::actingAs($user);
        $this->deleteJson('/api/copilot/oauth/connections/'.$connection['grant_id'])->assertOk();
        $this->tool($connection['access_token'], 'get_profile')->assertJsonPath('result.isError', true);
    }

    public function test_registration_rejects_untrusted_destinations_and_accepts_current_callbacks(): void
    {
        foreach (['https://evil.example/connector/oauth/test', 'http://chatgpt.com/connector/oauth/test',
            'https://chatgpt.com@evil.example/connector/oauth/test', 'https://user@chatgpt.com/connector/oauth/test',
            'https://chatgpt.com/connector/oauth/test#fragment', 'https://chatgpt.com:443/connector/oauth/test'] as $uri) {
            $this->postJson('/api/copilot/oauth/register', ['redirect_uris' => [$uri]])->assertStatus(400);
        }
        $this->postJson('/api/copilot/oauth/register', ['redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect']])->assertCreated();
    }

    public function test_expired_access_and_scope_downgrades_are_rejected(): void
    {
        $connection = $this->connection(User::factory()->create(['role' => 'client']), 'repro.read');
        $this->tool($connection['access_token'], 'prepare_note', ['shoot_id' => 1, 'field' => 'shoot_notes', 'text' => 'Test'])
            ->assertJsonPath('result.structuredContent.error.status', 403)->assertJsonStructure(['result' => ['_meta' => ['mcp/www_authenticate']]]);
        DB::table('copilot_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->tool($connection['access_token'], 'get_profile')->assertJsonPath('result.structuredContent.error.status', 401);
    }

    public function test_invalid_account_and_permission_changes_block_existing_grants(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($user);
        $user->forceFill(['permission_overrides' => ['allow' => [], 'deny' => ['robbie-view']]])->save();
        $this->tool($connection['access_token'], 'get_profile')->assertJsonPath('result.isError', true);
        $user->forceFill(['permission_overrides' => null, 'account_status' => 'suspended'])->save();
        $this->tool($connection['access_token'], 'get_profile')->assertJsonPath('result.isError', true);
    }
}
