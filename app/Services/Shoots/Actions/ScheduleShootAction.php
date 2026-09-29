<?php

namespace App\Services\Shoots\Actions;

use App\Http\Requests\UpdateShootStatusRequest;
use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\ClientConfirmationRecoveryService;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\ShootMutationSupportService;
use App\Services\ShootWorkflowService;
use Illuminate\Validation\ValidationException;

class ScheduleShootAction
{
    public function __construct(
        protected ShootMutationSupportService $support,
        protected ShootWorkflowService $workflowService,
        protected ShootMediaStorageService $mediaStorageService,
        protected AutomationService $automationService,
        protected ClientConfirmationRecoveryService $clientConfirmationRecoveryService,
        protected MailService $mailService,
        protected GoogleCalendarSyncDispatcher $googleCalendarSyncDispatcher
    ) {}

    public function execute(UpdateShootStatusRequest $request, Shoot $shoot, User $user): Shoot
    {
        $validated = $request->validated();
        $originalPhotographerId = $shoot->photographer_id;
        $beforeSnapshot = $this->mailService->captureShootSnapshot($shoot);
        $scheduledAt = $this->resolveScheduleInstant($validated, $shoot);

        $isMultiUnit = $shoot->units()->exists();
        $wasOnHold = in_array(strtolower((string) $shoot->status), ['hold_on', 'on_hold'], true)
            || in_array(strtolower((string) $shoot->workflow_status), ['hold_on', 'on_hold'], true);
        if ($isMultiUnit) {
            $this->resumeUnitPlan($shoot, $scheduledAt, $validated, $user);
        }
        $photographerId = $validated['photographer_id'] ?? $shoot->photographer_id;
        if (! $isMultiUnit && $photographerId) {
            $carbonDate = \Carbon\Carbon::parse($scheduledAt);
            \Illuminate\Support\Facades\DB::table('shoots')
                ->where('photographer_id', $photographerId)
                ->whereDate('scheduled_at', $carbonDate->toDateString())
                ->where('id', '!=', $shoot->id)
                ->lockForUpdate()
                ->get();

            $durationMinutes = $this->support->calculateShootDurationFromShoot($shoot);
            $this->support->checkPhotographerAvailability($photographerId, $scheduledAt, $durationMinutes, $shoot->id);

            // Non-deliverable lines (fees, holds, etc.) must not block resume/schedule
            // availability — they do not consume photographer calendar time.
            $targetServices = $shoot->services->map(function ($service) use ($scheduledAt) {
                return [
                    'id' => (int) $service->id,
                    'photographer_id' => $service->pivot?->photographer_id,
                    'scheduled_at' => $service->pivot?->scheduled_at ?: $scheduledAt->format('Y-m-d H:i:s'),
                    'price' => $service->pivot?->price,
                    'quantity' => $service->pivot?->quantity ?? 1,
                    'is_deliverable' => (bool) ($service->pivot?->is_deliverable ?? true),
                    'duration_minutes' => $service->pivot?->duration_minutes,
                ];
            })->values()->all();
            $this->support->checkServiceItemPhotographerAvailability($targetServices, (int) $photographerId, $shoot->id);

            if ($photographerId !== $shoot->photographer_id) {
                $shoot->photographer_id = $photographerId;
                $shoot->save();
            }
        }

        if ($wasOnHold) {
            $cancellationFee = 60;
            $currentBase = $shoot->base_quote ?? 0;
            $currentTotal = $shoot->total_quote ?? 0;

            if ($currentBase >= $cancellationFee && $currentTotal >= $cancellationFee) {
                $shoot->base_quote = max(0, $currentBase - $cancellationFee);
                $shoot->total_quote = max(0, $currentTotal - $cancellationFee);
                $shoot->save();
            }
        }

        if (! $isMultiUnit) {
            $this->workflowService->schedule($shoot, $scheduledAt, $user);
            $shoot->serviceItems()
                ->whereNull('scheduled_at')
                ->update([
                    'scheduled_at' => \Carbon\Carbon::parse($scheduledAt)->format('Y-m-d H:i:s'),
                    'workflow_status' => 'scheduled',
                    'updated_at' => now(),
                ]);
        }

        if (! $shoot->dropbox_raw_folder) {
            $this->mediaStorageService->createShootFolders($shoot);
        }

        $shoot->refresh();
        $shoot->load(['client', 'rep', 'photographer', 'services', 'createdByUser']);

        $context = $this->automationService->buildShootContext($shoot);
        if ($shoot->rep) {
            $context['rep'] = $shoot->rep;
        }
        $context['scheduled_at'] = $shoot->scheduled_at?->toISOString();
        $shootChangeSummary = $this->mailService->buildShootChangeSummary($beforeSnapshot, $shoot);
        $context['shoot_changes'] = $shootChangeSummary['summary'];
        $context['shoot_changes_html'] = $shootChangeSummary['html'];
        $shootScheduledAttemptedAt = now();
        $shootScheduledDispatch = $this->automationService->handleEvent('SHOOT_SCHEDULED', $context);

        if ($originalPhotographerId && $originalPhotographerId !== $shoot->photographer_id && $shoot->photographer_id) {
            $previousPhotographer = User::find($originalPhotographerId);
            $context['photographer_changed'] = true;
            $context['previous_photographer_id'] = $originalPhotographerId;
            $context['previous_photographer'] = $previousPhotographer;
            $context['new_photographer_id'] = $shoot->photographer_id;
            $context['new_photographer'] = $shoot->photographer;
            $context['affected_photographers'] = collect([$previousPhotographer])
                ->merge(collect($context['photographers'] ?? []))
                ->filter()
                ->unique('id')
                ->values()
                ->all();
            $this->automationService->handleEvent('PHOTOGRAPHER_CHANGED', $context);
        } elseif ($originalPhotographerId !== $shoot->photographer_id && $shoot->photographer_id) {
            $context['previous_photographer_id'] = $originalPhotographerId;
            $this->automationService->handleEvent('PHOTOGRAPHER_ASSIGNED', $context);
        }

        $shouldUseFallback = $this->automationService->shouldUseFallback('SHOOT_SCHEDULED', $shootScheduledDispatch) !== false;
        \Illuminate\Support\Facades\Log::info('Shoot scheduled fallback decision evaluated', [
            'shoot_id' => $shoot->id,
            'trigger_type' => 'SHOOT_SCHEDULED',
            'fallback_used' => $shouldUseFallback,
            'dispatch' => $this->formatDispatchSummaryForLog($shootScheduledDispatch),
        ]);

        $clientEmailSent = (bool) ($shootScheduledDispatch['client_email_sent'] ?? false);
        $photographerEmailSent = (bool) ($shootScheduledDispatch['photographer_email_sent'] ?? false);

        if ($shoot->client && $clientEmailSent) {
            $this->clientConfirmationRecoveryService->recordAutomationSent(
                $shoot,
                $shoot->client,
                $shootScheduledAttemptedAt
            );
        }

        if ($shouldUseFallback) {
            if (! $clientEmailSent) {
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

            if ($shouldUseFallback && ! $photographerEmailSent) {
                $this->mailService->sendAssignedPhotographerShootScheduledEmails($shoot);
            }
        }

        if (
            $originalPhotographerId
            && $originalPhotographerId !== $shoot->photographer_id
            && $this->automationService->shouldUseFallback('PHOTOGRAPHER_CHANGED')
        ) {
            $previousPhotographer = User::find($originalPhotographerId);
            $affectedPhotographers = collect([$previousPhotographer])
                ->merge(collect($context['photographers'] ?? []))
                ->filter()
                ->unique('id');

            foreach ($affectedPhotographers as $photographer) {
                $this->mailService->sendPhotographerChangedEmail(
                    $photographer,
                    $shoot,
                    $previousPhotographer,
                    $shootChangeSummary['summary']
                );
            }
        }

        $this->googleCalendarSyncDispatcher->dispatchShootSync($shoot->id);

        return $shoot;
    }

    private function resumeUnitPlan(Shoot $shoot, \DateTime $scheduledAt, array $validated, User $user): void
    {
        abort_unless(in_array(strtolower($user->role), ['admin', 'superadmin', 'editing_manager'], true), 403);
        \App\Support\LockedWrite::run(fn () => \Illuminate\Support\Facades\DB::transaction(function () use ($shoot, $scheduledAt, $validated, $user) {
            if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite') {
                \Illuminate\Support\Facades\DB::table('shoots')->where('id', $shoot->id)->update(['units_revision' => \Illuminate\Support\Facades\DB::raw('units_revision')]);
            }
            $current = Shoot::query()->lockForUpdate()->findOrFail($shoot->id);
            if (! isset($validated['expected_units_revision']) || (int) $validated['expected_units_revision'] !== (int) $current->units_revision) {
                throw ValidationException::withMessages(['expected_units_revision' => ['The unit visit plan changed. Reload before resuming it.']]);
            }
            foreach ($current->serviceItems()->with('service')->get() as $line) {
                if ($line->workflow_status !== 'cancelled' && $line->service?->requiresPhotographer() && (! $line->scheduled_at || ! $line->photographer_id)) {
                    throw ValidationException::withMessages(['service_lines' => ['Assign a photographer and time to every unit capture line before resuming.']]);
                }
            }
            if ($current->scheduled_at?->format('Y-m-d H:i:s') !== $scheduledAt->format('Y-m-d H:i:s')) {
                $local = \Carbon\Carbon::instance($scheduledAt);
                if ($current->timezone) {
                    $local->setTimezone($current->timezone);
                }
                $move = new \App\Models\ShootRescheduleRequest(['requested_date' => $local->toDateString(), 'requested_time' => $local->format('H:i'), 'units_revision' => $current->units_revision]);
                app(\App\Services\Shoots\MultiUnitRescheduleService::class)->apply($current, $move, $user);
            }
            $this->workflowService->schedule($current, $current->scheduled_at ?? $scheduledAt, $user);
        }), 'resume-unit-visit-plan');
        $shoot->refresh();
    }

