<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AiChatSession;
use App\Models\AiMessage;
use App\Services\ReproAi\RuleBasedOrchestrator;
use App\Services\ReproAi\ReproAiOrchestrator;
use App\Services\ReproAi\LlmClient;
use App\Services\ReproAi\IntentScorer;
use App\Services\ReproAi\IntentPolicy;
use App\Services\ReproAi\ShootOperatorService;
use App\Services\RobbieConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiChatController extends Controller
{
    private ?RuleBasedOrchestrator $ruleOrchestrator = null;
    private ?ReproAiOrchestrator $openAiOrchestrator = null;
    private RobbieConfigService $robbieConfigService;
    private IntentScorer $intentScorer;
    private IntentPolicy $intentPolicy;
    private ShootOperatorService $shootOperatorService;

    public function __construct(?RuleBasedOrchestrator $ruleOrchestrator = null, ?ReproAiOrchestrator $openAiOrchestrator = null)
    {
        $this->ruleOrchestrator = $ruleOrchestrator ?? app(RuleBasedOrchestrator::class);
        $this->robbieConfigService = app(RobbieConfigService::class);
        $this->intentScorer = app(IntentScorer::class);
        $this->intentPolicy = app(IntentPolicy::class);
        $this->shootOperatorService = app(ShootOperatorService::class);
        // Initialize OpenAI orchestrator with LlmClient
        try {
            $llmClient = app(LlmClient::class);
            $this->openAiOrchestrator = $openAiOrchestrator ?? new ReproAiOrchestrator($llmClient);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'warning');
            $this->openAiOrchestrator = null;
        }
    }

    /**
     * Handle chat message
     * POST /api/ai/chat
     */
    public function chat(Request $request)
    {
        // Wrap entire method in try-catch to catch any fatal errors
        try {
            // Log incoming request for debugging
            try {
                Log::info('AI Chat request received', [
                    'method' => $request->method(),
                    'url' => $request->fullUrl(),
                    'has_auth' => $request->user() !== null,
                    'user_id' => $request->user()?->id,
                ]);
            } catch (\Exception $e) {
                // Logging failed, continue anyway
            }

            $validated = $request->validate([
                'sessionId' => ['nullable', 'string'],
                'message'   => ['required', 'string'],
                'context'   => ['nullable', 'array'],
            ]);

            $clientContext = $validated['context'] ?? [];

            $user = $request->user();
            if (!$user) {
                try {
                    Log::warning('AI Chat: Unauthorized request', [
                        'ip' => $request->ip(),
                    ]);
                } catch (\Exception $e) {
                    // Ignore logging errors
                }
                return response()->json(['message' => 'Unauthorized'], 401)
                    ->header('Access-Control-Allow-Origin', $request->headers->get('Origin', '*'));
            }

            // Load or create session
            $session = null;
            try {
                $sessionId = $validated['sessionId'] ?? null;
                $session = $sessionId
                    ? AiChatSession::where('id', $sessionId)
                        ->where('user_id', $user->id)
                        ->first()
                    : null;

                if (!$session) {
                    $sessionData = [
                        'user_id' => $user->id,
                        'title'   => 'New conversation',
                    ];
                    
                    // Only set engine if column exists (migration may not have run yet)
                    if (Schema::hasColumn('ai_chat_sessions', 'engine')) {
                        $sessionData['engine'] = 'openai'; // Default to OpenAI
                    }
                    
                    $session = AiChatSession::create($sessionData);
                }
            } catch (\Exception $e) {
                \App\Services\ApiErrorResponder::log($e, 'error');
                throw $e; // Re-throw to be caught by outer handler
            }

            // Persist user message
            try {
                DB::transaction(function () use ($session, $validated, $clientContext) {
                    AiMessage::create([
                        'chat_session_id' => $session->id,
                        'sender'           => 'user',
                        'content'          => $validated['message'],
                        'metadata'         => $clientContext ?: null,
                    ]);
                });
            } catch (\Exception $e) {
                \App\Services\ApiErrorResponder::log($e, 'error');
            }

            // Check if we're already in a rule-based flow (session has a step set)
            // Refresh session to get latest step value
            $session->refresh();
            
            $isInRuleBasedFlow = false;
            if (Schema::hasColumn('ai_chat_sessions', 'step')) {
                $sessionStep = $session->step ?? null;
                // If session has a step, we're in a rule-based flow (e.g., BookShootFlow)
                if (!empty($sessionStep) && $sessionStep !== 'done') {
                    $isInRuleBasedFlow = true;
                    Log::info('Session is in rule-based flow', [
                        'session_id' => $session->id,
                        'step' => $sessionStep,
                        'message' => $validated['message'],
                    ]);
                }
            }
            
            // Detect intent early - only use rule-based for very specific intents
            $detected = $this->detectIntent($validated['message'], $clientContext);
            $detectedIntent = $detected['intent'] ?? 'general';
            $detectedSource = $detected['source'] ?? 'legacy';
            $detectedConfidence = $detected['confidence'] ?? null;
            Log::info('AI intent detected', [
                'intent' => $detectedIntent,
                'source' => $detectedSource,
                'confidence' => $detectedConfidence,
                'matched' => $detected['matched'] ?? [],
                'session_id' => $session->id,
                'user_id' => $user->id,
            ]);
            // Strict policy: rule-based only for explicit transactional intents
            $shouldUseRuleBased = $this->intentPolicy->isRuleBased(
                $detectedIntent,
                $detectedConfidence ?? 0.0
            );
            
            // If we're already in a rule-based flow, always continue with rule-based
            if ($isInRuleBasedFlow) {
                $shouldUseRuleBased = true;
            }
            
            // Determine which orchestrator to use (OpenAI preferred, fallback to rule-based)
            $useOpenAI = true;
            if (Schema::hasColumn('ai_chat_sessions', 'engine')) {
                $session->engine = $session->engine ?? 'openai';
                $useOpenAI = ($session->engine === 'openai') && !$shouldUseRuleBased;
            } else {
                $useOpenAI = !$shouldUseRuleBased;
            }
            
            $result = null;
            
            // Try OpenAI orchestrator first if available and not a rule-based intent
            $serverContext = $clientContext;
            $serverContext['user_id'] = $user->id;
            $serverContext['user_role'] = $user->role;
            try {
                $serverContext['robbie_config'] = $this->robbieConfigService
                    ->getMergedConfigForRole($user->role);
            } catch (\Exception $configError) {
                \App\Services\ApiErrorResponder::log($configError, 'warning');
                $serverContext['robbie_config'] = $this->robbieConfigService->getDefaultConfig();
            }

            $shootOperatorResult = $this->shootOperatorService->handle(
                $session,
                $validated['message'],
                $serverContext,
                $user
            );

            // The operator may decline the message (it recognised the intent but
            // could not resolve the record). Falling through to the orchestrators
            // keeps the conversation alive instead of dead-ending the user.
            if (is_array($shootOperatorResult) && ($shootOperatorResult['handoff'] ?? false)) {
                Log::info('Shoot operator handed off to orchestrator', [
                    'session_id' => $session->id,
                    'reason' => $shootOperatorResult['handoff_reason'] ?? null,
                ]);
                $serverContext['handoff'] = $shootOperatorResult['handoff_context'] ?? [];
                $shootOperatorResult = null;
            }

            if (is_array($shootOperatorResult)) {
                return response()->json($this->persistAssistantResult($session, $shootOperatorResult))
                    ->header('Access-Control-Allow-Origin', $origin ?? $request->headers->get('Origin', '*'))
                    ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
                    ->header('Access-Control-Allow-Credentials', 'true');
            }

            $openAiFailed = false;
            if ($useOpenAI && $this->openAiOrchestrator && !$shouldUseRuleBased) {
                try {
                    // Get assistant messages from OpenAI orchestrator
                    $assistantMessages = $this->openAiOrchestrator->handle(
                        $session,
                        $validated['message'],
                        $serverContext
                    );
                    
                    // Persist assistant messages
                    DB::transaction(function () use ($session, $assistantMessages) {
                        foreach ($assistantMessages ?? [] as $msg) {
                            AiMessage::create([
                                'chat_session_id' => $session->id,
                                'sender'          => $msg['sender'] ?? 'assistant',
                                'content'         => $msg['content'] ?? '',
                                'metadata'        => $msg['metadata'] ?? null,
                            ]);
                        }
                    });
                    
                    // Convert to expected format
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
                    
                    $result = [
                        'sessionId' => (string) $session->id,
                        'messages'  => $messages,
                        'meta'      => [
                            'suggestions' => [],
                            'actions'     => [],
                        ],
                    ];
                } catch (\Exception $openAiError) {
                    \App\Services\ApiErrorResponder::log($openAiError, 'warning');
                    // Fall through to rule-based orchestrator
                    $useOpenAI = false;
                    $shouldUseRuleBased = true; // Force rule-based on OpenAI failure
                    $openAiFailed = true;
                }
            }
            
            // Use rule-based orchestrator if OpenAI failed, not available, or intent detected
            if (!$useOpenAI || !$this->openAiOrchestrator || $shouldUseRuleBased) {
                try {
                    // Ensure rule-based orchestrator is available
                    if (!$this->ruleOrchestrator) {
                        try {
                            $this->ruleOrchestrator = app(RuleBasedOrchestrator::class);
                        } catch (\Exception $diError) {
                            \App\Services\ApiErrorResponder::log($diError, 'error');
                            throw new \RuntimeException('Failed to initialize AI service: ' . $diError->getMessage());
                        }
                    }
                    
                    // Pass detected intent in context only if NOT already in a rule-based flow.
                    // When already in a flow, the session's persisted intent should be used
                    // to avoid mid-flow intent switches that cause loops.
                    $ruleContext = $serverContext;
                    if (!$isInRuleBasedFlow && $detectedIntent && $detectedIntent !== 'general') {
                        $ruleContext['intent'] = $detectedIntent;
                    }
                    $ruleContext['allow_handoff'] = !$openAiFailed && $this->openAiOrchestrator !== null;
                    
                    $result = $this->ruleOrchestrator->handle(
                        $session,
                        $validated['message'],
                        $ruleContext
                    );

                    if (is_array($result) && ($result['handoff'] ?? false) && $this->openAiOrchestrator) {
                        $handoffContext = $result['handoff_context'] ?? [];
                        $openAiContext = $serverContext;
                        $openAiContext['handoff'] = $handoffContext;
                        try {
                            $assistantMessages = $this->openAiOrchestrator->handle(
                                $session,
                                $validated['message'],
                                $openAiContext
                            );

                            DB::transaction(function () use ($session, $assistantMessages) {
                                foreach ($assistantMessages ?? [] as $msg) {
                                    AiMessage::create([
                                        'chat_session_id' => $session->id,
                                        'sender'          => $msg['sender'] ?? 'assistant',
                                        'content'         => $msg['content'] ?? '',
                                        'metadata'        => $msg['metadata'] ?? null,
                                    ]);
                                }
                            });
                        } catch (\Exception $handoffError) {
                            \App\Services\ApiErrorResponder::log($handoffError, 'warning');

                            AiMessage::create([
                                'chat_session_id' => $session->id,
                                'sender'          => 'assistant',
                                'content'         => "I'm having trouble completing that right now. Please try again.",
                                'metadata'        => ['error' => 'handoff_failed'],
                            ]);
                        }

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

                        $result = [
                            'sessionId' => (string) $session->id,
                            'messages'  => $messages,
                            'meta'      => [
                                'suggestions' => [],
                                'actions'     => [],
                            ],
                        ];
                    }
                } catch (\Exception $ruleError) {
                    \App\Services\ApiErrorResponder::log($ruleError, 'error');
                    throw $ruleError;
                }
            }

            // Get origin for CORS on success response
            $origin = $request->headers->get('Origin', '*');
            if (!in_array($origin, ['http://localhost:5173', 'http://localhost:5174', 'http://127.0.0.1:5173'])) {
                $origin = '*';
            }

            return response()->json($result)
                ->header('Access-Control-Allow-Origin', $origin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
                ->header('Access-Control-Allow-Credentials', 'true');
        } catch (\Throwable $e) {
            // Log the full error
            try {
                \App\Services\ApiErrorResponder::log($e, 'error');
            } catch (\Exception $logError) {
                // Even logging failed, but continue
            }

            // Get origin for CORS
            $origin = $request->headers->get('Origin', '*');
            if (!in_array($origin, ['http://localhost:5173', 'http://localhost:5174', 'http://127.0.0.1:5173'])) {
                $origin = '*';
            }

            // Return error with CORS headers
            return response()->json([
                'message' => 'Failed to process chat message',
                'error' => config('app.debug') ? \App\Services\ApiErrorResponder::publicMessage($e) : 'An error occurred while processing your message',
                'debug' => null,
            ], 500)
            ->header('Access-Control-Allow-Origin', $origin)
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
            ->header('Access-Control-Allow-Credentials', 'true');
        }
    }

    /**
     * Detect intent from message and context
     * This allows us to route certain intents directly to rule-based flows
     */
    private function detectIntent(string $message, array $context): array
    {
        // Check context first (from UI buttons/cards)
        if (isset($context['intent']) && !empty($context['intent'])) {
            return [
                'intent' => $context['intent'],
                'score' => 1.0,
                'confidence' => 1.0,
                'matched' => ['context'],
                'source' => 'context',
            ];
        }

        $normalized = strtolower(trim($message));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';
        $recentShootTriggers = [
            'recent shoots',
            'recent shoot',
            'previous shoots',
            'past shoots',
            'show my shoots',
            'show my recent shoots',
            'show shoots',
            'list shoots',
            'my shoots',
            'recent bookings',
            'recent booking',
            'show my bookings',
            'show bookings',
            'list bookings',
            'my bookings',
        ];

        foreach ($recentShootTriggers as $trigger) {
            if ($trigger !== '' && str_contains($normalized, $trigger)) {
                return [
                    'intent' => 'manage_booking',
                    'score' => 1.2,
                    'confidence' => 1.0,
                    'matched' => [$trigger],
                    'source' => 'heuristic',
                ];
            }
        }

        $scored = $this->intentScorer->score($message);

        return [
            'intent' => $scored['name'] ?? 'general',
            'score' => $scored['score'] ?? 0.0,
            'confidence' => $scored['confidence'] ?? 0.0,
            'matched' => $scored['matched'] ?? [],
            'source' => 'registry',
        ];
    }

    /**
     * Get chat sessions
     * GET /api/ai/sessions
     */
    public function sessions(Request $request)
    {
        $user = auth()->user();
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $query = AiChatSession::where('user_id', $user->id)
            ->withCount('messages')
            ->with(['latestMessage'])
            ->orderBy('updated_at', 'desc');

        // Search filter
        if ($request->has('query') && !empty($request->query('query'))) {
            $searchTerm = $request->query('query');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', "%{$searchTerm}%")
                  ->orWhereHas('messages', function ($msgQuery) use ($searchTerm) {
                      $msgQuery->where('content', 'like', "%{$searchTerm}%");
                  });
            });
        }

        // Pagination
        $perPage = min($request->query('per_page', 20), 100);
        $sessions = $query->paginate($perPage);

        // Calculate stats
        $allSessions = AiChatSession::where('user_id', $user->id)->get();
        $oneWeekAgo = now()->subWeek();
        $thisWeekCount = $allSessions->filter(function ($s) use ($oneWeekAgo) {
            return $s->created_at->isAfter($oneWeekAgo);
        })->count();

        $totalMessages = $allSessions->sum(function ($s) {
            return $s->messages()->count();
        });
        $avgMessages = $allSessions->count() > 0 
            ? round($totalMessages / $allSessions->count(), 1) 
            : 0;

        $topicCounts = $allSessions->groupBy('topic')->map->count();
        $topTopic = $topicCounts->sortDesc()->keys()->first() ?? 'general';

        $items = collect($sessions->items())->map(function (AiChatSession $session) {
            $latest = $session->latestMessage;
            $preview = null;
            if ($latest && is_string($latest->content)) {
                $clean = trim(preg_replace('/\s+/', ' ', $latest->content));
                if ($clean !== '') {
                    $preview = \Illuminate\Support\Str::limit($clean, 80);
                }
            }

            return [
                'id' => $session->id,
                'title' => $session->title,
                'topic' => $session->topic,
                'messages_count' => $session->messages_count,
                'messageCount' => $session->messages_count,
                'preview' => $preview,
                'created_at' => optional($session->created_at)->toISOString(),
                'updated_at' => optional($session->updated_at)->toISOString(),
                'createdAt' => optional($session->created_at)->toISOString(),
                'updatedAt' => optional($session->updated_at)->toISOString(),
            ];
        })->all();

        return response()->json([
            'data' => $items,
            'meta' => [
                'pagination' => [
                    'current_page' => $sessions->currentPage(),
                    'per_page' => $sessions->perPage(),
                    'total' => $sessions->total(),
                    'last_page' => $sessions->lastPage(),
                ],
                'stats' => [
                    'thisWeekCount' => $thisWeekCount,
                    'avgMessagesPerSession' => $avgMessages,
                    'topTopic' => $topTopic,
                ],
            ],
        ]);
    }

    /**
     * Get session messages
     * GET /api/ai/sessions/{session}
     */
    public function sessionMessages(Request $request, string $sessionId)
    {
        $user = auth()->user();
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $session = AiChatSession::where('id', $sessionId)
            ->where('user_id', $user->id)
            ->with('messages')
            ->firstOrFail();

        $messages = $session->messages->map(function ($msg) {
            return [
                'id' => $msg->id,
                'sender' => $msg->sender,
                'content' => $msg->content,
                'metadata' => $msg->metadata,
                'createdAt' => $msg->created_at->toISOString(),
            ];
        });

        return response()->json([
            'session' => [
                'id' => $session->id,
                'title' => $session->title,
                'topic' => $session->topic,
                'createdAt' => $session->created_at->toISOString(),
                'updatedAt' => $session->updated_at->toISOString(),
            ],
            'messages' => $messages,
        ]);
    }

    /**
     * Generate a title from message
     */
    private function generateTitleFromMessage(string $message): string
    {
        // Extract key phrases
        $message = trim($message);
        
        // Try to extract property address or listing reference
        if (preg_match('/(\d+\s+[\w\s]+(?:Drive|Street|Avenue|Road|Lane|Way|Court|Place|Boulevard))/i', $message, $matches)) {
            return 'Listing for ' . $matches[1];
        }
        
        // Try to extract action + subject
        if (preg_match('/^(book|schedule|create|improve|rewrite|get|summarize)\s+(.+?)(?:\.|$)/i', $message, $matches)) {
            $action = ucfirst(strtolower(trim($matches[1])));
            $subject = trim($matches[2]);
            if (strlen($subject) > 40) {
                $subject = substr($subject, 0, 37) . '...';
            }
            return $action . ' ' . $subject;
        }
        
        // Fallback: first 50 characters
        if (strlen($message) > 50) {
            return substr($message, 0, 47) . '...';
        }
        
        return $message ?: 'New Chat';
    }

    /**
     * Delete a chat session
     * DELETE /api/ai/sessions/{session}
     */
    public function deleteSession(Request $request, string $sessionId)
    {
        $user = auth()->user();
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $session = AiChatSession::where('id', $sessionId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Delete all messages first
        $session->messages()->delete();
        
        // Delete the session
        $session->delete();

        return response()->json([
            'message' => 'Session deleted successfully',
        ]);
    }

    /**
     * Archive a chat session
     * POST /api/ai/sessions/{session}/archive
     */
    public function archiveSession(Request $request, string $sessionId)
    {
        $user = auth()->user();
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $session = AiChatSession::where('id', $sessionId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Add archived_at timestamp (you may need to add this column to the table)
        // For now, we'll use a simple approach with a JSON field or add a column later
        $session->update([
            'topic' => $session->topic ? $session->topic . ' (archived)' : 'archived',
        ]);

        return response()->json([
            'message' => 'Session archived successfully',
            'session' => $session->fresh(),
        ]);
    }

    public function shootOperatorAction(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'type' => ['required', 'string'],
            'shootId' => ['nullable'],
            'shoot_id' => ['nullable'],
            'payload' => ['nullable', 'array'],
            'cubicasa_order_id' => ['nullable', 'string'],
            'cubicasa_external_id' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->shootOperatorService->executeAction($validated, $user);

            return response()->json($result['payload'] ?? [], $result['status'] ?? 200);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'message' => config('app.debug') ? \App\Services\ApiErrorResponder::publicMessage($e) : 'Robbie could not complete that shoot action.',
            ], 500);
        }
    }

    private function persistAssistantResult(AiChatSession $session, array $result): array
    {
        DB::transaction(function () use ($session, $result) {
            foreach ($result['assistant_messages'] ?? [] as $msg) {
                AiMessage::create([
                    'chat_session_id' => $session->id,
                    'sender'          => $msg['sender'] ?? 'assistant',
                    'content'         => $msg['content'] ?? '',
                    'metadata'        => $msg['metadata'] ?? null,
                ]);
            }
        });

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
            'messages' => $messages,
            'meta' => [
                'suggestions' => $result['suggestions'] ?? [],
                'actions' => $result['actions'] ?? [],
            ],
        ];
    }
}
