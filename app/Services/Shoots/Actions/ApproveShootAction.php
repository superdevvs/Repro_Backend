<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\ClientConfirmationRecoveryService;
use App\Services\ShootWorkflowService;
use App\Services\Shoots\ShootEditablePayloadService;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ApproveShootAction
{
    private const NON_MODIFYING_REQUEST_APPROVAL_FIELDS = [
        'scheduled_at',
        'photographer_id',
        'notes',
        'skip_availability_check',
        'notify_client',
        'notify_photographer',
        'service_photographers',
    ];

    public function __construct(
        protected ShootMutationSupportService $support,
        protected ShootEditablePayloadService $editablePayloadService,
        protected ShootWorkflowService $workflowService,
        protected DropboxWorkflowService $dropboxService,
        protected InvoiceService $invoiceService,
        protected AutomationService $automationService,
        protected ClientConfirmationRecoveryService $clientConfirmationRecoveryService,
        protected MailService $mailService,
        protected GoogleCalendarSyncDispatcher $googleCalendarSyncDispatcher
    ) {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
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

        $this->editablePayloadService->apply($shoot, $validated);

        $scheduledAt = isset($validated['scheduled_at'])
            ? new \DateTime($validated['scheduled_at'])
            : (
                $shoot->scheduled_at instanceof \DateTimeInterface
                    ? new \DateTime($shoot->scheduled_at->format('Y-m-d H:i:s'))
                    : ($shoot->scheduled_at ? new \DateTime((string) $shoot->scheduled_at) : new \DateTime())
            );

        if (!empty($shoot->photographer_id)) {
            $skipAvailabilityCheck = $validated['skip_availability_check'] ?? in_array($user->role, ['admin', 'superadmin']);
            if (!$skipAvailabilityCheck) {
                $durationMinutes = $this->support->calculateShootDurationFromServices(
                    $shoot->services->map(fn ($service) => ['id' => $service->id])->toArray()
                );
                $this->support->checkPhotographerAvailability($shoot->photographer_id, $scheduledAt, $durationMinutes);
            }
        }

        $this->workflowService->approve($shoot, $scheduledAt, $user, $validated['notes'] ?? null);
        $this->dropboxService->createShootFolders($shoot);

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
        if ($wasRequested) {
            if ($this->hasClientFacingRequestModifications($validated)) {
                $requestApprovalTrigger = 'SHOOT_REQUEST_MODIFIED';
                $context['request_modified'] = true;
            }

            $requestApprovalDispatch = $this->automationService->handleEvent($requestApprovalTrigger, $context);
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
        $shootScheduledDispatch = $this->automationService->handleEvent('SHOOT_SCHEDULED', $context);
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

        if ($notifyClient !== false && $shoot->client && $clientEmailSent) {
            $this->clientConfirmationRecoveryService->recordAutomationSent(
                $shoot,
                $shoot->client,
                $shootScheduledAttemptedAt
            );
        }

        if ($shouldUseFallback || !$clientEmailSent || !$photographerEmailSent) {
            if ($notifyClient !== false && !$clientEmailSent) {
                if (!$shoot->client) {
                    $this->clientConfirmationRecoveryService->recordNoDeliveryPath($shoot, null, 'SHOOT_SCHEDULED');
                } elseif (!$this->clientConfirmationRecoveryService->hasDeliverableEmail($shoot->client)) {
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

            if ($notifyPhotographer !== false && !$photographerEmailSent) {
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
            if (!in_array($field, self::NON_MODIFYING_REQUEST_APPROVAL_FIELDS, true)) {
                return true;
            }
        }

        return false;
    }

    private function formatDispatchSummaryForLog(?array $dispatch): array
    {
        if (!is_array($dispatch)) {
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
