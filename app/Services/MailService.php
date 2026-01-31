<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Shoot;
use App\Models\Payment;
use App\Mail\AccountCreatedMail;
use App\Mail\ShootScheduledMail;
use App\Mail\ShootUpdatedMail;
use App\Mail\ShootRemovedMail;
use App\Mail\ShootReadyMail;
use App\Mail\PaymentConfirmationMail;
use App\Mail\TermsAcceptedMail;
use App\Mail\WeeklySalesReportMail;
use App\Mail\InvoiceGeneratedMail;
use App\Mail\InvoicePendingApprovalMail;
use App\Mail\InvoiceApprovedMail;
use App\Mail\InvoiceRejectedMail;
use App\Mail\PasswordResetMail;

class MailService
{
    /**
     * Send account created email
     */
    public function sendAccountCreatedEmail(User $user, string $resetLink): bool
    {
        try {
            Mail::to($user->email)->send(new AccountCreatedMail($user, $resetLink));
            
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
            
            // Send to client
            Mail::to($user->email)->send(new ShootScheduledMail($user, $shootData, $paymentLink));
            
            Log::info('Shoot scheduled email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email
            ]);

            // Also send to photographer if assigned
            if ($shoot->photographer && $shoot->photographer->email && $shoot->photographer->id !== $user->id) {
                Mail::to($shoot->photographer->email)->send(new ShootScheduledMail($shoot->photographer, $shootData, $paymentLink));
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
    public function sendShootUpdatedEmail(User $user, Shoot $shoot): bool
    {
        try {
            $shootData = $this->formatShootData($shoot);
            
            // Send to client
            Mail::to($user->email)->send(new ShootUpdatedMail($user, $shootData));
            
            Log::info('Shoot updated email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email
            ]);

            // Also send to photographer if assigned
            if ($shoot->photographer && $shoot->photographer->email && $shoot->photographer->id !== $user->id) {
                Mail::to($shoot->photographer->email)->send(new ShootUpdatedMail($shoot->photographer, $shootData));
                Log::info('Shoot updated email sent to photographer', [
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
            Mail::to($user->email)->send(new ShootRemovedMail($user, $shootData));
            
            Log::info('Shoot removed email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email
            ]);

            // Also send to photographer if assigned
            if ($shoot->photographer && $shoot->photographer->email && $shoot->photographer->id !== $user->id) {
                Mail::to($shoot->photographer->email)->send(new ShootRemovedMail($shoot->photographer, $shootData));
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
            Mail::to($user->email)->send(new ShootReadyMail($user, $shootData));
            
            Log::info('Shoot ready email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email
            ]);

            // Also send to photographer if assigned
            if ($shoot->photographer && $shoot->photographer->email && $shoot->photographer->id !== $user->id) {
                Mail::to($shoot->photographer->email)->send(new ShootReadyMail($shoot->photographer, $shootData));
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
            Mail::to($user->email)->send(new PaymentConfirmationMail($user, $shootData, $paymentData));
            
            Log::info('Payment confirmation email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'payment_id' => $payment->id,
                'email' => $user->email
            ]);

            // Also send to photographer if assigned
            if ($shoot->photographer && $shoot->photographer->email && $shoot->photographer->id !== $user->id) {
                Mail::to($shoot->photographer->email)->send(new PaymentConfirmationMail($shoot->photographer, $shootData, $paymentData));
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
            Mail::to($user->email)->send(new TermsAcceptedMail($user));
            
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

        // Format date with time
        $dateStr = 'TBD';
        if ($shoot->scheduled_date) {
            $dateStr = $shoot->scheduled_date->format('M j, Y');
            if ($shoot->time) {
                $dateStr .= ' at ' . $shoot->time;
            }
        }

        // Format notes - extract only content from notes relationship or shoot_notes field
        $notesText = $this->formatNotes($shoot);

        return (object) [
            'id' => $shoot->id,
            'location' => $fullAddress ?: 'TBD',
            'date' => $dateStr,
            'time' => $shoot->time ?? 'TBD',
            'photographer' => $shoot->photographer ? $shoot->photographer->name : 'TBD',
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
            Mail::to($user->email)->send(new PasswordResetMail($user, $resetLink));
            
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
            Mail::to($salesRep->email)->send(new WeeklySalesReportMail($salesRep, $reportData));
            
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

            Mail::to($photographer->email)->send(new InvoiceGeneratedMail($invoice));
            
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

            foreach ($admins as $admin) {
                Mail::to($admin->email)->send(new InvoicePendingApprovalMail($invoice, $admin));
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

            Mail::to($photographer->email)->send(new InvoiceApprovedMail($invoice));
            
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

            Mail::to($photographer->email)->send(new InvoiceRejectedMail($invoice));
            
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
            Mail::to($user->email)->send(new \App\Mail\ShootPaidMail($user, $shootData, $amount));
            
            Log::info('Shoot paid email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'amount' => $amount
            ]);

            // Also send to photographer if assigned
            if ($shoot->photographer && $shoot->photographer->email && $shoot->photographer->id !== $user->id) {
                Mail::to($shoot->photographer->email)->send(new \App\Mail\ShootPaidMail($shoot->photographer, $shootData, $amount));
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
            Mail::to($user->email)->send(new ShootRemovedMail($user, $shootData));
            
            Log::info('Shoot cancelled email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email
            ]);

            // Also send to photographer if assigned
            if ($shoot->photographer && $shoot->photographer->email && $shoot->photographer->id !== $user->id) {
                Mail::to($shoot->photographer->email)->send(new ShootRemovedMail($shoot->photographer, $shootData));
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
}