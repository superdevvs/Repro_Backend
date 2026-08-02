<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\StripePaymentMetadataService;
use App\Services\Payments\PublicPaymentAccessTokenService;
use App\Services\Shoots\ShootServiceItemSupport;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\ShootActivityLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
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
    protected $stripePaymentMetadataService;
    protected $serviceItemSupport;

    public function __construct(
        MailService $mailService,
        ShootActivityLogger $activityLogger,
        AutomationService $automationService,
        StripePaymentMetadataService $stripePaymentMetadataService,
        ShootServiceItemSupport $serviceItemSupport
    )
    {
        $this->mailService = $mailService;
        $this->activityLogger = $activityLogger;
        $this->automationService = $automationService;
        $this->stripePaymentMetadataService = $stripePaymentMetadataService;
        $this->serviceItemSupport = $serviceItemSupport;
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
        $shoot = $shoot->fresh(['payments']) ?? $shoot->loadMissing('payments');
        $allocationPayload = $this->buildAllocationPayloadFromRequest($request);
        $amountToPay = $this->resolveCheckoutAmountCents($shoot, $request, $allocationPayload);

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

            $paymentUrl = app(PublicPaymentAccessTokenService::class)->buildPublicUrl($shoot);
            $currency = config('services.stripe.currency', 'USD');
            $client = User::find($shoot->client_id);
            $metadata = array_merge([
                'shoot_id' => (string) $shoot->id,
                'type' => 'single',
            ], $this->buildStripeAllocationMetadata($allocationPayload));

            $sessionParams = [
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'product_data' => [
                            'name' => $this->buildCheckoutLineItemNameForShoot($shoot),
                            'metadata' => [
                                'shoot_id' => (string) $shoot->id,
                            ],
                        ],
                        'unit_amount' => $amountToPay,
                    ],
                    'quantity' => 1,
                ]],
                'payment_intent_data' => $this->buildPaymentIntentDataForSingleShoot($shoot, $metadata),
                'metadata' => $metadata,
                'client_reference_id' => 'shoot:' . $shoot->id,
                'success_url' => $paymentUrl . '?success=true&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => $paymentUrl,
            ];

            $sessionParams = $this->applyCheckoutCustomerParams($sessionParams, $client);

            $session = StripeSession::create($sessionParams);

            return response()->json([
                'checkoutUrl' => $session->url,
                'sessionId' => $session->id,
            ]);

        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        } catch (ValidationException $e) {
            throw $e;
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
        $shoot = $shoot->fresh(['payments']) ?? $shoot->loadMissing('payments');
        $allocationPayload = $this->buildAllocationPayloadFromRequest($request);
        $amountToPay = $this->resolveCheckoutAmountCents($shoot, $request, $allocationPayload);
        $returnTo = $this->sanitizeReturnTo($request->input('return_to'));

        if ($amountToPay <= 0) {
            return response()->json(['error' => 'This shoot is already fully paid or has a zero balance.'], 400);
        }

        try {
            $this->initStripe();

            $paymentUrl = app(PublicPaymentAccessTokenService::class)->buildPublicUrl($shoot);
            $currency = config('services.stripe.currency', 'USD');
            $client = User::find($shoot->client_id);

            $metadata = [
                'shoot_id' => (string) $shoot->id,
                'type' => 'single',
            ] + $this->buildStripeAllocationMetadata($allocationPayload);

            if ($returnTo) {
                $metadata['return_to'] = $returnTo;
            }

            $sessionParams = [
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'ui_mode' => 'embedded',
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'product_data' => [
                            'name' => $this->buildCheckoutLineItemNameForShoot($shoot),
                            'metadata' => [
                                'shoot_id' => (string) $shoot->id,
                            ],
                        ],
                        'unit_amount' => $amountToPay,
                    ],
                    'quantity' => 1,
                ]],
                'payment_intent_data' => $this->buildPaymentIntentDataForSingleShoot($shoot, $metadata),
                'metadata' => $metadata,
                'client_reference_id' => 'shoot:' . $shoot->id,
                'return_url' => $this->buildEmbeddedReturnUrl($shoot, $returnTo, $paymentUrl),
            ];

            $sessionParams = $this->applyCheckoutCustomerParams($sessionParams, $client);

            $session = StripeSession::create($sessionParams);

            return response()->json([
                'clientSecret' => $session->client_secret,
                'sessionId' => $session->id,
            ]);

        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        } catch (ValidationException $e) {
            throw $e;
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
                $shoot->loadMissing('payments');
                $amountToPay = $this->calculateCanonicalOutstandingAmountCents($shoot);
                if ($amountToPay <= 0) continue;

                $totalAmount += $amountToPay;
                $shootIds[] = (string) $shoot->id;

                $lineItems[] = [
                    'price_data' => [
                        'currency' => strtolower(config('services.stripe.currency', 'USD')),
                        'product_data' => [
                            'name' => $this->buildCheckoutLineItemNameForShoot($shoot),
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
                'payment_intent_data' => $this->buildPaymentIntentDataForMultipleShoots($shoots),
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
                $shoot->loadMissing('payments');
                $amountToPay = $this->calculateCanonicalOutstandingAmountCents($shoot);
                if ($amountToPay <= 0) continue;

                $totalAmount += $amountToPay;
                $shootIds[] = (string) $shoot->id;

                $lineItems[] = [
                    'price_data' => [
                        'currency' => strtolower(config('services.stripe.currency', 'USD')),
                        'product_data' => [
                            'name' => $this->buildCheckoutLineItemNameForShoot($shoot),
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
                'payment_intent_data' => $this->buildPaymentIntentDataForMultipleShoots($shoots),
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

    public function createPublicEmbeddedCheckoutSession(Request $request, string $token)
    {
        $accessToken = app(PublicPaymentAccessTokenService::class)->resolveAccessibleToken($token);
        if (!$accessToken) {
            return response()->json(['error' => 'This payment link is unavailable.'], 410);
        }

        $accessToken->markAccessed();

        return $this->createEmbeddedCheckoutSession($request, $accessToken->shoot);
    }

    public function confirmPublicCheckoutSession(Request $request, string $token)
    {
        $accessToken = app(PublicPaymentAccessTokenService::class)->resolveAccessibleToken($token);
        if (!$accessToken) {
            return response()->json(['error' => 'This payment link is unavailable.'], 410);
        }

        $accessToken->markAccessed();

        return $this->confirmCheckoutSession($request, $accessToken->shoot);
    }

    public function reconcileShootPayments(Shoot $shoot, ?string $sessionId = null): array
    {
        $shoot = $shoot->fresh(['payments', 'client']) ?? $shoot->loadMissing(['payments', 'client']);
        $session = $sessionId ? $this->retrieveCheckoutSession($sessionId) : null;
        $resolvedReturnTo = $this->resolveReturnToFromSession($session);
        $lastPaymentAmount = $this->resolveLastPaymentAmountFromSession($session);
        $summary = $shoot->syncPaymentStatusFromRecords($shoot->payment_type ?: 'stripe');

        if (($summary['remaining_balance'] ?? 0) <= 0 && !$session) {
            return [
                'reconciled' => false,
                'session_id' => null,
                'total_paid' => $summary['total_paid'],
                'payment_status' => $summary['payment_status'],
                'remaining_balance' => $summary['remaining_balance'],
                'last_payment_amount' => null,
                'return_to' => null,
                'receipt' => $this->buildReceiptPayloadForShoot($shoot),
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
                'receipt' => $this->buildReceiptPayloadForShoot($shoot),
            ];
        }

        try {
            $session = $session ?: $this->findRecentPaidSessionForShoot($shoot);

            if (!$session) {
                return [
                    'reconciled' => false,
                    'session_id' => null,
                    'total_paid' => $summary['total_paid'],
                    'payment_status' => $summary['payment_status'],
                    'remaining_balance' => $summary['remaining_balance'],
                    'last_payment_amount' => null,
                    'return_to' => null,
                    'receipt' => $this->buildReceiptPayloadForShoot($shoot),
                ];
            }

            $resolvedReturnTo = $this->resolveReturnToFromSession($session);
            $lastPaymentAmount = $this->resolveLastPaymentAmountFromSession($session);

            $matchedShootId = $session->metadata->shoot_id ?? null;
            if ($matchedShootId !== null && (string) $matchedShootId !== (string) $shoot->id) {
                return [
                    'reconciled' => false,
                    'session_id' => $session->id,
                    'total_paid' => $summary['total_paid'],
                    'payment_status' => $summary['payment_status'],
                    'remaining_balance' => $summary['remaining_balance'],
                    'last_payment_amount' => $lastPaymentAmount,
                    'return_to' => $resolvedReturnTo,
                    'receipt' => $this->buildReceiptPayloadForShoot($shoot),
                ];
            }

            if (($session->payment_status ?? null) !== 'paid') {
                return [
                    'reconciled' => false,
                    'session_id' => $session->id,
                    'total_paid' => $summary['total_paid'],
                    'payment_status' => $summary['payment_status'],
                    'remaining_balance' => $summary['remaining_balance'],
                    'last_payment_amount' => $lastPaymentAmount,
                    'return_to' => $resolvedReturnTo,
                    'receipt' => $this->buildReceiptPayloadForShoot($shoot),
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
                'last_payment_amount' => $lastPaymentAmount,
                'return_to' => $resolvedReturnTo,
                'receipt' => $this->buildReceiptPayloadForShoot($freshShoot),
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
            return DB::transaction(function () use ($shootId, $paymentIntentId, $amountTotal, $currency, $sessionId, $session) {
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

                $payment = $this->stripePaymentMetadataService->hydratePaymentRecord($payment, $session);
                $this->serviceItemSupport->allocatePayment(
                    $payment,
                    $shoot,
                    $this->buildAllocationPayloadFromStripeMetadata($session->metadata)
                );
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

            return DB::transaction(function () use ($shootIds, $paymentIntentId, $currency, $sessionId, $lineItems, $session) {
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

                    $payment = $this->stripePaymentMetadataService->hydratePaymentRecord($payment, $session);
                    $this->serviceItemSupport->allocatePayment($payment, $shoot, []);
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

    protected function buildAllocationPayloadFromRequest(Request $request): array
    {
        return [
            'shoot_service_ids' => collect($request->input('shoot_service_ids', []))
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'allocations' => is_array($request->input('allocations')) ? $request->input('allocations') : [],
            'allocation_strategy' => $request->input('allocation_strategy'),
        ];
    }

    protected function buildAllocationPayloadFromStripeMetadata(mixed $metadata): array
    {
        $serviceIds = (string) ($metadata->shoot_service_ids ?? '');

        return [
            'shoot_service_ids' => collect(explode(',', $serviceIds))
                ->map(fn ($id) => (int) trim($id))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'allocation_strategy' => $metadata->allocation_strategy ?? null,
        ];
    }

    protected function buildStripeAllocationMetadata(array $payload): array
    {
        $metadata = [];

        if (!empty($payload['shoot_service_ids'])) {
            $metadata['shoot_service_ids'] = implode(',', $payload['shoot_service_ids']);
        }

        if (!empty($payload['allocation_strategy'])) {
            $metadata['allocation_strategy'] = (string) $payload['allocation_strategy'];
        }

        return $metadata;
    }

    protected function resolveCheckoutAmountCents(Shoot $shoot, Request $request, array $allocationPayload): int
    {
        $outstandingCents = $this->calculateCanonicalOutstandingAmountCents($shoot);
        $amountToPay = $outstandingCents;

        if (!empty($allocationPayload['shoot_service_ids'])) {
            $serviceItems = collect($this->serviceItemSupport->summaries($shoot));
            $matchedCount = $serviceItems
                ->whereIn('shoot_service_id', $allocationPayload['shoot_service_ids'])
                ->count();

            if ($matchedCount !== count($allocationPayload['shoot_service_ids'])) {
                throw ValidationException::withMessages([
                    'shoot_service_ids' => ['One or more selected services do not belong to this shoot.'],
                ]);
            }

            $selectedBalance = $serviceItems
                ->whereIn('shoot_service_id', $allocationPayload['shoot_service_ids'])
                ->sum(fn ($item) => (float) ($item['balance_due'] ?? 0));

            $selectedAmountCents = (int) round($selectedBalance * 100);
            if ($selectedAmountCents > 0) {
                $amountToPay = min($selectedAmountCents, $outstandingCents);
            }
        }

        if ($request->has('amount')) {
            $requestedAmount = (int) round(((float) $request->input('amount')) * 100);
            if ($requestedAmount > 0 && $requestedAmount <= $outstandingCents) {
                $amountToPay = $requestedAmount;
            }
        }

        if ($this->serviceItemSupport->requiresExplicitAllocation($shoot, $amountToPay / 100, $allocationPayload)) {
            throw ValidationException::withMessages([
                'amount' => ['Custom partial payments must target selected services or an allocation strategy.'],
            ]);
        }

        return $amountToPay;
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

    protected function buildPaymentIntentDataForSingleShoot(Shoot $shoot, array $metadata = []): array
    {
        return [
            'description' => $this->buildStripePaymentDescriptionForSingleShoot($shoot),
            'metadata' => array_merge([
                'shoot_id' => (string) $shoot->id,
                'shoot_address' => $this->formatShootAddress($shoot) ?? '',
            ], $metadata),
        ];
    }

    protected function buildPaymentIntentDataForMultipleShoots($shoots): array
    {
        $shootIds = $shoots->pluck('id')->map(fn ($id) => (string) $id)->values();
        $description = $this->buildStripePaymentDescriptionForMultipleShoots($shoots);

        return [
            'description' => $description,
            'metadata' => [
                'shoot_ids' => $shootIds->implode(','),
                'shoot_count' => (string) $shootIds->count(),
            ],
        ];
    }

    protected function buildStripePaymentDescriptionForSingleShoot(Shoot $shoot): string
    {
        return $this->formatShootAddress($shoot)
            ?: ('Shoot #' . $shoot->id);
    }

    protected function buildCheckoutLineItemNameForShoot(Shoot $shoot): string
    {
        return $this->formatShootAddress($shoot)
            ?: ('Shoot #' . $shoot->id);
    }

    protected function buildReceiptPayloadForShoot(Shoot $shoot): ?array
    {
        $payments = ($shoot->payments ?? collect())->map(function ($payment) {
            if (!$payment instanceof Payment) {
                return $payment;
            }

            return $this->stripePaymentMetadataService->hydratePaymentRecordIfNeeded($payment);
        });

        $latestReceiptPayment = $this->stripePaymentMetadataService->resolveLatestReceiptPayment($payments);

        return $this->stripePaymentMetadataService->buildReceiptPayload($latestReceiptPayment);
    }

    protected function buildStripePaymentDescriptionForMultipleShoots($shoots): string
    {
        $addresses = $shoots
            ->map(fn (Shoot $shoot) => $this->formatShootAddress($shoot))
            ->filter()
            ->values();

        if ($addresses->isEmpty()) {
            return 'Multiple shoots';
        }

        $firstAddress = $addresses->first();
        $additionalCount = $addresses->count() - 1;

        if ($additionalCount <= 0) {
            return (string) $firstAddress;
        }

        return sprintf('%s (+%d more)', $firstAddress, $additionalCount);
    }

    protected function formatShootAddress(?Shoot $shoot): ?string
    {
        if (!$shoot) {
            return null;
        }

        $parts = array_filter([
            trim((string) ($shoot->address ?? '')),
            trim((string) ($shoot->city ?? '')),
            trim(implode(' ', array_filter([
                trim((string) ($shoot->state ?? '')),
                trim((string) ($shoot->zip ?? '')),
            ]))),
        ]);

        return $parts ? implode(', ', $parts) : null;
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
        $invoice = $this->findClientInvoiceForShoot($shoot);

        $this->syncClientInvoiceFromShootPayment(
            $invoice,
            $shoot,
            $payment,
            $totalPaid,
            'stripe',
            $payment->payment_details,
            $payment->processed_at instanceof Carbon ? $payment->processed_at : now()
        );

        // Clear watermark-sensitive caches so client sees non-watermarked images
        $this->clearShootCachesAfterPayment($shoot);

        // Log payment activity
        $this->activityLogger->log(
            $shoot,
            'payment_received',
            array_merge([
                'payment_id' => $payment->id,
                'amount' => $amount,
                'currency' => $payment->currency,
                'total_paid' => $totalPaid,
                'total_quote' => $shoot->total_quote,
                'old_status' => $oldPaymentStatus,
                'new_status' => $newPaymentStatus,
                'provider' => 'stripe',
            ], $this->stripePaymentMetadataService->buildActivityMetadata($payment)),
            null
        );

        // If fully paid, log completion and fire automation
        if ($newPaymentStatus === 'paid' && $oldPaymentStatus !== 'paid') {
            app(PublicPaymentAccessTokenService::class)->revokeTokensForShoot($shoot);

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
            $paymentCompletedDispatch = $this->automationService->handleEvent('PAYMENT_COMPLETED', $context);
        }

        // Send payment confirmation email fallback only when no automation is active.
        $client = User::find($shoot->client_id);
        if (
            $client
            && $this->automationService->shouldUseFallback(
                'PAYMENT_COMPLETED',
                $paymentCompletedDispatch ?? null
            ) !== false
        ) {
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

    protected function calculateCanonicalOutstandingAmountCents(Shoot $shoot): int
    {
        $totalQuote = (float) ($shoot->total_quote ?? 0);
        $totalPaid = $shoot->calculateCanonicalTotalPaid();

        return (int) round(max($totalQuote - $totalPaid, 0) * 100);
    }

    protected function sanitizeReturnTo(mixed $returnTo): ?string
    {
        if (!is_string($returnTo)) {
            return null;
        }

        $trimmed = trim($returnTo);
        if ($trimmed === '' || str_contains($trimmed, "\r") || str_contains($trimmed, "\n")) {
            return null;
        }

        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');

        if (str_starts_with($trimmed, '/')) {
            return $trimmed;
        }

        if (!preg_match('/^[a-z][a-z0-9+\-.]*:/i', $trimmed)) {
            return null;
        }

        $frontendParts = parse_url($frontendUrl);
        $returnParts = parse_url($trimmed);

        if (!$frontendParts || !$returnParts) {
            return null;
        }

        $sameOrigin =
            (($frontendParts['scheme'] ?? null) === ($returnParts['scheme'] ?? null))
            && (($frontendParts['host'] ?? null) === ($returnParts['host'] ?? null))
            && (($frontendParts['port'] ?? null) === ($returnParts['port'] ?? null));

        if (!$sameOrigin) {
            return null;
        }

        $path = $returnParts['path'] ?? '/';
        $query = isset($returnParts['query']) ? '?' . $returnParts['query'] : '';
        $fragment = isset($returnParts['fragment']) ? '#' . $returnParts['fragment'] : '';

        return $path . $query . $fragment;
    }

    protected function buildEmbeddedReturnUrl(Shoot $shoot, ?string $returnTo, string $paymentUrl): string
    {
        if (!$returnTo) {
            return $paymentUrl . '?success=true&session_id={CHECKOUT_SESSION_ID}';
        }

        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');

        return $frontendUrl
            . '/payment-return/shoot/' . $shoot->id
            . '?session_id={CHECKOUT_SESSION_ID}&return_to=' . rawurlencode($returnTo);
    }

    protected function resolveReturnToFromSession($session): ?string
    {
        $metadataReturnTo = $session?->metadata?->return_to ?? null;
        return $this->sanitizeReturnTo($metadataReturnTo);
    }

    protected function resolveLastPaymentAmountFromSession($session): ?float
    {
        $amountTotal = $session?->amount_total ?? null;
        if (!is_numeric($amountTotal)) {
            return null;
        }

        return round(((float) $amountTotal) / 100, 2);
    }

    protected function findClientInvoiceForShoot(Shoot $shoot): ?Invoice
    {
        return Invoice::query()
            ->where('shoot_id', $shoot->id)
            ->where(function ($query) use ($shoot) {
                $query->where('role', Invoice::ROLE_CLIENT);

                if ($shoot->client_id) {
                    $query->orWhere('client_id', $shoot->client_id);
                }
            })
            ->orderByDesc('id')
            ->first();
    }

    protected function syncClientInvoiceFromShootPayment(
        ?Invoice $invoice,
        Shoot $shoot,
        ?Payment $payment,
        float $shootTotalPaid,
        ?string $paymentMethod,
        mixed $paymentDetails,
        Carbon $processedAt
    ): void {
        if (!$invoice) {
            return;
        }

        $invoiceTotal = (float) ($invoice->total ?? $invoice->total_amount ?? $shoot->total_quote ?? 0);
        $amountPaid = round(min($shootTotalPaid, $invoiceTotal > 0 ? $invoiceTotal : $shootTotalPaid), 2);
        $isPaid = $invoiceTotal > 0
            ? $amountPaid >= ($invoiceTotal - 0.01)
            : $amountPaid > 0;

        $invoice->amount_paid = $amountPaid;
        $invoice->is_paid = $isPaid;
        $invoice->status = $isPaid
            ? Invoice::STATUS_PAID
            : (($invoice->status ?? Invoice::STATUS_SENT) === Invoice::STATUS_DRAFT
                ? Invoice::STATUS_SENT
                : ($invoice->status ?? Invoice::STATUS_SENT));
        $invoice->paid_at = $isPaid ? $processedAt : null;

        if ($paymentMethod !== null && $paymentMethod !== '') {
            $invoice->payment_method = $paymentMethod;
            $invoice->payment_details = is_array($paymentDetails) ? $paymentDetails : null;
        }

        $invoice->save();

        if ($payment && (int) $payment->invoice_id !== (int) $invoice->id) {
            $payment->invoice_id = $invoice->id;
            $payment->save();
        }
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
        if ($totalQuote <= 0.01) {
            return 'paid';
        }

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

            // Partial refunds are supported, so "already refunded" now means the
            // payment has been refunded in FULL. A payment with a partial refund
            // against it stays refundable up to its remainder.
            if ($paymentRecord->isFullyRefunded()) {
                return response()->json(['error' => 'This Stripe payment has already been fully refunded.'], 400);
            }

            $remainder = $paymentRecord->refundableRemainder();
            $refundAmount = round((float) $request->input('amount', $remainder), 2);

            if ($refundAmount <= 0) {
                return response()->json(['error' => 'Refund amount must be greater than zero.'], 422);
            }

            if ($refundAmount > ($remainder + 0.01)) {
                return response()->json([
                    'error' => 'Refund amount cannot exceed the unrefunded remainder of this payment.',
                    'refundable_remainder' => $remainder,
                ], 422);
            }

            $refundParams = [
                'payment_intent' => $paymentRecord->stripe_payment_id,
                'amount' => (int) round($refundAmount * 100),
            ];

            $refund = Refund::create($refundParams);

            if (in_array($refund->status, ['succeeded', 'pending'])) {
                $responsePayload = DB::transaction(function () use ($paymentRecord, $refund, $refundAmount, $request) {
                    // Record the refund as its own row. This is what makes the
                    // paid total correct for partial refunds: the payment keeps
                    // contributing its remainder instead of dropping out entirely.
                    $paymentRecord->refunds()->create([
                        'shoot_id' => $paymentRecord->shoot_id,
                        'amount' => $refundAmount,
                        'provider' => 'stripe',
                        'provider_refund_id' => $refund->id ?? null,
                        'reason' => $request->input('reason'),
                        'created_by' => $request->user()?->id,
                    ]);
                    $paymentRecord->load('refunds');

                    // Only a fully returned payment stops counting as completed.
                    if ($paymentRecord->isFullyRefunded()) {
                        $paymentRecord->status = Payment::STATUS_REFUNDED;
                    }

                    $paymentRecord->payment_details = $this->stripePaymentMetadataService->mergeRefundDetails(
                        $paymentRecord,
                        $refund,
                        $refundAmount
                    );
                    $paymentRecord->save();
                    $paymentRecord = $paymentRecord->fresh(['refunds']) ?? $paymentRecord;

                    $shoot = $paymentRecord->shoot;
                    $payload = [
                        'payment' => $this->stripePaymentMetadataService->serializePayment($paymentRecord),
                    ];

                    if ($shoot) {
                        $paymentSummary = $shoot->fresh(['payments'])?->syncPaymentStatusFromRecords('stripe')
                            ?? $shoot->syncPaymentStatusFromRecords('stripe');
                        $totalPaid = $paymentSummary['total_paid'];
                        $newStatus = $paymentSummary['payment_status'];
                        $invoice = $this->findClientInvoiceForShoot($shoot);
                        $refundProcessedAt = Carbon::parse($paymentRecord->payment_details['refunded_at'] ?? now()->toIso8601String());

                        $this->syncClientInvoiceFromShootPayment(
                            $invoice,
                            $shoot,
                            $paymentRecord,
                            $totalPaid,
                            'stripe',
                            $paymentRecord->payment_details,
                            $refundProcessedAt
                        );

                        $this->clearShootCachesAfterPayment($shoot);

                        $this->activityLogger->log(
                            $shoot,
                            'payment_refunded',
                            array_merge([
                                'payment_id' => $paymentRecord->id,
                                'refund_amount' => $refundAmount,
                                'new_payment_status' => $newStatus,
                                'provider' => 'stripe',
                            ], $this->stripePaymentMetadataService->buildActivityMetadata($paymentRecord)),
                            auth()->user()
                        );

                        $context = $this->automationService->buildShootContext($shoot);
                        $context['payment'] = $paymentRecord;
                        $context['payment_id'] = $paymentRecord->id;
                        $context['refund_amount'] = $refundAmount;
                        $context['payment_status'] = $newStatus;
                        $this->automationService->handleEvent('PAYMENT_REFUNDED', $context);

                        $payload = array_merge($payload, [
                            'total_paid' => $totalPaid,
                            'payment_status' => $newStatus,
                            'receipt' => $this->buildReceiptPayloadForShoot($shoot->fresh(['payments']) ?? $shoot->loadMissing('payments')),
                        ]);
                    }

                    return $payload;
                });

                Log::info("Stripe refund processed for payment ID: {$paymentRecord->id}");

                return response()->json([
                    'status' => 'success',
                    'refund' => $refund,
                    'data' => $responsePayload,
                ]);
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
