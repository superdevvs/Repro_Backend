<?php

namespace App\Services\ReproAi\Flows;

use App\Models\AiChatSession;
use App\Models\Shoot;
use App\Models\User;
use App\Services\ReproAi\ShootService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

class ManageBookingFlow
{
    public function __construct(
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
        $step = $session->step ?? 'ask_booking';
        $data = $session->state_data ?? [];

        return match($step) {
            'ask_booking' => $this->askBooking($session, $message, $data),
            'show_options' => $this->showOptions($session, $message, $data),
            'reschedule' => $this->handleReschedule($session, $message, $data),
            'change_services' => $this->handleChangeServices($session, $message, $data),
            'confirm_cancel' => $this->handleConfirmCancel($session, $message, $data),
            'confirm_change' => $this->confirmChange($session, $message, $data),
            default => $this->askBooking($session, $message, $data),
        };
    }

    private function askBooking(AiChatSession $session, string $message, array $data): array
    {
        // Check if message matches a shoot from suggestions
        if (empty($data['shoot_id'])) {
            $upcomingShoots = $this->shootService->listUpcomingForUser($session->user_id, 10);
            
            // Try to match message to a shoot
            foreach ($upcomingShoots as $shoot) {
                $shootLabel = "{$shoot->address}, {$shoot->city} - " . ($shoot->scheduled_at ? $shoot->scheduled_at->format('M d, Y') : 'TBD');
                if (str_contains($message, $shoot->address) || str_contains($message, (string)$shoot->id)) {
                    $data['shoot_id'] = $shoot->id;
                    break;
                }
            }
        }

        if (!empty($data['shoot_id'])) {
            $this->setStepAndData($session, 'show_options', $data);
            return $this->showOptions($session, $message, $data);
        }

        // First time asking - show upcoming shoots
        $upcomingShoots = $this->shootService->listUpcomingForUser($session->user_id, 10);
        
        if ($upcomingShoots->isEmpty()) {
            return [
                'assistant_messages' => [[
                    'content' => "You don't have any upcoming bookings in the next 30 days.",
                    'metadata' => ['step' => 'ask_booking'],
                ]],
                'suggestions' => [
                    'Book a new shoot',
                    'Check availability',
                ],
            ];
        }

        $this->setStepAndData($session, 'ask_booking', $data);

        $suggestions = [];
        foreach ($upcomingShoots as $shoot) {
            $dateStr = $shoot->scheduled_at ? $shoot->scheduled_at->format('M d, Y g:i A') : 'TBD';
            $label = "#{$shoot->id} - {$shoot->address}, {$shoot->city} - {$dateStr}";
            $suggestions[] = $label;
        }

        return [
            'assistant_messages' => [[
                'content' => "Which booking would you like to manage? (Next 30 days)",
                'metadata' => ['step' => 'ask_booking'],
            ]],
            'suggestions' => $suggestions,
            'meta' => [
                'shoots' => $upcomingShoots->map(fn($s) => [
                    'id' => $s->id,
                    'address' => $s->address,
                    'city' => $s->city,
                    'scheduled_at' => $s->scheduled_at?->toIso8601String(),
                ])->toArray(),
            ],
        ];
    }

    private function showOptions(AiChatSession $session, string $message, array $data): array
    {
        $shootId = $data['shoot_id'] ?? null;
        if (!$shootId) {
            return $this->askBooking($session, $message, $data);
        }

        $shoot = Shoot::find($shootId);
        if (!$shoot) {
            return [
                'assistant_messages' => [[
                    'content' => "I couldn't find that booking. Let's try again.",
                    'metadata' => ['step' => 'ask_booking'],
                ]],
                'suggestions' => ['Show my bookings'],
            ];
        }

        // Check if user selected an action
        $m = strtolower($message);
        if (str_contains($m, 'reschedule')) {
            $this->setStepAndData($session, 'reschedule', $data);
            return $this->handleReschedule($session, $message, $data);
        } elseif (str_contains($m, 'cancel')) {
            $this->setStepAndData($session, 'confirm_cancel', $data);
            return $this->handleConfirmCancel($session, $message, $data);
        } elseif (str_contains($m, 'change') && (str_contains($m, 'service') || str_contains($m, 'services'))) {
            $this->setStepAndData($session, 'change_services', $data);
            return $this->handleChangeServices($session, $message, $data);
        }

        // First time showing options
        $this->setStepAndData($session, 'show_options', $data);

        $shootInfo = "Booking #{$shoot->id}\n";
        $shootInfo .= "Property: {$shoot->address}, {$shoot->city}\n";
        $shootInfo .= "Date: " . ($shoot->scheduled_at ? $shoot->scheduled_at->format('M d, Y g:i A') : 'TBD') . "\n";
        $shootInfo .= "Status: {$shoot->status}";

        return [
            'assistant_messages' => [[
                'content' => "Here's the booking:\n\n{$shootInfo}\n\nWhat would you like to do?",
                'metadata' => ['step' => 'show_options', 'shoot_id' => $shoot->id],
            ]],
            'suggestions' => [
                'Reschedule',
                'Cancel',
                'Change services',
                'View details',
            ],
        ];
    }

