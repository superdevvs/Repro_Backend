<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Carbon\CarbonInterface;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\Shoot;
use App\Models\Payment;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\ShootEmailMatrix;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Users\EmailHealthService;

class MailService
{
    private const SHOOT_DELIVERED_SUBJECT = 'Your Photos Are Ready';
    private const SHOOT_REMINDER_SUBJECT = 'Shoot Reminder: 24 Hours to Go';
    private const SHOOT_REMOVED_SUBJECT = 'Photo Shoot Removed from Schedule';
    private const SHOOT_REQUEST_DECLINED_SUBJECT = 'Your Shoot Request Was Declined';
    private const SHOOT_CANCELLED_SUBJECT = 'Your Shoot Has Been Cancelled';
    private const SHOOT_CANCELLATION_REQUESTED_SUBJECT = 'Shoot Cancellation Request Received';
    private const SHOOT_PAID_SUBJECT = 'Payment Confirmed for Your Shoot';
    private const PHOTOGRAPHER_CHANGED_SUBJECT = 'Photographer Assignment Updated';

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

            $this->sendViaCakemail($user->email, 'New Account Information', $html, 'ACCOUNT_CREATED', [], [], [
                'related_account_id' => $user->id,
            ]);

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

    public function generateClientEmailVerificationLink(User $user): string
    {
        $relativeSignedUrl = URL::temporarySignedRoute(
            'api.email-verification.verify',
            now()->addDays(7),
            [
                'user' => $user->id,
                'hash' => sha1(Str::lower((string) $user->email)),
            ],
            absolute: false,
        );

        return $this->buildAbsoluteApiUrl($relativeSignedUrl);
    }

