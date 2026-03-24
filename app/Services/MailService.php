<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\Shoot;
use App\Models\Payment;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateRenderer;

class MailService
{
    private const SHOOT_DELIVERED_SUBJECT = 'Your Photos Are Ready';
    private const SHOOT_CANCELLED_SUBJECT = 'Your Shoot Has Been Cancelled';
    private const SHOOT_PAID_SUBJECT = 'Payment Confirmed for Your Shoot';

    /**
     * Send account created email
     */
    public function sendAccountCreatedEmail(User $user, string $resetLink): bool
    {
        try {
            $html = view('emails.account_created', [
                'user' => $user,
                'resetLink' => $resetLink,
            ])->render();

            $this->sendViaCakemail($user->email, 'New Account Information', $html, 'ACCOUNT_CREATED');

            Log::info('Account created email sent', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send account created email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send shoot scheduled email
     */
    public function sendShootScheduledEmail(User $user, Shoot $shoot, string $paymentLink): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            
            // Determine whether the primary recipient is the assigned photographer.
            $isDirectPhotographer = $this->isPhotographerRecipient($user, $shoot);

            // Send to primary recipient
            $html = view('emails.shoot_scheduled', [
                'user' => $user,
                'shoot' => $shootData,
                'paymentLink' => $isDirectPhotographer ? '' : $paymentLink,
                'isPhotographer' => $isDirectPhotographer,
            ])->render();
            $this->sendViaCakemail($user->email, 'New Shoot Scheduled', $html, 'SHOOT_SCHEDULED');
            
            Log::info('Shoot scheduled email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'is_photographer' => $isDirectPhotographer,
            ]);

            // Also send to photographer if assigned
            if ($shoot->photographer && $shoot->photographer->email && $shoot->photographer->id !== $user->id) {
                $htmlPhoto = view('emails.shoot_scheduled', [
                    'user' => $shoot->photographer,
                    'shoot' => $shootData,
                    'paymentLink' => $paymentLink,
                    'isPhotographer' => true,
                ])->render();
                $this->sendViaCakemail($shoot->photographer->email, 'New Shoot Scheduled', $htmlPhoto, 'SHOOT_SCHEDULED');
                Log::info('Shoot scheduled email sent to photographer', [
                    'photographer_id' => $shoot->photographer->id,
                    'shoot_id' => $shoot->id,
                    'email' => $shoot->photographer->email
                ]);
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot scheduled email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send shoot updated email
     */
    public function sendShootUpdatedEmail(
        User $user,
        Shoot $shoot,
        ?string $changesSummary = null,
        ?bool $notifyClient = null,
        ?bool $notifyPhotographer = null
    ): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'service', 'services']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $normalizedChangesSummary = $this->normalizeChangeSummaryText($changesSummary);
            $shouldNotifyClient = $notifyClient !== false;
            $shouldNotifyPhotographer = $notifyPhotographer !== false;
            $isPrimaryRecipientPhotographer = $this->isPhotographerRecipient($user, $shoot);
            
            if ($shouldNotifyClient) {
                $html = view('emails.shoot_updated', [
                    'user' => $user,
                    'shoot' => $shootData,
                    'changesSummary' => $normalizedChangesSummary,
                    'isPhotographer' => $isPrimaryRecipientPhotographer,
                ])->render();
                $this->sendViaCakemail($user->email, 'Scheduled Photo Shoot Updated', $html, 'SHOOT_UPDATED');
                
                Log::info('Shoot updated email sent', [
                    'user_id' => $user->id,
                    'shoot_id' => $shoot->id,
                    'email' => $user->email
                ]);
            } else {
                Log::info('Shoot updated email skipped for client', [
                    'user_id' => $user->id,
                    'shoot_id' => $shoot->id,
                    'email' => $user->email
                ]);
            }

            // Also send to photographer if assigned
            if (
                $shouldNotifyPhotographer
                && $shoot->photographer
                && $shoot->photographer->email
                && $shoot->photographer->id !== $user->id
            ) {
                $htmlPhoto = view('emails.shoot_updated', [
                    'user' => $shoot->photographer,
                    'shoot' => $shootData,
                    'changesSummary' => $normalizedChangesSummary,
                    'isPhotographer' => true,
                ])->render();
                $this->sendViaCakemail($shoot->photographer->email, 'Scheduled Photo Shoot Updated', $htmlPhoto, 'SHOOT_UPDATED');
                Log::info('Shoot updated email sent to photographer', [
                    'photographer_id' => $shoot->photographer->id,
                    'shoot_id' => $shoot->id,
                    'email' => $shoot->photographer->email
                ]);
            } elseif ($shoot->photographer && $shoot->photographer->email && $shoot->photographer->id !== $user->id) {
                Log::info('Shoot updated email skipped for photographer', [
                    'photographer_id' => $shoot->photographer->id,
                    'shoot_id' => $shoot->id,
                    'email' => $shoot->photographer->email
                ]);
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot updated email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send shoot removed email
     */
    public function sendShootRemovedEmail(User $user, Shoot $shoot): bool
    {
        try {
            $shootData = $this->formatShootData($shoot);
            
            // Send to client
            $html = view('emails.shoot_removed', [
                'user' => $user,
                'shoot' => $shootData,
            ])->render();
            $this->sendViaCakemail($user->email, self::SHOOT_CANCELLED_SUBJECT, $html, 'SHOOT_REMOVED');
            
            Log::info('Shoot removed email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot removed email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send shoot ready email
     */
    public function sendShootReadyEmail(User $user, Shoot $shoot): bool
    {
        try {
            $shootData = $this->formatShootData($shoot);
            
            // Send to client
            $html = view('emails.shoot_delivered', [
                'user' => $user,
                'shoot' => $shootData,
            ])->render();
            $this->sendViaCakemail($user->email, self::SHOOT_DELIVERED_SUBJECT, $html, 'SHOOT_READY');
            
            Log::info('Shoot ready email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot ready email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send payment confirmation email
     */
    public function sendPaymentConfirmationEmail(User $user, Shoot $shoot, Payment $payment): bool
    {
        try {
            $shootData = $this->formatShootData($shoot);
            $paymentData = $this->formatPaymentData($payment);
            
            // Send to client
            $html = view('emails.payment_confirmation', [
                'user' => $user,
                'shoot' => $shootData,
                'payment' => $paymentData,
            ])->render();
            $this->sendViaCakemail($user->email, 'Thank You for Your Payment!', $html, 'PAYMENT_CONFIRMATION');
            
            Log::info('Payment confirmation email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'payment_id' => $payment->id,
                'email' => $user->email
            ]);

            // Also send to photographer if assigned
            if ($shoot->photographer && $shoot->photographer->email && $shoot->photographer->id !== $user->id) {
                $htmlPhoto = view('emails.payment_confirmation', [
                    'user' => $shoot->photographer,
                    'shoot' => $shootData,
                    'payment' => $paymentData,
                ])->render();
                $this->sendViaCakemail($shoot->photographer->email, 'Thank You for Your Payment!', $htmlPhoto, 'PAYMENT_CONFIRMATION');
                Log::info('Payment confirmation email sent to photographer', [
                    'photographer_id' => $shoot->photographer->id,
                    'shoot_id' => $shoot->id,
                    'email' => $shoot->photographer->email
                ]);
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send payment confirmation email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'payment_id' => $payment->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send terms accepted email
     */
    public function sendTermsAcceptedEmail(User $user): bool
    {
        try {
            $html = view('emails.terms_accepted', [
                'user' => $user,
            ])->render();
            $this->sendViaCakemail($user->email, 'Terms/Conditions Accepted', $html, 'TERMS_ACCEPTED');
            
            Log::info('Terms accepted email sent', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send terms accepted email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Capture shoot snapshot
     */
    public function captureShootSnapshot(Shoot $shoot): array
    {
        $shoot = $shoot->fresh(['client', 'photographer', 'service', 'services']) ?? $shoot;
        $shoot->loadMissing(['client', 'photographer', 'service', 'services']);

        $propertyDetails = $this->normalizePropertyDetails($shoot->property_details);

        return [
            'status' => $shoot->status,
            'workflow_status' => $shoot->workflow_status,
            'scheduled_at' => $shoot->scheduled_at?->toISOString(),
            'scheduled_date' => $shoot->scheduled_date?->toDateString(),
            'time' => $shoot->time,
            'location' => $this->formatFullAddress($shoot) ?: 'TBD',
            'client_name' => $shoot->client?->name,
            'photographer_name' => $shoot->photographer?->name,
            'base_quote' => (float) ($shoot->base_quote ?? 0),
            'tax_amount' => (float) ($shoot->tax_amount ?? 0),
            'total_quote' => (float) ($shoot->total_quote ?? 0),
            'shoot_notes' => $shoot->shoot_notes,
            'company_notes' => $shoot->company_notes,
            'photographer_notes' => $shoot->photographer_notes,
            'editor_notes' => $shoot->editor_notes,
            'is_private_listing' => (bool) ($shoot->is_private_listing ?? false),
            'services' => $this->formatServicesForComparison($shoot),
            'property_details' => [
                'bedrooms' => $propertyDetails['bedrooms'] ?? $propertyDetails['beds'] ?? null,
                'bathrooms' => $propertyDetails['bathrooms'] ?? $propertyDetails['baths'] ?? null,
                'sqft' => $propertyDetails['sqft'] ?? $propertyDetails['squareFeet'] ?? null,
                'presence_option' => $propertyDetails['presenceOption'] ?? null,
                'access_contact_name' => $propertyDetails['accessContactName'] ?? null,
                'access_contact_phone' => $propertyDetails['accessContactPhone'] ?? null,
                'lockbox_code' => $propertyDetails['lockboxCode'] ?? null,
                'lockbox_location' => $propertyDetails['lockboxLocation'] ?? null,
            ],
        ];
    }

    /**
     * Build shoot change summary
     */
    public function buildShootChangeSummary(array $before, Shoot $shoot): array
    {
        $shoot = $shoot->fresh(['client', 'photographer', 'service', 'services']) ?? $shoot;
        $shoot->loadMissing(['client', 'photographer', 'service', 'services']);

        $afterPropertyDetails = $this->normalizePropertyDetails($shoot->property_details);
        $changes = [];

        $this->addChangeLine(
            $changes,
            'Status',
            $this->formatStatusValue($before['status'] ?? null),
            $this->formatStatusValue($shoot->status)
        );

        $this->addChangeLine(
            $changes,
            'Workflow',
            $this->formatStatusValue($before['workflow_status'] ?? null),
            $this->formatStatusValue($shoot->workflow_status)
        );

        $this->addChangeLine(
            $changes,
            'Schedule',
            $this->formatScheduleValue(
                $before['scheduled_date'] ?? null,
                $before['time'] ?? null,
                $before['scheduled_at'] ?? null
            ),
            $this->formatScheduleValue(
                $shoot->scheduled_date?->toDateString(),
                $shoot->time,
                $shoot->scheduled_at?->toISOString()
            )
        );

        $this->addChangeLine(
            $changes,
            'Location',
            $before['location'] ?? 'TBD',
            $this->formatFullAddress($shoot) ?: 'TBD'
        );

        $this->addChangeLine(
            $changes,
            'Services',
            $this->formatServiceSummary($before['services'] ?? []),
            $this->formatServiceSummary($this->formatServicesForComparison($shoot))
        );

        $this->addChangeLine(
            $changes,
            'Client',
            $this->normalizeChangeText($before['client_name'] ?? null),
            $this->normalizeChangeText($shoot->client?->name)
        );

        $this->addChangeLine(
            $changes,
            'Photographer',
            $this->normalizeChangeText($before['photographer_name'] ?? null),
            $this->normalizeChangeText($shoot->photographer?->name)
        );

        $this->addChangeLine(
            $changes,
            'Base Quote',
            $this->formatCurrency($before['base_quote'] ?? 0),
            $this->formatCurrency($shoot->base_quote ?? 0)
        );

        $this->addChangeLine(
            $changes,
            'Tax',
            $this->formatCurrency($before['tax_amount'] ?? 0),
            $this->formatCurrency($shoot->tax_amount ?? 0)
        );

        $this->addChangeLine(
            $changes,
            'Total',
            $this->formatCurrency($before['total_quote'] ?? 0),
            $this->formatCurrency($shoot->total_quote ?? 0)
        );

        $this->addChangeLine(
            $changes,
            'Shoot Notes',
            $this->normalizeChangeText($before['shoot_notes'] ?? null),
            $this->normalizeChangeText($shoot->shoot_notes)
        );

        $this->addChangeLine(
            $changes,
            'Company Notes',
            $this->normalizeChangeText($before['company_notes'] ?? null),
            $this->normalizeChangeText($shoot->company_notes)
        );

        $this->addChangeLine(
            $changes,
            'Photographer Notes',
            $this->normalizeChangeText($before['photographer_notes'] ?? null),
            $this->normalizeChangeText($shoot->photographer_notes)
        );

        $this->addChangeLine(
            $changes,
            'Editor Notes',
            $this->normalizeChangeText($before['editor_notes'] ?? null),
            $this->normalizeChangeText($shoot->editor_notes)
        );

        $this->addChangeLine(
            $changes,
            'Bedrooms',
            $this->formatNumberValue($before['property_details']['bedrooms'] ?? null),
            $this->formatNumberValue($afterPropertyDetails['bedrooms'] ?? $afterPropertyDetails['beds'] ?? null)
        );

        $this->addChangeLine(
            $changes,
            'Bathrooms',
            $this->formatNumberValue($before['property_details']['bathrooms'] ?? null, 1),
            $this->formatNumberValue($afterPropertyDetails['bathrooms'] ?? $afterPropertyDetails['baths'] ?? null, 1)
        );

        $this->addChangeLine(
            $changes,
            'Square Footage',
            $this->formatSquareFootage($before['property_details']['sqft'] ?? null),
            $this->formatSquareFootage($afterPropertyDetails['sqft'] ?? $afterPropertyDetails['squareFeet'] ?? null)
        );

        $this->addChangeLine(
            $changes,
            'Access Type',
            $this->formatStatusValue($before['property_details']['presence_option'] ?? null),
            $this->formatStatusValue($afterPropertyDetails['presenceOption'] ?? null)
        );

        $this->addChangeLine(
            $changes,
            'Access Contact Name',
            $this->normalizeChangeText($before['property_details']['access_contact_name'] ?? null),
            $this->normalizeChangeText($afterPropertyDetails['accessContactName'] ?? null)
        );

        $this->addChangeLine(
            $changes,
            'Access Contact Phone',
            $this->normalizeChangeText($before['property_details']['access_contact_phone'] ?? null),
            $this->normalizeChangeText($afterPropertyDetails['accessContactPhone'] ?? null)
        );

        $this->addChangeLine(
            $changes,
            'Lockbox Code',
            $this->normalizeChangeText($before['property_details']['lockbox_code'] ?? null),
            $this->normalizeChangeText($afterPropertyDetails['lockboxCode'] ?? null)
        );

        $this->addChangeLine(
            $changes,
            'Lockbox Location',
            $this->normalizeChangeText($before['property_details']['lockbox_location'] ?? null),
            $this->normalizeChangeText($afterPropertyDetails['lockboxLocation'] ?? null)
        );

        $this->addChangeLine(
            $changes,
            'Private Listing',
            $this->formatBooleanValue($before['is_private_listing'] ?? false),
            $this->formatBooleanValue((bool) ($shoot->is_private_listing ?? false))
        );

        if (empty($changes)) {
            $changes[] = 'Please review updated details in the dashboard.';
        }

        return [
            'summary' => implode("\n", $changes),
            'html' => implode('<br>', array_map('e', $changes)),
            'lines' => $changes,
        ];
    }

    /**
     * Format shoot data for email templates
     */
    private function formatShootData(Shoot $shoot): object
    {
        $shoot->loadMissing(['client', 'photographer', 'rep', 'services.category']);

        $fullAddress = $this->formatFullAddress($shoot);
        $propertyDetails = $this->normalizePropertyDetails($shoot->property_details);

        $formattedTime = null;
        if ($shoot->time) {
            try {
                $formattedTime = \Carbon\Carbon::parse($shoot->time)->format('g:i A');
            } catch (\Exception $e) {
                $formattedTime = $shoot->time;
            }
        }

        $dateStr = 'TBD';
        if ($shoot->scheduled_date) {
            $dateStr = $shoot->scheduled_date->format('M j, Y');
            if ($formattedTime) {
                $dateStr .= ' at ' . $formattedTime;
            }
        }

        $notesText = $this->formatNotes($shoot);
        $serviceRows = $this->formatDetailedServices($shoot);
        $assignedPhotographers = $this->formatAssignedPhotographers($shoot, $serviceRows);

        return (object) [
            'id' => $shoot->id,
            'location' => $fullAddress ?: 'TBD',
            'date' => $dateStr,
            'time' => $formattedTime ?? 'TBD',
            'status' => $shoot->status,
            'status_label' => $this->formatStatusValue($shoot->status),
            'primary_photographer' => $shoot->photographer?->name,
            'photographer' => $shoot->photographer ? $shoot->photographer->name : 'TBD',
            'photographers' => $assignedPhotographers,
            'photographers_label' => !empty($assignedPhotographers) ? implode(', ', $assignedPhotographers) : 'TBD',
            'client_name' => $shoot->client ? $shoot->client->name : 'N/A',
            'client_email' => $shoot->client?->email,
            'client_phone' => $shoot->client?->phonenumber,
            'rep_name' => $shoot->rep?->name,
            'notes' => $notesText,
            'notes_lines' => $this->splitLines($notesText),
            'company_notes_lines' => $this->splitLines($shoot->company_notes),
            'photographer_notes_lines' => $this->splitLines($shoot->photographer_notes),
            'total' => $shoot->base_quote ?? 0,
            'tax' => $shoot->tax_amount ?? 0,
            'tax_rate' => $shoot->tax_percent ?? 0,
            'grand_total' => $shoot->total_quote ?? 0,
            'formatted_subtotal' => $this->formatCurrency($shoot->base_quote ?? 0),
            'formatted_tax' => $this->formatCurrency($shoot->tax_amount ?? 0),
            'formatted_grand_total' => $this->formatCurrency($shoot->total_quote ?? 0),
            'packages' => $this->formatPackages($shoot),
            'services' => $serviceRows,
            'service_category' => $shoot->service_category ?? 'Standard',
            'property_highlights' => $this->buildPropertyHighlightRows($propertyDetails),
            'access_details' => $this->buildAccessRows($propertyDetails),
            'company_notes' => $shoot->company_notes,
            'photographer_notes' => $shoot->photographer_notes,
            'dashboard_url' => 'https://reprodashboard.com',
            'website_url' => 'https://reprophotos.com',
            'property_prep_url' => 'https://reprophotos.com/tips-to-get-your-property-camera-ready/',
            'review_url' => 'https://www.google.com/maps/place/R%2FE+Pro+Photos/reviews',
            'support_email' => 'contact@reprophotos.com',
            'support_phone' => '202-868-1663',
            'is_private_listing' => (bool) ($shoot->is_private_listing ?? false),
        ];
    }

    /**
     * Format notes for email display - extract content only
     */
    private function formatNotes(Shoot $shoot): string
    {
        $noteContents = [];

        // Check shoot_notes field first
        if (!empty($shoot->shoot_notes)) {
            $noteContents[] = $shoot->shoot_notes;
        }

        // Check notes relationship
        if ($shoot->relationLoaded('notes') && $shoot->notes) {
            foreach ($shoot->notes as $note) {
                if (!empty($note->content) && $note->visibility === 'client_visible') {
                    $noteContents[] = $note->content;
                }
            }
        } elseif (!$shoot->relationLoaded('notes')) {
            // Load notes if not loaded
            $shoot->load('notes');
            if ($shoot->notes) {
                foreach ($shoot->notes as $note) {
                    if (!empty($note->content) && $note->visibility === 'client_visible') {
                        $noteContents[] = $note->content;
                    }
                }
            }
        }

        return !empty($noteContents) ? implode("\n", $noteContents) : '';
    }

    /**
     * Format payment data for email templates
     */
    private function formatPaymentData(Payment $payment): object
    {
        return (object) [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'currency' => $payment->currency ?? 'USD',
            'status' => $payment->status,
            'payment_method' => $payment->payment_method ?? 'Card',
            'transaction_id' => $payment->transaction_id,
            'created_at' => $payment->created_at->format('M j, Y g:i A')
        ];
    }

    /**
     * Format packages for email display
     */
    private function formatPackages(Shoot $shoot): array
    {
        $packages = [];
        
        // Load services relationship if not already loaded
        if (!$shoot->relationLoaded('services')) {
            $shoot->load('services');
        }
        
        // Get all services from the shoot (many-to-many relationship)
        if ($shoot->services && $shoot->services->count() > 0) {
            foreach ($shoot->services as $service) {
                $servicePrice = (float) ($service->pivot->price ?? $service->price ?? 0);
                $quantity = (int) ($service->pivot->quantity ?? 1);
                $serviceName = $service->name ?? $service->service_name ?? 'Service';
                
                $packages[] = [
                    'name' => $serviceName . ($quantity > 1 ? " x{$quantity}" : ''),
                    'price' => $servicePrice * $quantity
                ];
            }
        } elseif ($shoot->service) {
            // Fallback to single service relationship (legacy)
            $packages[] = [
                'name' => $shoot->service->name ?? 'Photography Service',
                'price' => $shoot->base_quote ?? 0
            ];
        } elseif ($shoot->service_category) {
            // Fallback to service category
            $categoryNames = [
                'P' => 'Photography Package',
                'iGuide' => 'iGuide Virtual Tour',
                'Video' => 'Video Package'
            ];
            
            $packages[] = [
                'name' => $categoryNames[$shoot->service_category] ?? $shoot->service_category,
                'price' => $shoot->base_quote ?? 0
            ];
        }
        
        // If still no packages, add a generic one based on quote
        if (empty($packages) && ($shoot->base_quote ?? 0) > 0) {
            $packages[] = [
                'name' => 'Photography Services',
                'price' => $shoot->base_quote
            ];
        }
        
        return $packages;
    }

    private function formatDetailedServices(Shoot $shoot): array
    {
        $shoot->loadMissing(['services.category']);

        $servicePhotographerIds = collect($shoot->services ?? [])
            ->pluck('pivot.photographer_id')
            ->filter()
            ->unique()
            ->values();

        $servicePhotographers = $servicePhotographerIds->isNotEmpty()
            ? User::whereIn('id', $servicePhotographerIds)->get()->keyBy('id')
            : collect();

        $rows = [];

        foreach ($shoot->services ?? [] as $service) {
            $quantity = (int) ($service->pivot->quantity ?? 1);
            $unitPrice = (float) ($service->pivot->price ?? $service->price ?? 0);
            $lineTotal = $unitPrice * $quantity;
            $resolvedPhotographerId = $service->pivot->photographer_id ?? $shoot->photographer_id;
            $resolvedPhotographer = null;

            if ($resolvedPhotographerId) {
                if ($service->pivot->photographer_id && $servicePhotographers->has($service->pivot->photographer_id)) {
                    $resolvedPhotographer = $servicePhotographers->get($service->pivot->photographer_id);
                } elseif ($shoot->photographer && (int) $shoot->photographer->id === (int) $resolvedPhotographerId) {
                    $resolvedPhotographer = $shoot->photographer;
                } elseif ($servicePhotographers->has($resolvedPhotographerId)) {
                    $resolvedPhotographer = $servicePhotographers->get($resolvedPhotographerId);
                }
            }

            $meta = [];
            if (!empty($service->category?->name)) {
                $meta[] = $service->category->name;
            }
            if ($quantity > 1) {
                $meta[] = 'Qty ' . $quantity;
            }
            if ($unitPrice > 0) {
                $meta[] = $this->formatCurrency($unitPrice) . ' each';
            }

            $serviceName = $service->name ?? $service->service_name ?? 'Service';

            $rows[] = [
                'name' => $serviceName,
                'display_name' => $serviceName . ($quantity > 1 ? " x{$quantity}" : ''),
                'quantity' => $quantity,
                'category' => $service->category?->name,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'formatted_total' => $this->formatCurrency($lineTotal),
                'photographer_name' => $resolvedPhotographer?->name,
                'meta' => implode(' | ', $meta),
            ];
        }

        if (empty($rows)) {
            foreach ($this->formatPackages($shoot) as $package) {
                $rows[] = [
                    'name' => $package['name'],
                    'display_name' => $package['name'],
                    'quantity' => 1,
                    'category' => null,
                    'unit_price' => (float) ($package['price'] ?? 0),
                    'line_total' => (float) ($package['price'] ?? 0),
                    'formatted_total' => $this->formatCurrency($package['price'] ?? 0),
                    'photographer_name' => $shoot->photographer?->name,
                    'meta' => '',
                ];
            }
        }

        return $rows;
    }

    private function formatAssignedPhotographers(Shoot $shoot, array $serviceRows): array
    {
        return collect($serviceRows)
            ->pluck('photographer_name')
            ->filter()
            ->prepend($shoot->photographer?->name)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function isPhotographerRecipient(User $user, Shoot $shoot): bool
    {
        $shoot->loadMissing(['photographer', 'services']);

        $photographerIds = collect([
            $shoot->photographer_id,
            $shoot->photographer?->id,
        ])
            ->merge(
                collect($shoot->services ?? [])
                    ->pluck('pivot.photographer_id')
                    ->filter()
            )
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return $photographerIds->contains((int) $user->id);
    }

    private function buildPropertyHighlightRows(array $propertyDetails): array
    {
        $rows = [];

        $beds = $propertyDetails['bedrooms'] ?? $propertyDetails['beds'] ?? null;
        $baths = $propertyDetails['bathrooms'] ?? $propertyDetails['baths'] ?? null;
        $sqft = $propertyDetails['sqft'] ?? $propertyDetails['squareFeet'] ?? null;

        if ($beds !== null && $beds !== '') {
            $rows[] = ['label' => 'Bedrooms', 'value' => $this->formatNumberValue($beds)];
        }

        if ($baths !== null && $baths !== '') {
            $rows[] = ['label' => 'Bathrooms', 'value' => $this->formatNumberValue($baths, 1)];
        }

        if ($sqft !== null && $sqft !== '') {
            $rows[] = ['label' => 'Square Footage', 'value' => number_format((float) $sqft)];
        }

        return $rows;
    }

    private function buildAccessRows(array $propertyDetails): array
    {
        $rows = [];
        $mappedRows = [
            'Access Type' => $propertyDetails['presenceOption'] ?? null,
            'Access Contact' => $propertyDetails['accessContactName'] ?? null,
            'Access Phone' => $propertyDetails['accessContactPhone'] ?? null,
            'Lockbox Code' => $propertyDetails['lockboxCode'] ?? null,
            'Lockbox Location' => $propertyDetails['lockboxLocation'] ?? null,
        ];

        foreach ($mappedRows as $label => $value) {
            if ($value !== null && trim((string) $value) !== '') {
                $rows[] = ['label' => $label, 'value' => (string) $value];
            }
        }

        return $rows;
    }

    private function splitLines(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', trim($value)))
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeChangeSummaryText(?string $value): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== ''
            ? $trimmed
            : 'Please review updated details in the dashboard.';
    }

    public function generatePaymentLink(Shoot $shoot): string
    {
        $frontendUrl = config('app.frontend_url', 'https://reprodashboard.com');
        return "{$frontendUrl}/payment/{$shoot->id}";
    }

    public function generateStoredPasswordResetLink(User $user): string
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        return $this->generatePasswordResetLink($user, $token);
    }

    /**
     * Generate password reset link with token
     */
    public function generatePasswordResetLink(User $user, ?string $token = null): string
    {
        $frontendUrl = config('app.frontend_url', 'https://reprodashboard.com');
        if (!$token) {
            $token = \Illuminate\Support\Str::random(64);
        }
        return "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($user->email);
    }

    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail(User $user, string $resetLink): bool
    {
        try {
            $html = view('emails.password_reset', [
                'user' => $user,
                'resetLink' => $resetLink,
            ])->render();

            $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));

            $messagingService = app(MessagingService::class);
            $messagingService->sendEmail([
                'to' => $user->email,
                'subject' => 'Reset Your Password - R/E Pro Photos',
                'body_html' => $html,
                'body_text' => $text,
                'send_source' => 'PASSWORD_RESET',
                'tags' => ['password_reset'],
                'sender_name' => 'R/E Pro Photos',
            ]);
            
            Log::info('Password reset email sent', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send password reset email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send weekly sales report email
     */
    public function sendWeeklySalesReportEmail(User $salesRep, array $reportData): bool
    {
        try {
            $period = $reportData['period'];
            $weekLabel = "Week {$period['week_number']}, {$period['year']}";

            $html = view('emails.weekly_sales_report', [
                'salesRep' => $salesRep,
                'report' => $reportData,
                'weekLabel' => $weekLabel,
            ])->render();
            $this->sendViaCakemail($salesRep->email, "Weekly Sales Report - {$weekLabel}", $html, 'WEEKLY_SALES_REPORT');
            
            Log::info('Weekly sales report email sent', [
                'sales_rep_id' => $salesRep->id,
                'email' => $salesRep->email,
                'period' => $reportData['period'] ?? null,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send weekly sales report email', [
                'sales_rep_id' => $salesRep->id,
                'email' => $salesRep->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send invoice generated email
     */
    public function sendInvoiceGeneratedEmail(\App\Models\Invoice $invoice): bool
    {
        try {
            $invoice->loadMissing(['photographer', 'salesRep', 'items']);

            $recipient = $invoice->photographer ?? $invoice->salesRep;
            if (!$recipient || empty($recipient->email)) {
                Log::warning('Cannot send invoice email: recipient not found', [
                    'invoice_id' => $invoice->id
                ]);
                return false;
            }

            $period = "{$invoice->billing_period_start->format('M j')} - {$invoice->billing_period_end->format('M j, Y')}";
            $recipientRole = $invoice->photographer ? 'photographer' : 'sales rep';
            $rendered = $this->renderWeeklyInvoiceGeneratedTemplate($invoice, $recipient, $recipientRole, $period);

            if ($rendered) {
                $this->sendViaCakemail(
                    $recipient->email,
                    $rendered['subject'] ?: "Weekly Invoice - {$period}",
                    $rendered['html'],
                    'INVOICE_GENERATED'
                );
            } else {
                Log::warning('Weekly invoice template not found in messaging templates, using Blade fallback', [
                    'invoice_id' => $invoice->id,
                ]);

                $html = view('emails.invoice_generated', [
                    'invoice' => $invoice,
                    'photographer' => $recipient,
                    'recipient' => $recipient,
                    'recipientRole' => $recipientRole,
                    'period' => $period,
                ])->render();

                $this->sendViaCakemail($recipient->email, "Weekly Invoice - {$period}", $html, 'INVOICE_GENERATED');
            }
            
            Log::info('Invoice generated email sent', [
                'invoice_id' => $invoice->id,
                'recipient_id' => $recipient->id,
                'email' => $recipient->email,
                'recipient_role' => $invoice->photographer ? 'photographer' : 'sales_rep',
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send invoice generated email', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send invoice pending approval email to admins
     */
    public function sendInvoicePendingApprovalEmail(\App\Models\Invoice $invoice): bool
    {
        try {
            $admins = User::whereIn('role', ['admin', 'superadmin'])->get();
            
            if ($admins->isEmpty()) {
                Log::warning('No admins found to send invoice approval email', [
                    'invoice_id' => $invoice->id
                ]);
                return false;
            }

            $photographer = $invoice->photographer;
            $period = "{$invoice->billing_period_start->format('M j')} - {$invoice->billing_period_end->format('M j, Y')}";
            $subject = "Invoice Requires Approval - " . ($photographer ? $photographer->name : 'Unknown') . " - {$period}";

            foreach ($admins as $admin) {
                $html = view('emails.invoice_pending_approval', [
                    'invoice' => $invoice,
                    'photographer' => $photographer,
                    'admin' => $admin,
                    'period' => $period,
                ])->render();
                $this->sendViaCakemail($admin->email, $subject, $html, 'INVOICE_PENDING_APPROVAL');
            }
            
            Log::info('Invoice pending approval emails sent', [
                'invoice_id' => $invoice->id,
                'admin_count' => $admins->count()
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send invoice pending approval emails', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send invoice approved email
     */
    public function sendInvoiceApprovedEmail(\App\Models\Invoice $invoice): bool
    {
        try {
            $photographer = $invoice->photographer;
            if (!$photographer) {
                Log::warning('Cannot send invoice approved email: photographer not found', [
                    'invoice_id' => $invoice->id
                ]);
                return false;
            }

            $period = "{$invoice->billing_period_start->format('M j')} - {$invoice->billing_period_end->format('M j, Y')}";

            $html = view('emails.invoice_approved', [
                'invoice' => $invoice,
                'photographer' => $photographer,
                'period' => $period,
            ])->render();
            $this->sendViaCakemail($photographer->email, "Invoice Approved - {$period}", $html, 'INVOICE_APPROVED');
            
            Log::info('Invoice approved email sent', [
                'invoice_id' => $invoice->id,
                'photographer_id' => $photographer->id,
                'email' => $photographer->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send invoice approved email', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send invoice rejected email
     */
    public function sendInvoiceRejectedEmail(\App\Models\Invoice $invoice): bool
    {
        try {
            $photographer = $invoice->photographer;
            if (!$photographer) {
                Log::warning('Cannot send invoice rejected email: photographer not found', [
                    'invoice_id' => $invoice->id
                ]);
                return false;
            }

            $period = "{$invoice->billing_period_start->format('M j')} - {$invoice->billing_period_end->format('M j, Y')}";

            $html = view('emails.invoice_rejected', [
                'invoice' => $invoice,
                'photographer' => $photographer,
                'period' => $period,
            ])->render();
            $this->sendViaCakemail($photographer->email, "Invoice Rejected - {$period}", $html, 'INVOICE_REJECTED');
            
            Log::info('Invoice rejected email sent', [
                'invoice_id' => $invoice->id,
                'photographer_id' => $photographer->id,
                'email' => $photographer->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send invoice rejected email', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send shoot paid email (when admin marks as paid)
     */
    public function sendShootPaidEmail(User $user, Shoot $shoot, float $amount): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            
            if (!empty($user->email)) {
                $html = view('emails.shoot_paid', [
                    'user' => $user,
                    'shoot' => $shootData,
                    'amount' => $amount,
                ])->render();
                $this->sendViaCakemail($user->email, self::SHOOT_PAID_SUBJECT, $html, 'SHOOT_PAID');
                
                Log::info('Shoot paid email sent', [
                    'user_id' => $user->id,
                    'shoot_id' => $shoot->id,
                    'email' => $user->email,
                    'amount' => $amount
                ]);
            } else {
                Log::warning('Shoot paid email skipped because recipient email is missing', [
                    'user_id' => $user->id,
                    'shoot_id' => $shoot->id,
                    'amount' => $amount,
                ]);
            }
            
            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send shoot paid email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email ?? null,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send shoot cancelled/deleted email
     */
    public function sendShootCancelledEmail(User $user, Shoot $shoot): bool
    {
        try {
            $shootData = $this->formatShootData($shoot);
            
            // Send to client
            $html = view('emails.shoot_removed', [
                'user' => $user,
                'shoot' => $shootData,
            ])->render();
            $this->sendViaCakemail($user->email, self::SHOOT_CANCELLED_SUBJECT, $html, 'SHOOT_CANCELLED');
            
            Log::info('Shoot cancelled email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot cancelled email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send cancellation fee invoice email to client
     */
    public function sendCancellationFeeInvoiceEmail(User $client, \App\Models\Invoice $invoice): bool
    {
        try {
            $shoot = $invoice->shoot;
            $address = $shoot?->address ?? 'Property';

            $html = view('emails.cancellation_fee_invoice', [
                'invoice' => $invoice,
                'client' => $client,
                'shoot' => $shoot,
                'address' => $address,
            ])->render();
            $this->sendViaCakemail($client->email, "Cancellation Fee Invoice - {$address}", $html, 'CANCELLATION_FEE_INVOICE');
            
            Log::info('Cancellation fee invoice email sent', [
                'client_id' => $client->id,
                'invoice_id' => $invoice->id,
                'email' => $client->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send cancellation fee invoice email', [
                'client_id' => $client->id,
                'invoice_id' => $invoice->id,
                'email' => $client->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send an email via CakeMail API through MessagingService
     */
    private function addChangeLine(array &$changes, string $label, ?string $before, ?string $after): void
    {
        $before = trim((string) $before);
        $after = trim((string) $after);
        if ($before === $after || ($before === '' && $after === '')) {
            return;
        }
        if ($before === '' && $after !== '') {
            $changes[] = "{$label}: {$after}";
        } elseif ($before !== '' && $after === '') {
            $changes[] = "{$label}: removed (was {$before})";
        } else {
            $changes[] = "{$label}: {$before} → {$after}";
        }
    }

    private function formatStatusValue(?string $value): string
    {
        if (!$value) return '';
        return ucwords(str_replace(['_', '-'], ' ', $value));
    }

    private function formatScheduleValue(?string $date, ?string $time, ?string $scheduledAt): string
    {
        $parts = [];
        if ($date) {
            try {
                $parts[] = \Carbon\Carbon::parse($date)->format('M j, Y');
            } catch (\Exception $e) {
                $parts[] = $date;
            }
        } elseif ($scheduledAt) {
            try {
                $parts[] = \Carbon\Carbon::parse($scheduledAt)->format('M j, Y');
            } catch (\Exception $e) {
                $parts[] = $scheduledAt;
            }
        }
        if ($time) {
            try {
                $parts[] = \Carbon\Carbon::parse($time)->format('g:i A');
            } catch (\Exception $e) {
                $parts[] = $time;
            }
        }
        return implode(' at ', $parts) ?: 'TBD';
    }


    private function formatFullAddress(Shoot $shoot): string
    {
        return trim(sprintf(
            '%s, %s, %s %s',
            $shoot->address ?? '',
            $shoot->city ?? '',
            $shoot->state ?? '',
            $shoot->zip ?? ''
        ), ', ');
    }

    private function formatServicesForComparison(Shoot $shoot): array
    {
        $shoot->loadMissing('services');
        if (!$shoot->services || $shoot->services->isEmpty()) {
            return [];
        }
        return $shoot->services->map(function ($s) {
            return [
                'id' => $s->id,
                'name' => $s->name ?? $s->service_name ?? 'Service',
                'price' => (float) ($s->pivot->price ?? $s->price ?? 0),
                'quantity' => (int) ($s->pivot->quantity ?? 1),
            ];
        })->sortBy('id')->values()->toArray();
    }

    private function formatServiceSummary(array $services): string
    {
        if (empty($services)) return 'None';
        return collect($services)->map(function ($s) {
            $name = $s['name'] ?? 'Service';
            $qty = $s['quantity'] ?? 1;
            $price = $s['price'] ?? 0;
            $line = $name;
            if ($qty > 1) $line .= " x{$qty}";
            if ($price > 0) $line .= ' ($' . number_format($price * $qty, 2) . ')';
            return $line;
        })->implode(', ');
    }

    private function normalizeChangeText(?string $value): string
    {
        if ($value === null || trim($value) === '') return '';
        return trim($value);
    }

    private function formatCurrency($value): string
    {
        $num = (float) ($value ?? 0);
        return '$' . number_format($num, 2);
    }

    private function formatNumberValue($value, int $decimals = 0): string
    {
        if ($value === null || $value === '') return '';
        return number_format((float) $value, $decimals);
    }

    private function formatSquareFootage($value): string
    {
        if ($value === null || $value === '') return '';
        return number_format((int) $value) . ' sqft';
    }

    private function formatBooleanValue(bool $value): string
    {
        return $value ? 'Yes' : 'No';
    }

    private function normalizePropertyDetails($pd): array
    {
        if (is_string($pd)) {
            $pd = json_decode($pd, true) ?? [];
        }
        return is_array($pd) ? $pd : [];
    }

    private function renderWeeklyInvoiceGeneratedTemplate(\App\Models\Invoice $invoice, $recipient, string $recipientRole, string $period): ?array
    {
        $template = MessageTemplate::query()
            ->where('slug', 'weekly-invoice-generated')
            ->where('channel', 'EMAIL')
            ->where('is_active', true)
            ->first();

        if (!$template) {
            return null;
        }

        return app(TemplateRenderer::class)->render($template, [
            'recipient_name' => $recipient->name ?? 'there',
            'recipient_role' => $recipientRole,
            'billing_period' => $period,
            'invoice_number' => $invoice->invoice_number ?: 'Pending assignment',
            'invoice_status' => Str::headline((string) ($invoice->status ?? 'draft')),
            'invoice_total' => $this->formatCurrency($invoice->total_amount ?? $invoice->total ?? 0),
            'invoice_items_html' => $this->buildInvoiceItemsHtml($invoice),
            'invoice_items_text' => $this->buildInvoiceItemsText($invoice),
            'dashboard_url' => 'https://reprodashboard.com',
            'invoice_next_step' => 'Open the dashboard to review the invoice, confirm line items, and add any missing expenses before approval moves forward.',
            'approval_note' => 'Changes made after generation may trigger a fresh approval review before payout is finalized.',
        ]);
    }

    private function buildInvoiceItemsHtml(\App\Models\Invoice $invoice): string
    {
        if (!$invoice->items || $invoice->items->isEmpty()) {
            return '<p style="margin: 0;">Line items will appear here once charges or expenses are attached to the invoice.</p>';
        }

        return $invoice->items->map(function ($item) {
            $type = e(Str::headline((string) ($item->type ?? 'line item')));
            $description = e((string) ($item->description ?? 'Line item'));
            $amount = e($this->formatCurrency($item->total_amount ?? 0));

            return <<<HTML
<div class="info-row">
    <span class="info-label">{$type}</span>
    {$description}
    <strong style="float: right;">{$amount}</strong>
</div>
HTML;
        })->implode("\n");
    }

    private function buildInvoiceItemsText(\App\Models\Invoice $invoice): string
    {
        if (!$invoice->items || $invoice->items->isEmpty()) {
            return '- No line items have been attached yet.';
        }

        return $invoice->items->map(function ($item) {
            $type = Str::headline((string) ($item->type ?? 'line item'));
            $description = trim((string) ($item->description ?? 'Line item'));
            $amount = $this->formatCurrency($item->total_amount ?? 0);

            return "- {$description} ({$type}): {$amount}";
        })->implode("\n");
    }

    private function sendViaCakemail(?string $to, string $subject, string $html, string $sendSource): void
    {
        if (!is_string($to) || trim($to) === '') {
            throw new \InvalidArgumentException('Recipient email is required to send mail.');
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        $messagingService = app(MessagingService::class);
        $messagingService->sendEmail([
            'to' => $to,
            'subject' => $subject,
            'body_html' => $html,
            'body_text' => $text,
            'send_source' => $sendSource,
            'sender_name' => 'R/E Pro Photos',
        ]);
    }
}
