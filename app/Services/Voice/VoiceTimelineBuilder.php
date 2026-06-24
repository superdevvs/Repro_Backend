<?php

namespace App\Services\Voice;

use App\Models\VoiceCall;

class VoiceTimelineBuilder
{
    public function build(VoiceCall $call): array
    {
        $events = [];

        if ($call->direction === 'OUTBOUND') {
            $events[] = $this->row($call->created_at, 'Outbound AI call started', $call->status);
        } else {
            $events[] = $this->row($call->created_at, 'Inbound call received', $call->status);
        }

        if ($call->answered_at) {
            $events[] = $this->row($call->answered_at, 'Customer answered', 'answered');
        }
        if ($call->ai_current_state || $call->handled_by === 'ai') {
            $events[] = $this->row($call->provider_event_last_seen_at ?? $call->updated_at, 'Robbie AI joined the call', $call->ai_current_state ?: 'ai_active');
        }
        if ($call->intent) {
            $events[] = $this->row($call->updated_at, 'Caller intent detected: ' . str_replace('_', ' ', $call->intent), 'detected');
        }
        if ($call->needs_follow_up) {
            $events[] = $this->row($call->summary_generated_at ?? $call->updated_at, 'Follow-up suggested', 'needs_follow_up');
        }
        if ($call->ended_at) {
            $events[] = $this->row($call->ended_at, 'Call completed', $call->status);
        }

        return array_slice($events, 0, 8);
    }

    private function row(mixed $time, string $label, ?string $status): array
    {
        return [
            'time' => $time?->toIso8601String(),
            'label' => $label,
            'status' => $status,
        ];
    }
}
