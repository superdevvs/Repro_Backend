<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\ClientConfirmationRecoveryService;
use Illuminate\Support\Facades\Log;

class ShootNotificationDispatchService
{
    public function __construct(
        protected AutomationService $automationService,
        protected ClientConfirmationRecoveryService $clientConfirmationRecoveryService,
        protected ShootMediaStorageService $mediaStorageService,
        protected MailService $mailService,
    ) {
    }

    public function processCreatedShoot(int $shootId, bool $treatAsClientRequest, bool $isImmediatelyScheduled): void
    {
        $shoot = Shoot::with(['client', 'photographer', 'rep', 'service', 'services'])->find($shootId);
        if (!$shoot) {
            return;
        }

        if ($treatAsClientRequest) {
            try {
                $context = $this->buildShootContext($shoot);
                $this->automationService->handleEvent('SHOOT_REQUESTED', $context);
            } catch (\Exception $e) {
                Log::error('Failed to trigger SHOOT_REQUESTED automation', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$treatAsClientRequest && $isImmediatelyScheduled) {
            try {
                $this->mediaStorageService->createShootFolders($shoot);
            } catch (\Exception $e) {
                Log::error('Failed to create Dropbox folders for shoot', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $shootBookedDispatch = null;
        $shootBookedAttemptedAt = null;
        if (!$treatAsClientRequest) {
            try {
                $context = $this->buildShootContext($shoot);
                $shootBookedAttemptedAt = now();
                $shootBookedDispatch = $this->automationService->handleEvent('SHOOT_BOOKED', $context);
            } catch (\Exception $e) {
                Log::error('Failed to trigger SHOOT_BOOKED automation', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$treatAsClientRequest && $isImmediatelyScheduled) {
            try {
                $shoot->loadMissing(['client', 'photographer', 'services']);
                $client = $shoot->client;
                $shouldUseFallback = $this->automationService->shouldUseFallback('SHOOT_BOOKED', $shootBookedDispatch) !== false;
                Log::info('Shoot booking fallback decision evaluated', [
                    'shoot_id' => $shoot->id,
                    'trigger_type' => 'SHOOT_BOOKED',
                    'fallback_used' => $shouldUseFallback,
                    'dispatch' => $this->formatDispatchSummaryForLog($shootBookedDispatch),
                ]);

                $clientEmailSent = (bool) ($shootBookedDispatch['client_email_sent'] ?? false);
                $photographerEmailSent = (bool) ($shootBookedDispatch['photographer_email_sent'] ?? false);

                if ($client && $clientEmailSent) {
                    $this->clientConfirmationRecoveryService->recordAutomationSent(
                        $shoot,
                        $client,
                        $shootBookedAttemptedAt ?? now()
                    );
                }

                if (!$clientEmailSent) {
                    if (!$client) {
                        $this->clientConfirmationRecoveryService->recordNoDeliveryPath($shoot, null, 'SHOOT_BOOKED');
                    } elseif (!$this->clientConfirmationRecoveryService->hasDeliverableEmail($client)) {
                        $this->clientConfirmationRecoveryService->recordSkippedMissingEmail($shoot, $client, 'SHOOT_BOOKED');
                    } else {
                        $paymentLink = $this->mailService->generatePaymentLink($shoot);
                        $clientFallbackAttemptedAt = now();
                        $sentClientFallback = $this->mailService->sendShootScheduledEmail($client, $shoot, $paymentLink, false);

                        if ($sentClientFallback) {
                            $this->clientConfirmationRecoveryService->recordFallbackSent($shoot, $client, $clientFallbackAttemptedAt);
                        } else {
                            $this->clientConfirmationRecoveryService->recordProviderFailure(
                                $shoot,
                                $client,
                                'fallback',
                                $clientFallbackAttemptedAt,
                                'Fallback client confirmation send failed.'
                            );
                        }
                    }
                }

                if ($shouldUseFallback || !$photographerEmailSent) {
                    $this->mailService->sendAssignedPhotographerShootScheduledEmails($shoot);
                }
            } catch (\Exception $e) {
                Log::error('Failed to send shoot scheduled email during creation', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function processUpdatedShoot(
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
        $shoot = Shoot::with(['client', 'photographer', 'rep', 'service', 'services'])->find($shootId);
        if (!$shoot) {
            return;
        }

        $client = $shoot->client;
        $previousPhotographer = $originalPhotographerId ? User::find($originalPhotographerId) : null;
        $affectedPhotographers = collect([$previousPhotographer])
            ->merge(collect($this->automationService->buildShootContext($shoot)['photographers'] ?? []))
            ->filter()
            ->unique('id')
            ->values();

        if (
            $photographerNewlyAssigned
            && $shoot->photographer
            && !$this->automationService->hasActiveTrigger('SHOOT_SCHEDULED')
            && !$this->automationService->hasActiveTrigger('PHOTOGRAPHER_ASSIGNED')
        ) {
            try {
                $paymentLink = $this->mailService->generatePaymentLink($shoot);
                if ($client) {
                    $this->mailService->sendShootScheduledEmail($client, $shoot, $paymentLink ?? '');
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
            if (
                in_array($shoot->status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true)
                || in_array($shoot->workflow_status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true)
            ) {
                try {
                    $removedRecipient = $client ?? User::find($shoot->client_id);
                    if ($removedRecipient) {
                        $this->mailService->sendShootRemovedEmail($removedRecipient, $shoot);
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
                        $this->mailService->sendShootReadyEmail($deliveredRecipient, $shoot);
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

        $shootUpdatedDispatch = null;
        try {
            $context = $this->buildShootContext($shoot);
            $context['shoot_changes'] = $changesSummary;
            $context['shoot_changes_html'] = $changesHtml;
            $context['notify_client'] = $notifyClient;
            $context['notify_photographer'] = $notifyPhotographer;
            $context['photographer_changed'] = $photographerChanged;
            $context['system_email_already_sent'] = $systemEmailAlreadySent;

            $shootUpdatedDispatch = $this->automationService->handleEvent('SHOOT_UPDATED', $context);

            if ($photographerChanged) {
                $context['previous_photographer_id'] = $originalPhotographerId;
                $context['previous_photographer'] = $previousPhotographer;
                $context['new_photographer_id'] = $shoot->photographer_id;
                $context['new_photographer'] = $shoot->photographer;
                $context['affected_photographers'] = $affectedPhotographers->all();
                $context['photographer_change_summary'] = $changesSummary;
                $this->automationService->handleEvent('PHOTOGRAPHER_CHANGED', $context);
            } elseif ($originalPhotographerId !== $shoot->photographer_id && $shoot->photographer_id) {
                $context['previous_photographer_id'] = $originalPhotographerId;
                $this->automationService->handleEvent('PHOTOGRAPHER_ASSIGNED', $context);
            }

            if ($originalStatus !== $shoot->status || $originalWorkflow !== $shoot->workflow_status) {
                if (
                    in_array($shoot->status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true)
                    || in_array($shoot->workflow_status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true)
                ) {
                    $this->automationService->handleEvent('SHOOT_CANCELED', $context);
                }

                if ($shoot->status === Shoot::STATUS_DELIVERED || $shoot->workflow_status === Shoot::STATUS_DELIVERED) {
                    $this->automationService->handleEvent('SHOOT_COMPLETED', $context);
                }

                if ($shoot->status === Shoot::STATUS_UPLOADED || $shoot->workflow_status === Shoot::STATUS_UPLOADED) {
                    $this->automationService->handleEvent('PHOTO_UPLOADED', $context);
                    $this->automationService->handleEvent('MEDIA_UPLOAD_COMPLETE', $context);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to trigger automation events after shoot update', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);
        }

        $shouldUseFallback = $this->automationService->shouldUseFallback('SHOOT_UPDATED', $shootUpdatedDispatch) !== false;
        Log::info('Shoot updated fallback decision evaluated', [
            'shoot_id' => $shoot->id,
            'trigger_type' => 'SHOOT_UPDATED',
            'fallback_used' => $shouldUseFallback,
            'dispatch' => $this->formatDispatchSummaryForLog($shootUpdatedDispatch),
            'notify_client' => $notifyClient,
            'notify_photographer' => $notifyPhotographer,
        ]);

        $clientEmailSent = (bool) ($shootUpdatedDispatch['client_email_sent'] ?? false);
        $photographerEmailSent = (bool) ($shootUpdatedDispatch['photographer_email_sent'] ?? false);
        $shouldSendClientFallback = $client && $notifyClient !== false && ($shouldUseFallback || !$clientEmailSent) && !$clientEmailSent;
        $shouldSendPhotographerFallback = !$photographerChanged
            && $notifyPhotographer !== false
            && ($shouldUseFallback || !$photographerEmailSent);

        if ($shouldSendClientFallback) {
            try {
                $this->mailService->sendShootUpdatedEmail(
                    $client,
                    $shoot,
                    $changesSummary,
                    true,
                    $shouldSendPhotographerFallback
                );
            } catch (\Exception $e) {
                Log::error('Failed to send shoot updated email', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($client && $shouldSendPhotographerFallback) {
            try {
                $this->mailService->sendShootUpdatedEmail(
                    $client,
                    $shoot,
                    $changesSummary,
                    false,
                    true
                );
            } catch (\Exception $e) {
                Log::error('Failed to send photographer shoot update email fallback', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (
            $photographerChanged
            && $notifyPhotographer !== false
            && !$this->automationService->hasActiveTrigger('PHOTOGRAPHER_CHANGED')
        ) {
            foreach ($affectedPhotographers as $photographer) {
                try {
                    $this->mailService->sendPhotographerChangedEmail(
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
    }

    public function processExternalShootRequested(int $shootId): void
    {
        $shoot = Shoot::with(['client', 'photographer', 'rep', 'service', 'services'])->find($shootId);
        if (!$shoot) {
            return;
        }

        $dispatchResult = null;

        try {
            $context = $this->buildShootContext($shoot);
            $dispatchResult = $this->automationService->handleEvent('SHOOT_REQUESTED', $context);
        } catch (\Exception $e) {
            Log::error('Failed to trigger SHOOT_REQUESTED automation for external booking', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);
        }

        $shouldUseFallback = $this->automationService->shouldUseFallback('SHOOT_REQUESTED', $dispatchResult) !== false;
        $clientEmailSent = (bool) ($dispatchResult['client_email_sent'] ?? false);
        $client = $shoot->client;

        Log::info('External shoot requested fallback decision evaluated', [
            'shoot_id' => $shoot->id,
            'trigger_type' => 'SHOOT_REQUESTED',
            'fallback_used' => $shouldUseFallback || !$clientEmailSent,
            'dispatch' => $this->formatDispatchSummaryForLog($dispatchResult),
        ]);

        if ($shouldUseFallback || !$clientEmailSent) {
            if (!$client) {
                $this->logSkippedExternalShootRequestedFallback($shoot, null, 'missing_client_record');
            } elseif (!$this->clientConfirmationRecoveryService->hasDeliverableEmail($client)) {
                $this->logSkippedExternalShootRequestedFallback($shoot, $client, 'missing_client_email');
            } else {
                $sent = $this->mailService->sendShootRequestedEmail($client, $shoot);

                if (!$sent) {
                    Log::warning('External shoot requested client fallback did not send successfully.', [
                        'shoot_id' => $shoot->id,
                        'client_id' => $client->id,
                        'trigger_type' => 'SHOOT_REQUESTED',
                    ]);
                }
            }
        }

        if ($shouldUseFallback) {
            $this->mailService->sendShootRequestedAdminNotificationEmails($shoot);
        }
    }

    protected function buildShootContext(Shoot $shoot): array
    {
        $context = $this->automationService->buildShootContext($shoot);
        if ($shoot->rep) {
            $context['rep'] = $shoot->rep;
        }

        return $context;
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

    private function logSkippedExternalShootRequestedFallback(Shoot $shoot, ?User $client, string $reason): void
    {
        Log::warning('Skipping external shoot requested fallback because recipient data is incomplete.', [
            'shoot_id' => $shoot->id,
            'client_id' => $client?->id,
            'workflow_status' => $shoot->workflow_status,
            'status' => $shoot->status,
            'trigger_type' => 'SHOOT_REQUESTED',
            'recipient_type' => 'client',
            'reason' => $reason,
            'email' => $client?->email,
        ]);
    }
}
