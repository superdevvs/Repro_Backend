<?php

namespace App\Services\ReproAi\Tools;

use App\Models\Shoot;
use App\Models\Payment;
use App\Http\Controllers\PaymentController;
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

            // Create payment link using Square API directly
            $squareClient = new \Square\SquareClient([
                'accessToken' => config('services.square.access_token'),
                'environment' => config('services.square.environment', 'sandbox'),
            ]);
            
            $amountInCents = (int) ($amountToPay * 100);
            $money = new \Square\Models\Money();
            $money->setAmount($amountInCents);
            $money->setCurrency(config('services.square.currency', 'USD'));

            $lineItem = new \Square\Models\OrderLineItem('1');
            $lineItem->setName('Payment for Shoot at ' . $shoot->address);
            $lineItem->setBasePriceMoney($money);
            $lineItem->setMetadata(['shoot_id' => (string)$shoot->id]);

            $order = new \Square\Models\Order(config('services.square.location_id'));
            $order->setLineItems([$lineItem]);
            $order->setReferenceId((string)$shoot->id);

            $createOrderRequest = new \Square\Models\CreateOrderRequest();
            $createOrderRequest->setOrder($order);
            $createOrderRequest->setIdempotencyKey(\Illuminate\Support\Str::uuid()->toString());

            $orderResponse = $squareClient->getOrdersApi()->createOrder($createOrderRequest);
            $createdOrder = $orderResponse->getResult()->getOrder();

            $checkoutRequest = new \Square\Models\CreateCheckoutRequest(
                \Illuminate\Support\Str::uuid()->toString(),
                ['order' => $createdOrder]
            );

            $checkoutRequest->setRedirectUrl(config('app.frontend_url', 'http://localhost:5173') . '/shoots/' . $shoot->id . '/payment-success');
            
            $checkoutResponse = $squareClient->getCheckoutApi()->createCheckout(
                config('services.square.location_id'),
                $checkoutRequest
            );

            $checkout = $checkoutResponse->getResult()->getCheckout();
            $checkoutUrl = $checkout->getCheckoutPageUrl();

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


