<?php

namespace App\Services\Shoots\Actions;

use App\Jobs\CreateCubiCasaOrderJob;
use App\Jobs\ProcessCreatedShootSideEffectsJob;
use App\Http\Requests\StoreShootRequest;
use App\Models\Shoot;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Schedule\ScheduleDateScopeService;
use App\Services\Messaging\ClientConfirmationRecoveryService;
use App\Services\ShootActivityLogger;
use App\Services\ShootWorkflowService;
use App\Services\Shoots\CreateShootResult;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateShootAction
{
    public function __construct(
        protected ShootMutationSupportService $support,
        protected ShootWorkflowService $workflowService,
        protected ShootActivityLogger $activityLogger,
        protected InvoiceService $invoiceService,
        protected AutomationService $automationService,
        protected ClientConfirmationRecoveryService $clientConfirmationRecoveryService,
        protected DropboxWorkflowService $dropboxService,
        protected MailService $mailService,
        protected GoogleCalendarSyncDispatcher $googleCalendarSyncDispatcher
    ) {
    }

    public function execute(StoreShootRequest $request, User $user): CreateShootResult
    {
        $validated = $request->validated();
        $validated['services'] = $validated['services'] ?? [];
        $this->support->ensureClientCanBookServices((int) $validated['client_id'], $validated['services']);
        $client = $this->support->ensureClientHasDeliverableEmail((int) $validated['client_id']);

        $result = DB::transaction(function () use ($validated, $user, $request, $client) {
            $userRole = strtolower($user->role ?? '');
            $scheduledAt = !empty($validated['scheduled_at'])
                ? new \DateTime($validated['scheduled_at'])
                : null;
            $servicesPayload = $this->support->mergeServiceItemPayload(
                $validated['services'],
                $validated['service_items'] ?? null,
                $request->input('service_photographers'),
                $scheduledAt
            );
            $pricingCalculation = $this->support->buildPricingCalculation(
                $servicesPayload,
                $client,
                $validated['state'] ?? null,
                $validated['tax_region'] ?? null,
                $validated['coupon_code'] ?? null
            );

            if (
                in_array($userRole, ['admin', 'superadmin', 'editing_manager', 'salesrep', 'sales_rep'], true)
                && array_key_exists('admin_adjusted_total_quote', $validated)
                && $validated['admin_adjusted_total_quote'] !== null
            ) {
                $pricingCalculation = $this->applyAdminAdjustedTotal(
                    $pricingCalculation,
                    (float) $validated['admin_adjusted_total_quote']
                );
            }

            $repId = $validated['rep_id'] ?? $this->support->getClientRep($validated['client_id']);

            $isClient = $userRole === 'client';
            $isClientSelfBooking = $validated['client_id'] == $user->id;
            $isClientRequestFlag = $request->input('is_client_request', false);

            Log::info('Shoot creation role check', [
                'user_id' => $user->id,
                'client_id' => $validated['client_id'],
                'user_role_raw' => $user->role,
                'user_role_normalized' => $userRole,
                'is_client' => $isClient,
                'is_admin_or_rep' => in_array($userRole, ['admin', 'superadmin', 'editing_manager', 'rep', 'salesrep']),
                'is_client_self_booking' => $isClientSelfBooking,
                'is_client_request_flag' => $isClientRequestFlag,
            ]);

            $treatAsClientRequest = $isClient || $isClientSelfBooking || $isClientRequestFlag;

            if ($treatAsClientRequest) {
                $initialStatus = Shoot::STATUS_REQUESTED;
                $workflowStatus = Shoot::STATUS_REQUESTED;
                $photographerId = $validated['photographer_id'] ?? null;
                Log::info('Client shoot - setting requested status', [
                    'status' => $initialStatus,
                    'photographer_id' => $photographerId,
                ]);
            } else {
                $initialStatus = 'hold_on';
                $workflowStatus = Shoot::STATUS_SCHEDULED;
                $photographerId = $validated['photographer_id'] ?? null;
            }

            if (!$treatAsClientRequest && $photographerId && $scheduledAt) {
                $carbonDate = \Carbon\Carbon::parse($scheduledAt);
                DB::table('shoots')
                    ->where('photographer_id', $photographerId)
                    ->whereDate('scheduled_at', $carbonDate->toDateString())
                    ->lockForUpdate()
                    ->get();

                $durationMinutes = $this->support->calculateShootDurationFromServices($servicesPayload);
                // Enforce the same backend-authoritative availability bounds as the update path.
                $this->support->assertWithinAvailabilityBounds($photographerId, $scheduledAt, $durationMinutes);
            }

            if (!$treatAsClientRequest) {
                $this->support->checkServiceItemPhotographerAvailability(
                    $servicesPayload,
                    $photographerId
                );
            }

            $propertyDetailsPayload = is_array($validated['property_details'] ?? null)
                ? $validated['property_details']
                : [];
            $listingType = $validated['listing_type']
                ?? data_get($propertyDetailsPayload, 'listing_type')
                ?? data_get($propertyDetailsPayload, 'listingType')
                ?? 'for_sale';
            $propertyStatus = $validated['property_status']
                ?? data_get($propertyDetailsPayload, 'property_status')
                ?? data_get($propertyDetailsPayload, 'propertyStatus')
                ?? data_get($propertyDetailsPayload, 'status')
                ?? 'available';
            if (!in_array($listingType, ['for_sale', 'for_rent'], true)) {
                $listingType = 'for_sale';
            }
            if (!in_array($propertyStatus, ['available', 'coming_soon', 'pending', 'sold', 'rented'], true)) {
                $propertyStatus = 'available';
            }
            $autoPropertyTourLinks = array_filter([
                'property_mls' => $validated['mls_id']
                    ?? data_get($propertyDetailsPayload, 'mls_id')
                    ?? data_get($propertyDetailsPayload, 'mlsId'),
                'property_price' => data_get($propertyDetailsPayload, 'price'),
                'property_lot_size' => data_get($propertyDetailsPayload, 'lot_size')
                    ?? data_get($propertyDetailsPayload, 'lotSize'),
            ], static fn ($value) => $value !== null && $value !== '');
            $initialTourLinks = [];
            if (array_key_exists('tour_links', $validated) && is_array($validated['tour_links'])) {
                $initialTourLinks = $validated['tour_links'];
            }
            if (!empty($autoPropertyTourLinks)) {
                $initialTourLinks = array_merge($autoPropertyTourLinks, $initialTourLinks);
            }

            $shootType = $this->normalizeShootType($validated['shoot_type'] ?? null, $servicesPayload);
            $productStatus = $this->resolveProductStatus($servicesPayload, (float) $pricingCalculation['total_quote'], $validated['product_status'] ?? null);
            $isNoCharge = (float) $pricingCalculation['total_quote'] <= 0.01;
            $scheduleScope = app(ScheduleDateScopeService::class);
            $scheduledDate = $scheduledAt
                ? ($scheduleScope->localDateForScheduledAt($scheduledAt, $validated['timezone'] ?? null) ?? $scheduledAt->format('Y-m-d'))
                : null;
            $scheduledTime = $scheduledAt
                ? ($scheduleScope->localTimeForScheduledAt($scheduledAt, $validated['timezone'] ?? null) ?? $scheduledAt->format('H:i'))
                : ($validated['time'] ?? null);

            $shoot = Shoot::create([
                'client_id' => $validated['client_id'],
                'rep_id' => $repId,
                'photographer_id' => $photographerId,
                'service_id' => $servicesPayload[0]['id'] ?? null,
                'address' => $validated['address'],
                'city' => $validated['city'],
                'state' => $validated['state'],
                'zip' => $validated['zip'],
                'mls_id' => $validated['mls_id']
                    ?? data_get($propertyDetailsPayload, 'mls_id')
                    ?? data_get($propertyDetailsPayload, 'mlsId'),
                'listing_source' => $validated['listing_source'] ?? null,
                'property_details' => $validated['property_details'] ?? null,
                'listing_type' => $listingType,
                'property_status' => $propertyStatus,
                'tour_links' => !empty($initialTourLinks) ? $initialTourLinks : null,
                'scheduled_at' => $scheduledAt,
                'scheduled_date' => $scheduledDate,
                'time' => $scheduledTime,
                'status' => $initialStatus,
                'workflow_status' => $workflowStatus,
                'base_quote' => $pricingCalculation['base_quote'],
                'discount_type' => $pricingCalculation['discount_type'],
                'discount_value' => $pricingCalculation['discount_value'],
                'discount_amount' => $pricingCalculation['discount_amount'],
                'tax_region' => $pricingCalculation['tax_region'],
                'tax_percent' => $pricingCalculation['tax_percent'],
                'tax_amount' => $pricingCalculation['tax_amount'],
                'total_quote' => $pricingCalculation['total_quote'],
                'bypass_paywall' => $isNoCharge || (bool) ($validated['bypass_paywall'] ?? false),
                'payment_status' => $isNoCharge ? 'paid' : 'unpaid',
                'shoot_type' => $shootType,
                'product_status' => $productStatus,
                'created_by' => $user->name,
                'updated_by' => $user->name,
                'package_name' => $validated['package_name'] ?? null,
                'expected_final_count' => $validated['expected_final_count'] ?? null,
                // Legacy shoot-wide bracket value. Still accepted so existing API
                // clients keep working, and read as the last fallback when a service
                // item has no bracket size of its own, but it is no longer the
                // source of truth: `attachServices` below snapshots the real value
                // onto each bracketed service item.
                'bracket_mode' => $validated['bracket_mode'] ?? null,
                // `expected_raw_count` is not stored. It is the sum over service
                // items of photo_count x that item's own bracket size, which one
                // shoot-wide multiplication cannot express once two services are
                // shot at different sizes, and a stored copy silently went stale
                // (it was 0 on every shoot in production because it was derived
                // from an expected_final_count that is never populated).
                'shoot_notes' => $validated['shoot_notes'] ?? null,
                'company_notes' => $validated['company_notes'] ?? null,
                'photographer_notes' => $validated['photographer_notes'] ?? null,
                'editor_notes' => $validated['editor_notes'] ?? null,
            ]);

            $this->support->attachServices($shoot, $servicesPayload);

            if (!empty($pricingCalculation['coupon_code']) && $pricingCalculation['coupon_discount_amount'] > 0) {
                $coupon = $this->support->resolveCoupon($pricingCalculation['coupon_code']);
                if ($coupon) {
                    $coupon->increment('current_uses');
                }
            }

            if ($scheduledAt && !$treatAsClientRequest) {
                try {
                    $this->invoiceService->generateForShoot($shoot);
                } catch (\Exception $e) {
                    Log::warning('Failed to auto-create invoice for shoot', [
                        'shoot_id' => $shoot->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->support->createNotes($shoot, $validated, $user);

            if (!$treatAsClientRequest && $scheduledAt) {
                $this->workflowService->schedule($shoot, $scheduledAt, $user);
            }

            $this->activityLogger->log(
                $shoot,
                $treatAsClientRequest ? 'shoot_requested' : 'shoot_created',
                [
                    'by' => $user->name,
                    'status' => $initialStatus,
                    'scheduled_at' => $scheduledAt ? \Carbon\Carbon::instance($scheduledAt)->toIso8601String() : null,
                ],
                $user
            );

            return new CreateShootResult($shoot, $treatAsClientRequest, $scheduledAt);
        });

        $this->registerDeferredSideEffects($result);

        if (
            !$result->treatAsClientRequest
            && $result->scheduledAt !== null
            && $result->shoot->hasCubiCasaEligibleService()
        ) {
            // CubiCasa order creation is a post-booking side effect: it must run when the
            // booking is complete, but must NEVER block or fail the booking itself. On a
            // synchronous queue the dispatched job executes at destruct time, so a transient
            // CubiCasa failure (which the job throws to enable retries on a real queue) would
            // otherwise bubble up and 500 the booking after the shoot was already created.
            // Contain it here so the client always reaches the success page; on an async queue
            // the job is simply queued (no inline throw) and retries normally.
            try {
                $pending = CreateCubiCasaOrderJob::dispatch($result->shoot->id, 'booking')->afterCommit();
                // Force the PendingDispatch to resolve here (inside the try) so any synchronous
                // execution throw is caught rather than surfacing at end-of-scope destruct.
                unset($pending);
            } catch (\Throwable $e) {
                Log::warning('CubiCasa auto-create failed during booking; booking completed regardless.', [
                    'shoot_id' => $result->shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->googleCalendarSyncDispatcher->dispatchShootSync($result->shoot->id);

        return $result;
    }

    protected function applyAdminAdjustedTotal(array $pricingCalculation, float $adjustedTotalQuote): array
    {
        $totalQuote = round(max($adjustedTotalQuote, 0), 2);
        $taxPercent = (float) ($pricingCalculation['tax_percent'] ?? 0);
        $baseQuote = $taxPercent > 0
            ? round($totalQuote / (1 + ($taxPercent / 100)), 2)
            : $totalQuote;
        $taxAmount = round($totalQuote - $baseQuote, 2);

        return array_merge($pricingCalculation, [
            'service_subtotal' => $baseQuote,
            'base_quote' => $baseQuote,
            'discount_type' => null,
            'discount_value' => null,
            'discount_amount' => 0.0,
            'discounted_subtotal' => $baseQuote,
            'tax_amount' => $taxAmount,
            'total_quote' => $totalQuote,
        ]);
    }

    protected function normalizeShootType(?string $shootType, array $servicesPayload): string
    {
        $shootType = $shootType ?: Shoot::SHOOT_TYPE_STANDARD;

        if (in_array($shootType, Shoot::INTERNAL_NO_CHARGE_SHOOT_TYPES, true)) {
            return $shootType;
        }

        return empty($servicesPayload) ? Shoot::SHOOT_TYPE_COMPLIMENTARY : Shoot::SHOOT_TYPE_STANDARD;
    }

    protected function resolveProductStatus(array $servicesPayload, float $totalQuote, ?string $requestedStatus): string
    {
        if (empty($servicesPayload)) {
            return Shoot::PRODUCT_STATUS_NO_PRODUCT;
        }

        if ($requestedStatus && in_array($requestedStatus, [
            Shoot::PRODUCT_STATUS_HAS_PRODUCT,
            Shoot::PRODUCT_STATUS_ZERO_DOLLAR_PRODUCT,
        ], true)) {
            return $requestedStatus;
        }

        return $totalQuote <= 0.01
            ? Shoot::PRODUCT_STATUS_ZERO_DOLLAR_PRODUCT
            : Shoot::PRODUCT_STATUS_HAS_PRODUCT;
    }

    protected function registerDeferredSideEffects(CreateShootResult $result): void
    {
        ProcessCreatedShootSideEffectsJob::dispatch(
            $result->shoot->id,
            $result->treatAsClientRequest,
            $result->scheduledAt !== null
        )->afterCommit();
    }
}
