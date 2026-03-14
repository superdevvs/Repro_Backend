<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Shoot;
use App\Models\Payment;
use App\Services\Messaging\MessagingService;

class MailService
{
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
            $shootData = $this->formatShootData($shoot);
            
            // Determine if this call is sending directly to the photographer
            $isDirectPhotographer = $shoot->photographer && $user->id === $shoot->photographer->id;

            // Send to primary recipient
            $html = view('emails.shoot_scheduled', [
                'user' => $user,
                'shoot' => $shootData,
                'paymentLink' => $paymentLink,
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
            $shouldNotifyClient = $notifyClient !== false;
            $shouldNotifyPhotographer = $notifyPhotographer !== false;
            
            if ($shouldNotifyClient) {
                $html = view('emails.shoot_updated', [
                    'user' => $user,
                    'shoot' => $shootData,
                    'changesSummary' => $changesSummary,
                    'isPhotographer' => false,
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
                    'changesSummary' => $changesSummary,
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
            $this->sendViaCakemail($user->email, 'Photo Shoot Cancelled', $html, 'SHOOT_REMOVED');
            
            Log::info('Shoot removed email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email
            ]);

            // Also send to photographer if assigned
            if ($shoot->photographer && $shoot->photographer->email && $shoot->photographer->id !== $user->id) {
                $htmlPhoto = view('emails.shoot_removed', [
                    'user' => $shoot->photographer,
                    'shoot' => $shootData,
                ])->render();
                $this->sendViaCakemail($shoot->photographer->email, 'Photo Shoot Cancelled', $htmlPhoto, 'SHOOT_REMOVED');
                Log::info('Shoot removed email sent to photographer', [
                    'photographer_id' => $shoot->photographer->id,
                    'shoot_id' => $shoot->id,
                    'email' => $shoot->photographer->email
                ]);
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

    /**
     * Send shoot ready email
     */
    public function sendShootReadyEmail(User $user, Shoot $shoot): bool
    {
        try {
            $shootData = $this->formatShootData($shoot);
            
            // Send to client
            $html = view('emails.shoot_ready', [
                'user' => $user,
                'shoot' => $shootData,
            ])->render();
            $this->sendViaCakemail($user->email, 'Your Photos Are Ready!', $html, 'SHOOT_READY');
            
            Log::info('Shoot ready email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email
            ]);

            // Also send to photographer if assigned
            if ($shoot->photographer && $shoot->photographer->email && $shoot->photographer->id !== $user->id) {
                $htmlPhoto = view('emails.shoot_ready', [
                    'user' => $shoot->photographer,
                    'shoot' => $shootData,
                ])->render();
                $this->sendViaCakemail($shoot->photographer->email, 'Your Photos Are Ready!', $htmlPhoto, 'SHOOT_READY');
                Log::info('Shoot ready email sent to photographer', [
                    'photographer_id' => $shoot->photographer->id,
                    'shoot_id' => $shoot->id,
                    'email' => $shoot->photographer->email
                ]);
            }
            
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
     * Format shoot data for email templates
     */
    private function formatShootData(Shoot $shoot): object
    {
        // Ensure relationships are loaded
        $shoot->loadMissing(['client', 'photographer', 'services']);

        // Create full address from components
        $fullAddress = trim($shoot->address);
        if ($shoot->city) {
            $fullAddress .= ', ' . $shoot->city;
        }
        if ($shoot->state) {
            $fullAddress .= ', ' . $shoot->state;
        }
        if ($shoot->zip) {
            $fullAddress .= ' ' . $shoot->zip;
        }

        // Format time nicely (e.g., "2:00 PM" instead of "14:00")
        $formattedTime = null;
        if ($shoot->time) {
            try {
                $formattedTime = \Carbon\Carbon::parse($shoot->time)->format('g:i A');
            } catch (\Exception $e) {
                $formattedTime = $shoot->time;
            }
        }

        // Format date with time
        $dateStr = 'TBD';
        if ($shoot->scheduled_date) {
            $dateStr = $shoot->scheduled_date->format('M j, Y');
            if ($formattedTime) {
                $dateStr .= ' at ' . $formattedTime;
            }
        }

        // Format notes - extract only content from notes relationship or shoot_notes field
        $notesText = $this->formatNotes($shoot);

        return (object) [
            'id' => $shoot->id,
            'location' => $fullAddress ?: 'TBD',
            'date' => $dateStr,
            'time' => $formattedTime ?? 'TBD',
            'photographer' => $shoot->photographer ? $shoot->photographer->name : 'TBD',
            'client_name' => $shoot->client ? $shoot->client->name : 'N/A',
            'notes' => $notesText,
            'status' => $shoot->status,
            'total' => $shoot->base_quote ?? 0,
            'tax' => $shoot->tax_amount ?? 0,
            'tax_rate' => $shoot->tax_percent ?? 0,
            'grand_total' => $shoot->total_quote ?? 0,
            'packages' => $this->formatPackages($shoot),
            'service_category' => $shoot->service_category ?? 'Standard'
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
        
        // Add tax as separate line item if applicable
        if ($shoot->tax_amount && $shoot->tax_amount > 0) {
            $packages[] = [
                'name' => 'Tax',
                'price' => $shoot->tax_amount
            ];
        }
        
        return $packages;
    }

    /**
     * Generate payment link for shoot
     * Points to public payment page
     */
    public function generatePaymentLink(Shoot $shoot): string
    {
        $frontendUrl = config('app.frontend_url', 'https://reprodashboard.com');
        return "{$frontendUrl}/payment/{$shoot->id}";
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
            $photographer = $invoice->photographer;
            if (!$photographer) {
                Log::warning('Cannot send invoice email: photographer not found', [
                    'invoice_id' => $invoice->id
                ]);
                return false;
            }

            $period = "{$invoice->billing_period_start->format('M j')} - {$invoice->billing_period_end->format('M j, Y')}";
            $invoice->loadMissing('items');

            $html = view('emails.invoice_generated', [
                'invoice' => $invoice,
                'photographer' => $photographer,
                'period' => $period,
            ])->render();
            $this->sendViaCakemail($photographer->email, "Weekly Invoice - {$period}", $html, 'INVOICE_GENERATED');
            
            Log::info('Invoice generated email sent', [
                'invoice_id' => $invoice->id,
                'photographer_id' => $photographer->id,
                'email' => $photographer->email
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
            $shootData = $this->formatShootData($shoot);
            
            // Send to client
            $html = view('emails.shoot_paid', [
                'user' => $user,
                'shoot' => $shootData,
                'amount' => $amount,
            ])->render();
            $this->sendViaCakemail($user->email, 'Your Shoot Has Been Marked as Paid', $html, 'SHOOT_PAID');
            
            Log::info('Shoot paid email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'amount' => $amount
            ]);

            // Also send to photographer if assigned
            if ($shoot->photographer && $shoot->photographer->email && $shoot->photographer->id !== $user->id) {
                $htmlPhoto = view('emails.shoot_paid', [
                    'user' => $shoot->photographer,
                    'shoot' => $shootData,
                    'amount' => $amount,
                ])->render();
                $this->sendViaCakemail($shoot->photographer->email, 'Your Shoot Has Been Marked as Paid', $htmlPhoto, 'SHOOT_PAID');
                Log::info('Shoot paid email sent to photographer', [
                    'photographer_id' => $shoot->photographer->id,
                    'shoot_id' => $shoot->id,
                    'email' => $shoot->photographer->email
                ]);
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot paid email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
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
            $this->sendViaCakemail($user->email, 'Photo Shoot Cancelled', $html, 'SHOOT_CANCELLED');
            
            Log::info('Shoot cancelled email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email
            ]);

            // Also send to photographer if assigned
            if ($shoot->photographer && $shoot->photographer->email && $shoot->photographer->id !== $user->id) {
                $htmlPhoto = view('emails.shoot_removed', [
                    'user' => $shoot->photographer,
                    'shoot' => $shootData,
                ])->render();
                $this->sendViaCakemail($shoot->photographer->email, 'Photo Shoot Cancelled', $htmlPhoto, 'SHOOT_CANCELLED');
                Log::info('Shoot cancelled email sent to photographer', [
                    'photographer_id' => $shoot->photographer->id,
                    'shoot_id' => $shoot->id,
                    'email' => $shoot->photographer->email
                ]);
            }
            
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
    private function sendViaCakemail(string $to, string $subject, string $html, string $sendSource): void
    {
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