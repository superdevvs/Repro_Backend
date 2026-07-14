<?php

namespace Tests\Feature;

use App\Services\ReproAi\ToolDispatcher;
use App\Services\TelnyxAi\TelnyxAssistantSyncService;
use App\Services\TelnyxAi\ToolBridgeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelnyxAssistantSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telnyx.api_key' => 'test-key',
            'services.telnyx.api_base' => 'https://api.telnyx.com/v2',
            'services.telnyx.voice.enabled' => true,
            'services.telnyx.voice.assistant_id' => 'assistant-1',
            'services.telnyx.voice.webhook_url' => 'https://api.example.test/api/webhooks/telnyx/voice',
            'services.telnyx.tool_bridge.secret' => 'bridge-secret',
            'services.voice.canary_mode' => true,
            'services.voice.canary_numbers' => ['+12025550123'],
        ]);
    }

    public function test_all_twelve_voice_tools_have_schema_and_dispatcher_coverage(): void
    {
        $registry = app(ToolBridgeRegistry::class);
        $mapping = (new \ReflectionClass(ToolDispatcher::class))->getConstant('TOOL_MAPPING');

        $this->assertCount(12, ToolBridgeRegistry::ALLOWED_TOOLS);
        foreach (ToolBridgeRegistry::ALLOWED_TOOLS as $tool) {
            $this->assertNotNull($registry->definition($tool), "Missing schema for {$tool}");
            $this->assertArrayHasKey($tool, $mapping, "Missing dispatcher mapping for {$tool}");
        }
    }

    public function test_dry_run_reports_drift_without_mutating_telnyx(): void
    {
        Http::fake([
            'https://api.telnyx.com/v2/ai/assistants/assistant-1' => Http::response($this->currentAssistant()),
        ]);

        $result = app(TelnyxAssistantSyncService::class)->sync(false, 'safe-version');

        $this->assertFalse($result['applied']);
        $this->assertFalse($result['promote_to_main']);
        $this->assertContains('set_recording_consent', $result['missing_tools']);
        $this->assertCount(12, $result['desired_tools']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_apply_creates_non_main_consent_safe_version_without_exposing_secret(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response($this->currentAssistant());
            }

            return Http::response(['version_id' => 'version-safe-1'], 200);
        });

        $result = app(TelnyxAssistantSyncService::class)->sync(true, 'safe-version');

        $this->assertTrue($result['applied']);
        $this->assertSame('version-safe-1', $result['created_version_id']);
        $this->assertArrayNotHasKey('payload', $result);
        $this->assertStringNotContainsString('bridge-secret', json_encode($result));
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }
            $tools = collect($request['tools'] ?? [])->filter(fn ($tool) => ($tool['type'] ?? null) === 'webhook');

            return $request['promote_to_main'] === false
                && data_get($request->data(), 'telephony_settings.recording_settings.enabled') === false
                && $tools->count() === 12
                && $tools->contains(fn ($tool) => data_get($tool, 'webhook.name') === 'set_recording_consent')
                && str_contains((string) $request['instructions'], 'RePro voice call-control policy');
        });
    }

    public function test_canary_route_targets_only_allowlisted_number_and_preserves_main_fallback(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/versions/version-safe-1')) {
                return Http::response(array_merge($this->currentAssistant(), ['version_id' => 'version-safe-1']));
            }
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/canary-deploys')) {
                return Http::response([], 404);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/canary-deploys')) {
                return Http::response(['assistant_id' => 'assistant-1', 'rules' => $request['rules']]);
            }

            return Http::response([], 500);
        });

        $result = app(TelnyxAssistantSyncService::class)->routeCanary('version-safe-1');

        $this->assertSame('routed', $result['status']);
        $this->assertSame(['+12025550123'], $result['targets']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && data_get($request->data(), 'rules.0.match.0.attribute') === 'telnyx_end_user_target'
            && data_get($request->data(), 'rules.0.match.0.values') === ['+12025550123']
            && data_get($request->data(), 'rules.0.serve.version_id') === 'version-safe-1');
    }

    private function currentAssistant(): array
    {
        return [
            'id' => 'assistant-1',
            'version_id' => 'version-main',
            'instructions' => 'You are Robbie.',
            'model' => 'moonshotai/Kimi-K2.5',
            'tools' => [
                ['type' => 'hangup'],
                ['type' => 'webhook', 'webhook' => ['name' => 'verify_caller']],
                ['type' => 'webhook', 'webhook' => ['name' => 'handoff_to_staff']],
                ['type' => 'webhook', 'webhook' => ['name' => 'transfer_to_staff']],
            ],
            'telephony_settings' => [
                'recording_settings' => [
                    'enabled' => true,
                    'channels' => 'dual',
                    'format' => 'mp3',
                    'stop_on_conversation_end' => false,
                ],
            ],
        ];
    }
}
