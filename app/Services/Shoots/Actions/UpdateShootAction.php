<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Shoots\ShootEditablePayloadService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootMutationSupportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class UpdateShootAction
{
    public function __construct(
        protected ShootMutationSupportService $support,
        protected InvoiceService $invoiceService,
        protected ShootEditablePayloadService $editablePayloadService,
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

        $validated = $request->validate(array_merge(
            $this->editablePayloadService->validationRules(),
            [
            'status' => 'nullable|string|in:scheduled,completed,uploaded,editing,delivered,on_hold,cancelled',
            'workflow_status' => 'nullable|string|in:scheduled,completed,uploaded,editing,delivered,on_hold,cancelled',
            'is_private_listing' => 'nullable|boolean',
            'listing_type' => 'nullable|string|in:for_sale,for_rent',
            'property_status' => 'nullable|string|in:available,sold,rented',
            ]
        ));

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
                $normalizedScheduledDate = $validated['scheduled_date']
                    ?? $shoot->scheduled_date?->toDateString()
                    ?? $shoot->scheduled_date;
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
        if ($newStatus && in_array($newStatus, [Shoot::STATUS_EDITING, Shoot::STATUS_UPLOADED]) && empty($shoot->editor_id)) {
            $primaryEditor = User::where('role', 'editor')->first();
            if ($primaryEditor) {
                $shoot->editor_id = $primaryEditor->id;
            }
        }

        $this->editablePayloadService->apply($shoot, $validated);

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
        $photographerChanged = $originalPhotographerId !== null
            && $originalPhotographerId !== $shoot->photographer_id
            && $shoot->photographer_id !== null;
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
            $photographerChanged,
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
        bool $photographerChanged,
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
            $photographerChanged,
            $photographerNewlyAssigned
        ) {
            $shoot = Shoot::with(['client', 'photographer', 'rep', 'service', 'services'])->find($shootId);
            if (!$shoot) {
                return;
            }

            $client = $shoot->client;
            $previousPhotographer = $originalPhotographerId ? User::find($originalPhotographerId) : null;
            $affectedPhotographers = collect([$previousPhotographer])
                ->merge(collect($automationService->buildShootContext($shoot)['photographers'] ?? []))
                ->filter()
                ->unique('id')
                ->values();

            try {
                if ($client && !$automationService->hasActiveTrigger('SHOOT_UPDATED')) {
                    $mailService->sendShootUpdatedEmail(
                        $client,
                        $shoot,
                        $changesSummary,
                        $notifyClient,
                        $photographerChanged ? false : $notifyPhotographer
                    );
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
                && !$automationService->hasActiveTrigger('SHOOT_SCHEDULED')
                && !$automationService->hasActiveTrigger('PHOTOGRAPHER_ASSIGNED')
            ) {
                try {
                    $paymentLink = $mailService->generatePaymentLink($shoot);
                    if ($client) {
                        $mailService->sendShootScheduledEmail($client, $shoot, $paymentLink ?? '');
                    }
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
                $context['notify_client'] = $notifyClient;
                $context['notify_photographer'] = $notifyPhotographer;
                $context['photographer_changed'] = $photographerChanged;
                $context['system_email_already_sent'] = $systemEmailAlreadySent;

                $automationService->handleEvent('SHOOT_UPDATED', $context);

                if ($photographerChanged) {
                    $context['previous_photographer_id'] = $originalPhotographerId;
                    $context['previous_photographer'] = $previousPhotographer;
                    $context['new_photographer_id'] = $shoot->photographer_id;
                    $context['new_photographer'] = $shoot->photographer;
                    $context['affected_photographers'] = $affectedPhotographers->all();
                    $context['photographer_change_summary'] = $changesSummary;
                    $automationService->handleEvent('PHOTOGRAPHER_CHANGED', $context);
                } elseif ($originalPhotographerId !== $shoot->photographer_id && $shoot->photographer_id) {
                    $context['previous_photographer_id'] = $originalPhotographerId;
                    $automationService->handleEvent('PHOTOGRAPHER_ASSIGNED', $context);
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

            if (
                $photographerChanged
                && $notifyPhotographer !== false
                && !$automationService->hasActiveTrigger('PHOTOGRAPHER_CHANGED')
            ) {
                foreach ($affectedPhotographers as $photographer) {
                    try {
                        $mailService->sendPhotographerChangedEmail(
                            $photographer,
                            $shoot,
                            $previousPhotographer,
                            $changesSummary
                        );
                    } catch (\Exception $e) {
                        Log::error('Failed to send photographer changed email', [
                            'shoot_id' => $shoot->id,
                            'photographer_id' => $photographer->id ?? null,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        });
    }

    protected function abortJson(string $message, int $status): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], $status));
    }
}
