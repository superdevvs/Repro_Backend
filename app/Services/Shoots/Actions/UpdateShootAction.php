<?php

namespace App\Services\Shoots\Actions;

use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class UpdateShootAction
{
    public function __construct(
        protected ShootMutationSupportService $support,
        protected InvoiceService $invoiceService,
        protected ShootActivityLogger $activityLogger,
        protected MailService $mailService,
        protected AutomationService $automationService
    ) {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        $shoot->loadMissing('services');
        $beforeSnapshot = $this->mailService->captureShootSnapshot($shoot);
        $originalServiceIds = $shoot->services->pluck('id')->sort()->values()->all();
        $originalServiceNames = $shoot->services->pluck('name')->filter()->values()->all();
        $originalAddress = $this->support->formatFullAddress($shoot);
        $originalBaseQuote = (float) $shoot->base_quote;
        $originalTotalQuote = (float) $shoot->total_quote;
        $isAdmin = in_array($user->role, ['admin', 'superadmin', 'editing_manager']);
        $isClient = $user->role === 'client';
        $isRep = $user->role === 'salesRep';
        $requestKeys = array_keys($request->all());
        $onlyPrivateListing = count($requestKeys) > 0 && count(array_diff($requestKeys, ['is_private_listing'])) === 0;
        $clientEditableKeys = [
            'is_private_listing',
            'listing_type',
            'property_status',
            'bedrooms',
            'bathrooms',
            'sqft',
            'tour_links',
        ];
        $clientEditableTourLinkKeys = [
            'property_description',
            'property_mls',
            'property_price',
            'property_lot_size',
        ];

        if (!$isAdmin) {
            $ownsShoot = $isClient && (string) $shoot->client_id === (string) $user->id;
            $assignedRep = $isRep && (string) $shoot->rep_id === (string) $user->id;

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
            } else {
                if (!$onlyPrivateListing) {
                    $this->abortJson('Forbidden', 403);
                }
            }

            if (!$ownsShoot && !$assignedRep) {
                $this->abortJson('Forbidden', 403);
            }
        }

        $validated = $request->validate([
            'status' => 'nullable|string|in:scheduled,completed,uploaded,editing,delivered,on_hold,cancelled',
            'workflow_status' => 'nullable|string|in:scheduled,completed,uploaded,editing,delivered,on_hold,cancelled',
            'scheduled_date' => 'nullable|date',
            'scheduled_at' => 'nullable|date',
            'time' => 'nullable|string',
            'services' => 'nullable|array',
            'services.*.id' => 'required_with:services|integer|exists:services,id',
            'services.*.price' => 'nullable|numeric|min:0',
            'services.*.quantity' => 'nullable|integer|min:1',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:2',
            'zip' => 'nullable|string|max:10',
            'client_id' => 'nullable|exists:users,id',
            'photographer_id' => 'nullable|exists:users,id',
            'base_quote' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'total_quote' => 'nullable|numeric|min:0',
            'property_details' => 'nullable|array',
            'bedrooms' => 'nullable|integer|min:0',
            'bathrooms' => 'nullable|numeric|min:0',
            'sqft' => 'nullable|integer|min:0',
            'is_private_listing' => 'nullable|boolean',
            'tour_links' => 'nullable|array',
            'listing_type' => 'nullable|string|in:for_sale,for_rent',
            'property_status' => 'nullable|string|in:available,sold,rented',
            'shoot_notes' => 'nullable|string',
            'company_notes' => 'nullable|string',
            'photographer_notes' => 'nullable|string',
            'editor_notes' => 'nullable|string',
            'notify_client' => 'nullable|boolean',
            'notify_photographer' => 'nullable|boolean',
            'service_photographers' => 'nullable|array',
            'service_photographers.*.service_id' => 'required_with:service_photographers|integer',
            'service_photographers.*.photographer_id' => 'required_with:service_photographers|integer|exists:users,id',
        ]);

        $previousPrivateListing = (bool) ($shoot->is_private_listing ?? false);
        $originalStatus = $shoot->status;
        $originalWorkflow = $shoot->workflow_status;
        $originalScheduledAt = $shoot->scheduled_at?->toISOString();
        $originalScheduledDate = $shoot->scheduled_date?->toDateString();
        $originalTime = $shoot->time;
        $originalPhotographerId = $shoot->photographer_id;
        $originalClientId = $shoot->client_id;
        $originalShootNotes = $shoot->shoot_notes;
        $originalCompanyNotes = $shoot->company_notes;
        $originalPhotographerNotes = $shoot->photographer_notes;
        $originalEditorNotes = $shoot->editor_notes;
        $invoiceNeedsRefresh = false;
        $paymentFieldsProvided = array_key_exists('base_quote', $validated)
            || array_key_exists('tax_amount', $validated)
            || array_key_exists('total_quote', $validated);
        $targetClientId = (int) ($validated['client_id'] ?? $shoot->client_id);
        $targetServices = array_key_exists('services', $validated)
            ? $validated['services']
            : $shoot->services->map(fn ($service) => ['id' => $service->id])->values()->all();

        $this->support->ensureClientCanBookServices($targetClientId, $targetServices);

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

        if (array_key_exists('status', $validated)) {
            $shoot->status = $validated['status'];
        }

        $markDelivered = false;
        if (array_key_exists('scheduled_at', $validated) && $validated['scheduled_at']) {
            $scheduledAt = new \DateTime($validated['scheduled_at']);
            $shoot->scheduled_at = $scheduledAt;
        }
        if (array_key_exists('scheduled_date', $validated)) {
            $shoot->scheduled_date = $validated['scheduled_date'];
        }
        if (array_key_exists('time', $validated)) {
            $shoot->time = $validated['time'];
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
        if ($newStatus && in_array($newStatus, [Shoot::STATUS_EDITING, Shoot::STATUS_UPLOADED]) && empty($shoot->editor_id)) {
            $primaryEditor = User::where('role', 'editor')->first();
            if ($primaryEditor) {
                $shoot->editor_id = $primaryEditor->id;
            }
        }

        if (array_key_exists('services', $validated) && is_array($validated['services'])) {
            $this->support->attachServices($shoot, $validated['services']);
            $invoiceNeedsRefresh = true;

            if (!$paymentFieldsProvided) {
                $taxCalculation = $this->support->buildTaxCalculation(
                    $validated['services'],
                    $shoot->state ?? null,
                    $shoot->tax_region ?: null
                );

                $shoot->base_quote = $taxCalculation['base_quote'];
                $shoot->tax_region = $taxCalculation['tax_region'];
                $shoot->tax_percent = $taxCalculation['tax_percent'];
                $shoot->tax_amount = $taxCalculation['tax_amount'];
                $shoot->total_quote = $taxCalculation['total_quote'];
            }
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
        if (array_key_exists('base_quote', $validated)) {
            $shoot->base_quote = $validated['base_quote'];
        }
        if (array_key_exists('tax_amount', $validated)) {
            $shoot->tax_amount = $validated['tax_amount'];
        }
        if (array_key_exists('total_quote', $validated)) {
            $shoot->total_quote = $validated['total_quote'];
        }
        if ($paymentFieldsProvided) {
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
        $this->support->assignServicePhotographers($shoot, $validated['service_photographers'] ?? null);

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
            if ($originalPhotographerId !== $shoot->photographer_id) {
                $changes['photographer_id'] = ['from' => $originalPhotographerId, 'to' => $shoot->photographer_id];
            }
            if ($originalClientId !== $shoot->client_id) {
                $changes['client_id'] = ['from' => $originalClientId, 'to' => $shoot->client_id];
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

        if ($invoiceNeedsRefresh) {
            try {
                $hasInvoice = Invoice::where('shoot_id', $shoot->id)->exists();
                if ($hasInvoice) {
                    $this->invoiceService->generateForShoot($shoot->fresh());
                }
            } catch (\Exception $e) {
                Log::warning('Failed to refresh invoice after shoot update', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
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
        $photographerNewlyAssigned = $originalPhotographerId !== $shoot->photographer_id && $shoot->photographer_id && !$originalPhotographerId;

        $this->registerDeferredSideEffects(
            $shoot->id,
            $changesSummary,
            $changesHtml,
            $notifyClient,
            $notifyPhotographer,
            $originalPhotographerId,
            $originalScheduledAt,
            $originalScheduledDate,
            $originalTime,
            $originalStatus,
            $originalWorkflow,
            $photographerNewlyAssigned
        );

        return $shoot->fresh(['client', 'photographer', 'service', 'services.category', 'files']);
    }

    protected function registerDeferredSideEffects(
        int $shootId,
        string $changesSummary,
        string $changesHtml,
        ?bool $notifyClient,
        ?bool $notifyPhotographer,
        ?int $originalPhotographerId,
        ?string $originalScheduledAt,
        ?string $originalScheduledDate,
        ?string $originalTime,
        ?string $originalStatus,
        ?string $originalWorkflow,
        bool $photographerNewlyAssigned
    ): void {
        $mailService = $this->mailService;
        $automationService = $this->automationService;

        app()->terminating(function () use (
            $shootId,
            $mailService,
            $automationService,
            $changesSummary,
            $changesHtml,
            $notifyClient,
            $notifyPhotographer,
            $originalPhotographerId,
            $originalScheduledAt,
            $originalScheduledDate,
            $originalTime,
            $originalStatus,
            $originalWorkflow,
            $photographerNewlyAssigned
        ) {
            $shoot = Shoot::with(['client', 'photographer', 'rep', 'service', 'services'])->find($shootId);
            if (!$shoot) {
                return;
            }

            $client = $shoot->client;

            try {
                if ($client && !$automationService->hasActiveTrigger('SHOOT_UPDATED')) {
                    $mailService->sendShootUpdatedEmail($client, $shoot, $changesSummary, $notifyClient, $notifyPhotographer);
                }
            } catch (\Exception $e) {
                Log::error('Failed to send shoot updated email', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }

            if (
                $photographerNewlyAssigned
                && $shoot->photographer
                && !$automationService->hasActiveTrigger('SHOOT_UPDATED')
                && !$automationService->hasActiveTrigger('PHOTOGRAPHER_ASSIGNED')
            ) {
                try {
                    $paymentLink = $mailService->generatePaymentLink($shoot);
                    $mailService->sendShootScheduledEmail($shoot->photographer, $shoot, $paymentLink ?? '');
                } catch (\Exception $e) {
                    Log::error('Failed to send booking email to newly assigned photographer', [
                        'shoot_id' => $shoot->id,
                        'photographer_id' => $shoot->photographer_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $systemEmailAlreadySent = false;
            if ($originalStatus !== $shoot->status || $originalWorkflow !== $shoot->workflow_status) {
                if (in_array($shoot->status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true)
                    || in_array($shoot->workflow_status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true)) {
                    try {
                        $removedRecipient = $client ?? User::find($shoot->client_id);
                        if ($removedRecipient) {
                            $mailService->sendShootRemovedEmail($removedRecipient, $shoot);
                            $systemEmailAlreadySent = true;
                        }
                    } catch (\Exception $e) {
                        Log::error('Failed to send shoot removed email', [
                            'shoot_id' => $shoot->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                if ($shoot->status === Shoot::STATUS_DELIVERED || $shoot->workflow_status === Shoot::STATUS_DELIVERED) {
                    try {
                        $deliveredRecipient = $client ?? User::find($shoot->client_id);
                        if ($deliveredRecipient) {
                            $mailService->sendShootReadyEmail($deliveredRecipient, $shoot);
                            $systemEmailAlreadySent = true;
                        }
                    } catch (\Exception $e) {
                        Log::error('Failed to send shoot ready email', [
                            'shoot_id' => $shoot->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            try {
                $context = $automationService->buildShootContext($shoot);
                if ($shoot->rep) {
                    $context['rep'] = $shoot->rep;
                }
                $context['shoot_changes'] = $changesSummary;
                $context['shoot_changes_html'] = $changesHtml;
                $context['system_email_already_sent'] = $systemEmailAlreadySent;

                $automationService->handleEvent('SHOOT_UPDATED', $context);

                if ($originalPhotographerId !== $shoot->photographer_id && $shoot->photographer_id) {
                    $context['previous_photographer_id'] = $originalPhotographerId;
                    $automationService->handleEvent('PHOTOGRAPHER_ASSIGNED', $context);
                }

                $scheduledAtChanged = $originalScheduledAt !== $shoot->scheduled_at?->toISOString()
                    || $originalScheduledDate !== $shoot->scheduled_date?->toDateString()
                    || $originalTime !== $shoot->time;
                if ($scheduledAtChanged) {
                    $context['previous_scheduled_at'] = $originalScheduledAt;
                    $context['previous_scheduled_date'] = $originalScheduledDate;
                    $context['previous_time'] = $originalTime;
                    $automationService->handleEvent('SHOOT_SCHEDULED', $context);
                }

                if ($originalStatus !== $shoot->status || $originalWorkflow !== $shoot->workflow_status) {
                    if (in_array($shoot->status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true)
                        || in_array($shoot->workflow_status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true)) {
                        $automationService->handleEvent('SHOOT_CANCELED', $context);
                    }

                    if ($shoot->status === Shoot::STATUS_DELIVERED || $shoot->workflow_status === Shoot::STATUS_DELIVERED) {
                        $automationService->handleEvent('SHOOT_COMPLETED', $context);
                    }

                    if ($shoot->status === Shoot::STATUS_UPLOADED || $shoot->workflow_status === Shoot::STATUS_UPLOADED) {
                        $automationService->handleEvent('PHOTO_UPLOADED', $context);
                        $automationService->handleEvent('MEDIA_UPLOAD_COMPLETE', $context);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Failed to trigger automation events after shoot update', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    protected function abortJson(string $message, int $status): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], $status));
    }
}
