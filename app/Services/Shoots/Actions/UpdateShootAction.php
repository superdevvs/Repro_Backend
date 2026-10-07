<?php

namespace App\Services\Shoots\Actions;

use App\Jobs\CreateCubiCasaOrderJob;
use App\Jobs\ProcessCreatedShootSideEffectsJob;
use App\Jobs\ProcessUpdatedShootSideEffectsJob;
use App\Jobs\SyncShootIguideJob;
use App\Models\Shoot;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Schedule\ScheduleDateScopeService;
use App\Services\Schedule\ShootScheduleUpdateInput;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ReturnVisitBookingService;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootEditablePayloadService;
use App\Services\Shoots\ShootEditingAssignmentService;
use App\Services\Shoots\ShootMutationSupportService;
use App\Services\Shoots\ShootRealtorOptionsService;
use App\Services\Shoots\ShootServiceChangeGuard;
use Carbon\Carbon;
use App\Exceptions\PublicApiResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateShootAction
{
    public function __construct(
        protected ShootMutationSupportService $support,
        protected InvoiceService $invoiceService,
        protected ShootEditablePayloadService $editablePayloadService,
        protected ShootAuthorizationSupport $authorizationSupport,
        protected ShootEditingAssignmentService $editingAssignmentService,
        protected ShootActivityLogger $activityLogger,
        protected MailService $mailService,
        protected AutomationService $automationService,
        protected GoogleCalendarSyncDispatcher $googleCalendarSyncDispatcher,
        protected ShootServiceChangeGuard $serviceChangeGuard,
        protected ReturnVisitBookingService $returnVisits,
        protected AuditLogService $auditLog,
        protected ShootRealtorOptionsService $realtorOptions
    ) {}

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        $request->merge(\App\Support\Timezone::scheduleInput($request->only(['timezone', 'complimentary_service_options'])));
        $shoot->loadMissing(['services', 'ghostUsers']);
        $scheduleScope = app(ScheduleDateScopeService::class);
        // Capture the shoot's current local calendar day before any mutation so a reschedule
        // that moves it to a different day busts both the old and new buckets (Req 8.1, 8.3).
        $previousLocalDate = $scheduleScope->localDateForShoot($shoot);
        $beforeSnapshot = $this->mailService->captureShootSnapshot($shoot);
        $originalServiceIds = $shoot->services->pluck('id')->sort()->values()->all();
        $originalServiceNames = $shoot->services->pluck('name')->filter()->values()->all();
        $originalGhostUserIds = $shoot->ghostUsers->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
        $originalAddress = $this->support->formatFullAddress($shoot);
        $originalBaseQuote = (float) $shoot->base_quote;
        $originalTotalQuote = (float) $shoot->total_quote;
        $normalizedRole = strtolower((string) $user->role);
        // Global booking rights remain separate from legacy marketing rights.
        $isAdmin = in_array($normalizedRole, ['admin', 'superadmin', 'super_admin', 'editing_manager'], true);
        $canApproveFeaturedShoot = in_array($normalizedRole, ['admin', 'superadmin', 'super_admin'], true);
        $isClient = $user->role === 'client';
        $isRep = $this->authorizationSupport->hasRole($user, ['salesRep']);
        $management = app(\App\Services\Shoots\ShootManagementAccess::class);
        $isRep = $isRep || $management->isSalesRep($user);
        $canManageBooking = $management->can($user);
        $legacyMarketingOnly = $isRep && ! $management->canEdit($shoot, $user)
            && count(array_diff(array_keys($request->all()), ['tour_links', 'is_private_listing', 'is_featured', 'featured_homepage_title', 'featured_homepage_location', 'featured_homepage_subtitle', 'featured_homepage_cta_label', 'featured_homepage_cta_href', 'featured_homepage_images', 'ghost_user_ids'])) === 0;
        if ($legacyMarketingOnly) $canManageBooking = false;
        if ($isRep && ! $legacyMarketingOnly) {
            $request->replace($management->normalizeSalesEdit($shoot, $user, $request->all()));
        }
        $canManageRequested = $this->authorizationSupport->canManageRequestedShoot($shoot, $user);
        $canManageHold = $this->authorizationSupport->canManageHoldShoot($shoot, $user);
        $isPhotographer = $user->role === 'photographer';
        $requestKeys = array_keys($request->all());
        $onlyPrivateListing = count($requestKeys) > 0 && count(array_diff($requestKeys, ['is_private_listing'])) === 0;
        $onlyFeaturedFlag = count($requestKeys) > 0 && count(array_diff($requestKeys, ['is_featured'])) === 0;
        $onlyFeaturedMarketing = count($requestKeys) > 0 && count(array_diff($requestKeys, [
            'is_featured',
            'featured_homepage_title',
            'featured_homepage_location',
            'featured_homepage_subtitle',
            'featured_homepage_cta_label',
            'featured_homepage_cta_href',
            'featured_homepage_images',
        ])) === 0;
        $clientEditableKeys = [
            'is_private_listing',
            'timezone',
            'listing_type',
            'property_status',
            'bedrooms',
            'bathrooms',
            'sqft',
            'tour_links',
            'property_details',
        ];
        $repEditableKeys = [
            'is_private_listing',
            'is_featured',
            'featured_homepage_title',
            'featured_homepage_location',
            'featured_homepage_subtitle',
            'featured_homepage_cta_label',
            'featured_homepage_cta_href',
            'featured_homepage_images',
            'ghost_user_ids',
            'tour_links',
            'travel_location_confirmed',
            'travel_override',
            'travel_override_confirmed',
            'travel_override_confirmation_version',
            'travel_override_reason',
            // Product: assigned sales reps may reassign shoot + service-line photographers.
            'photographer_id',
            'service_photographers',
            'discount_type',
            'discount_value',
            'discount_amount',
            'notify_client',
            'notify_photographer',
        ];
        $photographerEditableKeys = [
            'is_featured',
        ];
        $isEditor = $user->role === 'editor';
        $assignedEditor = $isEditor
            && $this->authorizationSupport->canEditVideoTourLinks($shoot, $user);
        // Assigned video editors may only merge video links and embeds.
        $editorEditableKeys = [
            'tour_links',
        ];
        $editorEditableTourLinkKeys = \App\Services\Shoots\ShootAuthorizationSupport::VIDEO_TOUR_LINK_KEYS;
        $clientEditableTourLinkKeys = [
            'property_description',
            'property_mls',
            'property_price',
            'property_lot_size',
            // Which client's branding fronts the tour. Accepted here so the key
            // passes the whitelist; the value itself is checked further down
            // against the client's own linked circle, never the whole client list.
            'realtor_client_id',
        ];
        // Property-access fields a client may self-serve (text/code only, no media).
        $clientEditablePropertyDetailKeys = [
            'presenceOption',
            'lockboxCode',
            'lockboxLocation',
            'accessContactName',
            'accessContactPhone',
        ];
        $repEditableTourLinkKeys = [
            'realtor_client_id',
            'tour_style', 'tour_palette', 'header_position', 'tour_version',
            'realtor_info', 'autoplay', 'show_garage',
        ];

        if ($isRep && $canManageRequested && $request->hasAny(['status', 'workflow_status'])) {
            $this->abortJson('Use the approval or decline action to change a requested shoot status.', 403);
        }

        if ($isRep && $canManageHold && $request->hasAny(['status', 'workflow_status'])) {
            foreach (['status', 'workflow_status'] as $field) {
                $nextStatus = $request->input($field);
                if ($nextStatus === null || $nextStatus === '') {
                    continue;
                }
                if (! in_array(strtolower((string) $nextStatus), ['scheduled', 'on_hold', 'hold_on'], true)) {
                    $this->abortJson('Forbidden', 403);
                }
            }
        }

        if (! $isAdmin && ! $canManageBooking && ! $canManageRequested && ! $canManageHold) {
            $ownsShoot = $isClient && (string) $shoot->client_id === (string) $user->id;
            // Legacy shoots can inherit the client's rep without a shoot assignment.
            // This fallback authorizes only appearance edits, never other shoot writes.
            $clientRepAppearance = $isRep && ! $shoot->rep_id
                && $requestKeys === ['tour_links']
                && is_array($request->input('tour_links'))
                && count(array_diff(array_keys($request->input('tour_links')), $repEditableTourLinkKeys)) === 0
                && $this->support->getClientRep((int) $shoot->client_id) === (int) $user->id;
            $assignedRep = $isRep && ((string) $shoot->rep_id === (string) $user->id || $clientRepAppearance);
            $assignedPhotographer = $isPhotographer
                && $this->authorizationSupport->isPhotographerAssignedToShoot($shoot, $user);
            $clientCanTogglePrivateListing = $isClient
                && $onlyPrivateListing
                && $this->authorizationSupport->canClientAccessShoot($shoot, $user);

            if ($ownsShoot) {
                $onlyClientEditableFields = count($requestKeys) > 0 && count(array_diff($requestKeys, $clientEditableKeys)) === 0;

                if (! $onlyClientEditableFields) {
                    $this->abortJson('Forbidden', 403);
                }

                $requestedTourLinks = $request->input('tour_links', []);
                if (! is_array($requestedTourLinks)) {
                    $this->abortJson('Invalid tour_links payload', 422);
                }

                $invalidTourLinkKeys = array_diff(array_keys($requestedTourLinks), $clientEditableTourLinkKeys);
                if (! empty($invalidTourLinkKeys)) {
                    $this->abortJson('Forbidden', 403);
                }

                // A client chooses the tour's realtor only from their own circle:
                // themselves or an account linked to them. The picker is fed by the
                // same rule, so a valid UI choice always passes; this stops a crafted
                // request from putting an unrelated client's branding on the tour.
                if (array_key_exists('realtor_client_id', $requestedTourLinks)) {
                    $requestedRealtorId = $requestedTourLinks['realtor_client_id'];
                    $requestedRealtorId = $requestedRealtorId === null || $requestedRealtorId === ''
                        ? null
                        : (is_numeric($requestedRealtorId) ? (int) $requestedRealtorId : -1);

                    if (! $this->realtorOptions->canAssignRealtor($user, $requestedRealtorId)) {
                        $this->abortJson('You can only assign yourself or a linked account as the realtor.', 403);
                    }
                }

                // A client may only submit the whitelisted access-info fields via
                // property_details (lockbox/access contact). Reject any attempt to
                // overwrite price, MLS, description, or other property metadata.
                if ($request->has('property_details')) {
                    $requestedPropertyDetails = $request->input('property_details', []);
                    if (! is_array($requestedPropertyDetails)) {
                        $this->abortJson('Invalid property_details payload', 422);
                    }

                    $invalidPropertyDetailKeys = array_diff(
                        array_keys($requestedPropertyDetails),
                        $clientEditablePropertyDetailKeys
                    );
                    if (! empty($invalidPropertyDetailKeys)) {
                        $this->abortJson('Forbidden', 403);
                    }
                }
            } elseif ($isRep) {
                if ($request->hasAny(['scheduled_date', 'scheduled_at', 'time', 'services', 'service_items', 'service_lines', 'photographer_id', 'service_photographers'])
                    && ! $this->authorizationSupport->canEditShootAppointment($shoot, $user)) {
                    $this->abortJson('This shoot is locked for appointment changes.', 403);
                }
                // Global sales access is limited to appointment/service-plan editing.
                // Existing assignment-dependent marketing powers remain unchanged.
                if (! $assignedRep) {
                    $repEditableKeys = [];
                }
                // Assigned sales reps may move an active appointment, edit the
                // bookable service plan, and reassign photographers. Overview also
                // submits unchanged context; verify and discard it instead of
                // granting rights to change clients, line pricing, or property data.
                if ($this->authorizationSupport->canEditShootAppointment($shoot, $user)
                    && $request->hasAny([
                        'scheduled_date', 'scheduled_at', 'time', 'services', 'service_items',
                        'photographer_id', 'service_photographers', 'service_lines',
                    ])) {
                    $request->replace(app(\App\Services\Shoots\AssignedRepSchedulePayload::class)
                        ->normalize($shoot, $request->all()));
                    $requestKeys = array_keys($request->all());
                    $repEditableKeys = array_merge($repEditableKeys, [
                        'scheduled_date', 'scheduled_at', 'time', 'services', 'service_items',
                        'photographer_id', 'service_photographers',
                        'service_lines', 'expected_units_revision',
                        'notify_client', 'notify_photographer',
                        'confirm_service_detach', 'service_detach_confirmation_token',
                        'travel_location_confirmed', 'travel_override', 'travel_override_confirmed',
                        'travel_override_confirmation_version', 'travel_override_reason',
                    ]);
                }
                $onlyRepEditableFields = $isRep
                    && count($requestKeys) > 0
                    && count(array_diff($requestKeys, $repEditableKeys)) === 0;

                if (! ($assignedRep && $onlyPrivateListing) && ! $onlyRepEditableFields) {
                    $this->abortJson('Forbidden', 403);
                }

                $requestedTourLinks = $request->input('tour_links', []);
                if ($request->has('tour_links')) {
                    if (! is_array($requestedTourLinks)) {
                        $this->abortJson('Invalid tour_links payload', 422);
                    }

                    $invalidTourLinkKeys = array_diff(array_keys($requestedTourLinks), $repEditableTourLinkKeys);
                    if (! empty($invalidTourLinkKeys)) {
                        $this->abortJson('Forbidden', 403);
                    }
                }
            } elseif ($clientCanTogglePrivateListing) {
            } elseif ($assignedPhotographer) {
                $onlyPhotographerEditableFields = count($requestKeys) > 0
                    && count(array_diff($requestKeys, $photographerEditableKeys)) === 0;

                if (! $onlyPhotographerEditableFields) {
                    $this->abortJson('Forbidden', 403);
                }
            } elseif ($assignedEditor) {
                $onlyEditorEditableFields = count($requestKeys) > 0
                    && count(array_diff($requestKeys, $editorEditableKeys)) === 0;

                if (! $onlyEditorEditableFields) {
                    $this->abortJson('Forbidden', 403);
                }

                $requestedTourLinks = $request->input('tour_links', []);
                if (! is_array($requestedTourLinks)) {
                    $this->abortJson('Invalid tour_links payload', 422);
                }

                $invalidTourLinkKeys = array_diff(array_keys($requestedTourLinks), $editorEditableTourLinkKeys);
                if (! empty($invalidTourLinkKeys)) {
                    $this->abortJson('Forbidden', 403);
                }
            } else {
                if (! $onlyPrivateListing && ! $onlyFeaturedFlag) {
                    $this->abortJson('Forbidden', 403);
                }
            }

            if (! $ownsShoot && ! $isRep && ! $assignedPhotographer && ! $clientCanTogglePrivateListing && ! $assignedEditor) {
                $this->abortJson('Forbidden', 403);
            }
        }

        $validated = $request->validate(array_merge(
            $this->editablePayloadService->validationRules(),
            [
                'status' => 'nullable|string|in:scheduled,completed,uploaded,editing,delivered,on_hold,cancelled',
                'workflow_status' => 'nullable|string|in:scheduled,completed,uploaded,editing,delivered,on_hold,cancelled',
                'skip_availability_check' => 'nullable|boolean',
                'is_private_listing' => 'nullable|boolean',
                'is_listing_hidden' => 'nullable|boolean',
                'listing_type' => 'nullable|string|in:for_sale,for_rent',
                'property_status' => 'nullable|string|in:available,coming_soon,pending,sold,rented',
                'ghost_user_ids' => 'nullable|array',
                'ghost_user_ids.*' => [
                    'integer',
                    Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'client')),
                ],
                'tour_links.realtor_client_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'client')),
                ],
            ]
        ));

        // Overview submits local date/time even when only a client/address changes.
        // Resolve those fields once using the shoot's zone, then share the instant.
        $validated = app(ShootScheduleUpdateInput::class)->normalize($shoot, $validated);

        $complimentaryServiceOptions = $validated['complimentary_service_options'] ?? null;
        if (is_array($complimentaryServiceOptions)
            && ! in_array($normalizedRole, ['admin', 'superadmin', 'super_admin'], true)) {
            $this->abortJson('Only Admin and Super Admin can add complimentary services.', 403);
        }
        if (is_array($complimentaryServiceOptions)) {
            $separatelySavedFields = array_intersect(
                [
                    'ghost_user_ids',
                    'status',
                    'workflow_status',
                    'services',
                    'service_items',
                    'service_photographers',
                    'admin_adjusted_total_quote',
                ],
                array_keys($validated)
            );
            if ($separatelySavedFields !== []) {
                throw ValidationException::withMessages([
                    'complimentary_service_options' => [
                        'Save standard service, status, pricing, and shared-user changes separately before adding complimentary services.',
                    ],
                ]);
            }
        }

        $serviceChangeRequested = array_key_exists('services', $validated)
            || array_key_exists('service_items', $validated) || array_key_exists('service_lines', $validated);
        $isMultiUnit = app(\App\Services\Shoots\MultiUnitBookingService::class)->handles($shoot, $validated);
        $hasAdjustedTotal = array_key_exists('admin_adjusted_total_quote', $validated)
            && $validated['admin_adjusted_total_quote'] !== null;
        $targetServicesForChange = $serviceChangeRequested
            ? $this->editablePayloadService->targetServicesFor($shoot, $validated, $user)
            : [];

        if ($hasAdjustedTotal && ! $management->canAdjustPricing($user)) {
            $this->abortJson('You do not have permission to adjust shoot pricing.', 403);
        }

        $serviceDetachImpact = null;
        if ($serviceChangeRequested) {
            $legacyPricingFields = array_intersect(
                ['base_quote', 'tax_amount', 'total_quote'],
                array_keys($validated)
            );
            if (! empty($legacyPricingFields)) {
                $this->abortJson(
                    'Service changes are priced by the server. Use admin_adjusted_total_quote for an intentional override.',
                    422
                );
            }

            $serviceDetachImpact = $isMultiUnit ? null : $this->serviceChangeGuard->assertChangeAllowed(
                $shoot,
                $targetServicesForChange,
                $user,
                (bool) ($validated['confirm_service_detach'] ?? false),
                $validated['service_detach_confirmation_token'] ?? null,
                $hasAdjustedTotal
                    ? (float) $validated['admin_adjusted_total_quote']
                    : null,
                $validated['state'] ?? $shoot->state,
                array_key_exists('state', $validated) ? null : ($shoot->tax_region ?: null)
            );
        }
        $availabilityPayload = $validated;
        $scheduledAtProvidedForAvailability = array_key_exists('scheduled_at', $validated);
        $scheduledDateProvidedForAvailability = array_key_exists('scheduled_date', $validated);
        $timeProvidedForAvailability = array_key_exists('time', $validated);

        if (! $scheduledAtProvidedForAvailability && ($scheduledDateProvidedForAvailability || $timeProvidedForAvailability)) {
            $normalizedScheduledDate = $this->normalizeScheduledDateForDateTime(
                $validated['scheduled_date']
                ?? $shoot->scheduled_date?->toDateString()
                ?? $shoot->scheduled_date
            );
            $normalizedTime = $validated['time'] ?? $shoot->time;

            if ($normalizedScheduledDate) {
                $availabilityPayload['scheduled_at'] = Carbon::parse(
                    trim(sprintf('%s %s', $normalizedScheduledDate, $normalizedTime ?: '00:00'))
                )->format('Y-m-d H:i:s');
            }
        }

        $availabilityRelevantKeys = [
            'service_lines',
            'scheduled_at',
            'scheduled_date',
            'time',
            'photographer_id',
            'services',
            'service_items',
            'service_photographers',
        ];
        $travelGuard = app(\App\Services\Scheduling\ScheduleCommitGuard::class);
        $planBuilder = app(\App\Services\Scheduling\WriteSchedulePlan::class);
        $travelPayload = null;
        $parentChanges = false;
        // Editing a pending request does not reserve a visit. Status transitions
        // still use the same guard as every other confirmed scheduling write.
        $remainsRequest = $shoot->status === Shoot::STATUS_REQUESTED
            && $shoot->workflow_status === Shoot::STATUS_REQUESTED
            && ($validated['status'] ?? $shoot->status) === Shoot::STATUS_REQUESTED
            && ($validated['workflow_status'] ?? $shoot->workflow_status) === Shoot::STATUS_REQUESTED;
        if ($travelGuard->enabled()) {
            $availabilityRelevantKeys = array_merge($availabilityRelevantKeys, ['address', 'city', 'state', 'zip', 'timezone', 'property_details', 'status', 'workflow_status', 'travel_location_confirmed']);
        }
        $needsAvailabilityCheck = count(array_intersect(array_keys($validated), $availabilityRelevantKeys)) > 0;
        // skip_availability_check (or admin) may suppress booking-CONFLICT checks only.
        // The configured-hours availability bound is always enforced, identically to the
        // create path, so a shoot can never be rescheduled outside the photographer's hours.
        $skipConflictCheck = $isRep ? false : ($validated['skip_availability_check'] ?? ($isAdmin || $canManageRequested));
        if ($needsAvailabilityCheck) {
            $targetPhotographerId = array_key_exists('photographer_id', $validated) ? $validated['photographer_id'] : $shoot->photographer_id;
            $targetScheduledAt = array_key_exists('scheduled_at', $availabilityPayload)
                ? ($availabilityPayload['scheduled_at'] ? new \DateTime((string) $availabilityPayload['scheduled_at']) : null)
                : ($shoot->scheduled_at ? new \DateTime($shoot->scheduled_at->format('Y-m-d H:i:s')) : null);
            $targetServices = $this->editablePayloadService->targetServicesFor($shoot, $availabilityPayload, $user);

            // Availability windows are local clocks, while zoned bookings persist UTC instants.
            $scheduleTimezone = trim((string) (array_key_exists('timezone', $validated) ? $validated['timezone'] : $shoot->timezone));
            if ($scheduleTimezone !== '') {
                $zone = new \DateTimeZone($scheduleTimezone);
                $targetScheduledAt?->setTimezone($zone);
                $targetServices = array_map(function (array $service) use ($zone) {
                    if (! empty($service['scheduled_at'])) {
                        $service['scheduled_at'] = Carbon::parse($service['scheduled_at'])->setTimezone($zone)->toIso8601String();
                    }

                    return $service;
                }, $targetServices);
            }

            $assertTimezone = $scheduleTimezone !== '' ? $scheduleTimezone : null;
            if ($travelGuard->enabled()) {
                $travelPayload = $planBuilder->services($availabilityPayload, $targetServices,
                    $targetScheduledAt, $targetPhotographerId ? (int) $targetPhotographerId : null, $assertTimezone, 'update');
                $parentChanges = ! $remainsRequest && $planBuilder->changesItinerary($travelPayload, $shoot, $user);
            }
            if (! $travelGuard->enabled() || $parentChanges) {
                if ($targetPhotographerId && $targetScheduledAt && ! $isMultiUnit) {
                    $windows = app(\App\Services\Shoots\ShootDurationResolver::class)->windowsForServices(
                        $targetServices, $shoot->propertySqft(), $targetScheduledAt, $assertTimezone, (int) $targetPhotographerId
                    );
                    if ($targetServices === []) {
                        $windows[] = ['start' => \Carbon\Carbon::parse($targetScheduledAt),
                            'minutes' => app(\App\Services\Shoots\ShootDurationResolver::class)->defaultMinutes()];
                    }
                    foreach ($windows as $window) {
                        $this->support->assertWithinAvailabilityBounds(
                            (int) $targetPhotographerId, $window['start'] ?? $targetScheduledAt, $window['minutes'],
                            $shoot->id, $skipConflictCheck, $assertTimezone
                        );
                    }

                }

                if ($isMultiUnit) {
                    foreach ($targetServices as $line) {
                        if (! empty($line['photographer_required']) && ! empty($line['photographer_id']) && ! empty($line['scheduled_at'])) {
                            $this->support->assertWithinAvailabilityBounds((int) $line['photographer_id'], new \DateTime($line['scheduled_at']), (int) $line['duration_minutes'], $shoot->id, $skipConflictCheck, $assertTimezone);
                        }
                    }
                }
                $this->support->checkServiceItemPhotographerAvailability(
                    $targetServices, $targetPhotographerId ? (int) $targetPhotographerId : null,
                    $shoot->id, $assertTimezone, (bool) $skipConflictCheck, $targetScheduledAt
                );
            }
        }

        $travelPlans = [];
        $travelChildOnly = false;
        $travelPrepared = ['enabled' => false];
        if ($travelGuard->enabled() && ($needsAvailabilityCheck || is_array($complimentaryServiceOptions))) {
            $travelPayload ??= $planBuilder->services($availabilityPayload,
                $targetServices ?? $planBuilder->storedServices($shoot),
                $needsAvailabilityCheck ? $targetScheduledAt : $shoot->scheduled_at,
                $needsAvailabilityCheck ? ($targetPhotographerId ? (int) $targetPhotographerId : null) : $shoot->photographer_id,
                $assertTimezone ?? $shoot->timezone, 'update');
            $parentChanges = ! $remainsRequest && $planBuilder->changesItinerary($travelPayload, $shoot, $user);
            if ($parentChanges) {
                $travelPlans[] = ['payload' => $travelPayload, 'shoot' => $shoot->fresh()];
            }
            if (is_array($complimentaryServiceOptions) && ! Shoot::query()->where('complimentary_reshoot_idempotency_key', $complimentaryServiceOptions['idempotency_key'])->exists()) {
                $sourceForVisit = clone $shoot;
                $sourceForVisit->fill(\Illuminate\Support\Arr::only($validated, ['address', 'city', 'state', 'zip', 'timezone', 'property_details']));
                $childPayload = $planBuilder->returnVisit($sourceForVisit, $complimentaryServiceOptions);
                $childPayload = array_merge($childPayload, \Illuminate\Support\Arr::only($validated, ['travel_override', 'travel_override_confirmed', 'travel_override_confirmation_version', 'travel_override_reason', 'travel_location_confirmed']));
                $travelPlans[] = ['payload' => $childPayload, 'shoot' => null];
                $travelChildOnly = ! $parentChanges;
            }
            if ($travelPlans !== []) {
                $travelPrepared = $travelGuard->prepareBatch($travelPlans, $user);
            }
        }

        $ghostUserIds = collect($validated['ghost_user_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $previousPrivateListing = (bool) ($shoot->is_private_listing ?? false);
        $previousFeaturedState = (bool) ($shoot->is_featured ?? false);
        $previousFeaturedRequestedAt = $shoot->featured_requested_at?->toIso8601String();
        $previousListingHidden = (bool) ($shoot->is_listing_hidden ?? false);
        $originalStatus = $shoot->status;
        $originalWorkflow = $shoot->workflow_status;
        $originalScheduledAt = $shoot->scheduled_at?->toISOString();
        $originalScheduledDate = $shoot->scheduled_date?->toDateString();
        $originalTime = $shoot->time;
        $originalTimezone = $shoot->timezone;
        $originalPhotographerId = $shoot->photographer_id;
        $originalClientId = $shoot->client_id;
        $originalShootNotes = $shoot->shoot_notes;
        $originalCompanyNotes = $shoot->company_notes;
        $originalPhotographerNotes = $shoot->photographer_notes;
        $originalEditorNotes = $shoot->editor_notes;
        $originalTourLinks = $this->normalizeTourLinks($shoot->tour_links ?? []);

        if (array_key_exists('is_private_listing', $validated)) {
            $currentStatus = strtolower((string) ($shoot->workflow_status ?? $shoot->status ?? ''));
            if (! in_array($currentStatus, [
                'delivered', 'ready_for_client', 'admin_verified', 'ready',
                'completed', 'workflow_completed', 'client_delivered',
            ], true)) {
                $this->abortJson('Only delivered/completed shoots can be marked as Private Exclusive', 422);
            }
            $shoot->is_private_listing = (bool) $validated['is_private_listing'];
        }

        if (array_key_exists('is_listing_hidden', $validated)) {
            if (! in_array($user->role, ['admin', 'superadmin'], true)) {
                $this->abortJson('Only administrators can hide or unhide listings', 403);
            }
            $shoot->is_listing_hidden = (bool) $validated['is_listing_hidden'];
        }

        if (array_key_exists('status', $validated)) {
            $shoot->status = $validated['status'];
        }

        $markDelivered = false;
        $scheduledAtProvided = array_key_exists('scheduled_at', $validated);
        $scheduledDateProvided = array_key_exists('scheduled_date', $validated);
        $timeProvided = array_key_exists('time', $validated);

        if ($scheduledAtProvided) {
            if ($validated['scheduled_at']) {
                $scheduledAt = Carbon::parse($validated['scheduled_at']);
                $shoot->scheduled_at = $scheduledAt;
                $scheduleScope = app(ScheduleDateScopeService::class);
                $timezone = $validated['timezone'] ?? $shoot->timezone;
                $shoot->scheduled_date = $scheduleScope->localDateForScheduledAt($scheduledAt, $timezone)
                    ?? $scheduledAt->copy()->toDateString();
                $shoot->time = $scheduleScope->localTimeForScheduledAt($scheduledAt, $timezone)
                    ?? $scheduledAt->copy()->format('H:i');
            } else {
                $shoot->scheduled_at = null;
                $shoot->scheduled_date = null;
                $shoot->time = null;
            }
        } else {
            if ($scheduledDateProvided) {
                $shoot->scheduled_date = $validated['scheduled_date'];
            }
            if ($timeProvided) {
                $shoot->time = $validated['time'];
            }

            if ($scheduledDateProvided || $timeProvided) {
                $normalizedScheduledDate = $this->normalizeScheduledDateForDateTime(
                    $validated['scheduled_date']
                    ?? $shoot->scheduled_date?->toDateString()
                    ?? $shoot->scheduled_date
                );
                $normalizedTime = $validated['time'] ?? $shoot->time;

                if ($normalizedScheduledDate) {
                    $shoot->scheduled_at = Carbon::parse(
                        trim(sprintf('%s %s', $normalizedScheduledDate, $normalizedTime ?: '00:00'))
                    );
                }
            }
        }
        if (array_key_exists('workflow_status', $validated)) {
            $shoot->workflow_status = $validated['workflow_status'];
            if ($validated['workflow_status'] === Shoot::STATUS_DELIVERED) {
                $markDelivered = true;
            }
        }
        if (array_key_exists('status', $validated) && $validated['status'] === Shoot::STATUS_DELIVERED) {
            $markDelivered = true;
        }

        $newStatus = $validated['status'] ?? $validated['workflow_status'] ?? null;
        if (
            $newStatus
            && in_array($newStatus, [Shoot::STATUS_EDITING, Shoot::STATUS_UPLOADED], true)
            && empty($shoot->editor_id)
            && $this->editingAssignmentService->getTrackedServiceAssignments($shoot)->isEmpty()
        ) {
            $primaryEditor = User::where('role', 'editor')->first();
            if ($primaryEditor) {
                $shoot->editor_id = $primaryEditor->id;
            }
        }

        $featuredFlagProvided = array_key_exists('is_featured', $validated);
        $requestedFeaturedState = $featuredFlagProvided ? (bool) $validated['is_featured'] : null;
        if ($featuredFlagProvided) {
            unset($validated['is_featured']);
        }
        unset($validated['complimentary_service_options']);

        $createdReturnVisit = null;
        $returnVisitReplayed = false;
        $createdReturnVisitClassification = null;
        $createdReturnVisitInvoiceId = null;
        $pendingUpdateAttributes = $shoot->getDirty();
        try {
            $applyEdit = function () use (
                $shoot,
                $pendingUpdateAttributes,
                $validated,
                $user,
                $featuredFlagProvided,
                $requestedFeaturedState,
                $canApproveFeaturedShoot,
                $complimentaryServiceOptions,
                &$createdReturnVisit,
                &$returnVisitReplayed,
                &$createdReturnVisitClassification,
                &$createdReturnVisitInvoiceId
            ): void {
                // Retry attempts must publish only the committed return visit.
                $createdReturnVisit = null;
                $returnVisitReplayed = false;
                $createdReturnVisitClassification = null;
                $createdReturnVisitInvoiceId = null;
                app(\App\Services\Shoots\ShootManagementAccess::class)->assertVersion($shoot, $validated['expected_edit_version'] ?? null);
                $shoot->forceFill($pendingUpdateAttributes);
                $this->editablePayloadService->apply($shoot, $validated, $user);
                if ($featuredFlagProvided) {
                    $this->applyFeaturedRequestState(
                        $shoot,
                        (bool) $requestedFeaturedState,
                        $user,
                        $canApproveFeaturedShoot
                    );
                    $shoot->save();
                }

                if (! is_array($complimentaryServiceOptions)) {
                    return;
                }

                $isIdempotentReplay = Shoot::query()
                    ->where(
                        'complimentary_reshoot_idempotency_key',
                        $complimentaryServiceOptions['idempotency_key']
                    )
                    ->exists();
                if (! $isIdempotentReplay) {
                    $this->assertComplimentaryServiceAvailability(
                        $shoot->fresh(),
                        $complimentaryServiceOptions
                    );
                }
                $result = $this->returnVisits->createFromEditOptions(
                    $shoot->fresh(),
                    $complimentaryServiceOptions,
                    $user
                );
                $createdReturnVisit = $result['shoot'];
                $returnVisitReplayed = (bool) $result['replayed'];
                $createdReturnVisitClassification = (string) $result['classification'];
                $createdReturnVisitInvoiceId = $result['invoice_id'] ?? null;

                if (! $returnVisitReplayed) {
                    $eventType = $createdReturnVisitClassification === 'additional_work'
                        ? 'additional_work.created'
                        : 'complimentary_reshoot.created';
                    $this->auditLog->record(
                        $eventType,
                        $user,
                        $createdReturnVisit,
                        [
                            'entry_point' => 'edit_shoot',
                            'classification' => $createdReturnVisitClassification,
                            'client_pays' => (bool) ($complimentaryServiceOptions['client_pays'] ?? false),
                            'pay_photographer' => (bool) $complimentaryServiceOptions['pay_photographer'],
                            'pay_sales_rep' => (bool) $complimentaryServiceOptions['pay_sales_rep'],
                            'reason_code' => $complimentaryServiceOptions['reason_code'],
                            'reshoot_of_shoot_id' => $createdReturnVisit->reshoot_of_shoot_id,
                            'root_shoot_id' => $createdReturnVisit->root_shoot_id,
                            'idempotency_key' => $createdReturnVisit->complimentary_reshoot_idempotency_key,
                        ]
                    );
                }
            };
            $travelGuard->commit($travelPrepared, fn () => \App\Support\LockedWrite::run(fn () => DB::transaction($applyEdit), 'shoot-update'),
                function () use ($shoot, &$createdReturnVisit, $travelChildOnly) { return $travelChildOnly ? [$createdReturnVisit] : [$shoot, $createdReturnVisit]; });
        } catch (\DomainException $exception) {
                    $this->abortJson(\App\Services\ApiErrorResponder::publicMessage($exception), 409);
        }
        if (($scheduledAtProvided || array_key_exists('timezone', $validated)) && $shoot->scheduled_at) {
            $scheduleScope = app(ScheduleDateScopeService::class);
            $shoot->scheduled_date = $scheduleScope->localDateForShoot($shoot) ?? $shoot->scheduled_date;
            $shoot->time = $scheduleScope->localTimeForScheduledAt($shoot->scheduled_at, $shoot->timezone) ?? $shoot->time;
        }
        $updatedTourLinks = $this->normalizeTourLinks($shoot->tour_links ?? []);
        $tourLinksChanged = $originalTourLinks !== $updatedTourLinks;
        $generatedTourLinkKeys = $tourLinksChanged
            ? $this->extractMeaningfulTourLinkKeys($updatedTourLinks)
            : [];

        if (array_key_exists('ghost_user_ids', $validated)) {
            $shoot->ghostUsers()->sync($ghostUserIds);
            $shoot->load('ghostUsers');
        }

        try {
            $changes = [];
            if ($originalStatus !== $shoot->status) {
                $changes['status'] = ['from' => $originalStatus, 'to' => $shoot->status];
            }
            if ($originalWorkflow !== $shoot->workflow_status) {
                $changes['workflow_status'] = ['from' => $originalWorkflow, 'to' => $shoot->workflow_status];
            }
            $scheduleChanged = $originalScheduledDate !== $shoot->scheduled_date?->toDateString()
                || $originalTime !== $shoot->time;
            if ($originalScheduledDate !== $shoot->scheduled_date?->toDateString()) {
                $changes['scheduled_date'] = ['from' => $originalScheduledDate, 'to' => $shoot->scheduled_date?->toDateString()];
            }
            if ($originalTime !== $shoot->time) {
                $changes['time'] = ['from' => $originalTime, 'to' => $shoot->time];
            }
            if ($scheduleChanged) {
                // Matching pending requests are fulfilled by this move; non-matching stay pending.
                \App\Models\ShootRescheduleRequest::reconcilePendingForManualScheduleChange(
                    $shoot,
                    $user
                );
            }
            if ($originalTimezone !== $shoot->timezone) {
                $changes['timezone'] = ['from' => $originalTimezone, 'to' => $shoot->timezone];
            }
            if ($originalPhotographerId !== $shoot->photographer_id) {
                $changes['photographer_id'] = ['from' => $originalPhotographerId, 'to' => $shoot->photographer_id];
            }
            if ($originalClientId !== $shoot->client_id) {
                $changes['client_id'] = ['from' => $originalClientId, 'to' => $shoot->client_id];
            }
            if ($previousFeaturedState !== (bool) ($shoot->is_featured ?? false)) {
                $changes['is_featured'] = ['from' => $previousFeaturedState, 'to' => (bool) ($shoot->is_featured ?? false)];
            }
            if ($previousFeaturedRequestedAt !== $shoot->featured_requested_at?->toIso8601String()) {
                $changes['featured_requested_at'] = [
                    'from' => $previousFeaturedRequestedAt,
                    'to' => $shoot->featured_requested_at?->toIso8601String(),
                ];
            }
            if (array_key_exists('ghost_user_ids', $validated)) {
                $updatedGhostUserIds = $shoot->ghostUsers->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
                if ($originalGhostUserIds !== $updatedGhostUserIds) {
                    $changes['ghost_user_ids'] = ['from' => $originalGhostUserIds, 'to' => $updatedGhostUserIds];
                }
            }

            $newAddress = $this->support->formatFullAddress($shoot);
            if ($originalAddress !== $newAddress) {
                $changes['address'] = ['from' => $originalAddress, 'to' => $newAddress];
            }
            if ($serviceChangeRequested) {
                $newServiceIds = $shoot->services->pluck('id')->sort()->values()->all();
                $newServiceNames = $shoot->services->pluck('name')->filter()->values()->all();
                if ($originalServiceIds !== $newServiceIds) {
                    $changes['services'] = ['from' => $originalServiceNames, 'to' => $newServiceNames];
                }
            }
            if ((float) $shoot->base_quote !== $originalBaseQuote) {
                $changes['base_quote'] = ['from' => $originalBaseQuote, 'to' => (float) $shoot->base_quote];
            }
            if ((float) $shoot->total_quote !== $originalTotalQuote) {
                $changes['total_quote'] = ['from' => $originalTotalQuote, 'to' => (float) $shoot->total_quote];
            }
            if ($originalShootNotes !== $shoot->shoot_notes) {
                $changes['shoot_notes'] = 'updated';
            }
            if ($originalCompanyNotes !== $shoot->company_notes) {
                $changes['company_notes'] = 'updated';
            }
            if ($originalPhotographerNotes !== $shoot->photographer_notes) {
                $changes['photographer_notes'] = 'updated';
            }
            if ($originalEditorNotes !== $shoot->editor_notes) {
                $changes['editor_notes'] = 'updated';
            }
            if ($tourLinksChanged) {
                $changes['tour_links'] = 'updated';
            }

            if (! empty($changes)) {
                $this->activityLogger->log(
                    $shoot,
                    'shoot_updated',
                    [
                        'by' => $user->name,
                        'changes' => $changes,
                        'service_detach_impact' => $serviceDetachImpact,
                    ],
                    $user
                );
            }
        } catch (\Exception $e) {
            Log::warning('Failed to log shoot update activity: '.$e->getMessage());
        }

        if (! empty($generatedTourLinkKeys)) {
            try {
                $this->activityLogger->log(
                    $shoot,
                    'tour_links_generated',
                    [
                        'changed_keys' => $generatedTourLinkKeys,
                        'tour_link_count' => count($generatedTourLinkKeys),
                        'generated_by_role' => $user->role,
                        'generated_by_name' => $user->name,
                    ],
                    $user
                );
            } catch (\Exception $e) {
                Log::warning('Failed to log tour link activity: '.$e->getMessage());
            }
        }

        if ($previousPrivateListing !== (bool) ($shoot->is_private_listing ?? false)) {
            try {
                $this->activityLogger->log(
                    $shoot,
                    $shoot->is_private_listing ? 'private_listing_marked' : 'private_listing_unmarked',
                    [
                        'is_private_listing' => (bool) $shoot->is_private_listing,
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                    ],
                    $user
                );
            } catch (\Exception $e) {
            }
        }

        if ($previousFeaturedState !== (bool) ($shoot->is_featured ?? false)) {
            try {
                $this->activityLogger->log(
                    $shoot,
                    $shoot->is_featured ? 'featured_shoot_marked' : 'featured_shoot_unmarked',
                    [
                        'is_featured' => (bool) $shoot->is_featured,
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'by' => $user->name,
                    ],
                    $user
                );
            } catch (\Exception $e) {
            }
        }

        if ($previousListingHidden !== (bool) ($shoot->is_listing_hidden ?? false)) {
            try {
                $this->activityLogger->log(
                    $shoot,
                    $shoot->is_listing_hidden ? 'listing_hidden' : 'listing_unhidden',
                    [
                        'is_listing_hidden' => (bool) $shoot->is_listing_hidden,
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                    ],
                    $user
                );
            } catch (\Exception $e) {
            }
        }

        if ($markDelivered) {
            if (empty($shoot->admin_verified_at)) {
                $shoot->admin_verified_at = now();
                $shoot->save();
            }
            if ($shoot->workflow_status !== Shoot::STATUS_DELIVERED) {
                $shoot->workflow_status = Shoot::STATUS_DELIVERED;
                $shoot->save();
            }
        }

        $shoot->loadMissing(['client', 'rep', 'photographer', 'service', 'services']);
        $shootChangeSummary = $this->mailService->buildShootChangeSummary($beforeSnapshot, $shoot);
        $changesSummary = $shootChangeSummary['summary'];
        $changesHtml = $shootChangeSummary['html'];
        $notifyClient = array_key_exists('notify_client', $validated) ? (bool) $validated['notify_client'] : null;
        $notifyPhotographer = array_key_exists('notify_photographer', $validated) ? (bool) $validated['notify_photographer'] : null;
        $photographerChanged = $originalPhotographerId !== null
            && $originalPhotographerId !== $shoot->photographer_id
            && $shoot->photographer_id !== null;
        $photographerNewlyAssigned = $originalPhotographerId !== $shoot->photographer_id && $shoot->photographer_id && ! $originalPhotographerId;

        if (! $onlyFeaturedMarketing) {
            $this->registerDeferredSideEffects(
                $shoot->id,
                $changesSummary,
                $changesHtml,
                $notifyClient,
                $notifyPhotographer,
                $originalPhotographerId,
                $originalStatus,
                $originalWorkflow,
                $photographerChanged,
                $photographerNewlyAssigned
            );
        }

        if ($createdReturnVisitClassification === 'additional_work'
            && $createdReturnVisit
            && ! $returnVisitReplayed) {
            $this->dispatchCreatedAdditionalWorkSideEffects($createdReturnVisit);
        }

        if (! $onlyFeaturedMarketing) {
            $this->googleCalendarSyncDispatcher->dispatchShootSync($shoot->id);
        }
        if ($createdReturnVisit && ! $returnVisitReplayed) {
            $this->googleCalendarSyncDispatcher->dispatchShootSync($createdReturnVisit->id);
        }

        // Bust the per-date schedule buckets for both the old and the new calendar day so a
        // reschedule via update reflects in the Schedule_View immediately (Req 8.1, 8.3).
        $scheduleScope->invalidateDates([
            // Dashboard overview caches future appointments in today's bucket.
            now()->toDateString(),
            $previousLocalDate,
            $scheduleScope->localDateForShoot($shoot),
            $createdReturnVisit ? $scheduleScope->localDateForShoot($createdReturnVisit) : null,
        ]);

        $updatedShoot = $shoot->fresh(['client', 'photographer', 'service', 'services.category', 'files', 'ghostUsers']);
        if ($createdReturnVisit) {
            // Loading the relation opts the presenter into returning the compact
            // related-reshoot summary on this response, so Edit Shoot can show
            // the result without making the admin hunt for a second record.
            $updatedShoot->load('reshootChildren');
            $createdSummary = [
                'id' => $createdReturnVisit->id,
                'shoot_type' => $createdReturnVisit->shoot_type,
                'reshoot_classification' => $createdReturnVisitClassification,
                'reshoot_of_shoot_id' => $createdReturnVisit->reshoot_of_shoot_id,
                'root_shoot_id' => $createdReturnVisit->root_shoot_id,
                'scheduled_at' => $createdReturnVisit->scheduled_at?->toIso8601String(),
                'client_charge_total' => round((float) $createdReturnVisit->total_quote, 2),
                'payment_required' => $createdReturnVisitClassification === 'additional_work',
                'invoice_id' => $createdReturnVisitInvoiceId,
                'replayed' => $returnVisitReplayed,
            ];
            $updatedShoot->setAttribute('created_return_visit', $createdSummary);
            $updatedShoot->setAttribute('createdReturnVisit', $createdSummary);
            if ($createdReturnVisitClassification === 'complimentary_reshoot') {
                $updatedShoot->setAttribute('created_complimentary_reshoot', $createdSummary);
                $updatedShoot->setAttribute('createdComplimentaryReshoot', $createdSummary);
            } else {
                $updatedShoot->setAttribute('created_additional_work', $createdSummary);
                $updatedShoot->setAttribute('createdAdditionalWork', $createdSummary);
            }
        }

        return $updatedShoot;
    }

    private function dispatchCreatedAdditionalWorkSideEffects(Shoot $shoot): void
    {
        ProcessCreatedShootSideEffectsJob::dispatch(
            $shoot->id,
            false,
            $shoot->scheduled_at !== null
        )->afterCommit();

        if ($shoot->scheduled_at !== null && $shoot->hasCubiCasaAutoOrderService()) {
            try {
                $pending = CreateCubiCasaOrderJob::dispatch($shoot->id, 'booking')->afterCommit();
                unset($pending);
            } catch (\Throwable $exception) {
                Log::warning('CubiCasa auto-create failed during additional-work booking; booking completed regardless.', [
                    'shoot_id' => $shoot->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($shoot->hasIguideEligibleService()) {
            try {
                $pending = SyncShootIguideJob::dispatch($shoot->id)->afterCommit();
                unset($pending);
            } catch (\Throwable $exception) {
                Log::warning('iGUIDE discovery dispatch failed during additional-work booking; booking completed regardless.', [
                    'shoot_id' => $shoot->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    protected function registerDeferredSideEffects(
        int $shootId,
        string $changesSummary,
        string $changesHtml,
        ?bool $notifyClient,
        ?bool $notifyPhotographer,
        ?int $originalPhotographerId,
        ?string $originalStatus,
        ?string $originalWorkflow,
        bool $photographerChanged,
        bool $photographerNewlyAssigned
    ): void {
        ProcessUpdatedShootSideEffectsJob::dispatch(
            $shootId,
            $changesSummary,
            $changesHtml,
            $notifyClient,
            $notifyPhotographer,
            $originalPhotographerId,
            $originalStatus,
            $originalWorkflow,
            $photographerChanged,
            $photographerNewlyAssigned
        )->afterCommit();
    }

    /**
     * Validate the return visit exactly like a normal admin booking: configured
     * working-hour bounds and existing booking conflicts are both enforced for
     * every service-level photographer/schedule override. This runs inside the
     * outer Edit Shoot transaction so a failure also rolls back ordinary edits.
     */
    protected function assertComplimentaryServiceAvailability(Shoot $sourceShoot, array $options): void
    {
        $timezone = $options['timezone'] ?? $sourceShoot->timezone ?? config('app.timezone');
        $defaultScheduledAt = $options['scheduled_at'] ?? null;
        if (! $defaultScheduledAt
            && ! empty($options['scheduled_date'])
            && ! empty($options['time'])) {
            $defaultScheduledAt = Carbon::parse(
                $options['scheduled_date'].' '.$options['time'],
                $timezone
            )->format('Y-m-d H:i:s');
        }
        $defaultPhotographerId = $options['photographer_id'] ?? $sourceShoot->photographer_id;

        foreach ($options['service_items'] as $index => $item) {
            $scheduledAt = $item['scheduled_at'] ?? $defaultScheduledAt;
            $photographerId = $item['photographer_id'] ?? $defaultPhotographerId;
            if (! $scheduledAt || ! $photographerId) {
                continue;
            }

            // scheduled_at is persisted as the local wall-clock value across
            // the existing booking paths. Keep the same convention here so
            // conflict comparisons do not shift one side by the IANA offset.
            $scheduled = Carbon::parse((string) $scheduledAt);
            // Match CreateShootAction's lock-before-check ordering so concurrent
            // bookings cannot both pass against the same photographer/day.
            DB::table('shoots')
                ->where('photographer_id', (int) $photographerId)
                ->whereDate('scheduled_at', $scheduled->toDateString())
                ->lockForUpdate()
                ->get();

            $durationMinutes = $this->support->calculateShootDurationFromServices([[
                'id' => (int) $item['service_id'],
            ]]);

            try {
                $this->support->assertWithinAvailabilityBounds(
                    (int) $photographerId,
                    $scheduled->toDateTime(),
                    $durationMinutes
                );
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first()
                    ?: 'Photographer is not available at the selected time.';

                throw ValidationException::withMessages([
                    "complimentary_service_options.service_items.{$index}.scheduled_at" => [$message],
                ]);
            }
        }
    }

    protected function applyFeaturedRequestState(Shoot $shoot, bool $requestedFeaturedState, User $user, bool $canApproveFeaturedShoot): void
    {
        if ($canApproveFeaturedShoot) {
            $shoot->is_featured = $requestedFeaturedState;

            if ($requestedFeaturedState) {
                $shoot->featured_approved_at = now();
                $shoot->featured_approved_by = $user->id;
                $shoot->featured_requested_at = $shoot->featured_requested_at ?? now();
                $shoot->featured_requested_by = $shoot->featured_requested_by ?? $user->id;
            } else {
                $shoot->featured_requested_at = null;
                $shoot->featured_requested_by = null;
                $shoot->featured_approved_at = null;
                $shoot->featured_approved_by = null;
            }

            return;
        }

        if ((bool) ($shoot->is_featured ?? false)) {
            return;
        }

        if ($requestedFeaturedState) {
            $shoot->is_featured = false;
            $shoot->featured_requested_at = now();
            $shoot->featured_requested_by = $user->id;
            $shoot->featured_approved_at = null;
            $shoot->featured_approved_by = null;

            return;
        }

        $shoot->is_featured = false;
        $shoot->featured_requested_at = null;
        $shoot->featured_requested_by = null;
        $shoot->featured_approved_at = null;
        $shoot->featured_approved_by = null;
    }

    protected function normalizeTourLinks(mixed $tourLinks): array
    {
        if (is_string($tourLinks)) {
            $tourLinks = json_decode($tourLinks, true) ?: [];
        }

        if (! is_array($tourLinks)) {
            return [];
        }

        return $this->sortArrayRecursively($tourLinks);
    }

    protected function sortArrayRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortArrayRecursively($item);
            }
        }

        ksort($value);

        return $value;
    }

    protected function extractMeaningfulTourLinkKeys(array $tourLinks): array
    {
        $ignoredKeys = [
            'property_description',
            'property_mls',
            'property_price',
            'property_lot_size',
            'realtor_client',
            'realtor_client_id',
            'realtorClient',
            'realtorClientId',
        ];

        return collect($tourLinks)
            ->filter(function ($value, $key) use ($ignoredKeys) {
                if (in_array((string) $key, $ignoredKeys, true)) {
                    return false;
                }

                if (is_array($value)) {
                    return ! empty(array_filter($value, fn ($item) => is_string($item) ? trim($item) !== '' : ! empty($item)));
                }

                return is_string($value) ? trim($value) !== '' : ! empty($value);
            })
            ->keys()
            ->values()
            ->all();
    }

    protected function normalizeScheduledDateForDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    protected function abortJson(string $message, int $status): never
    {
        throw new PublicApiResponseException(response()->json(['message' => $message], $status));
    }
}
