<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Shoot; // Your Shoot model
use App\Models\Payment;
use App\Models\User;
use App\Services\MailService;
use App\Services\ShootActivityLogger;
use Illuminate\Support\Facades\DB;
use Square\SquareClient;
use Square\Models\CreateCheckoutRequest;
use Square\Models\CreateOrderRequest;
use Square\Models\Order;
use Square\Models\OrderLineItem;
use Square\Models\Money;
use Square\Models\CreateRefundRequest;
use Square\Models\CreatePaymentRequest;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Square\Exceptions\ApiException;

class PaymentController extends Controller
{
    protected $squareClient;
    protected $mailService;
    protected $activityLogger;

    /**
     * Constructor to initialize the Square Client.
     */
    public function __construct(MailService $mailService, ShootActivityLogger $activityLogger)
    {
        $this->mailService = $mailService;
        $this->activityLogger = $activityLogger;
        // Don't initialize Square client in constructor - lazy load it when needed
    }

    /**
     * Get or initialize the Square client (lazy loading)
     */
    protected function getSquareClient(): SquareClient
    {
        if ($this->squareClient === null) {
            $accessToken = config('services.square.access_token');
            
            if (empty($accessToken)) {
                Log::error('Square access token is not configured. Please set SQUARE_ACCESS_TOKEN in your .env file.');
                throw new \RuntimeException(
                    'Square payment integration is not configured. Please contact the administrator.'
                );
            }
            
            $this->squareClient = new SquareClient($accessToken);
        }
        
        return $this->squareClient;
    }

