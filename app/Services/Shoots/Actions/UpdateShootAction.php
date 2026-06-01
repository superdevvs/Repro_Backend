<?php

namespace App\Services\Shoots\Actions;

use App\Jobs\ProcessUpdatedShootSideEffectsJob;
use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootEditablePayloadService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootEditingAssignmentService;
use App\Services\Shoots\ShootMutationSupportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
        protected GoogleCalendarSyncDispatcher $googleCalendarSyncDispatcher
    ) {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        $shoot->loadMissing(['services', 'ghostUsers']);
        $beforeSnapshot = $this->mailService->captureShootSnapshot($shoot);
        $originalServiceIds = $shoot->services->pluck('id')->sort()->values()->all();
        $originalServiceNames = $shoot->services->pluck('name')->filter()->values()->all();
        $originalGhostUserIds = $shoot->ghostUsers->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
        $originalAddress = $this->support->formatFullAddress($shoot);
        $originalBaseQuote = (float) $shoot->base_quote;
        $originalTotalQuote = (float) $shoot->total_quote;
        $normalizedRole = strtolower((string) $user->role);
        $isAdmin = in_array($normalizedRole, ['admin', 'superadmin', 'editing_manager', 'salesrep', 'sales_rep'], true);
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
        ];
        $repEditableKeys = [
            'is_private_listing',
            'is_featured',
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
        $repEditableTourLinkKeys = [
            'realtor_client_id',
        ];

        if (!$isAdmin) {
            $ownsShoot = $isClient && (string) $shoot->client_id === (string) $user->id;
            $assignedRep = $isRep && (string) $shoot->rep_id === (string) $user->id;
            $assignedPhotographer = $isPhotographer
                && $this->authorizationSupport->isPhotographerAssignedToShoot($shoot, $user);
            $clientCanTogglePrivateListing = $isClient
                && $onlyPrivateListing
                && $this->authorizationSupport->canClientAccessShoot($shoot, $user);

            if ($ownsShoot) {
                $onlyClientEditableFields = count($requestKeys) > 0 && count(array_diff($requestKeys, $clientEditableKeys)) === 0;

                if (!$onlyClientEditableFields) {
                    $this->abortJson('Forbidden', 403);
                }

                $requestedTourLinks = $request->input('tour_links', []);
                if (!is_array($requestedTourLinks)) {
                    $this->abortJson('Invalid tour_links payload', 422);
                }

                $invalidTourLinkKeys = array_diff(array_keys($requestedTourLinks), $clientEditableTourLinkKeys);
                if (!empty($invalidTourLinkKeys)) {
                    $this->abortJson('Forbidden', 403);
                }
            } elseif ($assignedRep) {
                $onlyRepEditableFields = $assignedRep
                    && count($requestKeys) > 0
                    && count(array_diff($requestKeys, $repEditableKeys)) === 0;

                if (!$onlyPrivateListing && !$onlyRepEditableFields) {
                    $this->abortJson('Forbidden', 403);
                }

                $requestedTourLinks = $request->input('tour_links', []);
                if ($request->has('tour_links')) {
                    if (!is_array($requestedTourLinks)) {
                        $this->abortJson('Invalid tour_links payload', 422);
                    }

                    $invalidTourLinkKeys = array_diff(array_keys($requestedTourLinks), $repEditableTourLinkKeys);
                    if (!empty($invalidTourLinkKeys)) {
                        $this->abortJson('Forbidden', 403);
                    }
                }
            } elseif ($clientCanTogglePrivateListing) {
            } elseif ($assignedPhotographer) {
                $onlyPhotographerEditableFields = count($requestKeys) > 0
                    && count(array_diff($requestKeys, $photographerEditableKeys)) === 0;

                if (!$onlyPhotographerEditableFields) {
                    $this->abortJson('Forbidden', 403);
                }
            } else {
                if (!$onlyPrivateListing && !$onlyFeaturedFlag) {
                    $this->abortJson('Forbidden', 403);
                }
            }

            if (!$ownsShoot && !$assignedRep && !$assignedPhotographer && !$clientCanTogglePrivateListing) {
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

        if (array_key_exists('services', $validated)) {
            $targetShootType = (string) ($validated['shoot_type'] ?? $shoot->shoot_type ?? Shoot::SHOOT_TYPE_STANDARD);
            if (empty($validated['services']) && !in_array($targetShootType, Shoot::INTERNAL_NO_CHARGE_SHOOT_TYPES, true)) {
                $this->abortJson('A shoot without products must be marked complimentary, sample upload, internal test, or pricing pending.', 422);
            }
        }
        $availabilityPayload = $validated;
        $scheduledAtProvidedForAvailability = array_key_exists('scheduled_at', $validated);
        $scheduledDateProvidedForAvailability = array_key_exists('scheduled_date', $validated);
        $timeProvidedForAvailability = array_key_exists('time', $validated);

        if (!$scheduledAtProvidedForAvailability && ($scheduledDateProvidedForAvailability || $timeProvidedForAvailability)) {
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
        $skipAvailabilityCheck = $validated['skip_availability_check'] ?? $isAdmin;
        if ($needsAvailabilityCheck && !$skipAvailabilityCheck) {
            $targetPhotographerId = $validated['photographer_id'] ?? $shoot->photographer_id;
            $targetScheduledAt = isset($availabilityPayload['scheduled_at'])
                ? new \DateTime((string) $availabilityPayload['scheduled_at'])
                : ($shoot->scheduled_at ? new \DateTime($shoot->scheduled_at->format('Y-m-d H:i:s')) : null);
            $targetServices = $this->editablePayloadService->targetServicesFor($shoot, $availabilityPayload);

            if ($targetPhotographerId && $targetScheduledAt) {
                $this->support->checkPhotographerAvailability(
                    (int) $targetPhotographerId,
                    $targetScheduledAt,
                    $this->support->calculateShootDurationFromServices($targetServices),
                    $shoot->id
                );
            }

            $this->support->checkServiceItemPhotographerAvailability(
                $targetServices,
                $targetPhotographerId ? (int) $targetPhotographerId : null,
                $shoot->id
            );
        }
        $ghostUserIds = collect($validated['ghost_user_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $previousPrivateListing = (bool) ($shoot->is_private_listing ?? false);
        $previousFeaturedState = (bool) ($shoot->is_featured ?? false);
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
            if (!in_array($currentStatus, [
                'delivered', 'ready_for_client', 'admin_verified', 'ready',
                'completed', 'workflow_completed', 'client_delivered',
            ], true)) {
                $this->abortJson('Only delivered/completed shoots can be marked as Private Exclusive', 422);
            }
            $shoot->is_private_listing = (bool) $validated['is_private_listing'];
        }

        if (array_key_exists('is_listing_hidden', $validated)) {
            if (!in_array($user->role, ['admin', 'superadmin'], true)) {
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
                $shoot->scheduled_date = $scheduledAt->copy()->toDateString();
                $shoot->time = $scheduledAt->copy()->format('H:i');
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

        $this->editablePayloadService->apply($shoot, $validated);
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
            if (array_key_exists('services', $validated)) {
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

            if (!empty($changes)) {
                $this->activityLogger->log(
                    $shoot,
                    'shoot_updated',
                    [
                        'by' => $user->name,
                        'changes' => $changes,
                    ],
                    $user
                );
            }
        } catch (\Exception $e) {
            Log::warning('Failed to log shoot update activity: ' . $e->getMessage());
        }

        if (!empty($generatedTourLinkKeys)) {
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
                Log::warning('Failed to log tour link activity: ' . $e->getMessage());
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
        $photographerNewlyAssigned = $originalPhotographerId !== $shoot->photographer_id && $shoot->photographer_id && !$originalPhotographerId;

        if (!$onlyFeaturedFlag) {
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

        return $shoot->fresh(['client', 'photographer', 'service', 'services.category', 'files', 'ghostUsers']);
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

    protected function normalizeTourLinks(mixed $tourLinks): array
    {
        if (is_string($tourLinks)) {
            $tourLinks = json_decode($tourLinks, true) ?: [];
        }

        if (!is_array($tourLinks)) {
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
                    return !empty(array_filter($value, fn ($item) => is_string($item) ? trim($item) !== '' : !empty($item)));
                }

                return is_string($value) ? trim($value) !== '' : !empty($value);
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