    private function handleReschedule(AiChatSession $session, string $message, array $data): array
    {
        $shootId = $data['shoot_id'] ?? null;
        if (!$shootId) {
            return $this->askBooking($session, $message, $data);
        }

        $shoot = Shoot::find($shootId);
        if (!$shoot) {
            return [
                'assistant_messages' => [[
                    'content' => "I couldn't find that booking.",
                    'metadata' => ['step' => 'ask_booking'],
                ]],
            ];
        }

        // If we have a new date, update the shoot
        if (!empty($data['new_date'])) {
            $user = User::find($session->user_id);
            $updateData = [
                'date' => $data['new_date'],
                'time_window' => $data['new_time'] ?? $shoot->time,
            ];
            
            $this->shootService->updateFromAiConversation($shoot, $updateData, $user);
            
            $this->setStepAndData($session, null, null); // Reset flow
            return [
                'assistant_messages' => [[
                    'content' => "✅ I've rescheduled the shoot to {$data['new_date']} at {$data['new_time']}.",
                    'metadata' => ['step' => 'done', 'shoot_id' => $shoot->id],
                ]],
                'suggestions' => [
                    'Manage another booking',
                    'Book a new shoot',
                ],
            ];
        }

        // Ask for new date
        if (empty($data['new_date']) && !empty(trim($message)) && !str_contains(strtolower($message), 'reschedule')) {
            $data['new_date'] = $message;
            $this->setStepAndData($session, 'reschedule', $data);
            
            return [
                'assistant_messages' => [[
                    'content' => "What time works best on {$message}?",
                    'metadata' => ['step' => 'reschedule'],
                ]],
                'suggestions' => [
                    'Morning (10 AM)',
                    'Afternoon (2 PM)',
                    'Evening (5 PM)',
                    'Flexible',
                ],
            ];
        }

        // If we have date but not time, capture time
        if (!empty($data['new_date']) && empty($data['new_time']) && !empty(trim($message))) {
            $data['new_time'] = $message;
            $this->setStepAndData($session, 'reschedule', $data);
            
            // Now we have both date and time, update the shoot
            $user = User::find($session->user_id);
            $updateData = [
                'date' => $data['new_date'],
                'time_window' => $data['new_time'],
            ];
            
            $this->shootService->updateFromAiConversation($shoot, $updateData, $user);
            
            $this->setStepAndData($session, null, null); // Reset flow
            return [
                'assistant_messages' => [[
                    'content' => "✅ I've rescheduled the shoot to {$data['new_date']} at {$data['new_time']}.",
                    'metadata' => ['step' => 'done', 'shoot_id' => $shoot->id],
                ]],
                'suggestions' => [
                    'Manage another booking',
                    'Book a new shoot',
                ],
            ];
        }

        // First time asking for reschedule
        $this->setStepAndData($session, 'reschedule', $data);
        return [
            'assistant_messages' => [[
                'content' => "What date would you like to reschedule to?",
                'metadata' => ['step' => 'reschedule'],
            ]],
            'suggestions' => [
                'Tomorrow',
                'Next week',
                'This weekend',
            ],
        ];
    }

