<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\StripeCheckoutAttempt;
use App\Models\User;
use App\Services\Invoices\InvoiceAdjustmentService;
use App\Services\Invoices\InvoiceAuthorizationService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Payments\PublicPaymentAccessTokenService;
use App\Services\Payments\StripePaymentMetadataService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootServiceItemSupport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Customer as StripeCustomer;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\Webhook;

class StripePaymentController extends Controller
{
    private const CHECKOUT_OUTCOME_PROCESSED = 'processed';

    private const CHECKOUT_OUTCOME_ALREADY_PROCESSED = 'already_processed';

    private const CHECKOUT_OUTCOME_UNPAID = 'unpaid';

    private const CHECKOUT_OUTCOME_MISMATCH = 'mismatch';

    private const CHECKOUT_OUTCOME_BUSY = 'busy';

    private const CHECKOUT_OUTCOME_NOT_FOUND = 'not_found';

    private const CHECKOUT_OUTCOME_FAILED = 'failed';

    private const CHECKOUT_OUTCOME_REFUNDED_STALE = 'refunded_stale';

    private const CHECKOUT_LIFECYCLE_LOCK = 'stripe_checkout_attempt_creation';

    private const CHECKOUT_LIFECYCLE_LOCK_SECONDS = 180;

    private const CHECKOUT_SESSION_EXPIRY_MINUTES = 120;

    protected $mailService;

    protected $activityLogger;

    protected $automationService;

    protected $stripePaymentMetadataService;

    protected $serviceItemSupport;

    protected $invoiceAdjustments;

    protected $invoiceAuthorization;

    protected $authorizationSupport;

    public function __construct(
        MailService $mailService,
        ShootActivityLogger $activityLogger,
        AutomationService $automationService,
        StripePaymentMetadataService $stripePaymentMetadataService,
        ShootServiceItemSupport $serviceItemSupport,
        InvoiceAdjustmentService $invoiceAdjustments,
        InvoiceAuthorizationService $invoiceAuthorization,
        ShootAuthorizationSupport $authorizationSupport
    ) {
        $this->mailService = $mailService;
        $this->activityLogger = $activityLogger;
        $this->automationService = $automationService;
        $this->stripePaymentMetadataService = $stripePaymentMetadataService;
        $this->serviceItemSupport = $serviceItemSupport;
        $this->invoiceAdjustments = $invoiceAdjustments;
        $this->invoiceAuthorization = $invoiceAuthorization;
        $this->authorizationSupport = $authorizationSupport;
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
        Stripe::setMaxNetworkRetries(2);
    }