    /**
     * Resolve the appointment for schedule / resume-from-hold.
     *
     * Undated On Hold imports have null appointment fields. Accept a schedule-on-resume
     * payload (scheduled_at or scheduled_date[+time]), or fall back to a date already
     * saved on the shoot after a prior edit. Still reject when nothing provides a date.
     */
    private function resolveScheduleInstant(array $validated, Shoot $shoot): \DateTime
    {
        if (! empty($validated['scheduled_at'])) {
            return new \DateTime($validated['scheduled_at']);
        }

        $requestDate = $validated['scheduled_date'] ?? null;
        $requestTime = $validated['time'] ?? null;
        if (! empty($requestDate)) {
            return new \DateTime(trim(sprintf('%s %s', $requestDate, $requestTime ?: '00:00:00')));
        }

        if ($shoot->scheduled_at) {
            return new \DateTime($shoot->scheduled_at->format('Y-m-d H:i:s'));
        }

        $existingDate = $shoot->scheduled_date?->toDateString() ?? $shoot->scheduled_date;
        if (! empty($existingDate)) {
            $existingTime = $requestTime ?: ($shoot->time ?: '00:00:00');

            return new \DateTime(trim(sprintf('%s %s', $existingDate, $existingTime)));
        }

        throw ValidationException::withMessages([
            'scheduled_at' => ['scheduled_at is required'],
        ]);
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
