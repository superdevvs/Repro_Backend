<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\ShootWorkflowService;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ApproveShootAction
{
    public function __construct(
        protected ShootMutationSupportService $support,
        protected ShootWorkflowService $workflowService,
        protected DropboxWorkflowService $dropboxService,
        protected InvoiceService $invoiceService,
        protected AutomationService $automationService,
        protected MailService $mailService
    ) {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        $beforeSnapshot = $this->mailService->captureShootSnapshot($shoot);
        $wasRequested = $shoot->status === Shoot::STATUS_REQUESTED || $shoot->workflow_status === Shoot::STATUS_REQUESTED;

        $validated = $request->validate([
            'photographer_id' => 'nullable|exists:users,id',
            'scheduled_at' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'skip_availability_check' => 'nullable|boolean',
            'service_photographers' => 'nullable|array',
            'service_photographers.*.service_id' => 'required_with:service_photographers|integer',
            'service_photographers.*.photographer_id' => 'required_with:service_photographers|integer|exists:users,id',
        ]);

        $scheduledAt = isset($validated['scheduled_at'])
            ? new \DateTime($validated['scheduled_at'])
            : ($shoot->scheduled_at ? new \DateTime($shoot->scheduled_at) : new \DateTime());

        if (!empty($validated['photographer_id'])) {
            $skipAvailabilityCheck = $validated['skip_availability_check'] ?? in_array($user->role, ['admin', 'superadmin']);
            if (!$skipAvailabilityCheck) {
                $durationMinutes = $this->support->calculateShootDurationFromServices(
                    $shoot->services->map(fn ($service) => ['id' => $service->id])->toArray()
                );
                $this->support->checkPhotographerAvailability($validated['photographer_id'], $scheduledAt, $durationMinutes);
            }
            $shoot->photographer_id = $validated['photographer_id'];
            $shoot->save();
        }

        $this->support->assignServicePhotographers($shoot, $validated['service_photographers'] ?? null);

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

        $shoot->loadMissing(['client', 'photographer', 'rep', 'service', 'services']);
        $context = $this->automationService->buildShootContext($shoot);
        if ($shoot->rep) {
            $context['rep'] = $shoot->rep;
        }
        $context['scheduled_at'] = $shoot->scheduled_at?->toISOString();
        $shootChangeSummary = $this->mailService->buildShootChangeSummary($beforeSnapshot, $shoot);
        $context['shoot_changes'] = $shootChangeSummary['summary'];
        $context['shoot_changes_html'] = $shootChangeSummary['html'];
        if ($wasRequested) {
            $this->automationService->handleEvent('SHOOT_REQUEST_APPROVED', $context);
        }
        $this->automationService->handleEvent('SHOOT_BOOKED', $context);
        $this->automationService->handleEvent('SHOOT_SCHEDULED', $context);

        if ($shoot->client && !$this->automationService->hasActiveTrigger('SHOOT_UPDATED')) {
            $this->mailService->sendShootUpdatedEmail($shoot->client, $shoot, $shootChangeSummary['summary']);
        }

        if (
            $shoot->photographer
            && !$this->automationService->hasActiveTrigger('SHOOT_UPDATED')
            && !$this->automationService->hasActiveTrigger('PHOTOGRAPHER_ASSIGNED')
        ) {
            $this->mailService->sendShootScheduledEmail($shoot->photographer, $shoot, '');
        }

        return $shoot;
    }
}
