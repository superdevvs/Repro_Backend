<?php

namespace App\Services\ReproAi\Tools;

use App\Models\Shoot;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PaymentTools
{
    /**
     * Create a payment checkout link for a shoot
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Payment result
     */
    public function createPaymentLink(array $params, array $context = []): array
    {
        try {
            $shootId = $params['shoot_id'] ?? null;
            
            if (!$shootId) {
                return [
                    'success' => false,
                    'error' => 'Shoot ID is required',
                ];
            }

            $shoot = Shoot::find($shootId);
            
            if (!$shoot) {
                return [
                    'success' => false,
                    'error' => 'Shoot not found',
                ];
            }

            // Check if already fully paid
            $amountToPay = $shoot->total_quote - $shoot->total_paid;
            if ($amountToPay <= 0) {
                return [
                    'success' => true,
                    'message' => 'This shoot is already fully paid.',
                    'shoot_id' => $shoot->id,
                    'amount_remaining' => 0,
                    'checkout_url' => null,
                ];
            }

            // Create payment link using Stripe Checkout
            $stripeSecretKey = config('services.stripe.secret_key');
            if (empty($stripeSecretKey)) {
                return [
                    'success' => false,
                    'error' => 'Stripe is not configured. Please set STRIPE_SECRET_KEY in .env.',
                ];
            }

            \Stripe\Stripe::setApiKey($stripeSecretKey);

            $amountInCents = (int) round($amountToPay * 100);
            $currency = strtolower(config('services.stripe.currency', 'USD'));
            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
            $client = User::find($shoot->client_id);

            $sessionParams = [
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'line_items' => [[
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => 'Payment for Shoot at ' . $shoot->address,
                            'metadata' => ['shoot_id' => (string) $shoot->id],
                        ],
                        'unit_amount' => $amountInCents,
                    ],
                    'quantity' => 1,
                ]],
                'metadata' => [
                    'shoot_id' => (string) $shoot->id,
                    'type' => 'single',
                ],
                'client_reference_id' => 'shoot:' . $shoot->id,
                'success_url' => $frontendUrl . '/payment/' . $shoot->id . '?success=true&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => $frontendUrl . '/payment/' . $shoot->id,
            ];

            if ($client && $client->email) {
                $sessionParams['customer_creation'] = 'always';
                $sessionParams['customer_email'] = $client->email;
            }

            $session = \Stripe\Checkout\Session::create($sessionParams);

            $checkoutUrl = $session->url;

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
                'error' => 'Failed to create payment link: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get payment status for a shoot
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Payment status
     */
    public function getPaymentStatus(array $params, array $context = []): array
    {
        try {
            $shootId = $params['shoot_id'] ?? null;
            
            if (!$shootId) {
                return [
                    'success' => false,
                    'error' => 'Shoot ID is required',
                ];
            }

            $shoot = Shoot::with('payments')->find($shootId);
            
            if (!$shoot) {
                return [
                    'success' => false,
                    'error' => 'Shoot not found',
                ];
            }

            $totalPaid = $shoot->total_paid ?? 0;
            $totalQuote = $shoot->total_quote ?? 0;
            $amountRemaining = $totalQuote - $totalPaid;
            $paymentStatus = $amountRemaining <= 0 ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid');

            $payments = $shoot->payments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'status' => $payment->status,
                    'payment_date' => $payment->created_at->toDateString(),
                    'payment_method' => $payment->payment_method ?? 'Square',
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
}


