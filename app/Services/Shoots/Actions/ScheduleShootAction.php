<?php

namespace App\Services\Shoots\Actions;

use App\Http\Requests\UpdateShootStatusRequest;
use App\Models\Shoot;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\ShootWorkflowService;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Validation\ValidationException;

class ScheduleShootAction
{
    public function __construct(
        protected ShootMutationSupportService $support,
        protected ShootWorkflowService $workflowService,
        protected DropboxWorkflowService $dropboxService,
        protected AutomationService $automationService,
        protected MailService $mailService,
        protected GoogleCalendarSyncDispatcher $googleCalendarSyncDispatcher
    ) {
    }

    public function execute(UpdateShootStatusRequest $request, Shoot $shoot, User $user): Shoot
    {
        $validated = $request->validated();
        $originalPhotographerId = $shoot->photographer_id;
        $beforeSnapshot = $this->mailService->captureShootSnapshot($shoot);
        $scheduledAt = $validated['scheduled_at'] ? new \DateTime($validated['scheduled_at']) : null;

        if (!$scheduledAt) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['scheduled_at is required'],
            ]);
        }

        $photographerId = $validated['photographer_id'] ?? $shoot->photographer_id;
        if ($photographerId) {
            $carbonDate = \Carbon\Carbon::parse($scheduledAt);
            \Illuminate\Support\Facades\DB::table('shoots')
                ->where('photographer_id', $photographerId)
                ->whereDate('scheduled_at', $carbonDate->toDateString())
                ->where('id', '!=', $shoot->id)
                ->lockForUpdate()
                ->get();

            $durationMinutes = $this->support->calculateShootDurationFromShoot($shoot);
            $this->support->checkPhotographerAvailability($photographerId, $scheduledAt, $durationMinutes, $shoot->id);

            if ($photographerId !== $shoot->photographer_id) {
                $shoot->photographer_id = $photographerId;
                $shoot->save();
            }
        }

        $wasOnHold = ($shoot->status === 'hold_on' || $shoot->workflow_status === 'on_hold');
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

        $this->workflowService->schedule($shoot, $scheduledAt, $user);

        if (!$shoot->dropbox_raw_folder) {
            $this->dropboxService->createShootFolders($shoot);
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

        if ($shoot->client && $this->automationService->shouldUseFallback('SHOOT_SCHEDULED', $shootScheduledDispatch) !== false) {
            $paymentLink = $this->mailService->generatePaymentLink($shoot);
            $this->mailService->sendShootScheduledEmail($shoot->client, $shoot, $paymentLink);
        }

        if (
            $originalPhotographerId
            && $originalPhotographerId !== $shoot->photographer_id
            && !$this->automationService->hasActiveTrigger('PHOTOGRAPHER_CHANGED')
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
}
