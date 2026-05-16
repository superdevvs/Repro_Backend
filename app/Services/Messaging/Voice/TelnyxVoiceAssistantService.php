<?php

namespace App\Services\Messaging\Voice;

use App\Models\AiChatSession;
use App\Services\ReproAi\ReproAiOrchestrator;

class TelnyxVoiceAssistantService
{
    public function __construct(private readonly ReproAiOrchestrator $orchestrator)
    {
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.telnyx.voice.enabled', false);
    }

    public function assistantId(): ?string
    {
        $assistantId = config('services.telnyx.voice.assistant_id');

        return is_string($assistantId) && $assistantId !== '' ? $assistantId : null;
    }

    public function webhookUrl(): ?string
    {
        $webhookUrl = config('services.telnyx.voice.webhook_url');

        return is_string($webhookUrl) && $webhookUrl !== '' ? $webhookUrl : null;
    }

    public function buildToolContext(AiChatSession $session, array $callContext = []): array
    {
        return array_merge($callContext, [
            'channel' => 'VOICE',
            'voice_provider' => 'TELNYX',
            'ai_session_id' => $session->id,
        ]);
    }
}
