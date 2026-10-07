<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\ClientConfirmationRecoveryService;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\ShootEditablePayloadService;
use App\Services\Shoots\ShootMutationSupportService;
use App\Services\ShootWorkflowService;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApproveShootAction
{
    private const NON_MODIFYING_REQUEST_APPROVAL_FIELDS = [
        'scheduled_at',
        'photographer_id',
        'notes',
        'skip_availability_check',
        'travel_location_confirmed',
        'travel_override',
        'travel_override_confirmed',
        'travel_override_confirmation_version',
        'travel_override_reason',
        'notify_client',
        'notify_photographer',
        'service_photographers',
    ];

    public function __construct(
        protected ShootMutationSupportService $support,
        protected ShootEditablePayloadService $editablePayloadService,
        protected ShootWorkflowService $workflowService,
        protected ShootMediaStorageService $mediaStorageService,
        protected InvoiceService $invoiceService,
        protected AutomationService $automationService,
        protected ClientConfirmationRecoveryService $clientConfirmationRecoveryService,
        protected MailService $mailService,
        protected GoogleCalendarSyncDispatcher $googleCalendarSyncDispatcher
    ) {}

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        $management = app(\App\Services\Shoots\ShootManagementAccess::class);
        if ($management->isSalesRep($user)) {
            $request->replace($management->normalizeSalesEdit($shoot, $user, $request->all()));
        }
        $request->merge(\App\Support\Timezone::scheduleInput($request->only(['timezone', 'complimentary_service_options'])));
        $shoot->loadMissing('services');
        $beforeSnapshot = $this->mailService->captureShootSnapshot($shoot);
        $wasRequested = $shoot->status === Shoot::STATUS_REQUESTED || $shoot->workflow_status === Shoot::STATUS_REQUESTED;

        $validated = $request->validate(array_merge(
            $this->editablePayloadService->validationRules(),
            [
                'notes' => 'nullable|string|max:2000',
                'skip_availability_check' => 'nullable|boolean',
            ]
        ));

        // Resolve zoned input before the service merger reduces it to SQL clocks.
        // Approval owns its inheritance rules; keep only supplied fields so this
        // does not move independent visits or classify approval as a modification.
        $validated = array_intersect_key(
            app(\App\Services\Schedule\ShootScheduleUpdateInput::class)->normalizeTimestamps($shoot, $validated),
            $validated
        );

        $scheduledAt = isset($validated['scheduled_at'])
            ? new \DateTime($validated['scheduled_at'])
            : (
                $shoot->scheduled_at instanceof \DateTimeInterface
                    ? new \DateTime($shoot->scheduled_at->format('Y-m-d H:i:s'))
                    : ($shoot->scheduled_at ? new \DateTime((string) $shoot->scheduled_at) : new \DateTime)
            );

        $skipAvailabilityCheck = $management->isSalesRep($user) ? false : ($validated['skip_availability_check'] ?? in_array($user->role, ['admin', 'superadmin']));
        $targetPhotographerId = $validated['photographer_id'] ?? $shoot->photographer_id;
        $targetServices = $this->editablePayloadService->targetServicesFor($shoot, $validated, $user);
        $isMultiUnit = $shoot->units()->exists();
        $inheritedScheduleItemIds = [];
        $previousSchedule = $this->support->normalizeDateTimeForDatabase($shoot->scheduled_at);
        $approvedSchedule = $this->support->normalizeDateTimeForDatabase($scheduledAt);
        if (! $isMultiUnit && $approvedSchedule !== $previousSchedule) {
            $currentItems = $shoot->serviceItems->keyBy('service_id');
            // Modify/Approve payloads often re-echo each line's prior clock. Only a
            // clock that differs from storage counts as an intentional independent visit.
            $explicitScheduleServiceIds = collect(array_merge($validated['services'] ?? [], $validated['service_items'] ?? []))
                ->filter(function (array $service) use ($currentItems) {
                    if (! array_key_exists('scheduled_at', $service) || $service['scheduled_at'] === null || $service['scheduled_at'] === '') {
                        return false;
                    }
                    $incoming = $this->support->normalizeDateTimeForDatabase($service['scheduled_at']);
                    $stored = $this->support->normalizeDateTimeForDatabase(
                        $currentItems->get((int) ($service['service_id'] ?? $service['id']))?->scheduled_at
                    );

                    return $incoming !== null && $incoming !== $stored;
                })
                ->map(fn (array $service) => (int) ($service['service_id'] ?? $service['id']))
                ->all();
            $inheritedScheduleItemIds = $shoot->serviceItems
                ->filter(function ($item) use ($previousSchedule, $explicitScheduleServiceIds) {
                    $itemSchedule = $this->support->normalizeDateTimeForDatabase($item->scheduled_at);

                    return ! in_array((int) $item->service_id, $explicitScheduleServiceIds, true)
                        && $item->workflow_status !== 'cancelled'
                        && ($itemSchedule === null || $itemSchedule === $previousSchedule);
                })->pluck('id')->all();
            foreach ($targetServices as &$service) {
                $serviceSchedule = $this->support->normalizeDateTimeForDatabase(
                    array_key_exists('scheduled_at', $service)
                        ? $service['scheduled_at']
                        : $currentItems->get((int) $service['id'])?->scheduled_at
                );
                // Only omitted service schedules inherit the approved order time.
                // Explicit schedules and independently timed visits stay intact.
                if (! in_array((int) $service['id'], $explicitScheduleServiceIds, true)
                    && ($service['workflow_status'] ?? null) !== 'cancelled'
                    && ($serviceSchedule === null || $serviceSchedule === $previousSchedule)) {
                    $service['scheduled_at'] = $approvedSchedule;
                }
            }
            unset($service);
        }
        if ($isMultiUnit) {
            foreach ($targetServices as $index => $line) {
                if (($line['photographer_required'] ?? false) && (empty($line['photographer_id']) || empty($line['scheduled_at']))) {
                    throw \Illuminate\Validation\ValidationException::withMessages(["service_lines.$index" => ['Schedule and assign a photographer to every unit capture line before approval.']]);
                }
            }
        }
        // Validate the same booking anchor that persistence will derive. An
        // echoed or omitted shoot clock cannot override scheduled service rows.
        $scheduledAt = app(\App\Services\Schedule\ShootScheduleFromServices::class)
            ->earliestInstant($targetServices) ?? $scheduledAt;
        $timezone = $validated['timezone'] ?? $shoot->timezone;
        $availabilityServices = $targetServices;
        if ($timezone) {
            foreach ($availabilityServices as &$service) {
                if (! empty($service['scheduled_at'])) {
                    $service['scheduled_at'] = \Carbon\Carbon::parse($service['scheduled_at'], 'UTC')
                        ->setTimezone($timezone)->toIso8601String();
                }
            }
            unset($service);
        }
        if ($targetServices === [] && $targetPhotographerId) {
            $this->support->assertWithinAvailabilityBounds(
                (int) $targetPhotographerId, $scheduledAt,
                app(\App\Services\Shoots\ShootDurationResolver::class)->defaultMinutes(),
                $shoot->id, $skipAvailabilityCheck, $timezone
            );
        }
        $this->support->checkServiceItemPhotographerAvailability(
            $availabilityServices,
            $targetPhotographerId ? (int) $targetPhotographerId : null,
            $shoot->id, $timezone, $skipAvailabilityCheck, $scheduledAt
        );

        $travelGuard = app(\App\Services\Scheduling\ScheduleCommitGuard::class);
        $travelPrepared = $travelGuard->prepare(app(\App\Services\Scheduling\WriteSchedulePlan::class)->services(
            $validated, $availabilityServices, $scheduledAt, $targetPhotographerId ? (int) $targetPhotographerId : null,
            $timezone, 'approve'
        ), $shoot, $user);
        $writeAttempts = DB::transactionLevel() > 0 ? 1 : LockedWrite::DEFAULT_ATTEMPTS;
        $travelGuard->commit($travelPrepared, fn () => LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $scheduledAt, $user, $validated, $inheritedScheduleItemIds, $previousSchedule, $approvedSchedule) {
            app(\App\Services\Shoots\ShootManagementAccess::class)->assertVersion($shoot, $validated['expected_edit_version'] ?? null);
            // A failed SQLite attempt may leave the in-memory workflow state
            // changed even though its transaction rolled back.
            $shoot->refresh();
            $this->editablePayloadService->apply($shoot, $validated, $user);

            if ($inheritedScheduleItemIds !== []) {
                $shoot->serviceItems()->whereIn('id', $inheritedScheduleItemIds)
                    ->where(function ($query) use ($previousSchedule) {
                        $query->whereNull('scheduled_at');
                        if ($previousSchedule !== null) {
                            $query->orWhere('scheduled_at', $previousSchedule);
                        }
                    })->update([
                        'scheduled_at' => $approvedSchedule,
                        'updated_at' => now(),
                    ]);
                $shoot->unsetRelation('services')->unsetRelation('serviceItems');
            }

            $derivedSchedule = app(\App\Services\Schedule\ShootScheduleFromServices::class)
                ->earliestInstant($shoot->serviceItems()->get()) ?? $scheduledAt;
            $this->workflowService->approve($shoot, $derivedSchedule, $user, $validated['notes'] ?? null);
        }), "shoot.{$shoot->id}.approval-schedule", $writeAttempts));
        $this->mediaStorageService->createShootFolders($shoot);

        if ($scheduledAt) {
            try {
                $this->invoiceService->generateForShoot($shoot->fresh());
            } catch (\Exception $e) {
                Log::warning('Failed to auto-create invoice for approved shoot', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $shoot->refresh();
        $shoot->load(['client', 'photographer', 'rep', 'service', 'services']);

        // CubiCasa ordering is no longer dispatched here. Approval moves the
        // shoot to scheduled, and ShootObserver dispatches for ANY route to a
        // confirmed, dated, eligible, unlinked shoot — approval included. Doing
        // it in both places issued two concurrent creates for one shoot.

        $context = $this->automationService->buildShootContext($shoot);
        if ($shoot->rep) {
            $context['rep'] = $shoot->rep;
        }
        $context['scheduled_at'] = $shoot->scheduled_at?->toISOString();
        $shootChangeSummary = $this->mailService->buildShootChangeSummary($beforeSnapshot, $shoot);
        $context['shoot_changes'] = $shootChangeSummary['summary'];
        $context['shoot_changes_html'] = $shootChangeSummary['html'];
        $notifyClient = array_key_exists('notify_client', $validated) ? (bool) $validated['notify_client'] : null;
        $notifyPhotographer = array_key_exists('notify_photographer', $validated) ? (bool) $validated['notify_photographer'] : null;
        $context['notify_client'] = $notifyClient;
        $context['notify_photographer'] = $notifyPhotographer;
        $requestApprovalTrigger = 'SHOOT_REQUEST_APPROVED';
        $requestApprovalAttemptedAt = null;
        $requestApprovalClientEmailSent = false;
        if ($wasRequested) {
            $clientRequestChanges = $this->mailService->buildClientRequestChangeSummary($beforeSnapshot, $shoot);
            if ($this->hasClientFacingRequestModifications($validated)
                && (! empty($clientRequestChanges['lines']) || ! empty($clientRequestChanges['service_deltas']))) {
                $requestApprovalTrigger = 'SHOOT_REQUEST_MODIFIED';
                $context['request_modified'] = true;
                $context['shoot_changes'] = $clientRequestChanges['summary'] ?? ($shootChangeSummary['summary'] ?? 'Please review updated details in the dashboard.');
                $context['shoot_changes_html'] = null;
                $context['shoot_service_deltas'] = $clientRequestChanges['service_deltas'] ?? [];
            }

            $requestApprovalAttemptedAt = now();
            $requestApprovalDispatch = $this->automationService->handleEvent($requestApprovalTrigger, $context);
            $requestApprovalClientEmailSent = (bool) ($requestApprovalDispatch['client_email_sent'] ?? false);
            if (
                $requestApprovalTrigger === 'SHOOT_REQUEST_MODIFIED'
                && $notifyClient !== false
                && ! $requestApprovalClientEmailSent
                && $this->automationService->shouldUseFallback($requestApprovalTrigger, $requestApprovalDispatch)
                && $shoot->client
            ) {
                $requestApprovalClientEmailSent = $this->mailService->sendShootRequestModifiedEmail(
                    $shoot->client,
                    $shoot,
                    (string) ($context['shoot_changes'] ?? '')
                );
            }
            Log::info('Shoot request approval dispatch evaluated', [
                'shoot_id' => $shoot->id,
                'trigger_type' => $requestApprovalTrigger,
                'dispatch' => $this->formatDispatchSummaryForLog($requestApprovalDispatch),
            ]);
        }
        // Approval implies the shoot is scheduled — only fire SHOOT_SCHEDULED here.
        // SHOOT_BOOKED is reserved for the initial booking moment (CreateShootAction / BookingTools)
        // and firing it again on approval can cause duplicate emails to the photographer when the
        // SHOOT_BOOKED rule and the SHOOT_SCHEDULED fallback both target the photographer.
        $shootScheduledAttemptedAt = now();
        $scheduledContext = $context;
        if ($wasRequested) {
            $scheduledContext['notify_client'] = false;
        }
        $shootScheduledDispatch = $this->automationService->handleEvent('SHOOT_SCHEDULED', $scheduledContext);
        if (! empty($scheduledContext['photographers'])) {
            $this->automationService->handleEvent('PHOTOGRAPHER_ASSIGNED', $scheduledContext);
        }
        $shouldUseFallback = $this->automationService->shouldUseFallback('SHOOT_SCHEDULED', $shootScheduledDispatch) !== false;
        Log::info('Shoot approval fallback decision evaluated', [
            'shoot_id' => $shoot->id,
            'trigger_type' => 'SHOOT_SCHEDULED',
            'fallback_used' => $shouldUseFallback,
            'dispatch' => $this->formatDispatchSummaryForLog($shootScheduledDispatch),
            'notify_client' => $notifyClient,
            'notify_photographer' => $notifyPhotographer,
        ]);

        $clientEmailSent = (bool) ($shootScheduledDispatch['client_email_sent'] ?? false);
        $photographerEmailSent = (bool) ($shootScheduledDispatch['photographer_email_sent'] ?? false);
        $clientConfirmationCoveredByApproval = $wasRequested && $requestApprovalClientEmailSent;

        if ($notifyClient !== false && $shoot->client && ($clientEmailSent || $clientConfirmationCoveredByApproval)) {
            $this->clientConfirmationRecoveryService->recordAutomationSent(
                $shoot,
                $shoot->client,
                $clientConfirmationCoveredByApproval && $requestApprovalAttemptedAt
                    ? $requestApprovalAttemptedAt
                    : $shootScheduledAttemptedAt
            );
        }

        if ($shouldUseFallback) {
            if ($notifyClient !== false && ! $clientEmailSent && ! $clientConfirmationCoveredByApproval) {
                if (! $shoot->client) {
                    $this->clientConfirmationRecoveryService->recordNoDeliveryPath($shoot, null, 'SHOOT_SCHEDULED');
                } elseif (! $this->clientConfirmationRecoveryService->hasDeliverableEmail($shoot->client)) {
                    $this->clientConfirmationRecoveryService->recordSkippedMissingEmail($shoot, $shoot->client, 'SHOOT_SCHEDULED');
                } else {
                    $paymentLink = $this->mailService->generatePaymentLink($shoot);
                    $clientFallbackAttemptedAt = now();
                    $sentClientFallback = $this->mailService->sendShootScheduledEmail(
                        $shoot->client,
                        $shoot,
                        $paymentLink,
                        false
                    );

                    if ($sentClientFallback) {
                        $this->clientConfirmationRecoveryService->recordFallbackSent(
                            $shoot,
                            $shoot->client,
                            $clientFallbackAttemptedAt
                        );
                    } else {
                        $this->clientConfirmationRecoveryService->recordProviderFailure(
                            $shoot,
                            $shoot->client,
                            'fallback',
                            $clientFallbackAttemptedAt,
                            'Fallback client confirmation send failed.'
                        );
                    }
                }
            }

            if ($notifyPhotographer !== false && ! $photographerEmailSent) {
                $this->mailService->sendAssignedPhotographerShootScheduledEmails($shoot);
            }
        }

        $this->googleCalendarSyncDispatcher->dispatchShootSync($shoot->id);

        return $shoot;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function hasClientFacingRequestModifications(array $validated): bool
    {
        foreach (array_keys($validated) as $field) {
            if (! in_array($field, self::NON_MODIFYING_REQUEST_APPROVAL_FIELDS, true)) {
                return true;
            }
        }

        return false;
    }

    private function formatDispatchSummaryForLog(?array $dispatch): array
    {
        if (! is_array($dispatch)) {
            return [
                'present' => false,
            ];
        }

        return [
            'present' => true,
            'active_rule_count' => $dispatch['active_rule_count'] ?? null,
            'handled' => $dispatch['handled'] ?? null,
            'failed_run_count' => $dispatch['failed_run_count'] ?? null,
            'error_count' => count($dispatch['errors'] ?? []),
            'client_email_sent' => $dispatch['client_email_sent'] ?? null,
            'photographer_email_sent' => $dispatch['photographer_email_sent'] ?? null,
        ];
    }
}
