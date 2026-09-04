<?php

namespace App\Services\ReproAi\Tools;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Invoices\InvoiceAuthorizationService;
use App\Services\Payments\PublicPaymentAccessTokenService;
use Illuminate\Support\Facades\Log;

class PaymentTools
{
    public function __construct(
        protected PublicPaymentAccessTokenService $paymentTokens,
        protected InvoiceAuthorizationService $invoiceAuthorization
    ) {}

    /**
     * Create a payment checkout link for a shoot
     *
     * @param  array  $params  Parameters from AI tool call
     * @param  array  $context  Additional context
     * @return array Payment result
     */
    public function createPaymentLink(array $params, array $context = []): array
    {
        try {
            $shootId = $params['shoot_id'] ?? null;

            if (! $shootId) {
                return [
                    'success' => false,
                    'error' => 'Shoot ID is required',
                ];
            }

            $shoot = Shoot::find($shootId);

            if (! $shoot) {
                return [
                    'success' => false,
                    'error' => 'Shoot not found',
                ];
            }

            if (! $this->canAccessShoot($shoot, $context)) {
                return [
                    'success' => false,
                    'error' => 'You do not have access to create a payment link for this shoot.',
                ];
            }

            // Do not advertise a payable link until the same owner/customer
            // fields required by Checkout are present. The payment controller
            // remains the authoritative validation gate when the page opens.
            $shoot->loadMissing(['payments', 'client']);
            $client = $shoot->client;
            $missingDetails = collect([
                'owner name' => trim((string) ($client?->company_name ?: $client?->name)),
                'customer email' => filter_var(trim((string) ($client?->email ?? '')), FILTER_VALIDATE_EMAIL)
                    ? trim((string) $client->email)
                    : '',
                'property street' => trim((string) $shoot->address),
                'property city' => trim((string) $shoot->city),
                'property state' => trim((string) $shoot->state),
                'property postal code' => trim((string) $shoot->zip),
            ])->filter(fn ($value) => $value === '')->keys()->values()->all();

            if ($missingDetails !== []) {
                return [
                    'success' => false,
                    'error' => 'Complete the required Stripe details before creating a payment link: '.implode(', ', $missingDetails).'.',
                    'missing_details' => $missingDetails,
                ];
            }

            // Check if already fully paid
            $amountToPay = max((float) $shoot->total_quote - $shoot->calculateCanonicalTotalPaid(), 0);
            if ($amountToPay <= 0) {
                return [
                    'success' => true,
                    'message' => 'This shoot is already fully paid.',
                    'shoot_id' => $shoot->id,
                    'amount_remaining' => 0,
                    'checkout_url' => null,
                ];
            }

            // Return the canonical signed payment page. That page creates the
            // Checkout Session through StripePaymentController, so AI-created
            // links get the same authorization, customer details, metadata,
            // idempotency, and webhook reconciliation as every other payment.
            $checkoutUrl = $this->paymentTokens->buildPublicUrl($shoot);

            return [
                'success' => true,
                'message' => 'Payment link created successfully',
                'shoot_id' => $shoot->id,
                'amount_remaining' => $amountToPay,
                'checkout_url' => $checkoutUrl,
            ];
        } catch (\Exception $e) {
            Log::error('PaymentTools::createPaymentLink error', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to create payment link: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Get payment status for a shoot
     *
     * @param  array  $params  Parameters from AI tool call
     * @param  array  $context  Additional context
     * @return array Payment status
     */
    public function getPaymentStatus(array $params, array $context = []): array
    {
        try {
            $shootId = $params['shoot_id'] ?? null;

            if (! $shootId) {
                return [
                    'success' => false,
                    'error' => 'Shoot ID is required',
                ];
            }

            $shoot = Shoot::with('payments.refunds')->find($shootId);

            if (! $shoot) {
                return [
                    'success' => false,
                    'error' => 'Shoot not found',
                ];
            }

            if (! $this->canAccessShoot($shoot, $context)) {
                return [
                    'success' => false,
                    'error' => 'You do not have access to this shoot.',
                ];
            }

            $totalPaid = $shoot->calculateCanonicalTotalPaid();
            $totalQuote = (float) ($shoot->total_quote ?? 0);
            $amountRemaining = max($totalQuote - $totalPaid, 0);
            $paymentStatus = $amountRemaining <= 0 ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid');

            $payments = $shoot->payments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'status' => $payment->status,
                    'payment_date' => $payment->created_at->toDateString(),
                    'payment_method' => $payment->payment_method ?? 'unknown',
                ];
            })->toArray();

            return [
                'success' => true,
                'shoot_id' => $shoot->id,
                'total_quote' => $totalQuote,
                'total_paid' => $totalPaid,
                'amount_remaining' => $amountRemaining,
                'payment_status' => $paymentStatus,
                'payments' => $payments,
            ];
        } catch (\Exception $e) {
            Log::error('PaymentTools::getPaymentStatus error', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function canAccessShoot(Shoot $shoot, array $context): bool
    {
        $userId = $context['user_id'] ?? auth()->id();
        $user = $userId ? User::find($userId) : null;

        if (! $user) {
            return false;
        }

        return $this->invoiceAuthorization->canViewShootInvoice($shoot, $user);
    }
}
