<?php

namespace App\Services\ReproAi\Flows;

use App\Models\AiChatSession;
use App\Models\Shoot;
use App\Models\Service;
use App\Services\ReproAi\ShootService;
use App\Services\ReproAi\Tools\PaymentTools;
use Illuminate\Support\Facades\Schema;

class BookShootFlow
{
    protected ShootService $shootService;
    protected PaymentTools $paymentTools;

    public function __construct(?ShootService $shootService = null, ?PaymentTools $paymentTools = null)
    {
        $this->shootService = $shootService ?? app(ShootService::class);
        $this->paymentTools = $paymentTools ?? app(PaymentTools::class);
    }

    /**
     * Safely set step and state_data only if columns exist
     */
    protected function setStepAndData(AiChatSession $session, ?string $step = null, ?array $data = null): void
    {
        if ($step !== null && Schema::hasColumn('ai_chat_sessions', 'step')) {
            $session->step = $step;
        }
        if ($data !== null && Schema::hasColumn('ai_chat_sessions', 'state_data')) {
            $session->state_data = $data;
        }
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
        $step = $session->step ?? 'ask_property';
        $data = $session->state_data ?? [];

        return match ($step) {
            'ask_property'   => $this->askProperty($session, $message, $context, $data),
            'ask_date'       => $this->askDate($session, $message, $context, $data),
            'ask_time'       => $this->askTime($session, $message, $context, $data),
            'ask_services'   => $this->askServices($session, $message, $context, $data),
            'confirm'        => $this->confirm($session, $message, $context, $data),
            'done'           => $this->done($session, $data),
            default          => $this->reset($session),
        };
    }

    protected function reset(AiChatSession $session): array
    {
        if (Schema::hasColumn('ai_chat_sessions', 'step')) {
            $session->step = 'ask_property';
        }
        if (Schema::hasColumn('ai_chat_sessions', 'state_data')) {
            $session->state_data = [];
        }
        $session->save();

        $suggestions = $this->recentPropertySuggestions($session->user_id);
        if (empty($suggestions)) {
            $suggestions = ['Enter new address'];
        }

        return [
            'assistant_messages' => [[
                'content' => "Great! Let's book a new shoot. Which property is this for?",
                'metadata' => ['step' => 'ask_property'],
            ]],
            'suggestions' => $suggestions,
        ];
    }

    protected function askProperty(AiChatSession $session, string $message, array $context, array $data): array
    {
        // if UI sends property info in context (button click)
        if (!empty($context['propertyAddress']) || !empty($context['property_id'])) {
            $data['property_address'] = $context['propertyAddress'] ?? null;
            $data['property_city'] = $context['propertyCity'] ?? null;
            $data['property_state'] = $context['propertyState'] ?? null;
            $data['property_zip'] = $context['propertyZip'] ?? null;
            
            if (!empty($data['property_address']) && !empty($data['property_city'])) {
                $data['property_label'] = $this->formatPropertyLabel($data);
                $this->setStepAndData($session, 'ask_date', $data);
                $session->save();

                return [
                    'assistant_messages' => [[
                        'content'  => "Great, we'll shoot **{$data['property_label']}**.\n\nWhat date works best?",
                        'metadata' => ['step' => 'ask_date'],
                    ]],
                    'suggestions' => [
                        'Tomorrow morning',
                        'This weekend',
                        'Next available slot',
                    ],
                ];
            }
        }

        // first time we enter this step (no property yet)
        if (empty($data['property_address'])) {
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
            
            $this->setStepAndData($session, 'ask_date', $data);
            $session->save();

            return [
                'assistant_messages' => [[
                    'content'  => "Got it. When would you like the shoot for **{$data['property_label']}**?",
                    'metadata' => ['step' => 'ask_date'],
                ]],
                'suggestions' => [
                    'Tomorrow',
                    'This week',
                    'Next week',
                ],
            ];
        }

        // If property already set but user is changing it
        $this->setStepAndData($session, 'ask_property');
        $session->save();

        return [
            'assistant_messages' => [[
                'content'  => "Sure, let's book a new shoot. Which property is this for?",
                'metadata' => ['step' => 'ask_property'],
            ]],
            'suggestions' => $this->recentPropertySuggestions($session->user_id),
        ];
    }

    protected function askDate(AiChatSession $session, string $message, array $context, array $data): array
    {
        // For now, super simple: treat whatever they send as label & date string
        if (!empty(trim($message))) {
            $data['date_label'] = $message;
            $data['date'] = $this->parseDateFromMessage($message) ?? $message; // Try to parse, fallback to literal

            $this->setStepAndData($session, 'ask_time', $data);
            $session->save();

            return [
                'assistant_messages' => [[
                    'content'  => "What time of day works best?",
                    'metadata' => ['step' => 'ask_time'],
                ]],
                'suggestions' => [
                    'Morning',
                    'Afternoon',
                    'Golden hour',
                ],
            ];
        }

        // re-ask if empty
        return [
            'assistant_messages' => [[
                'content'  => "I didn't catch the date. What date should we book?",
                'metadata' => ['step' => 'ask_date'],
            ]],
            'suggestions' => [
                'Tomorrow',
                'This week',
                'Next week',
            ],
        ];
    }

