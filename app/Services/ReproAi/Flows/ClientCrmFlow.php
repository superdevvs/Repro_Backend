<?php

namespace App\Services\ReproAi\Flows;

use App\Models\AiChatSession;
use App\Models\Shoot;
use App\Models\User;
use App\Models\ShootNote;
use App\Services\Messaging\MessagingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class ClientCrmFlow
{
    /**
     * @return array{
     *   assistant_messages: array<int,array{content:string,metadata?:array}>,
     *   suggestions?: array<int,string>,
     *   actions?: array<int,array>
     * }
     */
    public function handle(AiChatSession $session, string $message, array $context = []): array
    {
        $step = $session->step ?? 'ask_action';
        $data = $session->state_data ?? [];

        return match($step) {
            'ask_action' => $this->askAction($session, $message, $data),
            'client_history' => $this->handleClientHistory($session, $message, $data),
            'send_follow_up' => $this->handleSendFollowUp($session, $message, $data),
            'create_note' => $this->handleCreateNote($session, $message, $data),
            'at_risk_clients' => $this->handleAtRiskClients($session, $message, $data),
            default => $this->askAction($session, $message, $data),
        };
    }

    private function askAction(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Detect specific action from message
        if (str_contains($messageLower, 'history') || str_contains($messageLower, 'profile')) {
            $this->setStepAndData($session, 'client_history', $data);
            $session->save();
            return $this->handleClientHistory($session, $message, $data);
        }
        
        if (str_contains($messageLower, 'follow up') || str_contains($messageLower, 'follow-up') || str_contains($messageLower, 'thank')) {
            $this->setStepAndData($session, 'send_follow_up', $data);
            $session->save();
            return $this->handleSendFollowUp($session, $message, $data);
        }
        
        if (str_contains($messageLower, 'note') || str_contains($messageLower, 'memo')) {
            $this->setStepAndData($session, 'create_note', $data);
            $session->save();
            return $this->handleCreateNote($session, $message, $data);
        }
        
        if (str_contains($messageLower, 'at risk') || str_contains($messageLower, 'inactive') || str_contains($messageLower, 'churning')) {
            $this->setStepAndData($session, 'at_risk_clients', $data);
            $session->save();
            return $this->handleAtRiskClients($session, $message, $data);
        }

        // Show action menu
        $this->setStepAndData($session, 'ask_action', $data);
        $session->save();

        return [
            'assistant_messages' => [[
                'content' => "👥 **Client CRM**\n\nWhat would you like to do?",
                'metadata' => ['step' => 'ask_action'],
            ]],
            'suggestions' => [
                'View client history',
                'Send follow-up message',
                'Add client note',
                'View at-risk clients',
            ],
        ];
    }

    private function handleClientHistory(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Select client if not selected
        if (empty($data['client_id'])) {
            $clients = User::where('role', 'client')
                ->has('shoots')
                ->withCount('shoots')
                ->orderBy('shoots_count', 'desc')
                ->limit(15)
                ->get();
            
            // Try to match from message
            foreach ($clients as $client) {
                if (str_contains($messageLower, strtolower($client->name))) {
                    $data['client_id'] = $client->id;
                    $data['client_name'] = $client->name;
                    break;
                }
                if ($client->email && str_contains($messageLower, strtolower($client->email))) {
                    $data['client_id'] = $client->id;
                    $data['client_name'] = $client->name;
                    break;
                }
            }
            
            if (empty($data['client_id'])) {
                $suggestions = [];
                foreach ($clients->take(8) as $client) {
                    $suggestions[] = "{$client->name} ({$client->shoots_count} shoots)";
                }
                
                $this->setStepAndData($session, 'client_history', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "👤 Which client's history would you like to view?",
                        'metadata' => ['step' => 'client_history'],
                    ]],
                    'suggestions' => $suggestions,
                ];
            }
        }
        
        $client = User::find($data['client_id']);
        
        if (!$client) {
            return [
                'assistant_messages' => [[
                    'content' => "❌ Could not find that client.",
                    'metadata' => ['step' => 'client_history'],
                ]],
                'suggestions' => ['Start over'],
            ];
        }
        
        // Get all shoots
        $shoots = Shoot::where('client_id', $client->id)
            ->with('services')
            ->orderBy('scheduled_at', 'desc')
            ->get();
        
        // Calculate stats
        $totalShoots = $shoots->count();
        $completedShoots = $shoots->where('status', 'completed')->count();
        $totalRevenue = $shoots->sum('total_quote');
        $paidAmount = $shoots->where('payment_status', 'paid')->sum('total_quote');
        $unpaidAmount = $shoots->where('payment_status', 'unpaid')->sum('total_quote');
        
        // Last shoot
        $lastShoot = $shoots->first();
        $lastShootDate = $lastShoot?->scheduled_at?->format('M d, Y') ?? 'Never';
        
        // Favorite services
        $serviceUsage = [];
        foreach ($shoots as $shoot) {
            foreach ($shoot->services as $service) {
                $serviceUsage[$service->name] = ($serviceUsage[$service->name] ?? 0) + 1;
            }
        }
        arsort($serviceUsage);
        $topServices = array_slice(array_keys($serviceUsage), 0, 3);
        
        $content = "👤 **Client Profile: {$client->name}**\n\n";
        $content .= "📧 **Email**: {$client->email}\n";
        $content .= "📱 **Phone**: " . ($client->phonenumber ?? 'Not provided') . "\n";
        $content .= "🏢 **Company**: " . ($client->company_name ?? 'N/A') . "\n\n";
        
        $content .= "**📊 Stats:**\n";
        $content .= "• Total Shoots: {$totalShoots}\n";
        $content .= "• Completed: {$completedShoots}\n";
        $content .= "• Total Revenue: $" . number_format($totalRevenue, 2) . "\n";
        $content .= "• Paid: $" . number_format($paidAmount, 2) . "\n";
        $content .= "• Outstanding: $" . number_format($unpaidAmount, 2) . "\n";
        $content .= "• Last Shoot: {$lastShootDate}\n";
        
        if (!empty($topServices)) {
            $content .= "\n**🎯 Favorite Services:**\n";
            foreach ($topServices as $service) {
                $count = $serviceUsage[$service];
                $content .= "• {$service} ({$count}x)\n";
            }
        }
        
        // Recent shoots
        $recentShoots = $shoots->take(5);
        if ($recentShoots->isNotEmpty()) {
            $content .= "\n**📸 Recent Shoots:**\n";
            foreach ($recentShoots as $shoot) {
                $date = $shoot->scheduled_at?->format('M d') ?? 'TBD';
                $status = match($shoot->status) {
                    'completed' => '✅',
                    'cancelled' => '❌',
                    default => '📅',
                };
                $content .= "• {$status} {$date} - {$shoot->address}\n";
            }
        }
        
        $this->setStepAndData($session, null, ['client_id' => $client->id]);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => [
                    'step' => 'client_history',
                    'client_id' => $client->id,
                    'total_shoots' => $totalShoots,
                    'total_revenue' => $totalRevenue,
                ],
            ]],
            'suggestions' => [
                'Send follow-up message',
                'Add client note',
                'Book shoot for this client',
                'View another client',
            ],
        ];
    }

    private function handleSendFollowUp(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Select client if not selected
        if (empty($data['client_id'])) {
            // Get clients with recent completed shoots
            $recentClients = User::where('role', 'client')
                ->whereHas('shoots', function ($query) {
                    $query->where('status', 'completed')
                        ->where('completed_at', '>=', now()->subDays(30));
                })
                ->with(['shoots' => function ($query) {
                    $query->where('status', 'completed')
                        ->orderBy('completed_at', 'desc')
                        ->limit(1);
                }])
                ->limit(10)
                ->get();
            
            foreach ($recentClients as $client) {
                if (str_contains($messageLower, strtolower($client->name))) {
                    $data['client_id'] = $client->id;
                    $data['client_name'] = $client->name;
                    break;
                }
            }
            
            if (empty($data['client_id'])) {
                $suggestions = [];
                foreach ($recentClients as $client) {
                    $lastShoot = $client->shoots->first();
                    $lastDate = $lastShoot?->completed_at?->format('M d') ?? 'N/A';
                    $suggestions[] = "{$client->name} (completed {$lastDate})";
                }
                
                if (empty($suggestions)) {
                    return [
                        'assistant_messages' => [[
                            'content' => "📋 No recent completed shoots to follow up on.",
                            'metadata' => ['step' => 'send_follow_up'],
                        ]],
                        'suggestions' => [
                            'View client history',
                            'View at-risk clients',
                        ],
                    ];
                }
                
                $this->setStepAndData($session, 'send_follow_up', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "📤 Which client would you like to follow up with?",
                        'metadata' => ['step' => 'send_follow_up'],
                    ]],
                    'suggestions' => $suggestions,
                ];
            }
        }
        
        // Select message type if not selected
        if (empty($data['follow_up_type'])) {
            $types = [
                'thank_you' => str_contains($messageLower, 'thank'),
                'feedback' => str_contains($messageLower, 'feedback') || str_contains($messageLower, 'review'),
                'referral' => str_contains($messageLower, 'referral') || str_contains($messageLower, 'refer'),
                'rebook' => str_contains($messageLower, 'rebook') || str_contains($messageLower, 'again'),
            ];
            
            foreach ($types as $type => $matched) {
                if ($matched) {
                    $data['follow_up_type'] = $type;
                    break;
                }
            }
            
            if (empty($data['follow_up_type'])) {
                $this->setStepAndData($session, 'send_follow_up', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "📝 What type of follow-up would you like to send?",
                        'metadata' => ['step' => 'send_follow_up'],
                    ]],
                    'suggestions' => [
                        'Thank you message',
                        'Request feedback',
                        'Ask for referral',
                        'Invite to rebook',
                    ],
                ];
            }
        }
        
        $client = User::find($data['client_id']);
        
        if (!$client || !$client->email) {
            return [
                'assistant_messages' => [[
                    'content' => "❌ Could not send - client email not found.",
                    'metadata' => ['step' => 'send_follow_up'],
                ]],
                'suggestions' => ['Choose another client'],
            ];
        }
        
        // Generate message based on type
        $messageTemplates = [
            'thank_you' => [
                'subject' => "Thank you for choosing REPRO-HQ!",
                'body' => "Hi {$client->name},\n\nThank you for your recent shoot with us! We hope you love your photos.\n\nBest regards,\nREPRO-HQ Team",
            ],
            'feedback' => [
                'subject' => "We'd love your feedback!",
                'body' => "Hi {$client->name},\n\nWe hope you're enjoying your photos! We'd appreciate if you could take a moment to share your feedback.\n\nThank you!\nREPRO-HQ Team",
            ],
            'referral' => [
                'subject' => "Know someone who needs great photos?",
                'body' => "Hi {$client->name},\n\nThank you for being a valued client! If you know anyone who could use our services, we'd love a referral.\n\nBest,\nREPRO-HQ Team",
            ],
            'rebook' => [
                'subject' => "Ready for your next shoot?",
                'body' => "Hi {$client->name},\n\nIt's been a while since your last shoot. We'd love to work with you again!\n\nLet us know when you're ready to book.\n\nBest,\nREPRO-HQ Team",
            ],
        ];
        
        $template = $messageTemplates[$data['follow_up_type']] ?? $messageTemplates['thank_you'];
        
        // Send email
        try {
            $messagingService = app(MessagingService::class);
            $messagingService->sendEmail([
                'to' => $client->email,
                'subject' => $template['subject'],
                'body_text' => $template['body'],
                'body_html' => nl2br($template['body']),
            ]);
            $emailSent = true;
        } catch (\Exception $e) {
            \Log::warning('Failed to send follow-up email', ['error' => $e->getMessage()]);
            $emailSent = false;
        }
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        $statusMessage = $emailSent 
            ? "✅ **Follow-up sent to {$client->name}!**\n\n📧 Email: {$client->email}\n📝 Type: " . ucfirst(str_replace('_', ' ', $data['follow_up_type']))
            : "❌ **Failed to send follow-up** - please check email settings.";
        
        return [
            'assistant_messages' => [[
                'content' => $statusMessage,
                'metadata' => [
                    'step' => 'done',
                    'client_id' => $client->id,
                    'email_sent' => $emailSent,
                ],
            ]],
            'suggestions' => [
                'Send to another client',
                'View client history',
                'Add client note',
            ],
        ];
    }

    private function handleCreateNote(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Select client if not selected
        if (empty($data['client_id'])) {
            $clients = User::where('role', 'client')
                ->has('shoots')
                ->orderBy('updated_at', 'desc')
                ->limit(10)
                ->get();
            
            foreach ($clients as $client) {
                if (str_contains($messageLower, strtolower($client->name))) {
                    $data['client_id'] = $client->id;
                    $data['client_name'] = $client->name;
                    break;
                }
            }
            
            if (empty($data['client_id'])) {
                $suggestions = [];
                foreach ($clients as $client) {
                    $suggestions[] = $client->name;
                }
                
                $this->setStepAndData($session, 'create_note', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "📝 Which client would you like to add a note for?",
                        'metadata' => ['step' => 'create_note'],
                    ]],
                    'suggestions' => $suggestions,
                ];
            }
        }
        
        // Get note content if not provided
        if (empty($data['note_content'])) {
            // Check if this message is the note content
            if (!empty(trim($message)) && !str_contains($messageLower, 'note') && !str_contains($messageLower, 'add')) {
                $data['note_content'] = $message;
            } else {
                $this->setStepAndData($session, 'create_note', $data);
                $session->save();
                
                $clientName = $data['client_name'] ?? 'this client';
                return [
                    'assistant_messages' => [[
                        'content' => "📝 What note would you like to add for **{$clientName}**?",
                        'metadata' => ['step' => 'create_note'],
                    ]],
                    'suggestions' => [
                        'Prefers morning shoots',
                        'VIP client - priority service',
                        'Needs extra communication',
                        'Cash discount eligible',
                    ],
                ];
            }
        }
        
        $client = User::find($data['client_id']);
        
        if (!$client) {
            return [
                'assistant_messages' => [[
                    'content' => "❌ Could not find that client.",
                    'metadata' => ['step' => 'create_note'],
                ]],
                'suggestions' => ['Start over'],
            ];
        }
        
        // Store note in client metadata
        $metadata = $client->metadata ?? [];
        $notes = $metadata['notes'] ?? [];
        $notes[] = [
            'content' => $data['note_content'],
            'created_by' => $session->user_id,
            'created_at' => now()->toIso8601String(),
        ];
        $metadata['notes'] = $notes;
        $client->metadata = $metadata;
        $client->save();
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => "✅ **Note Added!**\n\n👤 **Client**: {$client->name}\n📝 **Note**: {$data['note_content']}",
                'metadata' => [
                    'step' => 'done',
                    'client_id' => $client->id,
                ],
            ]],
            'suggestions' => [
                'Add another note',
                'View client history',
                'Book shoot for this client',
            ],
        ];
    }

    private function handleAtRiskClients(AiChatSession $session, string $message, array $data): array
    {
        // Find clients who haven't booked in a while
        $atRiskDays = 60; // Clients with no activity in 60+ days
        
        $atRiskClients = User::where('role', 'client')
            ->whereHas('shoots', function ($query) use ($atRiskDays) {
                $query->where('scheduled_at', '<', now()->subDays($atRiskDays));
            })
            ->whereDoesntHave('shoots', function ($query) use ($atRiskDays) {
                $query->where('scheduled_at', '>=', now()->subDays($atRiskDays));
            })
            ->withCount('shoots')
            ->with(['shoots' => function ($query) {
                $query->orderBy('scheduled_at', 'desc')->limit(1);
            }])
            ->orderBy('shoots_count', 'desc')
            ->limit(15)
            ->get();
        
        if ($atRiskClients->isEmpty()) {
            $this->setStepAndData($session, null, []);
            $session->save();
            
            return [
                'assistant_messages' => [[
                    'content' => "🎉 **Great news!** No at-risk clients found. All clients have recent activity.",
                    'metadata' => ['step' => 'at_risk_clients'],
                ]],
                'suggestions' => [
                    'View client history',
                    'Send follow-up message',
                ],
            ];
        }
        
        $content = "⚠️ **At-Risk Clients**\n\nThese clients haven't booked in {$atRiskDays}+ days:\n\n";
        
        foreach ($atRiskClients as $client) {
            $lastShoot = $client->shoots->first();
            $lastDate = $lastShoot?->scheduled_at?->format('M d, Y') ?? 'N/A';
            $daysSince = $lastShoot?->scheduled_at?->diffInDays(now()) ?? 0;
            
            $urgency = $daysSince > 90 ? '🔴' : '🟡';
            $content .= "{$urgency} **{$client->name}** ({$client->shoots_count} shoots)\n";
            $content .= "   Last: {$lastDate} ({$daysSince} days ago)\n\n";
        }
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => [
                    'step' => 'at_risk_clients',
                    'count' => $atRiskClients->count(),
                ],
            ]],
            'suggestions' => [
                'Send re-engagement campaign',
                'View client history',
                'Send follow-up message',
            ],
        ];
    }

    protected function setStepAndData(AiChatSession $session, ?string $step = null, ?array $data = null): void
    {
        if ($step !== null && Schema::hasColumn('ai_chat_sessions', 'step')) {
            $session->step = $step;
        }
        if ($data !== null && Schema::hasColumn('ai_chat_sessions', 'state_data')) {
            $session->state_data = $data;
        }
    }
}
