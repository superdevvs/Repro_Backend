<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Models\Shoot;
use App\Models\ShootFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShootResource extends JsonResource
{
    protected function normalizeCoordinate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Calculate total photographer pay from services.
     *
     * Mirrors {@see \App\Models\Shoot::getTotalPhotographerPayAttribute()} so the
     * API-serialized totalPhotographerPay matches the model accessor: when the
     * shoot_service pivot row has no override, fall back to the catalog
     * service's default photographer_pay before treating it as $0.
     */
    /**
     * Pending offline (cash/cheque) payment intents that are awaiting admin
     * confirmation. Excluded from totalPaid/balance calculations.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function serializePendingPayments(): array
    {
        $payments = $this->relationLoaded('payments')
            ? $this->payments
            : $this->payments()->get();

        return $payments
            ->filter(fn ($payment) => (string) $payment->status === \App\Models\Payment::STATUS_PENDING
                && in_array((string) $payment->payment_method, ['cash', 'check'], true))
            ->map(function ($payment) {
                $details = is_array($payment->payment_details) ? $payment->payment_details : [];

                return [
                    'id' => (int) $payment->id,
                    'amount' => (float) $payment->amount,
                    'currency' => strtoupper((string) ($payment->currency ?: 'USD')),
                    'paymentMethod' => (string) $payment->payment_method,
                    'status' => (string) $payment->status,
                    'createdAt' => optional($payment->created_at)->toIso8601String(),
                    'submittedByName' => $details['submitted_by_name'] ?? null,
                    'submittedByRole' => $details['submitted_by_role'] ?? null,
                    'checkNumber' => $details['check_number'] ?? null,
                    'paymentDate' => $details['payment_date'] ?? null,
                    'notes' => $details['notes'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    protected function calculatePendingPaymentTotal(): float
    {
        $payments = $this->relationLoaded('payments')
            ? $this->payments
            : $this->payments()->get();

        return (float) $payments
            ->filter(fn ($payment) => (string) $payment->status === \App\Models\Payment::STATUS_PENDING
                && in_array((string) $payment->payment_method, ['cash', 'check'], true))
            ->sum(fn ($payment) => (float) $payment->amount);
    }

    protected function calculatePhotographerPay(): float
    {
        // Ensure services (with category) are loaded
        if (!$this->relationLoaded('services')) {
            $this->load('services.category');
        } elseif ($this->services->isNotEmpty() && !$this->services->first()->relationLoaded('category')) {
            $this->services->load('category');
        }

        return (float) $this->services->sum(function ($service) {
            $pivotPay = $service->pivot->photographer_pay ?? null;
            $quantity = (int) ($service->pivot->quantity ?? 1);

            $pay = ($pivotPay !== null && $pivotPay !== '')
                ? (float) $pivotPay
                : (float) ($service->photographer_pay ?? 0);

            return $pay * max(1, $quantity);
        });
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Ensure services.category is loaded for per-category grouping
        if (!$this->relationLoaded('services')) {
            $this->load('services.category');
        } elseif ($this->services->isNotEmpty() && !$this->services->first()->relationLoaded('category')) {
            $this->services->load('category');
        }

        $requestingUser = $request->user();
        $isEditor = strtolower((string) ($requestingUser?->role ?? '')) === 'editor';
        $isPhotographer = strtolower((string) ($requestingUser?->role ?? '')) === 'photographer';
        $assignmentService = app(\App\Services\Shoots\ShootEditingAssignmentService::class);
        $serviceCollection = $this->services;
        $propertyDetails = is_array($this->property_details) ? $this->property_details : [];
        $listingLatitude = $this->normalizeCoordinate($propertyDetails['latitude'] ?? $propertyDetails['lat'] ?? null);
        $listingLongitude = $this->normalizeCoordinate($propertyDetails['longitude'] ?? $propertyDetails['lng'] ?? null);
        if ($isEditor && $requestingUser) {
            $serviceCollection = $assignmentService->filterServicesForEditor($this->resource, $requestingUser);
        }
        if ($isPhotographer && $requestingUser) {
            $photographerUserId = (string) $requestingUser->id;
            $isTopLevelPhotographer = (string) $this->photographer_id === $photographerUserId;
            $serviceCollection = $serviceCollection
                ->filter(function ($service) use ($photographerUserId, $isTopLevelPhotographer) {
                    $servicePhotographerId = $service->pivot?->photographer_id;

                    return $servicePhotographerId
                        ? (string) $servicePhotographerId === $photographerUserId
                        : $isTopLevelPhotographer;
                })
                ->values();
        }
        $editorAssignments = $assignmentService->buildEditorAssignmentsPayload(
            $this->resource,
            $isEditor ? $requestingUser : null
        );
        $resolvedTopLevelEditor = null;
        if ($isEditor && !empty($editorAssignments)) {
            $resolvedTopLevelEditor = $editorAssignments[0]['editor'] ?? null;
        } elseif (count($editorAssignments) === 1) {
            $resolvedTopLevelEditor = $editorAssignments[0]['editor'] ?? null;
        }
        $serviceItemSummaries = app(\App\Services\Shoots\ShootServiceItemSupport::class)->summaries($this->resource);
        if ($isEditor && $requestingUser) {
            $visibleServiceIds = $serviceCollection->pluck('id')->map(fn ($id) => (int) $id)->all();
            $serviceItemSummaries = collect($serviceItemSummaries)
                ->filter(fn ($item) => in_array((int) ($item['service_id'] ?? 0), $visibleServiceIds, true))
                ->values()
                ->all();
        }
        if ($isPhotographer && $requestingUser) {
            $visibleServiceIds = $serviceCollection->pluck('id')->map(fn ($id) => (int) $id)->all();
            $serviceItemSummaries = collect($serviceItemSummaries)
                ->filter(fn ($item) => in_array((int) ($item['service_id'] ?? 0), $visibleServiceIds, true))
                ->values()
                ->all();
        }
        $serviceItemByServiceId = collect($serviceItemSummaries)->keyBy('service_id');
        $originalServiceSubtotal = (float) $this->services->sum(function ($service) {
            $servicePrice = (float) ($service->pivot->price ?? $service->price ?? 0);
            $quantity = (int) ($service->pivot->quantity ?? 1);

            return $servicePrice * $quantity;
        });
        $isCancelled = strtolower((string) ($this->workflow_status ?? $this->status)) === strtolower(\App\Models\Shoot::STATUS_CANCELLED);
        $cancellationFee = (
            $isCancelled
            && (float) ($this->total_quote ?? 0) > 0
            && $originalServiceSubtotal > (float) ($this->total_quote ?? 0) + 0.01
        ) ? (float) $this->total_quote : 0.0;

        $tourLinks = is_array($this->tour_links) ? $this->tour_links : [];
        $realtorClient = $this->resolveRealtorClient($tourLinks);
        if ($realtorClient) {
            $tourLinks['realtor_client'] = $realtorClient;
        }

        return [
            'id' => (string) $this->id,
            'client' => [
                'id' => (string) $this->client_id,
                'name' => $this->client?->name ?? 'Unknown',
                'email' => $this->client?->email ?? '',
                'email_verified' => $this->client ? $this->client->email_verified_at !== null : false,
            ],
            'rep' => $this->when($this->rep_id, function () {
                return [
                    'id' => (string) $this->rep_id,
                    'name' => $this->rep?->name ?? 'Unknown',
                ];
            }),
            'photographer' => $this->when($this->photographer_id && !$isEditor, function () {
                return [
                    'id' => (string) $this->photographer_id,
                    'name' => $this->photographer?->name ?? 'Unassigned',
                ];
            }),
            'editor' => $resolvedTopLevelEditor,
            'location' => [
                'address' => $this->address,
                'city' => $this->city,
                'state' => $this->state,
                'zip' => $this->zip,
                'fullAddress' => "{$this->address}, {$this->city}, {$this->state} {$this->zip}",
                'latitude' => $listingLatitude,
                'longitude' => $listingLongitude,
            ],
            'latitude' => $listingLatitude,
            'longitude' => $listingLongitude,
            // Batch-load unique per-service photographer IDs to avoid N+1 queries
            'services' => (function () use ($serviceCollection, $isEditor, $assignmentService, $serviceItemByServiceId) {
                $servicePhotographerIds = $serviceCollection
                    ->pluck('pivot.photographer_id')
                    ->filter()
                    ->unique()
                    ->values();
                $servicePhotographers = $servicePhotographerIds->isNotEmpty()
                    ? \App\Models\User::whereIn('id', $servicePhotographerIds)->get()->keyBy('id')
                    : collect();
                $serviceEditorIds = $serviceCollection
                    ->pluck('pivot.editor_id')
                    ->filter()
                    ->unique()
                    ->values();
                $serviceEditors = $serviceEditorIds->isNotEmpty()
                    ? \App\Models\User::whereIn('id', $serviceEditorIds)->get()->keyBy('id')
                    : collect();

                return $serviceCollection->map(function ($service) use ($servicePhotographers, $serviceEditors, $isEditor, $assignmentService, $serviceItemByServiceId) {
                // FALLBACK RULE: service.photographer_id ?? shoot.photographer_id
                $resolvedPhotographerId = $service->pivot->photographer_id ?? $this->photographer_id;
                $sqftRanges = $service->relationLoaded('sqftRanges')
                    ? $service->getRelation('sqftRanges')
                    : $service->sqftRanges()->get();

                // Resolve photographer details
                $resolvedPhotographer = null;
                if (!$isEditor && $resolvedPhotographerId) {
                    // Try service-level photographer first (from batch-loaded collection)
                    if ($service->pivot->photographer_id) {
                        $photographer = $servicePhotographers->get($service->pivot->photographer_id);
                    } else {
                        // Fallback to shoot-level photographer
                        $photographer = $this->photographer;
                    }

                    if ($photographer) {
                        $resolvedPhotographer = [
                            'id' => (string) $photographer->id,
                            'name' => $photographer->name,
                            'avatar' => $photographer->avatar ?? null,
                        ];
                    }
                }
                $pivotEditorId = $service->pivot->editor_id ?? null;
                $resolvedEditor = null;
                if ($pivotEditorId) {
                    $editor = $serviceEditors->get($pivotEditorId);
                    if ($editor) {
                        $resolvedEditor = [
                            'id' => (string) $editor->id,
                            'name' => $editor->name,
                            'avatar' => $editor->avatar ?? null,
                            'email' => $editor->email,
                        ];
                    }
                }
                $categoryName = $service->category?->name ?? $service->category_name ?? $service->name;
                $editingCompletedAt = $service->pivot->editing_completed_at;
                $serviceItemSummary = $serviceItemByServiceId->get($service->id, []);
                
                return [
                    'id' => (string) $service->id,
                    'shoot_service_id' => isset($serviceItemSummary['shoot_service_id']) ? (string) $serviceItemSummary['shoot_service_id'] : ($service->pivot->id ? (string) $service->pivot->id : null),
                    'shootServiceId' => isset($serviceItemSummary['shootServiceId']) ? (string) $serviceItemSummary['shootServiceId'] : ($service->pivot->id ? (string) $service->pivot->id : null),
                    'name' => $service->name,
                    'price' => (float) ($service->pivot->price ?? $service->price ?? 0),
                    'quantity' => (int) ($service->pivot->quantity ?? 1),
                    'pricing_type' => $service->pricing_type,
                    'photo_count' => $service->photo_count !== null ? (int) $service->photo_count : null,
                    'sqft_ranges' => $sqftRanges->map(fn($range) => [
                        'id' => $range->id,
                        'sqft_from' => (int) $range->sqft_from,
                        'sqft_to' => (int) $range->sqft_to,
                        'duration' => $range->duration !== null ? (int) $range->duration : null,
                        'price' => (float) $range->price,
                        'photographer_pay' => $range->photographer_pay !== null ? (float) $range->photographer_pay : null,
                        'photo_count' => $range->photo_count !== null ? (int) $range->photo_count : null,
                    ])->values()->all(),
                    'photographer_pay' => $service->pivot->photographer_pay ? (float) $service->pivot->photographer_pay : null,
                    // Raw pivot value (may be null)
                    'photographer_id' => $isEditor ? null : ($service->pivot->photographer_id ? (string) $service->pivot->photographer_id : null),
                    // RESOLVED value with fallback (frontend uses this)
                    'resolved_photographer_id' => $isEditor ? null : ($resolvedPhotographerId ? (string) $resolvedPhotographerId : null),
                    // Resolved photographer details (never null if shoot has photographer)
                    'photographer' => $isEditor ? null : $resolvedPhotographer,
                    'editor_id' => $pivotEditorId ? (string) $pivotEditorId : null,
                    'editor' => $resolvedEditor,
                    'scheduled_at' => $serviceItemSummary['scheduled_at'] ?? null,
                    'scheduledAt' => $serviceItemSummary['scheduledAt'] ?? null,
                    'workflow_status' => $serviceItemSummary['workflow_status'] ?? ($service->pivot->workflow_status ?? null),
                    'workflowStatus' => $serviceItemSummary['workflowStatus'] ?? ($service->pivot->workflow_status ?? null),
                    'delivery_status' => $serviceItemSummary['delivery_status'] ?? ($service->pivot->delivery_status ?? null),
                    'deliveryStatus' => $serviceItemSummary['deliveryStatus'] ?? ($service->pivot->delivery_status ?? null),
                    'ready_at' => $serviceItemSummary['ready_at'] ?? null,
                    'readyAt' => $serviceItemSummary['readyAt'] ?? null,
                    'delivered_at' => $serviceItemSummary['delivered_at'] ?? null,
                    'deliveredAt' => $serviceItemSummary['deliveredAt'] ?? null,
                    'is_deliverable' => $serviceItemSummary['is_deliverable'] ?? true,
                    'isDeliverable' => $serviceItemSummary['isDeliverable'] ?? true,
                    'paid_amount' => $serviceItemSummary['paid_amount'] ?? 0.0,
                    'paidAmount' => $serviceItemSummary['paidAmount'] ?? 0.0,
                    'balance_due' => $serviceItemSummary['balance_due'] ?? (float) ($service->pivot->price ?? $service->price ?? 0),
                    'balanceDue' => $serviceItemSummary['balanceDue'] ?? (float) ($service->pivot->price ?? $service->price ?? 0),
                    'payment_status' => $serviceItemSummary['payment_status'] ?? 'unpaid',
                    'paymentStatus' => $serviceItemSummary['paymentStatus'] ?? 'unpaid',
                    'force_unlock_delivery' => $serviceItemSummary['force_unlock_delivery'] ?? false,
                    'forceUnlockDelivery' => $serviceItemSummary['forceUnlockDelivery'] ?? false,
                    'is_unlocked_for_delivery' => $serviceItemSummary['is_unlocked_for_delivery'] ?? false,
                    'isUnlockedForDelivery' => $serviceItemSummary['isUnlockedForDelivery'] ?? false,
                    'unlock_state' => $serviceItemSummary['unlock_state'] ?? 'locked',
                    'unlockState' => $serviceItemSummary['unlockState'] ?? 'locked',
                    'editing_completed_at' => $editingCompletedAt instanceof \DateTimeInterface
                        ? $editingCompletedAt->format(\DateTimeInterface::ATOM)
                        : ($editingCompletedAt ? (string) $editingCompletedAt : null),
                    'lane' => $assignmentService->normalizeLane($categoryName),
                    'category_key' => $assignmentService->normalizeLane($categoryName),
                    // Category info for per-category grouping
                    'category' => $service->category ? [
                        'id' => (string) $service->category->id,
                        'name' => $service->category->name,
                    ] : null,
                    'category_name' => $service->category?->name,
                ];
                });
            })(),
            // Explicitly include services_list for frontend compatibility
            'services_list' => $serviceCollection->pluck('name')->filter()->values()->all(),
            'serviceItems' => $serviceItemSummaries,
            'service_items' => $serviceItemSummaries,
            'editor_assignments' => $editorAssignments,
            'editorAssignments' => $editorAssignments,
            'scheduledAt' => $this->scheduled_at?->toIso8601String(),
            'scheduledDate' => $this->scheduled_date?->toDateString(),
            'time' => $this->time,
            // External booking sync fields (so the frontend "External Booking
            // Mapping" popup section can render preferred/alternate schedule,
            // requested photographers, payload, warnings, and mapping status).
            'alternate_scheduled_date' => $this->alternate_scheduled_date?->toDateString(),
            'alternateScheduledDate' => $this->alternate_scheduled_date?->toDateString(),
            'alternate_time' => $this->alternate_time,
            'alternateTime' => $this->alternate_time,
            'alternate_scheduled_at' => $this->alternate_scheduled_at?->toIso8601String(),
            'alternateScheduledAt' => $this->alternate_scheduled_at?->toIso8601String(),
            'requested_photographers' => is_array($this->requested_photographers) ? $this->requested_photographers : [],
            'requestedPhotographers' => is_array($this->requested_photographers) ? $this->requested_photographers : [],
            'external_booking_payload' => is_array($this->external_booking_payload) ? $this->external_booking_payload : null,
            'externalBookingPayload' => is_array($this->external_booking_payload) ? $this->external_booking_payload : null,
            'external_booking_warnings' => is_array($this->external_booking_warnings) ? $this->external_booking_warnings : [],
            'externalBookingWarnings' => is_array($this->external_booking_warnings) ? $this->external_booking_warnings : [],
            'external_booking_mapping_status' => $this->external_booking_mapping_status,
            'externalBookingMappingStatus' => $this->external_booking_mapping_status,
            'completedAt' => $this->completed_at?->toIso8601String(),
            'status' => $this->status,
            'workflowStatus' => $this->workflow_status,
            'shoot_type' => $this->shoot_type ?? Shoot::SHOOT_TYPE_STANDARD,
            'shootType' => $this->shoot_type ?? Shoot::SHOOT_TYPE_STANDARD,
            'product_status' => $this->product_status ?? Shoot::PRODUCT_STATUS_HAS_PRODUCT,
            'productStatus' => $this->product_status ?? Shoot::PRODUCT_STATUS_HAS_PRODUCT,
            'deliveryStatus' => $this->delivery_status ?? 'not_started',
            'rawPhotoCount' => (int) ($this->raw_photo_count ?? 0),
            'editedPhotoCount' => (int) ($this->edited_photo_count ?? 0),
            'raw_photo_count' => (int) ($this->raw_photo_count ?? 0),
            'edited_photo_count' => (int) ($this->edited_photo_count ?? 0),
            'canSubmitRaw' => $this->computeCanSubmitRaw($requestingUser),
            'canSubmitEdits' => $this->computeCanSubmitEdits($requestingUser),
            'canApproveEditingReview' => $this->computeCanApproveEditingReview($requestingUser),
            'can_submit_raw' => $this->computeCanSubmitRaw($requestingUser),
            'can_submit_edits' => $this->computeCanSubmitEdits($requestingUser),
            'can_approve_editing_review' => $this->computeCanApproveEditingReview($requestingUser),
            'payment' => [
                'serviceSubtotal' => $isEditor ? 0.0 : (float) (($this->base_quote ?? 0) + ($this->discount_amount ?? 0)),
                'baseQuote' => $isEditor ? 0.0 : (float) $this->base_quote,
                'discountType' => $this->discount_type,
                'discountValue' => $this->discount_value !== null ? (float) $this->discount_value : null,
                'discountAmount' => $isEditor ? 0.0 : (float) ($this->discount_amount ?? 0),
                'discountedSubtotal' => $isEditor ? 0.0 : (float) $this->base_quote,
                'taxRegion' => $this->tax_region ?? 'none',
                'taxPercent' => $isEditor ? 0.0 : (float) ($this->tax_percent ?? 0),
                'taxAmount' => $isEditor ? 0.0 : (float) $this->tax_amount,
                'totalQuote' => $isEditor ? 0.0 : (float) $this->total_quote,
                'totalPaid' => $isEditor ? 0.0 : (float) $this->total_paid,
                'remainingBalance' => $isEditor ? 0.0 : (float) $this->remaining_balance,
                'paymentStatus' => $isEditor ? null : $this->payment_status,
                'originalServiceSubtotal' => $isEditor ? 0.0 : $originalServiceSubtotal,
                'cancellationFee' => $isEditor ? 0.0 : $cancellationFee,
                'isCancellationFeeOnly' => !$isEditor && $cancellationFee > 0,
                'pendingPayments' => $isEditor ? [] : $this->serializePendingPayments(),
                'pendingTotal' => $isEditor ? 0.0 : (float) $this->calculatePendingPaymentTotal(),
            ],
            'photographerPay' => $this->calculatePhotographerPay(),
            'totalPhotographerPay' => $this->calculatePhotographerPay(),
            'photographer_pay' => $this->calculatePhotographerPay(), // Alternative key for compatibility
            'bypassPaywall' => (bool) $this->bypass_paywall,
            'createdBy' => $this->created_by_name ?? $this->created_by ?? 'Unknown',
            'createdAt' => $this->created_at->toIso8601String(),
            'cancellationRequestedAt' => $this->cancellation_requested_at?->toIso8601String(),
            'cancellationReason' => $this->cancellation_reason,
            'holdRequestedAt' => $this->hold_requested_at?->toIso8601String(),
            'holdRequestedBy' => $this->hold_requested_by,
            'holdReason' => $this->hold_reason,
            'property_details' => $this->property_details,
            'timezone' => $this->timezone,
            'mls_image_width' => $this->mls_image_width,
            'mlsImageWidth' => $this->mls_image_width,
            'tour_links' => $tourLinks,
            'realtor_client' => $realtorClient,
            'iguide_tour_url' => $this->iguide_tour_url,
            'iguide_floorplans' => $this->iguide_floorplans ?? [],
            'iguide_last_synced_at' => $this->iguide_last_synced_at?->toIso8601String(),
            'iguide_property_id' => $this->iguide_property_id,
            'iguide_work_order_id' => $this->iguide_work_order_id ?? null,
            'iguide_data' => is_array($this->iguide_data) ? $this->iguide_data : null,
            'cubicasa_order_id' => $this->cubicasa_order_id ?? null,
            'cubicasa_external_id' => $this->cubicasa_external_id ?? null,
            'cubicasa_status' => $this->cubicasa_status ?? null,
            'cubicasa_product_type' => $this->cubicasa_product_type ?? null,
            'cubicasa_tour_url' => $this->cubicasa_tour_url ?? null,
            'cubicasa_floorplans' => is_array($this->cubicasa_floorplans) ? $this->cubicasa_floorplans : [],
            'cubicasa_data' => is_array($this->cubicasa_data) ? $this->cubicasa_data : null,
            'cubicasa_last_synced_at' => $this->cubicasa_last_synced_at?->toIso8601String(),
            'cubicasa_last_status_at' => $this->cubicasa_last_status_at?->toIso8601String(),
            'cubicasa_sync_status' => $this->cubicasa_sync_status ?? null,
            'cubicasa_sync_job_id' => $this->cubicasa_sync_job_id ?? null,
            'cubicasa_sync_started_at' => $this->cubicasa_sync_started_at?->toIso8601String(),
            'cubicasa_last_sync_error' => $this->cubicasa_last_sync_error ?? null,
            'cubicasa_sync' => [
                'sync_status' => $this->cubicasa_sync_status ?? null,
                'sync_job_id' => $this->cubicasa_sync_job_id ?? null,
                'sync_started_at' => $this->cubicasa_sync_started_at?->toIso8601String(),
                'last_synced_at' => $this->cubicasa_last_synced_at?->toIso8601String(),
                'last_sync_error' => $this->cubicasa_last_sync_error ?? null,
            ],
            'is_private_listing' => (bool) ($this->is_private_listing ?? false),
            'isPrivateListing' => (bool) ($this->is_private_listing ?? false),
            'is_featured' => (bool) ($this->is_featured ?? false),
            'isFeatured' => (bool) ($this->is_featured ?? false),
            'featured_homepage_title' => $this->featured_homepage_title,
            'featuredHomepageTitle' => $this->featured_homepage_title,
            'featured_homepage_location' => $this->featured_homepage_location,
            'featuredHomepageLocation' => $this->featured_homepage_location,
            'featured_homepage_subtitle' => $this->featured_homepage_subtitle,
            'featuredHomepageSubtitle' => $this->featured_homepage_subtitle,
            'featured_homepage_cta_label' => $this->featured_homepage_cta_label,
            'featuredHomepageCtaLabel' => $this->featured_homepage_cta_label,
            'featured_homepage_cta_href' => $this->featured_homepage_cta_href,
            'featuredHomepageCtaHref' => $this->featured_homepage_cta_href,
            'featured_homepage_images' => $this->whenLoaded('featuredHomepageImages', function () {
                return $this->featuredHomepageImages->map(fn ($image) => [
                    'id' => (int) $image->id,
                    'shoot_file_id' => (int) $image->shoot_file_id,
                    'sort' => (int) $image->sort_order,
                    'alt' => $image->alt_text,
                    'focal' => $image->focal_point,
                    'width' => $image->width,
                    'height' => $image->height,
                ])->values()->all();
            }),
            'featuredHomepageImages' => $this->whenLoaded('featuredHomepageImages', function () {
                return $this->featuredHomepageImages->map(fn ($image) => [
                    'id' => (int) $image->id,
                    'shootFileId' => (int) $image->shoot_file_id,
                    'sort' => (int) $image->sort_order,
                    'alt' => $image->alt_text,
                    'focal' => $image->focal_point,
                    'width' => $image->width,
                    'height' => $image->height,
                ])->values()->all();
            }),
            'is_listing_hidden' => (bool) ($this->is_listing_hidden ?? false),
            'isListingHidden' => (bool) ($this->is_listing_hidden ?? false),
            'listing_type' => $this->listing_type,
            'listingType' => $this->listing_type,
            'property_status' => $this->property_status ?? 'available',
            'propertyStatus' => $this->property_status ?? 'available',
            'photographerPaidAt' => $this->photographer_paid_at?->toIso8601String(),
            'photographerPaidInvoiceId' => $this->photographer_paid_invoice_id,
            'salesRepPaidAt' => $this->sales_rep_paid_at?->toIso8601String(),
            'salesRepPaidInvoiceId' => $this->sales_rep_paid_invoice_id,
        ];
    }

    /**
     * Statuses from which a raw-submit is valid. Mirror FinalizeRawUploadAction.
     */
    private const SUBMIT_RAW_ALLOWED_STATUSES = [
        'scheduled',
        'booked',
        'raw_upload_pending',
    ];

    /**
     * Statuses from which an editor's edited-submit is valid. Mirror FinalizeEditedUploadAction.
     */
    private const SUBMIT_EDITED_ALLOWED_STATUSES = [
        'uploaded',
        'editing',
    ];

    /**
     * Roles that can submit edits directly to ready (skipping editing-manager review).
     */
    private const SUBMIT_EDITED_SKIP_REVIEW_ROLES = [
        'admin',
        'superadmin',
        'super_admin',
        'editing_manager',
    ];

    /**
     * Roles that can approve editor-submitted edits and promote shoot to ready.
     */
    private const APPROVE_EDITING_REVIEW_ROLES = [
        'admin',
        'superadmin',
        'super_admin',
        'editing_manager',
    ];

    protected function computeCanSubmitRaw(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));
        $allowedRoles = ['admin', 'superadmin', 'editing_manager', 'photographer'];
        if (!in_array($role, $allowedRoles, true)) {
            return false;
        }

        if ($role === 'photographer') {
            $isPrimaryPhotographer = (int) $this->photographer_id === (int) $user->id;
            $isServicePhotographer = $this->serviceItems()
                ->where('photographer_id', $user->id)
                ->exists();

            if (!$isPrimaryPhotographer && !$isServicePhotographer) {
                return false;
            }
        }

        $status = strtolower((string) ($this->workflow_status ?? $this->status ?? ''));
        $hasRawFiles = (int) ($this->raw_photo_count ?? 0) > 0
            || $this->files()->where('workflow_stage', ShootFile::STAGE_TODO)->exists();

        if (!$hasRawFiles) {
            return false;
        }

        if (in_array($status, self::SUBMIT_RAW_ALLOWED_STATUSES, true)) {
            return true;
        }

        if ($status !== 'uploaded') {
            return false;
        }

        if (!$this->photos_uploaded_at) {
            return true;
        }

        return $this->files()
            ->where('workflow_stage', ShootFile::STAGE_TODO)
            ->where('created_at', '>', $this->photos_uploaded_at)
            ->exists();
    }

    protected function computeCanSubmitEdits(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));
        $allowedRoles = ['admin', 'superadmin', 'editing_manager', 'editor'];
        if (!in_array($role, $allowedRoles, true)) {
            return false;
        }

        $status = strtolower((string) ($this->workflow_status ?? $this->status ?? ''));
        $hasEditedFiles = (int) ($this->edited_photo_count ?? 0) > 0
            || $this->files()
                ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
                ->exists();

        if (!$hasEditedFiles) {
            return false;
        }

        if (in_array($status, self::SUBMIT_EDITED_ALLOWED_STATUSES, true)) {
            return true;
        }

        // While in review, only editing-manager-style roles can resubmit (skip review).
        $canSkipReview = in_array($role, self::SUBMIT_EDITED_SKIP_REVIEW_ROLES, true);
        if ($status === 'review') {
            return $canSkipReview;
        }

        if ($status !== 'ready') {
            return false;
        }

        if (!$this->editing_completed_at) {
            return true;
        }

        return $this->files()
            ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
            ->where('created_at', '>', $this->editing_completed_at)
            ->exists();
    }

    protected function computeCanApproveEditingReview(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));
        if (!in_array($role, self::APPROVE_EDITING_REVIEW_ROLES, true)) {
            return false;
        }

        $status = strtolower((string) ($this->workflow_status ?? $this->status ?? ''));
        if ($status !== 'review') {
            return false;
        }

        $hasEditedFiles = (int) ($this->edited_photo_count ?? 0) > 0
            || $this->files()
                ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
                ->exists();

        return $hasEditedFiles;
    }

    protected function resolveRealtorClient(array $tourLinks): ?array
    {
        $realtorClientId = $tourLinks['realtor_client_id'] ?? $tourLinks['realtorClientId'] ?? null;
        if (!$realtorClientId) {
            return null;
        }

        $client = User::query()
            ->where('role', 'client')
            ->find($realtorClientId);

        if (!$client) {
            return null;
        }

        return [
            'id' => (string) $client->id,
            'name' => $client->name ?? 'Client',
            'email' => $client->email ?? null,
            'company' => $client->company_name ?? $client->company ?? null,
        ];
    }
}
