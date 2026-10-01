<?php

namespace App\Services\ReproAi\Tools;

use App\Models\VoiceCall;
use App\Services\TelnyxAi\VoiceToolContextResolver;
use App\Services\Voice\VoiceCallerVerificationService;

class IdentityTools
{
    public function verifyCaller(array $params, array $context): array
    {
        // JSON/model-supplied phone, user and call IDs are never identity authority.
        // The legacy bridge supplies an actual model, not serialized input.
        $call = request()->routeIs('telnyx-ai.tools.invoke')
            ? (app(VoiceToolContextResolver::class)->resolve(request())['call'] ?? null)
            : ($context['trusted_voice_call'] ?? null);
        if (! $call instanceof VoiceCall || strtoupper((string) ($context['channel'] ?? '')) !== 'VOICE') {
            return ['ok' => false, 'error' => 'trusted_voice_context_required'];
        }

        return app(VoiceCallerVerificationService::class)->verify($call, $params);
    }
}
