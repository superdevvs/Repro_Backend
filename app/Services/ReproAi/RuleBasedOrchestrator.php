<?php

namespace App\Services\ReproAi;

use App\Models\AiChatSession;
use App\Models\AiMessage;
use App\Services\ReproAi\Flows\BookShootFlow;
use App\Services\ReproAi\Flows\ManageBookingFlow;
use App\Services\ReproAi\Flows\AvailabilityFlow;
use App\Services\ReproAi\Flows\ClientStatsFlow;
use App\Services\ReproAi\Flows\AccountingFlow;
use App\Services\ReproAi\Flows\PhotographerManagementFlow;
use App\Services\ReproAi\Flows\InvoiceBillingFlow;
use App\Services\ReproAi\Flows\MediaDeliveryFlow;
use App\Services\ReproAi\Flows\ClientCrmFlow;
use App\Services\ReproAi\Flows\SupportFaqFlow;
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
        protected PhotographerManagementFlow $photographerManagementFlow,
        protected InvoiceBillingFlow $invoiceBillingFlow,
        protected MediaDeliveryFlow $mediaDeliveryFlow,
        protected ClientCrmFlow $clientCrmFlow,
        protected SupportFaqFlow $supportFaqFlow,
    ) {}

    /**
     * @return array{sessionId:string,messages:array,meta:array}
     */
    public function handle(AiChatSession $session, string $message, ?array $context = null): array
    {
        $context ??= [];

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
            // Always update intent if provided in context
            if (Schema::hasColumn('ai_chat_sessions', 'intent')) {
                $session->intent = $context['intent']; // e.g. 'book_shoot'
            }
            // Reset step when intent changes from context
            if (Schema::hasColumn('ai_chat_sessions', 'step')) {
                $session->step = null;
            }
            if (Schema::hasColumn('ai_chat_sessions', 'state_data')) {
                $session->state_data = [];
            }
        }

        // If still no intent, try to guess from message
        if (!$session->intent || !Schema::hasColumn('ai_chat_sessions', 'intent')) {
            $guessedIntent = $this->guessIntentFromMessage($message);
            $pageIntent = $this->getIntentFromPageContext($context, $session);
            if ($guessedIntent === 'general' && $pageIntent) {
                $guessedIntent = $pageIntent;
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

        // Delegate to specific flow
        try {
            $result = match ($intent) {
                'book_shoot'      => $this->bookShootFlow->handle($session, $message, $context),
                'manage_booking'  => $this->manageBookingFlow->handle($session, $message, $context),
                'availability'    => $this->availabilityFlow->handle($session, $message, $context),
                'client_stats'    => $this->clientStatsFlow->handle($session, $message, $context),
                'accounting'      => $this->accountingFlow->handle($session, $message, $context),
                'photographer_management' => $this->photographerManagementFlow->handle($session, $message, $context),
                'invoice_billing' => $this->invoiceBillingFlow->handle($session, $message, $context),
                'media_delivery'  => $this->mediaDeliveryFlow->handle($session, $message, $context),
                'client_crm'      => $this->clientCrmFlow->handle($session, $message, $context),
                'support_faq'     => $this->supportFaqFlow->handle($session, $message, $context),
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
            'accounting', 'invoices' => 'accounting',
            'shoot_history', 'shoot_details' => 'manage_booking',
            'ai_editing' => 'media_delivery',
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
                    'content' => "You're on the dashboard. Want a quick overview or specific issues?",
                    'metadata' => ['type' => 'system'],
                ]],
                'suggestions' => [
                    "Show issues needing attention",
                    "Today's shoots",
                    'Pending approvals',
                    'Late RAW uploads',
                ],
            ],
            'shoot_history' => [
                'assistant_messages' => [[
                    'content' => "You're in Shoot History. Want me to surface approvals, cancellations, or flagged shoots?",
                    'metadata' => ['type' => 'system'],
                ]],
                'suggestions' => [
                    'Show pending approvals',
                    'Review cancellations',
                    'Flagged shoots',
                    'Search by address',
                ],
            ],
            'shoot_details' => [
                'assistant_messages' => [[
                    'content' => "You're viewing a shoot. Want to reschedule, assign someone, or check delivery?",
                    'metadata' => ['type' => 'system'],
                ]],
                'suggestions' => [
                    'Reschedule this shoot',
                    'Assign photographer',
                    'Mark RAWs uploaded',
                    'Check delivery status',
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
                    'Block tomorrow morning',
                    'Set holiday',
                    'View photographer schedule',
                ],
            ],
            'accounting' => [
                'assistant_messages' => [[
                    'content' => "You're on Accounting. Want invoices, revenue, or payment status?",
                    'metadata' => ['type' => 'system'],
                ]],
                'suggestions' => [
                    'View outstanding invoices',
                    'Accounting summary',
                    'Create invoice',
                    'Payment status',
                ],
            ],
            'invoices' => [
                'assistant_messages' => [[
                    'content' => "You're in Invoices. Want to create, send, or review invoices?",
                    'metadata' => ['type' => 'system'],
                ]],
                'suggestions' => [
                    'Create invoice',
                    'Send invoice',
                    'View outstanding invoices',
                    'Apply discount',
                ],
            ],
            'ai_editing' => [
                'assistant_messages' => [[
                    'content' => "You're in AI Editing. Want a listing rewrite or media recommendation?",
                    'metadata' => ['type' => 'system'],
                ]],
                'suggestions' => [
                    'Rewrite listing description',
                    'Suggest upgrades',
                    'Which listings need new media?',
                    'Generate captions',
                ],
            ],
            'reports' => [
                'assistant_messages' => [[
                    'content' => "You're on Reports. Want revenue, performance, or team stats?",
                    'metadata' => ['type' => 'system'],
                ]],
                'suggestions' => [
                    'Revenue this month',
                    'Top clients',
                    'Photographer performance',
                    'Shoots completed',
                ],
            ],
            'settings' => [
                'assistant_messages' => [[
                    'content' => "You're in Settings. Want help with branding, scheduling, or integrations?",
                    'metadata' => ['type' => 'system'],
                ]],
                'suggestions' => [
                    'Update scheduling settings',
                    'Manage integrations',
                    'Tour branding',
                    'Help & FAQ',
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
            // Client stats
            'view client stats' => 'client_stats',
            'my stats' => 'client_stats',
            // Accounting
            'see accounting summary' => 'accounting',
            'see accounting' => 'accounting',
            'view accounting' => 'accounting',
            // Photographer management
            'assign photographer' => 'photographer_management',
            'assign photographer to shoot' => 'photographer_management',
            'view photographer schedule' => 'photographer_management',
            'update availability' => 'photographer_management',
            'view photographer earnings' => 'photographer_management',
            'photographer earnings' => 'photographer_management',
            // Invoice & Billing
            'create invoice' => 'invoice_billing',
            'create invoice for a shoot' => 'invoice_billing',
            'send invoice' => 'invoice_billing',
            'send invoice to client' => 'invoice_billing',
            'view outstanding invoices' => 'invoice_billing',
            'outstanding invoices' => 'invoice_billing',
            'apply discount' => 'invoice_billing',
            'apply discount to booking' => 'invoice_billing',
            // Media Delivery
            'check delivery status' => 'media_delivery',
            'delivery status' => 'media_delivery',
            'share gallery' => 'media_delivery',
            'share gallery with client' => 'media_delivery',
            'request reshoot' => 'media_delivery',
            'download all photos' => 'media_delivery',
            'download photos' => 'media_delivery',
            // Client CRM
            'view client history' => 'client_crm',
            'client history' => 'client_crm',
            'send follow-up message' => 'client_crm',
            'send follow-up' => 'client_crm',
            'add client note' => 'client_crm',
            'view at-risk clients' => 'client_crm',
            'at-risk clients' => 'client_crm',
            // Support & FAQ
            'help' => 'support_faq',
            'faq' => 'support_faq',
            'speak to a human' => 'support_faq',
            'create a support ticket' => 'support_faq',
            'support ticket' => 'support_faq',
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
            
            // Photographer Management
            str_contains($m, 'assign') && str_contains($m, 'photographer') => 'photographer_management',
            str_contains($m, 'photographer') && str_contains($m, 'schedule') => 'photographer_management',
            str_contains($m, 'photographer') && str_contains($m, 'earning') => 'photographer_management',
            str_contains($m, 'photographer') && str_contains($m, 'payout') => 'photographer_management',
            str_contains($m, 'block') && str_contains($m, 'date') => 'photographer_management',
            
            // Invoice & Billing
            str_contains($m, 'invoice') => 'invoice_billing',
            str_contains($m, 'billing') => 'invoice_billing',
            str_contains($m, 'outstanding') => 'invoice_billing',
            str_contains($m, 'unpaid') && str_contains($m, 'invoice') => 'invoice_billing',
            str_contains($m, 'discount') => 'invoice_billing',
            
            // Media Delivery
            str_contains($m, 'delivery') => 'media_delivery',
            str_contains($m, 'gallery') => 'media_delivery',
            str_contains($m, 'download') && (str_contains($m, 'photo') || str_contains($m, 'all')) => 'media_delivery',
            str_contains($m, 'reshoot') || str_contains($m, 're-shoot') => 'media_delivery',
            str_contains($m, 'photos ready') => 'media_delivery',
            str_contains($m, 'share') && str_contains($m, 'link') => 'media_delivery',
            
            // Client CRM
            str_contains($m, 'client') && str_contains($m, 'history') => 'client_crm',
            str_contains($m, 'follow-up') || str_contains($m, 'follow up') => 'client_crm',
            str_contains($m, 'client') && str_contains($m, 'note') => 'client_crm',
            str_contains($m, 'at-risk') || str_contains($m, 'at risk') => 'client_crm',
            str_contains($m, 'inactive') && str_contains($m, 'client') => 'client_crm',
            
            // Support & FAQ
            str_contains($m, 'faq') => 'support_faq',
            str_contains($m, 'how much') => 'support_faq',
            str_contains($m, 'pricing') => 'support_faq',
            str_contains($m, 'turnaround') => 'support_faq',
            str_contains($m, 'ticket') => 'support_faq',
            str_contains($m, 'human') || str_contains($m, 'representative') => 'support_faq',
            str_contains($m, 'question') => 'support_faq',
            
            // Availability (after photographer management to avoid conflicts)
            str_contains($m, 'availability') && !str_contains($m, 'update') => 'availability',
            str_contains($m, 'available') && str_contains($m, 'slot') => 'availability',
            str_contains($m, 'when') && str_contains($m, 'free') => 'availability',
            
            // Client stats
            str_contains($m, 'stats') => 'client_stats',
            str_contains($m, 'performance') => 'client_stats',
            
            // Accounting
            str_contains($m, 'revenue') => 'accounting',
            str_contains($m, 'accounting') => 'accounting',
            str_contains($m, 'payment') && !str_contains($m, 'pay now') => 'accounting',
            str_contains($m, 'earnings') && !str_contains($m, 'photographer') => 'accounting',
            str_contains($m, 'money') => 'accounting',
            
            // Greetings
            $m === 'hi' || $m === 'hello' || $m === 'hey' => 'greeting',
            str_contains($m, 'what can you do') => 'greeting',
            
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
            // Client stats
            'view another client' => 'client_stats',
            // Photographer management
            'assign another photographer' => 'photographer_management',
            // Invoice billing
            'create another invoice' => 'invoice_billing',
            'send another invoice' => 'invoice_billing',
            // Media delivery
            'share another gallery' => 'media_delivery',
            'download another shoot' => 'media_delivery',
            // Client CRM
            'send to another client' => 'client_crm',
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
                'content'  => "Hi! I'm Robbie, your photography business assistant. I can help you with:\n\n" .
                    "**📸 Shoots & Bookings**\n" .
                    "• Book a new shoot\n" .
                    "• Manage existing bookings\n" .
                    "• Check photographer availability\n\n" .
                    "**👥 Team & Clients**\n" .
                    "• Assign photographers to shoots\n" .
                    "• View client history & CRM\n" .
                    "• View photographer earnings\n\n" .
                    "**💰 Billing & Delivery**\n" .
                    "• Create & send invoices\n" .
                    "• Share photo galleries\n" .
                    "• Check delivery status\n\n" .
                    "**📊 Reports & Support**\n" .
                    "• Accounting summaries\n" .
                    "• FAQ & support\n\n" .
                    "What would you like to do?",
                'metadata' => ['type' => 'system'],
            ]],
            'suggestions' => [
                'Book a new shoot',
                'Assign photographer',
                'Create invoice',
                'Share gallery',
                'View outstanding invoices',
                'Help & FAQ',
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

