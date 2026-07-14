<?php

namespace App\Http\Controllers\API\TelnyxAi;

use App\Http\Controllers\Controller;
use App\Models\ToolBridgeInvocation;
use App\Services\ReproAi\ToolDispatcher;
use App\Services\TelnyxAi\ConfirmationTokenService;
use App\Services\TelnyxAi\ToolBridgeRedactor;
use App\Services\TelnyxAi\ToolBridgeRegistry;
use App\Services\TelnyxAi\VoiceToolContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TelnyxToolBridgeController extends Controller
{
    public function __construct(
        private readonly ToolBridgeRegistry $registry,
        private readonly ToolDispatcher $dispatcher,
        private readonly ConfirmationTokenService $tokens,
        private readonly ToolBridgeRedactor $redactor,
        private readonly VoiceToolContextResolver $contexts,
    ) {}

    public function invoke(string $tool, Request $request): JsonResponse
    {
        if (! $this->registry->isAllowed($tool)) {
            abort(404);
        }

        $body = $request->json()->all();
        if (isset($body['tool']) && $body['tool'] !== $tool) {
            abort(404);
        }

        // Telnyx sends the configured body parameters as a flat JSON object.
        // Keep accepting the legacy params envelope for internal compatibility,
        // but never accept its caller-supplied context as trusted identity.
        $params = isset($body['params']) && is_array($body['params'])
            ? $body['params']
            : Arr::except($body, ['tool', 'params', 'context']);
        $this->validateParameters($tool, $params);

        $resolved = $this->contexts->resolve($request);
        if (! $resolved) {
            return response()->json(['ok' => false, 'error' => 'trusted_call_not_found'], 403);
        }

        $voiceCall = $resolved['call'];
        $context = $resolved['context'];
        if ($this->registry->requiresVerified($tool) && ! $context['verified']) {
            return response()->json(['ok' => false, 'error' => 'unverified_caller'], 403);
        }
        $confirmationToken = trim((string) ($params['confirmation_token'] ?? ''));
        $accessParams = $params;
        if ($confirmationToken !== '' && $this->registry->requiresConfirmation($tool)) {
            $confirmedForAccess = $this->tokens->resolve($confirmationToken, $tool, (int) $context['voice_call_id']);
            if ($confirmedForAccess) {
                $accessParams = $confirmedForAccess['params'] ?? $params;
            } else {
                return response()->json(['ok' => false, 'error' => 'invalid_confirmation_token'], 200);
            }
        }
        if (! $this->contexts->canAccess($tool, $accessParams, $voiceCall, $context)) {
            return response()->json(['ok' => false, 'error' => 'forbidden_resource'], 403);
        }

        unset($params['user_id'], $params['phone_e164'], $params['voice_call_id'], $params['call_control_id'], $params['to']);
        $providerIdempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($confirmationToken !== '') {
            // A confirmation token represents one specific cached mutation. It
            // must win over provider retry headers so concurrent retries cannot
            // acquire different locks and execute the action more than once.
            $idempotencyKey = $this->tokens->executionKey($confirmationToken);
        } elseif ($providerIdempotencyKey !== '') {
            // Scope provider keys to the trusted call and tool. This prevents a
            // reused upstream key from replaying another call's response.
            $idempotencyKey = 'telnyx:'.hash('sha256', implode('|', [
                (string) $context['voice_call_id'],
                $tool,
                $providerIdempotencyKey,
            ]));
        } else {
            $idempotencyKey = Str::uuid()->toString();
        }

        $lock = Cache::lock('telnyx:tool-execution:'.hash('sha256', $idempotencyKey), 30);
        try {
            return $lock->block(5, fn (): JsonResponse => $this->execute(
                $tool,
                $params,
                $context,
                $idempotencyKey,
                $confirmationToken,
            ));
        } catch (Throwable $exception) {
            Log::warning('Telnyx tool lock or execution failed', [
                'tool' => $tool,
                'voice_call_id' => $voiceCall->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['ok' => false, 'error' => 'tool_temporarily_unavailable'], 200);
        }
    }

    private function execute(string $tool, array $params, array $context, string $idempotencyKey, string $confirmationToken): JsonResponse
    {
        $started = microtime(true);
        $existing = ToolBridgeInvocation::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing && $existing->response_json) {
            return response()->json($existing->response_json, 200);
        }

        $invocation = $existing ?: ToolBridgeInvocation::query()->create([
            'tool' => $tool,
            'channel' => 'VOICE',
            'phone_e164' => $context['phone_e164'] ?? null,
            'contact_id' => $context['contact_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'telnyx_conversation_id' => $context['telnyx_conversation_id'] ?? null,
            'call_control_id' => $context['call_control_id'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'status' => 'received',
            'request_json' => $this->redactor->redact(['params' => $params, 'context' => $context]),
            'metadata' => ['redacted' => true, 'redaction_version' => '2', 'trusted_context' => true],
        ]);

        try {
            if ($this->registry->requiresConfirmation($tool)) {
                if ($confirmationToken === '') {
                    $summary = $this->summaryFor($tool, $params);
                    $issued = $this->tokens->issue($tool, $params, $summary, (int) $context['voice_call_id']);
                    $response = array_merge([
                        'ok' => false,
                        'error' => 'requires_confirmation',
                        'requires_confirmation' => true,
                    ], $issued);
                    $this->finish($invocation, $response, $started, 'requires_confirmation', 'requires_confirmation');

                    return response()->json($response, 200);
                }

                if ($stored = $this->tokens->storedResult($confirmationToken)) {
                    $this->finish($invocation, $stored, $started, 'replayed', null);

                    return response()->json($stored, 200);
                }

                $confirmed = $this->tokens->resolve($confirmationToken, $tool, (int) $context['voice_call_id']);
                if (! $confirmed) {
                    $response = ['ok' => false, 'error' => 'invalid_confirmation_token'];
                    $this->finish($invocation, $response, $started, 'tool_blocked', 'invalid_confirmation_token');

                    return response()->json($response, 200);
                }

                $params = $confirmed['params'] ?? [];
                $context['confirmation_acknowledged'] = true;
            }

            $result = $this->dispatcher->dispatch($tool, $params, $context);
            $ok = ! isset($result['error'])
                && ($result['ok'] ?? true) !== false
                && ($result['success'] ?? true) !== false;
            $response = [
                'ok' => $ok,
                'result' => $result,
                'audit_id' => $invocation->id,
            ];
            $status = $ok ? 'ok' : (string) ($result['error'] ?? 'tool_failed');
            $this->finish($invocation, $response, $started, $status, $ok ? null : $status);

            if ($confirmationToken !== '') {
                $this->tokens->storeResult($confirmationToken, $response);
            }

            return response()->json($response, 200);
        } catch (Throwable $exception) {
            Log::error('Telnyx voice tool failed', [
                'tool' => $tool,
                'audit_id' => $invocation->id,
                'error' => $exception->getMessage(),
            ]);
            $response = [
                'ok' => false,
                'error' => 'tool_failed',
                'message' => 'The requested action could not be completed safely.',
                'audit_id' => $invocation->id,
            ];
            $this->finish($invocation, $response, $started, 'tool_failed', 'tool_failed');

            if ($confirmationToken !== '') {
                $this->tokens->storeResult($confirmationToken, $response);
            }

            return response()->json($response, 200);
        }
    }

    private function validateParameters(string $tool, array $params): void
    {
        $definition = $this->registry->definition($tool);
        $allowed = array_keys($definition['schema']['properties'] ?? []);
        $unknown = array_values(array_diff(array_keys($params), $allowed));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'params' => ['Unknown parameters: '.implode(', ', $unknown)],
            ]);
        }

        Validator::make($params, $this->registry->validationRules($tool))->validate();

        if ($tool === 'verify_caller' && empty($params['request_otp']) && empty($params['otp_code'])) {
            throw ValidationException::withMessages(['params' => ['request_otp or otp_code is required.']]);
        }
        if (
            $tool === 'reschedule_shoot'
            && empty($params['confirmation_token'])
            && empty($params['new_date'])
            && empty($params['new_time'])
        ) {
            throw ValidationException::withMessages(['params' => ['new_date or new_time is required.']]);
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
            'book_shoot' => sprintf('Confirm booking a shoot at %s, %s?', $params['address'] ?? 'the requested property', $params['city'] ?? ''),
            'reschedule_shoot' => sprintf('Confirm rescheduling shoot %s to %s %s?', $params['shoot_id'] ?? '', $params['new_date'] ?? '', $params['new_time'] ?? ''),
            'cancel_shoot' => sprintf('Confirm cancelling shoot %s?', $params['shoot_id'] ?? ''),
            'create_payment_link' => sprintf('Confirm creating a payment link for shoot %s?', $params['shoot_id'] ?? ''),
            default => 'Confirm this action?',
        };
    }
}