    /**
     * Create a Square Checkout link for a specific shoot.
     */
    public function createCheckoutLink(Request $request, Shoot $shoot)
    {
        // Calculate amount to be paid in cents
        $amountToPay = (int) (($shoot->total_quote - $shoot->total_paid) * 100);

        // Prevent creating payment links for fully paid or invalid amount shoots
        if ($amountToPay <= 0) {
            return response()->json(['error' => 'This shoot is already fully paid or has a zero balance.'], 400);
        }

        try {
            // 1. Create an Order
            $money = new Money();
            $money->setAmount($amountToPay);
            $money->setCurrency(config('services.square.currency', 'USD'));

            $lineItem = new OrderLineItem('1');
            $lineItem->setName('Payment for Shoot at ' . $shoot->address);
            $lineItem->setBasePriceMoney($money);
            // Add metadata to link the payment back to your internal models
            $lineItem->setMetadata(['shoot_id' => (string)$shoot->id]);

            $order = new Order(config('services.square.location_id'));
            $order->setLineItems([$lineItem]);
            $order->setReferenceId((string)$shoot->id); // Link order to the shoot

            $createOrderRequest = new CreateOrderRequest();
            $createOrderRequest->setOrder($order);
            $createOrderRequest->setIdempotencyKey(Str::uuid()->toString());

            $orderResponse = $this->getSquareClient()->getOrdersApi()->createOrder($createOrderRequest);
            $createdOrder = $orderResponse->getResult()->getOrder();

            // 2. Create the Checkout Request using the Order
            $checkoutRequest = new CreateCheckoutRequest(
                Str::uuid()->toString(),
                ['order' => $createdOrder]
            );

            // Set a redirect URL for after the payment is completed
            $checkoutRequest->setRedirectUrl(config('app.frontend_url') . '/shoots/' . $shoot->id . '/payment-success');
            
            $checkoutResponse = $this->getSquareClient()->getCheckoutApi()->createCheckout(
                config('services.square.location_id'),
                $checkoutRequest
            );

            $checkout = $checkoutResponse->getResult()->getCheckout();

            // Return the checkout URL to the frontend
            return response()->json([
                'checkoutUrl' => $checkout->getCheckoutPageUrl()
            ]);

        } catch (ApiException $e) {
            Log::error("Square API Exception: " . $e->getMessage(), ['response_body' => $e->getResponseBody()]);
            return response()->json(['error' => 'Could not create payment link. Please try again later.'], 500);
        } catch (\Exception $e) {
            Log::error("Generic Exception in createCheckoutLink: " . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    /**
     * Create checkout link for multiple shoots
     */
    public function payMultipleShoots(Request $request)
    {
        $validated = $request->validate([
            'shoot_ids' => 'required|array|min:1',
            'shoot_ids.*' => 'exists:shoots,id',
        ]);

        try {
            $shoots = Shoot::whereIn('id', $validated['shoot_ids'])->get();
            
            if ($shoots->isEmpty()) {
                return response()->json(['error' => 'No valid shoots found'], 400);
            }

            // Calculate total amount
            $totalAmount = 0;
            $lineItems = [];
            
            foreach ($shoots as $index => $shoot) {
                $amountToPay = (int) (($shoot->total_quote - $shoot->total_paid) * 100);
                if ($amountToPay <= 0) continue;
                
                $totalAmount += $amountToPay;
                
                $money = new Money();
                $money->setAmount($amountToPay);
                $money->setCurrency(config('services.square.currency', 'USD'));

                $lineItem = new OrderLineItem((string)($index + 1));
                $lineItem->setName('Payment for Shoot at ' . $shoot->address);
                $lineItem->setBasePriceMoney($money);
                $lineItem->setMetadata(['shoot_id' => (string)$shoot->id]);
                
                $lineItems[] = $lineItem;
            }

            if ($totalAmount <= 0) {
                return response()->json(['error' => 'All selected shoots are already fully paid'], 400);
            }

            // Create order with all line items
            $order = new Order(config('services.square.location_id'));
            $order->setLineItems($lineItems);
            $order->setReferenceId('multiple_shoots_' . implode(',', $validated['shoot_ids']));

            $createOrderRequest = new CreateOrderRequest();
            $createOrderRequest->setOrder($order);
            $createOrderRequest->setIdempotencyKey(Str::uuid()->toString());

            $orderResponse = $this->getSquareClient()->getOrdersApi()->createOrder($createOrderRequest);
            $createdOrder = $orderResponse->getResult()->getOrder();

            // Create checkout
            $checkoutRequest = new CreateCheckoutRequest(
                Str::uuid()->toString(),
                ['order' => $createdOrder]
            );

            $checkoutRequest->setRedirectUrl(config('app.frontend_url') . '/shoot-history?payment=success');

            $checkoutResponse = $this->getSquareClient()->getCheckoutApi()->createCheckout(
                config('services.square.location_id'),
                $checkoutRequest
            );

            $checkout = $checkoutResponse->getResult()->getCheckout();

            return response()->json([
                'checkoutUrl' => $checkout->getCheckoutPageUrl(),
                'totalAmount' => $totalAmount / 100,
                'shootCount' => $shoots->count(),
            ]);

        } catch (ApiException $e) {
            Log::error("Square API Exception (multiple shoots): " . $e->getMessage(), ['response_body' => $e->getResponseBody()]);
            return response()->json(['error' => 'Could not create payment link. Please try again later.'], 500);
        } catch (\Exception $e) {
            Log::error("Generic Exception in payMultipleShoots: " . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    /**
     * Handle incoming webhooks from Square.
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->all();
        Log::info('Square webhook received:', $payload);

        // Check if the event type is a payment update
        if (isset($payload['type']) && $payload['type'] === 'payment.updated') {
            $paymentData = $payload['data']['object']['payment'];

            // Process completed payments
            if ($paymentData['status'] === 'COMPLETED') {
                return $this->handleCompletedPayment($paymentData);
            }

            // Process failed payments
            if ($paymentData['status'] === 'FAILED') {
                return $this->handleFailedPayment($paymentData);
            }
        }

        return response()->json(['status' => 'success'], 200);
    }

    /**
     * Handle completed payment
     */
    protected function handleCompletedPayment(array $paymentData)
    {
                $orderId = $paymentData['order_id'];
                $paymentId = $paymentData['id'];
                $amount = $paymentData['amount_money']['amount'] / 100; // Convert from cents
                $currency = $paymentData['amount_money']['currency'];

        try {
                // Retrieve the order to get the shoot_id from the reference_id
                $orderResponse = $this->getSquareClient()->getOrdersApi()->retrieveOrder($orderId);
                $order = $orderResponse->getResult()->getOrder();
                $shootId = $order->getReferenceId();

            if (!$shootId) {
                Log::warning('Square webhook: No shoot_id found in order reference_id', [
                    'order_id' => $orderId,
                    'payment_id' => $paymentId,
                ]);
                return response()->json(['status' => 'success'], 200);
            }

            return DB::transaction(function () use ($shootId, $paymentId, $amount, $currency, $orderId) {
                    $shoot = Shoot::find($shootId);

                if (!$shoot) {
                    Log::warning('Square webhook: Shoot not found', ['shoot_id' => $shootId]);
                    return response()->json(['status' => 'success'], 200);
                }

                    // Prevent duplicate processing
                if (Payment::where('square_payment_id', $paymentId)->exists()) {
                    Log::info('Square webhook: Payment already processed', ['payment_id' => $paymentId]);
                    return response()->json(['status' => 'success'], 200);
                }

                        // Record the payment in your database
                        $payment = Payment::create([
                            'shoot_id' => $shoot->id,
                            'amount' => $amount,
                            'currency' => $currency,
                            'square_payment_id' => $paymentId,
                            'square_order_id' => $orderId,
                            'status' => Payment::STATUS_COMPLETED,
                            'processed_at' => now()
                        ]);

                // Calculate new payment status
                $totalPaid = $shoot->payments()
                    ->where('status', Payment::STATUS_COMPLETED)
                    ->sum('amount');

                $oldPaymentStatus = $shoot->payment_status;
                $newPaymentStatus = $this->calculatePaymentStatus($totalPaid, $shoot->total_quote);

                // Update shoot payment status
                $shoot->payment_status = $newPaymentStatus;
                            $shoot->save();

                // Log payment activity
                $this->activityLogger->log(
                    $shoot,
                    'payment_received',
                    [
                        'payment_id' => $payment->id,
                        'amount' => $amount,
                        'currency' => $currency,
                        'total_paid' => $totalPaid,
                        'total_quote' => $shoot->total_quote,
                        'old_status' => $oldPaymentStatus,
                        'new_status' => $newPaymentStatus,
                    ],
                    null // System action, no user
                );

                // If fully paid, log completion
                if ($newPaymentStatus === 'paid' && $oldPaymentStatus !== 'paid') {
                    $this->activityLogger->log(
                        $shoot,
                        'payment_completed',
                        [
                            'total_paid' => $totalPaid,
                            'total_quote' => $shoot->total_quote,
                        ],
                        null
                    );
                }

                // Dispatch payment confirmation email job (async)
                // TODO: Create SendPaymentConfirmationEmailJob
                        $client = User::find($shoot->client_id);
                        if ($client) {
                    try {
                            $this->mailService->sendPaymentConfirmationEmail($client, $shoot, $payment);
                        
                        // Log email sent
                        $this->activityLogger->log(
                            $shoot,
                            'payment_completion_email_sent',
                            [
                                'recipient' => $client->email,
                            ],
                            null
                        );
                    } catch (\Exception $e) {
                        Log::error('Failed to send payment confirmation email', [
                            'shoot_id' => $shoot->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                Log::info("Payment for Shoot ID {$shootId} processed successfully.", [
                    'payment_id' => $payment->id,
                    'amount' => $amount,
                    'payment_status' => $newPaymentStatus,
                ]);

                return response()->json(['status' => 'success'], 200);
            });
        } catch (\Exception $e) {
            Log::error('Square webhook processing error', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return success to Square to prevent retries for non-recoverable errors
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 200);
        }
    }

    /**
     * Handle failed payment
     */
    protected function handleFailedPayment(array $paymentData)
    {
        $paymentId = $paymentData['id'];
        $orderId = $paymentData['order_id'] ?? null;

        try {
            if ($orderId) {
                $orderResponse = $this->getSquareClient()->getOrdersApi()->retrieveOrder($orderId);
                $order = $orderResponse->getResult()->getOrder();
                $shootId = $order->getReferenceId();

                if ($shootId) {
                    $shoot = Shoot::find($shootId);
                    if ($shoot) {
                        // Log failed payment
                        $this->activityLogger->log(
                            $shoot,
                            'payment_failed',
                            [
                                'square_payment_id' => $paymentId,
                                'reason' => $paymentData['failure_reason'] ?? 'Unknown',
                            ],
                            null
                        );
                    }
                }
            }

            Log::warning('Square payment failed', [
                'payment_id' => $paymentId,
                'order_id' => $orderId,
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing failed payment webhook', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'success'], 200);
    }

    /**
     * Calculate payment status based on total paid vs total quote
     */
    protected function calculatePaymentStatus(float $totalPaid, float $totalQuote): string
    {
        if ($totalPaid <= 0) {
            return 'unpaid';
        }

        if ($totalPaid >= $totalQuote) {
            return 'paid';
        }

        return 'partial';
    }
    
    /**
     * Process a direct payment using Square Web Payments SDK token.
     * This endpoint receives a tokenized payment from the frontend and processes it.
     */
    public function createPayment(Request $request)
    {
        $request->validate([
            'sourceId' => 'required|string', // The token from Square Web Payments SDK
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'sometimes|string|size:3',
            'shoot_id' => 'sometimes|exists:shoots,id', // Optional: link payment to a shoot
            'idempotencyKey' => 'sometimes|string', // Optional: for idempotency
        ]);

        $amountCents = (int) (($request->input('amount')) * 100);
        $currency = $request->input('currency', config('services.square.currency', 'USD'));
        $sourceId = $request->input('sourceId');
        $idempotencyKey = $request->input('idempotencyKey', Str::uuid()->toString());
        $shootId = $request->input('shoot_id');

        try {
            // Create Money object
            $money = new Money();
            $money->setAmount($amountCents);
            $money->setCurrency($currency);

            // Create payment request
            $paymentRequest = new CreatePaymentRequest($sourceId, $idempotencyKey, $money);
            $paymentRequest->setLocationId(config('services.square.location_id'));

            // Optional: Add buyer information if provided
            if ($request->has('buyer')) {
                $buyerInfo = $request->input('buyer');
                // Square SDK handles buyer info through verification details
                // Additional buyer info can be added here if needed
            }

            // Process the payment
            $response = $this->getSquareClient()->getPaymentsApi()->createPayment($paymentRequest);

            if ($response->isSuccess()) {
                $payment = $response->getResult()->getPayment();
                $squarePaymentId = $payment->getId();
                $amount = $payment->getAmountMoney()->getAmount() / 100;
                $currency = $payment->getAmountMoney()->getCurrency();

                // If shoot_id is provided, record the payment
                if ($shootId) {
                    return DB::transaction(function () use ($shootId, $squarePaymentId, $amount, $currency, $payment) {
                        $shoot = Shoot::find($shootId);
                        if (!$shoot) {
                            return response()->json(['error' => 'Shoot not found'], 404);
                        }

                        // Check for duplicate payment
                        if (Payment::where('square_payment_id', $squarePaymentId)->exists()) {
                            Log::info('Square payment already processed', ['payment_id' => $squarePaymentId]);
                            return response()->json([
                                'status' => 'success',
                                'message' => 'Payment already processed',
                                'payment' => $payment,
                            ]);
                        }

                        // Record the payment
                        $paymentRecord = Payment::create([
                            'shoot_id' => $shoot->id,
                            'amount' => $amount,
                            'currency' => $currency,
                            'square_payment_id' => $squarePaymentId,
                            'square_order_id' => $payment->getOrderId(),
                            'status' => Payment::STATUS_COMPLETED,
                            'processed_at' => now(),
                        ]);

                        // Update shoot payment status
                        $totalPaid = $shoot->payments()
                            ->where('status', Payment::STATUS_COMPLETED)
                            ->sum('amount');

                        $oldPaymentStatus = $shoot->payment_status;
                        $newPaymentStatus = $this->calculatePaymentStatus($totalPaid, $shoot->total_quote);
                        $shoot->payment_status = $newPaymentStatus;
                        $shoot->save();

                        // Log payment activity
                        $this->activityLogger->log(
                            $shoot,
                            'payment_received',
                            [
                                'payment_id' => $paymentRecord->id,
                                'amount' => $amount,
                                'currency' => $currency,
                                'total_paid' => $totalPaid,
                                'total_quote' => $shoot->total_quote,
                                'old_status' => $oldPaymentStatus,
                                'new_status' => $newPaymentStatus,
                            ],
                            auth()->user()
                        );

                        return response()->json([
                            'status' => 'success',
                            'payment' => $payment,
                            'payment_record' => $paymentRecord,
                            'shoot' => [
                                'id' => $shoot->id,
                                'payment_status' => $newPaymentStatus,
                                'total_paid' => $totalPaid,
                            ],
                        ]);
                    });
                }

                // Payment processed but not linked to a shoot
                return response()->json([
                    'status' => 'success',
                    'payment' => $payment,
                    'message' => 'Payment processed successfully',
                ]);
            }

            // Payment failed
            $errors = $response->getErrors();
            Log::error('Square payment failed', [
                'errors' => $errors,
                'amount' => $amountCents,
                'currency' => $currency,
            ]);

            return response()->json([
                'status' => 'error',
                'errors' => $errors,
                'message' => 'Payment processing failed',
            ], 400);

        } catch (ApiException $e) {
            Log::error('Square API Exception in createPayment', [
                'message' => $e->getMessage(),
                'errors' => $e->getErrors(),
                'response_body' => $e->getResponseBody(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Payment processing failed',
                'error' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ], 500);

        } catch (\Exception $e) {
            Log::error('Exception in createPayment', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Refund a specific payment.
     */
    public function refundPayment(Request $request)
    {
        $request->validate([
            'payment_id' => 'required|string', // The Square Payment ID
            'amount' => 'required|numeric|min:0.01', // Amount to refund
        ]);

        $paymentId = $request->input('payment_id');
        $amountToRefund = (int) ($request->input('amount') * 100);

        try {
            $money = new Money();
            $money->setAmount($amountToRefund);
            $money->setCurrency(config('services.square.currency', 'USD'));

            $refundRequest = new CreateRefundRequest(
                Str::uuid()->toString(), // Idempotency key
                $paymentId,
                $money
            );
            
            $response = $this->getSquareClient()->getRefundsApi()->refundPayment($refundRequest);
            $refund = $response->getResult()->getRefund();

            if ($refund->getStatus() === 'COMPLETED' || $refund->getStatus() === 'PENDING') {
                // Update your internal payment record to reflect the refund
                $payment = Payment::where('square_payment_id', $paymentId)->first();
                if ($payment) {
                    $payment->status = Payment::STATUS_REFUNDED;
                    $payment->save();

                    // Update shoot payment status
                    $shoot = $payment->shoot;
                    $totalPaid = $shoot->payments()
                        ->where('status', Payment::STATUS_COMPLETED)
                        ->sum('amount');
                    
                    $newStatus = $this->calculatePaymentStatus($totalPaid, $shoot->total_quote);
                    $shoot->payment_status = $newStatus;
                        $shoot->save();

                    // Log refund activity
                    $this->activityLogger->log(
                        $shoot,
                        'payment_refunded',
                        [
                            'payment_id' => $payment->id,
                            'refund_amount' => $request->input('amount'),
                            'new_payment_status' => $newStatus,
                        ],
                        auth()->user()
                    );
                }
                
                Log::info("Refund processed for payment ID: {$paymentId}");
                return response()->json(['status' => 'success', 'refund' => $refund]);
            }

            return response()->json(['error' => 'Refund was not successful.', 'refund_status' => $refund->getStatus()], 400);

        } catch (ApiException $e) {
            Log::error("Square Refund API Exception: " . $e->getMessage(), ['response_body' => $e->getResponseBody()]);
            return response()->json(['error' => 'Failed to process refund.'], 500);
        }
    }
}
