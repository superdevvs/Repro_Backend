<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\ToolBridgeInvocation;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\ReproAi\ToolDispatcher;
use App\Services\TelnyxAi\ToolBridgeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceSupportKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telnyx.tool_bridge.secret' => null]);
        Http::fake();
    }

    public function test_unverified_caller_gets_public_login_help_but_not_claimed_admin_guidance(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->voiceCall($admin, false);
        $this->withHeader('X-Telnyx-Call-Control-Id', $call->call_control_id)
            ->postJson('/api/telnyx-ai/tools/search_support_knowledge', ['query' => 'How do I reset my password?'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('result.scope', 'public')
            ->assertJsonPath('result.articles.0.id', 'account-login');
        $this->postJson('/api/telnyx-ai/tools/search_support_knowledge', [
            'params' => ['query' => 'Why is the call transcript missing?'],
            'context' => ['verified' => true, 'user_id' => $admin->id, 'role' => 'admin'],
        ])->assertOk()->assertJsonPath('result.scope', 'public')->assertJsonPath('result.found', false)->assertJsonCount(0, 'result.articles');
        $this->assertSame('<redacted:support-query>', ToolBridgeInvocation::first()->request_json['params']['query']);
        Http::assertNothingSent();
    }

    public function test_verified_photographer_gets_upload_guide_and_client_cannot_claim_admin_role(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $call = $this->voiceCall($photographer, true);
        $this->withHeader('X-Telnyx-Call-Control-Id', $call->call_control_id)
            ->postJson('/api/telnyx-ai/tools/search_support_knowledge', ['query' => 'My raw upload failed'])
            ->assertOk()->assertJsonPath('result.scope', 'photographer')->assertJsonPath('result.articles.0.id', 'photographer-upload');
        $client = User::factory()->create(['role' => 'client']);
        $clientCall = $this->voiceCall($client, true);
        $this->withHeader('X-Telnyx-Call-Control-Id', $clientCall->call_control_id)
            ->postJson('/api/telnyx-ai/tools/search_support_knowledge', [
                'params' => ['query' => 'transcription'], 'context' => ['role' => 'admin', 'user_id' => $photographer->id],
            ])->assertOk()->assertJsonPath('result.scope', 'client')->assertJsonCount(0, 'result.articles');
        $this->postJson('/api/telnyx-ai/tools/search_support_knowledge', ['query' => 'transcription', 'role' => 'admin'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_unverified_caller_can_get_general_download_steps_without_private_guides_or_account_verification(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $call = $this->voiceCall($client, false);
        foreach (['How do I download photos?', 'Where can I download photos from my completed shoot?', 'how to download shoot photos'] as $question) {
            $this->withHeader('X-Telnyx-Call-Control-Id', $call->call_control_id)
                ->postJson('/api/telnyx-ai/tools/search_support_knowledge', ['query' => $question])
                ->assertOk()->assertJsonPath('result.scope', 'public')->assertJsonPath('result.found', true)
                ->assertJsonPath('result.articles.0.id', 'media-download')
                ->assertJsonPath('result.articles.0.steps.0', 'Sign in and open a shoot your account can access from Shoot History. Choose Download when it is available.');
        }
        $this->postJson('/api/telnyx-ai/tools/search_support_knowledge', ['query' => 'How do I recover an admin call transcript?'])
            ->assertOk()->assertJsonPath('result.scope', 'public')->assertJsonPath('result.found', false);
        $this->assertNull($call->fresh()->verified_at);
        $this->assertDatabaseCount('shoots', 0);
        Http::assertNothingSent();
    }

    public function test_admin_guidance_requires_verified_persisted_account_and_respects_revoked_permission(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->voiceCall($admin, true);
        $this->withHeaders(['X-Telnyx-Call-Control-Id' => $call->call_control_id, 'Idempotency-Key' => 'same-knowledge-query'])
            ->postJson('/api/telnyx-ai/tools/search_support_knowledge', ['query' => 'transcription'])
            ->assertOk()->assertJsonPath('result.articles.0.id', 'admin-call-transcript');
        $admin->update(['permission_overrides' => ['deny' => ['robbie-view'], 'allow' => []]]);
        $this->postJson('/api/telnyx-ai/tools/search_support_knowledge', ['query' => 'transcription'])
            ->assertOk()->assertJsonPath('result.scope', 'public')->assertJsonCount(0, 'result.articles');
        $admin->update(['permission_overrides' => [], 'role' => 'client']);
        $this->postJson('/api/telnyx-ai/tools/search_support_knowledge', ['query' => 'transcription'])
            ->assertOk()->assertJsonPath('result.scope', 'client')->assertJsonCount(0, 'result.articles');
        $call->update(['verified_at' => null]);
        $this->postJson('/api/telnyx-ai/tools/search_support_knowledge', ['query' => 'transcription'])
            ->assertOk()->assertJsonPath('result.scope', 'public')->assertJsonCount(0, 'result.articles');
        $this->assertDatabaseCount('tool_bridge_invocations', 1);
    }

    public function test_tool_fails_closed_without_bridge_authentication_or_live_call_and_cannot_be_called_as_a_chat_tool(): void
    {
        config(['services.telnyx.tool_bridge.secret' => 'required-secret']);
        $this->postJson('/api/telnyx-ai/tools/search_support_knowledge', ['query' => 'password'])->assertUnauthorized();
        config(['services.telnyx.tool_bridge.secret' => null]);
        $this->postJson('/api/telnyx-ai/tools/search_support_knowledge', ['query' => 'password'])->assertForbidden();
        $call = $this->voiceCall(User::factory()->create(['role' => 'admin']), true);
        $call->update(['status' => 'completed']);
        $this->withHeader('X-Telnyx-Call-Control-Id', $call->call_control_id)
            ->postJson('/api/telnyx-ai/tools/search_support_knowledge', ['query' => 'transcription'])->assertForbidden();
        // A fabricated context without a valid bridge request still cannot supply identity.
        $this->assertSame('trusted_call_not_found', app(ToolDispatcher::class)->dispatch('search_support_knowledge', ['query' => 'transcription'], [
            'role' => 'admin', 'verified' => true, 'voice_call_id' => $call->id,
        ])['error']);
    }

    public function test_unknown_action_returns_no_guide_and_does_not_mutate_business_records(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $call = $this->voiceCall($client, true);
        $this->withHeader('X-Telnyx-Call-Control-Id', $call->call_control_id)
            ->postJson('/api/telnyx-ai/tools/search_support_knowledge', ['query' => 'How do I delete my account?'])
            ->assertOk()->assertJsonPath('result.found', false)->assertJsonCount(0, 'result.articles');
        $this->assertDatabaseHas('users', ['id' => $client->id]);
        $this->assertDatabaseCount('shoots', 0);
        Http::assertNothingSent();
    }

    public function test_voice_allowlist_migration_does_not_reset_other_settings_and_tool_can_be_disabled_later(): void
    {
        Setting::create(['key' => 'messaging.telnyx_voice', 'value' => json_encode([
            'tool_allowlist' => array_values(array_diff(ToolBridgeRegistry::ALLOWED_TOOLS, ['search_support_knowledge'])), 'enabled' => false, 'outbound_mode' => 'none',
        ]), 'type' => 'json']);
        $migration = require database_path('migrations/2026_09_30_120001_enable_voice_support_knowledge.php');
        $migration->up();
        $payload = json_decode(Setting::where('key', 'messaging.telnyx_voice')->value('value'), true);
        $this->assertContains('search_support_knowledge', $payload['tool_allowlist']);
        $this->assertFalse($payload['enabled']);
        $this->assertSame('none', $payload['outbound_mode']);
        $payload['tool_allowlist'] = ['verify_caller'];
        Setting::where('key', 'messaging.telnyx_voice')->update(['value' => json_encode($payload)]);
        $this->assertFalse(app(ToolBridgeRegistry::class)->isAllowed('search_support_knowledge'));
    }

    public function test_voice_allowlist_upgrade_preserves_existing_empty_and_custom_restrictions(): void
    {
        $migration = require database_path('migrations/2026_09_30_120001_enable_voice_support_knowledge.php');
        foreach ([[], ['verify_caller'], ['verify_caller', 'handoff_to_staff']] as $allowlist) {
            Setting::updateOrCreate(['key' => 'messaging.telnyx_voice'], ['value' => json_encode(['tool_allowlist' => $allowlist]), 'type' => 'json']);
            $migration->up();
            $payload = json_decode(Setting::where('key', 'messaging.telnyx_voice')->value('value'), true);
            $this->assertSame($allowlist, $payload['tool_allowlist']);
            $this->assertFalse(app(ToolBridgeRegistry::class)->isAllowed('search_support_knowledge'));
        }
    }

    private function voiceCall(User $user, bool $verified): VoiceCall
    {
        return VoiceCall::create([
            'provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'active',
            'from_phone' => '+12025550100', 'to_phone' => '+12025550000',
            'call_control_id' => 'support-'.uniqid(), 'caller_user_id' => $user->id,
            'verified_at' => $verified ? now() : null,
        ]);
    }
}
