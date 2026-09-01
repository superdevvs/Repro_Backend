<?php

namespace App\Services\Shoots\Actions;

use App\Jobs\ProcessUpdatedShootSideEffectsJob;
use App\Models\Shoot;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Schedule\ScheduleDateScopeService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ComplimentaryReshootService;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootEditablePayloadService;
use App\Services\Shoots\ShootEditingAssignmentService;
use App\Services\Shoots\ShootMutationSupportService;
use App\Services\Shoots\ShootServiceChangeGuard;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
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
        protected ComplimentaryReshootService $complimentaryReshoots,
        protected AuditLogService $auditLog
    ) {}

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
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
        // Reps are NOT admins: they are assignment-scoped editors handled by the $assignedRep
        // branch below (rep_id === user->id with $repEditableKeys). Including them here would
        // let any rep bypass authorization and edit any shoot, so they must fall through.
        $isAdmin = in_array($normalizedRole, ['admin', 'superadmin', 'super_admin', 'editing_manager'], true);
        $canApproveFeaturedShoot = in_array($normalizedRole, ['admin', 'superadmin', 'super_admin'], true);
        $isClient = $user->role === 'client';
        $isRep = in_array($normalizedRole, ['salesrep', 'sales_rep'], true);
        $isPhotographer = $user->role === 'photographer';
        $requestKeys = array_keys($request->all());
        $onlyPrivateListing = count($requestKeys) > 0 && count(array_diff($requestKeys, ['is_private_listing'])) === 0;
        $onlyFeaturedFlag = count($requestKeys) > 0 && count(array_diff($requestKeys, ['is_featured'])) === 0;
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
        ];
        $photographerEditableKeys = [
            'is_featured',
        ];
        $clientEditableTourLinkKeys = [
            'property_description',
            'property_mls',
            'property_price',
            'property_lot_size',
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
        ];

        if (! $isAdmin) {
            $ownsShoot = $isClient && (string) $shoot->client_id === (string) $user->id;
            $assignedRep = $isRep && (string) $shoot->rep_id === (string) $user->id;
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
            } elseif ($assignedRep) {
                $onlyRepEditableFields = $assignedRep
                    && count($requestKeys) > 0
                    && count(array_diff($requestKeys, $repEditableKeys)) === 0;

                if (! $onlyPrivateListing && ! $onlyRepEditableFields) {
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
            } else {
                if (! $onlyPrivateListing && ! $onlyFeaturedFlag) {
                    $this->abortJson('Forbidden', 403);
                }
            }

            if (! $ownsShoot && ! $assignedRep && ! $assignedPhotographer && ! $clientCanTogglePrivateListing) {
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
            || array_key_exists('service_items', $validated);
        $hasAdjustedTotal = array_key_exists('admin_adjusted_total_quote', $validated)
            && $validated['admin_adjusted_total_quote'] !== null;
        $targetServicesForChange = $serviceChangeRequested
            ? $this->editablePayloadService->targetServicesFor($shoot, $validated, $user)
            : [];

        if ($hasAdjustedTotal
            && ! in_array($normalizedRole, ['admin', 'superadmin', 'super_admin'], true)) {
            $this->abortJson('Only Admin and Super Admin can set an adjusted total.', 403);
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

            $serviceDetachImpact = $this->serviceChangeGuard->assertChangeAllowed(
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
            'scheduled_at',
            'scheduled_date',
            'time',
            'photographer_id',
            'services',
            'service_items',
            'service_photographers',
        ];
        $needsAvailabilityCheck = count(array_intersect(array_keys($validated), $availabilityRelevantKeys)) > 0;
        // skip_availability_check (or admin) may suppress booking-CONFLICT checks only.
        // The configured-hours availability bound is always enforced, identically to the
        // create path, so a shoot can never be rescheduled outside the photographer's hours.
        $skipConflictCheck = $validated['skip_availability_check'] ?? $isAdmin;
        if ($needsAvailabilityCheck) {
            $targetPhotographerId = $validated['photographer_id'] ?? $shoot->photographer_id;
            $targetScheduledAt = isset($availabilityPayload['scheduled_at'])
                ? new \DateTime((string) $availabilityPayload['scheduled_at'])
                : ($shoot->scheduled_at ? new \DateTime($shoot->scheduled_at->format('Y-m-d H:i:s')) : null);
            $targetServices = $this->editablePayloadService->targetServicesFor($shoot, $availabilityPayload, $user);

            if ($targetPhotographerId && $targetScheduledAt) {
                $this->support->assertWithinAvailabilityBounds(
                    (int) $targetPhotographerId,
                    $targetScheduledAt,
                    $this->support->calculateShootDurationFromServices($targetServices),
                    $shoot->id,
                    $skipConflictCheck
                );
            }

            if (! $skipConflictCheck) {
                $this->support->checkServiceItemPhotographerAvailability(
                    $targetServices,
                    $targetPhotographerId ? (int) $targetPhotographerId : null,
                    $shoot->id
                );
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

        $createdComplimentaryReshoot = null;
        $complimentaryReshootReplayed = false;
        try {
            DB::transaction(function () use (
                $shoot,
                $validated,
                $user,
                $featuredFlagProvided,
                $requestedFeaturedState,
                $canApproveFeaturedShoot,
                $complimentaryServiceOptions,
                &$createdComplimentaryReshoot,
                &$complimentaryReshootReplayed
            ): void {
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
                $result = $this->complimentaryReshoots->createFromEditOptions(
                    $shoot->fresh(),
                    $complimentaryServiceOptions,
                    $user
                );
                $createdComplimentaryReshoot = $result['shoot'];
                $complimentaryReshootReplayed = (bool) $result['replayed'];

                if (! $complimentaryReshootReplayed) {
                    $this->auditLog->record(
                        'complimentary_reshoot.created',
                        $user,
                        $createdComplimentaryReshoot,
                        [
                            'entry_point' => 'edit_shoot',
                            'reshoot_of_shoot_id' => $createdComplimentaryReshoot->reshoot_of_shoot_id,
                            'root_shoot_id' => $createdComplimentaryReshoot->root_shoot_id,
                            'idempotency_key' => $createdComplimentaryReshoot->complimentary_reshoot_idempotency_key,
                        ]
                    );
                }
            });
        } catch (\DomainException $exception) {
            $this->abortJson($exception->getMessage(), 409);
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
            if ($originalScheduledDate !== $shoot->scheduled_date?->toDateString()) {
                $changes['scheduled_date'] = ['from' => $originalScheduledDate, 'to' => $shoot->scheduled_date?->toDateString()];
            }
            if ($originalTime !== $shoot->time) {
                $changes['time'] = ['from' => $originalTime, 'to' => $shoot->time];
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

        if (! $onlyFeaturedFlag) {
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

        $this->googleCalendarSyncDispatcher->dispatchShootSync($shoot->id);

        // Bust the per-date schedule buckets for both the old and the new calendar day so a
        // reschedule via update reflects in the Schedule_View immediately (Req 8.1, 8.3).
        $scheduleScope->invalidateDates([
            $previousLocalDate,
            $scheduleScope->localDateForShoot($shoot),
        ]);

        $updatedShoot = $shoot->fresh(['client', 'photographer', 'service', 'services.category', 'files', 'ghostUsers']);
        if ($createdComplimentaryReshoot) {
            // Loading the relation opts the presenter into returning the compact
            // related-reshoot summary on this response, so Edit Shoot can show
            // the result without making the admin hunt for a second record.
            $updatedShoot->load('reshootChildren');
            $createdSummary = [
                'id' => $createdComplimentaryReshoot->id,
                'shoot_type' => Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT,
                'reshoot_of_shoot_id' => $createdComplimentaryReshoot->reshoot_of_shoot_id,
                'scheduled_at' => $createdComplimentaryReshoot->scheduled_at?->toIso8601String(),
                'client_charge_total' => 0.0,
                'replayed' => $complimentaryReshootReplayed,
            ];
            $updatedShoot->setAttribute('created_complimentary_reshoot', $createdSummary);
            $updatedShoot->setAttribute('createdComplimentaryReshoot', $createdSummary);
        }

        return $updatedShoot;
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
        throw new HttpResponseException(response()->json(['message' => $message], $status));
    }
}
