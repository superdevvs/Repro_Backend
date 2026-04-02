<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
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
        protected MailService $mailService
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

        $shoot->loadMissing(['client', 'photographer', 'rep', 'service', 'services']);
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

            $this->automationService->handleEvent($requestApprovalTrigger, $context);
        }
        $this->automationService->handleEvent('SHOOT_BOOKED', $context);
        $this->automationService->handleEvent('SHOOT_SCHEDULED', $context);

        if (!$this->automationService->hasActiveTrigger('SHOOT_SCHEDULED')) {
            $paymentLink = $shoot->client ? $this->mailService->generatePaymentLink($shoot) : '';

            if ($shoot->client && $notifyClient !== false) {
                $this->mailService->sendShootScheduledEmail(
                    $shoot->client,
                    $shoot,
                    $paymentLink,
                    $notifyPhotographer
                );
            } elseif ($shoot->photographer && $notifyPhotographer !== false) {
                $this->mailService->sendShootScheduledEmail($shoot->photographer, $shoot, '');
            }
        }

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
}
