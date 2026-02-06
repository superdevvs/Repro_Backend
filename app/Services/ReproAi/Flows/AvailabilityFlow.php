<?php

namespace App\Services\ReproAi\Flows;

use App\Models\AiChatSession;
use App\Models\User;
use App\Services\PhotographerAvailabilityService;
use App\Services\ReproAi\FlowEngine\FlowEngine;
use App\Services\ReproAi\FlowEngine\FlowHandlerInterface;
use App\Services\ReproAi\FlowEngine\FlowState;
use App\Services\ReproAi\FlowEngine\FlowTransition;
use App\Services\ReproAi\ShootService;
use Illuminate\Support\Carbon;

class AvailabilityFlow implements FlowHandlerInterface
{
    public function __construct(
        protected PhotographerAvailabilityService $availabilityService,
        protected ShootService $shootService,
        protected FlowEngine $flowEngine,
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
        return $this->flowEngine->handle($session, $message, $context, $this);
    }

    public function defaultStep(): string
    {
        return 'ask_photographer';
    }

    public function handleStep(string $step, FlowState $state): FlowTransition
    {
        return match ($step) {
            'ask_photographer' => $this->askPhotographer($state),
            'ask_date_range' => $this->askDateRange($state),
            'show_slots' => $this->showSlots($state),
            default => $this->askPhotographer($state),
        };
    }

    private function askPhotographer(FlowState $state): FlowTransition
    {
        $data = $state->data;
        // Check if photographer_id is already set
        if (!empty($data['photographer_id'])) {
            $transition = $this->askDateRange($state->withData($data));

            return $transition->nextStep || $transition->clearStep
                ? $transition
                : FlowTransition::next('ask_date_range', $transition->response, $transition->data ?? $data);
        }

        // Try to match photographer from message
        $messageLower = $state->messageLower();

        $parsedDate = null;
        if (!empty(trim($state->message)) && empty($data['check_date'])) {
            $parsedDate = $this->parseDateFromMessage($state->message);
            if ($parsedDate) {
                $data['check_date'] = $parsedDate->format('Y-m-d');
            }
        }
        
        // Handle "All photographers" selection
        if (str_contains($messageLower, 'all photographer') || str_contains($messageLower, 'any photographer') || $messageLower === 'all' || $messageLower === 'any') {
            $data['photographer_id'] = null; // null means all photographers
            $data['photographer_name'] = 'All photographers';
            $transition = !empty($data['check_date'])
                ? $this->showSlots($state->withData($data))
                : $this->askDateRange($state->withData($data));

            return $transition->nextStep || $transition->clearStep
                ? $transition
                : FlowTransition::next(!empty($data['check_date']) ? 'show_slots' : 'ask_date_range', $transition->response, $transition->data ?? $data);
        }

        $photographers = User::where('role', 'photographer')->get(['id', 'name']);
        $matchedPhotographer = null;

        if (!empty(trim($state->message))) {
            foreach ($photographers as $photographer) {
                $photographerName = strtolower($photographer->name);
                $nameParts = preg_split('/\s+/', $photographerName, -1, PREG_SPLIT_NO_EMPTY);

                if ($messageLower === $photographerName || str_contains($messageLower, $photographerName)) {
                    $matchedPhotographer = $photographer;
                    break;
                }

                if (strlen($messageLower) >= 3 && preg_match('/\b' . preg_quote($messageLower, '/') . '\b/', $photographerName)) {
                    $matchedPhotographer = $photographer;
                    break;
                }

                foreach ($nameParts as $part) {
                    if (strlen($part) < 3) {
                        continue;
                    }
                    if (preg_match('/\b' . preg_quote($part, '/') . '\b/', $messageLower)) {
                        $matchedPhotographer = $photographer;
                        break 2;
                    }
                }
            }
        }

        if ($matchedPhotographer) {
            $data['photographer_id'] = $matchedPhotographer->id;
            $data['photographer_name'] = $matchedPhotographer->name;
            $transition = !empty($data['check_date'])
                ? $this->showSlots($state->withData($data))
                : $this->askDateRange($state->withData($data));

            return $transition->nextStep || $transition->clearStep
                ? $transition
                : FlowTransition::next(!empty($data['check_date']) ? 'show_slots' : 'ask_date_range', $transition->response, $transition->data ?? $data);
        }

        $cleanedMessage = trim(preg_replace(
            '/\b(check|availability|available|slots?|times?|for|photographer|photographers|please|show|me|any|all)\b/',
            '',
            $messageLower
        ));
        $cleanedMessage = trim(preg_replace('/\s+/', ' ', $cleanedMessage));

        $suggestions = $photographers->take(10)->pluck('name')->all();
        $suggestions[] = 'All photographers';

        if (!empty(trim($state->message)) && !$parsedDate && $cleanedMessage !== '') {
            $nameLabel = $cleanedMessage ?: trim($state->message);
            return FlowTransition::stay([
                'assistant_messages' => [[
                    'content' => "I couldn't find a photographer named \"{$nameLabel}\". Want to check someone else or all photographers?",
                    'metadata' => ['step' => 'ask_photographer', 'error' => 'photographer_not_found'],
                ]],
                'suggestions' => $suggestions,
            ], $data);
        }

        // First time asking - show photographer list
        $introMessage = !empty($data['check_date']) && $parsedDate
            ? 'Got it. Which photographer should I check for that date?'
            : "Which photographer's availability would you like to check?";

        return FlowTransition::stay([
            'assistant_messages' => [[
                'content' => $introMessage,
                'metadata' => ['step' => 'ask_photographer'],
            ]],
            'suggestions' => $suggestions,
        ], $data);
    }