    public function sendClientEmailVerificationEmail(User $user): bool
    {
        try {
            $verificationLink = $this->generateClientEmailVerificationLink($user);
            $html = view('emails.client_email_verification', [
                'user' => $user,
                'verificationLink' => $verificationLink,
                'dashboardUrl' => rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/'),
            ])->render();

            $this->sendViaCakemail(
                $user->email,
                'Verify Your Email Address',
                $html,
                'CLIENT_EMAIL_VERIFICATION',
                [],
                [],
                [
                    'related_account_id' => $user->id,
                ],
            );

            Log::info('Client email verification email sent', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::error('Failed to send client email verification email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function buildAbsoluteApiUrl(string $path): string
    {
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $apiBaseUrl = rtrim((string) config('app.url', 'https://api.reprodashboard.com'), '/');

        return $apiBaseUrl . '/' . ltrim($path, '/');
    }

    /**
     * Send shoot scheduled email
     */
    public function sendShootScheduledEmail(
        User $user,
        Shoot $shoot,
        string $paymentLink,
        ?bool $shouldNotifyPhotographer = true
    ): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
            
            // Determine whether the primary recipient is the assigned photographer.
            $isDirectPhotographer = $this->isPhotographerRecipient($user, $shoot);

            // Send to primary recipient
            $html = view('emails.shoot_scheduled', [
                'user' => $user,
                'shoot' => $shootData,
                'paymentLink' => $isDirectPhotographer ? '' : $paymentLink,
                'isPhotographer' => $isDirectPhotographer,
            ])->render();
            $this->sendViaCakemail($user->email, 'New Shoot Scheduled', $html, 'SHOOT_SCHEDULED', $clientCcEmails);
            
            Log::info('Shoot scheduled email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'is_photographer' => $isDirectPhotographer,
            ]);

            if (
                $shouldNotifyPhotographer !== false
                && $this->shouldSendAssignedPhotographerEmails($shoot, $user, ShootEmailMatrix::SHOOT_SCHEDULED)
            ) {
                foreach ($this->resolveAssignedPhotographers($shoot, $user->id) as $photographer) {
                    $htmlPhoto = view('emails.shoot_scheduled', [
                        'user' => $photographer,
                        'shoot' => $shootData,
                        'paymentLink' => '',
                        'isPhotographer' => true,
                    ])->render();
                    $this->sendViaCakemail($photographer->email, 'New Shoot Scheduled', $htmlPhoto, 'SHOOT_SCHEDULED');
                    Log::info('Shoot scheduled email sent to photographer', [
                        'photographer_id' => $photographer->id,
                        'shoot_id' => $shoot->id,
                        'email' => $photographer->email,
                    ]);
                }
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
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
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
                $this->sendViaCakemail($user->email, 'Scheduled Photo Shoot Updated', $html, 'SHOOT_UPDATED', $clientCcEmails);
                
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
                && $this->shouldSendAssignedPhotographerEmails($shoot, $user, ShootEmailMatrix::SHOOT_UPDATED)
            ) {
                foreach ($this->resolveAssignedPhotographers($shoot, $user->id) as $photographer) {
                    $htmlPhoto = view('emails.shoot_updated', [
                        'user' => $photographer,
                        'shoot' => $shootData,
                        'changesSummary' => $normalizedChangesSummary,
                        'isPhotographer' => true,
                    ])->render();
                    $this->sendViaCakemail($photographer->email, 'Scheduled Photo Shoot Updated', $htmlPhoto, 'SHOOT_UPDATED');
                    Log::info('Shoot updated email sent to photographer', [
                        'photographer_id' => $photographer->id,
                        'shoot_id' => $shoot->id,
                        'email' => $photographer->email,
                    ]);
                }
            } elseif ($this->shouldSendAssignedPhotographerEmails($shoot, $user, ShootEmailMatrix::SHOOT_UPDATED)) {
                Log::info('Shoot updated email skipped for photographer', [
                    'shoot_id' => $shoot->id,
                    'excluded_user_id' => $user->id,
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

    public function sendShootReminderEmail(
        User $user,
        Shoot $shoot,
        ?CarbonInterface $scheduledAt = null,
        array $tags = [],
        ?bool $shouldNotifyPhotographer = true
    ): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
            $isDirectPhotographer = $this->isPhotographerRecipient($user, $shoot);

            $html = view('emails.shoot_reminder', [
                'user' => $user,
                'shoot' => $shootData,
                'scheduledAt' => $scheduledAt,
                'isPhotographer' => $isDirectPhotographer,
            ])->render();

            $this->sendViaCakemail(
                $user->email,
                self::SHOOT_REMINDER_SUBJECT,
                $html,
                'SHOOT_REMINDER',
                $clientCcEmails,
                $tags
            );

            if (
                $shouldNotifyPhotographer !== false
                && $this->shouldSendAssignedPhotographerEmails($shoot, $user, ShootEmailMatrix::SHOOT_REMINDER)
            ) {
                foreach ($this->resolveAssignedPhotographers($shoot, $user->id) as $photographer) {
                    $htmlPhoto = view('emails.shoot_reminder', [
                        'user' => $photographer,
                        'shoot' => $shootData,
                        'scheduledAt' => $scheduledAt,
                        'isPhotographer' => true,
                    ])->render();

                    $this->sendViaCakemail(
                        $photographer->email,
                        self::SHOOT_REMINDER_SUBJECT,
                        $htmlPhoto,
                        'SHOOT_REMINDER',
                        [],
                        $tags
                    );
                }
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot reminder email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
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
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
            
            // Send to client
            $html = view('emails.shoot_removed', [
                'user' => $user,
                'shoot' => $shootData,
            ])->render();
            $this->sendViaCakemail($user->email, self::SHOOT_REMOVED_SUBJECT, $html, 'SHOOT_REMOVED', $clientCcEmails);
            
            Log::info('Shoot removed email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email
            ]);

            if ($this->shouldSendAssignedPhotographerEmails($shoot, $user, ShootEmailMatrix::SHOOT_REMOVED)) {
                foreach ($this->resolveAssignedPhotographers($shoot, $user->id) as $photographer) {
                    $htmlPhoto = view('emails.shoot_removed', [
                        'user' => $photographer,
                        'shoot' => $shootData,
                    ])->render();
                    $this->sendViaCakemail($photographer->email, self::SHOOT_REMOVED_SUBJECT, $htmlPhoto, 'SHOOT_REMOVED');
                    Log::info('Shoot removed email sent to photographer', [
                        'photographer_id' => $photographer->id,
                        'shoot_id' => $shoot->id,
                        'email' => $photographer->email,
                    ]);
                }
            }
            
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

    public function sendShootRequestDeclinedEmail(User $user, Shoot $shoot): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);

            $html = view('emails.shoot_request_declined', [
                'user' => $user,
                'shoot' => $shootData,
                'declineReason' => trim((string) ($shoot->declined_reason ?? '')),
            ])->render();

            $this->sendViaCakemail(
                $user->email,
                self::SHOOT_REQUEST_DECLINED_SUBJECT,
                $html,
                'SHOOT_REQUEST_DECLINED'
            );

            Log::info('Shoot request declined email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot request declined email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send shoot cancellation requested email
     */
    public function sendShootCancellationRequestedEmail(User $user, Shoot $shoot): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
            $isPhotographer = $this->isPhotographerRecipient($user, $shoot);

            $html = view('emails.shoot_cancellation_requested', [
                'user' => $user,
                'shoot' => $shootData,
                'isPhotographer' => $isPhotographer,
                'cancellationReason' => $shoot->cancellation_reason,
            ])->render();
            $this->sendViaCakemail(
                $user->email,
                self::SHOOT_CANCELLATION_REQUESTED_SUBJECT,
                $html,
                'SHOOT_CANCELLATION_REQUESTED',
                $clientCcEmails
            );

            Log::info('Shoot cancellation requested email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'is_photographer' => $isPhotographer,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot cancellation requested email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
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
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category', 'payments']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
            $paymentLink = $this->shouldShowShootReadyPaymentLink($shoot)
                ? $this->generatePaymentLink($shoot)
                : null;
            
            // Send to client
            $html = view('emails.shoot_delivered', [
                'user' => $user,
                'shoot' => $shootData,
                'paymentLink' => $paymentLink,
            ])->render();
            $this->sendViaCakemail($user->email, self::SHOOT_DELIVERED_SUBJECT, $html, 'SHOOT_DELIVERED', $clientCcEmails);
            
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
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
            
            // Send to client
            $html = view('emails.payment_confirmation', [
                'user' => $user,
                'shoot' => $shootData,
                'payment' => $paymentData,
            ])->render();
            $this->sendViaCakemail($user->email, 'Thank You for Your Payment!', $html, 'PAYMENT_CONFIRMATION', $clientCcEmails);
            
            Log::info('Payment confirmation email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'payment_id' => $payment->id,
                'email' => $user->email
            ]);
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
            'html' => $this->buildChangeSummaryHtml($changes),
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
        $paymentStatus = $this->resolveShootPaymentStatus($shoot);
        $totalPaid = $shoot->relationLoaded('payments')
            ? $shoot->calculateCanonicalTotalPaid()
            : (float) ($shoot->total_paid ?? 0);
        $remainingBalance = max((float) ($shoot->total_quote ?? 0) - $totalPaid, 0);

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
            'payment_status' => $paymentStatus,
            'remaining_balance' => $remainingBalance,
            'formatted_remaining_balance' => $this->formatCurrency($remainingBalance),
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
            'bypass_paywall' => (bool) ($shoot->bypass_paywall ?? false),
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
        return $this->resolveAssignedPhotographers($shoot)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->contains((int) $user->id);
    }

    private function shouldSendAssignedPhotographerEmails(Shoot $shoot, User $primaryRecipient, string $event): bool
    {
        return ShootEmailMatrix::includesPhotographer($event)
            && !$this->isPhotographerRecipient($primaryRecipient, $shoot);
    }

    private function resolveAssignedPhotographers(Shoot $shoot, ?int $excludeUserId = null): Collection
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

        if ($photographerIds->isEmpty()) {
            return collect();
        }

        $photographers = User::query()
            ->whereIn('id', $photographerIds->all())
            ->whereNotNull('email')
            ->get()
            ->keyBy('id');

        if ($shoot->photographer && $shoot->photographer->email) {
            $photographers->put($shoot->photographer->id, $shoot->photographer);
        }

        return $photographerIds
            ->map(fn ($id) => $photographers->get((int) $id))
            ->filter(fn ($user) => $user instanceof User)
            ->reject(fn (User $user) => $excludeUserId !== null && (int) $user->id === $excludeUserId)
            ->unique('id')
            ->values();
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

    private function resolveShootPaymentStatus(Shoot $shoot): string
    {
        $paymentStatus = Str::lower(trim((string) ($shoot->payment_status ?? '')));

        if (in_array($paymentStatus, ['paid', 'unpaid', 'partial'], true)) {
            return $paymentStatus;
        }

        if (!$shoot->relationLoaded('payments')) {
            $shoot->loadMissing('payments');
        }

        $totalPaid = $shoot->calculateCanonicalTotalPaid();
        $totalQuote = (float) ($shoot->total_quote ?? 0);

        if ($totalPaid <= 0) {
            return 'unpaid';
        }

        return $totalPaid >= $totalQuote ? 'paid' : 'partial';
    }

    private function shouldShowShootReadyPaymentLink(Shoot $shoot): bool
    {
        if ((bool) ($shoot->bypass_paywall ?? false)) {
            return false;
        }

        return in_array($this->resolveShootPaymentStatus($shoot), ['unpaid', 'partial'], true);
    }

    public function generatePaymentLink(Shoot $shoot): string
    {
        return app(\App\Services\Payments\PublicPaymentAccessTokenService::class)
            ->buildPublicUrl($shoot);
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

            $this->sendViaCakemail($user->email, 'Reset Your Password - R/E Pro Photos', $html, 'PASSWORD_RESET');
            
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

            if (!$recipient) {
                Log::warning('Cannot send invoice pending approval email: payee not found', [
                    'invoice_id' => $invoice->id,
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

            $recipient = $this->resolveInvoicePayee($invoice);
            $roleLabel = $this->resolveInvoicePayeeLabel($invoice);
            $roleHeading = $this->resolveInvoicePayeeHeading($invoice);
            $period = "{$invoice->billing_period_start->format('M j')} - {$invoice->billing_period_end->format('M j, Y')}";
            $subject = "Invoice Requires Approval - " . ($recipient ? $recipient->name : 'Unknown') . " - {$period}";

            foreach ($admins as $admin) {
                $html = view('emails.invoice_pending_approval', [
                    'invoice' => $invoice,
                    'recipient' => $recipient,
                    'admin' => $admin,
                    'period' => $period,
                    'roleLabel' => $roleLabel,
                    'roleHeading' => $roleHeading,
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
            $recipient = $this->resolveInvoicePayee($invoice);
            $roleLabel = $this->resolveInvoicePayeeLabel($invoice);
            if (!$recipient) {
                Log::warning('Cannot send invoice approved email: payee not found', [
                    'invoice_id' => $invoice->id
                ]);
                return false;
            }

            $period = "{$invoice->billing_period_start->format('M j')} - {$invoice->billing_period_end->format('M j, Y')}";

            $html = view('emails.invoice_approved', [
                'invoice' => $invoice,
                'period' => $period,
                'roleLabel' => $roleLabel,
            ])->render();
            $this->sendViaCakemail($recipient->email, "Invoice Approved - {$period}", $html, 'INVOICE_APPROVED');
            
            Log::info('Invoice approved email sent', [
                'invoice_id' => $invoice->id,
                'recipient_id' => $recipient->id,
                'email' => $recipient->email
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
            $recipient = $this->resolveInvoicePayee($invoice);
            $roleLabel = $this->resolveInvoicePayeeLabel($invoice);
            if (!$recipient) {
                Log::warning('Cannot send invoice rejected email: payee not found', [
                    'invoice_id' => $invoice->id
                ]);
                return false;
            }

            $period = "{$invoice->billing_period_start->format('M j')} - {$invoice->billing_period_end->format('M j, Y')}";

            $html = view('emails.invoice_rejected', [
                'invoice' => $invoice,
                'period' => $period,
                'roleLabel' => $roleLabel,
            ])->render();
            $this->sendViaCakemail($recipient->email, "Invoice Rejected - {$period}", $html, 'INVOICE_REJECTED');
            
            Log::info('Invoice rejected email sent', [
                'invoice_id' => $invoice->id,
                'recipient_id' => $recipient->id,
                'email' => $recipient->email
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

    private function resolveInvoicePayee(\App\Models\Invoice $invoice): ?User
    {
        return $invoice->photographer ?: $invoice->salesRep;
    }

    private function resolveInvoicePayeeLabel(\App\Models\Invoice $invoice): string
    {
        return $invoice->sales_rep_id ? 'sales rep' : 'photographer';
    }

    private function resolveInvoicePayeeHeading(\App\Models\Invoice $invoice): string
    {
        return $invoice->sales_rep_id ? 'Sales Rep' : 'Photographer';
    }

    /**
     * Send shoot paid email (when admin marks as paid)
     */
    public function sendShootPaidEmail(User $user, Shoot $shoot, float $amount): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
            
            if (!empty($user->email)) {
                $html = view('emails.shoot_paid', [
                    'user' => $user,
                    'shoot' => $shootData,
                    'amount' => $amount,
                ])->render();
                $this->sendViaCakemail($user->email, self::SHOOT_PAID_SUBJECT, $html, 'SHOOT_PAID', $clientCcEmails);
                
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
     * Send shoot cancelled email
     */
    public function sendShootCancelledEmail(User $user, Shoot $shoot): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);

            $html = view('emails.shoot_removed', [
                'user' => $user,
                'shoot' => $shootData,
            ])->render();
            $this->sendViaCakemail($user->email, self::SHOOT_CANCELLED_SUBJECT, $html, 'SHOOT_CANCELLED', $clientCcEmails);

            Log::info('Shoot cancelled email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
            ]);

            if ($this->shouldSendAssignedPhotographerEmails($shoot, $user, ShootEmailMatrix::SHOOT_CANCELLED)) {
                foreach ($this->resolveAssignedPhotographers($shoot, $user->id) as $photographer) {
                    $htmlPhoto = view('emails.shoot_removed', [
                        'user' => $photographer,
                        'shoot' => $shootData,
                    ])->render();
                    $this->sendViaCakemail($photographer->email, self::SHOOT_CANCELLED_SUBJECT, $htmlPhoto, 'SHOOT_CANCELLED');
                    Log::info('Shoot cancelled email sent to photographer', [
                        'photographer_id' => $photographer->id,
                        'shoot_id' => $shoot->id,
                        'email' => $photographer->email,
                    ]);
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send shoot cancelled email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendPhotographerChangedEmail(
        User $user,
        Shoot $shoot,
        ?User $previousPhotographer = null,
        ?string $changesSummary = null
    ): bool {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $normalizedChangesSummary = $this->normalizeChangeSummaryText($changesSummary);
            $isAssignedAfterChange = $this->resolveAssignedPhotographers($shoot)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->contains((int) $user->id);

            $html = view('emails.photographer_changed', [
                'user' => $user,
                'shoot' => $shootData,
                'changesSummary' => $normalizedChangesSummary,
                'previousPhotographer' => $previousPhotographer,
                'isAssignedAfterChange' => $isAssignedAfterChange,
            ])->render();

            $this->sendViaCakemail($user->email, self::PHOTOGRAPHER_CHANGED_SUBJECT, $html, 'PHOTOGRAPHER_CHANGED');

            Log::info('Photographer changed email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'is_assigned_after_change' => $isAssignedAfterChange,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send photographer changed email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email ?? null,
                'error' => $e->getMessage(),
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
            $address = $shoot ? ($this->formatFullAddress($shoot) ?: ($shoot->address ?? 'Property')) : 'Property';
            $clientCcEmails = $shoot ? $this->resolveShootCcEmailsForRecipient($shoot, $client) : $this->sanitizeEmailAddresses($client->shoot_cc_emails ?? [], $client->email);

            $html = view('emails.cancellation_fee_invoice', [
                'invoice' => $invoice,
                'client' => $client,
                'shoot' => $shoot,
                'address' => $address,
            ])->render();
            $this->sendViaCakemail($client->email, "Cancellation Fee Invoice - {$address}", $html, 'CANCELLATION_FEE_INVOICE', $clientCcEmails);
            
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

    /**
     * @param  array<int, string>  $changes
     */
    private function buildChangeSummaryHtml(array $changes): string
    {
        $filteredChanges = array_values(array_filter(array_map(
            fn ($line) => trim((string) $line),
            $changes
        ), fn ($line) => $line !== ''));

        if ($filteredChanges === []) {
            return '<p>Please review updated details in the dashboard.</p>';
        }

        $parsedChanges = array_map(
            fn (string $line) => $this->parseChangeSummaryLine($line),
            $filteredChanges
        );

        $comparisonChanges = array_values(array_filter(
            $parsedChanges,
            fn (array $change) => ($change['type'] ?? '') === 'comparison'
        ));
        $singleChanges = array_values(array_filter(
            $parsedChanges,
            fn (array $change) => ($change['type'] ?? '') === 'single'
        ));
        $textChanges = array_values(array_filter(
            $parsedChanges,
            fn (array $change) => ($change['type'] ?? '') === 'text'
        ));

        $html = '';

        if ($comparisonChanges !== [] || $singleChanges !== []) {
            $html .= '<div class="change-summary-block" style="margin:0 0 12px; padding:16px 18px; border:1px solid #dbe6f3; border-radius:14px; background-color:#f8fbff;">';

            if ($comparisonChanges !== []) {
                $html .= '<div style="margin:0 0 4px; font-size:11px; line-height:1.4; letter-spacing:1.2px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Before</div>';

                foreach ($comparisonChanges as $change) {
                    $label = e((string) ($change['label'] ?? 'Updated Detail'));
                    $beforeHtml = $this->buildChangeSummaryBeforeHtml(
                        (string) ($change['label'] ?? ''),
                        (string) ($change['before'] ?? ''),
                        (string) ($change['after'] ?? '')
                    );

                    $html .= <<<HTML
<div style="margin:0 0 10px;">
    <div style="margin:0 0 3px; font-size:13px; line-height:1.5; color:#6c84a2; font-weight:700;">{$label}</div>
    <div style="margin:0; font-size:14px; line-height:1.7; color:#2d4769;">{$beforeHtml}</div>
</div>
HTML;
                }

                $html .= '<div style="margin:12px 0; height:1px; background-color:#e7eef7; font-size:0; line-height:0;">&nbsp;</div>';
            }

            $html .= '<div style="margin:0 0 4px; font-size:11px; line-height:1.4; letter-spacing:1.2px; text-transform:uppercase; color:#6c84a2; font-weight:700;">After</div>';

            foreach ($comparisonChanges as $change) {
                $label = e((string) ($change['label'] ?? 'Updated Detail'));
                $afterValue = trim((string) ($change['after'] ?? ''));
                $afterHtml = e($afterValue !== '' ? $afterValue : 'Not set');

                $html .= <<<HTML
<div style="margin:0 0 10px;">
    <div style="margin:0 0 3px; font-size:13px; line-height:1.5; color:#6c84a2; font-weight:700;">{$label}</div>
    <div style="margin:0; font-size:14px; line-height:1.7; color:#10233b; font-weight:700;">{$afterHtml}</div>
</div>
HTML;
            }

            foreach ($singleChanges as $change) {
                $label = e((string) ($change['label'] ?? 'Updated Detail'));
                $value = trim((string) ($change['value'] ?? ''));
                $valueHtml = e($value !== '' ? $value : 'Not set');

                $html .= <<<HTML
<div style="margin:0 0 10px;">
    <div style="margin:0 0 3px; font-size:13px; line-height:1.5; color:#6c84a2; font-weight:700;">{$label}</div>
    <div style="margin:0; font-size:14px; line-height:1.7; color:#10233b; font-weight:700;">{$valueHtml}</div>
</div>
HTML;
            }

            $html .= '</div>';
        }

        foreach ($textChanges as $change) {
            $text = e((string) ($change['text'] ?? ''));
            $html .= '<p class="change-summary-block" style="margin:0 0 12px; font-size:14px; line-height:1.7; color:#2d4769;">' . $text . '</p>';
        }

        return $html !== ''
            ? $html
            : '<p>Please review updated details in the dashboard.</p>';
    }

    /**
     * @return array<string, string>
     */
    private function parseChangeSummaryLine(string $line): array
    {
        $line = trim($line);

        if (!str_contains($line, ':')) {
            return [
                'type' => 'text',
                'text' => $line,
            ];
        }

        [$label, $value] = explode(':', $line, 2);
        $label = trim($label);
        $value = trim($value);

        if (preg_match('/^removed\s+\(was\s+(.+)\)$/i', $value, $matches)) {
            return [
                'type' => 'comparison',
                'label' => $label,
                'before' => trim((string) ($matches[1] ?? '')),
                'after' => 'Removed',
            ];
        }

        if (preg_match('/\s(?:→|->)\s/u', $value) === 1) {
            [$before, $after] = preg_split('/\s*(?:→|->)\s*/u', $value, 2);

            return [
                'type' => 'comparison',
                'label' => $label,
                'before' => trim((string) $before),
                'after' => trim((string) $after),
            ];
        }

        return [
            'type' => 'single',
            'label' => $label,
            'value' => $value,
        ];
    }

    private function buildChangeSummaryBeforeHtml(string $label, string $before, string $after): string
    {
        $before = trim($before);
        $after = trim($after);

        if ($before === '') {
            return e('Not set');
        }

        if (strcasecmp($after, 'Removed') === 0) {
            return '<span style="text-decoration:line-through; color:#8c5f68;">' . e($before) . '</span>';
        }

        if (strcasecmp($label, 'Services') !== 0) {
            return e($before);
        }

        $beforeItems = array_values(array_filter(array_map(
            fn ($item) => trim((string) $item),
            preg_split('/\s*,\s*/', $before) ?: []
        ), fn ($item) => $item !== ''));

        if ($beforeItems === []) {
            return e($before);
        }

        $afterCounts = [];
        foreach (preg_split('/\s*,\s*/', $after) ?: [] as $item) {
            $normalizedItem = trim((string) $item);
            if ($normalizedItem === '') {
                continue;
            }

            $key = Str::lower($normalizedItem);
            $afterCounts[$key] = ($afterCounts[$key] ?? 0) + 1;
        }

        $parts = array_map(function (string $item) use (&$afterCounts) {
            $key = Str::lower($item);
            $remaining = (int) ($afterCounts[$key] ?? 0);

            if ($remaining > 0) {
                $afterCounts[$key] = $remaining - 1;

                return e($item);
            }

            return '<span style="text-decoration:line-through; color:#8c5f68;">' . e($item) . '</span>';
        }, $beforeItems);

        return implode(', ', $parts);
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
        $parts = array_filter([
            trim((string) ($shoot->address ?? '')),
            trim((string) ($shoot->city ?? '')),
            trim(implode(' ', array_filter([
                trim((string) ($shoot->state ?? '')),
                trim((string) ($shoot->zip ?? '')),
            ]))),
        ]);

        return implode(', ', $parts);
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

    private function sendViaCakemail(
        ?string $to,
        string $subject,
        string $html,
        string $sendSource,
        array $cc = [],
        array $tags = [],
        array $extraPayload = []
    ): void
    {
        if (!is_string($to) || trim($to) === '') {
            throw new \InvalidArgumentException('Recipient email is required to send mail.');
        }

        $relatedAccountId = $extraPayload['related_account_id'] ?? null;
        if ($relatedAccountId) {
            $recipient = User::query()->find($relatedAccountId);
            $blockedReason = app(EmailHealthService::class)->automatedSendBlockedReason($recipient, $sendSource);

            if ($blockedReason !== null) {
                Log::warning('Automated email send blocked by email health state.', [
                    'send_source' => $sendSource,
                    'related_account_id' => $relatedAccountId,
                    'email' => $to,
                    'reason' => $blockedReason,
                ]);

                throw new \RuntimeException($blockedReason);
            }
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        $payload = [
            'to' => $to,
            'cc' => $this->sanitizeEmailAddresses($cc, $to),
            'subject' => $subject,
            'body_html' => $html,
            'body_text' => $text,
            'send_source' => $sendSource,
            'sender_name' => 'R/E Pro Photos',
        ];

        if ($tags !== []) {
            $payload['tags_json'] = array_values($tags);
        }

        if ($relatedAccountId) {
            $payload['related_account_id'] = $relatedAccountId;
        }

        $messagingService = app(MessagingService::class);
        $messagingService->sendEmail($payload);
    }

    private function resolveShootCcEmailsForRecipient(Shoot $shoot, ?User $recipient = null): array
    {
        $shoot->loadMissing('client');
        $client = $shoot->client;

        if (!$client) {
            return [];
        }

        if ($recipient) {
            $recipientId = $recipient->id ? (int) $recipient->id : null;
            $clientId = $client->id ? (int) $client->id : null;
            $recipientEmail = strtolower(trim((string) ($recipient->email ?? '')));
            $clientEmail = strtolower(trim((string) ($client->email ?? '')));

            if (($recipientId !== null && $clientId !== null && $recipientId !== $clientId)
                && ($recipientEmail === '' || $clientEmail === '' || $recipientEmail !== $clientEmail)) {
                return [];
            }
        }

        return $this->sanitizeEmailAddresses($client->shoot_cc_emails ?? [], $client->email);
    }

    /**
     * @param  mixed  $emails
     * @return array<int, string>
     */
    private function sanitizeEmailAddresses(mixed $emails, ?string $exclude = null): array
    {
        $excluded = is_string($exclude) ? strtolower(trim($exclude)) : null;

        return collect(is_array($emails) ? $emails : [])
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->reject(fn ($email) => $excluded !== null && $email === $excluded)
            ->unique()
            ->values()
            ->all();
    }
}
