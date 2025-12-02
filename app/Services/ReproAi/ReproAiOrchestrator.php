<?php

namespace App\Services\ReproAi;

use App\Models\AiChatSession;
use App\Models\AiMessage;
use Illuminate\Support\Facades\Log;

class ReproAiOrchestrator
{
    private LlmClient $llmClient;

    public function __construct(LlmClient $llmClient)
    {
        $this->llmClient = $llmClient;
    }

    /**
     * Handle a user message and generate assistant response
     * 
     * @param AiChatSession $session The chat session
     * @param string $userMessage The user's message
     * @param array $context Additional context (mode, propertyId, listingId, etc.)
     * @return array Array of assistant messages to store
     */
    public function handle(AiChatSession $session, string $userMessage, array $context = []): array
    {
        // Store user message for error handling
        $currentUserMessage = $userMessage;
        
        // Build conversation history
        $messages = $this->buildMessageHistory($session);
        
        // Add system prompt
        array_unshift($messages, [
            'role' => 'system',
            'content' => $this->buildSystemPrompt($session, $context),
        ]);

        // Add user message
        $messages[] = [
            'role' => 'user',
            'content' => $userMessage,
        ];

        // Get available tools
        $tools = $this->getAvailableTools();

        // Call LLM
        try {
            $response = $this->llmClient->chatCompletion($messages, $tools, stream: false);
            
            // Handle tool calls if present
            $assistantMessages = $this->processResponse($response, $session, $context);
            
            return $assistantMessages;
        } catch (\Exception $e) {
            Log::error('Robbie orchestration failed', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // Check if this is a quota/API error and message contains actionable intent
            $errorMessage = $e->getMessage();
            $isQuotaError = str_contains($errorMessage, 'quota') || 
                           str_contains($errorMessage, '429') ||
                           str_contains($errorMessage, 'insufficient_quota');
            
            $detectedIntent = $this->detectIntentFromMessage($currentUserMessage);
            
            // If it's a quota error and we detected a booking intent, suggest using rule-based flow
            if ($isQuotaError && in_array($detectedIntent, ['book_shoot', 'manage_booking'])) {
                return [[
                    'sender' => 'assistant',
                    'content' => "I'm having trouble with my AI service right now, but I can still help you book a shoot! Let me guide you through the booking process step by step.",
                    'metadata' => [
                        'error' => $e->getMessage(),
                        'fallback_intent' => $detectedIntent,
                        'suggestions' => ['Book a new shoot', 'Continue booking'],
                    ],
                ]];
            }

            // Return error message with more details in debug mode
            $errorMessage = 'I apologize, but I encountered an error processing your request. Please try again.';
            if (config('app.debug')) {
                $errorMessage .= ' Error: ' . $e->getMessage();
            }

            return [[
                'sender' => 'assistant',
                'content' => $errorMessage,
                'metadata' => [
                    'error' => $e->getMessage(),
                    'suggestions' => ['Book a new shoot', 'Try again'],
                ],
            ]];
        }
    }

    /**
     * Detect intent from message (simple keyword matching)
     */
    private function detectIntentFromMessage(string $message): ?string
    {
        $m = strtolower(trim($message));
        
        if (str_contains($m, 'book') && (str_contains($m, 'shoot') || str_contains($m, 'new'))) {
            return 'book_shoot';
        }
        if (str_contains($m, 'schedule')) {
            return 'book_shoot';
        }
        if (str_contains($m, 'cancel') || str_contains($m, 'reschedule') || str_contains($m, 'manage')) {
            return 'manage_booking';
        }
        
        return null;
    }

    /**
     * Build system prompt for the AI
     */
    private function buildSystemPrompt(AiChatSession $session, array $context): string
    {
        $user = $session->user ?? \App\Models\User::find($session->user_id);
        $userRole = $user->role ?? 'client';
        $userName = $user->name ?? 'there';
        
        $prompt = "You are Robbie, an intelligent AI assistant for a real estate photography and media services platform. ";
        $prompt .= "You help users manage their entire photography workflow from booking to payment to delivery.\n\n";
        
        $prompt .= "User Context:\n";
        $prompt .= "- User: {$userName}\n";
        $prompt .= "- Role: {$userRole}\n\n";
        
        $prompt .= "Your Comprehensive Capabilities:\n";
        $prompt .= "1. BOOKING MANAGEMENT:\n";
        $prompt .= "   - Book new photography shoots (photos, video, drone, floorplans, iGuide)\n";
        $prompt .= "   - Collect property address, date, time, and service requirements\n";
        $prompt .= "   - Confirm booking details before finalizing\n";
        $prompt .= "   - Handle multi-step booking conversations naturally\n\n";
        
        $prompt .= "2. SHOOT MANAGEMENT:\n";
        $prompt .= "   - View shoot details (address, status, services, pricing, photographer)\n";
        $prompt .= "   - Reschedule shoots (change date/time)\n";
        $prompt .= "   - Cancel shoots with reason\n";
        $prompt .= "   - List shoots with filters (by status, date range, etc.)\n";
        $prompt .= "   - Update shoot status and workflow status\n\n";
        
        $prompt .= "3. PAYMENT PROCESSING:\n";
        $prompt .= "   - Check payment status for any shoot\n";
        $prompt .= "   - Create payment checkout links\n";
        $prompt .= "   - View payment history\n";
        $prompt .= "   - Guide users through payment process\n";
        $prompt .= "   - Handle payment-related questions\n\n";
        
        $prompt .= "4. DASHBOARD & ANALYTICS:\n";
        $prompt .= "   - Get dashboard statistics (revenue, shoot counts, pending items)\n";
        $prompt .= "   - Show upcoming shoots and items needing attention\n";
        $prompt .= "   - Provide insights on business performance\n";
        $prompt .= "   - Filter by time ranges (today, week, month, year, all)\n\n";
        
        $prompt .= "5. PROPERTY & LISTING MANAGEMENT:\n";
        $prompt .= "   - Get property details by ID or address\n";
        $prompt .= "   - Get listing information\n";
        $prompt .= "   - Update listing descriptions and copy\n";
        $prompt .= "   - Provide property insights\n\n";
        
        $prompt .= "6. PORTFOLIO OVERVIEW:\n";
        $prompt .= "   - Show portfolio statistics\n";
        $prompt .= "   - Identify listings needing media attention\n";
        $prompt .= "   - Track recent activity\n\n";
        
        $prompt .= "Guidelines for Interactions:\n";
        $prompt .= "- Be conversational, friendly, and professional\n";
        $prompt .= "- Always use tools to fetch real data before answering questions\n";
        $prompt .= "- For bookings: Guide users through collecting all required information (address, date, time, services)\n";
        $prompt .= "- For payments: Always check current status before creating payment links\n";
        $prompt .= "- For dashboard: Provide clear, actionable insights\n";
        $prompt .= "- When showing data, format it clearly with bullet points or structured lists\n";
        $prompt .= "- If information is missing, ask clarifying questions\n";
        $prompt .= "- Always confirm important actions (bookings, cancellations, payments) before executing\n";
        $prompt .= "- Provide helpful suggestions and next steps after completing actions\n";
        $prompt .= "- Use emojis sparingly but appropriately (📸 for shoots, 💰 for payments, 📊 for stats)\n\n";
        
        $prompt .= "Workflow Statuses:\n";
        $prompt .= "- 'booked': Shoot is booked but not scheduled\n";
        $prompt .= "- 'raw_upload_pending': Waiting for raw photos\n";
        $prompt .= "- 'raw_uploaded': Raw photos received\n";
        $prompt .= "- 'raw_issue': Issue with raw photos\n";
        $prompt .= "- 'editing': Photos being edited\n";
        $prompt .= "- 'editing_issue': Issue with editing\n";
        $prompt .= "- 'pending_review': Waiting for review\n";
        $prompt .= "- 'ready_for_client': Completed and ready\n";
        $prompt .= "- 'editing_uploaded': Edited photos uploaded\n\n";

        if (!empty($context['mode'])) {
            $prompt .= "Current context: " . $context['mode'] . " mode\n";
        }

        return $prompt;
    }

    /**
     * Build message history from session
     */
    private function buildMessageHistory(AiChatSession $session): array
    {
        $history = [];
        
        // Ensure messages are loaded
        if (!$session->relationLoaded('messages')) {
            $session->load('messages');
        }
        
        foreach ($session->messages as $message) {
            $role = match($message->sender) {
                'user' => 'user',
                'assistant' => 'assistant',
                'system' => 'system',
                default => 'user',
            };

            $history[] = [
                'role' => $role,
                'content' => $message->content,
            ];
        }

        return $history;
    }

    /**
     * Get available tools for function calling
     */
    private function getAvailableTools(): array
    {
        return [
            // Property & Listing Tools
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_property',
                    'description' => 'Get property details by property ID or address',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'property_id' => ['type' => 'string', 'description' => 'The property ID'],
                            'address' => ['type' => 'string', 'description' => 'Property address to search for'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_listing',
                    'description' => 'Get listing details by listing ID',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'listing_id' => ['type' => 'string', 'description' => 'The listing ID'],
                        ],
                        'required' => ['listing_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'update_listing_copy',
                    'description' => 'Update listing title, description, or highlights',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'listing_id' => ['type' => 'string', 'description' => 'The listing ID'],
                            'title' => ['type' => 'string', 'description' => 'New listing title'],
                            'description' => ['type' => 'string', 'description' => 'New listing description'],
                            'highlights' => ['type' => 'array', 'description' => 'Array of highlight strings', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['listing_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_portfolio_overview',
                    'description' => 'Get portfolio statistics and listings needing attention',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'user_id' => ['type' => 'integer', 'description' => 'User ID to get portfolio for'],
                        ],
                    ],
                ],
            ],
            
            // Booking Tools
            [
                'type' => 'function',
                'function' => [
                    'name' => 'book_shoot',
                    'description' => 'Book a photography shoot for a property. Use this when user wants to schedule a new shoot.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'address' => ['type' => 'string', 'description' => 'Property street address'],
                            'city' => ['type' => 'string', 'description' => 'City'],
                            'state' => ['type' => 'string', 'description' => 'State (2-letter code preferred)'],
                            'zip' => ['type' => 'string', 'description' => 'ZIP code'],
                            'date' => ['type' => 'string', 'description' => 'Scheduled date (YYYY-MM-DD format)'],
                            'time' => ['type' => 'string', 'description' => 'Time window (e.g., "3-5 PM", "morning", "afternoon")'],
                            'services' => ['type' => 'array', 'description' => 'Array of service IDs to book', 'items' => ['type' => 'integer']],
                            'notes' => ['type' => 'string', 'description' => 'Additional notes for the shoot'],
                            'photographer_id' => ['type' => 'integer', 'description' => 'Optional photographer ID'],
                        ],
                        'required' => ['address', 'city', 'state', 'zip', 'services'],
                    ],
                ],
            ],
            