    protected function askTime(AiChatSession $session, string $message, array $context, array $data): array
    {
        if (!empty(trim($message))) {
            $data['time_label'] = $message;
            $data['time_window'] = $message;

            $this->setStepAndData($session, 'ask_services', $data);
            $session->save();

            return [
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
            ];
        }

        return [
            'assistant_messages' => [[
                'content'  => "What time of day should we aim for?",
                'metadata' => ['step' => 'ask_time'],
            ]],
            'suggestions' => [
                'Morning',
                'Afternoon',
                'Golden hour',
            ],
        ];
    }

    protected function askServices(AiChatSession $session, string $message, array $context, array $data): array
    {
        if (!empty(trim($message))) {
            $data['services_label'] = $message;
            // Map label → internal service IDs
            $data['service_ids'] = $this->inferServiceIdsFromText($message);

            $this->setStepAndData($session, 'confirm', $data);
            $session->save();

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

            return [
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
            ];
        }

        return [
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
        ];
    }

    protected function confirm(AiChatSession $session, string $message, array $context, array $data): array
    {
        $m = strtolower($message);
        if (str_contains($m, 'change date') || (str_contains($m, 'change') && str_contains($m, 'date'))) {
            $this->setStepAndData($session, 'ask_date', $data);
            $session->save();

            return [
                'assistant_messages' => [[
                    'content'  => "No problem. What date works better?",
                    'metadata' => ['step' => 'ask_date'],
                ]],
                'suggestions' => [
                    'Tomorrow',
                    'This week',
                    'Next week',
                ],
            ];
        }

        if (str_contains($m, 'change') && (str_contains($m, 'service') || str_contains($m, 'services'))) {
            $this->setStepAndData($session, 'ask_services', $data);
            $session->save();

            return [
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
            ];
        }
        
        if (str_contains($m, 'change') && (str_contains($m, 'property') || str_contains($m, 'address'))) {
            $this->setStepAndData($session, 'ask_property', $data);
            $session->save();

            return [
                'assistant_messages' => [[
                    'content'  => "Sure, which property should we use instead?",
                    'metadata' => ['step' => 'ask_property'],
                ]],
                'suggestions' => $this->recentPropertySuggestions($session->user_id),
            ];
        }

        // Check for confirmation
        $isConfirmed = str_contains($m, 'yes') || 
                       str_contains($m, 'confirm') || 
                       str_contains($m, 'book') ||
                       str_contains($m, 'proceed');
        
        if (!$isConfirmed) {
            // user is unsure; gently re-ask with better suggestions
            return [
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
            ];
        }

        // We "book" the shoot using your existing service
        try {
            $booking = $this->shootService->createFromReproAi($session->user_id, $data);

            $this->setStepAndData($session, 'done', array_merge($data, ['shoot_id' => $booking->id]));
            $session->save();

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

            return [
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
            ];
        } catch (\Exception $e) {
            return [
                'assistant_messages' => [[
                    'content'  => "I encountered an error: " . $e->getMessage() . ". Would you like to try again?",
                    'metadata' => ['step' => 'confirm', 'error' => $e->getMessage()],
                ]],
                'suggestions' => ['Try again', 'Start over'],
            ];
        }
    }

    protected function done(AiChatSession $session, array $data): array
    {
        return [
            'assistant_messages' => [[
                'content'  => "Anything else you want to do with your bookings?",
                'metadata' => ['step' => 'done'],
            ]],
            'suggestions' => [
                'Book another shoot',
                'Manage an existing booking',
                'Check photographer availability',
            ],
        ];
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

        $services = Service::all(['id', 'name']);
        foreach ($services as $service) {
            $serviceName = strtolower($service->name);
            if (str_contains($t, $serviceName) || str_contains($serviceName, $t)) {
                $serviceIds[] = $service->id;
            }
        }

        // Fallback: if no matches, try common keywords
        if (empty($serviceIds)) {
            if (str_contains($t, 'photo')) {
                $photoService = Service::where('name', 'like', '%photo%')->first();
                if ($photoService) $serviceIds[] = $photoService->id;
            }
            if (str_contains($t, 'video')) {
                $videoService = Service::where('name', 'like', '%video%')->first();
                if ($videoService) $serviceIds[] = $videoService->id;
            }
            if (str_contains($t, 'drone')) {
                $droneService = Service::where('name', 'like', '%drone%')->first();
                if ($droneService) $serviceIds[] = $droneService->id;
            }
        }

        return $serviceIds ?: [Service::first()?->id ?? 1]; // Default to first service if none found
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
        $messageLower = strtolower($message);
        
        if ($messageLower === 'tomorrow') {
            return now()->addDay()->format('Y-m-d');
        } elseif ($messageLower === 'this weekend' || $messageLower === 'weekend') {
            $nextSaturday = now()->next(6);
            return $nextSaturday->format('Y-m-d');
        } elseif ($messageLower === 'next week') {
            return now()->addWeek()->format('Y-m-d');
        } elseif (preg_match('/(\d{4}-\d{2}-\d{2})/', $message, $matches)) {
            return $matches[1];
        } elseif (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $message, $matches)) {
            try {
                return \Carbon\Carbon::createFromFormat('m/d/Y', $matches[1])->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        }
        
        return null;
    }
}

