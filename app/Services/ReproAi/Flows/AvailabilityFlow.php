<?php

namespace App\Services\ReproAi\Flows;

use App\Models\AiChatSession;
use App\Models\User;
use App\Services\PhotographerAvailabilityService;
use App\Services\ReproAi\ShootService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class AvailabilityFlow
{
    public function __construct(
        protected PhotographerAvailabilityService $availabilityService,
        protected ShootService $shootService,
    ) {}

    /**
     * @return array{
     *   assistant_messages: array<int,array{content:string,metadata?:array}>,
     *   suggestions?: array<int,string>,
     *   actions?: array<int,array>
     * }
     */
    public function handle(AiChatSession $session, string $message, array $context = []): array
    {
        $step = $session->step ?? 'ask_photographer';
        $data = $session->state_data ?? [];

        return match($step) {
            'ask_photographer' => $this->askPhotographer($session, $message, $data),
            'ask_date_range' => $this->askDateRange($session, $message, $data),
            'show_slots' => $this->showSlots($session, $message, $data),
            default => $this->askPhotographer($session, $message, $data),
        };
    }

    private function askPhotographer(AiChatSession $session, string $message, array $data): array
    {
        // Check if photographer_id is already set
        if (!empty($data['photographer_id'])) {
            $this->setStepAndData($session, 'ask_date_range', $data);
            $session->save();
            return $this->askDateRange($session, $message, $data);
        }

        // Try to match photographer from message
        $messageLower = strtolower(trim($message));
        
        // Handle "All photographers" selection
        if (str_contains($messageLower, 'all photographer') || $messageLower === 'all' || $messageLower === 'any') {
            $data['photographer_id'] = null; // null means all photographers
            $data['photographer_name'] = 'All photographers';
            $this->setStepAndData($session, 'ask_date_range', $data);
            $session->save();
            return $this->askDateRange($session, $message, $data);
        }

        // Try to match a specific photographer by name
        if (!empty(trim($message)) && !str_contains($messageLower, 'availability') && !str_contains($messageLower, 'available')) {
            $photographers = User::where('role', 'photographer')->get(['id', 'name']);
            
            foreach ($photographers as $photographer) {
                if (strtolower($photographer->name) === $messageLower || 
                    str_contains($messageLower, strtolower($photographer->name)) ||
                    str_contains(strtolower($photographer->name), $messageLower)) {
                    $data['photographer_id'] = $photographer->id;
                    $data['photographer_name'] = $photographer->name;
                    $this->setStepAndData($session, 'ask_date_range', $data);
                    $session->save();
                    return $this->askDateRange($session, $message, $data);
                }
            }
        }

        // First time asking - show photographer list
        $this->setStepAndData($session, 'ask_photographer', $data);
        $session->save();

        $photographers = User::where('role', 'photographer')
            ->limit(10)
            ->get(['id', 'name']);

        $suggestions = [];
        foreach ($photographers as $photographer) {
            $suggestions[] = $photographer->name;
        }
        $suggestions[] = 'All photographers';

        return [
            'assistant_messages' => [[
                'content' => "Which photographer's availability would you like to check?",
                'metadata' => ['step' => 'ask_photographer'],
            ]],
            'suggestions' => $suggestions,
        ];
    }

    private function askDateRange(AiChatSession $session, string $message, array $data): array
    {
        $photographerName = $data['photographer_name'] ?? 'photographers';
        
        // Parse date from message
        if (!empty(trim($message)) && empty($data['check_date'])) {
            $date = $this->parseDateFromMessage($message);
            if ($date) {
                $data['check_date'] = $date->format('Y-m-d');
                $this->setStepAndData($session, 'show_slots', $data);
                $session->save();
                return $this->showSlots($session, $message, $data);
            }
        }

        // If we have a date, show slots
        if (!empty($data['check_date'])) {
            $this->setStepAndData($session, 'show_slots', $data);
            $session->save();
            return $this->showSlots($session, $message, $data);
        }

        // First time asking
        $this->setStepAndData($session, 'ask_date_range', $data);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => "What date would you like to check **{$photographerName}** availability for?",
                'metadata' => ['step' => 'ask_date_range', 'photographer_name' => $photographerName],
            ]],
            'suggestions' => [
                'Today',
                'Tomorrow',
                'This week',
                'Next week',
            ],
        ];
    }

    private function showSlots(AiChatSession $session, string $message, array $data): array
    {
        $checkDate = !empty($data['check_date']) 
            ? Carbon::parse($data['check_date']) 
            : now();
        
        $photographerId = $data['photographer_id'] ?? null;
        $photographerName = $data['photographer_name'] ?? ($photographerId 
            ? User::find($photographerId)?->name ?? 'photographer'
            : 'all photographers');

        // Check if user wants to check different date
        $messageLower = strtolower(trim($message));
        if (str_contains($messageLower, 'different date') || str_contains($messageLower, 'another date') || str_contains($messageLower, 'check tomorrow') || str_contains($messageLower, 'check next')) {
            unset($data['check_date']);
            $this->setStepAndData($session, 'ask_date_range', $data);
            $session->save();
            return $this->askDateRange($session, $message, $data);
        }

        // Check if user wants to book a slot
        if (str_contains($messageLower, 'book at') || str_contains($messageLower, 'book ')) {
            // Extract time from message and transition to booking flow
            return [
                'assistant_messages' => [[
                    'content' => "Great choice! Let me start a booking for that time slot. What property would you like to shoot?",
                    'metadata' => [
                        'step' => 'transition_to_booking',
                        'date' => $checkDate->format('Y-m-d'),
                        'photographer_id' => $photographerId,
                    ],
                ]],
                'suggestions' => [
                    'Enter new address',
                ],
                'actions' => [
                    [
                        'type' => 'switch_flow',
                        'flow' => 'book_shoot',
                        'context' => [
                            'date' => $checkDate->format('Y-m-d'),
                            'photographer_id' => $photographerId,
                        ],
                    ],
                ],
            ];
        }

        // Get available slots for the date
        $availableSlots = $this->shootService->getAvailabilityForDate($checkDate, $photographerId);

        $dateStr = $checkDate->format('l, M d, Y'); // e.g., "Monday, Dec 30, 2024"
        
        if (empty($availableSlots)) {
            $this->setStepAndData($session, 'show_slots', $data);
            $session->save();
            
            return [
                'assistant_messages' => [[
                    'content' => "📅 No available slots found for **{$photographerName}** on **{$dateStr}**.\n\nWould you like to check a different date?",
                    'metadata' => ['step' => 'show_slots', 'date' => $checkDate->format('Y-m-d')],
                ]],
                'suggestions' => [
                    'Check tomorrow',
                    'Check next week',
                    'Book a shoot anyway',
                ],
            ];
        }

        $slotsText = "📅 **{$photographerName}** availability for **{$dateStr}**:\n\n";
        foreach ($availableSlots as $slot) {
            $slotsText .= "• ✅ {$slot['display']}\n";
        }

        $suggestions = array_map(fn($slot) => "Book at {$slot['display']}", array_slice($availableSlots, 0, 3));
        $suggestions[] = 'Check different date';

        $this->setStepAndData($session, 'show_slots', $data);
        $session->save();

        return [
            'assistant_messages' => [[
                'content' => $slotsText . "\nWould you like to book one of these slots?",
                'metadata' => [
                    'step' => 'show_slots', 
                    'date' => $checkDate->format('Y-m-d'), 
                    'slots' => $availableSlots,
                    'photographer_id' => $photographerId,
                ],
            ]],
            'suggestions' => $suggestions,
            'meta' => [
                'date' => $checkDate->format('Y-m-d'),
                'slots' => $availableSlots,
                'photographer_id' => $photographerId,
            ],
        ];
    }

    private function parseDateFromMessage(string $message): ?Carbon
    {
        $m = strtolower($message);
        
        if ($m === 'today') {
            return now();
        } elseif ($m === 'tomorrow') {
            return now()->addDay();
        } elseif ($m === 'this week' || $m === 'week') {
            return now()->next(Carbon::MONDAY);
        } elseif ($m === 'next week') {
            return now()->addWeek()->next(Carbon::MONDAY);
        } elseif (preg_match('/(\d{4}-\d{2}-\d{2})/', $message, $matches)) {
            return Carbon::parse($matches[1]);
        } elseif (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $message, $matches)) {
            return Carbon::createFromFormat('m/d/Y', $matches[1]);
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

