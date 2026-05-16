<?php

namespace App\Services\Messaging\AiSms;

use App\Models\AiChatSession;
use App\Models\AiMessage;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\ReproAi\LlmClient;
use App\Services\ReproAi\ReproAiOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class SmsAiAgentService
{
    public function __construct(
        private readonly SmsContextResolverService $resolver,
        private readonly SmsComplianceService $compliance,
        private readonly SmsConfirmationGate $confirmationGate,
        private readonly SmsResponseFormatter $formatter,
        private readonly SmsRateLimiter $rateLimiter,
        private readonly MessagingService $messaging,
    ) {
    }

    /**
     * Handle a freshly-stored inbound SMS Message: run compliance, identity, AI, reply.
     */
    public function handleInbound(Message $inbound): void
    {
        if ($inbound->channel !== 'SMS' || $inbound->direction !== 'INBOUND') {
            return;
        }

        $thread = $inbound->thread()->first();
        if (!$thread) {
            return;
        }

        // Idempotency: skip if already processed.
        $metadata = $inbound->metadata ?? [];
        if (!empty($metadata['ai_processed'])) {
            return;
        }

        if (!$this->isGloballyEnabled()) {
            $this->markProcessed($inbound, ['skip_reason' => 'global_disabled']);
            return;
        }

        $smsNumber = $this->resolveSmsNumber($inbound);
        if ($smsNumber instanceof SmsNumber && $smsNumber->sms_ai_enabled === false) {
            $this->markProcessed($inbound, ['skip_reason' => 'sms_number_ai_disabled']);
            return;
        }

        $body = trim((string) $inbound->body_text);
        if ($body === '') {
            $this->markProcessed($inbound, ['skip_reason' => 'empty_body']);
            return;
        }

        // Compliance keywords run BEFORE any AI / opt-out checks.
        $keyword = $this->compliance->detectKeyword($body);
        if ($keyword !== null) {
            $this->handleComplianceKeyword($keyword, $inbound, $thread);
            return;
        }

        $resolved = $this->resolver->resolveByE164($inbound->from_address ?? '');
        $contact = $resolved['contact'] ?? $thread->contact;
        $user = $resolved['user'];

        if ($this->compliance->isOptedOut($contact, $user)) {
            // Suppression: don't reply, just record.
            $this->markProcessed($inbound, ['skip_reason' => 'opted_out']);
            return;
        }

        // Per-thread per-contact AI enable flag (default false). Staff opt clients in explicitly.
        if ($contact instanceof Contact && !$contact->sms_ai_enabled) {
            $this->markProcessed($inbound, ['skip_reason' => 'contact_ai_disabled']);
            return;
        }

        // Thread-level staff takeover.
        if ($thread->ai_paused_until && $thread->ai_paused_until->isFuture()) {
            $this->markProcessed($inbound, ['skip_reason' => 'thread_paused']);
            return;
        }

        // Per-thread rate limit.
        if (!$this->rateLimiter->attempt($thread->id)) {
            $thread->forceFill([
                'ai_paused_until' => CarbonImmutable::now()->addHour(),
                'metadata' => array_merge($thread->metadata ?? [], [
                    'ai_rate_limited_at' => CarbonImmutable::now()->toIso8601String(),
                ]),
            ])->save();
            Log::warning('SMS AI rate limit exceeded; thread auto-paused', [
                'thread_id' => $thread->id,
            ]);
            $this->markProcessed($inbound, ['skip_reason' => 'rate_limited']);
            return;
        }

        // Verified senders only get account-bound tools. Unidentified senders get a static
        // identification prompt and never enter the LLM.
        if (!$resolved['identified'] || $user === null) {
            $this->reply(
                $thread,
                $contact,
                "Hi! To help with shoots or payments, I need to verify your identity. Reply with your full name and the email on file.",
                ['skip_reason' => 'unidentified']
            );
            $this->markProcessed($inbound, ['skip_reason' => 'unidentified']);
            return;
        }

        $session = $this->resolveSession($user, $contact, $resolved['phone_e164']);
        $pending = $this->confirmationGate->pending($session);
        $isAffirmative = $pending !== null && $this->confirmationGate->isAffirmative($body);

        if ($pending !== null && !$isAffirmative) {
            // Anything but YES cancels the queued action.
            $this->confirmationGate->clear($session);
        }

        $context = [
            'channel' => 'SMS',
            'phone_e164' => $resolved['phone_e164'],
            'sms_thread_id' => $thread->id,
            'identified' => true,
            'verified' => true,
            'user_id' => $user->id,
            'user_role' => $user->role,
            'contact_id' => $contact?->id,
        ];

        try {
            if ($isAffirmative && $pending !== null) {
                $this->executeConfirmedAction($session, $pending, $thread, $contact, $context);
                $this->markProcessed($inbound, ['ai_action' => 'confirmed', 'tool' => $pending['tool']]);
                return;
            }

            $this->appendUserMessage($session, $body);

            $orchestrator = app(ReproAiOrchestrator::class);
            $assistantMessages = $orchestrator->handle($session, $body, $context);

            $reply = '';
            $toolMetadata = [];
            foreach ($assistantMessages as $msg) {
                $content = trim((string) ($msg['content'] ?? ''));
                if ($content !== '') {
                    $reply = $reply === '' ? $content : ($reply . "\n" . $content);
                }
                AiMessage::create([
                    'chat_session_id' => $session->id,
                    'sender' => 'assistant',
                    'content' => $content,
                    'metadata' => $msg['metadata'] ?? null,
                ]);
                if (!empty($msg['metadata'])) {
                    $toolMetadata[] = $msg['metadata'];
                }
            }

            if ($reply === '') {
                $reply = "I'm having trouble right now — please try again in a moment.";
            }

            $session->forceFill(['last_inbound_at' => CarbonImmutable::now()])->save();

            $this->reply($thread, $contact, $reply, [
                'ai_session_id' => $session->id,
                'tool_calls' => array_values(array_filter(array_map(
                    fn ($m) => $m['tool_calls'] ?? null,
                    $toolMetadata
                ))),
            ]);

            $this->markProcessed($inbound, ['ai_action' => 'replied', 'session_id' => $session->id]);
        } catch (\Throwable $e) {
            Log::error('SmsAiAgentService.handleInbound failed', [
                'message_id' => $inbound->id,
                'error' => $e->getMessage(),
            ]);
            $this->reply($thread, $contact, "Sorry, I hit a snag. A teammate will follow up shortly.", [
                'ai_error' => $e->getMessage(),
            ]);
            $this->markProcessed($inbound, ['ai_action' => 'errored', 'error' => $e->getMessage()]);
        }
    }

    private function handleComplianceKeyword(string $keyword, Message $inbound, MessageThread $thread): void
    {
        $resolved = $this->resolver->resolveByE164($inbound->from_address ?? '');
        $contact = $resolved['contact'] ?? $thread->contact;
        $user = $resolved['user'];

        if ($keyword === 'stop') {
            $this->compliance->applyOptOut($contact, $user);
        } elseif ($keyword === 'start') {
            $this->compliance->applyOptIn($contact, $user);
        }

        $reply = $this->compliance->staticReplyFor($keyword);

        if ($keyword === 'stop') {
            // Sending the stop confirmation is allowed (it's the only message permitted
            // post-opt-out per CTIA). Use bypass_opt_out to actually deliver.
            $this->reply($thread, $contact, $reply, ['compliance_keyword' => $keyword], bypassOptOut: true);
        } else {
            $this->reply($thread, $contact, $reply, ['compliance_keyword' => $keyword]);
        }

        $this->markProcessed($inbound, ['compliance_keyword' => $keyword]);
    }

    private function executeConfirmedAction(
        AiChatSession $session,
        array $pending,
        MessageThread $thread,
        ?Contact $contact,
        array $context
    ): void {
        $orchestrator = app(ReproAiOrchestrator::class);
        $context['confirmation_acknowledged'] = true;

        // Re-issue the same tool call directly via orchestrator-equivalent invocation.
        // We synthesize a one-shot LLM-less execution by calling handle() with a synthetic
        // user message that the LLM will route through the same tool. To avoid an extra LLM
        // round-trip we instead call the tool dispatcher used internally.
        $tool = (string) ($pending['tool'] ?? '');
        $payload = (array) ($pending['payload'] ?? []);

        // The orchestrator's executeTool is private; route through handle() which the LLM
        // can call. We give it a deterministic instruction.
        $instruction = "Execute the previously-confirmed {$tool} action with these parameters: "
            . json_encode($payload) . ". Do not ask for confirmation again.";

        $assistantMessages = $orchestrator->handle($session, $instruction, $context);
        $this->confirmationGate->clear($session);

        $reply = '';
        foreach ($assistantMessages as $msg) {
            $content = trim((string) ($msg['content'] ?? ''));
            if ($content !== '') {
                $reply = $reply === '' ? $content : ($reply . "\n" . $content);
            }
            AiMessage::create([
                'chat_session_id' => $session->id,
                'sender' => 'assistant',
                'content' => $content,
                'metadata' => array_merge($msg['metadata'] ?? [], ['confirmed_tool' => $tool]),
            ]);
        }

        if ($reply === '') {
            $reply = 'Done.';
        }

        $this->reply($thread, $contact, $reply, [
            'ai_session_id' => $session->id,
            'confirmed_tool' => $tool,
        ]);
    }

    private function reply(
        MessageThread $thread,
        ?Contact $contact,
        string $body,
        array $extraMetadata = [],
        bool $bypassOptOut = false
    ): void {
        $contact = $contact ?? $thread->contact;
        if (!$contact) {
            return;
        }

        $to = $contact->phone;
        if (!$to) {
            return;
        }

        $segments = $this->formatter->format($body);
        if ($segments === []) {
            return;
        }

        foreach ($segments as $segment) {
            try {
                $this->messaging->sendSms([
                    'to' => $to,
                    'body_text' => $segment,
                    'contact_phone' => $to,
                    'contact_name' => $contact->name,
                    'contact_type' => $contact->type,
                    'metadata' => array_merge(['ai_generated' => true], $extraMetadata),
                    'bypass_opt_out' => $bypassOptOut,
                    'send_source' => 'AI_SMS_AGENT',
                ]);
            } catch (\Throwable $e) {
                Log::error('AI SMS reply send failed', [
                    'thread_id' => $thread->id,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }
    }

    private function resolveSession(User $user, ?Contact $contact, string $phoneE164): AiChatSession
    {
        $idleTtl = (int) config('services.telnyx.ai_session_idle_ttl_minutes', 1440);
        $cutoff = CarbonImmutable::now()->subMinutes($idleTtl);

        $existing = AiChatSession::query()
            ->where('user_id', $user->id)
            ->where('channel', 'SMS')
            ->where(function ($q) use ($cutoff) {
                $q->where('last_inbound_at', '>=', $cutoff)
                    ->orWhereNull('last_inbound_at');
            })
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            $existing->forceFill([
                'phone_e164' => $phoneE164,
                'contact_id' => $contact?->id,
                'last_inbound_at' => CarbonImmutable::now(),
            ])->save();
            return $existing;
        }

        return AiChatSession::create([
            'user_id' => $user->id,
            'title' => 'SMS conversation',
            'topic' => 'general',
            'engine' => 'sms-agent',
            'channel' => 'SMS',
            'phone_e164' => $phoneE164,
            'contact_id' => $contact?->id,
            'last_inbound_at' => CarbonImmutable::now(),
        ]);
    }

    private function appendUserMessage(AiChatSession $session, string $body): void
    {
        AiMessage::create([
            'chat_session_id' => $session->id,
            'sender' => 'user',
            'content' => $body,
        ]);
    }

    private function markProcessed(Message $message, array $extra = []): void
    {
        $message->forceFill([
            'metadata' => array_merge($message->metadata ?? [], $extra, ['ai_processed' => true]),
        ])->save();
    }

    private function isGloballyEnabled(): bool
    {
        return (bool) config('services.telnyx.ai_sms_enabled', false);
    }

    private function resolveSmsNumber(Message $inbound): ?SmsNumber
    {
        $to = preg_replace('/\D+/', '', (string) $inbound->to_address);
        if ($to === '') {
            return null;
        }

        return SmsNumber::query()
            ->get()
            ->first(function (SmsNumber $number) use ($to): bool {
                $candidate = preg_replace('/\D+/', '', (string) $number->phone_number);

                return $candidate !== '' && (
                    $candidate === $to
                    || str_ends_with($candidate, substr($to, -10))
                    || str_ends_with($to, substr($candidate, -10))
                );
            });
    }
}