            // Shoot Management Tools
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_shoot_details',
                    'description' => 'Get detailed information about a specific shoot including address, status, services, pricing, and payment info',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'shoot_id' => ['type' => 'integer', 'description' => 'The shoot ID'],
                        ],
                        'required' => ['shoot_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_shoots',
                    'description' => 'List shoots with optional filters. Use this to show user their shoots.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'user_id' => ['type' => 'integer', 'description' => 'User ID (defaults to current user)'],
                            'status' => ['type' => 'string', 'description' => 'Filter by status: pending, scheduled, completed, cancelled'],
                            'limit' => ['type' => 'integer', 'description' => 'Maximum number of shoots to return (default: 10, max: 50)'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'reschedule_shoot',
                    'description' => 'Reschedule a shoot by changing the date and/or time',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'shoot_id' => ['type' => 'integer', 'description' => 'The shoot ID'],
                            'new_date' => ['type' => 'string', 'description' => 'New scheduled date (YYYY-MM-DD format)'],
                            'new_time' => ['type' => 'string', 'description' => 'New time window'],
                        ],
                        'required' => ['shoot_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'cancel_shoot',
                    'description' => 'Cancel a shoot',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'shoot_id' => ['type' => 'integer', 'description' => 'The shoot ID'],
                            'reason' => ['type' => 'string', 'description' => 'Optional cancellation reason'],
                        ],
                        'required' => ['shoot_id'],
                    ],
                ],
            ],
            
            // Payment Tools
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_payment_status',
                    'description' => 'Get payment status and history for a shoot. Use this to check if a shoot is paid, partially paid, or unpaid.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'shoot_id' => ['type' => 'integer', 'description' => 'The shoot ID'],
                        ],
                        'required' => ['shoot_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_payment_link',
                    'description' => 'Create a payment checkout link for a shoot. Use this when user wants to pay for a shoot.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'shoot_id' => ['type' => 'integer', 'description' => 'The shoot ID'],
                        ],
                        'required' => ['shoot_id'],
                    ],
                ],
            ],
            
            // Dashboard Tools
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_dashboard_stats',
                    'description' => 'Get comprehensive dashboard statistics including revenue, shoot counts, upcoming shoots, and items needing attention',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'user_id' => ['type' => 'integer', 'description' => 'User ID (defaults to current user)'],
                            'time_range' => ['type' => 'string', 'description' => 'Time range: today, week, month, year, or all (default: all)'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'update_shoot_status',
                    'description' => 'Update shoot status or workflow status',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'shoot_id' => ['type' => 'integer', 'description' => 'The shoot ID'],
                            'status' => ['type' => 'string', 'description' => 'New status: pending, scheduled, completed, cancelled, on_hold'],
                            'workflow_status' => ['type' => 'string', 'description' => 'New workflow status'],
                        ],
                        'required' => ['shoot_id'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Process LLM response and handle tool calls
     */
    private function processResponse(array $response, AiChatSession $session, array $context): array
    {
        $assistantMessages = [];
        $choice = $response['choices'][0] ?? null;

        if (!$choice) {
            return [[
                'sender' => 'assistant',
                'content' => 'I apologize, but I could not generate a response. Please try again.',
                'metadata' => [],
            ]];
        }

        $message = $choice['message'] ?? [];
        $toolCalls = $message['tool_calls'] ?? [];

        // If there are tool calls, execute them
        if (!empty($toolCalls)) {
            $toolResults = [];
            
            foreach ($toolCalls as $toolCall) {
                $functionName = $toolCall['function']['name'] ?? '';
                $functionArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true);
                
                try {
                    $result = $this->executeTool($functionName, $functionArgs, $session, $context);
                    $toolResults[] = [
                        'tool_call_id' => $toolCall['id'] ?? '',
                        'name' => $functionName,
                        'result' => $result,
                    ];
                } catch (\Exception $e) {
                    Log::error('Tool execution failed', [
                        'tool' => $functionName,
                        'error' => $e->getMessage(),
                    ]);
                    
                    $toolResults[] = [
                        'tool_call_id' => $toolCall['id'] ?? '',
                        'name' => $functionName,
                        'result' => ['error' => $e->getMessage()],
                    ];
                }
            }

            // Add tool call messages
            $assistantMessages[] = [
                'sender' => 'assistant',
                'content' => $message['content'] ?? '',
                'metadata' => [
                    'tool_calls' => $toolCalls,
                    'tool_results' => $toolResults,
                ],
            ];

            // Make a follow-up call with tool results
            $followUpMessages = $this->buildMessageHistory($session);
            array_unshift($followUpMessages, [
                'role' => 'system',
                'content' => $this->buildSystemPrompt($session, $context),
            ]);
            
            // Add the assistant message with tool calls
            $followUpMessages[] = [
                'role' => 'assistant',
                'content' => $message['content'] ?? '',
                'tool_calls' => $toolCalls,
            ];
            
            // Add tool results
            foreach ($toolResults as $toolResult) {
                $followUpMessages[] = [
                    'role' => 'tool',
                    'content' => json_encode($toolResult['result']),
                    'tool_call_id' => $toolResult['tool_call_id'],
                ];
            }

            // Get final response
            $finalResponse = $this->llmClient->chatCompletion($followUpMessages, [], stream: false);
            $finalChoice = $finalResponse['choices'][0] ?? null;
            
            if ($finalChoice) {
                $finalMessage = $finalChoice['message'] ?? [];
                $assistantMessages[] = [
                    'sender' => 'assistant',
                    'content' => $finalMessage['content'] ?? '',
                    'metadata' => [],
                ];
            }
        } else {
            // No tool calls, just return the message
            $assistantMessages[] = [
                'sender' => 'assistant',
                'content' => $message['content'] ?? '',
                'metadata' => [],
            ];
        }

        return $assistantMessages;
    }

    /**
     * Execute a tool call
     */
    private function executeTool(string $toolName, array $params, AiChatSession $session, array $context): array
    {
        // Map tool names to their classes and methods
        $toolMapping = [
            // Property & Listing Tools
            'get_property' => ['PropertyTools', 'getProperty'],
            'get_portfolio_overview' => ['PropertyTools', 'getPortfolioOverview'],
            'get_listing' => ['ListingTools', 'getListing'],
            'update_listing_copy' => ['ListingTools', 'updateListingCopy'],
            
            // Booking Tools
            'book_shoot' => ['BookingTools', 'bookShoot'],
            
            // Shoot Management Tools
            'get_shoot_details' => ['ShootManagementTools', 'getShootDetails'],
            'list_shoots' => ['ShootManagementTools', 'listShoots'],
            'reschedule_shoot' => ['ShootManagementTools', 'rescheduleShoot'],
            'cancel_shoot' => ['ShootManagementTools', 'cancelShoot'],
            
            // Payment Tools
            'get_payment_status' => ['PaymentTools', 'getPaymentStatus'],
            'create_payment_link' => ['PaymentTools', 'createPaymentLink'],
            
            // Dashboard Tools
            'get_dashboard_stats' => ['DashboardTools', 'getDashboardStats'],
            'update_shoot_status' => ['DashboardTools', 'updateShootStatus'],
        ];

        if (!isset($toolMapping[$toolName])) {
            throw new \Exception("Unknown tool: {$toolName}");
        }

        [$toolClass, $methodName] = $toolMapping[$toolName];
        $className = "App\\Services\\ReproAi\\Tools\\{$toolClass}";
        
        if (!class_exists($className)) {
            throw new \Exception("Tool class not found: {$className}");
        }

        $toolHandler = new $className();

        if (!method_exists($toolHandler, $methodName)) {
            throw new \Exception("Tool method not found: {$methodName} in {$className}");
        }

        // Add session user_id to context if not present
        if (!isset($context['user_id'])) {
            $context['user_id'] = $session->user_id;
        }

        return $toolHandler->$methodName($params, $context);
    }
}
