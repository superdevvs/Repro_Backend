<?php

namespace App\Http\Controllers\API\TelnyxAi;

use App\Http\Controllers\Controller;
use App\Models\ToolBridgeInvocation;
use App\Services\ReproAi\ToolDispatcher;
use App\Services\TelnyxAi\ConfirmationTokenService;
use App\Services\TelnyxAi\ToolBridgeRedactor;
use App\Services\TelnyxAi\ToolBridgeRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class TelnyxToolBridgeController extends Controller
{
    public function __construct(
        private readonly ToolBridgeRegistry $registry,
        private readonly ToolDispatcher $dispatcher,
        private readonly ConfirmationTokenService $tokens,
        private readonly ToolBridgeRedactor $redactor,
    ) {
    }

    public function invoke(string $tool, Request $request): JsonResponse
    {
        $started = microtime(true);
        $idempotencyKey = (string) $request->header('Idempotency-Key', Str::uuid()->toString());
        $payload = $request->validate([
            'tool' => ['nullable', 'string'],
            'params' => ['nullable', 'array'],
            'context' => ['nullable', 'array'],
        ]);

        if (($payload['tool'] ?? $tool) !== $tool || !$this->registry->isAllowed($tool)) {
            abort(404);
        }

        $params = $payload['params'] ?? [];
        $context = $payload['context'] ?? [];
        $channel = strtoupper((string) ($context['channel'] ?? 'VOICE'));

        $existing = ToolBridgeInvocation::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing && $existing->response_json) {
            return response()->json($existing->response_json);
        }

        $invocation = $existing ?: ToolBridgeInvocation::create([
            'tool' => $tool,
            'channel' => $channel,
            'phone_e164' => $context['phone_e164'] ?? null,
            'contact_id' => $context['contact_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'telnyx_event_id' => $context['telnyx_event_id'] ?? null,
            'telnyx_conversation_id' => $context['telnyx_conversation_id'] ?? null,
            'call_control_id' => $context['call_control_id'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'status' => 'received',
            'request_json' => $this->redactor->redact($payload),
            'metadata' => ['redacted' => true, 'redaction_version' => '1'],
        ]);

        $response = null;

        try {
            if ($this->registry->isVoiceOnly($tool) && $channel !== 'VOICE') {
                $response = ['ok' => false, 'error' => 'tool_blocked'];
                $this->finish($invocation, $response, $started, 'tool_blocked', 'tool_blocked');
                return response()->json($response, 403);
            }

            if ($this->registry->requiresVerified($tool) && empty($context['verified'])) {
                $response = ['ok' => false, 'error' => 'unverified_caller'];
                $this->finish($invocation, $response, $started, 'unverified_caller', 'unverified_caller');
                return response()->json($response, 403);
            }

            if ($this->registry->requiresConfirmation($tool)) {
                $token = (string) ($params['confirmation_token'] ?? '');
                if ($token === '') {
                    $summary = $this->summaryFor($tool, $params);
                    $issued = $this->tokens->issue($tool, $params, $summary);
                    $response = array_merge(['ok' => false, 'error' => 'requires_confirmation'], $issued);
                    $this->finish($invocation, $response, $started, 'requires_confirmation', 'requires_confirmation');
                    return response()->json($response, 409);
                }

                $confirmed = $this->tokens->consume($token, $tool);
                if (!$confirmed) {
                    $response = ['ok' => false, 'error' => 'invalid_confirmation_token'];
                    $this->finish($invocation, $response, $started, 'tool_blocked', 'invalid_confirmation_token');
                    return response()->json($response, 403);
                }

                $params = $confirmed['params'] ?? $params;
                $context['confirmation_acknowledged'] = true;
            }

            $result = $this->dispatcher->dispatch($tool, $params, $context);
            $response = [
                'ok' => !isset($result['error']) && ($result['ok'] ?? true) !== false,
                'result' => $result,
                'audit_id' => $invocation->id,
            ];

            $status = $response['ok'] ? 'ok' : (string) ($result['error'] ?? 'tool_failed');
            $this->finish($invocation, $response, $started, $status, $response['ok'] ? null : $status);

            return response()->json($response, $response['ok'] ? 200 : 422);
        } catch (Throwable $exception) {
            $response = ['ok' => false, 'error' => 'tool_failed', 'message' => $exception->getMessage(), 'audit_id' => $invocation->id];
            $this->finish($invocation, $response, $started, 'tool_failed', 'tool_failed');

            return response()->json($response, 500);
        }
    }

    private function finish(ToolBridgeInvocation $invocation, array $response, float $started, string $status, ?string $errorCode): void
    {
        $invocation->forceFill([
            'status' => $status,
            'error_code' => $errorCode,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'response_json' => $this->redactor->redact($response),
        ])->save();
    }

    private function summaryFor(string $tool, array $params): string
    {
        return match ($tool) {
            'book_shoot' => 'Reply YES to confirm booking this shoot request.',
            'reschedule_shoot' => 'Reply YES to confirm rescheduling this shoot.',
            'cancel_shoot' => 'Reply YES to confirm cancelling this shoot.',
            'create_payment_link' => 'Reply YES to confirm creating and sending this payment link.',
            default => 'Reply YES to confirm this action.',
        };
    }
}
