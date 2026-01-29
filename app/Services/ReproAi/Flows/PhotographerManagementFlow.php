<?php

namespace App\Services\ReproAi\Flows;

use App\Models\AiChatSession;
use App\Models\Shoot;
use App\Models\User;
use App\Models\PhotographerAvailability;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class PhotographerManagementFlow
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
            'assign_photographer' => $this->handleAssignPhotographer($session, $message, $data),
            'view_schedule' => $this->handleViewSchedule($session, $message, $data),
            'update_availability' => $this->handleUpdateAvailability($session, $message, $data),
            'view_earnings' => $this->handleViewEarnings($session, $message, $data),
            default => $this->askAction($session, $message, $data),
        };
    }

    private function askAction(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Detect specific action from message
        if (str_contains($messageLower, 'assign') || str_contains($messageLower, 'assign photographer')) {
            $this->setStepAndData($session, 'assign_photographer', $data);
            $session->save();
            return $this->handleAssignPhotographer($session, $message, $data);
        }
        
        if (str_contains($messageLower, 'schedule') || str_contains($messageLower, 'upcoming')) {
            $this->setStepAndData($session, 'view_schedule', $data);
            $session->save();
            return $this->handleViewSchedule($session, $message, $data);
        }
        
        if (str_contains($messageLower, 'availability') || str_contains($messageLower, 'block') || str_contains($messageLower, 'unblock')) {
            $this->setStepAndData($session, 'update_availability', $data);
            $session->save();
            return $this->handleUpdateAvailability($session, $message, $data);
        }
        
        if (str_contains($messageLower, 'earning') || str_contains($messageLower, 'payout') || str_contains($messageLower, 'pay')) {
            $this->setStepAndData($session, 'view_earnings', $data);
            $session->save();
            return $this->handleViewEarnings($session, $message, $data);
        }

        // Show action menu
        $this->setStepAndData($session, 'ask_action', $data);
        $session->save();

        return [
            'assistant_messages' => [[
                'content' => "📷 **Photographer Management**\n\nWhat would you like to do?",
                'metadata' => ['step' => 'ask_action'],
            ]],
            'suggestions' => [
                'Assign photographer to shoot',
                'View photographer schedule',
                'Update availability',
                'View photographer earnings',
            ],
        ];
    }

    private function handleAssignPhotographer(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Step 1: Select shoot if not selected
        if (empty($data['shoot_id'])) {
            // Get unassigned shoots
            $unassignedShoots = Shoot::whereNull('photographer_id')
                ->whereNotIn('status', ['cancelled', 'completed'])
                ->orderBy('scheduled_at', 'asc')
                ->limit(10)
                ->get();
            
            // Try to match from message
            foreach ($unassignedShoots as $shoot) {
                if (preg_match('/#?(\d+)/', $message, $matches)) {
                    if ((int)$matches[1] === $shoot->id) {
                        $data['shoot_id'] = $shoot->id;
                        break;
                    }
                }
                if (str_contains($messageLower, strtolower($shoot->address))) {
                    $data['shoot_id'] = $shoot->id;
                    break;
                }
            }
            
            if (empty($data['shoot_id'])) {
                if ($unassignedShoots->isEmpty()) {
                    return [
                        'assistant_messages' => [[
                            'content' => "✅ All shoots currently have photographers assigned.",
                            'metadata' => ['step' => 'assign_photographer'],
                        ]],
                        'suggestions' => [
                            'View photographer schedule',
                            'Book a new shoot',
                        ],
                    ];
                }
                
                $suggestions = [];
                foreach ($unassignedShoots as $shoot) {
                    $dateStr = $shoot->scheduled_at ? $shoot->scheduled_at->format('M d') : 'TBD';
                    $suggestions[] = "#{$shoot->id} - {$shoot->address} ({$dateStr})";
                }
                
                $this->setStepAndData($session, 'assign_photographer', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "📋 Which shoot needs a photographer?",
                        'metadata' => ['step' => 'assign_photographer'],
                    ]],
                    'suggestions' => $suggestions,
                ];
            }
        }
        
        // Step 2: Select photographer if not selected
        if (empty($data['photographer_id'])) {
            $shoot = Shoot::find($data['shoot_id']);
            $shootDate = $shoot?->scheduled_at;
            
            // Get available photographers
            $photographers = User::where('role', 'photographer')
                ->where('account_status', '!=', 'inactive')
                ->get();
            
            // Try to match from message
            foreach ($photographers as $photographer) {
                if (str_contains($messageLower, strtolower($photographer->name))) {
                    $data['photographer_id'] = $photographer->id;
                    $data['photographer_name'] = $photographer->name;
                    break;
                }
            }
            
            // Check for auto-assign request
            if (str_contains($messageLower, 'auto') || str_contains($messageLower, 'best') || str_contains($messageLower, 'recommend')) {
                $bestPhotographer = $this->findBestPhotographer($shoot);
                if ($bestPhotographer) {
                    $data['photographer_id'] = $bestPhotographer->id;
                    $data['photographer_name'] = $bestPhotographer->name;
                }
            }
            
            if (empty($data['photographer_id'])) {
                $suggestions = ['Auto-assign best match'];
                foreach ($photographers->take(5) as $photographer) {
                    $shootCount = Shoot::where('photographer_id', $photographer->id)
                        ->whereDate('scheduled_at', $shootDate?->format('Y-m-d'))
                        ->count();
                    $status = $shootCount > 0 ? "({$shootCount} shoots that day)" : "(available)";
                    $suggestions[] = "{$photographer->name} {$status}";
                }
                
                $this->setStepAndData($session, 'assign_photographer', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "Who should we assign to **#{$shoot->id} - {$shoot->address}**?",
                        'metadata' => ['step' => 'assign_photographer', 'shoot_id' => $shoot->id],
                    ]],
                    'suggestions' => $suggestions,
                ];
            }
        }
        
        // Step 3: Assign the photographer
        $shoot = Shoot::find($data['shoot_id']);
        $photographer = User::find($data['photographer_id']);
        
        if (!$shoot || !$photographer) {
            return [
                'assistant_messages' => [[
                    'content' => "❌ Could not find the shoot or photographer.",
                    'metadata' => ['step' => 'assign_photographer'],
                ]],
                'suggestions' => ['Start over'],
            ];
        }
        
        $shoot->photographer_id = $photographer->id;
        $shoot->save();
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => "✅ **{$photographer->name}** has been assigned to shoot **#{$shoot->id}** at {$shoot->address}.\n\n📅 Scheduled: " . ($shoot->scheduled_at ? $shoot->scheduled_at->format('M d, Y g:i A') : 'TBD'),
                'metadata' => ['step' => 'done', 'shoot_id' => $shoot->id, 'photographer_id' => $photographer->id],
            ]],
            'suggestions' => [
                'Assign another photographer',
                'View photographer schedule',
                'Book a new shoot',
            ],
        ];
    }

    private function handleViewSchedule(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Select photographer if not selected
        if (empty($data['photographer_id'])) {
            $photographers = User::where('role', 'photographer')->get(['id', 'name']);
            
            foreach ($photographers as $photographer) {
                if (str_contains($messageLower, strtolower($photographer->name))) {
                    $data['photographer_id'] = $photographer->id;
                    $data['photographer_name'] = $photographer->name;
                    break;
                }
            }
            
            if (empty($data['photographer_id'])) {
                $suggestions = [];
                foreach ($photographers->take(8) as $photographer) {
                    $suggestions[] = $photographer->name;
                }
                
                $this->setStepAndData($session, 'view_schedule', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "Which photographer's schedule would you like to see?",
                        'metadata' => ['step' => 'view_schedule'],
                    ]],
                    'suggestions' => $suggestions,
                ];
            }
        }
        
        $photographer = User::find($data['photographer_id']);
        $photographerName = $photographer?->name ?? 'Photographer';
        
        // Get upcoming shoots for this photographer
        $upcomingShoots = Shoot::where('photographer_id', $data['photographer_id'])
            ->where('scheduled_at', '>=', now())
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('scheduled_at', 'asc')
            ->limit(15)
            ->get();
        
        if ($upcomingShoots->isEmpty()) {
            $this->setStepAndData($session, null, []);
            $session->save();
            
            return [
                'assistant_messages' => [[
                    'content' => "📅 **{$photographerName}** has no upcoming shoots scheduled.",
                    'metadata' => ['step' => 'view_schedule', 'photographer_id' => $data['photographer_id']],
                ]],
                'suggestions' => [
                    'Assign a shoot to this photographer',
                    'Check another photographer',
                ],
            ];
        }
        
        $scheduleText = "📅 **{$photographerName}'s Upcoming Schedule**\n\n";
        
        $currentDate = null;
        foreach ($upcomingShoots as $shoot) {
            $shootDate = $shoot->scheduled_at->format('l, M d');
            if ($currentDate !== $shootDate) {
                $scheduleText .= "\n**{$shootDate}**\n";
                $currentDate = $shootDate;
            }
            $time = $shoot->scheduled_at->format('g:i A');
            $scheduleText .= "• {$time} - {$shoot->address}, {$shoot->city}\n";
        }
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => $scheduleText,
                'metadata' => [
                    'step' => 'view_schedule', 
                    'photographer_id' => $data['photographer_id'],
                    'shoot_count' => $upcomingShoots->count(),
                ],
            ]],
            'suggestions' => [
                'Check another photographer',
                'Update availability',
                'View earnings',
            ],
        ];
    }

    private function handleUpdateAvailability(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Select photographer if not selected
        if (empty($data['photographer_id'])) {
            $photographers = User::where('role', 'photographer')->get(['id', 'name']);
            
            foreach ($photographers as $photographer) {
                if (str_contains($messageLower, strtolower($photographer->name))) {
                    $data['photographer_id'] = $photographer->id;
                    $data['photographer_name'] = $photographer->name;
                    break;
                }
            }
            
            if (empty($data['photographer_id'])) {
                $suggestions = [];
                foreach ($photographers->take(8) as $photographer) {
                    $suggestions[] = $photographer->name;
                }
                
                $this->setStepAndData($session, 'update_availability', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "Which photographer's availability would you like to update?",
                        'metadata' => ['step' => 'update_availability'],
                    ]],
                    'suggestions' => $suggestions,
                ];
            }
        }
        
        // Select action (block/unblock) if not selected
        if (empty($data['availability_action'])) {
            if (str_contains($messageLower, 'block') && !str_contains($messageLower, 'unblock')) {
                $data['availability_action'] = 'block';
            } elseif (str_contains($messageLower, 'unblock') || str_contains($messageLower, 'available')) {
                $data['availability_action'] = 'unblock';
            } else {
                $this->setStepAndData($session, 'update_availability', $data);
                $session->save();
                
                $photographerName = $data['photographer_name'] ?? 'the photographer';
                return [
                    'assistant_messages' => [[
                        'content' => "Would you like to **block** or **unblock** dates for {$photographerName}?",
                        'metadata' => ['step' => 'update_availability'],
                    ]],
                    'suggestions' => [
                        'Block dates',
                        'Unblock dates',
                        'View current blocks',
                    ],
                ];
            }
        }
        
        // Select date if not selected
        if (empty($data['availability_date'])) {
            $parsedDate = $this->parseDateFromMessage($message);
            if ($parsedDate) {
                $data['availability_date'] = $parsedDate;
            } else {
                $this->setStepAndData($session, 'update_availability', $data);
                $session->save();
                
                $action = $data['availability_action'] === 'block' ? 'block' : 'make available';
                return [
                    'assistant_messages' => [[
                        'content' => "Which date would you like to {$action}?",
                        'metadata' => ['step' => 'update_availability'],
                    ]],
                    'suggestions' => [
                        'Tomorrow',
                        'This weekend',
                        'Next week',
                    ],
                ];
            }
        }
        
        // Apply the availability change
        $photographer = User::find($data['photographer_id']);
        $photographerName = $photographer?->name ?? 'Photographer';
        $date = Carbon::parse($data['availability_date']);
        $action = $data['availability_action'];
        
        if ($action === 'block') {
            PhotographerAvailability::updateOrCreate(
                [
                    'photographer_id' => $data['photographer_id'],
                    'date' => $date->format('Y-m-d'),
                ],
                [
                    'status' => 'blocked',
                    'day_of_week' => $date->dayOfWeek,
                ]
            );
            $resultMessage = "🚫 **{$photographerName}** is now blocked on **{$date->format('l, M d, Y')}**.";
        } else {
            PhotographerAvailability::where('photographer_id', $data['photographer_id'])
                ->where('date', $date->format('Y-m-d'))
                ->delete();
            $resultMessage = "✅ **{$photographerName}** is now available on **{$date->format('l, M d, Y')}**.";
        }
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => $resultMessage,
                'metadata' => ['step' => 'done', 'photographer_id' => $data['photographer_id']],
            ]],
            'suggestions' => [
                'Block another date',
                'View schedule',
                'Assign to shoot',
            ],
        ];
    }

    private function handleViewEarnings(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Select photographer if not selected
        if (empty($data['photographer_id'])) {
            $photographers = User::where('role', 'photographer')->get(['id', 'name']);
            
            foreach ($photographers as $photographer) {
                if (str_contains($messageLower, strtolower($photographer->name))) {
                    $data['photographer_id'] = $photographer->id;
                    $data['photographer_name'] = $photographer->name;
                    break;
                }
            }
            
            if (empty($data['photographer_id'])) {
                $suggestions = [];
                foreach ($photographers->take(8) as $photographer) {
                    $suggestions[] = $photographer->name;
                }
                
                $this->setStepAndData($session, 'view_earnings', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "Which photographer's earnings would you like to see?",
                        'metadata' => ['step' => 'view_earnings'],
                    ]],
                    'suggestions' => $suggestions,
                ];
            }
        }
        
        $photographer = User::find($data['photographer_id']);
        $photographerName = $photographer?->name ?? 'Photographer';
        
        // Calculate earnings from completed shoots
        $thisMonth = Shoot::where('photographer_id', $data['photographer_id'])
            ->where('status', 'completed')
            ->whereMonth('completed_at', now()->month)
            ->whereYear('completed_at', now()->year)
            ->get();
        
        $lastMonth = Shoot::where('photographer_id', $data['photographer_id'])
            ->where('status', 'completed')
            ->whereMonth('completed_at', now()->subMonth()->month)
            ->whereYear('completed_at', now()->subMonth()->year)
            ->get();
        
        $allTime = Shoot::where('photographer_id', $data['photographer_id'])
            ->where('status', 'completed')
            ->get();
        
        // Calculate pay from shoot_service pivot
        $thisMonthEarnings = $thisMonth->sum(function ($shoot) {
            return $shoot->services->sum('pivot.photographer_pay') ?? 0;
        });
        
        $lastMonthEarnings = $lastMonth->sum(function ($shoot) {
            return $shoot->services->sum('pivot.photographer_pay') ?? 0;
        });
        
        $allTimeEarnings = $allTime->sum(function ($shoot) {
            return $shoot->services->sum('pivot.photographer_pay') ?? 0;
        });
        
        // Get pending payouts (unpaid invoices)
        $pendingInvoices = Invoice::where('photographer_id', $data['photographer_id'])
            ->where('is_paid', false)
            ->sum('total_amount');
        
        $summary = "💰 **{$photographerName}'s Earnings**\n\n";
        $summary .= "**This Month ({$thisMonth->count()} shoots):**\n";
        $summary .= "• Earnings: $" . number_format($thisMonthEarnings, 2) . "\n\n";
        $summary .= "**Last Month ({$lastMonth->count()} shoots):**\n";
        $summary .= "• Earnings: $" . number_format($lastMonthEarnings, 2) . "\n\n";
        $summary .= "**All Time ({$allTime->count()} shoots):**\n";
        $summary .= "• Total Earnings: $" . number_format($allTimeEarnings, 2) . "\n";
        $summary .= "• Pending Payout: $" . number_format($pendingInvoices, 2) . "\n";
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => $summary,
                'metadata' => [
                    'step' => 'view_earnings',
                    'photographer_id' => $data['photographer_id'],
                    'this_month' => $thisMonthEarnings,
                    'last_month' => $lastMonthEarnings,
                    'all_time' => $allTimeEarnings,
                ],
            ]],
            'suggestions' => [
                'Check another photographer',
                'View schedule',
                'Create invoice',
            ],
        ];
    }

    private function findBestPhotographer(Shoot $shoot): ?User
    {
        $shootDate = $shoot->scheduled_at;
        if (!$shootDate) {
            return User::where('role', 'photographer')->first();
        }
        
        // Get photographers who don't have shoots that day and aren't blocked
        $photographers = User::where('role', 'photographer')
            ->where('account_status', '!=', 'inactive')
            ->get();
        
        $best = null;
        $minShoots = PHP_INT_MAX;
        
        foreach ($photographers as $photographer) {
            // Check if blocked
            $isBlocked = PhotographerAvailability::where('photographer_id', $photographer->id)
                ->whereDate('date', $shootDate->format('Y-m-d'))
                ->where('status', 'blocked')
                ->exists();
            
            if ($isBlocked) continue;
            
            // Count shoots that day
            $shootCount = Shoot::where('photographer_id', $photographer->id)
                ->whereDate('scheduled_at', $shootDate->format('Y-m-d'))
                ->whereNotIn('status', ['cancelled'])
                ->count();
            
            if ($shootCount < $minShoots) {
                $minShoots = $shootCount;
                $best = $photographer;
            }
        }
        
        return $best;
    }

    private function parseDateFromMessage(string $message): ?string
    {
        $messageLower = strtolower(trim($message));
        
        if (str_contains($messageLower, 'tomorrow')) {
            return now()->addDay()->format('Y-m-d');
        }
        if (str_contains($messageLower, 'today')) {
            return now()->format('Y-m-d');
        }
        if ($messageLower === 'this weekend' || str_contains($messageLower, 'saturday')) {
            return now()->next(Carbon::SATURDAY)->format('Y-m-d');
        }
        if (str_contains($messageLower, 'next week')) {
            return now()->addWeek()->startOfWeek()->format('Y-m-d');
        }
        
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $message, $matches)) {
            return $matches[1];
        }
        
        if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $message, $matches)) {
            try {
                return Carbon::createFromFormat('m/d/Y', $matches[1])->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        }
        
        return null;
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
