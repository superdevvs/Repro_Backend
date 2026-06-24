<?php

namespace App\Services\Voice;

class VoiceCallStateMapper
{
    public const CLOSED_STATUSES = ['completed', 'missed', 'failed', 'cancelled'];

    public function fromVapiStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'scheduled', 'queued' => 'queued',
            'started', 'initiating' => 'initiating',
            'ringing' => 'ringing',
            'in-progress', 'in_progress', 'active', 'answered' => 'answered',
            'forwarding' => 'human_handoff',
            'ended', 'completed', 'complete' => 'completed',
            'failed', 'busy', 'no-answer', 'no_answer' => 'failed',
            'cancelled', 'canceled' => 'cancelled',
            default => 'initiating',
        };
    }

    public function fromVapiMessageType(?string $type): ?string
    {
        return match (strtolower((string) $type)) {
            'assistant-started', 'assistant.started', 'assistant-speech-started', 'speech-update', 'model-output' => 'ai_active',
            'tool-calls', 'tool-call', 'function-call', 'function_call' => 'tool_running',
            'end-of-call-report' => 'completed',
            'hang' => null,
            default => null,
        };
    }

    public function endedStatus(bool $answered, ?string $endedReason = null): string
    {
        $reason = strtolower((string) $endedReason);
        if (str_contains($reason, 'fail') || str_contains($reason, 'error')) {
            return 'failed';
        }

        return $answered ? 'completed' : 'missed';
    }

    public function isClosed(?string $status): bool
    {
        return in_array((string) $status, self::CLOSED_STATUSES, true);
    }
}
