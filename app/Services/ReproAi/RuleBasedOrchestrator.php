<?php

namespace App\Services\ReproAi;

use App\Models\AiChatSession;
use App\Models\AiMessage;
use App\Services\ReproAi\Flows\BookShootFlow;
use App\Services\ReproAi\Flows\ManageBookingFlow;
use App\Services\ReproAi\Flows\AvailabilityFlow;
use App\Services\ReproAi\Flows\ClientStatsFlow;
use App\Services\ReproAi\Flows\AccountingFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RuleBasedOrchestrator
{
    public function __construct(
        protected BookShootFlow $bookShootFlow,
        protected ManageBookingFlow $manageBookingFlow,
        protected AvailabilityFlow $availabilityFlow,
        protected ClientStatsFlow $clientStatsFlow,
        protected AccountingFlow $accountingFlow,
    ) {}

    /**
     * @return array{sessionId:string,messages:array,meta:array}
     */
    public function handle(AiChatSession $session, string $message, ?array $context = null): array
    {
        $context ??= [];

        // Decide / override intent from context (cards / buttons from UI)
        if (isset($context['intent']) && !$session->intent) {
            // Only set if column exists
            if (Schema::hasColumn('ai_chat_sessions', 'intent')) {
                $session->intent = $context['intent']; // e.g. 'book_shoot'
            }
        }

        // If still no intent, try to guess from message
        if (!$session->intent || !Schema::hasColumn('ai_chat_sessions', 'intent')) {
            $guessedIntent = $this->guessIntentFromMessage($message);
            // Only set if column exists
            if (Schema::hasColumn('ai_chat_sessions', 'intent')) {
                $session->intent = $guessedIntent; // Always set (never null now)
            }
        }

        // Set engine if column exists
        if (Schema::hasColumn('ai_chat_sessions', 'engine')) {
            $session->engine ??= 'rules';
        }
        
        // Save session (only fields that exist will be saved)
        $session->save();

        // Get intent (use guessed intent if column doesn't exist or session intent is null)
        $intent = $session->intent;
        if (!$intent || !Schema::hasColumn('ai_chat_sessions', 'intent')) {
            $intent = $this->guessIntentFromMessage($message);
        }

        // Delegate to specific flow
        try {
            $result = match ($intent) {
                'book_shoot'      => $this->bookShootFlow->handle($session, $message, $context),
                'manage_booking'  => $this->manageBookingFlow->handle($session, $message, $context),
                'availability'    => $this->availabilityFlow->handle($session, $message, $context),
                'client_stats'    => $this->clientStatsFlow->handle($session, $message, $context),
                'accounting'      => $this->accountingFlow->handle($session, $message, $context),
                'greeting'        => $this->fallbackSmallTalk($session),
                default           => $this->fallbackSmallTalk($session), // 'general' and any other unknown intents
            };
        } catch (\Exception $e) {
            \Log::error('Flow execution error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'intent' => $intent,
                'session_id' => $session->id,
            ]);
            // Fallback to small talk on any flow error
            $result = $this->fallbackSmallTalk($session);
        }
        
        // Ensure result has required structure
        if (!isset($result['assistant_messages']) || !is_array($result['assistant_messages'])) {
            \Log::warning('Flow returned invalid structure', [
                'intent' => $intent,
                'result' => $result,
            ]);
            $result = $this->fallbackSmallTalk($session);
        }

        // Persist assistant messages
        DB::transaction(function () use ($session, $result) {
            foreach ($result['assistant_messages'] ?? [] as $msg) {
                AiMessage::create([
                    'chat_session_id' => $session->id,
                    'sender'          => 'assistant',
                    'content'         => $msg['content'],
                    'metadata'        => $msg['metadata'] ?? null,
                ]);
            }
        });

        // Build full history for frontend
        $messages = $session->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn (AiMessage $m) => [
                'id'        => (string) $m->id,
                'sender'    => $m->sender,
                'content'   => $m->content,
                'createdAt' => $m->created_at->toIso8601String(),
                'metadata'  => $m->metadata,
            ])->all();

        return [
            'sessionId' => (string) $session->id,
            'messages'  => $messages,
            'meta'      => [
                'suggestions' => $result['suggestions'] ?? [],
                'actions'     => $result['actions'] ?? [],
            ],
        ];
    }

    protected function guessIntentFromMessage(string $message): string
    {
        $m = strtolower($message);
        return match (true) {
            str_contains($m, 'book') && str_contains($m, 'shoot') => 'book_shoot',
            str_contains($m, 'schedule') => 'book_shoot',
            str_contains($m, 'cancel') 
            || str_contains($m, 'reschedule')
            || str_contains($m, 'change') => 'manage_booking',
            str_contains($m, 'availability')
            || str_contains($m, 'available') => 'availability',
            str_contains($m, 'stats')
            || str_contains($m, 'client') => 'client_stats',
            str_contains($m, 'invoice')
            || str_contains($m, 'revenue')
            || str_contains($m, 'accounting') => 'accounting',
            str_contains($m, 'hi')
            || str_contains($m, 'hello')
            || str_contains($m, 'hey') => 'greeting',
            default => 'general',   // <- FIXED: never null
        };
    }

    protected function fallbackSmallTalk(AiChatSession $session): array
    {
        // Show available flows when intent is unclear
        // Prioritize "Book a new shoot" as the first suggestion
        return [
            'assistant_messages' => [[
                'content'  => "Hi! I'm Robbie. I can help you with:\n\n• 📸 **Book a new shoot**\n• 📅 Manage existing bookings\n• 👤 Check photographer availability\n• 📊 View client statistics\n• 💰 See accounting summaries\n\nWhat would you like to do?",
                'metadata' => ['type' => 'system'],
            ]],
            'suggestions' => [
                'Book a new shoot',  // First and most prominent
                'Manage an existing booking',
                'Check photographer availability',
                'View client stats',
                'See accounting summary',
            ],
        ];
    }
}