    private function handleChangeServices(AiChatSession $session, string $message, array $data): array
    {
        $shootId = $data['shoot_id'] ?? null;
        if (!$shootId) {
            return $this->askBooking($session, $message, $data);
        }

        $shoot = Shoot::find($shootId);
        if (!$shoot) {
            return [
                'assistant_messages' => [[
                    'content' => "I couldn't find that booking.",
                    'metadata' => ['step' => 'ask_booking'],
                ]],
            ];
        }

        // If we have new services, update
        if (!empty($data['new_service_ids'])) {
            $user = User::find($session->user_id);
            $updateData = ['service_ids' => $data['new_service_ids']];
            
            $this->shootService->updateFromAiConversation($shoot, $updateData, $user);
            
            $this->setStepAndData($session, null, null);
            return [
                'assistant_messages' => [[
                    'content' => "✅ I've updated the services for this booking.",
                    'metadata' => ['step' => 'done', 'shoot_id' => $shoot->id],
                ]],
                'suggestions' => [
                    'Manage another booking',
                    'Book a new shoot',
                ],
            ];
        }

        // Parse services from message if provided
        if (!empty(trim($message)) && empty($data['new_service_ids'])) {
            $serviceIds = $this->inferServiceIdsFromText($message);
            if (!empty($serviceIds)) {
                $data['new_service_ids'] = $serviceIds;
                $user = User::find($session->user_id);
                $updateData = ['service_ids' => $serviceIds];
                
                $this->shootService->updateFromAiConversation($shoot, $updateData, $user);
                
                $this->setStepAndData($session, null, null);
                return [
                    'assistant_messages' => [[
                        'content' => "✅ I've updated the services for this booking.",
                        'metadata' => ['step' => 'done', 'shoot_id' => $shoot->id],
                    ]],
                    'suggestions' => [
                        'Manage another booking',
                        'Book a new shoot',
                    ],
                ];
            }
        }

        // Ask for new services
        $this->setStepAndData($session, 'change_services', $data);
        return [
            'assistant_messages' => [[
                'content' => "What services would you like for this booking?",
                'metadata' => ['step' => 'change_services'],
            ]],
            'suggestions' => [
                'Photos only',
                'Photos + video',
                'Photos + video + drone',
                'Full package',
            ],
        ];
    }

    private function inferServiceIdsFromText(string $text): array
    {
        $t = strtolower($text);
        $serviceIds = [];

        $services = \App\Models\Service::all(['id', 'name']);
        foreach ($services as $service) {
            $serviceName = strtolower($service->name);
            if (str_contains($t, $serviceName) || str_contains($serviceName, $t)) {
                $serviceIds[] = $service->id;
            }
        }

        // Fallback: if no matches, try common keywords
        if (empty($serviceIds)) {
            if (str_contains($t, 'photo')) {
                $photoService = \App\Models\Service::where('name', 'like', '%photo%')->first();
                if ($photoService) $serviceIds[] = $photoService->id;
            }
            if (str_contains($t, 'video')) {
                $videoService = \App\Models\Service::where('name', 'like', '%video%')->first();
                if ($videoService) $serviceIds[] = $videoService->id;
            }
            if (str_contains($t, 'drone')) {
                $droneService = \App\Models\Service::where('name', 'like', '%drone%')->first();
                if ($droneService) $serviceIds[] = $droneService->id;
            }
        }

        return $serviceIds;
    }

    private function handleConfirmCancel(AiChatSession $session, string $message, array $data): array
    {
        $shootId = $data['shoot_id'] ?? null;
        if (!$shootId) {
            return $this->askBooking($session, $message, $data);
        }

        $shoot = Shoot::find($shootId);
        if (!$shoot) {
            return [
                'assistant_messages' => [[
                    'content' => "I couldn't find that booking.",
                    'metadata' => ['step' => 'ask_booking'],
                ]],
            ];
        }

        $m = strtolower($message);
        if (str_contains($m, 'yes') || str_contains($m, 'confirm')) {
            $user = User::find($session->user_id);
            $this->shootService->cancelShoot($shoot, $user);
            
            $this->setStepAndData($session, null, null);
            return [
                'assistant_messages' => [[
                    'content' => "✅ I've cancelled the booking for {$shoot->address}, {$shoot->city}.",
                    'metadata' => ['step' => 'done', 'shoot_id' => $shoot->id],
                ]],
                'suggestions' => [
                    'Manage another booking',
                    'Book a new shoot',
                ],
            ];
        }

        // Ask for confirmation
        $this->setStepAndData($session, 'confirm_cancel', $data);
        return [
            'assistant_messages' => [[
                'content' => "Are you sure you want to cancel the booking for {$shoot->address}, {$shoot->city} on " . ($shoot->scheduled_at ? $shoot->scheduled_at->format('M d, Y') : 'TBD') . "?",
                'metadata' => ['step' => 'confirm_cancel'],
            ]],
            'suggestions' => [
                'Yes, cancel it',
                'No, keep it',
            ],
        ];
    }

    private function confirmChange(AiChatSession $session, string $message, array $data): array
    {
        return [
            'assistant_messages' => [[
                'content' => "I've noted your request. This feature is coming soon!",
                'metadata' => ['step' => 'confirm_change'],
            ]],
            'suggestions' => [
                'Book a new shoot',
                'Check availability',
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