    private function askDateRange(FlowState $state): FlowTransition
    {
        $data = $state->data;
        $photographerName = $data['photographer_name'] ?? 'photographers';
        
        // Parse date from message
        if (!empty(trim($state->message)) && empty($data['check_date'])) {
            $date = $this->parseDateFromMessage($state->message);
            if ($date) {
                $data['check_date'] = $date->format('Y-m-d');
                $transition = $this->showSlots($state->withData($data));

                return $transition->nextStep || $transition->clearStep
                    ? $transition
                    : FlowTransition::next('show_slots', $transition->response, $transition->data ?? $data);
            }
        }

        // If we have a date, show slots
        if (!empty($data['check_date'])) {
            $transition = $this->showSlots($state->withData($data));

            return $transition->nextStep || $transition->clearStep
                ? $transition
                : FlowTransition::next('show_slots', $transition->response, $transition->data ?? $data);
        }

        // First time asking
        return FlowTransition::stay([
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
        ], $data);
    }

    private function showSlots(FlowState $state): FlowTransition
    {
        $data = $state->data;
        $checkDate = !empty($data['check_date']) 
            ? Carbon::parse($data['check_date']) 
            : now();
        
        $photographerId = $data['photographer_id'] ?? null;
        $photographerName = $data['photographer_name'] ?? ($photographerId 
            ? User::find($photographerId)?->name ?? 'photographer'
            : 'all photographers');

        // Check if user wants to check different date
        $messageLower = $state->messageLower();
        if (str_contains($messageLower, 'different date') || str_contains($messageLower, 'another date') || str_contains($messageLower, 'check tomorrow') || str_contains($messageLower, 'check next')) {
            unset($data['check_date']);
            $transition = $this->askDateRange($state->withData($data));

            return $transition->nextStep || $transition->clearStep
                ? $transition
                : FlowTransition::next('ask_date_range', $transition->response, $transition->data ?? $data);
        }

        // Check if user wants to book a slot
        if (str_contains($messageLower, 'book at') || str_contains($messageLower, 'book ')) {
            // Extract time from message and transition to booking flow
            return FlowTransition::stay([
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
            ], $data);
        }

        // Get available slots for the date
        $availableSlots = $this->shootService->getAvailabilityForDate($checkDate, $photographerId);

        $dateStr = $checkDate->format('l, M d, Y'); // e.g., "Monday, Dec 30, 2024"
        
        if (empty($availableSlots)) {
            return FlowTransition::stay([
                'assistant_messages' => [[
                    'content' => "📅 No available slots found for **{$photographerName}** on **{$dateStr}**.\n\nWould you like to check a different date?",
                    'metadata' => ['step' => 'show_slots', 'date' => $checkDate->format('Y-m-d')],
                ]],
                'suggestions' => [
                    'Check tomorrow',
                    'Check next week',
                    'Book a shoot anyway',
                ],
            ], $data);
        }

        $slotsText = "📅 **{$photographerName}** availability for **{$dateStr}**:\n\n";
        foreach ($availableSlots as $slot) {
            $slotsText .= "• ✅ {$slot['display']}\n";
        }

        $suggestions = array_map(fn($slot) => "Book at {$slot['display']}", array_slice($availableSlots, 0, 3));
        $suggestions[] = 'Check different date';

        return FlowTransition::stay([
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
        ], $data);
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

}