    /**
     * Create a hosted Stripe Checkout session for a single shoot.
     */
    public function createCheckoutSession(Request $request, Shoot $shoot)
    {
        $this->validateSingleCheckoutRequest($request);
        $this->assertAuthenticatedActorCanPayForShoot($request, $shoot);

        $shoot = $shoot->fresh(['payments', 'client']) ?? $shoot->loadMissing(['payments', 'client']);
        $this->invoiceAdjustments->assertClientPaymentAllowedForShoot($shoot);
        $client = $shoot->client;
        $this->assertMandatoryStripeDetails($shoot, $client);
        $allocationPayload = $this->buildAllocationPayloadFromRequest($request);
        $amountToPay = $this->resolveCheckoutAmountCents($shoot, $request, $allocationPayload);

        if ($amountToPay <= 0) {
            return response()->json(['error' => 'This shoot is already fully paid or has a zero balance.'], 400);
        }

        try {
            $this->initStripe();

            $paymentUrl = app(PublicPaymentAccessTokenService::class)->buildPublicUrl($shoot);
            $currency = config('services.stripe.currency', 'USD');
            $metadata = $this->buildSingleShootStripeMetadata($shoot, $client, $allocationPayload);

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
                'payment_intent_data' => $this->buildPaymentIntentDataForSingleShoot($shoot, $client, $metadata),
                'metadata' => $metadata,
                'client_reference_id' => 'shoot:'.$shoot->id,
                'success_url' => $paymentUrl.'?success=true&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $paymentUrl,
            ];

            $sessionParams = $this->applyCheckoutCustomerParams($sessionParams, $client);

            $session = $this->createManagedCheckoutSession(
                $sessionParams,
                'single_hosted',
                $client,
                [[
                    'shoot_id' => (int) $shoot->id,
                    'amount_cents' => $amountToPay,
                    'allocation_payload' => $allocationPayload,
                ]]
            );

            return response()->json([
                'checkoutUrl' => $session->url,
                'sessionId' => $session->id,
            ]);

        } catch (\RuntimeException $e) {
            return response()->json(['error' => \App\Services\ApiErrorResponder::publicMessage($e)], 500);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json(['error' => 'Could not create payment link. Please try again later.'], 500);
        }
    }

    /**
     * Create an embedded Stripe Checkout session (renders inside app dialog).
     * Returns client_secret for use with @stripe/stripe-js initEmbeddedCheckout.
     */
    public function createEmbeddedCheckoutSession(Request $request, Shoot $shoot)
    {
        $this->validateSingleCheckoutRequest($request);
        $this->assertAuthenticatedActorCanPayForShoot($request, $shoot);

        $shoot = $shoot->fresh(['payments', 'client']) ?? $shoot->loadMissing(['payments', 'client']);
        $this->invoiceAdjustments->assertClientPaymentAllowedForShoot($shoot);
        $client = $shoot->client;
        $this->assertMandatoryStripeDetails($shoot, $client);
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

            $metadata = $this->buildSingleShootStripeMetadata($shoot, $client, $allocationPayload);

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
                'payment_intent_data' => $this->buildPaymentIntentDataForSingleShoot($shoot, $client, $metadata),
                'metadata' => $metadata,
                'client_reference_id' => 'shoot:'.$shoot->id,
                'return_url' => $this->buildEmbeddedReturnUrl($shoot, $returnTo, $paymentUrl),
            ];

            $sessionParams = $this->applyCheckoutCustomerParams($sessionParams, $client);

            $session = $this->createManagedCheckoutSession(
                $sessionParams,
                'single_embedded',
                $client,
                [[
                    'shoot_id' => (int) $shoot->id,
                    'amount_cents' => $amountToPay,
                    'allocation_payload' => $allocationPayload,
                ]]
            );

            return response()->json([
                'clientSecret' => $session->client_secret,
                'sessionId' => $session->id,
            ]);

        } catch (\RuntimeException $e) {
            return response()->json(['error' => \App\Services\ApiErrorResponder::publicMessage($e)], 500);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json(['error' => 'Could not create embedded checkout. Please try again later.'], 500);
        }
    }

    /**
     * Create a Stripe Checkout session for multiple shoots (auth required).
     */
    public function payMultipleShoots(Request $request)
    {
        $validated = $request->validate([
            'shoot_ids' => 'required|array|min:1|max:20',
            'shoot_ids.*' => 'integer|distinct|exists:shoots,id',
        ]);

        try {
            $shootOrder = collect($validated['shoot_ids'])
                ->values()
                ->mapWithKeys(fn ($id, $index) => [(string) $id => $index]);
            $shoots = Shoot::with(['client', 'payments'])
                ->whereIn('id', $validated['shoot_ids'])
                ->get()
                ->sortBy(fn (Shoot $shoot) => $shootOrder[(string) $shoot->id] ?? PHP_INT_MAX)
                ->values();

            if ($shoots->isEmpty()) {
                return response()->json(['error' => 'No valid shoots found'], 400);
            }

            foreach ($shoots as $shoot) {
                $this->assertAuthenticatedActorCanPayForShoot($request, $shoot);
                $this->invoiceAdjustments->assertClientPaymentAllowedForShoot($shoot);
            }

            $client = $this->resolveSingleCheckoutClient($shoots);

            $this->initStripe();

            $totalAmount = 0;
            $lineItems = [];
            $shootIds = [];
            $payableShoots = collect();
            $idempotencyShoots = [];

            foreach ($shoots as $shoot) {
                $amountToPay = $this->calculateCanonicalOutstandingAmountCents($shoot);
                if ($amountToPay <= 0) {
                    continue;
                }

                $this->assertMandatoryStripeDetails($shoot, $shoot->client);
                $totalAmount += $amountToPay;
                $shootIds[] = (string) $shoot->id;
                $payableShoots->push($shoot);
                $idempotencyShoots[] = [
                    'shoot_id' => (int) $shoot->id,
                    'amount_cents' => $amountToPay,
                    'allocation_payload' => [],
                ];

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
            $metadata = $this->buildMultipleShootsStripeMetadata($payableShoots, $client);
            $sessionParams = [
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'line_items' => $lineItems,
                'payment_intent_data' => $this->buildPaymentIntentDataForMultipleShoots($payableShoots, $client, $metadata),
                'metadata' => $metadata,
                'client_reference_id' => $this->buildMultipleShootsClientReference($shootIds),
                'success_url' => $frontendUrl.'/shoot-history?payment=success&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $frontendUrl.'/shoot-history',
            ];

            $sessionParams = $this->applyCheckoutCustomerParams($sessionParams, $client);

            $session = $this->createManagedCheckoutSession(
                $sessionParams,
                'multiple_hosted',
                $client,
                $idempotencyShoots
            );

            return response()->json([
                'checkoutUrl' => $session->url,
                'totalAmount' => $totalAmount / 100,
                'shootCount' => count($shootIds),
            ]);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['error' => \App\Services\ApiErrorResponder::publicMessage($e)], 500);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

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
            'shoot_ids' => 'required|array|min:1|max:20',
            'shoot_ids.*' => 'integer|distinct|exists:shoots,id',
        ]);

        try {
            $shootOrder = collect($validated['shoot_ids'])
                ->values()
                ->mapWithKeys(fn ($id, $index) => [(string) $id => $index]);
            $shoots = Shoot::with(['client', 'payments'])
                ->whereIn('id', $validated['shoot_ids'])
                ->get()
                ->sortBy(fn (Shoot $shoot) => $shootOrder[(string) $shoot->id] ?? PHP_INT_MAX)
                ->values();

            if ($shoots->isEmpty()) {
                return response()->json(['error' => 'No valid shoots found'], 400);
            }

            foreach ($shoots as $shoot) {
                $this->assertAuthenticatedActorCanPayForShoot($request, $shoot);
                $this->invoiceAdjustments->assertClientPaymentAllowedForShoot($shoot);
            }

            $client = $this->resolveSingleCheckoutClient($shoots);

            $this->initStripe();

            $totalAmount = 0;
            $lineItems = [];
            $shootIds = [];
            $payableShoots = collect();
            $idempotencyShoots = [];

            foreach ($shoots as $shoot) {
                $amountToPay = $this->calculateCanonicalOutstandingAmountCents($shoot);
                if ($amountToPay <= 0) {
                    continue;
                }

                $this->assertMandatoryStripeDetails($shoot, $shoot->client);
                $totalAmount += $amountToPay;
                $shootIds[] = (string) $shoot->id;
                $payableShoots->push($shoot);
                $idempotencyShoots[] = [
                    'shoot_id' => (int) $shoot->id,
                    'amount_cents' => $amountToPay,
                    'allocation_payload' => [],
                ];

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
            $metadata = $this->buildMultipleShootsStripeMetadata($payableShoots, $client);
            $sessionParams = [
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'ui_mode' => 'embedded',
                'line_items' => $lineItems,
                'payment_intent_data' => $this->buildPaymentIntentDataForMultipleShoots($payableShoots, $client, $metadata),
                'metadata' => $metadata,
                'client_reference_id' => $this->buildMultipleShootsClientReference($shootIds),
                'return_url' => $frontendUrl.'/shoot-history?payment=success&session_id={CHECKOUT_SESSION_ID}',
            ];

            $sessionParams = $this->applyCheckoutCustomerParams($sessionParams, $client);

            $session = $this->createManagedCheckoutSession(
                $sessionParams,
                'multiple_embedded',
                $client,
                $idempotencyShoots
            );

            return response()->json([
                'clientSecret' => $session->client_secret,
                'sessionId' => $session->id,
                'totalAmount' => $totalAmount / 100,
                'shootCount' => count($shootIds),
            ]);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['error' => \App\Services\ApiErrorResponder::publicMessage($e)], 500);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

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
        $outcome = null;

        switch ($event->type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                $outcome = $this->handleCheckoutCompleted($event->data->object);
                $handled = in_array($outcome, [
                    self::CHECKOUT_OUTCOME_PROCESSED,
                    self::CHECKOUT_OUTCOME_ALREADY_PROCESSED,
                    self::CHECKOUT_OUTCOME_REFUNDED_STALE,
                ], true);

                if (in_array($outcome, [self::CHECKOUT_OUTCOME_BUSY, self::CHECKOUT_OUTCOME_FAILED], true)) {
                    return response()->json([
                        'status' => 'retry',
                        'handled' => false,
                        'outcome' => $outcome,
                    ], 500);
                }
                break;

            case 'checkout.session.async_payment_failed':
                $outcome = 'payment_failed';
                Log::warning('Stripe asynchronous checkout payment failed.', [
                    'session_id' => $event->data->object->id ?? null,
                ]);
                $attemptId = (int) data_get($event->data->object, 'metadata.checkout_attempt_id', 0);
                if ($attemptId > 0) {
                    StripeCheckoutAttempt::whereKey($attemptId)
                        ->whereNotIn('status', [
                            StripeCheckoutAttempt::STATUS_PAID,
                            StripeCheckoutAttempt::STATUS_REFUNDED,
                        ])
                        ->update([
                            'status' => StripeCheckoutAttempt::STATUS_FAILED,
                            'failure_message' => 'Stripe reported an asynchronous payment failure.',
                        ]);
                }
                break;

            case 'refund.created':
            case 'refund.updated':
            case 'refund.failed':
                $handled = $this->handleStripeRefundEvent($event->data->object);
                $outcome = $handled ? 'refund_synced' : self::CHECKOUT_OUTCOME_FAILED;

                $refundStatus = strtolower((string) data_get($event->data->object, 'status', ''));
                $refundOperationKey = (string) data_get(
                    $event->data->object,
                    'metadata.app_refund_operation_key',
                    ''
                );
                if ($handled
                    && in_array($refundStatus, ['failed', 'canceled', 'cancelled'], true)
                    && str_starts_with($refundOperationKey, 'checkout_stale_')) {
                    $staleSessionId = trim((string) data_get(
                        $event->data->object,
                        'metadata.checkout_session_id',
                        ''
                    ));
                    $staleSession = $staleSessionId !== ''
                        ? $this->retrieveCheckoutSession($staleSessionId)
                        : null;
                    $handled = $staleSession && $this->reconcileStaleCheckoutRefund($staleSession) === true;
                    $outcome = $handled ? 'stale_checkout_refund_retried' : self::CHECKOUT_OUTCOME_FAILED;
                }

                if (! $handled) {
                    return response()->json([
                        'status' => 'retry',
                        'handled' => false,
                        'outcome' => $outcome,
                    ], 500);
                }
                break;

            case 'checkout.session.expired':
                $outcome = 'expired';
                Log::info('Stripe checkout session expired', ['session_id' => $event->data->object->id]);
                $attemptId = (int) data_get($event->data->object, 'metadata.checkout_attempt_id', 0);
                if ($attemptId > 0) {
                    StripeCheckoutAttempt::whereKey($attemptId)
                        ->whereNotIn('status', [
                            StripeCheckoutAttempt::STATUS_PAID,
                            StripeCheckoutAttempt::STATUS_REFUNDED,
                        ])
                        ->update([
                            'status' => StripeCheckoutAttempt::STATUS_EXPIRED,
                        ]);
                }
                break;

            default:
                $outcome = 'ignored';
                Log::info('Stripe webhook unhandled event type', ['type' => $event->type]);
        }

        return response()->json([
            'status' => 'success',
            'handled' => $handled,
            'outcome' => $outcome,
        ], 200);
    }

    public function confirmCheckoutSession(Request $request, Shoot $shoot)
    {
        $this->assertAuthenticatedActorCanPayForShoot($request, $shoot);
        $this->invoiceAdjustments->assertClientPaymentAllowedForShoot($shoot);

        $validated = $request->validate([
            'session_id' => 'required|string',
        ]);

        try {
            $result = $this->reconcileShootPayments($shoot, $validated['session_id']);

            return response()->json([
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'error' => 'Could not confirm Stripe payment session.',
            ], 500);
        }
    }

    /**
     * Confirm either a single- or multi-shoot Checkout Session after Stripe returns.
     */
    public function confirmPaymentSession(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'required|string|max:255',
        ]);

        try {
            $session = $this->retrieveCheckoutSession($validated['session_id']);
            $shootIds = $this->extractShootIdsFromSession($session);

            if ($shootIds === []) {
                return response()->json([
                    'error' => 'Stripe payment session is not linked to a shoot.',
                ], 422);
            }

            $shoots = Shoot::with(['payments', 'client'])
                ->whereIn('id', $shootIds)
                ->get();

            if ($shoots->count() !== count($shootIds)) {
                return response()->json([
                    'error' => 'One or more shoots linked to this payment session no longer exist.',
                ], 404);
            }

            foreach ($shoots as $linkedShoot) {
                $this->assertAuthenticatedActorCanPayForShoot($request, $linkedShoot);
                $this->invoiceAdjustments->assertClientPaymentAllowedForShoot($linkedShoot);
            }

            $anchorShoot = $shoots->firstWhere('id', (int) $shootIds[0]) ?? $shoots->first();
            $result = $this->reconcileShootPayments(
                $anchorShoot,
                $validated['session_id'],
                $session
            );

            return response()->json(['data' => $result]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'error' => 'Could not confirm Stripe payment session.',
            ], 500);
        }
    }

    public function createPublicEmbeddedCheckoutSession(Request $request, string $token)
    {
        $accessToken = app(PublicPaymentAccessTokenService::class)->resolveAccessibleToken($token);
        if (! $accessToken) {
            return response()->json(['error' => 'This payment link is unavailable.'], 410);
        }

        $accessToken->markAccessed();

        return $this->createEmbeddedCheckoutSession($request, $accessToken->shoot);
    }

    public function confirmPublicCheckoutSession(Request $request, string $token)
    {
        // A successful webhook revokes payment links as soon as the balance is
        // cleared. Stripe can deliver that webhook before the browser returns,
        // so confirmation must still accept the same unexpired token for the
        // exact paid Session. New checkout creation continues to require an
        // active token through resolveAccessibleToken().
        $accessToken = app(PublicPaymentAccessTokenService::class)->findToken($token);
        if (! $accessToken || ! $accessToken->shoot || $accessToken->isExpired()) {
            return response()->json(['error' => 'This payment link is unavailable.'], 410);
        }

        $accessToken->markAccessed();

        return $this->confirmCheckoutSession($request, $accessToken->shoot);
    }

    public function reconcileShootPayments(Shoot $shoot, ?string $sessionId = null, mixed $resolvedSession = null): array
    {
        $shoot = $shoot->fresh(['payments', 'client']) ?? $shoot->loadMissing(['payments', 'client']);
        $session = $resolvedSession ?: ($sessionId ? $this->retrieveCheckoutSession($sessionId) : null);
        $resolvedReturnTo = $this->resolveReturnToFromSession($session);
        $lastPaymentAmount = $this->resolveLastPaymentAmountFromSession($session);
        $summary = $shoot->syncPaymentStatusFromRecords($shoot->payment_type ?: 'stripe');

        if (($summary['remaining_balance'] ?? 0) <= 0 && ! $session) {
            return $this->buildReconciliationResult(
                $shoot,
                $summary,
                self::CHECKOUT_OUTCOME_ALREADY_PROCESSED,
                null,
                true
            );
        }

        $lock = Cache::lock('stripe_reconcile_shoot_'.$shoot->id, 10);

        if (! $lock->get()) {
            return $this->buildReconciliationResult(
                $shoot,
                $summary,
                self::CHECKOUT_OUTCOME_BUSY,
                $session,
                false,
                null,
                $resolvedReturnTo
            );
        }

        try {
            $session = $session ?: $this->findRecentPaidSessionForShoot($shoot);

            if (! $session) {
                return $this->buildReconciliationResult(
                    $shoot,
                    $summary,
                    self::CHECKOUT_OUTCOME_NOT_FOUND
                );
            }

            $resolvedReturnTo = $this->resolveReturnToFromSession($session);
            $lastPaymentAmount = $this->resolveLastPaymentAmountFromSession($session);

            $linkedShootIds = $this->extractShootIdsFromSession($session);
            if (! in_array((string) $shoot->id, $linkedShootIds, true)) {
                return $this->buildReconciliationResult(
                    $shoot,
                    $summary,
                    self::CHECKOUT_OUTCOME_MISMATCH,
                    $session,
                    false,
                    null,
                    $resolvedReturnTo
                );
            }

            if (($session->payment_status ?? null) !== 'paid') {
                return $this->buildReconciliationResult(
                    $shoot,
                    $summary,
                    self::CHECKOUT_OUTCOME_UNPAID,
                    $session,
                    false,
                    null,
                    $resolvedReturnTo
                );
            }

            $outcome = $this->handleCheckoutCompleted($session);
            $freshShoot = $shoot->fresh(['payments']) ?? $shoot->loadMissing('payments');
            $freshSummary = $freshShoot->syncPaymentStatusFromRecords('stripe');
            $paymentRecorded = $this->hasProcessedSession(
                (string) $session->id,
                is_string($session->payment_intent ?? null) ? $session->payment_intent : null
            );

            return $this->buildReconciliationResult(
                $freshShoot,
                $freshSummary,
                $outcome,
                $session,
                $paymentRecorded,
                $paymentRecorded ? $lastPaymentAmount : null,
                $resolvedReturnTo
            );
        } finally {
            optional($lock)->release();
        }
    }

    protected function buildReconciliationResult(
        Shoot $shoot,
        array $summary,
        string $outcome,
        mixed $session = null,
        bool $paymentRecorded = false,
        ?float $lastPaymentAmount = null,
        ?string $returnTo = null
    ): array {
        return [
            'outcome' => $outcome,
            'reconciled' => $outcome === self::CHECKOUT_OUTCOME_PROCESSED,
            'payment_refunded' => $outcome === self::CHECKOUT_OUTCOME_REFUNDED_STALE,
            'message' => $outcome === self::CHECKOUT_OUTCOME_REFUNDED_STALE
                ? 'The invoice balance changed while Checkout was open, so the Stripe charge was refunded. Refresh before paying the current balance.'
                : null,
            'payment_recorded' => $paymentRecorded,
            'session_id' => $session?->id ?? null,
            'session_payment_status' => $session?->payment_status ?? null,
            'total_paid' => $summary['total_paid'],
            'payment_status' => $summary['payment_status'],
            'remaining_balance' => $summary['remaining_balance'],
            'last_payment_amount' => $lastPaymentAmount,
            'return_to' => $returnTo,
            'receipt' => $this->buildReceiptPayloadForShoot($shoot),
            // The paid webhook may revoke the public token before the browser
            // returns. Include the receipt-page snapshot in this exact-session
            // response so partial balances and the property still render.
            'shoot' => $this->buildCheckoutConfirmationShootPayload($shoot, $summary),
        ];
    }

    protected function buildCheckoutConfirmationShootPayload(Shoot $shoot, array $summary): array
    {
        $shoot->loadMissing(['services', 'payments']);
        $payments = $shoot->payments
            ->filter(fn ($payment) => $payment instanceof Payment)
            ->map(fn (Payment $payment) => $this->stripePaymentMetadataService->serializePayment($payment))
            ->values()
            ->all();
        $services = $shoot->services->map(fn ($service) => [
            'name' => (string) $service->name,
            'pivot' => [
                'price' => (float) ($service->pivot->price ?? $service->price ?? 0),
                'quantity' => (int) ($service->pivot->quantity ?? 1),
            ],
        ])->values()->all();
        $invoiceAdjustmentsTotal = collect($this->serviceItemSupport->summaries($shoot))
            ->filter(fn ($item) => (bool) ($item['is_invoice_adjustment'] ?? false))
            ->sum(fn ($item) => (float) ($item['total_amount'] ?? $item['subtotal'] ?? 0));

        return [
            'id' => (int) $shoot->id,
            'address' => (string) $shoot->address,
            'city' => (string) ($shoot->city ?? ''),
            'state' => (string) ($shoot->state ?? ''),
            'zip' => (string) ($shoot->zip ?? ''),
            'scheduled_date' => $shoot->scheduled_date?->toISOString(),
            'time' => $shoot->time,
            'total_quote' => (float) ($shoot->total_quote ?? 0),
            'service_subtotal' => (float) (($shoot->base_quote ?? 0) + ($shoot->discount_amount ?? 0)),
            'base_quote' => (float) ($shoot->base_quote ?? 0),
            'discount_type' => $shoot->discount_type,
            'discount_value' => $shoot->discount_value !== null ? (float) $shoot->discount_value : null,
            'discount_amount' => (float) ($shoot->discount_amount ?? 0),
            'discounted_subtotal' => (float) ($shoot->base_quote ?? 0),
            'invoice_adjustments_total' => round($invoiceAdjustmentsTotal, 2),
            'tax_amount' => (float) ($shoot->tax_amount ?? 0),
            'services' => $services,
            'payments' => $payments,
            'payment_status' => $summary['payment_status'] ?? $shoot->payment_status,
            'amount_due' => (float) ($summary['remaining_balance'] ?? 0),
            'receipt' => $this->buildReceiptPayloadForShoot($shoot),
        ];
    }

    /**
     * Handle a completed Stripe Checkout session.
     */
    protected function handleCheckoutCompleted($session, bool $checkoutLifecycleLockHeld = false): string
    {
        $sessionId = (string) ($session->id ?? '');
        $paymentIntentId = $session->payment_intent ?? null;
        $metadata = $session->metadata;

        if ($sessionId === ''
            || ! is_string($paymentIntentId)
            || $paymentIntentId === ''
            || (isset($session->mode) && $session->mode !== 'payment')
            || (isset($session->status) && $session->status !== 'complete')) {
            Log::error('Stripe checkout session failed final validation.', [
                'session_id' => $sessionId ?: null,
                'mode' => $session->mode ?? null,
                'status' => $session->status ?? null,
                'has_payment_intent' => is_string($paymentIntentId) && $paymentIntentId !== '',
            ]);

            return self::CHECKOUT_OUTCOME_FAILED;
        }

        if (($session->payment_status ?? null) !== 'paid') {
            Log::info('Stripe checkout completed before payment was paid.', [
                'session_id' => $sessionId,
                'payment_status' => $session->payment_status ?? null,
            ]);

            return self::CHECKOUT_OUTCOME_UNPAID;
        }

        $checkoutLifecycleLock = null;
        if (! $checkoutLifecycleLockHeld) {
            $checkoutLifecycleLock = Cache::lock(
                self::CHECKOUT_LIFECYCLE_LOCK,
                self::CHECKOUT_LIFECYCLE_LOCK_SECONDS
            );

            if (! $checkoutLifecycleLock->get()) {
                Log::info('Stripe checkout lifecycle update already in progress.', [
                    'session_id' => $sessionId,
                ]);

                return self::CHECKOUT_OUTCOME_BUSY;
            }
        }

        try {
            if (! $this->validateManagedCheckoutAttempt($session)) {
                return self::CHECKOUT_OUTCOME_FAILED;
            }

            // Browser-return reconciliation and the Stripe webhook can arrive at
            // the same time. Serialize the canonical session finalizer so both
            // paths cannot create competing Payment rows before either commits.
            $finalizationLock = Cache::lock('stripe_checkout_finalize_'.$sessionId, 120);
            if (! $finalizationLock->get()) {
                Log::info('Stripe checkout finalization already in progress.', [
                    'session_id' => $sessionId,
                ]);

                return self::CHECKOUT_OUTCOME_BUSY;
            }

            try {
                // Prevent duplicate processing
                if ($this->hasProcessedSession($sessionId, $paymentIntentId)) {
                    Log::info('Stripe webhook: Session already processed', ['session_id' => $sessionId]);
                    $staleRefundState = $this->reconcileStaleCheckoutRefund($session);
                    if ($staleRefundState === false) {
                        return self::CHECKOUT_OUTCOME_FAILED;
                    }

                    $this->markManagedCheckoutAttemptPaid($session, $staleRefundState === true);

                    return $staleRefundState === true
                        ? self::CHECKOUT_OUTCOME_REFUNDED_STALE
                        : self::CHECKOUT_OUTCOME_ALREADY_PROCESSED;
                }

                $type = (string) data_get($metadata, 'type', 'single');

                if ($type === 'multiple') {
                    $outcome = $this->processMultipleShootPayment($session)
                        ? self::CHECKOUT_OUTCOME_PROCESSED
                        : self::CHECKOUT_OUTCOME_FAILED;
                } else {
                    $outcome = $this->processSingleShootPayment($session)
                        ? self::CHECKOUT_OUTCOME_PROCESSED
                        : self::CHECKOUT_OUTCOME_FAILED;
                }

                if ($outcome === self::CHECKOUT_OUTCOME_PROCESSED) {
                    if ($this->hasStaleCheckoutRefundOperation($session)) {
                        $outcome = self::CHECKOUT_OUTCOME_REFUNDED_STALE;
                    }

                    $this->markManagedCheckoutAttemptPaid(
                        $session,
                        $outcome === self::CHECKOUT_OUTCOME_REFUNDED_STALE
                    );
                }

                return $outcome;
            } finally {
                $finalizationLock->release();
            }
        } finally {
            optional($checkoutLifecycleLock)->release();
        }
    }

    /**
     * Process payment for a single shoot.
     */
    protected function processSingleShootPayment($session)
    {
        $sessionId = $session->id;
        $paymentIntentId = $session->payment_intent;
        $shootId = data_get($session, 'metadata.shoot_id');
        $amountTotal = ($session->amount_total ?? 0) / 100;
        $currency = strtoupper($session->currency ?? 'usd');

        if (! $shootId || ! is_string($paymentIntentId) || $paymentIntentId === '' || $amountTotal <= 0) {
            Log::warning('Stripe webhook: Single-shoot session data is incomplete.', [
                'session_id' => $sessionId,
                'shoot_id' => $shootId,
                'has_payment_intent' => is_string($paymentIntentId) && $paymentIntentId !== '',
                'amount_total' => $amountTotal,
            ]);

            return false;
        }

        try {
            $requiresStaleRefund = false;
            $processed = DB::transaction(function () use (
                $shootId,
                $paymentIntentId,
                $amountTotal,
                $currency,
                $sessionId,
                $session,
                &$requiresStaleRefund
            ) {
                $shoot = Shoot::query()->lockForUpdate()->find($shootId);

                if (! $shoot) {
                    Log::warning('Stripe webhook: Shoot not found', ['shoot_id' => $shootId]);

                    return false;
                }

                $shoot->load('payments.refunds');
                $chargedAmountCents = (int) round($amountTotal * 100);
                $requiresStaleRefund = ! $this->stripeSessionClientMatchesShoots($session, collect([$shoot]))
                    || $shoot->shoot_type === Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT
                    || $chargedAmountCents > $this->calculateCanonicalOutstandingAmountCents($shoot);

                if (! $requiresStaleRefund) {
                    $this->invoiceAdjustments->assertClientPaymentAllowedForShoot($shoot);
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
                $freshShoot = $shoot->fresh(['payments.refunds']);
                $totalPaidCents = (int) round($freshShoot->calculateCanonicalTotalPaid() * 100);
                $totalQuoteCents = (int) round(((float) $freshShoot->total_quote) * 100);
                if ($totalPaidCents > $totalQuoteCents) {
                    $requiresStaleRefund = true;
                }

                if ($requiresStaleRefund) {
                    $payment->refunds()->create([
                        'shoot_id' => $shoot->id,
                        'amount' => $amountTotal,
                        'provider' => 'stripe',
                        'operation_key' => $this->staleCheckoutRefundOperationKey($session, 1),
                        'status' => 'creating',
                        'reason' => 'Automatic refund: invoice balance changed while Stripe Checkout was open.',
                    ]);
                } else {
                    $this->serviceItemSupport->allocatePayment(
                        $payment,
                        $shoot,
                        $this->buildAllocationPayloadFromStripeMetadata($session->metadata)
                    );
                    $this->updateShootPaymentStatus($shoot, $payment, $amountTotal);
                }

                return true;
            });

            if ($processed && $requiresStaleRefund && $this->reconcileStaleCheckoutRefund($session) !== true) {
                return false;
            }

            return $processed;
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

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
        $shootIdsStr = (string) data_get($session, 'metadata.shoot_ids', '');
        $currency = strtoupper($session->currency ?? 'usd');

        if (empty($shootIdsStr)) {
            Log::warning('Stripe webhook: No shoot_ids in session metadata', ['session_id' => $sessionId]);

            return false;
        }

        $shootIds = array_filter(explode(',', $shootIdsStr));
        $shootIds = array_values(array_unique(array_map(
            fn ($shootId) => trim((string) $shootId),
            $shootIds
        )));

        try {
            $requiresStaleRefund = false;
            // Retrieve the session's line items to get per-shoot amounts
            $this->initStripe();
            $lineItems = StripeSession::allLineItems($sessionId, ['limit' => 100]);

            $processed = DB::transaction(function () use (
                $shootIds,
                $paymentIntentId,
                $currency,
                $sessionId,
                $lineItems,
                $session,
                &$requiresStaleRefund
            ) {
                // Double-check for duplicates inside transaction
                if ($this->hasProcessedSession($sessionId, $paymentIntentId)) {
                    return false;
                }

                $shoots = Shoot::whereIn('id', $shootIds)->lockForUpdate()->get()->keyBy('id');

                if ($shoots->count() !== count($shootIds)) {
                    throw new \RuntimeException('One or more shoots in the paid Stripe session no longer exist.');
                }

                // Map line items to shoots by order
                $lineItemsList = $lineItems->data ?? [];
                if (count($lineItemsList) !== count($shootIds)) {
                    throw new \RuntimeException('Stripe line-item count does not match the paid shoot count.');
                }

                $lineItemTotal = collect($lineItemsList)->sum(fn ($lineItem) => (int) ($lineItem->amount_total ?? 0));
                if (is_numeric($session->amount_total ?? null)
                    && $lineItemTotal !== (int) $session->amount_total) {
                    throw new \RuntimeException('Stripe line-item total does not match the Checkout Session total.');
                }

                $attemptId = (int) data_get($session, 'metadata.checkout_attempt_id', 0);
                if ($attemptId > 0) {
                    $attemptItems = StripeCheckoutAttempt::with('items')->find($attemptId)?->items ?? collect();
                    if ($attemptItems->count() !== count($lineItemsList)) {
                        throw new \RuntimeException('Stripe checkout attempt item count does not match the paid Session.');
                    }

                    foreach ($lineItemsList as $index => $lineItem) {
                        $attemptItem = $attemptItems->get($index);
                        if (! $attemptItem
                            || (string) $attemptItem->shoot_id !== (string) $shootIds[$index]
                            || (int) $attemptItem->expected_amount_cents !== (int) ($lineItem->amount_total ?? 0)) {
                            throw new \RuntimeException('Stripe checkout attempt amounts do not match the paid line items.');
                        }
                    }
                }

                $requiresStaleRefund = ! $this->stripeSessionClientMatchesShoots($session, $shoots);
                foreach ($lineItemsList as $index => $lineItem) {
                    $shoot = $shoots->get($shootIds[$index]);
                    $shoot->load('payments.refunds');
                    $lineAmountCents = (int) ($lineItem->amount_total ?? 0);
                    if ($shoot->shoot_type === Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT
                        || $lineAmountCents > $this->calculateCanonicalOutstandingAmountCents($shoot)) {
                        $requiresStaleRefund = true;
                    }
                }

                if (! $requiresStaleRefund) {
                    foreach ($shoots as $shoot) {
                        $this->invoiceAdjustments->assertClientPaymentAllowedForShoot($shoot);
                    }
                }

                $paymentEntries = collect();
                foreach ($shootIds as $index => $shootId) {
                    $shoot = $shoots->get($shootId);

                    $lineItem = $lineItemsList[$index];
                    $amount = ((int) ($lineItem->amount_total ?? 0)) / 100;
                    if ($amount <= 0) {
                        throw new \RuntimeException('Stripe returned a non-positive line-item amount.');
                    }

                    $payment = Payment::create([
                        'shoot_id' => $shoot->id,
                        'amount' => $amount,
                        'currency' => $currency,
                        'payment_method' => 'stripe',
                        'stripe_payment_id' => $paymentIntentId,
                        'stripe_session_id' => $sessionId.'_shoot_'.$shoot->id,
                        'status' => Payment::STATUS_COMPLETED,
                        'processed_at' => now(),
                    ]);

                    $payment = $this->stripePaymentMetadataService->hydratePaymentRecord($payment, $session);
                    $paymentEntries->push([
                        'payment' => $payment,
                        'shoot' => $shoot,
                        'amount' => $amount,
                    ]);
                }

                if ($paymentEntries->count() !== count($shootIds)) {
                    throw new \RuntimeException('Not every paid Stripe line item was recorded.');
                }

                foreach ($paymentEntries as $entry) {
                    $freshShoot = $entry['shoot']->fresh(['payments.refunds']);
                    $totalPaidCents = (int) round($freshShoot->calculateCanonicalTotalPaid() * 100);
                    $totalQuoteCents = (int) round(((float) $freshShoot->total_quote) * 100);
                    if ($totalPaidCents > $totalQuoteCents) {
                        $requiresStaleRefund = true;
                    }
                }

                $createdPayments = collect();
                foreach ($paymentEntries as $entry) {
                    /** @var Payment $payment */
                    $payment = $entry['payment'];
                    /** @var Shoot $shoot */
                    $shoot = $entry['shoot'];
                    $amount = (float) $entry['amount'];
                    if ($requiresStaleRefund) {
                        $payment->refunds()->create([
                            'shoot_id' => $shoot->id,
                            'amount' => $amount,
                            'provider' => 'stripe',
                            'operation_key' => $this->staleCheckoutRefundOperationKey($session, 1),
                            'status' => 'creating',
                            'reason' => 'Automatic refund: invoice balance changed while Stripe Checkout was open.',
                        ]);
                    } else {
                        $this->serviceItemSupport->allocatePayment($payment, $shoot, []);
                        $this->updateShootPaymentStatus($shoot, $payment, $amount, false);
                    }
                    $createdPayments->push($payment->fresh('shoot'));
                }

                $receiptClient = $createdPayments->first()?->shoot?->client;
                if (! $requiresStaleRefund && $receiptClient && $createdPayments->isNotEmpty()) {
                    $this->mailService->sendGroupedPaymentConfirmationEmail($receiptClient, $createdPayments);
                }

                return true;
            });

            if ($processed && $requiresStaleRefund && $this->reconcileStaleCheckoutRefund($session) !== true) {
                return false;
            }

            return $processed;
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return false;
        }
    }

    protected function staleCheckoutRefundOperationPrefix(mixed $session): string
    {
        return 'checkout_stale_'.substr(hash('sha256', implode(':', [
            (string) data_get($session, 'id', ''),
            (string) data_get($session, 'payment_intent', ''),
        ])), 0, 48).'_';
    }

    protected function staleCheckoutRefundOperationKey(mixed $session, int $attempt): string
    {
        return $this->staleCheckoutRefundOperationPrefix($session).max($attempt, 1);
    }

    protected function checkoutSessionPayments(mixed $session)
    {
        $sessionId = trim((string) data_get($session, 'id', ''));
        $paymentIntentId = trim((string) data_get($session, 'payment_intent', ''));

        if ($sessionId === '' || $paymentIntentId === '') {
            return collect();
        }

        return Payment::with('refunds')
            ->where('stripe_payment_id', $paymentIntentId)
            ->where(function ($query) use ($sessionId) {
                $query->where('stripe_session_id', $sessionId)
                    ->orWhere('stripe_session_id', 'like', $sessionId.'_shoot_%');
            })
            ->orderBy('id')
            ->get();
    }

    protected function hasStaleCheckoutRefundOperation(mixed $session): bool
    {
        $prefix = $this->staleCheckoutRefundOperationPrefix($session);

        return $this->checkoutSessionPayments($session)->contains(
            fn (Payment $payment) => $payment->refunds->contains(
                fn ($refund) => str_starts_with((string) $refund->operation_key, $prefix)
            )
        );
    }

    protected function createStaleCheckoutRefundOperations($payments, mixed $session, int $attempt): void
    {
        $operationKey = $this->staleCheckoutRefundOperationKey($session, $attempt);
        $paymentIds = collect($payments)->pluck('id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($paymentIds, $operationKey) {
            $lockedPayments = Payment::with('refunds')
                ->whereIn('id', $paymentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedPayments->count() !== count($paymentIds)) {
                throw new \RuntimeException('A stale Checkout payment disappeared before its refund could be reserved.');
            }

            foreach ($lockedPayments as $payment) {
                if ($payment->refunds->contains(
                    fn ($refund) => (string) $refund->operation_key === $operationKey
                )) {
                    continue;
                }

                $payment->refunds()->create([
                    'shoot_id' => $payment->shoot_id,
                    'amount' => round((float) $payment->amount, 2),
                    'provider' => 'stripe',
                    'operation_key' => $operationKey,
                    'status' => 'creating',
                    'reason' => 'Automatic refund: invoice balance changed while Stripe Checkout was open.',
                ]);
            }
        });
    }

    /**
     * Fully refund a paid Session whose invoice became smaller or changed owner
     * while Checkout was open. Refunding the entire stale charge is deliberate:
     * no historical amount is silently applied to a different current invoice.
     */
    protected function reconcileStaleCheckoutRefund(mixed $session): ?bool
    {
        $payments = $this->checkoutSessionPayments($session);
        if ($payments->isEmpty()) {
            return null;
        }

        $operationPrefix = $this->staleCheckoutRefundOperationPrefix($session);
        $allOperations = $payments->flatMap(
            fn (Payment $payment) => $payment->refunds->filter(
                fn ($refund) => str_starts_with((string) $refund->operation_key, $operationPrefix)
            )
        );
        if ($allOperations->isEmpty()) {
            return null;
        }

        $paymentIntentId = trim((string) data_get($session, 'payment_intent', ''));
        $expectedAmountCents = (int) data_get($session, 'amount_total', 0);
        if ($paymentIntentId === '' || $expectedAmountCents <= 0) {
            return false;
        }

        $refundLock = Cache::lock(
            'stripe_refund_intent_'.hash('sha256', $paymentIntentId),
            180
        );
        if (! $refundLock->get()) {
            return false;
        }

        try {
            $this->initStripe();

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $operationKey = $this->staleCheckoutRefundOperationKey($session, $attempt);
                $operations = $this->checkoutSessionPayments($session)
                    ->flatMap(fn (Payment $payment) => $payment->refunds->where('operation_key', $operationKey))
                    ->values();

                if ($operations->isEmpty()) {
                    $this->createStaleCheckoutRefundOperations($payments, $session, $attempt);
                    $operations = $this->checkoutSessionPayments($session)
                        ->flatMap(fn (Payment $payment) => $payment->refunds->where('operation_key', $operationKey))
                        ->values();
                }

                $operationAmountCents = (int) $operations->sum(
                    fn ($operation) => (int) round(((float) $operation->amount) * 100)
                );
                if ($operations->count() !== $payments->count()
                    || $operationAmountCents !== $expectedAmountCents) {
                    throw new \RuntimeException('The stale Checkout refund reservation does not match the Stripe charge.');
                }

                $statuses = $operations
                    ->map(fn ($operation) => strtolower((string) $operation->status))
                    ->unique();
                if ($statuses->every(fn ($status) => in_array(
                    $status,
                    ['pending', 'requires_action', 'succeeded', 'completed'],
                    true
                ))) {
                    return true;
                }

                if ($statuses->every(fn ($status) => in_array(
                    $status,
                    ['failed', 'canceled', 'cancelled'],
                    true
                ))) {
                    continue;
                }

                $lookupOperation = (object) [
                    'operation_key' => $operationKey,
                    'amount' => round($expectedAmountCents / 100, 2),
                    'created_at' => $operations->min('created_at'),
                ];
                $refund = $this->findStripeRefundForOperation($payments->first(), $lookupOperation);

                if (! $refund && $this->stripeRefundRetryWindowExpired($lookupOperation)) {
                    foreach ($operations as $operation) {
                        $operation->newQuery()
                            ->whereKey($operation->id)
                            ->where('status', 'creating')
                            ->update(['status' => 'failed']);
                    }

                    continue;
                }

                if (! $refund) {
                    try {
                        $refund = Refund::create([
                            'payment_intent' => $paymentIntentId,
                            'amount' => $expectedAmountCents,
                            'metadata' => [
                                'checkout_session_id' => (string) data_get($session, 'id', ''),
                                'app_refund_reason' => 'Automatic stale Checkout refund',
                                'app_refund_operation_key' => $operationKey,
                            ],
                        ], [
                            'idempotency_key' => 'repro_checkout_stale_refund_'.hash('sha256', $operationKey),
                        ]);
                    } catch (\Throwable $exception) {
                        $httpStatus = method_exists($exception, 'getHttpStatus')
                            ? (int) $exception->getHttpStatus()
                            : 0;
                        $isDefinitiveFailure = ($httpStatus >= 400
                                && $httpStatus < 500
                                && ! in_array($httpStatus, [409, 429], true))
                            || ($exception instanceof InvalidRequestException && $httpStatus === 0);

                        if ($isDefinitiveFailure) {
                            foreach ($operations as $operation) {
                                $operation->newQuery()
                                    ->whereKey($operation->id)
                                    ->where('status', 'creating')
                                    ->update(['status' => 'failed']);
                            }
                        }

                        \App\Services\ApiErrorResponder::log($exception, 'error');

                        return false;
                    }
                }

                if (! $this->handleStripeRefundEvent($refund, true)) {
                    return false;
                }

                $refundStatus = strtolower((string) data_get($refund, 'status', 'pending'));
                if (in_array($refundStatus, ['failed', 'canceled', 'cancelled'], true)) {
                    continue;
                }

                return true;
            }

            Log::critical('Automatic stale Checkout refund exhausted its retry attempts.', [
                'session_id' => data_get($session, 'id'),
                'payment_intent_id' => $paymentIntentId,
            ]);

            return false;
        } finally {
            $refundLock->release();
        }
    }

    /**
     * Reconcile refunds created by this app or directly in the Stripe Dashboard.
     */
    protected function handleStripeRefundEvent(mixed $refund, bool $refundLifecycleLockHeld = false): bool
    {
        $refundId = trim((string) data_get($refund, 'id', ''));
        $paymentIntentId = trim((string) data_get($refund, 'payment_intent', ''));
        $status = strtolower(trim((string) data_get($refund, 'status', 'pending')));
        $amountCents = (int) data_get($refund, 'amount', 0);
        $appPaymentId = (int) data_get($refund, 'metadata.app_payment_id', 0);
        $appRefundOperationKey = trim((string) data_get($refund, 'metadata.app_refund_operation_key', ''));

        if ($refundId === '' || $paymentIntentId === '' || $amountCents <= 0) {
            Log::error('Stripe refund event is missing required identifiers.', [
                'refund_id' => $refundId ?: null,
                'payment_intent_id' => $paymentIntentId ?: null,
                'amount' => $amountCents,
            ]);

            return false;
        }

        $refundLifecycleLock = null;
        if (! $refundLifecycleLockHeld) {
            $refundLifecycleLock = Cache::lock(
                'stripe_refund_intent_'.hash('sha256', $paymentIntentId),
                180
            );

            if (! $refundLifecycleLock->get()) {
                Log::info('Stripe refund lifecycle update already in progress.', [
                    'refund_id' => $refundId,
                    'payment_intent_id' => $paymentIntentId,
                ]);

                return false;
            }
        }

        try {
            $postCommitEffects = [];
            $handled = DB::transaction(function () use (
                $paymentIntentId,
                $appPaymentId,
                $amountCents,
                $refund,
                $refundId,
                $status,
                $appRefundOperationKey,
                &$postCommitEffects
            ) {
                // Lock every local row for this PaymentIntent before calculating
                // capacity. Concurrent Dashboard refunds then serialize and
                // cannot reserve the same payment amount twice.
                $payments = Payment::with(['refunds', 'shoot'])
                    ->where('stripe_payment_id', $paymentIntentId)
                    ->when($appPaymentId > 0, fn ($query) => $query->whereKey($appPaymentId))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($payments->isEmpty() || ($appPaymentId > 0 && $payments->count() !== 1)) {
                    Log::error('Stripe refund could not be mapped to an app payment.', [
                        'refund_id' => $refundId,
                        'payment_intent_id' => $paymentIntentId,
                        'app_payment_id' => $appPaymentId ?: null,
                        'matching_payments' => $payments->count(),
                    ]);

                    return false;
                }

                // A multi-shoot Checkout creates one local Payment per line
                // item while Stripe has one shared PaymentIntent. Preserve the
                // first allocation so later status events use the same rows.
                $existingAllocations = $payments->flatMap(function (Payment $payment) use (
                    $refundId,
                    $appRefundOperationKey
                ) {
                    return $payment->refunds
                        ->filter(function ($refundRecord) use ($refundId, $appRefundOperationKey) {
                            if ($refundRecord->provider !== 'stripe') {
                                return false;
                            }

                            return (string) $refundRecord->provider_refund_id === $refundId
                                || ($appRefundOperationKey !== ''
                                    && (string) $refundRecord->operation_key === $appRefundOperationKey);
                        })
                        ->map(fn ($refundRecord) => [
                            'payment_id' => (int) $payment->id,
                            'amount_cents' => (int) round(((float) $refundRecord->amount) * 100),
                        ]);
                })->values();

                $allocations = collect();
                if ($existingAllocations->isNotEmpty()) {
                    if ((int) $existingAllocations->sum('amount_cents') !== $amountCents) {
                        Log::error('Stored Stripe refund allocation no longer matches the provider amount.', [
                            'refund_id' => $refundId,
                            'provider_amount_cents' => $amountCents,
                            'stored_amount_cents' => (int) $existingAllocations->sum('amount_cents'),
                        ]);

                        return false;
                    }

                    $allocations = $existingAllocations;
                } else {
                    $remainingCents = $amountCents;
                    $failedStatuses = ['failed', 'canceled', 'cancelled'];

                    foreach ($payments as $payment) {
                        $reservedCents = in_array($status, $failedStatuses, true)
                            ? 0
                            : (int) $payment->refunds
                                ->filter(function ($refundRecord) use ($refundId, $appRefundOperationKey) {
                                    $isCurrentRefund = (string) $refundRecord->provider_refund_id === $refundId
                                        || ($appRefundOperationKey !== ''
                                            && (string) $refundRecord->operation_key === $appRefundOperationKey);

                                    return ! $isCurrentRefund
                                        && in_array(strtolower((string) ($refundRecord->status ?? 'succeeded')), [
                                            'creating', 'succeeded', 'completed', 'pending', 'requires_action',
                                        ], true);
                                })
                                ->sum(fn ($refundRecord) => (int) round(((float) $refundRecord->amount) * 100));
                        $capacityCents = max(
                            (int) round(((float) $payment->amount) * 100) - $reservedCents,
                            0
                        );
                        $allocatedCents = min($remainingCents, $capacityCents);

                        if ($allocatedCents > 0) {
                            $allocations->push([
                                'payment_id' => (int) $payment->id,
                                'amount_cents' => $allocatedCents,
                            ]);
                            $remainingCents -= $allocatedCents;
                        }

                        if ($remainingCents === 0) {
                            break;
                        }
                    }

                    if ($remainingCents !== 0 || $allocations->isEmpty()) {
                        Log::error('Stripe refund exceeds the locally refundable PaymentIntent amount.', [
                            'refund_id' => $refundId,
                            'payment_intent_id' => $paymentIntentId,
                            'unallocated_amount_cents' => $remainingCents,
                        ]);

                        return false;
                    }
                }

                foreach ($allocations as $allocation) {
                    $lockedPayment = $payments->firstWhere('id', (int) $allocation['payment_id']);
                    if (! $lockedPayment) {
                        throw new \RuntimeException('A payment in the Stripe refund allocation no longer exists.');
                    }

                    $allocatedAmount = round(((int) $allocation['amount_cents']) / 100, 2);
                    $existing = $lockedPayment->refunds()
                        ->where('provider', 'stripe')
                        ->where(function ($query) use ($refundId, $appRefundOperationKey) {
                            $query->where('provider_refund_id', $refundId);
                            if ($appRefundOperationKey !== '') {
                                $query->orWhere('operation_key', $appRefundOperationKey);
                            }
                        })
                        ->lockForUpdate()
                        ->first();
                    $previousStatus = strtolower((string) ($existing?->status ?? ''));
                    $effectiveStatus = $status;
                    $statusRank = [
                        '' => -1,
                        'creating' => -1,
                        'pending' => 0,
                        'requires_action' => 0,
                        'succeeded' => 1,
                        'completed' => 1,
                        // Stripe can exceptionally move a succeeded refund to
                        // failed later, returning funds to the account balance.
                        'failed' => 2,
                        'canceled' => 2,
                        'cancelled' => 2,
                    ];
                    $previousRank = $statusRank[$previousStatus] ?? 0;
                    $incomingRank = $statusRank[$status] ?? 0;

                    if ($existing && (
                        $incomingRank < $previousRank
                        || ($incomingRank === $previousRank
                            && $previousStatus !== $status
                            && $previousRank >= 1)
                    )) {
                        Log::info('Ignoring stale Stripe refund status transition.', [
                            'refund_id' => $refundId,
                            'payment_id' => $lockedPayment->id,
                            'stored_status' => $previousStatus,
                            'incoming_status' => $status,
                        ]);
                        $effectiveStatus = $previousStatus;
                    }

                    $wasApplied = in_array($previousStatus, ['succeeded', 'completed'], true);
                    $isApplied = in_array($effectiveStatus, ['succeeded', 'completed'], true);
                    $refundRecord = $existing ?: $lockedPayment->refunds()->make([
                        'provider' => 'stripe',
                        'provider_refund_id' => $refundId,
                    ]);
                    $refundRecord->fill([
                        'shoot_id' => $lockedPayment->shoot_id,
                        'amount' => $allocatedAmount,
                        'provider_refund_id' => $refundId,
                        'operation_key' => $refundRecord->operation_key ?: ($appRefundOperationKey ?: null),
                        'status' => $effectiveStatus,
                        'reason' => $refundRecord->reason
                            ?: ((string) data_get($refund, 'metadata.app_refund_reason', '')
                                ?: ((string) data_get($refund, 'reason', '') ?: 'Stripe Dashboard/API refund')),
                        'created_by' => $refundRecord->created_by
                            ?: ((int) data_get($refund, 'metadata.app_created_by', 0) ?: null),
                    ]);
                    $refundRecord->save();

                    $lockedPayment->load('refunds');
                    $lockedPayment->status = $lockedPayment->isFullyRefunded()
                        ? Payment::STATUS_REFUNDED
                        : Payment::STATUS_COMPLETED;

                    if (! $existing || $effectiveStatus === $status) {
                        $lockedPayment->payment_details = $this->stripePaymentMetadataService->mergeRefundDetails(
                            $lockedPayment,
                            $refund,
                            $allocatedAmount
                        );
                    }
                    $lockedPayment->save();

                    if ($wasApplied === $isApplied || ! $lockedPayment->shoot) {
                        continue;
                    }

                    $shoot = $lockedPayment->shoot;
                    $summary = $shoot->fresh(['payments'])?->syncPaymentStatusFromRecords('stripe')
                        ?? $shoot->syncPaymentStatusFromRecords('stripe');
                    $processedAt = is_numeric(data_get($refund, 'created'))
                        ? Carbon::createFromTimestamp((int) data_get($refund, 'created'))
                        : now();

                    $this->syncClientInvoiceFromShootPayment(
                        $this->findClientInvoiceForShoot($shoot),
                        $shoot,
                        $lockedPayment,
                        $summary['total_paid'],
                        'stripe',
                        $lockedPayment->payment_details,
                        $processedAt
                    );

                    $action = $isApplied ? 'payment_refunded' : 'payment_refund_reversed';
                    $this->activityLogger->log($shoot, $action, array_merge([
                        'payment_id' => $lockedPayment->id,
                        'refund_id' => $refundId,
                        'refund_amount' => $allocatedAmount,
                        'refund_status' => $effectiveStatus,
                        'new_payment_status' => $summary['payment_status'],
                        'provider' => 'stripe',
                        // The row belongs to this transaction. Suppress the
                        // realtime broadcast until the ledger has committed.
                        'suppress_notifications' => true,
                    ], $this->stripePaymentMetadataService->buildActivityMetadata($lockedPayment)), null);

                    $postCommitEffects[] = [
                        'shoot_id' => (int) $shoot->id,
                        'payment_id' => (int) $lockedPayment->id,
                        'refund_amount' => $allocatedAmount,
                        'payment_status' => $summary['payment_status'],
                        'is_applied' => $isApplied,
                    ];
                }

                return true;
            });

            if (! $handled) {
                return false;
            }

            // These effects must never run while the refund ledger is capable
            // of rolling back. A notification failure is logged separately and
            // cannot make Stripe retry an already committed refund.
            foreach ($postCommitEffects as $effect) {
                try {
                    $shoot = Shoot::find($effect['shoot_id']);
                    $payment = Payment::with('refunds')->find($effect['payment_id']);
                    if (! $shoot || ! $payment) {
                        throw new \RuntimeException('Committed refund side-effect records could not be reloaded.');
                    }

                    $this->clearShootCachesAfterPayment($shoot);
                    if ($effect['is_applied']) {
                        $context = $this->automationService->buildShootContext($shoot);
                        $context['payment'] = $payment;
                        $context['payment_id'] = $payment->id;
                        $context['refund_amount'] = $effect['refund_amount'];
                        $context['payment_status'] = $effect['payment_status'];
                        $this->automationService->handleEvent('PAYMENT_REFUNDED', $context);
                    }
                } catch (\Throwable $sideEffectException) {
                    \App\Services\ApiErrorResponder::log($sideEffectException, 'error');
                }
            }

            return true;
        } catch (\Throwable $exception) {
            \App\Services\ApiErrorResponder::log($exception, 'error');

            return false;
        } finally {
            optional($refundLifecycleLock)->release();
        }
    }

    protected function validateSingleCheckoutRequest(Request $request): void
    {
        $request->validate([
            'amount' => 'sometimes|numeric|min:0.01',
            'return_to' => 'nullable|string|max:500',
            'shoot_service_ids' => 'nullable|array|max:100',
            'shoot_service_ids.*' => 'integer|distinct|exists:shoot_service,id',
            'allocations' => 'nullable|array|max:100',
            'allocations.*.shoot_service_id' => 'required_with:allocations|integer|distinct|exists:shoot_service,id',
            'allocations.*.amount' => 'required_with:allocations|numeric|min:0.01',
            'allocation_strategy' => 'nullable|string|in:oldest_unpaid,manual,selected_service,selected_services',
        ]);
    }

    protected function assertAuthenticatedActorCanPayForShoot(Request $request, Shoot $shoot): void
    {
        $user = $request->user();

        // Public payment routes resolve a signed, revocable token before they
        // delegate here. Authenticated routes must pass the explicit role/tenant gate.
        if (! $user) {
            return;
        }

        if ($this->invoiceAuthorization->canViewShootInvoice($shoot, $user)) {
            return;
        }

        abort(403, 'Forbidden');
    }

    protected function assertMandatoryStripeDetails(Shoot $shoot, ?User $client): void
    {
        $errors = [];
        $ownerName = $this->resolveStripeOwnerName($client);

        if (! $client) {
            $errors['client'] = ['A client is required before a Stripe payment can be created.'];
        } elseif ($ownerName === '') {
            $errors['client.name'] = ['A client or company name is required for the Stripe payment owner.'];
        }

        $email = trim((string) ($client?->email ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['client.email'] = ['A valid client email is required for the Stripe customer.'];
        }

        $missingAddressParts = collect([
            'street' => $shoot->address,
            'city' => $shoot->city,
            'state' => $shoot->state,
            'postal code' => $shoot->zip,
        ])->filter(fn ($value) => trim((string) $value) === '')->keys()->all();

        if ($missingAddressParts !== []) {
            $errors['shoot.address'] = [
                'A complete property address is required for Stripe (missing '.implode(', ', $missingAddressParts).').',
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    protected function resolveSingleCheckoutClient($shoots): User
    {
        $clientIds = collect($shoots)
            ->pluck('client_id')
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        if ($clientIds->count() !== 1) {
            throw ValidationException::withMessages([
                'shoot_ids' => ['All shoots in one Stripe payment must belong to the same billing client.'],
            ]);
        }

        $client = collect($shoots)->first()?->client;
        if (! $client instanceof User) {
            throw ValidationException::withMessages([
                'shoot_ids' => ['The billing client for these shoots could not be found.'],
            ]);
        }

        return $client;
    }

    protected function resolveStripeOwnerName(?User $client): string
    {
        if (! $client) {
            return '';
        }

        return trim((string) ($client->company_name ?: $client->name));
    }

    protected function buildSingleShootStripeMetadata(
        Shoot $shoot,
        User $client,
        array $allocationPayload = []
    ): array {
        return array_merge([
            'shoot_id' => (string) $shoot->id,
            'type' => 'single',
            'client_id' => (string) $client->id,
            'environment' => $this->stripeMetadataValue((string) app()->environment()),
        ], $this->buildStripeAllocationMetadata($allocationPayload));
    }

    protected function buildMultipleShootsStripeMetadata($shoots, User $client): array
    {
        $shootIds = collect($shoots)->pluck('id')->map(fn ($id) => (string) $id)->values();
        $joinedShootIds = $shootIds->implode(',');

        if (strlen($joinedShootIds) > 500) {
            throw ValidationException::withMessages([
                'shoot_ids' => ['Too many shoots were selected for one Stripe payment.'],
            ]);
        }

        return [
            'shoot_ids' => $joinedShootIds,
            'shoot_count' => (string) $shootIds->count(),
            'type' => 'multiple',
            'client_id' => (string) $client->id,
            'environment' => $this->stripeMetadataValue((string) app()->environment()),
        ];
    }

    protected function stripeMetadataValue(string $value): string
    {
        return Str::limit(trim($value), 500, '');
    }

    /**
     * Create one authoritative Checkout attempt for the supplied shoots.
     *
     * The global lock is intentionally short-lived and only covers Session
     * creation. It lets us reuse an identical open Session and expire any
     * overlapping stale Session before another one can become payable.
     */
    protected function createManagedCheckoutSession(
        array $sessionParams,
        string $scope,
        User $client,
        array $items
    ): mixed {
        $normalizedItems = collect($items)
            ->map(fn ($item, $position) => [
                'shoot_id' => (int) ($item['shoot_id'] ?? 0),
                'position' => (int) $position,
                'expected_amount_cents' => (int) ($item['amount_cents'] ?? 0),
                'allocation_payload' => $item['allocation_payload'] ?? [],
            ])
            ->filter(fn ($item) => $item['shoot_id'] > 0 && $item['expected_amount_cents'] > 0)
            ->values();

        if ($normalizedItems->count() !== count($items) || $normalizedItems->isEmpty()) {
            throw ValidationException::withMessages([
                'payment' => ['Stripe checkout items are incomplete.'],
            ]);
        }

        $shootIds = $normalizedItems->pluck('shoot_id')->unique()->values()->all();
        if (count($shootIds) !== $normalizedItems->count()) {
            throw ValidationException::withMessages([
                'payment' => ['A shoot may only appear once in a Stripe checkout.'],
            ]);
        }

        $expectedAmountCents = (int) $normalizedItems->sum('expected_amount_cents');
        $currency = strtolower((string) data_get(
            $sessionParams,
            'line_items.0.price_data.currency',
            config('services.stripe.currency', 'USD')
        ));
        $uiMode = (string) ($sessionParams['ui_mode'] ?? 'hosted');
        $requestFingerprint = hash('sha256', json_encode([
            'scope' => $scope,
            'client_id' => (int) $client->id,
            'currency' => $currency,
            'params' => $sessionParams,
            'items' => $normalizedItems->all(),
        ], JSON_THROW_ON_ERROR));
        $lock = Cache::lock(
            self::CHECKOUT_LIFECYCLE_LOCK,
            self::CHECKOUT_LIFECYCLE_LOCK_SECONDS
        );

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'payment' => ['Another payment session is being prepared. Please try again in a moment.'],
            ]);
        }

        try {
            $activeAttempts = StripeCheckoutAttempt::with('items')
                ->whereIn('status', [
                    StripeCheckoutAttempt::STATUS_CREATING,
                    StripeCheckoutAttempt::STATUS_OPEN,
                    StripeCheckoutAttempt::STATUS_PROCESSING,
                ])
                ->whereHas('items', fn ($query) => $query->whereIn('shoot_id', $shootIds))
                ->orderBy('id')
                ->get();
            $retryAttempt = null;

            foreach ($activeAttempts as $activeAttempt) {
                if (! $activeAttempt->stripe_session_id) {
                    if ($activeAttempt->expires_at?->isPast()) {
                        $activeAttempt->update([
                            'status' => StripeCheckoutAttempt::STATUS_EXPIRED,
                            'failure_message' => 'The uncertain Stripe Session has passed its expiry time.',
                        ]);

                        continue;
                    }

                    $isExactRetry = $activeAttempt->status === StripeCheckoutAttempt::STATUS_CREATING
                        && $activeAttempt->scope === $scope
                        && $activeAttempt->ui_mode === $uiMode
                        && (int) $activeAttempt->client_id === (int) $client->id
                        && (int) $activeAttempt->expected_amount_cents === $expectedAmountCents
                        && strtolower((string) $activeAttempt->currency) === $currency
                        && hash_equals((string) $activeAttempt->request_fingerprint, $requestFingerprint);

                    if ($isExactRetry) {
                        $retryAttempt = $activeAttempt;

                        continue;
                    }

                    throw ValidationException::withMessages([
                        'payment' => [
                            'A previous Stripe session has an uncertain creation result. Retry the same payment or contact support before changing the amount.',
                        ],
                    ]);
                }

                try {
                    $activeSession = StripeSession::retrieve($activeAttempt->stripe_session_id);
                } catch (\Throwable $exception) {
                    $isMissing = $exception instanceof InvalidRequestException
                        && $exception->getStripeCode() === 'resource_missing';

                    if ($isMissing) {
                        $activeAttempt->update([
                            'status' => StripeCheckoutAttempt::STATUS_FAILED,
                            'failure_message' => Str::limit(\App\Services\ApiErrorResponder::publicMessage($exception), 2000, ''),
                        ]);

                        continue;
                    }

                    $activeAttempt->update([
                        'failure_message' => Str::limit(\App\Services\ApiErrorResponder::publicMessage($exception), 2000, ''),
                    ]);
                    throw new \RuntimeException(
                        'Stripe could not verify the previous payment Session. Please retry in a moment.',
                        previous: $exception
                    );
                }

                if (($activeSession->payment_status ?? null) === 'paid') {
                    $outcome = $this->handleCheckoutCompleted($activeSession, true);
                    if (! in_array($outcome, [
                        self::CHECKOUT_OUTCOME_PROCESSED,
                        self::CHECKOUT_OUTCOME_ALREADY_PROCESSED,
                        self::CHECKOUT_OUTCOME_REFUNDED_STALE,
                    ], true)) {
                        throw new \RuntimeException('A prior paid Stripe session could not be reconciled.');
                    }

                    throw ValidationException::withMessages([
                        'payment' => ['A recent Stripe payment already completed. Refresh the balance before paying again.'],
                    ]);
                }

                $isExactReusableAttempt = $activeAttempt->scope === $scope
                    && $activeAttempt->ui_mode === $uiMode
                    && (int) $activeAttempt->client_id === (int) $client->id
                    && (int) $activeAttempt->expected_amount_cents === $expectedAmountCents
                    && strtolower((string) $activeAttempt->currency) === $currency
                    && hash_equals((string) $activeAttempt->request_fingerprint, $requestFingerprint)
                    && ($activeSession->status ?? null) === 'open';

                if ($isExactReusableAttempt) {
                    $this->assertManagedCheckoutItemsRemainPayable($normalizedItems);

                    return $activeSession;
                }

                if (($activeSession->status ?? null) === 'complete') {
                    $activeAttempt->update(['status' => StripeCheckoutAttempt::STATUS_PROCESSING]);
                    throw ValidationException::withMessages([
                        'payment' => ['A previous Stripe payment is still processing. Please wait before trying again.'],
                    ]);
                }

                if (($activeSession->status ?? null) === 'open') {
                    $activeSession->expire();
                }

                $activeAttempt->update([
                    'status' => ($activeSession->status ?? null) === 'expired'
                        ? StripeCheckoutAttempt::STATUS_EXPIRED
                        : StripeCheckoutAttempt::STATUS_SUPERSEDED,
                ]);
            }

            // The amount was originally calculated before Stripe Customer and
            // Session setup. A paid Session may have finalized in that gap.
            // Re-read canonical balances while holding the same lifecycle lock
            // used by finalization so a stale amount can never become payable.
            $this->assertManagedCheckoutItemsRemainPayable($normalizedItems);

            if ($retryAttempt) {
                $attempt = $retryAttempt;
                $idempotencyKey = (string) $attempt->idempotency_key;
                $expiresAt = $attempt->expires_at ?? now()->addMinutes(self::CHECKOUT_SESSION_EXPIRY_MINUTES);
            } else {
                $expiresAt = now()->addMinutes(self::CHECKOUT_SESSION_EXPIRY_MINUTES);
                $idempotencyKey = 'repro_checkout_'.$scope.'_'.str_replace('-', '', (string) Str::uuid());
                $attempt = DB::transaction(function () use (
                    $client,
                    $scope,
                    $uiMode,
                    $expectedAmountCents,
                    $currency,
                    $requestFingerprint,
                    $idempotencyKey,
                    $expiresAt,
                    $normalizedItems
                ) {
                    $attempt = StripeCheckoutAttempt::create([
                        'client_id' => $client->id,
                        'scope' => $scope,
                        'ui_mode' => $uiMode,
                        'expected_amount_cents' => $expectedAmountCents,
                        'currency' => strtoupper($currency),
                        'status' => StripeCheckoutAttempt::STATUS_CREATING,
                        'request_fingerprint' => $requestFingerprint,
                        'idempotency_key' => $idempotencyKey,
                        'expires_at' => $expiresAt,
                    ]);

                    $attempt->items()->createMany($normalizedItems->all());

                    return $attempt;
                });
            }

            $sessionParams['expires_at'] = $expiresAt->timestamp;
            $sessionParams['metadata'] = array_merge(
                $sessionParams['metadata'] ?? [],
                ['checkout_attempt_id' => (string) $attempt->id]
            );
            $sessionParams['payment_intent_data']['metadata'] = array_merge(
                data_get($sessionParams, 'payment_intent_data.metadata', []),
                ['checkout_attempt_id' => (string) $attempt->id]
            );

            try {
                $session = StripeSession::create($sessionParams, [
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (\Throwable $exception) {
                $httpStatus = method_exists($exception, 'getHttpStatus')
                    ? (int) $exception->getHttpStatus()
                    : 0;
                $isDefinitiveFailure = $exception instanceof InvalidRequestException
                    || ($httpStatus >= 400 && $httpStatus < 500 && ! in_array($httpStatus, [409, 429], true));
                $attempt->update([
                    'status' => $isDefinitiveFailure
                        ? StripeCheckoutAttempt::STATUS_FAILED
                        : StripeCheckoutAttempt::STATUS_CREATING,
                    'failure_message' => Str::limit(\App\Services\ApiErrorResponder::publicMessage($exception), 2000, ''),
                ]);
                throw $exception;
            }

            if (($session->payment_status ?? null) === 'paid') {
                $outcome = $this->handleCheckoutCompleted($session, true);
                if (! in_array($outcome, [
                    self::CHECKOUT_OUTCOME_PROCESSED,
                    self::CHECKOUT_OUTCOME_ALREADY_PROCESSED,
                    self::CHECKOUT_OUTCOME_REFUNDED_STALE,
                ], true)) {
                    throw new \RuntimeException('The paid Stripe Session could not be reconciled.');
                }

                throw ValidationException::withMessages([
                    'payment' => ['This Stripe payment already completed. Refresh the balance before paying again.'],
                ]);
            }

            if (($session->status ?? null) === 'expired') {
                $attempt->update([
                    'status' => StripeCheckoutAttempt::STATUS_EXPIRED,
                    'stripe_session_id' => (string) $session->id,
                ]);
                throw ValidationException::withMessages([
                    'payment' => ['The previous Stripe Session expired. Please start the payment again.'],
                ]);
            }

            if (($session->status ?? null) === 'complete') {
                $attempt->update([
                    'status' => StripeCheckoutAttempt::STATUS_PROCESSING,
                    'stripe_session_id' => (string) $session->id,
                ]);
                throw ValidationException::withMessages([
                    'payment' => ['The previous Stripe payment is still processing. Please wait before trying again.'],
                ]);
            }

            if (($session->status ?? null) !== 'open') {
                $attempt->update([
                    'status' => StripeCheckoutAttempt::STATUS_FAILED,
                    'stripe_session_id' => (string) ($session->id ?? ''),
                    'failure_message' => 'Stripe returned an unexpected Checkout Session state.',
                ]);
                throw new \RuntimeException('Stripe returned an unexpected payment Session state.');
            }

            $attempt->update([
                'status' => StripeCheckoutAttempt::STATUS_OPEN,
                'stripe_session_id' => (string) $session->id,
                'expires_at' => is_numeric($session->expires_at ?? null)
                    ? Carbon::createFromTimestamp((int) $session->expires_at)
                    : $expiresAt,
            ]);

            return $session;
        } finally {
            $lock->release();
        }
    }

    protected function assertManagedCheckoutItemsRemainPayable($items): void
    {
        $shootIds = collect($items)
            ->pluck('shoot_id')
            ->map(fn ($shootId) => (int) $shootId)
            ->filter()
            ->unique()
            ->values();
        $shoots = Shoot::with(['payments.refunds'])
            ->whereIn('id', $shootIds->all())
            ->get()
            ->keyBy(fn (Shoot $shoot) => (int) $shoot->id);

        if ($shoots->count() !== $shootIds->count()) {
            throw ValidationException::withMessages([
                'payment' => ['One or more shoots are no longer available for payment.'],
            ]);
        }

        foreach ($items as $item) {
            $shoot = $shoots->get((int) $item['shoot_id']);
            $this->invoiceAdjustments->assertClientPaymentAllowedForShoot($shoot);

            $expectedAmountCents = (int) $item['expected_amount_cents'];
            $currentOutstandingCents = $this->calculateCanonicalOutstandingAmountCents($shoot);

            if ($currentOutstandingCents <= 0 || $expectedAmountCents > $currentOutstandingCents) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'The outstanding balance changed while the Stripe payment was being prepared. Refresh and try again.',
                    ],
                ]);
            }
        }
    }

    protected function validateManagedCheckoutAttempt(mixed $session): bool
    {
        $attemptId = (int) data_get($session, 'metadata.checkout_attempt_id', 0);

        // Sessions opened before this deployment do not have an attempt row;
        // keep them fulfillable through the legacy amount/metadata checks.
        if ($attemptId <= 0) {
            return true;
        }

        try {
            return DB::transaction(function () use ($attemptId, $session) {
                $attempt = StripeCheckoutAttempt::with('items')->lockForUpdate()->find($attemptId);
                $sessionId = trim((string) data_get($session, 'id', ''));
                $paymentIntentId = trim((string) data_get($session, 'payment_intent', ''));
                $sessionAmountCents = (int) data_get($session, 'amount_total', 0);
                $sessionCurrency = strtoupper((string) data_get($session, 'currency', ''));
                $linkedShootIds = $this->extractShootIdsFromSession($session);
                $attemptShootIds = $attempt
                    ? $attempt->items->map(fn ($item) => (string) $item->shoot_id)->values()->all()
                    : [];
                $currentShootClients = Shoot::query()
                    ->whereIn('id', $attemptShootIds)
                    ->pluck('client_id', 'id');
                $attemptClientMatches = $attempt
                    && $currentShootClients->count() === count($attemptShootIds)
                    && collect($attemptShootIds)->every(
                        fn ($shootId) => (int) $currentShootClients->get((int) $shootId) === (int) $attempt->client_id
                    );

                $isValid = $attempt
                    && $sessionId !== ''
                    && ($attempt->stripe_session_id === null || hash_equals(
                        (string) $attempt->stripe_session_id,
                        $sessionId
                    ))
                    && (int) $attempt->expected_amount_cents === $sessionAmountCents
                    && strtoupper((string) $attempt->currency) === $sessionCurrency
                    && $attemptShootIds === $linkedShootIds;

                if (! $isValid) {
                    Log::error('Paid Stripe Session does not match its checkout attempt.', [
                        'checkout_attempt_id' => $attemptId,
                        'session_id' => $sessionId ?: null,
                        'session_amount_cents' => $sessionAmountCents,
                        'session_currency' => $sessionCurrency,
                        'session_shoot_ids' => $linkedShootIds,
                        'attempt_client_matches' => $attemptClientMatches,
                    ]);

                    return false;
                }

                if (! $attemptClientMatches) {
                    Log::warning('Paid Stripe Session billing ownership changed; the charge will be refunded.', [
                        'checkout_attempt_id' => $attemptId,
                        'session_id' => $sessionId,
                    ]);
                }

                $updates = [
                    'status' => in_array($attempt->status, [
                        StripeCheckoutAttempt::STATUS_PAID,
                        StripeCheckoutAttempt::STATUS_REFUNDED,
                    ], true)
                        ? $attempt->status
                        : StripeCheckoutAttempt::STATUS_PROCESSING,
                    'stripe_payment_intent_id' => $paymentIntentId ?: $attempt->stripe_payment_intent_id,
                ];

                if (! $attempt->stripe_session_id) {
                    $updates['stripe_session_id'] = $sessionId;
                }

                $attempt->update($updates);

                return true;
            });
        } catch (\Throwable $exception) {
            \App\Services\ApiErrorResponder::log($exception, 'error');

            return false;
        }
    }

    protected function stripeSessionClientMatchesShoots(mixed $session, $shoots): bool
    {
        $currentClientIds = collect($shoots)
            ->pluck('client_id')
            ->map(fn ($clientId) => (int) $clientId)
            ->unique()
            ->values();

        if ($currentClientIds->count() !== 1) {
            return false;
        }

        $sessionClientId = (int) data_get($session, 'metadata.client_id', 0);
        if ($sessionClientId <= 0) {
            $attemptId = (int) data_get($session, 'metadata.checkout_attempt_id', 0);
            $sessionClientId = $attemptId > 0
                ? (int) StripeCheckoutAttempt::query()->whereKey($attemptId)->value('client_id')
                : 0;
        }

        return $sessionClientId <= 0 || $currentClientIds->first() === $sessionClientId;
    }

    protected function markManagedCheckoutAttemptPaid(mixed $session, bool $refunded = false): void
    {
        $attemptId = (int) data_get($session, 'metadata.checkout_attempt_id', 0);
        if ($attemptId <= 0) {
            return;
        }

        StripeCheckoutAttempt::whereKey($attemptId)->update([
            'status' => $refunded
                ? StripeCheckoutAttempt::STATUS_REFUNDED
                : StripeCheckoutAttempt::STATUS_PAID,
            'stripe_session_id' => (string) data_get($session, 'id', ''),
            'stripe_payment_intent_id' => (string) data_get($session, 'payment_intent', ''),
            'completed_at' => now(),
            'failure_message' => null,
        ]);
    }

    protected function buildMultipleShootsClientReference(array $shootIds): string
    {
        $reference = 'shoots:'.implode(',', $shootIds);

        return strlen($reference) <= 200
            ? $reference
            : 'shoots:group:'.hash('sha256', $reference);
    }

    protected function extractShootIdsFromSession(mixed $session): array
    {
        $type = (string) data_get($session, 'metadata.type', 'single');
        $rawIds = $type === 'multiple'
            ? explode(',', (string) data_get($session, 'metadata.shoot_ids', ''))
            : [(string) data_get($session, 'metadata.shoot_id', '')];

        return collect($rawIds)
            ->map(fn ($id) => trim((string) $id))
            ->filter(fn ($id) => $id !== '' && ctype_digit($id))
            ->unique()
            ->values()
            ->all();
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
            'allocations' => collect(is_array($request->input('allocations')) ? $request->input('allocations') : [])
                ->map(fn ($allocation) => [
                    'shoot_service_id' => (int) ($allocation['shoot_service_id'] ?? 0),
                    'amount' => round((float) ($allocation['amount'] ?? 0), 2),
                ])
                ->filter(fn ($allocation) => $allocation['shoot_service_id'] > 0 && $allocation['amount'] > 0)
                ->values()
                ->all(),
            'allocation_strategy' => $request->input('allocation_strategy'),
        ];
    }

    protected function buildAllocationPayloadFromStripeMetadata(mixed $metadata): array
    {
        $serviceIds = (string) ($metadata->shoot_service_ids ?? '');
        $allocationRows = collect(explode(',', (string) ($metadata->allocations_cents ?? '')))
            ->map(function ($pair) {
                [$serviceId, $amountCents] = array_pad(explode(':', trim((string) $pair), 2), 2, null);

                return [
                    'shoot_service_id' => (int) $serviceId,
                    'amount' => round(((int) $amountCents) / 100, 2),
                ];
            })
            ->filter(fn ($allocation) => $allocation['shoot_service_id'] > 0 && $allocation['amount'] > 0)
            ->values()
            ->all();

        return [
            'shoot_service_ids' => collect(explode(',', $serviceIds))
                ->map(fn ($id) => (int) trim($id))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'allocations' => $allocationRows,
            'allocation_strategy' => $metadata->allocation_strategy ?? null,
        ];
    }

    protected function buildStripeAllocationMetadata(array $payload): array
    {
        $metadata = [];

        if (! empty($payload['shoot_service_ids'])) {
            $metadata['shoot_service_ids'] = implode(',', $payload['shoot_service_ids']);
        }

        if (! empty($payload['allocations'])) {
            $serializedAllocations = collect($payload['allocations'])
                ->map(fn ($allocation) => sprintf(
                    '%d:%d',
                    (int) ($allocation['shoot_service_id'] ?? 0),
                    (int) round(((float) ($allocation['amount'] ?? 0)) * 100)
                ))
                ->implode(',');

            if (strlen($serializedAllocations) > 500) {
                throw ValidationException::withMessages([
                    'allocations' => ['Too many explicit allocations were supplied for one Stripe payment.'],
                ]);
            }

            $metadata['allocations_cents'] = $serializedAllocations;
        }

        if (! empty($payload['allocation_strategy'])) {
            $metadata['allocation_strategy'] = (string) $payload['allocation_strategy'];
        }

        return $metadata;
    }

    protected function resolveCheckoutAmountCents(Shoot $shoot, Request $request, array $allocationPayload): int
    {
        $outstandingCents = $this->calculateCanonicalOutstandingAmountCents($shoot);
        $amountToPay = $outstandingCents;
        $serviceItems = collect($this->serviceItemSupport->summaries($shoot));
        $allocationStrategy = (string) ($allocationPayload['allocation_strategy'] ?? '');

        if ($allocationStrategy === 'manual' && empty($allocationPayload['allocations'])) {
            throw ValidationException::withMessages([
                'allocations' => ['Manual allocation requires explicit allocation rows.'],
            ]);
        }

        if (in_array($allocationStrategy, ['selected_service', 'selected_services'], true)
            && empty($allocationPayload['shoot_service_ids'])) {
            throw ValidationException::withMessages([
                'shoot_service_ids' => ['The selected-service allocation strategy requires one or more service IDs.'],
            ]);
        }

        if (! empty($allocationPayload['shoot_service_ids'])) {
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

        $explicitAllocationCents = 0;
        if (! empty($allocationPayload['allocations'])) {
            $allocationServiceIds = collect($allocationPayload['allocations'])
                ->pluck('shoot_service_id')
                ->map(fn ($id) => (int) $id)
                ->values();
            $matchedCount = $serviceItems
                ->whereIn('shoot_service_id', $allocationServiceIds->all())
                ->count();

            if ($matchedCount !== $allocationServiceIds->count()) {
                throw ValidationException::withMessages([
                    'allocations' => ['One or more payment allocations do not belong to this shoot.'],
                ]);
            }

            $explicitAllocationCents = collect($allocationPayload['allocations'])
                ->sum(fn ($allocation) => (int) round(((float) $allocation['amount']) * 100));

            if ($explicitAllocationCents <= 0 || $explicitAllocationCents > $outstandingCents) {
                throw ValidationException::withMessages([
                    'allocations' => ['Explicit allocations must be positive and cannot exceed the outstanding balance.'],
                ]);
            }

            $amountToPay = $explicitAllocationCents;
        }

        if ($request->has('amount')) {
            $requestedAmount = (int) round(((float) $request->input('amount')) * 100);
            if ($requestedAmount <= 0 || $requestedAmount > $amountToPay) {
                throw ValidationException::withMessages([
                    'amount' => ['Payment amount cannot exceed the selected outstanding balance.'],
                ]);
            }

            $amountToPay = $requestedAmount;
        }

        if ($explicitAllocationCents > 0 && $explicitAllocationCents !== $amountToPay) {
            throw ValidationException::withMessages([
                'allocations' => ['Explicit allocations must add up exactly to the Stripe payment amount.'],
            ]);
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
        if (! $client || ! $client->email) {
            return $sessionParams;
        }

        $sessionParams['billing_address_collection'] = 'required';
        $sessionParams['name_collection'] = $client->company_name
            ? [
                'business' => [
                    'enabled' => true,
                    'optional' => false,
                ],
            ]
            : [
                'individual' => [
                    'enabled' => true,
                    'optional' => false,
                ],
            ];

        $stripeCustomerId = $this->findOrCreateStripeCustomer($client);

        if ($stripeCustomerId) {
            $sessionParams['customer'] = $stripeCustomerId;
            $sessionParams['customer_update'] = [
                'address' => 'auto',
                'name' => 'auto',
            ];

            return $sessionParams;
        }

        $sessionParams['customer_creation'] = 'always';
        $sessionParams['customer_email'] = $client->email;

        return $sessionParams;
    }

    protected function buildPaymentIntentDataForSingleShoot(
        Shoot $shoot,
        User $client,
        array $metadata = []
    ): array {
        // Browser navigation state belongs only on the Checkout Session. Never
        // copy query strings/fragments into the long-lived PaymentIntent.
        unset($metadata['return_to']);

        return [
            'description' => $this->buildStripePaymentDescriptionForSingleShoot($shoot),
            'receipt_email' => (string) $client->email,
            // Keep metadata to application identifiers. The required owner and
            // email live on the Stripe Customer, while the required property
            // address is the PaymentIntent description shown in Dashboard.
            'metadata' => $metadata,
        ];
    }

    protected function buildPaymentIntentDataForMultipleShoots(
        $shoots,
        User $client,
        array $metadata = []
    ): array {
        unset($metadata['return_to']);

        $description = $this->buildStripePaymentDescriptionForMultipleShoots($shoots);

        return [
            'description' => $description,
            'receipt_email' => (string) $client->email,
            'metadata' => $metadata,
        ];
    }

    protected function buildStripePaymentDescriptionForSingleShoot(Shoot $shoot): string
    {
        return $this->formatShootAddress($shoot)
            ?: ('Shoot #'.$shoot->id);
    }

    protected function buildCheckoutLineItemNameForShoot(Shoot $shoot): string
    {
        return $this->formatShootAddress($shoot)
            ?: ('Shoot #'.$shoot->id);
    }

    protected function buildReceiptPayloadForShoot(Shoot $shoot): ?array
    {
        $payments = ($shoot->payments ?? collect())->map(function ($payment) {
            if (! $payment instanceof Payment) {
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
        if (! $shoot) {
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
        $metadataKey = $this->stripeCustomerMetadataKey();
        $stripeCustomerId = $metadata[$metadataKey]
            ?? ($metadataKey === 'stripe_customer_id_live' ? ($metadata['stripe_customer_id'] ?? null) : null);

        try {
            if (is_string($stripeCustomerId) && $stripeCustomerId !== '') {
                try {
                    $customer = StripeCustomer::retrieve($stripeCustomerId);
                    if (! ($customer->deleted ?? false)) {
                        $mappedUserId = data_get($customer, 'metadata.app_user_id')
                            ?? data_get($customer, 'metadata.user_id');

                        if ((string) $mappedUserId === (string) $client->id) {
                            StripeCustomer::update($stripeCustomerId, $this->buildStripeCustomerParams($client));

                            return $stripeCustomerId;
                        }

                        Log::warning('Stored Stripe Customer does not match the billing client.', [
                            'user_id' => $client->id,
                            'stripe_customer_id' => $stripeCustomerId,
                        ]);
                    }
                } catch (InvalidRequestException $e) {
                    if ($e->getStripeCode() !== 'resource_missing') {
                        throw $e;
                    }
                }

                $this->forgetStripeCustomerId($client);
            }

            $customers = StripeCustomer::all([
                'email' => $client->email,
                'limit' => 10,
            ]);

            $existingCustomer = collect($customers->data ?? [])->first(function ($customer) use ($client) {
                $mappedUserId = data_get($customer, 'metadata.app_user_id')
                    ?? data_get($customer, 'metadata.user_id');

                return (string) $mappedUserId === (string) $client->id;
            });

            if ($existingCustomer && ! empty($existingCustomer->id)) {
                StripeCustomer::update($existingCustomer->id, $this->buildStripeCustomerParams($client));
                $this->storeStripeCustomerId($client, $existingCustomer->id);

                return $existingCustomer->id;
            }

            $createdCustomer = StripeCustomer::create(
                $this->buildStripeCustomerParams($client),
                ['idempotency_key' => 'repro_customer_'.$this->stripeMode().'_'.$client->id]
            );

            if (! empty($createdCustomer->id)) {
                $this->storeStripeCustomerId($client, $createdCustomer->id);

                return $createdCustomer->id;
            }
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'warning');
        }

        return null;
    }

    protected function buildStripeCustomerParams(User $client): array
    {
        $params = [
            'email' => trim((string) $client->email),
            'name' => Str::limit($this->resolveStripeOwnerName($client), 250, ''),
            'description' => 'Repro Dashboard billing client #'.$client->id,
            'metadata' => [
                'app_user_id' => (string) $client->id,
                'app_role' => (string) $client->role,
                'company_name' => $this->stripeMetadataValue((string) ($client->company_name ?? '')),
                'environment' => $this->stripeMetadataValue((string) app()->environment()),
            ],
        ];

        $phone = trim((string) ($client->phone ?: $client->phonenumber));
        if ($phone !== '') {
            $params['phone'] = $phone;
        }

        if (trim((string) ($client->company_name ?? '')) !== '') {
            $params['business_name'] = Str::limit(trim((string) $client->company_name), 250, '');
        } else {
            $params['individual_name'] = Str::limit(trim((string) $client->name), 250, '');
        }

        return $params;
    }

    protected function stripeMode(): string
    {
        return str_starts_with(trim((string) config('services.stripe.secret_key')), 'sk_live_')
            ? 'live'
            : 'test';
    }

    protected function stripeCustomerMetadataKey(): string
    {
        return 'stripe_customer_id_'.$this->stripeMode();
    }

    protected function storeStripeCustomerId(User $client, string $stripeCustomerId): void
    {
        $metadata = $client->metadata ?? [];
        $metadataKey = $this->stripeCustomerMetadataKey();

        if (($metadata[$metadataKey] ?? null) === $stripeCustomerId) {
            return;
        }

        $metadata[$metadataKey] = $stripeCustomerId;
        if ($metadataKey === 'stripe_customer_id_live') {
            $metadata['stripe_customer_id'] = $stripeCustomerId;
        }
        $client->forceFill([
            'metadata' => $metadata,
        ])->save();
    }

    protected function forgetStripeCustomerId(User $client): void
    {
        $metadata = $client->metadata ?? [];
        $metadataKey = $this->stripeCustomerMetadataKey();
        unset($metadata[$metadataKey]);

        if ($metadataKey === 'stripe_customer_id_live') {
            unset($metadata['stripe_customer_id']);
        }

        $client->forceFill(['metadata' => $metadata])->save();
    }

    protected function hasProcessedSession(string $sessionId, ?string $paymentIntentId = null): bool
    {
        return Payment::query()
            ->where(function ($query) use ($sessionId, $paymentIntentId) {
                $query->where('stripe_session_id', $sessionId)
                    ->orWhere('stripe_session_id', 'like', $sessionId.'_shoot_%');

                if (! empty($paymentIntentId)) {
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
    protected function updateShootPaymentStatus(Shoot $shoot, Payment $payment, float $amount, bool $dispatchReceipt = true): void
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
        if ($client && $dispatchReceipt) {
            try {
                $this->mailService->sendPaymentConfirmationEmail($client, $shoot, $payment);

                $this->activityLogger->log(
                    $shoot,
                    'payment_completion_email_sent',
                    ['recipient' => $client->email],
                    null
                );
            } catch (\Exception $e) {
                \App\Services\ApiErrorResponder::log($e, 'error');
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
        if (! is_string($returnTo)) {
            return null;
        }

        $trimmed = trim($returnTo);
        if ($trimmed === ''
            || str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '\\')
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $trimmed)) {
            return null;
        }

        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');

        if (str_starts_with($trimmed, '/')) {
            return $trimmed;
        }

        if (! preg_match('/^[a-z][a-z0-9+\-.]*:/i', $trimmed)) {
            return null;
        }

        $frontendParts = parse_url($frontendUrl);
        $returnParts = parse_url($trimmed);

        if (! $frontendParts || ! $returnParts) {
            return null;
        }

        $sameOrigin =
            (($frontendParts['scheme'] ?? null) === ($returnParts['scheme'] ?? null))
            && (($frontendParts['host'] ?? null) === ($returnParts['host'] ?? null))
            && (($frontendParts['port'] ?? null) === ($returnParts['port'] ?? null));

        if (! $sameOrigin) {
            return null;
        }

        $path = $returnParts['path'] ?? '/';
        $query = isset($returnParts['query']) ? '?'.$returnParts['query'] : '';
        $fragment = isset($returnParts['fragment']) ? '#'.$returnParts['fragment'] : '';

        return $path.$query.$fragment;
    }

    protected function buildEmbeddedReturnUrl(Shoot $shoot, ?string $returnTo, string $paymentUrl): string
    {
        if (! $returnTo) {
            return $paymentUrl.'?success=true&session_id={CHECKOUT_SESSION_ID}';
        }

        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');

        return $frontendUrl
            .'/payment-return/shoot/'.$shoot->id
            .'?session_id={CHECKOUT_SESSION_ID}&return_to='.rawurlencode($returnTo);
    }

    protected function resolveReturnToFromSession($session): ?string
    {
        $metadataReturnTo = $session?->metadata?->return_to ?? null;

        return $this->sanitizeReturnTo($metadataReturnTo);
    }

    protected function resolveLastPaymentAmountFromSession($session): ?float
    {
        $amountTotal = $session?->amount_total ?? null;
        if (! is_numeric($amountTotal)) {
            return null;
        }

        return round(((float) $amountTotal) / 100, 2);
    }

    protected function findClientInvoiceForShoot(Shoot $shoot): ?Invoice
    {
        return $this->invoiceAdjustments->preferredClientInvoiceForShoot($shoot);
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
        $this->invoiceAdjustments->reconcileClientInvoicesForShoot(
            $shoot,
            $payment,
            $paymentMethod,
            $paymentDetails
        );
    }

    /**
     * Clear watermark-sensitive caches after payment status changes.
     */
    protected function clearShootCachesAfterPayment(Shoot $shoot): void
    {
        if ($shoot->client_id) {
            foreach (['', 'raw', 'edited', 'all'] as $type) {
                Cache::forget('shoot_files_'.$shoot->id.'_'.$type.'_'.$shoot->client_id.'_client');
            }
        }
        $user = auth()->user();
        if ($user) {
            foreach (['', 'raw', 'edited', 'all'] as $type) {
                Cache::forget('shoot_files_'.$shoot->id.'_'.$type.'_'.$user->id.'_'.$user->role);
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
     * Find a provider refund created by an earlier ambiguous request.
     *
     * Stripe retains idempotency results for a limited period. Before replaying
     * a locally `creating` operation, search the PaymentIntent's refunds for our
     * durable operation key. This prevents both an immediate duplicate after a
     * lost response and a blind replay after Stripe may have evicted the key.
     */
    protected function findStripeRefundForOperation(Payment $payment, mixed $refundOperation): mixed
    {
        $operationKey = trim((string) ($refundOperation->operation_key ?? ''));
        if ($operationKey === '') {
            throw new \RuntimeException('The unresolved refund is missing its operation key.');
        }

        $params = [
            'payment_intent' => (string) $payment->stripe_payment_id,
            'limit' => 100,
        ];
        $operationCreatedAt = $refundOperation->created_at ?? null;
        if ($operationCreatedAt) {
            // The local row is committed before Stripe is called. The small
            // allowance covers clock skew without scanning the full PI history.
            $params['created'] = [
                'gte' => max(Carbon::parse($operationCreatedAt)->timestamp - 300, 0),
            ];
        }

        $refundPage = Refund::all($params);
        $refunds = is_object($refundPage) && method_exists($refundPage, 'autoPagingIterator')
            ? $refundPage->autoPagingIterator()
            : data_get($refundPage, 'data', []);

        if (! is_iterable($refunds)) {
            throw new \RuntimeException('Stripe returned an invalid refund collection.');
        }

        $matchingRefund = null;
        $expectedAmountCents = (int) round(((float) $refundOperation->amount) * 100);
        foreach ($refunds as $candidate) {
            if (trim((string) data_get($candidate, 'metadata.app_refund_operation_key', '')) !== $operationKey) {
                continue;
            }

            $candidateId = trim((string) data_get($candidate, 'id', ''));
            $candidatePaymentIntent = trim((string) data_get($candidate, 'payment_intent', ''));
            $candidateAmountCents = (int) data_get($candidate, 'amount', 0);
            if ($candidateId === ''
                || $candidatePaymentIntent !== (string) $payment->stripe_payment_id
                || $candidateAmountCents !== $expectedAmountCents) {
                throw new \RuntimeException('Stripe returned a conflicting refund for this operation key.');
            }

            if ($matchingRefund !== null) {
                throw new \RuntimeException('Stripe returned multiple refunds for one operation key.');
            }

            $matchingRefund = $candidate;
        }

        return $matchingRefund;
    }

    /**
     * Stop reusing an unresolved key before Stripe's documented 24-hour
     * retention floor can elapse. A successful no-match reconciliation then
     * releases the local reservation and requires an explicit new operation.
     */
    protected function stripeRefundRetryWindowExpired(mixed $refundOperation): bool
    {
        $createdAt = $refundOperation->created_at ?? null;

        return ! $createdAt || Carbon::parse($createdAt)->addHours(23)->isPast();
    }

    /**
     * Refund a Stripe payment.
     */
    public function refundPayment(Request $request)
    {
        if (! $this->authorizationSupport->hasRole($request->user(), ['admin', 'superadmin'])) {
            abort(403, 'Forbidden');
        }

        $request->validate([
            'payment_id' => 'required|integer|exists:payments,id',
            'amount' => 'sometimes|numeric|decimal:0,2|min:0.01',
            'reason' => 'nullable|string|max:2000',
            // Required at the API boundary: only the caller can preserve this
            // identifier when a completed HTTP response is lost.
            'refund_operation_id' => 'required|uuid',
        ]);

        $lockPayment = Payment::query()->findOrFail($request->integer('payment_id'));
        $refundLockIdentity = trim((string) $lockPayment->stripe_payment_id)
            ?: 'payment:'.$lockPayment->id;
        $refundLock = Cache::lock(
            'stripe_refund_intent_'.hash('sha256', $refundLockIdentity),
            180
        );
        if (! $refundLock->get()) {
            return response()->json([
                'error' => 'A refund for this payment is already being processed.',
            ], 409);
        }

        try {
            $this->initStripe();

            $paymentRecord = Payment::findOrFail($request->input('payment_id'));

            if ($paymentRecord->payment_method !== 'stripe' || empty($paymentRecord->stripe_payment_id)) {
                return response()->json(['error' => 'This payment was not processed via Stripe.'], 400);
            }

            $paymentRecord->load('refunds');
            $requestedOperationKey = (string) $request->input('refund_operation_id');
            $refundOperation = $paymentRecord->refunds
                ->first(fn ($refundRecord) => (string) $refundRecord->operation_key === $requestedOperationKey);

            // Partial refunds are supported. A replay of the operation that
            // completed the refund must still return its original success,
            // while a genuinely new refund against a fully-refunded payment is
            // rejected.
            if ($paymentRecord->isFullyRefunded() && ! $refundOperation) {
                return response()->json(['error' => 'This Stripe payment has already been fully refunded.'], 400);
            }

            // If a browser lost the first response and later opened a fresh
            // dialog, recover the unresolved operation even if that dialog
            // generated a new UUID. Only one `creating` refund is allowed to
            // reserve a payment at a time.
            if (! $refundOperation) {
                $refundOperation = $paymentRecord->refunds
                    ->filter(fn ($refundRecord) => strtolower((string) $refundRecord->status) === 'creating')
                    ->sortByDesc('id')
                    ->first();
            }

            if ($refundOperation) {
                $storedOperationKey = (string) $refundOperation->operation_key;
                $storedRefundCents = (int) round(((float) $refundOperation->amount) * 100);
                $requestedRefundCents = $request->has('amount')
                    ? (int) round(((float) $request->input('amount')) * 100)
                    : $storedRefundCents;

                if ($requestedRefundCents !== $storedRefundCents) {
                    return response()->json([
                        'error' => 'An unresolved refund already exists for a different amount. Retry that refund before starting another.',
                        'refund_operation_id' => $storedOperationKey,
                        'refund_amount' => round($storedRefundCents / 100, 2),
                    ], 409);
                }

                $requestedOperationKey = $storedOperationKey;
                $operationStatus = strtolower((string) $refundOperation->status);

                if ($operationStatus === 'creating') {
                    // The previous POST may have succeeded even though its HTTP
                    // response was lost. Provider reconciliation must precede
                    // every retry; a failed lookup is itself ambiguous and is
                    // allowed to bubble out without issuing another refund.
                    $refund = $this->findStripeRefundForOperation($paymentRecord, $refundOperation);

                    if (! $refund && $this->stripeRefundRetryWindowExpired($refundOperation)) {
                        $refundOperation->newQuery()
                            ->whereKey($refundOperation->id)
                            ->where('status', 'creating')
                            ->update(['status' => 'failed']);

                        return response()->json([
                            'error' => 'The unresolved refund operation expired and Stripe reports that no refund was created. Start a new refund operation.',
                            'refund_operation_id' => $requestedOperationKey,
                            'refund_status' => 'failed',
                        ], 409);
                    }
                }

                if (! isset($refund) && in_array($operationStatus, ['pending', 'requires_action'], true)) {
                    return response()->json([
                        'status' => $operationStatus,
                        'message' => 'Stripe is still processing this refund. The balance will update after it succeeds.',
                        'refund_operation_id' => $requestedOperationKey,
                        'refund' => [
                            'id' => $refundOperation->provider_refund_id,
                            'status' => $operationStatus,
                            'amount' => $storedRefundCents,
                        ],
                    ], 202);
                }

                if (! isset($refund) && in_array($operationStatus, ['failed', 'canceled', 'cancelled'], true)) {
                    return response()->json([
                        'error' => 'This refund attempt reached a terminal failure. Start a new refund operation to try again.',
                        'refund_operation_id' => $requestedOperationKey,
                        'refund_status' => $operationStatus,
                    ], 409);
                }

                if (! isset($refund) && in_array($operationStatus, ['succeeded', 'completed'], true)) {
                    $refund = (object) [
                        'id' => $refundOperation->provider_refund_id,
                        'status' => $operationStatus,
                        'payment_intent' => $paymentRecord->stripe_payment_id,
                        'amount' => $storedRefundCents,
                        'currency' => strtolower((string) ($paymentRecord->currency ?: 'usd')),
                        'created' => $refundOperation->created_at?->timestamp ?? now()->timestamp,
                        'metadata' => (object) [
                            'app_payment_id' => (string) $paymentRecord->id,
                            'shoot_id' => (string) ($paymentRecord->shoot_id ?? ''),
                            'app_created_by' => (string) ($refundOperation->created_by ?? ''),
                            'app_refund_reason' => (string) ($refundOperation->reason ?? ''),
                            'app_refund_operation_key' => $requestedOperationKey,
                        ],
                    ];
                }
            }

            if (! isset($refund)) {
                $pendingRefundCents = (int) $paymentRecord->refunds
                    ->filter(fn ($refund) => in_array(
                        strtolower((string) ($refund->status ?? '')),
                        ['creating', 'pending', 'requires_action'],
                        true
                    ) && (! $refundOperation || $refund->id !== $refundOperation->id))
                    ->sum(fn ($refund) => (int) round(((float) $refund->amount) * 100));
                $remainderCents = max(
                    (int) round($paymentRecord->refundableRemainder() * 100) - $pendingRefundCents,
                    0
                );
                $remainder = round($remainderCents / 100, 2);

                if ($remainderCents <= 0) {
                    return response()->json([
                        'error' => 'A refund for the remaining amount is already pending in Stripe.',
                        'pending_refund_amount' => round($pendingRefundCents / 100, 2),
                    ], 409);
                }

                $requestedRefundCents = $refundOperation
                    ? (int) round(((float) $refundOperation->amount) * 100)
                    : ($request->has('amount')
                        ? (int) round(((float) $request->input('amount')) * 100)
                        : $remainderCents);

                if ($requestedRefundCents <= 0) {
                    return response()->json(['error' => 'Refund amount must be greater than zero.'], 422);
                }

                if ($requestedRefundCents > $remainderCents) {
                    return response()->json([
                        'error' => 'Refund amount cannot exceed the unrefunded remainder of this payment.',
                        'refundable_remainder' => $remainder,
                    ], 422);
                }

                if (! $refundOperation) {
                    // Persist the operation before the network call. A timeout
                    // leaves this row in `creating`, so the next request reuses
                    // the exact same Stripe idempotency key.
                    $refundOperation = DB::transaction(function () use (
                        $paymentRecord,
                        $requestedRefundCents,
                        $requestedOperationKey,
                        $request
                    ) {
                        // Use the same row-lock boundary as Dashboard webhook
                        // allocation. Every local payment sharing this Stripe
                        // PaymentIntent is locked in a deterministic order.
                        $intentPayments = Payment::with('refunds')
                            ->where('stripe_payment_id', $paymentRecord->stripe_payment_id)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get();
                        $lockedPayment = $intentPayments->firstWhere('id', $paymentRecord->id);

                        if (! $lockedPayment) {
                            throw ValidationException::withMessages([
                                'payment_id' => ['The Stripe payment is no longer available for refund.'],
                            ]);
                        }

                        $existingOperation = $lockedPayment->refunds
                            ->first(fn ($record) => (string) $record->operation_key === $requestedOperationKey);
                        if ($existingOperation) {
                            return $existingOperation;
                        }

                        $reservedCents = (int) $lockedPayment->refunds
                            ->filter(fn ($record) => in_array(
                                strtolower((string) ($record->status ?? '')),
                                ['creating', 'pending', 'requires_action'],
                                true
                            ))
                            ->sum(fn ($record) => (int) round(((float) $record->amount) * 100));
                        $availableCents = max(
                            (int) round($lockedPayment->refundableRemainder() * 100) - $reservedCents,
                            0
                        );

                        if ($requestedRefundCents > $availableCents) {
                            throw ValidationException::withMessages([
                                'amount' => ['Refund amount exceeds the currently refundable balance.'],
                            ]);
                        }

                        return $lockedPayment->refunds()->create([
                            'shoot_id' => $lockedPayment->shoot_id,
                            'amount' => round($requestedRefundCents / 100, 2),
                            'provider' => 'stripe',
                            'operation_key' => $requestedOperationKey,
                            'status' => 'creating',
                            'reason' => (string) $request->input('reason', ''),
                            'created_by' => $request->user()?->id,
                        ]);
                    });
                }

                $refundParams = [
                    'payment_intent' => $paymentRecord->stripe_payment_id,
                    'amount' => $requestedRefundCents,
                    'metadata' => [
                        'app_payment_id' => (string) $paymentRecord->id,
                        'shoot_id' => (string) ($paymentRecord->shoot_id ?? ''),
                        'app_created_by' => (string) ($refundOperation->created_by ?? ''),
                        'app_refund_reason' => $this->stripeMetadataValue((string) ($refundOperation->reason ?? '')),
                        'app_refund_operation_key' => $requestedOperationKey,
                    ],
                ];

                try {
                    $refund = Refund::create($refundParams, [
                        'idempotency_key' => 'repro_refund_'.hash('sha256', implode(':', [
                            $paymentRecord->id,
                            $requestedOperationKey,
                        ])),
                    ]);
                } catch (\Throwable $exception) {
                    $httpStatus = method_exists($exception, 'getHttpStatus')
                        ? (int) $exception->getHttpStatus()
                        : 0;
                    $isDefinitiveFailure = ($httpStatus >= 400
                            && $httpStatus < 500
                            && ! in_array($httpStatus, [409, 429], true))
                        || ($exception instanceof InvalidRequestException && $httpStatus === 0);

                    if ($isDefinitiveFailure) {
                        $refundOperation->newQuery()
                            ->whereKey($refundOperation->id)
                            ->where('status', 'creating')
                            ->update(['status' => 'failed']);

                        return response()->json([
                            'error' => 'Stripe rejected this refund request. No refund was created.',
                            'refund_operation_id' => $requestedOperationKey,
                            'refund_status' => 'failed',
                        ], 422);
                    }

                    throw $exception;
                }
            }

            if (in_array($refund->status, ['pending', 'requires_action'], true)) {
                if (! $this->handleStripeRefundEvent($refund, true)) {
                    throw new \RuntimeException('Stripe refund could not be recorded locally.');
                }

                return response()->json([
                    'status' => $refund->status,
                    'message' => 'Stripe is still processing this refund. The balance will update after it succeeds.',
                    'refund_operation_id' => $requestedOperationKey,
                    'refund' => $refund,
                ], 202);
            }

            if (in_array($refund->status, ['succeeded', 'completed'], true)) {
                if (! $this->handleStripeRefundEvent($refund, true)) {
                    throw new \RuntimeException('Stripe refund could not be recorded locally.');
                }

                $freshPayment = $paymentRecord->fresh(['refunds', 'shoot'])
                    ?? $paymentRecord->load(['refunds', 'shoot']);
                $responsePayload = [
                    'payment' => $this->stripePaymentMetadataService->serializePayment($freshPayment),
                ];

                if ($freshPayment->shoot) {
                    $paymentSummary = $freshPayment->shoot->fresh(['payments'])
                        ?->syncPaymentStatusFromRecords('stripe')
                        ?? $freshPayment->shoot->syncPaymentStatusFromRecords('stripe');
                    $responsePayload = array_merge($responsePayload, [
                        'total_paid' => $paymentSummary['total_paid'],
                        'payment_status' => $paymentSummary['payment_status'],
                        'receipt' => $this->buildReceiptPayloadForShoot(
                            $freshPayment->shoot->fresh(['payments'])
                                ?? $freshPayment->shoot->loadMissing('payments')
                        ),
                    ]);
                }

                Log::info("Stripe refund processed for payment ID: {$paymentRecord->id}");

                return response()->json([
                    'status' => 'success',
                    'refund_operation_id' => $requestedOperationKey,
                    'refund' => $refund,
                    'data' => $responsePayload,
                ]);
            }

            if (in_array($refund->status, ['failed', 'canceled', 'cancelled'], true)) {
                if (! $this->handleStripeRefundEvent($refund, true)) {
                    throw new \RuntimeException('Stripe refund failure could not be recorded locally.');
                }
            }

            return response()->json(['error' => 'Refund was not successful.', 'refund_status' => $refund->status], 400);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json(['error' => 'Failed to process refund.'], 500);
        } finally {
            optional($refundLock)->release();
        }
    }
}
