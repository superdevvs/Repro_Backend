<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Invoices\InvoiceAdjustmentService;
use App\Services\InvoiceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShootEditablePayloadService
{
    public function __construct(
        protected ShootMutationSupportService $support,
        protected InvoiceService $invoiceService,
        protected InvoiceAdjustmentService $invoiceAdjustments,
        protected ShootNotesCompatibilityService $notesCompatibility,
        protected ShootServiceChangeGuard $serviceChangeGuard,
        protected ShootServiceItemSupport $serviceItemSupport,
        protected ShootActivityLogger $activityLogger
    ) {}

    public function validationRules(): array
    {
        return [
            'scheduled_date' => 'nullable|date',
            'scheduled_at' => 'nullable|date',
            'time' => 'nullable|string',
            'alternate_scheduled_date' => 'nullable|date',
            'alternate_time' => 'nullable|string',
            'alternate_scheduled_at' => 'nullable|date',
            'timezone' => 'nullable|string|timezone',
            'services' => 'nullable|array',
            'services.*.id' => 'required_with:services|integer|distinct|exists:services,id',
            'services.*.price' => 'nullable|numeric|min:0',
            'services.*.quantity' => 'nullable|integer|min:1',
            'services.*.photographer_id' => 'nullable|integer|exists:users,id',
            'services.*.editor_id' => 'nullable|integer|exists:users,id',
            'services.*.scheduled_at' => 'nullable|date',
            'services.*.is_deliverable' => 'nullable|boolean',
            'service_items' => 'nullable|array',
            'service_items.*.service_id' => 'required_with:service_items|integer|distinct|exists:services,id',
            'service_items.*.price' => 'nullable|numeric|min:0',
            'service_items.*.quantity' => 'nullable|integer|min:1',
            'service_items.*.photographer_id' => 'nullable|integer|exists:users,id',
            'service_items.*.editor_id' => 'nullable|integer|exists:users,id',
            'service_items.*.scheduled_at' => 'nullable|date',
            'service_items.*.is_deliverable' => 'nullable|boolean',
            'service_items.*.workflow_status' => ['nullable', Rule::in(['pending', 'scheduled', 'in_progress', 'ready', 'delivered', 'cancelled'])],
            'service_items.*.delivery_status' => ['nullable', Rule::in(['not_started', 'ready', 'delivered', 'cancelled'])],
            'service_items.*.force_unlock_delivery' => 'nullable|boolean',
            'service_items.*.unlock_reason' => 'nullable|string|max:2000',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:2',
            'zip' => 'nullable|string|max:10',
            'client_id' => 'nullable|exists:users,id',
            'photographer_id' => 'nullable|exists:users,id',
            'base_quote' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|string|in:fixed,percent',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'total_quote' => 'nullable|numeric|min:0',
            'admin_adjusted_total_quote' => 'nullable|numeric|min:0',
            'confirm_service_detach' => 'nullable|boolean',
            'service_detach_confirmation_token' => 'nullable|string|size:64',
            'property_details' => 'nullable|array',
            'mls_image_width' => 'nullable|integer|min:1|max:10000',
            'bedrooms' => 'nullable|integer|min:0',
            'bathrooms' => 'nullable|numeric|min:0',
            'sqft' => 'nullable|integer|min:0',
            'tour_links' => 'nullable|array',
            'tour_links.*' => 'nullable',
            'iguide_property_id' => 'nullable|string|max:128',
            'iguide_work_order_id' => 'nullable|string|max:128',
            'listing_type' => 'nullable|string|in:for_sale,for_rent',
            'property_status' => 'nullable|string|in:available,coming_soon,pending,sold,rented',
            'is_featured' => 'nullable|boolean',
            'featured_homepage_title' => 'nullable|string|max:255',
            'featured_homepage_location' => 'nullable|string|max:255',
            'featured_homepage_subtitle' => 'nullable|string|max:255',
            'featured_homepage_cta_label' => 'nullable|string|max:80',
            'featured_homepage_cta_href' => 'nullable|string|max:255',
            'featured_homepage_images' => 'nullable|array|min:0|max:10',
            'featured_homepage_images.*.shoot_file_id' => 'required_with:featured_homepage_images|integer|exists:shoot_files,id',
            'featured_homepage_images.*.sort' => 'nullable|integer|min:1|max:999',
            'featured_homepage_images.*.sort_order' => 'nullable|integer|min:1|max:999',
            'featured_homepage_images.*.alt' => 'nullable|string|max:255',
            'featured_homepage_images.*.alt_text' => 'nullable|string|max:255',
            'featured_homepage_images.*.focal' => ['nullable', 'string', 'max:32', 'regex:/^\d{1,3}%\s+\d{1,3}%$/'],
            'featured_homepage_images.*.focal_point' => ['nullable', 'string', 'max:32', 'regex:/^\d{1,3}%\s+\d{1,3}%$/'],
            'shoot_notes' => 'nullable|string',
            'company_notes' => 'nullable|string',
            'photographer_notes' => 'nullable|string',
            'editor_notes' => 'nullable|string',
            'notify_client' => 'nullable|boolean',
            'notify_photographer' => 'nullable|boolean',
            'service_photographers' => 'nullable|array',
            'service_photographers.*.service_id' => 'required_with:service_photographers|integer',
            'service_photographers.*.photographer_id' => 'nullable|integer|exists:users,id',
            'shoot_type' => [
                'nullable',
                Rule::in([
                    Shoot::SHOOT_TYPE_STANDARD,
                    Shoot::SHOOT_TYPE_COMPLIMENTARY,
                    Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT,
                    Shoot::SHOOT_TYPE_SAMPLE_UPLOAD,
                    Shoot::SHOOT_TYPE_INTERNAL_TEST,
                    Shoot::SHOOT_TYPE_PRICING_PENDING,
                ]),
            ],
            'product_status' => [
                'nullable',
                Rule::in([
                    Shoot::PRODUCT_STATUS_HAS_PRODUCT,
                    Shoot::PRODUCT_STATUS_NO_PRODUCT,
                    Shoot::PRODUCT_STATUS_ZERO_DOLLAR_PRODUCT,
                ]),
            ],
        ];
    }

    public function apply(Shoot $shoot, array $validated, ?User $actor = null): void
    {
        // UpdateShootAction stages a small set of workflow/listing attributes on
        // the caller model before handing off here. Carry those values onto the
        // locked row so a mixed details + service save is one atomic write and a
        // refresh cannot silently discard them.
        $pendingAttributes = $shoot->getDirty();

        DB::transaction(function () use ($shoot, $validated, $actor, $pendingAttributes) {
            $lockedShoot = Shoot::query()
                ->with(['client', 'serviceItems.service'])
                ->lockForUpdate()
                ->findOrFail($shoot->id);

            $serviceChangeRequested = array_key_exists('services', $validated)
                || array_key_exists('service_items', $validated);
            $hasAdjustedTotal = array_key_exists('admin_adjusted_total_quote', $validated)
                && $validated['admin_adjusted_total_quote'] !== null;
            $serviceDetachImpact = null;

            $this->assertComplimentaryReshootMutationAllowed($lockedShoot, $validated);

            if ($serviceChangeRequested) {
                $conflictingPricingFields = array_intersect(
                    ['base_quote', 'tax_amount', 'total_quote'],
                    array_keys($validated)
                );
                if (! empty($conflictingPricingFields)) {
                    throw ValidationException::withMessages([
                        'pricing' => [
                            'Service changes are priced by the server. Use admin_adjusted_total_quote for an intentional Admin override.',
                        ],
                    ]);
                }
            }

            if ($serviceChangeRequested && ! $actor) {
                throw ValidationException::withMessages([
                    'services' => ['An authenticated actor is required to change booked services.'],
                ]);
            }

            if ($serviceChangeRequested) {
                $targetServices = $this->targetServicesFor($lockedShoot, $validated, $actor);
                $serviceDetachImpact = $this->serviceChangeGuard->assertChangeAllowed(
                    $lockedShoot,
                    $targetServices,
                    $actor,
                    (bool) ($validated['confirm_service_detach'] ?? false),
                    $validated['service_detach_confirmation_token'] ?? null,
                    $hasAdjustedTotal
                        ? (float) $validated['admin_adjusted_total_quote']
                        : null,
                    $validated['state'] ?? $lockedShoot->state,
                    array_key_exists('state', $validated) ? null : ($lockedShoot->tax_region ?: null)
                );
            }

            if (! empty($pendingAttributes)) {
                $lockedShoot->forceFill($pendingAttributes);
            }

            $this->applyWithinTransaction($lockedShoot, $validated, $actor);

            if ($serviceDetachImpact !== null) {
                $this->activityLogger->log($lockedShoot, 'shoot_services_detached', [
                    'by' => $actor->name,
                    'service_detach_impact' => $serviceDetachImpact,
                    'suppress_notifications' => true,
                ], $actor);
            }
        });

        // Keep the caller's model instance aligned with the row that was
        // mutated under lock (approval and update actions continue using it).
        $shoot->refresh();
    }

    protected function applyWithinTransaction(Shoot $shoot, array $validated, ?User $actor = null): void
    {
        $shoot->loadMissing('services');

        if (($validated['shoot_type'] ?? null) === Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT
            && ! $shoot->isComplimentaryReshoot()) {
            throw ValidationException::withMessages([
                'shoot_type' => ['Create complimentary reshoots from the dedicated reshoot action so lineage and compensation decisions are recorded.'],
            ]);
        }

        $this->assertComplimentaryReshootMutationAllowed($shoot, $validated);

        $noteFields = ['shoot_notes', 'company_notes', 'photographer_notes', 'editor_notes'];
        $previousNotes = [];
        foreach ($noteFields as $field) {
            if (array_key_exists($field, $validated)) {
                $previousNotes[$field] = $shoot->{$field};
            }
        }

        $invoiceNeedsRefresh = false;
        $serviceChangeRequested = array_key_exists('services', $validated)
            || array_key_exists('service_items', $validated);
        $hasAdjustedTotal = array_key_exists('admin_adjusted_total_quote', $validated)
            && $validated['admin_adjusted_total_quote'] !== null;
        $previousProductStatus = (string) ($shoot->product_status ?? Shoot::PRODUCT_STATUS_HAS_PRODUCT);
        $previousBypassPaywall = (bool) $shoot->bypass_paywall;
        $paymentFieldsProvided = ! $serviceChangeRequested && (array_key_exists('base_quote', $validated)
            || array_key_exists('discount_type', $validated)
            || array_key_exists('discount_value', $validated)
            || array_key_exists('discount_amount', $validated)
            || array_key_exists('tax_amount', $validated)
            || array_key_exists('total_quote', $validated));
        $targetClientId = (int) ($validated['client_id'] ?? $shoot->client_id);
        $targetServices = $this->targetServicesFor($shoot, $validated, $actor);

        $this->support->ensureClientCanBookServices($targetClientId, $targetServices);

        if (array_key_exists('scheduled_at', $validated) && $validated['scheduled_at']) {
            $shoot->scheduled_at = new \DateTime($validated['scheduled_at']);
        }
        if (array_key_exists('scheduled_date', $validated)) {
            $shoot->scheduled_date = $validated['scheduled_date'];
        }
        if (array_key_exists('time', $validated)) {
            $shoot->time = $validated['time'];
        }
        if (array_key_exists('timezone', $validated)) {
            $shoot->timezone = $validated['timezone'];
        }

        // Persist the shoot-level alternate schedule. Touches no service pivots (Req 3.2).
        $altDateProvided = array_key_exists('alternate_scheduled_date', $validated);
        $altTimeProvided = array_key_exists('alternate_time', $validated);
        $altAtProvided = array_key_exists('alternate_scheduled_at', $validated);

        if ($altDateProvided) {
            $shoot->alternate_scheduled_date = $validated['alternate_scheduled_date'] ?: null;
        }
        if ($altTimeProvided) {
            $shoot->alternate_time = $validated['alternate_time'] ?: null;
        }
        if ($altAtProvided && $validated['alternate_scheduled_at']) {
            $shoot->alternate_scheduled_at = new \DateTime($validated['alternate_scheduled_at']);
        } elseif ($altDateProvided || $altTimeProvided) {
            // Derive from date+time; null time => null scheduled_at (mirrors resolveSchedule).
            $date = $shoot->alternate_scheduled_date
                ? $shoot->alternate_scheduled_date->toDateString()
                : null;
            $time = $shoot->alternate_time;
            $shoot->alternate_scheduled_at = ($date && $time)
                ? Carbon::parse("{$date} {$time}")
                : null;
        }

        if (
            array_key_exists('services', $validated)
            || array_key_exists('service_items', $validated)
            || array_key_exists('service_photographers', $validated)
        ) {
            $this->support->attachServices($shoot, $targetServices);
            $shoot->service_id = collect($targetServices)
                ->map(fn (array $service) => (int) ($service['id'] ?? $service['service_id'] ?? 0))
                ->filter()
                ->first();
            $invoiceNeedsRefresh = array_key_exists('services', $validated) || array_key_exists('service_items', $validated);
        }

        if (array_key_exists('address', $validated)) {
            $shoot->address = $validated['address'];
        }
        if (array_key_exists('city', $validated)) {
            $shoot->city = $validated['city'];
        }
        if (array_key_exists('state', $validated)) {
            $shoot->state = $validated['state'];
        }
        if (array_key_exists('zip', $validated)) {
            $shoot->zip = $validated['zip'];
        }
        if (array_key_exists('client_id', $validated)) {
            $shoot->client_id = $validated['client_id'];
        }
        if (array_key_exists('photographer_id', $validated)) {
            $shoot->photographer_id = $validated['photographer_id'];
        }
        if (array_key_exists('mls_image_width', $validated)) {
            $shoot->mls_image_width = $validated['mls_image_width'];
        }
        if (! $serviceChangeRequested && array_key_exists('base_quote', $validated)) {
            $shoot->base_quote = $validated['base_quote'];
        }
        if (! $serviceChangeRequested && array_key_exists('tax_amount', $validated)) {
            $shoot->tax_amount = $validated['tax_amount'];
        }
        if (! $serviceChangeRequested && array_key_exists('total_quote', $validated)) {
            $shoot->total_quote = $validated['total_quote'];
        }
        if (array_key_exists('shoot_type', $validated)) {
            $shoot->shoot_type = $validated['shoot_type'] ?: Shoot::SHOOT_TYPE_STANDARD;
        }
        if (array_key_exists('product_status', $validated)) {
            $shoot->product_status = $validated['product_status'] ?: Shoot::PRODUCT_STATUS_HAS_PRODUCT;
        }
        if (! $serviceChangeRequested && array_key_exists('discount_type', $validated)) {
            $shoot->discount_type = $validated['discount_type'];
        }
        if (! $serviceChangeRequested && array_key_exists('discount_value', $validated)) {
            $shoot->discount_value = $validated['discount_value'];
        }
        if (! $serviceChangeRequested && array_key_exists('discount_amount', $validated)) {
            $shoot->discount_amount = $validated['discount_amount'];
        }
        if ($paymentFieldsProvided) {
            $invoiceNeedsRefresh = true;
        }

        $shouldRecalculatePricing = $serviceChangeRequested
            || $hasAdjustedTotal
            || (! $paymentFieldsProvided && (
            array_key_exists('client_id', $validated)
            || array_key_exists('state', $validated)
        ));

        $serviceSubtotal = $this->support->calculateBaseQuote($targetServices);

        if ($shouldRecalculatePricing) {
            $taxRegion = array_key_exists('state', $validated)
                ? null
                : ($shoot->tax_region ?: null);
            $pricingCalculation = $serviceChangeRequested
                ? $this->support->buildPricingCalculationForExistingShoot(
                    $targetServices,
                    $shoot,
                    $validated['state'] ?? $shoot->state ?? null,
                    $taxRegion
                )
                : $this->support->buildPricingCalculation(
                    $targetServices,
                    User::find($targetClientId),
                    $validated['state'] ?? $shoot->state ?? null,
                    $taxRegion
                );
            $serviceSubtotal = (float) $pricingCalculation['service_subtotal'];
            $billableAdjustments = (float) $this->invoiceAdjustments
                ->billableItemsForShoot($shoot)
                ->sum(fn ($item) => (float) $item->total_amount);

            if ($hasAdjustedTotal) {
                $normalizedRole = strtolower((string) ($actor?->role ?? ''));
                if (! in_array($normalizedRole, ['admin', 'superadmin', 'super_admin'], true)) {
                    throw ValidationException::withMessages([
                        'admin_adjusted_total_quote' => ['Only Admin and Super Admin can set an adjusted total.'],
                    ]);
                }

                $adjustedTotal = round((float) $validated['admin_adjusted_total_quote'], 2);
                if ($adjustedTotal + 0.001 < $billableAdjustments) {
                    throw ValidationException::withMessages([
                        'admin_adjusted_total_quote' => ['Adjusted total cannot be lower than retained billable invoice adjustments.'],
                    ]);
                }

                $taxInclusiveServiceTotal = round(max($adjustedTotal - $billableAdjustments, 0), 2);
                $taxPercent = (float) ($pricingCalculation['tax_percent'] ?? 0);
                $baseQuote = $taxPercent > 0
                    ? round($taxInclusiveServiceTotal / (1 + ($taxPercent / 100)), 2)
                    : $taxInclusiveServiceTotal;

                $shoot->base_quote = $baseQuote;
                $shoot->discount_type = null;
                $shoot->discount_value = null;
                $shoot->discount_amount = 0;
                $shoot->tax_region = $pricingCalculation['tax_region'];
                $shoot->tax_percent = $taxPercent;
                $shoot->tax_amount = round($taxInclusiveServiceTotal - $baseQuote, 2);
                $shoot->total_quote = $adjustedTotal;
            } else {
                $shoot->base_quote = $pricingCalculation['base_quote'];
                $shoot->discount_type = $pricingCalculation['discount_type'];
                $shoot->discount_value = $pricingCalculation['discount_value'];
                $shoot->discount_amount = $pricingCalculation['discount_amount'];
                $shoot->tax_region = $pricingCalculation['tax_region'];
                $shoot->tax_percent = $pricingCalculation['tax_percent'];
                $shoot->tax_amount = $pricingCalculation['tax_amount'];
                $shoot->total_quote = round($pricingCalculation['total_quote'] + $billableAdjustments, 2);
            }

            $invoiceNeedsRefresh = true;
        }

        if ($serviceChangeRequested || $shouldRecalculatePricing || $paymentFieldsProvided) {
            $hasServices = count($targetServices) > 0;
            if (! $hasServices) {
                $shoot->product_status = Shoot::PRODUCT_STATUS_NO_PRODUCT;
            } elseif ($serviceSubtotal <= 0.01) {
                $shoot->product_status = Shoot::PRODUCT_STATUS_ZERO_DOLLAR_PRODUCT;
            } elseif (! array_key_exists('product_status', $validated)) {
                $shoot->product_status = Shoot::PRODUCT_STATUS_HAS_PRODUCT;
            }

            if ((float) ($shoot->total_quote ?? 0) <= 0.01) {
                $shoot->payment_status = 'paid';
                $shoot->bypass_paywall = true;
            } elseif ($previousBypassPaywall && in_array($previousProductStatus, [
                Shoot::PRODUCT_STATUS_NO_PRODUCT,
                Shoot::PRODUCT_STATUS_ZERO_DOLLAR_PRODUCT,
            ], true)) {
                $shoot->bypass_paywall = false;
            }
        }

        $propertyDetails = $shoot->property_details ?? [];
        if (is_string($propertyDetails)) {
            $propertyDetails = json_decode($propertyDetails, true) ?? [];
        }

        $propertyDetailsUpdated = false;
        if (array_key_exists('property_details', $validated) && is_array($validated['property_details'])) {
            $propertyDetails = array_merge($propertyDetails, $validated['property_details']);
            $propertyDetailsUpdated = true;
        }
        if (array_key_exists('bedrooms', $validated)) {
            $propertyDetails['bedrooms'] = $validated['bedrooms'];
            $propertyDetails['beds'] = $validated['bedrooms'];
            $propertyDetailsUpdated = true;
        }
        if (array_key_exists('bathrooms', $validated)) {
            $propertyDetails['bathrooms'] = $validated['bathrooms'];
            $propertyDetails['baths'] = $validated['bathrooms'];
            $propertyDetailsUpdated = true;
        }
        if (array_key_exists('sqft', $validated)) {
            $propertyDetails['sqft'] = $validated['sqft'];
            $propertyDetails['squareFeet'] = $validated['sqft'];
            $propertyDetailsUpdated = true;
        }

        if ($propertyDetailsUpdated) {
            $shoot->property_details = $propertyDetails;
            $shoot->mls_id = $validated['mls_id']
                ?? data_get($propertyDetails, 'mls_id')
                ?? data_get($propertyDetails, 'mlsId')
                ?? $shoot->mls_id;
            $invoiceNeedsRefresh = true;
        }

        if (array_key_exists('listing_type', $validated)) {
            $shoot->listing_type = $validated['listing_type'];
        }
        if (array_key_exists('iguide_property_id', $validated)) {
            $value = $validated['iguide_property_id'];
            $shoot->iguide_property_id = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        if (array_key_exists('iguide_work_order_id', $validated)) {
            $value = $validated['iguide_work_order_id'];
            $shoot->iguide_work_order_id = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        if (array_key_exists('property_status', $validated)) {
            $shoot->property_status = $validated['property_status'];
        }
        if (array_key_exists('is_featured', $validated)) {
            $nextFeaturedState = (bool) $validated['is_featured'];
            $shoot->is_featured = $nextFeaturedState;
        }

        foreach ([
            'featured_homepage_title',
            'featured_homepage_location',
            'featured_homepage_subtitle',
            'featured_homepage_cta_label',
            'featured_homepage_cta_href',
        ] as $featuredField) {
            if (array_key_exists($featuredField, $validated)) {
                $value = $validated[$featuredField];
                $shoot->{$featuredField} = is_string($value) && trim($value) !== '' ? trim($value) : null;
            }
        }

        $autoPropertyTourLinks = [];
        if ($propertyDetailsUpdated) {
            $autoPropertyTourLinks = array_filter([
                'property_mls' => $validated['mls_id']
                    ?? data_get($propertyDetails, 'mls_id')
                    ?? data_get($propertyDetails, 'mlsId'),
                'property_price' => data_get($propertyDetails, 'price'),
                'property_lot_size' => data_get($propertyDetails, 'lot_size')
                    ?? data_get($propertyDetails, 'lotSize'),
            ], static fn ($value) => $value !== null && $value !== '');
        }

        if (! empty($autoPropertyTourLinks)) {
            $currentTourLinks = $shoot->tour_links ?? [];
            if (is_string($currentTourLinks)) {
                $currentTourLinks = json_decode($currentTourLinks, true) ?? [];
            }
            $shoot->tour_links = array_merge($currentTourLinks, $autoPropertyTourLinks);
        }

        if (array_key_exists('tour_links', $validated) && is_array($validated['tour_links'])) {
            $currentTourLinks = $shoot->tour_links ?? [];
            if (is_string($currentTourLinks)) {
                $currentTourLinks = json_decode($currentTourLinks, true) ?? [];
            }
            $submittedTourLinks = $validated['tour_links'];
            // Provenance is server-owned. Entering a URL in the dedicated MLS
            // slot is an explicit admin attestation that it is the unbranded
            // destination; callers cannot forge provider provenance separately.
            unset($submittedTourLinks['iguide_mls_source']);
            if (array_key_exists('iguide_mls', $submittedTourLinks)) {
                $submittedTourLinks['iguide_mls_source'] = filled($submittedTourLinks['iguide_mls'])
                    ? 'manual'
                    : null;
            }
            $shoot->tour_links = array_merge($currentTourLinks, $submittedTourLinks);
        }

        if (array_key_exists('shoot_notes', $validated)) {
            $shoot->shoot_notes = $validated['shoot_notes'];
        }
        if (array_key_exists('company_notes', $validated)) {
            $shoot->company_notes = $validated['company_notes'];
        }
        if (array_key_exists('photographer_notes', $validated)) {
            $shoot->photographer_notes = $validated['photographer_notes'];
        }
        if (array_key_exists('editor_notes', $validated)) {
            $shoot->editor_notes = $validated['editor_notes'];
        }

        DB::transaction(function () use ($shoot, $validated, $actor, $previousNotes) {
            $shoot->save();

            foreach ($previousNotes as $field => $previousContent) {
                $this->notesCompatibility->syncScalarField(
                    $shoot,
                    $field,
                    $shoot->{$field},
                    $actor,
                    $previousContent
                );
            }

            if (array_key_exists('featured_homepage_images', $validated)) {
                $this->syncFeaturedHomepageImages($shoot, $validated['featured_homepage_images'] ?? []);
            }
        });

        if ($serviceChangeRequested) {
            $this->serviceItemSupport->reconcileAllocationsAfterServiceChange($shoot->fresh());
        }

        if ($shouldRecalculatePricing || $paymentFieldsProvided) {
            $shoot->refresh();
            $shoot->syncPaymentStatusFromRecords();
        }

        if ($invoiceNeedsRefresh) {
            $this->invoiceService->refreshClientInvoicesForShoot($shoot->fresh());
        }

        $shoot->refresh();
    }

    protected function syncFeaturedHomepageImages(Shoot $shoot, array $images): void
    {
        $normalizedImages = collect($images)
            ->map(function (array $image, int $index) {
                $sort = $image['sort'] ?? $image['sort_order'] ?? ($index + 1);

                return [
                    'shoot_file_id' => (int) $image['shoot_file_id'],
                    'sort_order' => (int) $sort,
                    'alt_text' => isset($image['alt']) && trim((string) $image['alt']) !== ''
                        ? trim((string) $image['alt'])
                        : (isset($image['alt_text']) && trim((string) $image['alt_text']) !== ''
                            ? trim((string) $image['alt_text'])
                            : null),
                    'focal_point' => trim((string) ($image['focal'] ?? $image['focal_point'] ?? '50% 50%')) ?: '50% 50%',
                ];
            })
            ->unique('shoot_file_id')
            ->sortBy('sort_order')
            ->values();

        $fileIds = $normalizedImages->pluck('shoot_file_id')->all();
        $validFileIds = $shoot->files()
            ->whereIn('id', $fileIds)
            ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $shoot->featuredHomepageImages()->delete();

        $normalizedImages
            ->filter(fn (array $image) => in_array($image['shoot_file_id'], $validFileIds, true))
            ->values()
            ->each(function (array $image, int $index) use ($shoot) {
                $file = $shoot->files()->find($image['shoot_file_id']);
                $metadata = is_array($file?->metadata) ? $file->metadata : [];

                $shoot->featuredHomepageImages()->create([
                    'shoot_file_id' => $image['shoot_file_id'],
                    'sort_order' => $index + 1,
                    'alt_text' => $image['alt_text'],
                    'focal_point' => $image['focal_point'],
                    'variant_640_path' => $file?->thumbnail_path ?: $file?->web_path ?: $file?->path ?: $file?->storage_path,
                    'variant_1280_path' => $file?->web_path ?: $file?->thumbnail_path ?: $file?->path ?: $file?->storage_path,
                    'variant_1920_path' => $file?->storage_path ?: $file?->path ?: $file?->web_path ?: $file?->thumbnail_path,
                    'width' => isset($metadata['width']) && is_numeric($metadata['width']) ? (int) $metadata['width'] : null,
                    'height' => isset($metadata['height']) && is_numeric($metadata['height']) ? (int) $metadata['height'] : null,
                ]);
            });
    }

    public function targetServicesFor(Shoot $shoot, array $validated, ?User $actor = null): array
    {
        $shoot->loadMissing(['services', 'serviceItems']);

        $targetServices = array_key_exists('services', $validated)
            ? $validated['services']
            : $shoot->services->map(fn ($service) => [
                'id' => $service->id,
                'price' => $service->pivot?->price,
                'quantity' => $service->pivot?->quantity ?? 1,
                'photographer_id' => $service->pivot?->photographer_id,
                'editor_id' => $service->pivot?->editor_id,
                'scheduled_at' => $service->pivot?->scheduled_at,
                'workflow_status' => $service->pivot?->workflow_status,
                'delivery_status' => $service->pivot?->delivery_status,
                'is_deliverable' => $service->pivot?->is_deliverable,
            ])->values()->all();

        $merged = $this->support->mergeServiceItemPayload(
            $targetServices,
            $validated['service_items'] ?? null,
            $validated['service_photographers'] ?? null,
            $validated['scheduled_at'] ?? $shoot->scheduled_at,
            true
        );

        $canOverrideLinePrice = in_array(
            strtolower(trim((string) ($actor?->role ?? ''))),
            ['admin', 'superadmin', 'super_admin'],
            true
        );
        $currentItems = $shoot->serviceItems->keyBy(fn ($item) => (int) $item->service_id);
        $serviceModels = \App\Models\Service::query()
            ->whereIn('id', collect($merged)->pluck('id')->filter()->unique()->all())
            ->get()
            ->keyBy(fn ($service) => (int) $service->id);
        $currentPropertyDetails = is_array($shoot->property_details) ? $shoot->property_details : [];
        $propertyDetails = is_array($validated['property_details'] ?? null)
            ? array_merge($currentPropertyDetails, $validated['property_details'])
            : $currentPropertyDetails;
        $sqftValue = $validated['sqft']
            ?? data_get($propertyDetails, 'sqft')
            ?? data_get($propertyDetails, 'squareFeet')
            ?? data_get($propertyDetails, 'square_feet');
        $sqft = is_numeric($sqftValue) ? (int) $sqftValue : null;

        return collect($merged)->map(function (array $service) use (
            $canOverrideLinePrice,
            $currentItems,
            $serviceModels,
            $sqft
        ) {
            $serviceId = (int) ($service['id'] ?? 0);
            $currentItem = $currentItems->get($serviceId);
            $serviceModel = $serviceModels->get($serviceId);
            $submittedPrice = $service['price'] ?? null;

            if (! $canOverrideLinePrice || $submittedPrice === null) {
                $service['price'] = $currentItem?->price
                    ?? $serviceModel?->getPriceForSqft($sqft)
                    ?? 0;
            }

            if (($service['quantity'] ?? null) === null) {
                $service['quantity'] = $currentItem?->quantity ?? 1;
            }

            return $service;
        })->values()->all();
    }

    private function assertComplimentaryReshootMutationAllowed(Shoot $shoot, array $validated): void
    {
        if (! $shoot->isComplimentaryReshoot()) {
            return;
        }

        if (array_key_exists('shoot_type', $validated)
            && ($validated['shoot_type'] ?: Shoot::SHOOT_TYPE_STANDARD) !== Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT) {
            throw ValidationException::withMessages([
                'shoot_type' => [
                    'A booked complimentary reshoot cannot be reclassified. Create a separate standard shoot instead.',
                ],
            ]);
        }

        if (array_key_exists('services', $validated) || array_key_exists('service_items', $validated)) {
            throw ValidationException::withMessages([
                'services' => [
                    'Service lines on a complimentary reshoot are fixed at booking. Book separate additional work or create a new complimentary reshoot instead.',
                ],
            ]);
        }

        foreach ([
            'base_quote',
            'discount_type',
            'discount_value',
            'discount_amount',
            'tax_amount',
            'total_quote',
            'admin_adjusted_total_quote',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                throw ValidationException::withMessages([
                    $field => [
                        'Client pricing on a complimentary reshoot is fixed at zero. Book separate additional work instead.',
                    ],
                ]);
            }
        }

        if (array_key_exists('product_status', $validated)
            && ($validated['product_status'] ?: Shoot::PRODUCT_STATUS_HAS_PRODUCT) !== Shoot::PRODUCT_STATUS_ZERO_DOLLAR_PRODUCT) {
            throw ValidationException::withMessages([
                'product_status' => ['A complimentary reshoot must remain a zero-dollar product.'],
            ]);
        }
    }
}
