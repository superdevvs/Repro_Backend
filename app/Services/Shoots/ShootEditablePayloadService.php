<?php

namespace App\Services\Shoots;

use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Validation\Rule;

class ShootEditablePayloadService
{
    public function __construct(
        protected ShootMutationSupportService $support,
        protected InvoiceService $invoiceService
    ) {
    }

    public function validationRules(): array
    {
        return [
            'scheduled_date' => 'nullable|date',
            'scheduled_at' => 'nullable|date',
            'time' => 'nullable|string',
            'timezone' => 'nullable|string|timezone',
            'services' => 'nullable|array',
            'services.*.id' => 'required_with:services|integer|exists:services,id',
            'services.*.price' => 'nullable|numeric|min:0',
            'services.*.quantity' => 'nullable|integer|min:1',
            'services.*.photographer_id' => 'nullable|integer|exists:users,id',
            'services.*.editor_id' => 'nullable|integer|exists:users,id',
            'services.*.scheduled_at' => 'nullable|date',
            'services.*.is_deliverable' => 'nullable|boolean',
            'service_items' => 'nullable|array',
            'service_items.*.service_id' => 'required_with:service_items|integer|exists:services,id',
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
            'property_details' => 'nullable|array',
            'mls_image_width' => 'nullable|integer|min:1|max:10000',
            'bedrooms' => 'nullable|integer|min:0',
            'bathrooms' => 'nullable|numeric|min:0',
            'sqft' => 'nullable|integer|min:0',
            'tour_links' => 'nullable|array',
            'tour_links.*' => 'nullable',
            'listing_type' => 'nullable|string|in:for_sale,for_rent',
            'property_status' => 'nullable|string|in:available,coming_soon,pending,sold,rented',
            'is_featured' => 'nullable|boolean',
            'shoot_notes' => 'nullable|string',
            'company_notes' => 'nullable|string',
            'photographer_notes' => 'nullable|string',
            'editor_notes' => 'nullable|string',
            'notify_client' => 'nullable|boolean',
            'notify_photographer' => 'nullable|boolean',
            'service_photographers' => 'nullable|array',
            'service_photographers.*.service_id' => 'required_with:service_photographers|integer',
            'service_photographers.*.photographer_id' => 'nullable|integer|exists:users,id',
        ];
    }

    public function apply(Shoot $shoot, array $validated): void
    {
        $shoot->loadMissing('services');

        $invoiceNeedsRefresh = false;
        $paymentFieldsProvided = array_key_exists('base_quote', $validated)
            || array_key_exists('discount_type', $validated)
            || array_key_exists('discount_value', $validated)
            || array_key_exists('discount_amount', $validated)
            || array_key_exists('tax_amount', $validated)
            || array_key_exists('total_quote', $validated);
        $targetClientId = (int) ($validated['client_id'] ?? $shoot->client_id);
        $targetServices = $this->targetServicesFor($shoot, $validated);

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

        if (
            array_key_exists('services', $validated)
            || array_key_exists('service_items', $validated)
            || array_key_exists('service_photographers', $validated)
        ) {
            $this->support->attachServices($shoot, $targetServices);
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
        if (array_key_exists('base_quote', $validated)) {
            $shoot->base_quote = $validated['base_quote'];
        }
        if (array_key_exists('tax_amount', $validated)) {
            $shoot->tax_amount = $validated['tax_amount'];
        }
        if (array_key_exists('total_quote', $validated)) {
            $shoot->total_quote = $validated['total_quote'];
        }
        if (array_key_exists('discount_type', $validated)) {
            $shoot->discount_type = $validated['discount_type'];
        }
        if (array_key_exists('discount_value', $validated)) {
            $shoot->discount_value = $validated['discount_value'];
        }
        if (array_key_exists('discount_amount', $validated)) {
            $shoot->discount_amount = $validated['discount_amount'];
        }
        if ($paymentFieldsProvided) {
            $invoiceNeedsRefresh = true;
        }

        $shouldRecalculatePricing = !$paymentFieldsProvided && (
            array_key_exists('services', $validated)
            || array_key_exists('client_id', $validated)
            || array_key_exists('state', $validated)
        );

        if ($shouldRecalculatePricing) {
            $pricingCalculation = $this->support->buildPricingCalculation(
                $targetServices,
                User::find($targetClientId),
                $validated['state'] ?? $shoot->state ?? null,
                $shoot->tax_region ?: null
            );

            $shoot->base_quote = $pricingCalculation['base_quote'];
            $shoot->discount_type = $pricingCalculation['discount_type'];
            $shoot->discount_value = $pricingCalculation['discount_value'];
            $shoot->discount_amount = $pricingCalculation['discount_amount'];
            $shoot->tax_region = $pricingCalculation['tax_region'];
            $shoot->tax_percent = $pricingCalculation['tax_percent'];
            $shoot->tax_amount = $pricingCalculation['tax_amount'];
            $shoot->total_quote = $pricingCalculation['total_quote'];
            $invoiceNeedsRefresh = true;
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
        if (array_key_exists('property_status', $validated)) {
            $shoot->property_status = $validated['property_status'];
        }
        if (array_key_exists('is_featured', $validated)) {
            $shoot->is_featured = (bool) $validated['is_featured'];
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

        if (!empty($autoPropertyTourLinks)) {
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
            $shoot->tour_links = array_merge($currentTourLinks, $validated['tour_links']);
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

        $shoot->save();

        if ($invoiceNeedsRefresh) {
            try {
                $hasInvoice = Invoice::where('shoot_id', $shoot->id)->exists();
                if ($hasInvoice) {
                    $this->invoiceService->generateForShoot($shoot->fresh());
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to refresh invoice after shoot update', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function targetServicesFor(Shoot $shoot, array $validated): array
    {
        $shoot->loadMissing('services');

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

        return $this->support->mergeServiceItemPayload(
            $targetServices,
            $validated['service_items'] ?? null,
            $validated['service_photographers'] ?? null,
            $validated['scheduled_at'] ?? $shoot->scheduled_at
        );
    }
}
