<?php

namespace App\Services\Voice;

use App\Models\VoiceCall;
use App\Models\VoiceCallToolInvocation;
use App\Services\ReproAi\ToolDispatcher;
use App\Services\TelnyxAi\ToolBridgeRegistry;
use App\Support\VoiceLocks;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Throwable;

class VoiceToolBridge
{
    private const ALIASES = [
        'check_availability' => 'get_availability',
        'handoff_human' => 'handoff_to_staff',
    ];

    private const VAPI_SAFE_TOOLS = [
        'verify_caller',
        'get_shoot_details',
        'check_availability',
        'get_availability',
        'get_pricing',
    ];

    private const VAPI_CONFIRMATION_GATED = [
        'book_shoot',
        'reschedule_shoot',
        'cancel_shoot',
        'create_payment_link',
        'handoff_human',
        'handoff_to_staff',
    ];

    public function __construct(
        private readonly ToolBridgeRegistry $registry,
        private readonly ToolDispatcher $dispatcher,
    ) {}

    public function handle(VoiceCall $call, string $tool, array $arguments, ?string $providerToolCallId = null): array
    {
        if ($providerToolCallId !== null && $providerToolCallId !== '') {
            try {
                return VoiceLocks::lock('voice:legacy-tool:'.hash('sha256', $call->id.'|'.$providerToolCallId), $tool === 'verify_caller' ? 120 : 30)
                    ->block(5, fn () => $this->execute($call, $tool, $arguments, $providerToolCallId));
            } catch (LockTimeoutException) {
                return ['success' => false, 'error' => 'tool_temporarily_unavailable'];
            }
        }

        return $this->execute($call, $tool, $arguments, $providerToolCallId);
    }

