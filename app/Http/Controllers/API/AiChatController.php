<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AiChatSession;
use App\Models\AiMessage;
use App\Services\ReproAi\RuleBasedOrchestrator;
use App\Services\ReproAi\ReproAiOrchestrator;
use App\Services\ReproAi\LlmClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiChatController extends Controller
{
    private ?RuleBasedOrchestrator $ruleOrchestrator = null;
    private ?ReproAiOrchestrator $openAiOrchestrator = null;

    public function __construct(?RuleBasedOrchestrator $ruleOrchestrator = null, ?ReproAiOrchestrator $openAiOrchestrator = null)
    {
        $this->ruleOrchestrator = $ruleOrchestrator ?? app(RuleBasedOrchestrator::class);
        // Initialize OpenAI orchestrator with LlmClient
        try {
            $llmClient = app(LlmClient::class);
            $this->openAiOrchestrator = $openAiOrchestrator ?? new ReproAiOrchestrator($llmClient);
        } catch (\Exception $e) {
            Log::warning('Failed to initialize OpenAI orchestrator, will use rule-based fallback', [
                'error' => $e->getMessage(),
            ]);
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
                $session = $validated['sessionId']
                    ? AiChatSession::where('id', $validated['sessionId'])
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
                Log::error('Failed to create/load session', [
                    'error' => $e->getMessage(),
                    'user_id' => $user->id,
                ]);
                throw $e; // Re-throw to be caught by outer handler
            }

            // Persist user message
            try {
                DB::transaction(function () use ($session, $validated) {
                    AiMessage::create([
                        'chat_session_id' => $session->id,
                        'sender'           => 'user',
                        'content'          => $validated['message'],
                        'metadata'         => $validated['context'] ?? null,
                    ]);
                });
            } catch (\Exception $e) {
                Log::error('Failed to save user message', [
                    'error' => $e->getMessage(),
                    'session_id' => $session->id,
                    'user_id' => $user->id,
                ]);
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
            
            // Detect intent early - if it's a clear booking/manage intent, use rule-based directly
            $detectedIntent = $this->detectIntent($validated['message'], $validated['context'] ?? []);
            $shouldUseRuleBased = in_array($detectedIntent, ['book_shoot', 'manage_booking', 'availability', 'client_stats', 'accounting', 'greeting']);
            
            // For greetings, always use rule-based to show proper welcome with suggestions
            if ($detectedIntent === 'greeting') {
                $shouldUseRuleBased = true;
            }
            
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
            if ($useOpenAI && $this->openAiOrchestrator && !$shouldUseRuleBased) {
                try {
                    // Get assistant messages from OpenAI orchestrator
                    $assistantMessages = $this->openAiOrchestrator->handle(
                        $session,
                        $validated['message'],
                        $validated['context'] ?? []
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
                    Log::warning('OpenAI orchestrator failed, falling back to rule-based', [
                        'error' => $openAiError->getMessage(),
                        'session_id' => $session->id,
                        'detected_intent' => $detectedIntent,
                    ]);
                    // Fall through to rule-based orchestrator
                    $useOpenAI = false;
                    $shouldUseRuleBased = true; // Force rule-based on OpenAI failure
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
                            Log::error('Failed to instantiate orchestrator', [
                                'error' => $diError->getMessage(),
                                'trace' => $diError->getTraceAsString(),
                            ]);
                            throw new \RuntimeException('Failed to initialize AI service: ' . $diError->getMessage());
                        }
                    }
                    
                    // Pass detected intent in context if available
                    $ruleContext = $validated['context'] ?? [];
                    if ($detectedIntent && $detectedIntent !== 'general') {
                        $ruleContext['intent'] = $detectedIntent;
                    }
                    
                    $result = $this->ruleOrchestrator->handle(
                        $session,
                        $validated['message'],
                        $ruleContext
                    );
                } catch (\Exception $ruleError) {
                    Log::error('Rule-based orchestrator also failed', [
                        'error' => $ruleError->getMessage(),
                    ]);
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
                Log::error('AI chat error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'user_id' => $user->id ?? null,
                    'session_id' => $session->id ?? null,
                    'message' => $validated['message'] ?? null,
                    'context' => $validated['context'] ?? [],
                ]);
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
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred while processing your message',
                'debug' => config('app.debug') ? [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'class' => get_class($e),
                ] : null,
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
    private function detectIntent(string $message, array $context): string
    {
        // Check context first (from UI buttons/cards)
        if (isset($context['intent']) && !empty($context['intent'])) {
            return $context['intent'];
        }
        
        // Detect from message content
        $m = strtolower(trim($message));
        
        // Booking intents
        if (str_contains($m, 'book') && (str_contains($m, 'shoot') || str_contains($m, 'new'))) {
            return 'book_shoot';
        }
        if (str_contains($m, 'schedule') || str_contains($m, 'new shoot')) {
            return 'book_shoot';
        }
        
        // Management intents
        if (str_contains($m, 'cancel') || str_contains($m, 'reschedule') || str_contains($m, 'change booking')) {
            return 'manage_booking';
        }
        if (str_contains($m, 'manage') && (str_contains($m, 'booking') || str_contains($m, 'shoot'))) {
            return 'manage_booking';
        }
        
        // Other intents
        if (str_contains($m, 'availability') || str_contains($m, 'available')) {
            return 'availability';
        }
        if (str_contains($m, 'stats') || str_contains($m, 'client stats')) {
            return 'client_stats';
        }
        if (str_contains($m, 'invoice') || str_contains($m, 'revenue') || str_contains($m, 'accounting')) {
            return 'accounting';
        }
        if (str_contains($m, 'hi') || str_contains($m, 'hello') || str_contains($m, 'hey')) {
            return 'greeting';
        }
        
        return 'general';
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

        return response()->json([
            'data' => $sessions->items(),
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
}

