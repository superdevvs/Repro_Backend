<?php

namespace Tests\Feature\Copilot;

use App\Models\User;
use App\Services\Copilot\CopilotOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class CopilotTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['copilot.issuer' => 'https://reprodashboard.com']);
        Http::preventStrayRequests();
        Queue::fake();
    }

    protected function connection(User $user, string $scopes = 'repro.read repro.write'): array
    {
        $clientId = (string) Str::uuid();
        $grantId = (string) Str::uuid();
        DB::table('copilot_clients')->insert(['id' => $clientId, 'name' => 'ChatGPT', 'redirect_uris' => json_encode(['https://chatgpt.com/connector/oauth/test']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('copilot_grants')->insert(['id' => $grantId, 'user_id' => $user->id, 'client_id' => $clientId, 'scopes' => $scopes,
            'resource' => app(CopilotOAuth::class)->resource(), 'created_at' => now(), 'updated_at' => now()]);
        $grant = DB::table('copilot_grants')->find($grantId);

        return [...app(CopilotOAuth::class)->issue($grant), 'grant_id' => $grantId, 'client_id' => $clientId];
    }

    protected function tool(string $token, string $name, array $args = [])
    {
        return $this->postJson('/api/copilot/mcp', ['jsonrpc' => '2.0', 'id' => 'test', 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $args ?: (object) []]], ['Authorization' => 'Bearer '.$token]);
    }
}
