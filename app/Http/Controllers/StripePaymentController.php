<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Shoot;
use App\Models\Payment;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\ShootActivityLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Customer as StripeCustomer;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Webhook;
use Stripe\Refund;
use Stripe\Exception\SignatureVerificationException;

class StripePaymentController extends Controller
{
    protected $mailService;
    protected $activityLogger;
    protected $automationService;

    public function __construct(MailService $mailService, ShootActivityLogger $activityLogger, AutomationService $automationService)
    {
        $this->mailService = $mailService;
        $this->activityLogger = $activityLogger;
        $this->automationService = $automationService;
    }

    /**
     * Initialise Stripe with the secret key from config.
     */
    protected function initStripe(): void
    {
        $secretKey = config('services.stripe.secret_key');

        if (empty($secretKey)) {
            Log::error('Stripe secret key is not configured. Please set STRIPE_SECRET_KEY in your .env file.');
            throw new \RuntimeException('Stripe payment integration is not configured. Please contact the administrator.');
        }

        Stripe::setApiKey($secretKey);
    }

    /**
     * Create a Stripe Checkout session for a single shoot (public, no auth required).
     */
    public function createCheckoutSession(Request $request, Shoot $shoot)
    {
        $amountToPay = (int) round(($shoot->total_quote - $shoot->total_paid) * 100);

        // Allow an optional partial payment amount from the request
        if ($request->has('amount')) {
            $requestedAmount = (int) round($request->input('amount') * 100);
            if ($requestedAmount > 0 && $requestedAmount <= $amountToPay) {
                $amountToPay = $requestedAmount;
            }
        }

        if ($amountToPay <= 0) {
            return response()->json(['error' => 'This shoot is already fully paid or has a zero balance.'], 400);
        }

        try {
            $this->initStripe();

            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
            $currency = config('services.stripe.currency', 'USD');
            $client = User::find($shoot->client_id);

            $sessionParams = [
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'product_data' => [
                            'name' => 'Payment for Shoot at ' . $shoot->address,
                            'metadata' => [
                                'shoot_id' => (string) $shoot->id,
                            ],
                        ],
                        'unit_amount' => $amountToPay,
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

            $sessionParams = $this->applyCheckoutCustomerParams($sessionParams, $client);

            $session = StripeSession::create($sessionParams);

            return response()->json([
                'checkoutUrl' => $session->url,
                'sessionId' => $session->id,
            ]);

        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Stripe createCheckoutSession error', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Could not create payment link. Please try again later.'], 500);
        }
    }

    /**
     * Create an embedded Stripe Checkout session (renders inside app dialog).
     * Returns client_secret for use with @stripe/stripe-js initEmbeddedCheckout.
     */
    public function createEmbeddedCheckoutSession(Request $request, Shoot $shoot)
    {
        $amountToPay = (int) round(($shoot->total_quote - $shoot->total_paid) * 100);

        if ($request->has('amount')) {
            $requestedAmount = (int) round($request->input('amount') * 100);
            if ($requestedAmount > 0 && $requestedAmount <= $amountToPay) {
                $amountToPay = $requestedAmount;
            }
        }

        if ($amountToPay <= 0) {
            return response()->json(['error' => 'This shoot is already fully paid or has a zero balance.'], 400);
        }

        try {
            $this->initStripe();

            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
            $currency = config('services.stripe.currency', 'USD');
            $client = User::find($shoot->client_id);

            $sessionParams = [
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'ui_mode' => 'embedded',
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'product_data' => [
                            'name' => 'Payment for Shoot at ' . $shoot->address,
                            'metadata' => [
                                'shoot_id' => (string) $shoot->id,
                            ],
                        ],
                        'unit_amount' => $amountToPay,
                    ],
                    'quantity' => 1,
                ]],
                'metadata' => [
                    'shoot_id' => (string) $shoot->id,
                    'type' => 'single',
                ],
                'client_reference_id' => 'shoot:' . $shoot->id,
                'return_url' => $frontendUrl . '/payment/' . $shoot->id . '?success=true&session_id={CHECKOUT_SESSION_ID}',
            ];

            $sessionParams = $this->applyCheckoutCustomerParams($sessionParams, $client);

            $session = StripeSession::create($sessionParams);

            return response()->json([
                'clientSecret' => $session->client_secret,
                'sessionId' => $session->id,
            ]);

        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Stripe createEmbeddedCheckoutSession error', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Could not create embedded checkout. Please try again later.'], 500);
        }
    }

    /**
     * Create a Stripe Checkout session for multiple shoots (auth required).
     */
    public function payMultipleShoots(Request $request)
    {
        $validated = $request->validate([
            'shoot_ids' => 'required|array|min:1',
            'shoot_ids.*' => 'exists:shoots,id',
        ]);

        try {
            $this->initStripe();

            $shoots = Shoot::whereIn('id', $validated['shoot_ids'])->get();

            if ($shoots->isEmpty()) {
                return response()->json(['error' => 'No valid shoots found'], 400);
            }

            $totalAmount = 0;
            $lineItems = [];
            $shootIds = [];

            foreach ($shoots as $shoot) {
                $amountToPay = (int) round(($shoot->total_quote - $shoot->total_paid) * 100);
                if ($amountToPay <= 0) continue;

                $totalAmount += $amountToPay;
                $shootIds[] = (string) $shoot->id;

                $lineItems[] = [
                    'price_data' => [
                        'currency' => strtolower(config('services.stripe.currency', 'USD')),
                        'product_data' => [
                            'name' => 'Payment for Shoot at ' . $shoot->address,
                            'metadata' => [
                                'shoot_id' => (string) $shoot->id,
                            ],
                        ],
                        'unit_amount' => $amountToPay,
                    ],
                    'quantity' => 1,
                ];
            }

            if ($totalAmount <= 0) {
                return response()->json(['error' => 'All selected shoots are already fully paid'], 400);
            }

            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
            $sessionParams = [
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'line_items' => $lineItems,
                'metadata' => [
                    'shoot_ids' => implode(',', $shootIds),
                    'type' => 'multiple',
                ],
                'client_reference_id' => 'shoots:' . implode(',', $shootIds),
                'success_url' => $frontendUrl . '/shoot-history?payment=success&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => $frontendUrl . '/shoot-history',
            ];

            $sessionParams = $this->applyCheckoutCustomerParams($sessionParams, $request->user());

            $session = StripeSession::create($sessionParams);

            return response()->json([
                'checkoutUrl' => $session->url,
                'totalAmount' => $totalAmount / 100,
                'shootCount' => count($shootIds),
            ]);

        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Stripe payMultipleShoots error', [
                'shoot_ids' => $validated['shoot_ids'],
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Could not create payment link. Please try again later.'], 500);
        }
    }

    /**
     * Create an embedded Stripe Checkout session for multiple shoots (auth required).
     * Returns client_secret for use with @stripe/stripe-js initEmbeddedCheckout.
     */
    public function payMultipleShootsEmbedded(Request $request)
    {
        $validated = $request->validate([
            'shoot_ids' => 'required|array|min:1',
            'shoot_ids.*' => 'exists:shoots,id',
        ]);

        try {
            $this->initStripe();

            $shoots = Shoot::whereIn('id', $validated['shoot_ids'])->get();

            if ($shoots->isEmpty()) {
                return response()->json(['error' => 'No valid shoots found'], 400);
            }

            $totalAmount = 0;
            $lineItems = [];
            $shootIds = [];

            foreach ($shoots as $shoot) {
                $amountToPay = (int) round(($shoot->total_quote - $shoot->total_paid) * 100);
                if ($amountToPay <= 0) continue;

                $totalAmount += $amountToPay;
                $shootIds[] = (string) $shoot->id;

                $lineItems[] = [
                    'price_data' => [
                        'currency' => strtolower(config('services.stripe.currency', 'USD')),
                        'product_data' => [
                            'name' => 'Payment for Shoot at ' . $shoot->address,
                            'metadata' => [
                                'shoot_id' => (string) $shoot->id,
                            ],
                        ],
                        'unit_amount' => $amountToPay,
                    ],
                    'quantity' => 1,
                ];
            }

            if ($totalAmount <= 0) {
                return response()->json(['error' => 'All selected shoots are already fully paid'], 400);
            }

            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
            $sessionParams = [
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'ui_mode' => 'embedded',
                'line_items' => $lineItems,
                'metadata' => [
                    'shoot_ids' => implode(',', $shootIds),
                    'type' => 'multiple',
                ],
                'client_reference_id' => 'shoots:' . implode(',', $shootIds),
                'return_url' => $frontendUrl . '/shoot-history?payment=success&session_id={CHECKOUT_SESSION_ID}',
            ];

            $sessionParams = $this->applyCheckoutCustomerParams($sessionParams, $request->user());

            $session = StripeSession::create($sessionParams);

            return response()->json([
                'clientSecret' => $session->client_secret,
                'sessionId' => $session->id,
                'totalAmount' => $totalAmount / 100,
                'shootCount' => count($shootIds),
            ]);

        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Stripe payMultipleShootsEmbedded error', [
                'shoot_ids' => $validated['shoot_ids'],
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Could not create embedded checkout. Please try again later.'], 500);
        }
    }

    /**
     * Handle incoming Stripe webhooks.
     * Signature is verified inside this method (no middleware alias needed).
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        if (empty($webhookSecret)) {
            Log::error('Stripe webhook secret is not configured.');
            return response()->json(['error' => 'Webhook not configured'], 500);
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook parse error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        Log::info('Stripe webhook received', ['type' => $event->type, 'id' => $event->id]);

        $handled = false;

        switch ($event->type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                $handled = $this->handleCheckoutCompleted($event->data->object);
                break;

            case 'checkout.session.expired':
                Log::info('Stripe checkout session expired', ['session_id' => $event->data->object->id]);
                break;

            default:
                Log::info('Stripe webhook unhandled event type', ['type' => $event->type]);
        }

        return response()->json(['status' => 'success', 'handled' => $handled], 200);
    }

    public function confirmCheckoutSession(Request $request, Shoot $shoot)
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
        ]);

        try {
            $result = $this->reconcileShootPayments($shoot, $validated['session_id']);

            return response()->json([
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe checkout session confirmation failed', [
                'shoot_id' => $shoot->id,
                'session_id' => $validated['session_id'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Could not confirm Stripe payment session.',
            ], 500);
        }
    }

    public function reconcileShootPayments(Shoot $shoot, ?string $sessionId = null): array
    {
        $shoot = $shoot->fresh(['payments', 'client']) ?? $shoot->loadMissing(['payments', 'client']);
        $summary = $shoot->syncPaymentStatusFromRecords($shoot->payment_type ?: 'stripe');

        if (($summary['remaining_balance'] ?? 0) <= 0) {
            return [
                'reconciled' => false,
                'session_id' => null,
                'total_paid' => $summary['total_paid'],
                'payment_status' => $summary['payment_status'],
                'remaining_balance' => $summary['remaining_balance'],
            ];
        }

        $lock = Cache::lock('stripe_reconcile_shoot_' . $shoot->id, 10);

        if (!$lock->get()) {
            return [
                'reconciled' => false,
                'session_id' => null,
                'total_paid' => $summary['total_paid'],
                'payment_status' => $summary['payment_status'],
                'remaining_balance' => $summary['remaining_balance'],
            ];
        }

        try {
            $session = $sessionId
                ? $this->retrieveCheckoutSession($sessionId)
                : $this->findRecentPaidSessionForShoot($shoot);

            if (!$session) {
                return [
                    'reconciled' => false,
                    'session_id' => null,
                    'total_paid' => $summary['total_paid'],
                    'payment_status' => $summary['payment_status'],
                    'remaining_balance' => $summary['remaining_balance'],
                ];
            }

            $matchedShootId = $session->metadata->shoot_id ?? null;
            if ($matchedShootId !== null && (string) $matchedShootId !== (string) $shoot->id) {
                return [
                    'reconciled' => false,
                    'session_id' => $session->id,
                    'total_paid' => $summary['total_paid'],
                    'payment_status' => $summary['payment_status'],
                    'remaining_balance' => $summary['remaining_balance'],
                ];
            }

            if (($session->payment_status ?? null) !== 'paid') {
                return [
                    'reconciled' => false,
                    'session_id' => $session->id,
                    'total_paid' => $summary['total_paid'],
                    'payment_status' => $summary['payment_status'],
                    'remaining_balance' => $summary['remaining_balance'],
                ];
            }

            $reconciled = $this->handleCheckoutCompleted($session);
            $freshShoot = $shoot->fresh(['payments']) ?? $shoot->loadMissing('payments');
            $freshSummary = $freshShoot->syncPaymentStatusFromRecords('stripe');

            return [
                'reconciled' => $reconciled,
                'session_id' => $session->id,
                'total_paid' => $freshSummary['total_paid'],
                'payment_status' => $freshSummary['payment_status'],
                'remaining_balance' => $freshSummary['remaining_balance'],
            ];
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Handle a completed Stripe Checkout session.
     */
    protected function handleCheckoutCompleted($session)
    {
        $sessionId = $session->id;
        $paymentIntentId = $session->payment_intent;
        $metadata = $session->metadata;

        // Prevent duplicate processing
        if ($this->hasProcessedSession($sessionId, $paymentIntentId)) {
            Log::info('Stripe webhook: Session already processed', ['session_id' => $sessionId]);
            return false;
        }

        $type = $metadata->type ?? 'single';

        if ($type === 'multiple') {
            return $this->processMultipleShootPayment($session);
        }

        return $this->processSingleShootPayment($session);
    }

    /**
     * Process payment for a single shoot.
     */
    protected function processSingleShootPayment($session)
    {
        $sessionId = $session->id;
        $paymentIntentId = $session->payment_intent;
        $shootId = $session->metadata->shoot_id ?? null;
        $amountTotal = ($session->amount_total ?? 0) / 100;
        $currency = strtoupper($session->currency ?? 'usd');

        if (!$shootId) {
            Log::warning('Stripe webhook: No shoot_id in session metadata', ['session_id' => $sessionId]);
            return false;
        }

        try {
            return DB::transaction(function () use ($shootId, $paymentIntentId, $amountTotal, $currency, $sessionId) {
                $shoot = Shoot::find($shootId);

                if (!$shoot) {
                    Log::warning('Stripe webhook: Shoot not found', ['shoot_id' => $shootId]);
                    return false;
                }

                // Double-check for duplicates inside transaction
                if ($this->hasProcessedSession($sessionId, $paymentIntentId)) {
                    return false;
                }

                $payment = Payment::create([
                    'shoot_id' => $shoot->id,
                    'amount' => $amountTotal,
                    'currency' => $currency,
                    'payment_method' => 'stripe',
                    'stripe_payment_id' => $paymentIntentId,
                    'stripe_session_id' => $sessionId,
                    'status' => Payment::STATUS_COMPLETED,
                    'processed_at' => now(),
                ]);

                $this->updateShootPaymentStatus($shoot, $payment, $amountTotal);

                return true;
            });
        } catch (\Exception $e) {
            Log::error('Stripe webhook single shoot processing error', [
                'session_id' => $sessionId,
                'shoot_id' => $shootId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Process payment for multiple shoots.
     */
    protected function processMultipleShootPayment($session)
    {
        $sessionId = $session->id;
        $paymentIntentId = $session->payment_intent;
        $shootIdsStr = $session->metadata->shoot_ids ?? '';
        $currency = strtoupper($session->currency ?? 'usd');

        if (empty($shootIdsStr)) {
            Log::warning('Stripe webhook: No shoot_ids in session metadata', ['session_id' => $sessionId]);
            return false;
        }

        $shootIds = array_filter(explode(',', $shootIdsStr));

        try {
            // Retrieve the session's line items to get per-shoot amounts
            $this->initStripe();
            $lineItems = StripeSession::allLineItems($sessionId, ['limit' => 100]);

            return DB::transaction(function () use ($shootIds, $paymentIntentId, $currency, $sessionId, $lineItems) {
                // Double-check for duplicates inside transaction
                if ($this->hasProcessedSession($sessionId, $paymentIntentId)) {
                    return false;
                }

                $shoots = Shoot::whereIn('id', $shootIds)->get()->keyBy('id');

                // Map line items to shoots by order
                $lineItemsList = $lineItems->data ?? [];
                foreach ($shootIds as $index => $shootId) {
                    $shoot = $shoots->get($shootId);
                    if (!$shoot) {
                        Log::warning('Stripe webhook: Shoot not found in multi-pay', ['shoot_id' => $shootId]);
                        continue;
                    }

                    // Get the corresponding line item amount, or fall back to outstanding
                    $lineItem = $lineItemsList[$index] ?? null;
                    $amount = $lineItem ? ($lineItem->amount_total / 100) : ($shoot->total_quote - $shoot->total_paid);

                    $payment = Payment::create([
                        'shoot_id' => $shoot->id,
                        'amount' => $amount,
                        'currency' => $currency,
                        'payment_method' => 'stripe',
                        'stripe_payment_id' => $paymentIntentId,
                        'stripe_session_id' => $sessionId . '_shoot_' . $shoot->id,
                        'status' => Payment::STATUS_COMPLETED,
                        'processed_at' => now(),
                    ]);

                    $this->updateShootPaymentStatus($shoot, $payment, $amount);
                }

                return true;
            });
        } catch (\Exception $e) {
            Log::error('Stripe webhook multi-shoot processing error', [
                'session_id' => $sessionId,
                'shoot_ids' => $shootIdsStr,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    protected function applyCheckoutCustomerParams(array $sessionParams, ?User $client): array
    {
        if (!$client || !$client->email) {
            return $sessionParams;
        }

        $stripeCustomerId = $this->findOrCreateStripeCustomer($client);

        if ($stripeCustomerId) {
            $sessionParams['customer'] = $stripeCustomerId;
            return $sessionParams;
        }

        $sessionParams['customer_creation'] = 'always';
        $sessionParams['customer_email'] = $client->email;

        return $sessionParams;
    }

    protected function findOrCreateStripeCustomer(User $client): ?string
    {
        $metadata = $client->metadata ?? [];
        $stripeCustomerId = $metadata['stripe_customer_id'] ?? null;

        if (is_string($stripeCustomerId) && $stripeCustomerId !== '') {
            return $stripeCustomerId;
        }

        try {
            $customers = StripeCustomer::all([
                'email' => $client->email,
                'limit' => 1,
            ]);

            $existingCustomer = $customers->data[0] ?? null;
            if ($existingCustomer && !empty($existingCustomer->id)) {
                $this->storeStripeCustomerId($client, $existingCustomer->id);
                return $existingCustomer->id;
            }

            $createdCustomer = StripeCustomer::create([
                'email' => $client->email,
                'name' => $client->name,
                'phone' => $client->phone ?? $client->phonenumber,
                'metadata' => [
                    'user_id' => (string) $client->id,
                    'app_role' => (string) $client->role,
                ],
            ]);

            if (!empty($createdCustomer->id)) {
                $this->storeStripeCustomerId($client, $createdCustomer->id);
                return $createdCustomer->id;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to resolve Stripe customer for checkout session', [
                'user_id' => $client->id,
                'email' => $client->email,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    protected function storeStripeCustomerId(User $client, string $stripeCustomerId): void
    {
        $metadata = $client->metadata ?? [];

        if (($metadata['stripe_customer_id'] ?? null) === $stripeCustomerId) {
            return;
        }

        $metadata['stripe_customer_id'] = $stripeCustomerId;
        $client->forceFill([
            'metadata' => $metadata,
        ])->save();
    }

    protected function hasProcessedSession(string $sessionId, ?string $paymentIntentId = null): bool
    {
        return Payment::query()
            ->where(function ($query) use ($sessionId, $paymentIntentId) {
                $query->where('stripe_session_id', $sessionId)
                    ->orWhere('stripe_session_id', 'like', $sessionId . '_shoot_%');

                if (!empty($paymentIntentId)) {
                    $query->orWhere('stripe_payment_id', $paymentIntentId);
                }
            })
            ->exists();
    }

    protected function retrieveCheckoutSession(string $sessionId)
    {
        $this->initStripe();

        return StripeSession::retrieve($sessionId);
    }

    protected function findRecentPaidSessionForShoot(Shoot $shoot)
    {
        $this->initStripe();

        $checkedCount = 0;
        $sessions = StripeSession::all(['limit' => 100]);

        foreach ($sessions->autoPagingIterator() as $session) {
            $checkedCount++;

            if ($checkedCount > 300) {
                break;
            }

            $type = $session->metadata->type ?? 'single';
            $shootId = $session->metadata->shoot_id ?? null;

            if ($type !== 'single') {
                continue;
            }

            if ((string) $shootId !== (string) $shoot->id) {
                continue;
            }

            if (($session->payment_status ?? null) !== 'paid') {
                continue;
            }

            return $this->retrieveCheckoutSession($session->id);
        }

        return null;
    }

    /**
     * Update shoot payment status, log activity, fire automation, send email.
     */
    protected function updateShootPaymentStatus(Shoot $shoot, Payment $payment, float $amount): void
    {
        $oldPaymentStatus = $shoot->payment_status;
        $paymentSummary = $shoot->fresh(['payments'])?->syncPaymentStatusFromRecords('stripe')
            ?? $shoot->syncPaymentStatusFromRecords('stripe');
        $totalPaid = $paymentSummary['total_paid'];
        $newPaymentStatus = $paymentSummary['payment_status'];

        // Clear watermark-sensitive caches so client sees non-watermarked images
        $this->clearShootCachesAfterPayment($shoot);

        // Log payment activity
        $this->activityLogger->log(
            $shoot,
            'payment_received',
            [
                'payment_id' => $payment->id,
                'amount' => $amount,
                'currency' => $payment->currency,
                'total_paid' => $totalPaid,
                'total_quote' => $shoot->total_quote,
                'old_status' => $oldPaymentStatus,
                'new_status' => $newPaymentStatus,
                'provider' => 'stripe',
            ],
            null
        );

        // If fully paid, log completion and fire automation
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

            $context = $this->automationService->buildShootContext($shoot);
            $context['payment'] = $payment;
            $context['payment_id'] = $payment->id;
            $context['payment_status'] = $newPaymentStatus;
            $context['amount_paid'] = $totalPaid;
            $this->automationService->handleEvent('PAYMENT_COMPLETED', $context);
        }

        // Send payment confirmation email
        $client = User::find($shoot->client_id);
        if ($client) {
            try {
                $this->mailService->sendPaymentConfirmationEmail($client, $shoot, $payment);

                $this->activityLogger->log(
                    $shoot,
                    'payment_completion_email_sent',
                    ['recipient' => $client->email],
                    null
                );
            } catch (\Exception $e) {
                Log::error('Failed to send Stripe payment confirmation email', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("Stripe payment for Shoot ID {$shoot->id} processed successfully.", [
            'payment_id' => $payment->id,
            'amount' => $amount,
            'payment_status' => $newPaymentStatus,
        ]);
    }

    /**
     * Clear watermark-sensitive caches after payment status changes.
     */
    protected function clearShootCachesAfterPayment(Shoot $shoot): void
    {
        if ($shoot->client_id) {
            foreach (['', 'raw', 'edited', 'all'] as $type) {
                Cache::forget('shoot_files_' . $shoot->id . '_' . $type . '_' . $shoot->client_id . '_client');
            }
        }
        $user = auth()->user();
        if ($user) {
            foreach (['', 'raw', 'edited', 'all'] as $type) {
                Cache::forget('shoot_files_' . $shoot->id . '_' . $type . '_' . $user->id . '_' . $user->role);
            }
        }
    }

    /**
     * Calculate payment status based on total paid vs total quote.
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
     * Refund a Stripe payment.
     */
    public function refundPayment(Request $request)
    {
        $request->validate([
            'payment_id' => 'required|integer|exists:payments,id',
            'amount' => 'sometimes|numeric|min:0.01',
        ]);

        try {
            $this->initStripe();

            $paymentRecord = Payment::findOrFail($request->input('payment_id'));

            if ($paymentRecord->payment_method !== 'stripe' || empty($paymentRecord->stripe_payment_id)) {
                return response()->json(['error' => 'This payment was not processed via Stripe.'], 400);
            }

            $refundParams = [
                'payment_intent' => $paymentRecord->stripe_payment_id,
            ];

            // If a specific amount is requested (partial refund)
            if ($request->has('amount')) {
                $refundParams['amount'] = (int) round($request->input('amount') * 100);
            }

            $refund = Refund::create($refundParams);

            if (in_array($refund->status, ['succeeded', 'pending'])) {
                $paymentRecord->status = Payment::STATUS_REFUNDED;
                $paymentRecord->save();

                $shoot = $paymentRecord->shoot;
                if ($shoot) {
                    $paymentSummary = $shoot->fresh(['payments'])?->syncPaymentStatusFromRecords('stripe')
                        ?? $shoot->syncPaymentStatusFromRecords('stripe');
                    $totalPaid = $paymentSummary['total_paid'];
                    $newStatus = $paymentSummary['payment_status'];

                    $this->activityLogger->log(
                        $shoot,
                        'payment_refunded',
                        [
                            'payment_id' => $paymentRecord->id,
                            'refund_amount' => $request->input('amount', $paymentRecord->amount),
                            'new_payment_status' => $newStatus,
                            'provider' => 'stripe',
                        ],
                        auth()->user()
                    );

                    $context = $this->automationService->buildShootContext($shoot);
                    $context['payment'] = $paymentRecord;
                    $context['payment_id'] = $paymentRecord->id;
                    $context['refund_amount'] = $request->input('amount', $paymentRecord->amount);
                    $context['payment_status'] = $newStatus;
                    $this->automationService->handleEvent('PAYMENT_REFUNDED', $context);
                }

                Log::info("Stripe refund processed for payment ID: {$paymentRecord->id}");
                return response()->json(['status' => 'success', 'refund' => $refund]);
            }

            return response()->json(['error' => 'Refund was not successful.', 'refund_status' => $refund->status], 400);

        } catch (\Exception $e) {
            Log::error('Stripe refund error', [
                'payment_id' => $request->input('payment_id'),
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Failed to process refund.'], 500);
        }
    }
}