    private function execute(VoiceCall $call, string $tool, array $arguments, ?string $providerToolCallId): array
    {
        // Provider payloads and stale in-memory models cannot grant verification.
        $call = $call->fresh();
        if (! $call || $call->ended_at !== null || in_array(strtolower((string) $call->status), ['completed', 'failed', 'missed', 'cancelled', 'canceled', 'busy', 'no-answer', 'no_answer', 'ended'], true)) {
            return ['success' => false, 'error' => 'trusted_call_not_found'];
        }
        $normalizedTool = self::ALIASES[$tool] ?? $tool;
        $user = $call->callerUser ?: $call->callerContact?->user;
        $eligible = ! ($call->caller_user_id && ! $call->callerUser) && (! $user || $user->isAccountEligibleForAuthentication());
        if (! $this->isAllowed($tool, $normalizedTool)) {
            return ['success' => false, 'error' => 'tool_not_allowed', 'message' => 'This tool is not enabled for Robbie voice calls.'];
        }
        // Static pricing and the registry's public help/verification tools remain
        // available without a code. Every private tool is gated before replay.
        if ($normalizedTool !== 'get_pricing' && $this->registry->requiresVerified($normalizedTool) && ($call->verified_at === null || ! $eligible)) {
            return ['success' => false, 'error' => 'unverified_caller', 'message' => 'Verify the caller before accessing private records or making changes.'];
        }
        $existing = $providerToolCallId
            ? VoiceCallToolInvocation::query()->where('voice_call_id', $call->id)->where('provider_tool_call_id', $providerToolCallId)->first()
            : null;

        $auditArguments = $arguments;
        if ($normalizedTool === 'verify_caller') {
            foreach (['otp_code', 'value'] as $secretKey) {
                if (isset($auditArguments[$secretKey])) {
                    $auditArguments[$secretKey] = 'receipt:'.hash_hmac('sha256', (string) $auditArguments[$secretKey], (string) config('app.key'));
                }
            }
        }
        if ($existing && (($existing->tool_name !== $tool) || $existing->input_payload != $auditArguments)) {
            return ['success' => false, 'error' => 'tool_call_conflict', 'message' => 'The tool call ID was reused for a different request.'];
        }
        // Re-evaluate read authorization after role, ownership or permission changes.
        $freshRead = in_array($normalizedTool, ['get_shoot_details', 'list_shoots', 'get_payment_status', 'get_availability', 'search_support_knowledge'], true);
        if ($existing && $existing->output_payload && ! $freshRead) {
            if ($normalizedTool === 'verify_caller' && data_get($existing->output_payload, 'result.result.verified') === true
                && ($call->verified_at === null || ! $eligible)) {
                return ['success' => false, 'error' => 'verification_failed'];
            }

            return $existing->output_payload;
        }

        $invocation = $existing ?: VoiceCallToolInvocation::query()->create([
            'voice_call_id' => $call->id,
            'tool_name' => $tool,
            'provider_tool_call_id' => $providerToolCallId,
            'status' => VoiceCallToolInvocation::STATUS_PENDING,
            'input_payload' => $auditArguments,
            'requires_confirmation' => $this->requiresConfirmation($tool),
        ]);

        if ($this->requiresConfirmation($tool)) {
            $message = $this->confirmationMessage($tool);

            return $this->finish($invocation, [
                'success' => false,
                'requires_confirmation' => true,
                'message' => $message,
                'tool_invocation_id' => $invocation->id,
            ], VoiceCallToolInvocation::STATUS_PENDING);
        }

        if ($tool === 'get_pricing') {
            return $this->finish($invocation, [
                'success' => true,
                'result' => [
                    'message' => 'Pricing varies by property and selected services. Offer to send a quote or connect the caller with the team.',
                ],
                'tool_invocation_id' => $invocation->id,
            ], VoiceCallToolInvocation::STATUS_EXECUTED);
        }

        try {
            $result = $this->dispatcher->dispatch($normalizedTool, $arguments, [
                'channel' => 'VOICE',
                'trusted_voice_call' => $normalizedTool === 'verify_caller' ? $call : null,
                'voice_call_id' => $call->id,
                'phone_e164' => strtoupper((string) $call->direction) === 'OUTBOUND' ? $call->to_phone : $call->from_phone,
                'contact_id' => $call->caller_contact_id,
                'user_id' => $eligible && $call->verified_at !== null ? $call->caller_user_id : null,
                'verified' => $eligible && (bool) $call->verified_at,
                'vapi_call_id' => $call->vapi_call_id,
            ]);

            return $this->finish($invocation, [
                'success' => ! isset($result['error']) && ($result['ok'] ?? true) !== false && ($result['success'] ?? true) !== false,
                'result' => $result,
                'tool_invocation_id' => $invocation->id,
            ], VoiceCallToolInvocation::STATUS_EXECUTED);
        } catch (Throwable $exception) {
            return $this->finish($invocation, [
                'success' => false,
                'error' => 'tool_failed',
                'message' => $exception->getMessage(),
                'tool_invocation_id' => $invocation->id,
            ], VoiceCallToolInvocation::STATUS_FAILED, $exception->getMessage());
        }
    }

    private function isAllowed(string $tool, string $normalizedTool): bool
    {
        if (in_array($tool, self::VAPI_SAFE_TOOLS, true) || in_array($tool, self::VAPI_CONFIRMATION_GATED, true)) {
            return true;
        }

        return $this->registry->isAllowed($normalizedTool);
    }

    private function requiresConfirmation(string $tool): bool
    {
        return in_array($tool, self::VAPI_CONFIRMATION_GATED, true);
    }

    private function finish(VoiceCallToolInvocation $invocation, array $response, string $status, ?string $error = null): array
    {
        $invocation->forceFill([
            'status' => $status,
            'output_payload' => $response,
            'error_message' => $error,
        ])->save();

        return $response;
    }

    private function confirmationMessage(string $tool): string
    {
        return match ($tool) {
            'book_shoot' => 'I found an available slot. Please confirm before I book it.',
            'reschedule_shoot' => 'I can reschedule this shoot, but I need confirmation before changing it.',
            'cancel_shoot' => 'I can cancel this shoot, but I need confirmation before removing it.',
            'create_payment_link' => 'I can create a payment link, but I need confirmation before sending it.',
            'handoff_human', 'handoff_to_staff' => 'I can hand this to the team, but I need confirmation before paging a person.',
            default => 'Please confirm before I take this action.',
        };
    }
}
