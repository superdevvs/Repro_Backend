<?php

namespace App\Services\ReproAi\Flows;

use App\Models\AiChatSession;
use App\Models\Shoot;
use App\Models\Service;
use App\Services\ReproAi\FlowEngine\FlowEngine;
use App\Services\ReproAi\FlowEngine\FlowHandlerInterface;
use App\Services\ReproAi\FlowEngine\FlowState;
use App\Services\ReproAi\FlowEngine\FlowTransition;
use App\Services\ReproAi\ShootService;
use App\Services\ReproAi\Tools\PaymentTools;

class BookShootFlow implements FlowHandlerInterface
{
    protected ShootService $shootService;
    protected PaymentTools $paymentTools;
    protected FlowEngine $flowEngine;

    public function __construct(
        ?ShootService $shootService = null,
        ?PaymentTools $paymentTools = null,
        ?FlowEngine $flowEngine = null,
    )
    {
        $this->shootService = $shootService ?? app(ShootService::class);
        $this->paymentTools = $paymentTools ?? app(PaymentTools::class);
        $this->flowEngine = $flowEngine ?? app(FlowEngine::class);
    }

    /**
     * @param  array<string,mixed>  $context
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
        return 'ask_property';
    }

    public function handleStep(string $step, FlowState $state): FlowTransition
    {
        return match ($step) {
            'ask_property'   => $this->askProperty($state),
            'ask_date'       => $this->askDate($state),
            'ask_time'       => $this->askTime($state),
            'ask_services'   => $this->askServices($state),
            'confirm'        => $this->confirm($state),
            'done'           => $this->done($state),
            default          => $this->reset($state),
        };
    }

    protected function reset(FlowState $state): FlowTransition
    {
        $suggestions = $this->recentPropertySuggestions($state->session->user_id);
        if (empty($suggestions)) {
            $suggestions = ['Enter new address'];
        }

        return FlowTransition::next('ask_property', [
            'assistant_messages' => [[
                'content' => "Great! Let's book a new shoot. Which property is this for?",
                'metadata' => ['step' => 'ask_property'],
            ]],
            'suggestions' => $suggestions,
        ], []);
    }

    protected function askProperty(FlowState $state): FlowTransition
    {
        $data = $state->data;
        $context = $state->context;
        $message = $state->message;
        $session = $state->session;

        // if UI sends property info in context (button click)
        if (!empty($context['propertyAddress']) || !empty($context['property_id'])) {
            $data['property_address'] = $context['propertyAddress'] ?? null;
            $data['property_city'] = $context['propertyCity'] ?? null;
            $data['property_state'] = $context['propertyState'] ?? null;
            $data['property_zip'] = $context['propertyZip'] ?? null;
            
            if (!empty($data['property_address']) && !empty($data['property_city'])) {
                $data['property_label'] = $this->formatPropertyLabel($data);

                return FlowTransition::next('ask_date', [
                    'assistant_messages' => [[
                        'content'  => "Great, we'll shoot **{$data['property_label']}**.\n\nWhat date works best?",
                        'metadata' => ['step' => 'ask_date'],
                    ]],
                    'suggestions' => [
                        'Tomorrow morning',
                        'This weekend',
                        'Next available slot',
                    ],
                ], $data);
            }
        }

        // first time we enter this step (no property yet)
        if (empty($data['property_address'])) {
            // Detect intent-trigger phrases that should NOT be treated as a property address
            $intentTriggers = [
                'book a new shoot', 'book a shoot', 'book new shoot', 'book another shoot',
                'book shoot', 'new shoot', 'schedule a shoot', 'schedule shoot',
                'let\'s book', 'i want to book',
            ];
            $messageLower = strtolower(trim($message));
            $isIntentTrigger = in_array($messageLower, $intentTriggers, true);
            
            if ($isIntentTrigger) {
                $suggestions = $this->recentPropertySuggestions($session->user_id);
                if (empty($suggestions)) {
                    $suggestions = ['Enter new address'];
                }
                return FlowTransition::stay([
                    'assistant_messages' => [[
                        'content'  => "Great! Let's book a new shoot. Which property is this for?",
                        'metadata' => ['step' => 'ask_property'],
                    ]],
                    'suggestions' => $suggestions,
                ], $data);
            }

            // Check if message matches a suggested address
            $suggestions = $this->recentPropertySuggestions($session->user_id);
            $matchedAddress = null;
            
            foreach ($suggestions as $suggestion) {
                if (strtolower(trim($message)) === strtolower(trim($suggestion))) {
                    // User selected a suggested address, try to find it in shoots
                    $matchedShoot = Shoot::where(function ($query) use ($session) {
                        $query->where('client_id', $session->user_id)
                              ->orWhere('rep_id', $session->user_id);
                    })
                    ->where(function ($query) use ($message) {
                        $parts = explode(',', $message);
                        if (count($parts) >= 2) {
                            $query->where('address', 'like', '%' . trim($parts[0]) . '%')
                                  ->where('city', 'like', '%' . trim($parts[1]) . '%');
                        } else {
                            $query->where('address', 'like', '%' . trim($message) . '%');
                        }
                    })
                    ->orderBy('created_at', 'desc')
                    ->first();
                    
                    if ($matchedShoot) {
                        $data['property_address'] = $matchedShoot->address;
                        $data['property_city'] = $matchedShoot->city;
                        $data['property_state'] = $matchedShoot->state;
                        $data['property_zip'] = $matchedShoot->zip;
                        $data['property_label'] = $this->formatPropertyLabel($data);
                        $matchedAddress = $data['property_label'];
                        break;
                    }
                }
            }
            
            // If no match found, treat as new address
            if (!$matchedAddress) {
                $data['property_label'] = $message;
                // Try to parse address components if it looks like an address
                $parts = array_map('trim', explode(',', $message));
                if (count($parts) >= 2) {
                    $data['property_address'] = $parts[0];
                    $data['property_city'] = $parts[1] ?? '';
                    $data['property_state'] = $parts[2] ?? '';
                    $data['property_zip'] = $parts[3] ?? '';
                } else {
                    $data['property_address'] = $message;
                }
            }

            return FlowTransition::next('ask_date', [
                'assistant_messages' => [[
                    'content'  => "Got it. When would you like the shoot for **{$data['property_label']}**?",
                    'metadata' => ['step' => 'ask_date'],
                ]],
                'suggestions' => [
                    'Tomorrow',
                    'This week',
                    'Next week',
                ],
            ], $data);
        }

        // If property already set but user is changing it
        return FlowTransition::stay([
            'assistant_messages' => [[
                'content'  => "Sure, let's book a new shoot. Which property is this for?",
                'metadata' => ['step' => 'ask_property'],
            ]],
            'suggestions' => $this->recentPropertySuggestions($session->user_id),
        ], $data);
    }

    protected function askDate(FlowState $state): FlowTransition
    {
        $data = $state->data;
        $message = $state->message;

        // Treat user entry as label, but only persist a parsed value if valid
        $trimmed = trim($message);
        if (!empty($trimmed)) {
            $data['date_label'] = $message;
            $parsedDate = $this->parseDateFromMessage($trimmed);

            if ($parsedDate) {
                $data['date'] = $parsedDate;
            } else {
                unset($data['date']);
            }

            // Check if message also contains time info (e.g., "tomorrow morning")
            $parsedTime = $this->parseTimeFromMessage($trimmed);
            if ($parsedTime) {
                $data['time_label'] = $parsedTime;
                $data['time_window'] = $parsedTime;

                // Skip time step, go directly to services
                return FlowTransition::next('ask_services', [
                    'assistant_messages' => [[
                        'content'  => "Got it, {$message}. What services would you like?",
                        'metadata' => ['step' => 'ask_services'],
                    ]],
                    'suggestions' => [
                        'Photos only',
                        'Photos + video',
                        'Photos + drone',
                        'Full package (photos, video, drone, floorplan)',
                    ],
                ], $data);
            }

            return FlowTransition::next('ask_time', [
                'assistant_messages' => [[
                    'content'  => "What time of day works best?",
                    'metadata' => ['step' => 'ask_time'],
                ]],
                'suggestions' => [
                    'Morning',
                    'Afternoon',
                    'Golden hour',
                ],
            ], $data);
        }

        // re-ask if empty
        return FlowTransition::stay([
            'assistant_messages' => [[
                'content'  => "I didn't catch the date. What date should we book?",
                'metadata' => ['step' => 'ask_date'],
            ]],
            'suggestions' => [
                'Tomorrow',
                'This week',
                'Next week',
            ],
        ], $data);
    }

    protected function askTime(FlowState $state): FlowTransition
    {
        $data = $state->data;
        $message = $state->message;

        if (!empty(trim($message))) {
            $data['time_label'] = $message;
            $data['time_window'] = $message;

            return FlowTransition::next('ask_services', [
                'assistant_messages' => [[
                    'content'  => "What would you like us to capture?",
                    'metadata' => ['step' => 'ask_services'],
                ]],
                'suggestions' => [
                    'Photos only',
                    'Photos + video',
                    'Photos + drone',
                    'Full package (photos, video, drone, floorplan)',
                ],
            ], $data);
        }

        return FlowTransition::stay([
            'assistant_messages' => [[
                'content'  => "What time of day should we aim for?",
                'metadata' => ['step' => 'ask_time'],
            ]],
            'suggestions' => [
                'Morning',
                'Afternoon',
                'Golden hour',
            ],
        ], $data);
    }

    protected function askServices(FlowState $state): FlowTransition
    {
        $data = $state->data;
        $message = $state->message;

        if (!empty(trim($message))) {
            $data['services_label'] = $message;
            // Map label → internal service IDs
            $data['service_ids'] = $this->inferServiceIdsFromText($message);

            // Build a detailed summary
            $propertyLabel = $data['property_label'] ?? ($data['property_address'] ?? 'Unknown property');
            $dateLabel = $data['date_label'] ?? 'TBD';
            $timeLabel = $data['time_label'] ?? 'TBD';
            $servicesLabel = $data['services_label'] ?? 'TBD';
            
            // Calculate estimated total if we have service IDs
            $estimatedTotal = null;
            if (!empty($data['service_ids'])) {
                $services = Service::whereIn('id', $data['service_ids'])->get();
                $estimatedTotal = $services->sum('price');
            }
            
            $summary = "📋 **Booking Summary**\n\n";
            $summary .= "📍 **Property**: {$propertyLabel}\n";
            $summary .= "📅 **Date**: {$dateLabel}\n";
            $summary .= "⏰ **Time**: {$timeLabel}\n";
            $summary .= "📸 **Services**: {$servicesLabel}\n";
            
            if ($estimatedTotal) {
                $summary .= "💰 **Estimated Total**: $" . number_format($estimatedTotal, 2) . "\n";
            }
            
            $summary .= "\nPlease review the details above. Ready to confirm this booking?";

            return FlowTransition::next('confirm', [
                'assistant_messages' => [[
                    'content'  => $summary,
                    'metadata' => [
                        'step' => 'confirm',
                        'summary' => [
                            'property' => $propertyLabel,
                            'date' => $dateLabel,
                            'time' => $timeLabel,
                            'services' => $servicesLabel,
                            'estimated_total' => $estimatedTotal,
                        ],
                    ],
                ]],
                'suggestions' => [
                    'Yes, confirm booking',
                    'Change the date',
                    'Change the services',
                    'Change the property',
                ],
            ], $data);
        }

        return FlowTransition::stay([
            'assistant_messages' => [[
                'content'  => "What services do you want for this shoot?",
                'metadata' => ['step' => 'ask_services'],
            ]],
            'suggestions' => [
                'Photos only',
                'Photos + video',
                'Photos + drone',
                'Full package (photos, video, drone, floorplan)',
            ],
        ], $data);
    }

    protected function confirm(FlowState $state): FlowTransition
    {
        $data = $state->data;
        $message = $state->message;
        $session = $state->session;
        $m = strtolower($message);

        if (str_contains($m, 'change date') || (str_contains($m, 'change') && str_contains($m, 'date'))) {
            return FlowTransition::next('ask_date', [
                'assistant_messages' => [[
                    'content'  => "No problem. What date works better?",
                    'metadata' => ['step' => 'ask_date'],
                ]],
                'suggestions' => [
                    'Tomorrow',
                    'This week',
                    'Next week',
                ],
            ], $data);
        }

        if (str_contains($m, 'change') && (str_contains($m, 'service') || str_contains($m, 'services'))) {
            return FlowTransition::next('ask_services', [
                'assistant_messages' => [[
                    'content'  => "Sure, what services should we switch to?",
                    'metadata' => ['step' => 'ask_services'],
                ]],
                'suggestions' => [
                    'Photos only',
                    'Photos + video',
                    'Photos + drone',
                    'Full package (photos, video, drone, floorplan)',
                ],
            ], $data);
        }
        
        if (str_contains($m, 'change') && (str_contains($m, 'property') || str_contains($m, 'address'))) {
            return FlowTransition::next('ask_property', [
                'assistant_messages' => [[
                    'content'  => "Sure, which property should we use instead?",
                    'metadata' => ['step' => 'ask_property'],
                ]],
                'suggestions' => $this->recentPropertySuggestions($session->user_id),
            ], $data);
        }

        // Handle retry/start over from error state
        if (str_contains($m, 'try again') || str_contains($m, 'retry')) {
            $propertyLabel = $data['property_label'] ?? ($data['property_address'] ?? 'Unknown property');
            $dateLabel = $data['date_label'] ?? 'TBD';
            $timeLabel = $data['time_label'] ?? 'TBD';
            $servicesLabel = $data['services_label'] ?? 'TBD';
            
            return FlowTransition::stay([
                'assistant_messages' => [[
                    'content'  => "Let's try again. Ready to confirm this booking?\n\n📍 {$propertyLabel}\n📅 {$dateLabel}\n⏰ {$timeLabel}\n📸 {$servicesLabel}",
                    'metadata' => ['step' => 'confirm'],
                ]],
                'suggestions' => [
                    'Yes, confirm booking',
                    'Change the date',
                    'Change the services',
                ],
            ], $data);
        }
        
        if (str_contains($m, 'start over') || str_contains($m, 'nevermind') || str_contains($m, 'never mind')) {
            return $this->reset($state->withData([]));
        }

        // Check for confirmation
        $isConfirmed = str_contains($m, 'yes') || 
                       str_contains($m, 'confirm') || 
                       str_contains($m, 'book') ||
                       str_contains($m, 'proceed') ||
                       str_contains($m, 'go ahead') ||
                       str_contains($m, 'do it') ||
                       str_contains($m, 'sure') ||
                       str_contains($m, 'looks good') ||
                       str_contains($m, 'perfect');
        
        if (!$isConfirmed) {
            // user is unsure; gently re-ask with better suggestions
            return FlowTransition::stay([
                'assistant_messages' => [[
                    'content'  => "Would you like me to go ahead and confirm this booking?",
                    'metadata' => ['step' => 'confirm'],
                ]],
                'suggestions' => [
                    'Yes, confirm booking',
                    'Change the date',
                    'Change the services',
                    'Change the property',
                ],
            ], $data);
        }

        // We "book" the shoot using your existing service
        try {
            $booking = $this->shootService->createFromReproAi($session->user_id, $data);
            $dataWithBooking = array_merge($data, ['shoot_id' => $booking->id]);

            // Create payment link if shoot has a total quote
            $paymentLink = null;
            $paymentMessage = '';
            if ($booking->total_quote > 0) {
                try {
                    $paymentResult = $this->paymentTools->createPaymentLink(
                        ['shoot_id' => $booking->id],
                        ['user_id' => $session->user_id]
                    );
                    
                    if ($paymentResult['success'] && !empty($paymentResult['checkout_url'])) {
                        $paymentLink = $paymentResult['checkout_url'];
                        $paymentMessage = "\n\n💰 **Payment**: You can pay for this shoot [here](" . $paymentLink . "). Total: $" . number_format($booking->total_quote, 2);
                    } elseif ($paymentResult['success'] && $paymentResult['amount_remaining'] == 0) {
                        $paymentMessage = "\n\n✅ This shoot is already fully paid.";
                    } else {
                        $paymentMessage = "\n\n💰 **Payment**: Total amount: $" . number_format($booking->total_quote, 2) . ". You can pay from the shoot details page.";
                    }
                } catch (\Exception $paymentError) {
                    // Payment link creation failed, but booking succeeded
                    \Log::warning('Failed to create payment link after booking', [
                        'shoot_id' => $booking->id,
                        'error' => $paymentError->getMessage(),
                    ]);
                    $paymentMessage = "\n\n💰 **Payment**: Total amount: $" . number_format($booking->total_quote, 2) . ". You can pay from the shoot details page.";
                }
            }

            $content = sprintf(
                "All set 🎉 I've booked a **%s** shoot for **%s** on **%s** at **%s**.%s\n\nYou can manage the booking in Shoot History.",
                $data['services_label'] ?? 'photo',
                $data['property_label'] ?? ($data['property_address'] ?? 'your property'),
                $data['date_label'] ?? 'the scheduled date',
                $data['time_label'] ?? 'the chosen time',
                $paymentMessage
            );

            $suggestions = ['View this shoot', 'Book another shoot'];
            if ($paymentLink) {
                array_unshift($suggestions, 'Pay now');
            }

            $actions = [
                [
                    'type' => 'open_shoot',
                    'shoot_id' => $booking->id,
                ],
            ];
            
            if ($paymentLink) {
                $actions[] = [
                    'type' => 'payment',
                    'shoot_id' => $booking->id,
                    'url' => $paymentLink,
                ];
            }

            return FlowTransition::next('done', [
                'assistant_messages' => [[
                    'content'  => $content,
                    'metadata' => [
                        'step' => 'done', 
                        'shoot_id' => $booking->id,
                        'payment_link' => $paymentLink,
                        'total_quote' => $booking->total_quote,
                    ],
                ]],
                'suggestions' => $suggestions,
                'actions' => $actions,
            ], $dataWithBooking);
        } catch (\Exception $e) {
            return FlowTransition::stay([
                'assistant_messages' => [[
                    'content'  => "I encountered an error: " . $e->getMessage() . ". Would you like to try again?",
                    'metadata' => ['step' => 'confirm', 'error' => $e->getMessage()],
                ]],
                'suggestions' => ['Try again', 'Start over'],
            ], $data);
        }
    }

    protected function done(FlowState $state): FlowTransition
    {
        return FlowTransition::clear([
            'assistant_messages' => [[
                'content'  => "Anything else you want to do with your bookings?",
                'metadata' => ['step' => 'done'],
            ]],
            'suggestions' => [
                'Book another shoot',
                'Manage an existing booking',
                'Check photographer availability',
            ],
        ], []);
    }

    // Helpers -------------------------------------------------------------

    protected function recentPropertySuggestions(int $userId): array
    {
        // Get all unique addresses from shoots where user is client or rep
        $shoots = Shoot::where(function ($query) use ($userId) {
            $query->where('client_id', $userId)
                  ->orWhere('rep_id', $userId);
        })
        ->whereNotNull('address')
        ->where('address', '!=', '')
        ->select('address', 'city', 'state', 'zip')
        ->orderBy('created_at', 'desc')
        ->get();

        // Get unique addresses (by address + city + state combination)
        $uniqueAddresses = $shoots->unique(function ($shoot) {
            return strtolower(trim($shoot->address)) . '|' . 
                   strtolower(trim($shoot->city ?? '')) . '|' . 
                   strtolower(trim($shoot->state ?? ''));
        });

        $suggestions = [];
        foreach ($uniqueAddresses->take(5) as $shoot) {
            $parts = array_filter([
                trim($shoot->address ?? ''),
                trim($shoot->city ?? ''),
                trim($shoot->state ?? ''),
            ]);
            
            if (!empty($parts)) {
                $label = implode(', ', $parts);
                if (!empty($label)) {
                    $suggestions[] = $label;
                }
            }
        }

        // Only show "Enter new address" if we have no suggestions
        if (empty($suggestions)) {
            $suggestions[] = 'Enter new address';
        }

        return $suggestions;
    }

    protected function inferServiceIdsFromText(string $text): array
    {
        $t = strtolower($text);
        $serviceIds = [];

        // Handle "Full package" - includes all main services
        if (str_contains($t, 'full package') || str_contains($t, 'everything') || str_contains($t, 'all services')) {
            $services = Service::where('name', 'like', '%photo%')
                ->orWhere('name', 'like', '%video%')
                ->orWhere('name', 'like', '%drone%')
                ->orWhere('name', 'like', '%floor%')
                ->pluck('id')
                ->toArray();
            
            if (!empty($services)) {
                return $services;
            }
        }

        // Handle common patterns
        $patterns = [
            'photo' => ['photo', 'picture', 'pics', 'images'],
            'video' => ['video', 'walkthrough', 'tour'],
            'drone' => ['drone', 'aerial', 'fly'],
            'floor' => ['floor', 'floorplan', 'floor plan', 'layout'],
            'iguide' => ['iguide', 'i-guide', 'matterport', '3d', 'virtual tour'],
        ];

        foreach ($patterns as $serviceType => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($t, $keyword)) {
                    $service = Service::where('name', 'like', "%{$serviceType}%")->first();
                    if ($service && !in_array($service->id, $serviceIds)) {
                        $serviceIds[] = $service->id;
                    }
                    break;
                }
            }
        }

        // Handle "photos only" - just photos
        if (str_contains($t, 'only') && str_contains($t, 'photo')) {
            $photoService = Service::where('name', 'like', '%photo%')->first();
            return $photoService ? [$photoService->id] : [];
        }

        // Try exact name matching if nothing found
        if (empty($serviceIds)) {
            $services = Service::all(['id', 'name']);
            foreach ($services as $service) {
                $serviceName = strtolower($service->name);
                if (str_contains($t, $serviceName) || str_contains($serviceName, $t)) {
                    $serviceIds[] = $service->id;
                }
            }
        }

        // Default to photo service if nothing matched
        if (empty($serviceIds)) {
            $photoService = Service::where('name', 'like', '%photo%')->first();
            if ($photoService) {
                $serviceIds[] = $photoService->id;
            } else {
                $firstService = Service::first();
                if ($firstService) {
                    $serviceIds[] = $firstService->id;
                }
            }
        }

        return array_unique($serviceIds);
    }

    protected function formatPropertyLabel(array $data): string
    {
        $parts = array_filter([
            $data['property_address'] ?? '',
            $data['property_city'] ?? '',
            $data['property_state'] ?? '',
        ]);
        return implode(', ', $parts) ?: 'Property';
    }

    protected function parseDateFromMessage(string $message): ?string
    {
        $messageLower = strtolower(trim($message));
        
        // Handle compound phrases like "tomorrow morning"
        if (str_contains($messageLower, 'tomorrow')) {
            return now()->addDay()->format('Y-m-d');
        }
        if (str_contains($messageLower, 'today')) {
            return now()->format('Y-m-d');
        }
        if ($messageLower === 'this weekend' || $messageLower === 'weekend' || str_contains($messageLower, 'saturday')) {
            return now()->next(\Carbon\Carbon::SATURDAY)->format('Y-m-d');
        }
        if (str_contains($messageLower, 'sunday')) {
            return now()->next(\Carbon\Carbon::SUNDAY)->format('Y-m-d');
        }
        if ($messageLower === 'this week' || $messageLower === 'week') {
            // Return next available weekday (tomorrow if weekday, else Monday)
            $next = now()->addDay();
            if ($next->isWeekend()) {
                $next = now()->next(\Carbon\Carbon::MONDAY);
            }
            return $next->format('Y-m-d');
        }
        if ($messageLower === 'next week') {
            return now()->addWeek()->startOfWeek()->format('Y-m-d');
        }
        if (str_contains($messageLower, 'next available') || str_contains($messageLower, 'asap') || str_contains($messageLower, 'soon')) {
            return now()->addDay()->format('Y-m-d');
        }
        
        // Try ISO format: YYYY-MM-DD
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $message, $matches)) {
            return $matches[1];
        }
        
        // Try US format: MM/DD/YYYY
        if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $message, $matches)) {
            try {
                return \Carbon\Carbon::createFromFormat('m/d/Y', $matches[1])->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        }
        
        // Try parsing natural language dates like "January 15" or "Jan 15th"
        try {
            $parsed = \Carbon\Carbon::parse($message);
            if ($parsed->isFuture() || $parsed->isToday()) {
                return $parsed->format('Y-m-d');
            }
        } catch (\Exception $e) {
            // Not parseable
        }
        
        return null;
    }

    /**
     * Extract time preference from message like "tomorrow morning"
     */
    protected function parseTimeFromMessage(string $message): ?string
    {
        $messageLower = strtolower($message);
        
        if (str_contains($messageLower, 'morning')) {
            return 'Morning';
        }
        if (str_contains($messageLower, 'afternoon')) {
            return 'Afternoon';
        }
        if (str_contains($messageLower, 'evening') || str_contains($messageLower, 'golden hour')) {
            return 'Golden hour';
        }
        
        return null;
    }
}

