<?php

namespace App\Services\ReproAi\Tools;

use App\Services\ReproAi\SupportKnowledgeBase;
use App\Services\RolePermissionService;
use App\Services\TelnyxAi\VoiceToolContextResolver;
use App\Support\SupportContact;

class KnowledgeTools
{
    public function __construct(private SupportKnowledgeBase $knowledge) {}

    public function search(array $params, array $context = []): array
    {
        // Resolve identity again from the protected bridge request. The model's
        // params/context, claimed role, phone number and account ID are not authority.
        if (! request()->routeIs('telnyx-ai.tools.invoke')) {
            return ['error' => 'trusted_voice_context_required'];
        }
        $resolved = app(VoiceToolContextResolver::class)->resolve(request());
        if (! $resolved) {
            return ['error' => 'trusted_call_not_found'];
        }
        $call = $resolved['call'];
        $user = $resolved['context']['verified'] ? ($call->callerUser ?: $call->callerContact?->user) : null;
        if ($user && ! app(RolePermissionService::class)->userCan($user, 'robbie', 'view')) {
            $user = null; // Keep public how-to help; do not expose restricted role guides.
        }
        $query = trim((string) ($params['query'] ?? ''));
        $articles = array_values(array_filter($this->knowledge->search($query, $user),
            fn (array $article) => $this->knowledge->canAnswer($query, $article)));

        return [
            'found' => $articles !== [],
            'scope' => $user ? $this->knowledge->role($user) : 'public',
            'knowledge_version' => $this->knowledge->version(),
            'articles' => array_slice($articles, 0, 3),
            'response_instructions' => 'Explain the best matching guide in one or two short spoken steps, then ask whether that helped. General how-to instructions require no verification code, including conditional steps for different roles. Guides are not live account status or successful actions. Verification is required only to access private records, perform protected actions or retrieve restricted internal guidance. If found is false, ask a focused clarification or offer a staff handoff, not an OTP prerequisite for general help. Do not claim a code, SMS, email, ticket, handoff or notification was sent without a confirming tool result. Do not invent steps or deadlines, or read internal IDs or long URLs aloud.',
            'support_contact' => 'Call or text '.SupportContact::PHONE_DISPLAY.' or email '.SupportContact::EMAIL.'.',
        ];
    }
}
