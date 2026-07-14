<?php

namespace App\Services\TelnyxAi;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelnyxAssistantSyncService
{
    private const POLICY_MARKER = '## RePro voice call-control policy';

    public function __construct(
        private readonly ToolBridgeRegistry $registry,
        private readonly VoiceSettingsService $settings,
    ) {}

    /** @return array<string,mixed> */
    public function inspect(bool $force = false): array
    {
        $assistantId = $this->assistantId();
        $cacheKey = 'telnyx:assistant-inspection:'.hash('sha256', $assistantId);
        if ($force) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinute(), function () use ($assistantId): array {
            $main = $this->currentAssistant($assistantId);
            $canary = $this->canaryDeployment($assistantId);
            $routedVersionId = $this->routedVersionId($canary['rules'] ?? [], (array) config('services.voice.canary_numbers', []));
            $current = $routedVersionId ? $this->assistantVersion($assistantId, $routedVersionId) : $main;
            $configured = $this->toolNames($current['tools'] ?? []);
            $desired = $this->registry->allowedTools();
            $missing = array_values(array_diff($desired, $configured));
            $extra = array_values(array_diff($this->webhookToolNames($current['tools'] ?? []), $desired));
            $automaticRecording = (bool) data_get($current, 'telephony_settings.recording_settings.enabled', false);
            $policyCurrent = str_contains((string) ($current['instructions'] ?? ''), self::POLICY_MARKER);

            return [
                'status' => $missing === [] && $extra === [] && ! $automaticRecording && $policyCurrent ? 'synced' : 'drifted',
                'assistant_id' => $assistantId,
                'version_id' => $current['version_id'] ?? null,
                'main_version_id' => $main['version_id'] ?? null,
                'canary_version_id' => $routedVersionId,
                'canary_route_status' => (bool) config('services.voice.canary_mode', true)
                    ? ($routedVersionId ? 'routed' : 'missing')
                    : 'not_required',
                'configured_tools' => $configured,
                'desired_tools' => $desired,
                'missing_tools' => $missing,
                'extra_webhook_tools' => $extra,
                'automatic_recording_enabled' => $automaticRecording,
                'policy_instructions_current' => $policyCurrent,
                'fetched_at' => now()->toIso8601String(),
            ];
        });
    }

    /** @return array<string,mixed> */
    public function sync(bool $apply = false, ?string $versionName = null): array
    {
        $assistantId = $this->assistantId();
        $current = $this->currentAssistant($assistantId);
        $desiredTools = $this->desiredTools($current['tools'] ?? []);
        $desiredToolNames = $this->registry->allowedTools();
        $configuredToolNames = $this->toolNames($current['tools'] ?? []);
        $payload = [
            'instructions' => $this->desiredInstructions((string) ($current['instructions'] ?? '')),
            'tools' => $desiredTools,
            'telephony_settings' => array_replace_recursive(
                is_array($current['telephony_settings'] ?? null) ? $current['telephony_settings'] : [],
                ['recording_settings' => [
                    'enabled' => false,
                    'channels' => 'dual',
                    'format' => 'mp3',
                    'stop_on_conversation_end' => true,
                ]],
            ),
            'version_name' => mb_substr($versionName ?: 'repro-voice-wiring-'.now()->format('Ymd-His'), 0, 50),
            'promote_to_main' => false,
        ];

        $result = [
            'applied' => false,
            'assistant_id' => $assistantId,
            'current_version_id' => $current['version_id'] ?? null,
            'configured_tools' => $configuredToolNames,
            'desired_tools' => $desiredToolNames,
            'missing_tools' => array_values(array_diff($desiredToolNames, $configuredToolNames)),
            'removed_webhook_tools' => array_values(array_diff($this->webhookToolNames($current['tools'] ?? []), $desiredToolNames)),
            'automatic_recording_will_be_disabled' => (bool) data_get($current, 'telephony_settings.recording_settings.enabled', false),
            'promote_to_main' => false,
            'version_name' => $payload['version_name'],
        ];

        if (! $apply) {
            return $result;
        }

        $response = Http::withToken($this->apiKey())
            ->connectTimeout(5)
            ->timeout(20)
            ->post($this->apiBase()."/ai/assistants/{$assistantId}", $payload);
        $this->throwOnFailure($response, 'assistant sync');
        $body = $response->json('data') ?? $response->json() ?? [];
        Cache::forget('telnyx:assistant-inspection:'.hash('sha256', $assistantId));

        return array_merge($result, [
            'applied' => true,
            'created_version_id' => $body['version_id'] ?? null,
            'response_status' => $response->status(),
        ]);
    }

    /** @return array<string,mixed> */
    public function routeCanary(string $versionId): array
    {
        $assistantId = $this->assistantId();
        $numbers = array_values(array_filter((array) config('services.voice.canary_numbers', [])));
        if ($numbers === []) {
            throw new RuntimeException('VOICE_CANARY_NUMBERS must be configured before routing a canary version.');
        }

        // Confirm the version exists before changing any routing state.
        $this->assistantVersion($assistantId, $versionId);
        $existing = $this->canaryDeployment($assistantId);
        $rules = $this->withoutCanaryTargets($existing['rules'] ?? [], $numbers);
        array_unshift($rules, [
            'match' => [[
                'attribute' => 'telnyx_end_user_target',
                'values' => $numbers,
            ]],
            'serve' => ['version_id' => $versionId],
        ]);

        $method = ! empty($existing['_exists']) ? 'put' : 'post';
        $response = Http::withToken($this->apiKey())
            ->connectTimeout(5)
            ->timeout(20)
            ->{$method}($this->apiBase()."/ai/assistants/{$assistantId}/canary-deploys", ['rules' => $rules]);
        $this->throwOnFailure($response, 'canary routing');
        Cache::forget('telnyx:assistant-inspection:'.hash('sha256', $assistantId));

        return [
            'assistant_id' => $assistantId,
            'version_id' => $versionId,
            'targets' => $numbers,
            'rule_count' => count($rules),
            'status' => 'routed',
        ];
    }

    /** @return array<string,mixed> */
    public function removeCanaryRoute(): array
    {
        $assistantId = $this->assistantId();
        $numbers = array_values(array_filter((array) config('services.voice.canary_numbers', [])));
        if ($numbers === []) {
            throw new RuntimeException('VOICE_CANARY_NUMBERS must be configured before removing its route.');
        }

        $existing = $this->canaryDeployment($assistantId);
        if (empty($existing['_exists'])) {
            return ['assistant_id' => $assistantId, 'targets' => $numbers, 'status' => 'already_absent'];
        }

        $rules = $this->withoutCanaryTargets($existing['rules'] ?? [], $numbers);
        $response = Http::withToken($this->apiKey())
            ->connectTimeout(5)
            ->timeout(20)
            ->put($this->apiBase()."/ai/assistants/{$assistantId}/canary-deploys", ['rules' => $rules]);
        $this->throwOnFailure($response, 'canary rollback');
        Cache::forget('telnyx:assistant-inspection:'.hash('sha256', $assistantId));

        return [
            'assistant_id' => $assistantId,
            'targets' => $numbers,
            'remaining_rule_count' => count($rules),
            'status' => 'removed',
        ];
    }

    /** @return list<array<string,mixed>> */
    public function desiredTools(array $currentTools = []): array
    {
        $baseUrl = $this->toolBridgeBaseUrl();
        $secret = (string) config('services.telnyx.tool_bridge.secret', '');
        if ($secret === '') {
            throw new RuntimeException('TELNYX_TOOL_BRIDGE_SECRET is not configured.');
        }

        $tools = array_values(array_filter(
            $currentTools,
            static fn ($tool) => is_array($tool) && ($tool['type'] ?? null) !== 'webhook',
        ));

        foreach ($this->registry->allowedTools() as $name) {
            $definition = $this->registry->definition($name);
            if (! $definition) {
                continue;
            }

            $tools[] = [
                'type' => 'webhook',
                'webhook' => [
                    'name' => $name,
                    'description' => $definition['description'],
                    'url' => $baseUrl.'/'.$name,
                    'method' => 'POST',
                    'headers' => [[
                        'name' => 'Authorization',
                        'value' => 'Bearer '.$secret,
                    ]],
                    'body_parameters' => $definition['schema'],
                    'async' => false,
                    'timeout_ms' => 5250,
                ],
            ];
        }

        return $tools;
    }

    /** @return list<string> */
    public function toolNames(array $tools): array
    {
        $names = [];
        foreach ($tools as $tool) {
            if (! is_array($tool)) {
                continue;
            }
            $name = data_get($tool, 'webhook.name') ?? $tool['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /** @return list<string> */
    private function webhookToolNames(array $tools): array
    {
        return $this->toolNames(array_values(array_filter(
            $tools,
            static fn ($tool) => is_array($tool) && ($tool['type'] ?? null) === 'webhook',
        )));
    }

    /** @return array<string,mixed> */
    private function currentAssistant(string $assistantId): array
    {
        $response = Http::withToken($this->apiKey())
            ->connectTimeout(5)
            ->timeout(15)
            ->get($this->apiBase()."/ai/assistants/{$assistantId}");
        $this->throwOnFailure($response, 'assistant inspection');
        $body = $response->json('data') ?? $response->json() ?? [];

        if (! is_array($body) || $body === []) {
            throw new RuntimeException('Telnyx returned an empty assistant response.');
        }

        return $body;
    }

    /** @return array<string,mixed> */
    private function assistantVersion(string $assistantId, string $versionId): array
    {
        $response = Http::withToken($this->apiKey())
            ->connectTimeout(5)
            ->timeout(15)
            ->get($this->apiBase()."/ai/assistants/{$assistantId}/versions/{$versionId}");
        $this->throwOnFailure($response, 'assistant version inspection');
        $body = $response->json('data') ?? $response->json() ?? [];
        if (! is_array($body) || $body === []) {
            throw new RuntimeException('Telnyx returned an empty assistant version response.');
        }

        return $body;
    }

    /** @return array<string,mixed> */
    private function canaryDeployment(string $assistantId): array
    {
        $response = Http::withToken($this->apiKey())
            ->connectTimeout(5)
            ->timeout(15)
            ->get($this->apiBase()."/ai/assistants/{$assistantId}/canary-deploys");
        if ($response->status() === 404) {
            return ['_exists' => false, 'rules' => []];
        }
        $this->throwOnFailure($response, 'canary inspection');
        $body = $response->json('data') ?? $response->json() ?? [];

        return array_merge(is_array($body) ? $body : [], ['_exists' => true]);
    }

    private function routedVersionId(array $rules, array $targets): ?string
    {
        $targets = array_values(array_filter(array_map('strval', $targets)));
        if ($targets === []) {
            return null;
        }

        foreach ($rules as $rule) {
            foreach ((array) ($rule['match'] ?? []) as $match) {
                if (($match['attribute'] ?? null) !== 'telnyx_end_user_target') {
                    continue;
                }
                if (array_intersect($targets, (array) ($match['values'] ?? [])) !== []) {
                    $versionId = data_get($rule, 'serve.version_id');

                    return is_string($versionId) && $versionId !== '' ? $versionId : null;
                }
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function withoutCanaryTargets(array $rules, array $targets): array
    {
        $result = [];
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $matches = [];
            $dropRule = false;
            foreach ((array) ($rule['match'] ?? []) as $match) {
                if (! is_array($match) || ($match['attribute'] ?? null) !== 'telnyx_end_user_target') {
                    $matches[] = $match;

                    continue;
                }
                $remaining = array_values(array_diff((array) ($match['values'] ?? []), $targets));
                if ($remaining !== []) {
                    $match['values'] = $remaining;
                    $matches[] = $match;
                } else {
                    // Removing a required AND condition would broaden this rule,
                    // so remove the complete rule when no target values remain.
                    $dropRule = true;
                    break;
                }
            }
            if (! $dropRule && $matches !== []) {
                $rule['match'] = $matches;
                $result[] = $rule;
            }
        }

        return array_values($result);
    }

    private function desiredInstructions(string $current): string
    {
        $beforeMarker = str_contains($current, self::POLICY_MARKER)
            ? trim((string) strstr($current, self::POLICY_MARKER, true))
            : trim($current);
        $policy = <<<'PROMPT'
## RePro voice call-control policy
- At the start of every call, identify yourself as Robbie, RePro's AI assistant.
- Read the configured recording disclosure and ask a direct yes-or-no recording question. Call set_recording_consent with the answer before recording can begin. Continue without recording after a no.
- Never reveal shoot, payment, or account details until verify_caller succeeds for this call.
- Use list_shoots and other read tools instead of guessing IDs or account data.
- For booking, rescheduling, cancellation, and payment-link actions, first call the tool without a confirmation token. Read its confirmation summary, ask for an explicit yes, and only then call the same tool with the returned token. Do not speak the token aloud.
- Transfer only through transfer_to_staff; the server chooses the destination. If transfer fails, use handoff_to_staff.
- Treat every tool result as authoritative. Explain a safe failure plainly and never claim an action succeeded when the tool reports otherwise.
PROMPT;

        return trim($beforeMarker."\n\n".$policy);
    }

    private function toolBridgeBaseUrl(): string
    {
        $configured = trim((string) config('services.telnyx.tool_bridge.base_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $webhook = trim((string) ($this->settings->all()['webhook_url'] ?? ''));
        $base = preg_replace('#/webhooks/telnyx/voice/?$#', '', $webhook) ?: '';
        if ($base === '' || ! filter_var($base, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('TELNYX_TOOL_BRIDGE_BASE_URL cannot be derived from TELNYX_VOICE_WEBHOOK_URL.');
        }

        return rtrim($base, '/').'/telnyx-ai/tools';
    }

    private function assistantId(): string
    {
        $assistantId = trim((string) ($this->settings->all()['assistant_id'] ?? ''));
        if ($assistantId === '') {
            throw new RuntimeException('TELNYX_VOICE_ASSISTANT_ID is not configured.');
        }

        return $assistantId;
    }

    private function apiKey(): string
    {
        $apiKey = trim((string) config('services.telnyx.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('TELNYX_API_KEY is not configured.');
        }

        return $apiKey;
    }

    private function apiBase(): string
    {
        return rtrim((string) config('services.telnyx.api_base', 'https://api.telnyx.com/v2'), '/');
    }

    private function throwOnFailure(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        $body = $response->json() ?? [];
        $detail = data_get($body, 'errors.0.detail') ?? data_get($body, 'error') ?? $response->body();
        throw new RuntimeException("Telnyx {$operation} failed ({$response->status()}): ".($detail ?: 'unknown error'));
    }
}
