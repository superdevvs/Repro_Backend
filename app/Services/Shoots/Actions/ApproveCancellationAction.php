<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\ShootWorkflowService;
use App\Services\Shoots\ShootWorkflowTransitionSupportService;
use Illuminate\Http\Request;

class ApproveCancellationAction
{
    public function __construct(
        protected ShootWorkflowService $workflowService,
        protected ShootWorkflowTransitionSupportService $support,
        protected InvoiceService $invoiceService,
        protected MailService $mailService
    ) {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        if (!$shoot->cancellation_requested_at) {
            throw new \InvalidArgumentException('No cancellation request pending for this shoot');
        }

        $validated = $request->validate([
            'decision' => 'nullable|string|in:charge_fee,waive_fee',
            'cancellation_fee' => 'nullable|numeric|min:0|max:10000',
            'waive_cancellation_fee' => 'nullable|boolean',
        ]);
        $decision = $validated['decision'] ?? null;
        if (!$decision) {
            $decision = ($validated['waive_cancellation_fee'] ?? false) ? 'waive_fee' : 'charge_fee';
        }
        $cancellationFee = $decision === 'charge_fee'
            ? (float) ($validated['cancellation_fee'] ?? $this->defaultCancellationFeeFor($shoot))
            : 0.0;

        $this->workflowService->cancel($shoot, $user, $shoot->cancellation_reason, $cancellationFee);

        $cancelledShoot = $shoot->fresh(['client', 'photographer', 'services', 'payments']) ?? $shoot;
        if ($cancellationFee > 0) {
            $invoice = $this->invoiceService->generateForShoot($cancelledShoot);
            $this->invoiceService->createCancellationPhotographerPayouts($cancelledShoot, 50.00);
            if ($invoice && $cancelledShoot->client) {
                $this->mailService->sendCancellationFeeInvoiceEmail($cancelledShoot->client, $invoice);
            }
        }

        $this->support->sendCancellationSideEffects($cancelledShoot, $user);
        $this->support->sendCancellationApprovalSideEffects($cancelledShoot, $user, $cancellationFee > 0, $cancellationFee);

        return $cancelledShoot;
    }

    protected function defaultCancellationFeeFor(Shoot $shoot): float
    {
        $currentStatus = strtolower((string) ($shoot->workflow_status ?? $shoot->status));

        return in_array($currentStatus, [
            strtolower(Shoot::STATUS_SCHEDULED),
            'booked',
            strtolower(Shoot::STATUS_ON_HOLD),
        ], true) ? 60.0 : 0.0;
    }
}
