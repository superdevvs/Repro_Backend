<?php

namespace App\Services\ReproAi;

use App\Models\AiChatSession;
use App\Models\AiMessage;
use App\Services\ReproAi\Flows\BookShootFlow;
use App\Services\ReproAi\Flows\ManageBookingFlow;
use App\Services\ReproAi\Flows\AvailabilityFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RuleBasedOrchestrator
{
    public function __construct(
        protected BookShootFlow $bookShootFlow,
        protected ManageBookingFlow $manageBookingFlow,
        protected AvailabilityFlow $availabilityFlow,
    ) {}

    /**
     * @return array{sessionId:string,messages:array,meta:array}
     */
    public function handle(AiChatSession $session, string $message, ?array $context = null): array
    {
        $context ??= [];
        $allowHandoff = (bool) ($context['allow_handoff'] ?? false);
        $fallbackUsed = false;
        $handoffReason = null;

        $pageContext = $this->extractPageContext($context);
        if (!empty($pageContext) && Schema::hasColumn('ai_chat_sessions', 'state_data')) {
            $stateData = is_array($session->state_data ?? null) ? $session->state_data : [];
            $stateData['page_context'] = array_merge($stateData['page_context'] ?? [], $pageContext);
            $session->state_data = $stateData;
        }

        // Check for flow switch request (user wants to change to a different flow)
        $flowSwitch = $this->detectFlowSwitch($message);
        if ($flowSwitch !== false) {
            if ($flowSwitch === null) {
                // Reset the session (go back / start over)
                if (Schema::hasColumn('ai_chat_sessions', 'intent')) {
                    $session->intent = null;
                }
                if (Schema::hasColumn('ai_chat_sessions', 'step')) {
                    $session->step = null;
                }
                if (Schema::hasColumn('ai_chat_sessions', 'state_data')) {
                    $session->state_data = [];
                }
                $session->save();
                return $this->buildResponse($session, $this->fallbackSmallTalk($session));
            } else {
                // Switch to a different flow
                if (Schema::hasColumn('ai_chat_sessions', 'intent')) {
                    $session->intent = $flowSwitch;
                }
                if (Schema::hasColumn('ai_chat_sessions', 'step')) {
                    $session->step = null; // Reset step for new flow
                }
                if (Schema::hasColumn('ai_chat_sessions', 'state_data')) {
                    $session->state_data = [];
                }
                $session->save();
            }
        }

        // Decide / override intent from context (cards / buttons from UI)
        if (isset($context['intent'])) {
            $contextIntent = $context['intent'];
            if (in_array($contextIntent, $this->allowedIntents(), true)) {
                // Always update intent if provided in context
                if (Schema::hasColumn('ai_chat_sessions', 'intent')) {
                    $session->intent = $contextIntent; // e.g. 'book_shoot'
                }
                // Reset step when intent changes from context
                if (Schema::hasColumn('ai_chat_sessions', 'step')) {
                    $session->step = null;
                }
                if (Schema::hasColumn('ai_chat_sessions', 'state_data')) {
                    $session->state_data = [];
                }
            }
        }

        // If still no intent, try to guess from message
        if (!$session->intent || !Schema::hasColumn('ai_chat_sessions', 'intent')) {
            $guessedIntent = $this->guessIntentFromMessage($message);
            $pageIntent = $this->getIntentFromPageContext($context, $session);
            if ($guessedIntent === 'general' && $pageIntent) {
                $guessedIntent = $pageIntent;
            }
            if (!in_array($guessedIntent, $this->allowedIntents(), true)) {
                $guessedIntent = 'general';
            }
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
            $pageIntent = $this->getIntentFromPageContext($context, $session);
            if ($intent === 'general' && $pageIntent) {
                $intent = $pageIntent;
            }
        }
        if (!in_array($intent, $this->allowedIntents(), true)) {
            $intent = 'general';
        }

        // Delegate to specific flow
        if ($intent === 'general') {
            $fallbackUsed = true;
            $handoffReason = 'intent_not_allowed';
            $result = $this->fallbackSmallTalk($session);
        } else {
            try {
                switch ($intent) {
                    case 'book_shoot':
                        $result = $this->bookShootFlow->handle($session, $message, $context);
                        break;
                    case 'manage_booking':
                        $result = $this->manageBookingFlow->handle($session, $message, $context);
                        break;
                    case 'availability':
                        $result = $this->availabilityFlow->handle($session, $message, $context);
                        break;
                    default:
                        $fallbackUsed = true;
                        $handoffReason = 'intent_not_allowed';
                        $result = $this->fallbackSmallTalk($session);
                        break;
                }
            } catch (\Exception $e) {
            \Log::error('Flow execution error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'intent' => $intent,
                'session_id' => $session->id,
            ]);
            $fallbackUsed = true;
            $handoffReason = 'flow_error';
            // Fallback to small talk on any flow error
            $result = $this->fallbackSmallTalk($session);
            }
        }
        
        // Ensure result has required structure
        if (!isset($result['assistant_messages']) || !is_array($result['assistant_messages'])) {
            \Log::warning('Flow returned invalid structure', [
                'intent' => $intent,
                'result' => $result,
            ]);
            $fallbackUsed = true;
            $handoffReason = $handoffReason ?? 'invalid_result';
            $result = $this->fallbackSmallTalk($session);
        }

        if ($allowHandoff && $fallbackUsed) {
            \Log::info('Rule-based handoff triggered', [
                'reason' => $handoffReason ?? 'fallback_smalltalk',
                'intent' => $intent,
                'session_id' => $session->id,
            ]);
            $handoffContext = [
                'reason' => $handoffReason ?? 'fallback_smalltalk',
                'intent' => $intent,
                'step' => Schema::hasColumn('ai_chat_sessions', 'step') ? $session->step : null,
                'state_data' => Schema::hasColumn('ai_chat_sessions', 'state_data')
                    ? (is_array($session->state_data ?? null) ? $session->state_data : [])
                    : [],
            ];

            if (Schema::hasColumn('ai_chat_sessions', 'step')) {
                $session->step = null;
            }
            if (Schema::hasColumn('ai_chat_sessions', 'intent')) {
                $session->intent = null;
            }
            if (Schema::hasColumn('ai_chat_sessions', 'state_data')) {
                $session->state_data = [];
            }
            $session->save();

            return [
                'handoff' => true,
                'handoff_context' => $handoffContext,
            ];
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

    /**
     * @return array<int, string>
     */
    protected function allowedIntents(): array
    {
        return [
            'book_shoot',
            'manage_booking',
            'availability',
        ];
    }

    protected function extractPageContext(array $context): array
    {
        $keys = ['page', 'route', 'tab', 'entityId', 'entityType'];
        $pageContext = array_intersect_key($context, array_flip($keys));
        return array_filter($pageContext, fn ($value) => $value !== null && $value !== '');
    }

    protected function getIntentFromPageContext(array $context, AiChatSession $session): ?string
    {
        $page = $context['page'] ?? null;
        if (!$page && Schema::hasColumn('ai_chat_sessions', 'state_data')) {
            $stateData = is_array($session->state_data ?? null) ? $session->state_data : [];
            $page = $stateData['page_context']['page'] ?? null;
        }

        return match ($page) {
            'book_shoot' => 'book_shoot',
            'availability' => 'availability',
            'shoot_history', 'shoot_details' => 'manage_booking',
            default => null,
        };
    }

    protected function getPageAwareFallback(AiChatSession $session): ?array
    {
        if (!Schema::hasColumn('ai_chat_sessions', 'state_data')) {
            return null;
        }

        $stateData = is_array($session->state_data ?? null) ? $session->state_data : [];
        $page = $stateData['page_context']['page'] ?? null;

        return match ($page) {
            'dashboard' => [
                'assistant_messages' => [[
                    'content' => "You're on the dashboard. Want to manage a booking, book a new shoot, or check availability?",
                    'metadata' => ['type' => 'system'],
                ]],
                'suggestions' => [
                    'Manage a booking',
                    'Book a new shoot',
                    'Check availability',
                    'Show upcoming shoots',
                ],
            ],
            'shoot_history' => [
                'assistant_messages' => [[
                    'content' => "You're in Shoot History. Want to manage or reschedule a booking?",
                    'metadata' => ['type' => 'system'],
                ]],
                'suggestions' => [
                    'Manage a booking',
                    'Reschedule a booking',
                    'Cancel a booking',
                    'Search by address',
                ],
            ],
            'shoot_details' => [
                'assistant_messages' => [[
                    'content' => "You're viewing a shoot. Want to reschedule, cancel, or change services?",
                    'metadata' => ['type' => 'system'],
                ]],
                'suggestions' => [
                    'Reschedule this shoot',
                    'Cancel this booking',
                    'Change services',
                    'Manage another booking',
                ],
            ],
            'book_shoot' => [
                'assistant_messages' => [[
                    'content' => "You're on Book a Shoot. Want me to start a new booking?",
                    'metadata' => ['type' => 'system'],
                ]],
                'suggestions' => [
                    'Book a new shoot',
                    'Tomorrow',
                    'This week',
                    'Next week',
                ],
            ],
            'availability' => [
                'assistant_messages' => [[
                    'content' => "You're on Availability. Want to check slots or block time?",
                    'metadata' => ['type' => 'system'],
                ]],
                'suggestions' => [
                    'Check availability',
                    'Today',
                    'Tomorrow',
                    'All photographers',
                ],
            ],
            default => null,
        };
    }

    protected function guessIntentFromMessage(string $message): string
    {
        $m = strtolower(trim($message));
        
        // Exact matches for common suggestions (highest priority)
        $exactMatches = [
            // Booking
            'book a new shoot' => 'book_shoot',
            'book a shoot' => 'book_shoot',
            'book new shoot' => 'book_shoot',
            'book another shoot' => 'book_shoot',
            // Manage booking
            'manage an existing booking' => 'manage_booking',
            'manage booking' => 'manage_booking',
            'manage another booking' => 'manage_booking',
            // Availability
            'check photographer availability' => 'availability',
            'check availability' => 'availability',
        ];
        
        if (isset($exactMatches[$m])) {
            return $exactMatches[$m];
        }
        
        // Pattern matching (lower priority)
        return match (true) {
            // Booking - be more aggressive with matching
            str_contains($m, 'book') && (str_contains($m, 'shoot') || str_contains($m, 'new') || str_contains($m, 'another')) => 'book_shoot',
            str_contains($m, 'schedule') && !str_contains($m, 'reschedule') && !str_contains($m, 'photographer') => 'book_shoot',
            str_contains($m, 'new shoot') => 'book_shoot',
            
            // Manage booking
            str_contains($m, 'cancel') && str_contains($m, 'booking') => 'manage_booking',
            str_contains($m, 'reschedule') => 'manage_booking',
            str_contains($m, 'manage') && str_contains($m, 'booking') => 'manage_booking',
            str_contains($m, 'change') && (str_contains($m, 'booking') || str_contains($m, 'date') || str_contains($m, 'service')) => 'manage_booking',
            str_contains($m, 'update') && str_contains($m, 'booking') => 'manage_booking',
            
            // Availability (after photographer management to avoid conflicts)
            str_contains($m, 'availability') && !str_contains($m, 'update') => 'availability',
            str_contains($m, 'available') && str_contains($m, 'slot') => 'availability',
            str_contains($m, 'when') && str_contains($m, 'free') => 'availability',
            
            default => 'general',
        };
    }
    
    /**
     * Check if the user wants to switch to a different flow
     */
    protected function detectFlowSwitch(string $message): ?string
    {
        $m = strtolower(trim($message));
        
        // Common phrases that indicate wanting to switch flows
        $switchPatterns = [
            // Booking
            'book a new shoot' => 'book_shoot',
            'book another shoot' => 'book_shoot',
            'let\'s book' => 'book_shoot',
            'i want to book' => 'book_shoot',
            // Manage booking
            'manage another booking' => 'manage_booking',
            // Availability
            'check different date' => 'availability',
            // Reset commands
            'go back' => null,
            'start over' => null,
            'nevermind' => null,
            'main menu' => null,
            'cancel' => null,
        ];
        
        foreach ($switchPatterns as $pattern => $intent) {
            if (str_contains($m, $pattern)) {
                return $intent;
            }
        }
        
        return false; // No switch detected (false means continue current flow)
    }

    protected function fallbackSmallTalk(AiChatSession $session): array
    {
        $pageFallback = $this->getPageAwareFallback($session);
        if ($pageFallback) {
            return $pageFallback;
        }

        // Show available flows when intent is unclear
        return [
            'assistant_messages' => [[
                'content'  => "Hi! I'm Robbie. I can help you with:\n\n" .
                    "**📸 Shoots & Bookings**\n" .
                    "• Book a new shoot\n" .
                    "• Manage existing bookings\n" .
                    "• Check photographer availability\n\n" .
                    "What would you like to do?",
                'metadata' => ['type' => 'system'],
            ]],
            'suggestions' => [
                'Book a new shoot',
                'Manage a booking',
                'Check availability',
            ],
        ];
    }

    /**
     * Build the response from a flow result
     */
    protected function buildResponse(AiChatSession $session, array $result): array
    {
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
}

